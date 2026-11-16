<?php
/**
 * WooCommerce inventory sync: the opt-out flags a request that is not a GET keeps for its repeated stock checks.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each id's own opt-out meta flag and parent, per blog, for InventorySync::is_sync_opted_out() outside a GET; required
 * on first use. Kept InventorySync::COPY_EPOCH_REREAD seconds, at most 256 ids, then read again.
 */
final class OptOutMemo {

	/**
	 * Blog id => when started, the own flag per id and the parent per id (0: not a variation).
	 *
	 * @var array<int, array{at: float, own: array<int, bool>, parent: array<int, int>}>
	 */
	private array $blogs = [];

	/**
	 * Whether the id or its variation parent carries the opt-out meta flag.
	 *
	 * @param int      $id        Product or variation ID.
	 * @param int|null $parent_id Its parent when the caller read it (0: not a variation); null: kept or read here.
	 * @return bool
	 */
	public function flagged( int $id, ?int $parent_id ): bool {
		$blog = get_current_blog_id();

		if ( isset( $this->blogs[ $blog ] ) && microtime( true ) - $this->blogs[ $blog ]['at'] > InventorySync::COPY_EPOCH_REREAD ) {
			unset( $this->blogs[ $blog ] );
		}

		if ( $this->own( $blog, $id ) ) {
			return true;
		}

		$parent_id ??= $this->blogs[ $blog ]['parent'][ $id ] ?? ( get_post_type( $id ) === 'product_variation' ? (int) wp_get_post_parent_id( $id ) : 0 );

		if ( isset( $this->blogs[ $blog ] ) ) {
			$this->blogs[ $blog ]['parent'][ $id ] = $parent_id;
		}

		return $parent_id > 0 && $this->own( $blog, $parent_id );
	}

	/**
	 * One id's own meta flag, kept or read (a full memo starts again).
	 *
	 * @param int $blog Blog id.
	 * @param int $id   Post id.
	 * @return bool
	 */
	private function own( int $blog, int $id ): bool {
		if ( isset( $this->blogs[ $blog ]['own'][ $id ] ) ) {
			return $this->blogs[ $blog ]['own'][ $id ];
		}

		$flag = get_post_meta( $id, InventorySync::SYNC_OPTOUT_META, true ) === 'yes';

		if ( ! isset( $this->blogs[ $blog ] ) || count( $this->blogs[ $blog ]['own'] ) >= 256 ) {
			$this->blogs[ $blog ] = [
				'at'     => microtime( true ),
				'own'    => [],
				'parent' => [],
			];
		}

		$this->blogs[ $blog ]['own'][ $id ] = $flag;

		return $flag;
	}

	/**
	 * Each copy parent's matched variation, and the flags of $ids, the parents and those variations as stored now. An
	 * opted-out parent (kept flag) is not matched; one opted in as stored at any read is matched then, its stale flag
	 * dropped, and every flag read again: the flags returned are read after the last match.
	 *
	 * @param InventorySync         $sync    The sync.
	 * @param array<int, int>       $parents Copy parent ids.
	 * @param array<string, string> $attrs   Normalised source variation attributes.
	 * @param array<int, int>       $held    Matches this request holds, by parent ([] matches).
	 * @param array<mixed>          $ids     Further ids to read (the source and its parent).
	 * @return array{0: array<int, bool>|null, 1: array<int, int>} Flags (null: a read failed) and matches.
	 */
	public static function match( InventorySync $sync, array $parents, array $attrs, array $held, array $ids ): array {
		$matched = [];
		$skipped = [];

		foreach ( $parents as $parent ) {
			if ( [] !== $held ) {
				$matched[ $parent ] = $held[ $parent ];
			} elseif ( $sync->is_sync_opted_out( $parent, 0 ) ) {
				$matched[ $parent ] = 0;
				$skipped[]          = $parent;
			} else {
				$matched[ $parent ] = $sync->match_sibling_variation( $parent, $attrs );
			}
		}

		$stored = self::read( array_merge( $ids, array_keys( $matched ), $matched ) );

		// Each read decides: a skipped parent opted in as stored is matched once, then every flag is read again.
		while ( null !== $stored ) {
			$late = array_filter( $skipped, static fn( int $parent ): bool => ! $sync->is_sync_opted_out( $parent, 0, $stored ) );

			if ( [] === $late ) {
				break;
			}

			$skipped = array_diff( $skipped, $late );

			foreach ( $late as $parent ) {
				wp_cache_delete( $parent, 'post_meta' );
				$matched[ $parent ] = $sync->match_sibling_variation( $parent, $attrs );
			}

			$sync->forget_opt_outs();
			$stored = self::read( array_merge( $ids, array_keys( $matched ), $matched ) );
		}

		return [ $stored, $matched ];
	}

	/**
	 * These ids' own flags as the database holds them now, past the object cache: a write's own checks, read right
	 * before the writes (no lock orders an opt-out write against them). Null (refuse) when the read failed; the ids
	 * are then flagged for Check stock.
	 *
	 * @param array<mixed> $ids Post ids.
	 * @return array<int, bool>|null
	 */
	public static function read( array $ids ): ?array {
		$ids    = array_values( array_unique( array_filter( array_map( static fn( mixed $id ): int => is_numeric( $id ) ? (int) $id : 0, $ids ) ) ) );
		$stored = self::stored( $ids );

		if ( null === $stored ) {
			require_once __DIR__ . '/StockDriftFlag.php';
			StockDriftFlag::note( $ids );
		}

		return $stored;
	}

	/**
	 * The flags read() reads; null when the read failed.
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return array<int, bool>|null
	 */
	private static function stored( array $ids ): ?array {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( [] === $ids ) {
			return [];
		}

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// HyperDB/LudicrousDB: the writer, not a replica that may lag behind the opt-out write.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Past the cache on purpose; one %d per id.
		$on = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s AND post_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $wpdb->postmeta, InventorySync::SYNC_OPTOUT_META, 'yes', ...$ids ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		$on = array_map( static fn( mixed $id ): int => is_numeric( $id ) ? (int) $id : 0, $on );

		return array_combine( $ids, array_map( static fn( int $id ): bool => in_array( $id, $on, true ), $ids ) );
	}

	/**
	 * Drop this blog's flags.
	 *
	 * @return void
	 */
	public function forget(): void {
		unset( $this->blogs[ get_current_blog_id() ] );
	}
}
