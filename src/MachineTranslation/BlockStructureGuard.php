<?php
/**
 * Block-structure check for machine-translated content.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses a machine translation whose block structure differs from its source's, checked as it would be saved.
 *
 * Compares delimiters, not the parsed tree, which fills in a missing closer silently: see
 * docs-ai/11-code-rationale.md, "BlockStructureGuard: what a result must keep".
 */
final class BlockStructureGuard {

	/**
	 * Block delimiter grammar of WP_Block_Parser::next_token(): keep it identical, so the list is what parse_blocks() reads.
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Block-delimiter text that is not a delimiter: raw, or escaped by wp_kses() or an entity encoder.
	 */
	private const STRAY = '/(?:<|&lt;|&#0*60;|&#x0*3c;)!--\s*\/?wp:/i';

	/**
	 * Why a machine translation may not be saved over block content.
	 *
	 * @param string $source Source content (the post as stored).
	 * @param string $result Translated content as it would be saved.
	 * @return string '' when the structure is kept or the source has no blocks; else one sentence for log, REST and CLI.
	 */
	public static function mismatch( string $source, string $result ): string {
		if ( ! str_contains( $source, '<!-- wp:' ) ) {
			return '';
		}

		$detail = self::difference( $source, $result );

		return '' === $detail ? '' : self::reason( $detail );
	}

	/**
	 * Why a provider answer may not be saved: text between two block delimiters came back without any.
	 *
	 * Script and style bodies are no text. Checked on the raw answer: the sanitizer empties some markup on purpose.
	 *
	 * @param string $sent   Content as sent, delimiters in place.
	 * @param string $answer The answer with its delimiters put back.
	 * @return string '' when each stretch with text kept text, or the delimiters differ (mismatch() reports that).
	 */
	public static function lost_text( string $sent, string $answer ): string {
		$src = self::delimiters( $sent );
		$out = self::delimiters( $answer );

		if ( null === $src || null === $out || [] === $src || count( $src ) !== count( $out ) ) {
			return '';
		}

		$count = count( $src );
		$stack = [];

		// Stretch $g runs from the end of delimiter $g - 1 to the start of delimiter $g (the end of the content for the last).
		for ( $g = 0; $g <= $count; $g++ ) {
			if ( $g < $count && ( $src[ $g ]['kind'] !== $out[ $g ]['kind'] || $src[ $g ]['name'] !== $out[ $g ]['name'] ) ) {
				return '';
			}

			$a = 0 === $g ? 0 : $src[ $g - 1 ]['end'];
			$b = 0 === $g ? 0 : $out[ $g - 1 ]['end'];

			if ( self::has_text( $g < $count ? substr( $sent, $a, $src[ $g ]['start'] - $a ) : substr( $sent, $a ) )
				&& ! self::has_text( $g < $count ? substr( $answer, $b, $out[ $g ]['start'] - $b ) : substr( $answer, $b ) ) ) {
				return sprintf(
					/* translators: 1: block name, 2: position of the nearest block delimiter. */
					__( 'the text of block %1$s (at block delimiter %2$d) came back empty', 'perflocale' ),
					[] === $stack ? 'core/freeform' : (string) end( $stack ),
					min( $g + 1, $count )
				);
			}

			if ( $g < $count && 'start' === $src[ $g ]['kind'] ) {
				$stack[] = $src[ $g ]['name'];
			} elseif ( $g < $count && 'end' === $src[ $g ]['kind'] ) {
				array_pop( $stack );
			}
		}

		return '';
	}

