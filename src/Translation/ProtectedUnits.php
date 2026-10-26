<?php
/**
 * Which "Do not translate" blocks of a content may keep their markup on save.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\MachineTranslation\BlockStructureGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which "Do not translate" blocks of a saved content keep their markup.
 *
 * Only under rules 1-4 (count, real blocks, placement, balance) and an exact match
 * of the whole serialized block; any doubt keeps nothing. Rules: see
 * docs-ai/11-code-rationale.md, "ProtectedUnits: when a block keeps its markup".
 */
final class ProtectedUnits {

	/**
	 * Elements without a closing tag.
	 */
	private const VOID = [ 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' ];

	/**
	 * Elements the Tag Processor reads whole up to their closing tag (script
	 * data, RCDATA and RAWTEXT).
	 */
	private const ATOMIC = [ 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'XMP', 'IFRAME', 'NOEMBED', 'NOFRAMES' ];

	/**
	 * Open elements under which a block is never kept: inert content and
	 * foreign content.
	 */
	private const INERT = [ 'TEMPLATE', 'NOSCRIPT', 'SVG', 'MATH' ];

	/**
	 * Blocks open around a block must be fewer than this; deeper content
	 * keeps nothing.
	 */
	private const MAX_DEPTH = 64;

	/**
	 * Most bytes of a block's parent chain; a longer chain keeps nothing.
	 */
	private const MAX_CHAIN_BYTES = 4096;

	/**
	 * The units of a source's stored content: its marked blocks that are real
	 * blocks there (rule 2) with balanced HTML (rule 4), each with its parent
	 * block chain and HTML context.
	 *
	 * @param string $content Stored content.
	 * @return array<int, array{bytes: string, chain: string, context: string}>
	 */
	public static function of_source( string $content ): array {
		$marked  = [];
		$lengths = [];

		foreach ( BlockSkipFilter::protected_units( $content ) as $unit ) {
			if ( self::balanced( $unit['bytes'] ) ) {
				$key                                 = $unit['chain'] . "\x1F" . $unit['bytes'];
				$marked[ $key ]                      = ( $marked[ $key ] ?? 0 ) + 1;
				$lengths[ strlen( $unit['bytes'] ) ] = true;
			}
		}

		$found = [] === $marked ? null : self::blocks( $content );

		if ( null === $found ) {
			return [];
		}

		[ $blocks, $contexts ] = $found;

		$units = [];
		$until = -1;

		foreach ( $blocks as $b ) {
			if ( $b['start'] < $until || ! $b['eligible'] ) {
				continue;
			}

			$bytes = null;
			$chain = null;

			if ( isset( $lengths[ $b['end'] - $b['start'] ] ) ) {
				$bytes = substr( $content, $b['start'], $b['end'] - $b['start'] );
				$chain = self::chain( $blocks, $b['parent'] );
			}

			// A marked block stored in another form than core's serializer
			// writes (attribute JSON with \u escapes, other spacing) is the
			// unit in serialized form, the form a translation holds it in.
			if ( ( null === $bytes || empty( $marked[ $chain . "\x1F" . $bytes ] ) ) && BlockSkipFilter::may_have_marker( substr( $content, $b['start'], $b['open_end'] - $b['start'] ) ) ) {
				$bytes   = self::serialized( substr( $content, $b['start'], $b['end'] - $b['start'] ) );
				$chain ??= self::chain( $blocks, $b['parent'] );
			}

			if ( null === $bytes || null === $chain || empty( $marked[ $chain . "\x1F" . $bytes ] ) ) {
				continue;
			}

			--$marked[ $chain . "\x1F" . $bytes ];
			$until   = $b['end'];
			$units[] = [
				'bytes'   => $bytes,
				'chain'   => $chain,
				'context' => BlockCommentLocator::context( $contexts, $b['context'] ),
			];
		}

		return $units;
	}

	/**
	 * A block's text as core's serializer writes it.
	 *
	 * @param string $block One block's text.
	 * @return string
	 */
	private static function serialized( string $block ): string {
		foreach ( parse_blocks( $block ) as $parsed ) {
			if ( is_string( $parsed['blockName'] ) ) {
				return serialize_block( $parsed );
			}
		}

		return '';
	}

	/**
	 * Replace each block of a content that may keep its markup (rules 1-3,
	 * against $units) with a fresh placeholder, in one pass.
	 *
	 * @param string                                                           $content Content (unslashed).
	 * @param array<int, array{bytes: string, chain: string, context: string}> $units   Units it may keep.
	 * @param callable(): string                                               $token   Makes a fresh placeholder.
	 * @return array{0: string, 1: array<string, array{bytes: string, chain: string, context: string}>} [ content with placeholders, placeholder => unit ].
	 */
	public static function swap( string $content, array $units, callable $token ): array {
		return self::splice( $content, self::spans( $content, $units ), $token );
	}

	/**
	 * The blocks of a content that may keep their markup (rules 1-3, against
	 * $units), outermost first in document order, each available unit used
	 * once (rule 1).
	 *
	 * @param string                                                           $content Content (unslashed).
	 * @param array<int, array{bytes: string, chain: string, context: string}> $units   Units it may keep.
	 * @return array<int, array{start: int, end: int, bytes: string, chain: string, context: string}>
	 */
	public static function spans( string $content, array $units ): array {
		$allowed = [];
		$lengths = [];

		foreach ( $units as $unit ) {
			$key                                 = self::key( $unit['chain'], $unit['context'], $unit['bytes'] );
			$allowed[ $key ]                     = ( $allowed[ $key ] ?? 0 ) + 1;
			$lengths[ strlen( $unit['bytes'] ) ] = true;
		}

		$found = [] === $allowed ? null : self::blocks( $content );

		if ( null === $found ) {
			return [];
		}

		[ $blocks, $contexts ] = $found;

		$chosen = [];
		$until  = -1;
		$left   = count( $units );
		$parent = [ -2, '' ];
		$around = [ -1, '' ];

		foreach ( $blocks as $b ) {
			if ( 0 === $left ) {
				break;
			}

			// Only a block as long as a unit can be one: the chain, context
			// and bytes are read for those alone.
			if ( $b['start'] < $until || ! $b['eligible'] || ! isset( $lengths[ $b['end'] - $b['start'] ] ) ) {
				continue;
			}

			// Siblings share their parent and context: the last of each is kept.
			if ( $parent[0] !== $b['parent'] ) {
				$parent = [ $b['parent'], self::chain( $blocks, $b['parent'] ) ];
			}

			if ( $around[0] !== $b['context'] ) {
				$around = [ $b['context'], BlockCommentLocator::context( $contexts, $b['context'] ) ];
			}

			$bytes = substr( $content, $b['start'], $b['end'] - $b['start'] );
			$key   = self::key( $parent[1], $around[1], $bytes );

			if ( empty( $allowed[ $key ] ) ) {
				continue;
			}

			--$allowed[ $key ];
			--$left;
			$until    = $b['end'];
			$chosen[] = [
				'start'   => $b['start'],
				'end'     => $b['end'],
				'bytes'   => $bytes,
				'chain'   => $parent[1],
				'context' => $around[1],
			];
		}

		return $chosen;
	}

	/**
	 * Sanitize a translation's content, its blocks identical to the source's units passed through as they are.
	 *
	 * For routes that sanitize before wp_update_post(): the save rule (ProtectedBlockSave) verifies those bytes again.
	 *
	 * @param string                   $html      Content (unslashed).
	 * @param int                      $source_id Source post.
	 * @param callable(string): string $sanitize  Sanitizer for the rest.
	 * @return string
	 */
	public static function sanitize_around( string $html, int $source_id, callable $sanitize ): string {
		$source           = BlockSkipFilter::may_have_marker( $html ) ? get_post_field( 'post_content', $source_id, 'raw' ) : '';
		$units            = is_string( $source ) && '' !== $source ? self::of_source( $source ) : [];
		[ $masked, $map ] = self::swap( $html, $units, static fn(): string => '<!-- perflocale-hold-' . wp_generate_uuid4() . ' -->' );
		$clean            = (string) $sanitize( $masked );
		$at               = 0;

		// Each placeholder once and in order, else nothing passes through.
		foreach ( array_keys( $map ) as $placeholder ) {
			$pos = strpos( $clean, (string) $placeholder );

			if ( false === $pos || $pos < $at || 1 !== substr_count( $clean, (string) $placeholder ) ) {
				return (string) $sanitize( $html );
			}

			$at = $pos;
		}

		return [] === $map ? $clean : strtr( $clean, array_map( static fn( array $unit ): string => $unit['bytes'], $map ) );
	}

	/**
	 * Build the content with each span replaced by a fresh placeholder, in one pass.
	 *
	 * @param string                                                                                 $content Content.
	 * @param array<int, array{start: int, end: int, bytes: string, chain: string, context: string}> $spans   Non-overlapping spans in document order.
	 * @param callable(): string                                                                     $token   Makes a fresh placeholder.
	 * @return array{0: string, 1: array<string, array{bytes: string, chain: string, context: string}>}
	 */
	public static function splice( string $content, array $spans, callable $token ): array {
		$parts = [];
		$map   = [];
		$at    = 0;

		foreach ( $spans as $s ) {
			$placeholder = (string) $token();
			$parts[]     = substr( $content, $at, $s['start'] - $at );
			$parts[]     = $placeholder;
			$at          = $s['end'];

			$map[ $placeholder ] = [
				'bytes'   => $s['bytes'],
				'chain'   => $s['chain'],
				'context' => $s['context'],
			];
		}

		$parts[] = substr( $content, $at );

		return [ implode( '', $parts ), $map ];
	}

	/**
	 * Whether a unit's HTML is balanced (rule 4).
	 *
	 * @param string $html Serialized block.
	 * @return bool
	 */
	public static function balanced( string $html ): bool {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return false;
		}

		try {
			return self::balanced_tokens( new \WP_HTML_Tag_Processor( $html ) );
		} catch ( \Error $e ) {
			// A Tag Processor without next_token() (WordPress before 6.5).
			return false;
		}
	}

