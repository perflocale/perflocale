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
	 * Move a queued job to `running`. Sets started_at + attempts++.
	 *
	 * @param string $job_id UUID.
	 * @return void
	 */
	public static function mark_running( string $job_id ): void {
		if ( ! self::is_safe_id( $job_id ) ) {
			return;
		}

		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );

		// Idempotent: only transitions from queued. Already-running rows
		// are NOT bumped (preserves the attempts counter set by the
		// worker that actually owns the lock).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled, and bound via the %i identifier placeholder.
		$wpdb->query(
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

		// Automatic callers (worker auto-retry, the deactivation Resumer) must
		// never resurrect a job an operator explicitly canceled, so they keep
		// 'canceled' in the exclusion set. Operator-initiated retries (CLI /
		// REST) pass $allow_canceled = true so re-running a canceled job
		// actually resets it instead of silently no-opping while the command
		// still reports success. 'complete' is always excluded.
		$excluded_statuses = $allow_canceled ? "'complete'" : "'complete', 'canceled'";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'); $excluded_statuses is a hardcoded literal list, never user input.
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
			 WHERE uuid = %s AND status NOT IN ({$excluded_statuses})",
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

		global $wpdb;
		$table = Schema::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is Schema::table('jobs'), class-controlled.
		$wpdb->delete( $table, [ 'uuid' => $job_id ], [ '%s' ] );
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

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a dynamic %s list for IN()
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
					$names
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
