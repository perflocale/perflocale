<?php
/**
 * One transaction unit for every write that binds slug-keyed URL bases data or the language rows.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\Tx\Outcome;
use PerfLocale\Database\Tx\Stop;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens the request's one plugin transaction, refuses foreign statements that would end it, sees a failed statement or an
 * ended transaction before COMMIT, and holds option publication until a confirmed COMMIT.
 *
 * Cold: loaded only by the writers that call run(). Docs: docs-ai/11-code-rationale.md, "One transaction unit".
 *
 * @since 1.0.8
 * @internal
 */
final class Tx {

	/**
	 * The text a refused statement leaves in `$wpdb->last_error`.
	 */
	public const REFUSAL = 'PerfLocale: statement refused inside a transaction';

	/**
	 * Statements that end, commit or roll back a transaction (MySQL's implicit-commit list), on the statement's shape().
	 */
	private const REFUSED = '/^(?:START|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE|SET\s+(?:(?:SESSION|GLOBAL|LOCAL)\s+|@@(?:SESSION\.|GLOBAL\.|LOCAL\.)?)?TRANSACTION|SET\s+PASSWORD|LOCK\s+TABLES?|UNLOCK\s+TABLES?|CREATE|ALTER|DROP|TRUNCATE|RENAME|XA|FLUSH|ANALYZE|OPTIMIZE|REPAIR|CHECK|CACHE\s+INDEX|LOAD\s+INDEX|RESET|GRANT|REVOKE|INSTALL|UNINSTALL|IMPORT|STOP|CHANGE|CALL)\b/i';

	/**
	 * A SET that assigns autocommit anywhere in its list (`SET NAMES x, autocommit = 1` too), on the statement's shape().
	 */
	private const AUTOCOMMIT = '/^SET\b.*\bautocommit\s*:?=/is';

	/**
	 * One token for shape(): a quoted string, a backquoted name, an executable comment, a comment, or whitespace.
	 */
	private const TOKENS = '~\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`((?:[^`]++|``)*+)`|/\*(M?!)\d{0,6}(.*?)\*/|/\*.*?(?:\*/|$)|(?:--(?=\s|$)|#)[^\n]*+|\s++~s';

	/**
	 * The open unit; null when none.
	 *
	 * @var self|null
	 */
	private static ?self $current = null;

	/**
	 * Whether the shutdown rollback is registered in this request.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Leases of open leased() scopes: id => [ lock name, blog id ]. A fatal skips the scope's end; shutdown releases them.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private static array $leases = [];

	/**
	 * Last leased() scope id.
	 *
	 * @var int
	 */
	private static int $scope = 0;

	/**
	 * The unit's connection guard.
	 *
	 * @var ConnectionGuard
	 */
	private ConnectionGuard $guard;

	/**
	 * The `query` filter of this unit.
	 *
	 * @var \Closure(mixed): mixed
	 */
	private \Closure $filter;

	/**
	 * Why the unit is dead; '' while alive.
	 *
	 * @var string
	 */
	private string $dead = '';

	/**
	 * Times the unit was made dead (0 or 1): send() sees a kill during its own call.
	 *
	 * @var int
	 */
	private int $kills = 0;

	/**
	 * The own statement being sent, passed once by the filter; '' when none (a reentrant statement is classified).
	 *
	 * @var string
	 */
	private string $own = '';

	/**
	 * The filter refused an own statement of a unit killed while it was being sent.
	 *
	 * @var bool
	 */
	private bool $withheld = false;

	/**
	 * Killed during an own call (a listener's statement), or another statement ran in place of one: never done.
	 *
	 * @var bool
	 */
	private bool $foreign = false;

	/**
	 * START TRANSACTION was sent: from here a statement error makes the unit dead.
	 *
	 * @var bool
	 */
	private bool $armed = false;

	/**
	 * A unit.
	 *
	 * @param string $name ConnectionGuard name.
	 */
	private function __construct( string $name ) {
		$this->guard  = new ConnectionGuard( $name );
		$this->filter = fn( $query ) => $this->filter( $query );
	}

