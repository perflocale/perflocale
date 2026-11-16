<?php
/**
 * Early boot of the theme builder add-ons.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the Blocksy, Kadence or Neve add-on at plugin load: the registry boots at init:0, too late for builder APIs
 * that read their component lists during after_setup_theme. Bootstrap requires it only when one of them is the theme.
 *
 * @internal
 */
final class ThemeAddonBoot {

	/**
	 * Theme slug (the add-on's get_id()) => add-on main file.
	 */
	private const FILES = [
		'blocksy' => 'addons/blocksy/PerfLocaleBlocksy.php',
		'kadence' => 'addons/kadence/PerfLocaleKadence.php',
		'neve'    => 'addons/neve/PerfLocaleNeve.php',
	];

	/**
	 * Boot the add-on of the active theme.
	 *
	 * @param Plugin $plugin   Service container.
	 * @param string $template Active theme (get_template()).
	 * @return void
	 */
	public static function boot( Plugin $plugin, string $template ): void {
		if ( ! isset( self::FILES[ $template ] ) || ! file_exists( __DIR__ . '/../../' . self::FILES[ $template ] ) ) {
			return;
		}

		$addon_id = $template;

		// This path boots ahead of AddonRegistry and marks the add-on booted, so apply the
		// registry's quarantine gate and perflocale/addon/enabled filter here, in its order.
		// Both options are autoloaded.
		$failures  = (array) get_option( 'perflocale_addon_failures', [] );
		$threshold = (int) apply_filters( 'perflocale/addons/quarantine_threshold', 3 );

		if ( $threshold > 0 && (int) ( $failures[ $addon_id ] ?? 0 ) >= $threshold ) {
			return;
		}

		$default_on = ! in_array( $addon_id, AddonRegistry::get_disabled(), true );

		/** This filter is documented in src/Addon/AddonRegistry.php */
		if ( ! (bool) apply_filters( 'perflocale/addon/enabled', $default_on, $addon_id ) ) {
			// Hand the decision to the registry so it neither boots the add-on nor asks again.
			add_action(
				'plugins_loaded',
				static function () use ( $plugin, $addon_id, $default_on ): void {
					if ( $plugin->has( 'addon_registry' ) ) {
						$registry = $plugin->addon_registry();
						$registry->defer( $addon_id );
						$registry->mark_filter_off( $addon_id, $default_on );
					}
				},
				15
			);
			return;
		}

		// Literal paths, so the release zip check can verify each target.
		match ( $template ) {
			'blocksy' => require_once __DIR__ . '/../../addons/blocksy/PerfLocaleBlocksy.php',
			'kadence' => require_once __DIR__ . '/../../addons/kadence/PerfLocaleKadence.php',
			'neve'    => require_once __DIR__ . '/../../addons/neve/PerfLocaleNeve.php',
		};
		$class_name = 'PerfLocale' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $template ) ) );

		if ( ! class_exists( $class_name ) ) {
			return;
		}

		$theme_addon = new $class_name();

		if ( ! $theme_addon instanceof AddonInterface ) {
			return;
		}

		// Third-party code: catch so a throw feeds the quarantine counter instead of a
		// sitewide fatal. Mirrors AddonRegistry::boot_addons().
		try {
			if ( ! $theme_addon->is_compatible() ) {
				return;
			}

			$theme_addon->boot( $plugin );
		} catch ( \Throwable $e ) {
			$failures[ $addon_id ] = (int) ( $failures[ $addon_id ] ?? 0 ) + 1;
			update_option( 'perflocale_addon_failures', $failures, true );
			AddonMigrationErrors::record(
				$addon_id,
				'boot',
				(int) $failures[ $addon_id ],
				\PerfLocale\Database\UpgradeRunner::describe( $e )
			);
			return;
		}

		// Booted cleanly: clear the boot failure counter and its admin record (boot stage only,
		// like AddonRegistry::boot_addons()).
		if ( isset( $failures[ $addon_id ] ) ) {
			unset( $failures[ $addon_id ] );
			update_option( 'perflocale_addon_failures', $failures, true );
			AddonMigrationErrors::clear_stage( $addon_id, 'boot' );
		}

		// Mark as already booted so AddonRegistry doesn't double-boot it.
		add_action(
			'plugins_loaded',
			static function () use ( $plugin, $theme_addon, $addon_id ): void {
				if ( $plugin->has( 'addon_registry' ) ) {
					$registry = $plugin->get( 'addon_registry' );
					$registry->register( $theme_addon );
					$registry->mark_booted( $addon_id );
				}
			},
			15
		);
	}
}
