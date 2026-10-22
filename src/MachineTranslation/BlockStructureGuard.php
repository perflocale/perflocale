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
 * Compares the block structure of a machine translation with its source
 * before the translation is saved.
 *
 * A provider receives block content whole, block delimiter comments included.
 * When it changes or cuts a delimiter, the block is lost on save: a raw `<`
 * in the attribute JSON makes wp_kses() escape the opener to `&lt;!-- wp:`, a
 * raw newline makes the JSON undecodable so the block is saved without its
 * attributes, and a cut-off opener is left behind as text. The check therefore
 * runs on the result as it would be saved (after sanitize_mt_html()).
 *
 * Delimiters are read with the expression WP_Block_Parser::next_token() uses,
 * so the list holds exactly the delimiters parse_blocks() reads, inner blocks
 * included. Comparing delimiters rather than the parsed tree also catches a
 * missing closer, which parse_blocks() fills in silently. A result passes when
 *
 * - it has the source's delimiters in the same order: the same kind (start,
 *   end or self-closing) and the same block name at every position;
 * - every delimiter whose attribute JSON decodes to a non-empty object in the
 *   source carries attribute JSON that decodes to a non-empty object;
 * - the text between delimiters, inside blocks or outside them, holds no
 *   more block-delimiter text (`<!-- wp:` or `&lt;!-- wp:`, closers included)
 *   than the source's does. wp_kses() closes a block whose closer was cut off,
 *   so a cut-off closer survives only as such text inside the block.
 *
 * Content without block delimiters is not checked. The check is pure PHP: one
 * regular-expression pass over each text.
 */
final class BlockStructureGuard {

	/**
	 * Block delimiter grammar, as in WP_Block_Parser::next_token().
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Block-delimiter text that is not a delimiter: raw, or escaped by
	 * wp_kses() or an entity encoder.
	 */
	private const STRAY = '/(?:<|&lt;|&#0*60;|&#x0*3c;)!--\s*\/?wp:/i';

	/**
	 * Why a machine translation may not be saved over block content.
	 *
	 * @param string $source Source content (the post as stored).
	 * @param string $result Translated content as it would be saved.
	 * @return string '' when the result keeps the source's block structure or
	 *                the source has no blocks; otherwise the reason, one
	 *                sentence for the job log, the REST response and WP-CLI.
	 */
	public static function mismatch( string $source, string $result ): string {
		if ( ! str_contains( $source, '<!-- wp:' ) ) {
			return '';
		}

		$detail = self::difference( $source, $result );

		return '' === $detail ? '' : self::reason( $detail );
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
	 * Damaged block markup in a stored content, for a read-only scan.
	 *
	 * Counts four kinds: `escaped` (block-delimiter text escaped by wp_kses()
	 * or an entity encoder, `&lt;!-- wp:`), `text` (raw block-delimiter text
	 * that is not a delimiter, such as a cut-off opener), `unbalanced` (an end
	 * with no matching start, or a start never ended) and `attributes` (blocks
	 * that lost comment attributes their source block has; only counted when
	 * $source is given). `first` is the earliest finding, `at` the earliest
	 * of each kind found.
	 *
	 * Lost attributes: the blocks of both texts are paired in order when their
	 * names match one to one. A key the source block has and the translation
	 * block lacks is lost unless the block editor itself leaves it out of the
	 * comment. The editor's serializer (getCommentAttributes() in
	 *
	 * @wordpress/blocks) writes only attributes its block type defines, never
	 * one with a `source` (it lives in the HTML) or the role `local`, and never
	 * a value equal to the schema default; core's PHP serialize_block_attributes()
	 * writes every key it is given. So for a block type in $schemas a key counts
	 * as lost when the schema defines it, gives it no source and no local role,
	 * and the source value is not its default. A block type without a schema
	 * (registered in JavaScript only) counts only when every attribute is gone,
	 * which is what wp_kses() leaves of attribute JSON that no longer decodes.
	 *
	 * Pure: the result depends on the arguments only.
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
	 * Blocks of a content that lost comment attributes of their source block
	 * (rule in damage()).
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
	 * Start and self-closing delimiters of a content with their decoded
	 * attributes, in order.
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
	 * `kind` is start, end or void; `name` carries the core/ namespace when
	 * the delimiter omits it; `attrs` is whether the attribute JSON decodes
	 * to a non-empty object; `start` and `end` are byte offsets.
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
