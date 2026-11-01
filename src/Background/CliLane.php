<?php
/**
 * Runs one background job in a WP-CLI process: `wp perflocale jobs run`.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a job's slices and retries in this WP-CLI process at their planned times while holding its lane
 * (JobLock::hold_cli_lane(); other workers leave the job alone). Loaded only by the WP-CLI command. No caller
 * capability check: the lane only picks the process; WorkerRunner re-checks the dispatcher and runs as them.
 *
 * @internal
 */
final class CliLane {

	/**
	 * Longest sleep between two refreshes of the hold, in seconds.
	 */
	private const NAP = 30;

	/**
	 * Seconds between checks while another worker runs a slice of the job.
	 */
	private const BUSY_WAIT = 5;

	/**
	 * Run a job here until it ends or another process takes it; a failed or canceled job is retried first.
	 *
	 * @param string                 $job_id Job identifier.
	 * @param callable(string): void $log    Prints a progress line.
	 * @return array{0: bool, 1: string} [ whether the job completed, message ].
	 */
	public static function run( string $job_id, callable $log ): array {
		$state = JobState::get_fresh( $job_id );

		if ( null === $state ) {
			return [ false, "Job not found: {$job_id}" ];
		}

		$status = self::text( $state, 'status' );

		if ( 'complete' === $status ) {
			return [ false, "Job {$job_id} is already complete." ];
		}

		if ( WorkerRegistry::queue_is_paused() ) {
			return [ false, 'The job queue is paused. Unpause it first: wp perflocale jobs unpause' ];
		}

		if ( ! JobLock::hold_cli_lane( $job_id ) ) {
			return [ false, "Job {$job_id} is held by another 'wp perflocale jobs run'. Wait for it to end; its hold lapses " . JobLock::CLI_LANE_TTL . ' seconds after it stops.' ];
		}

		try {
			$args = WorkerRegistry::with_blog_sentinel( self::args( $state ), self::number( $state, 'blog_id' ) );

			if ( 'failed' === $status || 'canceled' === $status ) {
				$error = self::retry( $job_id, $state, $args );

				if ( '' !== $error ) {
					return [ false, $error ];
				}
			}

			JobState::append_log( $job_id, __( 'Running in WP-CLI (wp perflocale jobs run); other workers wait for it.', 'perflocale' ) );

			return self::drive( $job_id, $args, $log );
		} finally {
			JobLock::release_cli_lane( $job_id );
		}
	}

	/**
	 * Reset a failed or canceled job as `jobs retry` does; its event, due when the hold lapses, runs it should this
	 * process die first.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $state  Row read before the hold.
	 * @param array<string, mixed> $args   Worker args with the blog sentinel.
	 * @return string Error message; '' when reset.
	 */
	private static function retry( string $job_id, array $state, array $args ): string {
		$version = $state['version'] ?? null;

		if ( ! is_int( $version ) || ! JobState::reset_for_retry( $job_id, true, -1, -1, $version ) ) {
			return "Could not reset job {$job_id} for retry (its state changed concurrently).";
		}

		$runner = JobRunnerFactory::pick();

		try {
			$runner->schedule( time() + JobLock::CLI_LANE_TTL + 1, self::text( $state, 'hook' ), $args, $job_id );
		} catch ( \Throwable $e ) {
			$message = sprintf(
				/* translators: %s is the runner's error message. */
				__( 'Failed to enqueue background job: %s', 'perflocale' ),
				\PerfLocale\Util\PathRedactor::redact( $e->getMessage() )
			);
			JobState::fail_queued( $job_id, $message );

			return "Could not retry job {$job_id}. {$message}";
		}

		JobState::set_engine( $job_id, $runner->get_engine_name() );

		return '';
	}

	/**
	 * Run the job's slices and retries here, each at its planned time, while this process holds the lane.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $args   Worker args with the blog sentinel.
	 * @param callable             $log    Prints a progress line.
	 * @return array{0: bool, 1: string}
	 */
	private static function drive( string $job_id, array $args, callable $log ): array {
		$next = 0;

		while ( true ) {
			// Wait for the planned time, and for a slice another worker started before the hold to end there.
			while ( true ) {
				if ( ! JobLock::hold_cli_lane( $job_id ) ) {
					return [ false, "Another 'wp perflocale jobs run' took job {$job_id}; this run stopped." ];
				}

				$state = JobState::get_fresh( $job_id );

				if ( null === $state || JobState::is_terminal( self::text( $state, 'status' ) ) ) {
					return self::ended( $job_id, $state );
				}

				if ( WorkerRegistry::queue_is_paused() ) {
					return [ false, "The job queue was paused; job {$job_id} keeps its place and runs when the queue is unpaused." ];
				}

				$wait = JobLock::is_held_now( $job_id ) ? self::BUSY_WAIT : $next - time();

				if ( $wait <= 0 ) {
					break;
				}

				sleep( min( self::NAP, $wait ) );
			}

			WorkerRunner::take_next_at( $job_id );
			WorkerRunner::run( $job_id, $args );

			$next  = WorkerRunner::take_next_at( $job_id );
			$state = JobState::get_fresh( $job_id );

			if ( null === $state || JobState::is_terminal( self::text( $state, 'status' ) ) ) {
				return self::ended( $job_id, $state );
			}

			// Not ended and nothing planned: another run owns the job now.
			if ( 0 === $next ) {
				return [ false, "Job {$job_id} is run by another worker now; this run stopped." ];
			}

			$log( sprintf( 'Job %s: %d of %d done; next run in %d s.', $job_id, self::number( $state, 'processed' ), self::number( $state, 'total' ), max( 0, $next - time() ) ) );
		}
	}

	/**
	 * The outcome of a job that ended or is gone.
	 *
	 * @param string                    $job_id Job identifier.
	 * @param array<string, mixed>|null $state  Fresh row.
	 * @return array{0: bool, 1: string}
	 */
	private static function ended( string $job_id, ?array $state ): array {
		$status = self::text( $state, 'status' );

		return match ( $status ) {
			'complete' => [ true, "Job {$job_id} complete." ],
			'failed'   => [ false, "Job {$job_id} failed: " . self::text( $state, 'error' ) ],
			'canceled' => [ false, "Job {$job_id} was canceled." ],
			default    => [ false, "Job {$job_id} no longer exists." ],
		};
	}

	/**
	 * A string column of a job row; '' when absent.
	 *
	 * @param array<string, mixed>|null $row Job row.
	 * @param string                    $key Column.
	 * @return string
	 */
	private static function text( ?array $row, string $key ): string {
		$value = $row[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * An integer column of a job row; 0 when absent.
	 *
	 * @param array<string, mixed> $row Job row.
	 * @param string               $key Column.
	 * @return int
	 */
	private static function number( array $row, string $key ): int {
		$value = $row[ $key ] ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * The worker args stored on a job row.
	 *
	 * @param array<string, mixed> $row Job row.
	 * @return array<string, mixed>
	 */
	private static function args( array $row ): array {
		$args = [];

		foreach ( is_array( $row['args'] ?? null ) ? $row['args'] : [] as $key => $value ) {
			if ( is_string( $key ) ) {
				$args[ $key ] = $value;
			}
		}

		return $args;
	}
}
