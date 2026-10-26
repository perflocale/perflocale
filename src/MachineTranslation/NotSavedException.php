<?php
/**
 * Thrown when a machine translation is refused before it is saved.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A save check refused the machine translation, so nothing was written.
 *
 * The message already says "not saved" and why: show it as it is.
 */
final class NotSavedException extends \RuntimeException {
}
