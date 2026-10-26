<?php
/**
 * Ties a unit of database writes to the connection it began on.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A session marker for one unit of writes (a transaction, a page of rows).
 *
 * WordPress's wpdb resends a statement after a lost connection on a new autocommit connection; every guarded write carries a
 * condition on a per-unit user variable, so there it matches nothing. See docs-ai/11-code-rationale.md, "ConnectionGuard: lost connections".
 */
final class ConnectionGuard {

	/**
	 * The user variable, `@perflocale_<name>`.
	 *
	 * @var string
	 */
	private string $variable;

	/**
	 * The unit's token; '' when no unit is open.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * The connection handle of wpdb when the unit began, when wpdb exposes one.
	 *
	 * @var object|null
	 */
	private ?object $handle = null;

	/**
	 * Whether a unit is open on MySQL or MariaDB.
	 *
	 * @var bool
	 */
	private bool $open = false;

	/**
	 * The guard of one kind of unit.
	 *
	 * @param string $name Lowercase letters, digits and underscores: the
	 *                     variable is `@perflocale_<name>`.
	 * @throws \InvalidArgumentException For any other name.
	 */
	public function __construct( string $name ) {
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $name ) ) {
			throw new \InvalidArgumentException( 'ConnectionGuard: invalid name.' );
		}

		$this->variable = '@perflocale_' . $name;
	}

	/**
	 * Whether the database can lose a connection under a unit (MySQL and
	 * MariaDB, not the SQLite Database Integration).
	 *
	 * @return bool
	 */
	public static function applies(): bool {
		return ! ( defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) );
	}

	/**
	 * Row-lock suffix for a locking read: ' FOR UPDATE' on MySQL and MariaDB, '' on SQLite (one writer per database;
	 * the SQLite integration's 2.2.x legacy driver refuses FOR UPDATE).
	 *
	 * @return literal-string
	 */
	public static function lock_suffix(): string {
		return self::applies() ? ' FOR UPDATE' : '';
	}

	/**
	 * Open a unit: set the marker (a random token) on the current connection, before START TRANSACTION, never after.
	 *
	 * Never CONNECTION_ID() instead: ids start again after a server restart.
	 *
	 * @return bool False when the marker could not be set.
	 */
	public function begin(): bool {
		$this->end();

		if ( ! self::applies() ) {
			return true;
		}

		$wpdb        = self::wpdb();
		$this->token = bin2hex( random_bytes( 16 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- See marker(): a checked name and a hex token.
		if ( false === $wpdb->query( 'SET ' . $this->marker() ) ) {
			$this->token = '';

			return false;
		}

		$this->handle = self::handle();
		$this->open   = true;

		return true;
	}

	/**
	 * Close the unit; the variable stays on the connection and the next unit gets a new token.
	 *
	 * @return void
	 */
	public function end(): void {
		$this->open   = false;
		$this->token  = '';
		$this->handle = null;
	}

	/**
	 * Whether wpdb still uses the connection the unit began on (no query; true without an open unit or a handle).
	 *
	 * @phpstan-impure
	 * @return bool
	 */
	public function holds(): bool {
		return ! $this->open || null === $this->handle || self::handle() === $this->handle;
	}

	/**
	 * Whether the unit ran on one connection up to here: the handle when wpdb exposes one, else the marker read back.
	 *
	 * No query when the handle is known, so a connection lost after a successful COMMIT is not taken for a lost unit.
	 *
	 * @return bool
	 */
	public function kept(): bool {
		if ( ! $this->open ) {
			return true;
		}

		return null !== $this->handle ? $this->holds() : $this->verify();
	}

	/**
	 * Whether the marker is still set on the connection wpdb uses: holds(), then one read on the primary.
	 *
	 * @return bool
	 */
	public function verify(): bool {
		if ( ! $this->open ) {
			return true;
		}

		if ( ! $this->holds() ) {
			return false;
		}

		$wpdb = self::wpdb();

		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		$was = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- See marker(): a checked name and a hex token.
			$same = $wpdb->get_var( 'SELECT ' . $this->marker() );
		} finally {
			$wpdb->suppress_errors( $was );
		}

		return '1' === (string) $same && $this->holds();
	}

	/**
	 * The SQL condition a guarded DELETE or UPDATE adds to its WHERE clause; '' when no unit is open.
	 *
	 * @return string
	 */
	public function condition(): string {
		if ( ! $this->open ) {
			return '';
		}

		return $this->marker();
	}

	/**
	 * `@perflocale_<name> = '<token>'`, without prepare(): the name is checked and the token is hex, so neither holds a quote.
	 *
	 * @return string
	 */
	private function marker(): string {
		return $this->variable . " = '" . $this->token . "'";
	}

	/**
	 * Insert a row as wpdb::insert() does, its VALUES sent as `SELECT ... FROM DUAL WHERE <marker>`.
	 *
	 * A statement another plugin's `query` filter reshaped is sent unchanged and the marker read back after it.
	 *
	 * @param string                    $table  Table name.
	 * @param array<string, mixed>      $row    Column => value.
	 * @param array<int, string>|string $format Formats, as for wpdb::insert().
	 * @return int|false 1 when written, 0 when the marker did not match (connection lost), false on a database error.
	 */
	public function insert( string $table, array $row, array|string $format ): int|false {
		$wpdb = self::wpdb();

		if ( ! $this->open ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return $wpdb->insert( $table, $row, $format );
		}

		$rewritten = false;
		$prefix    = 'INSERT INTO `' . $table . '` (';
		$condition = $this->condition();

		$rewrite = static function ( $query ) use ( &$rewritten, $prefix, $condition ) {
			if ( $rewritten || ! is_string( $query ) || ! str_starts_with( $query, $prefix ) || ! str_ends_with( $query, ')' ) ) {
				return $query;
			}

			// The column list holds backquoted names only, so the first ") VALUES (" ends it.
			$at = strpos( $query, ') VALUES (' );

			if ( false === $at ) {
				return $query;
			}

			$rewritten = true;

			return substr( $query, 0, $at ) . ') SELECT ' . substr( $query, $at + 10, -1 ) . ' FROM DUAL WHERE ' . $condition;
		};

		add_filter( 'query', $rewrite, PHP_INT_MAX );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert( $table, $row, $format );
		} finally {
			remove_filter( 'query', $rewrite, PHP_INT_MAX );
		}

		if ( false === $inserted ) {
			return false;
		}

		if ( ! $rewritten && ! $this->verify() ) {
			return 0;
		}

		return $this->holds() ? (int) $inserted : 0;
	}

	/**
	 * The connection handle of wpdb (a new one after each reconnect).
	 *
	 * @return object|null Null when wpdb exposes none.
	 */
	private static function handle(): ?object {
		$dbh = self::wpdb()->__get( 'dbh' );

		return is_object( $dbh ) ? $dbh : null;
	}

	/**
	 * The global wpdb.
	 *
	 * @return \wpdb
	 */
	private static function wpdb(): \wpdb {
		return \PerfLocale\Admin\Typed::wpdb();
	}
}
