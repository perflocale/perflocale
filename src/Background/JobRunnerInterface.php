<?php
/**
 * Background job runner contract.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstraction over WP-Cron and Action Scheduler.
 *
 * Two implementations:
 *   - {@see WpCronRunner}            — always available, uses wp_schedule_* primitives
 *   - {@see ActionSchedulerRunner}   — used when Action Scheduler is loaded (≥ 3.4)
 *
 * {@see JobRunnerFactory::pick()} returns the right one at runtime based on:
 *   1. The `perflocale/jobs/runner` filter (force override)
 *   2. `background_engine` setting (force_wp_cron / auto)
 *   3. Whether AS is loaded and at a compatible version
 *
 * Workers receive callbacks as `function ( string $job_id, array $args )`
 * regardless of which runner enqueued them — both runners normalise the
 * argument shape before calling the worker hook.
 */
interface JobRunnerInterface {

	/**
	 * Enqueue a one-shot async action for the runner to execute as soon
	 * as it picks it up (cron tick / AS claim).
	 *
	 * @param string               $hook   The WordPress action hook the
	 *                                     worker is listening on.
	 *                                     Convention: `perflocale_job_run_<type>`.
	 * @param array<string, mixed> $args   Worker args. MUST be JSON-serialisable
	 *                                     (use IDs / scalars; never resources or
	 *                                     closures). AS forbids non-serialisable
	 *                                     args; WP-Cron tolerates more but we
	 *                                     enforce the same contract for portability.
	 * @param string               $job_id PerfLocale-owned job identifier (UUID v4).
	 * @return string  Same job_id as the input (returned for fluent chaining).
	 */
	public function enqueue( string $hook, array $args, string $job_id ): string;

	/**
	 * Schedule an action to run at a specific UTC timestamp.
	 *
	 * @param int                  $timestamp Unix UTC seconds.
	 * @param string               $hook      Action hook name.
	 * @param array<string, mixed> $args      Worker args.
	 * @param string               $job_id    Job identifier.
	 * @return string  Same job_id as the input.
	 */
	public function schedule( int $timestamp, string $hook, array $args, string $job_id ): string;

	/**
	 * Cancel any pending action for {@param string $job_id}. Does NOT
	 * interrupt a job already running — the worker's lock-TTL handles that.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True if a pending action was found and cancelled; false otherwise.
	 */
	public function cancel( string $job_id ): bool;

	/**
	 * Whether {@param string $job_id} has a pending (not-yet-run) scheduled action.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public function is_scheduled( string $job_id ): bool;

	/**
	 * Identifier used by the Jobs admin UI to label which engine is running
	 * the job. One of: 'action_scheduler' | 'wp_cron'.
	 *
	 * @return string
	 */
	public function get_engine_name(): string;
}
