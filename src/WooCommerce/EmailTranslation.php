<?php
/**
 * WooCommerce email translation support.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates WooCommerce emails into the customer's language.
 *
 * Stores the language at order creation time, then translates email
 * subjects, headings, and additional content via PerfLocale's String
 * Translation system, and switches locale for email body rendering.
 */
final class EmailTranslation {

	/**
	 * Order meta key that stores the language slug.
	 */
	private const ORDER_LANG_META = '_perflocale_language';

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
	 * Whether the locale has been switched for the current email.
	 *
	 * @var bool
	 */
	private bool $locale_switched = false;

	/**
	 * Whether we've already registered the shutdown-time locale-restore
	 * safety net for the current request. Prevents stacking multiple
	 * shutdown callbacks when several emails go out in one request.
	 *
	 * @var bool
	 */
	private bool $shutdown_restore_registered = false;

	/**
	 * Whether an order-language window is currently open.
	 *
	 * Tracked separately from $locale_switched because the two are NOT the same
	 * thing: switch_to_locale() returns false without pushing a stack frame when
	 * the target locale has no installed language pack, or already equals
	 * determine_locale(). The window can therefore be open (router overridden,
	 * restore owed) while nothing was pushed and nothing must be popped.
	 *
	 * @var bool
	 */
	private bool $window_open = false;

	/**
	 * Whether the email body has finished rendering and a restore is owed.
	 *
	 * Set at woocommerce_email_footer, consumed by woocommerce_email_sent. Lets
	 * the window stay open across get_headers(), get_attachments() and wp_mail()
	 * while still telling a re-entering render that the previous email is done.
	 *
	 * @var bool
	 */
	private bool $restore_pending = false;

	/**
	 * The email the open window belongs to (window_key()), or '' for a window
	 * opened without one (stock notifications).
	 *
	 * A render of a different email while the window is still open means the
	 * previous email never reached its footer: WooCommerce catches an
	 * exception thrown inside an email render and goes on with the next email
	 * in the same request.
	 *
	 * @var string
	 */
	private string $window_key = '';

	/**
	 * Router language saved before an order-email override, restored by
	 * restore_locale().
	 *
	 * @var object|null
	 */
	private ?object $previous_router_language = null;

	/**
	 * Whether the router's current language is currently overridden for an
	 * email render.
	 *
	 * @var bool
	 */
	private bool $router_overridden = false;

	/**
	 * WooCommerce's default texts of the email being sent, read in the
	 * sender's locale before the switch: field => text.
	 *
	 * @var array<string, string>
	 */
	private array $sender_defaults = [];

	/**
	 * Preloaded email string translations per language ID.
	 *
	 * @var array<int, array<string, string>> language_id => [hash => translated_text]
	 */
	private array $email_translations = [];

	/**
	 * Cached language slug → ID map.
	 *
	 * @var array<string, int>
	 */
	private array $lang_id_cache = [];

