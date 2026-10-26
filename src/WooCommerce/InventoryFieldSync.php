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
 * The product-write half of InventorySync: editor, Quick/Bulk Edit, Variations panel, REST, CSV import and CRUD saves.
 *
 * Built on first use, so a request that writes no product (cart, checkout, Store API read) never loads it.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class InventoryFieldSync {

	/**
	 * Physical product fields that must be identical across all language variants.
	 *
	 * Filterable via `perflocale/woocommerce/synced_product_fields`.
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
		// Identity. The GTIN (WC 9.1+) names the same physical product in every language.
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
		// Not total_sales: a copy would overwrite each variant's own count, not add to it.
	];

	/**
	 * The price meta keys. They move as one group: WooCommerce rewrites
	 * `_price` and may clear `_sale_price` itself when one of them changes.
	 */
	private const PRICE_KEYS = [ '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to' ];

	/**
	 * WooCommerce props a save can change => the shared meta keys their change mirrors.
	 *
	 * No `stock_quantity` on purpose: the stock hooks mirror quantity (relatively for orders), never this path.
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
	 * Actions that run a save with a sync of its own; a save recorded inside one waits for it to end.
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
	 * A variation's `_price` is a real value, unlike a variable parent's child-derived aggregate, so it is mirrored.
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
	 * Shared keys a WooCommerce save changed, waiting for it (or its flow, when `waiting`) to end; blog id => product id.
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
	 * Products a REST or CSV-importer flow will save and then sync itself; the CRUD trigger leaves that save to it.
	 *
	 * Weak, so an object the flow never saves leaves no claim behind.
	 *
	 * @var \WeakMap<\WC_Product, true>
	 */
	private \WeakMap $flow_claims;

	/**
	 * Sibling variable parents waiting for their end-of-request rollup; blog id => parent id => true.
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
	 * Variations whose stock status a products-list Quick/Bulk Edit just changed; blog id => parent id => variation id.
	 *
	 * WooCommerce saves each child, then fires the edit action for the parent only, whose handler reads this list.
	 *
	 * @var array<int, array<int, array<int, true>>>
	 */
	private array $quick_edit_children = [];

	/**
	 * Sync every variation a Variations-tab bulk action touched, then roll the sibling parents up once.
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
	 * Record the shared keys a WooCommerce save changed, for sync_after_crud_update().
	 *
	 * Runs in every product and variation save, order stock saves included, so a save without a shared field does no I/O.
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

		// A captured, unreplayed relative stock statement: the save inside wc_update_product_stock(), owned by the stock hooks.
		if ( $product_id <= 0 || $this->sync->has_stock_op( $product_id ) ) {
			return;
		}

		if ( isset( $this->flow_claims[ $product ] ) ) {
			unset( $this->flow_claims[ $product ] );

			return;
		}

		// Derived state never pushes: variable/grouped prices and status come from children, a managed status from its quantity.
		// get_manage_stock() is WooCommerce's own test and reads 'parent' for a variation whose parent manages the stock.
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
	 * Copy the shared keys a finished WooCommerce save changed to the translation siblings.
	 *
	 * Inside an OWN_SYNC_FLOWS flow the record waits: the flow's full sync drops its own object's record, so nothing
	 * is copied twice, and flush_crud_updates() copies the rest.
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
	 * Drop what a create recorded: WooCommerce reports every prop of a new object as changed.
	 *
	 * The new object is a copy (translation clone, duplicate, added variation), not the record to follow.
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
	 * Copy the records left waiting inside a flow, once the flow's own handlers have run.
	 *
	 * Hooked last on each flow's closing action and to `shutdown`; a nested flow leaves the flush to the outer one.
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
	 * End of a translation's creation: drop what the copy and its variations recorded, then flush the rest.
	 *
	 * The copy is built from the product it translates and must push nothing back to it.
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
	 * Mark the object a REST or CSV-importer flow is about to save; the flow syncs it right after.
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
	 * Hooked to `shutdown`, as WooCommerce defers the edited parent's rollup; the queue empties first, so a rerun is a no-op.
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
	 * Queue sibling parents for one rollup each at the end of the request, not one per variation save.
	 *
	 * Rolled up at once while switched to another blog (the queue must not outlive the switch) or during shutdown.
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
	 * Whether a copy leaves a sibling's `_stock_status` alone: a stock-managed sibling derives it from its own quantity.
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
	 * @param array<int, string>|null $only_keys  Copy only these synced fields (a code save passes its changed keys); null: all.
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

		// Cross-request lock: one INSERT on the UNIQUE option_name wins, the other request returns.
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

			// Not on a variable parent: WC keeps one _price row per child price there, and update_post_meta() would
			// collapse that min/max index. WC rebuilds them from the sibling's own variations.
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

			// A stock-managed sibling never takes the source's `_stock_status`: it re-derives it from its own quantity.
			// A full copy re-derives only where a stock field differs, so an in-sync sibling costs no query.
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

				// The sibling lock stops hooks fired by these writes from re-entering; a held one means another request syncs it.
				if ( ! Lock::acquire( $this->sync->lock_name( $sibling_id ), InventorySync::LOCK_TTL_INNER ) ) {
					continue;
				}

				try {
					$changed = false;

					// A sibling that manages its stock, before and after this copy, owns `_stock`: only the stock hooks write it.
					// An absolute copy would overwrite an order's decrement not yet relayed to the source, losing that sale.
					// See docs-ai/11-code-rationale.md, "InventoryFieldSync: owned stock quantity".
					$owned = 'yes' === get_post_meta( $sibling_id, '_manage_stock', true )
						&& ( ! array_key_exists( '_manage_stock', $values ) || 'yes' === $values['_manage_stock'] );

					$rederive       = null !== $only_keys;
					$stock_written  = false;
					$manage_written = false;

					foreach ( $values as $field => $value ) {
						if ( $owned && '_stock' === $field ) {
							continue;
						}

						// Skip equal values: update_post_meta() returns false for them too, so the log keeps real failures only.
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

					// Raw meta writes bypass WC's change tracking: rebuild the lookup row under the held sibling lock.
					// Only a `_stock` write re-sets the quantity; a full copy that wrote `_manage_stock` sets the stock columns too.
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
		// Bulk Edit's stock change fires set_stock, which normally consumed the delta; taking it again lets none outlive this save.
		$this->sync->take_stock_delta( $product->get_id() );

		$this->sync_product_fields( $product->get_id() );

		// The parent copy carries only its derived status: sync each variation this edit changed, then roll the sibling
		// parents up once so their status comes from their own children again.
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
	 * Record a variation whose stock status a Quick/Bulk Edit of its parent changed, for sync_on_quick_or_bulk_edit().
	 *
	 * Only one that does not manage stock: a managed variation's status follows its quantity, which the stock hooks sync.
	 *
	 * @param int|string $variation_id Variation ID.
	 * @param mixed      $status       New stock status (unused; the sync reads the saved meta).
	 * @param mixed      $variation    Saved variation object.
	 * @return void
	 */
	public function note_quick_edit_child( $variation_id, $status = '', $variation = null ): void {
		unset( $status );

		// WooCommerce's wrapper action around both edits; every other variation save, orders included, returns here.
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
	 * Trigger sync for a product or variation row written by the WooCommerce CSV importer.
	 *
	 * A translated catalog syncs siblings per row; perflocale/woocommerce/sync_on_import can skip that for a later resync.
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
	 * Copy an edited variation's shared fields to the matching variation of each sibling parent, then roll those up.
	 *
	 * Matched as in the order stock path: exact attribute set under the linked parent, a diverged sibling skipped, never guessed.
	 * The source parent is left alone: WC's own save runs WC_Product_Variable::sync() for it.
	 *
	 * @param int                     $variation_id   Saved variation ID.
	 * @param bool                    $rollup_parents Roll touched sibling parents up inline (bulk edit passes false, rolls up once).
	 * @param array<int, string>|null $only_keys      Copy only these synced fields (a code save passes its changed keys); null: all.
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

			// The same wc_sync_prices opt-out as the product flow.
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

				// A variation opt-out blocks incoming writes too; read after the match, so only real candidates pay for it.
				if ( $sib_variation_id <= 0 || $sib_variation_id === $variation_id || $this->sync->is_sync_opted_out( $sib_variation_id ) ) {
					continue;
				}

				if ( ! Lock::acquire( $this->sync->lock_name( $sib_variation_id ), InventorySync::LOCK_TTL_INNER ) ) {
					continue;
				}

				try {
					$changed = false;

					// See sync_product_fields(): a sibling that manages its stock owns `_stock`.
					// A parent-managed variation stores 'no' here and takes the source's value.
					$owned = 'yes' === get_post_meta( $sib_variation_id, '_manage_stock', true )
						&& ( ! array_key_exists( '_manage_stock', $values ) || 'yes' === $values['_manage_stock'] );

					$rederive       = null !== $only_keys;
					$stock_written  = false;
					$manage_written = false;

					foreach ( $values as $field => $value ) {
						if ( $owned && '_stock' === $field ) {
							continue;
						}

						// Skip equal values: update_post_meta() returns false for them too.
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

					// Raw meta writes bypass WC's change tracking: rebuild the lookup row as in sync_product_fields().
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
	 * Rebuild the derived aggregates of sibling variable parents whose children were just synced.
	 *
	 * Locked so a hook fired inside the rollup bails; skipped under contention, as the next save rebuilds the same state.
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
