<?php
/**
 * Public configuration REST endpoint - used by edge workers.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /wp-json/perflocale/v1/config` - publishes the minimum set of
 * routing + language info an edge worker (Cloudflare Worker, Vercel
 * Edge, Netlify Edge) needs to pre-route a visitor before the request
 * ever hits PHP.
 *
 * ## Public by default
 *
 * The endpoint is public — all the data it returns is already visible
 * in the rendered HTML (hreflang tags, language switcher, URL prefixes)
 * — but it's gated behind the `edge_integration_enabled` setting so
 * sites that don't use an edge runtime don't expose an extra surface.
 *
 * **The response NEVER contains:** API keys, machine-translation provider
 * tokens, user data, internal post/term IDs, or any data not already
 * observable from the public site. That non-sensitive invariant is what
 * justifies the public default. If extended via the
 * {@see perflocale/api/config} filter, callers MUST preserve this invariant.
 *
 * ## Restricting access
 *
 * Site owners who still want to gate the endpoint (e.g. private staging,
 * IP allowlist, Application-Password auth) hook the
 * {@see perflocale/edge_worker/config_permission_callback} filter:
 *
 *     // Restrict to logged-in admins (works with Application Passwords).
 *     add_filter( 'perflocale/edge_worker/config_permission_callback', function () {
 *         return current_user_can( 'manage_options' );
 *     } );
 *
 *     // OR: IP allowlist for known edge-worker origins.
 *     add_filter( 'perflocale/edge_worker/config_permission_callback', function () {
 *         return in_array( $_SERVER['REMOTE_ADDR'] ?? '', [ '203.0.113.10' ], true );
 *     } );
 *
 * The default permission_callback returns `true` (public read).
 *
 * ## Caching
 *
 * Response is aggressively cacheable: hosts can hold it on their own
 * edge for an hour while we respect a 5-minute browser cache, plus a
 * 1-day stale-while-revalidate window. ETag + If-None-Match → 304
 * revalidation is implemented so edges can refresh cheaply.
 */
