<?php
/**
 * Base class for background jobs.
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
 * Base of every job that may run inline or in the background (imports, migrations, bulk translation).
 *
 * A job defines its type, required capability, Auto-mode threshold and a re-entrant execute(); its only state
 * between runs is its JobState row, as the instance is re-created between dispatch and execution.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
abstract class AbstractJob {

	/**
	 * Type slug, `[a-z0-9_]{1,40}`: part of the worker hook `perflocale_job_run_<type>` and of option names.
	 *
	 * @return string
	 */
	abstract public function get_type(): string;

	/**
	 * Capability the dispatcher needs; the worker checks it again for the stored `created_by` user.
	 *
	 * @return string
	 */
	abstract public function get_required_capability(): string;

	/**
	 * Auto mode runs the job in the background when args_size() reaches this.
	 *
	 * @return int
	 */
	abstract public function get_default_threshold(): int;

	/**
	 * Do the work, inline or from the worker hook: re-entrant (a re-run after a crash gives the same outcome), resumable
	 * or idempotent, and chunked so memory stays bounded.
	 *
	 * $progress is `function(int $processed, int $total, array $detail = []): void`; $detail may carry `stage`,
	 * `label` and `percent`. Call it about every second or every 50 items; the sync path may pass a no-op.
	 *
	 * @param array<string, mixed> $args     Worker args.
	 * @param callable             $progress `function(int, int, array): void`. Use
	 *                                       this to report `(processed, total)`
	 *                                       and an optional detail array. The
	 *                                       sync path may pass a no-op.
	 * @return array<string, mixed> Result payload (small structured data; large
	 *                              outputs are truncated by JobState).
	 *
	 * @throws \Throwable On any error; the worker hook catches + marks failed.
	 */
	abstract public function execute( array $args, callable $progress ): array;

	/**
	 * Whether this dispatch runs in the background: Never = inline, Always = background, Auto = args_size() reaches
	 * effective_threshold().
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
	 * Size of this args set in the job's cost unit (items, bytes, posts) for the Auto threshold; 0 by default.
	 *
	 * @param array<string, mixed> $args
	 * @return int
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Base default; subclasses override to read $args.
	protected function args_size( array $args ): int {
		return 0;
	}

	/**
	 * This dispatch's size in the job's own unit ({@see args_size()}), for the web-request guard.
	 *
	 * @since 1.0.7.4
	 * @param array<string, mixed> $args     Dispatch args.
	 * @param int                  $count_to A count that stops early counts at least this far.
	 * @return int
	 */
	public function estimated_size( array $args, int $count_to = 0 ): int {
		return $this->args_size( $args );
	}

	/**
	 * Seconds the job lock lives without a progress tick (JobLock::DEFAULT_TTL by default). A job that blocks for
	 * minutes without a tick overrides this to its worst case, or calls JobLock::refresh() itself.
	 *
	 * @return int Seconds.
	 */
	public function get_lock_ttl(): int {
		return JobLock::DEFAULT_TTL;
	}

	/**
	 * Whether the worker may run this job in slices.
	 *
	 * Then the worker passes `__perflocale_slice` = `[ 'resume' => <stored result or []> ]`; execute() may return early
	 * with `__perflocale_continue => true` and the same job is scheduled again from that result. The row stays
	 * `running` and the completion hook fires once; an inline or nested run never gets the key.
	 *
	 * @return bool
	 */
	public function supports_continuation(): bool {
		return false;
	}

	/**
	 * Whether a killed worker's job resumes from its last checkpoint instead of failing.
	 *
	 * Only for a continuation job reporting a per-unit `checkpoint` whose units are safe to redo: it gets a short lock,
	 * a dead-man event, and Retry resumes from the checkpoint.
	 *
	 * @since 1.0.7.4
	 * @return bool
	 */
	public function resumes_after_kill(): bool {
		return false;
	}

	/**
	 * Whether a run that ends starts the oldest queued job of its type at once instead of after the busy backoff
	 * (only while the type runs one job at a time).
	 *
	 * @since 1.0.7.4
	 * @return bool
	 */
	public function starts_next_when_done(): bool {
		return false;
	}

	/**
	 * Whether a row with no user (created_by 0) runs as no user instead of failing; needs a capability every user has
	 * (`exist`).
	 *
	 * @since 1.0.7.4
	 * @return bool
	 */
	public function runs_without_user(): bool {
		return false;
	}

	/**
	 * The threshold: the `background_thresholds` setting for this type, else get_default_threshold(), then the filter.
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
		 * Filter the Auto-mode threshold of a job type for this dispatch.
		 *
		 * @hook perflocale/jobs/threshold/<type>
		 * @param int                  $base Threshold from settings (or default).
		 * @param array<string, mixed> $args Current dispatch args.
		 */
		return (int) apply_filters( "perflocale/jobs/threshold/{$type}", $base, $args );
	}
}
