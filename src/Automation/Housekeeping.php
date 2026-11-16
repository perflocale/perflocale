<?php
/**
 * Automation housekeeping (daily).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Automation;

use PerfLocale\Database\Repository\PendingChangeRepository;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily automation housekeeping per blog (in `perflocale_jobs_gc`): settles stopped string writes, closes
 * orphaned pending changes, prunes decided changes past retention except each translation's newest applied one.
 * Every statement handles at most BATCH rows within TIME_BUDGET; a run that stopped on time runs again hourly, RERUNS a day.
 *
 * @phpstan-type Cursor array{at: string, id: int}
 * @phpstan-type State array{after: Cursor|null, applied: Cursor|false|null, declined: array<string, Cursor|null>, orphans: int, resync: array{stage: string, states: int, sources: int, retention: Cursor|null}, behind: bool, day: string, reruns: int}
 */
final class Housekeeping {

	/** Rows per statement: small, as a DELETE's per-row cost grows with its size on a small InnoDB buffer pool. */
	private const BATCH = 250;

	/** Seconds per run: the event runs other clean-up first and WP-Cron may have a short time limit. */
	private const TIME_BUDGET = 10;

	/** Seconds of the budget the Content ReSync steps use first, so a pending-changes backlog cannot starve them. */
	private const RESYNC_SHARE = 3;

	/** Applied rows per gone-target window, pending rows per orphan window: each check reads one index range, never every row. */
	private const WINDOW = 2000;

	/** Seconds into the run after which the orphan pass stops for the next run, so the retention steps keep their share. */
	private const ORPHAN_SHARE = 6;

	/** Extra runs per UTC day (every hourly watchdog tick) while the last run stopped on time. */
	private const RERUNS = 23;

	/** `{after, applied, declined, orphans, resync, behind, day, reruns}`: where the gone-target check, the superseded-applied and declined prunes, the orphan pass and the ReSync steps resume; the same-day re-runs. */
	public const STATE_OPTION = 'perflocale_automation_housekeeping';

	/**
	 * `perflocale_jobs_gc` listener: run the housekeeping on the event's blog under its recurring-run guard.
	 *
	 * @param mixed $blog_id The event's blog id; 0 for the current blog.
	 * @return void
	 */
	public static function on_jobs_gc( mixed $blog_id = 0 ): void {
		\PerfLocale\Background\JobState::run_recurring_for_blog( Scalar::int( $blog_id ), [ self::class, 'run' ] );
	}

	/**
	 * `perflocale_jobs_watchdog` listener: run again on the event's blog when the last run stopped on time.
	 *
	 * @param mixed $blog_id The event's blog id; 0 for the current blog.
	 * @return void
	 */
	public static function on_watchdog( mixed $blog_id = 0 ): void {
		\PerfLocale\Background\JobState::run_recurring_for_blog( Scalar::int( $blog_id ), [ self::class, 'run_if_behind' ] );
	}

	/**
	 * Run the housekeeping on the current blog when the last run stopped on time, at most RERUNS a UTC day.
	 *
	 * @return void
	 */
	public static function run_if_behind(): void {
		$state = self::state();
		$today = gmdate( 'Y-m-d' );
		$runs  = $today === $state['day'] ? $state['reruns'] : 0;

		if ( ! $state['behind'] || $runs >= self::RERUNS || ! ChangeService::available() ) {
			return;
		}

		// Counted before the run: a run that dies still counts.
		$state['day']    = $today;
		$state['reruns'] = $runs + 1;
		update_option( self::STATE_OPTION, $state, false );

		self::run();
	}

	/**
	 * Run the housekeeping on the current blog.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! ChangeService::available() ) {
			return;
		}

		$repo    = new PendingChangeRepository();
		$started = microtime( true );
		$in_time = static fn (): bool => microtime( true ) - $started < self::TIME_BUDGET;
		$saved   = self::state();
		$state   = $saved;

		/**
		 * Filters how many days decided automation changes are kept (each translation's newest applied change,
		 * and a person's Reject of a still-outdated string, always stay).
		 *
		 * @hook perflocale/automation/retention_days
		 * @since 1.0.8
		 *
		 * @param int $days Days (default 90). Below 1 keeps every change.
		 */
		$days = (int) apply_filters( 'perflocale/automation/retention_days', 90 );

