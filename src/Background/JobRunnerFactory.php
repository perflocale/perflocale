<?php
/**
 * Factory that picks the right job runner at call time.
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
 * Decides which {@see JobRunnerInterface} implementation to use.
 *
 * Selection order:
 *   1. `perflocale/jobs/runner` filter — power-user override; returns
 *      an instance to use unconditionally (great for tests).
 *   2. The `background_engine` setting — when set to 'force_wp_cron',
 *      always returns {@see WpCronRunner} even if AS is available.
 *   3. Action Scheduler detection — if AS is loaded and at ≥ 3.4, return
 *      {@see ActionSchedulerRunner}. Older AS versions had race conditions
 *      around multisite claims that we'd rather avoid.
 *   4. Fallback: {@see WpCronRunner}.
 *
 * Detection is done at CALL TIME, not at bootstrap, so AS has a chance to
 * load (AS hooks `init` priority 1; PerfLocale's Bootstrap runs slightly
 * earlier in some flows).
 */
final class JobRunnerFactory {

	/**
	 * Minimum Action Scheduler version we trust. Earlier versions had bugs
	 * around per-blog claims on multisite — bumping to a safe baseline costs
	 * us nothing because every WooCommerce-shipped AS for the last ~2 years
	 * is above 3.4.
	 */
	public const MIN_AS_VERSION = '3.4';

	/**
	 * Memoized resolved runner, keyed by blog ID — `background_engine` is a
	 * per-blog setting, so a flat memo would carry blog A's choice into
	 * blog B after switch_to_blog(). Memoization keeps dispatcher + worker
	 * + cancel within one request on the same runner choice: without it,
	 * AS becoming available mid-request (e.g. action_scheduler_init fires
	 * between Dispatcher::enqueue and the next call to pick) would leave a
	 * JobState row whose `engine` field disagrees with the runner
	 * subsequent cancel() / is_scheduled() probes use. Cleared via
	 * {@see reset_memo()} when the engine setting changes mid-request.
	 *
	 * @var array<int, JobRunnerInterface>
	 */
	private static array $memoized = [];

	/**
	 * Forget every blog's memoized runner.
	 *
	 * Called when the engine setting changes mid-request (the settings-save
	 * flow re-schedules recurring events immediately) so the re-schedule
	 * picks under the NEW setting instead of a runner memoized earlier in
	 * the request. Clearing all blogs over-invalidates at worst — one
	 * extra resolution per blog.
	 *
	 * @return void
	 */
	public static function reset_memo(): void {
		self::$memoized = [];
	}

	/**
	 * Resolve the runner for the current request. Memoized so every site
	 * within one request (dispatcher, worker, cancel, resume) sees the
	 * same engine choice.
	 *
	 * @return JobRunnerInterface
	 */
	public static function pick(): JobRunnerInterface {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( isset( self::$memoized[ $blog ] ) ) {
			return self::$memoized[ $blog ];
		}

		/**
		 * Override the runner instance globally — for tests, custom
		 * deployments, or third-party schedulers (e.g. an external queue).
		 * Memoized once chosen so dispatcher + worker + cancel within a
		 * single request all hit the same instance.
		 *
		 * @hook perflocale/jobs/runner
		 * @param JobRunnerInterface|null $override Default null = use the chain below.
		 */
		$override = apply_filters( 'perflocale/jobs/runner', null );

		if ( $override instanceof JobRunnerInterface ) {
			self::$memoized[ $blog ] = $override;
			return self::$memoized[ $blog ];
		}

		// A non-null, non-JobRunnerInterface return is a definite developer
		// mistake — the filter is silently ignored otherwise, which is the
		// exact failure mode _doing_it_wrong is for. Null (the default) means
		// "no override" and is the happy path; skip the nudge there.
		if ( $override !== null ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/jobs/runner", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/jobs/runner returned %s — must be an instance of \\PerfLocale\\Background\\JobRunnerInterface (or null to skip override). Falling back to the default runner chain.', 'perflocale' ),
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
	 * Resolve the runner for the engine a job was ACTUALLY scheduled under
	 * (JobState['engine']), not the current setting. An operator can flip
	 * `background_engine` after a job is queued; cancel()/is_scheduled()
	 * probes via pick() would then target the wrong store and silently
	 * miss the live event. {@see Resumer} keeps JobState['engine'] accurate.
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

		// Unknown/empty engine, or the stored AS engine is no longer
		// available — fall back to the current best pick so we still target
		// a live store.
		return self::pick();
	}

	/**
	 * Whether Action Scheduler is loaded AND new enough for us to trust.
	 *
	 * Probes `function_exists('as_enqueue_async_action')` because AS's
	 * own bootstrap defines that function once it's fully wired — any
	 * earlier check (class_exists, defined-constant) can return true
	 * before AS is actually ready to receive enqueues.
	 *
	 * Also gates on `action_scheduler_init` having fired: AS ≥ 3.1.6
	 * raises a `_doing_it_wrong` notice when data-store-dependent
	 * functions (`as_next_scheduled_action`, `as_schedule_recurring_action`,
	 * etc.) are called before that hook, because its data store isn't
	 * ready. Pre-init callers fall through to the WP-Cron path.
	 *
	 * @return bool
	 */
	public static function action_scheduler_available(): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		// Best-effort version check. The constant is defined by AS ≥ 3.0,
		// so missing-constant means a very old or custom bundling — be
		// conservative and trust it (modern bundlings will have it).
		if ( defined( '\\ActionScheduler::PLUGIN_VERSION' ) ) {
			if ( ! version_compare( \ActionScheduler::PLUGIN_VERSION, self::MIN_AS_VERSION, '>=' ) ) {
				return false;
			}
		}

		// AS's data store is initialised on the `action_scheduler_init`
		// action. Calling any data-store-dependent function before this
		// fires triggers a `_doing_it_wrong` notice in AS ≥ 3.1.6. Use
		// `did_action()` so callers in admin/init/later are unaffected.
		if ( did_action( 'action_scheduler_init' ) === 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Read the `background_engine` setting without exploding if the
	 * settings service hasn't been registered yet (very early bootstrap).
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
