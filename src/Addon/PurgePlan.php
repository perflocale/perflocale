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
 * Read-only snapshot of what AddonUninstaller::purge() WILL delete.
 *
 * Produced by AddonUninstaller::plan(). Table names here are FULL names
 * (with {$wpdb->prefix} already applied). Meta keys, option names, etc.
 * are as-declared.
 *
 * estimated_rows is computed by counting rows per target type via indexed
 * COUNT queries - approximate but cheap. Accurate for small sites, may
 * drift on huge sites if concurrent writes happen between plan() and purge().
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
