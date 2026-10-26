<?php
/**
 * WooCommerce stock alignment: the site's store (locks, fresh reads, WooCommerce's relative stock write).
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
 * StockAlignBackend on this site: `stockalign_<id>` + `invsync_<id>` Lock rows, reads past the object cache,
 * writes through wc_update_product_stock() so stock status, notices and other plugins' hooks run as usual.
 * Lease guard and its fixed TTL: see docs-ai/11-code-rationale.md, "WcStockAlignBackend: lease guard".
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class WcStockAlignBackend implements StockAlignBackend {

	/**
	 * The stock mirror.
	 *
	 * @var InventorySync
	 */
	private InventorySync $sync;

	/**
	 * Mirror locks this process holds, by member id.
	 *
	 * @var array<int, true>
	 */
	private array $mirror_held = [];

	/**
	 * "Aligning" locks this process holds, by member id.
	 *
	 * @var array<int, true>
	 */
	private array $align_held = [];

	/**
	 * Constructor.
	 *
	 * @param InventorySync $sync The stock mirror.
	 */
	public function __construct( InventorySync $sync ) {
		$this->sync = $sync;
	}

	/**
	 * {@inheritDoc}
	 */
	public function lock( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( ! Lock::acquire( StockSetMarker::align_lock( $id ), InventorySync::LOCK_TTL_OUTER ) ) {
				$this->release( $ids );
				return false;
			}

			$this->align_held[ $id ] = true;

			if ( ! Lock::acquire( $this->sync->lock_name( $id ), InventorySync::LOCK_TTL_OUTER ) ) {
				$this->release( $ids );
				return false;
			}

			$this->mirror_held[ $id ] = true;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function renew( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( ! isset( $this->align_held[ $id ] ) || ! Lock::extend( StockSetMarker::align_lock( $id ), InventorySync::LOCK_TTL_OUTER ) ) {
				return false;
			}

			if ( isset( $this->mirror_held[ $id ] ) && ! Lock::extend( $this->sync->lock_name( $id ), InventorySync::LOCK_TTL_OUTER ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function release_mirror( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( isset( $this->mirror_held[ $id ] ) ) {
				Lock::release( $this->sync->lock_name( $id ) );
				unset( $this->mirror_held[ $id ] );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function relock_mirror( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( isset( $this->mirror_held[ $id ] ) ) {
				continue;
			}

			if ( ! Lock::acquire( $this->sync->lock_name( $id ), InventorySync::LOCK_TTL_OUTER ) ) {
				$this->release_mirror( $ids );
				return false;
			}

			$this->mirror_held[ $id ] = true;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function release( array $ids ): void {
		$this->release_mirror( $ids );

		foreach ( $ids as $id ) {
			if ( isset( $this->align_held[ $id ] ) ) {
				Lock::release( StockSetMarker::align_lock( $id ) );
				unset( $this->align_held[ $id ] );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function read( array $ids ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( [] === $ids ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- A fresh read of one copy group under its locks (at most one row per member); the IN list holds only %d placeholders.
		$sql   = $wpdb->prepare(
			"SELECT ID, post_parent, post_type FROM %i WHERE post_type IN ('product', 'product_variation') AND post_status NOT IN ('trash', 'auto-draft') AND ID IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			array_merge( [ $wpdb->posts ], $ids )
		);
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $found ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
		}

		$posts = [];
		$owner = [];

		foreach ( $found as $row ) {
			$id = is_numeric( $row['ID'] ?? null ) ? (int) $row['ID'] : 0;

			if ( $id > 0 ) {
				$posts[ $id ] = is_numeric( $row['post_parent'] ?? null ) ? (int) $row['post_parent'] : 0;
				$owner[ $id ] = 'product_variation' === ( $row['post_type'] ?? '' ) ? $posts[ $id ] : 0;
			}
		}

		// Members' and parents' meta (a variation's opt-out follows its parent) from the DB: the object
		// cache may hold a value from before another process's write.
		$meta_ids = array_values( array_unique( array_filter( array_merge( array_keys( $posts ), array_values( $posts ) ) ) ) );

		foreach ( $meta_ids as $id ) {
			wp_cache_delete( $id, 'post_meta' );
		}

		update_meta_cache( 'post', $meta_ids );

		if ( '' !== self::db_error() ) {
			throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
		}

		$groups = self::link_groups( $meta_ids );
		$out    = [];

		foreach ( $owner as $id => $parent ) {
			$stock      = get_post_meta( $id, '_stock', true );
			$group      = $groups[ 0 === $parent ? $id : $parent ] ?? null;
			$sig        = 0 === $parent || null === $group ? '' : $this->sync->variation_signature( $id, $parent );
			$out[ $id ] = [
				'stock'     => is_numeric( $stock ) ? (float) $stock : null,
				'managed'   => 'yes' === get_post_meta( $id, '_manage_stock', true ),
				'opted_out' => $this->sync->is_sync_opted_out( $id ),
				'parent'    => $parent,
				'copy'      => null === $group || null === $sig ? null : $group . '|' . $sig,
			];
		}

		return $out;
	}

	/**
	 * The translation group of each of these products: id => group id (a product without a link is left out).
	 *
	 * @param list<int> $ids Product ids.
	 * @return array<int, int>
	 * @throws \RuntimeException When the read fails.
	 */
	private static function link_groups( array $ids ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( [] === $ids ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The links of one copy group under its locks, past the cache; the IN list holds only %d placeholders.
		$sql   = $wpdb->prepare(
			"SELECT object_id, group_id FROM %i WHERE type = 'post' AND object_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			array_merge( [ \PerfLocale\Database\Schema::table( 'translation_links' ) ], $ids )
		);
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $found ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
		}

		$out = [];

		foreach ( $found as $row ) {
			if ( is_numeric( $row['object_id'] ?? null ) && is_numeric( $row['group_id'] ?? null ) ) {
				$out[ (int) $row['object_id'] ] = (int) $row['group_id'];
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function write( int $id, float $delta, float $expect ): ?float {
		$product = wc_get_product( $id );
		$owner   = $product instanceof \WC_Product ? $product->get_stock_managed_by_id() : 0;

		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() || ! is_numeric( $owner ) || (int) $owner !== $id ) {
			throw new StockWriteFailed( '', true );
		}

		$this->sync->begin_writes();
		$this->sync->suspend_stock_mirror( $id );

		// Renew the lease right before the statement: a listener may block past the TTL. Only the statement of this
		// call's own wc_update_product_stock() is checked (is_own_statement()), never a listener's nested write.
		$blog  = get_current_blog_id();
		$moved = new StockWriteFailed( 'stock moved' );
		$ran   = null;
		$guard = function ( mixed $sql, mixed $product_id = 0, mixed $new_stock = null ) use ( $id, $blog, $delta, $expect, $moved, &$ran ): mixed {
			if ( ! is_numeric( $product_id ) || (int) $product_id !== $id || get_current_blog_id() !== $blog || ! self::is_own_statement() ) {
				return $sql;
			}

			if ( ! $this->renew( array_keys( $this->align_held ) ) ) {
				throw new StockLeaseLost();
			}

			$read = $this->write_if( $id, $sql, $delta, $expect, $new_stock, $moved );
			$ran  = is_numeric( $new_stock ) ? (float) $new_stock : null;

			return $read;
		};

		add_filter( 'woocommerce_update_product_stock_query', $guard, PHP_INT_MAX, 3 );

		try {
			$new = wc_update_product_stock( $product, abs( $delta ), $delta > 0 ? 'increase' : 'decrease' );
		} catch ( StockLeaseLost | StockWriteFailed $e ) {
			// The statement never ran: drop the delta Inventory Sync captured for it and the current-filter
			// entry the throw left. WP_Hook keeps the abandoned dispatch's frame (core has no finally): accepted.
			$this->sync->take_stock_delta( $id );
			self::leave_filter( 'woocommerce_update_product_stock_query' );

			if ( $e === $moved ) {
				return null;
			}

			throw $e;
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'PerfLocale stock alignment: the stock write of #%d failed: %s', $id, $e->getMessage() ) );

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text in the align report (WP-CLI table, JSON the settings screen sets as text); never echoed.
			// WooCommerce's and the database's text are shown; another plugin's message may hold anything,
			// so it is reported as stopped by a plugin, without the text. $ran: the statement ran before it.
			if ( $e instanceof \WC_Data_Exception ) {
				throw new StockWriteFailed( $e->getMessage(), false, false, $ran );
			}

			if ( '' !== self::db_error() ) {
				throw new StockWriteFailed( self::db_error(), false, false, $ran );
			}

			throw new StockWriteFailed( '', false, true, $ran );
		} finally {
			remove_filter( 'woocommerce_update_product_stock_query', $guard, PHP_INT_MAX );
			$this->sync->resume_stock_mirror( $id );
			$this->sync->end_writes();
		}

		if ( ! is_numeric( $new ) ) {
			throw new StockWriteFailed( '', true, false, $ran );
		}

		if ( '' !== self::db_error() ) {
			throw new StockWriteFailed( self::db_error(), false, false, $ran );
		}

		return (float) $new;
	}

	/**
	 * Run WooCommerce's relative statement only while the stock row still holds $expect, in one statement, so a
	 * SET or edit since the read is never shifted; returns a read-only statement for WooCommerce to run instead.
	 *
	 * @param int              $id        Product or variation id.
	 * @param mixed            $sql       WooCommerce's statement.
	 * @param float            $delta     Signed change.
	 * @param float            $expect    Its stock as last read.
	 * @param mixed            $new_stock The stock WooCommerce computed from its own read.
	 * @param StockWriteFailed $moved Thrown when the stock is not $expect.
	 * @return string
	 * @throws StockWriteFailed When the statement is not WooCommerce's or a query failed.
	 */
	private function write_if( int $id, mixed $sql, float $delta, float $expect, mixed $new_stock, StockWriteFailed $moved ): string {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The stock row WooCommerce's own statement writes, read and written past the cache; $sql is WooCommerce's prepared statement.
		$read = (string) $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = '_stock'", $wpdb->postmeta, $id );
		$raw  = $wpdb->get_var( $read );

		if ( '' !== self::db_error() ) {
			throw new StockWriteFailed( self::db_error() );
		}

		$amount = $this->stock_amount( abs( $delta ) ) * ( $delta > 0 ? 1 : -1 );

		if ( ! is_numeric( $raw ) || ! StockDriftAlign::same( (float) $raw, $expect ) || ! is_numeric( $new_stock ) || ! StockDriftAlign::same( (float) $new_stock, $this->stock_amount( (float) $raw ) + $amount ) ) {
			throw $moved;
		}

		// Another plugin's statement is not ours to change.
		if ( ! is_string( $sql ) || 1 !== preg_match( '/^\s*UPDATE\s.+\sWHERE\s+post_id\s*=\s*' . $id . '\s+AND\s+meta_key\s*=\s*\'_stock\'\s*$/is', $sql ) ) {
			throw new StockWriteFailed( '', false, true );
		}

		$done = $wpdb->query( $sql . (string) $wpdb->prepare( ' AND meta_value = %s', $raw ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( false === $done || '' !== self::db_error() ) {
			throw new StockWriteFailed( self::db_error() );
		}

		if ( 0 === $done ) {
			throw $moved;
		}

		return $read;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Whether the statement being filtered is the one write()'s own wc_update_product_stock() runs.
	 *
	 * Other code enters only through a hook: up to write(), exactly one WP_Hook frame (the guard's dispatch), then
	 * update_product_stock() (a store may call its parent's), then one wc_update_product_stock(); other frames ignored.
	 *
	 * @return bool
	 */
	private static function is_own_statement(): bool {
		$chain = '';

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Call identity of one stock statement during an alignment write, not debug output.
			$call = ( $frame['class'] ?? '' ) . '::' . $frame['function'];

			if ( self::class . '::write' === $call ) {
				return 1 === preg_match( '/^hook,(store,)+wc,$/', $chain );
			}

			if ( 'WP_Hook' === ( $frame['class'] ?? '' ) ) {
				$chain .= 'hook,';
			} elseif ( '::wc_update_product_stock' === $call ) {
				$chain .= 'wc,';
			} elseif ( str_ends_with( $call, '::update_product_stock' ) ) {
				$chain .= 'store,';
			}
		}

		return false;
	}

	/**
	 * Pop $hook off $wp_current_filter when on top: a throw skips apply_filters()'s array_pop().
	 *
	 * @param string $hook Filter name.
	 * @return void
	 */
	private static function leave_filter( string $hook ): void {
		global $wp_current_filter;

		if ( is_array( $wp_current_filter ) && [] !== $wp_current_filter && end( $wp_current_filter ) === $hook ) {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function markers( array $ids ): array {
		return StockSetMarker::read( $ids );
	}

	/**
	 * {@inheritDoc}
	 */
	public function clear_marker( int $id, string $value ): void {
		StockSetMarker::clear( $id, $value );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stock_amount( float $value ): float {
		$amount = function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $value ) : $value;

		return is_numeric( $amount ) ? (float) $amount : $value;
	}

	/**
	 * The last database error ('' when none), read afresh each time.
	 *
	 * @return string
	 *
	 * @phpstan-impure
	 */
	private static function db_error(): string {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		return $wpdb->last_error;
	}

	/**
	 * {@inheritDoc}
	 */
	public function filter_value( float $value, array $group, ?array $reference ): mixed {
		/**
		 * The value a stock alignment sets a copy group's copies to.
		 * Null or a non-number skips the group; runs on dry runs too.
		 *
		 * @hook  perflocale/woocommerce/stock_align_value
		 * @since 1.0.7.4
		 *
		 * @param float                     $value     The reference value.
		 * @param array<string, mixed>      $group     One perflocale_stock_drift() entry.
		 * @param array<string, mixed>|null $reference The reference member (product_id, parent_id, language, stock); null for a callable.
		 */
		return apply_filters( 'perflocale/woocommerce/stock_align_value', $value, $group, $reference );
	}

	/**
	 * {@inheritDoc}
	 */
	public function aligned( int $product_id, float $old, float $new, int $group_id ): void {
		/**
		 * After one copy's stock was aligned: fires once its group finished, never for an undone write.
		 *
		 * @hook  perflocale/woocommerce/stock_aligned
		 * @since 1.0.7.4
		 *
		 * @param int   $product_id Product or variation id written.
		 * @param float $old        Stock read under the lock before the write.
		 * @param float $new        Stock WooCommerce returned after the write.
		 * @param int   $group_id   The group's anchor id.
		 */
		do_action( 'perflocale/woocommerce/stock_aligned', $product_id, $old, $new, $group_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only; text the screen must not show.
		}
	}
}
