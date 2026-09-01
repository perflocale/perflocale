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
 *
 * Addons live in the addons/ directory and are loaded conditionally
 * based on their compatibility checks.
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
	 * True once the final boot pass (boot_pending at plugins_loaded:99, or the
	 * eager immediate pass) has run. Until then a late registration can still
	 * be picked up, so guard #2 in register() must not reject it just because
	 * an earlier pass already populated {@see self::$booted}.
	 *
	 * @var bool
	 */
	private bool $final_boot_done = false;

	/**
	 * Addons that were skipped this request because they declare a
	 * minimum PerfLocale version higher than the running one. Keyed by
	 * addon ID; value is the version the addon asked for. Surfaced on the
	 * Addons admin page so site owners know why an addon isn't active.
	 *
	 * @var array<string, string>
	 */
	private array $version_mismatches = [];

	/**
	 * Bundled addon manifest - keyed by directory name.
	 *
	 * Each entry carries:
	 *  - file:   absolute main-file path (resolved lazily via PERFLOCALE_DIR).
	 *  - class:  fully qualified class name that implements AddonInterface.
	 *  - compat: zero-arg callable that mirrors the target addon's
	 *            is_compatible() body - but DOES NOT require the addon's PHP
	 *            file to be loaded. Letting us skip the require_once entirely
	 *            on sites where the adjacent plugin isn't active is the whole
	 *            point of this manifest.
	 *
	 * Adding a new bundled addon: drop its directory under /addons and append
	 * an entry here. The runtime filesystem scan has been removed.
	 *
	 * The three closure bodies must stay in sync with each addon class's own
	 * is_compatible() method. Any divergence is considered a bug in THIS file
	 * - the addon's method remains the source of truth for IDE tooling and
	 * runtime safety.
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
				// AIOSEO defines AIOSEO_VERSION at load; min-version check is
				// re-applied inside the addon's own is_compatible() after the
				// file loads, so the gate here is the cheap presence check.
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
				// Slim-SEO 4.x renamed SlimSEO\Plugin → SlimSEO\Core. Accept
				// either so the manifest fast-path matches PerfLocaleSlimSeo::is_compatible().
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
		// Discover and boot addons after all plugins are active.
		// When booted as an eager service at init:0, plugins_loaded has already fired -
		// in that case call discover_and_boot() immediately instead of waiting.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->discover_and_boot();
			// plugins_loaded already passed, so the boot_pending(99) fallback
			// below will never fire — this immediate pass is the only one, and
			// any later registration genuinely cannot be booted.
			$this->final_boot_done = true;
		} else {
			add_action( 'plugins_loaded', [ $this, 'discover_and_boot' ], 20 );

			// Late-registration fallback: re-boot any addons registered via
			// `perflocale/addons/register` AFTER our main boot pass already
			// ran. Relies on $this->booted tracking to skip already-booted
			// addons - safe no-op when everything was handled earlier.
			add_action( 'plugins_loaded', [ $this, 'boot_pending' ], 99 );
		}
	}

	/**
	 * Boot any addons that were registered after the initial boot pass.
	 *
	 * External plugins that register via `perflocale/addons/register` at
	 * plugins_loaded priority 25-98 would miss the initial pass (which
	 * runs at 20). This fallback picks them up without double-booting
	 * the addons already processed.
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

		// This is the last scheduled boot pass (plugins_loaded:99); past it a
		// registration can no longer be booted, so let guard #2 reject it.
		$this->final_boot_done = true;
	}

	/**
	 * Discover addons from the addons/ directory and boot compatible ones.
	 *
	 * Bundled addons are declared in {@see self::bundled_manifest()}. Each
	 * entry carries a `compat` closure that mirrors the addon's own
	 * is_compatible() check but only touches the ADJACENT plugin's globals
	 * (constants, classes, helper functions). We call those closures first
	 * and only require + instantiate the addon class when the adjacent
	 * plugin is actually active.
	 *
	 * Net effect: a bare WP install with the plugin enabled no longer
	 * parses ~21 addon class files on every request. Each `require_once` is
	 * deferred until there is a real integration target to bind to.
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
				// Stale entry — cache references an addon that no longer exists
				// in the manifest (rare: only happens if PerfLocale itself was
				// updated to remove an addon while the cache is still warm).
				continue;
			}

			if ( isset( $this->addons[ $id ] ) ) {
				// Theme addons boot early and register their instance at
				// plugins_loaded:15 — that instance is authoritative, and a
				// second construction here would only trip duplicate guard #4.
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

		// Allow external plugins to register addons without placing files
		// in the bundled addons/ directory. External plugins hook here and
		// call $registry->register( new MyAddon() ).
		// Type-checked: only AddonInterface instances are accepted.
		/** @hook perflocale/addons/register Fires before addons boot. Pass your AddonInterface instance to $registry->register(). */
		do_action( 'perflocale/addons/register', $this );

		$this->boot_addons();
	}

	/**
	 * Resolve which addon IDs should boot this request. Cached in a
	 * transient so we only execute the per-addon compat closures once
	 * per environment change instead of every request.
	 *
	 * @param array<string, array{file: string, class: string, compat: callable}> $manifest
	 * @return string[]
	 */
	private static function resolve_bootable_ids( array $manifest ): array {
		// The transient trades one persistent read for ~21 sub-microsecond
		// compat closures (defined()/class_exists()-style checks). That trade
		// only wins when the read is an object-cache hit: WITHOUT a
		// persistent object cache a TTL'd transient is an autoload=off
		// options row, so the "cache" COSTS one options-table SELECT per
		// request (plus a site-option generation read on multisite) to save
		// microseconds. Plain sites therefore skip the transient machinery
		// entirely and recompute — byte-identical output, and staleness
		// cannot exist because nothing is cached on that branch.
		$use_cache  = (bool) wp_using_ext_object_cache();
		$generation = $use_cache ? self::bootable_generation() : 0;

		if ( $use_cache ) {
			$cached = get_transient( self::BOOTABLE_TRANSIENT );

			if ( is_array( $cached )
				&& isset( $cached['ids'] ) && is_array( $cached['ids'] )
				&& (int) ( $cached['gen'] ?? -1 ) === $generation
			) {
				return $cached['ids'];
			}
		}

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

		if ( $use_cache ) {
			set_transient(
				self::BOOTABLE_TRANSIENT,
				[
					'gen' => $generation,
					'ids' => $bootable,
				],
				self::BOOTABLE_TTL
			);
		}

		return $bootable;
	}

	/**
	 * Current network-wide generation for the bootable cache. The transient
	 * is per-blog; baking this generation into its payload lets one
	 * update_site_option() bump invalidate every blog's copy without
	 * enumerating blogs. Single-site has no cross-blog staleness problem,
	 * so a constant keeps its reads free.
	 *
	 * @return int
	 */
	private static function bootable_generation(): int {
		if ( ! is_multisite() ) {
			return 0;
		}

		return (int) get_site_option( self::BOOTABLE_GEN_OPTION, 0 );
	}

	/**
	 * Invalidate the bootable-addons cache. Hooked to plugin/theme
	 * lifecycle events that could flip a compat closure's result. Safe to
	 * call repeatedly — extra calls only force rebuilds, never staleness.
	 *
	 * Public so the WP-CLI helper can force a rebuild from the cache flush
	 * command and so a misbehaving environment can trigger one from the
	 * `perflocale/addons/cache/flush` action.
	 *
	 * @return void
	 */
	public static function flush_bootable_cache(): void {
		delete_transient( self::BOOTABLE_TRANSIENT );

		if ( is_multisite() ) {
			// Lifecycle hooks fire in a single blog's context, but a
			// network-wide plugin flip changes the compat result for every
			// blog — bump the network generation so each blog's per-blog
			// transient is discarded on its next read instead of lingering
			// until the TTL lapses.
			update_site_option(
				self::BOOTABLE_GEN_OPTION,
				(int) get_site_option( self::BOOTABLE_GEN_OPTION, 0 ) + 1
			);
		}
	}

	/**
	 * Register addon-bootable-cache invalidation hooks. Called once from
	 * Bootstrap::init at plugin load — runs in every context, since plugin
	 * activations can happen via admin, CLI, multisite network admin, etc.
	 *
	 * @return void
	 */
	public static function register_cache_invalidation(): void {
		// Plugin/theme activation surface — anything that toggles whether
		// an integration target's class/function exists.
		add_action( 'activated_plugin', [ self::class, 'flush_bootable_cache' ] );
		add_action( 'deactivated_plugin', [ self::class, 'flush_bootable_cache' ] );
		add_action( 'switch_theme', [ self::class, 'flush_bootable_cache' ] );

		// Plugin update completion — the new version might define / un-define
		// a class our compat closure looks for.
		add_action( 'upgrader_process_complete', [ self::class, 'flush_bootable_cache' ] );

		// Multisite: a plugin can be activated network-wide or per-blog.
		// Both routes raise `activated_plugin`, but a network-wide flip on
		// blog N still leaves blog M's transient untouched — flush all on
		// network activation so every blog rebuilds on next request.
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

		// (1) Invalid addon ID: the canonical contract is
		// AddonSchemaManager::ADDON_ID_PATTERN (2-16 char [a-z0-9_-] slug) —
		// the same pattern gates settings, the disabled list, schema
		// migrations, and the uninstall manifest. Enforcing it here means an
		// out-of-contract ID fails fast at registration instead of booting
		// and then silently no-oping on every persistence surface.
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

		// (2) Late registration: gate on $final_boot_done, NOT on a non-empty
		// $booted map. The first boot pass (plugins_loaded:20) populates
		// $booted, but boot_pending() at priority 99 is specifically there to
		// boot addons registered in the 21-98 window — keying this guard on
		// "$booted is non-empty" would reject exactly those and make the
		// fallback dead code. Only reject once the final pass has run.
		if ( $this->final_boot_done ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the addon ID. */
						__( 'Addon "%s" was registered AFTER the perflocale/addons/register action fired — its boot() will never be called. Hook your registration on perflocale/addons/register (or earlier).', 'perflocale' ),
						$id
					)
				),
				'1.0.0'
			);
			return;
		}

		// (3) Bundled-addon conflict: bundled addons (from the addons/
		// directory) cannot be overridden by external plugins. Upgraded
		// from a silent error_log to _doing_it_wrong so the rejection is
		// visible in WP_DEBUG mode for the addon author.
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

		// (4) Duplicate non-bundled registration: silently overwriting the
		// prior instance would drop whatever state the first instance set
		// up; second-call wins is rarely what the developer intended.
		if ( isset( $this->addons[ $id ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the addon ID. */
						__( 'Addon "%s" is already registered. Registering twice would overwrite the prior instance — second-call ignored.', 'perflocale' ),
						$id
					)
				),
				'1.0.0'
			);
			return;
		}

		// (5-7) Dev-only contract nudges. _doing_it_wrong() is WP_DEBUG-gated
		// by core anyway, so running the underlying preg_match / trim / is_array
		// checks in production would only burn cycles to report nothing. The
		// outer guard keeps prod boot cost at one `defined()` check per addon
		// (~50 ns × N addons) while still surfacing real contract violations
		// in dev. Checks 1-4 above don't get this gate — they have behavioural
		// side effects (return early) that must run in production too.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// (5) Empty get_name(): breaks Site Health "addon health" cards
			// and the admin Addons page row label.
			if ( trim( $addon->get_name() ) === '' ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: %s is the addon ID. */
							__( 'Addon "%s" returned an empty AddonInterface::get_name() — admin UI rows + Site Health cards rely on it. Provide a human-readable name.', 'perflocale' ),
							$id
						)
					),
					'1.0.0'
				);
			}

			// (6) Malformed get_version(): version_compare() against the
			// addon's own get_min_perflocale_version() requires a dot-version
			// string; anything else silently treats the gate as invalid.
			// Accept optional pre-release suffix (e.g. 1.0.0-beta).
			$version = $addon->get_version();
			if ( ! preg_match( '/^\d+\.\d+(\.\d+)?([+\-][0-9A-Za-z.\-]+)?$/', $version ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: addon ID, 2: offending version string. */
							__( 'Addon "%1$s" returned get_version()="%2$s" — must be a dot-version string (e.g. 1.0.0 or 1.0.0-beta). Compatibility gates via version_compare() will silently treat it as invalid.', 'perflocale' ),
							$id,
							$version
						)
					),
					'1.0.0'
				);
			}

			// (7) get_required_plugins() must return an array<string>;
			// non-string entries break the compat gate.
			$required = $addon->get_required_plugins();
			if ( ! is_array( $required ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: addon ID, 2: offending return type. */
							__( 'Addon "%1$s" returned get_required_plugins() = %2$s — must be an array of plugin-file strings (or empty array).', 'perflocale' ),
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
						__( 'A hook on perflocale/addons/registered returned %s — must be an array<string, AddonInterface>. Falling back to the unfiltered registration map.', 'perflocale' ),
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

		// Persistent failure counter - addons that throw repeatedly get
		// auto-quarantined so a broken third-party addon can't degrade the
		// whole site on every request.
		$failures        = (array) get_option( self::FAILURE_OPTION, [] );
		$failure_changed = false;

		$disabled = self::get_disabled();

		foreach ( $this->addons as $id => $addon ) {
			if ( isset( $this->booted[ $id ] ) ) {
				continue;
			}

			// Filter-resolved threshold; a value <= 0 disables quarantine
			// entirely (every failure is retried on the next request).
			$threshold = self::failure_threshold();
			if ( $threshold > 0 && ! empty( $failures[ $id ] ) && (int) $failures[ $id ] >= $threshold ) {
				// Quarantined until reset via reset_quarantine(). Skip boot.
				continue;
			}

			// Operator-controlled enable/disable. Any addon in the disabled
			// list is skipped at boot — bundled and external alike. The
			// old "bundled addons can't be disabled without a filter
			// override" safeguard was confusing UX: clicking Disable in
			// the admin wrote the option but boot still honoured the
			// addon, so `wp perflocale addon doctor` showed it BOTH
			// booted AND disabled. Removed.
			if ( in_array( $id, $disabled, true ) ) {
				continue;
			}

			// Addons that opt into version pinning via HasVersionRequirement
			// are skipped (not failed) when the host plugin is older than
			// the floor they ask for. The admin notice on the Addons page
			// surfaces the gap so the operator knows to upgrade PerfLocale.
			//
			// The SKIP must happen in every context (the addon's hooks
			// shouldn't fire on frontend either), but the RECORD-into-the-
			// list step is only consumed by the AddonsPage admin notice —
			// so we skip the array write on frontend / AJAX / REST / cron
			// to save a few microseconds and a few bytes of resident
			// memory on every non-admin request.
			if ( $addon instanceof HasVersionRequirement && defined( 'PERFLOCALE_VERSION' ) ) {
				$required = (string) $addon->get_min_perflocale_version();

				if ( $required !== '' && version_compare( (string) PERFLOCALE_VERSION, $required, '<' ) ) {
					if ( is_admin() && ! wp_doing_ajax() ) {
						$this->version_mismatches[ $id ] = $required;
					}
					continue;
				}
			}

			// is_compatible() is addon-author code just like boot(), so it
			// runs inside the same try: a throw feeds the quarantine counter
			// instead of fataling every request.
			try {
				/**
				 * Filter addon compatibility.
				 *
				 * @param bool $compatible Whether the addon is compatible.
				 * @param string $addon_id Addon ID.
				 */
				$compatible = apply_filters( 'perflocale/addon/is_compatible', $addon->is_compatible(), $id );

				if ( ! $compatible ) {
					continue;
				}

				$addon->boot( $plugin );
				$this->booted[ $id ] = true;

				// Clear any historical failure on a successful boot.
				if ( isset( $failures[ $id ] ) ) {
					unset( $failures[ $id ] );
					$failure_changed = true;
				}

				// Auto-seed declared defaults on the addon's first
				// successful boot. Saves addon authors from writing
				// imperative "if option doesn't exist, set it" code in
				// every boot() method, AND means AddonSettings::get()
				// reads return the documented default without callers
				// having to remember to pass one. Done AFTER boot() so
				// boot() failures don't leave a half-initialised entry
				// in the option.
				$this->maybe_seed_defaults( $addon, $id );

				/** @hook perflocale/addon/activated Fires after an addon is booted. */
				do_action( 'perflocale/addon/activated', $id );
			} catch ( \Throwable $e ) {
				// Addon boot failure must not crash the site. Increment the
				// persistent counter so repeatedly-broken addons are skipped
				// on subsequent requests instead of spiking CPU every load.
				$failures[ $id ] = (int) ( $failures[ $id ] ?? 0 ) + 1;
				$failure_changed = true;

				// Persist the message so the Addons admin page can surface
				// it inline on the card — operators no longer need to SSH
				// + tail error_log to find out why a card reads "Quarantined".
				AddonMigrationErrors::record(
					$id,
					'boot',
					(int) $failures[ $id ],
					$e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
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

	/**
	 * Transient name for the cached "which addons should boot" decision.
	 *
	 * Stores an array of addon IDs whose compat check has already passed.
	 * Lets us skip ~21 closure invocations per request once the cache is
	 * warm (the closures themselves are cheap but they add up on a site
	 * with many integrations active). Invalidated automatically when
	 * plugins/themes change, with a 12-hour ceiling as a safety net.
	 */
	private const BOOTABLE_TRANSIENT = 'perflocale_bootable_addons';

	/**
	 * Site option holding the network-wide generation number embedded in
	 * each blog's cached bootable payload (multisite only — see
	 * {@see self::bootable_generation()} / {@see self::flush_bootable_cache()}).
	 */
	private const BOOTABLE_GEN_OPTION = 'perflocale_bootable_gen';

	/**
	 * Cache TTL ceiling. Hooks below (`activated_plugin`, `deactivated_plugin`,
	 * `switch_theme`, `upgrader_process_complete`) invalidate eagerly on
	 * any change that could flip a compat result. The TTL is the
	 * defence-in-depth backstop: even if every invalidation hook misses,
	 * the cache rebuilds within 12 hours.
	 */
	private const BOOTABLE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Default number of consecutive boot failures before an addon is
	 * auto-quarantined.
	 *
	 * 3 tolerates transient issues (missing dependency during an upgrade
	 * window, a one-off OOM, etc.) before marking the addon as broken.
	 * Operators with flaky network-dependent addons can raise this via
	 * the `perflocale/addons/quarantine_threshold` filter (e.g. return 10
	 * to be more lenient). Returning 0 or a negative number disables
	 * quarantine entirely (every failure is retried on next request);
	 * returning 1 quarantines on the very first failure.
	 */
	private const FAILURE_THRESHOLD = 3;

	/**
	 * Resolved (filter-applied) failure threshold. Memoised per request
	 * so the filter doesn't fire on every boot iteration.
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
	 * True when the addon ID was registered from the bundled manifest
	 * (the addons/ directory inside the plugin), false when it came from
	 * an external plugin via the perflocale/addons/register action.
	 *
	 * Useful for CLI tooling and admin UI that needs to surface "this
	 * addon ships with PerfLocale itself" — bundled addons need --force
	 * to disable, and external addons honour the disabled list without
	 * any filter override.
	 *
	 * @param string $id Addon ID.
	 * @return bool
	 */
	public function is_bundled( string $id ): bool {
		return isset( $this->bundled[ $id ] );
	}

	/**
	 * Addons whose declared minimum PerfLocale version is higher than
	 * the running plugin. Map of addon ID → required version string.
	 *
	 * @return array<string, string>
	 */
	public function get_version_mismatches(): array {
		return $this->version_mismatches;
	}

	/**
	 * Option key storing the operator-controlled disabled-addon list.
	 *
	 * Format: indexed array of addon IDs. Empty array (the default) means
	 * "boot every compatible addon" — the historic behaviour.
	 */
	private const DISABLED_OPTION = 'perflocale_disabled_addons';

	/**
	 * Read the disabled-addon list. Bundled addons are normally exempt
	 * from this list at boot time (see boot_addons), so the list is
	 * primarily for external addons registered via the
	 * `perflocale/addons/register` action.
	 *
	 * Defensive normalisation: a direct DB write outside our own setter
	 * could leave junk in the option (nulls, numbers, malformed strings).
	 * We coerce to strings, drop anything that doesn't match the
	 * canonical addon-id pattern, then dedupe — guaranteeing the boot
	 * loop's `in_array(..., true)` comparisons always run against valid
	 * ids and that one bad direct-DB write can't put empty-string entries
	 * into the bootable cache.
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
	 * Hard cap on the serialised size of the disabled-addons option. The
	 * option is autoloaded, so anything stored here is deserialised on
	 * every request — a malicious or buggy caller mustn't be able to grow
	 * it without bound. 4 KiB comfortably fits hundreds of well-formed
	 * addon ids (16-char regex × ~150 = 2.4 KiB before overhead). Any
	 * write that would push past the cap is rejected, returns false, and
	 * is logged under WP_DEBUG.
	 */
	private const DISABLED_OPTION_MAX_BYTES = 4096;

	/**
	 * Lock name for serialised writes to the disabled-addons option.
	 * Without this two concurrent set_disabled() calls (admin + CLI, or
	 * two AJAX toggles in close succession) can race: both read the same
	 * snapshot, both mutate, the second update_option clobbers the first
	 * one's commit. Same pattern AddonSettings uses for the settings
	 * option, on a separate lock name so toggling an addon doesn't block
	 * settings saves.
	 */
	private const DISABLED_LOCK_NAME = 'addon_disabled_write';

	/** Short TTL — the work inside is a single update_option call. */
	private const DISABLED_LOCK_TTL = 10;

	/**
	 * Set the on/off state for one addon. Persists to the disabled-list
	 * option and bumps the bootable cache so the next request reflects
	 * the change without waiting on the 12-hour TTL.
	 *
	 * Rejects (returns false) when:
	 *   - the addon id is malformed (regex pattern from AddonSchemaManager)
	 *   - the resulting serialised list would exceed
	 *     {@see DISABLED_OPTION_MAX_BYTES}
	 *   - the write lock could not be acquired within
	 *     {@see DISABLED_LOCK_TTL} seconds (rare, lock contention)
	 *
	 * Concurrency: the whole read-mutate-write is wrapped in
	 * `Lock::with()` AND the read happens INSIDE the lock from the raw
	 * option (bypassing in-memory + object caches) so two concurrent
	 * callers can't lose either commit.
	 *
	 * @param string $id       Addon ID.
	 * @param bool   $disabled Whether the addon should be skipped at boot.
	 * @return bool True on commit; false on rejection (caller can surface).
	 */
	public static function set_disabled( string $id, bool $disabled ): bool {
		$id = trim( $id );

		// Validate the id BEFORE we touch the lock so callers learn about
		// bad input via the return value rather than a silent no-op.

		if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'PerfLocale AddonRegistry::set_disabled rejected — invalid addon_id "%s"', substr( $id, 0, 64 ) ) );
			}
			return false;
		}

		$result = \PerfLocale\Concurrency\Lock::with(
			self::DISABLED_LOCK_NAME,
			self::DISABLED_LOCK_TTL,
			static function () use ( $id, $disabled ): bool {
				// Re-read the option inside the lock, bypassing caches —
				// otherwise a concurrent commit between our outer read and
				// our update_option could be silently lost.
				wp_cache_delete( self::DISABLED_OPTION, 'options' );
				$current = (array) get_option( self::DISABLED_OPTION, [] );

				// Normalise the same way get_disabled() does so we never
				// re-serialise junk back to the DB even if the option got
				// poisoned by a direct write.
				$normalised = [];
				foreach ( $current as $value ) {
					$candidate = is_scalar( $value ) ? (string) $value : '';
					if ( $candidate !== '' && AddonSchemaManager::validate_addon_id( $candidate ) ) {
						$normalised[] = $candidate;
					}
				}
				$current = array_values( array_unique( $normalised ) );

				if ( $disabled ) {
					if ( ! in_array( $id, $current, true ) ) {
						$current[] = $id;
					}
				} else {
					$current = array_values( array_filter( $current, static fn( $value ) => (string) $value !== $id ) );
				}

				// Bounds check the post-mutation value. Without this an
				// attacker or buggy script that called set_disabled() in a
				// loop could grow the autoloaded option until WP startup was
				// visibly slow.
				$serialised = (string) maybe_serialize( $current );

				if ( strlen( $serialised ) > self::DISABLED_OPTION_MAX_BYTES ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log(
							sprintf(
								'PerfLocale AddonRegistry::set_disabled rejected — disabled-list would exceed %d bytes (current %d)',
								self::DISABLED_OPTION_MAX_BYTES,
								strlen( $serialised )
							)
						);
					}
					return false;
				}

				update_option( self::DISABLED_OPTION, $current, true );
				return true;
			}
		);

		// Lock::with() returns null when acquire timed out — surface as
		// a transient failure so callers (admin handler / CLI) can react.
		if ( $result === null ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'PerfLocale AddonRegistry::set_disabled rejected — lock contention on "%s"', $id ) );
			}
			return false;
		}

		if ( $result === true ) {
			// Flush the bootable-IDs transient — the disabled flag
			// changes whether an addon should boot next request. Doing
			// this OUTSIDE the lock is fine: the transient is a cache
			// invalidation signal, not authoritative state.
			self::flush_bootable_cache();
		}

		return (bool) $result;
	}

	/**
	 * Replace the entire disabled-addon list in one write.
	 *
	 * Unlike {@see set_disabled()} (which toggles one id), this overwrites
	 * the whole list — used by the data importer to restore an exported
	 * operator intent. An empty array means "nothing disabled" and is a
	 * valid, meaningful state (must be stored literally, not skipped).
	 *
	 * Applies the same guarantees as set_disabled(): canonical-id
	 * validation, dedupe, the {@see DISABLED_OPTION_MAX_BYTES} cap (so a
	 * crafted import can't bloat the autoloaded option), a serialised
	 * write under {@see DISABLED_LOCK_NAME}, and a bootable-cache flush so
	 * the change takes effect next request.
	 *
	 * @param array<int, string> $ids Addon IDs to disable. Non-string /
	 *                                 invalid / duplicate entries are dropped.
	 * @return bool True on commit; false on cap overflow or lock contention.
	 */
	public static function set_disabled_list( array $ids ): bool {
		$normalised = [];
		foreach ( $ids as $value ) {
			$candidate = is_scalar( $value ) ? (string) $value : '';
			if ( $candidate !== '' && AddonSchemaManager::validate_addon_id( $candidate ) ) {
				$normalised[] = $candidate;
			}
		}
		$normalised = array_values( array_unique( $normalised ) );

		$serialised = (string) maybe_serialize( $normalised );
		if ( strlen( $serialised ) > self::DISABLED_OPTION_MAX_BYTES ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'PerfLocale AddonRegistry::set_disabled_list rejected — list would exceed %d bytes (got %d)',
						self::DISABLED_OPTION_MAX_BYTES,
						strlen( $serialised )
					)
				);
			}
			return false;
		}

		$result = \PerfLocale\Concurrency\Lock::with(
			self::DISABLED_LOCK_NAME,
			self::DISABLED_LOCK_TTL,
			static function () use ( $normalised ): bool {
				update_option( self::DISABLED_OPTION, $normalised, true );
				return true;
			}
		);

		if ( $result !== true ) {
			return false;
		}

		self::flush_bootable_cache();
		return true;
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
	 * Seed declared defaults from `get_settings_fields()` into the addon
	 * settings option, but ONLY on the addon's first successful boot.
	 *
	 * "First" is detected by the absence of any entry for `$id` in the
	 * settings option — so:
	 *   • Fresh install / brand-new addon         → seed runs once.
	 *   • Subsequent boots                        → no-op (entry exists).
	 *   • User opened the admin form and saved
	 *     (even if every value matches default)   → no-op (entry exists).
	 *   • User manually wrote an empty entry
	 *     via set_addon([])                       → re-seeds, by design;
	 *     the explicit "no settings" state is
	 *     uncommon and the alternative (track a
	 *     separate "seeded" option) is more state
	 *     for marginal benefit.
	 *
	 * Each field's `default` value is only written if the field's `type`
	 * declares a default — hidden/addon-managed fields with no default
	 * are skipped so addons that use 'hidden' as opaque scratch space
	 * don't get stuck with a generic init value.
	 *
	 * Errors during seeding are swallowed: a failed AddonSettings::set_addon
	 * (size cap, lock contention) is logged via that class's own paths and
	 * does not prevent the addon's boot from succeeding.
	 *
	 * @param AddonInterface $addon
	 * @param string         $id
	 * @return void
	 */
	private function maybe_seed_defaults( AddonInterface $addon, string $id ): void {
		try {
			$fields = $addon->get_settings_fields();
		} catch ( \Throwable $e ) {
			// A throwing get_settings_fields() shouldn't block the boot
			// path. Other consumers (the admin page) already guard the
			// same call. Skip seeding silently.
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

		// Compute the seedable set BEFORE any I/O: for addons whose fields
		// are all global-storage (e.g. WooCommerce) $defaults is permanently
		// empty, and bailing here means those addons never trigger the
		// perflocale_addon_settings option read (autoload=off — one
		// options-table SELECT per request on non-object-cache sites when
		// this seeding pass is the request's only reader).
		$defaults = [];

		foreach ( $fields as $key => $field ) {
			if ( ! is_string( $key ) || $key === '' || ! is_array( $field ) ) {
				continue;
			}

			// 'global'-storage fields live in `perflocale_settings`, not
			// here — seeding them would create a stale duplicate copy
			// that confuses `wp perflocale addon settings get` and never
			// converges with the live value.
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

		// Only seed if the addon has no entry yet. AddonSettings::get_addon
		// returns [] for both "missing" and "empty" — both are the seed
		// trigger here, which is fine (see method docblock).
		$existing = AddonSettings::get_addon( $id );

		if ( ! empty( $existing ) ) {
			return;
		}

		$seeded = AddonSettings::set_addon( $id, $defaults );

		if ( $seeded ) {
			/**
			 * Fires once, after an addon's declared default settings have
			 * been written to perflocale_addon_settings on its first
			 * successful boot. Use this for one-shot first-activation
			 * work (welcome email, sample-data import, telemetry opt-in
			 * prompt). Will NOT fire on subsequent boots even if the
			 * user clears all settings — re-firing would require an
			 * explicit AddonSettings::forget($id) call.
			 *
			 * @hook perflocale/addon/seeded Fires after auto-seed on first boot.
			 * @param string                $addon_id Addon's get_id() value.
			 * @param array<string, mixed>  $defaults The defaults that were seeded.
			 */
			do_action( 'perflocale/addon/seeded', $id, $defaults );
		}
	}

}
