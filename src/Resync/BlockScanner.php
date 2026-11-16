<?php
/**
 * Block document scanner with byte offsets.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiles a block document into byte ranges exactly as core's parser reads it (core returns no offsets): its delimiter
 * regex and stack rules. Supported only when the ranges tile the document and the projection equals core's own parse;
 * depth is capped at {@see self::MAX_DEPTH} while reading, which bounds every later engine walk.
 *
 * @phpstan-type Range array{int, int}
 * @phpstan-type Frame array{name: string, open: Range, attrs_json: Range|null, attrs: array<mixed>|null, prev: int, leading: int|null, chunks: list<Range>, children: list<BlockNode>, path: BlockPath}
 * @phpstan-type Token array{string, string, Range|null, array<mixed>|null, int, int, bool}
 * @phpstan-type Parsed array{blockName: string|null, attrs: array<mixed>|null, innerBlocks: list<mixed>, innerHTML: string, innerContent: list<string|null>}
 */
final class BlockScanner {

	/**
	 * The delimiter regex of WordPress 7.1.4's `WP_Block_Parser::next_token()`, byte for byte (a unit test compares it
	 * with core's). Older cores use `\s` (also VT); guard 2 refuses a document on which the two disagree.
	 */
	public const DELIMITER_PATTERN = '/<!--[ \t\f\r\n]+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)[ \t\f\r\n]+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}[ \t\f\r\n]+\/?-->).)*+)?}[ \t\f\r\n]+)?(?P<void>\/)?-->/s';

	/** Reasons a document is unsupported. */
	public const UNSUPPORTED_UNCLOSED        = 'unclosed';
	public const UNSUPPORTED_UNCLOSED_NESTED = 'unclosed_nested';
	public const UNSUPPORTED_TILING          = 'tiling';
	public const UNSUPPORTED_PARITY          = 'parity';
	public const UNSUPPORTED_DEPTH           = 'depth';

	/**
	 * The one depth limit of the engine's walks: blocks and comment JSON deeper than this are refused while scanning
	 * ({@see self::UNSUPPORTED_DEPTH}); {@see Composer::whitespace_only()} compares deeper blocks byte for byte.
	 */
	public const MAX_DEPTH = 16;

	/** Token types, as core names them. */
	private const TOKEN_NONE   = 'no-more-tokens';
	private const TOKEN_VOID   = 'void-block';
	private const TOKEN_OPENER = 'block-opener';
	private const TOKEN_CLOSER = 'block-closer';

	/** The whitespace the delimiter regex matches. */
	private const DELIMITER_WHITESPACE = " \t\n\r\f";

	/**
	 * Scan a document. Guard 2 runs through `WP_Block_Parser` when loaded (always inside WordPress) or the reference
	 * given; without either `supported` rests on guard 1 alone.
	 *
	 * @param string        $document  The document bytes.
	 * @param callable|null $reference The reference parse to verify against; null uses core's parser when it is loaded.
	 * @return BlockTree
	 * @phpstan-param (callable(string): array<mixed>)|null $reference
	 */
	public static function scan( string $document, ?callable $reference = null ): BlockTree {
		$tree = self::build( $document );

		if ( ! $tree->supported ) {
			return $tree;
		}

		if ( ! self::tiles( $tree ) ) {
			return new BlockTree( $document, false, self::UNSUPPORTED_TILING, [] );
		}

		if ( $reference === null && class_exists( '\WP_Block_Parser' ) ) {
			$reference = static fn( string $doc ): array => ( new \WP_Block_Parser() )->parse( $doc );
		}

		if ( $reference !== null && ! self::verify( $tree, $reference( $document ) ) ) {
			return new BlockTree( $document, false, self::UNSUPPORTED_PARITY, [] );
		}

		return $tree;
	}

	/**
	 * Guard 1: the node ranges tile the document exactly: top-level nodes contiguous end to end; inside a block opener,
	 * chunks alternating with children, and closer contiguous; a void block is its opener only.
	 *
	 * @param BlockTree $tree Tree.
	 * @return bool
	 */
	public static function tiles( BlockTree $tree ): bool {
		$cursor = 0;

		foreach ( $tree->nodes as $node ) {
			if ( $node->start !== $cursor || ! self::node_tiles( $node ) ) {
				return false;
			}

			$cursor = $node->end;
		}

		return $cursor === strlen( $tree->document );
	}

