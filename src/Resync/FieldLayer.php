<?php
/**
 * How the resync consumes the field layer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\Fields\FieldClass;
use PerfLocale\Translation\Fields\FieldMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The resync's use of the field-rule registry: a source's field map and the keys a resync can write (post meta
 * only, the one implemented storage; a key the map does not know is post meta). No save listener.
 */
final class FieldLayer {

	/**
	 * The field map of a source post.
	 *
	 * @param \WP_Post $source The source.
	 * @return FieldMap
	 */
	public static function map_for( \WP_Post $source ): FieldMap {
		return FieldMap::for_post( $source->ID, $source->post_type );
	}

	/**
	 * The keys a resync can write: post meta or unknown to the map; one stored elsewhere is left alone.
	 *
	 * @param FieldMap           $map  The source's map.
	 * @param array<int, string> $keys Keys.
	 * @return list<string>
	 */
	public static function storable_keys( FieldMap $map, array $keys ): array {
		$out = [];

		foreach ( $keys as $key ) {
			$descriptors = $map->descriptors_for( $key );

			if ( [] === $descriptors || FieldClass::STORAGE_POSTMETA === $descriptors[0]->storage ) {
				$out[] = $key;
			}
		}

		return $out;
	}
}
