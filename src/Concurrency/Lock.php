<?php
/**
 * Atomic cross-request locks for critical sections.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Concurrency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic, TTL-bounded cross-request lock backed by a raw INSERT IGNORE.
 *
 * Uses `INSERT IGNORE INTO wp_options` so the UNIQUE KEY on `option_name`
 * enforces mutual exclusion at the InnoDB row level. A `$wpdb->rows_affected`
 * of 1 means we acquired the lock; 0 means another process already holds it.
 * Unlike WordPress core's `add_option()` (which uses `ON DUPLICATE KEY UPDATE`
 * and could let two concurrent callers both appear to "succeed"), this is a
 * genuine race-free acquire.
 *
 * TTL is encoded in the stored value so a crashed request doesn't permanently
 * wedge the lock - subsequent callers attempt a takeover after the stored
 * expiry has passed.
 *
 * ## Release-token guard
 *
 * Stored value format is `"<expiry>|<token>"` where `<token>` is a per-acquire
 * random hex string. The token is also stashed in {@see $owned} for the
 * acquiring request. {@see release()} does a conditional DELETE that ONLY
 * removes the row when the stored value still matches the acquirer's
 * stamped value — so a holder that ran past its TTL (whose lock was
 * already taken over by another worker) can't accidentally delete the new
 * owner's row.
 *
 * The takeover path in {@see acquire()} uses a true compare-and-swap UPDATE
 * for the same reason: two contenders racing on an expired lock would both
 * succeed with the old DELETE-then-INSERT pattern; only one wins the CAS
 * UPDATE.
 *
 * Intended for fine-grained locks around per-object sync work (inventory
 * sync, content sync, etc.) where transient-based "best effort" guards
 * have a TOCTOU race between get_transient() and set_transient().
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class Lock {

	/**
	 * Prefix for lock option names.
	 */
	private const PREFIX = 'perflocale_lock_';

	/**
	 * Cron hook used by the expired-lock cleanup job.
	 */
	public const CLEANUP_HOOK = 'perflocale_lock_cleanup';

	/**
	 * Stored-value separator between expiry timestamp and per-acquire token.
	 * Fixed literal — never derived from input, so format injection is
	 * impossible. Tokens are pure hex (see {@see generate_token()}) so they
	 * never contain this character either.
	 */
	private const DELIMITER = '|';

	/**
	 * Per-request map of lock keys → exact stored value at acquire time.
	 *
	 * Used as the CAS comparison value in {@see release()}. Also doubles as
	 * a reentry guard — if a hook handler re-enters its own critical section,
	 * the second {@see acquire()} call short-circuits to false.
	 *
	 * Keys are namespaced with the current blog ID on multisite — without
	 * that, `switch_to_blog( 2 )` followed by `acquire( 'X' )` would see
	 * `$owned[ 'perflocale_lock_X' ]` from blog 1's earlier acquire and
	 * short-circuit, even though the lock row lives in a DIFFERENT
	 * wp_<id>_options table and is genuinely free on blog 2. The blog-id
	 * prefix in {@see owned_key()} makes the per-request memo isolated
	 * per blog the same way the underlying options table is.
	 *
	 * Cleared on request end (PHP static state).
	 *
	 * @var array<string, string>
	 */
	private static array $owned = [];

	/**
	 * Build the per-blog static key for the {@see $owned} map. On
	 * single-site WP, get_current_blog_id() returns 1 (the only blog),
	 * so the prefix is constant and adds no semantic complexity. On
	 * multisite, switch_to_blog() updates get_current_blog_id() so the
	 * prefix follows the active context automatically.
	 *
	 * @param string $key Lock option name (already DB-prefixed).
	 * @return string
	 */
	private static function owned_key( string $key ): string {
		return ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $key;
	}

	/**
	 * Attempt to atomically acquire a named lock.
	 *
	 * @param string $name Lock name (will be prefixed to avoid collisions).
	 * @param int    $ttl Time-to-live in seconds. The lock self-expires
	 *    after this so a crashed request doesn't block
	 *    future acquisitions indefinitely.
	 * @return bool True if the lock was acquired.
	 */
	public static function acquire( string $name, int $ttl = 15 ): bool {
		global $wpdb;

		$key       = self::PREFIX . $name;
		$owned_key = self::owned_key( $key );

		// Fast path: we already own this lock this request. Recursion-safe —
		// a re-entrant caller (via hooks) gets the same answer without a DB
		// round-trip. TTL-aware: if our stored expiry has passed (or another
		// process's reap_expired dropped it mid-request), drop the stale entry
		// and fall through to the normal acquire path.
		if ( isset( self::$owned[ $owned_key ] ) ) {
			$owned_expiry = self::parse_expiry( (string) self::$owned[ $owned_key ] );
			if ( $owned_expiry > 0 && $owned_expiry <= time() ) {
				unset( self::$owned[ $owned_key ] );
			} else {
				return false;
			}
		}

		$expires = time() + max( 1, $ttl );
		$value   = self::format_value( $expires, self::generate_token() );

		// INSERT IGNORE is atomic at the DB layer - the UNIQUE constraint
		// check is serialised by InnoDB, so two concurrent callers cannot
		// both get rows_affected=1. phpcs suppressions: option_name is a
		// safe constant + controlled input; caller inputs are prepared.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$value,
				'no'
			)
		);

		if ( 1 === $inserted ) {
			self::invalidate_option_caches( $key );
			self::$owned[ $owned_key ] = $value;
			return true;
		}

		// Row already exists - read the stored expiry directly (bypassing
		// the object cache which could be stale after another process'
		// release ran). If it's still live, refuse without further work.
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);

		// Concurrent release between the INSERT and the SELECT can leave
		// us with $stored === null (the row vanished). Retry the INSERT
		// path once — if we still lose, the lock is contended and we bail.
		if ( $stored === null ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					$key,
					$value,
					'no'
				)
			);

			if ( 1 === $inserted ) {
				self::invalidate_option_caches( $key );
				self::$owned[ $owned_key ] = $value;
				return true;
			}

			return false;
		}

		$stored_expiry = self::parse_expiry( (string) $stored );

		if ( $stored_expiry <= 0 || $stored_expiry >= time() ) {
			// Lock is still live (or unparseable, which we treat as live to
			// avoid clobbering an unexpected row). Acquire fails.
			return false;
		}

		// Lock has expired — attempt a true CAS takeover. The UPDATE only
		// matches when the stored value is STILL the same expired one we
		// just observed; if another contender beat us between SELECT and
		// UPDATE, their newer value won't match and we cleanly fail to
		// acquire. Closes the race the older DELETE+INSERT path had.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$key,
				(string) $stored
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( 1 === (int) $updated ) {
			self::invalidate_option_caches( $key );
			self::$owned[ $owned_key ] = $value;
			return true;
		}

		return false;
	}

	/**
	 * Release a previously-acquired lock.
	 *
	 * Safe to call even if the lock wasn't owned by this request — the
	 * stored value won't match our (empty) owned token, so the conditional
	 * DELETE affects zero rows.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( string $name ): void {
		global $wpdb;

		$key         = self::PREFIX . $name;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;
		unset( self::$owned[ $owned_key ] );

		if ( $owned_value === null ) {
			return;
		}

		// Conditional DELETE that ONLY removes the row when the stored
		// value still matches what we stamped at acquire time. If another
		// worker took over after our TTL expired, the row's value is now
		// different and the DELETE affects zero rows — exactly what we want.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$owned_value
			)
		);
		self::invalidate_option_caches( $key );
	}

	/**
	 * Run a callable under a lock. Acquire, execute, release in try/finally.
	 *
	 * @param string   $name Lock name.
	 * @param int      $ttl TTL in seconds.
	 * @param callable $callback Callback to run when the lock is held.
	 * Return value is forwarded to the caller.
	 * @return mixed Callback return value, or null when the lock could not
	 * be acquired. (Callers that return null on success can't distinguish
	 * — wrap the callback to return a sentinel if you need to.)
	 */
	public static function with( string $name, int $ttl, callable $callback ): mixed {
		// Reentry detector (process-local). Recursing into Lock::with() with
		// the same name is a developer mistake: the inner acquire() will
		// either fail (returning null and skipping critical work) or — on a
		// non-row-locking backend — silently double-release at the outer
		// finally. Either way the outer caller's invariants are broken.
		// Same-process guard only: cross-request collisions are handled by
		// acquire()'s row-lock semantics.
		static $held = [];

		// Blog-namespace the re-entry key exactly as $owned is (see owned_key):
		// the same lock NAME on two blogs are independent rows in different
		// wp_<id>_options tables, so nesting them across a switch_to_blog() is
		// legitimate — not re-entry — and must not trip the guard or let the
		// inner finally clear the outer blog's entry early.
		$held_key = self::owned_key( $name );

		if ( isset( $held[ $held_key ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the lock name. */
						__( 'Lock::with( "%s", ... ) cannot be re-entered for the same lock name in the same request — the inner acquire() will fail (returning null) and the outer release() will run at the wrong time. Refactor to flatten the nested critical section.', 'perflocale' ),
						$name
					)
				),
				'1.0.0'
			);
		}

		if ( ! self::acquire( $name, $ttl ) ) {
			return null;
		}

		$held[ $held_key ] = true;

		try {
			return $callback();
		} finally {
			unset( $held[ $held_key ] );
			self::release( $name );
		}
	}

	/**
	 * Reap expired lock rows from wp_options.
	 *
	 * Locks are released explicitly via release() at the end of their
	 * critical section, but a crashed request (fatal error, OOM, timeout)
	 * can leak the row. This reaper walks every `perflocale_lock_*` row
	 * and deletes those whose stored expiry timestamp is in the past —
	 * safe because an active lock's value is always `time() + ttl`, so
	 * any in-flight lock has expiry > now.
	 *
	 * Scheduled daily from Bootstrap; manually-callable for tests.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function reap_expired(): int {
		// In-flight safety: if uninstall is mid-flight (or the plugin
		// directory was force-deleted), bail out. We don't touch any
		// plugin-owned tables here, but checking once at entry future-
		// proofs against handlers that grow custom-table queries later.
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return 0;
		}

		// Capture start time for the recurring-handler tracing/profiling
		// record. `BackgroundEvents::record_run()` writes it at the end
		// so the Jobs admin page can show "ran 2 hours ago — took N ms".
		$started_at = time();

		global $wpdb;

		$now    = time();
		$prefix = self::PREFIX;

		// Self-healing: delete every lock row that is NOT a currently-live
		// lock. A live lock is our exact shape (`<expiry>|<token>`) whose
		// expiry is still in the future. Everything else — expired locks,
		// and any malformed / leftover row that doesn't match our shape —
		// is swept, so a corrupt value can never permanently block a lock
		// name. (`acquire()` stays conservative and refuses to TAKE OVER an
		// unparseable row; the reaper is the one that cleans them up.)
		// `\\\\` in this double-quoted PHP string → MySQL `\\` → REGEXP `\`.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND NOT (
					option_value REGEXP '^[0-9]+\\\\|'
					AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) >= %d
				)",
				$wpdb->esc_like( $prefix ) . '%',
				$now
			)
		);

		if ( $deleted > 0 ) {
			// One blanket cache wipe - cheaper than invalidating each row
			// individually, and expired locks are cold-path data anyway.
			wp_cache_delete( 'alloptions', 'options' );

			// Sweep our own static $owned cache of any entries whose stored
			// expiry has passed. Without this, a long-running request that
			// holds an expired lock AND runs the daily reap in the same PHP
			// process keeps thinking it owns the lock — even though we
			// just deleted its DB row. Same-process coherence only; we
			// can't see another process's $owned cache, so a remote
			// reap-while-we-hold remains a documented TTL-lock limitation.
			$now_check = time();
			foreach ( self::$owned as $ok => $val ) {
				$exp = self::parse_expiry( (string) $val );
				if ( $exp > 0 && $exp <= $now_check ) {
					unset( self::$owned[ $ok ] );
				}
			}
		}

		// Record completion time + duration for the observability panel.
		\PerfLocale\Background\BackgroundEvents::record_run( self::CLEANUP_HOOK, $started_at );

		return $deleted;
	}

	/**
	 * Build the stored value: `"<expiry>|<token>"`.
	 *
	 * @param int    $expires Unix timestamp at which the lock auto-expires.
	 * @param string $token   Per-acquire random hex string.
	 * @return string
	 */
	private static function format_value( int $expires, string $token ): string {
		return $expires . self::DELIMITER . $token;
	}

	/**
	 * Generate a per-acquire random token. 16 hex chars from 8 random bytes
	 * — collision-resistant for the lock's lifetime (we only need uniqueness
	 * within the TTL window for one option_name, not global uniqueness).
	 *
	 * @return string Lowercase hex; never contains {@see DELIMITER}.
	 */
	private static function generate_token(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			// random_bytes throws if the OS has no entropy source. Fall
			// back to a microtime + uniqid pair — weaker but still unique
			// enough for the per-request lifetime of one lock row.
			return uniqid( '', true ) . dechex( (int) ( microtime( true ) * 1e6 ) );
		}
	}

	/**
	 * Parse the integer expiry timestamp out of either storage format.
	 * Returns 0 for unparseable values so the caller treats them as
	 * unknown / still-live (refuse to take over).
	 *
	 * @param string $stored Raw option_value.
	 * @return int Unix timestamp, or 0 if unparseable.
	 */
	private static function parse_expiry( string $stored ): int {
		$delim_pos = strpos( $stored, self::DELIMITER );

		if ( $delim_pos === false ) {
			return 0;
		}

		$prefix = substr( $stored, 0, $delim_pos );

		// `ctype_digit` rejects negative numbers, decimals, and the empty
		// string — so an empty or garbage prefix returns 0 (treated as
		// unknown / refuse takeover). Defensive against a corrupted row.
		if ( $prefix === '' || ! ctype_digit( $prefix ) ) {
			return 0;
		}

		return (int) $prefix;
	}

	/**
	 * Invalidate WP's option caches for a key modified outside WP's
	 * normal option API, so subsequent get_option/add_option calls see
	 * the real DB state.
	 *
	 * Does NOT clear the `alloptions` cache - lock rows use autoload='no'
	 * so they're never loaded into that bucket, and clearing it here
	 * would force a re-read of every autoloaded option on every lock
	 * acquire/release (a significant perf regression on large sites).
	 *
	 * @param string $key Option name (prefixed).
	 * @return void
	 */
	private static function invalidate_option_caches( string $key ): void {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
