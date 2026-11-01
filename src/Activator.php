<?php
/**
 * Plugin activator.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

use PerfLocale\Admin\TranslatorRole;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation: tables, the default language and initial options; safe to run again.
 */
final class Activator {

	/**
	 * The activation hook: this site, or every site of the network on a network activation (chunked; refused when one
	 * site could not be set up).
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		if ( is_multisite() ) {
			// A resume marker belongs to the uninstall that wrote it: activation ends that
			// uninstall, and honouring it on the next one would skip every blog below it.
			\PerfLocale\Database\SiteCleanup::forget_uninstall_progress();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_multisite() && ! empty( $_GET['networkwide'] ) ) {
			// Network activation: walk sites in chunks to keep memory flat on large networks.
			/**
			 * @hook perflocale/activation/chunk_size
			 * @param int $chunk Sites fetched per iteration. Must be >= 1.
			 */
			$chunk      = max( 1, (int) apply_filters( 'perflocale/activation/chunk_size', 100 ) );
			$offset     = 0;
			$network_id = get_current_network_id();

			// Sites that could not be set up, and how many were tried.
			$perflocale_failed_sites = [];
			$perflocale_tried_sites  = 0;

			do {
				$sites = get_sites(
					[
						'number'     => $chunk,
						'offset'     => $offset,
						'fields'     => 'ids',
						// This network only: WP_Site_Query's network_id 0 means all networks,
						// and network activation is per network. Mirrors Deactivator's sweep.
						'network_id' => $network_id,
						// Explicit: offset paging is only stable against a fixed sort.
						'orderby'    => 'id',
					]
				);

				foreach ( $sites as $site_id ) {
					++$perflocale_tried_sites;

					switch_to_blog( $site_id );

					try {
						// Force the Action Scheduler schema first (AS creates it lazily): the
						// resume enqueue and the recurring schedules below need its tables.
						if ( class_exists( '\\ActionScheduler_StoreSchema' ) ) {
							try {
								$as_schema = new \ActionScheduler_StoreSchema();
								$as_schema->register_tables( true );

								if ( class_exists( '\\ActionScheduler_LoggerSchema' ) ) {
									$as_log_schema = new \ActionScheduler_LoggerSchema();
									$as_log_schema->register_tables( true );
								}
							} catch ( \Throwable $e ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
								error_log( '[PerfLocale] network-activate AS schema init failed for site ' . (int) $site_id . ': ' . $e->getMessage() );
							}
						}

						try {
							// Non-fatal mode: the default wp_die()s (not a Throwable) at the
							// first missing table, so later sites would go untried.
							if ( ! \PerfLocale\Activator::activate( false ) ) {
								$perflocale_failed_sites[] = $site_id;
							}
						} catch ( \Throwable $e ) {
							// One site's failure must not abort the sweep for the rest.
							$perflocale_failed_sites[] = $site_id;
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
							error_log( '[PerfLocale] network-activate failed for site ' . (int) $site_id . ': ' . $e->getMessage() );
						}

						// Schedule per-blog recurring tasks so never-visited subsites get their
						// crons; only while no site has failed, as a failure refuses activation below.
						if ( [] === $perflocale_failed_sites
						&& class_exists( 'PerfLocale\\Bootstrap' )
						&& method_exists( 'PerfLocale\\Bootstrap', 'ensure_recurring_schedules' )
						) {
							try {
									\PerfLocale\Bootstrap::ensure_recurring_schedules();
							} catch ( \Throwable $e ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
								error_log( '[PerfLocale] network-activate ensure_recurring_schedules failed for site ' . (int) $site_id . ': ' . $e->getMessage() );
							}
						}
					} finally {
						restore_current_blog();
					}
				}

				$offset         += $chunk;
				$got_full_chunk = ( count( $sites ) === $chunk );
			} while ( $got_full_chunk );

