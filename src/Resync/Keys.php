<?php
/**
 * Normalisation, unit keys, canonical forms and similarity.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Util\StateHash;
use PerfLocale\Util\TextTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The comparison forms that decide which units and blocks count as the same; composition never writes them, it copies
 * bytes. norm() keeps canonical tags in html units and re-escapes decoded text, so the form is injective; a unit key
 * leaves the block name out, so text moved into another block type keeps its translation.
 */
final class Keys {

	/** Blocks whose whole content is compared byte for byte, line endings included (their HTML is a `<pre>`). */
	public const EXACT_BLOCKS = [ 'core/code', 'core/preformatted', 'core/verse' ];

	/**
	 * The elements whose content is compared byte for byte; `plaintext` runs to the fragment's end and renders with its
	 * whitespace.
	 */
	private const EXACT_ELEMENTS = [
		'pre'       => true,
		'textarea'  => true,
		'code'      => true,
		'plaintext' => true,
	];

	/**
	 * The elements whose content the tokenizer reads as text (a tag-like text inside is no tag); `plaintext` to the end.
	 * `noscript` is read as markup, as a browser without scripting shows it (the stricter reading).
	 */
	private const RAW_TEXT_ELEMENTS = [
		'script'    => true,
		'style'     => true,
		'textarea'  => true,
		'title'     => true,
		'xmp'       => true,
		'iframe'    => true,
		'noembed'   => true,
		'noframes'  => true,
		'plaintext' => true,
	];

	/**
	 * The roots of foreign content (SVG, MathML): inside them no element is
	 * raw text, as the tokenizer switches its state only for HTML elements.
	 */
	private const FOREIGN_ELEMENTS = [
		'svg'  => true,
		'math' => true,
	];

	/**
	 * The start tags that end foreign content. `font` with a color, face or size attribute does too, but the walk keeps
	 * it foreign: the reading that hides no tag.
	 */
	private const FOREIGN_BREAKOUT = [
		'b'          => true,
		'big'        => true,
		'blockquote' => true,
		'body'       => true,
		'br'         => true,
		'center'     => true,
		'code'       => true,
		'dd'         => true,
		'div'        => true,
		'dl'         => true,
		'dt'         => true,
		'em'         => true,
		'embed'      => true,
		'h1'         => true,
		'h2'         => true,
		'h3'         => true,
		'h4'         => true,
		'h5'         => true,
		'h6'         => true,
		'head'       => true,
		'hr'         => true,
		'i'          => true,
		'img'        => true,
		'li'         => true,
		'listing'    => true,
		'menu'       => true,
		'meta'       => true,
		'nobr'       => true,
		'ol'         => true,
		'p'          => true,
		'pre'        => true,
		'ruby'       => true,
		's'          => true,
		'small'      => true,
		'span'       => true,
		'strong'     => true,
		'strike'     => true,
		'sub'        => true,
		'sup'        => true,
		'table'      => true,
		'tt'         => true,
		'u'          => true,
		'ul'         => true,
		'var'        => true,
	];

	/** The first byte of a tag name: an ASCII letter. */
	private const TAG_NAME_START = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/** The tokenizer's whitespace: tab, LF, FF, space and CR (the input stream reads CR as LF). */
	private const TAG_SPACE = " \t\n\r\f";

	/** Role classes. */
	public const ROLE_HTML    = 'html';
	public const ROLE_TITLE   = 'title';
	public const ROLE_EXCERPT = 'excerpt';

	private const ROLE_TEXT_PREFIX = 'text:';
	private const ROLE_META_PREFIX = 'meta:';

	/** Tags and comments in an HTML fragment; one pattern with the similarity tokens. */
	private const TAG_PATTERN = TextTokens::TAG_PATTERN;

	private const ATTRIBUTE_PATTERN = '#([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#';

	/** The slash and name of a tag (comments do not match). */
	private const TAG_NAME_PATTERN = '#^<(/?)([a-zA-Z][a-zA-Z0-9:-]*)#';

