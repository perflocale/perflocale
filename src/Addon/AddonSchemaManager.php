<?php
/**
 * Addon schema manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies addon schemas via dbDelta and tracks per-addon schema versions.
 *
 * Called by the plugin's Migrator on boot whenever an addon's
 * get_schema_version() is ahead of the stored version. Isolates failures
 * per-addon so one broken migration doesn't block the rest.
 *
 * Schema version state lives in the `perflocale_addon_schema_versions`
 * option (autoload=false) as array<addon_id, int>.
 */
final class AddonSchemaManager {

	/** Option name for per-addon schema versions. */
	public const VERSIONS_OPTION = 'perflocale_addon_schema_versions';

	/**
	 * Regex for addon id - enforced by validate_addon_id().
	 *
	 * Hyphens are accepted so the bundled third-party-integration addons
	 * `contact-form-7`, `beaver-builder`, and `gravity-forms` match (without
	 * this, their Disable / settings-save / schema-migration paths silently
	 * no-op). The pattern is byte-oriented and the table-name builder
	 * backtick-quotes the identifier, so hyphens are safe in SQL.
	 */
	public const ADDON_ID_PATTERN = '/^[a-z0-9_-]{2,16}$/';

	/** Regex for short table names - enforced in apply_schema(). */
	public const SHORT_NAME_PATTERN = '/^[a-z0-9_]{1,16}$/';

	/**
	 * Apply schema for an addon and run its pending migrations.
	 *
	 * Returns true on full success, false if any part halted (error recorded
	 * to AddonMigrationErrors). The caller is expected to persist the updated
	 * version map by reading get_stored_versions() after this runs.
	 *
	 * @param HasSchema&AddonInterface $addon The addon to migrate.
	 * @return bool True if fully migrated to target, false if halted.
	 */
	public static function migrate( HasSchema&AddonInterface $addon ): bool {
		$addon_id = $addon->get_id();

		if ( ! self::validate_addon_id( $addon_id ) ) {
			AddonMigrationErrors::record(
				$addon_id,
				'migrate',
				0,
				'Invalid addon id - must match ' . self::ADDON_ID_PATTERN
			);
			return false;
		}

		$target = (int) $addon->get_schema_version();
		if ( $target < 1 ) {
			// Schema version 0 means "no schema managed" - nothing to do.
			return true;
		}

		$stored = self::get_stored_version( $addon_id );
		if ( $stored >= $target ) {
			// Already at or above target. Never downgrade.
			return true;
		}

		// Apply (idempotent) dbDelta for the declared schema.
		try {
			self::apply_schema( $addon_id, $addon->get_schema() );
		} catch ( \Throwable $e ) {
			AddonMigrationErrors::record( $addon_id, 'schema', $target, $e->getMessage() );
			return false;
		}

		// Run each pending migrate_to in order. Persist after each success
		// so a mid-sequence failure resumes from the next step.
		for ( $v = $stored + 1; $v <= $target; $v++ ) {
			/** @hook perflocale/addon/before_migrate Fires before each addon migration step. */
			do_action( 'perflocale/addon/before_migrate', $addon, $stored, $v );

			try {
				$ok = $addon->migrate_to( $v );
			} catch ( \Throwable $e ) {
				AddonMigrationErrors::record( $addon_id, 'migrate', $v, $e->getMessage() );
				/** @hook perflocale/addon/migration_failed */
				do_action( 'perflocale/addon/migration_failed', $addon, $v, $e );
				return false;
			}

			if ( false === $ok ) {
				AddonMigrationErrors::record(
					$addon_id,
					'migrate',
					$v,
					'migrate_to(' . $v . ') returned false'
				);
				/** @hook perflocale/addon/migration_failed */
				do_action(
					'perflocale/addon/migration_failed',
					$addon,
					$v,
					new \RuntimeException( 'migrate_to returned false' )
				);
				return false;
			}

			self::set_stored_version( $addon_id, $v );
			$stored = $v;

			/** @hook perflocale/addon/migrated Fires after each successful addon migration step. */
			do_action( 'perflocale/addon/migrated', $addon, $v );
		}

		return true;
	}

