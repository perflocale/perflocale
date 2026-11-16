<?php
/**
 * Base-to-translation alignment.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Util\Lcs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aligns a base document's blocks with its translation's, per pair of aligned parents: block-map anchors first, then
 * an LCS over language-free signatures between them, then name and attributes; guesses are flagged `ambiguous` and
 * never applied automatically. Unaligned translation blocks are translation-only, unaligned base blocks removals.
 */
final class Aligner {

	/** Flag: a run of equal signatures paired by guess. */
	public const FLAG_AMBIGUOUS = 'ambiguous';

	/** Most cells an LCS table may have. */
	public const CELL_BUDGET = 250000;

	/** The name of a non-whitespace freeform chunk in a signature. */
	private const FREEFORM_NAME = '#html';

	/** The list block and its item block (a list saved before WordPress 6.0 has no item blocks). */
	private const LIST_BLOCK      = 'core/list';
	private const LIST_ITEM_BLOCK = 'core/list-item';

	/** Attribute names a translation localizes besides {@see BlockAttributeSource::IGNORE_NAMES}: media, links, refs. */
	private const LOCALIZED_NAMES = '/^(ref|ids|mediaId|mediaUrl|mediaLink|mediaType|mediaSizeSlug|sizeSlug|linkDestination|linkTarget|linkClass|linkTo|lightbox|poster|fileId|textLinkHref|providerNameSlug|videoPoster)$/i';

	/** The attribute that marks a block as skipped; not a structural value. */
	private const SKIP_MARKER = 'perflocaleSkipTranslation';

	/**
	 * The base segmentation.
	 *
	 * @var Segmentation
	 */
	private readonly Segmentation $base;

	/**
	 * The translation segmentation.
	 *
	 * @var Segmentation
	 */
	private readonly Segmentation $translation;

	/**
	 * Base block per translation block id, from the block map.
	 *
	 * @var array<int, BlockNode>
	 */
	private array $anchors = [];

	/**
	 * Full and coarse signature per node id.
	 *
	 * @var array<int, array{string, string}>
	 */
	private array $signatures = [];

	/**
	 * Text attribute names per block name, as a set.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $text_names = [];

	/**
	 * Aligned blocks, base first.
	 *
	 * @var list<array{BlockNode, BlockNode}>
	 */
	private array $pairs = [];

	/**
	 * Paired units, base first.
	 *
	 * @var list<array{Unit, Unit}>
	 */
	private array $unit_pairs = [];

	/**
	 * Base units left unpaired where their block's unit counts differ ({@see self::pair_across()}).
	 *
	 * @var list<Unit>
	 */
	private array $unsure = [];

	/**
	 * Blocks below a list whose units pair with a list of the other shape ({@see self::cross_shape()}).
	 *
	 * @var list<BlockNode>
	 */
	private array $carried = [];

	/**
	 * Base blocks with no translation block.
	 *
	 * @var list<BlockNode>
	 */
	private array $removed = [];

	/**
	 * Translation blocks with no base block.
	 *
	 * @var list<BlockNode>
	 */
	private array $translation_only = [];

	/**
	 * Flags per base block path key.
	 *
	 * @var array<string, list<string>>
	 */
	private array $base_flags = [];

	/**
	 * Flags per translation block path key.
	 *
	 * @var array<string, list<string>>
	 */
	private array $translation_flags = [];

	/**
	 * Constructor.
	 *
	 * @param Segmentation $base        Base.
	 * @param Segmentation $translation Translation.
	 */
	private function __construct( Segmentation $base, Segmentation $translation ) {
		$this->base        = $base;
		$this->translation = $translation;
	}

	/**
	 * Align a base document with its translation.
	 *
	 * @param Segmentation     $base               The base, segmented.
	 * @param Segmentation     $translation        The translation, segmented.
	 * @param array<mixed>     $block_map          The last write's block map: `[[t_path, t_canon_hash16, b_path], ...]`, paths as {@see Segmentation::path_key()} gives them, an entry possibly followed by the person mark and the own part's hash ({@see PersonBlocks::entry()}); [] when none.
	 * @param array<int, Unit> $base_fields        The base's title, excerpt and field units.
	 * @param array<int, Unit> $translation_fields The translation's title, excerpt and field units.
	 * @return Alignment
	 * @phpstan-param list<Unit> $base_fields
	 * @phpstan-param list<Unit> $translation_fields
	 */
	public static function align( Segmentation $base, Segmentation $translation, array $block_map = [], array $base_fields = [], array $translation_fields = [] ): Alignment {
		$run = new self( $base, $translation );

		$run->resolve_anchors( $block_map );
		$run->align_lists( $base->tree->nodes, $translation->tree->nodes );
		$run->pair_fields( $base_fields, $translation_fields );

		ksort( $run->base_flags, SORT_NATURAL );
		ksort( $run->translation_flags, SORT_NATURAL );

		return new Alignment( $base, $translation, $run->pairs, $run->unit_pairs, $run->removed, $run->translation_only, $run->base_flags, $run->translation_flags, $run->unsure, $run->carried );
	}

	/**
	 * The signature of a block: what a translation keeps.
	 *
	 * @param BlockNode    $node         Block or freeform node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return string
	 */
	public static function signature( BlockNode $node, Segmentation $segmentation ): string {
		return ( new self( $segmentation, $segmentation ) )->signature_of( $node, $segmentation )[0];
	}

	/**
	 * The nodes of a child list that take part in an alignment: blocks and freeform nodes holding text; whitespace
	 * freeform nodes are separators.
	 *
	 * @param array<int, BlockNode> $nodes        Nodes.
	 * @param Segmentation          $segmentation Their segmentation.
	 * @return list<BlockNode>
	 * @phpstan-param list<BlockNode> $nodes
	 */
	public static function elements( array $nodes, Segmentation $segmentation ): array {
		$out = [];

		foreach ( $nodes as $node ) {
			if ( $node->is_freeform() && ( $segmentation->chunks[ Segmentation::path_key( $node ) ][0] ?? Segmenter::CHUNK_SEPARATOR ) === Segmenter::CHUNK_SEPARATOR ) {
				continue;
			}

			$out[] = $node;
		}

		return $out;
	}

