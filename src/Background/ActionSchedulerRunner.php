<?php
/**
 * Action Scheduler job runner.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runner used when Action Scheduler is loaded (WooCommerce, the AS plugin or another bundling): AS claims each
 * action once and retries on uncaught exceptions. Created only when
 * JobRunnerFactory::action_scheduler_available() is true, so the as_* functions exist.
 */
final class ActionSchedulerRunner implements JobRunnerInterface {

	/**
	 * AS group of PerfLocale's actions (filterable in Tools > Scheduled Actions).
	 */
	public const GROUP = 'perflocale';

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When Action Scheduler refuses the enqueue.
	 */
	public function enqueue( string $hook, array $args, string $job_id ): string {
		// 0 means AS did not schedule it: throw, so Dispatcher::enqueue() deletes the row and reports mode:error instead of
		// leaving a queued job with no action behind it (WpCronRunner does the same).
		$action_id = as_enqueue_async_action( $hook, [ $job_id, $args ], self::GROUP );

		if ( (int) $action_id <= 0 ) {
			throw new \RuntimeException(
				esc_html( 'Action Scheduler refused the async action for hook ' . $hook )
			);
		}

		return $job_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \RuntimeException When Action Scheduler refuses the scheduled action.
	 */
	public function schedule( int $timestamp, string $hook, array $args, string $job_id ): string {
		// Same as enqueue(): the retry, resume and busy-reschedule path must not lose the job silently.
		$action_id = as_schedule_single_action( max( time(), $timestamp ), $hook, [ $job_id, $args ], self::GROUP );

		if ( (int) $action_id <= 0 ) {
			throw new \RuntimeException(
				esc_html( 'Action Scheduler refused the scheduled action for hook ' . $hook )
			);
		}

		return $job_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * AS matches (hook, args, group) exactly: every args shape is unscheduled, then any action left for the job id.
	 */
	public function cancel( string $job_id ): bool {
		$state = JobState::get( $job_id );

		if ( ! $state || empty( $state['hook'] ) ) {
			return false;
		}

		$hook      = (string) $state['hook'];
		$base_args = (array) ( $state['args'] ?? [] );
		$cancelled = false;

		// Single-site shape first.
		foreach ( $this->candidate_arg_shapes( $job_id, $base_args, $state ) as $args ) {
			// Removes every matching action, not only the next one.
			as_unschedule_all_actions( $hook, $args, self::GROUP );
			$cancelled = true;
		}

		// The enumeration cannot predict every arg shape: also clear each pending action of this hook and group whose
		// first arg is this job id, so a cancel leaves no live action.
		foreach ( $this->pending_args_for_job( $hook, $job_id ) as $action_args ) {
			as_unschedule_all_actions( $hook, $action_args, self::GROUP );
			$cancelled = true;
		}

		return $cancelled;
	}

	/**
	 * Cancel the job's pending actions under $hook from one scan (every arg shape), without the shape enumeration.
	 *
	 * @param string $hook   Worker hook.
	 * @param string $job_id Job UUID.
	 * @return void
	 */
	public function cancel_pending( string $hook, string $job_id ): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			$this->cancel( $job_id );
			return;
		}

		$seen = [];

		foreach ( $this->pending_args_for_job( $hook, $job_id ) as $action_args ) {
			$key = serialize( $action_args ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A set key; one unschedule per arg shape.

			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				as_unschedule_all_actions( $hook, $action_args, self::GROUP );
			}
		}
	}

	/**
	 * The distinct args of pending actions under $hook and the group whose first argument is $job_id.
	 * The store is searched for the id first, so only this job's actions are read.
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
				'search'   => $job_id,
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

		// Arg shapes the enumeration cannot predict (see cancel()).
		return $this->pending_args_for_job( $hook, $job_id ) !== [];
	}

	/**
	 * Each args shape the job could be scheduled under: single-site, blog-id sentinel, busy-count sentinels.
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
		return 'action_scheduler';
	}
}
