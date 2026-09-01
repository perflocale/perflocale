<?php
/**
 * Addon custom uninstall capability.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in capability interface for addons that need custom cleanup logic
 * not expressible as declarative HasUninstallTargets - ActionScheduler
 * unschedule, external webhook cancellation, custom cache-group flushes,
 * log file removal, etc.
 *
 * === When this runs ===
 *
 * AddonUninstaller::purge() executes in this order:
 *
 * 1. Validate prefixes on declared targets (security)
 * 2. Compute PurgePlan (read-only snapshot of what will be deleted)
 * 3. do_action('perflocale/addon/before_uninstall', $addon_id, $plan)
 * 4. IF addon class is present AND implements HasCustomUninstall:
 * $addon->before_uninstall($plan);
 * (errors caught + logged; do NOT halt step 5)
 * 5. Execute declarative purge (tables, options, meta batched, etc.)
 * 6. Delete manifest option
 * 7. do_action('perflocale/addon/uninstalled', $addon_id, $result)
 *
 * So your before_uninstall() runs AFTER the plan is computed but BEFORE
 * declarative cleanup. You can still read your own tables, options, and
 * meta - they're deleted in step 5.
 *
 * === Limitation: manifest-only purge ===
 *
 * When the addon plugin is deleted from disk BEFORE PerfLocale's uninstall
 * runs, AddonUninstaller falls back to manifest-only purge. Your class is
 * gone, so before_uninstall() cannot be called. The manifest records that
 * the addon HAD a HasCustomUninstall implementation - the admin sees a
 * warning in `perflocale_addon_migration_errors` reminding them to manually
 * clear ActionScheduler hooks / external resources.
 *
 * Practical rule: in production, admins should uninstall addons VIA
 * PerfLocale's Settings UI, not by deleting the addon plugin. Document this
 * for your addon users. The manifest-only fallback is a safety net, not the
 * preferred path.
 *
 * === Error handling ===
 *
 * Exceptions thrown from before_uninstall() are caught + recorded to the
 * addon migration error log + admin notice shown, but declarative cleanup
 * STILL RUNS. The plugin must always be uninstallable even when a third-
 * party addon's custom cleanup is buggy.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasCustomUninstall {

	/**
	 * Run pre-purge custom cleanup.
	 *
	 * @param PurgePlan $plan Read-only snapshot of declarative targets about
	 * to be purged. Useful for logging / sizing.
	 * @return void
	 * @throws \Throwable Any exception is caught by AddonUninstaller and
	 * logged; does not halt declarative cleanup.
	 */
	public function before_uninstall( PurgePlan $plan ): void;
}
