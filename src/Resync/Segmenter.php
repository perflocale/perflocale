<?php
/**
 * Segments a scanned document into units.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Translation\BlockSkipFilter;
use PerfLocale\Translation\PostTranslationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The units of a document per object kind, and what is not a unit. A leaf block's units are its located text
 * attributes and comment-JSON text; a container's sit in its first and last own chunk; other text is freeform regions.
 * Text the composer cannot place flags `structure`; protected blocks have no units; units are disjoint by construction.
 *
 * @phpstan-type Leaf array{path: list<int|string>, format: string, start: int, end: int, quote: string}
 * @phpstan-type Placement array{chunk: int, range: array{int, int}, leaf: Leaf, cell: array{int, int, int}|null}
 * @phpstan-type Found array{int, int, string, string, string, string}
 */
final class Segmenter {

	/** Flag: text the composer cannot treat as units sits in the block. */
	public const FLAG_STRUCTURE = 'structure';

	/** Protection reasons. */
	public const PROTECTED_SKIP   = 'skip';
	public const PROTECTED_OPAQUE = 'opaque';

	/** Kinds of an own chunk. */
	public const CHUNK_SHELL     = 'shell';
	public const CHUNK_SEPARATOR = 'separator';
	public const CHUNK_FREEFORM  = 'freeform';

	/**
	 * Option: `callable(int $ref): list<string>|null` giving the `metadata.name` values marked "Do not translate" (the
	 * block or an ancestor) in the referenced pattern or its translation; null when it nests too deep. Asked once per ref.
	 */
	public const OPTION_PATTERN_MARKED = 'pattern_marked_names';

	/** Blocks whose bytes are never segmented. */
	public const OPAQUE_BLOCKS = [ 'core/html', 'core/code', 'core/shortcode' ];

	/** References that are structure in a template. */
	public const STRUCTURE_BLOCKS = [ 'core/template-part', 'core/pattern' ];

