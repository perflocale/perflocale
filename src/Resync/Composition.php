<?php
/**
 * The result of composing one content field.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What {@see Composer::compose()} assembled and the plan the {@see Verifier} checks: content, flags per source path
 * key ('' for the document), expected block tree and unit texts, each output block's source block, and unit counts.
 */
final class Composition {

	/**
	 * Constructor.
	 *
	 * @param string                         $content The composed bytes.
	 * @param array<string, list<string>>    $flags   Flags per source block path key; '' is the document.
	 * @param array<int, array{int, string}> $blocks  Depth and name of every block of the output, in document order.
	 * @param array<int, string>             $units   The text of every unit of the output, in document order.
	 * @param array<int, string|null>        $origins The source block path key each output block came from, per block.
	 * @param array<string, int>             $counts  Unit counts: reused, same_as_source, fuzzy, changed, new, removed_in_translation, left_source, machine_translated.
	 * @phpstan-param list<array{int, string}> $blocks
	 * @phpstan-param list<string> $units
	 * @phpstan-param list<string|null> $origins
	 */
	public function __construct(
		public readonly string $content,
		public readonly array $flags,
		public readonly array $blocks,
		public readonly array $units,
		public readonly array $origins,
		public readonly array $counts
	) {}

	/**
	 * Every flag set anywhere, sorted, each once.
	 *
	 * @return list<string>
	 */
	public function flag_list(): array {
		$all = [];

		foreach ( $this->flags as $flags ) {
			foreach ( $flags as $flag ) {
				$all[ $flag ] = true;
			}
		}

		$list = array_keys( $all );
		sort( $list, SORT_STRING );

		return $list;
	}
}
