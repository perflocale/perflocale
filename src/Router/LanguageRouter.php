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
 * Detects the current language from the request and holds it in static properties.
 *
 * Runs on every front-end request: detection is an O(1) prefix map lookup at parse_request priority 1.
 */
final class LanguageRouter {

	/**
	 * Static asset file extensions that skip language detection.
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
	 * Compound names (googlebot, gptbot, ...) are enumerated: `\bbot\b` misses a letter-bounded "bot",
	 * and a generic `\w*bot\b` would match names like "Abbot". Single source of truth for the three
	 * redirects and the cookie write. See docs-ai/11-code-rationale.md, "LanguageRouter: bot user agents".
	 *
	 * @hook perflocale/router/bot_ua_pattern Filter the bot detection regex.
	 */
	public const BOT_UA_PATTERN = '/\b(bot|crawl|spider|slurp|mediapartners|googlebot|bingbot|yandex|baidu|twitterbot|applebot|facebookexternalhit|linkedinbot|duckduckbot|gptbot|slackbot|discordbot|telegrambot|petalbot|ahrefsbot|semrushbot|pinterestbot|oai-searchbot|chatgpt-user|claudebot|claude-web|claude-user|claude-searchbot|anthropic-ai|perplexitybot|perplexity-user|ccbot|bytespider|amazonbot|meta-externalagent|meta-externalfetcher|duckassistbot|mistralai-user|cohere-ai|diffbot)\b/i';

