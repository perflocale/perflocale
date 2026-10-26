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
	 * @return bool False when another import holds it (or this request already does).
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
	 * @return bool|null True when this connection holds it, false when another one does, null when it is free.
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
