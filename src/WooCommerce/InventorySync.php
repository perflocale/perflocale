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
 * physical properties: stock, SKU, price, weight, and dimensions. This class
 * syncs these fields to sibling language variants whenever a product is saved.
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
		$this->cache = $cache;
	}

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
	 * @param int $product_id Sibling product ID whose meta was just synced.
	 * @return void
	 */
	private function refresh_product_lookup( int $product_id ): void {
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
		} elseif ( true === $product->get_manage_stock() && function_exists( 'wc_update_product_stock' ) ) {
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
			// price/SKU meta sync a NON-stock-managed sibling's
			// wc_product_meta_lookup row (backing catalog price sort/filter
			// and SKU search) would stay stale until its next real save.
			// Update exactly the columns this class syncs, on the EXISTING
			// row only (0 affected rows when WC hasn't created one = no-op,
			// matching WC's own lazy behaviour).
			$this->update_legacy_lookup_row( $product );
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
		// Sync after WooCommerce saves all product meta.
		add_action( 'woocommerce_process_product_meta', [ $this, 'sync_product_fields' ], 100, 1 );

		// Sync when saved via WooCommerce REST API (programmatic / bulk edits).
		add_action( 'woocommerce_rest_insert_product_object', [ $this, 'sync_on_rest_save' ], 10, 1 );

		// Sync stock when WooCommerce changes it via orders (purchase, cancel, refund).
		// These fire from wc_update_product_stock(), not from the admin product editor.
		// Without these, buying from the EN store reduces EN stock but DE/FR keep old values.
		add_action( 'woocommerce_product_set_stock', [ $this, 'sync_on_stock_change' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'sync_on_stock_change' ], 10, 1 );

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

		// CSV importer rows (products AND variations) — an imported price
		// list otherwise updates only the imported language's products.
		add_action( 'woocommerce_product_import_inserted_product_object', [ $this, 'sync_on_import' ], 10, 2 );

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
	 * Copy shared physical fields to all language variants of a product.
	 *
	 * @param int $product_id Post ID of the saved product.
	 * @return void
	 */
	public function sync_product_fields( int $product_id ): void {
		if ( ! $this->sync_enabled() || $this->is_sync_opted_out( $product_id ) ) {
			return;
		}

		// Atomically acquire a cross-request lock. add_option() is backed by
		// an INSERT against the UNIQUE option_name key, so two concurrent
		// requests cannot both enter this method - the loser bails.
		if ( ! Lock::acquire( $this->lock_name( $product_id ), self::LOCK_TTL_OUTER ) ) {
			return;
		}

		try {
			$base_fields = self::SHARED_FIELDS;
			$price_keys  = [ '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to' ];

			// Exclude price fields if wc_sync_prices is disabled.
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( $plugin->has( 'settings' ) && ! (bool) $plugin->get( 'settings' )->get( 'wc_sync_prices', true ) ) {
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

			if ( empty( $fields ) ) {
				return;
			}

			$repo         = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$translations = $repo->get_translations( $product_id, ObjectType::Post );

			// No sibling translations - nothing to sync.
			if ( count( $translations ) <= 1 ) {
				return;
			}

			// Collect meta values from the canonical (saved) product once.
			$values = [];

			foreach ( $fields as $field ) {
				$values[ $field ] = get_post_meta( $product_id, $field, true );
			}

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
					foreach ( $values as $field => $value ) {
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

						$result = update_post_meta( $sibling_id, $field, $value );

						if ( $result === false && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( sprintf( 'PerfLocale InventorySync: update_post_meta failed for sibling %d field %s', $sibling_id, $field ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}

					// Rebuild the sibling's wc_product_meta_lookup row from the
					// meta just written. update_post_meta() bypasses WC's CRUD
					// change-tracking, so the lookup (which backs catalog
					// price-sort / stock-filter / SKU search) would otherwise go
					// stale. Re-entrancy is safe: the sibling lock is held.
					$this->refresh_product_lookup( $sibling_id );

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

		if ( ! $this->sync_enabled() || $this->is_sync_opted_out( $product_id ) ) {
			return;
		}

		if ( ! Lock::acquire( $this->lock_name( $product_id ), self::LOCK_TTL_OUTER ) ) {
			return;
		}

		try {
			$repo         = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$translations = $repo->get_translations( $product_id, ObjectType::Post );

			if ( count( $translations ) <= 1 ) {
				return;
			}

			$stock_quantity = $product->get_stock_quantity();
			$stock_status   = $product->get_stock_status();
			$synced_ids     = [];

			foreach ( $translations as $link ) {
				$sibling_id = (int) $link->object_id;

				if ( $sibling_id === $product_id || $this->is_sync_opted_out( $sibling_id ) ) {
					continue;
				}

				if ( ! Lock::acquire( $this->lock_name( $sibling_id ), self::LOCK_TTL_INNER ) ) {
					continue;
				}

				try {
					// See the price-sync loop above: update_post_meta() returns
					// false for value-unchanged too, so only treat a write that
					// CHANGED something as a candidate failure — an in-sync
					// sibling is normal, not an error to log.
					$stock_same  = ( (string) get_post_meta( $sibling_id, '_stock', true ) === (string) $stock_quantity );
					$status_same = ( (string) get_post_meta( $sibling_id, '_stock_status', true ) === (string) $stock_status );
					$stock_ok    = $stock_same ? true : update_post_meta( $sibling_id, '_stock', $stock_quantity );
					$status_ok   = $status_same ? true : update_post_meta( $sibling_id, '_stock_status', $stock_status );

					if ( ( $stock_ok === false || $status_ok === false ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'PerfLocale InventorySync: stock update failed for sibling %d', $sibling_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}

					// Rebuild the sibling's wc_product_meta_lookup row (stock /
					// stock_status). Raw update_post_meta above bypasses WC's
					// change-tracking, so without this every order/refund would
					// leave the translated product showing stale stock in shop
					// queries. Re-entrancy is safe: the sibling lock is held.
					$this->refresh_product_lookup( $sibling_id );

					$synced_ids[] = $sibling_id;
				} finally {
					Lock::release( $this->lock_name( $sibling_id ) );
				}
			}

			if ( ! empty( $synced_ids ) ) {
				/** @hook perflocale/woocommerce/inventory_synced Fires after inventory sync completes. */
				do_action( 'perflocale/woocommerce/inventory_synced', $product_id, $synced_ids, [ '_stock', '_stock_status' ] );
			}
		} finally {
			Lock::release( $this->lock_name( $product_id ) );
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
	 * sibling's attributes we skip it (never guess). Same locked, change-only
	 * write path as the parent flow; raw update_post_meta does NOT re-fire
	 * woocommerce_variation_set_stock, so there is no re-entrancy loop.
	 *
	 * @param \WC_Product_Variation $variation Variation whose stock changed.
	 * @return void
	 */
	private function sync_variation_stock( \WC_Product_Variation $variation ): void {
		$variation_id = $variation->get_id();
		$parent_id    = $variation->get_parent_id();

		if ( $parent_id <= 0 ) {
			return;
		}

		if ( ! $this->sync_enabled() || $this->is_sync_opted_out( $variation_id ) ) {
			return;
		}

		// Outer lock on the VARIATION being changed.
		if ( ! Lock::acquire( $this->lock_name( $variation_id ), self::LOCK_TTL_OUTER ) ) {
			return;
		}

		try {
			$repo        = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$parent_sibs = $repo->get_translations( $parent_id, ObjectType::Post );

			if ( count( $parent_sibs ) <= 1 ) {
				return;
			}

			$stock_quantity = $variation->get_stock_quantity();
			$stock_status   = $variation->get_stock_status();
			$src_attrs      = $this->normalise_variation_attrs( $variation->get_attributes() );
			$synced_ids     = [];

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
					// Change-only writes: update_post_meta returns false for an
					// unchanged value too, so only a real change is a candidate
					// failure (mirrors the parent flow).
					$stock_same  = ( (string) get_post_meta( $sib_variation_id, '_stock', true ) === (string) $stock_quantity );
					$status_same = ( (string) get_post_meta( $sib_variation_id, '_stock_status', true ) === (string) $stock_status );
					$stock_ok    = $stock_same ? true : update_post_meta( $sib_variation_id, '_stock', $stock_quantity );
					$status_ok   = $status_same ? true : update_post_meta( $sib_variation_id, '_stock_status', $stock_status );

					if ( ( $stock_ok === false || $status_ok === false ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( sprintf( 'PerfLocale InventorySync: variation stock update failed for sibling %d', $sib_variation_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}

					// Rebuild the sibling variation's wc_product_meta_lookup row
					// so shop/stock queries reflect the new stock immediately.
					$this->refresh_product_lookup( $sib_variation_id );

					$synced_ids[] = $sib_variation_id;
				} finally {
					Lock::release( $this->lock_name( $sib_variation_id ) );
				}
			}

			if ( ! empty( $synced_ids ) ) {
				/** @hook perflocale/woocommerce/inventory_synced Fires after inventory sync completes. */
				do_action( 'perflocale/woocommerce/inventory_synced', $variation_id, $synced_ids, [ '_stock', '_stock_status' ] );
			}
		} finally {
			Lock::release( $this->lock_name( $variation_id ) );
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
		$this->sync_variation_fields( $variation->get_id() );
	}

	/**
	 * Trigger sync after a products-list Quick Edit / Bulk Edit save.
	 *
	 * @param \WC_Product $product Saved product.
	 * @return void
	 */
	public function sync_on_quick_or_bulk_edit( \WC_Product $product ): void {
		$this->sync_product_fields( $product->get_id() );
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
	 * @param int  $variation_id    Saved variation ID.
	 * @param bool $rollup_parents  Roll touched sibling parents up inline.
	 *                              The bulk-edit handler passes false and
	 *                              amortises the rollup across the whole run.
	 * @return array<int, true> Touched sibling parent IDs (keys).
	 */
	public function sync_variation_fields( int $variation_id, bool $rollup_parents = true ): array {
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

		try {
			$fields = self::VARIATION_FIELDS;

			$plugin = \PerfLocale\Plugin::get_instance();

			// Mirror the parent-product flow: price mirroring is opt-out via
			// the same wc_sync_prices setting (per-language pricing setups).
			if ( $plugin->has( 'settings' ) && ! (bool) $plugin->get( 'settings' )->get( 'wc_sync_prices', true ) ) {
				$fields = array_diff( $fields, [ '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to' ] );
			}

			/** @hook perflocale/woocommerce/synced_variation_fields Filter which variation meta keys are synced. */
			$fields = (array) apply_filters( 'perflocale/woocommerce/synced_variation_fields', $fields, $variation_id );

			if ( empty( $fields ) ) {
				return [];
			}

			$repo        = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$parent_sibs = $repo->get_translations( $parent_id, ObjectType::Post );

			if ( count( $parent_sibs ) <= 1 ) {
				return [];
			}

			$values = [];

			foreach ( $fields as $field ) {
				$values[ $field ] = get_post_meta( $variation_id, $field, true );
			}

			$src_attrs       = $this->normalise_variation_attrs( $variation->get_attributes() );
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

					foreach ( $values as $field => $value ) {
						// Change-only writes: update_post_meta returns false for
						// an unchanged value too, so only a real change is a
						// candidate failure (mirrors every other sync flow here).
						if ( (string) get_post_meta( $sib_variation_id, $field, true ) === (string) $value ) {
							continue;
						}

						$result  = update_post_meta( $sib_variation_id, $field, $value );
						$changed = true;

						if ( $result === false && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( sprintf( 'PerfLocale InventorySync: variation field sync failed for sibling %d field %s', $sib_variation_id, $field ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}

					if ( ! $changed ) {
						continue;
					}

					// Rebuild the sibling variation's wc_product_meta_lookup row
					// (price sort / stock filter / SKU search) — the raw meta
					// writes bypass WC's change tracking. Re-entrancy safe: the
					// sibling lock is held.
					$this->refresh_product_lookup( $sib_variation_id );

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

			try {
				\WC_Product_Variable::sync( $sib_parent_id );

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $sib_parent_id );
				}
			} finally {
				Lock::release( $this->lock_name( $sib_parent_id ) );
			}
		}
	}

	/**
	 * Normalise a variation attribute map for order-independent comparison.
	 *
	 * @param array<string, string> $attrs Raw WC variation attributes.
	 * @return array<string, string>
	 */
	private function normalise_variation_attrs( array $attrs ): array {
		$out = [];

		foreach ( $attrs as $key => $value ) {
			$out[ (string) $key ] = (string) $value;
		}

		ksort( $out );

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
	 * Attribute-signature => variation-id map for one sibling parent, built
	 * ONCE per request.
	 *
	 * A bulk stock pass (order with many line items, stock import) fires the
	 * sync once per source variation; scanning the sibling parent's whole
	 * child set on every call is O(variations^2) product hydrations per
	 * parent — measured at ~18ms/variation on a 50-variation product. Stock
	 * writes never change attributes, and the map covers SIBLING products
	 * (not the one being saved), so a per-request memo cannot serve a stale
	 * match in any real flow. Signatures come from the ksort'd normalised
	 * attribute map, so encoding is deterministic; on a duplicate signature
	 * the FIRST child wins, matching the previous scan's first-match order.
	 *
	 * The static is blog-keyed (multisite: switch_to_blog must never serve
	 * another blog's product ids) and the per-blog bucket is reset at a small
	 * bound so a mega-bulk run across many parents stays memory-flat.
	 *
	 * @param int $parent_id Sibling PARENT (variable) product id.
	 * @return array<string, int> attr-signature => variation id.
	 */
	private function sibling_variation_map( int $parent_id ): array {
		static $cache = [];

		$blog = get_current_blog_id();

		if ( isset( $cache[ $blog ][ $parent_id ] ) ) {
			return $cache[ $blog ][ $parent_id ];
		}

		$map    = [];
		$parent = wc_get_product( $parent_id );

		if ( $parent instanceof \WC_Product_Variable ) {
			foreach ( $parent->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );

				if ( ! $child instanceof \WC_Product_Variation ) {
					continue;
				}

				$sig = (string) wp_json_encode( $this->normalise_variation_attrs( $child->get_attributes() ) );

				if ( ! isset( $map[ $sig ] ) ) {
					$map[ $sig ] = (int) $child_id;
				}
			}
		}

		if ( count( $cache[ $blog ] ?? [] ) >= 16 ) {
			unset( $cache[ $blog ] );
		}

		$cache[ $blog ][ $parent_id ] = $map;

		return $map;
	}

	/**
	 * Legacy-WC fallback: refresh the price/SKU columns of an EXISTING
	 * wc_product_meta_lookup row directly.
	 *
	 * Only the columns this class syncs are touched (sku, min/max price,
	 * onsale); the row is never INSERTed — WC owns row creation. Values come
	 * from the product object's own accessors so variable parents get their
	 * true child-price range.
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
						"UPDATE {$wpdb->prefix}wc_product_meta_lookup
						SET sku = %s, global_unique_id = %s, min_price = %f, max_price = %f, onsale = %d
						WHERE product_id = %d",
						(string) $product->get_sku( 'edit' ),
						(string) $product->get_global_unique_id( 'edit' ),
						(float) ( '' === $min ? 0 : $min ),
						(float) ( '' === $max ? 0 : $max ),
						$product->is_on_sale( 'edit' ) ? 1 : 0,
						$product->get_id()
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}wc_product_meta_lookup
						SET sku = %s, min_price = %f, max_price = %f, onsale = %d
						WHERE product_id = %d",
						(string) $product->get_sku( 'edit' ),
						(float) ( '' === $min ? 0 : $min ),
						(float) ( '' === $max ? 0 : $max ),
						$product->is_on_sale( 'edit' ) ? 1 : 0,
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
