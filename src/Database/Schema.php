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
 *
 * Uses dbDelta() for idempotent table creation and updates.
 * All tables use the WordPress table prefix + 'perflocale_'.
 */
final class Schema {

	/**
	 * Object-cache group for the schema-existence verdict. Matches
	 * {@see \PerfLocale\Cache\CacheManager}'s persistent group so it lives
	 * in the same Redis/Memcached namespace (and is NOT registered
	 * non-persistent, so the verdict survives across requests).
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
	 * Create or update all plugin tables.
	 *
	 * Safe to call multiple times - dbDelta() is idempotent.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		SchemaWrites::create_tables();
	}

	/**
	 * Drop all plugin tables.
	 *
	 * Used during uninstall only.
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
	 * MySQL and MariaDB report error 1062 as "Duplicate entry ... for key";
	 * the SQLite Database Integration passes SQLite's "UNIQUE constraint
	 * failed: table.column" through. Only those two texts count: SQLSTATE
	 * 23000 alone also covers NOT NULL and other constraint failures, which
	 * stay real errors.
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
	 * Both wpdb and the SQLite Database Integration append every failed query
	 * to the global $EZSQL_ERROR, also with errors suppressed. Take it when a
	 * transaction starts and pass it to transaction_lost().
	 *
	 * @phpstan-impure
	 * @return int
	 */
	public static function query_error_count(): int {
		return SchemaWrites::query_error_count();
	}

