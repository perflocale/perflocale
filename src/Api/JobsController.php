<?php
/**
 * REST endpoints for the background-jobs system.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Background\JobRunnerFactory;
use PerfLocale\Background\JobState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints (namespace `perflocale/v1`):
 *
 *   GET    /jobs                List active jobs (active index + per-job status).
 *   GET    /jobs/{id}           Full detail for one job.
 *   POST   /jobs/{id}/cancel    Mark canceled + unschedule any pending action.
 *   POST   /jobs/{id}/retry     Reset to queued and re-enqueue with same args.
 *   DELETE /jobs/{id}           Hard-delete job state (only for completed/failed/canceled).
 *
 * All routes require the dispatching user's cap OR the supervisor cap.
 * Defaults to `perflocale_translate` (the broadest read perm); cancel /
 * retry / delete additionally require the dispatching user to be the
 * current user OR the current user to have `perflocale_manage_translations`.
 */
final class JobsController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'jobs';

	/**
	 * Per-request memo of JobState rows keyed by job id. Populated by the
	 * permission_callback so the matching handler doesn't re-issue the same
	 * DB read. WP_REST_Server reuses the same controller instance for a
	 * permission_callback + callback pair within one REST dispatch, so this
	 * memo's lifetime exactly matches what we need. Callers that must observe
	 * post-mutation state (cancel_job / retry_job after their write) call
	 * JobState::get() directly to bypass the stale entry.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $job_state_memo = [];

	/**
	 * Memoised JobState::get(). Returns the row the permission_callback
	 * already resolved; on first miss reads through to JobState::get().
	 *
	 * @param string $id Job UUID.
	 * @return array<string, mixed>|null
	 */
	private function get_job_state( string $id ): ?array {
		if ( array_key_exists( $id, $this->job_state_memo ) ) {
			return $this->job_state_memo[ $id ];
		}

		$this->job_state_memo[ $id ] = JobState::get( $id );
		return $this->job_state_memo[ $id ];
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_jobs' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9-]{8,64})',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_job' ],
					'permission_callback' => [ $this, 'read_job_permissions_check' ],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_job' ],
					'permission_callback' => [ $this, 'mutate_job_permissions_check' ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9-]{8,64})/cancel',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'cancel_job' ],
				'permission_callback' => [ $this, 'mutate_job_permissions_check' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[a-z0-9-]{8,64})/retry',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'retry_job' ],
				'permission_callback' => [ $this, 'mutate_job_permissions_check' ],
			]
		);
	}

	/**
	 * GET /jobs — list active jobs (newest first).
	 *
	 * Returns the bounded active-index — never the full per-job payload,
	 * which would be expensive to serialize on every poll. Clients fetch
	 * `/jobs/{id}` for the row they want to expand.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_jobs(): \WP_REST_Response {
		$jobs        = [];
		$is_super    = current_user_can( 'perflocale_manage_translations' );
		$current_uid = (int) get_current_user_id();

		// list_active_summary() omits args/result/log — this endpoint only
		// reads 5 small fields per row, so pulling the LONGTEXT columns
		// every 5 s on the polling client is pure wasted bytes-on-wire.
		foreach ( JobState::list_active_summary() as $job_id => $row ) {
			// Non-supervisors only see jobs they dispatched. `created_by` is
			// already hydrated on every $row by list_active() (it runs SELECT *
			// through the same hydrate() that JobState::get() uses), so the
			// previous per-row JobState::get() re-fetch was a pure N+1.
			if ( ! $is_super && (int) ( $row['created_by'] ?? 0 ) !== $current_uid ) {
				continue;
			}

			$jobs[] = [
				'id'         => $job_id,
				'type'       => (string) ( $row['type'] ?? '' ),
				'status'     => (string) ( $row['status'] ?? '' ),
				'progress'   => (int) ( $row['progress'] ?? 0 ),
				'updated_at' => (int) ( $row['updated_at'] ?? 0 ),
			];
		}

		return rest_ensure_response(
			[
				'jobs'   => $jobs,
				'engine' => JobRunnerFactory::pick()->get_engine_name(),
			]
		);
	}

	/**
	 * GET /jobs/{id} — full detail for one job.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (string) $request->get_param( 'id' );

		$state = $this->get_job_state( $id );

		// Return 404 (not 403) when the user lacks access. 403 would leak
		// whether the ID exists at all; 404 keeps non-existent and
		// not-yours indistinguishable from the caller's perspective.
		if ( ! $state || ! $this->user_can_read( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $this->sanitize_state_for_response( $state ) );
	}

	/**
	 * POST /jobs/{id}/cancel — mark canceled + unschedule.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = (string) $request->get_param( 'id' );
		$state = $this->get_job_state( $id );

		// 404 covers both "row missing" and "not yours" so the existence
		// of jobs you don't own isn't observable via the cancel endpoint.
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		// 409 on terminal status — distinct from 404 so the API client can
		// distinguish "already done, nothing to cancel" from "no such job".
		// Safe to disclose because the caller already proved ownership above.
		if ( ! in_array( (string) $state['status'], [ 'queued', 'running' ], true ) ) {
			return new \WP_Error(
				'rest_invalid_state',
				__( 'Job is already in a terminal state and cannot be canceled.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		JobRunnerFactory::for_engine( (string) ( $state['engine'] ?? '' ) )->cancel( $id );
		JobState::cancel( $id );

		// Release the locks held by the (now-canceled) worker. The per-JOB
		// lock is keyed by this job id, so it is dropped unconditionally even
		// though the acquiring request was a different one. The per-TYPE lock
		// is shared by every job of the type: a blind delete here could free a
		// lock another worker is legitimately holding and let two same-type
		// workers run at once, so it is only released when this request owns
		// it. Otherwise it clears when the canceled worker notices the
		// cancellation in its `finally`, or when its TTL
		// (JobLock::DEFAULT_TTL, 10 min) lapses. Both calls are idempotent.
		\PerfLocale\Background\JobLock::release( $id );
		\PerfLocale\Background\JobLock::release_type( (string) $state['type'] );

		$fresh = JobState::get( $id );
		return rest_ensure_response( $this->sanitize_state_for_response( $fresh ?? $state ) );
	}

	/**
	 * POST /jobs/{id}/retry — reset to queued and re-enqueue.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = (string) $request->get_param( 'id' );
		$state = $this->get_job_state( $id );

		// 404 covers "row missing" + "not yours" identically.
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		// Only retry-able from a terminal status.
		if ( ! in_array( (string) $state['status'], [ 'failed', 'canceled' ], true ) ) {
			return new \WP_Error(
				'rest_invalid_state',
				__( 'Only failed or canceled jobs can be retried.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		// Pre-flight cap re-check: the dispatcher's capability may have
		// been revoked since the original dispatch. The worker performs
		// the same check and marks failed if it fails, but doing it here
		// gives the operator immediate REST-level feedback instead of a
		// "queued → failed" round trip via the runner.
		$type    = (string) $state['type'];
		$factory = \PerfLocale\Background\WorkerRegistry::factory_for_type( $type );
		if ( is_callable( $factory ) ) {
			try {
				$probe_job  = $factory();
				$created_by = (int) ( $state['created_by'] ?? 0 );
				if ( $probe_job instanceof \PerfLocale\Background\AbstractJob
					&& $created_by > 0
					&& ! user_can( $created_by, $probe_job->get_required_capability() )
				) {
					return new \WP_Error(
						'rest_forbidden',
						__( 'Original dispatcher no longer holds the capability for this job.', 'perflocale' ),
						[ 'status' => 403 ]
					);
				}
			} catch ( \Throwable $e ) {
				// Factory blew up; let the worker handle it and mark failed.
				// Falls through to the enqueue below.
				unset( $e );
			}
		}

		// Re-inject the blog-id sentinel so the worker can switch_to_blog
		// before reading the per-blog JobState row. Without this, retries
		// of jobs dispatched from a non-main multisite site would orphan.
		$blog_id     = (int) ( $state['blog_id'] ?? 0 );
		$worker_args = \PerfLocale\Background\WorkerRegistry::with_blog_sentinel(
			(array) ( $state['args'] ?? [] ),
			$blog_id
		);

		if ( ! JobState::reset_for_retry( $id, true ) ) {
			return new \WP_Error(
				'perflocale_job_retry_failed',
				__( 'Could not reset the job for retry; its state changed concurrently.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		// Record which engine actually re-queued the job (mirrors
		// WorkerRegistry::schedule_recording_engine): if the operator switched
		// runner engines since the original enqueue, later cancel /
		// is_scheduled probes would otherwise target the WRONG store and
		// silently misfire.
		$runner = JobRunnerFactory::pick();
		$runner->enqueue(
			(string) $state['hook'],
			$worker_args,
			$id
		);
		JobState::set_engine( $id, $runner->get_engine_name() );

		$fresh = JobState::get( $id );
		return rest_ensure_response( $this->sanitize_state_for_response( $fresh ?? $state ) );
	}

	/**
	 * DELETE /jobs/{id} — hard-delete the job state row + active-index entry.
	 *
	 * Only allowed on jobs that have already finished (complete/failed/
	 * canceled). Running / queued jobs must be canceled first, so the
	 * runner has a chance to unschedule cleanly.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = (string) $request->get_param( 'id' );
		$state = $this->get_job_state( $id );

		// 404 covers "row missing" + "not yours" identically (security).
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		if ( ! in_array( (string) $state['status'], [ 'complete', 'failed', 'canceled' ], true ) ) {
			return new \WP_Error(
				'rest_invalid_state',
				__( 'Cancel the job before deleting it.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		JobState::delete( $id );

		return rest_ensure_response(
			[
				'deleted' => true,
				'id'      => $id,
			]
		);
	}

	/**
	 * Read permission — any user who can translate may see the queue.
	 *
	 * @return bool|\WP_Error
	 */
	public function read_permissions_check(): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_translate' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to view jobs.', 'perflocale' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Coarse mutate permission — finer per-job check happens in {@see user_can_mutate()}.
	 *
	 * @return bool|\WP_Error
	 */
	public function mutate_permissions_check(): bool|\WP_Error {
		if ( ! current_user_can( 'perflocale_translate' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to manage jobs.', 'perflocale' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Per-job READ permission. Routes the cap check + per-job ownership
	 * / supervisor check at the permission_callback layer so the request
	 * is rejected before reaching the handler.
	 *
	 * Returns 404 (not 403) when the user lacks per-job access, so the
	 * existence of a job ID owned by another translator isn't observable.
	 * The handler keeps its own check too — defense in depth, and so any
	 * internal caller still gets the gate.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function read_job_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->read_permissions_check();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$id = (string) $request->get_param( 'id' );
		if ( $id === '' ) {
			// Should never happen — the route regex enforces 8-64 chars —
			// but defensively reject malformed paths at the gate.
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		$state = $this->get_job_state( $id );
		if ( ! $state || ! $this->user_can_read( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		return true;
	}

	/**
	 * Per-job MUTATE permission (cancel / retry / delete). Same shape as
	 * {@see read_job_permissions_check()} but routes through user_can_mutate
	 * — currently identical to user_can_read, but kept as a separate hook
	 * so future tightening (e.g. supervisor-only delete) only changes one
	 * predicate.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function mutate_job_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->mutate_permissions_check();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$id = (string) $request->get_param( 'id' );
		if ( $id === '' ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		$state = $this->get_job_state( $id );
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		return true;
	}

	/**
	 * Per-job mutate permission. The current user must either be the
	 * dispatching user, or hold `perflocale_manage_translations`
	 * (supervisor cap).
	 *
	 * @param array<string, mixed> $state Job state row.
	 * @return bool
	 */
	private function user_can_mutate( array $state ): bool {
		$created_by = (int) ( $state['created_by'] ?? 0 );
		$current    = (int) get_current_user_id();

		if ( $created_by > 0 && $current === $created_by ) {
			return true;
		}

		return current_user_can( 'perflocale_manage_translations' );
	}

	/**
	 * Per-job read permission. Same scoping as {@see user_can_mutate()}:
	 * the dispatcher always sees their own row; everyone else needs the
	 * supervisor cap. Prevents one translator from observing another's
	 * job IDs, types, error messages, or log buffers.
	 *
	 * @param array<string, mixed> $state Job state row.
	 * @return bool
	 */
	private function user_can_read( array $state ): bool {
		return $this->user_can_mutate( $state );
	}

	/**
	 * Strip / coerce per-job state into a JSON-safe response shape.
	 *
	 * Specifically:
	 *   - Don't echo raw `args` to non-supervisors (could leak post IDs,
	 *     file paths). Show a redacted summary.
	 *   - Coerce all numeric fields to int.
	 *   - Pass the log ring buffer through as-is — already capped by JobState.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function sanitize_state_for_response( array $state ): array {
		// Args carry potentially-sensitive content (file paths, IDs).
		// Show them to the supervisor cap (so the admin can debug any
		// job) AND to the original dispatching user (so they can see
		// what their own job is doing). Every other translator sees a
		// sentinel — they know args exist but not their contents.
		$created_by = (int) ( $state['created_by'] ?? 0 );
		$current    = (int) get_current_user_id();
		$show_args  = ( $created_by > 0 && $current === $created_by )
			|| current_user_can( 'perflocale_manage_translations' );

		// Label for the dispatcher cell in the Jobs admin UI. `created_by`
		// may be 0 (GDPR anonymisation zeroed it), may point to a user
		// that no longer exists (admin deleted them without remap), or
		// may be a regular live user — in which case surface their
		// display_name (or user_login as fallback) so the UI shows
		// something human instead of a bare numeric ID.
		$created_by_label = null;
		if ( $created_by === 0 ) {
			$created_by_label = __( '(anonymized)', 'perflocale' );
		} else {
			$user = get_userdata( $created_by );
			if ( false === $user ) {
				$created_by_label = __( '(deleted user)', 'perflocale' );
			} else {
				$display          = trim( (string) $user->display_name );
				$created_by_label = $display !== '' ? $display : (string) $user->user_login;
			}
		}

		return [
			'id'               => (string) ( $state['id'] ?? '' ),
			'type'             => (string) ( $state['type'] ?? '' ),
			'engine'           => (string) ( $state['engine'] ?? '' ),
			'status'           => (string) ( $state['status'] ?? '' ),
			'created_at'       => (int) ( $state['created_at'] ?? 0 ),
			'started_at'       => (int) ( $state['started_at'] ?? 0 ),
			'completed_at'     => (int) ( $state['completed_at'] ?? 0 ),
			'progress'         => (int) ( $state['progress'] ?? 0 ),
			'total'            => (int) ( $state['total'] ?? 0 ),
			'processed'        => (int) ( $state['processed'] ?? 0 ),
			'attempts'         => (int) ( $state['attempts'] ?? 0 ),
			'error'            => (string) ( $state['error'] ?? '' ),
			'result'           => (array) ( $state['result'] ?? [] ),
			'log'              => array_values( (array) ( $state['log'] ?? [] ) ),
			'created_by'       => (int) ( $state['created_by'] ?? 0 ),
			'created_by_label' => $created_by_label,
			'blog_id'          => (int) ( $state['blog_id'] ?? 0 ),
			// Only supervisors / dispatcher see raw args; everyone else gets
			// a sentinel so the UI knows args exist without leaking values.
			'args'             => $show_args ? (array) ( $state['args'] ?? [] ) : null,
			'args_redacted'    => $show_args ? null : __( '(redacted — supervisors only)', 'perflocale' ),
		];
	}
}
