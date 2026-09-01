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

		// Save the current language when an order is created.
		add_action( 'woocommerce_new_order', [ $this, 'save_order_language' ], 10, 1 );

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

		// Restore locale after email body is rendered.
		add_action( 'woocommerce_email_footer', [ $this, 'restore_locale' ], 99 );

		// Plain-text emails have no footer action either — restore after
		// the send completes so a multi-email request (order status
		// cascade) can switch fresh per email.
		add_action( 'woocommerce_email_sent', [ $this, 'restore_locale' ], 99, 0 );

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
	public function save_order_language( int $order_id ): void {
		$lang_slug = $this->get_current_language_slug();

		if ( $lang_slug === '' ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::ORDER_LANG_META, sanitize_key( $lang_slug ) );
		$order->save();
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

		// The subject filter is the FIRST per-email hook WC fires with the
		// order in hand — switching here covers every email type,
		// including plain text (whose templates never fire
		// woocommerce_email_header).
		$this->switch_locale_for_order( $order );

		$lang_slug = $this->detect_order_language( $order );

		if ( $lang_slug === '' ) {
			return $formatted;
		}

		$language_id = $this->get_language_id( $lang_slug );

		if ( $language_id === 0 ) {
			return $formatted;
		}

		// Get the default getter method name.
		$default_method = 'get_default_' . $field;

		if ( ! method_exists( $email, $default_method ) ) {
			return $formatted;
		}

		// Read the raw option value (with placeholders intact).
		$raw_value = $email->get_option( $field, $email->{$default_method}() );

		if ( $raw_value === '' ) {
			return $formatted;
		}

		$context = "email_{$field}_{$email->id}";
		$hash    = StringRepository::compute_hash( 'woocommerce', $context, $raw_value );

		// Preload all email translations for this language in one query.
		$this->preload_email_translations( $language_id );

		$translated = $this->email_translations[ $language_id ][ $hash ] ?? null;

		if ( $translated === null || $translated === '' ) {
			return $formatted;
		}

		// Format placeholders in the translated string (same as WC does).
		return $email->format_string( $translated );
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

		$this->switch_locale_for_order( $order );
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
	 * @param \WC_Order $order Order being rendered.
	 * @return void
	 */
	private function switch_locale_for_order( \WC_Order $order ): void {
		if ( $this->locale_switched ) {
			return;
		}

		$lang_slug = $this->detect_order_language( $order );

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
		switch_to_locale( $locale );
		$this->locale_switched = true;

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
	 * Restore the locale after email body rendering.
	 *
	 * @return void
	 */
	public function restore_locale(): void {
		if ( ! $this->locale_switched ) {
			return;
		}

		restore_previous_locale();
		$this->locale_switched = false;

		if ( $this->router_overridden ) {
			try {
				Plugin::get_instance()->get( 'router' )->override_current_language( $this->previous_router_language );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$this->router_overridden        = false;
			$this->previous_router_language = null;
		}
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
