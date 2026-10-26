<?php
/**
 * PerfLocale WooCommerce addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce integration: products, terms, shop pages, order emails, currency and stock sync.
 */
final class PerfLocaleWooCommerce implements \PerfLocale\Addon\AddonInterface {

	/**
	 * WC email settings registered as translatable strings, one context per field and email id.
	 */
	private const EMAIL_STRING_FIELDS = [ 'subject', 'heading', 'additional_content' ];

	/**
	 * Lazily instantiated term translation manager.
	 *
	 * @var \PerfLocale\Translation\TermTranslationManager|null
	 */
	private ?\PerfLocale\Translation\TermTranslationManager $term_manager = null;

	/**
	 * How many Store API cart or checkout route callbacks are running (a batch nests them).
	 *
	 * @var int
	 */
	private int $store_api_cart_depth = 0;

	/**
	 * Whether boot() attached Inventory Sync's hooks (InventorySyncHooks::register()).
	 *
	 * @var bool
	 */
	private bool $inventory_sync_registered = false;

	/**
	 * Current-language sibling data of each cart line, for the open Store API cart window only.
	 * Keyed by blog, language and product id; emptied when the window closes.
	 *
	 * @var array<string, array{title: ?string, excerpt: ?string, image: int, gallery: ?array<int, int>}|null>
	 */
	private array $store_api_cart_siblings = [];

	/**
	 * Whether the open Store API cart window has primed its siblings' caches.
	 *
	 * @var bool
	 */
	private bool $store_api_cart_primed = false;

	/**
	 * WooCommerce's product data store, loaded on the first product type lookup.
	 *
	 * @var \WC_Data_Store|null
	 */
	private ?\WC_Data_Store $product_data_store = null;

	/**
	 * Product type mirror_product_type() gave each translation created in this request.
	 * Keyed "<blog id>:<post id>"; refresh_copy_lookup_row() reads and clears it.
	 *
	 * @var array<string, string>
	 */
	private array $created_product_types = [];

	/**
	 * Get the term translation manager (lazy).
	 *
	 * @return \PerfLocale\Translation\TermTranslationManager
	 */
	private function get_term_manager(): \PerfLocale\Translation\TermTranslationManager {
		if ( $this->term_manager === null ) {
			$plugin             = \PerfLocale\Plugin::get_instance();
			$this->term_manager = new \PerfLocale\Translation\TermTranslationManager( $plugin->get( 'cache' ) );
		}

		return $this->term_manager;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'woocommerce';
	}

	/**
	 * Stock block context of this admin-ajax request: 'stock', 'runner' (Action Scheduler) or ''.
	 * Also '' on every request that is not admin-ajax, wc-ajax included.
	 *
	 * @return string
	 */
	private static function stock_ajax_action(): string {
		if ( ! wp_doing_ajax() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared with action names; each handler checks its own nonce.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( str_starts_with( $action, 'perflocale_wc_stock_' ) ) {
			return 'stock';
		}

		return 'as_async_request_queue_runner' === $action ? 'runner' : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'WooCommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [ 'woocommerce/woocommerce.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		$settings = $plugin->get( 'settings' );

		// ---- Translatable content registration ----

		// Product post types. shop_order is excluded: orders carry language meta, never per-language copies.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// Variations are show_ui false (no _edit_link): link to the parent product's Variations tab.
		add_filter( 'perflocale/admin/edit_post_link', [ $this, 'edit_link' ], 10, 3 );

		// A variation is not publicly_queryable, but WooCommerce maps its permalink onto the parent
		// product (a working 200 URL): keep that view link.
		add_filter( 'perflocale/admin/view_post_link', [ $this, 'view_link' ], 10, 2 );

		// Product taxonomies; pa_* attributes are discovered after WC registers them.
		add_filter( 'perflocale/translatable_taxonomies', [ $this, 'add_taxonomies' ], 20 );

		// Register product meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );

		// ---- URL slug translation ----

		// Translate cart item permalink to the current language.
		add_filter( 'woocommerce_cart_item_permalink', [ $this, 'translate_cart_item_permalink' ], 10, 3 );

		// The Store API cart item takes name, excerpt and images from the product object: map them to
		// the current-language sibling, only while a Store API cart or checkout route runs.
		add_filter( 'rest_request_before_callbacks', [ $this, 'open_store_api_cart_window' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'close_store_api_cart_window' ], 10, 3 );
		// Block hydration calls the route controller itself and never fires the two filters above.
		add_filter( 'woocommerce_hydration_dispatch_request', [ $this, 'dispatch_store_api_hydration' ], PHP_INT_MAX, 4 );

		// Both WC filter families are needed: wc_get_cart_url()/wc_get_checkout_url() fire
		// woocommerce_get_<page>_url, while wc_get_page_permalink() fires the dynamic
		// woocommerce_get_<page>_page_permalink. Shop has only the second.
		add_filter( 'woocommerce_get_cart_url', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_checkout_url', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_cart_page_permalink', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_checkout_page_permalink', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_myaccount_page_permalink', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_shop_page_permalink', [ $this, 'translate_wc_url' ] );

		// Mini-Cart buttons use get_permalink( wc_get_page_id() ), so no WC URL filter fires: fix them
		// at page_link. Frontend only; sitemaps excluded: a <loc> is the page's own identity URL.
		// See docs-ai/11-code-rationale.md, "PerfLocaleWooCommerce: shop page links".
		if ( ! is_admin() && ! \PerfLocale\Helper::is_rest_request() && ! $this->is_sitemap_request() ) {
			// Priority 20: after UrlConverter::filter_page_link() (10), so only the links it leaves in the
			// page's own language are corrected.
			add_filter( 'page_link', [ $this, 'force_wc_page_language_prefix' ], 20, 2 );
		}

		// ---- Cross-sells / upsells across translation siblings ----

		// Stored cross-sell/upsell ids are copied verbatim: map each to its current-language sibling at
		// read time; untranslated ids pass through.
		add_filter( 'woocommerce_product_get_cross_sell_ids', [ $this, 'map_related_ids_to_language' ] );
		add_filter( 'woocommerce_product_get_upsell_ids', [ $this, 'map_related_ids_to_language' ] );
		add_filter( 'woocommerce_cart_crosssell_ids', [ $this, 'map_related_ids_to_language' ] );

		// Grouped children are the same kind of copied id list; unmapped, a language-scoped lookup drops
		// them and the add-to-cart form has no purchasable children.
		add_filter( 'woocommerce_product_get_children', [ $this, 'map_related_ids_to_language' ] );

		// ---- Coupon restrictions across translation siblings ----

		// WC compares raw ids and each language copy has its own: expand coupon lists to siblings at read
		// time, frontend only (the stored coupon keeps the saved ids).
		add_filter( 'woocommerce_coupon_get_product_ids', [ $this, 'expand_coupon_product_ids' ] );
		add_filter( 'woocommerce_coupon_get_excluded_product_ids', [ $this, 'expand_coupon_product_ids' ] );
		add_filter( 'woocommerce_coupon_get_product_categories', [ $this, 'expand_coupon_term_ids' ] );
		add_filter( 'woocommerce_coupon_get_excluded_product_categories', [ $this, 'expand_coupon_term_ids' ] );

		// Translate account endpoint URLs: /my-account/orders/, /my-account/downloads/, etc.
		add_filter( 'woocommerce_get_endpoint_url', [ $this, 'translate_endpoint_url' ], 10, 4 );

		// ---- WC page ID translation ----

		// Return the translated page id, or is_cart()/is_checkout() fail on non-default languages.
		$wc_pages = [ 'cart', 'checkout', 'myaccount', 'shop', 'terms' ];
		foreach ( $wc_pages as $page ) {
			// Priority 15: after WooCommerce's own option filters (10).
			add_filter( "option_woocommerce_{$page}_page_id", [ $this, 'filter_wc_page_id' ], 15 );
		}

		// ---- Optional services ----

		// Every order is tagged with its language, whether or not its emails are translated.
		$email = new \PerfLocale\WooCommerce\EmailTranslation();
		$email->register_order_language_hooks();

		// Personal-data export of an order: its language.
		add_filter( 'woocommerce_privacy_export_order_personal_data_props', [ $this, 'add_order_language_export_prop' ], 10, 1 );
		add_filter( 'woocommerce_privacy_export_order_personal_data_prop', [ $this, 'export_order_language_prop' ], 10, 3 );

		// Email translation - send order emails in the customer's stored language.
		if ( (bool) $settings->get( 'wc_email_translation', true ) ) {
			$email->register_hooks();
		}

		// Inventory sync - keep stock, SKU, price, weight in sync across variants.
		if ( (bool) $settings->get( 'wc_sync_stock', true ) ) {
			\PerfLocale\WooCommerce\InventorySyncHooks::register( $plugin->get( 'cache' ) );
			$this->inventory_sync_registered = true;

			// Stock block ajax actions and job types: only on its admin-ajax requests, Action Scheduler's
			// queue runner, WP-Cron and WP-CLI.
			$stock_ajax = self::stock_ajax_action();

			if ( '' !== $stock_ajax || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				// StockCheckJob::TYPE as a string: the job class loads only when a worker runs. Aligning is off (Api::align_off()).
				\PerfLocale\Background\WorkerRegistry::register( 'wc_stock_check', static fn(): \PerfLocale\Background\AbstractJob => new \PerfLocale\WooCommerce\StockCheckJob() );

				if ( 'stock' === $stock_ajax ) {
					\PerfLocale\WooCommerce\StockAlignScreen::register();
				}
			}

			// Per-product sync opt-out (Advanced tab), admin only; InventorySync reads the meta flag.
			if ( is_admin() ) {
				add_action( 'woocommerce_product_options_advanced', [ $this, 'render_sync_optout_field' ] );
				// Priority 5: before InventorySync's sync at 100, so the save that ticks the box respects it.
				add_action( 'woocommerce_process_product_meta', [ $this, 'save_sync_optout_field' ], 5, 1 );
			}
		}

		// Translation siblings legitimately share the source's SKU, which WC requires to be unique.
		add_filter( 'wc_product_has_unique_sku', [ $this, 'allow_translation_duplicate_sku' ], 10, 3 );

		// Same for the GTIN/UPC/EAN global unique ID (WC 9.1+).
		add_filter( 'wc_product_has_global_unique_id', [ $this, 'allow_translation_duplicate_global_unique_id' ], 10, 3 );

		// Multi-currency - display prices in the per-language configured currency.
		if ( (bool) $settings->get( 'wc_currency_per_lang', false ) ) {
			$currency = new \PerfLocale\WooCommerce\MultiCurrency();
			$currency->register_hooks();
		}

		// Always booted for the AJAX handler and cron hook; wc_exchange_rate_auto controls scheduling.
		$rate_sync = new \PerfLocale\WooCommerce\ExchangeRateSync( $settings );
		$rate_sync->register_hooks();

		// String translation - payment gateway titles, shipping labels.
		$strings = new \PerfLocale\WooCommerce\WcStringTranslation(
			$plugin->get( 'router' ),
			$plugin->get( 'cache' )
		);
		$strings->register_hooks();

		// ---- Attribute label auto-registration ----

		// WC attribute labels never pass through __(): register them as strings for the Strings page.
		add_action( 'woocommerce_attribute_added', [ $this, 'register_attribute_label_string' ], 10, 2 );
		add_action( 'woocommerce_attribute_updated', [ $this, 'register_attribute_label_string' ], 10, 2 );

		// WC_Query (priority 10) turns a shop front page or translated shop page into a product archive
		// after PerfLocale (priority 5) exempted it as a page: stamp it at 20, only where unstamped.
		// See docs-ai/11-code-rationale.md, "PerfLocaleWooCommerce: shop front page".
		add_action( 'pre_get_posts', [ $this, 'rescope_front_page_shop' ], 20 );

		// BlocksWpQuery caches product-grid results for 30 days under a hash of the query vars taken
		// before pre_get_posts: add a language var at parse_query, as WooCommerce documents. Only for
		// BlocksWpQuery: core's own WP_Query cache key already includes the SQL.
		add_action( 'parse_query', [ $this, 'separate_block_query_cache_by_language' ] );

		// Priority 15: product_type is an internal taxonomy no copy carries; set it before the variation
		// clone (20) loads the product with wc_get_product().
		add_action( 'perflocale/translation/created', [ $this, 'mirror_product_type' ], 15, 4 );

		// Clone variations onto a new product translation; priority 20, after the meta and type copy.
		add_action( 'perflocale/translation/created', [ $this, 'clone_product_variations' ], 20, 4 );

		// Lookup-table row for a non-variable translation. Priority 25: after mirror_product_type() (15),
		// whose recorded type picks the data store, and the variation clone (20).
		add_action( 'perflocale/translation/created', [ $this, 'refresh_copy_lookup_row' ], 25, 2 );

		// Product MT also translates variation descriptions and local attribute options.
		if ( $settings->mt_enabled() ) {
			add_action( 'perflocale/machine_translation/after', [ $this, 'translate_variation_texts' ], 10, 6 );
		}

		// Register WC non-gettext strings (attribute labels, email texts) on "Scan for Strings".
		add_action( 'perflocale/strings/after_scan', [ $this, 'sync_attribute_labels' ] );
		add_action( 'perflocale/strings/after_scan', [ $this, 'sync_email_strings' ] );

		// Re-register email strings when WC email settings are saved.
		add_action( 'woocommerce_settings_saved', [ $this, 'sync_email_strings' ] );

		// Other email-setting writes (WC_Email::update_option(), REST, the block email editor, code,
		// WP-CLI) skip woocommerce_settings_saved; core fires these two for every caller.
		add_action( 'added_option', [ $this, 'register_email_strings_on_add' ], 10, 2 );
		add_action( 'updated_option', [ $this, 'register_email_strings_on_update' ], 10, 3 );

		// ---- Cart fragment invalidation ----

		// WC keeps currency-formatted cart totals in the session: recalculate when the language changes.
		add_action( 'wp_loaded', [ $this, 'recalculate_cart_on_language_change' ], 20 );

		// Language in the cart hash, so sessionStorage fragments refresh on a language switch.
		add_filter( 'woocommerce_cart_hash', [ $this, 'add_language_to_cart_hash' ] );

		// Make the sessionStorage keys for cart fragments language-specific.
		add_filter( 'woocommerce_cart_fragment_name', [ $this, 'add_language_to_fragment_key' ] );
		add_filter( 'woocommerce_cart_hash_key', [ $this, 'add_language_to_fragment_key' ] );

		// Language in the wc-ajax URL: unprefixed, the fragment refresh falls back to the cookie language.
		add_filter( 'woocommerce_ajax_get_endpoint', [ $this, 'add_language_to_ajax_url' ], 10, 2 );

		// Clean up stale fragment entries from other languages in sessionStorage.
		add_action( 'wp_footer', [ $this, 'clear_stale_cart_fragments' ], 1 );

		// ---- Variation attribute translation ----

		// Frontend-only hooks (product pages, not AJAX).
		if ( ! is_admin() ) {
			// Return the product's original attribute terms while the dropdown renders; names are
			// translated by a separate filter.
			add_filter( 'woocommerce_dropdown_variation_attribute_options_args', [ $this, 'suspend_term_filter_for_dropdown' ], 5 );
			add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ $this, 'restore_term_filter_after_dropdown' ], 999, 2 );

			// Translate attribute term names in variation dropdowns.
			add_filter( 'woocommerce_variation_option_name', [ $this, 'translate_variation_option_name' ], 10, 4 );
		}

		// Also on WC AJAX (admin-ajax, where is_admin() is true), or mini-cart fragments stay untranslated.
		if ( ! is_admin() || wp_doing_ajax() ) {
			// wc_attribute_label() returns the raw DB value without __(): translate via String Translation.
			add_filter( 'woocommerce_attribute_label', [ $this, 'translate_attribute_label' ], 5, 3 );

			// Variation names are translated where shown, never via woocommerce_product_variation_title:
			// the variation data store writes that filter's result to post_title, so each read would write.

			// Name order lines in the checkout language.
			add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'name_order_line_in_checkout_language' ], 10, 4 );

