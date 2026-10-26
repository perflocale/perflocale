<?php
/**
 * Addon schema writes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates addon tables; loaded only while an addon migration is pending.
 */
final class AddonSchemaWrites {

	/**
	 * Validate every declared table, then apply them via dbDelta (idempotent on re-run).
	 *
	 * @param string                   $addon_id Addon identifier.
	 * @param array<int|string, mixed> $schema   Short name => CREATE TABLE body.
	 * @return void
	 * @throws \InvalidArgumentException When a short name, body or full name is invalid; no table is created then.
	 */
	public static function apply( string $addon_id, array $schema ): void {
		if ( [] === $schema ) {
			return;
		}

		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// SQLite has no identifier limit, so a long name that works there is never refused.
		$max    = defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) ? PHP_INT_MAX : 64;
		$tables = [];

		foreach ( $schema as $short_name => $body ) {
			if ( ! is_string( $short_name ) || 1 !== preg_match( AddonSchemaManager::SHORT_NAME_PATTERN, $short_name ) ) {
				// Escaped in case wp_die() renders the message.
				throw new \InvalidArgumentException(
					esc_html( "Invalid addon table short name '{$short_name}' - must match " . AddonSchemaManager::SHORT_NAME_PATTERN )
				);
			}

			if ( ! is_string( $body ) || '' === trim( $body ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Empty schema body for table '{$short_name}'" )
				);
			}

			// The identifier cannot be bound by prepare(); table_name() strips it to [A-Za-z0-9_].
			$full_name = \PerfLocale\Database\Schema::sanitize_table( AddonSchemaManager::table_name( $addon_id, $short_name ) );
			$length    = strlen( $full_name );

			if ( $length > $max ) {
				throw new \InvalidArgumentException(
					esc_html( "Addon table name {$full_name} is {$length} characters; the database allows 64. The addon needs a shorter id or table short name." )
				);
			}

			$tables[ $full_name ] = trim( $body );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( $tables as $full_name => $body ) {
			dbDelta( "CREATE TABLE {$full_name} (\n{$body}\n) {$charset_collate};" );
		}
	}
}
