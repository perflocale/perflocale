<?php
/**
 * URL converter - transforms URLs between languages.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\SlugTranslationRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts URLs between languages by injecting the correct language
 * prefix and substituting translated slugs.
 *
 * Hooks into permalink filters to automatically add language prefixes
 * to all WordPress-generated URLs.
 */
final class UrlConverter {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var SlugManager
	 */
	private readonly SlugManager $slug_manager;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Flag to prevent recursive filter calls.
	 *
	 * @var bool
	 */
	private bool $filtering = false;

	/**
	 * Soft cap for the per-request static memo arrays.
	 *
	 * Each memo below grows one entry per unique input. On a pathological
	 * request (e.g. a sitemap rebuild walking 10k posts, or a long-running
	 * WP-CLI command) these can balloon to a large arrays over the course
	 * of one PHP process. Cap the size and slice the oldest 25% when hit,
	 * mirroring the eviction pattern in CacheManager::set().
	 */
	private const MAX_CACHE_ENTRIES = 500;

	/**
	 * Default language query variable for query-parameter URL mode.
	 */
	public const DEFAULT_QUERY_VAR = 'lang';

	/**
	 * Per-request memoized read of `permalink_structure`.
	 *
	 * The option lives in WP's autoload cache so the underlying read is
	 * cheap (~1 µs), but it's called from convert(), resolve_query_string_url(),
	 * and the trailingslashit guard at line ~1131 — each of which runs once
	 * per permalink/page-link/post-type-link filter pass, i.e. dozens of
	 * times per page render. A single static memo collapses the work to one
	 * read per request. reset_static_caches() clears the memo on switch_blog
	 * (each multisite blog can have its own permalink_structure) and on
	 * language CRUD (no direct relation but consistent with the other
	 * memos in this class).
	 *
	 * @return bool
	 */
	private static function has_pretty_permalinks(): bool {
		if ( self::$has_pretty_permalinks_memo === null ) {
			self::$has_pretty_permalinks_memo = (bool) get_option( 'permalink_structure' );
		}

		return self::$has_pretty_permalinks_memo;
	}

	/**
	 * Clear every request-scoped static memo this class owns.
	 *
	 * Called on switch_blog (multisite isolation) and on language CRUD
	 * events (so URL building after a programmatic add / rename / delete
	 * picks up the new state for the rest of the request).
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$object_language_cache         = [];
		self::$home_url_memo                 = [];
		self::$object_permalink_memo         = [];
		self::$lazy_group_repo               = null;
		self::$lang_id_map                   = null;
		self::$is_admin_cached               = null;
		self::$excluded_paths_cached         = null;
		self::$skip_url_modification_memo    = null;
		self::$url_config_memo               = null;
		self::$home_host                     = null;
		self::$lang_map                      = null;
		self::$home_path                     = null;
		self::$home_trimmed                  = null;
		self::$sorted_prefixes               = null;
		self::$has_pretty_permalinks_memo    = null;
		self::$front_page_translation_memo   = [];
		self::$front_page_translation_primed = false;
		self::$primed_nav_refs               = [];
		self::$query_var_memo                = null;
	}

	/**
	 * Language query variable name used by query-parameter URL mode.
	 *
	 * Filterable so a site already using `lang` for something else (Polylang
	 * leftovers, a theme, a page builder) can move PerfLocale out of the way
	 * without touching rewrite rules. The result is passed through
	 * sanitize_key() and falls back to the default when a filter returns
	 * something unusable, so the value is always a safe query-arg name.
	 *
	 * The writer (UrlConverter), the reader (LanguageRouter), the rewrite
	 * rules (RewriteManager), the fallback redirect walker (PostQueryFilter)
	 * and the Site Health rewrite probe all call this, so they cannot disagree
	 * about which variable to use. Bootstrap primes it during load so admin
	 * and front-end requests freeze the same value at the same moment.
	 *
	 * @since 1.0.1
	 *
	 * @return string Sanitised query variable name. Never empty.
	 */
	public static function query_var(): string {
		if ( self::$query_var_memo !== null ) {
			return self::$query_var_memo;
		}

		/**
		 * Filters the query variable used for query-parameter URL mode.
		 *
		 * Fires once per request per blog. The returned value is passed
		 * through sanitize_key(); an empty or non-string result falls back
		 * to the default.
		 *
		 * REGISTER THIS FROM AN MU-PLUGIN. The name is resolved during
		 * PerfLocale's own load (Bootstrap primes it next to
		 * detect_locale_early), because query-mode language detection has to
		 * read the request before `plugins_loaded` fires. A filter added from
		 * an ordinary plugin or a theme's functions.php runs too late and is
		 * ignored — silently, because there is nothing to warn about at that
		 * point. mu-plugins load first, so that is the supported home for it.
		 *
		 * Renaming this on a live site changes stored rewrite rules in
		 * subdirectory mode, so flush permalinks (Settings > Permalinks)
		 * after adding or changing the filter. Existing URLs carrying the
		 * old name stop resolving, so treat a rename as a URL change.
		 *
		 * @since 1.0.1
		 *
		 * @param string $query_var Query variable name. Default 'lang'.
		 */
		$query_var = apply_filters( 'perflocale/url/query_var', self::DEFAULT_QUERY_VAR );

		$query_var = is_string( $query_var ) ? sanitize_key( $query_var ) : '';

		self::$query_var_memo = '' !== $query_var ? $query_var : self::DEFAULT_QUERY_VAR;

		return self::$query_var_memo;
	}

	/**
	 * Per-request URL-config snapshot (mode, default language, hide-default,
	 * translate-slugs), shared by every URL-conversion entry point so the
	 * ~200+ conversions on a render dispatch Settings::get + the
	 * perflocale/url_mode filter once instead of per call. Class-level memo
	 * so reset_static_caches() invalidates it when settings or languages
	 * mutate mid-request; the url_mode filter's contract guarantees the
	 * value is request-stable.
	 *
	 * @return array{url_mode: string, default: ?object, hide_default: bool, translate_slugs: bool}
	 */
	private function url_config(): array {
		if ( self::$url_config_memo === null ) {
			self::$url_config_memo = [
				'url_mode'        => $this->settings->get_url_mode(),
				'default'         => $this->router->get_default_language(),
				'hide_default'    => $this->settings->hide_default_prefix(),
				'translate_slugs' => $this->settings->translate_slugs_enabled(),
			];
		}

		return self::$url_config_memo;
	}

	/**
	 * Evict the oldest 25% of entries when the cap is reached.
	 *
	 * Called from every write site on the capped memos. The check is
	 * O(1) for under-cap writes (just count()), so the overhead is
	 * negligible in the common case.
	 *
	 * @param array<string, mixed> $cache Reference to the memo array.
	 * @return void
	 */
	private static function cap_cache( array &$cache ): void {
		if ( count( $cache ) < self::MAX_CACHE_ENTRIES ) {
			return;
		}

		$cache = array_slice( $cache, (int) ( self::MAX_CACHE_ENTRIES * 0.25 ), null, true );
	}

	/**
	 * Should every link filter on this request return the input URL unchanged?
	 *
	 * Returns true when the current request can't possibly result in a
	 * link-rewrite — i.e., subdirectory mode + hide_default_prefix +
	 * visitor on default language + no translation groups exist + no
	 * translated slugs exist. In that state, every filter_*_link / filter_home_url
	 * call would just produce the same URL back after a 5-7 µs round-trip
	 * through resolve_object_language + inject_prefix. The memo collapses
	 * the whole filter chain to a single isset() per call.
	 *
	 * Memoized per request; reset on `switch_blog` so the answer is
	 * recomputed for the new blog's context.
	 *
	 * @return bool
	 */
	private function should_skip_url_modification(): bool {
		// During site install / upgrade / multisite subsite creation
		// (wp_initialize_site sets wp_installing() true for its whole run) the
		// blog's perflocale tables may not exist yet, and there is nothing to
		// translate anyway. Skip URL modification — and the has_any_slugs() /
		// has_any_groups() probes that would otherwise query not-yet-created
		// tables and log DB errors — without memoizing, so normal detection
		// resumes for real requests once installation finishes.
		if ( wp_installing() ) {
			return true;
		}

		if ( self::$skip_url_modification_memo !== null ) {
			return self::$skip_url_modification_memo;
		}

		// Only two shapes can produce universally-unchanged URLs: subdirectory
		// with a hidden default prefix, and query mode (whose default language
		// never carries the ?lang= parameter). Subdomain / domain modes always
		// rewrite the host.
		$skip_mode = $this->settings->get_url_mode();

		if ( $skip_mode !== 'subdirectory' && $skip_mode !== 'query' ) {
			self::$skip_url_modification_memo = false;
			return self::$skip_url_modification_memo;
		}

		if ( $skip_mode === 'subdirectory' && ! $this->settings->hide_default_prefix() ) {
			self::$skip_url_modification_memo = false;
			return self::$skip_url_modification_memo;
		}

		if ( ! $this->router->is_default_language() ) {
			self::$skip_url_modification_memo = false;
			return self::$skip_url_modification_memo;
		}

		// At least one translated slug would require us to rewrite even
		// for the default language. Cheap check via the slug repo's
		// has_any_slugs() (cached aggressively).
		if ( $this->settings->translate_slugs_enabled() ) {
			$slug_repo = new SlugTranslationRepository( $this->cache );

			if ( $slug_repo->has_any_slugs() ) {
				self::$skip_url_modification_memo = false;
				return self::$skip_url_modification_memo;
			}
		}

		// At least one group means some post lives in a non-default
		// language and would need prefixing.
		if ( self::$lazy_group_repo === null ) {
			self::$lazy_group_repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		}

		if ( self::$lazy_group_repo->has_any_groups() ) {
			self::$skip_url_modification_memo = false;
			return self::$skip_url_modification_memo;
		}

		self::$skip_url_modification_memo = true;
		return self::$skip_url_modification_memo;
	}

	/**
	 * Batch-preloaded object language cache.
	 *
	 * Populated by preload_object_languages() on the_posts hook.
	 * Keyed by "{type}_{id}" → language object.
	 *
	 * @var array<string, object|null>
	 */
	private static array $object_language_cache = [];

	/**
	 * Per-request memoization for filter_home_url().
	 *
	 * WordPress calls home_url() hundreds of times per page render (nav, feed
	 * links, asset URLs, theme fragments, widgets). The same (url, path) input
	 * produces the same output deterministically during a request, so caching
	 * collapses the work. Keyed by "{url}|{path}".
	 *
	 * @var array<string, string>
	 */
	private static array $home_url_memo = [];

	/**
	 * Per-request memo of the parsed home host for subdomain/domain mode.
	 * Class-static (not function-static) so reset_static_caches() clears it
	 * on switch_blog — home_url()'s host differs per blog on multisite.
	 *
	 * @var string|null
	 */
	private static ?string $home_host = null;

	/**
	 * Per-request slug => language-object map used by convert(). Class-static
	 * so reset_static_caches() clears it on language CRUD (matching
	 * $lang_id_map) — otherwise convert() would serve a stale language set
	 * after a programmatic add/rename/delete within the same request.
	 *
	 * @var array<string, object>|null
	 */
	private static ?array $lang_map = null;

	/**
	 * Memoised name of the language query variable.
	 *
	 * The perflocale/url/query_var filter fires ONCE per request (per blog,
	 * since reset_static_caches() clears this on switch_blog) no matter how
	 * many URLs get built, so a render that converts 200 URLs pays for one
	 * apply_filters call, not 200. Every later read is a property hit.
	 *
	 * @var string|null
	 */
	private static ?string $query_var_memo = null;

	/**
	 * Per-request memo of the home-URL PATH (strip_language_prefix_from_path)
	 * and the trimmed home URL (inject_prefix). Class-static so
	 * reset_static_caches() clears them on switch_blog — both differ per blog
	 * on multisite.
	 *
	 * @var string|null
	 */
	private static ?string $home_path = null;

	/**
	 * @var string|null
	 */
	private static ?string $home_trimmed = null;

	/**
	 * Per-request list of active-language URL prefixes (strip_prefix). Class-
	 * static so reset_static_caches() clears it on switch_blog AND language
	 * CRUD — active languages/prefixes are per-blog and change on CRUD.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $sorted_prefixes = null;

	/**
	 * Per-request memo of the `permalink_structure` option's truthiness. Class-
	 * static so reset_static_caches() clears it on switch_blog — each blog on
	 * multisite has its own permalink_structure (some use plain, some pretty).
	 *
	 * @var bool|null
	 */
	private static ?bool $has_pretty_permalinks_memo = null;

	/**
	 * Per-request memo for is_front_page_translation(). Class-static so
	 * reset_static_caches() clears it on switch_blog AND on language CRUD —
	 * the front-page id and its translation group can both change cross-blog,
	 * and a language delete in-request changes group membership.
	 *
	 * @var array<int, bool>
	 */
	private static array $front_page_translation_memo = [];

	/**
	 * wp_navigation post IDs whose items were already batch-primed this
	 * request (Navigation-block path). Class-static + reset on switch_blog:
	 * the refs are per-blog post IDs.
	 *
	 * @var array<int, bool>
	 */
	private static array $primed_nav_refs = [];

	/**
	 * One-shot prime guard for the front-page translation group. Same lifetime
	 * rules as $front_page_translation_memo above.
	 *
	 * @var bool
	 */
	private static bool $front_page_translation_primed = false;

	/**
	 * Per-request memoization for filter_post_permalink() / filter_page_link().
	 *
	 * Archive + nav menus call post_link once per post - but on sites with
	 * multiple sidebar widgets or cross-linked content, the same permalink can
	 * be requested multiple times. Keyed by "{object_type}_{object_id}_{url}".
	 *
	 * @var array<string, string>
	 */
	private static array $object_permalink_memo = [];

