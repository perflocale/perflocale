<?php
/**
 * PerfLocale WooCommerce addon: variation description writes, loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variation description writes of the WooCommerce addon's own variation saves.
 */
final class PerfLocaleWooCommerceVariationText {

	/**
	 * Store a variation's description with its backslashes.
	 *
	 * WooCommerce's data store passes it to update_post_meta() unslashed, which strips one level of backslashes; a
	 * stored value other than that one was written after the save and stays.
	 *
	 * @param \WC_Product_Variation $variation The variation after save().
	 * @return void
	 */
	public static function keep_backslashes( \WC_Product_Variation $variation ): void {
		$variation_id = (int) $variation->get_id();
		$description  = (string) $variation->get_description( 'edit' );

		if ( $variation_id <= 0 || ! str_contains( $description, '\\' ) ) {
			return;
		}

		if ( get_post_meta( $variation_id, '_variation_description', true ) !== wp_unslash( $description ) ) {
			return;
		}

		update_post_meta( $variation_id, '_variation_description', wp_slash( $description ) );
	}
}
