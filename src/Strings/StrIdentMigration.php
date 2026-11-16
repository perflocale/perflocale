<?php
/**
 * The string identity switch-over: writers' identity repairs and the other-form rehash.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Strings;

use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Util\Scalar;

defined( 'ABSPATH' ) || exit;

/**
 * Moves rows stored under their tuple's other identity form (StringTranslation::identity()) to its identity; cold (writers,
 * jobs, CLI, upgrade). Other-form rows: 1.0.7.5's with a bar context, a 1.0.8 pre-release's "\x04" rows without one.
 *
 * Off the always-loaded classmap: no visitor request loads it. Design: dev/plans-1.0.8/str09/STR-09-MIGRATION-DESIGN-v4.md.
 */
final class StrIdentMigration {

	/** Per-site state, a small JSON object: phase, max_id, checked_id, unverified_n, held_n (and scan while one is wanted). */
	public const OPTION = 'perflocale_str_ident';

	/** Per-site id lists (`unverified`, `held`, up to IDS_MAX each), autoload off. */
	public const IDS_OPTION = 'perflocale_str_ident_ids';

	/** The runner's one-shot event, args [blog id], re-dispatched after each run until `done`. */
	public const HOOK = 'perflocale_str_ident_migrate';

	/** Lock name the chain and `wp perflocale db-migrate` share; the row is perflocale_lock_str_ident_migrate. */
	public const LOCK = 'str_ident_migrate';

	/** Rows per P1 batch. */
	private const BATCH = 500;

	/** Bytes of `original` per P1 batch, about. */
	private const BATCH_BYTES = 2097152;

	/** Seconds of batches per run. */
	private const BUDGET = 10;

	/** Ids kept per list. */
	private const IDS_MAX = 50;

	/** Runs in a row that roll the same batch back; after them its held pairs are kept without a fold. */
	private const FAILS_MAX = 5;

	/** State keys in order; `at` is the last progress time, `f` the failed runs in a row, `scan` a wanted full scan. */
	private const KEYS = [ 'phase', 'max_id', 'checked_id', 'unverified_n', 'held_n' ];

	/**
	 * A tuple's other-form hash: what a row still to be moved carries.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Original text.
	 * @return string
	 */
	public static function other_hash( string $domain, string $context, string $text ): string {
		return StringTranslation::identity_hash( $domain, $context, $text, true );
	}

