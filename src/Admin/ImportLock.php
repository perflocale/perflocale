<?php
/**
 * One data import at a time per site.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Concurrency\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The per-site lock a data import holds from before it reads the file until
 * its last write, whatever started it: the import form (inline or as a
 * background job), `wp perflocale import` and each slice of
 * `wp perflocale network-import`. While it is held, another import on the
 * same site is refused.
 *
 * On MySQL and MariaDB it is a named lock of the database connection
 * (GET_LOCK), which the server frees as soon as the connection ends, so an
 * import that crashes or is killed never leaves it behind. Its name holds the
 * database name and the site's table prefix, so every site of a network, and
 * every install on the same database server, has its own.
 *
 * On SQLite, or where GET_LOCK cannot be used, it is the plugin's option lock
 * (Concurrency\Lock) with a time to live of ten minutes that the import
 * extends as it goes (heartbeat()); after a crash it is free again once that
 * time has run out.
 *
 * heartbeat() runs at least once a minute through every phase of an import:
 * it extends the option lock, and it takes the connection lock again when a
 * reconnect of the database connection (wpdb after "server has gone away")
 * ended it while no other import took it. A reconnect is noticed at the first
 * heartbeat() after it. When the lock has become another import's, or cannot
 * be taken again, heartbeat() throws, and the import stops before its next
 * write.
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
	 * The database connection handle (wpdb's) the connection lock was taken
	 * on, when wpdb exposes one.
	 */
	private static ?object $conn = null;

	/**
	 * Take the current site's import lock.
	 *
	 * @return bool False when another import holds it (or this request
	 *              already does).
	 */
	public static function acquire(): bool {
		$name = self::name();

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

				return $got;
			}
		}

		if ( Lock::acquire( self::OPTION_LOCK, self::TTL ) ) {
			self::$held[ $name ] = 'option';
			self::$beat_at       = time();

			return true;
		}

		return false;
	}

	/**
	 * Keep the lock this request holds: an option lock is extended for
	 * another TTL; a connection lock that a reconnect ended is taken again
	 * while it is free. Does its work at most once per tenth of the TTL, and
	 * at once after a reconnect, so an import calls it as often as it likes.
	 * Never called inside an open transaction: a rolled-back extension would
	 * leave the stored value different from the one this request releases.
	 *
	 * @return void
	 * @throws \RuntimeException When this request no longer holds the lock:
	 *                           another import took it, or it could not be
	 *                           extended or taken again.
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
	}

	/**
	 * Whether the database connection the connection lock was taken on is
	 * still the one wpdb uses. False after a reconnect: the old connection's
	 * lock, and any transaction open on it, ended with it.
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
	 * @return bool|null True when taken, false when another connection holds
	 *                   it, null when GET_LOCK gave no answer.
	 */
	private static function mysql_lock( string $name ): ?bool {
		$wpdb = Typed::wpdb();

		// A database layer with read replicas (HyperDB, LudicrousDB) sends a
		// SELECT to a replica unless told otherwise; the lock must live on the
		// connection that writes.
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
	 * @return bool|null True when this connection holds it, false when
	 *                   another one does, null when it is free.
	 */
	private static function mysql_holder( string $name ): ?bool {
		$wpdb = Typed::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mine = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name ) );

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
	 * The lock name of the current site: at most 64 characters, as GET_LOCK
	 * requires.
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
