<?php
/**
 * WPML migration importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\MigrationSourceMapRepository;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Enum\TranslationStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports translation data from WPML.
 *
 * Reads WPML's icl_translations and icl_strings tables, maps language
 * codes to PerfLocale language IDs, and creates translation groups
 * and links for posts, terms, and strings.
 */
final class WpmlImporter {

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
	 * Source-map repository for idempotency across DB restores +
	 * partial-failure crashes. The map pins WPML trids to the
	 * translation_groups row PerfLocale created for them — so a re-run
	 * after a restore finds the prior mapping and reuses the group_id
	 * instead of allocating a duplicate.
	 *
	 * @var MigrationSourceMapRepository
	 */
	private readonly MigrationSourceMapRepository $source_map;

	/**
	 * Cache manager kept on the instance so we can construct additional
	 * repositories (e.g. StringTranslationRepository) lazily during string
	 * import without passing the cache through every call chain.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Language code mapping: WPML code => PerfLocale language ID.
	 *
	 * @var array<string, int>
	 */
	private array $language_map = [];

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
	}

	/**
	 * Check if WPML tables exist and import is possible.
	 *
	 * @return bool
	 */
	public function can_import(): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$translations_table = $this->wpdb->prefix . 'icl_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$translations_table
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $result > 0;
	}

	/**
	 * Run the full WPML import.
	 *
	 * @return array{posts: int, terms: int, strings: int, errors: array<int, string>}
	 */
	public function import(): array {
		$result = [
			'posts'   => 0,
			'terms'   => 0,
			'strings' => 0,
			'errors'  => [],
		];

		if ( ! $this->can_import() ) {
			$result['errors'][] = 'WPML tables not found.';
			return $result;
		}

		$this->build_language_map( $result );

		if ( empty( $this->language_map ) ) {
			$result['errors'][] = 'No matching languages found between WPML and PerfLocale.';
			return $result;
		}

		$result['posts']   = $this->import_post_translations( $result );
		$result['terms']   = $this->import_term_translations( $result );
		$result['strings'] = $this->import_string_translations( $result );

		return $result;
	}

	/**
	 * Build a mapping from WPML language codes to PerfLocale language IDs.
	 *
	 * @param array{posts: int, terms: int, strings: int, errors: array<int, string>} $result Import result (passed by reference for errors).
	 * @return void
	 */
	private function build_language_map( array &$result ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$icl_table = $this->wpdb->prefix . 'icl_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpml_langs = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT language_code FROM %i',
				$icl_table
			)
		);

		if ( ! is_array( $wpml_langs ) ) {
			$wpml_langs = [];
		}

		// Union in languages that appear ONLY in icl_string_translations. A
		// language activated for admin/theme string translation but with no
		// translated posts yet has NO icl_translations rows — building the map
		// from icl_translations alone would leave those string translations
		// unmapped and silently dropped at the $lang_id === null skip.
		$str_trans_table = $this->wpdb->prefix . 'icl_string_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$str_table_exists = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$str_trans_table
			)
		);

		if ( $str_table_exists > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$string_langs = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT language FROM %i',
					$str_trans_table
				)
			);

			if ( is_array( $string_langs ) ) {
				$wpml_langs = array_values( array_unique( array_merge( $wpml_langs, $string_langs ) ) );
			}
		}

		if ( $wpml_langs === [] ) {
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return;
		}

		$active_languages = $this->languages->get_active();

		// Guard against two WPML codes resolving to the SAME PerfLocale id (an
		// exact slug match on one plus a locale-prefix match on another, e.g.
		// 'pt' + 'pt-br' against a single Portuguese language). One object per
		// (group, language) is a hard invariant — a second code sharing the id
		// would make link_object() evict the first sibling's link from every
		// shared trid. Keep the first claimant, skip and report the rest.
		// Mirrors PolylangImporter::build_language_map().
		$claimed_by = [];

		foreach ( $wpml_langs as $wpml_code ) {
			$wpml_code = sanitize_text_field( $wpml_code );
			$matched   = null;

			// Skip blank codes. WPML's icl_translations carries rows with an
			// empty language_code (orphaned auto-draft / comment / package
			// element rows). Without this guard the pass-2 prefix match below
			// matches unconditionally — str_starts_with($locale, '') is always
			// true — so '' would poison language_map[''] with the first active
			// language and silently import every blank/NULL-code row as the
			// site default. Leaving '' unmapped makes those rows skip cleanly.
			if ( $wpml_code === '' ) {
				continue;
			}

			// Pass 1 — an exact slug match always wins. Slugs are unique, so
			// this is deterministic. Doing it in a separate pass prevents an
			// earlier language whose LOCALE merely starts with the code (e.g.
			// en_GB for code "en") from shadowing the language whose SLUG is
			// the code (en_US, slug "en").
			foreach ( $active_languages as $lang ) {
				if ( $lang->slug === $wpml_code ) {
					$matched = $lang;
					break;
				}
			}

			// Pass 2 — fall back to a locale-prefix match, normalising the
			// WPML hyphen form ("pt-br", "zh-hans") to PerfLocale's underscore
			// locale form ("pt_BR") so regional codes still map.
			if ( $matched === null ) {
				$needle = str_replace( '-', '_', strtolower( $wpml_code ) );
				foreach ( $active_languages as $lang ) {
					if ( str_starts_with( strtolower( (string) $lang->locale ), $needle ) ) {
						$matched = $lang;
						break;
					}
				}
			}

			if ( $matched !== null ) {
				if ( isset( $claimed_by[ (int) $matched->id ] ) ) {
					$result['errors'][] = sprintf(
						/* translators: 1: first WPML language code, 2: colliding WPML language code, 3: colliding code again */
						__( 'WPML languages "%1$s" and "%2$s" both map to the same PerfLocale language — "%3$s" was skipped. Add a distinct PerfLocale language for it and re-run.', 'perflocale' ),
						$claimed_by[ (int) $matched->id ],
						$wpml_code,
						$wpml_code
					);
					continue;
				}

				$claimed_by[ (int) $matched->id ] = $wpml_code;
				$this->language_map[ $wpml_code ] = (int) $matched->id;
			} else {
				$result['errors'][] = sprintf( 'No PerfLocale language match for WPML code "%s".', $wpml_code );
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Import post translations from WPML.
	 *
	 * Groups WPML translations by trid and creates PerfLocale
	 * translation groups with links for each language version.
	 *
	 * @param array{posts: int, terms: int, strings: int, errors: array<int, string>} $result Import result (passed by reference for errors).
	 * @return int Number of posts imported.
	 */
	private function import_post_translations( array &$result ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$icl_table = $this->wpdb->prefix . 'icl_translations';
		$imported  = 0;

		// Bounded memory: fetch DISTINCT trids first (one BIGINT per row),
		// chunk them, then per chunk SELECT only those trids' rows and process
		// — rather than one big SELECT holding every row in memory before
		// grouping (~15 MB of PHP arrays on a 50k-post bilingual install).

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$trids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT trid FROM %i
				WHERE element_type LIKE %s AND element_id IS NOT NULL
				ORDER BY trid ASC',
				$icl_table,
				'post_%'
			)
		);

		if ( empty( $trids ) ) {
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $trids ), $batch_size );

		foreach ( $batches as $batch ) {
			// $placeholders is a generated '%d,%d,...' string sized to the
			// current batch — safe to interpolate into the IN() clause.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// The icl_translations table identifier is class-controlled and bound to the current blog's wpdb prefix.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT trid, element_id, language_code, element_type
					FROM %i
					WHERE element_type LIKE %s
					AND element_id IS NOT NULL
					AND trid IN ($placeholders)
					ORDER BY trid ASC",
					array_merge( [ $icl_table ], [ 'post_%' ], $batch )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $rows ) ) {
				continue;
			}

			// Group this batch's rows by trid.
			$groups_by_trid = [];
			foreach ( $rows as $row ) {
				$groups_by_trid[ $row->trid ][] = $row;
			}

			$imported += $this->process_post_trid_groups( $groups_by_trid, $result );

			// Bound worker memory on huge legacy sites: drop runtime object-cache
			// copies + SAVEQUERIES log accumulated by this batch (re-fetched on
			// demand; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $imported;
	}

	/**
	 * Per-batch trid-group processor. Extracted from {@see import_post_translations()}
	 * so the same code path serves the chunked fetch above — and so a future
	 * refactor (e.g. parallelism, partial-progress checkpoints) only has to
	 * touch one place.
	 *
	 * @param array<int, array<int, object>> $groups_by_trid trid => row[]
	 * @param array<string, mixed>           $result          Accumulator (errors[]).
	 * @return int Number of group + link writes that succeeded.
	 */
	private function process_post_trid_groups( array $groups_by_trid, array &$result ): int {
		$imported = 0;

		// WPML writes an icl_translations row (with authoritative language) for
		// EVERY element, including untranslated ones that form a single-row
		// trid. When that lone language is the site DEFAULT, PerfLocale's
		// fallback convention already resolves the unassigned post correctly, so
		// a one-member group is redundant. But a NON-default single-language
		// post MUST get an explicit group/link or it is served as the default
		// language (wrong language, wrong hreflang). Resolve the default once.
		$default_lang    = $this->languages->get_default();
		$default_lang_id = $default_lang !== null ? (int) $default_lang->id : 0;

		foreach ( $groups_by_trid as $trid_rows ) {
			// Seed from the FIRST row whose language maps AND whose post still
			// exists — not blindly $trid_rows[0]. WPML lists trid rows in no
			// guaranteed order, so a deleted-source / unmapped row landing at
			// index 0 must not skip the whole trid and drop its still-valid
			// siblings. Swap the chosen seed to the front so the index-0 logic
			// below (and the per-sibling guard in the link loop) is unchanged.
			$first_lang = null;

			foreach ( $trid_rows as $seed_pos => $candidate ) {
				$cand_lang = $this->language_map[ $candidate->language_code ] ?? null;

				if ( $cand_lang !== null && get_post( (int) $candidate->element_id ) ) {
					$first_lang = $cand_lang;

					if ( $seed_pos !== 0 ) {
						$tmp                    = $trid_rows[0];
						$trid_rows[0]           = $trid_rows[ $seed_pos ];
						$trid_rows[ $seed_pos ] = $tmp;
					}

					break;
				}
			}

			if ( $first_lang === null ) {
				continue;
			}

			// A single-language trid is only worth a group when its language is
			// non-default; a lone default-language post is handled by the
			// fallback convention and needs no row.
			if ( count( $trid_rows ) < 2 && $first_lang === $default_lang_id ) {
				continue;
			}

			$first = $trid_rows[0];

			// Reuse path #1: prior import already recorded this trid in the
			// migration source-map. Survives DB restores because the map
			// row was committed in the same transaction as the group; if
			// the map row exists, the group exists and the import is safe
			// to skip create_group entirely.
			$source_key = $first->trid . '|post';
			$mapped_id  = $this->source_map->get_group_id( 'wpml', (string) $source_key );

			// Confirm the mapped group still exists before reusing it: a row
			// left behind by a pre-cascade delete (or a manual group removal)
			// would otherwise link posts to a dead group_id. Reuse path #2
			// (find_for_object) covers the first post already being linked
			// from an earlier partial/pre-source-map run.
			$existing = ( $mapped_id !== null ? $this->groups->find( $mapped_id ) : null )
				?: $this->groups->find_for_object( (int) $first->element_id, ObjectType::Post );

			if ( $existing ) {
				$group_id   = (int) $existing->id;
				$seed_index = 0;

				// Converge the source map on the REUSE path too. It is written
				// inside create_group(), so an import over objects that already
				// had a group (the common case — every post gets one on save)
				// left the map empty, and `--force-restart` then cleared it for
				// good. set_group_id() is INSERT .. ON DUPLICATE KEY UPDATE, so
				// this is idempotent and also repairs a stale row.
				if ( $mapped_id !== $group_id ) {
					$this->source_map->set_group_id( 'wpml', (string) $source_key, $group_id );
				}
			} else {
				$new_group_id = $this->groups->create_group(
					ObjectType::Post,
					(int) $first->element_id,
					$first_lang,
					// Derive the link status from the post's real status: a WPML
					// draft translation under a published source must NOT be
					// imported as published, or hreflang/switcher would emit a
					// public alternate URL for a non-public post. Non-publish
					// states collapse to Draft (never over-claims published).
					get_post_status( (int) $first->element_id ) === 'publish'
						? TranslationStatus::Published->value
						: TranslationStatus::Draft->value,
					SourceType::ImportedWpml,
					[
						'type' => 'wpml',
						'key'  => (string) $source_key,
					]
				);

				if ( $new_group_id === false ) {
					$result['errors'][] = sprintf( 'Failed to create group for WPML trid %d.', (int) $first->trid );
					continue;
				}

				$group_id   = (int) $new_group_id;
				$seed_index = 1;
				++$imported;
			}

			// Link every sibling row - including the first when we reused an
			// existing group, since it may belong to a different group from
			// an earlier partial import and link_object() will move it.
			for ( $i = $seed_index, $count = count( $trid_rows ); $i < $count; $i++ ) {
				$row     = $trid_rows[ $i ];
				$lang_id = $this->language_map[ $row->language_code ] ?? null;

				if ( $lang_id === null || ! get_post( (int) $row->element_id ) ) {
					continue;
				}

				// Skip if already in the target group with the same language -
				// avoids spurious "link moved" cache churn on rerun. BOTH
				// halves matter: PerfLocale auto-assigns every new post to the
				// default language on save, so an object can already sit in
				// this group while filed under the wrong language. Skipping on
				// group membership alone left it there (served as the default
				// language), and the sibling linked next evicted its row via
				// link_object()'s group+language DELETE — leaving the object
				// with no link at all.
				$already = $this->groups->find_link_for_object( (int) $row->element_id, ObjectType::Post );

				if ( $already
					&& (int) $already->group_id === $group_id
					&& (int) $already->language_id === (int) $lang_id ) {
					continue;
				}

				$link_result = $this->groups->link_object(
					$group_id,
					(int) $row->element_id,
					$lang_id,
					// Derive from the real post_status — see create_group above.
					get_post_status( (int) $row->element_id ) === 'publish'
						? TranslationStatus::Published->value
						: TranslationStatus::Draft->value,
					SourceType::ImportedWpml
				);

				if ( $link_result !== false ) {
					++$imported;
				}
			}
		}

		return $imported;
	}

	/**
	 * Resolve the per-batch chunk size for both post + term import paths.
	 * Filterable via `perflocale/migration/wpml/batch_size`. Default 100
	 * trids per batch — at ~2 langs each that's ~200 wp_icl_translations
	 * rows per SELECT. Clamped to 10–1000.
	 *
	 * @return int
	 */
	private static function resolve_batch_size(): int {
		/**
		 * Per-batch chunk size during WPML migration. Default 100 trids.
		 *
		 * Lower = smaller memory peak per batch, more SELECT round-trips.
		 * Higher = bigger memory peak, fewer round-trips. The bottleneck on
		 * large migrations is typically the per-row `get_post` /
		 * `find_for_object` chain, not the SELECT, so the default sits in
		 * the middle of the safe range.
		 *
		 * @hook perflocale/migration/wpml/batch_size
		 * @param int $size Default 100.
		 */
		$size = (int) apply_filters( 'perflocale/migration/wpml/batch_size', 100 );
		return max( 10, min( 1000, $size ) );
	}

	/**
	 * Import term translations from WPML.
	 *
	 * @param array{posts: int, terms: int, strings: int, errors: array<int, string>} $result Import result (passed by reference for errors).
	 * @return int Number of terms imported.
	 */
	private function import_term_translations( array &$result ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$icl_table = $this->wpdb->prefix . 'icl_translations';
		$imported  = 0;

		// Same batched-fetch refactor as import_post_translations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$trids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT trid FROM %i
				WHERE element_type LIKE %s AND element_id IS NOT NULL
				ORDER BY trid ASC',
				$icl_table,
				'tax_%'
			)
		);

		if ( empty( $trids ) ) {
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $trids ), $batch_size );

		foreach ( $batches as $batch ) {
			// $placeholders is a generated '%d,%d,...' string sized to the
			// current batch — safe to interpolate into the IN() clause.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// The icl_translations table identifier is class-controlled and bound to the current blog's wpdb prefix.
			// WPML stores the TERM TAXONOMY id (not the term_id) in
			// icl_translations.element_id for taxonomy rows. Resolve the real
			// term_id via wp_term_taxonomy so the group links to a term WP can
			// actually find — using element_id directly links the wrong term
			// (term_id != term_taxonomy_id on most sites) or silently no-ops.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT icl.trid, icl.element_id, icl.language_code, icl.element_type, tt.term_id AS resolved_term_id
					FROM %i icl
					LEFT JOIN {$this->wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = icl.element_id
					WHERE icl.element_type LIKE %s
					AND icl.element_id IS NOT NULL
					AND icl.trid IN ($placeholders)
					ORDER BY icl.trid ASC",
					array_merge( [ $icl_table ], [ 'tax_%' ], $batch )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $rows ) ) {
				continue;
			}

			$groups_by_trid = [];
			foreach ( $rows as $row ) {
				$groups_by_trid[ $row->trid ][] = $row;
			}

			$imported += $this->process_term_trid_groups( $groups_by_trid, $result );

			// Bound worker memory on huge legacy sites: drop runtime object-cache
			// copies + SAVEQUERIES log accumulated by this batch (re-fetched on
			// demand; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $imported;
	}

	/**
	 * Per-batch trid-group processor for the term-translations path.
	 *
	 * @param array<int, array<int, object>> $groups_by_trid
	 * @param array<string, mixed>           $result
	 * @return int
	 */
	private function process_term_trid_groups( array $groups_by_trid, array &$result ): int {
		$imported = 0;

		// See process_post_trid_groups(): a single-language trid only needs a
		// group when its language is non-default, otherwise the default-language
		// fallback convention already resolves it.
		$default_lang    = $this->languages->get_default();
		$default_lang_id = $default_lang !== null ? (int) $default_lang->id : 0;

		foreach ( $groups_by_trid as $trid_rows ) {
			// Seed from the FIRST row whose language maps AND whose term still
			// exists — not blindly $trid_rows[0] — so a deleted/unmapped row at
			// index 0 doesn't skip the whole trid and drop its valid siblings.
			// resolved_term_id is the real term_id (from the wp_term_taxonomy
			// JOIN); element_id is WPML's term_taxonomy_id and must NOT be used
			// as a term_id. Swap the seed to the front so the index-0 logic
			// below (and the per-sibling guard in the link loop) is unchanged.
			$first_lang = null;
			$first_term = 0;

			foreach ( $trid_rows as $seed_pos => $candidate ) {
				$cand_lang = $this->language_map[ $candidate->language_code ] ?? null;
				$cand_term = (int) ( $candidate->resolved_term_id ?? 0 );

				if ( $cand_lang !== null && $cand_term > 0 && term_exists( $cand_term ) ) {
					$first_lang = $cand_lang;
					$first_term = $cand_term;

					if ( $seed_pos !== 0 ) {
						$tmp                    = $trid_rows[0];
						$trid_rows[0]           = $trid_rows[ $seed_pos ];
						$trid_rows[ $seed_pos ] = $tmp;
					}

					break;
				}
			}

			if ( $first_lang === null || $first_term <= 0 ) {
				continue;
			}

			// Single-language trid → only worth a group when non-default.
			if ( count( $trid_rows ) < 2 && $first_lang === $default_lang_id ) {
				continue;
			}

			$first = $trid_rows[0];

			// Same idempotency layering as the post path above —
			// source_map first (survives restores), find_for_object
			// fallback (handles upgrades from before the source_map
			// existed), then create_group with the source_map link.
			$source_key = $first->trid . '|term';
			$mapped_id  = $this->source_map->get_group_id( 'wpml', (string) $source_key );

			// Confirm the mapped group still exists before reusing it (see the
			// post path above) so a stale map row can't link terms to a dead
			// group_id.
			$existing = ( $mapped_id !== null ? $this->groups->find( $mapped_id ) : null )
				?: $this->groups->find_for_object( $first_term, ObjectType::Term );

			if ( $existing ) {
				$group_id   = (int) $existing->id;
				$seed_index = 0;

				// See the post path: keep the source map converged on reuse.
				if ( $mapped_id !== $group_id ) {
					$this->source_map->set_group_id( 'wpml', (string) $source_key, $group_id );
				}
			} else {
				$new_group_id = $this->groups->create_group(
					ObjectType::Term,
					$first_term,
					$first_lang,
					TranslationStatus::Published->value,
					SourceType::ImportedWpml,
					[
						'type' => 'wpml',
						'key'  => (string) $source_key,
					]
				);

				if ( $new_group_id === false ) {
					$result['errors'][] = sprintf( 'Failed to create group for WPML term translation (source key %s).', (string) $source_key );
					continue;
				}

				$group_id   = (int) $new_group_id;
				$seed_index = 1;
				++$imported;
			}

			for ( $i = $seed_index, $count = count( $trid_rows ); $i < $count; $i++ ) {
				$row      = $trid_rows[ $i ];
				$lang_id  = $this->language_map[ $row->language_code ] ?? null;
				$row_term = (int) ( $row->resolved_term_id ?? 0 );

				if ( $lang_id === null || $row_term <= 0 || ! term_exists( $row_term ) ) {
					continue;
				}

				// Language-aware skip — see the post loop above for why group
				// membership alone is not enough.
				$already = $this->groups->find_link_for_object( $row_term, ObjectType::Term );

				if ( $already
					&& (int) $already->group_id === $group_id
					&& (int) $already->language_id === (int) $lang_id ) {
					continue;
				}

				$link_result = $this->groups->link_object(
					$group_id,
					$row_term,
					$lang_id,
					TranslationStatus::Published->value,
					SourceType::ImportedWpml
				);

				if ( $link_result !== false ) {
					++$imported;
				}
			}
		}

		return $imported;
	}

	/**
	 * Import string translations from WPML's icl_strings and icl_string_translations tables.
	 *
	 * @param array{posts: int, terms: int, strings: int, errors: array<int, string>} $result Import result; this method appends to `errors` (skipped rows, failed link writes).
	 * @return int Number of strings imported.
	 */
	private function import_string_translations( array &$result ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$strings_table   = $this->wpdb->prefix . 'icl_strings';
		$str_trans_table = $this->wpdb->prefix . 'icl_string_translations';
		$imported        = 0;
		$link_failures   = 0;

		// Check if strings table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$strings_table
			)
		);

		if ( (int) $table_exists === 0 ) {
			return 0;
		}

		// Hoist the StringTranslationRepository OUT of the per-row loop. The
		// repo is stateless beyond its CacheManager dependency, so a single
		// instance services every iteration. Previously a `new` ran on every
		// row — measurable on a 50k-string WPML site (50k allocations of
		// objects + their wp_cache_get bootstraps) and pure waste.
		$str_trans = new \PerfLocale\Database\Repository\StringTranslationRepository( $this->cache );

		// Keyset-paginated batch loop. Replaces a single SELECT that pulled
		// every WPML string translation into PHP memory at once — which on
		// a 50k-string WPML export was ~25 MB of PHP arrays before the loop
		// even started running and reliably OOM'd the default 256M PHP
		// memory limit. WHERE s.id > $last_id + ORDER BY s.id ASC + LIMIT N
		// is also faster than OFFSET-based pagination because the index seek
		// is constant per batch instead of linear in the offset.
		//
		// Batch size is filterable so very-large-string sites can tune it
		// down (longer per-string values) or hosted environments with
		// generous memory can crank it up to reduce DB round-trips.
		$batch_size = (int) apply_filters( 'perflocale/migration/wpml_string_batch_size', 500 );

		if ( $batch_size < 1 ) {
			$batch_size = 500;
		}

		$last_id          = 0;
		$skipped_unmapped = 0;
		$skipped_insert   = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					// WPML's icl_strings has NO `domain_name` column: the text
					// domain lives in `context` and the gettext msgctxt in
					// `gettext_context`. Selecting the nonexistent column made
					// the whole query error → zero WPML string translations
					// imported. Alias to the names the consumer already expects.
					"SELECT s.id, s.value, s.context AS domain_name, s.gettext_context AS string_context,
							st.id AS st_id, st.language, st.value AS translated_value
					FROM %i s
					INNER JOIN %i st ON st.string_id = s.id
					WHERE st.status IN (%d, %d)
					AND st.value IS NOT NULL
					AND st.value != ''
					AND st.id > %d
					ORDER BY st.id ASC
					LIMIT %d",
					$strings_table,
					$str_trans_table,
					10, // ICL_TM_COMPLETE
					3,  // ICL_TM_NEEDS_UPDATE — WPML still SERVES these (existing
					// translation shown, flagged for review); status=10 alone
						// silently dropped every needs-update translation.
					$last_id,
					$batch_size
				)
			);

			// A non-empty last_error means the batch SELECT itself failed (a
			// WPML schema the aliases don't match, or a missing
			// icl_string_translations table). Surface it instead of breaking
			// out as if the import cleanly finished with zero rows.
			if ( $this->wpdb->last_error !== '' ) {
				$result['errors'][] = 'WPML string translation query failed: ' . $this->wpdb->last_error;
				break;
			}

			if ( ! is_array( $rows ) || $rows === [] ) {
				break;
			}

			foreach ( $rows as $row ) {
				$lang_id = $this->language_map[ $row->language ] ?? null;

				if ( $lang_id === null ) {
					++$skipped_unmapped;
					continue;
				}

				$domain  = sanitize_text_field( $row->domain_name ?? 'default' );
				$context = sanitize_text_field( $row->string_context ?? '' );

				// Check if this string already exists in PerfLocale.
				$existing = $this->strings->find_by_hash( $domain, $context, $row->value );

				if ( ! $existing ) {
					$string_id = $this->strings->insert(
						[
							'domain'   => $domain,
							'context'  => $context,
							'original' => $row->value,
						]
					);

					if ( $string_id === false ) {
						++$skipped_insert;
						continue;
					}

					$existing = $this->strings->find( $string_id );
				}

				if ( ! $existing || empty( $existing->group_id ) ) {
					continue;
				}

				// Idempotency: don't clobber an existing translation (e.g. a
				// human correction made after a prior migration run) — mirrors
				// the TranslatePress guard. Skips only the value write; the
				// link upsert below still runs, so a value row whose link
				// write once failed self-heals on a re-run.
				$has_translation = $str_trans->get( (int) $existing->id, (int) $lang_id ) !== '';

				if ( ! $has_translation ) {
					$saved = $str_trans->set(
						(int) $existing->id,
						(int) $lang_id,
						(string) $row->translated_value
					);

					if ( $saved ) {
						++$imported;
						$has_translation = true;
					}
				}

				if ( $has_translation ) {
					// Mark the string's group translated for this language.
					// The value row alone is NEVER SERVED: the DB-mode
					// gettext map, the files-mode generator fetch, and the
					// strings admin grid all INNER JOIN translation_links —
					// without this row every imported string translation is
					// stored but invisible. upsert_link() (not link_object())
					// is the string-group-safe call; a single ON DUPLICATE
					// KEY upsert makes re-runs no-ops.
					//
					// The return is checked precisely because of that: a
					// translation whose link write failed is stored and
					// counted, yet served nowhere. Silently dropping the false
					// is what let a migration report "N strings imported" for
					// strings the site never showed.
					$linked = $this->groups->upsert_link(
						(int) $existing->group_id,
						(int) $existing->id,
						(int) $lang_id,
						'translated',
						\PerfLocale\Enum\SourceType::ImportedWpml
					);

					if ( $linked === false ) {
						++$link_failures;
					}
				}
			}

			// Advance keyset cursor. Even if the last batch was short, we
			// still capture the highest id so a partial-fail+restart can
			// resume cleanly (file/data import jobs use the same idiom).
			$last_id   = (int) end( $rows )->st_id;
			$row_count = count( $rows );

			// Release the batch array reference so PHP can reclaim the
			// memory before the next iteration. Critical on big sites:
			// without this, gc_collect_cycles() can leave the previous
			// batch in scope until the do-while condition is evaluated.
			unset( $rows );

			// Bound worker memory between streamed batches (runtime cache +
			// SAVEQUERIES log only; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

			// Partial batch → end of data. Avoids one wasted round-trip
			// that would just return zero rows under the cursor we just
			// advanced past.
			if ( $row_count < $batch_size ) {
				break;
			}
		} while ( true );

		if ( $link_failures > 0 ) {
			$result['errors'][] = sprintf(
				/* translators: %d: number of failed translation-link writes */
				__( 'WPML string import: %d translation link write(s) failed — the affected strings are stored but not served; re-run the import to repair them.', 'perflocale' ),
				$link_failures
			);
		}

		if ( $skipped_unmapped > 0 || $skipped_insert > 0 ) {
			$result['errors'][] = sprintf(
				/* translators: 1: total skipped, 2: unmapped-language count, 3: insert-failure count */
				__( 'WPML string import skipped %1$d translation(s): %2$d with no matching PerfLocale language, %3$d that failed to insert.', 'perflocale' ),
				$skipped_unmapped + $skipped_insert,
				$skipped_unmapped,
				$skipped_insert
			);
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $imported;
	}
}