	/**
	 * Per-request is_admin() result.
	 *
	 * is_admin() is called 200+ times per request through filter_home_url
	 * (every nav menu link, every asset URL). The admin/frontend split
	 * can't flip mid-request, so cache the boolean once.
	 *
	 * @var bool|null
	 */
	private static ?bool $is_admin_cached = null;

	/**
	 * Per-request cache of Settings::get_excluded_paths().
	 *
	 * The excluded-paths list is settings-driven but stable for the
	 * request lifetime. Cached once instead of rebuilt per memo-miss.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $excluded_paths_cached = null;

	/**
	 * Per-request memo of "does this request need any URL modification at all?"
	 *
	 * Many sites land in a state where every link-filter call resolves to
	 * "return URL unchanged":
	 *
	 *   - subdirectory URL mode
	 *   - hide_default_prefix is on
	 *   - visitor is on the default language
	 *   - no translation groups exist on this blog (no post lives in
	 *     another language → resolve_object_language always returns
	 *     "current language" = default)
	 *   - no translated slugs exist (or translate_slugs is off)
	 *
	 * On those requests the 115+ link-filter invocations each end up
	 * paying ~5-7 µs to compute "same URL back". This memo lets each
	 * filter short-circuit to a single isset() check instead.
	 *
	 * `null` = not yet determined, `true` = skip URL modification entirely,
	 * `false` = run the normal filter logic.
	 *
	 * @var bool|null
	 */
	private static ?bool $skip_url_modification_memo = null;

	/**
	 * Per-request lang_id → language object map.
	 *
	 * Built lazily on first cold-path use of preload_object_languages so it
	 * can substitute for a separate LanguageRepository::find_many() DB call.
	 * Sourced from router's already-loaded active languages, so this is pure
	 * in-memory reuse.
	 *
	 * @var array<int, object>|null
	 */
	private static ?array $lang_id_map = null;

	/**
	 * Lazily-resolved translation-group repository, reused across sub-queries.
	 *
	 * preload_object_languages() runs on every WP_Query - 4–6× per request on
	 * archive pages with sidebars/widgets. Resolving the repo inside the hot
	 * path adds ~10–20µs per call; holding it static collapses that to
	 * resolve-once-per-request.
	 *
	 * reset_static_caches() nulls this alongside the data memos, but that is
	 * housekeeping, NOT blog isolation: Plugin::get() memoises its services
	 * and nothing clears the container on `switch_blog`, so re-resolving hands
	 * back the very same instance. Blog isolation for this repo comes from
	 * TranslationGroupRepository::reset_static_caches(), which Bootstrap hooks
	 * to `switch_blog` to clear the repo's own class-static caches. What DOES
	 * need the reset here is self::$object_language_cache, whose keys are
	 * "{type}_{object_id}" and therefore collide across blogs.
	 *
	 * @var TranslationGroupRepository|null
	 */
	private static ?TranslationGroupRepository $lazy_group_repo = null;

	/**
	 * Per-request URL-config snapshot consumed by add_language_prefix_to_url().
	 *
	 * The values (url_mode, default language, hide_default, translate_slugs)
	 * are stable for the duration of a single request unless settings or
	 * languages are mutated programmatically — in which case
	 * reset_static_caches() clears this memo alongside the others.
	 *
	 * @var array{url_mode:string,default:?object,hide_default:bool,translate_slugs:bool}|null
	 */
	private static ?array $url_config_memo = null;



	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param Settings       $settings Plugin settings.
	 * @param SlugManager    $slug_manager Slug manager.
	 * @param CacheManager   $cache Cache manager.
	 */
	public function __construct(
		LanguageRouter $router,
		Settings $settings,
		SlugManager $slug_manager,
		CacheManager $cache
	) {
		$this->router       = $router;
		$this->settings     = $settings;
		$this->slug_manager = $slug_manager;
		$this->cache        = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Batch preload object languages for all posts in a WP_Query result.
		// This eliminates N+1 queries in resolve_object_language() when
		// rendering permalinks on archive pages with multiple posts.
		add_filter( 'the_posts', [ $this, 'preload_object_languages' ], 5, 2 );

		// Nav-menu items aren't covered by the the_posts prime above, so each
		// menu link would cost its own transient read. The permalink filters
		// fire during wp_setup_nav_menu_item's array_map — before
		// wp_get_nav_menu_items — so hook pre_wp_nav_menu (which runs earlier),
		// look the items up ourselves, and prime their caches first.
		add_filter( 'pre_wp_nav_menu', [ $this, 'prime_nav_menu_items_early' ], 10, 2 );

		// Block themes never call wp_nav_menu(): the Navigation block renders
		// navigation-link/submenu blocks (usually from a wp_navigation post
		// via `ref`), so the prime above never fires there and every menu
		// link pays a cold per-object lookup. Collect the referenced object
		// IDs before the block renders and batch-prime the same caches.
		add_filter( 'pre_render_block', [ $this, 'prime_navigation_block_items' ], 10, 2 );

		// Navigation-link/submenu anchors render the SAVED `url` attribute
		// verbatim — core never calls get_permalink() for them, so the
		// permalink filters can't localize them and every explicit menu item
		// sent translated-page visitors back to the default language. Swap
		// the href for the current-language sibling's permalink at render
		// (rides the caches primed above).
		add_filter( 'render_block_core/navigation-link', [ $this, 'localize_navigation_link_block' ], 10, 2 );
		add_filter( 'render_block_core/navigation-submenu', [ $this, 'localize_navigation_link_block' ], 10, 2 );

		// REST pagination Link headers (and _links hrefs) are built from the
		// UNPREFIXED rest_url() base even when the request entered via
		// /<lang>/wp-json/ — an API client following rel=next silently
		// switched from the language-scoped collection to the
		// default-language one mid-pagination (different totals, different
		// content). Re-insert the ENTRY prefix, keyed on how the request
		// came in so wp-admin's unprefixed editor base stays untouched.
		// Three args: the third is the blog id, which the callback needs to
		// leave a SIBLING blog's REST base alone on multisite.
		add_filter( 'rest_url', [ $this, 'preserve_rest_language_prefix' ], 10, 3 );

		// Term-side analog of the the_posts prime: every term list a page
		// renders (per-post category links, tag/category widgets + blocks,
		// Woo product-category lists) fires filter_term_link per term, each
		// costing its own translation lookup without this batch prime.
		add_filter( 'get_terms', [ $this, 'preload_term_languages' ], 5 );
		add_filter( 'get_the_terms', [ $this, 'preload_term_languages' ], 5 );

		// oEmbed of a translated post URL 404s: WP's oEmbed controller resolves
		// the URL via url_to_postid(), whose internal query is language-scoped
		// to the REQUEST language, so a /de/ post URL resolves to 0. Re-resolve
		// with language scoping suspended.
		add_filter( 'oembed_request_post_id', [ $this, 'resolve_oembed_post_id' ], 10, 2 );

		$reset_static = [ self::class, 'reset_static_caches' ];

		// Reset static cache on blog switch to prevent multisite contamination.
		// Gated behind is_multisite() — switch_to_blog() is a no-op on
		// single-site, but still fires the action; without the gate, any
		// third-party plugin's switch_to_blog(get_current_blog_id()) call
		// would needlessly wipe our per-request memos mid-request.
		if ( is_multisite() ) {
			add_action( 'switch_blog', $reset_static );
		}

		// Invalidate data-derived memos when languages mutate mid-request.
		// $lang_id_map + $object_language_cache contain language-object
		// snapshots whose slug / locale / prefix can rename, and URL
		// memos (home_url_memo / object_permalink_memo) bake those same
		// snapshots into their output. Without invalidation, any URL
		// built after a programmatic add / rename / delete keeps using
		// the pre-change snapshot for the rest of the request.
		add_action( 'perflocale/language/added', $reset_static );
		add_action( 'perflocale/language/updated', $reset_static );
		add_action( 'perflocale/language/slug_renamed', $reset_static );
		add_action( 'perflocale/language/deleted', $reset_static );

		// The order-email render window swaps the current language via
		// LanguageRouter::override_current_language() from cron / admin /
		// gateway-webhook contexts. The home_url / object-permalink / object-
		// language memos (and the is_default_language()-folded skip memo) bake
		// in the current language, so wipe them when the override — and its
		// restore — fires, or URLs built for one order's language leak into
		// another order rendered in the same worker request.
		add_action( 'perflocale/language/overridden', $reset_static );

		// Filter all permalink-generating functions to add language identifier.
		add_filter( 'post_link', [ $this, 'filter_post_permalink' ], 10, 2 );
		add_filter( 'page_link', [ $this, 'filter_page_link' ], 10, 2 );
		add_filter( 'post_type_link', [ $this, 'filter_post_type_link' ], 10, 2 );

		// Attachment pages were the ONE frontend object type whose links
		// never carried a language prefix — visiting one from a /de/ page
		// silently ejected the visitor to the default language (and core's
		// canonical redirect made the ejection permanent). Same fallback
		// semantics as untranslated posts: unmanaged attachments resolve to
		// the CURRENT language's prefix.
		add_filter( 'attachment_link', [ $this, 'filter_attachment_link' ], 10, 2 );
		add_filter( 'term_link', [ $this, 'filter_term_link' ], 10, 3 );
		add_filter( 'home_url', [ $this, 'filter_home_url' ], 10, 4 );

		// Filter author and date archive links.
		add_filter( 'author_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'year_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'month_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'day_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'search_link', [ $this, 'add_language_prefix' ] );

		// Query mode: home_url() is deliberately left untouched (see
		// filter_home_url), so the two places where core DERIVES URLs from a
		// base need their own handling. (1) The GET search form: the browser
		// replaces the action URL's query string with the form fields, which
		// would silently drop ?lang= — inject it as a hidden field instead.
		// (2) Core extends permalinks by appending path segments AFTER
		// trailingslashit(get_permalink()) — on a ?lang=-carrying permalink
		// the segment lands inside the parameter value; relocate it.
		add_filter( 'get_search_form', [ $this, 'inject_search_form_lang_field' ], 20 );
		add_filter( 'render_block_core/search', [ $this, 'inject_search_form_lang_field' ], 20 );
		add_filter( 'post_comments_feed_link', [ $this, 'repair_query_lang_url' ] );
		add_filter( 'get_comments_pagenum_link', [ $this, 'repair_query_lang_url' ] );
		add_filter( 'get_comment_link', [ $this, 'repair_query_lang_url' ] );
		add_filter( 'post_embed_url', [ $this, 'repair_query_lang_url' ] );
		add_filter( 'wp_link_pages_link', [ $this, 'repair_query_lang_url_html' ] );

		// SEO plugins derive rel=prev/next the same permalink-extension way;
		// these filters simply never fire when the plugin is absent.
		add_filter( 'rank_math/frontend/next_rel_link', [ $this, 'repair_query_lang_url_html' ] );
		add_filter( 'rank_math/frontend/prev_rel_link', [ $this, 'repair_query_lang_url_html' ] );
		add_filter( 'wpseo_adjacent_rel_url', [ $this, 'repair_query_lang_url' ] );

		// WooCommerce AJAX endpoints are built from the (query-mode-unfiltered)
		// home URL, so fragments/add-to-cart requests would lose the language
		// — and with it per-language strings and currency. Carry it explicitly.
		add_filter( 'woocommerce_ajax_get_endpoint', [ $this, 'add_lang_to_wc_ajax_endpoint' ] );

		if ( $this->settings->translate_slugs_enabled() ) {
			// Resolve translated taxonomy slugs in incoming URLs so WordPress
			// can find the term by its database slug. Priority 3 runs after
			// LanguageRouter (priority 1) so the language is already detected.
			add_action( 'parse_request', [ $this, 'resolve_translated_taxonomy_slugs' ], 3 );

			// Redirect term archives from their database slug to the translated slug.
			add_action( 'template_redirect', [ $this, 'redirect_term_to_translated_slug' ] );
		}
	}

	/**
	 * Filter post permalinks to add language prefix.
	 *
	 * @param string $permalink The post permalink.
	 * @param object $post The post object (WP_Post or stdClass from direct DB queries).
	 * @return string Filtered permalink.
	 */
	public function filter_post_permalink( string $permalink, object $post ): string {
		if ( $this->filtering || ! isset( $post->ID ) ) {
			return $permalink;
		}

		if ( $this->should_skip_url_modification() ) {
			return $permalink;
		}

		$post_id  = (int) $post->ID;
		$memo_key = 'post_' . $post_id . '_' . $permalink;
		if ( isset( self::$object_permalink_memo[ $memo_key ] ) ) {
			return self::$object_permalink_memo[ $memo_key ];
		}

		$permalink = $this->resolve_query_string_url( $permalink, $post_id );
		self::cap_cache( self::$object_permalink_memo );
		self::$object_permalink_memo[ $memo_key ] = $this->add_language_prefix_to_url( $permalink, $post_id, 'post' );
		return self::$object_permalink_memo[ $memo_key ];
	}

