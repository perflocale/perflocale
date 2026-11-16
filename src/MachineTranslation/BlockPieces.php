<?php
/**
 * Long block content in pieces, cut only between whole blocks or between the block-level elements of one block.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pieces of masked block content of at most a size: runs of whole sibling blocks, whitespace between them and blocks
 * without text kept here. A block too large is opened when its own HTML has no text (its inner blocks become units),
 * and a block without inner blocks is cut between its block-level elements (HtmlPieces), its markup checked on join.
 * Loaded by a literal require only when the content passes a piece size.
 *
 * @since 1.0.8
 * @internal
 */
final class BlockPieces {

	/**
	 * Whitespace trimmed from an answer (HtmlPieces' edge).
	 */
	private const EDGE = " \t\n\r\0\x0B";

	/**
	 * Deepest block opened.
	 */
	private const MAX_DEPTH = 32;

	/**
	 * Name of a run of classic HTML between blocks.
	 */
	private const CLASSIC = 'core/freeform';

	/**
	 * Masked content.
	 *
	 * @var string
	 */
	private string $html;

	/**
	 * The content's mask.
	 *
	 * @var BlockDelimiterMask
	 */
	private BlockDelimiterMask $mask;

	/**
	 * Characters per piece.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Blocks: start and end of the opener placeholder and of the closer placeholder (a void block: the same span twice).
	 *
	 * @var array<int, array{os: int, oe: int, cs: int, ce: int}>
	 */
	private array $blocks = [];

	/**
	 * Block names.
	 *
	 * @var array<int, string>
	 */
	private array $names = [];

	/**
	 * Inner blocks of each block, in order; -1 holds the top level.
	 *
	 * @var array<int, list<int>>
	 */
	private array $kids = [];

	/**
	 * Decoded opener attributes by block, read once when a filter needs them.
	 *
	 * @var array<int, array<mixed>>|null
	 */
	private ?array $attrs = null;

	/**
	 * Parts in order: kept bytes, a piece [ start, end, name of its first unit ], or HTML in pieces (HtmlPieces).
	 *
	 * @var list<string|array{0: int, 1: int, 2: string}|HtmlPieces>
	 */
	private array $parts = [];

	/**
	 * Per HtmlPieces object (spl_object_id): [ name, skeleton of its source ].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $cut = [];

	/**
	 * The texts to send, built once.
	 *
	 * @var list<string>
	 */
	private array $texts = [];

	/**
	 * Name of the block or run each text belongs to.
	 *
	 * @var list<string>
	 */
	private array $owners = [];

	/**
	 * Texts sent from a block or run cut by HtmlPieces, by position: that object and the text's index in it.
	 *
	 * @var array<int, array{0: HtmlPieces, 1: int}>
	 */
	private array $inner = [];

	/**
	 * Placeholder token expressions checked per piece.
	 *
	 * @var list<string>
	 */
	private array $patterns;

	/**
	 * Constructor.
	 *
	 * @param BlockDelimiterMask $mask     The content's mask.
	 * @param int                $limit    Characters per piece.
	 * @param string[]           $patterns Placeholder token expressions.
	 */
	private function __construct( BlockDelimiterMask $mask, int $limit, array $patterns ) {
		$this->mask     = $mask;
		$this->html     = $mask->text();
		$this->limit    = $limit;
		$this->patterns = array_values( $patterns );
	}

	/**
	 * Cut masked block content into pieces of at most $limit characters each (as sent); a piece over it is one block
	 * or element that has no safe cut (atomic_over()).
	 *
	 * @param BlockDelimiterMask $mask     The content's mask.
	 * @param int                $limit    Characters per piece.
	 * @param string[]           $patterns Expressions matching any placeholder token in the content (each mask's).
	 * @return self|null Null when it stays whole: one piece, a delimiter the parser could read otherwise, delimiter text
	 *                   outside a delimiter, or classic markup between top-level blocks that is not closed where it opens.
	 */
	public static function split( BlockDelimiterMask $mask, int $limit, array $patterns ): ?self {
		$html = $mask->text();

		if ( $limit < 1 || \PerfLocale\Util\Utf8::length( $html ) <= $limit || BlockStructureGuard::has_stray( $html ) ) {
			return null;
		}

		$self = new self( $mask, $limit, $patterns );

		if ( ! $self->read( $mask->layout() ) ) {
			return null;
		}

		$items = [];

		if ( ! $self->items( -1, 0, strlen( $html ), 0, $items ) ) {
			return null;
		}

		$self->pack( $items );

		return count( $self->texts ) > 1 ? $self : null;
	}

