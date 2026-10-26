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
 * Redirects first-time visitors to their country's language, using a GeoIP provider a site registers by filter.
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
	 * Memo of get_country_map(); per instance, so a new instance reads the saved option again.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $country_map_cache = null;

	/**
	 * Failures within CB_WINDOW before the provider is skipped.
	 */
	private const CB_THRESHOLD = 3;

	/**
	 * Rolling window (seconds) over which failures trip the breaker.
	 */
	private const CB_WINDOW = 300; // 5 minutes.

	/**
	 * Skip-TTL applied once the breaker trips.
	 */
	private const CB_COOLDOWN = 900; // 15 minutes.

	/**
	 * Cloudflare's published edge IP ranges (https://www.cloudflare.com/ips/).
	 *
	 * A stale list fails safe: CF-Connecting-IP is ignored and X-Forwarded-For / REMOTE_ADDR are used.
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
	 * Built-in GeoIP provider definitions: none ship, sites add theirs through perflocale/geo/providers.
	 *
	 * @var array<string, array{name: string, needs_key: bool, key_setting: string}>
	 */
	private const PROVIDERS = [];

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Plugin settings.
	 * @param LanguageRouter    $router Language router.
	 * @param CacheManager|null $cache Optional cache manager for the lookup cache; the container's when null.
	 */
	public function __construct( Settings $settings, LanguageRouter $router, ?CacheManager $cache = null ) {
		$this->settings = $settings;
		$this->router   = $router;
		$this->cache    = $cache;

		// Geo breaker thresholds (CB_*) for built-in providers only; filtered providers get the shared defaults.
		self::register_geo_breaker_filters();
	}

	/**
	 * Give each built-in provider's breaker the CB_* thresholds, once per request.
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
	 * The injected CacheManager, else the plugin container's (null before it is ready).
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

		// Priority 9 plus the position of 'geo' in `redirect_priority_order`: the admin's first method runs first.
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

		// GET and HEAD only: a 302 would drop a form's POST body.
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( $method !== 'GET' && $method !== 'HEAD' ) {
			return;
		}

		// No 302 for feeds and the like (a cookieless poller would flip a feed's language).
		// URL-derived checks stay above nocache_headers(), so these responses stay cacheable.
		if ( is_feed() || is_trackback() || is_robots() || is_favicon() ) {
			return;
		}

		// Without a language cookie the redirect would repeat on every page. Config-derived: above nocache_headers().
		if ( $this->router->language_cookie_never_written() ) {
			return;
		}

		// Excluded paths: no redirect, still cacheable, and no IP is sent to the provider.
		if ( $this->router->is_excluded_request_path() ) {
			return;
		}

		// From here the 302-or-200 answer depends on the visitor, so a cached 200 would end the redirect for everyone:
		// no-cache before every visitor-specific decline below. The edge worker (assets/js/edge-helper.js) keeps both.
		if ( $this->router->is_default_language() && ! headers_sent() ) {
			nocache_headers();
		}

		// Only first-time visitors (no cookie yet).
		if ( isset( $_COOKIE['perflocale_lang'] ) ) {
			return;
		}

		/**
		 * Consent gate, shared with the browser-language redirect: false skips the IP lookup entirely.
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

		// Skip bots: LanguageRouter::is_bot_ua() is the one detection pattern.
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		if ( \PerfLocale\Router\LanguageRouter::is_bot_ua( $ua ) ) {
			return;
		}

		/**
		 * Final short-circuit before the IP lookup: false stops the redirect and the outbound lookup.
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

		// The shared builder: REQUEST_URI holds the home path, so home_url() around it doubles a subfolder prefix.
		$current_url  = \PerfLocale\Router\LanguageRouter::current_request_url();
		$redirect_url = $converter->convert( $current_url, $language_slug );

		if ( $redirect_url === '' || $redirect_url === $current_url ) {
			return;
		}

		// No redirect to a landing that would 404 or bounce back; the default-language cookie
		// detect_language() set on this request still marks the visitor as returning.
		if ( ! $this->router->first_visit_landing_serves( $language_slug ) ) {
			return;
		}

		// Set cookie before redirect.
		$this->router->set_language_cookie_public( $language_slug );

		/** @hook perflocale/geo/redirected Fires after a GeoIP redirect. */
		do_action( 'perflocale/geo/redirected', $language_slug, $country_code, $ip );

		// Append a sentinel so even cookie-blocked browsers don't redirect twice.
		$redirect_url = add_query_arg( 'perflocale_redirected', '1', $redirect_url );

		// Domain mode: allow the target host, or wp_safe_redirect() falls back to /wp-admin/.
		$this->allow_redirect_host( $redirect_url );

		// A cache that stores 3xx (Varnish, nginx) would otherwise pin one visitor's 302 on everyone.
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
	 * The cache first, then perflocale/geo/lookup_country, then the configured provider.
	 *
	 * @param string $ip Visitor IP address.
	 * @return string Two-letter country code (uppercase) or empty string.
	 */
	private function lookup_country( string $ip ): string {
		// Privacy: key on a salted HMAC of the anonymized /24 (or v6 prefix), never the IP; a hashed IP is reversible.
		$anon      = function_exists( 'wp_privacy_anonymize_ip' ) ? wp_privacy_anonymize_ip( $ip ) : $ip;
		$cache_key = 'ip_' . hash_hmac( 'sha256', (string) $anon, wp_salt( 'auth' ) );
		$cache     = $this->cache();

		if ( $cache instanceof CacheManager ) {
			// get_cached(), not get(): a miss writes nothing, so unseen IPs add no rows.
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

		// 0 hours: no cache, every request asks the provider.
		$cache_hours = (int) $this->settings->get( 'geo_cache_hours', 24 );

		if ( $cache_hours > 0 && $country_code !== '' && $cache instanceof CacheManager ) {
			// Positive results only: negative rows per client network would fill wp_options; the breaker keeps retries cheap.
			$ttl = $cache_hours * HOUR_IN_SECONDS;
			$cache->set( $cache_key, $country_code, $ttl, 'perflocale_geo_lookup' );
		}

		return $country_code;
	}

	/**
	 * Call the configured GeoIP provider API.
	 *
	 * A per-provider circuit breaker skips a failing provider instead of paying its timeout for every visitor.
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
	 * Whether this provider's breaker is open (skip the call); the shared Breaker, so Site Health lists it.
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
	 * Record a failed provider call; the breaker trips at its threshold within the window.
	 *
	 * @param string $provider_id Provider ID.
	 * @return void
	 */
	private function record_breaker_failure( string $provider_id ): void {
		\PerfLocale\Concurrency\Breaker::record_failure( 'geo_' . $provider_id, 'fetch_empty' );
	}

	/**
	 * Fetch from a custom provider registered via filter.
	 *
	 * The definition's `fetch_callback` receives the IP and the settings.
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
	 * From get_country_map(), through the perflocale/geo/country_map filter.
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
	 * Build the country-to-language mapping: saved geo_country_map codes, then the locale region of uncovered languages.
	 *
	 * Both skip the default language (mapping it over-redirects); a locale without a region (`ar`) is never derived.
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
		$covered   = []; // Slugs with a saved code are not derived.

		foreach ( $saved_map as $lang_slug => $codes_string ) {
			$lang_slug = (string) $lang_slug;

			// A stored default-language entry is dropped at read time too.
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

		// Derive from the locale for active non-default languages the saved map does not cover.
		foreach ( $lang_repo->get_active() as $lang ) {
			$slug = (string) ( $lang->slug ?? '' );

			if ( $slug === '' || $slug === $default_slug || isset( $covered[ $slug ] ) ) {
				continue;
			}

			$parts = explode( '_', (string) ( $lang->locale ?? '' ) );

			if ( ! isset( $parts[1] ) || ! preg_match( '/^[A-Za-z]{2}$/', $parts[1] ) ) {
				continue; // No region (`ar`): only the admin can map it.
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
	 * REMOTE_ADDR unless proxy headers are trusted and the peer is a trusted proxy.
	 *
	 * @return string IP address or empty string.
	 */
	private function get_visitor_ip(): string {
		// Proxy headers are client-controlled: used only with geo_trust_proxy_headers and a peer in geo_trusted_proxies.
		// Both settings stay out of the defaults and the UI on purpose (secure by default, opt in from code).
		$remote_addr = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );

		if ( ! (bool) $this->settings->get( 'geo_trust_proxy_headers', false ) ) {
			return $remote_addr;
		}

		if ( ! $this->is_request_from_trusted_proxy( $remote_addr ) ) {
			return $remote_addr;
		}

		// CF-Connecting-IP only from a Cloudflare edge peer: other proxies forward a client-sent copy verbatim.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && $this->is_cloudflare_ip( $remote_addr ) ) {
			$cf_ip = trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );

			if ( Helper::is_ip( $cf_ip ) ) {
				return $cf_ip;
			}
		}

		// X-Forwarded-For: the client is the rightmost hop that is not a trusted proxy (a client can only prepend).
		// Multi-tier chains must list every internal hop in geo_trusted_proxies.
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

		// X-Real-IP: one value the trusted proxy sets, used after the X-Forwarded-For walk.
		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$real_ip = trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_REAL_IP'] ) ) );

			if ( Helper::is_ip( $real_ip ) ) {
				return $real_ip;
			}
		}

		return $remote_addr;
	}

	/**
	 * Whether an address is in geo_trusted_proxies (bare IPs or IPv4/IPv6 CIDR blocks).
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
	 * Whether an IP is in Cloudflare's published edge ranges, so its CF-Connecting-IP header is genuine.
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
	 * Byte-wise prefix comparison of two packed `inet_pton` addresses of the same length.
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
		$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		return $lang_repo->get_slug_map();
	}

	/**
	 * Get the available provider definitions.
	 *
	 * No providers ship: the same perflocale/geo/providers filter as the lookup path, so the Settings picker agrees.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_providers(): array {
		/** This filter is documented in src/Router/GeoRedirect.php */
		return (array) apply_filters( 'perflocale/geo/providers', self::PROVIDERS );
	}
}