		$resync_done = true;

		if ( \PerfLocale\Resync\Store::has_tables() ) {
			$resync_in_time = static fn (): bool => microtime( true ) - $started < self::RESYNC_SHARE;

			$resync_done = \PerfLocale\Resync\SyncRecorder::housekeep( $resync_in_time, self::BATCH, $days, $state['resync'] );
		}

		$finished = $in_time();

		if ( $finished ) {
			ChangeService::settle_string_intents( self::BATCH );
		}

		$finished = self::close_orphans( $repo, static fn (): bool => microtime( true ) - $started < self::ORPHAN_SHARE, $state ) && $finished;

		if ( $days >= 1 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

			foreach ( PendingChangeRepository::PRUNED_STATUSES as $status ) {
				$declined = in_array( $status, PendingChangeRepository::PERSON_DECLINED, true );

				// A statement that found fewer than BATCH rows finished its step; a declined step ends with its cursor.
				// A failed statement ends the step unfinished, so the run stays behind.
				do {
					if ( ! $in_time() ) {
						$finished = false;
						break 2;
					}

					if ( ! $declined ) {
						$pruned = $repo->prune_status( $status, $cutoff, self::BATCH );

						if ( $pruned < 0 ) {
							$finished = false;
							continue 2;
						}

						$more = $pruned >= self::BATCH;
						continue;
					}

					$after = $state['declined'][ $status ] ?? null;

					if ( $repo->prune_declined( $status, $cutoff, self::BATCH, $after ) < 0 ) {
						$finished = false;
						continue 2;
					}

					$state['declined'][ $status ] = $after;
					$more                         = null !== $after;
				} while ( $more );
			}

			if ( $finished && false !== $state['applied'] ) {
				$finished = self::prune_superseded_applied( $repo, $cutoff, $in_time, $state );
			}

			// The superseded-applied pass starts again only after this pass, so neither starves the other.
			if ( $finished && self::prune_gone_applied( $repo, $cutoff, $in_time, $state ) ) {
				$state['applied'] = null;
			} else {
				$finished = false;
			}
		}

		$state['behind'] = ! ( $finished && $resync_done );
		self::save( $state, $saved );

