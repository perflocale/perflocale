<?php
/**
 * WooCommerce inventory sync: the stock mirror's copy rules, for the scan.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StockCopyRules answered by InventorySync itself. A separate class, made only where a scan runs,
 * so the interface never loads on the cart, checkout and stock-change requests that load InventorySync.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockMirrorRules implements StockCopyRules {

	/**
	 * The stock mirror.
	 *
	 * @var InventorySync
	 */
	private InventorySync $sync;

	/**
	 * Constructor.
	 *
	 * @param InventorySync $sync The stock mirror.
	 */
	public function __construct( InventorySync $sync ) {
		$this->sync = $sync;
	}

	/**
	 * {@inheritDoc}
	 */
	public function variation_signature( int $variation_id, int $parent_id ): ?string {
		return $this->sync->variation_signature( $variation_id, $parent_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_sync_opted_out( int $product_id ): bool {
		return $this->sync->is_sync_opted_out( $product_id );
	}
}