	/** A URL or a run of ASCII digits in decoded text (a URL first, so its digits are part of it). */
	private const FIGURE_PATTERN = '#https?://[^\s<>"\']+|[0-9]+#';

	/** A non-ASCII decimal digit (Unicode category Nd: Arabic-Indic, Persian, Devanagari, fullwidth, ...). */
	private const NATIVE_DIGIT_PATTERN = '/(?![0-9])\p{Nd}/u';

	/**
	 * How far back a digit's run is read to find its value: Unicode decimal digits come in ordered runs of ten, and the
	 * longest stretch of adjacent runs is 50 (the mathematical digits).
	 */
	private const DIGIT_RUN_LIMIT = 64;

	/**
	 * The ASCII digit of each native decimal digit met, keyed by its UTF-8
	 * bytes; at most one entry per decimal digit Unicode defines.
	 *
	 * @var array<string, string>
	 */
	private static array $digit_values = [];

	/**
	 * The escapes written back into a decoded text run, and into a decoded
	 * attribute value (double-quoted in the canonical tag). Byte-wise, so
	 * text that is not valid UTF-8 keeps its bytes.
	 */
	private const TEXT_ESCAPES = [
		'&' => '&amp;',
		'<' => '&lt;',
		'>' => '&gt;',
	];

	private const VALUE_ESCAPES = [
		'&' => '&amp;',
		'"' => '&quot;',
	];

	/**
	 * The role class of a text attribute unit.
	 *
	 * @param string $attribute Attribute name (alt, label, ...).
	 * @return string
	 */
	public static function role_text( string $attribute ): string {
		return self::ROLE_TEXT_PREFIX . $attribute;
	}

	/**
	 * The role class of a custom field unit.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	public static function role_meta( string $key ): string {
		return self::ROLE_META_PREFIX . $key;
	}

	/**
	 * The normalised form of a unit's text.
	 *
	 * @param string $text   Text or HTML.
	 * @param string $format {@see BlockAttributeSource::FORMAT_HTML} or {@see BlockAttributeSource::FORMAT_TEXT}.
	 * @return string
	 */
	public static function norm( string $text, string $format = BlockAttributeSource::FORMAT_HTML ): string {
		return self::collapse( $format === BlockAttributeSource::FORMAT_HTML ? self::canonical_html( $text ) : self::decode( $text ) );
	}

	/**
	 * Whether a fragment holds any text once tags and comments are stripped (html; all bytes count when the tag regex
	 * fails) and entities decoded. Unicode whitespace is not text, nor is an attribute value.
	 *
	 * @param string $text   Text or HTML.
	 * @param string $format {@see BlockAttributeSource::FORMAT_HTML} or {@see BlockAttributeSource::FORMAT_TEXT}.
	 * @return bool
	 */
	public static function has_text( string $text, string $format = BlockAttributeSource::FORMAT_HTML ): bool {
		if ( $format === BlockAttributeSource::FORMAT_HTML ) {
			$text = preg_replace( self::TAG_PATTERN, '', $text ) ?? $text;
		}

		return self::collapse( self::decode( $text ) ) !== '';
	}

	/**
	 * The bytes of an html fragment's text: the fragment without its tags
	 * and comments (all of it when the tag regex fails).
	 *
	 * @param string $html Fragment.
	 * @return int
	 */
	public static function text_bytes( string $html ): int {
		return strlen( preg_replace( self::TAG_PATTERN, '', $html ) ?? $html );
	}

	/**
	 * The key of a unit.
	 *
	 * @param string $role   Role class: html, text:<attribute>, title, excerpt, meta:<key>.
	 * @param string $text   The unit's text.
	 * @param string $format The unit's format.
	 * @return string StateHash::plain() of the role and the normalised text: 64 hex characters (Matcher reads its first 15 as a number).
	 */
	public static function key( string $role, string $text, string $format = BlockAttributeSource::FORMAT_HTML ): string {
		return StateHash::plain( $role, self::norm( $text, $format ) );
	}

