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
 * Read-only scan for copy groups (what Inventory Sync keeps as one stock) whose stored stock differs.
 * Variations match as the stock mirror matches them; memory stays within CHILD_CHUNK posts: see
 * docs-ai/11-code-rationale.md, "StockDriftScan: copy groups and memory bound".
 *
 * @internal Internal to PerfLocale; not a public API.
 *
 * @phpstan-type StockMember array{product_id: int, parent_id: int, language: string, stock: float}
 * @phpstan-type StockLeftOut array{product_id: int, parent_id: int, language: string, reason: string}
 * @phpstan-type StockGroup array{group: int, parent_id: int, members: list<StockMember>, excluded: list<StockLeftOut>}
 * @phpstan-type StockPage array{groups: list<StockGroup>, next: int|null}
 * @phpstan-type StockScanContext array{sync: StockCopyRules, members: array<int, int>, languages: array<int, string>, default: int}
 */
final class StockDriftScan {

	/**
	 * Most translation groups one page reads.
	 */
	public const MAX_BATCH = 500;

	/**
	 * Most products and variations whose posts and meta one read primes and the runtime cache holds at once.
	 */
	public const CHILD_CHUNK = 1000;

	/**
	 * Every language, active or not, id => slug, from the languages table (no cache read or write).
	 *
	 * @return array<int, string>
	 * @throws \RuntimeException When the read fails.
	 */
	public static function languages(): array {
		$out = [];

		foreach ( self::language_table() as $id => $language ) {
			$out[ $id ] = $language['slug'];
		}

		return $out;
	}

	/**
	 * Every language, active or not, from the languages table: no cache, because the repository's
	 * cached list is written to a transient on sites without a persistent object cache.
	 *
	 * @return array<int, array{slug: string, name: string, active: bool, default: bool}>
	 * @throws \RuntimeException When the read fails.
	 */
	public static function language_table(): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of the small languages table; the repository's cached list would be written to a transient.
		$sql   = $wpdb->prepare( 'SELECT id, slug, name, is_active, is_default FROM %i', Schema::table( 'languages' ) );
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepared statement above.
		$out   = [];

		self::check_read( $found );

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$slug = $row['slug'] ?? '';

