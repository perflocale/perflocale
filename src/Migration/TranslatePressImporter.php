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
 * replacing original strings with their translations.
 */
final class TranslatePressImporter {

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
	 * Rows per batch when streaming the per-language gettext dictionary.
	 * Caps memory; filterable via `perflocale/migration/translatepress/gettext_batch_size`.
	 */
	private const GETTEXT_BATCH_SIZE = 1000;

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
	 * the `get_translation_in_language()` pre-check + the
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
	 * @var array<string, int|array>
	 */
	private array $result = [
		'posts'   => 0,
		'strings' => 0,
		'slugs'   => 0,
		'skipped' => 0,
		'errors'  => [],
	];

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
	 * @return array<string, int|array> Results with counts and errors.
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

		// Import each type.
		$this->result['posts']   = $this->import_post_translations();
		$this->result['strings'] = $this->import_string_translations();
		$this->result['slugs']   = $this->import_slug_translations();

		// Flush caches.
		$this->cache->flush_all();

		return $this->result;
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

		foreach ( $trp_languages as $trp_locale ) {
			if ( $trp_locale === $this->source_locale ) {
				continue; // Skip source language - it maps to default.
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

		$imported = 0;
		$manager  = new PostTranslationManager( $this->cache, $this->settings );

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

		if ( empty( $post_ids ) ) {
			// Sweep any leftover checkpoint from a prior run — there's
			// nothing more to import, so the option becomes stale.
			delete_option( self::POST_CHECKPOINT_OPTION );
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
		$checkpoint = (int) get_option( self::POST_CHECKPOINT_OPTION, 0 );

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

		foreach ( $batches as $batch_index => $batch ) {
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
			$pre_batch_errors   = (array) ( $this->result['errors'] ?? [] );

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

					if ( empty( $originals ) ) {
						continue;
					}

					$original_map = [];

					foreach ( $originals as $row ) {
						$original_map[ (int) $row->id ] = $row->original;
					}

					// For each target language, build translated content.
					foreach ( $this->language_map as $trp_locale => $pl_lang_id ) {
						$dict_table = $this->get_dictionary_table( $this->source_locale, $trp_locale );

						if ( ! $dict_table ) {
							continue;
						}

						// Get translations for these original IDs (manually translated only).
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$translations = $this->wpdb->get_results(
							$this->wpdb->prepare(
								"SELECT original_id, translated FROM %i WHERE original_id IN ({$id_placeholders}) AND status >= %d AND translated != ''",
								$dict_table,
								...array_merge( array_map( 'intval', $original_ids ), [ $this->min_status() ] )
							)
						);

						if ( empty( $translations ) ) {
							continue;
						}

						// Reconstruct translated content by replacing strings.
						$title   = $post->post_title;
						$content = $post->post_content;
						$excerpt = $post->post_excerpt;

						// Replace longest originals first. TRP strings can overlap
						// (e.g. a heading "Hello" and a paragraph "Hello world"); in
						// original_id order a shorter original would be substituted
						// inside the longer one first, so the longer string's
						// str_replace then no longer matches and its translation is
						// lost. Sorting by original length DESC makes each
						// replacement target the most specific string available.
						usort(
							$translations,
							static function ( $a, $b ) use ( $original_map ): int {
								$la = isset( $original_map[ (int) $a->original_id ] ) ? strlen( (string) $original_map[ (int) $a->original_id ] ) : 0;
								$lb = isset( $original_map[ (int) $b->original_id ] ) ? strlen( (string) $original_map[ (int) $b->original_id ] ) : 0;
								return $lb <=> $la;
							}
						);

						foreach ( $translations as $trans ) {
							$oid = (int) $trans->original_id;

							if ( ! isset( $original_map[ $oid ] ) ) {
								continue;
							}

							$original   = $original_map[ $oid ];
							$translated = $trans->translated;

							// Replace in all fields.
							$title   = str_replace( $original, $translated, $title );
							$content = str_replace( $original, $translated, $content );
							$excerpt = str_replace( $original, $translated, $excerpt );
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
						$existing_translation = $this->groups->get_translation_in_language(
							$post_id,
							ObjectType::Post,
							$pl_lang_id
						);

						if ( $existing_translation !== null ) {
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
							continue;
						}

						// Create the translated post. Pass $wp_error=true so the
						// is_wp_error() guard below sees real DB-level failures
						// (unique-violation, FK orphan) instead of just the 0
						// "couldn't insert" path that silently masks them.
						$new_post_id = wp_insert_post(
							// wp_slash: reconstructed content comes from raw DB
							// reads (unslashed); wp_insert_post() unslashes
							// internally, stripping backslashes otherwise.
							wp_slash(
								[
									'post_type'    => $post->post_type,
									'post_status'  => $post->post_status,
									'post_title'   => sanitize_text_field( $title ),
									'post_content' => wp_kses_post( $content ),
									'post_excerpt' => sanitize_textarea_field( $excerpt ),
									'post_parent'  => $post->post_parent,
									'menu_order'   => $post->menu_order,
								]
							),
							true
						);

						if ( is_wp_error( $new_post_id ) ) {
							$this->result['errors'][] = sprintf(
								'Failed to create translation for post %d in %s: %s',
								$post_id,
								$trp_locale,
								$new_post_id->get_error_message()
							);
							continue;
						}

						if ( $new_post_id === 0 ) {
							$this->result['errors'][] = sprintf( 'Failed to create translation for post %d in %s.', $post_id, $trp_locale );
							continue;
						}

						// Find or create PerfLocale language for the source post.
						$source_lang = $this->get_source_perflocale_language();

						if ( $source_lang && ! $manager->set_post_language( $post_id, $source_lang->slug ) ) {
							$this->result['errors'][] = sprintf( 'Failed to assign source language "%s" to post %d.', $source_lang->slug, $post_id );
						}

						// Link to translation group.
						$pl_lang = $this->languages->find( $pl_lang_id );

						if ( $pl_lang ) {
							if ( ! $manager->set_post_language( $new_post_id, $pl_lang->slug ) ) {
								$this->result['errors'][] = sprintf( 'Failed to assign language "%s" to translation post %d.', $pl_lang->slug, $new_post_id );
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
							} else {
								// No source group (e.g. the source set_post_language
								// above failed): the freshly-inserted translation
								// post can't be linked. Throw — same rationale as
								// the link_object-false path — so the batch ROLLBACKs
								// and removes the orphan instead of leaving it
								// unlinked AND counting it in $imported below.
								throw new \RuntimeException( 'trp_import: source post ' . $post_id . ' has no translation group; cannot link translation post ' . $new_post_id );
							}
						}

						++$imported;
					}
				}
			} catch ( \Throwable $e ) {
				// Any throw inside the batch (hook callback, KSES filter,
				// MySQL deadlock, etc.) lands here. Roll back the SQL
				// transaction AND undo our in-PHP bookkeeping so the
				// post-import result reflects what's actually in the DB.
				// Without this rollback, wp_insert_post() rows that landed
				// in wp_posts survive (the outer transaction would
				// implicitly roll back, but `imported` had already been
				// bumped) and the operator sees a green count that
				// outstrips the actual link_objects on disk.
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				$imported                 = $pre_batch_imported;
				$this->result['skipped']  = $pre_batch_skipped;
				$this->result['errors']   = $pre_batch_errors;
				$this->result['errors'][] = sprintf(
					'TranslatePress migration batch #%d failed: %s',
					(int) $batch_index,
					$e->getMessage()
				);

				$batch_failed = true;
				// Clamp the resume checkpoint below this failed batch's ids so a
				// later committed batch can't push it past them (re-attempted on
				// resume; the line-391 "already translated" guard makes re-runs
				// of committed posts idempotent).
				$first_failed_floor = min( $first_failed_floor, (int) min( $batch ) );
				wp_cache_flush();

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
					// loss): the server has already rolled the batch back, so
					// nothing landed. Undo the in-PHP bookkeeping and skip the
					// checkpoint advance so a resume re-runs this batch instead
					// of treating it as committed.
					$imported                 = $pre_batch_imported;
					$this->result['skipped']  = $pre_batch_skipped;
					$this->result['errors']   = $pre_batch_errors;
					$this->result['errors'][] = sprintf(
						'TranslatePress migration batch #%d failed to commit.',
						(int) $batch_index
					);

					$first_failed_floor = min( $first_failed_floor, (int) min( $batch ) );
					wp_cache_flush();
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
				update_option( self::POST_CHECKPOINT_OPTION, $checkpoint, false );

				wp_cache_flush();
			}
		}

		// Migration finished — drop the checkpoint so a future fresh-run
		// starts from the top instead of skipping every previously-
		// migrated id.
		delete_option( self::POST_CHECKPOINT_OPTION );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
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
						"SELECT g.id AS gettext_row_id, g.original_id, o.original, o.domain, o.context, g.translated
						FROM %i g
						INNER JOIN %i o ON o.id = g.original_id
						WHERE g.translated != '' AND g.status >= %d AND g.id > %d
						ORDER BY g.id ASC
						LIMIT %d",
						$gettext_table,
						$gettext_original_table,
						$this->min_status(),
						$last_id,
						$gettext_batch_size
					)
				);

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

					// Check if string already exists in PerfLocale.
					$existing = $this->strings->find_by_hash( $domain, $context, $row->original );

					if ( ! $existing ) {
						// Insert the string (hash is computed by StringRepository::insert()).
						$string_id = $this->strings->insert(
							[
								'domain'    => $domain,
								'context'   => $context,
								'original'  => $row->original,
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

				if ( $row_count < $gettext_batch_size ) {
					break;
				}
			} while ( true );
		}

		if ( $link_failures > 0 ) {
			$this->result['errors'][] = sprintf(
				/* translators: %d: number of failed translation-link writes */
				__( 'TranslatePress string import: %d translation link write(s) failed — the affected strings are stored but not served; re-run the import to repair them.', 'perflocale' ),
				$link_failures
			);
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
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
					$this->result['errors'][] = sprintf(
						/* translators: 1: TRP locale, 2: DB error */
						__( 'TranslatePress slug import failed for %1$s: %2$s', 'perflocale' ),
						$trp_locale,
						$this->wpdb->last_error
					);
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
			} while ( count( $rows ) === 5000 );
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $imported;
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
