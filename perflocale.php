<?php
/**
 * Plugin Name: PerfLocale
 * Plugin URI: https://perflocale.com
 * Description: Performance-first multilingual plugin for WordPress. Translate posts, pages, products, taxonomies, strings, and slugs, and keep your site fast.
 * Version: 1.0.7
 * Requires at least: 6.4
 * Tested up to: 7.1
 * Requires PHP: 8.1
 * Author: PerfLocale
 * Author URI: https://perflocale.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: perflocale
 * Domain Path: /languages
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---- Plugin constants ----

define( 'PERFLOCALE_VERSION', '1.0.7' );
define( 'PERFLOCALE_FILE', __FILE__ );
define( 'PERFLOCALE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERFLOCALE_URL', plugin_dir_url( __FILE__ ) );
// The canonical schema ships whole from a fresh install: Schema::create_tables()
// already defines every column and index (including the polymorphic
// translation_links.type + composite object_lang UNIQUE, the object_lookup
// per-object index, and the CLDR plural extra_forms column). No migrate_to_N
// methods exist — a future schema change bumps this and adds one; the
// dispatcher in Migrator picks it up. NOTE: dbDelta can ADD indexes but can
// never RESHAPE one under an existing name — an index reshape needs a
// migrate_to_N that drops the old index first.
define( 'PERFLOCALE_DB_VERSION', 1 );

// ---- Autoloader ----

// Static class-map: FQCN → relative path under src/. Replaces a
// per-class file_exists() syscall with a single array lookup. The PSR-4
// fallback below covers any class not present in the map.
$perflocale_class_map = require PERFLOCALE_DIR . 'autoload-classmap.php';

spl_autoload_register(
	static function ( $classname ) use ( $perflocale_class_map ) {
		if ( isset( $perflocale_class_map[ $classname ] ) ) {
			require_once PERFLOCALE_DIR . 'src/' . $perflocale_class_map[ $classname ];
			return;
		}

		$prefix = 'PerfLocale\\';

		if ( ! str_starts_with( $classname, $prefix ) ) {
			return;
		}

		$relative = substr( $classname, strlen( $prefix ) );
		$file     = PERFLOCALE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

require_once PERFLOCALE_DIR . 'template-tags.php';

// ---- Activation / Deactivation ----

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( is_multisite() ) {
			// A resume marker belongs to the uninstall that wrote it. Once the
			// plugin is being activated again, that uninstall is over: the
			// operator is installing, not recovering, and honouring the marker
			// on the NEXT uninstall would silently skip every blog below it.
			// Cleared here, once per activation, rather than per blog.
			PerfLocale\Database\SiteCleanup::forget_uninstall_progress();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_multisite() && ! empty( $_GET['networkwide'] ) ) {
			// Network activation: iterate sites in chunks so networks with tens
			// of thousands of sites don't spike memory loading every row at once.
			//
			// The default chunk of 100 IDs is tiny (~10 bytes/ID) - the real
			// work is per-site Activator::activate(). Expose via filter so
			// operators of unusual environments (very limited memory, very
			// large networks) can tune it.
			/**
			 * @hook perflocale/activation/chunk_size
			 * @param int $chunk Sites fetched per iteration. Must be >= 1.
			 */
			$chunk      = max( 1, (int) apply_filters( 'perflocale/activation/chunk_size', 100 ) );
			$offset     = 0;
			$network_id = get_current_network_id();

			// Sites that could not be set up, and how many were tried.
			$perflocale_failed_sites = [];
			$perflocale_tried_sites  = 0;

			do {
				$sites = get_sites(
					[
						'number'     => $chunk,
						'offset'     => $offset,
						'fields'     => 'ids',
						// This network only. WP_Site_Query defaults
						// `network_id` to 0, which core documents as "all
						// networks" - and `active_sitewide_plugins`, the option
						// "Network Activate" actually writes, is per-network.
						// Unscoped, activating on one network would provision
						// tables, force the Action Scheduler schema and write
						// recurring crons on every blog of every OTHER network
						// on the installation, for a plugin those networks
						// never activated. Mirrors Deactivator's sweep.
						'network_id' => $network_id,
						// Core already defaults to `orderby => 'id'`; stated
						// explicitly because `offset` paging is only stable
						// against a fixed sort, so the sort must not be
						// something a later edit can quietly drop.
						'orderby'    => 'id',
					]
				);

				foreach ( $sites as $site_id ) {
					++$perflocale_tried_sites;

					switch_to_blog( $site_id );

					try {
						// Force the Action Scheduler schema FIRST on never-visited
						// subsites (AS creates it lazily), as wp_initialize_site
						// does: the Activator's resume enqueue and the recurring
						// schedules below would otherwise hit "Table doesn't
						// exist" on every such blog.
						if ( class_exists( '\\ActionScheduler_StoreSchema' ) ) {
							try {
								$as_schema = new \ActionScheduler_StoreSchema();
								$as_schema->register_tables( true );

								if ( class_exists( '\\ActionScheduler_LoggerSchema' ) ) {
									$as_log_schema = new \ActionScheduler_LoggerSchema();
									$as_log_schema->register_tables( true );
								}
							} catch ( \Throwable $e ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
								error_log( '[PerfLocale] network-activate AS schema init failed for site ' . (int) $site_id . ': ' . $e->getMessage() );
							}
						}

						try {
							// NON-fatal mode, as wp_initialize_site uses. The
							// default mode wp_die()s on a missing table, and
							// wp_die() is not a Throwable: it ends the request
							// at the first such site, before any later site is
							// tried. Non-fatal mode logs the cause and returns
							// false - a missing table, or no language after the
							// seed - so every site is tried and the failures
							// are reported together, below.
							if ( ! PerfLocale\Activator::activate( false ) ) {
								$perflocale_failed_sites[] = $site_id;
							}
						} catch ( \Throwable $e ) {
							// One site's failure must not abort the sweep: every
							// later site would go untried, and the operator
							// would learn about one broken site per attempt.
							$perflocale_failed_sites[] = $site_id;
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
							error_log( '[PerfLocale] network-activate failed for site ' . (int) $site_id . ': ' . $e->getMessage() );
						}

						// Schedule per-blog recurring tasks now so subsites never
						// visited via wp-admin still get their GC + watchdog crons.
						// Only while no site has failed: after that the activation
						// is refused below, and a recurring event on a later site
						// would have nothing to run it.
						if ( [] === $perflocale_failed_sites
						&& class_exists( 'PerfLocale\\Bootstrap' )
						&& method_exists( 'PerfLocale\\Bootstrap', 'ensure_recurring_schedules' )
						) {
							try {
									PerfLocale\Bootstrap::ensure_recurring_schedules();
							} catch ( \Throwable $e ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
								error_log( '[PerfLocale] network-activate ensure_recurring_schedules failed for site ' . (int) $site_id . ': ' . $e->getMessage() );
							}
						}
					} finally {
						restore_current_blog();
					}
				}

				$offset         += $chunk;
				$got_full_chunk = ( count( $sites ) === $chunk );
			} while ( $got_full_chunk );

			// Refuse the activation if any site could not be set up. wp_die()
			// here stops core before it records the plugin as network-active,
			// so PerfLocale never runs on a network with sites that have no
			// tables or no language — a state nothing repairs on its own:
			// Migrator retries only the tables, and only on admin, REST and CLI
			// requests. (A missing settings row is not checked: it reads as the
			// defaults.) Every site is still set up as far as it can be:
			// tables, language, role and caps, and a one-shot resume event.
			// Only the recurring schedules stop at the first failure, so the
			// failed site and those after it get none. Activating again once
			// the cause is fixed re-runs every site; each step is idempotent.
			if ( [] !== $perflocale_failed_sites ) {
				$perflocale_shown = implode( ', ', array_slice( $perflocale_failed_sites, 0, 20 ) )
					. ( count( $perflocale_failed_sites ) > 20 ? ', …' : '' );

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the refused-activation path.
				error_log(
					sprintf(
						'[PerfLocale] network activation refused: %d of %d site(s) could not be set up (site IDs: %s); the lines above name the cause for each.',
						count( $perflocale_failed_sites ),
						$perflocale_tried_sites,
						$perflocale_shown
					)
				);

				wp_die(
					esc_html(
						sprintf(
							/* translators: 1: number of sites that could not be set up, 2: number of sites tried, 3: comma-separated site IDs */
							__( 'PerfLocale could not be set up on %1$d of %2$d sites (site IDs: %3$s), so it has not been activated. The PHP error log names the cause for each site. Fix it, then activate the plugin again.', 'perflocale' ),
							count( $perflocale_failed_sites ),
							$perflocale_tried_sites,
							$perflocale_shown
						)
					),
					esc_html__( 'Plugin Activation Error', 'perflocale' ),
					[ 'back_link' => true ]
				);
			}
		} else {
			PerfLocale\Activator::activate();
		}
	}
);

