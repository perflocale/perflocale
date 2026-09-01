<?php
/**
 * WooCommerce multi-currency support.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides per-language currency settings for WooCommerce.
 *
 * Stores currency code and exchange rate for each language in wp_options
 * and hooks into WooCommerce filters to display prices in the correct
 * currency based on the active language.
 */
final class MultiCurrency {

	/**
	 * Cached currency settings.
	 *
	 * @var array<string, array{currency_code: string, exchange_rate: float, manual_rate: bool}>|null
	 */
	private ?array $currencies = null;

	/**
	 * Cached exchange rate for the current request.
	 *
	 * Avoids repeated Settings + Router lookups on every convert_price() call.
	 *
	 * @var float|null
	 */
	private ?float $cached_rate = null;

	/**
	 * Register WooCommerce hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_currency', [ $this, 'filter_currency' ] );
		add_filter( 'woocommerce_product_get_price', [ $this, 'convert_price' ], 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', [ $this, 'convert_price' ], 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', [ $this, 'convert_price' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', [ $this, 'convert_price' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', [ $this, 'convert_price' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', [ $this, 'convert_price' ], 10, 2 );

		// Variable product price cache: WC caches variation prices in a
		// transient keyed by a hash. Without including the language, all
		// languages share the same cached prices and only the first
		// language to build the cache gets correct conversion.
		add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'add_language_to_prices_hash' ], 10, 3 );
		add_filter( 'woocommerce_variation_prices_price', [ $this, 'convert_variation_prices' ], 10, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', [ $this, 'convert_variation_prices' ], 10, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'convert_variation_prices' ], 10, 3 );

		// Shipping costs and fixed-amount coupons are stored in the base currency
		// and otherwise bypass convert_price(), so the customer would be charged
		// base-currency NUMBERS relabelled into the per-language currency. Convert
		// them so the whole order is consistent. (Cart fees added programmatically
		// by other plugins stay in the base currency — there is no
		// accumulation-safe filter to scale them; this is the documented limit.)
		add_filter( 'woocommerce_package_rates', [ $this, 'convert_shipping_rates' ], 100 );
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'add_currency_to_shipping_packages' ] );
		add_filter( 'woocommerce_coupon_get_amount', [ $this, 'convert_coupon_amount' ], 10, 2 );

		// Minimum/maximum spend thresholds are validated by WC_Discounts in the
		// BASE currency (get_minimum_amount()/get_maximum_amount()), so without
		// converting them a "min spend 50 EUR" coupon compares 50 against a cart
		// total already displayed/charged in the switched currency — rejecting
		// (or wrongly accepting) valid coupons. Unlike the discount amount these
		// apply to percentage coupons too, so there is no discount-type gate.
		add_filter( 'woocommerce_coupon_get_minimum_amount', [ $this, 'convert_coupon_threshold' ], 10, 2 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', [ $this, 'convert_coupon_threshold' ], 10, 2 );

		// Per-language currency display: symbol vs code, and position.
		add_filter( 'woocommerce_currency_symbol', [ $this, 'filter_currency_symbol' ], 10, 2 );
		add_filter( 'woocommerce_price_format', [ $this, 'filter_price_format' ] );

		// Per-currency decimal places. wc_get_price_decimals() applies this
		// filter, so it governs BOTH the rounding in convert_price() and the
		// displayed/line-total decimals. Without it a zero-decimal currency
		// (JPY/KRW/…) renders fractional prices like "¥2608.70".
		add_filter( 'wc_get_price_decimals', [ $this, 'filter_price_decimals' ] );

		// Multisite isolation: $currencies + $cached_rate are populated from
		// the per-blog `perflocale_settings` option, so on switch_to_blog()
		// the DI singleton would otherwise keep blog A's exchange rates +
		// base currency and serve them for blog B's product prices. Mirrors
		// the Settings::reset_cache() / AddonSettings::reset_static_caches
		// hooks wired in Bootstrap's multisite switch_blog block.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ $this, 'reset_caches' ] );
		}
	}

	/**
	 * Drop per-request cached state. Hooked to `switch_blog` on multisite
	 * so blog A's currency configuration + exchange rate can't leak into
	 * blog B's product rendering.
	 *
	 * @return void
	 */
	public function reset_caches(): void {
		$this->currencies  = null;
		$this->cached_rate = null;
	}

	/**
	 * Filter the active WooCommerce currency based on the current language.
	 *
	 * @param string $currency Default currency code.
	 * @return string Filtered currency code.
	 */
	public function filter_currency( mixed $currency ): string {
		$currency = is_string( $currency ) ? $currency : '';

		// Admin should always show the store's base currency (e.g. EUR),
		// not the per-language currency. Product prices are entered in the
		// base currency; showing лв. next to a EUR price field is confusing.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $currency;
		}