	/** Post types whose title is an admin label, never a unit. */
	public const UNTITLED_TYPES = [ 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' ];

	private const PATTERN_BLOCK = 'core/block';

	private const TABLE_BLOCK = 'core/table';

	/** The list block: its text is in its list-item children, or in `values` when it has none (saved before WordPress 6.0). */
	private const LIST_BLOCK = 'core/list';

	/** The list levels a childless list's `values` is split to, one unit per item; an item at the last level holds the rest. */
	public const LIST_DEPTH = 16;

	/** The elements that open a list level. */
	private const LIST_ELEMENTS = [ 'ul', 'ol' ];

	private const COMMENT_PATTERN = '/<!--.*?-->/s';

	/** The table's query sources, in the editor's save order. */
	private const TABLE_SECTIONS = [ 'head', 'body', 'foot' ];

	/** Override names of a synced pattern instance, by format. */
	private const OVERRIDE_HTML = [ 'content', 'text', 'caption' ];
	private const OVERRIDE_TEXT = [ 'label', 'alt', 'title' ];

	/**
	 * Comment leaves mirrored in the HTML: the background image's alt, the text of the `<!--more ...-->` comment.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const MIRRORS = [
		'core/cover' => [ 'alt' => Unit::MIRROR_ATTRIBUTE ],
		'core/more'  => [ 'customText' => Unit::MIRROR_COMMENT ],
	];

	private const MORE_PATTERN = '/<!--more (.*?)-->/s';

	/**
	 * Block-level elements: wpautop's list, plus the raw-text elements it
	 * treats apart. Embedded content (iframe, video, picture, svg, …) is
	 * phrasing content and part of the unit that holds it.
	 */
	private const BLOCK_ELEMENTS = [ 'table', 'thead', 'tfoot', 'caption', 'col', 'colgroup', 'tbody', 'tr', 'td', 'th', 'div', 'dl', 'dd', 'dt', 'ul', 'ol', 'li', 'pre', 'form', 'map', 'area', 'blockquote', 'address', 'style', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'fieldset', 'legend', 'section', 'article', 'aside', 'hgroup', 'header', 'footer', 'nav', 'figure', 'figcaption', 'details', 'menu', 'summary', 'script', 'textarea' ];

	/** The elements whose content is a unit when they hold no block-level element. */
	private const UNIT_ELEMENTS = [ 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'dt', 'dd', 'td', 'th', 'blockquote', 'figcaption', 'pre', 'caption', 'summary' ];

	/** Elements whose contents are never units; the tokenizer reads them as raw text. */
	private const RAW_ELEMENTS = [ 'script', 'style', 'textarea' ];

	/** Whether a fragment can hold a raw element at all: the cheap check before the tokenizer runs. */
	private const RAW_HINT = '#<(?:script|style|textarea)(?=[\s/>])#i';

	private const IMAGE_ATTRIBUTES = [ 'alt', 'title' ];

	/** The tag a freeform unit carries: the image whose attribute it is, or a depth-0 text run. */
	private const IMAGE_TAG = 'img';
	private const RUN_TAG   = '#text';

	/** The paragraph break wpautop splits at. */
	private const BLANK_LINE = '/\n\s*\n/';

	private const TRIM = " \t\n\r\0\x0B";

	/**
	 * The tree being segmented.
	 *
	 * @var BlockTree
	 */
	private readonly BlockTree $tree;

	/**
	 * Options of this run.
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $options;

	/**
	 * Marked override names per pattern ref, as the resolver answered.
	 *
	 * @var array<int, list<string>>
	 */
	private array $marked = [];

	/**
	 * Chain and comment attribute names per block name.
	 *
	 * @var array<string, array{list<string>, list<string>}>
	 */
	private array $names = [];

	/**
	 * Units found so far, in document order.
	 *
	 * @var list<Unit>
	 */
	private array $units = [];

	/**
	 * Flags per node path key.
	 *
	 * @var array<string, list<string>>
	 */
	private array $flags = [];

	/**
	 * Protected nodes per path key.
	 *
	 * @var array<string, string>
	 */
	private array $protected = [];

	/**
	 * Chunk kinds per node path key.
	 *
	 * @var array<string, list<string>>
	 */
	private array $chunks = [];

	/**
	 * Why the document could not be segmented ({@see BlockAttributeSource::ERROR_TOKENIZER},
	 * {@see BlockAttributeSource::ERROR_SELECTOR_BUDGET}, or {@see BlockScanner::UNSUPPORTED_DEPTH}
	 * for a referenced pattern too deep to read); '' while it can.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * Constructor.
	 *
	 * @param BlockTree            $tree    Tree.
	 * @param array<string, mixed> $options Options.
	 */
	private function __construct( BlockTree $tree, array $options ) {
		$this->tree    = $tree;
		$this->options = $options;
	}

	/**
	 * Segment a scanned document. An unsupported tree, or one whose text could not be read (tokenizer failure, selector
	 * budget), gives no units: the segmentation carries the error instead of a partial unit list.
	 *
	 * @param BlockTree            $tree    Tree.
	 * @param array<string, mixed> $options {@see self::OPTION_PATTERN_MARKED}.
	 * @return Segmentation
	 */
	public static function segment( BlockTree $tree, array $options = [] ): Segmentation {
		if ( $tree->nodes === [] ) {
			return new Segmentation( $tree, [], [], [], [] );
		}

		$run = new self( $tree, $options );

		if ( $tree->supported ) {
			foreach ( $tree->nodes as $node ) {
				$run->walk( $node, false, (string) $node->path->index );
			}
		}

		if ( $run->error !== '' ) {
			return new Segmentation( $tree, [], [], [], [], $run->error );
		}

		return new Segmentation( $tree, $run->units, $run->flags, $run->protected, $run->chunks );
	}

	/**
	 * The title as one text unit; none for whitespace or a post type whose title is an admin label.
	 *
	 * @param string $title     Title.
	 * @param string $post_type Post type.
	 * @return Unit|null
	 */
	public static function segment_title( string $title, string $post_type ): ?Unit {
		if ( in_array( $post_type, self::UNTITLED_TYPES, true ) || ! Keys::has_text( $title, BlockAttributeSource::FORMAT_TEXT ) ) {
			return null;
		}

		return new Unit( Unit::KIND_FIELD, Keys::ROLE_TITLE, BlockAttributeSource::FORMAT_TEXT, $title, null, null, [], '', null, null );
	}

	/**
	 * The excerpt as one text unit; none for whitespace.
	 *
	 * @param string $excerpt Excerpt.
	 * @return Unit|null
	 */
	public static function segment_excerpt( string $excerpt ): ?Unit {
		if ( ! Keys::has_text( $excerpt, BlockAttributeSource::FORMAT_TEXT ) ) {
			return null;
		}

		return new Unit( Unit::KIND_FIELD, Keys::ROLE_EXCERPT, BlockAttributeSource::FORMAT_TEXT, $excerpt, null, null, [], '', null, null );
	}

	/**
	 * The custom fields as units: one per caller key (MetaTranslator's MT keys) with exactly one non-blank string row,
	 * in the format `perflocale/mt/meta_key_format` answers; seed-cleared, multi-row and sensitive keys give none.
	 *
	 * @param array<int|string, mixed> $rows     Meta rows per key, as `get_post_meta( $id, $key, false )` returns them.
	 * @param string                   $post_type    Source post type.
	 * @param int                      $source_id    Source post id.
	 * @param array<string, mixed>     $seed_cleared The translation's seed-cleared marker, keyed by key (`ContentSync::seed_cleared_keys()`).
	 * @return list<Unit>
	 */
	public static function segment_fields( array $rows, string $post_type, int $source_id = 0, array $seed_cleared = [] ): array {
		$patterns = PostTranslationManager::sensitive_meta_patterns();
		$out      = [];

		foreach ( $rows as $key => $values ) {
			$key = (string) $key;

			if ( $key === '' || isset( $seed_cleared[ $key ] ) || ! is_array( $values ) || count( $values ) !== 1 ) {
				continue;
			}

			$value = reset( $values );

			if ( ! is_string( $value ) || trim( $value ) === '' || PostTranslationManager::is_sensitive_meta_key( $key, $patterns ) ) {
				continue;
			}

			/** This filter is documented in src/MachineTranslation/MetaTranslator.php */
			$format = apply_filters( 'perflocale/mt/meta_key_format', 'html', $key, $post_type, $source_id );
			$format = $format === BlockAttributeSource::FORMAT_TEXT ? BlockAttributeSource::FORMAT_TEXT : BlockAttributeSource::FORMAT_HTML;

			if ( ! Keys::has_text( $value, $format ) ) {
				continue;
			}

			$out[] = new Unit( Unit::KIND_FIELD, Keys::role_meta( $key ), $format, $value, null, null, [], '', null, null );
		}

		return $out;
	}

	/**
	 * Segment one node and its subtree, appending units in document order: the comment leaves, then each own chunk's
	 * units and the child after it.
	 *
	 * @param BlockNode $node               Node.
	 * @param bool      $ancestor_protected Whether an ancestor is protected.
	 * @param string    $key                The node's path key ({@see Segmentation::path_key()}), passed down so a key costs one step per node.
	 * @return void
	 */
	private function walk( BlockNode $node, bool $ancestor_protected, string $key ): void {
		if ( $this->error !== '' ) {
			return;
		}

		$protected = $ancestor_protected;

		if ( $node->is_freeform() ) {
			[ $kind, $units ] = $this->region( $node, 0, true );

			$this->chunks[ $key ] = [ $kind ];
			$this->append( $units );

			return;
		}

		if ( ! $protected ) {
			$reason = $this->protection( $node );

			if ( $reason !== '' ) {
				$this->protected[ $key ] = $reason;
				$protected               = true;
			}
		}

		$leaves   = [];
		$by_chunk = [];

		if ( ! $protected ) {
			if ( $node->attrs_json !== null && $node->attrs === null ) {
				$this->flag( $key, self::FLAG_STRUCTURE );
			} elseif ( ! in_array( $node->name, self::STRUCTURE_BLOCKS, true ) ) {
				[ $leaves, $by_chunk ] = $this->own_units( $node, $key );
			}
		}

		if ( $this->error !== '' ) {
			return;
		}

		$this->append( $leaves );

		$kinds = [];
		$last  = count( $node->chunks ) - 1;

		for ( $i = 0; $i <= $last; $i++ ) {
			$this->append( $by_chunk[ $i ] ?? [] );

			if ( $i === 0 || $i === $last ) {
				$kinds[] = self::CHUNK_SHELL;
			} else {
				[ $kind, $units ] = $this->region( $node, $i, ! $protected );

				$kinds[] = $kind;
				$this->append( $units );
			}

			if ( $i < $last ) {
				$this->walk( $node->children[ $i ], $protected, $key . '.' . $node->children[ $i ]->path->index );
			}
		}

		$this->chunks[ $key ] = $kinds;
	}

	/**
	 * Append units.
	 *
	 * @param array<int, Unit> $units Units.
	 * @return void
	 * @phpstan-param list<Unit> $units
	 */
	private function append( array $units ): void {
		foreach ( $units as $unit ) {
			$this->units[] = $unit;
		}
	}

	/**
	 * Add a flag to a node.
	 *
	 * @param string $key  Node path key.
	 * @param string $flag Flag.
	 * @return void
	 */
	private function flag( string $key, string $flag ): void {
		if ( ! in_array( $flag, $this->flags[ $key ] ?? [], true ) ) {
			$this->flags[ $key ][] = $flag;
		}
	}

	/**
	 * Why a block is protected, or '' when it is not: the skip marker in its comment, or an opaque block type.
	 *
	 * @param BlockNode $node Node.
	 * @return string
	 */
	private function protection( BlockNode $node ): string {
		if ( is_array( $node->attrs ) && ! empty( $node->attrs[ BlockSkipFilter::SKIP_ATTRIBUTE ] ) ) {
			return self::PROTECTED_SKIP;
		}

		if ( in_array( $node->name, self::OPAQUE_BLOCKS, true ) ) {
			return self::PROTECTED_OPAQUE;
		}

		return '';
	}

	/**
	 * The chain and comment attribute names of a block type.
	 *
	 * @param string $name Block name.
	 * @return array{list<string>, list<string>}
	 */
	private function names( string $name ): array {
		return $this->names[ $name ] ??= [ BlockText::chain( $name ), BlockText::comment_attributes( $name ) ];
	}

	/**
	 * A block's own units: the comment leaves (chain names the HTML does
	 * not hold, the comment attributes, pattern overrides) and the located
	 * units per own chunk, each chunk's in document order.
	 *
	 * @param BlockNode $node Node.
	 * @param string    $key  Node path key.
	 * @return array{list<Unit>, array<int, list<Unit>>}
	 */
	private function own_units( BlockNode $node, string $key ): array {
		$name  = (string) $node->name;
		$attrs = is_array( $node->attrs ) ? $node->attrs : [];

		[ $chain, $comment ] = $this->names( $name );

		// A list without list-item children keeps its text in `values`: the source chain locates it.
		if ( $chain === [] && $node->children === [] && $name === self::LIST_BLOCK ) {
			$chain = array_values( BlockAttributeSource::chain( $name ) );
		}

		$html    = '';
		$offsets = [];

		foreach ( $node->chunks as $i => $chunk ) {
			$offsets[ $i ] = strlen( $html );
			$html         .= $this->tree->slice( $chunk );
		}

		$names      = $name === self::TABLE_BLOCK ? array_merge( $chain, self::TABLE_SECTIONS ) : $chain;
		$skipped    = [];
		$placements = [];
		$rows       = [];
		$has_unit   = [];

		if ( $names !== [] && $node->chunks !== [] ) {
			$located = BlockAttributeSource::locate(
				[
					'blockName'    => $name,
					'attrs'        => $attrs,
					'innerBlocks'  => [],
					'innerHTML'    => $html,
					'innerContent' => [ $html ],
				],
				$html,
				$names
			);
			if ( $located['error'] !== '' ) {
				$this->error = $located['error'];

				return [ [], [] ];
			}

			$skipped = $located['skipped'];
			$nested  = self::nested( $located['leaves'] );

			if ( $nested !== [] ) {
				$this->flag( $key, self::FLAG_STRUCTURE );
			}

			foreach ( $located['leaves'] as $n => $leaf ) {
				if ( isset( $nested[ $n ] ) ) {
					continue;
				}

				$cell = $name === self::TABLE_BLOCK ? self::cell_of( $leaf['path'] ) : null;

				if ( $cell !== null ) {
					$rows[ $cell[0] . ':' . $cell[1] ] = [ $cell[0], $cell[1] ];
				}

				$found     = false;
				$placement = $this->place( $node, $key, $leaf, $html, $offsets, $cell, $found );

				// The HTML holds the value (as read() sees it), so the comment's copy is not read, unit or not.
				if ( $found ) {
					$has_unit[ $leaf['path'][0] ] = true;
				}

				if ( $placement !== null ) {
					$placements[] = $placement;
				}
			}
		}

		// Rows are numbered across the table in the save order of its sections, empty cells counted.
		usort( $rows, static fn( array $a, array $b ): int => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0] );
		$row_index = [];

		foreach ( $rows as $n => [ $section, $row ] ) {
			$row_index[ $section . ':' . $row ] = $n;
		}

		$by_chunk = [];
		$legacy   = $name === self::LIST_BLOCK && $node->children === [];

		foreach ( $placements as $placement ) {
			$leaf   = $placement['leaf'];
			$cell   = $placement['cell'];
			$format = $leaf['format'];
			$last   = $leaf['path'][ count( $leaf['path'] ) - 1 ];
			$role   = $format === BlockAttributeSource::FORMAT_HTML ? Keys::ROLE_HTML : Keys::role_text( (string) $last );

			// A list saved before WordPress 6.0: one unit per item, as a list-item block's `content` would hold it.
			if ( $legacy && $leaf['path'] === [ 'values' ] && $format === BlockAttributeSource::FORMAT_HTML ) {
				foreach ( $this->list_items( $key, $placement['range'] ) as $k => $range ) {
					$by_chunk[ $placement['chunk'] ][] = new Unit( Unit::KIND_RANGE, Keys::ROLE_HTML, $format, $this->tree->slice( $range ), $node, $range, [ 'values', $k ], '', null, null );
				}

				continue;
			}

			$by_chunk[ $placement['chunk'] ][] = new Unit(
				Unit::KIND_RANGE,
				$role,
				$format,
				$this->tree->slice( $placement['range'] ),
				$node,
				$placement['range'],
				$leaf['path'],
				$leaf['quote'],
				null,
				$cell === null ? null : [ $row_index[ $cell[0] . ':' . $cell[1] ], $cell[2] ]
			);
		}

		foreach ( $by_chunk as &$units ) {
			usort( $units, static fn( Unit $a, Unit $b ): int => ( $a->range[0] ?? 0 ) <=> ( $b->range[0] ?? 0 ) );
		}

		unset( $units );

		$leaves = [];
		$schema = BlockAttributeSource::schema( $name );

		foreach ( $chain as $attr ) {
			if ( isset( $has_unit[ $attr ] ) || in_array( $attr, $skipped, true ) ) {
				continue;
			}

			$value  = $attrs[ $attr ] ?? null;
			$format = self::json_format( $schema[ $attr ] ?? null );

			if ( ! is_string( $value ) || ! Keys::has_text( $value, $format ) ) {
				continue;
			}

			$leaves[] = self::json_unit( $node, $attr, $value, $format, [ $attr ], $this->mirror( $node, $name, $attr, $html, $offsets ) );
		}

		foreach ( $comment as $attr ) {
			$value = $attrs[ $attr ] ?? null;

			if ( ! is_string( $value ) || ! Keys::has_text( $value, BlockAttributeSource::FORMAT_TEXT ) ) {
				continue;
			}

			$leaves[] = self::json_unit( $node, $attr, $value, BlockAttributeSource::FORMAT_TEXT, [ $attr ], null );
		}

		if ( $name === self::PATTERN_BLOCK ) {
			foreach ( $this->override_units( $node, $attrs ) as $unit ) {
				$leaves[] = $unit;
			}
		}

		return [ $leaves, $by_chunk ];
	}

