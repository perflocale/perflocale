<?php
/**
 * Post query language filter.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Helper;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters WP_Query to return only posts in the current language, via posts_clauses.
 */
final class PostQueryFilter {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Memo of post types never language-scoped, even when translatable, keyed by blog.
	 * Core resolves templates and the navigation fallback by slug/newest; scoping breaks them (navigation then inserts junk menus).
	 * Keyed by blog: the filter's callbacks differ per subsite. See docs-ai/11-code-rationale.md, "PostQueryFilter: never-scoped post types".
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $never_scoped_memo = [];

	/**
	 * @return array<int, string>
	 */
	private function never_scoped_post_types(): array {
		// Hottest query path: memoised so apply_filters() runs once per blog.
		$blog_id = get_current_blog_id();

		if ( isset( $this->never_scoped_memo[ $blog_id ] ) ) {
			return $this->never_scoped_memo[ $blog_id ];
		}

		/**
		 * Filter the post types that are never language-scoped.
		 *
		 * @hook  perflocale/query/never_scoped_post_types
		 * @since 1.0.5
		 *
		 * @param array<int, string> $types Post type slugs.
		 * @return array<int, string>
		 */
		$this->never_scoped_memo[ $blog_id ] = array_values(
			array_unique(
				array_map(
					'strval',
					(array) apply_filters(
						'perflocale/query/never_scoped_post_types',
						[ 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ]
					)
				)
			)
		);

		return $this->never_scoped_memo[ $blog_id ];
	}

	/**
	 * Mark the navigation-fallback query core runs next.
	 * Core applies this filter only just before that query; without it the newest menu (a translation) wins everywhere.
	 *
	 * @param mixed $create Whether core may create a fallback menu.
	 * @return mixed Unchanged.
	 */
	public function arm_navigation_fallback( $create ) {
		if ( in_array( 'wp_navigation', $this->settings->get_translatable_post_types(), true ) ) {
			add_filter( 'the_posts', [ $this, 'filter_navigation_fallback' ], 20, 2 );
		}

		return $create;
	}

	/**
	 * Keep core's navigation fallback on a default-language menu, translated on the front end.
	 * Never empties the result: an empty result makes core insert a new menu. REST keeps the default menu for the Site Editor.
	 *
	 * @param mixed     $posts Posts core found.
	 * @param \WP_Query $query The query.
	 * @return mixed The posts, the first one replaced when a step applies.
	 */
	public function filter_navigation_fallback( $posts, \WP_Query $query ) {
		if ( 'wp_navigation' !== $query->get( 'post_type' ) ) {
			return $posts;
		}

		// One shot: the first wp_navigation query after the mark consumes it.
		remove_filter( 'the_posts', [ $this, 'filter_navigation_fallback' ], 20 );

		if ( ! is_array( $posts ) || ! isset( $posts[0] ) || ! $posts[0] instanceof \WP_Post || ! self::is_navigation_fallback_query( $query ) ) {
			return $posts;
		}

		$menu    = $posts[0];
		$default = $this->router->get_default_language();

		if ( $default && $this->detect_post_language_id( (int) $menu->ID ) !== (int) $default->id ) {
			$source = $this->newest_default_navigation( (int) $default->id );

			if ( $source instanceof \WP_Post ) {
				$menu = $source;
			}
		}

		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$plugin = \PerfLocale\Plugin::get_instance();
			$refs   = $plugin->has( 'block_ref_translator' ) ? $plugin->get( 'block_ref_translator' ) : null;

			if ( $refs instanceof \PerfLocale\Frontend\BlockRefTranslator ) {
				$translated = $refs->translated_ref( (int) $menu->ID, 'wp_navigation' );
				$post       = $translated > 0 ? get_post( $translated ) : null;

				if ( $post instanceof \WP_Post ) {
					$menu = $post;
				}
			}
		}

		$posts[0] = $menu;

