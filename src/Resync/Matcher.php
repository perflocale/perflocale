<?php
/**
 * New-source-to-base matching.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Util\Lcs;
use PerfLocale\Util\TextTokens;
use PerfLocale\Util\WorkBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches the new source's blocks and units with the base's (both in the source language), per pair of aligned
 * parents: identical blocks, then equal unit keys, then similarity by name; over a cell budget a fallback runs and the
 * matching is flagged `over_budget`. Units prefer a same-key structural partner, then any free same-key base unit.
 */
final class Matcher {

	/** Block classes. */
	public const CLASS_IDENTICAL  = 'identical';
	public const CLASS_TEXT_EQUAL = 'text_equal';
	public const CLASS_CHANGED    = 'changed';
	public const CLASS_NEW        = 'new';

	/** Unit statuses. */
	public const STATUS_REUSED                 = 'reused';
	public const STATUS_REMOVED_IN_TRANSLATION = 'removed_in_translation';
	public const STATUS_FUZZY                  = 'fuzzy';
	public const STATUS_CHANGED                = 'changed';
	public const STATUS_NEW                    = 'new';
	public const STATUS_REMOVED                = 'removed';

	/** Count key: reused translations equal to their base text. */
	public const SAME_AS_SOURCE = 'same_as_source';

	/** Least text similarity, in percent, for two blocks of one name to pair when their counts differ. */
	public const BLOCK_SIMILARITY = 50.0;

	/** Most cells an LCS table over equal tokens may have. */
	private const CELL_BUDGET = 250000;

	/** Most cells a weighted table over text similarity may have (a gap of 200 blocks of one name against 200). */
	private const SIMILARITY_CELL_BUDGET = 40000;

	/** The lowest near-match threshold; a threshold under it is exact only. */
	private const MIN_THRESHOLD = 80;

	/** Fewest tokens for a reused translation to count as same as source. */
	private const MIN_SAME_TOKENS = 2;

	/** The multiset hash is a masked sum of per-key values, so it never overflows. */
	private const MULTISET_MASK = 0x3FFFFFFFFFFFFFFF;

	/**
	 * The measured peak of one unit's similarity tokens per byte of its text (6.3 to 28.3 across scripts and HTML), plus
	 * {@see self::PEAK_PER_TOKEN_UNIT} per unit.
	 */
	private const PEAK_PER_TOKEN_BYTE = 30;

	/** The same per unit on top of its bytes: units of 100 bytes to 1 KB peak at up to 4 KB (measured). */
	private const PEAK_PER_TOKEN_UNIT = 1800;

	/** The memory a block's list holds per token it copies from its children: 16 to 47.5 bytes measured. */
	private const PEAK_PER_COPIED_TOKEN = 48;

	/** The memory a block's token counts hold per token of its list: up to 127 bytes measured (all distinct). */
	private const PEAK_PER_COUNTED_TOKEN = 128;

	/**
	 * The source segmentation.
	 *
	 * @var Segmentation
	 */
	private readonly Segmentation $source;

	/**
	 * The base segmentation.
	 *
	 * @var Segmentation
	 */
	private readonly Segmentation $base;

	/**
	 * The base-to-translation alignment.
	 *
	 * @var Alignment
	 */
	private readonly Alignment $alignment;

	/**
	 * The near-match threshold, 80 to 100.
	 *
	 * @var int
	 */
	private readonly int $threshold;

	/**
	 * Whether a pass ran its over-budget fallback.
	 *
	 * @var bool
	 */
	private bool $over_budget = false;

	/**
	 * Whether the matching stopped because a block's similarity tokens would not fit memory ({@see self::fits()}).
	 *
	 * @var bool
	 */
	private bool $memory = false;

	/**
	 * The request's memory limit in bytes; null when unbounded.
	 *
	 * @var int|null
	 */
	private readonly ?int $limit;

	/**
	 * Canon hash per node id.
	 *
	 * @var array<int, string>
	 */
	private array $canon = [];

	/**
	 * Unit-key multiset of a subtree per node id: a masked sum and a count.
	 *
	 * @var array<int, array{int, int}>
	 */
	private array $multiset = [];

