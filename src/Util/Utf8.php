<?php
/**
 * UTF-8 text functions in plain PHP.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UTF-8 text functions that need no mbstring: one code path, so every host gives the same bytes.
 *
 * @since 1.0.8
 * @internal
 */
final class Utf8 {

	/**
	 * Lowercase runs above U+007F as mb_strtolower() maps each code point (PHP 8.4 mbstring): [first, last, delta, step].
	 */
	private const LOWER = [
		[ 0xC0, 0xD6, 32, 1 ], [ 0xD8, 0xDE, 32, 1 ], [ 0x100, 0x12E, 1, 2 ], [ 0x132, 0x136, 1, 2 ],
		[ 0x139, 0x147, 1, 2 ], [ 0x14A, 0x176, 1, 2 ], [ 0x178, 0x178, -121, 1 ], [ 0x179, 0x17D, 1, 2 ],
		[ 0x181, 0x181, 210, 1 ], [ 0x182, 0x184, 1, 2 ], [ 0x186, 0x186, 206, 1 ], [ 0x187, 0x187, 1, 1 ],
		[ 0x189, 0x18A, 205, 1 ], [ 0x18B, 0x18B, 1, 1 ], [ 0x18E, 0x18E, 79, 1 ], [ 0x18F, 0x18F, 202, 1 ],
		[ 0x190, 0x190, 203, 1 ], [ 0x191, 0x191, 1, 1 ], [ 0x193, 0x193, 205, 1 ], [ 0x194, 0x194, 207, 1 ],
		[ 0x196, 0x196, 211, 1 ], [ 0x197, 0x197, 209, 1 ], [ 0x198, 0x198, 1, 1 ], [ 0x19C, 0x19C, 211, 1 ],
		[ 0x19D, 0x19D, 213, 1 ], [ 0x19F, 0x19F, 214, 1 ], [ 0x1A0, 0x1A4, 1, 2 ], [ 0x1A6, 0x1A6, 218, 1 ],
		[ 0x1A7, 0x1A7, 1, 1 ], [ 0x1A9, 0x1A9, 218, 1 ], [ 0x1AC, 0x1AC, 1, 1 ], [ 0x1AE, 0x1AE, 218, 1 ],
		[ 0x1AF, 0x1AF, 1, 1 ], [ 0x1B1, 0x1B2, 217, 1 ], [ 0x1B3, 0x1B5, 1, 2 ], [ 0x1B7, 0x1B7, 219, 1 ],
		[ 0x1B8, 0x1B8, 1, 1 ], [ 0x1BC, 0x1BC, 1, 1 ], [ 0x1C4, 0x1C4, 2, 1 ], [ 0x1C5, 0x1C5, 1, 1 ],
		[ 0x1C7, 0x1C7, 2, 1 ], [ 0x1C8, 0x1C8, 1, 1 ], [ 0x1CA, 0x1CA, 2, 1 ], [ 0x1CB, 0x1DB, 1, 2 ],
		[ 0x1DE, 0x1EE, 1, 2 ], [ 0x1F1, 0x1F1, 2, 1 ], [ 0x1F2, 0x1F4, 1, 2 ], [ 0x1F6, 0x1F6, -97, 1 ],
		[ 0x1F7, 0x1F7, -56, 1 ], [ 0x1F8, 0x21E, 1, 2 ], [ 0x220, 0x220, -130, 1 ], [ 0x222, 0x232, 1, 2 ],
		[ 0x23A, 0x23A, 10795, 1 ], [ 0x23B, 0x23B, 1, 1 ], [ 0x23D, 0x23D, -163, 1 ], [ 0x23E, 0x23E, 10792, 1 ],
		[ 0x241, 0x241, 1, 1 ], [ 0x243, 0x243, -195, 1 ], [ 0x244, 0x244, 69, 1 ], [ 0x245, 0x245, 71, 1 ],
		[ 0x246, 0x24E, 1, 2 ], [ 0x370, 0x372, 1, 2 ], [ 0x376, 0x376, 1, 1 ], [ 0x37F, 0x37F, 116, 1 ],
		[ 0x386, 0x386, 38, 1 ], [ 0x388, 0x38A, 37, 1 ], [ 0x38C, 0x38C, 64, 1 ], [ 0x38E, 0x38F, 63, 1 ],
		[ 0x391, 0x3A1, 32, 1 ], [ 0x3A3, 0x3AB, 32, 1 ], [ 0x3CF, 0x3CF, 8, 1 ], [ 0x3D8, 0x3EE, 1, 2 ],
		[ 0x3F4, 0x3F4, -60, 1 ], [ 0x3F7, 0x3F7, 1, 1 ], [ 0x3F9, 0x3F9, -7, 1 ], [ 0x3FA, 0x3FA, 1, 1 ],
		[ 0x3FD, 0x3FF, -130, 1 ], [ 0x400, 0x40F, 80, 1 ], [ 0x410, 0x42F, 32, 1 ], [ 0x460, 0x480, 1, 2 ],
		[ 0x48A, 0x4BE, 1, 2 ], [ 0x4C0, 0x4C0, 15, 1 ], [ 0x4C1, 0x4CD, 1, 2 ], [ 0x4D0, 0x52E, 1, 2 ],
		[ 0x531, 0x556, 48, 1 ], [ 0x10A0, 0x10C5, 7264, 1 ], [ 0x10C7, 0x10C7, 7264, 1 ], [ 0x10CD, 0x10CD, 7264, 1 ],
		[ 0x13A0, 0x13EF, 38864, 1 ], [ 0x13F0, 0x13F5, 8, 1 ], [ 0x1C89, 0x1C89, 1, 1 ], [ 0x1C90, 0x1CBA, -3008, 1 ],
		[ 0x1CBD, 0x1CBF, -3008, 1 ], [ 0x1E00, 0x1E94, 1, 2 ], [ 0x1E9E, 0x1E9E, -7615, 1 ], [ 0x1EA0, 0x1EFE, 1, 2 ],
		[ 0x1F08, 0x1F0F, -8, 1 ], [ 0x1F18, 0x1F1D, -8, 1 ], [ 0x1F28, 0x1F2F, -8, 1 ], [ 0x1F38, 0x1F3F, -8, 1 ],
		[ 0x1F48, 0x1F4D, -8, 1 ], [ 0x1F59, 0x1F5F, -8, 2 ], [ 0x1F68, 0x1F6F, -8, 1 ], [ 0x1F88, 0x1F8F, -8, 1 ],
		[ 0x1F98, 0x1F9F, -8, 1 ], [ 0x1FA8, 0x1FAF, -8, 1 ], [ 0x1FB8, 0x1FB9, -8, 1 ], [ 0x1FBA, 0x1FBB, -74, 1 ],
		[ 0x1FBC, 0x1FBC, -9, 1 ], [ 0x1FC8, 0x1FCB, -86, 1 ], [ 0x1FCC, 0x1FCC, -9, 1 ], [ 0x1FD8, 0x1FD9, -8, 1 ],
		[ 0x1FDA, 0x1FDB, -100, 1 ], [ 0x1FE8, 0x1FE9, -8, 1 ], [ 0x1FEA, 0x1FEB, -112, 1 ], [ 0x1FEC, 0x1FEC, -7, 1 ],
		[ 0x1FF8, 0x1FF9, -128, 1 ], [ 0x1FFA, 0x1FFB, -126, 1 ], [ 0x1FFC, 0x1FFC, -9, 1 ], [ 0x2126, 0x2126, -7517, 1 ],
		[ 0x212A, 0x212A, -8383, 1 ], [ 0x212B, 0x212B, -8262, 1 ], [ 0x2132, 0x2132, 28, 1 ], [ 0x2160, 0x216F, 16, 1 ],
		[ 0x2183, 0x2183, 1, 1 ], [ 0x24B6, 0x24CF, 26, 1 ], [ 0x2C00, 0x2C2F, 48, 1 ], [ 0x2C60, 0x2C60, 1, 1 ],
		[ 0x2C62, 0x2C62, -10743, 1 ], [ 0x2C63, 0x2C63, -3814, 1 ], [ 0x2C64, 0x2C64, -10727, 1 ],
		[ 0x2C67, 0x2C6B, 1, 2 ], [ 0x2C6D, 0x2C6D, -10780, 1 ], [ 0x2C6E, 0x2C6E, -10749, 1 ],
		[ 0x2C6F, 0x2C6F, -10783, 1 ], [ 0x2C70, 0x2C70, -10782, 1 ], [ 0x2C72, 0x2C72, 1, 1 ], [ 0x2C75, 0x2C75, 1, 1 ],
		[ 0x2C7E, 0x2C7F, -10815, 1 ], [ 0x2C80, 0x2CE2, 1, 2 ], [ 0x2CEB, 0x2CED, 1, 2 ], [ 0x2CF2, 0x2CF2, 1, 1 ],
		[ 0xA640, 0xA66C, 1, 2 ], [ 0xA680, 0xA69A, 1, 2 ], [ 0xA722, 0xA72E, 1, 2 ], [ 0xA732, 0xA76E, 1, 2 ],
		[ 0xA779, 0xA77B, 1, 2 ], [ 0xA77D, 0xA77D, -35332, 1 ], [ 0xA77E, 0xA786, 1, 2 ], [ 0xA78B, 0xA78B, 1, 1 ],
		[ 0xA78D, 0xA78D, -42280, 1 ], [ 0xA790, 0xA792, 1, 2 ], [ 0xA796, 0xA7A8, 1, 2 ], [ 0xA7AA, 0xA7AA, -42308, 1 ],
		[ 0xA7AB, 0xA7AB, -42319, 1 ], [ 0xA7AC, 0xA7AC, -42315, 1 ], [ 0xA7AD, 0xA7AD, -42305, 1 ],
		[ 0xA7AE, 0xA7AE, -42308, 1 ], [ 0xA7B0, 0xA7B0, -42258, 1 ], [ 0xA7B1, 0xA7B1, -42282, 1 ],
		[ 0xA7B2, 0xA7B2, -42261, 1 ], [ 0xA7B3, 0xA7B3, 928, 1 ], [ 0xA7B4, 0xA7C2, 1, 2 ], [ 0xA7C4, 0xA7C4, -48, 1 ],
		[ 0xA7C5, 0xA7C5, -42307, 1 ], [ 0xA7C6, 0xA7C6, -35384, 1 ], [ 0xA7C7, 0xA7C9, 1, 2 ],
		[ 0xA7CB, 0xA7CB, -42343, 1 ], [ 0xA7CC, 0xA7CC, 1, 1 ], [ 0xA7D0, 0xA7D0, 1, 1 ], [ 0xA7D6, 0xA7DA, 1, 2 ],
		[ 0xA7DC, 0xA7DC, -42561, 1 ], [ 0xA7F5, 0xA7F5, 1, 1 ], [ 0xFF21, 0xFF3A, 32, 1 ], [ 0x10400, 0x10427, 40, 1 ],
		[ 0x104B0, 0x104D3, 40, 1 ], [ 0x10570, 0x1057A, 39, 1 ], [ 0x1057C, 0x1058A, 39, 1 ], [ 0x1058C, 0x10592, 39, 1 ],
		[ 0x10594, 0x10595, 39, 1 ], [ 0x10C80, 0x10CB2, 64, 1 ], [ 0x10D50, 0x10D65, 32, 1 ], [ 0x118A0, 0x118BF, 32, 1 ],
		[ 0x16E40, 0x16E5F, 32, 1 ], [ 0x1E900, 0x1E921, 34, 1 ],
	];

