<?php
/**
 * Foreign Locations: base normalizer and loop breaker (3.8).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Router\LanguageRouter;
use PerfLocale\Router\Redirect\Sender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: loaded only when some other code redirects on the front end while a map exists; GET/HEAD only.
 *
 * @since 1.0.8
 */
final class ForeignLocation {

	/**
	 * First path segments that are never WordPress-routed pages.
	 */
	private const SKIP = [ 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'xmlrpc.php', 'admin', 'dashboard', 'login', 'login.php' ];

	/**
	 * Wp_redirect (priority 1, front end): replace the alias base of this blog's Location in place (caller's form
	 * kept); one that would land on the current URL is kept as sent with the fuse arg. Never false: core and
	 * WooCommerce exit after wp_redirect().
	 *
	 * @param mixed $location Location after earlier wp_redirect callbacks.
	 * @return mixed
	 */
	public static function filter( $location ) {
		$method = strtoupper( Runtime::server( 'REQUEST_METHOD' ) );

		// PerfLocale's own send is validated by the sender, never normalized here.
		if ( ! is_string( $location ) || ( class_exists( Sender::class, false ) && Sender::sending() ) || ! in_array( $method, [ '', 'GET', 'HEAD' ], true ) || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! self::routed( $location ) ) {
			return $location;
		}

		$new = Runtime::canonicalize_url( $location );

		if ( $new === $location ) {
			return $location;
		}

		$request = wp_unslash( Runtime::server( 'REQUEST_URI' ) );

		return self::key( $new ) === self::key( $request ) ? add_query_arg( Runtime::FUSE, '1', $location ) : $new;
	}

	/**
	 * A WordPress-routed path on this blog's port: not wp-admin, login, REST, uploads or a static file.
	 *
	 * @param string $location Location.
	 * @return bool
	 */
	private static function routed( string $location ): bool {
		$parts = wp_parse_url( $location );
		$home  = get_option( 'home' );
		$home  = wp_parse_url( is_string( $home ) ? $home : '' );

		if ( ! is_array( $parts ) || ! is_array( $home ) || ( isset( $parts['host'] ) && self::port( $parts ) !== self::port( $home ) ) ) {
			return false;
		}

		$path = $parts['path'] ?? '';

		if ( isset( LanguageRouter::STATIC_EXTENSIONS[ strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ] ) ) {
			return false;
		}

		$rest = ltrim( $path, '/' );
		$root = trim( $home['path'] ?? '', '/' );

		if ( $root !== '' && str_starts_with( $rest, $root . '/' ) ) {
			$rest = substr( $rest, strlen( $root ) + 1 );
		}

		$segs  = explode( '/', $rest );
		$first = Runtime::ctx()['mode'] === 'subdirectory' && Runtime::mark( $segs[0] ) !== null ? ( $segs[1] ?? '' ) : $segs[0];

		return ! in_array( $first, self::SKIP, true );
	}

	/**
	 * Port of a parsed URL ('' for the scheme's default).
	 *
	 * @param array{scheme?: string, port?: int} $parts wp_parse_url() result.
	 * @return string
	 */
	private static function port( array $parts ): string {
		$port = $parts['port'] ?? null;

		return $port === null || $port === ( strtolower( $parts['scheme'] ?? '' ) === 'https' ? 443 : 80 ) ? '' : (string) $port;
	}

	/**
	 * Scheme-free comparison key: path and query, plus the host in host modes; escapes lower-cased.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function key( string $url ): string {
		$parts = wp_parse_url( (string) wp_sanitize_redirect( $url ) );
		$parts = is_array( $parts ) ? $parts : [];
		$path  = $parts['path'] ?? '';
		$out   = ( $path === '' ? '/' : $path ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		if ( in_array( Runtime::ctx()['mode'], [ 'domain', 'subdomain' ], true ) ) {
			$out = LanguageRouter::normalize_host( $parts['host'] ?? Runtime::server( 'HTTP_HOST' ) ) . $out;
		}

		return (string) preg_replace_callback( '/%[0-9a-f]{2}/i', static fn( array $m ): string => strtolower( $m[0] ), $out );
	}
}
