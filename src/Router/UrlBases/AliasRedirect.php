<?php
/**
 * The base redirect rule: an alias base gets one 301 to the canonical base.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Router\Redirect\Sender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: Runtime registers it only on a request whose swap matched an alias.
 *
 * @since 1.0.8
 */
final class AliasRedirect {

	/**
	 * Template_redirect:12, after every other PerfLocale rule, core and WooCommerce: replace only the alias span.
	 *
	 * @return void
	 */
	public static function run(): void {
		$span = Runtime::span();
		$uri  = Runtime::server( 'REQUEST_URI' );

		// No language (shown-default unprefixed URL): the default-to-prefix rule owns it.
		if ( $span === null || $span['kind'] === 'current' || $span['lang'] === '' || substr( $uri, $span['start'], $span['len'] ) !== $span['bytes'] ) {
			return;
		}

		// With attachment pages off, core's own hop to the file is the only one.
		if ( empty( Runtime::map()['redirect'] ) || self::fused() || is_404() || ( is_attachment() && ! get_option( 'wp_attachment_pages_enabled' ) ) ) {
			return;
		}

		Sender::to( wp_unslash( substr_replace( $uri, $span['canonical'], $span['start'], $span['len'] ) ), 301, 'base_alias' );
	}

	/**
	 * Whether the request carries the loop-breaker arg (3.8).
	 *
	 * @return bool
	 */
	public static function fused(): bool {
		return isset( $_GET[ Runtime::FUSE ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
	}
}
