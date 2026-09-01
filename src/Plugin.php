<?php
/**
 * Main plugin service container.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton service container for the PerfLocale plugin.
 *
 * Services are registered as factories (callables) and only instantiated
 * on first access, ensuring minimal memory footprint.
 */
final class Plugin {

	/**
	 * Stable service-ID constants for the most-used DI services. Addons
	 * should prefer these over magic strings — the constants are part of
	 * the @api surface and won't be renamed across minor versions, but the
	 * string values may change in major versions if the service is split.
	 *
	 * @api
	 */
	public const SERVICE_SETTINGS   = 'settings';
	public const SERVICE_CACHE      = 'cache';
	public const SERVICE_ROUTER     = 'router';
	public const SERVICE_LANG_REPO  = 'lang_repo';
	public const SERVICE_GROUP_REPO = 'group_repo';
	public const SERVICE_SLUG_MGR   = 'slug_manager';
	public const SERVICE_URL_CONV   = 'url_converter';
	public const SERVICE_ADDONS     = 'addon_registry';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved service instances.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Factory callables keyed by service ID.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Services that must be booted eagerly (register hooks on init).
	 *
	 * @var array<int, string>
	 */
	private array $eager = [];

	/**
	 * Private constructor - use get_instance().
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register a service factory.
	 *
	 * The factory is not called until the service is first requested via get().
	 *
	 * @param string   $id Service identifier (typically the class name).
	 * @param callable $factory Factory callable that returns the service instance.
	 * @param bool     $eager Whether to boot this service immediately on boot().
	 * @return void
	 */
	public function register( string $id, callable $factory, bool $eager = false ): void {
		$this->factories[ $id ] = $factory;

		if ( $eager ) {
			$this->eager[] = $id;
		}
	}

	/**
	 * Get a service instance, creating it on first access.
	 *
	 * @param string $id Service identifier.
	 * @return object
	 *
	 * @throws \RuntimeException If the service is not registered.
	 */
	public function get( string $id ): object {
		if ( isset( $this->services[ $id ] ) ) {
			return $this->services[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException(
				sprintf( 'Service "%s" is not registered in the PerfLocale container.', esc_html( $id ) )
			);
		}

		$this->services[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->services[ $id ];
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	// ─────────────────────────────────────────────────────────────────
	// Typed accessors — IDE-autocomplete + type-safe lookups for the
	// most-used services. Addons can call these instead of `get( '…' )`
	// to get the right return type without casting. Marked @api: stable
	// across the 1.x line.
	// ─────────────────────────────────────────────────────────────────

	/**
	 * @api
	 * @return \PerfLocale\Settings
	 */
	public function settings(): Settings {
		return $this->get( self::SERVICE_SETTINGS );
	}

	/**
	 * @api
	 * @return \PerfLocale\Cache\CacheManager
	 */
	public function cache(): \PerfLocale\Cache\CacheManager {
		return $this->get( self::SERVICE_CACHE );
	}

	/**
	 * @api
	 * @return \PerfLocale\Router\LanguageRouter
	 */
	public function router(): \PerfLocale\Router\LanguageRouter {
		return $this->get( self::SERVICE_ROUTER );
	}

	/**
	 * @api
	 * @return \PerfLocale\Database\Repository\LanguageRepository
	 */
	public function lang_repo(): \PerfLocale\Database\Repository\LanguageRepository {
		return $this->get( self::SERVICE_LANG_REPO );
	}

	/**
	 * @api
	 * @return \PerfLocale\Database\Repository\TranslationGroupRepository
	 */
	public function group_repo(): \PerfLocale\Database\Repository\TranslationGroupRepository {
		return $this->get( self::SERVICE_GROUP_REPO );
	}

	/**
	 * @api
	 * @return \PerfLocale\Router\SlugManager
	 */
	public function slug_manager(): \PerfLocale\Router\SlugManager {
		return $this->get( self::SERVICE_SLUG_MGR );
	}

	/**
	 * @api
	 * @return \PerfLocale\Router\UrlConverter
	 */
	public function url_converter(): \PerfLocale\Router\UrlConverter {
		return $this->get( self::SERVICE_URL_CONV );
	}

	/**
	 * @api
	 * @return \PerfLocale\Addon\AddonRegistry
	 */
	public function addon_registry(): \PerfLocale\Addon\AddonRegistry {
		return $this->get( self::SERVICE_ADDONS );
	}

	/**
	 * Boot all eager services and call register_hooks() on them.
	 *
	 * Called once during plugin initialization.
	 *
	 * @return void
	 */
	public function boot(): void {
		foreach ( $this->eager as $id ) {
			$service = $this->get( $id );

			if ( method_exists( $service, 'register_hooks' ) ) {
				$service->register_hooks();
			}
		}
	}

	/**
	 * Reset the container - used only in tests.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->services  = [];
		$this->factories = [];
		$this->eager     = [];
		self::$instance  = null;
	}

	/**
	 * Whether the plugin is currently being uninstalled.
	 *
	 * Hooked handlers (recurring AS / WP-Cron callbacks) can call this
	 * to bail out cleanly when an action fires after `uninstall.php` has
	 * begun dropping tables/options out from under them. WordPress sets
	 * `WP_UNINSTALL_PLUGIN` for the duration of the uninstall request,
	 * so any code that runs in that request can detect the state.
	 *
	 * Also returns true if the plugin's main file is gone from disk -
	 * a defensive belt-and-braces check for the (rare) case where the
	 * plugin directory was deleted outside the normal uninstall flow
	 * (e.g. force-delete via FTP, broken host file manager).
	 *
	 * @return bool
	 */
	public static function is_uninstalling(): bool {
		if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return true;
		}

		if ( defined( 'PERFLOCALE_FILE' ) && ! file_exists( PERFLOCALE_FILE ) ) {
			return true;
		}

		return false;
	}
}
