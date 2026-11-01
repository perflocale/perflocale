<?php
/**
 * WooCommerce email and exchange-rate hooks whose classes load when a hook fires.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers EmailTranslation's and ExchangeRateSync's hooks as static forwarders to one shared instance of each,
 * created on the first call, so a visitor's page loads neither class.
 *
 * @internal
 */
final class WcLazyHooks {

	/**
	 * WooCommerce order-related email IDs.
	 *
	 * @var array<int, string>
	 */
	private const ORDER_EMAIL_IDS = [
		'new_order',
		'cancelled_order',
		'failed_order',
		'customer_on_hold_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_refunded_order',
		'customer_invoice',
		'customer_note',
	];

	/**
	 * The instance the email hooks call.
	 *
	 * @var EmailTranslation|null
	 */
	private static ?EmailTranslation $email = null;

	/**
	 * The instance the exchange-rate hooks call.
	 *
	 * @var ExchangeRateSync|null
	 */
	private static ?ExchangeRateSync $rates = null;

	/**
	 * Settings the exchange-rate instance is created with.
	 *
	 * @var Settings|null
	 */
	private static ?Settings $settings = null;

	/**
	 * The email instance, created on first use.
	 *
	 * @return EmailTranslation
	 */
	private static function email(): EmailTranslation {
		return self::$email ??= new EmailTranslation();
	}

	/**
	 * The exchange-rate instance, created on first use.
	 *
	 * @return ExchangeRateSync
	 */
	private static function rates(): ExchangeRateSync {
		return self::$rates ??= new ExchangeRateSync( self::$settings ?? \PerfLocale\Plugin::get_instance()->settings() );
	}

	/**
	 * Register the order-language tagging hooks; also when emails are not translated (export and later emails read it).
	 *
	 * @param EmailTranslation|null $instance The instance to call (EmailTranslation::register_order_language_hooks()).
	 * @return void
	 */
	public static function order_language( ?EmailTranslation $instance = null ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::$email = $instance ?? self::$email;

		add_action( 'woocommerce_new_order', [ self::class, 'save_order_language' ], 10, 2 );

		// Pay-for-order: an order without a language takes the one it is paid in, before the payment sends any email.
		add_action( 'woocommerce_before_pay_action', [ self::class, 'save_pay_page_order_language' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'save_store_api_paid_order_language' ], 10, 1 );
	}

