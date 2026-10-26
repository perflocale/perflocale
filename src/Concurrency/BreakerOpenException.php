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
 * An open breaker refused a call: callers can degrade gracefully without treating it as a downstream error.
 *
 * Carries the breaker key so logs and notices can name the open circuit.
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
