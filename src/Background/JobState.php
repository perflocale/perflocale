<?php
/**
 * Persistent job-state layer backed by the dedicated `wp_perflocale_jobs`
 * table.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// File-level disable: every $wpdb->query / get_* in this file targets
// `$table = Schema::table('jobs')` which is `$wpdb->prefix . 'perflocale_jobs'`
// — class-controlled, never user input, and passed through the `%i`
// identifier placeholder so prepare() quotes it. What still interpolates is
// the lock-reaper's $wpdb->options (core identifier), the IN() placeholder
// list built from a %s-per-value fill, and reset_for_retry()'s hardcoded
// status list; per-call phpcs:ignore comments don't cover the multi-line SQL
// strings these queries use, so the suppression is file-level. DirectQuery /
// NoCaching are intentional too: the jobs table is a concurrently-mutated
// source of truth that must not be cached (a stale read would mis-route a
// worker).
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * State store for background jobs.
 *
 * Backed by the `wp_perflocale_jobs` table (per-blog on multisite via the
 * standard `$wpdb->prefix`). Replaces the option-only storage that earlier
 * v1.0 development used.
 *
 * **Concurrency model.** Every mutating write is a single SQL UPDATE with
 * a `version` CAS column: the UPDATE asserts the prior version, succeeds
 * only when no other writer has touched the row since this caller read it,
 * and bumps `version` so the next read sees a different value. Losers see
 * `affected_rows = 0` and retry against the winning state. The DB row-lock
 * serialises the contended block, so concurrent progress / cancel / mark
 * sequences end deterministically without ever overwriting each other.
 *
 * **Public-API stability.** Every method here returns the same array shape
 * the option-based predecessor returned (`['id' => uuid, 'type' => …,
 * 'status' => …, 'created_at' => unix_ts, …]`) so REST, admin, and CLI
 * consumers continue to read the same keys.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class JobState {

	/**
	 * Maximum cap on the active-jobs list shown in the admin Jobs page.
	 * Filterable via `perflocale/jobs/active_index_max`.
	 *
	 * Lower bound clamps to 10 so a filter bug can't render the page
	 * useless.
	 */
	public const MAX_ACTIVE = 50;

	/**
	 * History TTL for terminal-state rows. After this age, completed /
	 * failed / canceled rows are pruned by the daily GC.
	 */
	public const HISTORY_TTL = DAY_IN_SECONDS;

	/**
	 * How long a job may sit in `queued` or `running` without its
	 * `updated_at` being bumped before GC declares it stuck.
	 */
	public const STUCK_TIMEOUT = 6 * HOUR_IN_SECONDS;

	/**
	 * Cap on the JSON-encoded `result` payload before truncation.
	 */
	public const MAX_RESULT_BYTES = 65536;

	/**
	 * Cap on the `error` field length.
	 */
	public const MAX_ERROR_BYTES = 2000;

	/**
	 * Ring-buffer size for `log` entries.
	 */
	public const MAX_LOG_ENTRIES = 20;

	/**
	 * CAS retry budget for log-append (and any future array-mutating
	 * methods that read-modify-write).
	 */
	private const CAS_MAX_RETRIES = 5;

	/**
	 * Statuses that cannot be downgraded by subsequent writes.
	 */
	private const TERMINAL_STATUSES = [ 'complete', 'failed', 'canceled' ];

	/**
	 * Whether a status is terminal (the job is done and must not run again).
	 * Public so dispatch/worker guards share one definition with this class.
	 *
	 * @param string $status Job status.
	 * @return bool
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, self::TERMINAL_STATUSES, true );
	}

	/**
	 * Resolve the runtime cap from the filter. Clamped to a sane floor.
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
					'[PerfLocale] JobState::create UUID collision for %s — dispatcher should retry with a fresh ID',
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
	 * Alias of {@see get()} kept for API compatibility — DB reads are
	 * authoritative, no cache layer to bust.
	 *
	 * @param string $job_id
	 * @return array<string, mixed>|null
	 */
	public static function get_fresh( string $job_id ): ?array {
		return self::get( $job_id );
	}

	/**
	 * Find an identical job that is still queued or running on this blog.
	 *
	 * "Identical" means the same type and byte-identical stored args - the
	 * same logical operation, not merely the same type, so unrelated work of
	 * the same kind still runs in parallel and a chunked chain (whose cursor
	 * advances every link) is never mistaken for a repeat of itself.
	 *
	 * Reads the `type_status` index, and the queued/running set is a handful
	 * of rows on any real install.
	 *
	 * @param string              $type Job type slug.
	 * @param array<mixed, mixed> $args Worker args as passed to {@see create()}.
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
	 * Move a queued job to `running`. Sets started_at + attempts++.
	 *
	 * Returns whether THIS caller performed the transition. False means the
	 * claim did not land: the write failed, or the row was no longer
	 * `queued` - cancelled, already claimed by another worker, or gone. The
	 * caller must not execute the job in that case. It used to return void,
	 * so a failed claim was invisible and the handler ran anyway: provider
	 * calls were made and billed, translations and descendants were created,
	 * and the completion hook fired, all while the row still said `queued`
	 * and stayed eligible for a later replay.
	 *
	 * @param string $job_id UUID.
	 * @return bool True when the row moved queued -> running.
	 */
	public static function mark_running( string $job_id ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// Idempotent: only transitions from queued. Already-running rows
		// are NOT bumped (preserves the attempts counter set by the
		// worker that actually owns the lock).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'running',
			     started_at = %s,
			     updated_at = %s,
			     attempts = attempts + 1,
			     version = version + 1
			 WHERE uuid = %s AND status = 'queued'",
				$table,
				$now,
				$now,
				$job_id
			)
		);

		// `false` is a query error; 0 is "no row was queued". Both mean the
		// caller does not own this job.
		return 1 === (int) $affected;
	}

	/**
	 * Update progress counters. Status-guarded so a tick can't resurrect
	 * a terminal row.
	 *
	 * @param string $job_id    UUID.
	 * @param int    $processed Items processed.
	 * @param int    $total     Total items (0 = indeterminate).
	 * @return void
	 */
	public static function update_progress( string $job_id, int $processed, int $total ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		$processed = max( 0, $processed );
		$total     = max( 0, $total );
		$progress  = ( $total > 0 ) ? (int) min( 99, floor( $processed * 100 / $total ) ) : 0;

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET processed = %d,
			     total = %d,
			     progress = %d,
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'running'",
				$table,
				$processed,
				$total,
				$progress,
				$now,
				$job_id
			)
		);
	}

	/**
	 * Mark a running job complete. Progress flips to 100, completed_at set.
	 *
	 * @param string               $job_id UUID.
	 * @param array<string, mixed> $result Worker output (truncated to MAX_RESULT_BYTES).
	 * @return void
	 */
	public static function mark_complete( string $job_id, array $result = [] ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		$result_json = wp_json_encode( self::truncate_result( $result ) );
		if ( $result_json === false ) {
			$result_json = '[]';
		}

		// Only transitions from `running`. A row that was canceled mid-flight
		// stays canceled — the worker's mark_complete is a no-op.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'complete',
			     progress = 100,
			     completed_at = %s,
			     updated_at = %s,
			     result = %s,
			     version = version + 1
			 WHERE uuid = %s AND status = 'running'",
				$table,
				$now,
				$now,
				$result_json,
				$job_id
			)
		);
	}

	/**
	 * Mark a queued/running job failed.
	 *
	 * @param string $job_id UUID.
	 * @param string $error  Human-readable error message (truncated).
	 * @return void
	 */
	public static function mark_failed( string $job_id, string $error ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// Accept from queued OR running. Cancel and complete are also terminal
		// transitions — those take precedence (status='canceled'/'complete'
		// rows are excluded by the WHERE).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'failed',
			     completed_at = %s,
			     updated_at = %s,
			     error = %s,
			     version = version + 1
			 WHERE uuid = %s AND status IN ('queued', 'running')",
				$table,
				$now,
				$now,
				self::truncate_error( $error ),
				$job_id
			)
		);
	}

	/**
	 * User-initiated cancel. Worker checks for canceled in its progress
	 * callback and bails. Cancel does NOT unschedule on its own — that's
	 * the runner's job ({@see JobRunnerInterface::cancel()}).
	 *
	 * @param string $job_id UUID.
	 * @return void
	 */
	public static function cancel( string $job_id ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// Only queued/running can be canceled. Already-terminal rows are
		// idempotent no-ops.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$wpdb->query(
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
	}

	/**
	 * Reset a failed/canceled job to queued so the caller can re-enqueue.
	 *
	 * @param string $job_id         UUID.
	 * @param bool   $allow_canceled Whether a canceled job may be reset. Only
	 *                               operator-initiated retries (CLI/REST) pass
	 *                               true; automatic callers leave it false.
	 * @return bool True if the row existed and was reset.
	 */
	public static function reset_for_retry( string $job_id, bool $allow_canceled = false ): bool {
		if ( ! self::is_safe_id( $job_id ) ) {
			return false;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// ALLOW-list, not a deny-list. The old exclusion (NOT IN
		// ('complete','canceled')) left 'queued' reachable, so two concurrent
		// retries of the same failed job BOTH matched a row, both returned true
		// and both enqueued the same UUID: the first flipped failed → queued and
		// 'queued' still satisfied the predicate for the second. Naming the
		// retryable SOURCE statuses makes the transition single-winner — the
		// loser gets 0 affected rows and its caller's existing "state changed
		// concurrently" 409 / CLI error, which both entry points already have.
		//
		// 'running' is required: Resumer flips a killed worker's row back to
		// queued after a deactivation. 'failed' is required: WorkerRegistry's
		// auto-retry resets immediately after mark_failed(). 'canceled' is
		// operator-only — automatic callers must never resurrect a job an
		// operator explicitly canceled. 'complete' is never retryable. 'queued'
		// is now correctly refused: it is never a legitimate SOURCE status,
		// because JobsController::retry_job and the CLI both gate on
		// status IN ('failed','canceled') before calling this.
		$allowed_statuses = $allow_canceled ? "'failed', 'running', 'canceled'" : "'failed', 'running'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'); $allowed_statuses is a hardcoded literal list, never user input.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
			 SET status = 'queued',
			     started_at = NULL,
			     completed_at = NULL,
			     progress = 0,
			     processed = 0,
			     total = 0,
			     error = '',
			     result = '[]',
			     updated_at = %s,
			     version = version + 1
			 WHERE uuid = %s AND status IN ({$allowed_statuses})",
				$table,
				$now,
				$job_id
			)
		);

		return (int) $affected === 1;
	}

	/**
	 * Append a log entry to the per-job ring buffer (last 20 kept).
	 *
	 * Uses a CAS retry loop on the `log` column + `version` so concurrent
	 * appends don't lose each other.
	 *
	 * @param string $job_id  UUID.
	 * @param string $message Log message (truncated to 500 chars).
	 * @return void
	 */
	public static function append_log( string $job_id, string $message ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );

		$entry = [
			't' => time(),
			'm' => substr( $message, 0, 500 ),
		];

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

			// Another writer won. Back off briefly (exponential + jitter)
			// before re-reading, so concurrent workers fan out instead of
			// hammering the same row in a tight spin loop. Skip the sleep on
			// the final attempt (nothing follows it).
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
	 * Is one of OUR long-running jobs currently in flight?
	 *
	 * Used to scope the `action_scheduler_failure_period` filter. That filter is
	 * store-wide — Action Scheduler passes the callback no action context — so
	 * raising the failure window unconditionally would delay reclamation of
	 * EVERY plugin's stuck actions, not just ours. Answering "no" here hands the
	 * incoming value straight back, leaving other plugins on AS's own cadence.
	 *
	 * NOT MEMOISED, deliberately. This used to cache the answer in a
	 * function-local static, on the reasoning that it "cannot meaningfully
	 * change inside one PHP process". That reasoning was wrong in two separate
	 * ways, and a long-lived process (a WP-CLI run, a queue worker) hits both:
	 *
	 * It changes over TIME. The same process that starts, finishes or reclaims a
	 * job then asks this question again and gets its first answer back — false
	 * after a job began, or true long after the last one ended, pinning the
	 * failure window at 300s or at 21,600s regardless of the truth.
	 *
	 * It changes across BLOGS. `Schema::table()` resolves against the CURRENT
	 * blog, so on multisite the cached answer belonged to whichever blog asked
	 * first. After switch_to_blog() a site with no jobs at all inherited its
	 * neighbour's `true` and quietly extended Action Scheduler's failure period
	 * — for every OTHER plugin's actions on that site too, since the filter is
	 * store-wide. That is the one direction where being wrong reaches beyond
	 * this plugin.
	 *
	 * Keying the static by blog would fix half of it and leave the time half, so
	 * the memo is simply gone. Measured cost of not having it: 0.0435 ms per
	 * call (2,000 calls, `type=ref` on the `status_updated` covering index,
	 * `Using index`, no row reads). The only caller is the
	 * `action_scheduler_failure_period` filter, which runs during Action
	 * Scheduler queue processing and never on a front-end page request. There is
	 * nothing here worth caching.
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
	 * Return the active-jobs list newest-first, capped at
	 * {@see active_index_max()} rows.
	 *
	 * Indexed query: `status_updated` covers the ORDER BY when the WHERE
	 * filter is open.
	 *
	 * When $status is given the filter is applied IN SQL, before the cap, so
	 * a status that only appears outside the most-recent-N window (e.g. a few
	 * failed jobs behind hundreds of newer completed ones) is still returned.
	 * Filtering after the cap in PHP would silently drop them.
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
	 * Every queued/running job, oldest-update first — the resume source.
	 *
	 * Distinct from {@see list_active()}: that caps at active_index_max() (the
	 * admin display window, newest-first), so an old pending job sitting beyond
	 * the cap would never be returned to the Resumer and would be silently
	 * dropped after a deactivate/reactivate. This is status-scoped to the only
	 * resumable states and bounded far higher, oldest-first for FIFO fairness.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_resumable(): array {
		global $wpdb;
		$table = Schema::table( 'jobs' );

		/**
		 * Upper bound on jobs resumed in one sweep. Default 1000 — far above
		 * the active-display cap so pending jobs aren't dropped, yet bounded
		 * so a runaway table can't OOM the sweep.
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
	 * Summary-only variant of {@see list_active()}: omits the three LONGTEXT
	 * columns (args / result / log) the admin Jobs list never displays
	 * inline. `error` is kept because the admin table surfaces it next to
	 * the status, and it's a small VARCHAR(2000) anyway.
	 *
	 * Used by the polled REST endpoint and the JobsPage initial render.
	 * Callers that need full data (Resumer, WP-CLI, GET /jobs/{id} detail
	 * endpoint) keep using `list_active()`.
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
				        completed_at, updated_at, error
				 FROM %i
				 ORDER BY updated_at DESC, id DESC
				 LIMIT %d',
				$table,
				$cap
			),
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			// Synthesize the omitted columns so hydrate()'s shape stays
			// consistent — downstream consumers can rely on the same keys
			// being present even when they hold empty placeholders.
			$row['args']   = '';
			$row['result'] = '';
			$row['log']    = '';

			$h               = self::hydrate( $row );
			$out[ $h['id'] ] = $h;
		}
		return $out;
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

		// Remove the artifact BEFORE the row, because the row is the only
		// record of what the artifact was. A completed data_export writes its
		// file into wp-content/uploads, which is web-served; deleting the job
		// used to leave that file behind with nothing left to identify or own
		// it, and the only thing that eventually removed it was the 7-day
		// age sweep. Ordering matters: if the unlink fails we still delete the
		// row (the file is then swept by age, exactly as before), but if the
		// row went first we would have lost the path entirely.
		//
		// The row still goes even when the unlink fails — a directory the web
		// server cannot write to, or an artifact the cleanup refuses because it
		// is no longer the file the job created. Making admin deletion fail
		// instead would leave the operator unable to remove a job at all, and
		// the artifact is web-served, so a stuck row is not a safer state than a
		// swept file. What was missing was any RECORD of the orphan: the file
		// survived with nothing owning it and nothing said so. Log it, so the
		// gap between "job deleted" and "file gone" is visible before the
		// 7-day sweep closes it.
		if ( ! self::delete_owned_artifact( $job_id ) ) {
			$orphan_state  = self::get( $job_id );
			$orphan_result = is_array( $orphan_state ) ? (array) ( $orphan_state['result'] ?? [] ) : [];
			$orphan_path   = (string) ( $orphan_result['path'] ?? '' );

			// Only when there really was an artifact to remove. Every other job
			// type has no file, and logging those would bury the real cases.
			if ( '' !== $orphan_path && file_exists( $orphan_path ) ) {
				// NEVER log the path, the basename or the token.
				//
				// An export filename is `perflocale-export-<date>-<32 CSPRN>.json`
				// and that suffix IS the access control: the file lands in
				// wp-content/uploads, which Apache guards with the .htaccess
				// harden_directory() writes but which nginx and Caddy ignore
				// entirely, so on those servers anyone holding the exact URL can
				// fetch the export. Writing the full path here put that token
				// into the PHP error log — a file support tooling, hosting
				// dashboards and log shippers routinely read — which handed away
				// the very secret the 32-character suffix exists to protect.
				//
				// The job UUID is enough to find the row and its recorded path
				// through the admin UI or WP-CLI, and a truncated SHA-256 of the
				// path lets two log lines be correlated without being reversible.
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

		global $wpdb;
		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled.
		$wpdb->delete( $table, [ 'uuid' => $job_id ], [ '%s' ] );
	}

	/**
	 * Delete the export file a finished job owns, if it still exists.
	 *
	 * Deliberately narrow. It removes ONLY a path this job recorded as its own
	 * result, only when that path resolves inside the plugin's own exports
	 * directory, and never one of the directory's hardening files. Anything it
	 * cannot prove it owns is left alone for the age sweep — losing a file is
	 * worse than keeping one a few days longer.
	 *
	 * @param string $job_id Job UUID.
	 * @return bool True when a file was removed.
	 */
	private static function delete_owned_artifact( string $job_id ): bool {
		$state = self::get( $job_id );

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

		// RESOLVE THE PARENT DIRECTORY, NEVER THE FILE.
		//
		// This used to call realpath() on the recorded path itself and unlink
		// whatever came back. realpath() FOLLOWS SYMLINKS, so a symlink left at
		// job A's recorded path pointing at job B's export resolved to B's real
		// file — which is genuinely inside the exports directory, so every
		// containment check passed. Deleting job A then destroyed job B's data,
		// left A's dangling symlink in place, and removed A's row while B's row
		// survived pointing at a file that no longer existed.
		//
		// Resolving only the DIRECTORY gives the traversal protection that was
		// wanted (`..` segments and a symlinked exports dir are still handled)
		// without ever redirecting the unlink to a different file.
		$real_parent = realpath( dirname( $path ) );
		$base        = basename( $path );

		if ( false === $real_parent || $real_parent !== $real_dir || '' === $base ) {
			return false;
		}

		// Belt and braces: the exporter never writes these names, and unlinking
		// one would strip the directory's own access controls.
		if ( in_array( $base, [ '.htaccess', 'index.php', 'web.config' ], true ) ) {
			return false;
		}

		$target = $real_parent . DIRECTORY_SEPARATOR . $base;

		// NON-FOLLOWING metadata for the type check. is_file() follows links and
		// so reports a symlink-to-a-regular-file as a regular file; lstat()
		// describes the entry itself. Refuse anything that is not a plain file:
		// a symlink, a FIFO, a socket, a device, a directory.
		$st = @lstat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing artifact is the normal already-cleaned case, not an error.

		if ( ! is_array( $st ) || ! isset( $st['mode'] ) ) {
			return false;
		}

		// S_IFMT / S_IFREG. PHP exposes no portable constants for these.
		if ( 0100000 !== ( ( (int) $st['mode'] ) & 0170000 ) ) {
			return false;
		}

		// IDENTITY BINDING. When the job recorded the inode and device of the
		// file it created, require the entry at this path to still BE that
		// file. This is what makes pathname reuse safe: the old file being
		// unlinked while a handle stayed open, and an unrelated new file
		// appearing at the same name, used to end with the cleanup deleting the
		// newcomer. Absent on rows written before this was recorded, in which
		// case the checks above stand alone and the age sweep is the backstop.
		$want_ino = (string) ( $result['ino'] ?? '' );
		$want_dev = (string) ( $result['dev'] ?? '' );

		// A row with no recorded identity cannot prove it owns the file that is
		// there NOW. Rows written before 1.0.1 carry only `path` and `bytes`, and
		// treating the pathname as ownership is exactly the mistake the identity
		// binding exists to prevent: if the original artifact is gone and any
		// other regular file has since taken that pathname, deleting the job
		// would delete the newcomer. Reproduced on four roots and both multisite
		// child blogs.
		//
		// So a legacy row REFUSES to unlink and leaves the artifact to the bounded
		// age sweep, which is the same backstop that already covers an unlink
		// failure. The cost is that a pre-1.0.1 export lingers until the sweep;
		// the alternative is deleting a file this row cannot show it owns.
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
	 * Zero the `created_by` field on every job dispatched by the given
	 * user — GDPR Right-to-Erasure + admin user-delete hook entry point.
	 *
	 * Single indexed UPDATE thanks to `KEY created_by`. No per-row reads.
	 *
	 * @param int $user_id
	 * @return int Number of rows affected.
	 */
	public static function anonymize_for_user( int $user_id ): int {
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

		return max( 0, (int) $affected );
	}

	/**
	 * Daily GC: prune terminal rows older than HISTORY_TTL and force-fail
	 * stuck queued/running rows whose updated_at is past STUCK_TIMEOUT.
	 *
	 * Two indexed queries replace the option-store's full-index walk:
	 *   - stuck-job scan: `status_updated` index, range scan
	 *   - terminal pruning: `status_updated` index, range scan
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

		// Stuck-job force-fail. Each job is probed against the runner for its
		// own stored engine inside the loop (so a long retry-backoff event
		// isn't mis-detected as stuck, and a non-active-engine job isn't
		// force-failed by the wrong runner).
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

			// Probe against the runner for the job's OWN engine, not a single
			// global pick() — see watchdog(): a job stored under the non-active
			// engine must not be force-failed just because the active runner
			// can't see it. for_engine() mirrors the cancel()/is_scheduled() paths.
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

		// Terminal-row pruning.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i
			 WHERE status IN ('complete', 'failed', 'canceled') AND updated_at < %s",
				$table,
				$history_mysql
			)
		);

		// Stale lock sweep — keeps wp_options clean of dead JobLock rows.
		self::sweep_expired_locks();

		\PerfLocale\Background\BackgroundEvents::record_run( 'perflocale_jobs_gc', $started );

		return $removed;
	}

	/**
	 * Switch into the target blog and invoke a recurring callback.
	 * Bootstrap wraps both `gc` and `watchdog` recurring actions through
	 * this helper.
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
	 * Hourly stuck-job watchdog. Same logic as the GC stuck-job pass
	 * (force-fail any queued/running row past STUCK_TIMEOUT) but runs
	 * 24× more frequently so dead workers don't sit visible-but-running
	 * until midnight.
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

			// Probe against the runner for the job's OWN engine, not a single
			// global pick(): an operator can flip background_engine after a job
			// is queued, so probing a non-active-engine job with the wrong
			// runner reports not-scheduled and would force-fail a still-live
			// job. for_engine() mirrors the cancel()/is_scheduled() paths.
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

		\PerfLocale\Background\BackgroundEvents::record_run( 'perflocale_jobs_watchdog', $started );

		return $surfaced;
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
	 * Job-id shape check — defends option-name-ish areas of the codebase
	 * (notably JobLock, which still keys per-job locks by UUID in
	 * `wp_options`) against attacker-supplied IDs from REST endpoints.
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
	 * Convert a raw DB row into the array shape consumers expect.
	 *
	 * Output shape consumers expect:
	 *   - `id` (the UUID — REST/admin consume it under this name)
	 *   - all timestamp fields as int Unix seconds (DB stores DATETIME)
	 *   - `args` / `result` / `log` as decoded arrays
	 *   - `error` as a string
	 *
	 * @param array<string, mixed> $row Raw row from the DB.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$datetime_to_ts = static function ( $v ): int {
			if ( $v === null || $v === '' || $v === '0000-00-00 00:00:00' ) {
				return 0;
			}
			// DATETIME columns store UTC (we INSERT with gmdate/current_time('mysql', true)).
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
	 * Delete expired JobLock option rows. Same logic as the option-store
	 * predecessor — JobLock still uses options for its per-job + per-type
	 * locks, and dead UUID rows accumulate without a sweep.
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
			// Select every row that is NOT a currently-live lock: an expired
			// well-formed `<expiry>|<token>` row, OR a malformed row that
			// doesn't match our shape at all. A bare CAST(option_value) would
			// silently skip malformed rows (CAST → 0 fails `> 0`), stranding
			// them forever and permanently blocking that lock name. Mirrors
			// Lock::reap_expired(). `\\\\` here → MySQL `\\` → REGEXP `\`.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table is $wpdb->options (WP core); LIKE value bound via prepare() below.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options}
					 WHERE option_name LIKE %s
					 AND NOT (
						option_value REGEXP '^[0-9]+\\\\|'
						AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) >= %d
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

			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

			// Re-assert at DELETE time the exact predicate that qualified these
			// names for collection, against the SAME captured $now. Between the
			// SELECT above and this statement a worker can refresh its lease or
			// CAS-reclaim an expired one; deleting by NAME alone then removes a
			// LIVE lock and lets a second worker enter a job — or a whole job
			// type — that is actively running. A renewed row now has
			// expiry >= $now and is skipped, so $pruned also becomes an honest
			// count of what was really deleted. Still one statement, no CAS list.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a dynamic %s list for IN(); replacements arrive as one array, which WPCS cannot count.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options}
					 WHERE option_name IN ({$placeholders})
					 AND NOT (
						option_value REGEXP '^[0-9]+\\\\|'
						AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) >= %d
					 )",
					array_merge( $names, [ $now ] )
				)
			);

			$pruned += (int) $deleted;

			foreach ( $names as $name ) {
				wp_cache_delete( $name, 'options' );
			}
			wp_cache_delete( 'notoptions', 'options' );
		}

		return $pruned;
	}

	/**
	 * Truncate the result payload if it serialises larger than MAX_RESULT_BYTES.
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
