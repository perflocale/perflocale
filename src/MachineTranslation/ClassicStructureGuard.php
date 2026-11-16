<?php
/**
 * HTML-structure check for machine-translated classic content.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares the raw provider answer for content without block delimiters with its source, so a cut-off answer is not saved.
 *
 * Inline elements are not ordered (word order moves them) and length is not compared (it varies by language).
 */
final class ClassicStructureGuard {

	/**
	 * Comments, script/style elements, declarations and tags; a `>` inside a quoted attribute does not end a tag.
	 * In a tag only a `<` that cannot start one (a quoted `A < B`), so a stray `<` costs one short scan.
	 */
	public const TOKEN = '/<!--.*?-->|<(script|style)\b(?:[^<>"\']++|"(?:[^"<]++|<(?![a-z\/!?]))*+"|\'(?:[^\'<]++|<(?![a-z\/!?]))*+\')*+>.*?<\/\1\s*>|<(\/?)([a-z][a-z0-9:-]*+)((?:[^<>"\']++|"(?:[^"<]++|<(?![a-z\/!?]))*+"|\'(?:[^\'<]++|<(?![a-z\/!?]))*+\')*+)>|<!(?!--)[^<>]*+>|<\?[^<>]*+>/is';

	/**
	 * Elements whose order a translation keeps, space-delimited.
	 */
	public const STRUCTURAL = ' address article aside blockquote caption center dd details dialog div dl dt fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 header hgroup hr li main nav ol p pre section summary table tbody td tfoot th thead tr ul ';

	/**
	 * Elements without a closing tag, space-delimited.
	 */
	public const VOID = ' area base br col embed hr img input link meta param source track wbr ';

	/**
	 * Elements whose end tag HTML may omit, space-delimited: closed by the end of an element around them.
	 */
	public const OPTIONAL_END = ' caption colgroup dd dt li optgroup option p rb rp rt rtc td tfoot th thead tr ';

	/**
	 * Start tag => open elements at the top it closes (HTML's implied end tags), space-delimited.
	 */
	public const CLOSED_BY = [
		'li'       => ' li ',
		'dt'       => ' dt dd ',
		'dd'       => ' dt dd ',
		'tr'       => ' td th tr ',
		'td'       => ' td th ',
		'th'       => ' td th ',
		'thead'    => ' td th tr thead tfoot ',
		'tfoot'    => ' td th tr thead tfoot ',
		'option'   => ' option ',
		'optgroup' => ' option optgroup ',
	];

	/**
	 * Why a raw machine translation of classic content may not be saved.
	 *
	 * @param string $source Source content as sent.
	 * @param string $result Raw provider answer, placeholders restored.
	 * @return string '' when the structure is kept; otherwise the reason, one sentence.
	 */
	public static function mismatch( string $source, string $result ): string {
		$detail = self::difference( $source, $result );

		return '' === $detail ? '' : sprintf(
			/* translators: %s: what differs, for example "an HTML tag is cut off". */
			__( 'The machine translation was not saved because it does not keep the HTML structure of the source: %s.', 'perflocale' ),
			$detail
		);
	}

