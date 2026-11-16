<?php
/**
 * The keys a translation keeps apart from its source.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The override marker: post meta of a translation, one row per `reference` or `value` key set apart from the
 * source, so a source save does not write it. One row per key so concurrent writers of different keys cannot
 * overwrite each other; callers decide when a key is listed or dropped.
 */
final class FieldOverrides {

	/** The post meta key. */
	public const META = '_perflocale_field_override';

	/**
	 * The keys a post's marker lists, in row order, each once; a key of digits stays a string.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, string>
	 */
	public static function keys( int $post_id ): array {
		$keys = [];

		if ( $post_id <= 0 ) {
			return $keys;
		}

		$seen = [];

		foreach ( (array) get_post_meta( $post_id, self::META, false ) as $key ) {
			if ( is_string( $key ) && '' !== $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$keys[]       = $key;
			}
		}

		return $keys;
	}

	/**
	 * Whether a post's marker lists a key.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Concrete key.
	 * @return bool
	 */
	public static function has( int $post_id, string $key ): bool {
		return in_array( $key, self::keys( $post_id ), true );
	}

	/**
	 * List a key in a post's marker, or drop it with every duplicate row. Writes only on change.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $key      Concrete key.
	 * @param bool   $override True to list the key, false to drop it.
	 * @return bool Whether a row was written or deleted.
	 */
	public static function set( int $post_id, string $key, bool $override ): bool {
		if ( $post_id <= 0 || '' === $key || self::has( $post_id, $key ) === $override ) {
			return false;
		}

		if ( $override ) {
			return false !== add_post_meta( $post_id, self::META, wp_slash( $key ) );
		}

		return delete_post_meta( $post_id, self::META, wp_slash( $key ) );
	}
}
