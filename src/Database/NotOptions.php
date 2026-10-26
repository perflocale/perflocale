<?php
/**
 * Per-name edits of WordPress's `notoptions` cache.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Takes names out of `notoptions` instead of dropping the whole list, which
 * with a persistent object cache makes the blog's next request re-read every
 * absent option it touches.
 */
final class NotOptions {

	/**
	 * Remove $names from `notoptions`, as add_option() does for its option.
	 *
	 * Edits the shared copy (force read), never this request's possibly older
	 * copy, so options another process created since stay unlisted.
	 *
	 * @param string[] $names Option names.
	 * @return void
	 */
	public static function unlist( array $names ): void {
		$names  = array_fill_keys( $names, true );
		$shared = wp_cache_get( 'notoptions', 'options', true );

		if ( is_array( $shared ) ) {
			if ( [] !== array_intersect_key( $shared, $names ) ) {
				wp_cache_set( 'notoptions', array_diff_key( $shared, $names ), 'options' );
			}

			return;
		}

		// No shared copy (evicted, cache down): a forced miss leaves this request's copy in place.
		$local = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $local ) && [] !== array_intersect_key( $local, $names ) ) {
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
