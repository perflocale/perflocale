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
	 * WC email settings registered as translatable strings, one context per
	 * field and email id.
	 */
	private const EMAIL_STRING_FIELDS = [ 'subject', 'heading', 'additional_content' ];

	/**
	 * Lazily instantiated term translation manager.
	 *
	 * @var \PerfLocale\Translation\TermTranslationManager|null
	 */
	private ?\PerfLocale\Translation\TermTranslationManager $term_manager = null;

	/**
	 * How many Store API cart or checkout route callbacks are running (a batch
	 * nests them).
	 *
	 * @var int
	 */
	private int $store_api_cart_depth = 0;

	/**
	 * The Inventory Sync service, or null when the sync is off.
	 *
	 * @var \PerfLocale\WooCommerce\InventorySync|null
	 */
	private ?\PerfLocale\WooCommerce\InventorySync $inventory_sync = null;

	/**
	 * Current-language sibling data of each cart line, for the open Store API
	 * cart window only. Keyed by blog, language and product/variation id;
	 * emptied when the window closes, so it never outlives one cart response.
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
	 * Product type mirror_product_type() gave each translation created in this
	 * request, keyed by "<blog id>:<post id>". refresh_copy_lookup_row() reads
	 * and clears it in the same perflocale/translation/created action.
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

		// `product_variation` is registered show_ui = false, so WordPress has no
		// `_edit_link` for it and the Translations screen would render a dead
		// link. A variation has no standalone editor in WooCommerce either — it
		// is edited inside its parent product's Variations tab — so point there
		// rather than inventing a URL that does not exist.
		add_filter( 'perflocale/admin/edit_post_link', [ $this, 'edit_link' ], 10, 3 );

		// A variation is not publicly_queryable, so PerfLocale's viewability
		// gate drops its front-end link — but WooCommerce maps a variation
		// permalink onto the parent product with the attributes as a query
		// string (WC_Post_Data::variation_post_link), and that URL returns 200.
		// Restore it rather than losing a working preview link.
		add_filter( 'perflocale/admin/view_post_link', [ $this, 'view_link' ], 10, 2 );

		// Register product taxonomies as translatable.
		// Product attributes (pa_*) are discovered dynamically after WC registers them.
		add_filter( 'perflocale/translatable_taxonomies', [ $this, 'add_taxonomies' ], 20 );

		// Register product meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );

		// ---- URL slug translation ----

		// Translate cart item permalink to the current language.
		add_filter( 'woocommerce_cart_item_permalink', [ $this, 'translate_cart_item_permalink' ], 10, 3 );

		// The Store API cart item (block Cart, Mini-Cart, Checkout summary)
		// applies the permalink filter above but builds its name, short
		// description and images straight from the product object, so the label
		// never followed the link. Map those to the current-language sibling
		// while a Store API cart or checkout route runs, and only then.
		add_filter( 'rest_request_before_callbacks', [ $this, 'open_store_api_cart_window' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'close_store_api_cart_window' ], 10, 3 );
		// WooCommerce's block hydration (the first paint of the Cart, Checkout
		// and All Products blocks and the Mini-Cart) calls the route's
		// controller itself and never fires the two filters above.
		add_filter( 'woocommerce_hydration_dispatch_request', [ $this, 'dispatch_store_api_hydration' ], PHP_INT_MAX, 4 );

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

		// ⭐ A GROUPED product's children are the same class of stored-ID list
		// and were the one member of it not mapped. `_children` is copied
		// verbatim to the translation, so on /de/ a grouped product handed
		// WooCommerce the DEFAULT-language child ids — and those ids then went
		// through a language-scoped lookup that dropped them, leaving the
		// add-to-cart form rendered with no purchasable children at all.
		// Two independent faults stacked; mapping the ids fixes this one at
		// source, because the mapped sibling belongs to the current language
		// and survives the scoping.
		//
		// Hook name verified in host source, not assumed:
		// abstract-wc-data.php:917-919 `return 'woocommerce_' . $this->object_type . '_get_';`
		// and :939 `apply_filters( $this->get_hook_prefix() . $prop, $value, $this )`,
		// with WC_Product_Grouped::get_children() -> get_prop( 'children' )
		// (class-wc-product-grouped.php:147-149).
		add_filter( 'woocommerce_product_get_children', [ $this, 'map_related_ids_to_language' ] );

		// ---- Coupon restrictions across translation siblings ----

		// A coupon's product/category inclusions and exclusions must also
		// cover each listed item's translation siblings: the SAME physical
		// product carries a different post/term ID per language, and WC
		// compares raw IDs. Expand the lists at READ time on the frontend
		// only — the stored coupon and the admin edit screen keep the raw
		// saved IDs.
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

		// Order language - every order is tagged with the language it is
		// placed or paid in, whether or not its emails are translated.
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
			$sync = new \PerfLocale\WooCommerce\InventorySync( $plugin->get( 'cache' ) );
			$sync->register_hooks();
			$this->inventory_sync = $sync;

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
		// ⭐ Carry the SOURCE's product_type onto the translation — for every
		// product type, not just variable. `product_type` is an internal WC
		// taxonomy, so it is not in get_translatable_taxonomies() and
		// copy_taxonomy_terms() never touches it; a translation therefore
		// materialises with NO product_type term and WooCommerce falls back to
		// WC_Product_Simple. clone_product_variations() below already knew this
		// ("The product_type taxonomy term is not part of the meta copy…") but
		// patched it only after an `is_type( 'variable' )` guard, so GROUPED and
		// EXTERNAL products silently degraded: measured on a test store, a
		// translated grouped product came back as WC_Product_Simple with
		// get_children() === [] even though its `_children` meta was copied
		// intact — no child products, and the wrong add-to-cart form.
		//
		// Priority 15: after create_translation's own meta/term copy, and
		// BEFORE clone_product_variations() at 20, which loads the target with
		// wc_get_product() and needs the class to already be right.
		// ⭐ Re-scope the front page when WooCommerce turns it into a product
		// archive. PerfLocale decides at pre_get_posts priority 5 and correctly
		// exempts the query because it names an explicit `page_id` — at that
		// moment it IS a page request. WC_Query::pre_get_posts then runs at
		// priority 10 and, when the shop page is the site's front page and the
		// theme supports WooCommerce or FSE, rewrites the SAME query to
		// `post_type => product` (includes/class-wc-query.php:348-358).
		//
		// The decision was made before the rewrite, so nothing ever stamps the
		// query and the language WHERE is never added. Measured on a test store
		// (Storefront, shop set as front page):
		//   /        main query post_type="product"  SQL has language WHERE: NO
		//   /de/     main query post_type="product"  SQL has language WHERE: NO
		//   /shop/   (the same archive, NOT the front page)            WHERE: YES
		// i.e. every language's products on the home page of a multilingual
		// store, while the identical /shop/ archive is scoped correctly.
		//
		// The same rewrite turns a translated shop page (/fr/boutique/, resolved
		// as a page) into an unscoped product archive on any site with a static
		// front page.
		//
		// Priority 20 so it runs after WC's rewrite, and it only ever ADDS the
		// stamp to a query nobody stamped, so no existing exemption is undone.
		add_action( 'pre_get_posts', [ $this, 'rescope_front_page_shop' ], 20 );

		// ⭐ Give WooCommerce's cached block queries a per-language cache key.
		//
		// The eight product-grid blocks (Best Sellers, On Sale, New, Top Rated,
		// By Category, By Tag, By Attribute, Handpicked) all run through
		// `BlocksWpQuery::get_cached_posts()`, which caches results in a
		// transient for 30 DAYS under
		//   md5( wp_json_encode( $this->query_vars ) )
		// (src/Blocks/Utils/BlocksWpQuery.php:50-68). That hash is computed
		// BEFORE `get_posts()` runs, and `get_posts()` is what fires
		// `pre_get_posts` — so PerfLocale's language stamp is not in the hash,
		// and every language shares one cache entry. Whichever language warmed
		// it first serves its product ids to all the others for a month.
		//
		// The fix is the one WooCommerce itself documents in that class's
		// docblock: "you can still ensure there is a unique hash by injecting
		// custom query vars via the parse_query filter … Doing so won't have any
		// negative effect on the query itself, and it will cause the hash to
		// change." `parse_query` fires from the constructor's
		// parse_query_vars() (wp-includes/class-wp-query.php:567-569 ->
		// :1164), i.e. before the hash is taken.
		//
		// Scoped to BlocksWpQuery instances ON PURPOSE. Injecting a language
		// var into every WP_Query would also change WP core's own
		// `generate_cache_key()` (class-wp-query.php:5010), which already
		// includes the SQL — so scoped queries are separated per language
		// ALREADY, and the only effect on exempt queries would be to multiply
		// their cache entries by the language count for no benefit.
		add_action( 'parse_query', [ $this, 'separate_block_query_cache_by_language' ] );

		add_action( 'perflocale/translation/created', [ $this, 'mirror_product_type' ], 15, 4 );

		add_action( 'perflocale/translation/created', [ $this, 'clone_product_variations' ], 20, 4 );

		// Give a non-variable product translation its wc_product_meta_lookup
		// row (WooCommerce 10.8+). Priority 25: after mirror_product_type()
		// (15), whose recorded product type picks the data store, and the
		// variation clone (20).
		add_action( 'perflocale/translation/created', [ $this, 'refresh_copy_lookup_row' ], 25, 2 );

		// Machine translation of a product also translates its variations'
		// descriptions and its local (non-taxonomy) attribute options, which
		// the post translation itself does not carry.
		if ( $settings->mt_enabled() ) {
			add_action( 'perflocale/machine_translation/after', [ $this, 'translate_variation_texts' ], 10, 5 );
		}

		// Register WC non-gettext strings (attribute labels, email subjects/headings)
		// when the user runs "Scan for Strings" from the Strings page.
		add_action( 'perflocale/strings/after_scan', [ $this, 'sync_attribute_labels' ] );
		add_action( 'perflocale/strings/after_scan', [ $this, 'sync_email_strings' ] );

		// Re-register email strings when WC email settings are saved,
		// so changed subjects/headings are immediately available for translation.
		add_action( 'woocommerce_settings_saved', [ $this, 'sync_email_strings' ] );

		// Every other write of an email's settings - WC_Email::update_option(),
		// the WC REST settings API, the block email editor, update_option()
		// from code or WP-CLI - reaches the option without
		// woocommerce_settings_saved. Core fires these two for every caller.
		add_action( 'added_option', [ $this, 'register_email_strings_on_add' ], 10, 2 );
		add_action( 'updated_option', [ $this, 'register_email_strings_on_update' ], 10, 3 );

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

			// Variation names are translated where they are shown (the cart,
			// order and Store API filters here), never through
			// `woocommerce_product_variation_title`: WooCommerce's variation
			// data store writes that filter's result to wp_posts.post_title
			// whenever it differs from the stored title, so a translated title
			// there turns every read in another language into a database write.

			// Name order lines in the checkout language.
			add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'name_order_line_in_checkout_language' ], 10, 4 );

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

		// Hide attribute rows whose value, in any language, is already in the
		// order line name. Every context: admin screens and admin-sent emails
		// show the stored name too.
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ $this, 'hide_attribute_meta_in_item_name' ], 10, 2 );
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
	 * A write of one email's settings anywhere else is registered by
	 * register_changed_email_strings().
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
	 * Register the email strings of a WC email settings option that was
	 * created (added_option).
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Stored value.
	 * @return void
	 */
	public function register_email_strings_on_add( $option, $value = null ): void {
		$this->register_changed_email_strings( $option, null, $value );
	}

	/**
	 * Register the email strings of a WC email settings option that was
	 * updated (updated_option).
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
	 * Register the subject, heading and additional content a write of one
	 * `woocommerce_{id}_settings` option changed, so the old translation moves
	 * to the new text as Needs Update whatever wrote the option.
	 *
	 * Every option write on the site passes through here, so everything up to
	 * the mailer lookup is string and array work. Payment gateway and shipping
	 * settings share the option name pattern but carry none of the fields, and
	 * a write that leaves all three fields as they were (an email switched on
	 * or off) registers nothing. The texts come from the NEW value: a direct
	 * update_option() leaves the mailer's cached copy of the settings stale.
	 *
	 * Skipped while WordPress or WooCommerce installs, before init (loading the
	 * mailer loads every email class and its translations), and inside the WC
	 * Settings page save, whose woocommerce_settings_saved runs
	 * sync_email_strings() for every email once the save has finished.
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

		// Read through the email's own get_option() - defaults and the
		// woocommerce_email_get_option filter included - exactly as
		// EmailTranslation does at send time, on a copy holding the new value.
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
	 * Add WooCommerce post types to the translatable list.
	 *
	 * shop_order is excluded - orders are tagged with a language but not copied.
	 *
	 * @param array<int, string> $post_types Existing post types.
	 * @return array<int, string>
	 */
	/**
	 * Point a variation row at its parent product's editor.
	 *
	 * WooCommerce has no standalone screen for a variation: `post.php` on a
	 * `product_variation` renders an empty editor. The Variations metabox on the
	 * parent product is the only place one can be edited, so that is where the
	 * link goes. A variation whose parent has been deleted gets no link at all —
	 * better than one that opens a blank screen.
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

			// ⚠️ ONLY A PUBLISHED TRANSLATION MAY STAND IN.
			//
			// "A translation exists" is not "a translation is reachable", and
			// this returned the former while WooCommerce consumed it as the
			// latter. Generate Missing Translations creates every translation
			// as a DRAFT, so on a store where an operator ran it — which
			// 1.0.5's own Settings copy tells them to do — wc_get_page_id(
			// 'cart' ) on /de/ returned a draft page id. Measured on
			// a test store: 221 (live cart) became 1031357 (draft), so the
			// cart, checkout and my-account links in the whole German funnel
			// pointed at pages a visitor cannot open.
			//
			// The status read is a cache hit: prime_wc_page_translations()
			// batch-primes these rows in the same one-shot pass above.
			if ( $translated_id && get_post_status( $translated_id ) === 'publish' ) {
				return $translated_id;
			}

			return $page_id;
		} finally {
			unset( $resolving[ $page_id_int ] );
		}
	}

	/**
	 * Batch-prime translation caches for every known WooCommerce page.
	 *
	 * Called once per request, the first time filter_wc_page_id() fires.
	 *
	 * The page IDs come from read_stored_option_values(), never get_option(),
	 * which would re-enter our own filter. The sibling-cascade inside
	 * prime_translations() means priming the primary page IDs also seeds the
	 * cache for their language siblings, so downstream calls for translated
	 * pages are L1 hits too.
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

		// Prime the TRANSLATIONS' post rows as well, in one more query.
		// filter_wc_page_id() has to know each candidate's post_status — a
		// DRAFT translation must not replace a live store page — and without
		// this that is a separate get_post() per WC page on a cold cache, five
		// times per front-end request. The link lookups below are free: they
		// read the map just primed above.
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
			// Statuses only — no meta, no terms.
			_prime_post_caches( $translated_ids, false, false );
		}
	}

	/**
	 * Stored values of the given options, read without any option_* filter.
	 *
	 * Not get_option(): its option_{$name} filter chain runs
	 * filter_wc_page_id(), which would resolve each page unprimed before the
	 * batch prime exists and hand back TRANSLATED ids instead of the stored
	 * ones.
	 *
	 * The values come from the same caches get_option() reads — alloptions for
	 * autoloaded names, the per-key options cache for the rest, which
	 * wp_prime_option_caches() fills in one query or one multi-get. That also
	 * serves WooCommerce's own get_option() calls for these names later in the
	 * request, so they cost nothing more. Names in `notoptions` are left out
	 * before anything is fetched: a persistent cache stores no negative
	 * entries, so asking for a missing name would be a round trip on every
	 * request.
	 *
	 * A name whose value cannot be read back (a persistent-cache store that
	 * failed) is simply omitted. The prime is only a hint: filter_wc_page_id()
	 * resolves every page correctly without it.
	 *
	 * Two cases keep one direct SELECT instead. While installing, a multisite
	 * wp_load_alloptions() reads every option uncached. And behind a
	 * persistent object cache that is not known to answer a multi-get in one
	 * round trip (see options_multiget_is_batched()), priming could cost a
	 * round trip per name.
	 *
	 * @param string[] $option_names Option names.
	 * @return array<string, mixed> Option name => stored value, for the names found.
	 */
	private function read_stored_option_values( array $option_names ): array {
		$values = [];

		if ( wp_installing() || ! $this->options_multiget_is_batched() ) {
			global $wpdb;

			$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );

			// $placeholders is a runtime-built %s-list whose length matches
			// count($option_names); scanner can't see that statically.
			// $wpdb->options is core-owned.
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
	 * WordPress's own object cache lives in the request, and Redis Object
	 * Cache 2.x (the drop-in that exposes redis_instance() and declares
	 * get_multiple()) answers a multi-get with one MGET. No other persistent
	 * drop-in is assumed to batch.
	 * wp_cache_supports( 'get_multiple' ) only says the method exists, and some
	 * drop-ins implement it as one get() per key (LiteSpeed Cache's does). Nor
	 * is redis_instance() enough on its own: Hummingbird's Redis drop-in and
	 * Redis Object Cache before 2.0 have it but no get_multiple(), so core's
	 * wp_cache_get_multiple() fallback runs one get() per key. On those,
	 * priming the page-id options would cost a backend round trip per name,
	 * more than the single SELECT it replaces, on requests that never read
	 * those options again.
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
	 * WooCommerce keeps the cart, checkout, my-account and terms page IDs at
	 * `autoload = off`, so get_wc_page_ids() has to fetch them rather than find
	 * them in alloptions. Where prime_wc_page_translations() reads them through
	 * the options cache, the first filter_wc_page_id() call of the request has
	 * already put all four there, so that fetch adds no database query;
	 * otherwise it goes through the persistent object cache like any other
	 * wp_prime_option_caches() call. That first call has also primed every WC
	 * page's translation links, so the lookup below is an L1 cache hit.
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
		// Measured on a test store browsing /de/: drop this guard and
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

			// ⚠️ ...but only a PUBLISHED translation owns a URL.
			//
			// "A translation exists" and "a translation is reachable" are not
			// the same claim, and this guard was making the first one do the
			// second one's job. Generate Missing Translations creates every
			// translation as a DRAFT, so the moment an operator runs it — which
			// 1.0.5's own Settings copy tells them to do — a draft cart page
			// existed for German, this returned early, and get_permalink() of
			// the cart, checkout and my-account pages handed a German shopper
			// the UNPREFIXED English URL from the mini-cart and the terms link.
			// Measured on a test store: expected /de/cart-3/, got /cart-3/.
			//
			// A draft cannot be visited, so it cannot own the URL; fall through
			// and prefix the source exactly as before the draft existed.
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
	 * `shop` is deliberately NOT in this list. It is the store's one indexable
	 * WooCommerce page, and it is reachable as a link target while ANOTHER page
	 * is queried (the product archive queries products, not the shop page), so
	 * the guard in force_wc_page_language_prefix() cannot protect it: measured
	 * on a test store, including it moved /de/shop-3/'s canonical and
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

		// The cart, mini-cart and checkout render for GUESTS. A translation is
		// created as a DRAFT, so "linked but not public yet" is the normal state
		// of half-finished work, not an edge case — swapping its title in
		// publishes unreleased copy to anyone holding an item in their basket.
		// Keep the source label instead. Same rule the cross-sell mapper already
		// applies in map_related_ids_to_language().
		if ( ! $this->is_publicly_viewable_translation( (int) $translated_id ) ) {
			return 0;
		}

		// A group that links products of different types (a simple product
		// with a variable one) is not a translation of the purchased item:
		// its title and image would name a different product in the cart,
		// the order and the emails. Keep the purchased product's own.
		if ( $this->product_type( $product_id ) !== $this->product_type( (int) $translated_id ) ) {
			return 0;
		}

		return (int) $translated_id;
	}

	/**
	 * A product's WooCommerce type.
	 *
	 * WooCommerce's product data store answers from its own product cache;
	 * the store is loaded once per request rather than once per lookup. A site
	 * that overrides the lookup through `woocommerce_product_type_query` gets
	 * WooCommerce's factory answer.
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
	 * Add the order language to the fields WooCommerce's personal-data
	 * exporter lists for an order.
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
	 * Value of the order-language field in the personal-data export: the
	 * language's name, or its code when the language no longer exists.
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
	 * Runs on `rest_request_before_callbacks`, so it covers a real HTTP call,
	 * a REST preload (rest_preload_api_request) and every cart or checkout
	 * sub-request of a batch; WooCommerce's block hydration opens it through
	 * dispatch_store_api_hydration(). Nothing else does: catalogue routes,
	 * the order-pay route and every non-REST render never see the mappers.
	 * The checkout route embeds the cart in its response
	 * (`__experimentalCart`, and `cart` in a 409 error), which the block
	 * Checkout summary shows. Only the product objects the cart itself holds
	 * are mapped, never a cross-sell or any other product the response embeds.
	 * Price, quantity, keys, ids, stock, item data and the order line name
	 * (built from get_name(), which no mapper touches) stay those of the
	 * purchased product.
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
	 * Core fires `rest_request_after_callbacks` for every request that fired
	 * the opening filter, including a permission failure or an error response.
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
	 * Run a Store API cart or checkout route for WooCommerce's block
	 * hydration inside the cart window.
	 *
	 * The Hydration service builds the first paint of the Cart, Checkout and
	 * All Products blocks and the Mini-Cart by calling the route's handler
	 * directly, between `woocommerce_hydration_dispatch_request` and
	 * `woocommerce_hydration_request_after_callbacks`. This runs last on the
	 * first, only when no other callback has answered, and calls the handler
	 * as WooCommerce would. The window closes in `finally`, so a handler or a
	 * later filter that throws cannot leave the mappers attached for the rest
	 * of the page. Inventory Sync's frame for the request (see
	 * InventorySync::enter_store_api_request()) closes in the same `finally`.
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
			if ( null !== $this->inventory_sync ) {
				$this->inventory_sync->enter_store_api_request( $request, \PerfLocale\WooCommerce\InventorySync::is_display_cart_read( $request ) );
			}

			return call_user_func( $handler['callback'], $request );
		} finally {
			$this->inventory_sync?->leave_store_api_request( $request );
			$this->close_store_api_cart_window( null, $handler, $request );
		}
	}

	/**
	 * Whether a REST route opens the Store API cart window: the cart routes
	 * (`/cart` and everything under it) and the checkout route (`/checkout`
	 * itself, not the order-pay route under it), versioned or not.
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	private static function is_store_api_cart_window_route( string $route ): bool {
		return str_starts_with( $route, '/wc/store/' )
			&& 1 === preg_match( '#^/wc/store(?:/v[0-9]+)?/(?:cart(?:/|$)|checkout/?$)#', $route );
	}

	/**
	 * Store API cart line name: the published sibling's title (a variation
	 * carries its parent's title here, so it maps through the parent).
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
	 * Store API cart line short description: the published sibling's, even
	 * when that one is empty, since the line links to the sibling's page.
	 * A sibling that still needs its password for this visitor keeps the
	 * purchased product's own. Variations carry no short description of
	 * their own and are left alone.
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
	 * Store API cart line image: the published sibling's own image. A sibling
	 * without one keeps the purchased product's image.
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
	 * Store API cart line gallery: follows the image, so a line never mixes
	 * the sibling's main image with the source's gallery.
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
	 * Identity, not ID: only the exact objects in the cart's lines qualify, so
	 * a cross-sell or any other product built from the same ID is left alone.
	 * Cost, once per window: one pass that primes every line's sibling post
	 * and meta (no query when they are cached, at most two when not), then
	 * cached reads per line.
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

		// One pass over the whole cart the first time: the posts and meta of
		// every line's sibling in two queries instead of one meta read per line.
		// The translation lookups are the ones the permalink filter makes anyway.
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
				// A variation with an image of its own keeps it: translated
				// variations copy it and are not linked one-to-one. One that
				// falls back to its parent's image follows the sibling parent.
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
				// A sibling behind a password keeps the purchased product's
				// short description until the visitor has entered that
				// password, as WooCommerce's own product responses do.
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

		// The parent's stored title, as the order line WooCommerce stored and
		// the cart line are: no "Protected:" prefix or other `the_title` output.
		$translated_name = $this->build_translated_variation_name( $variation, true );

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
	 * @param \WC_Product_Variation $variation    Variation product.
	 * @param bool                  $stored_title Use the parent's stored title, as
	 *                                            WooCommerce's own cart line name
	 *                                            does, instead of get_the_title().
	 * @return string|null Translated name or null.
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
	 * WooCommerce names the line with the variation's get_name(), which is its
	 * stored title. A shopper who checks out in another language gets the
	 * name the cart showed: the parent's stored title with the attribute
	 * values in that language. Nothing changes when the checkout language is
	 * the default or when no attribute value has a translation in it. Lines
	 * of other product types keep WooCommerce's own name.
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
	 * WooCommerce hides a variation attribute row when the term's name is in
	 * the line name, and it compares the term's own name only. A line named in
	 * the checkout language ("Blanket - Blue") kept the "Color: Bleu" row,
	 * which the order views then show translated under a name that already
	 * says Blue. The row is hidden when the name of the term or of any of its
	 * translations is in the line name.
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
				<p class="description"><?php echo esc_html__( 'Keeps stock levels, SKU, GTIN, price, weight, and dimensions identical across all translations of the same product whenever WooCommerce saves it, whether the change comes from an order, the product editor, Quick Edit, the REST API, an import, or another plugin. Prevents over-selling when the same physical product exists in multiple languages.', 'perflocale' ); ?></p>
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
						<?php
						// A stored id that no provider registers right now keeps its own
						// option, so saving the tab unchanged does not switch providers.
						if ( '' !== $rate_provider && ! isset( $providers[ $rate_provider ] ) ) :
							?>
							<option value="<?php echo esc_attr( $rate_provider ); ?>" selected="selected">
								<?php
								/* translators: %s: provider id stored in the settings. */
								echo esc_html( sprintf( __( '%s (not registered)', 'perflocale' ), $rate_provider ) );
								?>
							</option>
						<?php endif; ?>
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
				// Not a finite number above zero: refused, the stored rate of
				// the row stays (rate_to_store()).
				'exchange_rate' => \PerfLocale\WooCommerce\MultiCurrency::rate_to_store( $data['exchange_rate'] ?? 1.0, $existing[ $slug ] ?? null, $code ),
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
	 * Add the current language to a WooCommerce block query's cache key.
	 *
	 * Injects a query var that nothing reads, purely so
	 * `BlocksWpQuery::get_cached_posts()`'s md5 of `query_vars` differs per
	 * language. Deliberately NOT `perflocale_language_id`: that var drives
	 * `modify_query_clauses()`, and setting it here — before
	 * `filter_by_language()` has had its say at `pre_get_posts` — would scope
	 * queries that are meant to be exempt. See the rationale in boot().
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
	 * Only touches a MAIN query that WC has rewritten to a product archive:
	 * the front page of a site whose front page IS the shop page, or a
	 * request for the (translated) shop page on a site with a static front
	 * page. Only when nothing has stamped a language on it yet. See the
	 * rationale in boot().
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public function rescope_front_page_shop( $query ): void {
		if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || is_admin() ) {
			return;
		}

		// Already decided by someone — never override an existing stamp.
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

		// WooCommerce makes the same rewrite for any page request whose page
		// is the shop page while the site shows a static front page
		// (includes/class-wc-query.php:377-380), and leaves it marked both a
		// page and a product archive with page_id emptied. A translated shop
		// page (/fr/boutique/) is such a request: it resolves as a page, which
		// PerfLocale exempts for naming a page_id.
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
	 * `product_type` is one of WooCommerce's internal taxonomies, so it is not
	 * in `get_translatable_taxonomies()` and `copy_taxonomy_terms()` never
	 * copies it. A translation is therefore created with no product_type term
	 * at all, and `wc_get_product()` resolves it to `WC_Product_Simple`.
	 *
	 * `clone_product_variations()` sets the term only behind an
	 * `is_type( 'variable' )` guard, so GROUPED and EXTERNAL products rely on
	 * this method. Without the term a translated grouped product loads as
	 * `WC_Product_Simple` with `get_children()` empty, although its
	 * `_children` meta is copied intact, and the visitor gets a simple
	 * product's add-to-cart form and none of the children.
	 *
	 * The same gap covers the other WooCommerce taxonomies a product's props
	 * live in: visibility, shipping class and brand. mirror_catalog_terms()
	 * gives the translation those terms here as well, so the variable
	 * product that clone_product_variations() loads next carries them too.
	 *
	 * Runs at priority 15, before clone_product_variations() at 20, which calls
	 * `wc_get_product( $new_id )` and needs the class already resolved.
	 *
	 * @param int    $new_id      Newly created translation post ID.
	 * @param string $object_type Object type ('post' for post translations).
	 * @param string $target_slug Target language slug (unused: product_type is
	 *                            machinery and is never translated).
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

		// A product with no product_type term IS a simple product as far as
		// WooCommerce is concerned, and so is a translation without one: the
		// type is mirrored only when the source has it.
		if ( ! is_wp_error( $types ) && $types !== [] ) {
			// Not translated, deliberately: product_type is machinery
			// ('simple' / 'grouped' / 'variable' / 'external'), never shown to a
			// visitor. Translating these slugs would make wc_get_product() fail
			// to resolve a class at all.
			wp_set_object_terms( $new_id, array_map( 'strval', $types ), 'product_type' );
			$this->created_product_types[ get_current_blog_id() . ':' . $new_id ] = (string) reset( $types );
		}

		$this->mirror_catalog_terms( $new_id, $source_id );

		// WooCommerce caches each product's type the first time it loads the
		// product. A translation created as published has been loaded already
		// (publish-time hooks do that) while it had no type term, so the cache
		// says 'simple', and clone_product_variations() and every later load in
		// the request would get a simple product. Changing the term drops it.
		if ( class_exists( \WC_Cache_Helper::class ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'product_' . $new_id );
		}
	}

	/**
	 * Give a freshly-created product translation the source's visibility,
	 * shipping class and brand terms.
	 *
	 * `product_visibility`, `product_shipping_class` and `product_brand` are
	 * WooCommerce taxonomies outside `get_translatable_taxonomies()` (unless
	 * the site added one), so `copy_taxonomy_terms()` never copies them, and
	 * WooCommerce reads the props from these terms alone. Without them the
	 * translation of a hidden or search-only product is listed in the
	 * catalog, the translation of a featured product is not featured, and the
	 * translation has no shipping class (so it is charged at the no-class
	 * rate) and no brand.
	 *
	 * `product_visibility` holds two kinds of term, both written by
	 * WC_Product_Data_Store_CPT::update_visibility():
	 *   - `featured`, `exclude-from-catalog` and `exclude-from-search` are the
	 *     operator's settings, and are copied from the source;
	 *   - `outofstock` and `rated-N` follow the product's own stock status and
	 *     average rating. They are derived from the TRANSLATION's
	 *     `_stock_status` and `_wc_average_rating` the way update_visibility()
	 *     derives them, never copied from the source.
	 *
	 * Shipping class and brand are shared: the translation gets the source's
	 * own terms. A taxonomy the site made translatable is left alone, because
	 * copy_taxonomy_terms() has already given the translation that language's
	 * terms. `product_brand` exists from WooCommerce 9.6, and a taxonomy that
	 * is not registered is skipped.
	 *
	 * The terms are written directly, as product_type is: a CRUD set_*() and
	 * save() would fire woocommerce_update_product and every other save
	 * listener in the middle of the create. Running it again writes the same
	 * terms.
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

	/**
	 * Build the wc_product_meta_lookup row of a freshly-created, non-variable
	 * product translation.
	 *
	 * A translation is inserted with wp_insert_post() and its meta copied with
	 * add_post_meta() (PostTranslationManager::finish_new_translation()), so
	 * WooCommerce's CRUD create, which is what writes a product's lookup row,
	 * never runs for it. The lookup table backs the catalog's price sort and
	 * price filter, the stock filter and WooCommerce's product search by SKU:
	 * a translation without a row sorts ahead of every priced product with a
	 * NULL price, and a search by its SKU does not find it. WooCommerce
	 * writes the row on a later save only when a lookup prop changes, so a
	 * translator who publishes the translation with its price, SKU and stock
	 * untouched leaves it without one.
	 *
	 * Variable products are left to clone_product_variations(), which saves
	 * the translation through CRUD and rebuilds its row with
	 * WC_Product_Variable::sync(). Grouped, external and simple products, and
	 * custom types that are not 'variable', are built here.
	 *
	 * WooCommerce 10.8+ only: refresh_product_lookup_table() reads the row
	 * from the translation's meta and writes it with one REPLACE. The product
	 * object is not loaded for it: the row is built from meta alone, and loading
	 * the product would also read every term of the translation and, outside
	 * wp-admin, rewrite WooCommerce's term-count cache. WC_Data_Store::load()
	 * with 'product-<type>' resolves the same data store the product object
	 * uses (a registered custom type's own store, else the product store), and
	 * the type is the one mirror_product_type() just wrote. Older
	 * versions have no public single-product rebuild, and the translation is
	 * left as it is there; WooCommerce → Status → Tools → Product lookup
	 * tables builds the missing rows. The row is never written by hand, and
	 * wc_update_product_stock() is not used: it fires
	 * woocommerce_product_set_stock, whose sibling mirror would write the
	 * translation's just-copied quantity to the whole group and could
	 * overwrite a concurrent sale.
	 *
	 * With a row, the translation holds its SKU and GTIN in WooCommerce's
	 * uniqueness checks. allow_translation_duplicate_sku() and
	 * allow_translation_duplicate_global_unique_id() exempt translation
	 * siblings, so the source and the translation both keep saving.
	 *
	 * Runs at priority 25, after mirror_product_type() at 15 and
	 * clone_product_variations() at 20. A failure is contained here: the
	 * translation and its link stay, and the row is left to WooCommerce's
	 * regenerate tool or the next lookup-prop save.
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

		if ( version_compare( (string) WC()->version, '10.8.0', '<' ) ) {
			return;
		}

		try {
			$type = $type ?? \WC_Product_Factory::get_product_type( $new_id );

			if ( ! is_string( $type ) || $type === '' || 'variable' === $type ) {
				return;
			}

			\WC_Data_Store::load( 'product-' . sanitize_key( $type ) )->refresh_product_lookup_table( $new_id );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PerfLocale WC: lookup row rebuild failed for translation ' . $new_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Machine-translate a translated product's variation descriptions and its
	 * local (non-taxonomy) attribute options.
	 *
	 * Runs after the post translation, whether or not meta translation was
	 * asked for. A value is translated only while the translation still holds
	 * the source's copy (a translator's own text is kept), and never when its
	 * key is a Sync Field or was emptied on the translation. Both batches go
	 * through TranslationService, so the monthly character limit applies.
	 *
	 * Translated local options are written to the parent and, in lock-step, to
	 * each variation's value, and the parent records translated => original
	 * (InventorySync::LOCAL_ATTR_SOURCE_META) so stock and price sync keep
	 * pairing the variations across languages. An attribute whose translated
	 * options would be empty, repeat one another, or whose variations hold a
	 * value that is not one of its options keeps its source options.
	 *
	 * Failures never fail the post translation: they are recorded on the
	 * translation's meta breadcrumb and reported through
	 * perflocale/mt/meta_translate_failed.
	 *
	 * @param int|mixed   $source_id   Source post ID.
	 * @param string      $provider_id Provider that translated the post.
	 * @param array|mixed $result      translate_post() result (post_id = the translation).
	 * @param string      $target_slug Target language slug.
	 * @param string      $source_slug Source language slug.
	 * @return void
	 */
	public function translate_variation_texts( $source_id = 0, $provider_id = '', $result = [], $target_slug = '', $source_slug = '' ): void {
		$source_id = (int) $source_id;
		$target_id = is_array( $result ) ? (int) ( $result['post_id'] ?? 0 ) : 0;

		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id || '' === (string) $target_slug || '' === (string) $source_slug ) {
			return;
		}

		if ( 'product' !== get_post_type( $source_id ) || ! function_exists( 'wc_get_product' ) ) {
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
	 * Pair each source variation with the translation's variation of the same
	 * attributes (taxonomy values mapped to the target language's terms,
	 * local values read through the translation's translated => original map).
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
	 * The paired variations whose translation still holds the source's
	 * description, and whose description was not emptied there.
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
	 * The local attributes whose options the translation still holds as the
	 * source's copy.
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

			// The provider wait can be long: a description someone changed
			// meanwhile is theirs.
			clean_post_cache( $vid );
			$variation = wc_get_product( $vid );

			if ( ! $variation instanceof \WC_Product_Variation || (string) $variation->get_description( 'edit' ) !== $descriptions[ $vid ] ) {
				continue;
			}

			$variation->set_description( $restored );
			$variation->save();
		}

		return $errors;
	}

	/**
	 * Translate a product's local attribute options and write them to the
	 * parent and, in lock-step, to its variations' values.
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
