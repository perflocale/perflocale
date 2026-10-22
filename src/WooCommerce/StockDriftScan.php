<?php
/**
 * WooCommerce inventory sync: the read-only scan for language copies whose stock differs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the copy groups whose stored stock differs (`wp perflocale
 * stock-drift`). A copy group is what Inventory Sync keeps as one stock:
 *
 * - the members of one product translation group that manage their own
 *   stock and are not opted out of the sync (simple products, and variable
 *   products whose stock is kept on the parent);
 * - for each variation of the group's anchor (the member in the default
 *   language, else the lowest id) that manages its own stock: in each other
 *   member parent the first child whose attribute signature is the anchor
 *   variation's, matched as the stock mirror matches it
 *   (InventorySync::variation_signature()), that manages its own stock and is
 *   not opted out.
 *
 * Members without a numeric `_stock` are left out. A group with more than one
 * stock value is listed, one row per member. Reads only: product posts, their
 * meta and terms, and PerfLocale's link table; never orders.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockDriftScan {

	/**
	 * Most translation groups one page reads.
	 */
	public const MAX_BATCH = 500;

	/**
	 * Most products and variations whose posts and meta one read primes and
	 * the runtime cache holds at once.
	 */
	public const CHILD_CHUNK = 1000;

	/**
	 * Every language, active or not, id => slug, read from PerfLocale's
	 * languages table (no cache read or write).
	 *
	 * @return array<int, string>
	 * @throws \RuntimeException When the read fails.
	 */
	public static function languages(): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of the small languages table; the repository's cached list would be written to a transient.
		$sql   = $wpdb->prepare( 'SELECT id, slug FROM %i', Schema::table( 'languages' ) );
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement above.
		$out   = [];

		self::check_read( $found );

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$slug = $row['slug'] ?? '';

			if ( is_string( $slug ) && '' !== $slug ) {
				$out[ self::id( $row['id'] ?? 0 ) ] = $slug;
			}
		}

		return $out;
	}

	/**
	 * One page of the scan: the product translation groups after $after, at
	 * most $batch of them, by group id.
	 *
	 * Reads the posts and meta of at most CHILD_CHUNK products and
	 * variations at a time, also within one product, and drops the runtime
	 * cache between those reads.
	 *
	 * @param InventorySync      $sync         The stock mirror (matching and opt-outs).
	 * @param array<int, string> $languages    Language id => slug, every language.
	 * @param int                $default_lang Id of the default language (0: none).
	 * @param int                $after        Last group id of the previous page (0 to start).
	 * @param int                $batch        Groups per page (1 - MAX_BATCH).
	 * @return array{rows: list<array{group: int, product_id: int, parent_id: int, language: string, stock: string}>, groups: int, next: int|null}
	 *         The rows of the drifting groups, the drifting groups' count, and
	 *         the cursor of the next page (null after the last).
	 * @throws \InvalidArgumentException When $batch is out of range.
	 * @throws \RuntimeException When a read fails (the page is not reported, nothing past it is read).
	 */
	public static function page( InventorySync $sync, array $languages, int $default_lang, int $after, int $batch ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( $batch < 1 || $batch > self::MAX_BATCH ) {
			throw new \InvalidArgumentException( esc_html( sprintf( '--batch-size must be between 1 and %d.', self::MAX_BATCH ) ) );
		}

		$links = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A read-only scan in keyset pages; no WP API reads the link table by group range.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT l.group_id FROM %i l INNER JOIN %i p ON p.ID = l.object_id WHERE l.type = 'post' AND p.post_type = 'product' AND l.group_id > %d ORDER BY l.group_id LIMIT %d",
			$links,
			$wpdb->posts,
			$after,
			$batch
		);
		$col = is_string( $sql ) ? $wpdb->get_col( $sql ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement above.

		self::check_read( $col );

		$ids = array_values( wp_parse_id_list( is_array( $col ) ? $col : [] ) );

		if ( [] === $ids ) {
			return [
				'rows'   => [],
				'groups' => 0,
				'next'   => null,
			];
		}

		$first = $ids[0];
		$last  = $ids[ count( $ids ) - 1 ];
		$sql   = $wpdb->prepare(
			"SELECT l.group_id, l.object_id, l.language_id FROM %i l INNER JOIN %i p ON p.ID = l.object_id WHERE l.type = 'post' AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft') AND l.group_id BETWEEN %d AND %d ORDER BY l.group_id, l.object_id",
			$links,
			$wpdb->posts,
			$first,
			$last
		);
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement above.
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::check_read( $found );

		$groups  = [];
		$members = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$product                                       = self::id( $row['object_id'] ?? 0 );
			$groups[ self::id( $row['group_id'] ?? 0 ) ][] = $product;
			$members[ $product ]                           = self::id( $row['language_id'] ?? 0 );
		}

		$children = self::children( array_keys( $members ) );
		$rows     = [];
		$drifting = 0;
		$run      = [];
		$run_ids  = [];

		// The groups are read in runs whose products and variations number at
		// most CHILD_CHUNK, each run's posts and meta primed at once; the
		// runtime cache is dropped between runs. A group with more
		// variations than that runs alone and reads them CHILD_CHUNK at a
		// time.
		foreach ( $groups as $products ) {
			if ( count( $products ) < 2 ) {
				continue;
			}

			$group_ids = $products;

			foreach ( $products as $product ) {
				array_push( $group_ids, ...( $children[ $product ] ?? [] ) );
			}

			if ( [] !== $run && count( $run_ids ) + count( $group_ids ) > self::CHILD_CHUNK ) {
				self::run_groups( $sync, $run, $run_ids, $children, $members, $languages, $default_lang, $rows, $drifting );
				\PerfLocale\Cache\CacheManager::flush_runtime();
				$run     = [];
				$run_ids = [];
			}

			$run[] = $products;
			array_push( $run_ids, ...$group_ids );
		}

		if ( [] !== $run ) {
			self::run_groups( $sync, $run, $run_ids, $children, $members, $languages, $default_lang, $rows, $drifting );
		}

		return [
			'rows'   => $rows,
			'groups' => $drifting,
			'next'   => count( $ids ) < $batch ? null : $last,
		];
	}

	/**
	 * Read a run of groups: prime their posts and meta (products and
	 * variations) when they fit one chunk, then list each group's drift.
	 *
	 * @param InventorySync                                                                     $sync         The stock mirror.
	 * @param list<list<int>>                                                                   $run          The run's groups, each its products in link order.
	 * @param list<int>                                                                         $ids          The run's products and variations.
	 * @param array<int, list<int>>                                                             $children     Product id => variation ids.
	 * @param array<int, int>                                                                   $members      Product id => language id.
	 * @param array<int, string>                                                                $languages    Language id => slug.
	 * @param int                                                                               $default_lang Id of the default language (0: none).
	 * @param list<array{group: int, product_id: int, parent_id: int, language: string, stock: string}> $rows         Rows so far.
	 * @param int                                                                               $drifting     Drifting groups so far.
	 * @return void
	 * @throws \RuntimeException When a read fails.
	 */
	private static function run_groups( InventorySync $sync, array $run, array $ids, array $children, array $members, array $languages, int $default_lang, array &$rows, int &$drifting ): void {
		$chunked = count( $ids ) > self::CHILD_CHUNK;

		if ( ! $chunked ) {
			self::prime( $ids );
		}

		foreach ( $run as $products ) {
			self::group_rows( $sync, $products, $children, $members, $languages, $default_lang, $chunked, $rows, $drifting );
		}
	}

	/**
	 * List one group's drift: its products, then each variation of its
	 * anchor with the matching variation of every other product.
	 *
	 * With $chunked, the group's variations are read CHILD_CHUNK at a time
	 * (primed with their parent, the runtime cache dropped after each
	 * chunk); else every post the group reads is primed already.
	 *
	 * @param InventorySync                                                                     $sync         The stock mirror.
	 * @param list<int>                                                                         $products     The group's products in link order.
	 * @param array<int, list<int>>                                                             $children     Product id => variation ids.
	 * @param array<int, int>                                                                   $members      Product id => language id.
	 * @param array<int, string>                                                                $languages    Language id => slug.
	 * @param int                                                                               $default_lang Id of the default language (0: none).
	 * @param bool                                                                              $chunked      Read the variations in chunks.
	 * @param list<array{group: int, product_id: int, parent_id: int, language: string, stock: string}> $rows         Rows so far.
	 * @param int                                                                               $drifting     Drifting groups so far.
	 * @return void
	 * @throws \RuntimeException When a read fails.
	 */
	private static function group_rows( InventorySync $sync, array $products, array $children, array $members, array $languages, int $default_lang, bool $chunked, array &$rows, int &$drifting ): void {
		if ( $chunked ) {
			self::prime( $products );
		}

		$anchor = $products[0];

		foreach ( $products as $product ) {
			if ( $default_lang > 0 && $members[ $product ] === $default_lang ) {
				$anchor = $product;
				break;
			}
		}

		// The products themselves.
		$set = [];

		foreach ( $products as $product ) {
			$stock = self::stock( $sync, $product );

			if ( null !== $stock ) {
				$set[ $product ] = [ 0, $stock ];
			}
		}

		if ( self::differs( $set ) ) {
			++$drifting;
			self::add_rows( $rows, $anchor, $set, $members, $languages );
		}

		// The anchor's variations that hold a stock and a signature, in order.
		$variations = [];

		foreach ( self::chunks( $children[ $anchor ] ?? [], $anchor, $chunked ) as $chunk ) {
			foreach ( $chunk as $variation ) {
				$stock = self::stock( $sync, $variation );

				if ( null === $stock ) {
					continue;
				}

				$sig = $sync->variation_signature( $variation, $anchor );

				if ( null !== $sig ) {
					$variations[] = [ $variation, $stock, $sig ];
				}
			}
		}

		if ( [] === $variations ) {
			return;
		}

		// Each other product's first variation per signature, with its stock.
		$copies = [];

		foreach ( $products as $product ) {
			if ( $product === $anchor || ! isset( $children[ $product ] ) || $sync->is_sync_opted_out( $product ) ) {
				continue;
			}

			$copies[ $product ] = [];

			foreach ( self::chunks( $children[ $product ], $product, $chunked ) as $chunk ) {
				foreach ( $chunk as $child ) {
					$sig = $sync->variation_signature( $child, $product );

					if ( null !== $sig && ! isset( $copies[ $product ][ $sig ] ) ) {
						$copies[ $product ][ $sig ] = [ $child, self::stock( $sync, $child ) ];
					}
				}
			}
		}

		foreach ( $variations as [ $variation, $stock, $sig ] ) {
			$set = [ $variation => [ $anchor, $stock ] ];

			foreach ( $copies as $product => $first ) {
				[ $copy, $copy_stock ] = $first[ $sig ] ?? [ 0, null ];

				if ( $copy > 0 && null !== $copy_stock ) {
					$set[ $copy ] = [ $product, $copy_stock ];
				}
			}

			if ( self::differs( $set ) ) {
				++$drifting;
				self::add_rows( $rows, $variation, $set, $members, $languages );
			}
		}
	}

	/**
	 * A parent's variations in the chunks they are read in: all at once, or
	 * CHILD_CHUNK at a time, each chunk primed with the parent before it is
	 * read and the runtime cache dropped after it.
	 *
	 * @param list<int> $variations Variation ids in WooCommerce's order.
	 * @param int       $parent_id  Their parent.
	 * @param bool      $chunked    Read in chunks.
	 * @return \Generator<int, list<int>>
	 */
	private static function chunks( array $variations, int $parent_id, bool $chunked ): \Generator {
		if ( ! $chunked ) {
			yield $variations;
			return;
		}

		foreach ( array_chunk( $variations, self::CHILD_CHUNK ) as $chunk ) {
			self::prime( array_merge( [ $parent_id ], $chunk ) );

			yield $chunk;

			\PerfLocale\Cache\CacheManager::flush_runtime();
		}
	}

	/**
	 * Prime the posts and meta of these products and variations (the ones
	 * not cached yet).
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return void
	 */
	private static function prime( array $ids ): void {
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, false, true );
		}
	}

	/**
	 * The published and private variations of these products, each list in
	 * WooCommerce's order (menu_order, then id). Ids only: their posts and
	 * meta are read when their group is.
	 *
	 * @param array<int, int> $parents Product ids (one page's members).
	 * @return array<int, list<int>> Product id => variation ids.
	 * @throws \RuntimeException When the read fails.
	 */
	private static function children( array $parents ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( [] === $parents ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One read of one page's variations; the IN list holds only %d placeholders, one per product of the page (at most MAX_BATCH groups' members).
		$sql   = $wpdb->prepare(
			"SELECT ID, post_parent FROM %i WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private') AND post_parent IN (" . implode( ', ', array_fill( 0, count( $parents ), '%d' ) ) . ') ORDER BY post_parent, menu_order, ID',
			array_merge( [ $wpdb->posts ], array_values( $parents ) )
		);
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		self::check_read( $found );

		$out = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$out[ self::id( $row['post_parent'] ?? 0 ) ][] = self::id( $row['ID'] ?? 0 );
		}

		return $out;
	}

	/**
	 * Stop on a read that failed. A failed read is an error, never an empty
	 * result: wpdb answers it with no rows and sets last_error (cleared at
	 * the start of every query).
	 *
	 * @param mixed $found What the read returned (null when it did not run).
	 * @return void
	 * @throws \RuntimeException When the read failed.
	 */
	private static function check_read( mixed $found ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( ! is_array( $found ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html( 'The scan stopped because a database read failed. Run the command again.' ) );
		}
	}

	/**
	 * A database id read as a number (0 when it is none).
	 *
	 * @param mixed $value Value read.
	 * @return int
	 */
	private static function id( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A product's or variation's stored stock when it is part of a copy group
	 * (it manages its own stock, has a numeric `_stock` and is not opted out
	 * of the sync), else null.
	 *
	 * @param InventorySync $sync The stock mirror (opt-outs).
	 * @param int           $id   Product or variation id.
	 * @return string|null
	 */
	private static function stock( InventorySync $sync, int $id ): ?string {
		$stock = get_post_meta( $id, '_stock', true );

		if ( 'yes' !== get_post_meta( $id, '_manage_stock', true ) || ! is_numeric( $stock ) || $sync->is_sync_opted_out( $id ) ) {
			return null;
		}

		// The stored value as a number: "5.0" and "5" both print as 5.
		return (string) (float) $stock;
	}

	/**
	 * Whether a group of two or more members holds more than one stock value.
	 *
	 * @param array<int, array{0: int, 1: string}> $set Member id => [ parent id, stock ].
	 * @return bool
	 */
	private static function differs( array $set ): bool {
		if ( count( $set ) < 2 ) {
			return false;
		}

		$values = [];

		foreach ( $set as [ , $stock ] ) {
			$values[ (string) (float) $stock ] = true;
		}

		return count( $values ) > 1;
	}

	/**
	 * Add one row per member of a drifting group.
	 *
	 * @param list<array{group: int, product_id: int, parent_id: int, language: string, stock: string}> $rows      Rows so far.
	 * @param int                                                                                       $group     The group's anchor id.
	 * @param array<int, array{0: int, 1: string}>                                                      $set       Member id => [ parent id, stock ].
	 * @param array<int, int>                                                                           $members   Product id => language id.
	 * @param array<int, string>                                                                        $languages Language id => slug.
	 * @return void
	 */
	private static function add_rows( array &$rows, int $group, array $set, array $members, array $languages ): void {
		foreach ( $set as $id => [ $parent, $stock ] ) {
			$rows[] = [
				'group'      => $group,
				'product_id' => $id,
				'parent_id'  => $parent,
				'language'   => $languages[ $members[ 0 === $parent ? $id : $parent ] ?? 0 ] ?? '',
				'stock'      => $stock,
			];
		}
	}
}
