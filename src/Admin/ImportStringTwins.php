<?php
/**
 * Replace import: the rows of skipped twin strings and of refused strings, after the tables.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Admin;

use PerfLocale\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Loaded only when a Replace skips a held pair's twin or refused a strings row (ImportWorkTable TWIN, REFUSED).
 *
 * A twin's translations, links and pending rows join the kept row; in a language both have, the served row's value wins.
 * A refused row's translations, links, pending rows and empty group are removed. Every write carries the guard condition.
 */
final class ImportStringTwins {

	/**
	 * What a failed statement throws (the import's sentence).
	 *
	 * @var string
	 */
	private static string $failed = '';

	/**
	 * The guard condition every write carries, with its leading AND ('' outside a transaction).
	 *
	 * @var string
	 */
	private static string $guarded = '';

	/**
	 * Settle both kinds, a work-table page at a time.
	 *
	 * @param ImportWorkTable $work   The import's work table.
	 * @param string          $guard  The transaction's connection-guard condition ('' outside one).
	 * @param string          $failed What a failed statement throws.
	 * @return void
	 * @throws \RuntimeException When a statement fails.
	 */
	public static function settle( ImportWorkTable $work, string $guard, string $failed ): void {
		self::$guarded = '' === $guard ? '' : ' AND ' . $guard;
		self::$failed  = $failed;

		foreach ( $work->pages( ImportWorkTable::TWIN ) as $page ) {
			$served = $work->get( ImportWorkTable::TWIN_SERVED, $page );
			$groups = $work->get( ImportWorkTable::TWIN_GROUP, $page );

			foreach ( $work->get( ImportWorkTable::TWIN, $page ) as $twin => $kept ) {
				self::join( $twin, $kept, isset( $served[ $twin ] ) );
				self::drop_groups( [ $groups[ $twin ] ?? 0 ] );
			}
		}

		foreach ( $work->pages( ImportWorkTable::REFUSED ) as $page ) {
			$in = self::in( $page );

			self::write( 'DELETE FROM %i WHERE string_id IN (' . $in . ')', array_merge( [ Schema::table( 'string_translations' ) ], $page ) );
			self::write( 'DELETE FROM %i WHERE type = %s AND object_id IN (' . $in . ')', array_merge( [ Schema::table( 'translation_links' ), 'string' ], $page ) );
			self::write( 'DELETE FROM %i WHERE object_type = %s AND object_id IN (' . $in . ')', array_merge( [ Schema::table( 'pending_changes' ), 'string' ], $page ) );
			self::drop_groups( array_values( $work->get( ImportWorkTable::REFUSED, $page ) ) );
		}
	}

