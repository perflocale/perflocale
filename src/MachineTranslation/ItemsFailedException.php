<?php
/**
 * A provider failure of some texts of a list.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The texts $first..$last (0-based, inclusive) of the list a provider was given failed with $failure.
 *
 * @since 1.0.7.5
 */
final class ItemsFailedException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param \RuntimeException $failure The provider's failure; its message was escaped where it was thrown.
	 * @param int               $first   First failed text.
	 * @param int               $last    Last failed text.
	 */
	public function __construct( public readonly \RuntimeException $failure, public readonly int $first, public readonly int $last ) {
		parent::__construct( $failure->getMessage(), (int) $failure->getCode(), $failure ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped where it was thrown.
	}

	/**
	 * $e as the failure of texts $first..$last; a typed failure (deadline, breaker, refusal) passes unchanged.
	 *
	 * @param \RuntimeException $e     Failure.
	 * @param int               $first First failed text.
	 * @param int               $last  Last failed text.
	 * @return \RuntimeException
	 */
	public static function of( \RuntimeException $e, int $first, int $last ): \RuntimeException {
		if ( $e instanceof self ) {
			return new self( $e->failure, $first, $last );
		}

		return \RuntimeException::class === $e::class ? new self( $e, $first, $last ) : $e;
	}

	/**
	 * $e with the failed texts named and their length in characters, never their text; a typed failure passes unchanged.
	 *
	 * @param \RuntimeException  $e     Failure of a call that sent $sent.
	 * @param array<int, string> $sent  The call's texts.
	 * @param callable           $label Name of the text at a position of $sent, such as "title".
	 * @phpstan-param callable(int): string $label
	 * @return \RuntimeException
	 */
	public static function named( \RuntimeException $e, array $sent, callable $label ): \RuntimeException {
		if ( $e instanceof self ) {
			[ $first, $last, $e ] = [ $e->first, $e->last, $e->failure ];
		} elseif ( \RuntimeException::class === $e::class && [] !== $sent ) {
			[ $first, $last ] = [ 0, count( $sent ) - 1 ];
		} else {
			return $e;
		}

		$chars = 0;

		for ( $i = $first; $i <= $last; $i++ ) {
			$chars += mb_strlen( $sent[ $i ] ?? '' );
		}

		/* translators: %s: number of characters */
		$length = sprintf( _n( '%s character', '%s characters', $chars, 'perflocale' ), number_format_i18n( $chars ) );
		$count  = $last - $first + 1;

		if ( 1 === $count ) {
			/* translators: 1: the failed text, such as "title" or "block text 3 of 12", 2: its length */
			$prefix = sprintf( __( 'Failed item: %1$s (%2$s).', 'perflocale' ), $label( $first ), $length );
		} else {
			$items = 2 === $count
				/* translators: 1, 2: the two failed texts, such as "title" and "excerpt" */
				? sprintf( __( '%1$s and %2$s', 'perflocale' ), $label( $first ), $label( $last ) )
				/* translators: 1: the first failed text, 2: the last one */
				: sprintf( __( '%1$s to %2$s', 'perflocale' ), $label( $first ), $label( $last ) );

			/* translators: 1: the failed texts, 2: how many, 3: their length */
			$prefix = sprintf( __( 'Failed items: %1$s (%2$d texts, %3$s).', 'perflocale' ), $items, $count, $length );
		}

		return new \RuntimeException( esc_html( $prefix ) . ' ' . $e->getMessage(), (int) $e->getCode(), $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The provider message was escaped where it was thrown.
	}
}
