<?php
/**
 * WooCommerce inventory sync: whether a stock change can need its StockChangeLock.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps StockChangeLock out of the load set for a stock change with no language copy.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockLockGate {

	/**
	 * A stock change starts (sync on): StockChangeLock::begin() for a product (a variation's parent) with a language
	 * copy; only a read that ran can prove none (a failed one stores nothing and begin() reads again).
	 *
	 * @param int                         $id        Stock id.
	 * @param object                      $product   The product or variation (WC_Product).
	 * @param InventorySync               $sync      The stock mirror.
	 * @param array<int, array<int, int>> $suspended The mirror's suspended ids, blog id => id => depth.
	 * @return void
	 */
	public static function begin( int $id, object $product, InventorySync $sync, array $suspended ): void {
		$anchor = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : $id;
		$plugin = \PerfLocale\Plugin::get_instance();
		$links  = $anchor > 0 ? $plugin->group_repo()->get_translations( $anchor, \PerfLocale\Enum\ObjectType::Post ) : [];

		if ( count( $links ) >= 2 ) {
			require_once __DIR__ . '/StockChangeLock.php';
			StockChangeLock::begin( $id, $product, $sync, $suspended, $anchor, $links );
		} elseif ( ! $plugin->has( 'cache' ) || ! is_array( $plugin->cache()->get_static( 'translations_' . \PerfLocale\Enum\ObjectType::Post->value . '_' . $anchor, 'perflocale_trans' ) ) ) {
			require_once __DIR__ . '/StockChangeLock.php';
			StockChangeLock::begin( $id, $product, $sync, $suspended );
		}
	}
}
