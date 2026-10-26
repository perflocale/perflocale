<?php
/**
 * Addon schema capability.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in capability for addons that own database tables.
 *
 * Tables are named {prefix}perflocale_addon_{addon_id}_{short_name}, hyphens stripped; short names must match
 * AddonSchemaManager::SHORT_NAME_PATTERN. A full name over 64 characters is refused on MySQL and MariaDB.
 * One addon's failed migration does not block the others.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasSchema {

	/**
	 * Table schema bodies keyed by short name; an empty array for none.
	 *
	 * Each body is the column and index list without the `CREATE TABLE name (...)` wrapper. It runs through dbDelta,
	 * so dbDelta's rules apply (two-space indent, one column per line, PRIMARY KEY spelled in full).
	 *
	 * @return array<string, string>
	 */
	public function get_schema(): array;

	/**
	 * Current schema version: an integer starting at 1, bumped by 1 on every schema change.
	 *
	 * While the stored version is behind, dbDelta runs and then migrate_to( stored + 1 ... current ) in order.
	 *
	 * @return int
	 */
	public function get_schema_version(): int;

	/**
	 * Run the migration step for $target_version.
	 *
	 * Must be idempotent: DDL commits implicitly, so a failed step cannot roll back and runs again on a later pass.
	 * Return false or throw to halt; the stored version stays at $target_version - 1. DML is not wrapped in a transaction.
	 *
	 * @param int $target_version The version about to be applied.
	 * @return bool True on success, false to halt.
	 */
	public function migrate_to( int $target_version ): bool;
}
