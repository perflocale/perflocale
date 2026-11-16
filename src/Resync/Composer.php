<?php
/**
 * Composition of a translation from byte ranges.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles the new translation of one content field as a three-way merge from byte ranges, in the new source's order.
 * Only a comment JSON whose leaf changed and an attribute value that cannot be reused raw are re-serialized; when the
 * base equals the source and aligns one to one with the translation, the output is the translation byte for byte.
 *
 * @phpstan-type Level array{elements: list<BlockNode>, gaps: list<array{int, int}>}
 * @phpstan-type Edit array{int, int, string}
 * @phpstan-type Prev array{n: BlockNode|null, b: BlockNode|null, t: BlockNode|null}
 */
final class Composer {

	/** A per-language exception the engine could not honour, or a conflict. */
	public const FLAG_EXCEPTIONS = 'exceptions';
	/** A translation-only block with no anchor in the new source. */
	public const FLAG_ANCHOR = 'anchor';
	/** A machine output refused by the verifier. */
	public const FLAG_MT_QUALITY = 'mt_quality';
	/** Text the engine does not treat as a unit, or a region it could not place. */
	public const FLAG_STRUCTURE = Segmenter::FLAG_STRUCTURE;

	/** The flag key of the document itself. */
	public const DOCUMENT_KEY = '';

	/** Stands for a unit's text in a shell; a private-use character no document holds. */
	private const PLACEHOLDER = "\u{E000}";

	/** Comments and tags of an HTML fragment; group 2 is the tag name, unset for a comment. */
	private const TAG_PATTERN = '/<!--.*?-->|<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s';

	/** The attributes of a tag: name, then a double-quoted, single-quoted or bare value. */
	private const ATTRIBUTE_PATTERN = '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';

	/** JSON path key core writes on Site Editor saves and canon_json() leaves out: metadata > ignoredHookedBlocks. */
	private const VOLATILE_PATH = 'o8:metadatao19:ignoredHookedBlocks';

	private const SIDE_N = 'n';
	private const SIDE_B = 'b';
	private const SIDE_T = 't';

	/** The whitespace that may differ between blocks without a change: space, tab, CR and LF. */
	private const GAP_WHITESPACE = " \t\r\n";

	/**
	 * Index of every source unit in {@see Matching::$units}, by unit id.
	 *
	 * @var array<int, int>
	 */
	private array $unit_index = [];

	/**
	 * The translation's units, by unit id.
	 *
	 * @var array<int, true>
	 */
	private array $t_unit_ids = [];

	/**
	 * Per side, the parent id and position of every element.
	 *
	 * @var array<string, array<int, array{int, int}>>
	 */
	private array $positions = [];

	/**
	 * Per side, the level of every parent (0 for the document).
	 *
	 * @var array<string, array<int, Level>>
	 */
	private array $levels = [];

	/**
	 * Per side, the first and last unit-list index of every node's subtree, by node id; built per side on first use.
	 * Units are appended in document order, so a subtree's units are one contiguous run; no units, no entry.
	 *
	 * @var array<string, array<int, array{int, int}>>
	 */
	private array $spans = [];

	/**
	 * Flags per source block path key.
	 *
	 * @var array<string, list<string>>
	 */
	private array $flags = [];

	/**
	 * The planned block tree.
	 *
	 * @var list<array{int, string}>
	 */
	private array $blocks = [];

	/**
	 * The planned unit texts.
	 *
	 * @var list<string>
	 */
	private array $units = [];

	/**
	 * The source block each planned block came from.
	 *
	 * @var list<string|null>
	 */
	private array $origins = [];

	/**
	 * Depth of the blocks being emitted.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * The translation nodes copied whole into the output, by node id.
	 *
	 * @var array<int, true>
	 */
	private array $copied = [];

	/**
	 * Whether the new source differs from the base only in whitespace between blocks.
	 *
	 * @var bool
	 */
	private bool $gaps_only = false;

	/**
	 * Unit counts.
	 *
	 * @var array<string, int>
	 */
	private array $counts = [
		Matcher::STATUS_REUSED                 => 0,
		Matcher::SAME_AS_SOURCE                => 0,
		Matcher::STATUS_FUZZY                  => 0,
		Matcher::STATUS_CHANGED                => 0,
		Matcher::STATUS_NEW                    => 0,
		Matcher::STATUS_REMOVED_IN_TRANSLATION => 0,
		'left_source'                          => 0,
		'machine_translated'                   => 0,
	];

	/**
	 * Constructor.
	 *
	 * @param Segmentation       $n         The new source.
	 * @param Segmentation       $b         The base.
	 * @param Segmentation       $t         The translation.
	 * @param Alignment          $alignment Base-to-translation alignment.
	 * @param Matching           $matching  Source-to-base matching.
	 * @param array<int, string> $texts     Machine outputs per index in {@see Matching::$units}.
	 * @param bool               $keep      `resync_keep_exceptions`.
	 */
	private function __construct(
		private readonly Segmentation $n,
		private readonly Segmentation $b,
		private readonly Segmentation $t,
		private readonly Alignment $alignment,
		private readonly Matching $matching,
		private readonly array $texts,
		private readonly bool $keep
	) {
		foreach ( $matching->units as $i => $match ) {
			$this->unit_index[ spl_object_id( $match->unit ) ] = $i;
		}

		foreach ( $t->units as $unit ) {
			$this->t_unit_ids[ spl_object_id( $unit ) ] = true;
		}

		$this->flags     = $n->flags;
		$this->gaps_only = self::whitespace_only( $b->tree, $n->tree );
		$this->index( self::SIDE_N, $n );
		$this->index( self::SIDE_B, $b );
		$this->index( self::SIDE_T, $t );
	}

	/**
	 * Compose the translation of a content field.
	 *
	 * @param Segmentation       $source      The new source, segmented.
	 * @param Segmentation       $base        The base, segmented.
	 * @param Segmentation       $translation The translation, segmented.
	 * @param Alignment          $alignment   {@see Aligner::align()} of base and translation.
	 * @param Matching           $matching    {@see Matcher::match()} of source and base.
	 * @param array<int, string> $texts       Machine outputs per index in {@see Matching::$units}; a `changed` or `new` unit without one keeps the source's text.
	 * @param bool               $keep        `resync_keep_exceptions`.
	 * @return Composition
	 * @throws ComposeError When two edits overlap.
	 */
	public static function compose( Segmentation $source, Segmentation $base, Segmentation $translation, Alignment $alignment, Matching $matching, array $texts = [], bool $keep = true ): Composition {
		$run     = new self( $source, $base, $translation, $alignment, $matching, $texts, $keep );
		$content = $run->children( null, null, null, true );

		$run->unplaced();

		return new Composition( $content, $run->flags, $run->blocks, $run->units, $run->origins, $run->counts );
	}

	/**
	 * The shell of a node: name, leaf or container, attribute JSON and normalised shell chunks, every unit replaced by
	 * a placeholder.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return string
	 */
	public static function shell( BlockNode $node, Segmentation $segmentation ): string {
		$units = self::shell_units( $node, $segmentation );
		$attrs = $node->attrs;

		if ( is_array( $attrs ) ) {
			foreach ( $units as $unit ) {
				if ( $unit->kind === Unit::KIND_JSON ) {
					self::set_leaf( $attrs, $unit->path, self::PLACEHOLDER, false );
				}
			}
		}

		$parts = [ (string) $node->name, count( $node->chunks ) > 1 ? 'container' : 'leaf', Keys::canon_json( is_array( $attrs ) ? $attrs : null ) ];

		foreach ( self::shell_chunks( $node ) as $chunk ) {
			$parts[] = Keys::norm( self::blank( $segmentation->tree, $chunk, $units, self::PLACEHOLDER ) );
		}

		return implode( "\0", $parts );
	}

