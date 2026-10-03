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
 * The target language equals the source post's language, so there is nothing
 * to translate. {@see TranslationService::translate_post()} throws it before
 * any provider call; callers that answer over HTTP can map it to a 400.
 */
final class SameLanguageException extends \RuntimeException {
}
