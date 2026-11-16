<?php
/**
 * WooCommerce inventory sync: a copy's wc_product_meta_lookup columns below WooCommerce 10.8.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Below WooCommerce 10.8 (no per-product lookup rebuild), InventorySync::refresh_product_lookup() sets the columns its
 * sibling writes change on a copy's EXISTING lookup row; required on first use.
 */
final class LegacyLookupRow {

	/**
	 * Set sku, GTIN (WC 9.1+), min/max price, onsale and stock_status on the product's existing row; never INSERTs.
	 *
	 * stock_status matters beyond catalog filters: WooCommerce derives a variable parent's status from its children's
	 * rows (child_has_stock_status), so a stale variation row would undo the status just synced.
	 *
	 * @param \WC_Product $product Product whose lookup row to refresh.
	 * @return void
	 */
	public static function update( \WC_Product $product ): void {
		global $wpdb;

		try {
			if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
				$min = $product->get_variation_price( 'min' );
				$max = $product->get_variation_price( 'max' );
			} else {
				$min = $product->get_price( 'edit' );
				$max = $min;
			}

			// The GTIN column ships with the accessor (WC 9.1 adds both in one
			// migration), so method_exists doubles as the column-presence
			// probe - a fixed SQL naming the column would error on WC < 9.1.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted refresh of WC's lookup row; no WC API exists for this on older versions.
			if ( method_exists( $product, 'get_global_unique_id' ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET sku = %s, global_unique_id = %s, min_price = %f, max_price = %f, onsale = %d, stock_status = %s
						WHERE product_id = %d',
						$wpdb->prefix . 'wc_product_meta_lookup',
						(string) $product->get_sku( 'edit' ),
						(string) $product->get_global_unique_id( 'edit' ),
						(float) ( '' === $min ? 0 : $min ),
						(float) ( '' === $max ? 0 : $max ),
						$product->is_on_sale( 'edit' ) ? 1 : 0,
						(string) $product->get_stock_status( 'edit' ),
						$product->get_id()
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET sku = %s, min_price = %f, max_price = %f, onsale = %d, stock_status = %s
						WHERE product_id = %d',
						$wpdb->prefix . 'wc_product_meta_lookup',
						(string) $product->get_sku( 'edit' ),
						(float) ( '' === $min ? 0 : $min ),
						(float) ( '' === $max ? 0 : $max ),
						$product->is_on_sale( 'edit' ) ? 1 : 0,
						(string) $product->get_stock_status( 'edit' ),
						$product->get_id()
					)
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( \Throwable $e ) {
			// A missing lookup table (very old WC) or an accessor error never breaks the sync: the meta is already
			// correct; only the derived row stays stale.
			unset( $e );
		}
	}
}
