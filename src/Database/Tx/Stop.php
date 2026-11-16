<?php
/**
 * Ends the body of a transaction unit that can no longer commit.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Tx;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by Tx::check(); Tx::run() catches it and rolls back.
 *
 * @since 1.0.8
 * @internal
 */
final class Stop extends \RuntimeException {
}
