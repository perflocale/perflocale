<?php
/**
 * Term query language filter.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Plugin;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters term queries to return only terms in the current language.
 *
 * Uses the terms_clauses filter to join translation_links and filter
 * by language, similar to PostQueryFilter. In admin post editors,
 * filters by the edited post's language so taxonomy pickers only show
 * terms matching the post's language.
 */
final class TermQueryFilter {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Per-request post_id → language_id memo, behind reset_static_caches() so
	 * a `switch_to_blog()` mid-request wipes it (same post_id can map to a
	 * different language_id on a different blog).
	 *
	 * @var array<string, int>
	 */
	private static array $post_lang_id_cache = [];

	/**
	 * Per-request cache of the language SQL fragments, keyed by language_id.
	 *
	 * The join + where fragments are a pure function of the blog's table names
	 * (Schema::table) and the language_id, so they can be built once per
	 * language and spliced in on every subsequent terms_clauses cache-miss —
	 * mirroring PostQueryFilter::$query_clauses_cache. MUST be reset on
	 * switch_blog: the fragments embed the blog-specific table prefix, and
	 * language_id is a per-blog auto-increment, so a stale entry would splice
	 * another blog's table names / wrong-language clause. reset_static_caches()
	 * (hooked to switch_blog in Bootstrap) clears it below.
	 *
	 * @var array<int, array{join: string, where: string}>
	 */
	private static array $clause_cache = [];

	/**
	 * Reset per-blog static caches when multisite switches context.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$post_lang_id_cache = [];
		self::$clause_cache       = [];
	}

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param Settings       $settings Plugin settings.
	 */
	public function __construct( LanguageRouter $router, Settings $settings ) {
		$this->router   = $router;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'terms_clauses', [ $this, 'filter_terms_by_language' ], 10, 3 );

