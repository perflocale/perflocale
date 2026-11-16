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
	 * @param (callable(): bool)|null $go_on Asked before each table; false stops (the upgrade runner lost its lock).
	 * @return void
	 */
	public static function create_tables( ?callable $go_on = null ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( self::definitions() as $sql ) {
			if ( null !== $go_on && ! $go_on() ) {
				break;
			}

			dbDelta( $sql );
		}

		// Clear the cached tables_exist() verdict (activation and upgrade both end here).
		Schema::flush_tables_exist_cache();
	}

	/**
	 * The CREATE TABLE statement of every plugin table, by short name: what dbDelta applies and missing_parts() checks.
	 * Column types stay lowercase: dbDelta before WP 6.9 compares them case-sensitively with DESCRIBE and ALTERs on a mismatch.
	 *
	 * @return array<string, string>
	 */
	public static function definitions(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$charset_collate = is_string( $charset_collate ) ? $charset_collate : '';
		$prefix          = $wpdb->prefix . 'perflocale_';

		// Table 1: Languages.
		$sql_languages = "CREATE TABLE {$prefix}languages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(10) NOT NULL,
			locale varchar(20) NOT NULL,
			name varchar(100) NOT NULL,
			native_name varchar(100) NOT NULL,
			flag varchar(10) NOT NULL DEFAULT '',
			is_default tinyint(1) unsigned NOT NULL DEFAULT 0,
			is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
			sort_order int(11) unsigned NOT NULL DEFAULT 0,
			date_format varchar(50) NOT NULL DEFAULT '',
			time_format varchar(50) NOT NULL DEFAULT '',
			text_direction varchar(3) NOT NULL DEFAULT 'ltr',
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY locale (locale),
			KEY is_active_sort (is_active, sort_order),
			KEY is_default (is_default)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 2: Translation groups.
		$sql_groups = "CREATE TABLE {$prefix}translation_groups (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY type (type)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 3: Translation links. object_id is polymorphic (post, term, string ids share it), so `object_lang` leads with type.
		// `object_lookup` serves queries that bind only object_id: not a prefix of `object_lang`, never "dedupe" it.
		// KEY status and KEY group_object are left out on purpose: docs-ai/04-do-not-fix.md, 2.5.
		$sql_links = "CREATE TABLE {$prefix}translation_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			language_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'empty',
			source varchar(20) NOT NULL DEFAULT 'manual',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			domain varchar(100) NOT NULL DEFAULT 'default',
			context varchar(191) NOT NULL DEFAULT '',
			original text NOT NULL,
			original_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_path varchar(500) NOT NULL DEFAULT '',
			line_number int(11) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL,
			object_subtype varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL,
			language_id bigint(20) unsigned NOT NULL,
			slug varchar(200) NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY object_slug (object_type, object_id, language_id),
			UNIQUE KEY slug_lookup (language_id, object_type, object_subtype, slug(191))
		) ENGINE=InnoDB {$charset_collate};";



		// Table 9: Content hashes of source content, for change detection. content_hash is latin1, never ascii (see table 4).
		$sql_hashes = "CREATE TABLE {$prefix}content_hashes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			content_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY object_type_id (object_type, object_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 11: String translations, one row per string and language.
		// extra_forms: JSON plural forms 2..N (msgstr[2+]) on the `plural`-context row of 3-6 form languages; NULL otherwise.
		$sql_string_translations = "CREATE TABLE {$prefix}string_translations (
			string_id bigint(20) unsigned NOT NULL,
			language_id bigint(20) unsigned NOT NULL,
			translation longtext NOT NULL,
			extra_forms longtext NULL DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (string_id, language_id),
			KEY language_id (language_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 11: Background jobs. `version` is the compare-and-set column every mutating UPDATE checks and bumps.
		// updated_at has no ON UPDATE CURRENT_TIMESTAMP on purpose: that is the server's clock, and readers compare
		// with PHP UTC; JobState writes it on every change (docs-ai/11-code-rationale.md, "SchemaWrites: jobs updated_at").
		$sql_jobs = "CREATE TABLE {$prefix}jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			type varchar(64) NOT NULL,
			hook varchar(191) NOT NULL,
			engine varchar(20) NOT NULL DEFAULT 'wp_cron',
			status varchar(20) NOT NULL DEFAULT 'queued',
			progress tinyint(3) unsigned NOT NULL DEFAULT 0,
			total int(11) unsigned NOT NULL DEFAULT 0,
			processed int(11) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			version int(11) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at datetime NULL DEFAULT NULL,
			completed_at datetime NULL DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			args longtext NOT NULL,
			result longtext NOT NULL,
			error varchar(2000) NOT NULL DEFAULT '',
			log longtext NOT NULL,
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
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			migration_type varchar(20) NOT NULL,
			source_key varchar(191) NOT NULL,
			group_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY type_key (migration_type, source_key),
			KEY group_id (group_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 13: Automation changes, one row per write the automation policy decided (pending, applied, rejected,
		// superseded, reverted, refused). `payload` holds the proposal while pending, `previous` the replaced value for
		// undo. JSON columns are LONGTEXT: a summary lists every field key in scope. Datetimes are PHP UTC.
		// No KEY on status alone: pending reads add `decided_at IS NULL`, so status_decided serves them in id order.
		// status_decided_type: one type's pending rows in id order (the Strings review list). Not a stand-in for
		// status_decided (with no type the range is not in id order); status first, or SQLite (no statistics) reads it
		// for a type + status filter that KEY target or source_status serves.
		$sql_pending_changes = "CREATE TABLE {$prefix}pending_changes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			area varchar(32) NOT NULL,
			object_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			language_id bigint(20) unsigned NOT NULL,
			origin varchar(20) NOT NULL,
			trigger_name varchar(32) NOT NULL,
			job_id char(36) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor varchar(8) NOT NULL DEFAULT 'user',
			decision varchar(20) NOT NULL,
			reason varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			payload longtext NOT NULL,
			summary longtext NOT NULL,
			base_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			result_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			result_status varchar(20) NOT NULL DEFAULT '',
			result_modified datetime NULL DEFAULT NULL,
			previous longtext NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			decided_at datetime NULL DEFAULT NULL,
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY target (object_type, object_id, language_id, status),
			KEY source_status (source_id, status),
			KEY status_decided (status, decided_at),
			KEY status_decided_type (status, decided_at, object_type),
			KEY string_intent (object_type, result_status),
			KEY user_id (user_id),
			KEY decided_by (decided_by)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 14: Content ReSync bases, one row per source state some translation was synced to (shared by languages).
		// Snapshots are LONGBLOB so bytes round-trip whatever the charset (utf8mb3 would refuse 4-byte characters and
		// wpdb would drop the INSERT). A `live` row matching its newest revision keeps `revision_id` and empty text.
		// object_type: what the ids name ('post' only so far; term and string ids reuse post id numbers): last in both UNIQUE
		// keys so prefix reads stay indexed. KEY last_used_at ends with id and object_type: a type's retention read stays
		// covering and ordered; a key led by object_type misleads SQLite's planner.
		$sql_resync_base = "CREATE TABLE {$prefix}resync_base (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			source_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
			fields_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			origin varchar(20) NOT NULL DEFAULT 'live',
			revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title longblob NOT NULL,
			content longblob NOT NULL,
			excerpt longblob NOT NULL,
			fields longblob NOT NULL,
			created_at datetime NOT NULL,
			last_used_at datetime NOT NULL,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			PRIMARY KEY (id),
			UNIQUE KEY source_state (source_id, source_hash, fields_hash, object_type),
			KEY last_used_at (last_used_at, id, object_type)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 15: Content ReSync state, one row per translation post: its base, the previous base (undo), hashes for
		// the no-change check, field ownership, the block map and the write-ahead intent of a write in flight. A row
		// made only to hold an intent has base 0 and is never used as a state. object_type: as in resync_base.
		$sql_resync_state = "CREATE TABLE {$prefix}resync_state (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			translation_id bigint(20) unsigned NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			language_id bigint(20) unsigned NOT NULL,
			base_id bigint(20) unsigned NOT NULL DEFAULT 0,
			prev_base_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			fields_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			translation_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			translation_fields_hash char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT '',
			how varchar(20) NOT NULL DEFAULT '',
			base_quality varchar(20) NOT NULL DEFAULT '',
			complete tinyint(1) unsigned NOT NULL DEFAULT 0,
			fields_owned longtext NOT NULL,
			fields_unknown longtext NOT NULL,
			block_map longtext NOT NULL,
			intent longtext NOT NULL,
			intent_at datetime NULL DEFAULT NULL,
			synced_at datetime NOT NULL,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			PRIMARY KEY (id),
			UNIQUE KEY translation (translation_id, object_type),
			KEY source_language (source_id, language_id),
			KEY base_id (base_id),
			KEY prev_base_id (prev_base_id),
			KEY intent_at (intent_at)
		) ENGINE=InnoDB {$charset_collate};";

		return [
			'languages'            => $sql_languages,
			'translation_groups'   => $sql_groups,
			'translation_links'    => $sql_links,
			'strings'              => $sql_strings,
			'slug_translations'    => $sql_slugs,
			'content_hashes'       => $sql_hashes,
			'string_translations'  => $sql_string_translations,
			'jobs'                 => $sql_jobs,
			'migration_source_map' => $sql_migration_map,
			'pending_changes'      => $sql_pending_changes,
			'resync_base'          => $sql_resync_base,
			'resync_state'         => $sql_resync_state,
		];
	}

	/**
	 * Drop the named plugin tables (uninstall only): never a `perflocale_*` table nobody listed.
	 *
	 * @param array<int, string> $keep   Full table names to leave (an addon that kept its data).
	 * @param array<int, string> $tables Short names (`languages`); default this version's.
	 * @return void
	 */
	public static function drop_tables( array $keep = [], array $tables = Schema::REQUIRED_TABLES ): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'perflocale_';

		foreach ( $tables as $short ) {
			$safe = Schema::sanitize_table( $prefix . $short );

			if ( $safe !== $prefix . $short || in_array( $safe, $keep, true ) || SiteCleanup::is_foreign_table( $safe ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Teardown DDL: table name is an identifier (sanitized above) and is bound with %i; DROP has no cache to invalidate.
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
	 * Schema::tables_exist()'s SHOW TABLES probe, past every cache.
	 *
	 * @param string $table Full languages table name.
	 * @return bool|null Whether the table exists; null when the probe failed (no answer).
	 */
	public static function probe_tables( string $table ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema::tables_exist() caches the answer (autoloaded option, object cache, per-request static).
		$verdict = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return '' !== $wpdb->last_error ? null : $verdict;
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

	/**
	 * Whether the schema is the one this code expects: tables installed and no schema upgrade pending.
	 *
	 * No query when current: both reads are autoloaded options. Cold paths only (jobs, maintenance), so not in Schema.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return ! self::upgrade_pending() && Schema::tables_exist();
	}

	/**
	 * Whether a stored DB version is below the code's (an upgrade not finished yet); false before the first activation.
	 *
	 * @return bool
	 */
	public static function upgrade_pending(): bool {
		$stored = get_option( 'perflocale_db_version' );

		return false !== $stored && (int) $stored < PERFLOCALE_DB_VERSION;
	}
}
