<?php
/**
 * Longest common subsequence over token lists.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-preserving pairings of two lists: the LCS of equal tokens and the greatest-weight pairing. A table over the
 * caller's budget is refused (null) so the caller falls back to a linear method; among equal-length pairings the
 * earliest wins, and a table costs about one byte per cell (traceback steps plus two score rows).
 */
final class Lcs {

	/** The read-back step a cell records: a pair, up (first list), left (second list). */
	private const DIAGONAL = 'D';
	private const UP       = 'U';
	private const LEFT     = 'L';

	/**
	 * The longest common subsequence as index pairs, ascending on both sides.
	 *
	 * @param array<int, int|string> $a      Tokens.
	 * @param array<int, int|string> $b      Tokens.
	 * @param int                    $budget Most table cells accepted.
	 * @return array<int, array{int, int}>|null Null when the table would exceed the budget.
	 * @phpstan-param list<int|string> $a
	 * @phpstan-param list<int|string> $b
	 * @phpstan-return list<array{int, int}>|null
	 */
	public static function pairs( array $a, array $b, int $budget ): ?array {
		$n = count( $a );
		$m = count( $b );

		$prefix = 0;

		while ( $prefix < $n && $prefix < $m && $a[ $prefix ] === $b[ $prefix ] ) {
			++$prefix;
		}

		$suffix = 0;

		while ( $suffix < $n - $prefix && $suffix < $m - $prefix && $a[ $n - 1 - $suffix ] === $b[ $m - 1 - $suffix ] ) {
			++$suffix;
		}

		$pairs = [];

		for ( $i = 0; $i < $prefix; $i++ ) {
			$pairs[] = [ $i, $i ];
		}

		$an = $n - $prefix - $suffix;
		$bm = $m - $prefix - $suffix;

		if ( $an > 0 && $bm > 0 ) {
			if ( $an * $bm > $budget ) {
				return null;
			}

			$ra = array_reverse( array_slice( $a, $prefix, $an ) );
			$rb = array_reverse( array_slice( $b, $prefix, $bm ) );

			$above = array_fill( 0, $bm + 1, 0 );
			$trace = [];

			for ( $i = 1; $i <= $an; $i++ ) {
				$row   = [ 0 ];
				$left  = 0;
				$steps = str_repeat( self::UP, $bm );
				$token = $ra[ $i - 1 ];

				for ( $j = 1; $j <= $bm; $j++ ) {
					if ( $token === $rb[ $j - 1 ] ) {
						$left            = $above[ $j - 1 ] + 1;
						$steps[ $j - 1 ] = self::DIAGONAL;
					} elseif ( $above[ $j ] >= $left ) {
						$left = $above[ $j ];
					} else {
						$steps[ $j - 1 ] = self::LEFT;
					}

					$row[] = $left;
				}

				$trace[ $i ] = $steps;
				$above       = $row;
			}

			$i = $an;
			$j = $bm;

			while ( $i > 0 && $j > 0 ) {
				$step = $trace[ $i ][ $j - 1 ];

				if ( $step === self::DIAGONAL ) {
					$pairs[] = [ $prefix + $an - $i, $prefix + $bm - $j ];
					--$i;
					--$j;
				} elseif ( $step === self::UP ) {
					--$i;
				} else {
					--$j;
				}
			}
		}

		for ( $i = 0; $i < $suffix; $i++ ) {
			$pairs[] = [ $n - $suffix + $i, $m - $suffix + $i ];
		}

		return $pairs;
	}

	/**
	 * The k-th element of each token class paired with the k-th on the other
	 * side, in order: the linear fallback when a table is over budget.
	 *
	 * @param array<int, int|string> $a Tokens.
	 * @param array<int, int|string> $b Tokens.
	 * @return array<int, array{int, int}> Index pairs, ascending on the first side.
	 * @phpstan-param list<int|string> $a
	 * @phpstan-param list<int|string> $b
	 * @phpstan-return list<array{int, int}>
	 */
	public static function in_order( array $a, array $b ): array {
		$positions = [];

		foreach ( $b as $j => $token ) {
			$positions[ $token ][] = $j;
		}

		$next  = [];
		$pairs = [];

		foreach ( $a as $i => $token ) {
			$k = $next[ $token ] ?? 0;

			if ( isset( $positions[ $token ][ $k ] ) ) {
				$pairs[]        = [ $i, $positions[ $token ][ $k ] ];
				$next[ $token ] = $k + 1;
			}
		}

		return $pairs;
	}

	/**
	 * The order-preserving pairing of greatest total weight, over the pairs whose weight reaches the minimum.
	 *
	 * @param int      $n      Length of the first list.
	 * @param int      $m      Length of the second list.
	 * @param callable $weight `callable(int $i, int $j): float`, the weight of pairing the two elements.
	 * @param float    $min    Least weight a pair may have; above zero the table keeps a byte per cell, else its score rows as well.
	 * @param int      $budget Most table cells accepted.
	 * @return array<int, array{int, int}>|null Null when the table would exceed the budget.
	 * @phpstan-param callable(int, int): float $weight
	 * @phpstan-return list<array{int, int}>|null
	 */
	public static function weighted( int $n, int $m, callable $weight, float $min, int $budget ): ?array {
		if ( $n === 0 || $m === 0 ) {
			return [];
		}

		if ( $n * $m > $budget ) {
			return null;
		}

		return self::best( $n, $m, $weight, $min, max( $n, $m ) );
	}