		ChangeService::end_batch();
	}

	/**
	 * The superseded-applied prune from the saved cursor, while in time; at the pass end `applied` becomes false.
	 *
	 * @param PendingChangeRepository $repo    Repository.
	 * @param string                  $cutoff  UTC datetime.
	 * @param callable                $in_time Whether another statement may run.
	 * @param array<string, mixed>    $state   Run state; `applied` moves.
	 * @phpstan-param State $state
	 * @return bool Whether the pass ended.
	 */
	private static function prune_superseded_applied( PendingChangeRepository $repo, string $cutoff, callable $in_time, array &$state ): bool {
		$after = false === $state['applied'] ? null : $state['applied'];

		while ( $in_time() ) {
			if ( $repo->prune_superseded_applied( $cutoff, self::BATCH, $after ) < 0 ) {
				return false;
			}

			$state['applied'] = $after ?? false;

			if ( null === $after ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The gone-target check in windows of WINDOW applied rows from the saved cursor, while in time.
	 *
	 * @param PendingChangeRepository $repo    Repository.
	 * @param string                  $cutoff  UTC datetime.
	 * @param callable                $in_time Whether another statement may run.
	 * @param array<string, mixed>    $state   Run state; `after` moves.
	 * @phpstan-param State $state
	 * @return bool Whether the pass reached its last window (the cursor then restarts).
	 */
	private static function prune_gone_applied( PendingChangeRepository $repo, string $cutoff, callable $in_time, array &$state ): bool {
		while ( $in_time() ) {
			$until = $repo->applied_window_end( $cutoff, $state['after'], self::WINDOW );

			if ( false === $until ) {
				return false;
			}

			$pruned = $repo->prune_gone_applied( $cutoff, self::BATCH, $state['after'], $until );

			// A failed statement keeps the cursor; a full batch may leave more in the window: the same window again.
			if ( $pruned < 0 ) {
				return false;
			}

			if ( $pruned >= self::BATCH ) {
				continue;
			}

			$state['after'] = $until;

			if ( null === $until ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The orphan pass in windows of WINDOW pending rows from the saved cursor, while in time.
	 *
	 * @param PendingChangeRepository $repo    Repository.
	 * @param callable                $in_time Whether another statement may run.
	 * @param array<string, mixed>    $state   Run state; `orphans` moves.
	 * @phpstan-param State $state
	 * @return bool Whether the pass reached its last window (the cursor then restarts).
	 */
	private static function close_orphans( PendingChangeRepository $repo, callable $in_time, array &$state ): bool {
		while ( $in_time() ) {
			$until = $repo->pending_window_end( $state['orphans'], self::WINDOW );

			if ( false === $until ) {
				return false;
			}

			$closed = $repo->close_pending_orphans( self::BATCH, $state['orphans'], $until );

			// A failed statement keeps the cursor; the run is behind, so it runs again.
			if ( false === $closed ) {
				return false;
			}

			// A window that had orphans is checked again: a kind may have more than one batch.
			if ( $closed > 0 ) {
				ChangeService::mark_badge_dirty();
				continue;
			}

			$state['orphans'] = $until ?? 0;

			if ( null === $until ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The stored run state, or the idle one.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return State
	 */
	private static function state(): array {
		$saved   = get_option( self::STATE_OPTION, null );
		$saved   = is_array( $saved ) ? $saved : [];
		$resync  = is_array( $saved['resync'] ?? null ) ? $saved['resync'] : [];
		$applied = $saved['applied'] ?? null;

		$declined = is_array( $saved['declined'] ?? null ) ? $saved['declined'] : [];

		return [
			'after'    => self::cursor( $saved['after'] ?? null ),
			'applied'  => false === $applied ? false : self::cursor( $applied ),
			'declined' => array_map( static fn( string $status ): ?array => self::cursor( $declined[ $status ] ?? null ), array_combine( PendingChangeRepository::PERSON_DECLINED, PendingChangeRepository::PERSON_DECLINED ) ),
			'orphans'  => max( 0, Scalar::int( $saved['orphans'] ?? 0 ) ),
			'resync'   => [
				'stage'     => Scalar::str( $resync['stage'] ?? '' ),
				'states'    => max( 0, Scalar::int( $resync['states'] ?? 0 ) ),
				'sources'   => max( 0, Scalar::int( $resync['sources'] ?? 0 ) ),
				'retention' => self::cursor( $resync['retention'] ?? null ),
			],
			'behind'   => ! empty( $saved['behind'] ),
			'day'      => Scalar::str( $saved['day'] ?? '' ),
			'reruns'   => max( 0, Scalar::int( $saved['reruns'] ?? 0 ) ),
		];
	}

	/**
	 * A stored `{at, id}` cursor, or null.
	 *
	 * @param mixed $value Stored value.
	 * @return array{at: string, id: int}|null
	 */
	private static function cursor( mixed $value ): ?array {
		$at = is_array( $value ) ? Scalar::str( $value['at'] ?? '' ) : '';
		$id = is_array( $value ) ? Scalar::int( $value['id'] ?? 0 ) : 0;

		return 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $at ) && $id > 0 ? [
			'at' => $at,
			'id' => $id,
		] : null;
	}

	/**
	 * Store a changed run state; an idle one (no cursor, not behind) leaves no option.
	 *
	 * @param array<string, mixed> $state New state.
	 * @param array<string, mixed> $saved State read at the start.
	 * @phpstan-param State $state
	 * @phpstan-param State $saved
	 * @return void
	 */
	private static function save( array $state, array $saved ): void {
		if ( $state === $saved ) {
			return;
		}

		if ( null === $state['after'] && null === $state['applied'] && [] === array_filter( $state['declined'] ) && 0 === $state['orphans'] && \PerfLocale\Resync\SyncRecorder::IDLE_CURSORS === $state['resync'] && ! $state['behind'] ) {
			delete_option( self::STATE_OPTION );

			return;
		}

		update_option( self::STATE_OPTION, $state, false );
	}
}
