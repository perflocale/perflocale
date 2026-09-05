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
 * Implements a three-layer caching strategy:
 *
 * L1: Static PHP array (per-request, zero cost).
 * L2: WordPress Object Cache (persistent with Redis/Memcached).
 * L3: Transients (fallback, stored in wp_options).
 *
 * Every cache read cascades: L1 → L2 → L3 → loader callable.
 * Every cache write populates all layers.
 */
final class CacheManager {

	/**
	 * Default cache group.
	 */
	private const GROUP = 'perflocale';

	/**
	 * Every cache group the plugin uses. flush_all() iterates this set so
	 * a persistent object cache (Redis, Memcached) gets every plugin-owned
	 * key dropped — missing a group here strands stale entries past the
	 * flush until each entry's TTL expires.
	 *
	 * Add a group here when you introduce a new one; do NOT call
	 * `wp_cache_*( …, 'perflocale_xxx' )` against a group that isn't in
	 * this list, or `flush_all()` won't reach it.
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
	 * Maximum number of entries in the L1 static cache.
	 * 5000 accommodates sites with many translated strings (3000+ gettext calls/page)
	 * without triggering frequent eviction.
	 */
	private const MAX_STATIC_ENTRIES = 5000;

	/**
	 * Autoloaded-option name prefix for per-group L2 generations.
	 */
	private const GEN_OPTION_PREFIX = 'perflocale_cgen_';

	/**
	 * Ceilings for ONE batched L3 INSERT in set_many(). Whichever trips
	 * first flushes the batch.
	 *
	 * The byte ceiling is what actually matters: MySQL rejects any statement
	 * over `max_allowed_packet` AND drops the connection, and the floor
	 * still seen on shared hosting is 1 MB (MySQL's own default through
	 * 5.6). The budget counts RAW key+payload bytes, so it has to leave room
	 * for the escaping wpdb::prepare() applies: serialized PHP is quote-heavy
	 * and measured ~13% larger once escaped, while an adversarial
	 * all-quotes payload can only double. 256 KB keeps even that worst case
	 * at half the 1 MB floor. A fixed budget is deliberate — sizing against
	 * `SELECT @@max_allowed_packet` would add a query to a write path (or
	 * need a cache of its own) to learn a number this is already far below.
	 *
	 * The row ceiling bounds the other axis: thousands of tiny entries (an
	 * empty link list serializes to ~40 bytes) would otherwise build one
	 * statement with tens of thousands of placeholders.
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
	 * @return void
	 */
	public function register_hooks(): void {
		// Nothing to hook - the cache manager is a passive service.
		// CacheInvalidator handles the hook-based invalidation.
	}