	/**
	 * The first difference between the two structures.
	 *
	 * @param string $source Source content.
	 * @param string $result Result content.
	 * @return string '' when there is none.
	 */
	private static function difference( string $source, string $result ): string {
		$src = self::read( $source );
		$out = self::read( $result );

		if ( null === $src || null === $out ) {
			return __( 'its HTML could not be read', 'perflocale' );
		}

		if ( $out['cut'] > $src['cut'] ) {
			return __( 'an HTML tag is cut off', 'perflocale' );
		}

		$src_count = count( $src['order'] );
		$out_count = count( $out['order'] );

		for ( $i = 0, $shared = min( $src_count, $out_count ); $i < $shared; $i++ ) {
			if ( $src['order'][ $i ] !== $out['order'][ $i ] ) {
				return sprintf(
					/* translators: 1: position of the element, 2: number of elements in the source, 3: tag name in the source, 4: tag name in the result. */
					__( 'HTML element %1$d of %2$d should be <%3$s> but is <%4$s>', 'perflocale' ),
					$i + 1,
					$src_count,
					$src['order'][ $i ],
					$out['order'][ $i ]
				);
			}
		}

		if ( $src_count !== $out_count ) {
			return sprintf(
				/* translators: 1: number of structural elements in the result, 2: number in the source. */
				__( 'the result has %1$d structural HTML elements and the source %2$d', 'perflocale' ),
				$out_count,
				$src_count
			);
		}

		$open = $out['open'] + array_fill_keys( array_keys( $src['open'] ), 0 );
		ksort( $open );

		// A provider may close what the source left open, never the reverse.
		foreach ( $open as $name => $n ) {
			$was = $src['open'][ $name ] ?? 0;

			if ( $n > max( $was, 0 ) ) {
				/* translators: %s: tag name, for example p. */
				return sprintf( __( 'a <%s> element is not closed', 'perflocale' ), $name );
			}

			if ( $n < min( $was, 0 ) ) {
				/* translators: %s: tag name, for example p. */
				return sprintf( __( 'a </%s> tag has no matching start', 'perflocale' ), $name );
			}
		}

		return '';
	}

