<?php
/**
 * Picks the job runner at call time.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chooses the JobRunnerInterface: the perflocale/jobs/runner filter, then the `background_engine` setting
 * (force_wp_cron), then Action Scheduler when loaded and at least MIN_AS_VERSION, else WP-Cron.
 * Decided at call time, not at bootstrap, because Action Scheduler loads on `init` priority 1.
 */
final class JobRunnerFactory {

	/**
	 * Oldest Action Scheduler version trusted (earlier ones mishandle per-blog claims on multisite).
	 */
	public const MIN_AS_VERSION = '3.4';

	/**
	 * Resolved runner per blog id (`background_engine` is per blog), so dispatch, worker and cancel in one request use
	 * the same engine even if Action Scheduler becomes available mid-request.
	 *
	 * @var array<int, JobRunnerInterface>
	 */
	private static array $memoized = [];

	/**
	 * Forget every blog's memoized runner; called when the engine setting changes mid-request.
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$memoized = [];
	}

	/**
	 * The runner for the current request (memoized per blog).
	 *
	 * @return JobRunnerInterface
	 */
	public static function pick(): JobRunnerInterface {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( isset( self::$memoized[ $blog ] ) ) {
			return self::$memoized[ $blog ];
		}

		/**
		 * Override the job runner (tests, custom deployments, external queues); memoized once chosen.
		 *
		 * @hook perflocale/jobs/runner
		 * @param JobRunnerInterface|null $override Default null = use the chain below.
		 */
		$override = apply_filters( 'perflocale/jobs/runner', null );

		if ( $override instanceof JobRunnerInterface ) {
			self::$memoized[ $blog ] = $override;
			return self::$memoized[ $blog ];
		}

		// A non-null answer that is not a JobRunnerInterface is a developer mistake: say so instead of ignoring it.
		if ( $override !== null ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/jobs/runner", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/jobs/runner returned %s - must be an instance of \\PerfLocale\\Background\\JobRunnerInterface (or null to skip override). Falling back to the default runner chain.', 'perflocale' ),
						get_debug_type( $override )
					)
				),
				'1.0.0'
			);
		}

		$engine = self::read_engine_setting();

		if ( $engine === 'force_wp_cron' ) {
			self::$memoized[ $blog ] = new WpCronRunner();
			return self::$memoized[ $blog ];
		}

		if ( self::action_scheduler_available() ) {
			self::$memoized[ $blog ] = new ActionSchedulerRunner();
			return self::$memoized[ $blog ];
		}

		self::$memoized[ $blog ] = new WpCronRunner();
		return self::$memoized[ $blog ];
	}

	/**
	 * The runner for the engine a job was scheduled under (JobState['engine']), not the current setting, so a later
	 * setting change does not make cancel() or is_scheduled() miss the live event.
	 *
	 * @param string $engine Stored engine: 'wp_cron' | 'action_scheduler'.
	 * @return JobRunnerInterface
	 */
	public static function for_engine( string $engine ): JobRunnerInterface {
		if ( $engine === 'wp_cron' ) {
			return new WpCronRunner();
		}

		if ( $engine === 'action_scheduler' && self::action_scheduler_available() ) {
			return new ActionSchedulerRunner();
		}

		// Unknown engine, or Action Scheduler is gone: the current pick still targets a live store.
		return self::pick();
	}

	/**
	 * Whether Action Scheduler is loaded, new enough, and past `action_scheduler_init`.
	 *
	 * The function as_enqueue_async_action() exists only once AS is wired; before its init hook the data store is not
	 * ready and AS 3.1.6+ raises _doing_it_wrong, so earlier callers get WP-Cron.
	 *
	 * @return bool
	 */
	public static function action_scheduler_available(): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		// AS 3.0+ defines the constant; a bundling without it is trusted.
		if ( defined( '\\ActionScheduler::PLUGIN_VERSION' ) ) {
			if ( ! version_compare( \ActionScheduler::PLUGIN_VERSION, self::MIN_AS_VERSION, '>=' ) ) {
				return false;
			}
		}

		// The data store is ready only after `action_scheduler_init`.
		if ( did_action( 'action_scheduler_init' ) === 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * The `background_engine` setting, also before the settings service is registered.
	 *
	 * @return string 'auto' | 'force_wp_cron'
	 */
	private static function read_engine_setting(): string {
		try {
			$plugin = Plugin::get_instance();
		} catch ( \Throwable $e ) {
			return 'auto';
		}

		if ( ! $plugin->has( 'settings' ) ) {
			return 'auto';
		}

		$settings = $plugin->get( 'settings' );

		if ( ! $settings instanceof Settings ) {
			return 'auto';
		}

		$value = (string) $settings->get( 'background_engine', 'auto' );
		return in_array( $value, [ 'auto', 'force_wp_cron' ], true ) ? $value : 'auto';
	}
}