	/**
	 * Run $body in one transaction: COMMIT only when it returns true and no statement failed or ended the transaction.
	 *
	 * @param string                        $name      ConnectionGuard name (`@perflocale_<name>`).
	 * @param callable(self): bool          $body      True: COMMIT; false: ROLLBACK. A throw rolls back and is rethrown.
	 * @param (callable(): ?bool)|null      $confirm   After an unconfirmed COMMIT: true done, false not done, null unknown.
	 * @param string                        $isolation '' or 'REPEATABLE READ' (sent before START TRANSACTION on MySQL).
	 * @param (callable(string): void)|null $after     Given 'done' or 'unknown': the caller's cache invalidation, before any
	 *                                                 held completion action runs (a listener may throw).
	 * @return Outcome
	 * @throws \Throwable What $body throws (but Stop), after the rollback.
	 */
	public static function run( string $name, callable $body, ?callable $confirm = null, string $isolation = '', ?callable $after = null ): Outcome {
		// Nested inside another plugin transaction (a hook): refused before any statement; its START would commit that one.
		if ( self::open() ) {
			return new Outcome( 'busy', 'open' );
		}

		self::register();

		$tx            = new self( $name );
		$status        = 'failed';
		$why           = 'body';
		$ended         = false;
		$unconfirmed   = false;
		$capped        = false;
		$started       = false;
		$thrown        = null;
		self::$current = $tx;
		add_filter( 'query', $tx->filter, PHP_INT_MAX );

		try {
			$started = ( '' === $isolation || $tx->send( 'isolation' ) ) && $tx->begin();

			if ( ! $started ) {
				$status = 'busy';
				$why    = 'start';
			} else {
				$tx->armed = true;

				// The witness: discarded when the transaction ends underneath (implicit COMMIT, server rollback).
				if ( ConnectionGuard::applies() ) {
					$tx->send( 'savepoint' );
				}

				if ( ! OptionWriter::hold( $tx ) ) {
					$tx->kill( 'hold' );
				}

				$commit = false;

				if ( ! $tx->alive() ) {
					$why = $tx->reason();
				} else {
					try {
						$commit = (bool) $body( $tx );
					} catch ( Stop $e ) {
						$commit = false;
						$why    = $tx->reason();
					}
				}

				if ( $commit && ConnectionGuard::applies() && $tx->alive() ) {
					$tx->send( 'release' );
				}

				if ( $commit && ! $tx->alive() ) {
					$why = $tx->reason();
				} elseif ( $commit ) {
					// kept() before and after: a COMMIT resent on a new connection commits nothing.
					$ended = $tx->guard->kept() && $tx->send( 'commit' ) && $tx->guard->kept();

					if ( $ended && '' === $tx->dead ) {
						$status = 'done';
						$why    = '';
					} elseif ( $ended ) {
						// Killed while its COMMIT ran (a listener's statement): never done.
						$unconfirmed = true;
						$capped      = true;
						$why         = $tx->reason();
					} else {
						// The transaction is over (rolled back, or committed with its reply lost): end what is open on this connection.
						// A replaced ROLLBACK leaves it to the guard's.
						$ended       = $tx->send( 'rollback' ) || ! $tx->foreign;
						$unconfirmed = ! $tx->withheld;
						$capped      = $tx->foreign;
						$why         = $tx->withheld || $tx->foreign ? $tx->reason() : 'commit';
					}
				}
			}
		} catch ( \Throwable $e ) {
			$thrown = $e;
			$status = 'failed';
		}

		remove_filter( 'query', $tx->filter, PHP_INT_MAX );

		try {
			// ROLLBACK unless ended; the request's transaction is free before any completion action runs.
			$tx->guard->finish( $ended );
		} finally {
			self::$current = null;

			if ( $unconfirmed && null === $thrown ) {
				$done   = null !== $confirm ? $confirm() : null;
				$status = false === $done ? 'failed' : ( true === $done && ! $capped ? 'done' : 'unknown' );
			}

			$then    = null !== $after && ( 'done' === $status || 'unknown' === $status ) ? static fn() => $after( $status ) : null;
			$outcome = new Outcome( $status, $why, OptionWriter::settle( 'done' === $status, $tx, $then ) );
		}

		if ( null !== $thrown ) {
			throw $thrown;
		}

		return $outcome;
	}

