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

require_once __DIR__ . '/StockChangeLock.php';

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
	 * Nanoseconds a read may end after the stock change locks were asked for; later reads base no write (D72).
	 * Defence in depth: the fence check (I1) does not depend on it.
	 */
	public const READ_DEADLINE_NS = 1000000000;

	/**
	 * Seconds after which a fence row without a liveness lock counts as stale; until then its owner may still run.
	 */
	public const FENCE_STALE = 3600;

	/**
	 * The blog's fence generation read right after the locks; null while none are held.
	 *
	 * @var string|null
	 */
	private ?string $gen = null;

	/**
	 * The hrtime() right before the stock change locks were asked for; 0 while none are held.
	 *
	 * @var int|float
	 */
	private int|float $locked_at = 0;

	/**
	 * A read ended past READ_DEADLINE_NS since $locked_at: no more writes until release().
	 *
	 * @var bool
	 */
	private bool $late = false;

	/**
	 * Translation groups whose stock change locks this alignment holds.
	 *
	 * @var list<int>
	 */
	private array $locked_groups = [];

	/**
	 * The connection handle the stock change locks were taken on.
	 *
	 * @var object|null
	 */
	private ?object $handle = null;

	/**
	 * The alignment epoch value this alignment wrote (StockChangeLock::ALIGNED), until settle().
	 *
	 * @var string|null
	 */
	private ?string $epoch = null;

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
		$groups = $this->groups( $ids );

		if ( null === $groups ) {
			return false;
		}

		// The stock change locks first: a busy group touches no option lock, so a SET's mirror never leaves a marker
		// for an alignment that then gives up.
		$this->locked_at = hrtime( true );

		if ( ! StockChangeLock::hold( $groups ) ) {
			$this->locked_at = 0;
			return false;
		}

		$this->locked_groups = $groups;

		$this->late   = false;
		$this->handle = StockChangeLock::handle();

		// Before any read: a change a reconnect hid from the slots sees this alignment by the epoch.
		if ( ! $this->bump( $groups ) ) {
			$this->release( $ids );
			return false;
		}

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

		// Before any read that bases a write: a fenced change still in flight makes the group busy.
		$this->gen = $this->fences( $groups );

		if ( null === $this->gen ) {
			$this->release( $ids );
			return false;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function renew( array $ids ): bool {
		if ( $this->late || ( $this->locked_at > 0 && ! StockChangeLock::still_held( $this->locked_groups, $this->handle ) ) ) {
			return false;
		}

		foreach ( $ids as $id ) {
			if ( ! isset( $this->align_held[ $id ] ) || ! Lock::extend( StockSetMarker::align_lock( $id ), InventorySync::LOCK_TTL_OUTER ) ) {
				return false;
			}

			if ( isset( $this->mirror_held[ $id ] ) && ! Lock::extend( $this->sync->lock_name( $id ), InventorySync::LOCK_TTL_OUTER ) ) {
				return false;
			}
		}

		// A fence written since lock(): its change may sit half in the last read.
		return $this->locked_at <= 0 || $this->generation() === $this->gen;
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
		try {
			$this->release_mirror( $ids );

			foreach ( $ids as $id ) {
				if ( isset( $this->align_held[ $id ] ) ) {
					unset( $this->align_held[ $id ] );
					Lock::release( StockSetMarker::align_lock( $id ) );
				}
			}
		} finally {
			// Bumped, then stopped before any write: a change hidden across it is judged, not left unsure.
			if ( null !== $this->epoch ) {
				$this->settle( 0, 'none', [] );
			}

			// After the option locks, so a checkout that waited finds its mirror's lock free; also after a throw there.
			if ( $this->locked_at > 0 ) {
				StockChangeLock::unhold( $this->locked_groups );
			}

			$this->locked_groups = [];
			$this->locked_at     = 0;
			$this->late          = false;
			$this->handle        = null;
			$this->gen           = null;
			$this->epoch         = null;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function settle( int $reference, string $state, array $ids ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( null === $this->epoch ) {
			return;
		}

		[ $n, $group ] = array_pad( explode( '|', $this->epoch ), 2, '0' );
		$sql           = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $n . '|' . $group . '|' . $reference . '|' . $state . '|' . implode( ',', $ids ), StockChangeLock::ALIGNED, $this->epoch );
		$this->epoch   = null;

		// A newer alignment's bump wins: a change hidden across both is judged unsure.
		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Compare-and-set of the epoch row; prepared above.
		}
	}

	/**
	 * Bump the blog's alignment epoch (compare-and-set, not autoloaded) while every slot of $groups is held.
	 *
	 * @param list<int> $groups Translation group ids.
	 * @return bool False when it could not be written.
	 */
	private function bump( array $groups ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$group = 1 === count( $groups ) ? (int) $groups[0] : 0;

		for ( $try = 0; $try < 5; ++$try ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The epoch row past the cache on the writer; compare-and-set.
			$read = $wpdb->prepare( 'SELECT /* pfl-writer */ option_value FROM %i WHERE option_name = %s', $wpdb->options, StockChangeLock::ALIGNED );
			$raw  = is_string( $read ) ? self::writer()->get_var( $read ) : null;

			if ( '' !== self::db_error() ) {
				return false;
			}

			$value = ( ( is_string( $raw ) ? (int) explode( '|', $raw )[0] : 0 ) + 1 ) . '|' . $group . '|0|run';
			$sql   = is_string( $raw )
				? $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, StockChangeLock::ALIGNED, $raw )
				: $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, StockChangeLock::ALIGNED, $value );
			$done  = is_string( $sql ) ? $wpdb->query( $sql ) : false;
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			if ( 1 === $done ) {
				$this->epoch = $value;

				return true;
			}
		}

		return false;
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

		$this->sync->forget_opt_outs();
		update_meta_cache( 'post', $meta_ids );

		if ( '' !== self::db_error() ) {
			throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
		}

		$groups = self::link_groups( $meta_ids );
		$proved = [] !== $this->align_held && [] !== $owner ? $this->stock_meta( array_keys( $owner ) ) : null;
		$out    = [];

		foreach ( $owner as $id => $parent ) {
			$stock      = null === $proved ? get_post_meta( $id, '_stock', true ) : ( $proved[ $id ]['_stock'] ?? '' );
			$managed    = null === $proved ? get_post_meta( $id, '_manage_stock', true ) : ( $proved[ $id ]['_manage_stock'] ?? '' );
			$group      = $groups[ 0 === $parent ? $id : $parent ] ?? null;
			$sig        = 0 === $parent || null === $group ? '' : $this->sync->variation_signature( $id, $parent );
			$out[ $id ] = [
				'stock'     => is_numeric( $stock ) ? (float) $stock : null,
				'managed'   => 'yes' === $managed,
				'opted_out' => $this->sync->is_sync_opted_out( $id ),
				'parent'    => $parent,
				'copy'      => null === $group || null === $sig ? null : $group . '|' . $sig,
			];
		}

		// At the end: the member loop may still read past the cache (attributes, opt-outs).
		if ( $this->locked_at > 0 && hrtime( true ) - $this->locked_at > self::READ_DEADLINE_NS ) {
			$this->late = true;
		}

		return $out;
	}

	/**
	 * The translation groups of these members (a variation's is its parent's), read past the cache: every stock
	 * change that mirrors into them is keyed by one of these. Null when a read fails or a member has no group.
	 *
	 * @param list<int> $ids Member ids.
	 * @return list<int>|null
	 */
	private function groups( array $ids ): ?array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( [] === $ids ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The members' posts before their locks, past the cache; the IN list holds only %d placeholders.
		$sql   = $wpdb->prepare( 'SELECT ID, post_parent, post_type FROM %i WHERE ID IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( [ $wpdb->posts ], $ids ) );
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $found ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$anchors = [];

		foreach ( $ids as $id ) {
			$anchors[ $id ] = $id;
		}

		foreach ( $found as $row ) {
			if ( 'product_variation' === ( $row['post_type'] ?? '' ) && is_numeric( $row['ID'] ?? null ) && is_numeric( $row['post_parent'] ?? null ) ) {
				$anchors[ (int) $row['ID'] ] = (int) $row['post_parent'];
			}
		}

		try {
			$links = self::link_groups( array_values( array_unique( $anchors ) ) );
		} catch ( \RuntimeException $e ) {
			return null;
		}

		$groups = [];

		foreach ( $anchors as $anchor ) {
			if ( ! isset( $links[ $anchor ] ) ) {
				return null;
			}

			$groups[ $links[ $anchor ] ] = $links[ $anchor ];
		}

		sort( $groups );

		return $groups;
	}

	/**
	 * The fence check right after the locks: null (busy) while a fence row of these groups has a live owner; a row
	 * whose liveness lock the server freed, or one without a lock older than FENCE_STALE, is deleted (ordinary
	 * drift). Else the blog's fence generation ('0' before the first fence).
	 *
	 * @param list<int> $groups Translation group ids.
	 * @return string|null
	 */
	private function fences( array $groups ): ?string {
		$rows = $this->options( $groups );

		if ( null === $rows ) {
			return null;
		}

		foreach ( $rows as $name => $value ) {
			if ( ! str_starts_with( $name, StockChangeLock::FENCE ) ) {
				continue;
			}

			[ $lock, $at ] = array_pad( explode( '|', $value, 2 ), 2, '' );
			$stale         = '' === $lock ? ! is_numeric( $at ) || time() - (int) $at >= self::FENCE_STALE : '1' === $this->free( $lock );

			if ( ! $stale || ! $this->drop_fence( $name, $value ) ) {
				return null;
			}
		}

		return $rows[ StockChangeLock::GEN ] ?? '0';
	}

	/**
	 * The blog's fence generation now, or null when the read is not proved.
	 *
	 * @return string|null
	 */
	private function generation(): ?string {
		$rows = $this->options( [] );

		return null === $rows ? null : ( $rows[ StockChangeLock::GEN ] ?? '0' );
	}

	/**
	 * The generation and these groups' fence rows, read in one statement with Align's own lock row: a server that
	 * lacks Align's latest write (a lagging replica a proxy chose) or a failed read answers null.
	 *
	 * @param list<int> $groups Translation group ids whose fence rows to read.
	 * @return array<string, string>|null Option name => value.
	 */
	private function options( array $groups ): ?array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$sentinel = $this->sentinel();

		if ( null === $sentinel ) {
			return null;
		}

		$like = '';
		$args = [ $wpdb->options, StockChangeLock::GEN, $sentinel[0] ];

		foreach ( $groups as $group ) {
			$like  .= ' OR option_name LIKE %s';
			$args[] = $wpdb->esc_like( StockChangeLock::FENCE . $group . '_' ) . '%';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fence rows past the cache on the writer; the OR list holds only %s placeholders.
		$sql   = $wpdb->prepare( 'SELECT /* pfl-writer */ option_name, option_value FROM %i WHERE option_name IN (%s, %s)' . $like, $args );
		$found = is_string( $sql ) ? self::writer()->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $found ) || '' !== self::db_error() ) {
			return null;
		}

		$out = [];

		foreach ( $found as $row ) {
			if ( is_string( $row['option_name'] ?? null ) && is_string( $row['option_value'] ?? null ) ) {
				$out[ $row['option_name'] ] = $row['option_value'];
			}
		}

		return ( $out[ $sentinel[0] ] ?? null ) === $sentinel[1] ? $out : null;
	}

	/**
	 * The members' _stock and _manage_stock in one statement with Align's own lock row, renewed right before, so
	 * the answer holds every write up to now (fresher than the fence check); first row per key, as get_post_meta().
	 *
	 * @param list<int> $ids Member ids.
	 * @return array<int, array<string, string>> Id => meta key => value.
	 * @throws \RuntimeException When the read fails or is not proved.
	 */
	private function stock_meta( array $ids ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$first    = array_key_first( $this->align_held );
		$sentinel = null !== $first && Lock::extend( StockSetMarker::align_lock( $first ), InventorySync::LOCK_TTL_OUTER ) ? $this->sentinel() : null;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One copy group's stock rows under its locks, past the cache on the writer; the IN list holds only %d placeholders.
		$sql   = null === $sentinel ? null : $wpdb->prepare(
			"SELECT /* pfl-writer */ o.option_value AS pfl_sentinel, m.post_id, m.meta_key, m.meta_value FROM %i o LEFT JOIN %i m ON m.post_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ") AND m.meta_key IN ('_stock', '_manage_stock') WHERE o.option_name = %s ORDER BY m.meta_id",
			array_merge( [ $wpdb->options, $wpdb->postmeta ], $ids, [ $sentinel[0] ] )
		);
		$found = is_string( $sql ) ? self::writer()->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( null === $sentinel || ! is_array( $found ) || [] === $found || '' !== self::db_error() ) {
			throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
		}

		$out = [];

		foreach ( $found as $row ) {
			if ( ( $row['pfl_sentinel'] ?? null ) !== $sentinel[1] ) {
				throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
			}

			if ( is_numeric( $row['post_id'] ?? null ) && is_string( $row['meta_key'] ?? null ) && is_string( $row['meta_value'] ?? null ) ) {
				$out[ (int) $row['post_id'] ][ $row['meta_key'] ] ??= $row['meta_value'];
			}
		}

		return $out;
	}

	/**
	 * Align's first "aligning" lock row as stored now: [ option name, value ], or null.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function sentinel(): ?array {
		$first = array_key_first( $this->align_held );

		return null === $first ? null : Lock::row( StockSetMarker::align_lock( $first ) );
	}

	/**
	 * Whether a fence's liveness lock is free on the writer ('1'); anything else (in use, a replica, an error) is not.
	 *
	 * @param string $lock Lock name.
	 * @return string|null
	 */
	private function free( string $lock ): ?string {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$sql = $wpdb->prepare( 'SELECT IF(@@global.read_only OR @@global.innodb_read_only, NULL, IS_FREE_LOCK(%s))', $lock );
		$got = is_string( $sql ) ? self::writer()->get_var( $sql ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A lock function, prepared above.

		return is_scalar( $got ) ? (string) $got : null;
	}

	/**
	 * Delete a stale fence row if it still holds the value read.
	 *
	 * @param string $name  Option name.
	 * @param string $value Value read.
	 * @return bool False when the delete failed.
	 */
	private function drop_fence( string $name, string $value ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $name, $value );
		$done = is_string( $sql ) ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		return false !== $done && '' === self::db_error();
	}

	/**
	 * The global wpdb, its reads sent to the primary (HyperDB/LudicrousDB); a proxy routes `pfl-writer` statements.
	 *
	 * @return \wpdb
	 */
	private static function writer(): \wpdb {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		return $wpdb;
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

			// Our delta is never mirrored: drop it now, so a listener's nested CRUD save (no statement) cannot take it.
			$this->sync->take_stock_delta( $id );

			return $read;
		};

		add_filter( 'woocommerce_update_product_stock_query', $guard, PHP_INT_MAX, 3 );
		StockChangeLock::own_write( $id, $product );

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
			StockChangeLock::drop_own( $product );
			$this->sync->resume_stock_mirror( $id );
			$this->sync->end_writes();
		}

		if ( ! is_numeric( $new ) ) {
			throw new StockWriteFailed( '', true, false, $ran );
		}

		if ( '' !== self::db_error() ) {
			throw new StockWriteFailed( self::db_error(), false, false, $ran );
		}

		// Our statement's result: the returned quantity also holds a set_stock listener's later change of this object.
		return $ran ?? (float) $new;
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
		 * @param float $new        Stock the write's own statement set (a listener may change it later).
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