	/**
	 * Whether HTML shows a letter or digit (comments, tags, script and style bodies aside).
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	public static function has_text( string $html ): bool {
		if ( '' === trim( $html ) ) {
			return false;
		}

		$html = (string) preg_replace( '#<(script|style)\b[^>]*+>.*?</\1\s*>#is', '', $html );

		return 1 === preg_match( '/[\p{L}\p{N}]/u', html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A text check, not output; script and style are removed above.
	}

	/**
	 * The sentence a refused machine translation reports.
	 *
	 * @param string $detail What differs.
	 * @return string
	 */
	public static function reason( string $detail ): string {
		return sprintf(
			/* translators: %s: what differs, for example "the result has 5 block delimiters and the source 8". */
			__( 'The machine translation was not saved because it does not keep the block structure of the source: %s.', 'perflocale' ),
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
		$src = self::delimiters( $source );
		$out = self::delimiters( $result );

		if ( null === $src || null === $out ) {
			return __( 'its block delimiters could not be read', 'perflocale' );
		}

		$src_count = count( $src );
		$out_count = count( $out );
		$shared    = min( $src_count, $out_count );

		for ( $i = 0; $i < $shared; $i++ ) {
			if ( $src[ $i ]['kind'] !== $out[ $i ]['kind'] || $src[ $i ]['name'] !== $out[ $i ]['name'] ) {
				return sprintf(
					/* translators: 1: position of the delimiter, 2: number of delimiters in the source, 3: what the source has there, 4: what the result has there. */
					__( 'block delimiter %1$d of %2$d should be %3$s but is %4$s', 'perflocale' ),
					$i + 1,
					$src_count,
					self::describe( $src[ $i ] ),
					self::describe( $out[ $i ] )
				);
			}
		}

		if ( $src_count !== $out_count ) {
			return sprintf(
				/* translators: 1: number of block delimiters in the result, 2: number in the source. */
				__( 'the result has %1$d block delimiters and the source %2$d', 'perflocale' ),
				$out_count,
				$src_count
			);
		}

		for ( $i = 0; $i < $src_count; $i++ ) {
			if ( $src[ $i ]['attrs'] && ! $out[ $i ]['attrs'] ) {
				return sprintf(
					/* translators: 1: block name, 2: position of its delimiter. */
					__( 'the attributes of block %1$s (block delimiter %2$d) are missing or not valid JSON', 'perflocale' ),
					$src[ $i ]['name'],
					$i + 1
				);
			}
		}

		$stray = self::stray_count( $result, $out );

		if ( $stray > 0 && $stray > self::stray_count( $source, $src ) ) {
			return __( 'block markup was left as text', 'perflocale' );
		}

		return '';
	}

	/**
	 * Damaged block markup in a stored content, for a read-only scan (pure).
	 *
	 * Kinds `escaped`, `text` (delimiter text that is no delimiter), `unbalanced`, `attributes` (with $source only, see
	 * docs-ai/11-code-rationale.md, "BlockStructureGuard: lost attributes"); `first` is the earliest hit, `at` per kind.
	 *
	 * @param string                              $html    Content.
	 * @param string|null                         $source  The source content (null: no attribute check).
	 * @param array<string, array<string, mixed>> $schemas Block name => attribute schema (WP_Block_Type::$attributes).
	 * @return array{escaped: int, text: int, unbalanced: int, attributes: int, first: array{offset: int, kind: string, keys?: string[]}|null, at: array<string, array{offset: int, kind: string, keys?: string[]}>}
	 */
	public static function damage( string $html, ?string $source = null, array $schemas = [] ): array {
		$out = [
			'escaped'    => 0,
			'text'       => 0,
			'unbalanced' => 0,
			'attributes' => 0,
			'first'      => null,
			'at'         => [],
		];

		if ( ! str_contains( $html, 'wp:' ) ) {
			return $out;
		}

		$hits = [];

		if ( null !== $source ) {
			foreach ( self::lost_attributes( $html, $source, $schemas ) as [ $offset, $keys ] ) {
				$hits[] = [ $offset, 'attributes', $keys ];
			}
		}

		if ( preg_match_all( '/(?:&lt;|&#0*60;|&#x0*3c;)!--\s*\/?wp:/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$hits[] = [ (int) $hit[1], 'escaped' ];
			}
		}

		$tokens = self::delimiters( $html ) ?? [];
		$starts = [];

		foreach ( $tokens as $t ) {
			$starts[ $t['start'] ] = true;
		}

		if ( preg_match_all( '/<!--\s*\/?wp:/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				if ( ! isset( $starts[ (int) $hit[1] ] ) ) {
					$hits[] = [ (int) $hit[1], 'text' ];
				}
			}
		}

		$stack = [];

		foreach ( $tokens as $t ) {
			if ( 'start' === $t['kind'] ) {
				$stack[] = $t;
			} elseif ( 'end' === $t['kind'] ) {
				$open = array_pop( $stack );

				if ( null === $open || $open['name'] !== $t['name'] ) {
					$hits[] = [ $t['start'], 'unbalanced' ];
				}
			}
		}

		foreach ( $stack as $open ) {
			$hits[] = [ $open['start'], 'unbalanced' ];
		}

		foreach ( $hits as $hit ) {
			[ $offset, $kind ] = $hit;
			++$out[ $kind ];

			$spot = [
				'offset' => $offset,
				'kind'   => $kind,
			];

			if ( isset( $hit[2] ) ) {
				$spot['keys'] = $hit[2];
			}

			if ( ! isset( $out['at'][ $kind ] ) || $offset < $out['at'][ $kind ]['offset'] ) {
				$out['at'][ $kind ] = $spot;
			}

			if ( null === $out['first'] || $offset < $out['first']['offset'] ) {
				$out['first'] = $spot;
			}
		}

		return $out;
	}

