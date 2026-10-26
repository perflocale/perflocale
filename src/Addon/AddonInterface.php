<?php
/**
 * Addon interface.
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
 * Contract for PerfLocale addons.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
interface AddonInterface {

	/**
	 * Get the addon's unique identifier.
	 *
	 * Must match AddonSchemaManager::ADDON_ID_PATTERN, or register() rejects the addon.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Get the addon's display name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Get the addon version: a dot-version string such as 1.0.0 or 1.0.0-beta.
	 *
	 * @return string
	 */
	public function get_version(): string;

	/**
	 * Get required plugins (plugin file paths).
	 *
	 * @return array<int, string> e.g. ['woocommerce/woocommerce.php']
	 */
	public function get_required_plugins(): array;

	/**
	 * Check if the addon is compatible with the current environment.
	 *
	 * @return bool
	 */
	public function is_compatible(): bool;

	/**
	 * Boot the addon: register its hooks and filters.
	 *
	 * `$plugin` is the full DI container; opt-in capabilities are the Has* interfaces in this namespace.
	 * Toolkit reference: /docs/addon-system/developer-toolkit/
	 *
	 * @param Plugin $plugin The PerfLocale plugin container.
	 * @return void
	 */
	public function boot( Plugin $plugin ): void;

	/**
	 * Get addon-specific settings fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_settings_fields(): array;
}