	/**
	 * Run every step even when one throws (mandatory invalidation), then rethrow the first throw.
	 *
	 * @param \Closure ...$steps Steps.
	 * @phpstan-param \Closure(): void ...$steps
	 * @return void
	 * @throws \Throwable The first step's, after every step ran.
	 */
	public static function each( \Closure ...$steps ): void {
		$first = null;

		foreach ( $steps as $step ) {
			try {
				$step();
			} catch ( \Throwable $e ) {
				$first ??= $e;
			}
		}

		if ( null !== $first ) {
			throw $first;
		}
	}

	/**
	 * Lock::with() for the lease a unit runs under: a fatal error inside the scope leaves no lease row (shutdown releases it).
	 *
	 * @param string            $lock    Lock name.
	 * @param int               $ttl     Lease in seconds.
	 * @param callable(): mixed $section Critical section.
	 * @return mixed What $section returns; null when the lease is busy.
	 */
	public static function leased( string $lock, int $ttl, callable $section ): mixed {
		self::register();

		$id                  = ++self::$scope;
		self::$leases[ $id ] = [ $lock, function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ];

		try {
			return Lock::with( $lock, $ttl, $section );
		} finally {
			unset( self::$leases[ $id ] );
		}
	}

	/**
	 * Register shutdown() once per request.
	 *
	 * @return void
	 */
	private static function register(): void {
		if ( ! self::$registered ) {
			self::$registered = true;
			register_shutdown_function( [ self::class, 'shutdown' ] );
		}
	}

	/**
	 * Whether a plugin transaction is open in this request (a unit, or the Replace import's).
	 *
	 * @return bool
	 */
	public static function open(): bool {
		return ConnectionGuard::in_transaction();
	}

	/**
	 * The open unit; null when none.
	 *
	 * @return self|null
	 */
	public static function current(): ?self {
		return self::$current;
	}

	/**
	 * The unit's connection guard.
	 *
	 * @return ConnectionGuard
	 */
	public function guard(): ConnectionGuard {
		return $this->guard;
	}

	/**
	 * ' AND <guard condition>' for a write of this unit; '' on SQLite.
	 *
	 * @return string
	 */
	public function and(): string {
		$condition = $this->guard->condition();

		return '' !== $condition ? ' AND ' . $condition : '';
	}

	/**
	 * Whether the unit can still commit: no statement failed and wpdb is on the connection it began on.
	 *
	 * @return bool
	 */
	public function alive(): bool {
		$this->observe();

		if ( '' === $this->dead && ! $this->guard->holds() ) {
			$this->kill( 'connection' );
		}

		return '' === $this->dead;
	}

	/**
	 * Stop the body when the unit is dead.
	 *
	 * @return void
	 * @throws Stop When a statement failed or the connection was lost.
	 */
	public function check(): void {
		if ( ! $this->alive() ) {
			throw new Stop( $this->dead ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by run(), never printed.
		}
	}

	/**
	 * Send a statement of the body; a failure or a lost connection makes the unit dead.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int|false Rows affected; false on failure.
	 */
	public function query( string $sql ): int|false {
		$wpdb = \PerfLocale\Admin\Typed::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The caller's prepared statement.
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			$this->kill( 'error: ' . $wpdb->last_error );
		}

		$this->alive();

		return false === $result ? false : (int) $result;
	}

	/**
	 * Why the unit is dead, as an outcome reason.
	 *
	 * @return string
	 */
	private function reason(): string {
		return '' !== $this->dead ? 'dead: ' . $this->dead : 'body';
	}

