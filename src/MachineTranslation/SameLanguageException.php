<?php
/**
 * Thrown when a machine translation is asked for the source's own language.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The target language is the source post's language: thrown before any provider call (HTTP callers map it to a 400).
 */
final class SameLanguageException extends \RuntimeException {
}
