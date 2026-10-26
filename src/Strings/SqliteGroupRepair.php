<?php
/**
 * Repair of string translation groups on SQLite sites, in slices.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Strings;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every string its own translation group again on SQLite sites, in four phases of one-transaction slices.
 *
 * The SQLite driver rolls back on one failed statement and autocommits the rest, so no statement may fail.
 * Phases, slices and checks: docs-ai/11-code-rationale.md, "SqliteGroupRepair: phases and slices".
 */
final class SqliteGroupRepair {

	/**
	 * Option recording that the repair finished on this site.
	 */
	public const DONE_OPTION = 'perflocale_sqlite_string_groups_repaired';

	/**
	 * Phase, cursor and counts of an unfinished repair (JSON, not autoloaded), written in each slice's transaction.
	 */
	public const PROGRESS_OPTION = 'perflocale_sqlite_string_groups_repair';

	/**
	 * Lock name: one slice at a time, from the job or WP-CLI.
	 */
	private const LOCK = 'sqlite_string_groups';

	/**
	 * Items per slice by default.
	 */
	private const BATCH = 500;

	/**
	 * Statements the chained moves of one phase 2 slice may run, per item: bounds the slice's write lock.
	 */
	private const CHAIN_STATEMENTS_PER_ITEM = 4;

	/**
	 * The phases in order, with the percent reported at each one's start.
	 */
	private const PHASES = [
		'groups'       => 0,
		'links'        => 35,
		'unlinked'     => 60,
		'empty_groups' => 90,
		'done'         => 100,
	];

	/**
	 * The progress key holding the largest id of the table each phase walks.
	 */
	private const PHASE_SCALE = [
		'groups'       => 'max_string',
		'links'        => 'max_link',
		'unlinked'     => 'max_string',
		'empty_groups' => 'max_group',
	];

	/**
	 * Counts a slice reports, cumulative over the repair.
	 */
	private const COUNTS = [ 'groups_created', 'links_moved', 'links_written', 'groups_deleted', 'links_left', 'pairs_skipped' ];

	/**
	 * Whether the site runs on the SQLite Database Integration.
	 *
	 * @return bool
	 */
	public static function is_sqlite(): bool {
		return defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' );
	}

	/**
	 * Whether the site is on SQLite and the repair has not finished yet.
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return self::is_sqlite() && false === get_option( self::DONE_OPTION, false );
	}

	/**
	 * Items (strings, links, translated pairs or groups) one slice handles.
	 *
	 * @return int
	 */
	public static function batch_size(): int {
		/**
		 * Items the SQLite string-group repair handles per slice.
		 *
		 * Each slice is one transaction: smaller means a shorter write lock, larger fewer commits. Clamped to 50-5000.
		 *
		 * @hook  perflocale/strings/sqlite_repair_batch_size
		 * @since 1.0.7.3
		 *
		 * @param int $size Default 500.
		 */
		$size = (int) apply_filters( 'perflocale/strings/sqlite_repair_batch_size', self::BATCH );

		return max( 50, min( 5000, $size ) );
	}

	/**
	 * Run one slice of the repair.
	 *
	 * Status 'more' (work left), 'complete' (this call wrote DONE_OPTION), 'busy' (locked) or 'not_needed'.
	 *
	 * @param bool $foreground_files In files mode, compile the files here after the last slice (WP-CLI).
	 * @return array{status: string, percent: int, groups_created: int, links_moved: int, links_written: int, groups_deleted: int, links_left: int, pairs_skipped: int}
	 *
	 * @throws \RuntimeException When a read or write failed: the slice was rolled back.
	 */
	public static function run_slice( bool $foreground_files = false ): array {
		global $wpdb;

		if ( ! self::applies() || ! $wpdb instanceof \wpdb ) {
			return self::outcome( 'not_needed', [] );
		}

		if ( ! Lock::acquire( self::LOCK, 300 ) ) {
			return self::outcome( 'busy', [] );
		}

		try {
			$progress = self::slice( $wpdb, self::batch_size() );

			if ( null === $progress ) {
				return self::outcome( 'not_needed', [] );
			}

			if ( 'done' !== $progress['phase'] ) {
				return self::outcome( 'more', $progress );
			}

			self::verify( $wpdb, self::int( $progress['links_left'] ?? 0 ) );
			self::complete( $foreground_files );

			return self::outcome( 'complete', $progress );
		} finally {
			Lock::release( self::LOCK );
		}
	}

