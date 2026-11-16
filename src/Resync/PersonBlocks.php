<?php
/**
 * The blocks a person wrote or changed in a translation.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which translation blocks of a pair are a person's (not ReSync's own last output), judged by hash on the last write's
 * block map since two languages cannot be compared: marked entries, carried marks by own part, and after a person edit
 * any own part that differs from its record. Without a person edit or mark nothing runs; classic content has no map.
 */
final class PersonBlocks {

	/** The block map mark of an output block that keeps a person's text. */
	public const MARK = 'p';

	/** {@see self::basis()}: the block map judges the blocks. */
	public const BASIS_MAP = 'map';

	/** {@see self::basis()}: the live translation is the last automatic write's output; no block is a person's. */
	public const BASIS_LIVE = 'live';

	/** {@see self::basis()}: the last automatic write's own output judges the blocks ({@see self::owns()}). */
	public const BASIS_REVISION = 'revision';

	/** {@see self::basis()}: nothing records what automation wrote; every block is a person's. */
	public const BASIS_UNKNOWN = 'unknown';

	/**
	 * The state's `how` values that record the translation an automatic write left: SyncRecorder::HOW_CAPTURED and
	 * HOW_FILLED written out, so composing never loads the recorder (PersonBlocksHowTest holds them equal).
	 */
	public const AUTOMATIC_HOW = [ 'captured', 'filled' ];

	/**
	 * The person's translation blocks, by node id.
	 *
	 * @var array<int, true>
	 */
	private array $person;

	/**
	 * The new source's blocks by path key, built on first use.
	 *
	 * @var array<string, BlockNode>|null
	 */
	private ?array $source_blocks = null;

	/**
	 * Constructor.
	 *
	 * @param Prepared         $prepared The pair.
	 * @param array<int, true> $person   The person's translation blocks, by node id.
	 */
	private function __construct( private readonly Prepared $prepared, array $person ) {
		$this->person = $person;
	}

	/**
	 * The person's blocks of a pair.
	 *
	 * @param Prepared                 $prepared  The pair, with the block map of the last write in its options.
	 * @param bool                     $edited    Whether a person edited the translation since the last automatic write (rule 1).
	 * @param array<string, true>|null $automatic For a translation without a block map, the own parts of the last automatic write's blocks ({@see self::owns()}); null when unknown.
	 * @return self
	 */
	public static function of( Prepared $prepared, bool $edited, ?array $automatic = null ): self {
		$entries = [];
		$marked  = false;
		$carried = [];

		foreach ( (array) ( $prepared->options['block_map'] ?? [] ) as $entry ) {
			if ( self::is_carried( $entry ) ) {
				// A mark on a legacy-format anchor names no block this code can find: the blocks are judged as edited by a person
				// since the last automatic write.
				if ( StateHash::is_legacy( $entry[1], 16 ) ) {
					$edited = true;
				} else {
					$carried[ $entry[1] ] = true;
				}

				continue;
			}

			if ( ! is_array( $entry ) || count( $entry ) < 3 || ! isset( $entry[1], $entry[2] ) || ! is_string( $entry[1] ) || ! is_string( $entry[2] ) ) {
				continue;
			}

			$entries[ $entry[2] ] ??= $entry;
			$marked                 = $marked || self::marked( $entry );
		}

		if ( ! $edited && ! $marked && [] === $carried ) {
			return new self( $prepared, [] );
		}

		$person = [];
		$tree   = $prepared->seg_t->tree;
		$known  = [] === $entries ? $automatic : null;

		foreach ( $prepared->alignment->pairs as [ $b, $t ] ) {
			if ( null === $t->name ) {
				continue;
			}

			$entry = $entries[ Segmentation::path_key( $b ) ] ?? null;

			if ( null !== $entry && self::marked( $entry ) ) {
				$person[ spl_object_id( $t ) ] = true;
				continue;
			}

			if ( [] === $carried && ! $edited ) {
				continue;
			}

			$own = self::own( $t, $tree );

			if ( isset( $carried[ $own ] ) ) {
				$person[ spl_object_id( $t ) ] = true;
				continue;
			}

			if ( ! $edited ) {
				continue;
			}

			if ( null !== $entry ? $own !== self::recorded_own( $entry ) : ( null === $known || ! isset( $known[ $own ] ) ) ) {
				$person[ spl_object_id( $t ) ] = true;
			}
		}

		return new self( $prepared, $person );
	}

