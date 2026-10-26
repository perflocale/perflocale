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
 * Per-language currency and exchange rate for WooCommerce prices, from the wc_currencies setting.
 */
final class MultiCurrency {

	/**
	 * Cached currency settings.
	 *
	 * @var array<string, array{currency_code: string, exchange_rate: float, manual_rate: bool, display: string, position: string}>|null
	 */
	private ?array $currencies = null;

	/**
	 * Cached exchange rate for the current language.
	 *
	 * Dropped when a rendering window changes the language (forget_rate()).
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

		// WC caches variation prices in a transient keyed by this hash: without the language all languages share one.
		add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'add_language_to_prices_hash' ], 10, 3 );
		add_filter( 'woocommerce_variation_prices_price', [ $this, 'convert_variation_prices' ], 10, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', [ $this, 'convert_variation_prices' ], 10, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'convert_variation_prices' ], 10, 3 );

		// Shipping and fixed coupon amounts are stored in the base currency and bypass convert_price().
		// Cart fees added by other plugins stay in the base currency: no accumulation-safe filter scales them.
		add_filter( 'woocommerce_package_rates', [ $this, 'convert_shipping_rates' ], 100 );
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'add_currency_to_shipping_packages' ] );
		add_filter( 'woocommerce_coupon_get_amount', [ $this, 'convert_coupon_amount' ], 10, 2 );

		// WC_Discounts compares spend limits, stored in the base currency, with the converted cart total.
		add_filter( 'woocommerce_coupon_get_minimum_amount', [ $this, 'convert_coupon_threshold' ], 10, 2 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', [ $this, 'convert_coupon_threshold' ], 10, 2 );

		// Per-language currency display: symbol vs code, and position.
		add_filter( 'woocommerce_currency_symbol', [ $this, 'filter_currency_symbol' ], 10, 2 );
		add_filter( 'woocommerce_price_format', [ $this, 'filter_price_format' ] );

		// Per-currency decimals: also sets the rounding in convert_price(), not only the display.
		add_filter( 'wc_get_price_decimals', [ $this, 'filter_price_decimals' ] );

		// The cached rows and rate come from the per-blog settings option.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ $this, 'reset_caches' ] );
		}

		// The rate memo is per language; a rendering window (order email, with_language()) fires this on open and close.
		add_action( 'perflocale/language/overridden', [ $this, 'forget_rate' ], 10, 0 );
	}

	/**
	 * Drop per-request cached state, so one blog's currencies never price another blog's products.
	 *
	 * @return void
	 */
	public function reset_caches(): void {
		$this->currencies  = null;
		$this->cached_rate = null;
	}