	/**
	 * Walk the tokens of a block's HTML and tell whether it is balanced.
	 *
	 * @param \WP_HTML_Tag_Processor $p Processor over the block's HTML.
	 * @return bool
	 */
	private static function balanced_tokens( \WP_HTML_Tag_Processor $p ): bool {
		$open    = [];
		$foreign = 0;

		while ( $p->next_token() ) {
			$type = $p->get_token_type();

			if ( '#text' === $type || '#comment' === $type ) {
				continue;
			}

			if ( '#tag' !== $type ) {
				return false;
			}

			$tag = (string) $p->get_tag();

			if ( $p->is_tag_closer() ) {
				if ( [] === $open || array_pop( $open ) !== $tag ) {
					return false;
				}

				if ( 'SVG' === $tag || 'MATH' === $tag ) {
					--$foreign;
				}

				continue;
			}

			// Atomic elements arrive whole, closer included; one without its
			// closer leaves the processor at incomplete input.
			if ( in_array( $tag, self::VOID, true ) || in_array( $tag, self::ATOMIC, true ) || ( $foreign > 0 && $p->has_self_closing_flag() ) ) {
				continue;
			}

			if ( 'SVG' === $tag || 'MATH' === $tag ) {
				if ( $p->has_self_closing_flag() ) {
					continue;
				}

				++$foreign;
			}

			$open[] = $tag;
		}

		return [] === $open && ! $p->paused_at_incomplete_token();
	}

