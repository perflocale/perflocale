<?php
/**
 * Language router - detects current language from the request.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Helper;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The most performance-critical class in PerfLocale.
 *
 * Detects the current language from the URL as early as possible
 * (on parse_request, priority 1) and sets it in a static property
 * for instant access by all other components.
 *
 * Language detection is O(1) using a prebuilt slug hash map.
 */
final class LanguageRouter {

	/**
	 * Static asset file extensions - hashmap for O(1) lookup.
	 * Used to skip language detection on non-HTML requests.
	 *
	 * @var array<string, true>
	 */
	private const STATIC_EXTENSIONS = [
		'ico'         => true,
		'png'         => true,
		'jpg'         => true,
		'jpeg'        => true,
		'gif'         => true,
		'svg'         => true,
		'webp'        => true,
		'avif'        => true,
		'css'         => true,
		'js'          => true,
		'mjs'         => true,
		'woff'        => true,
		'woff2'       => true,
		'ttf'         => true,
		'eot'         => true,
		'map'         => true,
		'webmanifest' => true,
	];

	/**
	 * Bot / crawler user-agent detection pattern.
	 *
	 * Word boundaries prevent false positives on substrings ("Abbott",
	 * "Botanica" → not bots). Compound crawler names whose trailing "bot" is
	 * preceded by a letter (googlebot, twitterbot, applebot, …) must be
	 * enumerated explicitly because `\bbot\b` sees a letter-letter boundary,
	 * not a word boundary — and facebookexternalhit carries no "bot" token at
	 * all. Enumeration is deliberately used over a generic `\w*bot\b` branch,
	 * which would false-positive on names like "Abbot".
	 *
	 * Single source of truth — used by browser-language redirect, edge-hint
	 * redirect, geo-IP redirect, and the language-cookie write. Add new bot
	 * tokens here once and they propagate everywhere.
	 *
	 * AI crawlers and assistant fetchers (GPTBot/OAI-SearchBot/ChatGPT-User,
	 * ClaudeBot/Claude-User/Claude-SearchBot, PerplexityBot/Perplexity-User,
	 * CCBot, Bytespider, Amazonbot, meta-externalagent, DuckAssistBot,
	 * MistralAI-User, cohere-ai, Diffbot) are enumerated for the same reason
	 * the classic compounds are: their "bot" is letter-bounded or absent, so
	 * none of the generic tokens can match them. They crawl for AI search and
	 * answer engines, where a language redirect or a poisoned cache entry
	 * mis-indexes the site exactly like a classic search engine.
	 *
	 * @hook perflocale/router/bot_ua_pattern Filter the bot detection regex.
	 */
	public const BOT_UA_PATTERN = '/\b(bot|crawl|spider|slurp|mediapartners|googlebot|bingbot|yandex|baidu|twitterbot|applebot|facebookexternalhit|linkedinbot|duckduckbot|gptbot|slackbot|discordbot|telegrambot|petalbot|ahrefsbot|semrushbot|pinterestbot|oai-searchbot|chatgpt-user|claudebot|claude-web|claude-user|claude-searchbot|anthropic-ai|perplexitybot|perplexity-user|ccbot|bytespider|amazonbot|meta-externalagent|meta-externalfetcher|duckassistbot|mistralai-user|cohere-ai|diffbot)\b/i';

	/**
	 * Test whether a user-agent string belongs to a known bot or crawler.
	 *
	 * Filterable via `perflocale/router/bot_ua_pattern` so site owners can
	 * extend the list without forking the plugin.
	 *
	 * @param string $ua Sanitized user-agent string.
	 * @return bool
	 */
	public static function is_bot_ua( string $ua ): bool {
		if ( $ua === '' ) {
			return false;
		}

		// Per-request memoization keyed by UA. The UA in a request is
		// constant - the same string can be checked from multiple call
		// sites (redirector + 404 handler + canonical filter). Caching
		// avoids re-firing the `apply_filters` chain and re-running
		// the regex for each one.
		static $memo = [];

		if ( isset( $memo[ $ua ] ) ) {
			return $memo[ $ua ];
		}

		/** This filter is documented in self::BOT_UA_PATTERN. */
		$pattern = (string) apply_filters( 'perflocale/router/bot_ua_pattern', self::BOT_UA_PATTERN );

		$memo[ $ua ] = $pattern !== '' && preg_match( $pattern, $ua ) === 1;

		return $memo[ $ua ];
	}

	/**
	 * The current request rebuilt as an absolute URL on this site.
	 *
	 * `add_query_arg( [] )` returns REQUEST_URI, which on a WP-in-subfolder
	 * install (`home` = `/blog`) or a subdirectory-multisite subsite (`/sub`)
	 * ALREADY carries the home path. Handing that straight back to home_url()
	 * concatenates the path twice: on a `/sub` subsite `GET /sub/` produced
	 * `http://site/sub/sub/`, and the language redirect built from it landed
	 * on `/sub/de/sub/` — a hard 404 on the subsite's own front page for every
	 * first-time visitor. So strip the home path off the request before
	 * home_url() puts it back; on a root install there is nothing to strip and
	 * this stays the bare `home_url( add_query_arg( [] ) )` it always was.
	 *
	 * The home path comes from the RAW `home` option, never from home_url():
	 * UrlConverter::filter_home_url() prepends the default language's prefix
	 * when hide_default_prefix=false, so home_url( '/' ) reports `/sub/en/`
	 * there and the `/sub` we need to strip would never match — the doubling
	 * would survive on exactly the sites that show their default prefix. Same
	 * reasoning, and the same two lines, as the four detection-side strips in
	 * this class. Reading a stored option also keeps the URL server-controlled:
	 * `HTTP_HOST` must NOT be used to build it, because allow_redirect_host()
	 * whitelists this URL's host for the wp_safe_redirect() that follows, and a
	 * request-supplied host would turn that gate into an open redirect.
	 *
	 * The prefix only counts when it ends on a path boundary (`/sub`,
	 * `/sub/…`, `/sub?…`): a bare str_starts_with() would also eat the `/sub`
	 * out of a sibling subsite at `/subX/` or a page at `/subtle-thing/`,
	 * silently rewriting an unrelated URL.
	 *
	 * Deliberately NOT memoized, unlike is_bot_ua() above: both REQUEST_URI
	 * and `home` are per-request/per-blog, and a static cache here would hand
	 * one blog's URL to the next after a switch_to_blog().
	 *
	 * Single source of truth for the browser-language, edge-hint and geo-IP
	 * redirect targets — three separate copies of this string surgery is how
	 * the doubling reached all three call sites at once.
	 *
	 * @return string Absolute URL of the current request.
	 */
	public static function current_request_url(): string {
		$request = (string) add_query_arg( [] );

		// parse_url() yields null when `home` carries no path at all; the
		// cast makes that '', which the normalize below folds to '/' exactly
		// as an explicit fallback would - so root installs take the no-op path.
		$home_path = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
		$home_path = '/' . trim( $home_path, '/' );

		if ( $home_path !== '/'
			&& ( $request === $home_path
				|| str_starts_with( $request, $home_path . '/' )
				|| str_starts_with( $request, $home_path . '?' ) )
		) {
			$request = substr( $request, strlen( $home_path ) );

			if ( $request === '' || $request[0] !== '/' ) {
				$request = '/' . $request;
			}
		}

		return home_url( $request );
	}

	/**
	 * The resolved current language object.
	 *
	 * @var object|null
	 */
	private static ?object $current_language = null;

	/**
	 * The default language object.
	 *
	 * @var object|null
	 */
	private static ?object $default_language = null;

	/**
	 * Whether detect_language() has already run (prevents double execution).
	 *
	 * @var bool
	 */
	private static bool $detection_finalized = false;

	/**
	 * Prefix → slug lookup cached inside detect_from_path().
	 *
	 * Was a method-local `static` - promoted to a class property so that
	 * `maybe_reset_on_switch()` (hooked to `switch_blog`) and
	 * `reset_data_caches()` (hooked to the language CRUD events) can clear
	 * it, otherwise prefix mappings from a previous blog's active languages
	 * would leak into the current blog's path-based detection.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $path_prefix_map = null;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Language repository (lazy loaded).
	 *
	 * @var LanguageRepository|null
	 */
	private ?LanguageRepository $repo = null;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( Settings $settings, CacheManager $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Detect language on parse_request - strips the language prefix from
		// the URL and finalizes detection (cookie, action, etc.).
		add_action( 'parse_request', [ $this, 'detect_language' ], 1 );

		// The locale filter is registered exactly once by Bootstrap::init()
		// before detect_locale_early(). No conditional re-registration here.

		// Register the language query variable (filterable, see UrlConverter::query_var()).
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );

		// Flush rewrite rules if the flag is set (after activation or settings change).
		add_action( 'init', [ $this, 'maybe_flush_rules' ], 99 );

		// Redirect first-time visitors to their browser language; register
		// only when enabled. Priority comes from `redirect_priority_order`
		// (base 9 + array index) so that when both geo and browser-language
		// redirects are on, the user's chosen-first method runs first without
		// colliding with core's template_redirect hooks. Mirror in GeoRedirect.
		if ( (bool) $this->settings->get( 'redirect_browser_lang', false ) ) {
			$order    = $this->settings->get_redirect_priority_order();
			$idx      = array_search( 'browser', $order, true );
			$priority = $idx !== false ? 9 + (int) $idx : 10;
			add_action( 'template_redirect', [ $this, 'maybe_redirect_browser' ], $priority );
		}

		// Edge-hint redirect: trust a language already pre-decided by an edge
		// worker (Cloudflare/Vercel/Netlify) via the X-PerfLocale-Lang header
		// or perflocale_edge_lang cookie. Free / fast alternative to the geoip
		// API. Requires both this setting AND the global edge integration to
		// be on - the latter gates `detect_from_edge_hint()` itself.
		if (
			(bool) $this->settings->get( 'redirect_edge_hint_enabled', false )
			&& $this->settings->edge_integration_enabled()
		) {
			$order    = $this->settings->get_redirect_priority_order();
			$idx      = array_search( 'edge_hint', $order, true );
			$priority = $idx !== false ? 9 + (int) $idx : 11;
			add_action( 'template_redirect', [ $this, 'maybe_redirect_edge_hint' ], $priority );
		}

		// Strip the `perflocale_redirected` sentinel via a canonical 301 so
		// the URL the user ends up on doesn't carry our internal tracking
		// parameter. Runs BEFORE the browser/geo redirect checks so those
		// never re-fire on a cleaned URL.
		add_action( 'template_redirect', [ $this, 'clean_redirect_sentinel' ], 1 );