	/**
	 * Whether a transaction the caller opened can no longer commit as a whole,
	 * so the caller sends no other statement and rolls back.
	 *
	 * On every engine that is when one of the caller's own statements failed
	 * ($own_failed). It is also a query that failed since $errors was taken
	 * (query_error_count()), one run by another plugin's hook included, when
	 * that failure ended the transaction on the shared connection:
	 * - on the SQLite Database Integration, any failed statement: it rolls the
	 *   whole transaction back, and the statements after it commit one by one;
	 * - on MySQL and MariaDB, a deadlock (error 1213), which rolls the whole
	 *   transaction back, and a lock wait timeout (1205) only while
	 *   innodb_rollback_on_timeout is ON (by default a timeout rolls back the
	 *   one statement). Any other failed statement of another plugin leaves
	 *   the transaction open with every earlier write, so it does not count.
	 * wpdb records the error text, not its number, so the texts are matched.
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
	 * The `updated_at` assignment that `ON UPDATE CURRENT_TIMESTAMP` makes on
	 * MySQL and MariaDB, for a site on the SQLite Database Integration, which
	 * ignores that clause (translation_links, content_hashes and
	 * string_translations declare it).
	 *
	 * Returns '' on MySQL and MariaDB, so their statements stay as they are.
	 * On SQLite it returns `, updated_at = CASE WHEN <every assigned column
	 * keeps its value> THEN updated_at ELSE NOW() END`, to append to the SET or
	 * ON DUPLICATE KEY UPDATE list: the write time moves only when a value
	 * changes, as the clause does. SQLite reads every assignment against the
	 * row as it was, so the fragment may follow the other assignments. Text
	 * compares as bytes (the integration compares strings case-insensitively).
	 *
	 * Each entry of $columns names an assigned column: its expression, the
	 * expression it is set to (`VALUES(col)`, a SQL literal, or a placeholder
	 * the caller binds once more), and its kind ('text_null' for a nullable
	 * text column).
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
	 * `wpdb::prepare()` binds VALUES with %d/%s placeholders - it cannot bind
	 * SQL identifiers (table/column names). So a table name that genuinely
	 * has to be interpolated - DDL such as CREATE/DROP/TRUNCATE, or a dynamic
	 * FROM - is hardened here instead: every character outside the set a real
	 * MySQL identifier can contain ([A-Za-z0-9_]) is stripped. This is
	 * lossless for our own prefixed table names (WordPress already constrains
	 * `$table_prefix` to that set) and neutralises any unexpected
	 * metacharacter defensively, regardless of how the name was derived.
	 *
	 * @param string $table Full table name.
	 * @return string Sanitized identifier (safe to wrap in backticks).
	 */
	public static function sanitize_table( string $table ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_]/', '', $table );
	}

	/**
	 * Check if PerfLocale's database tables actually exist.
	 *
	 * Two-tier cache:
	 *  - a per-request static (one resolution per request); and
	 *  - the persistent object cache, holding only the positive
	 *    ("installed") verdict.
	 *
	 * On a site with a persistent object cache (Redis / Memcached) the
	 * recurring SHOW TABLES collapses to an in-memory hit. Without a
	 * persistent backend wp_cache is per-request only, so this degrades to
	 * exactly one SHOW TABLES per request - no regression. That same
	 * fall-through is why the WordPress Plugin Checker sandbox (no
	 * persistent cache + a `wp_pc_` table prefix while sharing the main
	 * `wp_options`) still runs the authoritative query and correctly sees
	 * the table as absent, instead of trusting a stale `perflocale_db_version`
	 * option and emitting "Table doesn't exist" errors.
	 *
	 * Only the positive verdict is cached, so a freshly-activated site is
	 * recognised on the very next request (no negative TTL to wait out),
	 * and {@see flush_tables_exist_cache()} clears it whenever the schema is
	 * (re)created or dropped. A table dropped out from under a cached
	 * verdict is bounded by the 1-hour TTL and surfaced by the Site Health
	 * "tables present" check.
	 *
	 * @return bool True if the languages table exists.
	 */
	/**
	 * Every table create_tables() is responsible for.
	 *
	 * Shared by the activation post-condition check and the migration
	 * post-condition check so the two lists cannot drift apart.
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
	 * dbDelta swallows DDL errors and its return value describes intent, not
	 * outcome, so the only honest post-condition is to ask the server what
	 * actually exists. Used to avoid stamping a schema version the database
	 * never reached.
	 *
	 * @return string[] Fully-qualified names of the missing tables.
	 */
	public static function missing_tables(): array {
		return SchemaWrites::missing_tables();
	}

	public static function tables_exist(): bool {
		// Keyed by blog: tables are per-blog ({$prefix}perflocale_*), so a
		// flat static would carry blog A's verdict into blog B after
		// switch_to_blog().
		/** @var array<int, bool> $exists */
		static $exists = [];

		$blog = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;

		if ( isset( $exists[ $blog ] ) ) {
			return $exists[ $blog ];
		}

		// Persistent verdict lives in an autoloaded option: on sites WITHOUT
		// an external object cache the wp_cache layer is empty every request,
		// which made this method run SHOW TABLES per request - a measurable
		// cost on servers with large table catalogs (thousands of tables make
		// the catalog scan multi-ms). The option rides alloptions, so a warm
		// verdict costs an array lookup. Only the positive verdict is stored;
		// flush_tables_exist_cache() deletes it on schema create/drop.
		if ( get_option( self::TABLES_EXIST_OPTION ) ) {
			$exists[ $blog ] = true;
			return true;
		}

		// Use the $found out-param rather than comparing the value: a
		// persistent backend (e.g. Redis) can round-trip the stored 1 back
		// as the string "1", so a strict `=== 1` would always miss. We only
		// ever store the positive verdict, so a hit means "installed".
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
	 * Called wherever the plugin's tables are created or dropped so a stale
	 * "installed" verdict can't outlive the schema. The per-request static
	 * in tables_exist() is left as-is: it is only ever set after a fresh
	 * resolution within the same request, so it can't disagree with a change
	 * made earlier in that request.
	 *
	 * @return void
	 */
	public static function flush_tables_exist_cache(): void {
		delete_option( self::TABLES_EXIST_OPTION );
		wp_cache_delete( self::TABLES_EXIST_KEY, self::CACHE_GROUP );
	}
}
