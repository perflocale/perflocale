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
 * Registers each job type's worker hook, so every job runs through WorkerRunner::run().
 *
 * That pipeline locks, re-checks the capability as the dispatching user, runs execute() as that user, retries with
 * backoff and releases the locks, also after a fatal error. Factories give each run a fresh job instance.
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

		// add_action() de-dupes by (hook, callable), so registering a type twice binds it once.
		add_action(
			Dispatcher::worker_hook( $type ),
			[ self::class, 'run' ],
			10,
			2
		);
	}

	/**
	 * Hook callback; both runners pass `(string $job_id, array $args)`.
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
	 * Shutdown function, a no-op unless PHP stopped inside execute() (fatal error or exit). Releases only the locks this
	 * process stamped; also run from release_before_error_page(). A SIGKILLed process never gets here: its locks expire.
	 *
	 * @return void
	 */
	public static function release_on_shutdown(): void {
		WorkerRunner::release_on_shutdown();
	}

	/**
	 * Run the shutdown guard before WordPress's fatal error page (`wp_php_error_args`).
	 *
	 * The page's wp_die() can end the process first (WP-CLI). The arguments are returned unchanged.
	 *
	 * @param mixed $args wp_die() arguments of the error page.
	 * @return mixed
	 */
	public static function release_before_error_page( mixed $args = [] ): mixed {
		return WorkerRunner::release_before_error_page( $args );
	}

	/**
	 * The registered factory for a job type, or null; lets REST callers build the job without running the worker hook.
	 *
	 * @param string $type Job type slug.
	 * @return callable|null
	 */
	public static function factory_for_type( string $type ): ?callable {
		$factory = self::$factories[ $type ] ?? null;
		return is_callable( $factory ) ? $factory : null;
	}

	/**
	 * Whether the queue is paused via the `background_paused` setting.
	 *
	 * Fails open (not paused) when Settings has not booted, so early bootstrap cannot deadlock the queue.
	 *
	 * @return bool
	 */
	public static function queue_is_paused(): bool {
		// Re-read at most once per `pause_refresh_window` seconds, so an operator pause lands within a tick without a read on
		// every progress callback. Keyed by blog: one PHP-FPM child serves workers for several blogs.
		static $cache = [];

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$window  = (int) apply_filters( 'perflocale/jobs/pause_refresh_window', 10 );
		$now     = time();

		if ( isset( $cache[ $blog_id ] )
			&& ( $now - $cache[ $blog_id ]['refreshed'] ) < max( 1, $window )
		) {
			return (bool) $cache[ $blog_id ]['value'];
		}

		// Bounded for long-running workers that touch many blogs; oldest entries drop first.
		if ( count( $cache ) > 64 ) {
			$cache = array_slice( $cache, -32, null, true );
		}

		// Read the row directly: forcing get_option() to re-read means deleting `alloptions`, which on a persistent object
		// cache makes every web request reload it. Core's `pre_option_*` / `option_*` filters are applied under their exact
		// core names, so operator pause filters still apply. See docs-ai/11-code-rationale.md, "WorkerRegistry: pause read".
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
					// Parity with get_option(): core's exact `option_{$option}` hook name.
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

		// Option missing or filtered to a non-array: fall back to the in-request Settings value.
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

			// Fail open so jobs keep running, but log it: the container should always be up here. The per-blog cache
			// limits this to one line per refresh window.
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
	 * Add the multisite blog-id sentinel so the worker can switch_to_blog() before reading its job row; no-op for 0.
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
