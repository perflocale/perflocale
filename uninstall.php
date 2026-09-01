<?php
/**
 * PerfLocale uninstall script.
 *
 * Fired when the plugin is deleted via the WordPress admin. Delegates the
 * actual per-site cleanup to PerfLocale\Database\SiteCleanup so the same
 * code path runs for both plugin uninstall and wp_uninitialize_site (when
 * a network admin permanently deletes a subsite).
 *
 * Handles both single-site and multisite installations.
 *
 * @package PerfLocale
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Uninstall runs in isolation - the plugin's spl_autoload_register is NOT
// registered. Explicitly pull in the classes we need so SiteCleanup can
// resolve TranslatorRole + AddonUninstaller without our autoloader.
//
// Wrapped in is_file checks because the plugin directory may have been
// partially removed before uninstall fires on some hosts. The SiteCleanup
// class falls back to inline lists when the include fails.
$perflocale_includes = [
	__DIR__ . '/src/Admin/TranslatorRole.php',
	__DIR__ . '/src/Database/Schema.php',
	// PrivacyIntegration MUST load before SiteCleanup — SiteCleanup's
	// USER_META_KEYS class constant is aliased to
	// PrivacyIntegration::USER_META_KEYS at class-definition time, so
	// PHP needs the PrivacyIntegration class available the moment
	// SiteCleanup.php is required. The autoloader isn't registered
	// during uninstall, so without this entry SiteCleanup.php throws
	// "Class PerfLocale\Admin\PrivacyIntegration not found" and the
	// cron/option sweep silently skips, leaking scheduled events past
	// uninstall.
	__DIR__ . '/src/Admin/PrivacyIntegration.php',
	// CacheManager carries the canonical list of plugin-owned object-cache
	// groups (GROUPS constant). SiteCleanup::flush_cache_groups() iterates
	// it on full uninstall so the L2 (Redis/Memcached) cache doesn't keep
	// serving ghost entries past install for up to 12h. Loaded here
	// (rather than as a soft dependency in SiteCleanup) because uninstall
	// has no autoloader.
	__DIR__ . '/src/Cache/CacheManager.php',
	__DIR__ . '/src/Database/SiteCleanup.php',
	__DIR__ . '/src/Addon/AddonInterface.php',
	__DIR__ . '/src/Addon/AddonUninstaller.php',
	__DIR__ . '/src/Addon/AddonMigrationErrors.php',
];

foreach ( $perflocale_includes as $perflocale_include ) {
	if ( is_file( $perflocale_include ) ) {
		require_once $perflocale_include;
	}
}
unset( $perflocale_includes, $perflocale_include );

/**
 * Read the "delete data on uninstall" decision from the CURRENT site's
 * settings. Must run inside the correct blog context on multisite.
 *
 * @return bool True if the site opted in to full data deletion.
 */
function perflocale_should_delete_data(): bool {
	$settings = get_option( 'perflocale_settings', [] );
	return ! empty( $settings['delete_data_on_uninstall'] );
}

// Bail out cleanly if the SiteCleanup class never loaded — happens on
// hosts that wiped the plugin directory before triggering uninstall.
// In that case there is nothing useful we can do without our own code.
if ( ! class_exists( \PerfLocale\Database\SiteCleanup::class ) ) {
	return;
}

// Execute the cleanup path chosen by each site independently.
//
// On multisite, the decision is read INSIDE the per-site loop so every
// subsite's own `delete_data_on_uninstall` preference is respected. The
// previous behavior read this flag once from whatever blog happened to be
// current when uninstall.php loaded (usually the network's main site) and
// applied it to every subsite - which could silently purge data on
// subsites whose admin explicitly chose to preserve it.
if ( is_multisite() ) {
	// Deliberately NOT scoped with `network_id`. Deactivator's sweep is - it
	// acts on the one network whose admin pressed the button - but uninstall
	// removes the plugin's FILES from the whole installation, so every blog of
	// every network loses the code that owns these rows. Narrowing this query
	// would strand sibling networks' tables, options and schedules forever
	// with nothing left on disk to clean them up.
	$perflocale_sites = get_sites(
		[
			'number' => 0,
			'fields' => 'ids',
		]
	);

	foreach ( $perflocale_sites as $perflocale_site_id ) {
		switch_to_blog( $perflocale_site_id );

		// try/finally so a fatal on one site doesn't leave subsequent
		// iterations running against the wrong blog context.
		try {
			\PerfLocale\Database\SiteCleanup::purge_current_site( perflocale_should_delete_data() );
		} finally {
			restore_current_blog();
		}
	}

	unset( $perflocale_sites, $perflocale_site_id );

	// Action Scheduler's tables are PER-BLOG on multisite, not network-wide:
	// ActionScheduler_Abstract_Schema::get_full_table_name() builds its names
	// from `$wpdb->prefix`, so every blog owns its own
	// wp_<id>_actionscheduler_* set and a sweep only ever reaches the blog it
	// is switched into. The per-site loop above is therefore what clears the
	// network - it calls `clear_action_scheduler_orphans()` once per blog,
	// switched in.
	//
	// The call below is a cheap belt-and-braces repeat on whatever blog is
	// current now; `network_clear_action_scheduler()` is a one-line alias for
	// that same per-blog method and the "network" in its name is historical.
	// What it is NOT is a network-scope pass that catches blogs the loop
	// missed - no such pass is possible, so do not delete the loop in favour
	// of it.
	\PerfLocale\Database\SiteCleanup::network_clear_action_scheduler();

	// Network-global options live in wp_sitemeta, not any blog's wp_options,
	// so the per-site purge loop above never touches them. Remove the plugin's
	// network-scoped keys here (canonical list on SiteCleanup so the orphan
	// audit can enforce coverage). These are regenerable cache tokens, safe to
	// drop unconditionally on a network uninstall.
	foreach ( \PerfLocale\Database\SiteCleanup::NETWORK_OPTIONS as $perflocale_network_option ) {
		delete_site_option( $perflocale_network_option );
	}
	unset( $perflocale_network_option );
} else {
	\PerfLocale\Database\SiteCleanup::purge_current_site( perflocale_should_delete_data() );
}
