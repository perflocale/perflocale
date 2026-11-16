<?php
/**
 * Long classic content in pieces cut only between whole elements.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pieces of at most a size, cut between sibling elements or at a blank line; the whitespace at a cut stays here, never
 * sent. An element too large is cut between its children and each piece is wrapped in bare tags of the elements around
 * the cut (`<table><tbody>`), which join() strips. Loaded by a literal require only when the bytes pass a piece size.
 *
 * @since 1.0.8
 */
final class HtmlPieces {

	/**
	 * Elements that end an inline run, space-delimited (wpautop's block list, main, script, style, textarea).
	 */
	private const BLOCK = ' address article aside blockquote caption col colgroup dd details div dl dt fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 header hgroup hr legend li main map math menu nav ol p pre script section style summary table tbody td textarea tfoot th thead tr ul ';

	/**
	 * Elements cut between their children when too large, space-delimited; any other element is never cut inside.
	 */
	private const CONTAINER = ' article aside blockquote dd details div dl fieldset figure footer form header li main menu nav ol section table tbody td tfoot th thead tr ul ';

	/**
	 * Containers where direct text is moved out by the HTML parser: one with such text is never cut inside.
	 */
	private const TABLE_PARTS = ' table tbody thead tfoot tr ';

	/**
	 * Deepest container cut inside.
	 */
	private const MAX_DEPTH = 32;

	/**
	 * Whitespace trimmed at a cut (ASCII only: one byte, one character).
	 */
	private const EDGE = " \t\n\r\0\x0B";

	/**
	 * Source.
	 *
	 * @var string
	 */
	private string $html;

	/**
	 * Nodes: name, start, end, content start, content end, uncuttable, table rows without tbody.
	 *
	 * @var array<int, array{name: string, start: int, end: int, in: int, out: int, whole: bool, rows: bool}>
	 */
	private array $nodes = [];

	/**
	 * Children of each node, in order (apart from the nodes: appending there would copy a node per child).
	 *
	 * @var array<int, list<int>>
	 */
	private array $kids = [];

	/**
	 * Character offsets at token boundaries, by byte offset, for size measures.
	 *
	 * @var list<int>
	 */
	private array $anchor_bytes = [];

	/**
	 * Character offsets at the bytes of $anchor_bytes.
	 *
	 * @var list<int>
	 */
	private array $anchor_chars = [];

	/**
	 * Last measured byte and its character offset: most measures move forward, so counting goes on from there.
	 *
	 * @var array{0: int, 1: int}
	 */
	private array $cursor = [ 0, 0 ];

	/**
	 * Parts in order: a kept string, or a piece [ bare start tags, source, bare end tags, their expressions, outer tag ].
	 *
	 * @var list<string|array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 */
	private array $parts = [];

	/**
	 * Parts before this content's first piece and in all, for refusals when it is one run of a larger content.
	 *
	 * @var array{0: int, 1: int}|null
	 */
	private ?array $numbering = null;

	/**
	 * Whether an inline run is cut at a blank line (off inside one block's HTML: never inside an inline run there).
	 *
	 * @var bool
	 */
	private bool $blank_cuts = true;

	/**
	 * The parts texts() sends, read once.
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>|null
	 */
	private ?array $sent = null;

	/**
	 * Constructor.
	 *
	 * @param string $html Content.
	 */
	private function __construct( string $html ) {
		$this->html = $html;
	}

	/**
	 * Cut a content into pieces of at most $limit characters.
	 *
	 * @param string $html       Content as sent (placeholders in).
	 * @param int    $limit      Characters per piece.
	 * @param bool   $blank_cuts Cut an inline run at a blank line.
	 * @return self|null Null when it stays whole: under the size, one piece, HTML read differently by a parser (a bare
	 *                   `<` in text, CDATA), or an element without the end tag HTML requires (a piece would hold half of it).
	 */
	public static function split( string $html, int $limit, bool $blank_cuts = true ): ?self {
		if ( $limit < 1 || \PerfLocale\Util\Utf8::length( $html ) <= $limit || str_contains( $html, '<![CDATA[' ) ) {
			return null;
		}

		$self             = new self( $html );
		$self->blank_cuts = $blank_cuts;

		if ( ! $self->read() ) {
			return null;
		}

		$atoms = [];
		$self->atoms( 0, [], $limit, $atoms );

		return $self->pack( $atoms, $limit ) ? $self : null;
	}