	/**
	 * One slice: read the progress, do one batch of the current phase, store the progress, commit.
	 *
	 * @param \wpdb $wpdb Database.
	 * @param int   $size Items per slice.
	 * @return array<string, mixed>|null The progress after the slice, or null when already done.
	 *
	 * @throws \RuntimeException On the first failed statement (rolled back).
	 */
	private static function slice( \wpdb $wpdb, int $size ): ?array {
		$errors = Schema::query_error_count();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A transaction of the repair: reads must see committed rows, writes are checked one by one.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			self::fail();
		}

		try {
			$stored = (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s)', $wpdb->options, self::DONE_OPTION, self::PROGRESS_OPTION ),
				ARRAY_A
			);
			self::check_read( $wpdb );

			$values = [];

			foreach ( $stored as $row ) {
				$values[ self::str( $row['option_name'] ?? '' ) ] = self::str( $row['option_value'] ?? '' );
			}

			if ( isset( $values[ self::DONE_OPTION ] ) ) {
				$wpdb->query( 'ROLLBACK' );

				return null;
			}

			$exists   = isset( $values[ self::PROGRESS_OPTION ] );
			$progress = ( $exists ? self::decode( $values[ self::PROGRESS_OPTION ] ) : null ) ?? self::start( $wpdb );

			// The last slice committed but complete() did not run: nothing is left to write.
			if ( 'done' === $progress['phase'] ) {
				$wpdb->query( 'ROLLBACK' );

				return $progress;
			}

			switch ( $progress['phase'] ) {
				case 'groups':
					$progress = self::phase_groups( $wpdb, $progress, $size );
					break;
				case 'links':
					$progress = self::phase_links( $wpdb, $progress, $size );
					break;
				case 'unlinked':
					$progress = self::phase_unlinked( $wpdb, $progress, $size );
					break;
				case 'empty_groups':
					$progress = self::phase_empty_groups( $wpdb, $progress, $size );
					break;
			}

			$json = (string) wp_json_encode( $progress );

