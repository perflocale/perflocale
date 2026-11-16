<?php
/**
 * The match of one source unit.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the Matcher found for one source unit: status, the paired base unit, that unit's translation (reused text, or
 * the previous translation of a changed unit), the similarity, and whether a reused translation equals its base text.
 */
final class UnitMatch {

	/**
	 * Constructor.
	 *
	 * @param Unit      $unit           The source unit.
	 * @param string    $status         {@see Matcher::STATUS_REUSED}, {@see Matcher::STATUS_REMOVED_IN_TRANSLATION}, {@see Matcher::STATUS_FUZZY}, {@see Matcher::STATUS_CHANGED} or {@see Matcher::STATUS_NEW}.
	 * @param Unit|null $base           The base unit paired with it; null when new.
	 * @param Unit|null $translation    The translation unit of the base unit; null when the base unit has none or the unit is new.
	 * @param float     $similarity     Similarity with the base unit in percent: 100 for an exact key, 0 when new.
	 * @param bool      $same_as_source Whether a reused translation's text equals its base text (two or more tokens).
	 */
	public function __construct(
		public readonly Unit $unit,
		public readonly string $status,
		public readonly ?Unit $base,
		public readonly ?Unit $translation,
		public readonly float $similarity,
		public readonly bool $same_as_source
	) {}
}