	/**
	 * The byte-exact parts of an html fragment, in order: `<pre>` and `<textarea>` content with line endings, `<code>`
	 * content outside them with CRLF read as LF, text after `<plaintext>`; '' when none. Elements are found as the HTML
	 * tokenizer reads real tags ({@see self::next_tag()}); the walk reads each byte a bounded number of times.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	public static function exact( string $html ): string {
		$out     = '';
		$at      = 0;
		$length  = strlen( $html );
		$foreign = [];

		while ( $at < $length ) {
			$tag = self::next_tag( $html, $at );

			if ( null === $tag ) {
				break;
			}

			[ $name, $closer, , $start, $self_closing ] = $tag;
			$at = $start;

			// An exact element counts in foreign content too: its region then
			// holds whatever a browser renders inside it.
			if ( ! self::html_start( $name, $closer, $self_closing, $foreign ) && ( $closer || ! isset( self::EXACT_ELEMENTS[ $name ] ) ) ) {
				continue;
			}

			if ( isset( self::RAW_TEXT_ELEMENTS[ $name ] ) ) {
				$end = self::raw_text_end( $html, $name, $start );
			} elseif ( isset( self::EXACT_ELEMENTS[ $name ] ) ) {
				$end = self::element_end( $html, $name, $start );
			} else {
				continue;
			}

			if ( isset( self::EXACT_ELEMENTS[ $name ] ) ) {
				$body = substr( $html, $start, $end - $start );
				$out .= $name . "\0" . ( 'code' === $name ? str_replace( "\r\n", "\n", $body ) : $body ) . "\0";
			}

			$at = $end;
		}

		return $out;
	}

	/**
	 * Whether a tag is a start tag read as HTML, updating the open foreign roots: `<svg>`/`<math>` opens foreign content,
	 * its end tag closes it, a {@see self::FOREIGN_BREAKOUT} tag closes all. At most {@see BlockScanner::MAX_DEPTH} roots
	 * are kept; a deeper one marks `*` (foreign until a start tag ends it), so end tags match a bounded list.
	 *
	 * @param string             $name         Lowercased tag name.
	 * @param bool               $closer       Whether it is an end tag.
	 * @param bool               $self_closing Whether it is self-closing.
	 * @param array<int, string> $foreign      The open foreign roots, outermost first; set to those after the tag.
	 * @return bool
	 * @phpstan-param list<string> $foreign
	 * @param-out list<string> $foreign
	 */
	private static function html_start( string $name, bool $closer, bool $self_closing, array &$foreign ): bool {
		$root = isset( self::FOREIGN_ELEMENTS[ $name ] );

		if ( $closer ) {
			$open = $root && ! in_array( '*', $foreign, true ) ? array_search( $name, $foreign, true ) : false;

			if ( false !== $open ) {
				$foreign = array_slice( $foreign, 0, (int) $open );
			}

			return false;
		}

		if ( [] !== $foreign && ! isset( self::FOREIGN_BREAKOUT[ $name ] ) ) {
			if ( $root && ! $self_closing && count( $foreign ) < BlockScanner::MAX_DEPTH ) {
				$foreign[] = $name;
			} elseif ( $root && ! $self_closing && '*' !== end( $foreign ) ) {
				$foreign[] = '*';
			}

			return false;
		}

		$foreign = $root && ! $self_closing ? [ $name ] : [];

		return ! $root;
	}

