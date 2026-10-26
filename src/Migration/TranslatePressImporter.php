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
 * Imports TranslatePress data: maps its languages, and rebuilds each translated post from TranslatePress's
 * string-level dictionary by replacing every translated text, attribute, block attribute, shortcode value and
 * translation block (TranslatePressContentRebuilder).
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
	 * TranslatePress status 1, machine-translated: TranslatePress shows every status but 0, so these are live and
	 * imported by default.
	 */
	private const MACHINE_TRANSLATED = 1;

	/**
	 * Lowest TranslatePress status imported: machine-translated by default (what the site shows); 2 imports reviewed
	 * strings only.
	 *
	 * @hook perflocale/migration/translatepress/min_status
	 *
	 * @return int
	 */
	private function min_status(): int {
		return (int) apply_filters( 'perflocale/migration/translatepress/min_status', self::MACHINE_TRANSLATED );
	}

	/**
	 * Highest gettext status that is TranslatePress's own translation (1 machine, 2 reviewed); 3 and 4 copy a similar
	 * string's or the language pack's, which would freeze the pack's text into overrides.
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
	 * Option of the highest source post id whose batch committed, so a run after a kill or timeout starts after it.
	 */
	public const POST_CHECKPOINT_OPTION = 'perflocale_trp_import_post_checkpoint';

	/**
	 * Option of the language selection the post checkpoint was written under: when the source or targets changed, the
	 * run starts from the first post (translated targets are skipped).
	 */
	public const POST_CHECKPOINT_FINGERPRINT_OPTION = 'perflocale_trp_import_post_checkpoint_fp';

	/**
	 * Rows per batch of the per-language gettext dictionary (filter `perflocale/migration/translatepress/gettext_batch_size`).
	 */
	private const GETTEXT_BATCH_SIZE = 1000;

	/**
	 * Most targets named in `existing_targets`, so the job's stored result stays under JobState::MAX_RESULT_BYTES (a
	 * larger one is dropped whole). The `existing` count is exact.
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
	 * Source map, as in the WPML and Polylang importers, so `--force-restart` clears all three importers' state the
	 * same way (this importer's idempotency is the existing-translation check and the post checkpoint).
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
	 * Set by stop_if_batch_broken() when the batch's transaction was lost: its catch runs it once more outside one.
	 *
	 * @var bool
	 */
	private bool $batch_lost = false;

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
	 * Migration results. `existing`: targets skipped as the post had a translation this importer did not write (kept);
	 * `existing_targets` names the first EXISTING_TARGETS_CAP. `errors`: notices and failures. `incomplete`: a failed
	 * read or write left data not imported; running again imports the rest.
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
	 * Whether the TranslatePress tables exist.
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
	 * PHP's time limit the import runs under (0 = none), given the current one: raised, never lowered.
	 *
	 * @param int $current max_execution_time now.
	 * @return int
	 */
	public static function time_limit( int $current ): int {
		$limit = self::filtered_limit();

		return self::raises( $current, $limit ) ? max( 0, $limit ) : $current;
	}

	/**
	 * The perflocale/migration/time_limit value (default 300 s), the same for the admission and the import.
	 *
	 * @return int
	 */
	private static function filtered_limit(): int {
		return (int) apply_filters( 'perflocale/migration/time_limit', 300 );
	}

	/**
	 * Whether the import sets $limit: no limit (0, as under WP-CLI) or a longer one stays; an equal one is set again,
	 * which restarts the timer. function_exists(): a disabled function is gone in PHP 8.
	 *
	 * @param int $current max_execution_time now.
	 * @param int $limit   Filtered limit.
	 * @return bool
	 */
	private static function raises( int $current, int $limit ): bool {
		return function_exists( 'set_time_limit' ) && 0 !== $current && ( $limit <= 0 || $limit >= $current );
	}

	/**
	 * Raise PHP's time limit for the import ({@see time_limit()}).
	 *
	 * @return void
	 */
	private static function raise_time_limit(): void {
		$current = (int) ini_get( 'max_execution_time' );
		$limit   = self::filtered_limit();

		if ( self::raises( $current, $limit ) ) {
			set_time_limit( max( 0, $limit ) ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, Squiz.PHP.DiscouragedFunctions.Discouraged -- Large imports may exceed default time limit.
		}
	}

	/**
	 * Run the full migration.
	 *
	 * @return array<string, int|bool|array> Results with counts, errors and the `incomplete` flag.
	 */
	public function import(): array {
		self::raise_time_limit();

		if ( ! $this->can_import() ) {
			$this->result['errors'][] = __( 'TranslatePress tables not found.', 'perflocale' );
			return $this->result;
		}

		// TranslatePress settings.
		$this->trp_settings = get_option( 'trp_settings', [] );

		if ( empty( $this->trp_settings ) || ! is_array( $this->trp_settings ) ) {
			$this->result['errors'][] = __( 'TranslatePress settings not found in wp_options.', 'perflocale' );
			return $this->result;
		}

		// A killed batch's transaction rolls back but its persistent cache writes survive, which would make the resumed
		// run's idempotency checks skip translations: start from the committed state.
		wp_cache_flush();

		// Language mapping.
		$this->build_language_map();

		if ( empty( $this->language_map ) ) {
			$this->result['errors'][] = __( 'No TranslatePress languages could be mapped to PerfLocale languages.', 'perflocale' );
			return $this->result;
		}

		$this->dictionary = new TranslatePressDictionary( $this->wpdb, $this->cache, $this->min_status(), \Closure::fromCallable( [ $this, 'beat' ] ) );

		// Term names first (created posts get translated terms), menus after the posts their items point to; the lock's
		// heartbeat runs with each step's progress. A failed dictionary or term read stops the run where it is.
		try {
			$this->result['terms'] = $this->import_term_names();
			$this->result['posts'] = $this->import_post_translations();
			$this->beat();

			// Menus point to the posts' translations and are copied once per language: they wait for a run whose post step
			// finished.
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
	 * The error left by the source read that just ran, or '' when it worked: a failed read returns [] like an empty
	 * one, and the next query resets last_error, so call it straight after the read.
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
	 * Throw so the batch's catch takes over when its transaction was lost or one of its own statements failed.
	 *
	 * Lost (Schema::transaction_lost()): rolled back and run once more outside a transaction. An own failure fails the
	 * batch on every engine; another plugin's failed query leaves a MySQL transaction intact.
	 *
	 * @param bool   $in_tx      Whether the batch runs in a transaction.
	 * @param int    $errors     Schema::query_error_count() when the batch started.
	 * @param bool   $own_failed Whether one of the batch's own statements failed.
	 * @param string $step       What the batch was doing, for the batch error.
	 * @return void
	 * @throws \RuntimeException When the batch has to stop.
	 */
	private function stop_if_batch_broken( bool $in_tx, int $errors, bool $own_failed, string $step ): void {
		if ( $in_tx && Schema::transaction_lost( $errors ) ) {
			$this->batch_lost = true;
			$message          = 'trp_import: the batch transaction ended on a failed query while ' . $step;

			throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text built from literals and integer ids.
		}

		if ( $own_failed ) {
			$message = 'trp_import: a database statement failed while ' . $step;

			throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text built from literals and integer ids.
		}
	}

	/**
	 * Log a database error's text when WP_DEBUG_LOG is on: it can carry prefixed table names and values, so results and
	 * job rows (shown to all Jobs users) only say a database error happened.
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
	 * Map TranslatePress locales to PerfLocale language ids.
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

		// A language TranslatePress had not published goes only into a switched-off PerfLocale language: never published.
		$published = $this->trp_settings['publish-languages'] ?? null;
		$published = is_array( $published ) ? array_map( 'strval', $published ) : null;

		foreach ( $trp_languages as $trp_locale ) {
			if ( $trp_locale === $this->source_locale ) {
				continue; // The source language maps to the default.
			}

			if ( $published !== null && ! in_array( (string) $trp_locale, $published, true ) ) {
				$this->map_unpublished_language( (string) $trp_locale );
				continue;
			}

			$matched = false;

			// Pass 1, exact locale, so a regional variant (de_AT) is never taken by its base's slug prefix (as WPML/Polylang).
			foreach ( $perflocale_languages as $pl_lang ) {
				if ( $pl_lang->locale === $trp_locale ) {
					$this->language_map[ $trp_locale ] = (int) $pl_lang->id;
					$matched                           = true;
					break;
				}
			}

			// Pass 2: slug prefix, only without an exact match.
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

		// The slug-prefix pass can map two regional locales (de_DE, de_AT) onto one language when only the base exists:
		// keep the exact-locale claimant (or the first) per language and report the rest.
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

		// No target may resolve to the source's language (or the default it falls back to): every post would get a
		// published duplicate and set_post_language() would evict the source from its group. Refused with an error.
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
	 * Map a language TranslatePress had not published, only onto a switched-off PerfLocale language (exact locale, then
	 * slug prefix), so visitors see nothing until it is switched on; otherwise skip it with a notice.
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
	 * Import post translations: for each post with translated strings and each target language, rebuild the content,
	 * insert the translation and link it in the post's group.
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

		// Older TranslatePress versions have no meta table.
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

		// Every post id that has translations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT meta_value FROM %i WHERE meta_key = %s ORDER BY meta_value ASC',
				$meta_table,
				'post_parent_id'
			)
		);

		// A failed read keeps the resume checkpoint: the sweep below is for a source with nothing left.
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
			// Nothing left to import: drop a stale checkpoint.
			delete_option( self::POST_CHECKPOINT_OPTION );
			delete_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION );
			return 0;
		}

		// meta_value is longtext, so SQL sorts it as text ("10" < "2"); the checkpoint is a high-water mark only in
		// numeric order, or a resume would skip lower ids for good.
		$post_ids = array_map( 'intval', $post_ids );
		sort( $post_ids, SORT_NUMERIC );

		// Resume after the highest post id the last committed batch recorded.
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
				// Everything past the checkpoint is done: clear it so a later fresh run does not do nothing.
				delete_option( self::POST_CHECKPOINT_OPTION );
				delete_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION );
				return 0;
			}
		}

		/**
		 * Filter the posts per transaction of the TranslatePress migration (default 50, clamped 5-500): larger batches mean
		 * fewer transactions and longer rollbacks.
		 *
		 * @hook perflocale/migration/translatepress/batch_size
		 * @param int $size Default 50.
		 */
		$batch_size = (int) apply_filters( 'perflocale/migration/translatepress/batch_size', self::BATCH_SIZE );
		$batch_size = max( 5, min( 500, $batch_size ) );

		// In batches.
		$batches = array_chunk( array_map( 'intval', $post_ids ), $batch_size );

		// After a failed batch the checkpoint never passes its ids, or a resume would skip them.
		$first_failed_floor = PHP_INT_MAX;

		$this->beat( 'posts', 0, count( $post_ids ) );

		// One transaction per batch; a batch whose transaction was lost is rolled back and queued once more outside one,
		// where another plugin's failed query is tolerated.
		$queue = [];

		foreach ( $batches as $batch_index => $batch ) {
			$queue[] = [ $batch_index, $batch, true ];
		}

		for ( $next = 0; isset( $queue[ $next ] ); ) {
			[ $batch_index, $batch, $in_tx ] = $queue[ $next ];
			++$next;

			$this->beat( 'posts', min( count( $post_ids ), (int) $batch_index * $batch_size ), count( $post_ids ) );
			$rebuilder->forget();

			// Release the status selections a rolled-back batch asked to delete. A rolled-back final batch's pending entry is
			// never released: its ROLLBACK undid them, and import() ends with flush_all().
			$this->groups->release_status_selectors();

			if ( $in_tx ) {
				$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				// Nested create_group() and link_object() join this transaction instead of a second START TRANSACTION, which
				// MySQL treats as a COMMIT. Cleared in the finally.
				$this->groups->set_in_transaction( true );
			}

			// Failed queries before this batch's writes, for stop_if_batch_broken().
			$batch_errors     = Schema::query_error_count();
			$this->batch_lost = false;

			// Totals before the batch, restored with its ROLLBACK so the counts match the database.
			$pre_batch_imported = $imported;
			$pre_batch_skipped  = (int) ( $this->result['skipped'] ?? 0 );
			$pre_batch_existing = [ $this->result['existing'], $this->result['existing_targets'] ];
			$pre_batch_errors   = (array) ( $this->result['errors'] ?? [] );

			// Posts this batch inserted; a failed batch deletes those its ROLLBACK did not remove.
			$batch_new_posts = [];

			$batch_failed = false;

			try {

				foreach ( $batch as $post_id ) {
					$post = get_post( $post_id );

					if ( ! $post ) {
						++$this->result['skipped'];
						continue;
					}

					// No "post has any translation" skip: get_translations() includes the source's own link, so it would drop pending
					// languages on a resume. Idempotency is per (post, language), below.

					// The post's original_id values.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$original_ids = $this->wpdb->get_col(
						$this->wpdb->prepare(
							"SELECT original_id FROM %i WHERE meta_key = 'post_parent_id' AND meta_value = %d",
							$meta_table,
							$post_id
						)
					);

					// A failed read throws, so the batch rolls back and the checkpoint stays below this post.
					$read_error = $this->read_error();

					if ( $read_error !== '' ) {
						self::log_db_error( 'reading post ' . $post_id, $read_error );

						throw new TranslatePressSourceReadException( 'reading post ' . $post_id . ' failed', $read_error );
					}

					if ( empty( $original_ids ) ) {
						continue;
					}

					// The originals of these ids.
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

					// The post's fields as translatable units, once for every language.
					$parsed = null;

					// Per target language.
					foreach ( $this->language_map as $trp_locale => $pl_lang_id ) {
						$dict_table = $this->get_dictionary_table( $this->source_locale, $trp_locale );

						if ( ! $dict_table ) {
							continue;
						}

						// The translations of these originals; with translation blocks every column is read, for block_type.
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

						// Rebuild from whole units only (text run, attribute value, block attribute, shortcode value, translation block):
						// never inside URLs or other text, and a replaced unit is not scanned again.
						$title   = $post->post_title;
						$content = $post->post_content;
						$excerpt = $post->post_excerpt;

						// Empty originals are skipped; two ids with one original: the first row wins. A deprecated translation block
						// (block_type 2) is not served by TranslatePress and is left out.
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

						// TranslatePress ties an excerpt (a WooCommerce short description) to no post: look it up whole, or per line.
						if ( $excerpt === $post->post_excerpt && $this->dictionary !== null ) {
							try {
								$excerpt = $this->dictionary->excerpt( $dict_table, $excerpt ) ?? $excerpt;
							} catch ( TranslatePressSourceReadException $e ) {
								self::log_db_error( 'reading the ' . $trp_locale . ' excerpt of post ' . $post_id, $e->db_error );

								throw $e;
							}
						}

						// Nothing translated: skip.
						if ( $title === $post->post_title && $content === $post->post_content && $excerpt === $post->post_excerpt ) {
							continue;
						}

						// A translation for (source, language) in translation_links already: without this check a re-import after a
						// restore that lost the checkpoint would duplicate every translation.
						$existing_link = null;

						foreach ( $this->groups->get_translations( $post_id, ObjectType::Post ) as $link ) {
							if ( (int) $link->language_id === $pl_lang_id ) {
								$existing_link = $link;
								break;
							}
						}

						if ( $existing_link !== null ) {
							// Exists: record the source map again (one upsert) so --force-restart can clear it.
							$source_group_existing = $this->groups->find_for_object( $post_id, ObjectType::Post );
							if ( $source_group_existing ) {
								$this->source_map->set_group_id(
									'trp',
									$post_id . '|' . $pl_lang_id,
									(int) $source_group_existing->id
								);
							}

							// One this importer wrote is the re-run case; any other translation keeps its content and is counted and named.
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

						// The source's language first, so the unique-slug check lets the translation keep the source's slug.
						$source_lang = $this->get_source_perflocale_language();

						$source_set = ! $source_lang || $manager->set_post_language( $post_id, $source_lang->slug );

						$this->stop_if_batch_broken( $in_tx, $batch_errors, false, 'setting the language of post ' . (int) $post_id );

						if ( ! $source_set ) {
							$this->result['errors'][] = sprintf(
								/* translators: 1: language slug, 2: post ID */
								__( 'Failed to assign source language "%1$s" to post %2$d.', 'perflocale' ),
								$source_lang->slug,
								$post_id
							);
						}

						// A child page goes under its parent's translation when there is one.
						$parent_id = (int) $post->post_parent;

						if ( $parent_id > 0 ) {
							$parent_id = (int) ( $manager->get_translations( $parent_id )[ $pl_lang->slug ] ?? $parent_id );
						}

						// $wp_error = true, so real database failures are seen. The translation keeps the source's author, dates,
						// discussion, password and slug: TranslatePress served one slug in every language, so old URLs keep working.
						$new_post_id = $this->insert_translation_post(
							// wp_slash: the rebuilt content comes unslashed from the database, and wp_insert_post() unslashes.
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

						// A failed INSERT or UPDATE fails the batch; a post WordPress refused (empty content) is a recorded skip.
						$this->stop_if_batch_broken(
							$in_tx,
							$batch_errors,
							is_wp_error( $new_post_id ) && in_array( $new_post_id->get_error_code(), [ 'db_insert_error', 'db_update_error' ], true ),
							'creating the translation of post ' . (int) $post_id
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

						// Into the translation group.
						$language_set = $manager->set_post_language( $new_post_id, $pl_lang->slug );

						$this->stop_if_batch_broken( $in_tx, $batch_errors, false, 'setting the language of post ' . (int) $new_post_id );

						if ( ! $language_set ) {
							$this->result['errors'][] = sprintf(
								/* translators: 1: language slug, 2: post ID */
								__( 'Failed to assign language "%1$s" to translation post %2$d.', 'perflocale' ),
								$pl_lang->slug,
								$new_post_id
							);
						}

						// Same group as the source.
						$source_group = $this->groups->find_for_object( $post_id, ObjectType::Post );

						if ( $source_group ) {
							// A false link_object() throws, so the batch rolls back instead of counting an unlinked post.
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

							$this->stop_if_batch_broken( $in_tx, $batch_errors, false, 'linking post ' . (int) $new_post_id );

							// The source map row, for --force-restart (as WPML and Polylang), outside the link transaction: a crash in between
							// leaves one missing row that the next import records again.
							$this->source_map->set_group_id(
								'trp',
								$post_id . '|' . $pl_lang_id,
								(int) $source_group->id
							);

							// As PerfLocale's own translations: the source's meta, featured image and terms, then
							// perflocale/translation/created (the WooCommerce add-on sets product type and variations there).
							$manager->complete_imported_translation( (int) $new_post_id, (int) $post_id, (string) $pl_lang->slug );

							$this->stop_if_batch_broken( $in_tx, $batch_errors, false, 'completing post ' . (int) $new_post_id );
						} else {
							// No source group (its set_post_language() failed): throw, so the batch rolls back and the post is deleted.
							throw new \RuntimeException( 'trp_import: source post ' . $post_id . ' has no translation group; cannot link translation post ' . $new_post_id );
						}

						++$imported;
					}
				}

				$this->stop_if_batch_broken( $in_tx, $batch_errors, false, 'finishing batch ' . (int) $batch_index );
			} catch ( \Throwable $e ) {
				// Any throw in the batch lands here: ROLLBACK undoes its transactional writes, the counters go back, and posts a
				// non-transactional (MyISAM) wp_posts kept are deleted below.
				if ( $in_tx ) {
					$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}

				// A lost transaction (SQLite): the batch once more, outside a transaction, from its pre-batch state.
				if ( $in_tx && $this->batch_lost ) {
					$imported                = $pre_batch_imported;
					$this->result['skipped'] = $pre_batch_skipped;
					$this->result['errors']  = $pre_batch_errors;

					[ $this->result['existing'], $this->result['existing_targets'] ] = $pre_batch_existing;

					// This process still remembers the rolled-back links (repository lookups, the request cache layer): the second run
					// must read the database.
					wp_cache_flush();
					\PerfLocale\Database\Repository\TranslationGroupRepository::reset_static_caches();
					$this->cache->reset_static();
					$this->delete_unlinked_batch_posts( (int) $batch_index, $batch_new_posts );

					$queue[ --$next ] = [ $batch_index, $batch, false ];

					continue;
				}

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

				// Keep the checkpoint below this failed batch's ids; committed posts are skipped on the retry.
				$first_failed_floor = min( $first_failed_floor, (int) min( $batch ) );
				wp_cache_flush();

				// After the flush, so the delete hooks see committed state.
				$this->delete_unlinked_batch_posts( (int) $batch_index, $batch_new_posts );

				// One batch's bad data does not stop the rest of the migration.
				continue;
			} finally {
				// Always cleared, so `true` never leaks into later create_group() and link_object() calls.
				$this->groups->set_in_transaction( false );
			}

			if ( ! $batch_failed ) {
				$committed = $in_tx ? $this->wpdb->query( 'COMMIT' ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// Release the status selections this batch asked to delete, on any blog, once its transaction has ended.
				$this->groups->release_status_selectors();

				if ( false === $committed ) {
					// COMMIT failed (lock timeout, deadlock, lost connection): the server rolled back. Undo the bookkeeping, delete the
					// posts a non-transactional wp_posts kept, and leave the checkpoint so a resume runs this batch again.
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

				// The checkpoint moves to the batch's highest post id after the COMMIT: a crash in between re-imports one batch,
				// which the per (post, language) check makes idempotent. Not autoloaded.
				$checkpoint = (int) max( $batch );
				if ( $first_failed_floor !== PHP_INT_MAX ) {
					// After a failed batch the checkpoint stays below its lowest id, so a resume retries it.
					$checkpoint = min( $checkpoint, $first_failed_floor - 1 );
				}
				update_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION, $fingerprint, false );
				update_option( self::POST_CHECKPOINT_OPTION, $checkpoint, false );

				// Committed: the persistent cache holds committed state only; drop the runtime copies.
				\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
			}
		}

		$this->beat( 'posts', count( $post_ids ), count( $post_ids ) );

		// Finished: drop the checkpoint so a later fresh run starts at the top.
		delete_option( self::POST_CHECKPOINT_OPTION );
		delete_option( self::POST_CHECKPOINT_FINGERPRINT_OPTION );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
	}

	/**
	 * Fingerprint of the language selection a checkpoint belongs to: the source locale, its PerfLocale language and every
	 * mapped target.
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
	 * Delete the posts a failed batch inserted that are still in wp_posts: on MyISAM the ROLLBACK keeps them (published,
	 * unlinked, inserted again next run); on InnoDB none are left and no delete hook fires.
	 *
	 * @param int        $batch_index Batch number, for the error message.
	 * @param array<int> $post_ids    Post IDs this batch's wp_insert_post() calls returned.
	 * @return void
	 */
	private function delete_unlinked_batch_posts( int $batch_index, array $post_ids ): void {
		// The batch transaction is over: the delete hooks' writes must not join it.
		$this->groups->set_in_transaction( false );

		if ( $post_ids === [] ) {
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// wp_posts itself: get_post() would still find the cached posts the ROLLBACK removed.
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
			// Called from the batch's catch block: an exception here would escape import().
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

		// Gettext tables present?
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

		// Filterable batch size, at least 100.
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

			// This language's gettext table present?
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

			// TranslatePress versions without plural support have neither column.
			$plural_columns = $this->has_column( $gettext_original_table, 'original_plural' ) && $this->has_column( $gettext_table, 'plural_form' )
				? 'o.original_plural, g.plural_form'
				: 'NULL AS original_plural, 0 AS plural_form';

			// Keyset pages on g.id: every row is imported (no fixed LIMIT) and memory stays one batch.
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

				// A failed SELECT also returns no rows: without this the language would end silently under a success result.
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
					// Sanitize the domain once, for both find_by_hash() and insert(): different values would hash differently.
					$domain = sanitize_text_field( (string) ( $row->domain ?? 'default' ) );

					// TranslatePress's 'trp_context' sentinel is PerfLocale's empty context; real _x() contexts are kept, so imported
					// strings have the runtime identity (domain|context|original).
					$raw_context = (string) ( $row->context ?? '' );
					$context     = ( $raw_context === '' || $raw_context === 'trp_context' ) ? '' : $raw_context;
					$original    = (string) $row->original;

					// A plural string's forms are rows plural_form 0..N: form 0 goes on the singular ('singular' or the _nx() context),
					// form 1 on the plural ('plural' or '<context> (plural)'), 2..N in the plural row's extra forms.
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

					// An existing string, with its group's type.
					$existing = $this->find_string( $domain, $context, $original );

					if ( ! $existing ) {
						// StringRepository::insert() computes the hash.
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
						// An existing translation (perhaps a later human correction) is kept; the link upsert still runs, since a value
						// without a link is never served and a re-run is how a failed link write heals.
						$has_translation = $string_translations->get( (int) $string_id, (int) $pl_lang_id ) !== '';

						if ( ! $has_translation ) {
							// The return decides the count and the link: a value never stored is neither counted nor linked (as WPML's loop).
							$saved = $string_translations->set(
								(int) $string_id,
								(int) $pl_lang_id,
								// Keeps newlines, as TranslatePress's own save (trp_sanitize_string) and PerfLocale's do.
								sanitize_textarea_field( $row->translated )
							);

							if ( $saved ) {
								++$imported;
								$has_translation = true;
							}
						}

						// The group marked translated for this language: an idempotent upsert (it may refresh a human row's status).
						$group_id = $existing
							? (int) $existing->group_id
							: (int) ( $this->strings->find( (int) $string_id )->group_id ?? 0 );

						if ( $has_translation && $group_id > 0 ) {
							// String links go into string groups only: an existing string in a post or term group (or none) gets one first.
							if ( ! $existing || ( isset( $existing->group_type ) && 'string' === $existing->group_type ) ) {
								TranslationGroupRepository::remember_string_group( $group_id );
							} else {
								$group_id = $this->groups->assign_new_string_group( (int) $string_id );
							}

							$link_id = $group_id > 0 ? $this->groups->upsert_link(
								$group_id,
								(int) $string_id,
								(int) $pl_lang_id,
								'translated',
								\PerfLocale\Enum\SourceType::ImportedTrp
							) : false;

							if ( $link_id === false ) {
								++$link_failures;
							}
						}
					}
				}

				// The cursor is g.id (the primary key): original_id repeats across a plural string's rows.
				$last_id   = (int) end( $rows )->gettext_row_id;
				$row_count = count( $rows );

				unset( $rows );

				// Runtime cache and SAVEQUERIES log between batches; the persistent cache is untouched.
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

				// Forms 2..N only complete a plural row that has form 1, and never replace forms already there.
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
				_n( 'TranslatePress string import: %d translation link write failed - the affected strings are stored but not served; re-run the import to repair them.', 'TranslatePress string import: %d translation link writes failed - the affected strings are stored but not served; re-run the import to repair them.', $link_failures, 'perflocale' ),
				$link_failures
			);
			$this->mark_incomplete();
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
	}

	/**
	 * A PerfLocale string by domain, context and original, with its group's type (`group_type`, null without a group),
	 * in one read.
	 *
	 * @param string $domain   Text domain.
	 * @param string $context  Context.
	 * @param string $original Original text.
	 * @return object|null The strings row plus group_type.
	 */
	private function find_string( string $domain, string $context, string $original ): ?object {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers are bound with %i; an import reads each string once.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT s.*, g.type AS group_type FROM %i s LEFT JOIN %i g ON g.id = s.group_id WHERE s.original_hash = %s',
				Schema::table( 'strings' ),
				Schema::table( 'translation_groups' ),
				StringRepository::compute_hash( $domain, $context, $original )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_object( $row ) ? $row : null;
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
	 * Import slug translations (TranslatePress SEO Pack tables, when present).
	 *
	 * @return int Number of slugs imported.
	 */
	private function import_slug_translations(): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$slug_originals = $this->wpdb->prefix . 'trp_slug_originals';
		$slug_trans     = $this->wpdb->prefix . 'trp_slug_translations';

		// Slug tables present?
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
			// Keyset pages on st.id, so every translated slug is imported.
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

				// SEO Pack's schema may not match this SELECT: report the error instead of reading it as no slugs.
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

				// Past this batch's highest id.
				$last_id = (int) ( end( $rows )->trp_row_id ?? 0 );

				// Prime the post cache for the batch, or get_post_field() queries once per row (as PolylangImporter).
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
						// The post type is the object_subtype of the slug_lookup unique key (language, type, subtype, slug).
						$post_type = (string) get_post_field( 'post_type', (int) $row->post_id );

						if ( $post_type === '' ) {
							continue;
						}

						$slug_manager = new \PerfLocale\Router\SlugManager( $this->cache );

						// Count only stored slugs; a failed write reports itself through perflocale/slug/write_failed.
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

				// Runtime cache and SAVEQUERIES log between batches; the persistent cache is untouched.
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
	 * The gettext rows the string import reads in every mapped language (translated, status in range, with an
	 * original), for its progress.
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
	 * Copy TranslatePress's order language (`trp_language`) to orders without a PerfLocale language (OrderLanguageCopy):
	 * the language the matching content went into, or for an unimported locale the PerfLocale language of that locale,
	 * active or not (it sets the email language and publishes nothing).
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
	 * Save-time automation paused around an imported insert: it runs before the importer sets the language and would
	 * take the post for a new default-language source (auto-assign, Auto-Create Stubs, auto-translate). Hook, method,
	 * priority and args exactly as Bootstrap registers them.
	 *
	 * @var array<int, array{0:string, 1:string, 2:int, 3:int}>
	 */
	private const PAUSED_ON_INSERT = [
		[ 'save_post', 'auto_assign_default_language', 5, 2 ],
		[ 'transition_post_status', 'auto_create_translation_stubs', 20, 3 ],
		[ 'transition_post_status', 'auto_translate_on_publish', 25, 3 ],
	];

	/**
	 * Insert one translated post with that automation paused (only callbacks at their registered priority are removed
	 * and put back); the language goes to the unique-slug check, so the source's slug may be kept.
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
	 * The dictionary table of a language pair (TranslatePress has one per source and target).
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

		// Table present?
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
	 * The PerfLocale language of the source (default) language.
	 *
	 * @return object|null Language object.
	 */
	private function get_source_perflocale_language(): ?object {
		$perflocale_languages = $this->languages->get_active();

		// Exact locale first, then the slug prefix, so a regional variant is not taken by its base.
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