	/**
	 * Register WooCommerce hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Register subject, heading, and additional_content filters for each order email.
		$email_ids = apply_filters( 'perflocale/woocommerce/translatable_email_ids', self::ORDER_EMAIL_IDS );

		foreach ( $email_ids as $id ) {
			add_filter( "woocommerce_email_subject_{$id}", [ $this, 'translate_email_subject' ], 10, 3 );
			add_filter( "woocommerce_email_heading_{$id}", [ $this, 'translate_email_heading' ], 10, 3 );
			add_filter( "woocommerce_email_additional_content_{$id}", [ $this, 'translate_email_additional_content' ], 10, 3 );
		}

		// Switch locale for email body template rendering. Kept for
		// third-party emails outside ORDER_EMAIL_IDS whose subject filters
		// we don't hook; the primary switch happens in
		// translate_email_field() so PLAIN-TEXT emails (whose templates
		// never fire woocommerce_email_header) render in the order
		// language too.
		add_action( 'woocommerce_email_header', [ $this, 'switch_locale_for_email' ], 5, 2 );

		// The body is done — but do NOT restore yet. woocommerce_email_footer
		// fires from inside get_content(), which WC_Email::send() calls BEFORE
		// get_headers(), get_attachments() and wp_mail(). Restoring here meant
		// the From-name, every wp_mail filter, the attachment names and (on a
		// multipart email) the entire plain-text alternative body were all
		// produced in the TRIGGERING request's language rather than the
		// customer's. Just record that a restore is owed.
		add_action( 'woocommerce_email_footer', [ $this, 'mark_restore_pending' ], 99 );

		// Plain-text emails have no footer action either — restore after
		// the send completes so a multi-email request (order status
		// cascade) can switch fresh per email.
		add_action( 'woocommerce_email_sent', [ $this, 'restore_locale' ], 99, 0 );

		// Stock notifications go to the shop. WooCommerce sends them from the
		// request that changed the stock, usually a checkout in the customer's
		// language: render them in the shop's language instead.
		foreach ( [ 'woocommerce_low_stock_notification', 'woocommerce_no_stock_notification', 'woocommerce_product_on_backorder_notification' ] as $stock_hook ) {
			add_action( $stock_hook, [ $this, 'switch_locale_for_shop' ], 1, 0 );
			add_action( $stock_hook, [ $this, 'restore_locale' ], PHP_INT_MAX, 0 );
		}

		// Multisite: these per-language caches hold blog-specific data
		// (slug→ID is a per-blog auto-increment; preloaded translations are
		// per-blog rows). Drop them on switch_blog so an email sent for one
		// blog during a multi-blog request (network order processing, CLI,
		// scheduled actions) can't reuse the previous blog's mapping and
		// send the wrong language. switch_blog only fires on multisite.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ $this, 'reset_caches' ] );
		}
	}

	/**
	 * Register the order-language tagging hooks.
	 *
	 * The WooCommerce add-on registers these whenever it is active, whether
	 * or not order emails are translated: the order's language is also read
	 * by the personal-data export and by emails sent after the setting is
	 * turned on.
	 *
	 * @return void
	 */
	public function register_order_language_hooks(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Save the current language when an order is created.
		add_action( 'woocommerce_new_order', [ $this, 'save_order_language' ], 10, 2 );

		// Pay-for-order: an order without a language takes the language it is
		// paid in, before the payment sends any email. The classic order-pay
		// page and the Store API order-pay and checkout routes fire these
		// actions before they process the payment.
		add_action( 'woocommerce_before_pay_action', [ $this, 'save_pay_page_order_language' ], 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'save_store_api_paid_order_language' ], 10, 1 );
	}

	/**
	 * Clear per-blog caches. Hooked to switch_blog on multisite.
	 *
	 * @return void
	 */
	public function reset_caches(): void {
		$this->email_translations = [];
		$this->lang_id_cache      = [];
	}

	/**
	 * Save the current language as order meta when a new order is placed.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save_order_language( int $order_id, $order = null ): void {
		$lang_slug = $this->get_current_language_slug();

		if ( $lang_slug === '' ) {
			return;
		}

		// WooCommerce hands the order object to this hook; re-loading it was a
		// second full load for no reason.
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$slug = sanitize_key( $lang_slug );

		if ( (string) $order->get_meta( self::ORDER_LANG_META, true ) === $slug ) {
			return;
		}

		$order->update_meta_data( self::ORDER_LANG_META, $slug );

		// save_meta_data(), not save(). A full save() inside woocommerce_new_order
		// writes the order table again, bumps date_modified and dispatches a
		// nested woocommerce_update_order — on the checkout path, for one meta
		// row. save_meta_data() writes just that row through the data store,
		// which does its own postmeta backfill when HPOS compatibility mode is on.
		$order->save_meta_data();
	}

	/**
	 * Classic order-pay page: give an order without a language the language
	 * of the page it is paid on. An order that has a language keeps it.
	 *
	 * @param mixed $order Order being paid.
	 * @return void
	 */
	public function save_pay_page_order_language( $order ): void {
		try {
			$slug = (string) Plugin::get_instance()->get( 'router' )->get_current_slug();
		} catch ( \Throwable $e ) {
			return;
		}

		$this->save_missing_order_language( $order, $slug );
	}

	/**
	 * Store API order-pay and checkout routes: give an order without a
	 * language the shopper's language, read as for a new order. An order
	 * that has a language keeps it.
	 *
	 * @param mixed $order Order being paid.
	 * @return void
	 */
	public function save_store_api_paid_order_language( $order ): void {
		$this->save_missing_order_language( $order, $this->get_current_language_slug() );
	}

	/**
	 * Store a language on an order that has none.
	 *
	 * @param mixed  $order Order.
	 * @param string $slug  Language slug; nothing is stored when empty.
	 * @return void
	 */
	private function save_missing_order_language( $order, string $slug ): void {
		$slug = sanitize_key( $slug );

		if ( $slug === '' || ! $order instanceof \WC_Order || $order->get_id() <= 0 ) {
			return;
		}

		if ( (string) $order->get_meta( self::ORDER_LANG_META, true ) !== '' ) {
			return;
		}

		$order->update_meta_data( self::ORDER_LANG_META, $slug );
		$order->save_meta_data();
	}

	/**
	 * Translate an email subject via String Translation.
	 *
	 * @param string    $subject Formatted subject string.
	 * @param \WC_Order $order Order object.
	 * @param \WC_Email $email Email object.
	 * @return string Translated subject.
	 */
	public function translate_email_subject( string $subject, $order, $email = null ): string {
		return $this->translate_email_field( $subject, $order, $email, 'subject' );
	}

	/**
	 * Translate an email heading via String Translation.
	 *
	 * @param string    $heading Formatted heading string.
	 * @param \WC_Order $order Order object.
	 * @param \WC_Email $email Email object.
	 * @return string Translated heading.
	 */
	public function translate_email_heading( string $heading, $order, $email = null ): string {
		return $this->translate_email_field( $heading, $order, $email, 'heading' );
	}

	/**
	 * Translate email additional content via String Translation.
	 *
	 * @param string    $content Formatted additional content.
	 * @param \WC_Order $order Order object.
	 * @param \WC_Email $email Email object.
	 * @return string Translated additional content.
	 */
	public function translate_email_additional_content( string $content, $order, $email = null ): string {
		return $this->translate_email_field( $content, $order, $email, 'additional_content' );
	}

	/**
	 * Translate an email field (subject, heading, or additional_content).
	 *
	 * Retrieves the raw option value (with placeholders like {site_title}),
	 * looks up the String Translation for the order's language, formats
	 * placeholders, and returns the translated string.
	 *
	 * @param string    $formatted Already-formatted value from WC.
	 * @param \WC_Order $order Order object.
	 * @param \WC_Email $email Email object (null for legacy 2-arg filters).
	 * @param string    $field Field name: 'subject', 'heading', or 'additional_content'.
	 * @return string Translated and formatted value, or original if no translation.
	 */
	private function translate_email_field( string $formatted, $order, $email, string $field ): string {
		if ( ! $order instanceof \WC_Order || ! $email instanceof \WC_Email ) {
			return $formatted;
		}

		// An email addressed to the shop, not the customer, renders in the
		// shop's language (the default language), whatever the language of the
		// request that sends it: a checkout in another language must not send
		// the shop its own notification in the customer's language.
		$for_shop = ! $this->email_uses_order_language( $email, $order );

		$default_method = 'get_default_' . $field;
		$has_default    = method_exists( $email, $default_method );

		// Read before the switch below, in the locale WooCommerce used to
		// format `$formatted`: the raw value (placeholders intact; for a shop
		// that kept the default, WooCommerce's default text in that locale)
		// and WooCommerce's default text itself.
		$raw_value      = $has_default ? (string) $email->get_option( $field, $email->{$default_method}() ) : '';
		$default_before = $has_default ? (string) $email->{$default_method}() : '';
		$wc_formatted   = $raw_value !== '' ? $email->format_string( $raw_value ) : '';

		$key = $this->window_key( $email, $order );

		// Opening a window for a new email: note WooCommerce's default texts
		// in the sender's locale first, to tell a saved default from a custom
		// text later (uses_default_text()).
		if ( $this->window_open && ( $this->restore_pending || $this->window_key !== $key ) ) {
			// The previous email is done: its body finished (footer fired), or
			// it is another email whose render stopped before its footer. Close
			// its window first, as switch_locale_to() would, so these are the
			// sender's.
			$this->restore_locale();
		}

		if ( ! $this->window_open ) {
			$this->sender_defaults = [];

			foreach ( [ 'subject', 'heading', 'additional_content' ] as $name ) {
				if ( method_exists( $email, 'get_default_' . $name ) ) {
					$this->sender_defaults[ $name ] = (string) $email->{'get_default_' . $name}();
				}
			}
		}

		// The subject filter is the FIRST per-email hook WC fires with the
		// order in hand — switching here covers every email type,
		// including plain text (whose templates never fire
		// woocommerce_email_header).
		$this->switch_locale_for_order( $order, $for_shop, $key );

		// WooCommerce formatted the {order_date} placeholder before the
		// switch, in the sender's language (e.g. an English month in a French
		// subject). Format it again in the order's language.
		if ( $this->window_open && $this->locale_switched && isset( $email->placeholders['{order_date}'] ) && function_exists( 'wc_format_datetime' ) ) {
			$created = $order->get_date_created();

			if ( $created ) {
				$email->placeholders['{order_date}'] = wc_format_datetime( $created );
			}
		}

		$lang_slug = $for_shop ? $this->shop_language() : $this->order_email_language( $order );

		if ( $lang_slug === '' || $raw_value === '' ) {
			return $formatted;
		}

		$language_id = $this->get_language_id( $lang_slug );

		if ( $language_id === 0 ) {
			return $formatted;
		}

		$context = "email_{$field}_{$email->id}";
		$hash    = StringRepository::compute_hash( 'woocommerce', $context, $raw_value );

		// Preload all email translations for this language in one query.
		$this->preload_email_translations( $language_id );

		$translated = $this->email_translations[ $language_id ][ $hash ] ?? null;

		if ( $translated !== null && $translated !== '' ) {
			// Format placeholders in the translated string (same as WC does).
			return $email->format_string( $translated );
		}

		// No stored translation. When the shop kept WooCommerce's default text
		// and this email's locale was switched, WooCommerce may have formatted
		// that text in the sender's language (an admin, a cron run, WP-CLI):
		// the subject before the switch, and any text it filled into the
		// email's settings earlier in the request. Format the default again,
		// now in the order's language. A custom text, or a value another
		// filter already changed, is kept as it is.
		if ( $this->window_open && $this->locale_switched
			&& $this->uses_default_text( $email, $field, $this->sender_defaults[ $field ] ?? $default_before )
			&& $formatted === $wc_formatted
		) {
			$default_now = (string) $email->{$default_method}();

			$reformatted = $email->format_string( $default_now );

			if ( $default_now !== '' && is_string( $reformatted ) ) {
				return $reformatted;
			}
		}

		return $formatted;
	}

	/**
	 * Whether the shop left an email field at WooCommerce's default text.
	 *
	 * Reads the saved email settings: an empty value, or one equal to the
	 * default text (the settings form pre-fills some fields with it), is the
	 * default. The email object itself cannot tell: WooCommerce keeps the
	 * default it filled in for the request in the object's settings.
	 *
	 * @param \WC_Email $email          Email.
	 * @param string    $field          subject, heading or additional_content.
	 * @param string    $default_before WooCommerce's default text in the sender's locale.
	 * @return bool
	 */
	private function uses_default_text( $email, string $field, string $default_before ): bool {
		if ( ! method_exists( $email, 'get_option_key' ) ) {
			return false;
		}

		$saved = get_option( $email->get_option_key(), [] );
		$value = is_array( $saved ) && is_string( $saved[ $field ] ?? null ) ? $saved[ $field ] : '';

		return $value === '' || $value === $default_before;
	}

	/**
	 * Switch locale before email body template renders.
	 *
	 * Hooked to woocommerce_email_header (first action in email templates)
	 * so all __() calls in the body use the customer's language .mo files.
	 *
	 * @param string    $email_heading Email heading text.
	 * @param \WC_Email $email Email object.
	 * @return void
	 */
	public function switch_locale_for_email( string $email_heading, $email = null ): void {
		if ( ! $email instanceof \WC_Email ) {
			return;
		}

		$order = $email->object ?? null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->switch_locale_for_order( $order, ! $this->email_uses_order_language( $email, $order ), $this->window_key( $email, $order ) );
	}

	/**
	 * Identify one email render: the email object, its type and its order.
	 *
	 * A saved order is identified by its id, so a reloaded copy of the same
	 * order is the same render; an order without an id by its object.
	 *
	 * @param \WC_Email $email Email being rendered.
	 * @param \WC_Order $order Order it concerns.
	 * @return string
	 */
	private function window_key( \WC_Email $email, \WC_Order $order ): string {
		$order_id = (int) $order->get_id();

		return spl_object_id( $email ) . ':' . (string) $email->id . ':'
			. ( $order_id > 0 ? (string) $order_id : 'object-' . spl_object_id( $order ) );
	}

	/**
	 * Switch locale and router language to the shop's language for a
	 * notification to the shop that is not an order email (stock levels).
	 *
	 * @return void
	 */
	public function switch_locale_for_shop(): void {
		$this->switch_locale_to( $this->shop_language() );
	}

	/**
	 * The shop's own language: the default language.
	 *
	 * @return string Language slug, or '' when there is no default language.
	 */
	private function shop_language(): string {
		try {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type hint for static analysis; a short description would be noise.
			/** @var \PerfLocale\Database\Repository\LanguageRepository $lang_repo */
			$lang_repo = Plugin::get_instance()->get( 'lang_repo' );
			$default   = $lang_repo->get_default();
		} catch ( \Throwable $e ) {
			return '';
		}

		return $default !== null ? sanitize_key( (string) $default->slug ) : '';
	}

	/**
	 * Whether this email should be rendered in the ORDER's language.
	 *
	 * The default is WooCommerce's own answer: `is_customer_email()`. An email
	 * addressed to the customer gets the customer's language; one addressed to
	 * the shop gets the shop's. Of the nine order emails this class hooks,
	 * `new_order`, `cancelled_order` and `failed_order` go to the store admin —
	 * and until 1.0.3 those were rendered in the CUSTOMER's language, so a shop
	 * whose orders came from three countries received its own notifications in
	 * three languages. WooCommerce gates its own locale switch the same way
	 * (`WC_Email::setup_locale()`), so this is agreement with core, not a
	 * preference.
	 *
	 * Filterable rather than hard-coded because the plugin already lets a site
	 * ADD email ids through `perflocale/woocommerce/translatable_email_ids`, and
	 * a hard `is_customer_email()` test would silently overrule anyone who added
	 * an admin email there on purpose — a shop with per-language fulfilment
	 * staff being the obvious case. The two filters now compose: one chooses
	 * WHICH emails are handled, this one chooses WHOSE language they use.
	 *
	 * @param \WC_Email $email Email being rendered.
	 * @param \WC_Order $order Order it concerns.
	 * @return bool
	 */
	private function email_uses_order_language( $email, $order ): bool {
		$default = ! method_exists( $email, 'is_customer_email' ) || (bool) $email->is_customer_email();

		/**
		 * Filter whether an order email renders in the order's language.
		 *
		 * @hook perflocale/woocommerce/email_uses_order_language
		 * @param bool      $uses   Default: whether WooCommerce considers this a customer email.
		 * @param \WC_Email $email  Email being rendered.
		 * @param \WC_Order $order  Order it concerns.
		 */
		return (bool) apply_filters( 'perflocale/woocommerce/email_uses_order_language', $default, $email, $order );
	}

	/**
	 * Switch locale AND router language to an order's language for the
	 * duration of an email render.
	 *
	 * Anchored from the subject filter (which fires for EVERY email type —
	 * the woocommerce_email_header action only exists in HTML templates,
	 * so plain-text bodies used to render in the triggering request's
	 * locale) and kept on the header action for third-party emails that
	 * bypass the subject filters. The router override makes
	 * language-keyed lookups (term names, attribute labels, string
	 * translations) resolve in the ORDER language even when the email
	 * fires from wp-admin, a gateway webhook, or cron.
	 *
	 * @param \WC_Order $order    Order being rendered.
	 * @param bool      $for_shop The email goes to the shop: use the shop's
	 *                            language, not the order's.
	 * @param string    $key      The email render the window is for (window_key()).
	 * @return void
	 */
	private function switch_locale_for_order( \WC_Order $order, bool $for_shop = false, string $key = '' ): void {
		$this->switch_locale_to( $for_shop ? $this->shop_language() : $this->order_email_language( $order ), $key );
	}

	/**
	 * Open the locale window for one email in a language.
	 *
	 * @param string $lang_slug Language slug; '' opens nothing.
	 * @param string $key       The email render the window is for (window_key()).
	 * @return void
	 */
	private function switch_locale_to( string $lang_slug, string $key = '' ): void {
		if ( $this->window_open ) {
			// A window is already open. The same email re-entering while its
			// body still renders (the header action after the subject filter)
			// leaves it alone. Otherwise the previous email is done: its footer
			// fired (the NEXT email of a cascade), or this is another email and
			// the previous render stopped before its footer. Close that window
			// and open a fresh one, or email B renders in email A's language.
			if ( ! $this->restore_pending && $this->window_key === $key ) {
				return;
			}

			$this->restore_locale();
		}

		if ( $lang_slug === '' ) {
			return;
		}

		$locale = $this->get_locale_for_slug( $lang_slug );

		if ( $locale === '' ) {
			return;
		}

		// switch_to_locale() MUST run BEFORE the router override. WP core
		// short-circuits (`if ( $current_locale === $locale ) return false;`)
		// whenever determine_locale() already equals the target — and the
		// router drives the `locale` filter, so overriding it first made the
		// switch a guaranteed no-op: no text domain was ever reloaded and the
		// email rendered in the TRIGGERING request's language instead of the
		// order's. Ordering it this way, determine_locale() still reports the
		// request language, so core performs the switch and reloads the
		// domains.
		//
		// Capturing the router's "previous" language after the switch is safe:
		// override_current_language() returns LanguageRouter's own static,
		// which the locale switcher never touches, so it still holds the
		// request's language here and restore_locale() puts it back.
		// Record what switch_to_locale() ACTUALLY did. It returns false without
		// pushing a stack frame when the locale has no installed language pack,
		// or already equals determine_locale(). Assuming a push happened meant
		// restore_previous_locale() later popped a frame somebody else had
		// pushed — WooCommerce's own wc_switch_to_site_locale(), typically —
		// leaving the rest of the request in a locale nobody chose. Reachable
		// today on any store with a language whose pack is not installed.
		$this->locale_switched = switch_to_locale( $locale );
		$this->window_open     = true;
		$this->window_key      = $key;

		try {
			$router   = Plugin::get_instance()->get( 'router' );
			$language = Plugin::get_instance()->get( 'lang_repo' )->find_by_slug( $lang_slug );

			if ( $language ) {
				$this->previous_router_language = $router->override_current_language( $language );
				$this->router_overridden        = true;
			}
		} catch ( \Throwable $e ) {
			// Router unavailable (partial boot) — the locale switch alone
			// still fixes the gettext strings.
			unset( $e );
		}

		// Safety net: the paired `woocommerce_email_footer` action that
		// calls restore_locale() won't fire if the email render dies mid
		// template (fatal in an email meta hook, addon throwing, etc.).
		// Without this, the site locale persists into subsequent
		// requests on long-lived workers (php-fpm, opcache, roadrunner).
		// Register exactly once per request; restore_locale() is
		// no-op-safe when the hook has already fired normally.
		if ( ! $this->shutdown_restore_registered ) {
			register_shutdown_function( [ $this, 'restore_locale' ] );
			$this->shutdown_restore_registered = true;
		}
	}

	/**
	 * Note that the email body has finished rendering.
	 *
	 * Hooked to woocommerce_email_footer, which fires inside get_content() —
	 * i.e. before get_headers(), get_attachments() and wp_mail(). The actual
	 * restore is deferred to woocommerce_email_sent so the whole send happens in
	 * the order's language; this only records that one is owed, which is what
	 * lets a cascade of emails tell "same email re-entering" from "next email".
	 *
	 * @return void
	 */
	public function mark_restore_pending(): void {
		if ( $this->window_open ) {
			$this->restore_pending = true;
		}
	}

	/**
	 * Restore the locale after email body rendering.
	 *
	 * @return void
	 */
	public function restore_locale(): void {
		if ( ! $this->window_open ) {
			return;
		}

		// Pop only if we pushed. See switch_locale_for_order().
		if ( $this->locale_switched ) {
			restore_previous_locale();
			$this->locale_switched = false;
		}

		// Deliberately OUTSIDE the guard above: the router override happens even
		// when the locale switch was a no-op, so it always has to be undone or
		// the rest of the request keeps serving the order's language.
		if ( $this->router_overridden ) {
			try {
				Plugin::get_instance()->get( 'router' )->override_current_language( $this->previous_router_language );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$this->router_overridden        = false;
			$this->previous_router_language = null;
		}

		$this->window_open     = false;
		$this->restore_pending = false;
		$this->window_key      = '';
	}

	/**
	 * Detect the language associated with an order.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return string Language slug or empty string.
	 */
	public function detect_order_language( \WC_Order $order ): string {
		$lang = $order->get_meta( self::ORDER_LANG_META, true );

		if ( ! is_string( $lang ) || $lang === '' ) {
			return '';
		}

		return sanitize_key( $lang );
	}

	/**
	 * The language a customer email for this order is written in.
	 *
	 * The order's own language; for an order without one (placed before
	 * PerfLocale ran, or imported), the default content language, which is
	 * the language the shop showed that customer. Without this fallback the
	 * email took the language of whoever triggered it: the admin's, a cron
	 * run's or the site's locale.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return string Language slug, or '' when there is no default language.
	 */
	private function order_email_language( \WC_Order $order ): string {
		$lang = $this->detect_order_language( $order );

		if ( $lang !== '' ) {
			return $lang;
		}

		try {
			$lang_repo = Plugin::get_instance()->get( 'lang_repo' );
			$default   = $lang_repo instanceof \PerfLocale\Database\Repository\LanguageRepository ? $lang_repo->get_default() : null;
		} catch ( \Throwable $e ) {
			return '';
		}

		return $default !== null ? sanitize_key( (string) $default->slug ) : '';
	}

	/**
	 * Preload all email string translations for a language in one query.
	 *
	 * @param int $language_id Target language ID.
	 * @return void
	 */
	private function preload_email_translations( int $language_id ): void {
		if ( isset( $this->email_translations[ $language_id ] ) ) {
			return;
		}

		$this->email_translations[ $language_id ] = [];

		global $wpdb;

		$strings_table = Schema::table( 'strings' );
		$links_table   = Schema::table( 'translation_links' );
		$groups_table  = Schema::table( 'translation_groups' );
		$st_table      = Schema::table( 'string_translations' );

		// Single query: fetch all email string translations for this language.
		// Joins the dedicated translations table via the composite PRIMARY KEY
		// (string_id, language_id) - an indexed seek per row, replacing the
		// pre-v2 CONCAT('perflocale_str_', s.id, '_', %d) wp_options join that
		// couldn't use an index.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.original_hash, st.translation AS translated_text
				FROM %i s
				INNER JOIN %i g
					ON g.id = s.group_id AND g.type = 'string'
				INNER JOIN %i l
					ON l.group_id = s.group_id AND l.language_id = %d
				INNER JOIN %i st
					ON st.string_id = s.id AND st.language_id = %d
				WHERE s.domain = 'woocommerce'
					AND s.context LIKE %s
					AND st.translation != ''",
				$strings_table,
				$groups_table,
				$links_table,
				$language_id,
				$st_table,
				$language_id,
				$wpdb->esc_like( 'email_' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$this->email_translations[ $language_id ][ $row->original_hash ] = $row->translated_text;
			}
		}
	}

	/**
	 * Get language ID for a slug, with per-request caching.
	 *
	 * @param string $slug Language slug.
	 * @return int Language ID or 0.
	 */
	private function get_language_id( string $slug ): int {
		if ( isset( $this->lang_id_cache[ $slug ] ) ) {
			return $this->lang_id_cache[ $slug ];
		}

		try {
			$plugin = Plugin::get_instance();
			$cache  = $plugin->get( 'cache' );
			$repo   = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$lang   = $repo->find_by_slug( $slug );

			$id = $lang ? (int) $lang->id : 0;
		} catch ( \Throwable $e ) {
			$id = 0;
		}

		$this->lang_id_cache[ $slug ] = $id;

		return $id;
	}

	/**
	 * Get the current language slug from the router.
	 *
	 * @return string Language slug or empty string.
	 */
	private function get_current_language_slug(): string {
		try {
			$router = Plugin::get_instance()->get( 'router' );
			$slug   = $router->get_current_slug();

			// Order creation is often a cookie-blind context for path-based
			// detection (Store API /wp-json/wc/store/v1/checkout, admin manual
			// orders), where the router resolves to the DEFAULT slug even though
			// the shopper was browsing a non-default language. The perflocale_lang
			// cookie set during front-end browsing is the reliable signal here, so
			// prefer it when the router fell back to empty/default. An explicit
			// non-default detection is never overridden.
			// ...but only where the cookie can plausibly be the SHOPPER's. An order
			// created by a shop manager in wp-admin would otherwise be tagged with
			// the ADMIN's browsing language and every customer email for it sent
			// in that language. The wp_doing_ajax() carve-out is load-bearing: the
			// classic checkout posts to admin-ajax.php, where is_admin() is true
			// but the cookie really is the shopper's. The Store API and
			// /?wc-ajax= paths are not is_admin() at all.
			if ( is_admin() && ! wp_doing_ajax() ) {
				// Cast here, not at the tail return: the router's getter is typed
				// mixed, and a second un-narrowed return would add an occurrence
				// to the PHPStan baseline's return.type pattern — one of the three
				// identifiers the release gate's static-analysis step greps for.
				return (string) $slug;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language hint, validated against active languages below; not a state change.
			$cookie = isset( $_COOKIE['perflocale_lang'] ) ? sanitize_key( wp_unslash( $_COOKIE['perflocale_lang'] ) ) : '';

			if ( $cookie !== '' && $cookie !== $slug ) {
				$lang_repo = Plugin::get_instance()->get( 'lang_repo' );
				$default   = $lang_repo->get_default();
				$ambiguous = ( $slug === '' || ( $default && $slug === $default->slug ) );

				// Validate against the ACTIVE slug map (not find_by_slug, which
				// falls through to a raw row even for a deactivated / renamed-away
				// language) so this matches the router's own cookie detection and
				// never records an order in an inactive language.
				if ( $ambiguous && isset( $lang_repo->get_slug_map()[ $cookie ] ) ) {
					return $cookie;
				}
			}

			return $slug;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Get the WordPress locale for a language slug.
	 *
	 * @param string $slug Language slug.
	 * @return string WordPress locale or empty string.
	 */
	private function get_locale_for_slug( string $slug ): string {
		try {
			$plugin = Plugin::get_instance();
			$cache  = $plugin->get( 'cache' );
			$repo   = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
			$lang   = $repo->find_by_slug( $slug );

			if ( $lang && ! empty( $lang->locale ) ) {
				return $lang->locale;
			}
		} catch ( \Throwable $e ) {
			// Service not available.
			unset( $e );
		}

		return '';
	}
}
