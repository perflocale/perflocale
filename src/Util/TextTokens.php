<?php
/**
 * Similarity tokens and the Dice score of two texts.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The text similarity the ReSync matcher and the string-set sync share, outside the ReSync namespace so a string sync
 * loads no ReSync class.
 *
 * @since 1.0.8
 */
final class TextTokens {

	/** Tags and comments in an HTML fragment (the one outer group keeps them in a split). */
	public const TAG_PATTERN = '#(<!--.*?-->|</?[a-zA-Z][a-zA-Z0-9:-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>)#s';

	/** Scripts written without spaces: one token per character. */
	private const SPACELESS = '\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}';

	private const TOKEN_PATTERN = '/[' . self::SPACELESS . ']|(?:(?![' . self::SPACELESS . '])[\p{L}\p{N}])+/u';

	/** Token pattern for text that is not valid UTF-8. */
	private const ASCII_TOKEN_PATTERN = '/[A-Za-z0-9]+/';

	/** Units with fewer tokens than this match exactly or not at all. */
	private const MIN_TOKENS = 3;

	/**
	 * The similarity tokens of a text: lowercased unicode words of the tag-stripped decoded text, single characters for
	 * spaceless scripts. When the tag regex fails (PCRE JIT stack on a huge unterminated tag) the tags stay in as tokens,
	 * so two different texts never compare equal for lack of tokens.
	 *
	 * @param string $text Text or HTML.
	 * @return list<string>
	 */
	public static function tokens( string $text ): array {
		$plain = self::decode( preg_replace( self::TAG_PATTERN, '', $text ) ?? $text );
		$found = [];

		if ( preg_match_all( self::TOKEN_PATTERN, $plain, $found ) === false ) {
			preg_match_all( self::ASCII_TOKEN_PATTERN, $plain, $found );
		}

		return array_map( 'strtolower', $found[0] );
	}

	/**
	 * The Dice coefficient of two texts' token multisets, in percent. Texts
	 * without a token (punctuation, emoji) are the same only byte for byte.
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 * @return float 0.0 to 100.0.
	 */
	public static function similarity( string $a, string $b ): float {
		$ta = self::tokens( $a );
		$tb = self::tokens( $b );

		if ( $ta === [] && $tb === [] ) {
			return $a === $b ? 100.0 : 0.0;
		}

		return self::dice( $ta, $tb );
	}

	/**
	 * The Dice coefficient of two token lists in percent (multiset intersection); under three tokens, equal or nothing.
	 * Callers comparing many texts pass the kept `array_count_values()` so no call counts again.
	 *
	 * @param array<int, string>      $a        Tokens ({@see self::tokens()}).
	 * @param array<int, string>      $b        Tokens.
	 * @param array<string, int>|null $counts_a The counts of `$a`, when kept.
	 * @param array<string, int>|null $counts_b The counts of `$b`, when kept.
	 * @return float 0.0 to 100.0.
	 * @phpstan-param list<string> $a
	 * @phpstan-param list<string> $b
	 */
	public static function dice( array $a, array $b, ?array $counts_a = null, ?array $counts_b = null ): float {
		$length_a = count( $a );
		$length_b = count( $b );

		if ( $length_a < self::MIN_TOKENS || $length_b < self::MIN_TOKENS ) {
			return $a === $b ? 100.0 : 0.0;
		}

		$common = 0;

		if ( $counts_a !== null && $counts_b !== null ) {
			if ( count( $counts_a ) === $length_a && count( $counts_b ) === $length_b ) {
				// No token repeats on either side: the intersection of the token sets.
				$common = count( array_intersect_key( $counts_a, $counts_b ) );
			} else {
				foreach ( $counts_a as $token => $count ) {
					$other   = $counts_b[ $token ] ?? 0;
					$common += $other < $count ? $other : $count;
				}
			}

			return 200.0 * $common / ( $length_a + $length_b );
		}

		$counts = $counts_a ?? array_count_values( $a );

		foreach ( $b as $token ) {
			if ( ( $counts[ $token ] ?? 0 ) > 0 ) {
				--$counts[ $token ];
				++$common;
			}
		}

		return 200.0 * $common / ( $length_a + $length_b );
	}

	/**
	 * Entities decoded, U+00A0 a space (as Keys decodes).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function decode( string $text ): string {
		return str_replace( "\u{00A0}", ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