	/**
	 * Similarity tokens of a subtree per node id.
	 *
	 * @var array<int, list<string>>
	 */
	private array $tokens = [];

	/**
	 * The counts of those tokens per node id.
	 *
	 * @var array<int, array<string, int>>
	 */
	private array $counts = [];

	/**
	 * The byte-exact parts of a subtree per node id (Keys::exact_of()).
	 *
	 * @var array<int, string>
	 */
	private array $exact = [];

	/**
	 * The byte-exact parts of a unit per unit id (exact_unit()).
	 *
	 * @var array<int, string>
	 */
	private array $unit_exact = [];

	/**
	 * Paired blocks, source first.
	 *
	 * @var list<array{BlockNode, BlockNode}>
	 */
	private array $pairs = [];

	/**
	 * Class per paired source block id.
	 *
	 * @var array<int, string>
	 */
	private array $classes = [];

	/**
	 * Base blocks with no source partner.
	 *
	 * @var list<BlockNode>
	 */
	private array $removed_blocks = [];

	/**
	 * Structural partner per source unit id.
	 *
	 * @var array<int, Unit>
	 */
	private array $structural = [];

	/**
	 * Constructor.
	 *
	 * @param Segmentation $source    Source.
	 * @param Segmentation $base      Base.
	 * @param Alignment    $alignment Alignment.
	 * @param int          $threshold Threshold.
	 */
	private function __construct( Segmentation $source, Segmentation $base, Alignment $alignment, int $threshold ) {
		$this->source    = $source;
		$this->base      = $base;
		$this->alignment = $alignment;
		$this->threshold = $threshold >= self::MIN_THRESHOLD && $threshold <= 100 ? $threshold : 100;
		$this->limit     = WorkBudget::memory_limit();
	}

	/**
	 * Match a new source with its base.
	 *
	 * @param Segmentation     $source        The new source, segmented.
	 * @param Segmentation     $base          The base, segmented.
	 * @param Alignment        $alignment     The base-to-translation alignment.
	 * @param int              $threshold     `resync_match_threshold`: 100 = exact matches only; 80 to 99 keeps near matches as `fuzzy`; any other value is exact only.
	 * @param array<int, Unit> $source_fields The source's title, excerpt and field units.
	 * @param array<int, Unit> $base_fields   The base's title, excerpt and field units.
	 * @return Matching
	 * @phpstan-param list<Unit> $source_fields
	 * @phpstan-param list<Unit> $base_fields
	 */
	public static function match( Segmentation $source, Segmentation $base, Alignment $alignment, int $threshold = 100, array $source_fields = [], array $base_fields = [] ): Matching {
		$run = new self( $source, $base, $alignment, $threshold );

		foreach ( $source->tree->nodes as $node ) {
			$run->hash( $node, $source );
		}

		foreach ( $base->tree->nodes as $node ) {
			$run->hash( $node, $base );
		}

		$run->match_lists( $source->tree->nodes, $base->tree->nodes );

		if ( $run->memory ) {
			return new Matching( $source, $base, [], [], [], [], [], $run->over_budget, true );
		}

		[ $units, $removed_units ] = $run->match_units( $source_fields, $base_fields );

		return new Matching( $source, $base, $run->pairs, $run->classes, $units, $run->removed_blocks, $removed_units, $run->over_budget );
	}

	/**
	 * The canon hash and unit-key multiset of a subtree, bottom-up.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return string The canon hash.
	 */
	private function hash( BlockNode $node, Segmentation $segmentation ): string {
		$children = [];
		$exact    = [];
		$sum      = 0;
		$count    = 0;

		foreach ( $node->children as $child ) {
			$children[] = $this->hash( $child, $segmentation );
			$exact[]    = $this->exact[ spl_object_id( $child ) ];

			[ $child_sum, $child_count ] = $this->multiset[ spl_object_id( $child ) ];

			$sum    = ( $sum + $child_sum ) & self::MULTISET_MASK;
			$count += $child_count;
		}

		foreach ( $segmentation->units_of( $node ) as $unit ) {
			$sum = ( $sum + (int) hexdec( substr( $unit->key, 0, 15 ) ) ) & self::MULTISET_MASK;
			++$count;
		}

		$id = spl_object_id( $node );

		$this->multiset[ $id ] = [ $sum, $count ];

		$this->canon[ $id ] = Keys::canon_of( $node, $segmentation->tree, $children );
		$this->exact[ $id ] = Keys::exact_of( $node, $segmentation->tree, $exact );

		return $this->canon[ $id ];
	}

