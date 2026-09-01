<?php
/**
 * WP-Cron-backed implementation of the job runner.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fallback runner used when Action Scheduler isn't available (or the user
 * explicitly forced WP-Cron via the `background_engine` setting).
 *
 * Workers are invoked with `(string $job_id, array $args)` — same shape as
 * {@see ActionSchedulerRunner} produces, so a single worker callback works
 * regardless of which runner enqueued it.
 *
 * WP-Cron caveats this runner has to handle that AS would handle natively:
 *   - No claim/lock semantics → caller must use {@see JobLock}.
 *   - No retry on failure → worker hook implements exponential backoff.
 *   - `wp_unschedule_event()` needs the original args triplet → we read
 *     args from {@see JobState} to reconstruct the lookup key.
 */
final class WpCronRunner implements JobRunnerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function enqueue( string $hook, array $args, string $job_id ): string {
		// Run on the next cron tick (typically <60s on a busy site, longer on
		// a quiet one; document this limitation in the Jobs admin page).
		self::schedule_or_throw( time() + 1, $hook, [ $job_id, $args ] );
		return $job_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule( int $timestamp, string $hook, array $args, string $job_id ): string {
		self::schedule_or_throw( max( time(), $timestamp ), $hook, [ $job_id, $args ] );
		return $job_id;
	}

