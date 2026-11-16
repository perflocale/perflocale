<?php
/**
 * One data import at a time per site.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Repository\TranslationGroupWrites;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The per-site lock every data import (form, job, CLI, network-import slice) holds from reading the file to its last write.
 *
 * GET_LOCK on MySQL (freed with the connection, so a crash leaves none), else an option lock with a TTL; heartbeat() keeps it.
 * See docs-ai/11-code-rationale.md, "ImportLock: connection lock and heartbeat".
 */
final class ImportLock {

	/**
	 * Option lock name (Concurrency\Lock rows are per site).
	 */
	private const OPTION_LOCK = 'import';

	/**
	 * Seconds the option lock lives without a heartbeat.
	 */
	private const TTL = 600;

	/**
	 * Seconds the option lock lives through a Replace transaction (as DataImportJob::get_lock_ttl()).
	 */
	private const REPLACE_TTL = 14400;

	/**
	 * Seconds the option lock of a URL-slug write lives (a fatal error skips its release).
	 */
	private const SLUG_TTL = 30;

	/**
	 * A URL-slug write's transaction is open (the shutdown function rolls it back after a fatal error).
	 *
	 * @var bool
	 */
	private static bool $slug_open = false;

	/**
	 * Option counting the imports that took the lock: a writer that read data before an import refuses after it.
	 * Odd while a counted import runs (set after the lock, made even before its release), so a URL-slug write's
	 * uncounted hold of the same lock is told apart. An odd value is at least the Unix time of the import's start or
	 * last heartbeat: one that lost its lock with its connection still counts until its next heartbeat stops it.
	 */
	public const GENERATION_OPTION = 'perflocale_import_generation';

	/**
	 * Seconds an odd count with a free lock still reads as a running import: three heartbeat intervals.
	 */
	private const COUNT_FRESH = 180;

	/**
	 * The odd generation this request's counted import set (made even at release()), else null.
	 *
	 * @var int|null
	 */
	private static ?int $counting = null;

	/**
	 * Why the last acquire() of an import failed though the lock was free ('' otherwise).
	 *
	 * @var string
	 */
	private static string $refused = '';

	/**
	 * Locks this request holds: name => `mysql` or `option`.
	 *
	 * @var array<string, string>
	 */
	private static array $held = [];

	/**
	 * When heartbeat() last did its work (Unix time).
	 */
	private static int $beat_at = 0;

	/**
	 * The wpdb connection handle the connection lock was taken on, when wpdb exposes one.
	 */
	private static ?object $conn = null;

	/**
	 * While not '': the import holds connection state (a TEMPORARY table), so a reconnect ends it with this message.
	 *
	 * @var string
	 */
	private static string $session_lost = '';

	/**
	 * Bind the import to its connection: after a reconnect heartbeat() throws $lost_message. '' unbinds.
	 *
	 * @param string $lost_message The import's message for a lost connection, or ''.
	 * @return void
	 */
	public static function bind_session( string $lost_message ): void {
		self::$session_lost = $lost_message;
	}

	/**
	 * Take the current site's import lock.
	 *
	 * @param int  $ttl    Seconds the option lock lives without a heartbeat (the connection lock needs none).
	 * @param bool $import Count it in the generation; a URL-slug write takes the lock too but is no import.
	 * @return bool False when another import holds it (or this request already does).
	 */
	public static function acquire( int $ttl = self::TTL, bool $import = true ): bool {
		$name = self::name();

		self::$refused = '';

		if ( isset( self::$held[ $name ] ) ) {
			return false;
		}

		if ( ! self::is_sqlite() ) {
			$got = self::mysql_lock( $name );

			if ( null !== $got ) {
				if ( $got ) {
					self::$held[ $name ] = 'mysql';
					self::$beat_at       = time();
					self::$conn          = self::handle();
				}

				return $got && self::counted( $import );
			}
		}

		if ( Lock::acquire( self::OPTION_LOCK, $ttl ) ) {
			self::$held[ $name ] = 'option';
			self::$beat_at       = time();

			return self::counted( $import );
		}

		return false;
	}

