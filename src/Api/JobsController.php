<?php
/**
 * REST endpoints of background jobs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Background\JobAccess;
use PerfLocale\Background\JobRunnerFactory;
use PerfLocale\Background\JobState;
use PerfLocale\Background\OneTimeDispatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `perflocale/v1`: GET /jobs, GET /jobs/{id}, POST /jobs/{id}/cancel, POST /jobs/{id}/retry, DELETE /jobs/{id}
 * (finished jobs only). Every route needs `perflocale_translate`; one job follows JobAccess::may_handle(). A retry
 * by another user also needs the job's capability and makes them its owner, so the worker runs with their rights.
 */
final class JobsController extends RestController {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'jobs';

	/**
	 * JobState rows by blog id + job id, so a permission_callback and its handler share one read. The server reuses
	 * this instance per dispatch, so each permission check reads afresh and each handler that changes the row drops it;
	 * the blog id is in the key because the instance can outlive a switch_to_blog().
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $job_state_memo = [];

	/**
	 * Memo key of a job on the current blog.
	 *
	 * @param string $id Job UUID.
	 * @return string
	 */
	private function job_state_key( string $id ): string {
		return ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $id;
	}

	/**
	 * JobState::get() through the memo: the row this dispatch's permission_callback read, else a fresh read.
	 *
	 * @param string $id Job UUID.
	 * @return array<string, mixed>|null
	 */
	private function get_job_state( string $id ): ?array {
		$key = $this->job_state_key( $id );

		if ( array_key_exists( $key, $this->job_state_memo ) ) {
			return $this->job_state_memo[ $key ];
		}

		return $this->load_job_state( $id );
	}

	/**
	 * Read the row from JobState and keep it for this dispatch's handler.
	 *
	 * @param string $id Job UUID.
	 * @return array<string, mixed>|null
	 */
	private function load_job_state( string $id ): ?array {
		$key = $this->job_state_key( $id );

		$this->job_state_memo[ $key ] = JobState::get( $id );
		return $this->job_state_memo[ $key ];
	}

	/**
	 * Drop the kept row after a handler changed it.
	 *
	 * @param string $id Job UUID.
	 * @return void
	 */
	private function forget_job_state( string $id ): void {
		unset( $this->job_state_memo[ $this->job_state_key( $id ) ] );
	}