	/**
	 * Match key of a unit.
	 *
	 * @param string $chain   Parent block chain.
	 * @param string $context HTML context.
	 * @param string $bytes   Serialized block.
	 * @return string
	 */
	public static function key( string $chain, string $context, string $bytes ): string {
		return $chain . "\x1F" . $context . "\x1F" . $bytes;
	}

	/**
	 * The blocks of a content in document order (spans, parent, context id, rule 2 eligibility) and the contexts.
	 *
	 * Null when the delimiters do not pair or disagree with parse_blocks(), past
	 * self::MAX_DEPTH or self::MAX_CHAIN_BYTES, or when the HTML cannot be read.
	 * Blocks hold integers only, so memory stays linear in the content.
	 *
	 * @param string $content Content.
	 * @return array{0: array<int, array{name: string, start: int, open_end: int, end: int, parent: int, context: int, eligible: bool}>, 1: array<int, array{0: int, 1: string}>}|null
	 */
	private static function blocks( string $content ): ?array {
		$tokens = BlockStructureGuard::delimiters( $content );

		if ( null === $tokens || [] === $tokens || ! function_exists( 'parse_blocks' ) || ! class_exists( 'WP_HTML_Processor' ) ) {
			return null;
		}

		$blocks = [];
		$stack  = [];
		$wanted = [];
		$depths = [];
		$names  = [];

		foreach ( $tokens as $t ) {
			$wanted[ $t['start'] ] = true;

			if ( 'end' === $t['kind'] ) {
				$open = array_pop( $stack );

				if ( null === $open || $blocks[ $open['index'] ]['name'] !== $t['name'] ) {
					return null;
				}

				$blocks[ $open['index'] ]['end']    = $t['end'];
				$blocks[ $open['index'] ]['closer'] = $t['start'];

				continue;
			}

			$depth = count( $stack );

			if ( $depth >= self::MAX_DEPTH ) {
				return null;
			}

			$top = 0 === $depth ? null : $stack[ $depth - 1 ];

			// Bytes of the parent chain: the parent names joined with ' > '.
			if ( null !== $top && $top['chain'] > self::MAX_CHAIN_BYTES ) {
				return null;
			}

			$depths[] = $depth;
			$names[]  = $t['name'];
			$blocks[] = [
				'name'     => $t['name'],
				'start'    => $t['start'],
				'open_end' => $t['end'],
				'end'      => 'void' === $t['kind'] ? $t['end'] : -1,
				'closer'   => $t['start'],
				'parent'   => null === $top ? -1 : $top['index'],
				'html'     => null !== $top && $top['html'],
			];

			if ( 'start' === $t['kind'] ) {
				$stack[] = [
					'index' => count( $blocks ) - 1,
					'chain' => ( null === $top ? 0 : $top['chain'] + 3 ) + strlen( $t['name'] ),
					'html'  => ( null !== $top && $top['html'] ) || 'core/html' === $t['name'],
				];
			}
		}

		if ( [] !== $stack ) {
			return null;
		}

		$parsed = [ [], [] ];
		self::walk( parse_blocks( $content ), 0, $parsed );

		if ( $depths !== $parsed[0] || $names !== $parsed[1] ) {
			return null;
		}

		$comments = BlockCommentLocator::comments( $content, $wanted );

		if ( null === $comments ) {
			return null;
		}

		[ $at, $contexts ] = $comments;

		// Contexts under template, noscript, svg or math: a parent comes
		// before its children.
		$inert = [ false ];

		foreach ( $contexts as $id => [ $up, $name ] ) {
			if ( $id > 0 ) {
				$inert[ $id ] = $inert[ $up ] || in_array( $name, self::INERT, true );
			}
		}

		$out = [];

		foreach ( $blocks as $b ) {
			$context = $at[ $b['start'] ] ?? null;

			$out[] = [
				'name'     => $b['name'],
				'start'    => $b['start'],
				'open_end' => $b['open_end'],
				'end'      => $b['end'],
				'parent'   => $b['parent'],
				'context'  => $context ?? 0,
				'eligible' => null !== $context
					&& ( $at[ $b['closer'] ] ?? null ) === $context
					&& ! $inert[ $context ]
					&& ! $b['html'],
			];
		}

		return [ $out, $contexts ];
	}

