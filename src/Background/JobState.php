<?php
/**
 * Background job state in the `wp_perflocale_jobs` table.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// File-level: the table is %i-quoted; $wpdb->options, IN() placeholder lists and reset_for_retry()'s hardcoded
// status list; per-call phpcs:ignore comments don't cover the multi-line SQL
// strings. No caching: the jobs table is concurrently mutated, and a stale read would mis-route a worker.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * State store for background jobs, in the per-blog `perflocale_jobs` table. Every mutating write is one UPDATE
 * guarded by the row's status or `version` (compare-and-set), so concurrent progress, cancel and mark calls never
 * overwrite each other; a loser sees 0 affected rows. Rows are returned as `['id' => uuid, 'type', 'status', ...]`.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class JobState {

	/**
	 * Default cap on the active-jobs list of the Jobs page (`perflocale/jobs/active_index_max`, floor 10).
	 */
	public const MAX_ACTIVE = 50;

	/**
	 * Age after which the daily GC prunes complete, failed and canceled rows.
	 */
	public const HISTORY_TTL = DAY_IN_SECONDS;

	/**
	 * How long a queued or running job may go without an `updated_at` bump before the GC declares it stuck.
	 */
	public const STUCK_TIMEOUT = 6 * HOUR_IN_SECONDS;

	/**
	 * Seconds a running job must go without a status update before worker_gone() considers it: covers the moment
	 * between a scheduled event starting and its worker taking the job lock.
	 */
	public const WORKER_GONE_GRACE = 300;

	/**
	 * Times the watchdog schedules a queued job without a worker event again (watchdog()).
	 */
	private const REQUEUE_MAX = 3;

	/**
	 * Cap on the JSON-encoded `result` payload before truncation.
	 */
	public const MAX_RESULT_BYTES = 65536;

	/**
	 * Key in `result` of the resume state of a job that resumes after a kill (AbstractJob::resumes_after_kill()); it
	 * marks the result as a checkpoint to keep on a retry or a requeue.
	 */
	public const RESUME_KEY = '__perflocale_resume';

	/**
	 * Key in a queued row's `result` counting the watchdog's reschedules (REQUEUE_MAX); not part of any job's result.
	 */
	public const REQUEUES_KEY = '__perflocale_requeues';

	/**
	 * Cap on the `error` field length.
	 */
	public const MAX_ERROR_BYTES = 2000;

	/**
	 * Ring-buffer size for `log` entries.
	 */
	public const MAX_LOG_ENTRIES = 20;

	/**
	 * Compare-and-set retries of a read-modify-write (append_log()).
	 */
	private const CAS_MAX_RETRIES = 5;

	/**
	 * Statuses no later write downgrades.
	 */
	private const TERMINAL_STATUSES = [ 'complete', 'failed', 'canceled' ];

	/**
	 * Job type => step for a run that ends outside its execute() (cancel, failure, dead worker).
	 *
	 * @var array<string, callable>
	 */
	private static array $run_end = [
		'bulk_string_translate' => [ Jobs\BulkStringTranslateJob::class, 'publish_after_run' ],
		'auto_translate'        => [ Jobs\AutoTranslateJob::class, 'run_ended' ],
	];

	/**
	 * Whether a status is terminal (the job is done and must not run again); shared with the dispatch and worker
	 * guards.
	 *
	 * @param string $status Job status.
	 * @return bool
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, self::TERMINAL_STATUSES, true );
	}

	/**
	 * The active-jobs cap from its filter, clamped to a floor.
	 *
	 * @return int
	 */
	public static function active_index_max(): int {
		/**
		 * Cap on the active-jobs list. Default 50.
		 *
		 * @hook perflocale/jobs/active_index_max
		 * @param int $max Default 50.
		 */
		$max = (int) apply_filters( 'perflocale/jobs/active_index_max', self::MAX_ACTIVE );
		return max( 10, $max );
	}

	/**
	 * Create a new job row.
	 *
	 * @param string               $job_id     UUID v4.
	 * @param string               $type       Type slug.
	 * @param array<string, mixed> $args       Worker args (JSON-encoded).
	 * @param int                  $created_by Dispatcher user ID.
	 * @param string               $hook       Worker hook name.
	 * @param string               $engine     'action_scheduler' | 'wp_cron'.
	 * @return bool True on insert; false on UUID collision.
	 */
	public static function create( string $job_id, string $type, array $args, int $created_by, string $hook, string $engine ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i
				(uuid, type, hook, engine, status, progress, total, processed,
				 attempts, created_by, blog_id, version, created_at, updated_at,
				 args, result, error, log)
			 VALUES (%s, %s, %s, %s, 'queued', 0, 0, 0,
				 0, %d, %d, 0, %s, %s,
				 %s, '[]', '', '[]')",
				$table,
				$job_id,
				$type,
				$hook,
				$engine,
				$created_by,
				function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				$now,
				$now,
				wp_json_encode( $args ) ?: '[]'
			)
		);

		if ( (int) $inserted !== 1 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for UUID collision; rare error path.
			error_log(
				sprintf(
					'[PerfLocale] JobState::create UUID collision for %s - dispatcher should retry with a fresh ID',
					$job_id
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Fetch a full job row by UUID, or null if unknown.
	 *
	 * @param string $job_id UUID.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $job_id ): ?array {
		if ( ! self::is_safe_id( $job_id ) ) {
			return null;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s LIMIT 1', $table, $job_id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Alias of get(), kept for the API: reads are from the database, with no cache to bust.
	 *
	 * @param string $job_id
	 * @return array<string, mixed>|null
	 */
	public static function get_fresh( string $job_id ): ?array {
		return self::get( $job_id );
	}

	/**
	 * A job's status and attempts, read past every cache: the worker's ownership check.
	 *
	 * @param string $job_id UUID.
	 * @return array{status: string, attempts: int}|null Null when the row is gone or the read failed.
	 * @phpstan-impure
	 */
	public static function owner( string $job_id ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! self::is_safe_id( $job_id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$row = $wpdb->get_row(
			(string) $wpdb->prepare( 'SELECT status, attempts FROM %i WHERE uuid = %s LIMIT 1', Schema::table( 'jobs' ), $job_id ),
			ARRAY_A
		);

		$status   = is_array( $row ) ? ( $row['status'] ?? null ) : null;
		$attempts = is_array( $row ) ? ( $row['attempts'] ?? null ) : null;

		// wpdb returns strings: attempts is compared by type with the run's generation.
		return is_string( $status ) && is_numeric( $attempts ) ? [
			'status'   => $status,
			'attempts' => (int) $attempts,
		] : null;
	}

	/**
	 * The decoded `result` of each given job, keyed by UUID: reads `uuid` and `result` only, at most 50 ids per
	 * statement; an unsafe or unknown id has no entry.
	 *
	 * @internal For the admin Jobs page; not part of the add-on API.
	 *
	 * @param array<int|string> $job_ids UUIDs.
	 * @return array<string, array<int|string, mixed>> UUID => result; [] when a read fails.
	 */
	public static function results( array $job_ids ): array {
		return self::json_column( $job_ids, 'result' );
	}

	/**
	 * The decoded `args` of each given job, keyed by UUID, as results().
	 *
	 * @internal For the admin Jobs page; not part of the add-on API.
	 *
	 * @param array<int|string> $job_ids UUIDs.
	 * @return array<string, array<int|string, mixed>> UUID => args; [] when a read fails.
	 */
	public static function args( array $job_ids ): array {
		return self::json_column( $job_ids, 'args' );
	}

	/**
	 * One decoded JSON column of each given job, keyed by UUID, at most 50 ids per statement.
	 *
	 * @param array<int|string> $job_ids UUIDs.
	 * @param string            $column  'result' or 'args'.
	 * @return array<string, array<int|string, mixed>>
	 */
	private static function json_column( array $job_ids, string $column ): array {
		$column = 'args' === $column ? 'args' : 'result';

		$ids = array_values( array_unique( array_filter( array_map( 'strval', $job_ids ), [ self::class, 'is_safe_id' ] ) ) );

		if ( [] === $ids ) {
			return [];
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		$table = Schema::table( 'jobs' );
		$out   = [];

		foreach ( array_chunk( $ids, 50 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- $table is Schema::table('jobs'), bound via %i; $placeholders is one %s per id; $column is 'result' or 'args'.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT uuid, {$column} FROM %i WHERE uuid IN ({$placeholders})",
					array_merge( [ $table ], $chunk )
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || self::last_read_failed() ) {
				return [];
			}

			foreach ( $rows as $row ) {
				if ( isset( $row['uuid'] ) && is_string( $row['uuid'] ) ) {
					$out[ $row['uuid'] ] = self::decode_json_array( $row[ $column ] ?? '[]' );
				}
			}
		}

		return $out;
	}

	/**
	 * An identical job still queued or running on this blog: same type and byte-identical stored args, so other work of
	 * the type runs in parallel and a chain whose cursor advances is never its own repeat. Uses `type_status`.
	 *
	 * @param string              $type Job type slug.
	 * @param array<mixed, mixed> $args Worker args as passed to create().
	 * @return string|null UUID of the in-flight twin, or null.
	 */
	public static function find_active_duplicate( string $type, array $args ): ?string {
		global $wpdb;

		$encoded = wp_json_encode( $args );

		if ( ! is_string( $encoded ) ) {
			return null;
		}

		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$uuid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT uuid FROM %i
			 WHERE type = %s
			   AND status IN ('queued', 'running')
			   AND blog_id = %d
			   AND args = %s
			 ORDER BY id ASC
			 LIMIT 1",
				$table,
				$type,
				function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				$encoded
			)
		);

		return is_string( $uuid ) && $uuid !== '' ? $uuid : null;
	}

	/**
	 * The oldest queued job of a type on this blog that never ran (the `type_status` index), or null.
	 *
	 * @param string $type   Job type slug.
	 * @param string $except A job left out.
	 * @return array<string, mixed>|null Fresh row.
	 */
	public static function oldest_queued( string $type, string $except = '' ): ?array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$uuid = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT uuid FROM %i WHERE type = %s AND status = %s AND attempts = %d AND blog_id = %d AND uuid != %s ORDER BY id ASC LIMIT 1',
				Schema::table( 'jobs' ),
				$type,
				'queued',
				0,
				function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				$except
			)
		);

		return is_string( $uuid ) && '' !== $uuid ? self::get_fresh( $uuid ) : null;
	}

	/**
	 * Move a queued job to `running` (started_at, attempts + 1). False when this call did not: the write failed or the
	 * row was not queued (canceled, claimed by another worker, gone), and the caller must not run the job, or it would
	 * spend the provider and write while the row still says queued.
	 *
	 * @param string $job_id UUID.
	 * @param int    $owner    The dispatcher (`created_by`) the caller authorized: only their row is claimed (-1: any).
	 * @param int    $version  The row version the caller read: claimed only while unchanged (-1: any).
	 * @param bool   $refused  Set true when the statement failed (not merely matched no row).
	 * @return bool True when the row moved queued -> running.
	 */
	public static function mark_running( string $job_id, int $owner = -1, int $version = -1, bool &$refused = false ): bool {
		$refused = false;

		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// Only from queued: a running row keeps the attempts its lock-owning worker set.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'running',
			     started_at = %s,
			     updated_at = %s,
			     attempts = attempts + 1,
			     version = version + 1
			 WHERE uuid = %s AND status = 'queued' AND ( %d < 0 OR created_by = %d ) AND ( %d < 0 OR version = %d )",
				$table,
				$now,
				$now,
				$job_id,
				$owner,
				$owner,
				$version,
				$version
			)
		);

		// `false` is a query error; 0 is "no row was queued". Either way the caller does not own the job.
		$refused = false === $affected;

		return 1 === (int) $affected;
	}

	/**
	 * Whether the worker of a running job is gone: no status update for WORKER_GONE_GRACE seconds and its JobLock row
	 * expired, or no lock row (swept) and nothing scheduled. A killed worker (SIGKILL, out of memory, an FPM restart)
	 * never releases its lock. Reads the lock row from the database, not the options cache.
	 *
	 * @param array<string, mixed> $state A row from get().
	 * @return bool
	 */
	public static function worker_gone( array $state ): bool {
		$job_id = (string) ( $state['id'] ?? '' );

		if ( 'running' !== (string) ( $state['status'] ?? '' ) || ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		if ( (int) ( $state['updated_at'] ?? 0 ) > time() - self::WORKER_GONE_GRACE ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock is read past the options cache, like JobLock's own compare-and-swap.
		$stored  = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, JobLock::PREFIX . $job_id )
		);
		$expires = (int) strtok( $stored, '|' );

		if ( $stored !== '' ) {
			return $expires > 0 && $expires <= time();
		}

		try {
			return ! JobRunnerFactory::for_engine( (string) ( $state['engine'] ?? '' ) )->is_scheduled( $job_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Mark a running job failed when its worker is gone (worker_gone()): the Jobs page offers Retry and an identical
	 * dispatch is not folded into it. The write lands only while the row is running with no newer status update.
	 *
	 * @param string $job_id UUID.
	 * @return bool True when this call marked the job failed.
	 */
	public static function fail_if_worker_gone( string $job_id ): bool {
		$state = self::get( $job_id );

		if ( ! $state || ! self::worker_gone( $state ) ) {
			return false;
		}

		if ( self::resumes( $state ) ) {
			self::requeue_gone( $state );
			return false;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'failed',
			     completed_at = %s,
			     updated_at = %s,
			     error = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'running' AND updated_at <= %s",
				Schema::table( 'jobs' ),
				$now,
				$now,
				self::truncate_error( __( 'The worker stopped without finishing: its lock expired and nothing continued the job. The job was marked failed; run it again or retry it from the Jobs page.', 'perflocale' ) ),
				$job_id,
				gmdate( 'Y-m-d H:i:s', time() - self::WORKER_GONE_GRACE )
			)
		);

		if ( 1 !== (int) $affected ) {
			return false;
		}

		self::run_ended( $job_id );

		return true;
	}

	/**
	 * Whether a row's result is a checkpoint of a job that resumes after a kill.
	 *
	 * @param array<string, mixed> $state A row from get().
	 * @return bool
	 */
	private static function resumes( array $state ): bool {
		$result = $state['result'] ?? null;

		return is_array( $result ) && is_array( $result[ self::RESUME_KEY ] ?? null );
	}

	/**
	 * Put a running job whose worker is gone back in the queue with its checkpoint, and schedule it now. The claim
	 * counts the attempt and the worker counts the kill (JobResume::open()).
	 *
	 * @param array<string, mixed> $state A row from get().
	 * @return bool True when this call requeued the job.
	 */
	private static function requeue_gone( array $state ): bool {
		global $wpdb;
		$job_id = is_string( $state['id'] ?? null ) ? $state['id'] : '';
		$hook   = is_string( $state['hook'] ?? null ) ? $state['hook'] : '';
		$args   = is_array( $state['args'] ?? null ) ? JobResume::keyed( $state['args'] ) : [];
		$blog   = is_int( $state['blog_id'] ?? null ) ? $state['blog_id'] : 0;

		if ( ! $wpdb instanceof \wpdb || ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			(string) $wpdb->prepare(
				"UPDATE %i
			 SET status = 'queued',
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'running' AND updated_at <= %s",
				Schema::table( 'jobs' ),
				current_time( 'mysql', true ),
				$job_id,
				gmdate( 'Y-m-d H:i:s', time() - self::WORKER_GONE_GRACE )
			)
		);

		if ( 1 !== $affected ) {
			return false;
		}

		self::append_log( $job_id, __( 'The background worker stopped; the job was queued again and continues from its last checkpoint.', 'perflocale' ), 'requeued' );

		if ( ! JobResume::reschedule( $job_id, $hook, WorkerRegistry::with_blog_sentinel( $args, $blog ), time(), is_string( $state['engine'] ?? null ) ? $state['engine'] : '' ) ) {
			self::fail_queued( $job_id, __( 'The worker stopped before finishing.', 'perflocale' ) );
		}

		return true;
	}

	/**
	 * Store the result of a running job at the start of a slice, counting an attempt when asked. A result over the cap
	 * is left out, the attempt still counted (the generation must move).
	 *
	 * @param string               $job_id        UUID.
	 * @param array<string, mixed> $result        Result (a checkpoint with its resume state).
	 * @param bool                 $count_attempt Add one to `attempts`.
	 * @param int                  $generation    The writing run's `attempts`, or -1 for any.
	 * @return bool True when the row was updated.
	 */
	public static function store_resume( string $job_id, array $result, bool $count_attempt, int $generation = -1 ): bool {
		global $wpdb;
		$json = wp_json_encode( $result );

		if ( ! $wpdb instanceof \wpdb || ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		$store = is_string( $json ) && strlen( $json ) <= self::MAX_RESULT_BYTES;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			(string) $wpdb->prepare(
				"UPDATE %i
			 SET result = CASE WHEN %d = 1 THEN %s ELSE result END,
			     attempts = attempts + %d,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'running' AND ( %d < 0 OR attempts = %d )",
				Schema::table( 'jobs' ),
				$store ? 1 : 0,
				$store ? $json : '',
				$count_attempt ? 1 : 0,
				current_time( 'mysql', true ),
				$job_id,
				$generation,
				$generation
			)
		);

		return 1 === $affected;
	}

	/**
	 * Update progress counters, status-guarded so a tick cannot resurrect a terminal row. The percent is
	 * `processed / total`, or `$percent` from a job whose counters cover one stage; below 100 until the job completes.
	 *
	 * @param string                    $job_id     UUID.
	 * @param int                       $processed  Items processed.
	 * @param int                       $total      Total items (0 = indeterminate).
	 * @param int                       $percent    Overall percent, or -1 to compute it from the counters.
	 * @param array<string, mixed>|null $checkpoint Stored as `result` in the same UPDATE; one over MAX_RESULT_BYTES is left out.
	 * @param int                       $generation The writing run's `attempts`, or -1 for any.
	 * @return bool True when the row was updated.
	 * @phpstan-impure
	 */
	public static function update_progress( string $job_id, int $processed, int $total, int $percent = -1, ?array $checkpoint = null, int $generation = -1 ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		$processed = max( 0, $processed );
		$total     = max( 0, $total );

		if ( $percent >= 0 ) {
			$progress = min( 99, $percent );
		} else {
			$progress = ( $total > 0 ) ? (int) min( 99, floor( $processed * 100 / $total ) ) : 0;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );
		$json  = null !== $checkpoint ? wp_json_encode( $checkpoint ) : false;

		// The checkpoint rides in the progress write; one over the cap is left out (the previous one stays).
		$store = is_string( $json ) && strlen( $json ) <= self::MAX_RESULT_BYTES;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET processed = %d,
			     total = %d,
			     progress = %d,
			     result = CASE WHEN %d = 1 THEN %s ELSE result END,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'running' AND ( %d < 0 OR attempts = %d )",
				$table,
				$processed,
				$total,
				$progress,
				$store ? 1 : 0,
				$store ? $json : '',
				$now,
				$job_id,
				$generation,
				$generation
			)
		);

		return 1 === $affected;
	}

	/**
	 * Mark a running job complete (progress 100, completed_at). False when this call did not: the write failed or the
	 * row was not running (canceled, finished, failed by the watchdog, gone); the worker fires
	 * `perflocale/jobs/completed` only on true.
	 *
	 * @param string               $job_id UUID.
	 * @param array<string, mixed> $result     Worker output (truncated to MAX_RESULT_BYTES).
	 * @param int                  $generation The writing run's `attempts`, or -1 for any.
	 * @return bool True when the row moved running -> complete.
	 */
	public static function mark_complete( string $job_id, array $result = [], int $generation = -1 ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		$result_json = wp_json_encode( self::truncate_result( $result ) );
		if ( $result_json === false ) {
			$result_json = '[]';
		}

		// Only from `running`: a row canceled mid-flight stays canceled.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'complete',
			     progress = 100,
			     completed_at = %s,
			     updated_at = %s,
			     result = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'running' AND ( %d < 0 OR attempts = %d )",
				$table,
				$now,
				$now,
				$result_json,
				$job_id,
				$generation,
				$generation
			)
		);

		// `false` is a query error; 0 is "the row was not running".
		return 1 === (int) $affected;
	}

	/**
	 * Store a result on a job that ended without completing (a cancel's or a failed run's counts). Complete and queued
	 * rows are left alone: a retry resets the row, and mark_complete() owns a finished job's result.
	 *
	 * @param string               $job_id UUID.
	 * @param array<string, mixed> $result     Result payload (truncated like mark_complete()).
	 * @param int                  $generation The writing run's `attempts`, or -1 for any.
	 * @return bool True when a row was updated.
	 */
	public static function record_result( string $job_id, array $result, int $generation = -1 ): bool {
		if ( ! self::is_safe_id( $job_id ) || $result === [] ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );

		$result_json = wp_json_encode( self::truncate_result( $result ) );
		if ( $result_json === false ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET result = %s,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status IN ('canceled', 'running', 'failed') AND ( %d < 0 OR attempts = %d )",
				$table,
				$result_json,
				current_time( 'mysql', true ),
				$job_id,
				$generation,
				$generation
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Mark a queued/running job failed.
	 *
	 * @param string $job_id     UUID.
	 * @param string $error      Human-readable error message (truncated).
	 * @param int    $generation The failing run's `attempts` (then only its running row), or -1 for any.
	 * @return bool True when this call moved the row to `failed`. False when
	 *              the write failed or the row was not queued/running (a
	 *              cancel or a completion won, or the row is gone).
	 */
	public static function mark_failed( string $job_id, string $error, int $generation = -1 ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// From queued or running only: a cancel or completion, also terminal, wins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'failed',
			     completed_at = %s,
			     updated_at = %s,
			     error = %s,
			     version = version + 1
			 WHERE uuid = %s AND status IN ('queued', 'running') AND ( %d < 0 OR attempts = %d ) AND ( %d < 0 OR status = 'running' )",
				$table,
				$now,
				$now,
				self::truncate_error( $error ),
				$job_id,
				$generation,
				$generation,
				$generation
			)
		);

		if ( 1 !== $affected ) {
			return false;
		}

		self::run_ended( $job_id );

		return true;
	}

	/**
	 * User-initiated cancel: the worker sees it in its progress callback and stops. Unscheduling is the runner's job
	 * (JobRunnerInterface::cancel()).
	 *
	 * @param string $job_id UUID.
	 * @return bool True when this call moved the row to `canceled`. False
	 *              when the write failed or the row was not queued/running.
	 */
	public static function cancel( string $job_id ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// Only queued or running rows can be canceled; a terminal row is a no-op.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'canceled',
			     completed_at = %s,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status IN ('queued', 'running')",
				$table,
				$now,
				$now,
				$job_id
			)
		);

		if ( 1 !== (int) $affected ) {
			return false;
		}

		self::run_ended( $job_id );

		return true;
	}

	/**
	 * Fail a job an operator retry reset to queued when its worker event could not be scheduled, so it is retryable at
	 * once. Only a still-queued row (a row a worker claimed meanwhile keeps running), and with `$version` only that
	 * version of it.
	 *
	 * @param string $job_id  UUID.
	 * @param string $error   Human-readable error message (truncated).
	 * @param int    $version Row version the caller read, or -1 for any.
	 * @return bool True when the row moved queued -> failed.
	 */
	public static function fail_queued( string $job_id, string $error, int $version = -1 ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'failed',
			     completed_at = %s,
			     updated_at = %s,
			     error = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'queued' AND ( %d < 0 OR version = %d )",
				$table,
				$now,
				$now,
				self::truncate_error( $error ),
				$job_id,
				$version,
				$version
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Reset a failed/canceled job to queued so the caller can re-enqueue.
	 *
	 * @param string $job_id         UUID.
	 * @param bool   $allow_canceled Whether a canceled job may be reset. Only
	 *                               operator-initiated retries (CLI/REST) pass
	 *                               true; automatic callers leave it false.
	 * @param int    $generation     The failed run's `attempts` (automatic retry), or -1 for any.
	 * @param int    $owner          User who becomes the job's dispatcher (`created_by`), or -1 to keep it.
	 * @param int    $version        Row version the caller checked (only that failed/canceled row is reset), or -1.
	 * @return bool True if the row existed and was reset.
	 */
	public static function reset_for_retry( string $job_id, bool $allow_canceled = false, int $generation = -1, int $owner = -1, int $version = -1 ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		// A running row reset here lost its worker: read it first for its end-of-run step.
		$before = self::get( $job_id );

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// Allow-list of source statuses, so two concurrent retries cannot both win: 'running' (Resumer), 'failed'
		// (WorkerRunner's auto-retry), 'canceled' (operator only); never 'complete' or 'queued'.
		// Why: docs-ai/11-code-rationale.md, "JobState: retry sources".
		$allowed_statuses = $allow_canceled ? "'failed', 'running', 'canceled'" : "'failed', 'running'";

		// A retry checked against one row version never resets a newer state of it, a running one least of all.
		if ( $version >= 0 ) {
			$allowed_statuses = $allow_canceled ? "'failed', 'canceled'" : "'failed'";
		}

		// The new owner is written with the reset, so the worker never runs the job as the old dispatcher.
		$owner_set = $owner > 0 ? ', created_by = ' . $owner : '';

		// A checkpoint of a job that resumes after a kill is kept, closed and with no kills counted.
		$result = '[]';

		if ( is_array( $before ) && self::resumes( $before ) && is_array( $before['result'] ?? null ) ) {
			$kept = $before['result'];
			unset( $kept[ self::REQUEUES_KEY ] );
			$kept[ self::RESUME_KEY ] = [
				'open'  => false,
				'kills' => 0,
				'at'    => -1,
				'unit'  => '',
			];
			$result                   = wp_json_encode( $kept );
			$result                   = is_string( $result ) && strlen( $result ) <= self::MAX_RESULT_BYTES ? $result : '[]';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'); $allowed_statuses is a hardcoded literal list, $owner_set an int column write, never user input.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'queued'{$owner_set},
			     started_at = NULL,
			     completed_at = NULL,
			     progress = 0,
			     processed = 0,
			     total = 0,
			     error = '',
			     result = %s,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status IN ({$allowed_statuses}) AND ( %d < 0 OR attempts = %d ) AND ( %d < 0 OR version = %d )",
				$table,
				$result,
				$now,
				$job_id,
				$generation,
				$generation,
				$version,
				$version
			)
		);

		if ( (int) $affected !== 1 ) {
			return false;
		}

		if ( is_array( $before ) && 'running' === ( $before['status'] ?? '' ) ) {
			self::run_ended( $job_id, $before );
		}

		return true;
	}

	/**
	 * Append a log entry to the job's ring buffer (last 20), in a compare-and-set loop on `log` and `version` so
	 * concurrent appends never lose each other.
	 *
	 * @param string $job_id  UUID.
	 * @param string $message Log message (truncated to 500 chars).
	 * @param string $key     Optional fixed key stored as `k`, so code recognizes its own line in any language.
	 * @return void
	 */
	public static function append_log( string $job_id, string $message, string $key = '' ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );

		$entry = [
			't' => time(),
			// mb_substr(): a byte cut can split a multibyte character, which wp_json_encode() then mangles or, without
			// mbstring, drops the whole message.
			'm' => mb_substr( $message, 0, 500 ),
		];

		if ( '' !== $key ) {
			$entry['k'] = $key;
		}

		for ( $attempt = 0; $attempt < self::CAS_MAX_RETRIES; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT log, version FROM %i WHERE uuid = %s LIMIT 1',
					$table,
					$job_id
				),
				ARRAY_A
			);

			if ( ! is_array( $row ) ) {
				return;
			}

			$log_raw = is_string( $row['log'] ) ? $row['log'] : '[]';
			$log     = json_decode( $log_raw, true );
			if ( ! is_array( $log ) ) {
				$log = [];
			}
			$log[] = $entry;
			if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
				$log = array_slice( $log, -self::MAX_LOG_ENTRIES );
			}

			$encoded = wp_json_encode( array_values( $log ) );
			if ( $encoded === false ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i
				 SET log = %s,
				     updated_at = %s,
				     version = version + 1
				 WHERE uuid = %s AND version = %d',
					$table,
					$encoded,
					current_time( 'mysql', true ),
					$job_id,
					(int) $row['version']
				)
			);

			if ( (int) $affected === 1 ) {
				return;
			}

			// Another writer won: back off (exponential plus jitter) before re-reading, so workers fan out instead of
			// spinning on the row; no sleep after the last attempt.
			if ( $attempt < self::CAS_MAX_RETRIES - 1 ) {
				usleep( random_int( 1000, 5000 ) * ( $attempt + 1 ) );
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for CAS-loop exhaustion; rare error path.
		error_log(
			sprintf(
				'[PerfLocale] JobState::append_log CAS retries exhausted for job %s',
				$job_id
			)
		);
	}

	/**
	 * Whether any job row on the current blog is running, worker alive or gone (the failure-period filter uses
	 * has_live_job_in_flight()). Not memoized: the answer changes over time and across blogs, and a call costs 0.04 ms.
	 * Why: docs-ai/11-code-rationale.md, "JobState: the in-flight answers".
	 *
	 * @return bool True when at least one job row on the CURRENT blog is `running`.
	 */
	public static function has_long_job_in_flight(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job state is not cacheable: a cached answer is exactly the bug this replaced.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE status = %s LIMIT 1',
				Schema::table( 'jobs' ),
				'running'
			)
		);

		return null !== $found;
	}

	/**
	 * Whether a job runs with a live worker (a status update within WORKER_GONE_GRACE, or an unexpired JobLock): scopes
	 * the store-wide `action_scheduler_failure_period` filter, so other plugins keep Action Scheduler's cadence. A
	 * failed read answers true. Why: docs-ai/11-code-rationale.md, "JobState: the in-flight answers".
	 *
	 * @return bool True when a job on the CURRENT blog is running with a live worker.
	 */
	public static function has_live_job_in_flight(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Job state is not cacheable; the table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT uuid, updated_at FROM %i WHERE status = %s ORDER BY updated_at DESC LIMIT 50',
				Schema::table( 'jobs' ),
				'running'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || self::last_read_failed() ) {
			return true;
		}

		// `Y-m-d H:i:s` UTC strings compare in time order.
		$fresh_after = gmdate( 'Y-m-d H:i:s', time() - self::WORKER_GONE_GRACE );
		$lock_names  = [];

		foreach ( $rows as $row ) {
			$updated = $row['updated_at'] ?? null;
			$uuid    = $row['uuid'] ?? null;

			if ( is_string( $updated ) && $updated > $fresh_after ) {
				return true;
			}

			if ( is_string( $uuid ) ) {
				$lock_names[] = JobLock::PREFIX . $uuid;
			}
		}

		if ( [] === $lock_names ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $lock_names ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- Lock rows are read past the options cache, like JobLock's own compare-and-swap; $placeholders is one %s per name.
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_value FROM %i WHERE option_name IN ({$placeholders})",
				array_merge( [ $wpdb->options ], $lock_names )
			)
		);

		if ( self::last_read_failed() ) {
			return true;
		}

		$now = time();

		foreach ( $values as $value ) {
			if ( is_string( $value ) && (int) strtok( $value, '|' ) > $now ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The active-jobs list newest-first, capped at active_index_max() (`status_updated` covers the ORDER BY). $status
	 * filters in SQL before the cap, so a status found only beyond the newest rows is still returned.
	 *
	 * @param string $status Optional exact status filter ('' = no filter).
	 * @return array<string, array<string, mixed>>  uuid → row
	 */
	public static function list_active( string $status = '' ): array {
		global $wpdb;
		$table = Schema::table( 'jobs' );
		$cap   = self::active_index_max();

		if ( $status !== '' ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
				 WHERE status = %s
				 ORDER BY updated_at DESC, id DESC
				 LIMIT %d',
					$table,
					$status,
					$cap
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i
				 ORDER BY updated_at DESC, id DESC
				 LIMIT %d',
					$table,
					$cap
				),
				ARRAY_A
			);
		}

		$out = [];
		foreach ( (array) $rows as $row ) {
			$h               = self::hydrate( $row );
			$out[ $h['id'] ] = $h;
		}
		return $out;
	}

	/**
	 * Number of jobs in each given status on the current blog: one grouped COUNT on `status_updated`, 0 for a status no
	 * row has.
	 *
	 * @internal For the network Jobs overview and Site Health; not part of the add-on API.
	 *
	 * @param array<int, string> $statuses Statuses to count.
	 * @return array<string, int>|null Status => count; null when the read fails.
	 */
	public static function count_by_status( array $statuses ): ?array {
		$statuses = array_values( array_unique( array_map( 'strval', $statuses ) ) );
		$out      = array_fill_keys( $statuses, 0 );

		if ( [] === $statuses ) {
			return $out;
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- $table is Schema::table('jobs'), bound via %i; $placeholders is one %s per status.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM %i WHERE status IN ({$placeholders}) GROUP BY status",
				array_merge( [ Schema::table( 'jobs' ) ], $statuses )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || self::last_read_failed() ) {
			return null;
		}

		foreach ( $rows as $row ) {
			$status = $row['status'] ?? null;
			$count  = $row['n'] ?? null;

			if ( is_string( $status ) && isset( $out[ $status ] ) && is_numeric( $count ) ) {
				$out[ $status ] = (int) $count;
			}
		}

		return $out;
	}

	/**
	 * Every queued or running job, oldest update first, for the Resumer: list_active() caps at the display window, so
	 * an old pending job beyond it would be dropped after a deactivate and reactivate.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_resumable(): array {
		global $wpdb;
		$table = Schema::table( 'jobs' );

		/**
		 * Most jobs resumed in one sweep: far above the display cap, bounded so a runaway table cannot exhaust memory.
		 *
		 * @hook perflocale/jobs/resume_max
		 * @param int $max Default 1000.
		 */
		$cap = max( 1, (int) apply_filters( 'perflocale/jobs/resume_max', 1000 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
			 WHERE status IN ('queued', 'running')
			 ORDER BY updated_at ASC, id ASC
			 LIMIT %d",
				$table,
				$cap
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$h               = self::hydrate( $row );
			$out[ $h['id'] ] = $h;
		}
		return $out;
	}

	/**
	 * The list_active() rows without the args, result and log columns (`error` is kept, shown beside the status), for
	 * the polled REST endpoint and the Jobs page. A running row also carries `stage`, the key of its worker's last
	 * `stage:<key>` log line (WorkerRunner). Callers that need full rows use list_active().
	 *
	 * @return array<string, array<string, mixed>>  uuid → row (without args/result/log)
	 */
	public static function list_active_summary(): array {
		global $wpdb;
		$table = Schema::table( 'jobs' );
		$cap   = self::active_index_max();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, uuid, type, hook, engine, status, progress, total, processed,
				        attempts, created_by, blog_id, version, created_at, started_at,
				        completed_at, updated_at, error,
				        CASE WHEN status = %s THEN log ELSE %s END AS stage_log
				 FROM %i
				 ORDER BY updated_at DESC, id DESC
				 LIMIT %d',
				'running',
				'',
				$table,
				$cap
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$stage_log = (string) ( $row['stage_log'] ?? '' );
			unset( $row['stage_log'] );

			// The omitted columns as empty placeholders, so every row has hydrate()'s keys.
			$row['args']   = '';
			$row['result'] = '';
			$row['log']    = '';

			$h               = self::hydrate( $row );
			$h['stage']      = self::current_stage( $stage_log );
			$out[ $h['id'] ] = $h;
		}
		return $out;
	}

	/**
	 * The key of the last stage a job's log records, or ''.
	 *
	 * @param string $log_json The job's `log` column.
	 * @return string
	 */
	private static function current_stage( string $log_json ): string {
		if ( $log_json === '' ) {
			return '';
		}

		$log = json_decode( $log_json, true );

		if ( ! is_array( $log ) ) {
			return '';
		}

		foreach ( array_reverse( $log ) as $entry ) {
			$key = is_array( $entry ) && isset( $entry['k'] ) ? (string) $entry['k'] : '';

			if ( str_starts_with( $key, 'stage:' ) ) {
				return sanitize_key( substr( $key, 6 ) );
			}
		}

		return '';
	}

	/**
	 * Permanently remove a job row.
	 *
	 * @param string $job_id UUID.
	 * @return void
	 */
	public static function delete( string $job_id ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		// The artifact goes before the row, the only record of its path; the row goes even when the unlink fails (the
		// orphan is logged, and the 7-day sweep removes it). Why: docs-ai/11-code-rationale.md, "JobState: export
		// cleanup".
		if ( ! self::delete_owned_artifact( $job_id ) ) {
			self::log_orphan_artifact( $job_id, self::get( $job_id ) );
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled.
		$wpdb->delete( $table, [ 'uuid' => $job_id ], [ '%s' ] );
	}

	/**
	 * Delete a job only while it is still finished (complete, failed or canceled) at the DELETE. The row goes first, by
	 * its read version, so a Retry or a worker claim after the read keeps it; the artifact then goes from that read.
	 *
	 * @param string $job_id UUID.
	 * @return bool False when the job is not finished; true when it was deleted or is gone.
	 */
	public static function delete_finished( string $job_id ): bool {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$table = Schema::table( 'jobs' );

		for ( $try = 0; $try < 3; $try++ ) {
			$state = self::get( $job_id );

			if ( null === $state ) {
				return true;
			}

			$status  = $state['status'] ?? '';
			$version = $state['version'] ?? 0;

			if ( ! is_string( $status ) || ! self::is_terminal( $status ) || ! is_int( $version ) ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
			$deleted = $wpdb->query(
				(string) $wpdb->prepare(
					"DELETE FROM %i WHERE uuid = %s AND status IN ('complete', 'failed', 'canceled') AND version = %d",
					$table,
					$job_id,
					$version
				)
			);

			if ( 1 === $deleted ) {
				if ( ! self::delete_owned_artifact( $job_id, $state ) ) {
					self::log_orphan_artifact( $job_id, $state );
				}
				return true;
			}
		}

		return false;
	}

	/**
	 * Log a deleted job's export file that could not be removed.
	 *
	 * @param string                    $job_id       UUID.
	 * @param array<string, mixed>|null $orphan_state The job's row as read before the delete.
	 * @return void
	 */
	private static function log_orphan_artifact( string $job_id, ?array $orphan_state ): void {
		$orphan_result = is_array( $orphan_state ) ? (array) ( $orphan_state['result'] ?? [] ) : [];
		$orphan_path   = (string) ( $orphan_result['path'] ?? '' );

		if ( '' !== $orphan_path && file_exists( $orphan_path ) ) {
			// Never the path or its name: the random suffix keeps the export private and logs are widely read.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'PerfLocale JobState: job %s deleted but its export artifact was NOT removed (path digest %s). It is now unowned and will be swept by age. Check directory permissions, or whether the file at that path was replaced.',
					$job_id,
					substr( hash( 'sha256', $orphan_path ), 0, 12 )
				)
			);
		}
	}

	/**
	 * Delete the export file a finished job owns, if it still exists: only a path the job recorded, inside the plugin's
	 * exports dir, never a hardening file, and only the file it created. Anything unproven is left to the age sweep.
	 *
	 * @param string                    $job_id Job UUID.
	 * @param array<string, mixed>|null $state  The job's row when the caller already read it (and has since deleted it); null reads it.
	 * @return bool True when a file was removed.
	 */
	private static function delete_owned_artifact( string $job_id, ?array $state = null ): bool {
		$state = $state ?? self::get( $job_id );

		if ( ! is_array( $state ) ) {
			return false;
		}

		$result = (array) ( $state['result'] ?? [] );
		$path   = (string) ( $result['path'] ?? '' );

		if ( '' === $path || str_contains( $path, "\0" ) ) {
			return false;
		}

		$exports = \PerfLocale\Helper::uploads_exports_dir();

		if ( '' === $exports ) {
			return false;
		}

		$real_dir = realpath( $exports );

		if ( false === $real_dir ) {
			return false;
		}

		// Resolve the parent directory, never the file: realpath() of the file could follow a symlink to another
		// export.
		$real_parent = realpath( dirname( $path ) );
		$base        = basename( $path );

		if ( false === $real_parent || $real_parent !== $real_dir || '' === $base ) {
			return false;
		}

		// The exporter never writes these names, and unlinking one would strip the directory's access controls.
		if ( in_array( $base, [ '.htaccess', 'index.php', 'web.config' ], true ) ) {
			return false;
		}

		$target = $real_parent . DIRECTORY_SEPARATOR . $base;

		// lstat(), not is_file() (which follows links): only a plain file, never a symlink, FIFO, socket, device or
		// dir.
		$st = @lstat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing artifact is the normal already-cleaned case, not an error.

		if ( ! is_array( $st ) || ! isset( $st['mode'] ) ) {
			return false;
		}

		// S_IFMT / S_IFREG. PHP exposes no portable constants for these.
		if ( 0100000 !== ( ( (int) $st['mode'] ) & 0170000 ) ) {
			return false;
		}

		// Identity binding: the entry at this path must still be the file the job created (inode and device), so a
		// newcomer at a reused path is never deleted.
		$want_ino = (string) ( $result['ino'] ?? '' );
		$want_dev = (string) ( $result['dev'] ?? '' );

		// A row with no recorded identity cannot prove it owns the file there now, so it never unlinks: the age sweep,
		// the unlink-failure backstop, removes the export later.
		if ( '' === $want_ino || '' === $want_dev ) {
			return false;
		}

		if ( (string) ( $st['ino'] ?? '' ) !== $want_ino || (string) ( $st['dev'] ?? '' ) !== $want_dev ) {
			return false;
		}

		wp_delete_file( $target );

		return ! file_exists( $target );
	}
	/**
	 * Zero `created_by` on every job the user dispatched (privacy erasure and user delete): one indexed UPDATE.
	 *
	 * @param int $user_id
	 * @return int Number of rows affected; 0 when the UPDATE fails.
	 */
	public static function anonymize_for_user( int $user_id ): int {
		return (int) self::try_anonymize_for_user( $user_id );
	}

	/**
	 * As anonymize_for_user(), but a failed UPDATE returns null, so the privacy eraser can tell "nothing to anonymize"
	 * from "could not anonymize".
	 *
	 * @internal
	 *
	 * @param int $user_id User whose jobs lose their `created_by`.
	 * @return int|null Number of rows affected, or null when the UPDATE failed.
	 */
	public static function try_anonymize_for_user( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
			 SET created_by = 0,
			     version = version + 1
			 WHERE created_by = %d',
				$table,
				$user_id
			)
		);

		// wpdb returns false on an error and when a `query` filter empties the statement (last_error untouched): the
		// return value is the only reliable signal.
		if ( false === $affected ) {
			return null;
		}

		return max( 0, (int) $affected );
	}

	/**
	 * Daily GC: prune terminal rows older than HISTORY_TTL and force-fail queued or running rows idle past
	 * STUCK_TIMEOUT (both range scans on `status_updated`). Finished `data_export` rows go first, one by one with their
	 * files.
	 *
	 * @return int Rows removed.
	 */
	public static function gc(): int {
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return 0;
		}

		$started = microtime( true );

		global $wpdb;
		$table = Schema::table( 'jobs' );

		$now           = time();
		$stuck_cutoff  = $now - (int) apply_filters( 'perflocale/jobs/stuck_timeout_seconds', self::STUCK_TIMEOUT );
		$stuck_mysql   = gmdate( 'Y-m-d H:i:s', $stuck_cutoff );
		$history_mysql = gmdate( 'Y-m-d H:i:s', $now - self::HISTORY_TTL );

		// Stuck jobs: each is probed against the runner of its own stored engine, so a long backoff event is not stuck
		// and the wrong runner never fails a job.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$stuck_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uuid FROM %i
			 WHERE status IN ('queued', 'running') AND updated_at < %s
			 LIMIT 500",
				$table,
				$stuck_mysql
			)
		);

		foreach ( $stuck_ids as $uuid ) {
			$uuid = (string) $uuid;

			$state = self::get( $uuid );
			if ( ! $state ) {
				continue;
			}

			// A killed job that resumes is requeued, as the watchdog does.
			if ( 'running' === ( $state['status'] ?? null ) && self::resumes( $state ) ) {
				self::fail_if_worker_gone( $uuid );
				continue;
			}

			// The runner of the job's own engine, not pick(): see watchdog().
			try {
				if ( JobRunnerFactory::for_engine( (string) ( $state['engine'] ?? '' ) )->is_scheduled( $uuid ) ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall through to force-fail without the probe.
			}

			$updated = (int) ( $state['updated_at'] ?? 0 );
			$stale_h = (int) ceil( ( $now - $updated ) / HOUR_IN_SECONDS );

			self::mark_failed(
				$uuid,
				sprintf(
				/* translators: %d is hours since last status update. */
					__( '[daily GC] Worker stalled or never ran (no status update in %d hours). Job was force-failed.', 'perflocale' ),
					$stale_h
				)
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-mode-gated diagnostic.
				error_log(
					sprintf(
						'[PerfLocale] daily GC force-failed stuck job %s (%dh stale)',
						$uuid,
						$stale_h
					)
				);
			}
		}

		// Finished exports first: the row is the only record of the file. Only a row this call deleted has its file
		// removed; a row changed since the read stays, with its file.
		$removed = self::gc_exports( $history_mysql );

		// Terminal-row pruning.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$removed += (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i
			 WHERE status IN ('complete', 'failed', 'canceled') AND updated_at < %s",
				$table,
				$history_mysql
			)
		);

		// Stale lock sweep - keeps wp_options clean of dead JobLock rows.
		self::sweep_expired_locks();

		\PerfLocale\Background\BackgroundEvents::record_run( 'perflocale_jobs_gc', $started );

		return $removed;
	}

	/**
	 * Delete finished export jobs older than the cutoff, with their files: at most 500 per call; gc()'s bulk prune
	 * takes the remaining rows and the daily age sweep the remaining files.
	 *
	 * @param string $cutoff GMT datetime; terminal rows last updated before it are deleted.
	 * @return int Rows deleted.
	 */
	private static function gc_exports( string $cutoff ): int {
		global $wpdb;
		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uuid FROM %i
			 WHERE type = %s AND status IN ('complete', 'failed', 'canceled') AND updated_at < %s
			 LIMIT 500",
				$table,
				'data_export',
				$cutoff
			)
		);

		$removed = 0;

		foreach ( $ids as $uuid ) {
			$uuid  = (string) $uuid;
			$state = self::is_safe_id( $uuid ) ? self::get_fresh( $uuid ) : null;

			if ( ! is_array( $state ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i
				 WHERE uuid = %s AND status IN ('complete', 'failed', 'canceled') AND updated_at < %s",
					$table,
					$uuid,
					$cutoff
				)
			);

			if ( 1 !== (int) $deleted ) {
				continue;
			}

			++$removed;
			self::delete_owned_artifact( $uuid, $state );
		}

		return $removed;
	}

	/**
	 * Switch into the target blog and run a recurring callback (Bootstrap's `gc` and `watchdog` actions).
	 *
	 * @param int      $target_blog Target blog id (0 = current blog).
	 * @param callable $callback    Handler.
	 * @return mixed
	 */
	public static function run_recurring_for_blog( int $target_blog, callable $callback ) {
		$switched = false;
		if ( $target_blog > 0
			&& function_exists( 'is_multisite' ) && is_multisite()
			&& function_exists( 'get_current_blog_id' ) && $target_blog !== (int) get_current_blog_id()
		) {
			switch_to_blog( $target_blog );
			$switched = true;
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Hourly watchdog: the GC's stuck-job pass 24 times a day, so dead workers do not look running until midnight.
	 *
	 * @return int Number of jobs surfaced as failed.
	 */
	public static function watchdog(): int {
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return 0;
		}

		$started = microtime( true );

		global $wpdb;
		$table = Schema::table( 'jobs' );

		$now          = time();
		$stuck_cutoff = $now - (int) apply_filters( 'perflocale/jobs/stuck_timeout_seconds', self::STUCK_TIMEOUT );
		$stuck_mysql  = gmdate( 'Y-m-d H:i:s', $stuck_cutoff );

		self::reschedule_idle_queued( $now );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uuid FROM %i
			 WHERE status IN ('queued', 'running') AND updated_at < %s
			 LIMIT 500",
				$table,
				$stuck_mysql
			)
		);

		$surfaced = 0;
		foreach ( $ids as $uuid ) {
			$uuid = (string) $uuid;

			$state = self::get( $uuid );
			if ( ! $state ) {
				continue;
			}
			if ( in_array( (string) ( $state['status'] ?? '' ), self::TERMINAL_STATUSES, true ) ) {
				continue;
			}

			// A job that resumes after a kill is requeued by the pass below, not failed here.
			if ( 'running' === ( $state['status'] ?? null ) && self::resumes( $state ) ) {
				continue;
			}

			// The runner of the job's own engine, not pick(): background_engine can change after a job is queued, and
			// the wrong runner would report a live job unscheduled. As cancel() and is_scheduled().
			try {
				if ( JobRunnerFactory::for_engine( (string) ( $state['engine'] ?? '' ) )->is_scheduled( $uuid ) ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall through to force-fail without the probe.
			}

			$updated = (int) ( $state['updated_at'] ?? 0 );
			$stale_h = (int) ceil( ( $now - $updated ) / HOUR_IN_SECONDS );

			self::mark_failed(
				$uuid,
				sprintf(
				/* translators: %d is hours since last status update. */
					__( '[watchdog] Worker stalled (no status update in %d hours). Job was force-failed by hourly watchdog.', 'perflocale' ),
					$stale_h
				)
			);
			++$surfaced;

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-mode-gated diagnostic.
				error_log(
					sprintf(
						'[PerfLocale] hourly watchdog force-failed stuck job %s (%dh stale)',
						$uuid,
						$stale_h
					)
				);
			}
		}

		// Running jobs whose worker is gone (worker_gone()): found within an hour of their lock expiring, not
		// STUCK_TIMEOUT.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$running = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uuid FROM %i
			 WHERE status = 'running' AND updated_at <= %s
			 LIMIT 500",
				$table,
				gmdate( 'Y-m-d H:i:s', $now - self::WORKER_GONE_GRACE )
			)
		);

		foreach ( $running as $uuid ) {
			if ( self::fail_if_worker_gone( (string) $uuid ) ) {
				++$surfaced;
			}
		}

		\PerfLocale\Background\BackgroundEvents::record_run( 'perflocale_jobs_watchdog', $started );

		return $surfaced;
	}

	/**
	 * Schedule again each queued job idle past WORKER_GONE_GRACE with no worker event (killed between its row and
	 * its event, or a lost cron write). At most REQUEUE_MAX times per job; then the stuck rule applies.
	 *
	 * @param int $now Unix time.
	 * @return void
	 */
	private static function reschedule_idle_queued( int $now ): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT uuid FROM %i
			 WHERE status = 'queued' AND updated_at <= %s
			 LIMIT 500",
				Schema::table( 'jobs' ),
				gmdate( 'Y-m-d H:i:s', $now - self::WORKER_GONE_GRACE )
			)
		);

		foreach ( $ids as $uuid ) {
			$state  = is_string( $uuid ) ? self::get( $uuid ) : null;
			$job_id = is_string( $state['id'] ?? null ) ? $state['id'] : '';
			$hook   = is_string( $state['hook'] ?? null ) ? $state['hook'] : '';
			$log    = is_array( $state['log'] ?? null ) ? $state['log'] : [];
			$result = is_array( $state['result'] ?? null ) ? $state['result'] : [];
			$count  = max( count( array_keys( array_column( $log, 'k' ), 'rescheduled', true ) ), is_int( $result[ self::REQUEUES_KEY ] ?? null ) ? $result[ self::REQUEUES_KEY ] : 0 );

			if ( '' === $job_id || '' === $hook || 'queued' !== ( $state['status'] ?? null ) || $count >= self::REQUEUE_MAX ) {
				continue;
			}

			try {
				if ( JobRunnerFactory::for_engine( is_string( $state['engine'] ?? null ) ? $state['engine'] : '' )->is_scheduled( $job_id ) ) {
					continue;
				}

				if ( ! self::take_requeue( $job_id, $result, $count + 1, is_int( $state['version'] ?? null ) ? $state['version'] : -1 ) ) {
					continue;
				}

				$args   = is_array( $state['args'] ?? null ) ? JobResume::keyed( $state['args'] ) : [];
				$runner = JobRunnerFactory::pick();
				$runner->schedule( $now, $hook, WorkerRegistry::with_blog_sentinel( $args, is_int( $state['blog_id'] ?? null ) ? $state['blog_id'] : 0 ), $job_id );
				self::set_engine( $job_id, $runner->get_engine_name() );
			} catch ( \Throwable $e ) {
				continue;
			}

			self::append_log( $job_id, __( 'This queued job had no worker event; it was scheduled again.', 'perflocale' ), 'rescheduled' );
		}
	}

	/**
	 * Store a queued row's reschedule count before its event is written: false when another process changed the row
	 * since it was read (a second watchdog took this reschedule). A count that would push the result over the cap is
	 * not stored (the log count still applies), never truncating a checkpoint.
	 *
	 * @param string       $job_id  UUID.
	 * @param array<mixed> $result  The row's result as read.
	 * @param int          $count   The new count.
	 * @param int          $version The row's version as read.
	 * @return bool Whether this process may schedule the job.
	 */
	private static function take_requeue( string $job_id, array $result, int $count, int $version ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$result[ self::REQUEUES_KEY ] = $count;
		$json                         = wp_json_encode( $result );

		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_RESULT_BYTES ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			(string) $wpdb->prepare(
				"UPDATE %i
			 SET result = %s,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'queued' AND version = %d",
				Schema::table( 'jobs' ),
				$json,
				current_time( 'mysql', true ),
				$job_id,
				$version
			)
		);

		return 1 === $affected;
	}

	/**
	 * Update the engine recorded on a job row.
	 *
	 * @param string $job_id UUID.
	 * @param string $engine 'wp_cron' | 'action_scheduler'.
	 * @return void
	 */
	public static function set_engine( string $job_id, string $engine ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}
		if ( ! in_array( $engine, [ 'wp_cron', 'action_scheduler' ], true ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
			 SET engine = %s,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s',
				$table,
				$engine,
				current_time( 'mysql', true ),
				$job_id
			)
		);
	}

	/**
	 * Job-id shape check, for the option-keyed code (JobLock keys per-job locks by UUID in `wp_options`) against
	 * malformed REST ids.
	 *
	 * @param string $job_id
	 * @return bool
	 */
	public static function is_safe_id( string $job_id ): bool {
		return (bool) preg_match( '/^[a-z0-9-]{8,64}$/', $job_id );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * A raw row in the consumers' shape: `id` (the UUID), timestamps as Unix seconds, `args`, `result` and `log`
	 * decoded, `error` a string.
	 *
	 * @param array<string, mixed> $row Raw row from the DB.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$datetime_to_ts = static function ( $v ): int {
			if ( $v === null || $v === '' || $v === '0000-00-00 00:00:00' ) {
				return 0;
			}
			// DATETIME columns store UTC (written with gmdate() / current_time( 'mysql', true )).
			$ts = strtotime( (string) $v . ' UTC' );
			return $ts === false ? 0 : (int) $ts;
		};

		return [
			'id'           => (string) ( $row['uuid'] ?? '' ),
			'type'         => (string) ( $row['type'] ?? '' ),
			'hook'         => (string) ( $row['hook'] ?? '' ),
			'engine'       => (string) ( $row['engine'] ?? 'wp_cron' ),
			'status'       => (string) ( $row['status'] ?? 'queued' ),
			'progress'     => (int) ( $row['progress'] ?? 0 ),
			'total'        => (int) ( $row['total'] ?? 0 ),
			'processed'    => (int) ( $row['processed'] ?? 0 ),
			'attempts'     => (int) ( $row['attempts'] ?? 0 ),
			'created_by'   => (int) ( $row['created_by'] ?? 0 ),
			'blog_id'      => (int) ( $row['blog_id'] ?? 0 ),
			'version'      => (int) ( $row['version'] ?? 0 ),
			'created_at'   => $datetime_to_ts( $row['created_at'] ?? null ),
			'started_at'   => $datetime_to_ts( $row['started_at'] ?? null ),
			'completed_at' => $datetime_to_ts( $row['completed_at'] ?? null ),
			'updated_at'   => $datetime_to_ts( $row['updated_at'] ?? null ),
			'args'         => self::decode_json_array( $row['args'] ?? '[]' ),
			'result'       => self::decode_json_array( $row['result'] ?? '[]' ),
			'error'        => (string) ( $row['error'] ?? '' ),
			'log'          => self::decode_json_array( $row['log'] ?? '[]' ),
		];
	}

	/**
	 * Decode a stored JSON column, returning an empty array on failure.
	 *
	 * @param mixed $value
	 * @return array<int|string, mixed>
	 */
	private static function decode_json_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Delete expired JobLock option rows. A per-job lock row stays while its job is running: worker_gone() reads it to
	 * tell a killed worker from a live one (the watchdog or the bridge fails the job, the next sweep the row). Type
	 * locks are swept whatever their jobs' state.
	 *
	 * @return int Number of stale locks removed.
	 */
	private static function sweep_expired_locks(): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		$now    = time();
		$pruned = 0;

		foreach ( [ JobLock::PREFIX, JobLock::TYPE_PREFIX ] as $prefix ) {
			// Every row that is not a live lock: an expired `<expiry>|<token>` row or a malformed one (a bare CAST
			// would skip it forever and block that lock name), as Lock::reap_expired(). `\\\\` here is MySQL `\\`,
			// REGEXP `\`.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table is $wpdb->options (WP core); LIKE value bound via prepare() below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					 AND NOT (
						option_value REGEXP '^[0-9]+\\\\|'
						AND CAST(CASE WHEN INSTR(option_value, '|') > 0 THEN SUBSTR(option_value, 1, INSTR(option_value, '|') - 1) ELSE option_value END AS UNSIGNED) >= %d
					 )",
					$wpdb->esc_like( $prefix ) . '%',
					$now
				),
				ARRAY_A
			);

			$names = array_map( static fn( $r ) => (string) $r['option_name'], (array) $rows );

			if ( empty( $names ) ) {
				continue;
			}

			if ( JobLock::PREFIX === $prefix ) {
				// Keep the lock rows of running jobs; when they cannot be read, no per-job row is swept.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
				$running = $wpdb->get_col(
					$wpdb->prepare( 'SELECT uuid FROM %i WHERE status = %s', Schema::table( 'jobs' ), 'running' )
				);

				if ( self::last_read_failed() ) {
					continue;
				}

				$keep  = array_map( static fn( string $uuid ): string => JobLock::PREFIX . $uuid, array_filter( $running, 'is_string' ) );
				$names = array_values( array_diff( $names, $keep ) );

				if ( empty( $names ) ) {
					continue;
				}
			}

			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

			// The DELETE re-asserts the SELECT's predicate against the same $now: a lease refreshed or reclaimed in
			// between has a later expiry and stays, so a live lock is never deleted and $pruned counts what was.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a dynamic %s list for IN(); replacements arrive as one array, which WPCS cannot count.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options}
					 WHERE option_name IN ({$placeholders})
					 AND NOT (
						option_value REGEXP '^[0-9]+\\\\|'
						AND CAST(CASE WHEN INSTR(option_value, '|') > 0 THEN SUBSTR(option_value, 1, INSTR(option_value, '|') - 1) ELSE option_value END AS UNSIGNED) >= %d
					 )",
					array_merge( $names, [ $now ] )
				)
			);

			$pruned += (int) $deleted;

			foreach ( $names as $name ) {
				wp_cache_delete( $name, 'options' );
			}
			\PerfLocale\Database\NotOptions::unlist( $names );
		}

		return $pruned;
	}

	/**
	 * Whether the database read that just ran failed: wpdb clears last_error at every query, so a non-empty one belongs
	 * to that read.
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private static function last_read_failed(): bool {
		global $wpdb;

		return $wpdb instanceof \wpdb && '' !== $wpdb->last_error;
	}

	/**
	 * Run the end-of-run step of the job's type (self::$run_end).
	 *
	 * @param string                    $job_id Job id.
	 * @param array<string, mixed>|null $row    Row read before the transition (null: read it now).
	 * @return void
	 */
	private static function run_ended( string $job_id, ?array $row = null ): void {
		$row  = $row ?? self::get( $job_id );
		$type = is_array( $row ) && is_string( $row['type'] ?? null ) ? $row['type'] : '';

		if ( ! is_array( $row ) || ! isset( self::$run_end[ $type ] ) ) {
			return;
		}

		try {
			( self::$run_end[ $type ] )( $row );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic: a failing step must not break the transition that called it.
			error_log( '[PerfLocale] Job ' . $job_id . ': the end-of-run step failed: ' . \PerfLocale\Util\PathRedactor::redact( $e->getMessage() ) );
		}
	}

	/**
	 * Truncate the result payload when its JSON is over MAX_RESULT_BYTES.
	 *
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function truncate_result( array $result ): array {
		$json = wp_json_encode( $result );
		if ( $json === false || strlen( $json ) <= self::MAX_RESULT_BYTES ) {
			return $result;
		}
		return [
			'_truncated' => true,
			'_size'      => strlen( $json ),
			'_message'   => sprintf(
				/* translators: %d is a byte count. */
				__( 'Result payload exceeded %d bytes and was dropped. Inspect the job log for details.', 'perflocale' ),
				self::MAX_RESULT_BYTES
			),
		];
	}

	/**
	 * Cap error messages so a stack trace can't bloat the row.
	 *
	 * @param string $error
	 * @return string
	 */
	private static function truncate_error( string $error ): string {
		return mb_substr( $error, 0, self::MAX_ERROR_BYTES );
	}
}
