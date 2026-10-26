<?php
/**
 * Placeholder masking for machine-translation safety.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces placeholders and inline HTML with `[[PFL_PH_N]]` sentinels before
 * machine translation and restores them verbatim after.
 */
final class PlaceholderMasker {

	/**
	 * Sentinel format; %d is the placeholder index.
	 */
	private const TOKEN_FORMAT = '[[PFL_PH_%d]]';

	/**
	 * A sentinel with the brackets around it: as sent, or LibreTranslate's `[[PFL PH N]]` and `[[[[PFL PH N]]]]`.
	 */
	private const TOKEN_RE = '/(\[+)\s*PFL(_PH_| PH )(\d+)\s*(\]+)/i';

	/**
	 * SEO template variables with double percent (Yoast, SEOPress): `%%sitename%%`.
	 * Masked before PRINTF_RE, whose `%%` rule would mask only the delimiters
	 * and send the variable name to the provider.
	 */
	private const SEO_VAR_DOUBLE_RE = '/%%[a-zA-Z][a-zA-Z0-9_-]*%%/';

	/**
	 * SEO template variables with single percent (Rank Math): `%title%`. A
	 * leading letter and no spaces keep prose like "50% and 20%" from matching.
	 */
	private const SEO_VAR_SINGLE_RE = '/%[a-zA-Z][a-zA-Z0-9_-]*%/';

	/**
	 * printf-style placeholders: `%s`, `%1$d`, `%%`. The narrow conversion set
	 * leaves a literal `%` in prose unmasked.
	 */
	private const PRINTF_RE = '/%(?:\d+\$)?[sdfgxXcouebE%]/';

	/**
	 * Brace-style placeholders: `{name}`, `{0}`, `{{var}}`, masked whole so a
	 * provider cannot split a brace pair.
	 */
	private const BRACE_RE = '/\{\{?[a-zA-Z0-9_.-]+\}?\}/';

	/**
	 * Inline HTML tags, each one its own placeholder so a provider can move text
	 * between an opening and a closing tag. Linear time (possessive, atomic): a
	 * quoted value needs `\s`, `/>` or `>` after it.
	 */
	private const HTML_RE = '/<\/?[a-zA-Z][a-zA-Z0-9]*+\b(?>\s++[a-zA-Z-]++(?>=(?>"[^"]*+"(?=\s|\/?>)|\'[^\']*+\'(?=\s|\/?>)|[^\s<>]*+))?)*+\s*+\/?>/';

	/**
	 * Replace every placeholder in $text with a unique sentinel.
	 *
	 * @param string $text Source text containing zero or more placeholders.
	 * @return array{0: string, 1: array<int, string>} [masked text, placeholders]; sentinel N restores to entry N.
	 */
	public static function mask( string $text ): array {
		$placeholders = [];
		$counter      = 0;

		$callback = static function ( $match ) use ( &$placeholders, &$counter ) {
			$placeholders[] = $match[0];
			return sprintf( self::TOKEN_FORMAT, $counter++ );
		};

		// Order matters: HTML first so a brace inside an attribute stays part of
		// its tag; SEO variables before printf, whose `%%` rule would split them.
		$masked = $text;
		foreach ( [ self::HTML_RE, self::BRACE_RE, self::SEO_VAR_DOUBLE_RE, self::SEO_VAR_SINGLE_RE, self::PRINTF_RE ] as $pattern ) {
			$before_count = count( $placeholders );
			$step         = preg_replace_callback( $pattern, $callback, $masked );

			if ( null === $step ) {
				// A PCRE error (work limit) leaves this step's tokens unmasked:
				// the text is still sent whole, never as an empty string.
				$placeholders = array_slice( $placeholders, 0, $before_count );
				$counter      = $before_count;
				continue;
			}

			$masked = $step;
		}

		return [ $masked, $placeholders ];
	}

	/**
	 * Restore placeholders into $text using the list returned by mask().
	 *
	 * Accepts reordered, re-cased or space-padded sentinels and TOKEN_RE's variants. A repeated or unknown one leaves
	 * the text unrestored, so preserves_placeholders() lets the caller reject it as it does a dropped one.
	 *
	 * @param string             $masked_translation Output from MT with sentinels embedded.
	 * @param array<int, string> $placeholders       Original-order placeholder list from mask().
	 * @return string Restored translation with placeholders re-injected.
	 */
	public static function restore( string $masked_translation, array $placeholders ): string {
		if ( $placeholders === [] || ! preg_match_all( self::TOKEN_RE, $masked_translation, $found, PREG_SET_ORDER ) ) {
			return $masked_translation;
		}

		$indexes = [];

		foreach ( $found as $m ) {
			if ( null === self::restored_token( $m, $placeholders ) ) {
				return $masked_translation;
			}

			$indexes[] = (int) $m[3];
		}

		if ( count( $indexes ) !== count( array_unique( $indexes ) ) ) {
			return $masked_translation;
		}

		$restored = preg_replace_callback(
			self::TOKEN_RE,
			static fn( array $m ): string => (string) self::restored_token( $m, $placeholders ),
			$masked_translation
		);

		// On a PCRE error the sentinels stay in place, so preserves_placeholders()
		// rejects the result instead of an empty string being saved.
		return $restored ?? $masked_translation;
	}

	/**
	 * One sentinel match restored, or null for an unknown index or brackets other than the sent ones.
	 *
	 * As sent, brackets beyond its own two are the source's and stay; the LibreTranslate form only as `[[ ]]` or
	 * `[[[[ ]]]]`.
	 *
	 * @param array<int, string> $m            TOKEN_RE match: brackets before, separator, index, brackets after.
	 * @param array<int, string> $placeholders Placeholder list from mask().
	 * @return string|null
	 */
	private static function restored_token( array $m, array $placeholders ): ?string {
		$index = (int) $m[3];
		$open  = strlen( $m[1] );
		$close = strlen( $m[4] );

		if ( ! isset( $placeholders[ $index ] ) ) {
			return null;
		}

		if ( '_PH_' === strtoupper( $m[2] ) ) {
			return $open >= 2 && $close >= 2 ? str_repeat( '[', $open - 2 ) . $placeholders[ $index ] . str_repeat( ']', $close - 2 ) : null;
		}

		return ( 2 === $open && 2 === $close ) || ( 4 === $open && 4 === $close ) ? $placeholders[ $index ] : null;
	}

	/**
	 * Whether a translation keeps every placeholder of its source verbatim.
	 *
	 * @param string $source      Original source text.
	 * @param string $translation Candidate translation.
	 * @return bool True if every placeholder of the source appears (verbatim) in the translation as often as in the
	 *              source, and no sentinel the source does not hold is left in it.
	 */
	public static function preserves_placeholders( string $source, string $translation ): bool {
		[ , $source_phs ] = self::mask( $source );

		if ( $source_phs === [] ) {
			return true;
		}

		foreach ( array_count_values( $source_phs ) as $ph => $count ) {
			if ( substr_count( $translation, (string) $ph ) < $count ) {
				return false;
			}
		}

		// An unknown sentinel restore() left in place.
		$sentinel = '/\[\[\s*PFL[_ ]PH[_ ]\d+\s*\]\]/i';

		return (int) preg_match_all( $sentinel, $translation ) <= (int) preg_match_all( $sentinel, $source );
	}
}
