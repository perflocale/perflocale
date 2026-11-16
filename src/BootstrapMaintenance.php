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
	 * One-time job state options, copied so a done job's class is not autoloaded on admin pages.
	 * BootstrapOneTimeJobGateTest keeps them equal to the jobs' constants.
	 */
	private const TEMPLATE_REFS_BACKFILL_OPTION = 'perflocale_template_refs_backfill';
	private const SQLITE_GROUP_REPAIR_OPTION    = 'perflocale_sqlite_string_groups_repair_job';

	/**
	 * `admin_init`: TemplateRefsBackfillJob::maybe_dispatch(), unless the state option says done.
	 *
	 * The done check reads the autoloaded option before any job class loads.
	 *
	 * @return void
	 */
	public static function maybe_dispatch_template_refs_backfill(): void {
		if ( self::one_time_job_done( self::TEMPLATE_REFS_BACKFILL_OPTION ) ) {
			return;
		}

		Background\Jobs\TemplateRefsBackfillJob::maybe_dispatch();
	}

	/**
	 * `admin_init` on SQLite: Background\Jobs\StringGroupRepairJob::maybe_dispatch(),
	 * after the same done check as maybe_dispatch_template_refs_backfill().
	 *
	 * @return void
	 */
	public static function maybe_dispatch_sqlite_group_repair(): void {
		if ( self::one_time_job_done( self::SQLITE_GROUP_REPAIR_OPTION ) ) {
			return;
		}

		Background\Jobs\StringGroupRepairJob::maybe_dispatch();
	}

	/**
	 * Whether a one-time job's state option says done (`done:<unix time>`).
	 *
	 * @param string $option State option.
	 * @return bool
	 */
	private static function one_time_job_done( string $option ): bool {
		$state = get_option( $option, '' );

		return is_string( $state ) && str_starts_with( $state, 'done:' );
	}

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
	 * Term URL slugs a creation could not write during an import (Translation\TermSlugRetry::HOOK).
	 *
	 * @param mixed $items Items.
	 * @param mixed $try   Attempt number.
	 * @return void
	 */
	public static function term_slug_retry( mixed $items, mixed $try = 1 ): void {
		require_once __DIR__ . '/Translation/TermSlugRetry.php';
		Translation\TermSlugRetry::run( $items, $try );
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

		// Read and delete under the import lock, so no Retry re-queues the import between them; busy: an hour later.
		if ( ! Background\Jobs\DataImportJob::lock_files( 1 ) ) {
			Background\Jobs\DataImportJob::keep_for_retry( $file );
			return;
		}

		try {
			// A queued or running import (a Retry) still reads it: checked again in an hour.
			if ( Background\Jobs\DataImportJob::holds( $file ) ) {
				Background\Jobs\DataImportJob::keep_for_retry( $file );
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
		} finally {
			Admin\ImportLock::release();
		}
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
	 * Daily GC of a blog: jobs, empty groups, stale uploads, expired transients, stale strings, orphan term slug history
	 * (perflocale_jobs_gc).
	 * The row-deleting sweeps wait while SchemaWrites::ready() is false: they select rows by columns.
	 *
	 * @param mixed $blog_id Blog ID.
	 * @return void
	 */
	public static function jobs_gc( $blog_id = 0 ) : void {
		Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'gc' ] );

		// Creation guid resets that could not finish (CreationMark state g): a bounded batch per day.
		Background\JobState::run_recurring_for_blog( is_numeric( $blog_id ) ? (int) $blog_id : 0, [ Translation\CreationMark::class, 'sweep' ] );

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
				// A return trip from 1.0.7.5 is found first; no sweep while rows may still be under the old
				// rule (a scan touched their new hash only); the runner is queued again when its chain stopped.
				\PerfLocale\Strings\StrIdentMigration::daily_check();

				if ( ! \PerfLocale\Strings\StrIdentMigration::done() ) {
					\PerfLocale\Strings\StrIdentMigration::maybe_dispatch();
					return;
				}
				( new \PerfLocale\Database\Repository\StringRepository( $cache ) )->gc_stale_strings();
			}
		);

		// Old URL slugs (term_h1..term_h5) of deleted terms that their delete hook missed, bounded per run.
		Background\JobState::run_recurring_for_blog(
			(int) $blog_id,
			static function (): void {
				if ( ! \PerfLocale\Database\SchemaWrites::ready() ) {
					return;
				}
				$cache = Plugin::get_instance()->has( 'cache' ) ? Plugin::get_instance()->get( 'cache' ) : null;
				\PerfLocale\Database\Repository\SlugTranslationWrites::gc_orphan_history( $cache instanceof Cache\CacheManager ? $cache : null );
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

	/**
	 * Delivery cron event: attempt 1 of a webhook delivery.
	 *
	 * @param string               $webhook_id Webhook ID.
	 * @param string               $event      Event name.
	 * @param array<string, mixed> $data       Payload.
	 * @param string               $timestamp  Event time.
	 * @return void
	 */
	public static function deliver_webhook( string $webhook_id, string $event, array $data, string $timestamp ): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/Webhook/Delivery.php';
		Webhook\Delivery::deliver( $webhook_id, $event, $data, $timestamp, 1 );
	}

	/**
	 * Drain cron event: the coalesced delivery queue, in bounded batches.
	 *
	 * @return void
	 */
	public static function drain_webhooks(): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/Webhook/Delivery.php';
		Webhook\Delivery::drain();
	}

	/**
	 * Retry cron event: attempt 2+ or a breaker-deferred re-delivery.
	 *
	 * @param string               $webhook_id        Webhook ID.
	 * @param string               $event             Event name.
	 * @param array<string, mixed> $data              Payload.
	 * @param string               $timestamp         Event time.
	 * @param int                  $attempt           Attempt number.
	 * @param string               $delivery_id       Delivery ID.
	 * @param int                  $breaker_deferrals Breaker deferrals so far.
	 * @return void
	 */
	public static function retry_webhook( string $webhook_id, string $event, array $data, string $timestamp, int $attempt, string $delivery_id = '', int $breaker_deferrals = 0 ): void {
		// Our files were replaced in this request: load nothing from them.
		if ( defined( 'PERFLOCALE_FILES_REPLACED' ) ) {
			return;
		}

		require_once __DIR__ . '/Webhook/Delivery.php';
		Webhook\Delivery::deliver( $webhook_id, $event, $data, $timestamp, $attempt, $delivery_id, $breaker_deferrals );
	}

	/**
	 * Daily reaper of expired lock rows (Bootstrap::LOCK_CLEANUP_HOOK); reap_expired()'s return is dropped.
	 *
	 * @return void
	 */
	public static function lock_cleanup(): void {
		Concurrency\Lock::reap_expired();
	}

	/**
	 * Hourly stuck-job watchdog of a blog (perflocale_jobs_watchdog).
	 *
	 * @param mixed $blog_id Blog ID.
	 * @return void
	 */
	public static function jobs_watchdog( $blog_id = 0 ): void {
		Background\JobState::run_recurring_for_blog( (int) $blog_id, [ Background\JobState::class, 'watchdog' ] );
	}

	/**
	 * One-shot resume after activation (perflocale_resume_jobs).
	 *
	 * @return void
	 */
	public static function resume_jobs(): void {
		Background\Resumer::resume();
	}

	/**
	 * First normal boot after a dormant one: retire the hreflang cache and drop the record.
	 *
	 * The group is retired at plugins_loaded:0. The record goes at the end of init, after Action
	 * Scheduler init (the settings listeners pick the engine), under the settings lock on a fresh
	 * read. See docs-ai/11-code-rationale.md, "Bootstrap: dormant boot".
	 *
	 * @param Plugin   $plugin   Plugin container.
	 * @param Settings $settings Settings service.
	 * @return void
	 */
	public static function resume_after_dormant_boot( Plugin $plugin, Settings $settings ): void {
		add_action(
			'plugins_loaded',
			static function () use ( $plugin, $settings ): void {
				$cache = $plugin->get( 'cache' );

				if ( $cache instanceof Cache\CacheManager ) {
					$cache->invalidate_group( 'perflocale_hreflang' );
				}

				add_action(
					'init',
					static function () use ( $settings ): void {
						Concurrency\Lock::with(
							'settings_update',
							10,
							static function () use ( $settings ): void {
								wp_cache_delete( 'perflocale_settings', 'options' );
								wp_cache_delete( 'alloptions', 'options' );

								$stored = get_option( 'perflocale_settings' );

								if ( is_array( $stored ) && array_key_exists( Bootstrap::DORMANT_KEY, $stored ) ) {
									unset( $stored[ Bootstrap::DORMANT_KEY ] );
									update_option( 'perflocale_settings', $stored );
								}

								$settings->reset_cache();
							}
						);
					},
					PHP_INT_MAX
				);
			},
			0
		);
	}
}