register_deactivation_hook( __FILE__, [ PerfLocale\Deactivator::class, 'deactivate' ] );

// Multisite: auto-create tables when a new site is added to the network.
add_action(
	'wp_initialize_site',
	static function ( WP_Site $new_site ): void {
		// wp_initialize_site fires whenever a subsite is created via
		// wp_insert_site() — including programmatic/CLI/REST contexts where
		// wp-admin/includes/plugin.php (which defines is_plugin_active_for_network)
		// is not loaded. Load it on demand to avoid a fatal.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
			return;
		}

		switch_to_blog( $new_site->blog_id );

		try {
			// Skip activation if a conflicting multilingual plugin is active for
			// this specific subsite. Mirror the constant check in Bootstrap::init().
			$conflict_constants = [
				'ICL_SITEPRESS_VERSION',
				'POLYLANG_VERSION',
				'TRP_PLUGIN_VERSION',
			];

			foreach ( $conflict_constants as $const ) {
				if ( defined( $const ) ) {
					return;
				}
			}

			// Belt-and-suspenders: check the subsite's own active_plugins option.
			$active_plugins = (array) get_option( 'active_plugins', [] );
			$conflict_files = [
				'sitepress-multilingual-cms/sitepress.php',
				'polylang/polylang.php',
				'polylang-pro/polylang.php',
				'translatepress-multilingual/index.php',
			];

			foreach ( $conflict_files as $conflict_file ) {
				if ( in_array( $conflict_file, $active_plugins, true ) ) {
					return;
				}
			}

			// wp_initialize_site fires BEFORE Action Scheduler bootstraps its
			// per-blog tables, so force the schema FIRST: both the Activator's
			// resume-jobs enqueue below and the as_schedule_* calls further
			// down would otherwise hit "Table doesn't exist" against
			// wp_<id>_actionscheduler_* on every subsite creation (AS creates
			// per-blog tables lazily, and its enqueue catch turns the failure
			// into error-log spam rather than a fatal).
			if ( class_exists( '\\ActionScheduler_StoreSchema' ) ) {
				try {
					$as_schema = new \ActionScheduler_StoreSchema();
					$as_schema->register_tables( true );

					if ( class_exists( '\\ActionScheduler_LoggerSchema' ) ) {
						$as_log_schema = new \ActionScheduler_LoggerSchema();
						$as_log_schema->register_tables( true );
					}
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
					error_log( '[PerfLocale] wp_initialize_site AS schema init failed: ' . $e->getMessage() );
				}
			}

			// Use the non-fatal Activator path here. The default activation
			// flow wp_die()'s on dbDelta failure, which would kill the
			// network admin's site-creation request mid-flight and leave a
			// half-provisioned subsite in `wp_blogs`. The Activator logs to
			// PHP's error log when a table is missing on this code path and
			// returns before the seeding steps. Nothing re-runs activation
			// later: while `perflocale_db_version` is unset, the next request
			// on the new subsite retries only the tables
			// (Migrator::maybe_migrate()). The seeded default language and
			// the settings row stay missing until activation runs on that
			// site again.
			try {
				PerfLocale\Activator::activate( false );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
				error_log( '[PerfLocale] wp_initialize_site activator failed: ' . $e->getMessage() );
			}

			// Schedule per-blog recurring tasks immediately. Without this, a
			// brand-new subsite that's never visited via wp-admin would never
			// have its background-jobs GC + watchdog scheduled (the lazy
			// admin_init handler in Bootstrap only runs on admin pageloads).
			if ( class_exists( 'PerfLocale\\Bootstrap' )
			&& method_exists( 'PerfLocale\\Bootstrap', 'ensure_recurring_schedules' )
			) {
				try {
					PerfLocale\Bootstrap::ensure_recurring_schedules();
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
					error_log( '[PerfLocale] wp_initialize_site ensure_recurring_schedules failed: ' . $e->getMessage() );
				}
			}
		} finally {
			restore_current_blog();
		}
	},
	200
);