	/**
	 * The similarity tokens of a subtree: its units' tokens and its children's. Each step is checked against memory first
	 * ({@see self::fits()}); once one does not fit the matching stops and every list is empty.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return list<string>
	 * @phpstan-impure
	 */
	private function tokens_of( BlockNode $node, Segmentation $segmentation ): array {
		$id = spl_object_id( $node );

		if ( isset( $this->tokens[ $id ] ) ) {
			return $this->tokens[ $id ];
		}

		if ( $this->memory ) {
			return [];
		}

		$children = [];
		$copied   = 0;

		foreach ( $node->children as $child ) {
			$list = $this->tokens_of( $child, $segmentation );

			if ( $this->memory ) {
				return [];
			}

			$children[] = $list;
			$copied    += count( $list );
		}

		$out = [];

		foreach ( $segmentation->units_of( $node ) as $unit ) {
			if ( ! $this->fits( strlen( $unit->text ) * self::PEAK_PER_TOKEN_BYTE + self::PEAK_PER_TOKEN_UNIT ) ) {
				return [];
			}

			foreach ( TextTokens::tokens( $unit->text ) as $token ) {
				$out[] = $token;
			}
		}

		if ( ! $this->fits( $copied * self::PEAK_PER_COPIED_TOKEN + ( count( $out ) + $copied ) * self::PEAK_PER_COUNTED_TOKEN ) ) {
			return [];
		}

		foreach ( $children as $list ) {
			foreach ( $list as $token ) {
				$out[] = $token;
			}
		}

		$this->tokens[ $id ] = $out;
		$this->counts[ $id ] = array_count_values( $out );

		return $out;
	}

	/**
	 * Whether a token step's projected peak on top of the memory in use leaves the engine's reserve under the limit (what
	 * earlier steps hold is counted as it is); else the matching stops ({@see Matching::$memory}). Always when unbounded.
	 *
	 * @param int $peak The step's projected peak, in bytes.
	 * @return bool
	 */
	private function fits( int $peak ): bool {
		if ( null === $this->limit || memory_get_usage() + $peak + Engine::reserve() <= $this->limit ) {
			return true;
		}

		$this->memory = true;

		return false;
	}

