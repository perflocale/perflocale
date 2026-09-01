<?php
/**
 * Immutable result of an addon purge operation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only summary of what AddonUninstaller::purge() actually deleted.
 *
 * Returned by AddonUninstaller::purge(). Counts here are actual DB deletions
 * (not estimates). A non-empty $errors array means partial cleanup - the
 * purge continued past each soft failure and recorded it.
 */
final class PurgeResult {

	/**
	 * @param PurgePlan         $plan The plan that was executed.
	 * @param int               $tables_dropped DROP TABLE statements that succeeded.
	 * @param int               $options_deleted delete_option() calls that returned true.
	 * @param int               $site_options_deleted delete_site_option() calls that returned true.
	 * @param int               $transient_rows_deleted wp_options rows removed by transient purge.
	 * @param array<string,int> $meta_rows_deleted object_type => rows deleted.
	 * @param int               $capabilities_removed remove_cap() calls applied.
	 * @param int               $cron_hooks_unscheduled wp_unschedule_hook() calls.
	 * @param float             $duration_ms Wall-clock duration.
	 * @param array<int,string> $errors Human-readable soft-error messages.
	 * @param bool              $custom_uninstall_ran Whether before_uninstall() executed.
	 * @param ?string           $custom_uninstall_error Message if before_uninstall() threw.
	 */
	public function __construct(
		public readonly PurgePlan $plan,
		public readonly int $tables_dropped,
		public readonly int $options_deleted,
		public readonly int $site_options_deleted,
		public readonly int $transient_rows_deleted,
		public readonly array $meta_rows_deleted,
		public readonly int $capabilities_removed,
		public readonly int $cron_hooks_unscheduled,
		public readonly float $duration_ms,
		public readonly array $errors,
		public readonly bool $custom_uninstall_ran,
		public readonly ?string $custom_uninstall_error,
	) {}

	/**
	 * Total rows deleted across all target types.
	 *
	 * @return int
	 */
	public function total_rows_deleted(): int {
		return $this->tables_dropped
			+ $this->options_deleted
			+ $this->site_options_deleted
			+ $this->transient_rows_deleted
			+ array_sum( $this->meta_rows_deleted )
			+ $this->capabilities_removed
			+ $this->cron_hooks_unscheduled;
	}

}
