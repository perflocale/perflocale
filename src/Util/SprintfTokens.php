<?php
/**
 * sprintf-placeholder parsing helper.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distinct-placeholder extraction for sprintf-style format strings.
 *
 * Used by the Strings admin modal validator AND the page-save fallback
 * to keep the client and server in agreement about which tokens appear
 * in a msgid (and must therefore appear, with matching positional
 * indices, in the translation).
 *
 * Lives in PerfLocale\Util because it's a generic string-parsing
 * utility — nothing about it is multilingual-specific.
 *
 * @internal Implementation detail. The public surface is the static
 *           extract() method below — third-party code that needs this
 *           parser should call it directly; everything else may change.
 */
final class SprintfTokens {

	/**
	 * Regex (without delimiters) matching a single sprintf token.
	 *
	 *   %<positional?><flags><width><precision?><specifier>
	 *
	 *   - `<positional>` is an optional `N$` prefix (e.g. `%1$s`).
	 *   - `<flags>` may include any of `-+ #0'`.
	 *   - `<width>` may be digits or `*` (consume next sprintf arg).
	 *   - `<precision>` is `.` followed by digits or `*`.
	 *   - `<specifier>` covers the WP/PHP set: b c d e E f F g G o O s u x X.
	 *
	 * Returned without the leading `/` and trailing `/g` so callers can
	 * pick their own delimiters and modifiers.
	 */
	public const PATTERN = '%(%|(?:\d+\$)?[-+ #0\']*\d*[bcdeEfFgGoOsuxX]|(?:\d+\$)?[-+ #0\']*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGoOsuxX])';

	/**
	 * Extract distinct sprintf placeholders from a string in their order
	 * of first occurrence. Skips the `%%` literal-percent escape.
	 *
	 * @param string $text Source text (msgid or translation).
	 * @return array<int, string> Distinct placeholder tokens.
	 */
	public static function extract( string $text ): array {
		if ( $text === '' ) {
			return [];
		}

		$count = preg_match_all( '/' . self::PATTERN . '/', $text, $matches );

		if ( ! $count ) {
			return [];
		}

		$out  = [];
		$seen = [];

		foreach ( $matches[0] as $token ) {
			if ( $token === '%%' ) {
				continue;
			}
			if ( ! isset( $seen[ $token ] ) ) {
				$seen[ $token ] = true;
				$out[]          = $token;
			}
		}

		return $out;
	}
}
