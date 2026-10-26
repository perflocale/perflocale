<?php
/**
 * Three-layer cache manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cache;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three-layer cache: L1 per-request array, L2 object cache, L3 transients.
 *
 * Reads cascade L1, L2, L3, then the loader; writes fill every layer.
 */
final class CacheManager {

	/**
	 * Default cache group.
	 */
	private const GROUP = 'perflocale';

	/**
	 * Every cache group the plugin uses.
	 *
	 * Iterated by flush_all(): a group missing here keeps stale entries past a flush.
	 *
	 * @var string[]
	 */
	public const GROUPS = [
		'perflocale',
		'perflocale_langs',
		'perflocale_trans',
		'perflocale_strings',
		'perflocale_slugs',
		'perflocale_hreflang',
		'perflocale_geo_lookup',
		'perflocale_found_rows',
	];

	/**
	 * Transient name prefixes flush_all() keeps: state, not cache (notices, rate limits, breakers).
	 *
	 * Must equal DataRegistry::flush_kept_transient_prefixes(); gate rule E13 and the unit tests check it.
	 *
	 * @var list<string>
	 */
	public const FLUSH_KEPT_TRANSIENTS = [
		'perflocale_breaker_',
		'perflocale_bulk_mt_error_',
		'perflocale_bulk_mt_warning_',
		'perflocale_import_notice_',
		'perflocale_lang_save_error_',
		'perflocale_mt_create_notice_',
		'perflocale_mt_publisher_',
		'perflocale_mt_rl_',
		'perflocale_po_import_result_',
		'perflocale_scan_queued_notice_',
		'perflocale_scan_result_',
		'perflocale_strings_regenerating',
	];

	/**
	 * Fixed-name transients flush_all() deletes by name under a persistent object cache.
	 *
	 * There they live only in its `transient` group, which the wp_options sweep misses. Must equal
	 * DataRegistry::flush_deleted_transient_names() (unit tests check it).
	 *
	 * @var list<string>
	 */
	public const FLUSH_DELETED_TRANSIENTS = [
		'perflocale_bootable_addons',
		'perflocale_exports_exposed',
		'perflocale_mt_wp_ai_ready',
		'perflocale_rewrites_verified',
		'perflocale_site_health_counts',
		'perflocale_site_health_files',
		'perflocale_site_health_hreflang',
		'perflocale_site_health_mt_scoring',
		'perflocale_site_health_orphans',
		'perflocale_site_health_stuck',
		'perflocale_string_blob_max',
		'perflocale_vcopy_epoch',
		'perflocale_vsig_epoch',
	];

	/**
	 * Maximum number of entries in the L1 static cache.
	 */
	private const MAX_STATIC_ENTRIES = 5000;

	/**
	 * Autoloaded-option name prefix for per-group L2 generations.
	 */
	private const GEN_OPTION_PREFIX = 'perflocale_cgen_';

	/**
	 * Ceilings for one batched L3 INSERT in set_many(); whichever trips first flushes the batch.
	 *
	 * Bytes stay far below the 1 MB max_allowed_packet floor even if escaping doubles them;
	 * see docs-ai/11-code-rationale.md, "CacheManager: batched L3 writes".
	 */
	private const L3_INSERT_MAX_ROWS  = 500;
	private const L3_INSERT_MAX_BYTES = 262144;

	/**
	 * L1: Per-request in-memory cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $static_cache = [];

	/**
	 * Per-process memo of L2 group generations, keyed "<blog_id>:<group>".
	 * Avoids a get_option() on every L2 access. See l2_generation().
	 *
	 * @var array<string, int>
	 */
	private static array $gen_memo = [];

	/**
	 * Option counting each blog's announced per-entry invalidations, which no generation records.
	 *
	 * Not autoloaded; named under the generation prefix so the checkpoint's one query reads it.
	 */
	private const CHANGES_OPTION = 'perflocale_cgen__changes';

	/**
	 * Blogs with per-entry invalidations this process has not announced yet
	 * (announce_changes() raises their change counters).
	 *
	 * @var array<int, true>
	 */
	private static array $changes_pending = [];

	/**
	 * Whether announce_changes() is registered as a shutdown function.
	 *
	 * @var bool
	 */
	private static bool $announce_registered = false;

	/**
	 * The change counter of each blog as this process's last checkpoint read it.
	 *
	 * @var array<int, int>
	 */
	private static array $changes_seen = [];

	/**
	 * How many times this process raised each blog's change counter since its
	 * last checkpoint read it. The next checkpoint counts these raises as its
	 * own, not as another process's change.
	 *
	 * @var array<int, int>
	 */
	private static array $changes_own = [];

	/**
	 * Blogs on which this process's own generation bump read back a value
	 * that also holds another process's bump, by blog id. The bump moved this
	 * process's memo past that change, so the next checkpoint reports it.
	 *
	 * @var array<int, true>
	 */
	private static array $bumped_past = [];

	/**
	 * The did_filter( 'pre_wp_load_alloptions' ) count when flush_runtime() last emptied the runtime
	 * object cache, or null when it has not.
	 *
	 * @var int|null
	 */
	private static ?int $runtime_flushed_at = null;

	/**
	 * Whether wp_cache_flush_runtime() is not core's stand-in
	 * (drop_in_flushes_runtime()), or null before the first check.
	 *
	 * @var bool|null
	 */
	private static ?bool $drop_in_flushes_runtime = null;

	/**
	 * Autoloaded options delete_autoloaded_options() found absent without the cached-list check (after
	 * flush_runtime()), by blog id; before_autoloaded_read() checks them before PerfLocale reads one.
	 *
	 * @var array<int, array<string, true>>
	 */
	private static array $unchecked_absent = [];

