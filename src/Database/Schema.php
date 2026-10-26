<?php
/**
 * Database schema definition.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines and creates all custom database tables.
 */
final class Schema {

	/**
	 * Object-cache group for the tables_exist verdict: CacheManager's persistent group, so it survives across requests.
	 */
	private const CACHE_GROUP = 'perflocale';

	/**
	 * Object-cache key for the "tables installed" verdict.
	 */
	private const TABLES_EXIST_KEY = 'tables_exist';

	/**
	 * Autoloaded per-blog option holding the positive tables_exist verdict -
	 * the persistent layer for sites without an external object cache.
	 */
	private const TABLES_EXIST_OPTION = 'perflocale_tables_exist';

	/**
	 * Create or update all plugin tables (idempotent).
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		SchemaWrites::create_tables();
	}

	/**
	 * Drop all plugin tables (uninstall only).
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		SchemaWrites::drop_tables();
	}

	/**
	 * Get the full table name with prefix.
	 *
	 * @param string $table Short table name (e.g. 'languages').
	 * @return string Full table name with WP prefix.
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . 'perflocale_' . $table;
	}

	/**
	 * Whether a database error is a duplicate-key (unique index) violation.
	 *
	 * Matches MySQL/MariaDB error 1062 and SQLite's "UNIQUE constraint failed"; SQLSTATE 23000 alone also covers
	 * NOT NULL and other constraint failures, which stay real errors.
	 *
	 * @param string $error The database error text (wpdb::$last_error).
	 * @return bool
	 */
	public static function is_duplicate_key_error( string $error ): bool {
		return SchemaWrites::is_duplicate_key_error( $error );
	}

	/**
	 * The number of failed queries this request has recorded.
	 *
	 * Both wpdb and the SQLite Database Integration append every failed query to $EZSQL_ERROR, even when errors are
	 * suppressed. Take it when a transaction starts and pass it to transaction_lost().
	 *
	 * @phpstan-impure
	 * @return int
	 */
	public static function query_error_count(): int {
		return SchemaWrites::query_error_count();
	}

	/**
	 * Whether a transaction the caller opened cannot commit as a whole, so the caller sends nothing more and rolls back.
	 *
	 * True when a caller statement failed, or a query since $errors (any plugin's) ended the transaction: any failure on
	 * SQLite; on MySQL/MariaDB a deadlock (1213), or a lock wait timeout (1205) with innodb_rollback_on_timeout ON.
	 * See docs-ai/11-code-rationale.md, "Schema: transaction_lost".
	 *
	 * @param int  $errors     query_error_count() when the transaction started.
	 * @param bool $own_failed Whether one of the caller's own statements failed.
	 * @phpstan-impure
	 * @return bool
	 */
	public static function transaction_lost( int $errors, bool $own_failed = false ): bool {
		return SchemaWrites::transaction_lost( $errors, $own_failed );
	}

	/**
	 * The `updated_at` assignment that `ON UPDATE CURRENT_TIMESTAMP` makes, for SQLite, which ignores that clause.
	 *
	 * '' on MySQL/MariaDB; on SQLite a `, updated_at = CASE ... END` fragment for the SET or ON DUPLICATE KEY UPDATE list.
	 * Each $columns entry: the column, what it is set to (`VALUES(col)`, a literal, or a placeholder the caller binds once
	 * more) and its kind. See docs-ai/11-code-rationale.md, "Schema: touch_write_time".
	 *
	 * @param array<int, array{0: literal-string, 1: literal-string, 2: 'int'|'text'|'text_null'}> $columns The assigned columns.
	 * @param literal-string                                                                       $alias   Table alias with its dot, for a multi-table UPDATE (e.g. 't.').
	 * @return literal-string
	 */
	public static function touch_write_time( array $columns, string $alias = '' ): string {
		return SchemaWrites::touch_write_time( $columns, $alias );
	}

	/**
	 * Make a table identifier safe to interpolate into a query string.
	 *
	 * Identifiers cannot be bound by prepare(), so every character outside [A-Za-z0-9_] is stripped (lossless for real prefixes).
	 *
	 * @param string $table Full table name.
	 * @return string Sanitized identifier (safe to wrap in backticks).
	 */
	public static function sanitize_table( string $table ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_]/', '', $table );
	}

	/**
	 * Every table create_tables() is responsible for; shared by the activation and migration post-condition checks.
	 *
	 * @var string[]
	 */
	public const REQUIRED_TABLES = [
		'languages',
		'translation_groups',
		'translation_links',
		'strings',
		'string_translations',
		'slug_translations',
		'content_hashes',
		'jobs',
		'migration_source_map',
	];

	/**
	 * Tables that create_tables() was supposed to produce but did not.
	 *
	 * dbDelta swallows DDL errors and reports intent, not outcome; this stops stamping a version the database never reached.
	 *
	 * @return string[] Fully-qualified names of the missing tables.
	 */
	public static function missing_tables(): array {
		return SchemaWrites::missing_tables();
	}

	/**
	 * Check if PerfLocale's database tables actually exist.
	 *
	 * Only the positive verdict is cached (static, autoloaded option, object cache), so an activation is seen at once;
	 * flush_tables_exist_cache() clears it on create or drop. See docs-ai/11-code-rationale.md, "Schema: tables_exist".
	 *
	 * @return bool True if the languages table exists.
	 */
	public static function tables_exist(): bool {
		// Keyed by blog: a flat static would carry blog A's verdict into blog B after switch_to_blog().
		/** @var array<int, bool> $exists */
		static $exists = [];

		$blog = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;

		if ( isset( $exists[ $blog ] ) ) {
			return $exists[ $blog ];
		}

		// Autoloaded option: without an external object cache, SHOW TABLES would otherwise run on every request.
		if ( get_option( self::TABLES_EXIST_OPTION ) ) {
			$exists[ $blog ] = true;
			return true;
		}

		// Use $found, not the value: a persistent backend can return the stored 1 as the string "1".
		$found = false;
		wp_cache_get( self::TABLES_EXIST_KEY, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			$exists[ $blog ] = true;
			return true;
		}

		global $wpdb;

		$table = self::table( 'languages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Positive result cached in an autoloaded option + the object cache + a per-request static; busted via flush_tables_exist_cache() on create/drop.
		$verdict = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $verdict ) {
			update_option( self::TABLES_EXIST_OPTION, 1, true );
			wp_cache_set( self::TABLES_EXIST_KEY, 1, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		$exists[ $blog ] = $verdict;

		return $verdict;
	}

	/**
	 * Invalidate the cross-request {@see tables_exist()} verdict.
	 *
	 * The per-request static is left alone: it is only set by a resolution earlier in the same request.
	 *
	 * @return void
	 */
	public static function flush_tables_exist_cache(): void {
		delete_option( self::TABLES_EXIST_OPTION );
		wp_cache_delete( self::TABLES_EXIST_KEY, self::CACHE_GROUP );
	}
}
