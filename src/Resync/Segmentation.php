<?php
/**
 * The units of one document.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of {@see Segmenter::segment()}: units in document order and the flags, protected blocks and chunk kinds
 * by path key ("0", "2.0.1"). A document whose text could not be read ($error says why) has no units.
 */
final class Segmentation {

	/**
	 * Protected nodes by path key, with the reason ({@see Segmenter::PROTECTED_SKIP},
	 * {@see Segmenter::PROTECTED_OPAQUE}); a protected node's descendants are not listed.
	 *
	 * @var array<string, string>
	 */
	public readonly array $protected;

	/**
	 * Unit indexes per node ({@see self::node_key()}).
	 *
	 * @var array<int, list<int>>
	 */
	private array $by_node = [];

	/**
	 * Constructor.
	 *
	 * @param BlockTree                   $tree            The segmented tree.
	 * @param array<int, Unit>            $units           Units in document order.
	 * @param array<string, list<string>> $flags           Flags per node path key ({@see Segmenter::FLAG_STRUCTURE}).
	 * @param array<string, string>       $protected_nodes Protected nodes by path key, with the reason.
	 * @param array<string, list<string>> $chunks          The kind of each own chunk per node path key ({@see Segmenter::CHUNK_SHELL}, {@see Segmenter::CHUNK_SEPARATOR}, {@see Segmenter::CHUNK_FREEFORM}).
	 * @param string                      $error           Why the document could not be segmented ({@see \PerfLocale\Translation\BlockAttributeSource::ERROR_TOKENIZER}, {@see \PerfLocale\Translation\BlockAttributeSource::ERROR_SELECTOR_BUDGET}, {@see BlockScanner::UNSUPPORTED_DEPTH}); '' when it was.
	 * @phpstan-param list<Unit> $units
	 */
	public function __construct(
		public readonly BlockTree $tree,
		public readonly array $units,
		public readonly array $flags,
		array $protected_nodes,
		public readonly array $chunks,
		public readonly string $error = ''
	) {
		$this->protected = $protected_nodes;

		foreach ( $units as $i => $unit ) {
			if ( $unit->node !== null ) {
				$this->by_node[ self::node_key( $unit->node ) ][] = $i;
			}
		}
	}

	/**
	 * The path key of a node.
	 *
	 * @param BlockNode $node Node.
	 * @return string
	 */
	public static function path_key( BlockNode $node ): string {
		return implode( '.', $node->path->indexes() );
	}

	/**
	 * The lookup key of a node: its object id, one step whatever the depth; the tree keeps its nodes alive, so an id
	 * names one node.
	 *
	 * @param BlockNode $node Node.
	 * @return int
	 */
	private static function node_key( BlockNode $node ): int {
		return spl_object_id( $node );
	}

	/**
	 * The units of one node (its own units, not its children's), in order.
	 *
	 * @param BlockNode $node Node.
	 * @return list<Unit>
	 */
	public function units_of( BlockNode $node ): array {
		$out = [];

		foreach ( $this->by_node[ self::node_key( $node ) ] ?? [] as $i ) {
			$out[] = $this->units[ $i ];
		}

		return $out;
	}

	/**
	 * The flags of one node.
	 *
	 * @param BlockNode $node Node.
	 * @return list<string>
	 */
	public function flags_of( BlockNode $node ): array {
		return $this->flags[ self::path_key( $node ) ] ?? [];
	}

	/**
	 * Whether a node is protected, itself or through an ancestor.
	 *
	 * @param BlockNode $node Node.
	 * @return bool
	 */
	public function is_protected( BlockNode $node ): bool {
		if ( $this->protected === [] ) {
			return false;
		}

		$key = '';

		foreach ( $node->path->indexes() as $index ) {
			$key = $key === '' ? (string) $index : $key . '.' . $index;

			if ( isset( $this->protected[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the units are disjoint: no two range units or mirrors share a byte, no two JSON leaves a node and path.
	 *
	 * @return bool
	 */
	public function disjoint(): bool {
		$ranges = [];
		$leaves = [];

		foreach ( $this->units as $unit ) {
			if ( $unit->range !== null ) {
				$ranges[] = $unit->range;
			}

			if ( $unit->mirror !== null ) {
				$ranges[] = $unit->mirror['range'];
			}

			if ( $unit->kind === Unit::KIND_JSON && $unit->node !== null ) {
				$leaf = self::path_key( $unit->node ) . "\0" . implode( "\0", $unit->path );

				if ( isset( $leaves[ $leaf ] ) ) {
					return false;
				}

				$leaves[ $leaf ] = true;
			}
		}

		usort( $ranges, static fn( array $a, array $b ): int => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0] );

		$last     = 0;
		$previous = null;

		foreach ( $ranges as $range ) {
			[ $start, $end ] = $range;

			if ( $end < $start || $start < $last || ( $start === $end && $range === $previous ) ) {
				return false;
			}

			$last     = max( $last, $end );
			$previous = $range;
		}

		return true;
	}
}
