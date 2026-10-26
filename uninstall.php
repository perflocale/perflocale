<?php
/**
 * PerfLocale uninstall script; the per-site cleanup is SiteCleanup's, shared with wp_uninitialize_site.
 *
 * The multisite sweep stops between blogs within a budget and leaves a resume marker: re-installing and deleting again
 * WITHOUT activating finishes it (core runs this file for an inactive plugin); WP-CLI finishes in one pass.
 *
 * @package PerfLocale
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// No autoloader during uninstall: every class the purge path reaches must be listed (UninstallIncludeClosureTest).
// is_file(): a host may have removed part of the plugin; SiteCleanup checks class_exists() before optional steps.
$perflocale_includes = [
	__DIR__ . '/src/Admin/TranslatorRole.php',
	__DIR__ . '/src/Database/Schema.php',
	// Schema::drop_tables() forwards to SchemaWrites.
	__DIR__ . '/src/Database/SchemaWrites.php',
	// CacheManager::GROUPS: the object-cache groups SiteCleanup retires.
	__DIR__ . '/src/Cache/CacheManager.php',
	// CacheManager's generation writes take their option out of notoptions through it.
	__DIR__ . '/src/Database/NotOptions.php',
	// The uninstall lists SiteCleanup reads.
	__DIR__ . '/src/Database/DataRegistry.php',
	__DIR__ . '/src/Database/SiteCleanup.php',
	__DIR__ . '/src/Addon/AddonInterface.php',
	// The whole closure of AddonUninstaller::purge(), dependencies first: a missing class throws inside SiteCleanup's
	// catch and silently leaves addon data behind.
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
 * Whether the CURRENT site opted in to deleting its data; on multisite call it inside that blog.
 *
 * SiteCleanup::blog_wants_data_deleted() is the test the network sweep applies too.
 *
 * @return bool True if the site opted in to full data deletion.
 */
function perflocale_should_delete_data(): bool {
	return \PerfLocale\Database\SiteCleanup::blog_wants_data_deleted();
}

// The plugin directory is gone: nothing can run.
if ( ! class_exists( \PerfLocale\Database\SiteCleanup::class ) ) {
	return;
}

// Each site's own `delete_data_on_uninstall` decides; on multisite it is read inside the per-site loop.
if ( is_multisite() ) {
	// Chunked, budget-bounded, failure-isolated and resumable; purge_network() says why `network_id` is not scoped.
	$perflocale_sweep = \PerfLocale\Database\SiteCleanup::purge_network(
		\PerfLocale\Database\SiteCleanup::uninstall_deadline()
	);

	// Action Scheduler tables are per blog, so purge_network() clears them; this repeats it on the current blog only.
	// It is not a network-wide pass (none is possible): never drop the per-blog clear for it.
	\PerfLocale\Database\SiteCleanup::network_clear_action_scheduler();

	// Network options (wp_sitemeta) only after a complete sweep: they include the resume marker.
	if ( $perflocale_sweep['complete'] ) {
		// Every network of the installation, not only the calling one.
		\PerfLocale\Database\SiteCleanup::purge_network_scope();
	} else {
		// Always logged: the plugin files are about to go, so no screen can show it.
		// A failure wins over running out of budget: it needs its cause fixed before another pass.
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
