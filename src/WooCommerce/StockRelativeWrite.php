<?php
/**
 * WooCommerce inventory sync: a source's relative stock change written to its language copies.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The relative mirror's statements, each locking one product's rows in meta_id order, retried on a deadlock or lock
 * wait timeout. Loaded by literal require_once on a mirrored stock change only: see docs-ai/11-code-rationale.md,
 * "StockRelativeWrite".
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockRelativeWrite {

	/**
	 * Tries per statement (the first and the retries).
	 */
	public const TRIES = 4;

	/**
	 * Seconds after the first try past which no retry starts: a lock wait timeout already waited the server's limit.
	 */
	private const BUDGET = 2.0;

	/**
	 * Index hint of every postmeta reference on MySQL and MariaDB: a product's rows in meta_id order, as WooCommerce's
	 * own stock statement scans them. Without it a small shop's optimizer takes the meta_key index and locks every
	 * product's `_stock` row.
	 */
	private const HINT = ' FORCE INDEX (post_id)';

	/**
	 * MySQL and MariaDB error number of a missing index in an index hint (ER_KEY_DOES_NOT_EXITS).
	 */
	private const NO_KEY = 1176;

	/**
	 * Deadlocks seen by run() in this request that may have rolled back an open transaction.
	 *
	 * @var int
	 */
	private static int $deadlocks = 0;

	/**
	 * Whether this request found the postmeta table without the post_id index: no hint from then on.
	 *
	 * @var bool
	 */
	private static bool $no_hint = false;

	/**
	 * Lock row (meta_id) of a group's hold sums, per blog and group, for this request.
	 *
	 * @var array<string, int>
	 */
	private static array $hold_rows = [];

	/**
	 * Error code of run()'s last write that still failed (DbErrorCode).
	 *
	 * @var string
	 */
	private static string $failure = '';

	/**
	 * A change mirrored without a delta ('set', or none captured): when WooCommerce's own statement failed or ran across a
	 * reconnect, or a nested change shared its relative delta ($lost), flag the group (the copies got the stock
	 * WooCommerce reported, which may be wrong).
	 *
	 * @param int       $source Product or variation whose stock WooCommerce changed.
	 * @param list<int> $copies Copy ids.
	 * @param bool      $lost   Its relative delta cannot be trusted (InventorySync::take_stock_delta()).
	 * @return void
	 */
	public static function flag_statement( int $source, array $copies, bool $lost = false ): void {
		// A copy created after the gate's read: the class is not loaded yet, this change still has its statement checked.
		require_once __DIR__ . '/StockChangeLock.php';

		$rerun = StockChangeLock::rerun( $source );
		$core  = StockChangeLock::failed( $source );

		if ( $lost ) {
			self::failed( sprintf( 'stock change of #%d: a nested change of it shared its relative change, copies #%s got the stock WooCommerce reported', $source, implode( ', #', $copies ) ), 'lost_delta' );
		}

		if ( $rerun ) {
			self::failed( sprintf( 'stock change of #%d: WooCommerce\'s own statement ran across a reconnect and may have changed it twice', $source ), 'reconnect' );
		}

		if ( $core ) {
			self::failed( sprintf( 'stock change of #%d: WooCommerce\'s own statement failed, ran unseen or overlapped other stock work, its copies got the change', $source ), 'core_statement' );
		}

		if ( $rerun || $core || $lost ) {
			require_once __DIR__ . '/StockDriftFlag.php';
			StockDriftFlag::note( array_values( array_unique( array_merge( $copies, [ $source ] ) ) ) );
		}
	}

	/**
	 * Apply a source's delta to each copy; a failed write is logged as drift, never replaced by an absolute copy.
	 *
	 * @param int       $source  Product or variation whose stock WooCommerce changed.
	 * @param list<int> $copies  Copy ids to write.
	 * @param float     $delta   Signed delta.
	 * @param list<int> $synced  Copies written (appended).
	 * @param list<int> $flipped Copies whose stock status changed (appended).
	 * @return list<int> Copies without a numeric `_stock`, for the absolute mirror.
	 */
	public static function mirror( int $source, array $copies, float $delta, array &$synced, array &$flipped ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// Copies exist: the lock class may be unloaded only for one added after the gate's read.
		require_once __DIR__ . '/StockChangeLock.php';

		$absolute = [];
		$gave_up  = [];
		$written  = [];
		$maybe    = [];
		$undone   = [];
		$seen     = self::$deadlocks;
		$doubt    = StockChangeLock::doubt( $source );
		$guard    = StockChangeLock::guard( $source );
		$bare     = '' === $guard;
		$handle   = StockChangeLock::handle();
		$queue    = $copies;
		$unknown  = false;
		$regains  = 0;
		$again    = [];

		// An alignment ran while a reconnect hid this change: see StockChangeLock::doubt().
		if ( '' !== $doubt ) {
			[ $queue, $unknown ] = self::pending( $source, $copies, $delta, $doubt, 'flag' === $doubt ? null : StockChangeLock::aligned( $source ), [], [], [] );
		}

		while ( [] !== $queue ) {
			$id = (int) array_shift( $queue );

			// From the copy's meta cache, primed by the opt-out check: no query.
			$meta = [
				'_stock'        => self::meta( $id, '_stock' ),
				'_manage_stock' => self::meta( $id, '_manage_stock' ),
				'_backorders'   => self::meta( $id, '_backorders' ),
				'_stock_status' => self::meta( $id, '_stock_status' ),
			];

			if ( ! is_numeric( $meta['_stock'] ) ) {
				$absolute[] = $id;
				continue;
			}

			// No `AND meta_value = <expected>`: the delta is right whatever the copy holds; that predicate lost sales.
			// The guard: only while this session still holds the change's lock (a rerun after a reconnect matches nothing).
			// No lock, a restock: only on this session, so wpdb's rerun after a lost reply cannot write it twice (high).
			$session = '' === $guard && $delta > 0 ? self::session() : 0;
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The hint is a constant or ''; the guard a literal with its own placeholder.
			$sql = $wpdb->prepare(
				'UPDATE %i' . self::hint() . " SET meta_value = meta_value + (%f) WHERE post_id = %d AND meta_key = '_stock'" . ( '' !== $guard ? ' AND IS_USED_LOCK(%s) = CONNECTION_ID()' : ( $session > 0 ? ' AND CONNECTION_ID() = %d' : '' ) ),
				...array_merge( [ $wpdb->postmeta, $delta, $id ], '' !== $guard ? [ $guard ] : ( $session > 0 ? [ $session ] : [] ) )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			$was      = StockChangeLock::handle();
			$rolled   = self::$deadlocks;
			$affected = is_string( $sql ) ? self::run( $sql ) : false;
			$undone   = self::undone( $seen, $undone, $written );

			// A sale the server rolled back must not leave the copy above the truth: once more, at the end of the queue.
			// Not inside an open transaction: its rollback may have undone the source's statement too.
			if ( false === $affected && $delta < 0 && ! isset( $again[ $id ] ) && ( 'lock_timeout' === self::$failure ? ! self::in_transaction() : ( 'deadlock' === self::$failure && self::$deadlocks === $rolled ) ) ) {
				$again[ $id ] = true;
				$queue[]      = $id;
				continue;
			}

			if ( false === $affected ) {
				self::failed( sprintf( 'stock change of #%d not mirrored to copy #%d: the copy drifts until Check stock aligns it', $source, $id ) );
				$gave_up[] = $id;
				continue;
			}

			// That restock matched nothing: rerun on a new session (committed or not) or the row is gone; never written again.
			if ( 0 === $affected && $session > 0 ) {
				$unknown = true;
				continue;
			}

			// Nothing matched under a guard: the row is gone, or a reconnect freed the lock. A new session ran it again: the
			// old one may have committed it before its reply was lost (wpdb reruns on "server has gone away").
			$lost    = 0 === $affected && '' !== $guard && StockChangeLock::handle() !== $was ? [ $id ] : [];
			$verdict = 0 === $affected && '' !== $guard ? ( ++$regains <= 3 ? StockChangeLock::regain( $source, array_merge( [ $source ], $written ), array_merge( $maybe, $lost ) ) : 'flag' ) : 'held';

			if ( 'held' !== $verdict ) {
				$maybe  = array_values( array_unique( array_merge( $maybe, $lost ) ) );
				$doubt  = 'flag' === $doubt ? 'flag' : $verdict;
				$guard  = $regains < 3 ? StockChangeLock::guard( $source ) : '';
				$copies = self::eligible( $source, $copies );

				// The source opted out or the read failed: no recovery write, the source's own included (flagged).
				if ( null === $copies ) {
					$copies   = [];
					$queue    = [];
					$absolute = [];
					continue;
				}

				[ $queue, $unsure ] = self::pending( $source, $copies, $delta, $doubt, 'flag' === $doubt ? null : StockChangeLock::aligned( $source ), $written, $maybe, array_merge( $absolute, $gave_up ) );
				$unknown            = $unknown || $unsure;
				continue;
			}

			// No row left to change (deleted since the cache read): the absolute mirror decides.
			if ( $affected < 1 ) {
				$absolute[] = $id;
				continue;
			}

			$written = in_array( $id, $written, true ) ? $written : array_merge( $written, [ $id ] );

			wp_cache_delete( $id, 'post_meta' );

			if ( self::status( $id, $meta ) ) {
				$flipped[] = $id;
			}

			self::lookup( $id );

			$undone = self::undone( $seen, $undone, $written );

			if ( $id !== $source && ! in_array( $id, $synced, true ) ) {
				$synced[] = $id;
			}
		}

		if ( 'skip' === $doubt ) {
			self::failed( sprintf( 'stock change of #%d not mirrored again: an alignment during a reconnect already counted it', $source ), 'reconnect' );
		}

		if ( 'all' === $doubt && in_array( $source, $written, true ) ) {
			self::failed( sprintf( 'stock change of #%d also written to itself: an alignment during a reconnect set it to the reference', $source ), 'reconnect' );
		}

		if ( [] !== $undone ) {
			self::failed( sprintf( 'stock change of #%d: a deadlock inside an open transaction may have undone copies #%s', $source, implode( ', #', $undone ) ), 'deadlock' );
		}

		// A member whose state a reconnect left unknown, or a reconnect during writes no guard covered: the group may differ.
		$unsure = $unknown || ( $bare && StockChangeLock::handle() !== $handle );
		$rerun  = StockChangeLock::rerun( $source );
		$core   = StockChangeLock::failed( $source );

		if ( $unsure ) {
			self::failed( sprintf( 'stock change of #%d: after a reconnect copies #%s may differ until Check stock aligns them', $source, implode( ', #', $copies ) ), 'reconnect' );
		}

		if ( $rerun ) {
			self::failed( sprintf( 'stock change of #%d: WooCommerce\'s own statement ran across a reconnect and may have changed it twice', $source ), 'reconnect' );
		}

		if ( $core ) {
			self::failed( sprintf( 'stock change of #%d: WooCommerce\'s own statement failed, ran unseen or overlapped other stock work, its copies got the change', $source ), 'core_statement' );
		}

		$unsure = $unsure || $rerun || $core;

		// Site Health asks for Check stock; nothing is read or written while every copy write landed.
		if ( [] !== $gave_up || [] !== $undone || $unsure ) {
			require_once __DIR__ . '/StockDriftFlag.php';
			StockDriftFlag::note( array_values( array_unique( array_merge( $gave_up, $undone, $unsure ? $copies : [], [] !== $undone || $unsure ? [ $source ] : [] ) ) ) );
		}

		// The absolute pass writes these next: theirs and the source's flags as stored now.
		return [] === $absolute ? [] : self::eligible( $source, $absolute ) ?? [];
	}

	/**
	 * The copies the opt-out decision lets in, on the flags as stored now (InventorySync::is_sync_opted_out() semantics:
	 * the skip filter on each id, a copy's parent asked on its own): after a slot was reacquired (an opt-out may have
	 * landed while it was lost) and before the absolute pass. Null when the source is out or the read failed: no write
	 * at all, the source and the copies flagged for Check stock.
	 *
	 * @param int             $source Product or variation whose change is mirrored.
	 * @param array<int, int> $ids    Copy ids.
	 * @return list<int>|null
	 */
	private static function eligible( int $source, array $ids ): ?array {
		$parents = [];

		foreach ( array_merge( [ $source ], $ids ) as $id ) {
			$parents[ $id ] = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : 0;
		}

		require_once __DIR__ . '/OptOutMemo.php';
		$stored = OptOutMemo::read( array_merge( array_keys( $parents ), array_values( $parents ) ) );
		// The skip filter as InventorySync::is_sync_opted_out() fires it (documented there).
		$skip = static fn( int $id, int $parent_id ): bool => (bool) apply_filters( 'perflocale/woocommerce/skip_inventory_sync', ! empty( $stored[ $id ] ) || ! empty( $stored[ $parent_id ] ), $id );
		$out  = static fn( int $id ): bool => ( $id !== $source && $parents[ $id ] > 0 && $skip( $parents[ $id ], 0 ) ) || $skip( $id, $parents[ $id ] );

		if ( null === $stored || $out( $source ) ) {
			require_once __DIR__ . '/StockDriftFlag.php';
			StockDriftFlag::note( array_keys( $parents ) );

			return null;
		}

		return array_values( array_filter( $ids, static fn( int $id ): bool => ! $out( $id ) ) );
	}

	/**
	 * After a reconnect verdict: the members still to write, and whether one is unknown. The aligned members follow the
	 * verdict, the others hold the change once written; an unknown one is written for a sale, never for a restock, so
	 * no member ends above the truth (at most one change below it, flagged).
	 *
	 * @param int            $source  Source id.
	 * @param list<int>      $copies  Copy ids.
	 * @param float          $delta   Signed delta.
	 * @param string         $doubt   StockChangeLock::doubt().
	 * @param list<int>|null $aligned Members the verdict applies to (null: every member).
	 * @param list<int>      $written Members this mirror wrote.
	 * @param list<int>      $maybe   Members whose write a reconnect hid.
	 * @param list<int>      $done    Members handed to the absolute mirror or given up.
	 * @return array{0: list<int>, 1: bool}
	 */
	private static function pending( int $source, array $copies, float $delta, string $doubt, ?array $aligned, array $written, array $maybe, array $done ): array {
		$queue   = [];
		$unknown = false;

		foreach ( array_merge( $copies, [ $source ] ) as $id ) {
			$set   = '' !== $doubt && ( null === $aligned || in_array( $id, $aligned, true ) );
			$own   = $id === $source || in_array( $id, $written, true ) ? true : ( in_array( $id, $maybe, true ) ? null : false );
			$holds = $set ? match ( $doubt ) {
				'skip'  => true,
				'all'   => false,
				default => null,
			} : $own;

			$unknown = $unknown || null === $holds;

			if ( ( false === $holds || ( null === $holds && $delta < 0 ) ) && ! in_array( $id, $done, true ) ) {
				$queue[] = $id;
			}
		}

		return [ $queue, $unknown ];
	}

	/**
	 * InventorySync::refresh_derived_stock_status(): the status derived inside one statement from the committed `_stock`.
	 *
	 * @param int                        $product_id Product or variation id.
	 * @param array<string, string>|null $meta       `_manage_stock`, `_backorders` and `_stock_status` read before the
	 *                                               stock write (null: read now).
	 * @return bool Whether the stored status was written.
	 */
	public static function status( int $product_id, ?array $meta = null ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$meta ??= [
			'_manage_stock' => self::meta( $product_id, '_manage_stock' ),
			'_backorders'   => self::meta( $product_id, '_backorders' ),
			'_stock_status' => self::meta( $product_id, '_stock_status' ),
		];

		// Mirrors WC_Product::validate_props(): a product without its own stock keeps the status its owner chose.
		if ( 'yes' !== ( $meta['_manage_stock'] ?? '' ) ) {
			return false;
		}

		$threshold  = get_option( 'woocommerce_notify_no_stock_amount', 0 );
		$threshold  = is_numeric( $threshold ) ? absint( $threshold ) : 0;
		$backorders = $meta['_backorders'] ?? '';
		$below      = ( '' !== $backorders && 'no' !== $backorders ) ? 'onbackorder' : 'outofstock';

		// No `_stock_status` row: an UPDATE cannot create one.
		if ( '' === ( $meta['_stock_status'] ?? '' ) ) {
			return false !== update_post_meta( $product_id, '_stock_status', (float) self::meta( $product_id, '_stock' ) > $threshold ? 'instock' : $below );
		}

		// MySQL, MariaDB: `_stock` first as a no-op target, so the scan locks every row of the product X in meta_id
		// order (a first, read-only table is read without a lock). SQLite has no row locks and refuses two targets.
		// The WHERE matches only a status that changes: MySQL counts changed rows, SQLite matched rows.
		$sql = self::sqlite()
			? "UPDATE %i st JOIN %i q ON q.post_id = st.post_id AND q.meta_key = '_stock'
			   SET st.meta_value = CASE WHEN CAST( q.meta_value AS DECIMAL(20,6) ) > %d THEN 'instock' ELSE %s END
			   WHERE st.post_id = %d AND st.meta_key = '_stock_status'"
			: 'UPDATE %i q' . self::hint() . ' JOIN %i st' . self::hint() . " ON st.post_id = q.post_id AND st.meta_key = '_stock_status'
			   SET q.meta_value = q.meta_value, st.meta_value = CASE WHEN CAST( q.meta_value AS DECIMAL(20,6) ) > %d THEN 'instock' ELSE %s END
			   WHERE q.post_id = %d AND q.meta_key = '_stock'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Deliberately atomic (a read-modify-write can publish a stale status); $sql is one of two literals.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql is one of the two literals above; the sniff sees only the appended text.
		$sql = $wpdb->prepare(
			$sql . " AND ( st.meta_value IS NULL OR st.meta_value <> CASE WHEN CAST( q.meta_value AS DECIMAL(20,6) ) > %d THEN 'instock' ELSE %s END )",
			$wpdb->postmeta,
			$wpdb->postmeta,
			$threshold,
			$below,
			$product_id,
			$threshold,
			$below
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = is_string( $sql ) ? self::run( $sql ) : false;

		if ( false === $affected && self::sqlite() ) {
			return self::wc_status( $product_id, $threshold, $below );
		}

		if ( false === $affected ) {
			self::failed( sprintf( 'stock status of #%d not refreshed', $product_id ) );
			self::refresh_failed( $product_id );

			return false;
		}

		if ( $affected > 0 ) {
			wp_cache_delete( $product_id, 'post_meta' );

			return true;
		}

		return false;
	}

	/**
	 * InventorySync::refresh_stock_lookup_columns(): the lookup row's stock columns copied inside one statement.
	 *
	 * @param int $product_id Product or variation id.
	 * @return void
	 */
	public static function lookup( int $product_id ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		try {
			// The lookup row first (WooCommerce writes it alone), then the product's rows in meta_id order (see status()).
			// An inner JOIN on `_stock`: a product without one keeps the row WooCommerce wrote.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- WooCommerce's derived lookup row, no WC API rebuilds it without rewriting stock; the hint is a constant or ''.
			$sql = $wpdb->prepare(
				'UPDATE %i l JOIN %i q' . self::hint() . " ON q.post_id = l.product_id AND q.meta_key = '_stock'
				 LEFT JOIN %i s" . self::hint() . " ON s.post_id = l.product_id AND s.meta_key = '_stock_status'
				 SET l.stock_quantity = CAST( q.meta_value AS DECIMAL(20,6) ),
				     l.stock_status   = COALESCE( s.meta_value, l.stock_status )
				 WHERE l.product_id = %d",
				$wpdb->prefix . 'wc_product_meta_lookup',
				$wpdb->postmeta,
				$wpdb->postmeta,
				$product_id
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			if ( is_string( $sql ) && false === self::run( $sql ) && ! ( self::sqlite() && self::wc_lookup( $product_id ) ) ) {
				self::failed( sprintf( 'lookup row of #%d not refreshed', $product_id ) );
				self::refresh_failed( $product_id );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Run one write; retry a deadlock or lock wait timeout (the server rolled the statement back) after a short
	 * jittered pause, within TRIES and BUDGET.
	 *
	 * @param string $sql Prepared statement.
	 * @return int|false Rows changed, or false when it still failed.
	 */
	public static function run( string $sql ) {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$start         = microtime( true );
		self::$failure = '';

		for ( $try = 1; ; ++$try ) {
			// A hinted statement's error is held back: a table without the index must not print into a response.
			$hinted = str_contains( $sql, self::HINT );
			$quiet  = $hinted ? $wpdb->suppress_errors( true ) : false;

			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared by the caller.
				$affected = $wpdb->query( $sql );
				$number   = self::error_number();
			} finally {
				if ( $hinted ) {
					$wpdb->suppress_errors( $quiet );
				}
			}

			// Any other error of a hinted statement is reported as wpdb reports it.
			if ( $hinted && false === $affected && self::NO_KEY !== $number && ! $quiet ) {
				$wpdb->print_error( (string) $wpdb->last_error );
			}

			if ( is_int( $affected ) ) {
				return $affected;
			}

			$error = (string) $wpdb->last_error;

			// A postmeta table without WordPress's post_id index: this statement and the rest of the request without the hint.
			if ( $hinted && self::NO_KEY === $number ) {
				self::$no_hint = true;
				$sql           = str_replace( self::HINT, '', $sql );
				--$try;
				continue;
			}

			$code          = \PerfLocale\Util\DbErrorCode::of( $error );
			self::$failure = $code;

			// An autocommit deadlock undid this statement only; earlier writes stay committed.
			if ( 'deadlock' === $code && self::in_transaction() ) {
				++self::$deadlocks;
			}

			if ( $try >= self::TRIES || microtime( true ) - $start > self::BUDGET || ! in_array( $code, [ 'deadlock', 'lock_timeout' ], true ) ) {
				return false;
			}

			usleep( random_int( 2000, 8000 ) << $try );
		}
	}

	/**
	 * A group's widened hold sum, taking the group's lock row before the sum's scan.
	 *
	 * Two copies' reserves each share-lock the group's holds, then insert: without one exclusive row first they
	 * deadlock or, without gap locks, both hold one shared unit. The row is the group's lowest `_stock` meta_id (the
	 * same for every copy), locked by meta_id: one index record (a post_id range would cross WooCommerce's MariaDB plan).
	 *
	 * @param string          $query      WooCommerce's hold-sum query, widened to the group.
	 * @param array<int, int> $group      The group's ids.
	 * @param int             $product_id Stock id the sum is for (named in a refusal).
	 * @return string The query with the lock row; as it is when the writer has no `_stock` row in the group.
	 * @throws \Exception WooCommerce's ReserveStockException, in a reserve whose lock row is unknown (refuse()).
	 */
	public static function hold_lock( string $query, array $group, int $product_id = 0 ): string {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( self::sqlite() || 1 !== preg_match_all( '/FROM (\S+) stock_table/', $query ) ) {
			return $query;
		}

		sort( $group );
		$key = get_current_blog_id() . ':' . implode( ',', $group );

		if ( ! isset( self::$hold_rows[ $key ] ) && [] !== $group ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The meta cache keeps no meta_id; the IN list holds only %d placeholders.
			$sql = $wpdb->prepare(
				'SELECT MIN(meta_id) FROM %i WHERE post_id IN (' . implode( ',', array_fill( 0, count( $group ), '%d' ) ) . ") AND meta_key = '_stock'",
				array_merge( [ $wpdb->postmeta ], $group )
			);

			// HyperDB/LudicrousDB: this read on the writer (a replica's missing row proves nothing), routing restored after.
			$flag  = property_exists( $wpdb, 'send_reads_to_primaries' ) ? 'send_reads_to_primaries' : ( property_exists( $wpdb, 'srtm' ) ? 'srtm' : '' );
			$saved = '' !== $flag ? $wpdb->__get( $flag ) : null;

			try {
				if ( '' !== $flag ) {
					$wpdb->__set( $flag, true );
				} elseif ( method_exists( $wpdb, 'send_reads_to_primaries' ) ) {
					$wpdb->send_reads_to_primaries();
				} elseif ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
					$wpdb->send_reads_to_masters();
				}

				// One retry, then refuse(); a failed read is never memoized (lock_row_answer()).
				for ( $try = 0; $try < 2 && is_string( $sql ) && ! isset( self::$hold_rows[ $key ] ); ++$try ) {
					$ran = $wpdb->query( $sql );
					$row = false !== $ran && '' === (string) $wpdb->last_error ? self::lock_row_answer( $wpdb->last_result ) : null;

					if ( null !== $row ) {
						self::$hold_rows[ $key ] = $row;
					}
				}
			} finally {
				if ( '' !== $flag ) {
					$wpdb->__set( $flag, $saved );
				}
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			if ( ! isset( self::$hold_rows[ $key ] ) ) {
				self::failed( sprintf( 'stock hold of products #%s: lock row read failed twice, hold refused', implode( ', #', $group ) ) );

				return self::refuse( $query, $product_id );
			}
		}

		// No `_stock` row in the group on the writer: WooCommerce's reserve reads its stock as 0 and grants nothing.
		if ( ( self::$hold_rows[ $key ] ?? 0 ) <= 0 ) {
			return $query;
		}

		$lock = '( SELECT COUNT(*) AS pfl_n FROM `' . str_replace( '`', '``', $wpdb->postmeta ) . '` WHERE meta_id = ' . self::$hold_rows[ $key ] . ' FOR UPDATE ) pfl_hold, ';

		return (string) preg_replace_callback( '/FROM (\S+) stock_table/', static fn( array $m ): string => 'FROM ' . $lock . $m[1] . ' stock_table', $query, 1 );
	}

	/**
	 * The lock-row read's answer from its raw result: exactly one row with one column, SQL NULL (no `_stock` row: 0)
	 * or a positive integer id PHP represents exactly. Anything else (no row, '', a missing column) is null: failed.
	 *
	 * @param mixed $rows wpdb::$last_result.
	 * @return int|null
	 */
	private static function lock_row_answer( mixed $rows ): ?int {
		if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_object( $rows[0] ?? null ) ) {
			return null;
		}

		$columns = get_object_vars( $rows[0] );

		if ( 1 !== count( $columns ) ) {
			return null;
		}

		$value = reset( $columns );

		if ( null === $value ) {
			return 0;
		}

		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		return is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $value ) && (string) (int) $value === $value ? (int) $value : null;
	}

	/**
	 * A group hold sum without its lock row. WooCommerce's reserve fails through its own ReserveStockException branch
	 * with a retryable message; its read-only sum keeps the group's holds; any other caller gets a sum that grants
	 * nothing (NULL fails the reserve's ">=" test).
	 *
	 * @param string $query      The widened hold-sum query.
	 * @param int    $product_id Stock id the sum is for.
	 * @return string
	 * @throws \Exception WooCommerce's ReserveStockException, in its reserve.
	 */
	private static function refuse( string $query, int $product_id ): string {
		$reserve = 'Automattic\\WooCommerce\\Checkout\\Helpers\\ReserveStock';

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Failure path only: which WooCommerce statement asked.
			if ( ltrim( $frame['class'] ?? '', '\\' ) !== $reserve ) {
				continue;
			}

			if ( 'get_reserved_stock' === $frame['function'] ) {
				return $query;
			}

			$exception = ( $frame['class'] ?? '' ) . 'Exception';

			if ( 'reserve_stock_for_product' === $frame['function'] && is_subclass_of( $exception, \Exception::class ) ) {
				// The post title is the name WooCommerce's own stock messages show (get_name()).
				$name = $product_id > 0 ? get_post_field( 'post_title', $product_id, 'raw' ) : '';
				$name = is_string( $name ) && '' !== $name ? $name : '#' . $product_id;

				/* translators: %s: product name. */
				$message = sprintf( __( 'The stock of %s could not be checked just now. Please try again.', 'perflocale' ), $name );

				// Escaped where WooCommerce prints it, as its own messages.
				$refusal = ( new \ReflectionClass( $exception ) )->newInstance( 'perflocale_stock_hold_busy', $message, 503 );

				throw $refusal;
			}
		}

		return (string) preg_replace( '/^.*?FROM (\S+) stock_table.*$/s', 'SELECT NULL FROM $1 stock_table WHERE 1 = 0', $query );
	}

	/**
	 * Copies written before a deadlock inside an open transaction this mirror met: it was rolled back with them.
	 *
	 * @param int       $seen    Deadlock count last looked at (updated).
	 * @param list<int> $undone  Copies already counted.
	 * @param list<int> $written Copies written so far.
	 * @return list<int>
	 */
	private static function undone( int &$seen, array $undone, array $written ): array {
		if ( self::$deadlocks === $seen ) {
			return $undone;
		}

		$seen = self::$deadlocks;

		return array_values( array_unique( array_merge( $undone, $written ) ) );
	}

	/**
	 * After a deadlock: whether the session had an open transaction (autocommit off, or more BEGINs than COMMITs and
	 * ROLLBACKs); the server's rollback ends it, so only these counters still tell. No answer counts as open.
	 *
	 * @return bool
	 */
	private static function in_transaction(): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// HyperDB/LudicrousDB: the session that deadlocked, not a replica.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session state on the deadlock path only.
		$auto = $wpdb->get_var( 'SELECT @@autocommit' );
		$rows = $wpdb->get_results( "SHOW SESSION STATUS WHERE Variable_name IN ('Com_begin', 'Com_commit', 'Com_rollback')", 'ARRAY_N' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$com = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_string( $row[0] ?? null ) && is_numeric( $row[1] ?? null ) ) {
				$com[ strtolower( $row[0] ) ] = (int) $row[1];
			}
		}

		if ( ! is_numeric( $auto ) || 3 !== count( $com ) ) {
			return true;
		}

		return 0 === (int) $auto || $com['com_begin'] > $com['com_commit'] + $com['com_rollback'];
	}

	/**
	 * This mysqli session's id on the server (CONNECTION_ID()); 0 without mysqli (SQLite: nothing reruns there).
	 *
	 * @return int
	 */
	private static function session(): int {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$dbh = $wpdb->__get( 'dbh' );

		try {
			return $dbh instanceof \mysqli ? (int) mysqli_thread_id( $dbh ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_thread_id -- wpdb keeps no session id; read from the client, no statement.
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * The index hint, '' on SQLite.
	 *
	 * @return literal-string
	 */
	private static function hint(): string {
		return self::sqlite() || self::$no_hint ? '' : self::HINT;
	}

	/**
	 * The error number of the last statement on a mysqli connection (0 otherwise).
	 *
	 * @return int
	 */
	private static function error_number(): int {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$dbh = $wpdb->__get( 'dbh' );

		return $dbh instanceof \mysqli ? mysqli_errno( $dbh ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- wpdb keeps only the message text, which follows the server's language.
	}

	/**
	 * Whether the site runs on the SQLite Database Integration (its 2.2.x legacy driver refuses multi-table UPDATEs).
	 *
	 * @return bool
	 */
	private static function sqlite(): bool {
		return defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' );
	}

	/**
	 * status() through WooCommerce (wc_update_product_stock_status()) when the driver refused the statement.
	 * As a sibling write, so the save it makes starts no sync.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param int    $threshold  Out-of-stock threshold.
	 * @param string $below      Status at or below it.
	 * @return bool Whether the stored status changed.
	 */
	private static function wc_status( int $product_id, int $threshold, string $below ): bool {
		$status = (float) self::meta( $product_id, '_stock' ) > $threshold ? 'instock' : $below;

		if ( $status === self::meta( $product_id, '_stock_status' ) || ! function_exists( 'wc_update_product_stock_status' ) ) {
			return false;
		}

		$sync = InventorySyncHooks::instance();
		$sync?->begin_writes();

		try {
			wc_update_product_stock_status( $product_id, $status );
		} finally {
			$sync?->end_writes();
		}

		wp_cache_delete( $product_id, 'post_meta' );

		return $status === self::meta( $product_id, '_stock_status' );
	}

	/**
	 * lookup() through WooCommerce's lookup table updater (10.8+) when the driver refused the statement.
	 *
	 * @param int $product_id Product or variation id.
	 * @return bool Whether WooCommerce refreshed the row.
	 */
	private static function wc_lookup( int $product_id ): bool {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$store   = is_object( $product ) && method_exists( $product, 'get_data_store' ) ? $product->get_data_store() : null;

		// WooCommerce's store wrapper forwards the method through __call: has_callable() is its own check.
		$call = [ $store, 'refresh_product_lookup_table' ];

		if ( ! is_object( $store ) || ! method_exists( $store, 'has_callable' ) || ! $store->has_callable( 'refresh_product_lookup_table' ) || ! is_callable( $call ) ) {
			return false;
		}

		$call( $product_id );

		return true;
	}

	/**
	 * A status or lookup refresh that still failed: Check stock refreshes it again and Site Health lists it.
	 *
	 * @param int $product_id Product or variation id.
	 * @return void
	 */
	private static function refresh_failed( int $product_id ): void {
		require_once __DIR__ . '/StockDriftFlag.php';
		StockDriftFlag::note( [], [ $product_id ] );
	}

	/**
	 * One meta value of a product as a string ('' when missing or not scalar).
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $key        Meta key.
	 * @return string
	 */
	private static function meta( int $product_id, string $key ): string {
		$value = get_post_meta( $product_id, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Named log line for a write that still failed after its tries.
	 *
	 * @param string $what What was not written.
	 * @param string $code Error code ('': the last statement's).
	 * @return void
	 */
	private static function failed( string $what, string $code = '' ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'PerfLocale InventorySync: %s (%s)', $what, '' !== $code ? $code : \PerfLocale\Util\DbErrorCode::of( (string) $wpdb->last_error ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only.
		}
	}
}
