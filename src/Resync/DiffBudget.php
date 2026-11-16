<?php
/**
 * Resync: the work budget of the Review screen's diffs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The work budget of the Review screen's diffs: core's `wp_text_diff()` cost grows with the product of a block's
 * changed lines and with their bytes, so a diff is drawn only while it fits the screen's remaining totals; above them
 * both texts show in full, escaped, in two columns. Limits come from `perflocale/resync/diff_budget`, once per render.
 */
final class DiffBudget {

	/** Per diff: the changed region's line product above which core's line matching is not run. */
	public const LINES = 4000000;

	/** Per screen: the changed-line pairs core may compare. */
	public const CELLS = 20000;

	/** Per screen: the bytes of changed lines, both sides, core may diff word by word. */
	public const BYTES = 100000;

	/**
	 * The most a site may raise each limit to: four times its default (core 7.1.3: at most 723 ms and 31.7 MiB measured).
	 */
	public const CELLS_MAX = 4 * self::CELLS;
	public const BYTES_MAX = 4 * self::BYTES;
	public const LINES_MAX = 4 * self::LINES;

	/**
	 * The least a site may lower each limit to: a tenth of its default, which still diffs an ordinary update (44 changed
	 * lines in a block, about 10 KB of changed text).
	 */
	public const CELLS_MIN = self::CELLS / 10;
	public const BYTES_MIN = self::BYTES / 10;
	public const LINES_MIN = self::LINES / 10;

	/**
	 * Pairs left.
	 *
	 * @var int
	 */
	private int $cells;

	/**
	 * Bytes left.
	 *
	 * @var int
	 */
	private int $bytes;

	/**
	 * The line ceiling of one diff.
	 *
	 * @var int
	 */
	private int $lines;

	/**
	 * The limits this budget started with.
	 *
	 * @var array{cells: int, bytes: int, lines: int}
	 */
	private array $limits;

	/**
	 * Start a screen's budget with the limits the filter gives.
	 */
	public function __construct() {
		$this->limits = self::filtered();
		$this->cells  = $this->limits['cells'];
		$this->bytes  = $this->limits['bytes'];
		$this->lines  = $this->limits['lines'];
	}

	/**
	 * The limits this budget started with.
	 *
	 * @return array{cells: int, bytes: int, lines: int}
	 */
	public function limits(): array {
		return $this->limits;
	}

	/**
	 * The limits from `perflocale/resync/diff_budget`, each a whole number clamped to its floor and maximum; any other
	 * value gives its default, a non-array every default.
	 *
	 * @return array{cells: int, bytes: int, lines: int}
	 */
	private static function filtered(): array {
		$defaults = [
			'cells' => self::CELLS,
			'bytes' => self::BYTES,
			'lines' => self::LINES,
		];
		$maximum  = [
			'cells' => self::CELLS_MAX,
			'bytes' => self::BYTES_MAX,
			'lines' => self::LINES_MAX,
		];
		$floor    = [
			'cells' => self::CELLS_MIN,
			'bytes' => self::BYTES_MIN,
			'lines' => self::LINES_MIN,
		];

		/**
		 * Filters the work budget of the "Review update" screen's diffs; a diff over what is left shows both texts in full in
		 * two columns. Read once per render; each limit is clamped to its floor and maximum, a non-integer gives its default.
		 * Raising costs time and memory (raising lines runs core's line matching before cells and bytes are checked).
		 *
		 * @internal Not public: an engine guard; a raised value can hang the review screen.
		 * @hook  perflocale/resync/diff_budget
		 * @since 1.0.8
		 *
		 * @param array<string, int> $limits {
		 *     @type int $cells Changed-line pairs core may compare on one screen. Default 20,000, from 2,000 to 80,000.
		 *     @type int $bytes Bytes of changed lines, both sides, core may diff word by word on one screen. Default 100,000, from 10,000 to 400,000.
		 *     @type int $lines Per diff: the line product of the changed region (after the common first and last lines) above which core's line matching is not run. Default 4,000,000, from 400,000 to 16,000,000.
		 * }
		 */
		/**
		 * A filter may return anything; only whole numbers are taken.
		 *
		 * @var mixed $given
		 */
		$given  = apply_filters( 'perflocale/resync/diff_budget', $defaults );
		$limits = $defaults;

		if ( ! is_array( $given ) ) {
			return $limits;
		}

		foreach ( $defaults as $name => $default ) {
			$value = $given[ $name ] ?? null;

			if ( is_string( $value ) ) {
				$value = \PerfLocale\Util\Scalar::parse_int( $value );
			}

			$limits[ $name ] = is_int( $value ) ? min( $maximum[ $name ], max( $floor[ $name ], $value ) ) : $default;
		}

		return $limits;
	}

