<?php
/**
 * Thin helper for Tier-1 async events (no JobState tracking).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes "fire-and-forget" one-shot events through Action Scheduler when
 * it's available, falling back to WP-Cron otherwise. Used by Tier-1 ops
 * (AUTO_TRANSLATE_CRON, webhook delivery + retry, temp-import cleanup,
 * lock cleanup, jobs GC) that don't need the per-job JobState tracking
 * that Tier-2 jobs get.
 *
 * Difference from {@see JobRunnerInterface}:
 *
 *   - Runner interface enqueues with a `$job_id` and stores per-job state
 *     in {@see JobState}. Required for the Jobs admin UI.
 *
 *   - BackgroundEvents enqueues without any `$job_id` or per-job state.
 *     Cheaper — no option writes for state tracking — and matches how the
 *     pre-refactor code paths worked. The cost: these events don't appear
 *     in *PerfLocale → Jobs*. They appear in *Tools → Scheduled Actions*
 *     when AS is the engine (under the `perflocale` group) and in
 *     `wp_options['cron']` when WP-Cron is.
 *
 * If you find yourself needing per-job progress / status / retry visibility
 * for one of these ops, promote it to a Tier-2 {@see AbstractJob} instead.
 */
final class BackgroundEvents {

	/**
	 * Enqueue a one-shot event ASAP (or after `$delay_seconds`).
	 *
	 * @param string            $hook         Action hook name.
	 * @param array<int, mixed> $args         Args passed to the hook's
	 *                                        callbacks. MUST be JSON-
	 *                                        serialisable (AS requires).
	 * @param int               $delay_seconds 0 = immediate (next cron
	 *                                        tick / AS claim cycle).
	 * @return void
	 */
	public static function enqueue( string $hook, array $args = [], int $delay_seconds = 0 ): void {
		$delay_seconds = max( 0, $delay_seconds );

		if ( self::use_action_scheduler() ) {
			if ( $delay_seconds === 0 ) {
				as_enqueue_async_action( $hook, $args, ActionSchedulerRunner::GROUP );
			} else {
				as_schedule_single_action( time() + $delay_seconds, $hook, $args, ActionSchedulerRunner::GROUP );
			}
			return;
		}

		wp_schedule_single_event( time() + $delay_seconds, $hook, $args );
	}

	/**
	 * Cancel any pending instances matching (hook, args).
	 *
	 * Defensively un-schedules in BOTH back-ends — if the engine setting
	 * was switched mid-life, an event enqueued under the previous engine
	 * could still be pending. Idempotent + cheap.
	 *
	 * @param string            $hook
	 * @param array<int, mixed> $args
	 * @return bool True if at least one instance was found and removed.
	 */
	public static function unschedule( string $hook, array $args = [] ): bool {
		$removed = false;

		if ( JobRunnerFactory::action_scheduler_available() ) {
			// `as_unschedule_all_actions` returns NULL on success in current
			// AS versions — we can't trust the return value as a "did anything
			// match" signal. Detect existence before the call instead.
			$had_pending = false !== as_next_scheduled_action( $hook, $args, ActionSchedulerRunner::GROUP );
			as_unschedule_all_actions( $hook, $args, ActionSchedulerRunner::GROUP );

			if ( $had_pending ) {
				$removed = true;
			}
		}

		$next = wp_next_scheduled( $hook, $args );

		if ( $next !== false ) {
			wp_unschedule_event( (int) $next, $hook, $args );
			$removed = true;
		}

		return $removed;
	}

	/**
	 * Whether at least one pending instance matches (hook, args).
	 *
	 * Checks both engines so callers don't have to know which one stored
	 * the event.
	 *
	 * `$args = null` (the default) means ANY args: the recurring
	 * maintenance events carry a blog-id argument on multisite-aware
	 * schedules, so an exact empty-args probe reports "not scheduled" on
	 * every Action Scheduler site even while the event is pending. Pass an
	 * explicit array only when the exact-args instance matters.
	 *
	 * @param string                 $hook
	 * @param array<int, mixed>|null $args Exact args, or null for any.
	 * @return bool
	 */
	public static function is_scheduled( string $hook, ?array $args = null ): bool {
		return null !== self::next_run( $hook, $args );
	}