		// When the user chose NOT to hide the default-language prefix, the
		// bare `/` and `/{default_slug}/` would otherwise both resolve to the
		// default homepage - duplicate content. 301 the bare form to
		// the prefixed form so there's one canonical URL. Only relevant for
		// subdirectory URL mode (subdomain/domain modes don't have this
		// conflict).
		if (
			! $this->settings->hide_default_prefix()
			&& $this->settings->get_url_mode() === 'subdirectory'
		) {
			add_action( 'template_redirect', [ $this, 'maybe_redirect_default_to_prefix' ], 2 );
		} elseif (
			$this->settings->hide_default_prefix()
			&& $this->settings->get_url_mode() === 'subdirectory'
		) {
			// The mirror case: with the default prefix HIDDEN, the canonical
			// home is the bare `/`, but `/{default_slug}/` (e.g. `/en/`) still
			// resolves to the same homepage - duplicate content with no 301.
			// (WordPress core's redirect_canonical only strips it for a static
			// page_on_front, not for a latest-posts front page.) 301 the bare
			// default-prefix home to `/`. Home-only by design: prefixed inner
			// URLs already carry a self-correcting canonical tag, and stripping
			// them here could mis-serve a URL whose content lives in another
			// language.
			add_action( 'template_redirect', [ $this, 'maybe_redirect_prefix_to_default' ], 2 );
		}

		// In `locale` prefix mode, canonicalise slug-form URLs (`/en/`) to
		// their locale equivalent (`/en-us/`). Without this, a user who
		// types the slug form hits a 404 because the rewrite rules only
		// match the configured prefix-type - this turns that 404 into a
		// graceful 301 to the canonical URL.
		if (
			$this->settings->get_url_mode() === 'subdirectory'
			&& $this->settings->get_url_prefix_type() === 'locale'
		) {
			add_action( 'template_redirect', [ $this, 'maybe_canonicalise_prefix_form' ], 3 );
		}

		// Query mode: canonicalize ?lang= values that carry a RENAMED slug.
		if ( $this->settings->get_url_mode() === 'query' ) {
			add_action( 'template_redirect', [ $this, 'maybe_redirect_renamed_query_slug' ], 2 );
		}

		// Invalidate data-derived static caches when languages mutate
		// mid-request (programmatic add / rename / delete / default
		// change via WP-CLI, REST, or admin AJAX). Without this, URL
		// routing after the mutation keeps using the stale slug map.
		$reset_data_caches = [ self::class, 'reset_data_caches' ];

		add_action( 'perflocale/language/added', $reset_data_caches );
		add_action( 'perflocale/language/updated', $reset_data_caches );
		add_action( 'perflocale/language/slug_renamed', $reset_data_caches );
		add_action( 'perflocale/language/deleted', $reset_data_caches );

