<?php
/**
 * Byte-range edits on a document.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies byte-range replacements to a string, from the end so earlier offsets stay valid (an insertion is an empty
 * range). Two edits sharing a byte, or two insertions at one offset, are refused: the composer never guesses a winner.
 */
final class Splice {

	/**
	 * Apply edits to a string.
	 *
	 * @param string                              $bytes The bytes to edit.
	 * @param array<int, array{int, int, string}> $edits Edits: start offset, end offset (exclusive), replacement.
	 * @return string
	 * @throws ComposeError When two edits overlap, or a range is out of bounds.
	 * @phpstan-param list<array{int, int, string}> $edits
	 */
	public static function apply( string $bytes, array $edits ): string {
		if ( $edits === [] ) {
			return $bytes;
		}

		usort( $edits, static fn( array $a, array $b ): int => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0] );

		$length     = strlen( $bytes );
		$prev_start = -1;
		$prev_end   = -1;

		foreach ( $edits as [ $start, $end, $replacement ] ) {
			if ( $start < 0 || $end < $start || $end > $length ) {
				throw new ComposeError( sprintf( 'edit [%d, %d) is outside the %d bytes', $start, $end, $length ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Offsets for the log, never rendered.
			}

			if ( $start < $prev_end || ( $start === $prev_start && $end === $start && $prev_end === $prev_start ) ) {
				throw new ComposeError( sprintf( 'edit [%d, %d) overlaps the edit before it', $start, $end ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Offsets for the log, never rendered.
			}

			$prev_start = $start;
			$prev_end   = $end;
		}

		for ( $i = count( $edits ) - 1; $i >= 0; $i-- ) {
			[ $start, $end, $replacement ] = $edits[ $i ];

			$bytes = substr( $bytes, 0, $start ) . $replacement . substr( $bytes, $end );
		}

		return $bytes;
	}
}
