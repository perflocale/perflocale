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
	 * Longest Retry-After wait slept inside one request; a longer one is a paced stop.
	 */
	public const RETRY_AFTER_SLEEP_MAX = 60;

	/**
	 * Call times kept for the latency rule.
	 */
	private const TIMES = 8;

	/**
	 * Unix time (float) the interactive web request started; null outside one.
	 *
	 * @var float|null
	 */
	private static ?float $request = null;

	/**
	 * Whether the run is WP-CLI; null reads PHP_SAPI.
	 *
	 * @var bool|null
	 */
	public static ?bool $cli = null;

	/**
	 * Recent call times in seconds, newest last; a negative one is a call cut at that many seconds (it needed longer).
	 *
	 * @var list<float>
	 */
	private static array $times = [];

	/**
	 * Unix time (float) the current slice's provider calls must end by.
	 *
	 * @var float|null
	 */
	private static ?float $at = null;

	/**
	 * Seconds a slice that starts with its request would have (for_slice()); null without a deadline.
	 *
	 * @var float|null
	 */
	private static ?float $window = null;

	/**
	 * Set or clear (null) the deadline.
	 *
	 * @param float|null $at     Unix time.
	 * @param float|null $window The slice's full window in seconds (for_slice()); null: the time left now.
	 * @return float|null The previous deadline, for the caller to restore.
	 */
	public static function set( ?float $at, ?float $window = null ): ?float {
		$previous     = self::$at;
		self::$at     = $at;
		self::$window = null === $at ? null : ( $window ?? $at - microtime( true ) );

		return $previous;
	}

	/**
	 * Seconds a slice that starts with its request has; null without a deadline. Other work in the request (WP-Cron
	 * events before the job) shortens left() but not this.
	 *
	 * @return float|null
	 */
	public static function window(): ?float {
		return null === self::$at ? null : self::$window;
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
	 * Count an interactive web request's room (room()) from its start, so no call or wait outlives it; WP-CLI has none.
	 *
	 * @param bool $on Whether the current work is such a request.
	 * @return bool Whether it was on before.
	 */
	public static function in_request( bool $on ): bool {
		$was   = null !== self::$request;
		$start = $_SERVER['REQUEST_TIME_FLOAT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- A number set by PHP, read as one.
		$now   = microtime( true );

		self::$request = $on && ! ( self::$cli ?? 'cli' === PHP_SAPI ) ? ( is_numeric( $start ) && (float) $start <= $now ? (float) $start : $now ) : null;

		return $was;
	}

	/**
	 * Whether an interactive request's room is counted (in_request()).
	 *
	 * @return bool
	 */
	public static function interactive(): bool {
		return null !== self::$request;
	}

	/**
	 * Seconds the current work has for provider calls and waits: left() in a slice, the rest of the request's
	 * REQUEST_SECONDS - MARGIN_SECONDS in an interactive web request, else null (no limit).
	 *
	 * @return float|null
	 */
	public static function room(): ?float {
		if ( null !== self::$at ) {
			return self::left();
		}

		return null === self::$request ? null : self::REQUEST_SECONDS - self::MARGIN_SECONDS - ( microtime( true ) - self::$request );
	}

	/**
	 * A provider call's timeout, cut to the time left; in an interactive request MIN_CALL_SECONDS stay for the save.
	 *
	 * @param float $timeout Timeout the call would use.
	 * @return float
	 * @throws DeadlineReachedException When less than MIN_CALL_SECONDS are left for the call.
	 */
	public static function timeout( float $timeout ): float {
		$room = self::room();

		if ( null === $room ) {
			return $timeout;
		}

		$room -= null === self::$at ? self::MIN_CALL_SECONDS : 0.0;

		if ( $room < self::MIN_CALL_SECONDS ) {
			throw new DeadlineReachedException();
		}

		return min( $timeout, $room );
	}

	/**
	 * Sleep before a retry when the wait and one more call fit in the room.
	 *
	 * @param int $seconds Wait.
	 * @return bool False, without sleeping, when they do not fit.
	 */
	public static function wait( int $seconds ): bool {
		$room = self::room();

		if ( null !== $room && $seconds + self::MIN_CALL_SECONDS > $room ) {
			return false;
		}

		sleep( $seconds );

		return true;
	}

	/**
	 * Sleep a provider's Retry-After wait when it is at most RETRY_AFTER_SLEEP_MAX, a retry follows and the wait plus
	 * the expected call fit in the room.
	 *
	 * @param int  $seconds Wait.
	 * @param bool $retry   Whether another attempt follows.
	 * @return bool False, without sleeping: the caller makes it a paced stop.
	 */
	public static function pause( int $seconds, bool $retry ): bool {
		$room = self::room();
		$need = max( self::MIN_CALL_SECONDS, self::expected() ?? 0.0 );

		if ( ! $retry || $seconds > self::RETRY_AFTER_SLEEP_MAX || ( null !== $room && $seconds + $need > $room ) ) {
			return false;
		}

		sleep( max( 0, $seconds ) );

		return true;
	}

	/**
	 * Record a provider call's time; $cut for a call the deadline cut (it needed longer).
	 *
	 * @param float $seconds Seconds.
	 * @param bool  $cut     Cut by the deadline.
	 * @return void
	 */
	public static function took( float $seconds, bool $cut = false ): void {
		$seconds        = max( 0.001, $seconds );
		self::$times[] = $cut ? -$seconds : $seconds;
		self::$times   = array_slice( self::$times, -self::TIMES );
	}

	/**
	 * Recent call times as took() keeps them, or replace them (a job's checkpoint); anything but finite numbers is dropped.
	 *
	 * @param mixed $times New times; null to only read.
	 * @return list<float>
	 */
	public static function times( mixed $times = null ): array {
		if ( null !== $times ) {
			$kept = [];

			foreach ( is_array( $times ) ? array_slice( $times, -self::TIMES ) : [] as $time ) {
				if ( ( is_int( $time ) || is_float( $time ) ) && is_finite( (float) $time ) && 0.0 !== (float) $time ) {
					$kept[] = max( -3600.0, min( 3600.0, (float) $time ) );
				}
			}

			self::$times = $kept;
		}

		return self::$times;
	}

	/**
	 * Seconds the next call is expected to take: the second-highest of three or more recent times (one outlier
	 * ignored), else the highest (a fast title call is no measure of a content part); null without any.
	 *
	 * @return float|null
	 */
	public static function expected(): ?float {
		$abs = array_map( 'abs', self::$times );
		rsort( $abs );

		return count( $abs ) > 2 ? $abs[1] : ( $abs[0] ?? null );
	}

	/**
	 * Whether the provider needs more than a whole window: of two or more calls the second-slowest answered after
	 * $window seconds or was cut after 80% of it; or a call cut after 80% of the window had at least the $left this
	 * slice has, while the slice is nearly whole (a short slice stops and the next one tries).
	 *
	 * @param float      $window Seconds a slice has.
	 * @param float|null $left   Seconds this slice has left.
	 * @return bool
	 */
	public static function too_slow( float $window, ?float $left = null ): bool {
		$times = self::$times;
		usort( $times, static fn( float $a, float $b ): int => abs( $b ) <=> abs( $a ) );

		$slowest = $times[0] ?? 0.0;

		if ( null !== $left && $slowest < 0 && -$slowest >= 0.8 * $window && -$slowest >= $left && $left >= 0.9 * $window ) {
			return true;
		}

		if ( count( $times ) < 2 ) {
			return false;
		}

		return $times[1] < 0 ? -$times[1] >= 0.8 * $window : $times[1] > $window;
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
	 * Seconds a web slice may run, the time its work must end by, and the window a slice that starts with its request has.
	 *
	 * @param float $job_seconds The job's slice budget (0 or less: none, the work still ends by the cap).
	 * @return array{0: float, 1: float, 2: float}
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
			return [ 0.0, $now + $max, $max ];
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
		return [ max( 0.001, min( $job_seconds, $end - $now ) ), $end, min( max( self::REQUEST_SECONDS - self::MARGIN_SECONDS, $job_seconds ), $max ) ];
	}
}
