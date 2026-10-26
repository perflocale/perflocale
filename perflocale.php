<?php
/**
 * Plugin Name: PerfLocale
 * Plugin URI: https://perflocale.com
 * Description: Performance-first multilingual plugin for WordPress. Translate posts, pages, products, taxonomies, strings, and slugs, and keep your site fast.
 * Version: 1.0.7.4
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

define( 'PERFLOCALE_VERSION', '1.0.7.4' );
define( 'PERFLOCALE_FILE', __FILE__ );
define( 'PERFLOCALE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERFLOCALE_URL', plugin_dir_url( __FILE__ ) );
// Schema::create_tables() ships the whole schema. dbDelta cannot reshape an index under an
// existing name: an index reshape needs a migrate_to_N that drops the old index first.
define( 'PERFLOCALE_DB_VERSION', 1 );

// ---- Autoloader ----

// Static class map (FQCN to path under src/); the PSR-4 fallback below covers the rest.
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
			// A resume marker belongs to the uninstall that wrote it: activation ends that
			// uninstall, and honouring it on the next one would skip every blog below it.
			PerfLocale\Database\SiteCleanup::forget_uninstall_progress();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_multisite() && ! empty( $_GET['networkwide'] ) ) {
			// Network activation: walk sites in chunks to keep memory flat on large networks.
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
						// This network only: WP_Site_Query's network_id 0 means all networks,
						// and network activation is per network. Mirrors Deactivator's sweep.
						'network_id' => $network_id,
						// Explicit: offset paging is only stable against a fixed sort.
						'orderby'    => 'id',
					]
				);

				foreach ( $sites as $site_id ) {
					++$perflocale_tried_sites;

					switch_to_blog( $site_id );

					try {
						// Force the Action Scheduler schema first (AS creates it lazily): the
						// resume enqueue and the recurring schedules below need its tables.
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
							// Non-fatal mode: the default wp_die()s (not a Throwable) at the
							// first missing table, so later sites would go untried.
							if ( ! PerfLocale\Activator::activate( false ) ) {
								$perflocale_failed_sites[] = $site_id;
							}
						} catch ( \Throwable $e ) {
							// One site's failure must not abort the sweep for the rest.
							$perflocale_failed_sites[] = $site_id;
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
							error_log( '[PerfLocale] network-activate failed for site ' . (int) $site_id . ': ' . $e->getMessage() );
						}

						// Schedule per-blog recurring tasks so never-visited subsites get their
						// crons; only while no site has failed, as a failure refuses activation below.
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

			// Refuse the activation when any site failed: wp_die() here stops core recording the
			// plugin as network-active, and nothing repairs a site without tables or a language.
			// Each step is idempotent, so activating again re-runs every site.
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
		// May fire from CLI or REST, where wp-admin/includes/plugin.php is not loaded.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
			return;
		}

		switch_to_blog( $new_site->blog_id );

		try {
			// Skip a subsite with a conflicting multilingual plugin; mirrors Bootstrap::init().
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

			// AS creates per-blog tables lazily, after this hook: force its schema first so the
			// resume enqueue and the as_schedule_* calls below find their tables.
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

			// Non-fatal mode: the default wp_die()s on a dbDelta failure, which would abort the
			// network admin's site-creation request and leave a half-provisioned subsite.
			try {
				PerfLocale\Activator::activate( false );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
				error_log( '[PerfLocale] wp_initialize_site activator failed: ' . $e->getMessage() );
			}

			// Schedule per-blog recurring tasks: Bootstrap's lazy admin_init path never runs
			// on a subsite nobody visits in wp-admin.
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

// Multisite: purge a deleted site's data regardless of `delete_data_on_uninstall` (that
// setting is for reversible plugin uninstall; blog deletion is irreversible).
add_action(
	'wp_uninitialize_site',
	static function ( WP_Site $old_site ): void {
		switch_to_blog( $old_site->blog_id );

		try {
			// Single-site teardown: network-global stores (wp_usermeta) stay for the other sites.
			PerfLocale\Database\SiteCleanup::purge_current_site( true, true );
		} finally {
			restore_current_blog();
		}
	},
	// Priority 5: before core's priority-10 table drop, because full_purge() reads add-on
	// manifests from this blog's options. See docs-ai/11-code-rationale.md, "perflocale.php: site deletion".
	5
);

// ---- Screen option save filters (must register before set_screen_options() in admin.php) ----
$perflocale_per_page_save = static function ( $status, $option, $value ) {
	// (int), not absint(): absint( -5 ) is 5, so a negative request would save as a positive count.
	$value = (int) $value;

	if ( $value < 1 ) {
		return $status;
	}

	// The same ceiling as every screen's read path (Helper::normalize_per_page()).
	return min( $value, \PerfLocale\Helper::per_page_ceiling( (string) $option ) );
};


add_filter( 'set_screen_option_perflocale_strings_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_languages_per_page', $perflocale_per_page_save, 10, 3 );
add_filter( 'set_screen_option_perflocale_translations_per_page', $perflocale_per_page_save, 10, 3 );

// ---- Global helper functions ----

if ( ! function_exists( 'perflocale' ) ) {
	/**
	 * Get the PerfLocale helper instance for the fluent API, e.g. perflocale()->slug().
	 *
	 * @api Returns the {@see \PerfLocale\Helper} singleton.
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
