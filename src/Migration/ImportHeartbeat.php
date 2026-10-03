<?php
/**
 * The import lock's heartbeat, for an importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an importer keep the {@see MigrationLock} while it works and report
 * how far it is.
 *
 * The importer calls beat() after each batch, with the stage it is in and
 * the stage's items done and in total. The callable set through
 * set_heartbeat() refreshes the lock at most once per 30 seconds, so a call
 * per item costs a time() read. A background job's reporter receives the
 * progress detail ({@see MigrationRunner::import()}); a stage total is
 * counted only when such a reporter is attached.
 *
 * The using class lists its stages, in the order it runs them, in a
 * `PROGRESS_STAGES` constant (list of stage keys).
 */
trait ImportHeartbeat {

	/**
	 * Called after each batch, to keep the import lock; null outside a lock.
	 *
	 * @var callable|null
	 */
	private $heartbeat = null;

	/**
	 * Whether the heartbeat passes the progress detail on to a reporter.
	 *
	 * @var bool
	 */
	private bool $has_reporter = false;

	/**
	 * Set the callable run after each batch (the import lock's heartbeat).
	 *
	 * It receives the progress detail as its only argument, or nothing when
	 * the call only keeps the lock.
	 *
	 * @param callable $heartbeat    Heartbeat.
	 * @param bool     $has_reporter Whether the heartbeat passes the progress detail on to a reporter (a background job's progress), so a stage total is worth a count query.
	 * @return void
	 */
	public function set_heartbeat( callable $heartbeat, bool $has_reporter = false ): void {
		$this->heartbeat    = $heartbeat;
		$this->has_reporter = $has_reporter;
	}

	/**
	 * Run the heartbeat, if one is set.
	 *
	 * @param string $stage Stage key from PROGRESS_STAGES, or '' to only keep the lock.
	 * @param int    $done  Items of the stage done.
	 * @param int    $total Items in the stage; 0 while not known.
	 * @return void
	 */
	private function beat( string $stage = '', int $done = 0, int $total = 0 ): void {
		if ( $this->heartbeat === null ) {
			return;
		}

		if ( $stage === '' ) {
			( $this->heartbeat )();
			return;
		}

		( $this->heartbeat )( self::progress_detail( $stage, $done, $total ) );
	}

	/**
	 * Whether a reporter receives the progress, so a stage total is worth a
	 * count query.
	 *
	 * @return bool
	 */
	private function reports_progress(): bool {
		return $this->heartbeat !== null && $this->has_reporter;
	}

	/**
	 * The progress detail of a stage: its key, items done and in total, and
	 * the import's overall percent.
	 *
	 * Every stage is an equal share of the percent; within a stage the share
	 * fills as `done / total`. A stage whose total is not known yet counts
	 * as just started.
	 *
	 * @param string $stage Stage key.
	 * @param int    $done  Items done.
	 * @param int    $total Items in the stage; 0 while not known.
	 * @return array{stage: string, processed: int, total: int, percent: int}
	 */
	private static function progress_detail( string $stage, int $done, int $total ): array {
		$stages = self::PROGRESS_STAGES;
		$index  = array_search( $stage, $stages, true );
		$done   = max( 0, $done );
		$total  = $total > 0 ? max( $total, $done ) : 0;
		$share  = $total > 0 ? $done / $total : 0.0;

		$percent = $index === false
			? 0
			: (int) floor( ( (int) $index + $share ) * 100 / count( $stages ) );

		return [
			'stage'     => $stage,
			'processed' => $done,
			'total'     => $total,
			'percent'   => min( 99, $percent ),
		];
	}
}
