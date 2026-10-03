<?php
/**
 * TranslatePress translations applied to a post field, unit by unit.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds a post field in a target language from the TranslatePress
 * translations attributed to the post (original => translation), replacing
 * only whole units the way TranslatePress replaced them on the page:
 *
 * - a text run between tags, comments and shortcode tags, compared after
 *   TranslatePress's trim (ASCII whitespace, and a no-break space or
 *   `&nbsp;` at either edge), whose surrounding whitespace is kept; a run
 *   with line breaks that matches nothing whole is tried line by line
 *   (wpautop turns those lines into paragraphs or lines of their own);
 * - the whole value of a title, aria-label or alt attribute, a placeholder
 *   on an input or textarea, and a value on a submit, button or reset input;
 * - a string value in a block comment's attributes, unless its key names
 *   markup, a link, style, colour or size, or the value looks like a URL;
 * - a quoted shortcode attribute value, with the same exclusions;
 * - an original that holds markup (a TranslatePress translation block): the
 *   inner HTML of one block-level element that contains no other block-level
 *   element, compared the way TranslatePress compares blocks (tags stripped,
 *   entities decoded, whitespace collapsed); in a field without block markup
 *   (classic content), also a paragraph that wpautop makes when the field
 *   renders ({@see self::paragraphs()}), whose text then keeps its units in
 *   a language without that block's translation.
 *
 * Text is compared through {@see TranslatePressDictionary::forms()}.
 * Script, style and textarea contents, other comments, URLs and every other
 * attribute keep their bytes, and so does any unit without a translation.
 *
 * No database access. A field is split once ({@see self::parse()}) and then
 * rendered once per language ({@see self::render()}).
 *
 * @phpstan-type Parsed array{
 *     pieces: list<string>,
 *     text: array<int, array{0: string, 1: string, 2: string}>,
 *     lines: array<int, list<string>>,
 *     attrs: array<int, array<string, string>>,
 *     json: array<int, array{0: int, 1: int, 2: list<array{0: list<int|string>, 1: string}>}>,
 *     codes: array<int, list<array{0: int, 1: int, 2: string, 3: string}>>,
 *     blocks: list<array{0: int, 1: int, 2: list<string>}>,
 *     paragraphs: list<array{0: int, 1: int, 2: int, 3: int, 4: list<string>}>
 * }
 */
final class TranslatePressContentRebuilder {

	/**
	 * Elements TranslatePress can merge into a translation block (its merge
	 * rules' top parents).
	 */
	private const BLOCK_PARENTS = [ 'p', 'div', 'li', 'ol', 'ul', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'body', 'footer', 'article', 'main', 'iframe', 'section', 'figure', 'figcaption', 'blockquote', 'cite', 'tr', 'td', 'th', 'table', 'tbody', 'thead', 'tfoot', 'form', 'label' ];