	/**
	 * The text of a node's shell outside its units: tags and comments stripped, entities decoded, whitespace collapsed.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return string
	 */
	public static function residual( BlockNode $node, Segmentation $segmentation ): string {
		$units = self::shell_units( $node, $segmentation );
		$text  = '';

		foreach ( self::shell_chunks( $node ) as $chunk ) {
			$text .= ' ' . self::blank( $segmentation->tree, $chunk, $units, '' );
		}

		$text = preg_replace( self::TAG_PATTERN, ' ', $text ) ?? $text;
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Merge class tokens: the tokens the source added or removed relative to the base are applied to the translation's.
	 *
	 * @param string $base        The base's class value.
	 * @param string $source      The source's class value.
	 * @param string $translation The translation's class value.
	 * @return string
	 */
	public static function merge_class( string $base, string $source, string $translation ): string {
		$b = self::tokens( $base );
		$n = self::tokens( $source );
		$t = self::tokens( $translation );

		$removed = array_diff( $b, $n );
		$added   = array_diff( $n, $b );
		$out     = [];

		foreach ( $t as $token ) {
			if ( ! in_array( $token, $removed, true ) ) {
				$out[] = $token;
			}
		}

		foreach ( $added as $token ) {
			if ( ! in_array( $token, $out, true ) ) {
				$out[] = $token;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * The whitespace-separated tokens of a class value.
	 *
	 * @param string $value Value.
	 * @return list<string>
	 */
	private static function tokens( string $value ): array {
		$tokens = preg_split( '/\s+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );

		return $tokens === false ? [] : $tokens;
	}

	// ---- levels and positions ----

	/**
	 * Index the elements and gaps of every level of a side.
	 *
	 * @param string       $side         Side.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return void
	 */
	private function index( string $side, Segmentation $segmentation ): void {
		$this->levels[ $side ]    = [];
		$this->positions[ $side ] = [];
		$this->index_level( $side, $segmentation, null );
	}

	/**
	 * Index one level and the levels below it.
	 *
	 * @param string         $side         Side.
	 * @param Segmentation   $segmentation Its segmentation.
	 * @param BlockNode|null $owner        The parent block; null for the document.
	 * @return void
	 */
	private function index_level( string $side, Segmentation $segmentation, ?BlockNode $owner ): void {
		$level = self::level( $segmentation, $owner );
		$pid   = $owner === null ? 0 : spl_object_id( $owner );

		$this->levels[ $side ][ $pid ] = $level;

		foreach ( $level['elements'] as $i => $node ) {
			$this->positions[ $side ][ spl_object_id( $node ) ] = [ $pid, $i ];

			if ( $node->children !== [] ) {
				$this->index_level( $side, $segmentation, $node );
			}
		}
	}

	/**
	 * The elements of a level and the gaps around them; a container's first and last chunks are its shell, the others
	 * its separators.
	 *
	 * @param Segmentation   $segmentation Segmentation.
	 * @param BlockNode|null $owner        The parent block; null for the document.
	 * @return array<string, mixed>
	 * @phpstan-return Level
	 */
	private static function level( Segmentation $segmentation, ?BlockNode $owner ): array {
		if ( $owner !== null ) {
			return [
				'elements' => $owner->children,
				'gaps'     => $owner->chunks,
			];
		}

		$elements = Aligner::elements( $segmentation->tree->nodes, $segmentation );
		$gaps     = [];
		$cursor   = 0;

		foreach ( $elements as $element ) {
			$gaps[] = [ $cursor, $element->start ];
			$cursor = $element->end;
		}

		$gaps[] = [ $cursor, strlen( $segmentation->tree->document ) ];

		return [
			'elements' => $elements,
			'gaps'     => $gaps,
		];
	}

	/**
	 * The segmentation of a side.
	 *
	 * @param string $side Side.
	 * @return Segmentation
	 */
	private function seg( string $side ): Segmentation {
		return match ( $side ) {
			self::SIDE_B => $this->b,
			self::SIDE_T => $this->t,
			default      => $this->n,
		};
	}

	/**
	 * Whether `$next` is the sibling right after `$node` on a side.
	 *
	 * @param BlockNode $node Node.
	 * @param BlockNode $next Candidate.
	 * @param string    $side Side.
	 * @return bool
	 */
	private function next_sibling( BlockNode $node, BlockNode $next, string $side ): bool {
		$a = $this->positions[ $side ][ spl_object_id( $node ) ] ?? null;
		$b = $this->positions[ $side ][ spl_object_id( $next ) ] ?? null;

		return $a !== null && $b !== null && $a[0] === $b[0] && $b[1] === $a[1] + 1;
	}

	/**
	 * The gap range before an element on a side; null for an element the index does not know.
	 *
	 * @param BlockNode $node Element.
	 * @param string    $side Side.
	 * @return array{int, int}|null
	 */
	private function gap_before( BlockNode $node, string $side ): ?array {
		$position = $this->positions[ $side ][ spl_object_id( $node ) ] ?? null;

		return $position === null ? null : $this->levels[ $side ][ $position[0] ]['gaps'][ $position[1] ];
	}

	/**
	 * The gap range after an element on a side; null for an element the index does not know.
	 *
	 * @param BlockNode $node Element.
	 * @param string    $side Side.
	 * @return array{int, int}|null
	 */
	private function gap_after( BlockNode $node, string $side ): ?array {
		$position = $this->positions[ $side ][ spl_object_id( $node ) ] ?? null;

		return $position === null ? null : ( $this->levels[ $side ][ $position[0] ]['gaps'][ $position[1] + 1 ] ?? null );
	}

	/**
	 * The bytes of a gap before an element on a side.
	 *
	 * @param BlockNode $node Element.
	 * @param string    $side Side.
	 * @return string
	 */
	private function gap_bytes( BlockNode $node, string $side ): string {
		$gap = $this->gap_before( $node, $side );

		return $gap === null ? '' : $this->seg( $side )->tree->slice( $gap );
	}

	/**
	 * Whether a node is an element of a level.
	 *
	 * @param BlockNode $node  Node.
	 * @param string    $side  Side.
	 * @param int       $owner The parent's id (0 for the document).
	 * @return bool
	 */
	private function in_level( BlockNode $node, string $side, int $owner ): bool {
		$position = $this->positions[ $side ][ spl_object_id( $node ) ] ?? null;

		return $position !== null && $position[0] === $owner;
	}

	// ---- levels: children, separators, regions, anchors ----

	/**
	 * Compose the children of a level in the source's order with separators, translation-only blocks and removals;
	 * for the document also the leading and trailing chunks.
	 *
	 * @param BlockNode|null $np   Source parent; null for the document.
	 * @param BlockNode|null $bp   Base parent.
	 * @param BlockNode|null $tp   Translation parent.
	 * @param bool           $root Whether this is the document level.
	 * @return string
	 */
	private function children( ?BlockNode $np, ?BlockNode $bp, ?BlockNode $tp, bool $root ): string {
		$nl = $this->levels[ self::SIDE_N ][ $np === null ? 0 : spl_object_id( $np ) ] ?? self::level( $this->n, $np );
		$bl = $bp === null && ! $root ? null : ( $this->levels[ self::SIDE_B ][ $bp === null ? 0 : spl_object_id( $bp ) ] ?? null );
		$tl = $tp === null && ! $root ? null : ( $this->levels[ self::SIDE_T ][ $tp === null ? 0 : spl_object_id( $tp ) ] ?? null );

		$anchored = $this->anchors( $np, $nl, $tl );
		$out      = '';

		if ( $root ) {
			$out .= $this->edge_gap( $nl, $bl, $tl, 0 );
		}

		$prev     = null;
		$suppress = false;
		$count    = count( $nl['elements'] );

		foreach ( $nl['elements'] as $i => $n ) {
			foreach ( $anchored['before'][ spl_object_id( $n ) ] ?? [] as $t ) {
				$gap = $this->gap_bytes( $t, self::SIDE_T );

				if ( $this->first_in_level( $t, self::SIDE_T ) ) {
					$gap = $prev === null ? '' : $this->n->tree->slice( $nl['gaps'][ $i ] );
				}

				$out .= $gap . $this->whole( $t, self::SIDE_T, null );
				$prev = [
					'n' => null,
					'b' => null,
					't' => $t,
				];
			}

			$freeform = $i > 0 && $np !== null && ( $this->n->chunks[ Segmentation::path_key( $np ) ][ $i ] ?? Segmenter::CHUNK_SEPARATOR ) === Segmenter::CHUNK_FREEFORM;

			if ( $this->stays_removed( $n ) ) {
				foreach ( $this->subtree_units( $this->n, $n ) as $unit ) {
					$this->count_match( $unit );
				}

				if ( $freeform ) {
					$out .= $this->region_beside_removal( $np, $nl, $i, $prev !== null && $this->emits_after( $nl, $i, $anchored ) );
				}

				if ( $i === 0 ) {
					$suppress = true;
				}

				continue;
			}

			if ( $freeform ) {
				$out     .= $suppress ? $this->region_beside_removal( $np, $nl, $i, false ) : $this->region( $np, $bp, $tp, $nl, $i );
				$suppress = false;
			} elseif ( $suppress ) {
				$suppress = false;
			} elseif ( $i > 0 ) {
				$out .= $this->separator( $prev, $n, $this->n->tree->slice( $nl['gaps'][ $i ] ) );
			} elseif ( $prev !== null ) {
				$out .= $this->separator( $prev, $n, '' );
			}

			$out .= $this->block( $n );
			$prev = [
				'n' => $n,
				'b' => $this->matching->base_of( $n ),
				't' => $this->translation_of( $n ),
			];

			foreach ( $anchored['after'][ spl_object_id( $n ) ] ?? [] as $t ) {
				$out .= $this->gap_bytes( $t, self::SIDE_T ) . $this->whole( $t, self::SIDE_T, null );
				$prev = [
					'n' => null,
					'b' => null,
					't' => $t,
				];
			}
		}

		foreach ( $anchored['end'] as $t ) {
			$gap = $this->gap_bytes( $t, self::SIDE_T );

			if ( $this->first_in_level( $t, self::SIDE_T ) ) {
				$after = $this->gap_after( $t, self::SIDE_T );
				$gap   = $prev === null || $after === null || $this->is_last_in_level( $t, self::SIDE_T ) ? '' : $this->t->tree->slice( $after );
			}

			$out .= $gap . $this->whole( $t, self::SIDE_T, null );
			$prev = [
				'n' => null,
				'b' => null,
				't' => $t,
			];
		}

		if ( $root && $count > 0 ) {
			$out .= $this->edge_gap( $nl, $bl, $tl, $count );
		}

		return $out;
	}

	/**
	 * Whether a node is the first element of its level; the level's leading chunk is the separator before it.
	 *
	 * @param BlockNode $node Node.
	 * @param string    $side Side.
	 * @return bool
	 */
	private function first_in_level( BlockNode $node, string $side ): bool {
		return ( $this->positions[ $side ][ spl_object_id( $node ) ][1] ?? null ) === 0;
	}

	/**
	 * Whether a node is the last element of its level; the level's trailing chunk is the bytes after it.
	 *
	 * @param BlockNode $node Node.
	 * @param string    $side Side.
	 * @return bool
	 */
	private function is_last_in_level( BlockNode $node, string $side ): bool {
		$position = $this->positions[ $side ][ spl_object_id( $node ) ] ?? null;

		return $position === null || $position[1] === count( $this->levels[ $side ][ $position[0] ]['elements'] ) - 1;
	}

	/**
	 * The document's leading or trailing chunk: the translation's when the source's equals the base's, else the source's.
	 *
	 * @param array<string, mixed>      $nl    Source level.
	 * @param array<string, mixed>|null $bl    Base level.
	 * @param array<string, mixed>|null $tl    Translation level.
	 * @param int                       $index 0 for the leading gap, the element count for the trailing one.
	 * @return string
	 * @phpstan-param Level $nl
	 * @phpstan-param Level|null $bl
	 * @phpstan-param Level|null $tl
	 */
	private function edge_gap( array $nl, ?array $bl, ?array $tl, int $index ): string {
		$n_bytes = $this->n->tree->slice( $nl['gaps'][ $index ] );

		if ( $bl === null || $tl === null ) {
			return $n_bytes;
		}

		$b_gap = $index === 0 ? $bl['gaps'][0] : $bl['gaps'][ count( $bl['elements'] ) ];
		$t_gap = $index === 0 ? $tl['gaps'][0] : $tl['gaps'][ count( $tl['elements'] ) ];

		return $this->same_gap( $this->b->tree->slice( $b_gap ), $n_bytes ) ? $this->t->tree->slice( $t_gap ) : $n_bytes;
	}

	/**
	 * The separator before a source block: the translation's when the output neighbours' translation blocks are adjacent
	 * and the previous is translation-only, or their base blocks are adjacent and the source kept the base's separator
	 * ({@see self::same_gap()}); else the source's.
	 *
	 * @param array<string, mixed>|null $prev    The previous output block.
	 * @param BlockNode                 $n       The source block.
	 * @param string                    $n_bytes The source's separator before it.
	 * @return string
	 * @phpstan-param Prev|null $prev
	 */
	private function separator( ?array $prev, BlockNode $n, string $n_bytes ): string {
		$t = $this->translation_of( $n );

		if ( $prev === null || $prev['t'] === null || $t === null || ! $this->next_sibling( $prev['t'], $t, self::SIDE_T ) ) {
			return $n_bytes;
		}

		if ( $prev['b'] === null ) {
			return $this->gap_bytes( $t, self::SIDE_T );
		}

		$b = $this->matching->base_of( $n );

		if ( $b !== null && $this->next_sibling( $prev['b'], $b, self::SIDE_B ) && $this->same_gap( $this->gap_bytes( $b, self::SIDE_B ), $n_bytes ) ) {
			return $this->gap_bytes( $t, self::SIDE_T );
		}

		return $n_bytes;
	}

	/**
	 * Whether the source kept the base's bytes between two blocks: equal bytes, or equal after trimming edge whitespace
	 * when the whole source differs only in such whitespace, so a re-save with other line endings composes unchanged.
	 *
	 * @param string $b_bytes The base's bytes.
	 * @param string $n_bytes The source's bytes.
	 * @return bool
	 */
	private function same_gap( string $b_bytes, string $n_bytes ): bool {
		return $b_bytes === $n_bytes || ( $this->gaps_only && trim( $b_bytes, self::GAP_WHITESPACE ) === trim( $n_bytes, self::GAP_WHITESPACE ) );
	}

	/**
	 * Whether a source differs from its base only in whitespace a resync ignores: between blocks, at document edges, in
	 * delimiters outside the attribute JSON, and in HTML outside byte-exact regions ({@see Keys::exact()}). Deeper than
	 * {@see BlockScanner::MAX_DEPTH} compares byte for byte; an unsupported tree answers false.
	 *
	 * @param BlockTree $base   The base.
	 * @param BlockTree $source The new source.
	 * @return bool
	 */
	public static function whitespace_only( BlockTree $base, BlockTree $source ): bool {
		if ( ! $base->supported || ! $source->supported ) {
			return false;
		}

		return self::same_nodes( $base, self::without_blank( $base ), $source, self::without_blank( $source ), 0 );
	}

	/**
	 * The top-level nodes of a tree without the freeform runs that hold only whitespace.
	 *
	 * @param BlockTree $tree Tree.
	 * @return list<BlockNode>
	 */
	private static function without_blank( BlockTree $tree ): array {
		$out = [];

		foreach ( $tree->nodes as $node ) {
			$bytes = $tree->slice( $node->range() );

			if ( ! $node->is_freeform() || strspn( $bytes, self::GAP_WHITESPACE ) !== strlen( $bytes ) ) {
				$out[] = $node;
			}
		}

		return $out;
	}

	/**
	 * Whether two lists of sibling nodes differ only in whitespace between blocks.
	 *
	 * @param BlockTree             $bt    The base's tree.
	 * @param array<int, BlockNode> $bs    The base's nodes.
	 * @param BlockTree             $nt    The source's tree.
	 * @param array<int, BlockNode> $ns    The source's nodes.
	 * @param int                   $depth The nodes' depth (0 for the top level).
	 * @return bool
	 */
	private static function same_nodes( BlockTree $bt, array $bs, BlockTree $nt, array $ns, int $depth ): bool {
		$bs = array_values( $bs );
		$ns = array_values( $ns );

		if ( count( $bs ) !== count( $ns ) ) {
			return false;
		}

		foreach ( $bs as $i => $b ) {
			$n = $ns[ $i ];

			if ( $depth >= BlockScanner::MAX_DEPTH ) {
				if ( $bt->slice( $b->range() ) !== $nt->slice( $n->range() ) ) {
					return false;
				}

				continue;
			}

			if ( count( $b->chunks ) !== count( $n->chunks ) || self::delimiters( $bt, $b ) !== self::delimiters( $nt, $n ) ) {
				return false;
			}

			foreach ( $b->chunks as $k => $chunk ) {
				if ( ! self::same_chunk( $bt->slice( $chunk ), $nt->slice( $n->chunks[ $k ] ) ) ) {
					return false;
				}
			}

			if ( ! self::same_nodes( $bt, $b->children, $nt, $n->children, $depth + 1 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether two own chunks of a block are equal but for ignorable whitespace: equal byte-exact regions
	 * ({@see Keys::exact()}) and equal bytes with whitespace runs collapsed.
	 *
	 * @param string $b_bytes The base's chunk.
	 * @param string $n_bytes The source's chunk.
	 * @return bool
	 */
	private static function same_chunk( string $b_bytes, string $n_bytes ): bool {
		if ( $b_bytes === $n_bytes ) {
			return true;
		}

		$loose = static fn ( string $bytes ): string => trim( (string) preg_replace( '/[ \t\r\n\f]+/', ' ', $bytes ) );

		return Keys::exact( $b_bytes ) === Keys::exact( $n_bytes ) && $loose( $b_bytes ) === $loose( $n_bytes );
	}

	/**
	 * A node's opener and closer without whitespace outside the attribute JSON, which stays byte for byte ('' for freeform).
	 *
	 * @param BlockTree $tree Tree.
	 * @param BlockNode $node Node.
	 * @return string
	 */
	private static function delimiters( BlockTree $tree, BlockNode $node ): string {
		$strip = static fn ( string $bytes ): string => str_replace( [ ' ', "\t", "\r", "\n" ], '', $bytes );
		$open  = $node->open;

		if ( $open === null ) {
			return '';
		}

		$json   = $node->attrs_json;
		$opener = $json === null
			? $strip( $tree->slice( $open ) )
			: $strip( $tree->slice( [ $open[0], $json[0] ] ) ) . "\0" . $tree->slice( $json ) . "\0" . $strip( $tree->slice( [ $json[1], $open[1] ] ) );

		return $opener . "\0" . ( $node->close === null ? '' : $strip( $tree->slice( $node->close ) ) );
	}

	/**
	 * A non-whitespace middle chunk of a container. With neighbours aligned on all sides: the translation's chunk if the
	 * source kept the base's, else the source's with slots filled if it fits; a misfit flags `exceptions` and keeps the
	 * translation's (source's when exceptions are off). Unaligned: the source's, flagged if the nearby chunks drifted.
	 *
	 * @param BlockNode            $np Source container.
	 * @param BlockNode|null       $bp Base container.
	 * @param BlockNode|null       $tp Translation container.
	 * @param array<string, mixed> $nl Source level.
	 * @param int                  $i  Chunk index.
	 * @return string
	 * @phpstan-param Level $nl
	 */
	private function region( BlockNode $np, ?BlockNode $bp, ?BlockNode $tp, array $nl, int $i ): string {
		$chunk   = $nl['gaps'][ $i ];
		$n_bytes = $this->n->tree->slice( $chunk );
		$n_units = $this->units_in( $this->n, $np, $chunk );

		$n_prev = $nl['elements'][ $i - 1 ];
		$n_cur  = $nl['elements'][ $i ];
		$b_prev = $this->matching->base_of( $n_prev );
		$b_cur  = $this->matching->base_of( $n_cur );
		$t_prev = $this->translation_of( $n_prev );
		$t_cur  = $this->translation_of( $n_cur );

		$b_gap = $b_prev !== null && $b_cur !== null && $this->next_sibling( $b_prev, $b_cur, self::SIDE_B ) ? $this->gap_before( $b_cur, self::SIDE_B ) : null;
		$t_gap = $t_prev !== null && $t_cur !== null && $this->next_sibling( $t_prev, $t_cur, self::SIDE_T ) ? $this->gap_before( $t_cur, self::SIDE_T ) : null;

		if ( $b_gap !== null && $t_gap !== null && $bp !== null && $tp !== null ) {
			if ( $this->b->tree->slice( $b_gap ) === $n_bytes ) {
				foreach ( $n_units as $unit ) {
					$this->text_for( $unit );
				}

				foreach ( $this->units_in( $this->t, $tp, $t_gap ) as $unit ) {
					$this->units[] = $unit->text;
				}

				return $this->t->tree->slice( $t_gap );
			}

			$t_units = $this->units_in( $this->t, $tp, $t_gap );

			if ( ! $this->region_fits( $n_units, $t_units, $this->units_in( $this->b, $bp, $b_gap ), $b_gap, $t_gap ) ) {
				$this->flag( $np, self::FLAG_EXCEPTIONS );

				if ( $this->keep ) {
					foreach ( $n_units as $unit ) {
						$this->count_match( $unit );
					}

					foreach ( $t_units as $unit ) {
						$this->units[] = $unit->text;
					}

					return $this->t->tree->slice( $t_gap );
				}

				foreach ( $n_units as $unit ) {
					++$this->counts['left_source'];
					$this->units[] = $unit->text;
				}

				return $n_bytes;
			}

			$edits = [];

			foreach ( $n_units as $unit ) {
				[ $text, $from ] = $this->slot_text( $unit );
				$edits           = array_merge( $edits, $this->slot_edits( $unit, $text, $from ) );
				$this->units[]   = $this->planned_text( $unit, $text, $from );
			}

			return Splice::apply( $n_bytes, self::relative( $edits, $chunk[0] ) );
		}

		foreach ( $n_units as $unit ) {
			++$this->counts['left_source'];
			$this->units[] = $unit->text;
		}

		$b_near = $b_prev !== null ? $this->gap_after( $b_prev, self::SIDE_B ) : ( $b_cur === null ? null : $this->gap_before( $b_cur, self::SIDE_B ) );
		$t_near = $t_prev !== null ? $this->gap_after( $t_prev, self::SIDE_T ) : ( $t_cur === null ? null : $this->gap_before( $t_cur, self::SIDE_T ) );

		$b_bytes = $b_near === null ? null : $this->b->tree->slice( $b_near );
		$t_bytes = $t_near === null ? null : $this->t->tree->slice( $t_near );

		if ( $b_bytes !== $t_bytes ) {
			$this->flag( $np, self::FLAG_STRUCTURE );
		}

		return $n_bytes;
	}

	/**
	 * Whether a container's freeform chunk can be filled from the source: each translation unit has a certain base unit
	 * inside the base's chunk, each paired base unit's translation is inside the translation's chunk (a container's units
	 * pair across all its chunks), and no source unit matches an unsure base unit.
	 *
	 * @param array<int, Unit> $n_units The source chunk's units.
	 * @param array<int, Unit> $t_units The translation chunk's units.
	 * @param array<int, Unit> $b_units The base chunk's units.
	 * @param array<int, int>  $b_gap   The base chunk's byte range.
	 * @param array<int, int>  $t_gap   The translation chunk's byte range.
	 * @return bool
	 * @phpstan-param list<Unit> $n_units
	 * @phpstan-param list<Unit> $t_units
	 * @phpstan-param list<Unit> $b_units
	 * @phpstan-param array{int, int} $b_gap
	 * @phpstan-param array{int, int} $t_gap
	 */
	private function region_fits( array $n_units, array $t_units, array $b_units, array $b_gap, array $t_gap ): bool {
		foreach ( $t_units as $unit ) {
			$base = $this->alignment->base_unit_of( $unit );

			if ( $base === null || ! self::inside( $base, $b_gap ) ) {
				return false;
			}
		}

		foreach ( $b_units as $unit ) {
			$translation = $this->alignment->translation_unit_of( $unit );

			if ( $translation !== null && ! self::inside( $translation, $t_gap ) ) {
				return false;
			}
		}

		foreach ( $n_units as $unit ) {
			$base = $this->matching->match_of( $unit )?->base;

			if ( $base !== null && $this->alignment->unsure( $base ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a unit's bytes lie within a range of its side.
	 *
	 * @param Unit            $unit  The unit.
	 * @param array<int, int> $range The range.
	 * @return bool
	 * @phpstan-param array{int, int} $range
	 */
	private static function inside( Unit $unit, array $range ): bool {
		return $unit->range !== null && $unit->range[0] >= $range[0] && $unit->range[1] <= $range[1];
	}

	/**
	 * A freeform region next to a block whose output is empty: the source's bytes, units `left_source`, the container
	 * flagged `structure`. It holds units only while it stays a middle chunk; the segmenter gives a shell chunk none.
	 *
	 * @param BlockNode            $np     Source container.
	 * @param array<string, mixed> $nl     Source level.
	 * @param int                  $i      Chunk index.
	 * @param bool                 $middle Whether the region stays a middle chunk of the output.
	 * @return string
	 * @phpstan-param Level $nl
	 */
	private function region_beside_removal( BlockNode $np, array $nl, int $i, bool $middle ): string {
		$chunk = $nl['gaps'][ $i ];

		foreach ( $this->units_in( $this->n, $np, $chunk ) as $unit ) {
			++$this->counts['left_source'];

			if ( $middle ) {
				$this->units[] = $unit->text;
			}
		}

		$this->flag( $np, self::FLAG_STRUCTURE );

		return $this->n->tree->slice( $chunk );
	}

	/**
	 * Whether a level outputs anything after the element at an index: a later kept element or a block anchored after it.
	 *
	 * @param array<string, mixed> $nl       Source level.
	 * @param int                  $i        Element index.
	 * @param array<string, mixed> $anchored The level's anchored blocks ({@see self::anchors()}).
	 * @return bool
	 * @phpstan-param Level $nl
	 * @phpstan-param array{before: array<int, list<BlockNode>>, after: array<int, list<BlockNode>>, end: list<BlockNode>} $anchored
	 */
	private function emits_after( array $nl, int $i, array $anchored ): bool {
		if ( $anchored['end'] !== [] ) {
			return true;
		}

		foreach ( $nl['elements'] as $j => $element ) {
			if ( $j < $i ) {
				continue;
			}

			$id = spl_object_id( $element );

			if ( isset( $anchored['after'][ $id ] ) || ( $j > $i && ( isset( $anchored['before'][ $id ] ) || ! $this->stays_removed( $element ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The translation-only blocks of a level and where they go: after the source block of the preceding aligned sibling,
	 * else before the next one's, else at the parent's end flagged `anchor`. A removed base block protected only in the
	 * translation is kept likewise, flagged `exceptions`; with exceptions off they are dropped and the parent flagged.
	 *
	 * @param BlockNode|null            $np Source parent.
	 * @param array<string, mixed>      $nl Source level.
	 * @param array<string, mixed>|null $tl Translation level.
	 * @return array{before: array<int, list<BlockNode>>, after: array<int, list<BlockNode>>, end: list<BlockNode>}
	 * @phpstan-param Level $nl
	 * @phpstan-param Level|null $tl
	 */
	private function anchors( ?BlockNode $np, array $nl, ?array $tl ): array {
		$out = [
			'before' => [],
			'after'  => [],
			'end'    => [],
		];

		if ( $tl === null ) {
			return $out;
		}

		$n_parent = $np === null ? 0 : spl_object_id( $np );

		foreach ( $tl['elements'] as $j => $t ) {
			$b = $this->alignment->base_of( $t );

			if ( $b === null && $this->alignment->carried( $t ) ) {
				continue;
			}

			if ( $b !== null ) {
				if ( $this->matching->source_of( $b ) !== null || ! $this->t->is_protected( $t ) || $this->b->is_protected( $b ) ) {
					continue;
				}
			}

			if ( ! $this->keep ) {
				$this->flag( $np, self::FLAG_EXCEPTIONS );
				continue;
			}

			if ( $b !== null ) {
				$this->flag( $np, self::FLAG_EXCEPTIONS );
			}

			$place = null;

			for ( $jj = $j - 1; $jj >= 0; $jj-- ) {
				$bs = $this->alignment->base_of( $tl['elements'][ $jj ] );

				if ( $bs === null ) {
					continue;
				}

				$ns = $this->matching->source_of( $bs );

				if ( $ns !== null && $this->in_level( $ns, self::SIDE_N, $n_parent ) ) {
					$place = [ 'after', $ns ];
				}

				break;
			}

			if ( $place === null ) {
				$total = count( $tl['elements'] );

				for ( $jj = $j + 1; $jj < $total; $jj++ ) {
					$bs = $this->alignment->base_of( $tl['elements'][ $jj ] );
					$ns = $bs === null ? null : $this->matching->source_of( $bs );

					if ( $ns !== null && $this->in_level( $ns, self::SIDE_N, $n_parent ) ) {
						$place = [ 'before', $ns ];
						break;
					}
				}
			}

			if ( $place === null ) {
				$this->flag( $np, self::FLAG_ANCHOR );
				$out['end'][] = $t;
				continue;
			}

			$out[ $place[0] ][ spl_object_id( $place[1] ) ][] = $t;
		}

		return $out;
	}

	// ---- blocks ----

	/**
	 * The translation block of a source block, through its base partner.
	 *
	 * @param BlockNode $n Source block.
	 * @return BlockNode|null
	 */
	private function translation_of( BlockNode $n ): ?BlockNode {
		$b = $this->matching->base_of( $n );

		return $b === null ? null : $this->alignment->translation_of( $b );
	}

	/**
	 * Whether a source block's output is empty: a per-language removal (base block without translation block, not
	 * carried by a list of the other shape) the source left identical, while exceptions are kept.
	 *
	 * @param BlockNode $n Source block.
	 * @return bool
	 */
	private function stays_removed( BlockNode $n ): bool {
		$b = $this->matching->base_of( $n );

		return $this->keep && $b !== null && $this->alignment->translation_of( $b ) === null && ! $this->alignment->carried( $b ) && $this->matching->class_of( $n ) === Matcher::CLASS_IDENTICAL;
	}

	/**
	 * Compose one source block; a block whose output is empty ({@see self::stays_removed()}) is never emitted.
	 *
	 * @param BlockNode $n Source block.
	 * @return string
	 */
	private function block( BlockNode $n ): string {
		$b     = $this->matching->base_of( $n );
		$t     = $b === null ? null : $this->alignment->translation_of( $b );
		$class = $b === null ? Matcher::CLASS_NEW : $this->matching->class_of( $n );

		$protected = $this->n->is_protected( $n ) || ( $b !== null && $this->b->is_protected( $b ) ) || ( $t !== null && $this->t->is_protected( $t ) );

		if ( $protected ) {
			if ( $t === null ) {
				return $b === null ? $this->whole( $n, self::SIDE_N, $n ) : $this->removal( $n, $b );
			}

			if ( $class === Matcher::CLASS_IDENTICAL ) {
				return $this->whole( $t, self::SIDE_T, $n );
			}

			if ( Keys::canon( $t, $this->t->tree ) === Keys::canon( $b, $this->b->tree ) && Keys::exact_tree( $t, $this->t->tree ) === Keys::exact_tree( $b, $this->b->tree ) ) {
				return $this->whole( $n, self::SIDE_N, $n );
			}

			$this->flag( $n, self::FLAG_EXCEPTIONS );

			return $this->whole( $t, self::SIDE_T, $n );
		}

		if ( $b === null ) {
			return $this->emit( $n, $n, null, null, [] );
		}

		if ( $t === null ) {
			return $this->removal( $n, $b );
		}

		if ( $this->keep && $class === Matcher::CLASS_IDENTICAL ) {
			$bytes = $this->whole( $t, self::SIDE_T, $n );

			return $n->is_freeform() ? $this->freeform_edges( $bytes, $n, $b ) : $bytes;
		}

		$edits   = [];
		$carrier = $n;

		if ( ! $this->keep ) {
			if ( self::shell( $t, $this->t ) !== self::shell( $b, $this->b ) ) {
				$this->flag( $n, self::FLAG_EXCEPTIONS );
			}
		} else {
			$shell_b = self::shell( $b, $this->b );
			$shell_n = self::shell( $n, $this->n );
			$shell_t = self::shell( $t, $this->t );

			if ( $shell_t !== $shell_b ) {
				if ( $shell_n === $shell_b ) {
					[ $fit, $ambiguous ] = ( count( $n->chunks ) > 1 ) === ( count( $t->chunks ) > 1 ) ? $this->slots_fit( $n, $t ) : [ false, false ];

					if ( $fit ) {
						$carrier = $t;

						if ( $ambiguous ) {
							$this->flag( $n, Aligner::FLAG_AMBIGUOUS );
						}
					} else {
						$this->flag( $n, self::FLAG_EXCEPTIONS );
					}
				} else {
					[ $edits, $conflict ] = $this->three_way( $n, $b, $t );

					if ( $conflict ) {
						$edits = [];
						$this->flag( $n, self::FLAG_EXCEPTIONS );
					}
				}

				// Translator-changed block whose units do not pair one to one and that cannot carry: the translation's bytes stay.
				if ( $carrier === $n && Aligner::pair_across( self::shell_units( $b, $this->b ), self::shell_units( $t, $this->t ) )['ambiguous'] ) {
					$this->flag( $n, self::FLAG_EXCEPTIONS );

					return $this->whole( $t, self::SIDE_T, $n );
				}
			}
		}

		if ( $carrier === $n && ( self::residual( $t, $this->t ) !== self::residual( $b, $this->b ) || $this->repeats_lost( $b, $t ) ) ) {
			$this->flag( $n, self::FLAG_STRUCTURE );
		}

		return $this->emit( $carrier, $n, $b, $t, $edits );
	}

	/**
	 * Whether the translation block holds attribute bytes a source-carried block drops: a shell tag repeats an attribute
	 * name (only the first occurrence is read, as browsers do) and those repeats differ from the base's; flagged only.
	 *
	 * @param BlockNode $b Base block.
	 * @param BlockNode $t Translation block.
	 * @return bool
	 */
	private function repeats_lost( BlockNode $b, BlockNode $t ): bool {
		if ( self::raw_shell( $t, $this->t ) === self::raw_shell( $b, $this->b ) ) {
			return false;
		}

		$tags_t    = self::tags( $t, $this->t );
		$repeats_t = array_column( $tags_t, 'repeats' );

		if ( array_merge( ...$repeats_t ) === [] ) {
			return false;
		}

		$tags_b = self::tags( $b, $this->b );

		return self::tag_names( $tags_t ) !== self::tag_names( $tags_b ) || $repeats_t !== array_column( $tags_b, 'repeats' );
	}

	/**
	 * A node's shell chunks as bytes, units blanked, each chunk prefixed with its length.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return string
	 */
	private static function raw_shell( BlockNode $node, Segmentation $segmentation ): string {
		$units = self::shell_units( $node, $segmentation );
		$out   = '';

		foreach ( self::shell_chunks( $node ) as $chunk ) {
			$bytes = self::blank( $segmentation->tree, $chunk, $units, self::PLACEHOLDER );
			$out  .= strlen( $bytes ) . ':' . $bytes;
		}

		return $out;
	}

	/**
	 * A base block with no translation block whose source changed, or one re-added with exceptions off: the source, flagged.
	 *
	 * @param BlockNode $n Source block.
	 * @param BlockNode $b Its base partner.
	 * @return string
	 */
	private function removal( BlockNode $n, BlockNode $b ): string {
		$this->flag( $n, self::FLAG_EXCEPTIONS );

		return $this->emit( $n, $n, $b, null, [] );
	}

	/**
	 * Whether the translation block can carry the source block's units: each translation slot pairs with a source unit by
	 * role and occurrence, and each unpaired source unit was removed by the translation and kept by the source. On unequal
	 * counts ({@see Aligner::pair_across()}) each pair must agree with the match; an unpaired translation unit has no base.
	 *
	 * @param BlockNode $n Source block.
	 * @param BlockNode $t Translation block.
	 * @return array{bool, bool} Whether it fits, and whether a kind's counts differ.
	 */
	private function slots_fit( BlockNode $n, BlockNode $t ): array {
		$n_units   = self::shell_units( $n, $this->n );
		$t_units   = self::shell_units( $t, $this->t );
		$across    = Aligner::pair_across( $n_units, $t_units );
		$ambiguous = $across['ambiguous'];

		if ( ! $ambiguous && count( $across['pairs'] ) !== count( $t_units ) ) {
			return [ false, false ];
		}

		$paired   = [];
		$t_paired = [];

		foreach ( $across['pairs'] as [ $i, $j ] ) {
			$paired[ $i ]   = true;
			$t_paired[ $j ] = true;

			$translation = $this->matching->match_of( $n_units[ $i ] )?->translation;

			if ( $ambiguous && $translation !== null && $translation !== $t_units[ $j ] ) {
				return [ false, true ];
			}
		}

		foreach ( $t_units as $j => $unit ) {
			if ( ! isset( $t_paired[ $j ] ) && $this->alignment->base_unit_of( $unit ) !== null ) {
				return [ false, $ambiguous ];
			}
		}

		foreach ( $n_units as $i => $unit ) {
			if ( ! isset( $paired[ $i ] ) && ( $this->matching->match_of( $unit )?->status ) !== Matcher::STATUS_REMOVED_IN_TRANSLATION ) {
				return [ false, $ambiguous ];
			}
		}

		return [ true, $ambiguous ];
	}

	/**
	 * A freeform run's translation bytes with each edge's whitespace as edge_gap() picks it: the translation's where the
	 * source kept the base's, else the source's. An identical match trims edges, so it says nothing about them.
	 *
	 * @param string    $t_bytes The translation's run.
	 * @param BlockNode $n       The source run.
	 * @param BlockNode $b       Its base partner.
	 * @return string
	 */
	private function freeform_edges( string $t_bytes, BlockNode $n, BlockNode $b ): string {
		$edges = static function ( string $bytes ): array {
			$lead = strspn( $bytes, self::GAP_WHITESPACE );
			$core = rtrim( $bytes, self::GAP_WHITESPACE );

			return [ substr( $bytes, 0, $lead ), substr( $bytes, max( $lead, strlen( $core ) ) ), $lead < strlen( $core ) ? substr( $core, $lead ) : '' ];
		};

		[ $t_lead, $t_trail, $t_core ] = $edges( $t_bytes );

		if ( '' === $t_core ) {
			return $t_bytes;
		}

		[ $n_lead, $n_trail ] = $edges( $this->n->tree->slice( $n->range() ) );
		[ $b_lead, $b_trail ] = $edges( $this->b->tree->slice( $b->range() ) );

		return ( $this->same_gap( $b_lead, $n_lead ) ? $t_lead : $n_lead ) . $t_core . ( $this->same_gap( $b_trail, $n_trail ) ? $t_trail : $n_trail );
	}

	/**
	 * A whole node copied from one side: its bytes, with its subtree's blocks and units added to the plan.
	 *
	 * @param BlockNode      $node Node.
	 * @param string         $side Side.
	 * @param BlockNode|null $n    The source block the node stands for; null for a translation-only block.
	 * @return string
	 */
	private function whole( BlockNode $node, string $side, ?BlockNode $n ): string {
		$seg = $this->seg( $side );

		foreach ( Verifier::blocks( [ $node ], $this->depth ) as $row ) {
			$this->blocks[] = $row;
		}

		if ( $side === self::SIDE_T ) {
			$this->copied[ spl_object_id( $node ) ] = true;
		}

		foreach ( self::subtree_nodes( $node ) as $sub ) {
			$this->origins[] = $this->origin_of( $sub, $side, $sub === $node ? $n : null );

			if ( $side === self::SIDE_T ) {
				$this->copied[ spl_object_id( $sub ) ] = true;
			}
		}

		foreach ( $this->subtree_units( $seg, $node ) as $unit ) {
			$this->units[] = $unit->text;
		}

		if ( $n !== null ) {
			foreach ( $this->subtree_units( $this->n, $n ) as $unit ) {
				$this->count_match( $unit );
			}
		}

		return $seg->tree->slice( $node->range() );
	}

	/**
	 * The source path key an output block came from: its own, or for a translation block its base's source partner's.
	 *
	 * @param BlockNode      $node Node.
	 * @param string         $side Side.
	 * @param BlockNode|null $n    The source block it stands for, when known.
	 * @return string|null
	 */
	private function origin_of( BlockNode $node, string $side, ?BlockNode $n ): ?string {
		if ( $n !== null ) {
			return Segmentation::path_key( $n );
		}

		if ( $side === self::SIDE_N ) {
			return Segmentation::path_key( $node );
		}

		if ( $side === self::SIDE_T ) {
			$b  = $this->alignment->base_of( $node );
			$ns = $b === null ? null : $this->matching->source_of( $b );

			return $ns === null ? null : Segmentation::path_key( $ns );
		}

		return null;
	}

	/**
	 * Emit a block from a carrier: its opener (JSON re-encoded when a leaf changed), its shell chunks with slots filled
	 * and merged values applied, its children composed in the source's order, and its closer.
	 *
	 * @param BlockNode                                                                    $c     Carrier: the source block or its translation block.
	 * @param BlockNode                                                                    $n     Source block.
	 * @param BlockNode|null                                                               $b     Base partner.
	 * @param BlockNode|null                                                               $t     Translation block.
	 * @param array{json?: list<array{list<int|string>, mixed, bool}>, attrs?: list<Edit>} $edits Merged values, on the source's bytes: a JSON leaf's path, value and whether it is removed.
	 * @return string
	 */
	private function emit( BlockNode $c, BlockNode $n, ?BlockNode $b, ?BlockNode $t, array $edits ): string {
		$side  = $c === $n ? self::SIDE_N : self::SIDE_T;
		$seg   = $this->seg( $side );
		$tree  = $seg->tree;
		$slots = $this->slots( $c, $n, $side );

		$json_slots  = [];
		$range_edits = [];
		$plan        = [];

		foreach ( $slots as [ $slot, $text, $from ] ) {
			foreach ( $this->slot_edits( $slot, $text, $from ) as $edit ) {
				$range_edits[] = $edit;
			}

			if ( $slot->kind === Unit::KIND_JSON ) {
				$json_slots[] = [ $slot, $text ];
				$plan[]       = [ $slot, $text ];
				continue;
			}

			$plan[] = [ $slot, $this->planned_text( $slot, $text, $from ) ];
		}

		foreach ( $edits['attrs'] ?? [] as $edit ) {
			$range_edits[] = $edit;
		}

		$open = $c->open === null ? '' : $tree->slice( $c->open );

		if ( $c->attrs_json !== null && ( $json_slots !== [] || ( $edits['json'] ?? [] ) !== [] ) ) {
			$decoded = BlockJson::decode( $tree->slice( $c->attrs_json ) );

			if ( $decoded === null ) {
				$this->flag( $n, self::FLAG_STRUCTURE );
			} else {
				$changed = false;

				foreach ( $json_slots as [ $slot, $text ] ) {
					if ( self::get_leaf( $decoded, $slot->path ) !== $text ) {
						self::set_leaf( $decoded, $slot->path, $text, false );
						$changed = true;
					}
				}

				foreach ( $edits['json'] ?? [] as [ $path, $value, $remove ] ) {
					self::set_leaf( $decoded, $path, $value, $remove );
					$changed = true;
				}

				if ( ( $edits['json'] ?? [] ) !== [] ) {
					self::reindex( $decoded );
				}

				if ( $changed && $c->open !== null && $decoded instanceof \stdClass ) {
					$open = Splice::apply( $open, [ [ $c->attrs_json[0] - $c->open[0], $c->attrs_json[1] - $c->open[0], BlockJson::encode( $decoded ) ] ] );
				}
			}
		} elseif ( $c->open !== null && ( $edits['json'] ?? [] ) !== [] ) {
			// An opener without JSON (the source dropped its last attribute): the edits go into a new object after the name.
			$added = new \stdClass();

			foreach ( $edits['json'] as [ $path, $value, $remove ] ) {
				self::set_leaf( $added, $path, $value, $remove );
			}

			self::reindex( $added );

			if ( $added instanceof \stdClass && [] !== get_object_vars( $added ) ) {
				if ( 1 === preg_match( '#^<!--\s+wp:(?:[a-z][a-z0-9_-]*/)?[a-z][a-z0-9_-]*(?=\s)#', $open, $name ) ) {
					$open = $name[0] . ' ' . BlockJson::encode( $added ) . substr( $open, strlen( $name[0] ) );
				} else {
					$this->flag( $n, self::FLAG_STRUCTURE );
				}
			}
		}

		if ( $c->name !== null ) {
			$this->blocks[]  = [ $this->depth, $c->name ];
			$this->origins[] = Segmentation::path_key( $n );
		}

		$chunks = $c->chunks;
		$count  = count( $chunks );
		$body   = '';
		$kept   = $count === 1 && $c === $n && $t !== null && $t->children !== [] ? $this->kept_in_empty( $n, $t ) : [];
		$split  = $kept === [] || $t === null ? null : $this->inner_end( $chunks[0], $t, $plan );

		foreach ( $plan as [ $slot, $text ] ) {
			if ( $slot->range === null || ( $count < 2 ? $split === null || $slot->range[0] < $split : $slot->range[0] < $chunks[ $count - 1 ][0] ) ) {
				$this->units[] = $text;
			}
		}

		if ( $count === 1 && $split !== null ) {
			$head = [];
			$tail = [];

			foreach ( $range_edits as $edit ) {
				if ( $edit[1] <= $split ) {
					$head[] = $edit;
				} else {
					$tail[] = $edit;
				}
			}

			$body = Splice::apply( $tree->slice( [ $chunks[0][0], $split ] ), self::relative( $head, $chunks[0][0] ) );
			++$this->depth;

			foreach ( $kept as $k => $kept_t ) {
				$body .= ( $k === 0 ? '' : $this->gap_bytes( $kept_t, self::SIDE_T ) ) . $this->whole( $kept_t, self::SIDE_T, null );
			}

			--$this->depth;
			$body .= Splice::apply( $tree->slice( [ $split, $chunks[0][1] ] ), self::relative( $tail, $split ) );

			foreach ( $plan as [ $slot, $text ] ) {
				if ( $slot->range !== null && $slot->range[0] >= $split ) {
					$this->units[] = $text;
				}
			}
		} elseif ( $count === 1 ) {
			$body = Splice::apply( $tree->slice( $chunks[0] ), self::relative( $range_edits, $chunks[0][0] ) );
		} elseif ( $count > 1 ) {
			$last = $chunks[ $count - 1 ];
			$body = Splice::apply( $tree->slice( $chunks[0] ), self::relative( self::within( $range_edits, $chunks[0] ), $chunks[0][0] ) );
			++$this->depth;
			$body .= $this->children( $n, $b, $t, false );
			--$this->depth;
			$body .= Splice::apply( $tree->slice( $last ), self::relative( self::within( $range_edits, $last ), $last[0] ) );

			foreach ( $plan as [ $slot, $text ] ) {
				if ( $slot->range !== null && $slot->range[0] >= $last[0] ) {
					$this->units[] = $text;
				}
			}
		}

		return $open . $body . ( $c->close === null ? '' : $tree->slice( $c->close ) );
	}

	/**
	 * Flag a translation-only block the output does not hold, so a block the translator wrote never leaves unseen:
	 * the document gets `anchor` with exceptions kept, else `exceptions`.
	 *
	 * @return void
	 */
	private function unplaced(): void {
		foreach ( $this->alignment->translation_only as $t ) {
			if ( ! isset( $this->copied[ spl_object_id( $t ) ] ) ) {
				$this->flag( null, $this->keep ? self::FLAG_ANCHOR : self::FLAG_EXCEPTIONS );

				return;
			}
		}
	}

	/**
	 * The translation-only children of a translation container whose source block has no children: all go to the
	 * container's end, flagged through {@see self::anchors()}.
	 *
	 * @param BlockNode $n Source block, one chunk.
	 * @param BlockNode $t Its translation block, with children.
	 * @return list<BlockNode> The kept children, in the translation's order.
	 */
	private function kept_in_empty( BlockNode $n, BlockNode $t ): array {
		$tl = $this->levels[ self::SIDE_T ][ spl_object_id( $t ) ] ?? null;

		return $tl === null ? [] : $this->anchors( $n, self::level( $this->n, $n ), $tl )['end'];
	}

	/**
	 * The offset where a one-chunk source container's inner content ends: before the translation container's closing
	 * bytes when the source chunk ends with them and no unit spans the offset; null otherwise (the `anchor` flag then
	 * holds the proposal).
	 *
	 * @param array{int, int}                 $chunk The source container's chunk.
	 * @param BlockNode                       $t     The translation container.
	 * @param array<int, array{Unit, string}> $plan  The container's unit slots.
	 * @return int|null
	 * @phpstan-param list<array{Unit, string}> $plan
	 */
	private function inner_end( array $chunk, BlockNode $t, array $plan ): ?int {
		$closing = $this->t->tree->slice( $t->chunks[ count( $t->chunks ) - 1 ] );
		$bytes   = $this->n->tree->slice( $chunk );

		if ( $closing === '' || strlen( $bytes ) <= strlen( $closing ) || ! str_ends_with( $bytes, $closing ) ) {
			return null;
		}

		$split = $chunk[1] - strlen( $closing );

		foreach ( $plan as [ $slot ] ) {
			if ( $slot->range !== null && $slot->range[0] < $split && $slot->range[1] > $split ) {
				return null;
			}
		}

		return $split;
	}

	/**
	 * The unit slots of a carrier and the text each gets: the source's own units, or the translation's units paired with
	 * source units by role and occurrence (by fingerprint on unequal counts); an unpaired unit keeps its own text.
	 *
	 * @param BlockNode $c    Carrier.
	 * @param BlockNode $n    Source block.
	 * @param string    $side The carrier's side.
	 * @return list<array{Unit, string, Unit|null}> Slot, text, the unit the text comes from (null for a machine output).
	 */
	private function slots( BlockNode $c, BlockNode $n, string $side ): array {
		$n_units = self::shell_units( $n, $this->n );
		$out     = [];

		if ( $side === self::SIDE_N ) {
			foreach ( $n_units as $unit ) {
				[ $text, $from ] = $this->slot_text( $unit );
				$out[]           = [ $unit, $text, $from ];
			}

			return $out;
		}

		$t_units = self::shell_units( $c, $this->t );
		$by_slot = [];
		$paired  = [];

		foreach ( Aligner::pair_across( $n_units, $t_units )['pairs'] as [ $i, $j ] ) {
			$by_slot[ $j ] = $i;
			$paired[ $i ]  = true;
		}

		foreach ( $t_units as $j => $slot ) {
			if ( isset( $by_slot[ $j ] ) ) {
				[ $text, $from ] = $this->text_for( $n_units[ $by_slot[ $j ] ] );
				$out[]           = $text === null ? [ $slot, $slot->text, $slot ] : [ $slot, $text, $from ];
			} else {
				$out[] = [ $slot, $slot->text, $slot ];
			}
		}

		foreach ( $n_units as $i => $unit ) {
			if ( ! isset( $paired[ $i ] ) ) {
				$this->count_match( $unit );
			}
		}

		return $out;
	}

	/**
	 * The text a source unit's own slot gets: its match's text, or the source's when the translation has none.
	 *
	 * @param Unit $unit Source unit.
	 * @return array{string, Unit|null}
	 */
	private function slot_text( Unit $unit ): array {
		[ $text, $from ] = $this->text_for( $unit );

		if ( $text === null ) {
			++$this->counts['left_source'];

			return [ $unit->text, $unit ];
		}

		return [ $text, $from ];
	}

	/**
	 * The text a source unit's match gives, counted: the translation's for `reused` and `fuzzy`, an accepted machine
	 * output for `changed` and `new`, else the source's (`left_source`); null for a unit the translation removed.
	 *
	 * @param Unit $unit Source unit.
	 * @return array{string|null, Unit|null} Text and the unit it comes from (null for a machine output).
	 */
	private function text_for( Unit $unit ): array {
		$match = $this->matching->match_of( $unit );

		if ( $match === null ) {
			++$this->counts['left_source'];

			return [ $unit->text, $unit ];
		}

		$this->count_match( $unit );

		switch ( $match->status ) {
			case Matcher::STATUS_REUSED:
			case Matcher::STATUS_FUZZY:
				if ( $match->translation !== null ) {
					return [ $match->translation->text, $match->translation ];
				}

				++$this->counts['left_source'];

				return [ $unit->text, $unit ];

			case Matcher::STATUS_REMOVED_IN_TRANSLATION:
				return [ null, null ];

			default:
				$index = $this->unit_index[ spl_object_id( $unit ) ] ?? -1;

				if ( isset( $this->texts[ $index ] ) ) {
					if ( Verifier::accepts_for( $unit, $this->texts[ $index ] ) ) {
						++$this->counts['machine_translated'];

						return [ $this->texts[ $index ], null ];
					}

					$this->flag( $unit->node, self::FLAG_MT_QUALITY );
				}

				++$this->counts['left_source'];

				return [ $unit->text, $unit ];
		}
	}

	/**
	 * Count a source unit's match status. An exact match with an unsure base unit ({@see Alignment::unsure()}) has no
	 * translation to reuse and is not counted reused.
	 *
	 * @param Unit $unit Source unit.
	 * @return void
	 */
	private function count_match( Unit $unit ): void {
		$match = $this->matching->match_of( $unit );

		if ( $match === null || ( $match->status === Matcher::STATUS_REUSED && $match->translation === null ) ) {
			return;
		}

		if ( isset( $this->counts[ $match->status ] ) ) {
			++$this->counts[ $match->status ];
		}

		if ( $match->same_as_source ) {
			++$this->counts[ Matcher::SAME_AS_SOURCE ];
		}
	}

	// ---- encodings ----

	/**
	 * The edits that put a text into a range unit's slot and its mirror: content bytes as they are; an attribute value
	 * raw when both quotes are double, else the whole token double-quoted and escaped; a comment mirror raw.
	 *
	 * @param Unit      $slot The unit whose bytes are replaced.
	 * @param string    $text The text.
	 * @param Unit|null $from The unit the text comes from; null for a machine output.
	 * @return list<Edit>
	 */
	private function slot_edits( Unit $slot, string $text, ?Unit $from ): array {
		$edits = [];

		if ( $slot->range !== null && ! ( $from === $slot && $text === $slot->text ) ) {
			if ( $slot->format === BlockAttributeSource::FORMAT_TEXT ) {
				$edits[] = self::attribute_edit( $slot->range, $slot->quote, $text, $from === null ? null : $from->quote );
			} else {
				$edits[] = [ $slot->range[0], $slot->range[1], $text ];
			}
		}

		if ( $slot->mirror !== null && ! ( $from === $slot ) ) {
			$mirror = $slot->mirror;

			if ( $mirror['kind'] === Unit::MIRROR_ATTRIBUTE ) {
				$from_mirror = $from !== null && $from->mirror !== null && $from->mirror['kind'] === Unit::MIRROR_ATTRIBUTE ? $from->mirror : null;

				if ( $from_mirror !== null && $from_mirror['quote'] === '"' && $mirror['quote'] === '"' ) {
					$edits[] = [ $mirror['range'][0], $mirror['range'][1], $this->seg( $this->side_of( $from ) )->tree->slice( $from_mirror['range'] ) ];
				} else {
					$edits[] = self::attribute_edit( $mirror['range'], $mirror['quote'], $text, null );
				}
			} else {
				$edits[] = [ $mirror['range'][0], $mirror['range'][1], $text ];
			}
		}

		return $edits;
	}

	/**
	 * The side a unit belongs to: the translation's or the source's.
	 *
	 * @param Unit|null $unit Unit.
	 * @return string
	 */
	private function side_of( ?Unit $unit ): string {
		return $unit !== null && isset( $this->t_unit_ids[ spl_object_id( $unit ) ] ) ? self::SIDE_T : self::SIDE_N;
	}

	/**
	 * The edit that writes an attribute value: raw when the slot and the text's attribute are both double-quoted, else
	 * the whole value token as `"` + esc_attr + `"`.
	 *
	 * @param array{int, int} $range      The slot's value range (without quotes).
	 * @param string          $quote      The slot's quote.
	 * @param string          $text       The text.
	 * @param string|null     $from_quote The quote of the attribute the text comes from; null when it comes from none.
	 * @return Edit
	 */
	private static function attribute_edit( array $range, string $quote, string $text, ?string $from_quote ): array {
		if ( $quote === '"' && $from_quote === '"' ) {
			return [ $range[0], $range[1], $text ];
		}

		$pad = $quote === '' ? 0 : 1;

		return [ $range[0] - $pad, $range[1] + $pad, '"' . esc_attr( $text ) . '"' ];
	}

	/**
	 * The text re-segmentation reads back from a slot after its edit.
	 *
	 * @param Unit      $slot Slot.
	 * @param string    $text Text written.
	 * @param Unit|null $from The unit the text comes from.
	 * @return string
	 */
	private function planned_text( Unit $slot, string $text, ?Unit $from ): string {
		if ( $slot->range === null || ( $from === $slot && $text === $slot->text ) || $slot->format !== BlockAttributeSource::FORMAT_TEXT ) {
			return $text;
		}

		return $slot->quote === '"' && $from !== null && $from->quote === '"' ? $text : esc_attr( $text );
	}

	// ---- three-way merge ----

	/**
	 * Merge the non-text values of a block onto the source: comment JSON leaves and own-tag attributes, tags matched by
	 * position. Per value: vN = vB -> vT; vT = vB or vT = vN -> vN; else a conflict; `class` merges per token. A value is
	 * absent only when its side lacks the path or attribute ({@see self::merge_key()}).
	 *
	 * @param BlockNode $n Source block.
	 * @param BlockNode $b Base block.
	 * @param BlockNode $t Translation block.
	 * @return array{array{json: list<array{list<int|string>, mixed, bool}>, attrs: list<Edit>}, bool} The edits on the source's bytes, and whether a conflict stops them.
	 */
	private function three_way( BlockNode $n, BlockNode $b, BlockNode $t ): array {
		$edits = [
			'json'  => [],
			'attrs' => [],
		];

		$json_n = self::attributes( $n, $this->n );
		$json_b = self::attributes( $b, $this->b );
		$json_t = self::attributes( $t, $this->t );
		$skip   = [];

		foreach ( [ [ $n, $this->n, $json_n ], [ $b, $this->b, $json_b ], [ $t, $this->t, $json_t ] ] as [ $node, $seg, $json ] ) {
			foreach ( $seg->units_of( $node ) as $unit ) {
				if ( $unit->kind === Unit::KIND_JSON ) {
					$skip[ self::json_key( $json, $unit->path ) ] = true;
				}
			}
		}

		$ln = self::leaves( $json_n );
		$lb = self::leaves( $json_b );
		$lt = self::leaves( $json_t );

		// A list merges by position: when both sides changed its length differently and one did more than add or remove
		// items at its end, a position no longer names the same item: a conflict.
		$sn = self::lists( $json_n );
		$sb = self::lists( $json_b );
		$st = self::lists( $json_t );

		foreach ( array_keys( $sn + $sb + $st ) as $list ) {
			$list   = (string) $list;
			$size_b = $sb[ $list ] ?? -1;
			$size_n = $sn[ $list ] ?? -1;
			$size_t = $st[ $list ] ?? -1;

			if ( $size_n !== $size_b && $size_t !== $size_b && ! str_starts_with( $list, self::VOLATILE_PATH )
				&& self::under( $ln, $list, $skip ) !== self::under( $lt, $list, $skip )
				&& ( ! self::keeps_head( $ln, $lb, $list, min( $size_n, $size_b ), $skip ) || ! self::keeps_head( $lt, $lb, $list, min( $size_t, $size_b ), $skip ) )
			) {
				return [ $edits, true ];
			}
		}

		foreach ( array_keys( $ln + $lb + $lt ) as $key ) {
			$key = (string) $key;

			if ( isset( $skip[ $key ] ) || str_starts_with( $key, self::VOLATILE_PATH ) ) {
				continue;
			}

			$merged = self::merge_value(
				self::merge_key( isset( $ln[ $key ] ), isset( $ln[ $key ] ) ? self::canonical( $ln[ $key ][1] ) : '' ),
				self::merge_key( isset( $lb[ $key ] ), isset( $lb[ $key ] ) ? self::canonical( $lb[ $key ][1] ) : '' ),
				self::merge_key( isset( $lt[ $key ] ), isset( $lt[ $key ] ) ? self::canonical( $lt[ $key ][1] ) : '' )
			);

			if ( $merged === null ) {
				return [ $edits, true ];
			}

			if ( $merged === 't' ) {
				$path            = $ln[ $key ][0] ?? $lb[ $key ][0] ?? $lt[ $key ][0] ?? [];
				$edits['json'][] = isset( $lt[ $key ] ) ? [ $path, $lt[ $key ][1], false ] : [ $path, null, true ];
			}
		}

		$tags_n = self::tags( $n, $this->n );
		$tags_b = self::tags( $b, $this->b );
		$tags_t = self::tags( $t, $this->t );

		if ( self::tag_names( $tags_n ) !== self::tag_names( $tags_b ) || self::tag_names( $tags_n ) !== self::tag_names( $tags_t ) ) {
			return [ $edits, true ];
		}

		foreach ( $tags_n as $k => $tag_n ) {
			$tag_b  = $tags_b[ $k ];
			$tag_t  = $tags_t[ $k ];
			$insert = '';

			foreach ( array_keys( $tag_n['attrs'] + $tag_b['attrs'] + $tag_t['attrs'] ) as $name ) {
				if ( ( $tag_n['attrs'][ $name ]['unit'] ?? false ) || ( $tag_b['attrs'][ $name ]['unit'] ?? false ) || ( $tag_t['attrs'][ $name ]['unit'] ?? false ) ) {
					continue;
				}

				$an = $tag_n['attrs'][ $name ] ?? null;
				$ab = $tag_b['attrs'][ $name ] ?? null;
				$at = $tag_t['attrs'][ $name ] ?? null;

				if ( $name === 'class' && $an !== null && $ab !== null && $at !== null && $an['value'] !== $ab['value'] ) {
					$value = self::merge_class( $ab['value'], $an['value'], $at['value'] );

					if ( $value !== $an['value'] ) {
						$edits['attrs'][] = [ $an['value_start'] - $an['pad'], $an['value_end'] + $an['pad'], '"' . esc_attr( $value ) . '"' ];
					}

					continue;
				}

				$merged = self::merge_value(
					self::merge_key( $an !== null, $an['value'] ?? '' ),
					self::merge_key( $ab !== null, $ab['value'] ?? '' ),
					self::merge_key( $at !== null, $at['value'] ?? '' )
				);

				if ( $merged === null ) {
					return [ $edits, true ];
				}

				if ( $merged !== 't' ) {
					continue;
				}

				if ( $at === null ) {
					if ( $an !== null ) {
						$edits['attrs'][] = [ $an['token_start'] - $an['space'], $an['token_end'], '' ];
					}
				} elseif ( $an === null ) {
					$insert .= ' ' . $name . '="' . esc_attr( $at['value'] ) . '"';
				} elseif ( $an['quote'] === '"' && $at['quote'] === '"' ) {
					$edits['attrs'][] = [ $an['value_start'], $an['value_end'], $at['raw'] ];
				} else {
					$edits['attrs'][] = [ $an['value_start'] - $an['pad'], $an['value_end'] + $an['pad'], '"' . esc_attr( $at['value'] ) . '"' ];
				}
			}

			if ( $insert !== '' ) {
				$edits['attrs'][] = [ $tag_n['inner_end'], $tag_n['inner_end'], $insert ];
			}
		}

		return [ $edits, false ];
	}

	/**
	 * The names of a tag list, closers prefixed with a slash.
	 *
	 * @param array<int, array<string, mixed>> $tags Tags.
	 * @return list<string>
	 */
	private static function tag_names( array $tags ): array {
		$out = [];

		foreach ( $tags as $tag ) {
			$out[] = is_string( $tag['name'] ) ? $tag['name'] : '';
		}

		return $out;
	}

	/**
	 * The three-way rule for one value: 'n' when the source's stands, 't' when the translation's replaces it, null on a
	 * conflict.
	 *
	 * @param string $vn Source value ({@see self::merge_key()}).
	 * @param string $vb Base value ({@see self::merge_key()}).
	 * @param string $vt Translation value ({@see self::merge_key()}).
	 * @return string|null
	 */
	private static function merge_value( string $vn, string $vb, string $vt ): ?string {
		if ( $vn === $vb ) {
			return $vt === $vn ? 'n' : 't';
		}

		if ( $vt === $vb || $vt === $vn ) {
			return 'n';
		}

		return null;
	}

	/**
	 * What merge_value() compares for one side: '' when the side lacks the value, else "v" and its comparable form, so a
	 * held value never reads as absent.
	 *
	 * @param bool   $held  Whether the side holds the value.
	 * @param string $value Its comparable form: a canonical() JSON value or an attribute's decoded value.
	 * @return string
	 */
	private static function merge_key( bool $held, string $value ): string {
		return $held ? 'v' . $value : '';
	}

	/**
	 * A JSON value in a form two sides can compare.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function canonical( mixed $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- A comparison key for a value JSON cannot encode.
	}

	/**
	 * A block's comment JSON decoded as objects (lists stay arrays); an empty object when absent or invalid.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return \stdClass
	 */
	private static function attributes( BlockNode $node, Segmentation $segmentation ): \stdClass {
		$decoded = $node->attrs_json === null ? null : BlockJson::decode( $segmentation->tree->slice( $node->attrs_json ) );

		return $decoded ?? new \stdClass();
	}

	/**
	 * One step of a JSON path as a key part: kind (`o` object key, `l` list index), key length in bytes, colon, key.
	 * Length-prefixed, so joined parts name one path whatever a key holds, and one key prefixes another exactly when its
	 * path lies at or above the other's.
	 *
	 * @param bool       $in_object Whether the step is an object's key.
	 * @param int|string $key       The key or index.
	 * @return string
	 */
	private static function path_part( bool $in_object, int|string $key ): string {
		$key = (string) $key;

		return ( $in_object ? 'o' : 'l' ) . strlen( $key ) . ':' . $key;
	}

	/**
	 * The key of a path in decoded attributes ({@see self::path_part()}), each step's kind read from its container.
	 *
	 * @param \stdClass|array<mixed> $attrs Attributes.
	 * @param list<int|string>       $path  Path.
	 * @return string
	 */
	private static function json_key( \stdClass|array $attrs, array $path ): string {
		$key  = '';
		$node = $attrs;

		foreach ( $path as $step ) {
			$in_object = is_object( $node );
			$key      .= self::path_part( $in_object, $step );

			if ( $in_object ) {
				$name = (string) $step;
				$node = $node->{$name} ?? null;
			} elseif ( is_array( $node ) ) {
				$node = $node[ $step ] ?? null;
			}
		}

		return $key;
	}

	/**
	 * The leaves of decoded attributes by path key ({@see self::path_part()}): scalars and empty containers. A scanned
	 * block's attributes nest at most {@see BlockScanner::MAX_DEPTH} levels.
	 *
	 * @param \stdClass|array<mixed> $attrs Attributes.
	 * @param list<int|string>       $path  Path so far.
	 * @param string                 $key   The key of the path so far.
	 * @return array<string, array{list<int|string>, mixed}>
	 */
	private static function leaves( \stdClass|array $attrs, array $path = [], string $key = '' ): array {
		$out       = [];
		$in_object = is_object( $attrs );

		foreach ( $in_object ? get_object_vars( $attrs ) : $attrs as $step => $value ) {
			$here     = $path;
			$here[]   = $step;
			$here_key = $key . self::path_part( $in_object, $step );

			$container = ( $value instanceof \stdClass && get_object_vars( $value ) !== [] ) || ( is_array( $value ) && $value !== [] );

			if ( $container ) {
				foreach ( self::leaves( $value, $here, $here_key ) as $k => $leaf ) {
					$out[ $k ] = $leaf;
				}
			} else {
				$out[ $here_key ] = [ $here, $value ];
			}
		}

		return $out;
	}

	/**
	 * The lists in decoded attributes, nested ones included, by path key, with their item counts; at most
	 * {@see BlockScanner::MAX_DEPTH} levels deep.
	 *
	 * @param \stdClass|array<mixed> $attrs Attributes.
	 * @param string                 $key   The key of the path so far.
	 * @return array<string, int>
	 */
	private static function lists( \stdClass|array $attrs, string $key = '' ): array {
		$out       = [];
		$in_object = is_object( $attrs );

		foreach ( $in_object ? get_object_vars( $attrs ) : $attrs as $step => $value ) {
			$here_key = $key . self::path_part( $in_object, $step );

			if ( is_array( $value ) ) {
				$out[ $here_key ] = count( $value );
			}

			if ( is_array( $value ) || $value instanceof \stdClass ) {
				$out += self::lists( $value, $here_key );
			}
		}

		return $out;
	}

	/**
	 * Whether a side's list keeps the base list's first `$count` items unchanged, text unit leaves aside.
	 *
	 * @param array<string, array{list<int|string>, mixed}> $side   The side's leaves.
	 * @param array<string, array{list<int|string>, mixed}> $base   The base's leaves.
	 * @param string                                        $path   The key of the list's path.
	 * @param int                                           $count  Items compared.
	 * @param array<string, true>                           $skip   The keys of the text units' leaves.
	 * @return bool
	 */
	private static function keeps_head( array $side, array $base, string $path, int $count, array $skip ): bool {
		for ( $i = 0; $i < $count; $i++ ) {
			$item = $path . self::path_part( false, $i );

			if ( self::under( $side, $item, $skip ) !== self::under( $base, $item, $skip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The leaves at or under a path as canonical values in key order, text unit leaves aside; a key prefix matches
	 * exactly the paths at or under it ({@see self::path_part()}).
	 *
	 * @param array<string, array{list<int|string>, mixed}> $leaves Leaves (leaves()).
	 * @param string                                        $path   The key of the path.
	 * @param array<string, true>                           $skip   The keys of the text units' leaves.
	 * @return array<string, string>
	 */
	private static function under( array $leaves, string $path, array $skip ): array {
		$out = [];

		foreach ( $leaves as $key => $leaf ) {
			$key = (string) $key;

			if ( ! isset( $skip[ $key ] ) && str_starts_with( $key, $path ) ) {
				$out[ $key ] = self::canonical( $leaf[1] );
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The tags of a node's shell chunks with their attributes, offsets and value ranges. An attribute is its name's first
	 * occurrence, as the browser reads it; later occurrences are the tag's `repeats`.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return list<array{name: string, inner_end: int, attrs: array<string, array{value: string, raw: string, quote: string, pad: int, value_start: int, value_end: int, token_start: int, token_end: int, space: int, unit: bool}>, repeats: list<string>}>
	 */
	private static function tags( BlockNode $node, Segmentation $segmentation ): array {
		$ranges = [];

		foreach ( self::shell_units( $node, $segmentation ) as $unit ) {
			if ( $unit->range !== null ) {
				$ranges[] = $unit->range;
			}

			if ( $unit->mirror !== null ) {
				$ranges[] = $unit->mirror['range'];
			}
		}

		$out = [];

		foreach ( self::shell_chunks( $node ) as $chunk ) {
			$html = $segmentation->tree->slice( $chunk );

			if ( preg_match_all( self::TAG_PATTERN, $html, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) === false ) {
				continue;
			}

			foreach ( $tags as $tag ) {
				$tag_name = $tag[2][0] ?? null;
				$whole    = $tag[0][0] ?? '';

				if ( $tag_name === null || $whole === '' ) {
					continue;
				}

				$start     = $chunk[0] + $tag[0][1];
				$end       = $start + strlen( $whole );
				$inner_end = $end - 1;

				if ( substr( $whole, -2 ) === '/>' ) {
					--$inner_end;
				}

				$attrs   = [];
				$repeats = [];

				$inside = $tag[3][0] ?? '';

				if ( $inside !== '' ) {
					$base = $chunk[0] + $tag[3][1];
					preg_match_all( self::ATTRIBUTE_PATTERN, $inside, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );

					foreach ( $found as $attr ) {
						$name = strtolower( $attr[1][0] ?? '' );

						if ( $name === '' ) {
							continue;
						}

						if ( isset( $attrs[ $name ] ) ) {
							$repeats[] = (string) ( $attr[0][0] ?? '' );
							continue;
						}

						$token_start = $base + $attr[1][1];
						$token_end   = $base + $attr[0][1] + strlen( $attr[0][0] ?? '' );
						$space       = 0;

						while ( $token_start - $space - 1 >= $chunk[0] && false !== strpos( " \t\n\r\x0B\x0C", $html[ $token_start - $space - 1 - $chunk[0] ] ) ) {
							++$space;
						}

						$raw   = '';
						$quote = '';
						$vs    = $token_end;

						foreach ( [
							2 => '"',
							3 => "'",
							4 => '',
						] as $group => $q ) {
							$captured = $attr[ $group ][0] ?? null;

							if ( $captured !== null ) {
								$raw   = $captured;
								$quote = $q;
								$vs    = $base + $attr[ $group ][1];
							}
						}

						$ve   = $vs + strlen( $raw );
						$unit = false;

						foreach ( $ranges as $range ) {
							if ( $range[0] === $vs && $range[1] === $ve ) {
								$unit = true;
							}
						}

						$attrs[ $name ] = [
							'value'       => html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
							'raw'         => $raw,
							'quote'       => $quote,
							'pad'         => $quote === '' ? 0 : 1,
							'value_start' => $vs,
							'value_end'   => $ve,
							'token_start' => $token_start,
							'token_end'   => $token_end,
							'space'       => $space,
							'unit'        => $unit,
						];
					}
				}

				$out[] = [
					'name'      => ( ( $tag[1][0] ?? '' ) === '/' ? '/' : '' ) . strtolower( $tag_name ),
					'inner_end' => $inner_end,
					'attrs'     => $attrs,
					'repeats'   => $repeats,
				];
			}
		}

		return $out;
	}

	// ---- helpers ----

	/**
	 * A node's shell chunks: all of a leaf's, the first and last of a container's.
	 *
	 * @param BlockNode $node Node.
	 * @return list<array{int, int}>
	 */
	private static function shell_chunks( BlockNode $node ): array {
		$count = count( $node->chunks );

		if ( $count <= 1 ) {
			return $node->chunks;
		}

		return [ $node->chunks[0], $node->chunks[ $count - 1 ] ];
	}

	/**
	 * A node's own units that belong to its shell: its JSON leaves and the range units of its shell chunks.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return list<Unit>
	 */
	private static function shell_units( BlockNode $node, Segmentation $segmentation ): array {
		$chunks = self::shell_chunks( $node );
		$out    = [];

		foreach ( $segmentation->units_of( $node ) as $unit ) {
			if ( $unit->range === null ) {
				$out[] = $unit;
				continue;
			}

			foreach ( $chunks as $chunk ) {
				if ( $unit->range[0] >= $chunk[0] && $unit->range[1] <= $chunk[1] ) {
					$out[] = $unit;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * A node's own units inside one range, in document order.
	 *
	 * @param Segmentation    $segmentation Segmentation.
	 * @param BlockNode       $node         Node.
	 * @param array{int, int} $range        Range.
	 * @return list<Unit>
	 */
	private function units_in( Segmentation $segmentation, BlockNode $node, array $range ): array {
		$out = [];

		foreach ( $segmentation->units_of( $node ) as $unit ) {
			if ( $unit->range !== null && $unit->range[0] >= $range[0] && $unit->range[1] <= $range[1] ) {
				$out[] = $unit;
			}
		}

		return $out;
	}

	/**
	 * Index the unit span of every node of a side ({@see self::$spans}).
	 *
	 * @param string       $side         Side.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return void
	 */
	private function index_spans( string $side, Segmentation $segmentation ): void {
		$order = [];

		foreach ( $segmentation->units as $i => $unit ) {
			$order[ spl_object_id( $unit ) ] = $i;
		}

		$this->spans[ $side ] = [];

		foreach ( $segmentation->tree->nodes as $node ) {
			$this->span_of( $side, $segmentation, $node, $order );
		}
	}

	/**
	 * The unit span of a node's subtree, recorded for it and every node below: the lowest and highest unit index.
	 *
	 * @param string          $side         Side.
	 * @param Segmentation    $segmentation Its segmentation.
	 * @param BlockNode       $node         Node.
	 * @param array<int, int> $order        Index in the unit list per unit id.
	 * @return array{int, int}|null Null for a subtree without units.
	 */
	private function span_of( string $side, Segmentation $segmentation, BlockNode $node, array $order ): ?array {
		$first = null;
		$last  = null;

		foreach ( $segmentation->units_of( $node ) as $unit ) {
			$index = $order[ spl_object_id( $unit ) ];

			if ( $first === null || $index < $first ) {
				$first = $index;
			}

			if ( $last === null || $index > $last ) {
				$last = $index;
			}
		}

		foreach ( $node->children as $child ) {
			$span = $this->span_of( $side, $segmentation, $child, $order );

			if ( $span === null ) {
				continue;
			}

			if ( $first === null || $span[0] < $first ) {
				$first = $span[0];
			}

			if ( $last === null || $span[1] > $last ) {
				$last = $span[1];
			}
		}

		if ( $first === null || $last === null ) {
			return null;
		}

		$this->spans[ $side ][ spl_object_id( $node ) ] = [ $first, $last ];

		return [ $first, $last ];
	}

	/**
	 * Every unit of a node's subtree in document order: the run of the unit list its span names.
	 *
	 * @param Segmentation $segmentation Segmentation.
	 * @param BlockNode    $node         Node.
	 * @return list<Unit>
	 */
	private function subtree_units( Segmentation $segmentation, BlockNode $node ): array {
		$side = $segmentation === $this->n ? self::SIDE_N : ( $segmentation === $this->b ? self::SIDE_B : self::SIDE_T );

		if ( ! isset( $this->spans[ $side ] ) ) {
			$this->index_spans( $side, $segmentation );
		}

		$span = $this->spans[ $side ][ spl_object_id( $node ) ] ?? null;

		return $span === null ? [] : array_slice( $segmentation->units, $span[0], $span[1] - $span[0] + 1 );
	}

	/**
	 * The blocks of a subtree in document order, the node first.
	 *
	 * @param BlockNode $node Node.
	 * @return list<BlockNode>
	 */
	private static function subtree_nodes( BlockNode $node ): array {
		$out = $node->name === null ? [] : [ $node ];

		foreach ( $node->children as $child ) {
			foreach ( self::subtree_nodes( $child ) as $sub ) {
				$out[] = $sub;
			}
		}

		return $out;
	}

	/**
	 * A chunk's bytes with every unit range and mirror inside it replaced.
	 *
	 * @param BlockTree        $tree        Tree.
	 * @param array{int, int}  $chunk       Chunk.
	 * @param array<int, Unit> $units       Units.
	 * @param string           $replacement Replacement.
	 * @return string
	 * @phpstan-param list<Unit> $units
	 */
	private static function blank( BlockTree $tree, array $chunk, array $units, string $replacement ): string {
		$edits = [];

		foreach ( $units as $unit ) {
			foreach ( [ $unit->range, $unit->mirror === null ? null : $unit->mirror['range'] ] as $range ) {
				if ( $range !== null && $range[0] >= $chunk[0] && $range[1] <= $chunk[1] ) {
					$edits[] = [ $range[0] - $chunk[0], $range[1] - $chunk[0], $replacement ];
				}
			}
		}

		return Splice::apply( $tree->slice( $chunk ), $edits );
	}

	/**
	 * Edits inside a range.
	 *
	 * @param array<int, array{int, int, string}> $edits Edits.
	 * @param array{int, int}                     $range Range.
	 * @return list<Edit>
	 * @phpstan-param list<Edit> $edits
	 */
	private static function within( array $edits, array $range ): array {
		return array_values( array_filter( $edits, static fn( array $edit ): bool => $edit[0] >= $range[0] && $edit[1] <= $range[1] ) );
	}

	/**
	 * Edits shifted to an offset.
	 *
	 * @param array<int, array{int, int, string}> $edits  Edits.
	 * @param int                                 $offset Offset.
	 * @return list<Edit>
	 * @phpstan-param list<Edit> $edits
	 */
	private static function relative( array $edits, int $offset ): array {
		return array_map( static fn( array $edit ): array => [ $edit[0] - $offset, $edit[1] - $offset, $edit[2] ], $edits );
	}

	/**
	 * Flag a source block (or the document).
	 *
	 * @param BlockNode|null $node Block; null for the document.
	 * @param string         $flag Flag.
	 * @return void
	 */
	private function flag( ?BlockNode $node, string $flag ): void {
		$key = $node === null ? self::DOCUMENT_KEY : Segmentation::path_key( $node );

		if ( ! in_array( $flag, $this->flags[ $key ] ?? [], true ) ) {
			$this->flags[ $key ][] = $flag;
		}
	}

	/**
	 * The value at a path of decoded JSON; null when absent.
	 *
	 * @param \stdClass|array<mixed> $container Container.
	 * @param list<int|string>       $path      Path.
	 * @return mixed
	 */
	private static function get_leaf( \stdClass|array $container, array $path ): mixed {
		$node = $container;

		foreach ( $path as $step ) {
			if ( is_object( $node ) ) {
				$key = (string) $step;

				if ( ! property_exists( $node, $key ) ) {
					return null;
				}

				$node = $node->{$key};
			} elseif ( is_array( $node ) && array_key_exists( $step, $node ) ) {
				$node = $node[ $step ];
			} else {
				return null;
			}
		}

		return $node;
	}

	/**
	 * Close the holes a removed item leaves in decoded JSON lists, so every list encodes as a list again.
	 *
	 * @param \stdClass|array<mixed> $container Container, modified.
	 * @return void
	 */
	private static function reindex( \stdClass|array &$container ): void {
		if ( is_array( $container ) ) {
			$container = array_values( $container );

			foreach ( $container as $key => $value ) {
				if ( $value instanceof \stdClass || is_array( $value ) ) {
					self::reindex( $value );
					$container[ $key ] = $value;
				}
			}

			return;
		}

		foreach ( get_object_vars( $container ) as $key => $value ) {
			if ( $value instanceof \stdClass || is_array( $value ) ) {
				self::reindex( $value );
				$container->{$key} = $value;
			}
		}
	}

	/**
	 * Set (or unset) the value at a path of decoded JSON, creating the
	 * containers on the way: objects for string steps, arrays for ints.
	 *
	 * @param \stdClass|array<mixed> $container Container, modified.
	 * @param list<int|string>       $path      Path.
	 * @param mixed                  $value     Value.
	 * @param bool                   $remove    Whether to remove the leaf instead.
	 * @return void
	 */
	private static function set_leaf( \stdClass|array &$container, array $path, mixed $value, bool $remove ): void {
		if ( $path === [] ) {
			return;
		}

		$step = array_shift( $path );

		if ( is_object( $container ) ) {
			$key = (string) $step;

			if ( $path === [] ) {
				if ( $remove ) {
					unset( $container->{$key} );
				} else {
					$container->{$key} = $value;
				}

				return;
			}

			$next = property_exists( $container, $key ) ? $container->{$key} : null;

			if ( ! $next instanceof \stdClass && ! is_array( $next ) ) {
				if ( $remove ) {
					return;
				}

				$next = is_int( $path[0] ) ? [] : new \stdClass();
			}

			self::set_leaf( $next, $path, $value, $remove );
			$container->{$key} = $next;

			return;
		}

		if ( $path === [] ) {
			if ( $remove ) {
				unset( $container[ $step ] );
			} else {
				$container[ $step ] = $value;
			}

			return;
		}

		$next = $container[ $step ] ?? null;

		if ( ! $next instanceof \stdClass && ! is_array( $next ) ) {
			if ( $remove ) {
				return;
			}

			$next = is_int( $path[0] ) ? [] : new \stdClass();
		}

		self::set_leaf( $next, $path, $value, $remove );
		$container[ $step ] = $next;
	}
}