		// Reset static state on blog switch (multisite) to prevent cross-site pollution.
		// Only reset when the blog actually changes: many plugins (WooCommerce,
		// AIOWPSecurity, etc.) call switch_to_blog() with the SAME blog ID to scope
		// internal lookups, which still fires switch_blog. Resetting on a same-blog
		// transition wipes the detected language and breaks the switcher for the
		// rest of the request. Gated behind is_multisite() — switch_to_blog is
		// a no-op on single-site, so the handler has nothing to do there.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'maybe_reset_on_switch' ], 10, 2 );
		}
	}

	/**
	 * 301-redirect slug-form language URLs (`/en/`) to their locale form
	 * (`/en-us/`) when `url_prefix_type = locale` is active.
	 *
	 * Guards:
	 * - Frontend only; admin, REST, XML-RPC, WP-CLI, cron untouched
	 * - GET/HEAD only - never 301 a POST and lose the body
	 * - First path segment must be a known language slug AND differ from
	 * that language's locale prefix (otherwise there's nothing to fix)
	 *
	 * @return void
	 */
	public function maybe_canonicalise_prefix_form(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		// Cheap path-shape check first — bail on the homepage case (path
		// trims to '') BEFORE doing any DB / option lookups. Locale-prefix
		// mode fires this handler on every frontend request; skipping the
		// slug-map and home-option fetch on no-prefix paths recovers
		// ~0.5–1 ms p50 on homepage hits. The full home-path stripping
		// below only runs when there's a real first segment to canonicalise.
		// esc_url_raw (not sanitize_text_field) preserves percent-encoded
		// bytes — sanitize_text_field strips %XX, corrupting non-ASCII
		// slugs like /de/%C3%BCber-uns/ before the redirect target is
		// rebuilt below. Same fix applied to SlugRedirector::maybe_redirect().
		$request_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$qs_pos      = strpos( $request_uri, '?' );
		$path        = $qs_pos !== false ? substr( $request_uri, 0, $qs_pos ) : $request_uri;
		$query       = $qs_pos !== false ? substr( $request_uri, $qs_pos ) : '';

		if ( trim( $path, '/' ) === '' ) {
			return;
		}

		$slug_map = $this->get_language_slug_map();

		if ( empty( $slug_map ) ) {
			return;
		}

		// Strip any WP-in-subfolder home path so we compare against the
		// site-relative segment only. Same raw-option trick as
		// maybe_redirect_default_to_prefix - bypass our own home_url
		// filter, which would otherwise prepend a language prefix.
		$home_path = (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ?: '/' );
		$home_path = '/' . trim( $home_path, '/' );

		if ( $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
			$relative = substr( $path, strlen( $home_path ) );

			if ( $relative === '' || $relative[0] !== '/' ) {
				$relative = '/' . $relative;
			}
		} else {
			$relative = $path;
		}

		$trimmed = trim( $relative, '/' );

		if ( $trimmed === '' ) {
			return;
		}

		$slash     = strpos( $trimmed, '/' );
		$first_seg = $slash !== false ? substr( $trimmed, 0, $slash ) : $trimmed;
		$rest      = $slash !== false ? substr( $trimmed, $slash ) : '';

		// First segment must be a known language slug, AND that language's
		// configured prefix must be different (i.e. locale form). When the
		// slug and the locale prefix match (e.g. "fr" → locale "fr_FR" →
		// "fr"), there's nothing to canonicalise.
		if ( ! isset( $slug_map[ $first_seg ] ) ) {
			return;
		}

		$lang    = $slug_map[ $first_seg ];
		$correct = $this->settings->get_url_prefix( $lang );

		if ( $correct === '' || $correct === $first_seg ) {
			return;
		}

		$home_base = rtrim( (string) get_option( 'home' ), '/' );
		$target    = $home_base . '/' . $correct . $rest . $query;

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Remove the `perflocale_redirected` sentinel via a 301 so the user
	 * ends up on a clean URL after a language redirect.
	 *
	 * Only fires once a language cookie is present (meaning the previous
	 * redirect step succeeded). If cookies are blocked the sentinel stays
	 * in the URL - that's the fuse working as designed.
	 *
	 * @return void
	 */
	public function clean_redirect_sentinel(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['perflocale_redirected'] ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return;
		}

		// Only strip when a cookie is present. Without this, a user whose
		// browser drops cookies would bounce between clean/sentinel URLs.
		if ( empty( $_COOKIE['perflocale_lang'] ) ) {
			return;
		}

		$clean = remove_query_arg( 'perflocale_redirected' );

		if ( $clean === '' ) {
			return;
		}

		wp_safe_redirect( $clean, 301 );
		exit;
	}

	/**
	 * 301-redirect bare URLs to the prefixed form when the admin chose not
	 * to hide the default-language prefix.
	 *
	 * Only registered when `hide_default_prefix = false` AND URL mode is
	 * subdirectory. When the user explicitly wants the default language's
	 * URLs to carry its prefix, visiting `/` without a prefix must not
	 * render duplicate content - canonicalise to `/{default_slug}/`.
	 *
	 * Bails silently in every case that could break form submissions,
	 * REST/XML-RPC/CLI traffic, admin pages, excluded paths, or already-
	 * prefixed requests. A filter (`perflocale/redirect_default_to_prefix`)
	 * lets site owners opt out without toggling the underlying setting.
	 *
	 * @return void
	 */
	public function maybe_redirect_default_to_prefix(): void {
		// Frontend only. admin-ajax, admin pages, cron, REST, XML-RPC,
		// and WP-CLI are all excluded paths for the redirect.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Never redirect requests that carry a body - 301 would drop
		// POST/PUT/DELETE payloads.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		/**
		 * Programmatic opt-out. Return false to let both `/` and
		 * `/{default_slug}/` resolve - useful for sites that
		 * deliberately serve the same content at both URLs.
		 *
		 * @hook perflocale/redirect_default_to_prefix
		 * @param bool $enabled Default true.
		 */
		if ( ! (bool) apply_filters( 'perflocale/redirect_default_to_prefix', true ) ) {
			return;
		}

		$slug_map = $this->get_language_slug_map();

		if ( empty( $slug_map ) ) {
			return;
		}

		$this->load_default_language();

		if ( self::$default_language === null ) {
			return;
		}

		// Only redirect when the resolved language is the default language.
		// If cookie / browser / edge-hint chose a different language for a
		// no-prefix URL, `maybe_redirect_browser()` (when enabled) handles
		// that. Don't step on its toes.
		if ( ! $this->is_default_language() ) {
			return;
		}

		// Split the request URI into path + query so we preserve the query
		// string verbatim while we only mutate the path. esc_url_raw (not
		// sanitize_text_field) preserves percent-encoded bytes — see the
		// maybe_canonicalise_prefix_form() rationale above.
		$request_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$qs_pos      = strpos( $request_uri, '?' );
		$path        = $qs_pos !== false ? substr( $request_uri, 0, $qs_pos ) : $request_uri;
		$query       = $qs_pos !== false ? substr( $request_uri, $qs_pos ) : '';

		if ( $path === '' ) {
			$path = '/';
		}

		// Strip any home-path prefix (WP installed in a subfolder like /blog)
		// so prefix detection compares the site-relative path only. Use the
		// raw `home` option, NOT our home_url filter — the filter prepends the
		// default-language prefix (when hide_default_prefix=false), which we'd
		// mistake for a sub-install and strip, loop-redirecting /fr/ to itself.
		$home_path = (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ?: '/' );
		$home_path = '/' . trim( $home_path, '/' );

		if ( $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
			$relative = substr( $path, strlen( $home_path ) );

			if ( $relative === '' || $relative[0] !== '/' ) {
				$relative = '/' . $relative;
			}
		} else {
			$relative = $path;
		}

		// Extract the first path segment to check against active-language
		// prefixes. `/en/contact/` → `en`, `/` → ``, `/contact/` → `contact`.
		$trimmed = trim( $relative, '/' );

		if ( $trimmed !== '' ) {
			$slash     = strpos( $trimmed, '/' );
			$first_seg = $slash !== false ? substr( $trimmed, 0, $slash ) : $trimmed;

			foreach ( $slug_map as $slug => $lang ) {
				if ( $first_seg === $slug ) {
					return; // Already carries a language slug prefix.
				}

				$locale_prefix = strtolower( str_replace( '_', '-', (string) ( $lang->locale ?? '' ) ) );

				if ( $locale_prefix !== '' && $first_seg === $locale_prefix ) {
					return; // Already carries a locale-style prefix.
				}
			}
		}

		// Excluded paths (wp-admin, wp-json, wp-login.php, custom). These
		// also already get skipped in the admin/REST guards above, but
		// custom excluded paths (e.g., `/api/`, `/feed/`) need this check.
		// Shared with UrlConverter. This used to be a bare str_starts_with, which
		// meant an excluded `/api` also excluded `/apifoo`, and which compared an
		// encoded request path against a decoded stored needle so no non-Latin
		// excluded path ever matched.
		if ( Helper::path_matches_excluded( $relative, (array) $this->settings->get_excluded_paths() ) ) {
			return;
		}

		// Build the prefixed target. `get_url_prefix()` returns the slug or
		// locale-style prefix per the site's `url_prefix_type` setting.
		$prefix = $this->settings->get_url_prefix( self::$default_language );

		if ( $prefix === '' ) {
			return;
		}

		// Compose absolute URL from the raw `home` option so we bypass our
		// own `home_url` filter (which would double-prefix).
		$home_base = rtrim( (string) get_option( 'home' ), '/' );

		if ( $home_base === '' ) {
			return;
		}

		$target = $home_base . '/' . $prefix . $relative . $query;

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * 301-redirect the bare default-prefix home (`/{default_slug}/`, e.g.
	 * `/en/`) to the canonical `/` when `hide_default_prefix=true`.
	 *
	 * The mirror of maybe_redirect_default_to_prefix(). With the default
	 * prefix hidden, `/` is canonical but `/{default_slug}/` still resolves to
	 * the same homepage - a duplicate-content URL WordPress core only strips
	 * for a static front page, not a latest-posts one. Home-only: a
	 * `/{default_slug}/foo/` inner URL is left alone (its per-page canonical
	 * tag already de-duplicates it, and stripping the prefix here could
	 * mis-serve a slug whose real content lives in another language).
	 *
	 * @return void
	 */
	public function maybe_redirect_prefix_to_default(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// GET/HEAD only - never 301 a request that carries a body.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		/**
		 * Programmatic opt-out. Return false to let both `/` and the bare
		 * `/{default_slug}/` home resolve.
		 *
		 * @hook perflocale/redirect_prefix_to_default
		 * @param bool $enabled Default true.
		 */
		if ( ! (bool) apply_filters( 'perflocale/redirect_prefix_to_default', true ) ) {
			return;
		}

		$this->load_default_language();

		if ( self::$default_language === null ) {
			return;
		}

		$default_slug = (string) ( self::$default_language->slug ?? '' );

		if ( $default_slug === '' ) {
			return;
		}

		// esc_url_raw (not sanitize_text_field) preserves percent-encoded
		// bytes - same rationale as maybe_redirect_default_to_prefix().
		$request_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$qs_pos      = strpos( $request_uri, '?' );
		$path        = $qs_pos !== false ? substr( $request_uri, 0, $qs_pos ) : $request_uri;
		$query       = $qs_pos !== false ? substr( $request_uri, $qs_pos ) : '';

		if ( $path === '' ) {
			$path = '/';
		}

		// Strip any WP-in-subfolder home path so we compare the site-relative
		// segment only. Raw `home` option, NOT home_url() (our filter).
		$home_path = (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ?: '/' );
		$home_path = '/' . trim( $home_path, '/' );

		if ( $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
			$relative = substr( $path, strlen( $home_path ) );

			if ( $relative === '' || $relative[0] !== '/' ) {
				$relative = '/' . $relative;
			}
		} else {
			$relative = $path;
		}

		// Home-only: the site-relative path must be EXACTLY the default
		// language's prefixed home (no further segment). Match both the slug
		// form (`/en/`) and — in `url_prefix_type=locale` mode — the locale
		// form (`/en-us/`), which is the CANONICAL prefixed URL the rewrite
		// rules serve and the one crawlers discover; without it that twin
		// stays an un-redirected duplicate of `/`. `/en/contact/` does not
		// match. get_url_prefix() equals the slug in slug mode, so this adds
		// no new redirect there.
		$default_prefix = $this->settings->get_url_prefix( self::$default_language );
		$trimmed        = trim( $relative, '/' );

		if ( $trimmed !== $default_slug && ( $default_prefix === '' || $trimmed !== $default_prefix ) ) {
			return;
		}

		$home_base = rtrim( (string) get_option( 'home' ), '/' );
		$target    = ( $home_base === '' ? '' : $home_base ) . '/' . $query;

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Detect the current language from the request.
	 *
	 * Always runs URL detection to strip the language prefix from
	 * $wp->request (so WordPress resolves the correct page). If the
	 * language was already resolved by detect_locale_early(), skips
	 * the cookie/browser fallbacks and just finalises (cookie + action).
	 *
	 * @param \WP $wp WordPress environment instance.
	 * @return void
	 */
	public function detect_language( \WP $wp ): void {
		// Prevent double execution (e.g., hook re-entry).
		if ( self::$detection_finalized ) {
			return;
		}

		// Skip processing for static asset requests (favicon, images, etc.)
		// to avoid unnecessary DB queries on non-HTML responses.
		$request = $wp->request ?? '';

		$ext = strtolower( pathinfo( $request, PATHINFO_EXTENSION ) );

		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf -- Fast path for static assets.
		if ( $request !== '' && isset( self::STATIC_EXTENSIONS[ $ext ] ) ) {
			self::$detection_finalized = true;
			return;
		}

		self::$detection_finalized = true;

		$slug_map = $this->get_language_slug_map();

		if ( empty( $slug_map ) ) {
			return;
		}

		$this->load_default_language();

		$already_detected = self::$current_language !== null;

		// Always run URL detection: it strips the language prefix from
		// $wp->request so WordPress resolves the correct page/post.
		$url_slug         = $this->detect_from_url( $wp, $slug_map );
		$detected_slug    = $url_slug;
		$detection_method = $url_slug !== null ? 'url' : 'default';

		// If language was already resolved by detect_locale_early(),
		// just set the cookie and fire the action.
		if ( $already_detected ) {
			$this->set_language_cookie( self::$current_language->slug );

			/** @hook perflocale/language/detected Fires after the current language is detected. */
			do_action( 'perflocale/language/detected', self::$current_language->slug, $detection_method );

			return;
		}

		// URL didn't match - try other detection methods.
		if ( $detected_slug === null ) {
			$detection_order = $this->settings->get_detection_order();

			foreach ( $detection_order as $method ) {
				switch ( $method ) {
					case 'url':
						// Already handled above.
						break;

					case 'cookie':
						$detected_slug = $this->detect_from_cookie( $slug_map );
						if ( $detected_slug !== null ) {
							$detection_method = 'cookie';
						}
						break;

					case 'browser':
						$detected_slug = $this->detect_from_browser( $slug_map );
						if ( $detected_slug !== null ) {
							$detection_method = 'browser';
						}
						break;

					case 'edge_hint':
						$detected_slug = $this->detect_from_edge_hint( $slug_map );
						if ( $detected_slug !== null ) {
							$detection_method = 'edge_hint';
						}
						break;

					case 'default':
						break;
				}

				if ( $detected_slug !== null ) {
					break;
				}
			}
		}

		// Final fallback: default language.
		if ( $detected_slug === null && self::$default_language !== null ) {
			$detected_slug    = self::$default_language->slug;
			$detection_method = 'default';
		}

		if ( $detected_slug !== null && isset( $slug_map[ $detected_slug ] ) ) {
			self::$current_language = $slug_map[ $detected_slug ];

			// A language resolved from a VISITOR-specific source (cookie /
			// Accept-Language / edge hint) varies the response body at a
			// shared URL — exactly what a URL-keyed full-page cache must
			// never store: one visitor's language would be cached under the
			// bare URL and served to everyone (the readme's "caches separate
			// by URL" guarantee only holds for URL-derived languages). Mark
			// these responses uncacheable; URL-detected requests — the
			// overwhelming majority — stay fully cacheable.
			if (
				in_array( $detection_method, [ 'cookie', 'browser', 'edge_hint' ], true )
				&& ! is_admin()
				&& ! headers_sent()
			) {
				nocache_headers();
			}

			$this->set_language_cookie( $detected_slug );

			/** @hook perflocale/language/detected Fires after the current language is detected. */
			do_action( 'perflocale/language/detected', $detected_slug, $detection_method );
		}
	}

	/**
	 * Whether the current request path matches a configured excluded path.
	 *
	 * Same site-relative derivation + raw prefix loop as the
	 * default-to-prefix canonical redirect, so the excluded-paths setting
	 * means the same thing in every flow that consults it. URL-derived
	 * (no cookies/headers), so callers may bail on it BEFORE
	 * nocache_headers() and keep excluded pages fully cacheable.
	 *
	 * @return bool
	 */
	public function is_excluded_request_path(): bool {
		$excluded_paths = (array) $this->settings->get_excluded_paths();

		if ( $excluded_paths === [] ) {
			return false;
		}

		$request_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$qs_pos      = strpos( $request_uri, '?' );
		$path        = $qs_pos !== false ? substr( $request_uri, 0, $qs_pos ) : $request_uri;

		if ( $path === '' ) {
			$path = '/';
		}

		// Site-relative: strip a subfolder-install home path via the raw
		// `home` option (not our filtered home_url — see the canonical
		// redirect's rationale).
		$home_path = (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ?: '/' );
		$home_path = '/' . trim( $home_path, '/' );

		if ( $home_path !== '/' && str_starts_with( $path, $home_path ) ) {
			$relative = substr( $path, strlen( $home_path ) );

			if ( $relative === '' || $relative[0] !== '/' ) {
				$relative = '/' . $relative;
			}
		} else {
			$relative = $path;
		}

		foreach ( $excluded_paths as $excluded ) {
			$excluded = (string) $excluded;

			if ( $excluded !== '' && str_starts_with( $relative, $excluded ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redirect first-time visitors to their browser-preferred language.
	 *
	 * Runs on template_redirect after detect_language() has already resolved
	 * the current language from the URL. Only triggers when:
	 * - Browser redirect setting is enabled
	 * - No language cookie exists (first visit)
	 * - Current page is the default language (no explicit prefix)
	 * - Browser prefers a different active language
	 * - Visitor is not a known bot/crawler
	 *
	 * @return void
	 */
	public function maybe_redirect_browser(): void {
		// Only on frontend page requests.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return;
		}

		// Body-preserving methods only - 302 drops POST/PUT/DELETE/PATCH payloads.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		// Feeds, robots.txt, favicon and trackbacks reach template_redirect
		// before core handles them, but a per-visitor language 302 must never
		// hijack them: cookieless feed pollers would silently flip a subscribed
		// English feed to another language on every poll. URL-derived, so it
		// stays ABOVE nocache_headers() below to keep those responses cacheable.
		if ( is_feed() || is_trackback() || is_robots() || is_favicon() ) {
			return;
		}

		if ( ! $this->settings->get( 'redirect_browser_lang' ) ) {
			return;
		}

		// Pages the admin excluded from language routing must not be
		// auto-redirected either (single-language campaign landing pages).
		// URL-derived — stays above nocache_headers() so excluded pages
		// keep full-page cacheability.
		if ( $this->is_excluded_request_path() ) {
			return;
		}

		// Whether this response is a 302 or a 200 depends on the INDIVIDUAL
		// visitor from here on (cookie, consent, bot UA, Accept-Language).
		// A full-page cache storing any of those 200s under the entry URL
		// starves the redirect for every later first-time visitor — so the
		// entry response must be uncacheable while the feature is on. Must
		// run BEFORE the visitor-specific declines; only URL-derived
		// conditions above it (mirrors GeoRedirect::maybe_redirect()).
		if ( $this->is_default_language() && ! headers_sent() ) {
			nocache_headers();
		}

		/**
		 * Consent gate - consent-management plugins can hook this to
		 * return false while the visitor hasn’t accepted non-strictly-
		 * necessary cookies, suppressing the automatic browser-language
		 * redirect until consent is granted.
		 *
		 * @hook perflocale/privacy/consent_given
		 * @param bool $granted Default true.
		 */
		if ( ! (bool) apply_filters( 'perflocale/privacy/consent_given', true ) ) {
			return;
		}

		// Only first-time visitors (no cookie yet).
		if ( isset( $_COOKIE['perflocale_lang'] ) ) {
			return;
		}

		// Redirect fuse: once we've redirected within this navigation step,
		// the URL carries `?perflocale_redirected=1`. Never redirect again
		// even if cookies are disabled - prevents infinite loops under
		// privacy browsers that silently drop our cookie.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_redirected'] ) ) {
			return;
		}

		// Only when URL resolved to the default language.
		if ( ! $this->is_default_language() ) {
			return;
		}

		// Skip bots / crawlers - they should see the default content.
		// Detection logic + pattern lives in self::is_bot_ua() — single
		// source of truth across browser/edge/geo redirect paths.
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		if ( self::is_bot_ua( $ua ) ) {
			return;
		}

		// Check what the browser prefers.
		$slug_map     = $this->get_language_slug_map();
		$browser_slug = $this->detect_from_browser( $slug_map );

		$default_slug = self::$default_language ? self::$default_language->slug : '';

		if ( $browser_slug === null || $browser_slug === $default_slug ) {
			// Browser prefers the default language or no match - set cookie and stay.
			if ( $default_slug !== '' ) {
				$this->set_language_cookie( $default_slug );
			}
			return;
		}

		// Build the redirect URL for the detected language.
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return;
		}

		/** @var UrlConverter $converter */
		$converter = $plugin->get( 'url_converter' );

		// Home-path-aware: a bare home_url( add_query_arg( [] ) ) doubles the
		// prefix of a subfolder install. See current_request_url().
		$current_url  = self::current_request_url();
		$redirect_url = $converter->convert( $current_url, $browser_slug );

		if ( $redirect_url === '' || $redirect_url === $current_url ) {
			return;
		}

		// Set cookie before redirect so the visitor is not redirected again.
		$this->set_language_cookie( $browser_slug );

		// Append a sentinel so even cookie-blocked browsers don't get redirected
		// a second time on their subsequent request. The target page can strip
		// it from the canonical URL if desired.
		$redirect_url = add_query_arg( 'perflocale_redirected', '1', $redirect_url );

		// Per-visitor language redirect on a shared URL — mark uncacheable so an
		// edge/server cache that stores 3xx can't pin it onto other visitors.
		// Whitelist the (possibly cross-host) target so wp_safe_redirect() does
		// not fall back to /wp-admin/ in domain/subdomain url-mode.
		$this->allow_redirect_host( $redirect_url );
		nocache_headers();
		wp_safe_redirect( $redirect_url, 302 );
		exit;
	}

	/**
	 * Redirect first-time visitors based on an edge-decided language hint.
	 *
	 * The language has already been chosen by an edge worker (Cloudflare,
	 * Vercel, Netlify) and forwarded to PHP via:
	 *   - the `X-PerfLocale-Lang` request header (filterable), or
	 *   - the `perflocale_edge_lang` cookie (filterable).
	 *
	 * Use case: a CF Worker reads `request.cf.country`, looks up its
	 * country->language map, and sets `X-PerfLocale-Lang: fr` on the request
	 * forwarded to origin. PHP receives the slug already resolved - no API
	 * call, no Accept-Language parsing, no country-map lookup at the WP layer.
	 *
	 * Same gating as `maybe_redirect_browser()`:
	 *   - GET/HEAD only (302 drops bodies)
	 *   - First-time visitors (no language cookie set yet)
	 *   - Loop-fuse via `perflocale_redirected=1` sentinel
	 *   - Bot/crawler exclusion (let SEO crawlers see default content)
	 *   - Only fires when the URL resolved to the default language
	 *   - Consent gate via `perflocale/privacy/consent_given`
	 *
	 * @return void
	 */
	public function maybe_redirect_edge_hint(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return;
		}

		// Body-preserving methods only.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		// Never 302 a feed / robots.txt / favicon / trackback to another
		// language (see maybe_redirect_browser()).
		if ( is_feed() || is_trackback() || is_robots() || is_favicon() ) {
			return;
		}

		// Setting + global edge gate. The detect_from_edge_hint() method also
		// bails when edge_integration_enabled is false, but checking here lets
		// us short-circuit before computing slug map / bot regex.
		if (
			! (bool) $this->settings->get( 'redirect_edge_hint_enabled' )
			|| ! $this->settings->edge_integration_enabled()
		) {
			return;
		}

		// Excluded paths never auto-redirect (see maybe_redirect_browser()).
		if ( $this->is_excluded_request_path() ) {
			return;
		}

		// Reuse the same consent gate as the browser-redirect path - cookie-
		// management plugins often hook this to suppress automatic redirects
		// before the visitor accepts non-strictly-necessary cookies.
		if ( ! (bool) apply_filters( 'perflocale/privacy/consent_given', true ) ) {
			return;
		}

		// Only first-time visitors.
		if ( isset( $_COOKIE['perflocale_lang'] ) ) {
			return;
		}

		// Loop fuse.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_redirected'] ) ) {
			return;
		}

		// Only when URL resolved to default language.
		if ( ! $this->is_default_language() ) {
			return;
		}

		// Skip bots / crawlers - they should see the default content.
		// Shared detection: see self::is_bot_ua().
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		if ( self::is_bot_ua( $ua ) ) {
			return;
		}

		// Read the edge-decided slug. Reuses detect_from_edge_hint() so the
		// header / cookie names + accept-hint filter are identical to the
		// detection-flow consumer - one source of truth.
		$slug_map  = $this->get_language_slug_map();
		$edge_slug = $this->detect_from_edge_hint( $slug_map );

		$default_slug = self::$default_language ? self::$default_language->slug : '';

		if ( $edge_slug === null || $edge_slug === $default_slug ) {
			// Edge has nothing for us, or chose the default - set cookie and stay.
			if ( $default_slug !== '' ) {
				$this->set_language_cookie( $default_slug );
			}
			return;
		}

		// Build the redirect target.
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return;
		}

		/** @var UrlConverter $converter */
		$converter = $plugin->get( 'url_converter' );

		// Home-path-aware: a bare home_url( add_query_arg( [] ) ) doubles the
		// prefix of a subfolder install. See current_request_url().
		$current_url  = self::current_request_url();
		$redirect_url = $converter->convert( $current_url, $edge_slug );

		if ( $redirect_url === '' || $redirect_url === $current_url ) {
			return;
		}

		// Cookie + sentinel before redirect (prevents re-fire on cookie-blocked browsers).
		$this->set_language_cookie( $edge_slug );
		$redirect_url = add_query_arg( 'perflocale_redirected', '1', $redirect_url );

		// Per-visitor language redirect on a shared URL — mark uncacheable so an
		// edge/server cache that stores 3xx can't pin it onto other visitors.
		// Whitelist the (possibly cross-host) target so wp_safe_redirect() does
		// not fall back to /wp-admin/ in domain/subdomain url-mode.
		$this->allow_redirect_host( $redirect_url );
		nocache_headers();
		wp_safe_redirect( $redirect_url, 302 );
		exit;
	}

	/**
	 * Whitelist a redirect target's host for one wp_safe_redirect() call.
	 *
	 * In domain/subdomain url-mode a language redirect targets a different host;
	 * without this, wp_safe_redirect() rejects it and falls back to /wp-admin/.
	 * No-op in subdirectory mode (the target is the same host, already allowed).
	 *
	 * @param string $url Redirect target URL.
	 * @return void
	 */
	private function allow_redirect_host( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || $host === '' ) {
			return;
		}

		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ) use ( $host ): array {
				$hosts[] = $host;

				return $hosts;
			}
		);
	}

	/**
	 * Detect the current language from the URL before init.
	 *
	 * Called during plugin file load (from Bootstrap::init()) so the
	 * WordPress locale filter returns the correct value BEFORE plugins
	 * load their text domains at init. Without this, WooCommerce and
	 * other plugins would always load English strings because their
	 * text domain loading runs before parse_request.
	 *
	 * Only sets self::$current_language - URL rewriting (prefix stripping
	 * from $wp->request) happens later in detect_language().
	 *
	 * @return void
	 */
	public function detect_locale_early(): void {
		if ( self::$current_language !== null ) {
			return;
		}

		// Admin pages (non-AJAX) and CLI use the WordPress default locale
		// until parse_request sets the language from query vars or cookies.
		if ( ( is_admin() && ! wp_doing_ajax() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Skip if the plugin's database tables don't exist in this context.
		// This prevents "Table doesn't exist" errors when running in
		// environments with a different table prefix (e.g., the WordPress
		// Plugin Checker sandbox) or before the install routine completes.
		if ( ! \PerfLocale\Database\Schema::tables_exist() ) {
			return;
		}

		$slug_map = $this->get_language_slug_map();

		if ( empty( $slug_map ) ) {
			return;
		}

		$this->load_default_language();

		$url_mode = $this->settings->get_url_mode();
		$slug     = null;

		if ( $url_mode === 'subdomain' ) {
			$slug = $this->detect_from_subdomain( $slug_map );
		} elseif ( $url_mode === 'domain' ) {
			$slug = $this->detect_from_domain( $slug_map );
		} elseif ( $url_mode === 'query' ) {
			$slug = $this->detect_from_query_param( $slug_map );
		} else {
			// Path mode: parse REQUEST_URI directly since $wp is not available yet.
			$slug = $this->detect_slug_from_request_uri( $slug_map );
		}

		// WooCommerce Store API (block cart/checkout): the client posts to
		// the UNPREFIXED /wp-json/wc/store/... base regardless of the page
		// language, so falling back to the default served German shoppers
		// English coupon/stock/checkout errors and cart fragments. The
		// visitor's validated language cookie is the right signal here —
		// Store API responses are per-cart and uncacheable, so cookie
		// variance is safe.
		//
		// NOT on a path-prefixed blog in subdirectory mode. This branch assumes
		// the Store API base sits at the site root (`/wp-json/wc/store/...`).
		// On a SUBDIRECTORY multisite child the site root already carries a path
		// segment (`/sub/wp-json/wc/store/...`), and resolving a non-default
		// language from the cookie there left the request inconsistent with
		// subdirectory-mode path expectations: every Store API call returned an
		// HTML 404 instead of JSON. Verified on mutest-subdir blog 2 — no cookie
		// and an `en` (default) cookie both returned 200, `de`/`es`/`fr` all
		// returned 404, the same route with the language IN the path returned
		// 200, and a non-Store REST route with the same cookie returned 200.
		//
		// Skipping the branch there means such a shopper gets Store API strings
		// in the DEFAULT language rather than a broken endpoint. That is a
		// deliberate trade: an English error message is a nuisance, a 404 cart
		// is a broken checkout. Single-site, subdomain and per-domain shapes, and
		// the network's own root blog, are untouched and keep the localisation.
		$blog_path = '/';

		if ( is_multisite() && isset( $GLOBALS['current_blog']->path ) ) {
			$blog_path = (string) $GLOBALS['current_blog']->path;
		}

		$store_api_prefix_safe = ( $url_mode !== 'subdirectory' || '/' === $blog_path );

		if ( $slug === null && $store_api_prefix_safe && isset( $_COOKIE['perflocale_lang'] ) ) {
			$request_path = (string) wp_parse_url(
				esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ),
				PHP_URL_PATH
			);

			if ( str_contains( $request_path, '/wc/store/' ) ) {
				$cookie_slug = sanitize_key( wp_unslash( (string) $_COOKIE['perflocale_lang'] ) );

				if ( isset( $slug_map[ $cookie_slug ] ) ) {
					$slug = $cookie_slug;
				}
			}
		}

		// Fallback to default when no prefix was found. In subdomain/domain
		// modes the bare host always maps to the default; in subdirectory mode
		// an un-prefixed path only means the default when the default prefix is
		// hidden (otherwise it should resolve normally, not be forced here).
		// NB: get_url_mode() returns 'subdirectory', never 'path' — comparing
		// against 'path' made this branch always-true and forced the default in
		// subdirectory mode even with a visible default prefix.
		if ( $slug === null && self::$default_language !== null ) {
			if ( $url_mode !== 'subdirectory' || $this->settings->hide_default_prefix() ) {
				$slug = self::$default_language->slug;
			}
		}

		if ( $slug !== null && isset( $slug_map[ $slug ] ) ) {
			self::$current_language = $slug_map[ $slug ];
		}
	}

	/**
	 * Detect language slug from REQUEST_URI path prefix.
	 *
	 * Lightweight URL parsing for early detection before WordPress's
	 * parse_request fires. Does NOT modify the request - only reads it.
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	private function detect_slug_from_request_uri( array $slug_map ): ?string {
		// esc_url_raw preserves percent-encoded bytes — wp_parse_url() needs
		// the original form to extract a structurally-correct path component.
		$request_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) );
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return null;
		}

		// Strip the WordPress installation subdirectory if present.
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );

		if ( is_string( $home_path ) && $home_path !== '' && $home_path !== '/' ) {
			if ( str_starts_with( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}
		}

		$path = ltrim( $path, '/' );

		if ( $path === '' ) {
			return null;
		}

		// Extract the first path segment.
		$slash_pos = strpos( $path, '/' );
		$first_seg = $slash_pos !== false ? substr( $path, 0, $slash_pos ) : $path;

		// Direct slug match.
		// REQUEST_URI is percent-encoded; the map keys are not. See
		// detect_from_path() for why only the lookup is decoded.
		if ( ! isset( $slug_map[ $first_seg ] ) && strpos( $first_seg, '%' ) !== false ) {
			$decoded_first = rawurldecode( $first_seg );

			if ( isset( $slug_map[ $decoded_first ] ) ) {
				$first_seg = $decoded_first;
			}
		}

		if ( isset( $slug_map[ $first_seg ] ) ) {
			return $first_seg;
		}

		// Locale-based prefix match (e.g., 'fr-fr' for slug 'fr').
		foreach ( $slug_map as $slug => $lang ) {
			$locale_prefix = strtolower( str_replace( '_', '-', $lang->locale ) );

			if ( $first_seg === $locale_prefix || rawurldecode( $first_seg ) === $locale_prefix ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Detect language from the URL prefix.
	 *
	 * Extracts the first path segment and checks if it's a valid language slug.
	 * If found, strips the prefix from $wp->request so WordPress resolves
	 * the correct content.
	 *
	 * @param \WP                   $wp WordPress environment.
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	private function detect_from_url( \WP $wp, array $slug_map ): ?string {
		$url_mode = $this->settings->get_url_mode();

		if ( $url_mode === 'subdomain' ) {
			return $this->detect_from_subdomain( $slug_map );
		}

		if ( $url_mode === 'domain' ) {
			return $this->detect_from_domain( $slug_map );
		}

		if ( $url_mode === 'query' ) {
			return $this->detect_from_query_param( $slug_map );
		}

		return $this->detect_from_path( $wp, $slug_map );
	}

	/**
	 * Build the URL-prefix → language-slug lookup used by every URL mode.
	 *
	 * A language is addressable by TWO forms: its own slug (`en`) and the
	 * locale form of its locale (`en-us` for `en_US`). Which one the plugin
	 * WRITES depends on the URL Prefix Format setting; which ones it READS is
	 * deliberately both, so flipping that setting — or an old bookmark, or a
	 * link someone already published — never 404s or silently serves the
	 * wrong language.
	 *
	 * TWO passes, and the order matters. Every language's own slug is claimed
	 * first; locale-form aliases only fill keys still free afterwards.
	 *
	 * A single pass let a LATER language's alias overwrite an EARLIER
	 * language's real slug: give one language the slug `de-de` and another
	 * the locale `de_DE`, and `/de-de/` silently served the second one — the
	 * first became unreachable by its own slug, and reversing the row order
	 * hid the problem. A slug is a real, UNIQUE-constrained identifier; an
	 * alias is a convenience. The identifier must always win.
	 *
	 * Built once per request behind a static that reset() drops on
	 * switch_blog, so the cost is O(languages) once, not per URL.
	 *
	 * @param array<string, object> $slug_map Active language slug map.
	 * @return array<string, string> Prefix (slug or locale form) → language slug.
	 */
	private function prefix_map( array $slug_map ): array {
		if ( self::$path_prefix_map !== null ) {
			return self::$path_prefix_map;
		}

		self::$path_prefix_map = [];

		foreach ( $slug_map as $slug => $lang ) {
			self::$path_prefix_map[ $slug ] = $slug;
		}

		foreach ( $slug_map as $slug => $lang ) {
			$locale_prefix = strtolower( str_replace( '_', '-', $lang->locale ) );

			if ( $locale_prefix !== $slug && ! isset( self::$path_prefix_map[ $locale_prefix ] ) ) {
				self::$path_prefix_map[ $locale_prefix ] = $slug;
			}
		}

		return self::$path_prefix_map;
	}

	/**
	 * Detect language from the `lang` query parameter (query URL mode).
	 *
	 * The raw value is only ever matched against the active-language prefix
	 * map — an unknown or malformed value means "no language in the URL"
	 * and the default-language fallback applies. The user-supplied string
	 * never propagates further: every URL the plugin builds afterwards
	 * uses the slug stored in the languages table.
	 *
	 * The parameter name comes from UrlConverter::query_var(), the same
	 * source the writer uses, so reader and writer cannot drift apart.
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	/**
	 * Read the language query parameter without destroying a legal prefix.
	 *
	 * sanitize_key() lowercases and strips everything outside `[a-z0-9_-]`,
	 * which is fine for the ordinary `?lang=de` but silently mangles a prefix
	 * the plugin itself wrote. Settings::get_url_prefix() is a byte operation,
	 * so a locale carrying `@` or non-ASCII survives into the URL: `sr_RS@latin`
	 * is written as `?lang=sr-rs@latin` and read back as `sr-rslatin`, which
	 * matches nothing, so every non-default page on such a site served the
	 * default language.
	 *
	 * Fixing the WRITER was the obvious move and is wrong: it would change every
	 * live URL on sites that work today. So the reader accepts the raw value
	 * when, and only when, it is a key the plugin itself minted, and otherwise
	 * falls back to the sanitised form exactly as before. The raw string is used
	 * solely as an array key for that lookup and never reaches SQL or output.
	 *
	 * @param string              $raw        Unslashed query value.
	 * @param array<string, mixed> $prefix_map Known prefixes.
	 * @return string Either the raw value or its sanitised form.
	 */
	private function resolve_prefix_candidate( string $raw, array $prefix_map ): string {
		if ( $raw !== '' && isset( $prefix_map[ $raw ] ) ) {
			return $raw;
		}

		$lowered = strtolower( $raw );

		if ( $lowered !== '' && isset( $prefix_map[ $lowered ] ) ) {
			return $lowered;
		}

		return sanitize_key( $raw );
	}

	private function detect_from_query_param( array $slug_map ): ?string {
		$query_var = UrlConverter::query_var();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language routing; no state change.
		if ( ! isset( $_GET[ $query_var ] ) || ! is_string( $_GET[ $query_var ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only language routing, no state change. Deliberately unsanitised: resolve_prefix_candidate() uses the raw string ONLY as an array key against the plugin's own prefix map and falls back to sanitize_key() for anything not found there, so nothing unsanitised reaches SQL, output or state. sanitize_key() here would strip the '@' and non-ASCII bytes out of a prefix the plugin itself wrote.
		$raw_slug   = (string) wp_unslash( $_GET[ $query_var ] );
		$prefix_map = $this->prefix_map( $slug_map );
		$slug       = $this->resolve_prefix_candidate( $raw_slug, $prefix_map );

		if ( $slug === '' ) {
			return null;
		}

		// Accept the slug AND the locale form, exactly like path mode does.
		// The URL Prefix Format setting decides which one gets WRITTEN; both
		// are always read. So `?lang=en` keeps resolving on a site later
		// switched to full-locale prefixes, and `?lang=en-us` resolves on a
		// slug-prefix site — no bookmark, backlink or indexed URL ever dies
		// because someone changed a setting.
		if ( isset( $prefix_map[ $slug ] ) ) {
			return $prefix_map[ $slug ];
		}

		// A renamed slug (e.g. `en` after the admin renamed it to `en-us`):
		// resolve via the redirect map so bookmarked/indexed old URLs keep
		// serving the right language. maybe_redirect_renamed_query_slug()
		// additionally 301s the URL itself to the new slug on GET/HEAD.
		$redirects = \PerfLocale\Database\Repository\LanguageRepository::get_slug_redirects();

		if ( isset( $redirects[ $slug ] ) ) {
			$resolved = (string) $redirects[ $slug ];

			if ( $resolved !== '' && isset( $slug_map[ $resolved ] ) ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * 301 query-mode URLs whose language parameter is not in canonical form.
	 *
	 * Covers both non-canonical cases:
	 * - a RENAMED slug (`?lang=en` after the admin renamed `en` to `en-us`)
	 * - the wrong PREFIX FORM for the current URL Prefix Format setting
	 *   (`?lang=en` on a site set to full locale, and the reverse)
	 *
	 * Detection resolves all of these on its own, so content is already
	 * correct without this handler. The redirect exists so each language
	 * keeps exactly ONE indexable query URL instead of several 200s.
	 *
	 * GET/HEAD only, frontend only, and only registered in query URL mode.
	 * The default language is untouched: it carries no parameter at all.
	 *
	 * @return void
	 */
	public function maybe_redirect_renamed_query_slug(): void {
		$query_var = UrlConverter::query_var();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only canonicalization.
		if ( is_admin() || ! isset( $_GET[ $query_var ] ) || ! is_string( $_GET[ $query_var ] ) ) {
			return;
		}

		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only canonicalisation. Same contract as detect_from_query_param(): the raw string is only ever an array key into the plugin's own prefix map, with sanitize_key() as the fallback.
		$raw_prefix = (string) wp_unslash( $_GET[ $query_var ] );

		$slug_map = $this->get_language_slug_map();

		if ( empty( $slug_map ) ) {
			return;
		}

		// Resolve whatever form the visitor arrived with: own slug, locale
		// form, or a slug the admin has since renamed. Same reader tolerance as
		// detect_from_query_param(), or this canonicaliser would 301 a legal
		// prefix it could not parse.
		$prefix_map = $this->prefix_map( $slug_map );
		$prefix     = $this->resolve_prefix_candidate( $raw_prefix, $prefix_map );

		if ( $prefix === '' ) {
			return;
		}
		$resolved   = $prefix_map[ $prefix ] ?? '';

		if ( $resolved === '' ) {
			$redirects = \PerfLocale\Database\Repository\LanguageRepository::get_slug_redirects();
			$resolved  = (string) ( $redirects[ $prefix ] ?? '' );

			if ( $resolved === '' || ! isset( $slug_map[ $resolved ] ) ) {
				return; // Unknown value — leave the URL alone.
			}
		}

		// Canonical form under the CURRENT URL Prefix Format setting. This is
		// the same value UrlConverter writes, so one language keeps exactly
		// one indexable query URL: reading both forms would otherwise leave
		// `?lang=en` and `?lang=en-us` both answering 200 — duplicate content,
		// which is the thing this plugin exists to prevent. Mirrors what
		// maybe_canonicalise_prefix_form() does for subdirectory mode.
		$canonical = $this->settings->get_url_prefix( $slug_map[ $resolved ] );

		if ( $canonical === $prefix ) {
			return; // Already canonical — no redirect.
		}

		// Never 301 to a prefix that resolves to a DIFFERENT language. Two
		// languages can want the same canonical form when one's slug equals
		// the other's locale form (slug `de-de` alongside locale `de_DE`).
		// Sending a visitor there would serve the wrong language under a
		// permanent, cacheable redirect. Leaving the URL alone always serves
		// the right content, so that is the safe direction to fail.
		if ( ( $prefix_map[ $canonical ] ?? '' ) !== $resolved ) {
			return;
		}

		$request_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$target      = add_query_arg( $query_var, rawurlencode( $canonical ), remove_query_arg( $query_var, $request_uri ) );

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Detect language from subdirectory URL prefix (e.g., example.com/en/page).
	 *
	 * @param \WP                   $wp WordPress environment.
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	private function detect_from_path( \WP $wp, array $slug_map ): ?string {
		$request = $wp->request ?? '';

		if ( $request === '' ) {
			if ( $this->settings->hide_default_prefix() && self::$default_language !== null ) {
				return self::$default_language->slug;
			}
			return null;
		}

		$prefix_map = $this->prefix_map( $slug_map );

		$slash_pos = strpos( $request, '/' );
		$first_seg = $slash_pos !== false ? substr( $request, 0, $slash_pos ) : $request;

		// $wp->request carries the PERCENT-ENCODED path, while prefix_map keys
		// are decoded. For an ASCII prefix the two forms are identical, which is
		// why this went unnoticed; for a locale prefix containing `@` or
		// non-ASCII a browser sends `/%D1%80%D1%83%D1%81-ru/` and the lookup
		// missed, so the plugin rendered the default language on a URL it had
		// emitted itself. Core does not miss, because WP::parse_request() retries
		// every rewrite rule against urldecode().
		//
		// Only the LOOKUP key is decoded. $first_seg stays raw because the string
		// surgery below indexes into $request and $matched_query by its length.
		$seg_key = $first_seg;

		if ( ! isset( $prefix_map[ $seg_key ] ) && strpos( $first_seg, '%' ) !== false ) {
			$decoded_seg = rawurldecode( $first_seg );

			if ( isset( $prefix_map[ $decoded_seg ] ) ) {
				$seg_key = $decoded_seg;
			}
		}

		if ( isset( $prefix_map[ $seg_key ] ) ) {
			$detected_slug = $prefix_map[ $seg_key ];

			$wp->request = $slash_pos !== false ? substr( $request, $slash_pos + 1 ) : '';

			if ( ! empty( $wp->matched_query ) ) {
				// $first_seg is a validated slug-map key (no regex
				// metacharacters), so strip the leading "{$first_seg}" or
				// "{$first_seg}/" prefix with string ops — equivalent to the
				// old anchored preg_replace but ~3x cheaper on this
				// every-request parse_request path.
				$mq = (string) $wp->matched_query;

				if ( str_starts_with( $mq, $first_seg . '/' ) ) {
					$wp->matched_query = substr( $mq, strlen( $first_seg ) + 1 );
				} elseif ( str_starts_with( $mq, $first_seg ) ) {
					$wp->matched_query = substr( $mq, strlen( $first_seg ) );
				}
			}

			// When no rewrite rule matched the language-prefixed URL,
			// WordPress may have set stale query_vars (pagename, name,
			// error) based on the full path including the prefix.
			// Clean those up to match the stripped request.
			if ( empty( $wp->matched_rule ) ) {
				unset( $wp->query_vars['error'] );

				if ( ! empty( $wp->query_vars['pagename'] ) ) {
					$pn     = $wp->query_vars['pagename'];
					$prefix = $first_seg . '/';

					if ( str_starts_with( $pn, $prefix ) ) {
						$wp->query_vars['pagename'] = substr( $pn, strlen( $prefix ) );
					} elseif ( $pn === $first_seg ) {
						unset( $wp->query_vars['pagename'] );
					}
				}

				if ( ! empty( $wp->query_vars['name'] ) && $wp->query_vars['name'] === $first_seg ) {
					unset( $wp->query_vars['name'] );
				}
			}

			return $detected_slug;
		}

		if ( $this->settings->hide_default_prefix() && self::$default_language !== null ) {
			return self::$default_language->slug;
		}

		return null;
	}

	/**
	 * Detect language from subdomain (e.g., en.example.com → 'en').
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	private function detect_from_subdomain( array $slug_map ): ?string {
		// Strip any :port — HTTP_HOST carries it on non-80/443 setups (dev,
		// staging, ported production) but wp_parse_url(home_url()) never does,
		// so an unstripped host would match neither branch and force default.
		$host      = (string) preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$base_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? '';

		// Extract the subdomain prefix: 'en.example.com' → 'en'.
		if ( str_ends_with( $host, '.' . $base_host ) ) {
			$subdomain = substr( $host, 0, -strlen( '.' . $base_host ) );

			if ( isset( $slug_map[ $subdomain ] ) ) {
				return $subdomain;
			}
		}

		// No subdomain or unrecognized - this IS the default language domain.
		if ( $host === $base_host && self::$default_language !== null ) {
			return self::$default_language->slug;
		}

		return null;
	}

	/**
	 * Detect language from per-language domain (e.g., example.fr → 'fr').
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	private function detect_from_domain( array $slug_map ): ?string {
		// Strip any :port so a ported HTTP_HOST still matches the (typically
		// portless) configured domain values — see detect_from_subdomain().
		$host    = (string) preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$domains = $this->settings->get_language_domains();

		// Build a reverse map: domain → slug, port-stripped on both sides so an
		// admin who entered `example.fr:8443` still matches a ported host.
		$domain_map = [];

		foreach ( $domains as $slug => $domain ) {
			$domain = (string) preg_replace( '/:\d+$/', '', (string) $domain );

			if ( $domain !== '' ) {
				$domain_map[ $domain ] = $slug;
			}
		}

		if ( isset( $domain_map[ $host ] ) && isset( $slug_map[ $domain_map[ $host ] ] ) ) {
			return $domain_map[ $host ];
		}

		// Fallback: if host matches home_url, use default language.
		$base_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? '';

		if ( $host === $base_host && self::$default_language !== null ) {
			return self::$default_language->slug;
		}

		return null;
	}

	/**
	 * Detect language from the cookie.
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null
	 */
	private function detect_from_cookie( array $slug_map ): ?string {
		$cookie_lang = sanitize_key( $_COOKIE['perflocale_lang'] ?? '' );

		if ( $cookie_lang === '' ) {
			return null;
		}

		if ( isset( $slug_map[ $cookie_lang ] ) ) {
			return $cookie_lang;
		}

		// Cookie holds an old slug that's been renamed (e.g. `en` after
		// the admin renamed it to `en-us`). Look up the redirect map and
		// return the new slug if it's still active. Without this, a
		// returning visitor with a stale cookie would fall through to
		// browser/default detection until their cookie expires (365d).
		$redirects = \PerfLocale\Database\Repository\LanguageRepository::get_slug_redirects();

		if ( isset( $redirects[ $cookie_lang ] ) ) {
			$resolved = (string) $redirects[ $cookie_lang ];

			if ( $resolved !== '' && isset( $slug_map[ $resolved ] ) ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Detect language from an edge-worker hint (header or cookie).
	 *
	 * Honoured only when `edge_integration_enabled` is on AND the user
	 * has `edge_hint` in their detection order. The header is checked
	 * first (newer, trumps sticky cookie); cookie is the fallback.
	 *
	 * Trust model: the hint behaves the same as a user-set cookie in
	 * terms of power - at worst the visitor sees a different default
	 * language than they'd have otherwise. URL-based detection still
	 * wins whenever a prefix is present, so a spoofed hint can never
	 * override a canonical URL.
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null
	 */
	private function detect_from_edge_hint( array $slug_map ): ?string {
		if ( ! $this->settings->edge_integration_enabled() ) {
			return null;
		}

		/**
		 * Name of the HTTP header that carries the edge-selected language.
		 *
		 * @hook perflocale/edge/hint_header
		 * @param string $header_name Default: X-PerfLocale-Lang.
		 */
		$header_name = (string) apply_filters( 'perflocale/edge/hint_header', 'X-PerfLocale-Lang' );

		/**
		 * Name of the cookie that carries the edge-selected language.
		 *
		 * @hook perflocale/edge/hint_cookie
		 * @param string $cookie_name Default: perflocale_edge_lang.
		 */
		$cookie_name = (string) apply_filters( 'perflocale/edge/hint_cookie', 'perflocale_edge_lang' );

		$candidates = [];

		if ( $header_name !== '' ) {
			// PHP exposes HTTP headers as HTTP_UPPER_WITH_UNDERSCORES.
			$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header_name ) );

			if ( isset( $_SERVER[ $server_key ] ) ) {
				$candidates[] = sanitize_key( wp_unslash( (string) $_SERVER[ $server_key ] ) );
			}
		}

		if ( $cookie_name !== '' && isset( $_COOKIE[ $cookie_name ] ) ) {
			$candidates[] = sanitize_key( (string) $_COOKIE[ $cookie_name ] );
		}

		foreach ( $candidates as $candidate ) {
			if ( $candidate === '' || ! isset( $slug_map[ $candidate ] ) ) {
				continue;
			}

			/**
			 * Veto edge-hint detection per request.
			 *
			 * Return false to reject an otherwise valid hint (e.g. behind
			 * a reverse proxy that mis-forwards headers).
			 *
			 * @hook perflocale/edge/accept_hint
			 * @param bool $accept True to honour the hint.
			 * @param string $slug Candidate language slug.
			 */
			if ( ! apply_filters( 'perflocale/edge/accept_hint', true, $candidate ) ) {
				continue;
			}

			return $candidate;
		}

		return null;
	}

	/**
	 * Detect language from the browser's Accept-Language header.
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null
	 */
	private function detect_from_browser( array $slug_map ): ?string {
		if ( ! $this->settings->get( 'redirect_browser_lang' ) ) {
			return null;
		}

		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) );

		if ( $accept === '' ) {
			return null;
		}

		// Parse Accept-Language and match against available slugs.
		$preferred = $this->parse_accept_language( $accept );

		foreach ( $preferred as $lang_code ) {
			// Try exact match first (e.g., 'fr').
			if ( isset( $slug_map[ $lang_code ] ) ) {
				return $lang_code;
			}

			// Try base language (e.g., 'pt-br' → 'pt'). Split on the subtag
			// separator rather than truncating to 2 chars — a 3-letter code
			// like 'fil' must not be cut to 'fi' and false-match Finnish.
			$base = explode( '-', $lang_code )[0];

			if ( isset( $slug_map[ $base ] ) ) {
				return $base;
			}
		}

		return null;
	}

	/**
	 * Parse Accept-Language header into sorted language codes.
	 *
	 * @param string $header Accept-Language header value.
	 * @return array<int, string> Language codes sorted by quality.
	 */
	private function parse_accept_language( string $header ): array {
		$languages = [];

		/**
		 * Filter the maximum number of Accept-Language entries parsed.
		 *
		 * The limit defends against DoS via crafted headers - lower it if
		 * you've seen real-world aggressive bots; raise it only if you
		 * genuinely serve >20-language clients.
		 *
		 * @hook perflocale/accept_language_limit
		 * @param int $limit Default 20.
		 */
		$limit = (int) apply_filters( 'perflocale/accept_language_limit', 20 );
		$limit = max( 1, $limit );

		foreach ( array_slice( explode( ',', $header ), 0, $limit ) as $part ) {
			$part = trim( $part );

			if ( preg_match( '/^([a-zA-Z\-]+)(?:;q=([0-9.]+))?$/', $part, $matches ) ) {
				$code    = strtolower( $matches[1] );
				$quality = isset( $matches[2] ) ? (float) $matches[2] : 1.0;

				$languages[ $code ] = $quality;
			}
		}

		arsort( $languages );

		return array_keys( $languages );
	}

	/**
	 * Set the language preference cookie.
	 *
	 * @param string $slug Language slug.
	 * @return void
	 */
	private function set_language_cookie( string $slug ): void {
		if ( headers_sent() ) {
			return;
		}

		// Never Set-Cookie for crawlers. Bots are exempt from all three
		// cookie CONSUMERS (browser-language, edge-hint and geo redirects),
		// so a cookie written to a bot can never be read — but the header
		// itself is poison for page caches (Varnish's builtin turns a
		// Set-Cookie response into hit-for-pass). Same single-source UA
		// check the redirect paths use.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- read-only UA sniff, absent index coalesced.
		if ( self::is_bot_ua( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ) ) {
			return;
		}

		// Cookieless / consent gate. Skip writing the language-preference
		// cookie when the operator has enabled cookieless mode, or a consent-
		// management plugin hasn't granted consent (the same filter the
		// redirect paths honour). PerfLocale routes by URL, so language
		// detection still works without the cookie - only "remember my
		// language" on non-prefixed URLs is lost.
		if (
			(bool) $this->settings->get( 'disable_language_cookie', false )
			|| ! (bool) apply_filters( 'perflocale/privacy/consent_given', true )
		) {
			return;
		}

		// Skip the write entirely when nothing in the active config can ever
		// CONSUME the cookie. A Set-Cookie on every first anonymous view is
		// poison for page caches (Varnish builtin turns it into hit-for-pass
		// and every follow-up request carries an uncacheable Cookie header) —
		// paid for a value no code path would read.
		if ( ! $this->language_cookie_is_consumable() ) {
			return;
		}

		// Validate the slug is a known active language to prevent cookie injection.
		$slug_map = $this->get_language_slug_map();

		if ( ! isset( $slug_map[ $slug ] ) ) {
			return;
		}

		// Skip if cookie already has this value (avoids unnecessary Set-Cookie headers).
		if ( isset( $_COOKIE['perflocale_lang'] ) && $_COOKIE['perflocale_lang'] === $slug ) {
			return;
		}

		/** @hook perflocale/cookie_lifetime Filter the language cookie lifetime in days. */
		$lifetime = (int) apply_filters( 'perflocale/cookie_lifetime', (int) $this->settings->get( 'cookie_lifetime', 365 ) );

		setcookie(
			'perflocale_lang',
			$slug,
			[
				'expires'  => time() + ( $lifetime * DAY_IN_SECONDS ),
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/**
	 * Whether any active code path can ever READ the language cookie.
	 *
	 * Consumers: the redirect features (browser / geo / edge-hint all treat
	 * an existing cookie as "returning visitor — don't redirect"), and the
	 * cookie step of the detection loop — which is only reachable when a
	 * URL can lack language routing, i.e. subdirectory mode with a VISIBLE
	 * default prefix (every other mode force-resolves unprefixed URLs to
	 * the default in detect_locale_early()).
	 *
	 * @return bool
	 */
	private function language_cookie_is_consumable(): bool {
		if (
			(bool) $this->settings->get( 'redirect_browser_lang', false )
			|| (bool) $this->settings->get( 'redirect_geo_enabled', false )
			|| (bool) $this->settings->get( 'redirect_edge_hint_enabled', false )
		) {
			return true;
		}

		// WooCommerce Store API requests (block cart/checkout) post to the
		// unprefixed REST base and resolve their language FROM this cookie
		// (see detect_locale_early) — on WC sites the cookie is functional.
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		if (
			$this->settings->get_url_mode() === 'subdirectory'
			&& ! $this->settings->hide_default_prefix()
		) {
			$order = (array) $this->settings->get_detection_order();

			return in_array( 'cookie', $order, true ) || in_array( 'edge_hint', $order, true );
		}

		return false;
	}

	/**
	 * Public wrapper for setting the language cookie.
	 *
	 * Used by GeoRedirect and other components that need to set
	 * the cookie outside the detection loop.
	 *
	 * @param string $slug Language slug.
	 * @return void
	 */
	public function set_language_cookie_public( string $slug ): void {
		// Validate the slug is an active language before setting cookie.
		$slug_map = $this->get_language_slug_map();

		if ( ! isset( $slug_map[ $slug ] ) ) {
			return;
		}

		$this->set_language_cookie( $slug );
	}

	/**
	 * Filter the WordPress locale to match the detected language.
	 *
	 * @param string $locale Current locale.
	 * @return string Filtered locale.
	 */
	public function filter_locale( string $locale ): string {
		// Don't change the locale in admin. The admin UI should use the
		// user's profile language, not the site's default language.
		// This covers both regular admin pages and admin AJAX (e.g. WC
		// variation list loaded via wp_ajax_woocommerce_load_variations).
		// Frontend WC AJAX uses /?wc-ajax= where is_admin() is false,
		// so those requests correctly get the visitor's language locale.
		if ( is_admin() ) {
			return $locale;
		}

		if ( self::$current_language !== null && ! empty( self::$current_language->locale ) ) {
			return self::$current_language->locale;
		}

		return $locale;
	}

	/**
	 * Add the language query variable to WordPress's recognized query vars.
	 *
	 * `lang` is deliberately unprefixed: it is the shared multilingual URL
	 * convention and is user-facing in query URL mode (`?lang=de`), where a
	 * `perflocale_`-prefixed var would surface in every visitor URL.
	 *
	 * Collision with another multilingual plugin that owns the same var
	 * (Polylang registers `lang` for its `language` taxonomy) is impossible by
	 * construction: Bootstrap::init() aborts before any hook is registered when
	 * WPML, Polylang or TranslatePress is active, detected via both the
	 * competitor's constant and the active_plugins / network-active lists. The
	 * migrators read the competitor's leftover taxonomy rows straight from the
	 * database, so importing never requires the other plugin to be running.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 * @return array<int, string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = UrlConverter::query_var();
		return $vars;
	}

	/**
	 * Flush rewrite rules if the flag is set.
	 *
	 * @return void
	 */
	public function maybe_flush_rules(): void {
		// Claim the flag BEFORE flushing: flush_rewrite_rules() can take
		// hundreds of ms, and every request arriving in that window would
		// also see the flag and flush (a herd of concurrent flushes after a
		// language/settings change on a busy site). delete_option() returns
		// true for exactly one concurrent claimer; the losers skip.
		if ( get_option( 'perflocale_flush_rules' ) && delete_option( 'perflocale_flush_rules' ) ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Get the current language object.
	 *
	 * @return object|null
	 */
	public function get_current_language(): ?object {
		return self::$current_language;
	}

	/**
	 * Temporarily override the current language for an internal rendering
	 * window — order emails rendered outside the customer's own request
	 * (admin status change, gateway webhook, cron) must resolve
	 * language-keyed lookups (term names, attribute labels, string
	 * translations) in the ORDER's language, not the triggering request's.
	 *
	 * Returns the previous value; callers MUST restore it when the window
	 * closes.
	 *
	 * @param object|null $language Language row to impose, or null.
	 * @return object|null Previous current language.
	 */
	public function override_current_language( ?object $language ): ?object {
		$previous               = self::$current_language;
		self::$current_language = $language;

		// Downstream consumers memoize per request against the current language
		// (UrlConverter's home_url / object-permalink / object-language memos).
		// Signal both the override AND its restore so those memos rebuild for
		// the imposed language instead of serving another order's URLs. Fired
		// only on an actual change to keep the no-op restore path free.
		if ( ( $previous?->slug ?? '' ) !== ( $language?->slug ?? '' ) ) {
			/** @hook perflocale/language/overridden Fires when the current language is swapped for an internal rendering window (e.g. order-email locale). */
			do_action( 'perflocale/language/overridden' );
		}

		return $previous;
	}

	/**
	 * Get the current language slug.
	 *
	 * @return string Empty string if no language is set.
	 */
	public function get_current_slug(): string {
		return self::$current_language?->slug ?? '';
	}

	/**
	 * Get the current language ID.
	 *
	 * @return int 0 if no language is set.
	 */
	public function get_current_language_id(): int {
		return (int) ( self::$current_language?->id ?? 0 );
	}

	/**
	 * Get the default language object.
	 *
	 * @return object|null
	 */
	public function get_default_language(): ?object {
		$this->load_default_language();
		return self::$default_language;
	}

	/**
	 * Check if the current language is the default.
	 *
	 * @return bool
	 */
	public function is_default_language(): bool {
		if ( self::$current_language === null || self::$default_language === null ) {
			return true;
		}

		return self::$current_language->slug === self::$default_language->slug;
	}

	/**
	 * Get all active languages.
	 *
	 * @return array<int, object>
	 */
	public function get_active_languages(): array {
		/** @hook perflocale/active_languages Filter the list of active languages. */
		return (array) apply_filters( 'perflocale/active_languages', $this->get_repo()->get_active() );
	}

	/**
	 * Get the language slug map for O(1) lookups.
	 *
	 * @return array<string, object>
	 */
	private function get_language_slug_map(): array {
		return $this->get_repo()->get_slug_map();
	}

	/**
	 * Load the default language into static cache.
	 *
	 * @return void
	 */
	private function load_default_language(): void {
		if ( self::$default_language !== null ) {
			return;
		}

		self::$default_language = $this->get_repo()->get_default();
	}

	/**
	 * Get the language repository instance (lazy loaded).
	 *
	 * @return LanguageRepository
	 */
	private function get_repo(): LanguageRepository {
		if ( $this->repo === null ) {
			$this->repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		}

		return $this->repo;
	}

	/**
	 * Reset only the data-derived static caches — leave per-request
	 * detection state (current_language, detection_finalized) intact.
	 *
	 * Hooked to the language CRUD action chain so a language add / slug
	 * rename / default-change / delete made mid-request takes effect
	 * for the rest of that request. Without it, $path_prefix_map and
	 * $default_language stay frozen at whatever they resolved to on
	 * first read, even though the underlying repo cache was invalidated
	 * by flush_languages().
	 *
	 * @return void
	 */
	public static function reset_data_caches(): void {
		self::$default_language = null;
		self::$path_prefix_map  = null;
	}

	/**
	 * Per-blog state stack so nested switch_to_blog → … → restore_current_blog
	 * sequences preserve each blog's detected language state. Keyed by blog id.
	 *
	 * Bounded to MAX_STACK_DEPTH entries to prevent unbounded growth from
	 * misbehaving plugins that call switch_to_blog() without a matching
	 * restore_current_blog(). When the cap is hit, the oldest frame is evicted
	 * (FIFO) - the most recently pushed states are more likely to be restored
	 * next, so keep those.
	 *
	 * @var array<int, array{current: ?object, default: ?object, final: bool}>
	 */
	private static array $blog_state_stack = [];

	/**
	 * Maximum number of frames to retain in $blog_state_stack. Picked as a
	 * depth no legitimate multisite code path approaches - typical
	 * switch_to_blog/restore_current_blog pairs nest 1–3 levels.
	 */
	private const MAX_STACK_DEPTH = 32;

	/**
	 * switch_blog hook handler. Behavior:
	 * - Same blog id (e.g. WooCommerce's self-scoping switch_to_blog calls):
	 * no-op - preserve state so the current request keeps working.
	 * - Different blog id: push the leaving blog's state onto the stack,
	 * then pop the entering blog's state if we have it (so a restore
	 * recovers the original state), otherwise reset for fresh detection.
	 *
	 * @param int|string $new_blog_id Target blog id.
	 * @param int|string $prev_blog_id Previous blog id.
	 * @return void
	 */
	public static function maybe_reset_on_switch( $new_blog_id, $prev_blog_id ): void {
		// WP core always passes int blog ids, but third-party code calling
		// do_action( 'switch_blog', … ) directly could pass anything. is_numeric
		// rejects null, bool, arrays, objects, and non-numeric strings - the
		// cases that would otherwise trigger PHP warnings or silent array→1
		// coercion on the int cast below. (is_scalar would be redundant here.)
		if ( ! is_numeric( $new_blog_id ) || ! is_numeric( $prev_blog_id ) ) {
			return;
		}

		$new  = (int) $new_blog_id;
		$prev = (int) $prev_blog_id;

		// Bail on self-switch (no-op) and on non-positive ids (malformed).
		if ( $new === $prev || $new < 1 || $prev < 1 ) {
			return;
		}

		// Defend against unbounded growth: if the stack is at cap, evict the
		// oldest frame before pushing the new one. array_shift() would rekey
		// numeric keys (blog_ids), so use the reset()/key()/unset() dance to
		// drop the first entry while preserving all other blog_id keys.
		if ( count( self::$blog_state_stack ) >= self::MAX_STACK_DEPTH ) {
			reset( self::$blog_state_stack );
			$oldest_blog_id = key( self::$blog_state_stack );
			if ( $oldest_blog_id !== null ) {
				unset( self::$blog_state_stack[ $oldest_blog_id ] );
			}

			// Surface the overflow in WP_DEBUG. Reaching this branch in
			// normal multisite traffic indicates a third-party plugin is
			// calling switch_to_blog() without matching restore_current_blog()
			// — diagnose-aid for "language switcher mysteriously broke"
			// support tickets where the original-blog state quietly vanishes.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional WP_DEBUG diagnostic for stack-overflow detection; gated on WP_DEBUG_LOG above.
				error_log(
					sprintf(
						'PerfLocale: LanguageRouter blog_state_stack hit MAX_STACK_DEPTH (%d). Evicted oldest frame for blog_id=%s. A plugin is likely calling switch_to_blog() without restore_current_blog().',
						self::MAX_STACK_DEPTH,
						(string) $oldest_blog_id
					)
				);
			}
		}

		self::$blog_state_stack[ $prev ] = [
			'current' => self::$current_language,
			'default' => self::$default_language,
			'final'   => self::$detection_finalized,
		];

		if ( isset( self::$blog_state_stack[ $new ] ) ) {
			$saved                     = self::$blog_state_stack[ $new ];
			self::$current_language    = $saved['current'];
			self::$default_language    = $saved['default'];
			self::$detection_finalized = $saved['final'];
			unset( self::$blog_state_stack[ $new ] );
		} else {
			// Clear state fields individually - do NOT call reset() here: reset()
			// empties $blog_state_stack which would wipe the prev-blog frame we
			// just pushed above and break the eventual restore.
			self::$current_language    = null;
			self::$default_language    = null;
			self::$detection_finalized = false;
		}

		// Always drop the path-prefix lookup: it's derived from the prior
		// blog's slug_map and has no mapping back to a per-blog frame.
		self::$path_prefix_map = null;
	}
}
