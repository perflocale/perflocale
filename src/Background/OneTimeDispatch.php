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
 * The admin_init dispatch of a job that runs once per blog
 * ({@see Jobs\TemplateRefsBackfillJob}, {@see Jobs\StringGroupRepairJob}),
 * decided from the job's autoloaded state option alone.
 *
 * States: absent (never dispatched), `claim:<unix time>` (a request is
 * dispatching), `queued:<job id>:<unix time of the last check>` (queued or
 * running), `done:<unix time>`, `failed:<unix time>`, `canceled:<unix time>`.
 * The job records done itself; failed and canceled are recorded by
 * {@see job_ended()}: failed by the worker once a failure is final (no
 * automatic retry follows it), canceled from the worker's
 * `perflocale/jobs/canceled` action and from the REST and WP-CLI cancel.
 * While the worker retries a failed run, the state stays queued.
 *
 * An admin page reads the option and nothing else in every state. The one
 * jobs-table read: a `queued:` state whose last check is CHECK_EVERY
 * seconds old or more is checked against its job row by the one request that
 * moves the check time forward (compare-and-set), so a job that failed, was
 * canceled or is gone without a recorded end is found within CHECK_EVERY
 * seconds of the next admin page view, at most once per CHECK_EVERY seconds.
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
	 * State option of each one-time job type: the jobs' OPTION constants,
	 * written out so that a job of any other type that ends loads neither
	 * class.
	 */
	private const OPTIONS = [
		'template_refs_backfill' => 'perflocale_template_refs_backfill',
		'sqlite_group_repair'    => 'perflocale_sqlite_string_groups_repair_job',
	];

	/**
	 * What a state asks of an admin page view: 'skip', 'dispatch' or
	 * 'check' (read the queued job's row first). Reads nothing.
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
	 * Dispatch the job when its state asks for it. The capability is checked
	 * before anything is read beyond the option, and only the request that
	 * wins the claim enqueues.
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
	 * Record what the request that won the claim queued, over its own claim
	 * only: `queued:<job id>:<unix time>`, or no state when nothing was queued
	 * (the next admin request dispatches again). A state the claim no longer
	 * holds is left as it is: the worker may have run the job to the end and
	 * recorded done while the request was still enqueueing it (an Action
	 * Scheduler queue runner takes an async action as soon as it is stored),
	 * or another request took the claim after CLAIM_TTL.
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
	 * Check a queued job's row, once per CHECK_EVERY seconds across all
	 * requests: the request that moves the check time forward reads the row.
	 * A row that is gone or canceled, or failed at least $retry_after_failed
	 * seconds ago, is dispatched again; a failure within that time is
	 * recorded as `failed:<its time>`. A queued row whose worker event is
	 * gone gets the event again ({@see schedule_if_lost()}).
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

		// A failed row whose worker still holds the job's lock is a run the
		// worker is still deciding on (an automatic retry may follow): only a
		// failure without that lock is final.
		if ( 'failed' !== $status || self::worker_holds( $job_id ) ) {
			return null;
		}

		// The worker releases the lock only after its retry decision, so the
		// failure is final when the row read again after the lock was found
		// free is the same row (same version, still failed). A row that
		// changed in between (reset for a retry, a new run) is decided by a
		// later check; a row that is gone or canceled by now is dispatched.
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
	 * Store a queued job's lost worker event again, same job id and args: a
	 * wp-cron.php run that read the cron array before the dispatch saves it
	 * back without it. Probed in the worker's order: event, lock, row.
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
	 * Whether a worker holds the job's lock ({@see JobLock}): the lock row,
	 * read from the database, has not expired. The worker holds it from
	 * before the run until after its retry decision.
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
	 * Record that a one-time job failed or was canceled, as
	 * `<status>:<unix time>`, when its state still names that job as queued.
	 * One UPDATE, only for the one-time job types; any other type, a state
	 * that names another job, or one already done is left as it is.
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
	 * Take the state from $from to $to in one statement that only one
	 * request can win: an INSERT IGNORE when there is no row ($from empty),
	 * a compare-and-set UPDATE otherwise. add_option() is not enough: when
	 * the options cache lists the option as missing it skips its existence
	 * check and its INSERT ... ON DUPLICATE KEY UPDATE overwrites a row
	 * another request just added, and still reports success.
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
	 * Drop the option from the options caches after a direct write, so that
	 * the next read loads the stored value: the request's copy of the
	 * autoloaded options is not written back, since another process may have
	 * changed other options in the persistent cache since this request read
	 * them.
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
