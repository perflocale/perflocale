<?php
/**
 * WooCommerce inventory sync: the marker a stock SET leaves when an alignment held its mirror's lock.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Concurrency\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a stock SET whose mirror was skipped because an alignment held the lock; the alignment replays it.
 * One plain-SQL options row per product (never the options API, like the lock rows): see
 * docs-ai/11-code-rationale.md, "StockSetMarker: skipped SET replay".
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockSetMarker {

	/**
	 * Option name prefix of a marker.
	 */
	public const PREFIX = 'perflocale_stockset_';

	/**
	 * Lock name prefix of the "aligning" lock an alignment holds per member.
	 */
	public const ALIGN_LOCK_PREFIX = 'stockalign_';

	/**
	 * Name of the "aligning" lock of a product or variation.
	 *
	 * @param int $product_id Product or variation id.
	 * @return string
	 */
	public static function align_lock( int $product_id ): string {
		return self::ALIGN_LOCK_PREFIX . $product_id;
	}

	/**
	 * Whether an alignment holds this product's "aligning" lock now.
	 *
	 * @param int $product_id Product or variation id.
	 * @return bool
	 */
	public static function aligning( int $product_id ): bool {
		return $product_id > 0 && Lock::seconds_remaining( self::align_lock( $product_id ) ) > 0;
	}

	/**
	 * A SET's mirror skipped a busy copy: leave a marker only when an alignment holds that id's "aligning" lock.
	 * Two concurrent SETs with no alignment running leave none.
	 *
	 * @param int $source_id The product or variation whose stock was set.
	 * @param int $busy_id   The id whose lock was taken.
	 * @return void
	 */
	public static function note_skipped_set( int $source_id, int $busy_id ): void {
		if ( self::aligning( $busy_id ) ) {
			self::record( $source_id );
		}
	}

	/**
	 * InventorySync::suspend_stock_mirror(): one more suspension of this id on the current blog.
	 *
	 * @param array<int, array<int, int>> $suspended InventorySync's suspensions, blog id => id => depth (updated).
	 * @param int                         $product_id Product or variation id.
	 * @return void
	 */
	public static function suspend_mirror( array &$suspended, int $product_id ): void {
		$blog = get_current_blog_id();

		$suspended[ $blog ][ $product_id ] = ( $suspended[ $blog ][ $product_id ] ?? 0 ) + 1;
	}

	/**
	 * InventorySync::resume_stock_mirror(): one suspension of this id on the current blog fewer.
	 *
	 * @param array<int, array<int, int>> $suspended InventorySync's suspensions, blog id => id => depth (updated).
	 * @param int                         $product_id Product or variation id.
	 * @return void
	 */
	public static function resume_mirror( array &$suspended, int $product_id ): void {
		$blog  = get_current_blog_id();
		$depth = ( $suspended[ $blog ][ $product_id ] ?? 0 ) - 1;

		if ( $depth > 0 ) {
			$suspended[ $blog ][ $product_id ] = $depth;
			return;
		}

		unset( $suspended[ $blog ][ $product_id ] );

		if ( [] === ( $suspended[ $blog ] ?? [] ) ) {
			unset( $suspended[ $blog ] );
		}
	}

	/**
	 * Record that this product's stock was SET while its mirror was skipped (a newer SET replaces the time).
	 *
	 * @param int $product_id Product or variation whose stock was set.
	 * @return void
	 */
	public static function record( int $product_id ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( $product_id <= 0 ) {
			return;
		}

		$name  = self::PREFIX . $product_id;
		$value = self::now_micro();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A marker row read and written only with plain SQL, like the lock rows (Lock).
		$sql      = $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $name, $value, 'no' );
		$inserted = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement above.

		if ( 1 !== $inserted ) {
			$sql = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $value, $name );

			if ( is_string( $sql ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement above.
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( $name, 'options' );
	}

	/**
	 * The markers of these products: id => [ set time in microseconds, stored value ].
	 *
	 * @param list<int> $product_ids Product or variation ids.
	 * @return array<int, array{0: int, 1: string}>
	 */
	public static function read( array $product_ids ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$names = [];

		foreach ( $product_ids as $id ) {
			if ( $id > 0 ) {
				$names[] = self::PREFIX . $id;
			}
		}

		if ( [] === $names ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One read of a copy group's markers (at most one row per member); the IN list holds only %s placeholders.
		$sql   = $wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')',
			array_merge( [ $wpdb->options ], $names )
		);
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$name  = is_string( $row['option_name'] ?? null ) ? $row['option_name'] : '';
			$value = is_string( $row['option_value'] ?? null ) ? $row['option_value'] : '';
			$id    = substr( $name, strlen( self::PREFIX ) );

			if ( str_starts_with( $name, self::PREFIX ) && \PerfLocale\Helper::is_ascii_digits( $id ) && '' !== $value && \PerfLocale\Helper::is_ascii_digits( $value ) ) {
				$out[ (int) $id ] = [ (int) $value, $value ];
			}
		}

		return $out;
	}

	/**
	 * Remove a marker if it still holds the value read, so a newer SET keeps its own marker.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $value      The stored value read().
	 * @return void
	 */
	public static function clear( int $product_id, string $value ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$name = self::PREFIX . $product_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A marker row read and written only with plain SQL, like the lock rows (Lock).
		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $value );

		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement above.
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( $name, 'options' );
	}

	/**
	 * The current time, in whole microseconds.
	 *
	 * @return string
	 */
	private static function now_micro(): string {
		return sprintf( '%.0f', microtime( true ) * 1000000 );
	}
}
