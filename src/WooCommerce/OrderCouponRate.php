<?php
/**
 * The order currency's exchange rate for coupons WooCommerce re-applies to an existing order.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds the order's rate to each coupon object recalculate_coupons() applies; MultiCurrency reads it first.
 * Loaded only by woocommerce_order_recalculate_coupons_coupon_object.
 *
 * @internal
 */
final class OrderCouponRate {

	/**
	 * Rate and order-currency decimals by the coupon object WooCommerce applies to the order.
	 *
	 * @var \WeakMap<object, array{0: float, 1: int}>|null
	 */
	private static ?\WeakMap $rates = null;

	/**
	 * Bind the order's rate to the coupon about to be re-applied (late priority: the object WooCommerce applies).
	 *
	 * @param mixed $coupon Coupon object.
	 * @param mixed $code   Coupon code.
	 * @param mixed $item   Coupon order item.
	 * @param mixed $order  Order being recalculated.
	 * @return mixed The coupon, unchanged.
	 * @throws \Exception WC_Data_Exception (400 on REST) when no single rate is known: thrown before calculate_totals().
	 */
	public static function bind( $coupon, $code = '', $item = null, $order = null ) {
		$object = self::wc_object( $coupon, 'WC_Coupon' );

		if ( null === $object ) {
			return $coupon;
		}

		try {
			$bound = self::order_rate( $order, $code, $item );
		} catch ( \Throwable $e ) {
			$bound = null;
		}

		if ( null === $bound ) {
			$wc_order = self::wc_object( $order, 'WC_Abstract_Order' );
			$id       = null !== $wc_order && method_exists( $wc_order, 'get_id' ) ? $wc_order->get_id() : 0;
			$currency = null !== $wc_order && method_exists( $wc_order, 'get_currency' ) ? $wc_order->get_currency( 'edit' ) : '';
			$message  = sprintf(
				/* translators: 1: order number, 2: currency code. */
				__( 'Order %1$d: its coupons were not recalculated: no single exchange rate for %2$s could be determined.', 'perflocale' ),
				is_int( $id ) ? $id : 0,
				is_string( $currency ) ? $currency : ''
			);

			$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;

			if ( is_object( $logger ) && method_exists( $logger, 'warning' ) ) {
				$logger->warning( $message, [ 'source' => 'perflocale' ] );
			}

			if ( class_exists( 'WC_Data_Exception' ) ) {
				throw new \WC_Data_Exception( 'perflocale_order_coupon_rate', esc_html( $message ), 400 );
			}

			throw new \RuntimeException( esc_html( $message ) );
		}

		self::$rates ??= new \WeakMap();

		self::$rates[ $object ] = $bound;

		return $coupon;
	}

	/**
	 * The rate and order-currency decimals bound to a coupon object, if any.
	 *
	 * @param object $coupon WC_Coupon.
	 * @return array{0: float, 1: int}|null
	 */
	public static function rate_for( object $coupon ): ?array {
		return self::$rates[ $coupon ] ?? null;
	}