	/**
	 * The pieces to send.
	 *
	 * @return list<string>
	 */
	public function texts(): array {
		return $this->texts;
	}

	/**
	 * Pieces texts() sends.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->texts );
	}

	/**
	 * The pieces over the size: each one block, classic run or element without a safe cut.
	 *
	 * @return list<array{name: string, chars: int, part: int}> Block name, characters, part (1-based).
	 */
	public function atomic_over(): array {
		$out = [];

		foreach ( $this->texts as $k => $text ) {
			$chars = \PerfLocale\Util\Utf8::length( $text );

			if ( $chars > $this->limit ) {
				$out[] = [
					'name'  => $this->owners[ $k ],
					'chars' => $chars,
					'part'  => $k + 1,
				];
			}
		}

		return $out;
	}

	/**
	 * Why the answer to piece $k (0-based, as texts()) is not saved; '' when it passes.
	 *
	 * @param int    $k      Piece.
	 * @param string $answer Its answer.
	 * @return string
	 */
	public function check( int $k, string $answer ): string {
		if ( ! isset( $this->texts[ $k ] ) ) {
			return '';
		}

		$count   = count( $this->texts );
		$refusal = isset( $this->inner[ $k ] ) ? $this->inner[ $k ][0]->check( $this->inner[ $k ][1], $answer ) : '';

		if ( '' !== $refusal ) {
			return $refusal;
		}

		if ( '' === trim( $answer, self::EDGE ) ) {
			/* translators: 1: piece number, 2: number of pieces */
			return sprintf( __( 'The machine translation was not saved because part %1$d of %2$d of the content came back empty.', 'perflocale' ), $k + 1, $count );
		}

		if ( BlockStructureGuard::has_stray( $answer ) ) {
			return self::added_delimiters( $k + 1, $count );
		}

		$answer = PlaceholderCheck::respaced( $answer );

		foreach ( $this->patterns as $pattern ) {
			$sent = preg_match_all( $pattern, $this->texts[ $k ], $m ) ? $m[0] : [];

			if ( PlaceholderCheck::damaged( $answer, $sent, $pattern ) > 0 ) {
				/* translators: 1: piece number, 2: number of pieces */
				return sprintf( __( 'The machine translation was not saved because part %1$d of %2$d of the content came back with block or shortcode markers missing, changed or more than once.', 'perflocale' ), $k + 1, $count );
			}
		}

		$broken = isset( $this->inner[ $k ] ) ? ClassicStructureGuard::mismatch( $this->texts[ $k ], $answer ) : '';

		/* translators: 1: piece number, 2: number of pieces, 3: block name, 4: why it is not saved */
		return '' === $broken ? '' : sprintf( __( 'Part %1$d of %2$d of the content (%3$s): %4$s', 'perflocale' ), $k + 1, $count, $this->owners[ $k ], $broken );
	}

