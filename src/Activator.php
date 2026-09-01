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
 * Handles plugin activation tasks.
 *
 * Creates database tables, seeds default language, and sets initial options.
 * Idempotent - safe to run multiple times.
 */
final class Activator {

	/**
	 * Run activation tasks.
	 *
	 * @param bool $fatal_on_missing_table When true (activation-hook path),
	 *                                     wp_die() if a critical table is
	 *                                     missing — that's the contract the
	 *                                     activation flow expects so the user
	 *                                     sees the failure immediately.
	 *                                     When false (called from
	 *                                     `wp_initialize_site`, an upgrade
	 *                                     handler, etc.), log + return so a
	 *                                     dbDelta failure on a tiny subsite
	 *                                     doesn't kill a network-admin
	 *                                     site-creation request mid-flight.
	 * @return bool True on success; false on table-creation failure when
	 *              `$fatal_on_missing_table` is false.
	 */
	public static function activate( bool $fatal_on_missing_table = true ): bool {
		// Suppress any output from dbDelta() during table creation.
		ob_start();
		Schema::create_tables();
		ob_end_clean();

		// Verify critical tables were actually created. dbDelta() can fail
		// silently on hosts with restrictive DB permissions.
		global $wpdb;

		// Every table defined in Schema.php must exist after activation. A
		// host with restrictive DB perms could let dbDelta succeed on some
		// tables and silently fail on others — verifying ALL of them
		// surfaces partial-create failures at activation time instead of
		// the first feature read against a missing table.
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

		// Store the current DB and plugin versions. autoload=false because
		// both values are only read by Migrator::maybe_migrate() on
		// admin_init + rest_api_init paths, not by any frontend request —
		// no need to carry them in alloptions on every page load.
		// Autoloaded: both are tiny ints read by Migrator::maybe_migrate /
		// maybe_update on wp_loaded — i.e. EVERY request — so a non-autoloaded
		// row costs one options-table SELECT per request on sites without a
		// persistent object cache.
		// Only stamp the DB version on a FRESH install (no stored value). If a
		// value is already present it belongs to the schema actually on disk:
		// overwriting it with the new constant would make Migrator's
		// `$current_version >= $target_version` gate short-circuit and silently
		// skip every migration step. That happens on two ordinary flows —
		// deactivate → replace the plugin files → reactivate, and Delete plugin
		// in the default preserve mode (which keeps the tables and the option).
		// Harmless while DB_VERSION is 1, load-bearing from the first bump on.
		if ( ! get_option( 'perflocale_db_version' ) ) {
			update_option( 'perflocale_db_version', PERFLOCALE_DB_VERSION, true );
		} else {
			self::set_autoload( 'perflocale_db_version', 'yes' );
		}

		update_option( 'perflocale_version', PERFLOCALE_VERSION, true );

		// Seed default language (English) if no languages exist yet.
		self::seed_default_language();

		// Install the Translator role and grant the perflocale_* caps to
		// administrators + editors right now, while we have a request.
		// Without this, the caps would only land on the next admin_init —
		// and the very first /wp-admin redirect after activation would
		// 403 because the current user's $allcaps cache was built before
		// the role caps existed. The admin_init handler stays in place
		// as the upgrade / self-heal path; install_caps() is idempotent.
		TranslatorRole::install_caps();

		// Initialize default settings and hot-path options. The
		// `perflocale_flush_rules` flag is initialized in this same block via
		// add_option() + set_autoload(), so no separate update_option() call
		// is needed up here.
		// The ~4 KB settings blob is read on nearly every frontend request
		// (language routing, hreflang, URL rewriting) so carrying it inside
		// the bundled `alloptions` fetch is cheaper than a dedicated SELECT
		// per request. Same for addon failures (read on every admin boot)
		// and flush-rules flag (read on every init).
		//
		// Webhooks are NOT autoloaded - the option contains HMAC secrets,
		// and we don't want those sitting in `alloptions` on every page load.
		// The dispatcher reads the option once on plugins_loaded; the extra
		// SELECT is paid for by sites that have webhooks configured (rare).
		// Mirror of the autoload=false in WebhookController::register_webhook
		// and ::delete_webhook so fresh activations don't temporarily land
		// the option in alloptions before the first write flips it.
		//
		// add_option() is atomic - avoids TOCTOU race on multisite. The
		// set_autoload() follow-ups are idempotent no-ops unless a prior
		// activation on the same site created the row with the wrong flag.
		$settings = new Settings();
		add_option( 'perflocale_settings', $settings->get_defaults(), '', true );
		add_option( 'perflocale_webhooks', [], '', false );
		add_option( 'perflocale_addon_failures', [], '', true );
		// Seeded so the default nothing-disabled state doesn't cost a
		// guaranteed-miss options SELECT in AddonRegistry::get_disabled() on
		// every request of a non-object-cache install (notoptions is
		// per-request there).
		add_option( 'perflocale_disabled_addons', [], '', true );
		add_option( 'perflocale_flush_rules', 1, '', true );
		self::set_autoload( 'perflocale_settings', 'yes' );
		self::set_autoload( 'perflocale_webhooks', 'no' );
		self::set_autoload( 'perflocale_addon_failures', 'yes' );
		self::set_autoload( 'perflocale_disabled_addons', 'yes' );
		self::set_autoload( 'perflocale_flush_rules', 'yes' );
		// Version guards are read every request (Migrator on wp_loaded) /
		// every admin request (caps guard on admin_init) — heal pre-existing
		// rows that were created with autoload off.
		self::set_autoload( 'perflocale_db_version', 'yes' );
		self::set_autoload( 'perflocale_version', 'yes' );
		self::set_autoload( 'perflocale_caps_version', 'yes' );

		// Schedule a one-shot resume sweep on the very next cron tick.
		// {@see \PerfLocale\Deactivator::deactivate()} unschedules every
		// worker hook to keep WP-Cron clean while the plugin is off, but
		// the JobState option rows survive deactivation. Without this
		// reschedule, jobs that were `queued` or `running` at deactivate
		// time would have no worker event waiting to fire after
		// reactivation. {@see \PerfLocale\Background\Resumer::resume()}
		// is the cron handler (registered in Bootstrap) that walks the
		// index and re-enqueues each survivor.
		//
		// Scheduling at time() not time()+0 because some hosts trip on
		// "you scheduled a past event"; +0 is fine in core but the +0
		// idiom is explicit. The cron event is single-fire — no risk of
		// the resume running on a steady-state interval.
		//
		// Idempotent: if the hook is already scheduled (rapid double-
		// activate), BackgroundEvents::is_scheduled checks both engines
		// (AS + WP-Cron) before enqueueing again. Routed through
		// BackgroundEvents so AS picks it up when loaded (catches the
		// resume even if the next request happens during a cold-cache
		// window where WP-Cron wouldn't fire).
		//
		// Note on timing: `register_activation_hook` callbacks fire very
		// early in the request lifecycle - before `action_scheduler_init`
		// has run, so `JobRunnerFactory::action_scheduler_available()`
		// returns false here. The resume event lands on WP-Cron, never
		// AS, regardless of the engine setting. That's fine for a
		// single one-shot event; the next request loads the plugin
		// normally and Bootstrap's recurring schedules go through AS as
		// usual.
		if ( ! \PerfLocale\Background\BackgroundEvents::is_scheduled( \PerfLocale\Background\Resumer::HOOK ) ) {
			\PerfLocale\Background\BackgroundEvents::enqueue( \PerfLocale\Background\Resumer::HOOK );
		}

		// Make sure the plugin's `uploads/perflocale/` parent dir exists and
		// carries its `.htaccess` + `index.php` protection files. Subdirs
		// (temp / exports / translations) are created lazily by their
		// consumers and harden themselves on creation.
		try {
			\PerfLocale\Helper::ensure_uploads_base_dir();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on activation-failure path.
			error_log( '[PerfLocale] ensure_uploads_base_dir failed: ' . $e->getMessage() );
		}

		/** @hook perflocale/activated Fires after the plugin is activated. */
		do_action( 'perflocale/activated', PERFLOCALE_VERSION );

		return true;
	}

