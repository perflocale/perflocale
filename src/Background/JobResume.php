<?php
/**
 * Resume after a killed worker, for jobs that opt in.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The resume state of a job whose {@see AbstractJob::resumes_after_kill()} is true, kept in its `result` under
 * {@see JobState::RESUME_KEY} next to the job's own checkpoint, and its one pending worker event. Loaded by the
 * worker and the watchdog for such jobs only.
 *
 * @internal
 */
final class JobResume {

	/**
	 * Lease of the job and type locks on a web request, in seconds: at least twice InnoDB's default lock wait
	 * (50 s), so a write that waits on a row lock never outlives it.
	 */
	public const LEASE = 120;

	/**
	 * Seconds after the lease ends at which the dead-man event runs.
	 */
	public const DEAD_MAN_GRACE = 30;

	/**
	 * Default stops at the same position (kills and deadline stops together) after which the job fails.
	 */
	public const MAX_KILLS = 3;

	/**
	 * The string keys of an array (a stored result is a JSON object).
	 *
	 * @param array<mixed> $values Array.
	 * @return array<string, mixed>
	 */
	public static function keyed( array $values ): array {
		$out = [];

		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * The job's checkpoint and its resume state, apart.
	 *
	 * @param array<mixed> $stored The stored result.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	public static function split( array $stored ): array {
		$meta = $stored[ JobState::RESUME_KEY ] ?? null;
		unset( $stored[ JobState::RESUME_KEY ] );

		return [ self::keyed( $stored ), is_array( $meta ) ? self::keyed( $meta ) : [] ];
	}

	/**
	 * A checkpoint taken inside a slice: open, naming the unit about to run.
	 *
	 * @param array<mixed>         $checkpoint The job's checkpoint.
	 * @param array<string, mixed> $meta       Resume state.
	 * @param string               $unit       The unit about to run, as shown to the user ('' when unknown).
	 * @return array<string, mixed>
	 */
	public static function stamp( array $checkpoint, array $meta, string $unit ): array {
		$checkpoint                         = self::keyed( $checkpoint );
		$meta['open']                       = true;
		$meta['unit']                       = $unit;
		$checkpoint[ JobState::RESUME_KEY ] = $meta;

		return $checkpoint;
	}

	/**
	 * A slice's result as stored when the slice ended: closed, with the time the next slice may run when later.
	 *
	 * @param array<string, mixed> $result     The slice's result.
	 * @param array<string, mixed> $meta       Resume state.
	 * @param int                  $not_before Unix time of the next slice (0: none).
	 * @return array<string, mixed>
	 */
	public static function close( array $result, array $meta, int $not_before = 0 ): array {
		$meta['open'] = false;

		if ( $not_before > time() ) {
			$meta['not_before'] = $not_before;
		}

		$result[ JobState::RESUME_KEY ] = $meta;

		return $result;
	}

	/**
	 * When a continuing job's event came early: the saved time of its next slice, else 0. A killed slice (still open)
	 * and a Retry (its resume state is rebuilt) run now.
	 *
	 * @param array<string, mixed> $meta Stored resume state.
	 * @return int Unix time, or 0.
	 */
	public static function wait_until( array $meta ): int {
		$at = $meta['not_before'] ?? null;

		return empty( $meta['open'] ) && is_int( $at ) && $at > time() ? $at : 0;
	}

	/**
	 * Open a slice: a slice still open in the stored state was killed. After max_stops() stops at one position the job
	 * fails, naming the item; else the checkpoint is stored open again (a kill also counts as an attempt). A refused
	 * opening runs nothing: null when the row belongs to another run, an error (failed, then retried) when it is.
	 *
	 * @param string               $job_id   Job id.
	 * @param string               $type     Job type.
	 * @param array<string, mixed> $resume   The job's checkpoint.
	 * @param array<string, mixed> $meta     Stored resume state.
	 * @param int                  $position The row's `processed`.
	 * @param bool                 $claimed  The row was claimed from queued (its attempt is counted already).
	 * @param int                  $generation In: the attempts read with the claim (-1: unknown); out: after the opening.
	 * @return array<string, mixed>|null Resume state of this slice; null when the job was failed or is another run's.
	 * @throws \RuntimeException When the database refused the opening of this run's row.
	 */
	public static function open( string $job_id, string $type, array $resume, array $meta, int $position, bool $claimed, int &$generation = -1 ): ?array {
		$kills = is_int( $meta['kills'] ?? null ) ? $meta['kills'] : 0;
		$at    = is_int( $meta['at'] ?? null ) ? $meta['at'] : -1;
		$unit  = is_string( $meta['unit'] ?? null ) && '' !== $meta['unit'] ? $meta['unit'] : sprintf(
			/* translators: %d: position of the item in the job, from 1. */
			__( 'item %d', 'perflocale' ),
			$position + 1
		);
		$killed = ! empty( $meta['open'] );

		if ( $killed ) {
			$kills = $at === $position ? $kills + 1 : 1;
			$at    = $position;
		}

		// A slice that was not killed names no unit until its first checkpoint: a setup kill names its position.
		$meta = [
			'open'  => true,
			'kills' => $kills,
			'at'    => $at,
			'unit'  => $killed && is_string( $meta['unit'] ?? null ) ? $meta['unit'] : '',
		];

		if ( $killed && $kills >= self::max_stops() ) {
			self::fail_killed( $job_id, $type, $resume, $meta, $kills, $unit, $generation );

			return null;
		}

		$resume[ JobState::RESUME_KEY ] = $meta;
		$counted                        = $killed && ! $claimed;

		if ( ! JobState::store_resume( $job_id, $resume, $counted, $generation ) ) {
			$now = JobState::owner( $job_id );

			if ( 'running' !== ( $now['status'] ?? null ) || ( $generation >= 0 && $now['attempts'] !== $generation ) ) {
				return null;
			}

			throw new \RuntimeException( esc_html__( 'The progress of this job could not be saved (the database refused the update), so the job was stopped. Retry it to run it again.', 'perflocale' ) );
		}

		if ( $counted && $generation >= 0 ) {
			++$generation;
		}

		if ( $killed ) {
			JobState::append_log(
				$job_id,
				sprintf(
					/* translators: 1: the item the worker was on, e.g. "post 12 into de". */
					__( 'The background worker stopped at %1$s; the job continues from there.', 'perflocale' ),
					$unit
				),
				'worker_killed'
			);
		}

		return $meta;
	}

	/**
	 * Stops at one item after which the job fails naming it, kills and deadline stops together.
	 *
	 * @return int
	 */
	public static function max_stops(): int {
		/**
		 * Filter how often a background job may stop at the same item (its worker killed, or a provider call cut by
		 * the slice's deadline) before the job fails naming the item. At least 1.
		 *
		 * @hook  perflocale/jobs/max_unit_stops
		 * @since 1.0.7.4
		 *
		 * @param int $stops Default 3.
		 */
		$stops = apply_filters( 'perflocale/jobs/max_unit_stops', self::MAX_KILLS );

		return max( 1, \PerfLocale\Admin\Typed::int( $stops, self::MAX_KILLS ) );
	}

	/**
	 * Count a slice's stop at the provider deadline like a kill at $position; null when the job failed on it.
	 *
	 * @param string               $job_id     Job id.
	 * @param string               $type       Job type.
	 * @param array<string, mixed> $checkpoint The slice's result (the unit's start).
	 * @param array<string, mixed> $meta       This slice's resume state.
	 * @param int                  $position   The unit's position.
	 * @param string               $unit       The unit, as shown to the user ('' when unknown).
	 * @param int                  $generation The attempts read with the claim (-1: any).
	 * @return array<string, mixed>|null
	 */
	public static function count_stop( string $job_id, string $type, array $checkpoint, array $meta, int $position, string $unit, int $generation ): ?array {
		$kills         = is_int( $meta['kills'] ?? null ) && ( $meta['at'] ?? null ) === $position ? $meta['kills'] + 1 : 1;
		$meta['kills'] = $kills;
		$meta['at']    = $position;

		if ( $kills < self::max_stops() ) {
			return $meta;
		}

		/* translators: %d: position of the item in the job, from 1. */
		self::fail_killed( $job_id, $type, $checkpoint, $meta, $kills, '' !== $unit ? $unit : sprintf( __( 'item %d', 'perflocale' ), $position + 1 ), $generation );

		return null;
	}

	/**
	 * Fail a job stopped max_stops() times at one item, keeping its checkpoint for a retry.
	 *
	 * @param string               $job_id Job id.
	 * @param string               $type   Job type.
	 * @param array<string, mixed> $resume The job's checkpoint.
	 * @param array<string, mixed> $meta   Resume state.
	 * @param int                  $kills  Kills at the item.
	 * @param string               $unit   The item.
	 * @param int                  $generation The attempts read with the claim (-1: any).
	 * @return void
	 */
	private static function fail_killed( string $job_id, string $type, array $resume, array $meta, int $kills, string $unit, int $generation ): void {
		$message = sprintf(
			/* translators: 1: number of stops, 2: the item, e.g. "post 12 into de". */
			__( 'The background worker stopped %1$d times at the same item (%2$s), so the job was stopped. Check that item, then retry the job.', 'perflocale' ),
			$kills,
			$unit
		);

		self::unschedule( $job_id );

		// The checkpoint is stored while the row still runs: Retry resumes from it.
		JobState::store_resume( $job_id, self::close( $resume, $meta ), false, $generation );

		if ( ! JobState::mark_failed( $job_id, $message, $generation ) ) {
			return;
		}

		JobState::append_log( $job_id, $message, 'worker_killed' );

		/** This action is documented in src/Background/WorkerRunner.php */
		do_action( 'perflocale/jobs/failed', $job_id, $type, new \RuntimeException( $message ) );
	}

	/**
	 * Make $at the time of the job's only pending event: every pending event of the job goes first (WP-Cron refuses an
	 * identical event within 10 minutes), then the new one is checked in the store and written again once if missing.
	 *
	 * @param string               $job_id Job id.
	 * @param string               $hook   Worker hook.
	 * @param array<string, mixed> $args   Worker args (with the blog sentinel).
	 * @param int                  $at     Unix time.
	 * @param string               $engine The engine recorded on the row ('': not known); its events go too when it differs.
	 * @return bool Whether the event is stored.
	 */
	public static function reschedule( string $job_id, string $hook, array $args, int $at, string $engine = '' ): bool {
		$runner = JobRunnerFactory::pick();

		try {
			// A custom runner's name is never recorded on the row: only the two built-in engines are compared.
			$known = [ 'wp_cron', 'action_scheduler' ];

			if ( in_array( $engine, $known, true ) && in_array( $runner->get_engine_name(), $known, true ) && $engine !== $runner->get_engine_name() ) {
				$other = JobRunnerFactory::for_engine( $engine );

				if ( $other->get_engine_name() !== $runner->get_engine_name() ) {
					self::fresh_cron( $other );
					self::cancel( $other, $job_id, $hook );
				}
			}

			self::fresh_cron( $runner );
			self::cancel( $runner, $job_id, $hook );
			$runner->schedule( $at, $hook, $args, $job_id );

			$stored = self::stored( $runner, $job_id, $hook, $args, $at );

			if ( ! $stored ) {
				// The cron option is one value: another process wrote its older copy over ours.
				self::fresh_cron( $runner );
				self::cancel( $runner, $job_id, $hook );
				$runner->schedule( $at, $hook, $args, $job_id );
				$stored = self::stored( $runner, $job_id, $hook, $args, $at );
			}

			JobState::set_engine( $job_id, $runner->get_engine_name() );
		} catch ( \Throwable $e ) {
			$stored = false;
		}

		if ( ! $stored ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: the hourly watchdog requeues the job.
			error_log( '[PerfLocale] Job ' . $job_id . ': its next worker event could not be stored; the watchdog requeues it.' );
		}

		return $stored;
	}

	/**
	 * Remove every pending event of the job.
	 *
	 * @param string $job_id Job id.
	 * @return void
	 */
	public static function unschedule( string $job_id ): void {
		$runner = JobRunnerFactory::pick();

		try {
			self::fresh_cron( $runner );
			$runner->cancel( $job_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Remove the job's pending events in one runner: on Action Scheduler from one scan of the hook.
	 *
	 * @param JobRunnerInterface $runner Runner.
	 * @param string             $job_id Job id.
	 * @param string             $hook   Worker hook.
	 * @return void
	 */
	private static function cancel( JobRunnerInterface $runner, string $job_id, string $hook ): void {
		if ( $runner instanceof ActionSchedulerRunner ) {
			$runner->cancel_pending( $hook, $job_id );
			return;
		}

		$runner->cancel( $job_id );
	}

	/**
	 * Read the cron option again before it is written: this request's copy can be older than a write since.
	 *
	 * @param JobRunnerInterface $runner Runner.
	 * @return void
	 */
	private static function fresh_cron( JobRunnerInterface $runner ): void {
		if ( ! $runner instanceof WpCronRunner ) {
			return;
		}

		if ( wp_using_ext_object_cache() ) {
			wp_load_alloptions( true );
		} else {
			wp_cache_delete( 'alloptions', 'options' );
		}

		wp_cache_delete( 'cron', 'options' );
	}

	/**
	 * Whether the job's event is in the store: at its time in the cron option as stored. An Action Scheduler action is
	 * one insert, and schedule() throws when it is refused.
	 *
	 * @param JobRunnerInterface   $runner Runner.
	 * @param string               $job_id Job id.
	 * @param string               $hook   Hook.
	 * @param array<string, mixed> $args   Worker args.
	 * @param int                  $at     Unix time it was scheduled at.
	 * @return bool
	 */
	private static function stored( JobRunnerInterface $runner, string $job_id, string $hook, array $args, int $at ): bool {
		$event = [ $job_id, $args ];

		if ( $runner instanceof WpCronRunner ) {
			global $wpdb;

			if ( ! $wpdb instanceof \wpdb ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The option as stored, past every in-process copy.
			$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'cron' ) );
			$cron = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
			$key  = md5( serialize( $event ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WP-Cron's own event key.

			// WpCronRunner::schedule() stores max( its time(), $at ): any second from $at to now.
			foreach ( is_array( $cron ) ? $cron : [] as $ts => $due ) {
				if ( $ts >= $at && $ts <= max( time(), $at ) && is_array( $due ) && is_array( $due[ $hook ] ?? null ) && isset( $due[ $hook ][ $key ] ) ) {
					return true;
				}
			}

			return false;
		}

		return true;
	}
}
