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
 * Handles schema migrations between plugin versions.
 *
 * Compares the stored DB version against PERFLOCALE_DB_VERSION and runs
 * any necessary migration methods, plus a separate code-version path for
 * non-DB upgrade tasks (cache flushes, rewrite rule regeneration).
 *
 * No concrete migrate_to_N methods exist on the canonical v1 schema -
 * fresh installs land on the final shape via Schema::create_tables() and
 * Activator::activate(). Future schema bumps add migrate_to_2(),
 * migrate_to_3(), etc. and bump PERFLOCALE_DB_VERSION; the dispatcher
 * below picks them up automatically via method_exists().
 */
final class Migrator {

	/**
	 * Register hooks.
	 *
	 * Coverage matrix:
	 *   - admin_init        — every wp-admin pageview.
	 *   - rest_api_init     — every REST request (covers headless / decoupled
	 *                         frontends that never load wp-admin).
	 *   - wp_loaded prio 1  — every other request: WP-CLI commands, wp-cron
	 *                         (DOING_CRON), and public frontend pageviews.
	 *                         Priority 1 puts us ahead of any code that
	 *                         depends on the schema being current.
	 *
	 * All three handlers are idempotent — `maybe_migrate()` early-returns
	 * when stored version >= target, `maybe_update()` when stored version
	 * matches the constant, and `maybe_migrate_addons()` is per-addon
	 * checksum-gated. Multiple firings during a single request are safe.
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

		// Catch-all for WP-CLI / wp-cron / frontend-only traffic that never
		// hits admin_init or rest_api_init. Idempotent so the duplicate
		// firing on admin/REST requests is a no-op.
		add_action( 'wp_loaded', [ $this, 'maybe_migrate' ], 1 );
		add_action( 'wp_loaded', [ $this, 'maybe_update' ], 1 );
		add_action( 'wp_loaded', [ $this, 'maybe_migrate_addons' ], 2 );
	}

	/**
	 * Run pending migrations for every registered addon that implements
	 * HasSchema. Failures are isolated per-addon - one broken addon doesn't
	 * block others.
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

			// Disabled or quarantined addons must not run migration/manifest
			// code. refresh()/migrate() call addon-author methods
			// (get_uninstall_targets(), get_schema_version()) that a
			// proven-broken addon can throw from — and boot-skipping never
			// unregisters, so get_addons() still returns them. A disabled
			// addon's schema simply migrates on the first request after
			// re-enable (this runs every request).
			if ( in_array( (string) $addon_id, $disabled, true ) || in_array( (string) $addon_id, $quarantined, true ) ) {
				continue;
			}

			// Skip incompatible addons - their schema shouldn't exist on this
			// environment anyway.
			try {
				if ( ! $addon->is_compatible() ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				continue;
			}

			// Refresh the manifest (captures declarative targets) if the addon
			// opts into either uninstall-related interface. Cheap; checksum-
			// gated so unchanged manifests don't write. Wrapped so a throw from
			// addon-author code isolates per-addon instead of fataling out of
			// wp_loaded, matching this method's documented contract.
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

		// Concurrency guard. maybe_migrate() is hooked on admin_init +
		// rest_api_init + wp_loaded, so right after an upgrade several parallel
		// requests can all pass the version gate above and run the SAME
		// migrate_to_N concurrently — a data-transform step would then race /
		// double-apply. A short-lived, atomic wp_cache_add mutex lets only the
		// first request through; the others bail and the migration completes on
		// that one request (or the next load if it was the loser).
		if ( ! wp_cache_add( 'migrating', 1, 'perflocale', 300 ) ) {
			return;
		}

		$old_version = (string) get_option( 'perflocale_db_version', '0' );

		try {
			// Run version-specific migrations BEFORE the additive dbDelta so
			// structural changes (drop-an-index, rename-a-column) complete
			// cleanly first. Commit the version AFTER each step's migrate_to_N
			// AND its dbDelta both succeed — mirroring AddonSchemaManager — so a
			// failure in a LATER step never re-runs an already-applied earlier
			// step (which, if not perfectly idempotent, would corrupt on the
			// second pass). Retry resumes from exactly the failed version.
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

				// Additive column/index changes for this version. dbDelta
				// reflects the full current schema and is idempotent, so running
				// it per step is safe; committing the version only after it runs
				// means version N is never recorded before N's columns exist.
				Schema::create_tables();

				// dbDelta swallows DDL errors and its return value reports
				// intent, not outcome — so a create that failed (restricted
				// grants, disk full, an index the server rejects) would
				// otherwise be recorded as a completed migration and never
				// retried. Ask the server what actually exists before
				// stamping; leaving the version behind means the next request
				// re-runs this step instead of silently moving on with a
				// half-provisioned schema.
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
	 * @param int    $version Target version that failed.
	 * @param string $message Error message.
	 * @return void
	 */
	private function show_migration_error( int $version, string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'PerfLocale migration to v%d failed: %s', $version, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		add_action(
			'admin_notices',
			static function () use ( $version, $message ): void {
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
	 * Detect plugin code version changes and run non-DB upgrade tasks.
	 *
	 * Separate from maybe_migrate() which handles DB schema changes only.
	 * This handles tasks like flushing caches, regenerating translation
	 * files, or clearing stale transients after a plugin file update.
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

			// One-time self-heal: reclaim widow translation groups that older
			// builds could leak (a string deleted without cascading its group;
			// a merge import that stranded groups). Safe + idempotent - after
			// the first pass there are no orphans, so the version gate above
			// makes this a no-op until the next upgrade.
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
