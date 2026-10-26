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
 * No migrate_to_N exists on the v1 schema; a schema bump adds migrate_to_N() (found by method_exists()) and raises
 * PERFLOCALE_DB_VERSION.
 */
final class Migrator {

	/**
	 * Register hooks on admin_init, rest_api_init and wp_loaded, so every kind of request migrates.
	 *
	 * Every handler returns early once current, so firing more than once per request is safe.
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

		// WP-CLI, cron and front-end requests; priority 1 runs before code that needs the current schema.
		// While an add-on table cannot be created, each firing retries it and logs the server's refusal.
		add_action( 'wp_loaded', [ $this, 'maybe_migrate' ], 1 );
		add_action( 'wp_loaded', [ $this, 'maybe_update' ], 1 );
		add_action( 'wp_loaded', [ $this, 'maybe_migrate_addons' ], 2 );
	}

	/**
	 * Run pending migrations for every registered addon that implements HasSchema; failures are isolated per addon.
	 *
	 * @return void
	 */
	public function maybe_migrate_addons(): void {
		$plugin = \PerfLocale\Plugin::get_instance();
		if ( ! $plugin->has( 'addon_registry' ) ) {
			return;
		}
		$registry = $plugin->get( 'addon_registry' );
		if ( ! method_exists( $registry, 'get_addons' ) ) {
			return;
		}

		$disabled    = \PerfLocale\Addon\AddonRegistry::get_disabled();
		$quarantined = method_exists( $registry, 'get_quarantined_ids' ) ? $registry->get_quarantined_ids() : [];

		// get_addons() is keyed by addon id (the registration key), so the id
		// comes from the registry, not an addon-author get_id() call.
		foreach ( $registry->get_addons() as $addon_id => $addon ) {
			if ( ! $addon instanceof \PerfLocale\Addon\AddonInterface ) {
				continue;
			}

			// Disabled or quarantined addons run no addon-author code here (it may throw); get_addons() still lists them.
			// A disabled addon's schema migrates on the first request after it is enabled again.
			if ( in_array( (string) $addon_id, $disabled, true ) || in_array( (string) $addon_id, $quarantined, true ) ) {
				continue;
			}

			// Incompatible addons: their schema has no place on this site.
			try {
				if ( ! $addon->is_compatible() ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				continue;
			}

			// Refresh the manifest (checksum-gated, no write when unchanged), then migrate.
			// A throw from addon-author code is recorded for that addon, never fatal on wp_loaded.
			try {
				\PerfLocale\Addon\AddonManifestWriter::refresh( $addon );

				if ( $addon instanceof \PerfLocale\Addon\HasSchema ) {
					\PerfLocale\Addon\AddonSchemaManager::migrate( $addon );
				}
			} catch ( \Throwable $e ) {
				\PerfLocale\Addon\AddonMigrationErrors::record( (string) $addon_id, 'migrate', 0, $e->getMessage() );
			}
		}
	}

	/**
	 * Run pending migrations if the DB version is outdated.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		$current_version = (int) get_option( 'perflocale_db_version', 0 );
		$target_version  = PERFLOCALE_DB_VERSION;

		if ( $current_version >= $target_version ) {
			return;
		}

		// Mutex: after an upgrade parallel requests all pass the version gate and would run the same migrate_to_N
		// twice; the atomic wp_cache_add() lets only the first through.
		if ( ! wp_cache_add( 'migrating', 1, 'perflocale', 300 ) ) {
			return;
		}

		$old_version = (string) get_option( 'perflocale_db_version', '0' );

		try {
			// Per step: migrate_to_N (before dbDelta, so drops and renames finish first), dbDelta, the table check,
			// then the version recorded. A later failure never re-runs an applied step; a retry resumes at the failed one.
			for ( $version = $current_version + 1; $version <= $target_version; $version++ ) {
				$method = 'migrate_to_' . $version;

				if ( method_exists( $this, $method ) ) {
					try {
						$this->$method();
					} catch ( \Throwable $e ) {
						$this->show_migration_error( $version, $e->getMessage() );
						// Don't bump version - migration will retry on next load.
						return;
					}
				}

				// Additive changes; dbDelta holds the full current schema and is idempotent.
				Schema::create_tables();

				// dbDelta swallows DDL errors: confirm the tables exist before recording, or a failed create is never retried.
				// Tables only: a step whose dbDelta adds a column or index must also check it here by name before recording.
				$missing = Schema::missing_tables();

				if ( $missing !== [] ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on a schema-failure path the operator must see.
					error_log(
						'[PerfLocale] schema step ' . $version . ' did not complete; missing table(s): '
						. implode( ', ', $missing ) . '. Version not recorded; will retry.'
					);

					return;
				}

				update_option( 'perflocale_db_version', $version, true );
			}
		} finally {
			wp_cache_delete( 'migrating', 'perflocale' );
		}

		/** @hook perflocale/upgraded Fires after a database migration. */
		do_action( 'perflocale/upgraded', $old_version, (string) $target_version );
	}

	/**
	 * Show a persistent admin notice when a migration fails.
	 *
	 * Only to users who manage options (network options on multisite), paths redacted; the debug log keeps the full text.
	 *
	 * @param int    $version Target version that failed.
	 * @param string $message Error message.
	 * @return void
	 */
	private function show_migration_error( int $version, string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'PerfLocale migration to v%d failed: %s', $version, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$message = \PerfLocale\Util\PathRedactor::redact( $message );

		add_action(
			'admin_notices',
			static function () use ( $version, $message ): void {
				if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-error"><p><strong>PerfLocale</strong>: %s</p></div>',
					sprintf(
					/* translators: 1: version number, 2: error message */
						esc_html__( 'Database migration to version %1$d failed: %2$s. The plugin will retry on next page load.', 'perflocale' ),
						absint( $version ),
						esc_html( $message )
					)
				);
			}
		);
	}

	/**
	 * Run non-DB upgrade tasks (cache flush, orphan-group sweep, rewrite flush) when the plugin code version changes.
	 *
	 * @return void
	 */
	public function maybe_update(): void {
		$stored_version = get_option( 'perflocale_version', '0' );

		if ( version_compare( $stored_version, PERFLOCALE_VERSION, '>=' ) ) {
			return;
		}

		// Flush all caches - new code may produce different output.
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'cache' ) ) {
			$plugin->get( 'cache' )->flush_all();

			// Reclaim orphan translation groups (a string deleted without its group, a merge import that stranded some);
			// idempotent, and the version gate runs it once per upgrade.
			( new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) ) )
				->sweep_orphan_groups();
		}

		// Flag rewrite rules for regeneration.
		update_option( 'perflocale_flush_rules', 1, false );

		/**
		 * Fires after the plugin code version is updated.
		 *
		 * Use this hook for version-specific non-DB tasks (e.g., migrating
		 * settings keys, regenerating files, showing changelog notices).
		 *
		 * @param string $old_version Previous plugin version.
		 * @param string $new_version Current plugin version.
		 */
		do_action( 'perflocale/updated', $stored_version, PERFLOCALE_VERSION );

		update_option( 'perflocale_version', PERFLOCALE_VERSION, true );
	}
}