	/**
	 * Apply the schema via dbDelta - idempotent on re-run.
	 *
	 * @param string                $addon_id Addon identifier.
	 * @param array<string, string> $schema Short-name → CREATE TABLE body.
	 * @return void
	 * @throws \InvalidArgumentException When a short-name is invalid.
	 */
	public static function apply_schema( string $addon_id, array $schema ): void {
		if ( [] === $schema ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		foreach ( $schema as $short_name => $body ) {
			if ( ! is_string( $short_name ) || 1 !== preg_match( self::SHORT_NAME_PATTERN, $short_name ) ) {
				// esc_html'd so programmer-controlled short_name can't
				// inject HTML if wp_die() ever renders the message.
				throw new \InvalidArgumentException(
					esc_html( "Invalid addon table short name '{$short_name}' - must match " . self::SHORT_NAME_PATTERN )
				);
			}

			if ( ! is_string( $body ) || '' === trim( $body ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Empty schema body for table '{$short_name}'" )
				);
			}

			// Sanitize the table identifier before interpolation: prepare()
			// cannot bind identifiers, so the name is stripped to a bare
			// [A-Za-z0-9_] token (lossless for our prefixed addon tables).
			$full_name = \PerfLocale\Database\Schema::sanitize_table( self::table_name( $addon_id, $short_name ) );
			$sql       = "CREATE TABLE {$full_name} (\n" . trim( $body ) . "\n) {$charset_collate};";

			dbDelta( $sql );
		}
	}

	/**
	 * Compute the full (prefixed) table name for an addon's short name.
	 *
	 * Returns the SANITIZED identifier so every caller agrees on the real
	 * table name. CREATE TABLE already runs the name through sanitize_table()
	 * (prepare() cannot bind identifiers), which strips characters like the
	 * hyphen in addon ids such as "visual-editor". Without sanitizing here too,
	 * DROP/count/exists would look for the un-stripped name and never match the
	 * table that was actually created — leaking orphan tables on uninstall.
	 *
	 * @param string $addon_id Addon identifier.
	 * @param string $short_name Short table name.
	 * @return string
	 */
	public static function table_name( string $addon_id, string $short_name ): string {
		global $wpdb;
		return \PerfLocale\Database\Schema::sanitize_table( $wpdb->prefix . 'perflocale_addon_' . $addon_id . '_' . $short_name );
	}

	/**
	 * Compute the prefix used by an addon's tables (for LIKE matching).
	 *
	 * @param string $addon_id Addon identifier.
	 * @return string
	 */
	public static function addon_table_prefix( string $addon_id ): string {
		global $wpdb;

		// Sanitize identically to table_name()/plan() so the prefix matches the
		// name the table was actually CREATEd under. A hyphenated addon id (e.g.
		// 'cf-7') sanitizes to 'cf7', so an unsanitized prefix would fail the
		// uninstall namespace check and leak the table.
		return \PerfLocale\Database\Schema::sanitize_table( $wpdb->prefix . 'perflocale_addon_' . $addon_id . '_' );
	}

	/**
	 * Get stored version for an addon (0 if never migrated).
	 *
	 * @param string $addon_id Addon identifier.
	 * @return int
	 */
	public static function get_stored_version( string $addon_id ): int {
		$versions = self::get_stored_versions();
		return (int) ( $versions[ $addon_id ] ?? 0 );
	}

	/**
	 * Get the full versions map.
	 *
	 * @return array<string, int>
	 */
	public static function get_stored_versions(): array {
		$raw = get_option( self::VERSIONS_OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Persist a single addon's stored version.
	 *
	 * @param string $addon_id Addon identifier.
	 * @param int    $version Version to store.
	 * @return void
	 */
	public static function set_stored_version( string $addon_id, int $version ): void {
		$versions              = self::get_stored_versions();
		$versions[ $addon_id ] = $version;
		update_option( self::VERSIONS_OPTION, $versions, false );
	}

	/**
	 * Forget an addon's stored version entirely (used by uninstall).
	 *
	 * @param string $addon_id Addon identifier.
	 * @return void
	 */
	public static function forget( string $addon_id ): void {
		$versions = self::get_stored_versions();
		if ( ! isset( $versions[ $addon_id ] ) ) {
			return;
		}
		unset( $versions[ $addon_id ] );
		if ( [] === $versions ) {
			delete_option( self::VERSIONS_OPTION );
		} else {
			update_option( self::VERSIONS_OPTION, $versions, false );
		}
	}

	/**
	 * Validate an addon id against ADDON_ID_PATTERN.
	 *
	 * @param string $addon_id Addon identifier.
	 * @return bool
	 */
	public static function validate_addon_id( string $addon_id ): bool {
		return 1 === preg_match( self::ADDON_ID_PATTERN, $addon_id );
	}
}
