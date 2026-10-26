<?php
/**
 * PerfLocale WooCommerce addon: coupon restriction lists.
 *
 * Loaded by PerfLocaleWooCommerce on the first non-empty coupon restriction read of a request.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coupon product and category restriction lists with every translation sibling of each listed ID.
 */
final class PerfLocaleWooCommerceCoupons {

	/**
	 * Sibling IDs by "blog|type|id", for this request (blog-keyed for switch_to_blog()).
	 *
	 * @var array<string, array<int, int>>
	 */
	private static array $siblings = [];

	/**
	 * The IDs, then each one's siblings, without repeats (first occurrence kept).
	 * Uncached IDs are loaded 200 per prime_translations() call, so a long list costs batch reads.
	 *
	 * @param array<int, mixed>           $ids  Saved object IDs.
	 * @param \PerfLocale\Enum\ObjectType $type Post or Term.
	 * @return array<int, int>
	 */
	public static function expand( array $ids, \PerfLocale\Enum\ObjectType $type ): array {
		$ids    = array_map( 'intval', $ids );
		$prefix = ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '|' . $type->value . '|';
		$cold   = [];

		foreach ( $ids as $id ) {
			if ( $id > 0 && ! isset( self::$siblings[ $prefix . $id ] ) ) {
				$cold[ $id ] = $id;
			}
		}

		if ( $cold !== [] ) {
			$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository(
				\PerfLocale\Plugin::get_instance()->get( 'cache' )
			);

			foreach ( array_chunk( $cold, 200 ) as $chunk ) {
				$repo->prime_translations( $type, $chunk );

				foreach ( $chunk as $id ) {
					$list = [];

					foreach ( (array) $repo->get_translations( $id, $type ) as $link ) {
						$list[] = (int) $link->object_id;
					}

					self::$siblings[ $prefix . $id ] = $list;
				}
			}
		}

		$out  = [];
		$seen = [];

		foreach ( $ids as $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$out[]       = $id;
			}
		}

		foreach ( $ids as $id ) {
			foreach ( $id > 0 ? self::$siblings[ $prefix . $id ] : [] as $sibling ) {
				if ( ! isset( $seen[ $sibling ] ) ) {
					$seen[ $sibling ] = true;
					$out[]            = $sibling;
				}
			}
		}

		return $out;
	}
}