			$written = $exists
				? $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $json, self::PROGRESS_OPTION ) ?? '' )
				: $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::PROGRESS_OPTION, $json, 'no' ) ?? '' );

			self::check_write( $written, 1 );

			if ( Schema::transaction_lost( $errors ) || false === $wpdb->query( 'COMMIT' ) ) {
				self::fail();
			}
		} catch ( \RuntimeException $e ) {
			// On SQLite a failed statement already ended the transaction; elsewhere this ends it.
			$wpdb->query( 'ROLLBACK' );

			throw $e;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			self::fail();
		} finally {
			self::forget_options();
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $progress;
	}

	/**
	 * The progress of a new repair: phase 1 and the largest ids it walks (max_group bounds phase 4).
	 *
	 * @param \wpdb $wpdb Database.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the read failed.
	 */
	private static function start( \wpdb $wpdb ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Inside the repair's transaction.
		$max = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ( SELECT MAX(id) FROM %i ) AS max_group, ( SELECT MAX(id) FROM %i ) AS max_string, ( SELECT MAX(id) FROM %i ) AS max_link',
				Schema::table( 'translation_groups' ),
				Schema::table( 'strings' ),
				Schema::table( 'translation_links' )
			),
			ARRAY_A
		);
		self::check_read( $wpdb );

		$max      = is_array( $max ) ? $max : [];
		$progress = [
			'phase'      => 'groups',
			'after'      => 0,
			'after_lang' => 0,
			'max_group'  => self::int( $max['max_group'] ?? 0 ),
			'max_string' => self::int( $max['max_string'] ?? 0 ),
			'max_link'   => self::int( $max['max_link'] ?? 0 ),
		];

		foreach ( self::COUNTS as $count ) {
			$progress[ $count ] = 0;
		}

		return $progress;
	}

	/**
	 * Phase 1: a new string group for each of the next strings that need one.
	 *
	 * One INSERT under this transaction's write lock, so the ids above the MAX(id) read before it are its rows.
	 *
	 * @param \wpdb                $wpdb     Database.
	 * @param array<string, mixed> $progress Progress.
	 * @param int                  $size     Items per slice.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException On a failed read or write.
	 */
	private static function phase_groups( \wpdb $wpdb, array $progress, int $size ): array {
		$strings = Schema::table( 'strings' );
		$groups  = Schema::table( 'translation_groups' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Inside the repair's transaction; lists are built from placeholders only.
		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.id FROM %i s LEFT JOIN %i g ON g.id = s.group_id
				WHERE s.id > %d AND ( g.id IS NULL OR g.type <> 'string'
				OR EXISTS ( SELECT 1 FROM %i o WHERE o.group_id = s.group_id AND o.id < s.id ) )
				ORDER BY s.id LIMIT %d",
				$strings,
				$groups,
				self::int( $progress['after'] ?? 0 ),
				$strings,
				$size
			)
		);
		self::check_read( $wpdb );

		$ids   = self::ints( $rows );
		$count = count( $ids );

		if ( $count > 0 ) {
			$before = self::int( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $groups ) ) );
			self::check_read( $wpdb );

			$values = implode( ', ', array_fill( 0, $count, "('string')" ) );
			self::check_write( $wpdb->query( $wpdb->prepare( "INSERT INTO %i (type) VALUES {$values}", $groups ) ?? '' ), $count );

			$new = self::ints( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d ORDER BY id', $groups, $before ) ) );
			self::check_read( $wpdb );

			if ( count( $new ) !== $count ) {
				self::fail();
			}

			$cases = implode( ' ', array_fill( 0, $count, 'WHEN %d THEN %d' ) );
			$in    = implode( ', ', array_fill( 0, $count, '%d' ) );
			$pairs = [];

			foreach ( $ids as $i => $string_id ) {
				$pairs[] = $string_id;
				$pairs[] = $new[ $i ];
			}

			self::check_write( $wpdb->query( $wpdb->prepare( "UPDATE %i SET group_id = CASE id {$cases} END WHERE id IN ({$in})", $strings, ...$pairs, ...$ids ) ?? '' ), $count );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$progress['groups_created'] = self::int( $progress['groups_created'] ?? 0 ) + $count;

		return count( $rows ) < $size ? self::next_phase( $progress ) : array_merge( $progress, [ 'after' => self::int( end( $rows ) ) ] );
	}

	/**
	 * Phase 2: move the next misplaced string links into their own string's group.
	 *
	 * Only group_id changes; `updated_at = updated_at` keeps MySQL's ON UPDATE from touching it.
	 * Free places move in one statement, held ones through move_chain() within the slice's budget.
	 *
	 * @param \wpdb                $wpdb     Database.
	 * @param array<string, mixed> $progress Progress.
	 * @param int                  $size     Items per slice.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException On a failed read or write.
	 */
	private static function phase_links( \wpdb $wpdb, array $progress, int $size ): array {
		$links = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Inside the repair's transaction; lists are built from placeholders only.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.group_id, l.language_id, s.group_id AS own_group
				FROM %i l JOIN %i s ON s.id = l.object_id
				WHERE l.type = 'string' AND l.id > %d AND l.group_id <> s.group_id
				ORDER BY l.id LIMIT %d",
				$links,
				Schema::table( 'strings' ),
				self::int( $progress['after'] ?? 0 ),
				$size
			),
			ARRAY_A
		);
		self::check_read( $wpdb );

		$moved = 0;
		$left  = 0;
		$last  = 0;
		$stop  = 0;

		if ( [] !== $rows ) {
			$targets = array_values( array_unique( array_map( static fn( $row ): int => self::int( $row['own_group'] ?? 0 ), $rows ) ) );
			$in      = implode( ', ', array_fill( 0, count( $targets ), '%d' ) );
			$held    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT group_id, language_id FROM %i WHERE group_id IN ({$in})", $links, ...$targets ), ARRAY_A );
			self::check_read( $wpdb );

			$taken = self::places( $held );
			$cases = [];
			$ids   = [];
			$later = [];

			foreach ( $rows as $row ) {
				$last  = self::int( $row['id'] ?? 0 );
				$own   = self::int( $row['own_group'] ?? 0 );
				$place = $own . ':' . self::int( $row['language_id'] ?? 0 );

				if ( isset( $taken[ $place ] ) || $own <= 0 ) {
					$later[] = $row;
					continue;
				}

				$taken[ $place ] = true;
				array_push( $cases, $last, $own );
				$ids[] = $last;
			}

			$count = count( $ids );

			if ( $count > 0 ) {
				$when = implode( ' ', array_fill( 0, $count, 'WHEN %d THEN %d' ) );
				$in   = implode( ', ', array_fill( 0, $count, '%d' ) );
				self::check_write( $wpdb->query( $wpdb->prepare( "UPDATE %i SET group_id = CASE id {$when} END, updated_at = updated_at WHERE id IN ({$in})", $links, ...$cases, ...$ids ) ?? '' ), $count );
				$moved += $count;
			}

			$done   = [];
			$budget = self::CHAIN_STATEMENTS_PER_ITEM * $size;

			foreach ( $later as $row ) {
				if ( isset( $done[ self::int( $row['id'] ?? 0 ) ] ) ) {
					continue;
				}

				$chain = self::int( $row['own_group'] ?? 0 ) > 0 ? self::move_chain( $wpdb, $row, $size, $budget ) : [];

				if ( null === $chain ) {
					$stop = self::int( $row['id'] ?? 0 );
					break;
				}

				if ( [] === $chain ) {
					++$left;
					continue;
				}

				foreach ( $chain as $chain_id ) {
					$done[ $chain_id ] = true;
				}

				$moved += count( $chain );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$progress['links_moved'] = self::int( $progress['links_moved'] ?? 0 ) + $moved;
		$progress['links_left']  = self::int( $progress['links_left'] ?? 0 ) + $left;

		// Budget spent: restart at the first link not handled. Moved links sit in their own group; left ones have lower ids.
		if ( $stop > 0 ) {
			return array_merge( $progress, [ 'after' => $stop - 1 ] );
		}

		return count( $rows ) < $size ? self::next_phase( $progress ) : array_merge( $progress, [ 'after' => $last ] );
	}

	/**
	 * Move a misplaced link whose place is held, with the links in the way.
	 *
	 * Moves from a free place back to the first link; a ring parks the first link in a temporary group.
	 * The writes are taken from the budget together, before the first one, so a chain moves whole or not at all.
	 *
	 * @param \wpdb        $wpdb   Database.
	 * @param array<mixed> $first  The link (id, group_id, language_id, own_group).
	 * @param int          $limit  Most links in one chain.
	 * @param int          $budget Statements the slice may still run.
	 * @return array<int, int>|null Ids of the links moved; empty when the first link stays (held place or too long);
	 *                              null when the budget ran out (nothing written).
	 *
	 * @throws \RuntimeException On a failed read or write.
	 */
	private static function move_chain( \wpdb $wpdb, array $first, int $limit, int &$budget ): ?array {
		$links    = Schema::table( 'translation_links' );
		$groups   = Schema::table( 'translation_groups' );
		$first_id = self::int( $first['id'] ?? 0 );
		$lang     = self::int( $first['language_id'] ?? 0 );
		$place    = self::int( $first['own_group'] ?? 0 );
		$path     = [ $first_id => $place ];
		$ring     = false;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Inside the repair's transaction.
		while ( true ) {
			if ( count( $path ) > $limit ) {
				return [];
			}

			if ( $budget <= 0 ) {
				return null;
			}

			--$budget;

			$holder = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT l.id, l.group_id, l.type, s.group_id AS own_group FROM %i l LEFT JOIN %i s ON s.id = l.object_id
					WHERE l.group_id = %d AND l.language_id = %d',
					$links,
					Schema::table( 'strings' ),
					$place,
					$lang
				),
				ARRAY_A
			);
			self::check_read( $wpdb );

			if ( ! is_array( $holder ) ) {
				break;
			}

			$holder_id = self::int( $holder['id'] ?? 0 );

			if ( $holder_id === $first_id ) {
				$ring = true;
				break;
			}

			$own = self::int( $holder['own_group'] ?? 0 );

			if ( 'string' !== ( $holder['type'] ?? '' ) || $own <= 0 || $own === self::int( $holder['group_id'] ?? 0 ) || isset( $path[ $holder_id ] ) ) {
				return [];
			}

			$path[ $holder_id ] = $own;
			$place              = $own;
		}

		$writes = count( $path ) + ( $ring ? 3 : 0 );

		if ( $writes > $budget ) {
			return null;
		}

		$budget -= $writes;
		$parked  = 0;

		if ( $ring ) {
			self::check_write( $wpdb->insert( $groups, [ 'type' => 'string' ], [ '%s' ] ), 1 );
			$parked = (int) $wpdb->insert_id;

			if ( $parked <= 0 ) {
				self::fail();
			}

			self::check_write( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET group_id = %d, updated_at = updated_at WHERE id = %d', $links, $parked, $first_id ) ?? '' ), 1 );
		}

		foreach ( array_reverse( $path, true ) as $id => $own ) {
			self::check_write( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET group_id = %d, updated_at = updated_at WHERE id = %d', $links, $own, $id ) ?? '' ), 1 );
		}

		if ( $parked > 0 ) {
			self::check_write( $wpdb->delete( $groups, [ 'id' => $parked ], [ '%d' ] ), 1 );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_keys( $path );
	}

	/**
	 * Phase 3: a link in the string's group for each of the next translated (string, language) pairs without one.
	 *
	 * @param \wpdb                $wpdb     Database.
	 * @param array<string, mixed> $progress Progress.
	 * @param int                  $size     Items per slice.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException On a failed read or write.
	 */
	private static function phase_unlinked( \wpdb $wpdb, array $progress, int $size ): array {
		$links = Schema::table( 'translation_links' );
		$after = self::int( $progress['after'] ?? 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Inside the repair's transaction; lists and value rows are built from placeholders only.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.string_id, t.language_id, s.group_id FROM %i t JOIN %i s ON s.id = t.string_id
				WHERE ( t.string_id > %d OR ( t.string_id = %d AND t.language_id > %d ) ) AND t.translation <> ''
				AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.type = 'string' AND l.object_id = t.string_id AND l.language_id = t.language_id )
				ORDER BY t.string_id, t.language_id LIMIT %d",
				Schema::table( 'string_translations' ),
				Schema::table( 'strings' ),
				$after,
				$after,
				self::int( $progress['after_lang'] ?? 0 ),
				$links,
				$size
			),
			ARRAY_A
		);
		self::check_read( $wpdb );

		$plan = [];
		$last = [ 0, 0 ];

		if ( [] !== $rows ) {
			$groups = array_values( array_unique( array_map( static fn( $row ): int => self::int( $row['group_id'] ?? 0 ), $rows ) ) );
			$in     = implode( ', ', array_fill( 0, count( $groups ), '%d' ) );
			$held   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT group_id, language_id FROM %i WHERE group_id IN ({$in})", $links, ...$groups ), ARRAY_A );
			self::check_read( $wpdb );

			$taken = self::places( $held );

			foreach ( $rows as $row ) {
				$group = self::int( $row['group_id'] ?? 0 );
				$lang  = self::int( $row['language_id'] ?? 0 );
				$last  = [ self::int( $row['string_id'] ?? 0 ), $lang ];
				$place = $group . ':' . $lang;

				if ( $group <= 0 || $last[0] <= 0 || isset( $taken[ $place ] ) ) {
					continue;
				}

				$taken[ $place ] = true;
				array_push( $plan, $group, $last[0], $lang );
			}

			$count = intdiv( count( $plan ), 3 );

			if ( $count > 0 ) {
				$values = implode( ', ', array_fill( 0, $count, "(%d, %d, %d, 'string', 'translated', 'manual')" ) );
				self::check_write( $wpdb->query( $wpdb->prepare( "INSERT INTO %i (group_id, object_id, language_id, type, status, source) VALUES {$values}", $links, ...$plan ) ?? '' ), $count );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$written                   = intdiv( count( $plan ), 3 );
		$progress['links_written'] = self::int( $progress['links_written'] ?? 0 ) + $written;
		$progress['pairs_skipped'] = self::int( $progress['pairs_skipped'] ?? 0 ) + count( $rows ) - $written;

		return count( $rows ) < $size ? self::next_phase( $progress ) : array_merge(
			$progress,
			[
				'after'      => $last[0],
				'after_lang' => $last[1],
			]
		);
	}

	/**
	 * Phase 4: delete the next unused string groups, only up to max_group.
	 *
	 * A scan running alongside may hold a newer group it has not linked yet.
	 *
	 * @param \wpdb                $wpdb     Database.
	 * @param array<string, mixed> $progress Progress.
	 * @param int                  $size     Items per slice.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException On a failed read or write.
	 */
	private static function phase_empty_groups( \wpdb $wpdb, array $progress, int $size ): array {
		$groups = Schema::table( 'translation_groups' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Inside the repair's transaction; lists are built from placeholders only.
		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT g.id FROM %i g WHERE g.type = 'string' AND g.id > %d AND g.id <= %d
				AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )
				AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.group_id = g.id )
				ORDER BY g.id LIMIT %d",
				$groups,
				self::int( $progress['after'] ?? 0 ),
				self::int( $progress['max_group'] ?? 0 ),
				Schema::table( 'strings' ),
				Schema::table( 'translation_links' ),
				$size
			)
		);
		self::check_read( $wpdb );

		$ids   = self::ints( $rows );
		$count = count( $ids );

		if ( $count > 0 ) {
			$in = implode( ', ', array_fill( 0, $count, '%d' ) );
			self::check_write( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$in})", $groups, ...$ids ) ?? '' ), $count );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$progress['groups_deleted'] = self::int( $progress['groups_deleted'] ?? 0 ) + $count;

		return count( $rows ) < $size ? self::next_phase( $progress ) : array_merge( $progress, [ 'after' => self::int( end( $rows ) ) ] );
	}

	/**
	 * The (group, language) places some links hold, as "group:language" keys.
	 *
	 * @param array<int|string, mixed> $rows Rows with group_id and language_id.
	 * @return array<string, true>
	 */
	private static function places( array $rows ): array {
		$places = [];

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$places[ self::int( $row['group_id'] ?? 0 ) . ':' . self::int( $row['language_id'] ?? 0 ) ] = true;
			}
		}

		return $places;
	}

	/**
	 * The progress moved to the start of the next phase.
	 *
	 * @param array<string, mixed> $progress Progress.
	 * @return array<string, mixed>
	 */
	private static function next_phase( array $progress ): array {
		$phases = array_keys( self::PHASES );
		$at     = array_search( $progress['phase'] ?? '', $phases, true );

		$progress['phase']      = is_int( $at ) && isset( $phases[ $at + 1 ] ) ? $phases[ $at + 1 ] : 'done';
		$progress['after']      = 0;
		$progress['after_lang'] = 0;

		return $progress;
	}

	/**
	 * The check of the committed rows that must pass before the repair is recorded as done.
	 *
	 * Work left drops the progress (restart from phase 1); a failed read keeps it, so the next run reads again.
	 *
	 * @param \wpdb $wpdb Database.
	 * @param int   $left Links phase 2 left where they were.
	 * @return void
	 *
	 * @throws \RuntimeException When the read failed or found work left.
	 */
	private static function verify( \wpdb $wpdb, int $left ): void {
		$strings = Schema::table( 'strings' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must read the committed rows.
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				( SELECT COUNT(*) FROM %i s LEFT JOIN %i g ON g.id = s.group_id
					WHERE g.id IS NULL OR g.type <> 'string'
					OR EXISTS ( SELECT 1 FROM %i o WHERE o.group_id = s.group_id AND o.id < s.id ) ) AS needy,
				( SELECT COUNT(*) FROM %i l JOIN %i s ON s.id = l.object_id
					WHERE l.type = 'string' AND l.group_id <> s.group_id ) AS misplaced",
				$strings,
				Schema::table( 'translation_groups' ),
				$strings,
				Schema::table( 'translation_links' ),
				$strings
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::check_read( $wpdb );

		if ( ! is_array( $counts ) ) {
			self::fail();
		}

		if ( 0 === self::int( $counts['needy'] ?? -1 ) && self::int( $counts['misplaced'] ?? -1 ) <= $left && is_numeric( $counts['misplaced'] ?? null ) ) {
			return;
		}

		delete_option( self::PROGRESS_OPTION );

		throw new \RuntimeException( esc_html__( 'The SQLite string-group repair finished its steps, but the check afterwards still found strings or string links outside their own group, so it was not recorded as done. It starts again from the beginning.', 'perflocale' ) );
	}

	/**
	 * Record the repair as done, drop its progress and caches, and recompile the files in files mode.
	 *
	 * @param bool $foreground_files Compile the files in this process.
	 * @return void
	 */
	private static function complete( bool $foreground_files ): void {
		update_option( self::DONE_OPTION, gmdate( 'Y-m-d H:i:s' ), true );
		delete_option( self::PROGRESS_OPTION );
		update_option( \PerfLocale\Background\Jobs\StringGroupRepairJob::OPTION, 'done:' . time(), true );

		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		// Every language's string map, inactive ones too (fallback chains), and the cached links.
		if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$cache->invalidate_group( 'perflocale_strings' );
			$cache->invalidate_group( 'perflocale_trans' );
		}

		if ( ! $foreground_files ) {
			\PerfLocale\Bootstrap::queue_files_regeneration_after_update();
		} elseif ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;

			if ( $settings instanceof \PerfLocale\Settings && 'files' === $settings->get( 'string_translation_mode' ) ) {
				( new TranslationFileGenerator( $cache ) )->generate_all();
			}
		}

		/**
		 * Fires after the SQLite string-group repair rewrote string links.
		 *
		 * @hook perflocale/strings/changed
		 *
		 * @param string $origin What changed the strings ('sqlite_group_repair').
		 */
		do_action( 'perflocale/strings/changed', 'sqlite_group_repair' );
	}

	/**
	 * A slice's outcome for the caller.
	 *
	 * @param string               $status   'more', 'complete', 'busy' or 'not_needed'.
	 * @param array<string, mixed> $progress Progress after the slice ([] when none ran).
	 * @return array{status: string, percent: int, groups_created: int, links_moved: int, links_written: int, groups_deleted: int, links_left: int, pairs_skipped: int}
	 */
	private static function outcome( string $status, array $progress ): array {
		$phases  = array_keys( self::PHASES );
		$phase   = is_string( $progress['phase'] ?? null ) && isset( self::PHASES[ $progress['phase'] ] ) ? $progress['phase'] : 'groups';
		$at      = (int) array_search( $phase, $phases, true );
		$start   = self::PHASES[ $phase ];
		$next    = isset( $phases[ $at + 1 ] ) ? self::PHASES[ $phases[ $at + 1 ] ] : 100;
		$max     = isset( self::PHASE_SCALE[ $phase ] ) ? self::int( $progress[ self::PHASE_SCALE[ $phase ] ] ?? 0 ) : 0;
		$part    = $max > 0 ? min( 1.0, self::int( $progress['after'] ?? 0 ) / $max ) : 0.0;
		$percent = 'complete' === $status ? 100 : (int) floor( $start + ( $next - $start ) * $part );

		return [
			'status'         => $status,
			'percent'        => $percent,
			'groups_created' => self::int( $progress['groups_created'] ?? 0 ),
			'links_moved'    => self::int( $progress['links_moved'] ?? 0 ),
			'links_written'  => self::int( $progress['links_written'] ?? 0 ),
			'groups_deleted' => self::int( $progress['groups_deleted'] ?? 0 ),
			'links_left'     => self::int( $progress['links_left'] ?? 0 ),
			'pairs_skipped'  => self::int( $progress['pairs_skipped'] ?? 0 ),
		];
	}

	/**
	 * Stored progress, or null when it is not a valid progress record.
	 *
	 * @param string $json Stored value.
	 * @return array<string, mixed>|null
	 */
	private static function decode( string $json ): ?array {
		$progress = json_decode( $json, true );

		if ( ! is_array( $progress ) || ! is_string( $progress['phase'] ?? null ) || ! isset( self::PHASES[ $progress['phase'] ] ) ) {
			return null;
		}

		$clean = [ 'phase' => $progress['phase'] ];

		foreach ( array_merge( [ 'after', 'after_lang', 'max_group', 'max_string', 'max_link' ], self::COUNTS ) as $key ) {
			$clean[ $key ] = self::int( $progress[ $key ] ?? 0 );
		}

		return $clean;
	}

	/**
	 * Drop the cached copies of the repair's options: a slice uses raw SQL, and a rollback must leave no cached value.
	 *
	 * @return void
	 */
	private static function forget_options(): void {
		wp_cache_delete( self::PROGRESS_OPTION, 'options' );
		wp_cache_delete( self::DONE_OPTION, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && ( isset( $notoptions[ self::PROGRESS_OPTION ] ) || isset( $notoptions[ self::DONE_OPTION ] ) ) ) {
			unset( $notoptions[ self::PROGRESS_OPTION ], $notoptions[ self::DONE_OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Stop the slice when the read that just ran failed (wpdb clears last_error at every query).
	 *
	 * @phpstan-impure
	 *
	 * @param \wpdb $wpdb Database.
	 * @return void
	 *
	 * @throws \RuntimeException When it failed.
	 */
	private static function check_read( \wpdb $wpdb ): void {
		if ( '' !== $wpdb->last_error ) {
			self::fail();
		}
	}

	/**
	 * Stop the slice when a write failed or changed another number of rows than it had to.
	 *
	 * @param mixed $result   What wpdb returned (rows changed, or false).
	 * @param int   $expected Rows the write had to change.
	 * @return void
	 *
	 * @throws \RuntimeException When it did not.
	 */
	private static function check_write( mixed $result, int $expected ): void {
		if ( $result !== $expected ) {
			self::fail();
		}
	}

	/**
	 * Stop the slice with the error a failed slice ends with.
	 *
	 * @return never
	 *
	 * @throws \RuntimeException Always.
	 */
	private static function fail(): never {
		throw new \RuntimeException( esc_html__( 'The SQLite string-group repair could not save a step (database error), so that step was undone and nothing of it was kept. The repair runs again later.', 'perflocale' ) );
	}

	/**
	 * Column values as positive ints, in order.
	 *
	 * @param array<int|string, mixed> $values Column values.
	 * @return array<int, int>
	 */
	private static function ints( array $values ): array {
		$ints = [];

		foreach ( $values as $value ) {
			$int = self::int( $value );

			if ( $int > 0 ) {
				$ints[] = $int;
			}
		}

		return $ints;
	}

	/**
	 * A column value as an int (0 when it is not numeric).
	 *
	 * @param mixed $value Column value.
	 * @return int
	 */
	private static function int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A column value as a string ('' when it is not a scalar).
	 *
	 * @param mixed $value Column value.
	 * @return string
	 */
	private static function str( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
