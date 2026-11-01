<?php
/**
 * Database migrator.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs schema migrations (DB version) and non-DB upgrade tasks (code version).
 *
 * Only the current-site checks live here; pending work runs in UpgradeRunner (steps in SchemaMigrations).
 */
final class Migrator {

	/**
	 * Blog id => add-on state of the last clean add-on pass: the pass fires on two or three hooks per request.
	 *
	 * @var array<int, string>
	 */
	private array $addons_current = [];

	/**
	 * Register hooks on admin_init, rest_api_init and wp_loaded, so every kind of request migrates.
	 *
	 * Every handler returns early once current; the add-on pass also skips a repeat for the same blog and add-on state.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Standard admin path.
		add_action( 'admin_init', [ $this, 'maybe_migrate' ] );
		add_action( 'admin_init', [ $this, 'maybe_update' ] );
		add_action( 'admin_init', [ $this, 'maybe_migrate_addons' ] );

		// REST-only / headless sites: run on first REST request.
		add_action( 'rest_api_init', [ $this, 'maybe_migrate' ] );
		add_action( 'rest_api_init', [ $this, 'maybe_update' ] );
		add_action( 'rest_api_init', [ $this, 'maybe_migrate_addons' ] );

		// Front-end, cron and WP-CLI requests; priority 1 runs before code that needs the current schema. A failing step retries with backoff.
		add_action( 'wp_loaded', [ $this, 'maybe_migrate' ], 1 );
		add_action( 'wp_loaded', [ $this, 'maybe_update' ], 1 );
		add_action( 'wp_loaded', [ $this, 'maybe_migrate_addons' ], 2 );

		// Retry now of the upgrade failure notice; a literal name, as the constant would load UpgradeNotice.
		add_action( 'admin_post_perflocale_upgrade_retry', [ \PerfLocale\Admin\UpgradeNotice::class, 'handle_retry' ] );
	}

	/**
	 * Run pending migrations for every loaded addon, not disabled or quarantined, that implements HasSchema; failures are isolated per addon.
	 *
	 * @return void
	 */
	public function maybe_migrate_addons(): void {
		$plugin = \PerfLocale\Plugin::get_instance();
		if ( ! $plugin->has( 'addon_registry' ) ) {
			return;
		}
		$registry = $plugin->get( 'addon_registry' );
		if ( ! method_exists( $registry, 'get_loaded_addons' ) ) {
			return;
		}

		$disabled    = \PerfLocale\Addon\AddonRegistry::get_disabled();
		$quarantined = method_exists( $registry, 'get_quarantined_ids' ) ? $registry->get_quarantined_ids() : [];
		// Not get_addons(): it would load the files of disabled and quarantined addons only to skip them below.
		$addons = $registry->get_loaded_addons();

		// Keyed by blog (switch_to_blog()); a pass with a failure is not recorded, so the next firing retries it.
		$blog_id = get_current_blog_id();
		$ids     = is_array( $addons ) ? array_keys( $addons ) : [];
		$skipped = is_array( $quarantined ) ? array_map( static fn( $id ): string => is_scalar( $id ) ? (string) $id : '', $quarantined ) : [];
		$state   = implode( ',', $ids ) . '|' . implode( ',', $disabled ) . '|' . implode( ',', $skipped );

		if ( ( $this->addons_current[ $blog_id ] ?? null ) === $state ) {
			return;
		}

		$clean = true;

		// Keyed by addon id (the registration key), so the id comes from the registry, not an addon-author get_id() call.
		foreach ( $addons as $addon_id => $addon ) {
			if ( ! $addon instanceof \PerfLocale\Addon\AddonInterface ) {
				continue;
			}

			// Disabled or quarantined addons run no addon-author code here (it may throw); another caller's get_addons() may have
			// loaded them. A disabled addon's schema migrates on the first request after it is enabled again.
			if ( in_array( (string) $addon_id, $disabled, true ) || in_array( (string) $addon_id, $quarantined, true ) ) {
				continue;
			}

			// Incompatible addons: their schema has no place on this site. A throw is not an answer: the next firing asks again.
			try {
				if ( ! $addon->is_compatible() ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				$clean = false;
				continue;
			}

			// Refresh the manifest (checksum-gated, no write when unchanged), then migrate.
			// A throw from addon-author code is recorded for that addon, never fatal on wp_loaded.
			try {
				\PerfLocale\Addon\AddonManifestWriter::refresh( $addon );

				if ( $addon instanceof \PerfLocale\Addon\HasSchema && ! \PerfLocale\Addon\AddonSchemaManager::migrate( $addon ) ) {
					$clean = false;
				}
			} catch ( \Throwable $e ) {
				$clean = false;
				\PerfLocale\Addon\AddonMigrationErrors::record( (string) $addon_id, 'migrate', 0, UpgradeRunner::describe( $e ) );
			}
		}

		if ( $clean ) {
			$this->addons_current[ $blog_id ] = $state;
		}
	}

	/**
	 * Run pending schema steps when the stored DB version is below the code's.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		if ( (int) get_option( 'perflocale_db_version', 0 ) >= PERFLOCALE_DB_VERSION ) {
			return;
		}

		UpgradeRunner::pending( 'schema' );
	}

	/**
	 * Run the upgrade tasks (cache flush, orphan-group sweep, rewrite flag, perflocale/updated) when the code version changes.
	 *
	 * @return void
	 */
	public function maybe_update(): void {
		if ( version_compare( (string) get_option( 'perflocale_version', '0' ), PERFLOCALE_VERSION, '>=' ) ) {
			return;
		}

		// Inside the backoff (UpgradeRunner::due(), longest step 3600 s) a request without the admin notice skips loading the
		// runner; UpgradeRunner::STATE_OPTION written out, as the constant would load it.
		$state = get_option( 'perflocale_upgrade_state' );
		$next  = is_array( $state ) && is_array( $state['update'] ?? null ) ? ( $state['update']['next'] ?? 0 ) : 0;
		$wait  = is_int( $next ) ? $next - time() : 0;

		if ( $wait > 0 && $wait <= 3600 && ! is_admin() ) {
			return;
		}

		UpgradeRunner::pending( 'update' );
	}
}