	/**
	 * The items of a pre-6.0 list's `values`: per `li` in document order, the range of its own text before any nested
	 * list, split per level down to {@see self::LIST_DEPTH} (the last level holds the rest, flagged `structure`). Text
	 * after a nested list or beside a raw element gives no range and flags `structure`.
	 *
	 * @param string          $key   Node path key.
	 * @param array{int, int} $range The `values` range in the document.
	 * @return list<array{int, int}> Document ranges.
	 */
	private function list_items( string $key, array $range ): array {
		$html = $this->tree->slice( $range );
		$doc  = BlockAttributeSource::elements( $html );

		if ( $doc['error'] !== '' ) {
			$this->error = $doc['error'];

			return [];
		}

		$count  = count( $doc['tag'] );
		$levels = [];
		$lists  = [];

		// Parents come before their children, so one pass gives every element the lists around it (the values' own list is 1).
		for ( $i = 0; $i < $count; $i++ ) {
			$parent       = $doc['parent'][ $i ];
			$levels[ $i ] = $parent < 0 ? 1 : $levels[ $parent ] + ( in_array( $doc['tag'][ $parent ], self::LIST_ELEMENTS, true ) ? 1 : 0 );

			if ( $parent >= 0 && $doc['tag'][ $parent ] === 'li' && in_array( $doc['tag'][ $i ], self::LIST_ELEMENTS, true ) ) {
				$lists[ $parent ][] = $i;
			}
		}

		$out = [];

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $doc['tag'][ $i ] !== 'li' || $levels[ $i ] > self::LIST_DEPTH ) {
				continue;
			}