	/**
	 * Filter page links to add language prefix.
	 *
	 * Both parameters are deliberately untyped. `get_page_link()` is the ONE
	 * permalink builder in core that does not guard its post lookup: it runs
	 * `$post = get_post( $post );` and then fires this filter with
	 * `$post->ID` — so when the id does not resolve (a page that was deleted
	 * after some plugin stored its id) PHP 8 emits "Attempt to read property
	 * ID on null", `$post->ID` evaluates to NULL, and a declared `int
	 * $post_id` threw a TypeError at ARGUMENT BINDING, before any guard in
	 * this body could run — turning core's warning into a fatal. Unpatched
	 * WordPress returns `home_url( '/?page_id=' )` there, so handing the
	 * input back UNCHANGED restores exactly the plugin-inactive behaviour.
	 * (`get_permalink()` is safe — it bails on `empty( $post->ID )` first —
	 * but everest-forms, ultimate-addons-for-gutenberg, wp-fastest-cache,
	 * oxygen and polylang all call `get_page_link()` directly with a stored
	 * page id.)
	 *
	 * @param mixed $link    The page link (any type; an earlier filter may
	 *                       have replaced it).
	 * @param mixed $post_id The page ID, or NULL when core could not resolve
	 *                       the post.
	 * @return mixed Filtered link, or the input unchanged.
	 */
	public function filter_page_link( $link, $post_id = 0 ) {
		if ( $this->filtering ) {
			return $link;
		}

		// is_numeric() rejects the NULL core passes for an unresolvable page
		// and any array/object a sloppy caller could produce, so the (int)
		// cast below cannot fatal. Numeric strings and floats still coerce
		// exactly as the previous `int $post_id` declaration did.
		if ( ! is_string( $link ) || ! is_numeric( $post_id ) ) {
			return $link;
		}

		$post_id = (int) $post_id;

		if ( $this->should_skip_url_modification() ) {
			return $link;
		}

		$memo_key = 'page_' . $post_id . '_' . $link;
		if ( isset( self::$object_permalink_memo[ $memo_key ] ) ) {
			return self::$object_permalink_memo[ $memo_key ];
		}

		// Front page translations should return the language root URL,
		// not the slug URL (e.g., /en/ instead of /en/homepage/).
		if ( $this->is_front_page_translation( $post_id ) ) {
			$lang_obj = $this->resolve_object_language( $post_id, 'post' );

			if ( $lang_obj && ! empty( $lang_obj->slug ) ) {
				self::cap_cache( self::$object_permalink_memo );
				self::$object_permalink_memo[ $memo_key ] = $this->convert( home_url( '/' ), $lang_obj->slug );
				return self::$object_permalink_memo[ $memo_key ];
			}
		}

		$link = $this->resolve_query_string_url( $link, $post_id );
		self::cap_cache( self::$object_permalink_memo );
		self::$object_permalink_memo[ $memo_key ] = $this->add_language_prefix_to_url( $link, $post_id, 'post' );
		return self::$object_permalink_memo[ $memo_key ];
	}

	/**
	 * Prefix attachment-page links with the language, mirroring the
	 * untranslated-post fallback (attachments are rarely translated, so
	 * the CURRENT request language wins — a /de/ page links to
	 * /de/<attachment>/, which serves 200 in-language instead of ejecting
	 * the visitor via core's canonical redirect).
	 *
	 * @param mixed $link    Attachment page URL.
	 * @param mixed $post_id Attachment ID.
	 * @return mixed
	 */
	public function filter_attachment_link( $link, $post_id = 0 ) {
		unset( $post_id );

		if ( $this->filtering || ! is_string( $link ) || $link === '' ) {
			return $link;
		}

		if ( $this->should_skip_url_modification() ) {
			return $link;
		}

		return $this->add_language_prefix( $link );
	}

	/**
	 * Filter custom post type links.
	 *
	 * @param string $link The post link.
	 * @param object $post The post object (WP_Post or stdClass from direct DB queries).
	 * @return string Filtered link.
	 */
	public function filter_post_type_link( string $link, object $post ): string {
		if ( $this->filtering || ! isset( $post->ID ) ) {
			return $link;
		}

		if ( $this->should_skip_url_modification() ) {
			return $link;
		}

		$post_id  = (int) $post->ID;
		$memo_key = 'cpt_' . $post_id . '_' . $link;
		if ( isset( self::$object_permalink_memo[ $memo_key ] ) ) {
			return self::$object_permalink_memo[ $memo_key ];
		}

		$link = $this->resolve_query_string_url( $link, $post_id );
		self::cap_cache( self::$object_permalink_memo );
		self::$object_permalink_memo[ $memo_key ] = $this->add_language_prefix_to_url( $link, $post_id, 'post' );
		return self::$object_permalink_memo[ $memo_key ];
	}

	/**
	 * Filter term links.
	 *
	 * `$term` is typed `object`, not `\WP_Term`, for the same reason
	 * filter_post_permalink() is: core does not normalise it. `get_term_link()`
	 * only converts a NON-object argument (`if ( ! is_object( $term ) )`), so
	 * any object a caller hands it — an stdClass row from a direct
	 * `$wpdb->get_results()` on `wp_terms`, or a `(object)` cast — reaches this
	 * filter verbatim and a declared `\WP_Term` threw a TypeError at ARGUMENT
	 * BINDING. Unlike the `page_link` case no installed host was proven to do
	 * it, but core accepts the shape and we would be the only thing that
	 * fatals on it, so the input is handed back UNCHANGED instead.
	 *
	 * @param mixed $link     The term link (any type; `category_link` /
	 *                        `tag_link` fire immediately before this filter).
	 * @param mixed $term     The term object.
	 * @param mixed $taxonomy The taxonomy slug. Unused.
	 * @return mixed Filtered link, or the input unchanged.
	 */
	public function filter_term_link( $link, $term = null, $taxonomy = '' ) {
		unset( $taxonomy );

		if ( $this->filtering ) {
			return $link;
		}

		if ( ! is_string( $link ) || ! is_object( $term ) || ! isset( $term->term_id ) || ! is_numeric( $term->term_id ) ) {
			return $link;
		}

		if ( $this->should_skip_url_modification() ) {
			return $link;
		}

		$term_id  = (int) $term->term_id;
		$memo_key = 'term_' . $term_id . '_' . $link;
		if ( isset( self::$object_permalink_memo[ $memo_key ] ) ) {
			return self::$object_permalink_memo[ $memo_key ];
		}

		self::cap_cache( self::$object_permalink_memo );
		self::$object_permalink_memo[ $memo_key ] = $this->add_language_prefix_to_url( $link, $term_id, 'term' );
		return self::$object_permalink_memo[ $memo_key ];
	}

	/**
	 * Resolve translated taxonomy slugs in incoming request query variables.
	 *
	 * When a URL uses a translated slug (e.g. /product-category/uncategorized/
	 * instead of /product-category/uncategorized-de/), WordPress can't find
	 * the term because the database slug differs. This action replaces
	 * translated slugs with the actual database slugs before WP_Query runs.
	 *
	 * A hierarchical taxonomy's query var carries the FULL nested path
	 * ('parent/child'), not just the term's own slug, because the rewrite rule
	 * captures everything after the taxonomy base. The lookup below therefore
	 * has to read it the same way WP_Query::parse_tax_query() eventually does
	 * — through wp_basename() — or it misses on every nested archive.
	 *
	 * Hooked to parse_request at priority 3 (after LanguageRouter at 1).
	 *
	 * @param \WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function resolve_translated_taxonomy_slugs( \WP $wp ): void {
		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		// Build a map of taxonomy query_var → taxonomy slug (cached per request),
		// alongside the list of query vars whose rewrite is hierarchical — the
		// exact flag WP_Query::parse_tax_query() tests before it basenames the
		// value. Collecting it here keeps get_taxonomy() out of the loop below.
		static $tax_query_vars = null;

		/**
		 * Query vars of the translatable taxonomies whose rewrite is hierarchical.
		 *
		 * @var string[] $hier_query_vars
		 */
		static $hier_query_vars = [];

		if ( $tax_query_vars === null ) {
			$tax_query_vars  = [];
			$hier_query_vars = [];
			$translatable    = $this->settings->get_translatable_taxonomies();

			foreach ( $translatable as $taxonomy ) {
				$tax_obj = get_taxonomy( $taxonomy );

				if ( $tax_obj && $tax_obj->query_var ) {
					$tax_query_vars[ $tax_obj->query_var ] = $taxonomy;

					// `rewrite` is false when a taxonomy opts out of rewrites;
					// reading the offset through empty() is deliberate, and is
					// what core does, so that case evaluates false rather than
					// warning.
					if ( ! empty( $tax_obj->rewrite['hierarchical'] ) ) {
						$hier_query_vars[] = (string) $tax_obj->query_var;
					}
				}
			}
		}