	/**
	 * Mark the unit dead (the first reason stays).
	 *
	 * @param string $why Reason.
	 * @return void
	 */
	private function kill( string $why ): void {
		if ( '' === $this->dead ) {
			$this->dead = '' !== $why ? substr( $why, 0, 120 ) : 'error';
			++$this->kills;
		}
	}

	/**
	 * A failed statement after START (its error still in `$wpdb->last_error`) makes the unit dead.
	 *
	 * @return void
	 */
	private function observe(): void {
		$error = \PerfLocale\Admin\Typed::wpdb()->last_error;

		if ( $this->armed && '' === $this->dead && '' !== $error ) {
			$this->kill( self::REFUSAL === $error ? 'refused' : 'error: ' . $error );
		}
	}

	/**
	 * The guard's claim, then START TRANSACTION as an own statement.
	 *
	 * @return bool
	 */
	private function begin(): bool {
		$started = false;

		try {
			$started = $this->guard->begin( true ) && $this->send( 'start' );
		} finally {
			if ( ! $started ) {
				$this->guard->end();
			}
		}

		return $started;
	}

	/**
	 * Send one own statement (fixed SQL); a failure or a lost connection makes the unit dead, except for ROLLBACK.
	 *
	 * @param string $which 'isolation', 'start', 'savepoint', 'release', 'commit' or 'rollback'.
	 * @return bool Whether it succeeded.
	 */
	private function send( string $which ): bool {
		$wpdb  = \PerfLocale\Admin\Typed::wpdb();
		$sent  = false;
		$kills = $this->kills;

		try {
			// $own lets exactly this statement through the filter, during this call only.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			switch ( $which ) {
				case 'isolation':
					$this->own = 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ';
					$sent      = ConnectionGuard::applies();
					$ok        = ! $sent || false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
					break;
				case 'start':
					$this->own = 'START TRANSACTION';
					$ok        = false !== $wpdb->query( 'START TRANSACTION' );
					break;
				case 'savepoint':
					$this->own = 'SAVEPOINT perflocale_tx';
					$ok        = false !== $wpdb->query( 'SAVEPOINT perflocale_tx' );
					break;
				case 'release':
					// A discarded savepoint answers 1305: an expected error, not printed.
					$was       = $wpdb->suppress_errors( true );
					$this->own = 'RELEASE SAVEPOINT perflocale_tx';
					$ok        = false !== $wpdb->query( 'RELEASE SAVEPOINT perflocale_tx' );
					$wpdb->suppress_errors( $was );
					break;
				case 'commit':
					$this->own = 'COMMIT';
					$ok        = false !== $wpdb->query( 'COMMIT' );
					break;
				default:
					$this->own = 'ROLLBACK';
					$ok        = false !== $wpdb->query( 'ROLLBACK' );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} finally {
			$this->own = '';
		}

		// Killed during its own call: whatever the transport reports.
		if ( $kills !== $this->kills ) {
			$this->foreign = true;
		}

		// A later `query` filter can replace it after this unit's filter passed it: what ran is not this unit's statement.
		if ( $ok && ( 'isolation' !== $which || $sent ) && ! self::ran( $which ) ) {
			$this->kill( 'replaced: ' . $which );
			$this->foreign = true;
			$ok            = false;
		}

		if ( ! $ok && 'rollback' !== $which ) {
			$this->kill( $which . ': ' . $wpdb->last_error );
		}

		return $ok;
	}

	/**
	 * Whether the statement wpdb ran last (`last_query`, after every `query` filter) is this own statement.
	 *
	 * @param string $which As send().
	 * @return bool
	 */
	private static function ran( string $which ): bool {
		$own  = [
			'isolation' => 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
			'start'     => 'START TRANSACTION',
			'savepoint' => 'SAVEPOINT perflocale_tx',
			'release'   => 'RELEASE SAVEPOINT perflocale_tx',
			'commit'    => 'COMMIT',
		][ $which ] ?? 'ROLLBACK';
		$last = \PerfLocale\Admin\Typed::wpdb()->last_query;

		return 0 === strcasecmp( (string) self::shape( (string) $last ), $own );
	}