	/**
	 * Register the email translation hooks.
	 *
	 * @param EmailTranslation|null $instance The instance to call (EmailTranslation::register_hooks()).
	 * @return void
	 */
	public static function emails( ?EmailTranslation $instance = null ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		self::$email = $instance ?? self::$email;

		$email_ids = apply_filters( 'perflocale/woocommerce/translatable_email_ids', self::ORDER_EMAIL_IDS );

		foreach ( $email_ids as $id ) {
			add_filter( "woocommerce_email_subject_{$id}", [ self::class, 'translate_email_subject' ], 10, 3 );
			add_filter( "woocommerce_email_heading_{$id}", [ self::class, 'translate_email_heading' ], 10, 3 );
			add_filter( "woocommerce_email_additional_content_{$id}", [ self::class, 'translate_email_additional_content' ], 10, 3 );
		}

		// For third-party emails outside ORDER_EMAIL_IDS; the primary switch is in translate_email_field(), as
		// plain-text templates never fire woocommerce_email_header.
		add_action( 'woocommerce_email_header', [ self::class, 'switch_locale_for_email' ], 5, 2 );

		// Not a restore: the footer fires inside get_content(), before the headers, attachments and wp_mail(), which
		// must also run in the order's language.
		add_action( 'woocommerce_email_footer', [ self::class, 'mark_restore_pending' ], 99 );

		// Restore after the send, so each email of a status cascade switches fresh.
		add_action( 'woocommerce_email_sent', [ self::class, 'restore_locale' ], 99, 0 );

		// Stock notifications go to the shop but are sent from the request that changed the stock, usually a
		// customer's checkout: render them in the shop's language.
		foreach ( [ 'woocommerce_low_stock_notification', 'woocommerce_no_stock_notification', 'woocommerce_product_on_backorder_notification' ] as $stock_hook ) {
			add_action( $stock_hook, [ self::class, 'switch_locale_for_shop' ], 1, 0 );
			add_action( $stock_hook, [ self::class, 'restore_locale' ], PHP_INT_MAX, 0 );
		}

		// The slug-to-ID map and preloaded translations are per blog.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'reset_caches' ] );
		}
	}

	/**
	 * Register the exchange-rate hooks; in write contexts also ExchangeRateSync::register_write_hooks().
	 *
	 * @param Settings              $settings Plugin settings.
	 * @param ExchangeRateSync|null $instance The instance to call (ExchangeRateSync::register_hooks()).
	 * @return void
	 */
	public static function rates_hooks( Settings $settings, ?ExchangeRateSync $instance = null ): void {
		self::$settings = $settings;
		self::$rates    = $instance ?? self::$rates;

		add_filter( 'cron_schedules', [ self::class, 'register_schedules' ] );

		// ExchangeRateSync::CRON_HOOK, written out: the constant would load the class. A void wrapper of sync_rates().
		add_action( 'perflocale_exchange_rate_sync', [ self::class, 'cron_sync_rates' ] );

		add_action( 'perflocale/settings/updated', [ self::class, 'maybe_reschedule' ] );

		// The AJAX handler is registered in Bootstrap::init(), independent of add-on boot order.

		// Stale-rates notice, hooked only while auto-sync is on.
		if ( (bool) $settings->get( 'wc_exchange_rate_auto', false ) ) {
			add_action( 'admin_notices', [ self::class, 'maybe_render_stale_notice' ] );
		}

		if ( \PerfLocale\Helper::is_write_context() ) {
			self::rates()->register_write_hooks();
		}
	}

	/**
	 * EmailTranslation::save_order_language() on the shared instance.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $order    Order object.
	 * @return void
	 */
	public static function save_order_language( int $order_id, $order = null ): void {
		self::email()->save_order_language( $order_id, $order );
	}

	/**
	 * EmailTranslation::save_pay_page_order_language() on the shared instance.
	 *
	 * @param mixed $order Order being paid.
	 * @return void
	 */
	public static function save_pay_page_order_language( $order ): void {
		self::email()->save_pay_page_order_language( $order );
	}

	/**
	 * EmailTranslation::save_store_api_paid_order_language() on the shared instance.
	 *
	 * @param mixed $order Order being paid.
	 * @return void
	 */
	public static function save_store_api_paid_order_language( $order ): void {
		self::email()->save_store_api_paid_order_language( $order );
	}

	/**
	 * EmailTranslation::translate_email_subject() on the shared instance.
	 *
	 * @param string    $subject Subject.
	 * @param \WC_Order $order   Order object.
	 * @param \WC_Email $email   Email object.
	 * @return string
	 */
	public static function translate_email_subject( string $subject, $order, $email = null ): string {
		return self::email()->translate_email_subject( $subject, $order, $email );
	}

	/**
	 * EmailTranslation::translate_email_heading() on the shared instance.
	 *
	 * @param string    $heading Heading.
	 * @param \WC_Order $order   Order object.
	 * @param \WC_Email $email   Email object.
	 * @return string
	 */
	public static function translate_email_heading( string $heading, $order, $email = null ): string {
		return self::email()->translate_email_heading( $heading, $order, $email );
	}

	/**
	 * EmailTranslation::translate_email_additional_content() on the shared instance.
	 *
	 * @param string    $content Additional content.
	 * @param \WC_Order $order   Order object.
	 * @param \WC_Email $email   Email object.
	 * @return string
	 */
	public static function translate_email_additional_content( string $content, $order, $email = null ): string {
		return self::email()->translate_email_additional_content( $content, $order, $email );
	}

	/**
	 * EmailTranslation::switch_locale_for_email() on the shared instance.
	 *
	 * @param string    $email_heading Email heading.
	 * @param \WC_Email $email         Email object.
	 * @return void
	 */
	public static function switch_locale_for_email( string $email_heading, $email = null ): void {
		self::email()->switch_locale_for_email( $email_heading, $email );
	}

	/**
	 * EmailTranslation::switch_locale_for_shop() on the shared instance.
	 *
	 * @return void
	 */
	public static function switch_locale_for_shop(): void {
		self::email()->switch_locale_for_shop();
	}

	/**
	 * EmailTranslation::mark_restore_pending() on the shared instance; without one no window is open.
	 *
	 * @return void
	 */
	public static function mark_restore_pending(): void {
		self::$email?->mark_restore_pending();
	}

	/**
	 * EmailTranslation::restore_locale() on the shared instance; without one no window is open.
	 *
	 * @return void
	 */
	public static function restore_locale(): void {
		self::$email?->restore_locale();
	}

	/**
	 * EmailTranslation::reset_caches() on the shared instance, if one exists.
	 *
	 * @return void
	 */
	public static function reset_caches(): void {
		self::$email?->reset_caches();
	}

	/**
	 * The exchange-rate cron intervals (RateSchedules), without ExchangeRateSync.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_schedules( array $schedules ): array {
		return RateSchedules::add( $schedules );
	}

	/**
	 * ExchangeRateSync::cron_sync_rates() on the shared instance.
	 *
	 * @return void
	 */
	public static function cron_sync_rates(): void {
		self::rates()->cron_sync_rates();
	}

	/**
	 * ExchangeRateSync::maybe_reschedule() on the shared instance.
	 *
	 * @return void
	 */
	public static function maybe_reschedule(): void {
		self::rates()->maybe_reschedule();
	}

	/**
	 * ExchangeRateSync::maybe_render_stale_notice() on the shared instance.
	 *
	 * @return void
	 */
	public static function maybe_render_stale_notice(): void {
		self::rates()->maybe_render_stale_notice();
	}
}
