<?php
/**
 * Inventory Sync's batched reads for a catalogue limit: a product's variations listed.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded on first use, only while InventorySync computes a Store API catalogue limit, so classic pages and carts never
 * load it. Works on what InventorySync passes; reads none of its state.
 */
final class CopyRowBatch {

	/**
	 * Per InventorySync instance (one per request): blog id => parent of the variation whose copy row was asked last.
	 *
	 * @var \WeakMap<InventorySync, array<int, int>>|null
	 */
	private static ?\WeakMap $last = null;

	/**
	 * The variations whose stored copy rows to read: this one, plus its unread siblings (up to $batch) when a sibling
	 * was asked right before.
	 *
	 * @param InventorySync                                   $sync         The instance asking.
	 * @param int                                             $variation_id Source variation id.
	 * @param array<array-key, mixed>                         $rows         Copy rows the instance holds for this blog, by variation id.
	 * @param callable(int, bool): (array<string, mixed>|null) $siblings     The instance's sibling_children().
	 * @param int                                             $batch        Most rows per read.
	 * @return array<int, int>
	 */
	public static function ids( InventorySync $sync, int $variation_id, array $rows, callable $siblings, int $batch ): array {
		self::$last ??= new \WeakMap();

		$blog   = get_current_blog_id();
		$parent = (int) wp_get_post_parent_id( $variation_id );
		$seen   = self::$last[ $sync ] ?? [];
		$after  = $seen[ $blog ] ?? 0;

		$seen[ $blog ]       = $parent;
		self::$last[ $sync ] = $seen;

		if ( $parent <= 0 || $parent !== $after || array_key_exists( $variation_id, $rows ) ) {
			return [ $variation_id ];
		}

		$kids = $siblings( $parent, false )['children'] ?? [];
		$kids = is_array( $kids ) ? wp_parse_id_list( $kids ) : [];

		return array_slice( array_values( array_unique( array_merge( [ $variation_id ], array_diff( $kids, array_keys( $rows ) ) ) ) ), 0, $batch );
	}

	/**
	 * The children to prime for verified stored answers: each answer's child and, for a parent already answered in this
	 * request, up to $batch children its stored map names.
	 *
	 * @param array<int, array{0: int, 1: mixed, 2: mixed, 3: array<mixed>}> $verify   Parent id => child id, set, state, stored map.
	 * @param array<int, mixed>                                              $answered Parent ids answered in this request (keys).
	 * @param int                                                            $batch    Most extra children per parent.
	 * @return array<int, int>
	 */
	public static function prime( array $verify, array $answered, int $batch ): array {
		$prime = [];

		foreach ( $verify as $parent_id => [ $child_id, , , $stored ] ) {
			$prime[] = $child_id;

			if ( isset( $answered[ $parent_id ] ) && is_array( $stored['m'] ?? null ) ) {
				$prime = array_merge( $prime, array_slice( array_filter( $stored['m'], 'is_int' ), 0, $batch ) );
			}
		}

		return array_values( array_unique( array_filter( $prime ) ) );
	}
}
