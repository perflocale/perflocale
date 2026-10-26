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
 * Tags each order with its language; customer emails render in it, shop emails in the default language.
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
	 * Whether the shutdown locale-restore safety net is registered for this request.
	 *
	 * @var bool
	 */
	private bool $shutdown_restore_registered = false;

	/**
	 * Whether an order-language window is open (router overridden, restore owed).
	 *
	 * Not $locale_switched: switch_to_locale() can return false without pushing a frame.
	 *
	 * @var bool
	 */
	private bool $window_open = false;

	/**
	 * Whether the email body finished rendering (footer fired) and a restore is owed.
	 *
	 * Keeps the window open through wp_mail() while telling the next render the previous email is done.
	 *
	 * @var bool
	 */
	private bool $restore_pending = false;

	/**
	 * The email the open window belongs to (window_key()), or '' (stock notifications).
	 *
	 * Another email while open: the previous render threw before its footer, and WooCommerce sends the next.
	 *
	 * @var string
	 */
	private string $window_key = '';

	/**
	 * Router language saved before an order-email override, restored by restore_locale().
	 *
	 * @var object|null
	 */
	private ?object $previous_router_language = null;

	/**
	 * Whether the router's current language is overridden for an email render.
	 *
	 * @var bool
	 */
	private bool $router_overridden = false;

	/**
	 * WooCommerce's default texts of the email being sent, in the sender's locale: field => text.
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

		// For third-party emails outside ORDER_EMAIL_IDS; the primary switch is in
		// translate_email_field(), as plain-text templates never fire woocommerce_email_header.
		add_action( 'woocommerce_email_header', [ $this, 'switch_locale_for_email' ], 5, 2 );

		// Do not restore here: the footer fires inside get_content(), before get_headers(),
		// get_attachments() and wp_mail(), which must also run in the order's language.
		add_action( 'woocommerce_email_footer', [ $this, 'mark_restore_pending' ], 99 );

		// Restore after the send, so each email of a status cascade switches fresh.
		add_action( 'woocommerce_email_sent', [ $this, 'restore_locale' ], 99, 0 );

		// Stock notifications go to the shop but are sent from the request that changed
		// the stock, usually a customer's checkout: render them in the shop's language.
		foreach ( [ 'woocommerce_low_stock_notification', 'woocommerce_no_stock_notification', 'woocommerce_product_on_backorder_notification' ] as $stock_hook ) {
			add_action( $stock_hook, [ $this, 'switch_locale_for_shop' ], 1, 0 );
			add_action( $stock_hook, [ $this, 'restore_locale' ], PHP_INT_MAX, 0 );
		}

		// The slug-to-ID map and preloaded translations are per blog.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ $this, 'reset_caches' ] );
		}
	}

	/**
	 * Register the order-language tagging hooks.
	 *
	 * Registered even when emails are not translated: the personal-data export and later emails read it.
	 *
	 * @return void
	 */
	public function register_order_language_hooks(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Save the current language when an order is created.
		add_action( 'woocommerce_new_order', [ $this, 'save_order_language' ], 10, 2 );

		// Pay-for-order: an order without a language takes the one it is paid in, before
		// the payment sends any email.
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

		// WooCommerce passes the order object; load it only when missing.
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

		// Not save(): inside woocommerce_new_order that rewrites the order, bumps
		// date_modified and fires a nested woocommerce_update_order for one meta row.
		$order->save_meta_data();
	}

	/**
	 * Classic order-pay page: give an order without a language the page's language.
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
	 * Store API order-pay and checkout: give an order without a language the shopper's language.
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
	 * Looks up the raw option value (placeholders intact), then formats the translation's placeholders.
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

		// An email to the shop renders in the default language, whatever the sending request's.
		$for_shop = ! $this->email_uses_order_language( $email, $order );

		$default_method = 'get_default_' . $field;
		$has_default    = method_exists( $email, $default_method );

		// Read before the switch below, in the locale WooCommerce formatted `$formatted` in.
		$raw_value      = $has_default ? (string) $email->get_option( $field, $email->{$default_method}() ) : '';
		$default_before = $has_default ? (string) $email->{$default_method}() : '';
		$wc_formatted   = $raw_value !== '' ? $email->format_string( $raw_value ) : '';

		$key = $this->window_key( $email, $order );

		// A new window first records WooCommerce's default texts in the sender's locale, so
		// uses_default_text() can tell a saved default from a custom text.
		if ( $this->window_open && ( $this->restore_pending || $this->window_key !== $key ) ) {
			// The previous email is done (footer fired, or it stopped before it): close its
			// window first, so the defaults read below are the sender's.
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

		// The subject filter is the first per-email hook with the order: switching here
		// covers plain-text emails, which never fire woocommerce_email_header.
		$this->switch_locale_for_order( $order, $for_shop, $key );

		// WooCommerce formatted {order_date} before the switch, in the sender's language.
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

		// No stored translation: a kept default text may have been formatted in the sender's
		// language (admin, cron, WP-CLI), so format it again. A custom text, or a value
		// another filter changed, stays.
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
	 * Whether the shop left an email field at WooCommerce's default text (empty or equal to it).
	 *
	 * Reads the saved option: the email object holds the default WooCommerce filled in for the request.
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
	 * Switch locale before the email body template renders (woocommerce_email_header).
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
	 * A saved order is keyed by id, so a reloaded copy of it is the same render.
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
	 * Switch locale and router language to the shop's language for a stock notification.
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
	 * Whether this email renders in the order's language.
	 *
	 * Default: is_customer_email(), as WC_Email::setup_locale() gates its own switch. Filterable: see
	 * docs-ai/11-code-rationale.md, "EmailTranslation: whose language".
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
	 * Switch locale and router language to an order's language for one email render.
	 *
	 * The router override makes term, attribute and string lookups follow it from admin, webhooks or cron.
	 *
	 * @param \WC_Order $order    Order being rendered.
	 * @param bool      $for_shop The email goes to the shop: use the shop's language.
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
			// The same email re-entering (header after subject) keeps its window. Otherwise the
			// previous email is done (footer fired, or it stopped before it): close its window,
			// or this email renders in the previous one's language.
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

		// Switch before the router override (it drives the `locale` filter; core skips a switch
		// to the current locale). False pushed no frame, so restore_locale() must not pop one.
		// See docs-ai/11-code-rationale.md, "EmailTranslation: locale window".
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
			// Router unavailable (partial boot): the locale switch alone still covers gettext.
			unset( $e );
		}

		// Safety net for a render that dies before woocommerce_email_sent. Once per request;
		// restore_locale() does nothing when the window is already closed.
		if ( ! $this->shutdown_restore_registered ) {
			register_shutdown_function( [ $this, 'restore_locale' ] );
			$this->shutdown_restore_registered = true;
		}
	}

	/**
	 * Note that the email body has finished rendering (woocommerce_email_footer).
	 *
	 * The restore waits for woocommerce_email_sent; this tells a re-entering email from the next one.
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

		// Pop only if we pushed. See switch_locale_to().
		if ( $this->locale_switched ) {
			restore_previous_locale();
			$this->locale_switched = false;
		}

		// Outside the guard above: the router override happens even when the switch was a no-op.
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
	 * An order without one (older or imported) gets the default language, not the sender's.
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

		// All email string translations of this language; string_translations joins on its
		// (string_id, language_id) primary key.
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

			// When the router fell back to empty/default (Store API checkout), prefer the shopper's
			// cookie. Not in wp-admin (a manager's own language); admin-ajax.php stays in, as the
			// classic checkout posts there.
			if ( is_admin() && ! wp_doing_ajax() ) {
				// Cast here: the getter returns mixed, and the release gate checks PHPStan's return.type baseline.
				return (string) $slug;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language hint, validated against active languages below; not a state change.
			$cookie = isset( $_COOKIE['perflocale_lang'] ) ? sanitize_key( wp_unslash( $_COOKIE['perflocale_lang'] ) ) : '';

			if ( $cookie !== '' && $cookie !== $slug ) {
				$lang_repo = Plugin::get_instance()->get( 'lang_repo' );
				$default   = $lang_repo->get_default();
				$ambiguous = ( $slug === '' || ( $default && $slug === $default->slug ) );

				// The active slug map, not find_by_slug() (which returns inactive rows), as the router does.
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