	/**
	 * Elements without a closing tag.
	 */
	private const VOID_ELEMENTS = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ];

	/**
	 * The block-level element names of wpautop() (a regex group): it ends a
	 * paragraph before their opening tags and after their closing tags.
	 */
	private const AUTOP_BLOCKS = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

	/**
	 * Keys whose values (and everything under them) are never text: markup,
	 * references, links and style. Compared lowercased.
	 */
	private const EXCLUDED_KEYS = [ 'classname', 'class', 'anchor', 'slug', 'ref', 'id', 'url', 'href', 'src', 'rel', 'tagname', 'align', 'layout', 'style' ];

	/**
	 * Key endings of colour, size and slug keys (textColor, fontSize,
	 * sizeSlug, minHeight). Compared lowercased.
	 */
	private const EXCLUDED_KEY_ENDINGS = [ 'color', 'gradient', 'size', 'slug', 'width', 'height' ];

	/**
	 * Deepest block attribute level that is read.
	 */
	private const JSON_MAX_DEPTH = 8;

	/**
	 * Most texts whose forms are remembered until the next forget(); texts
	 * beyond it get their forms computed on each lookup.
	 */
	private const FORMS_MEMO_MAX = 16384;

	/**
	 * The split pattern, built on first use.
	 *
	 * @var string
	 */
	private string $pattern = '';

	/**
	 * Forms per text ({@see TranslatePressDictionary::forms()}).
	 *
	 * @var array<string, list<string>>
	 */
	private array $forms = [];

	/**
	 * The pairs last rendered with, and their split ({@see self::split_pairs()}).
	 *
	 * @var array<string, string>
	 */
	private array $pairs = [];

	/**
	 * Text pairs and block pairs of $pairs.
	 *
	 * @var array{0: array<string, string>, 1: array<string, string>}
	 */
	private array $split = [ [], [] ];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $shortcode_tags Registered shortcode names: only these split text runs.
	 */
	public function __construct( private readonly array $shortcode_tags = [] ) {}

	/**
	 * Drop the remembered forms and pairs (the importer calls this for each batch).
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->forms = [];
		$this->pairs = [];
		$this->split = [ [], [] ];
	}

	/**
	 * Split a field into the units a translation can replace.
	 *
	 * @param string $field       Raw field value.
	 * @param bool   $with_blocks Whether to find translation-block candidates (only needed when an original holds markup).
	 * @return array|null Parsed field, or null when the field could not be split.
	 * @phpstan-return Parsed|null
	 */
	public function parse( string $field, bool $with_blocks ): ?array {
		$parsed = [
			'pieces'     => [],
			'text'       => [],
			'lines'      => [],
			'attrs'      => [],
			'json'       => [],
			'codes'      => [],
			'blocks'     => [],
			'paragraphs' => [],
		];

		if ( $field === '' ) {
			return $parsed;
		}

		$pieces = preg_split( $this->pattern(), $field, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $pieces ) || preg_last_error() !== PREG_NO_ERROR ) {
			return null;
		}

		$parsed['pieces'] = $pieces;

		// Open elements while finding block candidates: [ name, piece index, holds a block parent ].
		$stack = [];

		foreach ( $pieces as $i => $piece ) {
			if ( $piece === '' ) {
				continue;
			}

			// Even pieces are the text between the tokens.
			if ( $i % 2 === 0 ) {
				[ $lead, $core, $trail ] = self::trim_parts( $piece );

				if ( $core !== '' ) {
					$parsed['text'][ $i ] = [ $lead, $core, $trail ];

					if ( strpbrk( $core, "\r\n" ) !== false ) {
						$lines = preg_split( '/((?:\r\n|\r|\n)+)/', $piece, -1, PREG_SPLIT_DELIM_CAPTURE );

						if ( ! is_array( $lines ) ) {
							return null;
						}

						$parsed['lines'][ $i ] = $lines;
					}
				}

				continue;
			}

			if ( str_starts_with( $piece, '<!--' ) ) {
				$json = $this->block_json( $piece );

				if ( $json !== null ) {
					$parsed['json'][ $i ] = $json;
				}

				continue;
			}

			if ( $piece[0] === '[' ) {
				$values = $this->shortcode_values( $piece );

				if ( $values !== [] ) {
					$parsed['codes'][ $i ] = $values;
				}

				continue;
			}

			$closing = $piece[1] === '/';
			$start   = $closing ? 2 : 1;
			$name    = strtolower( substr( $piece, $start, strcspn( $piece, " \t\n\r\f/>", $start ) ) );

			if ( $name === 'script' || $name === 'style' ) {
				continue;
			}

			if ( ! $closing ) {
				$attrs = self::attributes( $piece );

				if ( $attrs !== [] ) {
					$parsed['attrs'][ $i ] = $attrs;
				}
			}

			if ( ! $with_blocks || $name === 'textarea' ) {
				continue;
			}

			if ( $closing ) {
				// The element this closes; elements left open inside it end here too.
				$s = count( $stack ) - 1;

				while ( $s >= 0 && $stack[ $s ][0] !== $name ) {
					--$s;
				}

				if ( $s < 0 ) {
					continue;
				}

				$open  = $stack[ $s ];
				$stack = array_slice( $stack, 0, $s );

				if ( ! $open[2] && in_array( $name, self::BLOCK_PARENTS, true ) ) {
					$inner = implode( '', array_slice( $pieces, $open[1] + 1, $i - $open[1] - 1 ) );
					$keys  = array_values( array_unique( array_filter( [ self::block_key( $inner ), self::block_key( wptexturize( $inner ) ) ] ) ) );

					if ( $keys !== [] ) {
						$parsed['blocks'][] = [ $open[1], $i, $keys ];
					}
				}

				continue;
			}

			if ( in_array( $name, self::VOID_ELEMENTS, true ) || str_ends_with( $piece, '/>' ) ) {
				continue;
			}

			if ( in_array( $name, self::BLOCK_PARENTS, true ) ) {
				foreach ( array_keys( $stack ) as $s ) {
					$stack[ $s ][2] = true;
				}
			}

			$stack[] = [ $name, $i, false ];
		}

		// Classic content: no block delimiter (core's has_blocks() test), so wpautop makes its paragraphs.
		if ( $with_blocks && ! str_contains( $field, '<!-- wp:' ) ) {
			$parsed['paragraphs'] = self::paragraphs( $field, $pieces );
		}

		return $parsed;
	}

	/**
	 * The field in one language.
	 *
	 * @param array                 $parsed {@see self::parse()} result.
	 * @param array<string, string> $pairs  Original => translation, for this post and language.
	 * @return string
	 * @phpstan-param Parsed $parsed
	 */
	public function render( array $parsed, array $pairs ): string {
		// The fields of one post and language share their pairs: split them once.
		if ( $pairs !== $this->pairs ) {
			$this->pairs = $pairs;
			$this->split = self::split_pairs( $pairs );
		}

		[ $text_pairs, $block_pairs ] = $this->split;

		$out = $parsed['pieces'];

		// Pieces inside a translated block: its translation replaced them whole.
		$inside = [];

		// Classic paragraphs with a translation block in this language, cut
		// out of the pieces they start and end in: [ from, to, translation ].
		$cuts = [];

		foreach ( $block_pairs === [] ? [] : $parsed['paragraphs'] as [ $first, $from, $last, $to, $keys ] ) {
			$translation = null;

			foreach ( $keys as $key ) {
				if ( isset( $block_pairs[ $key ] ) ) {
					$translation = $block_pairs[ $key ];
					break;
				}
			}

			if ( $translation === null ) {
				continue;
			}

			for ( $i = $first + 1; $i < $last; $i++ ) {
				$out[ $i ]    = '';
				$inside[ $i ] = true;
			}

			$cuts[ $first ][] = [ $from, $first === $last ? $to : strlen( $out[ $first ] ), $translation ];

			if ( $first !== $last ) {
				$cuts[ $last ][] = [ 0, $to, '' ];
			}
		}

		// The rest of such a piece keeps its own units.
		foreach ( $cuts as $i => $ranges ) {
			$text = '';
			$at   = 0;

			foreach ( $ranges as [ $from, $to, $translation ] ) {
				$text .= $this->render_text( substr( $out[ $i ], $at, $from - $at ), $text_pairs ) . $translation;
				$at    = $to;
			}

			$out[ $i ]    = $text . $this->render_text( substr( $out[ $i ], $at ), $text_pairs );
			$inside[ $i ] = true;
		}

		if ( $parsed['blocks'] === [] ) {
			$block_pairs = [];
		}

		foreach ( $block_pairs === [] ? [] : $parsed['blocks'] as [ $open, $close, $keys ] ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $block_pairs[ $key ] ) ) {
					continue;
				}

				[ $lead, , $trail ] = self::trim_parts( implode( '', array_slice( $out, $open + 1, $close - $open - 1 ) ) );

				for ( $i = $open + 1; $i < $close; $i++ ) {
					$out[ $i ]    = '';
					$inside[ $i ] = true;
				}

				$out[ $open + 1 ] = $lead . $block_pairs[ $key ] . $trail;
				break;
			}
		}

		if ( $text_pairs === [] ) {
			return implode( '', $out );
		}

		foreach ( $parsed['text'] as $i => [ $lead, $core, $trail ] ) {
			if ( isset( $inside[ $i ] ) ) {
				continue;
			}

			$translation = $this->translation( $core, $text_pairs );

			if ( $translation !== null ) {
				$out[ $i ] = $lead . $translation . $trail;
			} elseif ( isset( $parsed['lines'][ $i ] ) ) {
				$out[ $i ] = $this->render_lines( $parsed['lines'][ $i ], $text_pairs );
			}
		}

		foreach ( $parsed['attrs'] as $i => $attrs ) {
			if ( isset( $inside[ $i ] ) ) {
				continue;
			}

			$updates = [];

			foreach ( $attrs as $name => $value ) {
				[ $lead, $core, $trail ] = self::trim_parts( $value );
				$translation             = $this->translation( $core, $text_pairs );

				if ( $translation !== null ) {
					// The processor escapes what it writes: hand it plain text.
					$updates[ $name ] = $lead . html_entity_decode( $translation, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . $trail;
				}
			}

			if ( $updates !== [] ) {
				$processor = new \WP_HTML_Tag_Processor( $out[ $i ] );

				if ( $processor->next_tag() ) {
					foreach ( $updates as $name => $value ) {
						$processor->set_attribute( $name, $value );
					}

					$out[ $i ] = $processor->get_updated_html();
				}
			}
		}

		foreach ( $parsed['json'] as $i => [ $offset, $length, $leaves ] ) {
			if ( isset( $inside[ $i ] ) ) {
				continue;
			}

			$updates = [];

			foreach ( $leaves as [ $path, $value ] ) {
				[ $lead, $core, $trail ] = self::trim_parts( $value );
				$translation             = $this->translation( $core, $text_pairs );

				if ( $translation !== null ) {
					$updates[] = [ $path, $lead . $translation . $trail ];
				}
			}

			$attributes = $updates === [] ? null : json_decode( substr( $out[ $i ], $offset, $length ) );

			if ( ! $attributes instanceof \stdClass ) {
				continue;
			}

			foreach ( $updates as [ $path, $value ] ) {
				$attributes = self::with_value( $attributes, $path, $value );
			}

			$out[ $i ] = substr_replace( $out[ $i ], serialize_block_attributes( (array) $attributes ), $offset, $length );
		}

		foreach ( $parsed['codes'] as $i => $values ) {
			if ( isset( $inside[ $i ] ) ) {
				continue;
			}

			// Last value first, so the earlier offsets stay valid.
			foreach ( array_reverse( $values ) as [ $offset, $length, $value, $quote ] ) {
				[ $lead, $core, $trail ] = self::trim_parts( $value );
				$translation             = $this->translation( $core, $text_pairs );

				// A translation that would end the value or the tag is left out.
				if ( $translation !== null && strpbrk( $translation, $quote . '[]' ) === false ) {
					$out[ $i ] = substr_replace( $out[ $i ], $lead . $translation . $trail, $offset, $length );
				}
			}
		}

		return implode( '', $out );
	}

	/**
	 * Pairs split into text pairs and translation-block pairs (an original
	 * that holds markup), the latter keyed the way blocks are compared.
	 *
	 * @param array<string, string> $pairs Original => translation.
	 * @return array{0: array<string, string>, 1: array<string, string>}
	 */
	private static function split_pairs( array $pairs ): array {
		$text_pairs  = [];
		$block_pairs = [];

		foreach ( $pairs as $original => $translation ) {
			$original = (string) $original;

			if ( ! str_contains( $original, '<' ) ) {
				$text_pairs[ $original ] = $translation;
				continue;
			}

			$key = self::block_key( $original );

			if ( $key !== '' && ! isset( $block_pairs[ $key ] ) ) {
				$block_pairs[ $key ] = $translation;
			}
		}

		return [ $text_pairs, $block_pairs ];
	}

	/**
	 * The paragraphs wpautop makes of a field without block markup (classic
	 * content) that can each be a translation block of their own:
	 * TranslatePress stores one as the paragraph's inner HTML, and the raw
	 * field holds that without the paragraph's tags.
	 *
	 * A paragraph ends where wpautop ends it: at a blank line, at two br tags
	 * with only white space between them, before a block-level opening tag
	 * and after a block-level closing tag or a bare hr. It counts when it
	 * starts with no element open and outside every enclosing shortcode, ends
	 * with no element open, holds a tag or a line break (so its HTML holds
	 * markup), and holds no block-level tag, top parent of a TranslatePress
	 * block, comment, shortcode tag, script, style, textarea or closing tag
	 * without an open element. wpautop reads a "<" that starts no tag (an
	 * unclosed comment, a stray "<") as a tag up to the next ">", so no
	 * paragraph counts from there on.
	 *
	 * @param string             $field  Raw field value.
	 * @param array<int, string> $pieces The field's pieces.
	 * @return list<array{0: int, 1: int, 2: int, 3: int, 4: list<string>}> For each paragraph's trimmed text: the piece it starts in and the byte offset there, the piece it ends in and the byte offset after it there, and its keys.
	 */
	private static function paragraphs( string $field, array $pieces ): array {
		// Piece indexes of the closing shortcode tags, by name, and the next one to compare with.
		$closers = [];
		$next    = [];

		foreach ( $pieces as $i => $piece ) {
			if ( $i % 2 === 1 && str_starts_with( $piece, '[/' ) ) {
				$closers[ substr( $piece, 2, strcspn( $piece, " \t\n\r\f\v]", 2 ) ) ][] = $i;
			}
		}

		$paragraphs = [];
		$stack      = []; // Names of the open elements.
		$open       = []; // Number of open elements per name.
		$enclosed   = -1; // Piece index of the last closing tag of the enclosing shortcodes opened so far.
		$offset     = 0;  // Byte offset after the current piece.
		$start      = 0;  // Byte offset of the current paragraph.
		$from_piece = [ 0, 0 ]; // A piece at or before the current paragraph's start, and its byte offset.
		$ok         = true;
		$tags       = 0;
		$br         = -1; // Byte offset of a br tag with only white space after it so far.
		$br_tags    = 0;  // Tags in the current paragraph before that br tag.

		foreach ( $pieces as $i => $piece ) {
			$at      = $offset;
			$offset += strlen( $piece );

			// Even pieces are the text between the tokens: a blank line ends a paragraph.
			if ( $i % 2 === 0 ) {
				$stray = strpos( $piece, '<' );

				if ( strpbrk( $piece, "\r\n" ) !== false && preg_match_all( '~(?>\r\n|\r|\n)\s*(?>\r\n|\r|\n)~', $piece, $blanks, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $blanks[0] as [ $blank, $from ] ) {
						if ( $stray !== false && $from > $stray ) {
							break;
						}

						$paragraphs[] = $ok && $stack === [] ? self::paragraph( $field, $pieces, $from_piece, $start, $at + $from, $tags ) : null;
						$start        = $at + $from + strlen( $blank );
						$from_piece   = [ $i, $at ];
						$ok           = $stack === [] && $enclosed < $i;
						$tags         = 0;
						$br           = -1;
					}
				}

				if ( $stray !== false ) {
					return array_values( array_filter( $paragraphs ) );
				}

				if ( strspn( $piece, " \t\n\r\f\v" ) !== strlen( $piece ) ) {
					$br = -1;
				}

				continue;
			}

			if ( $piece[0] === '[' && str_contains( $piece, '<' ) ) {
				return array_values( array_filter( $paragraphs ) );
			}

			if ( preg_match( '~^<br\s*/?>\z~', $piece ) === 1 ) {
				if ( $br >= 0 ) {
					$paragraphs[] = $ok && $stack === [] ? self::paragraph( $field, $pieces, $from_piece, $start, $br, $br_tags ) : null;
					$start        = $offset;
					$from_piece   = [ $i + 1, $offset ];
					$ok           = $stack === [] && $enclosed < $i;
					$tags         = 0;
					$br           = -1;

					continue;
				}

				$br      = $at;
				$br_tags = $tags;
			} else {
				$br = -1;
			}

			if ( $piece[0] === '[' || str_starts_with( $piece, '<!--' ) ) {
				$ok = false;

				// An opening shortcode tag with a closing tag of its name after it encloses what lies between them.
				if ( $piece[0] === '[' && $piece[1] !== '/' && ! str_ends_with( $piece, '/]' ) ) {
					foreach ( $closers as $name => $indexes ) {
						$name = (string) $name;

						if ( ! str_starts_with( $piece, '[' . $name ) || preg_match( '~^[\w-]~', substr( $piece, strlen( $name ) + 1, 1 ) ) === 1 ) {
							continue;
						}

						$n = $next[ $name ] ?? 0;

						while ( isset( $indexes[ $n ] ) && $indexes[ $n ] < $i ) {
							++$n;
						}

						$next[ $name ] = $n;

						if ( isset( $indexes[ $n ] ) ) {
							$enclosed = max( $enclosed, $indexes[ $n ] );
						}
					}
				}

				continue;
			}

			$closing = $piece[1] === '/';
			$from    = $closing ? 2 : 1;
			$name    = strtolower( substr( $piece, $from, strcspn( $piece, " \t\n\r\f/>", $from ) ) );
			$whole   = ! $closing && in_array( $name, [ 'script', 'style', 'textarea' ], true ) && preg_match( '~</' . $name . '[^>]*+>\z~i', $piece ) === 1;
			$before  = ! $closing && preg_match( '~^<' . self::AUTOP_BLOCKS . '[\s/>]~', $piece ) === 1;
			$after   = ( $closing || $whole || $name === 'hr' ) && preg_match( '~(?:</' . self::AUTOP_BLOCKS . '|^<hr\s*?/?)>\z~', $piece ) === 1;

			if ( $before ) {
				$paragraphs[] = $ok && $stack === [] ? self::paragraph( $field, $pieces, $from_piece, $start, $at, $tags ) : null;
			}

			if ( $name === 'script' || $name === 'textarea' || in_array( $name, self::BLOCK_PARENTS, true ) || preg_match( '~^' . self::AUTOP_BLOCKS . '\z~', $name ) === 1 ) {
				$ok = false;
			}

			++$tags;

			if ( ! $whole && $closing ) {
				if ( ( $open[ $name ] ?? 0 ) > 0 ) {
					// The element this closes; elements left open inside it end here too.
					do {
						$top = (string) array_pop( $stack );
						--$open[ $top ];
					} while ( $top !== $name );
				} else {
					$ok = false;
				}
			} elseif ( ! $whole && ! in_array( $name, self::VOID_ELEMENTS, true ) && ! str_ends_with( $piece, '/>' ) ) {
				$stack[]       = $name;
				$open[ $name ] = ( $open[ $name ] ?? 0 ) + 1;
			}

			if ( $after ) {
				$start      = $offset;
				$from_piece = [ $i + 1, $offset ];
				$ok         = $stack === [] && $enclosed < $i;
				$tags       = 0;
			}
		}

		$paragraphs[] = $ok && $stack === [] ? self::paragraph( $field, $pieces, $from_piece, $start, $offset, $tags ) : null;

		return array_values( array_filter( $paragraphs ) );
	}

	/**
	 * One paragraph of {@see self::paragraphs()}, when it can be a translation block.
	 *
	 * @param string                $field      Raw field value.
	 * @param array<int, string>    $pieces     The field's pieces.
	 * @param array{0: int, 1: int} $from_piece A piece at or before the paragraph's start, and its byte offset.
	 * @param int                   $start      Byte offset of the paragraph.
	 * @param int                   $end        Byte offset after it.
	 * @param int                   $tags       Number of tags in it.
	 * @return array{0: int, 1: int, 2: int, 3: int, 4: list<string>}|null Its trimmed text's first piece and byte offset there, last piece and byte offset after it there, and its keys.
	 */
	private static function paragraph( string $field, array $pieces, array $from_piece, int $start, int $end, int $tags ): ?array {
		[ $lead, $core ] = self::trim_parts( substr( $field, $start, $end - $start ) );

		// Without a tag or a line break, the paragraph's HTML holds no markup.
		if ( $core === '' || ( $tags === 0 && strpbrk( $core, "\r\n" ) === false ) ) {
			return null;
		}

		$keys = array_values( array_unique( array_filter( [ self::block_key( $core ), self::block_key( wptexturize( $core ) ) ] ) ) );

		if ( $keys === [] ) {
			return null;
		}

		$from       = $start + strlen( $lead );
		$to         = $from + strlen( $core );
		[ $k, $at ] = $from_piece;
		$size       = strlen( $pieces[ $k ] );

		while ( $at + $size <= $from ) {
			$at  += $size;
			$size = strlen( $pieces[ ++$k ] );
		}

		$first = $k;
		$skip  = $from - $at;

		while ( $at + $size < $to ) {
			$at  += $size;
			$size = strlen( $pieces[ ++$k ] );
		}

		return [ $first, $skip, $k, $to - $at, $keys ];
	}

	/**
	 * Text between tokens in one language, as {@see self::render()} renders
	 * a text piece: translated whole, or line by line.
	 *
	 * @param string                $text  Text.
	 * @param array<string, string> $pairs Text pairs.
	 * @return string
	 */
	private function render_text( string $text, array $pairs ): string {
		[ $lead, $core, $trail ] = self::trim_parts( $text );

		if ( $core === '' || $pairs === [] ) {
			return $text;
		}

		$translation = $this->translation( $core, $pairs );

		if ( $translation !== null ) {
			return $lead . $translation . $trail;
		}

		if ( strpbrk( $core, "\r\n" ) !== false ) {
			$lines = preg_split( '/((?:\r\n|\r|\n)+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );

			if ( is_array( $lines ) ) {
				return $this->render_lines( $lines, $pairs );
			}
		}

		return $text;
	}

	/**
	 * A text run with line breaks, translated line by line.
	 *
	 * @param array<int, string>    $lines Lines and the line breaks between them.
	 * @param array<string, string> $pairs Text pairs.
	 * @return string
	 */
	private function render_lines( array $lines, array $pairs ): string {
		foreach ( $lines as $n => $line ) {
			if ( $n % 2 === 1 ) {
				continue;
			}

			[ $lead, $core, $trail ] = self::trim_parts( $line );
			$translation             = $core === '' ? null : $this->translation( $core, $pairs );

			if ( $translation !== null ) {
				$lines[ $n ] = $lead . $translation . $trail;
			}
		}

		return implode( '', $lines );
	}

	/**
	 * The translation of a whole text, or null.
	 *
	 * @param string                $text  Trimmed text.
	 * @param array<string, string> $pairs Text pairs.
	 * @return string|null
	 */
	private function translation( string $text, array $pairs ): ?string {
		if ( $text === '' ) {
			return null;
		}

		// The text as it is, the first of its forms.
		if ( isset( $pairs[ $text ] ) ) {
			return $pairs[ $text ];
		}

		$forms = $this->forms[ $text ] ?? null;

		if ( $forms === null ) {
			$forms = TranslatePressDictionary::forms( $text );

			if ( count( $this->forms ) < self::FORMS_MEMO_MAX ) {
				$this->forms[ $text ] = $forms;
			}
		}

		foreach ( $forms as $form ) {
			if ( isset( $pairs[ $form ] ) ) {
				return $pairs[ $form ];
			}
		}

		return null;
	}

	/**
	 * The split pattern: comments, script, style and textarea elements,
	 * tags (quote-aware) and registered shortcode tags. What lies between
	 * them is text. Every repetition is possessive, so a split is linear.
	 *
	 * @return string
	 */
	private function pattern(): string {
		if ( $this->pattern !== '' ) {
			return $this->pattern;
		}

		$attrs = '(?:\s++|/|[^\s/>=]++|=\s*+(?:"[^"]*+"|\'[^\']*+\'|[^\s>]*+))*+';
		$alts  = [ '<!--(?:[^-]++|-(?!->))*+-->' ];

		foreach ( [ 'script', 'style', 'textarea' ] as $raw ) {
			$alts[] = '<(?i:' . $raw . ')(?![^\s/>])' . $attrs . '>(?:[^<]++|<(?!/(?i:' . $raw . ')[\s/>]))*+</(?i:' . $raw . ')[^>]*+>';
		}

		$alts[] = '</?[a-zA-Z][^\s/>]*+' . $attrs . '>';

		$names = array_filter( array_map( 'strval', $this->shortcode_tags ), static fn( string $name ): bool => $name !== '' );

		if ( $names !== [] ) {
			$alts[] = '\[/?(?:' . implode( '|', array_map( static fn( string $name ): string => preg_quote( $name, '~' ), $names ) ) . ')(?![\w-])[^\]]*+\]';
		}

		$this->pattern = '~(' . implode( '|', $alts ) . ')~';

		return $this->pattern;
	}

	/**
	 * A text split as TranslatePress trims it: ASCII whitespace, and a
	 * no-break space or `&nbsp;` at either edge, repeated until none is left.
	 *
	 * @param string $text Text.
	 * @return array{0: string, 1: string, 2: string} Leading part, core, trailing part.
	 */
	private static function trim_parts( string $text ): array {
		$start = 0;
		$end   = strlen( $text );

		do {
			$before = [ $start, $end ];
			$start += strspn( $text, " \t\n\r\0\x0B", $start, $end - $start );

			while ( $end > $start && strpos( " \t\n\r\0\x0B", $text[ $end - 1 ] ) !== false ) {
				--$end;
			}

			foreach ( [ "\xC2\xA0", '&nbsp;' ] as $space ) {
				$size = strlen( $space );

				if ( $end - $start >= $size && substr_compare( $text, $space, $start, $size ) === 0 ) {
					$start += $size;
				}

				if ( $end - $start >= $size && substr_compare( $text, $space, $end - $size, $size ) === 0 ) {
					$end -= $size;
				}
			}
		} while ( [ $start, $end ] !== $before );

		return [ substr( $text, 0, $start ), substr( $text, $start, $end - $start ), substr( $text, $end ) ];
	}

	/**
	 * A translation block's text the way TranslatePress compares blocks:
	 * trimmed, tags stripped, entities decoded, whitespace collapsed.
	 *
	 * @param string $html Inner HTML or original.
	 * @return string
	 */
	private static function block_key( string $html ): string {
		$text = wp_strip_all_tags( self::trim_parts( $html )[1] );

		return (string) preg_replace( '/\s+/', ' ', html_entity_decode( htmlspecialchars_decode( $text, ENT_QUOTES ) ) );
	}

	/**
	 * The translatable attributes of a start tag and their decoded values.
	 *
	 * @param string $tag Start tag (or a whole textarea element).
	 * @return array<string, string>
	 */
	private static function attributes( string $tag ): array {
		if ( ! preg_match( '~[\s"\'/](?:title|aria-label|alt|placeholder|value)\s*+=~i', $tag ) ) {
			return [];
		}

		$processor = new \WP_HTML_Tag_Processor( $tag );

		if ( ! $processor->next_tag() ) {
			return [];
		}

		$element = (string) $processor->get_tag();
		$names   = [ 'title', 'aria-label', 'alt' ];

		if ( $element === 'INPUT' || $element === 'TEXTAREA' ) {
			$names[] = 'placeholder';
		}

		$type = $processor->get_attribute( 'type' );

		if ( $element === 'INPUT' && is_string( $type ) && in_array( strtolower( $type ), [ 'submit', 'button', 'reset' ], true ) ) {
			$names[] = 'value';
		}

		$values = [];

		foreach ( $names as $name ) {
			$value = $processor->get_attribute( $name );

			if ( is_string( $value ) && trim( $value ) !== '' ) {
				$values[ $name ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Where a block comment's attributes are, and their string values.
	 *
	 * @param string $comment Comment token.
	 * @return array{0: int, 1: int, 2: list<array{0: list<int|string>, 1: string}>}|null Offset and length of the JSON, and the text values with their paths.
	 */
	private function block_json( string $comment ): ?array {
		if ( ! str_contains( $comment, '{' ) || ! preg_match( '~^<!--\s++wp:(?:[a-z][a-z0-9_-]*+/)?[a-z][a-z0-9_-]*+\s++(\{(?:(?:[^}]++|}++(?=})|(?!}\s++/?-->).)*+)?\})\s++/?-->$~s', $comment, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$attributes = json_decode( $match[1][0] );

		if ( ! $attributes instanceof \stdClass ) {
			return null;
		}

		$leaves = [];
		self::text_leaves( $attributes, [], 0, $leaves );

		return $leaves === [] ? null : [ $match[1][1], strlen( $match[1][0] ), $leaves ];
	}

	/**
	 * Collect the string values that can be text, with their paths.
	 *
	 * @param mixed                                       $node   Decoded value.
	 * @param list<int|string>                            $path   Path to $node.
	 * @param int                                         $depth  Depth of $node.
	 * @param list<array{0: list<int|string>, 1: string}> $leaves Collected values.
	 * @return void
	 */
	private static function text_leaves( mixed $node, array $path, int $depth, array &$leaves ): void {
		if ( $depth >= self::JSON_MAX_DEPTH ) {
			return;
		}

		$children = $node instanceof \stdClass ? get_object_vars( $node ) : ( is_array( $node ) ? $node : [] );

		foreach ( $children as $key => $value ) {
			if ( is_string( $key ) && self::is_excluded_key( $key ) ) {
				continue;
			}

			$child = [ ...$path, $key ];

			if ( is_string( $value ) ) {
				if ( trim( $value ) !== '' && ! self::looks_like_url( $value ) ) {
					$leaves[] = [ $child, $value ];
				}
			} elseif ( is_array( $value ) || $value instanceof \stdClass ) {
				self::text_leaves( $value, $child, $depth + 1, $leaves );
			}
		}
	}

	/**
	 * A decoded value with one string replaced.
	 *
	 * @param mixed            $node  Decoded value.
	 * @param list<int|string> $path  Path of the string.
	 * @param string           $value New string.
	 * @return mixed
	 */
	private static function with_value( mixed $node, array $path, string $value ): mixed {
		if ( $path === [] ) {
			return $value;
		}

		$key = array_shift( $path );

		if ( $node instanceof \stdClass ) {
			$node->{$key} = self::with_value( $node->{$key} ?? null, $path, $value );
		} elseif ( is_array( $node ) ) {
			$node[ $key ] = self::with_value( $node[ $key ] ?? null, $path, $value );
		}

		return $node;
	}

	/**
	 * The quoted attribute values of a shortcode's opening tag.
	 *
	 * @param string $tag Shortcode tag.
	 * @return list<array{0: int, 1: int, 2: string, 3: string}> Offset, length, value and quote of each value that can be text.
	 */
	private function shortcode_values( string $tag ): array {
		if ( str_starts_with( $tag, '[/' ) || ! preg_match_all( '~([\w-]++)\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\')~', $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$values = [];

		foreach ( $matches as $match ) {
			// Group 2 holds a double-quoted value, group 3 a single-quoted one.
			$quote = isset( $match[3] ) && $match[3][1] >= 0 ? "'" : '"';

			[ $value, $offset ] = $match[ "'" === $quote ? 3 : 2 ] ?? [ '', -1 ];

			if ( $offset < 0 || self::is_excluded_key( $match[1][0] ) || trim( $value ) === '' || self::looks_like_url( $value ) ) {
				continue;
			}

			$values[] = [ $offset, strlen( $value ), $value, $quote ];
		}

		return $values;
	}

	/**
	 * Whether a key names markup, a reference, a link, style, colour or size.
	 *
	 * @param string $key Attribute key.
	 * @return bool
	 */
	private static function is_excluded_key( string $key ): bool {
		$key = strtolower( $key );

		if ( in_array( $key, self::EXCLUDED_KEYS, true ) ) {
			return true;
		}

		foreach ( self::EXCLUDED_KEY_ENDINGS as $ending ) {
			if ( str_ends_with( $key, $ending ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a value looks like a URL, a path, an anchor or a query.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function looks_like_url( string $value ): bool {
		return (bool) preg_match( '~^\s*+(?:[a-z][a-z0-9+.\-]*+://|(?:mailto|tel|sms|data|javascript):|www\.|[/#?])~i', $value );
	}
}
