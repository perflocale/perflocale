<?php
/**
 * Checks that a provider answer kept the placeholders it was sent.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract every mask restores by: each placeholder sent comes back exactly once and no other token of its form.
 *
 * A placeholder lost, repeated or invented means a damaged answer that must not be written.
 */
final class PlaceholderCheck {

	/**
	 * The answer with each block and shortcode placeholder comment in its sent spelling `<!-- pfl-b-... -->`.
	 *
	 * A provider may drop the spaces inside a comment; a name changed in any other way stays damaged.
	 *
	 * @param string $answer Provider answer.
	 * @return string
	 */
	public static function respaced( string $answer ): string {
		if ( ! str_contains( $answer, '<!--' ) ) {
			return $answer;
		}

		return preg_replace( '/<!--\s*+(pfl-[bs]-[0-9a-f]{12}-\d++)\s*+-->/', '<!-- $1 -->', $answer ) ?? $answer;
	}

	/**
	 * How many placeholders an answer damaged.
	 *
	 * @param string             $answer Provider answer.
	 * @param array<int, string> $sent   The text of each placeholder sent. Each one is a whole match of $any.
	 * @param string             $any    Regular expression matching any token of the placeholders' form.
	 * @return int 0 when every placeholder came back exactly once and no other token of the form appeared; else the
	 *             number of placeholders missing, repeated or changed, or the difference between tokens found and sent.
	 */
	public static function damaged( string $answer, array $sent, string $any ): int {
		$found = (int) preg_match_all( $any, $answer, $matches );
		$times = array_count_values( $found > 0 ? $matches[0] : [] );
		$bad   = 0;

		foreach ( $sent as $placeholder ) {
			if ( 1 !== ( $times[ $placeholder ] ?? 0 ) ) {
				++$bad;
			}
		}

		$count = count( $sent );

		if ( 0 === $bad && $found === $count ) {
			return 0;
		}

		return max( $bad, abs( $found - $count ) );
	}
}
