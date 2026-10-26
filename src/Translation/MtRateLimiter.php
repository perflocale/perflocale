<?php
/**
 * Machine-translation rate admission.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Concurrency\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that admits machine-translation requests, so every entry point shares the per-user and site budgets.
 *
 * The admit() call is not a query: it increments both counters when it allows, so call it once per request,
 * right before the request proceeds.
 */
final class MtRateLimiter {

	/**
	 * Transient key prefix for the per-user window; renaming it would hand every user a fresh window.
	 */
	private const USER_KEY_PREFIX = 'perflocale_mt_rl_';

	/** Transient key for the site-wide window. */
	private const SITE_KEY = 'perflocale_mt_rl_site';

	/**
	 * Lock name serialising the read-check-write; global, not per-user, because the site counter needs it too.
	 */
	private const LOCK_NAME = 'mt_rl_global';

	/** Seconds the lock may be held. */
	private const LOCK_TTL = 5;

	/**
	 * The stored window when well formed and unexpired (int count >= 0, plausible int start), else a fresh one.
	 *
	 * @param mixed $stored Raw transient value.
	 * @param int   $now    Current timestamp.
	 * @param int   $window Window length in seconds.
	 * @return array{count: int, window_start: int}
	 */
	private static function sane_window( $stored, int $now, int $window ): array {
		$fresh = [
			'count'        => 0,
			'window_start' => $now,
		];

		if ( ! is_array( $stored ) || ! isset( $stored['count'], $stored['window_start'] ) ) {
			return $fresh;
		}

		$count = $stored['count'];
		$start = $stored['window_start'];

		// is_int, not is_numeric: this class never writes "5" or 5.0, so those are corruption.
		if ( ! is_int( $count ) || $count < 0 || ! is_int( $start ) ) {
			return $fresh;
		}

		// A start in the future or not above 0 is not one this code wrote.
		if ( $start > $now || $start <= 0 ) {
			return $fresh;
		}

		if ( $now - $start >= $window ) {
			return $fresh;
		}

		return [
			'count'        => $count,
			'window_start' => $start,
		];
	}

	/**
	 * Decide whether one MT request may proceed, and count it if so.
	 *
	 * @param int $user_id Acting user.
	 * @return \WP_Error|null WP_Error when refused, null when admitted.
	 */
	public static function admit( int $user_id ): ?\WP_Error {
		if ( $user_id <= 0 ) {
			// Callers' permission gates run first; no user means nothing to count.
			return null;
		}

		/**
		 * Filter the per-user hourly MT request ceiling.
		 *
		 * @hook perflocale/mt/rate_limit
		 * @param int $limit Max requests per hour per user. Default 500.
		 */
		$limit = (int) apply_filters( 'perflocale/mt/rate_limit', 500 );

		/**
		 * Filter the site-wide hourly MT request ceiling, summed over all users; 0 disables it.
		 *
		 * @hook perflocale/mt/rate_limit_site
		 * @param int $limit Max requests per hour, summed across all users. Default 5000.
		 */
		$site_limit = (int) apply_filters( 'perflocale/mt/rate_limit_site', 5000 );

		if ( $limit <= 0 && $site_limit <= 0 ) {
			return null; // Both caps disabled - no throttling.
		}

		$window   = HOUR_IN_SECONDS;
		$key      = self::USER_KEY_PREFIX . $user_id;
		$site_key = self::SITE_KEY;

		$result = Lock::with(
			self::LOCK_NAME,
			self::LOCK_TTL,
			static function () use ( $key, $site_key, $limit, $site_limit, $window ): \WP_Error|bool {
				$now = time();

				// A corrupt window is reset and counted from zero, never compared or incremented as it is.
				$state      = self::sane_window( get_transient( $key ), $now, $window );
				$site_state = self::sane_window( get_transient( $site_key ), $now, $window );

				if ( $limit > 0 && (int) $state['count'] >= $limit ) {
					$retry_after = max( 1, $window - ( $now - (int) $state['window_start'] ) );

					return new \WP_Error(
						'rate_limited',
						sprintf(
							/* translators: 1: request limit, 2: retry-after seconds */
							__( 'Machine translation rate limit reached (%1$d/hour). Try again in %2$d seconds.', 'perflocale' ),
							$limit,
							$retry_after
						),
						[ 'status' => 429 ]
					);
				}

				if ( $site_limit > 0 && (int) $site_state['count'] >= $site_limit ) {
					$retry_after = max( 1, $window - ( $now - (int) $site_state['window_start'] ) );

					return new \WP_Error(
						'rate_limited_site',
						sprintf(
							/* translators: 1: site-wide request limit, 2: retry-after seconds */
							__( 'Site-wide machine translation rate limit reached (%1$d/hour total). Try again in %2$d seconds.', 'perflocale' ),
							$site_limit,
							$retry_after
						),
						[ 'status' => 429 ]
					);
				}

				++$state['count'];
				++$site_state['count'];

				// TTL past the window so the transient lasts until roll-over; sane_window() handles expiry.
				set_transient( $key, $state, $window + 60 );
				set_transient( $site_key, $site_state, $window + 60 );

				// Not null: Lock::with() returns null for a lock miss, so null would turn every success into a 429.
				return true;
			}
		);

		// Lock busy: fail closed, since an uncounted "allow" lets concurrent callers stampede past the cap.
		if ( null === $result ) {
			return new \WP_Error(
				'rate_limit_lock_busy',
				esc_html__( 'Machine translation rate-limit check is busy. Retry shortly.', 'perflocale' ),
				[
					'status'      => 429,
					'retry_after' => 2,
				]
			);
		}

		return $result instanceof \WP_Error ? $result : null;
	}
}
