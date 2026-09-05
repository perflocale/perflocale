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
 * The ONE place that decides whether a machine-translation request is admitted.
 *
 * WHY THIS CLASS EXISTS
 *   This logic previously existed as two near-identical private copies, in
 *   MachineTranslateController and BlockTranslateController. A third entry
 *   point — the `perflocale/translate-post` Ability — was added later and
 *   simply had no copy, so it ran the provider with no admission check at all.
 *   With the per-user quota exhausted, the site-wide quota exhausted, or the
 *   rate lock held, the two REST routes returned 429 and made zero provider
 *   calls while the Ability succeeded and made real batched provider calls.
 *   Abilities are reachable over REST and MCP, so "the quota is enforced"
 *   was true of the paths people tested and false of the newest one.
 *
 *   Duplication was the mechanism, so the fix is de-duplication rather than a
 *   third copy. Every caller now shares one implementation, one pair of
 *   transient keys and one lock name, which is also what makes the per-user
 *   and site-wide budgets genuinely shared across entry points.
 *
 * IMPORTANT: admit() is not a query — it INCREMENTS both counters when it
 * allows. Call it exactly once per translation request, at the point the
 * request is about to proceed.
 */
final class MtRateLimiter {

	/**
	 * Transient key prefix for the per-user window.
	 *
	 * Unchanged from the original controllers on purpose: an upgrade must not
	 * hand every user a fresh empty quota window.
	 */
	private const USER_KEY_PREFIX = 'perflocale_mt_rl_';

	/** Transient key for the site-wide window. */
	private const SITE_KEY = 'perflocale_mt_rl_site';

	/**
	 * Lock name serialising the read-check-write.
	 *
	 * GLOBAL, not per-user: the site counter has to serialise too. Without
	 * that, concurrent requests from different users each read the
	 * pre-increment site count, each pass the check, and each write count+1.
	 */
	private const LOCK_NAME = 'mt_rl_global';

	/** Seconds the lock may be held. */
	private const LOCK_TTL = 5;

	/**
	 * Coerce a stored rate-limit window into a trustworthy shape.
	 *
	 * Returns the stored window only when it is an array whose `count` is a
	 * non-negative integer, whose `window_start` is a plausible integer
	 * timestamp, and whose window has not expired. Everything else — a missing
	 * key, a string, an array, a boolean, a float, a negative or future
	 * timestamp, an expired window — yields a fresh zeroed window.
	 *
	 * Integer-typed on the way out so callers can compare and increment without
	 * casting, which is what let a corrupt value slip past the cap before.
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

		// is_int, not is_numeric: "5" and 5.0 are values this class never writes,
		// so accepting them would be accepting corruption it can reason about
		// only by guessing.
		if ( ! is_int( $count ) || $count < 0 || ! is_int( $start ) ) {
			return $fresh;
		}

		// A window that starts in the future, or before the plugin could
		// plausibly have written it, is not a window this code produced.
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
			// Uncredentialed requests never reach here (the callers' own
			// permission gates run first), but defend in depth.
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
		 * Filter the site-wide hourly MT request ceiling. Applies across
		 * ALL users summed together — bounds the total provider-quota burn
		 * a hostile editor or compromised account can cause within an hour.
		 * Set to 0 to disable the site cap (per-user cap still applies).
		 *
		 * @hook perflocale/mt/rate_limit_site
		 * @param int $limit Max requests per hour, summed across all users. Default 5000.
		 */
		$site_limit = (int) apply_filters( 'perflocale/mt/rate_limit_site', 5000 );

		if ( $limit <= 0 && $site_limit <= 0 ) {
			return null; // Both caps disabled — no throttling.
		}

		$window   = HOUR_IN_SECONDS;
		$key      = self::USER_KEY_PREFIX . $user_id;
		$site_key = self::SITE_KEY;

		$result = Lock::with(
			self::LOCK_NAME,
			self::LOCK_TTL,
			static function () use ( $key, $site_key, $limit, $site_limit, $window ): \WP_Error|bool {
				$now = time();

				// VALIDATE THE SHAPE, NOT JUST ITS PRESENCE.
				//
				// The old guard accepted any array carrying the two keys, then
				// compared with `(int) $state['count']` but incremented the raw
				// value. With a corrupt transient that combination silently broke
				// the cap: `count = "malformed"` casts to 0, so the limit was
				// never reached, and `++` on that string produced "malformee",
				// "malformef" … — an unbounded bypass that never converged on a
				// number. `count = []` threw `TypeError: Cannot increment array`
				// and turned the request into a 500, and `count = true` increments
				// to itself, freezing the counter forever.
				//
				// Anything that is not a non-negative integer, and any window
				// start that is not a plausible timestamp, is therefore treated as
				// no window at all and reset. That is fail-CLOSED for the caller:
				// the request is counted from zero rather than waved through.
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

				// Both are integers by construction (see sane_window), so these
				// are ordinary numeric increments.
				++$state['count'];
				++$site_state['count'];

				// TTL a little past the window so the transient survives right
				// up to window roll-over; the inline reset above handles expiry
				// too.
				set_transient( $key, $state, $window + 60 );
				set_transient( $site_key, $site_state, $window + 60 );

				// Sentinel, NOT null: Lock::with() returns null for a lock
				// miss, so a null success here would be indistinguishable and
				// would turn every allowed request into a 429.
				return true;
			}
		);

		// Lock::with returns null when it could not acquire. Fail CLOSED: a
		// request that cannot take the lock cannot have its count incremented,
		// so treating the miss as "allow" lets concurrent callers stampede past
		// the cap. A 429 with a short Retry-After is what the client should
		// see; retrying after a beat almost always succeeds.
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
