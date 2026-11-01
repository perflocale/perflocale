<?php
/**
 * No-store headers for a Store API response whose language came from the cookie.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a cookie-language Store API GET/HEAD uncacheable; loaded only when the request path names /wc/store/.
 *
 * @since 1.0.7.5
 */
final class StoreApiNoStore {

	/**
	 * Whether mark() ran on this request.
	 *
	 * @var bool
	 */
	private static bool $marked = false;

	/**
	 * Send core's no-cache headers, with no-store and private, on this response if it serves a Store API route.
	 *
	 * The route is judged when the response is sent: a REST prefix filtered after this plugin loads is known only then.
	 *
	 * @since 1.0.7.5
	 */
	public static function mark(): void {
		self::$marked = true;
		add_filter( 'rest_send_nocache_headers', [ self::class, 'send_nocache' ] );
		add_filter( 'nocache_headers', [ self::class, 'cache_control' ], PHP_INT_MAX );
	}

	/**
	 * True on a Store API route, else core's value.
	 *
	 * @since 1.0.7.5
	 *
	 * @param mixed $send Whether core sends no-cache headers.
	 * @return mixed
	 */
	public static function send_nocache( mixed $send ): mixed {
		return self::store_route() ? true : $send;
	}

	/**
	 * Whether the REST route core resolved for this request is in the wc/store namespace (any case, as the REST server).
	 */
	private static function store_route(): bool {
		$wp    = $GLOBALS['wp'] ?? null;
		$route = $wp instanceof \WP ? ( $wp->query_vars['rest_route'] ?? null ) : null;

		return is_string( $route ) && 0 === strncasecmp( '/' . ltrim( $route, '/' ), '/wc/store/', 10 );
	}

	/**
	 * Whether a Store API response already goes out no-store: marked here, or its own Cache-Control says so.
	 *
	 * @since 1.0.7.5
	 *
	 * @param mixed $result Response about to be sent.
	 */
	public static function already_no_store( mixed $result ): bool {
		if ( self::$marked ) {
			return true;
		}

		if ( $result instanceof \WP_HTTP_Response ) {
			foreach ( $result->get_headers() as $name => $value ) {
				if ( is_string( $value ) && 'cache-control' === strtolower( (string) $name ) && str_contains( strtolower( $value ), 'no-store' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Add no-store and private to Cache-Control when missing.
	 *
	 * WordPress below 6.8 sends them only to logged-in users, and this body follows the cookie.
	 *
	 * @since 1.0.7.5
	 *
	 * @param mixed $headers Header names and values from wp_get_nocache_headers().
	 * @return mixed
	 */
	public static function cache_control( mixed $headers ): mixed {
		if ( ! is_array( $headers ) || ! self::store_route() ) {
			return $headers;
		}

		$value  = is_string( $headers['Cache-Control'] ?? null ) ? trim( $headers['Cache-Control'] ) : '';
		$tokens = array_map( 'trim', explode( ',', strtolower( $value ) ) );

		foreach ( [ 'no-store', 'private' ] as $directive ) {
			if ( ! in_array( $directive, $tokens, true ) ) {
				$value .= ( '' === $value ? '' : ', ' ) . $directive;
			}
		}

		$headers['Cache-Control'] = $value;

		return $headers;
	}
}
