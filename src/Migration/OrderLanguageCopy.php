<?php
/**
 * Copies a source plugin's order language to PerfLocale's order meta.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The order-language step the WPML and TranslatePress importers share.
 *
 * Source meta `wpml_language` / `trp_language`; PerfLocale sends order emails in `_perflocale_language`.
 */
final class OrderLanguageCopy {

	/**
	 * PerfLocale's order meta key.
	 */
	public const META_KEY = '_perflocale_language';

	/**
	 * Orders read per batch.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Copy the source's order language to `_perflocale_language`.
	 *
	 * `shop_order` only, never replacing an existing value; written through the meta CRUD (keeps HPOS sync)
	 * without a full order save. Reads whichever storage WooCommerce uses: HPOS tables or posts.
	 *
	 * @param \wpdb                   $wpdb       Database.
	 * @param string                  $source_key The source plugin's order meta key.
	 * @param callable(string):string $slug_for   The PerfLocale language slug for a stored source code; '' when none matches.
	 * @param callable|null           $progress   Called with orders read and in total (0 while not known), at the start, per batch and at the end.
	 * @param bool                    $count      Whether to count the orders (one query, past one batch); else the total stays 0 until the end.
	 * @return array{written: int, missing: array<string, int>, error: string} Orders given a language; orders per unmatched source code; the first read's DB error, or ''.
	 */
	public static function copy( \wpdb $wpdb, string $source_key, callable $slug_for, ?callable $progress = null, bool $count = false ): array {
		$out = [
			'written' => 0,
			'missing' => [],
			'error'   => '',
		];

		if ( ! function_exists( 'wc_get_order' ) ) {
			return $out;
		}

		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// Tables and the column names that differ: order ID, meta row's order, order type, meta row ID.
		if ( $hpos ) {
			$meta    = $wpdb->prefix . 'wc_orders_meta';
			$orders  = $wpdb->prefix . 'wc_orders';
			$o_id    = 'id';
			$m_id    = 'order_id';
			$o_type  = 'type';
			$meta_pk = 'id';
		} else {
			$meta    = $wpdb->postmeta;
			$orders  = $wpdb->posts;
			$o_id    = 'ID';
			$m_id    = 'post_id';
			$o_type  = 'post_type';
			$meta_pk = 'meta_id';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$codes = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT m.meta_value FROM %i m INNER JOIN %i o ON o.%i = m.%i AND o.%i = %s WHERE m.meta_key = %s',
				$meta,
				$orders,
				$o_id,
				$m_id,
				$o_type,
				'shop_order',
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $wpdb->last_error !== '' ) {
			$out['error'] = $wpdb->last_error;
			return $out;
		}

		// On HPOS a meta change otherwise re-saves the whole order: new modified date and woocommerce_update_order
		// (webhooks, analytics) for every old order. Compatibility sync still copies the meta to posts.
		$meta_only = static fn(): bool => false;
		$done      = 0;
		$total     = 0;

		if ( $progress !== null && ! empty( $codes ) ) {
			$progress( 0, 0 );
		}

		foreach ( (array) $codes as $code ) {
			$code    = (string) $code;
			$slug    = (string) $slug_for( $code );
			$last_id = 0;
			$missing = 0;

			do {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT DISTINCT m.%i FROM %i m INNER JOIN %i o ON o.%i = m.%i AND o.%i = %s
						LEFT JOIN %i pl ON pl.%i = m.%i AND pl.meta_key = %s
						WHERE m.meta_key = %s AND m.meta_value = %s AND pl.%i IS NULL AND m.%i > %d
						ORDER BY m.%i ASC LIMIT %d',
						$m_id,
						$meta,
						$orders,
						$o_id,
						$m_id,
						$o_type,
						'shop_order',
						$meta,
						$m_id,
						$m_id,
						self::META_KEY,
						$source_key,
						$code,
						$meta_pk,
						$m_id,
						$last_id,
						$m_id,
						self::BATCH_SIZE
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( ! is_array( $ids ) || $ids === [] ) {
					break;
				}

				$ids     = array_map( 'intval', $ids );
				$last_id = (int) end( $ids );
				$fetched = count( $ids );
				$done   += $fetched;

				if ( $progress !== null && $count && $total === 0 && $fetched === self::BATCH_SIZE ) {
					$total = self::count_orders( $wpdb, $meta, $orders, $o_id, $m_id, $o_type, $meta_pk, $source_key );
				}

				if ( $slug === '' ) {
					$missing += $fetched;
				} else {
					foreach ( $ids as $order_id ) {
						$order = wc_get_order( $order_id );

						if ( ! $order instanceof \WC_Order || $order->get_type() !== 'shop_order' || (string) $order->get_meta( self::META_KEY, true ) !== '' ) {
							continue;
						}

						$order->update_meta_data( self::META_KEY, $slug );
						add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', $meta_only, PHP_INT_MAX );

						try {
							$order->save_meta_data();
						} finally {
							remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', $meta_only, PHP_INT_MAX );
						}

						++$out['written'];
					}
				}

				\PerfLocale\Background\MigrationCacheHelper::release_batch_memory();

				if ( $progress !== null ) {
					$progress( $done, $total );
				}
			} while ( $fetched === self::BATCH_SIZE );

			if ( $missing > 0 ) {
				$out['missing'][ $code ] = $missing;
			}
		}

		if ( $progress !== null && ! empty( $codes ) ) {
			$progress( $done, $done );
		}

		return $out;
	}

	/**
	 * Orders with the source's language and without a PerfLocale language.
	 *
	 * @param \wpdb  $wpdb       Database.
	 * @param string $meta       Order meta table.
	 * @param string $orders     Order table.
	 * @param string $o_id       Order ID column.
	 * @param string $m_id       Meta row's order column.
	 * @param string $o_type     Order type column.
	 * @param string $meta_pk    Meta row ID column.
	 * @param string $source_key The source plugin's order meta key.
	 * @return int
	 */
	private static function count_orders( \wpdb $wpdb, string $meta, string $orders, string $o_id, string $m_id, string $o_type, string $meta_pk, string $source_key ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count for the import's progress, on an import that reads more than one batch.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT m.%i) FROM %i m INNER JOIN %i o ON o.%i = m.%i AND o.%i = %s
				LEFT JOIN %i pl ON pl.%i = m.%i AND pl.meta_key = %s
				WHERE m.meta_key = %s AND pl.%i IS NULL',
				$m_id,
				$meta,
				$orders,
				$o_id,
				$m_id,
				$o_type,
				'shop_order',
				$meta,
				$m_id,
				$m_id,
				self::META_KEY,
				$source_key,
				$meta_pk
			)
		);
	}
}
