<?php
/**
 * Re-enqueue jobs that survived a deactivate and reactivate.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler of `perflocale_resume_jobs` (scheduled by Activator::activate(), run once the container is ready):
 * deactivation unschedules worker events but keeps job rows, so queued and running rows get their event again.
 * A running row with a held lock waits for a follow-up sweep after the lock expires (schedule_followup()).
 */
final class Resumer {

	/**
	 * Hook of the resume sweep.
	 */
	public const HOOK = 'perflocale_resume_jobs';

	/**
	 * Seconds after a lock's expiry before the follow-up sweep runs (also its shortest delay).
	 */
	private const FOLLOWUP_MARGIN = 60;

	/**
	 * Key of the held-lock skip line in a job's log (note_held_skip()).
	 */
	private const HELD_SKIP_KEY = 'resume_held_skip';

	/**
	 * Re-enqueue every queued or running job with no worker event waiting; idempotent.
	 *
	 * @return int Number of jobs re-enqueued.
	 */
	public static function resume(): int {
		// One sweep at a time: two sweeps in one second can both probe "not scheduled" before either schedules. A lost
		// lock means another sweep runs: 0.
		$result = \PerfLocale\Concurrency\Lock::with(
			'jobs_resume',
			60,
			static function (): int {
				return self::do_resume();
			}
		);

		return $result ?? 0;
	}

	/**
	 * The sweep, under the `jobs_resume` lock.
	 *
	 * @return int Number of jobs re-enqueued.
	 */
	private static function do_resume(): int {
		// Not list_active(): it caps at the newest 50 rows shown on the Jobs screen.
		$idx     = JobState::list_resumable();
		$resumed = 0;

		// Earliest lock expiry of the skipped rows that qualify for a follow-up; 0 = none.
		$followup_at = 0;

		if ( empty( $idx ) ) {
			return 0;
		}

		$runner = JobRunnerFactory::pick();

		foreach ( $idx as $job_id => $row ) {
			$status = (string) ( $row['status'] ?? 'unknown' );

			if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
				continue;
			}

			// A row gone since the listing is skipped; the GC tidies the rest.
			$state = JobState::get( (string) $job_id );

			if ( ! $state ) {
				continue;
			}

			$hook = (string) ( $state['hook'] ?? '' );
			$args = (array) ( $state['args'] ?? [] );

			if ( $hook === '' ) {
				continue;
			}

			// Skip a job whose worker event exists: probe both engines (the setting may have changed while deactivated) and
			// both args shapes (with and without the blog-id sentinel), or the job is duplicated.
			$stored_engine = (string) ( $state['engine'] ?? 'wp_cron' );
			$blog_id       = (int) ( $state['blog_id'] ?? 0 );

			$probe_shapes = [ $args ];
			if ( $blog_id > 0 ) {
				$with_sentinel                         = $args;
				$with_sentinel['__perflocale_blog_id'] = $blog_id;
				$probe_shapes[]                        = $with_sentinel;
			}

			// Type-busy retry shapes (`__perflocale_type_busy_count`), or the job is scheduled twice.
			for ( $n = 1; $n <= 10; $n++ ) {
				$with_busy                                 = $args;
				$with_busy['__perflocale_type_busy_count'] = $n;
				$probe_shapes[]                            = $with_busy;

				if ( $blog_id > 0 ) {
					$with_busy_blog                         = $with_busy;
					$with_busy_blog['__perflocale_blog_id'] = $blog_id;
					$probe_shapes[]                         = $with_busy_blog;
				}
			}

			// Lock-busy retry shapes, up to the `lock_busy_max_retries` cap (20).
			for ( $n = 1; $n <= 20; $n++ ) {
				$with_lock_busy                                 = $args;
				$with_lock_busy['__perflocale_lock_busy_count'] = $n;
				$probe_shapes[]                                 = $with_lock_busy;

				if ( $blog_id > 0 ) {
					$with_lock_busy_blog                         = $with_lock_busy;
					$with_lock_busy_blog['__perflocale_blog_id'] = $blog_id;
					$probe_shapes[]                              = $with_lock_busy_blog;
				}
			}

			$already_scheduled = false;
			foreach ( $probe_shapes as $probe_args ) {
				if ( self::is_already_scheduled( 'wp_cron', $hook, (string) $job_id, $probe_args )
					|| self::is_already_scheduled( 'action_scheduler', $hook, (string) $job_id, $probe_args )
				) {
					$already_scheduled = true;
					break;
				}
			}

			if ( $already_scheduled ) {
				continue;
			}

			// A held lock may be a live worker's (it refreshes it every TTL/4): resuming would run a second worker. Leave the
			// row; the follow-up after the lock expires resumes it only if the lock lapsed.
			if ( $status === 'running' && JobLock::is_held( (string) $job_id ) ) {
				$expiry = self::note_held_skip( (string) $job_id, $state );

				if ( $expiry > 0 && ( 0 === $followup_at || $expiry < $followup_at ) ) {
					$followup_at = $expiry;
				}
				continue;
			}

			// Back to queued without counting an attempt: the run was stopped by the deactivation, not by a failure.
			if ( $status === 'running' ) {
				JobState::reset_for_retry( (string) $job_id );
			}

			// The blog-id sentinel, as Dispatcher::enqueue() adds it.
			$worker_args = WorkerRegistry::with_blog_sentinel( $args, $blog_id );

			// time()+1, so the cron run that fires this event can pick the worker event up without re-entering this handler.
			$runner->schedule( time() + 1, $hook, $worker_args, (string) $job_id );

			// Store the engine used, so later cancel and is_scheduled probes target its store.
			$current_engine = $runner->get_engine_name();
			if ( $current_engine !== $stored_engine ) {
				JobState::set_engine( (string) $job_id, $current_engine );
			}

			JobState::append_log(
				(string) $job_id,
				__( 'Resumed after plugin reactivation.', 'perflocale' )
			);

			++$resumed;
		}

