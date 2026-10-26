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
 * Creates and links term translations (PostTranslationManager's group model, for taxonomy terms).
 */
final class TermTranslationManager {

	/**
	 * Longest slug sanitize_title() hands back (utf8_uri_encode( $title, 200 )).
	 *
	 * The cap cuts a "<base>-<suffix>" slug's suffix first, so room is reserved before appending.
	 */
	private const SLUG_MAX_LENGTH = 200;

	/**
	 * Highest "-N" suffix force_insert_term() walks to before giving up.
	 */
	private const MAX_SLUG_SUFFIX = 999;

	/**
	 * Request age (s) after which adoption stops on a web request: margin under a 30 s limit (no limit under WP-CLI).
	 */
	private const ADOPT_SECONDS = 20;

	/**
	 * Moves every adoption may make whatever the request's age, so a late create still adopts a small family.
	 */
	private const ADOPT_MIN_MOVES = 25;

	/**
	 * Seconds every adoption may run from its own start (see ADOPT_MIN_MOVES).
	 */
	private const ADOPT_MIN_SECONDS = 2;

	/**
	 * Source children read per query during adoption.
	 */
	private const ADOPT_PAGE = 500;

	/**
	 * Values of translation_links.source written by the importers.
	 *
	 * @var list<string>
	 */
	private const IMPORTED_SOURCES = [ 'imported_wpml', 'imported_polylang', 'imported_trp' ];

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
	 * Hierarchy served to core per blog and taxonomy while an adoption moves terms: two rebuilds per adoption, not
	 * one per move.
	 *
	 * @var array<int, array<string, array<int, array<int>>>>
	 */
	private array $hierarchy = [];

	/**
	 * Term set_parent() is updating; another term's edit makes the served hierarchy stale.
	 */
	private int $moving = 0;

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
	 * Strip a trailing "-<language-slug>" from a term slug, only when it is an active language's slug.
	 *
	 * A generic `-[a-z]{2,3}` match would strip words ("green-tea") and miss "pt-br", moving public URLs.
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
	 * Shorten a slug base so that $reserve further characters survive the SLUG_MAX_LENGTH cap.
	 *
	 * Cuts with Helper::truncate_slug(), never inside a percent-escape: sanitize_title() drops an orphaned
	 * `%` but keeps its hex digits, which stores multibyte names as mojibake.
	 *
	 * @param string $base    Sanitized slug base.
	 * @param int    $reserve Characters to keep free for the suffix.
	 * @return string Base shortened just enough; unchanged when it fits or $reserve leaves no room.
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
	 * @param SourceType $source Provenance tag for the new link (auto-translate passes MachineTranslation).
	 * @param bool|null  $created Set to true when this call inserted the translation it returns. Check it
	 *     before writing into the term: one this call did not create can be another author's.
	 * @return int|false Term ID of the new or already-linked translation (maybe another process's); false on
	 *     failure, or when the creation lock is held elsewhere and no linked translation is known.
	 */
	public function create_translation(
		int $source_id,
		string $taxonomy,
		string $target_slug,
		bool $copy_content = false,
		SourceType $source = SourceType::Manual,
		?bool &$created = null
	): int|false {
		$created     = false;
		$source_term = get_term( $source_id, $taxonomy );

		if ( ! $source_term instanceof \WP_Term ) {
			return false;
		}

		$target_lang = $this->languages->find_by_slug( $target_slug );

		if ( ! $target_lang ) {
			return false;
		}

		// Fast path: an existing translation needs no lock.
		$existing = $this->get_translation_id( $source_id, $target_slug );
		if ( $existing !== null ) {
			return $existing;
		}

		// Lock per (source_id, target_slug), not per group: two callers could both pass the check and
		// force_insert_term() would write duplicate rows. Term ids are unique across taxonomies.
		/**
		 * @hook perflocale/translation/create_term_lock_ttl
		 *
		 * Lock TTL (seconds) of term create_translation(); default 30 (terms have no meta or image copy).
		 *
		 * @param int $ttl Default 30.
		 */
		$lock_ttl = max( 5, (int) apply_filters( 'perflocale/translation/create_term_lock_ttl', 30 ) );

		return PostTranslationManager::create_under_lock(
			'create_xlat_term_' . $source_id . '_' . $target_slug,
			$lock_ttl,
			function () use ( $source_id, $taxonomy, $target_slug, $target_lang, $copy_content, $source, $source_term, &$created ): int|false {
				return $this->do_create_translation( $source_id, $taxonomy, $target_slug, $target_lang, $copy_content, $source, $source_term, $created );
			},
			function () use ( $source_id, $target_slug ): int|false {
				return $this->get_translation_id( $source_id, $target_slug ) ?? false;
			}
		);
	}

	/**
	 * Body of create_translation() under its lock: re-checks existence in the database before any insert.
	 *
	 * @param int        $source_id      Source term ID.
	 * @param string     $taxonomy       Taxonomy slug.
	 * @param string     $target_slug    Target language slug.
	 * @param object     $target_lang    Target language row (resolved by caller).
	 * @param bool       $copy_content   Whether to copy source description.
	 * @param SourceType $source         Provenance tag.
	 * @param \WP_Term   $source_term    Source term object (resolved by caller).
	 * @param bool       $inserted       Set to true once a new term is inserted and linked.
	 * @return int|false New term ID, a term already linked in the target language (maybe another
	 *                   process's), or false on failure or no answer from the existence query.
	 */
	private function do_create_translation(
		int $source_id,
		string $taxonomy,
		string $target_slug,
		object $target_lang,
		bool $copy_content,
		SourceType $source,
		\WP_Term $source_term,
		bool &$inserted
	): int|false {
		// Re-check in the database, never get_translation_id(): its memo predates the lock and misses another
		// process's link, and a second term would take that language's slot. No answer: false, never an insert.
		$language_id = (int) $target_lang->id;
		$linked      = $this->groups->find_translation_link_uncached( $source_id, ObjectType::Term, $language_id );

		if ( $linked === false ) {
			return false;
		}

		if ( $linked !== null ) {
			// Later reads in this process must not answer from the view that missed it.
			if ( $this->get_translation_id( $source_id, $target_slug ) !== $linked['object_id'] ) {
				$this->groups->forget_stale_group( $linked['group_id'], ObjectType::Term );
			}

			return $linked['object_id'];
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

		// Reserve room for "-<language>" before appending: the cap would cut the suffix and leave the source's slug.
		// $base_slug stays full length: it is the display slug recorded below; only the wp_terms slug must fit.
		$slug = $this->reserve_slug_room( $base_slug, strlen( $target_slug ) + 1 ) . '-' . $target_slug;

		$args = [
			'slug' => $slug,
		];

		if ( $copy_content ) {
			// From the resolved default-language source, so name, slug and description never mix two languages.
			$args['description'] = $content_source->description;
		}

		// Handle hierarchical terms - translate parent if exists.
		if ( $source_term->parent > 0 ) {
			$parent_translation = $this->parent_translation( (int) $source_term->parent, $language_id, $target_slug, $taxonomy, true );

			if ( $parent_translation > 0 ) {
				$args['parent'] = $parent_translation;
			}
		}

		// wp_insert_term() unslashes name and description, get_term() values are unslashed: slash for this call
		// only. The force_insert_term() fallback writes raw with $wpdb->insert() and must keep the raw values.
		$slashed_args                = $args;
		$slashed_args['description'] = isset( $args['description'] ) ? wp_slash( $args['description'] ) : null;
		if ( $slashed_args['description'] === null ) {
			unset( $slashed_args['description'] );
		}

		$result = wp_insert_term( wp_slash( $name ), $taxonomy, $slashed_args );

		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();

			// Only term_exists and db_insert_error with the slug taken (wp_unique_term_slug() overran the column) mean
			// a taken slug; any other error is WordPress or a filter refusing the term, never bypassed. Logged: the bulk
			// and post-copy callers only count a false.
			$slug_overflow = 'db_insert_error' === $code && get_term_by( 'slug', $slug, $taxonomy ) instanceof \WP_Term;

			if ( 'term_exists' !== $code && ! $slug_overflow ) {
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on a failure the bulk and post-copy paths do not report.
					error_log(
						sprintf(
							'[perflocale] create_translation: wp_insert_term() refused the %s translation of term %d in taxonomy %s (%s)',
							$target_slug,
							$source_id,
							$taxonomy,
							$code
						)
					);
				}

				return false;
			}

			// Slug taken: force-insert under the first free "-N" slug, with the unslashed $name and $args.
			$new_term_id = $this->force_insert_term( $name, $taxonomy, $slug, $args );

			if ( ! $new_term_id ) {
				return false;
			}
		} else {
			$new_term_id = $result['term_id'];
		}

		// A failed link would leave an orphan term reported as created: roll it back and fail.
		if ( ! $this->link_terms( $source_id, $new_term_id, $target_slug, $source ) ) {
			wp_delete_term( $new_term_id, $taxonomy );
			return false;
		}

		$inserted = true;

		// Read the parent again once this term is linked: of two racing processes, one sees the other's link.
		if ( $source_term->parent > 0 && ! isset( $args['parent'] ) ) {
			$late_parent = $this->parent_translation( (int) $source_term->parent, $language_id, $target_slug, $taxonomy, false );

			if ( $late_parent > 0 ) {
				$this->set_parent( $new_term_id, $taxonomy, $late_parent );
			}
		}

		// Record the display slug: the DB slug is "base-lang" (uncategorized-de), the URL uses the base.
		$slug_manager = new \PerfLocale\Router\SlugManager( $this->cache );
		$slug_manager->set_slug( 'term', $taxonomy, $new_term_id, $language_id, $base_slug );

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

		// Last: a request killed during a long adoption still leaves a complete translation.
		$this->adopt_child_translations( $new_term_id, $taxonomy, $language_id, $content_source );

		return $new_term_id;
	}

	/**
	 * The parent's translation, read uncached (another process may have linked it); 0 when none,
	 * gone or in another taxonomy, since a link can outlive its term and wp_insert_term() refuses it.
	 *
	 * @param int    $parent_id   Source term's parent.
	 * @param int    $language_id Target language id.
	 * @param string $target_slug Target language slug.
	 * @param string $taxonomy    Taxonomy slug.
	 * @param bool   $cached_when_unanswered Use the cached lookup when the database gives no answer.
	 * @return int Term id of the parent's translation, or 0.
	 */
	private function parent_translation( int $parent_id, int $language_id, string $target_slug, string $taxonomy, bool $cached_when_unanswered ): int {
		$linked = $this->groups->find_translation_link_uncached( $parent_id, ObjectType::Term, $language_id );

		if ( is_array( $linked ) ) {
			$parent_translation = $linked['object_id'];
		} elseif ( false === $linked && $cached_when_unanswered ) {
			$parent_translation = (int) $this->get_translation_id( $parent_id, $target_slug );
		} else {
			$parent_translation = 0;
		}

		if ( $parent_translation <= 0 || ! get_term( $parent_translation, $taxonomy ) instanceof \WP_Term ) {
			return 0;
		}

		return $parent_translation;
	}

	/**
	 * Move the top-level translations of the source term's children under the new term.
	 *
	 * Follows the source hierarchy only, as ContentSync::sync_on_term_edit(); off with `sync_term_hierarchy`.
	 *
	 * @param int      $new_term_id    Translation just created and linked.
	 * @param string   $taxonomy       Taxonomy slug.
	 * @param int      $language_id    Its language id.
	 * @param \WP_Term $content_source The term it was copied from.
	 * @return void
	 */
	private function adopt_child_translations( int $new_term_id, string $taxonomy, int $language_id, \WP_Term $content_source ): void {
		if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'settings' ) && ! (bool) $plugin->settings()->get( 'sync_term_hierarchy', true ) ) {
			return;
		}

		$entered  = microtime( true );
		$started  = is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ?? null ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $entered; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- A number set by PHP, checked above.
		$deadline = 'cli' === php_sapi_name() ? 0.0 : max( $started + self::ADOPT_SECONDS, $entered + self::ADOPT_MIN_SECONDS );
		$parent   = (int) $content_source->term_id;
		$page     = self::source_children( $parent, $taxonomy, 0 );

		if ( [] === $page ) {
			return;
		}

		$source_language = $this->detect_term_language( $parent );
		$source_vars     = is_object( $source_language ) ? get_object_vars( $source_language ) : [];

		if ( ! is_numeric( $source_vars['id'] ?? null ) ) {
			return;
		}

		$languages = [ (int) $source_vars['id'], $language_id ];
		$moved     = 0;
		$checked   = 0;
		$cut       = false;
		$blog      = get_current_blog_id();
		$answered  = false;
		$serve     = function ( mixed $pre ) use ( $taxonomy, $blog, &$answered ): mixed {
			if ( get_current_blog_id() !== $blog || ! isset( $this->hierarchy[ $blog ][ $taxonomy ] ) ) {
				return $pre;
			}

			$answered = true;

			return $this->hierarchy[ $blog ][ $taxonomy ];
		};
		$watch     = function ( mixed $term_id, mixed $tt_id = 0, mixed $tax = '' ) use ( $taxonomy, $blog ): void {
			if ( $tax === $taxonomy && get_current_blog_id() === $blog && ! ( is_numeric( $term_id ) && (int) $term_id === $this->moving ) ) {
				unset( $this->hierarchy[ $blog ][ $taxonomy ] );
			}
		};
		$gone      = function ( mixed $term_id, mixed $tax = '' ) use ( $taxonomy, $blog ): void {
			if ( $tax === $taxonomy && get_current_blog_id() === $blog ) {
				unset( $this->hierarchy[ $blog ][ $taxonomy ] );
			}
		};
		$placed    = function ( mixed $parent_id, mixed $term_id = 0, mixed $tax = '' ) use ( $taxonomy, $blog, $new_term_id ): mixed {
			if ( $tax === $taxonomy && get_current_blog_id() === $blog && is_numeric( $term_id ) && (int) $term_id === $this->moving && ! ( is_numeric( $parent_id ) && (int) $parent_id === $new_term_id ) ) {
				unset( $this->hierarchy[ $blog ][ $taxonomy ] );
			}

			return $parent_id;
		};

		// Core reads this copy of the hierarchy while terms move (this blog only). Another term's edit, a create or a
		// delete drops it, and so does a parent filter that puts the moving term elsewhere.
		add_filter( "pre_option_{$taxonomy}_children", $serve );
		add_action( 'edit_term', $watch, 10, 3 );
		add_action( 'create_term', $watch, 10, 3 );
		add_action( 'pre_delete_term', $gone, 10, 2 );
		add_filter( 'wp_update_term_parent', $placed, PHP_INT_MAX, 3 );

		try {
			// One lookup per page, each made only when there is time to move what it finds.
			while ( [] !== $page ) {
				$waiting = self::top_level_translations( array_values( $page ), $languages, $taxonomy, $parent, $new_term_id );

				if ( false === $waiting ) {
					return;
				}

				foreach ( $page as $child_id ) {
					$translation_id = $waiting[ $child_id ] ?? 0;

					if ( $translation_id > 0 && $translation_id !== $new_term_id ) {
						if ( self::out_of_time( $deadline, $moved ) ) {
							$cut = true;
							break 2;
						}

						$moved += $this->adopt_child( $child_id, $translation_id, $taxonomy, $new_term_id, $languages, $parent ) ? 1 : 0;
					}

					++$checked;
				}

				if ( count( $page ) < self::ADOPT_PAGE ) {
					break;
				}

				if ( self::out_of_time( $deadline, $moved ) ) {
					$cut = true;
					break;
				}

				$page = self::source_children( $parent, $taxonomy, (int) array_key_last( $page ) );
			}
		} finally {
			remove_filter( "pre_option_{$taxonomy}_children", $serve );
			remove_action( 'edit_term', $watch, 10 );
			remove_action( 'create_term', $watch, 10 );
			remove_action( 'pre_delete_term', $gone, 10 );
			remove_filter( 'wp_update_term_parent', $placed, PHP_INT_MAX );
			unset( $this->hierarchy[ $blog ][ $taxonomy ] );
			$this->moving = 0;

			// A served answer left the stored hierarchy deleted: rebuild it once, unless the caller suspended invalidation.
			if ( $answered && empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) {
				clean_taxonomy_cache( $taxonomy );
			}
		}

		$total = $cut ? self::count_children( $parent, $taxonomy ) : 0;

		// The rest stay at the top level: nothing resumes a cut-off adoption, the log says what moves them.
		if ( $total > $checked ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the work left is not reported anywhere else.
			error_log( sprintf( '[perflocale] create_translation: %d of %d source child terms were not checked for a translation to move under term %d in taxonomy %s (request time limit); their translations stay at the top level until set by hand, or until each source child term in the default language is saved while Sync Fields is not empty', $total - $checked, $total, $new_term_id, $taxonomy ) );
		}
	}

	/**
	 * Move one waiting translation if it, its source child and their link are still as the lookup found them.
	 *
	 * @param int        $child_id       Source child.
	 * @param int        $translation_id Its top-level translation.
	 * @param string     $taxonomy       Taxonomy slug.
	 * @param int        $new_term_id    New parent.
	 * @param array<int> $languages      Source language id, then the translation's language id.
	 * @param int        $parent_id      Source term the child must still be under.
	 * @return bool Whether it was moved.
	 */
	private function adopt_child( int $child_id, int $translation_id, string $taxonomy, int $new_term_id, array $languages, int $parent_id ): bool {
		$child = get_term( $child_id, $taxonomy );

		if ( ! $child instanceof \WP_Term ) {
			return false;
		}

		/** This filter is documented in src/Translation/ContentSync.php */
		if ( ! (bool) apply_filters( 'perflocale/sync/term_parent', true, $child, $translation_id, $taxonomy, $new_term_id ) ) {
			return false;
		}

		// Read again just before the move: another request may have moved or unlinked either term meanwhile.
		// No row lock on purpose: see docs-ai/11-code-rationale.md, "TermTranslationManager: child adoption".
		$current = self::top_level_translations( [ $child_id ], $languages, $taxonomy, $parent_id, $new_term_id );

		if ( ! is_array( $current ) || ( $current[ $child_id ] ?? 0 ) !== $translation_id ) {
			return false;
		}

		// Served before the write: edited_term listeners read the hierarchy with this move in it.
		$blog   = get_current_blog_id();
		$served = isset( $this->hierarchy[ $blog ][ $taxonomy ] );

		if ( $served ) {
			$this->hierarchy[ $blog ][ $taxonomy ][ $new_term_id ][] = $translation_id;
		}

		// Not batched: wp_update_term() cleans the caches before edited_term, so listeners read the new parent.
		if ( $this->set_parent( $translation_id, $taxonomy, $new_term_id ) ) {
			// Core just rebuilt and stored the hierarchy: serve that copy from the next move on.
			if ( ! $served ) {
				$this->take_hierarchy( $taxonomy );
			}

			return true;
		}

		if ( $served ) {
			$this->drop_last_child( $taxonomy, $new_term_id );
		}

		return false;
	}

	/**
	 * Copy the hierarchy core just stored; adoption serves it to core for the remaining moves.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	private function take_hierarchy( string $taxonomy ): void {
		// Suspended invalidation: core did not rebuild, so the stored hierarchy can be old.
		if ( ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) {
			return;
		}

		$stored = get_option( "{$taxonomy}_children" );

		if ( ! is_array( $stored ) ) {
			return;
		}

		$copy = [];

		foreach ( $stored as $parent => $children ) {
			if ( is_int( $parent ) && is_array( $children ) ) {
				$copy[ $parent ] = array_map( static fn( mixed $id ): int => is_numeric( $id ) ? (int) $id : 0, array_values( $children ) );
			}
		}

		$this->hierarchy[ get_current_blog_id() ][ $taxonomy ] = $copy;
	}

	/**
	 * Take back the child appended for a move that was not made.
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @param int    $parent_id Parent it was appended under.
	 * @return void
	 */
	private function drop_last_child( string $taxonomy, int $parent_id ): void {
		$blog = get_current_blog_id();

		if ( ! isset( $this->hierarchy[ $blog ][ $taxonomy ][ $parent_id ] ) ) {
			return;
		}

		array_pop( $this->hierarchy[ $blog ][ $taxonomy ][ $parent_id ] );

		if ( [] === $this->hierarchy[ $blog ][ $taxonomy ][ $parent_id ] ) {
			unset( $this->hierarchy[ $blog ][ $taxonomy ][ $parent_id ] );
		}
	}

	/**
	 * Whether a web request's adoption stops: past the deadline once the minimum moves are made, or 2 s after it.
	 *
	 * @param float $deadline 0 for no limit.
	 * @param int   $moved    Moves made.
	 * @return bool
	 */
	private static function out_of_time( float $deadline, int $moved ): bool {
		if ( $deadline <= 0.0 ) {
			return false;
		}

		$now = microtime( true );

		return $now >= $deadline && ( $moved >= self::ADOPT_MIN_MOVES || $now >= $deadline + self::ADOPT_MIN_SECONDS );
	}

	/**
	 * One page of a term's direct children, read from the table: the stored hierarchy can come from a
	 * language-filtered query.
	 *
	 * @param int    $parent_id Term.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $after     Last term_taxonomy_id of the previous page; 0 for the first.
	 * @return array<int, int> term_taxonomy_id => term id, in that order; empty also when the query gave no answer.
	 */
	private static function source_children( int $parent_id, string $taxonomy, int $after ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// Keyset on term_taxonomy_id, the order of KEY taxonomy, so no page sorts the whole taxonomy.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A read no term query filter may change.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT term_taxonomy_id, term_id FROM %i WHERE taxonomy = %s AND parent = %d AND term_taxonomy_id > %d ORDER BY term_taxonomy_id LIMIT %d', $wpdb->term_taxonomy, $taxonomy, $parent_id, $after, self::ADOPT_PAGE ) );

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return [];
		}

		$page = [];

		foreach ( $rows as $row ) {
			$fields = get_object_vars( $row );

			if ( is_numeric( $fields['term_taxonomy_id'] ?? null ) && is_numeric( $fields['term_id'] ?? null ) && (int) $fields['term_id'] > 0 ) {
				$page[ (int) $fields['term_taxonomy_id'] ] = (int) $fields['term_id'];
			}
		}

		return $page;
	}

	/**
	 * Number of a term's direct children; for the cut-off log only.
	 *
	 * @param int    $parent_id Term.
	 * @param string $taxonomy  Taxonomy.
	 * @return int
	 */
	private static function count_children( int $parent_id, string $taxonomy ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As source_children().
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE taxonomy = %s AND parent = %d', $wpdb->term_taxonomy, $taxonomy, $parent_id ) );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Of the given children of a term, linked in one language, their top-level translations in another
	 * language, while $new_term_id is still the parent's translation there; one indexed query per 500 terms.
	 *
	 * @param array<int> $term_ids    Terms.
	 * @param array<int> $languages   Language the terms are linked in, then the translations' language.
	 * @param string     $taxonomy    Taxonomy.
	 * @param int        $parent_id   Parent the terms must still have.
	 * @param int        $new_term_id Parent's translation the terms move under.
	 * @return array<int, int>|false Term id => translation id; false when a query gave no answer.
	 */
	private static function top_level_translations( array $term_ids, array $languages, string $taxonomy, int $parent_id, int $new_term_id ): array|false {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$term_ids    = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$found       = [];
		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );

		foreach ( array_chunk( $term_ids, 500 ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A read that must bypass every cache. Identifiers are bound with %i, the id list with one %d each.
			$sql = $wpdb->prepare(
				"SELECT s.object_id AS term_id, t.object_id AS translation_id FROM %i s
				INNER JOIN %i stt ON stt.term_id = s.object_id AND stt.taxonomy = %s AND stt.parent = %d
				INNER JOIN %i g ON g.id = s.group_id AND g.type = %s
				INNER JOIN %i t ON t.group_id = s.group_id AND t.language_id = %d
				INNER JOIN %i tt ON tt.term_id = t.object_id AND tt.taxonomy = %s AND tt.parent = 0
				INNER JOIN %i pl ON pl.object_id = %d AND pl.language_id = %d
				INNER JOIN %i pg ON pg.id = pl.group_id AND pg.type = %s
				INNER JOIN %i dl ON dl.group_id = pl.group_id AND dl.language_id = %d AND dl.object_id = %d
				WHERE s.object_id IN ( {$in} ) AND s.language_id = %d AND t.object_id <> s.object_id",
				array_merge(
					[ $links_table, $wpdb->term_taxonomy, $taxonomy, $parent_id, $groups_table, ObjectType::Term->value, $links_table, (int) ( $languages[1] ?? 0 ), $wpdb->term_taxonomy, $taxonomy ],
					[ $links_table, $parent_id, (int) ( $languages[0] ?? 0 ), $groups_table, ObjectType::Term->value, $links_table, (int) ( $languages[1] ?? 0 ), $new_term_id ],
					$chunk,
					[ (int) ( $languages[0] ?? 0 ) ]
				)
			);

			if ( ! is_string( $sql ) || '' === $sql ) {
				return false;
			}

			$rows = $wpdb->query( $sql );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_int( $rows ) ) {
				return false;
			}

			foreach ( is_array( $wpdb->last_result ) ? $wpdb->last_result : [] as $row ) {
				$fields = get_object_vars( $row );
				$term   = $fields['term_id'] ?? null;
				$trans  = $fields['translation_id'] ?? null;

				if ( is_numeric( $term ) && is_numeric( $trans ) && (int) $term > 0 && (int) $trans > 0 ) {
					$found[ (int) $term ] = (int) $trans;
				}
			}
		}

		return $found;
	}

	/**
	 * Set a term's parent.
	 *
	 * @param int    $term_id   Term to move.
	 * @param string $taxonomy  Taxonomy slug.
	 * @param int    $parent_id New parent.
	 * @return bool Whether it was set.
	 */
	private function set_parent( int $term_id, string $taxonomy, int $parent_id ): bool {
		// wp_update_term() writes back every field it reads: read the stored term, not this request's copy.
		wp_cache_delete( $term_id, 'terms' );
		$term = get_term( $term_id, $taxonomy );

		if ( $term instanceof \WP_Term && $parent_id === (int) $term->parent ) {
			return true;
		}

		// A term another request placed meanwhile keeps its parent.
		if ( ! $term instanceof \WP_Term || 0 !== (int) $term->parent ) {
			return false;
		}

		// Core's loop check walks cached ancestors, which another request may have moved: walk stored ones first.
		if ( self::in_chain( $term_id, $parent_id, $taxonomy ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on a move no caller reports.
			error_log( sprintf( '[perflocale] create_translation: did not set parent %d of term %d in taxonomy %s (hierarchy loop)', $parent_id, $term_id, $taxonomy ) );

			return false;
		}

		$this->moving = $term_id;

		try {
			$result = wp_update_term( $term_id, $taxonomy, [ 'parent' => $parent_id ] );
		} finally {
			$this->moving = 0;
		}

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on a failure no caller reports.
			error_log( sprintf( '[perflocale] create_translation: could not set parent %d of term %d in taxonomy %s (%s)', $parent_id, $term_id, $taxonomy, (string) $result->get_error_code() ) );

			return false;
		}

		return true;
	}

	/**
	 * Whether $term_id is $start or one of its stored ancestors, or the chain loops.
	 *
	 * Reads past this request's term cache, so core's own loop check then sees the same fresh copies.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $start    First term of the chain.
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool
	 */
	private static function in_chain( int $term_id, int $start, string $taxonomy ): bool {
		$seen = [];
		$id   = $start;

		while ( $id > 0 ) {
			if ( $id === $term_id || isset( $seen[ $id ] ) ) {
				return true;
			}

			$seen[ $id ] = true;
			wp_cache_delete( $id, 'terms' );
			$ancestor = get_term( $id, $taxonomy );

			if ( ! $ancestor instanceof \WP_Term ) {
				return false;
			}

			$id = (int) $ancestor->parent;
		}

		return false;
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

		// No group yet: two saves could both create one (no UNIQUE on (type, object_id)), leaving the term in
		// two groups. Serialise per source_id (term ids are unique across taxonomies).
		$lock_result = \PerfLocale\Concurrency\Lock::with(
			'link_term_first_group_' . $source_id,
			30,
			function () use ( $source_id, $target_id, $target_lang, $source ): bool {
				// Re-read inside the lock after dropping the null memoized above: otherwise the re-read never sees
				// a group another process committed meanwhile.
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

				// First translation: create the group with the source row, then link the target.
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

		// Lost the lock race: another process is creating the first group. Drop the memoized null, re-read once.
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
	 * The term create_translation() would copy from, for a caller to authorize.
	 *
	 * Mirrors create_translation(): 0 when nothing would be copied. No user policy: callers check the id.
	 *
	 * @param int    $source_id   Term ID the caller was given.
	 * @param string $taxonomy    Taxonomy slug.
	 * @param string $target_slug Target language slug.
	 * @return int Term ID that would be copied, or 0 when nothing would be.
	 */
	public function get_copy_source_id( int $source_id, string $taxonomy, string $target_slug ): int {
		if ( ! get_term( $source_id, $taxonomy ) instanceof \WP_Term ) {
			return 0;
		}

		if ( ! $this->languages->find_by_slug( $target_slug ) ) {
			return 0;
		}

		if ( $this->get_translation_id( $source_id, $target_slug ) !== null ) {
			return 0;
		}

		$content_source = $this->resolve_source_term( $source_id, $taxonomy );

		return $content_source instanceof \WP_Term ? (int) $content_source->term_id : 0;
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
	 * Lets bulk operations use a pre-built group_id => language => term_id map instead of N+1 queries.
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

			$new_lang = (int) $lang->id;
			$group_id = (int) $group->id;
			$touch    = \PerfLocale\Database\Schema::touch_write_time( [ [ 'language_id', '%d', 'int' ] ] );

			if ( '' === $touch || ! $wpdb instanceof \wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					$links_table,
					[ 'language_id' => $new_lang ],
					[
						'group_id'  => $group_id,
						'object_id' => $term_id,
					],
					[ '%d' ],
					[ '%d', '%d' ]
				);
			} else {
				// SQLite: the same write plus the updated_at that ON UPDATE CURRENT_TIMESTAMP gives on MySQL.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET language_id = %d, updated_at = CASE WHEN language_id = %d THEN updated_at ELSE NOW() END WHERE group_id = %d AND object_id = %d',
						$links_table,
						$new_lang,
						$new_lang,
						$group_id,
						$term_id
					) ?? ''
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			// false: a DB error (a concurrent writer took the slot). 0: the row was deleted meanwhile, or already had
			// this language (MySQL counts changed rows, not matched), so read it back.
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
				// Else: idempotent - already at target language. Fall through.
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
	 * Each language needs its own term with the same name, which WordPress refuses within one taxonomy.
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
	 * Records each group's default-language base slug for every member. Idempotent: set_slug() upserts.
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
				"SELECT g.id AS group_id, l.object_id AS term_id, l.language_id, l.source
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

			// Each member's own taxonomy is its slug-translation subtype.
			foreach ( $members as $m ) {
				// An importer-linked term keeps its own slug as its URL: the default member's slug would move its archive.
				if ( in_array( (string) $m->source, self::IMPORTED_SOURCES, true ) ) {
					continue;
				}

				$term_obj = get_term( (int) $m->term_id );
				$tax      = ( $term_obj instanceof \WP_Term ) ? $term_obj->taxonomy : '';

				if ( $tax === '' ) {
					continue;
				}

				// Count only writes that landed: set_slug() returns false on a failure (perflocale/slug/write_failed).
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
	 * Each language needs its own term with the same name, which WordPress refuses within one taxonomy.
	 *
	 * @param string               $name Term name.
	 * @param string               $taxonomy Taxonomy slug.
	 * @param string               $slug Unique slug (e.g. "test-fr").
	 * @param array<string, mixed> $args Additional args (parent, description).
	 * @return int|false Term ID or false.
	 */
	private function force_insert_term( string $name, string $taxonomy, string $slug, array $args ): int|false {
		global $wpdb;

		// Walk to the first free "-N" slug (wp_terms has no unique slug index). Keep both halves: the reserved room
		// (get_term_by() sanitizes "$base-N" back to $base at the cap, an endless spin) and MAX_SLUG_SUFFIX:
		// see docs-ai/11-code-rationale.md, "TermTranslationManager: forced insert slug walk".
		$base      = $this->reserve_slug_room( sanitize_title( $slug ), strlen( (string) self::MAX_SLUG_SUFFIX ) + 1 );
		$candidate = $base;
		$suffix    = 2;

		while ( $candidate !== '' && get_term_by( 'slug', $candidate, $taxonomy ) ) {
			if ( $suffix > self::MAX_SLUG_SUFFIX ) {
				// Every suffix is taken: fail. REST and admin callers report it; bulk paths only count it, hence the log.
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