	/**
	 * Count an import that just took the lock; when the count is not saved, free the lock and refuse the import.
	 *
	 * @param bool $import Whether the lock was taken for an import.
	 * @return bool
	 */
	private static function counted( bool $import ): bool {
		if ( ! $import ) {
			self::end_stale_count();

			return true;
		}

		if ( self::next_generation() ) {
			return true;
		}

		self::release();
		self::$refused = __( 'Import refused: the database did not save the import count. Nothing was imported. Import the file again; if this repeats, check the database.', 'perflocale' );

		return false;
	}

	/**
	 * Under an uncounted hold of the lock, make a stopped import's odd count even, so a URL-slug write does not read as
	 * an import; a fresh one may be an import that lost its lock and is left as it is.
	 *
	 * @return void
	 */
	private static function end_stale_count(): void {
		$now = self::stored();

		if ( is_string( $now ) ) {
			$odd = \PerfLocale\Util\Scalar::int( $now );

			if ( 1 === $odd % 2 && time() - $odd >= self::COUNT_FRESH ) {
				self::count_end( $odd );
			}
		}
	}

	/**
	 * Why the last acquire() of an import was refused though the lock was free: a database error ('' otherwise).
	 *
	 * @return string
	 */
	public static function refused(): string {
		return self::$refused;
	}

	/**
	 * Whether this request holds the current site's import lock (an import, or a URL-slug write).
	 *
	 * @return bool
	 */
	public static function held(): bool {
		return isset( self::$held[ self::name() ] );
	}

	/**
	 * Run a standalone URL-slug write under the import lock, in one transaction (section 4, h4 F5).
	 *
	 * Inside an import or a slug write of this request, or inside a transaction its owner registered, the write runs
	 * as it is: a second START TRANSACTION would commit the owner's. The write gets the unit's ConnectionGuard (null
	 * there); a result whose `ok` is false is rolled back.
	 *
	 * @param callable(ConnectionGuard|null): array{ok: bool} $write   The write.
	 * @param int                                             $tries   Attempts to take the lock.
	 * @param int                                             $wait_ms Pause between attempts.
	 * @return array<string, mixed>|false The write's result; false when an import or another slug write holds the lock.
	 * @throws \Throwable What the write throws, after the rollback.
	 */
	public static function with_slug_write( callable $write, int $tries = 1, int $wait_ms = 1000 ): array|false {
		if ( self::held() || TranslationGroupWrites::outer_transaction() ) {
			return $write( null );
		}

		for ( $try = 1; ! self::acquire( self::SLUG_TTL, false ); ++$try ) {
			if ( $try >= $tries ) {
				return false;
			}

			usleep( $wait_ms * 1000 );
		}

		$wpdb  = Typed::wpdb();
		$guard = new ConnectionGuard( 'slug_write' );

		static $shutdown = false;

		if ( ! $shutdown ) {
			$shutdown = true;
			register_shutdown_function( [ self::class, 'end_slug_write' ] );
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $guard->begin() || false === $wpdb->query( 'START TRANSACTION' ) ) {
				return [
					'ok'    => false,
					'error' => 'db_error',
				];
			}

			self::$slug_open = true;
			$result          = $write( $guard );

			// A unit that lost its connection wrote nothing it can commit.
			if ( ! empty( $result['ok'] ) && ! $guard->verify() ) {
				$result = [
					'ok'    => false,
					'error' => 'db_error',
				];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( empty( $result['ok'] ) || false === $wpdb->query( 'COMMIT' ) || ! $guard->kept() ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result['ok'] = false;
				$result['error'] ??= 'db_error';
			}

			self::$slug_open = false;

			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			throw $e;
		} finally {
			self::$slug_open = false;
			$guard->end();
			self::release();
		}
	}

	/**
	 * Shutdown after a fatal error inside a URL-slug write: roll it back and free the lock (finally never ran).
	 *
	 * @internal
	 * @return void
	 */
	public static function end_slug_write(): void {
		if ( self::$slug_open ) {
			self::$slug_open = false;
			Typed::wpdb()->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::release();
		}
	}

