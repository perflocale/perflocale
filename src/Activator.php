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

			// Sites that could not be set up, how many were tried, and those with a root post at a language code.
			$perflocale_failed_sites = [];
			$perflocale_tried_sites  = 0;
			$perflocale_clash_sites  = [];

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
								error_log( '[PerfLocale] network-activate AS schema init failed for site ' . (int) $site_id . ': ' . Database\UpgradeRunner::describe( $e ) );
							}
						}

						try {
							// Non-fatal mode: the default wp_die()s (not a Throwable) at the
							// first missing table, so later sites would go untried.
							if ( ! \PerfLocale\Activator::activate( false ) ) {
								$perflocale_failed_sites[] = $site_id;
							} elseif ( self::slug_clash( true ) ) {
								$perflocale_clash_sites[] = (int) $site_id;
							}
						} catch ( \Throwable $e ) {
							// One site's failure must not abort the sweep for the rest.
							$perflocale_failed_sites[] = $site_id;
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
							error_log( '[PerfLocale] network-activate failed for site ' . (int) $site_id . ': ' . Database\UpgradeRunner::describe( $e ) );
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
								error_log( '[PerfLocale] network-activate ensure_recurring_schedules failed for site ' . (int) $site_id . ': ' . Database\UpgradeRunner::describe( $e ) );
							}
						}
					} finally {
						restore_current_blog();
					}
				}

				$offset         += $chunk;
				$got_full_chunk = ( count( $sites ) === $chunk );
			} while ( $got_full_chunk );

			// Sites that kept an earlier version upgrade on the network pass, not only when visited.
			if ( [] === $perflocale_failed_sites ) {
				\PerfLocale\Database\NetworkUpgrade::arm( true );

				if ( [] !== $perflocale_clash_sites ) {
					\PerfLocale\Admin\SlugClashNotice::carry( $perflocale_clash_sites );
				}
			}

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

			if ( self::slug_clash() ) {
				\PerfLocale\Admin\SlugClashNotice::carry( [ get_current_blog_id() ] );
			}

			// Deactivation cleared the recurring events: back now, also when no admin page loads (WP-CLI).
			try {
				\PerfLocale\Bootstrap::ensure_recurring_schedules();
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
				error_log( '[PerfLocale] activate ensure_recurring_schedules failed: ' . Database\UpgradeRunner::describe( $e ) );
			}
		}
	}

	/**
	 * Multisite: set up a new site of a network where PerfLocale is network-active (wp_initialize_site).
	 *
	 * @param \WP_Site $new_site The new site.
	 * @return void
	 */
	public static function initialize_site( \WP_Site $new_site ): void {
		// The new site's own network: is_plugin_active_for_network() reads the network of the request (multi-network).
		$active = get_network_option( (int) $new_site->network_id, 'active_sitewide_plugins', [] );

		if ( ! is_array( $active ) || ! isset( $active[ plugin_basename( PERFLOCALE_FILE ) ] ) ) {
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
					error_log( '[PerfLocale] wp_initialize_site AS schema init failed: ' . Database\UpgradeRunner::describe( $e ) );
				}
			}

			// Non-fatal mode: the default wp_die()s on a dbDelta failure, which would abort the
			// network admin's site-creation request and leave a half-provisioned subsite.
			try {
				$activated = \PerfLocale\Activator::activate( false );
				// The strings table was just created.
				\PerfLocale\Strings\StrIdentMigration::on_new_site();

				if ( $activated && self::slug_clash() ) {
					\PerfLocale\Admin\SlugClashNotice::carry( [ (int) $new_site->blog_id ] );
				}
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
				error_log( '[PerfLocale] wp_initialize_site activator failed: ' . Database\UpgradeRunner::describe( $e ) );
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
					error_log( '[PerfLocale] wp_initialize_site ensure_recurring_schedules failed: ' . Database\UpgradeRunner::describe( $e ) );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Whether a published root post of this site holds a language code (SlugClashNotice; WP-CLI warns at once).
	 *
	 * @param bool $name_site Start each WP-CLI line with the site's name (network pass).
	 * @return bool
	 */
	private static function slug_clash( bool $name_site = false ): bool {
		require_once __DIR__ . '/Admin/SlugClashNotice.php';

		return \PerfLocale\Admin\SlugClashNotice::check_site( $name_site );
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

		foreach ( Schema::REQUIRED_TABLES as $table_name ) {
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
		// A fresh schema the server did not fully build gets 0: the upgrade runner retries it with backoff and a notice.
		if ( ! get_option( 'perflocale_db_version' ) ) {
			update_option( 'perflocale_db_version', [] === \PerfLocale\Database\UpgradeRunner::missing_parts() ? PERFLOCALE_DB_VERSION : 0, true );
		} else {
			self::set_autoload( 'perflocale_db_version', 'yes' );
		}

		self::stamp_version( \PerfLocale\Util\Scalar::str( PERFLOCALE_VERSION ) );

		// What this version's uninstall deletes, so an older version's uninstall deletes it too (a failed write: the upgrade task retries).
		\PerfLocale\Database\SiteCleanup::record_owned_names();

		// String identity, before the source strings below: an empty site starts `done`; a populated one gets its runner.
		\PerfLocale\Strings\StrIdentMigration::on_activation();

		// Seed English when no language exists.
		$seeded = self::seed_default_language();

		self::restore_translator_role();

		// Caps now, not on the next admin_init: the first redirect after activation would 403 otherwise.
		TranslatorRole::install_caps();

		// WooCommerce's Shop Manager gets perflocale_translate once per site;
		// after a preserve-mode uninstall this puts back what it took off.
		TranslatorRole::install_shop_manager_caps();

		// Put the site title and tagline on the Strings screen straight away: a fresh install is stamped above, so
		// maybe_update() never runs for it.
		\PerfLocale\Frontend\OptionStrings::register_source_strings();

		// The GC protection of the option-text and label domains.
		\PerfLocale\Strings\Domains::ensure_site_options();

		// Options read on most requests are autoloaded (webhooks: seed_request_options()).
		// add_option() seeds missing rows, with no insert-only guarantee under a concurrent insert;
		// set_autoload() repairs a row an earlier activation created with the wrong flag.
		$settings = new Settings();
		add_option( 'perflocale_settings', $settings->get_defaults(), '', true );
		add_option( 'perflocale_addon_failures', [], '', true );
		// Seeded, or get_disabled() misses the options table on every request without a persistent cache.
		add_option( 'perflocale_disabled_addons', [], '', true );
		add_option( 'perflocale_flush_rules', 1, '', true );
		// Before any import: the scope edits' import fence locks this row.
		\PerfLocale\Admin\ImportLock::ensure_generation();
		// Retire the stored variation maps and copy maps a persistent object
		// cache may still hold from an earlier activation (InventorySync).
		delete_transient( \PerfLocale\WooCommerce\InventorySync::STORED_MAP_EPOCH );
		delete_transient( \PerfLocale\WooCommerce\InventorySync::COPY_MAP_EPOCH );
		self::retire_cache_generations();
		self::set_autoload( 'perflocale_settings', 'yes' );
		self::set_autoload( 'perflocale_addon_failures', 'yes' );
		self::set_autoload( 'perflocale_disabled_addons', 'yes' );
		self::seed_request_options();

		// Inputs may have changed while inactive, and deactivation compiles nothing: the next admin view compiles.
		if ( false !== get_option( 'perflocale_url_bases_map', false ) ) {
			\PerfLocale\Router\UrlBases\Lifecycle::mark_dirty( 'activated' );
		}

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
			error_log( '[PerfLocale] ensure_uploads_base_dir failed: ' . Database\UpgradeRunner::describe( $e ) );
		}

		/** @hook perflocale/activated Fires after the plugin is activated. */
		do_action( 'perflocale/activated', PERFLOCALE_VERSION );

		return $seeded;
	}

	/**
	 * The flag and map rows front-end requests read and the empty webhooks row REST requests read, present and autoloaded
	 * (a missing or non-autoloaded one is a query per request without a persistent object cache). Run on activation and
	 * by every plugin update.
	 *
	 * @return list<string> Rows still missing or in the wrong autoload state, read back from the table.
	 */
	public static function seed_request_options(): array {
		// Insert-only: add_option() can overwrite a row that `notoptions` hides, losing a rename's redirect or a '1'.
		// Literal names, for the export/import gate. '' = not known yet: the repository checks its table.
		\PerfLocale\Database\OptionWriter::compare_and_set( 'perflocale_slug_redirects', [], false, null, true );
		\PerfLocale\Database\OptionWriter::compare_and_set( 'perflocale_has_any_slugs', '', false, null, true );
		\PerfLocale\Database\OptionWriter::compare_and_set( 'perflocale_has_any_groups', '', false, null, true );
		self::set_autoload( 'perflocale_slug_redirects', 'yes' );
		self::set_autoload( 'perflocale_has_any_slugs', 'yes' );
		self::set_autoload( 'perflocale_has_any_groups', 'yes' );
		self::set_autoload( 'perflocale_flush_rules', 'yes' );
		// Deactivation turns these off; the URL bases runtime registers only from alloptions. A missing row stays missing.
		self::set_autoload( 'perflocale_url_bases_map', 'yes' );
		self::set_autoload( 'perflocale_url_bases_dirty', 'yes' );
		self::set_autoload( 'perflocale_url_bases_checked', 'yes' );
		self::seed_webhooks_row();

		return self::request_options_left();
	}

	/**
	 * The rows seed_request_options() writes that are not as it leaves them: a required row missing, a present row out
	 * of autoload, the webhooks row autoloaded while it holds a webhook (or not while empty). One read past the caches.
	 *
	 * @return list<string>
	 */
	private static function request_options_left(): array {
		global $wpdb;

		$required = [ 'perflocale_slug_redirects', 'perflocale_has_any_slugs', 'perflocale_has_any_groups', 'perflocale_webhooks' ];

		if ( ! $wpdb instanceof \wpdb ) {
			return $required;
		}

		$present  = [ 'perflocale_flush_rules', 'perflocale_url_bases_map', 'perflocale_url_bases_dirty', 'perflocale_url_bases_checked' ];
		$names    = array_merge( $required, $present );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The rows as stored, past every cache; the IN list holds only %s placeholders.
		$sql  = $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', array_merge( [ $wpdb->options ], $names ) );
		$rows = is_string( $sql ) ? $wpdb->get_results( $sql, 'ARRAY_A' ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return $required;
		}

		$on    = \PerfLocale\Database\OptionWriter::autoload_on_values();
		$found = [];
		$left  = [];

		foreach ( $rows as $row ) {
			if ( is_string( $row['option_name'] ?? null ) ) {
				$found[ $row['option_name'] ] = $row;
			}
		}

		foreach ( $names as $name ) {
			$row = $found[ $name ] ?? null;

			if ( null === $row ) {
				if ( in_array( $name, $required, true ) ) {
					$left[] = $name;
				}

				continue;
			}

			$value    = is_string( $row['option_value'] ?? null ) ? $row['option_value'] : '';
			$autoload = is_string( $row['autoload'] ?? null ) ? $row['autoload'] : '';
			$want     = 'perflocale_webhooks' !== $name || empty( maybe_unserialize( $value ) );

			if ( $want !== in_array( $autoload, $on, true ) ) {
				$left[] = $name;
			}
		}

		return $left;
	}

	/**
	 * Autoload perflocale_webhooks only while it holds no webhook: no secret in alloptions, and no query per REST
	 * request on a site without webhooks. The autoload change matches the row's bytes, so a concurrent write is kept.
	 *
	 * @return void
	 */
	public static function seed_webhooks_row(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row's bytes, past every cache.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'perflocale_webhooks' ) ?? '' );

		if ( ! is_string( $raw ) ) {
			\PerfLocale\Database\OptionWriter::compare_and_set( 'perflocale_webhooks', [], false, null, true );

			return;
		}

		$on = empty( maybe_unserialize( $raw ) );

		if ( function_exists( 'wp_determine_option_autoload_value' ) ) {
			$autoload = $on ? 'on' : 'off';
		} else {
			$autoload = $on ? 'yes' : 'no';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set of the autoload flag; the caches are dropped below.
		if ( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET autoload = %s WHERE option_name = %s AND autoload <> %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $autoload, 'perflocale_webhooks', $autoload, $raw ) ?? '' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'perflocale_webhooks', 'options' );
		}
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
			$error = (string) $wpdb->last_error;

			// Judged by the table, not the INSERT: a concurrent activation may have seeded it first (unique slug).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Activation only, failure path only.
			$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

			if ( $after > 0 ) {
				return true;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'PerfLocale Activator: failed to seed default English language on blog %d. wpdb error: %s', (int) get_current_blog_id(), Util\DbErrorCode::of( $error ) ) );
			// Not during core's activation sandbox scrape or AJAX.
			if ( function_exists( 'wp_die' ) && defined( 'WP_SANDBOX_SCRAPING' ) === false && ! wp_doing_ajax() ) {
				$message = sprintf(
					/* translators: %s: database error code, e.g. "duplicate_key". */
					__( 'PerfLocale could not seed the default English language. Database error: %s', 'perflocale' ),
					esc_html( Util\DbErrorCode::of( $error ) )
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
	 * Recreate the Translator role a keep-data uninstall removed, from its snapshot, once; install_caps() then applies
	 * only the plugin's own changes. The snapshot is never exported, so an import cannot widen the role.
	 *
	 * @return void
	 */
	private static function restore_translator_role(): void {
		$snapshot = get_option( TranslatorRole::ROLE_SNAPSHOT_OPTION, null );

		if ( null === $snapshot ) {
			return;
		}

		delete_option( TranslatorRole::ROLE_SNAPSHOT_OPTION );

		if ( null !== get_role( TranslatorRole::ROLE_SLUG ) || ! is_array( $snapshot ) || ! is_array( $snapshot['caps'] ?? null ) ) {
			return;
		}

		$caps = [];

		foreach ( $snapshot['caps'] as $cap => $grant ) {
			$cap = is_string( $cap ) ? sanitize_key( $cap ) : '';

			if ( '' !== $cap ) {
				$caps[ $cap ] = (bool) $grant;
			}
		}

		$name = is_string( $snapshot['name'] ?? null ) && '' !== $snapshot['name'] ? $snapshot['name'] : __( 'Translator', 'perflocale' );

		add_role( TranslatorRole::ROLE_SLUG, $name, $caps );
	}

	/**
	 * Stamp the plugin version on a fresh install or a downgrade; a lower one is left, so the next request's
	 * Migrator::maybe_update() runs the upgrade tasks after a deactivate, replace and activate update.
	 *
	 * @param string $code The code version (PERFLOCALE_VERSION).
	 * @return void
	 */
	private static function stamp_version( string $code ): void {
		$stored = get_option( 'perflocale_version' );

		if ( ! is_string( $stored ) || '' === $stored || version_compare( $stored, $code, '>' ) ) {
			update_option( 'perflocale_version', $code, true );
		}
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
