<?php
/**
 * The WooCommerce add-on's PHP API: perflocale()->woocommerce().
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * perflocale()->woocommerce(), and perflocale()->addon( 'woocommerce' ) once the add-on booted; one object per request.
 * Availability is checked on every call (WP_Error when inactive); no capability check, like WooCommerce's stock functions.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 * @since 1.0.7.4
 *
 * @phpstan-import-type StockRow from StockDriftAlign
 * @phpstan-import-type StockPublicGroup from StockDriftAlign
 * @phpstan-type StockCopyRow array{group: int, product_id: int, parent_id: int, language: string, old: float, new: float, reference: int}
 * @phpstan-type StockLeftRow array{group: int, code: string, reason: string}
 */
final class Api {

	/**
	 * The instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * One per request: instance().
	 */
	private function __construct() {
	}

	/**
	 * The instance, created on the first call.
	 *
	 * @return self
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * The copy groups whose stock differs. Reads only.
	 *
	 *     perflocale()->woocommerce()->stock_drift( [ 'product_ids' => [ 123 ], 'limit' => 10 ] );
	 *     // [ [ 'group' => 123, 'parent_id' => 0, 'members' => [
	 *     //     [ 'product_id' => 123, 'parent_id' => 0, 'language' => 'en', 'stock' => 7.0 ],
	 *     //     [ 'product_id' => 456, 'parent_id' => 0, 'language' => 'de', 'stock' => 5.0 ] ] ], ... ]
	 *
	 * `group` is the group's anchor id: the default-language product, or its
	 * variation for a variation group (as in `wp perflocale stock-drift`).
	 *
	 * @since 1.0.7.4
	 *
	 * @param array{product_ids?: list<int>, limit?: int} $args `product_ids`: only the groups of these
	 *        products (any language; 1-500, not variations; empty: every
	 *        product). `limit`: stop after this many groups (0: all).
	 * @return list<StockPublicGroup>|\WP_Error
	 */
	public function stock_drift( array $args = [] ): array|\WP_Error {
		$sync = self::ready();

		if ( $sync instanceof \WP_Error ) {
			return $sync;
		}

		$valid = StockAlignArgs::drift( $args );

		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}

