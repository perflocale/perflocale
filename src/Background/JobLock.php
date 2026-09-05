<?php
/**
 * Per-job concurrency lock backed by an atomic INSERT IGNORE + CAS reclaim.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents two cron workers from running the same job concurrently.
 *
 * Action Scheduler has native claim semantics, but {@see WpCronRunner}
 * does not. We add a lock unconditionally so the same code path works
 * for both runners — when AS is the runner, this is belt-and-braces
 * (AS won't double-claim, but if a user dispatches the same job from
 * both an admin button AND a cron tick somehow, the lock still blocks
 * the second invocation).
 *
 * Lock storage: a single `perflocale_job_lock_<id>` option, NOT
 * autoloaded. The option's value is `"<expiry>|<token>"` — see
 * {@see PerfLocale\Concurrency\Lock} for the same scheme. The token
 * lets {@see release()} and {@see refresh()} do conditional writes that
 * fail cleanly when another worker took over an expired lock; without
 * it the original holder's late release/refresh would clobber the new
 * holder's row.
 *
 * Atomicity comes from a raw `INSERT IGNORE` (fast path) — backed by
 * `wp_options.option_name`'s UNIQUE constraint, so two concurrent
 * INSERTs cannot both succeed — and a conditional UPDATE for the
 * expired-takeover path. It is deliberately NOT `add_option()`: that
 * emits `INSERT … ON DUPLICATE KEY UPDATE` and returns truthy for every
 * racing caller, which measured 30 "winners" out of a 30-way fork while
 * one row landed. See {@see cas_acquire()} before changing this.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class JobLock {

	/**
	 * Option-key prefix. Combined with the job id to form the lock key.
	 */
	public const PREFIX = 'perflocale_job_lock_';

	/**
	 * Prefix for type-scoped locks (e.g. "only run one string_scan at a
	 * time"). Distinct from {@see PREFIX} so the per-job and per-type
	 * lock spaces never collide.
	 */
	public const TYPE_PREFIX = 'perflocale_type_lock_';

	/**
	 * Default lock TTL (30 minutes). Wide enough to cover most jobs that
	 * tick progress at least every few minutes; workers that block for
	 * longer than this without a refresh should override via
	 * {@see AbstractJob::get_lock_ttl()} (per-job-type cap) or call
	 * {@see refresh()} themselves inside their inner loops.
	 *
	 * The CAS-reclaim path catches dead locks safely (no double-execution
	 * on lock expiry — the CAS reclaim is the ONLY way another worker
	 * picks up a stale lock), but a too-low TTL means an alive but
	 * blocked worker can have its lock stolen mid-run, leading to
	 * parallel execution of a non-idempotent job. Default sized for the
	 * common case of "occasional 10-minute runs."
	 */
	public const DEFAULT_TTL = 1800;

	/**
	 * Stored-value separator between expiry timestamp and per-acquire
	 * token. Fixed literal — never derived from input.
	 */
	private const DELIMITER = '|';

	/**
	 * Per-request map of lock keys → exact stored value at acquire time.
	 *
	 * Used as the CAS comparison value in {@see release()} and
	 * {@see refresh()} so the original holder can't accidentally clobber
	 * a takeover by another worker after TTL expiry.
	 *
	 * Keys are namespaced with the current blog ID so the same lock
	 * NAME on two different multisite blogs (each having its own
	 * wp_<id>_options table) doesn't collide on the per-process memo.
	 *
	 * @var array<string, string>
	 */
	private static array $owned = [];

	/**
	 * Keys whose lease this process HELD and provably LOST — a conditional
	 * refresh matched zero rows and the re-read showed a different value.
	 *
	 * {@see $owned} answers "do I hold it?". This answers "did I hold it and
	 * lose it?", which is the distinction {@see refresh()} and {@see release()}
	 * need. Without it, clearing $owned on a lost refresh made a stale worker
	 * look identical to one that never acquired in this request, so its next
	 * heartbeat took the ownerless best-effort-overwrite path and stamped a NEW
	 * token over the takeover winner's lease, and its `finally` release took the
	 * forced-delete path and removed that lease outright — either way two
	 * workers could then run the same job, or the same job type.
	 *
	 * Cleared by {@see cas_acquire()} on a successful (re)acquire and by
	 * {@see release()}. Same blog-namespaced keys as {@see $owned}.
	 *
	 * @var array<string, true>
	 */
	private static array $lost = [];

	/**
	 * Build the per-blog static key for the {@see $owned} map.
	 *
	 * @param string $key Lock option name (already prefixed).
	 * @return string
	 */
	private static function owned_key( string $key ): string {
		return ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $key;
	}

	/**
	 * Atomically acquire the lock for {@param string $job_id}.
	 *
	 * Strategy (see {@see cas_acquire()} for the statements):
	 *   1. `INSERT IGNORE` — succeeds atomically iff no row exists. NOT
	 *      `add_option()`, which is not race-safe here.
	 *   2. If the INSERT lost, run an atomic UPDATE that bumps
	 *      `option_value` to our new expiry ONLY if the stored expiry is
	 *      in the past. mysqld's row lock ensures only one of N racing
	 *      threads sees `affected_rows = 1`; the rest see 0 and bail.
	 *
	 * @param string $job_id Job identifier (must match {@see JobState::is_safe_id()}).
	 * @param int    $ttl    Seconds before the lock auto-expires. Default 600.
	 * @return bool True if the lock was acquired; false if another holder owns it.
	 */
	public static function acquire( string $job_id, int $ttl = self::DEFAULT_TTL ): bool {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return false;
		}

		$key     = self::PREFIX . $job_id;
		$expires = time() + max( 30, $ttl );

		return self::cas_acquire( $key, $expires );
	}

	/**
	 * Refresh the lock's expiry for a long-running worker. Call periodically
	 * (e.g. every batch) so the lock TTL doesn't expire mid-run on
	 * legitimately long jobs.
	 *
	 * Does NOT generate a new token — the caller is presumed to still own
	 * the lock. The conditional UPDATE here only matches when the stored
	 * value is STILL the value we wrote at acquire time; if another worker
	 * has already taken over (because we ran past our TTL), the UPDATE
	 * affects zero rows and the call becomes a no-op.
	 *
	 * Visibility: a failed refresh is logged via error_log so the operator
	 * can detect "lock stolen mid-run" scenarios; the function is `void`
	 * by historical contract so callers don't need to branch on the
	 * return value.
	 *
	 * @param string $job_id Job identifier.
	 * @param int    $ttl    New TTL window from now.
	 * @return void
	 */
	public static function refresh( string $job_id, int $ttl = self::DEFAULT_TTL ): void {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return;
		}

		$key         = self::PREFIX . $job_id;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;

		if ( $owned_value === null && isset( self::$lost[ $owned_key ] ) ) {
			// We DID hold this lease and provably lost it: an earlier refresh
			// found another worker's value in the row. The ownerless best-effort
			// overwrite below is only ever correct for "never acquired in THIS
			// request"; here it would stamp a fresh token over the CURRENT
			// owner's lease and hand exclusivity to two workers at once. Stay
			// out and let the new owner keep its lock.
			return;
		}

		if ( $owned_value === null ) {
			// Refresh called without a prior acquire in THIS request — most
			// likely a long-running worker process where the static state
			// was reset between batches. Fall back to a best-effort overwrite
			// (matches the pre-token-guard behaviour) so we don't regress
			// existing AS workers.
			update_option( $key, self::format_value( time() + max( 30, $ttl ), self::generate_token() ), false );
			return;
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// Conditional refresh: extract the existing token (so we keep the
		// same identity) and update only if the stored value matches.
		$token       = self::parse_token( $owned_value );
		$new_expires = time() + max( 30, $ttl );
		$new_value   = self::format_value( $new_expires, $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = %s
				 WHERE option_name = %s
				 AND option_value = %s",
				$new_value,
				$key,
				$owned_value
			)
		);

		if ( 1 === (int) $updated ) {
			self::$owned[ $owned_key ] = $new_value;
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return;
		}

		// 0 affected rows is AMBIGUOUS: either another worker took the lock,
		// or the UPDATE was a no-op because $new_value is byte-identical to
		// the stored value (a refresh in the same second as the acquire with
		// an equal TTL yields the same "expiry|token" string, and MySQL
		// reports identical-value UPDATEs as 0 affected). Re-read to tell
		// them apart — dropping ownership on a no-op would turn release()
		// into a silent no-op (its conditional DELETE requires the owned
		// stamp) and leak the lock for the full TTL.
		if ( self::current_value( $key ) === $new_value ) {
			self::$owned[ $owned_key ] = $new_value;
			return;
		}

		// Lost ownership — log so the operator can spot the contention.
		// We intentionally don't throw; callers don't expect this method
		// to fail mid-loop, and silently no-op'ing matches the historical
		// behaviour better than aborting the whole job.
		if ( function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on lock-loss path.
			error_log( '[perflocale][job-lock] refresh() lost ownership of ' . $key . ' (another worker took over after TTL expiry)' );
		}

		// Also surface it in the admin Jobs log — error_log is frequently
		// unreachable on managed hosting, and lock contention is exactly what
		// an operator debugging a stalled or duplicated job needs to see.
		JobState::append_log( $job_id, __( 'Lock refresh lost — another worker took over after the lock TTL expired.', 'perflocale' ) );

		unset( self::$owned[ $owned_key ] );
		// Record that ownership was LOST, not merely absent. Clearing $owned
		// alone made this state look like "never acquired here", which is what
		// let the next refresh() overwrite and the finally-release() delete the
		// new owner's lease. Cleared again by cas_acquire() / release().
		self::$lost[ $owned_key ] = true;
	}

	/**
	 * Raw read of a lock row's current value, bypassing the options cache
	 * (lock rows are written with raw SQL, so the cache can be stale).
	 *
	 * @param string $key Full option name (already prefixed).
	 * @return string|null Stored value, or null when the row is gone.
	 */
	private static function current_value( string $key ): ?string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Release the lock. Workers MUST call this in a finally block so a
	 * thrown exception still releases the lock.
	 *
	 * Conditional DELETE: only removes the row when the stored value
	 * matches what we stamped at acquire time. If another worker took
	 * over after our TTL expired, the DELETE affects zero rows and the
	 * new owner's lock survives.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function release( string $job_id ): void {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return;
		}

		$key         = self::PREFIX . $job_id;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;
		$was_lost    = isset( self::$lost[ $owned_key ] );
		unset( self::$owned[ $owned_key ], self::$lost[ $owned_key ] );

		if ( $owned_value === null && $was_lost ) {
			// This worker held the lease, lost it to a takeover, and is now
			// unwinding through WorkerRegistry's `finally`. The forced delete
			// below is safe for a caller that NEVER owned the lock — the key is
			// namespaced to this job id, so nothing else can be holding it — but
			// here the ROW is somebody else's LIVE lease, and deleting it lets a
			// third worker enter a job that is actively running. Operator cancel
			// from REST/CLI is unaffected: it never refreshed, so it never
			// recorded a loss and still takes the forced path.
			return;
		}

		// Cross-request release (REST/CLI cancel) legitimately has no
		// $owned_value — the lock was stamped by the worker's request, whose
		// static state we cannot see. The per-job key is namespaced by this
		// exact job id, so nothing else can be holding it: dropping it
		// unconditionally frees the cancelled job instead of leaving the row
		// to linger until its TTL. Contrast release_type(), where the key is
		// shared and an unconditional delete could free a DIFFERENT running
		// job's lock.
		self::release_key( $key, $owned_value, true );
	}

	/**
	 * Whether a (live, non-expired) lock currently exists for the job.
	 * Inspection only — doesn't change state. Useful in tests + the
	 * admin Jobs page (to show "another worker is running this").
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public static function is_held( string $job_id ): bool {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return false;
		}

		$stored = (string) get_option( self::PREFIX . $job_id, '' );
		return self::parse_expiry( $stored ) > time();
	}

	/**
	 * Acquire the type-scoped lock so only one job of `$type` can run at a
	 * time. Used by {@see WorkerRegistry::run()} to throttle dispatch
	 * fan-out — if a user clicks "Scan strings" five times in a row, AS
	 * may spawn parallel workers and start five concurrent scans on the
	 * same wp_options-backed StringRepository, producing deadlocks. With
	 * this lock the first worker runs immediately; the other four
	 * re-queue with a backoff.
	 *
	 * Same CAS + token pattern as {@see acquire()}.
	 *
	 * The TTL should generally match or exceed the per-job lock TTL so a
	 * legitimately-long job doesn't keep its type lock past the per-job
	 * lock.
	 *
	 * @param string $type Job type slug (sanitize_key sanitized; only
	 *                     `[a-z0-9_-]` is expected from registered types).
	 * @param int    $ttl  Seconds before the lock auto-expires.
	 * @return bool True if the lock was acquired; false if another holder owns it.
	 */
	public static function acquire_type( string $type, int $ttl = self::DEFAULT_TTL ): bool {
		$type = self::sanitize_type( $type );

		if ( $type === '' ) {
			return false;
		}

		$key     = self::TYPE_PREFIX . $type;
		$expires = time() + max( 30, $ttl );

		return self::cas_acquire( $key, $expires );
	}

	/**
	 * Shared lock acquisition with true compare-and-swap reclaim of an
	 * expired lock. Two-step:
	 *
	 *   1. Fast path: a raw `INSERT IGNORE` succeeds iff no row exists, so
	 *      only one writer can win the race even under concurrent attempts.
	 *
	 *   2. Reclaim path: if step 1 lost, the row exists. Run an atomic
	 *      UPDATE that bumps `option_value` to our new value ONLY if the
	 *      stored expiry is in the past. mysqld's row lock ensures only
	 *      one of N racing threads sees `affected_rows = 1`; the rest see
	 *      0 and bail. Eliminates the delete-then-add race that the old
	 *      implementation had between `delete_option()` and `add_option()`.
	 *
	 * Object-cache invalidation is explicit on the reclaim path because
	 * `$wpdb->query()` bypasses the WP options abstraction and the cached
	 * value would otherwise stay stale until the next get_option call hit
	 * the DB.
	 *
	 * @param string $key     Full option name (already prefixed).
	 * @param int    $expires Unix expiry timestamp.
	 * @return bool True iff we now hold the lock.
	 */
	private static function cas_acquire( string $key, int $expires ): bool {
		$value = self::format_value( $expires, self::generate_token() );

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// Fast path — raw INSERT IGNORE, NOT add_option(). WP's add_option
		// uses `INSERT ... ON DUPLICATE KEY UPDATE option_name = VALUES(...)`
		// which under N-way fork concurrency returns truthy for EVERY
		// caller (the duplicate-update is a no-op but `affected_rows`
		// reporting is not reliably 0 across all storage engines /
		// versions). Verified empirically at 30-way fork → 30 "winners"
		// while only 1 row landed in wp_options. Raw INSERT IGNORE is
		// genuinely atomic via the InnoDB UNIQUE constraint: exactly
		// one writer sees `rows_affected = 1`.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$value,
				'no'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( 1 === (int) $inserted ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			self::$owned[ self::owned_key( $key ) ] = $value;
			// A fresh acquire re-establishes ownership, so any recorded
			// lost-ownership state for this key is obsolete.
			unset( self::$lost[ self::owned_key( $key ) ] );
			return true;
		}

		// Atomic CAS: bump the entire value ONLY if the stored expiry is
		// in the past (i.e. the previous holder crashed or ran past TTL).
		// Concurrent reclaimers race at the DB layer; only one observes
		// `affected_rows = 1`.
		//
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = %s
				 WHERE option_name = %s
				 AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d",
				$value,
				$key,
				time()
			)
		);

		if ( (int) $updated !== 1 ) {
			return false;
		}

		// Bust caches so subsequent `get_option` sees the new expiry. The
		// per-key cache holds the pre-update value; alloptions is irrelevant
		// here (lock option is autoload=no) but cheap to invalidate.
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		self::$owned[ self::owned_key( $key ) ] = $value;
		// A successful CAS reclaim re-establishes ownership, so any recorded
		// lost-ownership state for this key is obsolete.
		unset( self::$lost[ self::owned_key( $key ) ] );
		return true;
	}

	/**
	 * Extend the per-type concurrency lock's TTL, keeping the same owner
	 * token (conditional CAS). Called with the job's real lock TTL right
	 * after the job is built, then periodically from the progress callback —
	 * WITHOUT this a long job's type lock (acquired at the 1800s default and
	 * never refreshed) expires mid-flight and a second same-type job can
	 * reclaim it and run concurrently, breaking the max_concurrent=1 guarantee.
	 *
	 * @param string $type Job type slug.
	 * @param int    $ttl  New TTL in seconds.
	 * @return void
	 */
	public static function refresh_type( string $type, int $ttl = self::DEFAULT_TTL ): void {
		$type = self::sanitize_type( $type );

		if ( $type === '' ) {
			return;
		}

		$key         = self::TYPE_PREFIX . $type;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;

		if ( $owned_value === null && isset( self::$lost[ $owned_key ] ) ) {
			// Provably lost the TYPE lock (same reasoning as refresh()): the
			// ownerless overwrite below would stamp our token over the worker
			// that now holds it and break the max_concurrent=1 guarantee for
			// this whole job type.
			return;
		}

		if ( $owned_value === null ) {
			// No prior acquire in this request (static reset between AS
			// batches): best-effort overwrite, matching refresh()'s fallback.
			update_option( $key, self::format_value( time() + max( 30, $ttl ), self::generate_token() ), false );
			return;
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$token     = self::parse_token( $owned_value );
		$new_value = self::format_value( time() + max( 30, $ttl ), $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				$key,
				$owned_value
			)
		);

		if ( 1 === (int) $updated ) {
			self::$owned[ $owned_key ] = $new_value;
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return;
		}

		// 0 affected can be a no-op UPDATE, not a lost lock: the startup
		// refresh (acquire_type at DEFAULT_TTL, then refresh_type with the
		// job's TTL in the same second) produces a byte-identical
		// "expiry|token" value whenever the two TTLs are equal, and MySQL
		// reports identical-value UPDATEs as 0 affected. Misreading that as
		// lost ownership drops the owned stamp, release_type()'s conditional
		// DELETE stops matching, and the type lock leaks for the full TTL —
		// deferring every same-type job with "worker busy" for up to 30 min.
		if ( self::current_value( $key ) === $new_value ) {
			self::$owned[ $owned_key ] = $new_value;
			return;
		}

		// Lost ownership of the type lock — drop our stale record so we don't
		// keep trying to refresh a lock a different worker now owns, and mark it
		// LOST so the ownerless overwrite above stays disabled for this key.
		unset( self::$owned[ $owned_key ] );
		self::$lost[ $owned_key ] = true;
	}

	/**
	 * Release the type-scoped lock. Callers MUST invoke this in a finally
	 * block so a thrown exception doesn't strand the lock for the full
	 * TTL window.
	 *
	 * @param string $type
	 * @return void
	 */
	public static function release_type( string $type ): void {
		$type = self::sanitize_type( $type );

		if ( $type === '' ) {
			return;
		}

		$key         = self::TYPE_PREFIX . $type;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;
		unset( self::$owned[ $owned_key ] );

		self::release_key( $key, $owned_value );
	}

	/**
	 * Shared release path used by both {@see release()} and
	 * {@see release_type()}.
	 *
	 * @param string      $key         Full option name (already prefixed).
	 * @param string|null $owned_value Value we wrote at acquire time, or
	 *                                 null when called without prior acquire
	 *                                 in this request.
	 * @param bool        $force       Delete even without a recorded owner.
	 *                                 Only safe for keys namespaced to a
	 *                                 single job (see {@see self::release()}).
	 * @return void
	 */
	private static function release_key( string $key, ?string $owned_value, bool $force = false ): void {
		global $wpdb;

		if ( $owned_value === null && ! $force ) {
			return;
		}

		if ( $owned_value === null ) {
			// Forced, ownerless release: no value to compare, so delete the
			// row outright.
			delete_option( $key );
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return;
		}

		if ( ! $wpdb instanceof \wpdb ) {
			// Fallback when $wpdb isn't available — WP's own delete_option
			// always nukes the row.
			delete_option( $key );
			return;
		}

		// Conditional DELETE — only removes the row when the stored value
		// still matches what we stamped at acquire time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$owned_value
			)
		);
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Type slugs are caller-controlled (they come from registered worker
	 * factories, not user input), but defense-in-depth: only allow the
	 * `[a-z0-9_-]` charset that registered types actually use, so a
	 * malformed call can't traverse to a different option key.
	 *
	 * @param string $type
	 * @return string Sanitized type slug, or empty string if invalid.
	 */
	private static function sanitize_type( string $type ): string {
		if ( $type === '' || strlen( $type ) > 64 ) {
			return '';
		}

		return (string) preg_replace( '/[^a-z0-9_-]/', '', $type );
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
	 * Generate a per-acquire random token. Same impl as
	 * {@see PerfLocale\Concurrency\Lock::generate_token()}; kept here so
	 * this class has no static dependency on the other.
	 *
	 * @return string Lowercase hex; never contains {@see DELIMITER}.
	 */
	private static function generate_token(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return uniqid( '', true ) . dechex( (int) ( microtime( true ) * 1e6 ) );
		}
	}

	/**
	 * Parse the integer expiry timestamp out of either storage format.
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

		if ( $prefix === '' || ! ctype_digit( $prefix ) ) {
			return 0;
		}

		return (int) $prefix;
	}

	/**
	 * Extract the token portion from a stored value.
	 *
	 * @param string $stored Raw option_value.
	 * @return string
	 */
	private static function parse_token( string $stored ): string {
		$delim_pos = strpos( $stored, self::DELIMITER );

		if ( $delim_pos === false ) {
			return '';
		}

		return substr( $stored, $delim_pos + 1 );
	}
}
