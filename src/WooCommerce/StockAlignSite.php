<?php
/**
 * WooCommerce stock alignment: the engine wired to the current site.
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
 * What every stock alignment entry point (WP-CLI, settings block, jobs, PHP API) needs from this site:
 * languages (read from the table, no cache write), scan pages, the engine and product groups.
 *
 * @internal Internal to PerfLocale; not a public API.
 *
 * @phpstan-import-type StockPage from StockDriftScan
 */
final class StockAlignSite {

	/**
	 * The stock mirror.
	 *
	 * @var InventorySync
	 */
	private InventorySync $sync;

	/**
	 * Every language: id => slug, name, active, default.
	 *
	 * @var array<int, array{slug: string, name: string, active: bool, default: bool}>
	 */
	private array $languages;

	/**
	 * Constructor: reads the languages table.
	 *
	 * @param InventorySync $sync The stock mirror.
	 * @throws \RuntimeException When the read fails.
	 */
	public function __construct( InventorySync $sync ) {
		$this->sync      = $sync;
		$this->languages = StockDriftScan::language_table();
	}

	/**
	 * Slugs of the active languages.
	 *
	 * @return list<string>
	 */
	public function active_slugs(): array {
		$out = [];

		foreach ( $this->languages as $language ) {
			if ( $language['active'] ) {
				$out[] = $language['slug'];
			}
		}

		return $out;
	}

	/**
	 * The default language's slug ('' when there is none).
	 *
	 * @return string
	 */
	public function default_slug(): string {
		foreach ( $this->languages as $language ) {
			if ( $language['default'] ) {
				return $language['slug'];
			}
		}

		return '';
	}

	/**
	 * The engine's reference: 'default' becomes the default language's slug.
	 *
	 * @param string|callable $from 'default', a language slug, or a callable.
	 * @return string|callable
	 */
	public function reference( string|callable $from ): string|callable {
		return 'default' === $from ? $this->default_slug() : $from;
	}

	/**
	 * The drifting groups, one page at a time, the runtime cache dropped after each page.
	 *
	 * @return callable(int, int, list<int>|null): StockPage
	 */
	public function pages(): callable {
		$sync    = new StockMirrorRules( $this->sync );
		$slugs   = [];
		$default = 0;

		foreach ( $this->languages as $id => $language ) {
			$slugs[ $id ] = $language['slug'];

			if ( $language['default'] ) {
				$default = $id;
			}
		}

		return static function ( int $after, int $batch, ?array $group_ids ) use ( $sync, $slugs, $default ): array {
			$page = StockDriftScan::groups_page( $sync, $slugs, $default, $after, $batch, null === $group_ids ? null : self::ids( $group_ids ) );

			\PerfLocale\Cache\CacheManager::flush_runtime();

			return $page;
		};
	}

	/**
	 * The engine on this site's store.
	 *
	 * @return StockDriftAlign
	 */
	public function engine(): StockDriftAlign {
		return new StockDriftAlign( new WcStockAlignBackend( $this->sync ), $this->pages() );
	}

	/**
	 * The translation groups of these products (any language). A variation id is refused; non-products name none.
	 *
	 * @param list<int> $product_ids Product ids (1 - StockAlignArgs::MAX_PRODUCT_IDS).
	 * @return list<int>|\WP_Error
	 * @throws \RuntimeException When a read fails.
	 */
	public function group_ids( array $product_ids ): array|\WP_Error {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( [] === $product_ids ) {
			return [];
		}

		$in = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads of at most 500 caller-named ids; the IN lists hold only %d placeholders.
		$sql   = $wpdb->prepare( "SELECT ID, post_type FROM %i WHERE ID IN ({$in})", array_merge( [ $wpdb->posts ], $product_ids ) );
		$found = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;

		self::check_read( $found );

		$products = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$id   = is_numeric( $row['ID'] ?? null ) ? (int) $row['ID'] : 0;
			$type = is_string( $row['post_type'] ?? null ) ? $row['post_type'] : '';

			if ( 'product_variation' === $type ) {
				return StockAlignArgs::bad_ids( $id );
			}

			if ( 'product' === $type ) {
				$products[] = $id;
			}
		}

		if ( [] === $products ) {
			return [];
		}

		$sql   = $wpdb->prepare(
			"SELECT DISTINCT group_id FROM %i WHERE type = 'post' AND object_id IN (" . implode( ', ', array_fill( 0, count( $products ), '%d' ) ) . ') ORDER BY group_id',
			array_merge( [ Schema::table( 'translation_links' ) ], $products )
		);
		$found = is_string( $sql ) ? $wpdb->get_col( $sql ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::check_read( $found );

		$out = [];

		foreach ( is_array( $found ) ? $found : [] as $group ) {
			if ( is_numeric( $group ) ) {
				$out[] = (int) $group;
			}
		}

		return $out;
	}

	/**
	 * The translation group of a copy group's anchor (a product or a variation): 0 when it has none.
	 *
	 * @param int $anchor Product or variation id.
	 * @return int
	 * @throws \RuntimeException When a read fails.
	 */
	public function group_of( int $anchor ): int {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One row of one anchor.
		$sql = $wpdb->prepare(
			"SELECT l.group_id FROM %i l INNER JOIN %i p ON l.object_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END WHERE p.ID = %d AND l.type = 'post' LIMIT 1",
			Schema::table( 'translation_links' ),
			$wpdb->posts,
			$anchor
		);
		$id  = is_string( $sql ) ? $wpdb->get_var( $sql ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
		}

		return is_numeric( $id ) ? (int) $id : 0;
	}

	/**
	 * How many product translation groups the shop has (the size the background threshold compares).
	 *
	 * @return int
	 */
	public static function product_groups(): int {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One count, on the request that dispatches a stock job.
		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT l.group_id) FROM %i l INNER JOIN %i p ON p.ID = l.object_id WHERE l.type = 'post' AND p.post_type = 'product'",
			Schema::table( 'translation_links' ),
			$wpdb->posts
		);
		$n   = is_string( $sql ) ? $wpdb->get_var( $sql ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return is_numeric( $n ) ? (int) $n : 0;
	}

	/**
	 * Whole numbers from a list (other values left out).
	 *
	 * @param array<mixed> $values Values.
	 * @return list<int>
	 */
	private static function ids( array $values ): array {
		$out = [];

		foreach ( $values as $value ) {
			if ( is_int( $value ) ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	/**
	 * Stop on a read that failed.
	 *
	 * @param mixed $found What the read returned.
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
			throw new \RuntimeException( esc_html__( 'The stock check stopped because a database read failed. Try again.', 'perflocale' ) );
		}
	}
}
