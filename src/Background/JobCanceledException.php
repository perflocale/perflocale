<?php
/**
 * Exception that stops a running worker when its job is canceled.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by the worker's progress callback (WorkerRunner::run()) once the job is canceled, taken over or paused, so
 * WorkerRunner's catch cancels, re-queues (pause) or logs the takeover instead of mark_failed() and a retry. A job that never reports progress finishes.
 * set_result() attaches the counts reached before the cancel.
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