	/**
	 * The id of the row holding a hash, 0 for none. Only during a strings Replace: the table was cleared, so every row
	 * holds the hash this import computed and no other-form row can be missed (no StrIdentMigration::adopt() needed).
	 *
	 * @param string $table The strings table.
	 * @param string $hash  The computed hash.
	 * @return int
	 */
	public static function holder( string $table, string $hash ): int {
		$wpdb = Typed::wpdb();

		return Typed::int( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE original_hash = %s LIMIT 1', $table, $hash ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Inside the import transaction.
	}

	/**
	 * Move a twin's rows to the kept row: a language the kept row lacks moves; in one both have, a served twin's value wins.
	 *
	 * @param int  $twin   The twin's (file) id.
	 * @param int  $kept   The kept row's id.
	 * @param bool $served Whether the twin is the row visitors were served.
	 * @return void
	 */
	private static function join( int $twin, int $kept, bool $served ): void {
		$st    = Schema::table( 'string_translations' );
		$links = Schema::table( 'translation_links' );
		$have  = [];

		foreach ( self::read( 'SELECT string_id, language_id, translation, extra_forms FROM %i WHERE string_id IN (%d, %d)', [ $st, $twin, $kept ] ) as $row ) {
			$have[ Typed::int( $row['string_id'] ?? null ) ][ Typed::int( $row['language_id'] ?? null ) ] = $row;
		}

		foreach ( $have[ $twin ] ?? [] as $lang => $row ) {
			if ( ! isset( $have[ $kept ][ $lang ] ) ) {
				// SQLite ignores ON UPDATE CURRENT_TIMESTAMP: Schema::touch_write_time() moves updated_at there ('' elsewhere).
				$touch = Schema::touch_write_time( [ [ 'string_id', '%d', 'int' ] ] );
				self::write( 'UPDATE %i SET string_id = %d' . $touch . ' WHERE string_id = %d AND language_id = %d', array_merge( [ $st, $kept ], '' === $touch ? [] : [ $kept ], [ $twin, $lang ] ) );
			} elseif ( $served ) {
				$text  = Typed::str( $row['translation'] ?? '' );
				$forms = $row['extra_forms'] ?? null;
				$touch = Schema::touch_write_time( [ [ 'translation', '%s', 'text' ] ] );
				$set   = is_string( $forms ) ? 'translation = %s, extra_forms = %s' : 'translation = %s, extra_forms = NULL';
				$args  = is_string( $forms ) ? [ $st, $text, $forms ] : [ $st, $text ];

				self::write( 'UPDATE %i SET ' . $set . $touch . ' WHERE string_id = %d AND language_id = %d', array_merge( $args, '' === $touch ? [] : [ $text ], [ $kept, $lang ] ) );
			}
		}

		self::write( 'DELETE FROM %i WHERE string_id IN (%d)', [ $st, $twin ] );

		$linked = [];
		$group  = Typed::int( self::read( 'SELECT group_id FROM %i WHERE id = %d', [ Schema::table( 'strings' ), $kept ] )[0]['group_id'] ?? null );

		foreach ( self::read( 'SELECT id, language_id, object_id FROM %i WHERE type = %s AND object_id IN (%d, %d)', [ $links, 'string', $twin, $kept ] ) as $row ) {
			$linked[ Typed::int( $row['object_id'] ?? null ) ][ Typed::int( $row['language_id'] ?? null ) ] = Typed::int( $row['id'] ?? null );
		}

		foreach ( $linked[ $twin ] ?? [] as $lang => $id ) {
			if ( ! isset( $linked[ $kept ][ $lang ] ) && $group > 0 ) {
				$touch = Schema::touch_write_time( [ [ 'object_id', '%d', 'int' ], [ 'group_id', '%d', 'int' ] ] );
				self::write( 'UPDATE %i SET object_id = %d, group_id = %d' . $touch . ' WHERE id = %d', array_merge( [ $links, $kept, $group ], '' === $touch ? [] : [ $kept, $group ], [ $id ] ) );
			}
		}

		self::write( 'DELETE FROM %i WHERE type = %s AND object_id IN (%d)', [ $links, 'string', $twin ] );
		self::write( 'UPDATE %i SET object_id = %d WHERE object_type = %s AND object_id = %d', [ Schema::table( 'pending_changes' ), $kept, 'string', $twin ] );
	}

	/**
	 * Delete the given string groups that no strings row uses (a link left in one is reaped after the import).
	 *
	 * @param array<int> $ids Group ids.
	 * @return void
	 */
	private static function drop_groups( array $ids ): void {
		$ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );

		if ( [] === $ids ) {
			return;
		}

		$used = array_column( self::read( 'SELECT group_id FROM %i WHERE group_id IN (' . self::in( $ids ) . ')', array_merge( [ Schema::table( 'strings' ) ], $ids ) ), 'group_id' );
		$gone = array_values( array_diff( $ids, array_map( static fn( $id ): int => Typed::int( $id ), $used ) ) );

		if ( [] !== $gone ) {
			self::write( 'DELETE FROM %i WHERE type = %s AND id IN (' . self::in( $gone ) . ')', array_merge( [ Schema::table( 'translation_groups' ), 'string' ], $gone ) );
		}
	}

	/**
	 * One %d placeholder per id.
	 *
	 * @param array<int> $ids Ids.
	 * @return literal-string
	 */
	private static function in( array $ids ): string {
		return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	}

	/**
	 * Rows of a read; a failed read stops the import (inside its transaction, the caller rolls back).
	 *
	 * @param literal-string    $sql  SQL with placeholders.
	 * @param array<int, mixed> $args Values.
	 * @return list<array<string, mixed>>
	 * @throws \RuntimeException When the read fails.
	 */
	private static function read( string $sql, array $args ): array {
		$wpdb = Typed::wpdb();
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared from placeholders.

		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( self::$failed ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
		}

		return Typed::rows( $rows );
	}

	/**
	 * One write with the guard condition appended; a failure stops the import.
	 *
	 * @param literal-string    $sql  SQL with placeholders, ending in its WHERE conditions.
	 * @param array<int, mixed> $args Values.
	 * @return void
	 * @throws \RuntimeException When the write fails.
	 */
	private static function write( string $sql, array $args ): void {
		$wpdb     = Typed::wpdb();
		$prepared = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds placeholders only.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; the guard's condition is the guard's own fixed text.
		if ( ! is_string( $prepared ) || false === $wpdb->query( $prepared . self::$guarded ) ) {
			throw new \RuntimeException( self::$failed ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
		}
	}
}