		try {
			$site      = new StockAlignSite( $sync );
			$group_ids = self::groups( $site, $valid['product_ids'] );

			if ( $group_ids instanceof \WP_Error || [] === $group_ids ) {
				return $group_ids instanceof \WP_Error ? $group_ids : [];
			}

			$pages = $site->pages();
			$out   = [];
			$after = 0;

			do {
				$page = $pages( $after, StockDriftAlign::DEFAULT_BATCH, $group_ids );

				foreach ( $page['groups'] as $group ) {
					$out[] = StockDriftAlign::public_group( $group );

					if ( $valid['limit'] > 0 && count( $out ) >= $valid['limit'] ) {
						return $out;
					}
				}

				$after = $page['next'];
			} while ( null !== $after );

			return $out;
		} catch ( \RuntimeException $e ) {
			return self::read_failed( [] );
		}
	}

	/**
	 * Set the stock of each differing copy to the reference value; dry run by default.
	 *
	 *     perflocale()->woocommerce()->align_stock( [
	 *         'from'    => static fn( array $group ): ?float => My_Erp::stock_for( $group['group'] ),
	 *         'dry_run' => false,
	 *     ] );
	 *
	 * In this version it always returns a WP_Error (`perflocale_stock_align_off`): aligning is off.
	 *
	 * Returns `planned` (dry run) or `changed` copy rows plus `skipped` / `failed` groups. A group whose
	 * stock changed while aligned is in `changed` and also in `skipped` (`changed_while_aligning`).
	 *
	 * @since 1.0.7.4
	 *
	 * @param array{from?: string|callable, product_ids?: list<int>, dry_run?: bool} $args `from`: 'default'
	 *        (the default-language copy), an active language slug, or a
	 *        callable that receives one stock_drift() entry and returns an
	 *        int|float, or null to skip the group (called once per drifting
	 *        group, outside any lock). `product_ids` as in stock_drift().
	 *        `dry_run`: true (default) returns the plan and writes nothing.
	 * @return array{changed: list<StockCopyRow>, skipped: list<StockLeftRow>, failed: list<StockLeftRow>}|array{planned: list<StockCopyRow>, skipped: list<StockLeftRow>, failed: list<StockLeftRow>}|\WP_Error
	 */
	public function align_stock( array $args = [] ): array|\WP_Error {
		$off = self::align_off();

		if ( null !== $off ) {
			return $off;
		}

		$sync = self::ready();

		if ( $sync instanceof \WP_Error ) {
			return $sync;
		}

		try {
			$site = new StockAlignSite( $sync );
		} catch ( \RuntimeException $e ) {
			return self::read_failed( [] );
		}

		$valid = StockAlignArgs::align( $args, $site->active_slugs() );

		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}

		$copies  = [];
		$skipped = [];
		$failed  = [];

		try {
			$group_ids = self::groups( $site, $valid['product_ids'] );

			if ( $group_ids instanceof \WP_Error ) {
				return $group_ids;
			}

			if ( [] !== $group_ids ) {
				$site->engine()->run(
					[
						'from'      => $site->reference( $valid['from'] ),
						'dry_run'   => $valid['dry_run'],
						'group_ids' => $group_ids,
					],
					static function ( array $row ) use ( &$copies, &$skipped, &$failed ): void {
						if ( 'planned' === $row['status'] || 'changed' === $row['status'] ) {
							$copies[] = self::copy_row( $row );
						} elseif ( 'failed' === $row['status'] ) {
							$failed[] = self::left_row( $row );
						} else {
							$skipped[] = self::left_row( $row );
						}
					}
				);
			}
		} catch ( \RuntimeException $e ) {
			return self::read_failed( $valid['dry_run'] ? [] : $copies );
		}

		if ( $valid['dry_run'] ) {
			return [
				'planned' => $copies,
				'skipped' => $skipped,
				'failed'  => $failed,
			];
		}

		return [
			'changed' => $copies,
			'skipped' => $skipped,
			'failed'  => $failed,
		];
	}

	/**
	 * Aligning stock is off in this version (the check stays): the error every align path returns.
	 *
	 * @internal
	 *
	 * @return \WP_Error|null
	 */
	public static function align_off(): ?\WP_Error {
		return new \WP_Error( 'perflocale_stock_align_off', __( 'Aligning stock is not available in this version.', 'perflocale' ) );
	}

	/**
	 * A planned or changed engine row as align_stock() returns it.
	 *
	 * @internal
	 *
	 * @param StockRow $row An engine row.
	 * @return StockCopyRow
	 */
	public static function copy_row( array $row ): array {
		return [
			'group'      => $row['group'],
			'product_id' => $row['product_id'],
			'parent_id'  => $row['parent_id'],
			'language'   => $row['language'],
			'old'        => $row['old'] ?? 0.0,
			'new'        => $row['new'] ?? 0.0,
			'reference'  => $row['reference'] ?? 0,
		];
	}

	/**
	 * A skipped or failed engine row as align_stock() returns it.
	 *
	 * @internal
	 *
	 * @param StockRow $row An engine row.
	 * @return StockLeftRow
	 */
	public static function left_row( array $row ): array {
		return [
			'group'  => $row['group'],
			'code'   => $row['code'] ?? '',
			'reason' => $row['reason'] ?? '',
		];
	}

	/**
	 * The Inventory Sync instance when stock alignment can run in this request, else a 409 WP_Error.
	 *
	 * @internal
	 *
	 * @return InventorySync|\WP_Error
	 */
	public static function ready(): InventorySync|\WP_Error {
		if ( ! did_action( 'perflocale/addons/loaded' ) ) {
			return new \WP_Error( 'perflocale_too_early', __( 'Call this on the init action or later, after PerfLocale\'s add-ons have loaded.', 'perflocale' ), [ 'status' => 409 ] );
		}

		if ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			return new \WP_Error( 'perflocale_wc_switched_blog', __( 'Stock alignment cannot run after switch_to_blog(). Run it on that site, for example with WP-CLI --url.', 'perflocale' ), [ 'status' => 409 ] );
		}

		if ( ! class_exists( 'WooCommerce' ) || ! \PerfLocale\Helper::get_instance()->is_addon_active( 'woocommerce' ) ) {
			return new \WP_Error( 'perflocale_wc_inactive', __( 'Stock alignment needs WooCommerce and the PerfLocale WooCommerce add-on. Activate both, then try again.', 'perflocale' ), [ 'status' => 409 ] );
		}

		$sync = InventorySyncHooks::instance();

		if ( null === $sync || ! $sync->sync_enabled() ) {
			return new \WP_Error( 'perflocale_wc_sync_off', __( 'Stock alignment needs Inventory Sync. Turn on "Sync stock, SKU, and pricing across all language variants" in PerfLocale > Settings > Addons > WooCommerce.', 'perflocale' ), [ 'status' => 409 ] );
		}

		return $sync;
	}

	/**
	 * The groups to scan: null for every group, else the products' groups (empty when they have none).
	 *
	 * @param StockAlignSite $site        The site.
	 * @param list<int>      $product_ids Product ids (empty: every product).
	 * @return list<int>|null|\WP_Error
	 * @throws \RuntimeException When a read fails.
	 */
	private static function groups( StockAlignSite $site, array $product_ids ): array|null|\WP_Error {
		return [] === $product_ids ? null : $site->group_ids( $product_ids );
	}

	/**
	 * The read-failure error; the copies already aligned are in its data.
	 *
	 * @param list<StockCopyRow> $changed Changed rows so far.
	 * @return \WP_Error
	 */
	private static function read_failed( array $changed ): \WP_Error {
		return new \WP_Error(
			'perflocale_wc_read_failed',
			__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ),
			[
				'status'  => 500,
				'changed' => $changed,
			]
		);
	}
}