// Multisite: clean up when a site is permanently deleted from the network.
// FORCES a full purge regardless of the `delete_data_on_uninstall` setting.
// Reason: blog deletion is irreversible — WP drops the blog's posts /
// options / attachments along with the wp_blogs row. Keeping plugin tables
// (`wp_<id>_perflocale_*`) for a no-longer-existing blog is just orphan
// data. The `delete_data_on_uninstall` preference applies to plugin
// UNINSTALL (a reversible action where the operator might want to keep
// data for a later reinstall) — it does NOT apply to blog DELETION,
// where nothing else survives anyway.
add_action(
	'wp_uninitialize_site',
	static function ( WP_Site $old_site ): void {
		switch_to_blog( $old_site->blog_id );

		try {
			// single_site_teardown=true: only THIS subsite is being deleted, so
			// network-global stores (wp_usermeta) must be left intact for the
			// surviving sites.
			PerfLocale\Database\SiteCleanup::purge_current_site( true, true );
		} finally {
			restore_current_blog();
		}
	},
	// Priority 5 — BELOW core's own wp_uninitialize_site table-dropper (which
	// registers at priority 10 in ms-default-filters.php). Core drops the
	// deleted blog's wp_<id>_options / postmeta / termmeta, so PerfLocale must
	// run FIRST while those are still readable: full_purge() reads addon
	// manifests from options. An addon's purge runs only if this blog chose
	// to delete its data (or a filter says so), and then with the
	// single-site flag: it removes only what belongs to this blog and leaves
	// the network-global targets (site options, user meta) to the sites that
	// still use them. Skipped, it costs little: the addon's
	// perflocale_-prefixed tables still go with Schema::drop_tables() and its
	// per-blog rows with core's drop - only its custom before_uninstall()
	// cleanup does not run. PerfLocale only drops its OWN perflocale_*
	// tables/options/uploads-subdir, so running before core is safe.
	5
);