	/**
	 * The parent block chain of a block of self::blocks(): the names of the
	 * blocks above it joined with ' > ', outermost first.
	 *
	 * @param array<int, array{name: string, parent: int}> $blocks Blocks.
	 * @param int                                          $up     Index of the parent block, -1 for none.
	 * @return string
	 */
	private static function chain( array $blocks, int $up ): string {
		$names = [];

		for ( $at = $up; $at >= 0 && isset( $blocks[ $at ] ); $at = $blocks[ $at ]['parent'] ) {
			$names[] = $blocks[ $at ]['name'];
		}

		return implode( ' > ', array_reverse( $names ) );
	}

	/**
	 * The parser's blocks in document order: their depths and names.
	 *
	 * @param array<mixed>                                     $blocks Parsed blocks.
	 * @param int                                              $depth  Depth of these blocks.
	 * @param array{0: array<int, int>, 1: array<int, string>} $out    By reference - filled.
	 * @return void
	 */
	private static function walk( array $blocks, int $depth, array &$out ): void {
		foreach ( $blocks as $block ) {
			$name = is_array( $block ) ? ( $block['blockName'] ?? null ) : null;

			if ( ! is_string( $name ) ) {
				continue;
			}

			$out[0][] = $depth;
			$out[1][] = $name;
			$inner    = $block['innerBlocks'] ?? [];

			if ( is_array( $inner ) && [] !== $inner ) {
				self::walk( $inner, $depth + 1, $out );
			}
		}
	}
}
