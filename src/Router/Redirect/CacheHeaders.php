<?php
/**
 * Cache-header restrictions for PerfLocale responses.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\Redirect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only ever strengthens cache headers: merges no-store into what is queued and keeps it through `wp_headers`.
 *
 * Cold: loaded only on responses that depend on the visitor (detection, visitor rules, private redirects, Store API).
 *
 * @since 1.0.8
 */
final class CacheHeaders {

	/**
	 * Directives every no-store response carries (core's nocache_headers() order).
	 */
	private const STRICT = [ 'no-cache', 'must-revalidate', 'max-age=0', 'no-store', 'private' ];

	/**
	 * Directives the strict set replaces.
	 */
	private const REPLACED = [ 'no-cache', 'must-revalidate', 'max-age', 'no-store', 'private', 'public', 's-maxage' ];

	/**
	 * Expires value in the past (core's).
	 */
	private const PAST = 'Wed, 11 Jan 1984 05:00:00 GMT';

	/**
	 * require_no_store() ran on this request.
	 *
	 * @var bool
	 */
	private static bool $required = false;

	/**
	 * Whether this response must be no-store.
	 *
	 * @return bool
	 */
	public static function required(): bool {
		return self::$required;
	}

	/**
	 * Make this response no-store now and keep it so when core or a plugin emits headers later.
	 *
	 * @return void
	 */
	public static function require_no_store(): void {
		self::restrict();

		if ( self::$required ) {
			return;
		}

		self::$required = true;

		add_filter( 'wp_headers', [ self::class, 'filter_wp_headers' ], PHP_INT_MAX );
		add_action( 'send_headers', [ self::class, 'restrict' ], PHP_INT_MAX, 0 );
		// After every template_redirect callback; the last point before WordPress 6.9, which has no template buffer.
		add_filter( 'template_include', [ self::class, 'seal_template' ], PHP_INT_MAX );
		// Core's last header point (6.9+); hooking it starts core's template buffer, on no-store responses only.
		add_action( 'wp_finalized_template_enhancement_output_buffer', [ self::class, 'seal' ], PHP_INT_MAX, 0 );
	}

	/**
	 * Run restrict() now and seal() after the last template_redirect callback, without core's template buffer.
	 *
	 * @return void
	 */
	public static function restrict_page(): void {
		self::restrict();
		add_filter( 'template_include', [ self::class, 'seal_template' ], PHP_INT_MAX );
	}

	/**
	 * Run seal() at template_include; the template is returned unchanged.
	 *
	 * @internal Hooked by restrict_page() and require_no_store().
	 *
	 * @param mixed $template Template path.
	 * @return mixed
	 */
	public static function seal_template( $template ) {
		self::seal();

		return $template;
	}

	/**
	 * nocache_headers() (its listeners still see the response marked), then the unfiltered seal().
	 *
	 * @return void
	 */
	public static function restrict(): void {
		if ( headers_sent() ) {
			return;
		}

		$queued = self::queued( 'cache-control' );

		nocache_headers();
		self::seal( $queued );
	}

