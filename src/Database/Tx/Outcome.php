<?php
/**
 * What a transaction unit ended with.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Tx;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of Tx::run().
 *
 * @since 1.0.8
 * @internal
 */
final class Outcome {

	/**
	 * An outcome.
	 *
	 * @param string             $status    'done' (committed), 'failed' (rolled back), 'unknown' (COMMIT unconfirmed and undecided)
	 *                                      or 'busy' (refused before START TRANSACTION).
	 * @param string             $why       '' when done; 'body', 'dead: <reason>', 'commit', 'open' or 'start'.
	 * @param array<int, string> $forgotten Options whose cached forms were dropped (OptionWriter::settle()).
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $why,
		public readonly array $forgotten = []
	) {
	}
}
