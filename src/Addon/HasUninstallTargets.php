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
 * Opt-in capability interface for addons that want their data purged when
 * uninstalled (either individually via Settings › Addons, or in bulk when
 * the whole plugin is uninstalled with `delete_data_on_uninstall = true`).
 *
 * The targets returned here drive the declarative purge. Anything that
 * can't be expressed declaratively - ActionScheduler hooks, external
 * API cancellations, custom cache groups, log files - belongs in
 * HasCustomUninstall::before_uninstall() instead.
 *
 * === Security: prefix enforcement ===
 *
 * AddonUninstaller validates every target against the addon's enforced
 * namespace BEFORE executing any DELETE/DROP. A malicious or buggy addon
 * returning 'wp_posts' in its tables key will throw InvalidArgumentException
 * (hard namespaces) or be silently skipped with a warning (soft namespaces):
 *
 * HARD (throws on violation):
 * • tables: MUST match {$wpdb->prefix}perflocale_addon_{$id}_*
 * • options: MUST start with 'perflocale_'
 * • site_options:MUST start with 'perflocale_'
 * • capabilities:MUST start with 'perflocale_'
 *
 * SOFT (skipped with warning on violation):
 * • meta keys: MUST start with 'perflocale_' or '_perflocale_'
 * • transients: MUST start with 'perflocale_'
 * • cron_hooks: MUST start with 'perflocale_'
 *
 * This boundary means a supply-chain-compromised addon can't use PerfLocale
 * to wipe core WordPress tables. The admin's trust is in the addon's own
 * boot() code (like any WP plugin), not in its uninstall-target manifest.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasUninstallTargets {

	/**
	 * Return everything the addon owns, keyed by target type.
	 *
	 * All keys are optional; return [] if the addon owns nothing of a type.
	 *
	 * 'tables' string[] short names - prefix auto-applied
	 * (e.g. 've_documents' → {prefix}perflocale_addon_ve_ve_documents)
	 * 'options' string[] MUST start with 'perflocale_'
	 * 'site_options' string[] multisite network options, same rule
	 * 'transients' string[] prefixes (NOT LIKE wildcards) - helper escapes
	 * e.g. 'perflocale_ve_cache_' matches any
	 * transient starting with that string
	 * 'meta' array<'post'|'user'|'term'|'comment', string[]>
	 * meta keys MUST start with 'perflocale_'
	 * or '_perflocale_'
	 * 'capabilities' string[] caps to remove_cap() from every role
	 * 'cron_hooks' string[] wp_unschedule_hook() targets
	 *
	 * Example:
	 * return [
	 * 'tables' => ['ve_documents', 've_revisions'],
	 * 'options' => ['perflocale_ve_settings'],
	 * 'transients' => ['perflocale_ve_cache_'],
	 * 'meta' => [
	 * 'post' => ['_perflocale_ve_doc_id'],
	 * 'user' => ['perflocale_ve_last_opened'],
	 * ],
	 * 'capabilities' => ['perflocale_ve_edit'],
	 * 'cron_hooks' => ['perflocale_ve_sync'],
	 * ];
	 *
	 * @return array<string, array<int|string, mixed>>
	 */
	public function get_uninstall_targets(): array;
}
