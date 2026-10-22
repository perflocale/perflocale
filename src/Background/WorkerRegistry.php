<?php
/**
 * Worker hook registry.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralises worker-hook registration so every Tier-2 job goes through
 * the same lock + cap-check + retry + state-transition pipeline.
 *
 * Usage:
 *
 *     WorkerRegistry::register('data_import', static fn() => new DataImportJob());
 *
 * After registration, the `perflocale_job_run_data_import` action is
 * wired to {@see WorkerRegistry::run()}, which:
 *
 *   1. Reads the job state from {@see JobState}.
 *   2. Acquires the {@see JobLock} (atomic INSERT IGNORE + CAS reclaim).
 *   3. Re-validates the dispatcher's capability via `user_can()` - guards
 *      against caps revoked between dispatch and execution.
 *   4. Sets the current-user context to the dispatcher's user_id so
 *      anything inside `execute()` that calls `current_user_can()` sees
 *      the dispatcher's identity, not user 0 (cron).
 *   5. Marks the job running, invokes `execute()`, marks
 *      complete/failed.
 *   6. On uncaught exception, schedules a retry with exponential backoff
 *      (up to `perflocale/jobs/max_attempts`, default 5).
 *   7. Releases the lock in a finally block.
 *   8. When PHP stops inside `execute()` (a fatal error, an exit), a
 *      shutdown function releases the locks and marks the job failed (just
 *      before WordPress's fatal error page, when it shows one).
 *
 * Jobs are constructed lazily via a factory closure so the worker has a
 * fresh instance per invocation (any cached state from one run cannot
 * leak into the next).
 */
final class WorkerRegistry {

	/**
	 * Type → factory map. Each factory returns a fresh AbstractJob instance.
	 * Written by register(); read by WorkerRunner and factory_for_type().
	 *
	 * @internal
	 * @var array<string, callable(): AbstractJob>
	 */
	public static array $factories = [];

	/**
	 * Register a job type's worker hook.
	 *
	 * @param string                  $type    Job type slug.
	 * @param callable(): AbstractJob $factory Closure returning a fresh AbstractJob instance.
	 * @return void
	 */
	public static function register( string $type, callable $factory ): void {
		self::$factories[ $type ] = $factory;

		// Bind ONCE per type - multiple register() calls for the same type
		// would otherwise stack listeners. WP's add_action de-dupes by
		// (hook, callable) identity, so passing [self::class, 'run']
		// repeatedly is a no-op after the first.
		add_action(
			Dispatcher::worker_hook( $type ),
			[ self::class, 'run' ],
			10,
			2
		);
	}

	/**
	 * Hook callback. Both runners ({@see WpCronRunner}, {@see ActionSchedulerRunner})
	 * pass args as `(string $job_id, array $args)` because that's the shape
	 * we serialise into the runner queue.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $args   Worker args.
	 * @return void
	 */
	public static function run( string $job_id, array $args ): void {
		WorkerRunner::run( $job_id, $args );
	}

	/**
	 * Release the locks of a job whose execute() never returned, and mark the
	 * job failed.
	 *
	 * Registered once per process as a shutdown function. A no-op unless PHP
	 * stopped inside execute(): a fatal error (memory, execution time) or an
	 * exit. Switches to the job's blog first. JobLock releases only the values
	 * this process stamped, so a lock another worker took over stays, and
	 * JobState::mark_failed() moves only queued or running rows. The stored
	 * error is the fatal error's message with paths redacted, or a generic
	 * message when there was no fatal error. A process killed without a
	 * shutdown (SIGKILL, the kernel's out-of-memory killer) never gets here;
	 * its locks expire. Also run from {@see release_before_error_page()},
	 * before WordPress's fatal error page; the record is cleared on the first
	 * run, so the second does nothing.
	 *
	 * @return void
	 */
	public static function release_on_shutdown(): void {
		WorkerRunner::release_on_shutdown();
	}

	/**
	 * `wp_php_error_args`: run the shutdown guard before WordPress's fatal
	 * error page calls wp_die(), which ends the process when its handler
	 * exits (WP-CLI). The arguments are returned unchanged, and a failure
	 * here does not stop the page.
	 *
	 * @param mixed $args wp_die() arguments of the error page.
	 * @return mixed
	 */
	public static function release_before_error_page( mixed $args = [] ): mixed {
		return WorkerRunner::release_before_error_page( $args );
	}

	/**
	 * Look up the registered factory callable for a job type, or null if
	 * none is registered. Used by REST callers (e.g. retry pre-flight cap
	 * check) that need to instantiate the AbstractJob without re-invoking
	 * the worker hook.
	 *
	 * @param string $type
	 * @return callable|null
	 */
	public static function factory_for_type( string $type ): ?callable {
		$factory = self::$factories[ $type ] ?? null;
		return is_callable( $factory ) ? $factory : null;
	}