	/**
	 * The next real start or end tag at or after an offset, as the HTML tokenizer reads it: comments, bogus comments and
	 * a `<` that starts no tag are skipped; the tag ends at the first `>` outside quoted values ({@see self::tag_end()}).
	 * Null when no tag follows or a comment or tag never ends (the rest of the fragment is inside it).
	 *
	 * @param string $html Fragment.
	 * @param int    $at   Offset.
	 * @return array{string, bool, int, int, bool}|null The lowercased name, whether it is an end tag, the offset of its `<`, the offset after its `>`, and whether it is self-closing.
	 */
	private static function next_tag( string $html, int $at ): ?array {
		$length = strlen( $html );
		$lt     = strpos( $html, '<', $at );

		for ( ; false !== $lt; $lt = strpos( $html, '<', $at ) ) {
			$next = $html[ $lt + 1 ] ?? '';

			if ( '!' === $next && '<!--' === substr( $html, $lt, 4 ) ) {
				$end = self::comment_end( $html, $lt + 4 );

				if ( null === $end ) {
					return null;
				}

				$at = $end;
				continue;
			}

			$closer = '/' === $next;
			$name   = $lt + ( $closer ? 2 : 1 );

			if ( 1 !== strspn( $html, self::TAG_NAME_START, $name, 1 ) ) {
				if ( '!' === $next || '?' === $next || $closer ) {
					$end = strpos( $html, '>', $lt + 2 );

					if ( false === $end ) {
						return null;
					}

					$at = $end + 1;
					continue;
				}

				$at = $lt + 1;
				continue;
			}

			$size = strcspn( $html, self::TAG_SPACE . '/>', $name );
			$end  = self::tag_end( $html, $name + $size );

			if ( null === $end ) {
				return null;
			}

			return [ strtolower( substr( $html, $name, $size ) ), $closer, $lt, $end[0], $end[1] ];
		}

		return null;
	}

	/**
	 * Where a tag ends, from the byte after its name, by the tokenizer's attribute states: the first `>` outside a quoted
	 * or unquoted value; self-closing when a `/` stands right before it. Null when the tag never ends.
	 *
	 * @param string $html Fragment.
	 * @param int    $i    Offset after the tag name.
	 * @return array{int, bool}|null The offset after the `>` and whether the tag is self-closing.
	 */
	private static function tag_end( string $html, int $i ): ?array {
		$length = strlen( $html );

		while ( true ) {
			$from = $i;
			$i   += strspn( $html, self::TAG_SPACE . '/', $i );

			if ( $i >= $length ) {
				return null;
			}

			if ( '>' === $html[ $i ] ) {
				return [ $i + 1, $i > $from && '/' === $html[ $i - 1 ] ];
			}

			$i += 1 + strcspn( $html, self::TAG_SPACE . '/>=', $i + 1 );
			$i += strspn( $html, self::TAG_SPACE, $i );

			if ( $i >= $length ) {
				return null;
			}

			if ( '=' !== $html[ $i ] ) {
				continue;
			}

			$i += 1 + strspn( $html, self::TAG_SPACE, $i + 1 );
			$q  = $html[ $i ] ?? '';

			if ( '"' === $q || "'" === $q ) {
				$close = strpos( $html, $q, $i + 1 );

				if ( false === $close ) {
					return null;
				}

				$i = $close + 1;
				continue;
			}

			if ( '>' === $q ) {
				return [ $i + 1, false ];
			}

			$i += strcspn( $html, self::TAG_SPACE . '>', $i );

			if ( $i >= $length ) {
				return null;
			}
		}
	}

	/**
	 * Where a comment opened with `<!--` ends: at once for `<!-->` and `<!--->`, else after the first `-->` or `--!>`;
	 * null when it never ends.
	 *
	 * @param string $html Fragment.
	 * @param int    $from Offset after the `<!--`.
	 * @return int|null The offset after the comment.
	 */
	private static function comment_end( string $html, int $from ): ?int {
		if ( '>' === ( $html[ $from ] ?? '' ) ) {
			return $from + 1;
		}

		if ( '->' === substr( $html, $from, 2 ) ) {
			return $from + 2;
		}

		// Each `--` is found once: the first followed by `>` or `!>` ends it.
		for ( $dash = strpos( $html, '--', $from ); false !== $dash; $dash = strpos( $html, '--', $dash + 1 ) ) {
			if ( '>' === ( $html[ $dash + 2 ] ?? '' ) ) {
				return $dash + 3;
			}

			if ( '!>' === substr( $html, $dash + 2, 2 ) ) {
				return $dash + 4;
			}
		}

		return null;
	}