	/**
	 * Guard 2: the projection equals a reference parse by core's parser.
	 *
	 * @param BlockTree    $tree        Tree.
	 * @param array<mixed> $core_blocks `WP_Block_Parser::parse()` of the same document.
	 * @return bool
	 */
	public static function verify( BlockTree $tree, array $core_blocks ): bool {
		return self::project( $tree ) === $core_blocks;
	}

	/**
	 * The tree in parse_blocks() shape, with core's rules: a chunk before a child, and a top-level block's trailing chunk,
	 * only when not `empty()` ("" or "0"); a nested block's trailing chunk always.
	 *
	 * @param BlockTree $tree Tree.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<Parsed>
	 */
	public static function project( BlockTree $tree ): array {
		$out = [];

		foreach ( $tree->nodes as $node ) {
			$out[] = self::project_node( $tree, $node, false );
		}

		return $out;
	}

	/**
	 * One node in parse_blocks() shape.
	 *
	 * @param BlockTree $tree   Tree.
	 * @param BlockNode $node   Node.
	 * @param bool      $nested Whether the node is an inner block.
	 * @return array<string, mixed>
	 * @phpstan-return Parsed
	 */
	private static function project_node( BlockTree $tree, BlockNode $node, bool $nested ): array {
		if ( $node->is_freeform() ) {
			$html = $tree->slice( $node->range() );

			return [
				'blockName'    => null,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			];
		}

		$inner_blocks  = [];
		$inner_html    = '';
		$inner_content = [];
		$last          = count( $node->children );

		foreach ( $node->chunks as $i => $chunk ) {
			$html = $tree->slice( $chunk );

			if ( ( $i === $last && $nested ) || ( $html !== '' && $html !== '0' ) ) {
				$inner_html     .= $html;
				$inner_content[] = $html;
			}

			if ( $i < $last ) {
				$inner_blocks[]  = self::project_node( $tree, $node->children[ $i ], true );
				$inner_content[] = null;
			}
		}

		return [
			'blockName'    => $node->name,
			'attrs'        => $node->attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Whether one node's parts tile its range.
	 *
	 * @param BlockNode $node Node.
	 * @return bool
	 */
	private static function node_tiles( BlockNode $node ): bool {
		if ( $node->is_freeform() ) {
			return $node->start < $node->end
				&& $node->open === null
				&& $node->close === null
				&& $node->attrs_json === null
				&& $node->children === []
				&& $node->chunks === [ [ $node->start, $node->end ] ];
		}

		if ( $node->open === null || $node->open[0] !== $node->start || $node->open[1] < $node->open[0] ) {
			return false;
		}

		if ( $node->attrs_json !== null && ( $node->attrs_json[0] < $node->open[0] || $node->attrs_json[1] > $node->open[1] || $node->attrs_json[1] < $node->attrs_json[0] ) ) {
			return false;
		}

		$cursor = $node->open[1];

		if ( $node->is_void ) {
			return $node->chunks === [] && $node->children === [] && $node->close === null && $node->end === $cursor;
		}

		$count = count( $node->children );

		if ( $node->close === null || count( $node->chunks ) !== $count + 1 ) {
			return false;
		}

		foreach ( $node->chunks as $i => $chunk ) {
			if ( $chunk[0] !== $cursor || $chunk[1] < $chunk[0] ) {
				return false;
			}

			$cursor = $chunk[1];

			if ( $i < $count ) {
				$child = $node->children[ $i ];

				if ( $child->is_freeform() || $child->start !== $cursor || ! self::node_tiles( $child ) ) {
					return false;
				}

				$cursor = $child->end;
			}
		}

		if ( $node->close[0] !== $cursor || $node->close[1] < $node->close[0] ) {
			return false;
		}

		return $node->end === $node->close[1];
	}

	/**
	 * Core's `proceed()` loop over the tokens, recording offsets.
	 *
	 * @param string $document Document.
	 * @return BlockTree The tree before the guards; unsupported when blocks are left open at the end of the document, or when a block would stand deeper than {@see self::MAX_DEPTH} or its comment JSON nests deeper.
	 */
	private static function build( string $document ): BlockTree {
		$length = strlen( $document );
		$offset = 0;
		$output = [];
		$stack  = [];

		while ( true ) {
			[ $type, $name, $attrs_json, $attrs, $start, $token_length, $deep_json ] = self::next_token( $document, $offset );

			$depth = count( $stack );
			$end   = $start + $token_length;

			if ( ( $type === self::TOKEN_OPENER || $type === self::TOKEN_VOID ) && ( $depth >= self::MAX_DEPTH || $deep_json ) ) {
				return new BlockTree( $document, false, self::UNSUPPORTED_DEPTH, [] );
			}

			// HTML between the previous token and this one.
			$leading = ( $type !== self::TOKEN_NONE && $start > $offset ) ? $offset : null;

			switch ( $type ) {
				case self::TOKEN_NONE:
					if ( $depth > 0 ) {
						return new BlockTree( $document, false, $depth > 1 ? self::UNSUPPORTED_UNCLOSED_NESTED : self::UNSUPPORTED_UNCLOSED, [] );
					}

					if ( $offset < $length ) {
						$output[] = self::freeform( $offset, $length, count( $output ) );
					}

					return new BlockTree( $document, true, '', $output );

				case self::TOKEN_VOID:
					if ( $depth === 0 ) {
						if ( $leading !== null ) {
							$output[] = self::freeform( $leading, $start, count( $output ) );
						}

						$output[] = new BlockNode( $name, $start, $end, [ $start, $end ], null, $attrs_json, $attrs, true, [], [], new BlockPath( null, count( $output ) ) );
					} else {
						$node = new BlockNode( $name, $start, $end, [ $start, $end ], null, $attrs_json, $attrs, true, [], [], self::child_path( $stack[ $depth - 1 ] ) );
						self::attach( $stack[ $depth - 1 ], $node, $end );
					}

					$offset = $end;
					break;

				case self::TOKEN_OPENER:
					$path    = $depth === 0 ? new BlockPath( null, count( $output ) + ( $leading === null ? 0 : 1 ) ) : self::child_path( $stack[ $depth - 1 ] );
					$stack[] = self::open_frame( $name, [ $start, $end ], $attrs_json, $attrs, $leading, $path );
					$offset  = $end;
					break;

				case self::TOKEN_CLOSER:
					// A closer with no opener: the rest of the document, from the previous offset, is freeform.
					if ( $depth === 0 ) {
						if ( $offset < $length ) {
							$output[] = self::freeform( $offset, $length, count( $output ) );
						}

						return new BlockTree( $document, true, '', $output );
					}

					$frame = array_pop( $stack );
					$node  = self::close_frame( $frame, [ $start, $end ] );

					if ( $depth === 1 ) {
						if ( $frame['leading'] !== null ) {
							$output[] = self::freeform( $frame['leading'], $frame['open'][0], count( $output ) );
						}

						$output[] = $node;
					} else {
						self::attach( $stack[ $depth - 2 ], $node, $end );
					}

					$offset = $end;
					break;
			}
		}
	}

	/**
	 * A frame for a block just opened.
	 *
	 * @param string               $name       Block name.
	 * @param array{int, int}      $open       Opener range.
	 * @param array{int, int}|null $attrs_json Attribute JSON range.
	 * @param array<mixed>|null    $attrs      Decoded attributes.
	 * @param int|null             $leading    Start of the HTML before the opener at the top level, if any.
	 * @param BlockPath            $path       Path of the block.
	 * @return array
	 * @phpstan-return Frame
	 */
	private static function open_frame( string $name, array $open, ?array $attrs_json, ?array $attrs, ?int $leading, BlockPath $path ): array {
		return [
			'name'       => $name,
			'open'       => $open,
			'attrs_json' => $attrs_json,
			'attrs'      => $attrs,
			'prev'       => $open[1],
			'leading'    => $leading,
			'chunks'     => [],
			'children'   => [],
			'path'       => $path,
		];
	}

	/**
	 * The path of the next child of an open block.
	 *
	 * @param array $frame The open block's frame.
	 * @return BlockPath
	 * @phpstan-param Frame $frame
	 */
	private static function child_path( array $frame ): BlockPath {
		return new BlockPath( $frame['path'], count( $frame['children'] ) );
	}

	/**
	 * Core's `add_inner_block()`: the chunk before the child, then the child, appended to the frame by reference; a
	 * per-child copy would cost n² for n children.
	 *
	 * @param array     $frame The open block's frame.
	 * @param BlockNode $child Closed child.
	 * @param int       $next  Offset after the child.
	 * @return void
	 * @phpstan-param Frame $frame
	 */
	private static function attach( array &$frame, BlockNode $child, int $next ): void {
		$frame['chunks'][]   = [ $frame['prev'], $child->start ];
		$frame['children'][] = $child;
		$frame['prev']       = $next;
	}

	/**
	 * Core's `next_token()`: the next delimiter from an offset, and whether its comment JSON nests deeper than MAX_DEPTH.
	 *
	 * @param string $document Document.
	 * @param int    $offset   Offset to search from.
	 * @return array{string, string, array{int, int}|null, array<mixed>|null, int, int, bool} Type, name, attribute JSON range, decoded attributes, start, length, deeper JSON.
	 */
	private static function next_token( string $document, int $offset ): array {
		$matches = [];
		$found   = preg_match( self::DELIMITER_PATTERN, $document, $matches, PREG_OFFSET_CAPTURE, $offset );

		// No match, or a PCRE error (core treats both as the end of the tokens).
		if ( $found !== 1 ) {
			return [ self::TOKEN_NONE, '', null, null, 0, 0, false ];
		}

		$start  = (int) $matches[0][1];
		$length = strlen( $matches[0][0] );

		// The groups before `name` are always present (unmatched: offset -1); `attrs` and `void` may be absent.
		$is_closer = -1 !== $matches['closer'][1];
		$is_void   = isset( $matches['void'] ) && -1 !== $matches['void'][1];
		$namespace = -1 !== $matches['namespace'][1] ? $matches['namespace'][0] : 'core/';
		$name      = $namespace . $matches['name'][0];

		$attrs_json = null;
		$attrs      = [];
		$deep       = false;

		if ( isset( $matches['attrs'] ) && -1 !== $matches['attrs'][1] ) {
			// The group ends with the whitespace after the JSON; the range stops at the JSON's last brace.
			$json       = rtrim( $matches['attrs'][0], self::DELIMITER_WHITESPACE );
			$attrs_json = [ (int) $matches['attrs'][1], (int) $matches['attrs'][1] + strlen( $json ) ];
			$attrs      = BlockJson::decode_assoc( $matches['attrs'][0] );
			$deep       = BlockJson::deeper_than( $json, self::MAX_DEPTH );
		}

		if ( $is_void ) {
			return [ self::TOKEN_VOID, $name, $attrs_json, $attrs, $start, $length, $deep ];
		}

		if ( $is_closer ) {
			return [ self::TOKEN_CLOSER, $name, null, null, $start, $length, false ];
		}

		return [ self::TOKEN_OPENER, $name, $attrs_json, $attrs, $start, $length, $deep ];
	}

	/**
	 * A freeform node.
	 *
	 * @param int $start Start offset.
	 * @param int $end   End offset (exclusive).
	 * @param int $index Index among the top-level nodes.
	 * @return BlockNode
	 */
	private static function freeform( int $start, int $end, int $index ): BlockNode {
		return new BlockNode( null, $start, $end, null, null, null, [], false, [ [ $start, $end ] ], [], new BlockPath( null, $index ) );
	}

	/**
	 * The node of a frame being closed: its trailing chunk runs from the last child (or the opener) to the closer.
	 *
	 * @param array           $frame Frame.
	 * @param array{int, int} $close Closer range.
	 * @return BlockNode
	 * @phpstan-param Frame $frame
	 */
	private static function close_frame( array $frame, array $close ): BlockNode {
		$chunks   = $frame['chunks'];
		$chunks[] = [ $frame['prev'], $close[0] ];

		return new BlockNode( $frame['name'], $frame['open'][0], $close[1], $frame['open'], $close, $frame['attrs_json'], $frame['attrs'], false, $chunks, $frame['children'], $frame['path'] );
	}
}
