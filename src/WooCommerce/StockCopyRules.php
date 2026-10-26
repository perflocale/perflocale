<?php
/**
 * WooCommerce inventory sync: the rules that make language copies one stock.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How the stock mirror (InventorySync) matches variations and which products it skips.
 * The scan asks the mirror itself, so detection and alignment never disagree with the sync.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
interface StockCopyRules {

	/**
	 * A variation's attribute signature as the stock mirror matches it; null when not a variation.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $parent_id    Its parent.
	 * @return string|null
	 */
	public function variation_signature( int $variation_id, int $parent_id ): ?string;

	/**
	 * Whether a product (or a variation through its parent) is opted out of the sync.
	 *
	 * @param int $product_id Product or variation id.
	 * @return bool
	 */
	public function is_sync_opted_out( int $product_id ): bool;
}
