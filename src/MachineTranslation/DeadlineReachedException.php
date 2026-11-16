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
	 * Why the whole job stops (its checkpoint kept for Retry); '' for a stop the next run continues.
	 *
	 * @var string
	 */
	private string $job_stop;

	/**
	 * Seconds the provider asked for (Retry-After); null when the wait is its own, 0 when it asked none.
	 *
	 * @var int|null
	 */
	private ?int $asked;

	/**
	 * A stop that continues at once or at $retry_at, or ends the job when $job_stop is given.
	 *
	 * @param int      $retry_at Unix time to continue at; 0 for at once.
	 * @param bool     $paced    A rate-limit wait (Retry-After, PerfLocale's backoff after a 429, or its breaker's cooldown).
	 * @param string   $job_stop Why the job stops, shown as is (escaped by the caller); '' for none.
	 * @param int|null $asked    Seconds the provider asked for, when they may differ from the wait; null: the same.
	 */
	public function __construct( int $retry_at = 0, bool $paced = false, string $job_stop = '', ?int $asked = null ) {
		$wait = max( 1, $retry_at - time() );

		parent::__construct(
			match ( true ) {
				'' !== $job_stop => $job_stop,
				$paced           => esc_html( self::wait_text( $wait, $asked ) ),
				default          => esc_html__( 'The translation provider did not answer in time. The job continues in its next run.', 'perflocale' ),
			}
		);

		$this->retry_at = $retry_at;
		$this->paced    = $paced;
		$this->job_stop = $job_stop;
		$this->asked    = $asked;
	}

	/**
	 * Whether the wait is the provider's own; one second less than asked is the clock, not a different wait.
	 *
	 * @param int      $wait  Seconds until the next request, at least 1.
	 * @param int|null $asked As the constructor's.
	 * @return bool
	 */
	public static function asked_kept( int $wait, ?int $asked ): bool {
		return null === $asked || ( $asked >= $wait && $asked <= $wait + 1 );
	}

	/**
	 * The rate-limit sentence (not escaped): the provider's wait when it is kept, else PerfLocale's own (its backoff,
	 * or the cap) with the provider's.
	 *
	 * @param int      $wait  Seconds until the next request.
	 * @param int|null $asked As the constructor's.
	 * @return string
	 */
	public static function wait_text( int $wait, ?int $asked ): string {
		$wait = max( 1, $wait );

		if ( self::asked_kept( $wait, $asked ) ) {
			$wait = $asked ?? $wait;

			/* translators: %d: seconds */
			return sprintf( _n( 'The provider asked to wait %d second before the next request (rate limit). Try again then.', 'The provider asked to wait %d seconds before the next request (rate limit). Try again then.', $wait, 'perflocale' ), $wait );
		}

		if ( $asked > 0 ) {
			/* translators: 1: seconds PerfLocale waits, 2: seconds the provider asked for */
			return sprintf( _n( 'Rate limit: waiting %1$d second before the next request (the provider asked for %2$d). Try again then.', 'Rate limit: waiting %1$d seconds before the next request (the provider asked for %2$d). Try again then.', $wait, 'perflocale' ), $wait, $asked );
		}

		/* translators: %d: seconds */
		return sprintf( _n( 'Rate limit: waiting %d second before the next request. Try again then.', 'Rate limit: waiting %d seconds before the next request. Try again then.', $wait, 'perflocale' ), $wait );
	}

	/**
	 * Why the whole job stops; '' for a stop the next run continues.
	 *
	 * @return string
	 */
	public function job_stop(): string {
		return $this->job_stop;
	}

	/**
	 * Seconds until the next request (paced), at least 1; 0 otherwise.
	 *
	 * @return int
	 */
	public function wait(): int {
		return $this->paced ? max( 1, $this->retry_at - time() ) : 0;
	}

	/**
	 * Seconds the provider asked for (null: the wait is its own; 0: it asked none).
	 *
	 * @return int|null
	 */
	public function asked(): ?int {
		return $this->asked;
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
