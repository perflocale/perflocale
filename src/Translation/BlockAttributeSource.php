<?php
/**
 * Reads a parsed block's text attributes the way the block editor does.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the value of each text attribute of a parsed block from the place
 * the block type stores it.
 *
 * Core paragraph, heading, list-item, image, button and most other text blocks
 * keep their text in the saved HTML, not in the block comment: the block type's
 * attribute schema names a `source` (`rich-text`, `html`, `attribute`) and a CSS
 * `selector`. The editor reads each attribute from that element; this class
 * does the same on the server, so a caller gets the paragraph's inner HTML, the
 * image's `alt` and its `<figcaption>` as separate values instead of the whole
 * `<figure>…</figure>` markup.
 *
 * The attribute chain and the text-attribute rules mirror `textAttrChain()` and
 * `findTextAttrsInSchema()` in assets/js/block-toolbar.js, so the server and the
 * editor pick the same attributes for the same block.
 *
 * Used by the block-editor REST route only (never on a front-end request). No
 * dependency on ext-dom or on WP_HTML_Processor internals: one regex pass over
 * the block's own `innerHTML` (inner blocks are already cut out of it by the
 * block parser).
 */
final class BlockAttributeSource {

	/**
	 * Value is HTML (rich text): sent to MT in HTML mode.
	 */
	public const FORMAT_HTML = 'html';

	/**
	 * Value is plain text (an HTML attribute such as `alt`): sent in text mode.
	 */
	public const FORMAT_TEXT = 'text';

	/**
	 * Attributes that are never text, whatever their schema says. Same list as
	 * `IGNORE_ATTR_NAMES` in block-toolbar.js.
	 */
	private const IGNORE_NAMES = '/^(class|className|anchor|id|url|href|src|target|rel|tagName|level|align|orientation|direction|mode|style|color|backgroundColor|fontSize|fontFamily|borderRadius|borderColor|width|height|gap)$/i';

	/**
	 * Attribute names that are user-visible text although their schema source
	 * is an HTML attribute. Same list as `TRANSLATABLE_ATTR_NAMES` in
	 * block-toolbar.js; every other `source: attribute` value (URLs, targets,
	 * ids) is left alone.
	 */
	private const TEXT_ATTRIBUTE_NAMES = '/^(alt|title|placeholder|ariaLabel|aria-label|description|summary|tooltip)$/i';

	/**
	 * Per-block attribute chains. Same map as `per_block` in `textAttrChain()`.
	 *
	 * A quote keeps its text in inner paragraph blocks; its own text attribute
	 * is `citation`. Its `value` attribute is never read or written: the
	 * editor turns a non-empty `value` into paragraphs and replaces the
	 * quote's inner blocks with them.
	 *
	 * @var array<string, string[]>
	 */
	private const PER_BLOCK = [
		'core/quote'        => [ 'citation' ],
		'core/pullquote'    => [ 'value', 'citation' ],
		'core/button'       => [ 'text' ],
		'core/details'      => [ 'summary' ],
		'core/image'        => [ 'caption', 'alt', 'title' ],
		'core/embed'        => [ 'caption' ],
		'core/audio'        => [ 'caption' ],
		'core/video'        => [ 'caption' ],
		'core/post-excerpt' => [ 'excerpt' ],
	];

	/**
	 * Chain for a block with no per-block entry and no discoverable schema.
	 */
	private const FALLBACK_CHAIN = [ 'content', 'text', 'value' ];

	/**
	 * Maximum number of attribute names read from one block.
	 */
	private const MAX_NAMES = 32;

	/**
	 * Elements that never have content or a closing tag.
	 */
	private const VOID_ELEMENTS = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' ];

	/**
	 * Attribute names that may carry text for a block type, in priority order.
	 *
	 * Per-block map first, then the text attributes discovered from the
	 * registered schema, then `content` / `text` / `value`.
	 *
	 * @param string $block_name Block name, e.g. `core/image`.
	 * @return string[]
	 */
	public static function chain( string $block_name ): array {
		if ( isset( self::PER_BLOCK[ $block_name ] ) ) {
			return self::PER_BLOCK[ $block_name ];
		}

		$found = [];

		foreach ( self::schema( $block_name ) as $name => $def ) {
			if ( self::is_text_attribute( (string) $name, $def ) ) {
				$found[] = (string) $name;
			}
		}

		return $found !== [] ? $found : self::FALLBACK_CHAIN;
	}

