<?php
/**
 * Database schema: table installation and the write-transaction helpers.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, drops and checks the plugin tables, and the transaction and
 * SQLite write helpers of {@see Schema}, which forwards to this class.
 */
final class SchemaWrites {

	/**
	 * The server's innodb_rollback_on_timeout, once read (rollback_on_timeout()).
	 *
	 * @var bool|null
	 */
	private static ?bool $rollback_on_timeout = null;

	/**
	 * Create or update all plugin tables (dbDelta(), idempotent).
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'perflocale_';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Table 1: Languages.
		$sql_languages = "CREATE TABLE {$prefix}languages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(10) NOT NULL,
			locale VARCHAR(20) NOT NULL,
			name VARCHAR(100) NOT NULL,
			native_name VARCHAR(100) NOT NULL,
			flag VARCHAR(10) NOT NULL DEFAULT '',
			is_default TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			is_active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
			sort_order INT(11) UNSIGNED NOT NULL DEFAULT 0,
			date_format VARCHAR(50) NOT NULL DEFAULT '',
			time_format VARCHAR(50) NOT NULL DEFAULT '',
			text_direction VARCHAR(3) NOT NULL DEFAULT 'ltr',
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY locale (locale),
			KEY is_active_sort (is_active, sort_order),
			KEY is_default (is_default)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 2: Translation groups.
		$sql_groups = "CREATE TABLE {$prefix}translation_groups (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY type (type)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 3: Translation links. object_id is polymorphic (post, term, string ids share it), so `object_lang` leads with type.
		// `object_lookup` serves queries that bind only object_id: not a prefix of `object_lang`, never "dedupe" it.
		// KEY status and KEY group_object are left out on purpose: docs-ai/04-do-not-fix.md, 2.5.
		$sql_links = "CREATE TABLE {$prefix}translation_links (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT(20) UNSIGNED NOT NULL,
			object_id BIGINT(20) UNSIGNED NOT NULL,
			language_id BIGINT(20) UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'empty',
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY group_lang (group_id, language_id),
			UNIQUE KEY object_lang (type, object_id, language_id),
			KEY object_lookup (object_id, language_id),
			KEY language_id (language_id),
			KEY group_status (group_id, status),
			KEY updated_at (updated_at)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 4: Translatable strings. context VARCHAR(191) fits the 767-byte index limit of older MySQL in utf8mb4.
		// original_hash is latin1, never ascii: an ascii column makes wpdb treat the table as ascii and reject multibyte
		// queries (docs-ai/11-code-rationale.md, "SchemaWrites: latin1 hash columns"). last_seen_at drives the string GC.
		$sql_strings = "CREATE TABLE {$prefix}strings (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			domain VARCHAR(100) NOT NULL DEFAULT 'default',
			context VARCHAR(191) NOT NULL DEFAULT '',
			original TEXT NOT NULL,
			original_hash CHAR(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
			group_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			file_path VARCHAR(500) NOT NULL DEFAULT '',
			line_number INT(11) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY original_hash (original_hash),
			KEY domain_id (domain, id),
			KEY domain_context (domain, context),
			KEY context (context),
			KEY group_id (group_id),
			KEY last_seen_at (last_seen_at)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 6: Slug translations. One slug per object and language; slugs unique per language, type and subtype
		// (post type or taxonomy), so separate URL namespaces may share a slug.
		// slug(191) is the 767-byte per-column index limit of older MySQL, as core's post_name(191): docs-ai/04-do-not-fix.md, 2.2.
		$sql_slugs = "CREATE TABLE {$prefix}slug_translations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			object_type VARCHAR(20) NOT NULL,
			object_subtype VARCHAR(40) NOT NULL DEFAULT '',
			object_id BIGINT(20) UNSIGNED NOT NULL,
			language_id BIGINT(20) UNSIGNED NOT NULL,
			slug VARCHAR(200) NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY object_slug (object_type, object_id, language_id),
			UNIQUE KEY slug_lookup (language_id, object_type, object_subtype, slug(191))
		) ENGINE=InnoDB {$charset_collate};";



		// Table 9: Content hashes of source content, for change detection. content_hash is latin1, never ascii (see table 4).
		$sql_hashes = "CREATE TABLE {$prefix}content_hashes (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			object_id BIGINT(20) UNSIGNED NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT 'post',
			content_hash CHAR(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY object_type_id (object_type, object_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 11: String translations, one row per string and language.
		// extra_forms: JSON plural forms 2..N (msgstr[2+]) on the `plural`-context row of 3-6 form languages; NULL otherwise.
		$sql_string_translations = "CREATE TABLE {$prefix}string_translations (
			string_id BIGINT(20) UNSIGNED NOT NULL,
			language_id BIGINT(20) UNSIGNED NOT NULL,
			translation LONGTEXT NOT NULL,
			extra_forms LONGTEXT NULL DEFAULT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (string_id, language_id),
			KEY language_id (language_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 11: Background jobs. `version` is the compare-and-set column every mutating UPDATE checks and bumps.
		// updated_at has no ON UPDATE CURRENT_TIMESTAMP on purpose: that is the server's clock, and readers compare
		// with PHP UTC; JobState writes it on every change (docs-ai/11-code-rationale.md, "SchemaWrites: jobs updated_at").
		$sql_jobs = "CREATE TABLE {$prefix}jobs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			type VARCHAR(64) NOT NULL,
			hook VARCHAR(191) NOT NULL,
			engine VARCHAR(20) NOT NULL DEFAULT 'wp_cron',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			progress TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			total INT(11) UNSIGNED NOT NULL DEFAULT 0,
			processed INT(11) UNSIGNED NOT NULL DEFAULT 0,
			attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			version INT(11) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at DATETIME NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			args LONGTEXT NOT NULL,
			result LONGTEXT NOT NULL,
			error VARCHAR(2000) NOT NULL DEFAULT '',
			log LONGTEXT NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uuid (uuid),
			KEY status_updated (status, updated_at),
			KEY type_status (type, status),
			KEY created_by (created_by),
			KEY updated_at (updated_at)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 12: Migration source map: a source-plugin id (e.g. WPML "<trid>|<element_type>") => its translation group,
		// read inside create_group()'s transaction so a retried import never makes a second group for one source.
		// UNIQUE type_key makes the lookup-or-create atomic (ON DUPLICATE KEY UPDATE).
		$sql_migration_map = "CREATE TABLE {$prefix}migration_source_map (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			migration_type VARCHAR(20) NOT NULL,
			source_key VARCHAR(191) NOT NULL,
			group_id BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY type_key (migration_type, source_key),
			KEY group_id (group_id)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_languages );
		dbDelta( $sql_groups );
		dbDelta( $sql_links );
		dbDelta( $sql_strings );
		dbDelta( $sql_slugs );
		dbDelta( $sql_hashes );
		dbDelta( $sql_string_translations );
		dbDelta( $sql_jobs );
		dbDelta( $sql_migration_map );

		// Clear the cached tables_exist() verdict (activation and upgrade both end here).
		Schema::flush_tables_exist_cache();
	}

	/**
	 * Drop all plugin tables (uninstall only).
	 *
	 * @param array<int, string> $keep Full table names to leave (an addon that kept its data).
	 * @return void
	 */
	public static function drop_tables( array $keep = [] ): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'perflocale_';

