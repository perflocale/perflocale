<?php
/**
 * Time limits of a background slice that sends provider calls.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A web slice's budget, counted from the start of its request (WP-Cron and Action Scheduler run other work first),
 * and the deadline the bulk jobs set for that slice's provider calls; without a deadline calls are unchanged.
 */
final class CallDeadline {

	/**
	 * Wall-clock limit assumed for a web request (the common PHP-FPM kill).
	 */
	public const REQUEST_SECONDS = 30.0;

	/**
	 * Seconds kept free before that limit for the checkpoint, lock release and scheduling.
	 */
	public const MARGIN_SECONDS = 5.0;

	/**
	 * Least time a provider call is started with.
	 */
	public const MIN_CALL_SECONDS = 2.0;

	/**
	 * A failed call that had at least this long failed on its own, not for lack of time.
	 */
	public const SLOW_CALL_SECONDS = 15.0;

	/**
	 * Default longest a web slice's provider work runs.
	 */
	public const WEB_SLICE_MAX_SECONDS = 60.0;

	/**
	 * Lowest value the cap filter may set: room for the slice's setup and a provider call (MIN_CALL_SECONDS).
	 */
	public const WEB_SLICE_MIN_SECONDS = 10.0;

	/**
	 * Highest value the cap filter may set: JobResume::LEASE - DEAD_MAN_GRACE, or a live worker is taken over.
	 */
	public const WEB_SLICE_LIMIT_SECONDS = 90.0;

	/**
	 * Unix time (float) the current slice's provider calls must end by.
	 *
	 * @var float|null
	 */
	private static ?float $at = null;

	/**
	 * Set or clear (null) the deadline.
	 *
	 * @param float|null $at Unix time.
	 * @return float|null The previous deadline, for the caller to restore.
	 */
	public static function set( ?float $at ): ?float {
		$previous = self::$at;
		self::$at = $at;

		return $previous;
	}

	/**
	 * Seconds left before the deadline; null without one.
	 *
	 * @return float|null
	 */
	public static function left(): ?float {
		return null === self::$at ? null : self::$at - microtime( true );
	}

	/**
	 * A provider call's timeout, cut to the time left.
	 *
	 * @param float $timeout Timeout the call would use.
	 * @return float
	 * @throws DeadlineReachedException When less than MIN_CALL_SECONDS are left.
	 */
	public static function timeout( float $timeout ): float {
		$left = self::left();

		if ( null === $left ) {
			return $timeout;
		}

		if ( $left < self::MIN_CALL_SECONDS ) {
			throw new DeadlineReachedException();
		}

		return min( $timeout, $left );
	}

	/**
	 * Sleep before a retry when the wait and one more call fit before the deadline.
	 *
	 * @param int $seconds Wait.
	 * @return bool False, without sleeping, when they do not fit.
	 */
	public static function wait( int $seconds ): bool {
		$left = self::left();

		if ( null !== $left && $seconds + self::MIN_CALL_SECONDS > $left ) {
			return false;
		}

		sleep( $seconds );

		return true;
	}

	/**
	 * A filtered number of seconds as a float; null when the filter returned no number.
	 *
	 * @param mixed $value Filter result.
	 * @return float|null
	 */
	private static function seconds( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Seconds a web slice may run, and the time its work must end by.
	 *
	 * @param float $job_seconds The job's slice budget (0 or less: none, the work still ends by the cap).
	 * @return array{0: float, 1: float}
	 */
	public static function for_slice( float $job_seconds ): array {
		/**
		 * Filter the longest a web slice of a background job sends provider calls, in seconds (10 to 90, default 60).
		 * Also caps a raised or 0 slice_seconds budget; WP-CLI runs are not capped.
		 *
		 * @hook  perflocale/jobs/web_slice_max_seconds
		 * @since 1.0.7.4
		 *
		 * @param float $seconds Default 60.
		 */
		$max = self::seconds( apply_filters( 'perflocale/jobs/web_slice_max_seconds', self::WEB_SLICE_MAX_SECONDS ) );
		$max = null === $max ? self::WEB_SLICE_MAX_SECONDS : max( self::WEB_SLICE_MIN_SECONDS, min( self::WEB_SLICE_LIMIT_SECONDS, $max ) );
		$now = microtime( true );

		if ( $job_seconds <= 0 ) {
			return [ 0.0, $now + $max ];
		}

		$end   = $now + $job_seconds + self::MARGIN_SECONDS;
		$start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- A number set by PHP, read as one.

		if ( is_numeric( $start ) ) {
			// A budget raised past the default says the host allows longer requests.
			$cap     = max( self::REQUEST_SECONDS - self::MARGIN_SECONDS, $job_seconds );
			$elapsed = $now - (float) $start;

			// Alive past the whole limit: this host has no such limit.
			if ( $elapsed >= 0 && $elapsed < $cap + self::MARGIN_SECONDS ) {
				$end = (float) $start + $cap;
			}
		}

		$end = min( $end, $now + $max );

		// Never 0, which means "no time budget"; a slice always runs its first unit.
		return [ max( 0.001, min( $job_seconds, $end - $now ) ), $end ];
	}
}
