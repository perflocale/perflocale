<?php
/**
 * Text similarity for the string set, shared with the ReSync engine.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Util\TextTokens;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The edit scorer and threshold of the string-set sync: the ReSync matcher's (TextTokens::similarity()),
 * so the option-text sync and a resync agree on what a small edit is.
 *
 * @phpstan-type Prepared array{text: string, tokens: list<string>, counts: array<string, int>, length: int}
 */
final class TextSimilarity {

	/**
	 * Least similarity, in percent, at which two texts of one context pair as an edit.
	 */
	public const EDIT_THRESHOLD = 50;

	/**
	 * Similarity of two texts, in percent.
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 * @return float 0.0 to 100.0.
	 */
	public static function score( string $a, string $b ): float {
		return TextTokens::similarity( $a, $b );
	}

	/**
	 * A text prepared once for many comparisons.
	 *
	 * @param string $text Text.
	 * @return array<string, mixed>
	 * @phpstan-return Prepared
	 */
	public static function prepare( string $text ): array {
		$tokens = TextTokens::tokens( $text );

		return [
			'text'   => $text,
			'tokens' => $tokens,
			'counts' => array_count_values( $tokens ),
			'length' => count( $tokens ),
		];
	}

	/**
	 * Similarity of two prepared texts, in percent; equals score() only for pairs that can reach the threshold.
	 * Others return 0.0 early: Dice is at most 2*min / (a + b).
	 *
	 * @param array<string, mixed> $a A prepared text.
	 * @param array<string, mixed> $b A prepared text.
	 * @return float 0.0 to 100.0.
	 * @phpstan-param Prepared $a
	 * @phpstan-param Prepared $b
	 */
	public static function score_prepared( array $a, array $b ): float {
		if ( 0 === $a['length'] && 0 === $b['length'] ) {
			return $a['text'] === $b['text'] ? 100.0 : 0.0;
		}

		$shorter = $a['length'] < $b['length'] ? $a['length'] : $b['length'];

		if ( 200 * $shorter < self::EDIT_THRESHOLD * ( $a['length'] + $b['length'] ) ) {
			return 0.0;
		}

		return TextTokens::dice( $a['tokens'], $b['tokens'], $a['counts'], $b['counts'] );
	}

	/**
	 * Whether two texts are close enough to be one edited row.
	 *
	 * @param string $a Text.
	 * @param string $b Text.
	 * @return bool
	 */
	public static function is_edit( string $a, string $b ): bool {
		return self::score( $a, $b ) >= self::EDIT_THRESHOLD;
	}
}
