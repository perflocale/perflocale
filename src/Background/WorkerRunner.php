<?php
/**
 * Background job execution: the worker hook's pipeline.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the jobs WorkerRegistry registered: the pipeline WorkerRegistry::run() forwards to (lock, capability check,
 * retry and state transitions) and a running job's shutdown guard. Loaded on the first job run of a process.
 */
final class WorkerRunner {

	/**
	 * The error types WordPress's fatal error handler treats as fatal.
	 *
	 * @var array<int, int>
	 */
	private const FATAL_ERROR_TYPES = [ E_ERROR, E_PARSE, E_USER_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ];

	/** Repeated claims on a fresh row after a claim missed only by the version. */
	private const CLAIM_TRIES = 3;

	/** Seconds until the next event of a job whose claim kept missing by the version. */
	private const CLAIM_RETRY_DELAY = 10;

	/**
	 * The job whose execute() runs in this process (blog, id, type, type lock held), set just before execute() and
	 * cleared in `finally`, so release_on_shutdown() finds it only when PHP stopped inside execute().
	 *
	 * @var array{blog_id: int, job_id: string, type: string, type_locked: bool, resumes: bool, generation?: int}|null
	 */
	private static ?array $in_execute = null;

	/**
	 * Whether release_on_shutdown() is registered in this process.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Hook callback: both runners (WpCronRunner, ActionSchedulerRunner) pass `(string $job_id, array $args)`, the shape
	 * serialized into their queues.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $args   Worker args.
	 * @return void
	 */
	public static function run( string $job_id, array $args ): void {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return;
		}

