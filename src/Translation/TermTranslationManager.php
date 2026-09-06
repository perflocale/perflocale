<?php
/**
 * Term translation manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Helper;
use PerfLocale\Enum\SourceType;
use PerfLocale\Enum\TranslationStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages translation relationships between terms across languages.
 *
 * Same group-based linking pattern as PostTranslationManager but
 * operates on taxonomy terms.
 */
final class TermTranslationManager {

	/**
	 * Longest slug sanitize_title() will hand back.
	 *
	 * Core's sanitize_title_with_dashes() runs its input through
	 * utf8_uri_encode( $title, 200 ), so anything longer comes back cut to 200
	 * characters. The cut is not merely cosmetic here: every slug this class
	 * builds is "<base>-<suffix>", so when the base sits at (or one short of)
	 * the cap it is the suffix that gets shaved off and the result sanitizes
	 * straight back to the base it was derived from.
	 */
	private const SLUG_MAX_LENGTH = 200;

	/**
	 * Highest "-N" suffix force_insert_term() walks to before giving up.
	 */
	private const MAX_SLUG_SUFFIX = 999;

	/**
	 * @var TranslationGroupRepository
	 */
	private readonly TranslationGroupRepository $groups;

	/**
	 * @var LanguageRepository
	 */
	private readonly LanguageRepository $languages;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->groups    = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$this->languages = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$this->cache     = $cache;
	}

	/**
	 * Strip a trailing "-<language-slug>" from a term slug to recover the
	 * canonical base — but ONLY when the suffix is an actual active language
	 * slug. The previous `/-[a-z]{2,3}$/` matched any short trailing word
	 * ("green-tea" → "green", "how-to" → "how", "sci-fi" → "sci"), so merely
	 * creating a translation rewrote the source term's canonical display slug
	 * and started 301-redirecting its live public URL. It also failed the
	 * inverse — regioned/longer slugs ("pt-br", "zh-hans") were never stripped.
	 * Anchoring to the real language slugs fixes both directions.
	 *
	 * @param string $slug Term slug, possibly language-suffixed.
	 * @return string Base slug (unchanged when no genuine language suffix).
	 */
	private function strip_language_suffix( string $slug ): string {
		$patterns = [];

		foreach ( $this->languages->get_active() as $lang ) {
			if ( ! empty( $lang->slug ) ) {
				$patterns[] = preg_quote( (string) $lang->slug, '#' );
			}
		}

		if ( $patterns === [] ) {
			return $slug;
		}

		$stripped = preg_replace( '#-(' . implode( '|', $patterns ) . ')$#', '', $slug );

		return ( is_string( $stripped ) && $stripped !== '' ) ? $stripped : $slug;
	}

	/**
	 * Shorten a slug base so that $reserve further characters still survive
	 * sanitize_title()'s SLUG_MAX_LENGTH cap.
	 *
	 * The cap is applied to the finished string, so a suffix appended to an
	 * over-long base is exactly the part that gets cut. Reserving the room up
	 * front is what keeps "<base>-<language>" distinct from "<base>", and what
	 * keeps force_insert_term()'s "-N" walk able to reach a slug it has not
	 * already tried.
	 *
	 * The cut itself is delegated to {@see \PerfLocale\Helper::truncate_slug()},
	 * which never lands inside a percent-escape. An earlier version cut with
	 * byte substr() and relied on sanitize_title() to "drop the orphaned %".
	 * It does drop the %, but it KEEPS the hex digits that followed, so the
	 * slug silently became different bytes rather than shorter ones: every
	 * Japanese, Chinese or Korean term name of 24 characters and up, and every
	 * Russian or Arabic one of 45 and up, was stored as mojibake that no later
	 * sanitising repaired.
	 *
	 * @param string $base    Sanitized slug base.
	 * @param int    $reserve Characters to keep free for the suffix.
	 * @return string Base shortened just enough; unchanged when it already fits
	 *                (or when $reserve leaves no room at all, which no caller does).
	 */
	private function reserve_slug_room( string $base, int $reserve ): string {
		$max = self::SLUG_MAX_LENGTH - $reserve;

		if ( $max < 1 ) {
			return $base;
		}

		return Helper::truncate_slug( $base, $max );
	}

	/**
	 * Create a translation of a term in a target language.
	 *
	 * @param int        $source_id Source term ID.
	 * @param string     $taxonomy Taxonomy slug.
	 * @param string     $target_slug Target language slug.
	 * @param bool       $copy_content Whether to copy name/description.
	 * @param SourceType $source Provenance tag for the new
	 *     translation_links row. Manual is the default for the metabox /
	 *     REST manual flows; auto-translate-on-save passes
	 *     MachineTranslation.
	 * @return int|false New term ID or false.
	 */
	public function create_translation(
		int $source_id,
		string $taxonomy,
		string $target_slug,
		bool $copy_content = false,
		SourceType $source = SourceType::Manual
	): int|false {
		$source_term = get_term( $source_id, $taxonomy );

		if ( ! $source_term instanceof \WP_Term ) {
			return false;
		}

		$target_lang = $this->languages->find_by_slug( $target_slug );

		if ( ! $target_lang ) {
			return false;
		}

		// Fast path: existing translation present. Skip the lock for
		// the common "translation already exists" case.
		$existing = $this->get_translation_id( $source_id, $target_slug );
		if ( $existing !== null ) {
			return $existing;
		}

		// Acquire a per-(source_id, target_slug) lock. Two concurrent
		// callers for the same target-language translation of the same
		// source term can otherwise both pass the existing-check, both
		// run `wp_insert_term()`, and either (a) WP's name-uniqueness
		// rejects the second insert (recoverable) OR (b) we fall back
		// to `force_insert_term()` which writes raw via $wpdb and would
		// create duplicate term rows.
		//
		// Lock key intentionally narrow — term IDs in wp_terms are
		// globally unique across taxonomies, so source_id is sufficient
		// to scope the contention; we still include target_slug so
		// different target-language translations of the same term don't
		// serialise against each other.
		/**
		 * @hook perflocale/translation/create_term_lock_ttl
		 *
		 * Lock TTL (seconds) for the create_translation() critical
		 * section in TermTranslationManager. Default 30 — terms have no
		 * meta/featured-image copy step so the inserts are quick.
		 *
		 * @param int $ttl Default 30.
		 */
		$lock_ttl = max( 5, (int) apply_filters( 'perflocale/translation/create_term_lock_ttl', 30 ) );

		$result = \PerfLocale\Concurrency\Lock::with(
			'create_xlat_term_' . $source_id . '_' . $target_slug,
			$lock_ttl,
			function () use ( $source_id, $taxonomy, $target_slug, $target_lang, $copy_content, $source, $source_term ): int|false {
				return $this->do_create_translation( $source_id, $taxonomy, $target_slug, $target_lang, $copy_content, $source, $source_term );
			}
		);

		if ( $result !== null ) {
			return $result;
		}

		// Lost the lock race — sibling worker is creating this
		// translation right now. Re-check; if it landed, return its ID.
		$existing_after = $this->get_translation_id( $source_id, $target_slug );
		if ( $existing_after !== null ) {
			return $existing_after;
		}

		return false;
	}

	/**
	 * Body of create_translation() executed under the
	 * per-(source_id, target_slug) lock. Re-checks existence inside the
	 * lock to close the TOCTOU between the public-method fast-path and
	 * lock acquisition.
	 *
	 * @param int        $source_id      Source term ID.
	 * @param string     $taxonomy       Taxonomy slug.
	 * @param string     $target_slug    Target language slug.
	 * @param object     $target_lang    Target language row (resolved by caller).
	 * @param bool       $copy_content   Whether to copy source description.
	 * @param SourceType $source         Provenance tag.
	 * @param \WP_Term   $source_term    Source term object (resolved by caller).
	 * @return int|false New term ID, existing if a sibling worker created
	 *                   it concurrently, or false on failure.
	 */
	private function do_create_translation(
		int $source_id,
		string $taxonomy,
		string $target_slug,
		object $target_lang,
		bool $copy_content,
		SourceType $source,
		\WP_Term $source_term
	): int|false {
		// Re-check existing INSIDE the lock. Another worker may have
		// created the translation between the public method's
		// fast-path check and us reaching here.
		$existing = $this->get_translation_id( $source_id, $target_slug );
		if ( $existing !== null ) {
			return $existing;
		}

		// Resolve the default-language term as the content source.
		$content_source = $this->resolve_source_term( $source_id, $taxonomy );

		if ( ! $content_source ) {
			return false;
		}

		$name      = $content_source->name;
		$base_slug = $content_source->slug;

		// Remove an existing language suffix from the base slug.
		$base_slug = $this->strip_language_suffix( $base_slug );

		// Reserve room for "-<language>" inside sanitize_title()'s cap instead of
		// appending and letting the cap cut. Appending first is what turns a
		// 199/200-character base into a slug identical to the source term's own
		// (the "-de" is shaved back to nothing), and a 198-character one into a
		// slug carrying a meaningless "-d". $base_slug itself stays full length:
		// it is the public display slug recorded via SlugManager below, and only
		// the internal wp_terms slug needs to fit.
		$slug = $this->reserve_slug_room( $base_slug, strlen( $target_slug ) + 1 ) . '-' . $target_slug;

		$args = [
			'slug' => $slug,
		];

		if ( $copy_content ) {
			// Description comes from the resolved default-language source, not
			// the caller-passed term, so name/slug/description all seed from the
			// same source (creating an FR term from a DE term's context must not
			// mix the EN name with the DE description).
			$args['description'] = $content_source->description;
		}

		// Handle hierarchical terms - translate parent if exists.
		if ( $source_term->parent > 0 ) {
			$parent_translation = $this->get_translation_id( $source_term->parent, $target_slug );

			if ( $parent_translation !== null ) {
				$args['parent'] = $parent_translation;
			}
		}

		// wp_insert_term() unslashes name + description internally, so it
		// needs SLASHED input; the term name/description come UNSLASHED from
		// get_term(). Without wp_slash a backslash in a term name/description
		// is silently stripped in the translation sibling. The force_insert_term
		// fallback below writes via raw $wpdb->insert (no unslash) and MUST keep
		// the raw values — so slash only for this call.
		$slashed_args                = $args;
		$slashed_args['description'] = isset( $args['description'] ) ? wp_slash( $args['description'] ) : null;
		if ( $slashed_args['description'] === null ) {
			unset( $slashed_args['description'] );
		}

		$result = wp_insert_term( wp_slash( $name ), $taxonomy, $slashed_args );

		if ( is_wp_error( $result ) ) {
			// Name collision - force-insert via wpdb to create a truly separate
			// term. Raw $wpdb->insert does NOT unslash, so pass the ORIGINAL
			// unslashed $name / $args (never the slashed copy).
			$new_term_id = $this->force_insert_term( $name, $taxonomy, $slug, $args );

			if ( ! $new_term_id ) {
				return false;
			}
		} else {
			$new_term_id = $result['term_id'];
		}

		// Link terms. A failure here would leave the freshly-created term as an
		// orphan (a wp_terms row that's in no translation group), and we'd
		// wrongly report success. Roll the term back and fail — mirrors
		// PostTranslationManager::do_create_translation()'s link-failure path.
		if ( ! $this->link_terms( $source_id, $new_term_id, $target_slug, $source ) ) {
			wp_delete_term( $new_term_id, $taxonomy );
			return false;
		}

		// Record the display slug so translated URLs resolve correctly.
		// The DB slug is "base-lang" (e.g. uncategorized-de) but the URL
		// should use the base slug (e.g. uncategorized).
		$slug_manager = new \PerfLocale\Router\SlugManager( $this->cache );
		$slug_manager->set_slug( 'term', $taxonomy, $new_term_id, (int) $target_lang->id, $base_slug );

		// Also ensure the source term has a slug translation entry.
		$source_lang = $this->detect_term_language( $source_id );

		if ( $source_lang ) {
			$source_term_obj = get_term( $source_id, $taxonomy );

			if ( $source_term_obj instanceof \WP_Term ) {
				$source_base = $this->strip_language_suffix( $source_term_obj->slug );
				$slug_manager->set_slug( 'term', $taxonomy, $source_id, (int) $source_lang->id, $source_base );
			}
		}

		/**
		 * Fires after a translation term is created.
		 *
		 * @hook perflocale/translation/created
		 *
		 * @param int $new_id Newly-created term ID.
		 * @param string $type Object type ('post' or 'term').
		 * @param string $target_slug Target language slug.
		 * @param int $source_id Source term ID the translation was created from.
		 */
		do_action( 'perflocale/translation/created', $new_term_id, 'term', $target_slug, $source_id );

		return $new_term_id;
	}

	/**
	 * Link two terms as translations of each other.
	 *
	 * @param int        $source_id Source term ID.
	 * @param int        $target_id Target term ID.
	 * @param string     $target_slug Target language slug.
	 * @param SourceType $source Provenance tag for the new link.
	 * @return bool
	 */
	public function link_terms( int $source_id, int $target_id, string $target_slug, SourceType $source = SourceType::Manual ): bool {
		$target_lang = $this->languages->find_by_slug( $target_slug );

		if ( ! $target_lang ) {
			return false;
		}

		$group = $this->groups->find_for_object( $source_id, ObjectType::Term );

		if ( $group ) {
			$result = $this->groups->link_object(
				(int) $group->id,
				$target_id,
				(int) $target_lang->id,
				TranslationStatus::Published->value,
				$source
			);

			return $result !== false;
		}

		// No group exists yet — same create-first-group race
		// PostTranslationManager has. Two concurrent term saves can both
		// observe `$group === null`, both call `create_group()`, both
		// succeed (translation_groups has no UNIQUE on (type, object_id)),
		// leaving the source term in two groups. Serialise with the
		// token-guarded Lock primitive — keyed by source_id only (term IDs
		// are globally unique across taxonomies in wp_terms).
		$lock_result = \PerfLocale\Concurrency\Lock::with(
			'link_term_first_group_' . $source_id,
			30,
			function () use ( $source_id, $target_id, $target_lang, $source ): bool {
				// Re-read inside the lock — another worker may have
				// committed the group between the top-of-method
				// find_for_object() and us acquiring the lock. The
				// top-of-method call memoized its null in the request-local
				// $find_cache; without dropping that entry the re-read
				// returns the same stale null and never observes a group a
				// concurrent PHP process just committed — defeating the guard
				// and creating a second group for this term.
				$this->groups->invalidate_find_cache( $source_id, ObjectType::Term );
				$double_check = $this->groups->find_for_object( $source_id, ObjectType::Term );
				if ( $double_check ) {
					$result = $this->groups->link_object(
						(int) $double_check->id,
						$target_id,
						(int) $target_lang->id,
						TranslationStatus::Published->value,
						$source
					);
					return $result !== false;
				}

				// True first translation — create the group with the source
				// row, then link the target.
				$source_lang = $this->languages->get_default();

				if ( ! $source_lang ) {
					return false;
				}

				$group_id = $this->groups->create_group(
					ObjectType::Term,
					$source_id,
					(int) $source_lang->id,
					TranslationStatus::Published->value
				);

				if ( $group_id === false ) {
					return false;
				}

				$result = $this->groups->link_object(
					$group_id,
					$target_id,
					(int) $target_lang->id,
					TranslationStatus::Published->value,
					$source
				);

				return $result !== false;
			}
		);

		if ( $lock_result !== null ) {
			return $lock_result;
		}

		// Lost the lock race — sibling worker is creating the first
		// group right now. Re-read once more: if it landed, link the
		// target through the existing-group branch. Drop the memoized null
		// first so the re-read reflects the sibling's committed group.
		$this->groups->invalidate_find_cache( $source_id, ObjectType::Term );
		$retry_group = $this->groups->find_for_object( $source_id, ObjectType::Term );
		if ( ! $retry_group ) {
			return false;
		}

		$result = $this->groups->link_object(
			(int) $retry_group->id,
			$target_id,
			(int) $target_lang->id,
			TranslationStatus::Published->value,
			$source
		);
		return $result !== false;
	}

	/**
	 * Get all translations of a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, int> language_slug => term_id map.
	 */
	public function get_translations( int $term_id ): array {
		$links = $this->groups->get_translations( $term_id, ObjectType::Term );
		$map   = [];

		foreach ( $links as $link ) {
			if ( isset( $link->language_slug ) ) {
				$map[ $link->language_slug ] = (int) $link->object_id;
			}
		}

		return $map;
	}

	/**
	 * Get the translated term ID for a specific language.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $lang_slug Target language slug.
	 * @return int|null
	 */
	public function get_translation_id( int $term_id, string $lang_slug ): ?int {
		$translations = $this->get_translations( $term_id );

		return $translations[ $lang_slug ] ?? null;
	}

	/**
	 * Resolve the default-language term as the content source.
	 *
	 * @param int    $source_id Source term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return \WP_Term|null The default-language term, or null if not found.
	 */
	private function resolve_source_term( int $source_id, string $taxonomy ): ?\WP_Term {
		$default_lang = $this->languages->get_default();

		if ( ! $default_lang ) {
			$term = get_term( $source_id, $taxonomy );

			return ( $term instanceof \WP_Term ) ? $term : null;
		}

		$translations = $this->get_translations( $source_id );
		$default_id   = $translations[ $default_lang->slug ] ?? null;

		if ( $default_id && $default_id !== $source_id ) {
			$default_term = get_term( $default_id, $taxonomy );

			if ( $default_term instanceof \WP_Term ) {
				return $default_term;
			}
		}

		$term = get_term( $source_id, $taxonomy );

		return ( $term instanceof \WP_Term ) ? $term : null;
	}

	/**
	 * Resolve the translation-group id a term belongs to, or null if unlinked.
	 *
	 * Used by bulk operations that need to check "is this source term already
	 * translated to language X" via a pre-built group_id → lang → term_id map,
	 * without issuing an N+1 query per iteration.
	 *
	 * @param int $term_id Term ID.
	 * @return int|null Group ID or null.
	 */
	public function get_group_id_for_term( int $term_id ): ?int {
		$group = $this->groups->find_for_object( $term_id, ObjectType::Term );

		return $group ? (int) $group->id : null;
	}

	public function detect_term_language( int $term_id ): ?object {
		$links = $this->groups->get_translations( $term_id, ObjectType::Term );

		foreach ( $links as $link ) {
			if ( (int) $link->object_id === $term_id && isset( $link->language_slug ) ) {
				return $this->languages->find_by_slug( $link->language_slug );
			}
		}

		return null;
	}

	/**
	 * Set the language for a term (creating a group if needed).
	 *
	 * @param int    $term_id Term ID.
	 * @param string $lang_slug Language slug.
	 * @return bool
	 */
	public function set_term_language( int $term_id, string $lang_slug ): bool {
		$lang = $this->languages->find_by_slug( $lang_slug );

		if ( ! $lang ) {
			return false;
		}

		$group = $this->groups->find_for_object( $term_id, ObjectType::Term );

		if ( $group ) {
			global $wpdb;

			$links_table = \PerfLocale\Database\Schema::table( 'translation_links' );

			// Check if another term in this group already uses the target language.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$conflict = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT object_id FROM %i
					WHERE group_id = %d AND language_id = %d AND object_id != %d
					LIMIT 1',
					$links_table,
					(int) $group->id,
					(int) $lang->id,
					$term_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $wpdb->last_error ) {
				return false;
			}

			if ( $conflict ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$links_table,
				[ 'language_id' => (int) $lang->id ],
				[
					'group_id'  => (int) $group->id,
					'object_id' => $term_id,
				],
				[ '%d' ],
				[ '%d', '%d' ]
			);

			// Same return-value gauntlet as PostTranslationManager::set_post_language.
			// $wpdb->update can return:
			// false  → DB error (UNIQUE collision from a concurrent writer
			// grabbing the same (group_id, language_id) slot in
			// our TOCTOU window).
			// 0      → either (a) row was deleted between the conflict-
			// check SELECT and this UPDATE, OR (b) the row's
			// language_id was already this value (idempotent
			// no-op — MySQL reports affected_rows as CHANGED,
			// not matched).
			// >0     → row updated successfully.
			// Previously the call ignored the return value and unconditionally
			// returned true, surfacing silent "language change confirmed" in
			// the admin UI even when nothing landed.
			if ( $updated === false ) {
				return false;
			}

			if ( $updated === 0 ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$current_lang = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT language_id FROM %i WHERE group_id = %d AND object_id = %d LIMIT 1',
						$links_table,
						(int) $group->id,
						$term_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( $current_lang === null || (int) $current_lang !== (int) $lang->id ) {
					return false;
				}
				// Else: idempotent — already at target language. Fall through.
			}

			$this->groups->invalidate_group_cache( (int) $group->id );

			return true;
		}

		$group_id = $this->groups->create_group(
			ObjectType::Term,
			$term_id,
			(int) $lang->id,
			TranslationStatus::Published->value
		);

		return $group_id !== false;
	}

	/**
	 * Force-insert a term bypassing WordPress name uniqueness checks.
	 *
	 * WordPress prevents two terms with the same name in a taxonomy.
	 * For multilingual sites, each language needs its own term with the
	 * same visible name but a language-suffixed slug.
	 *
	 * @param string $name Term name.
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $slug Unique slug (e.g. "test-fr").
	 * @param array<string, mixed> $args Additional args (parent, description).
	 * @return int|false Term ID or false.
	 */
	/**
	 * Backfill slug translations for all existing term translation groups.
	 *
	 * For each translation group, determines the base slug (from the
	 * default-language term, stripping language suffixes) and records it
	 * as the display slug for every term in the group.
	 *
	 * Safe to call multiple times - set_slug() uses INSERT ... ON DUPLICATE KEY UPDATE.
	 *
	 * @return int Number of slug translations created.
	 */
	public function backfill_slug_translations(): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );
		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );

		// Fetch all term translation groups with their linked terms.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id AS group_id, l.object_id AS term_id, l.language_id
				FROM %i g
				INNER JOIN %i l ON l.group_id = g.id
				WHERE g.type = 'term'
				ORDER BY g.id",
				$groups_table,
				$links_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $rows ) ) {
			return 0;
		}

		// Group by translation group.
		$groups = [];

		foreach ( $rows as $row ) {
			$groups[ (int) $row->group_id ][] = $row;
		}

		$default_lang = $this->languages->get_default();
		$default_id   = $default_lang ? (int) $default_lang->id : 0;
		$slug_manager = new \PerfLocale\Router\SlugManager( $this->cache );
		$count        = 0;

		foreach ( $groups as $members ) {
			// Find the base slug from the default-language term in this group.
			$base_slug = null;

			// First, try the default-language term.
			foreach ( $members as $m ) {
				if ( (int) $m->language_id === $default_id ) {
					$term = get_term( (int) $m->term_id );

					if ( $term instanceof \WP_Term ) {
						// Strip language suffix if present (e.g. tutorials-fr → tutorials).
						$base_slug = $this->strip_language_suffix( $term->slug );
						break;
					}
				}
			}

			// Fallback: use any term's slug with the language suffix stripped.
			if ( $base_slug === null ) {
				$first_term = get_term( (int) $members[0]->term_id );

				if ( $first_term instanceof \WP_Term ) {
					$base_slug = $this->strip_language_suffix( $first_term->slug );
				}
			}

			if ( $base_slug === null ) {
				continue;
			}

			// Set slug translation for every term in the group. Look up
			// each term's taxonomy (the slug-translations subtype) at the
			// callsite — different members of the same translation group
			// always share the taxonomy in practice, but resolving per
			// member keeps us correct if that invariant ever changes.
			foreach ( $members as $m ) {
				$term_obj = get_term( (int) $m->term_id );
				$tax      = ( $term_obj instanceof \WP_Term ) ? $term_obj->taxonomy : '';

				if ( $tax === '' ) {
					continue;
				}

				// Count writes that actually landed. set_slug() returns false on a
				// genuine failure (and reports it via perflocale/slug/write_failed);
				// counting those would tell the operator N slugs were backfilled
				// when fewer were.
				if ( $slug_manager->set_slug( 'term', $tax, (int) $m->term_id, (int) $m->language_id, $base_slug ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Force-insert a term bypassing WordPress name uniqueness checks.
	 *
	 * WordPress prevents two terms with the same name in a taxonomy.
	 * For multilingual sites, each language needs its own term with the
	 * same visible name but a language-suffixed slug.
	 *
	 * @param string               $name Term name.
	 * @param string               $taxonomy Taxonomy slug.
	 * @param string               $slug Unique slug (e.g. "test-fr").
	 * @param array<string, mixed> $args Additional args (parent, description).
	 * @return int|false Term ID or false.
	 */
	private function force_insert_term( string $name, string $taxonomy, string $slug, array $args ): int|false {
		global $wpdb;

		// Dedupe the slug before inserting. This fallback runs precisely when
		// wp_insert_term() rejected with 'term_exists', and create_translation()
		// always supplies an explicit slug — core only errors the slug-provided
		// path when a term with that exact slug already exists. Inserting the
		// same slug verbatim would leave two wp_terms rows sharing it (wp_terms
		// has no unique slug index), and get_term_by('slug') / URL resolution
		// would then deterministically resolve to the pre-existing term, leaving
		// this new translation's archive unreachable. Walk to the first free
		// "-N" suffix, mirroring wp_unique_term_slug()'s intent.
		//
		// Both halves of that walk are load-bearing. get_term_by( 'slug', ... )
		// hands the needle to WP_Term_Query, which sanitize_title()s it — so a
		// $base already at SLUG_MAX_LENGTH makes every "$base-N" candidate come
		// back as $base itself, the same pre-existing term matches forever and
		// the loop never terminates: a 100% CPU spin holding
		// create_translation()'s lock until max_execution_time, which the CLI
		// SAPI leaves unlimited. Reserving room for the suffix keeps each
		// candidate distinct; the bound keeps the walk finite regardless.
		$base      = $this->reserve_slug_room( sanitize_title( $slug ), strlen( (string) self::MAX_SLUG_SUFFIX ) + 1 );
		$candidate = $base;
		$suffix    = 2;

		while ( $candidate !== '' && get_term_by( 'slug', $candidate, $taxonomy ) ) {
			if ( $suffix > self::MAX_SLUG_SUFFIX ) {
				// Every suffix in range is taken. Fail cleanly rather than walk
				// on: do_create_translation() turns this false into its own
				// false, which the REST and admin callers report to the user.
				// The bulk paths only count it as "skipped", hence the log.
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on an otherwise silent slug-exhaustion failure.
					error_log(
						sprintf(
							'[perflocale] force_insert_term: no free slug in taxonomy %s after %d suffixes on base "%s"',
							$taxonomy,
							self::MAX_SLUG_SUFFIX,
							$base
						)
					);
				}

				return false;
			}

			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		// Insert into wp_terms.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$wpdb->terms,
			[
				'name'       => $name,
				'slug'       => $candidate,
				'term_group' => 0,
			],
			[ '%s', '%s', '%d' ]
		);

		if ( $result === false ) {
			return false;
		}

		$term_id = (int) $wpdb->insert_id;

		// Insert into wp_term_taxonomy.
		$parent = absint( $args['parent'] ?? 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$wpdb->term_taxonomy,
			[
				'term_id'     => $term_id,
				'taxonomy'    => $taxonomy,
				'description' => $args['description'] ?? '',
				'parent'      => $parent,
				'count'       => 0,
			],
			[ '%d', '%s', '%s', '%d', '%d' ]
		);

		if ( $result === false ) {
			// Clean up the orphan term.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $wpdb->terms, [ 'term_id' => $term_id ], [ '%d' ] );
			return false;
		}

		// Clear term caches so WordPress picks up the new term.
		clean_term_cache( $term_id, $taxonomy );

		return $term_id;
	}
}