		foreach ( $tax_query_vars as $qv => $taxonomy ) {
			if ( empty( $wp->query_vars[ $qv ] ) || ! is_string( $wp->query_vars[ $qv ] ) ) {
				continue;
			}

			$slug = $wp->query_vars[ $qv ];

			// Reduce a nested path ('parent/child') to the term's own segment.
			// WP_Query::parse_tax_query() applies exactly this reduction — the
			// same wp_basename(), under the same rewrite['hierarchical'] test —
			// but only later, when it builds the tax query, so matching the raw
			// value here misses on every archive at depth >= 1 and the request
			// silently falls through to the source-language term. At depth 0
			// wp_basename() is the identity, so flat archives are unaffected.
			if ( in_array( $qv, $hier_query_vars, true ) ) {
				$slug = wp_basename( $slug );
			}

			// Resolve translated slug → DB slug, filtered by taxonomy.
			// Multiple taxonomies can share the same display slug (e.g. "uncategorized"
			// exists in both 'category' and 'product_cat'). A taxonomy-aware lookup
			// ensures we find the correct term.
			$db_slug = $this->resolve_term_slug_for_taxonomy( $slug, $taxonomy, $language_id );

			if ( $db_slug !== null && $db_slug !== $slug ) {
				// Writing the bare DB slug back — ancestors dropped — is safe:
				// parse_tax_query() discards them with wp_basename() anyway, so
				// the tax query core builds is identical either way.
				$wp->query_vars[ $qv ] = $db_slug;
			}
		}
	}

	/**
	 * Resolve a translated slug to the database slug for a specific taxonomy.
	 *
	 * Filters by object_subtype so terms in different taxonomies can share
	 * a translated slug without conflict (e.g. category/uncategorized and
	 * product_cat/uncategorized both translating to "sin-categoria"). The
	 * slug_lookup UNIQUE index guarantees one matching row per
	 * (language_id, 'term', taxonomy, slug).
	 *
	 * @param string $slug Translated slug from the URL.
	 * @param string $taxonomy Taxonomy to match against.
	 * @param int    $language_id Current language ID.
	 * @return string|null Database slug, or null if not found.
	 */
	private function resolve_term_slug_for_taxonomy( string $slug, string $taxonomy, int $language_id ): ?string {
		global $wpdb;

		$st_table = \PerfLocale\Database\Schema::table( 'slug_translations' );

		// With object_subtype filtering at the slug_translations level, the
		// resolver only needs the wp_terms join to look up the canonical
		// db slug — wp_term_taxonomy is no longer required for namespace
		// scoping because slug_lookup's UNIQUE on
		// (language_id, object_type, object_subtype, slug) already
		// guarantees one row.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table() are safe.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.slug AS db_slug
				FROM %i st
				INNER JOIN {$wpdb->terms} t ON t.term_id = st.object_id
				WHERE st.language_id = %d
				  AND st.object_type = %s
				  AND st.object_subtype = %s
				  AND st.slug = %s
				LIMIT 1",
				$st_table,
				$language_id,
				'term',
				$taxonomy,
				$slug
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result ? $result->db_slug : null;
	}

	/**
	 * Redirect term archive pages from the database slug to the translated slug.
	 *
	 * When a term has a translated slug (e.g. "tutorials" instead of
	 * "tutorials-fr"), visiting the database slug URL should 301-redirect
	 * to the canonical translated-slug URL. Applies to all translatable
	 * taxonomies: categories, tags, product categories, attributes, etc.
	 *
	 * @return void
	 */
	public function redirect_term_to_translated_slug(): void {
		if ( is_admin() || ( ! is_tax() && ! is_category() && ! is_tag() ) ) {
			return;
		}

		// Body-preserving methods only - 301/302 drops POST/PUT/DELETE/PATCH payloads.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		// Only handle translatable taxonomies.
		$translatable = $this->settings->get_translatable_taxonomies();

		if ( ! in_array( $term->taxonomy, $translatable, true ) ) {
			return;
		}

		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		$translated_slug = $this->slug_manager->get_slug( 'term', $term->term_id, $language_id );

		// '' is treated as "no translation", matching the guard in
		// add_language_prefix_to_url() — a stale cache or partial migration can
		// store '' instead of null, and the two consumers must agree or the
		// canonical URL and this redirect diverge.
		if ( $translated_slug === null || $translated_slug === '' || $translated_slug === $term->slug ) {
			return;
		}

		// Build the canonical URL with the translated slug.
		// get_term_link() applies filter_term_link() → replace_slug_in_url(),
		// so $canonical uses the translated slug.
		$canonical = get_term_link( $term );

		if ( is_wp_error( $canonical ) ) {
			return;
		}

		// Compare canonical path with current request path. Use esc_url_raw
		// (NOT sanitize_text_field) on the request URI: sanitize_text_field
		// strips %XX octets, so a non-Latin (percent-encoded) slug path would
		// never equal the canonical path and the 301 below would fire on every
		// request — an infinite redirect loop. Parse the path the same way as
		// the canonical so the comparison is symmetric.
		$canonical_path = untrailingslashit( (string) ( wp_parse_url( $canonical, PHP_URL_PATH ) ?: '' ) );
		$request_uri    = ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' );
		$qpos           = strpos( $request_uri, '?' );
		$request_path   = untrailingslashit( (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '' ) );

		if ( $canonical_path === $request_path ) {
			return; // Already at canonical URL.
		}

		// Preserve the query string so UTM/tracking/preview params survive
		// the slug-translation canonical redirect.
		if ( $qpos !== false ) {
			$canonical .= substr( $request_uri, $qpos );
		}

		wp_safe_redirect( $canonical, 301 );
		exit;
	}

	/**
	 * Filter home_url to add language prefix.
	 *
	 * Only adds prefix for non-admin, non-REST paths, and only for THIS blog.
	 *
	 * Every parameter is deliberately untyped. `get_home_url()` forwards the
	 * caller's `$path`, `$scheme` and `$blog_id` arguments to apply_filters()
	 * verbatim — it never casts them — and the filter fires from
	 * class-wp-hook.php, a file with no `strict_types`, so PHP's coercive rules
	 * apply: bools, ints and floats coerce to a `string $path` harmlessly, but
	 * null and array do NOT. A theme calling `home_url( $x )` with a null `$x`
	 * therefore made a declared `string $path` throw a TypeError at ARGUMENT
	 * BINDING — before any guard in this body could run — fataling the whole
	 * page for as long as the plugin was active. Values are normalized below
	 * instead, and `$url` is handed back UNCHANGED whenever it is not a usable
	 * string.
	 *
	 * @param mixed $url     The complete home URL.
	 * @param mixed $path    Path relative to home (any type; core forwards it raw).
	 * @param mixed $scheme  Scheme. Unused.
	 * @param mixed $blog_id Blog ID the URL was requested for, or null for the current blog.
	 * @return mixed Filtered URL, or the input unchanged.
	 */
	public function filter_home_url( $url, $path = '', $scheme = null, $blog_id = null ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return $url;
		}

		if ( $this->filtering ) {
			return $url;
		}

		// Another blog's home URL is not ours to localize. get_home_url() runs
		// its switch_to_blog() / restore_current_blog() pair BEFORE firing this
		// filter, so by the time we see the URL the current blog — and with it
		// every memo in this class, the language state, and the home host/path
		// we prefix against — belongs to the CALLING blog, not to $blog_id.
		// Unguarded, a visitor on the network main site's German page got
		// `get_home_url( 2 )` back as `example.com/de/sub/` instead of the
		// sibling's real `example.com/sub/`, and in subdomain / domain modes as
		// `de.example.com` — the sibling's hostname discarded outright. The
		// unprefixed URL core just computed is the only correct answer we have
		// for a blog whose languages we have not loaded.
		//
		// is_numeric() rejects both the null core passes for "current blog" and
		// the arrays/objects a sloppy caller could pass, so the (int) cast that
		// follows can never fatal on an object. is_multisite() keeps single-site
		// behaviour byte-identical: there core ignores $blog_id entirely and
		// really did return THIS site's home URL.
		if ( is_numeric( $blog_id ) && (int) $blog_id > 0 && (int) $blog_id !== get_current_blog_id() && is_multisite() ) {
			return $url;
		}

		// Non-string paths become '' — which is exactly how core treated them
		// when it built $url a moment ago (`get_home_url()` appends the path
		// only `if ( $path && is_string( $path ) )`). Normalizing the same way
		// keeps the excluded-path comparison below matching the URL that was
		// actually produced.
		$path = is_string( $path ) ? $path : '';

		// Universal skip on requests that can't produce a rewritten URL.
		// home_url() is hit 200+ times per archive render, so cutting the
		// whole filter chain to a single isset() check is worth a lot.
		if ( $this->should_skip_url_modification() ) {
			return $url;
		}

		// is_admin() is called 200+ times per request through this filter on
		// any archive render. Cache the result per-request — the admin /
		// frontend distinction can't flip mid-request.
		if ( self::$is_admin_cached === null ) {
			self::$is_admin_cached = is_admin();
		}

		if ( self::$is_admin_cached ) {
			return $url;
		}

		// Query mode NEVER rewrites home_url(): it is a BASE builder that WP
		// core concatenates further path segments onto (pagination links, REST
		// discovery, feed links, the search-form action) — appending ?lang=
		// here lands those segments INSIDE the parameter value and corrupts
		// the URL. The language parameter is attached at final-URL filters
		// instead: post/page/term links, author+date archive links, the
		// search-form hidden field, and convert() for switcher/hreflang.
		if ( $this->url_config()['url_mode'] === 'query' ) {
			return $url;
		}

		// Per-request memo: WP fires `home_url` 100+ times per archive render
		// (nav menus, asset URLs, widgets) with a small set of distinct inputs.
		// Caching collapses all repeated work into one compute per unique pair.
		$memo_key = $url . '|' . $path;
		if ( isset( self::$home_url_memo[ $memo_key ] ) ) {
			return self::$home_url_memo[ $memo_key ];
		}

		// Don't prefix wp-admin, wp-json, or other excluded paths. The
		// excluded-paths list is settings-driven but stable for the request
		// lifetime, so cache the already-normalized needles once (rtrim +
		// empty-drop hoisted out of the per-miss loop) instead of asking
		// Settings to rebuild the list on every memo miss.
		if ( self::$excluded_paths_cached === null ) {
			$needles = [];

			foreach ( $this->settings->get_excluded_paths() as $excluded_path ) {
				$needle = rtrim( (string) $excluded_path, '/' );

				if ( $needle !== '' ) {
					$needles[] = $needle;
				}
			}

			self::$excluded_paths_cached = $needles;
		}

		// Normalize before matching: core passes both '/wp-json/' and the
		// slash-less 'wp-json' (get_rest_url), which a raw prefix comparison
		// against the configured '/wp-json/' entries never matches.
		$normalized_path = '/' . ltrim( $path, '/' );

		foreach ( self::$excluded_paths_cached as $needle ) {
			if ( $normalized_path === $needle || str_starts_with( $normalized_path, $needle . '/' ) || str_starts_with( $normalized_path, $needle . '.' ) || str_starts_with( $normalized_path, $needle . '?' ) ) {
				self::cap_cache( self::$home_url_memo );
				self::$home_url_memo[ $memo_key ] = $url;
				return self::$home_url_memo[ $memo_key ];
			}
		}

		self::cap_cache( self::$home_url_memo );
		self::$home_url_memo[ $memo_key ] = $this->add_language_prefix( $url );
		return self::$home_url_memo[ $memo_key ];
	}

	/**
	 * Keep rest_url() output on the language-prefixed REST base the request
	 * actually entered through.
	 *
	 * Applies only during a REST request whose REQUEST_URI starts with an
	 * active language's subdirectory prefix before /wp-json — the shape a
	 * language-scoped API consumer used. Everything else (wp-admin's
	 * unprefixed editor base, frontend rest_url() prints, other URL modes)
	 * passes through untouched.
	 *
	 * @param mixed $url     REST URL being built.
	 * @param mixed $path    REST route being built. Unused.
	 * @param mixed $blog_id Blog the REST URL was requested for, or null for the current blog.
	 * @return mixed
	 */
	public function preserve_rest_language_prefix( $url, $path = '', $blog_id = null ) {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! is_string( $url ) ) {
			return $url;
		}

		// A sibling blog's REST base carries no language of ours. The
		// re-insert pattern below is built from the ENTRY blog's home path, and
		// on a SUBDOMAIN multisite every blog's home path is empty — so the
		// pattern matches a sibling's base too and stamped the entry blog's
		// language onto it: a request to `main.example.com/de/wp-json/…` turned
		// `get_rest_url( 3 )` into `sub2.example.com/de/wp-json/…` for a blog
		// whose only language is English, i.e. a guaranteed 404. (A
		// subdirectory network is accidentally immune, because the differing
		// home paths make the pattern miss.) Same guard, and same reasoning, as
		// filter_home_url(): is_numeric() rejects null and any array/object so
		// the (int) cast cannot fatal, and is_multisite() leaves single-site
		// behaviour untouched.
		if ( is_numeric( $blog_id ) && (int) $blog_id > 0 && (int) $blog_id !== get_current_blog_id() && is_multisite() ) {
			return $url;
		}

		static $entry_prefix     = null;
		static $reinsert_pattern = '';

		if ( $entry_prefix === null ) {
			$entry_prefix = '';
			$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Prefix-matched against the active-language slug set only.

			// Strip a WP-in-subfolder home path ('/blog') so the prefix segment
			// is compared at the site root — rest_url() re-inserts after the
			// same path below. Bypass our own home_url filter via the raw option.
			$home_path = rtrim( (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ?: '' ), '/' );
			$to_match  = $request_uri;

			if ( $home_path !== '' && str_starts_with( $request_uri, $home_path . '/' ) ) {
				$to_match = substr( $request_uri, strlen( $home_path ) );
			}

			if ( preg_match( '#^/([a-z0-9_-]+)/wp-json(?:/|\?|$)#i', $to_match, $m ) ) {
				$segment = strtolower( $m[1] );

				// Match the entry segment against BOTH the slug and the
				// configured prefix (locale form 'de-de' in url_prefix_type=
				// locale, where /de-de/wp-json/ is the canonical REST base and
				// /de/wp-json/ 404s). Store the form that actually appeared.
				foreach ( $this->router->get_active_languages() as $lang ) {
					$prefix = strtolower( $this->settings->get_url_prefix( $lang ) );

					if ( ( isset( $lang->slug ) && strtolower( (string) $lang->slug ) === $segment )
						|| ( $prefix !== '' && $prefix === $segment ) ) {
						$entry_prefix = $segment;
						break;
					}
				}
			}

			if ( $entry_prefix !== '' ) {
				$reinsert_pattern = '#^(https?://[^/]+' . preg_quote( $home_path, '#' ) . ')/wp-json(/|\?|$)#';
			}
		}

		if ( $entry_prefix === '' ) {
			return $url;
		}

		return (string) preg_replace(
			$reinsert_pattern,
			'$1/' . $entry_prefix . '/wp-json$2',
			$url,
			1
		);
	}

	/**
	 * Add the current language prefix to a URL.
	 *
	 * @param string $url URL to prefix.
	 * @return string Prefixed URL.
	 */
	public function add_language_prefix( string $url ): string {
		if ( $this->should_skip_url_modification() ) {
			return $url;
		}

		$current = $this->router->get_current_language();

		if ( $current === null ) {
			return $url;
		}

		$cfg      = $this->url_config();
		$url_mode = $cfg['url_mode'];

		if ( $url_mode === 'subdomain' ) {
			return $this->apply_subdomain( $url, $current );
		}

		if ( $url_mode === 'domain' ) {
			return $this->apply_domain( $url, $current );
		}

		// Query mode: the default language always stays on clean URLs (the
		// ?lang= parameter only marks non-default languages, mirroring the
		// hidden default prefix in subdirectory mode).
		if ( $url_mode === 'query' ) {
			if ( $this->router->is_default_language() ) {
				return $url;
			}

			// get_url_prefix() honours the URL Prefix Format setting, so query
			// mode now emits `?lang=en-us` when the site is set to full locale,
			// matching what path and subdomain modes put in the URL. It is
			// memoised per language id, so this is a hash hit, not a query.
			// Old `?lang=en` URLs keep resolving: the reader accepts the slug
			// and the locale form (see LanguageRouter::prefix_map()).
			return add_query_arg( self::query_var(), $this->settings->get_url_prefix( $current ), remove_query_arg( self::query_var(), $url ) );
		}

		// Subdirectory: skip prefix for default language if configured to hide it.
		if ( $cfg['hide_default'] && $this->router->is_default_language() ) {
			return $url;
		}

		return $this->inject_prefix( $url, $this->settings->get_url_prefix( $current ) );
	}

	/**
	 * Inject a hidden `lang` field into GET search forms (query URL mode).
	 *
	 * A GET form submission replaces the action URL's entire query string
	 * with the form's fields, so a ?lang= carried on the action would be
	 * dropped and every search from a non-default-language page would land
	 * on default-language results. The hidden field makes the browser carry
	 * the language itself. Default language needs no field (clean URLs).
	 *
	 * A non-string input is returned UNCHANGED rather than cast. `get_search_form`
	 * is one of the few core filters with an explicit null contract:
	 *
	 *     $result = apply_filters( 'get_search_form', $form, $args );
	 *     if ( null === $result ) { $result = $form; }
	 *
	 * i.e. NULL means "use the default form" — the guard core added for
	 * callbacks that forget to `return`. Casting here turned that NULL into ''
	 * before core could see it, core's fallback never fired, and
	 * get_search_form() echoed nothing: the search form vanished from the page
	 * on any site running such a callback at a priority below ours. The same
	 * method is also hooked to `render_block_core/search`, where the input is
	 * always a string and this guard never fires.
	 *
	 * @param mixed $form Search form HTML.
	 * @return mixed Form HTML, or the input unchanged.
	 */
	public function inject_search_form_lang_field( $form ) {
		if ( ! is_string( $form ) ) {
			return $form;
		}

		if ( $this->settings->get_url_mode() !== 'query' || $this->router->is_default_language() ) {
			return $form;
		}

		$current = $this->router->get_current_language();

		$query_var = self::query_var();

		if ( $current === null || stripos( $form, 'name="' . $query_var . '"' ) !== false ) {
			return $form;
		}

		$field = '<input type="hidden" name="' . esc_attr( $query_var ) . '" value="' . esc_attr( $this->settings->get_url_prefix( $current ) ) . '" />';
		$pos   = stripos( $form, '</form>' );

		return $pos === false ? $form . $field : substr_replace( $form, $field, $pos, 0 );
	}

	/**
	 * Append the current language to WooCommerce AJAX endpoint URLs (query
	 * URL mode). Plain string append — add_query_arg() would URL-encode the
	 * literal %%endpoint%% placeholder WC substitutes client-side.
	 *
	 * @param string $endpoint WC AJAX endpoint URL.
	 * @return string
	 */
	public function add_lang_to_wc_ajax_endpoint( $endpoint ): string {
		$endpoint = (string) $endpoint;

		if ( $this->settings->get_url_mode() !== 'query' || $this->router->is_default_language() ) {
			return $endpoint;
		}

		$current = $this->router->get_current_language();

		$query_var = self::query_var();

		if ( $current === null || strpos( $endpoint, $query_var . '=' ) !== false ) {
			return $endpoint;
		}

		return $endpoint . ( strpos( $endpoint, '?' ) !== false ? '&' : '?' ) . $query_var . '=' . rawurlencode( $this->settings->get_url_prefix( $current ) );
	}

	/**
	 * Relocate path segments that WP core appended AFTER a ?lang= parameter.
	 *
	 * Core builds derived URLs as trailingslashit( get_permalink() ) . 'seg/'
	 * (per-post comments feed, comment pagination, multipage posts, embeds).
	 * On a query-mode permalink that produces `/post/?lang=de/seg/` — the
	 * segment is swallowed into the parameter value. Rewrite it to the
	 * canonical `/post/seg/?lang=de` form. URLs without the corruption
	 * signature pass through untouched, as does every other URL mode.
	 *
	 * @param string $url Derived URL.
	 * @return string
	 */
	public function repair_query_lang_url( $url ): string {
		$url = (string) $url;

		$query_var = self::query_var();

		if ( $this->settings->get_url_mode() !== 'query' || strpos( $url, '?' . $query_var . '=' ) === false ) {
			return $url;
		}

		$quoted = preg_quote( $query_var, '#' );

		$url = (string) preg_replace(
			'#\?' . $quoted . '=([A-Za-z0-9_-]+)(/[^?\#]*)#',
			'$2?' . $query_var . '=$1',
			$url
		);

		// The relocation can leave a doubled slash at the join point
		// (trailingslashit'd permalink + leading-slash segment). Collapse it
		// everywhere except the scheme separator.
		return (string) preg_replace( '#(?<!:)//+#', '/', $url );
	}

	/**
	 * repair_query_lang_url() applied to href attributes inside link HTML
	 * (wp_link_pages_link passes rendered anchors, not bare URLs).
	 *
	 * @param string $html Link HTML.
	 * @return string
	 */
	public function repair_query_lang_url_html( $html ): string {
		$html = (string) $html;

		if ( $this->settings->get_url_mode() !== 'query' || strpos( $html, self::query_var() . '=' ) === false ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#href="([^"]+)"#',
			fn( $m ) => 'href="' . esc_url( $this->repair_query_lang_url( html_entity_decode( $m[1] ) ) ) . '"',
			$html
		);
	}

	/**
	 * Convert a URL to a specific target language.
	 *
	 * This is the main public API for URL conversion.
	 *
	 * @param string $url URL to convert.
	 * @param string $target_slug Target language slug.
	 * @return string Converted URL, or $url unchanged when $target_slug is not
	 *                an active language on this site. That rejected-slug path
	 *                returns early and does NOT fire the
	 *                `perflocale/url/convert` filter, because no conversion
	 *                took place.
	 */
	public function convert( string $url, string $target_slug ): string {
		// Resolve the target language first and refuse a slug this site does
		// not route. $target_slug is public API surface — perflocale_home_url()
		// and perflocale_get_term_link() pass theme input straight through, and
		// the url-convert ability takes it over REST — and every branch below
		// interpolates the slug verbatim (as the path prefix, or as ?lang=), so
		// a typo like 'fr' on an en/de/pl site produced /fr/, which routes
		// nowhere: WP's canonical guesser prefix-matches that segment against an
		// unrelated post and 301s the visitor onto it. Handing the input back
		// untouched is the safe answer — the caller already holds a URL that
		// resolves, so a typo degrades to "no conversion" rather than to a
		// redirect onto the wrong content. Falling back to the default language
		// was rejected: it would quietly retarget every link built from the typo
		// and hide the mistake from the theme author. The map is class-static —
		// built once per request, cleared by reset_static_caches() on language
		// CRUD and switch_blog — so the guard costs a single array lookup per
		// call and fetches the language list at most once, however many links
		// the page converts.
		if ( self::$lang_map === null ) {
			self::$lang_map = [];

			foreach ( $this->router->get_active_languages() as $tl ) {
				self::$lang_map[ $tl->slug ] = $tl;
			}
		}

		$target_lang_obj = self::$lang_map[ $target_slug ] ?? null;

		if ( $target_lang_obj === null ) {
			// Deliberately returns BEFORE the perflocale/url/convert filter at the
			// end of this method. That hook's contract is "the URL we converted",
			// and nothing was converted here; firing it with the raw input plus a
			// $target_slug this site does not route would ask every listener to
			// post-process a URL for a language that does not exist on this site.
			// The malformed-URL return below is unfiltered for the same reason.
			// Listeners that need to see rejected slugs should filter the
			// caller's input instead.
			return $url;
		}

		$current_slug = $this->router->get_current_slug();
		$default      = $this->router->get_default_language();

		// Parse the URL. This method is hot — once per hreflang link and per
		// language-switcher alternate (N × languages per page) — so wp_parse_url
		// and the home_url() parse are lifted/cached to run once, not per call.
		$parsed = wp_parse_url( $url );

		if ( $parsed === false ) {
			return $url;
		}

		$path = $parsed['path'] ?? '/';

		// Cache the home-URL path for the request (constant across convert()
		// calls). Compute home_url() with $this->filtering=true so
		// filter_home_url() short-circuits to raw WP_HOME — otherwise on a
		// non-default-language page our own filter adds the language prefix,
		// the path strip below fails to match, and home_url() prepends it a
		// second time (the doubled-path bug).
		if ( self::$home_path === null ) {
			$this->filtering = true;
			try {
				self::$home_path = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '/';
			} finally {
				$this->filtering = false;
			}
		}

		$home = self::$home_path;

		// Strip the home path.
		if ( str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
		}

		// Strip whatever active-language prefix the path currently has, not
		// just the current one — a caller may hand us a URL already prefixed
		// for another language (PostQueryFilter rewriting REST link-picker
		// results, or an hreflang generator across all languages). The result
		// is the canonical prefix-free path before we add the target prefix.
		// ONLY in subdirectory mode: it is the sole mode that carries the
		// language in the PATH. In query / subdomain / domain modes the
		// language lives in ?lang= or the host, so a first segment that merely
		// LOOKS like a language slug (a real page slugged "de" or "it") is
		// content and must survive. Resolve the mode once (also reused for the
		// target-prefix decision below).
		$cfg      = $this->url_config();
		$url_mode = $cfg['url_mode'];

		if ( $url_mode === 'subdirectory' ) {
			$path = $this->strip_language_prefix_from_path( $path );
		}

		// Add target language prefix (unless it's the default and prefix is hidden).
		$hide_default = $cfg['hide_default'];

		// Subdomain + domain modes change the host (handled in the URL
		// reconstruction below) and never touch the path; query mode carries
		// the language in ?lang= (appended below) and never touches the path
		// either. Only the subdirectory mode needs a language prefix on it.
		$host_routed  = ( $url_mode === 'subdomain' || $url_mode === 'domain' );
		$query_routed = $url_mode === 'query';

		if ( ! $host_routed && ! $query_routed ) {
			$target_prefix = $this->settings->get_url_prefix( $target_lang_obj );

			if ( ! ( $hide_default && $default !== null && $target_slug === $default->slug ) ) {
				$path = '/' . $target_prefix . $path;
			}
		}

		// Ensure trailing slash when permalinks are enabled.
		if ( self::has_pretty_permalinks() && $path !== '/' && ! str_ends_with( $path, '/' ) && ! pathinfo( $path, PATHINFO_EXTENSION ) ) {
			$path = trailingslashit( $path );
		}

		// Reconstruct the URL.
		$this->filtering = true;
		try {
			$new_url = home_url( $path );
		} finally {
			$this->filtering = false;
		}

		// Apply subdomain or domain hostname for non-subdirectory modes.
		if ( $url_mode === 'subdomain' ) {
			$new_url = $this->apply_subdomain( $new_url, $target_lang_obj );
		} elseif ( $url_mode === 'domain' ) {
			$new_url = $this->apply_domain( $new_url, $target_lang_obj );
		}

		// Re-add query string if present. In query mode, drop any stale lang
		// parameter the input URL carried first — the target language's own
		// parameter is appended below.
		$query_string = (string) ( $parsed['query'] ?? '' );

		if ( $query_routed && $query_string !== '' ) {
			parse_str( $query_string, $query_args );
			unset( $query_args[ self::query_var() ] );
			$query_string = $query_args === [] ? '' : http_build_query( $query_args );
		}

		if ( $query_string !== '' ) {
			$new_url .= '?' . $query_string;
		}

		// Query mode: append the target language parameter (default language
		// stays clean — exactly one canonical variant per language).
		if ( $query_routed && ! ( $default !== null && $target_slug === $default->slug ) ) {
			// Emit the configured prefix form (slug or full locale), not the raw
			// slug — see the note at the is_default branch above.
			$new_url = add_query_arg( self::query_var(), $this->settings->get_url_prefix( $target_lang_obj ), $new_url );
		}

		// Re-add fragment if present.
		if ( ! empty( $parsed['fragment'] ) ) {
			$new_url .= '#' . $parsed['fragment'];
		}

		/**
		 * Filter the converted URL.
		 *
		 * @param string $new_url      Converted URL.
		 * @param string $target_slug  Target language slug.
		 * @param string $current_slug Language slug the URL was converted FROM.
		 */
		return apply_filters( 'perflocale/url/convert', $new_url, $target_slug, $current_slug );
	}

	/**
	 * Get translated URLs for the current page in all active languages.
	 *
	 * Used by the language switcher and hreflang tags.
	 *
	 * @return array<string, string> language_slug => URL map.
	 */
	public function get_translations_for_current_page(): array {
		// Per-request cache - called by LanguageSwitcher, HreflangTags, and
		// LanguageSwitcherBlock independently on the same page. Blog-KEYED so a
		// mid-request switch_to_blog() can't hand blog B the alternate URLs
		// computed for blog A (host / prefix / translated slugs are per-blog); a
		// plain function-local static would leak across the switch because no
		// reset can reach it. Blog-keying self-corrects with no switch_blog hook.
		static $cached_by_blog = [];

		$blog_key = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( isset( $cached_by_blog[ $blog_key ] ) ) {
			return $cached_by_blog[ $blog_key ];
		}

		$languages = $this->router->get_active_languages();
		$urls      = [];

		// For singular posts/pages, only return URLs for languages with actual translations.
		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			if ( $post_id > 0 ) {
				$repo  = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
				$links = $repo->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post );

				// Batch-prime the WP post cache for all translation IDs at once,
				// so the individual get_post() calls below are free (cache hits).
				$link_ids = [];

				foreach ( $links as $link ) {
					if ( ! empty( $link->language_slug ) ) {
						$link_ids[] = (int) $link->object_id;
					}
				}

				if ( ! empty( $link_ids ) ) {
					_prime_post_caches( $link_ids, false, false );
				}

				// Build a slug → post map from the translation group.
				$translation_map = [];

				foreach ( $links as $link ) {
					if ( ! empty( $link->language_slug ) ) {
						$post = get_post( (int) $link->object_id );

						if ( $post && $post->post_status === 'publish' ) {
							$translation_map[ $link->language_slug ] = $post;
						}
					}
				}

				// Build URLs using the home_url + language prefix + post slug.
				$this->filtering = true;
				try {
					$home = rtrim( home_url(), '/' );
				} finally {
					$this->filtering = false;
				}

				$default       = $this->router->get_default_language();
				$hide_default  = $this->settings->hide_default_prefix();
				$front_page_id = (int) get_option( 'page_on_front' );

				// Check if we're viewing the front page or any of its translations.
				$is_front_page = is_front_page();

				if ( ! $is_front_page && $front_page_id > 0 ) {
					// Check if the front page is a sibling in the same translation
					// group - reuse the $links we already fetched instead of
					// querying the front page's group separately.
					foreach ( $links as $fg_link ) {
						if ( (int) $fg_link->object_id === $front_page_id ) {
							$is_front_page = true;
							break;
						}
					}
				}

				foreach ( $languages as $lang ) {
					if ( ! isset( $translation_map[ $lang->slug ] ) ) {
						continue;
					}

					$post = $translation_map[ $lang->slug ];

					// Build the canonical URL for this translation.
					if ( $is_front_page ) {
						// Front page: use the root URL for that language.
						$urls[ $lang->slug ] = $this->convert( trailingslashit( $home ), $lang->slug );
					} else {
						// Use get_permalink() to get the full URL including the
						// post type base (e.g. /product/ for WC products). Building
						// the URL from just the slug drops the CPT base.
						$permalink = get_permalink( $post->ID );

						// get_permalink() returns string|false; skip a false so the
						// map stays a clean slug=>URL(string) set (matches @return
						// and avoids emitting an empty href for that language).
						if ( is_string( $permalink ) && $permalink !== '' ) {
							$urls[ $lang->slug ] = $permalink;
						}
					}
				}

				$cached_by_blog[ $blog_key ] = $urls;
				return $urls;
			}
		}

		// For term archives, resolve the translated TERM in each language and
		// use get_term_link() so the alternate points at the canonical
		// translated slug (e.g. /de/category/uncategorized-de/). The generic
		// prefix-swap below would emit /de/category/uncategorized/ (source slug
		// under a language prefix), which 301-redirects to the canonical — a
		// conflicting hreflang signal vs the sitemap (which uses get_term_link).
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$repo  = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
				$links = $repo->get_translations( (int) $term->term_id, \PerfLocale\Enum\ObjectType::Term );

				// Batch-prime the sibling terms: only the queried term is in
				// the term cache, so each get_term() below would otherwise be
				// a cache-miss single-row SELECT per language on
				// no-object-cache sites (mirrors the singular branch's
				// _prime_post_caches).
				$sibling_ids = [];
				foreach ( $links as $link ) {
					if ( ! empty( $link->language_slug ) ) {
						$sibling_ids[] = (int) $link->object_id;
					}
				}
				if ( $sibling_ids !== [] && function_exists( '_prime_term_caches' ) ) {
					_prime_term_caches( $sibling_ids, false );
				}

				foreach ( $links as $link ) {
					if ( empty( $link->language_slug ) ) {
						continue;
					}

					$sibling = get_term( (int) $link->object_id, $term->taxonomy );

					if ( $sibling instanceof \WP_Term ) {
						$term_link = get_term_link( $sibling );

						if ( is_string( $term_link ) && $term_link !== '' ) {
							$urls[ $link->language_slug ] = $term_link;
						}
					}
				}

				if ( $urls !== [] ) {
					$cached_by_blog[ $blog_key ] = $urls;
					return $urls;
				}
			}
		}

		// For archives, home, search, etc. - generate URLs for all languages.
		$current = $this->get_current_url();

		foreach ( $languages as $lang ) {
			$urls[ $lang->slug ] = $this->convert( $current, $lang->slug );
		}

		$cached_by_blog[ $blog_key ] = $urls;
		return $urls;
	}

	/**
	 * Inject a language prefix into a URL.
	 *
	 * @param string $url URL.
	 * @param string $prefix Language slug to inject.
	 * @return string URL with language prefix.
	 */
	private function inject_prefix( string $url, string $prefix ): string {
		// Cache the un-filtered home URL + trimmed form per-request. Without
		// this, `inject_prefix` calls home_url() every invocation - on archive
		// pages with 30+ permalinks that's 30+ trips through WP's home_url
		// filter chain (our own filter is suppressed via $this->filtering, but
		// every OTHER plugin's home_url hook still runs). One compute per
		// request, reused everywhere.
		if ( self::$home_trimmed === null ) {
			$this->filtering = true;
			try {
				self::$home_trimmed = rtrim( home_url(), '/' );
			} finally {
				$this->filtering = false;
			}
		}
		$home_url_trimmed = self::$home_trimmed;

		// Check if the URL already has a language prefix. Use str_contains +
		// str_starts_with (cheap, no regex) instead of preg_match with pattern
		// compilation on every call.
		$already_prefixed = $home_url_trimmed . '/' . $prefix;
		if ( $url === $already_prefixed
			|| str_starts_with( $url, $already_prefixed . '/' ) ) {
			return $url;
		}

		// Insert the prefix after the home URL.
		if ( str_starts_with( $url, $home_url_trimmed ) ) {
			$remainder = substr( $url, strlen( $home_url_trimmed ) );
			// Strip any existing language prefix that filter_home_url() may have
			// added. Without this, a URL already prefixed with the current
			// language (e.g. /de/) would get the post's language prefix
			// prepended, producing doubled prefixes like /en/de/product/foo/.
			$remainder = $this->strip_language_prefix_from_path( $remainder );

			$url = $home_url_trimmed . '/' . $prefix . $remainder;
		}

		// Ensure trailing slash when permalinks are enabled (WP convention).
		// permalink_structure is in WP's options autoload cache (cheap get),
		// but calling get_option() 30+ times per request is still wasteful.
		if ( self::has_pretty_permalinks() ) {
			// Operate on the PATH portion only — slicing $end as a length here
			// was the original bug: substr($url, $end-20, $end) overshoots into
			// the query/fragment, and trailingslashit() on the whole URL would
			// append a slash AFTER the query (e.g. `?foo=bar/`).
			$qpos = strpos( $url, '?' );
			$frag = strpos( $url, '#' );
			$end  = false !== $qpos ? $qpos : ( false !== $frag ? $frag : strlen( $url ) );

			if ( $end > 0 && '/' !== $url[ $end - 1 ] ) {
				$path       = substr( $url, 0, $end );
				$last_slash = strrpos( $path, '/' );
				$last_seg   = false !== $last_slash ? substr( $path, $last_slash ) : $path;

				// Only add a slash if the last path segment has no file extension.
				if ( false === strpos( $last_seg, '.' ) ) {
					$url = substr( $url, 0, $end ) . '/' . substr( $url, $end );
				}
			}
		}

		return $url;
	}

	/**
	 * Strip an existing language prefix from a URL path.
	 *
	 * Used by inject_prefix() to avoid doubled prefixes when filter_home_url()
	 * already added the current language prefix to the URL.
	 *
	 * @param string $path Path portion after the home URL (e.g. /de/product/foo/).
	 * @return string Path without any language prefix.
	 */
	private function strip_language_prefix_from_path( string $path ): string {
		// Per-request cache of active language prefixes, sorted longest-first so
		// 'en-gb' matches before 'en'. str_starts_with against this short cached
		// list is 5–10× faster than compiling + running a regex per call, which
		// adds up on archive pages with 30+ permalinks.
		if ( self::$sorted_prefixes === null ) {
			self::$sorted_prefixes = [];

			foreach ( $this->router->get_active_languages() as $lang ) {
				$p = $this->settings->get_url_prefix( $lang );

				if ( $p !== '' ) {
					self::$sorted_prefixes[] = $p;
				}
			}

			usort( self::$sorted_prefixes, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
		}

		$sorted_prefixes = self::$sorted_prefixes;

		if ( $sorted_prefixes === [] ) {
			return $path;
		}

		foreach ( $sorted_prefixes as $prefix ) {
			$needle = '/' . $prefix;
			$nlen   = strlen( $needle );

			if ( ! str_starts_with( $path, $needle ) ) {
				continue;
			}

			// Exact match ('/en') or followed by '/' ('/en/foo').
			if ( strlen( $path ) === $nlen ) {
				return '';
			}

			if ( $path[ $nlen ] === '/' ) {
				return substr( $path, $nlen );
			}
		}

		return $path;
	}

	/**
	 * Apply subdomain to a URL (e.g., example.com → en.example.com).
	 *
	 * @param string $url URL to modify.
	 * @param object $language Language object.
	 * @return string URL with subdomain.
	 */
	private function apply_subdomain( string $url, object $language ): string {
		// home_url() is request-constant within a blog, but apply_subdomain()
		// runs once per language per converted URL (N× on a switcher/hreflang
		// page). Memoise the parsed host. Class-static so reset_static_caches()
		// clears it on switch_blog — the home host differs per blog on multisite.
		if ( self::$home_host === null ) {
			$this->filtering = true;
			try {
				self::$home_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? '';
			} finally {
				$this->filtering = false;
			}
		}

		if ( empty( self::$home_host ) ) {
			return $url;
		}

		$home_host  = self::$home_host;
		$default    = $this->router->get_default_language();
		$is_default = $default !== null && $language->slug === $default->slug;

		// Default language uses the base domain (no subdomain).
		$target_host = $is_default ? $home_host : $language->slug . '.' . $home_host;

		// Replace the hostname in the URL.
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';

		if ( $host !== '' && $host !== $target_host ) {
			$url = str_replace( '://' . $host, '://' . $target_host, $url );
		}

		return $url;
	}

	/**
	 * Apply per-language domain to a URL (e.g., example.com → example.fr).
	 *
	 * @param string $url URL to modify.
	 * @param object $language Language object.
	 * @return string URL with language domain.
	 */
	private function apply_domain( string $url, object $language ): string {
		$target_domain = $this->settings->get_language_domain( $language->slug );

		if ( empty( $target_domain ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';

		// Replace host AND port together. wp_parse_url() returns the host
		// without its port, so replacing the bare host would leave the source
		// URL's port dangling after a target that carries its own
		// (host:8875:8875 on nonstandard-port sites — staging/dev). Configured
		// domains are typically portless (production 80/443), where this is
		// byte-identical to the old behaviour.
		$source = $host . ( isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '' );

		if ( $host !== '' && $source !== $target_domain ) {
			$url = str_replace( '://' . $source, '://' . $target_domain, $url );
		}

		return $url;
	}

	/**
	 * Resolve a query-string URL to its pretty permalink equivalent.
	 *
	 * WordPress returns ?page_id=X or ?p=X for draft/pending/future posts.
	 * When pretty permalinks are enabled, this constructs the proper URL
	 * from the post slug so language prefixes are applied correctly.
	 *
	 * @param string $url URL that may contain query-string format.
	 * @param int    $post_id Post ID.
	 * @return string Resolved pretty URL or original URL.
	 */
	private function resolve_query_string_url( string $url, int $post_id ): string {
		// Fast path: pretty permalinks (the default) produce URLs without '?'.
		// Bail before the get_option() + regex on every permalink filter call.
		if ( strpos( $url, '?' ) === false ) {
			return $url;
		}

		if ( ! self::has_pretty_permalinks() ) {
			return $url;
		}

		// Only act on query-string URLs (?page_id=X or ?p=X).
		if ( strpos( $url, 'page_id=' ) === false && strpos( $url, 'p=' ) === false ) {
			return $url;
		}

		$post = get_post( $post_id );

		if ( ! $post || empty( $post->post_name ) ) {
			return $url;
		}

		$this->filtering = true;
		try {
			$home = rtrim( home_url(), '/' );
		} finally {
			$this->filtering = false;
		}

		if ( $post->post_type === 'page' ) {
			// Pages: build hierarchical path from ancestors.
			$page_path = get_page_uri( $post ) ?: $post->post_name;
			return trailingslashit( $home . '/' . $page_path );
		}

		// Posts / CPTs: use the permalink structure to build the URL.
		$struct = $post->post_type === 'post'
			? get_option( 'permalink_structure' )
			: get_option( $post->post_type . '_permalink_structure', '/' . $post->post_type . '/%postname%/' );

		if ( ! str_contains( $struct, '%postname%' ) ) {
			return $url;
		}

		$date = strtotime( $post->post_date ?: 'now' );
		$path = str_replace(
			[ '%year%', '%monthnum%', '%day%', '%postname%', '%post_id%' ],
			[
				gmdate( 'Y', $date ),
				gmdate( 'm', $date ),
				gmdate( 'd', $date ),
				$post->post_name,
				(string) $post->ID,
			],
			$struct
		);

		// Strip any remaining unreplaced tags (e.g., %category%, %author%).
		$path = preg_replace( '/%[a-z_]+%/', '', $path ) ?? $path;

		// Clean up double slashes from stripped tags.
		$path = preg_replace( '#/+#', '/', $path ) ?? $path;

		return trailingslashit( $home . $path );
	}

	/**
	 * Add language prefix to a URL with optional slug translation.
	 *
	 * @param string $url URL.
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type (post, term).
	 * @return string URL with language prefix and translated slug.
	 */
	private function add_language_prefix_to_url( string $url, int $object_id, string $object_type ): string {
		// Use the object's own language, not the visitor's current language.
		$language = $this->resolve_object_language( $object_id, $object_type );

		if ( $language === null ) {
			return $url;
		}

		$cfg      = $this->url_config();
		$url_mode = $cfg['url_mode'];
		$default  = $cfg['default'];

		if ( $url_mode === 'subdomain' ) {
			$url = $this->apply_subdomain( $url, $language );
		} elseif ( $url_mode === 'domain' ) {
			$url = $this->apply_domain( $url, $language );
		} elseif ( $url_mode === 'query' ) {
			// Query mode: the object's language rides in ?lang=. The default
			// language always stays clean (no parameter) so the canonical URL
			// set has exactly one variant per language.
			$url = remove_query_arg( self::query_var(), $url );

			if ( ! ( $default !== null && $language->slug === $default->slug ) ) {
				$url = add_query_arg( self::query_var(), $this->settings->get_url_prefix( $language ), $url );
			}
		} else {
			// Subdirectory mode - skip prefix for hidden default.
			$is_default = $cfg['hide_default']
				&& $default !== null
				&& $language->slug === $default->slug;

			if ( ! $is_default ) {
				$url = $this->inject_prefix( $url, $this->settings->get_url_prefix( $language ) );
			} else {
				// Default language with hidden prefix: strip any prefix that
				// filter_home_url() added for the visitor's current language.
				$this->filtering = true;
				try {
					$home = rtrim( home_url(), '/' );
				} finally {
					$this->filtering = false;
				}

				if ( str_starts_with( $url, $home ) ) {
					$remainder = substr( $url, strlen( $home ) );
					$stripped  = $this->strip_language_prefix_from_path( $remainder );

					if ( $stripped !== $remainder ) {
						$url = $home . $stripped;
					}
				}
			}
		}

		// Apply translated slug if available and slug translation is enabled.
		if ( $cfg['translate_slugs'] ) {
			$translated_slug = $this->slug_manager->get_slug( $object_type, $object_id, (int) $language->id );

			// Treat empty string the same as null ("no translation"). A stale
			// cache or partial migration may have stored '' instead of null;
			// without this guard `replace_slug_in_url` would strip the
			// original slug from the URL (e.g. /de/shop/ → /de//).
			if ( $translated_slug !== null && $translated_slug !== '' ) {
				$url = $this->replace_slug_in_url( $url, $object_id, $object_type, $translated_slug );
			}
		}

		return $url;
	}

	/**
	 * Pre-fetch menu items and prime caches BEFORE wp_setup_nav_menu_item
	 * runs its array_map and triggers a cascade of get_permalink()
	 * (filter_page_link) calls - each of which would otherwise fire a
	 * separate transient read for each linked post.
	 *
	 * Hooked to `pre_wp_nav_menu`, which fires at the top of `wp_nav_menu()`
	 * before the menu is located or its items are fetched. Returning null
	 * leaves the short-circuit behaviour of the filter unchanged (WP will
	 * continue to call wp_get_nav_menu_items as normal), but by then we've
	 * already populated the per-post caches so every downstream permalink
	 * filter lands on an L1 hit.
	 *
	 * @param \stdClass|string|null $output Existing short-circuit value (null = let WP continue).
	 * @param object|array|string   $args wp_nav_menu arguments.
	 * @return \stdClass|string|null Unmodified - we only prime, never short-circuit.
	 */
	public function prime_nav_menu_items_early( mixed $output, mixed $args ): mixed {
		if ( $output !== null || is_admin() ) {
			return $output;
		}

		// Static memo - multiple widgets on a page can fire wp_nav_menu
		// with different menus, but priming twice for the same menu ID is
		// wasted work. BLOG-KEYED so a mid-request switch_to_blog() can't make
		// blog B skip a prime because blog A already primed a menu whose
		// (per-blog, low-numbered) term_id collides — self-correcting, no
		// reset hook needed (same pattern as HreflangTags/OgLocale).
		static $primed_menus = [];
		$primed_blog         = is_multisite() ? get_current_blog_id() : 0;
		if ( ! isset( $primed_menus[ $primed_blog ] ) ) {
			$primed_menus[ $primed_blog ] = [];
		}

		$menu = null;

		if ( is_object( $args ) && ! empty( $args->menu ) ) {
			$menu = wp_get_nav_menu_object( $args->menu );
		} elseif ( is_object( $args ) && ! empty( $args->theme_location ) ) {
			$locations = get_nav_menu_locations();

			if ( isset( $locations[ $args->theme_location ] ) ) {
				$menu = wp_get_nav_menu_object( $locations[ $args->theme_location ] );
			}
		}

		if ( ! $menu || isset( $primed_menus[ $primed_blog ][ (int) $menu->term_id ] ) ) {
			return $output;
		}

		$primed_menus[ $primed_blog ][ (int) $menu->term_id ] = true;

		// Fetch just the post IDs the menu references - a lean query keyed on
		// the menu term relationship. We don't need the full wp_setup-
		// decorated items, just object_ids for the prime.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$post_ids_raw = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id
			 WHERE tr.term_taxonomy_id = %d
			 AND pm.meta_key = %s",
				(int) $menu->term_taxonomy_id,
				'_menu_item_object_id'
			)
		);

		// SQL above is `SELECT DISTINCT pm.meta_value` — values are already
		// deduped at the database layer. intval() can only normalize
		// strings to ints, never multiply them, and the downstream
		// prime_translations + IN-clause SQL handle duplicate ids
		// idempotently, so a PHP-side array_unique() here would just be
		// dead work on every menu prime. Filter > 0 still applies to drop
		// the impossible-but-harmless 0-rows.
		$post_ids = array_values( array_filter( array_map( 'intval', (array) $post_ids_raw ), static fn( int $id ): bool => $id > 0 ) );

		if ( $post_ids === [] ) {
			return $output;
		}

		$this->prime_menu_post_ids( $post_ids );

		return $output;
	}

	/**
	 * Batch-prime the post IDs a Navigation block references, BEFORE the
	 * block renders its navigation-link/submenu children (each of which
	 * calls get_permalink → our permalink filters → get_translations).
	 *
	 * Block themes never call wp_nav_menu(), so prime_nav_menu_items_early
	 * can't cover them. The Navigation block's items either sit in its
	 * innerBlocks directly or in a `wp_navigation` post referenced by the
	 * `ref` attribute; both shapes are walked here. Returning null leaves
	 * rendering untouched — this only warms caches.
	 *
	 * @param string|null $pre_render   Pre-render short-circuit value (null = render normally).
	 * @param mixed       $parsed_block The block being rendered.
	 * @return string|null Unmodified $pre_render.
	 */
	public function prime_navigation_block_items( $pre_render, $parsed_block ) {
		if ( $pre_render !== null || ! is_array( $parsed_block ) ) {
			return $pre_render;
		}

		if ( ( $parsed_block['blockName'] ?? '' ) !== 'core/navigation' ) {
			return $pre_render;
		}

		$attrs  = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : [];
		$ref    = isset( $attrs['ref'] ) && is_numeric( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
		$blocks = isset( $parsed_block['innerBlocks'] ) && is_array( $parsed_block['innerBlocks'] ) ? $parsed_block['innerBlocks'] : [];

		if ( $ref > 0 ) {
			if ( isset( self::$primed_nav_refs[ $ref ] ) ) {
				return $pre_render;
			}
			self::$primed_nav_refs[ $ref ] = true;

			$nav_post = get_post( $ref );

			if ( $nav_post instanceof \WP_Post && $nav_post->post_content !== '' ) {
				$blocks = parse_blocks( $nav_post->post_content );
			}
		}

		if ( $blocks === [] ) {
			return $pre_render;
		}

		$post_ids = [];
		$this->collect_navigation_post_ids( $blocks, $post_ids );

		if ( $post_ids !== [] ) {
			$this->prime_menu_post_ids( array_values( array_unique( $post_ids ) ) );
		}

		return $pre_render;
	}

	/**
	 * Recursively collect post-type object IDs from navigation-link /
	 * navigation-submenu blocks (taxonomy links resolve through a different
	 * cache path and are left to their own filters).
	 *
	 * @param array<mixed>   $blocks   Parsed block list.
	 * @param array<int,int> $post_ids Collector (by reference).
	 * @return void
	 */
	private function collect_navigation_post_ids( array $blocks, array &$post_ids ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';

			if ( $name === 'core/navigation-link' || $name === 'core/navigation-submenu' ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
				$id    = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;

				if ( $id > 0 && ( $attrs['kind'] ?? 'post-type' ) !== 'taxonomy' ) {
					$post_ids[] = $id;
				}
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && $block['innerBlocks'] !== [] ) {
				$this->collect_navigation_post_ids( $block['innerBlocks'], $post_ids );
			}
		}
	}

	/**
	 * Prime translation-link + slug caches for a set of post IDs.
	 *
	 * Extracted out of prime_nav_menu_items_early so we can call the same
	 * batched priming code path from multiple hooks if needed.
	 *
	 * @param array<int, int> $post_ids Post IDs to prime.
	 * @return void
	 */
	private function prime_menu_post_ids( array $post_ids ): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'cache' ) ) {
			$repo = $plugin->get( 'group_repo' );
			$repo->prime_translations( \PerfLocale\Enum\ObjectType::Post, $post_ids );
		}

		$this->prime_slug_translations_multilang( $post_ids );
	}

	/**
	 * Point a rendered navigation-link / navigation-submenu anchor at the
	 * current language's sibling of the linked object.
	 *
	 * Core renders these blocks from the SAVED `url` attribute (the object's
	 * permalink at save time, in the language it was authored in) and never
	 * calls get_permalink() — so on a translated page every explicit menu
	 * item pointed back to the default language. When the linked post/term
	 * has a published sibling in the current language, rewrite the anchor's
	 * href to that sibling's permalink (which routes through the plugin's
	 * mode-aware URL filters). No sibling → the saved URL stands and the
	 * missing-translation handling applies as usual.
	 *
	 * @param mixed $block_content Rendered block HTML.
	 * @param mixed $block         Parsed block (attrs carry id/kind).
	 * @return mixed
	 */
	public function localize_navigation_link_block( $block_content, $block ) {
		if ( ! is_string( $block_content ) || $block_content === '' || is_admin() ) {
			return $block_content;
		}

		$attrs     = is_array( $block ) && isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$object_id = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;

		if ( $object_id <= 0 ) {
			return $block_content; // Custom/external URL item — nothing to localize.
		}

		$current_slug = $this->router->get_current_slug();

		if ( $current_slug === '' ) {
			return $block_content;
		}

		$is_taxonomy = ( $attrs['kind'] ?? 'post-type' ) === 'taxonomy';
		$repo        = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$links       = $repo->get_translations(
			$object_id,
			$is_taxonomy ? \PerfLocale\Enum\ObjectType::Term : \PerfLocale\Enum\ObjectType::Post
		);

		$sibling_id = 0;

		foreach ( (array) $links as $link ) {
			if ( isset( $link->language_slug ) && $link->language_slug === $current_slug ) {
				$sibling_id = (int) $link->object_id;
				break;
			}
		}

		if ( $sibling_id <= 0 || $sibling_id === $object_id ) {
			return $block_content; // No sibling, or item already in this language.
		}

		if ( $is_taxonomy ) {
			$term = get_term( $sibling_id );
			$url  = $term instanceof \WP_Term ? get_term_link( $term ) : '';
		} else {
			if ( get_post_status( $sibling_id ) !== 'publish' ) {
				return $block_content; // Draft sibling — keep the working saved URL.
			}
			$url = get_permalink( $sibling_id );
		}

		if ( ! is_string( $url ) || $url === '' ) {
			return $block_content;
		}

		// Rewrite only the FIRST anchor: for navigation-link that is the
		// item itself; for navigation-submenu it is the parent link (child
		// items are separate navigation-link blocks, each filtered on their
		// own render pass before the submenu wraps them).
		$processor = new \WP_HTML_Tag_Processor( $block_content );

		if ( $processor->next_tag( 'a' ) ) {
			$processor->set_attribute( 'href', esc_url( $url ) );
			return $processor->get_updated_html();
		}

		return $block_content;
	}

	/**
	 * Resolve a translated-post URL to its post ID for the oEmbed endpoint.
	 *
	 * WP's oEmbed controller resolves ?url= via url_to_postid(), whose internal
	 * WP_Query is language-scoped by PostQueryFilter to the current request's
	 * language — so a URL in a NON-request language (e.g. /de/ while the REST
	 * request resolved to the default language) returns 0 and the endpoint
	 * 404s. Re-resolve with language scoping suspended so the URL maps to its
	 * own post regardless of the request language.
	 *
	 * @param int    $post_id Post ID WP resolved (0 when it could not).
	 * @param string $url     The requested URL.
	 * @return int
	 */
	public function resolve_oembed_post_id( $post_id, string $url ): int {
		if ( (int) $post_id > 0 ) {
			return (int) $post_id;
		}

		$suspend  = static fn(): bool => true;
		add_filter( 'perflocale/query/include_all_languages', $suspend, 99 );
		$resolved = url_to_postid( $url );
		remove_filter( 'perflocale/query/include_all_languages', $suspend, 99 );

		return $resolved > 0 ? (int) $resolved : (int) $post_id;
	}

	/**
	 * Prime slug translations for a batch of object IDs across every active
	 * language with null-sentinels for untranslated pairs. Extracted from
	 * prime_menu_post_ids so it can be reused independently of the
	 * translation-link prime; the term leg is fed by preload_term_languages.
	 *
	 * @param array<int, int> $object_ids Object IDs to prime.
	 * @param string          $object_type Object type ('post' or 'term').
	 * @return void
	 */
	private function prime_slug_translations_multilang( array $object_ids, string $object_type = 'post' ): void {
		if ( $object_ids === [] ) {
			return;
		}

		$active_langs = $this->router->get_active_languages();

		if ( $active_langs === [] ) {
			return;
		}

		$lang_ids = [];

		foreach ( $active_langs as $lang ) {
			$lid = (int) ( $lang->id ?? 0 );

			if ( $lid > 0 ) {
				$lang_ids[] = $lid;
			}
		}

		if ( $lang_ids === [] ) {
			return;
		}

		global $wpdb;

		$slug_table = \PerfLocale\Database\Schema::table( 'slug_translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pid_ph  = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$lang_ph = implode( ',', array_fill( 0, count( $lang_ids ), '%d' ) );
		// $slug_table leads: it binds the %i, which precedes the %s object_type
		// and the two %d id lists. prepare() consumes $args strictly in order.
		$args = array_merge( [ $slug_table, $object_type ], $object_ids, $lang_ids );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, language_id, slug FROM %i
				 WHERE object_type = %s
				 AND object_id IN ({$pid_ph})
				 AND language_id IN ({$lang_ph})",
				...$args
			)
		);
		// phpcs:enable

		$found = [];

		foreach ( (array) $rows as $row ) {
			$found[ (int) $row->object_id . ':' . (int) $row->language_id ] = (string) $row->slug;
		}

		foreach ( $object_ids as $pid ) {
			foreach ( $lang_ids as $lid ) {
				// Key format must match SlugTranslationRepository::get_slug's
				// read key exactly ("slug_{type}_{id}_{lang}").
				$cache_key = "slug_{$object_type}_{$pid}_{$lid}";
				$value     = $found[ $pid . ':' . $lid ] ?? null;
				$this->cache->set_static( $cache_key, $value, 'perflocale_slugs' );
			}
		}
	}

	/**
	 * Batch preload language slugs for all posts in a WP_Query result.
	 *
	 * This eliminates N+1 queries when rendering permalink lists (archives,
	 * search results, etc.). The preloaded data is consumed by
	 * resolve_object_language().
	 *
	 * @param array<int, \WP_Post> $posts Posts from WP_Query.
	 * @param \WP_Query            $query The query.
	 * @return array<int, \WP_Post> Unmodified posts.
	 */
	public function preload_object_languages( array $posts, \WP_Query $query ): array {
		if ( $posts === [] ) {
			return $posts;
		}

		// Fast-path detector: walk $posts once, collect only the IDs whose
		// language we haven't already cached for this request. On sub-queries
		// (sidebars / related / widgets) every ID is already primed by the
		// main query, so $missing_ids ends up empty and we return without
		// touching repos or the DB.
		$missing_ids = [];

		foreach ( $posts as $p ) {
			$pid = isset( $p->ID ) ? (int) $p->ID : 0;

			if ( $pid > 0 && ! isset( self::$object_language_cache[ 'post_' . $pid ] ) ) {
				$missing_ids[] = $pid;
			}
		}

		if ( $missing_ids === [] ) {
			return $posts;
		}

		// Repositories are stateless wrappers over the shared cache manager;
		// cache the instances on the UrlConverter so sub-queries don't pay
		// the object-construction cost 4-6× per request.
		if ( self::$lazy_group_repo === null ) {
			self::$lazy_group_repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		}

		$repo = self::$lazy_group_repo;

		// Build a lang_id → language object map from the router's already-
		// loaded active-languages list. Avoids a LanguageRepository::find_many()
		// roundtrip on every cold-path invocation. Active language count is
		// tiny (typically 2-5) so this is pure in-memory work.
		if ( self::$lang_id_map === null ) {
			$map = [];

			foreach ( $this->router->get_active_languages() as $lang ) {
				$lid = (int) ( $lang->id ?? 0 );

				if ( $lid > 0 ) {
					$map[ $lid ] = $lang;
				}
			}

			self::$lang_id_map = $map;
		}

		$lang_map = self::$lang_id_map;

		// Prime only the missing IDs - no need to re-prime siblings we've
		// already resolved in this request.
		$repo->prime_translations( ObjectType::Post, $missing_ids );

		$current = $this->router->get_current_language();

		foreach ( $missing_ids as $pid ) {
			self::cap_cache( self::$object_language_cache );

			$lang  = null;
			$links = $repo->get_translations( $pid, ObjectType::Post );

			foreach ( $links as $link ) {
				if ( (int) ( $link->object_id ?? 0 ) === $pid ) {
					$lid  = (int) $link->language_id;
					$lang = $lang_map[ $lid ] ?? null;
					break;
				}
			}

			// Unmanaged posts (no translation link) get the visitor's
			// current language so resolve_object_language can short-
			// circuit without a second repo pass.
			self::$object_language_cache[ 'post_' . $pid ] = $lang ?? $current;
		}

		return $posts;
	}

	/**
	 * Batch-prime TERM translations + languages — the term-side analog of
	 * preload_object_languages(). Without it every distinct term rendered
	 * on a page (per-post category links, category/tag widgets and blocks,
	 * Woo product-category lists) pays its own get_translations() lookup
	 * inside filter_term_link — a JOIN each on no-object-cache sites.
	 *
	 * Hooked to `get_terms` and `get_the_terms`; tolerant of both signatures
	 * (terms may be WP_Term objects, ids, or other field shapes — anything
	 * non-batchable passes through untouched).
	 *
	 * @param array<int, mixed>|mixed $terms Terms from the query (shape varies by 'fields').
	 * @return array<int, mixed>|mixed Unmodified $terms.
	 */
	public function preload_term_languages( $terms ) {
		if ( ! is_array( $terms ) || $terms === [] ) {
			return $terms;
		}

		$missing = [];

		foreach ( $terms as $t ) {
			$tid = 0;

			if ( $t instanceof \WP_Term ) {
				$tid = (int) $t->term_id;
			} elseif ( is_int( $t ) || ( is_string( $t ) && ctype_digit( $t ) ) ) {
				$tid = (int) $t;
			} else {
				return $terms; // slugs/names/count shapes — nothing to prime.
			}

			if ( $tid > 0 && ! isset( self::$object_language_cache[ 'term_' . $tid ] ) ) {
				$missing[] = $tid;
			}
		}

		// Pathological unbounded term queries (thousands of ids) would bloat
		// the L1 maps for little rendering benefit — let those fall back to
		// the per-term path, which negative-caches as it goes.
		if ( $missing === [] || count( $missing ) > 500 ) {
			return $terms;
		}

		if ( self::$lazy_group_repo === null ) {
			self::$lazy_group_repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		}

		$repo = self::$lazy_group_repo;

		if ( self::$lang_id_map === null ) {
			$map = [];

			foreach ( $this->router->get_active_languages() as $lang ) {
				$lid = (int) ( $lang->id ?? 0 );

				if ( $lid > 0 ) {
					$map[ $lid ] = $lang;
				}
			}

			self::$lang_id_map = $map;
		}

		$lang_map = self::$lang_id_map;

		$repo->prime_translations( ObjectType::Term, $missing );

		// Terms get the same multi-language slug prime posts have: without
		// it, every unique rendered term link on a translate-slugs site pays
		// its own per-(term,language) L3 transient read inside
		// SlugManager::get_slug. Gated like SlugManager::preload_slugs, which
		// also bails on is_admin(): is_admin() requests still run
		// filter_term_link, so their term links resolve one (term, language)
		// at a time through SlugManager::get_slug (in-memory memo, then the
		// repository's cached read). Both gate reads are memoized (url_config
		// + the repo's has_any_slugs verdict).
		if ( ! is_admin()
			&& $this->url_config()['translate_slugs']
			&& $this->slug_manager->has_any_slugs() ) {
			$this->prime_slug_translations_multilang( $missing, 'term' );
		}

		$current = $this->router->get_current_language();

		foreach ( $missing as $tid ) {
			self::cap_cache( self::$object_language_cache );

			$lang  = null;
			$links = $repo->get_translations( $tid, ObjectType::Term );

			foreach ( $links as $link ) {
				if ( (int) ( $link->object_id ?? 0 ) === $tid ) {
					$lid  = (int) $link->language_id;
					$lang = $lang_map[ $lid ] ?? null;
					break;
				}
			}

			self::$object_language_cache[ 'term_' . $tid ] = $lang ?? $current;
		}

		return $terms;
	}

	/**
	 * Check if a page is the static front page or a translation of it.
	 *
	 * Checks both translation group membership and slug match as a
	 * fallback for pages not linked in the same translation group.
	 *
	 * @param int $post_id Page ID.
	 * @return bool
	 */
	private function is_front_page_translation( int $post_id ): bool {
		// Class-static memo so reset_static_caches() (hooked to switch_blog +
		// perflocale/language/{added,updated,slug_renamed,deleted}) wipes it
		// when the blog or language set changes mid-request — page_on_front +
		// translation group membership are both per-blog and CRUD-sensitive.
		if ( isset( self::$front_page_translation_memo[ $post_id ] ) ) {
			return self::$front_page_translation_memo[ $post_id ];
		}

		$front_page_id = (int) get_option( 'page_on_front' );

		if ( $front_page_id === 0 || get_option( 'show_on_front' ) !== 'page' ) {
			self::$front_page_translation_memo[ $post_id ] = false;
			return false;
		}

		// Direct match - the page IS the front page.
		if ( $post_id === $front_page_id ) {
			self::$front_page_translation_memo[ $post_id ] = true;
			return true;
		}

		// Lazy prime of the front-page translation group. Runs during `init`
		// when WooCommerce / BlockPatterns build shop/cart/checkout permalinks
		// — before the_posts and wp_get_nav_menu_items fire — so without it
		// each init hits its own transient read. One prime covers the rest of
		// the request via the sibling-cascade in prime_translations().
		if ( ! self::$front_page_translation_primed ) {
			self::$front_page_translation_primed = true;

			$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$repo->prime_translations(
				\PerfLocale\Enum\ObjectType::Post,
				[ $front_page_id ]
			);
		}

		// Check translation group membership.
		$repo  = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$links = $repo->get_translations( $front_page_id, \PerfLocale\Enum\ObjectType::Post );

		foreach ( $links as $link ) {
			if ( (int) $link->object_id === $post_id ) {
				self::$front_page_translation_memo[ $post_id ] = true;
				return true;
			}
		}

		// Fallback: check if the page shares the front page's slug.
		$front_page = get_post( $front_page_id );
		$page       = get_post( $post_id );

		if ( $front_page && $page && $page->post_name === $front_page->post_name ) {
			self::$front_page_translation_memo[ $post_id ] = true;
			return true;
		}

		self::$front_page_translation_memo[ $post_id ] = false;
		return false;
	}

	/**
	 * Resolve the language of an object from its translation links.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type (post, term).
	 * @return object|null Language object.
	 */
	private function resolve_object_language( int $object_id, string $object_type ): ?object {
		$cache_key = $object_type . '_' . $object_id;

		// Check batch-preloaded cache first.
		if ( isset( self::$object_language_cache[ $cache_key ] ) ) {
			return self::$object_language_cache[ $cache_key ];
		}

		// Fallback: single lookup for objects not in the preloaded batch
		// (e.g., individual get_permalink() calls outside a loop).
		//
		// These two function-local statics are NOT a blog-affinity hazard even
		// though nothing resets them: Plugin::get() memoises its services and
		// the container is never cleared on `switch_blog`, so re-resolving
		// would hand back these exact instances anyway (verified: identical
		// spl_object_id across all four blogs of a subdomain network). The
		// blog-affine state is self::$object_language_cache above — keyed
		// "{type}_{object_id}", which collides across blogs — and THAT is
		// cleared by reset_static_caches() on switch_blog.
		static $repo      = null;
		static $lang_repo = null;

		if ( $repo === null ) {
			$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		}

		$type  = $object_type === 'post' ? ObjectType::Post : ObjectType::Term;
		$links = $repo->get_translations( $object_id, $type );

		foreach ( $links as $link ) {
			if ( (int) $link->object_id === $object_id && ! empty( $link->language_slug ) ) {
				if ( $lang_repo === null ) {
					$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
				}

				$result = $lang_repo->find_by_slug( $link->language_slug );
				self::cap_cache( self::$object_language_cache );
				self::$object_language_cache[ $cache_key ] = $result;
				return $result;
			}
		}

		$result = $this->router->get_current_language();
		self::cap_cache( self::$object_language_cache );
		self::$object_language_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Replace the slug segment in a URL with a translated slug.
	 *
	 * @param string $url The URL.
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type.
	 * @param string $translated_slug Translated slug.
	 * @return string URL with translated slug.
	 */
	private function replace_slug_in_url( string $url, int $object_id, string $object_type, string $translated_slug ): string {
		// Belt-and-braces: never replace a real slug with an empty one. Empty
		// `$translated_slug` indicates "no translation available" (mirrors the
		// caller-side guard in `add_language_prefix_to_url`). Without this
		// check, a stale cache row could turn /de/shop/ into /de//.
		if ( $translated_slug === '' ) {
			return $url;
		}

		$original_slug = null;

		if ( $object_type === 'post' ) {
			$post = get_post( $object_id );

			if ( $post && $post->post_name !== $translated_slug ) {
				$original_slug = $post->post_name;
			}
		} elseif ( $object_type === 'term' ) {
			$term = get_term( $object_id );

			if ( $term instanceof \WP_Term && $term->slug !== $translated_slug ) {
				$original_slug = $term->slug;
			}
		}

		if ( $original_slug === null ) {
			return $url;
		}

		// Replace by path segments to avoid substring collisions.
		// E.g., slug 'foo' in URL '/foobar/foo/' must only replace the exact segment.
		$parsed = wp_parse_url( $url );
		$path   = $parsed['path'] ?? '/';

		$segments = explode( '/', $path );
		$replaced = false;

		// Replace the LAST matching segment (the object's own slug, not ancestors).
		for ( $i = count( $segments ) - 1; $i >= 0; $i-- ) {
			if ( $segments[ $i ] === $original_slug ) {
				$segments[ $i ] = $translated_slug;
				$replaced       = true;
				break;
			}
		}

		if ( ! $replaced ) {
			return $url;
		}

		$new_path = implode( '/', $segments );

		// Reconstruct URL with replaced path.
		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
		$host   = $parsed['host'] ?? '';
		$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$frag   = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

		return $scheme . $host . $port . $new_path . $query . $frag;
	}

	/**
	 * Get the current request URL.
	 *
	 * @return string
	 */
	private function get_current_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		// esc_url_raw, NOT sanitize_text_field: the latter strips %XX octets,
		// corrupting percent-encoded (non-Latin) request paths used to build
		// hreflang / switcher / og:url alternates.
		$uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );

		// Drop the query string + fragment. This URL seeds the per-language
		// alternates for home / date / author / post-type archives consumed by
		// hreflang, the switcher, og:url, schema, and the speculation-rules
		// prerender list — all of which need the CANONICAL page URL. The
		// singular and taxonomy branches already produce query-less permalinks;
		// without this strip, the first visitor's ?utm_… would be baked into
		// HreflangTags' 12h-cached alternate set (its cache key deliberately
		// excludes the query string) and served to every subsequent visitor.
		$qpos = strpos( $uri, '?' );
		if ( $qpos !== false ) {
			$uri = substr( $uri, 0, $qpos );
		}
		$fpos = strpos( $uri, '#' );
		if ( $fpos !== false ) {
			$uri = substr( $uri, 0, $fpos );
		}

		// Search results are the one archive whose identity LIVES in the query
		// string: re-append `s` alone so the language switcher targets the same
		// search in the other language instead of its bare home page. Safe for
		// the cache-poisoning fix above — HreflangTags skips is_search()
		// entirely (never cached/emitted), and og:url/schema on a search page
		// are computed per-request.
		if ( is_search() ) {
			$term = get_search_query( false );

			if ( $term !== '' ) {
				$uri .= '?s=' . rawurlencode( $term );

				// A scoped search (WooCommerce product search: ?s=x&post_type=product)
				// is a different result set than the blog search — without the
				// scope, the switcher would land visitors on the wrong search.
				$post_type = get_query_var( 'post_type' );

				if ( is_string( $post_type ) && $post_type !== '' ) {
					$uri .= '&post_type=' . rawurlencode( $post_type );
				}
			}
		}

		// Validate host against the site's actual domain to prevent host header injection.
		$expected_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $host !== $expected_host ) {
			$host = $expected_host;
		}

		return $scheme . '://' . $host . $uri;
	}
}