	/**
	 * Where a raw text element's content ends: the fragment's end for `plaintext`, the script data states for `script`
	 * ({@see self::script_end()}), else the first `</name` end tag whatever the text holds.
	 *
	 * @param string $html  Fragment.
	 * @param string $name  Lowercased element name.
	 * @param int    $start Offset after its start tag.
	 * @return int
	 */
	private static function raw_text_end( string $html, string $name, int $start ): int {
		if ( 'plaintext' === $name ) {
			return strlen( $html );
		}

		if ( 'script' === $name ) {
			return self::script_end( $html, $start );
		}

		$close = [];

		return 1 === preg_match( '#</' . $name . '[\t\n\f\r />]#i', $html, $close, PREG_OFFSET_CAPTURE, $start ) ? $close[0][1] : strlen( $html );
	}

	/**
	 * Where a script's content ends, by the tokenizer's script data states: a `</script` end tag in data or an escaped run
	 * (`<!--` to `-->`); inside an escaped run `<script` opens a double-escaped run where `</script` does not end it.
	 *
	 * @param string $html  Fragment.
	 * @param int    $start Offset after the start tag.
	 * @return int
	 */
	private static function script_end( string $html, int $start ): int {
		$length = strlen( $html );
		$i      = $start;
		$state  = 'data';
		$dashes = 0;

		while ( $i < $length ) {
			if ( 'data' === $state ) {
				$lt = strpos( $html, '<', $i );

				if ( false === $lt ) {
					return $length;
				}

				if ( self::script_tag_at( $html, $lt, true ) ) {
					return $lt;
				}

				if ( '<!--' === substr( $html, $lt, 4 ) ) {
					$state  = 'escaped';
					$dashes = 2;
					$i      = $lt + 4;
					continue;
				}

				$i = $lt + 1;
				continue;
			}

			// Escaped and double-escaped runs: the dashes right before a `>` counted; `-->` leaves the run.
			$skip = strcspn( $html, '-<>', $i );

			if ( $skip > 0 ) {
				$dashes = 0;
				$i     += $skip;
			}

			if ( $i >= $length ) {
				return $length;
			}

			$byte = $html[ $i ];

			if ( '-' === $byte ) {
				++$dashes;
				++$i;
				continue;
			}

			if ( '>' === $byte ) {
				if ( $dashes >= 2 ) {
					$state = 'data';
				}

				$dashes = 0;
				++$i;
				continue;
			}

			$dashes = 0;

			if ( 'escaped' === $state && self::script_tag_at( $html, $i, true ) ) {
				return $i;
			}

			if ( 'escaped' === $state && self::script_tag_at( $html, $i, false ) ) {
				$state = 'double';
				$i    += 8;
				continue;
			}

			if ( 'double' === $state && self::script_tag_at( $html, $i, true ) ) {
				$state = 'escaped';
				$i    += 9;
				continue;
			}

			++$i;
		}

		return $length;
	}

	/**
	 * Whether a `<script` start (or, with $end, a `</script` end) followed by whitespace, `/` or `>` stands at an offset.
	 *
	 * @param string $html Fragment.
	 * @param int    $at   Offset of the `<`.
	 * @param bool   $end  Whether an end tag is asked for.
	 * @return bool
	 */
	private static function script_tag_at( string $html, int $at, bool $end ): bool {
		$open = $end ? '</script' : '<script';
		$size = strlen( $open );

		return 0 === substr_compare( $html, $open, $at, $size, true ) && 1 === strspn( $html, self::TAG_SPACE . '/>', $at + $size, 1 );
	}

