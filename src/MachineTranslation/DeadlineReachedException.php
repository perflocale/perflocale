<?php
/**
 * Thrown when a provider call or wait does not fit before a slice's deadline.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Not a provider failure: the bulk job stops the slice and sends the unit again in the next one.
 */
final class DeadlineReachedException extends \RuntimeException {

	/**
	 * Unix time to continue at; 0 for at once.
	 *
	 * @var int
	 */
	private int $retry_at;

	/**
	 * A provider's rate-limit wait: pacing, not counted as a stop at the unit.
	 *
	 * @var bool
	 */
	private bool $paced;

	/**
	 * A stop that continues at once or at $retry_at.
	 *
	 * @param int  $retry_at Unix time to continue at; 0 for at once.
	 * @param bool $paced    The provider asked to wait (Retry-After).
	 */
	public function __construct( int $retry_at = 0, bool $paced = false ) {
		parent::__construct( esc_html__( 'The translation provider did not answer in time. The job continues in its next run.', 'perflocale' ) );

		$this->retry_at = $retry_at;
		$this->paced    = $paced;
	}

	/**
	 * Whether the stop is a provider's rate-limit wait.
	 *
	 * @return bool
	 */
	public function paced(): bool {
		return $this->paced;
	}

	/**
	 * Unix time to continue at; 0 for at once.
	 *
	 * @return int
	 */
	public function retry_at(): int {
		return $this->retry_at;
	}
}
