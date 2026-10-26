<?php
/**
 * Dispatch state of a one-time background job, kept in one autoloaded option.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin_init dispatch of a once-per-blog job (TemplateRefsBackfillJob, StringGroupRepairJob), decided from its
 * autoloaded state option alone.
 *
 * States: absent, `claim:<time>`, `queued:<job id>:<time of the last check>`, `done:<time>`, `failed:<time>`,
 * `canceled:<time>`. The job records done; job_ended() records a final failure (worker) and a cancel (worker action,
 * REST, WP-CLI); while the worker retries the state stays queued. A queued state whose check is CHECK_EVERY old is
 * checked against its job row by the one request that moves the check time (compare-and-set).
 */
final class OneTimeDispatch {

	/**
	 * Seconds between two checks of a queued job's row.
	 */
	public const CHECK_EVERY = 10 * MINUTE_IN_SECONDS;

	/**
	 * Seconds after which a claim that never recorded its job is taken again.
	 */
	public const CLAIM_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * State option of each one-time job type, written out so that ending a job of another type loads neither class.
	 */
	private const OPTIONS = [
		'template_refs_backfill' => 'perflocale_template_refs_backfill',
		'sqlite_group_repair'    => 'perflocale_sqlite_string_groups_repair_job',
	];

	/**
	 * What a state asks of an admin page view: 'skip', 'dispatch' or 'check' (read the job row first). Reads nothing.
	 *
	 * @param string $state              Stored state ('' for none).
	 * @param int    $now                Unix time.
	 * @param int    $retry_after_failed Seconds a failed job waits before it is dispatched again.
	 * @param bool   $unknown_dispatches Whether a state of no known form is dispatched again.
	 * @return string
	 */
	public static function decide( string $state, int $now, int $retry_after_failed, bool $unknown_dispatches ): string {
		if ( '' === $state ) {
			return 'dispatch';
		}

		$parts = explode( ':', $state, 3 );
		$at    = (int) ( $parts[1] ?? 0 );

		switch ( $parts[0] ) {
			case 'done':
				return 'skip';
			case 'claim':
				return $at < $now - self::CLAIM_TTL ? 'dispatch' : 'skip';
			case 'canceled':
				return 'dispatch';
			case 'failed':
				return $at <= $now - $retry_after_failed ? 'dispatch' : 'skip';
			case 'queued':
				$checked = (int) ( $parts[2] ?? 0 );

				return $checked <= $now - self::CHECK_EVERY || $checked > $now + self::CHECK_EVERY ? 'check' : 'skip';
		}

		return $unknown_dispatches ? 'dispatch' : 'skip';
	}

	/**
	 * Dispatch the job when its state asks for it: the capability is checked first, and only the claim's winner enqueues.
	 *
	 * @param string   $option             State option.
	 * @param string   $state              State read from it.
	 * @param string   $capability         Capability the job requires.
	 * @param int      $retry_after_failed Seconds a failed job waits before it is dispatched again.
	 * @param bool     $unknown_dispatches Whether a state of no known form is dispatched again.
	 * @param callable $dispatch           Run by the request that won the claim, with the claim it
	 *                                     holds: enqueues the job and records it with {@see publish()}.
	 * @return void
	 */
	public static function maybe_dispatch( string $option, string $state, string $capability, int $retry_after_failed, bool $unknown_dispatches, callable $dispatch ): void {
		$now    = time();
		$action = self::decide( $state, $now, $retry_after_failed, $unknown_dispatches );

		if ( 'skip' === $action || ! current_user_can( $capability ) ) {
			return;
		}

		if ( 'check' === $action ) {
			$state = self::check( $option, $state, $now, $retry_after_failed );

			if ( null === $state ) {
				return;
			}
		}

		$claim = 'claim:' . $now;

		if ( ! self::claim( $option, $state, $claim ) ) {
			return;
		}

		$dispatch( $claim );
	}

	/**
	 * Record over this request's own claim what it queued (`queued:<job id>:<time>`, or no state when nothing was).
	 * A state other than this claim is left alone: the worker may already have recorded done (Action Scheduler can
	 * run the action as soon as it is stored), or another request took the claim after CLAIM_TTL.
	 *
	 * @param string $option State option.
	 * @param string $claim  The claim this request holds (`claim:<unix time>`).
	 * @param string $job_id Job queued ('' for none).
	 * @return void
	 */
	public static function publish( string $option, string $claim, string $job_id ): void {
		global $wpdb;

		if ( '' !== $job_id ) {
			self::claim( $option, $claim, 'queued:' . $job_id . ':' . time() );

			return;
		}

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $option, $claim );

		if ( is_string( $sql ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Compare-and-delete of this request's claim (prepared above); the options cache is dropped below.
			$wpdb->query( $sql );
		}

		self::forget( $option );
	}

