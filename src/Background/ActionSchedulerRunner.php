<?php
/**
 * Action Scheduler-backed implementation of the job runner.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Used when Action Scheduler is loaded (any WooCommerce-active site, the
 * standalone AS plugin, or any other plugin that bundles AS).
 *
 * Inherits AS's native features:
 *   - Persistent claim / lock model — two cron processes can't pick up
 *     the same action.
 *   - Built-in retry with exponential backoff on uncaught exceptions.
 *   - Admin UI at *Tools → Scheduled Actions* showing every action
 *     PerfLocale ever enqueued, grouped under `'perflocale'`.
 *
 * The factory only instantiates this when {@see JobRunnerFactory::action_scheduler_available()}
 * returns true, so we can assume `as_*` functions exist throughout.
 */
final class ActionSchedulerRunner implements JobRunnerInterface {

	/**
	 * AS group name. Surfaces in *Tools → Scheduled Actions* so PerfLocale's
	 * background work is filterable from WC's actions.
	 */
	public const GROUP = 'perflocale';

	/**
	 * {@inheritDoc}
	 */
	public function enqueue( string $hook, array $args, string $job_id ): string {
		as_enqueue_async_action( $hook, [ $job_id, $args ], self::GROUP );
		return $job_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule( int $timestamp, string $hook, array $args, string $job_id ): string {
		as_schedule_single_action( max( time(), $timestamp ), $hook, [ $job_id, $args ], self::GROUP );
		return $job_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * AS matches scheduled actions by exact (hook, args, group). We try
	 * both arg shapes: with the multisite blog-id sentinel and without
	 * (single-site form). Also use `as_unschedule_all_actions` to clear
	 * duplicates from retry / pause-reschedule paths.
	 */
	public function cancel( string $job_id ): bool {
		$state = JobState::get( $job_id );

		if ( ! $state || empty( $state['hook'] ) ) {
			return false;
		}

		$hook      = (string) $state['hook'];
		$base_args = (array) ( $state['args'] ?? [] );
		$cancelled = false;

		// Try the non-sentinel shape first (single-site form).
		foreach ( $this->candidate_arg_shapes( $job_id, $base_args, $state ) as $args ) {
			// `as_unschedule_all_actions` removes EVERY matching action,
			// not just the next-scheduled one. Idempotent: returns void.
			as_unschedule_all_actions( $hook, $args, self::GROUP );
			$cancelled = true;
		}

		// Belt-and-braces: the busy-count enumeration can't predict every arg
		// shape (combined type-busy + lock-busy counters, or a type-busy count
		// past the enumeration ceiling). Scan pending actions for this
		// hook+group whose first arg is this job_id (enqueue() always stores
		// args as [job_id, …]) and clear each, so a "successful" cancel never
		// leaves a live action behind.
		foreach ( $this->pending_args_for_job( $hook, $job_id ) as $action_args ) {
			as_unschedule_all_actions( $hook, $action_args, self::GROUP );
			$cancelled = true;
		}

		return $cancelled;
	}

	/**
	 * Collect the distinct arg arrays of pending actions under $hook+group
	 * whose first argument is $job_id, regardless of the trailing arg shape.
	 *
	 * @param string $hook   Worker hook.
	 * @param string $job_id Job UUID (always args[0] per enqueue()).
	 * @return array<int, array<int, mixed>>
	 */
	private function pending_args_for_job( string $hook, string $job_id ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}

		$actions = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => -1,
			],
			'OBJECT'
		);

		$out = [];

		foreach ( (array) $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
				continue;
			}

			$action_args = (array) $action->get_args();

			if ( ( $action_args[0] ?? null ) === $job_id ) {
				$out[] = $action_args;
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
			if ( false !== as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
				return true;
			}
		}

		// Catch arg shapes the enumeration can't predict (see cancel()).
		return $this->pending_args_for_job( $hook, $job_id ) !== [];
	}

	/**
	 * Yield each candidate (hook, args) shape this job could have been
	 * scheduled under: non-sentinel single-site form, multisite blog-id
	 * sentinel, and type-busy reschedule sentinel (and combinations).
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

		// Type-busy retries add `__perflocale_type_busy_count` to args.
		// Enumerate 1..10 which covers any realistic cascade given the
		// exponential cap (the busy-count delay saturates well before 10
		// attempts).
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
		// on JobLock::acquire failure). Same enumeration up to the
		// lock_busy_max_retries cap (20).
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
		return 'action_scheduler';
	}
}
