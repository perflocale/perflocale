<?php
/**
 * Content ReSync: what the HTML rules removed from a text.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the save chain's HTML rules (KSES for a user without `unfiltered_html`) removed something meaningful: a tag,
 * attribute, style declaration, URL scheme, visible text, or such markup in a block attribute. Judged as a browser
 * tokenizes and reads URLs, never on raw bytes; a rewrite a browser reads the same is no removal.
 */
final class MarkupRemoval {

	/** The attributes core's KSES checks for protocols (wp_kses_uri_attributes()). */
	private const URI_ATTRIBUTES = [ 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns' ];

	/** Elements whose content is text up to their closing tag. */
	private const RAW_TEXT = [ 'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext' ];

	/** Whitespace between a tag's name and its attributes, as the HTML tokenizer reads it. */
	private const SPACE = " \t\n\f\r";

	/** How deep markup inside block attributes is followed. */
	private const MAX_DEPTH = 4;

	/** The characters numeric references 128 to 159 stand for in a browser (windows-1252); the other codes stay. */
	private const WINDOWS_1252 = [
		0x80 => 0x20AC,
		0x82 => 0x201A,
		0x83 => 0x0192,
		0x84 => 0x201E,
		0x85 => 0x2026,
		0x86 => 0x2020,
		0x87 => 0x2021,
		0x88 => 0x02C6,
		0x89 => 0x2030,
		0x8A => 0x0160,
		0x8B => 0x2039,
		0x8C => 0x0152,
		0x8E => 0x017D,
		0x91 => 0x2018,
		0x92 => 0x2019,
		0x93 => 0x201C,
		0x94 => 0x201D,
		0x95 => 0x2022,
		0x96 => 0x2013,
		0x97 => 0x2014,
		0x98 => 0x02DC,
		0x99 => 0x2122,
		0x9A => 0x0161,
		0x9B => 0x203A,
		0x9C => 0x0153,
		0x9E => 0x017E,
		0x9F => 0x0178,
	];

	/**
	 * Whether the stored text lost a tag, attribute, CSS declaration, URL scheme, visible text, or such block markup.
	 *
	 * @param string $before The text as given.
	 * @param string $after  The text as stored.
	 * @return bool
	 */
	public static function found( string $before, string $after ): bool {
		if ( $before === $after ) {
			return false;
		}

		$uri = function_exists( 'wp_kses_uri_attributes' ) ? array_map( 'strtolower', array_map( 'strval', wp_kses_uri_attributes() ) ) : self::URI_ATTRIBUTES;

		return self::lost( $before, $after, array_fill_keys( $uri, true ), 0 );
	}

	/**
	 * The judgment over one text.
	 *
	 * @param string              $before The text as given.
	 * @param string              $after  The text as stored.
	 * @param array<string, bool> $uri    The attributes checked for a scheme.
	 * @param int                 $depth  How deep inside block attributes.
	 * @return bool
	 */
	private static function lost( string $before, string $after, array $uri, int $depth ): bool {
		if ( $before === $after ) {
			return false;
		}

		$was = self::scan( $before );
		$now = self::scan( $after );
		$is  = $now['tags'];
		$n   = count( $is );
		$j   = 0;

		// Every tag as given keeps its place in the stored text (the save never adds an opening tag before a kept one), so
		// a tag without a match further on was removed.
		foreach ( $was['tags'] as $tag ) {
			while ( $j < $n && $is[ $j ]['name'] !== $tag['name'] ) {
				++$j;
			}

			if ( $j === $n || self::attributes_lost( $tag['attrs'], $is[ $j ]['attrs'], $uri ) ) {
				return true;
			}

			++$j;
		}

		if ( ! self::text_kept( $was['text'], $now['text'] ) ) {
			return true;
		}

		return $depth < self::MAX_DEPTH && self::block_attributes_lost( $before, $after, $uri, $depth );
	}

	/**
	 * Whether the visible text as given is a subsequence of the stored one: the save only deletes or re-spells text.
	 *
	 * @param string $was The visible text as given.
	 * @param string $is  The visible text as stored.
	 * @return bool
	 */
	private static function text_kept( string $was, string $is ): bool {
		$length = strlen( $was );
		$j      = 0;

		for ( $i = 0; $i < $length; $i++ ) {
			$at = strpos( $is, $was[ $i ], $j );

			if ( false === $at ) {
				return false;
			}

			$j = $at + 1;
		}

		return true;
	}

	/**
	 * Whether a tag lost an attribute, a CSS property or a URL scheme.
	 *
	 * @param array<string, string> $was The attributes as given (raw values).
	 * @param array<string, string> $is  The attributes as stored (raw values).
	 * @param array<string, bool>   $uri The attributes checked for a scheme.
	 * @return bool
	 */
	private static function attributes_lost( array $was, array $is, array $uri ): bool {
		foreach ( $was as $name => $value ) {
			if ( ! array_key_exists( $name, $is ) ) {
				return true;
			}

			if ( 'style' === $name && self::css_lost( $value, $is[ $name ] ) ) {
				return true;
			}

			if ( isset( $uri[ $name ] ) ) {
				$scheme = self::scheme( $value );

				if ( '' !== $scheme && self::scheme( $is[ $name ] ) !== $scheme ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a style attribute lost a declaration: a property declared
	 * fewer times than before (a repeated property counts each time).
	 *
	 * @param string $was The attribute's raw value as given.
	 * @param string $is  The attribute's raw value as stored.
	 * @return bool
	 */
	private static function css_lost( string $was, string $is ): bool {
		$kept = array_count_values( self::css_properties( $is ) );

		foreach ( array_count_values( self::css_properties( $was ) ) as $property => $count ) {
			if ( $count > ( $kept[ $property ] ?? 0 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The CSS property names of a style attribute, lower case, one entry per declaration.
	 *
	 * @param string $css The attribute's raw value.
	 * @return list<string>
	 */
	private static function css_properties( string $css ): array {
		$out = [];

		foreach ( explode( ';', self::decode( $css ) ) as $declaration ) {
			$colon = strpos( $declaration, ':' );

			if ( false !== $colon ) {
				$out[] = strtolower( trim( substr( $declaration, 0, $colon ) ) );
			}
		}

		return $out;
	}

	/**
	 * The scheme a browser reads from a URL attribute's raw value, lower case; empty for a URL without one. Shared by
	 * every scheme check (WriteTripwire, HeldMarkup).
	 *
	 * @param string $raw The attribute's raw value.
	 * @return string
	 */
	public static function scheme( string $raw ): string {
		$url = str_replace( [ "\t", "\n", "\r" ], '', self::decode( $raw ) );
		$url = ltrim( $url, "\x00..\x20" );

		return 1 === preg_match( '/^([A-Za-z][A-Za-z0-9+.\-]*):/', $url, $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * A value with its character references decoded as a browser does: numeric ones with or without the semicolon (128
	 * to 159 as windows-1252), named ones with it.
	 *
	 * @param string $raw The raw value.
	 * @return string
	 */
	private static function decode( string $raw ): string {
		if ( ! str_contains( $raw, '&' ) ) {
			return $raw;
		}

		return (string) preg_replace_callback(
			'/&(?:#([0-9]+);?|#[xX]([0-9a-fA-F]+);?|([A-Za-z][A-Za-z0-9]*;))/',
			static function ( array $m ): string {
				if ( null !== $m[3] ) {
					return html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}

				$hex  = null !== $m[2] ? ltrim( $m[2], '0' ) : '';
				// Over 6 digits is above U+10FFFF; hexdec() would overflow (a notice on PHP 8.6).
				$code = null !== $m[2] ? ( strlen( $hex ) > 6 ? -1 : hexdec( $hex ) ) : (int) ltrim( $m[1] ?? '', '0' );

				if ( ! is_int( $code ) || $code <= 0 || $code > 0x10FFFF || ( $code >= 0xD800 && $code <= 0xDFFF ) ) {
					return "\u{FFFD}";
				}

				return \PerfLocale\Strings\StringScanner::codepoint_to_utf8( self::WINDOWS_1252[ $code ] ?? $code );
			},
			$raw,
			flags: PREG_UNMATCHED_AS_NULL
		);
	}

	/**
	 * Whether markup inside a block attribute was removed: openers matched in order, each markup string judged as a text;
	 * a removed opener or attribute key is a removal.
	 *
	 * @param string              $before The text as given.
	 * @param string              $after  The text as stored.
	 * @param array<string, bool> $uri    The attributes checked for a scheme.
	 * @param int                 $depth  How deep inside block attributes.
	 * @return bool
	 */
	private static function block_attributes_lost( string $before, string $after, array $uri, int $depth ): bool {
		if ( ! str_contains( $before, '<!--' ) ) {
			return false;
		}

		$was = self::block_attributes( $before );

		if ( [] === $was ) {
			return false;
		}

		$is = self::block_attributes( $after );

		if ( count( $is ) < count( $was ) ) {
			return true;
		}

		foreach ( $was as $i => $attrs ) {
			if ( self::values_lost( $attrs, $is[ $i ] ?? null, $uri, $depth + 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The attributes of each block opener and void block, in order (null when absent or not a JSON object).
	 *
	 * @param string $html The text.
	 * @return list<array<mixed>|null>
	 */
	private static function block_attributes( string $html ): array {
		if ( ! preg_match_all( BlockScanner::DELIMITER_PATTERN, $html, $matches, PREG_SET_ORDER ) ) {
			return [];
		}

		$out = [];

		foreach ( $matches as $match ) {
			if ( '' !== $match['closer'] ) {
				continue;
			}

			$json  = $match['attrs'] ?? '';
			$attrs = '' === $json ? null : json_decode( $json, true );
			$out[] = is_array( $attrs ) ? $attrs : null;
		}

		return $out;
	}

	/**
	 * Whether a block attribute value lost a key or markup.
	 *
	 * @param mixed               $was   The value as given.
	 * @param mixed               $is    The value as stored.
	 * @param array<string, bool> $uri   The attributes checked for a scheme.
	 * @param int                 $depth How deep inside block attributes.
	 * @return bool
	 */
	private static function values_lost( mixed $was, mixed $is, array $uri, int $depth ): bool {
		if ( is_array( $was ) ) {
			foreach ( $was as $key => $value ) {
				if ( ! is_array( $is ) || ! array_key_exists( $key, $is ) || self::values_lost( $value, $is[ $key ], $uri, $depth ) ) {
					return true;
				}
			}

			return false;
		}

		if ( is_string( $was ) && str_contains( $was, '<' ) ) {
			return ! is_string( $is ) || self::lost( $was, $is, $uri, $depth );
		}

		return false;
	}

	/**
	 * A text as the HTML tokenizer reads it: the start tags (lowercase names, raw values, first of a repeated name) and the
	 * visible text outside tags and comments (raw-text content and a `<` opening no tag included), references decoded,
	 * whitespace and control characters dropped.
	 *
	 * @param string $html The text.
	 * @return array{tags: list<array{name: string, attrs: array<string, string>}>, text: string}
	 */
	private static function scan( string $html ): array {
		$tags = [];
		$text = '';
		$len  = strlen( $html );
		$i    = 0;

		while ( $i < $len ) {
			$lt = strpos( $html, '<', $i );

			if ( false === $lt ) {
				$text .= substr( $html, $i );
				break;
			}

			$next = $html[ $lt + 1 ] ?? '';
			$end  = '/' === $next;

			if ( ! str_starts_with( substr( $html, $lt, 4 ), '<!--' ) && '!' !== $next && '?' !== $next && ! \PerfLocale\Helper::is_ascii_alpha( $html[ $lt + ( $end ? 2 : 1 ) ] ?? '' ) ) {
				$text .= substr( $html, $i, $lt + 1 - $i );
				$i     = $lt + 1;
				continue;
			}

			$text .= substr( $html, $i, $lt - $i );

			if ( str_starts_with( substr( $html, $lt, 4 ), '<!--' ) ) {
				$i = self::comment_end( $html, $lt + 4 );
				continue;
			}

			if ( '!' === $next || '?' === $next ) {
				$gt = strpos( $html, '>', $lt + 2 );
				$i  = false === $gt ? $len : $gt + 1;
				continue;
			}

			$tag = self::tag( $html, $lt + ( $end ? 2 : 1 ) );

			if ( null === $tag ) {
				break;
			}

			$i = $tag['end'];

			if ( $end ) {
				continue;
			}

			$tags[] = [
				'name'  => $tag['name'],
				'attrs' => $tag['attrs'],
			];

			if ( in_array( $tag['name'], self::RAW_TEXT, true ) ) {
				$stop  = self::raw_text_end( $html, $i, $tag['name'] );
				$text .= substr( $html, $i, $stop - $i );
				$i     = $stop;
			}
		}

		return [
			'tags' => $tags,
			'text' => (string) preg_replace( '/[\x00-\x20\x7F]+/', '', self::decode( $text ) ),
		];
	}

	/**
	 * Where a comment that opens before $from ends: after the first `--`
	 * followed by `>` or `!>`, or right away for `<!-->` and `<!--->`; the
	 * end of the text when it never closes.
	 *
	 * @param string $html The text.
	 * @param int    $from The offset after `<!--`.
	 * @return int
	 */
	private static function comment_end( string $html, int $from ): int {
		if ( '>' === ( $html[ $from ] ?? '' ) ) {
			return $from + 1;
		}

		if ( '->' === substr( $html, $from, 2 ) ) {
			return $from + 2;
		}

		$at = strpos( $html, '--', $from );

		while ( false !== $at ) {
			if ( '>' === ( $html[ $at + 2 ] ?? '' ) ) {
				return $at + 3;
			}

			if ( '!>' === substr( $html, $at + 2, 2 ) ) {
				return $at + 4;
			}

			$at = strpos( $html, '--', $at + 1 );
		}

		return strlen( $html );
	}

	/**
	 * Where a raw-text element's content ends: at its closing tag (any case, then whitespace, `/` or `>`), else text end.
	 *
	 * @param string $html The text.
	 * @param int    $from The offset after the start tag.
	 * @param string $name The element's name, lower case.
	 * @return int
	 */
	private static function raw_text_end( string $html, int $from, string $name ): int {
		if ( 'plaintext' === $name ) {
			return strlen( $html );
		}

		if ( 1 === preg_match( '#</' . $name . '[\s/>]#i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			return $m[0][1];
		}

		return strlen( $html );
	}

	/**
	 * One tag from its name on: the name lower case, the attributes, and
	 * the offset after its `>`; null when the text ends inside the tag.
	 *
	 * @param string $html  The text.
	 * @param int    $start The offset of the name's first letter.
	 * @return array{name: string, attrs: array<string, string>, end: int}|null
	 */
	private static function tag( string $html, int $start ): ?array {
		$len   = strlen( $html );
		$i     = $start + strcspn( $html, self::SPACE . '/>', $start );
		$name  = strtolower( substr( $html, $start, $i - $start ) );
		$attrs = [];

		while ( true ) {
			$i += strspn( $html, self::SPACE . '/', $i );

			if ( $i >= $len ) {
				return null;
			}

			if ( '>' === $html[ $i ] ) {
				return [
					'name'  => $name,
					'attrs' => $attrs,
					'end'   => $i + 1,
				];
			}

			// An attribute name: its first character may be `=`.
			$from = $i;
			$i    = $i + 1 + strcspn( $html, self::SPACE . '/>=', $i + 1 );
			$attr = strtolower( substr( $html, $from, $i - $from ) );
			$i   += strspn( $html, self::SPACE, $i );
			$val  = '';

			if ( '=' === ( $html[ $i ] ?? '' ) ) {
				++$i;
				$i    += strspn( $html, self::SPACE, $i );
				$quote = $html[ $i ] ?? '';

				if ( '"' === $quote || "'" === $quote ) {
					$close = strpos( $html, $quote, $i + 1 );

					if ( false === $close ) {
						return null;
					}

					$val = substr( $html, $i + 1, $close - $i - 1 );
					$i   = $close + 1;
				} else {
					$stop = strcspn( $html, self::SPACE . '>', $i );
					$val  = substr( $html, $i, $stop );
					$i   += $stop;
				}
			}

			if ( ! array_key_exists( $attr, $attrs ) ) {
				$attrs[ $attr ] = $val;
			}
		}
	}
}
