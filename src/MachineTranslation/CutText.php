<?php
/**
 * Refuses a machine-translated title or excerpt that came back cut off.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plausibility of a plain-text answer against its source. Thresholds are calibrated on real translations
 * (dev/plans-1.0.7.4/d1d4-mt/calibration); a short source never fails on length.
 */
final class CutText {

	/**
	 * Source weight below which the length floor is skipped.
	 */
	private const MIN_WEIGHT = 20;

	/**
	 * An answer below this share of the source's weight is cut.
	 */
	private const FLOOR = 0.25;

	/**
	 * Source weight and answer share for the end check: a cut answer lost its end and is shorter.
	 */
	private const END_MIN = 12;

	private const END_RATIO = 0.8;

	/**
	 * Sentence-final punctuation across scripts (Greek ";" is its question mark).
	 */
	private const TERMINAL = '[.!?;\x{2026}\x{3002}\x{FF01}\x{FF1F}\x{FF0E}\x{061F}\x{06D4}\x{0964}\x{0965}\x{1362}\x{104B}\x{17D4}\x{0589}\x{037E}\x{0F0D}]';

	/**
	 * A sentence end inside the text: then a lost final mark needs no length gate.
	 */
	private const INNER = '/' . self::TERMINAL . '[\s"\'\x{201D}\x{2019})]*\s+\S|[\x{3002}\x{FF01}\x{FF1F}\x{0964}\x{06D4}\x{1362}\x{104B}\x{17D4}]\S/u';

	/**
	 * Closing quotes and brackets read past at the end of the source.
	 */
	private const CLOSERS = '/[\s"\')\]}\x{00BB}\x{201D}\x{2019}\x{201C}\x{00AB}\x{2039}\x{203A}\x{2018}\x{300D}\x{300F}\x{FF09}\x{3011}]+$/u';

	/**
	 * Characters a finished text does not end with.
	 */
	private const CONNECT = '/[,\x{FF0C}\x{3001}:\x{FF1A}\-\x{2013}\x{2014}\/&+(\[{\x{FF08}\x{3010}\x{300C}\x{300E}]$/u';

	/**
	 * Bracket families: openers, closers.
	 */
	private const BRACKETS = [
		[ [ '(', "\u{FF08}" ], [ ')', "\u{FF09}" ] ],
		[ [ '[', "\u{3010}" ], [ ']', "\u{3011}" ] ],
		[ [ '{' ], [ '}' ] ],
		[ [ "\u{300C}", "\u{300E}" ], [ "\u{300D}", "\u{300F}" ] ],
	];

	/**
	 * Languages written without a sentence-final mark.
	 */
	private const NO_STOP = [ 'th', 'lo' ];

	/**
	 * The refusal sentence for the first cut answer, or ''.
	 *
	 * @param array<int, string> $inputs      Texts sent.
	 * @param array<int, mixed>  $outputs     Provider answers, parallel to $inputs.
	 * @param array<int, int>    $slots       Post field of each input: 0 title, 2 excerpt.
	 * @param string             $target_lang Target language slug.
	 * @return string
	 */
	public static function refusal( array $inputs, array $outputs, array $slots, string $target_lang ): string {
		foreach ( $inputs as $i => $source ) {
			$answer = $outputs[ $i ] ?? '';
			$reason = self::reason( (string) $source, is_string( $answer ) ? $answer : '', $target_lang );

			if ( '' === $reason ) {
				continue;
			}

			return 0 === ( $slots[ $i ] ?? 0 )
				/* translators: %s: why the title looks cut off. */
				? sprintf( __( 'The machine translation was not saved because its title looks cut off: %s.', 'perflocale' ), $reason )
				/* translators: %s: why the excerpt looks cut off. */
				: sprintf( __( 'The machine translation was not saved because its excerpt looks cut off: %s.', 'perflocale' ), $reason );
		}

		return '';
	}

