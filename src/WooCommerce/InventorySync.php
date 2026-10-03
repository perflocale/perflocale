<?php
/**
 * WooCommerce inventory sync across language variants.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Enum\ObjectType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps physical product fields identical across all language variants.
 *
 * Products are translated content (title, description) but share the same
 * physical properties: stock, SKU, GTIN, price, weight, and dimensions. This
 * class copies them to the sibling language variants of a product, and of a
 * variation (the attribute-matched variation of each sibling parent):
 *
 * - Stock quantity follows every stock change WooCommerce reports: orders,
 *   refunds and cancellations (replayed as the same relative change), and any
 *   save that sets a new quantity.
 * - The product editor, Quick/Bulk Edit, the Variations panel and its bulk
 *   actions, the REST API and `wp wc`, and the CSV importer mirror every shared
 *   field after their save. A copy that manages its own stock, and still does
 *   after that save, keeps its quantity, which only the stock changes above
 *   move, and the stock status its own quantity gives it: a save that changes
 *   no quantity leaves that copy's quantity as it is, even where it differs
 *   from the saved product's.
 * - Any other update of an existing product or variation through WooCommerce
 *   (WC_Product::save(), from custom code or another plugin) mirrors the shared
 *   fields whose WooCommerce props that save changed: prices, SKU, GTIN, weight
 *   and dimensions, virtual/downloadable, stock management and backorders, and
 *   the stock status of a product that does not manage its own stock (a copy
 *   that manages its own stock keeps the status its own quantity gives it). It
 *   never copies a quantity, and creating a product or variation does not
 *   start it.
 * - A save made while the product editor, Quick/Bulk Edit or the Variations
 *   panel (or one of its bulk actions) is saving waits until that save is
 *   over: the screen's own sync covers the product it edited, and any other
 *   product saved meanwhile (by a listener on its hooks, say) is mirrored
 *   then. While a translation is being created, the new copy's own saves push
 *   nothing back.
 *
 * The language copies of a product that manages its own stock are one physical
 * stock at checkout too: a pending order's stock hold on one copy counts
 * against every copy, and a cart holding several copies is checked against
 * that one stock. Order items, downloads and refunds keep the copy bought.
 *
 * Meta written directly, without a WooCommerce save, is not seen.
 */
final class InventorySync {

	/**
	 * Physical product fields that must be identical across all language variants.
	 *
	 * These represent product facts, not translatable content. The list is
	 * filterable via `perflocale/woocommerce/synced_product_fields`.
	 */
	private const SHARED_FIELDS = [
		// Stock management.
		'_stock',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		// Pricing.
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		// Identity. The GTIN/UPC/EAN (WC 9.1+) identifies the same physical
		// product in every language — the WC addon already exempts shared
		// values from WC's uniqueness validation for translation siblings,
		// so without syncing it here a GTIN edited AFTER translation
		// creation would silently diverge (harmless meta on WC < 9.1).
		'_sku',
		'_global_unique_id',
		// Physical dimensions (shipped in all languages the same way).
		'_weight',
		'_length',
		'_width',
		'_height',
		// Product nature.
		'_virtual',
		'_downloadable',
		// NB: total_sales is intentionally NOT synced. Copying the source
		// product's counter onto siblings clobbers each variant's own sales
		// (it overwrites, it does not aggregate), losing data and skewing
		// best-seller reports. Each language variant keeps its own count.
	];

	/**
	 * The price meta keys. They move as one group: WooCommerce rewrites
	 * `_price` and may clear `_sale_price` itself when one of them changes.
	 */
	private const PRICE_KEYS = [ '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to' ];

	/**
	 * WooCommerce props a save can change => the shared meta keys a change of
	 * that prop mirrors (see note_crud_update()).
	 *
	 * `stock_quantity` is absent on purpose: quantity is mirrored by the stock
	 * hooks, relatively for orders, and never by this path.
	 */
	private const CRUD_PROP_KEYS = [
		'regular_price'     => self::PRICE_KEYS,
		'sale_price'        => self::PRICE_KEYS,
		'date_on_sale_from' => self::PRICE_KEYS,
		'date_on_sale_to'   => self::PRICE_KEYS,
		'sku'               => [ '_sku' ],
		'global_unique_id'  => [ '_global_unique_id' ],
		'weight'            => [ '_weight' ],
		'length'            => [ '_length' ],
		'width'             => [ '_width' ],
		'height'            => [ '_height' ],
		'virtual'           => [ '_virtual' ],
		'downloadable'      => [ '_downloadable' ],
		'manage_stock'      => [ '_manage_stock', '_stock_status' ],
		'backorders'        => [ '_backorders', '_stock_status' ],
		'stock_status'      => [ '_stock_status' ],
	];

	/**
	 * Actions that run a save with a sync of its own: the product editor, the
	 * products-list Quick/Bulk Edit (WooCommerce fires this one around every
	 * admin save_post), the Variations panel and its bulk actions, and the
	 * creation of a translation. A save recorded while one of them runs waits
	 * for it to end (see sync_after_crud_update()).
	 */
	private const OWN_SYNC_FLOWS = [
		'woocommerce_process_product_meta'         => true,
		'woocommerce_product_bulk_and_quick_edit'  => true,
		'wp_ajax_woocommerce_save_variations'      => true,
		'wp_ajax_woocommerce_bulk_edit_variations' => true,
		'perflocale/translation/created'           => true,
	];

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Outer (source-product) lock TTL in seconds.
	 *
	 * Long enough to outlive the full sibling-sync loop even when the
	 * translation group has many siblings - the outer lock must not
	 * expire while inner work is still in flight.
	 */
	private const LOCK_TTL_OUTER = 30;

