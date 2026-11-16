<?php
/**
 * The matching of a new source with its base.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of {@see Matcher::match()}: block pairs with classes, every source unit's match, unpartnered base blocks
 * and units. `over_budget`: a fallback pass may pair a move differently, so review rather than apply unseen; `memory`:
 * the matching stopped for memory, holds no pairs, and the engine refuses the pair.
 */
final class Matching {

	/**
	 * Base block per source block id.
	 *
	 * @var array<int, BlockNode>
	 */
	private array $base_of = [];

	/**
	 * Source block per base block id.
	 *
	 * @var array<int, BlockNode>
	 */
	private array $source_of = [];

	/**
	 * Match per source unit id.
	 *
	 * @var array<int, UnitMatch>
	 */
	private array $match_of = [];

	/**
	 * Constructor.
	 *
	 * @param Segmentation                            $source         The source segmentation.
	 * @param Segmentation                            $base           The base segmentation.
	 * @param array<int, array{BlockNode, BlockNode}> $pairs          Paired blocks, source first, in source document order.
	 * @param array<int, string>                      $classes        Class per paired source block id.
	 * @param array<int, UnitMatch>                   $units          The match of every source unit, content in document order, then fields.
	 * @param array<int, BlockNode>                   $removed_blocks Base blocks with no source partner, the topmost only.
	 * @param array<int, Unit>                        $removed_units  Base units with no source partner.
	 * @param bool                                    $over_budget    Whether a pass fell back to a linear or banded pairing because its table would exceed the cell budget.
	 * @param bool                                    $memory         Whether the matching stopped because the tokens of a block would not fit the request's memory.
	 * @phpstan-param list<array{BlockNode, BlockNode}> $pairs
	 * @phpstan-param list<UnitMatch> $units
	 * @phpstan-param list<BlockNode> $removed_blocks
	 * @phpstan-param list<Unit> $removed_units
	 */
	public function __construct(
		public readonly Segmentation $source,
		public readonly Segmentation $base,
		public readonly array $pairs,
		private readonly array $classes,
		public readonly array $units,
		public readonly array $removed_blocks,
		public readonly array $removed_units,
		public readonly bool $over_budget = false,
		public readonly bool $memory = false
	) {
		foreach ( $pairs as [ $n, $b ] ) {
			$this->base_of[ spl_object_id( $n ) ]   = $b;
			$this->source_of[ spl_object_id( $b ) ] = $n;
		}

		foreach ( $units as $match ) {
			$this->match_of[ spl_object_id( $match->unit ) ] = $match;
		}
	}

	/**
	 * The base block a source block pairs with (its structural partner).
	 *
	 * @param BlockNode $source Source block.
	 * @return BlockNode|null
	 */
	public function base_of( BlockNode $source ): ?BlockNode {
		return $this->base_of[ spl_object_id( $source ) ] ?? null;
	}

	/**
	 * The source block a base block pairs with.
	 *
	 * @param BlockNode $base Base block.
	 * @return BlockNode|null
	 */
	public function source_of( BlockNode $base ): ?BlockNode {
		return $this->source_of[ spl_object_id( $base ) ] ?? null;
	}

	/**
	 * The class of a source block: identical, text_equal, changed, or new when it has no partner.
	 *
	 * @param BlockNode $source Source block.
	 * @return string
	 */
	public function class_of( BlockNode $source ): string {
		return $this->classes[ spl_object_id( $source ) ] ?? Matcher::CLASS_NEW;
	}

	/**
	 * The match of a source unit.
	 *
	 * @param Unit $source Source unit.
	 * @return UnitMatch|null Null for a unit the matching did not see.
	 */
	public function match_of( Unit $source ): ?UnitMatch {
		return $this->match_of[ spl_object_id( $source ) ] ?? null;
	}

	/**
	 * How many units have each status, the removed base units, and the reused units counted as same as source.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = [
			Matcher::STATUS_REUSED                 => 0,
			Matcher::STATUS_REMOVED_IN_TRANSLATION => 0,
			Matcher::STATUS_FUZZY                  => 0,
			Matcher::STATUS_CHANGED                => 0,
			Matcher::STATUS_NEW                    => 0,
			Matcher::STATUS_REMOVED                => count( $this->removed_units ),
			Matcher::SAME_AS_SOURCE                => 0,
		];

		foreach ( $this->units as $match ) {
			if ( isset( $counts[ $match->status ] ) ) {
				++$counts[ $match->status ];
			}

			if ( $match->same_as_source ) {
				++$counts[ Matcher::SAME_AS_SOURCE ];
			}
		}

		return $counts;
	}
}