	/**
	 * The masked content from the answers to texts(), in order; kept parts as they were. A block or run cut between
	 * its elements must come back with its markup unchanged.
	 *
	 * @param string[] $answers Answers.
	 * @return string
	 * @throws NotSavedException When an answer is empty, adds block delimiters, a piece lost a wrapper tag, or a cut
	 *                           block's markup changed.
	 */
	public function join( array $answers ): string {
		$answers = array_values( $answers );
		$count   = count( $this->texts );
		$out     = '';
		$k       = 0;

		foreach ( $this->parts as $part ) {
			if ( is_string( $part ) ) {
				$out .= $part;
				continue;
			}

			$n     = $part instanceof HtmlPieces ? $part->count() : 1;
			$given = array_map( 'strval', array_slice( $answers, $k, $n ) );

			foreach ( $given as $i => $answer ) {
				if ( BlockStructureGuard::has_stray( $answer ) ) {
					throw new NotSavedException( esc_html( self::added_delimiters( $k + $i + 1, $count ) ) );
				}
			}

			if ( $part instanceof HtmlPieces ) {
				$joined           = $part->join( $given );
				[ $name, $shape ] = $this->cut[ spl_object_id( $part ) ];

				if ( ClassicStructureGuard::skeleton( $joined ) !== $shape ) {
					/* translators: 1: first part, 2: last part, 3: number of parts, 4: block name */
					throw new NotSavedException( esc_html( sprintf( __( 'The machine translation was not saved because parts %1$d to %2$d of %3$d of the content (%4$s) came back with changed HTML tags or attributes.', 'perflocale' ), $k + 1, $k + $n, $count, $name ) ) );
				}

				$out .= $joined;
				$k   += $n;
				continue;
			}

			$answer = trim( $given[0] ?? '', self::EDGE );
			++$k;

			if ( '' === $answer ) {
				/* translators: 1: piece number, 2: number of pieces */
				throw new NotSavedException( esc_html( sprintf( __( 'The machine translation was not saved because part %1$d of %2$d of the content came back empty.', 'perflocale' ), $k, $count ) ) );
			}

			$out .= $answer;
		}

		return $out;
	}

	/**
	 * The refusal for an answer holding block delimiter text.
	 *
	 * @param int $k     Piece, 1-based.
	 * @param int $count Pieces.
	 * @return string
	 */
	private static function added_delimiters( int $k, int $count ): string {
		/* translators: 1: piece number, 2: number of pieces */
		return sprintf( __( 'The machine translation was not saved because part %1$d of %2$d of the content came back with block delimiters added.', 'perflocale' ), $k, $count );
	}

	/**
	 * Whether a content's own block delimiters nest as a parser reads them (every closer closes the open block of its
	 * name). The content sent may be re-serialized by a filter ("Do not translate"), which hides a source the save
	 * check then refuses; such a source is sent whole, never paid for in parts.
	 *
	 * @param string $html Post content as stored.
	 * @return bool
	 */
	public static function nests( string $html ): bool {
		$stack = [];

		foreach ( BlockStructureGuard::delimiters( $html ) ?? [ [ 'kind' => 'end', 'name' => '' ] ] as $t ) {
			if ( 'start' === $t['kind'] ) {
				$stack[] = $t['name'];
			} elseif ( 'end' === $t['kind'] && array_pop( $stack ) !== $t['name'] ) {
				return false;
			}
		}

		return [] === $stack;
	}

	/**
	 * Build the block tree from the placeholder spans; false where a parser could read it otherwise.
	 *
	 * @param list<array{kind: string, name: string, start: int, end: int}> $layout Delimiters in order.
	 * @return bool
	 */
	private function read( array $layout ): bool {
		$this->kids[-1] = [];
		$stack          = [];

		foreach ( $layout as $i => $d ) {
			$parent = [] === $stack ? -1 : (int) end( $stack );

			if ( 'end' === $d['kind'] ) {
				// A closer without its opener, or of another block.
				if ( [] === $stack || $this->names[ $parent ] !== $d['name'] ) {
					return false;
				}

				$b                       = $this->blocks[ $parent ];
				$this->blocks[ $parent ] = [
					'os' => $b['os'],
					'oe' => $b['oe'],
					'cs' => $d['start'],
					'ce' => $d['end'],
				];
				array_pop( $stack );
				continue;
			}

			$this->blocks[ $i ]      = [
				'os' => $d['start'],
				'oe' => $d['end'],
				'cs' => $d['start'],
				'ce' => $d['end'],
			];
			$this->kids[ $i ]        = [];
			$this->names[ $i ]       = $d['name'];
			$this->kids[ $parent ][] = $i;

			if ( 'start' === $d['kind'] ) {
				$stack[] = $i;
			}
		}

		return [] === $stack;
	}