	/**
	 * The pieces to send: those with text, wrapped.
	 *
	 * @return list<string>
	 */
	public function texts(): array {
		$out = [];

		foreach ( $this->parts as $part ) {
			if ( is_array( $part ) ) {
				$out[] = $part[0] . $part[1] . $part[2];
			}
		}

		return $out;
	}

	/**
	 * Name this content's pieces in refusals as parts $before + k of $total of a larger content.
	 *
	 * @param int $before Parts before its first piece.
	 * @param int $total  Parts in all.
	 * @return void
	 */
	public function number_as( int $before, int $total ): void {
		$this->numbering = [ $before, $total ];
	}

	/**
	 * Pieces texts() sends.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->sent() );
	}

	/**
	 * Why the answer to piece $k (0-based, as texts()) is not saved; '' when it passes.
	 *
	 * @param int    $k      Piece.
	 * @param string $answer Its answer.
	 * @return string
	 */
	public function check( int $k, string $answer ): string {
		$pieces = $this->sent();

		return isset( $pieces[ $k ] ) ? $this->unwrapped( $pieces[ $k ], $k + 1, count( $pieces ), $answer )[1] : '';
	}

	/**
	 * The content from the answers to texts(), in order; kept parts as they were.
	 *
	 * @param string[] $answers Answers.
	 * @return string
	 * @throws NotSavedException When an answer is empty or lost a wrapper tag.
	 */
	public function join( array $answers ): string {
		$out   = '';
		$k     = 0;
		$count = count( $answers );

		foreach ( $this->parts as $part ) {
			if ( ! is_array( $part ) ) {
				$out .= $part;
				continue;
			}

			[ $answer, $refusal ] = $this->unwrapped( $part, $k + 1, $count, (string) ( $answers[ $k ] ?? '' ) );
			++$k;

			if ( '' !== $refusal ) {
				throw new NotSavedException( esc_html( $refusal ) );
			}

			$out .= $answer;
		}

		return $out;
	}

	/**
	 * The parts texts() sends.
	 *
	 * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 */
	private function sent(): array {
		$this->sent ??= array_values( array_filter( $this->parts, 'is_array' ) );

		return $this->sent;
	}

	/**
	 * A piece's answer without the bare tags it was sent with, and why it is not saved ('' when it passes).
	 *
	 * @param array<int, string> $part   The piece.
	 * @param int                $k      Piece, 1-based.
	 * @param int                $count  Pieces.
	 * @param string             $answer Its answer.
	 * @return array{0: string, 1: string}
	 */
	private function unwrapped( array $part, int $k, int $count, string $answer ): array {
		[ $k, $count ] = null === $this->numbering ? [ $k, $count ] : [ $this->numbering[0] + $k, $this->numbering[1] ];
		$answer        = trim( $answer, self::EDGE );

		if ( '' === $answer ) {
			return [ '', self::refusal( $k, $count, '' ) ];
		}

		// Only the tags this piece was sent with; the close side starts at a tag and ends at the end, so a whitespace run
		// costs one pass.
		foreach ( [
			3 => '/^%s\s*+/i',
			4 => '/(?=<)%s$/i',
		] as $at => $form ) {
			if ( '' === $part[ $at ] ) {
				continue;
			}

			$answer = (string) preg_replace( sprintf( $form, $part[ $at ] ), '', $answer, 1, $done );

			if ( 1 !== $done ) {
				return [ '', self::refusal( $k, $count, $part[5] ) ];
			}
		}

		return [ rtrim( $answer, self::EDGE ), '' ];
	}

	/**
	 * Why a joined answer is not saved.
	 *
	 * @param int    $k     Piece, 1-based.
	 * @param int    $count Pieces.
	 * @param string $name  Wrapper element lost; '' for an empty answer.
	 * @return string
	 */
	private static function refusal( int $k, int $count, string $name ): string {
		return '' === $name
			/* translators: 1: piece number, 2: number of pieces */
			? sprintf( __( 'The machine translation was not saved because part %1$d of %2$d of the content came back empty.', 'perflocale' ), $k, $count )
			/* translators: 1: piece number, 2: number of pieces, 3: tag name, for example table */
			: sprintf( __( 'The machine translation was not saved because part %1$d of %2$d of the content came back without its <%3$s> wrapper.', 'perflocale' ), $k, $count, $name );
	}

