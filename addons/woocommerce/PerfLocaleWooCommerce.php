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
 * WooCommerce integration for PerfLocale.
 *
 * Translates products, variations, categories, and attributes. Sends order
 * emails in the customer's language. Optionally displays prices in a
 * per-language currency. Syncs stock, SKU, and pricing across language
 * variants so the same physical product is never over- or under-sold.
 */
final class PerfLocaleWooCommerce implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Lazily instantiated term translation manager.
	 *
	 * @var \PerfLocale\Translation\TermTranslationManager|null
	 */
	private ?\PerfLocale\Translation\TermTranslationManager $term_manager = null;

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

		// Register product and variation post types as translatable.
		// shop_order is intentionally EXCLUDED: orders are language-tagged via
		// _perflocale_language meta but must not be duplicated per language.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// Register product taxonomies as translatable.
		// Product attributes (pa_*) are discovered dynamically after WC registers them.
		add_filter( 'perflocale/translatable_taxonomies', [ $this, 'add_taxonomies' ], 20 );

		// Register product meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );

		// ---- URL slug translation ----

		// Translate cart item permalink to the current language.
		add_filter( 'woocommerce_cart_item_permalink', [ $this, 'translate_cart_item_permalink' ], 10, 3 );

		// Translate WooCommerce built-in page URLs to include the language prefix.
		//
		// Two different WC filter families are in play and both are needed.
		// wc_get_cart_url()/wc_get_checkout_url() fire `woocommerce_get_<page>_url`
		// (wc-core-functions.php), while wc_get_page_permalink( $page ) — the
		// function everything else goes through — fires the DYNAMIC
		// `woocommerce_get_<page>_page_permalink` (wc-page-functions.php).
		// Hooking only the wrapper leaves every direct wc_get_page_permalink()
		// caller unprefixed, so cart/checkout carry both forms. `shop` has no
		// wrapper at all: `woocommerce_get_shop_url` does not exist anywhere in
		// WooCommerce, so the shop URL was never prefixed.
		add_filter( 'woocommerce_get_cart_url', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_checkout_url', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_cart_page_permalink', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_checkout_page_permalink', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_myaccount_page_permalink', [ $this, 'translate_wc_url' ] );
		add_filter( 'woocommerce_get_shop_page_permalink', [ $this, 'translate_wc_url' ] );

		// The Mini-Cart footer buttons bypass wc_get_page_permalink() entirely
		// — MiniCartCartButtonBlock / MiniCartCheckoutButtonBlock build their
		// href as get_permalink( wc_get_page_id( … ) ) — so none of the filters
		// above fire for them and a shopper browsing /de/ is handed an
		// unprefixed /cart-3/ link that drops them back into the default
		// language mid-funnel. Correct those at the page_link layer instead.
		// Frontend only: admin screens and REST consumers expect the raw
		// permalink, and REST is not is_admin() so it needs its own gate.
		// Sitemaps are excluded for the opposite reason to the one above: a
		// <loc> is not navigation, it is the page's own identity URL, and an
		// untranslated page belongs in the sitemap under its own language
		// (what SitemapIntegration::pin_entry_loc_to_default enforces for the
		// core tree, and what every other untranslated page already does in a
		// third-party tree). Without this gate a /de/ sitemap advertised
		// /de/cart-3/ while that page's own canonical and x-default point at
		// /cart-3/ — an "alternate page with proper canonical" signal on a
		// surface this filter was never meant to reach.
		if ( ! is_admin() && ! \PerfLocale\Helper::is_rest_request() && ! $this->is_sitemap_request() ) {
			// Priority 20 runs after UrlConverter::filter_page_link() (10), so
			// we only correct the links it deliberately leaves in the page's
			// own language.
			add_filter( 'page_link', [ $this, 'force_wc_page_language_prefix' ], 20, 2 );
		}

		// ---- Cross-sells / upsells across translation siblings ----

		// _crosssell_ids/_upsell_ids are copied verbatim to translations, so
		// /de/ carts and product pages rendered DEFAULT-language products
		// (wc_get_product maps raw IDs with no language query). Swap each ID
		// for its current-language sibling at read time; untranslated
		// entries pass through unchanged.
		add_filter( 'woocommerce_product_get_cross_sell_ids', [ $this, 'map_related_ids_to_language' ] );
		add_filter( 'woocommerce_product_get_upsell_ids', [ $this, 'map_related_ids_to_language' ] );
		add_filter( 'woocommerce_cart_crosssell_ids', [ $this, 'map_related_ids_to_language' ] );

		// ---- Coupon restrictions across translation siblings ----

		// A coupon restricted to product/category X must also apply to X's
		// translation siblings: the SAME physical product carries a
		// different post/term ID per language, so WC's raw-ID comparison
		// rejected valid coupons on translated carts (and exclusion lists
		// leaked through sibling IDs). Expand the lists at READ time on the
		// frontend only — the stored coupon and the admin edit screen keep
		// the raw saved IDs.
		add_filter( 'woocommerce_coupon_get_product_ids', [ $this, 'expand_coupon_product_ids' ] );
		add_filter( 'woocommerce_coupon_get_excluded_product_ids', [ $this, 'expand_coupon_product_ids' ] );
		add_filter( 'woocommerce_coupon_get_product_categories', [ $this, 'expand_coupon_term_ids' ] );
		add_filter( 'woocommerce_coupon_get_excluded_product_categories', [ $this, 'expand_coupon_term_ids' ] );

		// Translate account endpoint URLs: /my-account/orders/, /my-account/downloads/, etc.
		add_filter( 'woocommerce_get_endpoint_url', [ $this, 'translate_endpoint_url' ], 10, 4 );

		// ---- WC page ID translation ----

		// Filter WC page options to return the translated page ID for the
		// current language. Without this, is_checkout(), is_cart(), etc.
		// return false on non-default language pages because WC compares
		// the current page ID against the default-language page ID.
		$wc_pages = [ 'cart', 'checkout', 'myaccount', 'shop', 'terms' ];
		foreach ( $wc_pages as $page ) {
			// Priority 15 runs after WooCommerce's own option filters
			// (they land at the default 10) so our translated ID is the
			// last one seen by callers - avoids undefined ordering when
			// both WC and this addon manipulate the same option.
			add_filter( "option_woocommerce_{$page}_page_id", [ $this, 'filter_wc_page_id' ], 15 );
		}

		// ---- Optional services ----

		// Email translation - send order emails in the customer's stored language.
		if ( (bool) $settings->get( 'wc_email_translation', true ) ) {
			$email = new \PerfLocale\WooCommerce\EmailTranslation();
			$email->register_hooks();
		}

		// Inventory sync - keep stock, SKU, price, weight in sync across variants.
		if ( (bool) $settings->get( 'wc_sync_stock', true ) ) {
			$sync = new \PerfLocale\WooCommerce\InventorySync( $plugin->get( 'cache' ) );
			$sync->register_hooks();

			// Per-product opt-out checkbox (product data → Advanced): lets a
			// merchant give ONE language's product independent prices/stock
			// (e.g. a DE-only promotion) while the rest of the group keeps
			// syncing — no code required. Only wired in admin when the sync
			// itself is active; the meta flag is read by InventorySync.
			if ( is_admin() ) {
				add_action( 'woocommerce_product_options_advanced', [ $this, 'render_sync_optout_field' ] );
				// Priority 5: before InventorySync's sync at 100, so the very
				// save that ticks the box already respects it.
				add_action( 'woocommerce_process_product_meta', [ $this, 'save_sync_optout_field' ], 5, 1 );
			}
		}

		// Allow duplicate SKUs across translation siblings.
		// WC enforces unique SKUs, but translations of the same product
		// are separate posts that legitimately share the same SKU.
		// This is the same approach WPML and Polylang use.
		add_filter( 'wc_product_has_unique_sku', [ $this, 'allow_translation_duplicate_sku' ], 10, 3 );

		// Same for the GTIN/UPC/EAN global unique ID (WC 9.1+): translations
		// carry the source's identifier and WC validates it exactly like a SKU.
		add_filter( 'wc_product_has_global_unique_id', [ $this, 'allow_translation_duplicate_global_unique_id' ], 10, 3 );

		// Multi-currency - display prices in the per-language configured currency.
		if ( (bool) $settings->get( 'wc_currency_per_lang', false ) ) {
			$currency = new \PerfLocale\WooCommerce\MultiCurrency();
			$currency->register_hooks();
		}

		// Exchange rate sync - always boot so the AJAX handler and cron
		// hook are available. Scheduling is controlled internally by the
		// wc_exchange_rate_auto setting.
		$rate_sync = new \PerfLocale\WooCommerce\ExchangeRateSync( $settings );
		$rate_sync->register_hooks();

		// String translation - payment gateway titles, shipping labels.
		$strings = new \PerfLocale\WooCommerce\WcStringTranslation(
			$plugin->get( 'router' ),
			$plugin->get( 'cache' )
		);
		$strings->register_hooks();

		// ---- Attribute label auto-registration ----

		// Auto-register WC attribute labels ("Color", "Size", etc.) into
		// PerfLocale's String Translation system so they appear on the Strings
		// page. WC doesn't pass these through __(), so gettext never sees them.
		add_action( 'woocommerce_attribute_added', [ $this, 'register_attribute_label_string' ], 10, 2 );
		add_action( 'woocommerce_attribute_updated', [ $this, 'register_attribute_label_string' ], 10, 2 );

		// Clone variations onto a freshly-created product translation. Without
		// this a translated VARIABLE product has no variation children and
		// renders "out of stock" with no variation form — broken for the core
		// WC store use case. Runs late (20) so create_translation's own meta
		// copy has finished.
		add_action( 'perflocale/translation/created', [ $this, 'clone_product_variations' ], 20, 4 );

		// Register WC non-gettext strings (attribute labels, email subjects/headings)
		// when the user runs "Scan for Strings" from the Strings page.
		add_action( 'perflocale/strings/after_scan', [ $this, 'sync_attribute_labels' ] );
		add_action( 'perflocale/strings/after_scan', [ $this, 'sync_email_strings' ] );

		// Re-register email strings when WC email settings are saved,
		// so changed subjects/headings are immediately available for translation.
		add_action( 'woocommerce_settings_saved', [ $this, 'sync_email_strings' ] );

		// ---- Cart fragment invalidation ----

		// Force WC to recalculate cart totals when the language changes.
		// WC caches cart totals (including currency-formatted prices) in
		// the session. When switching from FR (BGN) to EN (EUR), the cached
		// totals still show BGN amounts until the cart page recalculates.
		// This hook triggers recalculation on every page load where the
		// language differs from the last-seen language.
		add_action( 'wp_loaded', [ $this, 'recalculate_cart_on_language_change' ], 20 );

		// Include the language slug in the WC cart hash so that cart
		// fragments cached in the browser's sessionStorage are refreshed
		// when the visitor switches language. Without this, the mini-cart
		// shows stale HTML from the previous language.
		add_filter( 'woocommerce_cart_hash', [ $this, 'add_language_to_cart_hash' ] );

		// Make the sessionStorage keys for cart fragments language-specific.
		add_filter( 'woocommerce_cart_fragment_name', [ $this, 'add_language_to_fragment_key' ] );
		add_filter( 'woocommerce_cart_hash_key', [ $this, 'add_language_to_fragment_key' ] );

		// Include language slug in WC AJAX URL so the fragment refresh
		// request hits the correct language context. Without this, the
		// AJAX request to /?wc-ajax=get_refreshed_fragments has no
		// language prefix and falls back to the cookie, which may be
		// set to a different language than the current page.
		add_filter( 'woocommerce_ajax_get_endpoint', [ $this, 'add_language_to_ajax_url' ], 10, 2 );

		// Clean up stale fragment entries from other languages in sessionStorage.
		add_action( 'wp_footer', [ $this, 'clear_stale_cart_fragments' ], 1 );

		// ---- Variation attribute translation ----

		// Frontend-only hooks (product pages, not AJAX).
		if ( ! is_admin() ) {
			// Bypass term language filter during variation dropdown rendering
			// so the product's original attribute terms are returned regardless
			// of the current language. Names are translated via a separate filter.
			add_filter( 'woocommerce_dropdown_variation_attribute_options_args', [ $this, 'suspend_term_filter_for_dropdown' ], 5 );
			add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ $this, 'restore_term_filter_after_dropdown' ], 999, 2 );

			// Translate attribute term names in variation dropdowns.
			add_filter( 'woocommerce_variation_option_name', [ $this, 'translate_variation_option_name' ], 10, 4 );
		}

		// Cart, checkout, mini-cart, and order translation hooks.
		// These must also fire during WC AJAX requests (add-to-cart,
		// update cart fragments, etc.) which run through admin-ajax.php
		// where is_admin() returns true. Without this, mini-cart
		// fragments show untranslated variation names.
		if ( ! is_admin() || wp_doing_ajax() ) {
			// Translate attribute labels ("Color" → "Цвят") via PerfLocale's
			// String Translation system. WC's wc_attribute_label() returns the
			// raw DB value without calling __(), so gettext never sees it.
			add_filter( 'woocommerce_attribute_label', [ $this, 'translate_attribute_label' ], 5, 3 );

			// Translate variation attribute names in the product title
			// when WC generates it (data-store level).
			add_filter( 'woocommerce_product_variation_title', [ $this, 'translate_variation_title' ], 10, 4 );

			// Translate variation names displayed in cart, mini-cart, and checkout.
			add_filter( 'woocommerce_cart_item_name', [ $this, 'translate_cart_item_name' ], 10, 3 );

			// Translate variation names in order items (order confirmation, emails).
			add_filter( 'woocommerce_order_item_name', [ $this, 'translate_order_item_name' ], 10, 2 );

			// Translate variation attribute values shown below the product name
			// in cart/checkout (e.g. "Color: Blue" → "Color: Синьо").
			add_filter( 'woocommerce_get_item_data', [ $this, 'translate_cart_item_data' ], 10, 2 );

			// Translate attribute values in order item meta display.
			add_filter( 'woocommerce_display_item_meta', [ $this, 'translate_order_item_meta' ], 10, 3 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		// Every WC field is stored in the main `perflocale_settings`
		// option (read by ExchangeRateSync, MultiCurrency, the REST APIs,
		// the SettingsPage save handler) and rendered + saved by
		// {@see render_settings_subtab()} + {@see sanitize_currencies_post()}
		// — NOT by AddonSettings. The `storage => 'global'` marker tells
		// the framework not to seed defaults into perflocale_addon_settings
		// (would create a dead duplicate copy), not to render via the
		// auto-form, and to redirect `wp perflocale addon settings
		// get/set` to read from the main settings option transparently.
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
	 * WooCommerce caches cart totals in the session. When the visitor
	 * switches language and the currency changes (e.g., EUR → BGN),
	 * the cached totals still show the old currency amounts in the
	 * header mini-cart until the cart page forces a recalculation.
	 *
	 * This method detects language changes by comparing the current
	 * language slug against a WC session variable, and triggers
	 * calculate_totals() when they differ.
	 *
	 * @return void
	 */
	public function recalculate_cart_on_language_change(): void {
		// Only on frontend, skip admin and AJAX (AJAX fragments will
		// use the recalculated totals from the page that triggered them).
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
	 * Cart fragments are cached in the browser's sessionStorage keyed by
	 * the cart hash. By including the language, switching languages triggers
	 * a fragment refresh so the mini-cart shows translated variation names.
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
	 * Include language prefix in WC AJAX endpoint URLs.
	 *
	 * WC AJAX endpoints like `/?wc-ajax=get_refreshed_fragments` have no
	 * language prefix. The language router falls back to the cookie, which
	 * may be set to a different language (e.g., FR cookie on EN page).
	 * This filter ensures the AJAX URL includes the language prefix so
	 * the router detects the correct language from the URL.
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

		// Path-prefixing is subdirectory-mode logic only. Subdomain/domain
		// modes carry the language in the host (already present on the page
		// origin), and query mode appends ?lang= via
		// UrlConverter::add_lang_to_wc_ajax_endpoint() instead.
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

		// WC_AJAX::get_endpoint() builds the path from home_url('/', 'relative'),
		// which carries the install's home path ('/shop/' on a subfolder install,
		// '/blog2/' on an MU-subdirectory subsite). The language prefix belongs
		// AFTER that home path — prepending it ('/fr/shop/…') lands outside the
		// install's rewrite scope and the cart-fragment request 404s.
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
	 * WooCommerce stores mini-cart HTML in the browser's sessionStorage
	 * using keys like `wc_fragments_xxx` and `wc_cart_hash_xxx`. These
	 * are shared across all languages, causing the cached EN fragment
	 * to be served on FR pages. Appending the language slug ensures
	 * each language has its own fragment cache.
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
	 * Render the per-product sync opt-out checkbox on the product data
	 * panel's Advanced tab.
	 *
	 * @return void
	 */
	public function render_sync_optout_field(): void {
		woocommerce_wp_checkbox(
			[
				'id'          => \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META,
				'value'       => get_post_meta( (int) get_the_ID(), \PerfLocale\WooCommerce\InventorySync::SYNC_OPTOUT_META, true ),
				'label'       => __( 'Independent across languages', 'perflocale' ),
				'description' => __( 'Do not synchronize this product\'s shared data (prices, stock, dimensions, SKU) with its translations. Tick it on a single language\'s product to give only that language independent values — for example a promotional price in one language.', 'perflocale' ),
			]
		);

		// Marker input: an unchecked checkbox is indistinguishable from an
		// absent one, so programmatic saves (REST, importer, CLI) that never
		// render this field must not clear the flag.
		echo '<input type="hidden" name="_perflocale_sync_optout_present" value="1" />';
	}

	/**
	 * Persist the sync opt-out checkbox from the product edit screen.
	 *
	 * @param int $product_id Saved product ID.
	 * @return void
	 */
	public function save_sync_optout_field( int $product_id ): void {
		// WooCommerce verifies its own meta-box nonce before firing
		// woocommerce_process_product_meta — the same trust model as every
		// WC product field saved from this screen.
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
	 * WooCommerce checks `is_existing_sku()` which finds ANY other product
	 * with the same SKU. Translation siblings are separate posts but represent
	 * the same physical product, so they legitimately share a SKU.
	 *
	 * @param bool|mixed $sku_found Whether a duplicate SKU was found.
	 * @param int        $product_id Current product ID.
	 * @param string     $sku The SKU being checked.
	 * @return bool|mixed False if the duplicate is a translation sibling;
	 *                    otherwise the value WooCommerce passed, unchanged.
	 */
	public function allow_translation_duplicate_sku( $sku_found, int $product_id, string $sku ) {
		// Not always a bool. wc_product_has_unique_sku() reads $sku_found from
		// $data_store->is_existing_sku() (wc-product-functions.php:1027), which
		// is NOT part of WC_Object_Data_Store_Interface - it reaches the store
		// through WC_Data_Store::__call(), and that method returns null with no
		// error when the loaded store does not implement it
		// (class-wc-data-store.php:220-226). Any site whose product data store
		// is swapped via `woocommerce_product_data_store` for one that is not
		// WC_Product_Data_Store_CPT-derived therefore lands null here. WC
		// itself shrugs - it only asks `if ( apply_filters( ... ) )` at line
		// 1029, and null is falsy - but `bool` made PHP throw at argument
		// binding, before the guard below could run. Pass the value back
		// UNCHANGED rather than coercing it: WC's own falsy branch is the
		// correct outcome, and coercing a sentinel is what broke this addon
		// once before.
		if ( ! $sku_found || $sku === '' ) {
			return $sku_found;
		}

		// Allow the shared SKU only when EVERY other holder is a translation
		// sibling; a genuine third-party duplicate keeps WC's rejection.
		return $this->siblings_own_all_holders( 'sku', $sku, $product_id ) ? false : $sku_found;
	}

	/**
	 * Allow a shared GTIN/UPC/EAN (`_global_unique_id`, WC 9.1+) across
	 * translation siblings, mirroring {@see allow_translation_duplicate_sku()}.
	 *
	 * Translations copy the source's global unique ID (it identifies the same
	 * physical product), but WC validates it exactly like a SKU, so without
	 * this exemption a GTIN-bearing product's translation cannot be saved.
	 *
	 * @param bool|mixed $found            Whether a duplicate global unique ID was found.
	 * @param int        $product_id       Current product ID.
	 * @param string     $global_unique_id The value being checked.
	 * @return bool|mixed False if every other holder is a translation sibling;
	 *                    otherwise the value WooCommerce passed, unchanged.
	 */
	public function allow_translation_duplicate_global_unique_id( $found, int $product_id, string $global_unique_id ) {
		// WooCommerce passes an UNDEFINED variable here on one of its own
		// branches: wc_product_has_global_unique_id() never initialises
		// $global_unique_id_found, and when the product data store lacks
		// is_existing_global_unique_id() it only logs and falls through
		// (wc-product-functions.php:1059-1065, WC 10.9.4), so line 1075 filters
		// null. That is the same story as the SKU twin above, except here it is
		// WC core's own code path rather than a missing __call() target. WC
		// survives it - null is falsy at line 1075 - so we must too, and the
		// value goes back unchanged, never coerced.
		if ( ! $found || $global_unique_id === '' ) {
			return $found;
		}

		return $this->siblings_own_all_holders( 'global_unique_id', $global_unique_id, $product_id ) ? false : $found;
	}

	/**
	 * Decide whether every OTHER product/variation carrying a lookup value is a
	 * translation sibling of the product under validation.
	 *
	 * WC's own `wc_get_product_id_by_sku()`/`..._global_unique_id()` return only
	 * the lowest matching ID (frequently the product being validated itself),
	 * which cannot distinguish the sibling case. Query all holders instead.
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
	 * Whether $holder_id is the same physical product as $product_id in another
	 * language: either a directly linked translation sibling, or a variation
	 * whose parent is a translation sibling (variations inherit the parent's
	 * language and are not group-linked, but WC validates their SKUs/GTINs too).
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
	 * Every product/variation OTHER than $product_id that holds $value in the
	 * given `wc_product_meta_lookup` column. Mirrors WC's `is_existing_sku()`
	 * query without the LIMIT 1 so all holders are returned.
	 *
	 * @param string $column     'sku' or 'global_unique_id'.
	 * @param string $value      The value to match.
	 * @param int    $product_id The product to exclude.
	 * @return int[]
	 */
	private function lookup_value_holders( string $column, string $value, int $product_id ): array {
		global $wpdb;

		// $column is interpolated as a SQL identifier — restrict it to the two
		// known lookup columns so it can never carry arbitrary input.
		if ( ! in_array( $column, [ 'sku', 'global_unique_id' ], true ) ) {
			return [];
		}

		// wp_slash() matches WC's own is_existing_sku()/is_existing_global_unique_id()
		// parameter handling so identical rows are found. Write-context only
		// (SKU/GTIN validation fires on product save), so no result cache.
		// The lookup table and the column are bound as %i identifiers; $column is
		// additionally restricted to the 2-value whitelist above. Only $wpdb->posts
		// stays interpolated - it is a core-provided identifier.
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
	 * Swap each product ID for its current-language sibling (cross-sells,
	 * upsells, cart cross-sell lists). Untranslated products pass through.
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
	 * Expand a coupon's product-ID restriction list with every translation
	 * sibling of each listed product.
	 *
	 * @param mixed $ids Saved product IDs.
	 * @return array<int, int>
	 */
	public function expand_coupon_product_ids( $ids ) {
		return $this->expand_ids_with_siblings( (array) $ids, \PerfLocale\Enum\ObjectType::Post );
	}

	/**
	 * Expand a coupon's category-ID restriction list with every translation
	 * sibling of each listed term.
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
	 * Frontend/AJAX/REST reads only: the coupon edit screen must keep the
	 * raw saved lists (an expanded list rendered there would persist on the
	 * next save). Rides the primed link caches; memo is blog-keyed so an
	 * MU worker crossing switch_to_blog() can't serve another blog's
	 * sibling sets.
	 *
	 * @param array<int, mixed>          $ids  Saved object IDs.
	 * @param \PerfLocale\Enum\ObjectType $type Post or Term.
	 * @return array<int, int>
	 */
	private function expand_ids_with_siblings( array $ids, \PerfLocale\Enum\ObjectType $type ): array {
		if ( $ids === [] || ( is_admin() && ! wp_doing_ajax() ) ) {
			return array_map( 'intval', $ids );
		}

		static $memo = [];

		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$repo = null;
		$out  = array_map( 'intval', $ids );

		foreach ( $out as $id ) {
			if ( $id <= 0 ) {
				continue;
			}

			$key = $blog . '|' . $type->value . '|' . $id;

			if ( ! isset( $memo[ $key ] ) ) {
				if ( null === $repo ) {
					$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository(
						\PerfLocale\Plugin::get_instance()->get( 'cache' )
					);
				}

				$siblings = [];

				foreach ( (array) $repo->get_translations( $id, $type ) as $link ) {
					$siblings[] = (int) $link->object_id;
				}

				$memo[ $key ] = $siblings;
			}

			$out = array_merge( $out, $memo[ $key ] );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Clear stale non-language-specific cart fragments from sessionStorage.
	 *
	 * Before the language-specific fragment keys were introduced, WC stored
	 * fragments under a single key shared across all languages. Those stale
	 * entries cause the mini-cart to show content in the wrong language.
	 * This script clears them once per browser session.
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

		// Get the CURRENT language-specific fragment name.
		// Any sessionStorage entries for wc_fragments_* or wc_cart_hash_*
		// that DON'T match the current language key are stale and should
		// be removed. This handles both old pre-fix entries and entries
		// from other languages.
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

		// Defensive: harden JSON encoding for inline-script context. The
		// fragment / hash key values come through `woocommerce_*` filters,
		// so a third-party callback could in theory return a string
		// containing `</script>` and break out of this <script> block.
		// JSON_HEX_TAG / JSON_HEX_AMP / JSON_HEX_APOS / JSON_HEX_QUOT
		// hex-encode `<`, `>`, `&`, `'`, `"` in the JSON output, which is
		// the canonical WP-handbook pattern for embedding JSON inside an
		// inline <script>.
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
	 * WC's wc_attribute_label() returns the raw DB value without calling __().
	 * This filter bridges the gap by looking up the label in PerfLocale's
	 * string translation system (domain: woocommerce, context: attribute_label).
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

		// Default language serves the raw label — a label is never translated
		// into its own language, so skip the service resolution + lookups, as
		// every sibling translate_* method in this class already does.
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

			// Per-attribute context - what the writers register under. Must be
			// tried first; see attribute_label_context() for why the context
			// cannot be shared across attributes.
			$translated = $st->get_translation(
				$label,
				'woocommerce',
				$this->attribute_label_context( $name )
			);

			if ( $translated !== null ) {
				return $translated;
			}

			// Legacy shared context, for rows written before the per-attribute
			// split. Harmless to keep: at most one such row can exist per store,
			// because the old code deleted the others.
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
	 * Auto-register a WC attribute label into PerfLocale's String Translation.
	 *
	 * Fired when an attribute is created or updated in WooCommerce.
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
	 * MUST be unique per attribute. `register_setting_string()` treats every
	 * row sharing a (domain, context) as an older revision of ONE setting: it
	 * migrates their translations onto the new row and then DELETES their
	 * links, groups and `strings` rows. Registering Color, Size and Material
	 * under a single shared 'attribute_label' context therefore made each
	 * attribute wipe the previous one, leaving exactly one translatable label
	 * per store and silently re-pointing an existing translation at the wrong
	 * attribute. `sync_email_strings()` already keys per field+id for the same
	 * reason - this mirrors it.
	 *
	 * The name is normalised so the write side (which sees `color`, from
	 * `wc_get_attribute_taxonomies()`) and the read side (which sees `pa_color`,
	 * from WooCommerce's `woocommerce_attribute_label` filter) agree.
	 *
	 * @param string $name Attribute name, with or without the `pa_` prefix.
	 * @return string Context string; falls back to the legacy shared context
	 *                only when the name is unknown, which cannot collide
	 *                because a nameless attribute cannot be looked up either.
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
	 * Register all existing WC attribute labels into PerfLocale's Strings system.
	 *
	 * Runs on perflocale/strings/after_scan when the user clicks "Scan for Strings".
	 * Individual attribute creates/updates are handled by register_attribute_label_string().
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
	 * Register WC email subjects, headings, and additional content
	 * in PerfLocale's String Translation system.
	 *
	 * Runs on perflocale/strings/after_scan (when user clicks "Scan for Strings")
	 * and on woocommerce_settings_saved (when WC email settings change).
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
		$fields = [ 'subject', 'heading', 'additional_content' ];

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
	 * Add WooCommerce post types to the translatable list.
	 *
	 * shop_order is excluded - orders are tagged with a language but not copied.
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
	 * Add WooCommerce taxonomies to the translatable list.
	 *
	 * Discovers all registered pa_* attribute taxonomies dynamically so
	 * new attributes created in WooCommerce → Attributes are auto-included.
	 *
	 * @param array<int, string> $taxonomies Existing taxonomies.
	 * @return array<int, string>
	 */
	public function add_taxonomies( array $taxonomies ): array {
		$taxonomies[] = 'product_cat';
		$taxonomies[] = 'product_tag';

		// Dynamically discover all registered product attribute taxonomies.
		// NOTE: No taxonomy_exists() check here - this filter runs at init:0 but
		// WooCommerce registers pa_* taxonomies at init:5. The taxonomy name is
		// always correct; WordPress filter hooks fire at page-render time when
		// everything is registered.
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
	 * WC stores default-language page IDs in options like
	 * woocommerce_checkout_page_id. Functions like is_checkout() compare
	 * the current page against this option. Without translation, these
	 * checks fail on non-default language pages.
	 *
	 * @param mixed $page_id The original page ID from the option.
	 * @return mixed The translated page ID, or the original if no translation exists.
	 */
	public function filter_wc_page_id( mixed $page_id ): mixed {
		// Per-page-ID recursion guard. A plain scalar flag wasn't enough:
		// if two different WC page options resolve during the same call
		// stack (shop + checkout, for instance), the second call would
		// see the flag set by the first and bail even though there was
		// no real recursion. Keyed-by-id keeps the guard narrow.
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

		// One-shot: the very first time this filter fires in a request, fetch
		// every known WC page ID and batch-prime their translation-link
		// caches. Without this, each subsequent filter call (WC typically
		// resolves shop, cart, checkout, my-account, and terms on every
		// frontend request, plus sibling pages via blocks) costs its own
		// 2-query transient read. One prime collapses them all to one SQL.
		if ( ! $primed ) {
			$primed_by_blog[ $primed_blog ] = true;
			$this->prime_wc_page_translations( $plugin );
		}

		$resolving[ $page_id_int ] = true;

		try {
			// Container singleton — WC resolves shop/cart/checkout/my-account
			// page options repeatedly per request, so a fresh repository
			// allocation per call is pure churn.
			$groups_repo   = $plugin->get( 'group_repo' );
			$translated_id = $groups_repo->get_translation_in_language(
				$page_id_int,
				\PerfLocale\Enum\ObjectType::Post,
				$language_id
			);

			return $translated_id ?? $page_id;
		} finally {
			unset( $resolving[ $page_id_int ] );
		}
	}

	/**
	 * Batch-prime translation caches for every known WooCommerce page.
	 *
	 * Called once per request, the first time filter_wc_page_id() fires.
	 *
	 * Reads page IDs directly from wp_options (not via get_option, which
	 * would re-trigger our own filter and recurse). The sibling-cascade
	 * inside prime_translations() means priming the primary page IDs also
	 * seeds the cache for their language siblings, so downstream calls for
	 * translated pages are L1 hits too.
	 *
	 * @param \PerfLocale\Plugin $plugin Plugin container.
	 * @return void
	 */
	private function prime_wc_page_translations( \PerfLocale\Plugin $plugin ): void {
		global $wpdb;

		$option_names = [
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_shop_page_id',
			'woocommerce_terms_page_id',
		];

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );

		// Read raw option_values from wp_options to avoid re-entering our own
		// option_{name}_page_id filter chain. $placeholders is a runtime-built
		// %s-list whose length matches count($option_names); scanner can't see
		// that statically. $wpdb->options is core-owned.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
				...$option_names
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$ids = [];

		foreach ( (array) $rows as $r ) {
			$id = (int) $r->option_value;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( $ids === [] ) {
			return;
		}

		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
		$repo->prime_translations( \PerfLocale\Enum\ObjectType::Post, $ids );
	}

	/**
	 * Translate a WooCommerce URL to include the current language prefix.
	 *
	 * Anything that is not a non-empty string goes back EXACTLY as it came in,
	 * type included. wc_get_page_permalink() runs its FALLBACK through the same
	 * dynamic `woocommerce_get_<page>_page_permalink` filters this is hooked
	 * to, and callers pass non-string sentinels: WooCommerce's own
	 * CartCheckoutUtils::has_cart_page() is `wc_get_page_permalink( 'cart', -1 )
	 * !== -1`. Casting that int to the string '-1' answered "yes, this store
	 * has a cart page" for a store that has none — and because the cast ran
	 * before any language check it did so on the default language, on
	 * monolingual sites and in admin too. The visible damage was a dead "View
	 * cart" button pointing at the home page on the add-to-cart notice, the two
	 * cart error messages, the Product Button block and the mini-cart widget.
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
	 * Force the current language onto WooCommerce page links that never reach
	 * wc_get_page_permalink().
	 *
	 * Parts of WooCommerce build a page URL straight from `get_permalink()`:
	 * the Mini-Cart footer buttons (MiniCartCartButtonBlock /
	 * MiniCartCheckoutButtonBlock, `get_permalink( wc_get_page_id( 'cart' ) )`)
	 * and the checkout's terms-and-conditions link
	 * (`get_permalink( wc_terms_and_conditions_page_id() )`). The
	 * `woocommerce_get_*` filters registered in boot() never fire for those.
	 * What does fire is `page_link`, where UrlConverter::filter_page_link()
	 * resolves the page in ITS OWN language — deliberately, because that same
	 * output feeds wp_get_canonical_url() and the fallback-canonical /
	 * hreflang pinning. Correct for SEO, wrong for a shop link: on /de/ the
	 * shopper is handed an unprefixed /cart-3/ and lands on an en-US page with
	 * their cart intact but the store back in English.
	 *
	 * Only UNTRANSLATED WooCommerce pages are touched. When the merchant has
	 * translated the cart page, filter_wc_page_id() already swapped the option
	 * to the translated ID and UrlConverter built the correct /de/warenkorb/ —
	 * so a page that resolves in the current language is left exactly as it is.
	 *
	 * Costs the request nothing, though not for the reason it looks like:
	 * WooCommerce keeps the cart, checkout, my-account and terms page IDs at
	 * `autoload = off`, so reading them is a real fetch, not an alloptions
	 * lookup. get_wc_page_ids() collapses the set into one primed read, that
	 * read runs each ID through filter_wc_page_id() — which batch-primes every
	 * WC page's translation links — and the lookup below is then an L1 cache
	 * hit. WooCommerce asks for the same options moments later on every
	 * front-end render (wc_body_class() calls is_cart(), is_checkout() and
	 * is_account_page()), so this only pulls that fetch forward. Measured on
	 * perflocale.local: with a cold options cache a render costs 4 option
	 * queries without this filter and 2 with it; with a warm persistent object
	 * cache, 1 either way.
	 *
	 * @param mixed $link    Page permalink, already filtered by UrlConverter.
	 * @param mixed $post_id ID of the page the permalink belongs to.
	 * @return mixed Language-prefixed permalink, or $link untouched.
	 */
	public function force_wc_page_language_prefix( mixed $link, mixed $post_id ): mixed {
		// Re-entry guard. This callback runs INSIDE get_permalink(), and the
		// WC page IDs below are read with get_option() — whose option_* filter
		// chain is open to any plugin, including ones that build permalinks.
		// Re-entering would be harmless in value terms (convert() is
		// idempotent) but would recurse without bound.
		static $running = false;

		if ( $running || ! is_string( $link ) || $link === '' ) {
			return $link;
		}

		$page_id = (int) $post_id;

		if ( $page_id <= 0 ) {
			return $link;
		}

		// Never touch the permalink of the page currently being rendered.
		// That one is the request's own identity URL: wp_get_canonical_url(),
		// og:url and the hreflang alternate set are all derived from it, and a
		// fallback render's canonical is deliberately pinned to the SOURCE
		// language ({@see \PerfLocale\Frontend\HreflangTags::filter_fallback_canonical}).
		// Measured on perflocale.local browsing /de/: drop this guard and
		// get_permalink() of the cart, checkout and my-account pages returns
		// the /de/ URL even while that page is the one being rendered, so its
		// own og:url and its en-US and x-default alternates all move onto the
		// German URL instead of pointing back at the source. (The shop page is
		// unaffected either way — see get_wc_page_ids() for why it is not in
		// the set.) Store navigation always links to a page OTHER than the one
		// on screen, so the fix loses nothing by standing aside here.
		//
		// The WP_Post test is LOAD-BEARING, do not simplify it back to
		// get_queried_object_id(). That id is polymorphic — a TERM id on a term
		// archive, a USER id on an author archive — and those ids collide with
		// post ids freely: on a stock WooCommerce install the funnel pages and
		// the first product_cat terms are allocated in the same low range. On
		// this very site category 222 ('Sin categoría', a browsable archive) is
		// the checkout page's id, so the id-only guard skipped the whole fix on
		// /category/uncategorized-es/ and handed a German shopper an unprefixed
		// /checkout-3/ — the exact polymorphism translation_links.type exists
		// for, and a bug that would read as flaky because it is page-dependent.
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

			// A non-null result means this page already IS the current
			// language's version (or one exists and owns that URL) — the link
			// UrlConverter produced is correct and must not be re-prefixed.
			$in_current_language = $plugin->get( 'group_repo' )->get_translation_in_language(
				$page_id,
				\PerfLocale\Enum\ObjectType::Post,
				$language_id
			);

			if ( $in_current_language !== null ) {
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
	 * `shop` is deliberately NOT in this list. It is the store's one indexable
	 * WooCommerce page, and it is reachable as a link target while ANOTHER page
	 * is queried (the product archive queries products, not the shop page), so
	 * the guard in force_wc_page_language_prefix() cannot protect it: measured
	 * on perflocale.local, including it moved /de/shop-3/'s canonical and
	 * og:url off /shop-3/ onto itself and broke the source-language pinning.
	 * Every WooCommerce-sanctioned way of asking for the shop URL runs through
	 * wc_get_page_permalink( 'shop' ), which boot() hooks directly.
	 *
	 * Of WooCommerce's page-ID options only `shop` autoloads; the four read
	 * here carry `autoload = off`, so an uncached call reaches the database:
	 * wp_prime_option_caches() turns that into ONE query for the whole set
	 * rather than one per get_option(). They are still read back through
	 * get_option() and not out of the primed cache directly, because the
	 * option_* filter chain is where filter_wc_page_id() maps each page to its
	 * current-language translation and batch-primes the translation-link cache
	 * for the whole set.
	 *
	 * Memoized per request and keyed by blog: each site in a network has its
	 * own WC pages, so a mid-request switch_to_blog() must not inherit the
	 * previous blog's IDs.
	 *
	 * A mid-request language override (the order-email render window) is NOT
	 * invalidated here and does not need to be: an ID that stops being the
	 * imposed language's page still lands on the translation check in the
	 * caller, which leaves such a link alone.
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

		// None of these four autoloads, so each get_option() below would be its
		// own SELECT on a cold options cache. One multi-get covers the set.
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
	 * Mirrors the helper the SEO addons already use, plus the query-var form:
	 * core routes its own tree to `?sitemap=`/`?sitemap-stylesheet=` and Rank
	 * Math to `?sitemap=`/`?sitemap_n=`, which is what a site whose pretty
	 * sitemap rewrite rules have not been flushed actually serves.
	 *
	 * REQUEST_URI is fixed before plugins_loaded, so the verdict is
	 * request-constant and can gate the page_link registration in boot()
	 * outright — no per-request cost on the pages the filter does run on.
	 *
	 * @return bool
	 */
	private function is_sitemap_request(): bool {
		static $is_sitemap = null;

		if ( $is_sitemap === null ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$is_sitemap  = (bool) preg_match(
				'/sitemap[^\\/]*\.xml|[?&]sitemap(_n|-stylesheet|-subtype)?=/',
				$request_uri
			);
		}

		return $is_sitemap;
	}

	/**
	 * Translate cart item permalink to the current browsing language.
	 *
	 * When a product was added to the cart while browsing in another language
	 * (e.g., added in AR, now viewing cart in FR), the permalink still points
	 * to the original language's product URL. This filter replaces it with the
	 * current language's product URL so all cart links are consistent.
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

		// Never point a guest at a draft, pending or private sibling — the same
		// rule the cart LABEL applies in translated_product_title(). Failing the
		// check falls through to url_converter->convert() at the end of this
		// method, which is exactly what an UNTRANSLATED product already gets, so
		// the link stays inside the current language instead of pointing at a
		// target the visitor cannot open.
		if ( $translated_id && $translated_id !== $product_id && $this->is_publicly_viewable_translation( (int) $translated_id ) ) {
			// Use the translated product's permalink.
			$translated_permalink = get_permalink( $translated_id );

			if ( $translated_permalink ) {
				// Preserve variation query parameters (e.g., ?attribute_pa_color=blue)
				// and ONLY those. The original permalink's other params are the
				// SOURCE post's routing identity — re-applying them clobbers the
				// translated URL: ?lang= re-routes the "current language" link
				// back to the original language (query mode), and ?p=/?product=
				// makes it load the original post outright (Plain permalinks).
				// WC_Product_Variation::get_permalink() only ever appends
				// attribute_* args, so that prefix is the whole whitelist.
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
	 * Handles /my-account/orders/, /my-account/downloads/, /my-account/edit-account/, etc.
	 *
	 * @param mixed $url Full endpoint URL.
	 * @param mixed $endpoint Endpoint slug (e.g. 'orders', 'downloads').
	 * @param mixed $value Endpoint value or empty string.
	 * @param mixed $permalink Base page permalink.
	 * @return mixed Language-prefixed URL, or $url untouched when it is not a
	 *               string — `woocommerce_get_endpoint_url` only ever carries
	 *               one, but the pass-through belongs to translate_wc_url().
	 */
	public function translate_endpoint_url( mixed $url, mixed $endpoint, mixed $value, mixed $permalink ): mixed {
		return $this->translate_wc_url( $url );
	}

	/**
	 * Temporarily remove the term language filter before WC queries
	 * attribute terms for the variation dropdown.
	 *
	 * Without this, TermQueryFilter excludes the product's attribute terms
	 * on non-default language pages (since the terms are linked to the
	 * default language), resulting in an empty dropdown.
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
	 * Re-add the term language filter after the variation dropdown HTML
	 * has been rendered.
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
	 * WooCommerce calls $_product->get_name() which returns the stored
	 * post_title like "Test Product - Blue". We translate the attribute
	 * suffix part to the current language.
	 *
	 * @param string $name Product name (may contain HTML link).
	 * @param array  $cart_item Cart item data.
	 * @param string $cart_item_key Cart item key.
	 * @return string Translated product name.
	 */
	public function translate_cart_item_name( string $name, array $cart_item, string $cart_item_key ): string {
		if ( empty( $cart_item['variation_id'] ) ) {
			// Simple product: map the LABEL to the current-language sibling the
			// same way translate_cart_item_permalink() maps the LINK. Without
			// this the cart showed the source-language title pointing at the
			// translated URL — label and link disagreeing on the same row.
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

		$translated_name = $this->build_translated_variation_name( $variation );

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
		if ( $product_id <= 0 ) {
			return null;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return null;
		}

		$current_slug = $plugin->get( 'router' )->get_current_slug();

		if ( $current_slug === '' ) {
			return null;
		}

		$manager       = new \PerfLocale\Translation\PostTranslationManager( $plugin->get( 'cache' ), $plugin->get( 'settings' ) );
		$translated_id = $manager->get_translation_id( $product_id, $current_slug );

		if ( ! $translated_id || $translated_id === $product_id ) {
			return null;
		}

		// The cart, mini-cart and checkout render for GUESTS. A translation is
		// created as a DRAFT, so "linked but not public yet" is the normal state
		// of half-finished work, not an edge case — swapping its title in
		// publishes unreleased copy to anyone holding an item in their basket.
		// Keep the source label instead. Same rule the cross-sell mapper already
		// applies in map_related_ids_to_language().
		if ( ! $this->is_publicly_viewable_translation( (int) $translated_id ) ) {
			return null;
		}

		$title = get_the_title( $translated_id );

		return $title !== '' ? $title : null;
	}

	/**
	 * Whether a translated post may be shown to the current visitor.
	 *
	 * Core's own visibility rule (WP 5.7+) with a status fallback for older
	 * cores — the same six lines PerfLocaleAcf, PerfLocalePods and
	 * PerfLocaleMetabox already ship. Deliberately NOT pushed down into
	 * PostTranslationManager::get_translation_id(): the editor, the
	 * Translations page and every translation workflow legitimately resolve
	 * drafts, so the rule belongs at the PUBLIC display consumer.
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
			// preg_replace_callback (not preg_replace): the replacement is built
			// from the translated name, which may contain $N / ${N} / \N that
			// preg_replace would interpret as backreferences (esc_html doesn't
			// escape $ or \) and corrupt names like "Gift Card $50". A callback
			// return value is used literally.
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

		$translated_name = $this->build_translated_variation_name( $variation );

		if ( $translated_name === null ) {
			return $name;
		}

		// The $name may be wrapped in an <a> tag.
		if ( str_contains( $name, '<a ' ) ) {
			// preg_replace_callback (not preg_replace): the replacement is built
			// from the translated name, which may contain $N / ${N} / \N that
			// preg_replace would interpret as backreferences (esc_html doesn't
			// escape $ or \) and corrupt names like "Gift Card $50". A callback
			// return value is used literally.
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
	 * Build a translated variation product name.
	 *
	 * Combines the parent product title with translated attribute values.
	 * Returns null if no translation is needed or possible.
	 *
	 * @param \WC_Product_Variation $variation Variation product.
	 * @return string|null Translated name or null.
	 */
	private function build_translated_variation_name( \WC_Product_Variation $variation ): ?string {
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
			// "Any <attribute>" variations carry an empty slug; WooCommerce
			// omits them from the title, so skip rather than emit an empty
			// part that implode() turns into a stray ", " separator.
			if ( $slug_value === '' ) {
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				// Custom (non-taxonomy) attribute — keep its raw stored value.
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

		$title_base = get_the_title( $variation->get_parent_id() );

		/** This filter is documented in WooCommerce class-wc-product-variation-data-store-cpt.php */
		$separator = apply_filters( 'woocommerce_product_variation_title_attributes_separator', ' - ', $variation ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.

		return $title_base . $separator . implode( ', ', $translated_parts );
	}

	/**
	 * Translate the variation product title (e.g. "Test Product - Blue" → "Test Product - Синьо").
	 *
	 * Fires when WooCommerce generates the variation title for display in
	 * cart, checkout, mini-cart, order details, and emails. Translates each
	 * attribute value in the suffix to the current language.
	 *
	 * @param string      $title Full variation title.
	 * @param \WC_Product $product Variation product object.
	 * @param string      $title_base Parent product title.
	 * @param string      $title_suffix Formatted attribute values.
	 * @return string Translated variation title.
	 */
	public function translate_variation_title( string $title, $product, string $title_base, string $title_suffix ): string {
		if ( $title_suffix === '' || ! $product instanceof \WC_Product_Variation ) {
			return $title;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $title;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();

		if ( $current_slug === '' ) {
			return $title;
		}

		// Skip on default language - variation title is already correct.
		$default = $router->get_default_language();

		if ( $default && $current_slug === $default->slug ) {
			return $title;
		}

		$term_manager = $this->get_term_manager();
		$attributes   = $product->get_attributes();

		if ( empty( $attributes ) ) {
			return $title;
		}

		$translated_parts = [];

		foreach ( $attributes as $taxonomy => $slug_value ) {
			// "Any <attribute>" variations carry an empty slug; WooCommerce
			// omits them from the title, so skip rather than emit an empty
			// part that implode() turns into a stray ", " separator.
			if ( $slug_value === '' ) {
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				// Custom (non-taxonomy) attribute — keep its raw stored value.
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
					continue;
				}
			}

			$translated_parts[] = $term->name;
		}

		if ( empty( $translated_parts ) ) {
			return $title;
		}

		$translated_suffix = implode( ', ', $translated_parts );

		/** This filter is documented in WooCommerce class-wc-product-variation-data-store-cpt.php */
		$separator = apply_filters( 'woocommerce_product_variation_title_attributes_separator', ' - ', $product ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.

		return $title_base . $separator . $translated_suffix;
	}

	/**
	 * Translate a variation attribute option name to the current language.
	 *
	 * Looks up the term's translation via TermTranslationManager and
	 * returns the translated name. Falls back to the original name when
	 * no translation exists.
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
	 * WooCommerce's wc_get_formatted_cart_item_data() builds an array of
	 * key/value pairs like [{key: "Color", value: "Blue"}]. The values
	 * come from get_term_by('slug') which returns the original language
	 * term name. This filter translates the values.
	 *
	 * @param array<int, array{key: string, value: string}>|mixed $item_data Cart item data.
	 * @param array<string, mixed>                                $cart_item Cart item.
	 * @return array<int, array{key: string, value: string}>|mixed Item data,
	 *                                                            unchanged when
	 *                                                            it is not an
	 *                                                            item-data array.
	 */
	public function translate_cart_item_data( $item_data, array $cart_item ) {
		// WooCommerce expects this filter to be able to return a non-array and
		// keeps going: wc-template-functions.php:4538 filters, then line 4540
		// gates the whole formatting block on `if ( is_array( $item_data ) )`,
		// and the Store API's CartItemSchema.php:172 only foreach()es it. Our
		// callback sits at priority 10, so anything an earlier callback
		// returned binds here first. `array` made that a fatal on the cart and
		// checkout - the busiest pages the addon touches - before this body's
		// own guards could run. Return the value untouched instead.
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

		// Build a (label → [original term name → translated term name]) map.
		// Keying by attribute label prevents collisions between two
		// taxonomies that happen to share a term name (e.g. both
		// pa_color and pa_size defining "Red" would collide in a flat map).
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
					// Use the attribute's display label (what appears in
					// $data['key']) as the outer key. wc_attribute_label
					// strips the `pa_` prefix and applies the admin-set label.
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
					// Replace the original term name with the translated one.
					// WC renders meta as: <p><strong>Label:</strong> Value</p>
					// Use word-boundary-safe replacement to avoid partial matches.
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
	 * Render the WooCommerce settings subtab content (everything inside
	 * the `<table class="form-table">` wrapper on the WC subtab under
	 * PerfLocale → Settings → Addons).
	 *
	 * Owns the entire bespoke UI: pages section, products section, emails
	 * section, per-language exchange-rate matrix, auto-sync controls,
	 * provider-conditional API key inputs, sync-now button. Replaces the
	 * historical SettingsPage::render_woocommerce_tab() so all WC-specific
	 * rendering now lives in the WC addon directory.
	 *
	 * Data path is unchanged from the historical version: form fields
	 * still POST to admin.php?page=perflocale-settings with the
	 * perflocale_save_settings nonce; values land in `perflocale_settings`
	 * under `wc_*` keys. Conditional row visibility (currency-table /
	 * auto-sync / provider / interval / status / per-provider API key
	 * rows) is driven by the generic `data-perflocale-show-if` JS.
	 *
	 * @param \PerfLocale\Settings $settings The plugin settings service.
	 * @return void
	 */
	public function render_settings_subtab( \PerfLocale\Settings $settings ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			?>
			<tr>
				<td colspan="2">
					<div class="notice notice-warning inline" style="margin:8px 0;">
						<p><?php echo esc_html__( 'WooCommerce is not active. Install and activate WooCommerce to use these settings.', 'perflocale' ); ?></p>
					</div>
				</td>
			</tr>
			<?php
			return;
		}

		$email_translation = (bool) $settings->get( 'wc_email_translation', true );
		$sync_stock        = (bool) $settings->get( 'wc_sync_stock', true );
		$sync_prices       = (bool) $settings->get( 'wc_sync_prices', true );
		$currency_per_lang = (bool) $settings->get( 'wc_currency_per_lang', false );
		$wc_currencies     = (array) $settings->get( 'wc_currencies', [] );
		$auto_sync         = (bool) $settings->get( 'wc_exchange_rate_auto', false );
		$rate_provider     = (string) $settings->get( 'wc_exchange_rate_provider', '' );
		$rate_interval     = (string) $settings->get( 'wc_exchange_rate_interval', 'daily' );

		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->get( 'cache' ) );
		$all_langs = $lang_repo->get_active();

		$rate_sync = new \PerfLocale\WooCommerce\ExchangeRateSync( $settings );
		$providers = $rate_sync->get_providers();
		$intervals = \PerfLocale\WooCommerce\ExchangeRateSync::get_intervals();
		$last_sync = \PerfLocale\WooCommerce\ExchangeRateSync::get_last_sync();

		// Enqueue the WC-specific JS asset (sync-now + create-pages
		// button wiring + the rate-input readonly toggle on auto-sync
		// change). The generic addon-settings-conditional.js (already
		// enqueued by SettingsPage::enqueue_assets) handles show/hide
		// of conditional rows via the data-perflocale-show-if attrs
		// emitted below.
		wp_enqueue_script(
			'perflocale-wc-settings',
			PERFLOCALE_URL . 'assets/js/wc-settings.js',
			[],
			PERFLOCALE_VERSION,
			true
		);
		wp_localize_script(
			'perflocale-wc-settings',
			'perflocaleWcData',
			[
				'syncRatesNonce'    => wp_create_nonce( 'perflocale_sync_rates' ),
				'createPagesNonce'  => wp_create_nonce( 'perflocale_create_wc_pages' ),
				'i18nRatesUpdated'  => __( 'Rates updated.', 'perflocale' ),
				'i18nSyncFailed'    => __( 'Sync failed.', 'perflocale' ),
				'i18nNetworkError'  => __( 'Network error.', 'perflocale' ),
				'i18nCreatingPages' => __( 'Creating pages...', 'perflocale' ),
				'i18nFailed'        => __( 'Failed', 'perflocale' ),
				'i18nFailedDot'     => __( 'Failed.', 'perflocale' ),
				'i18nDone'          => __( 'Done', 'perflocale' ),
				'i18nNetworkErr'    => __( 'Network error', 'perflocale' ),
			]
		);

		?>

		<tr>
			<td colspan="2"><h3 style="margin:0 0 4px;"><?php echo esc_html__( 'Pages', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'WooCommerce Pages', 'perflocale' ); ?></th>
			<td>
				<p class="description" style="margin:0 0 8px;">
					<?php echo esc_html__( 'Create translation stubs for Cart, Checkout, My Account, and Shop pages in all active languages. Page titles will be translated using local WordPress translations first, then machine translation if enabled. Existing translations will not be affected.', 'perflocale' ); ?>
				</p>
				<div style="display:flex;align-items:center;gap:8px;">
					<button type="button" class="button" id="perflocale-create-wc-pages">
						<?php echo esc_html__( 'Create Page Translations', 'perflocale' ); ?>
					</button>
				</div>
				<div id="perflocale-wc-progress" style="display:none;margin-top:10px;max-width:420px;">
					<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
						<span id="perflocale-wc-status" style="font-size:13px;color:#50575e;"><?php echo esc_html__( 'Creating pages...', 'perflocale' ); ?></span>
						<span id="perflocale-wc-percent" style="font-size:12px;color:#50575e;font-weight:500;"></span>
					</div>
					<div style="width:100%;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
						<div id="perflocale-wc-bar" style="width:0;height:100%;background:#2271b1;border-radius:4px;transition:width 0.3s ease;"></div>
					</div>
				</div>
				<div id="perflocale-wc-pages-result" style="margin-top:8px;"></div>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Products', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Inventory Sync', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_sync_stock" value="1" <?php checked( $sync_stock ); ?>>
					<?php echo esc_html__( 'Sync stock, SKU, and pricing across all language variants', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'Keeps stock levels, SKU, price, weight, and dimensions identical across all translations of the same product. Prevents over-selling when the same physical product exists in multiple languages.', 'perflocale' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Price Sync', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_sync_prices" value="1" <?php checked( $sync_prices ); ?>>
					<?php echo esc_html__( 'Keep prices the same across all language variants', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'Included in Inventory Sync above. Disable only if you want language-specific base prices (unusual - typically handled via the currency table below).', 'perflocale' ); ?></p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Emails', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Order Email Language', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_email_translation" value="1" <?php checked( $email_translation ); ?>>
					<?php echo esc_html__( 'Send order confirmation and status emails in the customer\'s language', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'The customer\'s language is detected when they place the order and stored with the order. All subsequent emails (processing, completed, refunded, etc.) are sent in that language.', 'perflocale' ); ?></p>
			</td>
		</tr>

		<tr>
			<td colspan="2"><h3 style="margin:16px 0 4px;"><?php echo esc_html__( 'Currency', 'perflocale' ); ?></h3></td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Per-Language Currency', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_currency_per_lang" value="1" <?php checked( $currency_per_lang ); ?> id="perflocale-wc-currency-toggle">
					<?php echo esc_html__( 'Display prices in a different currency per language', 'perflocale' ); ?>
				</label>
				<p class="description">
					<?php echo esc_html__( 'Prices are converted on the fly using the exchange rate below, and checkout and orders are processed in the displayed (per-language) currency. Make sure your payment gateway accepts each currency you enable.', 'perflocale' ); ?>
					<a href="https://perflocale.com/docs/woocommerce/#per-language-currency" target="_blank" rel="noopener" style="margin-left:4px;"><?php echo esc_html__( 'WooCommerce multilingual setup', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr id="perflocale-wc-currency-table" data-perflocale-show-if='{"wc_currency_per_lang":true}'
		<?php
		if ( ! $currency_per_lang ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Exchange Rates', 'perflocale' ); ?></th>
			<td>
				<?php if ( count( $all_langs ) <= 1 ) : ?>
					<p class="description" style="margin:0;">
						<?php
						printf(
							/* translators: %s: URL to the Languages admin page */
							esc_html__( 'Add a second language at %s to configure per-language exchange rates.', 'perflocale' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=perflocale-languages' ) ) . '">' . esc_html__( 'PerfLocale → Languages', 'perflocale' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
				<p class="description" style="margin-bottom:8px;">
					<?php echo esc_html__( 'Set the currency code and exchange rate for each language. Prices are multiplied by the exchange rate.', 'perflocale' ); ?>
				</p>
				<table class="widefat fixed perflocale-mc-currency-table" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:20%;padding-left:8px;"><?php echo esc_html__( 'Language', 'perflocale' ); ?></th>
							<th style="width:12%;"><?php echo esc_html__( 'Currency', 'perflocale' ); ?></th>
							<th style="width:16%;"><?php echo esc_html__( 'Rate', 'perflocale' ); ?></th>
							<th style="width:24%;"><?php echo esc_html__( 'Display as', 'perflocale' ); ?></th>
							<th style="width:28%;"><?php echo esc_html__( 'Position', 'perflocale' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$default_currency = (string) get_option( 'woocommerce_currency', 'USD' );
						// Auto-synced rates persist to a dedicated option, not into
						// wc_currencies — read them here so the input mirrors the
						// last-synced value when auto-sync is on.
						$auto_rates = $auto_sync ? (array) get_option( \PerfLocale\WooCommerce\ExchangeRateSync::RATES_OPTION, [] ) : [];

						foreach ( $all_langs as $lang ) :
							$flag  = \PerfLocale\Helper::get_flag_emoji( $lang );
							$saved = $wc_currencies[ $lang->slug ] ?? [];

							// Saved value wins; otherwise infer the currency
							// from the language's locale (pl_PL → PLN etc.).
							if ( isset( $saved['currency_code'] ) && $saved['currency_code'] !== '' ) {
								$curr_code = (string) $saved['currency_code'];
							} else {
								$inferred  = \PerfLocale\WooCommerce\LocaleCurrency::guess_currency( $lang );
								$curr_code = $inferred !== '' ? $inferred : $default_currency;
							}
							// A manually-pinned currency keeps its saved rate even
							// when auto-sync is on; only non-pinned currencies show
							// the live auto rate.
							$is_manual = (bool) ( $saved['manual_rate'] ?? false );
							$rate      = ( $auto_sync && ! $is_manual && isset( $auto_rates[ $lang->slug ] ) )
								? (float) $auto_rates[ $lang->slug ]
								: ( $saved['exchange_rate'] ?? 1.0 );
							$display  = $saved['display'] ?? 'symbol';
							$position = $saved['position'] ?? 'default';
							$prefix   = 'wc_currencies[' . esc_attr( $lang->slug ) . ']';
							$symbol   = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $curr_code ) : $curr_code;
							?>
							<tr>
								<td style="padding-left:8px;"><?php echo esc_html( $flag . ' ' . ( $lang->native_name ?: $lang->name ) ); ?></td>
								<td>
									<input type="text"
										name="<?php echo esc_attr( $prefix ); ?>[currency_code]"
										value="<?php echo esc_attr( $curr_code ); ?>"
										placeholder="<?php echo esc_attr( $default_currency ); ?>"
										maxlength="3"
										class="small-text"
										style="width:100%;text-transform:uppercase;">
								</td>
								<td>
									<input type="number"
										name="<?php echo esc_attr( $prefix ); ?>[exchange_rate]"
										value="<?php echo esc_attr( (string) $rate ); ?>"
										min="0.0001"
										step="0.0001"
										class="small-text perflocale-rate-input"
										style="width:100%;"
										<?php
										if ( $auto_sync && ! $is_manual ) {
											echo 'readonly'; }
										?>
										>
									<label class="perflocale-mc-rate-note" style="display:block;">
										<input type="checkbox"
											name="<?php echo esc_attr( $prefix ); ?>[manual_rate]"
											value="1"
											<?php checked( $is_manual ); ?>>
										<?php echo esc_html__( 'Manual (do not auto-sync)', 'perflocale' ); ?>
									</label>
									<?php if ( $auto_sync && ! $is_manual ) : ?>
										<span class="description perflocale-mc-rate-note">
											<?php echo esc_html__( 'Auto-synced', 'perflocale' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<select name="<?php echo esc_attr( $prefix ); ?>[display]" style="width:100%;">
										<option value="symbol" <?php selected( $display, 'symbol' ); ?>><?php /* translators: %s: currency symbol */ printf( esc_html__( 'Symbol (%s)', 'perflocale' ), esc_html( $symbol ) ); ?></option>
										<option value="code" <?php selected( $display, 'code' ); ?>><?php /* translators: %s: currency code */ printf( esc_html__( 'Code (%s)', 'perflocale' ), esc_html( $curr_code ) ); ?></option>
									</select>
								</td>
								<td>
									<select name="<?php echo esc_attr( $prefix ); ?>[position]" style="width:100%;">
										<option value="default" <?php selected( $position, 'default' ); ?>><?php echo esc_html__( 'Default (WC setting)', 'perflocale' ); ?></option>
										<option value="left" <?php selected( $position, 'left' ); ?>><?php echo esc_html( $symbol . '10' ); ?></option>
										<option value="left_space" <?php selected( $position, 'left_space' ); ?>><?php echo esc_html( $symbol . ' 10' ); ?></option>
										<option value="right" <?php selected( $position, 'right' ); ?>><?php echo esc_html( '10' . $symbol ); ?></option>
										<option value="right_space" <?php selected( $position, 'right_space' ); ?>><?php echo esc_html( '10 ' . $symbol ); ?></option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top:6px;">
					<?php
					printf(
						/* translators: %s: default WooCommerce currency */
						esc_html__( 'Your store\'s base currency is %s. Set exchange rate to 1.0 to use the base currency for a language.', 'perflocale' ),
						'<strong>' . esc_html( $default_currency ) . '</strong>'
					);
					?>
				</p>
				<?php endif; // count > 1 ?>
			</td>
		</tr>

		<tr id="perflocale-wc-auto-sync-section" data-perflocale-show-if='{"wc_currency_per_lang":true}'
		<?php
		if ( ! $currency_per_lang ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Auto-Sync Rates', 'perflocale' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wc_exchange_rate_auto" value="1" <?php checked( $auto_sync ); ?> id="perflocale-auto-sync-toggle">
					<?php echo esc_html__( 'Automatically fetch exchange rates from a live API', 'perflocale' ); ?>
				</label>
				<p class="description"><?php echo esc_html__( 'When enabled, exchange rates are updated automatically on the schedule below. Manual rate fields become read-only.', 'perflocale' ); ?></p>
			</td>
		</tr>

		<tr id="perflocale-wc-sync-provider" data-perflocale-show-if='{"op":"AND","rules":[{"wc_currency_per_lang":true},{"wc_exchange_rate_auto":true}]}'
		<?php
		if ( ! $currency_per_lang || ! $auto_sync ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Rate Provider', 'perflocale' ); ?></th>
			<td>
				<?php if ( empty( $providers ) ) : ?>
					<p class="description" style="margin-top:0;">
						<?php echo esc_html__( 'No exchange-rate provider is registered. PerfLocale does not bundle one, so no request is made to any rate service unless your site arranges it.', 'perflocale' ); ?>
					</p>
				<?php else : ?>
					<select name="wc_exchange_rate_provider" id="perflocale-rate-provider">
						<?php foreach ( $providers as $pid => $prov ) : ?>
							<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $rate_provider, $pid ); ?>
								data-needs-key="<?php echo esc_attr( ! empty( $prov['needs_key'] ) ? '1' : '0' ); ?>"
								data-key-setting="<?php echo esc_attr( $prov['key_setting'] ?? '' ); ?>">
								<?php echo esc_html( (string) ( $prov['name'] ?? $pid ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<p class="description" style="margin-top:6px;">
					<?php echo esc_html__( 'Register a rate provider with the perflocale/woocommerce/exchange_rate_providers filter, or supply rates directly from perflocale/woocommerce/exchange_rates_fetched.', 'perflocale' ); ?>
					<br>
					<a href="https://perflocale.com/docs/exchange-rates/#sync-intervals" target="_blank" rel="noopener"><?php echo esc_html__( 'Exchange rate providers docs', 'perflocale' ); ?> <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;"></span></a>
				</p>
			</td>
		</tr>

		<tr id="perflocale-wc-sync-interval" data-perflocale-show-if='{"op":"AND","rules":[{"wc_currency_per_lang":true},{"wc_exchange_rate_auto":true}]}'
		<?php
		if ( ! $currency_per_lang || ! $auto_sync ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Sync Interval', 'perflocale' ); ?></th>
			<td>
				<select name="wc_exchange_rate_interval">
					<?php foreach ( $intervals as $ikey => $ilabel ) : ?>
						<option value="<?php echo esc_attr( $ikey ); ?>" <?php selected( $rate_interval, $ikey ); ?>>
							<?php echo esc_html( $ilabel ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Choose based on your API plan limits. Free tiers typically allow 1,000-1,500 requests per month.', 'perflocale' ); ?></p>
			</td>
		</tr>

		<?php
		// API-key fields are supplied by whichever rate provider the site
		// registers via perflocale/woocommerce/exchange_rate_providers.
		$key_fields = [];

		foreach ( $key_fields as $field_key => $field ) :
			$is_visible      = $currency_per_lang && $auto_sync && $rate_provider === $field['provider'];
			$override_source = $settings->get_override_source( $field_key );
			$is_overridden   = $override_source !== null;
			$stored_val      = $is_overridden ? '' : (string) $settings->get( $field_key, '' );
			$show_if_json    = wp_json_encode(
				[
					'op'    => 'AND',
					'rules' => [
						[ 'wc_currency_per_lang' => true ],
						[ 'wc_exchange_rate_auto' => true ],
						[ 'wc_exchange_rate_provider' => $field['provider'] ],
					],
				]
			);
			?>
		<tr class="perflocale-api-key-row" data-provider="<?php echo esc_attr( $field['provider'] ); ?>" data-perflocale-show-if="<?php echo esc_attr( (string) $show_if_json ); ?>" 
			<?php
			if ( ! $is_visible ) {
				echo 'style="display:none;"'; }
			?>
		>
			<th scope="row"><?php echo esc_html( $field['label'] ); ?></th>
			<td>
				<?php if ( $is_overridden ) : ?>
					<code><?php echo esc_html( $field['constant'] ); ?></code>
					<span class="description">
						<?php
						echo esc_html(
							match ( $override_source ) {
							'env'       => __( 'Defined in environment variable', 'perflocale' ),
							'connector' => __( 'Provided by WordPress Connectors API', 'perflocale' ),
							default     => __( 'Defined in wp-config.php', 'perflocale' ),
							}
						);
						?>
					</span>
				<?php else : ?>
					<input type="password"
						name="<?php echo esc_attr( $field_key ); ?>"
						value="<?php echo esc_attr( $stored_val ); ?>"
						class="regular-text"
						autocomplete="off">
					<p class="description">
						<?php
						printf(
							/* translators: %s: PHP constant or environment variable name (same name used for both). */
							esc_html__( 'Can also be set as the %s environment variable or PHP constant for security.', 'perflocale' ),
							'<code>' . esc_html( $field['constant'] ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>

		<tr id="perflocale-wc-sync-status" data-perflocale-show-if='{"op":"AND","rules":[{"wc_currency_per_lang":true},{"wc_exchange_rate_auto":true}]}'
		<?php
		if ( ! $currency_per_lang || ! $auto_sync ) {
			echo ' style="display:none;"'; }
		?>
		>
			<th scope="row"><?php echo esc_html__( 'Sync Status', 'perflocale' ); ?></th>
			<td>
				<p id="perflocale-last-sync-info">
					<?php if ( ! empty( $last_sync['timestamp'] ) ) : ?>
						<?php
						$provider_name = $providers[ $last_sync['provider'] ?? '' ]['name'] ?? ( $last_sync['provider'] ?? '' );
						printf(
							/* translators: 1: date/time, 2: provider name */
							esc_html__( 'Last synced: %1$s via %2$s', 'perflocale' ),
							'<strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_sync['timestamp'] ) ) . '</strong>',
							esc_html( $provider_name )
						);
						?>
					<?php else : ?>
						<?php echo esc_html__( 'No sync performed yet.', 'perflocale' ); ?>
					<?php endif; ?>
				</p>
				<button type="button" class="button" id="perflocale-sync-now" style="margin-top:4px;vertical-align:middle;">
					<?php echo esc_html__( 'Sync Now', 'perflocale' ); ?>
				</button>
				<span id="perflocale-sync-result" style="margin-left:4px;vertical-align:middle;margin-top:4px;display:inline-block;"></span>
				<span id="perflocale-sync-spinner" class="spinner" style="float:none;vertical-align:middle;margin-top:4px;"></span>
			</td>
		</tr>

		<?php
	}

	/**
	 * Extract + sanitize per-language WC currency settings from the
	 * SettingsPage form POST. Called by the main settings save handler
	 * to populate the `wc_currencies` settings key from the matrix
	 * inputs emitted by {@see render_settings_subtab()}.
	 *
	 * @return array<string, array{currency_code: string, exchange_rate: float, display: string, position: string}>
	 */
	public static function sanitize_currencies_post(): array {
		// Merge onto the currently-saved map rather than replacing it:
		// render_settings_subtab() only emits inputs for ACTIVE languages (and
		// none at all with a single active language), so a wholesale replace
		// would silently drop saved currency rows for every deactivated
		// language. POSTed rows win; unrendered rows survive until the language
		// is deleted (LanguageRepository::delete prunes the key).
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

			// Validated, not byte-truncated. substr( …, 0, 3 ) on a four-byte
			// character leaves three bytes of a half-finished UTF-8 sequence, and
			// the consequence is not local: the invalid value goes into the
			// settings array, update_option() hands it to wpdb, strip_invalid_text()
			// drops the bad bytes while the serialize() length header still claims
			// them, and the whole blob stops unserialising. get_option() then
			// returns false, load() falls back to defaults, and EVERY setting in
			// the plugin is silently lost. ISO 4217 codes are three ASCII letters
			// by definition, so anything else is simply not a currency code.
			$raw_code = sanitize_text_field( (string) ( $data['currency_code'] ?? '' ) );
			$code     = preg_match( '/^[A-Za-z]{3}$/', $raw_code ) === 1 ? strtoupper( $raw_code ) : '';

			if ( $code === '' ) {
				continue;
			}

			$display  = sanitize_key( (string) ( $data['display'] ?? 'symbol' ) );
			$position = sanitize_key( (string) ( $data['position'] ?? 'default' ) );

			$out[ $slug ] = [
				'currency_code' => $code,
				'exchange_rate' => max( 0.0001, (float) ( $data['exchange_rate'] ?? 1.0 ) ),
				// Carry the manual-rate pin through the POST handler — without it
				// the auto-sync skip (ExchangeRateSync / MultiCurrency) never sees
				// the flag, so a user-entered rate is silently overwritten.
				'manual_rate'   => ! empty( $data['manual_rate'] ),
				'display'       => in_array( $display, [ 'symbol', 'code' ], true ) ? $display : 'symbol',
				'position'      => in_array( $position, [ 'default', 'left', 'left_space', 'right', 'right_space' ], true ) ? $position : 'default',
			];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array_merge( $existing, $out );
	}

	/**
	 * Map a variable product's TAXONOMY attribute options onto the target
	 * language's sibling terms.
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
			// Custom (non-taxonomy) attributes carry literal strings, not term
			// IDs — there is no sibling term to point at, so leave them alone.
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

				// Untranslated term: keep the source term so the option is
				// still offered rather than silently dropped.
				$mapped[] = (int) $term_id;
			}

			if ( $changed ) {
				$attribute->set_options( $mapped );
			}
		}

		return $attributes;
	}

	/**
	 * Map a variation's attribute VALUES onto the target language's sibling
	 * term slugs, so they keep matching the parent's translated options.
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
			// "Any <attribute>" is stored as an empty value — keep it empty.
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
	 * Clone a variable product's variations onto its freshly-created
	 * translation.
	 *
	 * Copies attributes, prices, stock, shipping fields, image, and the
	 * variation description (a seed — the translator owns it afterwards).
	 * SKUs are copied VERBATIM: translation siblings deliberately share SKUs
	 * (they are the same physical inventory — see InventorySync), so WC's
	 * unique-SKU validation is suspended for the duration of the clone.
	 * Taxonomy attribute options and variation values are remapped to the
	 * target language's sibling terms so the variation form can match them.
	 *
	 * Idempotent: bails when the translation already has variation children
	 * (re-linking or a re-fired hook can't duplicate them).
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

			// The product_type taxonomy term is not part of the meta copy, so
			// the translation materialises as a simple product — set it to
			// variable BEFORE loading the target so WC returns the right class.
			wp_set_object_terms( (int) $new_id, 'variable', 'product_type' );

			$target = wc_get_product( (int) $new_id );

			if ( ! $target instanceof \WC_Product_Variable ) {
				return;
			}

			// Idempotency: never duplicate existing children.
			if ( [] !== $target->get_children() ) {
				return;
			}

			// Parent attribute definitions must match the source or the
			// variation form can't render its selects — but for TAXONOMY
			// attributes they must name the TARGET language's terms. The
			// translated product is assigned the sibling terms (see
			// TermAssignmentFilter::normalize_assignment), while a verbatim
			// copy of the source's options would point at the source terms;
			// wc_dropdown_variation_attribute_options() only emits an <option>
			// for a term whose slug appears in BOTH sets, so the intersection
			// came out empty and the dropdown rendered with no choices at all
			// — every translated variable product was unpurchasable.
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
					// Variation attribute VALUES are term slugs; they must move
					// to the sibling slugs in lock-step with the parent's
					// options above, because find_matching_product_variation()
					// matches the posted attribute_pa_* value against this meta.
					$nv->set_attributes( $this->translate_variation_attributes( $sv->get_attributes(), (string) $target_slug ) );
					$nv->set_status( $sv->get_status() );
					$nv->set_regular_price( (string) $sv->get_regular_price( 'edit' ) );
					$nv->set_sale_price( (string) $sv->get_sale_price( 'edit' ) );
					// A sale price with no schedule is on sale immediately and
					// indefinitely, and wc_scheduled_sales only ends sales that
					// carry a _sale_price_dates_to — without the dates the clone
					// would go on sale permanently (or a future sale would start
					// today). The source window must travel with the clone.
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
						// The downloadable FLAG without the files grants
						// ZERO download permissions at purchase — a customer
						// buying the translated variation would pay for a
						// download that never appears in their account or
						// order emails. The file list, limit, and expiry
						// must travel with the clone.
						$nv->set_downloads( $sv->get_downloads() );
						$nv->set_download_limit( $sv->get_download_limit( 'edit' ) );
						$nv->set_download_expiry( $sv->get_download_expiry( 'edit' ) );
					}

					$nv->set_weight( (string) $sv->get_weight( 'edit' ) );
					$nv->set_length( (string) $sv->get_length( 'edit' ) );
					$nv->set_width( (string) $sv->get_width( 'edit' ) );
					$nv->set_height( (string) $sv->get_height( 'edit' ) );
					$nv->set_tax_class( (string) $sv->get_tax_class( 'edit' ) );
					// Per-variation shipping class (e.g. "bulky") — without it the
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
}