		// Filter REST API term queries when editing a post in the block editor.
		add_action( 'rest_api_init', [ $this, 'register_rest_term_filters' ] );
	}

	/**
	 * Register REST API filters for all public taxonomies.
	 *
	 * @return void
	 */
	public function register_rest_term_filters(): void {
		$taxonomies = get_taxonomies( [ 'show_in_rest' => true ] );

		foreach ( $taxonomies as $taxonomy ) {
			add_filter( "rest_{$taxonomy}_query", [ $this, 'filter_rest_term_query' ], 10, 2 );
		}
	}

	/**
	 * Filter REST API term queries by the edited post's language.
	 *
	 * The block editor sends term requests with a `perflocale_post`
	 * parameter added by the editor sidebar JS middleware. This method
	 * detects the post's language and injects it into the query args
	 * so the terms_clauses filter can apply language filtering.
	 *
	 * @param array<string, mixed> $args Term query arguments.
	 * @param \WP_REST_Request     $request REST request.
	 * @return array<string, mixed>
	 */
	public function filter_rest_term_query( array $args, \WP_REST_Request $request ): array {
		$post_id = $request->get_param( 'perflocale_post' );

		if ( ! $post_id ) {
			return $args;
		}

		$language_id = $this->detect_post_language_id( absint( $post_id ) );

		if ( $language_id > 0 ) {
			$args['perflocale_language_id'] = $language_id;
		}

		return $args;
	}

	/**
	 * Filter term query clauses to restrict results to the current language.
	 *
	 * @param array<string, string> $clauses SQL clauses (fields, join, where, etc.).
	 * @param array<int, string>    $taxonomies Taxonomy slugs.
	 * @param array<string, mixed>  $args Query arguments.
	 * @return array<string, string> Modified clauses.
	 */
	public function filter_terms_by_language( array $clauses, array $taxonomies, array $args ): array {
		// Skip if opted out.
		if ( ! empty( $args['perflocale_all_languages'] ) ) {
			return $clauses;
		}

		// Skip when fetching terms for specific objects (e.g. get_the_terms(),
		// wp_get_object_terms()). That query asks "what terms does this post
		// have?" - it must return all assigned terms regardless of language.
		// Language filtering only applies when listing terms for pickers.
		if ( ! empty( $args['object_ids'] ) ) {
			return $clauses;
		}

		// Skip when fetching specific terms by ID (e.g. term_exists() called
		// during wp_set_object_terms() to validate term IDs). Filtering these
		// by language causes valid terms from other languages to fail validation
		// and be silently dropped from post assignments.
		if ( ! empty( $args['include'] ) ) {
			return $clauses;
		}

		// Skip slug resolution queries. When WordPress resolves a taxonomy
		// archive (e.g. /tag/tag1-fr/), WP_Tax_Query calls get_terms() with
		// a specific 'slug' argument to find the term. Filtering this by
		// language causes 404s when the slug is from a different language's
		// term. Language filtering for posts is handled by PostQueryFilter.
		if ( ! empty( $args['slug'] ) ) {
			return $clauses;
		}

		// Only filter translatable taxonomies.
		$translatable = $this->settings->get_translatable_taxonomies();
		$overlap      = array_intersect( $taxonomies, $translatable );

		if ( empty( $overlap ) ) {
			return $clauses;
		}

		// Determine language ID based on context.
		$language_id = $this->resolve_language_id( $args );

		if ( $language_id === 0 ) {
			return $clauses;
		}

		return $this->apply_language_clauses( $clauses, $language_id );
	}

	/**
	 * Resolve the language ID for term filtering based on context.
	 *
	 * Priority:
	 * 1. Explicit perflocale_language_id arg (from REST API or programmatic).
	 * 2. Admin post editor context (classic editor).
	 * 3. Frontend: current language from URL.
	 *
	 * @param array<string, mixed> $args Term query arguments.
	 * @return int Language ID or 0.
	 */
	private function resolve_language_id( array $args ): int {
		// 1. Explicit language ID (from REST API filter or programmatic use).
		if ( ! empty( $args['perflocale_language_id'] ) ) {
			return (int) $args['perflocale_language_id'];
		}

		// 2. Admin context: filter by the edited post's language.
		if ( is_admin() ) {
			// 2a. Classic-editor tag autocomplete (admin-ajax.php?action=ajax-tag-search).
			// The AJAX call carries no post ID of its own, so we read the
			// referring post.php/post-new.php URL from HTTP_REFERER.
			if ( wp_doing_ajax() ) {
				return $this->get_ajax_edit_language_id();
			}

			return $this->get_admin_edit_language_id();
		}

		// 3. Frontend: use the current language from URL.
		return $this->router->get_current_language_id();
	}

	/**
	 * Detect the edited post's language during a tag-search AJAX request.
	 *
	 * WordPress' classic-editor tag autocomplete posts to
	 * admin-ajax.php?action=ajax-tag-search and does not include a post ID.
	 * We parse HTTP_REFERER to find the post.php/post-new.php URL the tag
	 * box lives on, then reuse the same detection as non-AJAX admin
	 * (post=<id>, perflocale_lang=<slug>, or the default language).
	 *
	 * Returns 0 for any other AJAX action so we don't accidentally filter
	 * unrelated AJAX callers (e.g. wp_ajax_inline-save term pickers).
	 *
	 * @return int Language ID or 0.
	 */
	private function get_ajax_edit_language_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language detection.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';

		// Only handle the tag-search endpoint. Other AJAX actions stay
		// unfiltered to avoid surprising third-party callers.
		if ( $action !== 'ajax-tag-search' ) {
			return 0;
		}

		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		if ( $referer === '' ) {
			return 0;
		}

		return $this->detect_language_from_uri( $referer );
	}

	/**
	 * Detect the language ID for admin post editor context.
	 *
	 * Returns 0 when no context can be inferred, which disables term
	 * filtering. Uses $_SERVER + $_GET signals instead of the $pagenow
	 * global because $pagenow has proven unreliable at the point this
	 * runs on some Local-for-Linux setups (empty string despite being
	 * on post.php - WP's vars.php derives it from PHP_SELF which can
	 * be rewritten by the container's front controller).
	 *
	 * @return int Language ID or 0.
	 */
	private function get_admin_edit_language_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language detection.
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$on_post_edit = (
			strpos( $uri, '/wp-admin/post.php' ) !== false
			|| strpos( $uri, '/wp-admin/post-new.php' ) !== false
		);

		// Fall back to $pagenow on environments where it IS set correctly.
		if ( ! $on_post_edit ) {
			global $pagenow;
			$on_post_edit = in_array( (string) $pagenow, [ 'post.php', 'post-new.php' ], true );
		}

		if ( ! $on_post_edit ) {
			return 0;
		}

		return $this->detect_language_from_uri( $uri );
	}

	/**
	 * Extract an edited-post language ID from a post.php / post-new.php URI.
	 *
	 * Works for both the current request URI and referring URLs (for AJAX).
	 * Priority: ?post=<id> → ?perflocale_lang=<slug> → default language.
	 *
	 * @param string $uri Absolute or relative URL pointing at post.php / post-new.php.
	 * @return int Language ID or 0.
	 */
	private function detect_language_from_uri( string $uri ): int {
		if ( strpos( $uri, '/wp-admin/post.php' ) === false
			&& strpos( $uri, '/wp-admin/post-new.php' ) === false
		) {
			return 0;
		}

		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		if ( $query === '' ) {
			return 0;
		}

		$params = [];
		parse_str( $query, $params );

		// Existing post: detect language from the post_id.
		$post_id = isset( $params['post'] ) ? absint( $params['post'] ) : 0;

		if ( $post_id > 0 ) {
			return $this->detect_post_language_id( $post_id );
		}

		// New post: detect from PerfLocale source parameters.
		$lang_slug = isset( $params['perflocale_lang'] ) ? sanitize_text_field( (string) $params['perflocale_lang'] ) : '';

		if ( $lang_slug !== '' ) {
			$plugin = Plugin::get_instance();
			$cache  = $plugin->get( 'cache' );
			$repo   = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$lang   = $repo->find_by_slug( $lang_slug );

			return $lang ? (int) $lang->id : 0;
		}

		// Brand new post with no language context - use default language.
		$default = $this->router->get_default_language();

		return $default ? (int) $default->id : 0;
	}

	/**
	 * Detect a post's language ID from the translation links table.
	 *
	 * @param int $post_id Post ID.
	 * @return int Language ID or 0.
	 */
	private function detect_post_language_id( int $post_id ): int {
		// Key by blog_id:post_id so multisite switch_to_blog() doesn't serve
		// blog A's cached value to blog B (same post_id can map to a different
		// language on a different subsite). The class-static is cleared on
		// switch_blog via reset_static_caches() — defence in depth.
		$key = get_current_blog_id() . ':' . $post_id;

		if ( isset( self::$post_lang_id_cache[ $key ] ) ) {
			return self::$post_lang_id_cache[ $key ];
		}

		$repo  = Plugin::get_instance()->get( 'group_repo' );
		$links = $repo->get_translations( $post_id, ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) $link->object_id === $post_id && isset( $link->language_id ) ) {
				self::$post_lang_id_cache[ $key ] = (int) $link->language_id;
				return self::$post_lang_id_cache[ $key ];
			}
		}

		// Post has no language assigned - use default language.
		$default                          = $this->router->get_default_language();
		self::$post_lang_id_cache[ $key ] = $default ? (int) $default->id : 0;

		return self::$post_lang_id_cache[ $key ];
	}

	/**
	 * Apply language filtering SQL clauses.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param int                   $language_id Language ID.
	 * @return array<string, string> Modified clauses.
	 */
	private function apply_language_clauses( array $clauses, int $language_id ): array {
		// The join + where fragments are a pure function of the blog's table
		// names and $language_id, so build them once per language and reuse on
		// every subsequent terms_clauses cache-miss (mirrors
		// PostQueryFilter::$query_clauses_cache). Reset on switch_blog via
		// reset_static_caches() so a stale entry never splices another blog's
		// table prefix or a different blog's language_id semantics.
		if ( ! isset( self::$clause_cache[ $language_id ] ) ) {
			global $wpdb;

			$links_table  = Schema::table( 'translation_links' );
			$groups_table = Schema::table( 'translation_groups' );

			// LEFT JOIN approach: terms match if linked to this language OR have
			// no term-type link at all (unmanaged content). Avoids the expensive
			// NOT IN subquery that requires scanning all rows in translation_links.
			//
			// A second LEFT JOIN onto translation_groups with `type = 'term'`
			// replaces the prior `group_id IN (…huge list…)` filter. That list
			// grew O(n) in SQL text with the number of term-type groups; the join
			// version hits the translation_groups primary key and is constant-sized.
			$alias = 'pl_term_lang';

			// translation_links is polymorphic — object_id is a post OR a term id.
			// A post whose id equals a term's term_id would otherwise match this
			// term on `object_id = t.term_id`, producing a spurious extra row that
			// double-counts the term (and, via `_g.id IS NULL`, leaks it across
			// languages). Nest the type='term' filter into an INNER JOIN INSIDE the
			// LEFT JOIN so only term-typed links reach the result — a colliding
			// post-link never participates. Mirrors PostQueryFilter::modify_query_clauses.
			$join = " LEFT JOIN ( {$links_table} AS {$alias}"
				. " INNER JOIN {$groups_table} AS {$alias}_g"
				. " ON {$alias}_g.id = {$alias}.group_id AND {$alias}_g.type = 'term' )"
				. " ON {$alias}.object_id = t.term_id";

			// Match semantics: {$alias}_g.id IS NOT NULL means "a term-typed
			// translation link exists" (the nested INNER JOIN already excluded any
			// colliding post-link, so IS NULL means genuinely no term link); the
			// fallback branch admits unmanaged terms only.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is a hardcoded string constant, not user input.
			$where = $wpdb->prepare(
				" AND ( ( {$alias}.language_id = %d AND {$alias}_g.id IS NOT NULL ) OR {$alias}_g.id IS NULL )",
				$language_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			self::$clause_cache[ $language_id ] = [
				'join'  => $join,
				// prepare() returns string on success; is_string() narrows the
				// type cleanly and falls back to '' on the (never-hit-here)
				// null/false error path rather than splicing a bad fragment.
				'where' => is_string( $where ) ? $where : '',
			];
		}

		$fragments        = self::$clause_cache[ $language_id ];
		$clauses['join']  = ( $clauses['join'] ?? '' ) . $fragments['join'];
		$clauses['where'] = ( $clauses['where'] ?? '' ) . $fragments['where'];

		return $clauses;
	}
}
