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
 * A save check refused the machine translation (the block structure guard, a
 * block or shortcode marker that came back damaged), so nothing was written.
 * Its message is a whole sentence that already says the translation was not
 * saved and why. Every caller that catches RuntimeException catches it as
 * before; a caller that shows the message can show it without saying "not
 * saved" again.
 */
final class NotSavedException extends \RuntimeException {
}
