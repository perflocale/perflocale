<?php
/**
 * A composition that must not be written.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when the composer cannot assemble a document it can vouch for (overlapping edits, an unspliceable carrier);
 * the engine turns it into `compose_error` and writes nothing for the pair.
 */
final class ComposeError extends \RuntimeException {
}