	/**
	 * Which record judges the blocks of a person-edited translation: its block map (BASIS_MAP); without one, after a
	 * capture or engine fill, the live translation if it still has that write's hash (BASIS_LIVE), else that write's output
	 * (BASIS_REVISION); otherwise BASIS_UNKNOWN (a confirmation, a bootstrap, an older translation). Carried marks are no map.
	 *
	 * @param array<mixed> $block_map     The block map the run uses.
	 * @param string       $how           The state row's `how`; '' without a row.
	 * @param string       $recorded_hash The state row's translation hash; '' without a row.
	 * @param string       $live_hash     The live translation's hash.
	 * @return string One of the BASIS_* constants.
	 */
	public static function basis( array $block_map, string $how, string $recorded_hash, string $live_hash ): string {
		foreach ( $block_map as $entry ) {
			if ( is_array( $entry ) && count( $entry ) >= 3 ) {
				return self::BASIS_MAP;
			}
		}

		if ( '' === $recorded_hash || ! in_array( $how, self::AUTOMATIC_HOW, true ) ) {
			return self::BASIS_UNKNOWN;
		}

		return $recorded_hash === $live_hash ? self::BASIS_LIVE : self::BASIS_REVISION;
	}

	/**
	 * The own parts of a document's named blocks, each once: what an automatic write without a block map wrote.
	 *
	 * @param string $content A post content.
	 * @return array<string, true>|null Null for a document the scanner does not support.
	 */
	public static function owns( string $content ): ?array {
		$tree = BlockScanner::scan( $content );

		if ( ! $tree->supported ) {
			return null;
		}

		$out   = [];
		$stack = $tree->nodes;

		while ( [] !== $stack ) {
			$node = array_pop( $stack );

			if ( null !== $node->name ) {
				$out[ self::own( $node, $tree ) ] = true;
			}

			foreach ( $node->children as $child ) {
				$stack[] = $child;
			}
		}

		return $out;
	}

	/**
	 * The marks of a block map as carried marks (`[MARK, own]`, each own part once) for a state whose map is dropped or
	 * unusable: a marked entry carries its recorded own part, a carried mark itself, anything else nothing.
	 *
	 * @param array<mixed> $block_map A block map.
	 * @return list<list<string>>
	 */
	public static function carry( array $block_map ): array {
		$out = [];

		foreach ( $block_map as $entry ) {
			if ( self::is_carried( $entry ) ) {
				$out[ $entry[1] ] = true;
			} elseif ( is_array( $entry ) && count( $entry ) >= 3 && self::marked( $entry ) ) {
				$own = self::recorded_own( $entry );

				if ( '' !== $own ) {
					$out[ $own ] = true;
				}
			}
		}

		$list = [];

		foreach ( array_keys( $out ) as $own ) {
			$list[] = [ self::MARK, (string) $own ];
		}

		return $list;
	}

	/**
	 * The first 16 hex characters of a block's own canon (children counted, not read); for a childless block, its map hash.
	 *
	 * @param BlockNode $node Block.
	 * @param BlockTree $tree Its tree.
	 * @return string
	 */
	public static function own( BlockNode $node, BlockTree $tree ): string {
		return substr( Keys::canon_of( $node, $tree, [] ), 0, 16 );
	}

	/**
	 * A block map entry: `[t_path, hash16, b_path]`, plus the mark for one keeping a person's text; a block with children
	 * appends the mark or '' and its own part's hash ({@see self::own()}).
	 *
	 * @param string $path   Output block path key.
	 * @param string $hash16 Its canon hash prefix.
	 * @param string $origin The source block path key.
	 * @param bool   $person Whether it keeps a person's text.
	 * @param string $own    Its own part's hash; '' for a block without children.
	 * @return list<string>
	 */
	public static function entry( string $path, string $hash16, string $origin, bool $person, string $own ): array {
		if ( '' !== $own ) {
			return [ $path, $hash16, $origin, $person ? self::MARK : '', $own ];
		}

		return $person ? [ $path, $hash16, $origin, self::MARK ] : [ $path, $hash16, $origin ];
	}

	/**
	 * Whether the pair has no person's block.
	 *
	 * @return bool
	 */
	public function none(): bool {
		return [] === $this->person;
	}

	/**
	 * Whether a translation block is a person's.
	 *
	 * @param BlockNode $node Translation block.
	 * @return bool
	 */
	public function has( BlockNode $node ): bool {
		return isset( $this->person[ spl_object_id( $node ) ] );
	}