	/**
	 * Match two child lists, then the children of each pair.
	 *
	 * @param array<int, BlockNode> $n_nodes Source nodes.
	 * @param array<int, BlockNode> $b_nodes Base nodes.
	 * @return void
	 * @phpstan-param list<BlockNode> $n_nodes
	 * @phpstan-param list<BlockNode> $b_nodes
	 */
	private function match_lists( array $n_nodes, array $b_nodes ): void {
		if ( $this->memory ) {
			return;
		}

		$ns = Aligner::elements( $n_nodes, $this->source );
		$bs = Aligner::elements( $b_nodes, $this->base );

		if ( $ns === [] && $bs === [] ) {
			return;
		}

		$pair_n = [];
		$pair_b = [];

		// 1. Exact anchors: identical blocks, in order.
		$intern = [];
		$tn     = [];
		$tb     = [];

		foreach ( $ns as $n ) {
			$tn[] = $intern[ $this->canon[ spl_object_id( $n ) ] ] ??= count( $intern );
		}

		foreach ( $bs as $b ) {
			$tb[] = $intern[ $this->canon[ spl_object_id( $b ) ] ] ??= count( $intern );
		}

		foreach ( $this->equal_pairs( $tn, $tb ) as [ $i, $j ] ) {
			$pair_n[ $i ] = $j;
			$pair_b[ $j ] = $i;
		}

		// 2. Between exact anchors: the same name and the same non-empty unit keys.
		foreach ( self::gaps( $pair_n, $pair_b, count( $ns ), count( $bs ) ) as [ $gap_n, $gap_b ] ) {
			if ( $gap_n === [] || $gap_b === [] ) {
				continue;
			}

			$intern = [];
			$tn     = [];
			$tb     = [];

			foreach ( $gap_n as $i ) {
				$tn[] = $intern[ $this->text_token( $ns[ $i ], 'n' . $i ) ] ??= count( $intern );
			}

			foreach ( $gap_b as $j ) {
				$tb[] = $intern[ $this->text_token( $bs[ $j ], 'b' . $j ) ] ??= count( $intern );
			}

			foreach ( $this->equal_pairs( $tn, $tb ) as [ $k, $l ] ) {
				$pair_n[ $gap_n[ $k ] ] = $gap_b[ $l ];
				$pair_b[ $gap_b[ $l ] ] = $gap_n[ $k ];
			}
		}

		// 3. Between anchors, per block name: equal counts in order, else by similarity.
		foreach ( self::gaps( $pair_n, $pair_b, count( $ns ), count( $bs ) ) as [ $gap_n, $gap_b ] ) {
			if ( $gap_n === [] || $gap_b === [] ) {
				continue;
			}

			$by_name_n = [];
			$by_name_b = [];

			foreach ( $gap_n as $i ) {
				$by_name_n[ $ns[ $i ]->name ?? '' ][] = $i;
			}

			foreach ( $gap_b as $j ) {
				$by_name_b[ $bs[ $j ]->name ?? '' ][] = $j;
			}

			foreach ( $by_name_n as $name => $list_n ) {
				$list_b = $by_name_b[ $name ] ?? [];

				if ( $list_b === [] ) {
					continue;
				}

				foreach ( $this->pair_by_name( $ns, $bs, $list_n, $list_b ) as [ $i, $j ] ) {
					$pair_n[ $i ] = $j;
					$pair_b[ $j ] = $i;
				}

				if ( $this->memory ) {
					return;
				}
			}
		}

		foreach ( $ns as $i => $n ) {
			if ( ! isset( $pair_n[ $i ] ) ) {
				continue;
			}

			$b = $bs[ $pair_n[ $i ] ];

			$this->pairs[]                        = [ $n, $b ];
			$this->classes[ spl_object_id( $n ) ] = $this->class_of( $n, $b );

			if ( $n->children !== [] || $b->children !== [] ) {
				$this->match_lists( $n->children, $b->children );
			}
		}

		foreach ( $bs as $j => $b ) {
			if ( ! isset( $pair_b[ $j ] ) ) {
				$this->removed_blocks[] = $b;
			}
		}
	}

	/**
	 * The LCS of two token lists, or the k-th with the k-th over the budget, which flags the matching.
	 *
	 * @param array<int, int> $a Tokens.
	 * @param array<int, int> $b Tokens.
	 * @return list<array{int, int}>
	 * @phpstan-param list<int> $a
	 * @phpstan-param list<int> $b
	 */
	private function equal_pairs( array $a, array $b ): array {
		$pairs = Lcs::pairs( $a, $b, self::CELL_BUDGET );

		if ( $pairs !== null ) {
			return $pairs;
		}

		$this->over_budget = true;

		return Lcs::in_order( $a, $b );
	}

	/**
	 * The unpaired elements between consecutive pairs of the longest chain ascending on both sides (and the list ends),
	 * per gap on both sides; a crossed pair is in no gap.
	 *
	 * @param array<int, int> $pair_n Base index per paired source index.
	 * @param array<int, int> $pair_b Source index per paired base index.
	 * @param int             $n      Source count.
	 * @param int             $m      Base count.
	 * @return list<array{list<int>, list<int>}>
	 */
	private static function gaps( array $pair_n, array $pair_b, int $n, int $m ): array {
		$chain   = Lcs::monotone( $pair_n );
		$chain[] = [ $n, $m ];
		$out     = [];
		$from_n  = 0;
		$from_b  = 0;

		foreach ( $chain as [ $i, $j ] ) {
			$out[] = [ self::free( $from_n, $i, $pair_n ), self::free( $from_b, $j, $pair_b ) ];

			$from_n = $i + 1;
			$from_b = $j + 1;
		}

		return $out;
	}