			// Translate variation names displayed in cart, mini-cart, and checkout.
			add_filter( 'woocommerce_cart_item_name', [ $this, 'translate_cart_item_name' ], 10, 3 );

			// Translate variation names in order items (order confirmation, emails).
			add_filter( 'woocommerce_order_item_name', [ $this, 'translate_order_item_name' ], 10, 2 );

			// Translate variation attribute values below the product name in cart and checkout.
			add_filter( 'woocommerce_get_item_data', [ $this, 'translate_cart_item_data' ], 10, 2 );

			// Translate attribute values in order item meta display.
			add_filter( 'woocommerce_display_item_meta', [ $this, 'translate_order_item_meta' ], 10, 3 );
		}

		// Hide attribute rows whose value, in any language, is already in the line name. Every context:
		// admin screens and admin-sent emails show the stored name too.
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'hide_attribute_meta_in_item_name' ], 10, 2 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		// Stored in the main perflocale_settings option, rendered and saved by render_settings_subtab(),
		// not AddonSettings. AddonRegistry lists this add-on in GLOBAL_SETTINGS_ONLY and never builds
		// these fields at boot, so every field must stay 'storage' => 'global'.
		$global = [ 'storage' => 'global' ];
		return [
			'wc_email_translation'      => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Send Order Emails in Customer\'s Language', 'perflocale' ),
				'default' => true,
			],
			'wc_sync_stock'             => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Sync Inventory Across Language Variants', 'perflocale' ),
				'default' => true,
			],
			'wc_sync_prices'            => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Synchronize Prices Across Languages', 'perflocale' ),
				'default' => true,
			],
			'wc_currency_per_lang'      => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Different Currency Per Language', 'perflocale' ),
				'default' => false,
			],
			'wc_currencies'             => $global + [
				'type'    => 'hidden',
				'default' => [],
			],
			'wc_exchange_rate_auto'     => $global + [
				'type'    => 'checkbox',
				'label'   => __( 'Auto-Sync Exchange Rates', 'perflocale' ),
				'default' => false,
			],
			'wc_exchange_rate_provider' => $global + [
				'type'    => 'select',
				'label'   => __( 'Exchange Rate Provider', 'perflocale' ),
				'default' => '',
			],
			'wc_exchange_rate_interval' => $global + [
				'type'    => 'select',
				'label'   => __( 'Sync Interval', 'perflocale' ),
				'default' => 'daily',
			],
		];
	}

	/**
	 * Recalculate WC cart totals when the language (and currency) changes.
	 *
	 * WC keeps totals in the session; a session marker holds the last language seen.
	 *
	 * @return void
	 */
	public function recalculate_cart_on_language_change(): void {
		// Frontend only; AJAX fragments reuse the totals of the page that triggered them.
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return;
		}

		$current_slug = $plugin->get( 'router' )->get_current_slug();

		if ( $current_slug === '' ) {
			return;
		}

		$session_slug = WC()->session->get( 'perflocale_cart_lang' );

		if ( $session_slug === $current_slug ) {
			return;
		}

		// Language changed - recalculate totals so prices use the new currency.
		WC()->session->set( 'perflocale_cart_lang', $current_slug );
		WC()->cart->calculate_totals();
	}

	/**
	 * Add the current language slug to the WooCommerce cart hash.
	 *
	 * Fragments in sessionStorage are keyed by the hash, so a language switch refreshes them.
	 *
	 * @param string $hash Cart hash.
	 * @return string Modified hash with language suffix.
	 */
	public function add_language_to_cart_hash( string $hash ): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $hash;
		}

		$slug = $plugin->get( 'router' )->get_current_slug();

		if ( $slug !== '' ) {
			$hash = md5( $hash . '_' . $slug );
		}

		return $hash;
	}

	/**
	 * Include the language prefix in WC AJAX endpoint URLs.
	 *
	 * Unprefixed, the router falls back to the cookie, which may name another language.
	 *
	 * @param string $url AJAX endpoint URL.
	 * @param string $request Endpoint name (e.g., 'get_refreshed_fragments').
	 * @return string Language-prefixed URL.
	 */
	public function add_language_to_ajax_url( string $url, string $request ): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) || ! $plugin->has( 'settings' ) ) {
			return $url;
		}

		$router   = $plugin->get( 'router' );
		$settings = $plugin->get( 'settings' );

		// Subdirectory mode only: subdomain and domain modes carry the language in the host, and query
		// mode appends ?lang= in UrlConverter::add_lang_to_wc_ajax_endpoint().
		if ( $settings->get_url_mode() !== 'subdirectory' ) {
			return $url;
		}

		$current = $router->get_current_language();

		if ( ! $current ) {
			return $url;
		}

		$prefix = $settings->get_url_prefix( $current );

		if ( $prefix === '' ) {
			return $url;
		}

		$default      = $router->get_default_language();
		$hide_default = $settings->hide_default_prefix();

		// Don't add prefix for the default language if prefix is hidden.
		if ( $hide_default && $default && $current->slug === $default->slug ) {
			return $url;
		}

		// Add the language prefix to the AJAX URL path.
		// e.g., "/?wc-ajax=get_refreshed_fragments" → "/fr/?wc-ajax=get_refreshed_fragments"
		$parsed = wp_parse_url( $url );
		$path   = $parsed['path'] ?? '/';

		// The prefix goes after the install's home path ('/shop/', an MU subsite's '/blog2/'): placed
		// before it, the cart-fragment request 404s.
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( $home_path !== '' && str_starts_with( $path, $home_path . '/' ) ) {
			$remainder = substr( $path, strlen( $home_path ) );

			// Only add if not already prefixed.
			if ( ! str_starts_with( $remainder, '/' . $prefix . '/' ) && ! str_starts_with( $remainder, '/' . $prefix . '?' ) ) {
				$path = $home_path . '/' . $prefix . $remainder;
			}
		} elseif ( ! str_starts_with( $path, '/' . $prefix . '/' ) && ! str_starts_with( $path, '/' . $prefix . '?' ) ) {
			$path = '/' . $prefix . $path;
		}

		$url = $path;

		if ( ! empty( $parsed['query'] ) ) {
			$url .= '?' . $parsed['query'];
		}

		return $url;
	}

	/**
	 * Make WC cart fragment sessionStorage keys language-specific.
	 *
	 * @param string $key Fragment storage key.
	 * @return string Language-specific key.
	 */
	public function add_language_to_fragment_key( string $key ): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $key;
		}

		$slug = $plugin->get( 'router' )->get_current_slug();

		if ( $slug !== '' ) {
			$key .= '_' . $slug;
		}

		return $key;
	}

	/**
	 * Render the per-product sync opt-out checkbox on the product data Advanced tab.
	 *
	 * @return void
	 */
	public function render_sync_optout_field(): void {
		woocommerce_wp_checkbox(
			[
				'id'          => \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META,
				'value'       => get_post_meta( (int) get_the_ID(), \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META, true ),
				'label'       => __( 'Independent across languages', 'perflocale' ),
				'description' => __( 'Do not synchronize this product\'s shared data (prices, stock, dimensions, SKU) with its translations. Tick it on a single language\'s product to give only that language independent values - for example a promotional price in one language.', 'perflocale' ),
			]
		);

		// Marker input: an unchecked checkbox looks absent, so saves that never render this field
		// (REST, importer, CLI) must not clear the flag.
		echo '<input type="hidden" name="_perflocale_sync_optout_present" value="1" />';
	}

	/**
	 * Persist the sync opt-out checkbox from the product edit screen.
	 *
	 * @param int $product_id Saved product ID.
	 * @return void
	 */
	public function save_sync_optout_field( int $product_id ): void {
		// WooCommerce verifies its meta-box nonce before firing woocommerce_process_product_meta.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC's save_meta_boxes nonce verified upstream.
		if ( ! isset( $_POST['_perflocale_sync_optout_present'] ) ) {
			return;
		}

		if ( isset( $_POST[ \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META ] ) ) {
			update_post_meta( $product_id, \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META, 'yes' );
		} else {
			delete_post_meta( $product_id, \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Allow duplicate SKUs for products that are translation siblings.
	 *
	 * @param bool|mixed $sku_found Whether a duplicate SKU was found.
	 * @param int        $product_id Current product ID.
	 * @param string     $sku The SKU being checked.
	 * @return bool|mixed False for a translation sibling; otherwise WC's value, unchanged.
	 */
	public function allow_translation_duplicate_sku( $sku_found, int $product_id, string $sku ) {
		// Untyped: WC_Data_Store::__call() gives null for a store without is_existing_sku(). Return
		// the value unchanged, never coerced. See docs-ai/04-do-not-fix.md, C1.
		if ( ! $sku_found || $sku === '' ) {
			return $sku_found;
		}

		// Allow the shared SKU only when EVERY other holder is a translation
		// sibling; a genuine third-party duplicate keeps WC's rejection.
		return $this->siblings_own_all_holders( 'sku', $sku, $product_id ) ? false : $sku_found;
	}

	/**
	 * Allow a shared GTIN/UPC/EAN (`_global_unique_id`, WC 9.1+) across translation siblings.
	 *
	 * @param bool|mixed $found            Whether a duplicate global unique ID was found.
	 * @param int        $product_id       Current product ID.
	 * @param string     $global_unique_id The value being checked.
	 * @return bool|mixed False if every other holder is a sibling; otherwise WC's value, unchanged.
	 */
	public function allow_translation_duplicate_global_unique_id( $found, int $product_id, string $global_unique_id ) {
		// Untyped: WC core filters an undefined variable (null) when the data store lacks
		// is_existing_global_unique_id(). Return the value unchanged, never coerced.
		if ( ! $found || $global_unique_id === '' ) {
			return $found;
		}

		return $this->siblings_own_all_holders( 'global_unique_id', $global_unique_id, $product_id ) ? false : $found;
	}

	/**
	 * Whether every OTHER product or variation holding a lookup value is a translation sibling.
	 *
	 * WC's own lookups return only the lowest matching id, which cannot tell the sibling case apart.
	 *
	 * @param string $column     'sku' or 'global_unique_id'.
	 * @param string $value      The value being validated.
	 * @param int    $product_id The product under validation.
	 * @return bool True when at least one other holder exists and all are siblings.
	 */
	private function siblings_own_all_holders( string $column, string $value, int $product_id ): bool {
		$holders = $this->lookup_value_holders( $column, $value, $product_id );

		if ( $holders === [] ) {
			// WC reported a conflict but the lookup table lists no other holder
			// (e.g. its row is not built yet): defer to WC's own decision.
			return false;
		}

		$plugin = \PerfLocale\Plugin::get_instance();
		$repo   = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );

		foreach ( $holders as $holder_id ) {
			if ( ! $this->is_translation_sibling( $product_id, $holder_id, $repo ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether $holder_id is $product_id in another language: a linked sibling or its variation.
	 *
	 * Variations are not group-linked, but WC validates their SKUs and GTINs too.
	 *
	 * @param int                                                          $product_id Product under validation.
	 * @param int                                                          $holder_id  Other holder of the value.
	 * @param \PerfLocale\Database\Repository\TranslationGroupRepository    $repo       Group repository.
	 * @return bool
	 */
	private function is_translation_sibling( int $product_id, int $holder_id, \PerfLocale\Database\Repository\TranslationGroupRepository $repo ): bool {
		foreach ( $repo->get_translations( $product_id, \PerfLocale\Enum\ObjectType::Post ) as $link ) {
			if ( (int) $link->object_id === $holder_id ) {
				return true;
			}
		}

		$parent        = wp_get_post_parent_id( $product_id );
		$holder_parent = wp_get_post_parent_id( $holder_id );

		if ( $parent > 0 && $holder_parent > 0 ) {
			foreach ( $repo->get_translations( $parent, \PerfLocale\Enum\ObjectType::Post ) as $link ) {
				if ( (int) $link->object_id === $holder_parent ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Every product or variation except $product_id holding $value in a lookup-table column.
	 *
	 * WC's is_existing_sku() query without its LIMIT 1.
	 *
	 * @param string $column     'sku' or 'global_unique_id'.
	 * @param string $value      The value to match.
	 * @param int    $product_id The product to exclude.
	 * @return int[]
	 */
	private function lookup_value_holders( string $column, string $value, int $product_id ): array {
		global $wpdb;

		// $column is interpolated as a SQL identifier - restrict it to the two
		// known lookup columns so it can never carry arbitrary input.
		if ( ! in_array( $column, [ 'sku', 'global_unique_id' ], true ) ) {
			return [];
		}

		// wp_slash() matches WC's own lookup parameter handling. Write context only (product save),
		// so no result cache.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT lookup.product_id
				FROM {$wpdb->posts} AS posts
				INNER JOIN %i AS lookup ON posts.ID = lookup.product_id
				WHERE posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND lookup.%i = %s
				AND lookup.product_id <> %d",
				$wpdb->prefix . 'wc_product_meta_lookup',
				$column,
				wp_slash( $value ),
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Swap each product ID for its current-language sibling; untranslated products pass through.
	 *
	 * @param mixed $ids Saved product IDs.
	 * @return array<int, int>
	 */
	public function map_related_ids_to_language( $ids ) {
		$ids = array_map( 'intval', (array) $ids );

		if ( $ids === [] || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $ids;
		}

		$current_slug = \PerfLocale\Plugin::get_instance()->get( 'router' )->get_current_slug();

		if ( $current_slug === '' ) {
			return $ids;
		}

		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository(
			\PerfLocale\Plugin::get_instance()->get( 'cache' )
		);

		foreach ( $ids as $i => $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			foreach ( (array) $repo->get_translations( $id, \PerfLocale\Enum\ObjectType::Post ) as $link ) {
				if ( isset( $link->language_slug ) && $link->language_slug === $current_slug ) {
					$sibling = (int) $link->object_id;

					if ( $sibling > 0 && get_post_status( $sibling ) === 'publish' ) {
						$ids[ $i ] = $sibling;
					}
					break;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Expand a coupon's product-ID restriction list with each product's translation siblings.
	 *
	 * @param mixed $ids Saved product IDs.
	 * @return array<int, int>
	 */
	public function expand_coupon_product_ids( $ids ) {
		return $this->expand_ids_with_siblings( (array) $ids, \PerfLocale\Enum\ObjectType::Post );
	}

	/**
	 * Expand a coupon's category-ID restriction list with each term's translation siblings.
	 *
	 * @param mixed $ids Saved term IDs.
	 * @return array<int, int>
	 */
	public function expand_coupon_term_ids( $ids ) {
		return $this->expand_ids_with_siblings( (array) $ids, \PerfLocale\Enum\ObjectType::Term );
	}

	/**
	 * Merge each ID's translation-group siblings into the list.
	 *
	 * Never on admin screens: a list expanded on the coupon screen would persist on the next save.
	 *
	 * @param array<int, mixed>          $ids  Saved object IDs.
	 * @param \PerfLocale\Enum\ObjectType $type Post or Term.
	 * @return array<int, int>
	 */
	private function expand_ids_with_siblings( array $ids, \PerfLocale\Enum\ObjectType $type ): array {
		if ( $ids === [] || ( is_admin() && ! wp_doing_ajax() ) ) {
			return array_map( 'intval', $ids );
		}

		if ( ! class_exists( 'PerfLocaleWooCommerceCoupons', false ) ) {
			require_once __DIR__ . '/PerfLocaleWooCommerceCoupons.php';
		}

		return \PerfLocaleWooCommerceCoupons::expand( $ids, $type );
	}

	/**
	 * Clear sessionStorage cart fragments that do not belong to the current language's keys.
	 *
	 * @return void
	 */
	public function clear_stale_cart_fragments(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! wp_script_is( 'wc-cart-fragments', 'enqueued' ) ) {
			return;
		}

		// Every wc_fragments_* / wc_cart_hash_* entry except the current language's two keys is stale.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Calling WooCommerce core filters to match their fragment key generation.
		$current_fragment = apply_filters(
			'woocommerce_cart_fragment_name',
			'wc_fragments_' . md5( get_current_blog_id() . '_' . get_site_url( get_current_blog_id(), '/' ) . get_template() )
		);

		$current_hash_key = apply_filters(
			'woocommerce_cart_hash_key',
			'wc_cart_hash_' . md5( get_current_blog_id() . '_' . get_site_url( get_current_blog_id(), '/' ) . get_template() )
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// The keys come through woocommerce_* filters: hex-encode them for the inline script context.
		$keep_json = wp_json_encode(
			[ $current_fragment, $current_hash_key ],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		wp_add_inline_script(
			'wc-cart-fragments',
			'(function(){' .
				'try{' .
					'var keep=' . $keep_json . ';' .
					'var keys=Object.keys(sessionStorage);' .
					'for(var i=0;i<keys.length;i++){' .
						'var k=keys[i];' .
						'if((k.indexOf("wc_fragments_")===0||k.indexOf("wc_cart_hash_")===0)&&keep.indexOf(k)===-1){' .
							'sessionStorage.removeItem(k);' .
						'}' .
					'}' .
				'}catch(e){}' .
			'})();',
			'before'
		);
	}

	/**
	 * Translate a WooCommerce attribute label via PerfLocale's String Translation.
	 *
	 * @param string           $label Attribute label (e.g. "Color").
	 * @param string           $name Attribute taxonomy name (e.g. "pa_color").
	 * @param \WC_Product|null $product Product object or null.
	 * @return string Translated label or original.
	 */
	public function translate_attribute_label( string $label, string $name, $product ): string {
		if ( $label === '' ) {
			return $label;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		// The default language serves the raw label: skip the lookups.
		if ( $plugin->has( 'router' ) ) {
			$router  = $plugin->get( 'router' );
			$default = $router->get_default_language();

			if ( $default && $router->get_current_slug() === $default->slug ) {
				return $label;
			}
		}

		// Try both string translation services (DB mode and file mode).
		$services = [ 'string_translation', 'translation_file_loader' ];

		foreach ( $services as $service_id ) {
			if ( ! $plugin->has( $service_id ) ) {
				continue;
			}

			$st = $plugin->get( $service_id );

			if ( ! method_exists( $st, 'get_translation' ) ) {
				continue;
			}

			// Per-attribute context first, as the writers register it; see attribute_label_context().
			$translated = $st->get_translation(
				$label,
				'woocommerce',
				$this->attribute_label_context( $name )
			);

			if ( $translated !== null ) {
				return $translated;
			}

			// Shared 'attribute_label' context, still read for a row stored under it (at most one per store).
			$translated = $st->get_translation( $label, 'woocommerce', 'attribute_label' );

			if ( $translated !== null ) {
				return $translated;
			}

			// Fallback: try without context (in case registered without context).
			$translated = $st->get_translation( $label, 'woocommerce', '' );

			if ( $translated !== null ) {
				return $translated;
			}
		}

		return $label;
	}

	/**
	 * Register a WC attribute label as a string when the attribute is created or updated.
	 *
	 * @param int                  $id Attribute ID.
	 * @param array<string, mixed> $data Attribute data.
	 * @return void
	 */
	public function register_attribute_label_string( int $id, array $data ): void {
		$label = $data['attribute_label'] ?? ( $data['name'] ?? '' );

		if ( $label === '' ) {
			return;
		}

		$this->ensure_string_registered(
			$label,
			'woocommerce',
			$this->attribute_label_context( (string) ( $data['attribute_name'] ?? '' ) )
		);
	}

	/**
	 * Build the per-attribute translation context for an attribute label.
	 *
	 * Must be unique per attribute: register_setting_string() treats rows sharing a (domain, context)
	 * as revisions of one setting and deletes the others. The name is normalised so `color`
	 * (writers) and `pa_color` (the label filter) agree.
	 *
	 * @param string $name Attribute name, with or without the `pa_` prefix.
	 * @return string Context string; the shared context only for an unknown name.
	 */
	private function attribute_label_context( string $name ): string {
		if ( $name === '' ) {
			return 'attribute_label';
		}

		$slug = function_exists( 'wc_attribute_taxonomy_slug' )
			? wc_attribute_taxonomy_slug( $name )
			: preg_replace( '/^pa_/', '', $name );

		return 'attribute_label_' . (string) $slug;
	}

	/**
	 * Register all existing WC attribute labels as strings, on "Scan for Strings".
	 *
	 * @return void
	 */
	public function sync_attribute_labels(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		$attributes = wc_get_attribute_taxonomies();

		foreach ( $attributes as $attr ) {
			if ( ! empty( $attr->attribute_label ) ) {
				$this->ensure_string_registered(
					$attr->attribute_label,
					'woocommerce',
					$this->attribute_label_context( (string) ( $attr->attribute_name ?? '' ) )
				);
			}
		}
	}

	/**
	 * Register WC email subjects, headings and additional content as strings.
	 *
	 * Runs on "Scan for Strings" and woocommerce_settings_saved; other writes of one email's
	 * settings go through register_changed_email_strings().
	 *
	 * @return void
	 */
	public function sync_email_strings(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$mailer = WC()->mailer();

		if ( ! $mailer ) {
			return;
		}

		$emails = $mailer->get_emails();
		$fields = self::EMAIL_STRING_FIELDS;

		foreach ( $emails as $email ) {
			if ( ! $email instanceof \WC_Email ) {
				continue;
			}

			foreach ( $fields as $field ) {
				$default_method = 'get_default_' . $field;

				if ( ! method_exists( $email, $default_method ) ) {
					continue;
				}

				$value = $email->get_option( $field, $email->{$default_method}() );

				if ( $value === '' ) {
					continue;
				}

				$this->ensure_string_registered(
					$value,
					'woocommerce',
					"email_{$field}_{$email->id}"
				);
			}
		}
	}

	/**
	 * Register the email strings of a created WC email settings option (added_option).
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Stored value.
	 * @return void
	 */
	public function register_email_strings_on_add( $option, $value = null ): void {
		$this->register_changed_email_strings( $option, null, $value );
	}

	/**
	 * Register the email strings of an updated WC email settings option (updated_option).
	 *
	 * @param mixed $option    Option name.
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     Stored value.
	 * @return void
	 */
	public function register_email_strings_on_update( $option, $old_value = null, $value = null ): void {
		$this->register_changed_email_strings( $option, $old_value, $value );
	}

	/**
	 * Register the email texts that a write of one `woocommerce_{id}_settings` option changed.
	 *
	 * Runs on every option write, so all work before the mailer lookup is string and array work.
	 * Texts come from the NEW value: a direct update_option() leaves the mailer's copy stale.
	 * Skipped while installing, before init and inside the WC Settings save (covered by its hook).
	 *
	 * @param mixed $option    Option name.
	 * @param mixed $old_value Previous value; null for a new option.
	 * @param mixed $value     Stored value.
	 * @return void
	 */
	private function register_changed_email_strings( $option, $old_value, $value ): void {
		if ( ! is_string( $option ) || ! is_array( $value ) || ! str_starts_with( $option, 'woocommerce_' ) || ! str_ends_with( $option, '_settings' ) ) {
			return;
		}

		$previous = is_array( $old_value ) ? $old_value : [];
		$changed  = [];

		foreach ( self::EMAIL_STRING_FIELDS as $field ) {
			if ( ( $value[ $field ] ?? null ) !== ( $previous[ $field ] ?? null ) ) {
				$changed[] = $field;
			}
		}

		if ( [] === $changed || wp_installing() || ( defined( 'WC_INSTALLING' ) && WC_INSTALLING ) || ! did_action( 'init' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$tab = $GLOBALS['current_tab'] ?? '';

		if ( is_string( $tab ) && '' !== $tab && doing_action( 'woocommerce_settings_save_' . $tab ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$email  = null;

		foreach ( ( $mailer ? $mailer->get_emails() : [] ) as $candidate ) {
			if ( $candidate instanceof \WC_Email && $candidate->get_option_key() === $option ) {
				$email = $candidate;
				break;
			}
		}

		if ( null === $email ) {
			return;
		}

		// Read through the email's own get_option() (defaults and filter included), as EmailTranslation
		// does at send time, on a copy holding the new value.
		$reader           = clone $email;
		$reader->settings = $value;

		foreach ( $changed as $field ) {
			$default_method = 'get_default_' . $field;

			if ( ! method_exists( $reader, $default_method ) ) {
				continue;
			}

			$text = $reader->get_option( $field, $reader->{$default_method}() );

			if ( ! is_string( $text ) || '' === $text ) {
				continue;
			}

			$this->ensure_string_registered( $text, 'woocommerce', "email_{$field}_{$reader->id}" );
		}
	}

	/**
	 * Ensure a string is registered in PerfLocale's String Translation system.
	 *
	 * @param string $text Original text to register.
	 * @param string $domain Text domain (default: 'woocommerce').
	 * @param string $context Translation context (default: 'attribute_label').
	 * @return void
	 */
	private function ensure_string_registered( string $text, string $domain = 'woocommerce', string $context = 'attribute_label' ): void {
		if ( ! \PerfLocale\Database\Schema::tables_exist() ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->get( 'cache' );
		$repo   = new \PerfLocale\Database\Repository\StringRepository( $cache );

		$repo->register_setting_string( $text, $domain, $context );
	}

	/**
	 * Point a variation row at its parent product's editor.
	 *
	 * A variation has no standalone editor; without a product parent it gets no link, not a blank screen.
	 *
	 * @param string $url       URL resolved so far ('' when core could not).
	 * @param int    $post_id   Object being linked.
	 * @param string $post_type Its post type.
	 * @return string
	 */
	public function edit_link( string $url, int $post_id, string $post_type ): string {
		if ( 'product_variation' !== $post_type ) {
			return $url;
		}

		$parent_id = (int) wp_get_post_parent_id( $post_id );
		$parent    = $parent_id > 0 ? get_post( $parent_id ) : null;

		// Historical or incomplete imports can leave a non-product parent ID.
		if ( ! $parent instanceof \WP_Post || $parent->post_type !== 'product' ) {
			return '';
		}

		$parent_url = get_edit_post_link( $parent_id, 'raw' );

		return is_string( $parent_url ) ? $parent_url : '';
	}

	/**
	 * Restore the front-end link for a product variation.
	 *
	 * @param string   $url  URL resolved so far ('' when the type is not viewable).
	 * @param \WP_Post $post Object being linked.
	 * @return string
	 */
	public function view_link( string $url, \WP_Post $post ): string {
		if ( 'product_variation' !== $post->post_type ) {
			return $url;
		}

		$parent_id = (int) wp_get_post_parent_id( $post->ID );
		$parent    = $parent_id > 0 ? get_post( $parent_id ) : null;

		// ⚠️ A positive parent ID does not prove a usable product exists. Woo's
		// fallback can otherwise look like a link while opening no product.
		if ( ! $parent instanceof \WP_Post || $parent->post_type !== 'product'
			|| in_array( $parent->post_status, [ 'trash', 'auto-draft' ], true )
			|| in_array( $post->post_status, [ 'trash', 'auto-draft' ], true )
		) {
			return $url;
		}

		$permalink = get_permalink( $post );

		return is_string( $permalink ) ? $permalink : $url;
	}

	/**
	 * Add WooCommerce post types to the translatable list.
	 *
	 * @param array<int, string> $post_types Existing post types.
	 * @return array<int, string>
	 */
	public function add_post_types( array $post_types ): array {
		$post_types[] = 'product';
		$post_types[] = 'product_variation';

		return array_unique( $post_types );
	}

	/**
	 * Add WooCommerce taxonomies, every registered pa_* attribute included, to the translatable list.
	 *
	 * @param array<int, string> $taxonomies Existing taxonomies.
	 * @return array<int, string>
	 */
	public function add_taxonomies( array $taxonomies ): array {
		$taxonomies[] = 'product_cat';
		$taxonomies[] = 'product_tag';

		// No taxonomy_exists() check: this runs at init 0, before WooCommerce registers pa_* at init 5.
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attr ) {
				$taxonomies[] = wc_attribute_taxonomy_name( $attr->attribute_name );
			}
		}

		return array_unique( $taxonomies );
	}

	/**
	 * Add WooCommerce meta keys to the translatable list.
	 *
	 * @param array<int, string> $keys Existing translatable meta keys.
	 * @param string             $post_type Post type being registered.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		if ( $post_type === 'product' ) {
			$keys[] = '_purchase_note';
			$keys[] = '_button_text';
		}

		if ( $post_type === 'product_variation' ) {
			$keys[] = '_variation_description';
		}

		return $keys;
	}

	/**
	 * Filter a WC page option to return the translated page ID.
	 *
	 * WooCommerce's is_cart() and is_checkout() compare the current page with this option.
	 *
	 * @param mixed $page_id The original page ID from the option.
	 * @return mixed The translated page ID, or the original if no translation exists.
	 */
	public function filter_wc_page_id( mixed $page_id ): mixed {
		// Per-page-ID recursion guard: two WC page options can resolve in one call stack.
		static $resolving = [];
		// Blog-keyed: the one-shot WC-page prime flag must not carry across a
		// mid-request switch_to_blog() (each blog has its own WC pages).
		static $primed_by_blog = [];
		$primed_blog           = is_multisite() ? get_current_blog_id() : 0;
		$primed                = ! empty( $primed_by_blog[ $primed_blog ] );

		$page_id_int = (int) $page_id;

		if ( $page_id_int <= 0 || is_admin() ) {
			return $page_id;
		}

		if ( isset( $resolving[ $page_id_int ] ) ) {
			return $page_id;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $page_id;
		}

		$router      = $plugin->get( 'router' );
		$language_id = $router->get_current_language_id();

		if ( $language_id === 0 ) {
			return $page_id;
		}

		// First call per request and blog: batch-prime every WC page's translation links in one query.
		if ( ! $primed ) {
			$primed_by_blog[ $primed_blog ] = true;
			$this->prime_wc_page_translations( $plugin );
		}

		$resolving[ $page_id_int ] = true;

		try {
			// Container singleton: WC resolves these options many times per request.
			$groups_repo   = $plugin->get( 'group_repo' );
			$translated_id = $groups_repo->get_translation_in_language(
				$page_id_int,
				\PerfLocale\Enum\ObjectType::Post,
				$language_id
			);

			// Only a PUBLISHED translation may stand in: Generate Missing Translations creates drafts, and
			// a draft cart page breaks the whole funnel. The status read is a cache hit, primed above.
			if ( $translated_id && get_post_status( $translated_id ) === 'publish' ) {
				return $translated_id;
			}

			return $page_id;
		} finally {
			unset( $resolving[ $page_id_int ] );
		}
	}

	/**
	 * Batch-prime translation caches for every known WooCommerce page, once per request.
	 *
	 * Ids come from read_stored_option_values(): get_option() would re-enter filter_wc_page_id().
	 *
	 * @param \PerfLocale\Plugin $plugin Plugin container.
	 * @return void
	 */
	private function prime_wc_page_translations( \PerfLocale\Plugin $plugin ): void {
		$option_names = [
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_shop_page_id',
			'woocommerce_terms_page_id',
		];

		$stored = $this->read_stored_option_values( $option_names );
		$ids    = [];

		foreach ( $option_names as $option_name ) {
			$id = isset( $stored[ $option_name ] ) && is_scalar( $stored[ $option_name ] ) ? (int) $stored[ $option_name ] : 0;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( $ids === [] ) {
			return;
		}

		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
		$repo->prime_translations( \PerfLocale\Enum\ObjectType::Post, $ids );

		// Prime the translations' post rows too: filter_wc_page_id() reads each one's post_status.
		$language_id = $plugin->has( 'router' ) ? $plugin->get( 'router' )->get_current_language_id() : 0;

		if ( $language_id === 0 ) {
			return;
		}

		$translated_ids = [];

		foreach ( $ids as $source_id ) {
			$translated = $repo->get_translation_in_language(
				$source_id,
				\PerfLocale\Enum\ObjectType::Post,
				$language_id
			);

			if ( $translated ) {
				$translated_ids[] = (int) $translated;
			}
		}

		if ( $translated_ids !== [] ) {
			// Statuses only - no meta, no terms.
			_prime_post_caches( $translated_ids, false, false );
		}
	}

	/**
	 * Stored values of the given options, read without any option_* filter.
	 *
	 * Never get_option(): its filter runs filter_wc_page_id(), which returns TRANSLATED ids. Names in
	 * notoptions are never fetched (a persistent cache stores no negative entries). One direct SELECT
	 * while installing or when options_multiget_is_batched() is false. See docs-ai/04-do-not-fix.md, 2.29.
	 *
	 * @param string[] $option_names Option names.
	 * @return array<string, mixed> Option name => stored value, for the names found.
	 */
	private function read_stored_option_values( array $option_names ): array {
		$values = [];

		if ( wp_installing() || ! $this->options_multiget_is_batched() ) {
			global $wpdb;

			$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );

			// $placeholders is a %s list sized to $option_names; $wpdb->options is core-owned.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
					...$option_names
				),
				OBJECT_K
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $option_name => $row ) {
				$values[ (string) $option_name ] = $row->option_value;
			}

			return $values;
		}

		$alloptions = wp_load_alloptions();
		$pending    = [];

		foreach ( $option_names as $option_name ) {
			if ( isset( $alloptions[ $option_name ] ) ) {
				$values[ $option_name ] = $alloptions[ $option_name ];
			} else {
				$pending[] = $option_name;
			}
		}

		if ( $pending === [] ) {
			return $values;
		}

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) ) {
			$pending = array_values( array_diff( $pending, array_keys( $notoptions ) ) );
		}

		if ( $pending === [] ) {
			return $values;
		}

		wp_prime_option_caches( $pending );

		foreach ( wp_cache_get_multiple( $pending, 'options' ) as $option_name => $value ) {
			if ( false !== $value ) {
				$values[ (string) $option_name ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Whether a wp_cache_get_multiple() call costs at most one round trip.
	 *
	 * True for the in-request cache and Redis Object Cache 2.x (redis_instance() and get_multiple()).
	 * Not wp_cache_supports(): LiteSpeed's get_multiple() loops; nor redis_instance() alone: Hummingbird
	 * and Redis Object Cache before 2.0 lack get_multiple(). See docs-ai/04-do-not-fix.md, 2.29.
	 *
	 * @return bool
	 */
	private function options_multiget_is_batched(): bool {
		global $wp_object_cache;

		if ( ! wp_using_ext_object_cache() ) {
			return true;
		}

		return is_object( $wp_object_cache )
			&& method_exists( $wp_object_cache, 'redis_instance' )
			&& method_exists( $wp_object_cache, 'get_multiple' );
	}

	/**
	 * Translate a WooCommerce URL to include the current language prefix.
	 *
	 * Anything but a non-empty string goes back untouched, type included: has_cart_page() is
	 * `wc_get_page_permalink( 'cart', -1 ) !== -1`, so a cast invents a cart page.
	 * See docs-ai/04-do-not-fix.md, C1 and C2.
	 *
	 * @param mixed $url URL to translate.
	 * @return mixed Language-prefixed URL, or $url untouched.
	 */
	public function translate_wc_url( mixed $url ): mixed {
		if ( ! is_string( $url ) || $url === '' ) {
			return $url;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return $url;
		}

		$slug = $plugin->get( 'router' )->get_current_slug();

		if ( $slug === '' ) {
			return $url;
		}

		return $plugin->get( 'url_converter' )->convert( $url, $slug );
	}

	/**
	 * Force the current language onto WooCommerce page links built with get_permalink().
	 *
	 * The Mini-Cart buttons and the checkout terms link skip wc_get_page_permalink(), and UrlConverter
	 * keeps an untranslated page in its own language for SEO. Only untranslated WC funnel pages change.
	 * See docs-ai/11-code-rationale.md, "PerfLocaleWooCommerce: shop page links".
	 *
	 * @param mixed $link    Page permalink, already filtered by UrlConverter.
	 * @param mixed $post_id ID of the page the permalink belongs to.
	 * @return mixed Language-prefixed permalink, or $link untouched.
	 */
	public function force_wc_page_language_prefix( mixed $link, mixed $post_id ): mixed {
		// Re-entry guard: this runs inside get_permalink(), and the get_option() reads below are open
		// to plugins that build permalinks.
		static $running = false;

		if ( $running || ! is_string( $link ) || $link === '' ) {
			return $link;
		}

		$page_id = (int) $post_id;

		if ( $page_id <= 0 ) {
			return $link;
		}

		// Never touch the page being rendered: its URL feeds the canonical, og:url and hreflang. Test
		// WP_Post, not get_queried_object_id(): on archives that id is a term or user id that can equal
		// a page id.
		$queried = get_queried_object();

		if ( $queried instanceof \WP_Post && (int) $queried->ID === $page_id ) {
			return $link;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) || ! $plugin->has( 'router' ) ) {
			return $link;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();
		$language_id  = $router->get_current_language_id();

		if ( $current_slug === '' || $language_id === 0 ) {
			return $link;
		}

		$running = true;

		try {
			$wc_page_ids = $this->get_wc_page_ids();

			if ( ! isset( $wc_page_ids[ $page_id ] ) ) {
				return $link;
			}

			// Non-null: this page is or has the current language's version, so UrlConverter's link stands.
			$in_current_language = $plugin->get( 'group_repo' )->get_translation_in_language(
				$page_id,
				\PerfLocale\Enum\ObjectType::Post,
				$language_id
			);

			// ...but only a PUBLISHED translation owns a URL: a draft (Generate Missing Translations creates
			// drafts) cannot be visited, so prefix the source.
			if ( $in_current_language !== null && get_post_status( $in_current_language ) === 'publish' ) {
				return $link;
			}

			return $plugin->get( 'url_converter' )->convert( $link, $current_slug );
		} finally {
			$running = false;
		}
	}

	/**
	 * WooCommerce funnel-page IDs for the current language, as an id => true set.
	 *
	 * `shop` is excluded: it is indexable and linked while other pages are queried, so the rendered-page
	 * guard cannot protect its canonical; boot() hooks wc_get_page_permalink( 'shop' ) instead.
	 * Read via get_option(), whose filter maps each page to its translation. Memoized per blog.
	 *
	 * @return array<int, bool> Page ID set (empty when WooCommerce has no pages configured).
	 */
	private function get_wc_page_ids(): array {
		static $ids_by_blog = [];

		$blog_id = is_multisite() ? get_current_blog_id() : 0;

		if ( isset( $ids_by_blog[ $blog_id ] ) ) {
			return $ids_by_blog[ $blog_id ];
		}

		$ids          = [];
		$option_names = [
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id',
		];

		// None of the four autoloads: one multi-get covers the set.
		wp_prime_option_caches( $option_names );

		foreach ( $option_names as $option_name ) {
			$id = (int) get_option( $option_name );

			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}

		$ids_by_blog[ $blog_id ] = $ids;

		return $ids;
	}

	/**
	 * Whether the current request renders an XML sitemap.
	 *
	 * Also the query-var forms (`?sitemap=`, Rank Math `?sitemap_n=`) served without flushed rewrites.
	 * REQUEST_URI is fixed early, so boot() can gate the page_link filter on it.
	 *
	 * @return bool
	 */
	private function is_sitemap_request(): bool {
		static $is_sitemap = null;

		if ( $is_sitemap === null ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$is_sitemap  = \PerfLocale\Helper::is_sitemap_uri( $request_uri )
				|| 1 === preg_match( '/[?&]sitemap(?:_n|-stylesheet|-subtype)?=/', $request_uri );
		}

		return $is_sitemap;
	}

	/**
	 * Translate cart item permalink to the current browsing language.
	 *
	 * @param string $permalink Cart item permalink.
	 * @param array  $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string Translated permalink.
	 */
	public function translate_cart_item_permalink( string $permalink, array $cart_item, string $cart_item_key ): string {
		if ( $permalink === '' ) {
			return $permalink;
		}

		$product_id = (int) ( $cart_item['product_id'] ?? 0 );

		if ( $product_id <= 0 ) {
			return $permalink;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) || ! $plugin->has( 'router' ) ) {
			return $permalink;
		}

		$current_slug = $plugin->get( 'router' )->get_current_slug();

		if ( $current_slug === '' ) {
			return $permalink;
		}

		// Find the translation of this product in the current language.
		$cache    = $plugin->get( 'cache' );
		$settings = $plugin->get( 'settings' );
		$manager  = new \PerfLocale\Translation\PostTranslationManager( $cache, $settings );

		$translated_id = $manager->get_translation_id( $product_id, $current_slug );

		// Never link a guest to a draft, pending or private sibling (as translated_product_title()); a
		// miss falls through to convert(), like an untranslated product.
		if ( $translated_id && $translated_id !== $product_id && $this->is_publicly_viewable_translation( (int) $translated_id ) ) {
			// Use the translated product's permalink.
			$translated_permalink = get_permalink( $translated_id );

			if ( $translated_permalink ) {
				// Keep only attribute_* args (all WC_Product_Variation::get_permalink() appends): the others are
				// the source's routing identity (?lang=, ?p=, ?product=) and would load the source.
				$query = wp_parse_url( $permalink, PHP_URL_QUERY );

				if ( $query ) {
					$args = wp_parse_args( $query );

					$variation_args = array_filter(
						$args,
						static fn( $k ) => is_string( $k ) && str_starts_with( $k, 'attribute_' ),
						ARRAY_FILTER_USE_KEY
					);

					if ( $variation_args !== [] ) {
						$translated_permalink = add_query_arg( $variation_args, $translated_permalink );
					}
				}

				return $translated_permalink;
			}
		}

		// No translation - just ensure the URL has the right language prefix.
		return $plugin->get( 'url_converter' )->convert( $permalink, $current_slug );
	}

	/**
	 * Translate WooCommerce account endpoint URLs.
	 *
	 * @param mixed $url Full endpoint URL.
	 * @param mixed $endpoint Endpoint slug (e.g. 'orders', 'downloads').
	 * @param mixed $value Endpoint value or empty string.
	 * @param mixed $permalink Base page permalink.
	 * @return mixed Language-prefixed URL, or $url untouched when it is not a string.
	 */
	public function translate_endpoint_url( mixed $url, mixed $endpoint, mixed $value, mixed $permalink ): mixed {
		return $this->translate_wc_url( $url );
	}

	/**
	 * Remove the term language filter while WC queries the variation dropdown's attribute terms.
	 *
	 * Otherwise TermQueryFilter drops the default-language terms and the dropdown is empty.
	 *
	 * @param array<string, mixed> $args Dropdown arguments.
	 * @return array<string, mixed>
	 */
	public function suspend_term_filter_for_dropdown( array $args ): array {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'term_query_filter' ) ) {
			$filter = $plugin->get( 'term_query_filter' );
			remove_filter( 'terms_clauses', [ $filter, 'filter_terms_by_language' ], 10 );
		}

		return $args;
	}

	/**
	 * Re-add the term language filter after the variation dropdown HTML is rendered.
	 *
	 * @param string               $html Generated dropdown HTML.
	 * @param array<string, mixed> $args Dropdown arguments.
	 * @return string
	 */
	public function restore_term_filter_after_dropdown( string $html, array $args ): string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'term_query_filter' ) ) {
			$filter = $plugin->get( 'term_query_filter' );

			if ( ! has_filter( 'terms_clauses', [ $filter, 'filter_terms_by_language' ] ) ) {
				add_filter( 'terms_clauses', [ $filter, 'filter_terms_by_language' ], 10, 3 );
			}
		}

		return $html;
	}

	/**
	 * Translate the variation name in the cart, mini-cart, and checkout.
	 *
	 * @param string $name Product name (may contain HTML link).
	 * @param array  $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string Translated product name.
	 */
	public function translate_cart_item_name( string $name, array $cart_item, string $cart_item_key ): string {
		if ( empty( $cart_item['variation_id'] ) ) {
			// Simple product: map the label to the current-language sibling, as the permalink is mapped.
			$translated_name = $this->translated_product_title( (int) ( $cart_item['product_id'] ?? 0 ) );

			if ( $translated_name === null ) {
				return $name;
			}

			return $this->replace_item_name( $name, $translated_name );
		}

		$variation = wc_get_product( $cart_item['variation_id'] );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return $name;
		}

		$translated_name = $this->build_translated_variation_name( $variation, true );

		if ( $translated_name === null ) {
			return $name;
		}

		return $this->replace_item_name( $name, $translated_name );
	}

	/**
	 * Title of a product's translation in the current language.
	 *
	 * @param int $product_id Source product ID.
	 * @return string|null Translated title, or null when there is nothing to swap.
	 */
	private function translated_product_title( int $product_id ): ?string {
		$translated_id = $this->public_translation_id( $product_id );

		if ( $translated_id <= 0 ) {
			return null;
		}

		// The stored title, as WooCommerce's own cart line name is: no
		// "Protected:" prefix or other `the_title` output.
		$title = (string) get_post_field( 'post_title', $translated_id, 'raw' );

		return $title !== '' ? $title : null;
	}

	/**
	 * ID of a product's published translation in the current language.
	 *
	 * @param int $product_id Source product ID.
	 * @return int Translation ID, or 0 when there is nothing to swap.
	 */
	private function public_translation_id( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return 0;
		}

		$current_slug = $plugin->get( 'router' )->get_current_slug();

		if ( $current_slug === '' ) {
			return 0;
		}

		$manager       = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
		$translated_id = $manager->get_translation_id( $product_id, $current_slug );

		if ( ! $translated_id || $translated_id === $product_id ) {
			return 0;
		}

		// The cart renders for guests and translations start as drafts: an unpublished sibling keeps
		// the source label, as in map_related_ids_to_language().
		if ( ! $this->is_publicly_viewable_translation( (int) $translated_id ) ) {
			return 0;
		}

		// A group linking different product types is not a translation of the purchased item: keep its
		// own title and image.
		if ( $this->product_type( $product_id ) !== $this->product_type( (int) $translated_id ) ) {
			return 0;
		}

		return (int) $translated_id;
	}

	/**
	 * A product's WooCommerce type.
	 *
	 * Via the data store (its own cache, loaded once per request); WC's factory when
	 * woocommerce_product_type_query is filtered.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string Product type, '' when the ID is not a product.
	 */
	private function product_type( int $product_id ): string {
		if ( has_filter( 'woocommerce_product_type_query' ) ) {
			return (string) \WC_Product_Factory::get_product_type( $product_id );
		}

		$this->product_data_store ??= \WC_Data_Store::load( 'product' );

		return (string) $this->product_data_store->get_product_type( $product_id );
	}

	/**
	 * Add the order language to the fields WooCommerce's personal-data exporter lists for an order.
	 *
	 * @param mixed $props Field key => label.
	 * @return mixed
	 */
	public function add_order_language_export_prop( mixed $props ): mixed {
		if ( ! is_array( $props ) ) {
			return $props;
		}

		$props['perflocale_language'] = __( 'Order language', 'perflocale' );

		return $props;
	}

	/**
	 * Personal-data export value of the order language: its name, or its code when it is missing.
	 *
	 * @param mixed $value Value so far.
	 * @param mixed $prop  Field key.
	 * @param mixed $order Order being exported.
	 * @return mixed
	 */
	public function export_order_language_prop( mixed $value, mixed $prop = '', mixed $order = null ): mixed {
		if ( 'perflocale_language' !== $prop || ! $order instanceof \WC_Order ) {
			return $value;
		}

		$slug = sanitize_key( (string) $order->get_meta( '_perflocale_language', true ) );

		if ( $slug === '' ) {
			return $value;
		}

		$language = null;

		try {
			$language = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' )->find_by_slug( $slug );
		} catch ( \Throwable $e ) {
			$language = null;
		}

		$name = is_object( $language ) && isset( $language->name ) ? (string) $language->name : '';

		return $name !== '' ? $name : $slug;
	}

	/**
	 * Open the Store API cart window: attach the cart-line mappers.
	 *
	 * Covers HTTP calls, REST preloads and batch sub-requests; block hydration opens it through
	 * dispatch_store_api_hydration(). Only the cart's own product objects are mapped; price, stock,
	 * item data and the order line name stay the purchased product's.
	 *
	 * @param mixed $response Result so far (passed through).
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request being dispatched.
	 * @return mixed $response, unchanged.
	 */
	public function open_store_api_cart_window( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		if ( ! $request instanceof \WP_REST_Request || ! self::is_store_api_cart_window_route( $request->get_route() ) ) {
			return $response;
		}

		if ( 0 === $this->store_api_cart_depth++ ) {
			add_filter( 'woocommerce_product_title', [ $this, 'map_store_api_cart_title' ], 10, 2 );
			add_filter( 'woocommerce_product_get_short_description', [ $this, 'map_store_api_cart_short_description' ], 10, 2 );
			add_filter( 'woocommerce_product_get_image_id', [ $this, 'map_store_api_cart_image_id' ], 10, 2 );
			add_filter( 'woocommerce_product_variation_get_image_id', [ $this, 'map_store_api_cart_image_id' ], 10, 2 );
			add_filter( 'woocommerce_product_get_gallery_image_ids', [ $this, 'map_store_api_cart_gallery_image_ids' ], 10, 2 );
		}

		return $response;
	}

	/**
	 * Close the Store API cart window opened for the same request.
	 *
	 * Core fires this filter for every request that fired the opening one, errors included.
	 *
	 * @param mixed $response Result to send (passed through).
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request being dispatched.
	 * @return mixed $response, unchanged.
	 */
	public function close_store_api_cart_window( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		if ( $this->store_api_cart_depth <= 0 || ! $request instanceof \WP_REST_Request || ! self::is_store_api_cart_window_route( $request->get_route() ) ) {
			return $response;
		}

		if ( 0 === --$this->store_api_cart_depth ) {
			remove_filter( 'woocommerce_product_title', [ $this, 'map_store_api_cart_title' ], 10 );
			remove_filter( 'woocommerce_product_get_short_description', [ $this, 'map_store_api_cart_short_description' ], 10 );
			remove_filter( 'woocommerce_product_get_image_id', [ $this, 'map_store_api_cart_image_id' ], 10 );
			remove_filter( 'woocommerce_product_variation_get_image_id', [ $this, 'map_store_api_cart_image_id' ], 10 );
			remove_filter( 'woocommerce_product_get_gallery_image_ids', [ $this, 'map_store_api_cart_gallery_image_ids' ], 10 );

			$this->store_api_cart_siblings = [];
			$this->store_api_cart_primed   = false;
		}

		return $response;
	}

	/**
	 * Run a Store API cart or checkout route for WooCommerce's block hydration inside the cart window.
	 *
	 * Hydration calls the handler directly; this runs last and only when nothing answered. The window
	 * and Inventory Sync's frame close in `finally`, so a throw cannot leave the mappers attached.
	 *
	 * @param mixed $result  Result so far; anything but null is passed through.
	 * @param mixed $request Request built for the hydrated path.
	 * @param mixed $path    Hydrated path.
	 * @param mixed $handler Route handler WooCommerce matched.
	 * @return mixed The handler's response, or $result unchanged.
	 */
	public function dispatch_store_api_hydration( mixed $result, mixed $request = null, mixed $path = '', mixed $handler = null ): mixed {
		unset( $path );

		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! is_array( $handler ) || ! isset( $handler['callback'] ) || ! is_callable( $handler['callback'] ) || ! self::is_store_api_cart_window_route( $request->get_route() ) ) {
			return $result;
		}

		$this->open_store_api_cart_window( null, $handler, $request );

		try {
			if ( $this->inventory_sync_registered ) {
				\PerfLocale\WooCommerce\InventorySyncHooks::enter_store_api_request( $request, \PerfLocale\WooCommerce\InventorySyncHooks::is_display_cart_read( $request ) );
			}

			return call_user_func( $handler['callback'], $request );
		} finally {
			if ( $this->inventory_sync_registered ) {
				\PerfLocale\WooCommerce\InventorySyncHooks::leave_store_api_request( $request );
			}
			$this->close_store_api_cart_window( null, $handler, $request );
		}
	}

	/**
	 * Whether a REST route opens the Store API cart window: /cart and below, /checkout (not order-pay).
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	private static function is_store_api_cart_window_route( string $route ): bool {
		return str_starts_with( $route, '/wc/store/' )
			&& 1 === preg_match( '#^/wc/store(?:/v[0-9]+)?/(?:cart(?:/|$)|checkout/?$)#', $route );
	}

	/**
	 * Store API cart line name: the published sibling's title (a variation maps through its parent).
	 *
	 * @param mixed $title   Product title.
	 * @param mixed $product Product object.
	 * @return mixed
	 */
	public function map_store_api_cart_title( mixed $title, mixed $product = null ): mixed {
		$sibling = $this->store_api_cart_sibling( $product );

		return ( $sibling !== null && $sibling['title'] !== null ) ? $sibling['title'] : $title;
	}

	/**
	 * Store API cart line short description: the published sibling's, even when empty.
	 *
	 * A sibling still password-locked for this visitor keeps the purchased product's; variations are skipped.
	 *
	 * @param mixed $value   Short description.
	 * @param mixed $product Product object.
	 * @return mixed
	 */
	public function map_store_api_cart_short_description( mixed $value, mixed $product = null ): mixed {
		$sibling = $this->store_api_cart_sibling( $product );

		return ( $sibling !== null && $sibling['excerpt'] !== null ) ? $sibling['excerpt'] : $value;
	}

	/**
	 * Store API cart line image: the published sibling's own image, when it has one.
	 *
	 * @param mixed $image_id Image attachment ID.
	 * @param mixed $product  Product object.
	 * @return mixed
	 */
	public function map_store_api_cart_image_id( mixed $image_id, mixed $product = null ): mixed {
		$sibling = $this->store_api_cart_sibling( $product );

		return ( $sibling !== null && $sibling['image'] > 0 ) ? $sibling['image'] : $image_id;
	}

	/**
	 * Store API cart line gallery: follows the image, so a line never mixes the two products.
	 *
	 * @param mixed $ids     Gallery attachment IDs.
	 * @param mixed $product Product object.
	 * @return mixed
	 */
	public function map_store_api_cart_gallery_image_ids( mixed $ids, mixed $product = null ): mixed {
		$sibling = $this->store_api_cart_sibling( $product );

		return ( $sibling !== null && $sibling['gallery'] !== null ) ? $sibling['gallery'] : $ids;
	}

	/**
	 * Current-language sibling data for a product object the cart holds.
	 *
	 * Identity, not ID: a cross-sell built from the same ID is left alone. The first call per window
	 * primes every line's sibling post and meta.
	 *
	 * @param mixed $product Product object.
	 * @return array{title: ?string, excerpt: ?string, image: int, gallery: ?array<int, int>}|null
	 */
	private function store_api_cart_sibling( mixed $product ): ?array {
		if ( ! $product instanceof \WC_Product || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return null;
		}

		$line = null;

		foreach ( WC()->cart->cart_contents as $cart_item ) {
			if ( ( $cart_item['data'] ?? null ) === $product ) {
				$line = $cart_item;
				break;
			}
		}

		if ( $line === null ) {
			return null;
		}

		$product_id   = (int) ( $line['product_id'] ?? 0 );
		$variation_id = (int) ( $line['variation_id'] ?? 0 );
		$plugin       = \PerfLocale\Plugin::get_instance();
		$slug         = $plugin->has( 'router' ) ? (string) $plugin->get( 'router' )->get_current_slug() : '';
		$key          = get_current_blog_id() . ':' . $slug . ':' . $product_id . ':' . $variation_id;

		if ( array_key_exists( $key, $this->store_api_cart_siblings ) ) {
			return $this->store_api_cart_siblings[ $key ];
		}

		// First call: prime every line's sibling posts and meta in two queries, not one read per line.
		if ( ! $this->store_api_cart_primed ) {
			$this->store_api_cart_primed = true;

			if ( $slug !== '' ) {
				$manager = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
				$prime   = [];

				foreach ( WC()->cart->cart_contents as $cart_item ) {
					$pid = (int) ( $cart_item['product_id'] ?? 0 );
					$tid = $pid > 0 ? (int) $manager->get_translation_id( $pid, $slug ) : 0;

					if ( $tid > 0 && $tid !== $pid ) {
						$prime[ $tid ] = $tid;
					}
				}

				if ( $prime !== [] ) {
					_prime_post_caches( array_values( $prime ), false, true );
				}
			}
		}

		$sibling_id = $this->public_translation_id( $product_id );
		$data       = null;

		if ( $sibling_id > 0 ) {
			// The stored title, as WooCommerce's own product name is: no
			// "Protected:" prefix or other `the_title` output.
			$title   = (string) get_post_field( 'post_title', $sibling_id, 'raw' );
			$image   = 0;
			$gallery = null;

			if ( $variation_id > 0 ) {
				// A variation's own image stays (translated variations copy it and are not linked one-to-one);
				// one using its parent's image follows the sibling parent.
				if ( (int) $product->get_image_id( 'edit' ) <= 0 ) {
					$image = (int) get_post_thumbnail_id( $sibling_id );
				}
			} else {
				$image = (int) get_post_thumbnail_id( $sibling_id );

				if ( $image > 0 ) {
					$gallery = array_values( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $sibling_id, '_product_image_gallery', true ) ) ) ) );
				}
			}

			$data = [
				'title'   => $title !== '' ? $title : null,
				// A password-locked sibling keeps the purchased product's excerpt, as WC's own responses do.
				'excerpt' => ( $variation_id > 0 || post_password_required( $sibling_id ) ) ? null : (string) get_post_field( 'post_excerpt', $sibling_id, 'raw' ),
				'image'   => $image,
				'gallery' => $gallery,
			];
		}

		$this->store_api_cart_siblings[ $key ] = $data;

		return $data;
	}

	/**
	 * Whether a translated post may be shown to the current visitor.
	 *
	 * Not in PostTranslationManager::get_translation_id(): the editor and translation workflows
	 * resolve drafts, so the rule belongs to the public display consumer.
	 *
	 * @param int $post_id Translated post ID.
	 * @return bool
	 */
	private function is_publicly_viewable_translation( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'is_post_publicly_viewable' ) ) {
			return (bool) is_post_publicly_viewable( $post_id );
		}

		$status = get_post_status( $post_id );

		return is_string( $status ) && in_array( $status, get_post_stati( [ 'public' => true ] ), true );
	}

	/**
	 * Swap a cart row's visible label, preserving any surrounding <a> wrapper.
	 *
	 * @param string $name            Original (possibly linked) name markup.
	 * @param string $translated_name Replacement text.
	 * @return string
	 */
	private function replace_item_name( string $name, string $translated_name ): string {
		// The $name may be wrapped in an <a> tag - replace the inner text.
		if ( str_contains( $name, '<a ' ) ) {
			// A callback, not preg_replace(): a name like "Gift Card $50" would read as a backreference.
			return preg_replace_callback(
				'#>([^<]+)</a>#',
				static fn(): string => '>' . esc_html( $translated_name ) . '</a>',
				$name,
				1
			) ?? $name;
		}

		return esc_html( $translated_name );
	}

	/**
	 * Translate the variation name in order item display.
	 *
	 * @param string         $name Product name.
	 * @param \WC_Order_Item $item Order item.
	 * @return string Translated product name.
	 */
	public function translate_order_item_name( string $name, $item ): string {
		if ( ! method_exists( $item, 'get_variation_id' ) ) {
			return $name;
		}

		$variation_id = $item->get_variation_id();

		if ( ! $variation_id ) {
			return $name;
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return $name;
		}

		// The parent's stored title, as the order line WooCommerce stored and
		// the cart line are: no "Protected:" prefix or other `the_title` output.
		$translated_name = $this->build_translated_variation_name( $variation, true );

		if ( $translated_name === null ) {
			return $name;
		}

		// The $name may be wrapped in an <a> tag.
		if ( str_contains( $name, '<a ' ) ) {
			// A callback, not preg_replace(): a name like "Gift Card $50" would read as a backreference.
			return preg_replace_callback(
				'#>([^<]+)</a>#',
				static fn(): string => '>' . esc_html( $translated_name ) . '</a>',
				$name,
				1
			) ?? $name;
		}

		return esc_html( $translated_name );
	}

	/**
	 * Build a translated variation name: the parent title plus translated attribute values.
	 *
	 * @param \WC_Product_Variation $variation    Variation product.
	 * @param bool                  $stored_title Use the parent's stored title (as WC's cart line does), not get_the_title().
	 * @return string|null Translated name, or null when no translation is needed or possible.
	 */
	private function build_translated_variation_name( \WC_Product_Variation $variation, bool $stored_title = false ): ?string {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return null;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return null;
		}

		// Skip on default language - the stored variation title is already correct.
		$default = $router->get_default_language();

		if ( $default && $current_slug === $default->slug ) {
			return null;
		}

		$attributes = $variation->get_attributes();

		if ( empty( $attributes ) ) {
			return null;
		}

		// Check if attributes should be included in the title.
		$should_include = count( $attributes ) < 3;

		if ( $should_include && count( $attributes ) > 1 ) {
			foreach ( $attributes as $attr_name => $attr_val ) {
				if ( str_contains( $attr_name, '-' ) ) {
					$should_include = false;
					break;
				}
			}
		}

		/** This filter is documented in WooCommerce class-wc-product-variation-data-store-cpt.php */
		$should_include = apply_filters( 'woocommerce_product_variation_title_include_attributes', $should_include, $variation ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.

		if ( ! $should_include ) {
			return null;
		}

		$term_manager = $this->get_term_manager();

		$translated_parts = [];
		$has_translation  = false;

		foreach ( $attributes as $taxonomy => $slug_value ) {
			// "Any <attribute>" variations have an empty slug; WooCommerce omits them from the title.
			if ( $slug_value === '' ) {
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				// Custom (non-taxonomy) attribute - keep its raw stored value.
				$translated_parts[] = $slug_value;
				continue;
			}

			$term = get_term_by( 'slug', $slug_value, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				$translated_parts[] = $slug_value;
				continue;
			}

			$translated_id = $term_manager->get_translation_id( $term->term_id, $current_slug );

			if ( $translated_id !== null && $translated_id !== $term->term_id ) {
				$translated_term = get_term( $translated_id );

				if ( $translated_term instanceof \WP_Term ) {
					$translated_parts[] = $translated_term->name;
					$has_translation    = true;
					continue;
				}
			}

			$translated_parts[] = $term->name;
		}

		// Only return a translated name if at least one attribute was actually translated.
		if ( ! $has_translation ) {
			return null;
		}

		$title_base = $stored_title
			? (string) get_post_field( 'post_title', $variation->get_parent_id(), 'raw' )
			: get_the_title( $variation->get_parent_id() );

		/** This filter is documented in WooCommerce class-wc-product-variation-data-store-cpt.php */
		$separator = apply_filters( 'woocommerce_product_variation_title_attributes_separator', ' - ', $variation ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.

		return $title_base . $separator . implode( ', ', $translated_parts );
	}

	/**
	 * Name a new variation order line in the checkout language.
	 *
	 * Same name as the cart showed: the parent's stored title plus attribute values in that language.
	 * Unchanged on the default language, without translated values and for other product types.
	 *
	 * @param \WC_Order_Item_Product|mixed $item          Order line being created.
	 * @param string                       $cart_item_key Cart item key.
	 * @param array<string, mixed>|mixed   $values        Cart item.
	 * @param \WC_Order|null|mixed         $order         Order being created.
	 * @return void
	 */
	public function name_order_line_in_checkout_language( $item, $cart_item_key = '', $values = [], $order = null ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		if ( $item->get_variation_id() <= 0 ) {
			return;
		}

		// The cart line's own object: WooCommerce built the line from it.
		$variation = is_array( $values ) && ( $values['data'] ?? null ) instanceof \WC_Product_Variation ? $values['data'] : $item->get_product();
		$name      = $variation instanceof \WC_Product_Variation ? $this->build_translated_variation_name( $variation, true ) : null;

		if ( $name !== null && $name !== '' ) {
			$item->set_name( $name );
		}
	}

	/**
	 * Hide an order line's attribute rows whose value is already in its name.
	 *
	 * WooCommerce compares the term's own name only; here the name of any of its translations counts.
	 *
	 * @param array<int|string, object>|mixed $formatted_meta Formatted meta rows.
	 * @param \WC_Order_Item|mixed            $item           Order item.
	 * @return array<int|string, object>|mixed
	 */
	public function hide_attribute_meta_in_item_name( $formatted_meta, $item = null ) {
		if ( ! is_array( $formatted_meta ) || $formatted_meta === [] || ! $item instanceof \WC_Order_Item_Product || $item->get_variation_id() <= 0 ) {
			return $formatted_meta;
		}

		$item_name    = (string) $item->get_name();
		$term_manager = null;

		foreach ( $formatted_meta as $id => $meta ) {
			$taxonomy = is_object( $meta ) && isset( $meta->key, $meta->value ) ? str_replace( 'attribute_', '', (string) $meta->key ) : '';

			if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'slug', (string) $meta->value, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$term_manager ??= $this->get_term_manager();
			$translations   = $term_manager->get_translations( (int) $term->term_id );

			// The translated terms in one query, rather than one per language.
			_prime_term_caches( array_values( array_diff( array_map( 'intval', $translations ), [ (int) $term->term_id ] ) ), false );

			foreach ( $translations as $translated_id ) {
				$translated = (int) $translated_id === (int) $term->term_id ? $term : get_term( (int) $translated_id );

				if ( $translated instanceof \WP_Term && $translated->name !== '' && wc_is_attribute_in_product_name( $translated->name, $item_name ) ) {
					unset( $formatted_meta[ $id ] );
					break;
				}
			}
		}

		return $formatted_meta;
	}

	/**
	 * Translate a variation attribute option name to the current language.
	 *
	 * @param mixed $name Option display name.
	 * @param mixed $term WP_Term object or null for custom attributes.
	 * @param mixed $attribute Attribute taxonomy name.
	 * @param mixed $product WC_Product object.
	 * @return string Translated name.
	 */
	public function translate_variation_option_name( mixed $name, mixed $term, mixed $attribute, mixed $product ): string {
		if ( ! $term instanceof \WP_Term ) {
			return (string) ( $name ?? '' );
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return (string) $name;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return (string) $name;
		}

		// Skip on default language - term names are already correct.
		$default = $router->get_default_language();

		if ( $default && $current_slug === $default->slug ) {
			return (string) $name;
		}

		$term_manager  = $this->get_term_manager();
		$translated_id = $term_manager->get_translation_id( $term->term_id, $current_slug );

		if ( $translated_id !== null && $translated_id !== $term->term_id ) {
			$translated_term = get_term( $translated_id );

			if ( $translated_term instanceof \WP_Term ) {
				return $translated_term->name;
			}
		}

		return (string) $name;
	}

	/**
	 * Translate variation attribute values in cart item data.
	 *
	 * @param array<int, array{key: string, value: string}>|mixed $item_data Cart item data.
	 * @param array<string, mixed>                                $cart_item Cart item.
	 * @return array<int, array{key: string, value: string}>|mixed Item data, unchanged when not an array.
	 */
	public function translate_cart_item_data( $item_data, array $cart_item ) {
		// Untyped: WooCommerce accepts a non-array from this filter (it checks is_array()), so pass it
		// through untouched. See docs-ai/04-do-not-fix.md, C1.
		if ( ! is_array( $item_data ) ) {
			return $item_data;
		}

		if ( empty( $cart_item['variation'] ) || ! is_array( $cart_item['variation'] ) ) {
			return $item_data;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $item_data;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return $item_data;
		}

		// Skip on default language - attribute values are already in the default language.
		$default = $router->get_default_language();

		if ( $default && $current_slug === $default->slug ) {
			return $item_data;
		}

		$term_manager = $this->get_term_manager();

		// Map label => [term name => translated name]: two attributes can share a term name ("Red").
		$translation_map = [];

		foreach ( $cart_item['variation'] as $attr_key => $attr_value ) {
			if ( $attr_value === '' ) {
				continue;
			}

			// Extract the taxonomy from the attribute key (e.g., "attribute_pa_color" → "pa_color").
			$taxonomy = str_replace( 'attribute_', '', $attr_key );

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'slug', $attr_value, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$translated_id = $term_manager->get_translation_id( $term->term_id, $current_slug );

			if ( $translated_id !== null && $translated_id !== $term->term_id ) {
				$translated_term = get_term( $translated_id );

				if ( $translated_term instanceof \WP_Term && $translated_term->name !== $term->name ) {
					// Key by the display label, as it appears in $data['key'].
					$label = function_exists( 'wc_attribute_label' )
						? wc_attribute_label( $taxonomy )
						: $taxonomy;

					$translation_map[ $label ][ $term->name ] = $translated_term->name;
				}
			}
		}

		if ( empty( $translation_map ) ) {
			return $item_data;
		}

		// Replace the original term names with translated ones, scoped
		// to the attribute-label the item_data row belongs to.
		foreach ( $item_data as &$data ) {
			if ( ! isset( $data['key'], $data['value'] ) ) {
				continue;
			}

			$label = (string) $data['key'];
			$value = (string) $data['value'];

			if ( isset( $translation_map[ $label ][ $value ] ) ) {
				$data['value'] = $translation_map[ $label ][ $value ];
			}
		}

		unset( $data );

		return $item_data;
	}

	/**
	 * Translate variation attribute values in order item meta display.
	 *
	 * @param string         $html Formatted HTML of item meta.
	 * @param \WC_Order_Item $item Order item.
	 * @param array          $args Display arguments.
	 * @return string Translated HTML.
	 */
	public function translate_order_item_meta( string $html, $item, array $args ): string {
		if ( ! method_exists( $item, 'get_meta_data' ) || $html === '' ) {
			return $html;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $html;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return $html;
		}

		// Skip on default language - meta values are already correct.
		$default = $router->get_default_language();

		if ( $default && $current_slug === $default->slug ) {
			return $html;
		}

		$term_manager = $this->get_term_manager();

		$meta_data = $item->get_meta_data();

		foreach ( $meta_data as $meta ) {
			$data     = $meta->get_data();
			$meta_key = $data['key'] ?? '';
			$value    = $data['value'] ?? '';

			if ( $value === '' ) {
				continue;
			}

			// Attribute meta keys start with "pa_" for taxonomy-based attributes.
			$taxonomy = $meta_key;

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'slug', $value, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				// Value might be the term name, not slug.
				$term = get_term_by( 'name', $value, $taxonomy );
			}

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$translated_id = $term_manager->get_translation_id( $term->term_id, $current_slug );

			if ( $translated_id !== null && $translated_id !== $term->term_id ) {
				$translated_term = get_term( $translated_id );

				if ( $translated_term instanceof \WP_Term && $translated_term->name !== $term->name ) {
					// Replace the escaped original term name with the translated one.
					$escaped_original   = esc_html( $term->name );
					$escaped_translated = esc_html( $translated_term->name );

					// Replace only the first occurrence to avoid unintended matches.
					$pos = strpos( $html, $escaped_original );

					if ( $pos !== false ) {
						$html = substr_replace( $html, $escaped_translated, $pos, strlen( $escaped_original ) );
					}
				}
			}
		}

		return $html;
	}

	/**
	 * Render the WooCommerce settings subtab content (PerfLocale > Settings > Addons).
	 *
	 * Fields POST to perflocale-settings with the perflocale_save_settings nonce and land in
	 * perflocale_settings under wc_* keys; data-perflocale-show-if drives row visibility.
	 *
	 * @param \PerfLocale\Settings $settings The plugin settings service.
	 * @return void
	 */
	public function render_settings_subtab( \PerfLocale\Settings $settings ): void {
		require_once __DIR__ . '/PerfLocaleWooCommerceSettingsView.php';
		\PerfLocaleWooCommerceSettingsView::render( $settings );
	}

	/**
	 * Sanitize the per-language WC currency rows of the settings form POST into `wc_currencies`.
	 *
	 * @return array<string, array{currency_code: string, exchange_rate: float, display: string, position: string}>
	 */
	public static function sanitize_currencies_post(): array {
		// Merge onto the saved map: only ACTIVE languages render inputs, so a replace would drop the rows
		// of deactivated languages (LanguageRepository::delete prunes the key).
		$plugin    = \PerfLocale\Plugin::get_instance();
		$existing  = $plugin->has( 'settings' ) ? (array) $plugin->get( 'settings' )->get( 'wc_currencies', [] ) : [];

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by AdminController.
		if ( empty( $_POST['wc_currencies'] ) || ! is_array( $_POST['wc_currencies'] ) ) {
			return $existing;
		}

		$out = [];

		foreach ( wp_unslash( $_POST['wc_currencies'] ) as $slug => $data ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$slug = sanitize_key( (string) $slug );

			if ( $slug === '' || ! is_array( $data ) ) {
				continue;
			}

			// Validated, never byte-truncated: a broken UTF-8 sequence corrupts the serialized settings
			// option and every plugin setting is lost. ISO 4217 codes are three ASCII letters.
			$raw_code = sanitize_text_field( (string) ( $data['currency_code'] ?? '' ) );
			$code     = preg_match( '/^[A-Za-z]{3}$/', $raw_code ) === 1 ? strtoupper( $raw_code ) : '';

			if ( $code === '' ) {
				continue;
			}

			$display  = sanitize_key( (string) ( $data['display'] ?? 'symbol' ) );
			$position = sanitize_key( (string) ( $data['position'] ?? 'default' ) );

			$out[ $slug ] = [
				'currency_code' => $code,
				// Not a finite number above zero: refused, the stored rate of
				// the row stays (rate_to_store()).
				'exchange_rate' => \PerfLocale\WooCommerce\MultiCurrency::rate_to_store( $data['exchange_rate'] ?? 1.0, $existing[ $slug ] ?? null, $code ),
				// Carry the manual-rate pin, or auto-sync overwrites a user-entered rate.
				'manual_rate'   => ! empty( $data['manual_rate'] ),
				'display'       => in_array( $display, [ 'symbol', 'code' ], true ) ? $display : 'symbol',
				'position'      => in_array( $position, [ 'default', 'left', 'left_space', 'right', 'right_space' ], true ) ? $position : 'default',
			];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array_merge( $existing, $out );
	}

	/**
	 * Map a variable product's taxonomy attribute options onto the target language's sibling terms.
	 *
	 * @param array<int|string, mixed> $attributes  WC_Product_Attribute set.
	 * @param string                   $target_slug Target language slug.
	 * @return array<int|string, mixed>
	 */
	private function translate_attribute_options( array $attributes, string $target_slug ): array {
		if ( $target_slug === '' ) {
			return $attributes;
		}

		$manager = $this->get_term_manager();

		foreach ( $attributes as $attribute ) {
			// Custom attributes carry literal strings, not term IDs: there is no sibling to map.
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->is_taxonomy() ) {
				continue;
			}

			$mapped  = [];
			$changed = false;

			foreach ( $attribute->get_options() as $term_id ) {
				$sibling = $manager->get_translation_id( (int) $term_id, $target_slug );

				if ( $sibling !== null && $sibling > 0 ) {
					$mapped[] = $sibling;
					$changed  = true;
					continue;
				}

				// Untranslated term: keep the source term so the option is still offered.
				$mapped[] = (int) $term_id;
			}

			if ( $changed ) {
				$attribute->set_options( $mapped );
			}
		}

		return $attributes;
	}

	/**
	 * Map a variation's attribute values onto the target language's sibling term slugs.
	 *
	 * So they keep matching the parent's translated options.
	 *
	 * @param array<string, string> $attributes  taxonomy => term slug.
	 * @param string                $target_slug Target language slug.
	 * @return array<string, string>
	 */
	private function translate_variation_attributes( array $attributes, string $target_slug ): array {
		if ( $target_slug === '' ) {
			return $attributes;
		}

		$manager = $this->get_term_manager();

		foreach ( $attributes as $taxonomy => $slug ) {
			// "Any <attribute>" is stored as an empty value - keep it empty.
			if ( ! is_string( $slug ) || $slug === '' || ! taxonomy_exists( (string) $taxonomy ) ) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, (string) $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$sibling_id = $manager->get_translation_id( (int) $term->term_id, $target_slug );

			if ( $sibling_id === null || $sibling_id <= 0 ) {
				continue;
			}

			$sibling = get_term( $sibling_id );

			if ( $sibling instanceof \WP_Term ) {
				$attributes[ $taxonomy ] = $sibling->slug;
			}
		}

		return $attributes;
	}

	/**
	 * Add the current language to a WooCommerce block query's cache key.
	 *
	 * A var nothing reads: perflocale_language_id drives modify_query_clauses() and would scope
	 * exempt queries.
	 *
	 * @param \WP_Query $query Query being parsed.
	 * @return void
	 */
	public function separate_block_query_cache_by_language( $query ): void {
		if ( ! $query instanceof \Automattic\WooCommerce\Blocks\Utils\BlocksWpQuery ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return;
		}

		$language = $plugin->get( 'router' )->get_current_language();

		if ( ! is_object( $language ) || ( $language->slug ?? '' ) === '' ) {
			return;
		}

		$query->set( 'perflocale_cache_language', (string) $language->slug );
	}

	/**
	 * Language-scope a page request after WooCommerce turns it into a shop.
	 *
	 * Only an unstamped MAIN query WC rewrote to a product archive: the shop as front page, or a
	 * (translated) shop page on a site with a static front page.
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public function rescope_front_page_shop( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || is_admin() ) {
			return;
		}

		// Already decided by someone - never override an existing stamp.
		if ( (int) $query->get( 'perflocale_language_id' ) > 0 ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = is_string( $post_type ) ? [ $post_type ] : (array) $post_type;

		if ( ! in_array( 'product', $post_type, true ) ) {
			return;
		}

		// Narrow to the configurations that produce the gap. Any other product
		// archive was already scoped normally at priority 5.
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}

		// The shop page standing in as the front page.
		$front     = (int) get_option( 'page_on_front' );
		$shop_home = $front > 0 && $front === (int) wc_get_page_id( 'shop' );

		// With a static front page WC rewrites a shop page request too (page and product archive,
		// page_id emptied): a translated shop page such as /fr/boutique/ lands here.
		$shop_page = $query->is_page && $query->is_post_type_archive && '' === (string) $query->get( 'page_id' );

		if ( ! $shop_home && ! $shop_page ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return;
		}

		$language = $plugin->get( 'router' )->get_current_language();

		if ( ! is_object( $language ) || (int) ( $language->id ?? 0 ) <= 0 ) {
			return;
		}

		$query->set( 'perflocale_language_id', (int) $language->id );
	}

	/**
	 * Give a freshly-created product translation the SOURCE's product_type.
	 *
	 * The product_type taxonomy is internal and no copy carries it; without it wc_get_product() loads
	 * a WC_Product_Simple. Priority 15: before the variation clone (20) loads the product.
	 * Also mirrors visibility, shipping class and brand through mirror_catalog_terms().
	 *
	 * @param int    $new_id      Newly created translation post ID.
	 * @param string $object_type Object type ('post' for post translations).
	 * @param string $target_slug Target language slug (unused: product_type is never translated).
	 * @param int    $source_id   Source post ID.
	 * @return void
	 */
	public function mirror_product_type( $new_id, $object_type, $target_slug, $source_id ): void {
		if ( 'post' !== (string) $object_type ) {
			return;
		}

		$new_id    = (int) $new_id;
		$source_id = (int) $source_id;

		if ( $new_id <= 0 || $source_id <= 0 || 'product' !== get_post_type( $source_id ) ) {
			return;
		}

		$types = wp_get_object_terms( $source_id, 'product_type', [ 'fields' => 'slugs' ] );

		// No product_type term means simple, for the source and the translation alike.
		if ( ! is_wp_error( $types ) && $types !== [] ) {
			// Not translated: these slugs are machinery, and translated ones resolve no product class.
			wp_set_object_terms( $new_id, array_map( 'strval', $types ), 'product_type' );
			$this->created_product_types[ get_current_blog_id() . ':' . $new_id ] = (string) reset( $types );
		}

		$this->mirror_catalog_terms( $new_id, $source_id );

		// WC caches a product's type on first load; a published translation was loaded before it had
		// the term, so drop the cached 'simple'.
		if ( class_exists( \WC_Cache_Helper::class ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'product_' . $new_id );
		}
	}

	/**
	 * Give a freshly-created product translation the source's visibility, shipping class and brand terms.
	 *
	 * WC reads these props from non-translatable taxonomies alone. Operator flags are copied; outofstock
	 * and rated-N come from the translation's own meta, as update_visibility() derives them. Written
	 * directly: a CRUD save() would fire every save listener mid-create.
	 *
	 * @param int $new_id    Newly created translation post ID.
	 * @param int $source_id Source post ID.
	 * @return void
	 */
	private function mirror_catalog_terms( int $new_id, int $source_id ): void {
		$plugin       = \PerfLocale\Plugin::get_instance();
		$translatable = $plugin->has( 'settings' ) ? (array) $plugin->get( 'settings' )->get_translatable_taxonomies() : [];

		if ( taxonomy_exists( 'product_visibility' ) && ! in_array( 'product_visibility', $translatable, true ) ) {
			$flags = wp_get_object_terms( $source_id, 'product_visibility', [ 'fields' => 'slugs' ] );

			if ( ! is_wp_error( $flags ) ) {
				$terms = array_values( array_intersect( [ 'featured', 'exclude-from-search', 'exclude-from-catalog' ], array_map( 'strval', $flags ) ) );

				if ( 'outofstock' === (string) get_post_meta( $new_id, '_stock_status', true ) ) {
					$terms[] = 'outofstock';
				}

				$rating = min( 5, (int) round( (float) get_post_meta( $new_id, '_wc_average_rating', true ) ) );

				if ( $rating > 0 ) {
					$terms[] = 'rated-' . $rating;
				}

				wp_set_object_terms( $new_id, $terms, 'product_visibility' );
			}
		}

		foreach ( [ 'product_shipping_class', 'product_brand' ] as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) || in_array( $taxonomy, $translatable, true ) ) {
				continue;
			}

			$term_ids = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_wp_error( $term_ids ) ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $term_ids ), $taxonomy );
			}
		}
	}

	/**
	 * Clone a variable product's variations onto its freshly-created translation.
	 *
	 * SKUs are copied verbatim (siblings share inventory), so unique-SKU validation is suspended for
	 * the clone; attribute options and values map to the target language's terms. Idempotent.
	 *
	 * @param int    $new_id      Newly created translation post ID.
	 * @param string $object_type Object type ('post' for post translations).
	 * @param string $target_slug Target language slug.
	 * @param int    $source_id   Source post ID.
	 * @return void
	 */
	public function clone_product_variations( $new_id, $object_type, $target_slug, $source_id ): void {
		if ( 'post' !== (string) $object_type || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		if ( 'product' !== get_post_type( (int) $source_id ) ) {
			return;
		}

		try {
			$source = wc_get_product( (int) $source_id );

			if ( ! $source instanceof \WC_Product || ! $source->is_type( 'variable' ) ) {
				return;
			}

			// Set the type to variable BEFORE loading the target, so WC returns the right class.
			wp_set_object_terms( (int) $new_id, 'variable', 'product_type' );

			$target = wc_get_product( (int) $new_id );

			if ( ! $target instanceof \WC_Product_Variable ) {
				return;
			}

			// Idempotency: never duplicate existing children.
			if ( [] !== $target->get_children() ) {
				return;
			}

			// Taxonomy attribute options must name the TARGET language's terms: the dropdown offers only terms
			// in both the options and the product's (sibling) terms, so source options leave it empty.
			$target->set_attributes( $this->translate_attribute_options( $source->get_attributes(), (string) $target_slug ) );
			$target->save();

			// Translation siblings share SKUs by design; suspend WC's
			// unique-SKU validation for the duration of the clone only.
			add_filter( 'wc_product_has_unique_sku', '__return_false', 999 );

			try {
				foreach ( $source->get_children() as $variation_id ) {
					$sv = wc_get_product( (int) $variation_id );

					if ( ! $sv instanceof \WC_Product_Variation ) {
						continue;
					}

					$nv = new \WC_Product_Variation();
					$nv->set_parent_id( (int) $new_id );
					// Values are term slugs and move with the parent's options: find_matching_product_variation()
					// matches the posted attribute_pa_* value against this meta.
					$nv->set_attributes( $this->translate_variation_attributes( $sv->get_attributes(), (string) $target_slug ) );
					$nv->set_status( $sv->get_status() );
					$nv->set_regular_price( (string) $sv->get_regular_price( 'edit' ) );
					$nv->set_sale_price( (string) $sv->get_sale_price( 'edit' ) );
					// Copy the sale window: without _sale_price_dates_to the sale never ends (or a future one starts today).
					$nv->set_date_on_sale_from( $sv->get_date_on_sale_from( 'edit' ) );
					$nv->set_date_on_sale_to( $sv->get_date_on_sale_to( 'edit' ) );
					$nv->set_manage_stock( (bool) $sv->get_manage_stock( 'edit' ) );
					$nv->set_backorders( (string) $sv->get_backorders( 'edit' ) );
					$nv->set_low_stock_amount( $sv->get_low_stock_amount( 'edit' ) );

					if ( $sv->get_manage_stock( 'edit' ) ) {
						$nv->set_stock_quantity( $sv->get_stock_quantity( 'edit' ) );
					}

					$nv->set_stock_status( (string) $sv->get_stock_status( 'edit' ) );
					$nv->set_virtual( (bool) $sv->get_virtual( 'edit' ) );
					$nv->set_downloadable( (bool) $sv->get_downloadable( 'edit' ) );

					if ( $sv->get_downloadable( 'edit' ) ) {
						// Without the files, the downloadable flag grants no download permission at purchase.
						$nv->set_downloads( $sv->get_downloads() );
						$nv->set_download_limit( $sv->get_download_limit( 'edit' ) );
						$nv->set_download_expiry( $sv->get_download_expiry( 'edit' ) );
					}

					$nv->set_weight( (string) $sv->get_weight( 'edit' ) );
					$nv->set_length( (string) $sv->get_length( 'edit' ) );
					$nv->set_width( (string) $sv->get_width( 'edit' ) );
					$nv->set_height( (string) $sv->get_height( 'edit' ) );
					$nv->set_tax_class( (string) $sv->get_tax_class( 'edit' ) );
					// Per-variation shipping class (e.g. "bulky") - without it the
					// clone falls back to the parent/none and is charged wrong.
					$nv->set_shipping_class_id( (int) $sv->get_shipping_class_id( 'edit' ) );
					$nv->set_menu_order( (int) $sv->get_menu_order( 'edit' ) );
					$nv->set_image_id( (int) $sv->get_image_id( 'edit' ) );
					// Description seeds the translation; the translator owns it after.
					$nv->set_description( (string) $sv->get_description( 'edit' ) );

					$sku = (string) $sv->get_sku( 'edit' );

					if ( '' !== $sku ) {
						$nv->set_sku( $sku );
					}

					$nv->save();
					require_once __DIR__ . '/PerfLocaleWooCommerceVariationText.php';
					\PerfLocaleWooCommerceVariationText::keep_backslashes( $nv );
				}
			} finally {
				remove_filter( 'wc_product_has_unique_sku', '__return_false', 999 );
			}

			// Rebuild the parent's price range / stock rollups + lookup row.
			\WC_Product_Variable::sync( (int) $new_id );

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $new_id );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PerfLocale WC: variation clone failed for translation ' . (int) $new_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Build the wc_product_meta_lookup row of a freshly-created, non-variable product translation.
	 *
	 * Translations skip WC's CRUD create, so they get no row (wrong price sort, no SKU search). Built
	 * from meta by the data store's update_lookup_table(); never a CRUD save (save hooks) or
	 * wc_update_product_stock() (its sibling mirror could overwrite a concurrent sale).
	 *
	 * @param int    $new_id      Newly created translation post ID.
	 * @param string $object_type Object type ('post' for post translations).
	 * @return void
	 */
	public function refresh_copy_lookup_row( $new_id, $object_type ): void {
		if ( 'post' !== (string) $object_type || ! function_exists( 'WC' ) || ! class_exists( \WC_Data_Store::class ) || ! class_exists( \WC_Product_Factory::class ) ) {
			return;
		}

		$new_id = (int) $new_id;
		$key    = get_current_blog_id() . ':' . $new_id;
		$type   = $this->created_product_types[ $key ] ?? null;

		unset( $this->created_product_types[ $key ] );

		if ( $new_id <= 0 || 'product' !== get_post_type( $new_id ) ) {
			return;
		}

		try {
			$type = $type ?? \WC_Product_Factory::get_product_type( $new_id );

			if ( ! is_string( $type ) || $type === '' || 'variable' === $type ) {
				return;
			}

			$store = \WC_Data_Store::load( 'product-' . sanitize_key( $type ) );

			if ( version_compare( (string) WC()->version, '10.8.0', '>=' ) ) {
				$store->refresh_product_lookup_table( $new_id );
			} else {
				$this->write_lookup_row( $store, $new_id );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PerfLocale WC: lookup row rebuild failed for translation ' . $new_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Write a product's wc_product_meta_lookup row through its data store on WooCommerce below 10.8.
	 *
	 * Calls the protected update_lookup_table() (what 10.8's refresh_product_lookup_table() wraps)
	 * through closures scoped to the declaring classes; no action fires and no meta is written.
	 *
	 * @param \WC_Data_Store $store      Wrapper from WC_Data_Store::load().
	 * @param int            $product_id Product ID.
	 * @return void
	 * @throws \RuntimeException When the wrapper has no store instance or the
	 *                           store is not a WC_Data_Store_WP.
	 */
	private function write_lookup_row( \WC_Data_Store $store, int $product_id ): void {
		if ( ! property_exists( \WC_Data_Store::class, 'instance' ) || ! class_exists( \WC_Data_Store_WP::class ) ) {
			throw new \RuntimeException( 'WooCommerce has no data store instance' );
		}

		$read = \Closure::bind(
			static function ( \WC_Data_Store $wrapper ): mixed {
				return $wrapper->instance;
			},
			null,
			\WC_Data_Store::class
		);
		/**
		 * The store object the wrapper resolved. WooCommerce documents the
		 * property as a WC_Data_Store.
		 *
		 * @var object|null $instance
		 */
		$instance = $read( $store );

		if ( ! $instance instanceof \WC_Data_Store_WP ) {
			throw new \RuntimeException( 'the product data store is not a WC_Data_Store_WP' );
		}

		$write = \Closure::bind(
			static function ( \WC_Data_Store_WP $data_store, int $id ): void {
				$data_store->update_lookup_table( $id, 'wc_product_meta_lookup' );
			},
			null,
			\WC_Data_Store_WP::class
		);
		$write( $instance, $product_id );
	}

	/**
	 * Machine-translate a translated product's variation descriptions and local attribute options.
	 *
	 * Only values still holding the source's copy, never Sync Fields or emptied keys. Translated options
	 * are mirrored to the variation values with a translated => original map, so stock sync keeps
	 * pairing variations. Failures never fail the post translation.
	 *
	 * @param int|mixed      $source_id   Source post ID.
	 * @param string         $provider_id Provider that translated the post.
	 * @param array|mixed    $result      translate_post() result (post_id = the translation).
	 * @param string         $target_slug Target language slug.
	 * @param string         $source_slug Source language slug.
	 * @param \WP_Post|mixed $source_post Source post as translate_post() read it.
	 *                                    Its post type is used when its ID is $source_id.
	 * @return void
	 */
	public function translate_variation_texts( $source_id = 0, $provider_id = '', $result = [], $target_slug = '', $source_slug = '', $source_post = null ): void {
		$source_id = (int) $source_id;
		$target_id = is_array( $result ) ? (int) ( $result['post_id'] ?? 0 ) : 0;

		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id || '' === (string) $target_slug || '' === (string) $source_slug ) {
			return;
		}

		// Type from the passed source post: by ID it can cost a query, as the sibling sync of the
		// translation's save drops the source from the post cache.
		$post_type = ( $source_post instanceof \WP_Post && (int) $source_post->ID === $source_id )
			? (string) $source_post->post_type
			: get_post_type( $source_id );

		if ( 'product' !== $post_type || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$source = wc_get_product( $source_id );
		$target = wc_get_product( $target_id );

		if ( ! $source instanceof \WC_Product || ! $target instanceof \WC_Product ) {
			return;
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->get( 'settings' );
		$errors   = [];

		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror_variation = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', (array) $settings->get( 'sync_fields', [] ), 'product_variation' );
		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror_product = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', (array) $settings->get( 'sync_fields', [] ), 'product' );

		$pairs        = $source instanceof \WC_Product_Variable && $target instanceof \WC_Product_Variable
			? $this->variation_pairs( $source, $target, (string) $target_slug )
			: [];
		$descriptions = in_array( '_variation_description', array_map( 'strval', $mirror_variation ), true )
			? []
			: $this->seeded_variation_descriptions( $pairs );
		$options      = in_array( '_product_attributes', array_map( 'strval', $mirror_product ), true ) || isset( \PerfLocale\Translation\ContentSync::seed_cleared_keys( $target_id )['_product_attributes'] )
			? []
			: $this->seeded_local_options( $source, $target );

		if ( [] === $descriptions && [] === $options ) {
			return;
		}

		try {
			$service = new \PerfLocale\MachineTranslation\TranslationService( $settings, $plugin->get( 'cache' ) );
		} catch ( \Throwable $e ) {
			return;
		}

		// One check for both batches, so a limit that covers only one of them
		// sends neither.
		$needed = 0;

		foreach ( $descriptions as $text ) {
			$needed += mb_strlen( $text );
		}

		foreach ( $options as $list ) {
			foreach ( $list as $option ) {
				$needed += mb_strlen( $option );
			}
		}

		if ( $service->would_exceed_limit( $needed ) ) {
			$descriptions = [];
			$options      = [];
			$errors[]     = sprintf(
				/* translators: %1$s: Characters required, %2$s: Monthly limit */
				__( 'Monthly character limit would be exceeded (~%1$s characters required, limit %2$s). Translation blocked to prevent overage charges.', 'perflocale' ),
				number_format_i18n( $needed ),
				number_format_i18n( (int) $settings->get( 'mt_monthly_char_limit', 500000 ) )
			);
		}

		if ( [] !== $descriptions ) {
			$errors = array_merge( $errors, $this->write_variation_descriptions( $service, $descriptions, (string) $source_slug, (string) $target_slug, (string) $provider_id ) );
		}

		if ( [] !== $options ) {
			$errors = array_merge( $errors, $this->write_local_options( $service, $source_id, $target_id, $options, (string) $source_slug, (string) $target_slug, (string) $provider_id ) );
		}

		if ( [] !== $errors ) {
			$message = sprintf(
				/* translators: %s: the reasons, separated by " | ". */
				__( 'Variation descriptions or attribute options were not translated: %s', 'perflocale' ),
				implode( ' | ', array_unique( $errors ) )
			);

			\PerfLocale\MachineTranslation\MetaTranslator::record_meta_errors( $target_id, [ $message ] );

			/** This action is documented in src/MachineTranslation/MetaTranslator.php */
			do_action( 'perflocale/mt/meta_translate_failed', $source_id, $target_id, [ '_variation_description', '_product_attributes' ], $message );
		}
	}

	/**
	 * Pair each source variation with the translation's variation of the same attributes.
	 *
	 * Taxonomy values map to target terms; local values read through the translated => original map.
	 *
	 * @param \WC_Product_Variable $source      Source product.
	 * @param \WC_Product_Variable $target      Translated product.
	 * @param string               $target_slug Target language slug.
	 * @return array<int, int> source variation id => target variation id.
	 */
	private function variation_pairs( \WC_Product_Variable $source, \WC_Product_Variable $target, string $target_slug ): array {
		$source_children = array_map( 'intval', $source->get_children() );
		$target_children = array_map( 'intval', $target->get_children() );

		if ( [] === $source_children || [] === $target_children ) {
			return [];
		}

		_prime_post_caches( array_merge( $source_children, $target_children ), false, true );

		$local = get_post_meta( $target->get_id(), \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, true );
		$local = is_array( $local ) ? $local : [];

		$by_signature = [];

		foreach ( $target_children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child instanceof \WC_Product_Variation ) {
				continue;
			}

			$attrs = [];

			foreach ( $child->get_attributes() as $key => $value ) {
				$key   = (string) $key;
				$value = (string) $value;

				if ( isset( $local[ $key ][ $value ] ) && is_string( $local[ $key ][ $value ] ) ) {
					$value = $local[ $key ][ $value ];
				}

				$attrs[ $key ] = $value;
			}

			ksort( $attrs );
			$sig = (string) wp_json_encode( $attrs );

			if ( ! isset( $by_signature[ $sig ] ) ) {
				$by_signature[ $sig ] = $child_id;
			}
		}

		$pairs = [];

		foreach ( $source_children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child instanceof \WC_Product_Variation ) {
				continue;
			}

			$attrs = array_map( 'strval', $this->translate_variation_attributes( $child->get_attributes(), $target_slug ) );
			ksort( $attrs );
			$sig = (string) wp_json_encode( $attrs );

			if ( isset( $by_signature[ $sig ] ) ) {
				$pairs[ $child_id ] = $by_signature[ $sig ];
			}
		}

		return $pairs;
	}

	/**
	 * Paired variations whose translation still holds the source's description, not emptied there.
	 *
	 * @param array<int, int> $pairs source variation id => target variation id.
	 * @return array<int, string> target variation id => source description.
	 */
	private function seeded_variation_descriptions( array $pairs ): array {
		$out = [];

		foreach ( $pairs as $source_vid => $target_vid ) {
			$sv = wc_get_product( $source_vid );
			$tv = wc_get_product( $target_vid );

			if ( ! $sv instanceof \WC_Product_Variation || ! $tv instanceof \WC_Product_Variation ) {
				continue;
			}

			$text = (string) $sv->get_description( 'edit' );

			if ( '' === trim( $text ) || (string) $tv->get_description( 'edit' ) !== $text ) {
				continue;
			}

			if ( isset( \PerfLocale\Translation\ContentSync::seed_cleared_keys( $target_vid )['_variation_description'] ) ) {
				continue;
			}

			$out[ $target_vid ] = $text;
		}

		return $out;
	}

	/**
	 * Local attributes whose options the translation still holds as the source's copy.
	 *
	 * @param \WC_Product $source Source product.
	 * @param \WC_Product $target Translated product.
	 * @return array<string, array<int, string>> attribute key => source options.
	 */
	private function seeded_local_options( \WC_Product $source, \WC_Product $target ): array {
		$target_attrs = [];

		foreach ( $target->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() ) {
				$target_attrs[ sanitize_title( $attribute->get_name() ) ] = array_values( array_map( 'strval', $attribute->get_options() ) );
			}
		}

		$out = [];

		foreach ( $source->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->is_taxonomy() ) {
				continue;
			}

			$key     = sanitize_title( $attribute->get_name() );
			$options = array_values( array_map( 'strval', $attribute->get_options() ) );

			if ( '' === $key || [] === $options || ( $target_attrs[ $key ] ?? null ) !== $options ) {
				continue;
			}

			$out[ $key ] = $options;
		}

		return $out;
	}

	/**
	 * Translate and write variation descriptions (HTML, placeholders masked).
	 *
	 * @param \PerfLocale\MachineTranslation\TranslationService $service      MT service.
	 * @param array<int, string>                                $descriptions target variation id => source description.
	 * @param string                                            $source_slug  Source language slug.
	 * @param string                                            $target_slug  Target language slug.
	 * @param string                                            $provider_id  Provider id.
	 * @return array<int, string> Error reasons.
	 */
	private function write_variation_descriptions( \PerfLocale\MachineTranslation\TranslationService $service, array $descriptions, string $source_slug, string $target_slug, string $provider_id ): array {
		$masked = [];

		foreach ( $descriptions as $vid => $text ) {
			$masked[ $vid ] = \PerfLocale\Translation\PlaceholderMasker::mask( $text );
		}

		try {
			$out = $service->translate_batch_texts( array_values( array_map( static fn( array $m ): string => (string) $m[0], $masked ) ), $source_slug, $target_slug, $provider_id, false, 'html' );
		} catch ( \Throwable $e ) {
			return [ $e->getMessage() ];
		}

		$errors = [];
		$i      = 0;

		foreach ( $masked as $vid => $m ) {
			$translated = (string) ( $out[ $i ] ?? '' );
			++$i;

			if ( '' === trim( $translated ) ) {
				$errors[] = 'empty translation';
				continue;
			}

			$restored = \PerfLocale\Translation\PlaceholderMasker::restore( $translated, $m[1] );

			if ( ! \PerfLocale\Translation\PlaceholderMasker::preserves_placeholders( $descriptions[ $vid ], $restored ) ) {
				$errors[] = 'placeholder lost';
				continue;
			}

			// A description in block markup keeps its block structure, as
			// post content does; the variation keeps its description otherwise.
			$structure = \PerfLocale\MachineTranslation\BlockStructureGuard::mismatch( $descriptions[ $vid ], $restored );

			if ( '' !== $structure ) {
				$errors[] = $structure;
				continue;
			}

			// The provider wait can be long: a description someone changed
			// meanwhile is theirs.
			clean_post_cache( $vid );
			$variation = wc_get_product( $vid );

			if ( ! $variation instanceof \WC_Product_Variation || (string) $variation->get_description( 'edit' ) !== $descriptions[ $vid ] ) {
				continue;
			}

			$variation->set_description( $restored );
			$variation->save();
			require_once __DIR__ . '/PerfLocaleWooCommerceVariationText.php';
			\PerfLocaleWooCommerceVariationText::keep_backslashes( $variation );
		}

		return $errors;
	}

	/**
	 * Translate a product's local attribute options; write them to the parent and its variations' values.
	 *
	 * @param \PerfLocale\MachineTranslation\TranslationService $service     MT service.
	 * @param int                                               $source_id   Source product id.
	 * @param int                                               $target_id   Translated product id.
	 * @param array<string, array<int, string>>                 $options     attribute key => source options.
	 * @param string                                            $source_slug Source language slug.
	 * @param string                                            $target_slug Target language slug.
	 * @param string                                            $provider_id Provider id.
	 * @return array<int, string> Error reasons.
	 */
	private function write_local_options( \PerfLocale\MachineTranslation\TranslationService $service, int $source_id, int $target_id, array $options, string $source_slug, string $target_slug, string $provider_id ): array {
		$target = wc_get_product( $target_id );

		if ( ! $target instanceof \WC_Product ) {
			return [];
		}

		$children = $target instanceof \WC_Product_Variable ? array_map( 'intval', $target->get_children() ) : [];
		$values   = [];

		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( $child instanceof \WC_Product_Variation ) {
				$values[ $child_id ] = array_map( 'strval', $child->get_attributes() );
			}
		}

		// An attribute whose variations hold a value that is not one of its
		// options (an older slug form, a hand edit) keeps its options: moving
		// the options without those values would unpair the variations.
		foreach ( $options as $key => $list ) {
			foreach ( $values as $attrs ) {
				$value = $attrs[ $key ] ?? '';

				if ( '' !== $value && ! in_array( $value, $list, true ) ) {
					unset( $options[ $key ] );
					break;
				}
			}
		}

		if ( [] === $options ) {
			return [];
		}

		$flat = [];

		foreach ( $options as $list ) {
			foreach ( $list as $option ) {
				$flat[] = $option;
			}
		}

		try {
			$out = $service->translate_batch_texts( $flat, $source_slug, $target_slug, $provider_id, false, 'text' );
		} catch ( \Throwable $e ) {
			return [ $e->getMessage() ];
		}

		// The original of each option, through the source's own map when the
		// source is itself a translated copy, so every copy maps to one value.
		$source_map = get_post_meta( $source_id, \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, true );
		$source_map = is_array( $source_map ) ? $source_map : [];
		$maps       = [];
		$renames    = [];
		$errors     = [];
		$i          = 0;

		foreach ( $options as $key => $list ) {
			$translated = [];
			$seen       = [];
			$usable     = true;

			foreach ( $list as $option ) {
				// WooCommerce joins local options with "|".
				$t = trim( str_replace( '|', '/', sanitize_text_field( (string) ( $out[ $i ] ?? '' ) ) ) );
				++$i;
				$fold = wc_strtolower( $t );

				if ( '' === $t || isset( $seen[ $fold ] ) ) {
					$usable = false;
					continue;
				}

				$seen[ $fold ]         = true;
				$translated[ $option ] = $t;
			}

			if ( ! $usable ) {
				$errors[] = sprintf(
					/* translators: %s: product attribute name. */
					__( 'attribute "%s" kept its options (a translated option was empty or repeated)', 'perflocale' ),
					$key
				);
				continue;
			}

			$renames[ $key ] = $translated;

			foreach ( $translated as $option => $t ) {
				$original           = $source_map[ $key ][ $option ] ?? $option;
				$maps[ $key ][ $t ] = is_string( $original ) ? $original : $option;
			}
		}

		if ( [] === $renames ) {
			return $errors;
		}

		// The map first, so a sync fired by the saves below already pairs
		// the renamed variations.
		$stored = get_post_meta( $target_id, \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, true );
		$stored = is_array( $stored ) ? $stored : [];
		update_post_meta( $target_id, \PerfLocale\WooCommerce\InventorySync::LOCAL_ATTR_SOURCE_META, wp_slash( array_replace( $stored, $maps ) ) );

		// New attribute objects: WooCommerce records a change only when the
		// array it is given differs from the one it holds, and the objects
		// get_attributes() returns are the held ones.
		$attributes = [];

		foreach ( $target->get_attributes() as $name => $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && ! $attribute->is_taxonomy() && isset( $renames[ sanitize_title( $attribute->get_name() ) ] ) ) {
				$attribute = clone $attribute;
				$attribute->set_options( array_values( $renames[ sanitize_title( $attribute->get_name() ) ] ) );
			}

			$attributes[ $name ] = $attribute;
		}

		$target->set_attributes( $attributes );
		$target->save();

		foreach ( $values as $child_id => $attrs ) {
			$changed = false;

			foreach ( $renames as $key => $translated ) {
				$value = $attrs[ $key ] ?? '';

				if ( '' !== $value && isset( $translated[ $value ] ) ) {
					$attrs[ $key ] = $translated[ $value ];
					$changed       = true;
				}
			}

			if ( ! $changed ) {
				continue;
			}

			$child = wc_get_product( $child_id );

			if ( $child instanceof \WC_Product_Variation ) {
				$child->set_attributes( $attrs );
				$child->save();
			}
		}

		if ( $target instanceof \WC_Product_Variable ) {
			\WC_Product_Variable::sync( $target_id );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $target_id );
		}

		return $errors;
	}
}
