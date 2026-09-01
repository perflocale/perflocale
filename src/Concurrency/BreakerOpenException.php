<?php
/**
 * Thrown when a circuit breaker is open and refuses to forward a call.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Concurrency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed exception so callers can `catch (BreakerOpenException $e)` and
 * route to a graceful-degradation path (use TM, return cached result,
 * surface "service temporarily unavailable" to the user) WITHOUT
 * conflating with genuine downstream errors.
 *
 * Carries the breaker key so logs and admin notices can name the open
 * circuit without re-reading the breaker state.
 */
final class BreakerOpenException extends \RuntimeException {

	/**
	 * Breaker key (e.g. "mt_wp_ai_client" or "webhook_<uuid>").
	 *
	 * @var string
	 */
	private string $breaker_key;

	/**
	 * @param string          $breaker_key Breaker key.
	 * @param string          $message     Human-readable.
	 * @param \Throwable|null $previous    Optional cause.
	 */
	public function __construct( string $breaker_key, string $message = '', ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->breaker_key = $breaker_key;
	}

	/**
	 * @return string
	 */
	public function get_breaker_key(): string {
		return $this->breaker_key;
	}
}
