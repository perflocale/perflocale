<?php
/**
 * Immutable plan for an addon purge operation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What AddonUninstaller::purge() will delete, from AddonUninstaller::plan().
 *
 * Table names are full (prefixed), every other name as declared; estimated_rows is an indexed COUNT at plan time.
 */
final class PurgePlan {

	/**
	 * @param string                            $addon_id Addon identifier.
	 * @param array<int, string>                $tables Full table names with prefix.
	 * @param array<int, string>                $options Option names.
	 * @param array<int, string>                $site_options Network-option names (multisite).
	 * @param array<int, string>                $transient_prefixes Transient prefixes (no wildcards).
	 * @param array<string, array<int, string>> $meta object_type => meta keys.
	 * @param array<int, string>                $capabilities Caps to remove from roles.
	 * @param array<int, string>                $cron_hooks Cron hooks to unschedule.
	 * @param int                               $estimated_rows Sum of estimated rows/keys across all targets.
	 * @param bool                              $had_custom_uninstall Whether the addon implements HasCustomUninstall.
	 */
	public function __construct(
		public readonly string $addon_id,
		public readonly array $tables,
		public readonly array $options,
		public readonly array $site_options,
		public readonly array $transient_prefixes,
		public readonly array $meta,
		public readonly array $capabilities,
		public readonly array $cron_hooks,
		public readonly int $estimated_rows,
		public readonly bool $had_custom_uninstall,
	) {}

	/**
	 * Build an empty plan (no targets).
	 *
	 * @param string $addon_id Addon identifier.
	 * @return self
	 */
	public static function empty( string $addon_id ): self {
		return new self( $addon_id, [], [], [], [], [], [], [], 0, false );
	}

}
