<?php
/**
 * WooCommerce force-SSL scheme for PerfLocale redirects.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\Redirect;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the scheme WooCommerce would force on the destination, so WooCommerce adds no second hop.
 *
 * Mirrors WC_HTTPS::force_https_template_redirect() and unforce_https_template_redirect() on the destination's class.
 * Loaded only when WooCommerce is active with `woocommerce_force_ssl_checkout` on.
 *
 * @since 1.0.8
 */
final class WooScheme {

	/**
	 * The target with the scheme WooCommerce requires for its destination.
	 *
	 * @param string                $abs  Absolute target.
	 * @param array<string, mixed>  $opts Sender options; `dest` is the destination post id when the rule changes the object.
	 * @return string
	 */
	public static function select( string $abs, array $opts ): string {
		$secure = array_key_exists( 'dest', $opts )
			? self::is_secure_page( is_numeric( $opts['dest'] ) ? (int) $opts['dest'] : 0 )
			: ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) );

		if ( stripos( $abs, 'http://' ) === 0 ) {
			/** This filter is documented in WooCommerce (WC_HTTPS::force_https_template_redirect()). */
			if ( $secure || (bool) apply_filters( 'woocommerce_force_ssl_checkout', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as WC_HTTPS applies it.
				return 'https://' . substr( $abs, 7 );
			}

			return $abs;
		}

		if ( stripos( $abs, 'https://' ) !== 0 || $secure || 'yes' !== get_option( 'woocommerce_unforce_ssl_checkout' ) ) {
			return $abs;
		}

		$home = get_option( 'home' );

		if ( ( is_string( $home ) && str_contains( $home, 'https:' ) ) || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
			return $abs;
		}

		/** This filter is documented in WooCommerce (WC_HTTPS::unforce_https_template_redirect()). */
		return (bool) apply_filters( 'woocommerce_unforce_ssl_checkout', true ) ? 'http://' . substr( $abs, 8 ) : $abs; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as WC_HTTPS applies it.
	}

	/**
	 * Whether a post is the checkout or account page or one of their translations.
	 *
	 * @param int $post_id Destination post id.
	 * @return bool
	 */
	private static function is_secure_page( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$plugin = Plugin::get_instance();
		$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		foreach ( [ 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ] as $option ) {
			$page = get_option( $option );
			$page = is_numeric( $page ) ? (int) $page : 0;

			if ( $page === $post_id ) {
				return true;
			}

			if ( $page <= 0 || ! $cache instanceof CacheManager ) {
				continue;
			}

			foreach ( ( new TranslationGroupRepository( $cache ) )->get_translations( $page, ObjectType::Post ) as $link ) {
				if ( is_object( $link ) && isset( $link->object_id ) && is_numeric( $link->object_id ) && (int) $link->object_id === $post_id ) {
					return true;
				}
			}
		}

		return false;
	}
}
