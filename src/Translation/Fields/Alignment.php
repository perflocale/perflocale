<?php
/**
 * The alignment of two row lists.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of {@see RowAligner::align()}. A refused alignment (cap, memory, tokens) is the identity map,
 * ambiguous, every pair a guess, and names no rename.
 */
final class Alignment {

	/**
	 * Old index per new index, when paired.
	 *
	 * @var array<int, int>
	 */
	private array $new_of = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, int|null>        $map       Old index per new index, null for a new row.
	 * @param array<int, int>             $removed   Old indices with no partner.
	 * @param bool                        $ambiguous Whether any pair was a guess.
	 * @param array<int, array{int, int}> $moves     Pairs whose indices differ, [old, new], in new order.
	 * @param array<int, int>             $guessed   New indices paired by guess.
	 * @param string                      $refused   Why the aligner answered the identity map ({@see RowAligner::REFUSED_CAP}, {@see RowAligner::REFUSED_MEMORY}, {@see RowAligner::REFUSED_TOKENS}); '' when computed.
	 * @phpstan-param list<int|null> $map
	 * @phpstan-param list<int> $removed
	 * @phpstan-param list<array{int, int}> $moves
	 * @phpstan-param list<int> $guessed
	 */
	public function __construct(
		public readonly array $map,
		public readonly array $removed,
		public readonly bool $ambiguous,
		public readonly array $moves,
		public readonly array $guessed,
		public readonly string $refused = ''
	) {
		foreach ( $map as $j => $i ) {
			if ( null !== $i ) {
				$this->new_of[ $i ] = $j;
			}
		}
	}

	/**
	 * The old row of a new row.
	 *
	 * @param int $new_index New index.
	 * @return int|null
	 */
	public function old_of( int $new_index ): ?int {
		return $this->map[ $new_index ] ?? null;
	}

	/**
	 * The new row of an old row.
	 *
	 * @param int $old_index Old index.
	 * @return int|null
	 */
	public function new_of( int $old_index ): ?int {
		return $this->new_of[ $old_index ] ?? null;
	}

	/**
	 * Whether a new row was paired by guess.
	 *
	 * @param int $new_index New index.
	 * @return bool
	 */
	public function is_guessed( int $new_index ): bool {
		return in_array( $new_index, $this->guessed, true );
	}
}