	/**
	 * Whether the queue is paused via the `background_paused` setting.
	 *
	 * Returns false (not paused) if the Settings service hasn't booted yet
	 * - extremely early bootstrap shouldn't deadlock the queue against a
	 * missing container.
	 *
	 * @return bool
	 */
	public static function queue_is_paused(): bool {
		// Cache-bust + re-read so a worker tick picks up an operator pause
		// from a parallel request. Throttled to ~10s so a chatty progress
		// callback doesn't reload `alloptions` (every autoloaded option) on
		// every tick; the pause still lands within a tick of the next expiry.
		// Keyed by blog_id because `perflocale_settings` is per-blog and a
		// persistent PHP-FPM child serves workers for several blogs - without
		// the key, blog 3's worker could read blog 2's cached pause state.
		// Per-blog cache: each entry stores the resolved pause flag plus the timestamp when it was last refreshed from the option store.
		static $cache = [];

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$window  = (int) apply_filters( 'perflocale/jobs/pause_refresh_window', 10 );
		$now     = time();

		if ( isset( $cache[ $blog_id ] )
			&& ( $now - $cache[ $blog_id ]['refreshed'] ) < max( 1, $window )
		) {
			return (bool) $cache[ $blog_id ]['value'];
		}

		// Bound the in-process map so a long-running CLI / AS worker that
		// touches many blogs over time can't grow the static unboundedly.
		// 64 covers any realistic FPM child lifespan; oldest entries drop first.
		if ( count( $cache ) > 64 ) {
			$cache = array_slice( $cache, -32, null, true );
		}

		// Fresh-read the pause flag WITHOUT busting the shared `alloptions`
		// cache entry. `perflocale_settings` is autoloaded, so get_option()
		// serves it from that blob - forcing a re-read means deleting
		// `alloptions`, which on a persistent (Redis/Memcached) object cache is
		// fleet-wide: every concurrent web request then re-runs the full
		// autoloaded-options SELECT and re-sets the blob. At the ~10s cadence
		// this probe runs during a long job, that is a site-wide stampede. A
		// direct row read sees a parallel request's pause write immediately at
		// zero cache cost. The `pre_option_*` / `option_*` filter chain is
		// reproduced by hand so operators wiring conditional pause via filter
		// (deploy freeze, per-tenant override) still take effect in the tick.
		// WordPress core's own dynamic option-filter names ('pre_option_{$option}'
		// / 'option_{$option}' with our prefixed 'perflocale_settings' option):
		// reproduced verbatim so an operator filter registered on the core names
		// (the ones they'd use with get_option) fires in this direct-read path.
		// The core name is mandatory - a prefixed alias would not match.
		$stored = apply_filters( 'pre_option_perflocale_settings', false, 'perflocale_settings', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( false === $stored ) {
			$stored = null;
			global $wpdb;

			if ( $wpdb instanceof \wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$raw = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
						'perflocale_settings'
					)
				);

				if ( is_string( $raw ) ) {
					// Parity with the get_option() read this replaces: apply the
					// core `option_{$option}` filter to the resolved value (core's
					// exact hook name is required for operator filters to match).
					$stored = apply_filters( 'option_perflocale_settings', maybe_unserialize( $raw ), 'perflocale_settings' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				}
			}
		}

		if ( is_array( $stored ) ) {
			$value             = ! empty( $stored['background_paused'] );
			$cache[ $blog_id ] = [
				'value'     => $value,
				'refreshed' => $now,
			];
			return $value;
		}

		// Fallback to the in-request Settings cache when the option is
		// missing or has been replaced by a filter with a non-array
		// value. Reasonable because the worker callback is rarely
		// invoked outside a normal request.
		$fallback = false;
		try {
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'settings' ) ) {
				$settings = $plugin->get( 'settings' );
				if ( $settings instanceof \PerfLocale\Settings ) {
					$fallback = (bool) $settings->get( 'background_paused', false );
				}
			}
		} catch ( \Throwable $e ) {
			$fallback = false;

			// Settings container should be available whenever the worker
			// callback runs - a throw here means something fundamental is
			// wrong (container not booted, Settings table missing, etc.).
			// Default to "not paused" so jobs keep running, but emit a
			// diagnostic so the supervisor can spot the breakage instead
			// of silently running everything for hours against a possibly-
			// paused queue. Rate-limited by the per-blog cache so we log
			// at most once per refresh interval, not per job tick.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PerfLocale] queue_is_paused settings lookup threw for blog ' . (int) $blog_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for an unexpected settings/container fault path.
			}
		}

		$cache[ $blog_id ] = [
			'value'     => $fallback,
			'refreshed' => $now,
		];
		return $fallback;
	}

	/**
	 * Inject the multisite blog-id sentinel into worker args so the worker
	 * can switch_to_blog before reading per-blog JobState. No-op on single
	 * site (blog_id 0 → sentinel not added → worker runs as today).
	 *
	 * @param array<string, mixed> $args        Worker args (sentinel-free).
	 * @param int                  $target_blog Blog ID, or 0 for "no switch".
	 * @return array<string, mixed>
	 */
	public static function with_blog_sentinel( array $args, int $target_blog ): array {
		if ( $target_blog > 0 ) {
			$args['__perflocale_blog_id'] = $target_blog;
		}
		return $args;
	}
}