	/**
	 * Whether an output block keeps a person's text: it has no units and stands for a person's block, or one of its source
	 * block's units reused or near-matched a person's text.
	 *
	 * @param string $origin The output block's source block path key.
	 * @return bool
	 */
	public function keeps( string $origin ): bool {
		if ( $this->none() ) {
			return false;
		}

		$n = $this->source_block( $origin );

		if ( null === $n ) {
			return false;
		}

		$units = $this->prepared->seg_n->units_of( $n );

		if ( [] === $units ) {
			$b = $this->prepared->matching->base_of( $n );
			$t = null === $b ? null : $this->prepared->alignment->translation_of( $b );

			return null !== $t && $this->has( $t );
		}

		foreach ( $units as $unit ) {
			$match = $this->prepared->matching->match_of( $unit );

			if ( null === $match || ( Matcher::STATUS_REUSED !== $match->status && Matcher::STATUS_FUZZY !== $match->status ) ) {
				continue;
			}

			$node = $match->translation?->node;

			if ( null !== $node && $this->has( $node ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How many person's blocks the proposal changes or removes: the output's own part differs, or the source block is gone
	 * and no output block keeps it byte for byte (a moved block matches as removed and new, its text reused elsewhere).
	 *
	 * @param array<string, string> $own_by_origin The own part's hash of each output block, by its source block path key.
	 * @return int
	 */
	public function changed( array $own_by_origin ): int {
		if ( $this->none() ) {
			return 0;
		}

		$count = 0;
		$tree  = $this->prepared->seg_t->tree;

		foreach ( $this->prepared->alignment->pairs as [ $b, $t ] ) {
			if ( ! $this->has( $t ) ) {
				continue;
			}

			$n   = $this->prepared->matching->source_of( $b );
			$own = self::own( $t, $tree );

			if ( null !== $n ) {
				if ( ( $own_by_origin[ Segmentation::path_key( $n ) ] ?? null ) !== $own ) {
					++$count;
				}

				continue;
			}

			if ( ! $this->kept_elsewhere( $t, $own, $own_by_origin ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * The parts that replace a person's text: changed units whose translation stands in a person's block, by their
	 * {@see Matching::$units} index; a unit of a protected block is no part.
	 *
	 * @return list<int>
	 */
	public function units(): array {
		if ( $this->none() ) {
			return [];
		}

		$out = [];

		foreach ( $this->prepared->matching->units as $index => $match ) {
			$node = $match->translation?->node;

			if ( Matcher::STATUS_CHANGED === $match->status && null !== $node && $this->has( $node ) && ! $this->prepared->protected_elsewhere( $match->unit ) ) {
				$out[] = $index;
			}
		}

		return $out;
	}

	/**
	 * Whether an output block keeps a person's block whose source is gone, byte for byte: the output block of a source
	 * block with a unit matched to that block's text has the same own part.
	 *
	 * @param BlockNode             $t             The person's translation block.
	 * @param string                $own           Its own part's hash.
	 * @param array<string, string> $own_by_origin The own part's hash of each output block, by its source block path key.
	 * @return bool
	 */
	private function kept_elsewhere( BlockNode $t, string $own, array $own_by_origin ): bool {
		foreach ( $this->prepared->matching->units as $match ) {
			if ( $match->translation?->node !== $t || null === $match->unit->node ) {
				continue;
			}

			if ( ( $own_by_origin[ Segmentation::path_key( $match->unit->node ) ] ?? null ) === $own ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A new source block by path key.
	 *
	 * @param string $path Path key.
	 * @return BlockNode|null
	 */
	private function source_block( string $path ): ?BlockNode {
		if ( null === $this->source_blocks ) {
			$this->source_blocks = [];

			foreach ( $this->prepared->seg_n->tree->nodes as $node ) {
				$this->index( $node );
			}
		}

		return $this->source_blocks[ $path ] ?? null;
	}

	/**
	 * Index a source subtree by path key.
	 *
	 * @param BlockNode $node Node.
	 * @return void
	 */
	private function index( BlockNode $node ): void {
		if ( null !== $node->name ) {
			$this->source_blocks[ Segmentation::path_key( $node ) ] = $node;
		}

		foreach ( $node->children as $child ) {
			$this->index( $child );
		}
	}

	/**
	 * Whether a map entry is a carried mark: `[MARK, own]`.
	 *
	 * @param mixed $entry Entry.
	 * @return bool
	 * @phpstan-assert-if-true array{0: string, 1: string} $entry
	 */
	private static function is_carried( mixed $entry ): bool {
		return is_array( $entry ) && 2 === count( $entry ) && self::MARK === ( $entry[0] ?? null ) && isset( $entry[1] ) && is_string( $entry[1] ) && '' !== $entry[1];
	}

	/**
	 * Whether a map entry is marked.
	 *
	 * @param array<mixed> $entry Entry.
	 * @return bool
	 */
	private static function marked( array $entry ): bool {
		return self::MARK === ( $entry[3] ?? null );
	}

	/**
	 * The own part's hash an entry recorded: its own hash when it has one, else its hash (a block without children).
	 *
	 * @param array<mixed> $entry Entry.
	 * @return string
	 */
	private static function recorded_own( array $entry ): string {
		if ( isset( $entry[4] ) && is_string( $entry[4] ) ) {
			return $entry[4];
		}

		return is_string( $entry[1] ?? null ) ? $entry[1] : '';
	}
}
