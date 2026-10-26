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
 * In post editors it uses the edited post's language, so taxonomy pickers match the post.
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
	 * Per-request "blog_id:post_id" to language_id memo; cleared on switch_blog.
	 *
	 * @var array<string, int>
	 */
	private static array $post_lang_id_cache = [];

	/**
	 * Per-request language SQL fragments, keyed by language_id.
	 * Must be reset on switch_blog: fragments embed the blog's table prefix and per-blog language ids.
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
	 * The `perflocale_post` param is added by the editor sidebar JS middleware.
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

		// Terms of specific objects (get_the_terms()) must return every assigned term, any language.
		if ( ! empty( $args['object_ids'] ) ) {
			return $clauses;
		}

		// Terms by ID: wp_set_object_terms() validates IDs this way, and filtering silently drops assignments.
		if ( ! empty( $args['include'] ) ) {
			return $clauses;
		}

		// Slug lookups resolve taxonomy archives: filtering them 404s another language's term slug.
		if ( ! empty( $args['slug'] ) ) {
			return $clauses;
		}

		// Core's _get_term_hierarchy() build: stored once for every language.
		if ( 'all' === ( $args['get'] ?? '' ) && 'id=>parent' === ( $args['fields'] ?? '' ) && false === ( $args['update_term_meta_cache'] ?? true ) && empty( $args['perflocale_language_id'] ) ) {
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
	 * Resolve the language ID: explicit arg, then admin post editor, then the URL language.
	 *
	 * @param array<string, mixed> $args Term query arguments.
	 * @return int Language ID or 0.
	 */
	private function resolve_language_id( array $args ): int {
		// 1. Explicit language ID.
		if ( ! empty( $args['perflocale_language_id'] ) ) {
			return (int) $args['perflocale_language_id'];
		}

		// 2. Admin context: filter by the edited post's language.
		if ( is_admin() ) {
			// 2a. Classic-editor tag autocomplete carries no post ID: read it from the referer.
			if ( wp_doing_ajax() ) {
				return $this->get_ajax_edit_language_id();
			}

			return $this->get_admin_edit_language_id();
		}

		// 3. Frontend: use the current language from URL.
		return $this->router->get_current_language_id();
	}

	/**
	 * Detect the edited post's language during a tag-search AJAX request, from HTTP_REFERER.
	 * Returns 0 for any other AJAX action, so unrelated callers stay unfiltered.
	 *
	 * @return int Language ID or 0.
	 */
	private function get_ajax_edit_language_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language detection.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';

		// Only the tag-search endpoint.
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
	 * Detect the language ID for admin post editor context, or 0 (no filtering).
	 * Reads REQUEST_URI first: $pagenow comes from PHP_SELF, which some front controllers rewrite.
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

		// Fall back to $pagenow.
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
	 * Priority: ?post=<id>, then ?perflocale_lang=<slug>, then the default language.
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
		// Keyed by blog too: the same post_id can map to another language on another subsite.
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
		// Built once per language; reset on switch_blog (see $clause_cache).
		if ( ! isset( self::$clause_cache[ $language_id ] ) ) {
			global $wpdb;

			$links_table  = Schema::table( 'translation_links' );
			$groups_table = Schema::table( 'translation_groups' );

			// LEFT JOIN: a term matches when linked to this language or has no term link (unmanaged).
			// Table names are Schema::table() values: the fragment is returned to core, so it cannot be prepared.
			$alias = 'pl_term_lang';

			// object_id is a post OR term id: the nested INNER JOIN on type 'term' keeps a colliding post link out.
			$join = " LEFT JOIN ( {$links_table} AS {$alias}"
				. " INNER JOIN {$groups_table} AS {$alias}_g"
				. " ON {$alias}_g.id = {$alias}.group_id AND {$alias}_g.type = 'term' )"
				. " ON {$alias}.object_id = t.term_id";

			// `_g.id IS NULL` means no term link at all, so the fallback admits unmanaged terms only.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is a hardcoded string constant, not user input.
			$where = $wpdb->prepare(
				" AND ( ( {$alias}.language_id = %d AND {$alias}_g.id IS NOT NULL ) OR {$alias}_g.id IS NULL )",
				$language_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			self::$clause_cache[ $language_id ] = [
				'join'  => $join,
				// Never splice a failed prepare() result.
				'where' => is_string( $where ) ? $where : '',
			];
		}

		$fragments        = self::$clause_cache[ $language_id ];
		$clauses['join']  = ( $clauses['join'] ?? '' ) . $fragments['join'];
		$clauses['where'] = ( $clauses['where'] ?? '' ) . $fragments['where'];

		return $clauses;
	}
}