	/**
	 * Merge no-store into every queued cache header: Cache-Control, Pragma, Expires, CDN, surrogate and nginx controls.
	 *
	 * Runs no filter, so it is safe inside core's output-buffer callback.
	 *
	 * @param array<int, string> $before Cache-Control values queued before nocache_headers() replaced them.
	 * @return void
	 */
	public static function seal( array $before = [] ): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: ' . self::merge( array_merge( $before, self::queued( 'cache-control' ) ) ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: ' . self::PAST );
		header( 'Surrogate-Control: ' . self::surrogate( self::queued( 'surrogate-control' ) ) );

		foreach ( self::targeted() as $name ) {
			header( $name . ': no-store' );
		}

		// nginx honours X-Accel-Expires over Cache-Control (also with fastcgi_ignore_headers Cache-Control); 0 = no cache.
		header( 'X-Accel-Expires: 0' );
		header_remove( 'Last-Modified' );
	}

	/**
	 * Merge the no-store directives into the headers core is about to emit.
	 *
	 * @internal Hooked by require_no_store().
	 *
	 * @param mixed $headers Header name => value.
	 * @return mixed
	 */
	public static function filter_wp_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}

		$values    = [];
		$surrogate = [];

		foreach ( $headers as $name => $value ) {
			$lower = is_string( $name ) ? strtolower( $name ) : '';

			if ( in_array( $lower, [ 'cache-control', 'pragma', 'expires', 'last-modified', 'x-accel-expires', 'surrogate-control' ], true ) || str_ends_with( $lower, 'cdn-cache-control' ) ) {
				if ( $lower === 'cache-control' && is_string( $value ) ) {
					$values[] = $value;
				} elseif ( $lower === 'surrogate-control' && is_string( $value ) ) {
					$surrogate[] = $value;
				}

				unset( $headers[ $name ] );
			}
		}

		$headers['Cache-Control']     = self::merge( $values );
		$headers['Pragma']            = 'no-cache';
		$headers['Expires']           = self::PAST;
		$headers['CDN-Cache-Control'] = 'no-store';
		$headers['Surrogate-Control'] = self::surrogate( $surrogate );
		$headers['X-Accel-Expires']   = '0';
		$headers['Last-Modified']     = false;

		return $headers;
	}

	/**
	 * The strict directives plus every queued one they do not replace.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param array<int, string> $values Cache-Control values.
	 * @return string
	 */
	public static function merge( array $values ): string {
		$keep = [];

		foreach ( $values as $value ) {
			foreach ( self::directives( $value ) as $directive ) {
				$directive = strtolower( $directive );
				$name      = trim( explode( '=', $directive, 2 )[0] );

				// A quoted value (no-cache="Set-Cookie, X") is dropped whole: the strict set already covers it.
				if ( ! str_contains( $directive, '"' ) && ! in_array( $name, self::REPLACED, true ) ) {
					$keep[ $directive ] = true;
				}
			}
		}

		return implode( ', ', array_merge( self::STRICT, array_keys( $keep ) ) );
	}

	/**
	 * Surrogate-Control no-store, keeping `content=` (ESI) directives as written.
	 *
	 * @internal Public for the unit tests.
	 *
	 * @param array<int, string> $values Queued Surrogate-Control values.
	 * @return string
	 */
	public static function surrogate( array $values ): string {
		$out = [ 'no-store' ];

		foreach ( $values as $value ) {
			foreach ( self::directives( $value ) as $directive ) {
				if ( strtolower( trim( explode( '=', $directive, 2 )[0] ) ) === 'content' ) {
					$out[] = $directive;
				}
			}
		}

		return implode( ', ', array_unique( $out ) );
	}

	/**
	 * Comma-separated directives of a header value, commas inside quotes kept.
	 *
	 * @param string $value Header value.
	 * @return array<int, string>
	 */
	private static function directives( string $value ): array {
		preg_match_all( '/(?:[^,"]+|"(?:[^"\\\\]|\\\\.)*")+/', $value, $m );

		return array_values( array_filter( array_map( 'trim', $m[0] ), static fn( string $d ): bool => $d !== '' ) );
	}

	/**
	 * CDN-Cache-Control plus every queued targeted variant (Cloudflare-CDN-Cache-Control and the like).
	 *
	 * @return array<int, string>
	 */
	private static function targeted(): array {
		$names = [ 'cdn-cache-control' => 'CDN-Cache-Control' ];

		foreach ( headers_list() as $header ) {
			$name = trim( (string) strstr( $header, ':', true ) );

			if ( str_ends_with( strtolower( $name ), '-cdn-cache-control' ) ) {
				$names[ strtolower( $name ) ] = $name;
			}
		}

		return array_values( $names );
	}

	/**
	 * Values of a queued response header (lower-case name).
	 *
	 * @param string $name Header name, lower case.
	 * @return array<int, string>
	 */
	private static function queued( string $name ): array {
		$out = [];

		foreach ( headers_list() as $header ) {
			$colon = strpos( $header, ':' );

			if ( $colon !== false && strtolower( trim( substr( $header, 0, $colon ) ) ) === $name ) {
				$out[] = trim( substr( $header, $colon + 1 ) );
			}
		}

		return $out;
	}
}
