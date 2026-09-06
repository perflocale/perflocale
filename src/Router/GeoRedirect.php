<?php
/**
 * GeoIP-based language redirect.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Helper;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects first-time visitors to their country's language version
 * based on IP geolocation.
 *
 * Supports multiple GeoIP providers (free and paid), caches lookups
 * per IP in transients, and provides filter hooks for custom providers
 * and country-to-language mapping.
 */
final class GeoRedirect {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var CacheManager|null
	 */
	private readonly ?CacheManager $cache;

	/**
	 * Per-request memo of the country → language slug map. Built lazily
	 * by `get_country_map()` on first call; subsequent calls within the
	 * same request return the cached array. Per-INSTANCE (not static) so
	 * tests that mutate the saved option between calls see fresh data
	 * after instantiating a new GeoRedirect.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $country_map_cache = null;

	/**
	 * Failures within CB_WINDOW before the provider is skipped.
	 */
	private const CB_THRESHOLD = 3;

	/**
	 * Rolling window (seconds) over which failures accumulate before
	 * tripping the breaker.
	 */
	private const CB_WINDOW = 300; // 5 minutes.

	/**
	 * Skip-TTL applied once the breaker trips.
	 */
	private const CB_COOLDOWN = 900; // 15 minutes.

	/**
	 * Cloudflare's published edge IP ranges (https://www.cloudflare.com/ips/).
	 *
	 * CF-Connecting-IP is only trustworthy when the immediate peer really is
	 * Cloudflare: a generic reverse proxy forwards a client-sent header of that
	 * name verbatim. Authenticating REMOTE_ADDR against this list closes that
	 * spoofing hole. A stale list fails SAFE — CF-Connecting-IP is simply
	 * ignored and detection falls back to X-Forwarded-For / REMOTE_ADDR.
	 *
	 * @var array<int, string>
	 */
	private const CLOUDFLARE_IP_RANGES = [
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	];

	/**
	 * Built-in GeoIP provider definitions.
	 *
	 * @var array<string, array{name: string, needs_key: bool, key_setting: string}>
	 */
	private const PROVIDERS = [];

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Plugin settings.
	 * @param LanguageRouter    $router Language router.
	 * @param CacheManager|null $cache Optional cache manager for circuit-
	 *                                 breaker state; falls back to the
	 *                                 plugin container when null.
	 */
	public function __construct( Settings $settings, LanguageRouter $router, ?CacheManager $cache = null ) {
		$this->settings = $settings;
		$this->router   = $router;
		$this->cache    = $cache;

		// Preserve the geo-specific breaker thresholds (3 fails, 15min
		// cooldown — more conservative than the shared default of 5/5min
		// because a single missed country lookup costs the visitor a
		// wrong-language landing). Registered once per request via the
		// static flag; subsequent GeoRedirect constructions are no-ops.
		self::register_geo_breaker_filters();
	}

	/**
	 * Hook per-key breaker threshold/cooldown filters so geo_* breakers
	 * keep the original GeoRedirect-specific tuning even after the
	 * migration to the shared {@see PerfLocale\Concurrency\Breaker}
	 * primitive.
	 *
	 * Idempotent — a process-wide static flag guards against re-adding
	 * the filters when multiple GeoRedirect instances are constructed
	 * in the same request.
	 *
	 * @return void
	 */
	private static function register_geo_breaker_filters(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		foreach ( array_keys( self::PROVIDERS ) as $pid ) {
			$key = 'geo_' . $pid;
			add_filter( 'perflocale/breaker/threshold/' . $key, static fn(): int => self::CB_THRESHOLD );
			add_filter( 'perflocale/breaker/window_seconds/' . $key, static fn(): int => self::CB_WINDOW );
			add_filter( 'perflocale/breaker/cooldown_seconds/' . $key, static fn(): int => self::CB_COOLDOWN );
		}
	}

