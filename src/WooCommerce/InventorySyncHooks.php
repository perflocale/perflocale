<?php
/**
 * Inventory sync's hooks, attached without loading InventorySync.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\TranslationGroupRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attaches InventorySync's callbacks as static forwarders and builds the one
 * InventorySync instance the first time a callback has work for it. A request
 * that fires none of them (a page view that writes nothing and runs no cart,
 * checkout or stock change) neither builds nor loads InventorySync.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class InventorySyncHooks {

	/**
	 * Post meta on a translated variable product whose LOCAL (non-taxonomy)
	 * attribute options were machine-translated: attribute key => [ translated
	 * option => original option ]. Variation matching across languages reads a
	 * translated local value as the original, so stock, price and field sync
	 * keep pairing the variations after their options are translated.
	 */
	public const LOCAL_ATTR_SOURCE_META = '_perflocale_local_attr_source';

	/**
	 * Whether register() attached the hooks.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Cache manager the instance is built with; set by register().
	 *
	 * @var CacheManager
	 */
	private static CacheManager $cache;

	/**
	 * TranslationGroupRepository::link_changes() when the hooks were attached,
	 * the instance's starting mark (InventorySync::renew_copy_map_after_term_flush()).
	 *
	 * @var int
	 */
	private static int $link_mark = 0;

	/**
	 * Store API frames opened before the instance exists, in the form of
	 * InventorySync's own (InventorySync::enter_store_api_request()); handed to
	 * the instance when it is built.
	 *
	 * @var array<int, array{0: \WeakReference<\WP_REST_Request>, 1: bool, 2: int, 3: bool}>
	 */
	private static array $frames = [];

	/**
	 * Stock id of a catalog quantity limit being computed while the instance
	 * does not exist yet, or 0 (mark_catalog_limits()); handed to the instance
	 * when it is built.
	 *
	 * @var int
	 */
	private static int $catalog_stock_id = 0;

	/**
	 * Whether a catalog quantity limit is being computed while the instance
	 * does not exist yet (mark_catalog_limits()); handed to the instance when
	 * it is built.
	 *
	 * @var bool
	 */
	private static bool $catalog_limits = false;

	/**
	 * The instance, once a callback needed it.
	 *
	 * @var InventorySync|null
	 */
	private static ?InventorySync $sync = null;

	/**
	 * The order stock change WooCommerce is running (wc_reduce_stock_levels()
	 * or wc_increase_stock_levels(), between its `woocommerce_can_*_order_stock`
	 * filter and its closing action): a number of its own per order, 0
	 * outside one (order_stock_change()).
	 *
	 * @var int
	 */
	private static int $order_frame = 0;

	/**
	 * How many order stock changes this process opened (the last frame number).
	 *
	 * @var int
	 */
	private static int $order_frames = 0;

	/**
	 * Id of the order whose stock change is open (0 when none, or when the
	 * filter was given no order).
	 *
	 * @var int
	 */
	private static int $order_id = 0;

	/**
	 * Line items of the open order whose stock change WooCommerce finished
	 * (its per-item action, note_order_item_stock()).
	 *
	 * @var int
	 */
	private static int $order_step = 0;

	/**
	 * Attach the hooks. A registration starts from no instance and no pending
	 * state, as the add-on's boot built a new InventorySync before.
	 *
	 * @param CacheManager $cache Cache manager.
	 * @return void
	 */
	public static function register( CacheManager $cache ): void {
		self::$registered       = true;
		self::$cache            = $cache;
		self::$link_mark        = TranslationGroupRepository::link_changes();
		self::$sync             = null;
		self::$frames           = [];
		self::$catalog_stock_id = 0;
		self::$catalog_limits   = false;
		self::$order_frame      = 0;
		self::$order_id         = 0;
		self::$order_step       = 0;

		// Observe WooCommerce's own stock statement so the sibling mirror can
		// replay a RELATIVE change instead of an absolute snapshot - two
		// concurrent sales of two language siblings used to lose a decrement.
		// Priority 1: read WooCommerce's statement before another plugin can
		// rewrite it. The callback returns the query untouched.
		add_filter( 'woocommerce_update_product_stock_query', [ self::class, 'capture_stock_operation' ], 1, 4 );

		// wc_update_product_stock() announces its statement with these; a
		// direct data-store call does not, and is not captured. Last, so the
		// note is taken right before WooCommerce's own statement runs.
		add_action( 'woocommerce_product_before_set_stock', [ self::class, 'expect_stock_operation' ], PHP_INT_MAX, 1 );
		add_action( 'woocommerce_variation_before_set_stock', [ self::class, 'expect_stock_operation' ], PHP_INT_MAX, 1 );

		// Sync after WooCommerce saves all product meta.
		add_action( 'woocommerce_process_product_meta', [ self::class, 'sync_product_fields' ], 100, 1 );

		// Sync when saved via WooCommerce REST API (programmatic / bulk edits).
		add_action( 'woocommerce_rest_insert_product_object', [ self::class, 'sync_on_rest_save' ], 10, 1 );

		// A variation created or saved changes its parent's children or their
		// attributes, so that parent's variation map is built again when next
		// needed (an import or a batch that creates the copies' variations in
		// one process).
		add_action( 'woocommerce_new_product_variation', [ self::class, 'forget_variation_map' ], 10, 2 );
		add_action( 'woocommerce_update_product_variation', [ self::class, 'forget_variation_map' ], 10, 2 );

		// The stock groups (InventorySync::stock_group()) and the variation matches are
		// built from which variations a copy parent has, their attributes
		// and status, and which copies manage stock or opt out. A change of
		// any of those in this process drops them for the blog; a stock-only
		// save keeps them. WooCommerce's saves, direct meta writes, status
		// changes, deletions and product type changes are each seen. The
		// epoch renewals they mark are completed at shutdown.
		add_action( 'woocommerce_before_product_object_save', [ self::class, 'note_structural_save' ], PHP_INT_MAX, 1 );
		add_action( 'woocommerce_after_product_object_save', [ self::class, 'forget_after_structural_save' ], 10, 1 );
		add_action( 'woocommerce_product_type_changed', [ self::class, 'forget_stock_matches' ], 10, 0 );
		add_action( 'added_post_meta', [ self::class, 'forget_after_meta_change' ], 10, 3 );
		add_action( 'updated_post_meta', [ self::class, 'forget_after_meta_change' ], 10, 3 );
		add_action( 'deleted_post_meta', [ self::class, 'forget_after_meta_change' ], 10, 3 );
		add_action( 'transition_post_status', [ self::class, 'forget_after_status_change' ], 10, 3 );
		add_action( 'deleted_post', [ self::class, 'forget_after_post_deleted' ], 10, 2 );
		add_action( 'shutdown', [ self::class, 'renew_pending_epochs' ], PHP_INT_MAX, 0 );

		// The stored copy maps (InventorySync::stored_variation_copies()) check every
		// product fact by value; which attribute term reads as which
		// translation group they take from the copy-map epoch, which these
		// renew. In every request context: a cron event run inside a
		// visitor's request can link or edit a term too. Each callback
		// returns at once for any other taxonomy or object type.
		add_action( 'perflocale/cache/flush_object', [ self::class, 'renew_copy_map_after_term_flush' ], 10, 2 );
		add_action( 'perflocale/cache/flush_translations', [ self::class, 'renew_copy_map_epoch' ], 10, 0 );
		add_action( 'edit_terms', [ self::class, 'note_term_slug' ], 10, 2 );
		add_action( 'edited_term', [ self::class, 'renew_copy_map_after_term_edit' ], 10, 3 );
		add_action( 'delete_term', [ self::class, 'renew_copy_map_after_term_delete' ], 5, 3 );
		add_action( 'woocommerce_attribute_updated', [ self::class, 'renew_copy_map_epoch' ], 10, 0 );
		add_action( 'woocommerce_attribute_deleted', [ self::class, 'renew_copy_map_epoch' ], 10, 0 );
		add_action( 'set_object_terms', [ self::class, 'forget_after_product_type_terms' ], 10, 6 );

		// Which data stores read variations is a per-blog answer.
		add_action( 'switch_blog', [ self::class, 'forget_store_verdicts' ], 10, 0 );

		// Sync stock when WooCommerce changes it via orders (purchase, cancel, refund).
		// These fire from wc_update_product_stock(), not from the admin product editor.
		// Without these, buying from the EN store reduces EN stock but DE/FR keep old values.
		add_action( 'woocommerce_product_set_stock', [ self::class, 'sync_on_stock_change' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock', [ self::class, 'sync_on_stock_change' ], 10, 1 );

		// The copies are one stock before payment too. WooCommerce counts a
		// pending order's stock hold only against the id it holds, which
		// would leave every other language free to sell the same unit. Its
		// one hold-sum query feeds the cart checks, the Store API, the
		// checkout's own hold and pay-for-order. A Store API read of the cart
		// is the exception (see is_display_cart_read() and
		// InventorySync::in_display_cart_read()).
		add_filter( 'woocommerce_query_for_reserved_stock', [ self::class, 'group_reserved_stock_query' ], 10, 3 );

		// A Store API read of the cart shows figures and holds nothing, so it
		// gets WooCommerce's own: every REST GET or HEAD of the cart, from any
		// page (the Mini-Cart's refresh, and the cart and checkout pages' own
		// refreshes after their first render), and the server-side hydration
		// of the cart on pages other than the cart and checkout pages (the
		// Mini-Cart and the product blocks' cart state). The first render of
		// the cart and checkout pages, every cart change, the checkout's hold
		// and pay-for-order count every copy. Every Store API request is
		// framed, so a write or a checkout nested in a read still counts every
		// copy.
		add_filter( 'rest_request_before_callbacks', [ self::class, 'open_store_api_frame' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'close_store_api_frame' ], 10, 3 );

		// Once a stock hold for an order starts, every later hold-sum query of
		// the request counts every copy.
		add_filter( 'woocommerce_order_hold_stock_minutes', [ self::class, 'note_stock_hold' ], PHP_INT_MIN, 1 );

		// An order's stock reduction or restore changes the stock of each of
		// its line items in turn; the copy-map epochs those changes check are
		// read once for the order (InventorySync::current_epochs()). Opened
		// last on WooCommerce's go-ahead filter, advanced by its per-item
		// action, closed first on its closing action; nothing is built here.
		add_filter( 'woocommerce_can_reduce_order_stock', [ self::class, 'open_order_stock_change' ], PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_can_restore_order_stock', [ self::class, 'open_order_stock_change' ], PHP_INT_MAX, 2 );
		add_action( 'woocommerce_reduce_order_item_stock', [ self::class, 'note_order_item_stock' ], PHP_INT_MIN, 3 );
		add_action( 'woocommerce_restore_order_item_stock', [ self::class, 'note_order_item_stock' ], PHP_INT_MIN, 4 );
		add_action( 'woocommerce_reduce_order_stock', [ self::class, 'close_order_stock_change' ], PHP_INT_MIN, 0 );
		add_action( 'woocommerce_restore_order_stock', [ self::class, 'close_order_stock_change' ], PHP_INT_MIN, 0 );

		// The Store API asks the same query for the quantity limit of every
		// stock-managed product in its product data (product blocks, the
		// products route), where no money is at stake. There it stays
		// WooCommerce's own unless a shop opts in; a cart line's limit still
		// counts every copy. The minimum is filtered right before the limit
		// is computed and the maximum right after.
		add_filter( 'woocommerce_store_api_product_quantity_minimum', [ self::class, 'mark_catalog_limits' ], PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', [ self::class, 'unmark_catalog_limits' ], PHP_INT_MIN, 1 );

		// WooCommerce adds up a cart's quantities per stock id, which would
		// pass a cart holding two language copies of an item with one unit
		// left. Classic cart and checkout, the Store API cart and checkout,
		// and adding to the cart; each refuses with WooCommerce's own notice.
		add_filter( 'woocommerce_cart_item_required_stock_is_not_enough', [ self::class, 'cart_item_group_stock_short' ], 10, 3 );
		add_action( 'woocommerce_store_api_validate_cart_item', [ self::class, 'validate_store_api_cart_item' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ self::class, 'validate_add_to_cart_group_stock' ], 10, 4 );

		// A sold-individually product is one unit per order, and its language
		// copies are one product. WooCommerce looks for the same cart id only,
		// so the other language's copy went into the same cart. Adding it on
		// the classic form, the Store API cart and checkout, and the classic
		// cart and checkout now refuse or correct that with WooCommerce's own
		// notice. WooCommerce fires the first filter for a sold-individually
		// product only; the other two read one product flag per line.
		add_filter( 'woocommerce_add_to_cart_sold_individually_found_in_cart', [ self::class, 'sold_individually_found_in_cart' ], 10, 3 );
		add_action( 'woocommerce_store_api_validate_cart_item', [ self::class, 'validate_store_api_sold_individually' ], 10, 2 );
		add_action( 'woocommerce_check_cart_items', [ self::class, 'merge_sold_individually_copies' ], 0, 0 );

		// Admin variations panel + REST variation saves: sync the edited
		// variation's shared fields (price / stock flags / dimensions / SKU)
		// to the attribute-matched variation on each sibling-language parent,
		// then roll the sibling parents' derived aggregates (price range,
		// stock status) up via WC_Product_Variable::sync(). Priority 20 so WC
		// has persisted the variation's own meta for this save first. The
		// order-driven stock path above stays separate - it fires from
		// wc_update_product_stock(), not from these save flows.
		add_action( 'woocommerce_save_product_variation', [ self::class, 'sync_variation_fields' ], 20, 1 );
		add_action( 'woocommerce_rest_insert_product_variation_object', [ self::class, 'sync_on_rest_variation_save' ], 10, 1 );

		// Variations-tab BULK actions (set/adjust prices, sale dates, toggles,
		// dimensions) run plain CRUD saves and never fire
		// woocommerce_save_product_variation - only this dedicated hook. One
		// handler per bulk action; the sibling-parent rollup is amortised to
		// once per bulk run instead of once per variation.
		add_action( 'woocommerce_bulk_edit_variations', [ self::class, 'sync_after_bulk_variation_edit' ], 20, 4 );

		// Products-list Quick Edit and Bulk Edit save via CRUD and never fire
		// woocommerce_process_product_meta - without these hooks a price /
		// SKU / dimension change made there silently diverges across
		// languages (stock quantity alone propagated, via the set_stock
		// hooks). Both fire after the CRUD save has persisted the meta.
		add_action( 'woocommerce_product_quick_edit_save', [ self::class, 'sync_on_quick_or_bulk_edit' ], 10, 1 );
		add_action( 'woocommerce_product_bulk_edit_save', [ self::class, 'sync_on_quick_or_bulk_edit' ], 10, 1 );

		// "In stock?" on a variable product that does not manage stock is
		// saved on each child variation before the parent action above
		// fires. WooCommerce fires this only when a variation's status really
		// changed; the callback records the variation for the parent handler
		// and returns at once outside Quick/Bulk Edit.
		add_action( 'woocommerce_variation_set_stock_status', [ self::class, 'note_quick_edit_child' ], 10, 3 );

		// CSV importer rows (products AND variations) - an imported price
		// list otherwise updates only the imported language's products.
		add_action( 'woocommerce_product_import_inserted_product_object', [ self::class, 'sync_on_import' ], 10, 2 );

		// Any other update of an existing product or variation through
		// WooCommerce (custom code, another plugin). The data store reports
		// which props really changed while it saves; the handler records the
		// matching shared keys there and copies them once the save has
		// finished. A create fires woocommerce_new_product[_variation]
		// instead, which drops the record. Every save on the order path
		// leaves InventorySync::note_crud_update() before any I/O.
		add_action( 'woocommerce_product_object_updated_props', [ self::class, 'note_crud_update' ], 10, 2 );
		add_action( 'woocommerce_update_product', [ self::class, 'sync_after_crud_update' ], 10, 1 );
		add_action( 'woocommerce_update_product_variation', [ self::class, 'sync_after_crud_update' ], 10, 1 );
		add_action( 'woocommerce_new_product', [ self::class, 'forget_crud_update' ], 10, 1 );
		add_action( 'woocommerce_new_product_variation', [ self::class, 'forget_crud_update' ], 10, 1 );

		// A save recorded inside a flow with its own sync (OWN_SYNC_FLOWS)
		// waits until that flow's own handlers above have run. Last on each
		// flow's closing action. The two ajax flows end in wp_die(), so theirs
		// is the action WooCommerce fires after its save loop.
		add_action( 'woocommerce_process_product_meta', [ self::class, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'woocommerce_product_bulk_and_quick_edit', [ self::class, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'woocommerce_ajax_save_product_variations', [ self::class, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'woocommerce_bulk_edit_variations', [ self::class, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'perflocale/translation/created', [ self::class, 'finish_translation_flow' ], PHP_INT_MAX, 2 );

		// The REST API and the CSV importer hand the object they are about to
		// save through these filters and sync it themselves right after (the
		// handlers above). The claim must run last, so the object claimed is
		// the one WooCommerce saves whatever another filter returns.
		add_filter( 'woocommerce_rest_pre_insert_product_object', [ self::class, 'claim_for_flow' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', [ self::class, 'claim_for_flow' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_product_import_pre_insert_product_object', [ self::class, 'claim_for_flow' ], PHP_INT_MAX, 1 );

		// NB: the shared SKUs this sync writes stay saveable because the WC
		// addon exempts translation siblings from WC's unique-SKU (and GTIN)
		// validation - PerfLocaleWooCommerce::allow_translation_duplicate_sku.
	}

	/**
	 * The instance, built on first use; null while the hooks are not attached
	 * (inventory sync off). Calling it builds InventorySync, so code that only
	 * needs to know whether inventory sync is on must not call it.
	 *
	 * @return InventorySync|null
	 */
	public static function instance(): ?InventorySync {
		return self::$registered ? self::sync() : null;
	}

	/**
	 * The instance if it has been built, read afresh (a callback can build it
	 * while a filter runs).
	 *
	 * @return InventorySync|null
	 */
	private static function built(): ?InventorySync {
		return self::$sync;
	}

	/**
	 * The instance, built on first use.
	 *
	 * @return InventorySync
	 */
	private static function sync(): InventorySync {
		if ( null === self::$sync ) {
			self::$sync             = new InventorySync( self::$cache, self::$link_mark, self::$frames, self::$catalog_stock_id, self::$catalog_limits );
			self::$frames           = [];
			self::$catalog_stock_id = 0;
			self::$catalog_limits   = false;
		}

		return self::$sync;
	}

	/**
	 * Whether a Store API request is a display read of the cart: GET or HEAD
	 * on the cart route or a route under it, versioned or not, while
	 * WooCommerce's is_cart() and is_checkout() are both false. That is every
	 * REST read of the cart, whichever page sent it (a REST request renders
	 * no page), and the server-side hydration of the cart route on any page
	 * but the cart and checkout pages. The method is the effective one, so a
	 * POST sent with a method override is a write.
	 *
	 * @param \WP_REST_Request $request Store API request.
	 * @return bool
	 */
	public static function is_display_cart_read( \WP_REST_Request $request ): bool {
		return in_array( $request->get_method(), [ 'GET', 'HEAD' ], true )
			&& 1 === preg_match( '#^/wc/store(?:/v[0-9]+)?/cart(?:/|$)#', $request->get_route() )
			&& function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) && ! is_cart() && ! is_checkout();
	}

	/**
	 * Note that a Store API request starts running: in the instance once it
	 * exists, else in the frames handed to it when it is built. Nothing while
	 * the hooks are not attached (inventory sync off).
	 *
	 * @param \WP_REST_Request $request      The request.
	 * @param bool             $display_read Whether it is a display cart read (is_display_cart_read()).
	 * @param bool             $rest         Whether core's REST server dispatches it (open_store_api_frame()).
	 * @return void
	 */
	public static function enter_store_api_request( \WP_REST_Request $request, bool $display_read, bool $rest = false ): void {
		if ( null !== self::$sync ) {
			self::$sync->enter_store_api_request( $request, $display_read, $rest );
		} elseif ( self::$registered ) {
			self::$frames[] = [ \WeakReference::create( $request ), $display_read, get_current_blog_id(), $rest ];
		}
	}

	/**
	 * Note that a Store API request has finished (InventorySync::leave_store_api_request()):
	 * its frame goes, with every frame above it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return void
	 */
	public static function leave_store_api_request( \WP_REST_Request $request ): void {
		if ( null !== self::$sync ) {
			self::$sync->leave_store_api_request( $request );

			return;
		}

		for ( $i = count( self::$frames ) - 1; $i >= 0; --$i ) {
			if ( self::$frames[ $i ][0]->get() === $request ) {
				array_splice( self::$frames, $i );

				return;
			}
		}
	}

	/**
	 * InventorySync::capture_stock_operation().
	 *
	 * @param mixed $sql        Statement WooCommerce is about to execute.
	 * @param mixed $product_id Product / variation id being changed.
	 * @param mixed $new_stock  Resulting stock level. Unused: only the RELATIVE
	 *                          delta is replayed, never an absolute snapshot.
	 * @param mixed $operation  'set', 'increase' or 'decrease'.
	 * @return mixed
	 */
	public static function capture_stock_operation( $sql, $product_id = 0, $new_stock = null, $operation = 'set' ): mixed {
		return self::sync()->capture_stock_operation( $sql, $product_id, $new_stock, $operation );
	}

	/**
	 * InventorySync::expect_stock_operation().
	 *
	 * @param mixed $product Product or variation whose stock is about to change.
	 * @return void
	 */
	public static function expect_stock_operation( $product ): void {
		self::sync()->expect_stock_operation( $product );
	}

	/**
	 * InventorySync::sync_product_fields().
	 *
	 * @param int                     $product_id Post ID of the saved product.
	 * @param array<int, string>|null $only_keys  Copy only these of the synced
	 *                                            fields (a save through code
	 *                                            passes the keys it changed).
	 *                                            Null copies every one.
	 * @return void
	 */
	public static function sync_product_fields( int $product_id, ?array $only_keys = null ): void {
		self::sync()->sync_product_fields( $product_id, $only_keys );
	}

	/**
	 * InventorySync::sync_on_rest_save().
	 *
	 * @param \WC_Product $product Saved product object.
	 * @return void
	 */
	public static function sync_on_rest_save( \WC_Product $product ): void {
		self::sync()->sync_on_rest_save( $product );
	}

	/**
	 * InventorySync::forget_variation_map().
	 *
	 * @param int|mixed $variation_id Variation id.
	 * @param mixed     $variation    The variation object, when WooCommerce passes it.
	 * @return void
	 */
	public static function forget_variation_map( $variation_id = 0, $variation = null ): void {
		self::sync()->forget_variation_map( $variation_id, $variation );
	}

	/**
	 * InventorySync::note_structural_save().
	 *
	 * @param mixed $product Product being saved.
	 * @return void
	 */
	public static function note_structural_save( $product ): void {
		self::sync()->note_structural_save( $product );
	}

	/**
	 * InventorySync::forget_after_structural_save().
	 *
	 * @param mixed $product Saved product.
	 * @return void
	 */
	public static function forget_after_structural_save( $product ): void {
		self::sync()->forget_after_structural_save( $product );
	}

	/**
	 * InventorySync::forget_stock_matches().
	 *
	 * @return void
	 */
	public static function forget_stock_matches(): void {
		self::sync()->forget_stock_matches();
	}

	/**
	 * InventorySync::forget_after_meta_change(), which acts on a key of the
	 * variation structure (is_structural_meta_key()) and otherwise only drops
	 * the instance's own stock groups: before the instance exists, any other
	 * key builds none.
	 *
	 * @param mixed $meta_id   Meta id(s).
	 * @param mixed $object_id Object id.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public static function forget_after_meta_change( $meta_id, $object_id = 0, $meta_key = '' ): void {
		if ( null === self::$sync && ( ! is_string( $meta_key ) || ! self::is_structural_meta_key( $meta_key ) ) ) {
			return;
		}

		self::sync()->forget_after_meta_change( $meta_id, $object_id, $meta_key );
	}

	/**
	 * Whether a meta key is part of the variation structure the stock groups
	 * and variation matches are built from: a variation's attribute, the
	 * parent's attribute list, or the translated local attribute options.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_structural_meta_key( string $meta_key ): bool {
		return str_starts_with( $meta_key, 'attribute_' ) || '_product_attributes' === $meta_key || self::LOCAL_ATTR_SOURCE_META === $meta_key;
	}

	/**
	 * InventorySync::forget_after_status_change().
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 * @return void
	 */
	public static function forget_after_status_change( $new_status, $old_status = '', $post = null ): void {
		self::sync()->forget_after_status_change( $new_status, $old_status, $post );
	}

	/**
	 * InventorySync::forget_after_post_deleted().
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    Deleted post.
	 * @return void
	 */
	public static function forget_after_post_deleted( $post_id, $post = null ): void {
		self::sync()->forget_after_post_deleted( $post_id, $post );
	}

	/**
	 * InventorySync::renew_pending_epochs(), when the instance exists: only it queues
	 * epochs to renew.
	 *
	 * @return void
	 */
	public static function renew_pending_epochs(): void {
		self::$sync?->renew_pending_epochs();
	}

	/**
	 * InventorySync::renew_copy_map_after_term_flush().
	 *
	 * @param mixed $object_id   Object id.
	 * @param mixed $object_type Object type.
	 * @return void
	 */
	public static function renew_copy_map_after_term_flush( $object_id, $object_type = '' ): void {
		self::sync()->renew_copy_map_after_term_flush( $object_id, $object_type );
	}

	/**
	 * InventorySync::renew_copy_map_epoch().
	 *
	 * @return void
	 */
	public static function renew_copy_map_epoch(): void {
		self::sync()->renew_copy_map_epoch();
	}

	/**
	 * InventorySync::note_term_slug().
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public static function note_term_slug( $term_id, $taxonomy = '' ): void {
		self::sync()->note_term_slug( $term_id, $taxonomy );
	}

	/**
	 * InventorySync::renew_copy_map_after_term_edit().
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public static function renew_copy_map_after_term_edit( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		self::sync()->renew_copy_map_after_term_edit( $term_id, $tt_id, $taxonomy );
	}

	/**
	 * InventorySync::renew_copy_map_after_term_delete().
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public static function renew_copy_map_after_term_delete( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		self::sync()->renew_copy_map_after_term_delete( $term_id, $tt_id, $taxonomy );
	}

	/**
	 * InventorySync::forget_after_product_type_terms().
	 *
	 * @param mixed $object_id  Object id.
	 * @param mixed $terms      Terms given.
	 * @param mixed $tt_ids     Term taxonomy ids set.
	 * @param mixed $taxonomy   Taxonomy.
	 * @param mixed $append     Whether the terms were appended.
	 * @param mixed $old_tt_ids Term taxonomy ids before.
	 * @return void
	 */
	public static function forget_after_product_type_terms( $object_id, $terms = [], $tt_ids = [], $taxonomy = '', $append = false, $old_tt_ids = [] ): void {
		self::sync()->forget_after_product_type_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids );
	}

	/**
	 * InventorySync::forget_store_verdicts(), when the instance exists: only it holds
	 * the answers to forget.
	 *
	 * @return void
	 */
	public static function forget_store_verdicts(): void {
		self::$sync?->forget_store_verdicts();
	}

	/**
	 * InventorySync::sync_on_stock_change().
	 *
	 * @param \WC_Product $product Product whose stock changed.
	 * @return void
	 */
	public static function sync_on_stock_change( \WC_Product $product ): void {
		self::sync()->sync_on_stock_change( $product );
	}

	/**
	 * InventorySync::group_reserved_stock_query(). Before the instance exists,
	 * the one hold-sum query of a catalog limit noted here
	 * (mark_catalog_limits()) is left as WooCommerce builds it, as the
	 * instance leaves it.
	 *
	 * @param mixed $query            WooCommerce's hold-sum query.
	 * @param mixed $product_id       Stock id the holds are summed for.
	 * @param mixed $exclude_order_id Order whose own holds are left out.
	 * @return mixed
	 */
	public static function group_reserved_stock_query( $query, $product_id = 0, $exclude_order_id = 0 ): mixed {
		if ( null === self::$sync && (int) $product_id > 0 && (int) $product_id === self::$catalog_stock_id ) {
			self::$catalog_stock_id = 0;

			return $query;
		}

		return self::sync()->group_reserved_stock_query( $query, $product_id, $exclude_order_id );
	}

	/**
	 * Open the frame of a Store API request dispatched by the REST server.
	 *
	 * Filters `rest_request_before_callbacks`, which core fires for every
	 * request it runs a route callback for.
	 *
	 * @param mixed $response Result so far, returned untouched.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request being dispatched.
	 * @return mixed
	 */
	public static function open_store_api_frame( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		unset( $handler );

		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wc/store/' ) ) {
			self::enter_store_api_request( $request, self::is_display_cart_read( $request ), true );
		}

		return $response;
	}

	/**
	 * Close the frame opened for the same request.
	 *
	 * Filters `rest_request_after_callbacks`, which core fires for every
	 * request that fired the opening filter and returned.
	 *
	 * @param mixed $response Result to send, returned untouched.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request being dispatched.
	 * @return mixed
	 */
	public static function close_store_api_frame( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		unset( $handler );

		if ( $request instanceof \WP_REST_Request ) {
			self::leave_store_api_request( $request );
		}

		return $response;
	}

	/**
	 * `woocommerce_can_reduce_order_stock` / `woocommerce_can_restore_order_stock`
	 * (last): when WooCommerce goes ahead, an order stock change starts and
	 * gets a number of its own (with the order's id); otherwise none is open.
	 * A frame no closing action ends (a plugin that applies the filter only to
	 * ask, an exception in WooCommerce's line loop) lends its epoch read to no
	 * later stock change, because no line item of the order finishes
	 * (note_order_item_stock(), InventorySync::current_epochs()).
	 *
	 * @param mixed $can   Whether WooCommerce changes the order's stock, returned untouched.
	 * @param mixed $order The order.
	 * @return mixed
	 */
	public static function open_order_stock_change( mixed $can, mixed $order = null ): mixed {
		self::$order_frame = $can ? ++self::$order_frames : 0;
		self::$order_step  = 0;
		$id                = is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : 0;
		self::$order_id    = $can && is_int( $id ) ? $id : 0;

		return $can;
	}

	/**
	 * `woocommerce_reduce_order_stock` / `woocommerce_restore_order_stock`
	 * (first): the order's stock change is over.
	 *
	 * @return void
	 */
	public static function close_order_stock_change(): void {
		self::$order_frame = 0;
		self::$order_id    = 0;
		self::$order_step  = 0;
	}

	/**
	 * `woocommerce_reduce_order_item_stock` / `woocommerce_restore_order_item_stock`
	 * (first): WooCommerce finished one line item's stock change of the open
	 * order (the order is the last argument).
	 *
	 * @param mixed ...$args The item, the change, and the order last.
	 * @return void
	 */
	public static function note_order_item_stock( mixed ...$args ): void {
		$order = end( $args );
		$id    = is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : 0;

		if ( 0 !== self::$order_frame && is_int( $id ) && $id === self::$order_id ) {
			++self::$order_step;
		}
	}

	/**
	 * How many line items of the open order finished their stock change.
	 *
	 * @return int
	 */
	public static function order_stock_step(): int {
		return self::$order_step;
	}

	/**
	 * The number of the order stock change running now, 0 outside one.
	 *
	 * @return int
	 */
	public static function order_stock_change(): int {
		return self::$order_frame;
	}

	/**
	 * The id of the order whose stock change is open, 0 outside one or when
	 * the filter was given no order.
	 *
	 * @return int
	 */
	public static function order_stock_change_order(): int {
		return self::$order_id;
	}

	/**
	 * InventorySync::note_stock_hold().
	 *
	 * @param mixed $minutes Hold duration, returned untouched.
	 * @return mixed
	 */
	public static function note_stock_hold( mixed $minutes ): mixed {
		return self::sync()->note_stock_hold( $minutes );
	}

	/**
	 * Note the stock id of a product whose Store API quantity limit is about
	 * to be computed for product data outside a cart: in the instance once it
	 * exists (InventorySync::mark_catalog_limits()), else here, in the same
	 * order (the flag before the filter, the stock id after it), handed to an
	 * instance built in between. The note is dropped by that limit's hold-sum
	 * query (group_reserved_stock_query()), by the maximum filtered right after
	 * it (unmark_catalog_limits()), and by the next limit computed.
	 *
	 * @param mixed $minimum   Minimum quantity, returned untouched.
	 * @param mixed $product   Product the limits are for.
	 * @param mixed $cart_item Cart line the limits are for, or null.
	 * @return mixed
	 */
	public static function mark_catalog_limits( $minimum, $product = null, $cart_item = null ): mixed {
		if ( null !== self::$sync ) {
			return self::$sync->mark_catalog_limits( $minimum, $product, $cart_item );
		}

		self::$catalog_stock_id = 0;
		self::$catalog_limits   = false;

		if ( null !== $cart_item || ! $product instanceof \WC_Product ) {
			return $minimum;
		}

		self::$catalog_limits = true;

		/** This filter is documented in src/WooCommerce/InventorySync.php */
		if ( ! (bool) apply_filters( 'perflocale/woocommerce/count_shared_holds_in_catalog', false, $product ) ) {
			$stock_id = (int) $product->get_stock_managed_by_id();

			// A callback on the filter may have built the instance meanwhile.
			$built = self::built();

			if ( null !== $built ) {
				$built->note_catalog_stock_id( $stock_id );
			} else {
				self::$catalog_stock_id = $stock_id;
			}
		}

		return $minimum;
	}

	/**
	 * Drop the note of mark_catalog_limits() once the limit is computed
	 * (InventorySync::unmark_catalog_limits() once the instance exists).
	 *
	 * @param mixed $maximum Maximum quantity, returned untouched.
	 * @return mixed
	 */
	public static function unmark_catalog_limits( $maximum ): mixed {
		if ( null !== self::$sync ) {
			return self::$sync->unmark_catalog_limits( $maximum );
		}

		self::$catalog_stock_id = 0;
		self::$catalog_limits   = false;

		return $maximum;
	}

	/**
	 * InventorySync::cart_item_group_stock_short().
	 *
	 * @param mixed $not_enough WooCommerce's verdict for this stock id alone.
	 * @param mixed $product    Product of the cart line.
	 * @param mixed $values     Cart line.
	 * @return mixed
	 */
	public static function cart_item_group_stock_short( $not_enough, $product = null, $values = [] ): mixed {
		return self::sync()->cart_item_group_stock_short( $not_enough, $product, $values );
	}

	/**
	 * InventorySync::validate_store_api_cart_item().
	 *
	 * @param mixed $product   Product of the cart line.
	 * @param mixed $cart_item Cart line.
	 * @return void
	 */
	public static function validate_store_api_cart_item( $product, $cart_item = [] ): void {
		self::sync()->validate_store_api_cart_item( $product, $cart_item );
	}

	/**
	 * InventorySync::validate_add_to_cart_group_stock().
	 *
	 * @param mixed $passed       Whether validation passed so far.
	 * @param mixed $product_id   Product id being added.
	 * @param mixed $quantity     Units being added.
	 * @param mixed $variation_id Variation id being added, or 0.
	 * @return mixed
	 */
	public static function validate_add_to_cart_group_stock( $passed, $product_id = 0, $quantity = 1, $variation_id = 0 ): mixed {
		return self::sync()->validate_add_to_cart_group_stock( $passed, $product_id, $quantity, $variation_id );
	}

	/**
	 * InventorySync::sold_individually_found_in_cart().
	 *
	 * @param mixed $found        Whether WooCommerce found the product in the cart.
	 * @param mixed $product_id   Product id being added.
	 * @param mixed $variation_id Variation id being added, or 0.
	 * @return mixed
	 */
	public static function sold_individually_found_in_cart( $found, $product_id = 0, $variation_id = 0 ): mixed {
		return self::sync()->sold_individually_found_in_cart( $found, $product_id, $variation_id );
	}

	/**
	 * InventorySync::validate_store_api_sold_individually().
	 *
	 * @param mixed $product   Product of the cart line.
	 * @param mixed $cart_item Cart line.
	 * @return void
	 */
	public static function validate_store_api_sold_individually( $product, $cart_item = [] ): void {
		self::sync()->validate_store_api_sold_individually( $product, $cart_item );
	}

	/**
	 * InventorySync::merge_sold_individually_copies(). Before the instance
	 * exists, the method's own checks run here in its order and the cart's
	 * lines are read once: an empty cart (WooCommerce runs this check on any
	 * page that renders the cart, the Mini-Cart included) builds no instance,
	 * and a cart with lines hands the lines it read to the instance, which then
	 * does not read them again.
	 *
	 * @return void
	 */
	public static function merge_sold_individually_copies(): void {
		if ( null !== self::$sync ) {
			self::$sync->merge_sold_individually_copies();

			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}

		$cart = WC()->cart;

		if ( ! method_exists( $cart, 'check_cart_item_sold_individually' ) || false === has_action( 'woocommerce_check_cart_items', [ $cart, 'check_cart_items' ] ) ) {
			return;
		}

		$lines = $cart->get_cart();

		if ( ! is_array( $lines ) || [] === $lines ) {
			return;
		}

		self::sync()->merge_sold_individually_copies( $lines );
	}

	/**
	 * InventorySync::sync_variation_fields().
	 *
	 * @param int                     $variation_id   Saved variation ID.
	 * @param bool                    $rollup_parents Roll touched sibling parents up inline.
	 *                                                The bulk-edit handler passes false and
	 *                                                amortises the rollup across the whole run.
	 * @param array<int, string>|null $only_keys      Copy only these of the synced fields
	 *                                                (a save through code passes the keys
	 *                                                it changed). Null copies every one.
	 * @return void
	 */
	public static function sync_variation_fields( int $variation_id, bool $rollup_parents = true, ?array $only_keys = null ): void {
		self::sync()->sync_variation_fields( $variation_id, $rollup_parents, $only_keys );
	}

	/**
	 * InventorySync::sync_on_rest_variation_save().
	 *
	 * @param \WC_Product $variation Saved variation object.
	 * @return void
	 */
	public static function sync_on_rest_variation_save( \WC_Product $variation ): void {
		self::sync()->sync_on_rest_variation_save( $variation );
	}

	/**
	 * InventorySync::sync_after_bulk_variation_edit().
	 *
	 * @param string            $bulk_action Bulk action slug (unused - field diffing is value-based).
	 * @param array<mixed>      $data        Bulk action payload (unused).
	 * @param int|string        $product_id  Parent (variable) product ID.
	 * @param array<int|string> $variations  Affected variation IDs.
	 * @return void
	 */
	public static function sync_after_bulk_variation_edit( $bulk_action, $data, $product_id, $variations ): void {
		self::sync()->sync_after_bulk_variation_edit( $bulk_action, $data, $product_id, $variations );
	}

	/**
	 * InventorySync::sync_on_quick_or_bulk_edit().
	 *
	 * @param \WC_Product $product Saved product.
	 * @return void
	 */
	public static function sync_on_quick_or_bulk_edit( \WC_Product $product ): void {
		self::sync()->sync_on_quick_or_bulk_edit( $product );
	}

	/**
	 * InventorySync::note_quick_edit_child().
	 *
	 * @param int|string $variation_id Variation ID.
	 * @param mixed      $status       New stock status (unused; the sync reads the saved meta).
	 * @param mixed      $variation    Saved variation object.
	 * @return void
	 */
	public static function note_quick_edit_child( $variation_id, $status = '', $variation = null ): void {
		self::sync()->note_quick_edit_child( $variation_id, $status, $variation );
	}

	/**
	 * InventorySync::sync_on_import().
	 *
	 * @param \WC_Product          $product Imported product object.
	 * @param array<string, mixed> $data    Raw row data (unused).
	 * @return void
	 */
	public static function sync_on_import( $product, $data = [] ): void {
		self::sync()->sync_on_import( $product, $data );
	}

	/**
	 * InventorySync::note_crud_update().
	 *
	 * @param mixed $product       Product or variation being saved.
	 * @param mixed $updated_props Props whose stored value the save changed.
	 * @return void
	 */
	public static function note_crud_update( $product, $updated_props = [] ): void {
		self::sync()->note_crud_update( $product, $updated_props );
	}

	/**
	 * InventorySync::sync_after_crud_update().
	 *
	 * @param int|string $product_id Saved product or variation ID.
	 * @return void
	 */
	public static function sync_after_crud_update( $product_id ): void {
		self::sync()->sync_after_crud_update( $product_id );
	}

	/**
	 * InventorySync::forget_crud_update().
	 *
	 * @param int|string $product_id Created product or variation ID.
	 * @return void
	 */
	public static function forget_crud_update( $product_id ): void {
		self::sync()->forget_crud_update( $product_id );
	}

	/**
	 * InventorySync::flush_crud_updates().
	 *
	 * @return void
	 */
	public static function flush_crud_updates(): void {
		self::sync()->flush_crud_updates();
	}

	/**
	 * InventorySync::finish_translation_flow().
	 *
	 * @param int|string $new_id      ID of the translation just created.
	 * @param mixed      $object_type 'post' or 'term'.
	 * @return void
	 */
	public static function finish_translation_flow( $new_id, $object_type = '' ): void {
		self::sync()->finish_translation_flow( $new_id, $object_type );
	}

	/**
	 * InventorySync::claim_for_flow().
	 *
	 * @param mixed $product Object the flow will save.
	 * @return mixed
	 */
	public static function claim_for_flow( $product ): mixed {
		return self::sync()->claim_for_flow( $product );
	}
}
