<?php
/**
 * Three-state circuit breaker keyed by a string.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Concurrency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Circuit breaker for external calls (MT providers, webhooks, FX and geo IP services).
 *
 * Closed counts failures in a sliding window and opens at the threshold; open refuses calls for the cooldown, then
 * half-open lets one leased probe through. An evicted state transient costs one failed call, never a stuck-open breaker.
 * Tuned by the perflocale/breaker/* filters; see docs-ai/11-code-rationale.md, "Breaker: states and filters".
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class Breaker {

	private const STATE_CLOSED    = 'closed';
	private const STATE_OPEN      = 'open';
	private const STATE_HALF_OPEN = 'half_open';

	/**
	 * Transient name prefix (one transient per breaker).
	 */
	private const TRANSIENT_PREFIX = 'perflocale_breaker_';

	/**
	 * {@see Lock} name prefix for the HALF_OPEN single-probe lease.
	 */
	private const PROBE_LOCK_PREFIX = 'breaker_probe_';

	/**
	 * Defaults behind the generic and per-key filters.
	 */
	private const DEFAULT_THRESHOLD = 5;
	private const DEFAULT_WINDOW    = 300;  // 5 minutes
	private const DEFAULT_COOLDOWN  = 300;  // 5 minutes

	/**
	 * Longest lifetime of a breaker transient, so an idle breaker's row does not stay forever.
	 */
	private const STATE_TTL_CEILING = DAY_IN_SECONDS;

	/**
	 * Is the breaker currently refusing calls?
	 *
	 * An OPEN breaker past its cooldown is promoted to HALF_OPEN here; the probe lease
	 * (claim_probe()) then lets exactly one caller through.
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
			// Only the lease holder probes; everyone else is refused until it reports back.
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
	 * The state transient cannot enforce one probe (every reader of HALF_OPEN lets itself through), so a
	 * {@see Lock} row picks one winner; its TTL frees the breaker when a probe dies without reporting.
	 *
	 * @param string $key Breaker key.
	 * @return bool True when this caller owns the probe.
	 */
	private static function claim_probe( string $key ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			// No database (early boot, stubbed storage): let the probe through rather than fatal.
			return true;
		}

		/**
		 * Filter how long a single HALF_OPEN probe may hold its lease.
		 *
		 * Defaults to the breaker's cooldown clamped to 30-300 s: covers a slow provider timeout, yet a
		 * killed worker's lease frees within one cooldown.
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
	 * Release the single-probe lease as soon as the probe reports back, without waiting out its TTL.
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
	 * Record a failed call: CLOSED counts it and opens at the threshold, HALF_OPEN reopens with a fresh cooldown.
	 *
	 * @param string $key       Breaker key.
	 * @param string $reason    Short failure tag ("auth", "rate_limit"), shown in Site Health.
	 * @param int    $threshold_override Threshold for this record only (an auth error can open at once); 0 uses the configured one.
	 * @return void
	 */
	public static function record_failure( string $key, string $reason = '', int $threshold_override = 0 ): void {
		if ( (bool) apply_filters( 'perflocale/breaker/disabled', false ) ) {
			return;
		}

		$state = self::read_state( $key );
		$now   = time();

		if ( $state['state'] === self::STATE_HALF_OPEN ) {
			// Probe failed - straight back to OPEN with fresh cooldown.
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
			// Already open: record the failure for diagnostics without extending the cooldown.
			$state['last_failure'] = $now;
			$state['reason']       = $reason;
			self::write_state( $key, $state );
			return;
		}

		// CLOSED: the window runs from the LAST failure: one slow failed call can outlast a window anchored at
		// the first, so the breaker could never open. See docs-ai/11-code-rationale.md, "Breaker: failure streak".
		$window = self::window_seconds( $key );

		// The database decides "new streak" or "+1" in one statement; never `++$state['failures']`,
		// which loses concurrent increments during an outage.
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
			// A peer opened it: a sub-threshold whole-array write here would replace `open` with a stale
			// `closed`. Not a CAS (transients have none), but it narrows the window to these two lines.
			return;
		}

		self::write_state( $key, $state );
	}

	/**
	 * Record a successful call: clears the failure streak and closes a HALF_OPEN breaker.
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
			// The common path: nothing to clear, so no write.
			return;
		}

		self::write_state( $key, self::initial_state() );
		self::clear_streak_counter( $key );
		self::release_probe( $key );
	}

	/**
	 * Force the breaker closed (the Site Health "Reset" action).
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
	 * Option listing the tracked breaker keys (sanitised, at most 200, not autoloaded).
	 *
	 * The list_all() source under a persistent object cache, where transients never reach wp_options.
	 */
	private const INDEX_OPTION = 'perflocale_breakers_index';

	/**
	 * Add a key to the tracked-breakers index (every state write calls it).
	 *
	 * Lock-free on purpose: the index is display-only, and a write lost to a concurrent first failure
	 * returns with the next failure.
	 *
	 * @param string $sanitized_key Already-sanitised key.
	 * @return void
	 */
	private static function index_add( string $sanitized_key ): void {
		$index = (array) get_option( self::INDEX_OPTION, [] );

		if ( in_array( $sanitized_key, $index, true ) ) {
			return;
		}

		// At the cap, drop the oldest entry rather than refuse the new one.
		if ( count( $index ) >= 200 ) {
			array_shift( $index );
		}

		$index[] = $sanitized_key;
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Remove a key from the tracked-breakers index (on reset()).
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
	 * Current breaker state for diagnostics: the stored fields plus cooldown_remaining.
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
	 * Every known breaker's status(), keyed by sanitised breaker key (Site Health card).
	 *
	 * Reads the index plus up to 200 transient rows from wp_options.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_all(): array {
		// The index is the only source under a persistent object cache.
		$index = (array) get_option( self::INDEX_OPTION, [] );

		// Also transient rows the index misses (a lost index write, a row written by other code).
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
	 * Fresh CLOSED state, the one shape every reset path writes.
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
	 * Read the state transient with every key present, or the initial state on a miss.
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
	 * The reset-or-increment decision and the increment are one statement on a `count:last_failure_ts` row
	 * (not autoloaded): split, concurrent failures all start a new streak. SQL portability: see
	 * docs-ai/11-code-rationale.md, "Breaker: failure streak".
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
			// No database (early boot, stubbed storage): count from the state transient, which can lose a
			// concurrent increment but still counts.
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
					 %d - CAST(CASE WHEN INSTR(option_value, ':') > 0 THEN SUBSTR(option_value, INSTR(option_value, ':') + 1) ELSE option_value END AS UNSIGNED) > %d,
					 %s,
					 CONCAT(
						 ( CAST(CASE WHEN INSTR(option_value, ':') > 0 THEN SUBSTR(option_value, 1, INSTR(option_value, ':') - 1) ELSE option_value END AS UNSIGNED) + 1 ),
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

		// Read back through raw SQL - the row was written with raw SQL, so the
		// options cache still holds the pre-increment value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$committed = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);

		wp_cache_delete( $option, 'options' );
		\PerfLocale\Database\NotOptions::unlist( [ $option ] );

		return max( 1, (int) strtok( $committed, ':' ) );
	}

	/**
	 * Drop the streak counter row (a success or a reset ends the streak).
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

		$state = array_merge( self::initial_state(), $stored );

		// Coerce types too: a shared or restored cache can hold an array where a timestamp belongs, and
		// `time() - $x` would then fatal on the path meant to protect the site.
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
	 * Persist state for cooldown + window seconds (at least 60, at most STATE_TTL_CEILING).
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
			error_log( 'PerfLocale Breaker: failed to persist circuit state for "' . $sanitized . '" - the breaker will not hold open across requests until the transient store recovers.' );
		}

		self::index_add( $sanitized );
	}

	/**
	 * Filtered threshold for {$key}: per-key filter, else generic filter, else the default.
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
						__( 'A hook on perflocale/breaker/cooldown_seconds returned %s - must be an int (seconds). Falling back to the plugin default.', 'perflocale' ),
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
						__( 'A hook on perflocale/breaker/cooldown_seconds/%1$s returned %2$s - must be an int (seconds). Falling back to the generic cooldown.', 'perflocale' ),
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
	 * Sanitise a caller key to a transient-safe slug.
	 *
	 * @param string $key Caller key.
	 * @return string
	 */
	private static function sanitize_key( string $key ): string {
		// Lowercase letters, digits, `_` and `-`; 100 characters keep every prefixed option name under 191.
		$sanitized = preg_replace( '/[^a-z0-9_-]+/', '_', strtolower( $key ) );

		if ( $sanitized === null ) {
			return 'invalid';
		}

		return substr( $sanitized, 0, 100 );
	}
}