	/**
	 * UTF-8 character => its lowercase, built from LOWER on first use.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $lower = null;

	/**
	 * Text lowercased by a fixed per-code-point map (mb_strtolower()'s, PHP 8.4), the same with or without mbstring.
	 *
	 * Two deliberate differences from mb_strtolower(): final sigma is always σ (no PHP 8.3 context rule: a key must not
	 * depend on its neighbours), and invalid bytes are kept (mbstring writes "?").
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function lower( string $text ): string {
		// strtr, not strtolower(): PHP 8.1 strtolower() follows the process locale (LC_CTYPE).
		$text = strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );

		if ( 1 !== preg_match( '/[\x80-\xFF]/', $text ) ) {
			return $text;
		}

		if ( null === self::$lower ) {
			self::$lower = [ "\u{130}" => "i\u{307}" ];

			foreach ( self::LOWER as [ $first, $last, $delta, $step ] ) {
				for ( $cp = $first; $cp <= $last; $cp += $step ) {
					self::$lower[ self::chr( $cp ) ] = self::chr( $cp + $delta );
				}
			}
		}

		// Keys are whole UTF-8 characters, so in valid text strtr() only meets them at character starts.
		return strtr( $text, self::$lower );
	}

	/**
	 * Whether the text is well-formed UTF-8 (PCRE checks the whole subject, as mb_check_encoding() does).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function valid( string $text ): bool {
		return '' === $text || 1 === preg_match( '//u', $text );
	}

	/**
	 * Characters for a limit or a usage count: UTF-8 characters of valid UTF-8, else the byte count (an upper bound).
	 *
	 * Without mbstring, core's mb_strlen() before WordPress 6.9 counts invalid UTF-8 as 0; 'UTF-8' is explicit so a
	 * changed mb_internal_encoding() cannot change the count.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( string $text ): int {
		return self::valid( $text ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * A code point (not a surrogate) as UTF-8.
	 *
	 * @param int $cp Code point.
	 * @return string
	 */
	private static function chr( int $cp ): string {
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}

		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}

		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}

		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
}
