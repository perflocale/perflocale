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
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class InventorySync {

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
	public const LOCK_TTL_OUTER = 30;

	/**
	 * Inner (sibling) lock TTL in seconds.
	 *
	 * Shorter than the outer lock so expired inner locks can be taken
	 * over quickly if a single-sibling write stalls (slow DB, etc.) -
	 * while still outlasting a normal update_post_meta batch.
	 */
	public const LOCK_TTL_INNER = 10;

	/**
	 * Constructor.
	 *
	 * @param CacheManager                                                                     $cache            Cache manager.
	 * @param int|null                                                                         $copy_link_mark   TranslationGroupRepository::link_changes() to start the copy-map mark at; null: now.
	 * @param array<int, array{0: \WeakReference<\WP_REST_Request>, 1: bool, 2: int, 3: bool}> $store_api_frames Store API frames already open (InventorySyncHooks::enter_store_api_request()).
	 * @param int                                                                              $catalog_stock_id A catalog limit's stock id already noted (InventorySyncHooks::mark_catalog_limits()).
	 * @param bool                                                                             $catalog_limits   Whether a catalog limit is being computed (InventorySyncHooks::mark_catalog_limits()).
	 */
	public function __construct( CacheManager $cache, ?int $copy_link_mark = null, array $store_api_frames = [], int $catalog_stock_id = 0, bool $catalog_limits = false ) {
		$this->cache            = $cache;
		$this->copy_link_mark   = $copy_link_mark ?? \PerfLocale\Database\Repository\TranslationGroupRepository::link_changes();
		$this->store_api_frames = $store_api_frames;
		$this->catalog_stock_id = $catalog_stock_id;
		$this->catalog_limits   = $catalog_limits;
		$this->structural_saves = new \WeakMap();
	}

	/**
	 * Hooks are attached by InventorySyncHooks::register(), which the
	 * WooCommerce add-on calls; this method attaches nothing.
	 *
	 * @deprecated 1.0.7.2 Use InventorySyncHooks::register().
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		_deprecated_function( __METHOD__, '1.0.7.2', esc_html( InventorySyncHooks::class . '::register()' ) );
	}

	/**
	 * See InventorySyncHooks::is_display_cart_read().
	 *
	 * @param \WP_REST_Request $request Store API request.
	 * @return bool
	 */
	public static function is_display_cart_read( \WP_REST_Request $request ): bool {
		return InventorySyncHooks::is_display_cart_read( $request );
	}

	/**
	 * See InventorySyncHooks::open_store_api_frame().
	 *
	 * @param mixed $response Result so far, returned untouched.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request being dispatched.
	 * @return mixed
	 */
	public function open_store_api_frame( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		return InventorySyncHooks::open_store_api_frame( $response, $handler, $request );
	}

	/**
	 * See InventorySyncHooks::close_store_api_frame().
	 *
	 * @param mixed $response Result to send, returned untouched.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request being dispatched.
	 * @return mixed
	 */
	public function close_store_api_frame( mixed $response, mixed $handler = null, mixed $request = null ): mixed {
		return InventorySyncHooks::close_store_api_frame( $response, $handler, $request );
	}

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
	 * The shared-field sync of product writes, built on first use.
	 *
	 * @var InventoryFieldSync|null
	 */
	private ?InventoryFieldSync $fields = null;

	/**
	 * The shared-field sync of product writes (InventoryFieldSync), built the
	 * first time a product write needs it.
	 *
	 * @return InventoryFieldSync
	 */
	public function fields(): InventoryFieldSync {
		return $this->fields ??= new InventoryFieldSync( $this );
	}

	/**
	 * A sibling write of InventorySync or InventoryFieldSync starts (writing).
	 *
	 * @return void
	 */
	public function begin_writes(): void {
		++$this->writing;
	}

	/**
	 * A sibling write started by begin_writes() is over.
	 *
	 * @return void
	 */
	public function end_writes(): void {
		--$this->writing;
	}

	/**
	 * Whether a sibling write is running (a save it causes starts no sync).
	 *
	 * @return bool
	 */
	public function writes_running(): bool {
		return $this->writing > 0;
	}

	/**
	 * Whether WooCommerce's stock statement for this product of the current
	 * blog left a delta not yet taken (capture_stock_operation()).
	 *
	 * @param int $product_id Product or variation id.
	 * @return bool
	 */
	public function has_stock_op( int $product_id ): bool {
		return isset( $this->pending_stock_ops[ get_current_blog_id() ][ $product_id ] );
	}

	/**
	 * Forget WooCommerce's announcement of a stock change of this product of
	 * the current blog (expect_stock_operation()).
	 *
	 * @param int $product_id Product or variation id.
	 * @return void
	 */
	public function forget_stock_announcement( int $product_id ): void {
		unset( $this->announced_stock_ops[ get_current_blog_id() ][ $product_id ] );
	}

	/**
	 * InventoryFieldSync::sync_after_bulk_variation_edit() (built on first use: fields()).
	 *
	 * @param string            $bulk_action Bulk action slug (unused - field diffing is value-based).
	 * @param array<mixed>      $data        Bulk action payload (unused).
	 * @param int|string        $product_id  Parent (variable) product ID.
	 * @param array<int|string> $variations  Affected variation IDs.
	 * @return void
	 */
	public function sync_after_bulk_variation_edit( $bulk_action, $data, $product_id, $variations ): void {
		$this->fields()->sync_after_bulk_variation_edit( $bulk_action, $data, $product_id, $variations );
	}

	/**
	 * InventoryFieldSync::note_crud_update() (built on first use: fields()).
	 *
	 * @param mixed $product       Product or variation being saved.
	 * @param mixed $updated_props Props whose stored value the save changed.
	 * @return void
	 */
	public function note_crud_update( $product, $updated_props = [] ): void {
		// Where the field sync returns at once, answered here without building
		// it: a sibling write is running, the save changed nothing, or it is
		// the save inside wc_update_product_stock() (its stock statement not
		// replayed yet), which every order's stock change makes.
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : 0;

		if ( $this->writes_running() || ! is_array( $updated_props ) || [] === $updated_props || ( is_int( $id ) && $this->has_stock_op( $id ) ) ) {
			return;
		}

		$this->fields()->note_crud_update( $product, $updated_props );
	}

	/**
	 * InventoryFieldSync::sync_after_crud_update() (built on first use: fields()).
	 *
	 * @param int|string $product_id Saved product or variation ID.
	 * @return void
	 */
	public function sync_after_crud_update( $product_id ): void {
		// Not built yet: no save is recorded, so only the announcement goes.
		if ( null === $this->fields ) {
			$this->forget_stock_announcement( (int) $product_id );

			return;
		}

		$this->fields->sync_after_crud_update( $product_id );
	}

	/**
	 * InventoryFieldSync::forget_crud_update() (built on first use: fields()).
	 *
	 * @param int|string $product_id Created product or variation ID.
	 * @return void
	 */
	public function forget_crud_update( $product_id ): void {
		// Not built yet: nothing is recorded or queued.
		if ( null === $this->fields ) {
			return;
		}

		$this->fields->forget_crud_update( $product_id );
	}

	/**
	 * InventoryFieldSync::flush_crud_updates() (built on first use: fields()).
	 *
	 * @return void
	 */
	public function flush_crud_updates(): void {
		// Not built yet: nothing is recorded or queued.
		if ( null === $this->fields ) {
			return;
		}

		$this->fields->flush_crud_updates();
	}

	/**
	 * InventoryFieldSync::finish_translation_flow() (built on first use: fields()).
	 *
	 * @param int|string $new_id      ID of the translation just created.
	 * @param mixed      $object_type 'post' or 'term'.
	 * @return void
	 */
	public function finish_translation_flow( $new_id, $object_type = '' ): void {
		// Not built yet: nothing is recorded or queued.
		if ( null === $this->fields ) {
			return;
		}

		$this->fields->finish_translation_flow( $new_id, $object_type );
	}

	/**
	 * InventoryFieldSync::claim_for_flow() (built on first use: fields()).
	 *
	 * @param mixed $product Object the flow will save.
	 * @return mixed The same object, untouched.
	 */
	public function claim_for_flow( $product ) {
		return $this->fields()->claim_for_flow( $product );
	}

	/**
	 * InventoryFieldSync::run_deferred_rollups() (built on first use: fields()).
	 *
	 * @return void
	 */
	public function run_deferred_rollups(): void {
		// Not built yet: nothing is recorded or queued.
		if ( null === $this->fields ) {
			return;
		}

		$this->fields->run_deferred_rollups();
	}

	/**
	 * InventoryFieldSync::sync_product_fields() (built on first use: fields()).
	 *
	 * @param int                     $product_id Post ID of the saved product.
	 * @param array<int, string>|null $only_keys  Copy only these of the synced
	 *                                            fields (a save through code
	 *                                            passes the keys it changed).
	 *                                            Null copies every one.
	 * @return void
	 */
	public function sync_product_fields( int $product_id, ?array $only_keys = null ): void {
		$this->fields()->sync_product_fields( $product_id, $only_keys );
	}

	/**
	 * InventoryFieldSync::sync_on_rest_save() (built on first use: fields()).
	 *
	 * @param \WC_Product $product Saved product object.
	 * @return void
	 */
	public function sync_on_rest_save( \WC_Product $product ): void {
		$this->fields()->sync_on_rest_save( $product );
	}

	/**
	 * InventoryFieldSync::sync_on_rest_variation_save() (built on first use: fields()).
	 *
	 * @param \WC_Product $variation Saved variation object.
	 * @return void
	 */
	public function sync_on_rest_variation_save( \WC_Product $variation ): void {
		$this->fields()->sync_on_rest_variation_save( $variation );
	}

	/**
	 * InventoryFieldSync::sync_on_quick_or_bulk_edit() (built on first use: fields()).
	 *
	 * @param \WC_Product $product Saved product.
	 * @return void
	 */
	public function sync_on_quick_or_bulk_edit( \WC_Product $product ): void {
		$this->fields()->sync_on_quick_or_bulk_edit( $product );
	}

	/**
	 * InventoryFieldSync::note_quick_edit_child() (built on first use: fields()).
	 *
	 * @param int|string $variation_id Variation ID.
	 * @param mixed      $status       New stock status (unused; the sync reads the saved meta).
	 * @param mixed      $variation    Saved variation object.
	 * @return void
	 */
	public function note_quick_edit_child( $variation_id, $status = '', $variation = null ): void {
		$this->fields()->note_quick_edit_child( $variation_id, $status, $variation );
	}

	/**
	 * InventoryFieldSync::sync_on_import() (built on first use: fields()).
	 *
	 * @param \WC_Product          $product Imported product object.
	 * @param array<string, mixed> $data    Raw row data (unused).
	 * @return void
	 */
	public function sync_on_import( $product, $data = [] ): void {
		$this->fields()->sync_on_import( $product, $data );
	}

	/**
	 * InventoryFieldSync::sync_variation_fields() (built on first use: fields()).
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
		return $this->fields()->sync_variation_fields( $variation_id, $rollup_parents, $only_keys );
	}

	/**
	 * Build the lock name for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	public function lock_name( int $product_id ): string {
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
	 * weakly), whether it is a display cart read (InventorySyncHooks::is_display_cart_read()),
	 * the blog it runs on, and whether core's REST server dispatched it
	 * (InventorySyncHooks::open_store_api_frame()) rather than WooCommerce's hydration.
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
	 * Duplicate signatures of the variation maps in $variation_maps, per
	 * blog, parent id and signature (build_variation_map()).
	 *
	 * @var array<int, array<int, array<string, true>>>
	 */
	private static array $variation_dups = [];

	/**
	 * Whether the answer kept in $first_matches is the only child of the
	 * parent with that signature, per blog, parent id and signature, where
	 * the stored variation map that gave it says so (stored_first_siblings()).
	 *
	 * @var array<int, array<int, array<string, bool>>>
	 */
	private static array $match_unique = [];

	/**
	 * What this process last read of each blog's stored copy maps
	 * (read_copy_maps()): when, both epochs, whether this process made the
	 * copy-map epoch since, and each source variation's stored map (false
	 * for none), per blog.
	 *
	 * @var array<int, array{at: float, vsig: string, copy: string, made: bool, rows: array<int, mixed>}>
	 */
	private array $copy_reads = [];

	/**
	 * The epochs this request first read of each blog, from the store or
	 * from what it primed (read_copy_maps(), note_match_baseline(),
	 * stored_first_siblings()), and read again whenever an order's stock
	 * mirror found them changed (match_with_current_epochs()), per blog.
	 * The copy-map epoch is null until this request reads it, and with the
	 * copy maps not used also when it was missing. Removed when this process
	 * retires the copy maps itself (delete_copy_map_epoch()).
	 *
	 * @var array<int, array{vsig: string, copy: string|null}>
	 */
	private array $epochs_seen = [];

	/**
	 * Both epochs as read for the order stock change running now
	 * (InventorySyncHooks::order_stock_change()), per blog, with the number of
	 * that change and how many of its line items had finished when they were
	 * read or last taken (current_epochs()).
	 *
	 * @var array<int, array{frame: int, step: int, vsig: string, copy: string}>
	 */
	private array $order_epochs = [];

	/**
	 * How many times this process renewed a blog's copy-map epoch or
	 * stored-map epoch, per blog. A copy map built while it changed is not
	 * stored.
	 *
	 * @var array<int, int>
	 */
	private array $copy_renewals = [];

	/**
	 * The epoch pair under which each blog's memos were last emptied before
	 * a copy map was built (prepare_copy_map_build()), per blog.
	 *
	 * @var array<int, string>
	 */
	private array $copy_stamps = [];

	/**
	 * TranslationGroupRepository::link_changes() when a term flush last
	 * renewed the copy-map epoch (renew_copy_map_after_term_flush()).
	 *
	 * @var int
	 */
	private int $copy_link_mark = 0;

	/**
	 * Taxonomy of each term deleted in this process, per blog and term id,
	 * for the flushes that follow the deletion.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $deleted_terms = [];

	/**
	 * Slug of each `pa_*` term before its edit, per blog and term id, or ''
	 * when it could not be read (note_term_slug()).
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $term_slugs = [];

	/**
	 * The cart lines whose copy maps were primed last, per blog
	 * (prime_cart_copy_maps()).
	 *
	 * @var array<int, string>
	 */
	private array $cart_primed = [];

	/**
	 * Whether the Store API is computing a quantity limit for product data
	 * outside a cart right now (mark_catalog_limits()).
	 *
	 * @var bool
	 */
	private bool $catalog_limits = false;

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
	public function take_stock_delta( int $product_id ): ?float {
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
		// placeholder" that trips WordPress.DB.PreparedSQLPlaceholders - which
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
		// ended at (9,9,9) where the serial control ended at (7,7,7) - two of
		// three decrements lost, and the loss grows with concurrency.
		//
		// A delta is valid whatever the sibling currently holds: "sold one" is
		// -1 regardless of the base. Applying it inside a single UPDATE is
		// atomic in the engine, so N concurrent orders produce N decrements.
		//
		// The trade is that a sibling which has ALREADY drifted stays drifted -
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
	public function refresh_derived_stock_status( int $product_id ): bool {
		global $wpdb;

		// Mirror WC_Product::validate_props(): a product that does NOT manage
		// its own stock has an operator-chosen `_stock_status`, and deriving one
		// from a leftover `_stock` value would silently overwrite it - flipping
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
		// The WHERE matches the row only when the derived status differs from
		// the stored one, so the affected count means "the status changed" on
		// every engine: MySQL counts changed rows, but the SQLite Database
		// Integration counts matched rows.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately atomic; a read-modify-write here can publish a stale stock status under concurrency.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i st JOIN %i q ON q.post_id = st.post_id AND q.meta_key = '_stock'
				 SET st.meta_value = CASE WHEN CAST( q.meta_value AS DECIMAL(20,6) ) > %d THEN 'instock' ELSE %s END
				 WHERE st.post_id = %d AND st.meta_key = '_stock_status'
				   AND ( st.meta_value IS NULL OR st.meta_value <> CASE WHEN CAST( q.meta_value AS DECIMAL(20,6) ) > %d THEN 'instock' ELSE %s END )",
				$wpdb->postmeta,
				$wpdb->postmeta,
				$threshold,
				$below,
				$product_id,
				$threshold,
				$below
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
	public function refresh_stock_lookup_columns( int $product_id ): void {
		global $wpdb;

		try {
			// ONE statement that COPIES from postmeta instead of two cached
			// reads plus a write. wc_product_meta_lookup is what shop queries
			// read, so a stale figure here is visible to shoppers and can
			// oversell. Reading the source columns inside the UPDATE means the
			// row always lands on the committed quantity whatever order two
			// concurrent orders finished in - which is what makes this safe to
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
	 * The sibling sync writes meta with update_post_meta() (deliberately - it
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
	public function refresh_product_lookup( int $product_id, bool $stock_written = true, bool $manage_written = false ): void {
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
			// whose lock is NOT held here - an unguarded nested group sync.
			// Parent-managed variations take the direct-column path below;
			// the parent's own rollup covers its aggregate state.
			wc_update_product_stock( $product, $product->get_stock_quantity(), 'set' );
		} else {
			// Older WC has no per-product lookup refresh API and the stock
			// path above only covers stock-managed products - after a raw
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
	 * Per-product opt-out meta flag ('yes' = this product manages its own
	 * shared fields - nothing syncs INTO it, and its saves push nothing OUT).
	 *
	 * Set from the checkbox in the WooCommerce product Advanced panel (see
	 * the addon's render/save handlers) or programmatically. Lives on the
	 * PRODUCT (variations inherit their parent's flag), is per-language-copy
	 * (flag only the DE product to give it independent pricing while EN↔PL
	 * keep syncing), and is deliberately NOT itself a synced field.
	 */
	public const SYNC_OPTOUT_META = '_perflocale_sync_optout';

	/**
	 * See InventorySyncHooks::LOCAL_ATTR_SOURCE_META.
	 */
	public const LOCAL_ATTR_SOURCE_META = InventorySyncHooks::LOCAL_ATTR_SOURCE_META;

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
	 * Transient name prefix of the stored copy maps, followed by the source
	 * variation's id (see stored_variation_copies()).
	 */
	private const VARIATION_COPY_PREFIX = 'perflocale_vcopy_';

	/**
	 * Transient holding the epoch of the stored copy maps: "<uuid>|<unix
	 * time it was made>". Every change that can alter which variation is a
	 * copy of which, and that a copy map does not check by value, deletes it
	 * (renew_copy_map_epoch()); a reader makes a new one. A copy map is used
	 * only while it carries the current value. Deleted by name on activation
	 * and on every cache flush, like STORED_MAP_EPOCH.
	 */
	public const COPY_MAP_EPOCH = 'perflocale_vcopy_epoch';

	/**
	 * Shape version of a stored copy map; any other value is a miss.
	 */
	private const COPY_MAP_FORMAT = 1;

	/**
	 * Lifetime of a stored copy map, in seconds.
	 */
	private const COPY_MAP_TTL = DAY_IN_SECONDS;

	/**
	 * Largest serialised copy map that is stored, in bytes.
	 */
	private const COPY_MAP_MAX_BYTES = 16384;

	/**
	 * Most stock ids whose stored copy maps one cart read primes at once.
	 */
	private const COPY_MAP_BATCH = 50;

	/**
	 * Default seconds a copy-map epoch must have existed before a copy map is
	 * stored with it (copy_map_settle_seconds()).
	 */
	private const COPY_MAP_SETTLE = 60;

	/**
	 * Seconds after which this process reads the epochs again from the
	 * store instead of its own copy (read_copy_maps()).
	 */
	private const COPY_EPOCH_REREAD = 5.0;

	/**
	 * Master switch for cross-language product-data sync.
	 *
	 * @return bool
	 */
	public function sync_enabled(): bool {
		// wc_sync_stock is the long-standing master switch - the WC addon
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
	 * cross-language sync - by the per-product meta flag or by the
	 * long-standing skip filter.
	 *
	 * Checked on BOTH ends of every flow: an opted-out product neither
	 * receives sibling data nor pushes its own on save.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	public function is_sync_opted_out( int $product_id ): bool {
		$flagged = get_post_meta( $product_id, self::SYNC_OPTOUT_META, true ) === 'yes';

		if ( ! $flagged && get_post_type( $product_id ) === 'product_variation' ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			$flagged   = $parent_id > 0 && get_post_meta( $parent_id, self::SYNC_OPTOUT_META, true ) === 'yes';
		}

		/** @hook perflocale/woocommerce/skip_inventory_sync Return true to skip sync for this product (default: the per-product opt-out meta). */
		return (bool) apply_filters( 'perflocale/woocommerce/skip_inventory_sync', $flagged, $product_id );
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
		// parent's language - Bootstrap::auto_assign_default_language skips
		// product_variation). So get_translations(variation_id) is always empty
		// and the sibling loop below would bail - meaning an order that
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

		// PASS 1 - RELATIVE, DELIBERATELY LOCK-FREE.
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

		// PASS 2 - ABSOLUTE mirror, for siblings pass 1 could not apply to and
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
						// write that CHANGED something as a candidate failure -
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
	 * the parent flow - a lock-free atomic relative replay, then a locked
	 * absolute mirror; raw update_post_meta does NOT re-fire
	 * woocommerce_variation_set_stock, so there is no re-entrancy loop.
	 *
	 * Where the copies come from: when this request's stock check (the cart
	 * or checkout check that came before the order) already read the
	 * variation's stored copy map, the map is still fresh and valid, both
	 * epochs read again from the store are the ones the map was read with,
	 * and it names every copy parent, the copies are the ones the map names
	 * (held_variation_copies()); otherwise each copy parent is matched as
	 * above, and when an epoch moved since this request first read it, what
	 * the request read and kept of the copy parents is dropped before the
	 * match (match_with_current_epochs()). Either way the stock, the stock
	 * management and the opt-out are read live. Like the stock check, an
	 * order that takes its copies from the stored map does not see
	 * translation links of attribute terms changed directly in the database
	 * until the cache is cleared (see copy_maps_enabled()).
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

		$attributes = $variation->get_attributes();
		$source     = (int) $parent_id;

		// The copies this request already holds from the variation's stored
		// copy map (read by the stock check made earlier in this request),
		// used only when the map names every copy parent: then no copy parent
		// is matched below. Otherwise every copy parent is matched.
		$epochs       = null;
		$held         = is_int( $variation_id ) ? $this->held_variation_copies( $variation_id, $epochs ) : [];
		$copy_parents = is_array( $parent_sibs ) ? array_values( array_diff( wp_parse_id_list( wp_list_pluck( $parent_sibs, 'object_id' ) ), wp_parse_id_list( [ $parent_id ] ) ) ) : [];

		if ( [] !== $held ) {
			$want_parents = $copy_parents;
			$held_parents = array_keys( $held );

			sort( $want_parents );
			sort( $held_parents );

			if ( $want_parents !== $held_parents ) {
				$held = [];
			}
		}

		if ( [] === $held ) {
			// What this request read and kept of the copy parents, dropped
			// when another process changed a match since (an epoch moved).
			$this->match_with_current_epochs( $copy_parents, $epochs );

			// What the match of each copy parent below reads, and what comparing
			// the source's attributes with theirs reads, read for all of them at
			// once.
			$this->prime_copy_parents( $copy_parents, $source, is_array( $attributes ) ? array_map( 'strval', array_keys( $attributes ) ) : [] );

			$src_attrs = $this->normalise_variation_attrs( $attributes, $source );
		} else {
			$src_attrs = [];
		}

		$synced_ids = [];
		$absolute   = [];

		// PASS 1 - RELATIVE, LOCK-FREE. See sync_on_stock_change() for the full
		// reasoning: Lock::acquire() never waits, so a lock around an already
		// atomic `meta_value + delta` could only ever DROP a customer's
		// purchase. Two shoppers buying the same variation in two languages hit
		// exactly that.
		foreach ( $parent_sibs as $link ) {
			$sib_parent_id = (int) $link->object_id;

			if ( $sib_parent_id === $parent_id || $this->is_sync_opted_out( $sib_parent_id ) ) {
				continue;
			}

			$sib_variation_id = [] !== $held ? $held[ $sib_parent_id ] : $this->match_sibling_variation( $sib_parent_id, $src_attrs );

			// A variation-level opt-out must block INCOMING writes too, not
			// only outgoing ones - the source-side gate reads the same flag,
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

		// PASS 2 - ABSOLUTE mirror (read-modify-write), so it takes the locks.
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
	 * stored_variation_copies(), stored_first_siblings() and
	 * first_sibling_variation()). A copy opted
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

		// A cart line's group is asked for once per line: the reads every
		// line needs are made for all of them at once.
		$this->prime_cart_copy_maps( $stock_id );

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
	 * A variation's copies come from its stored copy map when that map holds
	 * (stored_variation_copies()); otherwise they are matched below, and the
	 * answer is stored when record_variation_copies() may store it. A copy
	 * parent opted out of the sync is left out either way, as it stands now.
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

		[ $stored_copies, $record ] = $this->stored_variation_copies( $product_id );

		if ( null !== $stored_copies ) {
			$copies = [];

			foreach ( $stored_copies as $copy_parent => $copy_id ) {
				if ( $copy_id !== $product_id && ! $this->is_sync_opted_out( (int) $copy_parent ) ) {
					$copies[] = $copy_id;
				}
			}

			return $copies;
		}

		if ( null !== $record ) {
			$this->prepare_copy_map_build( $record );
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );
		$links     = $parent_id > 0 ? $repo->get_translations( $parent_id, ObjectType::Post ) : [];

		if ( count( $links ) <= 1 || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$src = $this->stored_variation_attributes( $product_id );

		if ( null === $src ) {
			// Built from the product: no reader to check a stored map against.
			$record    = null;
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
			// The copy parents' children lists (variable_children()) and stored
			// variation maps (stored_first_siblings()) in one read, before
			// anything below. When this request holds no epoch yet (the copy
			// maps were not read), the copy-map epoch is read in the same
			// read, and both epochs are what an order's stock sync later in
			// the request is checked against (note_match_baseline()).
			$baseline = $this->needs_match_baseline();

			$this->prime_variable_children( $parents, true, $baseline );

			if ( $baseline ) {
				$this->note_match_baseline();
			}

			// The source's and the copy parents' posts, terms and meta in one
			// query each, for the opt-out check, the variation maps and the
			// values below.
			_prime_post_caches( array_merge( [ $parent_id ], $parents ), true, true );

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
		// answer is scanned. A copy map to be stored also needs the answer
		// of every opted-out copy parent, since opting out is read live.
		$ask    = null !== $record ? $parents : $wanted;
		$stored = [] !== $ask ? $this->stored_first_siblings( $ask, $attrs ) : [];

		foreach ( $wanted as $copy_parent ) {
			$copy_id = $stored[ $copy_parent ] ?? $this->first_sibling_variation( $copy_parent, $attrs );

			if ( $copy_id > 0 && $copy_id !== $product_id ) {
				$copies[] = $copy_id;
			}
		}

		if ( null !== $record ) {
			$this->record_variation_copies( $product_id, $parent_id, $parents, $src, $attrs, $stored, $record );
		}

		return $copies;
	}

	/**
	 * Note that a Store API request starts running.
	 *
	 * @param \WP_REST_Request $request      The request.
	 * @param bool             $display_read Whether it is a display cart read (InventorySyncHooks::is_display_cart_read()).
	 * @param bool             $rest         Whether core's REST server dispatches it (InventorySyncHooks::open_store_api_frame()).
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
		$this->catalog_limits   = false;

		if ( null !== $cart_item || ! $product instanceof \WC_Product ) {
			return $minimum;
		}

		// Read-only for the stored copy maps (may_record_copies()).
		$this->catalog_limits = true;

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
	 * Set the stock id of the catalog limit being computed, for an instance
	 * built while InventorySyncHooks::mark_catalog_limits() ran the
	 * `perflocale/woocommerce/count_shared_holds_in_catalog` filter: the note
	 * then belongs to this instance, as it would had the instance existed.
	 *
	 * @internal
	 *
	 * @param int $stock_id Stock id.
	 * @return void
	 */
	public function note_catalog_stock_id( int $stock_id ): void {
		$this->catalog_stock_id = $stock_id;
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
		$this->catalog_limits   = false;

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
	 * @param array<mixed>|null $lines The cart's lines as WC_Cart::get_cart() returned them in this check
	 *                                 (InventorySyncHooks::merge_sold_individually_copies()), or null to read
	 *                                 them here.
	 * @return void
	 */
	public function merge_sold_individually_copies( ?array $lines = null ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}

		$cart = WC()->cart;

		if ( ! method_exists( $cart, 'check_cart_item_sold_individually' ) || false === has_action( 'woocommerce_check_cart_items', [ $cart, 'check_cart_items' ] ) ) {
			return;
		}

		foreach ( $lines ?? $cart->get_cart() as $key => $line ) {
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
	 * Normalise a variation attribute map for order-independent comparison.
	 *
	 * Taxonomy values are resolved to the TRANSLATION-GROUP identity of the
	 * term they name, not to the raw slug. The WooCommerce add-on deliberately
	 * rewrites a cloned variation's attribute slugs into the target language
	 * (PerfLocaleWooCommerce::translate_variation_attributes(); without it the
	 * variation dropdown renders empty and the translated product is
	 * unpurchasable), so English `pa_color=red` and German `pa_color=rot` are
	 * the SAME variation with different raw signatures - and stock, price and
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
	public function normalise_variation_attrs( array $attrs, int $parent_id = 0, ?array &$tokens = null ): array {
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
		// <attribute>" is stored empty - neither names a term, so neither can
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
		self::$variation_dups      = [];
		self::$match_unique        = [];
	}

	/**
	 * WooCommerce product props whose change alters a stock group and a
	 * variation match: a variation's attributes, parent and status. A save
	 * that sets a product's attributes again with the values stored for them
	 * changes nothing a match reads (attribute_row_unchanged()). A change of
	 * whether a product manages its stock alters no match; its meta write
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
	 * blog's stock groups and variation matches once it is written. A save
	 * of an existing product whose only such change is its attributes is
	 * noted unless it writes the attribute row stored now
	 * (attribute_row_unchanged()).
	 *
	 * @param mixed $product Product being saved.
	 * @return void
	 */
	public function note_structural_save( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( $product->get_id() <= 0 ) {
			$this->structural_saves[ $product ] = true;
			return;
		}

		$props = array_intersect( self::STRUCTURAL_PROPS, array_keys( (array) $product->get_changes() ) );

		if ( [] === $props ) {
			return;
		}

		if ( [ 'attributes' ] === array_values( $props ) && $this->attribute_row_unchanged( $product ) ) {
			return;
		}

		$this->structural_saves[ $product ] = true;
	}

	/**
	 * Whether a save of this existing product writes the `_product_attributes`
	 * row that is stored now, so that it changes nothing a stock match reads.
	 *
	 * Stock matches read a product's attributes only through two of its post
	 * meta rows, never through its terms: `_product_attributes`, which
	 * WC_Product_Data_Store_CPT::update_attributes() writes from
	 * get_attributes(), and `_product_version`, which tells the variation
	 * reader whether the product was saved before WooCommerce 2.4
	 * (variation_attribute_context()). The options of a taxonomy attribute
	 * are the product's terms; that row holds none of them.
	 *
	 * True only when all of these hold, in this order:
	 * - the product is not a variation (a variation's attributes are
	 *   strings, which WooCommerce compares by value);
	 * - its data store is WooCommerce's own post-table store of simple,
	 *   variable or grouped products, the writer this check rebuilds;
	 * - no callback filters the attributes a variation returns, and
	 *   variations and variable products are read by WooCommerce's own stores
	 *   (reads_cpt_variation_stores()), so that row is what the reader reads;
	 * - the product's post meta is in the object cache already: the check
	 *   reads only post meta already there and never loads it, because the
	 *   save clears that cache before its own first read;
	 * - `_product_version` is 2.4.0 or later;
	 * - every attribute is a WC_Product_Attribute with array options and
	 *   array extra data, or null for one being removed;
	 * - the row built the way WooCommerce builds it is identical (===) to the
	 *   one stored row, or none is built and none is stored.
	 * Anything else, and any error while the row is built, reads as changed.
	 *
	 * The `_product_attributes` meta listener (forget_after_meta_change()) is
	 * required for correctness and stays registered for every save:
	 * WooCommerce clears the post's cache before it writes the row, so the
	 * write compares against the database and fires the listener on every
	 * real change, also when this product object or this process's cache is
	 * older than the database. Building the row runs the
	 * `woocommerce_product_get_attributes` filter once more per such save.
	 *
	 * @param object $product Existing product being saved (WC_Product).
	 * @return bool
	 */
	private function attribute_row_unchanged( object $product ): bool {
		if ( is_a( $product, 'WC_Product_Variation' ) || ! method_exists( $product, 'get_data_store' ) || ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_attributes' ) ) {
			return false;
		}

		$store = $product->get_data_store();
		$class = is_object( $store ) && method_exists( $store, 'get_current_class_name' ) ? $store->get_current_class_name() : '';

		if ( ! in_array( $class, [ 'WC_Product_Data_Store_CPT', 'WC_Product_Variable_Data_Store_CPT', 'WC_Product_Grouped_Data_Store_CPT' ], true )
			|| false !== has_filter( 'woocommerce_product_variation_get_attributes' )
			|| ! function_exists( 'wc_implode_text_attributes' )
			|| ! $this->reads_cpt_variation_stores() ) {
			return false;
		}

		$id = $product->get_id();

		if ( ! is_int( $id ) || false === wp_cache_get( $id, 'post_meta' ) ) {
			return false;
		}

		$version = get_post_meta( $id, '_product_version', true );

		if ( ! is_string( $version ) || '' === $version || version_compare( $version, '2.4.0', '<' ) ) {
			return false;
		}

		try {
			$attributes = $product->get_attributes();
			$row        = [];

			if ( ! is_array( $attributes ) ) {
				return false;
			}

			foreach ( $attributes as $key => $attribute ) {
				if ( null === $attribute ) {
					continue;
				}

				if ( ! self::is_product_attribute( $attribute )
					|| ! is_object( $attribute )
					|| ! method_exists( $attribute, 'get_options' )
					|| ! method_exists( $attribute, 'get_name' )
					|| ! method_exists( $attribute, 'get_position' )
					|| ! method_exists( $attribute, 'get_visible' )
					|| ! method_exists( $attribute, 'get_variation' )
					|| ! method_exists( $attribute, 'is_taxonomy' ) ) {
					return false;
				}

				$options = $attribute->get_options();

				if ( ! is_array( $options ) ) {
					return false;
				}

				$entry = [
					'name'         => $attribute->get_name(),
					'value'        => $attribute->is_taxonomy() ? '' : wc_implode_text_attributes( $options ),
					'position'     => $attribute->get_position(),
					'is_visible'   => $attribute->get_visible() ? 1 : 0,
					'is_variation' => $attribute->get_variation() ? 1 : 0,
					'is_taxonomy'  => $attribute->is_taxonomy() ? 1 : 0,
				];

				if ( method_exists( $attribute, 'get_all_extra_data' ) ) {
					$extra = $attribute->get_all_extra_data();

					if ( ! is_array( $extra ) ) {
						return false;
					}

					$entry = array_merge( $extra, $entry );
				}

				$row[ $key ] = $entry;
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		$stored = get_post_meta( $id, '_product_attributes', false );

		return [] === $row ? [] === $stored : ( is_array( $stored ) && 1 === count( $stored ) && $stored[0] === $row );
	}

	/**
	 * Whether a value is a WooCommerce product attribute object.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_product_attribute( mixed $value ): bool {
		return is_object( $value ) && is_a( $value, 'WC_Product_Attribute' );
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

		if ( InventorySyncHooks::is_structural_meta_key( $meta_key ) ) {
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
			} else {
				delete_transient( self::VARIATION_COPY_PREFIX . (int) $post->ID );
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

		unset( $this->stock_groups[ $blog ], self::$variation_maps[ $blog ], self::$first_matches[ $blog ], self::$variation_dups[ $blog ], self::$match_unique[ $blog ] );

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
	 * stored until a read has made one (stored_first_siblings()). The
	 * copy-map epoch is deleted too, whatever this process holds of it
	 * (delete_copy_map_epoch()).
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

		$this->delete_copy_map_epoch();
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
	 * parent's order), and whether it was saved before WooCommerce 2.4. These
	 * come from the parent's `_product_attributes` and `_product_version`
	 * rows only, never from its terms; attribute_row_unchanged() relies on
	 * that.
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
	 * (variable_children()) and, with $stored_maps, their stored variation
	 * maps and the maps' epoch (stored_first_siblings()) into the request's
	 * caches at once: one options query without a persistent object cache,
	 * one multi-get with one.
	 *
	 * @param array<int, int> $parent_ids  Product ids.
	 * @param bool            $stored_maps Read the stored variation maps and their epoch too.
	 * @param bool            $copy_epoch  Read the copy-map epoch too.
	 * @return void
	 */
	private function prime_variable_children( array $parent_ids, bool $stored_maps = true, bool $copy_epoch = false ): void {
		if ( [] === $parent_ids ) {
			return;
		}

		$names = $stored_maps ? [ self::STORED_MAP_EPOCH ] : [];

		if ( $copy_epoch ) {
			$names[] = self::COPY_MAP_EPOCH;
		}

		foreach ( $parent_ids as $parent_id ) {
			$names[] = 'wc_product_children_' . (int) $parent_id;

			if ( $stored_maps ) {
				$names[] = self::STORED_MAP_PREFIX . (int) $parent_id;
			}
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
	 * Before the stock mirror matches the variation of each copy parent
	 * (sync_variation_stock()), read for all of the parents at once what
	 * those matches would read one parent at a time:
	 * - the parents' posts and meta, which the opt-out check reads;
	 * - with a persistent object cache, the terms of each parent the match
	 *   builds because its children list cannot be used (sibling_children()),
	 *   which wc_get_product() reads. The type check in variable_children()
	 *   reads a parent's product_type terms only once WooCommerce's product
	 *   cache has lost the parent's type (a save of the product or of one of
	 *   its variations), and from the object cache while that holds them. The
	 *   children lists are not read ahead, since a list found missing there
	 *   is fetched again when it is asked for;
	 * - without one, the children lists (variable_children()) and the terms
	 *   of every parent, since WooCommerce reads each parent's product type
	 *   from its terms in every request;
	 * - the translation group of every attribute term that the source's
	 *   parent and the parents carry in the source's attribute taxonomies
	 *   (prime_canonical_attr_values()), which comparing the source's
	 *   attributes with those of each parent's variations otherwise reads
	 *   term by term, a lookup by slug and a group query each: the groups in
	 *   one query, from the term relationships in those taxonomies, read
	 *   once per taxonomy with a persistent object cache and, without one,
	 *   with the terms above, the source's parent among them. A parent whose
	 *   relationships the object cache no longer holds is compared term by
	 *   term.
	 * Parents whose variation map this request already holds and opted-out
	 * parents are left out. The posts, meta and children lists are read only
	 * for two or more parents, and the terms for two or more products, so
	 * each read takes the place of reads the matches would make.
	 *
	 * @param array<int, int>    $parent_ids    Copy parents.
	 * @param int                $source_parent The source variation's parent.
	 * @param array<int, string> $keys          The source variation's attribute keys.
	 * @return void
	 */
	private function prime_copy_parents( array $parent_ids, int $source_parent, array $keys ): void {
		$this->drop_memos_after_link_changes();

		$blog    = get_current_blog_id();
		$parents = array_values( array_filter( wp_parse_id_list( $parent_ids ), static fn( int $id ): bool => $id > 0 && ! isset( self::$variation_maps[ $blog ][ $id ] ) ) );

		if ( [] === $parents ) {
			return;
		}

		if ( count( $parents ) > 1 ) {
			_prime_post_caches( $parents, false, true );
		}

		$parents    = array_values( array_filter( $parents, fn( int $id ): bool => ! $this->is_sync_opted_out( $id ) ) );
		$taxonomies = array_values( array_filter( $keys, static fn( string $key ): bool => str_starts_with( $key, 'pa_' ) && taxonomy_exists( $key ) ) );

		if ( [] === $parents ) {
			return;
		}

		$terms = $parents;

		if ( wp_using_ext_object_cache() ) {
			if ( count( $parents ) > 1 ) {
				$terms = array_values( array_filter( $parents, fn( int $id ): bool => null === $this->variable_children( $id ) ) );
			}
		} else {
			if ( count( $parents ) > 1 && false === has_filter( 'woocommerce_get_children' ) && $this->reads_cpt_variation_stores() ) {
				$this->prime_variable_children( $parents, false );
			}

			if ( [] !== $taxonomies ) {
				$terms[] = $source_parent;
			}
		}

		if ( count( $terms ) > 1 ) {
			_prime_post_caches( $terms, true, false );
		}

		if ( [] === $taxonomies ) {
			return;
		}

		$products = array_merge( [ $source_parent ], $parents );

		if ( wp_using_ext_object_cache() ) {
			foreach ( $taxonomies as $taxonomy ) {
				wp_cache_get_multiple( $products, $taxonomy . '_relationships' );
			}
		}

		$this->prime_canonical_attr_values( $products, $taxonomies );
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
	public function match_sibling_variation( int $parent_id, array $src_attrs ): int {
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
			unset( self::$first_matches[ $blog ], self::$match_unique[ $blog ] );
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
			$blog = get_current_blog_id();

			unset( self::$variation_maps[ $blog ][ $parent_id ], self::$first_matches[ $blog ][ $parent_id ], self::$variation_dups[ $blog ][ $parent_id ], self::$match_unique[ $blog ][ $parent_id ] );
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
	 * A variation's attribute signature as the stock mirror matches it
	 * (child_signature(), with its parent's attribute reader where
	 * WooCommerce's own stores read variations, as sibling_children() reads
	 * it). Reads only. Null when the id is not a variation.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $parent_id    Its parent.
	 * @return string|null
	 */
	public function variation_signature( int $variation_id, int $parent_id ): ?string {
		$build   = false !== has_filter( 'woocommerce_product_variation_get_attributes' ) || ! $this->reads_cpt_variation_stores();
		$context = $build ? null : $this->variation_attribute_context( $parent_id );

		return $this->child_signature( $variation_id, $context, $parent_id );
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

		$set    = $this->sibling_children( $parent_id );
		$tokens = null;
		$dups   = [];
		$map    = null !== $set ? $this->build_variation_map( $parent_id, $set, $tokens, $dups ) : [];

		$this->remember_variation_map( $blog, $parent_id, $map, $dups );

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
	 * @param array<string, true>                                                               $dups      Receives each signature more than one
	 *                                                                                                     child has.
	 * @param-out array<string, true> $dups
	 * @return array<string, int>
	 */
	private function build_variation_map( int $parent_id, array $set, ?array &$tokens = null, array &$dups = [] ): array {
		$map  = [];
		$dups = [];

		foreach ( $set['children'] as $child_id ) {
			$sig = $this->child_signature( (int) $child_id, $set['context'], $parent_id, $tokens );

			if ( null === $sig ) {
				continue;
			}

			if ( isset( $map[ $sig ] ) ) {
				$dups[ $sig ] = true;
			} else {
				$map[ $sig ] = (int) $child_id;
			}
		}

		return $map;
	}

	/**
	 * Keep a parent's variation map for the request (sibling_variation_map()),
	 * in the blog's bounded bucket, with the signatures more than one of its
	 * children has.
	 *
	 * @param int                 $blog      Blog id.
	 * @param int                 $parent_id Parent product id.
	 * @param array<string, int>  $map       Attribute-signature => variation-id map.
	 * @param array<string, true> $dups      Signatures more than one child has.
	 * @return void
	 */
	private function remember_variation_map( int $blog, int $parent_id, array $map, array $dups ): void {
		if ( ! isset( self::$variation_maps[ $blog ][ $parent_id ] ) && count( self::$variation_maps[ $blog ] ?? [] ) >= 16 ) {
			unset( self::$variation_maps[ $blog ], self::$variation_dups[ $blog ] );
		}

		self::$variation_maps[ $blog ][ $parent_id ] = $map;
		self::$variation_dups[ $blog ][ $parent_id ] = $dups;
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
	 * (child_signature()) and the first child with it, and the signatures
	 * more than one child has. It is used only while
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

		$this->note_epochs_seen( $blog, $epoch, null );

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
						unset( self::$first_matches[ $blog ], self::$match_unique[ $blog ] );
					}

					self::$first_matches[ $blog ][ $parent_id ][ $sig ] = $child_id;

					// A map stored without its duplicate signatures leaves
					// the answer's uniqueness unknown.
					if ( is_array( $stored['d'] ?? null ) ) {
						self::$match_unique[ $blog ][ $parent_id ][ $sig ] = ! isset( $stored['d'][ $sig ] );
					} else {
						unset( self::$match_unique[ $blog ][ $parent_id ][ $sig ] );
					}
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
			$made = wp_generate_uuid4();

			set_transient( self::STORED_MAP_EPOCH, $made, MONTH_IN_SECONDS );

			if ( isset( $this->epochs_seen[ $blog ] ) && '' === $this->epochs_seen[ $blog ]['vsig'] ) {
				$this->epochs_seen[ $blog ]['vsig'] = $made;
			}
		}

		foreach ( $rebuild as $parent_id => [ $set, $state, $stored ] ) {
			$tokens = [];
			$dups   = [];
			$map    = $this->build_variation_map( $parent_id, $set, $tokens, $dups );

			$this->remember_variation_map( $blog, $parent_id, $map, $dups );

			$entry = [
				'e' => $epoch,
				's' => $state,
				't' => $tokens,
				'm' => $map,
				'd' => $dups,
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
	 * Whether stored copy maps can be read and stored in this request: the
	 * variations and variable products are read by WooCommerce's own
	 * post-table stores, no callback changes a variation's attributes, a
	 * product's children, the children's query or a product's type, and the
	 * maps are switched on (copy_maps_enabled()). A callback on the class a
	 * product is built with is evaluated for each copy parent instead
	 * (copy_parent_reads_children()). Checked in this process each time,
	 * never stored.
	 *
	 * @return bool
	 */
	private function copy_map_ready(): bool {
		return '' !== self::wc_version()
			&& function_exists( 'wc_get_product_variation_attributes' )
			&& false === has_filter( 'woocommerce_product_variation_get_attributes' )
			&& false === has_filter( 'woocommerce_get_children' )
			&& false === has_filter( 'woocommerce_variable_children_args' )
			&& false === has_filter( 'woocommerce_product_type_query' )
			&& self::copy_maps_enabled()
			&& $this->reads_cpt_variation_stores();
	}

	/**
	 * Whether cart and checkout stock checks use the stored copy maps, by
	 * the `perflocale/woocommerce/stock_map_enabled` filter, applied each
	 * time it is asked. The stored maps also give the copies for the stock
	 * sync of an order placed after such a check in the same request
	 * (sync_variation_stock()). The listeners that retire the maps stay
	 * registered either way, and the stored variation maps of each product
	 * (stored_first_siblings()) are not affected. A translation link of an
	 * attribute term changed directly in the database is not seen by a
	 * stored map, for the stock check and for the order's stock sync alike,
	 * until the cache is cleared.
	 *
	 * @return bool
	 */
	private static function copy_maps_enabled(): bool {
		/**
		 * Filter whether cart and checkout stock checks use stored stock
		 * maps: the stored answer, per variation, to which variation of each
		 * language copy matches it. The same stored answer gives the copies
		 * whose stock moves when an order placed after the check in the same
		 * request reduces the stock. False looks the matching variations up
		 * on every check and on every stock sync. Stock, held stock, stock
		 * management and the opt-out are always read live. Does not switch
		 * Inventory Sync. After changing translation links of attribute
		 * terms directly in the database, clear the cache: until then the
		 * stored maps, for the check and for the order's stock sync, do not
		 * see the change.
		 *
		 * @hook perflocale/woocommerce/stock_map_enabled
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'perflocale/woocommerce/stock_map_enabled', true );
	}

	/**
	 * Whether WooCommerce builds a copy parent, as a variable product, as
	 * WC_Product_Variable itself, so that its children are the ones its
	 * children list holds (variable_children()). Only a callback on
	 * `woocommerce_product_class` can make it another class; the filter is
	 * applied here, for this parent, with the arguments
	 * WC_Product_Factory::get_product_classname() gives it.
	 *
	 * @param int $parent_id Copy parent id.
	 * @return bool
	 */
	private static function copy_parent_reads_children( int $parent_id ): bool {
		if ( false === has_filter( 'woocommerce_product_class' ) ) {
			return true;
		}

		return 'WC_Product_Variable' === apply_filters( 'woocommerce_product_class', 'WC_Product_Variable', 'variable', 'product', $parent_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as WC_Product_Factory::get_product_classname() applies it.
	}

	/**
	 * WooCommerce's version, or '' when it is not loaded.
	 *
	 * @return string
	 */
	private static function wc_version(): string {
		$version = defined( 'WC_VERSION' ) ? constant( 'WC_VERSION' ) : '';

		return is_string( $version ) ? $version : '';
	}

	/**
	 * Seconds a copy-map epoch must have existed before a copy map is stored
	 * with it. Longer than any request and any database replica's lag, so
	 * the data a stored map is built from is read after every change that
	 * renewed the epoch before it was made has committed. A filter can raise
	 * it, never lower it below COPY_MAP_SETTLE. Read only when a missing map
	 * may be stored (stored_variation_copies()).
	 *
	 * @return int
	 */
	private function copy_map_settle_seconds(): int {
		/**
		 * Filter the seconds a copy-map epoch must have existed before a
		 * stored copy map is written with it. Values below the default are
		 * ignored, so a filter cannot remove the wait.
		 *
		 * @hook perflocale/woocommerce/stock_map_settle_seconds
		 * @param int $seconds Default 60.
		 */
		return max( self::COPY_MAP_SETTLE, (int) apply_filters( 'perflocale/woocommerce/stock_map_settle_seconds', self::COPY_MAP_SETTLE ) );
	}

	/**
	 * The unix time a copy-map epoch value was made, or null when the value
	 * does not carry one.
	 *
	 * @param string $epoch Epoch value.
	 * @return int|null
	 */
	private static function copy_epoch_time( string $epoch ): ?int {
		$parts = explode( '|', $epoch );

		return 2 === count( $parts ) && '' !== $parts[0] && \PerfLocale\Helper::is_ascii_digits( $parts[1] ) ? (int) $parts[1] : null;
	}

	/**
	 * Stock ids of the cart lines that manage stock, on the blog the cart
	 * was loaded on. Empty without a cart and while another blog is
	 * switched to.
	 *
	 * @return array<int, int>
	 */
	private function cart_stock_ids(): array {
		$wc   = function_exists( 'WC' ) && ! ( is_multisite() && ms_is_switched() ) ? WC() : null;
		$cart = is_object( $wc ) && property_exists( $wc, 'cart' ) ? $wc->cart : null;

		// get_cart_contents(), unlike get_cart(), never loads the session.
		$lines = is_object( $cart ) && method_exists( $cart, 'get_cart_contents' ) ? $cart->get_cart_contents() : null;
		$ids   = [];

		foreach ( is_array( $lines ) ? $lines : [] as $line ) {
			$product = is_array( $line ) ? ( $line['data'] ?? null ) : null;

			if ( is_object( $product ) && method_exists( $product, 'managing_stock' ) && method_exists( $product, 'get_stock_managed_by_id' ) && true === $product->managing_stock() ) {
				$id    = $product->get_stock_managed_by_id();
				$ids[] = is_numeric( $id ) ? (int) $id : 0;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether a copy map found missing for this variation may be stored now:
	 * the variation is the stock id of a cart line, or an order's stock hold
	 * has started (the checkout, pay-for-order). Never while the Store API
	 * computes product data outside a cart.
	 *
	 * @param int $variation_id Source variation id.
	 * @return bool
	 */
	private function may_record_copies( int $variation_id ): bool {
		if ( $this->catalog_limits ) {
			return false;
		}

		return $this->stock_hold_started || in_array( $variation_id, $this->cart_stock_ids(), true );
	}

	/**
	 * Read both epochs and the stored copy maps of these variations into this
	 * process's view of the blog (copy_reads), in one read: one multi-get
	 * with a persistent object cache, else one options query. Ids read in the
	 * last COPY_EPOCH_REREAD seconds are not read again; after that the
	 * epochs and maps are read from the store again, not from this process's
	 * runtime copy. A renewal this process still owes is made first.
	 *
	 * @param array<int, int> $variation_ids Source variation ids.
	 * @param bool            $create        Make the copy-map epoch when it is missing or unreadable.
	 * @return array{vsig: string, copy: string, time: int, created: bool}
	 *         Both epochs ('' when missing), the unix time the copy-map epoch
	 *         was made (0 when missing), and whether this read made it.
	 */
	private function read_copy_maps( array $variation_ids, bool $create ): array {
		$blog = get_current_blog_id();

		if ( isset( $this->epoch_pending[ $blog ] ) ) {
			$this->renew_stored_map_epoch();
		}

		$view  = $this->copy_reads[ $blog ] ?? null;
		$fresh = null !== $view && microtime( true ) - $view['at'] <= self::COPY_EPOCH_REREAD;
		$ids   = [];

		foreach ( $variation_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! ( $fresh && array_key_exists( $id, $view['rows'] ) ) ) {
				$ids[ $id ] = self::VARIATION_COPY_PREFIX . $id;
			}
		}

		if ( ! $fresh ) {
			$got  = $this->read_transients( array_merge( [ self::STORED_MAP_EPOCH, self::COPY_MAP_EPOCH ], array_values( $ids ) ), null !== $view );
			$view = [
				'at'   => microtime( true ),
				'vsig' => is_string( $got[ self::STORED_MAP_EPOCH ] ?? null ) ? $got[ self::STORED_MAP_EPOCH ] : '',
				'copy' => is_string( $got[ self::COPY_MAP_EPOCH ] ?? null ) ? $got[ self::COPY_MAP_EPOCH ] : '',
				'made' => false,
				'rows' => [],
			];

			$this->note_epochs_seen( $blog, $view['vsig'], $view['copy'] );
		} elseif ( [] !== $ids ) {
			$got = $this->read_transients( array_values( $ids ), false );
		} else {
			$got = [];
		}

		if ( count( $view['rows'] ) + count( $ids ) > 256 ) {
			$view['rows'] = [];
		}

		foreach ( $ids as $id => $name ) {
			$view['rows'][ $id ] = $got[ $name ] ?? false;
		}

		// A missing or unreadable epoch is made here; nothing is stored with
		// it until this process reads it again from the store.
		if ( $create && null === self::copy_epoch_time( $view['copy'] ) ) {
			if ( isset( $this->epochs_seen[ $blog ] ) && $this->epochs_seen[ $blog ]['copy'] === $view['copy'] ) {
				$this->epochs_seen[ $blog ]['copy'] = null;
			}

			$view['copy'] = wp_generate_uuid4() . '|' . time();
			$view['made'] = true;

			$this->note_epochs_seen( $blog, $view['vsig'], $view['copy'] );

			if ( ! self::write_transient_rows( self::COPY_MAP_EPOCH, $view['copy'], MONTH_IN_SECONDS ) ) {
				set_transient( self::COPY_MAP_EPOCH, $view['copy'], MONTH_IN_SECONDS );
			}
		}

		$this->copy_reads[ $blog ] = $view;

		$made = self::copy_epoch_time( $view['copy'] );

		return [
			'vsig'    => $view['vsig'],
			'copy'    => null !== $made ? $view['copy'] : '',
			'time'    => $made ?? 0,
			'created' => $view['made'],
		];
	}

	/**
	 * Several transients of the current blog in one read, by name; false for
	 * a missing one. With $force, a persistent object cache is asked again
	 * and, without one, this process's copies of the rows are dropped first
	 * (their entries in `notoptions` too), so the rows are read from the
	 * database.
	 *
	 * @param array<int, string> $names Transient names.
	 * @param bool               $force Read past this process's copies.
	 * @return array<string, mixed>
	 */
	private function read_transients( array $names, bool $force ): array {
		$out = [];

		if ( wp_using_ext_object_cache() ) {
			$got = wp_cache_get_multiple( $names, 'transient', $force );

			foreach ( $names as $name ) {
				$out[ $name ] = $got[ $name ] ?? false;
			}

			return $out;
		}

		$options = [];

		foreach ( $names as $name ) {
			$options[] = '_transient_' . $name;
			$options[] = '_transient_timeout_' . $name;
		}

		if ( $force ) {
			$notoptions = wp_cache_get( 'notoptions', 'options' );
			$dropped    = false;

			foreach ( $options as $option ) {
				wp_cache_delete( $option, 'options' );

				if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
					unset( $notoptions[ $option ] );
					$dropped = true;
				}
			}

			if ( $dropped ) {
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}

		wp_prime_option_caches( $options );

		foreach ( $names as $name ) {
			$out[ $name ] = get_transient( $name );
		}

		return $out;
	}

	/**
	 * The copies of a source variation from its stored copy map.
	 *
	 * A stored copy map (VARIATION_COPY_PREFIX) is what stock_copies() found
	 * for one source variation: its parent, the parent's linked products and,
	 * for each of them, the one child variation with the source's attribute
	 * signature, with the raw values the answer was derived from. It is used
	 * only while copy_map_ready() holds, it carries the current
	 * WooCommerce version and both current epochs (STORED_MAP_EPOCH,
	 * COPY_MAP_EPOCH), and everything below still reads as it did:
	 * - the source is a variation of the same parent, the parent's linked
	 *   products are the same, the source's attributes (WooCommerce's
	 *   reader) and the parent's local attribute sources are the same, and
	 *   the same keys name a registered taxonomy, all of them `pa_*`;
	 * - each named copy exists, is a published or private variation of its
	 *   linked product, and reads the same attributes with that product's
	 *   reader, whose local attribute sources are the same.
	 * Stock, holds, stock management and the opt-out are never stored. Any
	 * other case is a miss: stock_copies() matches the copies, and the
	 * second value tells it whether and with which epochs the answer may be
	 * stored (record_variation_copies()).
	 *
	 * @param int $variation_id Source variation id.
	 * @return array{0: array<int, int>|null, 1: array{vsig: string, copy: string, renewals: int}|null}
	 *         Copy parent id => copy variation id on a hit (null on a miss),
	 *         and what a miss's answer may be stored with (null: not stored).
	 */
	private function stored_variation_copies( int $variation_id ): array {
		if ( ! $this->copy_map_ready() ) {
			return [ null, null ];
		}

		$blog   = get_current_blog_id();
		$record = $this->may_record_copies( $variation_id );
		$state  = $this->read_copy_maps( [ $variation_id ], $record );

		if ( '' !== $state['vsig'] && '' !== $state['copy'] ) {
			$hit = $this->copy_map_hit( $variation_id, $this->copy_reads[ $blog ]['rows'][ $variation_id ] ?? false, $state['vsig'], $state['copy'] );

			if ( null !== $hit ) {
				return [ $hit, null ];
			}
		}

		if ( ! $record || '' === $state['vsig'] || '' === $state['copy'] || $state['created'] || time() - $state['time'] < $this->copy_map_settle_seconds() ) {
			return [ null, null ];
		}

		return [
			null,
			[
				'vsig'     => $state['vsig'],
				'copy'     => $state['copy'],
				'renewals' => $this->copy_renewals[ $blog ] ?? 0,
			],
		];
	}

	/**
	 * The copies of a source variation from its stored copy map, only when
	 * this request already read that map and the read is still fresh (the
	 * cart's stock check reads it before the order reduces the stock), so the
	 * answer costs no read of the map. Copy parent id => copy variation id;
	 * empty when the map is not held, not valid now, or the maps are off.
	 * The view is used for up to COPY_EPOCH_REREAD seconds and the map is
	 * checked again by value (stored_variation_copies()); then both epochs
	 * are read again from the store, past this process's copies, because a
	 * structural change made by another process since the view was read
	 * (another child of a copy parent given the source's signature, a term
	 * link moved) retires an epoch without changing anything the check by
	 * value reads. When either epoch differs from the view, the view is
	 * marked old (the next read of the maps goes to the store) and nothing
	 * is held: the order matches the copy parents afresh. That read is one
	 * multi-get with a persistent object cache, else one options query, once
	 * per order stock change (current_epochs()).
	 *
	 * @param int                                    $variation_id Source variation id.
	 * @param array{vsig: string, copy: string}|null $epochs       Receives both epochs when they were read
	 *                                                             here, else null.
	 * @param-out array{vsig: string, copy: string}|null $epochs
	 * @return array<int, int>
	 */
	private function held_variation_copies( int $variation_id, ?array &$epochs = null ): array {
		$blog   = get_current_blog_id();
		$view   = $this->copy_reads[ $blog ] ?? null;
		$epochs = null;

		if ( null === $view || microtime( true ) - $view['at'] > self::COPY_EPOCH_REREAD || ! array_key_exists( $variation_id, $view['rows'] ) || false === $view['rows'][ $variation_id ] ) {
			return [];
		}

		[ $stored ] = $this->stored_variation_copies( $variation_id );

		// A hit was checked against the view read_copy_maps() left.
		if ( ! is_array( $stored ) || ! array_key_exists( $blog, $this->copy_reads ) ) {
			return [];
		}

		$view   = $this->copy_reads[ $blog ];
		$epochs = $this->current_epochs();

		if ( $epochs['vsig'] !== $view['vsig'] || $epochs['copy'] !== $view['copy'] ) {
			$this->copy_reads[ $blog ]['at'] = 0.0;

			return [];
		}

		return $stored;
	}

	/**
	 * Both epochs of the current blog read from the store, past this process's
	 * copies (one multi-get with a persistent object cache, else one options
	 * query). Inside an order stock change (InventorySyncHooks::order_stock_change())
	 * they are read once for the order: the stock change of each next line
	 * item of the same blog takes that read, after WooCommerce finished the
	 * line item before it (InventorySyncHooks::order_stock_step()). A stock
	 * change with no line item finished since the read or its last use (one
	 * outside the line loop, in a frame no closing action ended) reads afresh.
	 *
	 * @return array{vsig: string, copy: string}
	 */
	private function current_epochs(): array {
		$blog  = get_current_blog_id();
		$frame = InventorySyncHooks::order_stock_change();
		$held  = $this->order_epochs[ $blog ] ?? null;

		$step  = InventorySyncHooks::order_stock_step();

		if ( 0 !== $frame && null !== $held && $frame === $held['frame'] && $step > $held['step'] ) {
			$this->order_epochs[ $blog ]['step'] = $step;

			return [
				'vsig' => $held['vsig'],
				'copy' => $held['copy'],
			];
		}

		$now    = $this->read_transients( [ self::STORED_MAP_EPOCH, self::COPY_MAP_EPOCH ], true );
		$vsig   = $now[ self::STORED_MAP_EPOCH ] ?? false;
		$copy   = $now[ self::COPY_MAP_EPOCH ] ?? false;
		$epochs = [
			'vsig' => is_string( $vsig ) ? $vsig : '',
			'copy' => is_string( $copy ) ? $copy : '',
		];

		if ( 0 !== $frame ) {
			$this->order_epochs[ $blog ] = [
				'frame' => $frame,
				'step'  => $step,
			] + $epochs;
		}

		return $epochs;
	}

	/**
	 * Note the epochs this request read of a blog (epochs_seen): the first
	 * pair read is kept, and a copy-map epoch not read before is added.
	 *
	 * @param int         $blog Blog id.
	 * @param string      $vsig STORED_MAP_EPOCH as read ('' when missing).
	 * @param string|null $copy COPY_MAP_EPOCH as read ('' when missing), or null when not read.
	 * @return void
	 */
	private function note_epochs_seen( int $blog, string $vsig, ?string $copy ): void {
		if ( ! isset( $this->epochs_seen[ $blog ] ) ) {
			$this->epochs_seen[ $blog ] = [
				'vsig' => $vsig,
				'copy' => $copy,
			];
		} elseif ( null === $this->epochs_seen[ $blog ]['copy'] && null !== $copy ) {
			$this->epochs_seen[ $blog ]['copy'] = $copy;
		}
	}

	/**
	 * Whether this request holds no epoch of the current blog yet
	 * (epochs_seen), so that the stock check's match takes both epochs as
	 * its baseline (note_match_baseline()). A request that read the copy
	 * maps (read_copy_maps()) holds both already.
	 *
	 * @return bool
	 */
	private function needs_match_baseline(): bool {
		return ! isset( $this->epochs_seen[ get_current_blog_id() ] );
	}

	/**
	 * Note both epochs of the current blog, as just primed with the copy
	 * parents' children lists (prime_variable_children()), as the first pair
	 * this request read (epochs_seen), before the match reads anything else.
	 * An order's stock sync later in the request compares the store's pair
	 * with it (match_with_current_epochs()). A missing copy-map epoch is no
	 * baseline (null): a term link moved by another process deletes the
	 * epoch, and a missing one cannot show that. When the copy-map epoch is
	 * held, the blog's match memos and the attribute terms' groups this
	 * process found before are dropped, so that what the match uses of them
	 * is read after the baseline.
	 *
	 * @return void
	 */
	private function note_match_baseline(): void {
		$blog = get_current_blog_id();
		$vsig = get_transient( self::STORED_MAP_EPOCH );
		$copy = get_transient( self::COPY_MAP_EPOCH );

		$this->note_epochs_seen( $blog, is_string( $vsig ) ? $vsig : '', is_string( $copy ) && '' !== $copy ? $copy : null );

		if ( null === $this->epochs_seen[ $blog ]['copy'] ) {
			return;
		}

		unset( $this->stock_groups[ $blog ], self::$canonical_attr_memo[ $blog ], self::$variation_maps[ $blog ], self::$first_matches[ $blog ], self::$variation_dups[ $blog ], self::$match_unique[ $blog ] );

		\PerfLocale\Database\Repository\TranslationGroupRepository::forget_found_groups( ObjectType::Term );
	}

	/**
	 * Make the current blog's copy-map epoch where none is stored, in one
	 * write that never replaces a stored value: an add to a persistent
	 * object cache, else one statement inserting both of its rows
	 * (write_transient_rows()). The value has the shape read_copy_maps()
	 * makes, so a copy map is stored with it only after
	 * copy_map_settle_seconds(). Null when no value was made: a callback
	 * filters reads of the epoch, WordPress is installing, another process
	 * stored one first, or the write failed.
	 *
	 * @return string|null The value made.
	 */
	private function add_copy_map_epoch(): ?string {
		if ( wp_installing() || self::transient_rows_read_filtered( self::COPY_MAP_EPOCH ) ) {
			return null;
		}

		$value = wp_generate_uuid4() . '|' . time();

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( self::COPY_MAP_EPOCH, $value, 'transient', MONTH_IN_SECONDS ) ? $value : null;
		}

		return self::write_transient_rows( self::COPY_MAP_EPOCH, $value, MONTH_IN_SECONDS, true ) ? $value : null;
	}

	/**
	 * Before an order's stock mirror matches the copy parents
	 * (sync_variation_stock()), when this request read an epoch earlier
	 * (epochs_seen): read both epochs again, past this process's copies
	 * (or take the pair held_variation_copies() just read, or the one read
	 * for the order's stock change: current_epochs()), and when either
	 * differs from what this request read, drop the blog's match memos, the
	 * attribute terms' groups this request found and this process's copies
	 * of the posts and meta of the copy parents and their children, then
	 * read those posts and meta again for all of them at once. A structural change made by another process during this
	 * request (another child given the source's signature, a term link
	 * moved) retires an epoch there, while the memos and copies this
	 * request's stock check built still show the old match; the order then
	 * matches as a fresh request would. With a persistent object cache the
	 * copies are deleted from it too. A request that read no epoch reads
	 * nothing here, and an unchanged pair drops nothing. A copy-map epoch
	 * the request never read, or found missing with the copy maps not used
	 * (note_match_baseline()), is no baseline: what the request kept is
	 * dropped once, as for a change, since a term link moved by another
	 * process renews only that epoch. With the copy maps not used, a
	 * missing copy-map epoch is then made (add_copy_map_epoch()), at most
	 * once until the next renewal deletes it. The pair read here, with the
	 * epoch made here in place of the missing one, is what the next order
	 * of the request is compared with.
	 *
	 * @param array<int, int>                        $copy_parents The source parent's linked products.
	 * @param array{vsig: string, copy: string}|null $epochs       Both epochs as held_variation_copies() read them
	 *                                                             for this order, or null.
	 * @return void
	 */
	private function match_with_current_epochs( array $copy_parents, ?array $epochs ): void {
		$blog = get_current_blog_id();
		$seen = $this->epochs_seen[ $blog ] ?? null;

		if ( null === $seen ) {
			return;
		}

		$epochs ??= $this->current_epochs();

		if ( $epochs['vsig'] === $seen['vsig'] && null !== $seen['copy'] && $epochs['copy'] === $seen['copy'] ) {
			return;
		}

		// With the copy maps not used nothing else makes the copy-map epoch.
		// Made here, before what the request kept is read again, it is the
		// baseline of the request's next order and of the next request's
		// stock check.
		$made = '' === $epochs['copy'] && ! $this->copy_map_ready() ? $this->add_copy_map_epoch() : null;

		if ( null !== $made ) {
			$epochs['copy'] = $made;

			if ( isset( $this->order_epochs[ $blog ] ) ) {
				$this->order_epochs[ $blog ]['copy'] = $made;
			}
		}

		$this->epochs_seen[ $blog ] = $epochs;

		unset( $this->stock_groups[ $blog ], self::$canonical_attr_memo[ $blog ], self::$variation_maps[ $blog ], self::$first_matches[ $blog ], self::$variation_dups[ $blog ], self::$match_unique[ $blog ] );

		// The attribute terms' groups this request found are read again too:
		// a term link moved by another process is what renews the copy-map
		// epoch.
		\PerfLocale\Database\Repository\TranslationGroupRepository::forget_found_groups( ObjectType::Term );

		if ( isset( $this->copy_reads[ $blog ] ) ) {
			$this->copy_reads[ $blog ]['at'] = 0.0;
		}

		$ids = [ $copy_parents ];

		foreach ( $copy_parents as $copy_parent ) {
			$set = $this->sibling_children( (int) $copy_parent, false );

			if ( null !== $set ) {
				$ids[] = $set['children'];
			}
		}

		$ids = array_values( array_unique( array_merge( ...$ids ) ) );

		if ( [] === $ids ) {
			return;
		}

		wp_cache_delete_multiple( $ids, 'posts' );
		wp_cache_delete_multiple( $ids, 'post_meta' );

		_prime_post_caches( $ids, false, true );
	}

	/**
	 * Check a stored copy map of a source variation against what it was
	 * built from (see stored_variation_copies()).
	 *
	 * @param int    $variation_id Source variation id.
	 * @param mixed  $entry        Stored copy map, as read.
	 * @param string $vsig         Current STORED_MAP_EPOCH.
	 * @param string $copy         Current COPY_MAP_EPOCH.
	 * @return array<int, int>|null Copy parent id => copy variation id, or null.
	 */
	private function copy_map_hit( int $variation_id, mixed $entry, string $vsig, string $copy ): ?array {
		if ( ! is_array( $entry )
			|| self::COPY_MAP_FORMAT !== ( $entry['f'] ?? null )
			|| self::wc_version() !== ( $entry['v'] ?? null )
			|| $vsig !== ( $entry['e'] ?? null )
			|| $copy !== ( $entry['t'] ?? null )
			|| ! is_int( $entry['a'] ?? null ) || $entry['a'] <= 0
			|| ! is_array( $entry['p'] ?? null ) || [] === $entry['p']
			|| ! is_array( $entry['r'] ?? null )
			|| ! is_string( $entry['h'] ?? null )
			|| ! is_array( $entry['x'] ?? null )
			|| ! is_array( $entry['c'] ?? null ) || count( $entry['c'] ) !== count( $entry['p'] ) ) {
			return null;
		}

		$anchor = $entry['a'];
		$post   = get_post( $variation_id );

		if ( ! $post instanceof \WP_Post || 'product_variation' !== $post->post_type || (int) $post->post_parent !== $anchor ) {
			return null;
		}

		/**
		 * Translation group repository.
		 *
		 * @var \PerfLocale\Database\Repository\TranslationGroupRepository $repo
		 */
		$repo    = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$parents = array_values( array_diff( wp_parse_id_list( wp_list_pluck( $repo->get_translations( $anchor, ObjectType::Post ), 'object_id' ) ), [ $anchor ] ) );

		sort( $parents );

		if ( $parents !== $entry['p'] ) {
			return null;
		}

		$src = $this->stored_variation_attributes( $variation_id );

		if ( null === $src || $src !== $entry['r'] || $this->local_sources_hash( $anchor ) !== $entry['h'] ) {
			return null;
		}

		$named = [];
		$rows  = [];
		$lists = [ $src ];

		foreach ( $parents as $copy_parent ) {
			$row = $entry['c'][ $copy_parent ] ?? null;

			if ( ! is_array( $row ) || ! is_int( $row['i'] ?? null ) || $row['i'] <= 0 || ! is_array( $row['r'] ?? null ) || ! is_string( $row['h'] ?? null ) ) {
				return null;
			}

			$named[ $copy_parent ] = $row['i'];
			$rows[ $copy_parent ]  = [ $row['r'], $row['h'] ];
			$lists[]               = $row['r'];
		}

		$keys = $this->taxonomy_keys( $lists );

		if ( $keys !== $entry['x'] ) {
			return null;
		}

		foreach ( $keys as $key ) {
			if ( ! str_starts_with( $key, 'pa_' ) ) {
				return null;
			}
		}

		// The named copies' and their parents' posts and meta in one query
		// each; the stock group reads the same rows next.
		_prime_post_caches( array_merge( array_values( $named ), $parents ), false, true );

		foreach ( $named as $copy_parent => $copy_id ) {
			$copy_post = get_post( $copy_id );

			if ( ! $copy_post instanceof \WP_Post
				|| 'product_variation' !== $copy_post->post_type
				|| (int) $copy_post->post_parent !== $copy_parent
				|| ! self::copy_parent_reads_children( $copy_parent )
				|| ! in_array( $copy_post->post_status, [ 'publish', 'private' ], true )
				|| $this->stored_variation_attributes( $copy_id, $this->variation_attribute_context( $copy_parent ) ) !== $rows[ $copy_parent ][0]
				|| $this->local_sources_hash( $copy_parent ) !== $rows[ $copy_parent ][1] ) {
				return null;
			}
		}

		return $named;
	}

	/**
	 * Before stock_copies() matches copies whose answer may be stored: when
	 * this process has not done so under the current epoch pair, empty the
	 * blog's memos built from translation groups and variation maps, and the
	 * repository's per-process term group lookups. The answer is then built
	 * only from data read after the epochs were seen settled.
	 *
	 * @param array{vsig: string, copy: string, renewals: int} $record What the answer may be stored with.
	 * @return void
	 */
	private function prepare_copy_map_build( array $record ): void {
		$blog  = get_current_blog_id();
		$stamp = $record['vsig'] . '|' . $record['copy'];

		if ( ( $this->copy_stamps[ $blog ] ?? '' ) === $stamp ) {
			return;
		}

		$this->copy_stamps[ $blog ] = $stamp;

		unset( self::$canonical_attr_memo[ $blog ], self::$variation_maps[ $blog ], self::$first_matches[ $blog ], self::$variation_dups[ $blog ], self::$match_unique[ $blog ] );

		\PerfLocale\Database\Repository\TranslationGroupRepository::forget_found_groups( ObjectType::Term );
	}

	/**
	 * Store the copy map of a source variation that stock_copies() just
	 * matched (see stored_variation_copies()). Nothing is stored unless all
	 * of these hold:
	 * - neither epoch was renewed by this process since they were read, and
	 *   no renewal is owed;
	 * - every linked product of the source's parent, opted out or not, has a
	 *   copy that a stored or freshly built variation map names as the only
	 *   child with the source's signature;
	 * - every key that names a registered taxonomy is a `pa_*` attribute;
	 * - the map passes the checks a read makes (copy_map_hit()) and is at
	 *   most COPY_MAP_MAX_BYTES serialised.
	 * Without a persistent object cache the map's two rows are written in one
	 * statement (write_transient_rows()).
	 *
	 * @param int                                              $variation_id Source variation id.
	 * @param int                                              $parent_id    Its parent.
	 * @param array<int, int>                                  $parents      The parent's linked products, the parent excluded.
	 * @param array<string, string>                            $src          Source attributes (stored_variation_attributes()).
	 * @param array<string, string>                            $attrs        The same, normalised.
	 * @param array<int, int>                                  $answers      What stored_first_siblings() answered.
	 * @param array{vsig: string, copy: string, renewals: int} $record       Epochs read before the match.
	 * @return void
	 */
	private function record_variation_copies( int $variation_id, int $parent_id, array $parents, array $src, array $attrs, array $answers, array $record ): void {
		$blog = get_current_blog_id();

		if ( [] === $parents || ( $this->copy_renewals[ $blog ] ?? 0 ) !== $record['renewals'] || isset( $this->epoch_pending[ $blog ] ) || ! $this->copy_map_ready() ) {
			return;
		}

		$sig  = (string) wp_json_encode( $attrs );
		$rows = [];

		foreach ( $parents as $copy_parent ) {
			$copy_parent = (int) $copy_parent;
			$copy_id     = $this->unique_answer( $copy_parent, $sig );

			if ( $copy_id <= 0 || ( isset( $answers[ $copy_parent ] ) && (int) $answers[ $copy_parent ] !== $copy_id ) ) {
				return;
			}

			$copy_attrs = $this->stored_variation_attributes( $copy_id, $this->variation_attribute_context( $copy_parent ) );

			if ( null === $copy_attrs ) {
				return;
			}

			$rows[ $copy_parent ] = [
				'i' => $copy_id,
				'r' => $copy_attrs,
				'h' => $this->local_sources_hash( $copy_parent ),
			];
		}

		$sorted = array_map( 'intval', $parents );
		sort( $sorted );
		ksort( $rows );

		$keys = $this->taxonomy_keys( array_merge( [ $src ], array_column( $rows, 'r' ) ) );

		foreach ( $keys as $key ) {
			if ( ! str_starts_with( $key, 'pa_' ) ) {
				return;
			}
		}

		$entry = [
			'f' => self::COPY_MAP_FORMAT,
			'v' => self::wc_version(),
			'e' => $record['vsig'],
			't' => $record['copy'],
			'a' => $parent_id,
			'p' => $sorted,
			'r' => $src,
			'h' => $this->local_sources_hash( $parent_id ),
			'x' => $keys,
			'c' => $rows,
		];

		if ( strlen( (string) wp_json_encode( $entry ) ) > self::COPY_MAP_MAX_BYTES || null === $this->copy_map_hit( $variation_id, $entry, $record['vsig'], $record['copy'] ) ) {
			return;
		}

		if ( ! self::write_transient_rows( self::VARIATION_COPY_PREFIX . $variation_id, $entry, self::COPY_MAP_TTL ) ) {
			set_transient( self::VARIATION_COPY_PREFIX . $variation_id, $entry, self::COPY_MAP_TTL );
		}

		if ( isset( $this->copy_reads[ $blog ] ) ) {
			$this->copy_reads[ $blog ]['rows'][ $variation_id ] = $entry;
		}
	}

	/**
	 * The copy a variation map built or read in this request names for a
	 * signature, when the map says no other child of the parent has it; 0
	 * otherwise (no map, no match, a duplicate, or uniqueness unknown).
	 *
	 * @param int    $parent_id Copy parent id.
	 * @param string $sig       Normalised source signature.
	 * @return int
	 */
	private function unique_answer( int $parent_id, string $sig ): int {
		$blog = get_current_blog_id();

		if ( isset( self::$variation_maps[ $blog ][ $parent_id ] ) ) {
			if ( ! isset( self::$variation_dups[ $blog ][ $parent_id ] ) || isset( self::$variation_dups[ $blog ][ $parent_id ][ $sig ] ) ) {
				return 0;
			}

			return (int) ( self::$variation_maps[ $blog ][ $parent_id ][ $sig ] ?? 0 );
		}

		if ( isset( self::$first_matches[ $blog ][ $parent_id ][ $sig ] ) && true === ( self::$match_unique[ $blog ][ $parent_id ][ $sig ] ?? null ) ) {
			return (int) self::$first_matches[ $blog ][ $parent_id ][ $sig ];
		}

		return 0;
	}

	/**
	 * A product's local attribute sources (LOCAL_ATTR_SOURCE_META) as one
	 * hash, read from its meta cache.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	private function local_sources_hash( int $product_id ): string {
		return md5( (string) wp_json_encode( get_post_meta( $product_id, self::LOCAL_ATTR_SOURCE_META, true ) ) );
	}

	/**
	 * The attribute keys of these attribute lists that name a registered
	 * taxonomy, sorted.
	 *
	 * @param array<int, array<mixed>> $lists Attribute key => value lists.
	 * @return array<int, string>
	 */
	private function taxonomy_keys( array $lists ): array {
		$keys = [];

		foreach ( $lists as $list ) {
			foreach ( array_keys( $list ) as $key ) {
				$key = (string) $key;

				if ( '' !== $key && taxonomy_exists( $key ) ) {
					$keys[ $key ] = true;
				}
			}
		}

		$keys = array_map( 'strval', array_keys( $keys ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * Before the first group of a cart line is built, read what every
	 * stock-managed cart line's group needs at once: the translations of
	 * each line's product (a variation's parent), the stored copy maps and
	 * both epochs (only with a variation line and only when
	 * copy_map_ready()), and the posts and meta of the copies and copy
	 * parents the maps name and of the simple products' copies. Groups are
	 * still built one stock id at a time, when asked for. Once per blog in
	 * a process, for at most COPY_MAP_BATCH lines whose group is not built
	 * yet, and only when there are two or more of them.
	 *
	 * @param int $stock_id Stock id whose group is about to be built.
	 * @return void
	 */
	private function prime_cart_copy_maps( int $stock_id ): void {
		$blog = get_current_blog_id();

		if ( isset( $this->cart_primed[ $blog ] ) ) {
			return;
		}

		$ids = $this->cart_stock_ids();

		if ( ! in_array( $stock_id, $ids, true ) ) {
			return;
		}

		$ids = array_slice( array_values( array_filter( $ids, fn( int $id ): bool => ! isset( $this->stock_groups[ $blog ][ $id ] ) ) ), 0, self::COPY_MAP_BATCH );

		if ( count( $ids ) < 2 ) {
			return;
		}

		$this->cart_primed[ $blog ] = implode( ',', $ids );

		$variations = [];
		$products   = [];
		$anchors    = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( 'product_variation' === $post->post_type ) {
				$variations[] = $id;
				$anchors[]    = (int) $post->post_parent;
			} else {
				$products[] = $id;
				$anchors[]  = $id;
			}
		}

		/**
		 * Translation group repository.
		 *
		 * @var \PerfLocale\Database\Repository\TranslationGroupRepository $repo
		 */
		$repo = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$repo->prime_translations( ObjectType::Post, array_values( array_filter( $anchors ) ) );

		$prime = [];

		if ( [] !== $variations && $this->copy_map_ready() ) {
			$state = $this->read_copy_maps( $variations, ! $this->catalog_limits );

			foreach ( $variations as $id ) {
				$entry = $this->copy_reads[ $blog ]['rows'][ $id ] ?? false;

				if ( ! is_array( $entry ) || '' === $state['copy'] || $state['copy'] !== ( $entry['t'] ?? null ) || $state['vsig'] !== ( $entry['e'] ?? null ) || ! is_array( $entry['c'] ?? null ) ) {
					continue;
				}

				foreach ( $entry['c'] as $copy_parent => $row ) {
					$prime[] = (int) $copy_parent;

					if ( is_array( $row ) && is_int( $row['i'] ?? null ) ) {
						$prime[] = $row['i'];
					}
				}
			}
		}

		foreach ( $products as $id ) {
			$prime = array_merge( $prime, array_diff( wp_parse_id_list( wp_list_pluck( $repo->get_translations( $id, ObjectType::Post ), 'object_id' ) ), [ $id ] ) );
		}

		$prime = array_values( array_unique( array_filter( $prime ) ) );

		if ( [] !== $prime ) {
			_prime_post_caches( $prime, false, true );
		}
	}

	/**
	 * Delete the current blog's copy-map epoch, which retires every stored
	 * copy map of the blog in every process. Unconditional: whatever this
	 * process holds of the epoch, the store is asked, and it needs no write
	 * space in the cache. Without a persistent object cache its value row is
	 * read by its key and, when it is stored, both of its rows are deleted in
	 * one statement (delete_transient_rows()).
	 *
	 * @return void
	 */
	private function delete_copy_map_epoch(): void {
		$blog = get_current_blog_id();

		$this->copy_renewals[ $blog ] = ( $this->copy_renewals[ $blog ] ?? 0 ) + 1;
		unset( $this->copy_reads[ $blog ], $this->epochs_seen[ $blog ], $this->order_epochs[ $blog ] );

		if ( ! self::delete_transient_rows( self::COPY_MAP_EPOCH ) ) {
			delete_transient( self::COPY_MAP_EPOCH );
		}
	}

	/**
	 * Store a transient with an expiry in one statement where transients are
	 * rows of the options table (no persistent object cache, not installing):
	 * the value row and the timeout row that set_transient() writes, written
	 * together, inserted or replaced, never autoloaded. This request's options
	 * cache is then left as add_option() leaves it: both values cached, both
	 * names out of `notoptions`. False when transients are stored elsewhere or
	 * the statement fails, or when a callback filters reads of these rows
	 * (transient_rows_read_filtered()); the caller then stores it with
	 * set_transient(). The option and transient hooks of these rows do not
	 * fire; no PerfLocale callback acts on them.
	 *
	 * With $only_new the rows are only inserted, never replaced, and true
	 * means both were inserted. Otherwise nothing of this value is known to
	 * be stored (another process stored a row first): this request's cached
	 * copies of both rows are dropped, and their names taken out of
	 * `notoptions`, so the next read asks the table.
	 *
	 * @param string $transient Transient name.
	 * @param mixed  $value     Value.
	 * @param int    $ttl       Seconds until it expires.
	 * @param bool   $only_new  Insert the rows only where neither is stored.
	 * @return bool
	 */
	private static function write_transient_rows( string $transient, mixed $value, int $ttl, bool $only_new = false ): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb || wp_using_ext_object_cache() || wp_installing() || self::transient_rows_read_filtered( $transient ) ) {
			return false;
		}

		$stored = maybe_serialize( $value );

		if ( ! is_string( $stored ) ) {
			return false;
		}

		$data    = '_transient_' . $transient;
		$timeout = '_transient_timeout_' . $transient;
		$expires = (string) ( time() + $ttl );

		// The value add_option() stores for a row that is not autoloaded.
		$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? 'off' : 'no';

		// The rows in key order, the order a delete of both locks them in.
		if ( $only_new ) {
			$sql = $wpdb->prepare(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s), (%s, %s, %s)',
				$wpdb->options,
				$data,
				$stored,
				$autoload,
				$timeout,
				$expires,
				$autoload
			);
		} else {
			$sql = $wpdb->prepare(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s), (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)',
				$wpdb->options,
				$data,
				$stored,
				$autoload,
				$timeout,
				$expires,
				$autoload
			);
		}

		if ( ! is_string( $sql ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The two rows set_transient() writes, in one prepared statement; the options cache is set below.
		$written = $wpdb->query( $sql );

		if ( false === $written ) {
			return false;
		}

		if ( $only_new && 2 !== $written ) {
			wp_cache_delete( $data, 'options' );
			wp_cache_delete( $timeout, 'options' );

			$notoptions = wp_cache_get( 'notoptions', 'options' );

			if ( is_array( $notoptions ) && ( isset( $notoptions[ $data ] ) || isset( $notoptions[ $timeout ] ) ) ) {
				unset( $notoptions[ $data ], $notoptions[ $timeout ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}

			return false;
		}

		wp_cache_set( $data, $stored, 'options' );
		wp_cache_set( $timeout, $expires, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && ( isset( $notoptions[ $data ] ) || isset( $notoptions[ $timeout ] ) ) ) {
			unset( $notoptions[ $data ], $notoptions[ $timeout ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		return true;
	}

	/**
	 * Whether a callback filters reads of a transient's rows before the
	 * options table answers them: WordPress's `pre_option` filter, the
	 * `pre_option_{$option}` filter of either row, or the transient's
	 * `pre_transient_{$transient}` filter. Code that answers those reads
	 * itself relies on the option and transient hooks of the writes, which
	 * only the transient API fires.
	 *
	 * @param string $transient Transient name.
	 * @return bool
	 */
	private static function transient_rows_read_filtered( string $transient ): bool {
		return false !== has_filter( 'pre_option' )
			|| false !== has_filter( 'pre_option__transient_' . $transient )
			|| false !== has_filter( 'pre_option__transient_timeout_' . $transient )
			|| false !== has_filter( 'pre_transient_' . $transient );
	}

	/**
	 * Delete a transient where transients are rows of the options table (no
	 * persistent object cache, not installing): one read of its value row by
	 * its key, as delete_option() reads it, then, when that row is stored, one
	 * statement that deletes the value row and the timeout row. No delete
	 * runs that could match no row: where the binary log records statements,
	 * such a delete is still a logged write.
	 * - Value row not stored: nothing is written and `notoptions` is left as
	 *   it is; a timeout row without its value row is left, as
	 *   delete_transient() leaves it. This request's cached copies of the two
	 *   rows are dropped: a copy of a value row the table does not hold was
	 *   read before another process deleted it.
	 * - Otherwise this request's options cache is left as delete_option()
	 *   leaves it: neither value cached, both names in `notoptions`.
	 * False when transients are stored elsewhere, a statement fails or cannot
	 * be prepared, or a callback filters reads of these rows
	 * (transient_rows_read_filtered()); the caller then deletes it with
	 * delete_transient(). The option and transient hooks of these rows do not
	 * fire; no PerfLocale callback acts on them.
	 *
	 * @param string $transient Transient name.
	 * @return bool
	 */
	private static function delete_transient_rows( string $transient ): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb || wp_using_ext_object_cache() || wp_installing() || self::transient_rows_read_filtered( $transient ) ) {
			return false;
		}

		$data    = '_transient_' . $transient;
		$timeout = '_transient_timeout_' . $transient;

		// One key: a unique-key lookup (a list of keys is a range read, which
		// costs more).
		$sql = $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s', $wpdb->options, $data );

		if ( ! is_string( $sql ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Whether the value row is stored, read past this request's options cache, in one prepared statement.
		$found = $wpdb->query( $sql );

		if ( false === $found ) {
			return false;
		}

		if ( 0 === $found ) {
			wp_cache_delete( $data, 'options' );
			wp_cache_delete( $timeout, 'options' );

			return true;
		}

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE option_name IN (%s, %s)', $wpdb->options, $data, $timeout );

		if ( ! is_string( $sql ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The two rows delete_transient() deletes, in one prepared statement; the options cache is cleared below.
		$deleted = $wpdb->query( $sql );

		if ( false === $deleted ) {
			return false;
		}

		$alloptions = wp_cache_get( 'alloptions', 'options' );

		if ( is_array( $alloptions ) && ( isset( $alloptions[ $data ] ) || isset( $alloptions[ $timeout ] ) ) ) {
			unset( $alloptions[ $data ], $alloptions[ $timeout ] );
			wp_cache_set( 'alloptions', $alloptions, 'options' );
		}

		wp_cache_delete( $data, 'options' );
		wp_cache_delete( $timeout, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$notoptions = is_array( $notoptions ) ? $notoptions : [];

		$notoptions[ $data ]    = true;
		$notoptions[ $timeout ] = true;

		wp_cache_set( 'notoptions', $notoptions, 'options' );

		return true;
	}

	/**
	 * Retire the current blog's stored copy maps after a change of which
	 * translation group an attribute term reads as (delete_copy_map_epoch()),
	 * and drop this process's memos built from those groups.
	 *
	 * @return void
	 */
	public function renew_copy_map_epoch(): void {
		$blog = get_current_blog_id();

		$this->copy_link_mark = \PerfLocale\Database\Repository\TranslationGroupRepository::link_changes();

		unset( $this->stock_groups[ $blog ], self::$canonical_attr_memo[ $blog ], self::$variation_maps[ $blog ], self::$first_matches[ $blog ], self::$variation_dups[ $blog ], self::$match_unique[ $blog ] );

		$this->delete_copy_map_epoch();
	}

	/**
	 * After a term's translation caches were flushed: when a translation
	 * group or link was written since the last renewal from here
	 * (TranslationGroupRepository::link_changes()), renew the copy-map epoch
	 * unless the term is known to be outside the `pa_*` attribute
	 * taxonomies. A flush with no write behind it (a term edit) renews
	 * nothing; one write renews once, however many members it flushes.
	 *
	 * @param mixed $object_id   Object id.
	 * @param mixed $object_type Object type.
	 * @return void
	 */
	public function renew_copy_map_after_term_flush( $object_id, $object_type = '' ): void {
		if ( 'term' !== $object_type || \PerfLocale\Database\Repository\TranslationGroupRepository::link_changes() === $this->copy_link_mark ) {
			return;
		}

		$term_id = is_numeric( $object_id ) ? (int) $object_id : 0;
		$term    = $term_id > 0 ? get_term( $term_id ) : null;

		if ( $term instanceof \WP_Term ) {
			$taxonomy = (string) $term->taxonomy;
		} else {
			$taxonomy = $this->deleted_terms[ get_current_blog_id() ][ $term_id ] ?? '';
		}

		if ( '' !== $taxonomy && ! str_starts_with( $taxonomy, 'pa_' ) ) {
			return;
		}

		$this->renew_copy_map_epoch();
	}

	/**
	 * Note a `pa_*` term's slug before it is edited (`edit_terms`).
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public function note_term_slug( $term_id, $taxonomy = '' ): void {
		if ( ! is_string( $taxonomy ) || ! str_starts_with( $taxonomy, 'pa_' ) ) {
			return;
		}

		$blog = get_current_blog_id();
		$id   = is_numeric( $term_id ) ? (int) $term_id : 0;
		$term = $id > 0 ? get_term( $id, $taxonomy ) : null;

		if ( count( $this->term_slugs[ $blog ] ?? [] ) >= 64 ) {
			unset( $this->term_slugs[ $blog ] );
		}

		$this->term_slugs[ $blog ][ $id ] = $term instanceof \WP_Term ? (string) $term->slug : '';
	}

	/**
	 * After a `pa_*` term was edited: renew the copy-map epoch when its slug
	 * changed or its old slug is unknown. WooCommerce rewrites the slug in
	 * its variations' meta with a direct query.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public function renew_copy_map_after_term_edit( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		unset( $tt_id );

		if ( ! is_string( $taxonomy ) || ! str_starts_with( $taxonomy, 'pa_' ) ) {
			return;
		}

		$blog   = get_current_blog_id();
		$id     = is_numeric( $term_id ) ? (int) $term_id : 0;
		$before = $this->term_slugs[ $blog ][ $id ] ?? '';

		unset( $this->term_slugs[ $blog ][ $id ] );

		$term = $id > 0 ? get_term( $id, $taxonomy ) : null;

		if ( '' === $before || ! $term instanceof \WP_Term || $term->slug !== $before ) {
			$this->renew_copy_map_epoch();
		}
	}

	/**
	 * After a term was deleted: note its taxonomy for the flushes that
	 * follow, and renew the copy-map epoch for a `pa_*` term.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public function renew_copy_map_after_term_delete( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		unset( $tt_id );

		$blog = get_current_blog_id();

		if ( count( $this->deleted_terms[ $blog ] ?? [] ) >= 64 ) {
			unset( $this->deleted_terms[ $blog ] );
		}

		$this->deleted_terms[ $blog ][ is_numeric( $term_id ) ? (int) $term_id : 0 ] = is_string( $taxonomy ) ? $taxonomy : '';

		if ( ! is_string( $taxonomy ) || str_starts_with( $taxonomy, 'pa_' ) ) {
			$this->renew_copy_map_epoch();
		}
	}

	/**
	 * After an existing product's type term was replaced outside WooCommerce's
	 * own type change: drop the stock groups and variation matches
	 * (forget_stock_matches()) and renew the copy-map epoch. A save that sets
	 * the same type again, and a new product's first type, change nothing.
	 *
	 * @param mixed $object_id  Object id.
	 * @param mixed $terms      Terms given.
	 * @param mixed $tt_ids     Term taxonomy ids set.
	 * @param mixed $taxonomy   Taxonomy.
	 * @param mixed $append     Whether the terms were appended.
	 * @param mixed $old_tt_ids Term taxonomy ids before.
	 * @return void
	 */
	public function forget_after_product_type_terms( $object_id, $terms = [], $tt_ids = [], $taxonomy = '', $append = false, $old_tt_ids = [] ): void {
		unset( $object_id, $terms );

		if ( 'product_type' !== $taxonomy ) {
			return;
		}

		$old = array_values( wp_parse_id_list( is_array( $old_tt_ids ) ? $old_tt_ids : [] ) );
		$new = array_values( wp_parse_id_list( is_array( $tt_ids ) ? $tt_ids : [] ) );

		if ( $append ) {
			$new = array_values( array_unique( array_merge( $old, $new ) ) );
		}

		sort( $old );
		sort( $new );

		if ( [] === $old || $old === $new ) {
			return;
		}

		$this->forget_stock_matches();
		$this->renew_copy_map_epoch();
	}

	/**
	 * Delete both epochs of the current blog, which retires every stored
	 * variation map and copy map of the blog.
	 *
	 * @return void
	 */
	public static function retire_stored_maps(): void {
		delete_transient( self::STORED_MAP_EPOCH );
		delete_transient( self::COPY_MAP_EPOCH );
	}

	/**
	 * Retire the stored maps when the stock sync setting changed. Hooked by
	 * the plugin in every request (Bootstrap), like the two below, because
	 * this class listens for changes only while the sync runs.
	 *
	 * @param mixed $settings Settings after the change.
	 * @param mixed $before   Settings before it.
	 * @return void
	 */
	public static function retire_after_settings_change( $settings = [], $before = [] ): void {
		$now = is_array( $settings ) ? (bool) ( $settings['wc_sync_stock'] ?? true ) : true;
		$was = is_array( $before ) ? (bool) ( $before['wc_sync_stock'] ?? true ) : true;

		if ( $now !== $was ) {
			self::retire_stored_maps();
		}
	}

	/**
	 * Retire the stored maps when WooCommerce is activated or deactivated.
	 *
	 * @param mixed $plugin Plugin basename.
	 * @return void
	 */
	public static function retire_after_plugin_toggle( $plugin = '' ): void {
		if ( 'woocommerce/woocommerce.php' === $plugin ) {
			self::retire_stored_maps();
		}
	}

	/**
	 * Legacy-WC fallback: refresh the price/SKU/stock-status columns of an
	 * EXISTING wc_product_meta_lookup row directly.
	 *
	 * Only the columns this class syncs are touched (sku, min/max price,
	 * onsale, stock_status); the row is never INSERTed - WC owns row
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
			// probe - a fixed SQL naming the column would error on WC < 9.1.
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
			// break the sync itself - the meta is already correct; only the
			// derived cache row stays stale, matching pre-fix behaviour.
			unset( $e );
		}
	}
}
