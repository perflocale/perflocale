<?php
/**
 * The alignment of a base document with its translation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of {@see Aligner::align()}: block and unit pairs, per-language removals, translation-only blocks and
 * `ambiguous` flags. Lookups are by object id: the segmentations are kept, so their nodes and units stay alive.
 */
final class Alignment {

	/**
	 * Translation node per base node id.
	 *
	 * @var array<int, BlockNode>
	 */
	private array $translation_of = [];

	/**
	 * Base node per translation node id.
	 *
	 * @var array<int, BlockNode>
	 */
	private array $base_of = [];

	/**
	 * Translation unit per base unit id.
	 *
	 * @var array<int, Unit>
	 */
	private array $translation_unit_of = [];

	/**
	 * The carried blocks, by node id.
	 *
	 * @var array<int, true>
	 */
	private array $carried = [];

	/**
	 * The unsure base units, by unit id.
	 *
	 * @var array<int, true>
	 */
	private array $unsure = [];

	/**
	 * Base unit per translation unit id.
	 *
	 * @var array<int, Unit>
	 */
	private array $base_unit_of = [];

	/**
	 * Constructor.
	 *
	 * @param Segmentation                            $base              The base segmentation.
	 * @param Segmentation                            $translation       The translation segmentation.
	 * @param array<int, array{BlockNode, BlockNode}> $pairs      Aligned blocks, base first, in base document order.
	 * @param array<int, array{Unit, Unit}>           $unit_pairs        Paired units, base first.
	 * @param array<int, BlockNode>                   $removed           Base blocks with no translation block, the topmost only.
	 * @param array<int, BlockNode>                   $translation_only  Translation blocks with no base block, the topmost only.
	 * @param array<string, list<string>>             $base_flags        Flags per base block path key.
	 * @param array<string, list<string>>             $translation_flags Flags per translation block path key.
	 * @param array<int, Unit>                        $unsure            Base units left unpaired where their block's unit counts differ: not removed by the translator.
	 * @param array<int, BlockNode>                   $carried           Blocks below a list whose item units pair with a list of the other shape (saved before WordPress 6.0, or migrated): neither removals nor translation-only blocks.
	 * @phpstan-param list<array{BlockNode, BlockNode}> $pairs
	 * @phpstan-param list<array{Unit, Unit}> $unit_pairs
	 * @phpstan-param list<BlockNode> $removed
	 * @phpstan-param list<BlockNode> $translation_only
	 * @phpstan-param list<Unit> $unsure
	 * @phpstan-param list<BlockNode> $carried
	 */
	public function __construct(
		public readonly Segmentation $base,
		public readonly Segmentation $translation,
		public readonly array $pairs,
		public readonly array $unit_pairs,
		public readonly array $removed,
		public readonly array $translation_only,
		public readonly array $base_flags,
		public readonly array $translation_flags,
		array $unsure = [],
		array $carried = []
	) {
		foreach ( $pairs as [ $b, $t ] ) {
			$this->translation_of[ spl_object_id( $b ) ] = $t;
			$this->base_of[ spl_object_id( $t ) ]        = $b;
		}

		foreach ( $unit_pairs as [ $b, $t ] ) {
			$this->translation_unit_of[ spl_object_id( $b ) ] = $t;
			$this->base_unit_of[ spl_object_id( $t ) ]        = $b;
		}

		foreach ( $unsure as $unit ) {
			$this->unsure[ spl_object_id( $unit ) ] = true;
		}

		foreach ( $carried as $node ) {
			$this->carried[ spl_object_id( $node ) ] = true;
		}
	}

	/**
	 * Whether a block below a list is carried by a list of the other shape: its text pairs through those units, so it is
	 * neither a removal nor translation-only.
	 *
	 * @param BlockNode $node Base or translation block.
	 * @return bool
	 */
	public function carried( BlockNode $node ): bool {
		return isset( $this->carried[ spl_object_id( $node ) ] );
	}

	/**
	 * Whether a base unit is unsure: unpaired where its block's unit counts differ, so its translation may exist.
	 *
	 * @param Unit $base Base unit.
	 * @return bool
	 */
	public function unsure( Unit $base ): bool {
		return isset( $this->unsure[ spl_object_id( $base ) ] );
	}

	/**
	 * The translation block aligned to a base block.
	 *
	 * @param BlockNode $base Base block.
	 * @return BlockNode|null
	 */
	public function translation_of( BlockNode $base ): ?BlockNode {
		return $this->translation_of[ spl_object_id( $base ) ] ?? null;
	}

	/**
	 * The base block aligned to a translation block.
	 *
	 * @param BlockNode $translation Translation block.
	 * @return BlockNode|null
	 */
	public function base_of( BlockNode $translation ): ?BlockNode {
		return $this->base_of[ spl_object_id( $translation ) ] ?? null;
	}

	/**
	 * The translation unit paired with a base unit; null for a per-language unit removal.
	 *
	 * @param Unit $base Base unit.
	 * @return Unit|null
	 */
	public function translation_unit_of( Unit $base ): ?Unit {
		return $this->translation_unit_of[ spl_object_id( $base ) ] ?? null;
	}

	/**
	 * The base unit paired with a translation unit.
	 *
	 * @param Unit $translation Translation unit.
	 * @return Unit|null
	 */
	public function base_unit_of( Unit $translation ): ?Unit {
		return $this->base_unit_of[ spl_object_id( $translation ) ] ?? null;
	}

	/**
	 * Whether any block is flagged ambiguous; such an alignment is never applied automatically.
	 *
	 * @return bool
	 */
	public function ambiguous(): bool {
		foreach ( [ $this->base_flags, $this->translation_flags ] as $flags ) {
			foreach ( $flags as $list ) {
				if ( in_array( Aligner::FLAG_AMBIGUOUS, $list, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether every base and translation block is aligned and no run is ambiguous (decides confirm quality and re-base).
	 *
	 * @return bool
	 */
	public function one_to_one(): bool {
		return $this->removed === [] && $this->translation_only === [] && ! $this->ambiguous();
	}
}