	/**
	 * The job id in the route's URL ('' for none); not get_param(), which prefers a body or query `id`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function url_id( \WP_REST_Request $request ): string {
		$id = $request->get_url_params()['id'] ?? '';
		return is_string( $id ) ? $id : '';
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
	 * GET /jobs: the active jobs, newest first, as summaries; `/jobs/{id}` gives one job's detail.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_jobs(): \WP_REST_Response {
		$jobs        = [];
		$current_uid = (int) get_current_user_id();
		$allowed     = [ [], [] ];

		// Summary rows: no args, result or log columns on a client polling every 5 s.
		foreach ( JobState::list_active_summary() as $job_id => $row ) {
			// JobAccess per type and owner kind, asked once each; status fields only, so no content check.
			$type = is_string( $row['type'] ?? null ) ? $row['type'] : '';
			$mine = $current_uid > 0 && (int) ( $row['created_by'] ?? 0 ) === $current_uid ? 1 : 0;

			$allowed[ $mine ][ $type ] ??= $mine ? JobAccess::may_handle_own( $type ) : JobAccess::may_handle_others( $type );

			if ( ! $allowed[ $mine ][ $type ] ) {
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
	 * GET /jobs/{id}: one job's detail.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = $this->url_id( $request );

		$state = $this->get_job_state( $id );

		// 404, not 403: whether another user's job exists stays hidden.
		if ( ! $state || ! $this->user_can_read( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $this->sanitize_state_for_response( $state ) );
	}

	/**
	 * POST /jobs/{id}/cancel: mark canceled and unschedule.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = $this->url_id( $request );
		$state = $this->get_job_state( $id );

		// 404 for missing and not yours alike.
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		// 409 for a finished job, distinct from 404; safe after the access check.
		if ( ! in_array( (string) $state['status'], [ 'queued', 'running' ], true ) ) {
			return new \WP_Error(
				'rest_invalid_state',
				__( 'Job is already in a terminal state and cannot be canceled.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		// The cancel is recorded before the event and locks go: if the write fails nothing else changes, and an event
		// firing after it finds a canceled row.
		$canceled = JobState::cancel( $id );
		$this->forget_job_state( $id );

		if ( ! $canceled ) {
			$latest = JobState::get( $id );
			$status = $latest ? (string) $latest['status'] : '';

			// A concurrent cancel landed: finish the cleanup.
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

		// A one-time job's dispatch state records the cancel.
		OneTimeDispatch::job_ended( $id, is_string( $state['type'] ?? null ) ? $state['type'] : '', 'canceled' );

		JobRunnerFactory::for_engine( (string) ( $state['engine'] ?? '' ) )->cancel( $id );

		// The job lock names this job only, so it is dropped although another request holds it. The type lock is shared:
		// released only when this request owns it, else by the worker's finally or its TTL.
		\PerfLocale\Background\JobLock::release( $id );
		\PerfLocale\Background\JobLock::release_type( (string) $state['type'] );

		$fresh = JobState::get( $id );
		return rest_ensure_response( $this->sanitize_state_for_response( $fresh ?? $state ) );
	}

	/**
	 * POST /jobs/{id}/retry: reset to queued and enqueue again.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = $this->url_id( $request );
		$state = $this->get_job_state( $id );

		// 404 for missing and not yours alike.
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		// Only failed or canceled jobs.
		if ( ! in_array( (string) $state['status'], [ 'failed', 'canceled' ], true ) ) {
			return new \WP_Error(
				'rest_invalid_state',
				__( 'Only failed or canceled jobs can be retried.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		// The retrying user needs the job's capability; another user's retry makes them the owner, so the worker runs it
		// with their rights (it runs as `created_by`).
		$type    = (string) $state['type'];
		$current = (int) get_current_user_id();
		$owner   = (int) ( $state['created_by'] ?? 0 ) === $current ? -1 : $current;
		$factory = \PerfLocale\Background\WorkerRegistry::factory_for_type( $type );

		// A type whose worker is not registered here (stock jobs) is checked against JobAccess's capability for it.
		$required_cap = JobAccess::type_capability( $type );

		if ( is_callable( $factory ) ) {
			try {
				$probe_job = $factory();
				if ( $probe_job instanceof \PerfLocale\Background\AbstractJob ) {
					$required_cap = $probe_job->get_required_capability();
				}
			} catch ( \Throwable $e ) {
				// The factory failed; the worker marks the job failed.
				unset( $e );
				$required_cap = '';
			}
		}

		if ( '' !== $required_cap && ! current_user_can( $required_cap ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to run this job.', 'perflocale' ),
				[ 'status' => 403 ]
			);
		}

		// The blog-id sentinel, so the worker reads the right blog's row.
		$blog_id     = (int) ( $state['blog_id'] ?? 0 );
		$worker_args = \PerfLocale\Background\WorkerRegistry::with_blog_sentinel(
			(array) ( $state['args'] ?? [] ),
			$blog_id
		);

		// Only the row version checked above is reset: a racing retry gets the 409 below.
		$version = $state['version'] ?? null;
		$reset   = is_int( $version ) && JobState::reset_for_retry( $id, true, -1, $owner, $version );
		$this->forget_job_state( $id );

		if ( ! $reset ) {
			return new \WP_Error(
				'perflocale_job_retry_failed',
				__( 'Could not reset the job for retry; its state changed concurrently.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		if ( $owner > 0 ) {
			JobState::append_log(
				$id,
				/* translators: %d: user ID. */
				sprintf( __( 'Retried by user %d; the job now runs with that user\'s permissions.', 'perflocale' ), $owner )
			);
		}

		// Store the engine that queued it (as WorkerRunner::schedule_recording_engine()), so cancel and is_scheduled
		// probe the right store.
		$runner = JobRunnerFactory::pick();
		try {
			$runner->enqueue(
				(string) $state['hook'],
				$worker_args,
				$id
			);
		} catch ( \Throwable $e ) {
			// Queued with no event behind it: back to failed (retryable at once), never deleted, never enqueued here.
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
	 * DELETE /jobs/{id}: delete the row of a finished job (complete, failed, canceled); cancel a live one first.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_job( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = $this->url_id( $request );
		$state = $this->get_job_state( $id );

		// 404 for missing and not yours alike.
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		$deleted = JobState::delete_finished( $id );
		$this->forget_job_state( $id );

		if ( ! $deleted ) {
			return new \WP_Error(
				'rest_invalid_state',
				__( 'Cancel the job before deleting it.', 'perflocale' ),
				[ 'status' => 409 ]
			);
		}

		return rest_ensure_response(
			[
				'deleted' => true,
				'id'      => $id,
			]
		);
	}

	/**
	 * Read permission: any user who can translate sees the queue.
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
	 * Coarse mutate permission; the per-job check is user_can_mutate().
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
	 * Per-job read permission at the permission_callback, 404 when denied so another user's job id is not observable.
	 * The handler checks again.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function read_job_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->read_permissions_check();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$id = $this->url_id( $request );
		if ( $id === '' ) {
			// The route regex already enforces 8-64 characters.
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		$state = $this->load_job_state( $id );
		if ( ! $state || ! $this->user_can_read( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		return true;
	}

	/**
	 * Per-job mutate permission (cancel, retry, delete), as read_job_permissions_check() through user_can_mutate(),
	 * a separate predicate so a stricter rule changes one place.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function mutate_job_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->mutate_permissions_check();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$id = $this->url_id( $request );
		if ( $id === '' ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		$state = $this->load_job_state( $id );
		if ( ! $state || ! $this->user_can_mutate( $state ) ) {
			return new \WP_Error( 'rest_not_found', __( 'Job not found.', 'perflocale' ), [ 'status' => 404 ] );
		}

		return true;
	}

	/**
	 * Per-job mutate permission (cancel, retry, delete): JobAccess::may_handle().
	 *
	 * @param array<string, mixed> $state Job state row.
	 * @return bool
	 */
	private function user_can_mutate( array $state ): bool {
		return JobAccess::may_handle( $state );
	}

	/**
	 * Per-job read permission: JobAccess::may_handle(), as {@see user_can_mutate()}.
	 *
	 * @param array<string, mixed> $state Job state row.
	 * @return bool
	 */
	private function user_can_read( array $state ): bool {
		return $this->user_can_mutate( $state );
	}

	/**
	 * Per request: whether a job type's worker runs without a user.
	 *
	 * @var array<string, bool>
	 */
	private static array $without_user = [];

	/**
	 * Whether this request's worker of $type runs without a user: its created_by 0 rows are system jobs.
	 *
	 * @param string $type Job type.
	 * @return bool
	 */
	private static function runs_without_user( string $type ): bool {
		if ( ! isset( self::$without_user[ $type ] ) ) {
			$factory                     = \PerfLocale\Background\WorkerRegistry::factory_for_type( $type );
			$job                         = is_callable( $factory ) ? $factory() : null;
			self::$without_user[ $type ] = $job instanceof \PerfLocale\Background\AbstractJob && $job->runs_without_user();
		}

		return self::$without_user[ $type ];
	}

	/**
	 * The job row as a JSON-safe response: numbers as ints, args only for those who may see them, the log as stored.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function sanitize_state_for_response( array $state ): array {
		// Args can name files and ids: shown to the dispatcher and to a supervisor with the type's capability (JobAccess).
		$created_by = (int) ( $state['created_by'] ?? 0 );
		$current    = (int) get_current_user_id();
		$show_args  = ( $created_by > 0 && $current === $created_by )
			|| JobAccess::may_handle_others( is_string( $state['type'] ?? null ) ? $state['type'] : '' );

		// Dispatcher label: created_by 0 (a system job, or anonymized), a deleted user, or a user's display name.
		$created_by_label = null;
		if ( $created_by === 0 ) {
			$created_by_label = self::runs_without_user( is_string( $state['type'] ?? null ) ? $state['type'] : '' )
				/* translators: Shown as who started a background job that runs without a user (e.g. auto-translate on publish). */
				? __( 'System', 'perflocale' )
				: __( '(anonymized)', 'perflocale' );
		} else {
			$user = get_userdata( $created_by );
			if ( false === $user ) {
				$created_by_label = __( '(deleted user)', 'perflocale' );
			} else {
				$display          = trim( (string) $user->display_name );
				$created_by_label = $display !== '' ? $display : (string) $user->user_login;
			}
		}

		// A `path` in the result (data_export) needs the download capability (`perflocale_import_export`), whatever the job
		// type; the other result fields stay visible.
		$result = (array) ( $state['result'] ?? [] );
		unset( $result[ JobState::REQUEUES_KEY ] );

		// A bulk translation's `cut`: the pair its next slice redoes, internal.
		if ( 'bulk_translate' === ( $state['type'] ?? null ) ) {
			unset( $result['cut'] );
		}

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
			// Others get a sentinel: args exist, values hidden.
			'args'             => $show_args ? (array) ( $state['args'] ?? [] ) : null,
			'args_redacted'    => $show_args ? null : __( '(redacted - supervisors only)', 'perflocale' ),
		];
	}
}
