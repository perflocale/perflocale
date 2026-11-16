<?php
/**
 * The pending-change reads of admin screens.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Database\Schema;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reads an edit screen, the Translations page and the Strings page run with Content ReSync on, apart from
 * PendingChangeRepository and ChangeService so those screens do not load them.
 *
 * @since 1.0.8
 */
final class PendingChangeReads {

	/** PendingChangeRepository::STATUS_PENDING; naming the constant would load the repository. */
	private const PENDING = 'pending';

	/**
	 * Whether this blog has the automation tables (database version 2 or later).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return Scalar::int( get_option( 'perflocale_db_version', 0 ) ) >= 2;
	}

	/**
	 * The pending row of each of several objects of one type, narrow, in one statement on the `target` index.
	 *
	 * @param string            $object_type Object type.
	 * @param array<int, mixed> $object_ids  Object ids.
	 * @return array<int, array{id: int, object_id: int, language_id: int, source_id: int, reason: string}> Keyed by object id.
	 */
	public static function for_objects( string $object_type, array $object_ids ): array {
		global $wpdb;

		$ids = [];

		foreach ( $object_ids as $id ) {
			$id = Scalar::int( $id );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( [] === $ids || ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$ids = array_values( $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, object_id, language_id, source_id, reason FROM %i WHERE object_type = %s AND object_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') AND status = %s ORDER BY id',
				array_merge( [ Schema::table( 'pending_changes' ), $object_type ], $ids, [ self::PENDING ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$object_id = Scalar::int( $row['object_id'] ?? 0 );

			$out[ $object_id ] = [
				'id'          => Scalar::int( $row['id'] ?? 0 ),
				'object_id'   => $object_id,
				'language_id' => Scalar::int( $row['language_id'] ?? 0 ),
				'source_id'   => Scalar::int( $row['source_id'] ?? 0 ),
				'reason'      => Scalar::str( $row['reason'] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * The newest pending string changes with payload, for the Strings review list.
	 *
	 * @param int                $limit           Most rows.
	 * @param array<int, string> $exclude_domains Domains left out.
	 * @return list<array{id: int, object_id: int, language_id: int, payload: array<mixed>, reason: string, created_at: string}>
	 */
	public static function strings( int $limit, array $exclude_domains = [] ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$exclude = array_values( array_filter( $exclude_domains, static fn ( string $d ): bool => '' !== $d ) );
		$table   = Schema::table( 'pending_changes' );
		$pending = self::PENDING;

		if ( [] === $exclude ) {
			$sql    = 'SELECT id, object_id, language_id, payload, reason, created_at FROM %i WHERE status = %s AND decided_at IS NULL AND object_type = %s ORDER BY id DESC LIMIT %d';
			$values = [ $table, $pending, 'string', max( 1, $limit ) ];
		} else {
			$sql    = 'SELECT p.id, p.object_id, p.language_id, p.payload, p.reason, p.created_at FROM %i p INNER JOIN %i s ON s.id = p.object_id'
				. ' WHERE p.status = %s AND p.decided_at IS NULL AND p.object_type = %s AND s.domain NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ') ORDER BY p.id DESC LIMIT %d';
			$values = array_merge( [ $table, Schema::table( 'strings' ), $pending, 'string' ], $exclude, [ max( 1, $limit ) ] );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The domain list is placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$payload = is_string( $row['payload'] ?? null ) && '' !== $row['payload'] ? json_decode( $row['payload'], true ) : null;

			$out[] = [
				'id'          => Scalar::int( $row['id'] ?? 0 ),
				'object_id'   => Scalar::int( $row['object_id'] ?? 0 ),
				'language_id' => Scalar::int( $row['language_id'] ?? 0 ),
				'payload'     => is_array( $payload ) ? $payload : [],
				'reason'      => Scalar::str( $row['reason'] ?? '' ),
				'created_at'  => Scalar::str( $row['created_at'] ?? '' ),
			];
		}

		return $out;
	}
}