			// Refuse the activation when any site failed: wp_die() here stops core recording the
			// plugin as network-active, and nothing repairs a site without tables or a language.
			// Each step is idempotent, so activating again re-runs every site.
			if ( [] !== $perflocale_failed_sites ) {
				$perflocale_shown = implode( ', ', array_slice( $perflocale_failed_sites, 0, 20 ) )
					. ( count( $perflocale_failed_sites ) > 20 ? ', …' : '' );

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the refused-activation path.
				error_log(
					sprintf(
						'[PerfLocale] network activation refused: %d of %d site(s) could not be set up (site IDs: %s); the lines above name the cause for each.',
						count( $perflocale_failed_sites ),
						$perflocale_tried_sites,
						$perflocale_shown
					)
				);

				wp_die(
					esc_html(
						sprintf(
							/* translators: 1: number of sites that could not be set up, 2: number of sites tried, 3: comma-separated site IDs */
							__( 'PerfLocale could not be set up on %1$d of %2$d sites (site IDs: %3$s), so it has not been activated. The PHP error log names the cause for each site. Fix it, then activate the plugin again.', 'perflocale' ),
							count( $perflocale_failed_sites ),
							$perflocale_tried_sites,
							$perflocale_shown
						)
					),
					esc_html__( 'Plugin Activation Error', 'perflocale' ),
					[ 'back_link' => true ]
				);
			}
		} else {
			\PerfLocale\Activator::activate();
		}
	}

	/**
	 * Multisite: set up a new site of a network where PerfLocale is network-active (wp_initialize_site).
	 *
	 * @param \WP_Site $new_site The new site.
	 * @return void
	 */
	public static function initialize_site( \WP_Site $new_site ): void {
		// May fire from CLI or REST, where wp-admin/includes/plugin.php is not loaded.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( PERFLOCALE_FILE ) ) ) {
			return;
		}

		switch_to_blog( $new_site->blog_id );

		try {
			// Skip a subsite with a conflicting multilingual plugin; mirrors Bootstrap::init().
			$conflict_constants = [
				'ICL_SITEPRESS_VERSION',
				'POLYLANG_VERSION',
				'TRP_PLUGIN_VERSION',
			];

			foreach ( $conflict_constants as $const ) {
				if ( defined( $const ) ) {
					return;
				}
			}

			// Belt-and-suspenders: check the subsite's own active_plugins option.
			$active_plugins = (array) get_option( 'active_plugins', [] );
			$conflict_files = [
				'sitepress-multilingual-cms/sitepress.php',
				'polylang/polylang.php',
				'polylang-pro/polylang.php',
				'translatepress-multilingual/index.php',
			];

			foreach ( $conflict_files as $conflict_file ) {
				if ( in_array( $conflict_file, $active_plugins, true ) ) {
					return;
				}
			}

			// AS creates per-blog tables lazily, after this hook: force its schema first so the
			// resume enqueue and the as_schedule_* calls below find their tables.
			if ( class_exists( '\\ActionScheduler_StoreSchema' ) ) {
				try {
					$as_schema = new \ActionScheduler_StoreSchema();
					$as_schema->register_tables( true );

					if ( class_exists( '\\ActionScheduler_LoggerSchema' ) ) {
						$as_log_schema = new \ActionScheduler_LoggerSchema();
						$as_log_schema->register_tables( true );
					}
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
					error_log( '[PerfLocale] wp_initialize_site AS schema init failed: ' . $e->getMessage() );
				}
			}

			// Non-fatal mode: the default wp_die()s on a dbDelta failure, which would abort the
			// network admin's site-creation request and leave a half-provisioned subsite.
			try {
				\PerfLocale\Activator::activate( false );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
				error_log( '[PerfLocale] wp_initialize_site activator failed: ' . $e->getMessage() );
			}

			// Schedule per-blog recurring tasks: Bootstrap's lazy admin_init path never runs
			// on a subsite nobody visits in wp-admin.
			if ( class_exists( 'PerfLocale\\Bootstrap' )
			&& method_exists( 'PerfLocale\\Bootstrap', 'ensure_recurring_schedules' )
			) {
				try {
					\PerfLocale\Bootstrap::ensure_recurring_schedules();
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
					error_log( '[PerfLocale] wp_initialize_site ensure_recurring_schedules failed: ' . $e->getMessage() );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Run activation tasks.
	 *
	 * @param bool $fatal_on_missing_table True on the activation hook: wp_die() at a missing table. False for
	 *                                     site creation and network loops: log and return, so one site does not
	 *                                     end the request.
	 * @return bool False for a missing table (non-fatal mode) or a site still without a language; in the second
	 *              case every later step has still run, so the caller decides what to refuse.
	 */
	public static function activate( bool $fatal_on_missing_table = true ): bool {
		// Suppress any output from dbDelta() during table creation.
		ob_start();
		Schema::create_tables();
		ob_end_clean();

		// dbDelta() can fail silently for some tables: every Schema table is checked.
		global $wpdb;

		$required = [
			'languages',
			'translation_groups',
			'translation_links',
			'strings',
			'string_translations',
			'slug_translations',
			'content_hashes',
			'jobs',
			'migration_source_map',
		];

		foreach ( $required as $table_name ) {
			$full_name = Schema::table( $table_name );
			$exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( ! $exists ) {
				if ( $fatal_on_missing_table ) {
					wp_die(
						sprintf(
							/* translators: %s: table name */
							esc_html__( 'PerfLocale activation failed: could not create the "%s" database table. Please check your database permissions and try again.', 'perflocale' ),
							esc_html( $full_name )
						),
						esc_html__( 'Plugin Activation Error', 'perflocale' ),
						[ 'back_link' => true ]
					);
				}

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path; operator needs to see this.
				error_log(
					sprintf(
						'[PerfLocale] Activator: could not create table "%s" on blog %d',
						$full_name,
						function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0
					)
				);
				return false;
			}
		}

		// Both versions autoloaded: the Migrator reads them on every request.
		// The DB version is stamped only on a fresh install: a stored one describes the schema on disk, and overwriting
		// it after a file replacement or a preserve-mode delete would make the Migrator skip every migration.
		if ( ! get_option( 'perflocale_db_version' ) ) {
			update_option( 'perflocale_db_version', PERFLOCALE_DB_VERSION, true );
		} else {
			self::set_autoload( 'perflocale_db_version', 'yes' );
		}

		update_option( 'perflocale_version', PERFLOCALE_VERSION, true );

		// Seed English when no language exists.
		$seeded = self::seed_default_language();

		// Caps now, not on the next admin_init: the first redirect after activation would 403 otherwise.
		TranslatorRole::install_caps();

		// WooCommerce's Shop Manager gets perflocale_translate once per site;
		// after a deactivation this puts back what remove_roles() took off.
		TranslatorRole::install_shop_manager_caps();

		// Put the site title and tagline on the Strings screen straight away.
		// maybe_update() cannot do it for a FRESH install because activation
		// stamps perflocale_version, so its version gate never opens.
		\PerfLocale\Frontend\OptionStrings::register_source_strings();

		// Options read on most requests are autoloaded; webhooks are not, since they hold HMAC secrets.
		// add_option() seeds missing rows, with no insert-only guarantee under a concurrent insert;
		// set_autoload() repairs a row an earlier activation created with the wrong flag.
		$settings = new Settings();
		add_option( 'perflocale_settings', $settings->get_defaults(), '', true );
		add_option( 'perflocale_webhooks', [], '', false );
		add_option( 'perflocale_addon_failures', [], '', true );
		// Seeded, or get_disabled() misses the options table on every request without a persistent cache.
		add_option( 'perflocale_disabled_addons', [], '', true );
		add_option( 'perflocale_flush_rules', 1, '', true );
		// Retire the stored variation maps and copy maps a persistent object
		// cache may still hold from an earlier activation (InventorySync).
		delete_transient( \PerfLocale\WooCommerce\InventorySync::STORED_MAP_EPOCH );
		delete_transient( \PerfLocale\WooCommerce\InventorySync::COPY_MAP_EPOCH );
		self::retire_cache_generations();
		self::set_autoload( 'perflocale_settings', 'yes' );
		self::set_autoload( 'perflocale_webhooks', 'no' );
		self::set_autoload( 'perflocale_addon_failures', 'yes' );
		self::set_autoload( 'perflocale_disabled_addons', 'yes' );
		self::set_autoload( 'perflocale_flush_rules', 'yes' );
		// Version guards, read on every request or admin request.
		self::set_autoload( 'perflocale_db_version', 'yes' );
		self::set_autoload( 'perflocale_version', 'yes' );
		self::set_autoload( 'perflocale_caps_version', 'yes' );

		// Deactivation unschedules every worker but keeps the job rows: one Resumer event re-enqueues queued and running jobs.
		// It lands on WP-Cron, since Action Scheduler is not initialised during activation.
		if ( ! \PerfLocale\Background\BackgroundEvents::is_scheduled( \PerfLocale\Background\Resumer::HOOK ) ) {
			\PerfLocale\Background\BackgroundEvents::enqueue( \PerfLocale\Background\Resumer::HOOK );
		}

		// The protected `uploads/perflocale/` parent; its subdirectories are created and hardened on first use.
		try {
			\PerfLocale\Helper::ensure_uploads_base_dir();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
			error_log( '[PerfLocale] ensure_uploads_base_dir failed: ' . $e->getMessage() );
		}

		/** @hook perflocale/activated Fires after the plugin is activated. */
		do_action( 'perflocale/activated', PERFLOCALE_VERSION );

		return $seeded;
	}

	/**
	 * Insert the default English language if the languages table is empty.
	 *
	 * @return bool True when the table holds at least one language afterwards.
	 */
	private static function seed_default_language(): bool {
		global $wpdb;

		$table = Schema::table( 'languages' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation-time seed check; caching a count we are about to invalidate would be wrong.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $count > 0 ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'slug'           => 'en',
				'locale'         => 'en_US',
				'name'           => 'English',
				'native_name'    => 'English',
				'flag'           => 'us',
				'is_default'     => 1,
				'is_active'      => 1,
				'sort_order'     => 0,
				'text_direction' => 'ltr',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
		);

		// A site without a language breaks routing and every translation feature: report why.
		if ( false === $inserted ) {
			$reason = (string) $wpdb->last_error;

			// Judged by the table, not the INSERT: a concurrent activation may have seeded it first (unique slug).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation only, failure path only.
			$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

			if ( $after > 0 ) {
				return true;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'PerfLocale Activator: failed to seed default English language on blog %d. wpdb error: %s', (int) get_current_blog_id(), $reason ) );
			// Not during core's activation sandbox scrape or AJAX.
			if ( function_exists( 'wp_die' ) && defined( 'WP_SANDBOX_SCRAPING' ) === false && ! wp_doing_ajax() ) {
				$message = sprintf(
					/* translators: %s: detailed database error message from wpdb. */
					__( 'PerfLocale could not seed the default English language. Database error: %s', 'perflocale' ),
					esc_html( $reason )
				);
				// A notice, not wp_die(): dying here would leave the plugin half-activated.
				add_action(
					'admin_notices',
					static function () use ( $message ): void {
						printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
					}
				);
			}

			return false;
		}

		return true;
	}

	/**
	 * Move every L2 generation past an earlier install's: a missing one starts at the clock, never at 0.
	 *
	 * @return void
	 */
	private static function retire_cache_generations(): void {
		foreach ( \PerfLocale\Cache\CacheManager::GROUPS as $group ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( $group );
		}
	}

	/**
	 * Flip the autoload flag of an existing option without touching its value; no-op for a missing option.
	 *
	 * @param string $name Option name.
	 * @param string $autoload Either 'yes' or 'no'.
	 * @return void
	 */
	private static function set_autoload( string $name, string $autoload ): void {
		$autoload = in_array( $autoload, [ 'yes', 'no' ], true ) ? $autoload : 'no';

		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( [ $name => $autoload ] );
			return;
		}

		// Fallback for WP < 6.4.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->options,
			[ 'autoload' => $autoload ],
			[ 'option_name' => $name ],
			[ '%s' ],
			[ '%s' ]
		);

		wp_cache_delete( 'alloptions', 'options' );
	}
}