	/**
	 * The items between two bytes inside a block (-1: the top level): separators and units, units over the size opened.
	 *
	 * @param int                                                                           $block Block, -1 for the top.
	 * @param int                                                                           $from  Start byte.
	 * @param int                                                                           $to    End byte.
	 * @param int                                                                           $depth Depth of its inner blocks.
	 * @param list<array{0: string, 1: int, 2: int, 3: int, 4: HtmlPieces|null, 5: string}> $items Items: kind ('sep', 'unit', 'run'), start, end, characters, HTML in pieces, name.
	 * @return bool False when a top-level run of classic markup is not closed where it opens.
	 */
	private function items( int $block, int $from, int $to, int $depth, array &$items ): bool {
		$at = $from;

		foreach ( $this->kids[ $block ] as $kid ) {
			if ( ! $this->run( $at, $this->blocks[ $kid ]['os'], $depth, $items ) ) {
				return false;
			}

			$this->unit( $kid, $depth, $items );
			$at = $this->blocks[ $kid ]['ce'];
		}

		return $this->run( $at, $to, $depth, $items );
	}

	/**
	 * The items of the bytes between two blocks: edge whitespace kept, the rest a unit when it has text.
	 *
	 * @param int                                                                           $from  Start byte.
	 * @param int                                                                           $to    End byte.
	 * @param int                                                                           $depth Depth.
	 * @param list<array{0: string, 1: int, 2: int, 3: int, 4: HtmlPieces|null, 5: string}> $items Items.
	 * @return bool
	 */
	private function run( int $from, int $to, int $depth, array &$items ): bool {
		if ( $to <= $from ) {
			return true;
		}

		$text  = substr( $this->html, $from, $to - $from );
		$start = $from + strspn( $text, self::EDGE );
		$end   = $from + strlen( rtrim( $text, self::EDGE ) );

		if ( $end <= $start ) {
			$this->add( $items, 'sep', $from, $to );
			return true;
		}

		$core = substr( $this->html, $start, $end - $start );

		// Hybrid content: an element opened before a block and closed after it would put half a wrapper in a piece.
		if ( 0 === $depth && str_contains( $core, '<' ) && ! ClassicStructureGuard::balanced( $core ) ) {
			return false;
		}

		$this->add( $items, 'sep', $from, $start );

		if ( ! BlockStructureGuard::has_text( $core, true ) ) {
			$this->add( $items, 'sep', $start, $end );
		} elseif ( ! $this->cut_html( $start, $end, self::CLASSIC, true, $items ) ) {
			$this->add( $items, 'unit', $start, $end, self::CLASSIC );
		}

		$this->add( $items, 'sep', $end, $to );

		return true;
	}

	/**
	 * Add HTML over the size as a run in pieces (HtmlPieces); false when it has no safe cut.
	 *
	 * @param int                                                                           $start      Start byte.
	 * @param int                                                                           $end        End byte.
	 * @param string                                                                        $name       Block name.
	 * @param bool                                                                          $blank_cuts Cut an inline run at a blank line.
	 * @param list<array{0: string, 1: int, 2: int, 3: int, 4: HtmlPieces|null, 5: string}> $items      Items.
	 * @return bool
	 */
	private function cut_html( int $start, int $end, string $name, bool $blank_cuts, array &$items ): bool {
		$html = substr( $this->html, $start, $end - $start );

		if ( \PerfLocale\Util\Utf8::length( $html ) <= $this->limit ) {
			return false;
		}

		require_once __DIR__ . '/HtmlPieces.php';

		$pieces = HtmlPieces::split( $html, $this->limit, $blank_cuts );

		if ( null === $pieces ) {
			return false;
		}

		$this->cut[ spl_object_id( $pieces ) ] = [ $name, ClassicStructureGuard::skeleton( $html ) ];
		$items[]                               = [ 'run', $start, $end, 0, $pieces, $name ];

		return true;
	}

