<?php
/**
 * One-shot and recurring events without JobState tracking.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules events with no job row (webhook delivery and retry, temp-import cleanup, lock cleanup, jobs GC)
 * through Action Scheduler when it is the engine, else WP-Cron. They are not on the Jobs screen; an operation that
 * needs progress, status or retry there is an AbstractJob.
 */
final class BackgroundEvents {

	/**
	 * Enqueue a one-shot event at once or after `$delay_seconds`.
	 *
	 * @param string            $hook         Action hook name.
	 * @param array<int, mixed> $args         Hook args; JSON-serialisable (Action Scheduler requires it).
	 * @param int               $delay_seconds 0 = immediate (next cron
	 *                                        tick / AS claim cycle).
	 * @return bool True when the scheduler ACCEPTED the event.
	 */
	public static function enqueue( string $hook, array $args = [], int $delay_seconds = 0 ): bool {
		$delay_seconds = max( 0, $delay_seconds );

		// A refusal (filter veto, duplicate, store error) is logged so a dropped event is visible.
		if ( self::use_action_scheduler() ) {
			// 0 means not scheduled.
			$action_id = $delay_seconds === 0
				? as_enqueue_async_action( $hook, $args, ActionSchedulerRunner::GROUP )
				: as_schedule_single_action( time() + $delay_seconds, $hook, $args, ActionSchedulerRunner::GROUP );

			$accepted = is_numeric( $action_id ) && (int) $action_id > 0;
		} else {
			// False on refusal; a WP_Error when a `pre_schedule_event` filter vetoes.
			$scheduled = wp_schedule_single_event( time() + $delay_seconds, $hook, $args, true );
			$accepted  = ( true === $scheduled );
		}

		if ( ! $accepted ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale BackgroundEvents: scheduler refused ' . $hook . ' (delay ' . $delay_seconds . 's); the event was NOT queued.' );
		}

		return $accepted;
	}