	/**
	 * Test whether a user-agent string belongs to a known bot or crawler.
	 *
	 * @param string $ua Sanitized user-agent string.
	 * @return bool
	 */
	public static function is_bot_ua( string $ua ): bool {
		if ( $ua === '' ) {
			return false;
		}

		// Per-request memo keyed by UA: several call sites check the same UA.
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
	 * Strips the home path off REQUEST_URI because home_url() adds it back. The path comes from the raw `home`
	 * option: home_url() may carry the default prefix, and HTTP_HOST would make allow_redirect_host() an open
	 * redirect. See docs-ai/11-code-rationale.md, "LanguageRouter: current request URL".
	 *
	 * @return string Absolute URL of the current request.
	 */
	public static function current_request_url(): string {
		$request = (string) add_query_arg( [] );

		// A `home` without a path casts to '', which normalizes to '/' (root install: no-op).
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
	 * A class property so switch_blog and the language CRUD hooks can clear it (prefixes are per blog).
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

		// Browser-language redirect. Priority 9 + index in `redirect_priority_order`, so the owner's first
		// method runs first and stays clear of core's template_redirect hooks. Mirror in GeoRedirect.
		if ( (bool) $this->settings->get( 'redirect_browser_lang', false ) ) {
			$order    = $this->settings->get_redirect_priority_order();
			$idx      = array_search( 'browser', $order, true );
			$priority = $idx !== false ? 9 + (int) $idx : 10;
			add_action( 'template_redirect', [ $this, 'maybe_redirect_browser' ], $priority );
		}

		// Edge-hint redirect (X-PerfLocale-Lang header or perflocale_edge_lang cookie set by an edge worker).
		// Needs this setting and the global edge integration, which gates detect_from_edge_hint() itself.
		if (
			(bool) $this->settings->get( 'redirect_edge_hint_enabled', false )
			&& $this->settings->edge_integration_enabled()
		) {
			$order    = $this->settings->get_redirect_priority_order();
			$idx      = array_search( 'edge_hint', $order, true );
			$priority = $idx !== false ? 9 + (int) $idx : 11;
			add_action( 'template_redirect', [ $this, 'maybe_redirect_edge_hint' ], $priority );
		}

		// Strip the `perflocale_redirected` sentinel with a 301. Priority 1: before the browser and geo
		// redirects, so they never re-fire on the cleaned URL.
		add_action( 'template_redirect', [ $this, 'clean_redirect_sentinel' ], 1 );

		// Default prefix shown: 301 the bare `/` to `/{default_slug}/` (one canonical home).
		if (
			! $this->settings->hide_default_prefix()
			&& $this->settings->get_url_mode() === 'subdirectory'
		) {
			add_action( 'template_redirect', [ $this, 'maybe_redirect_default_to_prefix' ], 2 );
		} elseif (
			$this->settings->hide_default_prefix()
			&& $this->settings->get_url_mode() === 'subdirectory'
		) {
			// Default prefix hidden: 301 `/{default_slug}/` to `/` (core's redirect_canonical does this only
			// for a static front page). Home only: inner prefixed URLs carry a canonical tag, and stripping
			// them could serve a URL whose content lives in another language.
			add_action( 'template_redirect', [ $this, 'maybe_redirect_prefix_to_default' ], 2 );
		}

		// Locale prefix mode: 301 slug-form URLs (`/en/`), which the rules do not match, to the locale form (`/en-us/`).
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

		// Host-routed modes: every wp_safe_redirect() (core, WooCommerce, SEO plugins) to another
		// language's page must accept that language's host.
		if ( in_array( $this->settings->get_url_mode(), [ 'domain', 'subdomain' ], true ) ) {
			add_filter( 'allowed_redirect_hosts', [ $this, 'filter_allowed_redirect_hosts' ] );
		}

		// Language add/rename/delete mid-request (WP-CLI, REST, AJAX) must not route with a stale slug map.
		$reset_data_caches = [ self::class, 'reset_data_caches' ];

		add_action( 'perflocale/language/added', $reset_data_caches );
		add_action( 'perflocale/language/updated', $reset_data_caches );
		add_action( 'perflocale/language/slug_renamed', $reset_data_caches );
		add_action( 'perflocale/language/deleted', $reset_data_caches );

		// Reset static state only when the blog really changes: plugins call switch_to_blog() with the SAME
		// id, and a reset then wipes the detected language for the rest of the request.
		if ( is_multisite() ) {
			// Three args: the third ('switch' or 'restore') tells a switch from its return leg.
			add_action( 'switch_blog', [ self::class, 'maybe_reset_on_switch' ], 10, 3 );
		}
	}

	/**
	 * 301-redirect slug-form language URLs (`/en/`) to their locale form (`/en-us/`).
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

		// Bail on the home path before any lookup: this runs on every front-end request in locale mode.
		// Use esc_url_raw(): sanitize_text_field() strips %XX and corrupts non-ASCII slugs.
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

		// Strip a subfolder home path, read from the raw `home` option: our home_url filter adds a prefix.
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

		// A known slug whose configured prefix differs (locale form); "fr" with prefix "fr" needs nothing.
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
	 * Remove the `perflocale_redirected` sentinel with a 301 after a language redirect.
	 *
	 * Only once the language cookie is set: with cookies blocked the sentinel stays, as the loop fuse.
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
	 * 301-redirect bare URLs to the default-language prefix when that prefix is shown.
	 *
	 * Registered only in subdirectory mode with hide_default_prefix off.
	 *
	 * @return void
	 */
	public function maybe_redirect_default_to_prefix(): void {
		// Front end only.
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
		 * Programmatic opt-out: return false to let both `/` and `/{default_slug}/` resolve.
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

		// Default language only: another language chosen by cookie, browser or edge hint is
		// maybe_redirect_browser()'s job.
		if ( ! $this->is_default_language() ) {
			return;
		}

		// Keep the query string verbatim. Use esc_url_raw() to keep %XX bytes (see maybe_canonicalise_prefix_form()).
		$request_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$qs_pos      = strpos( $request_uri, '?' );
		$path        = $qs_pos !== false ? substr( $request_uri, 0, $qs_pos ) : $request_uri;
		$query       = $qs_pos !== false ? substr( $request_uri, $qs_pos ) : '';

		if ( $path === '' ) {
			$path = '/';
		}

		// Strip a subfolder home path, read from the raw `home` option: the home_url filter adds the default
		// prefix, which would be stripped as a sub-install and loop-redirect /fr/ to itself.
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

		// Custom excluded paths (e.g. `/api/`); the matcher is shared with UrlConverter.
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
	 * 301-redirect the bare default-prefix home (`/{default_slug}/`) to `/` when the default prefix is hidden.
	 *
	 * Home only: an inner `/{default_slug}/foo/` has its canonical tag, and stripping it could serve a slug
	 * whose content lives in another language.
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
		 * Programmatic opt-out: return false to let both `/` and the bare `/{default_slug}/` home resolve.
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

		// Use esc_url_raw() to keep %XX bytes (see maybe_canonicalise_prefix_form()).
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

		// Home only: EXACTLY the default slug (`/en/`) or, in locale mode, its canonical locale form
		// (`/en-us/`); otherwise that twin stays an un-redirected duplicate of `/`.
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
	 * Always runs URL detection to strip the prefix from $wp->request. When detect_locale_early()
	 * already resolved the language, it only finalises (cookie + action).
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

				// A visitor-specific source (cookie, Accept-Language, edge hint) varies the body at a shared
				// URL, so a URL-keyed page cache must not store it. URL-detected requests stay cacheable.
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
	 * Same site-relative path and Helper::path_matches_excluded() as routing, the canonical redirect and
	 * the CDN tags; the edge worker (assets/js/edge-helper.js) keeps its own prefix match. URL-derived,
	 * so callers may bail on it before nocache_headers() and keep excluded pages cacheable.
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

		// Strip a subfolder home path via the raw `home` option (not the filtered home_url()).
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

		return Helper::path_matches_excluded( $relative, $excluded_paths );
	}

	/**
	 * Whether the language cookie can never be written on this site.
	 *
	 * True in cookieless mode while nothing hooks `perflocale/language_cookie/enabled` (set_language_cookie()'s
	 * test). The first-visit redirects then stay off: without the cookie they would redirect the same visitor
	 * on every default-language page. Config-derived, so callers ask it above nocache_headers().
	 *
	 * @return bool
	 */
	public function language_cookie_never_written(): bool {
		return (bool) $this->settings->get( 'disable_language_cookie', false )
			&& ! has_filter( 'perflocale/language_cookie/enabled' );
	}

	/**
	 * Redirect first-time visitors to their browser-preferred language.
	 *
	 * Runs on template_redirect for a first-time, non-bot visitor on a default-language page whose
	 * browser prefers another active language.
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

		// Never hijack feeds, robots.txt, favicon or trackbacks: cookieless feed pollers would flip a feed's
		// language. URL-derived, so it stays above nocache_headers() to keep them cacheable.
		if ( is_feed() || is_trackback() || is_robots() || is_favicon() ) {
			return;
		}

		if ( ! $this->settings->get( 'redirect_browser_lang' ) ) {
			return;
		}

		// No redirect when the cookie can never be written: it would repeat on every default-language page.
		// Config-derived, so it stays above nocache_headers().
		if ( $this->language_cookie_never_written() ) {
			return;
		}

		// Excluded paths are not redirected either. URL-derived, so it stays above nocache_headers().
		if ( $this->is_excluded_request_path() ) {
			return;
		}

		// From here the 302-or-200 depends on the visitor, so the entry response must be uncacheable or a
		// cached 200 starves the redirect for later visitors. Only URL-derived checks above (as GeoRedirect).
		if ( $this->is_default_language() && ! headers_sent() ) {
			nocache_headers();
		}

		/**
		 * Consent gate: consent plugins return false to suppress the redirect until consent is given.
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

		// Redirect fuse: `?perflocale_redirected=1` stops a second redirect when the browser drops the
		// cookie (privacy browsers), so it cannot loop.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_redirected'] ) ) {
			return;
		}

		// Only when URL resolved to the default language.
		if ( ! $this->is_default_language() ) {
			return;
		}

		// Bots see the default content (self::is_bot_ua()).
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

		// No redirect to a singular landing that would 404 or bounce back. The default-language cookie
		// detect_language() set on this request still marks the visitor as returning.
		if ( ! $this->first_visit_landing_serves( $browser_slug ) ) {
			return;
		}

		// Set cookie before redirect so the visitor is not redirected again.
		$this->set_language_cookie( $browser_slug );

		// Sentinel so a cookie-blocked browser is not redirected again on its next request.
		$redirect_url = add_query_arg( 'perflocale_redirected', '1', $redirect_url );

		// Per-visitor redirect on a shared URL: uncacheable, so a cache that stores 3xx cannot pin it on
		// others. Allow the (possibly cross-host) target, or wp_safe_redirect() falls back to /wp-admin/.
		$this->allow_redirect_host( $redirect_url );
		nocache_headers();
		wp_safe_redirect( $redirect_url, 302 );
		exit;
	}

	/**
	 * Redirect first-time visitors based on an edge-decided language hint.
	 *
	 * An edge worker (Cloudflare, Vercel, Netlify) sends the slug in the `X-PerfLocale-Lang` header or the
	 * `perflocale_edge_lang` cookie (names filterable). Same gating as maybe_redirect_browser().
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

		// Setting and global edge gate, checked here too to bail before the slug map and bot regex.
		if (
			! (bool) $this->settings->get( 'redirect_edge_hint_enabled' )
			|| ! $this->settings->edge_integration_enabled()
		) {
			return;
		}

		// No automatic redirect when the language cookie can never be
		// written (see maybe_redirect_browser()).
		if ( $this->language_cookie_never_written() ) {
			return;
		}

		// Excluded paths never auto-redirect (see maybe_redirect_browser()).
		if ( $this->is_excluded_request_path() ) {
			return;
		}

		// Same consent gate as maybe_redirect_browser().
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

		// Bots see the default content (self::is_bot_ua()).
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		if ( self::is_bot_ua( $ua ) ) {
			return;
		}

		// Reuse detect_from_edge_hint() so header/cookie names and filters match the detection flow.
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

		// No redirect, and no target-language cookie, to a singular landing
		// that would answer 404 or send the visitor back (see
		// maybe_redirect_browser()).
		if ( ! $this->first_visit_landing_serves( $edge_slug ) ) {
			return;
		}

		// Cookie + sentinel before redirect (prevents re-fire on cookie-blocked browsers).
		$this->set_language_cookie( $edge_slug );
		$redirect_url = add_query_arg( 'perflocale_redirected', '1', $redirect_url );

		// Per-visitor redirect on a shared URL: uncacheable, so a cache that stores 3xx cannot pin it on
		// others. Allow the (possibly cross-host) target, or wp_safe_redirect() falls back to /wp-admin/.
		$this->allow_redirect_host( $redirect_url );
		nocache_headers();
		wp_safe_redirect( $redirect_url, 302 );
		exit;
	}

	/**
	 * Whether a first-visit redirect to this language lands on content.
	 *
	 * On a singular post the landing applies the missing-translation rules (404 or back to the default);
	 * PostQueryFilter::serves_in_language() decides. Asked only right before a redirect, so a visitor who
	 * gets none also gets no target-language cookie.
	 *
	 * @param string $slug Language slug the visitor would be redirected to.
	 * @return bool
	 */
	public function first_visit_landing_serves( string $slug ): bool {
		if ( ! is_singular() ) {
			return true;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return true;
		}

		$language = $this->get_language_slug_map()[ $slug ] ?? null;
		$plugin   = \PerfLocale\Plugin::get_instance();

		if ( ! is_object( $language ) || ! $plugin->has( 'post_query_filter' ) ) {
			return true;
		}

		$filter = $plugin->get( 'post_query_filter' );

		if ( ! $filter instanceof \PerfLocale\Translation\PostQueryFilter ) {
			return true;
		}

		return $filter->serves_in_language( $post, $language );
	}

	/**
	 * Add every language's host to the hosts wp_safe_redirect() accepts.
	 *
	 * Also adds the site's own host from `home`: on a language host home_url() can carry that language's
	 * host, so core's own entry may not be the default host. Only hosts this site routes are added.
	 *
	 * @param mixed $hosts Allowed host names.
	 * @return mixed The list with the language hosts added; a non-array is returned unchanged.
	 */
	public function filter_allowed_redirect_hosts( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			return $hosts;
		}

		$url_mode  = $this->settings->get_url_mode();
		$base_host = self::normalize_host( (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) ?? '' ) );
		$slug_map  = $this->get_language_slug_map();
		$add       = $base_host !== '' ? [ $base_host ] : [];

