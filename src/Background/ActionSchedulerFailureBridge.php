<?php
/**
 * Action Scheduler failure states bridged to the jobs table.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles a failed Action Scheduler action with its job's row.
 *
 * Action Scheduler marks one of its actions failed when the action threw
 * (`action_scheduler_failed_execution`), when a fatal error ended the
 * process running it (`action_scheduler_unexpected_shutdown`) and when its
 * queue cleaner timed it out (`action_scheduler_failed_action`). AS marks
 * the action failed before it fires each of these. The job's row would stay
 * queued or running until the watchdog; this bridge fails it as soon as
 * nothing else carries the job.
 *
 * A failed action is not always the job's current attempt: the worker may
 * have reset the row and stored its retry before AS's own completion write
 * failed, a duplicate action may fail while another worker holds the job
 * lock, and the cleaner may time an action out while its worker, still
 * holding the lock, is between the reset and storing the retry. So:
 *
 * - The job this process is executing when a fatal error ends it fails at
 *   once, through the worker's own shutdown guard
 *   ({@see WorkerRunner::release_on_shutdown()}: its locks are released,
 *   the job is failed and a one-time job records the failure).
 * - Any other running row fails only when its worker is gone
 *   ({@see JobState::fail_if_worker_gone()}).
 * - A queued row fails only when no live lock is held for the job, no other
 *   action is scheduled or in progress for it, and the row still has the
 *   version read first. The row is read before the lock: a worker holds the
 *   lock from before its reset until after its retry is stored, and every
 *   status change or log line of a worker changes the version.
 *
 * The bridge never records a one-time job's end itself; the job's dispatch
 * check does that once the row is failed and no lock is held.
 *
 * AS's tables and queue runner are per blog, and each of these actions
 * fires in the blog that owns the action, where the job row also lives.
 *
 * @internal Hooked by Bootstrap at `init` when Action Scheduler is loaded.
 */
final class ActionSchedulerFailureBridge {

	/**
	 * `action_scheduler_failed_execution`: the action threw.
	 *
	 * @param mixed $action_id Action ID.
	 * @param mixed $exception The exception AS caught.
	 * @return void
	 */
	public static function on_failed_execution( $action_id, $exception = null ): void {
		$state = self::job_of( $action_id );

		if ( null !== $state ) {
			self::reconcile( $state, $exception instanceof \Throwable ? ': ' . $exception->getMessage() : '' );
		}
	}

	/**
	 * `action_scheduler_unexpected_shutdown`: a fatal error ended the
	 * process that ran the action.
	 *
	 * @param mixed $action_id Action ID.
	 * @param mixed $error     error_get_last() of the fatal error.
	 * @return void
	 */
	public static function on_unexpected_shutdown( $action_id, $error = null ): void {
		$state = self::job_of( $action_id );

		if ( null === $state ) {
			return;
		}

		// WorkerRunner is loaded whenever a job executes in this process.
		if ( class_exists( WorkerRunner::class, false ) && WorkerRunner::is_executing( $state['id'] ) ) {
			WorkerRunner::release_on_shutdown();
			return;
		}

		self::reconcile( $state, is_array( $error ) && is_string( $error['message'] ?? null ) ? ': ' . $error['message'] : '' );
	}

	/**
	 * `action_scheduler_failed_action`: AS's queue cleaner timed the
	 * action out.
	 *
	 * @param mixed $action_id Action ID.
	 * @return void
	 */
	public static function on_timed_out( $action_id ): void {
		$state = self::job_of( $action_id );

		if ( null !== $state ) {
			self::reconcile( $state, '' );
		}
	}

	/**
	 * Fail the job of a failed action when nothing else carries it.
	 *
	 * @param array{id: string, status: string, engine: string, version: int} $state  The job row, read before anything else.
	 * @param string                                                         $detail AS's error message starting with ": ", or ''.
	 * @return void
	 */
	private static function reconcile( array $state, string $detail ): void {
		$job_id = $state['id'];

		if ( 'running' === $state['status'] ) {
			JobState::fail_if_worker_gone( $job_id );
			return;
		}

		if ( JobLock::is_held_now( $job_id ) ) {
			return;
		}

		try {
			if ( JobRunnerFactory::for_engine( $state['engine'] )->is_scheduled( $job_id ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			return;
		}

		JobState::fail_queued(
			$job_id,
			/* translators: %s is an optional AS-supplied error message starting with ": ". */
			sprintf( __( '[AS marked failed] Worker terminated before completion%s', 'perflocale' ), $detail ),
			$state['version']
		);
	}

	/**
	 * The id, status, engine and version of the queued or running job of a
	 * failed action in the plugin's group, or null when the action is
	 * someone else's or its job is finished.
	 *
	 * @param mixed $action_id Action ID.
	 * @return array{id: string, status: string, engine: string, version: int}|null
	 */
	private static function job_of( $action_id ): ?array {
		if ( ! class_exists( '\\ActionScheduler' ) ) {
			return null;
		}

		try {
			$store  = \ActionScheduler::store();
			$action = is_object( $store ) && method_exists( $store, 'fetch_action' )
				? $store->fetch_action( is_numeric( $action_id ) ? (int) $action_id : 0 )
				: null;
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_object( $action ) || ! method_exists( $action, 'get_group' )
			|| $action->get_group() !== ActionSchedulerRunner::GROUP
		) {
			return null;
		}

		$as_args = method_exists( $action, 'get_args' ) ? $action->get_args() : [];
		$job_id  = is_array( $as_args ) && is_string( $as_args[0] ?? null ) ? $as_args[0] : '';

		if ( '' === $job_id || ! JobState::is_safe_id( $job_id ) ) {
			return null;
		}

		$state   = JobState::get( $job_id );
		$status  = $state['status'] ?? null;
		$engine  = $state['engine'] ?? null;
		$version = $state['version'] ?? null;

		if ( ( 'queued' !== $status && 'running' !== $status ) || ! is_int( $version ) ) {
			return null;
		}

		return [
			'id'      => $job_id,
			'status'  => $status,
			'engine'  => is_string( $engine ) ? $engine : '',
			'version' => $version,
		];
	}
}