	/**
	 * The items of one block: a separator without text, a unit, opened when too large and its own HTML has no text, or
	 * a block without inner blocks cut between its block-level elements.
	 *
	 * @param int                                                                           $block Block.
	 * @param int                                                                           $depth Its depth.
	 * @param list<array{0: string, 1: int, 2: int, 3: int, 4: HtmlPieces|null, 5: string}> $items Items.
	 * @return void
	 */
	private function unit( int $block, int $depth, array &$items ): void {
		$b    = $this->blocks[ $block ];
		$name = $this->names[ $block ];
		$text = substr( $this->html, $b['os'], $b['ce'] - $b['os'] );

		if ( ! BlockStructureGuard::has_text( $text, true ) ) {
			$this->add( $items, 'sep', $b['os'], $b['ce'] );
			return;
		}

		if ( \PerfLocale\Util\Utf8::length( $text ) <= $this->limit ) {
			$this->add( $items, 'unit', $b['os'], $b['ce'], $name );
			return;
		}

		if ( [] === $this->kids[ $block ] ) {
			$inner = substr( $this->html, $b['oe'], $b['cs'] - $b['oe'] );
			$start = $b['oe'] + strspn( $inner, self::EDGE );
			$end   = $b['oe'] + strlen( rtrim( $inner, self::EDGE ) );
			$cut   = [];

			if ( $end > $start && $this->cut_html( $start, $end, $name, false, $cut ) && $this->splittable( $block ) ) {
				$this->add( $items, 'sep', $b['os'], $start );
				array_push( $items, ...$cut );
				$this->add( $items, 'sep', $end, $b['ce'] );
				return;
			}

			$this->add( $items, 'unit', $b['os'], $b['ce'], $name );
			return;
		}

		if ( $depth >= self::MAX_DEPTH || BlockStructureGuard::has_text( $this->own( $block ), true ) ) {
			$this->add( $items, 'unit', $b['os'], $b['ce'], $name );
			return;
		}

		$this->add( $items, 'sep', $b['os'], $b['oe'] );
		$this->items( $block, $b['oe'], $b['cs'], $depth + 1, $items );
		$this->add( $items, 'sep', $b['cs'], $b['ce'] );
	}

	/**
	 * Whether a block without inner blocks that has a safe cut may be sent in pieces; only a filter's false says no.
	 *
	 * @param int $block Block.
	 * @return bool
	 */
	private function splittable( int $block ): bool {
		$name = $this->names[ $block ];

		try {
			/**
			 * Filter whether machine translation may send one block without inner blocks in pieces, cut between its
			 * block-level elements (table rows, list items, paragraphs of a quote), when the block is longer than the
			 * piece size. Only false keeps it whole; it is then sent as one text and may be refused as too large.
			 *
			 * @hook  perflocale/mt/block_splittable
			 * @since 1.0.8
			 *
			 * @param bool                 $splittable Default true.
			 * @param string               $block_name Block name, such as core/table.
			 * @param array<string, mixed> $block      `blockName` and `attrs` (the opener's decoded attributes).
			 */
			$value = apply_filters(
				'perflocale/mt/block_splittable',
				true,
				$name,
				[
					'blockName' => $name,
					'attrs'     => $this->attrs()[ $block ] ?? [],
				]
			);
		} catch ( \Throwable $e ) {
			$value = true;
		}

		return false !== $value;
	}

	/**
	 * Each opener's decoded attributes, from the content the mask puts back unchanged (read once).
	 *
	 * @return array<int, array<mixed>>
	 */
	private function attrs(): array {
		if ( null !== $this->attrs ) {
			return $this->attrs;
		}

		$this->attrs = [];
		$mask        = clone $this->mask;

		try {
			$source = $mask->restore( $this->html, $mask->segment_texts() );
		} catch ( \Throwable $e ) {
			return $this->attrs;
		}

		foreach ( BlockStructureGuard::delimiters( $source ) ?? [] as $i => $t ) {
			if ( 'end' === $t['kind'] || ! $t['attrs'] ) {
				continue;
			}

			$text  = substr( $source, $t['start'], $t['end'] - $t['start'] );
			$open  = (int) strpos( $text, '{' );
			$attrs = json_decode( substr( $text, $open, (int) strrpos( $text, '}' ) - $open + 1 ), true, 64 );

			if ( is_array( $attrs ) ) {
				$this->attrs[ $i ] = $attrs;
			}
		}

		return $this->attrs;
	}