	/**
	 * Check a queued job's row once per CHECK_EVERY across requests: gone or canceled, or failed $retry_after_failed ago,
	 * dispatches again; a newer failure is recorded as `failed:<time>`; a queued row without its event gets it again.
	 *
	 * @param string $option             State option.
	 * @param string $state              Stored `queued:` state.
	 * @param int    $now                Unix time.
	 * @param int    $retry_after_failed Seconds a failed job waits before it is dispatched again.
	 * @return string|null The state to dispatch from, or null for no dispatch.
	 */
	private static function check( string $option, string $state, int $now, int $retry_after_failed ): ?string {
		$job_id   = (string) ( explode( ':', $state, 3 )[1] ?? '' );
		$checking = 'queued:' . $job_id . ':' . $now;

		if ( ! self::claim( $option, $state, $checking ) ) {
			return null;
		}

		$row    = JobState::get( $job_id );
		$status = $row['status'] ?? null;

		if ( null === $row || 'canceled' === $status ) {
			return $checking;
		}

		if ( 'queued' === $status ) {
			self::schedule_if_lost( $job_id, $row );

			return null;
		}

		// While the worker holds the lock it may still retry: only a failure without the lock is final.
		if ( 'failed' !== $status || self::worker_holds( $job_id ) ) {
			return null;
		}

		// The lock is released after the retry decision: the failure is final when the row read again is the same row
		// (same version, still failed). A changed row waits for a later check; a gone or canceled one is dispatched.
		$fresh = JobState::get( $job_id );

		if ( null === $fresh || 'canceled' === ( $fresh['status'] ?? null ) ) {
			return $checking;
		}

		if ( 'failed' !== ( $fresh['status'] ?? null ) || ( $fresh['version'] ?? null ) !== ( $row['version'] ?? null ) ) {
			return null;
		}

		$failed_at = is_numeric( $fresh['completed_at'] ?? null ) ? (int) $fresh['completed_at'] : 0;

		if ( $failed_at <= $now - $retry_after_failed ) {
			return $checking;
		}

		self::claim( $option, $checking, 'failed:' . $failed_at );

		return null;
	}

	/**
	 * Store a queued job's lost worker event again (a wp-cron.php run that read the cron array before the dispatch saves
	 * it back without it). Probed in the worker's order: event, lock, row.
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $row    Its jobs row ({@see JobState::get()}).
	 * @return void
	 */
	private static function schedule_if_lost( string $job_id, array $row ): void {
		$hook   = is_string( $row['hook'] ?? null ) ? $row['hook'] : '';
		$engine = is_string( $row['engine'] ?? null ) ? $row['engine'] : '';

		if ( '' === $hook ) {
			return;
		}

		$args = [];

		foreach ( is_array( $row['args'] ?? null ) ? $row['args'] : [] as $key => $value ) {
			if ( is_string( $key ) ) {
				$args[ $key ] = $value;
			}
		}

		try {
			if ( JobRunnerFactory::for_engine( $engine )->is_scheduled( $job_id ) || self::worker_holds( $job_id ) || 'queued' !== ( JobState::get( $job_id )['status'] ?? null ) ) {
				return;
			}

			$runner = JobRunnerFactory::pick();
			$runner->schedule( time() + 1, $hook, WorkerRegistry::with_blog_sentinel( $args, (int) get_current_blog_id() ), $job_id );

			if ( $runner->get_engine_name() !== $engine ) {
				JobState::set_engine( $job_id, $runner->get_engine_name() );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // The watchdog still fails a job that never runs.
		}
	}

	/**
	 * Whether a worker holds the job's lock (unexpired, read from the database): from before the run until after its
	 * retry decision.
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	private static function worker_holds( string $job_id ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock is read past the options cache, as JobLock writes it.
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, JobLock::PREFIX . $job_id ) );

		return is_string( $stored ) && (int) strtok( $stored, '|' ) > time();
	}

	/**
	 * `perflocale/jobs/canceled`: record the cancel of a one-time job.
	 *
	 * @param mixed $job_id Job id.
	 * @param mixed $type   Job type.
	 * @return void
	 */
	public static function on_canceled( mixed $job_id, mixed $type ): void {
		if ( is_string( $job_id ) && is_string( $type ) ) {
			self::job_ended( $job_id, $type, 'canceled' );
		}
	}

	/**
	 * Record `<status>:<time>` for a one-time job that failed or was canceled, when its state still names it as queued
	 * (one UPDATE); other types and states are left alone.
	 *
	 * @param string $job_id Job id.
	 * @param string $type   Job type.
	 * @param string $status 'failed' or 'canceled'.
	 * @return bool Whether the state was written.
	 */
	public static function job_ended( string $job_id, string $type, string $status ): bool {
		global $wpdb;

		$option = self::OPTIONS[ $type ] ?? null;

		if ( null === $option || ! in_array( $status, [ 'failed', 'canceled' ], true ) || ! JobState::is_safe_id( $job_id ) || ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$queued = 'queued:' . $job_id;
		$to     = $status . ':' . time();
		$sql    = $wpdb->prepare(
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND ( CAST(option_value AS BINARY) = CAST(%s AS BINARY) OR option_value LIKE %s )',
			$wpdb->options,
			$to,
			$option,
			$queued,
			$wpdb->esc_like( $queued . ':' ) . '%'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Compare-and-set of the job's state (prepared above); the options cache is dropped below.
		$written = is_string( $sql ) && 1 === $wpdb->query( $sql );

		if ( $written ) {
			self::forget( $option );
		}

		return $written;
	}

	/**
	 * Move the state from $from to $to in one statement only one request wins: INSERT IGNORE without a row, else a
	 * compare-and-set UPDATE. Not add_option(): with the option in `notoptions` it overwrites a row another request added.
	 *
	 * @param string $option State option.
	 * @param string $from   State read ('' for no row).
	 * @param string $to     State to write.
	 * @return bool Whether this request took it.
	 */
	public static function claim( string $option, string $from, string $to ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$sql = '' === $from
			? $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $option, $to, 'yes' )
			: $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $to, $option, $from );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic claim (prepared above); the options cache is dropped below.
		$won = is_string( $sql ) && 1 === $wpdb->query( $sql );

		self::forget( $option );

		return $won;
	}

	/**
	 * Drop the option from the options caches after a direct write. The request's alloptions copy is not written back:
	 * another process may have changed other options since it was read.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private static function forget( string $option ): void {
		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $option, 'options' );
	}
}
