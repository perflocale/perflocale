<?php
/**
 * Worker hook registry: the execution of a job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the jobs {@see WorkerRegistry} registered: the pipeline its worker
 * hook callback {@see WorkerRegistry::run()} forwards to (lock, capability
 * check, retry and state transitions), and the shutdown guard of a running
 * job. Loaded on the first job run of a process.
 */
final class WorkerRunner {

	/**
	 * The error types WordPress's fatal error handler treats as fatal.
	 *
	 * @var array<int, int>
	 */
	private const FATAL_ERROR_TYPES = [ E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ];

	/**
	 * The job whose execute() runs in this process: its blog, id and type,
	 * and whether this worker holds the type lock. Set just before
	 * execute() and cleared in `finally`, so {@see release_on_shutdown()}
	 * finds it only when PHP stopped inside execute().
	 *
	 * @var array{blog_id: int, job_id: string, type: string, type_locked: bool}|null
	 */
	private static ?array $in_execute = null;

	/**
	 * Whether {@see release_on_shutdown()} is registered in this process.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Hook callback. Both runners ({@see WpCronRunner}, {@see ActionSchedulerRunner})
	 * pass args as `(string $job_id, array $args)` because that's the shape
	 * we serialise into the runner queue.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $args   Worker args.
	 * @return void
	 */
	public static function run( string $job_id, array $args ): void {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return;
		}

		// Multisite: the jobs table is per-blog ($wpdb->prefix), so every
		// JobState write lands on the dispatching blog and the worker MUST be
		// on that blog before it reads state. Both runners normally are -
		// Action Scheduler's tables
		// are per-blog ($wpdb->prefix) and WP-Cron's queue is a per-blog
		// option, so a tick fired for blog N runs in blog N's context - but
		// that is a property of two third-party queues, not something this
		// worker can assume: a queue runner invoked from the network admin, a
		// custom runner supplied through `perflocale/jobs/runner`, or a future
		// AS release that shares tables would all land here on the wrong blog,
		// and the failure mode is silent (state read from another site's jobs
		// table). Pull blog_id off the args sentinel and switch first.
		// The sentinel is injected by Dispatcher::enqueue, by every re-schedule
		// site inside this method, and by Resumer.
		$target_blog = isset( $args['__perflocale_blog_id'] )
			? (int) $args['__perflocale_blog_id']
			: 0;
		unset( $args['__perflocale_blog_id'] );

		$switched_blog = false;
		if ( $target_blog > 0 && function_exists( 'is_multisite' ) && is_multisite()
			&& function_exists( 'get_current_blog_id' ) && $target_blog !== (int) get_current_blog_id()
		) {
			switch_to_blog( $target_blog );
			$switched_blog = true;
		}

