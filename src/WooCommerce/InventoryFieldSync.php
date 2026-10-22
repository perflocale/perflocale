<?php
/**
 * WooCommerce inventory sync: the shared-field sync of product writes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Enum\ObjectType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The part of InventorySync that runs when a product is written: the product
 * editor, Quick/Bulk Edit, the Variations panel and its bulk actions, the REST
 * API, the CSV importer, any other WooCommerce save (CRUD updates), the flows
 * that sync their own save, and the end-of-request rollup of sibling variable
 * parents. InventorySync builds it the first time one of these runs, so a
 * request that writes no product (a cart, a checkout, a Store API read)
 * neither builds nor loads it. It works through the InventorySync instance it
 * belongs to (locks, matches, lookup refreshes, the stock deltas and the
 * write depth).
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class InventoryFieldSync {

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
		// product in every language - the WC addon already exempts shared
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
	 * Variation fields treated as shared product facts across languages.
	 *
	 * Unlike the variable PARENT (where price keys are child-derived
	 * aggregates WC owns and this class must not copy), a variation's
	 * `_price` is a real single value - safe and necessary to mirror.
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
	 * The InventorySync instance this belongs to.
	 *
	 * @var InventorySync
	 */
	private readonly InventorySync $sync;

	/**
	 * Constructor.
	 *
	 * @param InventorySync $sync The InventorySync instance this belongs to.
	 */
	public function __construct( InventorySync $sync ) {
		$this->sync        = $sync;
		$this->flow_claims = new \WeakMap();
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
	 * Sync every variation touched by a Variations-tab bulk action, rolling
	 * the sibling parents up once at the end.
	 *
	 * @param string            $bulk_action Bulk action slug (unused - field diffing is value-based).
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
		if ( $this->sync->writes_running() || ! is_array( $updated_props ) || [] === $updated_props ) {
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
		if ( $product_id <= 0 || $this->sync->has_stock_op( $product_id ) ) {
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
		$this->sync->forget_stock_announcement( $product_id );

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
		if ( ! $this->sync->sync_enabled() ) {
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

		if ( ! $this->sync->sync_enabled() || $this->sync->is_sync_opted_out( $product_id ) ) {
			return;
		}

		// Atomically acquire a cross-request lock. add_option() is backed by
		// an INSERT against the UNIQUE option_name key, so two concurrent
		// requests cannot both enter this method - the loser bails.
		if ( ! Lock::acquire( $this->sync->lock_name( $product_id ), InventorySync::LOCK_TTL_OUTER ) ) {
			return;
		}

		$this->sync->begin_writes();

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

				if ( $sibling_id === $product_id || $this->sync->is_sync_opted_out( $sibling_id ) ) {
					continue;
				}

				// Lock the sibling too so save_post hooks fired as a
				// side-effect of update_post_meta/wc_delete_product_transients
				// don't bounce back into this method. If the lock is already
				// held (another request racing on the same sibling) skip it -
				// the other request will finish the sync there.
				if ( ! Lock::acquire( $this->sync->lock_name( $sibling_id ), InventorySync::LOCK_TTL_INNER ) ) {
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
						// when the new value equals the stored one - and an
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

						// update_post_meta() unslashes; the value read from the
						// source is unslashed, so it is slashed for the write.
						$result  = update_post_meta( $sibling_id, $field, \PerfLocale\Helper::deep_slash( $value ) );
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

					if ( $derive && $rederive && $this->sync->refresh_derived_stock_status( $sibling_id ) ) {
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
					$this->sync->refresh_product_lookup( $sibling_id, $stock_written, null === $only_keys && $manage_written );

					// The copied setting can have made the sibling stock-managed;
					// its lookup row then needs its own quantity and status.
					if ( $derive && null !== $only_keys && 'yes' === (string) get_post_meta( $sibling_id, '_manage_stock', true ) ) {
						$this->sync->refresh_stock_lookup_columns( $sibling_id );
					}

					$synced_ids[] = $sibling_id;
				} finally {
					Lock::release( $this->sync->lock_name( $sibling_id ) );
				}
			}

			if ( ! empty( $synced_ids ) ) {
				/** @hook perflocale/woocommerce/inventory_synced Fires after inventory sync completes. */
				do_action( 'perflocale/woocommerce/inventory_synced', $product_id, $synced_ids, $fields );
			}
		} finally {
			$this->sync->end_writes();
			Lock::release( $this->sync->lock_name( $product_id ) );
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
		$this->sync->take_stock_delta( $product->get_id() );

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
	 * sibling sync per row - the filter lets operators skip it and rely on a
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
	 * as side-effects of the writes bail instead of looping - and a
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

		if ( ! $this->sync->sync_enabled() || $this->sync->is_sync_opted_out( $variation_id ) ) {
			return [];
		}

		if ( ! Lock::acquire( $this->sync->lock_name( $variation_id ), InventorySync::LOCK_TTL_OUTER ) ) {
			return [];
		}

		$this->sync->begin_writes();

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

			$src_attrs       = $this->sync->normalise_variation_attrs( $variation->get_attributes(), (int) $parent_id );
			$synced_ids      = [];
			$touched_parents = [];

			foreach ( $parent_sibs as $link ) {
				$sib_parent_id = (int) $link->object_id;

				if ( $sib_parent_id === $parent_id || $this->sync->is_sync_opted_out( $sib_parent_id ) ) {
					continue;
				}

				$sib_variation_id = $this->sync->match_sibling_variation( $sib_parent_id, $src_attrs );

				// A variation-level opt-out must block INCOMING writes too, not
				// only outgoing ones - the source-side gate reads the same flag,
				// so checking just the parent here would leave the key
				// protecting a variation in one direction only. Placed after
				// the match so the cheaper parent-level skip short-circuits
				// first and this meta read only happens on real candidates.
				if ( $sib_variation_id <= 0 || $sib_variation_id === $variation_id || $this->sync->is_sync_opted_out( $sib_variation_id ) ) {
					continue;
				}

				if ( ! Lock::acquire( $this->sync->lock_name( $sib_variation_id ), InventorySync::LOCK_TTL_INNER ) ) {
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

						// update_post_meta() unslashes; the value read from the
						// source is unslashed, so it is slashed for the write.
						$result  = update_post_meta( $sib_variation_id, $field, \PerfLocale\Helper::deep_slash( $value ) );
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

					if ( $derive && $rederive && $this->sync->refresh_derived_stock_status( $sib_variation_id ) ) {
						$changed = true;
					}

					if ( ! $changed ) {
						continue;
					}

					// Rebuild the sibling variation's wc_product_meta_lookup row
					// (price sort / stock filter / SKU search) - the raw meta
					// writes bypass WC's change tracking. Re-entrancy safe: the
					// sibling lock is held. Only a copy that wrote `_stock` lets
					// the refresh re-set the quantity, and a full copy that
					// wrote the stock-management setting has it set the stock
					// columns from the committed meta.
					$this->sync->refresh_product_lookup( $sib_variation_id, $stock_written, null === $only_keys && $manage_written );

					if ( $derive && null !== $only_keys && 'yes' === (string) get_post_meta( $sib_variation_id, '_manage_stock', true ) ) {
						$this->sync->refresh_stock_lookup_columns( $sib_variation_id );
					}

					$synced_ids[]                      = $sib_variation_id;
					$touched_parents[ $sib_parent_id ] = true;
				} finally {
					Lock::release( $this->sync->lock_name( $sib_variation_id ) );
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
			$this->sync->end_writes();
			Lock::release( $this->sync->lock_name( $variation_id ) );
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
	 * this class bails immediately; skipped (not queued) under contention -
	 * the next sibling save rebuilds the same derived state from the same
	 * children.
	 *
	 * @param array<int, int> $parent_ids Sibling parent IDs to roll up.
	 * @return void
	 */
	private function rollup_sibling_parents( array $parent_ids ): void {
		foreach ( $parent_ids as $sib_parent_id ) {
			$sib_parent_id = (int) $sib_parent_id;

			if ( $sib_parent_id <= 0 || ! Lock::acquire( $this->sync->lock_name( $sib_parent_id ), InventorySync::LOCK_TTL_INNER ) ) {
				continue;
			}

			$this->sync->begin_writes();

			try {
				\WC_Product_Variable::sync( $sib_parent_id );

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $sib_parent_id );
				}
			} finally {
				$this->sync->end_writes();
				Lock::release( $this->sync->lock_name( $sib_parent_id ) );
			}
		}
	}
}