			if ( is_string( $slug ) && '' !== $slug ) {
				$out[ self::id( $row['id'] ?? 0 ) ] = [
					'slug'    => $slug,
					'name'    => is_string( $row['name'] ?? null ) ? $row['name'] : $slug,
					'active'  => 1 === self::id( $row['is_active'] ?? 0 ),
					'default' => 1 === self::id( $row['is_default'] ?? 0 ),
				];
			}
		}

		return $out;
	}

	/**
	 * One page of the scan as report rows: at most $batch product translation groups after $after.
	 *
	 * @param StockCopyRules     $sync         The stock mirror (matching and opt-outs).
	 * @param array<int, string> $languages    Language id => slug, every language.
	 * @param int                $default_lang Id of the default language (0: none).
	 * @param int                $after        Last group id of the previous page (0 to start).
	 * @param int                $batch        Groups per page (1 - MAX_BATCH).
	 * @param list<int>|null     $group_ids    Only these translation groups (null: every group).
	 * @return array{rows: list<array{group: int, product_id: int, parent_id: int, language: string, stock: string}>, groups: int, next: int|null}
	 *         Drifting groups' rows and count, and the next cursor (null after the last).
	 * @throws \InvalidArgumentException When $batch is out of range.
	 * @throws \RuntimeException When a read fails (the page is not reported, nothing past it is read).
	 */
	public static function page( StockCopyRules $sync, array $languages, int $default_lang, int $after, int $batch, ?array $group_ids = null ): array {
		$page = self::groups_page( $sync, $languages, $default_lang, $after, $batch, $group_ids );
		$rows = [];

		foreach ( $page['groups'] as $group ) {
			foreach ( $group['members'] as $member ) {
				$rows[] = [
					'group'      => $group['group'],
					'product_id' => $member['product_id'],
					'parent_id'  => $member['parent_id'],
					'language'   => $member['language'],
					'stock'      => (string) $member['stock'],
				];
			}
		}

		return [
			'rows'   => $rows,
			'groups' => count( $page['groups'] ),
			'next'   => $page['next'],
		];
	}

	/**
	 * One page of the scan: the drifting copy groups of at most $batch product translation groups after $after,
	 * with members, stock and the copies left out (`unmanaged`, `not_numeric`, `opted_out`).
	 *
	 * @param StockCopyRules     $sync         The stock mirror (matching and opt-outs).
	 * @param array<int, string> $languages    Language id => slug, every language.
	 * @param int                $default_lang Id of the default language (0: none).
	 * @param int                $after        Last group id of the previous page (0 to start).
	 * @param int                $batch        Groups per page (1 - MAX_BATCH).
	 * @param list<int>|null     $group_ids    Only these translation groups (null: every group).
	 * @return StockPage
	 * @throws \InvalidArgumentException When $batch is out of range.
	 * @throws \RuntimeException When a read fails.
	 */
	public static function groups_page( StockCopyRules $sync, array $languages, int $default_lang, int $after, int $batch, ?array $group_ids = null ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( $batch < 1 || $batch > self::MAX_BATCH ) {
			throw new \InvalidArgumentException( esc_html( sprintf( '--batch-size must be between 1 and %d.', self::MAX_BATCH ) ) );
		}

		$empty = [
			'groups' => [],
			'next'   => null,
		];

		if ( null !== $group_ids ) {
			$group_ids = array_values( array_filter( array_map( 'intval', $group_ids ), static fn( int $id ): bool => $id > $after ) );

			if ( [] === $group_ids ) {
				return $empty;
			}
		}

		$links = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A read-only scan in keyset pages; no WP API reads the link table by group range. The IN lists hold only %d placeholders (at most the caller's 500 products' groups, or one page of group ids).
		$only = null !== $group_ids ? ' AND l.group_id IN (' . implode( ', ', array_fill( 0, count( $group_ids ), '%d' ) ) . ')' : '';
		$sql  = $wpdb->prepare(
			"SELECT DISTINCT l.group_id FROM %i l INNER JOIN %i p ON p.ID = l.object_id WHERE l.type = 'post' AND p.post_type = 'product' AND l.group_id > %d{$only} ORDER BY l.group_id LIMIT %d",
			array_merge( [ $links, $wpdb->posts, $after ], $group_ids ?? [], [ $batch ] )
		);
		$col  = is_string( $sql ) ? $wpdb->get_col( $sql ) : null;

		self::check_read( $col );

		$ids = array_values( wp_parse_id_list( is_array( $col ) ? $col : [] ) );

		if ( [] === $ids ) {
			return $empty;
		}

		$sql   = $wpdb->prepare(
			"SELECT l.group_id, l.object_id, l.language_id FROM %i l INNER JOIN %i p ON p.ID = l.object_id WHERE l.type = 'post' AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft') AND l.group_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY l.group_id, l.object_id',
			array_merge( [ $links, $wpdb->posts ], $ids )
		);
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::check_read( $found );

		$in_page = array_flip( $ids );
		$groups  = [];
		$members = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$group = self::id( $row['group_id'] ?? 0 );

			if ( ! isset( $in_page[ $group ] ) ) {
				continue;
			}

			$product              = self::id( $row['object_id'] ?? 0 );
			$groups[ $group ][]   = $product;
			$members[ $product ]  = self::id( $row['language_id'] ?? 0 );
		}

		$counts = self::child_counts( array_keys( $members ) );
		$ctx    = [
			'sync'      => $sync,
			'members'   => $members,
			'languages' => $languages,
			'default'   => $default_lang,
		];
		$out    = [];
		$run    = [];
		$size   = 0;

		// Runs of at most CHILD_CHUNK products and variations, primed at once, the runtime cache dropped
		// between runs; a larger group is read alone, in pieces (family()).
		foreach ( $groups as $products ) {
			if ( count( $products ) < 2 ) {
				continue;
			}

			$group_size = count( $products );

			foreach ( $products as $product ) {
				$group_size += $counts[ $product ] ?? 0;
			}

			if ( $group_size > self::CHILD_CHUNK ) {
				if ( [] !== $run ) {
					self::run_groups( $ctx, $run, $counts, $out );
					\PerfLocale\Cache\CacheManager::flush_runtime();
					$run  = [];
					$size = 0;
				}

				self::family( $ctx, $products, $counts, $out );
				\PerfLocale\Cache\CacheManager::flush_runtime();
				continue;
			}

			if ( [] !== $run && $size + $group_size > self::CHILD_CHUNK ) {
				self::run_groups( $ctx, $run, $counts, $out );
				\PerfLocale\Cache\CacheManager::flush_runtime();
				$run  = [];
				$size = 0;
			}

			$run[] = $products;
			$size += $group_size;
		}

		if ( [] !== $run ) {
			self::run_groups( $ctx, $run, $counts, $out );
		}

		return [
			'groups' => $out,
			'next'   => count( $ids ) < $batch ? null : $ids[ count( $ids ) - 1 ],
		];
	}

	/**
	 * Read a run of groups that fits one chunk: variation ids, then posts and meta primed at once, then drift.
	 *
	 * @param StockScanContext  $ctx    Scan context (sync, members, languages, default).
	 * @param list<list<int>>   $run    The run's groups, each its products in link order.
	 * @param array<int, int>   $counts Product id => variation count.
	 * @param list<StockGroup>  $out    Drifting groups so far.
	 * @return void
	 * @throws \RuntimeException When a read fails.
	 */
	private static function run_groups( array $ctx, array $run, array $counts, array &$out ): void {
		$parents = [];
		$ids     = [];

		foreach ( $run as $products ) {
			foreach ( $products as $product ) {
				$ids[] = $product;

				if ( ( $counts[ $product ] ?? 0 ) > 0 ) {
					$parents[] = $product;
				}
			}
		}

		$children = self::children( $parents );

		foreach ( $children as $list ) {
			array_push( $ids, ...$list );
		}

		self::prime( $ids );

		foreach ( $run as $products ) {
			self::group_rows( $ctx, $products, $children, $out );
		}
	}

	/**
	 * One primed group's drift: its products, then each anchor variation with its matches in the others.
	 *
	 * @param StockScanContext      $ctx      Scan context.
	 * @param list<int>             $products The group's products in link order.
	 * @param array<int, list<int>> $children Product id => variation ids.
	 * @param list<StockGroup>      $out      Drifting groups so far.
	 * @return void
	 */
	private static function group_rows( array $ctx, array $products, array $children, array &$out ): void {
		$sync   = $ctx['sync'];
		$anchor = self::anchor( $ctx, $products );

		self::product_group( $ctx, $products, $anchor, $out );

		// The anchor's variations that hold a stock and a signature, in order.
		$variations = [];

		foreach ( $children[ $anchor ] ?? [] as $variation ) {
			$stock = self::stock( $sync, $variation );

			if ( null === $stock ) {
				continue;
			}

			$sig = $sync->variation_signature( $variation, $anchor );

			if ( null !== $sig ) {
				$variations[] = [ $variation, $stock, $sig ];
			}
		}

		if ( [] === $variations ) {
			return;
		}

		// Each other product's first variation per signature.
		$copies    = [];
		$opted_out = [];

		foreach ( $products as $product ) {
			if ( $product === $anchor || ! isset( $children[ $product ] ) ) {
				continue;
			}

			if ( $sync->is_sync_opted_out( $product ) ) {
				$opted_out[] = $product;
				continue;
			}

			$copies[ $product ] = [];

			foreach ( $children[ $product ] as $child ) {
				$sig = $sync->variation_signature( $child, $product );

				if ( null !== $sig && ! isset( $copies[ $product ][ $sig ] ) ) {
					$copies[ $product ][ $sig ] = $child;
				}
			}
		}

		foreach ( $variations as [ $variation, $stock, $sig ] ) {
			$matched = [];

			foreach ( $copies as $product => $first ) {
				$matched[ $product ] = $first[ $sig ] ?? 0;
			}

			self::variation_group( $ctx, $anchor, $variation, $stock, $matched, $opted_out, $out );
		}
	}

	/**
	 * A product family too large for one chunk, matched piece by piece against a signature-hash index.
	 *
	 * @param StockScanContext $ctx      Scan context.
	 * @param list<int>        $products The group's products in link order.
	 * @param array<int, int>  $counts   Product id => variation count.
	 * @param list<StockGroup> $out      Drifting groups so far.
	 * @return void
	 * @throws \RuntimeException When a read fails.
	 */
	private static function family( array $ctx, array $products, array $counts, array &$out ): void {
		$sync = $ctx['sync'];

		self::prime( $products );

		$anchor = self::anchor( $ctx, $products );

		self::product_group( $ctx, $products, $anchor, $out );

		if ( ( $counts[ $anchor ] ?? 0 ) < 1 ) {
			return;
		}

		// Each other product's first child per signature: hash => child id.
		$index     = [];
		$opted_out = [];

		foreach ( $products as $product ) {
			if ( $product === $anchor || ( $counts[ $product ] ?? 0 ) < 1 ) {
				continue;
			}

			if ( $sync->is_sync_opted_out( $product ) ) {
				$opted_out[] = $product;
				continue;
			}

			$index[ $product ] = [];

			foreach ( self::child_chunks( $product ) as $chunk ) {
				foreach ( $chunk as $child ) {
					$sig = $sync->variation_signature( $child, $product );

					if ( null !== $sig ) {
						$key = self::sig_key( $sig );

						if ( ! isset( $index[ $product ][ $key ] ) ) {
							$index[ $product ][ $key ] = $child;
						}
					}
				}
			}
		}

		// A piece's variations and their matched copies are held together until the cache
		// flush: size it so all of them stay within CHILD_CHUNK, whatever the languages.
		$piece_size = intdiv( self::CHILD_CHUNK, count( $index ) + 1 );

		foreach ( self::child_chunks( $anchor, $piece_size ) as $chunk ) {
			$piece  = [];
			$copies = [];

			foreach ( $chunk as $variation ) {
				$stock = self::stock( $sync, $variation );

				if ( null === $stock ) {
					continue;
				}

				$sig = $sync->variation_signature( $variation, $anchor );

				if ( null === $sig ) {
					continue;
				}

				$matched = [];

				foreach ( $index as $product => $first ) {
					$matched[ $product ] = $first[ self::sig_key( $sig ) ] ?? 0;

					if ( $matched[ $product ] > 0 ) {
						$copies[] = $matched[ $product ];
					}
				}

				$piece[] = [ $variation, $stock, $sig, $matched ];
			}

			// Prime this piece's matched copies with their parents (the signature reads the parent's attributes).
			if ( [] !== $copies ) {
				self::prime( array_merge( array_keys( $index ), $copies ) );
			}

			foreach ( $piece as [ $variation, $stock, $sig, $matched ] ) {
				// A hash shared by two signatures matches nothing.
				foreach ( $matched as $product => $copy ) {
					if ( $copy > 0 && $sync->variation_signature( $copy, $product ) !== $sig ) {
						$matched[ $product ] = 0;
					}
				}

				self::variation_group( $ctx, $anchor, $variation, $stock, $matched, $opted_out, $out );
			}
		}
	}

	/**
	 * The group's anchor: the member in the default language, else the first product (lowest id).
	 *
	 * @param StockScanContext $ctx      Scan context.
	 * @param list<int>        $products The group's products in link order.
	 * @return int
	 */
	private static function anchor( array $ctx, array $products ): int {
		foreach ( $products as $product ) {
			if ( $ctx['default'] > 0 && $ctx['members'][ $product ] === $ctx['default'] ) {
				return $product;
			}
		}

		return $products[0];
	}

	/**
	 * Add the product-level copy group when it drifts.
	 *
	 * @param StockScanContext $ctx      Scan context.
	 * @param list<int>        $products The group's products in link order (primed).
	 * @param int              $anchor   The anchor.
	 * @param list<StockGroup> $out      Drifting groups so far.
	 * @return void
	 */
	private static function product_group( array $ctx, array $products, int $anchor, array &$out ): void {
		$sync = $ctx['sync'];
		$set  = [];

		foreach ( $products as $product ) {
			$stock = self::stock( $sync, $product );

			if ( null !== $stock ) {
				$set[ $product ] = [ 0, $stock ];
			}
		}

		if ( ! self::differs( $set ) ) {
			return;
		}

		$excluded = [];

		foreach ( $products as $product ) {
			if ( ! isset( $set[ $product ] ) ) {
				$excluded[] = self::left_out( $ctx, $product, 0, $product );
			}
		}

		$out[] = self::group( $ctx, $anchor, 0, $set, $excluded );
	}

	/**
	 * Add one variation copy group when it drifts.
	 *
	 * @param StockScanContext $ctx       Scan context.
	 * @param int              $anchor    The anchor product.
	 * @param int              $variation The anchor's variation.
	 * @param string           $stock     Its stock.
	 * @param array<int, int>  $matched   Other product => its matched variation (0: none).
	 * @param list<int>        $opted_out Other products opted out of the sync.
	 * @param list<StockGroup> $out       Drifting groups so far.
	 * @return void
	 */
	private static function variation_group( array $ctx, int $anchor, int $variation, string $stock, array $matched, array $opted_out, array &$out ): void {
		$sync = $ctx['sync'];
		$set  = [ $variation => [ $anchor, $stock ] ];

		foreach ( $matched as $product => $copy ) {
			$copy_stock = $copy > 0 ? self::stock( $sync, $copy ) : null;

			if ( null !== $copy_stock ) {
				$set[ $copy ] = [ $product, $copy_stock ];
			}
		}

		if ( ! self::differs( $set ) ) {
			return;
		}

		$excluded = [];

		foreach ( $matched as $product => $copy ) {
			if ( $copy > 0 && ! isset( $set[ $copy ] ) ) {
				$excluded[] = self::left_out( $ctx, $copy, $product, $product );
			}
		}

		foreach ( $opted_out as $product ) {
			$excluded[] = [
				'product_id' => 0,
				'parent_id'  => $product,
				'language'   => $ctx['languages'][ $ctx['members'][ $product ] ?? 0 ] ?? '',
				'reason'     => 'opted_out',
			];
		}

		$out[] = self::group( $ctx, $variation, $anchor, $set, $excluded );
	}

	/**
	 * A drifting group.
	 *
	 * @param StockScanContext                     $ctx      Scan context.
	 * @param int                                  $group    The anchor id.
	 * @param int                                  $parent   0, or the anchor product.
	 * @param array<int, array{0: int, 1: string}> $set      Member id => [ parent id, stock ].
	 * @param list<StockLeftOut>                   $excluded Copies left out.
	 * @return StockGroup
	 */
	private static function group( array $ctx, int $group, int $parent, array $set, array $excluded ): array {
		$members = [];

		foreach ( $set as $id => [ $member_parent, $stock ] ) {
			$members[] = [
				'product_id' => $id,
				'parent_id'  => $member_parent,
				'language'   => $ctx['languages'][ $ctx['members'][ 0 === $member_parent ? $id : $member_parent ] ?? 0 ] ?? '',
				'stock'      => (float) $stock,
			];
		}

		return [
			'group'     => $group,
			'parent_id' => $parent,
			'members'   => $members,
			'excluded'  => $excluded,
		];
	}

	/**
	 * A copy left out of its group, with why.
	 *
	 * @param StockScanContext $ctx         Scan context.
	 * @param int              $id          Product or variation id.
	 * @param int              $parent      0, or its product.
	 * @param int              $language_of The product whose language it has.
	 * @return StockLeftOut
	 */
	private static function left_out( array $ctx, int $id, int $parent, int $language_of ): array {
		$sync = $ctx['sync'];

		if ( $sync->is_sync_opted_out( $id ) ) {
			$reason = 'opted_out';
		} elseif ( 'yes' !== get_post_meta( $id, '_manage_stock', true ) ) {
			$reason = 'unmanaged';
		} else {
			$reason = 'not_numeric';
		}

		return [
			'product_id' => $id,
			'parent_id'  => $parent,
			'language'   => $ctx['languages'][ $ctx['members'][ $language_of ] ?? 0 ] ?? '',
			'reason'     => $reason,
		];
	}

	/**
	 * A product's variations, $size at a time by keyset in WooCommerce's order (menu_order, ID);
	 * each chunk is primed with the parent, the runtime cache dropped after it.
	 *
	 * @param int $parent_id Product id.
	 * @param int $size      Variations per chunk (1 to CHILD_CHUNK).
	 * @return \Generator<int, list<int>>
	 * @throws \RuntimeException When a read fails.
	 */
	private static function child_chunks( int $parent_id, int $size = self::CHILD_CHUNK ): \Generator {
		$size = max( 1, min( self::CHILD_CHUNK, $size ) );
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$first = true;
		$order = 0;
		$last  = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One keyset page of one product's variation ids.
			$sql = $first
				? $wpdb->prepare(
					"SELECT ID, menu_order FROM %i WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private') AND post_parent = %d ORDER BY menu_order, ID LIMIT %d",
					$wpdb->posts,
					$parent_id,
					$size
				)
				: $wpdb->prepare(
					"SELECT ID, menu_order FROM %i WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private') AND post_parent = %d AND ( menu_order > %d OR ( menu_order = %d AND ID > %d ) ) ORDER BY menu_order, ID LIMIT %d",
					$wpdb->posts,
					$parent_id,
					$order,
					$order,
					$last,
					$size
				);
			$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			self::check_read( $found );

			$chunk = [];

			foreach ( is_array( $found ) ? $found : [] as $row ) {
				$chunk[] = self::id( $row['ID'] ?? 0 );
				$order   = is_numeric( $row['menu_order'] ?? null ) ? (int) $row['menu_order'] : 0;
			}

			unset( $found );

			if ( [] === $chunk ) {
				return;
			}

			$last  = $chunk[ count( $chunk ) - 1 ];
			$first = false;

			self::prime( array_merge( [ $parent_id ], $chunk ) );

			yield $chunk;

			\PerfLocale\Cache\CacheManager::flush_runtime();
		} while ( count( $chunk ) === $size );
	}

	/**
	 * A signature as one integer: the first 8 bytes of its MD5.
	 *
	 * @param string $sig Signature.
	 * @return int
	 */
	private static function sig_key( string $sig ): int {
		$key = unpack( 'J', md5( $sig, true ) );

		return is_array( $key ) && is_int( $key[1] ?? null ) ? $key[1] : 0;
	}

	/**
	 * Prime the posts and meta of these products and variations (the ones not cached yet).
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
	 * How many published and private variations each of these products has.
	 *
	 * @param array<int, int> $parents Product ids (one page's members).
	 * @return array<int, int> Product id => variation count (products with none left out).
	 * @throws \RuntimeException When the read fails.
	 */
	private static function child_counts( array $parents ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( [] === $parents ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One read of one page's variation counts; the IN list holds only %d placeholders, one per product of the page (at most MAX_BATCH groups' members).
		$sql   = $wpdb->prepare(
			"SELECT post_parent, COUNT(*) AS n FROM %i WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private') AND post_parent IN (" . implode( ', ', array_fill( 0, count( $parents ), '%d' ) ) . ') GROUP BY post_parent',
			array_merge( [ $wpdb->posts ], array_values( $parents ) )
		);
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		self::check_read( $found );

		$out = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$out[ self::id( $row['post_parent'] ?? 0 ) ] = self::id( $row['n'] ?? 0 );
		}

		return $out;
	}

	/**
	 * The published and private variation ids of these products, in WooCommerce's order (menu_order, ID).
	 *
	 * @param array<int, int> $parents Product ids (one run's products that have variations).
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One read of one run's variations (at most CHILD_CHUNK); the IN list holds only %d placeholders.
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
	 * Stop on a read that failed: wpdb answers a failure with no rows and last_error, never an empty result.
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
	 * A product's or variation's stored stock when it belongs in a copy group (managed, numeric, not opted out).
	 *
	 * @param StockCopyRules $sync The stock mirror (opt-outs).
	 * @param int            $id   Product or variation id.
	 * @return string|null
	 */
	private static function stock( StockCopyRules $sync, int $id ): ?string {
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
}