	/**
	 * Get a value from the cache, computing it if not found.
	 *
	 * Cascades through all 3 layers. If the value is not found in any layer,
	 * the $loader callable is invoked and the result is stored in all layers.
	 *
	 * @param string   $key Cache key.
	 * @param callable $loader Callable that computes the value on cache miss.
	 * @param int      $ttl Time-to-live in seconds (for L2 and L3).
	 * @param string   $group Cache group (default: 'perflocale').
	 * @return mixed
	 */
	public function get( string $key, callable $loader, int $ttl = HOUR_IN_SECONDS, string $group = self::GROUP ): mixed {
		// L1: Static cache (fastest).
		// Include blog ID for multisite to prevent cross-site cache pollution.
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

		// L3: Transients.
		// Transients cannot store null or false reliably (get_transient returns
		// false on miss AND for stored false/null values). We wrap values in an
		// array envelope to preserve the original type.
		// Skip entirely under a persistent external object cache: set() skips
		// the L3 write in that case (transients route to wp_cache's shared
		// `transient` group, duplicating L2), so reading L3 here is a wasted
		// round-trip against the same backend. Mirrors set()'s L3 write guard.
		if ( ! wp_using_ext_object_cache() ) {
			$transient_key = $this->transient_key( $key, $group );
			$envelope      = get_transient( $transient_key );

			if ( is_array( $envelope ) && array_key_exists( 'v', $envelope ) ) {
				$value = $envelope['v'];
				$this->ensure_static_capacity( $full_key );
				$this->static_cache[ $full_key ] = $value;

				// Promote to L2 only when L2 reads are actually enabled — the
				// read path above is gated on object_cache_enabled, so writing
				// here with it disabled just stores a key nothing ever reads.
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
	 * Read a value from the cache layers WITHOUT computing or writing on miss.
	 *
	 * Unlike get(), there is no loader and a miss writes nothing — use this when
	 * a miss must fall through to a non-cacheable path (e.g. a per-key lookup
	 * whose negative results must not be persisted, which would otherwise flood
	 * storage). Mirrors get()'s L1->L2->L3 read and L1 promotion on hit; returns
	 * $default on a full miss.
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

		// L3 — wrap in ['v' => $value] so we can tell a stored null/false from
		// get_transient()'s "not found" false. Skip L3 entirely under a
		// persistent object cache: set_transient() would route to wp_cache's
		// WP-wide "transient" group (shared with other plugins, so flush_all
		// can't safely wipe it), which none of the plugin's group flushes can
		// reach — the wp_options sweeps would find zero rows and every group
		// invalidation would silently no-op. Without ext cache, L3 goes to
		// wp_options as normal.
		if ( ! wp_using_ext_object_cache() ) {
			$transient_key = $this->transient_key( $key, $group );
			set_transient( $transient_key, [ 'v' => $value ], $ttl );
		}
	}

	/**
	 * Write many entries to L1+L2+L3 in one shot.
	 *
	 * Equivalent to looping `set()` over `$entries`, but collapses the
	 * L3 transient writes into multi-row `INSERT ... ON DUPLICATE KEY
	 * UPDATE` statements. The default `set_transient()` path does TWO
	 * `update_option` calls per entry (data + timeout), so writing N
	 * entries costs `N × 2` round-trips. On `prime_translations()`'s cold
	 * path that's 24 round-trips per page render (12 posts × 2 options
	 * each) — measurably the dominant cost when no Redis is in play.
	 * Batched, that work is one query (~150 µs vs ~1.5-2 ms); a set that
	 * small never comes near the ceilings below.
	 *
	 * Batches are bounded by L3_INSERT_MAX_ROWS / L3_INSERT_MAX_BYTES: a
	 * genuine sitemap prime (3,400 groups) built a SINGLE 5.9 MiB INSERT,
	 * which a host with the old 1 MB `max_allowed_packet` default answers
	 * by rejecting the statement AND closing the connection mid-request.
	 *
	 * @param array<string, mixed> $entries map of cache_key => value
	 * @param int                  $ttl
	 * @param string               $group
	 * @return void
	 */
	public function set_many( array $entries, int $ttl = HOUR_IN_SECONDS, string $group = self::GROUP ): void {
		if ( $entries === [] ) {
			return;
		}

		// L1 — evict oldest entries if at capacity, then write each one.
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

		// Re-assert the bound AFTER the batch. The pre-emptive slice above frees
		// a FIXED 25% (1,250 slots) and is then never re-checked, so any batch
		// bigger than that — or a warm cache plus a mid-sized batch — lands over
		// MAX_STATIC_ENTRIES and stays there: measured 6,000 retained from one
		// 6,000-entry batch and 10,750 after a second disjoint one, against a
		// documented 5,000 hard bound, and 5,151 from a real cold prime. One
		// array_slice, O(N), at most once per call — not per entry, no sort.
		// Drops the OLDEST keys so the entries this call just wrote (the current
		// render's working set) survive, matching ensure_static_capacity()'s
		// direction on the single-entry path. L1 is request-local and every
		// entry still goes to L2/L3 below, so an evicted key is a miss that
		// re-reads, never a wrong answer.
		$overflow = count( $this->static_cache ) - self::MAX_STATIC_ENTRIES;

		if ( $overflow > 0 ) {
			$this->static_cache = array_slice( $this->static_cache, $overflow, null, true );
		}

		// L2 — wp_cache_set per entry. There's no batched wp_cache API in WP
		// core (wp_cache_set_multiple exists only on WP 6.0+ AND only when
		// the active object cache backend implements it), so a tight foreach
		// is the safe portable path. Each call is sub-microsecond on the
		// non-persistent default cache; Redis backends are typically pipelined
		// internally too. Skipped entirely when the setting disables L2.
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

		// L3 — single multi-row INSERT instead of N × 2 update_option calls.
		// Skip entirely when a persistent external object cache is active
		// (Redis et al.): get_transient() reads that backend, not wp_options,
		// so rows written here would be unreadable dead weight.
		if ( wp_using_ext_object_cache() ) {
			return;
		}

		// We INTENTIONALLY skip the `_transient_timeout_*` companion option
		// that WP's set_transient() writes. CacheInvalidator does explicit
		// purges on every event that could stale these values, so the
		// transient mechanism's auto-expiration is unused — writing the
		// timeout row would just double the wp_options inserts. With
		// timeout missing, WP's get_transient() falls through to "no
		// expiration set; return the value" — exactly what we want.
		$option_names  = [];
		$values_clause = [];
		$args          = [];
		$batch_bytes   = 0;

		foreach ( $entries as $key => $value ) {
			$transient_id = $this->derive_transient_key( (string) $key, $group );
			$data_name    = '_transient_' . $transient_id;
			$serialized   = (string) maybe_serialize( [ 'v' => $value ] );
			$row_bytes    = strlen( $data_name ) + strlen( $serialized );

			// Flush BEFORE appending the row that would breach a ceiling,
			// never after, so every emitted statement is inside budget. A
			// lone row bigger than the budget still goes out on its own —
			// one row can't be split, and set_transient() would hit the
			// same packet wall with it.
			if (
				$values_clause !== []
				&& ( count( $values_clause ) >= self::L3_INSERT_MAX_ROWS
					|| $batch_bytes + $row_bytes > self::L3_INSERT_MAX_BYTES )
			) {
				$this->insert_transient_rows( $values_clause, $args );

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
		}

		if ( $values_clause !== [] ) {
			$this->insert_transient_rows( $values_clause, $args );
		}

		// Wipe the WP options cache for the keys we just touched — direct
		// DB writes don't go through update_option(), so the "options"
		// wp_cache group can hold a stale positive value for one of these
		// names that another request had just read.
		foreach ( $option_names as $name ) {
			wp_cache_delete( $name, 'options' );
		}

		// notoptions is WP's negative cache: a single array blob under the
		// 'options' group's 'notoptions' key listing every option name
		// that's known to NOT exist. We just INSERTed L3 transient rows
		// for these names, so any entry in the blob claiming they don't
		// exist is now wrong. The previous read-modify-write pattern
		// (wp_cache_get → unset() the names → wp_cache_set) had a TOCTOU
		// race: two concurrent set_many() calls could each capture the
		// blob, unset different name sets, and the second writer's set()
		// would overwrite the first's modifications — leaving some names
		// still marked as missing, which causes get_transient() to return
		// false even though the row is present in wp_options. That
		// surfaces as a permanently-stale negative cache until WP's next
		// options flush.
		//
		// Race-free fix: drop the entire notoptions blob in one atomic
		// wp_cache_delete(). Marginal cost — the next get_option() call
		// for any non-existent option rebuilds the blob naturally from a
		// single DB lookup (sub-millisecond). In exchange we eliminate
		// the race window entirely. The same trade-off applies under
		// every persistent object-cache backend (Redis et al.): atomic
		// delete is universally supported, atomic read-modify-write is
		// not.
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Emit ONE batched L3 transient INSERT.
	 *
	 * Split out of set_many() so its row/byte ceilings have a single place
	 * to flush to. `ON DUPLICATE KEY UPDATE` keeps the write idempotent for
	 * keys that already hold a row, which is what makes chunking safe: the
	 * batches are independent statements, so a re-primed key overwrites
	 * rather than erroring on the unique option_name index.
	 *
	 * @param string[]     $values_clause One `(%s, %s, %s)` tuple per row.
	 * @param array<mixed> $args Flattened bind values matching $values_clause.
	 * @return void
	 */
	private function insert_transient_rows( array $values_clause, array $args ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES "
			. implode( ',', $values_clause )
			. ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)';

		$wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		// phpcs:enable
	}

	/**
	 * Delete a value from all cache layers.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return void
	 */
	public function delete( string $key, string $group = self::GROUP ): void {
		$full_key = $this->make_static_key( $group, $key );

		unset( $this->static_cache[ $full_key ] );

		if ( $this->object_cache_enabled ) {
			wp_cache_delete( self::l2_key( $key, $group ), $group );
		}

		delete_transient( $this->transient_key( $key, $group ) );
	}

	/**
	 * Reset only the request-scoped static cache - leaves L2 (wp_cache)
	 * and L3 (transient) intact.
	 *
	 * Intended for multisite `switch_blog` handling: in-memory caches keyed
	 * by the old blog's context must not leak into the new blog, but the
	 * persistent layers are already blog-scoped by WP, so there's no need
	 * to touch them. Also safe on uninitialised subsites where our tables
	 * don't exist yet - this method never runs a DB query.
	 *
	 * @return void
	 */
	public function reset_static(): void {
		$this->static_cache = [];
	}

	/**
	 * Current L2 generation for a group.
	 *
	 * Bumping it (see bump_group_generation()) orphans every existing L2 key
	 * in the group — the plugin's reliable group-flush primitive. It does NOT
	 * depend on wp_cache_flush_group(), which silently no-ops on common
	 * object-cache backends (e.g. Redis Object Cache driven by Predis:
	 * wp_cache_supports('flush_group') returns true yet flush_group() clears
	 * nothing). The generation lives in an autoloaded option (survives across
	 * requests) and is memoised per process in self::$gen_memo to avoid a
	 * get_option() on every L2 access — measured at ~1.6 µs each, which is ~3×
	 * a warm wp_cache_get() and adds up across the dozens of cache touches on a
	 * page render. Every bump (instance OR static) updates the memo, so an
	 * in-process flush is always observed; only a CONCURRENT flush in a
	 * separate request goes unseen until this (usually short) request ends —
	 * an acceptable, bounded window given language flushes are rare. Keyed by
	 * blog so switch_to_blog() needs no reset (the option itself is per-blog).
	 *
	 * @param string $group Cache group.
	 * @return int
	 */
	public static function l2_generation( string $group ): int {
		$memo_key = ( is_multisite() ? get_current_blog_id() : 0 ) . ':' . $group;

		return self::$gen_memo[ $memo_key ] ??= (int) get_option( self::GEN_OPTION_PREFIX . $group, 0 );
	}

	/**
	 * Build the generation-prefixed L2 (object-cache) key. The prefix changes
	 * when the group's generation is bumped, so prior entries are orphaned and
	 * aged out by TTL while fresh reads miss and recompute.
	 *
	 * PUBLIC + static: code that reads/writes a PerfLocale L2 group DIRECTLY
	 * via wp_cache_*() (the prime_translations batch fast-path,
	 * Glossary) MUST route its key through here, or it would address
	 * a different key space than CacheManager and miss every entry (and never
	 * be reached by a generation bump).
	 *
	 * @param string $key   Logical cache key.
	 * @param string $group Cache group.
	 * @return string
	 */
	public static function l2_key( string $key, string $group ): string {
		return 'g' . self::l2_generation( $group ) . ':' . $key;
	}

	/**
	 * Instance shim for the static generation bump (used by flush_*()).
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	private function bump_generation( string $group ): void {
		self::bump_group_generation( $group );
	}

	/**
	 * Increment a group's L2 generation. Static so callers that lack a live
	 * CacheManager instance (the daily GC,
	 * SiteCleanup) can flush a group reliably too. State lives entirely in the
	 * autoloaded option, so there is no instance state to keep in sync.
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	public static function bump_group_generation( string $group ): void {
		global $wpdb;

		$option   = self::GEN_OPTION_PREFIX . $group;
		$memo_key = ( is_multisite() ? get_current_blog_id() : 0 ) . ':' . $group;

		// Increment in the DB, never memo+1. l2_generation() pins the
		// generation for the whole PHP process, so a long-lived worker
		// (BulkTranslateJob, SiteTranslateJob, DataImportJob, StringScanJob —
		// each a single multi-minute invocation) still holds the value it read
		// at start. Computing next = memo + 1 therefore OVERWRITES every
		// generation other requests committed in the meantime, rolling the
		// counter BACKWARD — which re-points l2_key() at an already-populated
		// keyspace and resurrects stale entries site-wide until their TTL.
		// INSERT .. ON DUPLICATE KEY UPDATE makes the increment atomic under
		// the row lock, so generations are strictly monotonic no matter how
		// many processes bump concurrently. Mirrors the MT usage counter in
		// AbstractProvider::track_usage_chars(), which solved the same class.
		//
		// autoload stays 'yes' — these options are deliberately autoloaded so
		// l2_generation() costs no query on the read path (see its docblock);
		// the ON DUPLICATE branch must not touch the autoload column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, '1', 'yes')
				 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$option
			)
		);

		// Read the committed value back, bypassing the options cache (the row
		// was written with raw SQL, so the cached copy is stale). Memoise THAT
		// — not a locally-computed guess — so this process addresses the same
		// keyspace as everyone else for the rest of its run.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$committed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);

		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		self::$gen_memo[ $memo_key ] = $committed;
	}

	/**
	 * Reliably invalidate an entire cache group across L1 + L2 on ANY
	 * object-cache backend. Public entry point for callers that previously
	 * relied on wp_cache_flush_group() directly. L2 is invalidated by bumping
	 * the group generation rather than wp_cache_flush_group().
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

		// L2: generation bump — reliable on every backend.
		self::bump_group_generation( $group );

		// L3: without a persistent object cache, values live as transients in
		// wp_options (generation-versioning only covers L2). Drop this group's
		// transients by their derived prefix so a same-request read can't
		// satisfy from a stale L3 envelope. No-op under a persistent cache
		// (L3 is never written there).
		if ( ! wp_using_ext_object_cache() ) {
			$this->delete_group_transients( $group );
		}
	}

	/**
	 * Delete every L3 transient belonging to a cache group, matching the
	 * exact name derive_transient_key() produces (`perflocale_<group>_*`).
	 *
	 * @param string $group Cache group.
	 * @return void
	 */
	private function delete_group_transients( string $group ): void {
		$this->delete_transients_by_prefix( 'perflocale_' . $group . '_' );
	}

	/**
	 * Delete every L3 transient whose name starts with $transient_prefix —
	 * the data row AND the `_transient_timeout_` twin WP writes beside it.
	 *
	 * ONE SELECT + ONE bulk DELETE + per-name cache busts — never a per-row
	 * delete_transient() loop. Each delete_transient() costs ~3 queries
	 * (SELECT + DELETE for the data row, DELETE for the timeout row), so on
	 * a crawled site holding thousands of per-URL hreflang transients the
	 * loop turned a single term edit into a multi-second admin request. The
	 * wp_cache_delete() calls are pure memory ops without a drop-in and keep
	 * same-request get_transient() reads coherent after the raw SQL.
	 *
	 * @param string $transient_prefix Transient-name prefix, i.e. the option
	 *                                 name WITHOUT its `_transient_` prefix.
	 * @return void
	 */
	private function delete_transients_by_prefix( string $transient_prefix ): void {
		global $wpdb;

		$prefix         = $wpdb->esc_like( '_transient_' . $transient_prefix ) . '%';
		$timeout_prefix = $wpdb->esc_like( '_transient_timeout_' . $transient_prefix ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix )
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$prefix,
				$timeout_prefix
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// With a drop-in installed get_transient() reads the object cache's
		// shared `transient` group instead of wp_options. CacheManager never
		// writes there (set()/set_many() skip L3 in that mode), but the
		// plugin's hand-rolled transients do — the strings-regenerating flag,
		// the per-user admin notices, Breaker state — and the rows we just
		// deleted are the pre-drop-in twins of exactly those names, which the
		// old delete_transient() loop used to clear. Keep that behaviour. The
		// set shrinks to nothing after the first sweep (those rows are gone
		// for good), so this costs one round-trip per legacy row, once.
		$ext_cache = wp_using_ext_object_cache();

		foreach ( (array) $names as $name ) {
			$name      = (string) $name;
			$transient = substr( $name, strlen( '_transient_' ) );

			wp_cache_delete( $name, 'options' );
			wp_cache_delete( '_transient_timeout_' . $transient, 'options' );

			if ( $ext_cache ) {
				wp_cache_delete( $transient, 'transient' );
			}
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Flush all PerfLocale caches.
	 *
	 * @return void
	 */
	public function flush_all(): void {
		// L1: Reset static cache.
		$this->static_cache = [];

		// L2: bump every group's generation — orphans all existing keys
		// reliably on any backend. wp_cache_flush_group() is a no-op on
		// common ones (e.g. Redis Object Cache + Predis), which would
		// otherwise strand stale entries until each entry's TTL expires.
		foreach ( self::GROUPS as $group ) {
			$this->bump_generation( $group );
		}

		// L3: every `perflocale_*` transient in one SELECT + one bulk DELETE,
		// with the per-name cache busts that keep get_transient() from reading
		// through to a value we just removed (the reason the original code
		// looped delete_transient() instead of issuing a wildcard DELETE —
		// delete_transients_by_prefix() does both, so the loop is no longer
		// the price of correctness).
		//
		// The loop was unbounded and cost ~3 queries + ~1 ms PER ROW: measured
		// at 30,603 queries / 21.4 s for the 9,061 cached entries on a 10k-post
		// site, where the whole of flush_all() now runs in 18 queries / 0.5 s
		// (2 of those queries are this L3 sweep). flush_all() runs
		// from deactivation, the Migrator's upgrade path and the admin
		// Clear-cache button, none of which raise max_execution_time — and
		// `deactivate_{$plugin}` fires BEFORE update_option('active_plugins'),
		// so timing out there leaves the plugin active with its cron
		// unscheduled, rewrite_rules deleted and the Translator role stripped.
		//
		// It also closes a leak: with a persistent object cache installed,
		// delete_transient() drops the object-cache entry and deletes NO
		// wp_options row, so L3 rows written before the drop-in appeared
		// survived every flush forever (5,659 of them non-expiring on that
		// same site). The bulk DELETE reaps them.
		$this->delete_transients_by_prefix( 'perflocale_' );

		// L0: the eager link map is a plain autoloaded option, not a
		// transient, so neither the generation bumps above nor the L3 sweep
		// touch it — and prime_translations() consults it BEFORE any of
		// them (its "Step 0 fast-path"), which makes it the effective source
		// of truth for the language columns it denormalises. A "Clear cache"
		// that leaves it in place keeps serving the slugs and names from
		// before whatever the operator was trying to fix, leaving that state
		// with no user-reachable recovery at all. Pure invalidation: the map
		// rebuilds lazily in TranslationGroupRepository::get_eager_link_map(),
		// which re-persists it with autoload still on. Five option deletes,
		// paid only where flush_all() itself already runs — the admin
		// Clear-cache button, WP-CLI, deactivation and the version-gated
		// one-shot in Migrator::maybe_update() — never on a steady-state
		// request.
		//
		// Deleted inline rather than through
		// TranslationGroupRepository::invalidate_eager_link_map() because
		// flush_all() also runs on deactivation/teardown paths where
		// constructing a repository is undesirable, and that method
		// additionally bumps `perflocale_found_rows`, which the generation
		// loop above already covers. KEEP THIS TYPE LIST IN SYNC WITH
		// invalidate_eager_link_map().
		foreach ( [ 'post', 'term', 'string', 'post_type', 'taxonomy' ] as $eager_type ) {
			$eager_option = 'perflocale_eager_links_' . $eager_type;

			delete_option( $eager_option );
			// delete_option() returns early WITHOUT clearing any cache when
			// the row is already gone, which would strand a stale map in the
			// options cache; purge unconditionally.
			wp_cache_delete( $eager_option, 'options' );
		}

		// Deleting the rows is only half of it: get_eager_link_map() consults
		// a per-process static memo BEFORE the option, so inside the flushing
		// request the map would be neither cleared nor rebuilt and every
		// subsequent read would keep answering from pre-flush data. That is
		// not theoretical — the bulk-term-translate handler and the
		// TranslatePress importer both flush precisely so their own
		// idempotency checks see fresh rows. reset_static_caches() is a pure
		// static (no repository instance, so it stays safe on the
		// deactivation/teardown paths this method also serves) and clears the
		// same four in-process memos a "clear everything" is asking for.
		\PerfLocale\Database\Repository\TranslationGroupRepository::reset_static_caches();

		/** @hook perflocale/cache/flush_all Fires after all caches are flushed. */
		do_action( 'perflocale/cache/flush_all' );
	}

	/**
	 * Flush caches for a specific object.
	 *
	 * @param int    $object_id The object ID.
	 * @param string $object_type The object type (post, term, etc.).
	 * @return void
	 */
	public function flush_object( int $object_id, string $object_type ): void {
		$this->delete( "translation_group_{$object_type}_{$object_id}", 'perflocale_trans' );
		$this->delete( "translations_{$object_type}_{$object_id}", 'perflocale_trans' );

		/** @hook perflocale/cache/flush_object Fires after object caches are flushed. */
		do_action( 'perflocale/cache/flush_object', $object_id, $object_type );
	}

	/**
	 * Flush all translated-slug caches for the current blog.
	 *
	 * Mirrors flush_languages() but for SlugTranslationRepository's three
	 * caching layers:
	 *   1. Static L1 memo (the in-process $this->static_cache map, keyed
	 *      `[blog_id:]perflocale_slugs:slug_<type>_<id>_<lang>` — drop
	 *      every entry whose key contains the group name).
	 *   2. Persistent L2 (wp_cache group `perflocale_slugs`).
	 *   3. Autoloaded zero-state flag `perflocale_has_any_slugs`. This
	 *      option is written to `'1'` when the FIRST slug row appears and
	 *      is never cleared during normal writes. After a wholesale slug
	 *      delete (e.g. when a language is removed and all its slug rows
	 *      go with it) the flag may be wrong; deleting it forces the next
	 *      has_any_slugs() call to recompute from a single LIMIT 1 query.
	 *
	 * Called from LanguageRepository::delete() after the slug_translations
	 * cascade. Routing would otherwise resolve cached slug→object_id
	 * lookups for a language that no longer exists, and has_any_slugs()
	 * would keep paying the SELECT cost when the table is now empty.
	 *
	 * @return void
	 */
	public function flush_slugs(): void {
		// L1: drop static entries in the 'perflocale_slugs' group. Match
		// the substring anywhere because keys are `[blog_id:]group:key`.
		$this->static_cache = array_filter(
			$this->static_cache,
			static fn( $k ) => strpos( $k, 'perflocale_slugs:' ) === false,
			ARRAY_FILTER_USE_KEY
		);

		// L2: bump the group generation — reliable on every object-cache
		// backend (unlike wp_cache_flush_group(), which no-ops on Redis
		// Object Cache + Predis even though wp_cache_supports() claims it).
		$this->bump_generation( 'perflocale_slugs' );

		// L3: same shape as flush_languages() — one bulk sweep of the group's
		// transient rows (data + timeout twin), plus the per-name cache busts
		// that keep WP's options cache, which get_transient() reads through,
		// from serving a value the DELETE just removed. Without this, a
		// same-request get_slug() would still satisfy from the transient and
		// return the now-deleted language's slug. Transient names follow
		// derive_transient_key(): for group 'perflocale_slugs' and key
		// 'slug_<type>_<id>_<lang>' the rows are
		// `_transient_perflocale_perflocale_slugs_*` (plus an md5 fallback
		// when the long-key threshold trips — those land under the broader
		// `_transient_perflocale_*` prefix, but only flush_all() needs to
		// chase those, and the cost of leaking a tiny number of md5-named
		// rows on language delete is bounded by the per-language slug count).
		$this->delete_group_transients( 'perflocale_slugs' );

		// Zero-state flag: defensive delete (no harm if it was already
		// absent; next has_any_slugs() rebuilds it from a SELECT 1 LIMIT 1
		// when at least one slug row remains, or stays absent otherwise).
		delete_option( 'perflocale_has_any_slugs' );

		/** @hook perflocale/cache/flush_slugs Fires after slug caches are flushed. */
		do_action( 'perflocale/cache/flush_slugs' );
	}

	/**
	 * Flush every per-object translation-link cache for the current blog.
	 *
	 * Mirrors flush_slugs() for the `perflocale_trans` group, which backs
	 * get_translations()/flush_object() (`translations_<type>_<id>` +
	 * `translation_group_<type>_<id>`). Used when a wholesale change (e.g. a
	 * language delete) removes link rows without enumerating each affected
	 * sibling object, so per-object invalidation can't be targeted.
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

		// L3: bulk-delete the group's transient rows so a same-request
		// get_translations() can't satisfy from a stale transient. Mirrors
		// flush_slugs()'s L3 handling.
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

		// LanguageRepository::find_all() canonical-default path uses this
		// key. Must invalidate alongside the bootstrap bundle.
		$this->delete( 'all_sorted', 'perflocale_langs' );

		// Group-level flush of `perflocale_langs` so dynamic per-id
		// (`language_<id>`) and per-slug (`language_slug_<slug>`) keys go too —
		// else a slug rename leaves `language_slug_en` alive in a persistent
		// cache and find_by_slug('en') returns the pre-rename object until TTL.
		// Also clears the L1 static cache: keys are `[blog_id:]group:key`, so
		// match the `perflocale_langs:` substring anywhere (blog-id on multisite).
		$this->static_cache = array_filter(
			$this->static_cache,
			static fn( $k ) => strpos( $k, 'perflocale_langs:' ) === false,
			ARRAY_FILTER_USE_KEY
		);

		$this->bump_generation( 'perflocale_langs' );

		// Per-page hreflang HTML chunks are language-set-dependent and
		// cached at 6h TTL. Without this invalidation, adding/removing/
		// renaming a language leaves stale hreflang on every cached page
		// for up to 6 hours.
		$this->bump_generation( 'perflocale_hreflang' );

		// L3 transient cleanup for two groups: perflocale_langs (language-data
		// layer) and perflocale_hreflang (per-page chunks). A slug rename that
		// clears L1+L2 but leaves a stale envelope (`['v' => null]`) in the
		// options table would have the next request read it via L3 and return
		// the wrong type — a find_by_slug() fatal on persistent-cache sites.
		// delete_group_transients derives the doubled `perflocale_perflocale_*`
		// prefix — a hand-built pattern here once used the single-prefix form
		// for hreflang and matched ZERO rows, so language add/delete/rename
		// served stale hreflang for up to 12h on non-persistent-cache sites.
		$this->delete_group_transients( 'perflocale_langs' );
		$this->delete_group_transients( 'perflocale_hreflang' );
	}

	/**
	 * Get a value from L1 static cache only (no fallback).
	 *
	 * Useful for checking if a value has been loaded in this request
	 * without triggering L2/L3 lookups.
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
	 * Whether the L2 (WP object cache) layer is currently active for reads
	 * and writes. True only when both the user setting allows it AND a
	 * persistent external object cache (Redis / Memcached / etc.) is in
	 * place — without persistence, L2 is just an in-memory dupe of L1.
	 *
	 * @return bool
	 */
	public function l2_enabled(): bool {
		return $this->object_cache_enabled && wp_using_ext_object_cache();
	}

	/**
	 * Evict the oldest 25% of L1 entries when the cap is reached and
	 * $full_key would add a NEW entry. Must run on EVERY L1 insert path —
	 * including the L2/L3 hit-promotions and set_static() seeding, not just
	 * set() — because on a warm cache a long-lived process (WP-CLI bulk
	 * export, queue-runner batch) inserts almost exclusively via promotion,
	 * so set() alone never fires to bound the array.
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
	 * Prevents cross-site cache pollution in multisite setups
	 * with shared persistent object cache (Redis/Memcached).
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
	 * Generate a transient key, keeping it under 172 characters.
	 *
	 * WordPress transient names are limited to 172 characters for the transient
	 * key itself (191 chars for option_name minus '_transient_' prefix).
	 *
	 * Public so adjacent components (e.g. TranslationGroupRepository's
	 * direct-options prime path) can reproduce the exact key without
	 * duplicating the length-threshold + md5-fallback logic. Drift between
	 * two implementations would silently miss transient hits.
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
	 * Internal alias to keep the historical private name available for
	 * backwards-compat with any callers inside this class. New code should
	 * use derive_transient_key() directly.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group.
	 * @return string
	 */
	private function transient_key( string $key, string $group ): string {
		return $this->derive_transient_key( $key, $group );
	}
}