final class ConfigController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'config';

	/**
	 * 3-layer cache group used for the endpoint payload.
	 */
	private const CACHE_GROUP = 'perflocale';

	/**
	 * Cache key within the group.
	 */
	private const CACHE_KEY = 'edge_config_v1';

	/**
	 * Payload cache TTL (seconds). Invalidated eagerly on language or
	 * settings changes, so this is a ceiling not a staleness budget.
	 */
	private const CACHE_TTL = 900; // 15 minutes.

	/**
	 * Register REST routes.
	 *
	 * Only called when `edge_integration_enabled` is on - Bootstrap
	 * gates the container so `register_hooks()` / `register_routes()`
	 * never runs on sites that haven't opted in.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_config' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);
	}

	/**
	 * Permission gate for the public config endpoint.
	 *
	 * Default: public read. The payload contains only non-sensitive routing
	 * + language metadata (slugs, locales, URL mode, default language) that
	 * is already observable from the rendered HTML, hreflang tags, and
	 * public language switcher — there is nothing to gate by default.
	 *
	 * Site owners who want to restrict it (private staging, IP allowlist,
	 * Application-Password auth, mTLS proxy, etc.) filter this callback:
	 *
	 *     add_filter( 'perflocale/edge_worker/config_permission_callback',
	 *         fn () => current_user_can( 'manage_options' ) );
	 *
	 * Returning `false` or a `WP_Error` produces the standard WP-REST 401/
	 * 403 response. Returning `true` (the default) keeps the endpoint open.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error
	 */
	public function permission_check( \WP_REST_Request $request ) {
		/**
		 * Filter the permission_callback for the edge-worker config endpoint.
		 *
		 * Default is `true` (public read). The endpoint exposes only data
		 * that is already observable from the rendered site — see the class
		 * docblock for the non-sensitive invariant. Return `false`, a
		 * `WP_Error`, or the result of `current_user_can()` to restrict.
		 *
		 * @hook perflocale/edge_worker/config_permission_callback
		 * @param bool|\WP_Error   $allowed Default true.
		 * @param \WP_REST_Request $request The REST request.
		 */
		$allowed = apply_filters(
			'perflocale/edge_worker/config_permission_callback',
			true,
			$request
		);

		// Type-safety contract: filter must return bool or WP_Error. A
		// non-conformant return is a definite developer mistake — silently
		// coercing to true would defeat the gate; coercing to false would
		// 403 every edge worker. Surface the bug + fall back to the safe
		// default (preserve the documented public-read behavior).
		if ( ! is_bool( $allowed ) && ! ( $allowed instanceof \WP_Error ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/edge_worker/config_permission_callback", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/edge_worker/config_permission_callback returned %s — must be bool or WP_Error. Falling back to the default public-read permission.', 'perflocale' ),
						get_debug_type( $allowed )
					)
				),
				'1.0.0'
			);
			$allowed = true;
		}

		return $allowed;
	}

	/**
	 * Handler: returns the public config payload with cache-friendly headers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_config( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $this->build_payload();
		$etag    = '"' . md5( (string) wp_json_encode( $payload ) ) . '"';

		// Respect If-None-Match - lets edges revalidate with a cheap 304.
		$if_none_match = $request->get_header( 'if_none_match' );

		if ( is_string( $if_none_match ) && trim( $if_none_match ) === $etag ) {
			$response = new \WP_REST_Response( null, 304 );
			$response->header( 'ETag', $etag );
			$response->header( 'Cache-Control', 'public, max-age=300, s-maxage=3600, stale-while-revalidate=86400' );

			return $response;
		}

		$response = new \WP_REST_Response( $payload, 200 );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=300, s-maxage=3600, stale-while-revalidate=86400' );

		return $response;
	}

	/**
	 * Build the payload with 3-layer caching.
	 *
	 * @return array<string, mixed>
	 */
	private function build_payload(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return $this->build_payload_uncached();
		}

		/** @var CacheManager $cache */
		$cache = $plugin->get( 'cache' );

		return (array) $cache->get(
			self::CACHE_KEY,
			fn(): array => $this->build_payload_uncached(),
			self::CACHE_TTL,
			self::CACHE_GROUP
		);
	}

	/**
	 * Build the payload without caching (used by the loader callback).
	 *
	 * @return array<string, mixed>
	 */
	private function build_payload_uncached(): array {
		$plugin = Plugin::get_instance();

		/** @var Settings $settings */
		$settings = $plugin->get( 'settings' );

		$languages = [];

		if ( $plugin->has( 'cache' ) ) {
			$lang_repo  = $plugin->get( 'lang_repo' );
			$active     = $lang_repo->get_active();
			$default    = $lang_repo->get_default();
			$default_id = $default ? (int) $default->id : 0;

			foreach ( $active as $lang ) {
				if ( ! is_object( $lang ) ) {
					continue;
				}

				$slug   = (string) ( $lang->slug ?? '' );
				$locale = (string) ( $lang->locale ?? '' );

				if ( $slug === '' ) {
					continue;
				}

				$languages[] = [
					'slug'           => $slug,
					'locale'         => $locale,
					'hreflang'       => $locale !== '' ? \PerfLocale\Helper::format_locale_as_bcp47( $locale ) : $slug,
					'prefix'         => $settings->get_url_prefix( $lang ),
					'domain'         => $settings->get_language_domain( $slug ),
					'text_direction' => (string) ( $lang->text_direction ?? 'ltr' ),
					'is_default'     => ( (int) ( $lang->id ?? 0 ) === $default_id ),
				];
			}
		}

		$default_slug = '';

		foreach ( $languages as $lang ) {
			if ( ! empty( $lang['is_default'] ) ) {
				$default_slug = (string) $lang['slug'];
				break;
			}
		}

		$payload = [
			'version'             => PERFLOCALE_VERSION,
			'url_mode'            => $settings->get_url_mode(),
			'url_prefix_type'     => $settings->get_url_prefix_type(),
			'default_slug'        => $default_slug,
			'hide_default_prefix' => $settings->hide_default_prefix(),
			'excluded_paths'      => array_values( array_filter( array_map( 'strval', $settings->get_excluded_paths() ) ) ),
			'detection_order'     => array_values( array_filter( array_map( 'strval', $settings->get_detection_order() ) ) ),
			'edge_hint_header'    => 'X-PerfLocale-Lang',
			'edge_hint_cookie'    => 'perflocale_edge_lang',
			'languages'           => $languages,
		];

		/**
		 * Filter the edge-config payload before it is cached and returned
		 * to edge runtimes. Use it to expose custom fields (feature flags,
		 * per-language A/B variants, fallback chains) that your Cloudflare
		 * Worker / Vercel Edge / Netlify Edge handler needs without making
		 * a separate origin request.
		 *
		 * The result is stored in the 3-layer cache and contributes to the
		 * ETag - keep additions deterministic (no timestamps, no request-
		 * specific data) or call `ConfigController::invalidate()` whenever
		 * the added data changes.
		 *
		 * @hook perflocale/api/config
		 *
		 * @param array $payload Full config payload.
		 */
		return (array) apply_filters( 'perflocale/api/config', $payload );
	}

	/**
	 * Invalidate the cached payload - hook target for language/settings
	 * mutation events.
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		$plugin->get( 'cache' )->delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
