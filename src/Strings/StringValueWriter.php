<?php
/**
 * String group repair for the automation string writes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives a string a string group before an automation write (StringTarget) links a language to it.
 */
final class StringValueWriter {

	/**
	 * Mint a fresh string-type translation group and repoint one string at it, only while it names the group read.
	 *
	 * Both statements are checked (callers report counts the operator pays against): a failed repoint reclaims the
	 * group, so the caller gets 0 and counts the row failed; a repair another writer made first is taken.
	 *
	 * @param int $string_id Row in the `strings` table to repair.
	 * @param int $from      The group the caller read.
	 * @return int New group id, the winner's string group, or 0 when the repair could not be completed.
	 */
	public static function mint_group( int $string_id, int $from ): int {
		if ( $string_id <= 0 ) {
			return 0;
		}

		$wpdb = self::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal write; there is no cache to consult and no repository accessor that mints a bare string group.
		$inserted = $wpdb->insert(
			Schema::table( 'translation_groups' ),
			[ 'type' => ObjectType::String->value ],
			[ '%s' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		// Read only after a confirmed INSERT: on a failed one some drivers keep a prior row's id.
		$group_id = $wpdb->insert_id;

		if ( $group_id <= 0 ) {
			return 0;
		}

		$taken = \PerfLocale\Database\Repository\StringGroupAssigner::repoint( $wpdb, $string_id, $from, $group_id );

		if ( 0 === $taken ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reclaiming the group this method just created, by primary key.
			$wpdb->delete( Schema::table( 'translation_groups' ), [ 'id' => $group_id ], [ '%d' ] );
		}

		return $taken;
	}

	/**
	 * WordPress's database object.
	 *
	 * @return \wpdb
	 * @throws \RuntimeException When it is missing.
	 */
	private static function db(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}

		return $wpdb;
	}
}
