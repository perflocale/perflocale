<?php
/**
 * Addon uninstall targets capability.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in capability declaring the data an addon's uninstall purges (from Settings > Addons, or with the plugin).
 *
 * Every target is checked against the addon's namespace before any DELETE or DROP, so an addon cannot wipe core data:
 * a violation in tables, options, site_options or capabilities aborts the whole purge; meta, transient and cron_hooks
 * violations are skipped with a warning.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasUninstallTargets {

	/**
	 * Return everything the addon owns, keyed by target type; every key is optional.
	 *
	 * 'tables': short names (prefixed to {prefix}perflocale_addon_{id}_); 'options', 'site_options', 'capabilities',
	 * 'cron_hooks' and 'transients' (prefixes, not LIKE patterns): names starting 'perflocale_'; 'meta':
	 * array<'post'|'user'|'term'|'comment', string[]> of keys starting 'perflocale_' or '_perflocale_'.
	 *
	 * @return array<string, array<int|string, mixed>>
	 */
	public function get_uninstall_targets(): array;
}