	/**
	 * Move the other-form rows of these tuples to their hash, so a writer then finds them by it.
	 *
	 * A row whose columns are its other pre-image moves to its own hash (another tuple's row stays that tuple's, and one
	 * holding a member's hash leaves it); a row whose columns are not (a cut context, a changed text) moves to the
	 * writer's tuple, the pre-image it was stored for.
	 *
	 * @param array<array-key, array<mixed>> $members New hash => member (domain, context, original).
	 * @return bool False when a read or a move failed: the writer stops as on a failed read and retries later.
	 */
	public static function adopt( array $members ): bool {
		global $wpdb;

		$old = [];

		foreach ( $members as $hash => $member ) {
			$old[ self::other_hash( Scalar::str( $member['domain'] ?? 'default' ), Scalar::str( $member['context'] ?? '' ), Scalar::str( $member['original'] ?? '' ) ) ] = (string) $hash;
		}

		if ( [] === $old || ! $wpdb instanceof \wpdb ) {
			return [] === $old;
		}

		foreach ( array_chunk( array_values( array_unique( array_merge( array_keys( $old ), array_map( 'strval', array_keys( $members ) ) ) ) ), 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One %s per hash.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, domain, context, original, original_hash FROM %i WHERE original_hash IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', \PerfLocale\Database\Schema::table( 'strings' ), ...$chunk ), ARRAY_A );

			if ( ! is_array( $rows ) || self::db_error( $wpdb ) ) {
				return false;
			}

			foreach ( $rows as $row ) {
				$stored = Scalar::str( $row['original_hash'] ?? '' );
				[ $domain, $context, $text ] = [ Scalar::str( $row['domain'] ?? '' ), Scalar::str( $row['context'] ?? '' ), Scalar::str( $row['original'] ?? '' ) ];
				// A row under its own identity is its tuple's (a pre-bar-rule domain can share another tuple's other form).
				if ( StringRepository::compute_hash( $domain, $context, $text ) === $stored ) {
					continue;
				}

				$own    = self::other_hash( $domain, $context, $text ) === $stored;
				$member = $members[ $old[ $stored ] ?? '' ] ?? [];
				// An unverified row moves to the writer's tuple only when its text or its context is the tuple's (a cut
				// context or a changed text, never both), so 1.0.7.5's bar ambiguity cannot hand it to another tuple.
				$fits   = $text === Scalar::str( $member['original'] ?? '' ) || $context === Scalar::str( $member['context'] ?? '' );
				$to     = $own ? StringRepository::compute_hash( $domain, $context, $text ) : ( $fits ? ( $old[ $stored ] ?? '' ) : '' );
				$moved  = '' === $to ? 'gone' : self::move_freeing( $wpdb, Scalar::int( $row['id'] ?? 0 ), $stored, $to );

				if ( 'failed' === $moved ) {
					return false;
				}

				// A repaired unverified row P1 already counted: the report goes down by one.
				if ( ! $own && 'moved' === $moved ) {
					self::repaired( Scalar::int( $row['id'] ?? 0 ) );
				}
			}
		}

		return true;
	}

	/**
	 * find_by_hash() for a cold caller (an import): an other-form row of the tuple moves to its hash first.
	 *
	 * @param StringRepository $strings The repository.
	 * @param string           $domain  Text domain.
	 * @param string           $context Context.
	 * @param string           $text    Original text.
	 * @return object|null
	 */
	public static function find_or_rehash( StringRepository $strings, string $domain, string $context, string $text ): ?object {
		self::adopt( [ StringRepository::compute_hash( $domain, $context, $text ) => compact( 'domain', 'context' ) + [ 'original' => $text ] ] );

		return $strings->find_by_hash( $domain, $context, $text );
	}

	/**
	 * Whether a recorded source hash names this string row: its stored hash, or either form of its own columns' hash
	 * (recorded before or after a move). Only the row's own columns, so another string never matches.
	 *
	 * @param string       $recorded The recorded hash ('' never matches).
	 * @param array<mixed> $row      The row: original_hash, domain, context, original.
	 * @return bool
	 */
	public static function same_source( string $recorded, array $row ): bool {
		$tuple = [ Scalar::str( $row['domain'] ?? '' ), Scalar::str( $row['context'] ?? '' ), Scalar::str( $row['original'] ?? '' ) ];

		return '' !== $recorded && in_array( $recorded, [ Scalar::str( $row['original_hash'] ?? '' ), StringTranslation::identity_hash( ...$tuple ), self::other_hash( ...$tuple ) ], true );
	}

	/**
	 * Like same_source(), for a caller holding only the stored hash: equal, else the row's columns are read by id and compared.
	 *
	 * @param string $recorded The recorded hash.
	 * @param string $stored   The row's stored hash as the caller read it.
	 * @param int    $id       The string id.
	 * @return bool
	 */
	public static function same_source_id( string $recorded, string $stored, int $id ): bool {
		global $wpdb;

		if ( '' === $recorded || $recorded === $stored ) {
			return '' !== $recorded;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb instanceof \wpdb ? $wpdb->get_row( $wpdb->prepare( 'SELECT domain, context, original FROM %i WHERE id = %d', \PerfLocale\Database\Schema::table( 'strings' ), $id ), ARRAY_A ) : null;

		return is_array( $row ) && self::same_source( $recorded, [ 'original_hash' => $stored ] + $row );
	}

	/**
	 * Rehash one row by compare-and-set, after a check that no other row holds the new hash (by state, never by
	 * error text; no statement is sent that a held hash would fail, as a failed statement ends a SQLite transaction).
	 *
	 * @param \wpdb                $wpdb  Database.
	 * @param int                  $id    Row id.
	 * @param string               $from  Its stored hash.
	 * @param string               $to    Its new hash.
	 * @param ConnectionGuard|null $guard The batch's guard; null outside one.
	 * @return string 'moved', 'held' (another row holds $to), 'gone' (the row changed first) or 'failed'.
	 */
	public static function move( \wpdb $wpdb, int $id, string $from, string $to, ?ConnectionGuard $guard = null ): string {
		$table = \PerfLocale\Database\Schema::table( 'strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE original_hash = %s LIMIT 1', $table, $to ) );

		if ( self::db_error( $wpdb ) ) {
			return 'failed';
		}

		if ( null !== $holder ) {
			return (int) $holder === $id ? 'gone' : 'held';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared; the marker is the guard's fixed text.
		$moved = $wpdb->query( (string) $wpdb->prepare( 'UPDATE %i SET original_hash = %s WHERE id = %d AND original_hash = %s', $table, $to, $id, $from ) . self::marker( $guard ) );

		// A 0-row result on a lost connection is unknown (a resend matches nothing), never 'gone'.
		if ( false === $moved || ( 1 !== $moved && ! self::kept( $guard ) ) ) {
			return 'failed';
		}

		return 1 === $moved ? 'moved' : 'gone';
	}

	/**
	 * move(), and when another tuple's row holds $to under its own other form (a bar context's 1.0.7.5 hash is a
	 * bar-free tuple's identity), that row moves to its identity first and the move is tried again.
	 *
	 * @param \wpdb                $wpdb  Database.
	 * @param int                  $id    Row id.
	 * @param string               $from  Its stored hash.
	 * @param string               $to    Its new hash.
	 * @param ConnectionGuard|null $guard The batch's guard; null outside one.
	 * @return string As move().
	 */
	private static function move_freeing( \wpdb $wpdb, int $id, string $from, string $to, ?ConnectionGuard $guard = null ): string {
		$moved = self::move( $wpdb, $id, $from, $to, $guard );

		if ( 'held' !== $moved ) {
			return $moved;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$holder = $wpdb->get_row( $wpdb->prepare( 'SELECT id, domain, context, original FROM %i WHERE original_hash = %s', \PerfLocale\Database\Schema::table( 'strings' ), $to ), ARRAY_A );

		if ( self::db_error( $wpdb ) ) {
			return 'failed';
		}

		$tuple = is_array( $holder ) ? [ Scalar::str( $holder['domain'] ?? '' ), Scalar::str( $holder['context'] ?? '' ), Scalar::str( $holder['original'] ?? '' ) ] : [];

		if ( [] === $tuple || self::other_hash( ...$tuple ) !== $to ) {
			return 'held';
		}

		$freed = self::move( $wpdb, Scalar::int( $holder['id'] ?? 0 ), $to, StringRepository::compute_hash( ...$tuple ), $guard );

		return 'moved' === $freed ? self::move( $wpdb, $id, $from, $to, $guard ) : ( 'failed' === $freed ? 'failed' : 'held' );
	}

	/**
	 * A row whose stored context lost its tags (its hash has them) gets the scanned context back. Compared here, byte
	 * for byte: a PAD SPACE collation reads "button" and "button " as equal.
	 *
	 * @param \wpdb                               $wpdb     Database.
	 * @param string                              $table    The strings table.
	 * @param array<mixed>                        $existing Stored hashes of the batch.
	 * @param array<string, array<string, mixed>> $hashed   Hash => member.
	 * @return void
	 */
	public static function repair_contexts( \wpdb $wpdb, string $table, array $existing, array $hashed ): void {
		$queued = false;

		foreach ( $existing as $hash ) {
			$context = is_string( $hash ) ? Scalar::str( $hashed[ $hash ]['context'] ?? '' ) : '';

			if ( '' === $context || sanitize_text_field( $context ) === $context ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored = $wpdb->get_var( (string) $wpdb->prepare( 'SELECT context FROM %i WHERE original_hash = %s', $table, $hash ) );

			// A context longer than the column is stored cut: no repair.
			if ( ! is_string( $stored ) || $stored === $context || mb_substr( $context, 0, 191 ) === $stored ) {
				continue;
			}

			// Compiled files hold the old context key: their regeneration is queued first, and a refused queue leaves
			// the row for the next scan.
			if ( ! $queued && ! StringRepository::queue_files_regeneration() ) {
				continue;
			}

			$queued = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( (string) $wpdb->prepare( 'UPDATE %i SET context = %s WHERE original_hash = %s', $table, $context, $hash ) );
		}

		// A regeneration that started before these updates may have read the old contexts: one that has not started yet.
		if ( $queued ) {
			StringRepository::queue_files_regeneration( true );
		}
	}

	/**
	 * How many members are not stored as their own (domain, context, text) row; a failed read counts all.
	 *
	 * A row another writer stored first counts as stored; a hash another tuple holds does not, and its text is named.
	 *
	 * @param \wpdb                               $wpdb    Database.
	 * @param string                              $table   The strings table.
	 * @param array<string, array<string, mixed>> $members Hash => member (domain, context, original).
	 * @return array{0: int, 1: array<int, string>} How many are not stored, and the texts whose hash another tuple holds.
	 */
	public static function unstored( \wpdb $wpdb, string $table, array $members ): array {
		if ( $members === [] ) {
			return [ 0, [] ];
		}

		$hashes       = array_keys( $members );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The hash list is placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT original_hash, domain, context, original FROM %i WHERE original_hash IN ({$placeholders})", $table, ...$hashes ), ARRAY_A );

		if ( ! is_array( $rows ) || self::db_error( $wpdb ) ) {
			return [ count( $members ), [] ];
		}

		$stored = [];

		foreach ( $rows as $row ) {
			$stored[ Scalar::str( $row['original_hash'] ?? '' ) ] = $row;
		}

		$missing = 0;
		$held    = [];

		foreach ( $members as $hash => $member ) {
			$row = $stored[ $hash ] ?? null;

			if ( null === $row ) {
				++$missing;
				continue;
			}

			$same = Scalar::str( $row['domain'] ?? '' ) === sanitize_text_field( Scalar::str( $member['domain'] ?? 'default' ) )
				&& Scalar::str( $row['context'] ?? '' ) === sanitize_text_field( Scalar::str( $member['context'] ?? '' ) )
				&& Scalar::str( $row['original'] ?? '' ) === Scalar::str( $member['original'] ?? '' );

			if ( ! $same ) {
				++$missing;
				$held[] = Scalar::str( $member['original'] ?? '' );
			}
		}

		return [ $missing, $held ];
	}

	/**
	 * The runner's event handler: up to BUDGET seconds of batches for the blog, then the next event until `done`.
	 *
	 * @param mixed $blog_id Blog id (the event arg).
	 * @return void
	 */
	public static function run( $blog_id = 0 ): void {
		\PerfLocale\Background\JobState::run_recurring_for_blog(
			is_numeric( $blog_id ) ? (int) $blog_id : 0,
			static function (): void {
				$outcome = self::drive( self::BUDGET );

				// More rows: at once. Busy (the CLI runs) or a transient failure: a minute later, so cron does not spin.
				// No is_scheduled() probe here: it counts this running Action Scheduler action (as Resumer notes).
				if ( 'done' !== $outcome ) {
					\PerfLocale\Background\BackgroundEvents::enqueue( self::HOOK, [ get_current_blog_id() ], 'more' === $outcome ? 0 : MINUTE_IN_SECONDS );
				}
			}
		);
	}

	/**
	 * Run P1 batches under the shared lock for up to $seconds, then P3 (the chain and the CLI, one at a time).
	 *
	 * @param float $seconds Budget (each run holds the lock well inside its 120 s TTL).
	 * @return string 'done', 'more', 'busy' (another runner holds the lock) or 'retry' (a transient failure).
	 */
	public static function drive( float $seconds ): string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! \PerfLocale\Concurrency\Lock::acquire( self::LOCK, 120 ) ) {
			return 'busy';
		}

		try {
			$until = microtime( true ) + $seconds;

			do {
				$outcome = self::slice( $wpdb );
			} while ( 'more' === $outcome && microtime( true ) < $until );

			return $outcome;
		} finally {
			\PerfLocale\Concurrency\Lock::release( self::LOCK );
		}
	}

	/**
	 * One P1 batch, or P3 once no row is above the cursor.
	 *
	 * @param \wpdb $wpdb Database.
	 * @return string 'done', 'more' or 'retry'.
	 */
	public static function slice( \wpdb $wpdb ): string {
		$raw   = self::raw( $wpdb );
		$state = self::state( $raw );

		if ( 'done' === $state['phase'] ) {
			return 'done';
		}

		if ( null === $raw || 'rows' !== $state['phase'] ) {
			$state['phase'] = 'rows';
			$state['at']    = time();

			return self::write( $raw, $state ) ? 'more' : 'retry';
		}

		$table = \PerfLocale\Database\Schema::table( 'strings' );

		// Ids and sizes, then the rows, both outside the batch transaction, so no snapshot hides a write.
		// Sizes in bytes on every engine: SQLite's LENGTH() counts characters.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The size form is a fixed literal.
		$sizes = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ' . ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? 'LENGTH(HEX(original)) / 2' : 'LENGTH(original)' ) . ' AS n FROM %i WHERE id > %d ORDER BY id LIMIT %d', $table, $state['checked_id'], self::BATCH ), ARRAY_A );

		if ( ! is_array( $sizes ) || self::db_error( $wpdb ) ) {
			return 'retry';
		}

		if ( [] === $sizes ) {
			return self::finish( $wpdb, $raw, $state );
		}

		$ids   = [];
		$bytes = 0;

		foreach ( $sizes as $size ) {
			$bytes += Scalar::int( $size['n'] ?? 0 );

			if ( [] !== $ids && $bytes > self::BATCH_BYTES ) {
				break;
			}

			$ids[] = Scalar::int( $size['id'] ?? 0 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One %d per id.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, domain, context, original, original_hash FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id', $table, ...$ids ), ARRAY_A );

		if ( ! is_array( $rows ) || self::db_error( $wpdb ) ) {
			return 'retry';
		}

		$found = [
			'unverified' => [],
			'held'       => [],
		];

		// One transaction per batch; a held hash continues the batch, any failed statement rolls it all back
		// (also a failure wpdb counted that a later statement's last_error hid). Every write carries the guard's marker:
		// wpdb resends a statement after a lost connection unseen, on a new autocommit one, where it then matches nothing.
		$errors = \PerfLocale\Database\Schema::query_error_count();
		$guard  = ConnectionGuard::applies() ? new ConnectionGuard( 'str_ident' ) : null;

		// No marker, no batch: an unmarked write resent on a new connection could commit alone.
		if ( null !== $guard && ! $guard->begin() ) {
			return 'retry';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			$guard?->end();

			return 'retry';
		}

		foreach ( $rows as $row ) {
			$id                          = Scalar::int( $row['id'] ?? 0 );
			$stored                      = Scalar::str( $row['original_hash'] ?? '' );
			[ $domain, $context, $text ] = [ Scalar::str( $row['domain'] ?? '' ), Scalar::str( $row['context'] ?? '' ), Scalar::str( $row['original'] ?? '' ) ];
			$new                         = StringRepository::compute_hash( $domain, $context, $text );

			if ( $stored === $new ) {
				continue;
			}

			// Only a row whose stored hash is its columns' other-form hash is rehashed; any other row is unverified.
			// After a return-trip reset, rows up to the last `done`'s max_id were counted then; only newer ones count.
			if ( self::other_hash( $domain, $context, $text ) !== $stored ) {
				if ( $id > $state['max_id'] ) {
					$found['unverified'][] = $id;
				}

				continue;
			}

			$moved = self::move_freeing( $wpdb, $id, $stored, $new, $guard );

			// After FAILS_MAX rolled-back runs of this batch a held pair is kept as it is, so a fold that fails the
			// same way every time cannot stop P1 for good.
			if ( 'held' === $moved && ( $state['f'] ?? 0 ) < self::FAILS_MAX ) {
				$moved = self::fold( $wpdb, $id, $stored, $new, $guard );
			}

			if ( 'failed' === $moved ) {
				$wpdb->query( 'ROLLBACK' );
				$guard?->end();

				return self::failed_run( $raw, $state );
			}

			if ( 'held' === $moved && $id > $state['max_id'] ) {
				$found['held'][] = $id;
			}
		}

		// Checked after the COMMIT too: one resent on a new connection committed nothing (the server rolled the batch back).
		$committed = self::kept( $guard ) && \PerfLocale\Database\Schema::query_error_count() <= $errors && false !== $wpdb->query( 'COMMIT' ) && self::kept( $guard );
		$guard?->end();

		if ( ! $committed ) {
			$wpdb->query( 'ROLLBACK' );

			return self::failed_run( $raw, $state );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$next                 = $state;
		$next['checked_id']   = (int) end( $ids );
		$next['at']           = time();
		unset( $next['f'] );
		$next['unverified_n'] = $state['unverified_n'] + count( $found['unverified'] );
		$next['held_n']       = $state['held_n'] + count( $found['held'] );

		// The first unverified row asks for a full string scan, queued from wp-admin (a job needs a user).
		if ( 0 === $state['unverified_n'] && $next['unverified_n'] > 0 ) {
			$next['scan'] = 1;
		}

		// After the commit. A lost compare-and-set leaves the cursor to the other runner; the batch is idempotent.
		if ( self::write( $raw, $next ) ) {
			self::note_ids( $found );
		}

		return 'more';
	}

	/**
	 * A batch rolled back: count the run, so the batch's held pairs are kept unfolded after FAILS_MAX.
	 *
	 * @param string               $raw   The state row the run read.
	 * @param array<string, mixed> $state Its decoded value.
	 * @return string 'retry'.
	 */
	private static function failed_run( string $raw, array $state ): string {
		$state['f'] = Scalar::int( $state['f'] ?? 0 ) + 1;
		self::write( $raw, $state );

		return 'retry';
	}

	/**
	 * P3: `done` with the cursor as max_id and checked_id, autoload on, then the maps flushed.
	 *
	 * @param \wpdb                $wpdb  Database.
	 * @param string               $raw   The state row the run read.
	 * @param array<string, mixed> $state Its decoded value.
	 * @return string 'done', or 'more' when another runner moved the state first.
	 */
	private static function finish( \wpdb $wpdb, string $raw, array $state ): string {
		$state['phase']      = 'done';
		$state['at']         = time();
		$state['max_id']     = $state['checked_id'];
		$state['checked_id'] = $state['max_id'];

		if ( ! self::write( $raw, $state ) ) {
			return 'more';
		}

		self::flush_maps( $wpdb );

		return 'done';
	}

	/**
	 * A held pair. When at most one side has translations, keep that side (else the new-hash row), move the other
	 * side's pending rows to it, delete the other side with its links and group, and rehash the kept old row. When both
	 * have translations, keep both and move to the new row the languages only the old one has, so each is served.
	 * Only a holder with the same domain, context and text is a twin: any other holder is kept as it is ('held').
	 *
	 * @param \wpdb                $wpdb  Database.
	 * @param int                  $id    The other-form row.
	 * @param string               $from  Its stored hash.
	 * @param string               $to    Its new hash, which another row holds.
	 * @param ConnectionGuard|null $guard The batch's guard; null on SQLite.
	 * @return string 'moved' (folded), 'held' (both have translations: kept and reported) or 'failed'.
	 */
	private static function fold( \wpdb $wpdb, int $id, string $from, string $to, ?ConnectionGuard $guard = null ): string {
		$pair = self::pair( $wpdb, $id, $to, true );

		if ( null === $pair ) {
			return 'failed';
		}

		[ $holder, $langs, $groups, $same ] = $pair;

		if ( ! $same ) {
			return 'held';
		}

		if ( [] !== $langs[ $id ] && [] !== $langs[ $holder ] ) {
			return self::share_languages( $wpdb, $id, $holder, $groups[ $holder ], array_diff( $langs[ $id ], $langs[ $holder ] ), $guard ) ? 'held' : 'failed';
		}

		$keep = [] !== $langs[ $id ] ? $id : $holder;
		$drop = $keep === $id ? $holder : $id;

		// Each statement is checked at once: after a failure nothing more is sent, and the batch rolls back.
		// The DELETE holds only while the dropped row has no translation (one saved since the read keeps it).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$steps = [
			$wpdb->prepare( "UPDATE %i SET object_id = %d WHERE object_type = 'string' AND object_id = %d", \PerfLocale\Database\Schema::table( 'pending_changes' ), $keep, $drop ),
			$wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND NOT EXISTS ( SELECT 1 FROM %i WHERE string_id = %d )', \PerfLocale\Database\Schema::table( 'strings' ), $drop, \PerfLocale\Database\Schema::table( 'string_translations' ), $drop ),
			$wpdb->prepare( "DELETE FROM %i WHERE type = 'string' AND object_id = %d", \PerfLocale\Database\Schema::table( 'translation_links' ), $drop ),
			$wpdb->prepare( "DELETE FROM %i WHERE id = %d AND type = 'string' AND NOT EXISTS ( SELECT 1 FROM %i WHERE group_id = %d )", \PerfLocale\Database\Schema::table( 'translation_groups' ), $groups[ $drop ], \PerfLocale\Database\Schema::table( 'strings' ), $groups[ $drop ] ),
		];

		foreach ( $steps as $i => $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; the marker is the guard's fixed text.
			$done = $wpdb->query( (string) $sql . self::marker( $guard ) );

			if ( false === $done || self::db_error( $wpdb ) || ! self::kept( $guard ) || ( 1 === $i && 1 !== $done ) ) {
				return 'failed';
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $keep === $id ? self::move( $wpdb, $id, $from, $to, $guard ) : 'moved';
	}

	/**
	 * A held pair's holder and, per side, its translated language ids and group, read with locking reads (SQLite has
	 * one writer and no FOR UPDATE).
	 *
	 * @param \wpdb  $wpdb    Database.
	 * @param int    $id      The other-form row.
	 * @param string $to      Its new hash.
	 * @param bool   $locking Lock the rows (inside the batch transaction).
	 * @return array{0: int, 1: array<int, list<int>>, 2: array<int, int>, 3: bool}|null Null on a failed read or no
	 *         holder; 3: whether the holder is the row's twin (same domain, context and text, byte for byte).
	 */
	private static function pair( \wpdb $wpdb, int $id, string $to, bool $locking ): ?array {
		$lock = $locking && ! ( defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// One read by the primary key and one by the unique key, so the locks never fall back to a scan.
		$rows = [];

		foreach ( [ [ 'id = %d', $id ], [ 'original_hash = %s', $to ] ] as [ $where, $value ] ) {
			$sql  = 'id = %d' === $where ? ( $lock ? 'SELECT id, group_id, domain, context, original FROM %i WHERE id = %d FOR UPDATE' : 'SELECT id, group_id, domain, context, original FROM %i WHERE id = %d' ) : ( $lock ? 'SELECT id, group_id, domain, context, original FROM %i WHERE original_hash = %s FOR UPDATE' : 'SELECT id, group_id, domain, context, original FROM %i WHERE original_hash = %s' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of the four literals above.
			$read = $wpdb->get_results( $wpdb->prepare( $sql, \PerfLocale\Database\Schema::table( 'strings' ), $value ), ARRAY_A );

			if ( ! is_array( $read ) || self::db_error( $wpdb ) ) {
				return null;
			}

			$rows = array_merge( $rows, $read );
		}

		$groups = [];
		$tuples = [];

		foreach ( $rows as $row ) {
			$groups[ Scalar::int( $row['id'] ?? 0 ) ] = Scalar::int( $row['group_id'] ?? 0 );
			$tuples[ Scalar::int( $row['id'] ?? 0 ) ] = [ Scalar::str( $row['domain'] ?? '' ), Scalar::str( $row['context'] ?? '' ), Scalar::str( $row['original'] ?? '' ) ];
		}

		$holder = (int) ( array_keys( array_diff_key( $groups, [ $id => true ] ) )[0] ?? 0 );

		if ( $holder <= 0 || ! isset( $groups[ $id ] ) ) {
			return null;
		}

		$table = \PerfLocale\Database\Schema::table( 'string_translations' );
		$read  = $lock
			? $wpdb->get_results( $wpdb->prepare( 'SELECT string_id, language_id FROM %i WHERE string_id IN (%d, %d) FOR UPDATE', $table, $id, $holder ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT string_id, language_id FROM %i WHERE string_id IN (%d, %d)', $table, $id, $holder ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $read ) || self::db_error( $wpdb ) ) {
			return null;
		}

		$langs = [
			$id     => [],
			$holder => [],
		];

		foreach ( $read as $row ) {
			$langs[ Scalar::int( $row['string_id'] ?? 0 ) ][] = Scalar::int( $row['language_id'] ?? 0 );
		}

		return [ $holder, $langs, $groups, $tuples[ $id ] === $tuples[ $holder ] ];
	}

	/**
	 * A kept pair's languages only the other-form row has move to the new-hash row (value, link, pending), so after `done`
	 * every language is served by the key a lookup computes; the languages both have stay on both, for review.
	 *
	 * @param \wpdb                $wpdb  Database.
	 * @param int                  $old   The other-form row.
	 * @param int                  $new   The new-hash row.
	 * @param int                  $group The new row's group.
	 * @param array<int>           $langs Language ids only the old row has.
	 * @param ConnectionGuard|null $guard The batch's guard; null on SQLite.
	 * @return bool False when a statement failed or the connection was lost.
	 */
	private static function share_languages( \wpdb $wpdb, int $old, int $new, int $group, array $langs, ?ConnectionGuard $guard = null ): bool {
		if ( [] === $langs ) {
			return true;
		}

		$langs = array_values( array_map( 'intval', $langs ) );
		$in    = implode( ',', array_fill( 0, count( $langs ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One %d per language id.
		foreach ( [
			// The new row's own links in these languages carry no value (it has none there): they make way.
			$wpdb->prepare( "DELETE FROM %i WHERE type = 'string' AND ( group_id = %d OR object_id = %d ) AND language_id IN (" . $in . ')', \PerfLocale\Database\Schema::table( 'translation_links' ), $group, $new, ...$langs ),
			// A move, not an edit: updated_at is kept as it is on every engine (no ON UPDATE bump on MySQL).
			$wpdb->prepare( 'UPDATE %i SET string_id = %d, updated_at = updated_at WHERE string_id = %d AND language_id IN (' . $in . ')', \PerfLocale\Database\Schema::table( 'string_translations' ), $new, $old, ...$langs ),
			// The pending changes of these languages follow their values.
			$wpdb->prepare( "UPDATE %i SET object_id = %d WHERE object_type = 'string' AND object_id = %d AND language_id IN (" . $in . ')', \PerfLocale\Database\Schema::table( 'pending_changes' ), $new, $old, ...$langs ),
			$wpdb->prepare( "UPDATE %i SET group_id = %d, object_id = %d, updated_at = updated_at WHERE type = 'string' AND object_id = %d AND language_id IN (" . $in . ')', \PerfLocale\Database\Schema::table( 'translation_links' ), $group, $new, $old, ...$langs ),
		] as $sql ) {
			if ( false === $wpdb->query( (string) $sql . self::marker( $guard ) ) || self::db_error( $wpdb ) || ! self::kept( $guard ) ) {
				return false;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return true;
	}

	/**
	 * Whether an other-form row is one side of a pair P1 keeps (both sides translated); a foldable pair is not.
	 *
	 * @param \wpdb  $wpdb Database.
	 * @param int    $id   The other-form row.
	 * @param string $to   Its new hash.
	 * @return bool
	 */
	private static function kept_pair( \wpdb $wpdb, int $id, string $to ): bool {
		$pair = self::pair( $wpdb, $id, $to, false );

		return null !== $pair && $pair[3] && [] !== $pair[1][ $id ] && [] !== $pair[1][ $pair[0] ];
	}

	/**
	 * For the lab and tools: how a refused rehash of $id to $to classifies, by state.
	 *
	 * @param int    $id Row id.
	 * @param string $to The new hash.
	 * @return string 'held' (another row holds $to) or 'transient'.
	 */
	public static function classify( int $id, string $to ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$holder = $wpdb instanceof \wpdb ? $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE original_hash = %s LIMIT 1', \PerfLocale\Database\Schema::table( 'strings' ), $to ) ) : null;

		return null !== $holder && Scalar::int( $holder ) !== $id ? 'held' : 'transient';
	}

	/**
	 * The state row's raw value, read past the options cache; null when there is no row.
	 *
	 * @param \wpdb $wpdb Database.
	 * @return string|null
	 */
	public static function raw( \wpdb $wpdb ): ?string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::OPTION ) );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * A state value decoded, every key present (absent or unreadable: the start, phase '').
	 *
	 * @param string|null $raw Raw value.
	 * @return array{phase: string, max_id: int, checked_id: int, unverified_n: int, held_n: int, at: int, f?: int, scan?: int}
	 */
	public static function state( ?string $raw ): array {
		$value = json_decode( (string) $raw, true );
		$value = is_array( $value ) ? $value : [];
		$state = [
			'phase'        => Scalar::str( $value['phase'] ?? '' ),
			'max_id'       => Scalar::int( $value['max_id'] ?? 0 ),
			'checked_id'   => Scalar::int( $value['checked_id'] ?? 0 ),
			'unverified_n' => max( 0, Scalar::int( $value['unverified_n'] ?? 0 ) ),
			'held_n'       => max( 0, Scalar::int( $value['held_n'] ?? 0 ) ),
			'at'           => Scalar::int( $value['at'] ?? 0 ),
		];

		if ( isset( $value['f'] ) ) {
			$state['f'] = max( 0, Scalar::int( $value['f'] ) );
		}

		if ( isset( $value['scan'] ) ) {
			$state['scan'] = 1;
		}

		return $state;
	}

	/**
	 * Write the state by compare-and-set on the raw row: an INSERT that never replaces a row when there was
	 * none; autoload on only at `done`; the options caches dropped so get_option() reads the new row.
	 *
	 * @param string|null          $expected The raw value the new state was built from; null: no row.
	 * @param array<string, mixed> $state    New state.
	 * @return bool Whether this write won.
	 */
	public static function write( ?string $expected, array $state ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$value    = (string) wp_json_encode( array_intersect_key( $state, array_flip( array_merge( self::KEYS, [ 'at', 'f', 'scan' ] ) ) ) );
		$done     = 'done' === ( $state['phase'] ?? '' );
		$was_done = null !== $expected && 'done' === self::state( $expected )['phase'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = null === $expected
			? $wpdb->query( (string) $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::OPTION, $value, $done ? 'yes' : 'no' ) )
			: $wpdb->query( (string) $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $value, $done ? 'yes' : 'no', self::OPTION, $expected ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( self::OPTION, 'options' );

		// A first row leaves the notoptions list; at an autoload flip the autoloaded set changes.
		if ( null === $expected ) {
			\PerfLocale\Database\NotOptions::unlist( [ self::OPTION ] );
		}

		if ( $done !== $was_done ) {
			wp_cache_delete( 'alloptions', 'options' );
		}

		return 1 === $written;
	}

	/**
	 * Whether this site's switch-over is `done`, read from the row.
	 *
	 * @return bool
	 */
	public static function done(): bool {
		global $wpdb;

		return $wpdb instanceof \wpdb && 'done' === self::state( self::raw( $wpdb ) )['phase'];
	}

	/**
	 * Re-arm: queue the runner when the phase is not `done` and no event is pending.
	 *
	 * @return bool Whether an event is pending afterwards.
	 */
	public static function maybe_dispatch(): bool {
		global $wpdb;

		return $wpdb instanceof \wpdb && ! self::done() && self::dispatch();
	}

	/**
	 * Queue the runner's next event for this blog unless one is pending.
	 *
	 * @return bool
	 */
	private static function dispatch(): bool {
		$args = [ get_current_blog_id() ];

		return \PerfLocale\Background\BackgroundEvents::is_scheduled( self::HOOK, $args ) || \PerfLocale\Background\BackgroundEvents::enqueue( self::HOOK, $args );
	}

	/**
	 * The per-site upgrade task: the 1.0.7.5 map keys purged, v1 combined bundles out of the files manifest before
	 * the update's regeneration is queued, then start(). Throws when the switch-over could not be queued.
	 *
	 * @return void
	 * @throws \RuntimeException With UpgradeRunner::OWN_ERROR.
	 */
	public static function upgrade_task(): void {
		global $wpdb;

		// M1: a return from 1.0.7.5 is found here when 1.0.7.5 was activated (it stamped its own version); after a file-replace
		// downgrade the stored version stays at this one, no task runs, and the daily check finds the return.
		$failed = '';

		try {
			self::daily_check();
		} catch ( \Throwable $e ) {
			$failed = \PerfLocale\Database\UpgradeRunner::describe( $e );
		}

		if ( $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', \PerfLocale\Database\Schema::table( 'languages' ) ) );

			self::cache()?->delete_many( array_map( static fn( $id ): string => 'all_string_translations_' . Scalar::int( $id ), $ids ), 'perflocale_strings' );
		}

		$manifest = get_option( TranslationFileGenerator::MANIFEST_OPTION );

		if ( is_array( $manifest ) ) {
			$kept = array_values( array_filter( $manifest, static fn( $name ): bool => ! str_starts_with( Scalar::str( $name ), TranslationFileGenerator::COMBINED_PREFIX ) ) );

			if ( $kept !== $manifest ) {
				update_option( TranslationFileGenerator::MANIFEST_OPTION, $kept, true );
			}
		}

		try {
			if ( ! self::start() && '' === $failed ) {
				$failed = __( 'The background scheduler refused the job.', 'perflocale' );
			}
		} catch ( \Throwable $e ) {
			$failed = '' === $failed ? \PerfLocale\Database\UpgradeRunner::describe( $e ) : $failed;
		}

		// After the purges above: the task reruns from here on Retry now and the backoff.
		if ( '' !== $failed ) {
			/* translators: %s: cause. */
			throw new \RuntimeException( sprintf( __( 'The string identity switch-over could not be queued: %s', 'perflocale' ), $failed ), \PerfLocale\Database\UpgradeRunner::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
		}
	}

	/**
	 * Daily, and first in `wp perflocale db-migrate`: after `done`, an other-form row (1.0.7.5's bar context after a return
	 * trip, a pre-release "\x04" row) sends the site back to P1 from that row: maps flushed, autoload off, runner queued.
	 * Reads the rows past a moving checked_id and the first 50 by id; a row matching neither form is ignored.
	 *
	 * @return bool Whether the site was sent back to P1.
	 */
	public static function daily_check(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$raw   = self::raw( $wpdb );
		$state = self::state( $raw );

		if ( null === $raw || 'done' !== $state['phase'] ) {
			return false;
		}

		$table  = \PerfLocale\Database\Schema::table( 'strings' );
		$low    = 0;
		$cursor = $state['checked_id'];
		$until  = microtime( true ) + 2;

		// The first 50 rows once, then pages past the moving cursor until an other-form row, the last row or 2 s (
		// thousands of strings added after `done` are read in one check, not 500 a day).
		foreach ( [ 0, $cursor ] as $i => $after ) {
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$read = $wpdb->get_results( $wpdb->prepare( 'SELECT id, domain, context, original, original_hash FROM %i WHERE id > %d ORDER BY id LIMIT %d', $table, $after, 0 === $i ? 50 : self::BATCH ), ARRAY_A );

				if ( ! is_array( $read ) || self::db_error( $wpdb ) ) {
					return false;
				}

				foreach ( $read as $row ) {
					$id = Scalar::int( $row['id'] ?? 0 );

					[ $domain, $context, $text ] = [ Scalar::str( $row['domain'] ?? '' ), Scalar::str( $row['context'] ?? '' ), Scalar::str( $row['original'] ?? '' ) ];

					// A pair P1 keeps (both sides translated) stays in the other form on purpose: no reset for it; a foldable pair resets (H2).
					if ( Scalar::str( $row['original_hash'] ?? '' ) === self::other_hash( $domain, $context, $text ) && ( 0 === $low || $id < $low ) && ! self::kept_pair( $wpdb, $id, StringRepository::compute_hash( $domain, $context, $text ) ) ) {
						$low = $id;
					}
				}

				if ( 0 !== $i && [] !== $read ) {
					$cursor = Scalar::int( end( $read )['id'] ?? 0 );
					$after  = $cursor;
				}
			} while ( 0 !== $i && 0 === $low && self::BATCH === count( $read ) && microtime( true ) < $until );
		}

		$next = $state;

		if ( 0 === $low ) {
			// The check cursor moves on, so rows added after `done` are each read once.
			$next['checked_id'] = $cursor;

			if ( $next !== $state ) {
				self::write( $raw, $next );
			}

			return false;
		}

		$next['phase']      = 'rows';
		$next['checked_id'] = $low - 1;

		if ( ! self::write( $raw, $next ) ) {
			return false;
		}

		// The `done` maps were keyed by stored hashes; the state's cache deletes and the autoload flip ran in write().
		self::flush_maps( $wpdb );
		self::dispatch();

		return true;
	}

	/**
	 * This site's switch-over for Site Health and the CLI.
	 *
	 * @return array{phase: string, checked_id: int, last_id: int, unverified_n: int, held_n: int, stalled: bool, over_cap: list<string>, ids: array<mixed>}
	 */
	public static function status(): array {
		global $wpdb;

		$state = $wpdb instanceof \wpdb ? self::state( self::raw( $wpdb ) ) : self::state( null );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last  = $wpdb instanceof \wpdb ? Scalar::int( $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', \PerfLocale\Database\Schema::table( 'strings' ) ) ) ) : 0;
		$ids   = get_option( self::IDS_OPTION, [] );

		// Locales whose per-domain files pass the loader's 200-file cap (files mode serves the rest from the combined bundle).
		$count = [];

		foreach ( (array) get_option( TranslationFileGenerator::MANIFEST_OPTION, [] ) as $name ) {
			$name = Scalar::str( $name );

			if ( ! str_starts_with( $name, TranslationFileGenerator::COMBINED_PREFIX ) && 1 === preg_match( '/-([^-]+)\.l10n\.php$/', $name, $m ) ) {
				$count[ $m[1] ] = ( $count[ $m[1] ] ?? 0 ) + 1;
			}
		}

		return [
			'phase'        => '' === $state['phase'] ? 'not started' : $state['phase'],
			'checked_id'   => $state['checked_id'],
			'last_id'      => $last,
			'unverified_n' => $state['unverified_n'],
			'held_n'       => $state['held_n'],
			// No event pending and no runner holding the lock while not done: the chain stopped (a lost event or a fatal).
			// Also a chain that runs but moves nothing for a day (a batch rolled back every time).
			'stalled'      => 'done' !== $state['phase'] && ( ( $state['at'] > 0 && time() - $state['at'] > DAY_IN_SECONDS ) || ( ! \PerfLocale\Background\BackgroundEvents::is_scheduled( self::HOOK, [ get_current_blog_id() ] ) && 0 === \PerfLocale\Concurrency\Lock::seconds_remaining( self::LOCK ) ) ),
			'over_cap'     => array_map( 'strval', array_keys( array_filter( $count, static fn( int $n ): bool => $n > 200 ) ) ),
			'ids'          => is_array( $ids ) ? $ids : [],
		];
	}

	/**
	 * At activation, before PerfLocale writes its own strings: see start().
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		self::start();
	}

	/**
	 * No state row and the strings table read empty: `done` (insert-only, autoloaded); else the runner while not `done`.
	 * A failed read is not empty, so it never grants.
	 *
	 * @return bool Whether nothing is left to start: `done`, or the runner queued.
	 */
	private static function start(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return true;
		}

		if ( null === self::raw( $wpdb ) && ! self::db_error( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$any = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', \PerfLocale\Database\Schema::table( 'strings' ) ) );

			if ( null === $any && ! self::db_error( $wpdb ) && self::write( null, [ 'phase' => 'done', 'max_id' => 0, 'checked_id' => 0, 'unverified_n' => 0, 'held_n' => 0 ] ) ) {
				return true;
			}
		}

		return self::done() || self::dispatch();
	}

	/**
	 * On a new site (wp_initialize_site): its strings table was just created, so `done` unconditionally.
	 *
	 * @return void
	 */
	public static function on_new_site(): void {
		add_option( self::OPTION, (string) wp_json_encode( [ 'phase' => 'done', 'max_id' => 0, 'checked_id' => 0, 'unverified_n' => 0, 'held_n' => 0 ] ), '', true );
	}

	/**
	 * Rule 18: a Replace import refilled the strings table and hashed every row it wrote by its identity, so a site with a
	 * state starts over as a new site's `done`; the id lists named rows that are gone. No state: the runner counts the rows.
	 *
	 * @return void
	 */
	public static function replaced(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// A compare-and-set, retried while a runner moves the state; the import's cache flush follows (no bridged map left).
		for ( $try = 0; $try < 5; $try++ ) {
			$raw = self::raw( $wpdb );

			if ( null === $raw || self::write( $raw, [ 'phase' => 'done', 'max_id' => 0, 'checked_id' => 0, 'unverified_n' => 0, 'held_n' => 0 ] ) ) {
				break;
			}
		}

		delete_option( self::IDS_OPTION );
	}

	/**
	 * From admin_init (a user who may queue it): queue the full string scan a batch asked for, then clear the ask.
	 *
	 * @return void
	 */
	public static function queue_scan(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( self::state( self::raw( $wpdb ) )['scan'] ) ) {
			self::enqueue_scan( $wpdb );
		}
	}

	/**
	 * From `wp perflocale db-migrate`: queue the full string scan while rows are unverified, then clear any ask.
	 *
	 * @return string The job id; '' when nothing was queued (no unverified row, no manage_options user, or refused).
	 */
	public static function queue_scan_for_unverified(): string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || ! current_user_can( 'manage_options' ) || self::state( self::raw( $wpdb ) )['unverified_n'] < 1 ) {
			return '';
		}

		return self::enqueue_scan( $wpdb );
	}

	/**
	 * Queue StringScanJob(all), then clear the state's scan ask.
	 *
	 * @param \wpdb $wpdb Database.
	 * @return string The job id, '' when the dispatcher refused it.
	 */
	private static function enqueue_scan( \wpdb $wpdb ): string {
		// Queued first, so a stop before the clear leaves the ask (a second scan at worst, never none); the
		// clear is a compare-and-set retried while P1 moves the state, so a lost CAS does not queue the scan again.
		$queued = \PerfLocale\Background\Dispatcher::enqueue( new \PerfLocale\Background\Jobs\StringScanJob(), [ 'mode' => 'all' ] );

		if ( ! isset( $queued['job_id'] ) ) {
			return '';
		}

		for ( $try = 0; $try < 5; $try++ ) {
			$raw   = self::raw( $wpdb );
			$state = self::state( $raw );

			if ( ! isset( $state['scan'] ) ) {
				break;
			}

			unset( $state['scan'] );

			if ( self::write( $raw, $state ) ) {
				break;
			}
		}

		return Scalar::str( $queued['job_id'] );
	}

	/**
	 * A writer repaired an unverified row P1 had counted; the report goes down by one.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	private static function repaired( int $id ): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$raw   = self::raw( $wpdb );
		$state = self::state( $raw );

		if ( null !== $raw && $id <= $state['checked_id'] && $state['unverified_n'] > 0 ) {
			--$state['unverified_n'];
			self::write( $raw, $state );
		}
	}

	/**
	 * Add a batch's unverified and held ids to the id lists (their own option, autoload off, IDS_MAX each).
	 *
	 * @param array{unverified: list<int>, held: list<int>} $found Ids of the batch.
	 * @return void
	 */
	private static function note_ids( array $found ): void {
		if ( [] === $found['unverified'] && [] === $found['held'] ) {
			return;
		}

		$ids = get_option( self::IDS_OPTION, [] );
		$ids = is_array( $ids ) ? $ids : [];

		foreach ( $found as $list => $add ) {
			$ids[ $list ] = array_slice( array_values( array_unique( array_merge( array_map( static fn( $v ): int => Scalar::int( $v ), (array) ( $ids[ $list ] ?? [] ) ), $add ) ) ), 0, self::IDS_MAX );
		}

		update_option( self::IDS_OPTION, $ids, false );
	}

	/**
	 * Drop every language's DB-mode map, so the next miss builds it unbridged.
	 *
	 * @param \wpdb $wpdb Database.
	 * @return void
	 */
	private static function flush_maps( \wpdb $wpdb ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', \PerfLocale\Database\Schema::table( 'languages' ) ) );

		$cache = self::cache();

		if ( null !== $cache ) {
			$cache->delete_many( array_map( static fn( $id ): string => 'all_str2_translations_' . Scalar::int( $id ), $ids ), 'perflocale_strings' );
			require_once __DIR__ . '/../Cache/CacheDiagnostics.php';
			\PerfLocale\Cache\CacheDiagnostics::forget_map_sizes( $cache, $ids );
		}
	}

	/**
	 * The plugin's cache service, or null outside a booted plugin.
	 *
	 * @return \PerfLocale\Cache\CacheManager|null
	 */
	private static function cache(): ?\PerfLocale\Cache\CacheManager {
		$plugin = \PerfLocale\Plugin::get_instance();
		$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		return $cache instanceof \PerfLocale\Cache\CacheManager ? $cache : null;
	}

	/**
	 * The guard's condition with its leading AND; '' without a guard.
	 *
	 * @param ConnectionGuard|null $guard The batch's guard.
	 * @return string
	 */
	private static function marker( ?ConnectionGuard $guard ): string {
		$condition = null === $guard ? '' : $guard->condition();

		return '' === $condition ? '' : ' AND ' . $condition;
	}

	/**
	 * Whether the batch still runs on the connection it began on (true without a guard).
	 *
	 * @phpstan-impure
	 * @param ConnectionGuard|null $guard The batch's guard.
	 * @return bool
	 */
	private static function kept( ?ConnectionGuard $guard ): bool {
		return null === $guard || $guard->kept();
	}

	/**
	 * Whether the last statement failed (read through a call: the connection changes it).
	 *
	 * @phpstan-impure
	 * @param \wpdb $wpdb Database.
	 * @return bool
	 */
	private static function db_error( \wpdb $wpdb ): bool {
		return '' !== $wpdb->last_error;
	}
}