	/**
	 * The unpaired indexes from one bound to another, end exclusive.
	 *
	 * @param int             $from   First.
	 * @param int             $to     One past the last.
	 * @param array<int, int> $paired The paired indexes, as keys.
	 * @return list<int>
	 */
	private static function free( int $from, int $to, array $paired ): array {
		$out = [];

		for ( $i = $from; $i < $to; $i++ ) {
			if ( ! isset( $paired[ $i ] ) ) {
				$out[] = $i;
			}
		}

		return $out;
	}

	/**
	 * The token of a block for the text-equal pass: its name and subtree unit-key hash, or a unique token (pairing with
	 * nothing) when it has no units.
	 *
	 * @param BlockNode $node   Block.
	 * @param string    $unique A token unique to the block.
	 * @return string
	 */
	private function text_token( BlockNode $node, string $unique ): string {
		[ $sum, $count ] = $this->multiset[ spl_object_id( $node ) ];

		return $count === 0 ? "\0" . $unique : ( $node->name ?? '' ) . "\0" . $sum . "\0" . $count;
	}

	/**
	 * Pair the blocks of one name in a gap: equal counts in order, else an order-preserving weighted LCS over text
	 * similarity (pairs under BLOCK_SIMILARITY never form); over the cell budget a banded table, which flags the matching.
	 *
	 * @param array<int, BlockNode> $ns     Source elements.
	 * @param array<int, BlockNode> $bs     Base elements.
	 * @param array<int, int>       $list_n Source indexes of the name, in order.
	 * @param array<int, int>       $list_b Base indexes of the name, in order.
	 * @return list<array{int, int}> Element index pairs.
	 * @phpstan-param list<BlockNode> $ns
	 * @phpstan-param list<BlockNode> $bs
	 * @phpstan-param list<int> $list_n
	 * @phpstan-param list<int> $list_b
	 * @phpstan-impure
	 */
	private function pair_by_name( array $ns, array $bs, array $list_n, array $list_b ): array {
		$out = [];

		if ( count( $list_n ) === count( $list_b ) ) {
			foreach ( $list_n as $k => $i ) {
				$out[] = [ $i, $list_b[ $k ] ];
			}

			return $out;
		}

		$tokens_n = [];
		$tokens_b = [];
		$counts_n = [];
		$counts_b = [];
		$length_n = [];
		$length_b = [];

		foreach ( $list_n as $k => $i ) {
			$tokens_n[ $k ] = $this->tokens_of( $ns[ $i ], $this->source );
			$counts_n[ $k ] = $this->counts[ spl_object_id( $ns[ $i ] ) ] ?? [];
			$length_n[ $k ] = count( $tokens_n[ $k ] );
		}

		foreach ( $list_b as $l => $j ) {
			$tokens_b[ $l ] = $this->tokens_of( $bs[ $j ], $this->base );
			$counts_b[ $l ] = $this->counts[ spl_object_id( $bs[ $j ] ) ] ?? [];
			$length_b[ $l ] = count( $tokens_b[ $l ] );
		}

		if ( $this->memory ) {
			return [];
		}

		// Dice is at most 2·min(|a|, |b|) / (|a| + |b|): under the block similarity, the pair cannot form.
		$weight = static function ( int $k, int $l ) use ( $tokens_n, $tokens_b, $counts_n, $counts_b, $length_n, $length_b ): float {
			$shorter = $length_n[ $k ] < $length_b[ $l ] ? $length_n[ $k ] : $length_b[ $l ];

			if ( 200.0 * $shorter < self::BLOCK_SIMILARITY * ( $length_n[ $k ] + $length_b[ $l ] ) ) {
				return 0.0;
			}

			return TextTokens::dice( $tokens_n[ $k ], $tokens_b[ $l ], $counts_n[ $k ], $counts_b[ $l ] );
		};

		$pairs = Lcs::weighted( count( $list_n ), count( $list_b ), $weight, self::BLOCK_SIMILARITY, self::SIMILARITY_CELL_BUDGET );

		if ( $pairs === null ) {
			$this->over_budget = true;

			$pairs = Lcs::banded( count( $list_n ), count( $list_b ), $weight, self::BLOCK_SIMILARITY, self::SIMILARITY_CELL_BUDGET );
		}

		foreach ( $pairs as [ $k, $l ] ) {
			$out[] = [ $list_n[ $k ], $list_b[ $l ] ];
		}

		return $out;
	}

