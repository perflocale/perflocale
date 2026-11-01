<?php
/**
 * The $public_only filter of perflocale()->translations() and translations_many(), loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the translation-group members every visitor can see.
 */
final class PublicTranslations {

	/**
	 * Keep is_post_publicly_viewable() members (published, viewable post type), reading every member in one query.
	 *
	 * Not read_post: core denies it to logged-out visitors.
	 *
	 * @param array<int, array<string, int>> $maps Maps of language slug => post id.
	 * @return array<int, array<string, int>>
	 */
	public static function filter( array $maps ): array {
		_prime_post_caches( array_unique( array_merge( [], ...array_map( 'array_values', array_values( $maps ) ) ) ), false, false );

		return array_map( static fn( array $map ): array => array_filter( $map, 'is_post_publicly_viewable' ), $maps );
	}
}
