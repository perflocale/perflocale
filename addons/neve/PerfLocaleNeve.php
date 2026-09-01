<?php
/**
 * PerfLocale Neve theme addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Neve theme integration for PerfLocale.
 *
 * Registers a Language Switcher component in Neve's Header Footer Grid (HFG)
 * builder so users can drag it into any header row via the Customizer.
 */
final class PerfLocaleNeve implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'neve';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Neve';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return 'neve' === get_template();
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register our HFG component via the support components filter.
		add_filter( 'hfg_support_components_filter', [ $this, 'register_hfg_component' ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Register the Language Switcher as an HFG header component.
	 *
	 * @param array<string, array<string, array<int, string>>> $theme_support HFG theme support config.
	 * @return array<string, array<string, array<int, string>>>
	 */
	public function register_hfg_component( array $theme_support ): array {
		require_once __DIR__ . '/NeveSwitcherComponent.php';

		if ( isset( $theme_support['builders']['HFG\Core\Builder\Header'] ) ) {
			$theme_support['builders']['HFG\Core\Builder\Header'][] = 'PerfLocaleNeveSwitcherComponent';
		}

		return $theme_support;
	}
}
