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
 * Job runner contract over WP-Cron (WpCronRunner) and Action Scheduler (ActionSchedulerRunner).
 *
 * JobRunnerFactory::pick() chooses one; both call the worker hook as `function ( string $job_id, array $args )`.
 */
interface JobRunnerInterface {

	/**
	 * Enqueue a one-shot action to run at the runner's next pick-up.
	 *
	 * @param string               $hook   Worker hook, `perflocale_job_run_<type>`.
	 * @param array<string, mixed> $args   Worker args: JSON-serialisable scalars and ids only (Action Scheduler requires it).
	 * @param string               $job_id Job id (UUID v4).
	 * @return string  The job id.
	 */
	public function enqueue( string $hook, array $args, string $job_id ): string;

	/**
	 * Schedule an action at a UTC timestamp.
	 *
	 * @param int                  $timestamp Unix UTC seconds.
	 * @param string               $hook      Action hook name.
	 * @param array<string, mixed> $args      Worker args.
	 * @param string               $job_id    Job identifier.
	 * @return string  Same job_id as the input.
	 */
	public function schedule( int $timestamp, string $hook, array $args, string $job_id ): string;

	/**
	 * Cancel the job's pending action; a running job is not interrupted (its lock TTL covers that).
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True if a pending action was found and cancelled; false otherwise.
	 */
	public function cancel( string $job_id ): bool;

	/**
	 * Whether the job has a pending scheduled action.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public function is_scheduled( string $job_id ): bool;

	/**
	 * Engine label for the Jobs screen: 'action_scheduler' or 'wp_cron'.
	 *
	 * @return string
	 */
	public function get_engine_name(): string;
}
