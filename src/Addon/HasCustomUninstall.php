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
 * Opt-in capability for addon cleanup that HasUninstallTargets cannot declare (Action Scheduler hooks, webhooks, logs).
 *
 * Runs after the PurgePlan is computed and validated, before the declarative purge, so the addon's data is still there.
 * Not called when the addon plugin was deleted before the uninstall (manifest-only purge); the admin is warned instead.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasCustomUninstall {

	/**
	 * Run pre-purge custom cleanup.
	 *
	 * @param PurgePlan $plan Read-only snapshot of the declarative targets about to be purged.
	 * @return void
	 * @throws \Throwable Any exception is caught by AddonUninstaller and
	 * logged; does not halt declarative cleanup.
	 */
	public function before_uninstall( PurgePlan $plan ): void;
}