		if ( $followup_at > 0 ) {
			self::schedule_followup( $followup_at );
		}

		return $resumed;
	}

	/**
	 * Log once that a running row was skipped for its held lock, and say whether it still qualifies for a follow-up.
	 *
	 * Logged once because each log write bumps `updated_at`, and a row whose lock never lapses must still age into the
	 * watchdog's stuck window; a row older than that window stops qualifying, so the follow-up chain ends.
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $state  The row as read for this sweep.
	 * @return int The lock's expiry when the row qualifies, else 0.
	 */
	private static function note_held_skip( string $job_id, array $state ): int {
		$message = __( 'Resume skipped: worker lock still held. The job is checked again after the lock expires; if it is still stuck then, run "wp perflocale jobs resume" or cancel and retry it.', 'perflocale' );
		$log     = (array) ( $state['log'] ?? [] );
		$last    = end( $log );
		$logged  = false;

		// Matched by key, not text: the line is translated.
		if ( ! is_array( $last ) || self::HELD_SKIP_KEY !== ( $last['k'] ?? null ) ) {
			JobState::append_log( $job_id, $message, self::HELD_SKIP_KEY );
			$logged = true;
		}

		$updated = $state['updated_at'] ?? 0;

		if ( ! $logged && time() - ( is_int( $updated ) ? $updated : 0 ) >= self::stuck_after() ) {
			return 0;
		}

		// A lock released since is_held() reads 0: the follow-up only confirms the row is done.
		return max( time(), JobLock::expires_at( $job_id ) );
	}

	/**
	 * Schedule one follow-up sweep shortly after `$expiry`, unless a sweep is pending in either store.
	 *
	 * The delay is at least FOLLOWUP_MARGIN and at most the stuck window, so a corrupt expiry ends with the watchdog;
	 * the event shape is Activator::activate()'s, so deactivation, engine changes and uninstall clear it.
	 *
	 * @param int $expiry Earliest expiry among the skipped rows' locks.
	 * @return void
	 */
	private static function schedule_followup( int $expiry ): void {
		if ( self::followup_pending() ) {
			return;
		}

		$delay = max( self::FOLLOWUP_MARGIN, $expiry + self::FOLLOWUP_MARGIN - time() );

		BackgroundEvents::enqueue( self::HOOK, [], min( max( self::FOLLOWUP_MARGIN, self::stuck_after() ), $delay ) );
	}

	/**
	 * Seconds without an `updated_at` bump after which the watchdog and GC treat a queued or running row as stuck.
	 *
	 * @return int
	 */
	private static function stuck_after(): int {
		/** This filter is applied in JobState::gc() and JobState::watchdog(). */
		$seconds = apply_filters( 'perflocale/jobs/stuck_timeout_seconds', JobState::STUCK_TIMEOUT );

		return is_numeric( $seconds ) ? (int) $seconds : JobState::STUCK_TIMEOUT;
	}

	/**
	 * Whether a resume sweep is pending in either store.
	 *
	 * Not BackgroundEvents::is_scheduled(): it counts a running Action Scheduler action, which is this sweep.
	 *
	 * @return bool
	 */
	private static function followup_pending(): bool {
		if ( JobRunnerFactory::action_scheduler_available() && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				[
					'hook'     => self::HOOK,
					'status'   => 'pending',
					'group'    => ActionSchedulerRunner::GROUP,
					'per_page' => 1,
				],
				'ids'
			);

			if ( ! empty( $pending ) ) {
				return true;
			}
		}

		return false !== wp_next_scheduled( self::HOOK );
	}

	/**
	 * Whether a worker event for (engine, hook, job_id, args) is already scheduled, for example by a dispatch made
	 * before the sweep ran.
	 *
	 * @param string               $engine 'wp_cron' or 'action_scheduler'.
	 * @param string               $hook
	 * @param string               $job_id
	 * @param array<string, mixed> $args
	 * @return bool
	 */
	private static function is_already_scheduled( string $engine, string $hook, string $job_id, array $args ): bool {
		if ( $engine === 'action_scheduler' && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( $hook, [ $job_id, $args ], ActionSchedulerRunner::GROUP );
			return $next !== false && $next !== null;
		}

		return (bool) wp_next_scheduled( $hook, [ $job_id, $args ] );
	}
}