		return $posts;
	}

	/**
	 * Whether a wp_navigation query has the shape of
	 * `WP_Navigation_Fallback::get_most_recently_published_navigation()`.
	 *
	 * @param \WP_Query $query The query.
	 * @return bool
	 */
	private static function is_navigation_fallback_query( \WP_Query $query ): bool {
		return 'publish' === $query->get( 'post_status' )
			&& 1 === (int) $query->get( 'posts_per_page' )
			&& 'date' === $query->get( 'orderby' )
			&& 'DESC' === strtoupper( (string) $query->get( 'order' ) );
	}

	/**
	 * The newest published wp_navigation post in the default language or unlinked.
	 *
	 * @param int $default_id Default language ID.
	 * @return \WP_Post|null
	 */
	private function newest_default_navigation( int $default_id ): ?\WP_Post {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.* FROM %i p
				 LEFT JOIN ( %i l INNER JOIN %i g ON g.id = l.group_id AND g.type = 'post' ) ON l.object_id = p.ID
				 WHERE p.post_type = 'wp_navigation'
				   AND p.post_status = 'publish'
				   AND ( ( l.language_id = %d AND g.id IS NOT NULL ) OR g.id IS NULL )
				 ORDER BY p.post_date DESC, p.ID DESC
				 LIMIT 1",
				$wpdb->posts,
				Schema::table( 'translation_links' ),
				Schema::table( 'translation_groups' ),
				$default_id
			)
		);

		if ( ! is_object( $row ) || empty( $row->ID ) ) {
			return null;
		}

		$rows = [ new \WP_Post( $row ) ];
		update_post_cache( $rows );

		$post = get_post( (int) $row->ID );

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Whether resolve_duplicate_slug() loaded a default-language fallback post.
	 * Suppresses the canonical redirect.
	 *
	 * @var bool
	 */
	private bool $loaded_fallback = false;

	/**
	 * Target language slug of the in-flight scoped REST link-picker search, or ''.
	 *
	 * @var string
	 */
	private string $rest_search_target_slug = '';

	/**
	 * Per-request memo for find_slug_candidates(), keyed "blog|post_type|slug".
	 *
	 * @var array<string, array<int, object>>
	 */
	private array $slug_candidates_memo = [];

	/**
	 * Lazily instantiated translation group repository.
	 *
	 * @var \PerfLocale\Database\Repository\TranslationGroupRepository|null
	 */
	private ?\PerfLocale\Database\Repository\TranslationGroupRepository $groups_repo = null;

	/**
	 * Per-request map of post object_id to language_id, built on the first filter_get_pages() call.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $page_language_map_cache = null;

	/**
	 * Request constants for the filter_get_pages() loop; cleared on switch_blog.
	 *
	 * @var array{lang_id:int, tt:array<int, string>}|null
	 */
	private static ?array $get_pages_cfg_cache = null;

	/**
	 * Pre-built language JOIN/WHERE fragments keyed by language_id; cleared on switch_blog.
	 *
	 * @var array<int, array{join:string, where:string}>|null
	 */
	private static ?array $query_clauses_cache = null;

	/**
	 * Per-request "blog_id:post_id" to language_id memo for detect_post_language_id().
	 *
	 * @var array<string, int>
	 */
	private static array $detect_post_lang_cache = [];

	/**
	 * Per-request "blog_id:post_id" to [language_id => post_id] memo for get_translations_map().
	 *
	 * @var array<string, array<int, int>>
	 */
	private static array $translations_map_cache = [];

	/**
	 * Per-request `option_page_for_posts` translation cache, keyed "language_id:page_id".
	 *
	 * @var array<string, int>
	 */
	private static array $page_for_posts_cache = [];

	/**
	 * Per-request adjacent-post (Previous/Next) clause cache, keyed by the viewed post id.
	 * Must be cleared on switch_blog: post ids collide across blogs.
	 *
	 * @var array<int, array{join: string, where: string}|null>
	 */
	private static array $adjacent_clauses_memo = [];

	/**
	 * Generational cache group of paginated found_posts counts.
	 * Bumped on every change that can move a count (status, deletion, links, languages).
	 */
	private const FOUND_ROWS_GROUP = 'perflocale_found_rows';

	/**
	 * Alias of the language LEFT JOIN added by modify_query_clauses().
	 */
	private const LANGUAGE_ALIAS = 'pl_lang_filter';

	/**
	 * Smallest look-ahead, in rows, of the bounded row check in listing_page_exists().
	 */
	private const LISTING_LOOKAHEAD_MIN = 50;

	/**
	 * Final clauses of the front-end main listing query on page 2+, per blog id, for listing_page_exists().
	 * Keyed by blog, not reset on switch_blog: a switch between the main query and wp_head must keep it.
	 *
	 * @var array<int, array{oid: int, language_id: int, confirmed: bool, request: ?string, distinct: string, fields: string, join: string, where: string, groupby: string, orderby: string, limits: string}>
	 */
	private static array $main_listing = [];

	/**
	 * Per blog, the spl_object_id() of a front-end main query left without a language condition.
	 * Such a listing has the same rows in every language.
	 *
	 * @var array<int, int>
	 */
	private static array $unscoped_main_listing = [];

	/**
	 * Per-query (spl_object_id) COUNT SQL and LIMIT flag to reproduce core's found_posts.
	 * Consumed and unset in set_found_posts().
	 *
	 * @var array<int, array{sql: string, has_limits: bool}>
	 */
	private static array $found_rows_data = [];

	/**
	 * Reset per-blog static caches when multisite switches context.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$page_language_map_cache = null;
		self::$get_pages_cfg_cache     = null;
		self::$query_clauses_cache     = null;
		self::$detect_post_lang_cache  = [];
		self::$translations_map_cache  = [];
		self::$page_for_posts_cache    = [];
		self::$adjacent_clauses_memo   = [];
		// Not $found_rows_data or $main_listing: query-object scoped, and a third-party
		// switch_to_blog() pair between stash and the_posts would zero pagination.
	}

	/**
	 * Get the translation group repository (lazy).
	 *
	 * @return \PerfLocale\Database\Repository\TranslationGroupRepository
	 */
	private function get_groups_repo(): \PerfLocale\Database\Repository\TranslationGroupRepository {
		if ( $this->groups_repo === null ) {
			$this->groups_repo = new \PerfLocale\Database\Repository\TranslationGroupRepository(
				\PerfLocale\Plugin::get_instance()->get( 'cache' )
			);
		}

		return $this->groups_repo;
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
		// Resolve translated front page for language homepages (/en/, /de/).
		add_action( 'pre_get_posts', [ $this, 'resolve_translated_front_page' ], 1 );

		// Filter page_for_posts to the translated version for the current language.
		add_filter( 'option_page_for_posts', [ $this, 'filter_page_for_posts' ] );

		// Convert translated blog pages to blog index queries.
		add_action( 'pre_get_posts', [ $this, 'resolve_translated_posts_page' ], 2 );

		// Resolve correct page/post when duplicate slugs exist across languages.
		add_action( 'pre_get_posts', [ $this, 'resolve_duplicate_slug' ], 5 );

		// Priority 5: the language flag is set before third-party callbacks at 10 inspect the query.
		add_action( 'pre_get_posts', [ $this, 'filter_by_language' ], 5 );

		// Sitewide comment feeds run their own comment query, which the post clauses never touch.
		add_filter( 'comment_feed_join', [ $this, 'scope_comment_feed_join' ], 10, 2 );
		add_filter( 'comment_feed_where', [ $this, 'scope_comment_feed_where' ], 10, 2 );

		// Redirect front/blog page slugs to their canonical language root URL.
		add_action( 'template_redirect', [ $this, 'redirect_front_page_slug' ], 5 );

		// Priority 1: strip the `perflocale_fb` sentinel before the fallback handler can re-fire on it.
		add_action( 'template_redirect', [ $this, 'clean_fallback_sentinel' ], 1 );

		// Enforce missing translation action on singular pages.
		add_action( 'template_redirect', [ $this, 'handle_missing_translation' ] );

		// Keep core's canonical redirect from preempting handle_missing_translation() on a fallback post.
		add_filter( 'redirect_canonical', [ $this, 'prevent_fallback_canonical_redirect' ], 10, 2 );

		// Priority 6, after filter_by_language: replace SQL_CALC_FOUND_ROWS with a cached exact count.
		add_action( 'pre_get_posts', [ $this, 'optimize_found_rows' ], 6 );

		// Apply language clause to every query that was marked above.
		add_filter( 'posts_clauses', [ $this, 'modify_query_clauses' ], 10, 2 );

		// COUNT SQL from the final clauses: posts_clauses_request includes every third-party clause edit.
		add_filter( 'posts_clauses_request', [ $this, 'stash_found_rows_count' ], PHP_INT_MAX, 2 );

		// A short-circuited query keeps the short-circuiting plugin's own found_posts.
		add_filter( 'posts_pre_query', [ $this, 'unstash_on_short_circuit' ], PHP_INT_MAX, 2 );

		// Keep the main listing's final clauses on page 2 and later; the hreflang
		// alternates decide page existence from them (listing_page_exists()).
		add_filter( 'posts_clauses_request', [ $this, 'remember_main_listing' ], PHP_INT_MAX, 2 );
		add_filter( 'posts_request', [ $this, 'capture_main_listing_request' ], PHP_INT_MIN, 2 );
		add_filter( 'posts_pre_query', [ $this, 'confirm_main_listing' ], PHP_INT_MAX, 2 );

		// Supply found_posts / max_num_pages for queries optimize_found_rows() changed.
		add_filter( 'the_posts', [ $this, 'set_found_posts' ], 20, 2 );

		// Invalidate cached counts; link changes bump it in TranslationGroupRepository::invalidate_eager_link_map().
		add_action( 'transition_post_status', [ $this, 'flush_found_rows_on_transition' ], 10, 2 );
		add_action( 'deleted_post', [ $this, 'flush_found_rows_on_delete' ], 10, 2 );
		add_action( 'perflocale/language/added', [ $this, 'flush_found_rows_cache' ] );
		add_action( 'perflocale/language/deleted', [ $this, 'flush_found_rows_cache' ] );

		// Previous/Next links: get_adjacent_post() runs raw SQL, not a WP_Query.
		add_filter( 'get_previous_post_join', [ $this, 'filter_adjacent_post_join' ], 10, 5 );
		add_filter( 'get_next_post_join', [ $this, 'filter_adjacent_post_join' ], 10, 5 );
		add_filter( 'get_previous_post_where', [ $this, 'filter_adjacent_post_where' ], 10, 5 );
		add_filter( 'get_next_post_where', [ $this, 'filter_adjacent_post_where' ], 10, 5 );

		// Block editor link picker: scope /wp/v2/search by the edited post (`perflocale_post`), not the URL language.
		add_filter( 'rest_post_search_query', [ $this, 'filter_rest_search_query' ], 10, 2 );

		// Re-prefix result URLs to the queried language; core has no per-item search filter.
		add_filter( 'rest_request_after_callbacks', [ $this, 'filter_rest_search_response_urls' ], 10, 3 );

		// Filter get_pages() results by language (used by wp_page_menu fallback).
		add_filter( 'get_pages', [ $this, 'filter_get_pages' ], 10, 2 );

		// Keep core's navigation fallback on a default-language menu.
		add_filter( 'wp_navigation_should_create_fallback', [ $this, 'arm_navigation_fallback' ] );

		// Main-query translation priming lives in UrlConverter::preload_object_languages (the_posts 5).
	}

	/**
	 * Point a language homepage main query (/de/) at that language's translated front page.
	 *
	 * @param \WP_Query $query The main query.
	 * @return void
	 */
	public function resolve_translated_front_page( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Feeds, robots, sitemaps, embeds and trackbacks share the bare homepage's empty query shape.
		if (
			! empty( $query->is_feed )
			|| ! empty( $query->is_robots )
			|| ! empty( $query->is_favicon )
			|| ! empty( $query->is_embed )
			|| ! empty( $query->is_trackback )
			|| ! empty( $query->is_404 )
			|| (string) $query->get( 'sitemap' ) !== ''
			|| (string) $query->get( 'sitemap-stylesheet' ) !== ''
			|| (string) $query->get( 'sitemap-subtype' ) !== ''
		) {
			return;
		}

		$show_on_front = get_option( 'show_on_front' );
		$front_page_id = (int) get_option( 'page_on_front' );

		if ( $show_on_front !== 'page' || $front_page_id === 0 ) {
			return;
		}

		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		// Front page: parse_query() set page_id = page_on_front, or a bare language homepage.
		$queried_page_id = (int) $query->get( 'page_id' );
		$is_front_page   = false;

		if ( $queried_page_id === $front_page_id ) {
			// Already the static front page.
			$is_front_page = true;
		} elseif ( $queried_page_id === 0 ) {
			// Bare language homepage. Core parses any unknown /x/y/ as `attachment=y`: leave that to core.
			$is_front_page = (
				! $query->is_archive
				&& ! $query->is_singular
				&& ! $query->get( 'attachment' )
				&& ! $query->get( 'attachment_id' )
				&& ! $query->get( 'pagename' )
				&& ! $query->get( 'name' )
				&& ! $query->get( 'p' )
				&& ! $query->get( 'post_type' )
				&& ! $query->get( 'category_name' )
				&& ! $query->get( 'tag' )
				&& ! $query->get( 's' )
			);
		}

		if ( ! $is_front_page ) {
			return;
		}

		// page_on_front may already be in the current language (after a default-language change).
		$front_page_lang = $this->get_post_language_id( $front_page_id );

		if ( $front_page_lang === $language_id ) {
			// Use it directly.
			$target_id = $front_page_id;
		} else {
			// Find the front page's translation in the current language.
			$target_id = $this->find_translated_page( $front_page_id, $language_id );

			// Every front-page translation links to the language root, so its preview lands here.
			if ( ! $target_id ) {
				$target_id = $this->find_preview_front_page( $query, $front_page_id, $language_id );
			}

			if ( ! $target_id ) {
				// No translation: show the front page as-is.
				$this->guard_editor_fallback( $front_page_id );
				$target_id = $front_page_id;
			}
		}

		// Force this query to load the correct page as a static front page.
		$query->set( 'page_id', $target_id );
		$query->set( 'post_type', 'page' );
		$query->is_page       = true;
		$query->is_singular   = true;
		$query->is_home       = false;
		$query->is_front_page = true;
	}

	/**
	 * 301-redirect a front page slug URL (/en/homepage/) to its language root.
	 *
	 * @return void
	 */
	public function redirect_front_page_slug(): void {
		if ( is_admin() || ! is_page() ) {
			return;
		}

		// Core routes feeds, pagination, embeds, robots and sitemaps to page_on_front: never redirect those.
		if (
			is_feed()
			|| is_embed()
			|| is_preview()
			|| is_trackback()
			|| is_robots()
			|| is_favicon()
			|| (int) get_query_var( 'page' ) > 1
			|| get_query_var( 'sitemap' ) !== ''
			|| get_query_var( 'sitemap-stylesheet' ) !== ''
		) {
			return;
		}

		// GET/HEAD only: a redirect drops a POST body.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$front_page_id = (int) get_option( 'page_on_front' );

		if ( $front_page_id === 0 ) {
			return;
		}

		// Collect all page IDs that serve as the front page (source + translations).
		$front_page_ids = [ $front_page_id ];

		$links = $this->get_groups_repo()->get_translations( $front_page_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			$front_page_ids[] = (int) $link->object_id;
		}

		if ( ! in_array( $post->ID, $front_page_ids, true ) ) {
			// Also match by slug: the page may share the slug without being linked.
			$front_page = get_post( $front_page_id );
			if ( ! $front_page || $post->post_name !== $front_page->post_name ) {
				return;
			}
		}

		// Act only when a front-page slug is a path segment of the URL.
		// esc_url_raw, not sanitize_text_field: percent-encoded slugs must survive.
		$request_path = ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' );
		$qs_break     = strpos( $request_path, '?' );
		$request_path = $qs_break !== false ? substr( $request_path, 0, $qs_break ) : $request_path;
		$request_path = trim( (string) wp_unslash( $request_path ), '/' );
		$segments     = $request_path === '' ? [] : explode( '/', $request_path );
		$front_slugs  = [];

		// One IN-list query instead of a cache-miss SELECT per language.
		_prime_post_caches( $front_page_ids, false, false );

		foreach ( $front_page_ids as $fpid ) {
			$fp = get_post( $fpid );

			if ( $fp instanceof \WP_Post && $fp->post_name !== '' ) {
				$front_slugs[] = $fp->post_name;
			}
		}

		if ( $front_slugs === [] || ! array_intersect( $segments, $front_slugs ) ) {
			return;
		}

		// home_url( '/' ) already carries the language prefix for every URL mode; adding a slug 301-loops.
		$canonical = home_url( '/' );

		// Compare paths parsed the same way; esc_url_raw keeps %XX octets.
		$request_uri  = ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' );
		$qs_pos       = strpos( $request_uri, '?' );
		$current_path = untrailingslashit( (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '/' ) );
		$target_path  = untrailingslashit( (string) ( wp_parse_url( $canonical, PHP_URL_PATH ) ?: '/' ) );

		if ( $current_path === $target_path ) {
			return;
		}

		// Preserve the query string.
		if ( $qs_pos !== false ) {
			$canonical .= substr( $request_uri, $qs_pos );
		}

		wp_safe_redirect( $canonical, 301 );
		exit;
	}

	/**
	 * Get the language ID for a post from its translation link.
	 *
	 * @param int $post_id Post ID.
	 * @return int Language ID, or 0 if not linked.
	 */
	private function get_post_language_id( int $post_id ): int {
		// Served from the primed translation-link cache on most front-end requests.
		$links = $this->get_groups_repo()->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) ( $link->object_id ?? 0 ) === $post_id ) {
				return (int) ( $link->language_id ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Find the published translation of a page in a specific language.
	 *
	 * @param int $source_id Source page ID.
	 * @param int $language_id Target language ID.
	 * @return int|null Translated page ID, or null.
	 */
	private function find_translated_page( int $source_id, int $language_id ): ?int {
		$links = $this->get_groups_repo()->get_translations( $source_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) $link->language_id === $language_id ) {
				$post = get_post( (int) $link->object_id );

				if ( $post && $post->post_status === 'publish' ) {
					return (int) $link->object_id;
				}
			}
		}

		return null;
	}

	/**
	 * Find the unpublished front-page translation a logged-in editor previews on the language root.
	 * Status rules of find_preview_target(): a preview_id must name this translation.
	 *
	 * @param \WP_Query $query         The main query.
	 * @param int       $front_page_id page_on_front.
	 * @param int       $language_id   Current language ID.
	 * @return int Page ID, or 0 when this is not such a preview.
	 */
	private function find_preview_front_page( \WP_Query $query, int $front_page_id, int $language_id ): int {
		$preview_id = $this->logged_in_preview_id( $query );

		if ( $preview_id < 0 && ! $this->may_edit_unpublished( 'page' ) ) {
			return 0;
		}

		$links = $this->get_groups_repo()->get_translations( $front_page_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) $link->language_id !== $language_id ) {
				continue;
			}

			$post = get_post( (int) $link->object_id );

			if ( ! $post instanceof \WP_Post ) {
				return 0;
			}

			if ( $preview_id > 0 ) {
				return $preview_id === (int) $post->ID && $this->can_preview_post( $preview_id ) ? $preview_id : 0;
			}

			if ( in_array( $post->post_status, get_post_stati( [ 'protected' => true ] ), true )
				&& current_user_can( 'edit_post', (int) $post->ID )
			) {
				return (int) $post->ID;
			}

			return 0;
		}

		return 0;
	}

	/**
	 * Filter the page_for_posts option to return the translated page ID.
	 * Core reads it in parse_request to pick the blog index, so a translated blog page gets the blog template.
	 *
	 * @param mixed $page_id The page_for_posts option value.
	 * @return mixed Translated page ID or original.
	 */
	public function filter_page_for_posts( $page_id ) {
		// Only filter on the frontend.
		if ( is_admin() ) {
			return $page_id;
		}

		$page_id     = (int) $page_id;
		$language_id = $this->router->get_current_language_id();

		if ( $page_id === 0 || $language_id === 0 ) {
			return $page_id;
		}

		// Class static, not a method static: reset_static_caches() must clear it on switch_blog.
		$cache_key = $language_id . ':' . $page_id;

		if ( array_key_exists( $cache_key, self::$page_for_posts_cache ) ) {
			return self::$page_for_posts_cache[ $cache_key ];
		}

		// Check if the posts page is already in the current language.
		$posts_page_lang = $this->get_post_language_id( $page_id );

		if ( $posts_page_lang === $language_id ) {
			self::$page_for_posts_cache[ $cache_key ] = $page_id;
			return $page_id;
		}

		// Find the translated posts page for the current language.
		remove_filter( 'option_page_for_posts', [ $this, 'filter_page_for_posts' ] );
		$target_id = $this->find_translated_page( $page_id, $language_id );
		add_filter( 'option_page_for_posts', [ $this, 'filter_page_for_posts' ] );

		$resolved                                 = $target_id ?: $page_id;
		self::$page_for_posts_cache[ $cache_key ] = $resolved;

		return $resolved;
	}

	/**
	 * Convert a query for a translation of page_for_posts into a blog index query.
	 *
	 * @param \WP_Query $query The main query.
	 * @return void
	 */
	public function resolve_translated_posts_page( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( get_option( 'show_on_front' ) !== 'page' ) {
			return;
		}

		// Get the raw (unfiltered) page_for_posts value.
		remove_filter( 'option_page_for_posts', [ $this, 'filter_page_for_posts' ] );
		$raw_posts_page_id = (int) get_option( 'page_for_posts' );
		add_filter( 'option_page_for_posts', [ $this, 'filter_page_for_posts' ] );

		if ( $raw_posts_page_id === 0 ) {
			return;
		}

		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		// Get all page IDs that are translations of the posts page.
		$posts_page_ids = [ $raw_posts_page_id ];

		$links = $this->get_groups_repo()->get_translations( $raw_posts_page_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			$posts_page_ids[] = (int) $link->object_id;
		}

		// Check if the current query is targeting any translation of the posts page.
		$queried_page_id  = (int) $query->get( 'page_id' );
		$queried_pagename = Helper::normalize_slug_for_query( (string) $query->get( 'pagename' ) );

		$is_posts_page = false;

		if ( $queried_page_id > 0 && in_array( $queried_page_id, $posts_page_ids, true ) ) {
			$is_posts_page = true;
		} elseif ( ! empty( $queried_pagename ) ) {
			// Prime first: at pre_get_posts the post cache is empty.
			_prime_post_caches( $posts_page_ids, false, false );

			foreach ( $posts_page_ids as $pid ) {
				// Full URI, not post_name: `pagename` is the full path, and a same-named page elsewhere must not match.
				if ( get_page_uri( $pid ) === $queried_pagename ) {
					$is_posts_page = true;
					break;
				}
			}
		}

		if ( ! $is_posts_page ) {
			return;
		}

		// Convert this from a page query to a blog posts query.
		$query->set( 'page_id', 0 );
		$query->set( 'pagename', '' );
		$query->set( 'post_type', 'post' );
		$query->is_page       = false;
		$query->is_singular   = false;
		$query->is_home       = true;
		$query->is_posts_page = true;
	}

	/**
	 * Resolve the correct post when duplicate slugs exist across languages.
	 * Runs before core's language-blind get_page_by_path(), which returns the first match.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return void
	 */
	public function resolve_duplicate_slug( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		// No groups: every resolver below would return nothing.
		if ( ! $this->get_groups_repo()->has_any_groups() ) {
			return;
		}

		// Pagename queries; normalised to the stored percent-encoded post_name form.
		$pagename = Helper::normalize_slug_for_query( (string) $query->get( 'pagename' ) );

		if ( ! empty( $pagename ) ) {
			// Nested page: look up by basename, then require get_page_uri() to equal the path.
			// Do not remove the URI checks: a same-basename page under another parent would be served.
			$lookup_slug = $pagename;
			$require_uri = '';

			if ( str_contains( (string) $pagename, '/' ) ) {
				$lookup_slug = wp_basename( (string) $pagename );
				$require_uri = trim( (string) $pagename, '/' );
			}

			// A preview_id loads that post first. Only this route checks the path at depth 0 too:
			// slugs are unique only among siblings. Do not harmonise with the lookups below.
			$preview_id  = $this->logged_in_preview_id( $query );
			$preview_uri = $require_uri !== '' ? $require_uri : $lookup_slug;

			if ( $preview_id > 0 ) {
				$preview_target = $this->find_preview_target( $lookup_slug, $language_id, 'page', $preview_uri, $preview_id );

				if ( $preview_target > 0 ) {
					$this->route_to_preview_target( $query, $preview_target, true );
					return;
				}
			}

			$resolved_id = $this->find_post_by_slug_and_language( $lookup_slug, $language_id, 'page' );

			if ( $resolved_id && $require_uri !== '' && get_page_uri( $resolved_id ) !== $require_uri ) {
				$resolved_id = 0;
			}

			if ( $resolved_id ) {
				$query->set( 'page_id', $resolved_id );
				$query->set( 'pagename', '' );
				$query->is_page     = true;
				$query->is_singular = true;

				// Drop parse_request's language-blind queried object.
				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}

			// Published lookup missed: a bare preview link (or a page builder's, without one) to an unpublished translation.
			if ( $preview_id === 0 || ( $preview_id === -1 && $this->may_edit_unpublished( 'page' ) ) ) {
				$preview_target = $this->find_preview_target( $lookup_slug, $language_id, 'page', $preview_uri, 0 );

				if ( $preview_target > 0 ) {
					$this->route_to_preview_target( $query, $preview_target, true );
					return;
				}
			}

			// Load the default-language post so handle_missing_translation() applies the configured action.
			$fallback_id = $this->find_fallback_post( $lookup_slug, $language_id, 'page' );

			if ( $fallback_id && $require_uri !== '' && get_page_uri( $fallback_id ) !== $require_uri ) {
				$fallback_id = 0;
			}

			if ( $fallback_id ) {
				$this->guard_editor_fallback( $fallback_id );
				$query->set( 'page_id', $fallback_id );
				$query->set( 'pagename', '' );
				$query->set( 'perflocale_all_languages', true );
				$query->is_page        = true;
				$query->is_singular    = true;
				$this->loaded_fallback = true;

				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}

			// Load any sibling: otherwise redirect_guess_404_permalink() 301s past the fallback chain.
			$any_id = $this->find_any_translated_post_by_slug( $lookup_slug, 'page', $language_id );

			if ( $any_id && $require_uri !== '' && get_page_uri( $any_id ) !== $require_uri ) {
				$any_id = 0;
			}

			if ( $any_id ) {
				$this->guard_editor_fallback( $any_id );
				$query->set( 'page_id', $any_id );
				$query->set( 'pagename', '' );
				$query->set( 'perflocale_all_languages', true );
				$query->is_page        = true;
				$query->is_singular    = true;
				$this->loaded_fallback = true;

				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}
		}

		// Name queries (regular posts), normalised the same way.
		$name = Helper::normalize_slug_for_query( (string) $query->get( 'name' ) );

		if ( ! empty( $name ) ) {
			$post_type = $query->get( 'post_type' );

			if ( empty( $post_type ) ) {
				$post_type = 'post';
			}

			// A multi-type request is left to core: the resolvers bind one type.
			if ( ! is_string( $post_type ) ) {
				return;
			}

			$preview_id = $this->logged_in_preview_id( $query );

			// Core turns a hierarchical own-query-var request into a pagename lookup with its own ID
			// condition (class-wp-query.php:2156-2217), which a `p` route cannot survive.
			if ( $preview_id >= 0 && $this->is_own_query_var_request( $query, $post_type ) ) {
				$preview_id = -1;
			}

			if ( $preview_id > 0 ) {
				$preview_target = $this->find_preview_target( $name, $language_id, $post_type, '', $preview_id );

				if ( $preview_target > 0 ) {
					$this->route_to_preview_target( $query, $preview_target, false );
					return;
				}
			}

			$resolved_id = $this->find_post_by_slug_and_language( $name, $language_id, $post_type );

			if ( $resolved_id ) {
				$query->set( 'p', $resolved_id );
				$query->set( 'name', '' );

				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}

			// Same reason as the pagename branch above.
			if ( $preview_id === 0 || ( $preview_id === -1 && $this->may_edit_unpublished( $post_type ) && ! $this->is_own_query_var_request( $query, $post_type ) ) ) {
				$preview_target = $this->find_preview_target( $name, $language_id, $post_type, '', 0 );

				if ( $preview_target > 0 ) {
					$this->route_to_preview_target( $query, $preview_target, false );
					return;
				}
			}

			// No translation: fall back to the default language.
			$fallback_id = $this->find_fallback_post( $name, $language_id, $post_type );

			if ( $fallback_id ) {
				$this->guard_editor_fallback( $fallback_id );
				$query->set( 'p', $fallback_id );
				$query->set( 'name', '' );
				$query->set( 'perflocale_all_languages', true );
				$this->loaded_fallback = true;

				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}

			// Last resort: any sibling, as in the pagename branch.
			$any_id = $this->find_any_translated_post_by_slug( $name, $post_type, $language_id );

			if ( $any_id ) {
				$this->guard_editor_fallback( $any_id );
				$query->set( 'p', $any_id );
				$query->set( 'name', '' );
				$query->set( 'perflocale_all_languages', true );
				$this->loaded_fallback = true;

				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}
		}
	}

	/**
	 * Find the first published, linked post with this slug in any other language.
	 *
	 * @param string $slug Post slug.
	 * @param string $post_type Post type.
	 * @param int    $exclude_language_id Language to exclude from the search.
	 * @return int|null
	 */
	private function find_any_translated_post_by_slug( string $slug, string $post_type, int $exclude_language_id ): ?int {
		// Lowest ID wins (candidates are ordered by ID).
		foreach ( $this->find_slug_candidates( $slug, $post_type ) as $row ) {
			if ( (int) $row->language_id !== $exclude_language_id ) {
				return (int) $row->ID;
			}
		}

		return null;
	}

	/**
	 * Every published, linked post sharing a slug, with its language; memoised per blog, type and slug.
	 * Published only: the preview lookup is separate and must not widen this or its memo.
	 *
	 * @param string $slug      Post slug.
	 * @param string $post_type Post type.
	 * @return array<int, object> Rows with ID + language_id, ordered by ID.
	 */
	private function find_slug_candidates( string $slug, string $post_type ): array {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$key  = $blog . '|' . $post_type . '|' . $slug;

		if ( array_key_exists( $key, $this->slug_candidates_memo ) ) {
			return $this->slug_candidates_memo[ $key ];
		}

		// Cap guards third-party loops over many slugs.
		if ( count( $this->slug_candidates_memo ) > 8 ) {
			$this->slug_candidates_memo = [];
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, tl.language_id FROM {$wpdb->posts} p
				INNER JOIN %i tl ON tl.object_id = p.ID
				INNER JOIN %i tg ON tg.id = tl.group_id AND tg.type = 'post'
				WHERE p.post_name = %s AND p.post_type = %s AND p.post_status = 'publish'
				ORDER BY p.ID ASC",
				$links_table,
				$groups_table,
				$slug,
				$post_type
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->slug_candidates_memo[ $key ] = $rows;

		return $rows;
	}

	/**
	 * Find a post by slug filtered by language.
	 *
	 * @param string $slug Post slug.
	 * @param int    $language_id Language ID.
	 * @param string $post_type Post type.
	 * @return int|null Post ID or null.
	 */
	private function find_post_by_slug_and_language( string $slug, int $language_id, string $post_type ): ?int {
		foreach ( $this->find_slug_candidates( $slug, $post_type ) as $row ) {
			if ( (int) $row->language_id === $language_id ) {
				return (int) $row->ID;
			}
		}

		return null;
	}

	/**
	 * Find a fallback post in the default language when no translation exists.
	 *
	 * @param string $slug Post slug.
	 * @param int    $language_id Current language ID (to exclude - already checked).
	 * @param string $post_type Post type.
	 * @return int|null Post ID or null.
	 */
	private function find_fallback_post( string $slug, int $language_id, string $post_type ): ?int {
		$default = $this->router->get_default_language();

		if ( ! $default || (int) $default->id === $language_id ) {
			return null;
		}

		return $this->find_post_by_slug_and_language( $slug, (int) $default->id, $post_type );
	}

	/**
	 * Whether the main query is a logged-in preview, and which post it names.
	 *
	 * @param \WP_Query $query The main query.
	 * @return int -1 when this is not a logged-in preview, 0 when the preview
	 *             names no post (a bare preview=true link), otherwise the
	 *             preview_id.
	 */
	private function logged_in_preview_id( \WP_Query $query ): int {
		if ( ! $query->is_preview() || ! is_user_logged_in() ) {
			return -1;
		}

		// With preview_nonce, core's _show_post_preview() verified this (int) id at init.
		// Without it the id is only a hint: every caller still requires edit_post.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview_id = isset( $_GET['preview_id'] ) && is_scalar( $_GET['preview_id'] ) ? (int) $_GET['preview_id'] : 0;

		return max( 0, $preview_id );
	}

	/**
	 * Whether a logged-in user may edit posts of this type; gates the unpublished-translation lookup.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function may_edit_unpublished( string $post_type ): bool {
		$type = is_user_logged_in() ? get_post_type_object( $post_type ) : null;
		$cap  = $type !== null ? ( $type->cap->edit_posts ?? null ) : null;

		return is_string( $cap ) && current_user_can( $cap );
	}

	/**
	 * Whether a hierarchical type is requested through its own query var.
	 * Core resolves that by path after pre_get_posts and drops a post routed by `p`.
	 *
	 * @param \WP_Query $query     The main query.
	 * @param string    $post_type Post type.
	 * @return bool
	 */
	private function is_own_query_var_request( \WP_Query $query, string $post_type ): bool {
		$type = get_post_type_object( $post_type );

		return $type !== null && $type->hierarchical && $type->query_var && ! empty( $query->get( (string) $type->query_var ) );
	}

	/**
	 * Refuses a page-builder link that would load this post of another language (the guard loads only here).
	 *
	 * @param int $post_id The post about to load.
	 * @return void
	 */
	private function guard_editor_fallback( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $_GET !== [] && is_user_logged_in() ) {
			EditorFallbackGuard::check(
				$post_id,
				fn(): string => EditorFallbackGuard::language_name( $this->router->get_active_languages(), $this->get_post_language_id( $post_id ) )
			);
		}
	}

	/**
	 * Find the post a logged-in editor is previewing under a slug URL.
	 * Not memoised: the answer depends on the current user. Without a preview_id, protected statuses only, never private.
	 *
	 * @param string $slug        post_name to match.
	 * @param int    $language_id Current language ID.
	 * @param string $post_type   Post type.
	 * @param string $require_uri Page path the post must have, or '' for no path check.
	 * @param int    $preview_id  Post named by preview_id, or 0 to look the slug up.
	 * @return int Post ID, or 0.
	 */
	private function find_preview_target( string $slug, int $language_id, string $post_type, string $require_uri, int $preview_id ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		if ( $preview_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i tl ON tl.object_id = p.ID AND tl.language_id = %d
					INNER JOIN %i tg ON tg.id = tl.group_id AND tg.type = 'post'
					WHERE p.ID = %d AND p.post_name = %s AND p.post_type = %s",
					$wpdb->posts,
					$links_table,
					$language_id,
					$groups_table,
					$preview_id,
					$slug,
					$post_type
				)
			);
		} else {
			$statuses = array_values( get_post_stati( [ 'protected' => true ] ) );

			if ( $statuses === [] ) {
				return 0;
			}

			$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p
					INNER JOIN %i tl ON tl.object_id = p.ID AND tl.language_id = %d
					INNER JOIN %i tg ON tg.id = tl.group_id AND tg.type = 'post'
					WHERE p.post_name = %s AND p.post_type = %s AND p.post_status IN ( {$status_placeholders} )
					ORDER BY p.ID ASC",
					array_merge( [ $wpdb->posts, $links_table, $language_id, $groups_table, $slug, $post_type ], $statuses )
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$ids = array_map( 'intval', (array) $ids );

		if ( count( $ids ) > 1 ) {
			_prime_post_caches( $ids, false, false );
		}

		foreach ( $ids as $id ) {
			if ( $require_uri !== '' && get_page_uri( $id ) !== $require_uri ) {
				continue;
			}

			if ( $this->can_preview_post( $id ) ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Whether a preview may be routed to this post: editable, and kept by WP_Query's own status gate.
	 * Core's literal caps (edit_post, read_post for private), not Helper::object_permission: a wider check routes posts WP_Query drops.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function can_preview_post( int $post_id ): bool {
		$status = get_post_status_object( (string) get_post_status( $post_id ) );

		if ( ! $status || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		if ( $status->public || $status->protected ) {
			return true;
		}

		return $status->private && current_user_can( 'read_post', $post_id );
	}

	/**
	 * Point the main query at the post find_preview_target() chose.
	 * No fallback flags: the post is in the current language.
	 *
	 * @param \WP_Query $query   The main query.
	 * @param int       $post_id Post to load.
	 * @param bool      $is_page Whether the request came through `pagename`.
	 * @return void
	 */
	private function route_to_preview_target( \WP_Query $query, int $post_id, bool $is_page ): void {
		if ( $is_page ) {
			$query->set( 'page_id', $post_id );
			$query->set( 'pagename', '' );
			$query->is_page     = true;
			$query->is_singular = true;
		} else {
			$query->set( 'p', $post_id );
			$query->set( 'name', '' );
		}

		$query->queried_object    = null;
		$query->queried_object_id = 0;
	}

	/**
	 * Prevent WordPress canonical redirect when a fallback post was loaded.
	 * Otherwise it redirects before handle_missing_translation() can apply the configured action.
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $requested_url The original requested URL.
	 * @return string|false URL to redirect to, or false to cancel.
	 */
	public function prevent_fallback_canonical_redirect( $redirect_url, $requested_url ) {
		if ( $this->loaded_fallback ) {
			return false;
		}

		// Also when the post's language differs from the URL's: handle_missing_translation() decides.
		if ( is_singular() ) {
			$post = get_queried_object();

			if ( $post instanceof \WP_Post ) {
				$current_lang_id = $this->router->get_current_language_id();
				$post_lang_id    = $this->get_post_language_id( (int) $post->ID );

				if ( $current_lang_id > 0 && $post_lang_id > 0 && $current_lang_id !== $post_lang_id ) {
					return false;
				}

				// Unlinked attachments are served in place under a non-default prefix.
				if ( $current_lang_id > 0 && $post_lang_id === 0 && is_attachment() && ! $this->router->is_default_language() ) {
					return false;
				}
			}
		}

		return $redirect_url;
	}

	/**
	 * Mark a WP_Query with the current language ID for modify_query_clauses().
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return void
	 */
	public function filter_by_language( \WP_Query $query ): void {
		if ( $query->is_main_query() ) {
			unset( self::$unscoped_main_listing[ get_current_blog_id() ] );
		}

		// Skip in admin (handled by admin list column instead).
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Skip if explicitly asked to include all languages.
		if ( $query->get( 'perflocale_all_languages' ) ) {
			return;
		}

		// Skip nav menu and widget queries to avoid unintended filtering.
		if ( $query->get( 'suppress_filters' ) ) {
			return;
		}

		/**
		 * Filter whether to include all languages in this query.
		 *
		 * @param bool $include Whether to include all languages.
		 * @param \WP_Query $query The query object.
		 */
		if ( apply_filters( 'perflocale/query/include_all_languages', false, $query ) ) {
			$this->remember_unscoped_main_listing( $query );

			return;
		}

		// Keep an explicit language stamp (filter_rest_search_query()).
		if ( (int) $query->get( 'perflocale_language_id' ) > 0 ) {
			return;
		}

		// Editor REST reads run on the unprefixed REST base (default language): show all languages, as in admin lists.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Taxonomy archives: the term already scopes the posts; a post filter 404s another language's term.
		if ( $query->is_main_query() && ( $query->is_tax() || $query->is_category() || $query->is_tag() ) ) {
			return;
		}

		// `p` / `page_id` are exempt on ANY query: a named id can only be deleted by the language WHERE
		// (WooCommerce re-queries the product by `p` in a secondary query). `is_attachment` stays main-only.
		if (
			(int) $query->get( 'p' ) > 0
			|| (int) $query->get( 'page_id' ) > 0
			|| ( $query->is_main_query() && $query->is_attachment )
		) {
			return;
		}

		// Only filter translatable post types.
		$post_type = $query->get( 'post_type' );

		if ( empty( $post_type ) ) {
			$post_type = 'post';
		}

		if ( is_string( $post_type ) ) {
			$post_type = [ $post_type ];
		}

		// Child types (product_variation) inherit their parent's language.
		$child_types = apply_filters( 'perflocale/query/child_post_types', [ 'product_variation' ] );
		$post_type   = array_diff( (array) $post_type, $child_types );

		// Never-scoped types: not folded into $child_types, which means "inherits from a parent".
		$post_type = array_diff( $post_type, $this->never_scoped_post_types() );

		$translatable = $this->settings->get_translatable_post_types();

		// If none of the queried post types are translatable, skip.
		$overlap = array_intersect( $post_type, $translatable );

		if ( empty( $overlap ) ) {
			$this->remember_unscoped_main_listing( $query );

			return;
		}

		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		// Store language ID for the posts_clauses filter.
		$query->set( 'perflocale_language_id', $language_id );
	}

	/**
	 * Record a front-end main query left unscoped for a reason that holds in every language.
	 *
	 * @param \WP_Query $query The query.
	 * @return void
	 */
	private function remember_unscoped_main_listing( \WP_Query $query ): void {
		if ( $query->is_main_query() ) {
			self::$unscoped_main_listing[ get_current_blog_id() ] = spl_object_id( $query );
		}
	}

	/**
	 * Whether this main query lists the same rows in every language (left unscoped, no language id).
	 *
	 * @param \WP_Query $query This request's main query.
	 * @return bool
	 */
	public static function is_unscoped_main_listing( \WP_Query $query ): bool {
		return ( self::$unscoped_main_listing[ get_current_blog_id() ] ?? 0 ) === spl_object_id( $query )
			&& (int) $query->get( 'perflocale_language_id' ) <= 0;
	}

	/**
	 * Language ID to scope a sitewide comment feed by, or 0 (singular feeds are scoped by their post).
	 *
	 * @param \WP_Query $query The comment feed's query.
	 * @return int Language ID, or 0 to skip.
	 */
	private function comment_feed_language_id( $query ): int {
		if ( is_admin()
			|| ! $query instanceof \WP_Query
			|| ! $query->is_comment_feed
			|| $query->is_singular
			|| ! $this->get_groups_repo()->has_any_groups()
		) {
			return 0;
		}

		return $this->router->get_current_language_id();
	}

	/**
	 * JOIN the translation tables into a sitewide comment-feed query.
	 *
	 * @param mixed     $join  Current JOIN SQL.
	 * @param \WP_Query $query The comment feed's query.
	 * @return mixed
	 */
	public function scope_comment_feed_join( $join, $query ) {
		if ( $this->comment_feed_language_id( $query ) === 0 ) {
			return $join;
		}

		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias + Schema-owned table names, no user input.
		return $join
			. " LEFT JOIN ( {$links_table} AS pl_cfeed"
			. " INNER JOIN {$groups_table} AS pl_cfeed_g"
			. " ON pl_cfeed_g.id = pl_cfeed.group_id AND pl_cfeed_g.type = 'post' )"
			. " ON pl_cfeed.object_id = {$wpdb->posts}.ID";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Restrict a sitewide comment feed to the current language's posts, plus unmanaged posts.
	 *
	 * @param mixed     $where Current WHERE SQL.
	 * @param \WP_Query $query The comment feed's query.
	 * @return mixed
	 */
	public function scope_comment_feed_where( $where, $query ) {
		$language_id = $this->comment_feed_language_id( $query );

		if ( $language_id === 0 ) {
			return $where;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias is hardcoded; value bound via prepare.
		return $where . $wpdb->prepare(
			' AND ( ( pl_cfeed.language_id = %d AND pl_cfeed_g.id IS NOT NULL ) OR pl_cfeed_g.id IS NULL )',
			$language_id
		);
	}

	/**
	 * Scope /wp/v2/search to the language of the edited post (`perflocale_post`).
	 * An unassigned post uses the default language, not all languages.
	 *
	 * @param array<string, mixed> $query_args Query arguments for WP_Query.
	 * @param \WP_REST_Request     $request    REST request.
	 * @return array<string, mixed>
	 */
	public function filter_rest_search_query( array $query_args, \WP_REST_Request $request ): array {
		$this->rest_search_target_slug = '';

		$post_id_param = $request->get_param( 'perflocale_post' );
		$post_id       = is_scalar( $post_id_param ) ? absint( (string) $post_id_param ) : 0;

		if ( $post_id <= 0 ) {
			return $query_args;
		}

		$language_id = $this->detect_post_language_id( $post_id );

		if ( $language_id > 0 ) {
			$query_args['perflocale_language_id'] = $language_id;
			// Strict: an unmanaged post's permalink lacks the language prefix, so the picker must not offer it.
			$query_args['perflocale_strict_language'] = true;

			// Target slug for the result URL rewrite.
			$slug = $this->lookup_language_slug_by_id( $language_id );

			if ( $slug !== '' ) {
				$this->rest_search_target_slug = $slug;
			}
		}

		return $query_args;
	}

	/**
	 * Force every REST search-result URL into the language the picker is scoped to.
	 * On rest_request_after_callbacks, not rest_post_dispatch, which skips rest_do_request() callers.
	 *
	 * @param \WP_REST_Response $response Outgoing REST response.
	 * @param array             $handler  Route handler descriptor.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function filter_rest_search_response_urls( $response, $handler, $request ) {
		unset( $handler );

		if ( $this->rest_search_target_slug === '' ) {
			return $response;
		}

		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( $request->get_route() !== '/wp/v2/search' ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$converter   = \PerfLocale\Plugin::get_instance()->get( 'url_converter' );
		$target_slug = $this->rest_search_target_slug;
		$changed     = false;

		foreach ( $data as $index => $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}

			$converted = $converter->convert( (string) $row['url'], $target_slug );

			if ( $converted !== $row['url'] ) {
				$data[ $index ]['url'] = $converted;
				$changed               = true;
			}
		}

		if ( $changed ) {
			$response->set_data( $data );
		}

		// Single shot.
		$this->rest_search_target_slug = '';

		return $response;
	}

	/**
	 * Look up an active language's slug by id.
	 *
	 * @param int $language_id Language ID.
	 * @return string Slug, or '' if not found.
	 */
	private function lookup_language_slug_by_id( int $language_id ): string {
		$cache = \PerfLocale\Plugin::get_instance()->get( 'cache' );
		$repo  = new \PerfLocale\Database\Repository\LanguageRepository( $cache );

		foreach ( $repo->get_active() as $lang ) {
			if ( (int) $lang->id === $language_id ) {
				return (string) ( $lang->slug ?? '' );
			}
		}

		return '';
	}

	/**
	 * Resolve a post's language id, or the default language when unassigned.
	 * Same policy as TermQueryFilter::detect_post_language_id().
	 *
	 * @param int $post_id Post ID.
	 * @return int Language ID (0 only when there is no default language).
	 */
	private function detect_post_language_id( int $post_id ): int {
		// Keyed by blog: the same post_id can map to another language on another subsite.
		$key = get_current_blog_id() . ':' . $post_id;

		if ( isset( self::$detect_post_lang_cache[ $key ] ) ) {
			return self::$detect_post_lang_cache[ $key ];
		}

		$links = $this->get_groups_repo()->get_translations( $post_id, ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) $link->object_id === $post_id && isset( $link->language_id ) ) {
				self::$detect_post_lang_cache[ $key ] = (int) $link->language_id;
				return self::$detect_post_lang_cache[ $key ];
			}
		}

		$default                              = $this->router->get_default_language();
		self::$detect_post_lang_cache[ $key ] = $default ? (int) $default->id : 0;

		return self::$detect_post_lang_cache[ $key ];
	}

	/**
	 * Join translation links and filter by language on queries marked with perflocale_language_id.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param \WP_Query             $query The query.
	 * @return array<string, string> Modified clauses.
	 */
	public function modify_query_clauses( array $clauses, \WP_Query $query ): array {
		$language_id = (int) $query->get( 'perflocale_language_id' );

		if ( $language_id === 0 ) {
			return $clauses;
		}

		// No groups: the clause would keep every post, so leave core's SQL untouched.
		if ( ! $this->get_groups_repo()->has_any_groups() ) {
			return $clauses;
		}

		// Strict (perflocale_strict_language): no unlinked fallback, for pickers. Others admit unmanaged posts.
		$strict    = (bool) $query->get( 'perflocale_strict_language' );
		$cache_key = $language_id . ( $strict ? ':s' : ':f' );

		if ( isset( self::$query_clauses_cache[ $cache_key ] ) ) {
			$cached           = self::$query_clauses_cache[ $cache_key ];
			$clauses['join']  = ( $clauses['join'] ?? '' ) . $cached['join'];
			$clauses['where'] = ( $clauses['where'] ?? '' ) . $cached['where'];
			return $clauses;
		}

		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// LEFT JOIN, not NOT IN. object_id is a post OR term id: the nested INNER JOIN on type 'post'
		// keeps a colliding term link out (it would duplicate rows and leak across languages).
		// Table names are Schema::table() values: the fragment is returned to core, so it cannot be prepared.
		$alias = self::LANGUAGE_ALIAS;

		$join_sql = " LEFT JOIN ( {$links_table} AS {$alias}"
			. " INNER JOIN {$groups_table} AS {$alias}_g"
			. " ON {$alias}_g.id = {$alias}.group_id AND {$alias}_g.type = 'post' )"
			. " ON {$alias}.object_id = {$wpdb->posts}.ID";

		// Strict still admits non-translatable and never-scoped types (language-neutral URLs). Strict callers
		// set the language id themselves and skip filter_by_language(), so never-scoped types are subtracted here too.
		$translatable = array_values(
			array_diff( $this->settings->get_translatable_post_types(), $this->never_scoped_post_types() )
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is a hardcoded string constant, not user input.
		if ( $strict ) {
			if ( $translatable !== [] ) {
				$placeholders = implode( ', ', array_fill( 0, count( $translatable ), '%s' ) );
				$where_sql    = $wpdb->prepare(
					" AND ( ( {$alias}.language_id = %d AND {$alias}_g.id IS NOT NULL )"
						. " OR {$wpdb->posts}.post_type NOT IN ( {$placeholders} ) )",
					array_merge( [ $language_id ], array_values( $translatable ) )
				);
			} else {
				$where_sql = $wpdb->prepare(
					" AND {$alias}.language_id = %d AND {$alias}_g.id IS NOT NULL",
					$language_id
				);
			}
		} else {
			$where_sql = self::language_where_sql( $language_id );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::$query_clauses_cache[ $cache_key ] = [
			'join'  => $join_sql,
			'where' => $where_sql,
		];

		$clauses['join']  = ( $clauses['join'] ?? '' ) . $join_sql;
		$clauses['where'] = ( $clauses['where'] ?? '' ) . $where_sql;

		return $clauses;
	}

	/**
	 * Record the exact COUNT SQL for a query optimize_found_rows() flagged.
	 * On posts_clauses_request at PHP_INT_MAX, not in modify_query_clauses(): only the final clauses match what SQL_CALC counted.
	 *
	 * @param mixed     $clauses Final WP_Query clauses (array; typed mixed because
	 *                           any filter callback earlier in the chain may
	 *                           return something else).
	 * @param \WP_Query $query   The query.
	 * @return mixed Unmodified clauses.
	 */
	public function stash_found_rows_count( $clauses, \WP_Query $query ) {
		if ( ! is_array( $clauses ) ) {
			return $clauses;
		}

		if ( ! $query->get( 'perflocale_optimize_found_rows' ) ) {
			return $clauses;
		}

		$has_limits = trim( (string) ( $clauses['limits'] ?? '' ) ) !== '';
		$sql        = '';

		if ( $has_limits ) {
			$sql = self::count_sql( $clauses );
		}

		// Drop dead entries in long runs: a recycled spl_object_id could match one.
		if ( count( self::$found_rows_data ) > 50 ) {
			self::$found_rows_data = [];
		}

		self::$found_rows_data[ spl_object_id( $query ) ] = [
			'sql'        => $sql,
			'has_limits' => $has_limits,
		];

		return $clauses;
	}

	/**
	 * Drop the found-rows stash when another plugin short-circuits the query.
	 * That plugin (ElasticPress) supplies its own found_posts.
	 *
	 * @param array<int, \WP_Post|int>|null $posts Short-circuit result, or null.
	 * @param \WP_Query                     $query The query.
	 * @return array<int, \WP_Post|int>|null Unmodified $posts.
	 */
	public function unstash_on_short_circuit( $posts, \WP_Query $query ) {
		if ( $posts !== null && $query->get( 'perflocale_optimize_found_rows' ) ) {
			unset( self::$found_rows_data[ spl_object_id( $query ) ] );
		}

		return $posts;
	}

	/**
	 * Keep the final clauses of the front-end main query on page 2 or later.
	 *
	 * @param mixed     $clauses Final clauses (array in normal flow).
	 * @param \WP_Query $query   The query.
	 * @return mixed Unmodified clauses.
	 */
	public function remember_main_listing( $clauses, \WP_Query $query ) {
		if ( ! $query->is_main_query() || is_admin() ) {
			return $clauses;
		}

		$blog_id     = get_current_blog_id();
		$language_id = (int) $query->get( 'perflocale_language_id' );

		unset( self::$main_listing[ $blog_id ] );

		if ( ! is_array( $clauses ) || $language_id <= 0 || (int) $query->get( 'paged' ) < 2 ) {
			return $clauses;
		}

		self::$main_listing[ $blog_id ] = [
			'oid'         => spl_object_id( $query ),
			'language_id' => $language_id,
			'confirmed'   => false,
			'request'     => null,
			'distinct'    => (string) ( $clauses['distinct'] ?? '' ),
			'fields'      => (string) ( $clauses['fields'] ?? '' ),
			'join'        => (string) ( $clauses['join'] ?? '' ),
			'where'       => (string) ( $clauses['where'] ?? '' ),
			'groupby'     => (string) ( $clauses['groupby'] ?? '' ),
			'orderby'     => (string) ( $clauses['orderby'] ?? '' ),
			'limits'      => (string) ( $clauses['limits'] ?? '' ),
		];

		return $clauses;
	}

	/**
	 * Keep the request core built for the kept main listing, before posts_request callbacks.
	 *
	 * @param mixed     $request The SQL request.
	 * @param \WP_Query $query   The query.
	 * @return mixed Unmodified request.
	 */
	public function capture_main_listing_request( $request, \WP_Query $query ) {
		$blog_id = get_current_blog_id();

		if ( isset( self::$main_listing[ $blog_id ] ) && self::$main_listing[ $blog_id ]['oid'] === spl_object_id( $query ) ) {
			self::$main_listing[ $blog_id ]['request'] = is_string( $request ) ? $request : null;
		}

		return $request;
	}

	/**
	 * Confirm the kept main-listing clauses are the ones the main query runs, else drop them.
	 *
	 * @param mixed     $posts Short-circuit result, or null.
	 * @param \WP_Query $query The query.
	 * @return mixed Unmodified $posts.
	 */
	public function confirm_main_listing( $posts, \WP_Query $query ) {
		$blog_id = get_current_blog_id();
		$kept    = self::$main_listing[ $blog_id ] ?? null;

		if ( null === $kept || spl_object_id( $query ) !== $kept['oid'] ) {
			return $posts;
		}

		$request = (string) $query->request;

		if (
			null !== $posts
			|| null === $kept['request']
			|| $request !== $kept['request']
			|| ! str_contains( $request, $kept['where'] )
			|| ! str_contains( $request, $kept['join'] )
		) {
			unset( self::$main_listing[ $blog_id ] );

			return $posts;
		}

		self::$main_listing[ $blog_id ]['confirmed'] = true;

		return $posts;
	}

	/**
	 * Whether page N of this request's main listing has rows in each given language.
	 * Answered from the found-rows group (exact count, else lower bound, else one bounded row check).
	 *
	 * @param \WP_Query $query        This request's main query.
	 * @param int[]     $language_ids Languages to answer.
	 * @return array<int, bool>|null Page exists, by language id; null when the
	 *                               main query's clauses cannot answer (see
	 *                               confirmed_listing(), an unrecognised LIMIT,
	 *                               or a database error).
	 */
	public static function listing_page_exists( \WP_Query $query, array $language_ids ): ?array {
		global $wpdb;

		$listing = self::confirmed_listing( $query );

		if ( null === $listing ) {
			return null;
		}

		$kept     = $listing['clauses'];
		$own      = $listing['own'];
		$limits   = trim( $kept['limits'] );
		$start    = 0;
		$per_page = 0;

		if ( '' !== $limits ) {
			if ( 1 !== preg_match( '/^LIMIT\s+(\d+)\s*,\s*(\d+)$/i', $limits, $m ) ) {
				return null;
			}

			$start    = (int) $m[1];
			$per_page = (int) $m[2];
		}

		$cache = \PerfLocale\Plugin::get_instance()->get( 'cache' );

		if ( ! $cache instanceof \PerfLocale\Cache\CacheManager ) {
			return null;
		}

		$targets = [];

		foreach ( $language_ids as $language_id ) {
			$language_id = (int) $language_id;

			if ( $language_id <= 0 || isset( $targets[ $language_id ] ) ) {
				continue;
			}

			$clauses          = $kept;
			$clauses['where'] = str_replace( $own, self::language_where_sql( $language_id ), $kept['where'] );
			$count_sql        = self::count_sql( $clauses );

			$targets[ $language_id ] = [
				'clauses' => $clauses,
				'exact'   => self::found_rows_key( $count_sql ),
				'bound'   => self::found_rows_key( $count_sql, 'frlo' ),
			];
		}

		if ( [] === $targets ) {
			return [];
		}

		// Without a persistent object cache the entries are option rows: read
		// every value and timeout row of every language in one query.
		if ( ! wp_using_ext_object_cache() ) {
			$names = [];

			foreach ( $targets as $target ) {
				foreach ( [ $target['exact'], $target['bound'] ] as $key ) {
					$name    = $cache->derive_transient_key( $key, self::FOUND_ROWS_GROUP );
					$names[] = '_transient_' . $name;
					$names[] = '_transient_timeout_' . $name;
				}
			}

			wp_prime_option_caches( $names );
		}

		$lookahead = max( self::LISTING_LOOKAHEAD_MIN, 5 * $per_page );
		$exists    = [];
		$store     = [];

		foreach ( $targets as $language_id => $target ) {
			$exact = self::read_found_rows_entry( $cache, $target['exact'] );

			if ( null !== $exact ) {
				$exists[ $language_id ] = $exact > $start;
				continue;
			}

			$lo = self::read_found_rows_entry( $cache, $target['bound'] );

			if ( null !== $lo && $start < $lo ) {
				$exists[ $language_id ] = true;
				continue;
			}

			$cap = self::listing_check_cap( $start, $lookahead, $lo );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Statement assembled from the main query's final clauses plus the prepared language condition; identifiers through %i; the result is cached with the generation.
			$rows = $wpdb->get_var( self::bounded_count_sql( $target['clauses'], $cap ) );

			if ( ! is_numeric( $rows ) ) {
				self::store_found_rows_entries( $cache, $store );

				return null;
			}

			$rows = (int) $rows;

			if ( $rows < $cap ) {
				$store[ $target['exact'] ] = $rows;
				$exists[ $language_id ]    = $rows > $start;
			} else {
				$store[ $target['bound'] ] = $cap;
				$exists[ $language_id ]    = true;
			}
		}

		self::store_found_rows_entries( $cache, $store );

		return $exists;
	}

	/**
	 * The kept, confirmed main-listing clauses and own language condition, or null.
	 * Null unless the condition appears once, GROUP BY is only the post id, and DISTINCT selects rows or ids.
	 *
	 * @param \WP_Query $query The main query.
	 * @return array{clauses: array{oid: int, language_id: int, confirmed: bool, request: ?string, distinct: string, fields: string, join: string, where: string, groupby: string, orderby: string, limits: string}, own: string}|null
	 */
	private static function confirmed_listing( \WP_Query $query ): ?array {
		global $wpdb;

		$kept = self::$main_listing[ get_current_blog_id() ] ?? null;

		if ( null === $kept || true !== $kept['confirmed'] || spl_object_id( $query ) !== $kept['oid'] ) {
			return null;
		}

		$own = self::language_where_sql( $kept['language_id'] );

		if ( 1 !== substr_count( $kept['where'], $own ) ) {
			return null;
		}

		$groupby  = trim( $kept['groupby'] );
		$distinct = trim( $kept['distinct'] );
		$fields   = trim( $kept['fields'] );

		if ( '' !== $groupby && "{$wpdb->posts}.ID" !== $groupby ) {
			return null;
		}

		if ( '' !== $distinct && "{$wpdb->posts}.*" !== $fields && "{$wpdb->posts}.ID" !== $fields ) {
			return null;
		}

		return [
			'clauses' => $kept,
			'own'     => $own,
		];
	}

	/**
	 * Most rows a bounded check reads: the page plus look-ahead, or twice a passed lower bound.
	 * Doubling is capped at 1.5x the page plus look-ahead, so a crawl needs few checks without a large read.
	 *
	 * @param int      $start     Offset of the page's first row.
	 * @param int      $lookahead Rows read beyond the page's first row.
	 * @param int|null $lo        Stored lower bound of the language's rows, or null.
	 * @return int
	 */
	private static function listing_check_cap( int $start, int $lookahead, ?int $lo ): int {
		$base = $start + 1 + $lookahead;

		return max( $base, min( 2 * ( $lo ?? 0 ), intdiv( 3 * $base, 2 ) ) );
	}

	/**
	 * Count this listing in every active language with one grouped statement, and store each count.
	 * Valid only when the language condition is a conjunct: the 1=0 probe proves it. See docs-ai/11-code-rationale.md, "PostQueryFilter: grouped listing count".
	 *
	 * @param \WP_Query                      $query     The main query.
	 * @param string                         $count_sql The query's own COUNT statement (count_sql()).
	 * @param \PerfLocale\Cache\CacheManager $cache     Cache manager.
	 * @return int|null This language's count, or null when it was not counted here.
	 */
	private function count_listing_in_languages( \WP_Query $query, string $count_sql, \PerfLocale\Cache\CacheManager $cache ): ?int {
		global $wpdb;

		if ( $query->is_search() || ! $this->settings->hreflang_enabled() ) {
			return null;
		}

		$listing = self::confirmed_listing( $query );

		if ( null === $listing || self::count_sql( $listing['clauses'] ) !== $count_sql ) {
			return null;
		}

		$clauses = $listing['clauses'];
		$own     = $listing['own'];
		$alias   = self::LANGUAGE_ALIAS;
		$grouped = '' !== trim( $clauses['distinct'] ) || '' !== trim( $clauses['groupby'] );
		$from    = "FROM {$wpdb->posts} " . $clauses['join'] . ' WHERE 1=1 ';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The main query's final clauses with the prepared language condition replaced by a constant; nothing is cached from it.
		$admitted = $wpdb->get_var( (string) $wpdb->remove_placeholder_escape( 'SELECT 1 ' . $from . str_replace( $own, ' AND ( 1=0 )', $clauses['where'] ) . ' LIMIT 1' ) );
		$error    = (string) $wpdb->last_error;

		if ( '' !== $error || null !== $admitted ) {
			return null;
		}

		$select = $grouped ? "COUNT(DISTINCT {$wpdb->posts}.ID)" : 'COUNT(*)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The main query's final clauses with the prepared language condition replaced by a constant, grouped by the language columns of the JOIN modify_query_clauses() adds; the counts are cached with the generation.
		$rows  = $wpdb->get_results( (string) $wpdb->remove_placeholder_escape( "SELECT CASE WHEN {$alias}_g.id IS NULL THEN 0 ELSE {$alias}.language_id END AS pfl_language, {$select} AS pfl_rows " . $from . str_replace( $own, ' AND ( 1=1 )', $clauses['where'] ) . ' GROUP BY pfl_language' ), ARRAY_A );
		$error = (string) $wpdb->last_error;

		if ( '' !== $error || ! is_array( $rows ) ) {
			return null;
		}

		$by_language = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_numeric( $row['pfl_language'] ?? null ) || ! is_numeric( $row['pfl_rows'] ?? null ) ) {
				return null;
			}

			$by_language[ (int) $row['pfl_language'] ] = (int) $row['pfl_rows'];
		}

		$unlinked    = $by_language[0] ?? 0;
		$language_id = (int) $clauses['language_id'];
		$ids         = [ $language_id ];
		$entries     = [];

		foreach ( $this->router->get_active_languages() as $language ) {
			$ids[] = (int) ( $language->id ?? 0 );
		}

		foreach ( array_unique( $ids ) as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			$counted          = $clauses;
			$counted['where'] = str_replace( $own, self::language_where_sql( $id ), $clauses['where'] );

			$entries[ self::found_rows_key( self::count_sql( $counted ) ) ] = ( $by_language[ $id ] ?? 0 ) + $unlinked;
		}

		self::store_found_rows_entries( $cache, $entries );

		return ( $by_language[ $language_id ] ?? 0 ) + $unlinked;
	}

	/**
	 * Store found-rows entries for an hour.
	 * Without an object cache: one upsert of the exact rows set_transient() writes (value + timeout, not autoloaded).
	 *
	 * @param \PerfLocale\Cache\CacheManager $cache   Cache manager.
	 * @param array<string, int>             $entries Value by key in the found-rows group.
	 * @return void
	 */
	private static function store_found_rows_entries( \PerfLocale\Cache\CacheManager $cache, array $entries ): void {
		global $wpdb;

		if ( [] === $entries ) {
			return;
		}

		if ( wp_using_ext_object_cache() ) {
			foreach ( $entries as $key => $value ) {
				$cache->set( (string) $key, $value, HOUR_IN_SECONDS, self::FOUND_ROWS_GROUP );
			}

			return;
		}

		$expires   = (string) ( time() + HOUR_IN_SECONDS );
		$row_count = 0;
		$args      = [ $wpdb->options ];
		$written   = [];

		foreach ( $entries as $key => $value ) {
			$name = $cache->derive_transient_key( (string) $key, self::FOUND_ROWS_GROUP );
			$rows = [
				'_transient_' . $name         => (string) maybe_serialize( [ 'v' => $value ] ),
				'_transient_timeout_' . $name => $expires,
			];

			$cache->set_static( (string) $key, $value, self::FOUND_ROWS_GROUP );

			foreach ( $rows as $option => $option_value ) {
				++$row_count;
				$written[ $option ] = $option_value;
				array_push( $args, $option, $option_value );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One (%s, %s, 'no') tuple per row, all values bound; the table name through %i.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES ' . implode( ', ', array_fill( 0, $row_count, "(%s, %s, 'no')" ) ) . ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)', ...$args ) );

		// Written without update_option(): sync the options cache and its negative cache.
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$notoptions = is_array( $notoptions ) ? $notoptions : [];

		foreach ( $written as $option => $option_value ) {
			wp_cache_set( $option, $option_value, 'options' );
			unset( $notoptions[ $option ] );
		}

		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}

	/**
	 * The non-strict language condition modify_query_clauses() appends for one language.
	 *
	 * @param int $language_id Language id.
	 * @return string
	 */
	private static function language_where_sql( int $language_id ): string {
		global $wpdb;

		$alias = self::LANGUAGE_ALIAS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is a class constant.
		return (string) $wpdb->prepare( " AND ( ( {$alias}.language_id = %d AND {$alias}_g.id IS NOT NULL ) OR {$alias}_g.id IS NULL )", $language_id );
	}

	/**
	 * The COUNT statement that reproduces a query's found rows from its final clauses.
	 * Grouped or distinct queries count DISTINCT ID, as SQL_CALC_FOUND_ROWS does.
	 *
	 * @param array<mixed> $clauses Final clauses (distinct, groupby, join, where).
	 * @return string
	 */
	private static function count_sql( array $clauses ): string {
		global $wpdb;

		$distinct = trim( (string) ( $clauses['distinct'] ?? '' ) );
		$groupby  = trim( (string) ( $clauses['groupby'] ?? '' ) );
		$select   = ( $distinct !== '' || $groupby !== '' )
			? "COUNT(DISTINCT {$wpdb->posts}.ID)"
			: 'COUNT(*)';

		$sql = "SELECT {$select} FROM {$wpdb->posts} "
			. (string) ( $clauses['join'] ?? '' ) . ' WHERE 1=1 ' . (string) ( $clauses['where'] ?? '' );

		// prepare() masks % with a per-request random token: unmask it, or LIKE queries never hit the cache key.
		return (string) $wpdb->remove_placeholder_escape( $sql );
	}

	/**
	 * Cache key in the found-rows group for a statement, in the current generation.
	 *
	 * @param string $sql  Normalised COUNT statement.
	 * @param string $kind 'fr' (exact count) or 'frlo' (lower bound).
	 * @return string
	 */
	private static function found_rows_key( string $sql, string $kind = 'fr' ): string {
		return $kind . ':' . \PerfLocale\Cache\CacheManager::l2_generation( self::FOUND_ROWS_GROUP ) . ':' . md5( $sql );
	}

	/**
	 * Row count of a listing's final clauses, read no further than $cap rows.
	 * The ORDER BY follows the type_status_date index in the listing's direction: it avoids a sort, never changes the count.
	 *
	 * @param array<mixed> $clauses Final clauses.
	 * @param int          $cap     Rows to read at most (>= 1).
	 * @return string
	 */
	private static function bounded_count_sql( array $clauses, int $cap ): string {
		global $wpdb;

		$grouped = '' !== trim( (string) ( $clauses['distinct'] ?? '' ) ) || '' !== trim( (string) ( $clauses['groupby'] ?? '' ) );
		$order   = '';

		if ( ! $grouped ) {
			$ascending = str_starts_with( trim( (string) ( $clauses['orderby'] ?? '' ) ), "{$wpdb->posts}.post_date ASC" );
			$order     = $ascending
				? (string) $wpdb->prepare( ' ORDER BY %i.post_type ASC, %i.post_status ASC, %i.post_date ASC', $wpdb->posts, $wpdb->posts, $wpdb->posts )
				: (string) $wpdb->prepare( ' ORDER BY %i.post_type DESC, %i.post_status DESC, %i.post_date DESC', $wpdb->posts, $wpdb->posts, $wpdb->posts );
		}

		$head = $grouped
			? (string) $wpdb->prepare( 'SELECT COUNT(*) FROM ( SELECT DISTINCT %i.ID FROM %i ', $wpdb->posts, $wpdb->posts )
			: (string) $wpdb->prepare( 'SELECT COUNT(*) FROM ( SELECT %i.ID FROM %i ', $wpdb->posts, $wpdb->posts );

		$sql = $head . (string) ( $clauses['join'] ?? '' ) . ' WHERE 1=1 ' . (string) ( $clauses['where'] ?? '' )
			. $order . ' LIMIT ' . max( 1, $cap ) . ' ) AS pfl_rows';

		return (string) $wpdb->remove_placeholder_escape( $sql );
	}

	/**
	 * One found-rows entry, as a number, or null when it is missing or expired.
	 * Reads the option rows directly: get_transient() would delete an expired row the next write updates in place.
	 *
	 * @param \PerfLocale\Cache\CacheManager $cache Cache manager.
	 * @param string                         $key   Key in the found-rows group.
	 * @return int|null
	 */
	private static function read_found_rows_entry( \PerfLocale\Cache\CacheManager $cache, string $key ): ?int {
		if ( wp_using_ext_object_cache() ) {
			$value = $cache->get_cached( $key, self::FOUND_ROWS_GROUP );

			return is_numeric( $value ) ? (int) $value : null;
		}

		$name    = $cache->derive_transient_key( $key, self::FOUND_ROWS_GROUP );
		$timeout = get_option( '_transient_timeout_' . $name );

		if ( ! is_numeric( $timeout ) || (int) $timeout < time() ) {
			return null;
		}

		$envelope = get_option( '_transient_' . $name );

		return ( is_array( $envelope ) && isset( $envelope['v'] ) && is_numeric( $envelope['v'] ) ) ? (int) $envelope['v'] : null;
	}

	/**
	 * Suppress WP core's SQL_CALC_FOUND_ROWS on the front-end main archive query.
	 * With the language LEFT JOIN it scans every matching row; set_found_posts() supplies an exact cached count.
	 *
	 * @param \WP_Query $query The query being parsed.
	 * @return void
	 */
	public function optimize_found_rows( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// A search reads every row for relevance anyway; a stored count per term would only add rows.
		if ( $query->is_search() ) {
			return;
		}

		// Respect an explicit opt-out.
		if ( $query->get( 'no_found_rows' ) ) {
			return;
		}

		// Singular views do not paginate.
		if (
			'' !== (string) $query->get( 'name' )
			|| '' !== (string) $query->get( 'pagename' )
			|| (int) $query->get( 'p' ) > 0
			|| (int) $query->get( 'page_id' ) > 0
		) {
			return;
		}

		// Other `fields` shapes can skip the_posts, which would leave found_posts at 0.
		$fields = (string) $query->get( 'fields' );
		if ( $fields !== '' && $fields !== 'all' ) {
			return;
		}

		// Only queries carrying the language JOIN (not taxonomy archives).
		if ( (int) $query->get( 'perflocale_language_id' ) === 0 ) {
			return;
		}

		if ( ! $this->get_groups_repo()->has_any_groups() ) {
			return;
		}

		/**
		 * Master switch for the found-rows optimization; false keeps core's SQL_CALC_FOUND_ROWS.
		 *
		 * @param bool      $enabled Whether to optimize this query. Default true.
		 * @param \WP_Query $query   The query being parsed.
		 */
		if ( ! apply_filters( 'perflocale/query/optimize_found_rows', true, $query ) ) {
			return;
		}

		$query->set( 'no_found_rows', true );
		$query->set( 'perflocale_optimize_found_rows', true );
	}

	/**
	 * Supply found_posts / max_num_pages for a query optimize_found_rows() changed.
	 * Mirrors WP_Query::set_found_posts() branch for branch (empty posts, LIMIT, `found_posts` filter).
	 *
	 * @param mixed     $posts Posts returned by the query (array in normal flow).
	 * @param \WP_Query $query The query.
	 * @return mixed Unmodified posts.
	 */
	public function set_found_posts( $posts, \WP_Query $query ) {
		if ( ! $query->get( 'perflocale_optimize_found_rows' ) ) {
			return $posts;
		}

		$oid  = spl_object_id( $query );
		$data = self::$found_rows_data[ $oid ] ?? null;
		unset( self::$found_rows_data[ $oid ] );

		// Empty posts keep 0, as in core. No stash: a short-circuiting plugin supplies found_posts.
		if ( $data === null || ( is_array( $posts ) && $posts === [] ) ) {
			return $posts;
		}

		if ( $data['has_limits'] ) {
			$cache = \PerfLocale\Plugin::get_instance()->get( 'cache' );

			if ( ! $cache instanceof \PerfLocale\Cache\CacheManager ) {
				return $posts;
			}

			// Generation in the key: only L2 keys are generation-prefixed, so a bump alone leaves the L3 transient stale.
			$key = self::found_rows_key( $data['sql'] );

			// is_numeric, not is_int: Redis returns stored integers as strings across requests.
			$cached = $cache->get_cached( $key, self::FOUND_ROWS_GROUP );
			$count  = is_numeric( $cached ) ? (int) $cached : null;

			// Page 2+: every language's count in one statement.
			if ( $count === null ) {
				$count = $this->count_listing_in_languages( $query, $data['sql'], $cache );
			}

			if ( $count === null ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Count SQL assembled from WP core's own clauses + the language WHERE that modify_query_clauses() already $wpdb->prepare()'d (no raw user input reaches it); a successful result is cached generationally below.
				$raw   = $wpdb->get_var( $data['sql'] );
				$count = is_numeric( $raw ) ? (int) $raw : 0;

				// A failed COUNT gives 0 and is not cached: alternates read this entry as an exact count.
				if ( is_numeric( $raw ) ) {
					$cache->set( $key, $count, HOUR_IN_SECONDS, self::FOUND_ROWS_GROUP );
				}
			}
		} else {
			// No LIMIT: core counts the returned posts.
			$count = is_array( $posts ) ? count( $posts ) : ( $posts === null ? 0 : 1 );
		}

		// Core skips the found_posts filter when no_found_rows is set.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'found_posts' is a WP core hook we deliberately re-apply, not a plugin-defined one.
		$count = (int) apply_filters_ref_array( 'found_posts', [ $count, $query ] );

		$query->found_posts = $count;

		if ( $data['has_limits'] ) {
			$posts_per_page = (int) $query->get( 'posts_per_page' );

			if ( $posts_per_page !== 0 ) {
				$query->max_num_pages = (int) ceil( $count / $posts_per_page );
			}
		}

		return $posts;
	}

	/**
	 * Bump the found_posts count-cache generation when either status is publish.
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 * @return void
	 */
	public function flush_found_rows_on_transition( $new_status, $old_status ): void {
		// publish to publish bumps too: an edit can move a post between date/author archives.
		if ( $new_status !== 'publish' && $old_status !== 'publish' ) {
			return;
		}

		$this->flush_found_rows_cache();
	}

	/**
	 * Bump the count-cache generation when a published post is permanently deleted.
	 * Deletion fires no transition_post_status.
	 *
	 * @param int          $post_id Deleted post ID (unused; kept for the hook signature).
	 * @param \WP_Post|null $post   The deleted post object (WP 5.5+), or null.
	 * @return void
	 */
	public function flush_found_rows_on_delete( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && $post->post_status !== 'publish' ) {
			return;
		}

		$this->flush_found_rows_cache();
	}

	/**
	 * Invalidate every cached found_posts count for the current blog (generation bump).
	 *
	 * @return void
	 */
	public function flush_found_rows_cache(): void {
		\PerfLocale\Cache\CacheManager::bump_group_generation( self::FOUND_ROWS_GROUP );
	}

	/**
	 * Append the language JOIN to the adjacent-post (Previous/Next) query.
	 *
	 * @param string        $join           Existing JOIN SQL.
	 * @param bool          $in_same_term   Unused (core arg).
	 * @param array|string  $excluded_terms Unused (core arg).
	 * @param string        $taxonomy       Unused (core arg).
	 * @param \WP_Post|null $post           The post whose neighbour is sought.
	 * @return string
	 */
	public function filter_adjacent_post_join( $join, $in_same_term, $excluded_terms, $taxonomy, $post ): string {
		$clauses = $this->adjacent_post_clauses( $post instanceof \WP_Post ? $post : null );

		return $clauses === null ? (string) $join : (string) $join . $clauses['join'];
	}

	/**
	 * Append the language WHERE to the adjacent-post (Previous/Next) query.
	 *
	 * @param string        $where          Existing WHERE SQL.
	 * @param bool          $in_same_term   Unused (core arg).
	 * @param array|string  $excluded_terms Unused (core arg).
	 * @param string        $taxonomy       Unused (core arg).
	 * @param \WP_Post|null $post           The post whose neighbour is sought.
	 * @return string
	 */
	public function filter_adjacent_post_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ): string {
		$clauses = $this->adjacent_post_clauses( $post instanceof \WP_Post ? $post : null );

		return $clauses === null ? (string) $where : (string) $where . $clauses['where'];
	}

	/**
	 * Memoised JOIN + WHERE scoping a Previous/Next query to the viewed post's language, or null.
	 * Keys on `p.ID`: get_adjacent_post() aliases the posts table as `p`.
	 *
	 * @param \WP_Post|null $post Viewed post.
	 * @return array{join: string, where: string}|null
	 */
	private function adjacent_post_clauses( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( array_key_exists( $post->ID, self::$adjacent_clauses_memo ) ) {
			return self::$adjacent_clauses_memo[ $post->ID ];
		}

		$result = null;

		if (
			! is_admin()
			&& in_array( $post->post_type, $this->settings->get_translatable_post_types(), true )
			&& $this->get_groups_repo()->has_any_groups()
		) {
			$language_id = $this->get_post_language_id( (int) $post->ID );

			// An unmanaged viewed post still scopes by the request language.
			if ( $language_id === 0 ) {
				$language_id = $this->router->get_current_language_id();
			}

			if ( $language_id > 0 ) {
				global $wpdb;

				$links_table  = Schema::table( 'translation_links' );
				$groups_table = Schema::table( 'translation_groups' );
				$alias        = 'pl_adjacent';

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is a hardcoded constant, not user input.
				$join = " LEFT JOIN ( {$links_table} AS {$alias}"
					. " INNER JOIN {$groups_table} AS {$alias}_g"
					. " ON {$alias}_g.id = {$alias}.group_id AND {$alias}_g.type = 'post' )"
					. " ON {$alias}.object_id = p.ID";

				// Non-strict, as in the front-end loop.
				$where = $wpdb->prepare(
					" AND ( ( {$alias}.language_id = %d AND {$alias}_g.id IS NOT NULL ) OR {$alias}_g.id IS NULL )",
					$language_id
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$result = [
					'join'  => $join,
					'where' => $where,
				];
			}
		}

		self::$adjacent_clauses_memo[ $post->ID ] = $result;

		return $result;
	}

	/**
	 * Filter get_pages() results by the current language.
	 * get_pages() (the wp_page_menu() fallback) bypasses WP_Query filtering.
	 *
	 * @param array<int, \WP_Post> $pages Pages returned by get_pages().
	 * @param array<string, mixed> $args Parsed get_pages() arguments.
	 * @return array<int, \WP_Post>
	 */
	public function filter_get_pages( array $pages, array $args ): array {
		if ( is_admin() || empty( $pages ) ) {
			return $pages;
		}

		// Class static so reset_static_caches() clears it on switch_blog.
		if ( self::$get_pages_cfg_cache === null ) {
			self::$get_pages_cfg_cache = [
				'lang_id' => $this->router->get_current_language_id(),
				'tt'      => $this->settings->get_translatable_post_types(),
			];
		}
		if ( self::$get_pages_cfg_cache['lang_id'] === 0 ) {
			return $pages;
		}

		// Gate on the queried types: one translatable member suffices, as unlinked pages are kept.
		$queried = (array) ( $args['post_type'] ?? 'page' );

		if ( array_intersect( $queried, self::$get_pages_cfg_cache['tt'] ) === [] ) {
			return $pages;
		}
		$language_id = self::$get_pages_cfg_cache['lang_id'];

		// Hydrate only the unseen IDs.
		if ( self::$page_language_map_cache === null ) {
			self::$page_language_map_cache = [];
		}

		$needs_lookup = [];

		foreach ( $pages as $page ) {
			$id = (int) $page->ID;

			if ( ! array_key_exists( $id, self::$page_language_map_cache ) ) {
				$needs_lookup[] = $id;
			}
		}

		if ( $needs_lookup !== [] ) {
			$this->hydrate_page_language_map( $needs_lookup );

			// filter_page_link() runs next for every page and needs its translations.
			$this->get_groups_repo()->prime_translations( \PerfLocale\Enum\ObjectType::Post, $needs_lookup );
		}

		$lang_map = self::$page_language_map_cache;

		// Pass 1: return $pages unchanged when nothing is dropped.
		$needs_filter = false;

		foreach ( $pages as $page ) {
			$id  = (int) $page->ID;
			$lid = $lang_map[ $id ] ?? null;

			if ( $lid !== null && $lid !== $language_id ) {
				$needs_filter = true;
				break;
			}
		}

		if ( ! $needs_filter ) {
			return $pages;
		}

		$filtered = [];

		foreach ( $pages as $page ) {
			$id  = (int) $page->ID;
			$lid = $lang_map[ $id ] ?? null;

			if ( $lid === null || $lid === $language_id ) {
				$filtered[] = $page;
			}
		}

		return $filtered;
	}

	/**
	 * Populate `page_language_map_cache` for the given post IDs; unlinked IDs get null.
	 *
	 * @param array<int, int> $post_ids Post IDs to hydrate.
	 * @return void
	 */
	private function hydrate_page_language_map( array $post_ids ): void {
		if ( $post_ids === [] ) {
			return;
		}

		// No groups: negative-cache every ID.
		$groups_repo = $this->get_groups_repo();

		if ( ! $groups_repo->has_any_groups() ) {
			foreach ( $post_ids as $pid ) {
				if ( ! array_key_exists( $pid, self::$page_language_map_cache ) ) {
					self::$page_language_map_cache[ $pid ] = null;
				}
			}

			return;
		}

		// Eager link map (null above the size caps).
		$eager_map = $groups_repo->get_eager_link_map( \PerfLocale\Enum\ObjectType::Post );

		if ( is_array( $eager_map ) ) {
			foreach ( $post_ids as $pid ) {
				$links = $eager_map[ $pid ] ?? null;

				if ( is_array( $links ) ) {
					// The post's own link is the row whose object_id matches.
					foreach ( $links as $link ) {
						if ( (int) ( $link->object_id ?? 0 ) === $pid ) {
							self::$page_language_map_cache[ $pid ] = (int) $link->language_id;
							continue 2;
						}
					}
				}

				// Unmanaged: negative cache.
				if ( ! array_key_exists( $pid, self::$page_language_map_cache ) ) {
					self::$page_language_map_cache[ $pid ] = null;
				}
			}

			return;
		}

		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names from Schema::table(), placeholders are %d.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.object_id, l.language_id
				 FROM %i l
				 INNER JOIN %i g ON l.group_id = g.id
				 WHERE g.type = 'post'
				 AND l.object_id IN ({$placeholders})",
				$links_table,
				$groups_table,
				...$post_ids
			)
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			self::$page_language_map_cache[ (int) $row->object_id ] = (int) $row->language_id;
		}

		// Negative-cache unmanaged IDs.
		foreach ( $post_ids as $pid ) {
			if ( ! array_key_exists( $pid, self::$page_language_map_cache ) ) {
				self::$page_language_map_cache[ $pid ] = null;
			}
		}
	}

	/**
	 * Apply missing_translation_action (show_default, show_404, redirect_default) on singular pages.
	 * Walks the language fallback chain first.
	 *
	 * @return void
	 */
	public function handle_missing_translation(): void {
		// Re-entry fuse: never act twice in one request.
		static $handled = false;

		if ( $handled ) {
			return;
		}

		$handled = true;

		// Landing on our own fallback redirect: never fall back again (loop guard).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_fb'] ) ) {
			return;
		}

		if ( is_admin() || ! is_singular() ) {
			return;
		}

		// GET/HEAD only: a redirect drops a POST body.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Only handle translatable post types.
		$translatable = $this->settings->get_translatable_post_types();

		if ( ! in_array( $post->post_type, $translatable, true ) ) {
			return;
		}

		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		// Is this post in the current language?
		$post_lang = $this->get_post_language_id( $post->ID );
		$default   = $this->router->get_default_language();

		// An unlinked post is default-language content.
		$unlinked_id = ( $post_lang === 0 && $default !== null ) ? (int) $post->ID : 0;

		if ( $unlinked_id > 0 ) {
			$post_lang = (int) $default->id;
		}

		if ( $post_lang === $language_id ) {
			return; // Post matches the current language.
		}

		// Reached under another language's prefix.
		$current_slug = $this->router->get_current_slug();

		// The current language's own sibling wins over the fallback chain.
		$translations_map = $unlinked_id > 0
			? [ (int) $default->id => $unlinked_id ]
			: $this->get_translations_map( $post->ID );
		$own_id           = (int) ( $translations_map[ $language_id ] ?? 0 );

		if ( $own_id > 0 && $own_id !== (int) $post->ID ) {
			$own_post = get_post( $own_id );

			if ( $own_post instanceof \WP_Post && $own_post->post_status === 'publish' ) {
				$own_url = get_permalink( $own_id );

				if ( is_string( $own_url ) && $own_url !== '' && ! $this->is_current_url( $own_url ) ) {
					// One permanent redirect to the sibling; it returns only when
					// the sibling's URL does not route back to the sibling.
					$this->redirect_to_own_language_sibling( $own_url, $current_slug, (int) $post->ID, $own_id );
					$this->redirect_to_fallback( $own_url, $current_slug, $current_slug, (int) $post->ID, $own_id );
					// Both redirect methods exit once they redirect.
				}
			}
		}

		// 'post_query' chain, deliberately separate from the 'strings' chain.
		$chain = $this->settings->get_fallback_chain( (string) $current_slug, 'post_query' );

		if ( $chain !== [] ) {
			// The map load primed the post caches.
			if ( $translations_map !== [] ) {
				$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository(
					\PerfLocale\Plugin::get_instance()->get( 'cache' )
				);

				foreach ( $chain as $try_slug ) {
					$lang = $lang_repo->find_by_slug( $try_slug );

					if ( ! $lang ) {
						continue;
					}

					$target_id = (int) ( $translations_map[ (int) $lang->id ] ?? 0 );

					if ( $target_id <= 0 ) {
						continue;
					}

					$target_post = get_post( $target_id ); // primed cache above

					if ( ! $target_post instanceof \WP_Post || $target_post->post_status !== 'publish' ) {
						continue;
					}

					$target_url = $this->fallback_target_url( $target_id, $unlinked_id, $default );

					if ( $target_url === '' ) {
						continue;
					}

					// Same-URL fuse against a redirect loop.
					if ( $this->is_current_url( $target_url ) ) {
						continue;
					}

					$this->redirect_to_fallback( $target_url, $current_slug, $try_slug, (int) $post->ID, $target_id );
					// redirect_to_fallback() exits.
				}
			}
		}

		// Chain exhausted: apply the configured action.
		$action = $this->settings->get_missing_translation_action();

		if ( $action === 'show_404' ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		if ( $action === 'redirect_default' ) {
			if ( $default ) {
				$default_post_id = (int) ( $translations_map[ (int) $default->id ] ?? 0 );

				// The queried post may itself be the default-language sibling: still canonicalise its URL.
				if ( $default_post_id > 0 ) {
					$default_post = get_post( $default_post_id );

					if ( $default_post instanceof \WP_Post && $default_post->post_status === 'publish' ) {
						$default_url = $this->fallback_target_url( $default_post_id, $unlinked_id, $default );

						if ( $default_url !== '' ) {
							// Already there: the stripped sentinel cannot stop a loop. Return, not fall through to the home redirect.
							if ( $this->is_current_url( $default_url ) ) {
								return;
							}

							$this->redirect_to_fallback( $default_url, $current_slug, $default->slug, (int) $post->ID, $default_post_id );
						}
					}
				}

				// No default-language version: its homepage, from raw get_option( 'home' ) (home_url adds the current prefix).
				$home_base = rtrim( (string) get_option( 'home' ), '/' );

				if ( $home_base !== '' ) {
					// A path prefix exists only in subdirectory mode.
					$prefix_default = $this->settings->get_url_mode() === 'subdirectory'
						&& ! $this->settings->hide_default_prefix();

					$home_url = $prefix_default
						? $home_base . '/' . $this->settings->get_url_prefix( $default ) . '/'
						: $home_base . '/';

					$this->redirect_to_fallback( $home_url, $current_slug, $default->slug, (int) $post->ID, 0 );
				}
			}
		}

		// show_default: the post is shown as-is.
	}

	/**
	 * Whether a request for this post in the given language serves content, for first-visit redirects.
	 * Must mirror handle_missing_translation(): a chain landing on the default language is false (it sends the visitor back).
	 *
	 * @param \WP_Post $post     Post the request resolved to.
	 * @param object   $language Language row (id, slug) the visitor would be sent to.
	 * @return bool
	 */
	public function serves_in_language( \WP_Post $post, object $language ): bool {
		if ( ! in_array( $post->post_type, $this->settings->get_translatable_post_types(), true ) ) {
			return true;
		}

		$language_id = (int) ( $language->id ?? 0 );
		$post_lang   = $this->get_post_language_id( (int) $post->ID );
		$default     = $this->router->get_default_language();
		$default_id  = $default !== null ? (int) $default->id : 0;
		$unlinked_id = ( $post_lang === 0 && $default !== null ) ? (int) $post->ID : 0;

		if ( $unlinked_id > 0 ) {
			$post_lang = $default_id;
		}

		if ( $post_lang === $language_id ) {
			return true;
		}

		$translations_map = $unlinked_id > 0
			? [ $default_id => $unlinked_id ]
			: $this->get_translations_map( (int) $post->ID );

		$own_id = (int) ( $translations_map[ $language_id ] ?? 0 );

		if ( $own_id > 0 ) {
			$own_post = get_post( $own_id );

			if ( $own_post instanceof \WP_Post && $own_post->post_status === 'publish' ) {
				return true;
			}
		}

		$chain = $this->settings->get_fallback_chain( (string) ( $language->slug ?? '' ), 'post_query' );

		if ( $chain !== [] && $translations_map !== [] ) {
			$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository(
				\PerfLocale\Plugin::get_instance()->get( 'cache' )
			);

			foreach ( $chain as $try_slug ) {
				$lang = $lang_repo->find_by_slug( $try_slug );

				if ( ! $lang ) {
					continue;
				}

				$lang_id   = (int) $lang->id;
				$target_id = (int) ( $translations_map[ $lang_id ] ?? 0 );

				if ( $target_id <= 0 ) {
					continue;
				}

				$target_post = get_post( $target_id );

				// First published chain member; the default language would send the visitor back.
				if ( $target_post instanceof \WP_Post && $target_post->post_status === 'publish' ) {
					return $lang_id !== $default_id;
				}
			}
		}

		return $this->settings->get_missing_translation_action() === 'show_default';
	}

	/**
	 * Whether an absolute URL is the current request: path, p/page_id, and the language part of the URL mode.
	 * Never the scheme (TLS proxies), the host only in host modes, and no other query args (sentinel, tracking).
	 *
	 * @param string $url Absolute URL to compare against the current request.
	 * @return bool
	 */
	private function is_current_url( string $url ): bool {
		// esc_url_raw, not sanitize_text_field: keep %XX octets.
		$request_uri  = ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/' );
		$request_path = strtok( $request_uri, '?' ) ?: '/';

		$target_path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );

		if ( untrailingslashit( $request_path ) !== untrailingslashit( $target_path ) ) {
			return false;
		}

		// Plain permalinks carry the object in p/page_id: compare those, not tracking args.
		$request_query = [];
		$target_query  = [];
		$qpos          = strpos( $request_uri, '?' );

		if ( $qpos !== false ) {
			parse_str( substr( $request_uri, $qpos + 1 ), $request_query );
		}

		$target_qs = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?? '' );

		if ( $target_qs !== '' ) {
			parse_str( $target_qs, $target_query );
		}

		foreach ( [ 'p', 'page_id' ] as $param ) {
			if ( (int) ( $request_query[ $param ] ?? 0 ) !== (int) ( $target_query[ $param ] ?? 0 ) ) {
				return false;
			}
		}

		// Host and query modes: a sibling can share path and object; only the language part differs.
		$url_mode = $this->settings->get_url_mode();

		if ( $url_mode === 'subdomain' || $url_mode === 'domain' ) {
			$target_host = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );

			// A target without a host is relative, so it is on the request's host.
			if ( $target_host !== '' ) {
				$request_host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

				if ( LanguageRouter::normalize_host( $request_host ) !== LanguageRouter::normalize_host( $target_host ) ) {
					return false;
				}
			}
		} elseif ( $url_mode === 'query' ) {
			// Absent reads as '': the default language has no parameter.
			$query_var    = \PerfLocale\Router\UrlConverter::query_var();
			$request_lang = $request_query[ $query_var ] ?? '';
			$target_lang  = $target_query[ $query_var ] ?? '';

			if ( ( is_string( $request_lang ) ? $request_lang : '' ) !== ( is_string( $target_lang ) ? $target_lang : '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 301 a visitor on another language's slug to the current language's own translation, without a sentinel.
	 * Loop fuse: redirects only when the target URL routes to the translation; else returns for the sentinel redirect.
	 *
	 * @param string $url          Translation's permalink.
	 * @param string $slug         Current language slug.
	 * @param int    $from_post_id Post being rendered.
	 * @param int    $to_post_id   Translation in the current language.
	 * @return void Returns only when no redirect was issued.
	 */
	private function redirect_to_own_language_sibling( string $url, string $slug, int $from_post_id, int $to_post_id ): void {
		if ( url_to_postid( $url ) !== $to_post_id ) {
			return;
		}

		$url = $this->with_passthrough_query( $url );

		/** This filter is documented in src/Translation/PostQueryFilter.php (redirect_to_fallback). */
		$status = (int) apply_filters(
			'perflocale/fallback/redirect_status',
			301,
			$slug,
			$slug,
			$from_post_id,
			$to_post_id
		);

		if ( ! in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
			$status = 301;
		}

		wp_safe_redirect( $url, $status );
		exit;
	}

	/**
	 * Carry the request's query string over to a redirect target, minus its routing parameters.
	 *
	 * @param string $url Redirect target.
	 * @return string Target with the request's other query arguments.
	 */
	private function with_passthrough_query( string $url ): string {
		// esc_url_raw, not sanitize_text_field, which deletes every %XX escape.
		// The '/?' prefix is required: esc_url() prepends a scheme to a bare query string.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on the next line by esc_url_raw() after the '/?' prefix.
		$raw_qs     = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( (string) $_SERVER['QUERY_STRING'] ) : '';
		$safe_qs    = $raw_qs === '' ? '' : esc_url_raw( '/?' . $raw_qs );
		$qs_mark    = strpos( $safe_qs, '?' );
		$request_qs = ( $safe_qs !== '' && $qs_mark !== false ) ? substr( $safe_qs, $qs_mark + 1 ) : '';

		if ( $request_qs !== '' ) {
			$passthrough = [];

			parse_str( $request_qs, $passthrough );

			if ( is_array( $passthrough ) && $passthrough !== [] ) {
				// The fallback guard must only see the walker's own sentinel.
				unset( $passthrough['perflocale_fb'] );

				// Object-routing params name the old post and would clobber the target's own ?p= (ping-pong).
				unset(
					$passthrough['p'],
					$passthrough['page_id'],
					$passthrough['name'],
					$passthrough['pagename'],
					$passthrough['attachment_id']
				);

				// Query mode: a stale language param loops, as clean_fallback_sentinel() strips the sentinel first.
				if ( $this->settings->get_url_mode() === 'query' ) {
					// The configured name, not the literal 'lang': the name is filterable.
					unset( $passthrough[ \PerfLocale\Router\UrlConverter::query_var() ] );
				}

				if ( $passthrough !== [] ) {
					$url = add_query_arg( $passthrough, $url );
				}
			}
		}

		return $url;
	}

	/**
	 * The URL a missing-translation redirect sends the visitor to for a post.
	 * An unlinked post's permalink carries the visitor's language, so it is converted to the default.
	 *
	 * @param int         $target_id        Post to link to.
	 * @param int         $unlinked_id      The queried post when it has no translation link, else 0.
	 * @param object|null $default_language Default-language object.
	 * @return string URL, or '' when the post has none.
	 */
	private function fallback_target_url( int $target_id, int $unlinked_id, ?object $default_language ): string {
		$url = get_permalink( $target_id );

		if ( ! is_string( $url ) || $url === '' ) {
			return '';
		}

		if ( $target_id === $unlinked_id && $default_language !== null ) {
			$converter = \PerfLocale\Plugin::get_instance()->get( 'url_converter' );

			if ( $converter instanceof \PerfLocale\Router\UrlConverter ) {
				$url = $converter->convert( $url, (string) $default_language->slug );
			}
		}

		return $url;
	}

	/**
	 * Issue a fallback redirect with the sentinel, the status filter and its allow-list.
	 *
	 * @param string $url Target URL (from get_permalink / home_url).
	 * @param string $from_slug Current language slug.
	 * @param string $to_slug Target language slug.
	 * @param int    $from_post_id Source post being rendered.
	 * @param int    $to_post_id Target post ID (0 for homepage fallback).
	 * @return never
	 */
	private function redirect_to_fallback( string $url, string $from_slug, string $to_slug, int $from_post_id, int $to_post_id ): void {
		$url = $this->with_passthrough_query( $url );

		// The sentinel stops the landing request from re-running the walker.
		$url = add_query_arg( 'perflocale_fb', '1', $url );

		/**
		 * Filter the HTTP status code used for language-fallback redirects.
		 *
		 * Default 302, so fallbacks stop once the translation is published; 301 for the current
		 * language's own translation. Values outside {301, 302, 307, 308} fall back to the default.
		 *
		 * @hook perflocale/fallback/redirect_status
		 * @param int $status Default 302; 301 for the current language's own translation.
		 * @param string $from_slug Source language slug.
		 * @param string $to_slug Target language slug.
		 * @param int $from_post_id Source post being rendered.
		 * @param int $to_post_id Target post ID (0 if redirecting to the language homepage).
		 */
		$status = (int) apply_filters(
			'perflocale/fallback/redirect_status',
			302,
			$from_slug,
			$to_slug,
			$from_post_id,
			$to_post_id
		);

		if ( ! in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
			$status = 302;
		}

		wp_safe_redirect( $url, $status );
		exit;
	}

	/**
	 * Memoised `[language_id =&gt; post_id]` map of a post's translations; primes their post cache.
	 *
	 * @param int $post_id Source post.
	 * @return array<int, int>
	 */
	private function get_translations_map( int $post_id ): array {
		// Keyed by blog; cleared on switch_blog.
		$key = get_current_blog_id() . ':' . $post_id;

		if ( isset( self::$translations_map_cache[ $key ] ) ) {
			return self::$translations_map_cache[ $key ];
		}

		$links = $this->get_groups_repo()->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post );
		$map   = [];
		$ids   = [];

		foreach ( $links as $link ) {
			if ( ! isset( $link->language_id, $link->object_id ) ) {
				continue;
			}

			$lang_id = (int) $link->language_id;
			$obj_id  = (int) $link->object_id;

			if ( $lang_id <= 0 || $obj_id <= 0 ) {
				continue;
			}

			$map[ $lang_id ] = $obj_id;
			$ids[]           = $obj_id;
		}

		// Unique already: one row per (group_id, language_id).
		if ( $ids !== [] && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}

		self::$translations_map_cache[ $key ] = $map;

		return $map;
	}

	/**
	 * Strip the `perflocale_fb` sentinel from the URL with a 301.
	 *
	 * @return void
	 */
	public function clean_fallback_sentinel(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['perflocale_fb'] ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		$clean = remove_query_arg( 'perflocale_fb' );

		if ( ! is_string( $clean ) || $clean === '' ) {
			return;
		}

		wp_safe_redirect( $clean, 301 );
		exit;
	}
}