	/**
	 * Inner (sibling) lock TTL in seconds.
	 *
	 * Shorter than the outer lock so expired inner locks can be taken
	 * over quickly if a single-sibling write stalls (slow DB, etc.) -
	 * while still outlasting a normal update_post_meta batch.
	 */
	private const LOCK_TTL_INNER = 10;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache            = $cache;
		$this->flow_claims      = new \WeakMap();
		$this->structural_saves = new \WeakMap();
	}

	/**
	 * Shared meta keys a WooCommerce save changed, waiting for that save (or
	 * the flow around it, when `waiting` is set) to finish, keyed blog id =>
	 * product / variation id.
	 *
	 * @var array<int, array<int, array{keys: array<int, string>, variation: bool, parent: int, waiting?: bool}>>
	 */
	private array $crud_pending = [];

	/**
	 * Whether flush_crud_updates() is hooked to `shutdown` yet.
	 *
	 * @var bool
	 */
	private bool $flush_hooked = false;

	/**
	 * Product objects that a REST or CSV-importer flow is about to save and
	 * will sync itself right after. The CRUD trigger leaves that one save to
	 * the flow. Weak, so an object the flow never saves cannot leave a claim
	 * behind once it is gone.
	 *
	 * @var \WeakMap<\WC_Product, true>
	 */
	private \WeakMap $flow_claims;

	/**
	 * Sibling variable parents waiting for their end-of-request rollup, keyed
	 * blog id => parent id => true.
	 *
	 * @var array<int, array<int, true>>
	 */
	private array $deferred_rollups = [];

	/**
	 * Whether run_deferred_rollups() is hooked to `shutdown` yet.
	 *
	 * @var bool
	 */
	private bool $rollups_hooked = false;

	/**
	 * Depth of the sibling writes this class is performing right now.
	 *
	 * A WooCommerce save caused by those writes (the lookup refresh below
	 * WooCommerce 10.8, a variable parent's rollup, a listener on the meta
	 * writes) is a consequence of a sync, never the start of another one.
	 *
	 * @var int
	 */
	private int $writing = 0;

	/**
	 * Build the lock name for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function lock_name( int $product_id ): string {
		return 'invsync_' . $product_id;
	}

	/**
	 * Relative stock deltas captured from WooCommerce's own stock statement,
	 * keyed by the product / variation id it is about to change.
	 *
	 * WooCommerce decrements stock with an ATOMIC RELATIVE statement
	 * (`SET meta_value = meta_value -1`) precisely so two concurrent shoppers
	 * cannot lose an update. The sibling mirror used to read
	 * get_stock_quantity() - an ABSOLUTE snapshot - and blind-write it onto
	 * every language sibling. Siblings each own their own `_stock` row, so
	 * that is a read-modify-write and cannot represent two concurrent
	 * decrements: two sales from a stock of 10 left BOTH siblings at 9
	 * instead of 8. Capturing the delta lets the mirror replay the same
	 * relative operation instead of a lossy snapshot.
	 *
	 * Keyed blog id => product / variation id: a listener that switches blogs
	 * between WooCommerce's statement and its set_stock action cannot replay
	 * one blog's delta on another blog's product with the same id.
	 *
	 * @var array<int, array<int, float>>
	 */
	private array $pending_stock_ops = [];

	/**
	 * Products and variations whose stock WooCommerce announced it is about
	 * to change (woocommerce_{product|variation}_before_set_stock), keyed
	 * blog id => the id whose stock row changes.
	 *
	 * @var array<int, array<int, true>>
	 */
	private array $announced_stock_ops = [];

	/**
	 * Variations whose stock status a products-list Quick Edit or Bulk Edit
	 * just changed, keyed blog id => parent id => variation id => true.
	 *
	 * WooCommerce applies "In stock?" to a variable product that does not
	 * manage stock by saving each child variation, then fires the quick/bulk
	 * edit action for the parent only. The parent handler reads this list so
	 * the matching variations in the other languages follow.
	 *
	 * @var array<int, array<int, array<int, true>>>
	 */
	private array $quick_edit_children = [];

	/**
	 * Ids that share one physical stock with a stock id (see stock_group()),
	 * keyed blog id => stock id.
	 *
	 * @var array<int, array<int, array<int, int>>>
	 */
	private array $stock_groups = [];

	/**
	 * Stock id whose quantity limit the Store API is computing for product
	 * data outside a cart right now, or 0 (see mark_catalog_limits()).
	 *
	 * @var int
	 */
	private int $catalog_stock_id = 0;

	/**
	 * Store API requests running now, innermost last: the request (held
	 * weakly), whether it is a display cart read (is_display_cart_read()),
	 * the blog it runs on, and whether core's REST server dispatched it
	 * (open_store_api_frame()) rather than WooCommerce's hydration.
	 *
	 * @var array<int, array{0: \WeakReference<\WP_REST_Request>, 1: bool, 2: int, 3: bool}>
	 */
	private array $store_api_frames = [];

	/**
	 * Whether a stock hold for an order has started in this request.
	 *
	 * @var bool
	 */
	private bool $stock_hold_started = false;

	/**
	 * Canonical tokens of variation attribute values (canonical_attr_value()),
	 * keyed blog id => "taxonomy|value".
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $canonical_attr_memo = [];

	/**
	 * Whether WooCommerce's own post-table data stores read variations and the
	 * children of variable products (reads_cpt_variation_stores()), keyed
	 * blog id => the data store filters registered when it was read
	 * (data_store_filters()). Emptied on every blog switch.
	 *
	 * @var array<int, array<string, bool>>
	 */
	private array $cpt_variation_stores = [];

	/**
	 * Products being saved with a change that stock groups or variation
	 * matches are built from (note_structural_save()).
	 *
	 * @var \WeakMap<\WC_Product, true>
	 */
	private \WeakMap $structural_saves;

	/**
	 * TranslationGroupRepository::link_changes() when the memos built from
	 * translation groups were last checked (drop_memos_after_link_changes()),
	 * or -1 before the first check.
	 *
	 * @var int
	 */
	private int $memo_link_changes = -1;

	/**
	 * When this process last renewed each blog's stored-map epoch
	 * (renew_stored_map_epoch()), blog id => microtime.
	 *
	 * @var array<int, float>
	 */
	private array $epoch_renewed = [];

	/**
	 * Blogs with a change that can alter a variation match since this
	 * process last renewed their epoch, blog id => true. The epoch is
	 * renewed before this process next reads a stored map of the blog, and
	 * at shutdown (renew_pending_epochs()).
	 *
	 * @var array<int, true>
	 */
	private array $epoch_pending = [];

	/**
	 * Record the relative stock operation WooCommerce is about to run.
	 *
	 * Registered on `woocommerce_update_product_stock_query` at priority 1 so
	 * it reads WooCommerce's own statement before another plugin can rewrite
	 * it. The query is returned untouched - this callback only observes.
	 *
	 * @param mixed $sql        Statement WooCommerce is about to execute.
	 * @param mixed $product_id Product / variation id being changed.
	 * @param mixed $new_stock  Resulting stock level. Unused: only the RELATIVE
	 *                          delta is replayed, never an absolute snapshot.
	 * @param mixed $operation  'set', 'increase' or 'decrease'.
	 * @return mixed The unmodified statement.
	 */
	public function capture_stock_operation( $sql, $product_id = 0, $new_stock = null, $operation = 'set' ) {
		$product_id = (int) $product_id;
		$blog       = get_current_blog_id();

		// Always clear first: a later absolute 'set' on the same product must
		// never be mirrored with a stale delta left over from an 'increase'.
		unset( $this->pending_stock_ops[ $blog ][ $product_id ] );

		// Only a statement wc_update_product_stock() announced is followed by
		// the set_stock action that replays and consumes the delta. A direct
		// call of the data store's update_product_stock() fires no set_stock,
		// so a delta captured from it would stay behind for the rest of the
		// process: it would hold back a later CRUD save of the product (see
		// note_crud_update()) and be replayed in place of its next absolute
		// stock write.
		$announced = isset( $this->announced_stock_ops[ $blog ][ $product_id ] );

		unset( $this->announced_stock_ops[ $blog ][ $product_id ] );

		if ( $announced && $product_id > 0 && is_string( $sql ) && ( 'increase' === $operation || 'decrease' === $operation ) ) {
			$delta = $this->extract_stock_delta( $sql, $product_id );

			if ( null !== $delta ) {
				$this->pending_stock_ops[ $blog ][ $product_id ] = $delta;
			}
		}

		return $sql;
	}

	/**
	 * Consume the delta captured for a product, if any.
	 *
	 * Consumed (not merely read) so a handler that bails early - master switch
	 * off, product opted out, no siblings, lock contention - cannot leave a
	 * stale delta behind for a later call in the same request.
	 *
	 * @param int $product_id Product / variation id.
	 * @return float|null Signed delta, or null when the change was absolute.
	 */
	private function take_stock_delta( int $product_id ): ?float {
		$blog = get_current_blog_id();
		$op   = $this->pending_stock_ops[ $blog ][ $product_id ] ?? null;

		unset( $this->pending_stock_ops[ $blog ][ $product_id ], $this->announced_stock_ops[ $blog ][ $product_id ] );

		return is_float( $op ) ? $op : null;
	}

	/**
	 * Note that WooCommerce is about to change a product's stock through
	 * wc_update_product_stock() or a CRUD save, so its next stock statement
	 * may be captured (see capture_stock_operation()).
	 *
	 * A CRUD save announces the change too but writes `_stock` without a
	 * statement; its set_stock action consumes the note (take_stock_delta()),
	 * and a save that ends without one drops it (sync_after_crud_update()).
	 *
	 * @param mixed $product Product or variation whose stock is about to change.
	 * @return void
	 */
	public function expect_stock_operation( $product ): void {
		$id = $product instanceof \WC_Product ? (int) $product->get_id() : 0;

		if ( $id > 0 ) {
			$this->announced_stock_ops[ get_current_blog_id() ][ $id ] = true;
		}
	}

	/**
	 * Signed delta from WooCommerce's relative stock statement.
	 *
	 * WC_Product_Data_Store_CPT::update_product_stock() builds exactly
	 * "SET meta_value = meta_value %+f WHERE post_id = %d AND meta_key='_stock'"
	 * for increase/decrease, and wpdb::prepare renders %+f locale-unaware, so
	 * the sign and magnitude are readable without guessing. Anything else - a
	 * WooCommerce rewrite, or another plugin that filtered the statement first
	 * - returns null and the caller keeps the absolute mirror it has always
	 * used. Losing the optimisation is a correctness no-op; guessing a delta
	 * would not be.
	 *
	 * @param string $sql        Statement WooCommerce built.
	 * @param int    $product_id Product id the statement must target.
	 * @return float|null Signed delta, or null when the shape is unrecognised.
	 */
	private function extract_stock_delta( string $sql, int $product_id ): ?float {
		$matched = preg_match(
			"/SET\s+meta_value\s*=\s*meta_value\s*([+-])\s*([0-9]+(?:\.[0-9]+)?)\s+WHERE\s+post_id\s*=\s*([0-9]+)\s+AND\s+meta_key\s*=\s*'_stock'/i",
			$sql,
			$matches
		);

		if ( 1 !== $matched || (int) $matches[3] !== $product_id ) {
			return null;
		}

		$delta = (float) $matches[2];

		if ( ! is_finite( $delta ) || $delta <= 0.0 ) {
			return null;
		}

		return '-' === $matches[1] ? -$delta : $delta;
	}

	/**
	 * Apply the source's relative stock change to ONE sibling, atomically.
	 *
	 * The same statement shape WooCommerce runs on the product being bought,
	 * so two concurrent sales of two language siblings both land instead of
	 * one overwriting the other with a snapshot. Returns false - and the
	 * caller falls back to the absolute mirror - for any sibling this cannot
	 * safely apply to.
	 *
	 * @param int   $sibling_id Sibling product / variation id.
	 * @param float $delta      Signed delta to apply.
	 * @return bool True when the relative write happened. False only when the
	 *              sibling has no numeric `_stock` row, where the caller's
	 *              absolute mirror is the right fallback.
	 */
	private function apply_relative_stock( int $sibling_id, float $delta ): bool {
		global $wpdb;

		if ( $sibling_id <= 0 || 0.0 === $delta ) {
			return false;
		}

		// A sibling with no NUMERIC `_stock` of its own has nothing to
		// decrement - a product that never tracked stock would be driven
		// negative from an empty value. Fall back to the absolute mirror.
		if ( ! is_numeric( get_post_meta( $sibling_id, '_stock', true ) ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberate atomic read-modify-write; no WP meta API can express one, which is exactly why WooCommerce writes its own here too.
		// `meta_value + (%f)` with a PLAIN %f, not `meta_value %+f`. The sign
		// already rides in prepare()'s %F output, and `%+f` is a "complex
		// placeholder" that trips WordPress.DB.PreparedSQLPlaceholders — which
		// Plugin Check pulls in at full severity, so it would move the tracked
		// dist score off 0.
		//
		// UNCONDITIONAL. There is deliberately NO `AND meta_value = <expected>`
		// identity predicate here, and adding one back reintroduces a
		// lost-update bug worse than the drift it was meant to solve.
		//
		// That predicate compared the sibling against the SOURCE's pre-change
		// value. With three simultaneous orders against three language copies,
		// all three of WooCommerce's own atomic decrements commit before any
		// PerfLocale sync runs, so by sync time no sibling still holds the
		// pre-change value. The predicate matched zero rows, the caller fell
		// through to the absolute mirror, and that copied one worker's stale
		// snapshot. Measured on three sites: three sales of a stock-10 product
		// ended at (9,9,9) where the serial control ended at (7,7,7) — two of
		// three decrements lost, and the loss grows with concurrency.
		//
		// A delta is valid whatever the sibling currently holds: "sold one" is
		// -1 regardless of the base. Applying it inside a single UPDATE is
		// atomic in the engine, so N concurrent orders produce N decrements.
		//
		// The trade is that a sibling which has ALREADY drifted stays drifted —
		// its value moves by the right amount from the wrong base. That is the
		// correct direction to fail: an absolute 'set' (admin stock edit,
		// quick/bulk edit, REST or CLI write) still runs the absolute mirror
		// below and re-converges the whole group, whereas a lost purchase is
		// permanent.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET meta_value = meta_value + (%f) WHERE post_id = %d AND meta_key = '_stock'",
				$wpdb->postmeta,
				$delta,
				$sibling_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// A relative delta always changes the value, so 0 affected rows means
		// there was no `_stock` row to update (and false means a DB error).
		if ( ! is_int( $affected ) || $affected < 1 ) {
			return false;
		}

		// The row moved underneath the object cache - same call WooCommerce
		// makes after its own stock statement.
		wp_cache_delete( $sibling_id, 'post_meta' );

		return true;
	}

	/**
	 * Re-derive one product's `_stock_status` from ITS OWN resulting quantity.
	 *
	 * After a relative write the sibling's quantity is its own, so copying the
	 * source's status would be a second lossy snapshot. This reproduces
	 * WC_Product::validate_props() - above the no-stock threshold is in stock,
	 * otherwise on backorder when backorders are allowed, otherwise out of
	 * stock - against the sibling's own meta.
	 *
	 * @param int $product_id Sibling product / variation id.
	 * @return bool Whether the stored status was written.
	 */
	private function refresh_derived_stock_status( int $product_id ): bool {
		global $wpdb;

		// Mirror WC_Product::validate_props(): a product that does NOT manage
		// its own stock has an operator-chosen `_stock_status`, and deriving one
		// from a leftover `_stock` value would silently overwrite it — flipping
		// an always-in-stock product to outofstock because an old quantity row
		// happened to be 0.
		if ( 'yes' !== (string) get_post_meta( $product_id, '_manage_stock', true ) ) {
			return false;
		}

		$threshold  = absint( get_option( 'woocommerce_notify_no_stock_amount', 0 ) );
		$backorders = (string) get_post_meta( $product_id, '_backorders', true );

		// Below-threshold resolves to onbackorder or outofstock. Neither input
		// is raced by a stock change, so both are decided here and bound.
		$below = ( '' !== $backorders && 'no' !== $backorders ) ? 'onbackorder' : 'outofstock';

		// ONE statement, deriving from `_stock` as committed AT WRITE TIME
		// rather than from a value read moments earlier. That is what lets the
		// callers run this unlocked: with a PHP-side read-derive-write, two
		// concurrent orders could each read a quantity, and the one that wrote
		// second could publish a status derived from the EARLIER quantity.
		// Reading the quantity inside the UPDATE removes the window entirely.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately atomic; a read-modify-write here can publish a stale stock status under concurrency.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i st JOIN %i q ON q.post_id = st.post_id AND q.meta_key = '_stock'
				 SET st.meta_value = CASE WHEN CAST( q.meta_value AS DECIMAL(20,6) ) > %d THEN 'instock' ELSE %s END
				 WHERE st.post_id = %d AND st.meta_key = '_stock_status'",
				$wpdb->postmeta,
				$wpdb->postmeta,
				$threshold,
				$below,
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( is_int( $affected ) && $affected > 0 ) {
			wp_cache_delete( $product_id, 'post_meta' );

			return true;
		}

		// 0 affected rows is USUALLY the status already being correct, which is
		// the common case and needs nothing. The other possibility is that the
		// product has no `_stock_status` row at all, and an UPDATE cannot create
		// one. Distinguish with a primed-cache read rather than another query.
		if ( '' !== (string) get_post_meta( $product_id, '_stock_status', true ) ) {
			return false;
		}

		$quantity = (int) get_post_meta( $product_id, '_stock', true );

		return false !== update_post_meta( $product_id, '_stock_status', $quantity > $threshold ? 'instock' : $below );
	}

	/**
	 * Refresh ONLY the stock columns of a sibling's wc_product_meta_lookup row.
	 *
	 * Note that refresh_product_lookup() must NOT be used after a relative write: its
	 * WC < 10.8 fallback re-sets stock ABSOLUTELY via
	 * wc_update_product_stock(..., 'set') from a value it read a moment
	 * earlier, which is harmless for the absolute mirror but would clobber a
	 * concurrent decrement and undo exactly what the relative path exists for.
	 * Update the two derived columns on the EXISTING row instead (0 affected
	 * rows when WooCommerce has not created one yet, matching its own lazy
	 * behaviour); a missing table or column on very old WooCommerce leaves the
	 * derived row stale, which is the pre-fix behaviour.
	 *
	 * @param int $product_id Sibling product / variation id.
	 * @return void
	 */
	private function refresh_stock_lookup_columns( int $product_id ): void {
		global $wpdb;

		try {
			// ONE statement that COPIES from postmeta instead of two cached
			// reads plus a write. wc_product_meta_lookup is what shop queries
			// read, so a stale figure here is visible to shoppers and can
			// oversell. Reading the source columns inside the UPDATE means the
			// row always lands on the committed quantity whatever order two
			// concurrent orders finished in — which is what makes this safe to
			// call unlocked. It is also cheaper than the version it replaces.
			//
			// An inner JOIN on `_stock`: callers only reach here after
			// apply_relative_stock() has confirmed a numeric `_stock` row, and a
			// product without one should keep whatever WooCommerce put in the
			// lookup rather than be forced to 0.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted refresh of WooCommerce's derived lookup row; no WC API rebuilds it without also rewriting stock.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i l
					 JOIN %i q ON q.post_id = l.product_id AND q.meta_key = '_stock'
					 LEFT JOIN %i s ON s.post_id = l.product_id AND s.meta_key = '_stock_status'
					 SET l.stock_quantity = CAST( q.meta_value AS DECIMAL(20,6) ),
					     l.stock_status   = COALESCE( s.meta_value, l.stock_status )
					 WHERE l.product_id = %d",
					$wpdb->prefix . 'wc_product_meta_lookup',
					$wpdb->postmeta,
					$wpdb->postmeta,
					$product_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Set the stock columns of a product's wc_product_meta_lookup row from its
	 * committed meta, the way WooCommerce derives them: the quantity while the
	 * product manages its own stock, NULL while it does not, and the stored
	 * stock status.
	 *
	 * Used below WooCommerce 10.8, after a copy changed the stock-management
	 * setting (see refresh_product_lookup()). One statement that reads the
	 * meta inside the UPDATE, so the row lands on the committed quantity
	 * whatever an order's relay wrote meanwhile (that relay updates the same
	 * columns unlocked, refresh_stock_lookup_columns()), and no `_stock` is
	 * written. A product without a lookup row is left alone (0 affected
	 * rows), and so is the quantity of a stock-managed product without a
	 * `_stock` row. The caller clears the product's transients.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	private function refresh_lookup_stock_from_meta( int $product_id ): void {
		global $wpdb;

		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted refresh of WooCommerce's derived lookup row; no WC API below 10.8 rebuilds it without also rewriting stock.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i l
					 LEFT JOIN %i m ON m.post_id = l.product_id AND m.meta_key = '_manage_stock'
					 LEFT JOIN %i q ON q.post_id = l.product_id AND q.meta_key = '_stock'
					 LEFT JOIN %i s ON s.post_id = l.product_id AND s.meta_key = '_stock_status'
					 SET l.stock_quantity = CASE WHEN m.meta_value = 'yes' THEN COALESCE( CAST( q.meta_value AS DECIMAL(20,6) ), l.stock_quantity ) ELSE NULL END,
					     l.stock_status   = COALESCE( s.meta_value, l.stock_status )
					 WHERE l.product_id = %d",
					$wpdb->prefix . 'wc_product_meta_lookup',
					$wpdb->postmeta,
					$wpdb->postmeta,
					$wpdb->postmeta,
					$product_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( \Throwable $e ) {
			// A missing lookup table or column leaves the derived row as it
			// was; the meta is already correct.
			unset( $e );
		}
	}

	/**
	 * Rebuild a product's wc_product_meta_lookup row from its current meta.
	 *
	 * The sibling sync writes meta with update_post_meta() (deliberately — it
	 * must bypass WC's unique-SKU validation so translations can share a SKU,
	 * and avoid the overhead of full CRUD saves). That bypasses WC's change-
	 * tracking, so neither a plain $product->save() nor the data store's
	 * conditional lookup update fires. The lookup table backs catalog
	 * price-sort, stock-filter and SKU search, so it must be refreshed
	 * explicitly or the translated product shows stale data in shop queries.
	 *
	 * WC 10.8+ exposes WC_Product_Data_Store_CPT::refresh_product_lookup_table()
	 * which reads fresh from meta and rebuilds the whole row unconditionally.
	 * Older WC has no public single-product rebuild, so fall back to
	 * wc_update_product_stock() whose data-store path rebuilds the row from meta
	 * as a side effect (only for stock-managed products). Re-entrancy is safe:
	 * callers hold the sibling lock, so any hook this fires bails immediately.
	 *
	 * A caller that did not change `_stock` passes $stock_written = false, and
	 * the WC < 10.8 fallback then never re-sets the quantity from a value read a
	 * moment earlier, which could overwrite a concurrent order's decrement. The
	 * legacy column refresh leaves the quantity column alone, and the quantity
	 * WooCommerce stores there follows the stock-management setting as well as
	 * `_stock` (none while the product does not manage its stock). A caller
	 * that changed `_manage_stock` passes $manage_written = true, and the
	 * fallback then also sets the stock columns from the committed meta
	 * (refresh_lookup_stock_from_meta()), which writes no `_stock`.
	 *
	 * @param int  $product_id     Sibling product ID whose meta was just synced.
	 * @param bool $stock_written  Whether `_stock` may have been written.
	 * @param bool $manage_written Whether `_manage_stock` was written.
	 * @return void
	 */
	private function refresh_product_lookup( int $product_id, bool $stock_written = true, bool $manage_written = false ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			return;
		}

		if ( function_exists( 'WC' ) && version_compare( (string) WC()->version, '10.8.0', '>=' ) ) {
			$product->get_data_store()->refresh_product_lookup_table( $product_id );
		} elseif ( $stock_written && true === $product->get_manage_stock() && function_exists( 'wc_update_product_stock' ) ) {
			// Strict own-stock check: managing_stock() is also truthy for a
			// PARENT-managed variation (get_manage_stock() returns 'parent'),
			// and wc_update_product_stock() would then redirect to the sibling
			// PARENT and fire woocommerce_product_set_stock for a product
			// whose lock is NOT held here — an unguarded nested group sync.
			// Parent-managed variations take the direct-column path below;
			// the parent's own rollup covers its aggregate state.
			wc_update_product_stock( $product, $product->get_stock_quantity(), 'set' );
		} else {
			// Older WC has no per-product lookup refresh API and the stock
			// path above only covers stock-managed products — after a raw
			// price/SKU/stock-status meta sync a NON-stock-managed sibling's
			// wc_product_meta_lookup row (backing catalog price sort/filter,
			// SKU search and WooCommerce's own variable-parent stock rollup)
			// would stay stale until its next real save.
			// Update exactly the columns this class syncs, on the EXISTING
			// row only (0 affected rows when WC hasn't created one = no-op,
			// matching WC's own lazy behaviour).
			$this->update_legacy_lookup_row( $product );

			if ( $manage_written ) {
				$this->refresh_lookup_stock_from_meta( $product_id );
			}
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Observe WooCommerce's own stock statement so the sibling mirror can
		// replay a RELATIVE change instead of an absolute snapshot - two
		// concurrent sales of two language siblings used to lose a decrement.
		// Priority 1: read WooCommerce's statement before another plugin can
		// rewrite it. The callback returns the query untouched.
		add_filter( 'woocommerce_update_product_stock_query', [ $this, 'capture_stock_operation' ], 1, 4 );

		// wc_update_product_stock() announces its statement with these; a
		// direct data-store call does not, and is not captured. Last, so the
		// note is taken right before WooCommerce's own statement runs.
		add_action( 'woocommerce_product_before_set_stock', [ $this, 'expect_stock_operation' ], PHP_INT_MAX, 1 );
		add_action( 'woocommerce_variation_before_set_stock', [ $this, 'expect_stock_operation' ], PHP_INT_MAX, 1 );

		// Sync after WooCommerce saves all product meta.
		add_action( 'woocommerce_process_product_meta', [ $this, 'sync_product_fields' ], 100, 1 );

		// Sync when saved via WooCommerce REST API (programmatic / bulk edits).
		add_action( 'woocommerce_rest_insert_product_object', [ $this, 'sync_on_rest_save' ], 10, 1 );

		// A variation created or saved changes its parent's children or their
		// attributes, so that parent's variation map is built again when next
		// needed (an import or a batch that creates the copies' variations in
		// one process).
		add_action( 'woocommerce_new_product_variation', [ $this, 'forget_variation_map' ], 10, 2 );
		add_action( 'woocommerce_update_product_variation', [ $this, 'forget_variation_map' ], 10, 2 );

		// The stock groups (stock_group()) and the variation matches are
		// built from which variations a copy parent has, their attributes
		// and status, and which copies manage stock or opt out. A change of
		// any of those in this process drops them for the blog; a stock-only
		// save keeps them. WooCommerce's saves, direct meta writes, status
		// changes, deletions and product type changes are each seen. The
		// epoch renewals they mark are completed at shutdown.
		add_action( 'woocommerce_before_product_object_save', [ $this, 'note_structural_save' ], PHP_INT_MAX, 1 );
		add_action( 'woocommerce_after_product_object_save', [ $this, 'forget_after_structural_save' ], 10, 1 );
		add_action( 'woocommerce_product_type_changed', [ $this, 'forget_stock_matches' ], 10, 0 );
		add_action( 'added_post_meta', [ $this, 'forget_after_meta_change' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'forget_after_meta_change' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'forget_after_meta_change' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'forget_after_status_change' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'forget_after_post_deleted' ], 10, 2 );
		add_action( 'shutdown', [ $this, 'renew_pending_epochs' ], PHP_INT_MAX, 0 );

		// Which data stores read variations is a per-blog answer.
		add_action( 'switch_blog', [ $this, 'forget_store_verdicts' ], 10, 0 );

		// Sync stock when WooCommerce changes it via orders (purchase, cancel, refund).
		// These fire from wc_update_product_stock(), not from the admin product editor.
		// Without these, buying from the EN store reduces EN stock but DE/FR keep old values.
		add_action( 'woocommerce_product_set_stock', [ $this, 'sync_on_stock_change' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'sync_on_stock_change' ], 10, 1 );

		// The copies are one stock before payment too. WooCommerce counts a
		// pending order's stock hold only against the id it holds, which
		// would leave every other language free to sell the same unit. Its
		// one hold-sum query feeds the cart checks, the Store API, the
		// checkout's own hold and pay-for-order. A Store API read of the cart
		// is the exception (see is_display_cart_read() and
		// in_display_cart_read()).
		add_filter( 'woocommerce_query_for_reserved_stock', [ $this, 'group_reserved_stock_query' ], 10, 3 );

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
		add_filter( 'rest_request_before_callbacks', [ $this, 'open_store_api_frame' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'close_store_api_frame' ], 10, 3 );

		// Once a stock hold for an order starts, every later hold-sum query of
		// the request counts every copy.
		add_filter( 'woocommerce_order_hold_stock_minutes', [ $this, 'note_stock_hold' ], PHP_INT_MIN, 1 );

		// The Store API asks the same query for the quantity limit of every
		// stock-managed product in its product data (product blocks, the
		// products route), where no money is at stake. There it stays
		// WooCommerce's own unless a shop opts in; a cart line's limit still
		// counts every copy. The minimum is filtered right before the limit
		// is computed and the maximum right after.
		add_filter( 'woocommerce_store_api_product_quantity_minimum', [ $this, 'mark_catalog_limits' ], PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', [ $this, 'unmark_catalog_limits' ], PHP_INT_MIN, 1 );

		// WooCommerce adds up a cart's quantities per stock id, which would
		// pass a cart holding two language copies of an item with one unit
		// left. Classic cart and checkout, the Store API cart and checkout,
		// and adding to the cart; each refuses with WooCommerce's own notice.
		add_filter( 'woocommerce_cart_item_required_stock_is_not_enough', [ $this, 'cart_item_group_stock_short' ], 10, 3 );
		add_action( 'woocommerce_store_api_validate_cart_item', [ $this, 'validate_store_api_cart_item' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart_group_stock' ], 10, 4 );

		// A sold-individually product is one unit per order, and its language
		// copies are one product. WooCommerce looks for the same cart id only,
		// so the other language's copy went into the same cart. Adding it on
		// the classic form, the Store API cart and checkout, and the classic
		// cart and checkout now refuse or correct that with WooCommerce's own
		// notice. WooCommerce fires the first filter for a sold-individually
		// product only; the other two read one product flag per line.
		add_filter( 'woocommerce_add_to_cart_sold_individually_found_in_cart', [ $this, 'sold_individually_found_in_cart' ], 10, 3 );
		add_action( 'woocommerce_store_api_validate_cart_item', [ $this, 'validate_store_api_sold_individually' ], 10, 2 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'merge_sold_individually_copies' ], 0, 0 );

		// Admin variations panel + REST variation saves: sync the edited
		// variation's shared fields (price / stock flags / dimensions / SKU)
		// to the attribute-matched variation on each sibling-language parent,
		// then roll the sibling parents' derived aggregates (price range,
		// stock status) up via WC_Product_Variable::sync(). Priority 20 so WC
		// has persisted the variation's own meta for this save first. The
		// order-driven stock path above stays separate — it fires from
		// wc_update_product_stock(), not from these save flows.
		add_action( 'woocommerce_save_product_variation', [ $this, 'sync_variation_fields' ], 20, 1 );
		add_action( 'woocommerce_rest_insert_product_variation_object', [ $this, 'sync_on_rest_variation_save' ], 10, 1 );

		// Variations-tab BULK actions (set/adjust prices, sale dates, toggles,
		// dimensions) run plain CRUD saves and never fire
		// woocommerce_save_product_variation — only this dedicated hook. One
		// handler per bulk action; the sibling-parent rollup is amortised to
		// once per bulk run instead of once per variation.
		add_action( 'woocommerce_bulk_edit_variations', [ $this, 'sync_after_bulk_variation_edit' ], 20, 4 );

		// Products-list Quick Edit and Bulk Edit save via CRUD and never fire
		// woocommerce_process_product_meta — without these hooks a price /
		// SKU / dimension change made there silently diverges across
		// languages (stock quantity alone propagated, via the set_stock
		// hooks). Both fire after the CRUD save has persisted the meta.
		add_action( 'woocommerce_product_quick_edit_save', [ $this, 'sync_on_quick_or_bulk_edit' ], 10, 1 );
		add_action( 'woocommerce_product_bulk_edit_save', [ $this, 'sync_on_quick_or_bulk_edit' ], 10, 1 );

		// "In stock?" on a variable product that does not manage stock is
		// saved on each child variation before the parent action above
		// fires. WooCommerce fires this only when a variation's status really
		// changed; the callback records the variation for the parent handler
		// and returns at once outside Quick/Bulk Edit.
		add_action( 'woocommerce_variation_set_stock_status', [ $this, 'note_quick_edit_child' ], 10, 3 );

		// CSV importer rows (products AND variations) — an imported price
		// list otherwise updates only the imported language's products.
		add_action( 'woocommerce_product_import_inserted_product_object', [ $this, 'sync_on_import' ], 10, 2 );

		// Any other update of an existing product or variation through
		// WooCommerce (custom code, another plugin). The data store reports
		// which props really changed while it saves; the handler records the
		// matching shared keys there and copies them once the save has
		// finished. A create fires woocommerce_new_product[_variation]
		// instead, which drops the record. Every save on the order path
		// leaves note_crud_update() before any I/O.
		add_action( 'woocommerce_product_object_updated_props', [ $this, 'note_crud_update' ], 10, 2 );
		add_action( 'woocommerce_update_product', [ $this, 'sync_after_crud_update' ], 10, 1 );
		add_action( 'woocommerce_update_product_variation', [ $this, 'sync_after_crud_update' ], 10, 1 );
		add_action( 'woocommerce_new_product', [ $this, 'forget_crud_update' ], 10, 1 );
		add_action( 'woocommerce_new_product_variation', [ $this, 'forget_crud_update' ], 10, 1 );

		// A save recorded inside a flow with its own sync (OWN_SYNC_FLOWS)
		// waits until that flow's own handlers above have run. Last on each
		// flow's closing action. The two ajax flows end in wp_die(), so theirs
		// is the action WooCommerce fires after its save loop.
		add_action( 'woocommerce_process_product_meta', [ $this, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'woocommerce_product_bulk_and_quick_edit', [ $this, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'woocommerce_ajax_save_product_variations', [ $this, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'woocommerce_bulk_edit_variations', [ $this, 'flush_crud_updates' ], PHP_INT_MAX, 0 );
		add_action( 'perflocale/translation/created', [ $this, 'finish_translation_flow' ], PHP_INT_MAX, 2 );

		// The REST API and the CSV importer hand the object they are about to
		// save through these filters and sync it themselves right after (the
		// handlers above). The claim must run last, so the object claimed is
		// the one WooCommerce saves whatever another filter returns.
		add_filter( 'woocommerce_rest_pre_insert_product_object', [ $this, 'claim_for_flow' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', [ $this, 'claim_for_flow' ], PHP_INT_MAX, 1 );
		add_filter( 'woocommerce_product_import_pre_insert_product_object', [ $this, 'claim_for_flow' ], PHP_INT_MAX, 1 );

		// NB: the shared SKUs this sync writes stay saveable because the WC
		// addon exempts translation siblings from WC's unique-SKU (and GTIN)
		// validation — PerfLocaleWooCommerce::allow_translation_duplicate_sku.
	}

	/**
	 * Sync every variation touched by a Variations-tab bulk action, rolling
	 * the sibling parents up once at the end.
	 *
	 * @param string            $bulk_action Bulk action slug (unused — field diffing is value-based).
	 * @param array<mixed>      $data        Bulk action payload (unused).
	 * @param int|string        $product_id  Parent (variable) product ID.
	 * @param array<int|string> $variations  Affected variation IDs.
	 * @return void
	 */
	public function sync_after_bulk_variation_edit( $bulk_action, $data, $product_id, $variations ): void {
		unset( $bulk_action, $data, $product_id );

		$touched = [];

		foreach ( (array) $variations as $vid ) {
			$touched += $this->sync_variation_fields( (int) $vid, false );
		}

		if ( $touched !== [] ) {
			$this->rollup_sibling_parents( array_keys( $touched ) );
		}
	}

	/**
	 * Record the shared keys a WooCommerce save of a product or variation
	 * changed, for sync_after_crud_update().
	 *
	 * Runs inside the data store's save, for every product and variation save
	 * on the site, each order line's stock save included. Everything up to the
	 * first check that needs the object is array work, so a save that changed
	 * no shared field (a sale, a rating, a rollup) costs no I/O.
	 *
	 * @param mixed $product       Product or variation being saved.
	 * @param mixed $updated_props Props whose stored value the save changed.
	 * @return void
	 */
	public function note_crud_update( $product, $updated_props = [] ): void {
		if ( $this->writing > 0 || ! is_array( $updated_props ) || [] === $updated_props ) {
			return;
		}

		$changed = array_intersect_key( self::CRUD_PROP_KEYS, array_flip( array_filter( $updated_props, 'is_string' ) ) );

		if ( [] === $changed || ! $product instanceof \WC_Product ) {
			return;
		}

		$product_id = (int) $product->get_id();

		// WooCommerce's own relative stock statement for this product has been
		// captured and not replayed yet: this is the save inside
		// wc_update_product_stock() (an order, a refund, an order-screen stock
		// adjustment). The stock hooks own that save.
		if ( $product_id <= 0 || isset( $this->pending_stock_ops[ get_current_blog_id() ][ $product_id ] ) ) {
			return;
		}

		if ( isset( $this->flow_claims[ $product ] ) ) {
			unset( $this->flow_claims[ $product ] );

			return;
		}

		// Derived state never pushes. A variable or grouped product's prices
		// and stock status come from its children; a stock-managed product's
		// status comes from its own quantity. get_manage_stock() is
		// WooCommerce's own test (WC_Product::validate_props) and reads
		// 'parent' for a variation whose parent manages the stock.
		$derived_type = $product->is_type( [ 'variable', 'grouped' ] );
		$keys         = [];

		foreach ( $changed as $prop => $prop_keys ) {
			if ( 'stock_status' === $prop && $product->get_manage_stock() ) {
				continue;
			}

			foreach ( $prop_keys as $key ) {
				if ( $derived_type && ( '_stock_status' === $key || in_array( $key, self::PRICE_KEYS, true ) ) ) {
					continue;
				}

				$keys[ $key ] = true;
			}
		}

		if ( [] === $keys ) {
			return;
		}

		$blog     = get_current_blog_id();
		$previous = $this->crud_pending[ $blog ][ $product_id ]['keys'] ?? [];

		$this->crud_pending[ $blog ][ $product_id ] = [
			'keys'      => array_values( array_unique( array_merge( $previous, array_keys( $keys ) ) ) ),
			'variation' => $product instanceof \WC_Product_Variation,
			'parent'    => (int) $product->get_parent_id(),
		];
	}

	/**
	 * Copy the shared keys a finished WooCommerce save changed to the
	 * translation siblings (see note_crud_update()).
	 *
	 * WooCommerce fires the two hooks this runs on once the save is complete
	 * and the product's caches are cleared. A save with nothing recorded,
	 * every order-path save among them, returns on the first check.
	 *
	 * Inside a flow with its own sync (OWN_SYNC_FLOWS) the record waits for
	 * the flow to end. The flow's full-field sync of the object it edited
	 * drops that object's record (see forget_record()), so nothing is copied
	 * twice; what is left, such as another product a listener on the flow's
	 * hooks saved meanwhile, is copied by flush_crud_updates().
	 *
	 * @param int|string $product_id Saved product or variation ID.
	 * @return void
	 */
	public function sync_after_crud_update( $product_id ): void {
		$product_id = (int) $product_id;
		$blog       = get_current_blog_id();

		// A save that announced a stock change it did not store fired no
		// set_stock action to consume the note.
		unset( $this->announced_stock_ops[ $blog ][ $product_id ] );

		if ( ! isset( $this->crud_pending[ $blog ][ $product_id ] ) ) {
			return;
		}

		// No flow is still running once the request is shutting down, even
		// when one ended in wp_die() and is still on the action stack.
		if ( $this->flow_depth() > 0 && ! doing_action( 'shutdown' ) ) {
			$this->crud_pending[ $blog ][ $product_id ]['waiting'] = true;

			// For a flow that never reaches its closing action.
			if ( ! $this->flush_hooked ) {
				add_action( 'shutdown', [ $this, 'flush_crud_updates' ], 4, 0 );
				$this->flush_hooked = true;
			}

			return;
		}

		$pending = $this->crud_pending[ $blog ][ $product_id ];

		$this->forget_record( $blog, $product_id );
		$this->apply_crud_record( $product_id, $pending );
	}

	/**
	 * Copy what one finished save recorded to the translation siblings.
	 *
	 * @param int                  $product_id Saved product or variation ID.
	 * @param array<string, mixed> $pending    Its record (see $crud_pending).
	 * @return void
	 */
	private function apply_crud_record( int $product_id, array $pending ): void {
		if ( ! $this->sync_enabled() ) {
			return;
		}

		$keys = $pending['keys'];

		// Both checks run before sync_*_fields() takes its first lock.
		if ( ! $this->prices_synced() ) {
			$keys = array_values( array_diff( $keys, self::PRICE_KEYS ) );
		}

		if ( [] === $keys ) {
			return;
		}

		$anchor = $pending['variation'] ? $pending['parent'] : $product_id;
		$repo   = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		if ( $anchor <= 0 || count( $repo->get_translations( $anchor, ObjectType::Post ) ) <= 1 ) {
			return;
		}

		if ( ! $pending['variation'] ) {
			$this->sync_product_fields( $product_id, $keys );

			return;
		}

		$touched = $this->sync_variation_fields( $product_id, false, $keys );

		if ( [] !== $touched ) {
			$this->queue_sibling_rollups( array_keys( $touched ) );
		}
	}

	/**
	 * Drop what a create recorded. WooCommerce reports every prop of a new
	 * product or variation as changed, and a create never starts this sync:
	 * the new object is the copy (a translation clone, a duplicate, an added
	 * variation), not the record the other languages should follow.
	 *
	 * @param int|string $product_id Created product or variation ID.
	 * @return void
	 */
	public function forget_crud_update( $product_id ): void {
		$this->forget_record( get_current_blog_id(), (int) $product_id );
	}

	/**
	 * Drop one save record, and its blog's bucket once that is empty.
	 *
	 * @param int $blog       Blog ID.
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	private function forget_record( int $blog, int $product_id ): void {
		unset( $this->crud_pending[ $blog ][ $product_id ] );

		if ( [] === ( $this->crud_pending[ $blog ] ?? null ) ) {
			unset( $this->crud_pending[ $blog ] );
		}
	}

	/**
	 * How many flows with their own sync (OWN_SYNC_FLOWS) are running.
	 *
	 * @return int
	 */
	private function flow_depth(): int {
		global $wp_current_filter;

		$depth = 0;

		foreach ( (array) $wp_current_filter as $hook ) {
			if ( is_string( $hook ) && isset( self::OWN_SYNC_FLOWS[ $hook ] ) ) {
				++$depth;
			}
		}

		return $depth;
	}

	/**
	 * Copy what the saves recorded inside a flow left for it (see
	 * sync_after_crud_update()), once the flow's own handlers have run.
	 *
	 * Hooked last on each flow's closing action, and to `shutdown` for a flow
	 * that never got there. Inside a flow that another flow is running, the
	 * outer one flushes when it ends.
	 *
	 * @return void
	 */
	public function flush_crud_updates(): void {
		if ( [] === $this->crud_pending ) {
			return;
		}

		if ( $this->flow_depth() > 1 && ! doing_action( 'shutdown' ) ) {
			return;
		}

		foreach ( $this->crud_pending as $blog_id => $records ) {
			$ready = array_filter( $records, static fn( array $record ): bool => ! empty( $record['waiting'] ) );

			if ( [] === $ready ) {
				continue;
			}

			foreach ( array_keys( $ready ) as $id ) {
				$this->forget_record( (int) $blog_id, (int) $id );
			}

			$switch = is_multisite() && (int) $blog_id !== get_current_blog_id();

			if ( $switch ) {
				switch_to_blog( (int) $blog_id );
			}

			try {
				foreach ( $ready as $id => $record ) {
					$this->apply_crud_record( (int) $id, $record );
				}
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * End of a translation's creation: drop what the new copy and its
	 * variations recorded, since the copy is built from the product it
	 * translates and must push nothing back to it, then flush the rest.
	 *
	 * @param int|string $new_id      ID of the translation just created.
	 * @param mixed      $object_type 'post' or 'term'.
	 * @return void
	 */
	public function finish_translation_flow( $new_id, $object_type = '' ): void {
		$blog   = get_current_blog_id();
		$new_id = (int) $new_id;

		if ( 'post' === $object_type && $new_id > 0 ) {
			foreach ( $this->crud_pending[ $blog ] ?? [] as $id => $record ) {
				if ( (int) $id === $new_id || $record['parent'] === $new_id ) {
					$this->forget_record( $blog, (int) $id );
				}
			}
		}

		$this->flush_crud_updates();
	}

	/**
	 * Mark the object a REST or CSV-importer flow is about to save. The CRUD
	 * trigger leaves that save to the flow, which syncs it right after.
	 *
	 * @param mixed $product Object the flow will save.
	 * @return mixed The same object, untouched.
	 */
	public function claim_for_flow( $product ) {
		if ( $product instanceof \WC_Product ) {
			$this->flow_claims[ $product ] = true;
		}

		return $product;
	}

	/**
	 * Roll each queued sibling parent up once.
	 *
	 * Hooked to `shutdown` when the first parent is queued, the way
	 * WooCommerce defers the edited parent's own rollup. Public so it can run
	 * on demand; the queue is emptied first, so a second run does nothing.
	 *
	 * @return void
	 */
	public function run_deferred_rollups(): void {
		$queue                  = $this->deferred_rollups;
		$this->deferred_rollups = [];

		foreach ( $queue as $blog_id => $parents ) {
			$switch = is_multisite() && (int) $blog_id !== get_current_blog_id();

			if ( $switch ) {
				switch_to_blog( (int) $blog_id );
			}

			try {
				$this->rollup_sibling_parents( array_keys( $parents ) );
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Queue sibling parents for one rollup each at the end of the request, so
	 * a loop of variation saves rolls each parent up once rather than once per
	 * variation.
	 *
	 * Rolled up at once instead while switched to another blog (the queue must
	 * not outlive the switch) or once the request is already shutting down.
	 *
	 * @param array<int, int> $parent_ids Sibling parent IDs.
	 * @return void
	 */
	private function queue_sibling_rollups( array $parent_ids ): void {
		if ( ( is_multisite() && ms_is_switched() ) || doing_action( 'shutdown' ) ) {
			$this->rollup_sibling_parents( $parent_ids );

			return;
		}

		$blog = get_current_blog_id();

		foreach ( $parent_ids as $parent_id ) {
			$this->deferred_rollups[ $blog ][ (int) $parent_id ] = true;
		}

		if ( ! $this->rollups_hooked ) {
			add_action( 'shutdown', [ $this, 'run_deferred_rollups' ], 5 );
			$this->rollups_hooked = true;
		}
	}

	/**
	 * Whether the price keys take part in the sync (the wc_sync_prices setting).
	 *
	 * @return bool
	 */
	private function prices_synced(): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		return ! $plugin->has( 'settings' ) || (bool) $plugin->get( 'settings' )->get( 'wc_sync_prices', true );
	}

	/**
	 * Per-product opt-out meta flag ('yes' = this product manages its own
	 * shared fields — nothing syncs INTO it, and its saves push nothing OUT).
	 *
	 * Set from the checkbox in the WooCommerce product Advanced panel (see
	 * the addon's render/save handlers) or programmatically. Lives on the
	 * PRODUCT (variations inherit their parent's flag), is per-language-copy
	 * (flag only the DE product to give it independent pricing while EN↔PL
	 * keep syncing), and is deliberately NOT itself a synced field.
	 */
	public const SYNC_OPTOUT_META = '_perflocale_sync_optout';

	/**
	 * Post meta on a translated variable product whose LOCAL (non-taxonomy)
	 * attribute options were machine-translated: attribute key => [ translated
	 * option => original option ]. Variation matching across languages reads a
	 * translated local value as the original, so stock, price and field sync
	 * keep pairing the variations after their options are translated.
	 */
	public const LOCAL_ATTR_SOURCE_META = '_perflocale_local_attr_source';

	/**
	 * Attribute-signature => variation-id maps of sibling parents, per blog and
	 * parent id (see sibling_variation_map()).
	 *
	 * @var array<int, array<int, array<string, int>>>
	 */
	private static array $variation_maps = [];

	/**
	 * The first child variation of a sibling parent with a given attribute
	 * signature, or 0 for none, per blog, parent id and signature (see
	 * first_sibling_variation()).
	 *
	 * @var array<int, array<int, array<string, int>>>
	 */
	private static array $first_matches = [];

	/**
	 * Transient name prefix of the stored variation maps, followed by the
	 * variable product's id (see stored_first_siblings()).
	 */
	private const STORED_MAP_PREFIX = 'perflocale_vsig_';

	/**
	 * Transient holding the epoch of the stored variation maps: a random
	 * token, replaced after changes that can alter a variation match
	 * (forget_stock_matches()). A stored map is used only while it carries
	 * the current token. It is deleted by name on activation and on every
	 * cache flush (CacheManager::flush_all(), which deactivation runs), so
	 * that a persistent object cache drops it too; a missing epoch retires
	 * every stored map of the blog.
	 */
	public const STORED_MAP_EPOCH = 'perflocale_vsig_epoch';

	/**
	 * Seconds within which a further change in this process marks the epoch
	 * for renewal instead of renewing it at once (forget_stock_matches()).
	 */
	private const EPOCH_RENEW_INTERVAL = 1.0;

	/**
	 * Master switch for cross-language product-data sync.
	 *
	 * @return bool
	 */
	private function sync_enabled(): bool {
		// wc_sync_stock is the long-standing master switch — the WC addon
		// doesn't even register this class when it's off. Re-reading it here
		// is belt-and-braces (a mid-request settings change) and gives the
		// filter a per-request override point.
		$plugin  = \PerfLocale\Plugin::get_instance();
		$enabled = ! $plugin->has( 'settings' ) || (bool) $plugin->get( 'settings' )->get( 'wc_sync_stock', true );

		/** @hook perflocale/woocommerce/inventory_sync_enabled Master switch for cross-language product-data sync (default: the wc_sync_stock setting). */
		return (bool) apply_filters( 'perflocale/woocommerce/inventory_sync_enabled', $enabled );
	}

	/**
	 * Whether a product (or a variation via its parent) is opted out of the
	 * cross-language sync — by the per-product meta flag or by the
	 * long-standing skip filter.
	 *
	 * Checked on BOTH ends of every flow: an opted-out product neither
	 * receives sibling data nor pushes its own on save.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	private function is_sync_opted_out( int $product_id ): bool {
		$flagged = get_post_meta( $product_id, self::SYNC_OPTOUT_META, true ) === 'yes';

		if ( ! $flagged && get_post_type( $product_id ) === 'product_variation' ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			$flagged   = $parent_id > 0 && get_post_meta( $parent_id, self::SYNC_OPTOUT_META, true ) === 'yes';
		}

		/** @hook perflocale/woocommerce/skip_inventory_sync Return true to skip sync for this product (default: the per-product opt-out meta). */
		return (bool) apply_filters( 'perflocale/woocommerce/skip_inventory_sync', $flagged, $product_id );
	}

	/**
	 * The source's values of the fields to copy.
	 *
	 * When a copy re-derives stock status, `_stock_status` comes last, so
	 * keeps_own_status() sees the sibling's `_manage_stock` as just copied.
	 *
	 * @param int               $source_id Source product or variation ID.
	 * @param array<int, mixed> $fields    Meta keys to copy.
	 * @param bool              $derive    Whether the copy re-derives stock status.
	 * @return array<string, mixed> Meta key => value.
	 */
	private function read_source_values( int $source_id, array $fields, bool $derive ): array {
		$values = [];

		foreach ( $fields as $field ) {
			$key            = (string) $field;
			$values[ $key ] = get_post_meta( $source_id, $key, true );
		}

		if ( $derive && array_key_exists( '_stock_status', $values ) ) {
			$status = $values['_stock_status'];

			unset( $values['_stock_status'] );

			$values['_stock_status'] = $status;
		}

		return $values;
	}

	/**
	 * Whether a copy leaves one sibling's `_stock_status` alone: the sibling
	 * manages its own stock, so its status comes from its own quantity
	 * (refresh_derived_stock_status()), never from the source.
	 *
	 * @param bool   $derive     Whether the copy re-derives stock status.
	 * @param string $field      Meta key about to be copied.
	 * @param int    $sibling_id Sibling product or variation ID.
	 * @return bool
	 */
	private function keeps_own_status( bool $derive, string $field, int $sibling_id ): bool {
		return $derive && '_stock_status' === $field && 'yes' === (string) get_post_meta( $sibling_id, '_manage_stock', true );
	}

	/**
	 * Copy shared physical fields to all language variants of a product.
	 *
	 * @param int                     $product_id Post ID of the saved product.
	 * @param array<int, string>|null $only_keys  Copy only these of the synced
	 *                                            fields (a save through code
	 *                                            passes the keys it changed).
	 *                                            Null copies every one.
	 * @return void
	 */
	public function sync_product_fields( int $product_id, ?array $only_keys = null ): void {
		// A full copy covers whatever a save of this product recorded.
		if ( null === $only_keys ) {
			$this->forget_record( get_current_blog_id(), $product_id );
		}

		if ( ! $this->sync_enabled() || $this->is_sync_opted_out( $product_id ) ) {
			return;
		}

		// Atomically acquire a cross-request lock. add_option() is backed by
		// an INSERT against the UNIQUE option_name key, so two concurrent
		// requests cannot both enter this method - the loser bails.
		if ( ! Lock::acquire( $this->lock_name( $product_id ), self::LOCK_TTL_OUTER ) ) {
			return;
		}

		++$this->writing;

		try {
			$base_fields = self::SHARED_FIELDS;
			$price_keys  = self::PRICE_KEYS;

			// Exclude price fields if wc_sync_prices is disabled.
			if ( ! $this->prices_synced() ) {
				$base_fields = array_diff( $base_fields, $price_keys );
			}

			// For a variable parent these price keys are child-derived aggregates
			// WC owns: it stores one _price row per unique child price (min..max)
			// and empties parent _regular_price/_sale_price. Copying them with
			// update_post_meta() would collapse the sibling's multi-row _price
			// index to a single value and corrupt its min/max price lookup, so
			// leave WC to rebuild them from the sibling's own variations.
			$product = wc_get_product( $product_id );

			if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
				$base_fields = array_diff( $base_fields, $price_keys );
			}

			/** @hook perflocale/woocommerce/synced_product_fields Filter which meta keys are synced. */
			$fields = (array) apply_filters( 'perflocale/woocommerce/synced_product_fields', $base_fields, $product_id );

			if ( null !== $only_keys ) {
				$fields = array_values( array_intersect( $fields, $only_keys ) );
			}

			if ( empty( $fields ) ) {
				return;
			}

			// A sibling that manages its own stock never takes the source's
			// `_stock_status`: it re-derives its status from its OWN quantity
			// instead. A partial copy re-derives it after every copied
			// stock-management or backorders setting, or status change. A full
			// copy re-derives it only where it wrote the quantity, the
			// stock-management setting or the backorders setting, or where the
			// sibling's status differs from the source's, so an in-sync
			// sibling costs no query.
			$derive = [] !== array_intersect( $fields, [ '_manage_stock', '_backorders', '_stock_status' ] );

			$repo         = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$translations = $repo->get_translations( $product_id, ObjectType::Post );

			// No sibling translations - nothing to sync.
			if ( count( $translations ) <= 1 ) {
				return;
			}

			// Collect meta values from the canonical (saved) product once.
			$values = $this->read_source_values( $product_id, $fields, $derive );

			$synced_ids = [];

			foreach ( $translations as $link ) {
				$sibling_id = (int) $link->object_id;

				if ( $sibling_id === $product_id || $this->is_sync_opted_out( $sibling_id ) ) {
					continue;
				}

				// Lock the sibling too so save_post hooks fired as a
				// side-effect of update_post_meta/wc_delete_product_transients
				// don't bounce back into this method. If the lock is already
				// held (another request racing on the same sibling) skip it -
				// the other request will finish the sync there.
				if ( ! Lock::acquire( $this->lock_name( $sibling_id ), self::LOCK_TTL_INNER ) ) {
					continue;
				}

				try {
					$changed = false;

					// Whether the sibling manages its own stock and still does
					// after this copy, read before this copy can change the
					// setting. Its quantity then belongs to the stock hooks alone
					// (sync_on_stock_change(): orders as a relative change,
					// explicit stock changes absolutely), so no copy writes its
					// `_stock`. An absolute copy of the source's quantity would
					// overwrite an order's decrement that is committed on the
					// sibling and not yet relayed to the source, and that sale
					// would be lost. A sibling that does not manage its stock
					// takes the source's quantity: no order moves it, and this
					// copy may be about to make it managed. So does a sibling
					// this copy stops managing: once it does not manage its
					// stock, no sale depends on its quantity.
					$owned = 'yes' === get_post_meta( $sibling_id, '_manage_stock', true )
						&& ( ! array_key_exists( '_manage_stock', $values ) || 'yes' === $values['_manage_stock'] );

					$rederive       = null !== $only_keys;
					$stock_written  = false;
					$manage_written = false;

					foreach ( $values as $field => $value ) {
						if ( $owned && '_stock' === $field ) {
							continue;
						}

						// update_post_meta() returns false BOTH on failure AND
						// when the new value equals the stored one — and an
						// already-in-sync sibling is the COMMON case, not an
						// error. Skip the write (and the log) when the value
						// already matches, so WP_DEBUG logs carry only genuine
						// failures instead of dozens of false "failed" lines
						// per product save.
						if ( (string) get_post_meta( $sibling_id, $field, true ) === (string) $value ) {
							continue;
						}

						if ( in_array( $field, [ '_stock', '_stock_status', '_manage_stock', '_backorders' ], true ) ) {
							$rederive = true;
						}

						if ( $this->keeps_own_status( $derive, (string) $field, $sibling_id ) ) {
							continue;
						}

						$result  = update_post_meta( $sibling_id, $field, $value );
						$changed = true;

						if ( '_stock' === $field ) {
							$stock_written = true;
						} elseif ( '_manage_stock' === $field ) {
							$manage_written = true;
						}

						if ( $result === false && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( sprintf( 'PerfLocale InventorySync: update_post_meta failed for sibling %d field %s', $sibling_id, $field ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}

					if ( $derive && $rederive && $this->refresh_derived_stock_status( $sibling_id ) ) {
						$changed = true;
					}

					// A partial copy counts a sibling only when it wrote something.
					if ( null !== $only_keys && ! $changed ) {
						continue;
					}

					// Rebuild the sibling's wc_product_meta_lookup row from the
					// meta just written. update_post_meta() bypasses WC's CRUD
					// change-tracking, so the lookup (which backs catalog
					// price-sort / stock-filter / SKU search) would otherwise go
					// stale. Re-entrancy is safe: the sibling lock is held. Only
					// a copy that wrote `_stock` lets the refresh re-set the
					// quantity, and a full copy that wrote the stock-management
					// setting has it set the stock columns from the committed
					// meta (see refresh_product_lookup()). A partial copy that
					// leaves the sibling stock-managed refreshes them below.
					$this->refresh_product_lookup( $sibling_id, $stock_written, null === $only_keys && $manage_written );

					// The copied setting can have made the sibling stock-managed;
					// its lookup row then needs its own quantity and status.
					if ( $derive && null !== $only_keys && 'yes' === (string) get_post_meta( $sibling_id, '_manage_stock', true ) ) {
						$this->refresh_stock_lookup_columns( $sibling_id );
					}

					$synced_ids[] = $sibling_id;
				} finally {
					Lock::release( $this->lock_name( $sibling_id ) );
				}
			}

			if ( ! empty( $synced_ids ) ) {
				/** @hook perflocale/woocommerce/inventory_synced Fires after inventory sync completes. */
				do_action( 'perflocale/woocommerce/inventory_synced', $product_id, $synced_ids, $fields );
			}
		} finally {
			--$this->writing;
			Lock::release( $this->lock_name( $product_id ) );
		}
	}

	/**
	 * Trigger sync when a product is saved via the WooCommerce REST API.
	 *
	 * @param \WC_Product $product Saved product object.
	 * @return void
	 */
	public function sync_on_rest_save( \WC_Product $product ): void {
		unset( $this->flow_claims[ $product ] );

		$this->sync_product_fields( $product->get_id() );
	}

	/**
	 * Sync stock to translation siblings when WooCommerce changes stock via orders.
	 *
	 * Fires from wc_update_product_stock() during order placement, cancellation,
	 * and refund. Only syncs stock-related fields (not price/weight/dimensions).
	 *
	 * @param \WC_Product $product Product whose stock changed.
	 * @return void
	 */
	public function sync_on_stock_change( \WC_Product $product ): void {
		// Variations are NOT linked into translation groups (they inherit the
		// parent's language — Bootstrap::auto_assign_default_language skips
		// product_variation). So get_translations(variation_id) is always empty
		// and the sibling loop below would bail — meaning an order that
		// decremented a variation's stock never propagated to the sibling-
		// language variations, and every language kept its own stock counter
		// (cross-language oversell). Route variations to the parent-anchored
		// path that matches siblings by attribute set.
		if ( $product instanceof \WC_Product_Variation ) {
			$this->sync_variation_stock( $product );
			return;
		}

		$product_id = $product->get_id();
		// Consume the delta WooCommerce just applied to the SOURCE (see
		// capture_stock_operation). Taken BEFORE any early return so a
		// disabled or opted-out product cannot leave a stale delta behind.
		$delta = $this->take_stock_delta( $product_id );

		if ( ! $this->sync_enabled() || $this->is_sync_opted_out( $product_id ) ) {
			return;
		}

		$repo         = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$translations = $repo->get_translations( $product_id, ObjectType::Post );

		if ( count( $translations ) <= 1 ) {
			return;
		}

		$synced_ids = [];
		$absolute   = [];
		$flipped    = [];

		// PASS 1 — RELATIVE, DELIBERATELY LOCK-FREE.
		//
		// Lock::acquire() is a NON-BLOCKING mutex: on contention it returns
		// false immediately, it never waits. This loop used to sit inside two
		// of them, and each one silently DROPPED a real customer purchase:
		//
		// The OUTER lock, on the source product: two simultaneous orders for
		// the SAME product meant the loser returned early, so its decrement
		// never reached any sibling language at all.
		//
		// The INNER lock, on each sibling: three simultaneous orders for three
		// language copies all want the same two sibling locks, so whoever lost
		// `continue`d and that language kept the old figure.
		//
		// A lock cannot help here even in principle. apply_relative_stock()
		// issues `SET meta_value = meta_value + (delta)`, which InnoDB already
		// serialises on the row; the lock added nothing but a way to lose the
		// write. Locks are for the read-modify-write in pass 2, and only there.
		//
		// A sibling the relative path cannot handle (no numeric `_stock` of its
		// own) is deferred to pass 2 rather than skipped.
		foreach ( $translations as $link ) {
			$sibling_id = (int) $link->object_id;

			if ( $sibling_id === $product_id || $this->is_sync_opted_out( $sibling_id ) ) {
				continue;
			}

			if ( null !== $delta && $this->apply_relative_stock( $sibling_id, $delta ) ) {
				// The sibling's quantity is now ITS OWN, so derive its status
				// from that rather than copying the source's. Both refreshes
				// re-read the quantity inside a single statement, so they are
				// safe to run unlocked and cannot publish a stale figure.
				if ( $this->refresh_derived_stock_status( $sibling_id ) ) {
					$flipped[] = $sibling_id;
				}

				$this->refresh_stock_lookup_columns( $sibling_id );

				$synced_ids[] = $sibling_id;
				continue;
			}

			$absolute[] = $sibling_id;
		}

		// PASS 2 — ABSOLUTE mirror, for siblings pass 1 could not apply to and
		// for every non-order change ('set': admin edit, quick/bulk edit, REST,
		// CLI, resync). This one really is a read-modify-write, so it takes the
		// locks. Dropping a 'set' on contention is safe in a way dropping a
		// delta is not: the winner writes the same absolute value this call
		// would have written.
		if ( ! empty( $absolute ) && Lock::acquire( $this->lock_name( $product_id ), self::LOCK_TTL_OUTER ) ) {
			++$this->writing;

			try {
				$stock_quantity = $product->get_stock_quantity();
				$stock_status   = $product->get_stock_status();

				foreach ( $absolute as $sibling_id ) {
					if ( ! Lock::acquire( $this->lock_name( $sibling_id ), self::LOCK_TTL_INNER ) ) {
						continue;
					}

					try {
						// See the price-sync loop above: update_post_meta()
						// returns false for value-unchanged too, so only treat a
						// write that CHANGED something as a candidate failure —
						// an in-sync sibling is normal, not an error to log.
						$stock_same  = ( (string) get_post_meta( $sibling_id, '_stock', true ) === (string) $stock_quantity );
						$status_same = ( (string) get_post_meta( $sibling_id, '_stock_status', true ) === (string) $stock_status );
						$stock_ok    = $stock_same ? true : update_post_meta( $sibling_id, '_stock', $stock_quantity );
						$status_ok   = $status_same ? true : update_post_meta( $sibling_id, '_stock_status', $stock_status );

						if ( ( $stock_ok === false || $status_ok === false ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( sprintf( 'PerfLocale InventorySync: stock update failed for sibling %d', $sibling_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}

						if ( ! $status_same ) {
							$flipped[] = $sibling_id;
						}

						// Rebuild the sibling's wc_product_meta_lookup row
						// (stock / stock_status). Raw update_post_meta above
						// bypasses WC's change-tracking, so without this every
						// order/refund would leave the translated product showing
						// stale stock in shop queries. Re-entrancy is safe: the
						// sibling lock is held.
						$this->refresh_product_lookup( $sibling_id );

						$synced_ids[] = $sibling_id;
					} finally {
						Lock::release( $this->lock_name( $sibling_id ) );
					}
				}
			} finally {
				--$this->writing;
				Lock::release( $this->lock_name( $product_id ) );
			}
		}

		// A variable product that manages its variations' stock gives them its
		// stock status when WooCommerce saves it, as the save inside
		// wc_update_product_stock() did for the source. The copies were
		// written without a save, so their variations would keep the old
		// status: in stock in every other language after the last unit sold.
		// Only a copy whose status just changed has anything to pass down.
		if ( ! empty( $flipped ) && $product->is_type( 'variable' ) ) {
			$this->sync_managed_children( $flipped );
		}

		if ( ! empty( $synced_ids ) ) {
			/** @hook perflocale/woocommerce/inventory_synced Fires after inventory sync completes. */
			do_action( 'perflocale/woocommerce/inventory_synced', $product_id, $synced_ids, [ '_stock', '_stock_status' ] );
		}
	}

	/**
	 * Give the variations of each variable copy that manages their stock the
	 * copy's stock status, through WooCommerce's own downward sync
	 * (sync_managed_variation_stock_status(), which it runs on every save of
	 * a variable product). A fresh object is read for each copy, since its
	 * `_stock` and `_stock_status` rows were written past WooCommerce's
	 * product cache.
	 *
	 * @param array<int, int> $parent_ids Variable product copies just written.
	 * @return void
	 */
	private function sync_managed_children( array $parent_ids ): void {
		++$this->writing;

		try {
			foreach ( $parent_ids as $parent_id ) {
				if ( 'variable' !== \WC_Product_Factory::get_product_type( (int) $parent_id ) ) {
					continue;
				}

				$parent = new \WC_Product_Variable( (int) $parent_id );
				$store  = $parent->get_data_store();

				if ( $parent->get_manage_stock() && $store->has_callable( 'sync_managed_variation_stock_status' ) ) {
					$store->sync_managed_variation_stock_status( $parent );
				}
			}
		} finally {
			--$this->writing;
		}
	}

	/**
	 * Propagate a variation's stock to the equivalent variation on each
	 * translation-sibling of its PARENT (variations aren't group-linked, so we
	 * anchor on the parent product, which is).
	 *
	 * The sibling variation is located by an EXACT attribute-set match: the
	 * clone step copies attributes verbatim and PerfLocale deliberately keeps
	 * the original attribute terms on translated variations, so the maps are
	 * string-for-string equal. When a translator has manually diverged a
	 * sibling's attributes we skip it (never guess). Same two-pass write path as
	 * the parent flow — a lock-free atomic relative replay, then a locked
	 * absolute mirror; raw update_post_meta does NOT re-fire
	 * woocommerce_variation_set_stock, so there is no re-entrancy loop.
	 *
	 * @param \WC_Product_Variation $variation Variation whose stock changed.
	 * @return void
	 */
	private function sync_variation_stock( \WC_Product_Variation $variation ): void {
		$variation_id = $variation->get_id();
		$parent_id    = $variation->get_parent_id();
		// Consume the delta WooCommerce just applied to the SOURCE variation,
		// before any early return (see capture_stock_operation). A variation
		// whose stock is PARENT-managed never reaches here: WooCommerce fires
		// woocommerce_product_set_stock for the parent instead, and the parent
		// path above carries the parent's own delta.
		$delta = $this->take_stock_delta( $variation_id );

		if ( $parent_id <= 0 ) {
			return;
		}

		if ( ! $this->sync_enabled() || $this->is_sync_opted_out( $variation_id ) ) {
			return;
		}

		$repo        = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$parent_sibs = $repo->get_translations( $parent_id, ObjectType::Post );

		if ( count( $parent_sibs ) <= 1 ) {
			return;
		}

		$src_attrs  = $this->normalise_variation_attrs( $variation->get_attributes(), (int) $parent_id );
		$synced_ids = [];
		$absolute   = [];

		// PASS 1 — RELATIVE, LOCK-FREE. See sync_on_stock_change() for the full
		// reasoning: Lock::acquire() never waits, so a lock around an already
		// atomic `meta_value + delta` could only ever DROP a customer's
		// purchase. Two shoppers buying the same variation in two languages hit
		// exactly that.
		foreach ( $parent_sibs as $link ) {
			$sib_parent_id = (int) $link->object_id;

			if ( $sib_parent_id === $parent_id || $this->is_sync_opted_out( $sib_parent_id ) ) {
				continue;
			}

			$sib_variation_id = $this->match_sibling_variation( $sib_parent_id, $src_attrs );

			// A variation-level opt-out must block INCOMING writes too, not
			// only outgoing ones — the source-side gate reads the same flag,
			// so checking just the parent here would leave the key
			// protecting a variation in one direction only. Placed after
			// the match so the cheaper parent-level skip short-circuits
			// first and this meta read only happens on real candidates.
			if ( $sib_variation_id <= 0 || $sib_variation_id === $variation_id || $this->is_sync_opted_out( $sib_variation_id ) ) {
				continue;
			}

			if ( null !== $delta && $this->apply_relative_stock( $sib_variation_id, $delta ) ) {
				$this->refresh_derived_stock_status( $sib_variation_id );
				$this->refresh_stock_lookup_columns( $sib_variation_id );

				$synced_ids[] = $sib_variation_id;
				continue;
			}

			$absolute[] = $sib_variation_id;
		}

		// PASS 2 — ABSOLUTE mirror (read-modify-write), so it takes the locks.
		if ( ! empty( $absolute ) && Lock::acquire( $this->lock_name( $variation_id ), self::LOCK_TTL_OUTER ) ) {
			++$this->writing;

			try {
				$stock_quantity = $variation->get_stock_quantity();
				$stock_status   = $variation->get_stock_status();

				foreach ( $absolute as $sib_variation_id ) {
					if ( ! Lock::acquire( $this->lock_name( $sib_variation_id ), self::LOCK_TTL_INNER ) ) {
						continue;
					}

					try {
						// Change-only writes: update_post_meta returns false for
						// an unchanged value too, so only a real change is a
						// candidate failure (mirrors the parent flow).
						$stock_same  = ( (string) get_post_meta( $sib_variation_id, '_stock', true ) === (string) $stock_quantity );
						$status_same = ( (string) get_post_meta( $sib_variation_id, '_stock_status', true ) === (string) $stock_status );
						$stock_ok    = $stock_same ? true : update_post_meta( $sib_variation_id, '_stock', $stock_quantity );
						$status_ok   = $status_same ? true : update_post_meta( $sib_variation_id, '_stock_status', $stock_status );

						if ( ( $stock_ok === false || $status_ok === false ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( sprintf( 'PerfLocale InventorySync: variation stock update failed for sibling %d', $sib_variation_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}

						// Rebuild the sibling variation's wc_product_meta_lookup
						// row so shop/stock queries reflect the new stock
						// immediately.
						$this->refresh_product_lookup( $sib_variation_id );

						$synced_ids[] = $sib_variation_id;
					} finally {
						Lock::release( $this->lock_name( $sib_variation_id ) );
					}
				}
			} finally {
				--$this->writing;
				Lock::release( $this->lock_name( $variation_id ) );
			}
		}

		if ( ! empty( $synced_ids ) ) {
			/** @hook perflocale/woocommerce/inventory_synced Fires after inventory sync completes. */
			do_action( 'perflocale/woocommerce/inventory_synced', $variation_id, $synced_ids, [ '_stock', '_stock_status' ] );
		}
	}

	/**
	 * The ids whose stock is one physical stock with a stock id: itself, and
	 * each language copy that manages its own stock too. A variation's copies
	 * are the attribute-matched variations of its parent's copies (see
	 * stored_first_siblings() and first_sibling_variation()). A copy opted
	 * out of the sync, or one that does not manage its own stock, keeps its
	 * own stock; an opted-out id has no copies.
	 *
	 * Memoised per request and per blog, and bounded like the variation map.
	 * A shop that counts the copies in Store API product data too (see
	 * mark_catalog_limits()) asks for them on catalogue renders, once per
	 * stock-managed product, so the first call for a product reads its
	 * copies in a fixed number of queries, never one per copy or per
	 * variation.
	 *
	 * @param int $stock_id The id WooCommerce keeps the stock on
	 *                      (WC_Product::get_stock_managed_by_id()).
	 * @return array<int, int> Ascending ids, $stock_id included.
	 */
	private function stock_group( int $stock_id ): array {
		$this->drop_memos_after_link_changes();

		$blog = get_current_blog_id();

		if ( isset( $this->stock_groups[ $blog ][ $stock_id ] ) ) {
			return $this->stock_groups[ $blog ][ $stock_id ];
		}

		$group = [ $stock_id ];

		if ( 'yes' === (string) get_post_meta( $stock_id, '_manage_stock', true ) && ! $this->is_sync_opted_out( $stock_id ) ) {
			$copies = $this->stock_copies( $stock_id );

			// The checks below read each copy's post and meta: one query each
			// for all of them, whatever the number of languages.
			if ( [] !== $copies ) {
				_prime_post_caches( $copies, false, true );
			}

			foreach ( $copies as $copy_id ) {
				if ( 'yes' === (string) get_post_meta( $copy_id, '_manage_stock', true ) && ! $this->is_sync_opted_out( $copy_id ) ) {
					$group[] = $copy_id;
				}
			}
		}

		$group = array_values( array_unique( $group ) );
		sort( $group );

		if ( count( $this->stock_groups[ $blog ] ?? [] ) >= 256 ) {
			unset( $this->stock_groups[ $blog ] );
		}

		$this->stock_groups[ $blog ][ $stock_id ] = $group;

		return $group;
	}

	/**
	 * The language copies of a product, or the matching variations of the
	 * language copies of a variation's parent. $product_id itself excluded.
	 *
	 * @param int $product_id Product or variation id.
	 * @return array<int, int>
	 */
	private function stock_copies( int $product_id ): array {
		/**
		 * Translation group repository.
		 *
		 * @var \PerfLocale\Database\Repository\TranslationGroupRepository $repo
		 */
		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		if ( 'product_variation' !== get_post_type( $product_id ) ) {
			$copies = [];

			foreach ( $repo->get_translations( $product_id, ObjectType::Post ) as $link ) {
				$copies[] = (int) $link->object_id;
			}

			return array_values( array_diff( $copies, [ $product_id ] ) );
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );
		$links     = $parent_id > 0 ? $repo->get_translations( $parent_id, ObjectType::Post ) : [];

		if ( count( $links ) <= 1 || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$src = $this->stored_variation_attributes( $product_id );

		if ( null === $src ) {
			$variation = wc_get_product( $product_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				return [];
			}

			$src = [];

			foreach ( (array) $variation->get_attributes() as $key => $value ) {
				$src[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		$copies  = [];
		$parents = is_array( $links ) ? array_values( array_diff( wp_parse_id_list( wp_list_pluck( $links, 'object_id' ) ), [ $parent_id ] ) ) : [];

		if ( [] !== $parents ) {
			// The source's and the copy parents' posts, terms and meta in one
			// query each, for the opt-out check, the variation maps and the
			// values below.
			_prime_post_caches( array_merge( [ $parent_id ], $parents ), true, true );

			// The copy parents' children lists (variable_children()) and stored
			// variation maps (stored_first_siblings()) in one read.
			$this->prime_variable_children( $parents );

			// Every attribute term of those parents resolved to its translation
			// group in one query, so neither side looks a term or a group up
			// value by value.
			$this->prime_canonical_attr_values( array_merge( [ $parent_id ], $parents ), array_map( 'strval', array_keys( $src ) ) );
		}

		$attrs  = $this->normalise_variation_attrs( $src, $parent_id );
		$wanted = [];

		foreach ( $links as $link ) {
			$copy_parent = (int) $link->object_id;

			if ( $copy_parent !== $parent_id && ! $this->is_sync_opted_out( $copy_parent ) ) {
				$wanted[] = $copy_parent;
			}
		}

		// The copy parents' stored variation maps first; a parent they do not
		// answer is scanned.
		$stored = [] !== $wanted ? $this->stored_first_siblings( $wanted, $attrs ) : [];

		foreach ( $wanted as $copy_parent ) {
			$copy_id = $stored[ $copy_parent ] ?? $this->first_sibling_variation( $copy_parent, $attrs );

			if ( $copy_id > 0 && $copy_id !== $product_id ) {
				$copies[] = $copy_id;
			}
		}

		return $copies;
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
	 * Note that a Store API request starts running.
	 *
	 * @param \WP_REST_Request $request      The request.
	 * @param bool             $display_read Whether it is a display cart read (is_display_cart_read()).
	 * @param bool             $rest         Whether core's REST server dispatches it (open_store_api_frame()).
	 * @return void
	 */
	public function enter_store_api_request( \WP_REST_Request $request, bool $display_read, bool $rest = false ): void {
		$this->store_api_frames[] = [ \WeakReference::create( $request ), $display_read, get_current_blog_id(), $rest ];
	}

	/**
	 * Note that a Store API request has finished: its frame goes, with every
	 * frame above it (a nested request whose closing filter never ran). A
	 * request with no frame changes nothing.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return void
	 */
	public function leave_store_api_request( \WP_REST_Request $request ): void {
		for ( $i = count( $this->store_api_frames ) - 1; $i >= 0; --$i ) {
			if ( $this->store_api_frames[ $i ][0]->get() === $request ) {
				array_splice( $this->store_api_frames, $i );

				return;
			}
		}
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
	public function open_store_api_frame( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		unset( $handler );

		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wc/store/' ) ) {
			$this->enter_store_api_request( $request, self::is_display_cart_read( $request ), true );
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
	public function close_store_api_frame( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		unset( $handler );

		if ( $request instanceof \WP_REST_Request && [] !== $this->store_api_frames ) {
			$this->leave_store_api_request( $request );
		}

		return $response;
	}

	/**
	 * Note that a stock hold for an order has started.
	 *
	 * Filters `woocommerce_order_hold_stock_minutes`, which WooCommerce
	 * applies once at the start of every order's stock hold, before its hold
	 * statement.
	 *
	 * @param mixed $minutes Hold duration, returned untouched.
	 * @return mixed
	 */
	public function note_stock_hold( mixed $minutes ): mixed {
		$this->stock_hold_started = true;

		return $minutes;
	}

	/**
	 * Whether the innermost running Store API request on this blog is a
	 * display cart read and no stock hold has started in this request.
	 * Frames whose request is gone are dropped first. A read dispatched by
	 * core's REST server counts only while this HTTP request is itself a REST
	 * request (Helper::is_rest_request()): in a page request its frame is
	 * one a throwable left behind when it escaped the dispatch, or a read the
	 * page asked for itself, and neither exempts a check.
	 *
	 * @return bool
	 */
	private function in_display_cart_read(): bool {
		if ( $this->stock_hold_started ) {
			return false;
		}

		while ( [] !== $this->store_api_frames && null === $this->store_api_frames[ array_key_last( $this->store_api_frames ) ][0]->get() ) {
			array_pop( $this->store_api_frames );
		}

		if ( [] === $this->store_api_frames ) {
			return false;
		}

		$top = $this->store_api_frames[ array_key_last( $this->store_api_frames ) ];

		return true === $top[1] && $top[2] === get_current_blog_id() && ( true !== $top[3] || \PerfLocale\Helper::is_rest_request() );
	}

	/**
	 * Count the pending stock holds of every language copy against one copy.
	 *
	 * Filters `woocommerce_query_for_reserved_stock`. WooCommerce's query
	 * sums the unexpired holds of pending and draft orders for one stock id;
	 * this widens exactly that one predicate to the copies' ids. Everything
	 * else stays WooCommerce's: the order exclusion, the status and expiry
	 * conditions and its FOR UPDATE, and the rows themselves, which keep the
	 * id each order holds, so release, expiry and refunds are unchanged.
	 *
	 * A query without the predicate exactly once (another plugin rewrote it,
	 * or WooCommerce changed its shape) is returned untouched, as is the
	 * query of an id with no copies, the one query asked for Store API
	 * product data outside a cart (see mark_catalog_limits()), and every
	 * query asked while a display cart read runs (in_display_cart_read()):
	 * the Mini-Cart, the product blocks' cart state and every REST read of the
	 * cart (the browser's refreshes, on the cart and checkout pages too) show
	 * WooCommerce's own figures, while every cart change, the first render of
	 * the cart and checkout pages, the checkout's hold and pay-for-order count
	 * every copy.
	 *
	 * @param mixed $query            WooCommerce's hold-sum query.
	 * @param mixed $product_id       Stock id the holds are summed for.
	 * @param mixed $exclude_order_id Order whose own holds are left out.
	 * @return mixed
	 */
	public function group_reserved_stock_query( $query, $product_id = 0, $exclude_order_id = 0 ) {
		unset( $exclude_order_id );

		$product_id = (int) $product_id;

		if ( $product_id > 0 && $product_id === $this->catalog_stock_id ) {
			$this->catalog_stock_id = 0;

			return $query;
		}

		if ( $this->in_display_cart_read() ) {
			return $query;
		}

		if ( ! is_string( $query ) || $product_id <= 0 || ! $this->sync_enabled() ) {
			return $query;
		}

		$group = $this->stock_group( $product_id );

		if ( count( $group ) <= 1 ) {
			return $query;
		}

		$pattern = '/stock_table\.`product_id` = ' . $product_id . '(?![0-9])/';

		if ( 1 !== preg_match_all( $pattern, $query ) ) {
			return $query;
		}

		$widened = preg_replace( $pattern, 'stock_table.`product_id` IN (' . implode( ',', $group ) . ')', $query, 1 );

		return is_string( $widened ) ? $widened : $query;
	}

	/**
	 * Note the stock id of a product whose Store API quantity limit is about
	 * to be computed for product data outside a cart.
	 *
	 * Filters `woocommerce_store_api_product_quantity_minimum`, which the
	 * Store API applies right before it asks for the product's held stock.
	 * That one hold-sum query is then left as WooCommerce builds it: product
	 * blocks and the products route show a limit, and every step where money
	 * is at stake (a cart line's limit, the cart and add-to-cart checks, the
	 * checkout's hold, pay-for-order) still counts the holds of every copy.
	 * The note is dropped by that query, by the maximum filtered right after
	 * it (unmark_catalog_limits()), and by the next limit computed.
	 *
	 * @param mixed $minimum   Minimum quantity, returned untouched.
	 * @param mixed $product   Product the limits are for.
	 * @param mixed $cart_item Cart line the limits are for, or null.
	 * @return mixed
	 */
	public function mark_catalog_limits( $minimum, $product = null, $cart_item = null ) {
		$this->catalog_stock_id = 0;

		if ( null !== $cart_item || ! $product instanceof \WC_Product ) {
			return $minimum;
		}

		/**
		 * Filter whether Store API product data outside a cart counts the
		 * pending stock holds of every language copy of a product.
		 *
		 * Off by default: the product blocks and the products route then show
		 * WooCommerce's own quantity limit and cost no extra query, while the
		 * cart, add-to-cart, checkout and pay-for-order checks count every
		 * copy either way. Return true for exact limits in product data too.
		 *
		 * @hook perflocale/woocommerce/count_shared_holds_in_catalog
		 * @param bool        $count   Default false.
		 * @param \WC_Product $product Product or variation the limit is for.
		 */
		if ( ! (bool) apply_filters( 'perflocale/woocommerce/count_shared_holds_in_catalog', false, $product ) ) {
			$this->catalog_stock_id = (int) $product->get_stock_managed_by_id();
		}

		return $minimum;
	}

	/**
	 * Drop the note of mark_catalog_limits() once the limit is computed.
	 *
	 * Filters `woocommerce_store_api_product_quantity_maximum`, first.
	 *
	 * @param mixed $maximum Maximum quantity, returned untouched.
	 * @return mixed
	 */
	public function unmark_catalog_limits( $maximum ) {
		$this->catalog_stock_id = 0;

		return $maximum;
	}

	/**
	 * Units of a product's shared stock in the cart, when the cart holds
	 * another language copy of it.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param float       $adding  Units about to be added on top.
	 * @return float|null Units of every copy together, or null when no other
	 *                    copy is in the cart (WooCommerce's own per-id count
	 *                    is then the whole story).
	 */
	private function shared_units_in_cart( \WC_Product $product, float $adding = 0.0 ): ?float {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart || ! $product->managing_stock() || $product->backorders_allowed() ) {
			return null;
		}

		$quantities = WC()->cart->get_cart_item_quantities();

		// A cart of one stock id holds no second copy of anything.
		if ( count( $quantities ) < ( $adding > 0 ? 1 : 2 ) || ! $this->sync_enabled() ) {
			return null;
		}

		$stock_id = (int) $product->get_stock_managed_by_id();
		$others   = 0.0;

		foreach ( $this->stock_group( $stock_id ) as $id ) {
			if ( $id !== $stock_id ) {
				$others += (float) ( $quantities[ $id ] ?? 0 );
			}
		}

		if ( $others <= 0 ) {
			return null;
		}

		return $others + (float) ( $quantities[ $stock_id ] ?? 0 ) + $adding;
	}

	/**
	 * Classic cart and checkout: check a cart line against the units of every
	 * language copy in the cart.
	 *
	 * Filters `woocommerce_cart_item_required_stock_is_not_enough`, so a
	 * shortfall shows WooCommerce's own "not enough in stock" notice. Held
	 * stock is WooCommerce's figure for the same order it excludes.
	 *
	 * @param mixed $not_enough WooCommerce's verdict for this stock id alone.
	 * @param mixed $product    Product of the cart line.
	 * @param mixed $values     Cart line.
	 * @return mixed True when the shared stock cannot cover the cart.
	 */
	public function cart_item_group_stock_short( $not_enough, $product = null, $values = [] ) {
		unset( $values );

		if ( $not_enough || ! $product instanceof \WC_Product || ! function_exists( 'wc_get_held_stock_quantity' ) ) {
			return $not_enough;
		}

		$required = $this->shared_units_in_cart( $product );

		if ( null === $required ) {
			return $not_enough;
		}

		$session  = WC()->session;
		$order_id = 0;

		if ( $session ) {
			$order_id = isset( $session->order_awaiting_payment ) ? absint( $session->order_awaiting_payment ) : absint( $session->get( 'store_api_draft_order', 0 ) );
		}

		return (float) $product->get_stock_quantity() < (float) wc_get_held_stock_quantity( $product, $order_id ) + $required;
	}

	/**
	 * Store API cart and checkout: the same check for a cart line.
	 *
	 * The Store API skips the classic check, and reports a shortfall through
	 * the exception its own stock check throws, so the shopper sees
	 * WooCommerce's own message. A display cart read (in_display_cart_read())
	 * is left to WooCommerce's own check: every cart change, the first render
	 * of the cart and checkout pages and the checkout run this one.
	 *
	 * @param mixed $product   Product of the cart line.
	 * @param mixed $cart_item Cart line.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\PartialOutOfStockException When the shared stock cannot cover the cart.
	 */
	public function validate_store_api_cart_item( $product, $cart_item = [] ): void {
		unset( $cart_item );

		if ( ! $product instanceof \WC_Product || ! class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\PartialOutOfStockException::class ) || ! class_exists( \Automattic\WooCommerce\Checkout\Helpers\ReserveStock::class ) ) {
			return;
		}

		if ( $this->in_display_cart_read() ) {
			return;
		}

		$required = $this->shared_units_in_cart( $product );

		if ( null === $required ) {
			return;
		}

		$draft_id = WC()->session ? absint( WC()->session->get( 'store_api_draft_order', 0 ) ) : 0;
		$held     = ( new \Automattic\WooCommerce\Checkout\Helpers\ReserveStock() )->get_reserved_stock( $product, $draft_id );

		if ( (float) $product->get_stock_quantity() - (float) $held < $required ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\PartialOutOfStockException( 'woocommerce_rest_product_partially_out_of_stock', $product->get_name() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A product name WooCommerce builds its own message from, as its own stock check does.
		}
	}

	/**
	 * Adding to the cart (classic form and Store API): refuse units the shared
	 * stock cannot cover when the cart holds another language copy.
	 *
	 * The notice is WooCommerce's own: its classic stock check is run on the
	 * cart lines of this stock plus the new one, for that one call, and the
	 * cart's lines are put back before returning.
	 *
	 * @param mixed $passed       Whether validation passed so far.
	 * @param mixed $product_id   Product id being added.
	 * @param mixed $quantity     Units being added.
	 * @param mixed $variation_id Variation id being added, or 0.
	 * @return mixed False when the units cannot be added.
	 */
	public function validate_add_to_cart_group_stock( $passed, $product_id = 0, $quantity = 1, $variation_id = 0 ) {
		if ( ! $passed || ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_add_notice' ) ) {
			return $passed;
		}

		$product = wc_get_product( (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id );
		$adding  = (float) wc_stock_amount( $quantity );

		if ( ! $product instanceof \WC_Product || $adding <= 0 || ! $product->is_in_stock() || null === $this->shared_units_in_cart( $product, $adding ) ) {
			return $passed;
		}

		$cart  = WC()->cart;
		$group = $this->stock_group( (int) $product->get_stock_managed_by_id() );
		$lines = [
			'perflocale-add-to-cart' => [
				'key'          => 'perflocale-add-to-cart',
				'product_id'   => (int) $product_id,
				'variation_id' => (int) $variation_id,
				'quantity'     => $adding,
				'data'         => $product,
			],
		];

		$contents = $cart->cart_contents;

		foreach ( $contents as $key => $line ) {
			if ( isset( $line['data'] ) && $line['data'] instanceof \WC_Product && in_array( (int) $line['data']->get_stock_managed_by_id(), $group, true ) ) {
				$lines[ $key ] = $line;
			}
		}

		$cart->cart_contents = $lines;

		try {
			$result = $cart->check_cart_item_stock();
		} finally {
			$cart->cart_contents = $contents;
		}

		if ( ! is_wp_error( $result ) ) {
			return $passed;
		}

		wc_add_notice( $result->get_error_message(), 'error' );

		return false;
	}

	/**
	 * Cart lines holding another language copy of a sold-individually
	 * product: the linked copies of a product, or the attribute-matched
	 * variations of its parent's copies (see stock_copies()), whether or not
	 * they manage stock. A product opted out of the sync, an opted-out copy
	 * and a copy that is not sold individually itself stay separate.
	 *
	 * @param int    $id    Product id, or variation id for a variation.
	 * @param string $until Cart key to stop at (that line and the lines after
	 *                      it are not looked at), or '' for the whole cart.
	 * @return array<int, string> Keys of those lines, in cart order.
	 */
	private function copy_lines_in_cart( int $id, string $until = '' ): array {
		if ( $id <= 0 || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return [];
		}

		$copies = null;
		$synced = null;
		$primed = false;
		$keys   = [];

		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( (string) $key === $until ) {
				break;
			}

			$line_id = (int) ( ! empty( $line['variation_id'] ) ? $line['variation_id'] : ( $line['product_id'] ?? 0 ) );

			if ( $line_id <= 0 || $line_id === $id || (float) ( $line['quantity'] ?? 0 ) <= 0
				|| ! ( $line['data'] ?? null ) instanceof \WC_Product || ! $line['data']->is_sold_individually() ) {
				continue;
			}

			// A product opted out of the sync, or a shop with it off, has no copies.
			$synced ??= $this->sync_enabled() && ! $this->is_sync_opted_out( $id );

			if ( ! $synced ) {
				return [];
			}

			// Only a line of the product's translation group can hold a copy
			// (may_be_copy_line()); no other line needs the copies looked up.
			if ( ! $this->may_be_copy_line( $id, $line_id, $primed ) ) {
				continue;
			}

			if ( null === $copies ) {
				$copies = array_values( array_filter( $this->stock_copies( $id ), fn( $copy ) => ! $this->is_sync_opted_out( (int) $copy ) ) );
			}

			if ( [] === $copies ) {
				return [];
			}

			if ( in_array( $line_id, $copies, true ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * Whether an earlier cart line can hold a language copy of a product, read
	 * from the translation groups alone: the line's product is in the
	 * product's group, or, for a variation, the line is a variation whose
	 * parent is in the group of the variation's parent and is another
	 * product. stock_copies() returns only such ids. The groups of every
	 * sold-individually line in the cart are read in one query per request,
	 * gathered once per pass over the cart ($primed); each pair then reads
	 * its two groups from the repository's cache. When a read fails the
	 * answer is true, so the lines are compared as stock_copies() compares
	 * them.
	 *
	 * @param int  $id      Product id, or variation id for a variation.
	 * @param int  $line_id Product or variation id of an earlier cart line.
	 * @param bool $primed  Whether this pass over the cart read the groups of
	 *                      its sold-individually lines already; set here.
	 * @return bool
	 */
	private function may_be_copy_line( int $id, int $line_id, bool &$primed ): bool {
		$anchor      = $id;
		$line_anchor = $line_id;

		if ( 'product_variation' === get_post_type( $id ) ) {
			if ( 'product_variation' !== get_post_type( $line_id ) ) {
				return false;
			}

			$anchor      = (int) wp_get_post_parent_id( $id );
			$line_anchor = (int) wp_get_post_parent_id( $line_id );

			if ( $anchor <= 0 || $line_anchor <= 0 || $line_anchor === $anchor ) {
				return false;
			}
		}

		/**
		 * Translation group repository.
		 *
		 * @var \PerfLocale\Database\Repository\TranslationGroupRepository $repo
		 */
		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		if ( ! $primed ) {
			$primed = true;
			$repo->prime_find_for_objects( array_merge( [ $anchor ], $this->sold_individually_anchors() ), ObjectType::Post );
		}

		if ( ! $repo->prime_find_for_objects( [ $anchor, $line_anchor ], ObjectType::Post ) ) {
			return true;
		}

		$group = self::group_id( $repo->find_for_object( $anchor, ObjectType::Post ) );

		return $group > 0 && self::group_id( $repo->find_for_object( $line_anchor, ObjectType::Post ) ) === $group;
	}

	/**
	 * The id whose translation group holds each sold-individually line in the
	 * cart: the product, or a variation's parent.
	 *
	 * @return array<int, int>
	 */
	private function sold_individually_anchors(): array {
		$ids = [];

		foreach ( WC()->cart->get_cart() as $line ) {
			$product = $line['data'] ?? null;

			if ( ! $product instanceof \WC_Product || ! $product->is_sold_individually() ) {
				continue;
			}

			$pid   = (int) $product->get_id();
			$ids[] = 'product_variation' === get_post_type( $pid ) ? (int) wp_get_post_parent_id( $pid ) : $pid;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Adding a sold-individually product on the classic form: another
	 * language copy in the cart counts as the product itself, so WooCommerce
	 * refuses with its own "cannot add another" notice.
	 *
	 * @param mixed $found        Whether WooCommerce found the product in the cart.
	 * @param mixed $product_id   Product id being added.
	 * @param mixed $variation_id Variation id being added, or 0.
	 * @return mixed
	 */
	public function sold_individually_found_in_cart( $found, $product_id = 0, $variation_id = 0 ) {
		if ( $found ) {
			return $found;
		}

		return [] !== $this->copy_lines_in_cart( (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id );
	}

	/**
	 * Store API cart and checkout: a line of a sold-individually product that
	 * follows a line of another language copy is one unit too many, reported
	 * through the exception WooCommerce's own check throws. The message names
	 * the line as the Store API cart shows it: get_title(), which the add-on's
	 * cart window maps to the current language's copy.
	 *
	 * @param mixed $product   Product of the cart line.
	 * @param mixed $cart_item Cart line.
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\TooManyInCartException When an earlier line holds a copy.
	 */
	public function validate_store_api_sold_individually( $product, $cart_item = [] ): void {
		if ( ! $product instanceof \WC_Product || ! $product->is_sold_individually() || ! is_array( $cart_item ) || empty( $cart_item['key'] ) || ! class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\TooManyInCartException::class ) ) {
			return;
		}

		if ( [] !== $this->copy_lines_in_cart( (int) $product->get_id(), (string) $cart_item['key'] ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\TooManyInCartException( 'woocommerce_rest_product_too_many_in_cart', $product->get_title() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A product name WooCommerce builds its own message from, as its own check does.
		}
	}

	/**
	 * Classic cart and checkout: fold a line of a sold-individually product
	 * into the first line of another language copy. That line then holds
	 * more than one unit, which WooCommerce's own check, running next on the
	 * same action, puts back to one with its own notice; checkout stops on
	 * that notice. Only where that check is hooked (the Store API unhooks it
	 * and reports the same cart through validate_store_api_sold_individually()).
	 *
	 * @return void
	 */
	public function merge_sold_individually_copies(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}

		$cart = WC()->cart;

		if ( ! method_exists( $cart, 'check_cart_item_sold_individually' ) || false === has_action( 'woocommerce_check_cart_items', [ $cart, 'check_cart_items' ] ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $line ) {
			$product = $line['data'] ?? null;

			if ( ! $product instanceof \WC_Product || ! $product->is_sold_individually() ) {
				continue;
			}

			$earlier = $this->copy_lines_in_cart( (int) $product->get_id(), (string) $key );

			if ( [] === $earlier || ! isset( $cart->cart_contents[ $earlier[0] ] ) ) {
				continue;
			}

			$cart->cart_contents[ $earlier[0] ]['quantity'] += $line['quantity'];
			$cart->remove_cart_item( $key );
		}
	}

	/**
	 * Variation fields treated as shared product facts across languages.
	 *
	 * Unlike the variable PARENT (where price keys are child-derived
	 * aggregates WC owns and this class must not copy), a variation's
	 * `_price` is a real single value — safe and necessary to mirror.
	 * total_sales is per-variant by design (see SHARED_FIELDS note).
	 */
	private const VARIATION_FIELDS = [
		'_stock',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_sku',
		'_global_unique_id',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_virtual',
		'_downloadable',
	];

	/**
	 * Trigger the variation field sync for REST variation saves.
	 *
	 * @param \WC_Product $variation Saved variation object.
	 * @return void
	 */
	public function sync_on_rest_variation_save( \WC_Product $variation ): void {
		unset( $this->flow_claims[ $variation ] );

		$this->sync_variation_fields( $variation->get_id() );
	}

	/**
	 * Trigger sync after a products-list Quick Edit / Bulk Edit save.
	 *
	 * @param \WC_Product $product Saved product.
	 * @return void
	 */
	public function sync_on_quick_or_bulk_edit( \WC_Product $product ): void {
		// Bulk Edit changes stock through wc_update_product_stock( ..., true ),
		// which still fires the set_stock action, so sync_on_stock_change()
		// has normally consumed the delta already. Taking it again makes sure
		// nothing captured during this save outlives it.
		$this->take_stock_delta( $product->get_id() );

		$this->sync_product_fields( $product->get_id() );

		// Variations whose stock status this edit changed (see
		// note_quick_edit_child). The parent copy above carries only the
		// parent's derived status, so sync each recorded variation, then
		// roll every touched sibling parent up once so its status is derived
		// from its own children again.
		$blog       = get_current_blog_id();
		$product_id = $product->get_id();
		$children   = $this->quick_edit_children[ $blog ][ $product_id ] ?? [];

		unset( $this->quick_edit_children[ $blog ][ $product_id ] );

		if ( [] === $children ) {
			return;
		}

		// An untranslated product has no sibling variations. Checking here
		// keeps each child's sync from taking its lock only to find that out.
		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		if ( count( $repo->get_translations( $product_id, ObjectType::Post ) ) <= 1 ) {
			return;
		}

		$touched = [];

		foreach ( array_keys( $children ) as $variation_id ) {
			$touched += $this->sync_variation_fields( (int) $variation_id, false );
		}

		if ( [] !== $touched ) {
			$this->rollup_sibling_parents( array_keys( $touched ) );
		}
	}

	/**
	 * Record a variation whose stock status a Quick Edit or Bulk Edit of its
	 * parent just changed, for sync_on_quick_or_bulk_edit().
	 *
	 * Only a variation that does not manage its own stock is recorded: its
	 * status is the value the operator chose. A stock-managed variation's
	 * status follows its own quantity, which the stock hooks already sync.
	 *
	 * @param int|string $variation_id Variation ID.
	 * @param mixed      $status       New stock status (unused; the sync reads the saved meta).
	 * @param mixed      $variation    Saved variation object.
	 * @return void
	 */
	public function note_quick_edit_child( $variation_id, $status = '', $variation = null ): void {
		unset( $status );

		// The wrapper action WooCommerce fires around both edits
		// (WC_Admin_Post_Types::bulk_and_quick_edit_hook). Every other
		// variation save, including the order path, returns here.
		if ( ! doing_action( 'woocommerce_product_bulk_and_quick_edit' ) ) {
			return;
		}

		if ( ! $variation instanceof \WC_Product_Variation ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $variation_id ) : null;
		}

		if ( ! $variation instanceof \WC_Product_Variation || $variation->managing_stock() ) {
			return;
		}

		$parent_id = $variation->get_parent_id();

		if ( $parent_id <= 0 ) {
			return;
		}

		$this->quick_edit_children[ get_current_blog_id() ][ $parent_id ][ $variation->get_id() ] = true;
	}

	/**
	 * Trigger sync for a product or variation row written by the WooCommerce
	 * CSV importer.
	 *
	 * Cheap when the row has no translations (one cached group lookup +
	 * short-circuit), but a mass import of a fully-translated catalog runs a
	 * sibling sync per row — the filter lets operators skip it and rely on a
	 * post-import resync instead.
	 *
	 * @param \WC_Product          $product Imported product object.
	 * @param array<string, mixed> $data    Raw row data (unused).
	 * @return void
	 */
	public function sync_on_import( $product, $data = [] ): void {
		unset( $data );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		unset( $this->flow_claims[ $product ] );

		/** @hook perflocale/woocommerce/sync_on_import Return false to skip sibling sync during CSV imports. */
		if ( ! (bool) apply_filters( 'perflocale/woocommerce/sync_on_import', true, $product ) ) {
			return;
		}

		if ( $product instanceof \WC_Product_Variation ) {
			$this->sync_variation_fields( $product->get_id() );
			return;
		}

		$this->sync_product_fields( $product->get_id() );
	}

	/**
	 * Copy an edited variation's shared fields to the attribute-matched
	 * variation on every sibling-language parent, then rebuild each touched
	 * sibling parent's derived aggregates.
	 *
	 * Sibling variations are located exactly like the order-driven stock
	 * path: anchor on the (group-linked) PARENT, match children by exact
	 * normalised attribute set, and skip (never guess) when a translator
	 * has diverged a sibling's attributes. Same lock discipline: outer lock
	 * on the source variation, inner lock per sibling write, so hooks fired
	 * as side-effects of the writes bail instead of looping — and a
	 * concurrent save of the sibling itself skips rather than fights.
	 *
	 * The SOURCE parent's aggregates are deliberately left alone: WC's own
	 * save flow (admin variations panel and REST alike) runs
	 * WC_Product_Variable::sync() for the product being edited.
	 *
	 * @param int                     $variation_id   Saved variation ID.
	 * @param bool                    $rollup_parents Roll touched sibling parents up inline.
	 *                                                The bulk-edit handler passes false and
	 *                                                amortises the rollup across the whole run.
	 * @param array<int, string>|null $only_keys      Copy only these of the synced fields
	 *                                                (a save through code passes the keys
	 *                                                it changed). Null copies every one.
	 * @return array<int, true> Touched sibling parent IDs (keys).
	 */
	public function sync_variation_fields( int $variation_id, bool $rollup_parents = true, ?array $only_keys = null ): array {
		// A full copy covers whatever a save of this variation recorded.
		if ( null === $only_keys ) {
			$this->forget_record( get_current_blog_id(), $variation_id );
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return [];
		}

		$parent_id = $variation->get_parent_id();

		if ( $parent_id <= 0 ) {
			return [];
		}

		if ( ! $this->sync_enabled() || $this->is_sync_opted_out( $variation_id ) ) {
			return [];
		}

		if ( ! Lock::acquire( $this->lock_name( $variation_id ), self::LOCK_TTL_OUTER ) ) {
			return [];
		}

		++$this->writing;

		try {
			$fields = self::VARIATION_FIELDS;

			// Mirror the parent-product flow: price mirroring is opt-out via
			// the same wc_sync_prices setting (per-language pricing setups).
			if ( ! $this->prices_synced() ) {
				$fields = array_diff( $fields, self::PRICE_KEYS );
			}

			/** @hook perflocale/woocommerce/synced_variation_fields Filter which variation meta keys are synced. */
			$fields = (array) apply_filters( 'perflocale/woocommerce/synced_variation_fields', $fields, $variation_id );

			if ( null !== $only_keys ) {
				$fields = array_values( array_intersect( $fields, $only_keys ) );
			}

			if ( empty( $fields ) ) {
				return [];
			}

			// See sync_product_fields(): a stock-managed sibling keeps the
			// status its own quantity gives it.
			$derive = [] !== array_intersect( $fields, [ '_manage_stock', '_backorders', '_stock_status' ] );

			$repo        = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$parent_sibs = $repo->get_translations( $parent_id, ObjectType::Post );

			if ( count( $parent_sibs ) <= 1 ) {
				return [];
			}

			$values = $this->read_source_values( $variation_id, $fields, $derive );

			$src_attrs       = $this->normalise_variation_attrs( $variation->get_attributes(), (int) $parent_id );
			$synced_ids      = [];
			$touched_parents = [];

			foreach ( $parent_sibs as $link ) {
				$sib_parent_id = (int) $link->object_id;

				if ( $sib_parent_id === $parent_id || $this->is_sync_opted_out( $sib_parent_id ) ) {
					continue;
				}

				$sib_variation_id = $this->match_sibling_variation( $sib_parent_id, $src_attrs );

				// A variation-level opt-out must block INCOMING writes too, not
				// only outgoing ones — the source-side gate reads the same flag,
				// so checking just the parent here would leave the key
				// protecting a variation in one direction only. Placed after
				// the match so the cheaper parent-level skip short-circuits
				// first and this meta read only happens on real candidates.
				if ( $sib_variation_id <= 0 || $sib_variation_id === $variation_id || $this->is_sync_opted_out( $sib_variation_id ) ) {
					continue;
				}

				if ( ! Lock::acquire( $this->lock_name( $sib_variation_id ), self::LOCK_TTL_INNER ) ) {
					continue;
				}

				try {
					$changed = false;

					// See sync_product_fields(): the quantity of a sibling
					// variation that manages its own stock, and still does
					// after this copy, belongs to the stock hooks, and no copy
					// writes it. A parent-managed variation stores 'no' here
					// and takes the source's value.
					$owned = 'yes' === get_post_meta( $sib_variation_id, '_manage_stock', true )
						&& ( ! array_key_exists( '_manage_stock', $values ) || 'yes' === $values['_manage_stock'] );

					$rederive       = null !== $only_keys;
					$stock_written  = false;
					$manage_written = false;

					foreach ( $values as $field => $value ) {
						if ( $owned && '_stock' === $field ) {
							continue;
						}

						// Change-only writes: update_post_meta returns false for
						// an unchanged value too, so only a real change is a
						// candidate failure (mirrors every other sync flow here).
						if ( (string) get_post_meta( $sib_variation_id, $field, true ) === (string) $value ) {
							continue;
						}

						if ( in_array( $field, [ '_stock', '_stock_status', '_manage_stock', '_backorders' ], true ) ) {
							$rederive = true;
						}

						if ( $this->keeps_own_status( $derive, (string) $field, $sib_variation_id ) ) {
							continue;
						}

						$result  = update_post_meta( $sib_variation_id, $field, $value );
						$changed = true;

						if ( '_stock' === $field ) {
							$stock_written = true;
						} elseif ( '_manage_stock' === $field ) {
							$manage_written = true;
						}

						if ( $result === false && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( sprintf( 'PerfLocale InventorySync: variation field sync failed for sibling %d field %s', $sib_variation_id, $field ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}

					if ( $derive && $rederive && $this->refresh_derived_stock_status( $sib_variation_id ) ) {
						$changed = true;
					}

					if ( ! $changed ) {
						continue;
					}

					// Rebuild the sibling variation's wc_product_meta_lookup row
					// (price sort / stock filter / SKU search) — the raw meta
					// writes bypass WC's change tracking. Re-entrancy safe: the
					// sibling lock is held. Only a copy that wrote `_stock` lets
					// the refresh re-set the quantity, and a full copy that
					// wrote the stock-management setting has it set the stock
					// columns from the committed meta.
					$this->refresh_product_lookup( $sib_variation_id, $stock_written, null === $only_keys && $manage_written );

					if ( $derive && null !== $only_keys && 'yes' === (string) get_post_meta( $sib_variation_id, '_manage_stock', true ) ) {
						$this->refresh_stock_lookup_columns( $sib_variation_id );
					}

					$synced_ids[]                      = $sib_variation_id;
					$touched_parents[ $sib_parent_id ] = true;
				} finally {
					Lock::release( $this->lock_name( $sib_variation_id ) );
				}
			}

			if ( $rollup_parents && $touched_parents !== [] ) {
				$this->rollup_sibling_parents( array_keys( $touched_parents ) );
			}

			if ( ! empty( $synced_ids ) ) {
				/** @hook perflocale/woocommerce/inventory_synced Fires after inventory sync completes. */
				do_action( 'perflocale/woocommerce/inventory_synced', $variation_id, $synced_ids, $fields );
			}

			return $touched_parents;
		} finally {
			--$this->writing;
			Lock::release( $this->lock_name( $variation_id ) );
		}
	}

	/**
	 * Rebuild the derived aggregates of sibling variable parents whose
	 * children were just synced.
	 *
	 * A variation price/stock write changes the PARENT's derived state
	 * (multi-row _price index, min/max lookup columns, parent stock status,
	 * price-range transients). WC rebuilds all of it from the children in
	 * WC_Product_Variable::sync(); without this the sibling shop archive
	 * keeps the stale price range until the sibling's next manual save.
	 * Locked so any hook fired from inside the rollup that lands back in
	 * this class bails immediately; skipped (not queued) under contention —
	 * the next sibling save rebuilds the same derived state from the same
	 * children.
	 *
	 * @param array<int, int> $parent_ids Sibling parent IDs to roll up.
	 * @return void
	 */
	private function rollup_sibling_parents( array $parent_ids ): void {
		foreach ( $parent_ids as $sib_parent_id ) {
			$sib_parent_id = (int) $sib_parent_id;

			if ( $sib_parent_id <= 0 || ! Lock::acquire( $this->lock_name( $sib_parent_id ), self::LOCK_TTL_INNER ) ) {
				continue;
			}

			++$this->writing;

			try {
				\WC_Product_Variable::sync( $sib_parent_id );

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $sib_parent_id );
				}
			} finally {
				--$this->writing;
				Lock::release( $this->lock_name( $sib_parent_id ) );
			}
		}
	}

	/**
	 * Normalise a variation attribute map for order-independent comparison.
	 *
	 * Taxonomy values are resolved to the TRANSLATION-GROUP identity of the
	 * term they name, not to the raw slug. The WooCommerce add-on deliberately
	 * rewrites a cloned variation's attribute slugs into the target language
	 * (PerfLocaleWooCommerce::translate_variation_attributes(); without it the
	 * variation dropdown renders empty and the translated product is
	 * unpurchasable), so English `pa_color=red` and German `pa_color=rot` are
	 * the SAME variation with different raw signatures — and stock, price and
	 * field sync silently skipped every one of them. Canonicalising both sides
	 * makes the two agree.
	 *
	 * The mapping is a pure function of (taxonomy, slug) within one blog, so
	 * it can only ADD matches: two signatures equal before this change are
	 * still equal after it. "Any value" (empty) entries and terms with no
	 * translation group keep their raw literal value. A custom (non-taxonomy)
	 * value keeps its literal too, unless its parent records it as the
	 * machine translation of an original option (LOCAL_ATTR_SOURCE_META):
	 * then it reads as that original, so German `size=Klein` and English
	 * `size=Small` are the same variation.
	 *
	 * @param array<string, string>                     $attrs     Raw WC variation attributes.
	 * @param int                                       $parent_id The variations' parent product id
	 *                                                             (0: custom values stay literal).
	 * @param array<string, array<string, string>>|null $tokens    When given, receives key => value =>
	 *                                                             canonical token of each non-empty value.
	 * @return array<string, string>
	 */
	private function normalise_variation_attrs( array $attrs, int $parent_id = 0, ?array &$tokens = null ): array {
		$out   = [];
		$local = null;

		foreach ( $attrs as $key => $value ) {
			$key   = (string) $key;
			$value = (string) $value;

			// A global attribute's key is its `pa_*` taxonomy. Any other key is
			// a local attribute, even one that shares a taxonomy's name
			// ("category").
			if ( $parent_id > 0 && '' !== $value && '' !== $key && ! ( str_starts_with( $key, 'pa_' ) && taxonomy_exists( $key ) ) ) {
				if ( null === $local ) {
					// From the parent's meta cache, which the callers prime.
					$local = get_post_meta( $parent_id, self::LOCAL_ATTR_SOURCE_META, true );
					$local = is_array( $local ) ? $local : [];
				}

				if ( isset( $local[ $key ] ) && is_array( $local[ $key ] ) && isset( $local[ $key ][ $value ] ) && is_string( $local[ $key ][ $value ] ) ) {
					$value = $local[ $key ][ $value ];
				}
			}

			$out[ $key ] = $this->canonical_attr_value( $key, $value );

			if ( null !== $tokens && '' !== $value ) {
				$tokens[ $key ][ $value ] = $out[ $key ];
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Translation-group identity for one variation attribute value.
	 *
	 * Memoised per request and per blog: a bulk stock pass compares the same
	 * handful of distinct attribute values hundreds of times, and both lookups
	 * underneath are already per-request cached (WP's term cache and
	 * TranslationGroupRepository::find_for_object()'s blog-keyed static). The
	 * bucket is reset at a small bound so a mega-bulk run stays memory-flat,
	 * exactly as sibling_variation_map() does.
	 *
	 * @param string $taxonomy Attribute key (a taxonomy name for `pa_*`).
	 * @param string $value    Stored value: a term slug for taxonomy
	 *                         attributes, a literal for custom ones.
	 * @return string Canonical token, or the raw value when there is no group.
	 */
	private function canonical_attr_value( string $taxonomy, string $value ): string {
		// Custom (non-taxonomy) attributes carry literal strings and "any
		// <attribute>" is stored empty — neither names a term, so neither can
		// be canonicalised. Both keep their raw value, unchanged.
		if ( $taxonomy === '' || $value === '' || ! taxonomy_exists( $taxonomy ) ) {
			return $value;
		}

		$this->drop_memos_after_link_changes();

		$blog = get_current_blog_id();
		$key  = $taxonomy . '|' . $value;

		if ( isset( self::$canonical_attr_memo[ $blog ][ $key ] ) ) {
			return self::$canonical_attr_memo[ $blog ][ $key ];
		}

		$canonical = $value;
		$term      = get_term_by( 'slug', $value, $taxonomy );

		if ( $term instanceof \WP_Term ) {
			$group = \PerfLocale\Plugin::get_instance()
				->get( 'group_repo' )
				->find_for_object( (int) $term->term_id, ObjectType::Term );

			if ( $group && isset( $group->id ) && (int) $group->id > 0 ) {
				// A colon can never appear in a term slug (sanitize_title
				// strips it), so this token cannot collide with a real value.
				$canonical = 'pfl-termgroup:' . (int) $group->id;
			}
		}

		if ( count( self::$canonical_attr_memo[ $blog ] ?? [] ) >= 512 ) {
			unset( self::$canonical_attr_memo[ $blog ] );
		}

		self::$canonical_attr_memo[ $blog ][ $key ] = $canonical;

		return $canonical;
	}

	/**
	 * Note the canonical token (canonical_attr_value()) of every attribute
	 * term these products carry in the given taxonomies, with one group query
	 * for all of them. Only a term whose slug reads back as itself is noted:
	 * that is the term get_term_by( 'slug' ) finds for that value. Any other
	 * value is resolved one by one, as canonical_attr_value() does.
	 *
	 * @param array<int, int>    $product_ids Products whose terms are primed.
	 * @param array<int, string> $keys        Attribute keys of the source variation.
	 * @return void
	 */
	private function prime_canonical_attr_values( array $product_ids, array $keys ): void {
		$taxonomies = [];

		foreach ( $keys as $key ) {
			$key = (string) $key;

			if ( str_starts_with( $key, 'pa_' ) && taxonomy_exists( $key ) ) {
				$taxonomies[] = $key;
			}
		}

		if ( [] === $taxonomies ) {
			return;
		}

		$this->drop_memos_after_link_changes();

		$blog     = get_current_blog_id();
		$terms    = [];
		$term_ids = [];

		// The term objects of every product and taxonomy in one read, rather
		// than one per product and taxonomy in get_object_term_cache() below.
		foreach ( $product_ids as $id ) {
			foreach ( $taxonomies as $taxonomy ) {
				$cached = wp_cache_get( (int) $id, "{$taxonomy}_relationships" );

				foreach ( is_array( $cached ) ? $cached : [] as $term_id ) {
					if ( is_numeric( $term_id ) ) {
						$term_ids[] = (int) $term_id;
					}
				}
			}
		}

		if ( [] !== $term_ids ) {
			_prime_term_caches( array_values( array_unique( $term_ids ) ), false );
		}

		foreach ( $product_ids as $id ) {
			foreach ( $taxonomies as $taxonomy ) {
				// Primed above and by the caller: no filter, no query.
				$got = get_object_term_cache( (int) $id, $taxonomy );

				foreach ( is_array( $got ) ? $got : [] as $term ) {
					$memo_key = $taxonomy . '|' . $term->slug;

					if ( ! isset( self::$canonical_attr_memo[ $blog ][ $memo_key ] ) && sanitize_title( $term->slug ) === $term->slug ) {
						$terms[ $memo_key ] = $term;
					}
				}
			}
		}

		if ( [] === $terms ) {
			return;
		}

		/**
		 * Translation group repository.
		 *
		 * @var \PerfLocale\Database\Repository\TranslationGroupRepository $repo
		 */
		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );

		if ( ! $repo->prime_find_for_objects( array_map( static fn( \WP_Term $t ): int => (int) $t->term_id, array_values( $terms ) ), ObjectType::Term ) ) {
			// The query failed: every value is resolved one by one.
			return;
		}

		if ( count( self::$canonical_attr_memo[ $blog ] ?? [] ) + count( $terms ) > 512 ) {
			unset( self::$canonical_attr_memo[ $blog ] );
		}

		foreach ( $terms as $memo_key => $term ) {
			$group_id = self::group_id( $repo->find_for_object( (int) $term->term_id, ObjectType::Term ) );

			self::$canonical_attr_memo[ $blog ][ $memo_key ] = $group_id > 0 ? 'pfl-termgroup:' . $group_id : $term->slug;
		}
	}

	/**
	 * The id of a translation group row, or 0 for no row.
	 *
	 * @param object|null $group Group row (TranslationGroupRepository::find_for_object()).
	 * @return int
	 */
	private static function group_id( ?object $group ): int {
		return null !== $group && isset( $group->id ) && is_numeric( $group->id ) ? (int) $group->id : 0;
	}

	/**
	 * Drop the memos built from translation groups once this process has
	 * changed a group or link since they were last checked
	 * (TranslationGroupRepository::link_changes()): the stock groups, the
	 * canonical attribute tokens, the variation maps and the first matches.
	 * The next read builds them again.
	 *
	 * @return void
	 */
	private function drop_memos_after_link_changes(): void {
		$changes = \PerfLocale\Database\Repository\TranslationGroupRepository::link_changes();

		if ( $changes === $this->memo_link_changes ) {
			return;
		}

		$this->memo_link_changes   = $changes;
		$this->stock_groups        = [];
		self::$canonical_attr_memo = [];
		self::$variation_maps      = [];
		self::$first_matches       = [];
	}

	/**
	 * WooCommerce product props whose change alters a stock group and a
	 * variation match: a variation's attributes, parent and status. A change
	 * of whether a product manages its stock alters no match; its meta write
	 * (STOCK_SHARING_META) drops the stock groups.
	 */
	private const STRUCTURAL_PROPS = [ 'attributes', 'parent_id', 'status' ];

	/**
	 * Meta keys whose change alters which copies share one stock, but no
	 * variation match: whether a product manages its stock, and the sync
	 * opt-out.
	 */
	private const STOCK_SHARING_META = [
		'_manage_stock'        => true,
		self::SYNC_OPTOUT_META => true,
	];

	/**
	 * Note a WooCommerce save that creates a product or changes one of
	 * STRUCTURAL_PROPS, so that forget_after_structural_save() drops the
	 * blog's stock groups and variation matches once it is written.
	 *
	 * @param mixed $product Product being saved.
	 * @return void
	 */
	public function note_structural_save( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( $product->get_id() <= 0 || [] !== array_intersect( self::STRUCTURAL_PROPS, array_keys( (array) $product->get_changes() ) ) ) {
			$this->structural_saves[ $product ] = true;
		}
	}

	/**
	 * After a save noted by note_structural_save(), drop the blog's stock
	 * groups and variation matches.
	 *
	 * @param mixed $product Saved product.
	 * @return void
	 */
	public function forget_after_structural_save( $product ): void {
		if ( $product instanceof \WC_Product && isset( $this->structural_saves[ $product ] ) ) {
			unset( $this->structural_saves[ $product ] );
			$this->forget_stock_matches();
		}
	}

	/**
	 * After a post meta write: a variation attribute (`attribute_*`), a
	 * parent's attribute list or its local attribute sources
	 * (LOCAL_ATTR_SOURCE_META) drops the blog's stock groups and variation
	 * matches; stock management or the sync opt-out drops its stock groups.
	 * Any other key keeps both.
	 *
	 * @param mixed $meta_id   Meta id (or ids, for a deletion).
	 * @param mixed $object_id Post id.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public function forget_after_meta_change( $meta_id, $object_id = 0, $meta_key = '' ): void {
		if ( ! is_string( $meta_key ) ) {
			return;
		}

		if ( str_starts_with( $meta_key, 'attribute_' ) || '_product_attributes' === $meta_key || self::LOCAL_ATTR_SOURCE_META === $meta_key ) {
			$this->forget_stock_matches();
		} elseif ( isset( self::STOCK_SHARING_META[ $meta_key ] ) ) {
			unset( $this->stock_groups[ get_current_blog_id() ] );
		}
	}

	/**
	 * A product or variation changing status (created, published, trashed,
	 * restored) drops the blog's stock groups and variation matches.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 * @return void
	 */
	public function forget_after_status_change( $new_status, $old_status = '', $post = null ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			$this->forget_stock_matches();
		}
	}

	/**
	 * A product or variation deleted drops the blog's stock groups and
	 * variation matches; a deleted product's stored variation map is deleted.
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    Deleted post.
	 * @return void
	 */
	public function forget_after_post_deleted( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			$this->forget_stock_matches();

			if ( 'product' === $post->post_type ) {
				delete_transient( self::STORED_MAP_PREFIX . (int) $post->ID );
			}
		}
	}

	/**
	 * Drop the current blog's stock groups, variation maps and first
	 * matches, and renew the epoch of its stored variation maps
	 * (renew_stored_map_epoch()). The next read builds them again.
	 *
	 * Renewals are coalesced per blog: within EPOCH_RENEW_INTERVAL of this
	 * process's last renewal, the change only marks the blog
	 * (epoch_pending), and the epoch is renewed before this process next
	 * reads a stored map of the blog (stored_first_siblings()), at the next
	 * change after the interval, or at shutdown (renew_pending_epochs()).
	 * Other processes keep the stored maps until then; a stored map's answer
	 * is always read back from the named child (stored_first_siblings()).
	 *
	 * @return void
	 */
	public function forget_stock_matches(): void {
		$blog = get_current_blog_id();

		unset( $this->stock_groups[ $blog ], self::$variation_maps[ $blog ], self::$first_matches[ $blog ] );

		if ( isset( $this->epoch_renewed[ $blog ] ) && microtime( true ) - $this->epoch_renewed[ $blog ] < self::EPOCH_RENEW_INTERVAL ) {
			$this->epoch_pending[ $blog ] = true;
			return;
		}

		$this->renew_stored_map_epoch();
	}

	/**
	 * Renew the current blog's epoch of its stored variation maps
	 * (STORED_MAP_EPOCH), which retires every stored map of the blog in every
	 * process. With no epoch there is no stored map to retire, and none is
	 * stored until a read has made one (stored_first_siblings()).
	 *
	 * @return void
	 */
	private function renew_stored_map_epoch(): void {
		$blog = get_current_blog_id();

		unset( $this->epoch_pending[ $blog ] );
		$this->epoch_renewed[ $blog ] = microtime( true );

		if ( false !== get_transient( self::STORED_MAP_EPOCH ) ) {
			set_transient( self::STORED_MAP_EPOCH, wp_generate_uuid4(), MONTH_IN_SECONDS );
		}
	}

	/**
	 * Renew the epoch of every blog with a change since this process last
	 * renewed it (epoch_pending). Runs at shutdown.
	 *
	 * @return void
	 */
	public function renew_pending_epochs(): void {
		foreach ( array_keys( $this->epoch_pending ) as $blog ) {
			if ( get_current_blog_id() === $blog ) {
				$this->renew_stored_map_epoch();
				continue;
			}

			if ( ! is_multisite() || null === get_site( $blog ) ) {
				unset( $this->epoch_pending[ $blog ] );
				continue;
			}

			switch_to_blog( $blog );
			$this->renew_stored_map_epoch();
			restore_current_blog();
		}
	}

	/**
	 * Drop every answer of reads_cpt_variation_stores().
	 *
	 * @return void
	 */
	public function forget_store_verdicts(): void {
		$this->cpt_variation_stores = [];
	}

	/**
	 * A variation's attributes as WC_Product_Variation::get_attributes()
	 * returns them, read without building the product: WooCommerce's own
	 * reader, the one the variation data store fills the product from, with
	 * the `attribute_` prefix taken off each key as
	 * WC_Product_Variation::set_attributes() does. Null when the product has
	 * to be built instead: the id is not a variation, a callback filters the
	 * attributes a variation returns, or variations are read by another data
	 * store than WooCommerce's own (reads_cpt_variation_stores()).
	 *
	 * With the parent's part of that reader read beforehand
	 * (variation_attribute_context()), a variation of that parent is read the
	 * way the reader reads it, from the variation's own meta only. A context
	 * is read only where WooCommerce's own stores read variations
	 * (sibling_children()), so a read with one does not ask again.
	 *
	 * @param int                       $variation_id Variation id; its post and meta are primed by the caller.
	 * @param array<string, mixed>|null $context      variation_attribute_context() of the parent, or null.
	 * @return array<string, string>|null
	 */
	private function stored_variation_attributes( int $variation_id, ?array $context = null ): ?array {
		$post = get_post( $variation_id );

		if ( ! $post instanceof \WP_Post
			|| 'product_variation' !== $post->post_type
			|| has_filter( 'woocommerce_product_variation_get_attributes' )
			|| ! function_exists( 'wc_get_product_variation_attributes' )
			|| ( null === $context && ! $this->reads_cpt_variation_stores() ) ) {
			return null;
		}

		$attrs = [];
		$raw   = null !== $context && (int) $post->post_parent === $context['parent']
			? $this->read_variation_attributes( $variation_id, $context )
			: wc_get_product_variation_attributes( $variation_id );

		foreach ( is_array( $raw ) ? $raw : [] as $key => $value ) {
			$key = (string) $key;

			if ( str_starts_with( $key, 'attribute_' ) ) {
				$key = substr( $key, 10 );
			}

			$attrs[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $attrs;
	}

	/**
	 * The parent's part of wc_get_product_variation_attributes(), which is the
	 * same for every variation of a parent: its attributes, the meta keys of
	 * those used for variations (`attribute_` plus the sanitised name, in the
	 * parent's order), and whether it was saved before WooCommerce 2.4.
	 *
	 * @param int $parent_id Variable product id.
	 * @return array{parent: int, attributes: array<mixed>, keys: array<int, string>, pre_24: bool}
	 */
	private function variation_attribute_context( int $parent_id ): array {
		$attributes = array_filter( (array) get_post_meta( $parent_id, '_product_attributes', true ) );
		$keys       = [];

		foreach ( $attributes as $name => $options ) {
			if ( is_array( $options ) && ! empty( $options['is_variation'] ) ) {
				$keys[] = 'attribute_' . sanitize_title( (string) $name );
			}
		}

		return [
			'parent'     => $parent_id,
			'attributes' => $attributes,
			'keys'       => array_values( array_unique( $keys ) ),
			'pre_24'     => version_compare( (string) get_post_meta( $parent_id, '_product_version', true ), '2.4.0', '<' ),
		];
	}

	/**
	 * Whether WooCommerce's own post-table data stores read variations and the
	 * children of variable products, so that a variation's stored meta and
	 * WooCommerce's children transient hold what the built products hold.
	 * Read once per blog and set of data store filters (data_store_filters()):
	 * a store filter can answer per blog, and one can be added later in the
	 * process.
	 *
	 * @return bool
	 */
	private function reads_cpt_variation_stores(): bool {
		$blog    = get_current_blog_id();
		$filters = self::data_store_filters();

		if ( ! isset( $this->cpt_variation_stores[ $blog ][ $filters ] ) ) {
			if ( count( $this->cpt_variation_stores[ $blog ] ?? [] ) >= 8 ) {
				unset( $this->cpt_variation_stores[ $blog ] );
			}

			$this->cpt_variation_stores[ $blog ][ $filters ] = 'WC_Product_Variation_Data_Store_CPT' === self::data_store_class( 'product-variation' )
				&& 'WC_Product_Variable_Data_Store_CPT' === self::data_store_class( 'product-variable' );
		}

		return $this->cpt_variation_stores[ $blog ][ $filters ];
	}

	/**
	 * The callbacks registered on the filters through which WooCommerce
	 * chooses the data stores of variations and variable products, as one
	 * string: adding or removing a callback there changes it.
	 *
	 * @return string
	 */
	private static function data_store_filters(): string {
		$hooks = isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ? $GLOBALS['wp_filter'] : [];
		$key   = '';

		foreach ( [ 'woocommerce_data_stores', 'woocommerce_product-variation_data_store', 'woocommerce_product-variable_data_store' ] as $hook ) {
			$key    .= '|';
			$wp_hook = $hooks[ $hook ] ?? null;

			if ( ! $wp_hook instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				$key .= $priority . ':' . implode( ',', array_keys( (array) $callbacks ) ) . ';';
			}
		}

		return $key;
	}

	/**
	 * The class of the data store WooCommerce loads for an object type, or ''
	 * when it cannot load one.
	 *
	 * @param string $type Object type, e.g. 'product-variation'.
	 * @return string
	 */
	private static function data_store_class( string $type ): string {
		if ( ! class_exists( '\WC_Data_Store' ) ) {
			return '';
		}

		try {
			$store = \WC_Data_Store::load( $type );
		} catch ( \Exception $e ) {
			return '';
		}

		$name = is_object( $store ) && method_exists( $store, 'get_current_class_name' ) ? $store->get_current_class_name() : '';

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Read the children transients of these variable products
	 * (variable_children()), their stored variation maps and the maps' epoch
	 * (stored_first_siblings()) into the request's caches at once: one
	 * options query without a persistent object cache, one multi-get with
	 * one.
	 *
	 * @param array<int, int> $parent_ids Product ids.
	 * @return void
	 */
	private function prime_variable_children( array $parent_ids ): void {
		if ( [] === $parent_ids ) {
			return;
		}

		$names = [ self::STORED_MAP_EPOCH ];

		foreach ( $parent_ids as $parent_id ) {
			$names[] = 'wc_product_children_' . (int) $parent_id;
			$names[] = self::STORED_MAP_PREFIX . (int) $parent_id;
		}

		if ( wp_using_ext_object_cache() ) {
			wp_cache_get_multiple( $names, 'transient' );

			return;
		}

		$options = [];

		foreach ( $names as $name ) {
			$options[] = '_transient_' . $name;
			$options[] = '_transient_timeout_' . $name;
		}

		wp_prime_option_caches( $options );
	}

	/**
	 * The ids WC_Product_Variable::get_children() returns for a variable
	 * product, read from WooCommerce's children transient without building the
	 * product: the transient's list of all children, used only where
	 * WooCommerce's own read would use it as it is. Null when the product has
	 * to be built instead: its class is not WC_Product_Variable, another data
	 * store reads it, a callback filters the children, or the transient is
	 * missing, not a list WooCommerce accepts, or stored with an older product
	 * transient version.
	 *
	 * @param int $parent_id Product id.
	 * @return array<int, int>|null
	 */
	private function variable_children( int $parent_id ): ?array {
		if ( false !== has_filter( 'woocommerce_get_children' ) || ! class_exists( '\WC_Product_Factory' ) || ! $this->reads_cpt_variation_stores() ) {
			return null;
		}

		$type = \WC_Product_Factory::get_product_type( $parent_id );

		if ( ! is_string( $type ) || '' === $type || 'WC_Product_Variable' !== \WC_Product_Factory::get_product_classname( $parent_id, $type ) ) {
			return null;
		}

		// The checks of WC_Product_Variable_Data_Store_CPT::validate_children_data().
		$children = get_transient( 'wc_product_children_' . $parent_id );

		if ( ! is_array( $children ) || empty( $children['all'] ) || ! isset( $children['visible'] ) || ! is_array( $children['all'] ) || ! is_array( $children['visible'] ) ) {
			return null;
		}

		foreach ( array_merge( $children['all'], $children['visible'] ) as $id ) {
			if ( ! is_numeric( $id ) ) {
				return null;
			}
		}

		// A list stored with WooCommerce's product transient version is used
		// only while that version is current, as the releases that store one
		// check it.
		if ( isset( $children['version'] ) && ( ! class_exists( '\WC_Cache_Helper' ) || \WC_Cache_Helper::get_transient_version( 'product' ) !== $children['version'] ) ) {
			return null;
		}

		return array_values( wp_parse_id_list( $children['all'] ) );
	}

	/**
	 * What wc_get_product_variation_attributes() returns for a variation of
	 * the parent the context was read from: every variation attribute of the
	 * parent, '' where the variation stores none ("any"), else the variation's
	 * stored value; on a parent saved before WooCommerce 2.4 a stored slug of
	 * a text attribute reads as the parent's text for it.
	 *
	 * @param int                  $variation_id Variation id.
	 * @param array<string, mixed> $context      variation_attribute_context() of its parent.
	 * @return array<string, mixed> Meta key (`attribute_*`) => value.
	 */
	private function read_variation_attributes( int $variation_id, array $context ): array {
		$keys = array_values( array_filter( is_array( $context['keys'] ?? null ) ? $context['keys'] : [], 'is_string' ) );
		$out  = array_fill_keys( $keys, '' );
		$meta = get_post_meta( $variation_id );
		$meta = is_array( $meta ) ? $meta : [];

		foreach ( $keys as $name ) {
			if ( ! str_starts_with( $name, 'attribute_' ) || ! isset( $meta[ $name ] ) || ! is_array( $meta[ $name ] ) ) {
				continue;
			}

			$value = $meta[ $name ][0] ?? null;

			if ( true === ( $context['pre_24'] ?? false ) && is_string( $value ) && sanitize_title( $value ) === $value && is_array( $context['attributes'] ?? null ) ) {
				foreach ( $context['attributes'] as $attribute ) {
					if ( ! is_array( $attribute ) || 'attribute_' . sanitize_title( (string) ( $attribute['name'] ?? '' ) ) !== $name ) {
						continue;
					}

					$texts = function_exists( 'wc_get_text_attributes' ) ? wc_get_text_attributes( (string) ( $attribute['value'] ?? '' ) ) : [];

					foreach ( is_array( $texts ) ? $texts : [] as $text ) {
						if ( is_string( $text ) && sanitize_title( $text ) === $value ) {
							$value = $text;
							break;
						}
					}
				}
			}

			$out[ $name ] = $value;
		}

		return $out;
	}

	/**
	 * Find the child variation of $parent_id whose attribute set exactly
	 * matches $src_attrs, or 0 when none does (e.g. a translator diverged the
	 * sibling's attributes).
	 *
	 * @param int                   $parent_id Sibling PARENT (variable) product id.
	 * @param array<string, string> $src_attrs Normalised source variation attributes.
	 * @return int Matching child variation id, or 0.
	 */
	private function match_sibling_variation( int $parent_id, array $src_attrs ): int {
		$map = $this->sibling_variation_map( $parent_id );

		return $map[ (string) wp_json_encode( $src_attrs ) ] ?? 0;
	}

	/**
	 * The same answer as match_sibling_variation(), read from the parent's
	 * children in order up to the first one with the signature, instead of
	 * mapping every child: the map keeps the first child of each signature,
	 * so the first match is the map's entry. The children's posts and meta
	 * are primed for the first ten children, then for the rest when the scan
	 * reads on. A map built earlier
	 * in the request answers directly; each answer is kept per request until
	 * one of the parent's variations is created or saved
	 * (forget_variation_map()). For the hold-sum query, which needs one
	 * sibling per copy parent, when the parent's stored variation map does
	 * not answer (stored_first_siblings()); the stock and field syncs, which
	 * pair many variations, use the map.
	 *
	 * @param int                   $parent_id Sibling PARENT (variable) product id.
	 * @param array<string, string> $src_attrs Normalised source variation attributes.
	 * @return int Matching child variation id, or 0.
	 */
	private function first_sibling_variation( int $parent_id, array $src_attrs ): int {
		$this->drop_memos_after_link_changes();

		$blog = get_current_blog_id();
		$sig  = (string) wp_json_encode( $src_attrs );

		if ( isset( self::$variation_maps[ $blog ][ $parent_id ] ) ) {
			return self::$variation_maps[ $blog ][ $parent_id ][ $sig ] ?? 0;
		}

		if ( isset( self::$first_matches[ $blog ][ $parent_id ][ $sig ] ) ) {
			return self::$first_matches[ $blog ][ $parent_id ][ $sig ];
		}

		$found    = 0;
		$set      = $this->sibling_children( $parent_id, false );
		$children = null !== $set ? $set['children'] : [];
		$build    = null !== $set && $set['build'];
		$context  = null !== $set ? $set['context'] : null;

		foreach ( [ array_slice( $children, 0, 10 ), array_slice( $children, 10 ) ] as $chunk ) {
			if ( [] === $chunk ) {
				continue;
			}

			_prime_post_caches( $chunk, $build, true );

			foreach ( $chunk as $child_id ) {
				if ( $this->child_signature( (int) $child_id, $context, $parent_id ) === $sig ) {
					$found = (int) $child_id;
					break 2;
				}
			}
		}

		if ( ! isset( self::$first_matches[ $blog ][ $parent_id ] ) && count( self::$first_matches[ $blog ] ?? [] ) >= 16 ) {
			unset( self::$first_matches[ $blog ] );
		}

		self::$first_matches[ $blog ][ $parent_id ][ $sig ] = $found;

		return $found;
	}

	/**
	 * Forget one parent's variation map, and its first matches, after one of
	 * its variations was created or saved.
	 *
	 * @param int|mixed $variation_id Variation id.
	 * @param mixed     $variation    The variation object, when WooCommerce passes it.
	 * @return void
	 */
	public function forget_variation_map( $variation_id = 0, $variation = null ): void {
		$parent_id = $variation instanceof \WC_Product
			? (int) $variation->get_parent_id()
			: (int) wp_get_post_parent_id( (int) $variation_id );

		if ( $parent_id > 0 ) {
			unset( self::$variation_maps[ get_current_blog_id() ][ $parent_id ], self::$first_matches[ get_current_blog_id() ][ $parent_id ] );
		}
	}

	/**
	 * A sibling parent's children in WooCommerce's order, the parent's part of
	 * the attribute reader (variation_attribute_context()), and whether the
	 * children will be built (child_signature()); null for a parent that is
	 * not a variable product. The children come from WooCommerce's children
	 * transient (variable_children()), else from the built parent. With
	 * $prime, every child's post and meta is primed, and its terms too when
	 * the children will be built.
	 *
	 * @param int  $parent_id Sibling PARENT (variable) product id.
	 * @param bool $prime     Prime every child now.
	 * @return array{children: array<int, int>, context: array<string, mixed>|null, build: bool}|null
	 */
	private function sibling_children( int $parent_id, bool $prime = true ): ?array {
		$children = $this->variable_children( $parent_id );

		if ( null === $children ) {
			$parent   = wc_get_product( $parent_id );
			$children = $parent instanceof \WC_Product_Variable ? $parent->get_children() : null;
		}

		if ( ! is_array( $children ) ) {
			return null;
		}

		$children = array_values( wp_parse_id_list( $children ) );
		$build    = false !== has_filter( 'woocommerce_product_variation_get_attributes' ) || ! $this->reads_cpt_variation_stores();

		// Every child's post and meta in one query each, and its terms only
		// when the products are built.
		if ( $prime && [] !== $children ) {
			_prime_post_caches( $children, $build, true );
		}

		return [
			'children' => $children,
			'context'  => $build ? null : $this->variation_attribute_context( $parent_id ),
			'build'    => $build,
		];
	}

	/**
	 * A child variation's attribute signature: its attributes read without
	 * building it (stored_variation_attributes()), else from the built
	 * variation, normalised (normalise_variation_attrs()) and JSON-encoded.
	 * Null when the child is not a variation.
	 *
	 * @param int                                       $child_id  Child variation id.
	 * @param array<string, mixed>|null                 $context   variation_attribute_context() of the parent, or null.
	 * @param int                                       $parent_id Parent product id.
	 * @param array<string, array<string, string>>|null $tokens    When given, receives the canonical tokens
	 *                                                             (normalise_variation_attrs()).
	 * @return string|null
	 */
	private function child_signature( int $child_id, ?array $context, int $parent_id, ?array &$tokens = null ): ?string {
		$attrs = $this->stored_variation_attributes( $child_id, $context );

		if ( null === $attrs ) {
			$child = wc_get_product( $child_id );

			if ( ! $child instanceof \WC_Product_Variation ) {
				return null;
			}

			$attrs = $child->get_attributes();
		}

		return (string) wp_json_encode( $this->normalise_variation_attrs( $attrs, $parent_id, $tokens ) );
	}

	/**
	 * Attribute-signature => variation-id map for one sibling parent, built
	 * once per request and built again after one of its variations is created
	 * or saved (forget_variation_map()).
	 *
	 * A bulk stock pass (order with many line items, stock import) fires the
	 * sync once per source variation, so the map is built once per parent and
	 * request. The children come from WooCommerce's children transient
	 * (variable_children()), else from the built parent. Each child's
	 * attributes are read from its primed meta with WooCommerce's own reader
	 * (stored_variation_attributes()), without building the variation; the
	 * children are built only when a callback filters the attributes a
	 * variation returns or another data store reads them. Stock writes never
	 * change attributes and never save a variation, so a stock pass keeps its
	 * maps. Signatures come from the ksort'd normalised attribute map, so
	 * encoding is deterministic; on a duplicate signature the FIRST child
	 * wins.
	 *
	 * The memo is blog-keyed (multisite: switch_to_blog must never serve
	 * another blog's product ids) and the per-blog bucket is reset at a small
	 * bound so a mega-bulk run across many parents stays memory-flat.
	 *
	 * @param int $parent_id Sibling PARENT (variable) product id.
	 * @return array<string, int> attr-signature => variation id.
	 */
	private function sibling_variation_map( int $parent_id ): array {
		$this->drop_memos_after_link_changes();

		$blog = get_current_blog_id();

		if ( isset( self::$variation_maps[ $blog ][ $parent_id ] ) ) {
			return self::$variation_maps[ $blog ][ $parent_id ];
		}

		$set = $this->sibling_children( $parent_id );
		$map = null !== $set ? $this->build_variation_map( $parent_id, $set ) : [];

		$this->remember_variation_map( $blog, $parent_id, $map );

		return $map;
	}

	/**
	 * Attribute-signature => variation-id map of a parent's children, the
	 * first child of each signature, in the children's order.
	 *
	 * @param int                                                                               $parent_id Sibling PARENT (variable) product id.
	 * @param array{children: array<int, int>, context: array<string, mixed>|null, build: bool} $set sibling_children() of the parent.
	 * @param array<string, array<string, string>>|null                                         $tokens    When given, receives the canonical tokens
	 *                                                                                                     of every child's values (normalise_variation_attrs()).
	 * @return array<string, int>
	 */
	private function build_variation_map( int $parent_id, array $set, ?array &$tokens = null ): array {
		$map = [];

		foreach ( $set['children'] as $child_id ) {
			$sig = $this->child_signature( (int) $child_id, $set['context'], $parent_id, $tokens );

			if ( null !== $sig && ! isset( $map[ $sig ] ) ) {
				$map[ $sig ] = (int) $child_id;
			}
		}

		return $map;
	}

	/**
	 * Keep a parent's variation map for the request (sibling_variation_map()),
	 * in the blog's bounded bucket.
	 *
	 * @param int                $blog      Blog id.
	 * @param int                $parent_id Parent product id.
	 * @param array<string, int> $map       Attribute-signature => variation-id map.
	 * @return void
	 */
	private function remember_variation_map( int $blog, int $parent_id, array $map ): void {
		if ( ! isset( self::$variation_maps[ $blog ][ $parent_id ] ) && count( self::$variation_maps[ $blog ] ?? [] ) >= 16 ) {
			unset( self::$variation_maps[ $blog ] );
		}

		self::$variation_maps[ $blog ][ $parent_id ] = $map;
	}

	/**
	 * The first child variation with the source signature of each of these
	 * copy parents, read from their stored variation maps, so that a match
	 * costs no read of the parent's children.
	 *
	 * A stored map is kept per variable product in a transient: in the object
	 * cache with a persistent object cache, else in an options row that is not
	 * autoloaded. It is read together with WooCommerce's children transients
	 * (prime_variable_children()). It holds what sibling_variation_map()
	 * holds: each attribute signature of the parent's children
	 * (child_signature()) and the first child with it. It is used only while
	 * all of these hold:
	 * - it carries the blog's current epoch (STORED_MAP_EPOCH), which
	 *   changes that can alter a match renew (forget_stock_matches()), at the
	 *   latest before this process reads a stored map again;
	 * - it was built from the children WooCommerce lists now, in the same
	 *   order, and from the parent's current attribute reader and local
	 *   attribute sources (stored_map_state());
	 * - every attribute value it was built from still reads as the same
	 *   canonical token (canonical_attr_value()).
	 * The child it names for the signature is then read, and it is the answer
	 * only when its own signature is the source's. Every other case (a
	 * signature the map does not name, a missing or retired map, a named child
	 * without the signature) is answered by mapping every child of the parent,
	 * and that map is stored when it differs from the stored one. A retired
	 * map is replaced only once an epoch exists, so a map is always stored
	 * with an epoch read before its children were.
	 *
	 * A parent answered earlier in the request, a parent that is not a
	 * variable product, and a parent whose children are built
	 * (sibling_children()) get no answer here; first_sibling_variation()
	 * answers them.
	 *
	 * @param array<int, int>       $parent_ids Copy parents (variable products).
	 * @param array<string, string> $src_attrs  Normalised source variation attributes.
	 * @return array<int, int> Parent id => matching child variation id, or 0.
	 */
	private function stored_first_siblings( array $parent_ids, array $src_attrs ): array {
		$this->drop_memos_after_link_changes();

		$blog = get_current_blog_id();

		if ( isset( $this->epoch_pending[ $blog ] ) ) {
			$this->renew_stored_map_epoch();
		}

		$sig     = (string) wp_json_encode( $src_attrs );
		$epoch   = get_transient( self::STORED_MAP_EPOCH );
		$epoch   = is_string( $epoch ) ? $epoch : '';
		$answers = [];
		$verify  = [];
		$rebuild = [];

		foreach ( $parent_ids as $parent_id ) {
			$parent_id = (int) $parent_id;

			if ( isset( $verify[ $parent_id ] ) || isset( $rebuild[ $parent_id ] ) || isset( self::$variation_maps[ $blog ][ $parent_id ] ) || isset( self::$first_matches[ $blog ][ $parent_id ][ $sig ] ) ) {
				continue;
			}

			$set = $this->sibling_children( $parent_id, false );

			if ( null === $set || $set['build'] || null === $set['context'] ) {
				continue;
			}

			$state  = $this->stored_map_state( $parent_id, $set );
			$stored = get_transient( self::STORED_MAP_PREFIX . $parent_id );
			$stored = is_array( $stored ) ? $stored : null;

			if ( null !== $stored
				&& '' !== $epoch
				&& ( $stored['e'] ?? null ) === $epoch
				&& ( $stored['s'] ?? null ) === $state
				&& is_array( $stored['m'] ?? null )
				&& is_int( $stored['m'][ $sig ] ?? null )
				&& is_array( $stored['t'] ?? null )
				&& $this->stored_tokens_current( $stored['t'] ) ) {
				$verify[ $parent_id ] = [ (int) $stored['m'][ $sig ], $set, $state, $stored ];
				continue;
			}

			$rebuild[ $parent_id ] = [ $set, $state, $stored ];
		}

		if ( [] !== $verify ) {
			// The named children's posts and meta in one query each; the stock
			// group reads the same ids next.
			_prime_post_caches( array_values( array_map( static fn( array $v ): int => $v[0], $verify ) ), false, true );

			foreach ( $verify as $parent_id => [ $child_id, $set, $state, $stored ] ) {
				if ( $this->child_signature( $child_id, $set['context'], $parent_id ) === $sig ) {
					$answers[ $parent_id ] = $child_id;

					if ( ! isset( self::$first_matches[ $blog ][ $parent_id ] ) && count( self::$first_matches[ $blog ] ?? [] ) >= 16 ) {
						unset( self::$first_matches[ $blog ] );
					}

					self::$first_matches[ $blog ][ $parent_id ][ $sig ] = $child_id;
				} else {
					$rebuild[ $parent_id ] = [ $set, $state, $stored ];
				}
			}
		}

		if ( [] === $rebuild ) {
			return $answers;
		}

		// Every child of the parents mapped here: posts and meta in one query
		// each for all of them.
		$children = [];

		foreach ( $rebuild as [ $set ] ) {
			$children[] = $set['children'];
		}

		$children = array_merge( ...$children );

		if ( [] !== $children ) {
			_prime_post_caches( $children, false, true );
		}

		if ( '' === $epoch ) {
			set_transient( self::STORED_MAP_EPOCH, wp_generate_uuid4(), MONTH_IN_SECONDS );
		}

		foreach ( $rebuild as $parent_id => [ $set, $state, $stored ] ) {
			$tokens = [];
			$map    = $this->build_variation_map( $parent_id, $set, $tokens );

			$this->remember_variation_map( $blog, $parent_id, $map );

			$entry = [
				'e' => $epoch,
				's' => $state,
				't' => $tokens,
				'm' => $map,
			];

			if ( '' !== $epoch && $entry !== $stored ) {
				set_transient( self::STORED_MAP_PREFIX . $parent_id, $entry, MONTH_IN_SECONDS );
			}

			$answers[ $parent_id ] = $map[ $sig ] ?? 0;
		}

		return $answers;
	}

	/**
	 * What a parent's stored variation map was built from, besides the
	 * canonical tokens: its children as WooCommerce lists them, in order, the
	 * parent's part of the attribute reader (variation_attribute_context())
	 * and its local attribute sources (LOCAL_ATTR_SOURCE_META), as one hash.
	 *
	 * @param int                                                                               $parent_id Parent product id.
	 * @param array{children: array<int, int>, context: array<string, mixed>|null, build: bool} $set       sibling_children() of the parent.
	 * @return string
	 */
	private function stored_map_state( int $parent_id, array $set ): string {
		return md5( (string) wp_json_encode( [ $set['children'], $set['context'], get_post_meta( $parent_id, self::LOCAL_ATTR_SOURCE_META, true ) ] ) );
	}

	/**
	 * Whether every attribute value a stored variation map was built from
	 * still reads as the canonical token it read as then.
	 *
	 * @param array<mixed> $tokens Attribute key => value => canonical token.
	 * @return bool
	 */
	private function stored_tokens_current( array $tokens ): bool {
		foreach ( $tokens as $key => $values ) {
			if ( ! is_array( $values ) ) {
				return false;
			}

			foreach ( $values as $value => $token ) {
				if ( $this->canonical_attr_value( (string) $key, (string) $value ) !== $token ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Legacy-WC fallback: refresh the price/SKU/stock-status columns of an
	 * EXISTING wc_product_meta_lookup row directly.
	 *
	 * Only the columns this class syncs are touched (sku, min/max price,
	 * onsale, stock_status); the row is never INSERTed — WC owns row
	 * creation. Values come from the product object's own accessors so
	 * variable parents get their true child-price range.
	 *
	 * stock_status matters beyond catalog filters: WooCommerce derives a
	 * variable parent's status from its children's lookup rows
	 * (WC_Product_Variable_Data_Store_CPT::child_has_stock_status), so a
	 * sibling variation whose row kept the old status would make the sibling
	 * parent's rollup undo the status just synced.
	 *
	 * @param \WC_Product $product Product whose lookup row to refresh.
	 * @return void
	 */
	private function update_legacy_lookup_row( \WC_Product $product ): void {
		global $wpdb;

		try {
			if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
				$min = $product->get_variation_price( 'min' );
				$max = $product->get_variation_price( 'max' );
			} else {
				$min = $product->get_price( 'edit' );
				$max = $min;
			}

			// The GTIN column ships with the accessor (WC 9.1 adds both in one
			// migration), so method_exists doubles as the column-presence
			// probe — a fixed SQL naming the column would error on WC < 9.1.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted refresh of WC's lookup row; no WC API exists for this on older versions.
			if ( method_exists( $product, 'get_global_unique_id' ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET sku = %s, global_unique_id = %s, min_price = %f, max_price = %f, onsale = %d, stock_status = %s
						WHERE product_id = %d',
						$wpdb->prefix . 'wc_product_meta_lookup',
						(string) $product->get_sku( 'edit' ),
						(string) $product->get_global_unique_id( 'edit' ),
						(float) ( '' === $min ? 0 : $min ),
						(float) ( '' === $max ? 0 : $max ),
						$product->is_on_sale( 'edit' ) ? 1 : 0,
						(string) $product->get_stock_status( 'edit' ),
						$product->get_id()
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i
						SET sku = %s, min_price = %f, max_price = %f, onsale = %d, stock_status = %s
						WHERE product_id = %d',
						$wpdb->prefix . 'wc_product_meta_lookup',
						(string) $product->get_sku( 'edit' ),
						(float) ( '' === $min ? 0 : $min ),
						(float) ( '' === $max ? 0 : $max ),
						$product->is_on_sale( 'edit' ) ? 1 : 0,
						(string) $product->get_stock_status( 'edit' ),
						$product->get_id()
					)
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( \Throwable $e ) {
			// A missing lookup table (very old WC) or accessor error must never
			// break the sync itself — the meta is already correct; only the
			// derived cache row stays stale, matching pre-fix behaviour.
			unset( $e );
		}
	}
}
