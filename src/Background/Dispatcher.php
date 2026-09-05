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
 * Single entry point that sync callers use instead of running heavy work
 * inline. Decides between inline execution and enqueueing for async.
 *
 * Returns a structured result so the caller knows what happened:
 *   - `['mode' => 'sync',   'result' => [...]]`     ran inline; here's the output
 *   - `['mode' => 'sync',   'error'  => '...']`     ran inline but threw
 *   - `['mode' => 'async',  'job_id' => '...']`     enqueued; track via Jobs page
 *   - `['mode' => 'denied', 'error'  => '...']`     user lacks the required cap
 *
 * Worker hook registration lives in {@see WorkerRegistry::register_hooks()};
 * each job type's `perflocale_job_run_<type>` action invokes the worker with
 * `(string $job_id, array $args)` and handles cap re-validation, locking,
 * retries, and state transitions.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class Dispatcher {

	/**
	 * Compose the worker hook name from a job type slug.
	 *
	 * Used by both Dispatcher (enqueue side) and WorkerRegistry (listener
	 * side) so they always agree on the hook name.
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
	 * @return array{mode: string, job_id?: string, duplicate?: bool, result?: array<string,mixed>, error?: string}
	 */
	public static function dispatch( AbstractJob $job, array $args ): array {
		// Capability check on the dispatch side. The worker hook re-runs
		// this check with stored `created_by`, but failing here lets us
		// surface a clean error to the caller without ever enqueueing.
		if ( ! current_user_can( $job->get_required_capability() ) ) {
			return [
				'mode'  => 'denied',
				'error' => __( 'You do not have permission to run this job.', 'perflocale' ),
			];
		}

		/**
		 * Veto a job dispatch before it runs.
		 *
		 * Returning `false` from this filter blocks the dispatch entirely:
		 * the job never runs (sync or async) and the caller gets back
		 * `[ 'mode' => 'denied', 'error' => ... ]`. Returning `true` (the
		 * default) lets the dispatch proceed.
		 *
		 * Useful for ops policies that the capability system can't express
		 * cleanly: deploy freezes ("no jobs after 5pm UTC"), per-tenant
		 * quotas, A/B rollout gates, monitoring-driven kill switches.
		 *
		 * Distinct from `perflocale/jobs/threshold/<type>` which only
		 * decides sync-vs-async — `should_dispatch` is the kill switch.
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
	 * Useful for retries from the Jobs admin page (the user explicitly asked
	 * to re-run it asynchronously) and for callers that know their workload
	 * is too big to run sync regardless of settings.
	 *
	 * @param AbstractJob          $job  Job descriptor.
	 * @param array<string, mixed> $args Worker args.
	 * @return array{mode: string, job_id: string, duplicate?: bool}|array{mode: string, error: string}
	 */
	/**
	 * Maximum serialised size of `$args` accepted by an async dispatch.
	 *
	 * Anything larger would bloat the `args` column of the JobState
	 * row. 100 KB comfortably covers every realistic job (an import job's
	 * args are paths + IDs, not the payload itself) while rejecting a
	 * hostile caller that tries to use the queue as a data sink.
	 *
	 * Filterable via `perflocale/jobs/max_args_bytes` for ops who genuinely
	 * need bigger payloads (e.g. a custom job that ships inline data).
	 */
	public const MAX_ARGS_BYTES = 102400;

	public static function enqueue( AbstractJob $job, array $args ): array {
		if ( ! current_user_can( $job->get_required_capability() ) ) {
			return [
				'mode'  => 'denied',
				'error' => __( 'You do not have permission to run this job.', 'perflocale' ),
			];
		}

		// Reject oversized args before they reach JobState::create — the
		// args array is persisted in the `args` LONGTEXT column of the
		// per-job row, and an attacker with dispatch permission could
		// otherwise stuff the jobs table with megabytes of garbage per call.
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

		// Reject deeply-nested arrays before serialisation. A 100 KB JSON
		// payload of 1000-level-nested arrays still fits the size cap but
		// can trip PHP's `xdebug.max_nesting_level` and slows the write
		// (JSON-encoding walks the full tree, and so does every later
		// decode on the worker side). Cap at a depth that covers any
		// legitimate job args shape.
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

		// Idempotent admission. Two identical dispatches - a double-clicked
		// admin button, a retried REST call, the same `--async` command run
		// twice - used to create two independent jobs for one logical
		// operation. The default per-type concurrency of 1 serialises them, so
		// on a stock install the second mostly re-walks work the first already
		// did; but an operator who raises
		// `perflocale/jobs/max_concurrent/{type}` to overlap same-type work
		// gets both running at once, and then the provider is called twice for
		// every string and paid for twice, with an unlinked draft left behind.
		//
		// Scoped to the exact operation (type + identical args + this blog),
		// never a global lock: different work of the same type still runs in
		// parallel exactly as before.
		/**
		 * Filter whether an identical in-flight job blocks a new dispatch.
		 *
		 * Return false to allow duplicate admission (the pre-1.0.2 behaviour).
		 *
		 * @hook perflocale/jobs/deduplicate_admission
		 * @param bool        $enabled Default true.
		 * @param string      $type    Job type slug.
		 * @param array       $args    Worker args.
		 */
		if ( (bool) apply_filters( 'perflocale/jobs/deduplicate_admission', true, $job->get_type(), $args ) ) {
			$in_flight = JobState::find_active_duplicate( $job->get_type(), $args );

			if ( $in_flight !== null ) {
				JobState::append_log(
					$in_flight,
					__( 'An identical dispatch arrived while this job was still in flight; it was folded into this job instead of starting a second one.', 'perflocale' )
				);

				return [
					'mode'      => 'async',
					'job_id'    => $in_flight,
					'duplicate' => true,
				];
			}
		}

		$runner = JobRunnerFactory::pick();
		$hook   = self::worker_hook( $job->get_type() );

		// JobState::create uses an atomic INSERT IGNORE against the jobs
		// table's UNIQUE uuid key and returns
		// false on the astronomically rare UUID collision. Retry up to
		// three times with fresh UUIDs before giving up — collisions
		// indicate either a broken entropy source or someone re-creating
		// the same job_id manually, both of which should surface as a
		// hard error rather than silently re-using an unrelated row.
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

		if ( ! $created ) {
			return [
				'mode'  => 'error',
				'error' => __( 'Could not allocate a unique job id after 3 attempts; please retry the operation.', 'perflocale' ),
			];
		}

		// Embed the dispatching blog id in worker args so the worker can
		// `switch_to_blog()` before reading the per-blog JobState row. On
		// single-site installs this is a no-op (blog_id 0 → sentinel not
		// added). The worker strips the sentinel before invoking the job.
		$blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$worker_args = WorkerRegistry::with_blog_sentinel( $args, $blog_id );

		// If the runner blows up before scheduling the worker event (AS
		// table corrupted, wp_cron option write failed, etc.), the
		// JobState row would survive with no worker event waiting — a
		// stuck queued row visible on the Jobs page until the GC's 6-hour
		// stuck-sweep marks it failed. That's a slow path; clean up at
		// the dispatch site instead so the failure is visible immediately
		// to the caller.
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

		// DISABLE_WP_CRON + the WP-Cron runner + no external cron = dispatched
		// jobs sit in `queued` until the watchdog flags them stuck ~6h later.
		// We can't probe for an external cron, so flag the combination on the
		// job log so the operator sees it instead of a "queued forever" mystery.
		if ( $runner->get_engine_name() === 'wp_cron'
			&& defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
		) {
			JobState::append_log(
				$job_id,
				__( 'DISABLE_WP_CRON is set — this job needs an external cron trigger (system cron hitting wp-cron.php) to run.', 'perflocale' )
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

		return [
			'mode'   => 'async',
			'job_id' => $job_id,
		];
	}

	/**
	 * Run the job inline and capture the result. The progress callback is
	 * a no-op in sync mode — sync callers expect a single result, not a
	 * progress stream.
	 *
	 * @param AbstractJob          $job
	 * @param array<string, mixed> $args
	 * @return array{mode: string, result?: array<string,mixed>, error?: string}
	 */
	private static function execute_inline( AbstractJob $job, array $args ): array {
		// Sync callers don't care about progress; pass a no-op closure.
		// `function () {}` rather than an arrow fn because the latter must
		// have an expression body, which collides with the void contract.
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
	 * Resolve the Settings service or return null if it's not registered
	 * yet (very early bootstrap).
	 *
	 * @return Settings|null
	 */
	/**
	 * Bounded recursion to measure the deepest nested-array path in
	 * `$args`. Caps at 64 to keep the probe itself from blowing PHP's
	 * stack — `array_depth(...) > $cap` short-circuits as soon as we
	 * exceed the threshold the caller cares about.
	 *
	 * @param mixed $value
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
