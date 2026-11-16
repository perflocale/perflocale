<?php
/**
 * Country code from a CDN or server GeoIP header.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in geo sources that read a header the CDN or web server sets; required by GeoRedirect only after
 * its peer check (header_peer_trusted()) passed.
 */
final class GeoHeaderSource {

	/**
	 * Source id => `$_SERVER` keys, read in order; GeoRedirect::header_source() lists the ids too.
	 * Server variables have no HTTP_ prefix, so no client header fills them; Apache renames them REDIRECT_* after a rewrite.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SOURCES = [
		'header_cloudflare' => [ 'HTTP_CF_IPCOUNTRY' ],
		'header_cloudfront' => [ 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' ],
		'header_server'     => [ 'GEOIP2_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE', 'REDIRECT_GEOIP2_COUNTRY_CODE', 'REDIRECT_GEOIP_COUNTRY_CODE' ],
	];

	/**
	 * Whether an id is a built-in header source.
	 *
	 * @param string $id Stored geo_provider value.
	 * @return bool
	 */
	public static function is_source( string $id ): bool {
		return isset( self::SOURCES[ $id ] );
	}

	/**
	 * The selected source's country code: ISO 3166 alpha-2, upper case, or '' (unknown XX included).
	 *
	 * @param string $id Selected source id; any other id reads nothing.
	 * @return string
	 */
	public static function country( string $id ): string {
		foreach ( self::SOURCES[ $id ] ?? [] as $key ) {
			$raw = $_SERVER[ $key ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- length-checked, then unslashed and sanitized below.

			if ( ! is_string( $raw ) || strlen( $raw ) > 8 ) {
				continue;
			}

			$code = strtoupper( trim( sanitize_text_field( wp_unslash( $raw ) ) ) );

			if ( preg_match( '/\A[A-Z]{2}\z/', $code ) === 1 && $code !== 'XX' ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Source id => label, for the GeoIP Provider select; an id a site registers stays its provider (GeoRedirect).
	 *
	 * @param array<string, mixed> $registered GeoRedirect::get_providers().
	 * @return array<string, string>
	 */
	public static function labels( array $registered = [] ): array {
		return array_diff_key(
			[
				'header_cloudflare' => __( 'Cloudflare (CF-IPCountry header)', 'perflocale' ),
				'header_cloudfront' => __( 'Amazon CloudFront (CloudFront-Viewer-Country header)', 'perflocale' ),
				'header_server'     => __( 'Web server GeoIP module (GEOIP2_COUNTRY_CODE or GEOIP_COUNTRY_CODE)', 'perflocale' ),
			],
			$registered
		);
	}
}