	/**
	 * A block's own HTML: its bytes outside its delimiters and inner blocks.
	 *
	 * @param int $block Block.
	 * @return string
	 */
	private function own( int $block ): string {
		$b   = $this->blocks[ $block ];
		$out = '';
		$at  = $b['oe'];

		foreach ( $this->kids[ $block ] as $kid ) {
			$out .= substr( $this->html, $at, $this->blocks[ $kid ]['os'] - $at ) . ' ';
			$at   = $this->blocks[ $kid ]['ce'];
		}

		return $out . substr( $this->html, $at, $b['cs'] - $at );
	}

	/**
	 * Add a separator or unit with its length in characters; an empty span adds nothing.
	 *
	 * @param list<array{0: string, 1: int, 2: int, 3: int, 4: HtmlPieces|null, 5: string}> $items Items.
	 * @param string                                                                        $kind  'sep' or 'unit'.
	 * @param int                                                                           $from  Start byte.
	 * @param int                                                                           $to    End byte.
	 * @param string                                                                        $name  A unit's block name.
	 * @return void
	 */
	private function add( array &$items, string $kind, int $from, int $to, string $name = '' ): void {
		if ( $to > $from ) {
			$items[] = [ $kind, $from, $to, \PerfLocale\Util\Utf8::length( substr( $this->html, $from, $to - $from ) ), null, $name ];
		}
	}

	/**
	 * Pack units greedily into pieces; a separator that is not whitespace, and HTML in pieces, end the piece.
	 *
	 * @param list<array{0: string, 1: int, 2: int, 3: int, 4: HtmlPieces|null, 5: string}> $items Items in order.
	 * @return void
	 */
	private function pack( array $items ): void {
		// The open piece [ start, end, characters, name ] and the whitespace after it, not yet part of it.
		$open = null;
		$gap  = [];

		foreach ( $items as [ $kind, $from, $to, $chars, $run, $name ] ) {
			$bytes = substr( $this->html, $from, $to - $from );

			if ( null !== $run || ( 'sep' === $kind && ( null === $open || '' !== trim( $bytes, self::EDGE ) ) ) ) {
				$this->flush( $open, $gap );
				$open          = null;
				$gap           = [];
				$this->parts[] = $run ?? $bytes;
				continue;
			}

			if ( 'sep' === $kind ) {
				$gap[] = $bytes;
				continue;
			}

			$gap_chars = array_sum( array_map( [ \PerfLocale\Util\Utf8::class, 'length' ], $gap ) );

			if ( null !== $open && $open[2] + $gap_chars + $chars <= $this->limit ) {
				$open = [ $open[0], $to, $open[2] + $gap_chars + $chars, $open[3] ];
				$gap  = [];
				continue;
			}

			$this->flush( $open, $gap );
			$open = [ $from, $to, $chars, $name ];
			$gap  = [];
		}

		$this->flush( $open, $gap );
		$this->index();
	}

	/**
	 * Add the open piece and the whitespace after it to the parts.
	 *
	 * @param array{0: int, 1: int, 2: int, 3: string}|null $open Open piece.
	 * @param string[]                                      $gap  Whitespace after it.
	 * @return void
	 */
	private function flush( ?array $open, array $gap ): void {
		if ( null !== $open ) {
			$this->parts[] = [ $open[0], $open[1], $open[3] ];
		}

		foreach ( $gap as $bytes ) {
			$this->parts[] = $bytes;
		}
	}

	/**
	 * Build the texts with their owners, and name each run's pieces as parts of the whole content in its refusals.
	 *
	 * @return void
	 */
	private function index(): void {
		$total = 0;

		foreach ( $this->parts as $part ) {
			$total += $part instanceof HtmlPieces ? $part->count() : ( is_array( $part ) ? 1 : 0 );
		}

		foreach ( $this->parts as $part ) {
			if ( $part instanceof HtmlPieces ) {
				$part->number_as( count( $this->texts ), $total );

				foreach ( $part->texts() as $i => $text ) {
					$this->inner[ count( $this->texts ) ] = [ $part, $i ];
					$this->owners[]                       = $this->cut[ spl_object_id( $part ) ][0];
					$this->texts[]                        = $text;
				}
			} elseif ( is_array( $part ) ) {
				$this->owners[] = $part[2];
				$this->texts[]  = substr( $this->html, $part[0], $part[1] - $part[0] );
			}
		}
	}
}
