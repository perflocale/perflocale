<?php
/**
 * Job dispatch entry point.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single entry point for heavy work: runs a job inline or enqueues it for a background worker.
 *
 * Result `mode` and its keys:
 *   - 'sync':   `result`, or `error` when execute() threw.
 *   - 'async':  `job_id`; `duplicate`, `status` and `updated_at` when an identical job was joined; or `replaced`.
 *   - 'denied': `error` (missing capability or a should_dispatch veto).
 *   - 'error':  `error` (args too large or too deep, no job id, or the runner failed).
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class Dispatcher {

	/**
	 * Compose the worker hook name from a job type slug (shared with WorkerRegistry).
	 *
	 * @param string $type Job type slug.
	 * @return string Hook name, e.g. 'perflocale_job_run_data_import'.
	 */
	public static function worker_hook( string $type ): string {
		return 'perflocale_job_run_' . $type;
	}

	/**
	 * Dispatch the given job.
	 *
	 * @param AbstractJob          $job  Job descriptor.
	 * @param array<string, mixed> $args Worker args.
	 * @return array{mode: string, job_id?: string, duplicate?: bool, status?: string, updated_at?: int, replaced?: string, result?: array<string,mixed>, error?: string}
	 */
	public static function dispatch( AbstractJob $job, array $args ): array {
		// The worker re-checks as the stored `created_by`; failing here returns a clean error without enqueueing.
		if ( ! current_user_can( $job->get_required_capability() ) ) {
			return [
				'mode'  => 'denied',
				'error' => __( 'You do not have permission to run this job.', 'perflocale' ),
			];
		}

		/**
		 * Veto a job dispatch before it runs.
		 *
		 * False vetoes it (mode 'denied'); a string vetoes it with that message. Distinct from
		 * `perflocale/jobs/threshold/<type>`, which only picks sync or async.
		 *
		 * @hook  perflocale/jobs/should_dispatch
		 * @since 1.0.0
		 *
		 * @param bool                 $proceed Default true.
		 * @param AbstractJob          $job     The job instance about to dispatch.
		 * @param array<string, mixed> $args    The dispatch args.
		 * @return bool|string Return false to veto; return a string to veto
		 *                     with a custom error message.
		 */
		$proceed = apply_filters( 'perflocale/jobs/should_dispatch', true, $job, $args );

		if ( $proceed === false || is_string( $proceed ) ) {
			return [
				'mode'  => 'denied',
				'error' => is_string( $proceed ) && $proceed !== ''
					? $proceed
					: __( 'Job dispatch vetoed by filter.', 'perflocale' ),
			];
		}

		$settings = self::settings();

		if ( ! $settings instanceof Settings || ! $job->should_run_async( $args, $settings ) ) {
			return self::execute_inline( $job, $args );
		}

		return self::enqueue( $job, $args );
	}

	/**
	 * Force-enqueue a job for async execution, bypassing the sync/async decision.
	 *
	 * An identical job still queued or running on this blog is returned instead (`duplicate`), unless its worker is gone
	 * ({@see JobState::fail_if_worker_gone()}): then that job is marked failed and the result names it in `replaced`.
	 *
	 * @param AbstractJob          $job  Job descriptor.
	 * @param array<string, mixed> $args Worker args.
	 * @return array{mode: string, job_id: string, duplicate?: bool, status?: string, updated_at?: int, replaced?: string}|array{mode: string, error: string}
	 */
	/**
	 * Maximum JSON size of `$args` for an async dispatch, so the job row does not become a data sink.
	 *
	 * Filterable via `perflocale/jobs/max_args_bytes`.
	 */
	public const MAX_ARGS_BYTES = 102400;

	public static function enqueue( AbstractJob $job, array $args ): array {
		if ( ! current_user_can( $job->get_required_capability() ) ) {
			return [
				'mode'  => 'denied',
				'error' => __( 'You do not have permission to run this job.', 'perflocale' ),
			];
		}

		// The args are stored in the job row, which must not grow by megabytes per call.
		$encoded = wp_json_encode( $args );
		$limit   = (int) apply_filters( 'perflocale/jobs/max_args_bytes', self::MAX_ARGS_BYTES );

		if ( $encoded === false || strlen( $encoded ) > max( 1024, $limit ) ) {
			return [
				'mode'  => 'error',
				'error' => sprintf(
					/* translators: %d: maximum allowed args size in bytes */
					__( 'Job args exceed the maximum allowed size (%d bytes).', 'perflocale' ),
					$limit
				),
			];
		}

		// Deep nesting fits the size cap but can trip xdebug.max_nesting_level and slows every encode and decode.
		$max_depth = (int) apply_filters( 'perflocale/jobs/max_args_depth', 20 );
		if ( self::array_depth( $args ) > max( 4, $max_depth ) ) {
			return [
				'mode'  => 'error',
				'error' => sprintf(
					/* translators: %d: maximum allowed args nesting depth */
					__( 'Job args exceed the maximum allowed nesting depth (%d).', 'perflocale' ),
					$max_depth
				),
			];
		}

		// Idempotent admission: an identical dispatch (double click, retried REST call) joins the in-flight job; with a
		// raised max_concurrent both would run and pay the provider twice. Scoped to type + args + blog, never global.
		/**
		 * Filter whether an identical in-flight job blocks a new dispatch.
		 *
		 * Return false to admit identical jobs side by side.
		 *
		 * @hook perflocale/jobs/deduplicate_admission
		 * @param bool        $enabled Default true.
		 * @param string      $type    Job type slug.
		 * @param array       $args    Worker args.
		 */
		$dedupe = (bool) apply_filters( 'perflocale/jobs/deduplicate_admission', true, $job->get_type(), $args );

		// Check-then-insert under a short lock, so two identical dispatches in the same second cannot miss each other.
		$admission = $dedupe ? self::acquire_admission( $job->get_type(), $args ) : '';
		$replaced  = '';

		try {
			if ( $dedupe ) {
				$in_flight = JobState::find_active_duplicate( $job->get_type(), $args );

				// A twin whose worker was killed is failed, not joined: nothing would ever run it.
				if ( $in_flight !== null && JobState::fail_if_worker_gone( $in_flight ) ) {
					$replaced  = $in_flight;
					$in_flight = null;
				}

				if ( $in_flight !== null ) {
					JobState::append_log(
						$in_flight,
						__( 'An identical dispatch arrived while this job was still in flight; it was folded into this job instead of starting a second one.', 'perflocale' )
					);

					$twin = JobState::get( $in_flight );

					return [
						'mode'       => 'async',
						'job_id'     => $in_flight,
						'duplicate'  => true,
						'status'     => (string) ( $twin['status'] ?? 'queued' ),
						'updated_at' => (int) ( $twin['updated_at'] ?? 0 ),
					];
				}
			}

			$runner = JobRunnerFactory::pick();
			$hook   = self::worker_hook( $job->get_type() );

			// INSERT IGNORE on the UNIQUE uuid fails on a collision: three fresh UUIDs, then a hard error, never a reused row.
			$job_id  = '';
			$created = false;
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$job_id = wp_generate_uuid4();
				if ( JobState::create(
					$job_id,
					$job->get_type(),
					$args,
					(int) get_current_user_id(),
					$hook,
					$runner->get_engine_name()
				) ) {
					$created = true;
					break;
				}
			}
		} finally {
			if ( $admission !== '' ) {
				JobLock::release_type( $admission );
			}
		}

		if ( ! $created ) {
			return [
				'mode'  => 'error',
				'error' => __( 'Could not allocate a unique job id after 3 attempts; please retry the operation.', 'perflocale' ),
			];
		}

		// The worker switches to this blog before reading its job row; single site adds no sentinel.
		$blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$worker_args = WorkerRegistry::with_blog_sentinel( $args, $blog_id );

		// A runner failure would leave a queued row with no worker event until the stuck sweep: delete it and report now.
		try {
			$runner->enqueue( $hook, $worker_args, $job_id );
		} catch ( \Throwable $e ) {
			JobState::delete( $job_id );
			return [
				'mode'  => 'error',
				'error' => sprintf(
					/* translators: %s is the runner's error message. */
					__( 'Failed to enqueue background job: %s', 'perflocale' ),
					$e->getMessage()
				),
			];
		}

		// With DISABLE_WP_CRON the WP-Cron runner needs an external cron, which cannot be probed: say so in the job log.
		if ( $runner->get_engine_name() === 'wp_cron'
			&& defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
		) {
			JobState::append_log(
				$job_id,
				__( 'DISABLE_WP_CRON is set - this job needs an external cron trigger (system cron hitting wp-cron.php) to run.', 'perflocale' )
			);
		}

		/**
		 * Fires after a background job has been enqueued.
		 *
		 * @hook perflocale/jobs/enqueued
		 * @param string $job_id   Identifier of the newly-queued job.
		 * @param string $type     Job type slug.
		 * @param string $engine   Runner engine ('action_scheduler' | 'wp_cron').
		 * @param array  $args     Worker args.
		 */
		do_action( 'perflocale/jobs/enqueued', $job_id, $job->get_type(), $runner->get_engine_name(), $args );

		if ( $replaced !== '' ) {
			JobState::append_log(
				$job_id,
				sprintf(
					/* translators: %s: id of the earlier job */
					__( 'An identical job (%s) had stopped without finishing; it was marked failed and this job replaces it.', 'perflocale' ),
					$replaced
				)
			);

			return [
				'mode'     => 'async',
				'job_id'   => $job_id,
				'replaced' => $replaced,
			];
		}

		return [
			'mode'   => 'async',
			'job_id' => $job_id,
		];
	}

	/**
	 * Take the short lock that makes admission of one operation atomic.
	 *
	 * Keyed by type, args and blog. Waits up to five seconds; a dead holder's lock expires after 30 seconds; without
	 * the lock the dispatch goes ahead unserialised.
	 *
	 * @param string       $type Job type slug.
	 * @param array<mixed> $args Worker args.
	 * @return string The JobLock type key to release, or '' when not taken.
	 */
	private static function acquire_admission( string $type, array $args ): string {
		$key = 'admit_' . md5( $type . '|' . (int) get_current_blog_id() . '|' . (string) wp_json_encode( $args ) );

		for ( $attempt = 0; $attempt < 25; $attempt++ ) {
			if ( JobLock::acquire_type( $key, 30 ) ) {
				return $key;
			}

			usleep( 200000 );
		}

		return '';
	}

	/**
	 * Run the job inline and capture the result; sync mode reports no progress.
	 *
	 * @param AbstractJob          $job  Job descriptor.
	 * @param array<string, mixed> $args Worker args.
	 * @return array{mode: string, result?: array<string,mixed>, error?: string}
	 */
	private static function execute_inline( AbstractJob $job, array $args ): array {
		// A closure, not an arrow fn: an arrow fn's expression body clashes with the void return type.
		$noop = static function ( int $processed, int $total ): void {};

		try {
			$result = $job->execute( $args, $noop );
			return [
				'mode'   => 'sync',
				'result' => $result,
			];
		} catch ( \Throwable $e ) {
			return [
				'mode'  => 'sync',
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Depth of the deepest nested array in `$value`, capped so the probe itself cannot exhaust the stack.
	 *
	 * @param mixed $value  Value to measure.
	 * @param int   $cap    Hard recursion ceiling.
	 * @return int Depth of the deepest nested array (1 for a flat array).
	 */
	private static function array_depth( $value, int $cap = 64 ): int {
		if ( ! is_array( $value ) || $cap <= 0 ) {
			return 0;
		}
		$max = 1;
		foreach ( $value as $v ) {
			if ( is_array( $v ) ) {
				$d = 1 + self::array_depth( $v, $cap - 1 );
				if ( $d > $max ) {
					$max = $d;
				}
			}
		}
		return $max;
	}

	/**
	 * Resolve the Settings service, or null when it is not registered yet (early bootstrap).
	 *
	 * @return Settings|null
	 */
	private static function settings(): ?Settings {
		try {
			$plugin = Plugin::get_instance();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! $plugin->has( 'settings' ) ) {
			return null;
		}

		$settings = $plugin->get( 'settings' );
		return $settings instanceof Settings ? $settings : null;
	}
}
