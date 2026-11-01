<?php
/**
 * Bootstrap's maintenance callbacks.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurring-schedule upkeep, cron and GC bodies and the bulk MT dispatch gates of Bootstrap, which registers and
 * forwards, so a page view does not compile them.
 *
 * @internal
 */
final class BootstrapMaintenance {

	/**
	 * Option holding the last active engine, to detect Action Scheduler appearing or disappearing,
	 * which `maybe_remigrate_engine()` cannot see. Autoloaded.
	 *
	 * @var string
	 */
	private const ACTIVE_ENGINE_OPTION = 'perflocale_active_engine';

	/**
	 * Timestamp of the last full recurring-schedule check (autoloaded; written at most every 6 hours).
	 */
	private const SCHEDULES_VERIFIED_OPTION = 'perflocale_schedules_verified_at';

	/**
	 * Throttled ensure_recurring_schedules() for admin_init: full run on engine drift, else every 6 hours.
	 *
	 * Settings saves (update_option_perflocale_settings:20), WP-CLI, network activation and
	 * wp_initialize_site call the unthrottled method.
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules_throttled(): void {
		// The full check costs several ms under AS (one table query per is_scheduled()), so it
		// runs at most every 6 hours; the cheap drift check stays per request.
		$current_engine = Background\JobRunnerFactory::pick()->get_engine_name();
		$stored_engine  = get_option( self::ACTIVE_ENGINE_OPTION, $current_engine );
		$drifted        = ! is_string( $stored_engine ) || $stored_engine !== $current_engine;

		if ( ! $drifted ) {
			$last = get_option( self::SCHEDULES_VERIFIED_OPTION, 0 );

			if ( is_numeric( $last ) && time() - (int) $last < 6 * HOUR_IN_SECONDS ) {
				return;
			}
		}

		self::ensure_recurring_schedules();
		update_option( self::SCHEDULES_VERIFIED_OPTION, time(), true );
	}

	/**
	 * Ensure the recurring background events are scheduled under the active engine.
	 *
	 * On engine drift (Action Scheduler gone or back) it first unschedules them from both
	 * engines, or stranded AS actions would fire alongside the new WP-Cron ones. Unthrottled.
	 *
	 * @return void
	 */
	public static function ensure_recurring_schedules(): void {
		$current_engine = Background\JobRunnerFactory::pick()->get_engine_name();
		$stored_engine  = (string) get_option( self::ACTIVE_ENGINE_OPTION, $current_engine );

		if ( $stored_engine !== $current_engine ) {
			// Drift (also catches a `background_engine` change written without our hook): wipe
			// schedules in both engines so old ones do not fire alongside the new.
			$blog_arg_for_drift = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];
			Background\BackgroundEvents::unschedule_recurring( Bootstrap::LOCK_CLEANUP_HOOK );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc', $blog_arg_for_drift );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog', $blog_arg_for_drift );
			// Also the legacy no-arg form, so it does not fire alongside the per-blog one.
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc' );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog' );
			// MT usage GC is engine-bound too: clear the per-blog and no-arg forms.
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc', $blog_arg_for_drift );
			Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc' );
			// A pending one-shot resume under the old engine would be stranded: clear it.
			Background\BackgroundEvents::unschedule_all( Background\Resumer::HOOK );

			update_option( self::ACTIVE_ENGINE_OPTION, $current_engine, true );
		}

		if ( ! Background\BackgroundEvents::is_scheduled( Bootstrap::LOCK_CLEANUP_HOOK ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				Bootstrap::LOCK_CLEANUP_HOOK,
				time() + HOUR_IN_SECONDS,
				DAY_IN_SECONDS,
				'daily'
			);
		}

		// The blog id as args: the handler switches into that blog before it reads site state.
		// Action Scheduler's tables are per blog too.
		$blog_arg = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];

		if ( ! Background\BackgroundEvents::is_scheduled( 'perflocale_jobs_gc', $blog_arg ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				'perflocale_jobs_gc',
				time() + HOUR_IN_SECONDS,
				DAY_IN_SECONDS,
				'daily',
				$blog_arg
			);
		}

		if ( ! Background\BackgroundEvents::is_scheduled( 'perflocale_jobs_watchdog', $blog_arg ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				'perflocale_jobs_watchdog',
				time() + ( 10 * MINUTE_IN_SECONDS ),
				HOUR_IN_SECONDS,
				'hourly',
				$blog_arg
			);
		}

		// Weekly drop of perflocale_mt_usage_YYYY_MM options older than 13 months.
		if ( ! Background\BackgroundEvents::is_scheduled( 'perflocale_mt_usage_gc', $blog_arg ) ) {
			Background\BackgroundEvents::enqueue_recurring(
				'perflocale_mt_usage_gc',
				time() + ( 30 * MINUTE_IN_SECONDS ),
				WEEK_IN_SECONDS,
				'weekly',
				$blog_arg
			);
		}

		// First call after install or after the option was deleted: seed the baseline.
		if ( get_option( self::ACTIVE_ENGINE_OPTION ) === false ) {
			update_option( self::ACTIVE_ENGINE_OPTION, $current_engine, true );
		}
	}

	/**
	 * On a `background_engine` change, move every recurring event to the new engine.
	 *
	 * Also reschedules the exchange-rate sync via `ExchangeRateSync::maybe_reschedule()`.
	 *
	 * @param mixed $old_value Previous option payload (array or scalar).
	 * @param mixed $new_value New option payload (array or scalar).
	 * @return void
	 */
	public static function maybe_remigrate_engine( $old_value, $new_value ): void {
		$old_engine = is_array( $old_value ) && isset( $old_value['background_engine'] )
			? (string) $old_value['background_engine']
			: 'auto';
		$new_engine = is_array( $new_value ) && isset( $new_value['background_engine'] )
			? (string) $new_value['background_engine']
			: 'auto';

		if ( $old_engine === $new_engine ) {
			return;
		}

		// Forget the memoized runner and reset Settings' memo first: pick() may already have run in
		// this request, and either would re-stamp the events under the old engine.
		Background\JobRunnerFactory::reset_memo();

		$plugin = Plugin::get_instance();
		if ( $plugin->has( 'settings' ) ) {
			try {
				$settings = $plugin->get( 'settings' );
				if ( method_exists( $settings, 'reset_cache' ) ) {
					$settings->reset_cache();
				}
			} catch ( \Throwable $e ) {
				// Non-fatal: at worst the schedule lands under the old engine until the next request.
				unset( $e );
			}
		}

		// Cancel the recurring events on both back-ends and clear the active-engine option; the
		// re-enqueue below reads the saved setting (this hook runs after the write).
		delete_option( self::ACTIVE_ENGINE_OPTION );
		$blog_arg_for_remig = function_exists( 'get_current_blog_id' ) ? [ (int) get_current_blog_id() ] : [ 0 ];
		Background\BackgroundEvents::unschedule_recurring( Bootstrap::LOCK_CLEANUP_HOOK );
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_gc', $blog_arg_for_remig );
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_jobs_watchdog', $blog_arg_for_remig );
		// MT usage GC is engine-bound too (re-created below).
		Background\BackgroundEvents::unschedule_recurring( 'perflocale_mt_usage_gc', $blog_arg_for_remig );
		self::ensure_recurring_schedules();

		// Exchange-rate sync reschedules through the same helper, so it follows the new engine.
		if ( $plugin->has( 'exchange_rate_sync' ) ) {
			try {
				$ers = $plugin->get( 'exchange_rate_sync' );
				if ( method_exists( $ers, 'maybe_reschedule' ) ) {
					$ers->maybe_reschedule();
				}
			} catch ( \Throwable $e ) {
				// Best-effort: the next init reconciles state.
				unset( $e );
			}
		}
	}

	/**
	 * Delete an abandoned import temp file, only inside the temp dir (perflocale_cleanup_temp_import).
	 *
	 * @param string $file File path, or an XLIFF import's key.
	 * @return void
	 */
	public static function cleanup_temp_import( string $file ) : void {
		if ( $file === '' ) {
			return;
		}

		if ( Background\Jobs\XliffImportJob::is_key( $file ) ) {
			Background\Jobs\XliffImportJob::cleanup( $file );
			return;
		}

		// An unimported chunked upload frees its quota slot, whether or not its file remains.
		Admin\ImportUpload::release_path( $file );

		// Only files inside `uploads/perflocale/temp/`.
		$temp_dir = realpath( Helper::uploads_temp_dir() );
		$real     = realpath( $file );

		if ( $temp_dir === false || $real === false ) {
			return;
		}

		if ( ! str_starts_with( $real, rtrim( $temp_dir, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			return;
		}

		wp_delete_file( $real );
	}

	/**
	 * Weekly GC of the MT usage counters of a blog (perflocale_mt_usage_gc).
	 *
	 * @param mixed $blog_id Blog ID.
	 * @return void
	 */
	public static function mt_usage_gc( $blog_id = 0 ) : void {
		Background\JobState::run_recurring_for_blog(
			(int) $blog_id,
			static function (): void {
				MachineTranslation\AbstractProvider::gc_old_usage_counters();
			}
		);
	}

	/**
	 * Daily GC of a blog: jobs, empty groups, stale uploads, expired transients, stale strings (perflocale_jobs_gc).
	 * The row-deleting sweeps wait while SchemaWrites::ready() is false: they select rows by columns.
	 *
	 * @param mixed $blog_id Blog ID.
	 * @return void
	 */
	public static function jobs_gc( $blog_id = 0 ) : void {
		Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'gc' ] );

		// Also sweep empty translation_groups rows (orphans, and groups unlink_by_object_id()
		// kept when it could not count the links left), capped per tick.
		Background\JobState::run_recurring_for_blog(
			(int) $blog_id,
			static function (): void {
				if ( class_exists( '\\PerfLocale\\Database\\Repository\\TranslationGroupRepository' )
				&& \PerfLocale\Database\SchemaWrites::ready()
				) {
					\PerfLocale\Database\Repository\TranslationGroupRepository::gc_empty_groups();
				}
			}
		);

		// Safety-net sweep for abandoned temp/export files (7+ days), for lost cleanup events.
		Background\JobState::run_recurring_for_blog(
			(int) $blog_id,
			static function (): void {
				if ( method_exists( '\\PerfLocale\\Helper', 'gc_stale_upload_files' ) ) {
					\PerfLocale\Helper::gc_stale_upload_files();
				}
			}
		);

		// Expired perflocale_* transients: core's sweeps miss them when nothing reads them or
		// WP-Cron is off. One bounded DELETE joining value and timeout rows.
		Background\JobState::run_recurring_for_blog(
			(int) $blog_id,
			static function (): void {
				global $wpdb;
				$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
			 WHERE a.option_name LIKE %s
			   AND a.option_name NOT LIKE %s
			   AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			   AND b.option_value < %s",
						$wpdb->esc_like( '_transient_perflocale_' ) . '%',
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						(string) $now
					)
				);
			}
		);

		// Strings mark-and-sweep: delete rows unseen past the retention window (filter
		// perflocale/strings/stale_retention_days), cascading to string_translations.
		Background\JobState::run_recurring_for_blog(
			(int) $blog_id,
			static function (): void {
				if ( ! class_exists( '\\PerfLocale\\Database\\Repository\\StringRepository' )
					|| ! \PerfLocale\Database\SchemaWrites::ready() ) {
					return;
				}
				$cache = Plugin::get_instance()->has( 'cache' )
					? Plugin::get_instance()->get( 'cache' )
					: null;
				if ( $cache === null ) {
					return;
				}
				( new \PerfLocale\Database\Repository\StringRepository( $cache ) )->gc_stale_strings();
			}
		);

		// Orphan sweep for string_translations whose parent string was deleted elsewhere.
		Background\JobState::run_recurring_for_blog(
			(int) $blog_id,
			static function (): void {
				if ( ! class_exists( '\\PerfLocale\\Database\\Repository\\StringTranslationRepository' )
					|| ! \PerfLocale\Database\SchemaWrites::ready() ) {
					return;
				}
				$cache = Plugin::get_instance()->has( 'cache' )
					? Plugin::get_instance()->get( 'cache' )
					: null;
				if ( $cache === null ) {
					return;
				}
				( new \PerfLocale\Database\Repository\StringTranslationRepository( $cache ) )->gc_orphans();
			}
		);
	}

	/**
	 * Veto a bulk MT dispatch whose estimate exceeds the monthly cap (perflocale/jobs/should_dispatch).
	 *
	 * @param mixed                  $proceed Verdict so far.
	 * @param Background\AbstractJob $job     Job.
	 * @param array<string, mixed>   $args    Job args.
	 * @return mixed
	 */
	public static function mt_budget_gate( $proceed, Background\AbstractJob $job, array $args ) {
		if ( $proceed !== true ) {
			return $proceed;
		}

		$type = $job->get_type();
		if ( ! in_array( $type, [ 'bulk_translate', 'bulk_string_translate' ], true ) ) {
			return $proceed;
		}

		$settings = Plugin::get_instance()->get( 'settings' );
		if ( ! (bool) $settings->get( 'mt_enforce_cap_on_bulk', true ) ) {
			return $proceed;
		}

		$estimator = new MachineTranslation\CostEstimator( $settings );

		if ( $type === 'bulk_translate' ) {
			$estimate = $estimator->estimate_posts(
				(array) ( $args['source_ids'] ?? [] ),
				(array) ( $args['target_lang_ids'] ?? [] ),
				! empty( $args['include_meta'] )
			);
		} else {
			// With Overwrite on (skip_existing false), translated rows are sent again and counted.
			$string_job = $job instanceof Background\Jobs\BulkStringTranslateJob
				? $job
				: new Background\Jobs\BulkStringTranslateJob();
			$estimate   = $estimator->estimate_strings(
				$string_job->resolve_ids_for_estimate( $args ),
				(array) ( $args['target_lang_ids'] ?? [] ),
				! (bool) ( $args['skip_existing'] ?? true )
			);
		}

		// Deny only when there is work to send: an all-translated selection (chars 0) is a no-op.
		if ( ! empty( $estimate['would_exceed'] ) && (int) $estimate['chars'] > 0 ) {
			return sprintf(
				/* translators: 1: estimated characters, 2: remaining monthly characters, 3: monthly limit. */
				__( 'This translation needs about %1$s characters but only %2$s of the monthly limit (%3$s) remain. Raise the limit under Settings → Addons → Machine Translation, or narrow the selection.', 'perflocale' ),
				number_format_i18n( (int) $estimate['chars'] ),
				number_format_i18n( (int) $estimate['monthly_remaining'] ),
				number_format_i18n( (int) $estimate['monthly_limit'] )
			);
		}

		return $proceed;
	}

	/**
	 * Count a bulk MT dispatch against the user's hourly limit (perflocale/jobs/should_dispatch).
	 *
	 * @param mixed                  $proceed Verdict so far.
	 * @param Background\AbstractJob $job     Job.
	 * @param array<string, mixed>   $args    Job args.
	 * @return mixed
	 */
	public static function mt_rate_gate( $proceed, Background\AbstractJob $job, array $args ) {
		if ( $proceed !== true ) {
			return $proceed;
		}

		$type = $job->get_type();

		if ( ! in_array( $type, [ 'bulk_translate', 'bulk_string_translate', 'site_translate' ], true ) ) {
			return $proceed;
		}

		if ( $type === 'site_translate' && (int) ( $args['after_id'] ?? 0 ) > 0 ) {
			return $proceed;
		}

		$limited = Translation\MtRateLimiter::admit( get_current_user_id() );

		return $limited instanceof \WP_Error ? $limited->get_error_message() : $proceed;
	}
}