			$start = $doc['start'][ $i ];
			$end   = $doc['end'][ $i ] ?? $start;

			if ( isset( $lists[ $i ] ) ) {
				if ( $levels[ $i ] === self::LIST_DEPTH ) {
					$this->flag( $key, self::FLAG_STRUCTURE );
				} else {
					$rest   = '';
					$cursor = $doc['tag_start'][ $lists[ $i ][0] ];

					foreach ( $lists[ $i ] as $list ) {
						$rest  .= substr( $html, $cursor, $doc['tag_start'][ $list ] - $cursor );
						$cursor = $doc['close_end'][ $list ] ?? $end;
					}

					$rest .= substr( $html, $cursor, max( 0, $end - $cursor ) );

					if ( Keys::has_text( $rest ) ) {
						$this->flag( $key, self::FLAG_STRUCTURE );
						continue;
					}

					$end = $doc['tag_start'][ $lists[ $i ][0] ];
				}
			}

			if ( $start >= $end ) {
				continue;
			}

			$text    = substr( $html, $start, $end - $start );
			$outside = self::outside_raw( $text );

			if ( $outside === null ) {
				$this->error = BlockAttributeSource::ERROR_TOKENIZER;

				return [];
			}

			if ( ! Keys::has_text( $outside ) ) {
				continue;
			}