	/**
	 * The class of a paired source block.
	 *
	 * @param BlockNode $n Source block.
	 * @param BlockNode $b Its partner.
	 * @return string
	 */
	private function class_of( BlockNode $n, BlockNode $b ): string {
		if ( $this->canon[ spl_object_id( $n ) ] === $this->canon[ spl_object_id( $b ) ] && $this->exact[ spl_object_id( $n ) ] === $this->exact[ spl_object_id( $b ) ] ) {
			return self::CLASS_IDENTICAL;
		}

		if ( $this->multiset[ spl_object_id( $n ) ] === $this->multiset[ spl_object_id( $b ) ] ) {
			return self::CLASS_TEXT_EQUAL;
		}

		return self::CLASS_CHANGED;
	}

	/**
	 * Match every source unit: exact keys through the structural partner first, then any free same-key base unit (those
	 * with a translation first); else the structural partner by similarity against the threshold, or new.
	 *
	 * @param array<int, Unit> $source_fields Source field units.
	 * @param array<int, Unit> $base_fields   Base field units.
	 * @return array{list<UnitMatch>, list<Unit>} The matches in source order, and the removed base units.
	 * @phpstan-param list<Unit> $source_fields
	 * @phpstan-param list<Unit> $base_fields
	 */
	private function match_units( array $source_fields, array $base_fields ): array {
		foreach ( $this->pairs as [ $n, $b ] ) {
			$n_units = $this->source->units_of( $n );
			$b_units = $this->base->units_of( $b );

			foreach ( Aligner::pair_units( $n_units, $b_units ) as [ $k, $l ] ) {
				$this->structural[ spl_object_id( $n_units[ $k ] ) ] = $b_units[ $l ];
			}
		}

		$by_role = [];

		foreach ( $base_fields as $unit ) {
			$by_role[ $unit->role ][] = $unit;
		}

		$seen = [];

		foreach ( $source_fields as $unit ) {
			$k                   = $seen[ $unit->role ] ?? 0;
			$seen[ $unit->role ] = $k + 1;

			if ( isset( $by_role[ $unit->role ][ $k ] ) ) {
				$this->structural[ spl_object_id( $unit ) ] = $by_role[ $unit->role ][ $k ];
			}
		}

		$n_units = array_merge( $this->source->units, $source_fields );
		$b_units = array_merge( $this->base->units, $base_fields );
		$taken   = [];
		$exact   = [];

		// A structural partner with the same key wins.
		foreach ( $n_units as $unit ) {
			$partner = $this->structural[ spl_object_id( $unit ) ] ?? null;

			if ( $partner !== null && $partner->key === $unit->key && $this->exact_unit( $partner ) === $this->exact_unit( $unit ) && ! isset( $taken[ spl_object_id( $partner ) ] ) ) {
				$taken[ spl_object_id( $partner ) ] = true;
				$exact[ spl_object_id( $unit ) ]    = $partner;
			}
		}

		// Then any free base unit with the same key, in document order, those with a translation unit preferred:
		// per key and exact parts, a queue with and one without a translation, each taken from its head.
		$free_by_key = [];

		foreach ( $b_units as $unit ) {
			if ( ! isset( $taken[ spl_object_id( $unit ) ] ) ) {
				$free_by_key[ $unit->key ][] = $unit;
			}
		}

		$queues = [];
		$next   = [];

		foreach ( $n_units as $unit ) {
			if ( isset( $exact[ spl_object_id( $unit ) ] ) ) {
				continue;
			}

			$key = $unit->key;

			if ( ! isset( $queues[ $key ] ) ) {
				$queues[ $key ] = [];

				foreach ( $free_by_key[ $key ] ?? [] as $candidate ) {
					$queues[ $key ][ $this->exact_unit( $candidate ) ][ null === $this->alignment->translation_unit_of( $candidate ) ? 1 : 0 ][] = $candidate;
				}
			}

			$parts  = $this->exact_unit( $unit );
			$chosen = null;

			foreach ( [ 0, 1 ] as $queue ) {
				$at = $next[ $key ][ $parts ][ $queue ] ?? 0;

				if ( isset( $queues[ $key ][ $parts ][ $queue ][ $at ] ) ) {
					$chosen                           = $queues[ $key ][ $parts ][ $queue ][ $at ];
					$next[ $key ][ $parts ][ $queue ] = $at + 1;
					break;
				}
			}

			if ( $chosen !== null ) {
				$taken[ spl_object_id( $chosen ) ] = true;
				$exact[ spl_object_id( $unit ) ]   = $chosen;
			}
		}

		$matches = [];

		foreach ( $n_units as $unit ) {
			$id      = spl_object_id( $unit );
			$partner = $exact[ $id ] ?? null;

			if ( $partner !== null ) {
				$translation = $this->alignment->translation_unit_of( $partner );
				$status      = $translation === null && ! $this->alignment->unsure( $partner ) ? self::STATUS_REMOVED_IN_TRANSLATION : self::STATUS_REUSED;
				$same        = $translation !== null && self::same_as_source( $partner, $translation );

				$matches[] = new UnitMatch( $unit, $status, $partner, $translation, 100.0, $same );
				continue;
			}

			$partner = $this->structural[ $id ] ?? null;

			if ( $partner === null || isset( $taken[ spl_object_id( $partner ) ] ) ) {
				$matches[] = new UnitMatch( $unit, self::STATUS_NEW, null, null, 0.0, false );
				continue;
			}

			$taken[ spl_object_id( $partner ) ] = true;

			$similarity = TextTokens::similarity( $unit->text, $partner->text );
			$status     = $this->threshold < 100 && $similarity >= $this->threshold ? self::STATUS_FUZZY : self::STATUS_CHANGED;

			$matches[] = new UnitMatch( $unit, $status, $partner, $this->alignment->translation_unit_of( $partner ), $similarity, false );
		}

		$removed = [];

		foreach ( $b_units as $unit ) {
			if ( ! isset( $taken[ spl_object_id( $unit ) ] ) ) {
				$removed[] = $unit;
			}
		}

		return [ $matches, $removed ];
	}

