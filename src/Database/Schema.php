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
	 * Autoloaded per-blog option holding the positive tables_exist verdict —
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

		// Table 3: Translation links.
		// `type` mirrors the owning group's type ('post' / 'term' / 'string').
		// object_id is a POLYMORPHIC id — post, term, and string id-spaces share
		// the column — so the object-uniqueness key MUST include the type or a
		// post and a term with a colliding numeric id can't coexist and routine
		// writes corrupt each other. Shipped inline in the canonical schema.
		//
		// `object_lookup` is load-bearing: because `type` leads the UNIQUE
		// `object_lang`, that key covers NO query that binds only object_id (the
		// hot per-object shapes bind type on the GROUPS table, never on links).
		// Without it, main-query language filtering and the found-rows COUNT
		// degrade to full link-table scans per outer row (~11 s uncached at
		// 10k posts; ~2 ms with the index). Do not "dedupe" it against
		// `object_lang` — it is not a leftmost prefix of it.
		//
		// Indexes intentionally NOT included (EXPLAIN-verified against real
		// data — kept here so a future contributor doesn't re-add them):
		// - `KEY status (status)`: low cardinality (3-4 values); the
		// planner picks `group_status` for filtered status queries
		// and ignores the standalone single-column index.
		// - `KEY group_object (group_id, object_id)`: planner always
		// picks the UNIQUE `object_lang` for `WHERE group_id = ? AND
		// object_id = ?` because the UNIQUE on object_id resolves to
		// ≤1 row before group_id is even checked.
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

		// Table 4: Translatable strings.
		//
		// Column sizing:
		// - context VARCHAR(191) — fits InnoDB's 767-byte per-column index
		//   limit on legacy MySQL (utf8mb4 × 191 = 764 bytes) so both
		//   KEY context and KEY domain_context indexes work on every MySQL.
		//   Practical translation contexts (the _x() 3rd arg) are always
		//   short identifiers ("menu_item", "post_status", "navigation"),
		//   so 191 chars is well past what real-world i18n data needs.
		// - original_hash CHAR(64) ASCII — SHA-256 hex digests use exactly
		//   64 chars from [0-9a-f]. ASCII charset stores 1 byte/char vs
		//   utf8mb4's 4; the UNIQUE index drops from 256 → 64 bytes/row.
		//   latin1_bin collation keeps hash comparisons collation-free at
		//   1 byte/char. latin1 (NOT ascii) is load-bearing: wpdb's
		//   get_table_charset() DROPS latin1 from mixed charset sets, so the
		//   table resolves to utf8mb4 and raw queries carrying multibyte text
		//   pass wpdb's pre-flight. An ascii hash column poisoned the WHOLE
		//   table to 'ascii' and made wpdb silently reject every multibyte
		//   INSERT/SELECT that didn't go through wpdb->insert()'s per-field
		//   checks (proven: TM store + Strings search dropped non-ASCII).
		// - last_seen_at: mark-and-sweep GC timestamp. Touched by every
		//   StringRepository::bulk_insert() (the scanner re-marks rows
		//   it re-discovers in plugin/theme code) and every
		//   register_setting_string() (manually-registered strings stay
		//   fresh as long as the code path that registers them runs).
		//   The daily GC deletes rows whose last_seen_at is older than
		//   the filterable retention window (default 90 days) — covers
		//   the disabled-plugin / removed-theme cleanup case.
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

		// Table 6: Slug translations.
		//
		// Two invariants, both DB-enforced:
		//
		// 1. `UNIQUE KEY object_slug (object_type, object_id, language_id)` —
		// each object has exactly ONE translated slug per language.
		//
		// 2. `UNIQUE KEY slug_lookup (language_id, object_type, object_subtype,
		// slug)` — within a single (language, object_type, object_subtype)
		// namespace, every translated slug is unique. The `object_subtype`
		// column captures the WP sub-type that `object_type` alone can't:
		// - For posts (object_type='post'): the post_type ('post',
		// 'page', 'product', etc.).
		// - For terms (object_type='term'): the taxonomy ('category',
		// 'product_cat', 'post_tag', etc.).
		//
		// Without this distinction the second UNIQUE would false-flag
		// legitimately-separate URL namespaces (e.g. category/uncategorized
		// vs product_cat/uncategorized sharing a translated slug). Including
		// the subtype makes the DB invariant match the actual URL-collision
		// surface, so SlugTranslationRepository::find_unique_slug() can
		// auto-suffix the genuine same-namespace conflicts and accept the
		// cross-namespace coincidences as-is.
		// Column sizing:
		// - slug is indexed at slug(191), NOT in full. InnoDB's per-COLUMN
		//   index limit is 767 bytes under REDUNDANT/COMPACT row format
		//   (MySQL <= 5.6, MariaDB <= 10.1 — both inside WP 6.4's supported
		//   matrix). utf8mb4 x 200 + 2 length bytes = 802, so indexing the
		//   full column makes CREATE TABLE fail outright with "Specified key
		//   was too long; max key length is 767 bytes" — the other eight
		//   tables create fine, leaving a half-provisioned schema and an
		//   activation error that misleadingly blames DB permissions.
		//   191 x 4 + 2 = 766, one byte under the cap, and it is the same
		//   prefix WordPress core uses for wp_posts.post_name(191) and
		//   wp_terms.slug(191). The limit is per COLUMN, not per key.
		//   Keep the column itself VARCHAR(200) to match core's post_name.
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



		// Table 9: Content hashes - track source content for change detection.
		// content_hash is a SHA-256 hex digest — 64 chars from [0-9a-f].
		// latin1 stores 1 byte/char vs utf8mb4's 4, and latin1_bin keeps
		// hash comparison collation-free. See strings table for why latin1
		// (not ascii) matters for wpdb's table-charset resolution.
		$sql_hashes = "CREATE TABLE {$prefix}content_hashes (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			object_id BIGINT(20) UNSIGNED NOT NULL,
			object_type VARCHAR(20) NOT NULL DEFAULT 'post',
			content_hash CHAR(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY object_type_id (object_type, object_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 11: String translations - replaces the per-row
		// `perflocale_str_<id>_<lang>` option pattern that required a
		// CONCAT()-join on wp_options (non-indexable, full table scan).
		// With a proper composite primary key on (string_id, language_id)
		// every lookup is now a direct index seek.
		// `extra_forms` holds the JSON-encoded plural forms BEYOND the two
		// this row's `translation` and its sibling singular/plural rows
		// cover — i.e. msgstr[2..N] for CLDR languages with 3-6 plural
		// forms (Polish, Russian, Arabic …). It lives only on the
		// `plural`-context row and is NULL for every 2-form language and
		// every non-plural string, so the common case pays zero storage and
		// the column is never read. See PluralRules + StringTranslation's
		// form-index selection. Nullable, no index (only ever read
		// alongside its own row by PRIMARY KEY).
		$sql_string_translations = "CREATE TABLE {$prefix}string_translations (
			string_id BIGINT(20) UNSIGNED NOT NULL,
			language_id BIGINT(20) UNSIGNED NOT NULL,
			translation LONGTEXT NOT NULL,
			extra_forms LONGTEXT NULL DEFAULT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (string_id, language_id),
			KEY language_id (language_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Table 11: Background-job state. Dedicated table for typed, indexed
		// state tracking. Replaces the older option-based store (one row per
		// job in wp_options) — the option store doesn't allow indexed
		// queries, so admin filters / stuck-job scans / GDPR sweeps were
		// O(N) over the full active-index. Typed columns + indexes here
		// give:
		// - `uuid`             — external-facing identifier (REST URLs,
		// Action Scheduler args).
		// - `version`          — optimistic-concurrency CAS column. Every
		// mutating UPDATE bumps it AND checks the
		// prior value, so concurrent writers serialise
		// at the DB row-lock layer without ever
		// overwriting each other.
		// - `status_updated`   — covers stuck-job watchdog
		// (`WHERE status IN (...) AND updated_at < ?`).
		// - `type_status`      — admin / CLI filtering by job type + status.
		// - `created_by`       — GDPR exporter/eraser + per-user admin views.
		//
		// LONGTEXT for args/result/log because args can be ~100 KB
		// (filterable) and a 20-entry log ring buffer can grow past
		// TEXT's 64 KB limit if entries are near the 500-char cap.
		//
		// `updated_at` deliberately has NO `ON UPDATE CURRENT_TIMESTAMP`:
		// that stamps the MySQL SERVER's clock, while every sibling datetime
		// is written by PHP in UTC (current_time('mysql', true)) and every
		// consumer — the stuck-job watchdog, the GC sweeps, hydrate() —
		// compares it with gmdate(). When the DB timezone is not UTC the two
		// clocks disagree: on a server behind UTC a row touched a second ago
		// already looks hours stale, so the watchdog force-fails jobs that
		// are still running and discards their result. JobState writes
		// updated_at explicitly on every state change instead; MySQL DDL has
		// no way to express "ON UPDATE UTC_TIMESTAMP".
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

		// Table 12: Migration source-map. Pins a source-plugin identifier
		// (WPML trid, Polylang term_id, etc.) to the translation_groups row
		// the importer created for it. Lookups happen INSIDE the
		// create_group() transaction so a partial-failure crash or a
		// post-import DB restore can't allocate a duplicate group_id for
		// the same source identifier on retry. Strings already get this
		// guarantee from REPLACE INTO; this table extends the same
		// idempotency to posts and terms.
		//
		// Columns:
		//   - migration_type: 'wpml' / 'polylang' / 'trp' (extensible).
		//   - source_key: per-importer identifier, format up to the
		//     importer (e.g. WPML uses "<trid>|<element_type>").
		//   - group_id: translation_groups.id this maps to.
		//
		// The UNIQUE (migration_type, source_key) is what makes the
		// lookup-or-create dance atomic via ON DUPLICATE KEY UPDATE.
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

		// Schema is now present — clear any cached verdict so the next
		// tables_exist() re-confirms against the fresh state. Covers both
		// activation (Activator) and upgrades (Migrator), the two callers.
		self::flush_tables_exist_cache();
	}

	/**
	 * Drop all plugin tables.
	 *
	 * Used during uninstall only.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'perflocale_';

		// Discover every existing `<prefix>perflocale_*` table rather than
		// using a hard-coded list. Earlier plugin versions shipped tables
		// that newer versions removed from the schema (e.g. an `options`
		// table that lived briefly during early development). A hard-coded
		// drop list would leave those stranded on upgraded installs.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = (array) $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);

		foreach ( $existing as $full_name ) {
			// $full_name is the DB's own SHOW TABLES output, already filtered
			// to our prefix; we still strip it to a bare identifier before
			// interpolation since prepare() cannot bind a table name, and
			// require it to start with our prefix as a final guard.
			$safe = self::sanitize_table( (string) $full_name );
			if ( $safe === '' || 0 !== strpos( $safe, $prefix ) ) {
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

		// Tables are gone — drop the cached "installed" verdict so nothing
		// keeps querying the now-missing tables.
		self::flush_tables_exist_cache();
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
	 * Make a table identifier safe to interpolate into a query string.
	 *
	 * `wpdb::prepare()` binds VALUES with %d/%s placeholders — it cannot bind
	 * SQL identifiers (table/column names). So a table name that genuinely
	 * has to be interpolated — DDL such as CREATE/DROP/TRUNCATE, or a dynamic
	 * FROM — is hardened here instead: every character outside the set a real
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
	 * exactly one SHOW TABLES per request — no regression. That same
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
		global $wpdb;

		$missing = [];

		foreach ( self::REQUIRED_TABLES as $table_name ) {
			$full_name = self::table( $table_name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) ) ) {
				$missing[] = $full_name;
			}
		}

		return $missing;
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
		// which made this method run SHOW TABLES per request — a measurable
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
