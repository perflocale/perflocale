<?php
/**
 * TranslatePress migration importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\MigrationSourceMapRepository;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\StringTranslationRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Enum\TranslationStatus;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports translation data from TranslatePress.
 *
 * Reads TranslatePress's wp_trp_* tables, maps language codes to
 * PerfLocale language IDs, and creates translated posts with content
 * reconstructed from dictionary entries.
 *
 * TranslatePress stores string-level translations (not whole posts),
 * so this importer reconstructs full translated post content by
 * replacing each whole text, attribute value, block attribute, shortcode
 * value and translation block that TranslatePress translated
 * ({@see TranslatePressContentRebuilder}).
 */
final class TranslatePressImporter {

	use ImportHeartbeat;

	/**
	 * The import's stages, in the order it runs them ({@see ImportHeartbeat}).
	 */
	private const PROGRESS_STAGES = [ 'term_names', 'posts', 'menus', 'strings', 'slugs', 'orders' ];

	/**
	 * Posts processed per batch.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * TranslatePress status: 1 = machine-translated. TRP DISPLAYS everything
	 * with status != 0, so machine-translated strings are live on the source
	 * site and must be migrated by default (auto-translate is TRP's headline
	 * feature — the common case is a site with mostly status-1 content).
	 */
	private const MACHINE_TRANSLATED = 1;

	/**
	 * Minimum TranslatePress status to import. Defaults to MACHINE_TRANSLATED so
	 * the migration is faithful to what the source site actually displays;
	 * filter to 2 (human-reviewed) for a reviewed-only import.
	 *
	 * @hook perflocale/migration/translatepress/min_status
	 *
	 * @return int
	 */
	private function min_status(): int {
		return (int) apply_filters( 'perflocale/migration/translatepress/min_status', self::MACHINE_TRANSLATED );
	}

	/**
	 * Highest TranslatePress gettext status that is TranslatePress's own
	 * translation (1 machine, 2 reviewed). Status 3 is a copy of a similar
	 * string's translation and 4 a copy of the language pack's; importing
	 * those would freeze the pack's text into overrides.
	 */
	private const GETTEXT_OWN_MAX_STATUS = 2;

	/**
	 * Highest gettext status imported.
	 *
	 * @return int
	 */
	private function gettext_max_status(): int {
		/**
		 * Whether to import TranslatePress gettext rows that copy a similar
		 * string's translation (status 3) or the language pack's (status 4).
		 *
		 * @hook perflocale/migration/translatepress/include_gettext_copies
		 * @param bool $include Default false.
		 */
		return apply_filters( 'perflocale/migration/translatepress/include_gettext_copies', false ) ? 4 : self::GETTEXT_OWN_MAX_STATUS;
	}

	/**
	 * Option name for the post-translation resumability checkpoint.
	 *
	 * After each successfully-committed batch we store the highest
	 * post_id processed. A subsequent invocation (after watchdog kill,
	 * timeout, manual restart) starts AFTER that id instead of redoing
	 * the whole import — which on big sites would otherwise iterate
	 * tens of thousands of already-imported posts looking up "is this
	 * already in a group?" for each one.
	 */
	public const POST_CHECKPOINT_OPTION = 'perflocale_trp_import_post_checkpoint';

	/**
	 * Option holding the language selection the post checkpoint was written under.
	 *
	 * A checkpoint only says which source posts were done for the languages
	 * mapped at the time. When the source language or the mapped targets have
	 * changed since, the posts below the checkpoint still lack the new
	 * targets, so the run starts from the first post instead (already
	 * translated targets are skipped, so that repeats no work).
	 */
	public const POST_CHECKPOINT_FINGERPRINT_OPTION = 'perflocale_trp_import_post_checkpoint_fp';

	/**
	 * Rows per batch when streaming the per-language gettext dictionary.
	 * Caps memory; filterable via `perflocale/migration/translatepress/gettext_batch_size`.
	 */
	private const GETTEXT_BATCH_SIZE = 1000;

	/**
	 * Most targets named in `existing_targets`, which keeps the migration
	 * job's stored result far below JobState::MAX_RESULT_BYTES (a larger
	 * result is dropped whole). The `existing` count is exact.
	 */
	private const EXISTING_TARGETS_CAP = 100;

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * @var LanguageRepository
	 */
	private readonly LanguageRepository $languages;

	/**
	 * @var TranslationGroupRepository
	 */
	private readonly TranslationGroupRepository $groups;

	/**
	 * @var StringRepository
	 */
	private readonly StringRepository $strings;

	/**
	 * Source-map for cross-restore idempotency. Symmetric with the
	 * WPML + Polylang importers. TRP's main idempotency mechanism is
	 * the per-(post, language) existing-translation pre-check + the
	 * POST_CHECKPOINT_OPTION (perflocale_trp_import_post_checkpoint),
	 * but the source_map gives operators a single tool (the
	 * `--force-restart` CLI flag) to clear migration state across
	 * all three importers consistently.
	 *
	 * @var MigrationSourceMapRepository
	 */
	private readonly MigrationSourceMapRepository $source_map;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * @var \PerfLocale\Settings
	 */
	private readonly \PerfLocale\Settings $settings;

	/**
	 * TranslatePress settings from wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $trp_settings = [];

	/**
	 * Language mapping: TRP locale => PerfLocale language ID.
	 *
	 * @var array<string, int>
	 */
	private array $language_map = [];

	/**
	 * Default (source) language locale from TranslatePress.
	 *
	 * @var string
	 */
	private string $source_locale = '';

	/**
	 * Migration results.
	 *
	 * `existing` counts the (post, language) targets whose TranslatePress
	 * translation was not imported because the post already had a
	 * translation in that language that this importer did not write (an
	 * auto-created stub, or a manual or machine translation), which is kept
	 * as it is. `existing_targets` names the first EXISTING_TARGETS_CAP of
	 * them: source post id, language slug, id of the kept translation.
	 *
	 * `errors` holds every message for the operator: notices (a language
	 * that was not mapped or published, strings that matched nothing) and
	 * failures. `incomplete` is true when a failure left part of the
	 * TranslatePress data not imported (a source read or a write failed);
	 * a run of the import again imports the rest. A notice never sets it.
	 *
	 * @var array<string, int|bool|array>
	 */
	private array $result = [
		'posts'            => 0,
		'terms'            => 0,
		'menus'            => 0,
		'strings'          => 0,
		'slugs'            => 0,
		'orders'           => 0,
		'skipped'          => 0,
		'existing'         => 0,
		'existing_targets' => [],
		'errors'           => [],
		'incomplete'       => false,
	];

	/**
	 * Dictionary matches for text outside post content, for this run.
	 *
	 * @var TranslatePressDictionary|null
	 */
	private ?TranslatePressDictionary $dictionary = null;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb       = $wpdb;
		$this->cache      = $cache;
		$this->languages  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$this->groups     = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$this->strings    = new StringRepository( $cache );
		$this->source_map = new MigrationSourceMapRepository();