	/**
	 * The `query` filter while the unit is open: an earlier failed statement makes it dead; a dead unit and any statement
	 * that would end the transaction are refused (wpdb then returns false without sending anything).
	 *
	 * @param mixed $query Statement.
	 * @return mixed The statement, or '' when refused.
	 */
	private function filter( mixed $query ): mixed {
		$this->observe();

		if ( ! is_string( $query ) ) {
			return $query;
		}

		if ( '' !== $this->own && 0 === strcasecmp( (string) self::shape( $query ), $this->own ) ) {
			$this->own = '';

			// A unit a reentrant statement killed sends only its ROLLBACK.
			if ( '' === $this->dead || 'ROLLBACK' === $query ) {
				return $query;
			}

			$this->withheld = true;
		} elseif ( '' === $this->dead ) {
			$shape = self::shape( $query );

			// A statement that cannot be read is refused (a failed read never grants).
			if ( null !== $shape && 1 !== preg_match( self::REFUSED, $shape ) && 1 !== preg_match( self::AUTOCOMMIT, $shape ) ) {
				return $query;
			}

			$this->kill( 'refused: ' . strtoupper( (string) strtok( (string) $shape, ' ' ) ) );
		}

		\PerfLocale\Admin\Typed::wpdb()->last_error = self::REFUSAL;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Support aid, only with WP_DEBUG.
			error_log( '[PerfLocale] statement refused inside a transaction: ' . substr( $query, 0, 80 ) );
		}

		return '';
	}

	/**
	 * The statement as MySQL reads it: comments dropped, an executable comment (opened by slash-star-bang, with an optional
	 * M and version) as its content, quoted strings as '?', backquoted names unquoted, spaces collapsed; null when unreadable.
	 *
	 * @param string $sql Statement.
	 * @return string|null
	 */
	private static function shape( string $sql ): ?string {
		$out = preg_replace_callback(
			self::TOKENS,
			static function ( array $m ): string {
				$first = $m[0][0];

				if ( "'" === $first || '"' === $first ) {
					return "'?'";
				}

				if ( '`' === $first ) {
					return str_replace( '``', '`', $m[1] ?? '' );
				}

				// Executed by the server (any version): read as its content.
				if ( 4 === count( $m ) ) {
					return ' ' . self::shape( $m[3] ) . ' ';
				}

				return ' ';
			},
			$sql
		);

		return null === $out ? null : trim( (string) preg_replace( '/ {2,}/', ' ', $out ) );
	}

	/**
	 * At shutdown, a unit a fatal error left open: ROLLBACK, release the claim, drop held publication; then the leases of
	 * leased() scopes it left open.
	 *
	 * @internal Registered by run() and leased().
	 * @return void
	 */
	public static function shutdown(): void {
		$tx            = self::$current;
		self::$current = null;

		try {
			if ( null !== $tx ) {
				remove_filter( 'query', $tx->filter, PHP_INT_MAX );

				try {
					$tx->guard->finish( false );
				} finally {
					OptionWriter::settle( false, $tx );
				}
			}
		} finally {
			self::release_leases();
		}
	}

	/**
	 * Release the leases of leased() scopes still open, newest first; Lock::release() deletes only this request's own row.
	 *
	 * @return void
	 */
	private static function release_leases(): void {
		$leases       = array_reverse( self::$leases );
		self::$leases = [];

		foreach ( $leases as [ $lock, $blog ] ) {
			// The row lives in the options table of the blog that took it.
			$moved = $blog > 0 && (int) get_current_blog_id() !== $blog;

			if ( $moved ) {
				switch_to_blog( $blog );
			}

			try {
				Lock::release( $lock );
			} finally {
				if ( $moved ) {
					restore_current_blog();
				}
			}
		}
	}
}
