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
 * Looks up + records `(migration_type, source_key) -> group_id` mappings.
 *
 * Pins a source-plugin identifier (WPML trid, Polylang term_id, etc.) to
 * the translation_groups row the importer created for it, so a partial-
 * failure crash or a post-import DB restore can't allocate a duplicate
 * group_id on retry. Strings already get this guarantee from REPLACE
 * INTO; this table extends the same idempotency to posts and terms.
 *
 * The UNIQUE (migration_type, source_key) constraint backs the
 * lookup-or-create dance: every write uses ON DUPLICATE KEY UPDATE so
 * concurrent imports converge to a single row per source identifier.
 *
 * Used by:
 *   - {@see \PerfLocale\Database\Repository\TranslationGroupRepository::create_group()}
 *     writes the mapping inside its own create_group transaction.
 *   - {@see \PerfLocale\Migration\WpmlImporter} and
 *     {@see \PerfLocale\Migration\PolylangImporter} look up before
 *     creating a new group, so re-runs reuse existing IDs.
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
	 * Resolve the full table name for the current blog.
	 *
	 * Computed on every call so that switch_to_blog() flips the prefix
	 * correctly — capturing the name in the constructor would otherwise pin
	 * this instance to whichever blog was active at construction. Same rule
	 * as every sibling repository; today's callers build the repo inside the
	 * blog they operate on, so this is the convention holding rather than a
	 * bug being fixed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'migration_source_map' );
	}

	/**
	 * Resolve a previously-recorded mapping, or null when nothing's been
	 * stored for this (type, key) pair yet.
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
	 * Record a `(type, key) -> group_id` mapping.
	 *
	 * Uses `INSERT … ON DUPLICATE KEY UPDATE` so re-importers (and crash
	 * recovery paths) converge to the most-recent mapping without
	 * touching the existing row's id or created_at.
	 *
	 * Called from {@see TranslationGroupRepository::create_group()} INSIDE
	 * the same transaction that creates the group row, so the mapping and
	 * the group are atomically committed together.
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
	 * Clear every mapping for one migration type. Used by the operator-
	 * driven `--force-restart` flow when they want a clean re-import
	 * (e.g. after intentionally restoring a backup to a known-good state).
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
