<?php
/**
 * One-shot recovery: re-enqueue jobs that survived a deactivate/reactivate.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges the deactivate-reactivate gap.
 *
 * {@see \PerfLocale\Deactivator} unschedules every `perflocale_job_run_<type>`
 * hook so a deactivated plugin doesn't leave cron events firing against
 * missing callbacks. The JobState rows in the plugin's `jobs` table,
 * though, intentionally survive — the operator's job history shouldn't
 * vanish on deactivate.
 *
 * The consequence: after reactivate, any rows that were `queued` or
 * `running` at deactivation time have no cron / Action Scheduler event
 * waiting to fire them. They'd sit as stuck-queued forever (until the
 * GC's stuck-job sweep marks them failed 6h later).
 *
 * {@see \PerfLocale\Activator::activate()} schedules a
 * `perflocale_resume_jobs` cron event for `time()`; this class is the
 * handler. The split between scheduling-in-activator and handling-here-
 * via-Bootstrap matters: by the time the cron event actually fires, the
 * Plugin DI container is fully built and `JobRunnerFactory::pick()` can
 * resolve the engine setting correctly. Doing the resume synchronously
 * inside `Activator::activate()` would risk the container not being
 * ready yet on some early-activation flows.
 */
final class Resumer {

	/**
	 * Cron hook fired by {@see \PerfLocale\Activator::activate()} to
	 * trigger the resume sweep.
	 */
	public const HOOK = 'perflocale_resume_jobs';

	/**
	 * Scan the active index and re-enqueue every `queued` or `running`
	 * job that has no scheduled worker event waiting for it.
	 *
	 * Idempotent: re-runs no-op when the index has no resumable rows.
	 * Safe to call mid-flight; rows that already have a pending event
	 * skip the re-schedule.
	 *
	 * @return int Number of jobs re-enqueued.
	 */
	public static function resume(): int {
		// Serialise the resume sweep so two back-to-back activations don't
		// both walk the index and double-schedule jobs. The per-row
		// is_already_scheduled() dedupe below catches most duplicates, but two
		// callers within the same second can both probe "not scheduled" before
		// either commits its schedule(). A lost lock acquire just means another
		// resumer is in flight, so we return 0 (its run yields the real count).
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
	 * Resume-sweep body. Always called under the `jobs_resume` lock by
	 * {@see resume()} so two activations / explicit triggers can't race
	 * past the per-job `is_already_scheduled()` dedupe.
	 *
	 * @return int Number of jobs re-enqueued.
	 */
	private static function do_resume(): int {
		// list_resumable() (not list_active()): the latter caps at the
		// admin-display window (newest 50), so an old queued/running job
		// beyond the cap would never be resumed after a deactivate/reactivate.
		$idx     = JobState::list_resumable();
		$resumed = 0;

		if ( empty( $idx ) ) {
			return 0;
		}

		$runner = JobRunnerFactory::pick();

		foreach ( $idx as $job_id => $row ) {
			$status = (string) ( $row['status'] ?? 'unknown' );

			if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
				continue;
			}

			// Re-load the full per-job row to get hook + args. Skip if the
			// row vanished between the listing and now — the normal GC's
			// stuck-job sweep tidies anything left behind on the next tick.
			$state = JobState::get( (string) $job_id );

			if ( ! $state ) {
				continue;
			}

			$hook = (string) ( $state['hook'] ?? '' );
			$args = (array) ( $state['args'] ?? [] );

			if ( $hook === '' ) {
				continue;
			}

			// Skip if a worker event already exists (e.g. a fresh admin
			// dispatch ran before the Resumer). Probe BOTH engines — the
			// operator may have switched background_engine while deactivated —
			// and BOTH arg shapes (base + the multisite blog-id sentinel that
			// Dispatcher::enqueue injects), or a probe with stripped args would
			// miss the scheduled event and duplicate the job.
			$stored_engine = (string) ( $state['engine'] ?? 'wp_cron' );
			$blog_id       = (int) ( $state['blog_id'] ?? 0 );

			$probe_shapes = [ $args ];
			if ( $blog_id > 0 ) {
				$with_sentinel                         = $args;
				$with_sentinel['__perflocale_blog_id'] = $blog_id;
				$probe_shapes[]                        = $with_sentinel;
			}

			// Also probe type-busy retry shapes. A job that was mid-
			// type-busy-retry when the plugin deactivated has its
			// scheduled args carrying `__perflocale_type_busy_count=N`.
			// Without this enumeration the Resumer would miss those
			// scheduled events and schedule a duplicate; both would fire
			// and contest the per-job lock.
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

			// Same shape for the per-job-lock-busy retry chain (introduced
			// when WorkerRegistry stopped silently returning on
			// JobLock::acquire failure). Enumerated up to the
			// `lock_busy_max_retries` cap (20) so any in-flight retry chain
			// is detected and not duplicated by resume().
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

			// A 'running' row whose JobLock is still HELD belongs to a live
			// worker (it refreshes the lock every TTL/4 via its progress
			// callback) — resetting + rescheduling here would run a SECOND
			// worker over the same job concurrently. Leave it; it will finish
			// and mark_complete normally. A genuinely crashed worker's lock
			// expires (bounded by get_lock_ttl), after which a later sweep
			// resumes it cleanly.
			if ( $status === 'running' && \PerfLocale\Background\JobLock::is_held( (string) $job_id ) ) {
				JobState::append_log( (string) $job_id, __( 'Resume skipped: worker lock still held.', 'perflocale' ) );
				continue;
			}

			// Flip running → queued before scheduling so the Jobs page
			// reflects reality (no worker is actually mid-run). Does NOT
			// bump attempts — the previous run didn't fail on its own
			// merits, it was killed by the operator deactivating.
			if ( $status === 'running' ) {
				JobState::reset_for_retry( (string) $job_id );
			}

			// Inject the blog-id sentinel so the worker can switch_to_blog
			// before reading state — same as a fresh Dispatcher::enqueue.
			// `$blog_id` was already captured above for the dedupe probe.
			$worker_args = WorkerRegistry::with_blog_sentinel( $args, $blog_id );

			// Schedule at time()+1 so the cron tick that fires THIS event
			// can also pick up the newly-scheduled worker event on its
			// next sweep, without a re-entrance through the same cron
			// handler.
			$runner->schedule( time() + 1, $hook, $worker_args, (string) $job_id );

			// Write the engine actually used to re-schedule so subsequent
			// cancel/is_scheduled probes target the correct store. If the
			// operator flipped engines while deactivated the stored value
			// would point at the wrong runner and silently misfire.
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

		return $resumed;
	}

	/**
	 * Whether a worker event for the given (engine, hook, job_id, args)
	 * is already scheduled. Used to dedupe resume against a fresh user-
	 * driven dispatch that happened to land between deactivate and the
	 * Resumer running.
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