	/**
	 * Enqueue a recurring event with the same engine-preference rules as
	 * `enqueue()`: Action Scheduler when loaded and the operator hasn't
	 * forced WP-Cron, else native WP-Cron.
	 *
	 * Callers must pass BOTH an interval in seconds (used by Action
	 * Scheduler) AND a WP-Cron schedule name (used by the fallback). The
	 * two have to match — there is no shared primitive, since
	 * `wp_schedule_event()` takes a registered schedule name while
	 * `as_schedule_recurring_action()` takes raw seconds. Stock schedules
	 * (`'hourly'`, `'twicedaily'`, `'daily'`, `'weekly'`) are always safe;
	 * any custom schedule must be registered via the `cron_schedules`
	 * filter by the caller.
	 *
	 * @param string            $hook                Hook name.
	 * @param int               $first_run_timestamp UNIX timestamp for
	 *                                               the first fire.
	 * @param int               $interval_seconds    Repeat interval
	 *                                               in seconds (AS).
	 * @param string            $wp_cron_schedule    WP-Cron schedule
	 *                                               name (fallback).
	 * @param array<int, mixed> $args
	 * @return void
	 */
	public static function enqueue_recurring(
		string $hook,
		int $first_run_timestamp,
		int $interval_seconds,
		string $wp_cron_schedule,
		array $args = []
	): void {
		$first_run        = max( time(), $first_run_timestamp );
		$interval_seconds = max( 1, $interval_seconds );

		if ( self::use_action_scheduler() ) {
			as_schedule_recurring_action( $first_run, $interval_seconds, $hook, $args, ActionSchedulerRunner::GROUP );
			return;
		}

		wp_schedule_event( $first_run, $wp_cron_schedule, $hook, $args );
	}

	/**
	 * Cancel ALL pending occurrences of a recurring hook in both back-ends.
	 *
	 * Mirrors `unschedule()` but uses `wp_clear_scheduled_hook` (clears
	 * every instance, including recurring) instead of
	 * `wp_unschedule_event` (which only removes a single occurrence by
	 * timestamp). Idempotent.
	 *
	 * @param string            $hook
	 * @param array<int, mixed> $args
	 * @return void
	 */
	public static function unschedule_recurring( string $hook, array $args = [] ): void {
		if ( JobRunnerFactory::action_scheduler_available() ) {
			as_unschedule_all_actions( $hook, $args, ActionSchedulerRunner::GROUP );
		}

		wp_clear_scheduled_hook( $hook, $args );
	}

	/**
	 * Find the next-run UNIX timestamp for a hook in either back-end.
	 *
	 * Returns the earlier of the AS and WP-Cron next-runs (in case both
	 * happen to be scheduled — e.g. after an engine-setting flip), or
	 * null when nothing is pending.
	 *
	 * `$args = null` (the default) matches ANY args — see is_scheduled().
	 *
	 * @param string                 $hook
	 * @param array<int, mixed>|null $args Exact args, or null for any.
	 * @return int|null
	 */
	public static function next_run( string $hook, ?array $args = null ): ?int {
		$next_as = false;

		if ( JobRunnerFactory::action_scheduler_available() ) {
			// Action Scheduler natively treats null args as "any args".
			$next_as = as_next_scheduled_action( $hook, $args, ActionSchedulerRunner::GROUP );
		}

		$next_wp = null === $args
			? self::wp_next_scheduled_any_args( $hook )
			: wp_next_scheduled( $hook, $args );

		$candidates = array_filter(
			[
				is_int( $next_as ) ? $next_as : null,
				is_int( $next_wp ) ? $next_wp : null,
			],
			static fn( $v ) => $v !== null
		);

			return $candidates ? min( $candidates ) : null;
	}