	/**
	 * Whether another request holds this site's import lock now (read only; this request's own lock does not count).
	 * A failed read counts as held.
	 *
	 * @return bool
	 */
	public static function running(): bool {
		$name = self::name();

		if ( isset( self::$held[ $name ] ) ) {
			return false;
		}

		if ( self::option_lock_held() ) {
			return true;
		}

		if ( self::is_sqlite() ) {
			return false;
		}

		$wpdb = Typed::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		$was = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$holder = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );
		} finally {
			$wpdb->suppress_errors( $was );
		}

		return '' !== $wpdb->last_error || ( null !== $holder && '' !== (string) $holder );
	}

	/**
	 * Whether the option lock is held by any request, or could not be read.
	 *
	 * @return bool
	 */
	private static function option_lock_held(): bool {
		return Lock::seconds_remaining( self::OPTION_LOCK ) > 0 || '' !== Typed::wpdb()->last_error;
	}

	/**
	 * The import generation, read from the primary past every options cache: a row cached as missing (notoptions)
	 * would hide a later request's first import.
	 *
	 * @return int
	 */
	public static function generation(): int {
		return \PerfLocale\Util\Scalar::int( self::stored() );
	}

	/**
	 * The generation of a writer that fences imports by a locking read of the row: the row is created first where
	 * missing (a site whose seed failed), since a locking read of a missing row locks nothing under READ COMMITTED.
	 *
	 * @return int|null Null when the row could not be read or created.
	 */
	public static function fence_generation(): ?int {
		$now = self::stored();

		if ( null === $now && self::insert_generation() ) {
			$now = self::stored();
		}

		return is_string( $now ) ? \PerfLocale\Util\Scalar::int( $now ) : null;
	}

	/**
	 * The generation and one more option from the primary in one statement (the row created first as in fence_generation()).
	 *
	 * @param string $option The other option.
	 * @return array{generation: int, other: string|null}|null Null when a read failed or the row could not be created.
	 */
	public static function fence_generation_with( string $option ): ?array {
		$rows = self::stored_with( $option );

		if ( null !== $rows && ! isset( $rows[ self::GENERATION_OPTION ] ) && self::insert_generation() ) {
			$rows = self::stored_with( $option );
		}

		if ( null === $rows || ! isset( $rows[ self::GENERATION_OPTION ] ) ) {
			return null;
		}

		return [
			'generation' => \PerfLocale\Util\Scalar::int( $rows[ self::GENERATION_OPTION ] ),
			'other'      => $rows[ $option ] ?? null,
		];
	}

	/**
	 * Whether the generation is still `$generation`, by a locking read held to the caller's transaction end: an import
	 * that starts later waits for it, as its count is written before its first write.
	 *
	 * @param int $generation The count the work began with.
	 * @return bool False as well when the read failed or the row is gone.
	 */
	public static function still( int $generation ): bool {
		$wpdb = Typed::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The locking clause is a fixed literal.
		$sql = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1' . ( self::is_sqlite() ? '' : ' LOCK IN SHARE MODE' ), $wpdb->options, self::GENERATION_OPTION );

		if ( ! is_string( $sql ) || '' === $sql ) {
			return false;
		}

		$value = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; past the caches on purpose.

		return '' === $wpdb->last_error && is_string( $value ) && \PerfLocale\Util\Scalar::int( $value ) === $generation;
	}

	/**
	 * The generation row's value as stored, read from the primary.
	 *
	 * @return string|null|false Null when there is no row, false when the read failed.
	 */
	private static function stored(): string|null|false {
		$wpdb = Typed::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Past the caches on purpose.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::GENERATION_OPTION ) );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return null === $value ? null : \PerfLocale\Util\Scalar::str( $value );
	}

	/**
	 * The generation row and one more option as stored, read from the primary.
	 *
	 * @param string $option The other option.
	 * @return array<string, string>|null By option name; null when the read failed.
	 */
	private static function stored_with( string $option ): ?array {
		$wpdb = Typed::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Past the caches on purpose.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s)', $wpdb->options, self::GENERATION_OPTION, $option ), ARRAY_A );

		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}

		$out = [];

		foreach ( $rows as $row ) {
			$out[ \PerfLocale\Util\Scalar::str( $row['option_name'] ?? '' ) ] = \PerfLocale\Util\Scalar::str( $row['option_value'] ?? '' );
		}

		return $out;
	}

	/**
	 * Create the generation row (0) where none exists, never overwriting one: a locking read of a missing row locks
	 * nothing under READ COMMITTED, so the scope edits' import fence needs the row before a site's first import.
	 *
	 * @return bool Whether the row exists now.
	 */
	public static function ensure_generation(): bool {
		$wpdb = Typed::wpdb();
		$row  = self::insert_generation() ? $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::GENERATION_OPTION ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null !== $row;
	}

	/**
	 * INSERT IGNORE of the generation row at 0, autoload no; add_option() would overwrite a concurrent import's count.
	 *
	 * @return bool False when the statement failed.
	 */
	private static function insert_generation(): bool {
		$wpdb = Typed::wpdb();
		$sql  = $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, '0', 'no')", $wpdb->options, self::GENERATION_OPTION );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return is_string( $sql ) && '' !== $sql && false !== $wpdb->query( $sql );
	}

	/**
	 * Whether a counted import runs now: the generation it was read as is odd, and recent or the lock is held.
	 *
	 * @param int $generation The generation, read before this call.
	 * @return bool
	 */
	public static function counting( int $generation ): bool {
		return 1 === $generation % 2 && ( time() - $generation < self::COUNT_FRESH || self::running() );
	}

	/**
	 * Count an import that took the lock: a compare-and-set on the row (created where missing), checked, to the next
	 * odd value, at least the time (a stopped import may have left one).
	 *
	 * @return bool False when the count was not saved: the import must not run, as no scope edit would see it.
	 */
	private static function next_generation(): bool {
		$now = self::stored();

		if ( null === $now && self::insert_generation() ) {
			$now = self::stored();
		}

		if ( ! is_string( $now ) ) {
			return false;
		}

		$wpdb = Typed::wpdb();
		$next = \PerfLocale\Util\Scalar::int( $now );
		$next = max( $next + ( 1 === $next % 2 ? 2 : 1 ), time() | 1 );
		$sql  = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, (string) $next, self::GENERATION_OPTION, $now );
		$done = is_string( $sql ) && '' !== $sql ? $wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		wp_cache_delete( self::GENERATION_OPTION, 'options' );

		if ( 1 !== $done ) {
			return false;
		}

		self::$counting = $next;

		return true;
	}

	/**
	 * End a counted import: its odd generation becomes the next even one, by a compare-and-set on that value.
	 *
	 * @param int $odd The generation the import set.
	 * @return void
	 */
	private static function count_end( int $odd ): void {
		$wpdb = Typed::wpdb();
		$sql  = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, (string) ( $odd + 1 ), self::GENERATION_OPTION, (string) $odd );

		if ( is_string( $sql ) && '' !== $sql ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		}

		wp_cache_delete( self::GENERATION_OPTION, 'options' );
	}

	/**
	 * Keep this request's lock: extend the option lock, or retake a connection lock a reconnect ended; throttled to TTL/10.
	 *
	 * Never call it inside an open transaction: a rolled-back extension would differ from the value release() deletes.
	 *
	 * @return void
	 * @throws \RuntimeException When this request does not hold the lock: another import took it, or it could not be kept.
	 */
	public static function heartbeat(): void {
		if ( [] === self::$held || ( self::connection_kept() && time() - self::$beat_at < intdiv( self::TTL, 10 ) ) ) {
			return;
		}

		$name = self::name();
		$kind = self::$held[ $name ] ?? '';

		self::$beat_at = time();

		if ( 'option' === $kind ) {
			$kept = Lock::extend( self::OPTION_LOCK, self::TTL );
		} elseif ( 'mysql' === $kind ) {
			if ( '' !== self::$session_lost && ! self::connection_kept() ) {
				throw new \RuntimeException( esc_html( self::$session_lost ) );
			}

			$kept = self::mysql_holder( $name ) ?? self::mysql_lock( $name );

			if ( true === $kept ) {
				self::$conn = self::handle();
			}
		} else {
			return;
		}

		if ( true !== $kept ) {
			throw new \RuntimeException( esc_html( self::busy_message() ) );
		}

		// The count carries the beat, so a reader sees this import as running if its lock goes with its connection.
		if ( null !== self::$counting ) {
			self::count_beat( self::$counting );
		}
	}

	/**
	 * Move a running import's odd count to at least the time, by a compare-and-set on its own value.
	 *
	 * @param int $odd The generation the import set.
	 * @return void
	 */
	private static function count_beat( int $odd ): void {
		$next = max( $odd + 2, time() | 1 );
		$wpdb = Typed::wpdb();
		$sql  = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, (string) $next, self::GENERATION_OPTION, (string) $odd );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( is_string( $sql ) && '' !== $sql && 1 === $wpdb->query( $sql ) ) {
			self::$counting = $next;
		}

		wp_cache_delete( self::GENERATION_OPTION, 'options' );
	}

	/**
	 * Before a Replace transaction, where heartbeat() cannot run: extend the
	 * option lock for REPLACE_TTL; the next heartbeat() runs at once and puts TTL back.
	 *
	 * @return void
	 * @throws \RuntimeException When this request does not hold the lock.
	 */
	public static function hold_for_transaction(): void {
		if ( 'option' !== ( self::$held[ self::name() ] ?? '' ) ) {
			return;
		}

		self::$beat_at = 0;

		if ( ! Lock::extend( self::OPTION_LOCK, self::REPLACE_TTL ) ) {
			throw new \RuntimeException( esc_html( self::busy_message() ) );
		}
	}

	/**
	 * Whether wpdb still uses the connection the lock was taken on; a reconnect ended that lock and any open transaction.
	 *
	 * @return bool True as well when this request holds no connection lock.
	 */
	private static function connection_kept(): bool {
		return null === self::$conn || self::handle() === self::$conn;
	}

	/**
	 * Release the current site's import lock, when this request holds it.
	 *
	 * @return void
	 */
	public static function release(): void {
		$name = self::name();
		$kind = self::$held[ $name ] ?? '';

		// Even before the lock goes, only from this import's own value: a failed write leaves it odd, read as a
		// stopped import once the lock is free.
		if ( null !== self::$counting && '' !== $kind ) {
			self::count_end( self::$counting );
		}

		self::$counting = null;

		self::$session_lost = '';

		unset( self::$held[ $name ] );

		if ( [] === self::$held ) {
			self::$conn = null;
		}

		if ( 'mysql' === $kind ) {
			$wpdb = Typed::wpdb();
			$sql  = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name );

			if ( is_string( $sql ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared on the line above.
				$wpdb->query( $sql );
			}
		} elseif ( 'option' === $kind ) {
			Lock::release( self::OPTION_LOCK );
		}
	}

	/**
	 * The error an import reports when the lock is held.
	 *
	 * @return string
	 */
	public static function busy_message(): string {
		/* translators: "PerfLocale → Jobs" names an admin screen. */
		return __( 'Import refused: another import is running on this site. Wait for it to finish (PerfLocale → Jobs lists background imports), then import the file again.', 'perflocale' );
	}

	/**
	 * Take the connection lock.
	 *
	 * @param string $name Lock name.
	 * @return bool|null True when taken, false when another connection holds it, null when GET_LOCK gave no answer.
	 */
	private static function mysql_lock( string $name ): ?bool {
		$wpdb = Typed::wpdb();

		// HyperDB/LudicrousDB send a SELECT to a replica; the lock must live on the writing connection.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		$was = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		} finally {
			$wpdb->suppress_errors( $was );
		}

		if ( null === $got ) {
			return null;
		}

		return '1' === (string) $got;
	}

	/**
	 * Whether this connection holds the connection lock.
	 *
	 * @param string $name Lock name.
	 * @return bool|null True when this connection holds it, false when another one does or the read failed (asking
	 *                   GET_LOCK again would nest it on this connection), null when it is free.
	 */
	private static function mysql_holder( string $name ): ?bool {
		$wpdb = Typed::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mine = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ) );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return null === $mine ? null : '1' === (string) $mine;
	}

	/**
	 * wpdb's connection handle (a new one after each reconnect).
	 *
	 * @return object|null Null when wpdb exposes none.
	 */
	private static function handle(): ?object {
		$dbh = Typed::wpdb()->__get( 'dbh' );

		return is_object( $dbh ) ? $dbh : null;
	}

	/**
	 * The current site's lock name (DB name and prefix): at most 64 characters, as GET_LOCK requires.
	 *
	 * @return string
	 */
	private static function name(): string {
		$db = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : '';

		return 'perflocale_import_' . md5( ( is_string( $db ) ? $db : '' ) . '|' . Typed::wpdb()->prefix );
	}

	/**
	 * Whether the site runs on the SQLite Database Integration.
	 *
	 * @return bool
	 */
	private static function is_sqlite(): bool {
		return defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' );
	}
}
