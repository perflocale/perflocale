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
 * Typed exception for CLIENT-side XLIFF problems: empty payload, XML that
 * does not parse, a document declaring XML entities, or a `trgLang` that
 * matches no active language.
 *
 * It exists so the REST layer can answer a malformed upload with a stable
 * 4xx instead of 500. Without the distinction the controller could only
 * catch `\Throwable` and had to report every parse failure as a server
 * error, which tells an integrator to retry a request that can never
 * succeed and buries genuine server faults in the same bucket.
 *
 * Extends `\RuntimeException` so existing callers that catch the documented
 * `\RuntimeException` contract keep working unchanged.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
final class XliffFormatException extends \RuntimeException {
}
