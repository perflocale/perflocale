<?php
/**
 * Whether a recorded import group still fits the set an importer reads.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a migration source-map row before an importer reuses its group.
 *
 * A Replace import or a restore can renumber groups, and a group can gain other items; reusing a stale
 * row would link posts into a term group or move another set's items.
 */
final class SourceMapCheck {

	/**
	 * Whether a recorded group still fits: right type, no outside item in a language the set uses.
	 *
	 * Uncached on purpose: importers read their own writes.
	 *
	 * @param ObjectType      $type     Object type.
	 * @param int             $group_id Recorded group.
	 * @param array<int>      $objects  The set's objects.
	 * @param array<int, int> $langs    Language ids the set uses, as keys.
	 * @param bool            $alone    Single object: nothing else may be in the group.
	 * @return bool
	 */
	public static function group_fits( ObjectType $type, int $group_id, array $objects, array $langs, bool $alone = false ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed read per recorded group, only when a record exists.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT g.type, l.object_id, l.language_id FROM %i g LEFT JOIN %i l ON l.group_id = g.id WHERE g.id = %d', Schema::table( 'translation_groups' ), Schema::table( 'translation_links' ), $group_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) || $rows === [] || ( get_object_vars( $rows[0] )['type'] ?? '' ) !== $type->value ) {
			return false;
		}

		$links = array_filter( $rows, static fn( object $row ): bool => null !== ( get_object_vars( $row )['object_id'] ?? null ) );

		foreach ( $links as $link ) {
			$row = get_object_vars( $link );

			if ( $alone ) {
				return false;
			}

			$object   = is_numeric( $row['object_id'] ?? null ) ? (int) $row['object_id'] : 0;
			$language = is_numeric( $row['language_id'] ?? null ) ? (int) $row['language_id'] : 0;

			if ( ! in_array( $object, $objects, true ) && isset( $langs[ $language ] ) ) {
				return false;
			}
		}

		return true;
	}
}
