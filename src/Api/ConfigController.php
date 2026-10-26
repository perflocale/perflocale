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
 * `GET /perflocale/v1/config`: the routing and language data an edge worker needs to pre-route a visitor.
 *
 * Public read: the payload holds only data the rendered site already shows (never keys, user data or internal IDs),
 * and a `perflocale/api/config` filter must keep it that way. Restrict with the config_permission_callback filter.
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
	 * Payload cache TTL (seconds): a ceiling, as language and settings changes invalidate it.
	 */
	private const CACHE_TTL = 900; // 15 minutes.

	/**
	 * Register REST routes; Bootstrap calls it only while `edge_integration_enabled` is on.
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
	 * Permission gate for the config endpoint: public read unless the filter below restricts it.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error
	 */
	public function permission_check( \WP_REST_Request $request ) {
		/**
		 * Filter the permission of the edge-worker config endpoint (default true, public read).
		 *
		 * Return false, a WP_Error or a current_user_can() result to restrict it.
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

		// A return that is not bool or WP_Error is a developer bug: report it and keep the documented public default.
		if ( ! is_bool( $allowed ) && ! ( $allowed instanceof \WP_Error ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/edge_worker/config_permission_callback", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/edge_worker/config_permission_callback returned %s - must be bool or WP_Error. Falling back to the default public-read permission.', 'perflocale' ),
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

		// If-None-Match lets edges revalidate with a 304.
		$if_none_match = $request->get_header( 'if_none_match' );

		if ( is_string( $if_none_match ) && trim( $if_none_match ) === $etag ) {
			$response = new \WP_REST_Response( null, 304 );
			$response->header( 'ETag', $etag );
			$response->header( 'Cache-Control', $this->cache_control() );

			return $response;
		}

		$response = new \WP_REST_Response( $payload, 200 );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', $this->cache_control() );

		return $response;
	}

	/**
	 * Cache-Control value for a config response.
	 *
	 * Private when the permission filter is hooked or the caller is logged in: a shared copy could reach a refused client.
	 *
	 * @return string
	 */
	private function cache_control(): string {
		if ( is_user_logged_in() || has_filter( 'perflocale/edge_worker/config_permission_callback' ) ) {
			return 'private, no-store';
		}

		return 'public, max-age=300, s-maxage=3600, stale-while-revalidate=86400';
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
		 * Filter the public edge-config payload before it is cached and returned.
		 *
		 * The result is cached and feeds the ETag: keep additions deterministic, or call ConfigController::invalidate().
		 *
		 * @hook perflocale/api/config
		 *
		 * @param array $payload Full config payload.
		 */
		return (array) apply_filters( 'perflocale/api/config', $payload );
	}

	/**
	 * Invalidate the cached payload on language or settings changes.
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
