<?php
/**
 * WooCommerce URL bases provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases\Providers;

use PerfLocale\Admin\Typed;
use PerfLocale\Contract\UrlBasesProvider;
use PerfLocale\Router\UrlBases\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Group `woocommerce`: product, product archive (the shop), product category, tag and brand, public attribute bases.
 *
 * Declares only; the compiler derives the product bases from the translated Shop page when they start with it.
 *
 * @internal
 */
final class WooCommerce implements UrlBasesProvider {

	/**
	 * The `perflocale/url_bases/providers` callback of the WooCommerce add-on (compile contexts only).
	 *
	 * @param mixed $providers Providers so far.
	 * @return array<mixed>
	 */
	public static function add( mixed $providers ): array {
		$providers   = is_array( $providers ) ? $providers : [];
		$providers[] = new self();

		return $providers;
	}

	/** Group id. */
	public function group(): string {
		return 'woocommerce';
	}

	/** Group label. */
	public function label(): string {
		return 'WooCommerce';
	}

	/**
	 * Keys with their labels: the core rules (public, rewrite slug, archive slug differs) on WooCommerce's translatable
	 * objects; an object that is not translatable is not offered, as in Core.
	 *
	 * @return array<string, string>
	 */
	public function keys(): array {
		/** @var \PerfLocale\Settings $settings */
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		// Brands (product_brand) exist from WooCommerce 9.6; Core skips a taxonomy that is not registered.
		$taxes = [ 'product_cat', 'product_tag', 'product_brand' ];

		if ( function_exists( 'wc_get_attribute_taxonomies' ) && function_exists( 'wc_attribute_taxonomy_name' ) ) {
			foreach ( Typed::arr( wc_get_attribute_taxonomies() ) as $attr ) {
				if ( is_object( $attr ) && 1 === Typed::int( $attr->attribute_public ?? 0 ) && is_string( $attr->attribute_name ?? null ) ) {
					$taxes[] = Typed::str( wc_attribute_taxonomy_name( $attr->attribute_name ) );
				}
			}
		}

		$taxes = array_values( array_intersect( $taxes, Typed::strs( $settings->get_translatable_taxonomies() ) ) );
		$types = array_values( array_intersect( [ 'product' ], Typed::strs( $settings->get_translatable_post_types() ) ) );

		// A non-ASCII attribute slug cannot be a map key; such an attribute keeps its base in every language.
		return array_filter(
			( new Core( $types, $taxes ) )->keys(),
			static fn( string $key ): bool => 1 === preg_match( Registry::KEY_PATTERN, $key ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * None: the compiler already reads WooCommerce's permalink and shop page options and attribute changes.
	 *
	 * @return string[]
	 */
	public function inputs(): array {
		return [];
	}

	/**
	 * None: attribute bases that share a first segment pass full-path validation; shop child pages are shop exceptions.
	 *
	 * @return string[]
	 */
	public function shared(): array {
		return [];
	}
}
