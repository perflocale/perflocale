<?php
/**
 * Addon registry.
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
 * Discovers, validates, and boots PerfLocale addons.
 */
final class AddonRegistry {

	/**
	 * Registered addons.
	 *
	 * @var array<string, AddonInterface>
	 */
	private array $addons = [];

	/**
	 * Bundled addons (from the addons/ directory) - protected from override.
	 *
	 * @var array<string, AddonInterface>
	 */
	private array $bundled = [];

	/**
	 * Booted addons.
	 *
	 * @var array<string, bool>
	 */
	private array $booted = [];

	/**
	 * API objects public_api() handed out in this request, by addon id (null: none, or creating it failed).
	 *
	 * @var array<string, object|null>
	 */
	private array $public_apis = [];

	/**
	 * True once the final boot pass (boot_pending at plugins_loaded:99, or the eager immediate pass) has run.
	 *
	 * Until then guard #2 in register() must accept late registrations, even though $booted is already populated.
	 *
	 * @var bool
	 */
	private bool $final_boot_done = false;

	/**
	 * Addons skipped this request because they need a newer PerfLocale: addon ID => required version.
	 *
	 * @var array<string, string>
	 */
	private array $version_mismatches = [];

	/**
	 * Addons whose is_compatible() (or a site filter) said no, keyed by addon ID.
	 *
	 * Populated in every context: Site Health reads it from the weekly cron check, where is_admin() is false.
	 *
	 * @var array<string, bool>
	 */
	private array $incompatible = [];

	/**
	 * Addons the `perflocale/addon/enabled` filter kept from booting this request, keyed by addon ID.
	 *
	 * True when it turned off an addon that would boot, false when it only kept a disabled one off. Final for the
	 * request, and not a fault, so kept apart from $incompatible and the quarantine counter.
	 *
	 * @var array<string, bool>
	 */
	private array $filter_off = [];

