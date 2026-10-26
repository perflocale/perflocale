<?php
/**
 * WooCommerce stock alignment: a stock write that did not happen.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by StockAlignBackend::write(). `gone`: the product does not load or manage its stock any more;
 * `by_plugin`: another plugin stopped the write (its message is not shown); else WooCommerce's or the DB's text.
 * `written`: the statement ran before the failure (a later hook threw), so the change is there to undo.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockWriteFailed extends \RuntimeException {

	/**
	 * Whether the product is gone or does not manage its own stock.
	 *
	 * @var bool
	 */
	public readonly bool $gone;

	/**
	 * Whether another plugin's code stopped the write.
	 *
	 * @var bool
	 */
	public readonly bool $by_plugin;

	/**
	 * The stock after the statement when it ran before the failure (null: it did not run).
	 *
	 * @var float|null
	 */
	public readonly ?float $written;

	/**
	 * Constructor.
	 *
	 * @param string     $message   Failure text ('' when gone or stopped by a plugin).
	 * @param bool       $gone      The product is gone or does not manage its stock.
	 * @param bool       $by_plugin Another plugin's code stopped the write.
	 * @param float|null $written   The stock after the statement when it ran.
	 */
	public function __construct( string $message, bool $gone = false, bool $by_plugin = false, ?float $written = null ) {
		parent::__construct( $message );
		$this->gone      = $gone;
		$this->by_plugin = $by_plugin;
		$this->written   = $written;
	}
}
