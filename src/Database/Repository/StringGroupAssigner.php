<?php
/**
 * A new string group for one string.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write-path half of {@see TranslationGroupRepository::assign_new_string_group()}, loaded on first use (the repository
 * loads on every page).
 *
 * @internal
 */
final class StringGroupAssigner {

	/**
	 * INSERT a string group, then point the string at it. A new id other strings already name is not used (their links
	 * would replace this string's): the group goes to an id above every group id and `strings.group_id`.
	 *
	 * @param \wpdb                $wpdb           Database.
	 * @param int                  $string_id      Row in the `strings` table.
	 * @param bool                 $in_transaction Inside the caller's transaction: nothing follows a failed statement, no reclaim.
	 * @param ConnectionGuard|null $guard          The caller's open unit: every write carries its marker.
	 * @return int The new group id, or 0 when the string could not be moved.
	 */
	public static function assign( \wpdb $wpdb, int $string_id, bool $in_transaction, ?ConnectionGuard $guard = null ): int {
		$groups = Schema::table( 'translation_groups' );
		$cond   = null === $guard ? '' : $guard->condition();

		if ( $string_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-heal write; there is no cache to consult.
		$inserted = null === $guard ? $wpdb->insert( $groups, [ 'type' => ObjectType::String->value ], [ '%s' ] ) : $guard->insert( $groups, [ 'type' => ObjectType::String->value ], [ '%s' ] );

		if ( ! $inserted ) {
			return 0;
		}

		// Read only after a confirmed INSERT: on a failed one insert_id can
		// still hold a prior row's id.
		$group_id = (int) $wpdb->insert_id;

		if ( $group_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Self-heal reads on the primary key and the group_id index; identifiers are bound with %i.
		$claimed = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d LIMIT 1', Schema::table( 'strings' ), $group_id ) );

		// An unread claim is no free id; inside a transaction nothing follows a failed statement.
		if ( self::failed( $wpdb ) ) {
			if ( ! $in_transaction ) {
				self::delete_group( $wpdb, $group_id, $cond );
			}

			return 0;
		}

		if ( null !== $claimed && '' !== $claimed ) {
			if ( false === self::delete_group( $wpdb, $group_id, $cond ) ) {
				return 0;
			}

			$max_group = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $groups ) );

			if ( self::failed( $wpdb ) ) {
				return 0;
			}

			$max_named = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(group_id) FROM %i', Schema::table( 'strings' ) ) );

			if ( self::failed( $wpdb ) ) {
				return 0;
			}

			$group_id = 1 + max( (int) $max_group, (int) $max_named );

			$row = [
				'id'   => $group_id,
				'type' => ObjectType::String->value,
			];

			if ( ! ( null === $guard ? $wpdb->insert( $groups, $row, [ '%d', '%s' ] ) : $guard->insert( $groups, $row, [ '%d', '%s' ] ) ) ) {
				return 0;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Self-heal write, keyed on the primary key; the guard's condition is built by the guard.
		$updated = '' === $cond
			? $wpdb->update( Schema::table( 'strings' ), [ 'group_id' => $group_id ], [ 'id' => $string_id ], [ '%d' ], [ '%d' ] )
			: $wpdb->query( $wpdb->prepare( 'UPDATE %i SET group_id = %d WHERE id = %d', Schema::table( 'strings' ), $group_id, $string_id ) . ' AND ' . $cond );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_int( $updated ) || $updated < 1 ) {
			if ( ! ( false === $updated && $in_transaction ) ) {
				self::delete_group( $wpdb, $group_id, $cond );
			}

			return 0;
		}

		TranslationGroupRepository::remember_string_group( $group_id );

		return $group_id;
	}

	/**
	 * Delete the group this method created, by primary key; with $cond, only on the unit's connection.
	 *
	 * @param \wpdb  $wpdb     Database.
	 * @param int    $group_id Group.
	 * @param string $cond     The guard's condition, or ''.
	 * @return int|bool Rows deleted; false on a database error.
	 */
	private static function delete_group( \wpdb $wpdb, int $group_id, string $cond ): int|bool {
		$groups = Schema::table( 'translation_groups' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reclaiming the group this method just created, by primary key; the guard's condition is built by the guard.
		return '' === $cond
			? $wpdb->delete( $groups, [ 'id' => $group_id ], [ '%d' ] )
			: $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $groups, $group_id ) . ' AND ' . $cond );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}



	/**
	 * Whether the last statement failed.
	 *
	 * @phpstan-impure
	 * @param \wpdb $wpdb Database.
	 * @return bool
	 */
	private static function failed( \wpdb $wpdb ): bool {
		return '' !== $wpdb->last_error;
	}
}
