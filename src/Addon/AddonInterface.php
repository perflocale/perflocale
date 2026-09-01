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
 * Each addon must implement this interface to be discovered
 * and managed by the AddonRegistry.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
interface AddonInterface {

	/**
	 * Get the addon's unique identifier.
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
	 * Get the addon version.
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
	 * Boot the addon — register hooks, filters, etc.
	 *
	 * The `$plugin` argument is the full PerfLocale DI container. Addons
	 * can use it to reach the plugin's internal toolkit:
	 *
	 *   • Repositories      $plugin->lang_repo(), $plugin->group_repo()
	 *   • Cache             $plugin->cache()
	 *   • Settings          $plugin->settings()
	 *   • Router            $plugin->router()
	 *   • URL conversion    $plugin->url_converter(), $plugin->slug_manager()
	 *   • Addon registry    $plugin->addon_registry()
	 *   • Any registered    $plugin->get( Plugin::SERVICE_* )
	 *     service by ID
	 *
	 * Beyond the container, these classes form the addon-facing @api
	 * surface — call them directly, no DI lookup needed:
	 *
	 *   • \PerfLocale\Concurrency\Lock       Atomic critical-section guard
	 *   • \PerfLocale\Concurrency\Breaker    Circuit breaker for external calls
	 *   • \PerfLocale\Background\Dispatcher  Background-job dispatch
	 *   • \PerfLocale\Background\AbstractJob Base for custom jobs
	 *   • \PerfLocale\Background\JobState    Persistent job state
	 *   • \PerfLocale\Helper                 Fluent helper API +
	 *                                       load_addon_textdomain() (modern i18n)
	 *   • \PerfLocale\Addon\AddonSettings    Read/write the addon-settings option
	 *
	 * Opt-in capability interfaces (implement alongside AddonInterface):
	 *
	 *   • \PerfLocale\Addon\HasSchema             dbDelta() on activation
	 *   • \PerfLocale\Addon\HasUninstallTargets   declarative uninstall sweep
	 *   • \PerfLocale\Addon\HasCustomUninstall    custom uninstall logic
	 *   • \PerfLocale\Addon\HasVersionRequirement skip-with-notice on old hosts
	 *   • \PerfLocale\Addon\HasCardInfo           override Addons-page card metadata
	 *
	 * Full reference + examples: /docs/addon-system/developer-toolkit/
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
