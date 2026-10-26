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
use PerfLocale\Helper;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts URLs between languages: language prefix, host or query arg, and translated slugs.
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
	 * Soft cap for the per-request static memo arrays; cap_cache() drops the oldest 25%.
	 */
	private const MAX_CACHE_ENTRIES = 500;

	/**
	 * Cap on each blog's queue of term IDs awaiting a slug prime; queueing past it flushes that blog's queue.
	 *
	 * Per blog, not in total. Keep it equal to the per-call `count( $missing )` guard in
	 * preload_term_languages(): the triggering call's IDs are queued after the flush. Raise one, raise both.
	 */
	private const MAX_PENDING_TERM_SLUG_IDS = 500;

	/**
	 * Default language query variable for query-parameter URL mode.
	 */
	public const DEFAULT_QUERY_VAR = 'lang';

	/**
	 * Per-request memo of whether `permalink_structure` is set.
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
	 * Runs on switch_blog, language CRUD and perflocale/language/overridden. It leaves
	 * $pending_term_slug_ids alone on purpose (see that property).
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
		self::$home_authority                = null;
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
	 * Every writer and reader of the variable (router, rewrite rules, redirects, Site Health) calls this, so
	 * they cannot disagree. Bootstrap primes it during load.
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
		 * Register it from an mu-plugin: it is read during PerfLocale's load, before `plugins_loaded`.
		 * Flush permalinks after changing it; URLs carrying the old name stop resolving.
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
	 * Per-request URL config snapshot shared by every conversion entry point.
	 *
	 * Class-static so reset_static_caches() clears it; the url_mode filter is request-stable by contract.
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
	 * True only in subdirectory mode with a hidden default prefix or in query mode, with the visitor on the
	 * default language, no translation groups and no translated slugs. Memoized until reset_static_caches().
	 *
	 * @return bool
	 */
	private function should_skip_url_modification(): bool {
		// During install or subsite creation the plugin's tables may not exist: skip, without memoizing.
		if ( wp_installing() ) {
			return true;
		}

		if ( self::$skip_url_modification_memo !== null ) {
			return self::$skip_url_modification_memo;
		}

		// Subdomain and domain modes always rewrite the host.
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

		// A translated slug changes default-language URLs too.
		if ( $this->settings->translate_slugs_enabled() ) {
			$slug_repo = new SlugTranslationRepository( $this->cache );

			if ( $slug_repo->has_any_slugs() ) {
				self::$skip_url_modification_memo = false;
				return self::$skip_url_modification_memo;
			}
		}

		// A group means some post is in a non-default language.
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
	 * Batch-preloaded object languages, keyed "{type}_{id}"; filled on the_posts.
	 *
	 * @var array<string, object|null>
	 */
	private static array $object_language_cache = [];

	/**
	 * Term IDs whose slug prime is queued, keyed by blog ID, then term ID (docs-ai/04-do-not-fix.md 2.31).
	 *
	 * Keyed by blog because term IDs repeat across blogs. NOT cleared by reset_static_caches(): it holds only
	 * IDs, the prime reads languages when it runs, and dropping them sends slug reads to the per-key path.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $pending_term_slug_ids = [];

	/**
	 * Per-request memo for filter_home_url(), keyed "{url}|{path}".
	 *
	 * @var array<string, string>
	 */
	private static array $home_url_memo = [];

	/**
	 * Parsed home host for subdomain/domain mode; per blog, so reset_static_caches() clears it on switch_blog.
	 *
	 * @var string|null
	 */
	private static ?string $home_host = null;

	/**
	 * Home URL host[:port] for domain mode, where apply_domain() serves an unmapped default language.
	 * Per blog, so reset_static_caches() clears it on switch_blog.
	 *
	 * @var string|null
	 */
	private static ?string $home_authority = null;

	/**
	 * Slug => language object map for convert(); class-static so language CRUD clears it.
	 *
	 * @var array<string, object>|null
	 */
	private static ?array $lang_map = null;

	/**
	 * Memoised name of the language query variable; cleared on switch_blog.
	 *
	 * @var string|null
	 */
	private static ?string $query_var_memo = null;

	/**
	 * Home URL path for convert(); per blog, so cleared on switch_blog.
	 *
	 * @var string|null
	 */
	private static ?string $home_path = null;

	/**
	 * Home URL without its trailing slash, read with this class's home_url filter suppressed.
	 * Shared by inject_prefix() and the hidden-default branch of add_language_prefix_to_url().
	 *
	 * @var string|null
	 */
	private static ?string $home_trimmed = null;

	/**
	 * Active-language URL prefixes for strip_prefix(); per blog and changed by language CRUD.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $sorted_prefixes = null;

	/**
	 * Memo of whether `permalink_structure` is set; per blog on multisite.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_pretty_permalinks_memo = null;

	/**
	 * Per-request memo for is_front_page_translation(); cleared on switch_blog and on language CRUD,
	 * because a language delete changes group membership.
	 *
	 * @var array<int, bool>
	 */
	private static array $front_page_translation_memo = [];

	/**
	 * wp_navigation post IDs whose items were batch-primed this request; per-blog IDs, cleared on switch_blog.
	 *
	 * @var array<int, bool>
	 */
	private static array $primed_nav_refs = [];

	/**
	 * One-shot prime guard for the front-page translation group; same lifetime as $front_page_translation_memo.
	 *
	 * @var bool
	 */
	private static bool $front_page_translation_primed = false;

	/**
	 * Per-request memo for the post, page and CPT link filters, keyed "{type}_{id}_{url}".
	 *
	 * @var array<string, string>
	 */
	private static array $object_permalink_memo = [];

	/**
	 * Per-request is_admin() result; the admin/front-end split cannot flip mid-request.
	 *
	 * @var bool|null
	 */
	private static ?bool $is_admin_cached = null;

	/**
	 * Per-request cache of Settings::get_excluded_paths().
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $excluded_paths_cached = null;

	/**
	 * Per-request memo of should_skip_url_modification(): null unknown, true skip, false run the filters.
	 *
	 * @var bool|null
	 */
	private static ?bool $skip_url_modification_memo = null;

	/**
	 * Per-request lang_id => language object map, built from the router's active languages (no DB call).
	 *
	 * @var array<int, object>|null
	 */
	private static ?array $lang_id_map = null;

	/**
	 * Lazily resolved translation-group repository, reused across sub-queries.
	 *
	 * Resetting it is housekeeping, not blog isolation: the container returns the same instance and the repo
	 * clears its own caches on switch_blog. The cross-blog risk is $object_language_cache, keyed without blog.
	 *
	 * @var TranslationGroupRepository|null
	 */
	private static ?TranslationGroupRepository $lazy_group_repo = null;

	/**
	 * Per-request URL config snapshot for url_config(); reset_static_caches() clears it.
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
		// Batch preload object languages for every WP_Query result (avoids N+1 in resolve_object_language()).
		add_filter( 'the_posts', [ $this, 'preload_object_languages' ], 5, 2 );

		// The permalink filters fire inside wp_setup_nav_menu_item, before wp_get_nav_menu_items, so the nav
		// menu prime hooks pre_wp_nav_menu, which runs earlier.
		add_filter( 'pre_wp_nav_menu', [ $this, 'prime_nav_menu_items_early' ], 10, 2 );

		// Block themes render the Navigation block, not wp_nav_menu(): prime its items before it renders.
		add_filter( 'pre_render_block', [ $this, 'prime_navigation_block_items' ], 10, 2 );

		// Navigation-link anchors render the saved `url` verbatim, without get_permalink(): swap in the
		// current-language sibling's permalink.
		add_filter( 'render_block_core/navigation-link', [ $this, 'localize_navigation_link_block' ], 10, 2 );
		add_filter( 'render_block_core/navigation-submenu', [ $this, 'localize_navigation_link_block' ], 10, 2 );

		// REST Link headers use the unprefixed rest_url() base: re-insert the entry prefix, keyed on how the
		// request came in. The third arg (blog id) leaves a sibling blog's REST base alone on multisite.
		add_filter( 'rest_url', [ $this, 'preserve_rest_language_prefix' ], 10, 3 );

		// Term analog of the the_posts prime: every rendered term list fires filter_term_link per term.
		add_filter( 'get_terms', [ $this, 'preload_term_languages' ], 5 );
		add_filter( 'get_the_terms', [ $this, 'preload_term_languages' ], 5 );

		// The slug half of that prime is queued rather than run; SlugManager
		// runs the queue when a queued term's slug is first read.
		$this->slug_manager->set_term_slug_prime_flusher(
			fn( int $term_id ) => $this->flush_pending_term_slug_prime( $term_id )
		);

		// The oEmbed controller's url_to_postid() is scoped to the request language and reads /de/ as a listing.
		add_filter( 'oembed_request_post_id', [ $this, 'resolve_oembed_post_id' ], 10, 2 );

		$reset_static = [ self::class, 'reset_static_caches' ];

		// Multisite only: single-site switch_to_blog() still fires switch_blog and would wipe the memos needlessly.
		if ( is_multisite() ) {
			add_action( 'switch_blog', $reset_static );
		}

		// Language CRUD: the language snapshots baked into these memos would go stale for the rest of the request.
		add_action( 'perflocale/language/added', $reset_static );
		add_action( 'perflocale/language/updated', $reset_static );
		add_action( 'perflocale/language/slug_renamed', $reset_static );
		add_action( 'perflocale/language/deleted', $reset_static );

		// The order-email render window overrides the current language, which the memos bake in: a URL built
		// for one order's language would leak into the next order rendered in the same request.
		add_action( 'perflocale/language/overridden', $reset_static );

		// Filter all permalink-generating functions to add language identifier.
		add_filter( 'post_link', [ $this, 'filter_post_permalink' ], 10, 2 );
		add_filter( 'page_link', [ $this, 'filter_page_link' ], 10, 2 );
		add_filter( 'post_type_link', [ $this, 'filter_post_type_link' ], 10, 2 );

		// Attachment pages get the current language's prefix, like unmanaged posts, so a /de/ visitor stays on /de/.
		add_filter( 'attachment_link', [ $this, 'filter_attachment_link' ], 10, 2 );
		add_filter( 'term_link', [ $this, 'filter_term_link' ], 10, 3 );
		add_filter( 'home_url', [ $this, 'filter_home_url' ], 10, 4 );

		// Filter author and date archive links.
		add_filter( 'author_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'year_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'month_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'day_link', [ $this, 'add_language_prefix' ] );
		add_filter( 'search_link', [ $this, 'add_language_prefix' ] );

		// Query mode leaves home_url() untouched, so URLs core derives from a base need repair: the GET search
		// form drops ?lang= (inject a hidden field), and appended segments land inside the ?lang= value.
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

		// WooCommerce AJAX endpoints come from the home URL, which query mode leaves unfiltered: carry the language.
		add_filter( 'woocommerce_ajax_get_endpoint', [ $this, 'add_lang_to_wc_ajax_endpoint' ] );

		if ( $this->settings->translate_slugs_enabled() ) {
			// Map translated taxonomy slugs in incoming URLs to database slugs; priority 3 runs after
			// LanguageRouter (priority 1).
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
	 * Untyped: get_page_link() fires this with a null ID for a missing page, and a typed parameter would fatal.
	 * The input is then returned unchanged, as core does without the plugin.
	 *
	 * @param mixed $link    The page link (an earlier filter may have changed its type).
	 * @param mixed $post_id The page ID, or null when core could not resolve the post.
	 * @return mixed Filtered link, or the input unchanged.
	 */
	public function filter_page_link( $link, $post_id = 0 ) {
		if ( $this->filtering ) {
			return $link;
		}

		// is_numeric() rejects core's null and any array, so the (int) cast cannot fatal.
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

		// A front page translation links to the language root (/en/), not its slug.
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
	 * Prefix attachment-page links with the current request language, as for untranslated posts.
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
	 * Untyped: core passes any caller object (such as an stdClass row) through verbatim, and a typed
	 * parameter would fatal; unusable input is returned unchanged.
	 *
	 * @param mixed $link     The term link (any type).
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
	 * A hierarchical taxonomy's query var holds the full 'parent/child' path: read it through wp_basename(),
	 * as WP_Query::parse_tax_query() does, or every nested archive misses. Hooked to parse_request at 3.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function resolve_translated_taxonomy_slugs( \WP $wp ): void {
		$language_id = $this->router->get_current_language_id();

		if ( $language_id === 0 ) {
			return;
		}

		// Taxonomy query_var => taxonomy map, plus the hierarchical query vars. Deliberately not memoised in a
		// static: taxonomies differ per blog and the translatable list is a setting.
		$tax_query_vars  = [];
		$hier_query_vars = [];

		foreach ( $this->settings->get_translatable_taxonomies() as $taxonomy ) {
			$tax_obj = get_taxonomy( $taxonomy );

			if ( $tax_obj && $tax_obj->query_var ) {
				$tax_query_vars[ $tax_obj->query_var ] = $taxonomy;

				// Read through empty(), as core does: `rewrite` is false when a taxonomy opts out.
				if ( ! empty( $tax_obj->rewrite['hierarchical'] ) ) {
					$hier_query_vars[] = (string) $tax_obj->query_var;
				}
			}
		}

		foreach ( $tax_query_vars as $qv => $taxonomy ) {
			if ( empty( $wp->query_vars[ $qv ] ) || ! is_string( $wp->query_vars[ $qv ] ) ) {
				continue;
			}

			// Core delivers this urldecoded; slug_translations holds the sanitize_title() form.
			$slug = Helper::normalize_slug_for_query( $wp->query_vars[ $qv ] );

			// Reduce 'parent/child' to the term's own segment, as WP_Query::parse_tax_query() does later.
			if ( in_array( $qv, $hier_query_vars, true ) ) {
				$slug = wp_basename( $slug );
			}

			// Taxonomy-aware: "uncategorized" can exist in both category and product_cat.
			$db_slug = $this->resolve_term_slug_for_taxonomy( $slug, $taxonomy, $language_id );

			if ( $db_slug !== null && $db_slug !== $slug ) {
				// Writing the bare DB slug back is safe: parse_tax_query() drops ancestors with wp_basename().
				$wp->query_vars[ $qv ] = $db_slug;
			}
		}
	}

	/**
	 * Resolve a translated slug to the database slug for a specific taxonomy.
	 *
	 * Filters by object_subtype so taxonomies can share a translated slug; slug_lookup's UNIQUE index gives one row.
	 *
	 * @param string $slug Translated slug from the URL.
	 * @param string $taxonomy Taxonomy to match against.
	 * @param int    $language_id Current language ID.
	 * @return string|null Database slug, or null if not found.
	 */
	private function resolve_term_slug_for_taxonomy( string $slug, string $taxonomy, int $language_id ): ?string {
		global $wpdb;

		$st_table = \PerfLocale\Database\Schema::table( 'slug_translations' );

		// The slug_lookup UNIQUE key (language_id, object_type, object_subtype, slug) gives one row: no term_taxonomy join.
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
	 * A feed or page 2+ suffix after the term base is kept in the redirect. In query mode the request's query
	 * args are merged into the canonical's, whose values win; otherwise the query string is appended verbatim.
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

		// '' means "no translation", as in add_language_prefix_to_url(): both must agree or the canonical URL
		// and this redirect diverge.
		if ( $translated_slug === null || $translated_slug === '' || $translated_slug === $term->slug ) {
			return;
		}

		// get_term_link() runs filter_term_link(), so $canonical carries the translated slug.
		$canonical = get_term_link( $term );

		if ( is_wp_error( $canonical ) ) {
			return;
		}

		// esc_url_raw(), not sanitize_text_field(): the latter strips %XX octets, so a percent-encoded slug path
		// would never match the canonical and the 301 would loop.
		$canonical_path = untrailingslashit( (string) ( wp_parse_url( $canonical, PHP_URL_PATH ) ?: '' ) );
		$request_uri    = ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '' );
		$qpos           = strpos( $request_uri, '?' );
		$request_path   = untrailingslashit( (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '' ) );

		if ( $canonical_path === $request_path ) {
			return; // Already at canonical URL.
		}

		// Feed and page 2+ suffix, recognised only where redirect_canonical() would, so a term slugged `feed`
		// is never cut. Patterns come from $wp_rewrite (add_feed(), pagination base) and anchor on the end.
		global $wp_rewrite;

		$suffix         = '';
		$suffix_pattern = '';

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			$paged = get_query_var( 'paged' );

			if ( is_feed() && ! empty( $wp_rewrite->feeds ) ) {
				$feeds          = implode( '|', array_map( static fn( $feed ): string => preg_quote( (string) $feed, '#' ), (array) $wp_rewrite->feeds ) );
				$suffix_pattern = '/(?:' . preg_quote( (string) $wp_rewrite->feed_base, '#' ) . '/)?(?:' . $feeds . ')';
			} elseif ( is_numeric( $paged ) && (int) $paged > 1 ) {
				$suffix_pattern = '/' . preg_quote( (string) $wp_rewrite->pagination_base, '#' ) . '/?\d+';
			}
		}

		if ( $suffix_pattern !== '' ) {
			// The whole rest after the canonical base must match: a term slugged `feed` keeps its `/feed/feed/`.
			if ( str_starts_with( $request_path, $canonical_path . '/' )
				&& preg_match( '#^' . $suffix_pattern . '$#', substr( $request_path, strlen( $canonical_path ) ) ) === 1
			) {
				return;
			}

			if ( preg_match( '#' . $suffix_pattern . '$#', $request_path, $suffix_match ) === 1 ) {
				$suffix = $suffix_match[0];
			}
		}

		// Canonical URL plus the request's suffix. Loop-proof: the target's base is $canonical_path.
		$query_pos       = strpos( $canonical, '?' );
		$target          = $query_pos !== false ? substr( $canonical, 0, $query_pos ) : $canonical;
		$canonical_query = $query_pos !== false ? substr( $canonical, $query_pos + 1 ) : '';

		if ( $suffix !== '' ) {
			$target = str_ends_with( $target, '/' ) ? untrailingslashit( $target ) . $suffix . '/' : $target . $suffix;
		}

		if ( $canonical_query === '' ) {
			// Keep UTM, tracking and preview params, appended verbatim.
			if ( $qpos !== false ) {
				$target .= substr( $request_uri, $qpos );
			}
		} else {
			// Query URL mode: appending would add a second `?` whose `lang` matches nothing. Drop the
			// canonical's keys from the request args, then add the canonical's, so its language wins.
			$canonical_args = [];
			wp_parse_str( $canonical_query, $canonical_args );

			$request_query = $qpos !== false ? substr( $request_uri, $qpos ) : '';
			$target        = add_query_arg(
				urlencode_deep( $canonical_args ),
				$target . remove_query_arg( array_map( 'strval', array_keys( $canonical_args ) ), $request_query )
			);
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Filter home_url to add language prefix.
	 *
	 * Only for non-admin, non-REST paths of this blog. Untyped: core forwards the caller's raw arguments, and
	 * a null `$path` would fatal a typed parameter; an unusable `$url` is returned unchanged.
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

		// Another blog's home URL is not ours: get_home_url() restores the calling blog before this filter
		// fires, so every memo, language and home host here belongs to the caller. is_numeric() rejects null
		// ("current blog") and arrays; single-site core ignores $blog_id.
		if ( is_numeric( $blog_id ) && (int) $blog_id > 0 && (int) $blog_id !== get_current_blog_id() && is_multisite() ) {
			return $url;
		}

		// Non-string paths become '', as get_home_url() treated them, so the excluded-path check matches.
		$path = is_string( $path ) ? $path : '';

		// Universal skip on requests that cannot produce a rewritten URL.
		if ( $this->should_skip_url_modification() ) {
			return $url;
		}

		// The admin/front-end split cannot flip mid-request.
		if ( self::$is_admin_cached === null ) {
			self::$is_admin_cached = is_admin();
		}

		if ( self::$is_admin_cached ) {
			return $url;
		}

		// Query mode never rewrites home_url(): core appends path segments to it, which would land inside
		// ?lang=. Final-URL filters, the search-form field and convert() attach the language instead.
		if ( $this->url_config()['url_mode'] === 'query' ) {
			return $url;
		}

		// Per-request memo keyed by url and path: home_url fires many times per render with few distinct inputs.
		$memo_key = $url . '|' . $path;
		if ( isset( self::$home_url_memo[ $memo_key ] ) ) {
			return self::$home_url_memo[ $memo_key ];
		}

		// Don't prefix wp-admin, wp-json or other excluded paths; the normalized needles are cached per request.
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

		// Shared with LanguageRouter so both match the same paths; decoded so a non-Latin excluded path matches.
		if ( Helper::path_matches_excluded( $path, self::$excluded_paths_cached ) ) {
			self::cap_cache( self::$home_url_memo );
			self::$home_url_memo[ $memo_key ] = $url;
			return self::$home_url_memo[ $memo_key ];
		}

		self::cap_cache( self::$home_url_memo );
		self::$home_url_memo[ $memo_key ] = $this->add_language_prefix( $url );
		return self::$home_url_memo[ $memo_key ];
	}

	/**
	 * Keep rest_url() output on the language-prefixed REST base the request entered through.
	 *
	 * Only during a REST request that entered via /<lang>/wp-json; wp-admin's unprefixed base passes through.
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

		// A sibling blog's REST base carries no language of ours: on a subdomain network every home path is
		// empty, so the pattern below would match a sibling's base too. Same guard as filter_home_url().
		if ( is_numeric( $blog_id ) && (int) $blog_id > 0 && (int) $blog_id !== get_current_blog_id() && is_multisite() ) {
			return $url;
		}

		static $entry_prefix     = null;
		static $reinsert_pattern = '';

		if ( $entry_prefix === null ) {
			$entry_prefix = '';
			$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Prefix-matched against the active-language slug set only.

			// Strip a WP-in-subfolder home path so the prefix is compared at the site root. Reads the raw
			// option to bypass our own home_url filter.
			$home_path = rtrim( (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ?: '' ), '/' );
			$to_match  = $request_uri;

			if ( $home_path !== '' && str_starts_with( $request_uri, $home_path . '/' ) ) {
				$to_match = substr( $request_uri, strlen( $home_path ) );
			}

			if ( preg_match( '#^/([a-z0-9_-]+)/wp-json(?:/|\?|$)#i', $to_match, $m ) ) {
				$segment = strtolower( $m[1] );

				// Match the slug and the configured prefix (locale form 'de-de'); store the form that appeared.
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

		// Query mode: the default language stays on clean URLs, like the hidden default prefix.
		if ( $url_mode === 'query' ) {
			if ( $this->router->is_default_language() ) {
				return $url;
			}

			// get_url_prefix() honours the URL Prefix Format setting; the reader accepts slug and locale forms.
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
	 * A GET form replaces the action's query string, dropping ?lang=. A non-string is returned unchanged:
	 * core reads a null `get_search_form` result as "use the default form", and a cast would blank it.
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
	 * Append the current language to WooCommerce AJAX endpoint URLs (query URL mode).
	 *
	 * Plain append: add_query_arg() would encode the %%endpoint%% placeholder WC fills client-side.
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
	 * Core appends segments to trailingslashit( get_permalink() ), so `/post/?lang=de/seg/` becomes
	 * `/post/seg/?lang=de`. Other URLs and modes pass through.
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

		// Collapse a doubled slash at the join point, except after the scheme.
		return (string) preg_replace( '#(?<!:)//+#', '/', $url );
	}

	/**
	 * Apply repair_query_lang_url() to href attributes in link HTML (wp_link_pages_link passes anchors).
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
	 * @param string $url URL to convert.
	 * @param string $target_slug Target language slug.
	 * @return string Converted URL, or $url unchanged and unfiltered when $target_slug is not an active language.
	 */
	public function convert( string $url, string $target_slug ): string {
		// Refuse a slug this site does not route: it is public API input, and /fr/ on an en/de site 301s via
		// WP's canonical guesser onto an unrelated post. Returning the input is deliberate (a default-language
		// fallback would hide the typo). The map is class-static, cleared by reset_static_caches().
		if ( self::$lang_map === null ) {
			self::$lang_map = [];

			foreach ( $this->router->get_active_languages() as $tl ) {
				self::$lang_map[ $tl->slug ] = $tl;
			}
		}

		$target_lang_obj = self::$lang_map[ $target_slug ] ?? null;

		if ( $target_lang_obj === null ) {
			// Deliberately before the perflocale/url/convert filter: its contract is "the URL we converted".
			return $url;
		}

		$current_slug = $this->router->get_current_slug();
		$default      = $this->router->get_default_language();

		// Hot path (per hreflang link and switcher alternate): parse once, home path cached.
		$parsed = wp_parse_url( $url );

		if ( $parsed === false ) {
			return $url;
		}

		$path = $parsed['path'] ?? '/';

		// Read home_url() with $this->filtering set, or our own filter adds the prefix and it doubles below.
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

		// Strip any active-language prefix (callers may pass another language's URL), in subdirectory mode
		// only: in the other modes a first segment like "de" is a real page slug.
		$cfg      = $this->url_config();
		$url_mode = $cfg['url_mode'];

		if ( $url_mode === 'subdirectory' ) {
			$path = $this->strip_language_prefix_from_path( $path );
		}

		// Add target language prefix (unless it's the default and prefix is hidden).
		$hide_default = $cfg['hide_default'];

		// Only subdirectory mode puts the language in the path; host modes and query mode leave it alone.
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

		// Re-add the query string; in query mode drop a stale lang parameter first.
		$query_string = (string) ( $parsed['query'] ?? '' );

		if ( $query_routed && $query_string !== '' ) {
			parse_str( $query_string, $query_args );
			unset( $query_args[ self::query_var() ] );
			$query_string = $query_args === [] ? '' : http_build_query( $query_args );
		}

		if ( $query_string !== '' ) {
			$new_url .= '?' . $query_string;
		}

		// Query mode: append the target language parameter; the default language stays clean.
		if ( $query_routed && ! ( $default !== null && $target_slug === $default->slug ) ) {
			// Emit the configured prefix form (slug or full locale), not the raw slug.
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
		// Per-request cache keyed by blog: alternate URLs are per blog, and a function-local static is out of
		// reach of any switch_blog reset.
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

				// Batch-prime the post cache so the get_post() calls below hit it.
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
					// The front page may be a sibling in this group: reuse $links.
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
						// get_permalink() keeps the post type base (/product/), which a slug-only URL drops.
						$permalink = get_permalink( $post->ID );

						// get_permalink() can return false: skip it rather than emit an empty href.
						if ( is_string( $permalink ) && $permalink !== '' ) {
							$urls[ $lang->slug ] = $permalink;
						}
					}
				}

				// A rewrite endpoint on the page (WooCommerce's
				// /my-account/orders/) is part of the address in every language.
				$endpoint = $this->current_endpoint();

				if ( $endpoint !== null ) {
					foreach ( $urls as $slug => $url ) {
						$urls[ $slug ] = $this->append_endpoint( $url, $endpoint );
					}
				}

				$cached_by_blog[ $blog_key ] = $urls;
				return $urls;
			}
		}

		// Term archives: link each translated term through get_term_link(), so the alternate carries the
		// translated slug as the sitemap does; the generic prefix swap below would emit a URL that 301s.
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$repo  = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
				$links = $repo->get_translations( (int) $term->term_id, \PerfLocale\Enum\ObjectType::Term );

				// Batch-prime the sibling terms, or each get_term() below is a SELECT without an object cache.
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

		// A term archive without links whose link is a query string (plain permalinks): get_current_url() drops
		// that query string, so use the server-built link.
		if ( ( is_tax() || is_category() || is_tag() ) && get_queried_object() instanceof \WP_Term ) {
			$term_link = get_term_link( get_queried_object() );

			if ( is_string( $term_link ) && str_contains( $term_link, '?' ) ) {
				$current = $term_link;
			}
		}

		foreach ( $languages as $lang ) {
			$urls[ $lang->slug ] = $this->convert( $current, $lang->slug );
		}

		$cached_by_blog[ $blog_key ] = $urls;
		return $urls;
	}

	/**
	 * The rewrite endpoint the main query was routed through, if any.
	 *
	 * A query string can set any endpoint var, so with pretty permalinks it counts only when the matched rule
	 * carries it; with plain permalinks only on a page.
	 *
	 * @return array{var: string, name: string, value: string}|null Query var,
	 *         endpoint slug and value (`view-order` / `123`), or null.
	 */
	public function current_endpoint(): ?array {
		global $wp_rewrite, $wp_query, $wp;

		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_query instanceof \WP_Query || empty( $wp_rewrite->endpoints ) ) {
			return null;
		}

		$pretty  = $wp_rewrite->using_permalinks();
		$matched = [];

		if ( $pretty ) {
			// matched_query is unset until parse_request() matched a rule.
			$rule_query = $wp instanceof \WP ? (string) $wp->matched_query : '';

			if ( $rule_query === '' ) {
				return null;
			}

			parse_str( $rule_query, $matched );
		} elseif ( ! $wp_query->is_page() ) {
			return null;
		}

		foreach ( (array) $wp_rewrite->endpoints as $endpoint ) {
			$name = (string) ( $endpoint[1] ?? '' );
			$var  = (string) ( $endpoint[2] ?? '' );

			if ( $name === '' || $var === '' || ! isset( $wp_query->query[ $var ] ) || ! is_scalar( $wp_query->query[ $var ] ) ) {
				continue;
			}

			if ( $pretty && ! array_key_exists( $var, $matched ) ) {
				continue;
			}

			$segments = array_filter(
				explode( '/', (string) $wp_query->query[ $var ] ),
				static fn( string $segment ): bool => $segment !== ''
			);

			return [
				'var'   => $var,
				'name'  => $name,
				'value' => implode( '/', array_map( static fn( string $segment ): string => rawurlencode( rawurldecode( $segment ) ), $segments ) ),
			];
		}

		return null;
	}

	/**
	 * Add a rewrite endpoint to a page URL, as WooCommerce's wc_get_endpoint_url() builds it.
	 *
	 * @param string                                          $url      Page URL.
	 * @param array{var: string, name: string, value: string} $endpoint From current_endpoint().
	 * @return string
	 */
	private function append_endpoint( string $url, array $endpoint ): string {
		if ( (string) get_option( 'permalink_structure' ) === '' ) {
			return add_query_arg( $endpoint['var'], $endpoint['value'], $url );
		}

		$query = '';
		$qpos  = strpos( $url, '?' );

		if ( $qpos !== false ) {
			$query = substr( $url, $qpos );
			$url   = substr( $url, 0, $qpos );
		}

		$path = $endpoint['value'] === '' ? $endpoint['name'] : $endpoint['name'] . '/' . $endpoint['value'];

		return user_trailingslashit( trailingslashit( $url ) . $path ) . $query;
	}

	/**
	 * Inject a language prefix into a URL.
	 *
	 * @param string $url URL.
	 * @param string $prefix Language slug to inject.
	 * @return string URL with language prefix.
	 */
	private function inject_prefix( string $url, string $prefix ): string {
		// Unfiltered home URL, cached per request: other plugins' home_url hooks still run on every call.
		if ( self::$home_trimmed === null ) {
			$this->filtering = true;
			try {
				self::$home_trimmed = rtrim( home_url(), '/' );
			} finally {
				$this->filtering = false;
			}
		}
		$home_url_trimmed = self::$home_trimmed;

		// Return a URL that already carries this prefix unchanged.
		$already_prefixed = $home_url_trimmed . '/' . $prefix;
		if ( $url === $already_prefixed
			|| str_starts_with( $url, $already_prefixed . '/' ) ) {
			return $url;
		}

		// Insert the prefix after the home URL.
		if ( str_starts_with( $url, $home_url_trimmed ) ) {
			$remainder = substr( $url, strlen( $home_url_trimmed ) );
			// Strip a prefix filter_home_url() may have added, or /en/de/product/foo/ results.
			$remainder = $this->strip_language_prefix_from_path( $remainder );

			$url = $home_url_trimmed . '/' . $prefix . $remainder;
		}

		// Trailing slash when permalinks are enabled (WP convention).
		if ( self::has_pretty_permalinks() ) {
			// Work on the path only: trailingslashit() on the whole URL would put the slash after the query.
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
	 * @param string $path Path portion after the home URL (e.g. /de/product/foo/).
	 * @return string Path without any language prefix.
	 */
	private function strip_language_prefix_from_path( string $path ): string {
		// Active-language prefixes, longest first so 'en-gb' matches before 'en'.
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
		// Parsed home host, memoised (runs per language per converted URL); per blog, cleared on switch_blog.
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
	 * A default language without its own domain is served on the home host, so its links built on a
	 * language domain move back there. A non-default language without a domain is returned unchanged.
	 *
	 * @param string $url URL to modify.
	 * @param object $language Language object.
	 * @return string URL with language domain.
	 */
	private function apply_domain( string $url, object $language ): string {
		$target_domain = $this->settings->get_language_domain( $language->slug );

		if ( empty( $target_domain ) ) {
			$target_domain = self::$home_authority ?? $this->home_authority();

			// A link already on the home host stays there. Anchored at the start: the home address can also
			// appear in a query value of a link on another host.
			if (
				$target_domain === ''
				|| str_starts_with( $url, 'https://' . $target_domain . '/' )
				|| str_starts_with( $url, 'http://' . $target_domain . '/' )
			) {
				return $url;
			}

			$default = $this->router->get_default_language();

			if ( $default === null || $language->slug !== $default->slug ) {
				return $url;
			}
		}

		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';

		// Replace host and port together: wp_parse_url() returns the host without its port, so replacing the
		// bare host would leave the source port after a target that carries its own.
		$source = $host . ( isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '' );

		if ( $host !== '' && $source !== $target_domain ) {
			$url = str_replace( '://' . $source, '://' . $target_domain, $url );
		}

		return $url;
	}

	/**
	 * Host, with its port when it has one, of the site's own home URL.
	 *
	 * Read with this class's home_url filter suppressed: the stored `home`, never a language host. Per blog.
	 *
	 * @return string Authority such as `example.com` or `example.com:8443`, or '' when home has no host.
	 */
	private function home_authority(): string {
		if ( self::$home_authority === null ) {
			$this->filtering = true;
			try {
				$home = wp_parse_url( home_url() );
			} finally {
				$this->filtering = false;
			}

			$host = is_array( $home ) ? (string) ( $home['host'] ?? '' ) : '';

			self::$home_authority = $host === '' ? '' : $host . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' );
		}

		return self::$home_authority;
	}

	/**
	 * Resolve a query-string URL to its pretty permalink equivalent.
	 *
	 * WordPress returns ?page_id=X or ?p=X for draft, pending and future posts; with pretty permalinks the
	 * URL is built from the slug so language prefixes apply.
	 *
	 * @param string $url URL that may contain query-string format.
	 * @param int    $post_id Post ID.
	 * @return string Resolved pretty URL or original URL.
	 */
	private function resolve_query_string_url( string $url, int $post_id ): string {
		// Fast path: pretty permalink URLs have no '?'.
		if ( strpos( $url, '?' ) === false ) {
			return $url;
		}

		if ( ! self::has_pretty_permalinks() ) {
			return $url;
		}

		// Only ?page_id=X or ?p=X. Parse and test the keys: a substring 'p=' test matches parameters such as
		// WooCommerce's `?attribute_pa_group=large` and breaks every variation URL.
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( $query === '' ) {
			return $url;
		}

		$args = [];
		wp_parse_str( $query, $args );

		if ( ! isset( $args['p'] ) && ! isset( $args['page_id'] ) ) {
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

		// Posts and CPTs: use the structure the type registered. Never invent one: without a permastruct keep
		// the working query-string URL rather than a pretty one that 404s.
		if ( $post->post_type === 'post' ) {
			$struct = (string) get_option( 'permalink_structure' );
		} else {
			$struct = (string) get_option( $post->post_type . '_permalink_structure', '' );

			if ( $struct === '' ) {
				global $wp_rewrite;

				$struct = ( $wp_rewrite instanceof \WP_Rewrite )
					? (string) $wp_rewrite->get_extra_permastruct( $post->post_type )
					: '';
			}
		}

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
			// Query mode: the default language stays clean (no parameter), one canonical URL per language.
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
				// Hidden default: remove a prefix filter_home_url() added for the visitor's language, after the
				// same stored base inject_prefix() uses.
				if ( self::$home_trimmed === null ) {
					$this->filtering = true;
					try {
						self::$home_trimmed = rtrim( home_url(), '/' );
					} finally {
						$this->filtering = false;
					}
				}
				$home = self::$home_trimmed;

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

			// '' means "no translation", as in redirect_term_to_translated_slug(); else the slug is stripped (/de//).
			if ( $translated_slug !== null && $translated_slug !== '' ) {
				$url = $this->replace_slug_in_url( $url, $object_id, $object_type, $translated_slug );
			}
		}

		return $url;
	}

	/**
	 * Prime caches for a classic menu's linked posts before wp_setup_nav_menu_item builds their permalinks.
	 *
	 * Hooked to `pre_wp_nav_menu`, which fires before the items are fetched; never short-circuits.
	 *
	 * @param \stdClass|string|null $output Existing short-circuit value (null = let WP continue).
	 * @param object|array|string   $args wp_nav_menu arguments.
	 * @return \stdClass|string|null Unmodified - we only prime, never short-circuit.
	 */
	public function prime_nav_menu_items_early( mixed $output, mixed $args ): mixed {
		if ( $output !== null || is_admin() ) {
			return $output;
		}

		// Primed menus keyed by blog: menu term IDs collide across blogs, and no reset reaches a static.
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

		// Only the post IDs the menu references, not the full menu items.
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

		// DISTINCT already dedupes and the prime handles duplicates: no array_unique().
		$post_ids = array_values( array_filter( array_map( 'intval', (array) $post_ids_raw ), static fn( int $id ): bool => $id > 0 ) );

		if ( $post_ids === [] ) {
			return $output;
		}

		$this->prime_menu_post_ids( $post_ids );

		return $output;
	}

	/**
	 * Batch-prime the post IDs a Navigation block references, before its link blocks render.
	 *
	 * Walks the inner blocks and the `ref` wp_navigation post; never short-circuits rendering.
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
			// Prime the menu that will render, not the saved one: BlockRefTranslator swaps the ref on
			// render_block_data, after this pre_render_block pass. The translator memoises.
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( $plugin->has( 'block_ref_translator' ) ) {
				$translated_ref = $plugin->get( 'block_ref_translator' )->translated_ref( $ref, 'wp_navigation' );

				if ( $translated_ref > 0 ) {
					$ref = $translated_ref;
				}
			}

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
	 * Recursively collect post-type object IDs from navigation-link and navigation-submenu blocks.
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
	 * Point a rendered navigation-link or navigation-submenu anchor at the current language's sibling.
	 *
	 * Core renders the saved `url` and never calls get_permalink(). Without a published sibling the saved URL stands.
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
			return $block_content; // Custom/external URL item - nothing to localize.
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
				return $block_content; // Draft sibling - keep the working saved URL.
			}
			$url = get_permalink( $sibling_id );
		}

		if ( ! is_string( $url ) || $url === '' ) {
			return $block_content;
		}

		// Only the first anchor: a submenu's children are separate navigation-link blocks, filtered on their own.
		$processor = new \WP_HTML_Tag_Processor( $block_content );

		if ( $processor->next_tag( 'a' ) ) {
			$processor->set_attribute( 'href', esc_url( $url ) );
			return $processor->get_updated_html();
		}

		return $block_content;
	}

	/**
	 * Resolve a URL WordPress could not resolve to its post ID for oEmbed.
	 *
	 * url_to_postid() is scoped to the request language, so retry with scoping suspended (removed in finally,
	 * or a caught exception leaves the request unscoped), then try a language root.
	 *
	 * @param int    $post_id Post ID WP resolved (0 when it could not).
	 * @param string $url     The requested URL.
	 * @return int
	 */
	public function resolve_oembed_post_id( $post_id, string $url ): int {
		if ( (int) $post_id > 0 ) {
			return (int) $post_id;
		}

		$suspend = static fn(): bool => true;
		add_filter( 'perflocale/query/include_all_languages', $suspend, 99 );

		try {
			$resolved = url_to_postid( $url );
		} finally {
			remove_filter( 'perflocale/query/include_all_languages', $suspend, 99 );
		}

		if ( $resolved > 0 ) {
			return (int) $resolved;
		}

		$front = $this->front_page_for_language_root( $url );

		return $front > 0 ? $front : (int) $post_id;
	}

	/**
	 * Static front page shown at the language root a URL addresses.
	 *
	 * The default language gives page_on_front; another gives its published, publicly viewable group member
	 * (the stored link status is not read). Anything else gives 0.
	 *
	 * @param string $url URL to resolve.
	 * @return int Post ID, or 0.
	 */
	private function front_page_for_language_root( string $url ): int {
		if ( get_option( 'show_on_front' ) !== 'page' ) {
			return 0;
		}

		$front_id = get_option( 'page_on_front' );
		$front_id = is_numeric( $front_id ) ? (int) $front_id : 0;

		if ( $front_id <= 0 ) {
			return 0;
		}

		$languages = [];

		foreach ( $this->router->get_active_languages() as $language ) {
			if ( isset( $language->slug, $language->id ) && is_string( $language->slug ) && is_numeric( $language->id ) ) {
				$languages[ $language->slug ] = (int) $language->id;
			}
		}

		$slug = $this->language_of_root_url( $url, $languages );

		if ( $slug === null ) {
			return 0;
		}

		$default = $this->url_config()['default'];

		if ( $default !== null && isset( $default->slug ) && $slug === $default->slug ) {
			return get_post( $front_id ) instanceof \WP_Post ? $front_id : 0;
		}

		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		if ( ! $repo instanceof TranslationGroupRepository ) {
			return 0;
		}

		foreach ( $repo->get_translations( $front_id, ObjectType::Post ) as $link ) {
			if ( ! is_object( $link ) || ! isset( $link->language_id, $link->object_id ) || ! is_numeric( $link->language_id ) || (int) $link->language_id !== ( $languages[ $slug ] ?? 0 ) ) {
				continue;
			}

			$target_id = is_numeric( $link->object_id ) ? (int) $link->object_id : 0;

			if ( $target_id > 0 && get_post_status( $target_id ) === 'publish' && is_post_publicly_viewable( $target_id ) ) {
				return $target_id;
			}

			return 0;
		}

		return 0;
	}

	/**
	 * Slug of the active language whose root a URL is, by the URL mode's routing rules.
	 *
	 * Query mode has none: url_to_postid() already resolves the bare home URL. Another host or port is
	 * rejected here, independently of url_to_postid()'s own checks.
	 *
	 * @param string             $url       URL to check.
	 * @param array<string, int> $languages Active languages, slug => id.
	 * @return string|null Language slug, or null when the URL is no language root.
	 */
	private function language_of_root_url( string $url, array $languages ): ?string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || isset( $parts['query'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = LanguageRouter::normalize_host( $parts['host'] ?? '' );

		if ( ( $scheme !== 'http' && $scheme !== 'https' ) || $host === '' ) {
			return null;
		}

		$port = $parts['port'] ?? null;
		$path = $parts['path'] ?? '';

		$this->filtering = true;
		try {
			$home = wp_parse_url( home_url() );
		} finally {
			$this->filtering = false;
		}

		if ( ! is_array( $home ) ) {
			return null;
		}

		$home_host = LanguageRouter::normalize_host( $home['host'] ?? '' );

		if ( $home_host === '' ) {
			return null;
		}

		$home_port = $home['port'] ?? null;
		$home_path = trailingslashit( $home['path'] ?? '' );

		// The home path without its trailing slash is the same root.
		if ( $path === rtrim( $home_path, '/' ) ) {
			$path = $home_path;
		}

		$cfg     = $this->url_config();
		$default = $cfg['default'] !== null && isset( $cfg['default']->slug ) && is_string( $cfg['default']->slug ) ? $cfg['default']->slug : null;

		if ( $cfg['url_mode'] === 'subdirectory' ) {
			if ( $host !== $home_host || $port !== $home_port || ! str_starts_with( $path, $home_path ) ) {
				return null;
			}

			$segment = substr( $path, strlen( $home_path ) );

			if ( $segment === '' ) {
				return $cfg['hide_default'] ? $default : null;
			}

			if ( str_ends_with( $segment, '/' ) ) {
				$segment = substr( $segment, 0, -1 );
			}

			if ( $segment === '' || str_contains( $segment, '/' ) ) {
				return null;
			}

			$slug = $this->router->slug_for_path_prefix( $segment );

			return $slug !== null && isset( $languages[ $slug ] ) ? $slug : null;
		}

		if ( $path !== $home_path ) {
			return null;
		}

		if ( $cfg['url_mode'] === 'subdomain' ) {
			if ( $port !== $home_port ) {
				return null;
			}

			if ( $host === $home_host ) {
				return $default;
			}

			if ( ! str_ends_with( $host, '.' . $home_host ) ) {
				return null;
			}

			$slug = substr( $host, 0, -strlen( '.' . $home_host ) );

			return isset( $languages[ $slug ] ) ? $slug : null;
		}

		if ( $cfg['url_mode'] === 'domain' ) {
			foreach ( $this->settings->get_language_domains() as $slug => $domain ) {
				$domain      = (string) $domain;
				$domain_port = preg_match( '/:(\d+)$/', $domain, $m ) ? (int) $m[1] : null;

				if ( isset( $languages[ $slug ] ) && LanguageRouter::normalize_host( $domain ) === $host && $domain_port === $port ) {
					return (string) $slug;
				}
			}

			return $host === $home_host && $port === $home_port ? $default : null;
		}

		return null;
	}

	/**
	 * Prime slug translations for object IDs across every active language, with null sentinels for gaps.
	 *
	 * The term leg runs the queue preload_term_languages() fills (prime_pending_term_slugs()).
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
		// Order matches the placeholders: %i, %s, then the two %d lists.
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

		// A failed SELECT proves nothing: seed no null sentinels, so each read runs its own lookup.
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return;
		}

		$found = [];

		foreach ( $rows as $row ) {
			$found[ (int) $row->object_id . ':' . (int) $row->language_id ] = (string) $row->slug;
		}

		foreach ( $object_ids as $pid ) {
			foreach ( $lang_ids as $lid ) {
				// Must match SlugTranslationRepository::get_slug()'s read key ("slug_{type}_{id}_{lang}").
				$cache_key = "slug_{$object_type}_{$pid}_{$lid}";
				$value     = $found[ $pid . ':' . $lid ] ?? null;
				$this->cache->set_static( $cache_key, $value, 'perflocale_slugs' );
			}
		}
	}

	/**
	 * Batch preload language slugs for all posts in a WP_Query result.
	 *
	 * Avoids N+1 queries on permalink lists; resolve_object_language() reads the result.
	 *
	 * @param array<int, \WP_Post> $posts Posts from WP_Query.
	 * @param \WP_Query            $query The query.
	 * @return array<int, \WP_Post> Unmodified posts.
	 */
	public function preload_object_languages( array $posts, \WP_Query $query ): array {
		if ( $posts === [] ) {
			return $posts;
		}

		// Only IDs not cached this request; sub-queries usually find them all primed and return here.
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

		// Hold the repository instance across sub-queries.
		if ( self::$lazy_group_repo === null ) {
			self::$lazy_group_repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		}

		$repo = self::$lazy_group_repo;

		// lang_id => language map from the router's active languages, instead of a find_many() query.
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

		// Prime only the missing IDs.
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

			// Unmanaged posts get the visitor's current language, so resolve_object_language() can short-circuit.
			self::$object_language_cache[ 'post_' . $pid ] = $lang ?? $current;
		}

		return $posts;
	}

	/**
	 * Batch-prime term translations and languages, the term analog of preload_object_languages().
	 *
	 * The slug prime is only queued (queue_term_slug_prime()). Non-ID term shapes pass through untouched.
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
			} elseif ( is_int( $t ) || ( is_string( $t ) && Helper::is_ascii_digits( $t ) ) ) {
				$tid = (int) $t;
			} else {
				return $terms; // slugs/names/count shapes - nothing to prime.
			}

			if ( $tid > 0 && ! isset( self::$object_language_cache[ 'term_' . $tid ] ) ) {
				$missing[] = $tid;
			}
		}

		// Huge term lists take the per-term path rather than bloat the L1 maps. This 500 must equal
		// MAX_PENDING_TERM_SLUG_IDS.
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

		// Queued, not run: most term reads (WooCommerce product data, template parts) build no link. Gated like
		// SlugManager::preload_slugs() on is_admin(); admin term links resolve per key. Both gate reads are memoized.
		if ( ! is_admin()
			&& $this->url_config()['translate_slugs']
			&& $this->slug_manager->has_any_slugs() ) {
			$this->queue_term_slug_prime( $missing );
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
	 * Queue term IDs for the current blog's deferred slug prime.
	 *
	 * A keyed set, so repeated IDs are stored once; passing MAX_PENDING_TERM_SLUG_IDS runs the queued prime first.
	 * IDs queued from inside that prime's SELECT are replaced, not kept, so the queue stays within the cap.
	 *
	 * @param array<int, int> $term_ids Term IDs, duplicates allowed.
	 * @return void
	 */
	private function queue_term_slug_prime( array $term_ids ): void {
		$blog_id = (int) get_current_blog_id();
		$queued  = self::$pending_term_slug_ids[ $blog_id ] ?? [];
		$fresh   = array_diff_key( array_fill_keys( $term_ids, true ), $queued );

		if ( $fresh === [] ) {
			return;
		}

		if ( count( $queued ) + count( $fresh ) > self::MAX_PENDING_TERM_SLUG_IDS ) {
			$this->prime_pending_term_slugs( $blog_id );
			$queued = [];
		}

		self::$pending_term_slug_ids[ $blog_id ] = $queued + $fresh;
	}

	/**
	 * Run the current blog's queued term slug prime when $term_id is queued.
	 *
	 * Called by SlugManager::get_slug() after its memo misses. A prime runs only on a non-empty queue and
	 * empties it, so a request never runs more of these SELECTs than queueing calls that added an ID.
	 *
	 * @param int $term_id ID of the term whose slug is about to be read.
	 * @return void
	 */
	private function flush_pending_term_slug_prime( int $term_id ): void {
		$blog_id = (int) get_current_blog_id();

		if ( isset( self::$pending_term_slug_ids[ $blog_id ][ $term_id ] ) ) {
			$this->prime_pending_term_slugs( $blog_id );
		}
	}

	/**
	 * Prime the slugs of every term queued for a blog, and empty its queue.
	 *
	 * Emptied before the query so nothing reached during the prime can run it again.
	 *
	 * @param int $blog_id Blog whose queue to run; must be the current blog.
	 * @return void
	 */
	private function prime_pending_term_slugs( int $blog_id ): void {
		$term_ids = array_keys( self::$pending_term_slug_ids[ $blog_id ] ?? [] );

		unset( self::$pending_term_slug_ids[ $blog_id ] );

		$this->prime_slug_translations_multilang( $term_ids, 'term' );
	}

	/**
	 * Check if a page is the static front page or a translation of it.
	 *
	 * Falls back to a slug match for pages not linked in the front page's translation group.
	 *
	 * @param int $post_id Page ID.
	 * @return bool
	 */
	private function is_front_page_translation( int $post_id ): bool {
		// Class-static memo: page_on_front and group membership are per blog and change with language CRUD.
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

		// Lazy prime of the front-page group: WooCommerce and block patterns build permalinks during `init`,
		// before the_posts and the nav-menu prime.
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

		// Fallback: single lookup for objects outside a preloaded batch. These statics need no blog reset: the
		// container returns the same instances on every blog; the blog-affine state is $object_language_cache.
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
		// Never replace a real slug with an empty one ('' means "no translation"), or /de/shop/ becomes /de//.
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
		// esc_url_raw(), not sanitize_text_field(), which strips %XX octets from non-Latin paths.
		$uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );

		// Drop the query string and fragment: alternates need the canonical URL, and HreflangTags caches them
		// under a key without the query string, so one visitor's ?utm_ would be served to everyone.
		$qpos = strpos( $uri, '?' );
		if ( $qpos !== false ) {
			$uri = substr( $uri, 0, $qpos );
		}
		$fpos = strpos( $uri, '#' );
		if ( $fpos !== false ) {
			$uri = substr( $uri, 0, $fpos );
		}

		// Search results live in the query string: re-append `s` alone. Safe for the strip above: HreflangTags
		// skips is_search(), and og:url and schema are computed per request on a search page.
		if ( is_search() ) {
			$term = get_search_query( false );

			if ( $term !== '' ) {
				$uri .= '?s=' . rawurlencode( $term );

				// A scoped search (?post_type=product) is a different result set: keep the scope.
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