// ---- Screen option save filters (must register before set_screen_options() in admin.php) ----
//
// Every PerfLocale list page that exposes "Rows per page" in Screen Options
// uses the same integer-coerce save logic. One closure reused across the
// four hooks - no repeated callback allocation, single place to audit.
$perflocale_per_page_save = static function ( $status, $option, $value ) {
	// (int), not absint(): absint( -5 ) is 5, so a negative request used to
	// be saved as a positive row count instead of being rejected.
	$value = (int) $value;

	if ( $value < 1 ) {
		return $status;
	}

	// ONE ceiling, shared with every screen's READ path - see
	// Helper::normalize_per_page(). Capping only on save left previously
	// stored oversized values driving the query untouched.
	return min( $value, \PerfLocale\Helper::per_page_ceiling( (string) $option ) );
};


add_filter( 'set_screen_option_perflocale_strings_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_languages_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_translations_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_assignments_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_glossary_per_page', $perflocale_per_page_save, 10, 3 );

// ---- Global helper functions ----

if ( ! function_exists( 'perflocale' ) ) {
	/**
	 * Get the PerfLocale helper instance for the fluent API.
	 *
	 * Usage:
	 * perflocale()->slug() → "fr"
	 * perflocale()->locale() → "fr_FR"
	 * perflocale()->name() → "French"
	 * perflocale()->native_name() → "Français"
	 * perflocale()->is_rtl() → false
	 * perflocale()->switcher() → "<nav>...</nav>"
	 *
	 * @api  Stable API surface — semver-bound; safe for themes, addons,
	 *       and external plugins to depend on. Returns the {@see \PerfLocale\Helper}
	 *       singleton whose public methods are themselves semver-bound.
	 *
	 * @return PerfLocale\Helper
	 */
	function perflocale(): PerfLocale\Helper {
		return PerfLocale\Helper::get_instance();
	}
}

// ---- Bootstrap ----

// Runs after the global helper functions above are defined: listeners of
// perflocale/loaded, which Bootstrap::init() fires, may call perflocale().
PerfLocale\Bootstrap::init();