	/**
	 * Schedule a single cron event, HONOURING wp_schedule_single_event()'s
	 * return so a silent scheduling failure (a plugin short-circuiting the
	 * `pre_schedule_event` filter, a storage error) can't leave a JobState row
	 * that never runs. A duplicate-event WP_Error counts as success (the event
	 * already exists — expected on a double-resume or busy/retry reschedule);
	 * any other failure throws so Dispatcher::enqueue()'s catch tears down the
	 * orphaned JobState row and reports mode:error.
	 *
	 * @param int               $timestamp When to run.
	 * @param string            $hook      Cron hook.
	 * @param array<int, mixed> $args      Hook args.
	 * @return void
	 * @throws \RuntimeException When scheduling genuinely fails.
	 */
	private static function schedule_or_throw( int $timestamp, string $hook, array $args ): void {
		$scheduled = wp_schedule_single_event( $timestamp, $hook, $args, true );

		if ( $scheduled === true ) {
			return;
		}

		if ( is_wp_error( $scheduled ) && $scheduled->get_error_code() === 'duplicate_event' ) {
			return;
		}

		$message = is_wp_error( $scheduled ) ? $scheduled->get_error_message() : 'wp_schedule_single_event returned false';

		throw new \RuntimeException( esc_html( 'Failed to schedule cron event: ' . $message ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Clears ALL scheduled occurrences of this job's worker event (a job
	 * can legitimately have multiple instances queued from pause-reschedule,
	 * type-busy reschedule, or retry paths). `wp_clear_scheduled_hook()`
	 * matches on (hook, args) and removes every matching event regardless
	 * of timestamp — the safe one-shot equivalent of a loop of
	 * wp_unschedule_event() calls.
	 *
	 * Returns true if the call succeeded; WP returns the number of cleared
	 * events (0+) or false on internal failure.
	 */
	public function cancel( string $job_id ): bool {
		$state = JobState::get( $job_id );

		if ( ! $state || empty( $state['hook'] ) ) {
			return false;
		}

		$hook      = (string) $state['hook'];
		$base_args = (array) ( $state['args'] ?? [] );
		$cleared   = false;

		foreach ( $this->candidate_arg_shapes( $job_id, $base_args, $state ) as $args ) {
			// `wp_clear_scheduled_hook` removes every occurrence with this
			// (hook, args) tuple. Returns the count cleared (0+) or false
			// on internal failure — treat anything non-false as success.
			if ( wp_clear_scheduled_hook( $hook, $args ) !== false ) {
				$cleared = true;
			}
		}

		// Belt-and-braces: the busy-count enumeration above can't predict every
		// arg shape — a job can carry BOTH a type-busy and a lock-busy counter
		// at once, and type-busy has no count cap, so it can exceed the
		// enumeration ceiling. Scan the cron array for ANY remaining event
		// under this hook whose first arg is this job_id (schedule() always
		// stores args as [job_id, …]) and clear it, so a "successful" cancel
		// never leaves a live event that later fires against the dead job.
		foreach ( $this->scheduled_events_for_job( $hook, $job_id ) as $event ) {
			wp_unschedule_event( $event['ts'], $hook, $event['args'] );
			$cleared = true;
		}

		return $cleared;
	}

	/**
	 * Collect EVERY pending cron event under $hook whose first argument is
	 * $job_id, regardless of the trailing arg shape, as a flat list of
	 * `{ ts, args }`. A flat list (not a timestamp-keyed map) is required
	 * because the cron array can hold several DISTINCT arg shapes for the same
	 * job at the SAME timestamp (nested under different arg-hash keys); a map
	 * keyed by timestamp would collapse them and leave the extras scheduled.
	 *
	 * @param string $hook   Worker hook.
	 * @param string $job_id Job UUID (always args[0] per schedule()).
	 * @return array<int, array{ts:int, args:array<int, mixed>}>
	 */
	private function scheduled_events_for_job( string $hook, string $job_id ): array {
		$cron = _get_cron_array();
		$out  = [];

		if ( ! is_array( $cron ) ) {
			return $out;
		}

		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
				continue;
			}

			foreach ( $hooks[ $hook ] as $event ) {
				$event_args = ( isset( $event['args'] ) && is_array( $event['args'] ) ) ? $event['args'] : [];

				if ( ( $event_args[0] ?? null ) === $job_id ) {
					$out[] = [
						'ts'   => (int) $timestamp,
						'args' => $event_args,
					];
				}
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_scheduled( string $job_id ): bool {
		$state = JobState::get( $job_id );

		if ( ! $state || empty( $state['hook'] ) ) {
			return false;
		}

		$hook      = (string) $state['hook'];
		$base_args = (array) ( $state['args'] ?? [] );

		foreach ( $this->candidate_arg_shapes( $job_id, $base_args, $state ) as $args ) {
			if ( wp_next_scheduled( $hook, $args ) !== false ) {
				return true;
			}
		}

		// Catch arg shapes the enumeration can't predict (see cancel()).
		return $this->scheduled_events_for_job( $hook, $job_id ) !== [];
	}

	/**
	 * Yield each candidate (hook, args) shape this job could have been
	 * scheduled under. The dispatcher injects a blog-id sentinel; the
	 * worker's type-busy reschedule path further injects a busy-count
	 * sentinel. A cancel must clear every variant or stale events fire
	 * after the operator was told the job was canceled.
	 *
	 * @param string               $job_id
	 * @param array<string, mixed> $base_args
	 * @param array<string, mixed> $state
	 * @return iterable<array<int, mixed>>
	 */
	private function candidate_arg_shapes( string $job_id, array $base_args, array $state ): iterable {
		yield [ $job_id, $base_args ];

		$has_blog = ! empty( $state['blog_id'] );

		if ( $has_blog ) {
			$with_blog                         = $base_args;
			$with_blog['__perflocale_blog_id'] = (int) $state['blog_id'];
			yield [ $job_id, $with_blog ];
		}

		// Type-busy retries add `__perflocale_type_busy_count` to args. We
		// don't know the exact count an in-flight retry was scheduled
		// with; enumerate 1..10 which covers any realistic cascade given
		// the exponential cap (the busy-count delay saturates well before
		// 10 attempts).
		for ( $n = 1; $n <= 10; $n++ ) {
			$with_busy                                 = $base_args;
			$with_busy['__perflocale_type_busy_count'] = $n;
			yield [ $job_id, $with_busy ];

			if ( $has_blog ) {
				$with_busy_blog                         = $with_busy;
				$with_busy_blog['__perflocale_blog_id'] = (int) $state['blog_id'];
				yield [ $job_id, $with_busy_blog ];
			}
		}

		// Per-job-lock-busy retries add `__perflocale_lock_busy_count` to
		// args (introduced when WorkerRegistry stopped silently returning
		// on JobLock::acquire failure). Same enumeration pattern — the
		// cancel path probes 1..20 (matching the lock_busy_max_retries
		// cap so an in-flight retry chain can be fully cleared by cancel).
		for ( $n = 1; $n <= 20; $n++ ) {
			$with_lock_busy                                 = $base_args;
			$with_lock_busy['__perflocale_lock_busy_count'] = $n;
			yield [ $job_id, $with_lock_busy ];

			if ( $has_blog ) {
				$with_lock_busy_blog                         = $with_lock_busy;
				$with_lock_busy_blog['__perflocale_blog_id'] = (int) $state['blog_id'];
				yield [ $job_id, $with_lock_busy_blog ];
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_engine_name(): string {
		return 'wp_cron';
	}
}
