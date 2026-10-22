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
 * The placeholder contract every mask restores by: each placeholder sent
 * comes back exactly once, and the answer holds no other token of the
 * placeholders' form. A placeholder lost, repeated or invented means the
 * answer is damaged and must not be written.
 */
final class PlaceholderCheck {

	/**
	 * How many placeholders an answer damaged.
	 *
	 * One scan of the answer finds every token of the placeholders' form and
	 * counts each token's text; each placeholder sent is then looked up in
	 * those counts.
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