		$plugin         = \PerfLocale\Plugin::get_instance();
		$this->settings = $plugin->get( 'settings' );
	}

	/**
	 * Check if TranslatePress tables exist.
	 *
	 * @return bool
	 */
	public function can_import(): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$this->wpdb->prefix . 'trp_original_strings'
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $table_exists > 0;
	}

	/**
	 * Run the full migration.
	 *
	 * @return array<string, int|bool|array> Results with counts, errors and the `incomplete` flag.
	 */
	public function import(): array {
		// function_exists() skips cleanly when the host lists set_time_limit in
		// disable_functions — since PHP 8 a disabled function is removed from
		// the function table, so calling it unguarded is a fatal, not a
		// warning, and the migration would die on its first statement. Matches
		// the guarded siblings in Bootstrap.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( (int) apply_filters( 'perflocale/migration/time_limit', 300 ) ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large imports may exceed default time limit.
		}

		if ( ! $this->can_import() ) {
			$this->result['errors'][] = __( 'TranslatePress tables not found.', 'perflocale' );
			return $this->result;
		}

		// Load TranslatePress settings.
		$this->trp_settings = get_option( 'trp_settings', [] );

		if ( empty( $this->trp_settings ) || ! is_array( $this->trp_settings ) ) {
			$this->result['errors'][] = __( 'TranslatePress settings not found in wp_options.', 'perflocale' );
			return $this->result;
		}

		// A hard-killed batch rolls its SQL transaction back, but whatever it
		// wrote to a persistent object cache (translation-group entries, the
		// eager link map in alloptions) survives the kill. A resumed run would
		// then see rolled-back links in the idempotency pre-checks and silently
		// skip re-creating those translations. Start from committed DB state.
		wp_cache_flush();

		// Build language mapping.
		$this->build_language_map();

		if ( empty( $this->language_map ) ) {
			$this->result['errors'][] = __( 'No TranslatePress languages could be mapped to PerfLocale languages.', 'perflocale' );
			return $this->result;
		}

		$this->dictionary = new TranslatePressDictionary( $this->wpdb, $this->cache, $this->min_status(), \Closure::fromCallable( [ $this, 'beat' ] ) );

		// Term names first, so the terms copied onto created posts are the
		// translated ones; menus after the posts their items point to.
		// The import lock's heartbeat runs with each step's progress: between
		// the steps and after each batch inside them.
		//
		// A dictionary or term read that fails stops the run where it is:
		// posts created after a failed term read would get target terms under
		// their source names, which a later run keeps as translations.
		try {
			$this->result['terms'] = $this->import_term_names();
			$this->result['posts'] = $this->import_post_translations();
			$this->beat();

			// Menu items point to the posts' translations, and a menu that
			// already has a copy in a language is never copied again. When
			// the post step did not finish (a failed read, insert, batch or
			// commit), the menus wait for the run that imports those posts.
			if ( ! $this->result['incomplete'] ) {
				$this->result['menus'] = $this->import_menu_labels();
			}

			$this->beat( 'strings', 0, 0 );
			$this->result['strings'] = $this->import_option_strings() + $this->import_string_translations();
			$this->beat();
			$this->result['slugs'] = $this->import_slug_translations();
			$this->beat();
			$this->result['orders'] = $this->import_order_languages();

			$this->report_unmatched_strings();
		} catch ( TranslatePressSourceReadException $e ) {
			self::log_db_error( $e->getMessage(), $e->db_error );

			$written = $this->dictionary?->written() ?? [
				'terms'   => 0,
				'menus'   => 0,
				'strings' => 0,
			];

			$this->result['terms']    = $written['terms'];
			$this->result['menus']    = $written['menus'];
			$this->result['strings']  = max( (int) $this->result['strings'], $written['strings'] );
			$this->result['errors'][] = sprintf(
				/* translators: %s: source plugin name */
				__( 'Some %s data could not be read because of a database error, so the import stopped before it finished. What it imported is kept. Run the import again.', 'perflocale' ),
				'TranslatePress'
			);
			$this->mark_incomplete();
		}

		// Flush caches.
		$this->cache->flush_all();

		return $this->result;
	}

	/**
	 * The error left by the source read that just ran, or '' when it worked.
	 *
	 * A failed get_col()/get_results() returns [] exactly as an empty result
	 * does; only last_error tells them apart, and the next query resets it,
	 * so call this straight after the read.
	 *
	 * @phpstan-impure
	 *
	 * @return string
	 */
	private function read_error(): string {
		return $this->wpdb->last_error;
	}

	/**
	 * Record that part of the TranslatePress data was not imported by this run.
	 *
	 * @return void
	 */
	private function mark_incomplete(): void {
		$this->result['incomplete'] = true;
	}

	/**
	 * Log a database error's own text to the PHP error log, when WP_DEBUG_LOG is on.
	 *
	 * That text can carry table names with their prefix and row values.
	 * Migration results and job rows are shown to every user with Jobs
	 * access, so the messages stored there say only that a database error
	 * happened; the detail is here.
	 *
	 * @param string $where What was running, for the log line.
	 * @param string $error The database error.
	 * @return void
	 */
	private static function log_db_error( string $where, string $error ): void {
		if ( $error === '' || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic, only when WP_DEBUG_LOG is on.
		error_log( '[PerfLocale] TranslatePress import: database error while ' . $where . ' - ' . $error );
	}

	/**
	 * Build a mapping of TranslatePress locales to PerfLocale language IDs.
	 *
	 * @return void
	 */
	private function build_language_map(): void {
		$this->source_locale = $this->trp_settings['default-language'] ?? '';
		$trp_languages       = $this->trp_settings['translation-languages'] ?? [];

		if ( empty( $this->source_locale ) || ! is_array( $trp_languages ) ) {
			$this->result['errors'][] = __( 'TranslatePress language configuration is incomplete.', 'perflocale' );
			return;
		}

		$perflocale_languages = $this->languages->get_active();

		// Languages TranslatePress had not published were shown to editors
		// only. Their translations go only into a PerfLocale language that is
		// switched off, so importing never publishes them.
		$published = $this->trp_settings['publish-languages'] ?? null;
		$published = is_array( $published ) ? array_map( 'strval', $published ) : null;

		foreach ( $trp_languages as $trp_locale ) {
			if ( $trp_locale === $this->source_locale ) {
				continue; // Skip source language - it maps to default.
			}

			if ( $published !== null && ! in_array( (string) $trp_locale, $published, true ) ) {
				$this->map_unpublished_language( (string) $trp_locale );
				continue;
			}

			$matched = false;

			// Pass 1: an EXACT locale match must win first, so a regional variant
			// (e.g. de_AT) is never shadowed by a greedy slug-prefix match on its
			// base language (de). Mirrors the WPML/Polylang two-pass mapping.
			foreach ( $perflocale_languages as $pl_lang ) {
				if ( $pl_lang->locale === $trp_locale ) {
					$this->language_map[ $trp_locale ] = (int) $pl_lang->id;
					$matched                           = true;
					break;
				}
			}

			// Pass 2: slug-prefix fallback only when no exact locale matched.
			if ( ! $matched ) {
				foreach ( $perflocale_languages as $pl_lang ) {
					if ( str_starts_with( $trp_locale, $pl_lang->slug ) ) {
						$this->language_map[ $trp_locale ] = (int) $pl_lang->id;
						$matched                           = true;
						break;
					}
				}
			}

			if ( ! $matched ) {
				$this->result['errors'][] = sprintf(
					/* translators: %s: TranslatePress locale code */
					__( 'Could not map TranslatePress language "%s" to a PerfLocale language. Please add this language first.', 'perflocale' ),
					$trp_locale
				);
			}
		}

		// A slug-prefix fallback (Pass 2) can map two TRP regional locales
		// (e.g. de_DE and de_AT) onto the SAME PerfLocale language when only the
		// base language exists. The loop would then process both into one
		// language and silently drop/overwrite the second. Keep the exact-locale
		// claimant (or the first mapped) per PerfLocale id and report the rest.
		$by_pl_id = [];
		foreach ( $this->language_map as $trp_loc => $pl_id ) {
			$by_pl_id[ $pl_id ][] = $trp_loc;
		}
		foreach ( $by_pl_id as $pl_id => $locales ) {
			if ( count( $locales ) < 2 ) {
				continue;
			}
			$keep = null;
			foreach ( $locales as $loc ) {
				foreach ( $perflocale_languages as $pl_lang ) {
					if ( (int) $pl_lang->id === (int) $pl_id && $pl_lang->locale === $loc ) {
						$keep = $loc;
						break 2;
					}
				}
			}
			$keep = $keep ?? $locales[0];
			foreach ( $locales as $loc ) {
				if ( $loc === $keep ) {
					continue;
				}
				unset( $this->language_map[ $loc ] );
				$this->result['errors'][] = sprintf(
					/* translators: %s: TranslatePress locale code */
					__( 'Could not map TranslatePress language "%s" to a PerfLocale language. Please add this language first.', 'perflocale' ),
					$loc
				);
			}
		}

		// A target locale must never resolve to the SAME PerfLocale language as
		// the SOURCE. The source is excluded from language_map (line ~259), so
		// the by_pl_id dedup above cannot catch it: e.g. TRP source de_DE +
		// target de_AT with only a base "de" language (locale de_DE) — de_AT
		// slug-prefix-maps to that same language. Left unguarded, the post loop
		// creates a PUBLISHED duplicate per post and set_post_language(source)
		// evicts the source from its own group (a re-runnable corruption). The
		// fallback-to-default in get_source_perflocale_language() is included on
		// purpose — mapping a target onto the default language triggers the same
		// eviction. Refuse those targets with an actionable error.
		$source_lang = $this->get_source_perflocale_language();

		if ( $source_lang && isset( $source_lang->id ) ) {
			$source_pl_id = (int) $source_lang->id;

			foreach ( $this->language_map as $loc => $pl_id ) {
				if ( (int) $pl_id === $source_pl_id ) {
					unset( $this->language_map[ $loc ] );
					$this->result['errors'][] = sprintf(
						/* translators: %s: TranslatePress locale code */
						__( 'TranslatePress language "%s" resolves to the same PerfLocale language as the source and was skipped. Add a distinct PerfLocale language for this locale to import its translations.', 'perflocale' ),
						$loc
					);
				}
			}
		}
	}

	/**
	 * The dictionary table of each mapped language, keyed by PerfLocale language ID.
	 *
	 * @return array<int, array{0: string, 1: string}> Language ID => [ table, language slug ].
	 */
	private function dictionary_tables(): array {
		$tables = [];

		foreach ( $this->language_map as $trp_locale => $pl_lang_id ) {
			$table   = $this->get_dictionary_table( $this->source_locale, (string) $trp_locale );
			$pl_lang = $this->languages->find( (int) $pl_lang_id );

			if ( $table !== null && $pl_lang !== null ) {
				$tables[ (int) $pl_lang_id ] = [ $table, (string) $pl_lang->slug ];
			}
		}

		return $tables;
	}

	/**
	 * Translate term names from dictionary strings that belong to no post.
	 *
	 * @return int Term translations created.
	 */
	private function import_term_names(): int {
		$source = $this->get_source_perflocale_language();

		if ( $source === null || $this->dictionary === null ) {
			return 0;
		}

		$created = 0;
		$tables  = $this->dictionary_tables();
		$done    = 0;

		$this->beat( 'term_names', 0, count( $tables ) );

		foreach ( $tables as [ $table, $slug ] ) {
			$created += $this->dictionary->import_terms( $table, (string) $source->slug, $slug, $this->settings->get_translatable_taxonomies(), $this->result['errors'] );

			++$done;
			$this->beat( 'term_names', $done, count( $tables ) );
		}

		return $created;
	}

	/**
	 * Copy menus into each language with their translated labels.
	 *
	 * @return int Menus created.
	 */
	private function import_menu_labels(): int {
		$source = $this->get_source_perflocale_language();

		if ( $source === null || $this->dictionary === null ) {
			return 0;
		}

		$created = 0;
		$tables  = $this->dictionary_tables();
		$done    = 0;

		$this->beat( 'menus', 0, count( $tables ) );

		foreach ( $tables as [ $table, $slug ] ) {
			$created += $this->dictionary->import_menus( $table, (string) $source->slug, $slug, $this->result['errors'] );

			++$done;
			$this->beat( 'menus', $done, count( $tables ) );
		}

		return $created;
	}

	/**
	 * Import the site title and tagline translations.
	 *
	 * @return int Translations stored.
	 */
	private function import_option_strings(): int {
		$dictionary = $this->dictionary;

		if ( $dictionary === null ) {
			return 0;
		}

		$stored = 0;

		foreach ( $this->dictionary_tables() as $pl_lang_id => [ $table ] ) {
			$stored += $dictionary->import_option_strings( $table, (int) $pl_lang_id );
			$this->beat();
		}

		return $stored;
	}

	/**
	 * Report the dictionary strings outside post content that matched nothing.
	 *
	 * @return void
	 */
	private function report_unmatched_strings(): void {
		if ( $this->dictionary === null ) {
			return;
		}

		$meta_table = $this->wpdb->prefix . 'trp_original_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The table name is bound through prepare().
		$meta_exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$meta_table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( $meta_exists === 0 ) {
			return;
		}

		foreach ( $this->dictionary_tables() as [ $table, $slug ] ) {
			$unmatched = $this->dictionary->unmatched_count( $table, $meta_table );

			if ( $unmatched > 0 ) {
				$this->result['errors'][] = sprintf(
					/* translators: 1: number of strings, 2: language slug */
					_n( '%1$d TranslatePress translation in %2$s belongs to no post and matches no term name, menu label, site title, tagline or excerpt, so it was not imported (widget text and theme or plugin output, for example).', '%1$d TranslatePress translations in %2$s belong to no post and match no term name, menu label, site title, tagline or excerpt, so they were not imported (widget text and theme or plugin output, for example).', $unmatched, 'perflocale' ),
					$unmatched,
					$slug
				);
			}
		}
	}

	/**
	 * Map a language TranslatePress had not published.
	 *
	 * Only a PerfLocale language that is switched off takes its translations
	 * (matched as the published ones are: exact locale first, then slug
	 * prefix); visitors do not see them until the language is switched on.
	 * Otherwise the language is skipped with a notice.
	 *
	 * @param string $trp_locale TranslatePress locale.
	 * @return void
	 */
	private function map_unpublished_language( string $trp_locale ): void {
		$inactive = array_values(
			array_filter(
				$this->languages->find_all(),
				static fn( object $lang ): bool => empty( $lang->is_active ) && empty( $lang->is_default )
			)
		);

		$match = null;

		foreach ( $inactive as $pl_lang ) {
			if ( $pl_lang->locale === $trp_locale ) {
				$match = $pl_lang;
				break;
			}
		}

		if ( $match === null ) {
			foreach ( $inactive as $pl_lang ) {
				if ( str_starts_with( $trp_locale, (string) $pl_lang->slug ) ) {
					$match = $pl_lang;
					break;
				}
			}
		}

		if ( $match === null ) {
			$this->result['errors'][] = sprintf(
				/* translators: %s: TranslatePress locale code */
				__( 'TranslatePress had not published "%s" (only editors saw it), so its translations were not imported. To import them without publishing them, add the language under PerfLocale → Languages, switch it off, and run the import again.', 'perflocale' ),
				$trp_locale
			);
			return;
		}

		$this->language_map[ $trp_locale ] = (int) $match->id;

		$this->result['errors'][] = sprintf(
			/* translators: 1: TranslatePress locale code, 2: PerfLocale language name */
			__( 'TranslatePress had not published "%1$s"; its translations were imported into %2$s, which is switched off, so visitors do not see them until you switch it on.', 'perflocale' ),
			$trp_locale,
			(string) ( $match->name ?? $match->slug )
		);
	}

	/**
	 * Import post/page translations from TranslatePress dictionary tables.
	 *
	 * TranslatePress stores string-level translations. This method:
	 * 1. Finds all posts that have translated strings
	 * 2. For each post + target language, reconstructs translated content
	 * 3. Creates a new WordPress post with the translated content
	 * 4. Links it in a PerfLocale translation group
	 *
	 * @return int Number of posts imported.
	 */
	private function import_post_translations(): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		global $shortcode_tags;

		$imported  = 0;
		$manager   = new PostTranslationManager( $this->cache, $this->settings );
		$rebuilder = new TranslatePressContentRebuilder( is_array( $shortcode_tags ) ? array_map( 'strval', array_keys( $shortcode_tags ) ) : [] );

		$original_table = $this->wpdb->prefix . 'trp_original_strings';
		$meta_table     = $this->wpdb->prefix . 'trp_original_meta';

		// Check if meta table exists (older TRP versions may not have it).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$meta_table
			)
		);

		if ( ! $meta_exists ) {
			$this->result['errors'][] = __( 'TranslatePress original_meta table not found. Post-level migration not possible.', 'perflocale' );
			return 0;
		}

		// Get all unique post IDs that have translations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT meta_value FROM %i WHERE meta_key = %s ORDER BY meta_value ASC',
				$meta_table,
				'post_parent_id'
			)
		);

		// On a failed read keep the resume checkpoint: the sweep below is for
		// a source with nothing left to import.
		$read_error = $this->read_error();

		if ( $read_error !== '' ) {
			self::log_db_error( 'reading the post list', $read_error );

			$this->result['errors'][] = sprintf(
				/* translators: %s: source plugin name */
				__( 'Some %s post data could not be read because of a database error and was not imported. Re-run the import.', 'perflocale' ),
				'TranslatePress'
			);
			$this->mark_incomplete();
			return 0;
		}

		if ( empty( $post_ids ) ) {
			// Sweep any leftover checkpoint from a prior run — there's
			// nothing more to import, so the option becomes stale.
			delete_option( self::POST_CHECKPOINT_OPTION );
			delete_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION );
			return 0;
		}

		// meta_value is a longtext column, so the SQL ORDER BY sorts it
		// LEXICOGRAPHICALLY ("10" < "2"). The resume checkpoint (highest
		// committed post_id) is only a valid high-water mark under NUMERIC
		// ascending order - otherwise a committed batch holding a
		// numerically-large id pushes the checkpoint past lower-numbered ids
		// that were never processed, and a resumed run skips them forever.
		$post_ids = array_map( 'intval', $post_ids );
		sort( $post_ids, SORT_NUMERIC );

		// Resumability checkpoint: pick up where a previous run stopped.
		// The option stores the highest post_id committed by the last
		// successful batch. Skip anything ≤ that id so a watchdog-killed
		// import doesn't redo work on restart (and so the operator can
		// re-trigger the job without manually filtering).
		$checkpoint  = (int) get_option( self::POST_CHECKPOINT_OPTION, 0 );
		$fingerprint = $this->checkpoint_fingerprint();

		if ( $checkpoint > 0 ) {
			$stored_fingerprint = get_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION, '' );

			if ( is_string( $stored_fingerprint ) && $stored_fingerprint !== '' && $stored_fingerprint !== $fingerprint ) {
				delete_option( self::POST_CHECKPOINT_OPTION );
				delete_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION );
				$checkpoint = 0;
			}
		}

		if ( $checkpoint > 0 ) {
			$post_ids_filtered = [];

			foreach ( $post_ids as $pid ) {
				$pid_i = (int) $pid;
				if ( $pid_i > $checkpoint ) {
					$post_ids_filtered[] = $pid_i;
				}
			}

			$post_ids = $post_ids_filtered;

			if ( empty( $post_ids ) ) {
				// Everything past the checkpoint was already processed.
				// Clear the checkpoint so a future fresh-run doesn't
				// silently no-op.
				delete_option( self::POST_CHECKPOINT_OPTION );
				delete_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION );
				return 0;
			}
		}

		/**
		 * Posts processed per transaction during TranslatePress migration.
		 * Default 50. Each post triggers a post-insert + translation-link
		 * write; bigger batches = fewer transactions but longer rollback
		 * windows on error. Clamped to 5–500.
		 *
		 * @hook perflocale/migration/translatepress/batch_size
		 * @param int $size Default 50.
		 */
		$batch_size = (int) apply_filters( 'perflocale/migration/translatepress/batch_size', self::BATCH_SIZE );
		$batch_size = max( 5, min( 500, $batch_size ) );

		// Process in batches.
		$batches = array_chunk( array_map( 'intval', $post_ids ), $batch_size );

		// Resume low-water mark: once any batch fails, the checkpoint must not
		// advance past it — otherwise a later committed batch pushes the
		// checkpoint beyond the failed ids and a resume skips them forever.
		$first_failed_floor = PHP_INT_MAX;

		$this->beat( 'posts', 0, count( $post_ids ) );

		foreach ( $batches as $batch_index => $batch ) {
			$this->beat( 'posts', min( count( $post_ids ), (int) $batch_index * $batch_size ), count( $post_ids ) );
			$rebuilder->forget();

			$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// Register this raw transaction so nested create_group()/link_object()
			// calls join it instead of issuing a second START TRANSACTION (which
			// MySQL treats as an implicit COMMIT of this outer one, defeating the
			// batch ROLLBACK below). Cleared in the finally on every exit path.
			$this->groups->set_in_transaction( true );

			// Snapshot the running totals so a mid-batch failure can roll
			// them back together with the SQL transaction. Previously, a
			// throw inside wp_insert_post / set_post_language / link_object
			// after the SQL rows had been written but BEFORE COMMIT would
			// silently abort the batch and the `imported` / `errors`
			// counters drifted from the actual database state — surfacing
			// to the operator as "we imported 50 posts" when only 12
			// actually persisted.
			$pre_batch_imported = $imported;
			$pre_batch_skipped  = (int) ( $this->result['skipped'] ?? 0 );
			$pre_batch_existing = [ $this->result['existing'], $this->result['existing_targets'] ];
			$pre_batch_errors   = (array) ( $this->result['errors'] ?? [] );

			// Posts this batch inserted; a failed batch deletes the ones its
			// ROLLBACK did not remove (see delete_unlinked_batch_posts()).
			$batch_new_posts = [];

			$batch_failed = false;

			try {

				foreach ( $batch as $post_id ) {
					$post = get_post( $post_id );

					if ( ! $post ) {
						++$this->result['skipped'];
						continue;
					}

					// NOTE: no coarse "post already has ANY translation" skip here.
					// get_translations() returns every group link including the
					// source's own, so a `count > 1` guard skipped a post that had
					// a translation in SOME OTHER language — dropping still-pending
					// languages on a resume/re-import (es done, de never imported).
					// Per-(post, language) idempotency is enforced below by
					// get_translation_in_language(), which is the correct grain.

					// Get all original_id values for this post.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$original_ids = $this->wpdb->get_col(
						$this->wpdb->prepare(
							"SELECT original_id FROM %i WHERE meta_key = 'post_parent_id' AND meta_value = %d",
							$meta_table,
							$post_id
						)
					);

					// Throw on a failed read, so the catch below rolls the batch
					// back and the checkpoint stays below this post.
					$read_error = $this->read_error();

					if ( $read_error !== '' ) {
						self::log_db_error( 'reading post ' . $post_id, $read_error );

						throw new TranslatePressSourceReadException( 'reading post ' . $post_id . ' failed', $read_error );
					}

					if ( empty( $original_ids ) ) {
						continue;
					}

					// Get original strings for these IDs.
					$id_placeholders = implode( ',', array_fill( 0, count( $original_ids ), '%d' ) );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$originals = $this->wpdb->get_results(
						// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
						$this->wpdb->prepare(
							"SELECT id, original FROM %i WHERE id IN ({$id_placeholders})",
							$original_table,
							...array_map( 'intval', $original_ids )
						)
					);

					$read_error = $this->read_error();

					if ( $read_error !== '' ) {
						self::log_db_error( 'reading post ' . $post_id, $read_error );

						throw new TranslatePressSourceReadException( 'reading post ' . $post_id . ' failed', $read_error );
					}

					if ( empty( $originals ) ) {
						continue;
					}

					$original_map = [];
					$with_blocks  = false;

					foreach ( $originals as $row ) {
						$original_map[ (int) $row->id ] = $row->original;

						// An original that holds markup is a TranslatePress translation block.
						$with_blocks = $with_blocks || str_contains( (string) $row->original, '<' );
					}

					// The post's fields split into translatable units, once for all languages.
					$parsed = null;

					// For each target language, build translated content.
					foreach ( $this->language_map as $trp_locale => $pl_lang_id ) {
						$dict_table = $this->get_dictionary_table( $this->source_locale, $trp_locale );

						if ( ! $dict_table ) {
							continue;
						}

						// Get translations for these original IDs (manually translated only).
						// With translation blocks among the originals, every column is
						// read, so each row carries block_type where the table has it.
						$columns = $with_blocks ? '*' : 'original_id, translated';

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$translations = $this->wpdb->get_results(
							$this->wpdb->prepare(
								"SELECT {$columns} FROM %i WHERE original_id IN ({$id_placeholders}) AND status >= %d AND translated != ''",
								$dict_table,
								...array_merge( array_map( 'intval', $original_ids ), [ $this->min_status() ] )
							)
						);

						$read_error = $this->read_error();

						if ( $read_error !== '' ) {
							self::log_db_error( 'reading post ' . $post_id . ' in ' . $trp_locale, $read_error );

							throw new TranslatePressSourceReadException( 'reading post ' . $post_id . ' in ' . $trp_locale . ' failed', $read_error );
						}

						if ( empty( $translations ) ) {
							continue;
						}

						// Reconstruct translated content from the whole units
						// TranslatePress translated: a text run, an attribute value,
						// a block attribute, a shortcode value or a translation
						// block is replaced only as a whole, never inside URLs or
						// other text, and a replaced unit is never scanned again.
						$title   = $post->post_title;
						$content = $post->post_content;
						$excerpt = $post->post_excerpt;

						// An empty original is skipped; when two ids share an
						// original, the first row's translation wins. TranslatePress
						// does not serve a deprecated translation block
						// (block_type 2), so it is left out.
						$pairs = [];

						foreach ( $translations as $trans ) {
							$oid = (int) $trans->original_id;

							if ( ! isset( $original_map[ $oid ] ) || ( isset( $trans->block_type ) && 2 === (int) $trans->block_type ) ) {
								continue;
							}

							$original = (string) $original_map[ $oid ];

							if ( $original === '' || isset( $pairs[ $original ] ) ) {
								continue;
							}

							$pairs[ $original ] = (string) $trans->translated;
						}

						if ( $pairs !== [] ) {
							if ( $parsed === null ) {
								$parsed = [
									'title'   => $rebuilder->parse( $title, $with_blocks ),
									'content' => $rebuilder->parse( $content, $with_blocks ),
									'excerpt' => $rebuilder->parse( $excerpt, $with_blocks ),
								];

								// A field that cannot be split keeps its source text.
								if ( in_array( null, $parsed, true ) ) {
									$this->result['errors'][] = sprintf(
										/* translators: %d: post ID */
										__( 'Part of post %d could not be parsed, so that part keeps its source-language text in the imported translations.', 'perflocale' ),
										$post_id
									);
								}
							}

							$title   = $parsed['title'] !== null ? $rebuilder->render( $parsed['title'], $pairs ) : $title;
							$content = $parsed['content'] !== null ? $rebuilder->render( $parsed['content'], $pairs ) : $content;
							$excerpt = $parsed['excerpt'] !== null ? $rebuilder->render( $parsed['excerpt'], $pairs ) : $excerpt;
						}

						// TranslatePress attributes a post's excerpt (a WooCommerce
						// short description) to no post: look it up whole, or line
						// by line.
						if ( $excerpt === $post->post_excerpt && $this->dictionary !== null ) {
							try {
								$excerpt = $this->dictionary->excerpt( $dict_table, $excerpt ) ?? $excerpt;
							} catch ( TranslatePressSourceReadException $e ) {
								self::log_db_error( 'reading the ' . $trp_locale . ' excerpt of post ' . $post_id, $e->db_error );

								throw $e;
							}
						}

						// Skip if nothing was actually translated.
						if ( $title === $post->post_title && $content === $post->post_content && $excerpt === $post->post_excerpt ) {
							continue;
						}

						// Cross-restore idempotency check — does a PerfLocale
						// translation already exist for this (source_post,
						// target_lang)? Without this, a re-import after a DB
						// restore that lost POST_CHECKPOINT_OPTION (but kept
						// the translation_links rows) duplicates every
						// translation post on every subsequent run. Reads
						// from the translation_links table, so it survives
						// any restore that includes the perflocale tables.
						$existing_link = null;

						foreach ( $this->groups->get_translations( $post_id, ObjectType::Post ) as $link ) {
							if ( (int) $link->language_id === $pl_lang_id ) {
								$existing_link = $link;
								break;
							}
						}

						if ( $existing_link !== null ) {
							// Translation already exists; re-record the
							// source_map so a subsequent --force-restart
							// can clear it cleanly. Cheap (single UPSERT).
							$source_group_existing = $this->groups->find_for_object( $post_id, ObjectType::Post );
							if ( $source_group_existing ) {
								$this->source_map->set_group_id(
									'trp',
									$post_id . '|' . $pl_lang_id,
									(int) $source_group_existing->id
								);
							}

							// A translation this importer wrote on an earlier run
							// is the re-run case. Anything else keeps its content
							// and this TranslatePress translation is not
							// imported, so it is counted and named in the result.
							if ( SourceType::ImportedTrp->value !== (string) ( $existing_link->source ?? '' ) ) {
								++$this->result['existing'];

								if ( count( $this->result['existing_targets'] ) < self::EXISTING_TARGETS_CAP ) {
									$this->result['existing_targets'][] = [
										'post'        => (int) $post_id,
										'language'    => (string) ( $existing_link->language_slug ?? '' ),
										'translation' => (int) $existing_link->object_id,
									];
								}
							}
							continue;
						}

						$pl_lang = $this->languages->find( $pl_lang_id );

						if ( ! $pl_lang ) {
							continue;
						}

						// The source's language goes first, so the unique-slug check
						// sees the source in another language and lets the
						// translation keep the same slug.
						$source_lang = $this->get_source_perflocale_language();

						if ( $source_lang && ! $manager->set_post_language( $post_id, $source_lang->slug ) ) {
							$this->result['errors'][] = sprintf(
								/* translators: 1: language slug, 2: post ID */
								__( 'Failed to assign source language "%1$s" to post %2$d.', 'perflocale' ),
								$source_lang->slug,
								$post_id
							);
						}

						// A child page goes under its parent's translation when
						// there is one, as PerfLocale's own translations do.
						$parent_id = (int) $post->post_parent;

						if ( $parent_id > 0 ) {
							$parent_id = (int) ( $manager->get_translations( $parent_id )[ $pl_lang->slug ] ?? $parent_id );
						}

						// Create the translated post. Pass $wp_error=true so the
						// is_wp_error() guard below sees real DB-level failures
						// (unique-violation, FK orphan) instead of just the 0
						// "couldn't insert" path that silently masks them.
						// The translation keeps the source's author, dates,
						// discussion settings, password and slug: TranslatePress
						// served the same post under the same slug in every
						// language, so the old URLs keep working.
						$new_post_id = $this->insert_translation_post(
							// wp_slash: reconstructed content comes from raw DB
							// reads (unslashed); wp_insert_post() unslashes
							// internally, stripping backslashes otherwise.
							wp_slash(
								[
									'post_type'      => $post->post_type,
									'post_status'    => $post->post_status,
									'post_author'    => (int) $post->post_author,
									'post_date'      => $post->post_date,
									'post_date_gmt'  => $post->post_date_gmt,
									'post_name'      => $post->post_name,
									'post_password'  => $post->post_password,
									'comment_status' => $post->comment_status,
									'ping_status'    => $post->ping_status,
									'post_title'     => \PerfLocale\Helper::sanitize_plain_text_field( $title ),
									'post_content'   => wp_kses_post( $content ),
									'post_excerpt'   => wp_kses_post( $excerpt ),
									'post_parent'    => $parent_id,
									'menu_order'     => $post->menu_order,
								]
							),
							(string) $pl_lang->slug
						);

						if ( is_wp_error( $new_post_id ) ) {
							$this->result['errors'][] = sprintf(
								/* translators: 1: post ID, 2: TranslatePress locale code, 3: error message */
								__( 'Failed to create translation for post %1$d in %2$s: %3$s', 'perflocale' ),
								$post_id,
								$trp_locale,
								$new_post_id->get_error_message()
							);
							$this->mark_incomplete();
							continue;
						}

						if ( $new_post_id === 0 ) {
							$this->result['errors'][] = sprintf(
								/* translators: 1: post ID, 2: TranslatePress locale code */
								__( 'Failed to create translation for post %1$d in %2$s.', 'perflocale' ),
								$post_id,
								$trp_locale
							);
							$this->mark_incomplete();
							continue;
						}

						// Recorded before anything below can throw.
						$batch_new_posts[] = (int) $new_post_id;

						// Link to translation group.
						if ( ! $manager->set_post_language( $new_post_id, $pl_lang->slug ) ) {
							$this->result['errors'][] = sprintf(
								/* translators: 1: language slug, 2: post ID */
								__( 'Failed to assign language "%1$s" to translation post %2$d.', 'perflocale' ),
								$pl_lang->slug,
								$new_post_id
							);
						}

						// Ensure both are in the same group.
						$source_group = $this->groups->find_for_object( $post_id, ObjectType::Post );

						if ( $source_group ) {
							// Throw on link_object false so the
							// surrounding try/catch ROLLBACKs the batch.
							// Without the throw, $imported is still
							// bumped (line below) for posts that never
							// got linked into their translation group,
							// inflating the success count over reality.
							$link_id = $this->groups->link_object(
								(int) $source_group->id,
								$new_post_id,
								$pl_lang_id,
								TranslationStatus::Published->value,
								SourceType::ImportedTrp
							);
							if ( $link_id === false ) {
								throw new \RuntimeException( 'trp_import: link_object failed for post ' . $new_post_id );
							}

							// Record the source_map mapping so the
							// `--force-restart` CLI flag can clear it
							// symmetrically with WPML and Polylang.
							// Outside the link_object transaction by
							// design — TRP doesn't go through
							// create_group(), so the in-transaction
							// optimisation that WPML/PLL get isn't
							// available here. Worst case: a crash
							// between link_object commit and this row
							// leaves a missing map row for one (post,
							// lang); the next re-import re-records it
							// via the same path.
							$this->source_map->set_group_id(
								'trp',
								$post_id . '|' . $pl_lang_id,
								(int) $source_group->id
							);

							// What PerfLocale's own translations get: the source's
							// meta (page template, product data), featured image and
							// terms, then perflocale/translation/created, where the
							// WooCommerce addon sets the product type and creates
							// the variations.
							$manager->complete_imported_translation( (int) $new_post_id, (int) $post_id, (string) $pl_lang->slug );
						} else {
							// No source group (e.g. the source set_post_language
							// above failed): the freshly-inserted translation
							// post can't be linked. Throw — same rationale as
							// the link_object-false path — so the batch rolls
							// back and the catch deletes the post instead of
							// leaving it unlinked AND counting it in $imported.
							throw new \RuntimeException( 'trp_import: source post ' . $post_id . ' has no translation group; cannot link translation post ' . $new_post_id );
						}

						++$imported;
					}
				}
			} catch ( \Throwable $e ) {
				// Any throw inside the batch (hook callback, KSES filter,
				// MySQL deadlock, failed source read, etc.) lands here.
				// ROLLBACK undoes the batch's writes to transactional
				// tables and the counters return to their pre-batch values,
				// so the result reflects what's actually in the DB. The
				// translation posts a non-transactional (MyISAM) wp_posts
				// kept are deleted below.
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				$imported                 = $pre_batch_imported;
				$this->result['skipped']  = $pre_batch_skipped;
				$this->result['errors']   = $pre_batch_errors;
				$this->result['errors'][] = sprintf(
					/* translators: 1: batch number, 2: error message */
					__( 'TranslatePress migration batch #%1$d failed: %2$s', 'perflocale' ),
					(int) $batch_index,
					$e->getMessage()
				);

				[ $this->result['existing'], $this->result['existing_targets'] ] = $pre_batch_existing;

				$batch_failed = true;
				$this->mark_incomplete();

				// Clamp the resume checkpoint below this failed batch's ids so a
				// later committed batch can't push it past them (re-attempted on
				// resume; the line-391 "already translated" guard makes re-runs
				// of committed posts idempotent).
				$first_failed_floor = min( $first_failed_floor, (int) min( $batch ) );
				wp_cache_flush();

				// After the flush, so the delete hooks see committed state.
				$this->delete_unlinked_batch_posts( (int) $batch_index, $batch_new_posts );

				// Continue to the next batch instead of aborting the
				// entire migration: one batch's bad data shouldn't lock
				// the operator out of migrating everything else.
				continue;
			} finally {
				// Always clear the shared transaction flag, on success OR throw,
				// so it can't leak `true` into later create_group()/link_object().
				$this->groups->set_in_transaction( false );
			}

			if ( ! $batch_failed ) {
				$committed = $this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( false === $committed ) {
					// COMMIT failed (lock timeout / deadlock / connection
					// loss): the server has rolled the batch's transactional
					// writes back. Undo the in-PHP bookkeeping, delete the
					// translation posts a non-transactional wp_posts kept, and
					// skip the checkpoint advance so a resume re-runs this
					// batch instead of treating it as committed.
					$imported                 = $pre_batch_imported;
					$this->result['skipped']  = $pre_batch_skipped;
					$this->result['errors']   = $pre_batch_errors;
					$this->result['errors'][] = sprintf(
						/* translators: %d: batch number */
						__( 'TranslatePress migration batch #%d failed to commit.', 'perflocale' ),
						(int) $batch_index
					);

					[ $this->result['existing'], $this->result['existing_targets'] ] = $pre_batch_existing;

					$this->mark_incomplete();

					$first_failed_floor = min( $first_failed_floor, (int) min( $batch ) );
					wp_cache_flush();
					$this->delete_unlinked_batch_posts( (int) $batch_index, $batch_new_posts );
					continue;
				}

				// Advance the resumability checkpoint to the highest
				// post_id in the just-committed batch. Recording it AFTER
				// the COMMIT means a crash between COMMIT and this update
				// at worst re-imports one batch (idempotent — the
				// existing "skip if post already has PerfLocale
				// translations" guard inside the loop handles re-runs
				// cleanly). update_option's autoload flag stays false so
				// this doesn't bloat alloptions for sites that ran the
				// importer once and forgot to clean up.
				$checkpoint = (int) max( $batch );
				if ( $first_failed_floor !== PHP_INT_MAX ) {
					// A prior batch failed — never record a checkpoint at or
					// above its lowest id, so the resume re-attempts it.
					$checkpoint = min( $checkpoint, $first_failed_floor - 1 );
				}
				update_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION, $fingerprint, false );
				update_option( self::POST_CHECKPOINT_OPTION, $checkpoint, false );

				// The batch is committed, so a persistent cache holds only
				// committed state; drop this process's runtime copies.
				\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
			}
		}

		$this->beat( 'posts', count( $post_ids ), count( $post_ids ) );

		// Migration finished — drop the checkpoint so a future fresh-run
		// starts from the top instead of skipping every previously-
		// migrated id.
		delete_option( self::POST_CHECKPOINT_OPTION );
		delete_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
	}

	/**
	 * Fingerprint of the language selection a post checkpoint belongs to.
	 *
	 * The TranslatePress source locale, the PerfLocale language it resolves
	 * to, and every mapped target (locale => PerfLocale language id).
	 *
	 * @return string
	 */
	private function checkpoint_fingerprint(): string {
		$targets = $this->language_map;
		ksort( $targets );

		$source = $this->get_source_perflocale_language();

		return md5(
			(string) wp_json_encode(
				[
					(string) $this->source_locale,
					$source && isset( $source->id ) ? (int) $source->id : 0,
					$targets,
				]
			)
		);
	}

	/**
	 * Delete the translation posts a failed batch inserted that are still in wp_posts.
	 *
	 * ROLLBACK removes the batch's links, groups and source-map rows, but a
	 * wp_posts table on a non-transactional engine (MyISAM) keeps the inserted
	 * posts: published, unlinked (so listed in every language), and inserted
	 * again by the next run. On InnoDB the ROLLBACK already removed them, so
	 * the SELECT finds none and no delete hook fires.
	 *
	 * @param int        $batch_index Batch number, for the error message.
	 * @param array<int> $post_ids    Post IDs this batch's wp_insert_post() calls returned.
	 * @return void
	 */
	private function delete_unlinked_batch_posts( int $batch_index, array $post_ids ): void {
		// The batch transaction is over: the delete hooks' writes must not
		// treat it as still open.
		$this->groups->set_in_transaction( false );

		if ( $post_ids === [] ) {
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// Read wp_posts itself: wp_insert_post() cached these posts, so
		// get_post() would still find the ones the ROLLBACK removed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $this->wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are unpacked, which WPCS cannot count; the %i table name leads, then one %d per id.
			$this->wpdb->prepare(
				"SELECT ID FROM %i WHERE ID IN ({$id_placeholders})",
				$this->wpdb->posts,
				...$post_ids
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$read_error = $this->read_error();

		if ( $read_error !== '' ) {
			self::log_db_error( 'checking for leftover translation posts', $read_error );

			$this->result['errors'][] = sprintf(
				/* translators: 1: batch number, 2: comma-separated post IDs */
				__( 'TranslatePress migration batch #%1$d: could not check for leftover translation posts %2$s.', 'perflocale' ),
				$batch_index,
				implode( ', ', $post_ids )
			);
			$this->mark_incomplete();
			return;
		}

		$not_deleted = [];

		foreach ( array_map( 'intval', $remaining ) as $remaining_id ) {
			// One caller is the batch's catch block: an exception here would
			// escape import().
			try {
				$deleted = wp_delete_post( $remaining_id, true );
			} catch ( \Throwable $e ) {
				$deleted = null;
			}

			if ( ! $deleted instanceof \WP_Post ) {
				$not_deleted[] = $remaining_id;
			}
		}

		if ( $not_deleted !== [] ) {
			$this->result['errors'][] = sprintf(
				/* translators: 1: batch number, 2: comma-separated post IDs */
				__( 'TranslatePress migration batch #%1$d: could not delete translation posts %2$s; delete them by hand.', 'perflocale' ),
				$batch_index,
				implode( ', ', $not_deleted )
			);
			$this->mark_incomplete();
		}
	}

	/**
	 * Import gettext string translations.
	 *
	 * @return int Number of strings imported.
	 */
	private function import_string_translations(): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$imported            = 0;
		$link_failures       = 0;
		$string_translations = new StringTranslationRepository( $this->cache );

		$gettext_original_table = $this->wpdb->prefix . 'trp_gettext_original_strings';

		// Check if gettext tables exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$gettext_original_table
			)
		);

		if ( ! $exists ) {
			return 0;
		}

		// Gettext batch size is filterable: large-string sites tune down,
		// generous-memory hosts crank up. Bounded floor of 100 prevents
		// degenerate per-row pagination if someone passes garbage.
		$gettext_batch_size = (int) apply_filters(
			'perflocale/migration/translatepress/gettext_batch_size',
			self::GETTEXT_BATCH_SIZE
		);

		if ( $gettext_batch_size < 100 ) {
			$gettext_batch_size = self::GETTEXT_BATCH_SIZE;
		}

		$done  = 0;
		$total = 0;

		foreach ( $this->language_map as $trp_locale => $pl_lang_id ) {
			$gettext_table = Schema::sanitize_table( $this->wpdb->prefix . 'trp_gettext_' . strtolower( str_replace( '-', '_', $trp_locale ) ) );

			// Check if this language's gettext table exists.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$lang_exists = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$gettext_table
				)
			);

			if ( ! $lang_exists ) {
				continue;
			}

			// Plural forms 2..N, stored once their plural row exists.
			$extra_forms = [];

			// Tables from TranslatePress versions without plural support have
			// neither column.
			$plural_columns = $this->has_column( $gettext_original_table, 'original_plural' ) && $this->has_column( $gettext_table, 'plural_form' )
				? 'o.original_plural, g.plural_form'
				: 'NULL AS original_plural, 0 AS plural_form';

			// Keyset-paginated batch loop. Replaces a single SELECT with a
			// flat `LIMIT 10000` cap that:
			// (a) silently truncated any site with >10k gettext strings
			// per language — rows past the cap never imported.
			// (b) pulled the whole dictionary into PHP memory at once —
			// 10000 rows × ~500 B per row = ~5 MB per language, and
			// on a 5-language site this peaked at ~25 MB before the
			// inner loop even started running.
			// WHERE g.original_id > $last_id is index-seek-cheap per
			// batch (constant time vs OFFSET's linear scan), and the
			// `unset( $rows )` between batches lets PHP reclaim memory.
			$last_id = 0;

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT g.id AS gettext_row_id, g.original_id, o.original, o.domain, o.context, g.translated, {$plural_columns}
						FROM %i g
						INNER JOIN %i o ON o.id = g.original_id
						WHERE g.translated != '' AND g.status >= %d AND g.status <= %d AND g.id > %d
						ORDER BY g.id ASC
						LIMIT %d",
						$gettext_table,
						$gettext_original_table,
						$this->min_status(),
						$this->gettext_max_status(),
						$last_id,
						$gettext_batch_size
					)
				);

				// A failed SELECT also returns no rows; without this check the
				// rest of this language would end silently under a success result.
				$read_error = $this->read_error();

				if ( $read_error !== '' ) {
					self::log_db_error( 'reading the ' . $trp_locale . ' strings', $read_error );

					$this->result['errors'][] = sprintf(
						/* translators: %s: TRP locale */
						__( 'TranslatePress string import failed for %s because of a database error. Re-run the import.', 'perflocale' ),
						$trp_locale
					);
					$this->mark_incomplete();
					break;
				}

				if ( ! is_array( $rows ) || $rows === [] ) {
					break;
				}

				foreach ( $rows as $row ) {
					// Sanitize the domain ONCE and use the same value for both
					// the dedup lookup and the insert — otherwise find_by_hash()
					// (raw domain) and insert() (sanitized domain) compute
					// different hashes, breaking idempotency for domains that
					// change under sanitize_text_field().
					$domain = sanitize_text_field( (string) ( $row->domain ?? 'default' ) );

					// TRP stores no-context strings under the literal 'trp_context'
					// sentinel and real _x() contexts verbatim. Map the sentinel to
					// PerfLocale's empty context so imported strings share the
					// runtime identity (domain|context|original) that _x()/
					// find_by_hash() use — otherwise an _x() string imports under
					// context '' and stays untranslated at runtime.
					$raw_context = (string) ( $row->context ?? '' );
					$context     = ( $raw_context === '' || $raw_context === 'trp_context' ) ? '' : $raw_context;
					$original    = (string) $row->original;

					// A plural string's forms are separate rows (plural_form 0..N).
					// PerfLocale keeps form 0 on the singular under the context
					// 'singular' (or the _nx() context), form 1 on the plural
					// under 'plural' (or '<context> (plural)'), and forms 2..N in
					// that plural row's extra forms, as the scanner and the
					// ngettext filters do.
					$plural_original = (string) ( $row->original_plural ?? '' );
					$plural_form     = (int) ( $row->plural_form ?? 0 );

					if ( $plural_original !== '' ) {
						if ( $plural_form === 0 ) {
							$context = $context !== '' ? $context : 'singular';
						} else {
							$context  = $context !== '' ? $context . ' (plural)' : 'plural';
							$original = $plural_original;
						}

						if ( $plural_form >= 2 ) {
							$extra_forms[ $domain . "\0" . $context . "\0" . $original ][ $plural_form - 2 ] = sanitize_textarea_field( (string) $row->translated );
							continue;
						}
					}

					// Check if string already exists in PerfLocale.
					$existing = $this->strings->find_by_hash( $domain, $context, $original );

					if ( ! $existing ) {
						// Insert the string (hash is computed by StringRepository::insert()).
						$string_id = $this->strings->insert(
							[
								'domain'    => $domain,
								'context'   => $context,
								'original'  => $original,
								'file_path' => 'translatepress-import',
							]
						);
					} else {
						$string_id = (int) $existing->id;
					}

					if ( $string_id ) {
						// Idempotency: don't clobber an existing translation (e.g. a
						// human correction made after a prior migration run) — skip
						// only the value write. The link upsert below must still run:
						// the value row alone is never served (every serving layer
						// INNER JOINs translation_links), so a value whose link write
						// once failed can only self-heal through a re-run here.
						$has_translation = $string_translations->get( (int) $string_id, (int) $pl_lang_id ) !== '';

						if ( ! $has_translation ) {
							// The return decides both the count and the link. A
							// discarded false counted a string as imported and then
							// marked its group 'translated' for a value that was
							// never stored — the operator read a success total over
							// rows the site cannot serve. Same shape as the WPML
							// importer's string loop.
							$saved = $string_translations->set(
								(int) $string_id,
								(int) $pl_lang_id,
								// sanitize_textarea_field (not sanitize_text_field) so
								// multi-line translations keep their newlines — TRP's
								// own save path (trp_sanitize_string) preserves \r\n\t
								// and collapsing them here would diverge from the
								// source and from the native PerfLocale save path.
								sanitize_textarea_field( $row->translated )
							);

							if ( $saved ) {
								++$imported;
								$has_translation = true;
							}
						}

						// Mark the string's group translated for this language.
						// Idempotent ON DUPLICATE KEY upsert; on human-touched rows
						// it refreshes status/source, which is acceptable — the
						// link's presence, not its source, is what serving needs.
						$group_id = $existing
							? (int) $existing->group_id
							: (int) ( $this->strings->find( (int) $string_id )->group_id ?? 0 );

						if ( $has_translation && $group_id > 0 ) {
							$link_id = $this->groups->upsert_link(
								$group_id,
								(int) $string_id,
								(int) $pl_lang_id,
								'translated',
								\PerfLocale\Enum\SourceType::ImportedTrp
							);

							if ( $link_id === false ) {
								++$link_failures;
							}
						}
					}
				}

				// Advance the keyset cursor on g.id (the PK). original_id is
				// NON-unique — plural strings share it across rows — so paginating
				// on it would skip the rest of a plural group at a batch boundary.
				$last_id   = (int) end( $rows )->gettext_row_id;
				$row_count = count( $rows );

				unset( $rows );

				// Bound worker memory between streamed gettext batches (runtime
				// cache + SAVEQUERIES log only; persistent cache untouched).
				\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

				$done += $row_count;

				if ( $total === 0 && $row_count >= $gettext_batch_size && $this->reports_progress() ) {
					$total = $this->count_gettext_rows( $gettext_original_table );
				}

				$this->beat( 'strings', $done, $total );

				if ( $row_count < $gettext_batch_size ) {
					break;
				}
			} while ( true );

			foreach ( $extra_forms as $key => $forms ) {
				[ $domain, $context, $original ] = explode( "\0", (string) $key, 3 );

				$plural_row = $this->strings->find_by_hash( $domain, $context, $original );

				// Forms 2..N only complete a plural row that has form 1, and never
				// replace forms already there.
				if ( $plural_row === null
					|| $string_translations->get( (int) $plural_row->id, (int) $pl_lang_id ) === ''
					|| $string_translations->get_extra_forms( (int) $plural_row->id, (int) $pl_lang_id ) !== [] ) {
					continue;
				}

				ksort( $forms );
				$positional = [];

				for ( $i = 0, $last = (int) max( array_keys( $forms ) ); $i <= $last; $i++ ) {
					$positional[] = (string) ( $forms[ $i ] ?? '' );
				}

				$string_translations->set_extra_forms( (int) $plural_row->id, (int) $pl_lang_id, $positional );
			}
		}

		$this->beat( 'strings', $done, $done );

		if ( $link_failures > 0 ) {
			$this->result['errors'][] = sprintf(
				/* translators: %d: number of failed translation-link writes */
				_n( 'TranslatePress string import: %d translation link write failed — the affected strings are stored but not served; re-run the import to repair them.', 'TranslatePress string import: %d translation link writes failed — the affected strings are stored but not served; re-run the import to repair them.', $link_failures, 'perflocale' ),
				$link_failures
			);
			$this->mark_incomplete();
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
	}

	/**
	 * Whether a table has a column.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function has_column( string $table, string $column ): bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Both names are bound through prepare().
		$found = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return $found > 0;
	}

	/**
	 * Import slug translations (if TranslatePress SEO Pack tables exist).
	 *
	 * @return int Number of slugs imported.
	 */
	private function import_slug_translations(): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$slug_originals = $this->wpdb->prefix . 'trp_slug_originals';
		$slug_trans     = $this->wpdb->prefix . 'trp_slug_translations';

		// Check if slug tables exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$slug_originals
			)
		);

		if ( ! $exists ) {
			return 0;
		}

		$imported    = 0;
		$slugs_table = Schema::table( 'slug_translations' );
		$done        = 0;
		$total       = 0;

		$this->beat( 'slugs', 0, 0 );

		foreach ( $this->language_map as $trp_locale => $pl_lang_id ) {
			// Keyset-paginate on st.id so a site with more than 5000
			// translated slugs in one language imports ALL of them, instead
			// of silently dropping everything past the first 5000.
			$last_id = 0;

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT st.id AS trp_row_id, so.slug AS original_slug, st.slug AS translated_slug, so.post_id
						FROM %i st
						INNER JOIN %i so ON so.id = st.slug_original_id
						WHERE st.language = %s AND st.slug != '' AND st.id > %d
						ORDER BY st.id ASC
						LIMIT 5000",
						$slug_trans,
						$slug_originals,
						$trp_locale,
						$last_id
					)
				);

				// Surface a query failure instead of hiding it: the slug tables
				// are TranslatePress SEO Pack (premium) and their schema may not
				// match this SELECT, in which case the query errors and returns
				// no rows — which would otherwise look identical to "no slugs to
				// import" and silently migrate zero slugs.
				if ( $this->wpdb->last_error !== '' ) {
					self::log_db_error( 'reading the ' . $trp_locale . ' slugs', $this->wpdb->last_error );

					$this->result['errors'][] = sprintf(
						/* translators: %s: TRP locale */
						__( 'TranslatePress slug import failed for %s because of a database error.', 'perflocale' ),
						$trp_locale
					);
					$this->mark_incomplete();
					break;
				}

				if ( empty( $rows ) ) {
					break;
				}

				// Advance the cursor past this batch's highest id.
				$last_id = (int) ( end( $rows )->trp_row_id ?? 0 );

				// Batch-prime the post object cache for every post_id we're
				// about to look up below — without this, get_post_field()
				// issues one SELECT against wp_posts PER ROW. Mirrors the
				// pattern in PolylangImporter and is safe whenever
				// _prime_post_caches() is available (WP 4.7+).
				if ( function_exists( '_prime_post_caches' ) ) {
					$prime_ids = array_values(
						array_unique(
							array_filter(
								array_map( static fn( $r ): int => (int) ( $r->post_id ?? 0 ), $rows ),
								static fn( int $id ): bool => $id > 0
							)
						)
					);
					if ( $prime_ids !== [] ) {
						_prime_post_caches( $prime_ids, false, false );
					}
				}

				foreach ( $rows as $row ) {
					if ( $row->post_id > 0 ) {
						// Look up the post_type to use as object_subtype.
						// Without it, the slug_lookup UNIQUE on
						// (language, object_type, object_subtype, slug) would
						// either reject a legit cross-post-type duplicate or
						// collapse two distinct post types into one URL space.
						$post_type = (string) get_post_field( 'post_type', (int) $row->post_id );

						if ( $post_type === '' ) {
							continue;
						}

						$slug_manager = new \PerfLocale\Router\SlugManager( $this->cache );

						// Only count a slug the write actually stored — a failed
						// write reports itself through perflocale/slug/write_failed,
						// and inflating the migration summary would hide it.
						$stored = $slug_manager->set_slug(
							'post',
							$post_type,
							(int) $row->post_id,
							$pl_lang_id,
							sanitize_title( $row->translated_slug )
						);

						if ( $stored ) {
							++$imported;
						}
					}
				}

				// Bound worker memory between streamed slug batches (runtime
				// cache + SAVEQUERIES log only; persistent cache untouched).
				\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

				$done += count( $rows );

				if ( $total === 0 && count( $rows ) === 5000 && $this->reports_progress() ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One count for the import's progress; the IN() list is generated placeholders, every value bound.
					$total = (int) $this->wpdb->get_var(
						$this->wpdb->prepare(
							"SELECT COUNT(*) FROM %i st INNER JOIN %i so ON so.id = st.slug_original_id WHERE st.slug != '' AND st.language IN (" . implode( ',', array_fill( 0, count( $this->language_map ), '%s' ) ) . ')',
							array_merge( [ $slug_trans, $slug_originals ], array_map( 'strval', array_keys( $this->language_map ) ) )
						)
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
				}

				$this->beat( 'slugs', $done, $total );
			} while ( count( $rows ) === 5000 );
		}

		$this->beat( 'slugs', $done, $done );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
	}

	/**
	 * The gettext rows the string import reads in every mapped language, for its progress.
	 *
	 * The same rows as the import's batch query: translated, with a status in
	 * the imported range, and an original.
	 *
	 * @param string $original_table TranslatePress's gettext originals table.
	 * @return int
	 */
	private function count_gettext_rows( string $original_table ): int {
		$total = 0;

		foreach ( array_keys( $this->language_map ) as $trp_locale ) {
			$table = Schema::sanitize_table( $this->wpdb->prefix . 'trp_gettext_' . strtolower( str_replace( '-', '_', (string) $trp_locale ) ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The same check the import makes before reading a language; prepared (the sniff does not recognise the injected $this->wpdb).
			$exists = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $exists ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One count per language for the import's progress, on an import that reads more than one batch.
			$count = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i g INNER JOIN %i o ON o.id = g.original_id
					WHERE g.translated != '' AND g.status >= %d AND g.status <= %d",
					$table,
					$original_table,
					$this->min_status(),
					$this->gettext_max_status()
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			$total += $this->wpdb->last_error === '' ? (int) $count : 0;
		}

		return $total;
	}

	/**
	 * Copy TranslatePress's order language (`trp_language`) to PerfLocale's order meta.
	 *
	 * Orders only, and only those without a PerfLocale language yet
	 * ({@see OrderLanguageCopy}). An order in TranslatePress's default
	 * language gets the language the source content was imported into, one
	 * in a translation language the language its content went into. An
	 * order in a locale whose content was not imported (a language
	 * TranslatePress had not published) gets the PerfLocale language with
	 * that locale, active or not: it sets the order's email language and
	 * publishes nothing.
	 *
	 * @return int Orders given a language.
	 */
	private function import_order_languages(): int {
		$source = $this->get_source_perflocale_language();
		$all    = $this->languages->find_all();
		$copy   = OrderLanguageCopy::copy(
			$this->wpdb,
			'trp_language',
			function ( string $code ) use ( $source, $all ): string {
				if ( $code === $this->source_locale ) {
					return $source !== null ? (string) $source->slug : '';
				}

				$lang_id = $this->language_map[ $code ] ?? null;

				if ( $lang_id !== null ) {
					$lang = $this->languages->find( (int) $lang_id );

					return $lang !== null ? (string) $lang->slug : '';
				}

				// Exact locale first, then the slug prefix, as for content.
				foreach ( $all as $lang ) {
					if ( (string) $lang->locale === $code ) {
						return (string) $lang->slug;
					}
				}

				foreach ( $all as $lang ) {
					if ( (string) $lang->slug !== '' && str_starts_with( $code, (string) $lang->slug ) ) {
						return (string) $lang->slug;
					}
				}

				return '';
			},
			function ( int $done, int $total ): void {
				$this->beat( 'orders', $done, $total );
			},
			$this->reports_progress()
		);

		if ( $copy['error'] !== '' ) {
			self::log_db_error( 'reading order languages', $copy['error'] );

			$this->result['errors'][] = sprintf(
				/* translators: %s: source plugin name */
				__( 'The %s order languages could not be read because of a database error, so orders did not get their language. Re-run the import.', 'perflocale' ),
				'TranslatePress'
			);
			$this->mark_incomplete();
			return 0;
		}

		foreach ( $copy['missing'] as $code => $missing ) {
			$this->result['errors'][] = sprintf(
				/* translators: 1: number of orders, 2: TranslatePress language code, e.g. "de_DE" */
				_n(
					'%1$d order has the TranslatePress language "%2$s", which has no PerfLocale language; its emails use the default language.',
					'%1$d orders have the TranslatePress language "%2$s", which has no PerfLocale language; their emails use the default language.',
					$missing,
					'perflocale'
				),
				$missing,
				(string) $code
			);
		}

		return $copy['written'];
	}

	/**
	 * Save-time automation that must not see an imported translation: each
	 * runs inside wp_insert_post(), before this importer assigns the post's
	 * language, so it would take the post for a new default-language source
	 * (auto-assign would label it default, Auto-Create Stubs would make a
	 * group of empty drafts for it, auto-translate would queue machine
	 * translation over the imported text). Hook, callback method, priority
	 * and accepted args exactly as Bootstrap registers them.
	 *
	 * @var array<int, array{0:string, 1:string, 2:int, 3:int}>
	 */
	private const PAUSED_ON_INSERT = [
		[ 'save_post', 'auto_assign_default_language', 5, 2 ],
		[ 'transition_post_status', 'auto_create_translation_stubs', 20, 3 ],
		[ 'transition_post_status', 'auto_translate_on_publish', 25, 3 ],
	];

	/**
	 * Insert one translated post with the save-time automation paused.
	 *
	 * Only a callback hooked at exactly its registered priority is removed,
	 * and only those are put back, so a caller that detached one itself
	 * keeps it detached.
	 *
	 * The post's language is passed on to the unique-slug check, so the
	 * translation may keep the slug its source uses in another language.
	 *
	 * @param array<string, mixed> $postarr   Slashed post data.
	 * @param string               $lang_slug Language slug of the translation ('' when not known).
	 * @return int|\WP_Error New post ID, or the insert error.
	 */
	private function insert_translation_post( array $postarr, string $lang_slug = '' ): int|\WP_Error {
		$paused = [];

		foreach ( self::PAUSED_ON_INSERT as [ $hook, $method, $priority, $args ] ) {
			$callback = [ \PerfLocale\Bootstrap::class, $method ];

			if ( has_action( $hook, $callback ) === $priority ) {
				remove_action( $hook, $callback, $priority );
				$paused[] = [ $hook, $callback, $priority, $args ];
			}
		}

		PostTranslationManager::$creating_translation_lang_slug = $lang_slug !== '' ? $lang_slug : null;

		try {
			return wp_insert_post( $postarr, true );
		} finally {
			PostTranslationManager::$creating_translation_lang_slug = null;

			foreach ( $paused as [ $hook, $callback, $priority, $args ] ) {
				add_action( $hook, $callback, $priority, $args );
			}
		}
	}

	/**
	 * Get the dictionary table name for a language pair.
	 *
	 * TranslatePress creates one dictionary table per source→target language pair.
	 *
	 * @param string $source_locale Source language locale.
	 * @param string $target_locale Target language locale.
	 * @return string|null Table name or null if not found.
	 */
	private function get_dictionary_table( string $source_locale, string $target_locale ): ?string {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$source = strtolower( str_replace( '-', '_', $source_locale ) );
		$target = strtolower( str_replace( '-', '_', $target_locale ) );
		$table  = Schema::sanitize_table( $this->wpdb->prefix . 'trp_dictionary_' . $source . '_' . $target );

		// Verify table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $exists ? $table : null;
	}

	/**
	 * Get the PerfLocale language object for the source (default) language.
	 *
	 * @return object|null Language object.
	 */
	private function get_source_perflocale_language(): ?object {
		$perflocale_languages = $this->languages->get_active();

		// Exact locale match wins first; only then fall back to a slug-prefix
		// match, so a regional variant is not shadowed by its base language.
		foreach ( $perflocale_languages as $pl_lang ) {
			if ( $pl_lang->locale === $this->source_locale ) {
				return $pl_lang;
			}
		}

		foreach ( $perflocale_languages as $pl_lang ) {
			if ( str_starts_with( $this->source_locale, $pl_lang->slug ) ) {
				return $pl_lang;
			}
		}

		return $this->languages->get_default();
	}
}