	/**
	 * Blocks that lost comment attributes of their source block (rule in damage()).
	 *
	 * @param string                              $html    Content.
	 * @param string                              $source  Source content.
	 * @param array<string, array<string, mixed>> $schemas Block name => attribute schema.
	 * @return array<int, array{0: int, 1: string[]}> [ offset of the block's start, lost keys ].
	 */
	private static function lost_attributes( string $html, string $source, array $schemas ): array {
		$mine   = self::attribute_sets( $html );
		$theirs = self::attribute_sets( $source );

		if ( null === $mine || null === $theirs || array_column( $mine, 0 ) !== array_column( $theirs, 0 ) ) {
			return [];
		}

		$out = [];

		foreach ( $theirs as $i => [ $name, $source_attrs ] ) {
			$attrs = $mine[ $i ][1];

			if ( [] === $source_attrs ) {
				continue;
			}

			$lost = array_keys( array_diff_key( $source_attrs, $attrs ) );

			if ( ! isset( $schemas[ $name ] ) ) {
				// No schema: only a block that lost every attribute.
				if ( [] === $attrs ) {
					$out[] = [ $mine[ $i ][2], array_map( 'strval', $lost ) ];
				}
				continue;
			}

			$counted = [];

			foreach ( $lost as $key ) {
				$def = $schemas[ $name ][ $key ] ?? null;

				if (
					! is_array( $def )
					|| isset( $def['source'] )
					|| 'local' === ( $def['role'] ?? $def['__experimentalRole'] ?? '' )
					|| ( array_key_exists( 'default', $def ) && $def['default'] === $source_attrs[ $key ] )
				) {
					continue;
				}

				$counted[] = (string) $key;
			}

			if ( [] !== $counted ) {
				$out[] = [ $mine[ $i ][2], $counted ];
			}
		}

		return $out;
	}

	/**
	 * Start and self-closing delimiters with their decoded attributes, in order.
	 *
	 * @param string $html Content.
	 * @return array<int, array{0: string, 1: array<mixed>, 2: int}>|null [ block name, attributes ([] when none or undecodable), offset ].
	 */
	private static function attribute_sets( string $html ): ?array {
		if ( false === preg_match_all( self::DELIMITER, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}

		$out = [];

		foreach ( $matches as $m ) {
			if ( null !== $m['closer'][0] ) {
				continue;
			}

			$attrs = null === $m['attrs'][0] ? [] : json_decode( (string) $m['attrs'][0], true );
			$out[] = [ ( $m['namespace'][0] ?? 'core/' ) . $m['name'][0], is_array( $attrs ) ? $attrs : [], (int) $m[0][1] ];
		}

		return $out;
	}

	/**
	 * Read the block delimiters of a text, in order.
	 *
	 * `name` gets core/ when the delimiter omits it; `attrs`: the JSON decodes to a non-empty object; byte offsets.
	 *
	 * @param string $html Content.
	 * @return array<int, array{kind: string, name: string, attrs: bool, start: int, end: int}>|null
	 *         Null when the expression fails (backtracking limit).
	 */
	public static function delimiters( string $html ): ?array {
		$found = preg_match_all( self::DELIMITER, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL );

		if ( false === $found ) {
			return null;
		}

		$tokens = [];

		foreach ( $matches as $m ) {
			$kind = 'start';

			if ( null !== $m['void'][0] ) {
				$kind = 'void';
			} elseif ( null !== $m['closer'][0] ) {
				$kind = 'end';
			}

			$attrs = false;

			if ( 'end' !== $kind && null !== $m['attrs'][0] ) {
				$decoded = json_decode( (string) $m['attrs'][0], true );
				$attrs   = is_array( $decoded ) && [] !== $decoded;
			}

			$tokens[] = [
				'kind'  => $kind,
				'name'  => ( $m['namespace'][0] ?? 'core/' ) . $m['name'][0],
				'attrs' => $attrs,
				'start' => (int) $m[0][1],
				'end'   => (int) $m[0][1] + strlen( (string) $m[0][0] ),
			];
		}

		return $tokens;
	}

	/**
	 * Number of stray delimiter texts between the delimiters.
	 *
	 * @param string                                                                           $html   Content.
	 * @param array<int, array{kind: string, name: string, attrs: bool, start: int, end: int}> $tokens Its delimiters.
	 * @return int
	 */
	private static function stray_count( string $html, array $tokens ): int {
		$offset = 0;
		$count  = 0;

		foreach ( $tokens as $t ) {
			$count += (int) preg_match_all( self::STRAY, substr( $html, $offset, $t['start'] - $offset ) );
			$offset = $t['end'];
		}

		return $count + (int) preg_match_all( self::STRAY, substr( $html, $offset ) );
	}

	/**
	 * A delimiter in words.
	 *
	 * @param array{kind: string, name: string, attrs: bool, start: int, end: int} $token Delimiter.
	 * @return string
	 */
	private static function describe( array $token ): string {
		if ( 'void' === $token['kind'] ) {
			/* translators: %s: block name, for example core/separator. */
			return sprintf( __( 'self-closing block %s', 'perflocale' ), $token['name'] );
		}

		if ( 'end' === $token['kind'] ) {
			/* translators: %s: block name, for example core/group. */
			return sprintf( __( 'the end of block %s', 'perflocale' ), $token['name'] );
		}

		/* translators: %s: block name, for example core/paragraph. */
		return sprintf( __( 'the start of block %s', 'perflocale' ), $token['name'] );
	}
}
