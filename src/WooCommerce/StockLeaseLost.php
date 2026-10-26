<?php
/**
 * WooCommerce stock alignment: the group's locks were lost at the moment of a write.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by StockAlignBackend::write() when the locks cannot be renewed right before the stock statement.
 * Nothing is written; the group ends `changed_while_aligning`.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockLeaseLost extends \RuntimeException {
}
