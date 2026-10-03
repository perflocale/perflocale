<?php
/**
 * WPML add-ons left active after a switch to PerfLocale.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds WooCommerce Multilingual and WPML String Translation while they are
 * active.
 *
 * Neither is a conflict PerfLocale refuses to run beside (WPML itself is):
 * String Translation does nothing without WPML, and WooCommerce Multilingual
 * keeps its own currency switcher and currency settings running without
 * WPML, next to PerfLocale's. Both are left active easily after a
 * migration, so {@see SiteHealth} reports them and shows a notice with a
 * button that deactivates WooCommerce Multilingual through core's own
 * Plugins action.
 */
final class LeftoverPluginCheck {

	/**
	 * Display name => version constant and plugin files.
	 *
	 * @var array<string, array{constant: string, files: list<string>}>
	 */
	public const PLUGINS = [
		'WooCommerce Multilingual' => [
			'constant' => 'WCML_VERSION',
			'files'    => [ 'woocommerce-multilingual/wpml-woocommerce.php' ],
		],
		'WPML String Translation'  => [
			'constant' => 'WPML_ST_VERSION',
			'files'    => [ 'wpml-string-translation/plugin.php' ],
		],
	];

	/**
	 * Active leftover plugins: display name => plugin file (or '' when only
	 * the version constant shows it).
	 *
	 * @return array<string, string>
	 */
	public static function active(): array {
		$active = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		$out = [];

		foreach ( self::PLUGINS as $name => $meta ) {
			foreach ( $meta['files'] as $file ) {
				if ( in_array( $file, $active, true ) ) {
					$out[ $name ] = $file;
					continue 2;
				}
			}

			if ( defined( $meta['constant'] ) ) {
				$out[ $name ] = '';
			}
		}

		return $out;
	}

	/**
	 * Why one leftover plugin should go.
	 *
	 * @param string $name Display name from {@see PLUGINS}.
	 * @return string
	 */
	public static function describe( string $name ): string {
		$text = sprintf(
			/* translators: %s: plugin name, e.g. "WooCommerce Multilingual" */
			__( '%s is still active. It depends on WPML and is not needed with PerfLocale; deactivate it so it does not run next to PerfLocale.', 'perflocale' ),
			$name
		);

		if ( $name === 'WooCommerce Multilingual' ) {
			$text .= ' ' . __( 'WooCommerce Multilingual can keep its own currency switcher and currency settings running without WPML.', 'perflocale' );
		}

		return $text;
	}

	/**
	 * Core's deactivate link for WooCommerce Multilingual, or '' for any
	 * other plugin or when the current user may not deactivate it here.
	 *
	 * A plugin active network-wide is deactivated from the network admin,
	 * so no link is offered for it on a site's screens.
	 *
	 * @param string $name Display name from {@see PLUGINS}.
	 * @param string $file Plugin file.
	 * @return string
	 */
	public static function deactivate_url( string $name, string $file ): string {
		if ( $name !== 'WooCommerce Multilingual' || $file === '' || ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'deactivate_plugin', $file ) ) {
			return '';
		}

		if ( ! in_array( $file, (array) get_option( 'active_plugins', [] ), true ) ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				[
					'action' => 'deactivate',
					'plugin' => rawurlencode( $file ),
				],
				admin_url( 'plugins.php' )
			),
			'deactivate-plugin_' . $file
		);
	}
}