	/**
	 * WP-Cron next-run for a hook across ALL argument sets.
	 *
	 * `wp_next_scheduled()` keys on md5(serialize($args)), so it can only
	 * find the exact-args instance; there is no core any-args lookup short
	 * of walking the cron array.
	 *
	 * @param string $hook Hook name.
	 * @return int|false Earliest timestamp, or false when none pending.
	 */
	private static function wp_next_scheduled_any_args( string $hook ) {
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : [];

		if ( ! is_array( $crons ) ) {
			return false;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ] ) ) {
				// Timestamps are the array's ascending keys, so the first
				// hit is the earliest occurrence.
				return (int) $timestamp;
			}
		}

		return false;
	}

	/**
	 * Unschedule EVERY occurrence of a hook (any args, any timestamp) in
	 * BOTH back-ends. Used at deactivation/uninstall to make sure pending
	 * events don't keep firing with no callback once the plugin is off.
	 *
	 * The WP-Cron side uses `wp_unschedule_hook()` (matches across all
	 * args, unlike `wp_clear_scheduled_hook` which needs args to match).
	 * The AS side uses `as_unschedule_all_actions($hook, [], $group)`
	 * which Action Scheduler interprets as "all pending actions for this
	 * hook in this group, regardless of args".
	 *
	 * @param string $hook
	 * @return void
	 */
	public static function unschedule_all( string $hook ): void {
		if ( JobRunnerFactory::action_scheduler_available() ) {
			as_unschedule_all_actions( $hook, [], ActionSchedulerRunner::GROUP );
		}

		wp_unschedule_hook( $hook );
	}

	/**
	 * Option that stores the most-recent successful run for every
	 * recurring hook the plugin owns. Format:
	 *   [ hook_name => [ 'ran_at' => int, 'duration_ms' => int ], ... ]
	 *
	 * Bounded: only ~5 keys ever (one per recurring hook), so the
	 * option stays small enough to autoload cheaply.
	 *
	 * @var string
	 */
	public const LAST_RUN_OPTION = 'perflocale_recurring_last_run';

	/**
	 * Record that a recurring handler just finished running.
	 *
	 * Called by `Lock::reap_expired`, `JobState::gc`, and similar
	 * recurring handlers right before they return. Stores both the
	 * completion timestamp and the wall-clock duration so the Jobs
	 * admin page can show "ran 2 hours ago — took 14 ms" without
	 * needing a separate logging layer.
	 *
	 * @param string    $hook        Hook name that just finished.
	 * @param int|float $started_at  Unix start time. Accepts both integer
	 *                               seconds or float `microtime(true)`
	 *                               for sub-second precision in the Jobs
	 *                               panel "took N ms" annotation. 0 to
	 *                               skip duration tracking.
	 * @return void
	 */
	public static function record_run( string $hook, $started_at = 0 ): void {
		$now = microtime( true );
		$log = (array) get_option( self::LAST_RUN_OPTION, [] );

		$started_float = (float) $started_at;
		$duration_ms   = $started_float > 0
			? max( 0, (int) round( ( $now - $started_float ) * 1000 ) )
			: 0;

		$log[ $hook ] = [
			'ran_at'      => (int) $now,
			'duration_ms' => $duration_ms,
		];

		// Defensive bound: we only set ~5 known hook keys, but a buggy
		// caller could accumulate strays. Cap at 50.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50, null, true );
		}

		update_option( self::LAST_RUN_OPTION, $log, false );
	}

	/**
	 * Look up the most-recent run record for a hook.
	 *
	 * @param string $hook
	 * @return array{ran_at: int, duration_ms: int}|null
	 */
	public static function last_run( string $hook ): ?array {
		$log = (array) get_option( self::LAST_RUN_OPTION, [] );

		if ( ! isset( $log[ $hook ] ) || ! is_array( $log[ $hook ] ) ) {
			return null;
		}

		return [
			'ran_at'      => (int) ( $log[ $hook ]['ran_at'] ?? 0 ),
			'duration_ms' => (int) ( $log[ $hook ]['duration_ms'] ?? 0 ),
		];
	}

	/**
	 * Whether the current engine choice resolves to Action Scheduler.
	 *
	 * Mirrors {@see JobRunnerFactory::pick()} without instantiating —
	 * cheaper for the common-path `enqueue()` call.
	 *
	 * @return bool
	 */
	/**
	 * Whether the active engine is Action Scheduler (vs. plain WP-Cron).
	 *
	 * Public so callers can pick a different fan-out strategy per engine —
	 * e.g. the webhook queue only coalesces on WP-Cron, where every
	 * wp_schedule_single_event rewrites the single autoloaded `cron`
	 * option; AS enqueues each async action into its own table row.
	 *
	 * @return bool
	 */
	public static function is_action_scheduler_engine(): bool {
		return self::use_action_scheduler();
	}

	private static function use_action_scheduler(): bool {
		if ( ! JobRunnerFactory::action_scheduler_available() ) {
			return false;
		}

		// Respect the `force_wp_cron` setting.
		try {
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( $plugin->has( 'settings' ) ) {
				$settings = $plugin->get( 'settings' );

				if ( $settings instanceof \PerfLocale\Settings
					&& (string) $settings->get( 'background_engine', 'auto' ) === 'force_wp_cron' ) {
					return false;
				}
			}
		} catch ( \Throwable $e ) {
			// Very early bootstrap — default to AS-available.
			unset( $e );
		}

		return true;
	}
}
