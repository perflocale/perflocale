<?php
/**
 * Thrown when submitted XLIFF cannot be parsed or is structurally unusable.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Xliff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client-side XLIFF problem (empty, unparsable, declares entities, unknown trgLang): REST answers 4xx, not 500.
 *
 * Extends \RuntimeException so callers catching the documented contract keep working.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class XliffFormatException extends \RuntimeException {
}
