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
	 * Compose the worker hook name from a job type slug (Dispatcher::worker_hook() forwards here).
	 *
	 * @param string $type Job type slug.
	 * @return string Hook name, e.g. 'perflocale_job_run_data_import'.
	 */
	public static function worker_hook( string $type ): string {
		return 'perflocale_job_run_' . $type;
	}

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
			self::worker_hook( $type ),
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
	 * @return bool
	 */
	public static function queue_is_paused(): bool {
		return QueuePause::queue_is_paused();
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