	/**
	 * Lazily resolve a CacheManager instance.
	 *
	 * Prefers the one injected at construction time; falls back to the
	 * plugin container so existing callers that don't pass a cache
	 * instance still get the circuit-breaker behaviour.
	 *
	 * @return CacheManager|null
	 */
	private function cache(): ?CacheManager {
		if ( $this->cache instanceof CacheManager ) {
			return $this->cache;
		}

		try {
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'cache' ) ) {
				$resolved = $plugin->get( 'cache' );
				return $resolved instanceof CacheManager ? $resolved : null;
			}
		} catch ( \Throwable $e ) {
			// Plugin container may not be ready on very early requests.
			unset( $e );
		}

		return null;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! (bool) $this->settings->get( 'redirect_geo_enabled' ) ) {
			return;
		}

		// Priority comes from `redirect_priority_order` so the admin's chosen
		// first method runs first when both this and the browser-language
		// redirect are enabled. Default order is ['geo','browser'] which
		// resolves to priority 9 here (matching the previous hard-coded value).
		$order    = $this->settings->get_redirect_priority_order();
		$idx      = array_search( 'geo', $order, true );
		$priority = $idx !== false ? 9 + (int) $idx : 10;
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], $priority );
	}

	/**
	 * Redirect first-time visitors based on their IP country.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		// Only on frontend page requests.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return;
		}

		// Body-preserving methods only - a 302 would drop the POST payload
		// and silently turn form submissions from anonymous first-time
		// visitors into orphaned GETs on the language-prefixed URL.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		// Feeds, robots.txt, favicon and trackbacks reach template_redirect
		// before core handles them; a per-visitor geo 302 must not hijack them
		// (a cookieless feed poller from a mapped country would flip a
		// subscribed feed's language on every poll). URL-derived, so it stays
		// ABOVE nocache_headers() to keep those responses cacheable.
		if ( is_feed() || is_trackback() || is_robots() || is_favicon() ) {
			return;
		}

		// Pages the admin excluded from language routing must not be
		// geo-redirected either (single-language campaign landing pages).
		// URL-derived — stays above nocache_headers() so excluded pages
		// keep full-page cacheability, and above the geo lookup so no IP
		// is ever sent to the provider for an excluded page.
		if ( $this->router->is_excluded_request_path() ) {
			return;
		}

		// From here on, whether this response is a 302 or a 200 depends on
		// the INDIVIDUAL visitor (cookie, consent, bot UA, their country).
		// If a full-page cache stores any of those 200s under the entry URL
		// (a bot, a cookied visitor, or a same-country visitor primes it),
		// PHP never runs for later visitors and the geo redirect is
		// silently dead. This must fire BEFORE the visitor-specific
		// declines below — only URL-derived conditions above it. Enabling
		// geo redirect therefore trades cacheability of default-language
		// entry pages for a redirect that actually fires; operators who
		// need both should deploy the edge worker (assets/js/edge-helper.js).
		if ( $this->router->is_default_language() && ! headers_sent() ) {
			nocache_headers();
		}

		// Only first-time visitors (no cookie yet).
		if ( isset( $_COOKIE['perflocale_lang'] ) ) {
			return;
		}

		/**
		 * Consent gate - shared with the browser-language redirect.
		 * Consent plugins return false while the visitor hasn’t accepted
		 * non-strictly-necessary processing, suppressing the IP lookup
		 * entirely (no request to the GeoIP provider is made).
		 *
		 * @hook perflocale/privacy/consent_given
		 * @param bool $granted Default true.
		 */
		if ( ! (bool) apply_filters( 'perflocale/privacy/consent_given', true ) ) {
			return;
		}

		// Redirect fuse: skip if we already redirected this navigation step.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_redirected'] ) ) {
			return;
		}

		// Only when URL resolved to the default language.
		if ( ! $this->router->is_default_language() ) {
			return;
		}

		// Skip bots / crawlers. Single source of truth for the detection
		// pattern lives in LanguageRouter::is_bot_ua().
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		if ( \PerfLocale\Router\LanguageRouter::is_bot_ua( $ua ) ) {
			return;
		}

		/**
		 * Final short-circuit before the IP lookup is performed.
		 *
		 * Integrators commonly want to skip GeoIP redirects for authenticated
		 * users (they already picked a language), for specific URL patterns
		 * (/api/*, /webhook/*), or for returning visitors that were brought
		 * back by a campaign link. Returning false here stops the redirect
		 * and, crucially, avoids the outbound IP-lookup request.
		 *
		 * @hook perflocale/geo/should_redirect
		 *
		 * @param bool $should_redirect Default true.
		 */
		if ( ! (bool) apply_filters( 'perflocale/geo/should_redirect', true ) ) {
			return;
		}

		// Get visitor IP.
		$ip      = $this->get_visitor_ip();
		$real_ip = $ip;

		/** @hook perflocale/geo/visitor_ip Override the visitor IP (useful for testing on localhost). */
		$ip = (string) apply_filters( 'perflocale/geo/visitor_ip', $ip );

		// Skip local/private IPs unless the IP was overridden via filter.
		if ( $ip === '' || ( $ip === $real_ip && $this->is_local_ip( $ip ) ) ) {
			return;
		}

		// Lookup country code (cached).
		$country_code = $this->lookup_country( $ip );

		if ( $country_code === '' ) {
			return;
		}

		/** @hook perflocale/geo/country_code Modify the detected country code after GeoIP lookup. */
		$country_code = (string) apply_filters( 'perflocale/geo/country_code', $country_code, $ip );

		if ( $country_code === '' ) {
			return;
		}

		// Map country to language.
		$language_slug = $this->map_country_to_language( strtoupper( $country_code ) );

		/** @hook perflocale/geo/redirect_language Override which language to redirect to. */
		$language_slug = (string) apply_filters( 'perflocale/geo/redirect_language', $language_slug, $country_code, $ip );

		if ( $language_slug === '' ) {
			return;
		}

		// Check that the language is active and not the default.
		$default = $this->router->get_default_language();

		if ( $default && $language_slug === $default->slug ) {
			return;
		}

		$slug_map = $this->get_slug_map();

		if ( ! isset( $slug_map[ $language_slug ] ) ) {
			return;
		}

		// Build redirect URL.
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return;
		}

		/** @var UrlConverter $converter */
		$converter = $plugin->get( 'url_converter' );

		// Current URL via the shared builder: REQUEST_URI already contains the
		// home path, so a plain home_url( add_query_arg( [] ) ) doubles a
		// subfolder install's prefix. See LanguageRouter::current_request_url().
		$current_url  = \PerfLocale\Router\LanguageRouter::current_request_url();
		$redirect_url = $converter->convert( $current_url, $language_slug );

		if ( $redirect_url === '' || $redirect_url === $current_url ) {
			return;
		}

		// Set cookie before redirect.
		$this->router->set_language_cookie_public( $language_slug );

		/** @hook perflocale/geo/redirected Fires after a GeoIP redirect. */
		do_action( 'perflocale/geo/redirected', $language_slug, $country_code, $ip );

		// Append a sentinel so even cookie-blocked browsers don't redirect twice.
		$redirect_url = add_query_arg( 'perflocale_redirected', '1', $redirect_url );

		// This 302 is a per-visitor language decision (geo/cookie-driven) on a
		// shared URL — mark it uncacheable so an edge/server cache that stores
		// 3xx (Varnish, nginx fastcgi_cache) can't pin one visitor's redirect
		// onto everyone. Plugin page caches already skip it via the Set-Cookie
		// above; this covers caches that ignore Set-Cookie on redirects.
		// In domain/subdomain url-mode the target lives on a different host;
		// whitelist it for this single redirect so wp_safe_redirect() doesn't
		// reject the cross-host URL and fall back to /wp-admin/. No-op in
		// subdirectory mode (same host is already allowed).
		$this->allow_redirect_host( $redirect_url );

		nocache_headers();
		wp_safe_redirect( $redirect_url, 302 );
		exit;
	}

	/**
	 * Whitelist a redirect target's host for one wp_safe_redirect() call.
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
	 * Look up the country code for an IP address.
	 *
	 * Checks transient cache first, then calls the configured provider.
	 * Custom providers can bypass built-in ones via the
	 * perflocale/geo/lookup_country filter.
	 *
	 * @param string $ip Visitor IP address.
	 * @return string Two-letter country code (uppercase) or empty string.
	 */
	private function lookup_country( string $ip ): string {
		// Cache-key derivation is privacy-load-bearing: a bare md5(ip) is
		// reversible over the 4-billion-address IPv4 space, so persisting it
		// (options table / Redis, 24h) stores personal data. Anonymize the
		// host bits first (core's /24 / v6-prefix zeroing — country lookups
		// are stable at that granularity) and HMAC with a site salt so the
		// stored key can't be dictionary-reversed offline. Bonus: one entry
		// now serves the whole /24, cutting provider lookups.
		$anon      = function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $ip ) : $ip;
		$cache_key = 'ip_' . hash_hmac( 'sha256', (string) $anon, wp_salt( 'auth' ) );
		$cache     = $this->cache();

		// 3-layer cache lookup via CacheManager. If the cache container
		// isn't available (very rare — only on early-boot edge paths) we
		// fall through and query the provider uncached.
		if ( $cache instanceof CacheManager ) {
			// Read-only: only POSITIVE results are ever written (see set() below),
			// so any non-empty hit is a real country code — no sentinel needed.
			// get_cached() (not get()) means a MISS writes nothing, so a flood of
			// spoofed X-Forwarded-For IPs can't pile up negative-result rows.
			$cached = $cache->get_cached( $cache_key, 'perflocale_geo_lookup' );

			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		}

		/**
		 * Let custom providers return a country code, bypassing built-in providers.
		 *
		 * @hook perflocale/geo/lookup_country
		 * @param string $country_code Empty string (return a 2-letter code to skip built-in providers).
		 * @param string $ip Visitor IP address.
		 */
		$country_code = (string) apply_filters( 'perflocale/geo/lookup_country', '', $ip );

		if ( $country_code === '' ) {
			$country_code = $this->call_provider( $ip );
		}

		$country_code = strtoupper( sanitize_key( $country_code ) );

		// Cache the result. Two-tier TTL:
		// - Successful lookup (non-empty country): full configured window.
		// - Empty result (provider 429 / 5xx / network hiccup / bad IP):
		// short window so transient provider failures recover quickly
		// instead of locking visitors out for the full cache duration.
		// 0 = caching disabled - every request queries the provider.
		$cache_hours = (int) $this->settings->get( 'geo_cache_hours', 24 );

		if ( $cache_hours > 0 && $country_code !== '' && $cache instanceof CacheManager ) {
			// Only cache POSITIVE results. Empty-result writes are skipped:
			// on no-external-object-cache sites every wp_options write costs
			// 2 rows (transient value + transient timeout), and the cache
			// key is derived from $ip — an attacker spoofing thousands of
			// distinct X-Forwarded-For headers would otherwise flood
			// wp_options with negative-result rows until the daily GC sweep
			// reaps them. Inline-fail recovery still works because L1
			// (in-request static) prevents repeated provider calls within a
			// single request; subsequent requests just re-call the provider
			// (cheap when the breaker is OPEN, sub-µs short-circuit there).
			$ttl = $cache_hours * HOUR_IN_SECONDS;
			$cache->set( $cache_key, $country_code, $ttl, 'perflocale_geo_lookup' );
		}

		return $country_code;
	}

	/**
	 * Call the configured GeoIP provider API.
	 *
	 * A per-provider circuit breaker sits in front of the actual fetch: if
	 * the provider has recently returned empty/errored {@see self::CB_THRESHOLD}
	 * times within {@see self::CB_WINDOW}, subsequent requests short-circuit
	 * for {@see self::CB_COOLDOWN} instead of paying the 5-second HTTP
	 * timeout on every new visitor.
	 *
	 * @param string $ip IP address.
	 * @return string Two-letter country code or empty string.
	 */
	private function call_provider( string $ip ): string {
		$provider_id = (string) $this->settings->get( 'geo_provider', '' );

		/**
		 * Filter available GeoIP providers.
		 *
		 * @hook perflocale/geo/providers
		 * @param array $providers Provider definitions.
		 */
		$providers = apply_filters( 'perflocale/geo/providers', self::PROVIDERS );

		if ( ! isset( $providers[ $provider_id ] ) ) {
			return '';
		}

		$provider = $providers[ $provider_id ];

		// Check if provider needs a key and one is configured.
		if ( ! empty( $provider['needs_key'] ) && ! empty( $provider['key_setting'] ) ) {
			$key = (string) $this->settings->get( $provider['key_setting'], '' );

			if ( $key === '' ) {
				return '';
			}
		}

		// Circuit-breaker: skip outright while the cooldown is active.
		if ( $this->is_breaker_open( $provider_id ) ) {
			return '';
		}

		$result = $this->fetch_custom_provider( $provider_id, $provider, $ip );

		if ( $result !== '' ) {
			$this->record_breaker_success( $provider_id );
		} else {
			$this->record_breaker_failure( $provider_id );
		}

		return $result;
	}

	/**
	 * Check whether the circuit breaker for this provider is currently open
	 * (i.e., we should skip the HTTP call).
	 *
	 * Delegates to the shared {@see PerfLocale\Concurrency\Breaker} so the
	 * Site Health "circuit breakers" card lists every open breaker in one
	 * place (MT, webhooks, FX sync, geo, scoring). Same transient-backed
	 * storage so cache eviction behaviour matches.
	 *
	 * @param string $provider_id Provider ID.
	 * @return bool
	 */
	private function is_breaker_open( string $provider_id ): bool {
		return \PerfLocale\Concurrency\Breaker::is_open( 'geo_' . $provider_id );
	}

	/**
	 * Record a successful provider call and clear failure tracking.
	 *
	 * @param string $provider_id Provider ID.
	 * @return void
	 */
	private function record_breaker_success( string $provider_id ): void {
		\PerfLocale\Concurrency\Breaker::record_success( 'geo_' . $provider_id );
	}

	/**
	 * Record a failed provider call and trip the breaker when the threshold
	 * is reached inside the rolling window.
	 *
	 * @param string $provider_id Provider ID.
	 * @return void
	 */
	private function record_breaker_failure( string $provider_id ): void {
		\PerfLocale\Concurrency\Breaker::record_failure( 'geo_' . $provider_id, 'fetch_empty' );
	}

	/**
	 * Validate a wp_remote_get response and decode its JSON body.
	 *
	 * All GeoIP providers share the same response shape: a JSON object
	 * with a country-code field. They MUST also share the same failure
	 * handling - a 429 (rate-limit) or 5xx response typically returns
	 * an HTML error page, which `json_decode` happily parses as null,
	 * returning empty country and silently breaking language detection
	 * for the visitor's entire session. Centralising the response
	 * validation here closes that gap across every provider.
	 *
	 * @param mixed $response wp_remote_get return value.
	 * @return array<string, mixed>|null Decoded body or null on any failure.
	 */
	private function decode_json_response( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Fetch from a custom provider registered via filter.
	 *
	 * Custom providers should add a 'fetch_callback' key to their definition.
	 *
	 * @param string               $provider_id Provider ID.
	 * @param array<string, mixed> $provider Provider definition.
	 * @param string               $ip IP address.
	 * @return string Country code.
	 */
	private function fetch_custom_provider( string $provider_id, array $provider, string $ip ): string {
		if ( ! empty( $provider['fetch_callback'] ) && is_callable( $provider['fetch_callback'] ) ) {
			try {
				return (string) call_user_func( $provider['fetch_callback'], $ip, $this->settings );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'PerfLocale GeoIP provider "%s" error: %s', $provider_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return '';
			}
		}

		return '';
	}

	/**
	 * Map a country code to a language slug.
	 *
	 * Uses the geo_country_map setting, with auto-population from
	 * active language flag fields as fallback.
	 *
	 * @param string $country_code Two-letter uppercase country code.
	 * @return string Language slug or empty string.
	 */
	private function map_country_to_language( string $country_code ): string {
		$map = $this->get_country_map();

		/** @hook perflocale/geo/country_map Filter the country-to-language mapping. */
		$map = (array) apply_filters( 'perflocale/geo/country_map', $map );

		return $map[ $country_code ] ?? '';
	}

	/**
	 * Build the country-to-language mapping.
	 *
	 * Two layers, in order:
	 *   1. Saved `geo_country_map` setting — explicit per-language ISO
	 *      country codes. Filtered server-side: the default language is
	 *      dropped (an explicit default mapping over-redirects), and
	 *      non-ISO entries are dropped (defends against legacy emoji
	 *      saves from before this hardening pass).
	 *   2. Locale-derived fallback — for languages NOT covered by the
	 *      saved map AND whose `locale` carries an ISO country part
	 *      (`en_US` → `US`, `de_DE` → `DE`). The default language is
	 *      excluded here too.
	 *
	 * Generic-locale languages (e.g. `ar` with no `_XX` suffix) are
	 * intentionally not auto-derived — the admin must configure them
	 * explicitly because there's no single right answer (Arabic spans
	 * SA/EG/AE/JO/MA/DZ/etc.).
	 *
	 * @return array<string, string> ISO country code (uppercase) => language slug.
	 */
	private function get_country_map(): array {
		if ( $this->country_map_cache !== null ) {
			return $this->country_map_cache;
		}

		$cache        = \PerfLocale\Plugin::get_instance()->get( 'cache' );
		$lang_repo    = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		$default      = $lang_repo->get_default();
		$default_slug = is_object( $default ) && ! empty( $default->slug ) ? (string) $default->slug : '';

		$active_slugs = [];
		foreach ( $lang_repo->get_active() as $l ) {
			if ( ! empty( $l->slug ) ) {
				$active_slugs[ (string) $l->slug ] = true;
			}
		}

		$saved_map = (array) $this->settings->get( 'geo_country_map', [] );
		$map       = [];
		$covered   = []; // slugs that already have at least one mapped code → skip auto-derive for these.

		foreach ( $saved_map as $lang_slug => $codes_string ) {
			$lang_slug = (string) $lang_slug;

			// Defense in depth: even if a stale option still has a
			// default-language entry from before the save-time guard,
			// silently drop it at read time so old data doesn't override
			// the "stays on default" behaviour.
			if ( $default_slug !== '' && $lang_slug === $default_slug ) {
				continue;
			}

			if ( ! isset( $active_slugs[ $lang_slug ] ) ) {
				continue;
			}

			foreach ( explode( ',', (string) $codes_string ) as $token ) {
				$code = strtoupper( trim( $token ) );

				if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
					$map[ $code ]          = $lang_slug;
					$covered[ $lang_slug ] = true;
				}
			}
		}

		// Auto-derive from `locale` for any active non-default language
		// the saved map didn't cover.
		foreach ( $lang_repo->get_active() as $lang ) {
			$slug = (string) ( $lang->slug ?? '' );

			if ( $slug === '' || $slug === $default_slug || isset( $covered[ $slug ] ) ) {
				continue;
			}

			$parts = explode( '_', (string) ( $lang->locale ?? '' ) );

			if ( ! isset( $parts[1] ) || ! preg_match( '/^[A-Za-z]{2}$/', $parts[1] ) ) {
				continue; // generic locale like `ar` — admin must configure explicitly.
			}

			$code = strtoupper( substr( $parts[1], 0, 2 ) );

			if ( ! isset( $map[ $code ] ) ) {
				$map[ $code ] = $slug;
			}
		}

		$this->country_map_cache = $map;

		return $map;
	}

	/**
	 * Get the visitor's real IP address.
	 *
	 * Supports proxies via X-Forwarded-For and CF-Connecting-IP headers.
	 *
	 * @return string IP address or empty string.
	 */
	private function get_visitor_ip(): string {
		// Proxy headers are ATTACKER-CONTROLLABLE on a default WordPress
		// install — any client can set X-Forwarded-For / CF-Connecting-IP /
		// X-Real-IP on their request, and WordPress passes them through.
		// Trusting them by default means a single attacker can rotate
		// thousands of distinct spoofed IPs, exhausting the GeoIP provider
		// quota AND (without the negative-result cache-write skip in
		// lookup_country) bloating wp_options with one row per spoofed IP.
		//
		// Site operators who run behind a real reverse proxy (Cloudflare,
		// Nginx with proxy_set_header, AWS ALB) must opt in by setting
		// `geo_trust_proxy_headers => true` AND providing a
		// `geo_trusted_proxies` CIDR allow-list. The proxy header is only
		// honoured when REMOTE_ADDR is inside the allow-list (i.e. the
		// request actually came from the configured reverse proxy).
		//
		// Without opt-in we fall back to REMOTE_ADDR, which the web server
		// sets from the TCP socket peer and cannot be spoofed at the
		// application layer. Loses Cloudflare/ALB awareness for non-opted-
		// in sites; trades closed-by-default for accuracy on those edges.
		$remote_addr = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );

		if ( ! (bool) $this->settings->get( 'geo_trust_proxy_headers', false ) ) {
			return $remote_addr;
		}

		if ( ! $this->is_request_from_trusted_proxy( $remote_addr ) ) {
			return $remote_addr;
		}

		// Cloudflare — CF-Connecting-IP is a single, chain-less value that only
		// Cloudflare sets authoritatively (it strips any client-sent copy). A
		// generic nginx/ALB forwards a client-sent header of the same name
		// verbatim, so honour it ONLY when the immediate peer is a published
		// Cloudflare edge address; otherwise it is attacker-controllable.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && $this->is_cloudflare_ip( $remote_addr ) ) {
			$cf_ip = trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );

			if ( Helper::is_ip( $cf_ip ) ) {
				return $cf_ip;
			}
		}

		// X-Forwarded-For — a well-behaved proxy APPENDS the address it saw, so
		// the real client is the RIGHTMOST entry that is not itself one of our
		// trusted proxies. Walking right-to-left and skipping trusted hops
		// discards any addresses the client prepended (they sit to the left of
		// what the first trusted proxy appended). Operators with multi-tier
		// chains must list every internal hop's CIDR in geo_trusted_proxies,
		// else resolution stops at the first unlisted (inner) proxy.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$hops = explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

			for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
				$hop = trim( $hops[ $i ] );

				if ( $hop === '' || ! Helper::is_ip( $hop ) ) {
					continue;
				}

				if ( $this->is_request_from_trusted_proxy( $hop ) ) {
					continue;
				}

				return $hop;
			}
		}

		// X-Real-IP — single value set by the trusted proxy itself (nginx
		// proxy_set_header). Trust it only as a direct value from that proxy,
		// validated, after the forgery-resistant X-Forwarded-For walk.
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$real_ip = trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_REAL_IP'] ) ) );

			if ( Helper::is_ip( $real_ip ) ) {
				return $real_ip;
			}
		}

		return $remote_addr;
	}

	/**
	 * Check whether REMOTE_ADDR is inside the configured trusted-proxy
	 * CIDR allow-list. Supports both bare IPs (`203.0.113.10`) and CIDR
	 * blocks (`10.0.0.0/8`, `192.168.0.0/16`, IPv6 `2001:db8::/32`).
	 *
	 * @param string $remote_addr REMOTE_ADDR for the current request.
	 * @return bool
	 */
	private function is_request_from_trusted_proxy( string $remote_addr ): bool {
		if ( $remote_addr === '' ) {
			return false;
		}

		$cidrs = (array) $this->settings->get( 'geo_trusted_proxies', [] );

		if ( $cidrs === [] ) {
			return false;
		}

		$remote_packed = @inet_pton( $remote_addr ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton returns false on invalid input; we check that explicitly.

		if ( $remote_packed === false ) {
			return false;
		}

		foreach ( $cidrs as $cidr ) {
			$cidr = trim( (string) $cidr );
			if ( $cidr === '' ) {
				continue;
			}

			[ $subnet, $bits ] = array_pad( explode( '/', $cidr, 2 ), 2, null );

			$subnet_packed = @inet_pton( (string) $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same false-on-invalid contract.
			if ( $subnet_packed === false || strlen( $subnet_packed ) !== strlen( $remote_packed ) ) {
				continue;
			}

			$total_bits = strlen( $subnet_packed ) * 8;
			$bits       = $bits === null ? $total_bits : max( 0, min( $total_bits, (int) $bits ) );

			if ( $this->packed_ip_in_subnet( $remote_packed, $subnet_packed, $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IP falls inside Cloudflare's published edge ranges — used to
	 * authenticate that a CF-Connecting-IP header genuinely came from
	 * Cloudflare (REMOTE_ADDR is a CF edge) rather than a client forgery
	 * forwarded by a generic proxy.
	 *
	 * @param string $ip Candidate address (REMOTE_ADDR).
	 * @return bool
	 */
	private function is_cloudflare_ip( string $ip ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton returns false on invalid input; we check that explicitly.

		if ( $packed === false ) {
			return false;
		}

		foreach ( self::CLOUDFLARE_IP_RANGES as $cidr ) {
			[ $subnet, $bits ] = array_pad( explode( '/', $cidr, 2 ), 2, null );

			$subnet_packed = @inet_pton( (string) $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same false-on-invalid contract.
			if ( $subnet_packed === false || strlen( $subnet_packed ) !== strlen( $packed ) ) {
				continue;
			}

			$total_bits = strlen( $subnet_packed ) * 8;
			$bits       = $bits === null ? $total_bits : max( 0, min( $total_bits, (int) $bits ) );

			if ( $this->packed_ip_in_subnet( $packed, $subnet_packed, $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Byte-wise prefix comparison for IPv4/IPv6 in their packed `inet_pton`
	 * form. $a and $b must be the same length (both 4 or both 16 bytes).
	 *
	 * @param string $a Packed candidate address.
	 * @param string $b Packed subnet address.
	 * @param int    $bits Prefix length in bits.
	 * @return bool
	 */
	private function packed_ip_in_subnet( string $a, string $b, int $bits ): bool {
		$bytes     = intdiv( $bits, 8 );
		$remainder = $bits % 8;

		if ( $bytes > 0 && strncmp( $a, $b, $bytes ) !== 0 ) {
			return false;
		}

		if ( $remainder === 0 ) {
			return true;
		}

		$mask = chr( 0xFF << ( 8 - $remainder ) );
		return ( $a[ $bytes ] & $mask ) === ( $b[ $bytes ] & $mask );
	}

	/**
	 * Check if an IP is a local/private address (skip GeoIP for these).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private function is_local_ip( string $ip ): bool {
		return ! Helper::is_public_ipv4( $ip );
	}

	/**
	 * Get the active language slug map.
	 *
	 * @return array<string, object>
	 */
	private function get_slug_map(): array {
		$cache     = \PerfLocale\Plugin::get_instance()->get( 'cache' );
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		return $lang_repo->get_slug_map();
	}

	/**
	 * Get the available provider definitions.
	 *
	 * No providers ship with the plugin — the list is whatever the site
	 * registers through `perflocale/geo/providers`. The same filter is
	 * applied on the lookup path, so the admin picker and the runtime
	 * resolver always agree on which providers exist; without it a
	 * site-registered provider could never be selected in Settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_providers(): array {
		/** This filter is documented in src/Router/GeoRedirect.php */
		return (array) apply_filters( 'perflocale/geo/providers', self::PROVIDERS );
	}
}
