<?php
/**
 * Plugin Name: PerfLocale
 * Plugin URI: https://perflocale.com
 * Description: Performance-first multilingual plugin for WordPress. Translate posts, pages, products, taxonomies, strings, and slugs, and keep your site fast.
 * Version: 1.0.8.0.1
 * Requires at least: 6.4
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

define( 'PERFLOCALE_VERSION', '1.0.8.0.1' );
define( 'PERFLOCALE_FILE', __FILE__ );
define( 'PERFLOCALE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERFLOCALE_URL', plugin_dir_url( __FILE__ ) );
// Schema::create_tables() ships the whole schema. dbDelta cannot reshape an index under an
// existing name: the upgrade runner replaces it (UpgradeRunner::replace_reshaped_keys()).
define( 'PERFLOCALE_DB_VERSION', 2 );

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

// An upgrader replacing our folder detaches the code in memory from the other version's files (class loads only then).
add_filter( 'upgrader_source_selection', [ PerfLocale\Util\OwnFilesReplaced::class, 'on_source_selection' ], PHP_INT_MAX, 4 );

require_once PERFLOCALE_DIR . 'template-tags.php';

// ---- Activation / Deactivation ----

register_activation_hook( __FILE__, [ PerfLocale\Activator::class, 'on_activation' ] );

register_deactivation_hook( __FILE__, [ PerfLocale\Deactivator::class, 'deactivate' ] );

// Multisite: auto-create tables when a new site is added to the network.
add_action(
	'wp_initialize_site',
	[ PerfLocale\Activator::class, 'initialize_site' ],
	200
);

// Multisite: purge a deleted site's data regardless of `delete_data_on_uninstall` (that
// setting is for reversible plugin uninstall; blog deletion is irreversible). Priority 5: before core's priority-10
// table drop, because full_purge() reads add-on manifests from this blog's options.
add_action( 'wp_uninitialize_site', [ PerfLocale\Database\SiteCleanup::class, 'on_site_deleted' ], 5 );

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