	/**
	 * The greatest-weight pairing within a diagonal band as wide as the budget allows (the full table when it fits): the
	 * over-budget fallback of {@see self::weighted()}; a shift farther than half the band is not found.
	 *
	 * @param int      $n      Length of the first list.
	 * @param int      $m      Length of the second list.
	 * @param callable $weight `callable(int $i, int $j): float`, the weight of pairing the two elements.
	 * @param float    $min    Least weight a pair may have; above zero the band keeps a byte per cell, else its score rows as well.
	 * @param int      $budget Most table cells accepted.
	 * @return array<int, array{int, int}>
	 * @phpstan-param callable(int, int): float $weight
	 * @phpstan-return list<array{int, int}>
	 */
	public static function banded( int $n, int $m, callable $weight, float $min, int $budget ): array {
		if ( $n === 0 || $m === 0 ) {
			return [];
		}

		$cells = intdiv( $budget, max( $n, $m ) );

		return self::best( $n, $m, $weight, $min, intdiv( max( 0, $cells - 1 ), 2 ) );
	}

	/**
	 * The longest chain of pairs ascending on both sides (an LIS over the
	 * second side in the order of the first): the pairs a linear fallback
	 * made that keep their order. The rest crossed.
	 *
	 * @param array<int, int> $pairs Second index per first index.
	 * @return list<array{int, int}> Pairs ascending on both sides.
	 */
	public static function monotone( array $pairs ): array {
		ksort( $pairs );

		$keys  = array_keys( $pairs );
		$vals  = array_values( $pairs );
		$tails = [];
		$prev  = [];

		foreach ( $vals as $n => $j ) {
			$lo = 0;
			$hi = count( $tails );

			while ( $lo < $hi ) {
				$mid = intdiv( $lo + $hi, 2 );

				if ( $vals[ $tails[ $mid ] ] < $j ) {
					$lo = $mid + 1;
				} else {
					$hi = $mid;
				}
			}

			$tails[ $lo ] = $n;
			$prev[ $n ]   = $lo > 0 ? $tails[ $lo - 1 ] : -1;
		}

		$out = [];

		for ( $n = $tails === [] ? -1 : $tails[ count( $tails ) - 1 ]; $n >= 0; $n = $prev[ $n ] ) {
			$out[] = [ $keys[ $n ], $vals[ $n ] ];
		}

		return array_reverse( $out );
	}

	/**
	 * The weighted table over the cells within `half` columns of the diagonal, read back from the end; a cell outside the
	 * band counts as zero so a path never leaves it. Rows keep only their band's scores plus one step per cell; with a
	 * minimum of zero or less the score rows are kept too, for zero-sum pairs beyond the band.
	 *
	 * @param int      $n      Length of the first list.
	 * @param int      $m      Length of the second list.
	 * @param callable $weight `callable(int $i, int $j): float`.
	 * @param float    $min    Least weight a pair may have.
	 * @param int      $half   Columns on each side of the diagonal.
	 * @return list<array{int, int}>
	 * @phpstan-param callable(int, int): float $weight
	 */
	private static function best( int $n, int $m, callable $weight, float $min, int $half ): array {
		$above  = [];
		$trace  = [];
		$lasts  = [];
		$scores = $min > 0.0 ? null : [ 0 => [] ];

		for ( $i = 1; $i <= $n; $i++ ) {
			$row    = [];
			$centre = intdiv( $i * $m, $n );
			$lo     = max( 1, $centre - $half );
			$hi     = min( $m, $centre + $half );
			$steps  = $hi >= $lo ? str_repeat( self::UP, $hi - $lo + 1 ) : '';

			$left = 0.0;

			for ( $j = $lo; $j <= $hi; $j++ ) {
				$up = $above[ $j ] ?? 0.0;

				if ( $up >= $left ) {
					$best = $up;
				} else {
					$best              = $left;
					$steps[ $j - $lo ] = self::LEFT;
				}

				// Reversed so the read-back from the end prefers the earliest pairs.
				$w = $weight( $n - $i, $m - $j );

				if ( $w >= $min ) {
					$diagonal = ( $above[ $j - 1 ] ?? 0.0 ) + $w;

					if ( $diagonal >= $best ) {
						$best              = $diagonal;
						$steps[ $j - $lo ] = self::DIAGONAL;
					}
				}

				$row[ $j ] = $best;
				$left      = $best;
			}

			$trace[ $i ] = $steps;
			$lasts[ $i ] = $row[ $hi ] ?? 0.0;
			$above       = $row;

			if ( $scores !== null ) {
				$scores[ $i ] = $row;
			}
		}

		$pairs = [];
		$i     = $n;
		$j     = $m;

		while ( $i > 0 && $j > 0 ) {
			$centre = intdiv( $i * $m, $n );
			$lo     = max( 1, $centre - $half );
			$hi     = min( $m, $centre + $half );

			if ( $j < $lo || $j > $hi ) {
				if ( $scores !== null ) {
					$w = $weight( $n - $i, $m - $j );

					if ( $w >= $min && 0.0 === ( $scores[ $i - 1 ][ $j - 1 ] ?? 0.0 ) + $w ) {
						$pairs[] = [ $n - $i, $m - $j ];
						--$i;
						--$j;
						continue;
					}
				}

				if ( $j - 1 === $hi && $lasts[ $i ] > 0.0 ) {
					--$j;
				} else {
					--$i;
				}

				continue;
			}

			$step = $trace[ $i ][ $j - $lo ];

			if ( $step === self::DIAGONAL ) {
				$pairs[] = [ $n - $i, $m - $j ];
				--$i;
				--$j;
			} elseif ( $step === self::UP ) {
				--$i;
			} else {
				--$j;
			}
		}

		return $pairs;
	}
}