	/**
	 * Structure of an HTML text.
	 *
	 * @param string $html Content.
	 * @return array{cut: int, order: string[], open: array<string, int>, left: list<string>}|null `cut`: tag starts never
	 *         closed; `order`: structural opening tags; `open`: opened minus closed per tag; `left`: elements open at the
	 *         end. Null when the expression fails.
	 */
	private static function read( string $html ): ?array {
		// TOKEN's lazy comment, script and style patterns scan to the end once per opener that never closes.
		if ( BlockStructureGuard::unclosed_raw( $html ) > BlockStructureGuard::UNCLOSED_MAX ) {
			return null;
		}

		$gaps = preg_replace( self::TOKEN, "\n", $html );

		if ( null === $gaps || false === preg_match_all( self::TOKEN, $html, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}

		$out = [
			'cut'   => (int) preg_match_all( '/<[a-z\/!?]/i', $gaps ),
			'order' => [],
			'open'  => [],
			'left'  => [],
		];

		// Open elements, to close the optional-end ones HTML closes implicitly.
		$stack = [];

		foreach ( $matches as $m ) {
			$name = strtolower( (string) ( $m[3] ?? '' ) );

			// The HTML parser adds a tbody a table leaves out.
			if ( '' === $name || 'tbody' === $name ) {
				continue;
			}

			if ( '/' === $m[2] ) {
				$out['open'][ $name ] = ( $out['open'][ $name ] ?? 0 ) - 1;
				$at                   = array_search( $name, $stack, true );

				while ( false !== $at && [] !== $stack ) {
					$top = (string) array_pop( $stack );

					if ( $top === $name ) {
						break;
					}

					self::closed_implicitly( $out, $top );
				}

				continue;
			}

			$closes = self::CLOSED_BY[ $name ] ?? ( str_contains( self::STRUCTURAL, " $name " ) ? ' p ' : '' );

			while ( [] !== $stack && str_contains( $closes, ' ' . end( $stack ) . ' ' ) ) {
				self::closed_implicitly( $out, (string) array_pop( $stack ) );
			}

			if ( str_contains( self::STRUCTURAL, " $name " ) ) {
				$out['order'][] = $name;
			}

			if ( ! str_contains( self::VOID, " $name " ) && ! str_ends_with( rtrim( (string) $m[4] ), '/' ) ) {
				$out['open'][ $name ] = ( $out['open'][ $name ] ?? 0 ) + 1;
				$stack[]              = $name;
			}
		}

		$out['left'] = $stack;

		return $out;
	}

	/**
	 * Whether an HTML text opens and closes its own elements: no tag cut off, no stray end tag, and nothing left open but
	 * elements whose end tag HTML may omit.
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	public static function balanced( string $html ): bool {
		$read = self::read( $html );

		if ( null === $read || $read['cut'] > 0 ) {
			return false;
		}

		$open = $read['open'];

		foreach ( $read['left'] as $name ) {
			if ( str_contains( self::OPTIONAL_END, " $name " ) ) {
				--$open[ $name ];
			}
		}

		return [] === array_filter( $open );
	}

	/**
	 * Attributes whose values a provider translates: their names stay in the skeleton, their values do not.
	 */
	private const TRANSLATED_ATTRIBUTES = ' alt title aria-label placeholder ';

	/**
	 * An HTML text's markup in order: tags with every attribute name and value as written (those of
	 * TRANSLATED_ATTRIBUTES without the value), script/style elements and declarations whole, and the number of tag
	 * starts that never close; text and comments left out. One pass, linear in the length.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function skeleton( string $html ): string {
		$out    = [];
		$cut    = 0;
		$length = strlen( $html );
		$at     = strpos( $html, '<' );

		while ( false !== $at ) {
			$next = $html[ $at + 1 ] ?? '';

			if ( str_starts_with( substr( $html, $at, 4 ), '<!--' ) ) {
				$end = strpos( $html, '-->', $at + 4 );

				// Unclosed: the rest is that comment.
				if ( false === $end ) {
					break;
				}

				$at = strpos( $html, '<', $end + 3 );
				continue;
			}

			if ( 1 === preg_match( '/\G<(\/?)([a-z][^\s\/>]*+)((?:[^>"\'<]++|"[^"]*+"|\'[^\']*+\')*+)>/Ai', $html, $m, 0, $at ) ) {
				$name  = $m[2];
				$out[] = '<' . $m[1] . $name . self::attributes( $m[3] ) . '>';
				$after = $at + strlen( $m[0] );
				$raw   = '' === $m[1] && 1 === preg_match( '/^(?:script|style)$/i', $name );

				if ( $raw ) {
					$close = stripos( $html, '</' . $name, $after );

					if ( false === $close ) {
						$out[] = substr( $html, $after );
						break;
					}

					$out[] = substr( $html, $after, $close - $after );
					$after = $close;
				}

				$at = strpos( $html, '<', $after );
				continue;
			}

			if ( '!' === $next || '?' === $next ) {
				$end = strpos( $html, '>', $at + 2 );

				if ( false !== $end ) {
					$out[] = substr( $html, $at, $end + 1 - $at );
					$at    = strpos( $html, '<', $end + 1 );
					continue;
				}
			}

			// A tag start that does not close.
			if ( 1 === preg_match( '/[a-z\/!?]/i', $next ) ) {
				++$cut;
			}

			$at = $at + 1 < $length ? strpos( $html, '<', $at + 1 ) : false;
		}

		return implode( "\n", $out ) . "\ncut:" . $cut;
	}

	/**
	 * A tag's attributes in order, values as written, without the values of TRANSLATED_ATTRIBUTES.
	 *
	 * @param string $text The tag's text after its name.
	 * @return string
	 */
	private static function attributes( string $text ): string {
		if ( '' === trim( $text, " \t\n\r\f/" ) ) {
			return '';
		}

		preg_match_all( '/([^\s"\'>\/=]++)(?:\s*+=\s*+("[^"]*+"|\'[^\']*+\'|[^\s"\'=<>`]++))?/', $text, $m, PREG_SET_ORDER );

		$out = '';

		foreach ( $m as $a ) {
			$value = $a[2] ?? '';
			$value = '' !== $value && ( '"' === $value[0] || "'" === $value[0] ) ? substr( $value, 1, -1 ) : $value;
			$out  .= ' ' . $a[1] . ( str_contains( self::TRANSLATED_ATTRIBUTES, ' ' . strtolower( $a[1] ) . ' ' ) ? '' : '="' . $value . '"' );
		}

		return $out;
	}

	/**
	 * Count an open element HTML closes without its end tag as closed; any other element stays open.
	 *
	 * @param array{cut: int, order: string[], open: array<string, int>, left: list<string>} $out  Structure read so far.
	 * @param string                                                                         $name Element.
	 * @return void
	 */
	private static function closed_implicitly( array &$out, string $name ): void {
		if ( str_contains( self::OPTIONAL_END, " $name " ) ) {
			$out['open'][ $name ] = ( $out['open'][ $name ] ?? 0 ) - 1;
		}
	}
}
