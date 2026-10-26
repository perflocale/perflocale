<?php
/**
 * Migration source-map repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `(migration_type, source_key) -> group_id`: pins a source plugin's id (WPML trid, Polylang term_id) to the group
 * the importer created, so a retry after a crash or restore reuses it. Writes upsert on the UNIQUE (type, key), so
 * concurrent imports converge; create_group() writes it in its own transaction, the importers read it first.
 */
final class MigrationSourceMapRepository {

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'migration_source_map' );
	}

	/**
	 * The recorded group of a (type, key) pair, or null.
	 *
	 * @param string $migration_type Importer identifier (e.g. 'wpml', 'polylang', 'trp').
	 * @param string $source_key     Per-importer natural key (e.g. WPML "<trid>|<element_type>").
	 * @return int|null translation_groups.id, or null if no mapping recorded.
	 */
	public function get_group_id( string $migration_type, string $source_key ): ?int {
		if ( $migration_type === '' || $source_key === '' ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT group_id FROM %i WHERE migration_type = %s AND source_key = %s LIMIT 1',
				$this->table(),
				$migration_type,
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $id === null ) {
			return null;
		}

		$id = (int) $id;

		return $id > 0 ? $id : null;
	}

	/**
	 * Record a `(type, key) -> group_id` mapping with an upsert (re-runs converge; id and created_at kept). Called by
	 * create_group() inside its transaction, so mapping and group commit together.
	 *
	 * @param string $migration_type Importer identifier.
	 * @param string $source_key     Per-importer natural key.
	 * @param int    $group_id       translation_groups.id.
	 * @return bool True on success.
	 */
	public function set_group_id( string $migration_type, string $source_key, int $group_id ): bool {
		if ( $migration_type === '' || $source_key === '' || $group_id <= 0 ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (migration_type, source_key, group_id)
				 VALUES (%s, %s, %d)
				 ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)',
				$this->table(),
				$migration_type,
				$source_key,
				$group_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result !== false;
	}

	/**
	 * Source keys of one migration type that map onto a group (through the `group_id` index, at most `$limit`).
	 *
	 * @param string $migration_type Importer identifier.
	 * @param int    $group_id       translation_groups.id.
	 * @param int    $limit          Maximum keys returned.
	 * @return list<string>
	 */
	public function keys_for_group( string $migration_type, int $group_id, int $limit = 10 ): array {
		if ( $migration_type === '' || $group_id <= 0 ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$keys = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT source_key FROM %i WHERE group_id = %d AND migration_type = %s ORDER BY id ASC LIMIT %d',
				$this->table(),
				$group_id,
				$migration_type,
				max( 1, $limit )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_values( array_map( static fn( $key ): string => is_scalar( $key ) ? (string) $key : '', (array) $keys ) );
	}

	/**
	 * Remove one `(type, key)` mapping.
	 *
	 * @param string $migration_type Importer identifier.
	 * @param string $source_key     Per-importer natural key.
	 * @return bool True when the query ran.
	 */
	public function delete_key( string $migration_type, string $source_key ): bool {
		if ( $migration_type === '' || $source_key === '' ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->delete(
			$this->table(),
			[
				'migration_type' => $migration_type,
				'source_key'     => $source_key,
			],
			[ '%s', '%s' ]
		);

		return $deleted !== false;
	}

	/**
	 * Clear every mapping of one migration type (`--force-restart`, for a clean re-import).
	 *
	 * @param string $migration_type Importer identifier.
	 * @return int Number of rows deleted.
	 */
	public function delete_for_type( string $migration_type ): int {
		if ( $migration_type === '' ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->delete(
			$this->table(),
			[ 'migration_type' => $migration_type ],
			[ '%s' ]
		);

		return $deleted === false ? 0 : (int) $deleted;
	}
}