	/**
	 * The byte-exact parts of a unit: its whole text for `<pre>`/`<textarea>` content or an EXACT_BLOCKS text range, else
	 * Keys::exact() of its html; '' for text. Two units with one key are the same only when these are equal too.
	 *
	 * @param Unit $unit Unit.
	 * @return string
	 */
	private function exact_unit( Unit $unit ): string {
		$id = spl_object_id( $unit );

		if ( ! isset( $this->unit_exact[ $id ] ) ) {
			$whole = in_array( $unit->tag, [ 'pre', 'textarea' ], true )
				|| ( Unit::KIND_RANGE === $unit->kind && null !== $unit->node && in_array( (string) $unit->node->name, Keys::EXACT_BLOCKS, true ) );

			if ( $whole ) {
				$this->unit_exact[ $id ] = "whole\0" . $unit->text;
			} else {
				$this->unit_exact[ $id ] = BlockAttributeSource::FORMAT_HTML === $unit->format ? Keys::exact( $unit->text ) : '';
			}
		}

		return $this->unit_exact[ $id ];
	}

	/**
	 * Whether a reused translation's text equals its base text, with two tokens or more.
	 *
	 * @param Unit $base        Base unit.
	 * @param Unit $translation Its translation unit.
	 * @return bool
	 */
	private static function same_as_source( Unit $base, Unit $translation ): bool {
		return count( TextTokens::tokens( $base->text ) ) >= self::MIN_SAME_TOKENS
			&& Keys::norm( $translation->text, $translation->format ) === Keys::norm( $base->text, $base->format );
	}
}
