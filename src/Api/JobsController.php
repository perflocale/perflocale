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
 * A retry also requires the capability of the job's type and, when someone
 * other than the dispatcher retries, every right the dispatcher holds: the
 * worker runs the job as the dispatcher.
 */
final class JobsController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'jobs';

	/**
	 * Per-request memo of JobState rows keyed by BLOG ID + job id. Populated by
	 * the permission_callback so the matching handler doesn't re-issue the same
	 * DB read. WP_REST_Server reuses the same controller instance for a
	 * permission_callback + callback pair within one REST dispatch, so this
	 * memo's lifetime exactly matches what we need. Callers that must observe
	 * post-mutation state (cancel_job / retry_job after their write) call
	 * JobState::get() directly to bypass the stale entry.
	 *
	 * The blog id is part of the key because the jobs table is per-blog while
	 * this memo is not, and a controller instance can outlive a
	 * switch_to_blog() in a CLI or worker context rather than a normal REST
	 * dispatch.
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
		$key = ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $id;

		if ( array_key_exists( $key, $this->job_state_memo ) ) {
			return $this->job_state_memo[ $key ];
		}

		$this->job_state_memo[ $key ] = JobState::get( $id );
		return $this->job_state_memo[ $key ];
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
		// reads a few small fields per row, so pulling the LONGTEXT columns
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
				'stage'      => (string) ( $row['stage'] ?? '' ),
				'processed'  => (int) ( $row['processed'] ?? 0 ),
				'total'      => (int) ( $row['total'] ?? 0 ),
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

		// Record the cancel BEFORE removing the worker event or the locks. If
		// the write does not land, nothing else is touched: the job keeps its
		// event and its worker keeps its lock. An event that fires after the
		// write finds a canceled row and stands down.
		if ( ! JobState::cancel( $id ) ) {
			$latest = JobState::get( $id );
			$status = $latest ? (string) $latest['status'] : '';

			// A concurrent cancel already landed: finish the cleanup below.
			if ( 'canceled' !== $status ) {
				if ( JobState::is_terminal( $status ) ) {
					return new \WP_Error(
						'rest_invalid_state',
						__( 'Job is already in a terminal state and cannot be canceled.', 'perflocale' ),
						[ 'status' => 409 ]
					);
				}

				return new \WP_Error(
					'perflocale_job_cancel_failed',
					__( 'Could not cancel the job: the database refused the status change. Nothing was changed; try again.', 'perflocale' ),
					[ 'status' => 500 ]
				);
			}
		}

		JobRunnerFactory::for_engine( (string) ( $state['engine'] ?? '' ) )->cancel( $id );

		// Release the locks held by the (now-canceled) worker. The per-JOB
		// lock is keyed by this job id, so it is dropped unconditionally even
		// though the acquiring request was a different one. The per-TYPE lock
		// is shared by every job of the type: a blind delete here could free a
		// lock another worker is legitimately holding and let two same-type
		// workers run at once, so it is only released when this request owns
		// it. Otherwise it clears when the canceled worker notices the
		// cancellation in its `finally`, or when its TTL
		// (JobLock::DEFAULT_TTL, 30 min) lapses. Both calls are idempotent.
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
		// "queued → failed" round trip via the runner. The retrying user
		// must hold the same capability on this site.
		$type       = (string) $state['type'];
		$created_by = (int) ( $state['created_by'] ?? 0 );
		$factory    = \PerfLocale\Background\WorkerRegistry::factory_for_type( $type );
		if ( is_callable( $factory ) ) {
			try {
				$probe_job = $factory();
				if ( $probe_job instanceof \PerfLocale\Background\AbstractJob ) {
					$required_cap = $probe_job->get_required_capability();

					if ( $created_by > 0 && ! user_can( $created_by, $required_cap ) ) {
						return new \WP_Error(
							'rest_forbidden',
							__( 'Original dispatcher no longer holds the capability for this job.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}

					if ( ! current_user_can( $required_cap ) ) {
						return new \WP_Error(
							'rest_forbidden',
							__( 'You do not have permission to run this job.', 'perflocale' ),
							[ 'status' => 403 ]
						);
					}
				}
			} catch ( \Throwable $e ) {
				// Factory blew up; let the worker handle it and mark failed.
				// Falls through to the rights check and the enqueue below.
				unset( $e );
			}
		}

		// The worker runs the job as its dispatcher, so a retry by another
		// user must not hand the job rights that user does not hold.
		if ( ! $this->holds_dispatcher_rights( $created_by ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'This job runs with the permissions of the user who started it, and that user has permissions you do not have. Start the job again yourself instead.', 'perflocale' ),
				[ 'status' => 403 ]
			);
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
		try {
			$runner->enqueue(
				(string) $state['hook'],
				$worker_args,
				$id
			);
		} catch ( \Throwable $e ) {
			// The row is `queued` with no worker event behind it. Put it back
			// to `failed` (retryable at once) rather than leave it for the
			// watchdog. Never delete it (it is the operator's history) and
			// never re-enqueue here.
			$message = sprintf(
				/* translators: %s is the runner's error message. */
				__( 'Failed to enqueue background job: %s', 'perflocale' ),
				\PerfLocale\Util\PathRedactor::redact( $e->getMessage() )
			);
			JobState::fail_queued( $id, $message );

			return new \WP_Error( 'perflocale_job_enqueue_failed', $message, [ 'status' => 500 ] );
		}
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
	 * Whether the current user holds every right of a job's dispatcher on
	 * this site.
	 *
	 * The worker runs a job as its dispatcher (`created_by`). A user who
	 * retries someone else's job may do so only when that identity gives the
	 * job nothing the retrying user lacks: on a network, super admin rights
	 * need a super admin; everywhere, each capability the dispatcher holds
	 * must be held by the retrying user. Role names are not compared (an
	 * administrator does not hold the `editor` role capability), and a
	 * capability the dispatcher holds in the role but not in effect (a site
	 * administrator's `unfiltered_html` on a network) is skipped. A job with
	 * no dispatcher, or one whose dispatcher no longer exists, runs as nobody:
	 * the worker refuses it.
	 *
	 * @param int $created_by The job's dispatcher.
	 * @return bool
	 */
	private function holds_dispatcher_rights( int $created_by ): bool {
		$current = (int) get_current_user_id();

		if ( $created_by <= 0 || $created_by === $current ) {
			return true;
		}

		$dispatcher = get_userdata( $created_by );

		if ( ! $dispatcher instanceof \WP_User ) {
			return true;
		}

		if ( is_multisite() && is_super_admin( $created_by ) && ! is_super_admin( $current ) ) {
			return false;
		}

		$roles = wp_roles();

		foreach ( (array) $dispatcher->allcaps as $cap => $granted ) {
			$cap = (string) $cap;

			if ( ! $granted || is_numeric( $cap ) || $roles->is_role( $cap ) || current_user_can( $cap ) ) {
				continue;
			}

			if ( $dispatcher->has_cap( $cap ) ) {
				return false;
			}
		}

		return true;
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

		// `result` can name a file: for a data_export job it is
		// `[ 'path' => …, 'bytes' => … ]`. The cap that got the caller this
		// far is not the one that may download that file
		// (`perflocale_import_export`), so `path` is gated on the DOWNLOAD
		// capability, not on $show_args. Keyed on the field name rather than
		// the job type so an addon job that returns a path is covered too.
		// Everything else in `result` (`bytes`, counts) stays visible, so the
		// Jobs UI still shows that the job finished and how large the output
		// was.
		$result = (array) ( $state['result'] ?? [] );

		if ( isset( $result['path'] ) && ! current_user_can( 'perflocale_import_export' ) ) {
			unset( $result['path'] );
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
			'result'           => $result,
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
