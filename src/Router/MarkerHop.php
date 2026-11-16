<?php
/**
 * Redirect-marker landings: optional cleanup hop, clean pagination links.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A landing with ?perflocale_redirected=1 or a signed ?perflocale_fb serves as is; a filter can bring back the 301 that strips it.
 *
 * Cold: registered only on a request that carries a marker.
 *
 * @since 1.0.8
 */
final class MarkerHop {

	/**
	 * Marker query keys.
	 */
	private const MARKERS = [ 'perflocale_redirected', 'perflocale_fb' ];

	/**
	 * At template_redirect:1, before the rules that read the markers as loop fuses.
	 *
	 * @return void
	 */
	public static function run(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['perflocale_redirected'] ) ) {
			self::clean_redirect_sentinel();
		}

		if ( ! empty( $_GET['perflocale_fb'] ) ) {
			self::clean_fallback_sentinel();
		}
		// phpcs:enable

		add_filter( 'get_pagenum_link', [ self::class, 'strip' ], PHP_INT_MAX );
	}

	/**
	 * The fallback target with its marker: a short signature of that URL, so a typed or copied marker is no fuse elsewhere.
	 *
	 * @param string $url Absolute fallback target.
	 * @return string
	 */
	public static function fb_url( string $url ): string {
		// Signed in the form the sender emits.
		$parts = wp_parse_url( wp_sanitize_redirect( $url ) );
		$parts = is_array( $parts ) ? $parts : [];

		return add_query_arg( 'perflocale_fb', self::fb_sign( (string) ( $parts['path'] ?? '' ), (string) ( $parts['query'] ?? '' ), (string) ( $parts['host'] ?? '' ) ), $url );
	}

	/**
	 * Whether the request's fallback marker is the one fb_url() gave this URL.
	 *
	 * @return bool
	 */
	public static function fb_landed(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- hashed and compared only.
		$value = $_GET['perflocale_fb'] ?? null;
		$uri   = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$host  = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		// phpcs:enable
		$uri = explode( '?', $uri, 2 );

		return is_string( $value ) && hash_equals( self::fb_sign( $uri[0], $uri[1] ?? '', $host ), $value );
	}

	/**
	 * Eight hex of an HMAC over what makes a URL another page: path, public query vars, host in host modes, blog.
	 *
	 * @param string $path  URL path, encoded or not.
	 * @param string $query Query string.
	 * @param string $host  Host.
	 * @return string
	 */
	private static function fb_sign( string $path, string $query, string $host ): string {
		parse_str( $query, $args );

		$wp   = $GLOBALS['wp'] ?? null;
		$keep = [];

		// The language query var is one of them in every URL mode.
		foreach ( $wp instanceof \WP ? (array) $wp->public_query_vars : [ 'p', 'page_id', 'attachment_id', UrlConverter::query_var() ] as $var ) {
			if ( isset( $args[ $var ] ) && is_string( $args[ $var ] ) ) {
				$keep[ $var ] = $args[ $var ];
			}
		}

		ksort( $keep );

		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;
		$mode     = $settings instanceof \PerfLocale\Settings ? $settings->get_url_mode() : '';
		$host     = $mode === 'subdomain' || $mode === 'domain' ? LanguageRouter::normalize_host( $host ) : '';
		$data     = implode( '|', [ 'perflocale_fb', untrailingslashit( rawurldecode( $path ) ), http_build_query( $keep ), $host, get_current_blog_id() ] );

		return substr( hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) ), 0, 8 );
	}

	/**
	 * Pagination links copy the request's query (core, WooCommerce): drop the markers.
	 *
	 * @internal Hooked by run().
	 *
	 * @param mixed $url Link.
	 * @return mixed
	 */
	public static function strip( $url ) {
		return is_string( $url ) && str_contains( $url, 'perflocale_' ) ? remove_query_arg( self::MARKERS, $url ) : $url;
	}

	/**
	 * Visitor marker: only with the language cookie, as without it the marker is the loop fuse.
	 *
	 * @return void
	 */
	private static function clean_redirect_sentinel(): void {
		if ( empty( $_COOKIE['perflocale_lang'] ) ) {
			return;
		}

		$clean = self::hop( 'perflocale_redirected' );

		if ( $clean !== null ) {
			Redirect\Sender::to( $clean, 301, 'sentinel_clean' );
		}
	}

	/**
	 * Fallback marker.
	 *
	 * @return void
	 */
	private static function clean_fallback_sentinel(): void {
		$clean = self::hop( 'perflocale_fb' );

		if ( $clean !== null ) {
			Redirect\Sender::to( $clean, 301, 'fallback_sentinel_clean' );
		}
	}

	/**
	 * The request URL without the marker when the filter asks for the cleanup hop, else null (GET and HEAD only).
	 *
	 * @param string $marker Marker query key.
	 * @return string|null
	 */
	private static function hop( string $marker ): ?string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ( $method !== 'GET' && $method !== 'HEAD' ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return null;
		}

		$clean = remove_query_arg( $marker );

		if ( $clean === '' ) {
			return null;
		}

		/**
		 * Whether a marker landing gets the 301 that strips the marker (one more hop); by default the landing serves.
		 *
		 * @hook perflocale/redirect/clean_marker_hop
		 * @since 1.0.8
		 *
		 * @param bool   $hop     Default false.
		 * @param string $marker  'perflocale_redirected' (visitor redirect) or 'perflocale_fb' (missing-translation fallback).
		 * @param string $current The request URL, relative, as WordPress builds it from REQUEST_URI.
		 * @param string $clean   The same URL without the marker.
		 */
		return (bool) apply_filters( 'perflocale/redirect/clean_marker_hop', false, $marker, (string) add_query_arg( [] ), $clean ) ? $clean : null;
	}
}