		$lang_slug    = $this->get_current_language_slug();
		$lang_setting = $this->get_currency_for_language( $lang_slug );

		if ( $lang_setting === null ) {
			return $currency;
		}

		return $lang_setting['currency_code'];
	}

	/**
	 * Replace the currency symbol with the currency code when configured.
	 *
	 * @param string $symbol Currency symbol (e.g. €, £, лв.).
	 * @param string $currency_code Currency code (e.g. EUR, GBP, BGN).
	 * @return string
	 */
	public function filter_currency_symbol( string $symbol, string $currency_code ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $symbol;
		}

		$lang_setting = $this->get_currency_for_language( $this->get_current_language_slug() );

		if ( $lang_setting === null ) {
			return $symbol;
		}

		if ( ( $lang_setting['display'] ?? 'symbol' ) === 'code' ) {
			return $currency_code;
		}

		return $symbol;
	}

	/**
	 * Override the price format (symbol position) per language.
	 *
	 * @param string $format WC price format string (e.g. '%1$s%2$s').
	 * @return string
	 */
	public function filter_price_format( string $format ): string {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $format;
		}

		$lang_setting = $this->get_currency_for_language( $this->get_current_language_slug() );

		if ( $lang_setting === null ) {
			return $format;
		}

		$position = $lang_setting['position'] ?? 'default';

		return match ( $position ) {
			'left' => '%1$s%2$s',
			'left_space' => '%1$s&nbsp;%2$s',
			'right' => '%2$s%1$s',
			'right_space' => '%2$s&nbsp;%1$s',
			default => $format,
		};
	}

	/**
	 * Override the decimal places for the current language's currency.
	 *
	 * Zero-decimal ISO-4217 currencies (JPY, KRW, …) must render + round as
	 * integers; the store default of 2 produces nonsense like "¥2608.70" and a
	 * fractional charged amount. Because wc_get_price_decimals() applies this
	 * filter, returning 0 here also makes convert_price()'s round() integer-snap.
	 *
	 * @param mixed $decimals Store default decimal places.
	 * @return int
	 */
	public function filter_price_decimals( $decimals ): int {
		$decimals = is_numeric( $decimals ) ? (int) $decimals : 2;

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $decimals;
		}

		$lang_setting = $this->get_currency_for_language( $this->get_current_language_slug() );

		if ( $lang_setting === null ) {
			return $decimals;
		}

		return self::is_zero_decimal_currency( (string) ( $lang_setting['currency_code'] ?? '' ) ) ? 0 : $decimals;
	}

	/**
	 * Whether a currency code has zero minor units (no decimal subunit).
	 *
	 * @param string $code ISO-4217 currency code.
	 * @return bool
	 */
	private static function is_zero_decimal_currency( string $code ): bool {
		// ISO-4217 currencies with 0 minor units — same set WooCommerce treats
		// as zero-decimal in get_currencies()/format.
		static $zero = [
			'BIF',
			'CLP',
			'DJF',
			'GNF',
			'JPY',
			'KMF',
			'KRW',
			'MGA',
			'PYG',
			'RWF',
			'UGX',
			'VND',
			'VUV',
			'XAF',
			'XOF',
			'XPF',
		];

		return in_array( strtoupper( $code ), $zero, true );
	}

	/**
	 * Convert a product price using the exchange rate for the current language.
	 *
	 * WooCommerce may pass null for unset sale prices (get_prop returns null
	 * when a property has no value), so the parameter accepts mixed.
	 *
	 * @param mixed       $price Raw price string (or null).
	 * @param \WC_Product $product WooCommerce product.
	 * @return string Converted price string.
	 */
	public function convert_price( mixed $price, \WC_Product $product ): string {
		if ( ! is_string( $price ) || $price === '' ) {
			return (string) ( $price ?? '' );
		}

		$rate = $this->get_exchange_rate();

		if ( $rate === 1.0 ) {
			return $price;
		}

		return (string) round( (float) $price * $rate, wc_get_price_decimals() );
	}

	/**
	 * Convert flat-rate shipping costs (and their tax) to the active currency.
	 *
	 * WC computes package rates fresh from the shipping-zone settings (base
	 * currency) and caches the RESULT per package, so multiplying here applies
	 * exactly once per (currency, package) — no accumulation. The currency is
	 * folded into the package hash by add_currency_to_shipping_packages() so a
	 * language switch can't reuse the prior currency's cached rates.
	 *
	 * @param array<string, \WC_Shipping_Rate> $rates Calculated package rates.
	 * @return array<string, \WC_Shipping_Rate>
	 */
	public function convert_shipping_rates( $rates ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! is_array( $rates ) ) {
			return $rates;
		}

		$rate = $this->get_exchange_rate();

		if ( $rate === 1.0 ) {
			return $rates;
		}

		foreach ( $rates as $shipping_rate ) {
			if ( ! $shipping_rate instanceof \WC_Shipping_Rate ) {
				continue;
			}

			$shipping_rate->set_cost( round( (float) $shipping_rate->get_cost() * $rate, wc_get_price_decimals() ) );

			$taxes = $shipping_rate->get_taxes();

			if ( is_array( $taxes ) ) {
				foreach ( $taxes as $key => $tax ) {
					$taxes[ $key ] = (float) $tax * $rate;
				}

				$shipping_rate->set_taxes( $taxes );
			}
		}

		return $rates;
	}

	/**
	 * Make WC's per-session shipping-rate cache currency-aware so a visitor who
	 * switches language doesn't keep seeing the prior currency's cached rates.
	 *
	 * @param array<int, array<string, mixed>> $packages Shipping packages.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_currency_to_shipping_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		$slug = $this->get_current_language_slug();

		foreach ( $packages as $key => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $key ]['perflocale_currency'] = $slug;
			}
		}

		return $packages;
	}

	/**
	 * Convert fixed-amount coupon discounts to the active currency.
	 *
	 * Only fixed_cart / fixed_product coupons carry a base-currency monetary
	 * amount; percentage coupons are proportional to the already-converted line
	 * totals and must NOT be scaled. The filter receives the coupon's stored
	 * (base) amount each call and returns base*rate, so there is no accumulation.
	 *
	 * @param mixed      $amount Coupon amount.
	 * @param \WC_Coupon $coupon Coupon.
	 * @return mixed
	 */
	public function convert_coupon_amount( $amount, $coupon ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $coupon instanceof \WC_Coupon ) {
			return $amount;
		}

		if ( ! in_array( $coupon->get_discount_type(), [ 'fixed_cart', 'fixed_product' ], true ) ) {
			return $amount;
		}

		$rate = $this->get_exchange_rate();

		if ( $rate === 1.0 ) {
			return $amount;
		}

		return round( (float) $amount * $rate, wc_get_price_decimals() );
	}

	/**
	 * Convert a coupon minimum/maximum spend threshold into the active
	 * currency. Mirrors convert_coupon_amount() but with NO discount-type gate
	 * (spend thresholds apply to every coupon type). An empty threshold ('')
	 * means "no limit" and is passed through untouched.
	 *
	 * @param string|int|float $amount Threshold in base currency.
	 * @param \WC_Coupon       $coupon Coupon.
	 * @return string|int|float
	 */
	public function convert_coupon_threshold( $amount, $coupon ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $coupon instanceof \WC_Coupon ) {
			return $amount;
		}

		if ( $amount === '' || $amount === null ) {
			return $amount;
		}

		$rate = $this->get_exchange_rate();

		if ( $rate === 1.0 ) {
			return $amount;
		}

		return round( (float) $amount * $rate, wc_get_price_decimals() );
	}

	/**
	 * Add language slug and exchange rate to the variation prices hash.
	 *
	 * WooCommerce caches variable product prices in a transient keyed by
	 * this hash. Without including the language, all languages share the
	 * same cache entry and only the first to build it gets correct prices.
	 *
	 * @param array<int, string> $hash Hash components.
	 * @param \WC_Product        $product Variable product.
	 * @param bool               $for_display Whether prices are for display.
	 * @return array<int, string>
	 */
	public function add_language_to_prices_hash( array $hash, \WC_Product $product, bool $for_display ): array {
		$slug = $this->get_current_language_slug();
		$rate = $this->get_exchange_rate();

		$hash[] = $slug . '_' . $rate;

		return $hash;
	}

	/**
	 * Convert a single variation price in the variation price cache builder.
	 *
	 * WC's read_price_data() applies this filter per-variation with the raw
	 * price string, the variation product, and the parent variable product.
	 *
	 * @param mixed       $price Raw price string.
	 * @param \WC_Product $variation Variation product.
	 * @param \WC_Product $variable Parent variable product.
	 * @return string Converted price.
	 */
	public function convert_variation_prices( mixed $price, \WC_Product $variation, \WC_Product $variable ): string {
		if ( ! is_string( $price ) || $price === '' ) {
			return (string) ( $price ?? '' );
		}

		$rate = $this->get_exchange_rate();

		if ( $rate === 1.0 ) {
			return $price;
		}

		return (string) round( (float) $price * $rate, wc_get_price_decimals() );
	}

	/**
	 * Get currency settings for a specific language.
	 *
	 * @param string $lang_slug Language slug.
	 * @return array{currency_code: string, exchange_rate: float, manual_rate: bool, display: string, position: string}|null
	 */
	public function get_currency_for_language( string $lang_slug ): ?array {
		if ( $lang_slug === '' ) {
			return null;
		}

		$currencies = $this->load_currencies();

		if ( ! isset( $currencies[ $lang_slug ] ) ) {
			return null;
		}

		return $currencies[ $lang_slug ];
	}

	/**
	 * Get the exchange rate for the current language.
	 *
	 * Returns 1.0 when the current language uses the default currency
	 * or no currency setting is configured.
	 *
	 * @return float Exchange rate multiplier.
	 */
	public function get_exchange_rate(): float {
		if ( $this->cached_rate !== null ) {
			return $this->cached_rate;
		}

		$lang_slug    = $this->get_current_language_slug();
		$lang_setting = $this->get_currency_for_language( $lang_slug );

		$this->cached_rate = $lang_setting !== null ? (float) $lang_setting['exchange_rate'] : 1.0;

		return $this->cached_rate;
	}

	/**
	 * Load currency settings.
	 *
	 * Reads from `perflocale_settings.wc_currencies` (the canonical
	 * storage). Returns an empty array when the settings service is
	 * unavailable — callers downstream gate on emptiness.
	 *
	 * @return array<string, array{currency_code: string, exchange_rate: float, manual_rate: bool}>
	 */
	private function load_currencies(): array {
		if ( $this->currencies !== null ) {
			return $this->currencies;
		}

		$stored = [];

		try {
			$settings      = Plugin::get_instance()->get( 'settings' );
			$from_settings = $settings->get( 'wc_currencies', [] );

			if ( is_array( $from_settings ) && ! empty( $from_settings ) ) {
				$stored = $from_settings;
			}
		} catch ( \Throwable $e ) {
			// Settings service not available during early bootstrap —
			// return empty so callers fall through to defaults.
			unset( $e );
		}

		// Load machine-fetched rates from the dedicated option (written by
		// ExchangeRateSync, kept separate to avoid races with settings
		// writes). Only consult them when global auto-sync is on; when it
		// is off the manual rate in wc_currencies is the source of truth
		// and any leftover RATES_OPTION entries are stale.
		$auto_sync_on = false;

		try {
			$auto_sync_on = (bool) Plugin::get_instance()->get( 'settings' )->get( 'wc_exchange_rate_auto', false );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$auto_rates = $auto_sync_on ? get_option( ExchangeRateSync::RATES_OPTION, [] ) : [];

		if ( ! is_array( $auto_rates ) ) {
			$auto_rates = [];
		}

		// Read the base straight from the option, never through
		// get_woocommerce_currency(): that filter chain includes this class's
		// own filter_currency(), which returns the per-LANGUAGE code and would
		// make the comparison below always false.
		$base_currency = (string) get_option( 'woocommerce_currency', '' );

		$this->currencies = [];

		foreach ( $stored as $slug => $data ) {
			if ( ! is_array( $data ) || empty( $data['currency_code'] ) ) {
				continue;
			}

			$slug_key    = sanitize_key( (string) $slug );
			$manual_rate = (bool) ( $data['manual_rate'] ?? false );
			$rate        = (float) ( $data['exchange_rate'] ?? 1.0 );
			$code        = sanitize_text_field( (string) $data['currency_code'] );

			if ( $auto_sync_on && ! $manual_rate && isset( $auto_rates[ $slug_key ] ) ) {
				$rate = (float) $auto_rates[ $slug_key ];
			}

			// A language priced in the store's BASE currency is by definition
			// 1:1. Sync skips those codes, so any rate sitting here is stale —
			// left over from before the language was switched to the base, or
			// baked into wc_currencies by a settings save — and would silently
			// mark every price up or down. Applied after the auto-rate merge so
			// it overrides both sources.
			if ( $base_currency !== '' && $code === $base_currency ) {
				$rate = 1.0;
			}

			$this->currencies[ $slug_key ] = [
				'currency_code' => $code,
				'exchange_rate' => $rate,
				'manual_rate'   => $manual_rate,
				'display'       => (string) ( $data['display'] ?? 'symbol' ),
				'position'      => (string) ( $data['position'] ?? 'default' ),
			];
		}

		return $this->currencies;
	}

	/**
	 * Get the current language slug from the router.
	 *
	 * @return string Language slug or empty string.
	 */
	private function get_current_language_slug(): string {
		try {
			$router = Plugin::get_instance()->get( 'router' );

			return $router->get_current_slug();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
