<?php
/**
 * Body of LanguageRepository::find_many().
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch language fetch, in its own class so the always-loaded repository does not compile it.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class LanguageFindMany {

	/**
	 * Languages by id: active ones from the bootstrap, then the per-id L1 cache, then one IN() query that primes it.
	 *
	 * @param array<int, int>                $ids    Language IDs.
	 * @param \Closure(): array<int, object> $active The bootstrap's active rows; called only when an id is valid.
	 * @param CacheManager                   $cache  Cache manager.
	 * @param \wpdb                          $wpdb   Database connection.
	 * @return array<int, object> Map of id => language object. Missing IDs are absent.
	 */
	public static function find( array $ids, \Closure $active, CacheManager $cache, \wpdb $wpdb ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$ids = array_filter( $ids, static fn( int $id ): bool => $id > 0 );

		if ( $ids === [] ) {
			return [];
		}

		$result          = [];
		$missing         = [];
		$bootstrap_by_id = [];

		foreach ( $active() as $row ) {
			$bootstrap_by_id[ (int) $row->id ] = $row;
		}

		foreach ( $ids as $id ) {
			if ( isset( $bootstrap_by_id[ $id ] ) ) {
				$result[ $id ] = $bootstrap_by_id[ $id ];
				continue;
			}

			$cached = $cache->get_static( "language_{$id}", 'perflocale_langs' );

			if ( is_object( $cached ) ) {
				$result[ $id ] = $cached;
			} else {
				$missing[] = $id;
			}
		}

		if ( $missing === [] ) {
			return $result;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id IN ({$placeholders})",
				array_merge( [ Schema::table( 'languages' ) ], $missing )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$id = (int) $row->id;
			$cache->set( "language_{$id}", $row, DAY_IN_SECONDS, 'perflocale_langs' );
			$result[ $id ] = $row;
		}

		return $result;
	}
}