	/**
	 * Bundled addon manifest, keyed by directory name.
	 *
	 * `compat` mirrors the addon's is_compatible() without loading its file, so a site without the host plugin never
	 * requires it; each closure must stay in sync with that method, which remains the source of truth.
	 *
	 * @return array<string, array{file: string, class: string, compat: callable}>
	 */
	private static function bundled_manifest(): array {
		$dir = defined( 'PERFLOCALE_DIR' ) ? PERFLOCALE_DIR : '';

		return [
			'acf'             => [
				'file'   => $dir . 'addons/acf/PerfLocaleAcf.php',
				'class'  => 'PerfLocaleAcf',
				'compat' => static fn(): bool => class_exists( 'ACF' ) || function_exists( 'acf_get_field_groups' ),
			],
			'aioseo'          => [
				'file'   => $dir . 'addons/aioseo/PerfLocaleAioseo.php',
				'class'  => 'PerfLocaleAioseo',
				// Presence only; the addon's is_compatible() checks the minimum version after the file loads.
				'compat' => static fn(): bool => class_exists( 'AIOSEO\\Plugin\\AIOSEO' ),
			],
			'beaver-builder'  => [
				'file'   => $dir . 'addons/beaver-builder/PerfLocaleBeaverBuilder.php',
				'class'  => 'PerfLocaleBeaverBuilder',
				'compat' => static fn(): bool => class_exists( 'FLBuilder' ),
			],
			'blocksy'         => [
				'file'   => $dir . 'addons/blocksy/PerfLocaleBlocksy.php',
				'class'  => 'PerfLocaleBlocksy',
				'compat' => static fn(): bool => function_exists( 'get_template' ) && 'blocksy' === get_template(),
			],
			'bricks'          => [
				'file'   => $dir . 'addons/bricks/PerfLocaleBricks.php',
				'class'  => 'PerfLocaleBricks',
				'compat' => static fn(): bool => defined( 'BRICKS_VERSION' ) || class_exists( 'Bricks\\Theme' ),
			],
			'contact-form-7'  => [
				'file'   => $dir . 'addons/contact-form-7/PerfLocaleContactForm7.php',
				'class'  => 'PerfLocaleContactForm7',
				'compat' => static fn(): bool => defined( 'WPCF7_VERSION' ),
			],
			'elementor'       => [
				'file'   => $dir . 'addons/elementor/PerfLocaleElementor.php',
				'class'  => 'PerfLocaleElementor',
				'compat' => static fn(): bool => defined( 'ELEMENTOR_VERSION' ),
			],
			'gravity-forms'   => [
				'file'   => $dir . 'addons/gravity-forms/PerfLocaleGravityForms.php',
				'class'  => 'PerfLocaleGravityForms',
				'compat' => static fn(): bool => class_exists( 'GFAPI' ),
			],
			'kadence'         => [
				'file'   => $dir . 'addons/kadence/PerfLocaleKadence.php',
				'class'  => 'PerfLocaleKadence',
				'compat' => static fn(): bool => function_exists( 'get_template' ) && 'kadence' === get_template(),
			],
			'metabox'         => [
				'file'   => $dir . 'addons/metabox/PerfLocaleMetabox.php',
				'class'  => 'PerfLocaleMetabox',
				'compat' => static fn(): bool => defined( 'RWMB_VER' ) || class_exists( 'RWMB_Loader' ),
			],
			'neve'            => [
				'file'   => $dir . 'addons/neve/PerfLocaleNeve.php',
				'class'  => 'PerfLocaleNeve',
				'compat' => static fn(): bool => function_exists( 'get_template' ) && 'neve' === get_template(),
			],
			'oxygen'          => [
				'file'   => $dir . 'addons/oxygen/PerfLocaleOxygen.php',
				'class'  => 'PerfLocaleOxygen',
				'compat' => static fn(): bool => defined( 'CT_VERSION' ),
			],
			'oxygen6'         => [
				'file'   => $dir . 'addons/oxygen6/PerfLocaleOxygen6.php',
				'class'  => 'PerfLocaleOxygen6',
				'compat' => static fn(): bool => defined( '__BREAKDANCE_VERSION' ),
			],
			'pods'            => [
				'file'   => $dir . 'addons/pods/PerfLocalePods.php',
				'class'  => 'PerfLocalePods',
				'compat' => static fn(): bool => defined( 'PODS_VERSION' ) || function_exists( 'pods' ),
			],
			'rankmath'        => [
				'file'   => $dir . 'addons/rankmath/PerfLocaleRankmath.php',
				'class'  => 'PerfLocaleRankmath',
				'compat' => static fn(): bool => class_exists( 'RankMath' ),
			],
			'seopress'        => [
				'file'   => $dir . 'addons/seopress/PerfLocaleSeopress.php',
				'class'  => 'PerfLocaleSeopress',
				'compat' => static fn(): bool => function_exists( 'seopress_get_service' ) || class_exists( 'SEOPRESS_Functions' ),
			],
			'slimseo'         => [
				'file'   => $dir . 'addons/slimseo/PerfLocaleSlimSeo.php',
				'class'  => 'PerfLocaleSlimSeo',
				// Slim SEO 4.x has SlimSEO\Core, older releases SlimSEO\Plugin; matches PerfLocaleSlimSeo::is_compatible().
				'compat' => static fn(): bool => class_exists( 'SlimSEO\\Core' ) || class_exists( 'SlimSEO\\Plugin' ),
			],
			'theseoframework' => [
				'file'   => $dir . 'addons/theseoframework/PerfLocaleTheSeoFramework.php',
				'class'  => 'PerfLocaleTheSeoFramework',
				'compat' => static fn(): bool => function_exists( 'the_seo_framework' ) || function_exists( 'tsf' ),
			],
			'woocommerce'     => [
				'file'   => $dir . 'addons/woocommerce/PerfLocaleWooCommerce.php',
				'class'  => 'PerfLocaleWooCommerce',
				'compat' => static fn(): bool => class_exists( 'WooCommerce' ),
			],
			'wpforms'         => [
				'file'   => $dir . 'addons/wpforms/PerfLocaleWPForms.php',
				'class'  => 'PerfLocaleWPForms',
				'compat' => static fn(): bool => function_exists( 'wpforms' ),
			],
			'yoast'           => [
				'file'   => $dir . 'addons/yoast/PerfLocaleYoast.php',
				'class'  => 'PerfLocaleYoast',
				'compat' => static fn(): bool => defined( 'WPSEO_VERSION' ),
			],
		];
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// As an eager service at init:0, plugins_loaded has already fired: boot now.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->discover_and_boot();
			// The boot_pending(99) fallback will never fire, so this pass is final.
			$this->final_boot_done = true;
		} else {
			add_action( 'plugins_loaded', [ $this, 'discover_and_boot' ], 20 );

			// Late-registration fallback; $this->booted skips addons already booted.
			add_action( 'plugins_loaded', [ $this, 'boot_pending' ], 99 );
		}
	}

	/**
	 * Boot any addons that were registered after the initial boot pass.
	 *
	 * Picks up external plugins that register at plugins_loaded 21-98, after the pass at 20.
	 *
	 * @return void
	 */
	public function boot_pending(): void {
		$pending = 0;

		foreach ( $this->addons as $id => $addon ) {
			if ( ! isset( $this->booted[ $id ] ) ) {
				++$pending;
			}
		}

		if ( $pending > 0 ) {
			$this->boot_addons();
		}

		// Last scheduled pass: a later registration cannot be booted, so guard #2 rejects it.
		$this->final_boot_done = true;
	}

	/**
	 * Discover addons from the addons/ directory and boot compatible ones.
	 *
	 * Only addons whose manifest `compat` closure passes are required, so a site without host plugins parses none.
	 *
	 * @return void
	 */
	public function discover_and_boot(): void {
		if ( ! defined( 'PERFLOCALE_DIR' ) ) {
			return;
		}

		$manifest     = self::bundled_manifest();
		$bootable_ids = self::resolve_bootable_ids( $manifest );

		foreach ( $bootable_ids as $id ) {
			if ( ! isset( $manifest[ $id ] ) ) {
				// Defensive: an id without a manifest entry.
				continue;
			}

			if ( isset( $this->addons[ $id ] ) ) {
				// Theme addons register their own instance at plugins_loaded:15; a second one would trip guard #4.
				continue;
			}

			$main_file  = (string) $manifest[ $id ]['file'];
			$class_name = (string) $manifest[ $id ]['class'];

			if ( $main_file === '' || ! file_exists( $main_file ) ) {
				continue;
			}

			require_once $main_file;

			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$addon = new $class_name();

			if ( ! $addon instanceof AddonInterface ) {
				continue;
			}

			$this->register( $addon );
		}

		// Snapshot bundled addon IDs - these cannot be overridden by external plugins.
		$this->bundled = $this->addons;

		// External plugins call $registry->register( new MyAddon() ) here.
		/** @hook perflocale/addons/register Fires before addons boot. Pass your AddonInterface instance to $registry->register(). */
		do_action( 'perflocale/addons/register', $this );

		$this->boot_addons();
	}

	/**
	 * Resolve which addon IDs should boot this request; computed every time, never cached.
	 *
	 * @param array<string, array{file: string, class: string, compat: callable}> $manifest Bundled addon manifest.
	 * @return string[]
	 */
	private static function resolve_bootable_ids( array $manifest ): array {
		// Never cached: a list stored where a host plugin was not visible silently disabled its integration, and computing
		// costs about a cache read. See docs-ai/11-code-rationale.md, "AddonRegistry: bootable list".
		$bootable = [];

		foreach ( $manifest as $id => $entry ) {
			$compat = $entry['compat'] ?? null;

			try {
				if ( ! is_callable( $compat ) || ! (bool) $compat() ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'PerfLocale addon manifest compat check for "%s" threw: %s', $id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				continue;
			}

			$bootable[] = $id;
		}

		return $bootable;
	}

	/**
	 * Invalidate the bootable-addons cache: delete the unused transient and bump the multisite generation.
	 *
	 * Public for the WP-CLI cache flush and the `perflocale/addons/cache/flush` action.
	 *
	 * @return void
	 */
	public static function flush_bootable_cache(): void {
		delete_transient( self::BOOTABLE_TRANSIENT );

		if ( is_multisite() ) {
			// Nothing reads the generation: resolve_bootable_ids() computes the list on every request.
			update_site_option(
				self::BOOTABLE_GEN_OPTION,
				(int) get_site_option( self::BOOTABLE_GEN_OPTION, 0 ) + 1
			);
		}
	}

	/**
	 * Register addon-bootable-cache invalidation hooks, in every context (Bootstrap::init).
	 *
	 * @return void
	 */
	public static function register_cache_invalidation(): void {
		// Anything that can change whether a host plugin's class or function exists.
		add_action( 'activated_plugin', [ self::class, 'flush_bootable_cache' ] );
		add_action( 'deactivated_plugin', [ self::class, 'flush_bootable_cache' ] );
		add_action( 'switch_theme', [ self::class, 'flush_bootable_cache' ] );

		add_action( 'upgrader_process_complete', [ self::class, 'flush_bootable_cache' ] );

		// Also covers network-wide (de)activation on multisite.
		add_action( 'activate_plugin', [ self::class, 'flush_bootable_cache' ] );
		add_action( 'deactivate_plugin', [ self::class, 'flush_bootable_cache' ] );

		/** @hook perflocale/addons/cache/flush Manually triggered invalidation. */
		add_action( 'perflocale/addons/cache/flush', [ self::class, 'flush_bootable_cache' ] );
	}

	/**
	 * Register an addon.
	 *
	 * @param AddonInterface $addon Addon instance.
	 * @return void
	 */
	public function register( AddonInterface $addon ): void {
		$id = $addon->get_id();

		// (1) ADDON_ID_PATTERN gates settings, the disabled list, schemas and uninstall: fail fast here.
		if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the offending addon ID returned from AddonInterface::get_id(). */
						__( 'AddonInterface::get_id() must return a 2-16 character slug matching [a-z0-9_-]. Got: "%s". Addon was not registered.', 'perflocale' ),
						$id
					)
				),
				'1.0.0'
			);
			return;
		}

		// (2) Gate on $final_boot_done, not a non-empty $booted: boot_pending() exists to boot addons registered after
		// the first pass.
		if ( $this->final_boot_done ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the addon ID. */
						__( 'Addon "%s" was registered AFTER the perflocale/addons/register action fired - its boot() will never be called. Hook your registration on perflocale/addons/register (or earlier).', 'perflocale' ),
						$id
					)
				),
				'1.0.0'
			);
			return;
		}

		// (3) Bundled addons cannot be overridden by external plugins.
		if ( isset( $this->bundled[ $id ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the addon ID. */
						__( 'Addon "%s" cannot override a bundled addon with the same ID. Choose a different ID.', 'perflocale' ),
						$id
					)
				),
				'1.0.0'
			);
			return;
		}

		// (4) A second registration is ignored: overwriting would drop the first instance's state.
		if ( isset( $this->addons[ $id ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the addon ID. */
						__( 'Addon "%s" is already registered. Registering twice would overwrite the prior instance - second-call ignored.', 'perflocale' ),
						$id
					)
				),
				'1.0.0'
			);
			return;
		}

		// (5-7) Dev-only: _doing_it_wrong() reports nothing without WP_DEBUG. Guards 1-4 return early and run everywhere.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// (5) Admin rows and Site Health cards need a name.
			if ( trim( $addon->get_name() ) === '' ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: %s is the addon ID. */
							__( 'Addon "%s" returned an empty AddonInterface::get_name() - admin UI rows + Site Health cards rely on it. Provide a human-readable name.', 'perflocale' ),
							$id
						)
					),
					'1.0.0'
				);
			}

			// (6) version_compare() needs a dot-version, optionally with a pre-release suffix.
			$version = $addon->get_version();
			if ( ! preg_match( '/^\d+\.\d+(\.\d+)?([+\-][0-9A-Za-z.\-]+)?$/', $version ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: addon ID, 2: offending version string. */
							__( 'Addon "%1$s" returned get_version()="%2$s" - must be a dot-version string (e.g. 1.0.0 or 1.0.0-beta). Compatibility gates via version_compare() will silently treat it as invalid.', 'perflocale' ),
							$id,
							$version
						)
					),
					'1.0.0'
				);
			}

			// (7) Non-string entries break the compat gate.
			$required = $addon->get_required_plugins();
			if ( ! is_array( $required ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: addon ID, 2: offending return type. */
							__( 'Addon "%1$s" returned get_required_plugins() = %2$s - must be an array of plugin-file strings (or empty array).', 'perflocale' ),
							$id,
							get_debug_type( $required )
						)
					),
					'1.0.0'
				);
			}
		}

		$this->addons[ $id ] = $addon;
	}

	/**
	 * Mark an addon as already booted (for early-boot addons).
	 *
	 * @param string $id Addon ID.
	 * @return void
	 */
	public function mark_booted( string $id ): void {
		$this->booted[ $id ] = true;
	}

	/**
	 * Record a `perflocale/addon/enabled` false result reached before the
	 * registry booted (theme addons decide while the plugin file loads). The
	 * boot loop then skips the addon without asking the filter again.
	 *
	 * @param string $id         Addon ID.
	 * @param bool   $default_on Whether the addon would have booted without the filter.
	 * @return void
	 */
	public function mark_filter_off( string $id, bool $default_on = true ): void {
		$this->filter_off[ $id ] = $default_on;
	}

	/**
	 * Boot all compatible addons.
	 *
	 * @return void
	 */
	private function boot_addons(): void {
		$plugin = Plugin::get_instance();

		/**
		 * Filter registered addons before booting.
		 *
		 * @param array<string, AddonInterface> $addons Registered addons.
		 */
		$pre_filter = $this->addons;
		$filtered   = apply_filters( 'perflocale/addons/registered', $this->addons );

		if ( ! is_array( $filtered ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/addons/registered", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/addons/registered returned %s - must be an array<string, AddonInterface>. Falling back to the unfiltered registration map.', 'perflocale' ),
						get_debug_type( $filtered )
					)
				),
				'1.0.0'
			);
			$filtered = $pre_filter;
		}

		$this->addons = $filtered;

		// Restore bundled addons if a filter removed or replaced them.
		foreach ( $this->bundled as $id => $addon ) {
			$this->addons[ $id ] = $addon;
		}

		// Addons that keep throwing are quarantined, so one broken addon cannot degrade every request.
		$failures        = (array) get_option( self::FAILURE_OPTION, [] );
		$failure_changed = false;

		$disabled = self::get_disabled();

		foreach ( $this->addons as $id => $addon ) {
			if ( isset( $this->booted[ $id ] ) || isset( $this->filter_off[ $id ] ) ) {
				continue;
			}

			// A threshold <= 0 disables quarantine.
			$threshold = self::failure_threshold();
			if ( $threshold > 0 && ! empty( $failures[ $id ] ) && (int) $failures[ $id ] >= $threshold ) {
				// Quarantined until reset_quarantine().
				continue;
			}

			// The disabled list applies to bundled and external addons alike.
			$default_on = ! in_array( $id, $disabled, true );

			/**
			 * Filter whether an addon boots in this request.
			 *
			 * Evaluated once per addon per request at boot (init:0); for the Blocksy, Kadence and Neve theme addons only a
			 * callback from a must-use plugin or a plugin loaded before PerfLocale counts. The language is not known yet.
			 * False skips the addon for this request only (disabled by filter; the disabled list and failure counter are
			 * untouched); true boots an addon the disabled list turns off. Quarantined addons never reach this filter.
			 *
			 * @hook perflocale/addon/enabled
			 * @param bool   $enabled  Default: false when the addon is in the disabled list, else true.
			 * @param string $addon_id Addon ID.
			 */
			$enabled = (bool) apply_filters( 'perflocale/addon/enabled', $default_on, $id );

			if ( ! $enabled ) {
				$this->filter_off[ $id ] = $default_on;
				continue;
			}

			// Skipped, not failed, when PerfLocale is older than the addon's floor. Recorded in every context: Site Health
			// (weekly cron) and WP-CLI read it, and neither is an admin request.
			if ( $addon instanceof HasVersionRequirement && defined( 'PERFLOCALE_VERSION' ) ) {
				$required = (string) $addon->get_min_perflocale_version();

				if ( $required !== '' && version_compare( (string) PERFLOCALE_VERSION, $required, '<' ) ) {
					$this->version_mismatches[ $id ] = $required;

					continue;
				}
			}

			// is_compatible() is addon-author code like boot(): a throw feeds the quarantine counter, not a fatal error.
			try {
				/**
				 * Filter addon compatibility.
				 *
				 * @param bool $compatible Whether the addon is compatible.
				 * @param string $addon_id Addon ID.
				 */
				$compatible = apply_filters( 'perflocale/addon/is_compatible', $addon->is_compatible(), $id );

				if ( ! $compatible ) {
					// Recorded in every context, like version_mismatches, so Site Health and WP-CLI can report it.
					$this->incompatible[ $id ] = true;

					continue;
				}

				$addon->boot( $plugin );
				$this->booted[ $id ] = true;

				// Clear the counter and the `boot` record: boot failures are often transient (a file mid-update), and a stale
				// notice would stay. A failed migration or uninstall is a separate fact and stays.
				if ( isset( $failures[ $id ] ) ) {
					unset( $failures[ $id ] );
					$failure_changed = true;

					AddonMigrationErrors::clear_stage( $id, 'boot' );
				}

				// After boot(), so a failed boot leaves no half-seeded entry.
				$this->maybe_seed_defaults( $addon, $id );

				/** @hook perflocale/addon/activated Fires after an addon is booted. */
				do_action( 'perflocale/addon/activated', $id );
			} catch ( \Throwable $e ) {
				// A boot failure must not crash the site; the persistent counter quarantines repeat offenders.
				$failures[ $id ] = (int) ( $failures[ $id ] ?? 0 ) + 1;
				$failure_changed = true;

				// Persisted so the Addons page can show the cause on the card.
				AddonMigrationErrors::record(
					$id,
					'boot',
					(int) $failures[ $id ],
					\PerfLocale\Util\PathRedactor::redact( $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() )
				);

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'PerfLocale addon %s failed to boot (%d/%d): %s in %s:%d', $id, $failures[ $id ], self::failure_threshold(), $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}

		if ( $failure_changed ) {
			update_option( self::FAILURE_OPTION, $failures, true );
		}

		/** @hook perflocale/addons/loaded Fires after all addons are booted. */
		do_action( 'perflocale/addons/loaded' );
	}

	/**
	 * Option key storing per-addon failure counters.
	 */
	private const FAILURE_OPTION = 'perflocale_addon_failures';

	// The bundled add-on whose settings fields are all stored globally.
	private const GLOBAL_SETTINGS_ONLY = 'woocommerce';

	/**
	 * Transient of the cached bootable list; nothing writes or reads it.
	 *
	 * Still deleted by flush_bootable_cache(), so a payload an earlier version stored does not linger.
	 */
	private const BOOTABLE_TRANSIENT = 'perflocale_bootable_addons';

	/**
	 * Site option with the network-wide generation of the bootable cache; bumped by flush_bootable_cache(), never read.
	 */
	private const BOOTABLE_GEN_OPTION = 'perflocale_bootable_gen';

	/**
	 * Default consecutive boot failures before an addon is quarantined.
	 *
	 * Filter: `perflocale/addons/quarantine_threshold`; 0 or less disables quarantine, 1 quarantines on the first failure.
	 */
	private const FAILURE_THRESHOLD = 3;

	/**
	 * Resolved (filter-applied) failure threshold, memoised per request.
	 *
	 * @return int
	 */
	private static function failure_threshold(): int {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		/**
		 * Filter the number of consecutive boot failures before an addon
		 * is auto-quarantined.
		 *
		 * @hook perflocale/addons/quarantine_threshold
		 * @param int $threshold Default: 3. Min: 1 (any value <= 0 disables quarantine).
		 */
		$threshold = (int) apply_filters( 'perflocale/addons/quarantine_threshold', self::FAILURE_THRESHOLD );
		$cached    = $threshold;
		return $cached;
	}

	/**
	 * List the IDs of addons that have been auto-quarantined.
	 *
	 * @return array<int, string>
	 */
	public function get_quarantined_ids(): array {
		$failures  = (array) get_option( self::FAILURE_OPTION, [] );
		$threshold = self::failure_threshold();
		$out       = [];

		if ( $threshold <= 0 ) {
			// Quarantine disabled by filter; nothing is ever quarantined.
			return $out;
		}

		foreach ( $failures as $id => $count ) {
			if ( (int) $count >= $threshold ) {
				$out[] = (string) $id;
			}
		}

		return $out;
	}

	/**
	 * Clear a quarantined addon's failure counter so it can attempt to boot again.
	 *
	 * @param string $id Addon ID.
	 * @return void
	 */
	public function reset_quarantine( string $id ): void {
		$failures = (array) get_option( self::FAILURE_OPTION, [] );
		unset( $failures[ $id ] );
		update_option( self::FAILURE_OPTION, $failures, true );

		// Drop the boot records too: the notice is what the operator sees. A failed migration is not retried by this.
		AddonMigrationErrors::clear_stage( $id, 'boot' );
	}

	/**
	 * Get all registered addons.
	 *
	 * @return array<string, AddonInterface>
	 */
	public function get_addons(): array {
		return $this->addons;
	}

	/**
	 * Check if an addon is booted.
	 *
	 * @param string $id Addon ID.
	 * @return bool
	 */
	public function is_booted( string $id ): bool {
		return isset( $this->booted[ $id ] );
	}

	/**
	 * A booted addon's API object for perflocale()->addon(), kept for the request (a failure too).
	 *
	 * @param string $id Addon ID.
	 * @return object|null
	 */
	public function public_api( string $id ): ?object {
		return AddonPublicApi::get( $id, $this->public_apis, $this->booted, $this->addons );
	}

	/**
	 * Whether the addon came from the bundled manifest rather than the `perflocale/addons/register` action.
	 *
	 * @param string $id Addon ID.
	 * @return bool
	 */
	public function is_bundled( string $id ): bool {
		return isset( $this->bundled[ $id ] );
	}

	/**
	 * Addons skipped because they need a newer PerfLocale: addon ID => required version.
	 *
	 * @return array<string, string>
	 */
	/**
	 * Addons that reported themselves incompatible and were skipped.
	 *
	 * @return array<string, bool>
	 */
	public function get_incompatible_ids(): array {
		return $this->incompatible;
	}

	public function get_version_mismatches(): array {
		return $this->version_mismatches;
	}

	/**
	 * Addons the `perflocale/addon/enabled` filter turned off this request (not those the disabled list turns off).
	 *
	 * @return array<string, bool>
	 */
	public function get_disabled_by_filter_ids(): array {
		return array_filter( $this->filter_off );
	}

	/**
	 * Option key of the operator-controlled disabled-addon list: indexed addon IDs; empty boots every compatible addon.
	 */
	public const DISABLED_OPTION = 'perflocale_disabled_addons'; // Public for AddonDisabledList.

	/**
	 * Read the disabled-addon list.
	 *
	 * Junk from a direct database write is dropped: only strings matching the addon-id pattern, deduplicated.
	 *
	 * @return array<int, string>
	 */
	public static function get_disabled(): array {
		$raw = get_option( self::DISABLED_OPTION, [] );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$valid = [];
		foreach ( $raw as $value ) {
			$candidate = is_scalar( $value ) ? (string) $value : '';
			if ( $candidate !== '' && AddonSchemaManager::validate_addon_id( $candidate ) ) {
				$valid[] = $candidate;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Set the on/off state for one addon in the disabled list and flush the bootable cache.
	 *
	 * False for a malformed id, a list over AddonDisabledList's size cap, a held write lock (Lock::with() does not wait)
	 * or a refused options write. The read runs inside the lock after dropping the cached options, so no change is lost.
	 *
	 * @param string $id       Addon ID.
	 * @param bool   $disabled Whether the addon should be skipped at boot.
	 * @return bool True when the stored list holds the requested state
	 *              (written, or already so); false on rejection.
	 */
	public static function set_disabled( string $id, bool $disabled ): bool {
		return AddonDisabledList::set_disabled( $id, $disabled );
	}

	/**
	 * Replace the entire disabled-addon list in one write.
	 *
	 * Used by the data importer. An empty array is a valid state (nothing disabled) and is stored; validation, size cap,
	 * lock and cache flush are as in set_disabled().
	 *
	 * @param array<int, string> $ids Addon IDs to disable. Non-string /
	 *                                 invalid / duplicate entries are dropped.
	 * @return bool True when the stored list equals the normalised one
	 *              (written, or already so); false on cap overflow, lock
	 *              contention, or a refused options write.
	 */
	public static function set_disabled_list( array $ids ): bool {
		return AddonDisabledList::set_disabled_list( $ids );
	}

	/**
	 * True if the addon is currently in the operator-controlled disabled list.
	 *
	 * @param string $id Addon ID.
	 * @return bool
	 */
	public static function is_disabled( string $id ): bool {
		return in_array( $id, self::get_disabled(), true );
	}

	/**
	 * Seed declared defaults from `get_settings_fields()` on the addon's first successful boot.
	 *
	 * "First" means the addon's settings entry is missing or empty. Fields without a `default` key and global-storage
	 * fields are skipped; a failed write does not fire `perflocale/addon/seeded` and is retried on the next boot.
	 *
	 * @param AddonInterface $addon Addon instance.
	 * @param string         $id    Addon ID.
	 * @return void
	 */
	private function maybe_seed_defaults( AddonInterface $addon, string $id ): void {
		// The bundled add-on GLOBAL_SETTINGS_ONLY names has nothing to seed.
		if ( self::GLOBAL_SETTINGS_ONLY === $id && ( $this->bundled[ $id ] ?? null ) === $addon ) {
			return;
		}

		try {
			$fields = $addon->get_settings_fields();
		} catch ( \Throwable $e ) {
			// A throwing get_settings_fields() must not block the boot.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'PerfLocale: get_settings_fields() threw for addon "%s" during default-seeding: %s',
						$id,
						$e->getMessage()
					)
				);
			}
			return;
		}

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return;
		}

		// Compute the seedable set before any I/O: all-global addons then never read the non-autoloaded option.
		$defaults = [];

		foreach ( $fields as $key => $field ) {
			if ( ! is_string( $key ) || $key === '' || ! is_array( $field ) ) {
				continue;
			}

			// Global-storage fields live in `perflocale_settings`; a copy here would never converge with it.
			if ( AddonSettings::is_global_storage( $field ) ) {
				continue;
			}

			if ( array_key_exists( 'default', $field ) ) {
				$defaults[ $key ] = $field['default'];
			}
		}

		if ( empty( $defaults ) ) {
			return;
		}

		// get_addon() returns [] for a missing and an empty entry alike; both seed.
		$existing = AddonSettings::get_addon( $id );

		if ( ! empty( $existing ) ) {
			return;
		}

		$seeded = AddonSettings::set_addon( $id, $defaults );

		if ( $seeded ) {
			/**
			 * Fires after an addon's declared defaults are seeded on its first successful boot.
			 *
			 * For one-shot first-activation work; it fires again only when the addon's settings entry is removed or emptied.
			 *
			 * @hook perflocale/addon/seeded Fires after auto-seed on first boot.
			 * @param string                $addon_id Addon's get_id() value.
			 * @param array<string, mixed>  $defaults The defaults that were seeded.
			 */
			do_action( 'perflocale/addon/seeded', $id, $defaults );
		}
	}

}