	/**
	 * Pair the units of two aligned blocks: table cells by row and column when both have the same shape, else by index;
	 * freeform units by LCS over their element tags; the rest by role, attribute and occurrence index.
	 *
	 * @param array<int, Unit> $base        Base units.
	 * @param array<int, Unit> $translation Translation units.
	 * @return list<array{int, int}> Index pairs, ascending on the base side.
	 * @phpstan-param list<Unit> $base
	 * @phpstan-param list<Unit> $translation
	 */
	public static function pair_units( array $base, array $translation ): array {
		[ $b_cells, $b_regions, $b_rest ] = self::unit_groups( $base );
		[ $t_cells, $t_regions, $t_rest ] = self::unit_groups( $translation );

		$pairs = [];

		if ( $b_cells !== [] && $t_cells !== [] ) {
			if ( self::grid( $base, $b_cells ) === self::grid( $translation, $t_cells ) ) {
				$by_coordinate = [];

				foreach ( $t_cells as $j ) {
					$by_coordinate[ self::coordinate( $translation[ $j ] ) ] = $j;
				}

				foreach ( $b_cells as $i ) {
					$coordinate = self::coordinate( $base[ $i ] );

					if ( isset( $by_coordinate[ $coordinate ] ) ) {
						$pairs[] = [ $i, $by_coordinate[ $coordinate ] ];
					}
				}
			} else {
				$count = min( count( $b_cells ), count( $t_cells ) );

				for ( $k = 0; $k < $count; $k++ ) {
					$pairs[] = [ $b_cells[ $k ], $t_cells[ $k ] ];
				}
			}
		}

		if ( $b_regions !== [] && $t_regions !== [] ) {
			$intern = [];
			$tb     = [];
			$tt     = [];

			foreach ( $b_regions as $i ) {
				$tb[] = $intern[ $base[ $i ]->tag . "\0" . $base[ $i ]->role ] ??= count( $intern );
			}

			foreach ( $t_regions as $j ) {
				$tt[] = $intern[ $translation[ $j ]->tag . "\0" . $translation[ $j ]->role ] ??= count( $intern );
			}

			foreach ( Lcs::pairs( $tb, $tt, self::CELL_BUDGET ) ?? Lcs::in_order( $tb, $tt ) as [ $k, $l ] ) {
				$pairs[] = [ $b_regions[ $k ], $t_regions[ $l ] ];
			}
		}

		$t_by_slot = [];

		foreach ( $t_rest as $j ) {
			$t_by_slot[ self::slot( $translation[ $j ] ) ][] = $j;
		}

		$seen = [];

		foreach ( $b_rest as $i ) {
			$slot          = self::slot( $base[ $i ] );
			$k             = $seen[ $slot ] ?? 0;
			$seen[ $slot ] = $k + 1;

			if ( isset( $t_by_slot[ $slot ][ $k ] ) ) {
				$pairs[] = [ $i, $t_by_slot[ $slot ][ $k ] ];
			}
		}

		usort( $pairs, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		return $pairs;
	}

	/**
	 * Pair the units of two blocks in different languages. A unit kind with equal counts pairs as pair_units(); with
	 * unequal counts only units whose fingerprint occurs once per side pair (LCS), table rows by row fingerprint. No pair
	 * rests on position alone: the kind is ambiguous and its unpaired base units unsure; over the LCS budget, none pair.
	 *
	 * @param array<int, Unit> $base        Units of the one side (base or source).
	 * @param array<int, Unit> $translation Units of the translation.
	 * @return array{pairs: list<array{int, int}>, ambiguous: bool, unsure: list<int>} Index pairs ascending on the base side, whether a kind's counts differ, and the unpaired base indexes of those kinds.
	 * @phpstan-param list<Unit> $base
	 * @phpstan-param list<Unit> $translation
	 */
	public static function pair_across( array $base, array $translation ): array {
		[ $b_cells, $b_regions, $b_rest ] = self::unit_groups( $base );
		[ $t_cells, $t_regions, $t_rest ] = self::unit_groups( $translation );

		$pairs     = [];
		$ambiguous = false;
		$unsure    = [];

		if ( $b_cells !== [] && $t_cells !== [] ) {
			$b_grid = self::grid( $base, $b_cells );
			$t_grid = self::grid( $translation, $t_cells );

			if ( $b_grid === $t_grid ) {
				foreach ( self::pair_units( self::pick( $base, $b_cells ), self::pick( $translation, $t_cells ) ) as [ $k, $l ] ) {
					$pairs[] = [ $b_cells[ $k ], $t_cells[ $l ] ];
				}
			} else {
				$ambiguous = true;
				$found     = $b_grid[1] === $t_grid[1] ? self::row_pairs( $base, $b_cells, $translation, $t_cells ) : [];
				$pairs     = array_merge( $pairs, $found );
				$unsure    = array_merge( $unsure, self::unpaired( $b_cells, $found ) );
			}
		}

		if ( $b_regions !== [] && $t_regions !== [] ) {
			if ( count( $b_regions ) === count( $t_regions ) ) {
				foreach ( self::pair_units( self::pick( $base, $b_regions ), self::pick( $translation, $t_regions ) ) as [ $k, $l ] ) {
					$pairs[] = [ $b_regions[ $k ], $t_regions[ $l ] ];
				}
			} else {
				$ambiguous = true;
				$found     = self::decided( $base, $b_regions, $translation, $t_regions );
				$pairs     = array_merge( $pairs, $found );
				$unsure    = array_merge( $unsure, self::unpaired( $b_regions, $found ) );
			}
		}

		$b_by_slot = [];
		$t_by_slot = [];

		foreach ( $b_rest as $i ) {
			$b_by_slot[ self::slot( $base[ $i ] ) ][] = $i;
		}

		foreach ( $t_rest as $j ) {
			$t_by_slot[ self::slot( $translation[ $j ] ) ][] = $j;
		}

		foreach ( $b_by_slot as $slot => $b_slot ) {
			$t_slot = $t_by_slot[ $slot ] ?? [];

			if ( $t_slot === [] ) {
				continue;
			}

			if ( count( $b_slot ) === count( $t_slot ) ) {
				foreach ( $b_slot as $k => $i ) {
					$pairs[] = [ $i, $t_slot[ $k ] ];
				}

				continue;
			}

			$ambiguous = true;
			$found     = self::decided( $base, $b_slot, $translation, $t_slot );
			$pairs     = array_merge( $pairs, $found );
			$unsure    = array_merge( $unsure, self::unpaired( $b_slot, $found ) );
		}

		usort( $pairs, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		sort( $unsure );

		return [
			'pairs'     => $pairs,
			'ambiguous' => $ambiguous,
			'unsure'    => $unsure,
		];
	}

	/**
	 * The units at some indexes, as a list.
	 *
	 * @param array<int, Unit> $units   Units.
	 * @param array<int, int>  $indexes Indexes.
	 * @return list<Unit>
	 * @phpstan-param list<Unit> $units
	 * @phpstan-param list<int> $indexes
	 */
	private static function pick( array $units, array $indexes ): array {
		$out = [];

		foreach ( $indexes as $i ) {
			$out[] = $units[ $i ];
		}

		return $out;
	}

	/**
	 * The indexes of a group no pair names on the base side.
	 *
	 * @param array<int, int>             $group Indexes.
	 * @param array<int, array{int, int}> $pairs Pairs, base first.
	 * @return list<int>
	 * @phpstan-param list<int> $group
	 * @phpstan-param list<array{int, int}> $pairs
	 */
	private static function unpaired( array $group, array $pairs ): array {
		$paired = [];
		$out    = [];

		foreach ( $pairs as [ $i ] ) {
			$paired[ $i ] = true;
		}

		foreach ( $group as $i ) {
			if ( ! isset( $paired[ $i ] ) ) {
				$out[] = $i;
			}
		}

		return $out;
	}

	/**
	 * The pairs the fingerprints of two groups of units decide, as unit index pairs ({@see self::certain()}).
	 *
	 * @param array<int, Unit> $base        Units of the one side.
	 * @param array<int, int>  $b_group     Indexes of the group on that side.
	 * @param array<int, Unit> $translation Units of the other side.
	 * @param array<int, int>  $t_group     Indexes of the group on that side.
	 * @return list<array{int, int}>
	 * @phpstan-param list<Unit> $base
	 * @phpstan-param list<int> $b_group
	 * @phpstan-param list<Unit> $translation
	 * @phpstan-param list<int> $t_group
	 */
	private static function decided( array $base, array $b_group, array $translation, array $t_group ): array {
		$a     = [];
		$b     = [];
		$pairs = [];

		foreach ( $b_group as $i ) {
			$a[] = self::fingerprint( $base[ $i ] );
		}

		foreach ( $t_group as $j ) {
			$b[] = self::fingerprint( $translation[ $j ] );
		}

		foreach ( self::certain( $a, $b ) as [ $k, $l ] ) {
			$pairs[] = [ $b_group[ $k ], $t_group[ $l ] ];
		}

		return $pairs;
	}

	/**
	 * What of a unit survives translation: role, element tag, html inline-tag skeleton, digit runs and URLs, media id.
	 *
	 * @param Unit $unit Unit.
	 * @return string
	 */
	private static function fingerprint( Unit $unit ): string {
		return implode(
			"\0",
			[
				$unit->role,
				$unit->tag,
				$unit->format === BlockAttributeSource::FORMAT_HTML ? Keys::skeleton( $unit->text ) : '',
				implode( ' ', Keys::figures( $unit->text, $unit->format ) ),
				self::media_id( $unit ),
			]
		);
	}

	/**
	 * The media id of a query item's unit (a legacy gallery image): the block's `ids` entry at its index; '' when none.
	 *
	 * @param Unit $unit Unit.
	 * @return string
	 */
	private static function media_id( Unit $unit ): string {
		$attrs = $unit->node?->attrs;
		$ids   = is_array( $attrs ) ? ( $attrs['ids'] ?? null ) : null;
		$item  = $unit->path[1] ?? null;

		if ( ! is_array( $ids ) || ! is_int( $item ) || ! isset( $ids[ $item ] ) || ! is_int( $ids[ $item ] ) ) {
			return '';
		}

		return (string) $ids[ $item ];
	}

	/**
	 * The pairs two fingerprint lists decide: fingerprints occurring once per side pair in order (their LCS); every other
	 * position stays unpaired. Over the budget, none.
	 *
	 * @param array<int, string> $a Fingerprints of the one side.
	 * @param array<int, string> $b Fingerprints of the other side.
	 * @return list<array{int, int}> Position pairs, ascending on both sides.
	 * @phpstan-param list<string> $a
	 * @phpstan-param list<string> $b
	 */
	private static function certain( array $a, array $b ): array {
		$count_a = array_count_values( $a );
		$count_b = array_count_values( $b );
		$intern  = [];
		$ta      = [];
		$tb      = [];

		foreach ( $a as $k => $fingerprint ) {
			$ta[] = $count_a[ $fingerprint ] === 1 && ( $count_b[ $fingerprint ] ?? 0 ) === 1 ? $intern[ $fingerprint ] ??= count( $intern ) : 'a' . $k;
		}

		foreach ( $b as $l => $fingerprint ) {
			$tb[] = $count_b[ $fingerprint ] === 1 && ( $count_a[ $fingerprint ] ?? 0 ) === 1 ? $intern[ $fingerprint ] ??= count( $intern ) : 'b' . $l;
		}

		return Lcs::pairs( $ta, $tb, self::CELL_BUDGET ) ?? [];
	}

	/**
	 * The cell pairs of two differently shaped tables with as many columns: rows pair where their fingerprints decide
	 * (each cell's column and fingerprint), a paired row's cells by column.
	 *
	 * @param array<int, Unit> $base        Base units.
	 * @param array<int, int>  $b_cells     Indexes of the base's cell units.
	 * @param array<int, Unit> $translation Translation units.
	 * @param array<int, int>  $t_cells     Indexes of the translation's cell units.
	 * @return list<array{int, int}>
	 * @phpstan-param list<Unit> $base
	 * @phpstan-param list<int> $b_cells
	 * @phpstan-param list<Unit> $translation
	 * @phpstan-param list<int> $t_cells
	 */
	private static function row_pairs( array $base, array $b_cells, array $translation, array $t_cells ): array {
		$b_rows = self::rows( $base, $b_cells );
		$t_rows = self::rows( $translation, $t_cells );
		$pairs  = [];

		foreach ( self::certain( self::row_fingerprints( $base, $b_rows ), self::row_fingerprints( $translation, $t_rows ) ) as [ $k, $l ] ) {
			$t_row = $t_rows[ $l ];

			foreach ( $b_rows[ $k ] as $column => $i ) {
				if ( isset( $t_row[ $column ] ) ) {
					$pairs[] = [ $i, $t_row[ $column ] ];
				}
			}
		}

		return $pairs;
	}

	/**
	 * The cell units of a table by row (in order), then by column.
	 *
	 * @param array<int, Unit> $units Units.
	 * @param array<int, int>  $cells Indexes of the cell units.
	 * @return list<array<int, int>> Unit index per column, per row.
	 * @phpstan-param list<Unit> $units
	 * @phpstan-param list<int> $cells
	 */
	private static function rows( array $units, array $cells ): array {
		$rows = [];

		foreach ( $cells as $i ) {
			$cell = $units[ $i ]->cell;

			if ( $cell !== null ) {
				$rows[ $cell[0] ][ $cell[1] ] = $i;
			}
		}

		ksort( $rows );

		$out = [];

		foreach ( $rows as $row ) {
			ksort( $row );
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The fingerprint of each row: its cells' columns and fingerprints.
	 *
	 * @param array<int, Unit>            $units Units.
	 * @param array<int, array<int, int>> $rows  Rows ({@see self::rows()}).
	 * @return list<string>
	 * @phpstan-param list<Unit> $units
	 * @phpstan-param list<array<int, int>> $rows
	 */
	private static function row_fingerprints( array $units, array $rows ): array {
		$out = [];

		foreach ( $rows as $row ) {
			$parts = [];

			foreach ( $row as $column => $i ) {
				$parts[] = $column . ':' . self::fingerprint( $units[ $i ] );
			}

			$out[] = implode( "\1", $parts );
		}

		return $out;
	}

	/**
	 * The slot of a unit: its role and attribute path without item indexes, so the k-th caption of a gallery's items
	 * pairs with the k-th and never with the gallery's own caption.
	 *
	 * @param Unit $unit Unit.
	 * @return string
	 */
	private static function slot( Unit $unit ): string {
		return $unit->role . "\0" . implode( '.', array_filter( $unit->path, 'is_string' ) );
	}

	/**
	 * The indexes of a unit list by kind: table cells, freeform units, the rest.
	 *
	 * @param array<int, Unit> $units Units.
	 * @return array{list<int>, list<int>, list<int>}
	 * @phpstan-param list<Unit> $units
	 */
	private static function unit_groups( array $units ): array {
		$cells   = [];
		$regions = [];
		$rest    = [];

		foreach ( $units as $i => $unit ) {
			if ( $unit->cell !== null ) {
				$cells[] = $i;
			} elseif ( $unit->kind === Unit::KIND_RANGE && $unit->path === [] ) {
				$regions[] = $i;
			} else {
				$rest[] = $i;
			}
		}

		return [ $cells, $regions, $rest ];
	}

	/**
	 * A cell's row and column as a key.
	 *
	 * @param Unit $unit Cell unit.
	 * @return string
	 */
	private static function coordinate( Unit $unit ): string {
		return $unit->cell === null ? '' : $unit->cell[0] . ':' . $unit->cell[1];
	}

	/**
	 * The shape of a table from its cell units: rows and columns.
	 *
	 * @param array<int, Unit> $units Units.
	 * @param array<int, int>  $cells Indexes of the cell units.
	 * @return array{int, int}
	 * @phpstan-param list<Unit> $units
	 * @phpstan-param list<int> $cells
	 */
	private static function grid( array $units, array $cells ): array {
		$rows    = 0;
		$columns = 0;

		foreach ( $cells as $i ) {
			$cell = $units[ $i ]->cell;

			if ( $cell !== null ) {
				$rows    = max( $rows, $cell[0] + 1 );
				$columns = max( $columns, $cell[1] + 1 );
			}
		}

		return [ $rows, $columns ];
	}

	/**
	 * Resolve the block map to anchors: translation blocks in document order whose canon hash equals an entry's take its
	 * base block, equal hashes in entry order; a malformed entry or one naming no block of that name is ignored.
	 *
	 * @param array<mixed> $block_map Block map.
	 * @return void
	 */
	private function resolve_anchors( array $block_map ): void {
		$by_hash = [];

		foreach ( $block_map as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 3 || count( $entry ) > 5 || ! isset( $entry[1], $entry[2] ) || ! is_string( $entry[1] ) || ! is_string( $entry[2] ) ) {
				continue;
			}

			$by_hash[ $entry[1] ][] = $entry[2];
		}

		if ( $by_hash === [] ) {
			return;
		}

		$base_nodes = [];

		foreach ( $this->base->tree->nodes as $node ) {
			self::index( $node, (string) $node->path->index, $base_nodes );
		}

		$hashes = [];

		foreach ( $this->translation->tree->nodes as $node ) {
			self::hash( $node, $this->translation->tree, $hashes );
		}

		$translation_blocks = [];

		foreach ( $this->translation->tree->nodes as $node ) {
			self::collect( $node, $translation_blocks );
		}

		$next = [];

		foreach ( $translation_blocks as $t ) {
			$hash16 = substr( $hashes[ spl_object_id( $t ) ], 0, 16 );
			$at     = $next[ $hash16 ] ?? 0;

			if ( ! isset( $by_hash[ $hash16 ][ $at ] ) ) {
				continue;
			}

			$b_path          = $by_hash[ $hash16 ][ $at ];
			$next[ $hash16 ] = $at + 1;
			$b               = $base_nodes[ $b_path ] ?? null;

			if ( $b !== null && $b->name === $t->name ) {
				$this->anchors[ spl_object_id( $t ) ] = $b;
			}
		}
	}

	/**
	 * Index a block subtree by path key.
	 *
	 * @param BlockNode                $node Node.
	 * @param string                   $key  Its path key.
	 * @param array<string, BlockNode> $out  Index.
	 * @return void
	 */
	private static function index( BlockNode $node, string $key, array &$out ): void {
		if ( $node->is_freeform() ) {
			return;
		}

		$out[ $key ] = $node;

		foreach ( $node->children as $child ) {
			self::index( $child, $key . '.' . $child->path->index, $out );
		}
	}

	/**
	 * The canon hash of a subtree, bottom-up, one hash per node.
	 *
	 * @param BlockNode          $node Node.
	 * @param BlockTree          $tree Tree.
	 * @param array<int, string> $out  Hash per node id.
	 * @return string
	 */
	private static function hash( BlockNode $node, BlockTree $tree, array &$out ): string {
		$children = [];

		foreach ( $node->children as $child ) {
			$children[] = self::hash( $child, $tree, $out );
		}

		$out[ spl_object_id( $node ) ] = Keys::canon_of( $node, $tree, $children );

		return $out[ spl_object_id( $node ) ];
	}

	/**
	 * The blocks of a subtree in document order.
	 *
	 * @param BlockNode             $node Node.
	 * @param array<int, BlockNode> $out  Blocks.
	 * @return void
	 */
	private static function collect( BlockNode $node, array &$out ): void {
		if ( $node->is_freeform() ) {
			return;
		}

		$out[] = $node;

		foreach ( $node->children as $child ) {
			self::collect( $child, $out );
		}
	}

	/**
	 * Align two child lists, then the children of each pair.
	 *
	 * @param array<int, BlockNode> $b_nodes Base nodes.
	 * @param array<int, BlockNode> $t_nodes Translation nodes.
	 * @return void
	 * @phpstan-param list<BlockNode> $b_nodes
	 * @phpstan-param list<BlockNode> $t_nodes
	 */
	private function align_lists( array $b_nodes, array $t_nodes ): void {
		$bs = self::elements( $b_nodes, $this->base );
		$ts = self::elements( $t_nodes, $this->translation );

		if ( $bs === [] && $ts === [] ) {
			return;
		}

		$b_index = [];

		foreach ( $bs as $i => $b ) {
			$b_index[ spl_object_id( $b ) ] = $i;
		}

		$pair_b   = [];
		$pair_t   = [];
		$anchor_b = [];

		foreach ( $ts as $j => $t ) {
			$b = $this->anchors[ spl_object_id( $t ) ] ?? null;
			$i = $b === null ? null : ( $b_index[ spl_object_id( $b ) ] ?? null );

			if ( $i !== null && ! isset( $pair_b[ $i ] ) ) {
				$pair_b[ $i ]   = $j;
				$pair_t[ $j ]   = $i;
				$anchor_b[ $i ] = $j;
			}
		}

		$full_b = [];
		$full_t = [];

		foreach ( $bs as $i => $b ) {
			$full_b[ $i ] = $this->signature_of( $b, $this->base )[0];
		}

		foreach ( $ts as $j => $t ) {
			$full_t[ $j ] = $this->signature_of( $t, $this->translation )[0];
		}

		// The LCS over signatures runs gap by gap between the anchors that kept their order.
		$flag_b = [];
		$flag_t = [];
		$chain  = Lcs::monotone( $anchor_b );

		$chain[] = [ count( $bs ), count( $ts ) ];

		$from_b = 0;
		$from_t = 0;

		foreach ( $chain as [ $to_b, $to_t ] ) {
			$gap_b = [];
			$gap_t = [];

			for ( $i = $from_b; $i < $to_b; $i++ ) {
				if ( ! isset( $pair_b[ $i ] ) ) {
					$gap_b[] = $i;
				}
			}

			for ( $j = $from_t; $j < $to_t; $j++ ) {
				if ( ! isset( $pair_t[ $j ] ) ) {
					$gap_t[] = $j;
				}
			}

			$from_b = $to_b + 1;
			$from_t = $to_t + 1;

			if ( $gap_b === [] || $gap_t === [] ) {
				continue;
			}

			$intern = [];
			$tok_b  = [];
			$tok_t  = [];

			foreach ( $gap_b as $i ) {
				$tok_b[] = $intern[ $full_b[ $i ] ] ??= count( $intern );
			}

			foreach ( $gap_t as $j ) {
				$tok_t[] = $intern[ $full_t[ $j ] ] ??= count( $intern );
			}

			$lcs = Lcs::pairs( $tok_b, $tok_t, self::CELL_BUDGET );

			if ( $lcs === null ) {
				// Too large for a table: the gap's blocks pair in order per signature below, every one a guess.
				$lcs = [];

				foreach ( $gap_b as $i ) {
					$flag_b[ $i ] = true;
				}

				foreach ( $gap_t as $j ) {
					$flag_t[ $j ] = true;
				}
			}

			foreach ( $lcs as [ $k, $l ] ) {
				$pair_b[ $gap_b[ $k ] ] = $gap_t[ $l ];
				$pair_t[ $gap_t[ $l ] ] = $gap_b[ $k ];
			}
		}

		// Leftovers with equal signatures crossed the LCS: the translator moved them. They pair in order per signature.
		[ $left_b, $left_t ] = self::leftovers( $bs, $ts, $pair_b, $pair_t );

		$moved_full   = [];
		$moved_coarse = [];

		if ( $left_b !== [] && $left_t !== [] ) {
			foreach ( self::in_order( $left_b, $left_t, $full_b, $full_t ) as [ $i, $j ] ) {
				$pair_b[ $i ]     = $j;
				$pair_t[ $j ]     = $i;
				$moved_full[ $i ] = true;
			}
		}

		// A signature with more blocks on one side than on the other, both sides having some, was paired by guess.
		$this->guesses( $bs, $ts, $anchor_b, $pair_t, $full_b, $full_t, $flag_b, $flag_t );

		// The rest pair by block name and attributes alone, with the same rule.
		[ $left_b, $left_t ] = self::leftovers( $bs, $ts, $pair_b, $pair_t );

		if ( $left_b !== [] && $left_t !== [] ) {
			$coarse_b = [];
			$coarse_t = [];
			$intern   = [];
			$tok_b    = [];
			$tok_t    = [];

			foreach ( $left_b as $i ) {
				$coarse_b[ $i ] = $this->signature_of( $bs[ $i ], $this->base )[1];
				$tok_b[]        = $intern[ $coarse_b[ $i ] ] ??= count( $intern );
			}

			foreach ( $left_t as $j ) {
				$coarse_t[ $j ] = $this->signature_of( $ts[ $j ], $this->translation )[1];
				$tok_t[]        = $intern[ $coarse_t[ $j ] ] ??= count( $intern );
			}

			$lcs = Lcs::pairs( $tok_b, $tok_t, self::CELL_BUDGET );

			if ( $lcs === null ) {
				$lcs = Lcs::in_order( $tok_b, $tok_t );

				foreach ( $left_b as $i ) {
					$flag_b[ $i ] = true;
				}

				foreach ( $left_t as $j ) {
					$flag_t[ $j ] = true;
				}
			}

			foreach ( $lcs as [ $k, $l ] ) {
				$pair_b[ $left_b[ $k ] ]       = $left_t[ $l ];
				$pair_t[ $left_t[ $l ] ]       = $left_b[ $k ];
				$moved_coarse[ $left_b[ $k ] ] = true;
			}

			$this->guesses( array_intersect_key( $bs, array_flip( $left_b ) ), array_intersect_key( $ts, array_flip( $left_t ) ), [], $pair_t, $coarse_b, $coarse_t, $flag_b, $flag_t );
		}

		// A pair of the two later passes that stands out of place is a guess when a block map resolved, else when it
		// crosses a pair of its signature.
		if ( $moved_full !== [] || $moved_coarse !== [] ) {
			$this->misplaced( $bs, $ts, $pair_b, $full_b, $full_t, $moved_full, $moved_coarse, $flag_b, $flag_t );
		}

		foreach ( array_keys( $flag_b ) as $i ) {
			$this->flag( $bs[ $i ], true );
		}

		foreach ( array_keys( $flag_t ) as $j ) {
			$this->flag( $ts[ $j ], false );
		}

		$this->emit( $bs, $ts, $pair_b, $pair_t );
	}

	/**
	 * Pair two sublists by signature in order: the k-th block of a signature on one side with the k-th on the other.
	 *
	 * @param array<int, int>    $left_b Base indexes.
	 * @param array<int, int>    $left_t Translation indexes.
	 * @param array<int, string> $sig_b  Signature per base index.
	 * @param array<int, string> $sig_t  Signature per translation index.
	 * @return list<array{int, int}> Element index pairs.
	 * @phpstan-param list<int> $left_b
	 * @phpstan-param list<int> $left_t
	 */
	private static function in_order( array $left_b, array $left_t, array $sig_b, array $sig_t ): array {
		$tok_b = [];
		$tok_t = [];

		foreach ( $left_b as $i ) {
			$tok_b[] = $sig_b[ $i ];
		}

		foreach ( $left_t as $j ) {
			$tok_t[] = $sig_t[ $j ];
		}

		$out = [];

		foreach ( Lcs::in_order( $tok_b, $tok_t ) as [ $k, $l ] ) {
			$out[] = [ $left_b[ $k ], $left_t[ $l ] ];
		}

		return $out;
	}

	/**
	 * Mark the blocks paired by guess: every non-anchor block of a signature whose counts differ, both sides having some.
	 *
	 * @param array<int, BlockNode> $bs       Base elements (a subset, keyed by element index).
	 * @param array<int, BlockNode> $ts       Translation elements (a subset, keyed by element index).
	 * @param array<int, int>       $anchor_b The anchored base indexes.
	 * @param array<int, int>       $pair_t   Base index per paired translation index.
	 * @param array<int, string>    $sig_b    Signature per base index.
	 * @param array<int, string>    $sig_t    Signature per translation index.
	 * @param array<int, true>      $flag_b   Flagged base indexes, added to.
	 * @param array<int, true>      $flag_t   Flagged translation indexes, added to.
	 * @return void
	 */
	private function guesses( array $bs, array $ts, array $anchor_b, array $pair_t, array $sig_b, array $sig_t, array &$flag_b, array &$flag_t ): void {
		$count_b = [];
		$count_t = [];

		foreach ( array_keys( $bs ) as $i ) {
			if ( ! isset( $anchor_b[ $i ] ) ) {
				$count_b[ $sig_b[ $i ] ] = ( $count_b[ $sig_b[ $i ] ] ?? 0 ) + 1;
			}
		}

		foreach ( array_keys( $ts ) as $j ) {
			if ( ! self::anchored_t( $j, $pair_t, $anchor_b ) ) {
				$count_t[ $sig_t[ $j ] ] = ( $count_t[ $sig_t[ $j ] ] ?? 0 ) + 1;
			}
		}

		$guessed = [];

		foreach ( $count_b as $signature => $nb ) {
			$nt = $count_t[ $signature ] ?? 0;

			if ( $nt > 0 && $nt !== $nb ) {
				$guessed[ $signature ] = true;
			}
		}

		if ( $guessed === [] ) {
			return;
		}

		foreach ( array_keys( $bs ) as $i ) {
			if ( ! isset( $anchor_b[ $i ] ) && isset( $guessed[ $sig_b[ $i ] ] ) ) {
				$flag_b[ $i ] = true;
			}
		}

		foreach ( array_keys( $ts ) as $j ) {
			if ( isset( $guessed[ $sig_t[ $j ] ] ) && ! self::anchored_t( $j, $pair_t, $anchor_b ) ) {
				$flag_t[ $j ] = true;
			}
		}
	}

	/**
	 * Flag the leftover- and coarse-pass pairs that are guesses: the translation block stands outside where the pairs
	 * around it put it, and a block map resolved to anchors or the pair crosses another of its signature. With anchors
	 * such a pair joins a block the translator wrote or edited, so a move cannot be told from a replacement.
	 *
	 * @param array<int, BlockNode> $bs           Base elements.
	 * @param array<int, BlockNode> $ts           Translation elements.
	 * @param array<int, int>       $pair_b       Translation index per paired base index.
	 * @param array<int, string>    $full_b       Full signature per base index.
	 * @param array<int, string>    $full_t       Full signature per translation index.
	 * @param array<int, true>      $moved_full   Base indexes paired by the leftover pass.
	 * @param array<int, true>      $moved_coarse Base indexes paired by the coarse pass.
	 * @param array<int, true>      $flag_b       Flagged base indexes, added to.
	 * @param array<int, true>      $flag_t       Flagged translation indexes, added to.
	 * @return void
	 * @phpstan-param list<BlockNode> $bs
	 * @phpstan-param list<BlockNode> $ts
	 */
	private function misplaced( array $bs, array $ts, array $pair_b, array $full_b, array $full_t, array $moved_full, array $moved_coarse, array &$flag_b, array &$flag_t ): void {
		ksort( $pair_b );

		$total = count( $bs );
		$lower = [];
		$last  = -1;

		for ( $i = 0; $i < $total; $i++ ) {
			$lower[ $i ] = $last;

			if ( isset( $pair_b[ $i ] ) && ! isset( $moved_full[ $i ] ) && ! isset( $moved_coarse[ $i ] ) ) {
				$last = $pair_b[ $i ];
			}
		}

		$out   = [];
		$upper = count( $ts );

		for ( $i = $total - 1; $i >= 0; $i-- ) {
			if ( ! isset( $pair_b[ $i ] ) ) {
				continue;
			}

			$j = $pair_b[ $i ];

			if ( ! isset( $moved_full[ $i ] ) && ! isset( $moved_coarse[ $i ] ) ) {
				$upper = $j;
			} elseif ( $j < min( $lower[ $i ], $upper ) || $j > max( $lower[ $i ], $upper ) ) {
				$out[ $i ] = true;
			}
		}

		if ( $out === [] ) {
			return;
		}

		$crossed = [];

		foreach ( $this->anchors === [] ? [ 0, 1 ] : [] as $pass ) {
			$moved = $pass === 0 ? $moved_full : $moved_coarse;

			if ( array_intersect_key( $out, $moved ) === [] ) {
				continue;
			}

			$sig_b = [];
			$sig_t = [];

			foreach ( $pair_b as $i => $j ) {
				$sig_b[ $i ] = $pass === 0 ? $full_b[ $i ] : $this->signature_of( $bs[ $i ], $this->base )[1];
				$sig_t[ $i ] = $pass === 0 ? $full_t[ $j ] : $this->signature_of( $ts[ $j ], $this->translation )[1];
			}

			$crossed[ $pass ] = self::crossed_within( $pair_b, $sig_b ) + self::crossed_within( $pair_b, $sig_t );
		}

		foreach ( array_keys( $out ) as $i ) {
			if ( $this->anchors !== [] || isset( $crossed[ isset( $moved_full[ $i ] ) ? 0 : 1 ][ $i ] ) ) {
				$flag_b[ $i ]            = true;
				$flag_t[ $pair_b[ $i ] ] = true;
			}
		}
	}

	/**
	 * The base indexes of the pairs that cross another pair with the same key.
	 *
	 * @param array<int, int>    $pair_b Translation index per paired base index, ascending.
	 * @param array<int, string> $key    Key per paired base index.
	 * @return array<int, true>
	 */
	private static function crossed_within( array $pair_b, array $key ): array {
		$max = [];
		$out = [];

		foreach ( $pair_b as $i => $j ) {
			if ( isset( $max[ $key[ $i ] ] ) && $max[ $key[ $i ] ] > $j ) {
				$out[ $i ] = true;
			}

			$max[ $key[ $i ] ] = max( $max[ $key[ $i ] ] ?? -1, $j );
		}

		$min = [];

		foreach ( array_reverse( $pair_b, true ) as $i => $j ) {
			if ( isset( $min[ $key[ $i ] ] ) && $min[ $key[ $i ] ] < $j ) {
				$out[ $i ] = true;
			}

			$min[ $key[ $i ] ] = min( $min[ $key[ $i ] ] ?? PHP_INT_MAX, $j );
		}

		return $out;
	}

	/**
	 * Whether a translation element is an anchor.
	 *
	 * @param int             $j        Translation index.
	 * @param array<int, int> $pair_t   Base index per paired translation index.
	 * @param array<int, int> $anchor_b Translation index per anchored base index.
	 * @return bool
	 */
	private static function anchored_t( int $j, array $pair_t, array $anchor_b ): bool {
		return isset( $pair_t[ $j ] ) && isset( $anchor_b[ $pair_t[ $j ] ] ) && $anchor_b[ $pair_t[ $j ] ] === $j;
	}

	/**
	 * The unpaired indexes of two lists.
	 *
	 * @param array<int, BlockNode> $bs     Base elements.
	 * @param array<int, BlockNode> $ts     Translation elements.
	 * @param array<int, int>       $pair_b Translation index per paired base index.
	 * @param array<int, int>       $pair_t Base index per paired translation index.
	 * @return array{list<int>, list<int>}
	 * @phpstan-param list<BlockNode> $bs
	 * @phpstan-param list<BlockNode> $ts
	 */
	private static function leftovers( array $bs, array $ts, array $pair_b, array $pair_t ): array {
		$left_b = [];
		$left_t = [];

		foreach ( $bs as $i => $b ) {
			if ( ! isset( $pair_b[ $i ] ) ) {
				$left_b[] = $i;
			}
		}

		foreach ( $ts as $j => $t ) {
			if ( ! isset( $pair_t[ $j ] ) ) {
				$left_t[] = $j;
			}
		}

		return [ $left_b, $left_t ];
	}

	/**
	 * Record the pairs of two lists in base order, pair their units, recurse, and list the rest as removed or
	 * translation-only.
	 *
	 * @param array<int, BlockNode> $bs     Base elements.
	 * @param array<int, BlockNode> $ts     Translation elements.
	 * @param array<int, int>       $pair_b Translation index per base index.
	 * @param array<int, int>       $pair_t Base index per translation index.
	 * @return void
	 * @phpstan-param list<BlockNode> $bs
	 * @phpstan-param list<BlockNode> $ts
	 */
	private function emit( array $bs, array $ts, array $pair_b, array $pair_t ): void {
		foreach ( $bs as $i => $b ) {
			if ( ! isset( $pair_b[ $i ] ) ) {
				$this->removed[] = $b;
				continue;
			}

			$t = $ts[ $pair_b[ $i ] ];

			$this->pairs[] = [ $b, $t ];

			if ( self::is_list( $b ) && self::is_list( $t ) && ( $b->children === [] ) !== ( $t->children === [] ) ) {
				$this->cross_shape( $b, $t );
				continue;
			}

			$b_units = $this->base->units_of( $b );
			$t_units = $this->translation->units_of( $t );

			$across = self::pair_across( $b_units, $t_units );

			foreach ( $across['pairs'] as [ $k, $l ] ) {
				$this->unit_pairs[] = [ $b_units[ $k ], $t_units[ $l ] ];
			}

			foreach ( $across['unsure'] as $k ) {
				$this->unsure[] = $b_units[ $k ];
			}

			if ( $across['ambiguous'] ) {
				$this->flag( $b, true );
				$this->flag( $t, false );
			}

			if ( $b->children !== [] || $t->children !== [] ) {
				$this->align_lists( $b->children, $t->children );
			}
		}

		foreach ( $ts as $j => $t ) {
			if ( ! isset( $pair_t[ $j ] ) ) {
				$this->translation_only[] = $t;
			}
		}
	}

	/**
	 * Whether a node is a list block.
	 *
	 * @param BlockNode $node Node.
	 * @return bool
	 */
	private static function is_list( BlockNode $node ): bool {
		return $node->name === self::LIST_BLOCK;
	}

	/**
	 * Pair two aligned lists of different shapes (one saved before WordPress 6.0, one with list-item children): legacy
	 * item units pair with list-item `content` units in document order, and the blocks below both lists are carried
	 * (neither removals nor translation-only).
	 *
	 * @param BlockNode $b Base list.
	 * @param BlockNode $t Translation list.
	 * @return void
	 */
	private function cross_shape( BlockNode $b, BlockNode $t ): void {
		$b_units = $this->list_units( $b, $this->base );
		$t_units = $this->list_units( $t, $this->translation );
		$across  = self::pair_items( $b_units, $t_units );

		foreach ( $across['pairs'] as [ $k, $l ] ) {
			$this->unit_pairs[] = [ $b_units[ $k ], $t_units[ $l ] ];
		}

		foreach ( $across['unsure'] as $k ) {
			$this->unsure[] = $b_units[ $k ];
		}

		if ( $across['ambiguous'] ) {
			$this->flag( $b, true );
			$this->flag( $t, false );
		}

		foreach ( [ $b, $t ] as $side ) {
			foreach ( self::below( $side ) as $node ) {
				$this->carried[] = $node;
			}
		}
	}

	/**
	 * The item units of a list in document order: a legacy list's own `values` units, or the `content` units of the
	 * list-item blocks below it, every level.
	 *
	 * @param BlockNode    $owner        List block.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return list<Unit>
	 */
	private function list_units( BlockNode $owner, Segmentation $segmentation ): array {
		$out = [];

		foreach ( array_merge( [ $owner ], self::below( $owner ) ) as $node ) {
			foreach ( $segmentation->units_of( $node ) as $unit ) {
				if ( ( $node === $owner && ( $unit->path[0] ?? null ) === 'values' ) || ( $node->name === self::LIST_ITEM_BLOCK && $unit->path === [ 'content' ] ) ) {
					$out[] = $unit;
				}
			}
		}

		return $out;
	}

	/**
	 * The blocks below a node in document order, down to the depth a per-level list split reaches
	 * ({@see Segmenter::LIST_DEPTH} lists, a list and a list item per level).
	 *
	 * @param BlockNode $node  Node.
	 * @param int       $depth The node's depth below the first call's node.
	 * @return list<BlockNode>
	 */
	private static function below( BlockNode $node, int $depth = 0 ): array {
		$out = [];

		if ( $depth >= 2 * Segmenter::LIST_DEPTH ) {
			return $out;
		}

		foreach ( $node->children as $child ) {
			$out[] = $child;

			foreach ( self::below( $child, $depth + 1 ) as $descendant ) {
				$out[] = $descendant;
			}
		}

		return $out;
	}

	/**
	 * Pair the item units of two differently shaped lists: in order on equal counts, else only where the fingerprints
	 * decide, ambiguous, the unpaired base units unsure ({@see self::pair_across()}).
	 *
	 * @param array<int, Unit> $base        Base item units.
	 * @param array<int, Unit> $translation Translation item units.
	 * @return array{pairs: list<array{int, int}>, ambiguous: bool, unsure: list<int>}
	 * @phpstan-param list<Unit> $base
	 * @phpstan-param list<Unit> $translation
	 */
	public static function pair_items( array $base, array $translation ): array {
		if ( count( $base ) === count( $translation ) ) {
			$pairs = [];

			foreach ( array_keys( $base ) as $k ) {
				$pairs[] = [ $k, $k ];
			}

			return [
				'pairs'     => $pairs,
				'ambiguous' => false,
				'unsure'    => [],
			];
		}

		if ( $base === [] || $translation === [] ) {
			return [
				'pairs'     => [],
				'ambiguous' => false,
				'unsure'    => [],
			];
		}

		$found = self::decided( $base, array_keys( $base ), $translation, array_keys( $translation ) );

		return [
			'pairs'     => $found,
			'ambiguous' => true,
			'unsure'    => self::unpaired( array_keys( $base ), $found ),
		];
	}

	/**
	 * Flag a block ambiguous.
	 *
	 * @param BlockNode $node    Block.
	 * @param bool      $is_base Whether it is a base block.
	 * @return void
	 */
	private function flag( BlockNode $node, bool $is_base ): void {
		$key   = Segmentation::path_key( $node );
		$flags = $is_base ? $this->base_flags[ $key ] ?? [] : $this->translation_flags[ $key ] ?? [];

		if ( in_array( self::FLAG_AMBIGUOUS, $flags, true ) ) {
			return;
		}

		$flags[] = self::FLAG_AMBIGUOUS;

		if ( $is_base ) {
			$this->base_flags[ $key ] = $flags;
		} else {
			$this->translation_flags[ $key ] = $flags;
		}
	}

	/**
	 * Pair title, excerpt and field units by role, the k-th of a role with the k-th.
	 *
	 * @param array<int, Unit> $base_fields        Base units.
	 * @param array<int, Unit> $translation_fields Translation units.
	 * @return void
	 * @phpstan-param list<Unit> $base_fields
	 * @phpstan-param list<Unit> $translation_fields
	 */
	private function pair_fields( array $base_fields, array $translation_fields ): void {
		$by_role = [];

		foreach ( $translation_fields as $unit ) {
			$by_role[ $unit->role ][] = $unit;
		}

		$seen = [];

		foreach ( $base_fields as $unit ) {
			$k                   = $seen[ $unit->role ] ?? 0;
			$seen[ $unit->role ] = $k + 1;

			if ( isset( $by_role[ $unit->role ][ $k ] ) ) {
				$this->unit_pairs[] = [ $unit, $by_role[ $unit->role ][ $k ] ];
			}
		}
	}

	/**
	 * The full and the coarse signature of a node. Full: name, structural
	 * attributes, unit roles and counts, inline-tag skeletons, digit runs
	 * and URLs. Coarse: name and structural attributes.
	 *
	 * @param BlockNode    $node         Node.
	 * @param Segmentation $segmentation Its segmentation.
	 * @return array{string, string}
	 */
	private function signature_of( BlockNode $node, Segmentation $segmentation ): array {
		$id = spl_object_id( $node );

		if ( isset( $this->signatures[ $id ] ) ) {
			return $this->signatures[ $id ];
		}

		$units = $segmentation->units_of( $node );

		if ( $node->is_freeform() ) {
			$coarse = self::FREEFORM_NAME . "\n";
		} else {
			$coarse = $node->name . "\n" . Keys::canon_json( $this->structural_attrs( $node, $units ) );
		}

		$roles     = [];
		$skeletons = [];
		$figures   = [];

		foreach ( $units as $unit ) {
			$roles[ $unit->role ] = ( $roles[ $unit->role ] ?? 0 ) + 1;

			if ( $unit->format === BlockAttributeSource::FORMAT_HTML ) {
				$skeletons[] = Keys::skeleton( $unit->text );
			}

			foreach ( Keys::figures( $unit->text, $unit->format ) as $figure ) {
				$figures[] = $figure;
			}
		}

		ksort( $roles, SORT_STRING );
		sort( $figures, SORT_STRING );

		$parts = [];

		foreach ( $roles as $role => $n ) {
			$parts[] = $role . '=' . $n;
		}

		$full = $coarse . "\n" . implode( ',', $parts ) . "\n" . implode( '|', $skeletons ) . "\n" . implode( ' ', $figures );

		$this->signatures[ $id ] = [ $full, $coarse ];

		return $this->signatures[ $id ];
	}

	/**
	 * The attributes of a block that are neither text nor localized values. The skip marker is left out too, so a block
	 * marked only in the translation still aligns with its base block.
	 *
	 * @param BlockNode        $node  Block.
	 * @param array<int, Unit> $units Its units.
	 * @return array<mixed>|null Null when the attribute JSON is invalid.
	 * @phpstan-param list<Unit> $units
	 */
	private function structural_attrs( BlockNode $node, array $units ): ?array {
		if ( $node->attrs === null ) {
			return null;
		}

		$text = $this->text_names( (string) $node->name );

		foreach ( $units as $unit ) {
			if ( $unit->kind === Unit::KIND_JSON && $unit->path !== [] ) {
				$text[ (string) $unit->path[0] ] = true;
			}
		}

		$attrs = $node->attrs;

		foreach ( array_keys( $attrs ) as $key ) {
			$name = (string) $key;

			if ( isset( $text[ $name ] ) || $name === self::SKIP_MARKER || preg_match( BlockAttributeSource::IGNORE_NAMES, $name ) === 1 || preg_match( self::LOCALIZED_NAMES, $name ) === 1 ) {
				unset( $attrs[ $key ] );
			}
		}

		return $attrs;
	}

	/**
	 * The text attribute names of a block type, as a set.
	 *
	 * @param string $name Block name.
	 * @return array<string, true>
	 */
	private function text_names( string $name ): array {
		return $this->text_names[ $name ] ??= array_fill_keys( array_merge( BlockText::chain( $name ), BlockText::comment_attributes( $name ) ), true );
	}
}
