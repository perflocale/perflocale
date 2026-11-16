<?php
/**
 * Retires the stored WooCommerce stock maps when stock sync, the add-on list or WooCommerce is switched.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooked in every request (Bootstrap) by class name, so it loads only when one of those switches fires.
 */
final class StoredMapRetire {

	/**
	 * Delete both epochs of the current blog, which retires every stored variation map and copy map of the blog.
	 *
	 * @return void
	 */
	public static function retire_stored_maps(): void {
		delete_transient( InventorySync::STORED_MAP_EPOCH );
		delete_transient( InventorySync::COPY_MAP_EPOCH );
	}

	/**
	 * Retire the stored maps when the stock sync setting changed.
	 *
	 * @param mixed $settings Settings after the change.
	 * @param mixed $before   Settings before it.
	 * @return void
	 */
	public static function retire_after_settings_change( $settings = [], $before = [] ): void {
		$now = is_array( $settings ) ? (bool) ( $settings['wc_sync_stock'] ?? true ) : true;
		$was = is_array( $before ) ? (bool) ( $before['wc_sync_stock'] ?? true ) : true;

		if ( $now !== $was ) {
			self::retire_stored_maps();
		}
	}

	/**
	 * Retire the stored maps when WooCommerce is activated or deactivated.
	 *
	 * @param mixed $plugin Plugin basename.
	 * @return void
	 */
	public static function retire_after_plugin_toggle( $plugin = '' ): void {
		if ( 'woocommerce/woocommerce.php' === $plugin ) {
			self::retire_stored_maps();
		}
	}
}