	/**
	 * Cancel pending instances of (hook, args) in both engines (an engine switch can leave one in the other).
	 *
	 * @param string            $hook
	 * @param array<int, mixed> $args
	 * @return bool True if at least one instance was found and removed.
	 */
	public static function unschedule( string $hook, array $args = [] ): bool {
		$removed = false;

		if ( JobRunnerFactory::action_scheduler_available() ) {
			// as_unschedule_all_actions() returns null on success: check for a pending one first.
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
	 * Whether an instance of (hook, args) is pending in either engine, or running in Action Scheduler.
	 *
	 * Null args = any args. Not `next_run() !== null`: a running or async action has no timestamp, and every caller is
	 * an idempotence or health guard. Docs: docs-ai/11-code-rationale.md, "BackgroundEvents: Action Scheduler answer shapes".
	 *
	 * @param string                 $hook
	 * @param array<int, mixed>|null $args Exact args, or null for any.
	 * @return bool
	 */
	public static function is_scheduled( string $hook, ?array $args = null ): bool {
		$next_as = self::as_next_raw( $hook, $args );

		// true = running or pending async; int = pending scheduled.
		if ( true === $next_as || is_int( $next_as ) ) {
			return true;
		}

		$next_wp = null === $args
			? self::wp_next_scheduled_any_args( $hook )
			: wp_next_scheduled( $hook, $args );

		return is_int( $next_wp );
	}

	/**
	 * Whether Action Scheduler itself holds a pending or running action of the hook, whatever WP-Cron holds; asked
	 * before an event moves from WP-Cron to Action Scheduler. False without Action Scheduler.
	 *
	 * @param string                 $hook Hook name.
	 * @param array<int, mixed>|null $args Exact args, or null for any.
	 * @return bool
	 */
	public static function is_scheduled_in_action_scheduler( string $hook, ?array $args = null ): bool {
		$next_as = self::as_next_raw( $hook, $args );

		return true === $next_as || is_int( $next_as );
	}

	/**
	 * Action Scheduler's raw next-run answer: false, a pending timestamp, or true (running, or pending async).
	 * False without Action Scheduler.
	 *
	 * @param string                 $hook Hook name.
	 * @param array<int, mixed>|null $args Exact args, or null for any.
	 * @return int|bool Timestamp, true for an in-progress action or a pending
	 *                  async action, else false.
	 */
	private static function as_next_raw( string $hook, ?array $args ) {
		if ( ! JobRunnerFactory::action_scheduler_available() ) {
			return false;
		}

		// Null args are "any args" in Action Scheduler.
		return as_next_scheduled_action( $hook, $args, ActionSchedulerRunner::GROUP );
	}

	/**
	 * Enqueue a recurring event on the same engine as enqueue().
	 *
	 * The interval (Action Scheduler) and the WP-Cron schedule name must match; a custom schedule name must be
	 * registered on `cron_schedules` by the caller.
	 *
	 * @param string            $hook                Hook name.
	 * @param int               $first_run_timestamp UNIX timestamp for
	 *                                               the first fire.
	 * @param int               $interval_seconds    Repeat interval
	 *                                               in seconds (AS).
	 * @param string            $wp_cron_schedule    WP-Cron schedule
	 *                                               name (fallback).
	 *
	 * @param array<int, mixed> $args
	 * @return bool True when the scheduler admitted the event.
	 */
	public static function enqueue_recurring(
		string $hook,
		int $first_run_timestamp,
		int $interval_seconds,
		string $wp_cron_schedule,
		array $args = []
	): bool {
		$first_run        = max( time(), $first_run_timestamp );
		$interval_seconds = max( 1, $interval_seconds );

		if ( self::use_action_scheduler() ) {
			// 0 means not scheduled.
			$action_id = as_schedule_recurring_action( $first_run, $interval_seconds, $hook, $args, ActionSchedulerRunner::GROUP );
			$accepted  = is_numeric( $action_id ) && (int) $action_id > 0;
			$reason    = $accepted ? '' : 'Action Scheduler returned no action id';
		} else {
			$scheduled = wp_schedule_event( $first_run, $wp_cron_schedule, $hook, $args, true );
			$accepted  = ( true === $scheduled );

			if ( is_wp_error( $scheduled ) ) {
				$reason = 'WP-Cron refused: ' . $scheduled->get_error_message();
			} else {
				$reason = $accepted ? '' : 'WP-Cron refused (schedule "' . $wp_cron_schedule . '" may not be registered)';
			}
		}

		if ( ! $accepted ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale BackgroundEvents: scheduler refused recurring ' . $hook . ' - ' . $reason . '; the event was NOT queued.' );
		}

		return $accepted;
	}

	/**
	 * Cancel every pending occurrence of a recurring hook in both engines.
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
	 * The earlier next-run timestamp of the hook in either engine, or null.
	 *
	 * Null is not "nothing pending": a running or async Action Scheduler action has no timestamp. For "is anything
	 * pending" use is_scheduled(); to tell running from not scheduled, next_run_detail(). Null args = any args.
	 *
	 * @param string                 $hook
	 * @param array<int, mixed>|null $args Exact args, or null for any.
	 * @return int|null
	 */
	public static function next_run( string $hook, ?array $args = null ): ?int {
		return self::next_run_detail( $hook, $args )['next'];
	}

	/**
	 * The next_run() value plus `untimed`: Action Scheduler answered true (running, or pending async), from the same probe.
	 * `untimed` can be true while WP-Cron is forced (the probe runs whenever Action Scheduler is available).
	 *
	 * @internal Display helper for the Jobs screen's scheduled-tasks panel.
	 *           Despite being public-static, it is NOT part of the @api
	 *           surface; its shape may change between minor releases.
	 *
	 * @param string                 $hook Hook name.
	 * @param array<int, mixed>|null $args Exact args, or null for any.
	 * @return array{next: int|null, untimed: bool} `next` is exactly what
	 *                                              next_run() returns.
	 */
	public static function next_run_detail( string $hook, ?array $args = null ): array {
		$next_as = self::as_next_raw( $hook, $args );

		$next_wp = null === $args
			? self::wp_next_scheduled_any_args( $hook )
			: wp_next_scheduled( $hook, $args );

		$candidates = array_filter(
			[
				is_int( $next_as ) ? $next_as : null,
				is_int( $next_wp ) ? $next_wp : null,
			],
			static function ( $v ) {
				return null !== $v;
			}
		);

		return [
			'next'    => $candidates ? min( $candidates ) : null,
			'untimed' => true === $next_as,
		];
	}

	/**
	 * WP-Cron next run of a hook over every args set (wp_next_scheduled() finds the exact args only).
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
				// Timestamps are ascending keys: the first hit is the earliest.
				return (int) $timestamp;
			}
		}

		return false;
	}

	/**
	 * Unschedule every occurrence of a hook (any args) in both engines, at deactivation and uninstall.
	 *
	 * Action Scheduler gets the hook alone, no group: only that form ignores args.
	 * Docs: docs-ai/11-code-rationale.md, "BackgroundEvents: unschedule_all without a group".
	 *
	 * @param string $hook
	 * @return void
	 */
	public static function unschedule_all( string $hook ): void {
		if ( JobRunnerFactory::action_scheduler_available() ) {
			as_unschedule_all_actions( $hook );
		}

		wp_unschedule_hook( $hook );
	}

	/**
	 * Option of the last successful run per recurring hook: [ hook => [ 'ran_at' => int, 'duration_ms' => int ] ].
	 *
	 * @var string
	 */
	public const LAST_RUN_OPTION = 'perflocale_recurring_last_run';

	/**
	 * Record that a recurring handler finished (time and duration for the Jobs screen).
	 *
	 * @param string    $hook        Hook name that just finished.
	 * @param int|float $started_at  Start time in seconds or microtime( true ); 0 skips the duration.
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

		// Bound against stray keys from a buggy caller.
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
	 * Whether the engine resolves to Action Scheduler, without building a runner.
	 *
	 * @return bool
	 */
	/**
	 * Public so callers can choose a fan-out per engine (the webhook queue coalesces only on WP-Cron, where each
	 * event rewrites the autoloaded `cron` option).
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
			// Settings not available yet: Action Scheduler.
			unset( $e );
		}

		return true;
	}
}