	/**
	 * Whether core's diff of two texts fits what is left; when it does, its cost is taken.
	 *
	 * @param string $left  Left text.
	 * @param string $right Right text.
	 * @return bool
	 */
	public function take( string $left, string $right ): bool {
		return $this->admit( self::cost( self::lines( $left ), self::lines( $right ), $this->lines ) );
	}

	/**
	 * Whether a cost fits what is left; when it does, it is taken. A cost that does not fit takes nothing.
	 *
	 * @param array{cells: int, bytes: int}|null $cost The cost, null when the line matching was not run.
	 * @return bool
	 */
	public function admit( ?array $cost ): bool {
		if ( null === $cost || $cost['cells'] > $this->cells || $cost['bytes'] > $this->bytes ) {
			return false;
		}

		$this->cells -= $cost['cells'];
		$this->bytes -= $cost['bytes'];

		return true;
	}

	/**
	 * A text's lines as `wp_text_diff()` splits them.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	public static function lines( string $text ): array {
		return explode( "\n", normalize_whitespace( $text ) );
	}

	/**
	 * What core's diff of two line lists costs: the changed-line pairs and their bytes; null when the changed region's
	 * line product is over the ceiling and the matching was not run.
	 *
	 * @param array<int, string> $left    Left lines.
	 * @param array<int, string> $right   Right lines.
	 * @param int                $ceiling The line ceiling ({@see self::LINES} unless a site set another).
	 * @phpstan-param list<string> $left
	 * @phpstan-param list<string> $right
	 * @return array{cells: int, bytes: int}|null
	 */
	public static function cost( array $left, array $right, int $ceiling = self::LINES ): ?array {
		[ $l, $r ] = self::middle( $left, $right );

		if ( $l * $r > $ceiling ) {
			return null;
		}

		if ( ! class_exists( 'Text_Diff', false ) ) {
			require_once ABSPATH . 'wp-includes/wp-diff.php';
		}

		$cells = 0;
		$bytes = 0;
		$edits = ( new \Text_Diff( 'auto', [ $left, $right ] ) )->getDiff();

		foreach ( is_array( $edits ) ? $edits : [] as $op ) {
			if ( $op instanceof \Text_Diff_Op_change && is_array( $op->orig ) && is_array( $op->final ) ) {
				$cells += count( $op->orig ) * count( $op->final );
				$bytes += self::bytes( $op->orig ) + self::bytes( $op->final );
			}
		}

		return [
			'cells' => $cells,
			'bytes' => $bytes,
		];
	}

	/**
	 * The bytes of a list of lines.
	 *
	 * @param array<mixed> $lines Lines.
	 * @return int
	 */
	private static function bytes( array $lines ): int {
		$n = 0;

		foreach ( $lines as $line ) {
			$n += strlen( is_scalar( $line ) ? (string) $line : '' );
		}

		return $n;
	}

	/**
	 * The line counts of the changed region: each side without the lines both share at the start and at the end.
	 *
	 * @param array<int, string> $left  Left lines.
	 * @param array<int, string> $right Right lines.
	 * @phpstan-param list<string> $left
	 * @phpstan-param list<string> $right
	 * @return array{0: int, 1: int}
	 */
	public static function middle( array $left, array $right ): array {
		$nl  = count( $left );
		$nr  = count( $right );
		$min = min( $nl, $nr );
		$pre = 0;

		while ( $pre < $min && $left[ $pre ] === $right[ $pre ] ) {
			++$pre;
		}

		$suf = 0;

		while ( $suf < $min - $pre && $left[ $nl - 1 - $suf ] === $right[ $nr - 1 - $suf ] ) {
			++$suf;
		}

		return [ $nl - $pre - $suf, $nr - $pre - $suf ];
	}
}
