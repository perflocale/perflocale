<?php
/**
 * Polylang migration importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\MigrationSourceMapRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Enum\TranslationStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports translation data from Polylang.
 *
 * Reads Polylang's term_taxonomy entries (taxonomy `language`,
 * `post_translations`, and `term_translations`) to reconstruct
 * translation relationships in PerfLocale's group system.
 */
final class PolylangImporter {

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
	 * Source-map for idempotency across DB restores + partial-failure
	 * retries. Pins a Polylang translation-group term_id to the
	 * translation_groups row PerfLocale allocated for it.
	 *
	 * @var MigrationSourceMapRepository
	 */
	private readonly MigrationSourceMapRepository $source_map;

	/**
	 * Language slug mapping: Polylang slug => PerfLocale language ID.
	 *
	 * @var array<string, int>
	 */
	private array $language_map = [];

	/**
	 * Translation-link writes this run asked for and did not get.
	 *
	 * A refused link is not fatal — the objects keep their content — but the
	 * translation relationship it stood for is simply absent, and the import
	 * summary counts only what was written. Tallied here and surfaced once in
	 * {@see import()} so the operator is told to re-run instead of reading a
	 * clean-looking count over a partial migration.
	 *
	 * @var int
	 */
	private int $link_failures = 0;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager — accepted for DI consistency
	 *                           with sibling importers; importer flushes via
	 *                           the cache manager wired into the language and
	 *                           group repos rather than holding a direct ref.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- DI signature parity with sibling importers.
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb       = $wpdb;
		$this->languages  = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$this->groups     = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$this->source_map = new MigrationSourceMapRepository();
	}

	/**
	 * Check if Polylang taxonomy exists and import is possible.
	 *
	 * @return bool
	 */
	public function can_import(): bool {
		return taxonomy_exists( 'language' ) || $this->polylang_terms_exist();
	}

	/**
	 * Run the full Polylang import.
	 *
	 * @return array{posts: int, terms: int, errors: array<int, string>}
	 */
	public function import(): array {
		$result = [
			'posts'  => 0,
			'terms'  => 0,
			'errors' => [],
		];

		if ( ! $this->can_import() ) {
			$result['errors'][] = 'Polylang language taxonomy not found.';
			return $result;
		}

		$this->build_language_map( $result );

		if ( empty( $this->language_map ) ) {
			$result['errors'][] = 'No matching languages found between Polylang and PerfLocale.';
			return $result;
		}

		$result['posts'] = $this->import_post_translations( $result );
		$result['terms'] = $this->import_term_translations( $result );

		// Polylang creates post_translations/term_translations terms only for
		// LINKED objects — untranslated content carries its authoritative
		// language solely in the 'language' / 'term_language' taxonomies. A
		// non-default single-language object MUST still get an explicit
		// one-member group/link or it is served as the default language
		// (wrong language, wrong hreflang) — same rule as the WPML importer's
		// single-row-trid handling.
		$result['posts'] += $this->import_single_language_objects( 'language', ObjectType::Post, $result );
		$result['terms'] += $this->import_single_language_objects( 'term_language', ObjectType::Term, $result );

		if ( $this->link_failures > 0 ) {
			$result['errors'][] = sprintf(
				/* translators: %d: number of failed translation-link writes */
				__( 'Polylang import: %d translation link(s) could not be written — those translations are not connected to their group; re-run the import to retry them.', 'perflocale' ),
				$this->link_failures
			);
		}

		return $result;
	}

	/**
	 * Check if Polylang language terms exist in the database.
	 *
	 * Used when the Polylang plugin is deactivated but its data remains.
	 *
	 * @return bool
	 */
	private function polylang_terms_exist(): bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->wpdb->term_taxonomy} WHERE taxonomy = %s",
				'language'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $count > 0;
	}

	/**
	 * Build a mapping from Polylang language slugs to PerfLocale language IDs.
	 *
	 * @param array{posts: int, terms: int, errors: array<int, string>} $result Import result (passed by reference for errors).
	 * @return void
	 */
	private function build_language_map( array &$result ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Read the term DESCRIPTION too: Polylang stores each language's
		// authoritative WordPress locale there as a serialized array
		// (['locale' => 'pt_BR', 'rtl' => …, 'flag_code' => …]). Matching on
		// that exact locale — instead of a greedy str_starts_with on the slug —
		// is the only way to distinguish regional variants (pt_BR vs pt_PT) and
		// to handle Polylang's hyphenated slugs ('pt-br') against PerfLocale's
		// underscore locales ('pt_BR').
		$pll_langs = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT t.slug, t.name, tt.description
				FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				'language'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $pll_langs ) ) {
			return;
		}

		$active_languages = $this->languages->get_active();

		// Guard against two Polylang languages resolving to the SAME PerfLocale
		// id (a locale match on one plus a slug-fallback match on another, or
		// duplicate Polylang locales). The second would silently duplicate that
		// language inside every migrated group — one object_id per (group,
		// language) is a hard invariant. Keep the first, skip and report the rest.
		$claimed_by = [];

		foreach ( $pll_langs as $pll_lang ) {
			$pll_slug   = sanitize_text_field( $pll_lang->slug );
			$pll_locale = self::extract_pll_locale( (string) ( $pll_lang->description ?? '' ) );

			$matched_id = null;

			// Pass 1 — Polylang's locale is authoritative (slugs can collide, e.g.
			// a PerfLocale 'pt'=pt_BR vs a Polylang 'pt'=pt_PT). Exact, normalised
			// locale match so 'pt-br' === 'pt_BR' and pt_BR/pt_PT never collapse.
			if ( $pll_locale !== '' ) {
				foreach ( $active_languages as $lang ) {
					if ( self::normalize_locale( $lang->locale ) === self::normalize_locale( $pll_locale ) ) {
						$matched_id = (int) $lang->id;
						break;
					}
				}
			}

			// Pass 2 — fall back to an exact slug match (no description locale, or
			// a locale PerfLocale doesn't carry). Never a str_starts_with prefix.
			if ( $matched_id === null ) {
				foreach ( $active_languages as $lang ) {
					if ( $lang->slug === $pll_slug ) {
						$matched_id = (int) $lang->id;
						break;
					}
				}
			}

			if ( $matched_id !== null ) {
				if ( isset( $claimed_by[ $matched_id ] ) ) {
					$result['errors'][] = sprintf(
						/* translators: 1: first Polylang slug, 2: colliding Polylang slug, 3: colliding slug again */
						__( 'Polylang languages "%1$s" and "%2$s" both map to the same PerfLocale language — "%3$s" was skipped. Add a distinct PerfLocale language for it and re-run.', 'perflocale' ),
						$claimed_by[ $matched_id ],
						$pll_slug,
						$pll_slug
					);
					continue;
				}

				$claimed_by[ $matched_id ]       = $pll_slug;
				$this->language_map[ $pll_slug ] = $matched_id;
			} else {
				$result['errors'][] = sprintf(
					'No PerfLocale language match for Polylang slug "%s" (locale "%s").',
					$pll_slug,
					$pll_locale
				);
			}
		}
	}

	/**
	 * Extract the canonical locale from a Polylang `language`-term description
	 * (a serialized array with a 'locale' key). Returns '' when absent/malformed.
	 *
	 * @param string $description Serialized term-taxonomy description.
	 * @return string
	 */
	private static function extract_pll_locale( string $description ): string {
		if ( $description === '' ) {
			return '';
		}

		// Decode with allowed_classes=false to block PHP-object injection from a
		// crafted term description — the same guard already used at the other
		// two unserialize sites in this importer. A serialized array still
		// decodes; a serialized object becomes __PHP_Incomplete_Class and fails
		// the is_array() check below.
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false is the exact mitigation the rule warns about; @ suppresses the unsupported-class notice the option already neutralises.
		$meta = is_serialized( $description )
			? @unserialize( $description, [ 'allowed_classes' => false ] )
			: $description;
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_array( $meta ) && isset( $meta['locale'] ) && is_string( $meta['locale'] ) ) {
			return sanitize_text_field( $meta['locale'] );
		}

		return '';
	}

	/**
	 * Normalise a locale for comparison: lowercase, hyphens => underscores.
	 *
	 * @param string $locale Locale string.
	 * @return string
	 */
	private static function normalize_locale( string $locale ): string {
		return strtolower( str_replace( '-', '_', $locale ) );
	}

	/**
	 * Import post translations from Polylang's post_translations taxonomy.
	 *
	 * Polylang stores translation groups as serialized arrays in
	 * term meta under the `post_translations` taxonomy.
	 *
	 * @param array{posts: int, terms: int, errors: array<int, string>} $result Import result (passed by reference for errors).
	 * @return int Number of posts imported.
	 */
	private function import_post_translations( array &$result ): int {
		$imported = 0;

		// Batched-fetch refactor: fetch only the term_ids for the
		// 'post_translations' taxonomy first (cheap — BIGINT per row),
		// chunk them, then per chunk fetch the (term_id, description)
		// rows. Description is a serialized PHP array that maybe-unserialize
		// expands into N slots in memory per term, so chunking is the
		// real memory win here.

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$term_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT t.term_id FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				'post_translations'
			)
		);

		if ( empty( $term_ids ) ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $term_ids ), $batch_size );

		foreach ( $batches as $batch ) {
			// $placeholders is a generated '%d,%d,...' string sized to the
			// current batch — safe to interpolate into the IN() clause.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// $this->wpdb->terms / ->term_taxonomy are wpdb-provided table names.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$translation_terms = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT t.term_id, tt.description
					FROM {$this->wpdb->terms} t
					INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					AND t.term_id IN ($placeholders)",
					array_merge( [ 'post_translations' ], $batch )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $translation_terms ) ) {
				continue;
			}

			$imported += $this->process_post_translation_terms( $translation_terms, $result );
			// Bound worker memory on huge legacy sites (runtime cache +
			// SAVEQUERIES log only; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}
		return $imported;
	}

	/**
	 * Per-batch processor for the post-translations taxonomy terms.
	 *
	 * @param array<int, object>   $translation_terms term rows for this batch
	 * @param array<string, mixed> $result            accumulator (errors[])
	 * @return int
	 */
	private function process_post_translation_terms( array $translation_terms, array &$result ): int {
		$imported = 0;

		// Bulk-prime caches for every post id referenced by this batch in
		// ONE pass before the per-term loop. Previously each iteration
		// called get_post() and groups->find_for_object() per language slug
		// twice (once to find seed, once to link siblings) — on a typical
		// 5-language Polylang term with N terms in a batch, that's
		// 2 × 5 × N round-trips, each a DB lookup until WP's per-post-id
		// cache warmed up incidentally during the loop.
		//
		// _prime_post_caches() warms WP's wp_posts + post_meta caches with
		// a single SELECT ... WHERE ID IN (...). groups->prime_translations()
		// does the same for our translation_groups/links tables. After both
		// prime calls every per-row get_post() and find_for_object() inside
		// the loop is a static-cache hit (~nanoseconds vs ~milliseconds).
		$all_post_ids = [];

		foreach ( $translation_terms as $term ) {
			// Same allowed_classes=false guard as below; we have to decode
			// twice (once here for the prime collection, once below for the
			// actual processing) but both decodes hit the same cached
			// is_serialized() result and the second decode runs against
			// the warmed in-memory string. The cost is a few hundred
			// microseconds total vs. the DB cost we'd otherwise pay.
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false is the exact mitigation the rule warns about; @ suppresses the unsupported-class notice that the unserialize() options already neutralise.
			$translations_for_prime = is_serialized( (string) $term->description )
				? @unserialize( (string) $term->description, [ 'allowed_classes' => false ] )
				: $term->description;
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! is_array( $translations_for_prime ) ) {
				continue;
			}

			foreach ( $translations_for_prime as $post_id ) {
				$post_id = (int) $post_id;
				if ( $post_id > 0 ) {
					$all_post_ids[ $post_id ] = true; // dedupe via map keys
				}
			}
		}

		if ( $all_post_ids !== [] ) {
			$post_id_list = array_keys( $all_post_ids );

			if ( function_exists( '_prime_post_caches' ) ) {
				// Skip term + meta priming (false, false) — we only need
				// post existence + post-row data here, not post_meta or
				// terms. Saves two extra SELECTs over the same id set.
				_prime_post_caches( $post_id_list, false, false );
			}

			$this->groups->prime_translations( ObjectType::Post, $post_id_list );
		}

		foreach ( $translation_terms as $term ) {
			// allowed_classes=false blocks object instantiation: Polylang
			// writes this row when lower-privileged users assign language
			// links via Polylang's UI, so unserialising without restriction
			// would let editor-level POP gadgets run as the manage_options
			// admin who triggers the migration.
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false is the exact mitigation the rule warns about; @ suppresses the unsupported-class notice that the unserialize() options already neutralise.
			$translations = is_serialized( (string) $term->description )
				? @unserialize( (string) $term->description, [ 'allowed_classes' => false ] )
				: $term->description;
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

			// A non-empty description that failed to unserialize (truncated/
			// corrupt row) would otherwise be dropped silently — surface it so
			// the operator knows a group was skipped rather than assuming a
			// clean migration.
			if ( ! is_array( $translations ) && '' !== trim( (string) $term->description ) ) {
				$result['errors'][] = sprintf(
					/* translators: %d: term_taxonomy term id */
					__( 'Unreadable Polylang post_translations data for term %d — group skipped.', 'perflocale' ),
					(int) $term->term_id
				);
				continue;
			}

			if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
				continue;
			}

			// Reuse path #1: migration source-map. Survives DB restores
			// because the map row was committed in the same transaction as
			// the group. Confirm the group still exists before reusing it so a
			// row left by a pre-cascade delete can't link posts to a dead id.
			$source_key        = $term->term_id . '|post';
			$mapped_id         = $this->source_map->get_group_id( 'polylang', (string) $source_key );
			$existing_group_id = ( $mapped_id !== null && $this->groups->find( $mapped_id ) ) ? $mapped_id : null;

			// Find an existing PerfLocale group for any post in the set -
			// supports resumable imports after a partial run that pre-dated
			// the source_map (e.g. upgrade from an older version). If none
			// exist, pick the first valid entry as the seed for a new group.
			$first_slug    = null;
			$first_post_id = null;
			$first_lang_id = null;

			foreach ( $translations as $lang_slug => $post_id ) {
				$post_id = (int) $post_id;
				$lang_id = $this->language_map[ $lang_slug ] ?? null;

				if ( $lang_id === null || ! get_post( $post_id ) ) {
					continue;
				}

				if ( $existing_group_id === null ) {
					$existing = $this->groups->find_for_object( $post_id, ObjectType::Post );

					if ( $existing ) {
						$existing_group_id = (int) $existing->id;
					}
				}

				if ( $first_slug === null ) {
					$first_slug    = $lang_slug;
					$first_post_id = $post_id;
					$first_lang_id = $lang_id;
				}
			}

			if ( $first_slug === null || $first_post_id === null || $first_lang_id === null ) {
				continue;
			}

			if ( $existing_group_id !== null ) {
				$group_id  = $existing_group_id;
				$skip_seed = false; // Link every sibling, existing group may be missing some.

				// Converge the source map on the REUSE path too. It is only
				// written inside create_group(), so importing over objects that
				// already had a group (the common case — every post gets one on
				// save) left the map empty, and `--force-restart` then cleared
				// it permanently. set_group_id() is INSERT .. ON DUPLICATE KEY
				// UPDATE, so this is idempotent and repairs stale rows too.
				if ( $mapped_id !== $group_id ) {
					$this->source_map->set_group_id( 'polylang', (string) $source_key, $group_id );
				}
			} else {
				$new_group_id = $this->groups->create_group(
					ObjectType::Post,
					$first_post_id,
					$first_lang_id,
					// Derive the link status from the post's real status: a draft
					// translation must NOT be imported as published, or hreflang/
					// switcher would emit a public alternate URL for a non-public
					// post. Non-publish states collapse to Draft (never
					// over-claims published). Matches the WPML importer.
					get_post_status( $first_post_id ) === 'publish'
						? TranslationStatus::Published->value
						: TranslationStatus::Draft->value,
					SourceType::ImportedPolylang,
					[
						'type' => 'polylang',
						'key'  => (string) $source_key,
					]
				);

				if ( $new_group_id === false ) {
					$result['errors'][] = sprintf( 'Failed to create group for Polylang post translation term %d.', (int) $term->term_id );
					continue;
				}

				$group_id  = (int) $new_group_id;
				$skip_seed = true;
				++$imported;
			}

			foreach ( $translations as $lang_slug => $post_id ) {
				if ( $skip_seed && $lang_slug === $first_slug ) {
					continue;
				}

				$post_id = (int) $post_id;
				$lang_id = $this->language_map[ $lang_slug ] ?? null;

				if ( $lang_id === null || ! get_post( $post_id ) ) {
					continue;
				}

				// Skip only when the post is in the target group AND already
				// filed under this language. PerfLocale auto-assigns the
				// default language on save, so a group-only check leaves the
				// seed mis-languaged and lets the next sibling's link_object()
				// (which deletes the group+language row it replaces) drop it
				// entirely.
				$already = $this->groups->find_link_for_object( $post_id, ObjectType::Post );

				if ( $already
					&& (int) $already->group_id === $group_id
					&& (int) $already->language_id === (int) $lang_id ) {
					continue;
				}

				$link_result = $this->groups->link_object(
					$group_id,
					$post_id,
					$lang_id,
					// Derive from the real post_status — see create_group above.
					get_post_status( $post_id ) === 'publish'
						? TranslationStatus::Published->value
						: TranslationStatus::Draft->value,
					SourceType::ImportedPolylang
				);

				if ( $link_result !== false ) {
					++$imported;
				} else {
					++$this->link_failures;
				}
			}
		}

		return $imported;
	}

	/**
	 * Import term translations from Polylang's term_translations taxonomy.
	 *
	 * @param array{posts: int, terms: int, errors: array<int, string>} $result Import result (passed by reference for errors).
	 * @return int Number of terms imported.
	 */
	private function import_term_translations( array &$result ): int {
		$imported = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$term_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT t.term_id FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				'term_translations'
			)
		);

		if ( empty( $term_ids ) ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return 0;
		}

		$batch_size = self::resolve_batch_size();
		$batches    = array_chunk( array_map( 'intval', $term_ids ), $batch_size );

		foreach ( $batches as $batch ) {
			// $placeholders is a generated '%d,%d,...' string sized to the
			// current batch — safe to interpolate into the IN() clause.
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

			// $this->wpdb->terms / ->term_taxonomy are wpdb-provided table names.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$translation_terms = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT t.term_id, tt.description
					FROM {$this->wpdb->terms} t
					INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = %s
					AND t.term_id IN ($placeholders)",
					array_merge( [ 'term_translations' ], $batch )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $translation_terms ) ) {
				continue;
			}

			$imported += $this->process_term_translation_terms( $translation_terms, $result );
			// Bound worker memory on huge legacy sites (runtime cache +
			// SAVEQUERIES log only; persistent cache untouched).
			\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
		}
		return $imported;
	}

	/**
	 * Per-batch processor for the term-translations taxonomy terms.
	 *
	 * @param array<int, object>   $translation_terms term rows for this batch
	 * @param array<string, mixed> $result            accumulator (errors[])
	 * @return int
	 */
	private function process_term_translation_terms( array $translation_terms, array &$result ): int {
		$imported = 0;

		foreach ( $translation_terms as $term ) {
			// allowed_classes=false blocks object instantiation: Polylang
			// writes this row when lower-privileged users assign language
			// links via Polylang's UI, so unserialising without restriction
			// would let editor-level POP gadgets run as the manage_options
			// admin who triggers the migration.
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false is the exact mitigation the rule warns about; @ suppresses the unsupported-class notice that the unserialize() options already neutralise.
			$translations = is_serialized( (string) $term->description )
				? @unserialize( (string) $term->description, [ 'allowed_classes' => false ] )
				: $term->description;
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

			// Surface an unreadable (truncated/corrupt) serialized description
			// instead of dropping it silently.
			if ( ! is_array( $translations ) && '' !== trim( (string) $term->description ) ) {
				$result['errors'][] = sprintf(
					/* translators: %d: term_taxonomy term id */
					__( 'Unreadable Polylang term_translations data for term %d — group skipped.', 'perflocale' ),
					(int) $term->term_id
				);
				continue;
			}

			if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
				continue;
			}

			// Source-map first — survives DB restores. Same layering as the
			// post path in process_post_translation_terms().
			$term_source_key   = $term->term_id . '|term';
			$mapped_term_id    = $this->source_map->get_group_id( 'polylang', (string) $term_source_key );
			$existing_group_id = ( $mapped_term_id !== null && $this->groups->find( $mapped_term_id ) ) ? $mapped_term_id : null;
			$first_slug        = null;
			$first_term_id     = null;
			$first_lang_id     = null;

			foreach ( $translations as $lang_slug => $term_id ) {
				$term_id = (int) $term_id;
				$lang_id = $this->language_map[ $lang_slug ] ?? null;

				if ( $lang_id === null || ! term_exists( $term_id ) ) {
					continue;
				}

				if ( $existing_group_id === null ) {
					$existing = $this->groups->find_for_object( $term_id, ObjectType::Term );

					if ( $existing ) {
						$existing_group_id = (int) $existing->id;
					}
				}

				if ( $first_slug === null ) {
					$first_slug    = $lang_slug;
					$first_term_id = $term_id;
					$first_lang_id = $lang_id;
				}
			}

			if ( $first_slug === null || $first_term_id === null || $first_lang_id === null ) {
				continue;
			}

			if ( $existing_group_id !== null ) {
				$group_id  = $existing_group_id;
				$skip_seed = false;

				// See the post path: keep the source map converged on reuse.
				if ( $mapped_term_id !== $group_id ) {
					$this->source_map->set_group_id( 'polylang', (string) $term_source_key, $group_id );
				}
			} else {
				$new_group_id = $this->groups->create_group(
					ObjectType::Term,
					$first_term_id,
					$first_lang_id,
					TranslationStatus::Published->value,
					SourceType::ImportedPolylang,
					[
						'type' => 'polylang',
						'key'  => (string) $term_source_key,
					]
				);

				if ( $new_group_id === false ) {
					$result['errors'][] = sprintf( 'Failed to create group for Polylang term translation term %d.', (int) $term->term_id );
					continue;
				}

				$group_id  = (int) $new_group_id;
				$skip_seed = true;
				++$imported;
			}

			foreach ( $translations as $lang_slug => $term_id ) {
				if ( $skip_seed && $lang_slug === $first_slug ) {
					continue;
				}

				$term_id = (int) $term_id;
				$lang_id = $this->language_map[ $lang_slug ] ?? null;

				if ( $lang_id === null || ! term_exists( $term_id ) ) {
					continue;
				}

				// Language-aware skip — see the post loop above.
				$already = $this->groups->find_link_for_object( $term_id, ObjectType::Term );

				if ( $already
					&& (int) $already->group_id === $group_id
					&& (int) $already->language_id === (int) $lang_id ) {
					continue;
				}

				$link_result = $this->groups->link_object(
					$group_id,
					$term_id,
					$lang_id,
					TranslationStatus::Published->value,
					SourceType::ImportedPolylang
				);

				if ( $link_result !== false ) {
					++$imported;
				} else {
					++$this->link_failures;
				}
			}
		}

		return $imported;
	}

	/**
	 * Create one-member groups for objects that have a Polylang language but
	 * no linked translations.
	 *
	 * Polylang stores each object's language as a term relationship — posts
	 * under the 'language' taxonomy, terms under 'term_language' (whose term
	 * slugs carry a 'pll_' prefix). Objects without linked translations never
	 * appear in the post_translations/term_translations taxonomies, so the
	 * pair passes above cannot see them. Default-language objects need no
	 * group (the fallback convention resolves them); every other language
	 * needs an explicit link or the object is served as the default language.
	 *
	 * @param string               $taxonomy Polylang language taxonomy name.
	 * @param ObjectType           $type     Object type the taxonomy classifies.
	 * @param array<string, mixed> $result   Accumulator (errors[]).
	 * @return int Number of groups created.
	 */
	private function import_single_language_objects( string $taxonomy, ObjectType $type, array &$result ): int {
		$imported = 0;

		$default_lang    = $this->languages->get_default();
		$default_lang_id = $default_lang !== null ? (int) $default_lang->id : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$lang_terms = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT tt.term_taxonomy_id, tt.term_id, t.slug
				FROM {$this->wpdb->terms} t
				INNER JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s",
				$taxonomy
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $lang_terms ) ) {
			return 0;
		}

		$batch_size = self::resolve_batch_size();

		foreach ( $lang_terms as $lang_term ) {
			// 'term_language' term slugs carry a 'pll_' prefix; strip it so
			// both taxonomies resolve through the same language_map keys
			// (which build_language_map() built from sanitized 'language' slugs).
			$slug    = sanitize_text_field( (string) $lang_term->slug );
			$slug    = str_starts_with( $slug, 'pll_' ) ? substr( $slug, 4 ) : $slug;
			$lang_id = $this->language_map[ $slug ] ?? null;

			// A lone default-language object is handled by the fallback
			// convention and needs no row.
			if ( $lang_id === null || $lang_id === $default_lang_id ) {
				continue;
			}

			// Keyset-paginate this language's object ids so a huge
			// single-language content set never lands in memory at once.
			$last_object_id = 0;

			do {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$object_ids = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT object_id FROM {$this->wpdb->term_relationships}
						WHERE term_taxonomy_id = %d AND object_id > %d
						ORDER BY object_id ASC
						LIMIT %d",
						(int) $lang_term->term_taxonomy_id,
						$last_object_id,
						$batch_size
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( empty( $object_ids ) ) {
					break;
				}

				$object_ids     = array_map( 'intval', $object_ids );
				$last_object_id = (int) end( $object_ids );

				// Prime existence + group lookups for the whole batch so the
				// per-object checks below are cache hits, not per-row SELECTs.
				if ( ObjectType::Post === $type && function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( $object_ids, false, false );
				}

				$this->groups->prime_translations( $type, $object_ids );

				foreach ( $object_ids as $object_id ) {
					$object_exists = ObjectType::Post === $type
						? (bool) get_post( $object_id )
						: (bool) term_exists( $object_id );

					if ( ! $object_exists ) {
						continue;
					}

					// Derive the link status once — a draft must never import as
					// published. Terms have no draft state.
					$link_status = ( ObjectType::Post === $type && get_post_status( $object_id ) !== 'publish' )
						? TranslationStatus::Draft->value
						: TranslationStatus::Published->value;

					// Objects already grouped by the pair passes (or a prior
					// run of this pass) are done — but ONLY when they are also
					// filed under the right language. Every post PerfLocale has
					// seen already sits in an auto-created default-language
					// group, so a group-only check skipped exactly the objects
					// this pass exists to fix: a lone Polylang `de` post stayed
					// flagged English and was served (and hreflang'd) as the
					// default language. Re-language it inside its existing
					// group rather than creating a second one.
					$existing_link = $this->groups->find_link_for_object( $object_id, $type );

					if ( $existing_link ) {
						if ( (int) $existing_link->language_id === (int) $lang_id ) {
							continue;
						}

						$relinked = $this->groups->link_object(
							(int) $existing_link->group_id,
							$object_id,
							$lang_id,
							$link_status,
							SourceType::ImportedPolylang
						);

						if ( $relinked !== false ) {
							++$imported;
						} else {
							++$this->link_failures;
						}

						continue;
					}

					$source_key = $lang_term->term_id . ':' . $object_id . '|' . $type->value;

					// A live source-map row means a prior run created this
					// group; if the object was since unlinked by hand, don't
					// fight the removal.
					$mapped_id = $this->source_map->get_group_id( 'polylang', (string) $source_key );

					if ( $mapped_id !== null && $this->groups->find( $mapped_id ) ) {
						continue;
					}

					$new_group_id = $this->groups->create_group(
						$type,
						$object_id,
						$lang_id,
						$link_status,
						SourceType::ImportedPolylang,
						[
							'type' => 'polylang',
							'key'  => (string) $source_key,
						]
					);

					if ( $new_group_id === false ) {
						$result['errors'][] = sprintf(
							'Failed to create single-language group for Polylang %s %d.',
							$type->value,
							$object_id
						);
						continue;
					}

					++$imported;
				}

				// Bound worker memory on huge legacy sites (runtime cache +
				// SAVEQUERIES log only; persistent cache untouched).
				\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();
			} while ( count( $object_ids ) === $batch_size );
		}

		return $imported;
	}

	/**
	 * Resolve the per-batch fetch size for Polylang term reads.
	 *
	 * Each batch fetches `description` (a serialized PHP array) for N
	 * `post_translations` / `term_translations` taxonomy terms. Smaller
	 * batches use less memory at the cost of more SQL roundtrips.
	 *
	 * @return int Clamped to [10, 1000].
	 */
	private static function resolve_batch_size(): int {
		/**
		 * Filter the per-batch SELECT size used by the Polylang importer.
		 *
		 * The importer first fetches every `post_translations` /
		 * `term_translations` term_id, chunks them by this size, and only
		 * then fetches the `description` payload (serialized translation
		 * map). Lowering this value reduces peak memory during very large
		 * migrations; raising it reduces the number of SQL roundtrips.
		 *
		 * Returned values are clamped to the range [10, 1000].
		 *
		 * @hook perflocale/migration/polylang/batch_size
		 * @since 1.0.0
		 *
		 * @param int $size Default 100.
		 * @return int
		 *
		 * @example
		 * add_filter( 'perflocale/migration/polylang/batch_size', fn() => 250 );
		 */
		$size = (int) apply_filters( 'perflocale/migration/polylang/batch_size', 100 );

		return max( 10, min( 1000, $size ) );
	}
}
