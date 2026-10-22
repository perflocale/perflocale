<?php
/**
 * The SQLite string-group repair as a background job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Background\Dispatcher;
use PerfLocale\Background\OneTimeDispatch;
use PerfLocale\Strings\SqliteGroupRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs {@see SqliteGroupRepair} on a SQLite site, slice by slice, in the job
 * runner (WP-Cron or Action Scheduler), never on a visitor's request.
 *
 * Queued from wp-admin (admin_init, by a user who can manage options; the
 * request only claims the state below and queues the job); `wp perflocale
 * db-migrate` runs the same slices in the foreground. Each execution runs
 * repair slices for up to SLICE_SECONDS and then continues in a new
 * execution. Where the repair stands lives in the repair's own progress
 * option, so a job that stopped anywhere resumes from the last committed
 * slice. A slice that failed (rolled back) fails the execution with that
 * reason; the worker retries it later, and a job that ran out of retries is
 * queued again from the next admin page view.
 *
 * The blog's dispatch state is the option `perflocale_sqlite_string_groups_repair_job`,
 * in the forms of {@see OneTimeDispatch}: absent until the first dispatch,
 * `claim:<unix time>` while a request queues the job, `queued:<job id>:<unix
 * time>` while the job is queued or running, `failed:<unix time>` or
 * `canceled:<unix time>` once its job failed or was canceled, and
 * `done:<unix time>` once the repair finished (written by the repair with
 * its done option).
 */
final class StringGroupRepairJob extends AbstractJob {

	/**
	 * The blog's dispatch state.
	 */
	public const OPTION = 'perflocale_sqlite_string_groups_repair_job';

	/**
	 * Capability of a user who may queue the repair.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Seconds of repair slices per execution.
	 */
	private const SLICE_SECONDS = 10;

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'sqlite_group_repair';
	}

	/** {@inheritDoc} */
	public function get_required_capability(): string {
		return self::CAPABILITY;
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		return 0;
	}

	/** {@inheritDoc} */
	public function supports_continuation(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Result: the repair's counts so far (`groups_created`, `links_moved`,
	 * `links_written`, `groups_deleted`, `links_left`).
	 *
	 * @param array<string, mixed> $args     Worker args (`__perflocale_slice` from the worker).
	 * @param callable             $progress Progress reporter.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When a slice failed (it was rolled back), or another process holds the repair.
	 */
	public function execute( array $args, callable $progress ): array {
		$sliced = is_array( $args['__perflocale_slice'] ?? null );
		$until  = microtime( true ) + self::SLICE_SECONDS;

		do {
			$outcome = SqliteGroupRepair::run_slice();

			if ( 'busy' === $outcome['status'] ) {
				throw new \RuntimeException( esc_html__( 'The SQLite string-group repair is running in another process (for example WP-CLI). This job tries again later.', 'perflocale' ) );
			}

			$done = $outcome['groups_created'] + $outcome['links_moved'] + $outcome['links_written'] + $outcome['groups_deleted'];

			$progress( $done, max( 1, $done ), [ 'percent' => $outcome['percent'] ] );

			$result = $outcome;
			unset( $result['status'], $result['percent'] );

			if ( 'more' !== $outcome['status'] ) {
				return $result;
			}
		} while ( ! $sliced || microtime( true ) < $until );

		$result['__perflocale_continue'] = true;

		return $result;
	}

	/**
	 * `admin_init` on a SQLite site: the first wp-admin request of a user
	 * who can manage options queues the repair, once, and again when the job
	 * it recorded failed, was canceled or is gone. Decided from the
	 * autoloaded state option alone; a queued job's row is read at most once
	 * per {@see OneTimeDispatch::CHECK_EVERY} seconds. The request claims the
	 * state in one statement and queues the job; it reads no repair data.
	 *
	 * @return void
	 */
	public static function maybe_dispatch(): void {
		if ( ! defined( 'DB_ENGINE' ) || 'sqlite' !== constant( 'DB_ENGINE' ) || wp_doing_ajax() ) {
			return;
		}

		$state = get_option( self::OPTION, '' );

		// Done, the state of every admin request once the repair ran, stops
		// here without loading the dispatch class.
		if ( ! is_string( $state ) || str_starts_with( $state, 'done:' ) || 'skip' === OneTimeDispatch::decide( $state, time(), 0, true ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// The repair is done (WP-CLI finished it, or the state was lost):
		// record done here so that later requests stop at the state.
		if ( false !== get_option( SqliteGroupRepair::DONE_OPTION, false ) ) {
			OneTimeDispatch::claim( self::OPTION, $state, 'done:' . time() );

			return;
		}

		OneTimeDispatch::maybe_dispatch(
			self::OPTION,
			$state,
			self::CAPABILITY,
			0,
			true,
			static fn( string $claim ) => self::dispatch( $claim )
		);
	}

	/**
	 * Queue the repair job and record it as queued over the claim of the
	 * request that won it ({@see OneTimeDispatch::publish()}).
	 *
	 * @param string $claim The claim this request holds.
	 * @return void
	 */
	private static function dispatch( string $claim ): void {
		$queued = Dispatcher::enqueue( new self(), [] );

		// Not queued (a veto filter, a refused dispatch): no job id, and the
		// next admin request tries again.
		OneTimeDispatch::publish( self::OPTION, $claim, isset( $queued['job_id'] ) && is_string( $queued['job_id'] ) ? $queued['job_id'] : '' );
	}
}