	/**
	 * Read the text attributes of a parsed block.
	 *
	 * Sourced attributes (`rich-text`, `html`, `children`) are read from the
	 * inner HTML of the first element matching the schema selector; `attribute`
	 * sources from that element's decoded HTML attribute; comment attributes
	 * from the block comment. A selector this reader cannot evaluate is
	 * reported in `skipped`, never replaced by the block's whole HTML.
	 *
	 * @param array<mixed> $block Parsed block (parse_blocks() shape).
	 * @param array<mixed> $names Attribute names to read; empty = the block type's chain.
	 *                            Anything that is not a valid attribute name is ignored.
	 * @return array{values: array<string, string>, formats: array<string, string>, skipped: string[]}
	 */
	public static function read( array $block, array $names = [] ): array {
		$block_name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$html       = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
		$schema     = self::schema( $block_name );

		$wanted = [];

		foreach ( ( $names !== [] ? $names : self::chain( $block_name ) ) as $name ) {
			if ( is_string( $name ) && self::is_valid_name( $name ) && ! in_array( $name, $wanted, true ) ) {
				$wanted[] = $name;
			}

			if ( count( $wanted ) >= self::MAX_NAMES ) {
				break;
			}
		}

		$out = [
			'values'  => [],
			'formats' => [],
			'skipped' => [],
		];

		$doc = null;

		foreach ( $wanted as $name ) {
			if ( preg_match( self::IGNORE_NAMES, $name ) ) {
				continue;
			}

			$def    = is_array( $schema[ $name ] ?? null ) ? $schema[ $name ] : [];
			$source = is_string( $def['source'] ?? null ) ? $def['source'] : '';
			$value  = null;
			$format = self::FORMAT_HTML;

			if ( $source !== '' ) {
				if ( in_array( $source, [ 'rich-text', 'html', 'children' ], true ) ) {
					$selector = is_string( $def['selector'] ?? null ) ? $def['selector'] : '';

					if ( $selector === '' ) {
						$value = $html;
					} else {
						$doc ??= self::tokenize( $html );
						$match = self::query( $doc, $selector );

						if ( $match === false ) {
							$out['skipped'][] = $name;
							continue;
						}

						if ( $match !== null ) {
							$start = $doc['start'][ $match ];
							$value = substr( $html, $start, ( $doc['end'][ $match ] ?? $start ) - $start );
						}
					}
				} elseif ( $source === 'attribute' && preg_match( self::TEXT_ATTRIBUTE_NAMES, $name ) ) {
					$format    = self::FORMAT_TEXT;
					$selector  = is_string( $def['selector'] ?? null ) ? $def['selector'] : '';
					$html_attr = strtolower( is_string( $def['attribute'] ?? null ) ? $def['attribute'] : '' );

					if ( $selector === '' || $html_attr === '' ) {
						$out['skipped'][] = $name;
						continue;
					}

					$doc ??= self::tokenize( $html );
					$match = self::query( $doc, $selector );

					if ( $match === false ) {
						$out['skipped'][] = $name;
						continue;
					}

					if ( $match !== null && isset( $doc['attrs'][ $match ][ $html_attr ] ) ) {
						$value = $doc['attrs'][ $match ][ $html_attr ];
					}
				} else {
					// URL, boolean, query, raw and meta sources are not text.
					$out['skipped'][] = $name;
					continue;
				}
			}

			// Comment attribute, or a sourced attribute also present in the
			// block comment (older saved shapes) when the HTML held nothing.
			if ( ( $value === null || trim( $value ) === '' ) && isset( $attrs[ $name ] ) && is_string( $attrs[ $name ] ) ) {
				$value = $attrs[ $name ];
			}

			if ( $value === null || trim( $value ) === '' ) {
				continue;
			}

			$out['values'][ $name ]  = trim( $value );
			$out['formats'][ $name ] = $format;
		}

		return $out;
	}

	/**
	 * Whether a block or any block above it on a path carries the "Do not
	 * translate" marker.
	 *
	 * @param array<mixed> $blocks Parsed blocks (parse_blocks() shape).
	 * @param array<mixed> $path   Index path over real blocks (placeholders skipped).
	 * @return bool
	 */
	public static function path_is_skip_marked( array $blocks, array $path ): bool {
		$current = $blocks;

		foreach ( $path as $idx ) {
			$real = [];

			foreach ( $current as $b ) {
				if ( is_array( $b ) && is_string( $b['blockName'] ?? null ) && $b['blockName'] !== '' ) {
					$real[] = $b;
				}
			}

			$idx = is_numeric( $idx ) ? (int) $idx : -1;

			if ( $idx < 0 || ! isset( $real[ $idx ] ) ) {
				return false;
			}

			$node  = $real[ $idx ];
			$attrs = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [];

			if ( ! empty( $attrs[ BlockSkipFilter::SKIP_ATTRIBUTE ] ) ) {
				return true;
			}

			$current = is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : [];
		}

		return false;
	}