	/**
	 * Whether L2 (WordPress Object Cache) is enabled.
	 *
	 * @var bool
	 */
	private bool $object_cache_enabled;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->object_cache_enabled = (bool) $settings->get( 'cache_object_enabled', true );
	}

	/**
	 * Register hooks.
	 *
	 * Write requests only: a translatable post's status change drops the Translations screen's cached
	 * status selections. Every other hook-based invalidation lives in CacheInvalidator.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$is_write_context = is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] )
			|| defined( 'REST_REQUEST' )
			|| ( defined( 'WP_CLI' ) && WP_CLI );

		if ( $is_write_context ) {
			add_action( 'transition_post_status', [ \PerfLocale\Database\Repository\TranslationGroupRepository::class, 'on_post_status_transition' ], 10, 3 );
		}
	}

	/**
	 * Get a value from the cache, computing it if not found.
	 *
	 * Caches the loader's result even when it is null, so a miss is cached too: a sweep or repair that
	 * must see current DB truth uses a raw query instead.
	 *
	 * @param string   $key Cache key.
	 * @param callable $loader Callable that computes the value on cache miss.
	 * @param int      $ttl Time-to-live in seconds (for L2 and L3).
	 * @param string   $group Cache group (default: 'perflocale').
	 * @return mixed
	 */
	public function get( string $key, callable $loader, int $ttl = HOUR_IN_SECONDS, string $group = self::GROUP ): mixed {
		// L1: static cache, keyed with the blog ID so multisite blogs never share entries.
		$full_key = $this->make_static_key( $group, $key );

		if ( array_key_exists( $full_key, $this->static_cache ) ) {
			return $this->static_cache[ $full_key ];
		}

		// L2: WordPress Object Cache (skipped if disabled in settings).
		if ( $this->object_cache_enabled ) {
			$found = false;
			$value = wp_cache_get( self::l2_key( $key, $group ), $group, false, $found );

			if ( $found ) {
				$this->ensure_static_capacity( $full_key );
				$this->static_cache[ $full_key ] = $value;
				return $value;
			}
		}

		// L3: transients, in a ['v' => ...] envelope so a stored null or false differs from a miss.
		// Skipped under a persistent object cache, as in set(): there transients duplicate L2.
		if ( ! wp_using_ext_object_cache() ) {
			$transient_key = $this->transient_key( $key, $group );
			$envelope      = get_transient( $transient_key );

			if ( is_array( $envelope ) && array_key_exists( 'v', $envelope ) ) {
				$value = $envelope['v'];
				$this->ensure_static_capacity( $full_key );
				$this->static_cache[ $full_key ] = $value;

				// Promote to L2 only when L2 reads are enabled.
				if ( $this->object_cache_enabled ) {
					wp_cache_set( self::l2_key( $key, $group ), $value, $group, $ttl );
				}

				return $value;
			}
		}

		// Cache miss - compute the value.
		$value = $loader();

		$this->set( $key, $value, $ttl, $group );

		return $value;
	}

	/**
	 * Read a value from the cache layers without computing or writing on a miss.
	 *
	 * For lookups whose negative results must not be persisted; returns $default on a full miss.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @param mixed  $default Value to return on a miss.
	 * @return mixed
	 */
	public function get_cached( string $key, string $group = self::GROUP, mixed $default = null ): mixed {
		$full_key = $this->make_static_key( $group, $key );

		if ( array_key_exists( $full_key, $this->static_cache ) ) {
			return $this->static_cache[ $full_key ];
		}

		if ( $this->object_cache_enabled ) {
			$found = false;
			$value = wp_cache_get( self::l2_key( $key, $group ), $group, false, $found );

			if ( $found ) {
				$this->ensure_static_capacity( $full_key );
				$this->static_cache[ $full_key ] = $value;
				return $value;
			}
		}

		if ( ! wp_using_ext_object_cache() ) {
			$envelope = get_transient( $this->transient_key( $key, $group ) );

			if ( is_array( $envelope ) && array_key_exists( 'v', $envelope ) ) {
				$value = $envelope['v'];
				$this->ensure_static_capacity( $full_key );
				$this->static_cache[ $full_key ] = $value;
				return $value;
			}
		}

		return $default;
	}

	/**
	 * Store a value in all three cache layers.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl Time-to-live in seconds.
	 * @param string $group Cache group.
	 * @return void
	 */
	public function set( string $key, mixed $value, int $ttl = HOUR_IN_SECONDS, string $group = self::GROUP ): void {
		$full_key = $this->make_static_key( $group, $key );

		// L1 - evict oldest entries if at capacity.
		$this->ensure_static_capacity( $full_key );

		$this->static_cache[ $full_key ] = $value;

		// L2 (skipped if disabled in settings).
		if ( $this->object_cache_enabled ) {
			wp_cache_set( self::l2_key( $key, $group ), $value, $group, $ttl );
		}

		// L3 in a ['v' => ...] envelope. Skipped under a persistent object cache: transients go to its shared
		// `transient` group there, which no group flush or wp_options sweep reaches.
		if ( ! wp_using_ext_object_cache() ) {
			$transient_key = $this->transient_key( $key, $group );
			set_transient( $transient_key, [ 'v' => $value ], $ttl );
		}
	}

	/**
	 * Write many entries to L1, L2 and L3, with L3 as batched multi-row INSERTs.
	 *
	 * Expired `_transient_timeout_` twins of $drop_timeout_keys are deleted only after every batch
	 * succeeds; until then a rewritten row would still read as expired.
	 *
	 * @param array<string, mixed> $entries map of cache_key => value
	 * @param int                  $ttl
	 * @param string               $group
	 * @param string[]             $drop_timeout_keys Keys of $entries whose expired timeout twin is deleted after the write.
	 * @return void
	 */
	public function set_many( array $entries, int $ttl = HOUR_IN_SECONDS, string $group = self::GROUP, array $drop_timeout_keys = [] ): void {
		if ( $entries === [] ) {
			return;
		}

		// L1 - evict oldest entries if at capacity, then write each one.
		$remaining_capacity = self::MAX_STATIC_ENTRIES - count( $this->static_cache );

		if ( $remaining_capacity < count( $entries ) ) {
			$this->static_cache = array_slice(
				$this->static_cache,
				(int) ( self::MAX_STATIC_ENTRIES * 0.25 ),
				null,
				true
			);
		}

		foreach ( $entries as $key => $value ) {
			$this->static_cache[ $this->make_static_key( $group, $key ) ] = $value;
		}

		// Re-assert the bound after the batch: the fixed 25% slice above does not cover a large batch.
		// Drops the oldest keys so this call's entries survive; an evicted key only re-reads from L2/L3.
		$overflow = count( $this->static_cache ) - self::MAX_STATIC_ENTRIES;

		if ( $overflow > 0 ) {
			$this->static_cache = array_slice( $this->static_cache, $overflow, null, true );
		}

		// L2 (skipped if disabled in settings): wp_cache_set_multiple() where available.
		if ( $this->object_cache_enabled ) {
			if ( function_exists( 'wp_cache_set_multiple' ) ) {
				$l2_entries = [];
				foreach ( $entries as $key => $value ) {
					$l2_entries[ self::l2_key( $key, $group ) ] = $value;
				}
				wp_cache_set_multiple( $l2_entries, $group, $ttl );
			} else {
				foreach ( $entries as $key => $value ) {
					wp_cache_set( self::l2_key( $key, $group ), $value, $group, $ttl );
				}
			}
		}

		// L3 - multi-row INSERTs. Skipped under a persistent object cache: get_transient() reads that
		// backend, so rows written here would never be read.
		if ( wp_using_ext_object_cache() ) {
			return;
		}

		// No `_transient_timeout_*` row: CacheInvalidator purges on every staling event and a row without one
		// never expires. Expired twins of $drop_timeout_keys are deleted after the INSERTs.
		$option_names  = [];
		$timeout_names = [];
		$drop_timeouts = array_fill_keys( $drop_timeout_keys, true );
		$values_clause = [];
		$args          = [];
		$batch_bytes   = 0;
		$all_written   = true;

		foreach ( $entries as $key => $value ) {
			$transient_id = $this->derive_transient_key( (string) $key, $group );
			$data_name    = '_transient_' . $transient_id;
			$serialized   = (string) maybe_serialize( [ 'v' => $value ] );
			$row_bytes    = strlen( $data_name ) + strlen( $serialized );

				// Flush before appending a row that would breach a ceiling; a lone oversized row goes out alone.
			if (
				$values_clause !== []
				&& ( count( $values_clause ) >= self::L3_INSERT_MAX_ROWS
					|| $batch_bytes + $row_bytes > self::L3_INSERT_MAX_BYTES )
			) {
				$all_written = $this->insert_transient_rows( $values_clause, $args ) && $all_written;

				$values_clause = [];
				$args          = [];
				$batch_bytes   = 0;
			}

			$option_names[]  = $data_name;
			$values_clause[] = '(%s, %s, %s)';

			$args[] = $data_name;
			$args[] = $serialized;
			$args[] = 'no';

			$batch_bytes += $row_bytes;

			if ( isset( $drop_timeouts[ $key ] ) ) {
				$timeout_names[] = '_transient_timeout_' . $transient_id;
			}
		}

		if ( $values_clause !== [] ) {
			$all_written = $this->insert_transient_rows( $values_clause, $args ) && $all_written;
		}

		if ( $all_written && $timeout_names !== [] ) {
			$this->delete_transient_timeout_rows( $timeout_names );
		}

		// Raw SQL bypasses update_option(), so drop each name from the options cache.
		foreach ( $option_names as $name ) {
			wp_cache_delete( $name, 'options' );
		}

		// The inserted rows may be listed absent. Only their names: this path runs without a persistent
		// cache, so the list is this request's own and a whole drop only re-reads every absent option.
		\PerfLocale\Database\NotOptions::unlist( $option_names );
	}

	/**
	 * Emit one batched L3 transient INSERT.
	 *
	 * ON DUPLICATE KEY UPDATE keeps each batch independent: a re-primed key overwrites its row.
	 *
	 * @param string[]     $values_clause One `(%s, %s, %s)` tuple per row.
	 * @param array<mixed> $args Flattened bind values matching $values_clause.
	 * @return bool False when the statement failed.
	 */
	private function insert_transient_rows( array $values_clause, array $args ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES "
			. implode( ',', $values_clause )
			. ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)';

		$result = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * Delete `_transient_timeout_*` rows by exact name, at most
	 * L3_INSERT_MAX_ROWS names per statement, and drop each name from the
	 * options cache (the rows go by raw SQL, not delete_option()).
	 *
	 * @param string[] $timeout_names Full option names.
	 * @return void
	 */
	private function delete_transient_timeout_rows( array $timeout_names ): void {
		global $wpdb;

		foreach ( array_chunk( $timeout_names, self::L3_INSERT_MAX_ROWS ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name IN ({$placeholders})", $wpdb->options, ...$chunk ) );
			// phpcs:enable
		}

		foreach ( $timeout_names as $name ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/**
	 * Delete a value from all cache layers.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return void
	 */
	public function delete( string $key, string $group = self::GROUP ): void {
		$this->evict( $key, $group );

		self::note_change();
	}

	/**
	 * Delete a value from all cache layers without announcing a change.
	 *
	 * For a read result that must not stay cached (a failed load) when the stored data did not change.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return void
	 */
	public function evict( string $key, string $group = self::GROUP ): void {
		$full_key = $this->make_static_key( $group, $key );

		unset( $this->static_cache[ $full_key ] );

		if ( $this->object_cache_enabled ) {
			wp_cache_delete( self::l2_key( $key, $group ), $group );
		}

		// L3 exists only without a persistent object cache; under one, core would send this delete to the shared
		// `transient` group, where this class stores nothing.
		if ( ! wp_using_ext_object_cache() ) {
			delete_transient( $this->transient_key( $key, $group ) );
		}
	}

	/**
	 * Delete several values from all cache layers.
	 *
	 * The batch form of delete(): one L2 multiple delete and one L3 DELETE per L3_INSERT_MAX_ROWS names.
	 *
	 * @param string[] $keys  Cache keys.
	 * @param string   $group Cache group.
	 * @return void
	 */
	public function delete_many( array $keys, string $group = self::GROUP ): void {
		if ( $keys === [] ) {
			return;
		}

		self::note_change();

		$l2_keys = [];
		$names   = [];

		foreach ( $keys as $key ) {
			$key = (string) $key;

			unset( $this->static_cache[ $this->make_static_key( $group, $key ) ] );

			$l2_keys[]    = self::l2_key( $key, $group );
			$transient_id = $this->derive_transient_key( $key, $group );
			$names[]      = '_transient_' . $transient_id;
			$names[]      = '_transient_timeout_' . $transient_id;
		}

		if ( $this->object_cache_enabled ) {
			wp_cache_delete_multiple( $l2_keys, $group );
		}

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		global $wpdb;

		foreach ( array_chunk( $names, self::L3_INSERT_MAX_ROWS ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name IN ({$placeholders})", $wpdb->options, ...$chunk ) );
			// phpcs:enable
		}

		// Raw SQL bypasses delete_option(), so drop each name from the options cache.
		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/**
	 * Most names in one existence SELECT of delete_existing(): MariaDB turns an IN list of
	 * 1,000 values or more into a subquery (in_predicate_conversion_threshold).
	 */
	private const L3_SELECT_MAX_NAMES = 500;

	/**
	 * Delete entries of one group from every layer, with SQL only for the L3 rows that exist.
	 *
	 * Follows delete_transient()'s order and conditions and never DELETEs a row its non-locking SELECTs did not
	 * find, so inside a transaction it takes only the locks delete_transient() would; see
	 * docs-ai/11-code-rationale.md, "CacheManager: delete_existing".
	 *
	 * @internal Used by CacheInvalidator's hreflang sweep.
	 *
	 * @param string[] $keys  Cache keys.
	 * @param string   $group Cache group.
	 * @return void
	 */
	public function delete_existing( array $keys, string $group = self::GROUP ): void {
		if ( $keys === [] ) {
			return;
		}

		self::note_change();

		$l2_keys = [];

		foreach ( $keys as $key ) {
			$key = (string) $key;

			unset( $this->static_cache[ $this->make_static_key( $group, $key ) ] );

			if ( $this->object_cache_enabled ) {
				$l2_keys[] = self::l2_key( $key, $group );
			}
		}

		if ( $l2_keys !== [] ) {
			if ( wp_cache_supports( 'delete_multiple' ) ) {
				wp_cache_delete_multiple( $l2_keys, $group );
			} else {
				foreach ( $l2_keys as $l2_key ) {
					wp_cache_delete( $l2_key, $group );
				}
			}
		}

		if ( wp_using_ext_object_cache() ) {
			return;
		}

		$transients = [];

		foreach ( $keys as $key ) {
			$transients[] = $this->derive_transient_key( (string) $key, $group );
		}

		$transients = array_values( array_unique( $transients ) );

		if ( wp_installing() ) {
			foreach ( $transients as $transient ) {
				delete_transient( $transient );
			}

			return;
		}

		foreach ( array_chunk( $transients, self::L3_SELECT_MAX_NAMES ) as $chunk ) {
			$this->delete_existing_transients( $chunk );
		}
	}

	/**
	 * Run delete_transient() for each name of one chunk, with SQL only for the rows that exist.
	 *
	 * @param string[] $transients Transient names.
	 * @return void
	 */
	private function delete_existing_transients( array $transients ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$data_names = [];

		foreach ( $transients as $transient ) {
			$data_names[] = '_transient_' . $transient;
		}

		$data_rows = self::find_option_rows( $data_names );

		if ( null === $data_rows ) {
			foreach ( $transients as $transient ) {
				delete_transient( $transient );
			}

			return;
		}

		if ( $data_rows === [] ) {
			return;
		}

		$timeout_names = [];

		foreach ( $transients as $transient ) {
			if ( isset( $data_rows[ strtolower( '_transient_' . $transient ) ] ) ) {
				$timeout_names[] = '_transient_timeout_' . $transient;
			}
		}

		$timeout_rows = self::find_option_rows( $timeout_names );

		if ( null === $timeout_rows ) {
			foreach ( $transients as $transient ) {
				delete_transient( $transient );
			}

			return;
		}

			// delete_option()'s autoload test: 'yes' without wp_autoload_values_to_autoload(), else that
			// function's four defaults through its filter, which can only remove one.
		$autoload_on = [ 'yes' ];

		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			$autoload_on = [ 'yes', 'on', 'auto-on', 'auto' ];
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, applied as wp_autoload_values_to_autoload() applies it.
			$autoload_on = array_intersect( array_filter( (array) apply_filters( 'wp_autoload_values_to_autoload', $autoload_on ), 'is_string' ), $autoload_on );
		}

		// delete_option() adds a deleted name to notoptions from WordPress 6.7,
		// the release that added wp_get_wp_version().
		$record_absent = function_exists( 'wp_get_wp_version' );

		foreach ( $transients as $transient ) {
			$data          = '_transient_' . $transient;
			$data_autoload = $data_rows[ strtolower( $data ) ] ?? null;

			if ( null === $data_autoload ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_option()'s own statement.
			$deleted = (bool) $wpdb->delete( $wpdb->options, [ 'option_name' => $data ] );

			self::forget_deleted_option( $data, in_array( $data_autoload, $autoload_on, true ), $record_absent );

			if ( ! $deleted ) {
				continue;
			}

			$timeout          = '_transient_timeout_' . $transient;
			$timeout_autoload = $timeout_rows[ strtolower( $timeout ) ] ?? null;

			if ( null === $timeout_autoload ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_option()'s own statement.
			$wpdb->delete( $wpdb->options, [ 'option_name' => $timeout ] );

			self::forget_deleted_option( $timeout, in_array( $timeout_autoload, $autoload_on, true ), $record_absent );
		}
	}

	/**
	 * Read which of the named options rows exist, with their autoload value, in one SELECT.
	 *
	 * @param string[] $names Option names, at most L3_SELECT_MAX_NAMES.
	 * @return array<string, string>|null Autoload value by lower-cased name; null when the SELECT fails.
	 */
	private static function find_option_rows( array $names ): ?array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( $names === [] ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ran = $wpdb->query( $wpdb->prepare( "SELECT option_name, autoload FROM %i WHERE option_name IN ({$placeholders})", $wpdb->options, ...$names ) );
		// phpcs:enable

		if ( false === $ran ) {
			return null;
		}

		$rows = [];

		/**
		 * A db.php drop-in may fill last_result with rows of any shape.
		 *
		 * @var mixed $row
		 */
		foreach ( (array) $wpdb->last_result as $row ) {
			if ( is_object( $row ) && isset( $row->option_name, $row->autoload ) ) {
				/**
				 * Both columns are VARCHAR NOT NULL.
				 *
				 * @var object{option_name: string, autoload: string} $row
				 */
				// Keyed the way the IN list matched: case-insensitive, trailing spaces ignored.
				$rows[ strtolower( rtrim( (string) $row->option_name ) ) ] = (string) $row->autoload;
			}
		}

		return $rows;
	}

	/**
	 * Give deleted rows delete_option()'s cache handling.
	 *
	 * Autoloaded names leave alloptions, others the options cache; with $record_absent the name goes into
	 * notoptions. Runs only without a persistent object cache, where all three are per request.
	 *
	 * @param string $name          Option name.
	 * @param bool   $autoloaded    Whether the row was autoloaded, by delete_option()'s test.
	 * @param bool   $record_absent Whether delete_option() records deleted names in notoptions.
	 * @return void
	 */
	private static function forget_deleted_option( string $name, bool $autoloaded, bool $record_absent ): void {
		if ( $autoloaded ) {
			$alloptions = wp_load_alloptions( true );

			if ( isset( $alloptions[ $name ] ) ) {
				unset( $alloptions[ $name ] );
				wp_cache_set( 'alloptions', $alloptions, 'options' );
			}
		} else {
			wp_cache_delete( $name, 'options' );
		}

		if ( ! $record_absent ) {
			return;
		}

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$notoptions = is_array( $notoptions ) ? $notoptions : [];

		$notoptions[ $name ] = true;

		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}

	/**
	 * Delete autoloaded options, including a cached copy that has no row.
	 *
	 * WordPress's delete_option() leaves a name in the cached `alloptions` list when the row is gone (a concurrent
	 * request stored the list whole, or a rollback), so the list is dropped (one delete, never an edit) when an
	 * absent row is still in it; see docs-ai/11-code-rationale.md, "CacheManager: autoloaded option deletes".
	 *
	 * @param string[] $names Option names.
	 * @return void
	 */
	public static function delete_autoloaded_options( array $names ): void {
		self::note_change();

		$absent = [];

		foreach ( $names as $name ) {
			$deleted = delete_option( $name );

			wp_cache_delete( $name, 'options' );

			if ( ! $deleted ) {
				$absent[] = $name;
			}
		}

		if ( $absent === [] || wp_installing() ) {
			return;
		}

		$blog_id = get_current_blog_id();

		if ( self::alloptions_unloaded_since_flush() ) {
			foreach ( $absent as $name ) {
				self::$unchecked_absent[ $blog_id ][ $name ] = true;
			}

			return;
		}

		self::forget_unchecked_absent( $blog_id, $absent );

		$alloptions = wp_load_alloptions();

		foreach ( $absent as $name ) {
			if ( isset( $alloptions[ $name ] ) ) {
				wp_cache_delete( 'alloptions', 'options' );

				return;
			}
		}
	}

	/**
	 * Before PerfLocale reads an option delete_autoloaded_options() left unchecked, drop a stale alloptions list.
	 *
	 * One array lookup unless the option was noted; the read loads the list anyway.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function before_autoloaded_read( string $name ): void {
		if ( self::$unchecked_absent === [] ) {
			return;
		}

		$blog_id = get_current_blog_id();

		if ( ! isset( self::$unchecked_absent[ $blog_id ][ $name ] ) ) {
			return;
		}

		self::forget_unchecked_absent( $blog_id, [ $name ] );

		if ( wp_installing() ) {
			return;
		}

		$alloptions = wp_load_alloptions();

		if ( isset( $alloptions[ $name ] ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/**
	 * Remove names from a blog's unchecked absent options.
	 *
	 * @param int      $blog_id Blog id.
	 * @param string[] $names   Option names.
	 * @return void
	 */
	private static function forget_unchecked_absent( int $blog_id, array $names ): void {
		if ( ! isset( self::$unchecked_absent[ $blog_id ] ) ) {
			return;
		}

		foreach ( $names as $name ) {
			unset( self::$unchecked_absent[ $blog_id ][ $name ] );
		}

		if ( self::$unchecked_absent[ $blog_id ] === [] ) {
			unset( self::$unchecked_absent[ $blog_id ] );
		}
	}

	/**
	 * Empty this process's runtime object cache, as batch jobs do between batches to bound memory.
	 *
	 * Skipped when wp_cache_flush_runtime() is core's cache-compat stand-in, which calls wp_cache_flush().
	 *
	 * @return bool Whether the runtime cache was emptied.
	 */
	public static function flush_runtime(): bool {
		return self::drop_in_flushes_runtime() && self::empty_runtime();
	}

	/**
	 * Call wp_cache_flush_runtime(), whichever defines it.
	 *
	 * @return bool
	 */
	private static function empty_runtime(): bool {
		if ( ! function_exists( 'wp_cache_flush_runtime' ) || ! wp_cache_flush_runtime() ) {
			return false;
		}

		self::$runtime_flushed_at = did_filter( 'pre_wp_load_alloptions' );

		return true;
	}

	/**
	 * Release posts a loop has finished with from this process's runtime object cache; nothing is invalidated.
	 *
	 * A persistent cache cannot drop one key from memory only, so there flush_runtime() drops all.
	 *
	 * @param array<int, int> $post_ids Posts the loop has finished with.
	 * @return void
	 */
	public static function release_runtime_posts( array $post_ids ): void {
		if ( ! wp_using_ext_object_cache() ) {
			if ( $post_ids !== [] ) {
				wp_cache_delete_multiple( $post_ids, 'posts' );
			}

			return;
		}

		self::flush_runtime();
	}

	/**
	 * Whether wp_cache_flush_runtime() empties only the runtime cache: it is
	 * core's or the drop-in's own, not core's cache-compat.php stand-in.
	 *
	 * @return bool
	 */
	private static function drop_in_flushes_runtime(): bool {
		if ( self::$drop_in_flushes_runtime === null ) {
			self::$drop_in_flushes_runtime = false;

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				$file = ( new \ReflectionFunction( 'wp_cache_flush_runtime' ) )->getFileName();

				self::$drop_in_flushes_runtime = is_string( $file ) && basename( $file ) !== 'cache-compat.php';
			}
		}

		return self::$drop_in_flushes_runtime;
	}

	/**
	 * Record that this process dropped cached entries of the current blog.
	 *
	 * No query: announce_changes() raises the blog's change counter at the next checkpoint, job end or shutdown.
	 *
	 * @return void
	 */
	private static function note_change(): void {
		self::$changes_pending[ is_multisite() ? get_current_blog_id() : 0 ] = true;

		// The fatal error handler's wp_die() ends the process before later shutdown functions run, so the
		// error page's arguments filter announces first.
		if ( ! self::$announce_registered ) {
			self::$announce_registered = true;
			register_shutdown_function( [ self::class, 'announce_at_shutdown' ] );
			add_filter( 'wp_php_error_args', [ self::class, 'announce_before_error_page' ], 10, 1 );
		}
	}

	/**
	 * The shutdown announcement. An entry dropped by a shutdown function
	 * that runs after this one registers it again, so that change is
	 * announced too.
	 *
	 * @internal Public only for register_shutdown_function().
	 *
	 * @return void
	 */
	public static function announce_at_shutdown(): void {
		self::$announce_registered = false;

		self::announce_changes();
	}

	/**
	 * Announce before WordPress's fatal error page ends the process.
	 *
	 * @internal Public only for the wp_php_error_args filter.
	 *
	 * @param mixed $args The error page's arguments, returned unchanged.
	 * @return mixed
	 */
	public static function announce_before_error_page( mixed $args ): mixed {
		self::announce_changes();

		return $args;
	}

	/**
	 * Raise the change counter of each blog with unannounced entry drops, one write per blog.
	 *
	 * Another long-running process's checkpoint then sees invalidations no generation records. A blog whose
	 * write fails stays pending.
	 *
	 * @return bool Whether every pending blog was announced.
	 */
	public static function announce_changes(): bool {
		global $wpdb;

		if ( self::$changes_pending === [] ) {
			return true;
		}

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$autoload = function_exists( 'wp_determine_option_autoload_value' ) ? 'off' : 'no';
		$all      = true;

		foreach ( array_keys( self::$changes_pending ) as $blog ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter; read only by refresh_if_changed().
			$raised = $wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', %s) ON DUPLICATE KEY UPDATE option_value = option_value + 1", 0 === $blog ? $wpdb->options : $wpdb->get_blog_prefix( $blog ) . 'options', self::CHANGES_OPTION, $autoload ) ?? '' );

			if ( false === $raised ) {
				$all = false;

				continue;
			}

			unset( self::$changes_pending[ $blog ] );
			self::$changes_own[ $blog ] = ( self::$changes_own[ $blog ] ?? 0 ) + 1;
		}

		return $all;
	}

	/**
	 * Drop this process's in-memory copies when another process changed cached data.
	 *
	 * Long-running processes only, at checkpoints between batches; never on a page request. One query reads the
	 * generations and the change counter; see docs-ai/11-code-rationale.md, "CacheManager: checkpoints".
	 *
	 * @return bool Whether another process changed anything.
	 */
	public static function refresh_if_changed(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		self::announce_changes();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The generations as committed, past every in-process copy.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( self::GEN_OPTION_PREFIX ) . '%' ), ARRAY_A );

		// A failed read changes nothing: the next checkpoint compares with
		// the last reading that succeeded.
		if ( null === $rows || '' !== $wpdb->last_error ) {
			return false;
		}

		$committed = [];

		foreach ( $rows as $row ) {
			if ( isset( $row['option_name'], $row['option_value'] ) && is_string( $row['option_name'] ) && is_numeric( $row['option_value'] ) ) {
				$committed[ substr( $row['option_name'], strlen( self::GEN_OPTION_PREFIX ) ) ] = (int) $row['option_value'];
			}
		}

		// A group this process has not used takes the committed generation without counting as a change. The
		// change counter compares with the last reading plus this process's own raises since.
		$blog    = is_multisite() ? get_current_blog_id() : 0;
		$changed = false;
		$changes = $committed[ substr( self::CHANGES_OPTION, strlen( self::GEN_OPTION_PREFIX ) ) ] ?? 0;

		if ( isset( self::$changes_seen[ $blog ] ) && self::$changes_seen[ $blog ] + ( self::$changes_own[ $blog ] ?? 0 ) !== $changes ) {
			$changed = true;
		}

		self::$changes_seen[ $blog ] = $changes;
		unset( self::$changes_own[ $blog ] );

		if ( isset( self::$bumped_past[ $blog ] ) ) {
			$changed = true;
			unset( self::$bumped_past[ $blog ] );
		}

		foreach ( self::GROUPS as $group ) {
			$memo_key = $blog . ':' . $group;
			$now      = $committed[ $group ] ?? 0;

			if ( isset( self::$gen_memo[ $memo_key ] ) && self::$gen_memo[ $memo_key ] !== $now ) {
				$changed = true;
			}

			self::$gen_memo[ $memo_key ] = $now;
		}

		if ( ! $changed ) {
			return false;
		}

		// Also through core's stand-in: stale copies here are wrong data, not memory.
		self::empty_runtime();

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'cache' ) ) {
			$cache = $plugin->get( 'cache' );

			if ( $cache instanceof self ) {
				$cache->reset_static();
			}
		}

		\PerfLocale\Bootstrap::reset_static_state();
		\PerfLocale\Router\UrlConverter::reset_static_caches();
		\PerfLocale\Router\SlugManager::reset_static_caches();

		/**
		 * Fires when a long-running process's checkpoint found data another
		 * process changed and dropped its in-memory copies
		 * (CacheManager::refresh_if_changed()).
		 *
		 * @hook perflocale/cache/refreshed
		 */
		do_action( 'perflocale/cache/refreshed' );

		return true;
	}

	/**
	 * Whether flush_runtime() emptied the runtime cache and nothing has loaded alloptions since.
	 *
	 * Core's wp_load_alloptions() applies pre_wp_load_alloptions on every call, so its did_filter() count moves with each load.
	 *
	 * @return bool
	 */
	private static function alloptions_unloaded_since_flush(): bool {
		return null !== self::$runtime_flushed_at && did_filter( 'pre_wp_load_alloptions' ) === self::$runtime_flushed_at;
	}

	/**
	 * Reset only the request-scoped static cache; L2 and L3 stay.
	 *
	 * For switch_blog: the persistent layers are blog-scoped already, and no query runs (safe on uninitialised subsites).
	 *
	 * @return void
	 */
	public function reset_static(): void {
		$this->static_cache = [];
	}

	/**
	 * Current L2 generation for a group, memoised per process and blog.
	 *
	 * Bumping it orphans every L2 key of the group: wp_cache_flush_group() clears nothing on some backends. The
	 * option stays autoloaded so this read costs no query; see docs-ai/11-code-rationale.md, "CacheManager: generation tokens".
	 *
	 * @param string $group Cache group.
	 * @return int
	 */
	public static function l2_generation( string $group ): int {
		$memo_key = ( is_multisite() ? get_current_blog_id() : 0 ) . ':' . $group;

		return self::$gen_memo[ $memo_key ] ??= (int) get_option( self::GEN_OPTION_PREFIX . $group, 0 );
	}

	/**
	 * Build the generation-prefixed L2 key; a bump orphans the old prefix's entries.
	 *
	 * Code that calls wp_cache_*() on a PerfLocale group directly must build its key here, or no bump reaches it.
	 *
	 * @param string $key   Logical cache key.
	 * @param string $group Cache group.
	 * @return string
	 */
	public static function l2_key( string $key, string $group ): string {
		return 'g' . self::l2_generation( $group ) . ':' . $key;
	}

	/**
	 * The md5 ending a transient name keyed by a runtime value (the DNS answers): value plus default-group generation.
	 *
	 * The flush_all() bump of that generation retires such names even where a transient cannot be found by prefix.
	 *
	 * @param string $value The value the transient is keyed by.
	 * @return string
	 */
	public static function flush_scoped_hash( string $value ): string {
		return md5( self::l2_generation( self::GROUP ) . '|' . $value );
	}

	/**
	 * The generation bump of the group flushes (flush_*(), invalidate_group()).
	 *
	 * A written bump of a self::GROUPS group also announces this blog's pending entry drops: a checkpoint
	 * compares every generation. A bump inside a transaction uses bump_group_generation(), which announces nothing.
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	private function bump_generation( string $group ): void {
		if ( self::bump_group_generation( $group ) && in_array( $group, self::GROUPS, true ) ) {
			unset( self::$changes_pending[ is_multisite() ? get_current_blog_id() : 0 ] );
		}
	}

	/**
	 * Atomically increment a group's L2 generation in the database.
	 *
	 * Static so callers without a CacheManager instance (daily GC, SiteCleanup) can flush a group.
	 *
	 * @param string $group Cache group.
	 * @return bool Whether the increment was written.
	 */
	public static function bump_group_generation( string $group ): bool {
		global $wpdb;

		$option   = self::GEN_OPTION_PREFIX . $group;
		$blog     = is_multisite() ? get_current_blog_id() : 0;
		$memo_key = $blog . ':' . $group;
		$previous = self::$gen_memo[ $memo_key ] ?? null;

		// Atomic increment in the DB, never memo + 1: a long-lived worker's memo is stale, and memo + 1 would roll
		// the generation back and resurrect stale entries. Autoload stays 'yes' (the ON DUPLICATE branch never
		// touches it) so l2_generation() costs no query. A missing row starts at the clock in ms, not 1: after an
		// uninstall an unflushable cache still holds the earlier install's low generations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bumped = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %s, 'yes')
				 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$option,
				(string) (int) ( microtime( true ) * 1000 )
			)
		);

		// Memoise the committed value, read past the stale options cache (the row was written by raw SQL).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$committed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);

		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		// Unlist only a created row (1 affected; an update gives 2): an existing one is autoloaded, and get_option()
		// reads the alloptions dropped above before notoptions.
		if ( 1 === $bumped ) {
			\PerfLocale\Database\NotOptions::unlist( [ $option ] );
		}

		if ( null !== $previous && $committed !== $previous + 1 ) {
			self::$bumped_past[ $blog ] = true;
		}

		self::$gen_memo[ $memo_key ] = $committed;

		return false !== $bumped;
	}

	/**
	 * Invalidate a cache group across L1 and L2 on any object-cache backend, by a generation bump.
	 *
	 * @param string $group Cache group (one of self::GROUPS).
	 * @return void
	 */
	public function invalidate_group( string $group ): void {
		// L1: drop static entries for the group (keys are `[blog:]group:key`).
		$needle             = $group . ':';
		$this->static_cache = array_filter(
			$this->static_cache,
			static fn( $k ) => strpos( (string) $k, $needle ) === false,
			ARRAY_FILTER_USE_KEY
		);

		// L2: generation bump - reliable on every backend.
		$this->bump_generation( $group );

		// L3: generations cover only L2, so drop this group's transients by prefix; under a persistent object
		// cache L3 is never written.
		if ( ! wp_using_ext_object_cache() ) {
			$this->delete_group_transients( $group );
		}
	}

	/**
	 * Delete every L3 transient of a cache group (`perflocale_<group>_*`, as derive_transient_key() names them).
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	private function delete_group_transients( string $group ): void {
		$this->delete_transients_by_prefix( 'perflocale_' . $group . '_' );
	}

	/**
	 * Delete every L3 transient whose name starts with $transient_prefix, with its timeout twin.
	 *
	 * One SELECT and one bulk DELETE, never a per-row delete_transient() loop (thousands of hreflang rows on a
	 * crawled site). Names under a $keep prefix are neither deleted nor dropped from any cache.
	 *
	 * @param string       $transient_prefix Transient-name prefix (the option name without `_transient_`).
	 * @param list<string> $keep             Transient-name prefixes to keep.
	 * @return void
	 */
	private function delete_transients_by_prefix( string $transient_prefix, array $keep = [] ): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$prefix         = $wpdb->esc_like( '_transient_' . $transient_prefix ) . '%';
		$timeout_prefix = $wpdb->esc_like( '_transient_timeout_' . $transient_prefix ) . '%';
		$kept_values    = [];
		$kept_timeouts  = [];

		foreach ( $keep as $kept ) {
			$kept_values[]   = $wpdb->esc_like( '_transient_' . $kept ) . '%';
			$kept_timeouts[] = $wpdb->esc_like( '_transient_timeout_' . $kept ) . '%';
		}

		$not_values   = implode( '', array_fill( 0, count( $kept_values ), ' AND option_name NOT LIKE %s' ) );
		$not_timeouts = implode( '', array_fill( 0, count( $kept_timeouts ), ' AND option_name NOT LIKE %s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM %i WHERE option_name LIKE %s{$not_values}", $wpdb->options, $prefix, ...$kept_values )
		);

		// prepare() gives null only for a malformed statement; query('')
		// then runs nothing.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE ( option_name LIKE %s OR option_name LIKE %s ){$not_values}{$not_timeouts}",
				$wpdb->options,
				$prefix,
				$timeout_prefix,
				...$kept_values,
				...$kept_timeouts
			) ?? ''
		);
		// phpcs:enable

		// With a drop-in, get_transient() reads the shared `transient` group, where the hand-rolled transients
		// (Site Health, add-on boot list, DNS) live: each swept row's object-cache copy goes too.
		$ext_cache = wp_using_ext_object_cache();
		$swept     = [];

		foreach ( (array) $names as $name ) {
			$name      = (string) $name;
			$transient = substr( $name, strlen( '_transient_' ) );
			$swept[]   = $name;
			$swept[]   = '_transient_timeout_' . $transient;

			wp_cache_delete( $name, 'options' );
			wp_cache_delete( '_transient_timeout_' . $transient, 'options' );

			if ( $ext_cache ) {
				wp_cache_delete( $transient, 'transient' );
			}
		}

		wp_cache_delete( 'alloptions', 'options' );
		\PerfLocale\Database\NotOptions::unlist( $swept );
	}

	/**
	 * Flush all PerfLocale caches, and only those.
	 *
	 * The state transients in FLUSH_KEPT_TRANSIENTS survive; other plugins' and WordPress's keys are untouched.
	 *
	 * @return void
	 */
	public function flush_all(): void {
		// L1: Reset static cache.
		$this->static_cache = [];

		// L2: bump every group's generation - orphans all existing keys
		// on any backend, including one whose group flush does nothing.
		foreach ( self::GROUPS as $group ) {
			$this->bump_generation( $group );
		}

		// L3: every `perflocale_*` transient but the kept state ones, in one SELECT and one bulk DELETE: this runs
		// on deactivation, where a per-row loop can time out before `active_plugins` is updated. The bulk DELETE
		// also reaps rows written before a persistent object cache appeared; see docs-ai/11-code-rationale.md, "CacheManager: flush_all".
		$this->delete_transients_by_prefix( 'perflocale_', self::FLUSH_KEPT_TRANSIENTS );

		// Under a persistent object cache transients live only in its `transient` group, out of the sweep's
		// reach: the fixed-name ones go by name. The DNS answers are retired by the generation bump above.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete_multiple( self::FLUSH_DELETED_TRANSIENTS, 'transient' );
		} elseif ( wp_cache_supports( 'flush_group' ) ) {
			// This process's options cache only: it also holds rows another process deleted, which the sweep cannot select.
			wp_cache_flush_group( 'options' );
		}

		// L0: the eager link map is an autoloaded option that neither the bumps nor the sweep reach, and
		// prime_translations() reads it first. Deleted inline, not via invalidate_eager_link_map(): deactivation
		// must not build a repository. Keep this type list in sync with invalidate_eager_link_map().
		// delete_autoloaded_options() also drops an alloptions list a concurrent request re-stored with a map.
		self::delete_autoloaded_options(
			[
				'perflocale_eager_links_post',
				'perflocale_eager_links_term',
				'perflocale_eager_links_option',
				'perflocale_eager_links_string',
				'perflocale_eager_links_post_type',
				'perflocale_eager_links_taxonomy',
			]
		);

		// get_eager_link_map() reads a static memo before the option, and importers flush so their idempotency
		// checks see fresh rows. reset_static_caches() is static, so it is safe on teardown paths.
		\PerfLocale\Database\Repository\TranslationGroupRepository::reset_static_caches();

		/** @hook perflocale/cache/flush_all Fires after all caches are flushed. */
		do_action( 'perflocale/cache/flush_all' );
	}

	/**
	 * Flush caches for a specific object.
	 *
	 * Deletes its `translations_<type>_<id>` links from `perflocale_trans`, then fires `perflocale/cache/flush_object`.
	 *
	 * @param int    $object_id The object ID.
	 * @param string $object_type The object type (post, term, etc.).
	 * @return void
	 */
	public function flush_object( int $object_id, string $object_type ): void {
		$this->delete( "translations_{$object_type}_{$object_id}", 'perflocale_trans' );

		/** @hook perflocale/cache/flush_object Fires after object caches are flushed. */
		do_action( 'perflocale/cache/flush_object', $object_id, $object_type );
	}

	/**
	 * Flush all translated-slug caches for the current blog.
	 *
	 * Drops `perflocale_slugs` from every layer and the has_any_slugs() TRUE pin (option and raw L2 key). A
	 * same-request recompute also needs the in-process pin copies reset;
	 * see docs-ai/11-code-rationale.md, "CacheManager: has_any_slugs pin".
	 *
	 * @return void
	 */
	public function flush_slugs(): void {
		// L1: drop static entries of 'perflocale_slugs' (keys are `[blog_id:]group:key`).
		$this->static_cache = array_filter(
			$this->static_cache,
			static fn( $k ) => strpos( $k, 'perflocale_slugs:' ) === false,
			ARRAY_FILTER_USE_KEY
		);

		// L2: bump the group generation - reliable on every object-cache
		// backend, including one whose group flush does nothing.
		$this->bump_generation( 'perflocale_slugs' );

		// L3: bulk-delete the group's transients so a same-request get_slug() cannot return a deleted slug.
		// Md5-named long keys fall under the broader prefix that only flush_all() sweeps.
		$this->delete_group_transients( 'perflocale_slugs' );

		// Zero-state TRUE pin: the option and the raw L2 key (no l2_key(), so no bump reaches it) go together, or
		// a survivor short-circuits the recompute. Deleted even with L2 off: a key written while on is read again.
		delete_option( 'perflocale_has_any_slugs' );
		wp_cache_delete( 'has_any_slugs', 'perflocale_trans' );

		/** @hook perflocale/cache/flush_slugs Fires after slug caches are flushed. */
		do_action( 'perflocale/cache/flush_slugs' );
	}

	/**
	 * Flush every per-object translation-link cache for the current blog.
	 *
	 * For wholesale changes (a language delete) that remove link rows without naming each affected object.
	 *
	 * @return void
	 */
	public function flush_translations(): void {
		// L1: drop static entries in the 'perflocale_trans' group.
		$this->static_cache = array_filter(
			$this->static_cache,
			static fn( $k ) => strpos( $k, 'perflocale_trans:' ) === false,
			ARRAY_FILTER_USE_KEY
		);

		// L2: bump the group generation (reliable group-flush on any backend).
		$this->bump_generation( 'perflocale_trans' );

		// L3: bulk-delete the group's transients so a same-request read cannot use a stale one.
		$this->delete_group_transients( 'perflocale_trans' );

		/** @hook perflocale/cache/flush_translations Fires after translation-link caches are flushed. */
		do_action( 'perflocale/cache/flush_translations' );
	}

	/**
	 * Flush all language caches.
	 *
	 * @return void
	 */
	public function flush_languages(): void {
		// Bundled bootstrap blob - one transient feeds
		// LanguageRepository::get_active / get_default / get_slug_map.
		$this->delete( 'bootstrap', 'perflocale_langs' );

		// LanguageRepository::find_all()'s canonical-default key, invalidated with the bootstrap bundle.
		$this->delete( 'all_sorted', 'perflocale_langs' );

		// The whole `perflocale_langs` group, so per-id and per-slug keys go too (a slug rename would otherwise
		// keep the old slug's entry). L1 keys are `[blog_id:]group:key`, so match the group anywhere.
		$this->static_cache = array_filter(
			$this->static_cache,
			static fn( $k ) => strpos( $k, 'perflocale_langs:' ) === false,
			ARRAY_FILTER_USE_KEY
		);

		$this->bump_generation( 'perflocale_langs' );

		// Cached hreflang chunks depend on the language set.
		$this->bump_generation( 'perflocale_hreflang' );

		// L3 for both groups: a stale envelope left after a slug rename would be read back through L3.
		// delete_group_transients() derives the doubled `perflocale_perflocale_*` prefix; a hand-built one misses.
		$this->delete_group_transients( 'perflocale_langs' );
		$this->delete_group_transients( 'perflocale_hreflang' );
	}

	/**
	 * Get a value from L1 static cache only (no fallback).
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return mixed|null Null if not in static cache.
	 */
	public function get_static( string $key, string $group = self::GROUP ): mixed {
		$full_key = $this->make_static_key( $group, $key );

		return $this->static_cache[ $full_key ] ?? null;
	}

	/**
	 * Set a value in L1 static cache only.
	 *
	 * Useful for preloaded data that should not be persisted.
	 *
	 * @param string $key Cache key.
	 * @param mixed  $value Value.
	 * @param string $group Cache group.
	 * @return void
	 */
	public function set_static( string $key, mixed $value, string $group = self::GROUP ): void {
		$full_key = $this->make_static_key( $group, $key );

		$this->ensure_static_capacity( $full_key );
		$this->static_cache[ $full_key ] = $value;
	}

	/**
	 * Whether L2 is active: the setting allows it and a persistent object cache is in place.
	 *
	 * @return bool
	 */
	public function l2_enabled(): bool {
		return $this->object_cache_enabled && wp_using_ext_object_cache();
	}

	/**
	 * Evict the oldest 25% of L1 entries when the cap is reached and $full_key is new.
	 *
	 * Runs on every L1 insert path, including L2/L3 hit promotions: a warm long-lived process inserts mostly by
	 * promotion.
	 *
	 * @param string $full_key Full static cache key about to be written.
	 * @return void
	 */
	private function ensure_static_capacity( string $full_key ): void {
		if ( count( $this->static_cache ) >= self::MAX_STATIC_ENTRIES && ! isset( $this->static_cache[ $full_key ] ) ) {
			// Remove the oldest 25% of entries.
			$this->static_cache = array_slice( $this->static_cache, (int) ( self::MAX_STATIC_ENTRIES * 0.25 ), null, true );
		}
	}

	/**
	 * Build a static cache key with multisite blog ID prefix.
	 *
	 * @param string $group Cache group.
	 * @param string $key Cache key.
	 * @return string Full static cache key.
	 */
	private function make_static_key( string $group, string $key ): string {
		$prefix = is_multisite() ? get_current_blog_id() . ':' : '';

		return $prefix . $group . ':' . $key;
	}

	/**
	 * Generate a transient key under the 172-character transient name limit.
	 *
	 * Public so direct-options prime paths (TranslationGroupRepository) build the exact same key.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return string
	 */
	public function derive_transient_key( string $key, string $group ): string {
		$full = 'perflocale_' . $group . '_' . $key;

		if ( strlen( $full ) > 160 ) {
			return 'perflocale_' . md5( $group . '_' . $key );
		}

		return $full;
	}

	/**
	 * Internal alias of derive_transient_key().
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return string
	 */
	private function transient_key( string $key, string $group ): string {
		return $this->derive_transient_key( $key, $group );
	}
}