		if ( $url_mode === 'domain' ) {
			foreach ( $this->settings->get_language_domains() as $slug => $domain ) {
				$domain = trim( (string) $domain );

				if ( ! isset( $slug_map[ $slug ] ) || $domain === '' ) {
					continue;
				}

				// A stored value may carry a scheme, a path or a port.
				$host = (string) wp_parse_url( str_contains( $domain, '//' ) ? $domain : '//' . $domain, PHP_URL_HOST );

				if ( $host === '' ) {
					continue;
				}

				// Core compares the target host case-sensitively; add the host
				// as a converted URL spells it and in its normalised form.
				$add[] = $host;
				$add[] = self::normalize_host( $host );
			}
		} elseif ( $url_mode === 'subdomain' && $base_host !== '' ) {
			$default      = $this->get_default_language();
			$default_slug = $default !== null ? (string) $default->slug : '';

			foreach ( array_keys( $slug_map ) as $slug ) {
				if ( (string) $slug !== $default_slug ) {
					$add[] = $slug . '.' . $base_host;
				}
			}
		}

		return array_values( array_unique( array_merge( $hosts, array_filter( $add ) ) ) );
	}

	/**
	 * Whitelist a redirect target's host for one wp_safe_redirect() call.
	 *
	 * Cross-host targets (domain/subdomain mode) are otherwise rejected, falling back to /wp-admin/.
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
	 * Runs at plugin load (Bootstrap::init()) so the locale filter is right before plugins load their text
	 * domains at init. Sets only self::$current_language; prefix stripping happens in detect_language().
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

		// Tables may be missing (another table prefix, e.g. the Plugin Check sandbox, or before install).
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

		if ( $slug === null ) {
			$slug = $this->detect_store_api_cookie_language( $slug_map, $url_mode );
		}

		// No prefix: the bare host is the default in subdomain/domain mode; in subdirectory mode an
		// un-prefixed path is the default only when the default prefix is hidden.
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
	 * Language of a WooCommerce Store API request whose URL carries none.
	 *
	 * The block cart and checkout use the unprefixed /wp-json/wc/store/ base, so the language cookie decides;
	 * a non-default cookie marks a GET/HEAD uncacheable. Not on a path-prefixed subsite in subdirectory mode,
	 * where it made every Store API call a 404. See docs-ai/11-code-rationale.md, "LanguageRouter: Store API cookie language".
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @param string                $url_mode Configured URL mode.
	 * @return string|null Cookie language slug, or null when the branch does not apply.
	 */
	private function detect_store_api_cookie_language( array $slug_map, string $url_mode ): ?string {
		$blog_path = '/';

		if ( is_multisite() && isset( $GLOBALS['current_blog']->path ) ) {
			$blog_path = (string) $GLOBALS['current_blog']->path;
		}

		if ( $url_mode === 'subdirectory' && '/' !== $blog_path ) {
			return null;
		}

		// A Store API response on this request may follow the cookie. The
		// callback runs only when a REST response is served and checks the route
		// there, so a page view pays for the registration alone.
		add_filter( 'rest_pre_serve_request', [ $this, 'vary_store_api_response_on_cookie' ], 20, 3 );

		if ( ! isset( $_COOKIE['perflocale_lang'] ) ) {
			return null;
		}

		$request_path = (string) wp_parse_url(
			esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ),
			PHP_URL_PATH
		);

		if ( ! str_contains( $request_path, '/wc/store/' ) ) {
			return null;
		}

		$cookie_slug = sanitize_key( wp_unslash( (string) $_COOKIE['perflocale_lang'] ) );

		if ( isset( $slug_map[ $cookie_slug ] ) ) {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

			if ( ( 'GET' === $method || 'HEAD' === $method ) && ! $this->slug_is_default( $cookie_slug ) ) {
				add_filter( 'rest_send_nocache_headers', '__return_true' );
			}

			return $cookie_slug;
		}

		return null;
	}

	/**
	 * Send `Vary: Cookie` on a Store API response that may follow the language cookie (opt-in).
	 *
	 * Priority 20: after WooCommerce's CORS callback, which replaces `Vary`; it appends so `Vary: Origin`
	 * survives. Responses with `no-store` (cart, checkout) are skipped.
	 *
	 * @param mixed $served  Whether the request has already been served.
	 * @param mixed $result  Response about to be sent.
	 * @param mixed $request Request being served.
	 * @return mixed $served, unchanged.
	 */
	public function vary_store_api_response_on_cookie( mixed $served, mixed $result = null, mixed $request = null ): mixed {
		if ( $served || ! $request instanceof \WP_REST_Request || ! str_starts_with( $request->get_route(), '/wc/store/' ) ) {
			return $served;
		}

		if ( $result instanceof \WP_HTTP_Response ) {
			foreach ( $result->get_headers() as $name => $value ) {
				if ( is_string( $value ) && 'cache-control' === strtolower( (string) $name ) && str_contains( strtolower( $value ), 'no-store' ) ) {
					return $served;
				}
			}
		}

		/**
		 * Whether to send `Vary: Cookie` on Store API responses whose language may come from the cookie.
		 *
		 * Turn it on only when your page or edge cache honours `Vary`; otherwise key that path on `perflocale_lang`.
		 *
		 * @hook perflocale/router/store_api_vary_cookie
		 * @param bool             $vary    Whether to send the header. Default false.
		 * @param \WP_REST_Request $request Store API request being served.
		 */
		if ( ! (bool) apply_filters( 'perflocale/router/store_api_vary_cookie', false, $request ) || headers_sent() ) {
			return $served;
		}

		header( 'Vary: Cookie', false );

		return $served;
	}

	/**
	 * Detect language slug from REQUEST_URI path prefix.
	 *
	 * Early detection before parse_request; reads the request, never modifies it.
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	private function detect_slug_from_request_uri( array $slug_map ): ?string {
		// esc_url_raw preserves percent-encoded bytes - wp_parse_url() needs
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

		// Direct slug match; decode only for the lookup (see detect_from_path()).
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
	 * Strips a found path prefix from $wp->request so WordPress resolves the right content.
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
	 * Reads both the slug and the locale form, so a Prefix Format change never 404s old links. Two passes:
	 * every own slug first, then aliases only into free keys (a slug is UNIQUE, an alias must never shadow it).
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
	 * Language slug that a subdirectory-mode path segment addresses.
	 *
	 * Looks the segment up in the prefix map detect_from_path() routes by,
	 * as given and then percent-decoded, as detect_from_path() does.
	 *
	 * @internal Used by UrlConverter to recognise a language root URL, and by
	 *           SlugRedirector to keep a served prefix from being redirected.
	 *
	 * @param string $segment One path segment after the home path, without slashes.
	 * @return string|null Language slug, or null when no active language uses the segment.
	 */
	public function slug_for_path_prefix( string $segment ): ?string {
		if ( $segment === '' ) {
			return null;
		}

		$prefix_map = $this->prefix_map( $this->get_language_slug_map() );

		if ( isset( $prefix_map[ $segment ] ) ) {
			return $prefix_map[ $segment ];
		}

		if ( str_contains( $segment, '%' ) ) {
			$decoded = rawurldecode( $segment );

			if ( isset( $prefix_map[ $decoded ] ) ) {
				return $prefix_map[ $decoded ];
			}
		}

		return null;
	}

	/**
	 * Detect language from the `lang` query parameter (query URL mode).
	 *
	 * The raw value is only matched against the prefix map, never output. The name comes from
	 * UrlConverter::query_var(), as for the writer.
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	/**
	 * Read the language query parameter without destroying a legal prefix.
	 *
	 * Settings writes prefixes sanitize_key() would mangle (`?lang=sr-rs@latin`), and changing the writer
	 * would change live URLs: the raw value counts only when it is a known key, used solely as an array key.
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

		// Both the slug and the locale form are read, whatever Prefix Format writes, so old links keep resolving.
		if ( isset( $prefix_map[ $slug ] ) ) {
			return $prefix_map[ $slug ];
		}

		// A renamed slug resolves through the redirect map; maybe_redirect_renamed_query_slug() also 301s it.
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
	 * A renamed slug or the other prefix form: detection already serves the right content, and the redirect
	 * keeps ONE indexable query URL per language. The default language carries no parameter.
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

		// Same reader tolerance as detect_from_query_param(), or this would 301 a legal prefix it cannot parse.
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
				return; // Unknown value - leave the URL alone.
			}
		}

		// Canonical form under the current Prefix Format, as UrlConverter writes it: one 200 URL per
		// language (as maybe_canonicalise_prefix_form() does in subdirectory mode).
		$canonical = $this->settings->get_url_prefix( $slug_map[ $resolved ] );

		if ( $canonical === $prefix ) {
			return; // Already canonical - no redirect.
		}

		// Never 301 to a prefix that resolves to a DIFFERENT language (slug `de-de` beside locale `de_DE`):
		// a permanent, cacheable redirect would serve the wrong language. Leaving the URL alone is safe.
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

		// $wp->request is percent-encoded while prefix_map keys are decoded (`@` or non-ASCII prefixes), and
		// core retries rules against urldecode(). Only the lookup key is decoded: the string surgery below
		// relies on the raw length of $first_seg.
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
					// $first_seg is a slug-map key (no regex metacharacters), so string ops strip it.
				$mq = (string) $wp->matched_query;

				if ( str_starts_with( $mq, $first_seg . '/' ) ) {
					$wp->matched_query = substr( $mq, strlen( $first_seg ) + 1 );
				} elseif ( str_starts_with( $mq, $first_seg ) ) {
					$wp->matched_query = substr( $mq, strlen( $first_seg ) );
				}
			}

			// No rule matched the prefixed URL: drop query vars WordPress derived from the full path.
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
	 * Canonicalise a host for comparison: port-stripped, lowercase, A-label.
	 *
	 * Uses core's pure-PHP IdnaEncoder, not idn_to_ascii(): the two disagree (`faß.de`), and routing must not
	 * depend on the host's extensions. Public so Site Health compares the same strings as the router.
	 * See docs-ai/11-code-rationale.md, "LanguageRouter: host normalisation".
	 *
	 * @param string $host Raw host, possibly with a port.
	 * @return string Canonical host for comparison.
	 */
	public static function normalize_host( string $host ): string {
		$host = (string) preg_replace( '/:\d+$/', '', $host );

		if ( $host === '' ) {
			return '';
		}

		// Fast path: ASCII only needs case folding. Use strtolower(): the extension-guard suite forbids
		// mbstring here.
		if ( ! preg_match( '/[\x80-\xFF]/', $host ) ) {
			return strtolower( $host );
		}

		if ( strlen( $host ) > 253 ) {
			return strtolower( $host );
		}

		if ( ! class_exists( '\WpOrg\Requests\IdnaEncoder' ) ) {
			return strtolower( $host );
		}

		try {
			return strtolower( \WpOrg\Requests\IdnaEncoder::encode( $host ) );
		} catch ( \Throwable $e ) {
			// Over-long label or another refusal: the host matches no configured domain, never a fatal.
			unset( $e );

			return strtolower( $host );
		}
	}

	/**
	 * Detect language from subdomain (e.g., en.example.com → 'en').
	 *
	 * @param array<string, object> $slug_map Language slug map.
	 * @return string|null Detected language slug or null.
	 */
	private function detect_from_subdomain( array $slug_map ): ?string {
		// Strip any :port - HTTP_HOST carries it on non-80/443 setups (dev,
		// staging, ported production) but wp_parse_url(home_url()) never does,
		// so an unstripped host would match neither branch and force default.
		$host      = self::normalize_host( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$base_host = self::normalize_host( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' ) );

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
		// portless) configured domain values - see detect_from_subdomain().
		$host    = self::normalize_host( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$domains = $this->settings->get_language_domains();

		// Build a reverse map: domain → slug, port-stripped on both sides so an
		// admin who entered `example.fr:8443` still matches a ported host.
		$domain_map = [];

		foreach ( $domains as $slug => $domain ) {
			$domain = self::normalize_host( (string) $domain );

			if ( $domain !== '' ) {
				$domain_map[ $domain ] = $slug;
			}
		}

		if ( isset( $domain_map[ $host ] ) && isset( $slug_map[ $domain_map[ $host ] ] ) ) {
			return $domain_map[ $host ];
		}

		// Fallback: if host matches home_url, use default language.
		$base_host = self::normalize_host( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' ) );

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

		// A renamed slug in the cookie resolves through the redirect map, so a returning visitor keeps
		// their language until the cookie expires.
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
	 * Detect language from an edge-worker hint (header first, then cookie).
	 *
	 * Trusted like a user-set cookie: a URL prefix always wins, so a spoofed hint cannot override a canonical URL.
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
			// separator rather than truncating to 2 chars - a 3-letter code
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
		 * The limit defends against crafted headers.
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

		// Never Set-Cookie for crawlers: no bot ever reads it, and the header makes page caches pass
		// (Varnish hit-for-pass). Same UA check as the redirects.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- read-only UA sniff, absent index coalesced.
		if ( self::is_bot_ua( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ) ) {
			return;
		}

		// Cookieless mode or no consent: skip the write (routing is by URL, so only "remember my language"
		// is lost). Cookieless mode is only the default of `perflocale/language_cookie/enabled` asked below.
		$cookie_enabled = ! (bool) $this->settings->get( 'disable_language_cookie', false );

		if (
			( ! $cookie_enabled && ! has_filter( 'perflocale/language_cookie/enabled' ) )
			|| ! (bool) apply_filters( 'perflocale/privacy/consent_given', true )
		) {
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

		// Skip the write when no active code path can read the cookie (Set-Cookie defeats page caches).
		// Last of the pure guards on purpose: the most expensive, so it runs at most once per visitor.
		// $unrouted_default is an out-parameter the narrowing below reuses (null when not resolved).
		$unrouted_default = null;
		$consumer         = $this->language_cookie_consumer( $unrouted_default );

		if ( self::COOKIE_CONSUMER_NONE === $consumer ) {
			return;
		}

		// Skip a first-visit DEFAULT-language cookie when WooCommerce is its sole reader: its readers treat it
		// as no cookie. Every clause is load-bearing (redirect and detection readers, a non-default shopper's
		// cart, a stale cookie, admin-ajax orders, edge workers): see docs-ai/11-code-rationale.md,
		// "LanguageRouter: redundant WooCommerce cookie write".
		if (
			self::COOKIE_CONSUMER_WOOCOMMERCE === $consumer
			&& ! isset( $_COOKIE['perflocale_lang'] )
			&& $this->slug_is_default( $slug )
			&& ( $unrouted_default ?? $this->unrouted_url_resolves_to_default() )
			&& ! $this->edge_worker_may_steer()
		) {
			/**
			 * Whether to skip a language-cookie write that would change no answer.
			 *
			 * Return false to always write it, e.g. for an edge, CDN or analytics layer of your own that reads it.
			 *
			 * @hook perflocale/cookie/skip_redundant_write
			 * @param bool   $skip Whether to skip the redundant write. Default true.
			 * @param string $slug Language slug that would have been written.
			 */
			if ( (bool) apply_filters( 'perflocale/cookie/skip_redundant_write', true, $slug ) ) {
				return;
			}
		}

		/**
		 * Whether to write the language-preference cookie.
		 *
		 * Asked only right before a write, so it cannot force one a guard above refused. It wins over the
		 * cookieless setting both ways; in cookieless mode the first-visit redirects run only while it is hooked.
		 *
		 * @hook perflocale/language_cookie/enabled
		 * @param bool   $enabled Whether to write the cookie. Default true, false when the setting disables it.
		 * @param string $slug    Language slug about to be written.
		 */
		if ( ! (bool) apply_filters( 'perflocale/language_cookie/enabled', $cookie_enabled, $slug ) ) {
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

	/** No active code path can read the language cookie. */
	private const COOKIE_CONSUMER_NONE = '';

	/** A redirect feature reads it as "returning visitor - don't steer". */
	private const COOKIE_CONSUMER_REDIRECT = 'redirect';

	/** Only WooCommerce reads it (Store API language, order-email language). */
	private const COOKIE_CONSUMER_WOOCOMMERCE = 'woocommerce';

	/** The detection loop resolves the language FROM it on unprefixed URLs. */
	private const COOKIE_CONSUMER_DETECTION = 'detection';

	/**
	 * Which active code path can READ the language cookie.
	 *
	 * Returns the reason, because set_language_cookie() narrows only the WooCommerce one. WooCommerce is
	 * checked LAST, or a store would hide the detection reader. clean_redirect_sentinel() also reads the
	 * cookie but needs no write of its own (only the redirects append its sentinel).
	 *
	 * @param bool|null $unrouted_resolves_to_default Out-parameter the caller's narrowing reuses; null
	 *                    when the redirect branch answers first.
	 * @return string One of the COOKIE_CONSUMER_* constants.
	 */
	private function language_cookie_consumer( ?bool &$unrouted_resolves_to_default = null ): string {
		$unrouted_resolves_to_default = null;

		if (
			(bool) $this->settings->get( 'redirect_browser_lang', false )
			|| (bool) $this->settings->get( 'redirect_geo_enabled', false )
			|| (bool) $this->settings->get( 'redirect_edge_hint_enabled', false )
		) {
			return self::COOKIE_CONSUMER_REDIRECT;
		}

		// Where an un-routed URL is not the default, the detection loop's cookie/edge_hint steps read the
		// cookie. Resolved into the out-parameter so the caller does not ask twice.
		$unrouted_resolves_to_default = $this->unrouted_url_resolves_to_default();

		if ( ! $unrouted_resolves_to_default ) {
			$order = (array) $this->settings->get_detection_order();

			if ( in_array( 'cookie', $order, true ) || in_array( 'edge_hint', $order, true ) ) {
				return self::COOKIE_CONSUMER_DETECTION;
			}
		}

		// WooCommerce: the Store API (unprefixed REST base) and EmailTranslation (the classic checkout posts
		// to admin-ajax.php) resolve their language from this cookie.
		if ( class_exists( 'WooCommerce' ) ) {
			return self::COOKIE_CONSUMER_WOOCOMMERCE;
		}

		return self::COOKIE_CONSUMER_NONE;
	}

	/**
	 * Whether this slug is the site's default language.
	 *
	 * Asks LanguageRepository::get_default(), the row the cookie readers compare against, not a slug-map
	 * row's is_default flag (two flagged rows would make the narrowing skip a write they need).
	 *
	 * @param string $slug Language slug about to be written.
	 * @return bool
	 */
	private function slug_is_default( string $slug ): bool {
		$this->load_default_language();

		$default = self::$default_language;

		return $default !== null && (string) $default->slug === $slug;
	}

	/**
	 * Whether a URL without language routing resolves to the DEFAULT language without the cookie.
	 *
	 * The same condition detect_locale_early() guards its default fallback with: false only in
	 * subdirectory mode with a visible default prefix.
	 *
	 * @return bool
	 */
	private function unrouted_url_resolves_to_default(): bool {
		return $this->settings->get_url_mode() !== 'subdirectory'
			|| $this->settings->hide_default_prefix();
	}

	/**
	 * Whether an edge worker may be steering this visitor by language.
	 *
	 * Both bundled workers stop steering once they see a perflocale_lang cookie. Strategy A is silent but
	 * needs `edge_integration_enabled` (its /config endpoint); the header covers a worker with a cached config.
	 *
	 * @return bool
	 */
	private function edge_worker_may_steer(): bool {
		if ( $this->settings->edge_integration_enabled() ) {
			return true;
		}

		// Same filter and default detect_from_edge_hint() resolves the header
		// name with. Never hard-code the name here: an operator who renamed it
		// would silently lose the exemption and with it the default language.
		$header_name = (string) apply_filters( 'perflocale/edge/hint_header', 'X-PerfLocale-Lang' );

		if ( $header_name === '' ) {
			return false;
		}

		// PHP exposes HTTP headers as HTTP_UPPER_WITH_UNDERSCORES.
		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header_name ) );

		if ( ! isset( $_SERVER[ $server_key ] ) ) {
			return false;
		}

		return sanitize_key( wp_unslash( (string) $_SERVER[ $server_key ] ) ) !== '';
	}

	/**
	 * Public wrapper for setting the language cookie (GeoRedirect and other components).
	 *
	 * Not an unconditional write: with WooCommerce as the only reader, a first-visit default slug writes
	 * nothing (see set_language_cookie()); `perflocale/cookie/skip_redundant_write` false always writes.
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
		// Admin, including admin AJAX, keeps the user's profile locale. Front-end /?wc-ajax= is not
		// is_admin(), so it gets the visitor's language.
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
	 * `lang` is unprefixed on purpose: it is user-facing in query mode (`?lang=de`). It cannot collide with
	 * Polylang's, as Bootstrap::init() aborts while WPML, Polylang or TranslatePress is active. Renaming it
	 * needs a rewrite flush on every site.
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
		// Claim the flag before flushing (a flush is slow): delete_option() is true for exactly one
		// concurrent claimer, so a busy site does not flush in a herd.
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
	 * Temporarily override the current language for an internal rendering window.
	 *
	 * Order emails rendered outside the customer's request (admin, webhook, cron) need the ORDER's
	 * language. Callers MUST restore the returned value.
	 *
	 * @param object|null $language Language row to impose, or null.
	 * @return object|null Previous current language.
	 */
	public function override_current_language( ?object $language ): ?object {
		$previous               = self::$current_language;
		self::$current_language = $language;

		// UrlConverter memoizes per current language: signal the override and its restore (only on a change).
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
	 * Reset only the data-derived static caches, not the per-request detection state.
	 *
	 * Hooked to the language CRUD actions so a mid-request change applies to the rest of the request.
	 *
	 * @return void
	 */
	public static function reset_data_caches(): void {
		self::$default_language = null;
		self::$path_prefix_map  = null;
	}

	/**
	 * Language state parked by open switch_to_blog() calls: one frame per call, in depth order.
	 *
	 * Depth-ordered like core's `_wp_switched_stack`, NOT keyed by blog id: a re-entrant switch (core's
	 * get_home_url( $id ) makes one) would consume a frame an outer restore still needs. `blog` lets a
	 * restore prove the frame it pops is its own. See docs-ai/11-code-rationale.md, "LanguageRouter: blog switch stack".
	 *
	 * @var array<int, array{blog: int, current: ?object, default: ?object, final: bool}>
	 */
	private static array $blog_state_stack = [];

	/**
	 * Maximum frames kept in $blog_state_stack (legitimate nesting stays far below it).
	 */
	private const MAX_STACK_DEPTH = 32;

	/**
	 * Handle the switch_blog action.
	 *
	 * 'switch' parks the leaving blog's state and starts the entering blog clean (or copies a still-open
	 * frame of it, leaving the frame in place); 'restore' pops its own frame and parks nothing. A same-blog
	 * switch is a no-op. See docs-ai/11-code-rationale.md, "LanguageRouter: blog switch stack".
	 *
	 * @param int|string $new_blog_id  Target blog id.
	 * @param int|string $prev_blog_id Previous blog id.
	 * @param mixed      $context      'switch' or 'restore'. Untyped: the action is public, and anything
	 *     but 'restore' counts as a switch.
	 * @return void
	 */
	public static function maybe_reset_on_switch( $new_blog_id, $prev_blog_id, mixed $context = 'switch' ): void {
		// Third-party code may fire switch_blog with anything; is_numeric() rejects what the int cast would coerce.
		if ( ! is_numeric( $new_blog_id ) || ! is_numeric( $prev_blog_id ) ) {
			return;
		}

		$new  = (int) $new_blog_id;
		$prev = (int) $prev_blog_id;

		// Bail on self-switch (no-op) and on non-positive ids (malformed).
		if ( $new === $prev || $new < 1 || $prev < 1 ) {
			return;
		}

		if ( 'restore' === $context ) {
			$leaving_slug = self::$current_language?->slug ?? '';

			self::restore_blog_frame( $new );

			// Always drop the path-prefix lookup: it's derived from the prior
			// blog's slug_map and has no mapping back to a per-blog frame.
			self::$path_prefix_map = null;

			// Services that resolved against the language in effect on the
			// blog being left re-read the one put back.
			if ( ( self::$current_language?->slug ?? '' ) !== $leaving_slug ) {
				/**
				 * Fires when restore_current_blog() puts back a different current language.
				 *
				 * @hook perflocale/language/restored
				 */
				do_action( 'perflocale/language/restored' );
			}

			return;
		}

		// Defend against unbounded growth: at the cap, drop the OLDEST frame
		// before parking a new one. The frames about to be restored are the
		// deep ones, so the shallow end is what goes.
		if ( count( self::$blog_state_stack ) >= self::MAX_STACK_DEPTH ) {
			$evicted = array_shift( self::$blog_state_stack );

				// WP_DEBUG only: MAX_STACK_DEPTH switches are open without a restore, so the evicted blog's
				// language will not come back. The message names no culprit (it may be this plugin).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// Read through is_array(): $GLOBALS is `mixed` to static
				// analysis, and this only exists to put a number in a log line.
				$open = isset( $GLOBALS['_wp_switched_stack'] ) && is_array( $GLOBALS['_wp_switched_stack'] )
					? count( $GLOBALS['_wp_switched_stack'] )
					: 0;

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional WP_DEBUG diagnostic for stack-overflow detection; gated on WP_DEBUG_LOG above.
				error_log(
					sprintf(
						'PerfLocale: LanguageRouter parked %1$d blog-state frames with %2$d switch_to_blog() calls open; evicted the oldest (blog_id=%3$s), whose language state will be detected afresh instead of restored. switch_to_blog() calls outnumber restore_current_blog() calls in this request.',
						self::MAX_STACK_DEPTH,
						$open,
						(string) $evicted['blog']
					)
				);
			}
		}

		self::$blog_state_stack[] = [
			'blog'    => $prev,
			'current' => self::$current_language,
			'default' => self::$default_language,
			'final'   => self::$detection_finalized,
		];

		// Re-entering a blog still open lower down: copy its live state and LEAVE the frame for the restore
		// that parked it. A finished visit has no frame left, so that blog is detected afresh.
		$open_frame = self::find_open_frame( $new );

		if ( null !== $open_frame ) {
			self::$current_language    = $open_frame['current'];
			self::$default_language    = $open_frame['default'];
			self::$detection_finalized = $open_frame['final'];
		} else {
			// Clear the three state fields individually. Nothing here may
			// empty $blog_state_stack: the frame parked a few lines above is
			// what this switch's restore will come back for.
			self::$current_language    = null;
			self::$default_language    = null;
			self::$detection_finalized = false;
		}

		// Always drop the path-prefix lookup: it's derived from the prior
		// blog's slug_map and has no mapping back to a per-blog frame.
		self::$path_prefix_map = null;
	}

	/**
	 * Pop the frame parked by the switch this restore unwinds and put its language state back.
	 *
	 * A frame of another blog (drifted stacks: a hand-fired switch_blog, an eviction) is put back untouched
	 * and the blog starts fresh: detecting again is safe, another blog's language is not.
	 *
	 * @param int $new Blog id being returned to.
	 * @return void
	 */
	private static function restore_blog_frame( int $new ): void {
		$frame = array_pop( self::$blog_state_stack );

		if ( null !== $frame && $frame['blog'] === $new ) {
			self::$current_language    = $frame['current'];
			self::$default_language    = $frame['default'];
			self::$detection_finalized = $frame['final'];
			return;
		}

		if ( null !== $frame ) {
			self::$blog_state_stack[] = $frame;
		}

		self::$current_language    = null;
		self::$default_language    = null;
		self::$detection_finalized = false;
	}

	/**
	 * The frame of a blog whose switch_to_blog() is still open, if any.
	 *
	 * Scans at most MAX_STACK_DEPTH entries and only on a cross-blog switch,
	 * so the cost is bounded and off the per-request path.
	 *
	 * @param int $blog_id Blog id to look for.
	 * @return array{blog: int, current: ?object, default: ?object, final: bool}|null
	 */
	private static function find_open_frame( int $blog_id ): ?array {
		for ( $i = count( self::$blog_state_stack ) - 1; $i >= 0; $i-- ) {
			if ( self::$blog_state_stack[ $i ]['blog'] === $blog_id ) {
				return self::$blog_state_stack[ $i ];
			}
		}

		return null;
	}
}
