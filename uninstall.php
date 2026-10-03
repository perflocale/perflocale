<?php
/**
 * PerfLocale uninstall script.
 *
 * Fired when the plugin is deleted via the WordPress admin. Delegates the
 * actual per-site cleanup to PerfLocale\Database\SiteCleanup so the same
 * code path runs for both plugin uninstall and wp_uninitialize_site (when
 * a network admin permanently deletes a subsite).
 *
 * Handles both single-site and multisite installations. The multisite path
 * is chunked and budget-bounded: it stops BETWEEN blogs rather than being
 * killed inside one, and leaves a resume marker so a network too large for
 * one request can be finished by re-installing the plugin and deleting it
 * again WITHOUT activating it — core runs this file for an installed,
 * inactive plugin, and the next pass carries on from the marker. From
 * WP-CLI, `wp plugin uninstall perflocale` finishes it in one pass.
 * See SiteCleanup::purge_network().
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
	// ⚠️ THE FULL CLOSURE, not "one more require". AddonUninstaller::plan()
	// calls AddonSchemaManager::validate_addon_id() as its FIRST statement, and
	// that class was missing here — so the entire manifest-driven addon purge
	// threw "class not found" and the throw was swallowed, leaving addon tables
	// and options behind on every uninstall with no error anywhere. Same shape
	// as the PrivacyIntegration load-order note above.
	//
	// plan() also reaches PurgePlan (its return type) and AddonManifestWriter
	// (reads/refreshes the manifest), so both are required or the path throws a
	// few lines later instead. Listed BEFORE AddonUninstaller: none of these
	// alias a constant at class-definition time today, but the ordering costs
	// nothing and stops a future constant alias from reintroducing exactly the
	// bug this comment describes.
	//
	// PurgeResult is the SAME BUG a second time: uninstall() constructs it on
	// every exit path and returns it, so without this line the purge throws
	// after the deletions and the documented perflocale/addon/uninstalled
	// action never fires — swallowed by the catch in SiteCleanup. A class this
	// file does not require is only as safe as the last person who checked;
	// the reachable set is asserted, not eyeballed.
	__DIR__ . '/src/Addon/AddonMigrationErrors.php',
	__DIR__ . '/src/Addon/AddonSchemaManager.php',
	__DIR__ . '/src/Addon/AddonManifestWriter.php',
	__DIR__ . '/src/Addon/PurgePlan.php',
	__DIR__ . '/src/Addon/PurgeResult.php',
	__DIR__ . '/src/Addon/AddonUninstaller.php',
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
 * Delegates to SiteCleanup::blog_wants_data_deleted(), whose test the
 * network sweep's per-blog classification applies too, so the two cannot
 * drift apart on what "delete my data" means. Kept as a function because
 * this file exposes it.
 *
 * @return bool True if the site opted in to full data deletion.
 */
function perflocale_should_delete_data(): bool {
	return \PerfLocale\Database\SiteCleanup::blog_wants_data_deleted();
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
	// The per-blog sweep lives in SiteCleanup, which keeps this file to the
	// decisions that belong to an uninstall. It is chunked, budget-bounded,
	// isolates one blog's failure from the rest, and records where it
	// stopped. Its docblock carries the reasoning, including why `network_id`
	// is deliberately NOT scoped here and why a background job cannot do this
	// work.
	$perflocale_sweep = \PerfLocale\Database\SiteCleanup::purge_network(
		\PerfLocale\Database\SiteCleanup::uninstall_deadline()
	);

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
	// drop on a network uninstall.
	//
	// ONLY on the pass that finished the sweep. That list contains the resume
	// marker itself, so deleting it after an interrupted pass would throw away
	// the one record of which blogs are still to do.
	if ( $perflocale_sweep['complete'] ) {
		// Every network of the installation, not only the calling one — see
		// the method.
		\PerfLocale\Database\SiteCleanup::purge_network_scope();
	} else {
		// Logged unconditionally, not behind WP_DEBUG_LOG: by the time this
		// happens the plugin's files are about to be deleted, so there is no
		// UI left to surface it in, and the operator has to act.
		//
		// The two causes need different advice. Running out of budget needs
		// only another pass; a site that FAILED needs its cause fixed first,
		// or the next pass fails on it again — and under WP-CLI there is no
		// budget at all, so "ran out of time" would be simply untrue. A pass
		// that did both gets the failure advice: it is the one that blocks.
		if ( $perflocale_sweep['list_error'] ) {
			$perflocale_cause = 'could not read the list of sites';
			$perflocale_next  = 'The line above has the database error. Once the database is healthy, re-install the plugin and delete it again WITHOUT activating it - the next pass carries on from the last site purged.';
		} elseif ( $perflocale_sweep['failed'] > 0 ) {
			$perflocale_cause = sprintf( 'could not purge %d site(s)', $perflocale_sweep['failed'] );
			$perflocale_next  = 'The lines above name each site and the error. Fix the cause, then re-install the plugin and delete it again WITHOUT activating it - the next pass starts at the first site that failed.';
		} elseif ( $perflocale_sweep['meta_error'] ) {
			$perflocale_cause = 'could not delete PerfLocale user preferences';
			$perflocale_next  = 'Every site is purged; the line above has the database error. Once the database is healthy, re-install the plugin and delete it again WITHOUT activating it - the next pass retries only that step.';
		} else {
			$perflocale_cause = 'ran out of execution budget';
			$perflocale_next  = 'Re-install the plugin and delete it again WITHOUT activating it - the next pass resumes from there. From WP-CLI, `wp plugin uninstall perflocale` finishes it in one pass, because WP-CLI has no execution limit.';
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the incomplete-uninstall path.
		error_log(
			sprintf(
				'[PerfLocale] uninstall %1$s: %2$d site(s) purged in this pass, %3$d already done earlier, %4$d of %5$d not yet purged. %6$s Nothing is deleted twice; the resume marker expires after a day, after which a fresh pass starts from the beginning.',
				$perflocale_cause,
				$perflocale_sweep['purged'],
				$perflocale_sweep['skipped'],
				$perflocale_sweep['remaining'],
				$perflocale_sweep['total'],
				$perflocale_next
			)
		);

		unset( $perflocale_cause, $perflocale_next );
	}

	unset( $perflocale_sweep );
} else {
	\PerfLocale\Database\SiteCleanup::purge_current_site( perflocale_should_delete_data() );
}