	/**
	 * Registered attribute schema of a block type, or [] when unregistered.
	 *
	 * @param string $block_name Block name.
	 * @return array<mixed>
	 */
	private static function schema( string $block_name ): array {
		if ( $block_name === '' || ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return [];
		}

		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );

		return ( $type instanceof \WP_Block_Type && is_array( $type->attributes ) ) ? $type->attributes : [];
	}

	/**
	 * Same inclusion rules as `findTextAttrsInSchema()` in block-toolbar.js.
	 *
	 * @param string $name Attribute name.
	 * @param mixed  $def  Attribute schema.
	 * @return bool
	 */
	private static function is_text_attribute( string $name, $def ): bool {
		if ( ! is_array( $def ) || preg_match( self::IGNORE_NAMES, $name ) ) {
			return false;
		}

		$type   = $def['type'] ?? '';
		$source = $def['source'] ?? '';

		if ( $type === 'rich-text' ) {
			return true;
		}

		if ( $type !== 'string' ) {
			return false;
		}

		if ( in_array( $source, [ 'html', 'rich-text', 'children' ], true ) ) {
			return true;
		}

		return $source === 'attribute' && (bool) preg_match( self::TEXT_ATTRIBUTE_NAMES, $name );
	}

	/**
	 * Attribute names accepted from a caller.
	 *
	 * @param string $name Candidate.
	 * @return bool
	 */
	private static function is_valid_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $name );
	}

	/**
	 * One pass over an HTML fragment: every element with its tag, decoded
	 * attributes, parent and the byte range of its content, as parallel lists
	 * indexed by element (document order).
	 *
	 * @param string $html Fragment.
	 * @return array{tag: list<string>, attrs: list<array<string, string>>, parent: list<int>, start: list<int>, end: array<int, int>}
	 */
	private static function tokenize( string $html ): array {
		$tag_names = [];
		$attrs     = [];
		$parents   = [];
		$starts    = [];
		$ends      = [];
		$stack     = [];
		$length    = strlen( $html );

		preg_match_all( '#<!--.*?-->|<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#s', $html, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		foreach ( $found as $tag ) {
			$name = strtolower( $tag[2][0] ?? '' );

			if ( $name === '' ) {
				continue; // Comment.
			}

			$at    = $tag[0][1];
			$after = $at + strlen( $tag[0][0] );

			if ( ( $tag[1][0] ?? '' ) === '/' ) {
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					if ( $tag_names[ $stack[ $i ] ] === $name ) {
						foreach ( array_splice( $stack, $i ) as $open ) {
							$ends[ $open ] = $at;
						}
						break;
					}
				}
				continue;
			}

			$raw_attrs = $tag[3][0] ?? '';
			$self      = str_ends_with( rtrim( $raw_attrs ), '/' ) || in_array( $name, self::VOID_ELEMENTS, true );
			$index     = count( $tag_names );

			$tag_names[]    = $name;
			$attrs[]        = self::parse_attributes( $raw_attrs );
			$parents[]      = $stack !== [] ? $stack[ count( $stack ) - 1 ] : -1;
			$starts[]       = $after;
			$ends[ $index ] = $self ? $after : $length;

			if ( ! $self ) {
				$stack[] = $index;
			}
		}

		return [
			'tag'    => $tag_names,
			'attrs'  => $attrs,
			'parent' => $parents,
			'start'  => $starts,
			'end'    => $ends,
		];
	}

	/**
	 * Decoded attributes of one start tag.
	 *
	 * @param string $raw Everything between the tag name and `>`.
	 * @return array<string, string>
	 */
	private static function parse_attributes( string $raw ): array {
		$out = [];

		preg_match_all( '#([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#', $raw, $found, PREG_SET_ORDER );

		foreach ( $found as $a ) {
			$name = strtolower( $a[1] );

			if ( isset( $out[ $name ] ) ) {
				continue;
			}

			$value        = ( $a[2] ?? '' ) . ( $a[3] ?? '' ) . ( $a[4] ?? '' );
			$out[ $name ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $out;
	}

	/**
	 * First element, in document order, matching a selector.
	 *
	 * Supports comma alternatives; compounds made of an optional tag, `.class`,
	 * `[attr]` and `:not([attr])`; descendant (space) and child (`>`)
	 * combinators. That covers every text selector in core's block.json files.
	 *
	 * @param array{tag: list<string>, attrs: list<array<string, string>>, parent: list<int>, start: list<int>, end: array<int, int>} $doc      Tokenized fragment.
	 * @param string                                                                                                                  $selector CSS selector.
	 * @return int|false|null Element index, null when none matches, false when the selector is unsupported.
	 */
	private static function query( array $doc, string $selector ) {
		$alternatives = [];

		foreach ( explode( ',', $selector ) as $alt ) {
			$parsed = self::parse_selector( $alt );

			if ( $parsed === null ) {
				return false;
			}

			$alternatives[] = $parsed;
		}

		foreach ( array_keys( $doc['tag'] ) as $i ) {
			foreach ( $alternatives as $steps ) {
				if ( self::matches_steps( $doc, $i, $steps, count( $steps ) - 1 ) ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Parse one comma-free selector into compound steps.
	 *
	 * @param string $selector Selector without commas.
	 * @return list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}>|null Null when unsupported.
	 */
	private static function parse_selector( string $selector ): ?array {
		$selector = trim( (string) preg_replace( '/\s*>\s*/', ' > ', trim( $selector ) ) );

		if ( $selector === '' ) {
			return null;
		}

		$steps      = [];
		$combinator = ' ';

		foreach ( (array) preg_split( '/\s+/', $selector ) as $token ) {
			$token = (string) $token;

			if ( $token === '>' ) {
				if ( $steps === [] || $combinator === '>' ) {
					return null;
				}
				$combinator = '>';
				continue;
			}

			if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*|\*)?((?:\.[A-Za-z_-][A-Za-z0-9_-]*|\[[A-Za-z_:][-A-Za-z0-9_:.]*\]|:not\(\[[A-Za-z_:][-A-Za-z0-9_:.]*\]\))*)$/', $token, $m ) ) {
				return null;
			}

			preg_match_all( '/\.([A-Za-z_-][A-Za-z0-9_-]*)|:not\(\[([^\]]+)\]\)|\[([^\]]+)\]/', $m[2], $parts, PREG_SET_ORDER );

			$tag  = strtolower( $m[1] );
			$step = [
				'combinator' => $steps === [] ? '' : $combinator,
				'tag'        => $tag === '*' ? '' : $tag,
				'classes'    => [],
				'has'        => [],
				'not'        => [],
			];

			foreach ( $parts as $p ) {
				if ( ( $p[1] ?? '' ) !== '' ) {
					$step['classes'][] = $p[1];
				} elseif ( ( $p[2] ?? '' ) !== '' ) {
					$step['not'][] = strtolower( $p[2] );
				} elseif ( ( $p[3] ?? '' ) !== '' ) {
					$step['has'][] = strtolower( $p[3] );
				}
			}

			$steps[]    = $step;
			$combinator = ' ';
		}

		return $combinator === '>' ? null : $steps;
	}

	/**
	 * Whether element $i matches steps[0..$k], with steps[$k] on $i itself.
	 *
	 * @param array{tag: list<string>, attrs: list<array<string, string>>, parent: list<int>, start: list<int>, end: array<int, int>} $doc   Tokenized fragment.
	 * @param int                                                                                                                     $i     Element index.
	 * @param list<array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>}>               $steps Parsed steps.
	 * @param int                                                                                                                     $k     Step index.
	 * @return bool
	 */
	private static function matches_steps( array $doc, int $i, array $steps, int $k ): bool {
		if ( ! isset( $steps[ $k ] ) || ! self::matches_compound( $doc['tag'][ $i ] ?? '', $doc['attrs'][ $i ] ?? [], $steps[ $k ] ) ) {
			return false;
		}

		if ( $k === 0 ) {
			return true;
		}

		$parent = $doc['parent'][ $i ] ?? -1;

		if ( $steps[ $k ]['combinator'] === '>' ) {
			return $parent >= 0 && self::matches_steps( $doc, $parent, $steps, $k - 1 );
		}

		while ( $parent >= 0 ) {
			if ( self::matches_steps( $doc, $parent, $steps, $k - 1 ) ) {
				return true;
			}
			$parent = $doc['parent'][ $parent ] ?? -1;
		}

		return false;
	}

	/**
	 * Whether one element matches one compound selector.
	 *
	 * @param string                                                                                              $tag   Element tag.
	 * @param array<string, string>                                                                               $attrs Element attributes.
	 * @param array{combinator: string, tag: string, classes: list<string>, has: list<string>, not: list<string>} $step Compound.
	 * @return bool
	 */
	private static function matches_compound( string $tag, array $attrs, array $step ): bool {
		if ( $step['tag'] !== '' && $tag !== $step['tag'] ) {
			return false;
		}

		if ( $step['classes'] !== [] ) {
			$classes = (array) preg_split( '/\s+/', trim( $attrs['class'] ?? '' ) );

			foreach ( $step['classes'] as $class ) {
				if ( ! in_array( $class, $classes, true ) ) {
					return false;
				}
			}
		}

		foreach ( $step['has'] as $attr ) {
			if ( ! array_key_exists( $attr, $attrs ) ) {
				return false;
			}
		}

		foreach ( $step['not'] as $attr ) {
			if ( array_key_exists( $attr, $attrs ) ) {
				return false;
			}
		}

		return true;
	}
}
