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
 * Filters WP_Query to return only posts in the current language.
 *
 * Joins the translation_links table to WP_Query via the posts_clauses
 * filter to add a language condition without requiring a separate query.
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
	 * Whether a fallback post was loaded by resolve_duplicate_slug().
	 *
	 * Set when the current language has no translation and the default-language
	 * post was loaded instead. Used to suppress WordPress canonical redirect.
	 *
	 * @var bool
	 */
	private bool $loaded_fallback = false;

	/**
	 * Target language slug for the in-flight REST search request, or '' when
	 * the request isn't a scoped link-picker call. Set by
	 * filter_rest_search_query() and consumed by
	 * filter_rest_search_result_url() to rewrite the per-item URL.
	 *
	 * Lives on the instance (not as a static) because PostQueryFilter is a
	 * singleton inside the plugin's DI container - one instance per request
	 * - and a static would risk leaking between sub-requests on the same
	 * process (CLI long-runners, multisite switch_to_blog loops).
	 *
	 * @var string
	 */
	private string $rest_search_target_slug = '';

	/**
	 * Per-request memo for find_slug_candidates(), keyed
	 * "blog|post_type|slug" (blog-keyed so switch_to_blog() can't serve
	 * another blog's language ids). Bounded at a handful of entries.
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
	 * Cached map of post-type object_id → language_id for the current blog.
	 *
	 * Populated once on first `filter_get_pages()` call and reused for every
	 * subsequent invocation. A theme using `wp_page_menu()` or a sidebar with
	 * page-listing widgets can fire `get_pages()` many times per request;
	 * caching the full map collapses N*(2 queries) into 1 query per request.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $page_language_map_cache = null;

	/**
	 * Cached request-constants read by filter_get_pages() hot-loop - cleared
	 * on switch_blog so multisite language context doesn't leak.
	 *
	 * @var array{lang_id:int, tt:array<int, string>}|null
	 */
	private static ?array $get_pages_cfg_cache = null;

	/**
	 * Cached pre-built JOIN/WHERE SQL fragments keyed by language_id. WP_Query
	 * runs our posts_clauses filter for every query in a request - archive +
	 * sidebars + widgets + related-posts easily hit 4–6 queries - and each one
	 * otherwise rebuilt the same JOIN/WHERE strings (implode + prepare). Cache
	 * the fragments once per language and splice them in.
	 *
	 * @var array<int, array{join:string, where:string}>|null
	 */
	private static ?array $query_clauses_cache = null;

	/**
	 * Per-request post_id → language_id memo for detect_post_language_id(),
	 * keyed by "blog_id:post_id". Class-static so reset_static_caches()
	 * clears it on switch_blog — without that, a CLI long-runner or REST
	 * cross-blog hop could read blog A's mapping while serving blog B.
	 *
	 * @var array<string, int>
	 */
	private static array $detect_post_lang_cache = [];

	/**
	 * Per-request post_id → [language_id => post_id] memo for
	 * get_translations_map(), keyed by "blog_id:post_id". Same switch_blog-
	 * safety rationale as $detect_post_lang_cache.
	 *
	 * @var array<string, array<int, int>>
	 */
	private static array $translations_map_cache = [];

	/**
	 * Per-request `option_page_for_posts` translation cache, keyed
	 * "language_id:page_id". Reset on switch_blog with the others below.
	 *
	 * @var array<string, int>
	 */
	private static array $page_for_posts_cache = [];

	/**
	 * Per-request adjacent-post (Previous/Next) clause cache, keyed by the
	 * viewed post id. Static + reset on switch_blog with the others below —
	 * post ids collide numerically across blogs, so this MUST be cleared on
	 * blog switch or a neighbour query on blog B could reuse blog A's clause.
	 *
	 * @var array<int, array{join: string, where: string}|null>
	 */
	private static array $adjacent_clauses_memo = [];

	/**
	 * Generational cache group holding paginated found_posts counts. Bumped by
	 * flush_found_rows_cache() on every content change that can move the count
	 * (publish-status transition, deletion, translation-link mutation, language
	 * add/delete), so a cached count is never served stale. See
	 * optimize_found_rows()/set_found_posts().
	 */
	private const FOUND_ROWS_GROUP = 'perflocale_found_rows';

	/**
	 * Per-request map of spl_object_id(WP_Query) → the data needed to reproduce
	 * WP core's found_posts for that query: the exact COUNT SQL ('sql', only
	 * built for paginated queries) and whether the query has a LIMIT clause
	 * ('has_limits', which selects WP's paginated vs count($posts) branch).
	 * Written in modify_query_clauses() (where the final clauses are known) and
	 * consumed + unset in set_found_posts() on the_posts. Request-scoped; also
	 * cleared on switch_blog for defence in depth.
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
		// self::$found_rows_data is deliberately NOT cleared here. Its entries
		// are query-OBJECT-scoped (keyed by spl_object_id) and blog-anchored
		// (the stashed COUNT SQL bakes in the originating blog's table names),
		// and they are consumed on the_posts in the originating blog's context.
		// switch_blog fires for ANY switch_to_blog()/restore_current_blog()
		// pair — including ones inside third-party callbacks that run between
		// our posts_clauses_request stash and the_posts — so clearing it here
		// wiped in-flight main-query counts and zeroed pagination.
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

		// Mark queries with the current language ID for clause filtering.
		// Priority 5 ensures the language flag is attached before third-party
		// plugins hooked at the default priority 10 decide whether to touch
		// the query - without this, ordering was effectively undefined.
		add_action( 'pre_get_posts', [ $this, 'filter_by_language' ], 5 );

		// Sitewide comment feeds (/de/comments/feed/, ?feed=rss2&withcomments=1)
		// build their OWN comment query — the post-side language clauses never
		// touch it, so a language-prefixed comments feed served every
		// language's comments. Scope the comment feed's post JOIN the same way.
		add_filter( 'comment_feed_join', [ $this, 'scope_comment_feed_join' ], 10, 2 );
		add_filter( 'comment_feed_where', [ $this, 'scope_comment_feed_where' ], 10, 2 );

		// Redirect front/blog page slugs to their canonical language root URL.
		add_action( 'template_redirect', [ $this, 'redirect_front_page_slug' ], 5 );

		// Strip the `perflocale_fb` sentinel at priority 1 so the URL the
		// user ends up on after a fallback redirect doesn't carry the
		// internal tracking param. Runs before the fallback handler at the
		// default priority to guarantee we never re-fire on a sentinel URL.
		add_action( 'template_redirect', [ $this, 'clean_fallback_sentinel' ], 1 );

		// Enforce missing translation action on singular pages.
		add_action( 'template_redirect', [ $this, 'handle_missing_translation' ] );

		// Prevent WordPress canonical redirect when a fallback post was loaded
		// intentionally. Without this, WP redirects to the default-language
		// permalink before handle_missing_translation() can apply the configured action.
		add_filter( 'redirect_canonical', [ $this, 'prevent_fallback_canonical_redirect' ], 10, 2 );

		// Suppress WP core's SQL_CALC_FOUND_ROWS on the front-end main archive
		// query (priority 6 — after filter_by_language at 5 has attached the
		// language id). Runs only where PerfLocale's language LEFT JOIN would
		// otherwise force MySQL to scan every matching row to count them. The
		// exact count is recovered — generationally cached — in set_found_posts()
		// so found_posts / max_num_pages stay identical to the SQL_CALC result.
		add_action( 'pre_get_posts', [ $this, 'optimize_found_rows' ], 6 );

		// Apply language clause to every query that was marked above.
		add_filter( 'posts_clauses', [ $this, 'modify_query_clauses' ], 10, 2 );

		// Stash the COUNT SQL for optimized queries from the FINAL clauses.
		// posts_clauses_request fires after every posts_clauses callback (any
		// priority), so third-party clause edits are included — exactly what
		// SQL_CALC_FOUND_ROWS would have counted.
		add_filter( 'posts_clauses_request', [ $this, 'stash_found_rows_count' ], PHP_INT_MAX, 2 );

		// If another plugin short-circuits the query (posts_pre_query non-null),
		// drop the stash so set_found_posts() leaves that plugin's own
		// found_posts value untouched.
		add_filter( 'posts_pre_query', [ $this, 'unstash_on_short_circuit' ], PHP_INT_MAX, 2 );

		// Supply found_posts / max_num_pages for queries whose SQL_CALC was
		// suppressed by optimize_found_rows(). Prio 20 so it runs after the
		// translation-cache prime (the_posts prio 5) — order is immaterial to
		// the count, but keeps the prime first.
		add_filter( 'the_posts', [ $this, 'set_found_posts' ], 20, 2 );

		// Invalidate cached found_posts counts on any change that can move them.
		// Translation-link mutations are covered by a generation bump inside
		// TranslationGroupRepository::invalidate_eager_link_map() (the single
		// point every link path funnels through). These cover the remaining
		// dimensions: a post crossing the publish boundary, permanent deletion,
		// and language add/delete.
		add_action( 'transition_post_status', [ $this, 'flush_found_rows_on_transition' ], 10, 2 );
		add_action( 'deleted_post', [ $this, 'flush_found_rows_on_delete' ], 10, 2 );
		add_action( 'perflocale/language/added', [ $this, 'flush_found_rows_cache' ] );
		add_action( 'perflocale/language/deleted', [ $this, 'flush_found_rows_cache' ] );

		// Language-scope the adjacent-post (Previous/Next) links. WP core's
		// get_adjacent_post() builds raw SQL and never runs a WP_Query, so
		// neither pre_get_posts nor posts_clauses fire — without these filters
		// the Prev/Next links on a single post cross languages.
		add_filter( 'get_previous_post_join', [ $this, 'filter_adjacent_post_join' ], 10, 5 );
		add_filter( 'get_next_post_join', [ $this, 'filter_adjacent_post_join' ], 10, 5 );
		add_filter( 'get_previous_post_where', [ $this, 'filter_adjacent_post_where' ], 10, 5 );
		add_filter( 'get_next_post_where', [ $this, 'filter_adjacent_post_where' ], 10, 5 );

		// Filter the Gutenberg link-picker / REST search by the EDITED post's
		// language. The block editor's apiFetch middleware injects a
		// `perflocale_post=<id>` query param into /wp/v2/search calls, and
		// this filter maps that to the language whose posts should appear
		// in the typeahead. Without it, the URL-derived current language
		// (always empty under /wp-json/) defaults to the site default and
		// authors editing a DE post see only EN suggestions.
		add_filter( 'rest_post_search_query', [ $this, 'filter_rest_search_query' ], 10, 2 );

		// Re-prefix every search-result URL to the queried language. The
		// search handler builds URLs via get_permalink(), which resolves a
		// post's "primary" language — wrong when one post is linked to several
		// languages in one group (corruption / WPML-legacy state). Forcing the
		// queried language gives the picker a deterministic answer. There's no
		// per-item search filter, so we walk the dispatched response.
		add_filter( 'rest_request_after_callbacks', [ $this, 'filter_rest_search_response_urls' ], 10, 3 );

		// Filter get_pages() results by language (used by wp_page_menu fallback).
		add_filter( 'get_pages', [ $this, 'filter_get_pages' ], 10, 2 );

		// Translation-cache priming for posts in the main query is now done
		// by UrlConverter::preload_object_languages (the_posts prio 5),
		// which calls prime_translations() AND extracts per-post language
		// IDs in one pass. Registering a second the_posts callback here
		// just to call prime_translations() again was pure overhead — the
		// L1 cache short-circuited it on every hit. Removed in 1.0.0.
	}

	/**
	 * Resolve the translated front page for language homepages.
	 *
	 * When visiting /en/ or /de/, WordPress shows the blog listing because
	 * it only knows about one static front page. This intercepts the main
	 * query and points it to the translated front page for that language.
	 *
	 * @param \WP_Query $query The main query.
	 * @return void
	 */
	public function resolve_translated_front_page( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Don't hijack variant requests. /feed/, /robots.txt, /wp-sitemap.xml,
		// /embed, trackbacks and 404 routing arrive with empty
		// pagename/name/p/post_type — the same shape the "bare language
		// homepage" detection below keys on. Without this guard they'd be
		// rewritten to page_on_front, poisoning is_feed()/is_robots() and
		// making redirect_canonical 301 them to the page slug.
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

		// Detect if this query is for the front page. Two cases:
		// 1. Language homepage with no specific content: /en/, /de/ - only 'lang' in query_vars.
		// 2. WordPress's parse_query() already auto-set page_id = page_on_front
		// (happens when is_home is true and a static front page is configured).
		$queried_page_id = (int) $query->get( 'page_id' );
		$is_front_page   = false;

		if ( $queried_page_id === $front_page_id ) {
			// parse_query already resolved this as the static front page.
			$is_front_page = true;
		} elseif ( $queried_page_id === 0 ) {
			// No page_id set - check if it's a bare language homepage.
			// Exclude archive/taxonomy queries (product_cat, post_tag, etc.)
			// which WordPress has already flagged by parse_query() time.
			$is_front_page = (
				! $query->is_archive
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

		// Step 1: Check if the current page_on_front is ALREADY in the current language.
		// This happens when the default language was changed and page_on_front was swapped.
		$front_page_lang = $this->get_post_language_id( $front_page_id );

		if ( $front_page_lang === $language_id ) {
			// The front page IS in the current language - just use it directly.
			$target_id = $front_page_id;
		} else {
			// Step 2: Find the translation of the front page in the current language.
			$target_id = $this->find_translated_page( $front_page_id, $language_id );

			if ( ! $target_id ) {
				// No translation found - show the front page as-is.
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
	 * Redirect front page slug URLs to their canonical language root.
	 *
	 * When a static front page has slug "homepage", visiting /en/homepage/
	 * would render the same content as /en/. This 301-redirects the slug
	 * URL to the language root to avoid duplicate content.
	 *
	 * @return void
	 */
	public function redirect_front_page_slug(): void {
		if ( is_admin() || ! is_page() ) {
			return;
		}

		// Don't clobber variant views of the front page. When a static front
		// page is set, WP routes /feed/, /page/2/, /embed/, /robots.txt,
		// /wp-sitemap.xml and other special URLs to the front page's post ID
		// (is_page() is true for all of them because WP's fallback resolver
		// returns page_on_front). Redirecting those to the bare language root
		// drops feeds, inner-page pagination, embeds, trackbacks, robots.txt,
		// and sitemaps.
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

		// Body-preserving methods only. A 301/302 on POST/PUT/DELETE/PATCH
		// would drop the request body, turning form submissions into GETs
		// to the canonical URL and silently discarding user data.
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
			// Also check by slug - the page might share the front page's slug
			// but not be linked in the same translation group (e.g., created
			// independently or with a broken translation link).
			$front_page = get_post( $front_page_id );
			if ( ! $front_page || $post->post_name !== $front_page->post_name ) {
				return;
			}
		}

		// Belt-and-braces: the queried object is the front page, but that
		// doesn't guarantee the URL contains the front-page slug - WP's
		// fallback resolver returns page_on_front for many special URLs
		// (edge cases the variant-view guards above may not cover).
		// Only act when the URL *actually* contains a front-page slug
		// as a path segment. No slug in the URL = nothing to canonicalise.
		// esc_url_raw (not sanitize_text_field) so percent-encoded non-Latin
		// front-page slugs survive the segment match.
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

		// Build the canonical language root URL. home_url('/') already goes
		// through our home_url filter, which injects the right prefix for the
		// active URL mode (subdir slug/locale, subdomain, per-domain, or bare
		// for a hidden default), so one call covers every combination —
		// building it as home_url('/'.$slug.'/') would double the prefix in
		// non-subdirectory modes and 301-loop.
		$canonical = home_url( '/' );

		// Only redirect if the current URL differs from the canonical. Use
		// esc_url_raw (NOT sanitize_text_field, which strips %XX octets) and
		// parse the path the same way as the target so a percent-encoded
		// (non-Latin) path compares correctly instead of triggering a spurious
		// redirect.
		$request_uri  = ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' );
		$qs_pos       = strpos( $request_uri, '?' );
		$current_path = untrailingslashit( (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '/' ) );
		$target_path  = untrailingslashit( (string) ( wp_parse_url( $canonical, PHP_URL_PATH ) ?: '/' ) );

		if ( $current_path === $target_path ) {
			return;
		}

		// Preserve the query string - dropping it breaks UTM attribution,
		// click IDs, preview tokens, and similar contextual parameters.
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
		// Serve from the translation-link cache, primed for most front-facing
		// requests (prime_nav_menu, the_posts, or the lazy front-page prime).
		// get_translations falls through to a dedicated SELECT only when the
		// group isn't primed — so the hot path is O(1) in memory instead of a
		// DB round-trip per filter_page_for_posts / handle_missing_translation.
		$links = $this->get_groups_repo()->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) ( $link->object_id ?? 0 ) === $post_id ) {
				return (int) ( $link->language_id ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Find the translated version of a page in a specific language.
	 *
	 * Looks up the translation group and finds the post linked to the target language.
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
	 * Filter the page_for_posts option to return the translated page ID.
	 *
	 * This runs on the 'option_page_for_posts' filter so WordPress sees the
	 * translated blog page ID during parse_request, BEFORE it decides whether
	 * to show the blog index or a static page. This ensures the blog template
	 * is used for translated blog pages like /en/blog/, /de/blog/, etc.
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

		// Per-request cache keyed by language + page. CLASS-static (not a
		// method static) so reset_static_caches() clears it on switch_blog:
		// language IDs are per-blog auto-increments, so a method static here
		// would serve blog A's translated posts page to blog B for the same
		// numeric language id.
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
	 * Convert a translated blog page to a proper blog index query.
	 *
	 * When page_for_posts is set to a page in one language, visiting the
	 * blog URL in another language resolves to the translated page. But
	 * WordPress doesn't recognize it as the blog index because the page ID
	 * doesn't match page_for_posts. This method detects that case and
	 * converts the query from a page query to a blog posts query.
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
			// Check if the pagename matches any translation of the posts page.
			// Batch-prime first: this runs at pre_get_posts (before the main
			// query populates the post cache), so each get_post_field() below
			// would otherwise be a cache-miss single-row SELECT — one per
			// language, on every hierarchical-page view of no-object-cache
			// sites.
			_prime_post_caches( $posts_page_ids, false, false );

			foreach ( $posts_page_ids as $pid ) {
				// get_page_uri(), not post_name: `pagename` carries the FULL
				// path, so a posts page nested under a parent never matched its
				// own leaf slug and the archive rendered as a singular page
				// instead. Comparing full URIs also stops a same-named page
				// under a different parent from being mistaken for it, which a
				// basename comparison would have allowed. Both sides are the
				// stored percent-encoded form: get_page_uri() concatenates raw
				// post_name values, and $queried_pagename was normalised above.
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
	 *
	 * WordPress uses get_page_by_path() for pagename queries, which returns
	 * the first match. We intercept the query before that and set the correct
	 * page_id based on the current language.
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

		// Zero-state short-circuit: both slug resolvers below INNER JOIN
		// translation_groups, so with zero groups they return null and take no
		// query-mutating branch. has_any_groups() is autoloaded + memoised (no
		// extra query on group-having sites). Same idiom as the resolvers below.
		if ( ! $this->get_groups_repo()->has_any_groups() ) {
			return;
		}

		// Handle pagename queries (pages/hierarchical CPTs).
		//
		// Normalised to the stored form first: post_name is percent-encoded for
		// every non-Latin script, the query var is not, and every resolver and
		// get_page_uri() comparison below matches against post_name. No-op on
		// ASCII, so Latin-script sites are unaffected.
		$pagename = Helper::normalize_slug_for_query( (string) $query->get( 'pagename' ) );

		if ( ! empty( $pagename ) ) {
			// For a nested page core sets `pagename` to the FULL path
			// ("parent/child"), but the three resolvers below match on
			// `post_name`, which holds only "child". Every lookup therefore
			// missed at depth >= 1, core's language-blind get_page_by_path()
			// won, and a translated child page 301'd to its source-language
			// sibling — unreachable in its own language. Look up by basename,
			// then require the candidate's own full URI to equal the requested
			// path, so a same-named page under a different parent can never be
			// served in its place. Depth 0 keeps the exact previous behaviour:
			// $lookup is $pagename and there is nothing to verify.
			$lookup_slug = $pagename;
			$require_uri = '';

			if ( str_contains( (string) $pagename, '/' ) ) {
				$lookup_slug = wp_basename( (string) $pagename );
				$require_uri = trim( (string) $pagename, '/' );
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

				// Reset queried_object so WordPress doesn't use the stale
				// object from parse_request (which resolved to the first
				// matching slug regardless of language).
				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}

			// No translation in current language - fall back to the default
			// language post so it loads and handle_missing_translation() can
			// apply the configured action (show_default / show_404 / redirect).
			$fallback_id = $this->find_fallback_post( $lookup_slug, $language_id, 'page' );

			if ( $fallback_id && $require_uri !== '' && get_page_uri( $fallback_id ) !== $require_uri ) {
				$fallback_id = 0;
			}

			if ( $fallback_id ) {
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

			// Neither the current language nor the default language has this
			// page. Load *any* sibling (typically the original authored
			// language) so handle_missing_translation() gets a queried post
			// and walks the configured fallback chain. Without this, WP
			// 404s and redirect_guess_404_permalink() beats our handler
			// with a raw 301 to the canonical sibling - bypassing the
			// chain + sentinel entirely.
			$any_id = $this->find_any_translated_post_by_slug( $lookup_slug, 'page', $language_id );

			if ( $any_id && $require_uri !== '' && get_page_uri( $any_id ) !== $require_uri ) {
				$any_id = 0;
			}

			if ( $any_id ) {
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

		// Handle name queries (regular posts). Same normalisation as the
		// pagename branch above, for the same reason.
		$name = Helper::normalize_slug_for_query( (string) $query->get( 'name' ) );

		if ( ! empty( $name ) ) {
			$post_type = $query->get( 'post_type' );

			if ( empty( $post_type ) ) {
				$post_type = 'post';
			}

			$resolved_id = $this->find_post_by_slug_and_language( $name, $language_id, $post_type );

			if ( $resolved_id ) {
				$query->set( 'p', $resolved_id );
				$query->set( 'name', '' );

				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}

			// No translation in current language - fall back to default language.
			$fallback_id = $this->find_fallback_post( $name, $language_id, $post_type );

			if ( $fallback_id ) {
				$query->set( 'p', $fallback_id );
				$query->set( 'name', '' );
				$query->set( 'perflocale_all_languages', true );
				$this->loaded_fallback = true;

				$query->queried_object    = null;
				$query->queried_object_id = 0;
				return;
			}

			// Last resort: load any sibling so handle_missing_translation()
			// can walk the fallback chain (see the paging branch above for
			// the full rationale).
			$any_id = $this->find_any_translated_post_by_slug( $name, $post_type, $language_id );

			if ( $any_id ) {
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
	 * Find the first published post with a matching slug in any language
	 * other than the given one. Used as a last-resort resolution so our
	 * missing-translation handler gets a chance to run before WP’s own
	 * 404-guess 301.
	 *
	 * Only returns posts that PerfLocale manages (i.e. have a
	 * translation_link row). A post with no translation group is not part
	 * of the multilingual tree and is left alone.
	 *
	 * @param string $slug Post slug.
	 * @param string $post_type Post type.
	 * @param int    $exclude_language_id Language to exclude from the search.
	 * @return int|null
	 */
	private function find_any_translated_post_by_slug( string $slug, string $post_type, int $exclude_language_id ): ?int {
		// Candidates are ordered p.ID ASC, preserving the old query's
		// lowest-ID tiebreak.
		foreach ( $this->find_slug_candidates( $slug, $post_type ) as $row ) {
			if ( (int) $row->language_id !== $exclude_language_id ) {
				return (int) $row->ID;
			}
		}

		return null;
	}

	/**
	 * ONE query fetching every published, group-linked post sharing a slug,
	 * with its language — memoized per (blog, post_type, slug) for the
	 * request. The three slug resolvers used to each run their own variant
	 * of this query, so a singular view of UNMANAGED content (no sibling in
	 * any language) cost three sequential SELECT round trips; they now
	 * select in PHP over this one result set.
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

		// A request resolves one slug; the cap only guards pathological
		// loops from third-party code re-querying many slugs.
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
	 * Used by resolve_duplicate_slug() so the post loads successfully and
	 * handle_missing_translation() can apply the configured action instead
	 * of WordPress 404-ing and canonical-redirecting.
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
	 * Prevent WordPress canonical redirect when a fallback post was loaded.
	 *
	 * When resolve_duplicate_slug() loads a default-language post because no
	 * translation exists, the post's permalink won't match the current URL
	 * (e.g. /de/product/foo/ vs /en/product/foo/). WordPress's redirect_canonical
	 * would redirect to the default-language URL before handle_missing_translation()
	 * can apply the configured action.
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $requested_url The original requested URL.
	 * @return string|false URL to redirect to, or false to cancel.
	 */
	public function prevent_fallback_canonical_redirect( $redirect_url, $requested_url ) {
		if ( $this->loaded_fallback ) {
			return false;
		}

		// Also block when the queried post's language differs from the
		// current URL language - that's exactly when
		// handle_missing_translation() wants to take over (fallback chain
		// walk + configured action). Without this, WP's own canonical
		// redirect beats our handler to template_redirect and 301s the
		// visitor straight to the canonical sibling, bypassing our chain.
		if ( is_singular() ) {
			$post = get_queried_object();

			if ( $post instanceof \WP_Post ) {
				$current_lang_id = $this->router->get_current_language_id();
				$post_lang_id    = $this->get_post_language_id( (int) $post->ID );

				if ( $current_lang_id > 0 && $post_lang_id > 0 && $current_lang_id !== $post_lang_id ) {
					return false;
				}

				// Attachments are UNMANAGED (no language row), so the
				// mismatch check above never blocks and core's canonical
				// redirect permanently ejected /de/<attachment>/ visitors
				// to the default language. Serve in-place under the prefix
				// instead — same fallback untranslated posts get.
				if ( $current_lang_id > 0 && $post_lang_id === 0 && is_attachment() && ! $this->router->is_default_language() ) {
					return false;
				}
			}
		}

		return $redirect_url;
	}

	/**
	 * Filter WP_Query to only return posts in the current language.
	 *
	 * Modifies the query by adding a JOIN to translation_links and a
	 * WHERE clause filtering by the current language ID.
	 *
	 * @param \WP_Query $query The WP_Query instance.
	 * @return void
	 */
	public function filter_by_language( \WP_Query $query ): void {
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
			return;
		}

		// An upstream integration already stamped an explicit language on
		// this query (e.g. filter_rest_search_query scoping the block
		// editor's link picker to the EDITED post's language). Overwriting
		// the stamp with the request-detected language — the default
		// language on wp-admin's unprefixed REST base — showed only
		// default-language results with force-prefixed franken-URLs.
		if ( (int) $query->get( 'perflocale_language_id' ) > 0 ) {
			return;
		}

		// Authenticated editor REST reads (site editor Pages data view,
		// Navigation block link search, inserter queries) run on the
		// UNPREFIXED REST base, which detection resolves to the default
		// language — scoping those made every non-default-language page
		// invisible in the site editor. Mirror the admin list-table
		// behavior (all languages visible) for users who can edit posts;
		// explicitly stamped queries (guard above) and anonymous frontend
		// REST consumers stay language-scoped.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Skip language filtering on taxonomy archive main queries.
		// Each language has its own translated terms, so the term association
		// already scopes posts to the correct language. Adding a post-level
		// language filter on top causes 404s when the queried term belongs
		// to a different language than the current URL context.
		if ( $query->is_main_query() && ( $query->is_tax() || $query->is_category() || $query->is_tag() ) ) {
			return;
		}

		// Skip language filtering on explicit-ID singular main queries (?p=,
		// ?page_id= — plain permalinks, previews, shortlinks, and the front
		// page). The visitor named ONE specific object; applying the language
		// WHERE here filters it out entirely (it IS linked, just to another
		// language), the queried object comes back null, and WP core's
		// redirect_canonical then 301s to the object's own-language canonical
		// before handle_missing_translation() can walk the fallback chain —
		// losing the visitor's language even when a sibling translation
		// exists. Let the object load; the template_redirect handlers apply
		// the configured language behavior (sibling redirect / fallback / 404).
		if (
			$query->is_main_query()
			&& ( (int) $query->get( 'p' ) > 0 || (int) $query->get( 'page_id' ) > 0 || $query->is_attachment )
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

		// Exclude child post types that inherit language from their parent
		// (e.g. product_variation). They must not be filtered independently.
		$child_types = apply_filters( 'perflocale/query/child_post_types', [ 'product_variation' ] );
		$post_type   = array_diff( (array) $post_type, $child_types );

		$translatable = $this->settings->get_translatable_post_types();

		// If none of the queried post types are translatable, skip.
		$overlap = array_intersect( $post_type, $translatable );

		if ( empty( $overlap ) ) {
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
	 * Whether a comment-feed query should be language-scoped, and for which
	 * language.
	 *
	 * Sitewide comment feeds only — a singular post's own comment feed is
	 * already scoped by its post. Returns 0 to leave the query alone.
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
	 * Restrict a sitewide comment feed to the current language's posts
	 * (non-strict: comments on unmanaged posts stay visible everywhere,
	 * matching the frontend fallback semantics).
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
	 * Scope the REST search endpoint (/wp/v2/search) to the language of the
	 * post being edited.
	 *
	 * The block editor sends a `perflocale_post=<id>` param on every search
	 * call (injected by the apiFetch middleware in editor-sidebar.js). We
	 * resolve that post to a language ID and stamp it onto the search query
	 * args. The existing `posts_clauses` filter then applies the JOIN +
	 * language WHERE so only same-language results are returned, and the
	 * /de/contact/ URL is the one Gutenberg shows in the typeahead.
	 *
	 * When the edited post has no language assignment yet (brand-new draft
	 * created before any language metabox interaction, untranslated legacy
	 * content), we fall back to the site's default language. Showing every
	 * language mixed would produce duplicate-title rows like "/contact/"
	 * and "/de/contact/" next to each other — confusing UX. Defaulting is
	 * predictable and mirrors how the rest of the plugin treats unassigned
	 * posts.
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
			// Strict mode: exclude posts with no language assignment. The
			// default forgiving mode lets unmanaged posts appear in every
			// language's results so legacy pre-PerfLocale content stays
			// reachable on the frontend - but the link picker must NOT show
			// them, because their permalink is the plain (default-lang)
			// URL. An editor on a /de/ post who picks "/contact/" from the
			// typeahead has no way to know that link lacks the /de/ prefix.
			$query_args['perflocale_strict_language'] = true;

			// Remember the target slug so the per-item URL filter can
			// rewrite permalinks into the queried language even when
			// UrlConverter's per-post lookup picks a different language
			// for a post that lives in multiple language slots at once.
			$slug = $this->lookup_language_slug_by_id( $language_id );

			if ( $slug !== '' ) {
				$this->rest_search_target_slug = $slug;
			}
		}

		return $query_args;
	}

	/**
	 * Force every REST search-result URL into the language the picker is
	 * scoped to.
	 *
	 * Bound on `rest_request_after_callbacks` (fires from dispatch() so it
	 * runs for both real HTTP requests AND rest_do_request() callers). The
	 * sibling `rest_post_dispatch` only fires from the HTTP entry point and
	 * would silently skip internal callers / unit tests. We narrow to the
	 * search route + an active target slug captured by
	 * filter_rest_search_query(), so unrelated endpoints (and unscoped
	 * /wp/v2/search calls) are untouched.
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

		// Single-shot: drop the target slug so a follow-up request on the
		// same PHP process (CLI long-runner) doesn't reuse stale state.
		$this->rest_search_target_slug = '';

		return $response;
	}

	/**
	 * Look up a language's slug given its primary key id. Single-query and
	 * cached behind the languages repo - safe to call from a per-request
	 * hot path.
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
	 * Resolve a post's language id from the translation_links table, falling
	 * back to the default language when the post has no assignment.
	 *
	 * Mirrors TermQueryFilter::detect_post_language_id() so both pickers
	 * share the same "unassigned → default" policy.
	 *
	 * @param int $post_id Post ID.
	 * @return int Language ID (0 only when there is no default language).
	 */
	private function detect_post_language_id( int $post_id ): int {
		// Key by blog_id:post_id and live as a class-static so reset_static_caches()
		// clears the memo on switch_blog (same Bundle-A pattern TermQueryFilter
		// uses, which this method's docstring claims to mirror).
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
	 * Modify query clauses to join translation links and filter by language.
	 *
	 * Registered once via register_hooks, applies to every query that has
	 * the perflocale_language_id set (skips those that don't).
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

		// Zero-state short-circuit: with no translation groups on this blog
		// the LEFT JOIN below is guaranteed to return zero links — and the
		// language-WHERE clause would then keep every post unchanged. Skip
		// the JOIN entirely so WP_Query's own SQL stays untouched. The
		// `has_any_groups()` lookup is sub-µs on warm cache; on real
		// multilingual sites the check passes and we proceed to the JOIN.
		if ( ! $this->get_groups_repo()->has_any_groups() ) {
			return $clauses;
		}

		// Strict mode: callers that need "exactly this language, no unlinked
		// fallback" set perflocale_strict_language on the query. Used by
		// MenuManager so the nav-menus Pages/Posts pickers don't leak
		// unmanaged posts into every menu. Other callers (normal frontend
		// queries, admin list tables) keep the forgiving fallback so
		// pre-PerfLocale content still appears.
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

		// LEFT JOIN approach: posts match if linked to this language (strict)
		// OR have no post-type link at all (non-strict/fallback), so MySQL uses
		// index lookups instead of materializing a NOT IN set. The join onto
		// translation_groups (type='post') replaces a `group_id IN (…)` list
		// whose SQL text grew O(n) with group count (~100KB on 10k+ groups);
		// the indexed join is constant-sized.
		//
		// translation_links is polymorphic — object_id is a post OR a term id
		// — so a post whose ID equals a translated term's id would otherwise
		// match that term-link on `object_id = posts.ID`, producing a spurious
		// extra row that double-counts the post (and, via `_g.id IS NULL`,
		// leaks it across languages). Nesting the type='post' filter into an
		// INNER JOIN inside the LEFT JOIN restricts the link join to post-typed
		// links only, so a colliding term row never reaches the result.
		$alias = 'pl_lang_filter';

		$join_sql = " LEFT JOIN ( {$links_table} AS {$alias}"
			. " INNER JOIN {$groups_table} AS {$alias}_g"
			. " ON {$alias}_g.id = {$alias}.group_id AND {$alias}_g.type = 'post' )"
			. " ON {$alias}.object_id = {$wpdb->posts}.ID";

		// WHERE semantics: `{$alias}_g.id IS NOT NULL` = a post-typed link was
		// found (the nested INNER JOIN already excluded any term-link sharing
		// the object_id, so IS NULL means genuinely no post link). Strict mode
		// requires a matching post-typed link in the current language;
		// non-strict also admits unmanaged posts (no link) for fallback.
		//
		// Strict mode still admits posts of NON-translatable types: PerfLocale
		// never gives them a per-language variant, so their permalink is the
		// same language-neutral URL in every context. Excluding them would
		// drop, e.g., a non-translatable CPT from the block-editor link picker
		// on a translated post even though linking to it is correct.
		$translatable = $this->settings->get_translatable_post_types();
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
			$where_sql = $wpdb->prepare(
				" AND ( ( {$alias}.language_id = %d AND {$alias}_g.id IS NOT NULL ) OR {$alias}_g.id IS NULL )",
				$language_id
			);
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
	 * Record the exact COUNT SQL for a query whose SQL_CALC_FOUND_ROWS was
	 * suppressed by optimize_found_rows(). set_found_posts() runs it (or serves
	 * a cached result) on the_posts.
	 *
	 * Hooked to `posts_clauses_request` at PHP_INT_MAX — NOT called from
	 * modify_query_clauses() — so the stashed clauses are the FINAL ones WP
	 * assembles into the executed query. posts_clauses_request fires after
	 * every posts_clauses callback at every priority; stashing inside our own
	 * prio-10 posts_clauses callback would silently diverge from the real query
	 * whenever another plugin edits clauses at prio 11+ (SQL_CALC_FOUND_ROWS,
	 * which this replaces, always counted the final query).
	 *
	 * Invariant: this only stashes when the query carries the
	 * `perflocale_optimize_found_rows` flag, which optimize_found_rows() sets
	 * only after asserting the query is a non-suppressed front-end main query —
	 * and WP fires posts_clauses_request for every non-suppressed query — so a
	 * flagged query always gets stashed and set_found_posts() always finds its
	 * entry.
	 *
	 * The COUNT mirrors what SQL_CALC_FOUND_ROWS counts: COUNT(DISTINCT ID) when
	 * the query is grouped/distinct (tax archives group by ID for de-duplication,
	 * where SQL_CALC returns the group count), COUNT(*) otherwise. Verified
	 * byte-identical to found_posts across home/category/tag/author/date/search.
	 *
	 * We also record whether the query has a LIMIT clause. WP core only runs a
	 * found-rows count when `!empty($limits)`; a "show all" / nopaging query
	 * (empty limits) instead takes found_posts from count($posts). set_found_posts()
	 * reproduces both branches off this flag, so the count query is built only
	 * when it will actually be used.
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
			global $wpdb;

			$distinct = trim( (string) ( $clauses['distinct'] ?? '' ) );
			$groupby  = trim( (string) ( $clauses['groupby'] ?? '' ) );
			$select   = ( $distinct !== '' || $groupby !== '' )
				? "COUNT(DISTINCT {$wpdb->posts}.ID)"
				: 'COUNT(*)';

			$sql = "SELECT {$select} FROM {$wpdb->posts} "
				. (string) ( $clauses['join'] ?? '' ) . ' WHERE 1=1 ' . (string) ( $clauses['where'] ?? '' );

			// wpdb::prepare() masks literal % characters with a RANDOM-per-request
			// {hash} token (placeholder_escape) that wpdb::query() converts back to
			// % just before execution. Search clauses (LIKE '%term%') therefore
			// differ byte-wise on every request even when logically identical —
			// which would give the count a fresh cache key per request and a 100%
			// miss rate. Normalize exactly the way wpdb does at execution time so
			// the key (and the SQL we later run) is stable.
			$sql = (string) $wpdb->remove_placeholder_escape( $sql );
		}

		// Safety valve for CLI long-runners: entries whose query never reaches
		// the_posts (third-party short-circuits, exotic query shapes) would
		// otherwise accumulate — and a recycled spl_object_id could then match
		// a stale entry. No real request has anywhere near this many in-flight
		// optimized main queries, so a reset here only ever drops dead entries.
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
	 *
	 * Hooked to posts_pre_query at PHP_INT_MAX. A non-null return here (e.g.
	 * ElasticPress serving results from its index) means WP never executes the
	 * SQL our stashed COUNT mirrors — and the short-circuiting plugin supplies
	 * its own found_posts. Unstashing makes set_found_posts() bail so that
	 * value is left untouched instead of being clobbered with a count of a
	 * query that never ran.
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
	 * Suppress WP core's SQL_CALC_FOUND_ROWS on the front-end main archive query.
	 *
	 * SQL_CALC_FOUND_ROWS is deprecated (MySQL 8.0.17+) and, combined with the
	 * language LEFT JOIN this class adds, forces MySQL to scan every matching row
	 * — ignoring LIMIT — to produce the count. On large sites that turns a
	 * sub-millisecond indexed LIMIT lookup into a full O(matching-rows) scan on
	 * every archive view. Suppressing it lets the main query use the index + LIMIT;
	 * set_found_posts() supplies an exact, cached count so pagination is unchanged.
	 *
	 * Scope is deliberately narrow: front-end main query only (excludes admin,
	 * REST, and secondary queries), not singular, not an explicit no_found_rows
	 * opt-out, and only queries PerfLocale actually filters (language id attached
	 * + translation groups exist). Everything else is left 100% untouched.
	 *
	 * @param \WP_Query $query The query being parsed.
	 * @return void
	 */
	public function optimize_found_rows( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Respect an explicit opt-out (e.g. a caching plugin that computes its
		// own count) and any query already skipping the count.
		if ( $query->get( 'no_found_rows' ) ) {
			return;
		}

		// Singular views (single post/page) don't paginate a result set — the
		// SQL_CALC scan there is trivial and found_posts is irrelevant.
		if (
			'' !== (string) $query->get( 'name' )
			|| '' !== (string) $query->get( 'pagename' )
			|| (int) $query->get( 'p' ) > 0
			|| (int) $query->get( 'page_id' ) > 0
		) {
			return;
		}

		// Non-default `fields` shapes (ids / id=>parent) are only reachable on a
		// main query via unusual third-party pre_get_posts callbacks, and some
		// of their code paths skip the_posts — which would leave found_posts at
		// 0 with no set_found_posts() to supply it. Not worth optimizing; keep
		// WP's native counting there.
		$fields = (string) $query->get( 'fields' );
		if ( $fields !== '' && $fields !== 'all' ) {
			return;
		}

		// Only queries carrying PerfLocale's language JOIN suffer the scan. The
		// id is attached by filter_by_language() at pre_get_posts prio 5; it is
		// intentionally NOT set for tax/category/tag main queries (which this
		// class does not language-JOIN), so those keep WP's native behaviour.
		if ( (int) $query->get( 'perflocale_language_id' ) === 0 ) {
			return;
		}

		if ( ! $this->get_groups_repo()->has_any_groups() ) {
			return;
		}

		/**
		 * Master switch for the found-rows pagination optimization. Return false
		 * to keep WP core's SQL_CALC_FOUND_ROWS behaviour for every query.
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
	 * Supply found_posts / max_num_pages for a query whose SQL_CALC was
	 * suppressed by optimize_found_rows(), reproducing WP core's own
	 * WP_Query::set_found_posts() branch-for-branch: bail on an empty posts array
	 * (out-of-range/empty pages keep found_posts at 0); for a LIMITed query use
	 * the count (generationally cached, or run once and cached); for a
	 * show-all/nopaging query use count($posts); apply the `found_posts` filter;
	 * and set max_num_pages only when a LIMIT was present.
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

		// Mirror WP core: bail on an empty posts array (found_posts stays 0), and
		// defensively bail if the stash is somehow missing. stash_found_rows_count()
		// runs on posts_clauses_request at PHP_INT_MAX for every query carrying
		// the perflocale_optimize_found_rows flag, so a flagged query that
		// reaches the_posts always has an entry — except when
		// unstash_on_short_circuit() deliberately removed it because another
		// plugin short-circuited posts_pre_query and is supplying its own
		// found_posts. Bailing leaves that value untouched.
		if ( $data === null || ( is_array( $posts ) && $posts === [] ) ) {
			return $posts;
		}

		if ( $data['has_limits'] ) {
			$cache = \PerfLocale\Plugin::get_instance()->get( 'cache' );

			if ( ! $cache instanceof \PerfLocale\Cache\CacheManager ) {
				return $posts;
			}

			// Fold the group generation into the key itself. flush_found_rows_cache()
			// invalidates by bumping the generation, but only the L2 object-cache key
			// is generation-prefixed — the L3 transient key is not. On a site with no
			// persistent object cache the count lives in that transient, so a bare
			// bump would leave it stale. Embedding the generation in the key changes
			// the key on every bump, so L1/L2/L3 alike miss the old entry and the
			// count is recomputed. Orphaned prior-generation transients self-expire
			// via the TTL.
			$generation = \PerfLocale\Cache\CacheManager::l2_generation( self::FOUND_ROWS_GROUP );
			$key        = 'fr:' . $generation . ':' . md5( $data['sql'] );

			// is_numeric, NOT is_int: the Redis object-cache drop-in stores bare
			// integers as numeric strings and returns them as strings on a
			// cross-request read (same-process reads come back from its runtime
			// cache still typed int, which masks this in CLI tests). A strict
			// is_int() here rejects every warm read and re-runs the COUNT on
			// every request.
			$cached = $cache->get_cached( $key, self::FOUND_ROWS_GROUP );
			$count  = is_numeric( $cached ) ? (int) $cached : null;

			if ( $count === null ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Count SQL assembled from WP core's own clauses + the language WHERE that modify_query_clauses() already $wpdb->prepare()'d (no raw user input reaches it); the result IS cached generationally on the very next line.
				$count = (int) $wpdb->get_var( $data['sql'] );
				$cache->set( $key, $count, HOUR_IN_SECONDS, self::FOUND_ROWS_GROUP );
			}
		} else {
			// No LIMIT (show all / nopaging): WP core counts the returned posts.
			$count = is_array( $posts ) ? count( $posts ) : ( $posts === null ? 0 : 1 );
		}

		// Re-apply WP core's found_posts filter so plugins that hook it still run
		// for these queries — WP skips it when no_found_rows is set.
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
	 * Bump the found_posts count-cache generation on a publish-boundary
	 * transition. Fires on every status change; only a post entering or leaving
	 * 'publish' can move an archive count, so skip everything else to avoid
	 * needless cache churn (e.g. draft autosaves, publish→publish edits).
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 * @return void
	 */
	public function flush_found_rows_on_transition( $new_status, $old_status ): void {
		// Skip only when NEITHER side is published (draft→draft autosaves etc.).
		// publish→publish deliberately bumps: editing a published post can move
		// it between date/author/post-type archives without a status change, so
		// skipping same-status saves served stale counts (page-2 links that 404)
		// for up to the cache TTL. WP core bumps its own `last_changed` on these
		// saves too, so this adds no invalidation the baseline didn't already pay.
		if ( $new_status !== 'publish' && $old_status !== 'publish' ) {
			return;
		}

		$this->flush_found_rows_cache();
	}

	/**
	 * Bump the count-cache generation when a PUBLISHED post is permanently
	 * deleted (which fires no transition_post_status). Deleting a draft,
	 * auto-draft, revision, or already-trashed post can't move a published
	 * archive count — and those are the bulk of deleted_post fires (revision GC,
	 * auto-draft cleanup) — so skip them to avoid needless cache churn.
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
	 * Invalidate every cached found_posts count for the current blog by bumping
	 * the generation token. Cheap (one autoloaded-option write) and per-blog
	 * isolated; the next archive view recomputes and re-caches.
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
	 * Build (and memoise) the JOIN + WHERE that scope a Previous/Next query to
	 * the VIEWED post's language, mirroring modify_query_clauses()' non-strict
	 * fallback semantics. Returns null when scoping doesn't apply (admin, a
	 * non-translatable post type, an unassigned post, or a site with no groups)
	 * so the filters pass the SQL through untouched.
	 *
	 * The JOIN keys on `p.ID` because get_adjacent_post() queries
	 * `FROM {$wpdb->posts} AS p`, not the bare posts table.
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

			// An UNMANAGED viewed post (no language assignment) must not
			// drop scoping entirely — the request language still defines
			// what prev/next should offer, otherwise a /de/ visitor gets
			// cross-language adjacent links (mirrors modify_query_clauses'
			// request-language fallback). Unmanaged neighbours stay included
			// via the OR-IS-NULL clause below either way.
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

				// Non-strict (fallback parity with the frontend loop): same-
				// language linked posts OR unmanaged posts (no post-link); other
				// languages are excluded.
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
	 *
	 * WordPress's wp_page_menu() fallback (used by themes like Neve when no
	 * menu is assigned) calls get_pages() which bypasses WP_Query language
	 * filtering. This ensures only pages in the current language are returned.
	 *
	 * @param array<int, \WP_Post> $pages Pages returned by get_pages().
	 * @param array<string, mixed> $args Parsed get_pages() arguments.
	 * @return array<int, \WP_Post>
	 */
	public function filter_get_pages( array $pages, array $args ): array {
		if ( is_admin() || empty( $pages ) ) {
			return $pages;
		}

		// Cache request-constants (current language + translatable types) so
		// the 10+ invocations per archive page don't each redo these.
		// Class-static (not method-static) so reset_static_caches() can nuke
		// it on switch_blog without stale language context.
		if ( self::$get_pages_cfg_cache === null ) {
			self::$get_pages_cfg_cache = [
				'lang_id' => $this->router->get_current_language_id(),
				'tt'      => $this->settings->get_translatable_post_types(),
			];
		}
		if ( self::$get_pages_cfg_cache['lang_id'] === 0 ) {
			return $pages;
		}

		// get_pages() defaults to 'page' but accepts any hierarchical type
		// (and WP_Query-style arrays). Translatability is per-type, so gate on
		// the QUERIED type(s) — a translatable hierarchical CPT must be
		// filtered even when 'page' itself is not translatable. Unlinked posts
		// are always KEPT below, so for a mixed array one translatable member
		// is enough to make filtering both safe and necessary.
		$queried = (array) ( $args['post_type'] ?? 'page' );

		if ( array_intersect( $queried, self::$get_pages_cfg_cache['tt'] ) === [] ) {
			return $pages;
		}
		$language_id = self::$get_pages_cfg_cache['lang_id'];

		// Bulk-hydrate the per-page cache for unseen IDs. Scoping the query to
		// only the IDs in `$pages` (a bounded IN-list, ~100–200µs) avoids
		// loading the FULL post-type translation_links table (~1.7ms at 1k+
		// rows) and caches the results for later calls.
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

			// Also prime the TRANSLATIONS cache for these pages in the same
			// batch: the page-list block / wp_list_pages render that called
			// get_pages will fire filter_page_link for every page next, and
			// each one needs get_translations(). Without this, every page
			// pays a cold per-object lookup (~85µs against Redis, more
			// against the DB) — ~100 menu pages ≈ 9ms per request. One
			// batched prime makes them all L1 hits.
			$this->get_groups_repo()->prime_translations( \PerfLocale\Enum\ObjectType::Post, $needs_lookup );
		}

		$lang_map = self::$page_language_map_cache;

		// Two-pass loop: pass 1 detects whether ANY page needs dropping,
		// so the common case (all pages already match the current language)
		// returns the original $pages array unchanged - no allocation, no
		// rebuild. array_filter always walks every element AND rebuilds
		// the array even when nothing changes.
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
	 * Populate `page_language_map_cache` with rows for the given post IDs.
	 *
	 * Uses a single IN-list query scoped to exactly the IDs we need -
	 * scales O(IDs) rather than O(all-translated-posts) as the previous
	 * full-table scan did. IDs without a translation row get a null
	 * sentinel so subsequent calls don't re-query them.
	 *
	 * @param array<int, int> $post_ids Post IDs to hydrate.
	 * @return void
	 */
	private function hydrate_page_language_map( array $post_ids ): void {
		if ( $post_ids === [] ) {
			return;
		}

		// Zero-state short-circuit: if no translation groups exist on
		// this blog, the JOIN below is guaranteed to return 0 rows. Seed
		// the cache with negative entries so subsequent `filter_get_pages`
		// calls hit the cache instead of repeating this fruitless query.
		// `has_any_groups()` caches its TRUE answer aggressively (L1 + L2
		// across requests), so the check is sub-µs on real multilingual
		// sites and we proceed to the JOIN below.
		$groups_repo = $this->get_groups_repo();

		if ( ! $groups_repo->has_any_groups() ) {
			foreach ( $post_ids as $pid ) {
				if ( ! array_key_exists( $pid, self::$page_language_map_cache ) ) {
					self::$page_language_map_cache[ $pid ] = null;
				}
			}

			return;
		}

		// Eager-link-map fast path. On sites under the size caps, every
		// post translation link is already in an alloptions-cached map
		// (no DB roundtrip per request). Pull the relevant rows from
		// there instead of repeating the JOIN below. Falls through to
		// the JOIN on bigger sites where get_eager_link_map returns null.
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

				// Unmanaged (no translation group) → negative cache.
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

		// Negative-cache unmanaged IDs so we don't re-query them next call.
		foreach ( $post_ids as $pid ) {
			if ( ! array_key_exists( $pid, self::$page_language_map_cache ) ) {
				self::$page_language_map_cache[ $pid ] = null;
			}
		}
	}

	/**
	 * Handle missing translations on singular pages.
	 *
	 * When viewing a post/page in a language that has no translation,
	 * apply the configured missing_translation_action:
	 * - show_default: show the post as-is in its original language (no redirect)
	 * - show_404: return a 404 error
	 * - redirect_default: redirect to the default-language version of the post
	 *
	 * Also implements language fallback chain: if the current language
	 * has a fallback configured, try the fallback language first.
	 *
	 * @return void
	 */
	public function handle_missing_translation(): void {
		// Re-entry fuse: even if template_redirect somehow fires twice in
		// one request, never act twice. Cheap; avoids edge-case double
		// redirects (e.g. interaction with third-party template_redirect
		// handlers that re-trigger the hook chain).
		static $handled = false;

		if ( $handled ) {
			return;
		}

		$handled = true;

		// Sentinel guard: if this request is the landing point of a
		// fallback redirect we just issued, never fall back again. Even if
		// a translation-group inconsistency would otherwise cause a loop,
		// the presence of `?perflocale_fb=1` short-circuits it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_fb'] ) ) {
			return;
		}

		if ( is_admin() || ! is_singular() ) {
			return;
		}

		// Body-preserving methods only. POST/PUT/DELETE/PATCH would lose
		// their payload through a 302, so we skip the fallback walker on
		// those entirely - WP handles the request normally, form
		// submissions reach their handlers.
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

		// Check if this post IS in the current language.
		$post_lang = $this->get_post_language_id( $post->ID );

		if ( $post_lang === $language_id ) {
			return; // Post matches the current language - all good.
		}

		// Post is NOT in the current language - the viewer reached it via
		// a direct URL with a non-matching prefix (e.g. /en-us/page/ when
		// no en-US translation exists but a sibling does).
		$current_slug = $this->router->get_current_slug();

		// Prefer the CURRENT language's own sibling before consulting the
		// fallback chain. The visitor asked for this language and the group
		// has a translation in it — they just landed on another language's
		// slug under this prefix (e.g. /de/<en-slug>/). Redirecting to the
		// current-language sibling keeps them in the requested language;
		// walking the fallback chain here would send a German request to the
		// first chain language (e.g. Polish) even though German exists.
		$translations_map = $this->get_translations_map( $post->ID );
		$own_id           = (int) ( $translations_map[ $language_id ] ?? 0 );

		if ( $own_id > 0 && $own_id !== (int) $post->ID ) {
			$own_post = get_post( $own_id );

			if ( $own_post instanceof \WP_Post && $own_post->post_status === 'publish' ) {
				$own_url = get_permalink( $own_id );

				if ( is_string( $own_url ) && $own_url !== '' && ! $this->is_current_url( $own_url ) ) {
					$this->redirect_to_fallback( $own_url, $current_slug, $current_slug, (int) $post->ID, $own_id );
					// redirect_to_fallback() calls exit - unreachable below.
				}
			}
		}

		$fallbacks = $this->settings->get_language_fallbacks();
		$chain     = $fallbacks[ $current_slug ] ?? [];

		if ( $chain !== [] ) {
			// One translation-group lookup up front; the walk is pure
			// in-memory after this. Primes post caches so the publish-
			// status check below is a cache hit, not a DB round-trip.
			$translations_map = $this->get_translations_map( $post->ID );

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

					$target_url = get_permalink( $target_id );

					if ( ! is_string( $target_url ) || $target_url === '' ) {
						continue;
					}

					// If the browser is already on the target URL, stop -
					// avoids a redirect loop when get_permalink() happens to
					// return the current URL. (Loop immunity is also enforced
					// at a higher layer via the `?perflocale_fb=1` sentinel,
					// but a same-URL short-circuit here is a cheap extra fuse.)
					if ( $this->is_current_url( $target_url ) ) {
						continue;
					}

					$this->redirect_to_fallback( $target_url, $current_slug, $try_slug, (int) $post->ID, $target_id );
					// redirect_to_fallback() calls exit - unreachable below.
				}
			}
		}

		// Chain exhausted (or empty) - apply the configured missing-
		// translation action exactly as before.
		$action = $this->settings->get_missing_translation_action();

		if ( $action === 'show_404' ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		if ( $action === 'redirect_default' ) {
			$default = $this->router->get_default_language();

			if ( $default ) {
				// Reuse the translations map if we already loaded it above.
				$translations_map = $translations_map ?? $this->get_translations_map( $post->ID );
				$default_post_id  = (int) ( $translations_map[ (int) $default->id ] ?? 0 );

				// Accept the case where the queried post IS the default-
				// language sibling (WP found it via slug match when no
				// current-language translation exists). We still want to
				// canonicalise the URL to the default-language version -
				// get_permalink() builds it under the post's own language
				// regardless of what language was detected on the request.
				if ( $default_post_id > 0 ) {
					$default_post = get_post( $default_post_id );

					if ( $default_post instanceof \WP_Post && $default_post->post_status === 'publish' ) {
						$default_url = get_permalink( $default_post_id );

						if ( is_string( $default_url ) && $default_url !== '' ) {
							$this->redirect_to_fallback( $default_url, $current_slug, $default->slug, (int) $post->ID, $default_post_id );
						}
					}
				}

				// No default-language version at all - redirect to the
				// default language's homepage. Build the URL from raw
				// get_option('home') so we bypass our own home_url filter
				// (which would prefix the *current* detected language
				// instead of the default).
				$home_base = rtrim( (string) get_option( 'home' ), '/' );

				if ( $home_base !== '' ) {
					// A path prefix only exists in subdirectory mode. Query
					// mode's default language is always the bare home, and
					// subdomain/domain modes carry language in the hostname —
					// concatenating /en/ there builds a URL with no route.
					$prefix_default = $this->settings->get_url_mode() === 'subdirectory'
						&& ! $this->settings->hide_default_prefix();

					$home_url = $prefix_default
						? $home_base . '/' . $this->settings->get_url_prefix( $default ) . '/'
						: $home_base . '/';

					$this->redirect_to_fallback( $home_url, $current_slug, $default->slug, (int) $post->ID, 0 );
				}
			}
		}

		// 'show_default' action (default behavior): do nothing - the post is shown as-is
		// in whatever language it's in. This is the safest fallback.
	}

	/**
	 * Is the given absolute URL the same request the browser is currently
	 * making? Compares scheme + host + path (query string ignored -
	 * the sentinel parameter on the redirect target would always differ
	 * but we don't want that to count as "different URL").
	 *
	 * @param string $url Absolute URL to compare against the current request.
	 * @return bool
	 */
	private function is_current_url( string $url ): bool {
		// esc_url_raw, not sanitize_text_field (which strips %XX octets and
		// would make a percent-encoded non-Latin request never match $url).
		$request_uri  = ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/' );
		$request_path = strtok( $request_uri, '?' ) ?: '/';

		$target_path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );

		if ( untrailingslashit( $request_path ) !== untrailingslashit( $target_path ) ) {
			return false;
		}

		// Paths match — but under plain permalinks the OBJECT identity lives in
		// the query string, so /es/?p=100 and /es/?p=200 share the path "/es/"
		// while naming different posts. Compare only the identity params
		// (p / page_id): tracking junk on the current request must not defeat
		// the loop fuse, and pretty-permalink URLs (no identity params on
		// either side) keep the original pure-path semantics.
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

		return true;
	}

	/**
	 * Issue a fallback redirect. Centralised so the sentinel param,
	 * redirect-status filter, allow-list, and `exit` all live in one place
	 * that's hard to get wrong.
	 *
	 * @param string $url Target URL (from get_permalink / home_url).
	 * @param string $from_slug Current language slug.
	 * @param string $to_slug Target language slug.
	 * @param int    $from_post_id Source post being rendered.
	 * @param int    $to_post_id Target post ID (0 for homepage fallback).
	 * @return never
	 */
	private function redirect_to_fallback( string $url, string $from_slug, string $to_slug, int $from_post_id, int $to_post_id ): void {
		// Preserve the original request's query string (UTM, click IDs,
		// previews, etc.) so marketing attribution survives the fallback
		// redirect. The `perflocale_fb` sentinel is added on top; if the
		// original had conflicting values they are overwritten by ours.
		// esc_url_raw, not sanitize_text_field. _sanitize_text_fields() loops
		// `preg_replace('/%[a-f0-9]{2}/i', '', …)` until no escape remains, so it
		// DELETES every percent-escape in the string: a Japanese site search
		// `?s=%E3%83%8B...` arrives at the redirect target as a bare `?s`, and
		// `utm_campaign=Fr%C3%BChling` becomes `Frhling`. The sibling
		// is_current_url() already uses esc_url_raw for exactly this reason, and
		// so does every REQUEST_URI read in this codebase.
		//
		// The '/?' prefix is load-bearing, and its absence was a bug: esc_url()
		// prepends a scheme to anything that does not look like a path, so a BARE
		// query string came back as `http://utm_source=nl&…` and parse_str() then
		// read the first parameter's name as `http://utm_source`. REQUEST_URI
		// escapes this because it always starts with '/'. Prefixing gives
		// esc_url_raw a path to recognise; the prefix is stripped straight back
		// off, and the result is byte-identical to the input for every query
		// string tested.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on the very next line by esc_url_raw(); it cannot be applied here because esc_url() prepends a scheme to a value that does not look like a path, which is the bug this replaced.
		$raw_qs     = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( (string) $_SERVER['QUERY_STRING'] ) : '';
		$safe_qs    = $raw_qs === '' ? '' : esc_url_raw( '/?' . $raw_qs );
		$qs_mark    = strpos( $safe_qs, '?' );
		$request_qs = ( $safe_qs !== '' && $qs_mark !== false ) ? substr( $safe_qs, $qs_mark + 1 ) : '';

		if ( $request_qs !== '' ) {
			$passthrough = [];

			parse_str( $request_qs, $passthrough );

			if ( is_array( $passthrough ) && $passthrough !== [] ) {
				// Never forward `perflocale_fb` - the walker will re-add it,
				// and accepting a user-supplied value would let attackers
				// short-circuit the fallback guard.
				unset( $passthrough['perflocale_fb'] );

				// Never forward WP object-routing params either: they name the
				// post we are redirecting AWAY from. Under plain permalinks the
				// TARGET's identity lives in its own ?p=; add_query_arg would
				// let the stale request's p= clobber it, sending the visitor
				// straight back to the wrong-language post (redirect ping-pong).
				// Under pretty permalinks they are dead weight on a pathful URL.
				unset(
					$passthrough['p'],
					$passthrough['page_id'],
					$passthrough['name'],
					$passthrough['pagename'],
					$passthrough['attachment_id']
				);

				// In query mode `lang` IS the language-routing param — as much
				// the redirected-away-from request's identity as ?p= above. The
				// target URL already carries the TARGET's own ?lang= (or none
				// for the default language); re-applying the stale request
				// value re-triggers the fallback walker on the landing request:
				// an infinite 302/301 loop, because clean_fallback_sentinel
				// strips the sentinel before the walker's fuse can hold.
				if ( $this->settings->get_url_mode() === 'query' ) {
					// Strip the CONFIGURED name, not the literal 'lang'. With the
					// name filtered, a hard-coded key left the stale value in the
					// target URL and the fallback walker re-fired on an identical
					// URL — the infinite redirect the comment above describes.
					unset( $passthrough[ \PerfLocale\Router\UrlConverter::query_var() ] );
				}

				if ( $passthrough !== [] ) {
					$url = add_query_arg( $passthrough, $url );
				}
			}
		}

		// Append the sentinel so the landing URL never re-triggers the
		// fallback walker, regardless of any upstream misconfiguration.
		$url = add_query_arg( 'perflocale_fb', '1', $url );

		/**
		 * Filter the HTTP status code used for language-fallback redirects.
		 *
		 * Default 302 (temporary) so fallbacks naturally stop being served
		 * once the real translation is published. Sites prioritising SEO
		 * consolidation can return 301 for permanent redirects.
		 *
		 * Return values outside the allow-list {301, 302, 307, 308} are
		 * coerced back to 302 - prevents filter misuse from breaking
		 * browsers (e.g. a rogue 200 wouldn’t redirect at all).
		 *
		 * @hook perflocale/fallback/redirect_status
		 * @param int $status Default 302.
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
	 * Return a `[language_id =&gt; post_id]` map for every translation of
	 * the given post, using the already-cached translation-group lookup
	 * and priming the post cache for the candidates (so subsequent
	 * `get_post()` calls are free).
	 *
	 * Per-request memo: the same post is looked up by the fallback chain
	 * walker, the `redirect_default` branch, HreflangTags, LanguageSwitcher,
	 * etc. in a single request. Compute once.
	 *
	 * @param int $post_id Source post.
	 * @return array<int, int>
	 */
	private function get_translations_map( int $post_id ): array {
		// blog_id:post_id key — class-static cleared on switch_blog so a
		// CLI long-runner or REST cross-blog hop can't serve blog A's map
		// to blog B (Bundle-A multisite-safety pattern).
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

		// Batch-prime the post cache so the publish-status + permalink
		// checks that follow are in-memory hits. $ids is already unique:
		// get_translations() returns one row per (group_id, language_id)
		// pair (UNIQUE composite key), and object_id is a function of
		// that key — so the same post_id can't appear twice in a single
		// group's link list. array_unique() here was dead work.
		if ( $ids !== [] && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}

		self::$translations_map_cache[ $key ] = $map;

		return $map;
	}

	/**
	 * Strip the `perflocale_fb` sentinel from the URL via a 301 canonical
	 * redirect so it doesn’t stick in the address bar after a fallback.
	 *
	 * Mirrors the pattern used by {@see LanguageRouter::clean_redirect_sentinel()}.
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
