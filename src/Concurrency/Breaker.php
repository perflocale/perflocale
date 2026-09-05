<?php
/**
 * Simple three-state circuit breaker keyed by string identifier.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Concurrency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three-state circuit breaker designed for protecting external calls
 * (MT providers, webhook receivers, FX APIs, geo IP services) from
 * piling retries onto an already-failing dependency.
 *
 * ## State machine
 *
 *   - **CLOSED**: normal operation. `is_open()` returns false.
 *     `record_failure()` increments a counter within a sliding (tumbling)
 *     window; if the counter reaches the threshold the breaker
 *     transitions to OPEN.
 *
 *   - **OPEN**: refuses calls for `cooldown` seconds. `is_open()`
 *     returns true. After cooldown expires it lazily transitions to
 *     HALF_OPEN on the next `is_open()` check.
 *
 *   - **HALF_OPEN**: allows exactly ONE probe call through. `is_open()`
 *     returns false. The next `record_success()` closes the breaker;
 *     a `record_failure()` re-opens it with a fresh cooldown.
 *
 * ## Storage
 *
 * Each breaker's state lives in a per-key transient
 * `perflocale_breaker_{key}`. Transients give us free TTL handling and
 * automatic object-cache integration. Cache eviction "forgets" the
 * breaker state — that's acceptable: it just means one extra failed call
 * before the breaker re-opens, never a stuck-open situation that
 * eats good traffic forever.
 *
 * ## Filters
 *
 *   - `perflocale/breaker/disabled` (bool, default false) — global
 *     kill-switch. When true, `is_open()` always returns false and
 *     `record_failure()` is a no-op. Useful for ops to temporarily
 *     disable circuit-breaker behaviour while debugging.
 *
 *   - `perflocale/breaker/threshold/{key}` (int, default 5) — number of
 *     failures within the window that trips the breaker.
 *
 *   - `perflocale/breaker/window_seconds/{key}` (int, default 300) —
 *     how far back to count failures. A failure older than this resets
 *     the counter.
 *
 *   - `perflocale/breaker/cooldown_seconds/{key}` (int, default 300) —
 *     OPEN duration before the breaker probes again.
 *
 *   - `perflocale/breaker/probe_lease_seconds` (int, key-aware) — how long
 *     the single HALF_OPEN probe holds its turn. Defaults to the breaker's
 *     own cooldown, clamped to 30-300s.
 *
 * Per-key filters fall back to the generic `perflocale/breaker/*`
 * variant (no `{key}` suffix) so site owners can tune all breakers at
 * once or just one specific breaker.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class Breaker {

	private const STATE_CLOSED    = 'closed';
	private const STATE_OPEN      = 'open';
	private const STATE_HALF_OPEN = 'half_open';

	/**
	 * Transient name prefix. Per-key transient = one option row per
	 * breaker, autoload off.
	 */
	private const TRANSIENT_PREFIX = 'perflocale_breaker_';

	/**
	 * {@see Lock} name prefix for the HALF_OPEN single-probe lease.
	 */
	private const PROBE_LOCK_PREFIX = 'breaker_probe_';

	/**
	 * Defaults — every per-key filter falls back to these if no per-key
	 * value is wired up.
	 */
	private const DEFAULT_THRESHOLD = 5;
	private const DEFAULT_WINDOW    = 300;  // 5 minutes
	private const DEFAULT_COOLDOWN  = 300;  // 5 minutes

	/**
	 * Maximum lifetime of a breaker transient. We use the cooldown +
	 * window as the natural TTL ceiling; this just stops a zombie
	 * "closed but with stale counter" entry from sitting in wp_options
	 * forever if the breaker is never touched again. Cap at 1 day.
	 */
	private const STATE_TTL_CEILING = DAY_IN_SECONDS;

	/**
	 * Is the breaker currently refusing calls?
	 *
	 * Lazy transitions: an OPEN breaker past its cooldown moves to
	 * HALF_OPEN on the first is_open() call after the cooldown expired,
	 * and returns false (allow the probe). The transition is persisted
	 * so subsequent concurrent calls inside the same cooldown-expiry
	 * window don't all see HALF_OPEN simultaneously and probe in
	 * parallel — only the FIRST is_open() call after cooldown promotes
	 * to HALF_OPEN; concurrent calls happening at the SAME microsecond
	 * may all see the transition, which is an acceptable rare race
	 * (worst case: N parallel probes against a degraded provider).
	 *
	 * @param string $key Breaker key (caller-defined).
	 * @return bool True if the breaker is OPEN and calls should be
	 *              refused; false otherwise (CLOSED or HALF_OPEN).
	 */
	public static function is_open( string $key ): bool {
		/** @hook perflocale/breaker/disabled Global kill-switch (returns false always when true). */
		if ( (bool) apply_filters( 'perflocale/breaker/disabled', false ) ) {
			return false;
		}

		$state = self::read_state( $key );

		if ( $state['state'] === self::STATE_CLOSED ) {
			return false;
		}

		if ( $state['state'] === self::STATE_HALF_OPEN ) {
			// Probe state — exactly ONE caller may go through. Everyone else
			// keeps getting the refusal until that probe reports back.
			return ! self::claim_probe( $key );
		}

		// OPEN: check cooldown.
		$cooldown = self::cooldown_seconds( $key );

		if ( time() - $state['opened_at'] >= $cooldown ) {
			// Cooldown elapsed → promote to HALF_OPEN and allow probe.
			$state['state'] = self::STATE_HALF_OPEN;
			self::write_state( $key, $state );
			return ! self::claim_probe( $key );
		}

		return true;
	}

	/**
	 * Try to take the single-probe lease for a HALF_OPEN breaker.
	 *
	 * The state transient alone cannot enforce "one probe": every caller
	 * that reads HALF_OPEN reads the same value and lets itself through, so
	 * N concurrent callers all probed a provider that had just failed hard
	 * enough to open the breaker — N paid calls, N timeouts, and N chances
	 * to re-open it. The lease is a {@see Lock} row: `INSERT IGNORE` on the
	 * options unique key, so InnoDB picks exactly one winner, and the TTL
	 * means a probe that dies without reporting cannot wedge the breaker.
	 *
	 * Only reachable in HALF_OPEN — the CLOSED hot path returns before it,
	 * so a healthy provider pays nothing for this.
	 *
	 * @param string $key Breaker key.
	 * @return bool True when this caller owns the probe.
	 */
	private static function claim_probe( string $key ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			// No database to arbitrate the lease (early boot, or a harness
			// with stubbed storage). Fall back to the pre-lease behaviour and
			// let the probe through: one extra probe against a degraded
			// provider is a far better outcome than a fatal raised by the
			// very code whose job is to keep a failing dependency from taking
			// the site down. Same fallback the streak counter takes.
			return true;
		}

		/**
		 * Filter how long a single HALF_OPEN probe may hold its lease.
		 *
		 * Defaults to the breaker's own cooldown, clamped to 30-300s: long
		 * enough to cover a slow provider timeout, short enough that a
		 * killed worker's lease frees up well inside one cooldown.
		 *
		 * @hook perflocale/breaker/probe_lease_seconds
		 * @param int    $seconds Lease TTL.
		 * @param string $key     Breaker key.
		 */
		$ttl = (int) apply_filters(
			'perflocale/breaker/probe_lease_seconds',
			min( 300, max( 30, self::cooldown_seconds( $key ) ) ),
			$key
		);

		return Lock::acquire( self::PROBE_LOCK_PREFIX . self::sanitize_key( $key ), max( 1, $ttl ) );
	}

	/**
	 * Release the single-probe lease. Called the moment the probe reports
	 * back (either way), so the next HALF_OPEN window is immediately
	 * available instead of waiting out the lease TTL.
	 *
	 * @param string $key Breaker key.
	 * @return void
	 */
	private static function release_probe( string $key ): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		Lock::release( self::PROBE_LOCK_PREFIX . self::sanitize_key( $key ) );
	}

	/**
	 * Record a failed call. Increments the sliding-window failure counter
	 * in CLOSED state; trips to OPEN if the counter crosses the threshold.
	 * In HALF_OPEN state, immediately re-opens with a fresh cooldown.
	 *
	 * @param string $key       Breaker key.
	 * @param string $reason    Short tag for the failure (e.g. "auth",
	 *                          "rate_limit", "transient"). Stored as the
	 *                          most-recent reason for Site Health display.
	 * @param int    $threshold_override Optional one-shot override for
	 *                          this single record (e.g. an auth error
	 *                          can trip the breaker on the first hit
	 *                          regardless of the configured threshold).
	 *                          Pass 0 to use the configured threshold.
	 * @return void
	 */
	public static function record_failure( string $key, string $reason = '', int $threshold_override = 0 ): void {
		if ( (bool) apply_filters( 'perflocale/breaker/disabled', false ) ) {
			return;
		}

		$state = self::read_state( $key );
		$now   = time();

		if ( $state['state'] === self::STATE_HALF_OPEN ) {
			// Probe failed — straight back to OPEN with fresh cooldown.
			self::write_state(
				$key,
				[
					'state'         => self::STATE_OPEN,
					'failures'      => max( 1, $state['failures'] ),
					'first_failure' => $now,
					'last_failure'  => $now,
					'opened_at'     => $now,
					'reason'        => $reason,
				]
			);
			self::release_probe( $key );
			return;
		}

		if ( $state['state'] === self::STATE_OPEN ) {
			// Already open — bump last_failure for diagnostics but don't
			// extend the cooldown. is_open() guards against new calls
			// reaching this path under normal use.
			$state['last_failure'] = $now;
			$state['reason']       = $reason;
			self::write_state( $key, $state );
			return;
		}

		// CLOSED: count toward the threshold. The staleness gap is measured
		// from the LAST failure, not the first (sliding, not tumbling): a
		// hung/rate-limited provider costs 93-600s wall-clock PER failed
		// call (retries × timeout + Retry-After sleeps), so anchoring the
		// window at first_failure meant the counter reset before it could
		// ever reach the threshold — the breaker could mathematically never
		// open for exactly the slow-failure storms it exists to stop.
		// Consecutive failures each within one window of the previous keep
		// accumulating; a success still clears the counter via
		// record_success().
		$window = self::window_seconds( $key );

		// One atomic DB statement decides "new streak" vs "+1" and returns the
		// committed count — never `++$state['failures']`. A provider outage
		// fails many in-flight requests at once; deriving the count from the
		// serialized state transient lost those concurrent increments (every
		// worker read the same stale value and overwrote its peers), so the
		// counter never reached the threshold and the breaker stayed CLOSED
		// through the exact storm it exists to stop — the plugin kept spending
		// its full retry budget (up to 3 tries + backoff + Retry-After sleeps)
		// against a dead endpoint.
		$stale = ( 0 === $state['last_failure'] || ( $now - $state['last_failure'] ) > $window );

		$state['failures'] = self::bump_streak_counter( $key, $window, $now, (int) $state['failures'], $stale );

		if ( 1 === $state['failures'] ) {
			$state['first_failure'] = $now;
		}

		$state['last_failure'] = $now;
		$state['reason']       = $reason;

		$threshold = $threshold_override > 0 ? $threshold_override : self::threshold( $key );

		if ( $state['failures'] >= $threshold ) {
			$state['state']     = self::STATE_OPEN;
			$state['opened_at'] = $now;
		} elseif ( self::read_state( $key )['state'] !== self::STATE_CLOSED ) {
			// Publishing is the one step that is NOT atomic: the counter is
			// decided by the database, but the state transient is a blind
			// whole-array write. During a failure storm a caller whose count
			// stayed below the threshold can land its write AFTER a peer that
			// crossed it, replacing `open` with a stale `closed/n` — and the
			// breaker that had just tripped starts forwarding calls again.
			// The re-read in the elseif above stands this caller down when
			// someone already opened it. Not a CAS (transients offer none),
			// but it closes the window from "the whole call" to "these two
			// lines", and it only runs on the sub-threshold failure path.
			return;
		}

		self::write_state( $key, $state );
	}

	/**
	 * Record a successful call. In CLOSED state this clears any
	 * accumulated failure counter (so a single hiccup doesn't take the
	 * breaker halfway to OPEN forever). In HALF_OPEN state this
	 * transitions back to CLOSED — the probe worked, normal operation
	 * resumes.
	 *
	 * @param string $key Breaker key.
	 * @return void
	 */
	public static function record_success( string $key ): void {
		if ( (bool) apply_filters( 'perflocale/breaker/disabled', false ) ) {
			return;
		}

		$state = self::read_state( $key );

		if ( $state['state'] === self::STATE_CLOSED && $state['failures'] === 0 ) {
			// Nothing to clear — most calls hit this path. Skip the
			// write to keep the hot path free of option_update traffic.
			return;
		}

		self::write_state( $key, self::initial_state() );
		self::clear_streak_counter( $key );
		self::release_probe( $key );
	}

	/**
	 * Force the breaker back to fully-closed state. Intended for the
	 * Site Health "Reset" action — admin override after the operator
	 * has manually verified the downstream is healthy.
	 *
	 * @param string $key Breaker key.
	 * @return void
	 */
	public static function reset( string $key ): void {
		$sanitized = self::sanitize_key( $key );
		delete_transient( self::TRANSIENT_PREFIX . $sanitized );
		self::clear_streak_counter( $key );
		self::release_probe( $key );
		self::index_remove( $sanitized );
	}

	/**
	 * Option name backing the index of currently-tracked breaker keys.
	 *
	 * Used by {@see list_all()} so the Site Health card can enumerate
	 * active breakers regardless of where transient state actually
	 * lives (wp_options on a vanilla install, Redis/Memcached when an
	 * external object cache is wired up). Without this index, list_all()
	 * scanning wp_options sees nothing on a Redis install — transients
	 * bypass wp_options when an external cache is active.
	 *
	 * Stored as a flat sanitised-key array. Capped at 200 entries —
	 * matches the previous wp_options scan cap and protects against
	 * a misbehaving caller that creates one breaker per request.
	 * autoload=no because list_all() is admin/Site-Health only.
	 */
	private const INDEX_OPTION = 'perflocale_breakers_index';

	/**
	 * Add a key to the tracked-breakers index. Called on every write
	 * path so an OPEN breaker can be enumerated by list_all() even
	 * when transient storage is opaque (Redis). Idempotent.
	 *
	 * Deliberately lock-free: this runs on the record_failure() hot path
	 * (every external-service failure). The index is observability-only —
	 * the breaker STATE lives in transients — and a lost write under a
	 * concurrent first-failure self-heals on the next failure AND is
	 * covered by list_all()'s wp_options fallback scan on non-Redis
	 * installs. Serialising it isn't worth the per-failure DB round-trips.
	 *
	 * @param string $sanitized_key Already-sanitised key.
	 * @return void
	 */
	private static function index_add( string $sanitized_key ): void {
		$index = (array) get_option( self::INDEX_OPTION, [] );

		if ( in_array( $sanitized_key, $index, true ) ) {
			return;
		}

		// Cap at 200 — same ceiling list_all() always honoured. Drop the
		// oldest entry on overflow rather than refusing the write.
		if ( count( $index ) >= 200 ) {
			array_shift( $index );
		}

		$index[] = $sanitized_key;
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Remove a key from the tracked-breakers index. Called from
	 * {@see reset()} so a forced-close breaker disappears from the
	 * Site Health card on the next render.
	 *
	 * @param string $sanitized_key Already-sanitised key.
	 * @return void
	 */
	private static function index_remove( string $sanitized_key ): void {
		$index = (array) get_option( self::INDEX_OPTION, [] );
		$index = array_values( array_filter( $index, static fn( $k ) => $k !== $sanitized_key ) );
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Current breaker state as an associative array for diagnostics.
	 *
	 * Keys: state, failures, first_failure, last_failure, opened_at,
	 * reason, cooldown_remaining (computed).
	 *
	 * @param string $key Breaker key.
	 * @return array<string, mixed>
	 */
	public static function status( string $key ): array {
		$state = self::read_state( $key );

		$cooldown_remaining = 0;
		if ( $state['state'] === self::STATE_OPEN ) {
			$cooldown_remaining = max( 0, self::cooldown_seconds( $key ) - ( time() - $state['opened_at'] ) );
		}

		$state['cooldown_remaining'] = $cooldown_remaining;

		return $state;
	}

	/**
	 * Enumerate every currently-known breaker (any state) by scanning
	 * the transient table. Used by the Site Health card and any
	 * "list all breakers" UI.
	 *
	 * Returns rows keyed by the breaker name (without the prefix), each
	 * value the {@see status()} array. The scan is bounded: at most 200
	 * rows. Real installs have a handful (one per provider, one per
	 * webhook, etc.) — this cap protects against a misconfigured loop
	 * that creates breakers per request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_all(): array {
		// Primary source: the breaker-tracking index (option-backed,
		// works regardless of transient storage backend). Every
		// {@see write_state()} call adds the key; {@see reset()} removes it.
		// On a vanilla install transients also exist in wp_options, but
		// on Redis/Memcached installs the index is the only enumeration
		// source — a raw wp_options scan would return nothing.
		$index = (array) get_option( self::INDEX_OPTION, [] );

		// Defensive secondary scan: any orphaned wp_options transient
		// rows (e.g. from before the index was introduced, or written
		// directly by integrators) get picked up too. Union the two
		// sources and de-duplicate.
		global $wpdb;

		if ( $wpdb instanceof \wpdb ) {
			$prefix = '_transient_' . self::TRANSIENT_PREFIX;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 200",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $option_name ) {
					$key = substr( (string) $option_name, strlen( $prefix ) );
					if ( $key !== '' && ! in_array( $key, $index, true ) ) {
						$index[] = $key;
					}
				}
			}
		}

		if ( empty( $index ) ) {
			return [];
		}

		$out = [];

		foreach ( $index as $key ) {
			$status               = self::status( (string) $key );
			$out[ (string) $key ] = $status;
		}

		return $out;
	}

	/**
	 * Initial / fresh-CLOSED state. Centralised so every reset path
	 * agrees on the shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function initial_state(): array {
		return [
			'state'         => self::STATE_CLOSED,
			'failures'      => 0,
			'first_failure' => 0,
			'last_failure'  => 0,
			'opened_at'     => 0,
			'reason'        => '',
		];
	}

	/**
	 * Read current state from the transient, or return initial state on
	 * miss. Always returns a fully-populated array (no missing keys).
	 *
	 * @param string $key Breaker key.
	 * @return array<string, mixed>
	 */
	/**
	 * Option backing the atomic per-key failure counter.
	 *
	 * @param string $key Breaker key (raw).
	 * @return string
	 */
	private static function counter_option( string $key ): string {
		return 'perflocale_breaker_n_' . self::sanitize_key( $key );
	}

	/**
	 * Count this failure and return the committed streak length.
	 *
	 * BOTH the increment and the sliding-window reset happen inside ONE
	 * statement, under the InnoDB row lock. Splitting them is what made the
	 * breaker unreliable: whether to reset or increment was decided from the
	 * state transient, so a burst of simultaneous failures all read
	 * `last_failure = 0`, all concluded "new streak", and all wrote 1 —
	 * the counter never climbed to the threshold and the breaker stayed
	 * CLOSED through the outage it exists to stop.
	 *
	 * The row stores `count:last_failure_ts`, so the SQL can compare the
	 * stored timestamp against the window and either restart at 1 or add
	 * one, atomically, with no read-modify-write in PHP.
	 *
	 * Deliberately NOT autoloaded: read only after a call has already
	 * failed, never on the hot `is_open()` path.
	 *
	 * @param string $key     Breaker key (raw).
	 * @param int    $window  Sliding-window seconds.
	 * @param int    $now     Current unix timestamp.
	 * @param int    $current Failure count already in the state transient.
	 * @param bool   $stale   Whether that state is outside the window.
	 * @return int Committed failure count (>= 1).
	 */
	private static function bump_streak_counter( string $key, int $window, int $now, int $current, bool $stale ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			// No database (early boot, or a unit-test harness with stubbed
			// storage): fall back to counting from the state transient. That
			// is the pre-atomic behaviour — it can still lose a concurrent
			// increment, but a breaker that counts imperfectly beats one that
			// never counts at all.
			return $stale ? 1 : max( 1, $current + 1 );
		}

		$option = self::counter_option( $key );
		$fresh  = '1:' . $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %s, 'no')
				 ON DUPLICATE KEY UPDATE option_value = IF(
					 %d - CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) > %d,
					 %s,
					 CONCAT(
						 CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) + 1,
						 ':',
						 %d
					 )
				 )",
				$option,
				$fresh,
				$now,
				$window,
				$fresh,
				$now
			)
		);

		// Read back through raw SQL — the row was written with raw SQL, so the
		// options cache still holds the pre-increment value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$committed = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);

		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return max( 1, (int) strtok( $committed, ':' ) );
	}

	/**
	 * Drop the streak counter (a success or an explicit reset ends the
	 * streak, so the row would otherwise linger until the next failure).
	 *
	 * @param string $key Breaker key (raw).
	 * @return void
	 */
	private static function clear_streak_counter( string $key ): void {
		delete_option( self::counter_option( $key ) );
	}

	private static function read_state( string $key ): array {
		$stored = get_transient( self::TRANSIENT_PREFIX . self::sanitize_key( $key ) );

		if ( ! is_array( $stored ) ) {
			return self::initial_state();
		}

		// Defensive defaults: pad missing keys so callers never have to
		// branch on isset(). Catches the case where a schema-extension
		// added a new field after the transient was last written.
		$state = array_merge( self::initial_state(), $stored );

		// Coerce the shape as well as the keys. Breaker state lives in a
		// transient, i.e. in whatever object cache the host runs - shared
		// with every other plugin, and restorable from a stale dump. An
		// array or object where a timestamp belongs turns `time() - $x` into
		// a fatal TypeError on a path whose entire job is to keep a failing
		// dependency from taking the site down. Anything that is not a plain
		// scalar counts as absent.
		foreach ( [ 'failures', 'first_failure', 'last_failure', 'opened_at' ] as $int_field ) {
			$state[ $int_field ] = is_numeric( $state[ $int_field ] ) ? max( 0, (int) $state[ $int_field ] ) : 0;
		}

		$state['state']  = in_array( $state['state'], [ self::STATE_CLOSED, self::STATE_OPEN, self::STATE_HALF_OPEN ], true )
			? $state['state']
			: self::STATE_CLOSED;
		$state['reason'] = is_scalar( $state['reason'] ) ? (string) $state['reason'] : '';

		return $state;
	}

	/**
	 * Persist state. TTL is the larger of (cooldown + window) so the
	 * row sticks around long enough for the next call to read it, but
	 * is cleaned up by core's transient GC if the breaker goes idle.
	 *
	 * @param string               $key   Breaker key.
	 * @param array<string, mixed> $state State payload.
	 * @return void
	 */
	private static function write_state( string $key, array $state ): void {
		$ttl = min(
			self::STATE_TTL_CEILING,
			max( 60, self::cooldown_seconds( $key ) + self::window_seconds( $key ) )
		);

		$sanitized = self::sanitize_key( $key );

		if ( false === set_transient( self::TRANSIENT_PREFIX . $sanitized, $state, $ttl ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; the breaker can't hold open across requests if its state won't persist.
			error_log( 'PerfLocale Breaker: failed to persist circuit state for "' . $sanitized . '" — the breaker will not hold open across requests until the transient store recovers.' );
		}

		self::index_add( $sanitized );
	}

	/**
	 * Filtered threshold for {$key}. Per-key filter falls back to
	 * generic filter, which falls back to the default constant.
	 *
	 * @param string $key Breaker key.
	 * @return int
	 */
	private static function threshold( string $key ): int {
		$generic = (int) apply_filters( 'perflocale/breaker/threshold', self::DEFAULT_THRESHOLD );
		$per_key = (int) apply_filters( 'perflocale/breaker/threshold/' . $key, $generic );
		return max( 1, $per_key );
	}

	/**
	 * Filtered window seconds for {$key}.
	 *
	 * @param string $key Breaker key.
	 * @return int
	 */
	private static function window_seconds( string $key ): int {
		$generic = (int) apply_filters( 'perflocale/breaker/window_seconds', self::DEFAULT_WINDOW );
		$per_key = (int) apply_filters( 'perflocale/breaker/window_seconds/' . $key, $generic );
		return max( 1, $per_key );
	}

	/**
	 * Filtered cooldown seconds for {$key}.
	 *
	 * @param string $key Breaker key.
	 * @return int
	 */
	private static function cooldown_seconds( string $key ): int {
		$generic_raw = apply_filters( 'perflocale/breaker/cooldown_seconds', self::DEFAULT_COOLDOWN );
		if ( ! is_numeric( $generic_raw ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/breaker/cooldown_seconds", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/breaker/cooldown_seconds returned %s — must be an int (seconds). Falling back to the plugin default.', 'perflocale' ),
						get_debug_type( $generic_raw )
					)
				),
				'1.0.0'
			);
			$generic_raw = self::DEFAULT_COOLDOWN;
		}
		$generic     = (int) $generic_raw;
		$per_key_raw = apply_filters( 'perflocale/breaker/cooldown_seconds/' . $key, $generic );
		if ( ! is_numeric( $per_key_raw ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/breaker/cooldown_seconds/' . esc_html( $key ) . '", ... )',
				esc_html(
					sprintf(
						/* translators: 1: breaker key, 2: offending return type. */
						__( 'A hook on perflocale/breaker/cooldown_seconds/%1$s returned %2$s — must be an int (seconds). Falling back to the generic cooldown.', 'perflocale' ),
						$key,
						get_debug_type( $per_key_raw )
					)
				),
				'1.0.0'
			);
			$per_key_raw = $generic;
		}
		return max( 1, (int) $per_key_raw );
	}

	/**
	 * Sanitise caller-supplied key to a transient-safe slug. Caller
	 * inputs are usually static / controlled ("mt_<provider_id>",
	 * "webhook_<uuid>") but the helper guards against an integrator
	 * passing in a value that breaks the option-name charset.
	 *
	 * @param string $key Caller key.
	 * @return string
	 */
	private static function sanitize_key( string $key ): string {
		// Allow lowercase letters, digits, underscore, hyphen. Anything
		// else gets stripped. Cap at 100 chars to fit comfortably inside
		// the wp_options.option_name 191-char limit alongside the
		// `_transient_perflocale_breaker_` (30-char) prefix.
		$sanitized = preg_replace( '/[^a-z0-9_-]+/', '_', strtolower( $key ) );

		if ( $sanitized === null ) {
			return 'invalid';
		}

		return substr( $sanitized, 0, 100 );
	}
}