	/**
	 * Tokenize into nodes; false when a text holds a `<`, an end tag is missing or stray below the top, or the expression fails.
	 *
	 * @return bool
	 */
	private function read(): bool {
		$closed_by = ClassicStructureGuard::CLOSED_BY + [ 'tbody' => ' td th tr thead tbody tfoot ' ];
		$optional  = ClassicStructureGuard::OPTIONAL_END . 'tbody ';
		$length    = strlen( $this->html );
		$stack     = [ 0 ];
		$at        = 0;
		$chars     = 0;

		$this->nodes[0] = self::node( '#root', 0, 0 );
		$this->anchor( 0, 0 );

		// TOKEN would scan to the end once per opener that never closes: such content stays whole.
		if ( BlockStructureGuard::unclosed_raw( $this->html ) > BlockStructureGuard::UNCLOSED_MAX ) {
			return false;
		}

		// One token at a time: a list of every token's captures costs about 1 KB per tag.
		while ( 1 === preg_match( ClassicStructureGuard::TOKEN, $this->html, $t, PREG_OFFSET_CAPTURE, $at ) ) {
			$start = (int) $t[0][1];
			$end   = $start + strlen( $t[0][0] );
			$gap   = substr( $this->html, $at, $start - $at );

			if ( str_contains( $gap, '<' ) ) {
				return false;
			}

			$chars += \PerfLocale\Util\Utf8::length( $gap );
			$this->anchor( $start, $chars );
			$chars += \PerfLocale\Util\Utf8::length( $t[0][0] );
			$this->anchor( $end, $chars );
			$at     = $end;
			$parent = (int) end( $stack );

			if ( -1 !== ( $t[1][1] ?? -1 ) ) {
				$this->add( $parent, self::node( strtolower( $t[1][0] ), $start, $end, true ) );
				continue;
			}

			$name = strtolower( (string) ( $t[3][0] ?? '' ) );

			if ( '' === $name || ( isset( $t[3][1] ) && -1 === $t[3][1] ) ) {
				$this->add( $parent, self::node( '#comment', $start, $end, true ) );
				continue;
			}

			if ( '/' !== ( $t[2][0] ?? '' ) ) {
				$closes = $closed_by[ $name ] ?? ( str_contains( ClassicStructureGuard::STRUCTURAL, " $name " ) ? ' p ' : '' );

				while ( isset( $stack[1] ) && str_contains( $closes, ' ' . $this->nodes[ (int) end( $stack ) ]['name'] . ' ' ) ) {
					$this->close( (int) array_pop( $stack ), $start );
				}

				$void = str_contains( ClassicStructureGuard::VOID, " $name " ) || str_ends_with( rtrim( (string) ( $t[4][0] ?? '' ) ), '/' );
				$id   = $this->add( (int) end( $stack ), self::node( $name, $start, $end, $void ) );

				if ( ! $void ) {
					$stack[] = $id;
				}

				continue;
			}

			$found = false;

			for ( $i = count( $stack ) - 1; $i > 0; $i-- ) {
				if ( $this->nodes[ $stack[ $i ] ]['name'] === $name ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				// A stray end tag at the top is a part of its own, kept; below the top a piece would carry it.
				if ( 1 !== count( $stack ) ) {
					return false;
				}

				$this->add( 0, self::node( '/' . $name, $start, $end, true ) );
				continue;
			}

			while ( isset( $stack[1] ) ) {
				$id = (int) array_pop( $stack );

				if ( $this->nodes[ $id ]['name'] === $name ) {
					$this->ends( $id, $start, $end );
					break;
				}

				if ( ! str_contains( $optional, " {$this->nodes[ $id ]['name']} " ) ) {
					return false;
				}

				$this->close( $id, $start );
			}
		}

		if ( PREG_NO_ERROR !== preg_last_error() || str_contains( substr( $this->html, $at ), '<' ) ) {
			return false;
		}

		$this->anchor( $length, $chars + \PerfLocale\Util\Utf8::length( substr( $this->html, $at ) ) );

		while ( isset( $stack[1] ) ) {
			$id = (int) array_pop( $stack );

			if ( ! str_contains( $optional, " {$this->nodes[ $id ]['name']} " ) ) {
				return false;
			}

			$this->close( $id, $length );
		}

		$this->ends( 0, $length, $length );

		return true;
	}

	/**
	 * A node.
	 *
	 * @param string $name  Element name.
	 * @param int    $start Start byte.
	 * @param int    $end   Byte after its start tag (the end of a void or raw element).
	 * @param bool   $whole Never cut inside.
	 * @return array{name: string, start: int, end: int, in: int, out: int, whole: bool, rows: bool}
	 */
	private static function node( string $name, int $start, int $end, bool $whole = false ): array {
		return [
			'name'  => $name,
			'start' => $start,
			'end'   => $end,
			'in'    => $end,
			'out'   => $end,
			'whole' => $whole,
			'rows'  => false,
		];
	}

	/**
	 * Add a node under a parent.
	 *
	 * @param int                                                                                   $under  Parent id.
	 * @param array{name: string, start: int, end: int, in: int, out: int, whole: bool, rows: bool} $node   Node.
	 * @return int Id.
	 */
	private function add( int $under, array $node ): int {
		$id                     = count( $this->nodes );
		$this->nodes[ $id ]     = $node;
		$this->kids[ $under ][] = $id;

		return $id;
	}

	/**
	 * Close an element whose end tag HTML may omit at a byte; whitespace before it is outside.
	 *
	 * @param int $id Node.
	 * @param int $at Byte of what closed it.
	 * @return void
	 */
	private function close( int $id, int $at ): void {
		$floor = $this->nodes[ $id ]['in'];

		while ( $at > $floor && str_contains( self::EDGE, $this->html[ $at - 1 ] ) ) {
			--$at;
		}

		$this->ends( $id, $at, $at );
	}

	/**
	 * Set where a node's content and the node end.
	 *
	 * @param int $id  Node.
	 * @param int $out Content end byte.
	 * @param int $end End byte.
	 * @return void
	 */
	private function ends( int $id, int $out, int $end ): void {
		$node               = $this->nodes[ $id ];
		$node['out']        = $out;
		$node['end']        = $end;
		$this->nodes[ $id ] = $node;
	}

	/**
	 * Record the character offset of a byte.
	 *
	 * @param int $byte  Byte offset.
	 * @param int $chars Characters before it.
	 * @return void
	 */
	private function anchor( int $byte, int $chars ): void {
		$this->anchor_bytes[] = $byte;
		$this->anchor_chars[] = $chars;
	}

	/**
	 * Characters before a byte: the nearest anchor at or before it, plus the bytes between.
	 *
	 * @param int $byte Byte offset.
	 * @return int
	 */
	private function chars( int $byte ): int {
		[ $lo, $hi ] = [ 0, count( $this->anchor_bytes ) - 1 ];

		while ( $lo < $hi ) {
			$mid = intdiv( $lo + $hi + 1, 2 );

			if ( ( $this->anchor_bytes[ $mid ] ?? PHP_INT_MAX ) <= $byte ) {
				$lo = $mid;
			} else {
				$hi = $mid - 1;
			}
		}

		$base  = $this->anchor_bytes[ $lo ] ?? 0;
		$chars = $this->anchor_chars[ $lo ] ?? 0;

		if ( $this->cursor[0] >= $base && $this->cursor[0] <= $byte ) {
			[ $base, $chars ] = $this->cursor;
		}

		$chars       += \PerfLocale\Util\Utf8::length( substr( $this->html, $base, $byte - $base ) );
		$this->cursor = [ $byte, $chars ];

		return $chars;
	}

	/**
	 * A node's units: block children, and the inline runs between them cut at blank lines; empty when it is never cut.
	 *
	 * @param int $id Node.
	 * @return list<array{0: int, 1: int, 2: int}> [ start, end, node id or -1 for a run ].
	 */
	private function units( int $id ): array {
		$node   = $this->nodes[ $id ];
		$units  = [];
		$run    = $node['in'];
		$table  = str_contains( self::TABLE_PARTS, " {$node['name']} " );
		$rows   = false;
		$parts  = false;
		$inline = [];

		foreach ( [ ...( $this->kids[ $id ] ?? [] ), -1 ] as $kid ) {
			if ( -1 !== $kid && ! str_contains( self::BLOCK, ' ' . $this->nodes[ $kid ]['name'] . ' ' ) && ! str_starts_with( $this->nodes[ $kid ]['name'], '/' ) ) {
				$inline[] = $kid;
				continue;
			}

			$stop = -1 === $kid ? $node['out'] : $this->nodes[ $kid ]['start'];

			foreach ( $this->runs( $inline, $run, $stop ) as $r ) {
				// Text in a table part moves out of the table in the parser.
				if ( $table && BlockStructureGuard::has_text( substr( $this->html, $r[0], $r[1] - $r[0] ) ) ) {
					return [];
				}

				$units[] = $r;
			}

			if ( -1 === $kid ) {
				break;
			}

			$units[] = [ $this->nodes[ $kid ]['start'], $this->nodes[ $kid ]['end'], $kid ];
			$run     = $this->nodes[ $kid ]['end'];
			$inline  = [];
			$rows    = $rows || 'tr' === $this->nodes[ $kid ]['name'];
			$parts   = $parts || str_contains( ' thead tbody tfoot caption colgroup ', ' ' . $this->nodes[ $kid ]['name'] . ' ' );
		}

		if ( 'table' === $node['name'] ) {
			if ( $rows && $parts ) {
				return [];
			}

			$node               = $this->nodes[ $id ];
			$node['rows']       = $rows;
			$this->nodes[ $id ] = $node;
		}

		return $units;
	}

	/**
	 * The inline runs between two bytes, trimmed, cut at blank lines outside the inline elements there.
	 *
	 * @param array<int, int> $inline Inline elements and comments between the bytes.
	 * @param int             $from   Start byte.
	 * @param int             $to     End byte.
	 * @return list<array{0: int, 1: int, 2: int}>
	 */
	private function runs( array $inline, int $from, int $to ): array {
		$cuts = [];

		// Blank lines and inline elements are both in order: one pointer walks the elements.
		if ( $this->blank_cuts && preg_match_all( '/\n[ \t\r]*\n/', substr( $this->html, $from, $to - $from ), $m, PREG_OFFSET_CAPTURE ) ) {
			$inline = array_values( $inline );
			$i      = 0;

			foreach ( $m[0] as $hit ) {
				$at = $from + (int) $hit[1];

				while ( isset( $inline[ $i ] ) && $this->nodes[ $inline[ $i ] ]['end'] <= $at ) {
					++$i;
				}

				if ( ! isset( $inline[ $i ] ) || $this->nodes[ $inline[ $i ] ]['start'] >= $at ) {
					$cuts[] = $at;
				}
			}
		}

		$runs = [];

		foreach ( [ ...$cuts, $to ] as $cut ) {
			$a = $from;
			$b = $cut;

			while ( $a < $b && str_contains( self::EDGE, $this->html[ $a ] ) ) {
				++$a;
			}

			while ( $b > $a && str_contains( self::EDGE, $this->html[ $b - 1 ] ) ) {
				--$b;
			}

			if ( $a < $b ) {
				$runs[] = [ $a, $b, -1 ];
			}

			$from = $cut;
		}

		return $runs;
	}

	/**
	 * The units no piece cuts inside, in order, each with the containers cut around it.
	 *
	 * @param int                                                     $id    Container.
	 * @param array<int, int>                                         $path  Containers cut, from the top.
	 * @param int                                                     $limit Characters per piece.
	 * @param list<array{0: int, 1: int, 2: array<int, int>, 3: int}> $atoms Out: [ start, end, path, node id or -1 ].
	 * @param int                                                     $over  Most characters the containers around add to a piece.
	 * @return void
	 */
	private function atoms( int $id, array $path, int $limit, array &$atoms, int $over = 0 ): void {
		$units = $this->units( $id );

		// A piece of one unit also carries this container's tags and the whitespace inside them (or its bare tags).
		if ( 0 !== $id && [] !== $units ) {
			$name  = $this->nodes[ $id ]['name'];
			$tbody = $this->nodes[ $id ]['rows'] ? 7 : 0;
			$lead  = $this->chars( $units[0][0] ) - $this->chars( $this->nodes[ $id ]['start'] );
			$tail  = $this->chars( $this->nodes[ $id ]['end'] ) - $this->chars( $units[ count( $units ) - 1 ][1] );
			$over += max( $lead, strlen( $name ) + 2 + $tbody ) + max( $tail, strlen( $name ) + 3 + $tbody + 1 );
		}

		foreach ( $units as [ $start, $end, $kid ] ) {
			$size = -$this->chars( $start ) + $this->chars( $end );

			if ( -1 !== $kid && count( $path ) < self::MAX_DEPTH && ! $this->nodes[ $kid ]['whole']
				&& str_contains( self::CONTAINER, ' ' . $this->nodes[ $kid ]['name'] . ' ' )
				&& $size + $over > $limit && [] !== $this->units( $kid ) ) {
				$this->atoms( $kid, [ ...$path, $kid ], $limit, $atoms, $over );
				continue;
			}

			$atoms[] = [ $start, $end, $path, $kid ];
		}
	}

	/**
	 * Pack units into pieces greedily; false when it makes one piece.
	 *
	 * @param list<array{0: int, 1: int, 2: array<int, int>, 3: int}> $atoms Units.
	 * @param int                                                     $limit Characters per piece.
	 * @return bool
	 */
	private function pack( array $atoms, int $limit ): bool {
		if ( count( $atoms ) < 2 ) {
			return false;
		}

		$first = $atoms[0][2][0] ?? null;
		$from  = null === $first ? $atoms[0][0] : $this->nodes[ $first ]['start'];
		$open  = [];
		$cuts  = [];

		$this->parts = [ substr( $this->html, 0, $from ) ];

		$base = $this->chars( $from );

		for ( $k = 1, $n = count( $atoms ); $k < $n; $k++ ) {
			// Positions are measured in order (cut, then the end to test), so counting only moves forward.
			[ $left, $right, $chain ] = $this->boundary( $atoms[ $k - 1 ], $atoms[ $k ] );
			$next                     = $this->chars( $right );

			// Measured to where the piece would really end: the closing tags and whitespace up to the next cut count.
			[ $end, , $close ] = $k + 1 < $n ? $this->boundary( $atoms[ $k ], $atoms[ $k + 1 ] ) : [ $this->top_end( $atoms[ $k ] ), 0, [] ];
			$size              = $this->chars( $end ) - $base + strlen( $this->bare( $open, true, $open ) . $this->bare( $close, false, $open ) );
			$stray             = $this->stray( $atoms[ $k ] ) || $this->stray( $atoms[ $k - 1 ] );

			if ( $size <= $limit && ! $stray ) {
				continue;
			}

			$gap = substr( $this->html, $left, $right - $left );

			// Only whitespace sits between units; anything else means the reading went wrong: stay whole.
			if ( '' !== trim( $gap, self::EDGE ) || $left > $right ) {
				return false;
			}

			$this->piece( $from, $left, $open, $chain );
			$this->parts[] = $gap;
			$cuts[]        = $left;
			$from          = $right;
			$open          = $chain;
			$base          = $next;
		}

		$end = $this->top_end( $atoms[ $n - 1 ] );

		$this->piece( $from, $end, $open, [] );
		$this->parts[] = substr( $this->html, $end );

		return [] !== $cuts;
	}

	/**
	 * The end of the top-level element a unit is in (the unit's own end at the top).
	 *
	 * @param array{0: int, 1: int, 2: array<int, int>, 3: int} $unit Unit.
	 * @return int
	 */
	private function top_end( array $unit ): int {
		return isset( $unit[2][0] ) ? $this->nodes[ $unit[2][0] ]['end'] : $unit[1];
	}

	/**
	 * Whether a unit is a stray end tag (a part of its own).
	 *
	 * @param array{0: int, 1: int, 2: array<int, int>, 3: int} $unit Unit.
	 * @return bool
	 */
	private function stray( array $unit ): bool {
		return -1 !== $unit[3] && str_starts_with( $this->nodes[ $unit[3] ]['name'], '/' );
	}

	/**
	 * Where the cut between two units falls: the end of the left side, the start of the right, the containers open there.
	 *
	 * @param array{0: int, 1: int, 2: array<int, int>, 3: int} $left  Unit before the cut.
	 * @param array{0: int, 1: int, 2: array<int, int>, 3: int} $right Unit after it.
	 * @return array{0: int, 1: int, 2: array<int, int>}
	 */
	private function boundary( array $left, array $right ): array {
		$common = [];

		foreach ( $left[2] as $i => $id ) {
			if ( ( $right[2][ $i ] ?? null ) !== $id ) {
				break;
			}

			$common[] = $id;
		}

		$depth = count( $common );
		$a     = isset( $left[2][ $depth ] ) ? $this->nodes[ $left[2][ $depth ] ]['end'] : $left[1];
		$b     = isset( $right[2][ $depth ] ) ? $this->nodes[ $right[2][ $depth ] ]['start'] : $right[0];

		return [ $a, $b, $common ];
	}

	/**
	 * Add a piece, kept as it is when it has no text.
	 *
	 * @param int             $from  Start byte.
	 * @param int             $to    End byte.
	 * @param array<int, int> $open  Containers open at its start.
	 * @param array<int, int> $close Containers open at its end.
	 * @return void
	 */
	private function piece( int $from, int $to, array $open, array $close ): void {
		$source = substr( $this->html, $from, $to - $from );

		if ( ! BlockStructureGuard::has_text( $source, true ) ) {
			$this->parts[] = $source;
			return;
		}

		$start = $this->bare( $open, true, $open );
		$end   = $this->bare( $close, false, $open );
		$outer = $open[0] ?? $close[0] ?? null;

		$this->parts[] = [ $start, $source, $end, self::wrapper( $start, true, $source ), self::wrapper( $end, false, $source ), null === $outer ? '' : $this->nodes[ $outer ]['name'] ];
	}

	/**
	 * Bare start or end tags of containers; a table with rows outside tbody gets one, closed only where it was opened.
	 *
	 * @param array<int, int> $chain  Containers, from the top.
	 * @param bool            $start  Start tags (else end tags, innermost first).
	 * @param array<int, int> $opened Containers whose bare start tags the piece carries.
	 * @return string
	 */
	private function bare( array $chain, bool $start, array $opened ): string {
		$tags = [];

		foreach ( $chain as $id ) {
			$name = $this->nodes[ $id ]['name'];
			$rows = 'table' === $name && $this->nodes[ $id ]['rows'] && ( $start || in_array( $id, $opened, true ) );

			$tags[] = $start ? '<' . $name . '>' . ( $rows ? '<tbody>' : '' ) : ( $rows ? '</tbody>' : '' ) . '</' . $name . '>';
		}

		return implode( '', $start ? $tags : array_reverse( $tags ) );
	}

	/**
	 * The expression for bare tags a piece was sent with, case and whitespace free; '' for none. A tbody added here may
	 * be dropped, and one a parser adds around rows sent right after `<table>` or before `</table>` is accepted; a source
	 * tbody stays. Whitespace is matched possessively: a tag always follows it.
	 *
	 * @param string $bare   Bare tags sent.
	 * @param bool   $start  Start tags (else end tags).
	 * @param string $source The piece's source.
	 * @return string
	 */
	private static function wrapper( string $bare, bool $start, string $source ): string {
		if ( '' === $bare || ! preg_match_all( '#<(/?)([a-z][a-z0-9:-]*+)>#', $bare, $m, PREG_SET_ORDER ) ) {
			return '';
		}

		$rows  = $start ? 1 === preg_match( '#^\s*<tr\b#i', $source ) : 1 === preg_match( '#</tr\s*>\s*$#i', $source );
		$tags  = [];
		$count = count( $m );

		foreach ( $m as $i => $tag ) {
			$tags[] = 'tbody' === $tag[2] ? '(?:<' . ( $start ? '' : '\/' ) . 'tbody\s*+>)?' : '<' . ( $start ? '' : '\/' ) . $tag[2] . '\s*+>';

			// A parser's tbody next to the source's rows: after the last start tag, before the first end tag or a table
			// end right after a row end (a source tbody would sit between them).
			if ( 'table' === $tag[2] && ( $start ? $rows && $count - 1 === $i : ( $rows && 0 === $i ) || 'tr' === ( $m[ $i - 1 ][2] ?? '' ) ) ) {
				$tags[ $i ] = $start ? $tags[ $i ] . '(?:\s*+<tbody\s*+>)?' : '(?:<\/tbody\s*+>\s*+)?' . $tags[ $i ];
			}
		}

		return implode( '\s*+', $tags );
	}
}