	/**
	 * The order currency's rate: its language's row, else the one rate every row with it agrees on.
	 * 1.0 for the base, an order PerfLocale did not price in a currency no stored row names (WooCommerce's own amount)
	 * and an amount taken from the item's discount.
	 *
	 * @param mixed $order Order.
	 * @param mixed $code  Coupon code.
	 * @param mixed $item  Coupon order item.
	 * @return array{0: float, 1: int}|null Rate and decimals; null when rows disagree, a named row is unpriced, none were read
	 *                                      or a PerfLocale-priced order's currency has no row (switched or deleted since).
	 */
	private static function order_rate( $order, $code, $item ): ?array {
		$order = self::wc_object( $order, 'WC_Abstract_Order' );

		if ( null === $order || ! method_exists( $order, 'get_currency' ) || ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}

		$currency = $order->get_currency( 'edit' );
		$currency = is_string( $currency ) ? $currency : '';
		$places   = get_option( 'woocommerce_price_num_decimals', 2 );
		$decimals = MultiCurrency::is_zero_decimal_currency( $currency ) ? 0 : ( is_numeric( $places ) ? absint( $places ) : 2 );
		$base     = get_option( 'woocommerce_currency', '' );

		if ( self::from_item_discount( $code, $item ) || ( is_string( $base ) ? $base : '' ) === $currency ) {
			return [ 1.0, $decimals ];
		}

		// Stored rows decide "no row": load_currencies() also drops unpriced auto-sync rows, and a failed or empty read is not "none".
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$stored   = $settings instanceof \PerfLocale\Settings ? $settings->get( 'wc_currencies', [] ) : null;

		if ( ! is_array( $stored ) || [] === $stored ) {
			return null;
		}

		$named = false;

		foreach ( $stored as $row ) {
			$code_raw = is_array( $row ) ? ( $row['currency_code'] ?? '' ) : '';
			$named    = $named || ( is_string( $code_raw ) && strtoupper( trim( $code_raw ) ) === $currency );
		}

		if ( ! $named ) {
			return self::is_perflocale_priced( $order ) ? null : [ 1.0, $decimals ];
		}

		$rows = ( new MultiCurrency() )->load_currencies();
		$lang = $order->get_meta( '_perflocale_language', true );
		$lang = is_string( $lang ) ? sanitize_key( $lang ) : '';

		if ( isset( $rows[ $lang ] ) && strtoupper( $rows[ $lang ]['currency_code'] ) === $currency ) {
			return [ $rows[ $lang ]['exchange_rate'], $decimals ];
		}

		$rates = [];

		foreach ( $rows as $row ) {
			if ( strtoupper( $row['currency_code'] ) === $currency ) {
				$rates[ (string) $row['exchange_rate'] ] = $row['exchange_rate'];
			}
		}

		return 1 === count( $rates ) ? [ reset( $rates ), $decimals ] : null;
	}

	/**
	 * Whether PerfLocale priced the order: a `_perflocale_language` stamp and a currency other than the store base.
	 *
	 * @param object $order WC_Abstract_Order.
	 * @return bool
	 */
	public static function is_perflocale_priced( object $order ): bool {
		if ( ! method_exists( $order, 'get_currency' ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		$currency = $order->get_currency( 'edit' );
		$base     = get_option( 'woocommerce_currency', '' );
		$lang     = $order->get_meta( '_perflocale_language', true );

		return is_string( $currency ) && '' !== $currency && ( is_string( $base ) ? $base : '' ) !== $currency
			&& is_string( $lang ) && '' !== sanitize_key( $lang );
	}

	/**
	 * The value when it is an object of the WooCommerce class, else null.
	 * The class name is a variable: PHPStan has no WooCommerce classes, so callers check each method they call.
	 *
	 * @param mixed  $value Value.
	 * @param string $class WooCommerce class.
	 * @return object|null
	 */
	private static function wc_object( $value, string $class ): ?object {
		return is_object( $value ) && is_a( $value, $class ) ? $value : null;
	}

	/**
	 * Whether recalculate_coupons() took the amount from the item's discount (deleted coupon, no nominal amount stored).
	 *
	 * @param mixed $code Coupon code.
	 * @param mixed $item Coupon order item.
	 * @return bool
	 */
	private static function from_item_discount( $code, $item ): bool {
		$item = self::wc_object( $item, 'WC_Order_Item_Coupon' );

		if ( null === $item || ! method_exists( $item, 'get_meta' ) ) {
			return false;
		}

		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || wc_get_coupon_id_by_code( is_string( $code ) ? $code : '' ) ) {
			return false;
		}

		$info = $item->get_meta( 'coupon_info', true );

		if ( $info ) {
			$short   = is_string( $info ) ? json_decode( $info, true ) : null;
			$nominal = is_array( $short ) ? ( $short[3] ?? 0 ) : 0;
		} else {
			$data    = $item->get_meta( 'coupon_data', true );
			$nominal = $data ? ( ( (array) $data )['amount'] ?? 0 ) : 0;
		}

		return ! ( is_numeric( $nominal ) ? (float) $nominal : 0.0 );
	}
}