		try {
			self::run_on_current_blog( $job_id, $args, $target_blog );
		} finally {
			if ( $switched_blog ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Inner worker body - runs on the (already-switched) blog of the
	 * dispatching site. Split out so {@see run()} can wrap the entire
	 * body in a single switch_to_blog / restore_current_blog pair.
	 *
	 * @param string               $job_id      Job identifier.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID we switched into (0 = none).
	 * @return void
	 */
	private static function run_on_current_blog( string $job_id, array $args, int $target_blog ): void {
		$state = JobState::get( $job_id );

		if ( ! $state ) {
			// Orphan event - JobState row was GC'd or never created.
			// Silently no-op; nothing actionable.
			return;
		}

		// Bail on ANY terminal status, not just 'canceled'. A stray/duplicate
		// cron event for a job that already completed or failed would otherwise
		// re-execute it - double-translating, double-billing the MT provider, or
		// re-running a migration over data the operator has since edited.
		if ( JobState::is_terminal( (string) $state['status'] ) ) {
			return;
		}

		// Pause gate: operator hit "Pause queue" in the admin. The job has
		// already been accepted (it has a JobState row); we just re-schedule
		// it for a future cron tick instead of running. When the operator
		// flips the switch back the next tick picks the work up. Without this,
		// the only options when something is mis-dispatching are "let it run"
		// or "deactivate the plugin" - neither is operator-friendly.
		if ( WorkerRegistry::queue_is_paused() ) {
			JobState::append_log(
				$job_id,
				__( 'Worker paused by operator; re-queued.', 'perflocale' )
			);
			self::schedule_recording_engine(
				time() + (int) apply_filters( 'perflocale/jobs/pause_recheck_seconds', 300 ),
				Dispatcher::worker_hook( (string) $state['type'] ),
				WorkerRegistry::with_blog_sentinel( $args, $target_blog ),
				$job_id
			);
			return;
		}

		$type    = (string) $state['type'];
		$factory = WorkerRegistry::$factories[ $type ] ?? null;

		if ( ! is_callable( $factory ) ) {
			self::fail_for_good(
				$job_id,
				$type,
				sprintf(
				/* translators: %s is the job type slug. */
					__( 'No worker registered for job type "%s".', 'perflocale' ),
					$type
				)
			);
			return;
		}

		// Per-type concurrency cap: the type lock serialises same-type work
		// (AS can fire several same-type events at once, racing on the same
		// wp_options rows); a busy lock re-queues with a short delay. Filter
		// to >1 / PHP_INT_MAX to disable. try/catch so a buggy 3rd-party
		// filter can't fail the worker into a retry dead-loop.
		try {
			$max_concurrent = (int) apply_filters( "perflocale/jobs/max_concurrent/{$type}", 1 );
		} catch ( \Throwable $e ) {
			$max_concurrent = 1;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for 3rd-party filter throwing.
			error_log( '[PerfLocale] perflocale/jobs/max_concurrent/' . $type . ' filter threw: ' . $e->getMessage() );
		}

		$type_locked = false;
		if ( $max_concurrent <= 1 ) {
			if ( ! JobLock::acquire_type( $type ) ) {
				// Re-queue with exponential backoff + jitter. Without jitter,
				// N racing workers all retry at exactly the same second and
				// re-collide on the next tick; randomising 0..min_delay
				// fans them out. Constant 60s would also generate dozens of
				// redundant cron events for an hour-long bulk job.
				$busy_count = (int) ( $args['__perflocale_type_busy_count'] ?? 0 ) + 1;
				$min_delay  = (int) apply_filters( 'perflocale/jobs/type_busy_retry_seconds', 60 );
				$max_delay  = (int) apply_filters( 'perflocale/jobs/type_busy_max_seconds', 600 );
				$base       = max( 1, min( $max_delay, $min_delay * (int) pow( 2, max( 0, $busy_count - 1 ) ) ) );
				try {
					$jitter = random_int( 0, max( 1, (int) ( $min_delay / 2 ) ) );
				} catch ( \Throwable $e ) {
					$jitter = (int) ( $min_delay / 4 );
				}
				$delay = max( 1, $base + $jitter );

				$retry_args                                 = $args;
				$retry_args['__perflocale_type_busy_count'] = $busy_count;

				self::schedule_recording_engine(
					time() + $delay,
					Dispatcher::worker_hook( $type ),
					WorkerRegistry::with_blog_sentinel( $retry_args, $target_blog ),
					$job_id
				);
				JobState::append_log(
					$job_id,
					sprintf(
						/* translators: %d is the deferral delay in seconds. */
						__( 'Same-type worker busy; deferred by %ds.', 'perflocale' ),
						$delay
					)
				);
				return;
			}
			$type_locked = true;
		}

		// Build the AbstractJob early so we can read its per-type lock TTL.
		// If the factory blows up we release the type lock and fail cleanly.
		// `?? null` is intentional defensive coding: the runtime contract
		// allows a dispatched type that isn't in $factories (operator
		// manually fires a stale hook, addon misregisters) and falls
		// through to the instanceof check below for a clean failure path.
		// @phpstan-ignore-next-line nullCoalesce.offset
		$factory = WorkerRegistry::$factories[ $type ] ?? null;
		try {
			/** @var AbstractJob|null $job */
			$job = is_callable( $factory ) ? $factory() : null;
		} catch ( \Throwable $e ) {
			if ( $type_locked ) {
				JobLock::release_type( $type );
			}
			self::fail_for_good( $job_id, $type, self::redact_paths( $e->getMessage() ) );
			return;
		}

		if ( ! $job instanceof AbstractJob ) {
			if ( $type_locked ) {
				JobLock::release_type( $type );
			}
			self::fail_for_good(
				$job_id,
				$type,
				sprintf(
				/* translators: %s is the job type slug. */
					__( 'Job factory did not return an AbstractJob for type "%s".', 'perflocale' ),
					$type
				)
			);
			return;
		}

		$lock_ttl = max( 60, (int) $job->get_lock_ttl() );

		// Extend the per-type lock (acquired above at the 1800s default,
		// before the job existed) to the job's real TTL, so a long job's type
		// lock can't expire mid-flight and let a second same-type job start.
		// Kept fresh by the progress callback below.
		if ( $type_locked ) {
			JobLock::refresh_type( $type, $lock_ttl );
		}

		// Concurrency lock. AS has native claims, but we also lock so the
		// same job_id can't be picked up by both AS and a sync caller. The
		// TTL is taken from the AbstractJob so long-running monolithic
		// migrations don't get reclaimed mid-flight (default 30 min).
		if ( ! JobLock::acquire( $job_id, $lock_ttl ) ) {
			// Another worker holds the per-job lock. Re-queue the worker event
			// with exponential backoff + jitter (the consumed AS/WP-Cron event
			// is gone, so a silent return would strand the job until the
			// stuck-sweep) and release the type lock so other types run. A
			// `__perflocale_lock_busy_count` sentinel caps the chain at 20 so a
			// permanently-wedged lock fails the job instead of thrashing cron.
			if ( $type_locked ) {
				JobLock::release_type( $type );
			}

			$busy_count = (int) ( $args['__perflocale_lock_busy_count'] ?? 0 ) + 1;
			/**
			 * @hook perflocale/jobs/lock_busy_max_retries
			 *
			 * Maximum number of times a worker will re-queue itself when
			 * the per-job lock is held by another worker. After this many
			 * attempts the job is marked failed with a diagnostic
			 * message; the user can manually retry from the Jobs admin
			 * page.
			 *
			 * @param int $max Default 20.
			 */
			$max_retries = max( 1, (int) apply_filters( 'perflocale/jobs/lock_busy_max_retries', 20 ) );

			if ( $busy_count > $max_retries ) {
				// Another worker may still hold the lock and run the job, so a
				// one-time job's dispatch state is left to its check, which
				// takes the failure as final once that lock is gone.
				JobState::mark_failed(
					$job_id,
					sprintf(
					/* translators: 1: job_id, 2: retry count */
						__( 'Per-job concurrency lock held by another worker after %2$d retries; marking failed so the operator can intervene. Either another worker is genuinely running this job, or the lock row is wedged - check wp_options for `perflocale_job_lock_%1$s`.', 'perflocale' ),
						$job_id,
						$max_retries
					)
				);
				return;
			}

			// Backoff: lock_ttl/4 (so the next retry lands BEFORE the lock
			// would naturally expire - gives us multiple chances to catch
			// a held lock as the holder finishes), with a floor of 60s and
			// exponential growth on consecutive busy hits. Jitter avoids
			// thundering-herd if N workers raced into the same lock.
			$min_delay = max( 60, (int) ( $lock_ttl / 4 ) );
			$max_delay = max( $min_delay, (int) apply_filters( 'perflocale/jobs/lock_busy_max_seconds', 600 ) );
			$base      = max( 1, min( $max_delay, $min_delay * (int) pow( 2, max( 0, $busy_count - 1 ) ) ) );
			try {
				$jitter = random_int( 0, max( 1, (int) ( $min_delay / 2 ) ) );
			} catch ( \Throwable $e ) {
				$jitter = (int) ( $min_delay / 4 );
			}
			$delay = max( 1, $base + $jitter );

			$retry_args                                 = $args;
			$retry_args['__perflocale_lock_busy_count'] = $busy_count;

			self::schedule_recording_engine(
				time() + $delay,
				Dispatcher::worker_hook( $type ),
				WorkerRegistry::with_blog_sentinel( $retry_args, $target_blog ),
				$job_id
			);
			JobState::append_log(
				$job_id,
				sprintf(
					/* translators: 1: deferral delay in seconds, 2: retry count, 3: max */
					__( 'Per-job lock held by another worker; deferred by %1$ds (retry %2$d/%3$d).', 'perflocale' ),
					$delay,
					$busy_count,
					$max_retries
				)
			);
			return;
		}

		$prev_user     = (int) get_current_user_id();
		$outer_execute = self::$in_execute;
		$continue_job  = false;

		try {
			// Re-validate cap against stored `created_by`. Catches user
			// deletion / role downgrade between dispatch and execution.
			$created_by = (int) ( $state['created_by'] ?? 0 );

			$required_cap = $job->get_required_capability();
			if ( ! is_string( $required_cap ) || $required_cap === '' ) {
				self::fail_for_good(
					$job_id,
					$type,
					sprintf(
					/* translators: %s is the job type slug. */
						__( 'Worker rejected: get_required_capability() did not return a valid capability string for job type "%s".', 'perflocale' ),
						$type
					)
				);
				return;
			}

			if ( $created_by <= 0 || ! user_can( $created_by, $required_cap ) ) {
				self::fail_for_good( $job_id, $type, __( 'Permission revoked or dispatching user no longer has access.', 'perflocale' ) );
				return;
			}

			// Set current-user so anything inside execute() sees the
			// dispatcher's identity. Cron context defaults to user 0.
			wp_set_current_user( $created_by );

			// Switch to the dispatcher's user locale so worker-emitted
			// translated strings (errors, log entries) match the user
			// who'll see them in REST / admin. Without this, translated
			// strings inside execute() are emitted in the cron tick's
			// locale (typically site_locale, which on multisite may
			// differ from the dispatcher's preferred admin language).
			// `restore_previous_locale()` is paired in the finally.
			$switched_locale = false;
			if ( function_exists( 'switch_to_user_locale' ) ) {
				$switched_locale = (bool) switch_to_user_locale( $created_by );
			}

			// Claim the row BEFORE doing any work. The transition is the
			// durable record that this worker owns the job; if it does not
			// land there is no such record, and everything below - provider
			// spend, created posts, the completion hook - would happen
			// against a row that still says `queued` and is still eligible
			// to be picked up and replayed by the next sweep.
			$resume = [];

			if ( ! JobState::mark_running( $job_id ) ) {
				// The claim did not transition anything. Read the row FRESH
				// (the gate at the top of this method reads a cached one) and
				// decide from what is actually stored.
				$fresh  = JobState::get_fresh( $job_id );
				$status = $fresh ? (string) $fresh['status'] : '';

				if ( 'running' !== $status ) {
					if ( '' === $status || JobState::is_terminal( $status ) ) {
						// Cancelled, completed or failed between the cached
						// gate and this write - or the row is gone. Nothing
						// to report; stand down.
						return;
					}

					// Still `queued`: the claim WRITE failed, so nothing
					// durable says this worker owns the job. Fail loudly
					// rather than run untracked work - a retry from the Jobs
					// page is safe precisely because nothing ran.
					self::fail_for_good(
						$job_id,
						$type,
						__( 'Could not record the job as running (the database refused the status update); refusing to execute it untracked.', 'perflocale' )
					);

					return;
				}

				// Already `running` AND this worker holds the per-job lock
				// (acquired above; a live peer would have sent us down the
				// busy-retry path instead). So the row is ours, resuming:
				// the cooperative pause bail deliberately leaves it running,
				// and mark_running() is a documented no-op there so the
				// attempts counter is not charged twice for one run. A job
				// that runs in slices resumes from the result stored on it.
				$resume = is_array( $fresh['result'] ?? null ) ? $fresh['result'] : [];
				unset( $fresh );
			}

			// A job that runs in slices gets its resume point in a local copy
			// of the args; the re-schedule paths below keep the original args.
			$exec_args = $args;

			if ( $job->supports_continuation() ) {
				unset( $exec_args['__perflocale_slice'] );
				$exec_args['__perflocale_slice'] = [ 'resume' => $resume ];
			}

			// Tick-throttle: refresh the lock on a wall-clock cadence so the
			// progress callback can't accidentally hammer the DB by refreshing
			// every row, and so a worker that emits frequent ticks still pays
			// only one lock-refresh per minute. Also probes pause + cancel on
			// the same cadence so an operator's Pause / Cancel takes effect
			// within ~1 progress tick of the operator action.
			$last_lock_refresh   = time();
			$lock_refresh_window = max( 30, (int) floor( $lock_ttl / 4 ) );
			$last_stage          = '';

			// A fatal error or an exit inside execute() skips `finally`; the
			// shutdown guard then releases both locks and fails the job.
			// WordPress's fatal error handler is a shutdown function registered
			// before this one. When it shows its error page (an admin request,
			// or no output sent yet) it calls wp_die(), and a wp_die handler
			// that exits, as WP-CLI's does, ends the process before the guard
			// runs; the page's arguments are filtered just before that call.
			if ( ! self::$shutdown_registered ) {
				register_shutdown_function( [ WorkerRegistry::class, 'release_on_shutdown' ] );
				add_filter( 'wp_php_error_args', [ WorkerRegistry::class, 'release_before_error_page' ], 10, 1 );
				self::$shutdown_registered = true;
			}

			self::$in_execute = [
				'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'job_id'      => $job_id,
				'type'        => $type,
				'type_locked' => $type_locked,
			];

			// The start of a job's execution is a checkpoint of the process
			// that runs it (`wp action-scheduler run` runs many in one process):
			// what another process changed since is read again from here on.
			\PerfLocale\Cache\CacheManager::refresh_if_changed();

			// The progress callback: items done and in total, and an optional
			// detail. A detail `stage` (a key) with its `label` starts a stage:
			// its first report appends a log line keyed `stage:<key>`, which
			// the Jobs page reads as the running job's stage. A detail
			// `percent` is the job's overall progress when processed / total
			// count the current stage only.
			$result = $job->execute(
				$exec_args,
				static function ( int $processed, int $total, array $detail = [] ) use ( $job_id, $type, $type_locked, $lock_ttl, &$last_lock_refresh, $lock_refresh_window, &$last_stage ): void {
					$now = JobState::get_fresh( $job_id );

					// Cancellation check. An operator Cancel (Jobs page / REST /
					// CLI) during execute() sets status='canceled' in the DB while
					// the worker's object-cache still holds the old value;
					// get_fresh() busts that cache so we see it within one tick.
					// Throws a sentinel so the catch routes to the canceled path
					// (no retry, no error log).
					if ( $now && (string) $now['status'] === 'canceled' ) {
						throw new JobCanceledException( 'Job canceled mid-flight.' );
					}

					// Pause check - if the operator paused the queue while this
					// worker was running, cooperatively bail out. The shared
					// sentinel routes to the JobCanceledException catch, which
					// tells pause from cancel by the fresh row status and
					// RE-QUEUES the paused worker like the pre-run gate does.
					if ( WorkerRegistry::queue_is_paused() ) {
						JobState::append_log( $job_id, __( 'Queue paused mid-flight; worker exiting cooperatively.', 'perflocale' ) );
						throw new JobCanceledException( 'Queue paused mid-flight.' );
					}

					$stage = isset( $detail['stage'] ) ? sanitize_key( (string) $detail['stage'] ) : '';

					if ( $stage !== '' && $stage !== $last_stage ) {
						$label = isset( $detail['label'] ) ? (string) $detail['label'] : '';
						JobState::append_log( $job_id, $label !== '' ? $label : $stage, 'stage:' . $stage );
						$last_stage = $stage;
					}

					JobState::update_progress( $job_id, $processed, $total, isset( $detail['percent'] ) ? (int) $detail['percent'] : -1 );

					// Refresh the lock on a wall-clock cadence (default: every
					// TTL/4 seconds). Avoids a DB write per progress tick from
					// jobs that emit thousands of ticks; still keeps the lock
					// fresh for jobs that emit sparse ticks.
					$wall = time();
					if ( $wall - $last_lock_refresh >= $lock_refresh_window ) {
						JobLock::refresh( $job_id, $lock_ttl );
						if ( $type_locked ) {
							JobLock::refresh_type( $type, $lock_ttl );
						}
						$last_lock_refresh = $wall;
					}
				}
			);

			// A job may report a run that did nothing but fail with
			// `run_failed` and a reason in `first_error`. It is recorded as
			// failed with its result, so the Jobs page offers Retry, and is not
			// re-run automatically: every item already failed once and was
			// paid for.
			$run_failed_reason = is_array( $result ) && ! empty( $result['run_failed'] )
				? trim( (string) ( $result['first_error'] ?? '' ) )
				: '';

			if ( $job->supports_continuation() && ! empty( $result['__perflocale_continue'] ) ) {
				// The slice stopped before the last item. The row stays running
				// with the counts so far and, once they are stored, the job is
				// scheduled again after both locks are released (below).
				// Completion is recorded, and its hook fires, once: after the
				// last slice.
				unset( $result['__perflocale_continue'] );

				$continue_job = self::store_checkpoint( $job_id, $type, $result );
			} elseif ( $run_failed_reason !== '' ) {
				JobState::mark_failed( $job_id, self::redact_paths( $run_failed_reason ) );
				JobState::record_result( $job_id, $result );

				$stored = JobState::get_fresh( $job_id );

				if ( 'failed' === ( $stored['status'] ?? null ) ) {
					/** This action is documented in src/Background/WorkerRunner.php */
					do_action( 'perflocale/jobs/failed', $job_id, $type, new \RuntimeException( $run_failed_reason ) );

					// Not retried automatically: a one-time job records the failure.
					OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
				}
			} elseif ( JobState::mark_complete( $job_id, is_array( $result ) ? $result : [] ) ) {
				/**
				 * Fires after a background job finishes successfully.
				 *
				 * @hook perflocale/jobs/completed
				 * @param string $job_id Identifier of the completed job.
				 * @param string $type   Job type slug.
				 * @param array  $result Result payload (already stored on the job row).
				 */
				do_action( 'perflocale/jobs/completed', $job_id, $type, is_array( $result ) ? $result : [] );
			} else {
				// The row did not move running -> complete, so the completion
				// hook must not claim it did. Report what is actually stored.
				// Never throw from here: the Throwable catch below would mark
				// the finished work failed and schedule an automatic re-run.
				$stored        = JobState::get_fresh( $job_id );
				$stored_status = $stored['status'] ?? null;

				if ( 'canceled' === $stored_status ) {
					self::report_late_cancel( $job_id, $type, $result );
				} elseif ( 'running' === $stored_status ) {
					// The status write itself failed. The row stays running
					// until the hourly watchdog marks it failed as stalled.
					JobState::append_log(
						$job_id,
						__( 'The work finished, but the job could not be marked complete (the database refused the status update).', 'perflocale' )
					);
				}
			}
		} catch ( JobCanceledException $e ) {
			// Two cooperative bails share this sentinel: operator CANCEL
			// (row status is already 'canceled' - that's how the progress
			// callback detected it) and operator PAUSE (status still
			// 'running'). Distinguish by the fresh row status: a paused
			// worker must be re-queued exactly like the pre-run pause gate,
			// otherwise the job strands as 'running' with no scheduled event
			// - unpause never resumes it and the 6h watchdog force-fails it.
			$fresh_state = JobState::get_fresh( $job_id );

			if ( ! $fresh_state || (string) $fresh_state['status'] === 'canceled' ) {
				JobState::append_log(
					$job_id,
					__( 'Worker aborted by operator cancel.', 'perflocale' )
				);

				// The counts the job reached before the cancel, when it attached them.
				JobState::record_result( $job_id, $e->get_result() );

				/**
				 * Fires when a worker aborted itself in response to a cancel.
				 * Distinct from `perflocale/jobs/failed` because it isn't an
				 * error; useful for monitoring integrations that want to
				 * distinguish operator-canceled from worker-errored.
				 *
				 * @hook perflocale/jobs/canceled
				 * @param string $job_id
				 * @param string $type
				 */
				do_action( 'perflocale/jobs/canceled', $job_id, $type );
			} else {
				JobState::append_log(
					$job_id,
					__( 'Worker paused by operator; re-queued.', 'perflocale' )
				);
				self::schedule_recording_engine(
					time() + (int) apply_filters( 'perflocale/jobs/pause_recheck_seconds', 300 ),
					Dispatcher::worker_hook( $type ),
					WorkerRegistry::with_blog_sentinel( $args, $target_blog ),
					$job_id
				);
			}
		} catch ( \Throwable $e ) {
			// Strip absolute filesystem paths from the error message before
			// persisting. The stored message is visible to supervisors via
			// the Jobs admin page and the REST endpoint; leaking the host's
			// directory structure helps neither the operator nor anyone
			// trying to debug. The original exception is still passed to
			// the perflocale/jobs/failed hook for code that wants the full
			// trace.
			JobState::mark_failed( $job_id, self::redact_paths( $e->getMessage() ) );

			/**
			 * Fires after a background job ends in an unrecoverable error
			 * (or before its retry-with-backoff is scheduled).
			 *
			 * @hook perflocale/jobs/failed
			 * @param string     $job_id Identifier of the failed job.
			 * @param string     $type   Job type slug.
			 * @param \Throwable $e      The thrown exception.
			 */
			do_action( 'perflocale/jobs/failed', $job_id, $type, $e );

			self::retry_or_record_failure( $job_id, $type, $args, $target_blog );

		} finally {
			// The end of a job's run is a batch boundary: other processes'
			// checkpoints see the entries it dropped from here on, before
			// its locks go.
			\PerfLocale\Cache\CacheManager::announce_changes();

			if ( ! empty( $switched_locale ) && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
			wp_set_current_user( $prev_user );
			JobLock::release( $job_id );
			// Release the type lock based on the flag captured at acquisition
			// time, not by re-evaluating the filter - the filter could return
			// a different value here than at acquire time, leaking the lock.
			if ( $type_locked ) {
				JobLock::release_type( $type );
			}
			self::$in_execute = $outer_execute;
		}

		// After `finally`: a continuation that ran while this worker still held
		// the per-job lock would take the lock-busy path and wait lock_ttl/4.
		if ( $continue_job ) {
			self::schedule_continuation( $job_id, $type, $args, $target_blog );
		}
	}

	/**
	 * Store the counts a slice stopped at on the running row, and say whether
	 * the next slice may be scheduled.
	 *
	 * The next slice resumes from what is stored, so it is scheduled only
	 * after the write lands. A refused write is tried once more, after a
	 * fresh read of the row. A cancel found by either read wins, as it does
	 * at the last progress tick. When the second write is refused too, the
	 * job is failed with a plain message and nothing is scheduled; if that
	 * status write is refused as well, the row stays running with no worker
	 * and the dead-worker check fails it. A row in any other state (failed
	 * by the watchdog, gone) is left as it is.
	 *
	 * @param string               $job_id Job identifier.
	 * @param string               $type   Job type slug.
	 * @param array<string, mixed> $result The counts the slice stopped at.
	 * @return bool Whether the counts are stored and the next slice may run.
	 */
	private static function store_checkpoint( string $job_id, string $type, array $result ): bool {
		for ( $write = 1; $write <= 2; $write++ ) {
			$stored = JobState::get_fresh( $job_id );
			$status = $stored['status'] ?? null;

			if ( 'canceled' === $status ) {
				self::report_late_cancel( $job_id, $type, $result );
				return false;
			}

			if ( 'running' !== $status ) {
				return false;
			}

			if ( JobState::record_result( $job_id, $result ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the jobs table refused the same write twice.
		error_log( '[PerfLocale] Job ' . $job_id . ': the database refused twice to store the progress of a slice, so the job was stopped and marked failed.' );

		$reason = __( 'The progress of this job could not be saved (the database refused the update), so the job was stopped. Retry it to run it again.', 'perflocale' );

		JobState::mark_failed( $job_id, $reason );

		if ( 'failed' === ( JobState::get_fresh( $job_id )['status'] ?? null ) ) {
			/** This action is documented in src/Background/WorkerRunner.php */
			do_action( 'perflocale/jobs/failed', $job_id, $type, new \RuntimeException( $reason ) );

			// Not retried automatically: a one-time job records the failure.
			OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
		}

		return false;
	}

	/**
	 * Schedule the next slice of a job that runs in slices.
	 *
	 * Due now, with the args the job was dispatched with: the busy counters
	 * of an earlier deferral are dropped, so the event has the arg shape the
	 * runners' cancel and is_scheduled() probes and the Resumer look for, and
	 * a deferral's backoff does not carry into the next slice.
	 *
	 * @param string               $job_id      Job identifier.
	 * @param string               $type        Job type slug.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID to re-inject as sentinel (0 on single-site).
	 * @return void
	 */
	private static function schedule_continuation( string $job_id, string $type, array $args, int $target_blog ): void {
		unset( $args['__perflocale_type_busy_count'], $args['__perflocale_lock_busy_count'] );

		self::schedule_recording_engine(
			time(),
			Dispatcher::worker_hook( $type ),
			WorkerRegistry::with_blog_sentinel( $args, $target_blog ),
			$job_id
		);
	}

	/**
	 * Record an operator cancel that landed after the job's last progress tick.
	 *
	 * Cancel wins, as it does when the last tick sees it: the result is not
	 * recorded, the chunk this execution queued to continue itself is
	 * canceled, and `perflocale/jobs/canceled` fires.
	 *
	 * @param string               $job_id Job identifier.
	 * @param string               $type   Job type slug.
	 * @param array<string, mixed> $result The result execute() returned.
	 * @return void
	 */
	private static function report_late_cancel( string $job_id, string $type, array $result ): void {
		JobState::append_log(
			$job_id,
			__( 'Canceled by the operator after the work had finished; the result was not recorded.', 'perflocale' )
		);

		self::cancel_queued_successor( $job_id, $type, $result );

		/** This action is documented in src/Background/WorkerRunner.php */
		do_action( 'perflocale/jobs/canceled', $job_id, $type );
	}

	/**
	 * Release the locks of a job whose execute() never returned, and mark the
	 * job failed.
	 *
	 * Registered once per process as a shutdown function. A no-op unless PHP
	 * stopped inside execute(): a fatal error (memory, execution time) or an
	 * exit. Switches to the job's blog first. JobLock releases only the values
	 * this process stamped, so a lock another worker took over stays, and
	 * JobState::mark_failed() moves only queued or running rows. The stored
	 * error is the fatal error's message with paths redacted, or a generic
	 * message when there was no fatal error. A process killed without a
	 * shutdown (SIGKILL, the kernel's out-of-memory killer) never gets here;
	 * its locks expire. Also run from {@see release_before_error_page()},
	 * before WordPress's fatal error page; the record is cleared on the first
	 * run, so the second does nothing.
	 *
	 * @return void
	 */
	public static function release_on_shutdown(): void {
		$record = self::$in_execute;

		if ( null === $record ) {
			return;
		}

		self::$in_execute = null;
		$switched_blog    = false;

		if ( $record['blog_id'] > 0 && function_exists( 'is_multisite' ) && is_multisite()
			&& $record['blog_id'] !== (int) get_current_blog_id()
		) {
			switch_to_blog( $record['blog_id'] );
			$switched_blog = true;
		}

		try {
			JobLock::release( $record['job_id'] );

			if ( $record['type_locked'] ) {
				JobLock::release_type( $record['type'] );
			}

			$error   = error_get_last();
			$message = is_array( $error ) && in_array( $error['type'], self::FATAL_ERROR_TYPES, true )
				? self::redact_paths( $error['message'] )
				: '';

			self::fail_for_good(
				$record['job_id'],
				$record['type'],
				'' !== $message
					? $message
					/* translators: Stored as the error of a background job whose PHP process ended while the job was running, without a PHP error. */
					: __( 'The worker stopped before finishing.', 'perflocale' )
			);
		} finally {
			if ( $switched_blog ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * `wp_php_error_args`: run the shutdown guard before WordPress's fatal
	 * error page calls wp_die(), which ends the process when its handler
	 * exits (WP-CLI). The arguments are returned unchanged, and a failure
	 * here does not stop the page.
	 *
	 * @param mixed $args wp_die() arguments of the error page.
	 * @return mixed
	 */
	public static function release_before_error_page( mixed $args = [] ): mixed {
		try {
			self::release_on_shutdown();
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $args;
	}

	/**
	 * After a run that threw: schedule the automatic retry when the job may
	 * have one. When the failure is final instead (no retry left, the reset
	 * for the retry refused, or the runner refused the retry's event) a
	 * one-time job records it in its dispatch state
	 * ({@see OneTimeDispatch::job_ended()}). While a retry is pending that
	 * state stays queued, so the final failure records its own time.
	 *
	 * @param string               $job_id      Job identifier.
	 * @param string               $type        Job type slug.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID to re-inject as sentinel (0 on single-site).
	 * @return void
	 */
	private static function retry_or_record_failure( string $job_id, string $type, array $args, int $target_blog ): void {
		if ( 'failed' === self::schedule_retry_if_eligible( $job_id, $type, $args, $target_blog ) ) {
			OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
		}
	}

	/**
	 * Mark a job failed for good (no automatic retry follows) and, when this
	 * call made the transition, record it for a one-time job
	 * ({@see OneTimeDispatch::job_ended()}; a no-op without a query for any
	 * other type). Nothing is recorded when a cancel or a completion won.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $type   Job type slug.
	 * @param string $error  Error stored on the row.
	 * @return void
	 */
	private static function fail_for_good( string $job_id, string $type, string $error ): void {
		if ( JobState::mark_failed( $job_id, $error ) ) {
			OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
		}
	}

	/**
	 * Re-schedule the job with exponential backoff if we haven't hit the
	 * attempt cap. The attempt counter is bumped inside
	 * {@see JobState::mark_running()}, so reading it here gives us the
	 * current count.
	 *
	 * @param string               $job_id      Job identifier.
	 * @param string               $type        Job type slug.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID to re-inject as sentinel (0 on single-site).
	 * @return string 'retry' when a retry is scheduled; 'failed' when the row
	 *                stays failed with no retry (none left, the reset refused,
	 *                or the runner refused the event and the row went back to
	 *                failed); 'none' when the row is gone or in any other state
	 *                (a cancel or a completion won, another process owns it).
	 */
	private static function schedule_retry_if_eligible( string $job_id, string $type, array $args, int $target_blog ): string {
		$state = JobState::get( $job_id );

		if ( ! $state ) {
			return 'none';
		}

		// Never resurrect a job that already reached a terminal-by-intent
		// state. A non-cancel exception racing with an operator cancel (or a
		// completion) would otherwise re-schedule a job the operator believes
		// is finished. 'failed' is intentionally NOT terminal here - that is
		// exactly the state this retry path exists to recover from.
		$current_status = (string) ( $state['status'] ?? '' );
		if ( $current_status === 'canceled' || $current_status === 'complete' ) {
			return 'none';
		}

		$attempts = (int) ( $state['attempts'] ?? 0 );

		/**
		 * Maximum number of times a worker is re-scheduled after a thrown
		 * exception. Includes the initial attempt - i.e. max_attempts=5
		 * runs the job up to 5 times total.
		 *
		 * @hook perflocale/jobs/max_attempts
		 * @param int $max Default 5.
		 */
		$max = (int) apply_filters( 'perflocale/jobs/max_attempts', 5 );

		if ( $attempts >= $max ) {
			return 'failed' === $current_status ? 'failed' : 'none';
		}

		$delay = min( 3600, 60 * (int) pow( 2, $attempts - 1 ) );

		/**
		 * Filter the retry delay (seconds). Receives the current attempt
		 * count so backends can implement custom backoff curves.
		 *
		 * @hook perflocale/jobs/retry_delay
		 * @param int $delay    Default = exponential, capped at 1 hour.
		 * @param int $attempts How many attempts have already run.
		 */
		$delay = (int) apply_filters( 'perflocale/jobs/retry_delay', $delay, $attempts );
		$delay = max( 1, $delay );

		// The row is reset first; an event for a row that is not queued would
		// stop at the worker's terminal-status gate and retry nothing.
		if ( ! JobState::reset_for_retry( $job_id ) ) {
			return 'failed' === ( JobState::get_fresh( $job_id )['status'] ?? null ) ? 'failed' : 'none';
		}

		try {
			self::schedule_recording_engine(
				time() + $delay,
				Dispatcher::worker_hook( $type ),
				WorkerRegistry::with_blog_sentinel( $args, $target_blog ),
				$job_id
			);
		} catch ( \Throwable $e ) {
			// The runner refused the event: the queued row would wait for the
			// stuck-job sweep. It goes back to failed (retryable from the Jobs
			// page) with the runner's error, unless a worker took it meanwhile.
			$message = sprintf(
				/* translators: %s is the runner's error message. */
				__( 'Failed to enqueue background job: %s', 'perflocale' ),
				self::redact_paths( $e->getMessage() )
			);

			if ( ! JobState::fail_queued( $job_id, $message ) ) {
				return 'none';
			}

			JobState::append_log( $job_id, $message );

			return 'failed';
		}

		JobState::append_log(
			$job_id,
			sprintf(
			/* translators: %1$d = attempt number, %2$d = delay seconds. */
				__( 'Retry scheduled (attempt %1$d, delay %2$ds).', 'perflocale' ),
				$attempts + 1,
				$delay
			)
		);

		return 'retry';
	}

	/**
	 * Cancel the job a canceled job queued to continue itself.
	 *
	 * A chained job (SiteTranslateJob) enqueues its next chunk before the
	 * worker records the result, so a cancel that lands in between leaves
	 * that chunk queued and scheduled. The chunk is named by the result's
	 * `next_job` key. It is canceled only when it is a different job of the
	 * same type and `next_duplicate` is explicitly false: a dispatch folded
	 * into an identical job already in flight belongs to someone else.
	 *
	 * Mirrors the REST cancel: the row first, then the worker event and the
	 * per-job lock; the per-type lock is left to its owner. Never throws, so
	 * the Throwable catch in the caller cannot misreport the canceled job as
	 * failed and schedule a retry.
	 *
	 * @param string               $job_id Canceled job id.
	 * @param string               $type   Its job type.
	 * @param array<string, mixed> $result The result execute() returned.
	 * @return void
	 */
	private static function cancel_queued_successor( string $job_id, string $type, array $result ): void {
		$next = $result['next_job'] ?? null;

		if ( ! is_string( $next ) || $next === $job_id || ! JobState::is_safe_id( $next )
			|| false !== ( $result['next_duplicate'] ?? null )
		) {
			return;
		}

		try {
			$next_state = JobState::get_fresh( $next );

			if ( ! $next_state || ( $next_state['type'] ?? null ) !== $type || ! JobState::cancel( $next ) ) {
				return;
			}

			$engine = $next_state['engine'] ?? '';
			JobRunnerFactory::for_engine( is_string( $engine ) ? $engine : '' )->cancel( $next );
			JobLock::release( $next );

			JobState::append_log(
				$next,
				sprintf(
					/* translators: %s: id of the canceled job that queued this one. */
					__( 'Canceled because job %s, which queued this one, was canceled.', 'perflocale' ),
					$job_id
				)
			);
			JobState::append_log(
				$job_id,
				sprintf(
					/* translators: %s: id of the job this one had queued. */
					__( 'The job it had queued next (%s) was canceled as well.', 'perflocale' ),
					$next
				)
			);
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the successor may still be queued.
			error_log( '[PerfLocale] could not cancel job ' . $next . ' queued by canceled job ' . $job_id . ': ' . self::redact_paths( $e->getMessage() ) );
		}
	}

	/**
	 * Re-schedule a worker AND record the engine it actually landed on.
	 *
	 * The reschedule paths (pause / type-busy / lock-busy / retry) pick the
	 * current best runner, which may differ from the engine the job was
	 * originally accepted under (operator flipped background_engine, or AS
	 * became (un)available mid-flight). JobState['engine'] must follow, or the
	 * watchdog's for_engine($state['engine'])->is_scheduled() probe targets the
	 * wrong store - force-failing a healthy job or missing a stuck one. Mirrors
	 * Resumer's engine-write.
	 *
	 * @param int                  $timestamp When to run.
	 * @param string               $hook Worker hook.
	 * @param array<string, mixed> $args Worker args.
	 * @param string               $job_id Job id.
	 * @return void
	 */
	private static function schedule_recording_engine( int $timestamp, string $hook, array $args, string $job_id ): void {
		$runner = JobRunnerFactory::pick();
		$runner->schedule( $timestamp, $hook, $args, $job_id );
		JobState::set_engine( $job_id, $runner->get_engine_name() );
	}

	/**
	 * Strip absolute filesystem paths from a string so it can be stored on
	 * a job state row without leaking the host's directory layout.
	 *
	 * Replaces:
	 *   - ABSPATH prefix (the canonical WP installation root) → `<wp>/`
	 *   - WP_CONTENT_DIR prefix → `<content>/`
	 *   - Any remaining `/abs/path/to/file.ext` → `<path>/file.ext`
	 *
	 * Tuned for the kind of messages PHP throws ("File not found:
	 * /var/www/...","RuntimeException: ... /etc/passwd ..."). Conservative
	 * regex: only matches sequences that look like absolute Unix paths
	 * ending in a recognised file extension, to avoid mangling unrelated
	 * text. Multi-line safe.
	 *
	 * The rule itself lives in {@see \PerfLocale\Util\PathRedactor} so the addon
	 * boot-failure log applies exactly the same one.
	 *
	 * @param string $message Raw exception/error message.
	 * @return string Redacted message, never longer than the input.
	 */
	private static function redact_paths( string $message ): string {
		return \PerfLocale\Util\PathRedactor::redact( $message );
	}
}