	/**
	 * Insert the default English language if the languages table is empty.
	 *
	 * @return void
	 */
	private static function seed_default_language(): void {
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
			return;
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

		// Surface the failure so the operator can see what broke during
		// activation. Without this, a failed insert (disk-full, charset
		// mismatch, constraint violation) leaves the site with zero
		// languages - which breaks routing, the language switcher,
		// hreflang emission, and every translation feature - and the
		// admin gets no signal at all about why.
		if ( false === $inserted ) {
			$reason = (string) $wpdb->last_error;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'PerfLocale Activator: failed to seed default English language. wpdb error: ' . $reason );
			// Re-raise as a notice via the WP activation-error mechanism
			// when called from `register_activation_hook`. The constant
			// is set by core during the activation request.
			if ( function_exists( 'wp_die' ) && defined( 'WP_SANDBOX_SCRAPING' ) === false && ! wp_doing_ajax() ) {
				$message = sprintf(
					/* translators: %s: detailed database error message from wpdb. */
					__( 'PerfLocale could not seed the default English language. Database error: %s', 'perflocale' ),
					esc_html( $reason )
				);
				// Use a notice rather than wp_die so a partial activation
				// can still complete and the admin can fix manually -
				// dying here would also kill the activation hook and
				// leave the plugin in an inconsistent half-activated
				// state.
				add_action(
					'admin_notices',
					static function () use ( $message ): void {
						printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
					}
				);
			}
		}
	}

	/**
	 * Flip the autoload flag on an existing option without touching its value.
	 *
	 * Uses WP 6.4+ `wp_set_option_autoload_values` when available, falls back
	 * to a direct wpdb UPDATE for older versions. Safe no-op when the option
	 * doesn't exist yet.
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
