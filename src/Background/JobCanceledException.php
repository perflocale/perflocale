<?php
/**
 * Sentinel exception used to break out of a running worker when the
 * operator cancels the job mid-flight.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by the progress callback {@see WorkerRegistry::run()} hands to
 * each job's `execute()`. Distinct from {@see \Throwable} so the worker's
 * catch block can route cancellation through the {@see JobState::cancel()}
 * path instead of {@see JobState::mark_failed()} + retry-with-backoff.
 *
 * Long-running jobs that periodically call `$progress($processed, $total)`
 * get the cancel signal for free — the next progress tick throws. Jobs
 * that don't call progress (sub-second work) finish naturally; the
 * cancellation arrives too late to interrupt them, which is fine.
 *
 * A job may attach the counts it reached before the cancel with
 * set_result(); the worker stores them on the canceled job.
 */
final class JobCanceledException extends \RuntimeException {

	/**
	 * Partial result attached by the job, if any.
	 *
	 * @var array<string, mixed>
	 */
	private array $result = [];

	/**
	 * Attach the job's partial result.
	 *
	 * @param array<string, mixed> $result Counts reached before the cancel.
	 * @return void
	 */
	public function set_result( array $result ): void {
		$this->result = $result;
	}

	/**
	 * The partial result attached by the job ([] when none).
	 *
	 * @return array<string, mixed>
	 */
	public function get_result(): array {
		return $this->result;
	}
}
