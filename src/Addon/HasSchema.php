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
 * Opt-in capability interface for addons that own database tables.
 *
 * Addons implement this to declare table schemas + migration steps. The
 * plugin's Migrator runs pending migrations on every version bump, tracks
 * per-addon versions in the `perflocale_addon_schema_versions` option, and
 * isolates failures so one broken addon doesn't block others.
 *
 * Naming rules enforced by AddonSchemaManager:
 * • addon_id regex /^[a-z0-9_]{2,16}$/
 * • short_name regex /^[a-z0-9_]{1,16}$/
 * • full table name = $wpdb->prefix . 'perflocale_addon_' . addon_id . '_' . short_name
 *
 * The 32-char budget for (addon_id + '_' + short_name) keeps the total
 * table-name length ≤ 64 (MySQL limit) even on multisite subsites whose
 * prefix runs up to 16 chars (e.g. wp_123456_).
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasSchema {

	/**
	 * Table schema bodies keyed by short name.
	 *
	 * Each value is the CREATE TABLE body (columns + indexes + constraints)
	 * WITHOUT the surrounding `CREATE TABLE name (…)` wrapper. The plugin
	 * wraps each body with the enforced prefix + charset/collate clause and
	 * runs it through dbDelta - so dbDelta's usual formatting rules apply
	 * (two-space indent, one column per line, PRIMARY KEY spelled in full).
	 *
	 * Example:
	 * return [
	 * 've_documents' => "
	 * id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	 * post_id BIGINT UNSIGNED NOT NULL,
	 * data LONGTEXT NOT NULL,
	 * updated_at DATETIME NOT NULL,
	 * PRIMARY KEY (id),
	 * KEY post_id (post_id)
	 * ",
	 * ];
	 *
	 * Return an empty array for addons that declare no tables.
	 *
	 * @return array<string, string>
	 */
	public function get_schema(): array;

	/**
	 * Current schema version.
	 *
	 * Integer, monotonically increasing. Bump by 1 on every breaking schema
	 * change. Fresh installs run dbDelta once + every migrate_to(1..N) in
	 * order. Existing installs run only the migrate_to(stored+1..N) steps.
	 *
	 * Starting value for a new addon: 1.
	 *
	 * @return int
	 */
	public function get_schema_version(): int;

	/**
	 * Run migration step for $target_version.
	 *
	 * Called by the Migrator once per intermediate version between the
	 * stored version and get_schema_version(), inclusive. Return true on
	 * success; return false OR throw to halt - stored_version stays at
	 * (target_version - 1) and retries on the next request.
	 *
	 * MUST BE IDEMPOTENT. MySQL/MariaDB implicit-commit DDL (CREATE/ALTER/
	 * DROP TABLE), so partial failures cannot be rolled back. Write your
	 * SQL so that re-running produces the same end state:
	 *
	 * • Use dbDelta for column additions (auto-compares current schema)
	 * • Use IF NOT EXISTS / IF EXISTS clauses where supported
	 * • Check information_schema.COLUMNS before ALTER TABLE ADD COLUMN
	 * • Wrap DML batches in $wpdb->query('START TRANSACTION') + COMMIT
	 * yourself if atomicity matters - PerfLocale does NOT wrap for you
	 *
	 * @param int $target_version The version about to be applied.
	 * @return bool True on success, false to halt.
	 */
	public function migrate_to( int $target_version ): bool;
}