		// The jobs table is per blog: switch to the dispatching blog (the args sentinel) before reading state. Both
		// runners normally run there, but a network-admin runner, a custom runner or a shared-table queue would not,
		// and silently. Why: docs-ai/11-code-rationale.md, "WorkerRunner: blog, claim and resume".
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
	 * The worker body on the dispatching blog, apart so run() wraps it in one switch_to_blog() pair.
	 *
	 * @param string               $job_id      Job identifier.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID we switched into (0 = none).
	 * @return void
	 */
	private static function run_on_current_blog( string $job_id, array $args, int $target_blog ): void {
		$state = JobState::get( $job_id );

		if ( ! $state ) {
			// Orphan event: the row was GC'd or never created; nothing to do.
			return;
		}

		// Any terminal status: a stray or duplicate event for a finished job would translate and bill twice, or re-run
		// a migration over data edited since.
		if ( JobState::is_terminal( (string) $state['status'] ) ) {
			return;
		}

		// Pause gate ("Pause queue" in the admin): the accepted job is scheduled for a later tick instead of running,
		// and resumes when the queue is unpaused.
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

		// Per-type cap: the type lock serializes same-type work (Action Scheduler can fire several at once); a busy
		// lock re-queues with a short delay. A filter to > 1 disables it; try/catch so a bad filter cannot loop
		// retries.
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
				// Re-queue with exponential backoff and jitter, so racing workers fan out instead of colliding on the
				// next tick, and an hour-long job does not leave dozens of 60 s events.
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

		// The job early, for its lock TTL (a failing factory releases the type lock). `?? null`: a stale hook or a
		// misregistered add-on can dispatch a type with no factory, which fails at the instanceof below.
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
		$resumes  = $job->resumes_after_kill();

		// A job that resumes after a kill takes a short lease on the web, so its dead-man event finds a killed
		// worker's lock expired. WP-CLI keeps the long one: its slices have no deadline.
		if ( $resumes && 'cli' !== PHP_SAPI ) {
			$lock_ttl = min( $lock_ttl, JobResume::LEASE );
		}

		// Extend the type lock (taken at the 1800 s default before the job existed) to the job's TTL, so a second
		// same-type job cannot start mid-flight; the progress callback keeps it fresh.
		if ( $type_locked ) {
			JobLock::refresh_type( $type, $lock_ttl );
		}

		// The per-job lock: Action Scheduler claims actions, but this also keeps a sync caller off the same job. The
		// TTL is the job's, so a long migration is not reclaimed mid-flight (default 30 min).
		if ( ! JobLock::acquire( $job_id, $lock_ttl ) ) {
			// Another worker holds the job lock: re-queue with backoff and jitter (the consumed event is gone, so a
			// silent return strands the job) and release the type lock. `__perflocale_lock_busy_count` caps the chain.
			if ( $type_locked ) {
				JobLock::release_type( $type );
			}

			$busy_count = (int) ( $args['__perflocale_lock_busy_count'] ?? 0 ) + 1;
			/**
			 * @hook perflocale/jobs/lock_busy_max_retries
			 *
			 * Maximum number of times a worker will re-queue itself when
			 * the per-job lock is held by another worker. After this many
			 * attempts the event stops; the job is left to the lock holder.
			 *
			 * @param int $max Default 20.
			 */
			$max_retries = max( 1, (int) apply_filters( 'perflocale/jobs/lock_busy_max_retries', 20 ) );

			if ( $busy_count > $max_retries ) {
				// Stop this duplicate event without touching the row: the holder may still be
				// running the job, and a holder that died leaves a stale row the watchdog fails.
				return;
			}

			// Backoff lock_ttl/4 (floor 60 s), growing on consecutive busy hits: retries land before the lock would
			// expire.
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

		$resume_meta   = [];
		$worker_args   = $args;
		$prev_user     = (int) get_current_user_id();
		$outer_execute = self::$in_execute;
		$continue_job  = false;
		$continue_at   = 0;
		$generation    = -1;
		$superseded    = false;
		$waiting       = false;

		try {
			// Re-validate the capability against the stored `created_by`: the user may be deleted or downgraded since
			// dispatch.
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

			// A row with no user (anonymized, or a system job) runs only for a type that opts in.
			if ( ( $created_by <= 0 && ! $job->runs_without_user() ) || ! user_can( $created_by, $required_cap ) ) {
				self::fail_for_good( $job_id, $type, __( 'Permission revoked or dispatching user no longer has access.', 'perflocale' ) );
				return;
			}

			// execute() runs as the dispatcher (cron runs as user 0).
			wp_set_current_user( $created_by );

			// The dispatcher's locale, so strings the worker emits (errors, log lines) are in their admin language;
			// restored in `finally`.
			$switched_locale = false;
			if ( function_exists( 'switch_to_user_locale' ) ) {
				$switched_locale = (bool) switch_to_user_locale( $created_by );
			}

			// Claim the row before any work: the transition is the durable record that this worker owns the job;
			// without it, spending and writes would run against a row still `queued` and eligible for a replay.
			$resume   = [];
			$claimed  = true;
			$position = 0;
			$attempts = -1;

			// The claim is a compare-and-set on the row read here, so this run's generation and checkpoint are its own claim's.
			$claim_row  = JobState::get_fresh( $job_id );
			$claim_from = is_int( $claim_row['attempts'] ?? null ) ? $claim_row['attempts'] : -1;

			$refused = false;
			$won     = $claim_from >= 0 && JobState::mark_running( $job_id, $created_by, is_int( $claim_row['version'] ?? null ) ? $claim_row['version'] : -1, $refused );
			$fresh   = $won ? null : JobState::get_fresh( $job_id );

			// Still queued for this owner with the same attempts (a Retry or a bookkeeping write since the read): claimed
			// again on the fresh row, a few times.
			for ( $try = 0; $try < self::CLAIM_TRIES && ! $won && ! $refused && $fresh && 'queued' === $fresh['status'] && $fresh['created_by'] === $created_by && $fresh['attempts'] === $claim_from && is_int( $fresh['version'] ?? null ); $try++ ) {
				$claim_row = $fresh;
				$won       = JobState::mark_running( $job_id, $created_by, $fresh['version'], $refused );
				$fresh     = $won ? null : JobState::get_fresh( $job_id );
			}

			if ( ! $won ) {
				$claimed = false;

				// The claim moved nothing: decide from the fresh read (the gate above read a cached row).
				$status = $fresh ? (string) $fresh['status'] : '';

				// Another user's Cancel and Retry since the check: that Retry's event runs the job, authorized as them.
				if ( $fresh && ( is_int( $fresh['created_by'] ?? null ) ? $fresh['created_by'] : 0 ) !== $created_by ) {
					return;
				}

				// Claimed by another run since the read (a Cancel and Retry, another worker): that run owns the job.
				if ( $fresh && ( $fresh['attempts'] ?? null ) !== $claim_from ) {
					return;
				}

				if ( 'running' !== $status ) {
					if ( '' === $status || JobState::is_terminal( $status ) ) {
						// Canceled, completed or failed since the gate, or gone: stand down.
						return;
					}

					// Still `queued` after every try without an error: another event of the job runs it.
					if ( ! $refused ) {
						try {
							self::schedule_recording_engine( time() + self::CLAIM_RETRY_DELAY, Dispatcher::worker_hook( $type ), WorkerRegistry::with_blog_sentinel( $args, $target_blog ), $job_id );
							$waiting = true;
						} catch ( \Throwable $e ) {
							// Unclaimed: failed only while the row is still the one read, else another run owns it.
							if ( $fresh && is_int( $fresh['version'] ?? null ) && JobState::fail_queued( $job_id, self::redact_paths( $e->getMessage() ), $fresh['version'] ) ) {
								OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
							}
						}

						return;
					}

					// The claim write failed: nothing durable says this worker owns the job. Fail it loudly, only while it
					// is still the row read, instead of running untracked work; a Retry is safe because nothing ran.
					if ( $fresh && is_int( $fresh['version'] ?? null ) && JobState::fail_queued( $job_id, __( 'Could not record the job as running (the database refused the status update); refusing to execute it untracked.', 'perflocale' ), $fresh['version'] ) ) {
						OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
					}

					return;
				}

				// Already `running` and this worker holds the job lock: the row is ours, resuming (a pause bail leaves
				// it running, and mark_running() does not charge attempts twice). A sliced job resumes from its stored
				// result.
				$resume   = self::stored_result( $fresh );
				$position = is_int( $fresh['processed'] ?? null ) ? $fresh['processed'] : 0;
				$attempts = is_int( $fresh['attempts'] ?? null ) ? $fresh['attempts'] : -1;
				unset( $fresh );
			} else {
				$attempts = $claim_from + 1;

				if ( $resumes ) {
					// A requeued or retried row keeps its checkpoint: the one the claim was made on.
					$resume   = self::stored_result( $claim_row );
					$position = is_int( $claim_row['processed'] ?? null ) ? $claim_row['processed'] : 0;
				}
			}

			unset( $worker_args['__perflocale_type_busy_count'], $worker_args['__perflocale_lock_busy_count'] );
			$worker_args = WorkerRegistry::with_blog_sentinel( $worker_args, $target_blog );

			if ( $resumes ) {
				[ $resume, $resume_meta ] = JobResume::split( $resume );
				$engine                   = is_string( $state['engine'] ?? null ) ? $state['engine'] : '';
				$wait                     = JobResume::wait_until( $resume_meta );

				// An early event of a continuing job (another engine's, a duplicate) leaves the one event at the saved
				// time.
				if ( $wait > 0 ) {
					JobResume::reschedule( $job_id, Dispatcher::worker_hook( $type ), $worker_args, $wait, $engine );
					$waiting = true;
					return;
				}

				$resume_meta              = JobResume::open( $job_id, $type, $resume, $resume_meta, $position, $claimed, $attempts );

				if ( null === $resume_meta ) {
					return;
				}

				// The dead-man event: a killed slice is run again from its checkpoint after the lease.
				JobResume::reschedule( $job_id, Dispatcher::worker_hook( $type ), $worker_args, time() + $lock_ttl + JobResume::DEAD_MAN_GRACE, $engine );
			}

			// The generation is this claim's attempts (counted by the opening), so a later claim cannot be adopted.
			$generation = $attempts >= 0 ? $attempts : self::generation( $job_id );

			// A job without the opening's fence: a later claim (Cancel and Retry) since this one owns the job.
			if ( ! $resumes && $claimed ) {
				$now = JobState::owner( $job_id );

				if ( self::taken_over( $now, $generation ) ) {
					self::log_taken_over( $job_id, $now );
					return;
				}
			}

			// A sliced job gets its resume point in a copy of the args; the re-schedule paths keep the original.
			$exec_args = $args;

			if ( $job->supports_continuation() ) {
				unset( $exec_args['__perflocale_slice'] );

				// The stops (kills and deadline stops) counted at the slice's first unit, for a job that ends a unit
				// itself.
				$exec_args['__perflocale_slice'] = [
					'resume' => $resume,
					'stops'  => [
						'at'    => $resume_meta['at'] ?? -1,
						'count' => $resume_meta['kills'] ?? 0,
					],
				];
			}

			// Lock refresh and the pause and cancel probes on a wall-clock cadence: one refresh per interval however
			// often a job ticks, and an operator's Pause or Cancel lands within about one tick.
			$last_lock_refresh   = time();
			$saw_checkpoint      = false;
			$last_unit           = '';
			$lock_refresh_window = max( 30, (int) floor( $lock_ttl / 4 ) );
			$last_stage          = '';

			// A fatal error or exit inside execute() skips `finally`: the shutdown guard releases both locks and fails
			// the job. Why: docs-ai/11-code-rationale.md, "WorkerRunner: the shutdown guard".
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
				'resumes'     => $resumes,
				'generation'  => $generation,
			];

			// A job's start is a checkpoint of its process (`wp action-scheduler run` runs many): other processes'
			// changes are read again from here.
			\PerfLocale\Cache\CacheManager::refresh_if_changed();

			// Progress: items done and in total, and a detail: `stage` with `label` starts a stage (a `stage:<key>` log
			// line the Jobs page shows); `percent` is overall progress when the counts cover one stage.
			$result = $job->execute(
				$exec_args,
				static function ( int $processed, int $total, array $detail = [] ) use ( $job_id, $type, $type_locked, $lock_ttl, &$last_lock_refresh, $lock_refresh_window, &$last_stage, $resumes, $resume_meta, &$saw_checkpoint, $generation, &$superseded, &$last_unit ): void {
					// A job that resumes after a kill stores its per-unit checkpoint, stamped open, with the progress.
					$checkpoint = null;

					if ( $resumes && is_array( $detail['checkpoint'] ?? null ) ) {
						$last_unit  = is_string( $detail['unit'] ?? null ) ? $detail['unit'] : '';
						$checkpoint = JobResume::stamp( $detail['checkpoint'], $resume_meta, $last_unit );
					}

					$percent    = isset( $detail['percent'] ) ? (int) $detail['percent'] : -1;

					// The fenced write is the ownership check: a refused one is told apart by a narrow read.
					if ( ! JobState::update_progress( $job_id, $processed, $total, $percent, $checkpoint, $generation ) ) {
						$now = JobState::owner( $job_id );

						// The cancel sentinel, so a job's own cancel handling runs; the catch then writes nothing.
						if ( self::taken_over( $now, $generation ) ) {
							$superseded = true;
							throw new JobCanceledException( 'Job taken over by another run.' );
						}

						// An operator Cancel: the catch routes the sentinel to the canceled path (no retry, no error
						// log).
						if ( 'canceled' === ( $now['status'] ?? null ) ) {
							throw new JobCanceledException( 'Job canceled mid-flight.' );
						}

						// A checkpoint is stored before its unit runs: written once more, then the run stops (retried
						// from the last one).
						if ( null !== $checkpoint && ! JobState::update_progress( $job_id, $processed, $total, $percent, $checkpoint, $generation ) ) {
							throw new \RuntimeException( esc_html__( 'The progress of this job could not be saved (the database refused the update), so the job was stopped. Retry it to run it again.', 'perflocale' ) );
						}
					}

					$stage = isset( $detail['stage'] ) ? sanitize_key( (string) $detail['stage'] ) : '';

					if ( $stage !== '' && $stage !== $last_stage ) {
						$label = isset( $detail['label'] ) ? (string) $detail['label'] : '';
						JobState::append_log( $job_id, $label !== '' ? $label : $stage, 'stage:' . $stage );
						$last_stage = $stage;
					}

					// Pause: the catch re-queues by the fresh row status. A job that reports checkpoints is paused at
					// one, once stored.
					if ( ( null !== $checkpoint || ! $saw_checkpoint ) && WorkerRegistry::queue_is_paused() ) {
						JobState::append_log( $job_id, __( 'Queue paused mid-flight; worker exiting cooperatively.', 'perflocale' ) );
						throw new JobCanceledException( 'Queue paused mid-flight.' );
					}

					$saw_checkpoint = $saw_checkpoint || null !== $checkpoint;

					// Refresh the lock every TTL/4 seconds by default, not per tick: thousands of ticks cost one write
					// per interval.
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

			// A run that did nothing but fail (`run_failed`, reason in `first_error`) is recorded as failed with its
			// result, offering Retry, and never re-run automatically: every item failed once and was paid for.
			$run_failed_reason = is_array( $result ) && ! empty( $result['run_failed'] )
				? trim( (string) ( $result['__perflocale_failed_reason'] ?? $result['first_error'] ?? '' ) )
				: '';

			// Each end below is a fenced write: a run another run took over changes nothing.
			if ( $job->supports_continuation() && ! empty( $result['__perflocale_continue'] ) ) {
				// The slice stopped before the last item: the row stays running with the counts, and the job is
				// scheduled again once they are stored and both locks released. Completion and its hook come once,
				// after the last slice.
				$unit_stop = ! empty( $result['__perflocale_unit_stop'] );
				unset( $result['__perflocale_continue'], $result['__perflocale_unit_stop'] );

				$continue_at = self::continue_at( $result );
				$slice_meta  = $resume_meta;

				// A unit cut by the provider deadline counts like a kill there.
				if ( $resumes && $unit_stop ) {
					$slice_meta = JobResume::count_stop( $job_id, $type, $result, $resume_meta, is_int( $result['processed'] ?? null ) ? $result['processed'] : 0, $last_unit, $generation );
				}

				if ( null !== $slice_meta ) {
					if ( $resumes ) {
						$result = JobResume::close( $result, $slice_meta, $continue_at );
					}

					$continue_job = self::store_checkpoint( $job_id, $type, $result, $generation );
				}
			} elseif ( $run_failed_reason !== '' ) {
				$failed = JobState::mark_failed( $job_id, self::redact_paths( $run_failed_reason ), $generation );
				$stored = $failed ? null : JobState::owner( $job_id );

				if ( ! $failed && self::taken_over( $stored, $generation ) ) {
					self::log_taken_over( $job_id, $stored );
				} else {
					if ( $resumes ) {
						JobResume::unschedule( $job_id );
					}

					// A result that is a checkpoint is stored closed, so Retry continues from it.
					$checkpoint = $resumes && ! empty( $result['__perflocale_checkpoint'] );
					unset( $result['__perflocale_checkpoint'], $result['__perflocale_failed_reason'] );

					if ( $checkpoint ) {
						unset( $result['run_failed'] );
						$result = JobResume::close( $result, $resume_meta );
					}

					JobState::record_result( $job_id, $result, $generation );
				}

				if ( $failed ) {
					/** This action is documented in src/Background/WorkerRunner.php */
					do_action( 'perflocale/jobs/failed', $job_id, $type, new \RuntimeException( $run_failed_reason ) );

					// Not retried automatically: a one-time job records the failure.
					OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
				}
			} elseif ( JobState::mark_complete( $job_id, is_array( $result ) ? $result : [], $generation ) ) {
				if ( $resumes ) {
					JobResume::unschedule( $job_id );
				}

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
				// The row did not move to complete, so the hook must not say it did: report what is stored. Never throw
				// here, or the catch below would fail finished work and schedule a re-run.
				$stored        = JobState::owner( $job_id );
				$stored_status = $stored['status'] ?? null;

				if ( self::taken_over( $stored, $generation ) ) {
					self::log_taken_over( $job_id, $stored );
				} elseif ( 'canceled' === $stored_status ) {
					self::report_late_cancel( $job_id, $type, $result );
				} elseif ( 'running' === $stored_status ) {
					// The status write failed: the row stays running until the hourly watchdog fails it as stalled.
					JobState::append_log(
						$job_id,
						__( 'The work finished, but the job could not be marked complete (the database refused the status update).', 'perflocale' )
					);
				}
			}
		} catch ( JobCanceledException $e ) {
			// The sentinel means cancel (row already 'canceled') or pause (still 'running'): a paused worker is
			// re-queued as at the pause gate, or the job would strand running with no event until the 6 h watchdog
			// fails it.
			$fresh_state = JobState::get_fresh( $job_id );

			if ( $superseded || self::taken_over( $fresh_state, $generation ) ) {
				self::log_taken_over( $job_id, $fresh_state );
			} elseif ( ! $fresh_state || (string) $fresh_state['status'] === 'canceled' ) {
				JobState::append_log(
					$job_id,
					__( 'Worker aborted by operator cancel.', 'perflocale' )
				);

				// The counts the job reached, when it attached them; a resumable job's checkpoint is kept for Retry,
				// replaced by the one the job attached at the cancel (closed).
				$keep_checkpoint = $resumes && is_array( $fresh_state['result'] ?? null ) && is_array( $fresh_state['result'][ JobState::RESUME_KEY ] ?? null );
				$canceled_at     = $e->get_result();
				$new_checkpoint  = $resumes && ! empty( $canceled_at['__perflocale_checkpoint'] );
				unset( $canceled_at['__perflocale_checkpoint'] );

				if ( $new_checkpoint ) {
					JobState::record_result( $job_id, JobResume::close( $canceled_at, $resume_meta ), $generation );
				} elseif ( ! $keep_checkpoint ) {
					JobState::record_result( $job_id, $canceled_at, $generation );
				}

				/**
				 * Fires when a worker stopped for a cancel: not an error, so apart from `perflocale/jobs/failed` for
				 * monitoring.
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

				$recheck = time() + (int) apply_filters( 'perflocale/jobs/pause_recheck_seconds', 300 );

				if ( $resumes ) {
					// A pause is not a kill: the slice is closed, and the event replaces the dead-man.
					$paused = $fresh_state['result'] ?? null;
					$paused = is_array( $paused ) ? $paused : [];
					$kept = JobState::store_resume( $job_id, JobResume::close( JobResume::split( $paused )[0], $resume_meta ), false, $generation );
					$now  = $kept ? null : JobState::owner( $job_id );

					if ( ! $kept && self::taken_over( $now, $generation ) ) {
						self::log_taken_over( $job_id, $now );
					} else {
						JobResume::reschedule( $job_id, Dispatcher::worker_hook( $type ), $worker_args, $recheck );
					}
				} else {
					self::schedule_recording_engine(
						$recheck,
						Dispatcher::worker_hook( $type ),
						WorkerRegistry::with_blog_sentinel( $args, $target_blog ),
						$job_id
					);
				}
			}
		} catch ( \Throwable $e ) {
			// The stored message has absolute paths redacted (supervisors see it on the Jobs page and REST); the hook
			// still gets the original exception.
			if ( ! JobState::mark_failed( $job_id, self::redact_paths( $e->getMessage() ), $generation ) ) {
				$ended = JobState::owner( $job_id );

				if ( ( null === $ended && $generation >= 0 ) || self::taken_over( $ended, $generation ) ) {
					self::log_taken_over( $job_id, $ended );
					return;
				}
			}

			/**
			 * Fires after a background job ends in an unrecoverable error (or before its retry with backoff is
			 * scheduled).
			 *
			 * @hook perflocale/jobs/failed
			 * @param string     $job_id Identifier of the failed job.
			 * @param string     $type   Job type slug.
			 * @param \Throwable $e      The thrown exception.
			 */
			do_action( 'perflocale/jobs/failed', $job_id, $type, $e );

			// The retry event replaces the dead-man (WP-Cron refuses an identical event within 10 minutes).
			if ( $resumes ) {
				JobResume::unschedule( $job_id );
			}

			self::retry_or_record_failure( $job_id, $type, $args, $target_blog, $generation );

		} finally {
			// A run's end is a batch boundary: other processes' checkpoints see the entries it dropped, before its
			// locks go.
			\PerfLocale\Cache\CacheManager::announce_changes();

			if ( ! empty( $switched_locale ) && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
			wp_set_current_user( $prev_user );
			JobLock::release( $job_id );
			// Release by the flag captured at acquisition, not the filter again, which could answer differently and
			// leak it.
			if ( $type_locked ) {
				JobLock::release_type( $type );
			}
			self::$in_execute = $outer_execute;

			// One job at a time, in order: after any end of this run but a continuing or waiting job, the next starts.
			if ( $type_locked && ! $continue_job && ! $waiting && $job->starts_next_when_done() ) {
				self::start_next( $type, $target_blog, $job_id );
			}
		}

		// After `finally`: a continuation that ran while this worker held the job lock would take the busy path and
		// wait lock_ttl/4.
		if ( $continue_job ) {
			if ( $resumes ) {
				JobResume::reschedule( $job_id, Dispatcher::worker_hook( $type ), $worker_args, $continue_at );
			} else {
				self::schedule_continuation( $job_id, $type, $args, $target_blog, $continue_at );
			}
		}
	}

	/**
	 * Move the one event of the type's oldest job that never ran to now, once the type lock is free; a requeued or
	 * retried job keeps its own event, so a retry keeps its backoff.
	 *
	 * @param string $type        Job type slug.
	 * @param int    $target_blog Blog ID to re-inject as sentinel (0 on single-site).
	 * @param string $except      The job that just ran.
	 * @return void
	 */
	private static function start_next( string $type, int $target_blog, string $except ): void {
		$next = JobState::oldest_queued( $type, $except );

		if ( null === $next || ! is_string( $next['id'] ?? null ) || ! is_string( $next['hook'] ?? null ) ) {
			return;
		}

		$args = is_array( $next['args'] ?? null ) ? JobResume::keyed( $next['args'] ) : [];

		JobResume::reschedule( $next['id'], $next['hook'], WorkerRegistry::with_blog_sentinel( $args, $target_blog ), time(), is_string( $next['engine'] ?? null ) ? $next['engine'] : '' );
	}

	/**
	 * A row's stored result as a job resumes from it: without the watchdog's reschedule count.
	 *
	 * @param array<string, mixed>|null $row Fresh row.
	 * @return array<mixed>
	 */
	private static function stored_result( ?array $row ): array {
		$result = is_array( $row['result'] ?? null ) ? $row['result'] : [];
		unset( $result[ JobState::REQUEUES_KEY ] );

		return $result;
	}

	/**
	 * This run's generation: the row's `attempts` once claimed, which every later claim raises; -1 when unread.
	 *
	 * @param string $job_id Job identifier.
	 * @return int
	 */
	private static function generation( string $job_id ): int {
		return JobState::owner( $job_id )['attempts'] ?? -1;
	}

	/**
	 * Whether another run owns the job now: another claim raised its attempts (whatever its status since), or the row
	 * left `running` (Retry after a cancel, the watchdog). A cancel of this run is not: its own path handles it.
	 *
	 * @param array<string, mixed>|null $row        Fresh row.
	 * @param int                       $generation This run's generation (-1: unknown, never taken over).
	 * @return bool
	 */
	private static function taken_over( ?array $row, int $generation ): bool {
		if ( $generation < 0 || null === $row ) {
			return false;
		}

		return ( $row['attempts'] ?? null ) !== $generation || ! in_array( $row['status'] ?? null, [ 'running', 'canceled' ], true );
	}

	/**
	 * Note a run that stopped because another run owns its job (a row that already ended is not noted).
	 *
	 * @param string                    $job_id Job identifier.
	 * @param array<string, mixed>|null $row    Fresh row.
	 * @return void
	 */
	private static function log_taken_over( string $job_id, ?array $row ): void {
		if ( ! in_array( $row['status'] ?? null, [ 'queued', 'running' ], true ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the job's row belongs to the other run.
		error_log( '[PerfLocale] Job ' . $job_id . ': another run of this job took over, so this run stopped without writing.' );
	}

	/**
	 * Store the counts a slice stopped at and say whether the next slice may be scheduled (only after the write lands).
	 * A refused write is tried once more after a fresh read, a cancel found wins, and a second refusal fails the job;
	 * a row in any other state (failed by the watchdog, gone) is left as it is.
	 *
	 * @param string               $job_id Job identifier.
	 * @param string               $type   Job type slug.
	 * @param array<string, mixed> $result     The counts the slice stopped at.
	 * @param int                  $generation This run's generation.
	 * @return bool Whether the counts are stored and the next slice may run.
	 */
	private static function store_checkpoint( string $job_id, string $type, array $result, int $generation ): bool {
		for ( $write = 1; $write <= 2; $write++ ) {
			$stored = JobState::owner( $job_id );
			$status = $stored['status'] ?? null;

			if ( self::taken_over( $stored, $generation ) ) {
				self::log_taken_over( $job_id, $stored );
				return false;
			}

			if ( 'canceled' === $status ) {
				self::report_late_cancel( $job_id, $type, $result );
				return false;
			}

			if ( 'running' !== $status ) {
				return false;
			}

			if ( JobState::record_result( $job_id, $result, $generation ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the jobs table refused the same write twice.
		error_log( '[PerfLocale] Job ' . $job_id . ': the database refused twice to store the progress of a slice, so the job was stopped and marked failed.' );

		$reason = __( 'The progress of this job could not be saved (the database refused the update), so the job was stopped. Retry it to run it again.', 'perflocale' );

		if ( JobState::mark_failed( $job_id, $reason, $generation ) ) {
			/** This action is documented in src/Background/WorkerRunner.php */
			do_action( 'perflocale/jobs/failed', $job_id, $type, new \RuntimeException( $reason ) );

			// Not retried automatically: a one-time job records the failure.
			OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
		}

		return false;
	}

	/**
	 * Pause before the next web slice on Action Scheduler: it starts a batch of up to 30 s about every 60 s, so with a
	 * 60 s pause at least every other run is other plugins'.
	 */
	private const AS_SLICE_PAUSE = 60;

	/**
	 * When the next slice runs: the time a slice asked for (`__perflocale_continue_at`, e.g. a provider's
	 * Retry-After), taken out of its result, within the next hour; else at once (AS_SLICE_PAUSE later on Action
	 * Scheduler outside WP-CLI).
	 *
	 * @param array<string, mixed> $result The slice's result (the key is removed).
	 * @return int Unix time.
	 */
	private static function continue_at( array &$result ): int {
		$at = $result['__perflocale_continue_at'] ?? 0;
		unset( $result['__perflocale_continue_at'] );

		$now  = time();
		$next = $now;

		if ( 'cli' !== PHP_SAPI && 'action_scheduler' === JobRunnerFactory::pick()->get_engine_name() ) {
			$next = $now + self::AS_SLICE_PAUSE;
		}

		return is_int( $at ) ? min( max( $at, $next ), $now + HOUR_IN_SECONDS ) : $next;
	}

	/**
	 * Schedule the next slice with the dispatch args (earlier busy counters dropped), so the event has the shape the
	 * runners' cancel and is_scheduled() probes and the Resumer look for, and no deferral backoff carries over.
	 *
	 * @param string               $job_id      Job identifier.
	 * @param string               $type        Job type slug.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID to re-inject as sentinel (0 on single-site).
	 * @param int                  $at          Unix time it is due (continue_at()).
	 * @return void
	 */
	private static function schedule_continuation( string $job_id, string $type, array $args, int $target_blog, int $at ): void {
		unset( $args['__perflocale_type_busy_count'], $args['__perflocale_lock_busy_count'] );

		self::schedule_recording_engine(
			$at,
			Dispatcher::worker_hook( $type ),
			WorkerRegistry::with_blog_sentinel( $args, $target_blog ),
			$job_id
		);
	}

	/**
	 * Record an operator cancel that landed after the job's last progress tick: cancel wins as at a tick (no result
	 * recorded, the queued continuation chunk canceled, `perflocale/jobs/canceled` fired).
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
	 * Whether this process is inside execute() of the job.
	 *
	 * @internal For ActionSchedulerFailureBridge.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public static function is_executing( string $job_id ): bool {
		return null !== self::$in_execute && self::$in_execute['job_id'] === $job_id;
	}

	/**
	 * Shutdown function: release the locks of a job whose execute() never returned (a fatal error or an exit) and fail
	 * it, on its blog. A no-op otherwise; also run from release_before_error_page(), the record cleared on the first
	 * run. Why: docs-ai/11-code-rationale.md, "WorkerRunner: the shutdown guard".
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
			$error   = error_get_last();
			$message = is_array( $error ) && in_array( $error['type'], self::FATAL_ERROR_TYPES, true )
				? self::redact_paths( $error['message'] )
				: '';

			// The row fails before the locks go, so a queued duplicate cannot resume it in between. A job that
			// resumes after a kill stays running: its dead-man event continues it from its checkpoint.
			try {
				if ( ! empty( $record['resumes'] ) ) {
					JobState::append_log(
						$record['job_id'],
						'' !== $message
							? $message
							/* translators: Stored as the error of a background job whose PHP process ended while the job was running, without a PHP error. */
							: __( 'The worker stopped before finishing.', 'perflocale' )
					);
				} else {
					self::fail_for_good(
						$record['job_id'],
						$record['type'],
						'' !== $message
							? $message
							/* translators: Stored as the error of a background job whose PHP process ended while the job was running, without a PHP error. */
							: __( 'The worker stopped before finishing.', 'perflocale' ),
						$record['generation'] ?? -1
					);
				}
			} finally {
				JobLock::release( $record['job_id'] );

				if ( $record['type_locked'] ) {
					JobLock::release_type( $record['type'] );
				}
			}
		} finally {
			if ( $switched_blog ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * `wp_php_error_args`: run the shutdown guard before the fatal error page's wp_die(), which ends the process when
	 * its handler exits (WP-CLI). The arguments return unchanged; a failure here never stops the page.
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
	 * After a run that threw: schedule the automatic retry when one is left. A final failure (no retry left, the reset
	 * or the event refused) is recorded in a one-time job's dispatch state (OneTimeDispatch::job_ended()).
	 *
	 * @param string               $job_id      Job identifier.
	 * @param string               $type        Job type slug.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID to re-inject as sentinel (0 on single-site).
	 * @param int                  $generation  The failed run's generation (-1: any).
	 * @return void
	 */
	private static function retry_or_record_failure( string $job_id, string $type, array $args, int $target_blog, int $generation = -1 ): void {
		if ( 'failed' === self::schedule_retry_if_eligible( $job_id, $type, $args, $target_blog, $generation ) ) {
			OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
		}
	}

	/**
	 * Mark a job failed for good (no retry follows) and, when this call made the transition, record it for a one-time
	 * job (OneTimeDispatch::job_ended(); no query for other types). A cancel or completion that won records nothing.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $type   Job type slug.
	 * @param string $error      Error stored on the row.
	 * @param int    $generation The failing run's generation (-1: any).
	 * @return void
	 */
	private static function fail_for_good( string $job_id, string $type, string $error, int $generation = -1 ): void {
		if ( JobState::mark_failed( $job_id, $error, $generation ) ) {
			OneTimeDispatch::job_ended( $job_id, $type, 'failed' );
		}
	}

	/**
	 * Re-schedule the job with exponential backoff below the attempt cap (mark_running() counts the attempts).
	 *
	 * @param string               $job_id      Job identifier.
	 * @param string               $type        Job type slug.
	 * @param array<string, mixed> $args        Worker args (sentinel stripped).
	 * @param int                  $target_blog Blog ID to re-inject as sentinel (0 on single-site).
	 * @param int                  $generation  The failed run's generation: the reset lands only on its row (-1: any).
	 * @return string 'retry' when a retry is scheduled; 'failed' when the row stays failed with no retry (none left,
	 *                the reset or the event refused); 'none' when the row is gone or in another state (a cancel or a
	 *                completion won, another process owns it).
	 */
	private static function schedule_retry_if_eligible( string $job_id, string $type, array $args, int $target_blog, int $generation = -1 ): string {
		$state = JobState::get( $job_id );

		if ( ! $state ) {
			return 'none';
		}

		// Never resurrect a canceled or completed job (an exception racing an operator cancel); 'failed' is the state
		// this path recovers from.
		$current_status = (string) ( $state['status'] ?? '' );
		if ( $current_status === 'canceled' || $current_status === 'complete' ) {
			return 'none';
		}

		$attempts = (int) ( $state['attempts'] ?? 0 );

		/**
		 * Most runs of a job that throws, the first included.
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
		 * Filter the retry delay in seconds; gets the attempt count for a custom backoff curve.
		 *
		 * @hook perflocale/jobs/retry_delay
		 * @param int $delay    Default = exponential, capped at 1 hour.
		 * @param int $attempts How many attempts have already run.
		 */
		$delay = (int) apply_filters( 'perflocale/jobs/retry_delay', $delay, $attempts );
		$delay = max( 1, $delay );

		// The row is reset first: an event for a row not queued stops at the worker's terminal-status gate.
		if ( ! JobState::reset_for_retry( $job_id, false, $generation ) ) {
			$fresh = JobState::owner( $job_id );

			return null !== $fresh && 'failed' === $fresh['status'] && ( $generation < 0 || $fresh['attempts'] === $generation ) ? 'failed' : 'none';
		}

		try {
			self::schedule_recording_engine(
				time() + $delay,
				Dispatcher::worker_hook( $type ),
				WorkerRegistry::with_blog_sentinel( $args, $target_blog ),
				$job_id
			);
		} catch ( \Throwable $e ) {
			// The runner refused the event: the row goes back to failed (retryable) with the runner's error, unless a
			// worker took it meanwhile, instead of waiting for the stuck-job sweep.
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
	 * Cancel the chunk a canceled chained job (SiteTranslateJob) queued before the worker recorded the cancel: the
	 * result's `next_job`, when it is another job of the type and `next_duplicate` is false. As the REST cancel (row,
	 * event, job lock; the type lock left to its owner); never throws, so the caller's catch cannot schedule a retry.
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
	 * Re-schedule a worker and record the engine it landed on: the current runner can differ from the job's (engine
	 * setting changed, Action Scheduler gone or back), and the watchdog probes the recorded one. As the Resumer does.
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
	 * Redact absolute paths from a message stored on a job row: ABSPATH -> `<wp>/`, WP_CONTENT_DIR -> `<content>/`,
	 * other absolute paths ending in a file extension -> `<path>/file.ext` (PathRedactor, shared with the add-on boot
	 * log).
	 *
	 * @param string $message Raw exception/error message.
	 * @return string Redacted message, never longer than the input.
	 */
	private static function redact_paths( string $message ): string {
		return \PerfLocale\Util\PathRedactor::redact( $message );
	}
}