	/**
	 * Where a `<pre>` or `<code>` content ends: at the real end tag that closes it (nested same-name elements closed
	 * first), raw text elements skipped, foreign content read as exact() reads it; else the fragment's end.
	 *
	 * @param string $html  Fragment.
	 * @param string $name  Lowercased element name.
	 * @param int    $start Offset after its start tag.
	 * @return int
	 */
	private static function element_end( string $html, string $name, int $start ): int {
		$at      = $start;
		$depth   = 0;
		$foreign = [];
		$tag     = self::next_tag( $html, $at );

		for ( ; null !== $tag; $tag = self::next_tag( $html, $at ) ) {
			[ $tag_name, $closer, $lt, $after, $self_closing ] = $tag;
			$at = $after;

			if ( $closer && $name === $tag_name ) {
				if ( 0 === $depth ) {
					return $lt;
				}

				--$depth;
				continue;
			}

			if ( ! self::html_start( $tag_name, $closer, $self_closing, $foreign ) && ( $closer || ! isset( self::EXACT_ELEMENTS[ $tag_name ] ) ) ) {
				continue;
			}

			if ( $name === $tag_name ) {
				++$depth;
			} elseif ( isset( self::RAW_TEXT_ELEMENTS[ $tag_name ] ) ) {
				$at = self::raw_text_end( $html, $tag_name, $after );
			}
		}

		return strlen( $html );
	}

	/**
	 * The byte-exact parts of a block and its subtree, hashed, from its children's: exact() of each own chunk, an
	 * EXACT_BLOCKS chunk without a `<pre>` whole and trimmed. '' when the subtree has none, so such blocks compare equal.
	 *
	 * @param BlockNode          $node     Node.
	 * @param BlockTree          $tree     The node's tree.
	 * @param array<int, string> $children The children's values, in order.
	 * @return string
	 * @phpstan-param list<string> $children
	 */
	public static function exact_of( BlockNode $node, BlockTree $tree, array $children ): string {
		$whole = in_array( (string) $node->name, self::EXACT_BLOCKS, true );
		$parts = [ (string) count( $node->chunks ), (string) count( $children ) ];
		$any   = false;

		foreach ( $node->chunks as $chunk ) {
			$bytes = $tree->slice( $chunk );
			$exact = self::exact( $bytes );

			if ( $whole && '' === $exact ) {
				array_push( $parts, 'block', trim( $bytes ) );
				$any = true;
			} else {
				array_push( $parts, 'exact', $exact );
				$any = $any || '' !== $exact;
			}
		}

		foreach ( $children as $child ) {
			$parts[] = $child;
			$any     = $any || '' !== $child;
		}

		return $any ? StateHash::plain( ...$parts ) : '';
	}

	/**
	 * The byte-exact parts of a block's subtree (exact_of()), bottom-up; depth bounded by BlockScanner::MAX_DEPTH.
	 *
	 * @param BlockNode $node Node.
	 * @param BlockTree $tree The node's tree.
	 * @return string
	 */
	public static function exact_tree( BlockNode $node, BlockTree $tree ): string {
		$children = [];

		foreach ( $node->children as $child ) {
			$children[] = self::exact_tree( $child, $tree );
		}

		return self::exact_of( $node, $tree, $children );
	}