		// Every existing `<prefix>perflocale_*` table, not a fixed list: tables gone from the schema are dropped too.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (array) $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);

		foreach ( $existing as $full_name ) {
			// SHOW TABLES output, still sanitized and prefix-checked before the DROP.
			$safe = Schema::sanitize_table( (string) $full_name );
			if ( $safe === '' || 0 !== strpos( $safe, $prefix ) || in_array( $safe, $keep, true ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Teardown DDL: table name is an identifier (sanitized + prefix-checked above) and is bound with %i; DROP has no cache to invalidate.
			$wpdb->query(
				$wpdb->prepare(
					'DROP TABLE IF EXISTS %i',
					$safe
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		// Drop the cached "installed" verdict so nothing queries the missing tables.
		Schema::flush_tables_exist_cache();
	}

	/**
	 * Whether a database error is a duplicate-key (unique index) violation.
	 *
	 * Only MySQL's "Duplicate entry" and SQLite's "UNIQUE constraint failed": SQLSTATE 23000 also covers NOT NULL failures.
	 *
	 * @param string $error The database error text (wpdb::$last_error).
	 * @return bool
	 */
	public static function is_duplicate_key_error( string $error ): bool {
		return '' !== $error && ( false !== stripos( $error, 'Duplicate entry' ) || false !== stripos( $error, 'UNIQUE constraint failed' ) );
	}

	/**
	 * The number of failed queries this request has recorded ($EZSQL_ERROR, kept even with errors suppressed).
	 *
	 * Take it when a transaction starts and pass it to transaction_lost().
	 *
	 * @phpstan-impure
	 * @return int
	 */
	public static function query_error_count(): int {
		return isset( $GLOBALS['EZSQL_ERROR'] ) && is_array( $GLOBALS['EZSQL_ERROR'] ) ? count( $GLOBALS['EZSQL_ERROR'] ) : 0;
	}

	/**
	 * Whether the caller's transaction cannot commit as a whole, so it sends nothing more and rolls back.
	 *
	 * True when its own statement failed, or a failure since $errors (any plugin's) ended the transaction: any on
	 * SQLite, a deadlock or (innodb_rollback_on_timeout ON) a lock wait timeout on MySQL; see Schema::transaction_lost().
	 *
	 * @param int  $errors     query_error_count() when the transaction started.
	 * @param bool $own_failed Whether one of the caller's own statements failed.
	 * @phpstan-impure
	 * @return bool
	 */
	public static function transaction_lost( int $errors, bool $own_failed = false ): bool {
		if ( $own_failed ) {
			return true;
		}

		if ( self::query_error_count() <= $errors ) {
			return false;
		}

		if ( defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) ) {
			return true;
		}

		foreach ( array_slice( (array) $GLOBALS['EZSQL_ERROR'], max( 0, $errors ) ) as $entry ) {
			$text = is_array( $entry ) && isset( $entry['error_str'] ) && is_string( $entry['error_str'] ) ? $entry['error_str'] : '';

			if ( false !== stripos( $text, 'Deadlock found when trying to get lock' ) ) {
				return true;
			}

			if ( false !== stripos( $text, 'Lock wait timeout exceeded' ) && self::rollback_on_timeout() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the server rolls back the whole transaction on a lock wait timeout (innodb_rollback_on_timeout).
	 *
	 * Read once per request, only after a timeout; the variable is read-only at runtime.
	 *
	 * @return bool
	 */
	private static function rollback_on_timeout(): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( null === self::$rollback_on_timeout && $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A server variable, read once per request.
			$value                     = $wpdb->get_var( 'SELECT @@innodb_rollback_on_timeout' );
			self::$rollback_on_timeout = is_string( $value ) && in_array( strtoupper( $value ), [ '1', 'ON' ], true );
		}

		return true === self::$rollback_on_timeout;
	}

	/**
	 * The `updated_at` assignment `ON UPDATE CURRENT_TIMESTAMP` makes, for SQLite, which ignores that clause.
	 *
	 * '' on MySQL; on SQLite a CASE appended to the SET list that moves the time only when a value changes.
	 * Each $columns entry: the column, the expression it is set to, its kind ('text_null' for a nullable text column).
	 *
	 * @param array<int, array{0: literal-string, 1: literal-string, 2: 'int'|'text'|'text_null'}> $columns The assigned columns.
	 * @param literal-string                                                                       $alias   Table alias with its dot, for a multi-table UPDATE (e.g. 't.').
	 * @return literal-string
	 */
	public static function touch_write_time( array $columns, string $alias = '' ): string {
		if ( $columns === [] || ! defined( 'DB_ENGINE' ) || 'sqlite' !== constant( 'DB_ENGINE' ) ) {
			return '';
		}

		$same = '';

		foreach ( $columns as [ $old, $new, $kind ] ) {
			$equal = 'int' === $kind ? $old . ' = ' . $new : 'CAST(' . $old . ' AS BINARY) = CAST(' . $new . ' AS BINARY)';
			$equal = 'text_null' === $kind ? '(' . $equal . ' OR (' . $old . ' IS NULL AND ' . $new . ' IS NULL))' : $equal;
			$same  = '' === $same ? $equal : $same . ' AND ' . $equal;
		}

		$column = $alias . 'updated_at';

		return ', ' . $column . ' = CASE WHEN ' . $same . ' THEN ' . $column . ' ELSE NOW() END';
	}

	/**
	 * Tables that create_tables() was supposed to produce but did not.
	 *
	 * Asks the server: dbDelta swallows DDL errors, and a version must not be stamped for a schema never reached.
	 *
	 * @return string[] Fully-qualified names of the missing tables.
	 */
	public static function missing_tables(): array {
		global $wpdb;

		// One catalog scan over the shared prefix, compared in PHP: a per-table query is slow on a large catalog.
		$prefix = $wpdb->prefix . 'perflocale_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema post-condition; must ask the server, and a cached answer would defeat the purpose.
		$found = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);

		// Case-insensitive: lower_case_table_names=1 folds names, and the prefix may hold capitals.
		$have = [];

		foreach ( (array) $found as $name ) {
			$have[ strtolower( (string) $name ) ] = true;
		}

		$missing = [];

		foreach ( Schema::REQUIRED_TABLES as $table_name ) {
			$full_name = Schema::table( $table_name );

			if ( ! isset( $have[ strtolower( $full_name ) ] ) ) {
				$missing[] = $full_name;
			}
		}

		return $missing;
	}
}
