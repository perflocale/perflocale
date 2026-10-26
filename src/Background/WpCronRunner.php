<?php
/**
 * WP-Cron job runner.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runner used without Action Scheduler or when `background_engine` forces WP-Cron.
 *
 * WP-Cron has no claim, lock or retry: JobLock and the worker's backoff cover those, and cancel rebuilds the
 * scheduled args from JobState because wp_unschedule_event() needs them.
 */
final class WpCronRunner implements JobRunnerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function enqueue( string $hook, array $args, string $job_id ): string {
		// Runs on the next cron tick, which on a quiet site can be minutes away.
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
	 * Schedule one cron event and throw when scheduling fails, so Dispatcher::enqueue() removes the row instead of
	 * leaving a job that never runs. A duplicate-event error counts as success (double resume, busy reschedule).
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
	 * Clears every queued occurrence of the job's event (pause, busy and retry reschedules can queue several).
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
			// Returns the count cleared, or false on an internal failure.
			if ( wp_clear_scheduled_hook( $hook, $args ) !== false ) {
				$cleared = true;
			}
		}

		// The enumeration cannot predict every arg shape (both busy counters at once, a type-busy count past its ceiling):
		// also clear any event under this hook whose first arg is this job id, so a cancel leaves no live event.
		foreach ( $this->scheduled_events_for_job( $hook, $job_id ) as $event ) {
			wp_unschedule_event( $event['ts'], $hook, $event['args'] );
			$cleared = true;
		}

		return $cleared;
	}

	/**
	 * Every pending cron event under $hook whose first argument is $job_id, as a flat list: one timestamp can hold
	 * several arg shapes of the same job, which a timestamp-keyed map would collapse.
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

		// Arg shapes the enumeration cannot predict (see cancel()).
		return $this->scheduled_events_for_job( $hook, $job_id ) !== [];
	}

	/**
	 * Each args shape the job could be scheduled under (blog-id sentinel, busy-count sentinels); cancel clears them all.
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

		// Type-busy retries add `__perflocale_type_busy_count`; 1..10 covers any chain (its delay saturates sooner).
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

		// Lock-busy retries add `__perflocale_lock_busy_count`; 1..20 matches the lock_busy_max_retries cap.
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