	/**
	 * Drop the exchange-rate memo when a language window opens or closes.
	 *
	 * The currency rows stay loaded: they hold every language.
	 *
	 * @return void
	 */
	public function forget_rate(): void {
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

		// Admin shows the base currency: product prices are entered in it.
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
	 * Zero-decimal currencies (JPY, KRW) get 0, which also makes convert_price() round to integers.
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
		// ISO 4217 currencies with no minor unit, as WooCommerce treats them.
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
	 * An amount converted at a rate and rounded to the active price decimals.
	 *
	 * An amount above zero converts to at least the smallest unit: at 0 decimals and a rate
	 * below 1, a small price or shipping cost would otherwise round to 0 and be free.
	 *
	 * @param float $amount Amount in the store's base currency.
	 * @param float $rate   Exchange rate.
	 * @return float
	 */
	private static function convert_amount( float $amount, float $rate ): float {
		$decimals  = wc_get_price_decimals();
		$converted = round( $amount * $rate, $decimals );

		if ( $converted <= 0.0 && $amount > 0.0 && is_int( $decimals ) ) {
			return 1 / 10 ** $decimals;
		}

		return $converted;
	}

	/**
	 * Convert a product price using the exchange rate for the current language.
	 *
	 * WooCommerce passes null for an unset sale price.
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

		return (string) self::convert_amount( (float) $price, $rate );
	}

	/**
	 * Convert flat-rate shipping costs (and their tax) to the active currency.
	 *
	 * WC caches the computed rates per package, so this converts once per currency and package (no
	 * accumulation); add_currency_to_shipping_packages() keys that cache by language.
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

			$shipping_rate->set_cost( self::convert_amount( (float) $shipping_rate->get_cost(), $rate ) );

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
	 * Key WC's per-session shipping-rate cache by language, so a switch never reuses another currency's rates.
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
	 * Percentage coupons follow the converted line totals and are not scaled. The filter gets the stored
	 * amount on each call, so nothing accumulates. No floor: it would make a price floored to one unit free.
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
	 * Convert a coupon minimum/maximum spend threshold into the active currency.
	 *
	 * Every coupon type, unlike convert_coupon_amount(); '' means no limit and passes through.
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
	 * WC keys its variation prices transient by this hash, so without them all languages share one entry.
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

		return (string) self::convert_amount( (float) $price, $rate );
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
	 * The exchange rate for the current language: 1.0 when it has no currency row.
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
	 * An exchange rate that can price something: a finite number above zero.
	 *
	 * @param mixed $raw Rate as stored, submitted or synced.
	 * @return float|null The rate, or null when it is not usable.
	 */
	public static function usable_rate( mixed $raw ): ?float {
		if ( ! is_int( $raw ) && ! is_float( $raw ) && ! ( is_string( $raw ) && is_numeric( $raw ) ) ) {
			return null;
		}

		$rate = (float) $raw;

		return is_finite( $rate ) && $rate > 0 ? $rate : null;
	}

	/**
	 * A language's synced rate, only for the store base and its currency now.
	 * The rate comes from the last-sync record with its pair (one read; a rename moves only the rates option).
	 *
	 * @param mixed  $rates The synced rates (ExchangeRateSync::RATES_OPTION, slug => rate).
	 * @param mixed  $last  The last-sync record (ExchangeRateSync::LAST_SYNC_OPTION: base, codes, slug_rates).
	 * @param string $slug  Language slug.
	 * @param string $code  The language's currency now.
	 * @param string $base  The store base currency now.
	 * @return float|null Null when there is none or it is for another pair.
	 */
	public static function synced_rate( mixed $rates, mixed $last, string $slug, string $code, string $base ): ?float {
		if ( ! is_array( $rates ) || ! isset( $rates[ $slug ] ) || ! is_array( $last ) || ( $last['base'] ?? null ) !== $base || ! is_array( $last['codes'] ?? null ) ) {
			return null;
		}

		// A record without slug_rates binds no number to its pair: the configured rate wins until a sync.
		if ( ! is_array( $last['slug_rates'] ?? null ) ) {
			return null;
		}

		$synced = array_intersect_key( $last['slug_rates'], array_flip( array_keys( $last['codes'], $code, true ) ) );

		return self::usable_rate( reset( $synced ) );
	}

	/**
	 * The exchange rate a settings writer stores for one language row.
	 *
	 * An unusable submitted rate keeps the stored rate while the currency stays the same, else 1.0.
	 * A usable rate is stored with the 0.0001 floor.
	 *
	 * @param mixed  $raw    Submitted rate.
	 * @param mixed  $stored The language's stored currency row, if any.
	 * @param string $code   Currency code stored for the row.
	 * @return float
	 */
	public static function rate_to_store( mixed $raw, mixed $stored, string $code ): float {
		$rate = self::usable_rate( $raw );

		if ( $rate === null && is_array( $stored ) && strtoupper( (string) ( $stored['currency_code'] ?? '' ) ) === $code ) {
			$rate = self::usable_rate( $stored['exchange_rate'] ?? null );
		}

		return max( 0.0001, $rate ?? 1.0 );
	}

	/**
	 * Load the currency rows from `perflocale_settings.wc_currencies`; empty without the settings service.
	 *
	 * @return array<string, array{currency_code: string, exchange_rate: float, manual_rate: bool, display: string, position: string}>
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
			// No settings service in early bootstrap: no rows.
			unset( $e );
		}

		// Synced rates (their own option, apart from settings writes) apply only while auto-sync is on;
		// otherwise the rate in wc_currencies wins and leftover synced rates are stale.
		$auto_sync_on = false;

		try {
			$auto_sync_on = (bool) Plugin::get_instance()->get( 'settings' )->get( 'wc_exchange_rate_auto', false );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		if ( $auto_sync_on ) {
			// Both options are not autoloaded: one query for the pair.
			wp_prime_option_caches( [ ExchangeRateSync::RATES_OPTION, ExchangeRateSync::LAST_SYNC_OPTION ] );
		}

		$auto_rates = $auto_sync_on ? get_option( ExchangeRateSync::RATES_OPTION, [] ) : [];
		$last_sync  = $auto_sync_on ? get_option( ExchangeRateSync::LAST_SYNC_OPTION, [] ) : [];

		// Not get_woocommerce_currency(): its filter_currency() returns the language's code, not the base.
		$base_currency = (string) get_option( 'woocommerce_currency', '' );

		$this->currencies = [];

		foreach ( $stored as $slug => $data ) {
			if ( ! is_array( $data ) || empty( $data['currency_code'] ) ) {
				continue;
			}

			$slug_key    = sanitize_key( (string) $slug );
			$manual_rate = (bool) ( $data['manual_rate'] ?? false );
			$code        = sanitize_text_field( (string) $data['currency_code'] );

			// An unusable stored rate counts as 1.0, an unusable synced one as no synced rate.
			$rate = self::usable_rate( $data['exchange_rate'] ?? 1.0 ) ?? 1.0;

			if ( $auto_sync_on && ! $manual_rate ) {
				$rate = self::synced_rate( $auto_rates, $last_sync, $slug_key, $code, $base_currency ) ?? $rate;
			}

			// The base currency is 1:1 and sync skips it, so a rate here is stale; after the merge, it overrides both sources.
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