	/**
	 * Why an answer looks cut off, or '' when it is plausible. An empty answer is left to the caller.
	 *
	 * @param string $source      Text sent.
	 * @param string $answer      Provider answer.
	 * @param string $target_lang Target language slug.
	 * @return string
	 */
	public static function reason( string $source, string $answer, string $target_lang ): string {
		// Trailing spaces and format marks (no-break space, direction marks) are no end; text that is not UTF-8 stays.
		$source = preg_replace( '/[\s\p{Z}\p{Cf}]+$/u', '', trim( $source ) ) ?? trim( $source );
		$answer = preg_replace( '/[\s\p{Z}\p{Cf}]+$/u', '', trim( $answer ) ) ?? trim( $answer );

		if ( '' === $source || '' === $answer ) {
			return '';
		}

		$mid = __( 'it stops in the middle of a sentence', 'perflocale' );

		if ( 1 !== preg_match( '//u', $answer ) || ( str_ends_with( $answer, "\u{FFFD}" ) && ! str_ends_with( $source, "\u{FFFD}" ) ) ) {
			return $mid;
		}

		$weight = self::weight( $source );
		$ratio  = $weight > 0 ? self::weight( $answer ) / $weight : 1.0;

		if ( $weight >= self::MIN_WEIGHT && $ratio < self::FLOOR ) {
			return __( 'it is much shorter than the original', 'perflocale' );
		}

		// A single sentence also has to be shorter: translators and providers drop a final period on short texts.
		$gated = $weight >= self::END_MIN && $ratio < self::END_RATIO;

		if ( ( ( $gated || 1 === preg_match( self::INNER, $source ) ) && self::lost_end( $source, $answer, $target_lang ) )
			|| ( $gated && 1 === preg_match( self::CONNECT, $answer ) && 1 !== preg_match( self::CONNECT, $source ) ) ) {
			return $mid;
		}

		foreach ( self::BRACKETS as [ $opens, $closes ] ) {
			if ( self::depth( $answer, $opens, $closes ) > max( 0, self::depth( $source, $opens, $closes ) ) ) {
				return __( 'a bracket is not closed', 'perflocale' );
			}
		}

		return '';
	}

	/**
	 * Visible characters, a CJK, kana, Hangul or fullwidth one counted twice; spaces and marks count nothing.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function weight( string $text ): int {
		$base = preg_match_all( '/[^\s\p{Z}\p{Mn}\p{Me}\p{Cf}]/u', $text );
		$wide = preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{3001}-\x{303F}\x{FF01}-\x{FF60}\x{FFE0}-\x{FFE6}]/u', $text );

		return (int) $base + (int) $wide;
	}

	/**
	 * The source ends a sentence and the answer ends in a letter or digit, with its marks (an answer ending in a closer is complete).
	 *
	 * @param string $source      Source.
	 * @param string $answer      Answer.
	 * @param string $target_lang Target language slug.
	 * @return bool
	 */
	private static function lost_end( string $source, string $answer, string $target_lang ): bool {
		$lang = strtolower( (string) preg_replace( '/[-_].*$/', '', $target_lang ) );

		if ( in_array( $lang, self::NO_STOP, true ) ) {
			return false;
		}

		$source = (string) preg_replace( self::CLOSERS, '', $source );

		return 1 === preg_match( '/' . self::TERMINAL . '$/u', $source ) && 1 === preg_match( '/[\p{L}\p{N}]\p{M}*+$/u', $answer );
	}

	/**
	 * Openers minus closers of one bracket family.
	 *
	 * @param string             $text   Text.
	 * @param array<int, string> $opens  Openers.
	 * @param array<int, string> $closes Closers.
	 * @return int
	 */
	private static function depth( string $text, array $opens, array $closes ): int {
		$depth = 0;

		foreach ( $opens as $c ) {
			$depth += substr_count( $text, $c );
		}

		foreach ( $closes as $c ) {
			$depth -= substr_count( $text, $c );
		}

		return $depth;
	}
}