	/**
	 * The canonical JSON of block attributes.
	 *
	 * @param array<mixed>|null $attrs Attributes decoded associatively; null for invalid JSON.
	 * @return string
	 */
	public static function canon_json( ?array $attrs ): string {
		if ( $attrs === null ) {
			return 'null';
		}

		if ( isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ) {
			unset( $attrs['metadata']['ignoredHookedBlocks'] );

			if ( $attrs['metadata'] === [] ) {
				unset( $attrs['metadata'] );
			}
		}

		$json = wp_json_encode( self::sorted( $attrs ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * The canonical form of a block, hashed: its name, canonical JSON, normalised own chunks and its children's canon.
	 *
	 * @param BlockNode $node Node.
	 * @param BlockTree $tree The node's tree.
	 * @return string StateHash::stored(), 64 characters.
	 */
	public static function canon( BlockNode $node, BlockTree $tree ): string {
		$children = [];

		foreach ( $node->children as $child ) {
			$children[] = self::canon( $child, $tree );
		}

		return self::canon_of( $node, $tree, $children );
	}

	/**
	 * The canon of a block from its children's canon, so a bottom-up walk hashes each node once; its first 16 characters
	 * are the anchor a block map stores.
	 *
	 * @param BlockNode          $node     Node.
	 * @param BlockTree          $tree     The node's tree.
	 * @param array<int, string> $children The children's canon, in order.
	 * @return string StateHash::stored() over the name, the canonical JSON, the two counts, the normalised chunks and the children's canon: 64 characters.
	 * @phpstan-param list<string> $children
	 */
	public static function canon_of( BlockNode $node, BlockTree $tree, array $children ): string {
		$parts = [ (string) $node->name, self::canon_json( $node->attrs ), (string) count( $node->chunks ), (string) count( $node->children ) ];

		foreach ( $node->chunks as $chunk ) {
			$parts[] = self::norm( $tree->slice( $chunk ) );
		}

		foreach ( $children as $child ) {
			$parts[] = $child;
		}

		return StateHash::stored( ...$parts );
	}

	/**
	 * The inline-tag skeleton of an html fragment: tag names in order, lowercased, closers with a slash; two translations
	 * with the same markup share it whatever their words and hrefs.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	public static function skeleton( string $html ): string {
		$found = [];

		if ( preg_match_all( self::TAG_PATTERN, $html, $found ) === false ) {
			return '';
		}

		$out = [];

		foreach ( $found[0] as $tag ) {
			$m = [];

			if ( preg_match( self::TAG_NAME_PATTERN, $tag, $m ) === 1 ) {
				$out[] = $m[1] . strtolower( $m[2] );
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * The digit runs and URLs of a unit's text as a sorted multiset, tags stripped (an href is markup) and native digits
	 * read as ASCII (a translation may write "٧" for "7"): numbers and links survive translation.
	 *
	 * @param string $text   Text or HTML.
	 * @param string $format {@see BlockAttributeSource::FORMAT_HTML} or {@see BlockAttributeSource::FORMAT_TEXT}.
	 * @return list<string>
	 */
	public static function figures( string $text, string $format = BlockAttributeSource::FORMAT_HTML ): array {
		if ( $format === BlockAttributeSource::FORMAT_HTML ) {
			$text = preg_replace( self::TAG_PATTERN, '', $text ) ?? $text;
		}

		$found = [];

		preg_match_all( self::FIGURE_PATTERN, self::ascii_digits( self::decode( $text ) ), $found );

		$out = $found[0];

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * A text with every non-ASCII decimal digit replaced by its ASCII digit; invalid UTF-8 keeps its bytes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function ascii_digits( string $text ): string {
		if ( preg_match( '/[\x80-\xFF]/', $text ) !== 1 ) {
			return $text;
		}

		return preg_replace_callback( self::NATIVE_DIGIT_PATTERN, static fn ( array $m ): string => self::digit_value( $m[0] ), $text ) ?? $text;
	}

	/**
	 * The ASCII digit of one decimal digit: its place in its run, read back at most {@see self::DIGIT_RUN_LIMIT}, mod ten.
	 *
	 * @param string $digit One decimal digit outside ASCII, UTF-8 (two to four bytes).
	 * @return string
	 */
	private static function digit_value( string $digit ): string {
		if ( isset( self::$digit_values[ $digit ] ) ) {
			return self::$digit_values[ $digit ];
		}

		$length = strlen( $digit );
		$point  = ord( $digit[0] ) & ( $length === 2 ? 0x1F : ( $length === 3 ? 0x0F : 0x07 ) );

		for ( $i = 1; $i < $length; $i++ ) {
			$point = ( $point << 6 ) | ( ord( $digit[ $i ] ) & 0x3F );
		}

		$place = 0;

		while ( $place < self::DIGIT_RUN_LIMIT && $point - $place > 0 && preg_match( '/^\p{Nd}$/u', html_entity_decode( '&#' . ( $point - $place - 1 ) . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === 1 ) {
			++$place;
		}

		self::$digit_values[ $digit ] = (string) ( $place % 10 );

		return self::$digit_values[ $digit ];
	}

	/**
	 * Entities decoded, U+00A0 a space: on invalid UTF-8 the collapse is ASCII-only, so this is what makes a no-break
	 * space a space.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function decode( string $text ): string {
		return str_replace( "\u{00A0}", ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Every whitespace run one space, trimmed; invalid UTF-8 collapses ASCII whitespace only.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function collapse( string $text ): string {
		$out = preg_replace( '/\s+/u', ' ', $text );

		if ( $out === null ) {
			$out = (string) preg_replace( '/\s+/', ' ', $text );
		}

		return trim( $out );
	}

	/**
	 * An HTML fragment with its text runs decoded and re-escaped and its tags canonicalised. When the tag regex fails the
	 * bytes stay: decoding would give a text that shows "<b>" and a bold tag the same form.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private static function canonical_html( string $html ): string {
		$pieces = preg_split( self::TAG_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $pieces ) ) {
			return $html;
		}

		$out = '';

		foreach ( $pieces as $i => $piece ) {
			// Odd pieces are the tags and comments.
			$out .= ( $i % 2 === 1 ) ? self::canonical_tag( $piece ) : strtr( self::decode( $piece ), self::TEXT_ESCAPES );
		}

		return $out;
	}

	/**
	 * One tag in canonical form: lowercase name, attributes sorted, values decoded and double-quoted, the first of a
	 * repeated name (as the browser takes it), no self-closing slash (an unquoted value keeps its trailing one).
	 *
	 * @param string $tag The tag bytes, `<` to `>`.
	 * @return string
	 */
	private static function canonical_tag( string $tag ): string {
		$m = [];

		if ( str_starts_with( $tag, '<!--' ) || preg_match( '#^<(/?)([a-zA-Z][a-zA-Z0-9:-]*)(.*)>$#s', $tag, $m ) !== 1 ) {
			return $tag;
		}

		$name = strtolower( $m[2] );

		if ( $m[1] === '/' ) {
			return '</' . $name . '>';
		}

		$attributes = [];
		$found      = [];

		preg_match_all( self::ATTRIBUTE_PATTERN, $m[3], $found, PREG_SET_ORDER );

		foreach ( $found as $attribute ) {
			$attr = strtolower( $attribute[1] );

			if ( isset( $attributes[ $attr ] ) ) {
				continue;
			}

			$value = $attribute[4] ?? ( $attribute[3] ?? ( $attribute[2] ?? '' ) );

			$attributes[ $attr ] = strtr( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), self::VALUE_ESCAPES );
		}

		ksort( $attributes, SORT_STRING );

		$out = '<' . $name;

		foreach ( $attributes as $attribute_name => $value ) {
			$out .= ' ' . $attribute_name . '="' . $value . '"';
		}

		return $out . '>';
	}

	/**
	 * Keys sorted recursively; lists keep their order.
	 *
	 * @param array<mixed> $value Value.
	 * @return array<mixed>
	 */
	private static function sorted( array $value ): array {
		if ( array_values( $value ) !== $value ) { // Not array_is_list(): Plugin Check flags it as WordPress 6.5.
			uksort( $value, self::compare_keys( ... ) );
		}

		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::sorted( $item );
			}
		}

		return $value;
	}

	/**
	 * A total order on array keys: integer keys numerically, then string keys bytewise. PHP's default reads "01", 1 and
	 * "1.0" as equal; a string compare puts 10 before 2 and breaks an eleven-entry object that equals a list.
	 *
	 * @param int|string $a Key.
	 * @param int|string $b Key.
	 * @return int
	 */
	private static function compare_keys( int|string $a, int|string $b ): int {
		if ( is_int( $a ) && is_int( $b ) ) {
			return $a <=> $b;
		}

		if ( is_int( $a ) || is_int( $b ) ) {
			return is_int( $a ) ? -1 : 1;
		}

		return strcmp( $a, $b );
	}
}
