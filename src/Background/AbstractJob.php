<?php
/**
 * Base class for Tier-2 background jobs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every "conditionally async" operation (DataImport, migration importers,
 * bulk MT, etc.) extends this. Tier-1 ops (webhook delivery, exchange-rate
 * sync, etc.) bypass it entirely — they enqueue directly via the runner.
 *
 * The base class defines:
 *   - Type slug (used to dispatch the worker hook).
 *   - Required capability for dispatching this job.
 *   - Default threshold for "Auto" background-processing mode.
 *   - The actual work (`execute()`), which MUST be re-entrant + resumable.
 *   - Decision logic for sync vs async via {@see should_run_async()}.
 *
 * Workers are STATELESS aside from the per-job JobState row (read inside
 * the worker hook). Anything cached on the instance lives only for the duration
 * of one execute() call — re-creation between dispatch and execution is
 * normal.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
abstract class AbstractJob {

	/**
	 * Short slug, e.g. 'data_import'. Used to compose the worker hook
	 * name (`perflocale_job_run_<type>`) so each job type has its own
	 * isolated callback.
	 *
	 * MUST be `[a-z0-9_]{1,40}` — used as part of a hook name and an option
	 * prefix, both of which are sensitive to weird characters.
	 *
	 * @return string
	 */
	abstract public function get_type(): string;

	/**
	 * Capability the dispatching user must have. Re-checked inside the
	 * worker hook against the stored `created_by` user, so a revoked cap
	 * between dispatch and execution is caught.
	 *
	 * @return string
	 */
	abstract public function get_required_capability(): string;

	/**
	 * Default item-count threshold for "Auto" mode. If the job's
	 * {@see args_size()} returns >= this value, the dispatcher will
	 * route to async; otherwise it runs inline.
	 *
	 * @return int
	 */
	abstract public function get_default_threshold(): int;

	/**
	 * The actual work. Called either inline (sync path) or by the worker
	 * hook (async path). The implementation MUST be:
	 *   - Re-entrant: same args → same outcome on a re-run after a crash.
	 *   - Resumable: read its own progress from JobState if it wants
	 *     incremental work; otherwise idempotent re-processing is fine.
	 *   - Bounded in memory: process in chunks; don't load everything
	 *     into a single in-memory array on large workloads.
	 *
	 * The progress callback signature is `function(int $processed, int $total): void`.
	 * Workers should call it periodically — every 1 second or every 50
	 * items, whichever first. Callers may pass a no-op for the sync path.
	 *
	 * @param array<string, mixed> $args     Worker args.
	 * @param callable             $progress `function(int, int): void`. Use this
	 *                                       to report `(processed, total)`. The
	 *                                       sync path may pass a no-op.
	 * @return array<string, mixed> Result payload (small structured data; large
	 *                              outputs are truncated by JobState).
	 *
	 * @throws \Throwable On any error; the worker hook catches + marks failed.
	 */
	abstract public function execute( array $args, callable $progress ): array;

	/**
	 * Decide whether THIS dispatch should run async (true) or inline (false).
	 *
	 * Logic:
	 *   - `Never`  → always inline.
	 *   - `Always` → always async.
	 *   - `Auto`   → async if {@see args_size()} >= {@see effective_threshold()}.
	 *
	 * @param array<string, mixed> $args     Worker args.
	 * @param Settings             $settings Plugin settings.
	 * @return bool
	 */
	public function should_run_async( array $args, Settings $settings ): bool {
		$mode = (string) $settings->get( 'background_processing', 'auto' );

		if ( $mode === 'always' ) {
			return true;
		}

		if ( $mode === 'never' ) {
			return false;
		}

		// Auto.
		return $this->args_size( $args ) >= $this->effective_threshold( $args );
	}

	/**
	 * How many "items" are in this args set. Default is 0 (always sync in
	 * Auto mode) — override per-job to return the actual item count, byte
	 * size, post count, whatever's the cost dimension.
	 *
	 * Compared against the threshold value to drive the Auto-mode decision.
	 *
	 * @param array<string, mixed> $args
	 * @return int
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Base default; subclasses override to read $args.
	protected function args_size( array $args ): int {
		return 0;
	}

	/**
	 * How long the per-job lock should live without a progress refresh.
	 * Default is {@see JobLock::DEFAULT_TTL}. Jobs that issue a single
	 * monolithic call (DB migrations, big CSV imports) should override to
	 * a value comfortably larger than their worst-case duration so the
	 * lock isn't reclaimed mid-flight by a second worker.
	 *
	 * Progress ticks also refresh the lock — workers that emit periodic
	 * progress can rely on the default. Workers that block for minutes
	 * without a tick (slow HTTP, single-shot importer) need to bump this
	 * to cover the worst-case run time, OR call {@see JobLock::refresh()}
	 * themselves inside their loops.
	 *
	 * @return int Seconds.
	 */
	public function get_lock_ttl(): int {
		return JobLock::DEFAULT_TTL;
	}

	/**
	 * Resolve the effective threshold for this job + this args set.
	 *
	 * Reads from `background_thresholds` setting (admin override per type),
	 * falling back to {@see get_default_threshold()}, finally exposed via
	 * the `perflocale/jobs/threshold/<type>` filter for per-args dynamic
	 * thresholds.
	 *
	 * @param array<string, mixed> $args
	 * @return int
	 */
	protected function effective_threshold( array $args ): int {
		try {
			$settings   = Plugin::get_instance()->get( 'settings' );
			$thresholds = (array) ( $settings instanceof Settings ? $settings->get( 'background_thresholds', [] ) : [] );
		} catch ( \Throwable $e ) {
			$thresholds = [];
		}

		$type = $this->get_type();
		$base = isset( $thresholds[ $type ] ) ? (int) $thresholds[ $type ] : $this->get_default_threshold();

		/**
		 * Filter the threshold for a specific job type. Receives the args
		 * for the current dispatch so the threshold can vary with payload.
		 *
		 * @hook perflocale/jobs/threshold/<type>
		 * @param int                  $base Threshold from settings (or default).
		 * @param array<string, mixed> $args Current dispatch args.
		 */
		return (int) apply_filters( "perflocale/jobs/threshold/{$type}", $base, $args );
	}
}
