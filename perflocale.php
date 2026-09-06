<?php
/**
 * Plugin Name: PerfLocale
 * Plugin URI: https://perflocale.com
 * Description: Performance-first multilingual plugin for WordPress. Translate posts, pages, products, taxonomies, strings, and slugs without slowing your site down.
 * Version: 1.0.2
 * Requires at least: 6.4
 * Tested up to: 7.1
 * Requires PHP: 8.1
 * Author: Alex Georgiev
 * Author URI: https://alexgv.com
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

define( 'PERFLOCALE_VERSION', '1.0.2' );
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
					switch_to_blog( $site_id );

					try {
						PerfLocale\Activator::activate();

						// Force AS schema on never-visited subsites (AS creates it
						// lazily) so the ensure_recurring_schedules writes below
						// land; without it these blogs get no recurring crons.
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

						// Schedule per-blog recurring tasks now so subsites never
						// visited via wp-admin still get their GC + watchdog crons.
						if ( class_exists( 'PerfLocale\\Bootstrap' )
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
			// PHP's error log when a table is missing on this code path; the
			// next admin pageload on the new subsite re-runs activation via
			// the standard fatal-on-failure path.
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
	// manifests from options to run addon uninstallers. After core's drop
	// that read returns empty → skipped addon cleanup. PerfLocale only drops
	// its OWN perflocale_* tables/options/uploads-subdir, so running before
	// core is safe.
	5
);

// ---- Screen option save filters (must register before set_screen_options() in admin.php) ----
//
// Every PerfLocale list page that exposes "Rows per page" in Screen Options
// uses the same integer-coerce save logic. One closure reused across the
// four hooks - no repeated callback allocation, single place to audit.
$perflocale_per_page_save = static function ( $status, $option, $value ) {
	return absint( $value );
};

add_filter( 'set_screen_option_perflocale_strings_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_languages_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_translations_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_assignments_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_glossary_per_page', $perflocale_per_page_save, 10, 3 );

// ---- Bootstrap ----

PerfLocale\Bootstrap::init();

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
