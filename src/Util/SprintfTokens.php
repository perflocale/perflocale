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
 * Distinct sprintf placeholders of a format string, which a string translation must also contain.
 *
 * The Strings page checks with it server side; assets/js/strings-validate.js mirrors PATTERN client side.
 *
 * @internal Only extract() is meant for other callers; everything else may change.
 */
final class SprintfTokens {

	/**
	 * Regex body (no delimiters) for one token: `%`, optional `N$`, flags, width, `.precision`, specifier; or `%%`.
	 *
	 * Width starts with 1-9 (zeros are flags), so each token has one parse and matching stays linear.
	 */
	public const PATTERN = '%(%|(?:\d+\$)?[-+ #0\']*(?:[1-9]\d*|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGoOsuxX])';

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