			if ( $outside !== $text ) {
				$this->flag( $key, self::FLAG_STRUCTURE );
				continue;
			}

			$out[] = [ $range[0] + $start, $range[0] + $end ];
		}

		return $out;
	}

	/**
	 * Where a located leaf lands: one text part in a first or last chunk is a unit; parts in two chunks or text beside a
	 * raw element flag `structure` and give none; a part in a middle chunk is left to the freeform rule.
	 *
	 * @param BlockNode            $node    Node.
	 * @param string               $key     Node path key.
	 * @param array<string, mixed> $leaf    Located leaf, in inner-HTML coordinates.
	 * @param string               $html    The inner HTML.
	 * @param array<int, int>      $offsets Inner-HTML offset of each chunk.
	 * @param array|null           $cell    Table cell (section order, row, column), if any.
	 * @param bool                 $found   Set when the range holds text anywhere, unit or not.
	 * @return array<string, mixed>|null
	 * @phpstan-param Leaf $leaf
	 * @phpstan-param array{int, int, int}|null $cell
	 * @phpstan-return Placement|null
	 */
	private function place( BlockNode $node, string $key, array $leaf, string $html, array $offsets, ?array $cell, bool &$found ): ?array {
		$start = $leaf['start'];
		$end   = $leaf['end'];
		$found = false;

		if ( $start >= $end ) {
			return null;
		}

		$parts = [];
		$raw   = false;

		foreach ( $node->chunks as $i => $chunk ) {
			$chunk_start = $offsets[ $i ];
			$chunk_end   = $chunk_start + ( $chunk[1] - $chunk[0] );
			$part_start  = max( $start, $chunk_start );
			$part_end    = min( $end, $chunk_end );

			if ( $part_start >= $part_end ) {
				continue;
			}

			$text    = substr( $html, $part_start, $part_end - $part_start );
			$outside = $leaf['format'] === BlockAttributeSource::FORMAT_HTML ? self::outside_raw( $text ) : $text;

			if ( $outside === null ) {
				$this->error = BlockAttributeSource::ERROR_TOKENIZER;

				return null;
			}

			if ( Keys::has_text( $outside, $leaf['format'] ) ) {
				$parts[] = [ $i, $part_start, $part_end ];
				$raw     = $raw || $outside !== $text;
			}
		}

		if ( $parts === [] ) {
			return null;
		}

		$found = true;

		if ( $raw || count( $parts ) > 1 ) {
			$this->flag( $key, self::FLAG_STRUCTURE );

			return null;
		}

		[ $i, $part_start, $part_end ] = $parts[0];

		$last = count( $node->chunks ) - 1;

		if ( $i > 0 && $i < $last ) {
			return null;
		}

		$base = $node->chunks[ $i ][0] - $offsets[ $i ];

		return [
			'chunk' => $i,
			'range' => [ $base + $part_start, $base + $part_end ],
			'leaf'  => $leaf,
			'cell'  => $cell,
		];
	}

	/**
	 * The leaves inside or overlapping an earlier-starting leaf's range (a table nested in a cell), as a set of indexes:
	 * the outer range stays the unit, the inner text is `structure`; blank cells inside are dropped so they count no row.
	 * Equal starts mean the same element; the stable sort keeps the first located.
	 *
	 * @param array<int, array<string, mixed>> $leaves Located leaves.
	 * @return array<int, true>
	 * @phpstan-param list<Leaf> $leaves
	 */
	private static function nested( array $leaves ): array {
		$order = array_keys( $leaves );

		usort( $order, static fn( int $a, int $b ): int => $leaves[ $a ]['start'] <=> $leaves[ $b ]['start'] );

		$out  = [];
		$last = -1;

		foreach ( $order as $n ) {
			if ( $leaves[ $n ]['start'] < $last ) {
				$out[ $n ] = true;
				continue;
			}

			$last = max( $last, $leaves[ $n ]['end'] );
		}

		return $out;
	}

	/**
	 * The cell a table leaf belongs to: section order, row, column.
	 *
	 * @param list<int|string> $path Leaf path.
	 * @return array{int, int, int}|null
	 */
	private static function cell_of( array $path ): ?array {
		if ( count( $path ) !== 5 || $path[2] !== 'cells' || $path[4] !== 'content' ) {
			return null;
		}

		$section = array_search( $path[0], self::TABLE_SECTIONS, true );

		if ( $section === false ) {
			return null;
		}

		return [ $section, (int) $path[1], (int) $path[3] ];
	}

	/**
	 * The format of a comment leaf by its schema: html for a rich-text type or an html, rich-text or children source,
	 * else text.
	 *
	 * @param mixed $def Attribute schema, if registered.
	 * @return string
	 */
	private static function json_format( mixed $def ): string {
		if ( is_array( $def ) && ( ( $def['type'] ?? '' ) === 'rich-text' || in_array( $def['source'] ?? '', [ 'html', 'rich-text', 'children' ], true ) ) ) {
			return BlockAttributeSource::FORMAT_HTML;
		}

		return BlockAttributeSource::FORMAT_TEXT;
	}

	/**
	 * A comment leaf unit.
	 *
	 * @param BlockNode                 $node   Node.
	 * @param string                    $attr   Attribute name (the last step of the path).
	 * @param string                    $value  The string value.
	 * @param string                    $format html or text.
	 * @param list<int|string>          $path   JSON path.
	 * @param array<string, mixed>|null $mirror Mirror, if any.
	 * @return Unit
	 * @phpstan-param array{range: array{int, int}, kind: string, quote: string}|null $mirror
	 */
	private static function json_unit( BlockNode $node, string $attr, string $value, string $format, array $path, ?array $mirror ): Unit {
		$role = $format === BlockAttributeSource::FORMAT_HTML ? Keys::ROLE_HTML : Keys::role_text( $attr );

		return new Unit( Unit::KIND_JSON, $role, $format, $value, $node, null, $path, '', $mirror, null );
	}

	/**
	 * The mirror of a comment leaf in the block's HTML: the first img's alt for a cover's `alt`, the `<!--more ...-->`
	 * text for a more block's `customText`; only in a first or last own chunk.
	 *
	 * @param BlockNode       $node    Node.
	 * @param string          $name    Block name.
	 * @param string          $attr    Attribute name.
	 * @param string          $html    Inner HTML.
	 * @param array<int, int> $offsets Inner-HTML offset of each chunk.
	 * @return array{range: array{int, int}, kind: string, quote: string}|null
	 */
	private function mirror( BlockNode $node, string $name, string $attr, string $html, array $offsets ): ?array {
		$kind = self::MIRRORS[ $name ][ $attr ] ?? '';

		if ( $kind === '' || $node->chunks === [] ) {
			return null;
		}

		if ( $kind === Unit::MIRROR_ATTRIBUTE ) {
			$doc = BlockAttributeSource::elements( $html );

			if ( $doc['error'] !== '' ) {
				$this->error = $doc['error'];

				return null;
			}

			foreach ( $doc['tag'] as $i => $tag ) {
				if ( $tag !== 'img' || ! isset( $doc['values'][ $i ]['alt'] ) ) {
					continue;
				}

				[ $start, $end, $quote ] = $doc['values'][ $i ]['alt'];

				// A valueless attribute has no value token to write into.
				if ( $start >= $end && $quote === '' ) {
					return null;
				}

				$range = $this->to_document( $node, $offsets, $start, $end );

				return $range === null ? null : [
					'range' => $range,
					'kind'  => Unit::MIRROR_ATTRIBUTE,
					'quote' => $quote,
				];
			}

			return null;
		}

		$m = [];

		if ( preg_match( self::MORE_PATTERN, $this->tree->slice( $node->chunks[0] ), $m, PREG_OFFSET_CAPTURE ) !== 1 ) {
			return null;
		}

		$start = $node->chunks[0][0] + (int) $m[1][1];

		return [
			'range' => [ $start, $start + strlen( $m[1][0] ) ],
			'kind'  => Unit::MIRROR_COMMENT,
			'quote' => '',
		];
	}

	/**
	 * An inner-HTML range mapped to the document, when it lies inside one first or last own chunk.
	 *
	 * @param BlockNode       $node    Node.
	 * @param array<int, int> $offsets Inner-HTML offset of each chunk.
	 * @param int             $start   Range start.
	 * @param int             $end     Range end.
	 * @return array{int, int}|null
	 */
	private function to_document( BlockNode $node, array $offsets, int $start, int $end ): ?array {
		$last = count( $node->chunks ) - 1;

		foreach ( $node->chunks as $i => $chunk ) {
			$chunk_start = $offsets[ $i ];
			$chunk_end   = $chunk_start + ( $chunk[1] - $chunk[0] );

			if ( $start >= $chunk_start && $end <= $chunk_end ) {
				if ( $i > 0 && $i < $last ) {
					return null;
				}

				return [ $chunk[0] + $start - $chunk_start, $chunk[0] + $end - $chunk_start ];
			}
		}

		return null;
	}

	/**
	 * The override units of a synced pattern instance: the string leaves of `content` ({name: {attribute: value}} or the
	 * older {name: {values: {...}}}), else of `overrides`; names marked in the referenced pattern give none.
	 *
	 * @param BlockNode    $node  Node.
	 * @param array<mixed> $attrs Attributes.
	 * @return list<Unit>
	 */
	private function override_units( BlockNode $node, array $attrs ): array {
		$base    = 'content';
		$content = $attrs['content'] ?? null;

		if ( ! is_array( $content ) ) {
			$base    = 'overrides';
			$content = $attrs['overrides'] ?? null;
		}

		if ( ! is_array( $content ) || $content === [] ) {
			return [];
		}

		$marked = $this->marked_names( is_numeric( $attrs['ref'] ?? null ) ? (int) $attrs['ref'] : 0 );
		$out    = [];

		foreach ( $content as $pattern_name => $data ) {
			if ( ! is_array( $data ) || in_array( (string) $pattern_name, $marked, true ) ) {
				continue;
			}

			$path = [ $base, $pattern_name ];

			if ( $base === 'content' && is_array( $data['values'] ?? null ) && array_filter( array_keys( $data['values'] ), 'is_string' ) !== [] ) {
				$data   = $data['values'];
				$path[] = 'values';
			}

			foreach ( $data as $attr => $value ) {
				if ( ! is_string( $attr ) || ! is_string( $value ) ) {
					continue;
				}

				if ( in_array( $attr, self::OVERRIDE_HTML, true ) ) {
					$format = BlockAttributeSource::FORMAT_HTML;
				} elseif ( in_array( $attr, self::OVERRIDE_TEXT, true ) ) {
					$format = BlockAttributeSource::FORMAT_TEXT;
				} else {
					continue;
				}

				if ( ! Keys::has_text( $value, $format ) ) {
					continue;
				}

				$out[] = self::json_unit( $node, $attr, $value, $format, array_merge( $path, [ $attr ] ), null );
			}
		}

		return $out;
	}

	/**
	 * The marked override names of a pattern, asked once per ref. A null answer (the pattern nests too deep) makes the
	 * document unsegmentable ({@see BlockScanner::UNSUPPORTED_DEPTH}), since a marked value would otherwise become a unit.
	 *
	 * @param int $ref Pattern post id.
	 * @return list<string>
	 */
	private function marked_names( int $ref ): array {
		if ( isset( $this->marked[ $ref ] ) ) {
			return $this->marked[ $ref ];
		}

		$resolver = $this->options[ self::OPTION_PATTERN_MARKED ] ?? null;
		$names    = [];

		if ( is_callable( $resolver ) ) {
			$answer = $resolver( $ref );

			if ( null === $answer ) {
				$this->error = BlockScanner::UNSUPPORTED_DEPTH;
			}

			foreach ( is_array( $answer ) ? $answer : [] as $name ) {
				if ( is_string( $name ) ) {
					$names[] = $name;
				}
			}
		}

		$this->marked[ $ref ] = $names;

		return $names;
	}

	/**
	 * One own chunk that is not a shell: a separator when it holds only whitespace and HTML comments (a tag is not
	 * whitespace), else a freeform region.
	 *
	 * @param BlockNode $node  Node.
	 * @param int       $index Chunk index.
	 * @param bool      $emit  Whether to produce units (false inside a protected block).
	 * @return array{string, list<Unit>} The chunk kind and its units.
	 */
	private function region( BlockNode $node, int $index, bool $emit ): array {
		$range = $node->chunks[ $index ];
		$html  = $this->tree->slice( $range );

		if ( ! Keys::has_text( preg_replace( self::COMMENT_PATTERN, '', $html ) ?? $html, BlockAttributeSource::FORMAT_TEXT ) ) {
			return [ self::CHUNK_SEPARATOR, [] ];
		}

		return [ self::CHUNK_FREEFORM, $emit ? $this->freeform_units( $node, $html, $range[0] ) : [] ];
	}

	/**
	 * The units of a freeform region: each innermost unit-kind block element's content, depth-0 text runs split at blank
	 * lines, and image alt and title outside those; nothing inside raw elements. A unit element holding a raw element
	 * beside its text gives no unit and flags `structure`.
	 *
	 * @param BlockNode $node Node the units belong to.
	 * @param string    $html The region's bytes.
	 * @param int       $base Document offset of the region.
	 * @return list<Unit>
	 */
	private function freeform_units( BlockNode $node, string $html, int $base ): array {
		$doc = BlockAttributeSource::elements( $html );

		if ( $doc['error'] !== '' ) {
			$this->error = $doc['error'];

			return [];
		}

		$count = count( $doc['tag'] );

		$is_block        = [];
		$under_block     = [];
		$has_block_child = array_fill( 0, max( 1, $count ), false );

		for ( $i = 0; $i < $count; $i++ ) {
			$tag    = $doc['tag'][ $i ];
			$parent = $doc['parent'][ $i ];

			$is_block[ $i ]    = in_array( $tag, self::BLOCK_ELEMENTS, true );
			$under_block[ $i ] = $parent >= 0 && ( $is_block[ $parent ] || $under_block[ $parent ] );

			if ( $is_block[ $i ] && ! in_array( $tag, self::RAW_ELEMENTS, true ) ) {
				for ( $p = $parent; $p >= 0 && ! $has_block_child[ $p ]; $p = $doc['parent'][ $p ] ) {
					$has_block_child[ $p ] = true;
				}
			}
		}

		$found   = [];
		$covered = [];

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $is_block[ $i ] && ! $under_block[ $i ] ) {
				$covered[] = [ $doc['tag_start'][ $i ], $doc['close_end'][ $i ] ?? $doc['end'][ $i ] ?? $doc['tag_end'][ $i ] ];
			}

			if ( $has_block_child[ $i ] || ! in_array( $doc['tag'][ $i ], self::UNIT_ELEMENTS, true ) ) {
				continue;
			}

			$start = $doc['start'][ $i ];
			$end   = $doc['end'][ $i ] ?? $start;

			if ( $start >= $end ) {
				continue;
			}

			$text    = substr( $html, $start, $end - $start );
			$outside = self::outside_raw( $text );

			if ( $outside === null ) {
				$this->error = BlockAttributeSource::ERROR_TOKENIZER;

				return [];
			}

			if ( ! Keys::has_text( $outside ) ) {
				continue;
			}

			if ( $outside !== $text ) {
				$this->flag( Segmentation::path_key( $node ), self::FLAG_STRUCTURE );
				continue;
			}

			$found[] = [ $start, $end, BlockAttributeSource::FORMAT_HTML, '', '', $doc['tag'][ $i ] ];
		}

		foreach ( $doc['comments'] as [ $comment_start, $comment_end, $parent ] ) {
			if ( $parent < 0 ) {
				$covered[] = [ $comment_start, $comment_end ];
			}
		}

		usort( $covered, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		$cursor = 0;
		$length = strlen( $html );

		foreach ( $covered as [ $covered_start, $covered_end ] ) {
			if ( $covered_start > $cursor ) {
				foreach ( self::runs( substr( $html, $cursor, $covered_start - $cursor ), $cursor ) as $run ) {
					$found[] = $run;
				}
			}

			$cursor = max( $cursor, $covered_end );
		}

		if ( $cursor < $length ) {
			foreach ( self::runs( substr( $html, $cursor ), $cursor ) as $run ) {
				$found[] = $run;
			}
		}

		usort( $found, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		$images = [];

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $doc['tag'][ $i ] !== 'img' || self::inside( $doc['tag_start'][ $i ], $doc['tag_end'][ $i ], $found ) ) {
				continue;
			}

			foreach ( self::IMAGE_ATTRIBUTES as $attr ) {
				if ( ! isset( $doc['values'][ $i ][ $attr ] ) ) {
					continue;
				}

				[ $start, $end, $quote ] = $doc['values'][ $i ][ $attr ];

				if ( $start < $end && Keys::has_text( substr( $html, $start, $end - $start ), BlockAttributeSource::FORMAT_TEXT ) ) {
					$images[] = [ $start, $end, BlockAttributeSource::FORMAT_TEXT, $attr, $quote, self::IMAGE_TAG ];
				}
			}
		}

		if ( $images !== [] ) {
			$found = array_merge( $found, $images );
			usort( $found, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		}

		if ( self::uncovered_text( $html, $found ) ) {
			$this->flag( Segmentation::path_key( $node ), self::FLAG_STRUCTURE );
		}

		$out = [];

		foreach ( $found as [ $start, $end, $format, $attr, $quote, $tag ] ) {
			$range = [ $base + $start, $base + $end ];
			$role  = $format === BlockAttributeSource::FORMAT_HTML ? Keys::ROLE_HTML : Keys::role_text( $attr );

			$out[] = new Unit( Unit::KIND_RANGE, $role, $format, $this->tree->slice( $range ), $node, $range, [], $quote, null, null, $tag );
		}

		return $out;
	}

	/**
	 * The bytes of a fragment outside its raw elements (with their tags), the fragment itself when it holds none; null
	 * when it could not be tokenized.
	 *
	 * @param string $html Fragment.
	 * @return string|null
	 */
	private static function outside_raw( string $html ): ?string {
		if ( ! preg_match( self::RAW_HINT, $html ) ) {
			return $html;
		}

		$doc = BlockAttributeSource::elements( $html );

		if ( $doc['error'] !== '' ) {
			return null;
		}

		$out    = '';
		$cursor = 0;

		foreach ( $doc['tag'] as $i => $tag ) {
			if ( ! in_array( $tag, self::RAW_ELEMENTS, true ) || $doc['tag_start'][ $i ] < $cursor ) {
				continue;
			}

			$out   .= substr( $html, $cursor, $doc['tag_start'][ $i ] - $cursor );
			$cursor = $doc['close_end'][ $i ] ?? $doc['tag_end'][ $i ];
		}

		return $out . substr( $html, $cursor );
	}

	/**
	 * Whether text lies outside every found range and raw element (a
	 * classic div's own text): it is copied untranslated, so it is flagged.
	 *
	 * @param string                   $html  The region's bytes.
	 * @param array<int, array<mixed>> $found Found ranges, sorted.
	 * @return bool
	 * @phpstan-param list<Found> $found
	 */
	private static function uncovered_text( string $html, array $found ): bool {
		$rest   = '';
		$cursor = 0;

		foreach ( $found as [ $start, $end ] ) {
			$rest  .= substr( $html, $cursor, max( 0, $start - $cursor ) );
			$cursor = max( $cursor, $end );
		}

		$rest = self::outside_raw( $rest . substr( $html, $cursor ) );

		return $rest === null || Keys::has_text( $rest );
	}

	/**
	 * The text runs of a depth-0 gap: split at blank lines, trimmed, those that hold text.
	 *
	 * @param string $text   The gap's bytes.
	 * @param int    $offset Offset of the gap in the region.
	 * @return list<array{int, int, string, string, string, string}>
	 */
	private static function runs( string $text, int $offset ): array {
		$pieces = preg_split( self::BLANK_LINE, $text, -1, PREG_SPLIT_OFFSET_CAPTURE );
		$out    = [];

		foreach ( is_array( $pieces ) ? $pieces : [ [ $text, 0 ] ] as [ $piece, $at ] ) {
			$trimmed = trim( $piece, self::TRIM );

			if ( $trimmed === '' || ! Keys::has_text( $trimmed ) ) {
				continue;
			}

			$start = $offset + $at + strlen( $piece ) - strlen( ltrim( $piece, self::TRIM ) );
			$out[] = [ $start, $start + strlen( $trimmed ), BlockAttributeSource::FORMAT_HTML, '', '', self::RUN_TAG ];
		}

		return $out;
	}

	/**
	 * Whether a range lies inside one of the found ranges (sorted, disjoint).
	 *
	 * @param int                      $start Range start.
	 * @param int                      $end   Range end.
	 * @param array<int, array<mixed>> $found Found ranges.
	 * @return bool
	 * @phpstan-param list<Found> $found
	 */
	private static function inside( int $start, int $end, array $found ): bool {
		$low  = 0;
		$high = count( $found ) - 1;

		while ( $low <= $high ) {
			$mid = intdiv( $low + $high, 2 );

			if ( $found[ $mid ][0] <= $start ) {
				$low = $mid + 1;
			} else {
				$high = $mid - 1;
			}
		}

		return $high >= 0 && $end <= $found[ $high ][1];
	}
}
