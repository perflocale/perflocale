<?php
/**
 * Upgrade runner: schema steps, upgrade tasks and add-on steps under one lock, with backoff.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

use PerfLocale\Concurrency\Lock;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs pending upgrades for the current blog: one runner at a time (Lock), a failed step retried with backoff, every
 * failure kept in one non-autoloaded option until the step succeeds.
 *
 * Loaded only when an upgrade is pending, on Retry now and on Site Health; Migrator keeps the current-site checks.
 *
 * @since 1.0.7.5
 * @internal
 */
final class UpgradeRunner {

	/** Per-blog state: scope => step, attempts, error, next, since, last, running (+ done, target for `update`; refusal, cause, manual for `schema`). */
	public const STATE_OPTION = 'perflocale_upgrade_state';

	/** Lock name; the row lives in the blog's own options table. */
	public const LOCK = 'upgrade';

	/** Lock TTL, renewed after each step and task: one ALTER on a large table can run for many minutes. */
	private const LOCK_TTL = 3600;

	/** Longest metadata-lock wait (seconds) of a dbDelta statement on MySQL/MariaDB; well below LOCK_TTL. */
	private const DDL_LOCK_WAIT = 600;

	/** Seconds before the next try after the 1st, 2nd, 3rd, 4th and later failure; Migrator::maybe_update() mirrors the last. */
	private const BACKOFF = [ 60, 300, 900, 3600 ];

	/** Missing UNIQUE indexes one explicit check reads for duplicate rows. */
	private const PROBE_INDEXES = 2;

	/** Rows each of those duplicate reads looks at. */
	private const PROBE_ROWS = 100000;

	/** Seconds between the cheap checks of a step stopped for its operator. */
	private const STOPPED_CHECK = 3600;

	/** Equal identified refusals in a row that stop automatic tries; more than one, as a grant or table may be changing. */
	private const REFUSALS = 3;

	/** DbErrorCode codes that pass by themselves. */
	private const TEMPORARY = [ 'deadlock', 'lock_timeout', 'connection', 'server_busy' ];

	/** DbErrorCode codes that repeat until someone acts; only these count toward a stop (`other` is no diagnosis). */
	private const PERMANENT = [ 'duplicate_key', 'too_long', 'bad_value', 'not_null', 'missing_table', 'missing_column', 'key_too_long', 'denied', 'syntax' ];

	/** Exception code of PerfLocale's own upgrade-path throws, whose message describe() keeps. */
	public const OWN_ERROR = 0x50464C45;

	/**
	 * Whether retry() is running: every scope is due.
	 *
	 * @var bool
	 */
	private static bool $force = false;

	/**
	 * Cause of the last failed tables check of a current schema (not stored: nothing is pending).
	 *
	 * @var string
	 */
	private static string $repair_error = '';

	/**
	 * Same-name keys the last missing_parts() found with another shape: table => key => declared unique, parts (never PRIMARY).
	 *
	 * @var array<string, array<string, array{0: bool, 1: list<string>}>>
	 */
	private static array $reshape = [];

	/**
	 * `table index name` of the UNIQUE keys the running attempt found blocked by duplicate rows.
	 *
	 * @var list<string>
	 */
	private static array $duplicates = [];

	/**
	 * Missing UNIQUE keys of the running attempt's last inspection.
	 *
	 * @var list<string>
	 */
	private static array $missing_unique = [];

	/**
	 * Fingerprint and streak of the running attempt's identified refusal (empty when none).
	 *
	 * @var array{sig?: string, n?: int}
	 */
	private static array $refusal = [];

	/**
	 * Whether the server itself reported a duplicate key in the running attempt.
	 *
	 * @var bool
	 */
	private static bool $server_duplicate = false;

	/** Whether the running attempt's stop is a refusal of the database user's privileges. */
	private static bool $denied = false;

	/**
	 * Whether that refusal reached REFUSALS in a row: the step stops for its operator.
	 *
	 * @var bool
	 */
	private static bool $refused = false;

	/**
	 * The object holding the migrate_to_N() steps.
	 *
	 * @var object
	 */
	private object $steps;

	/**
	 * @param object|null $steps migrate_to_N() holder; SchemaMigrations by default.
	 */
	public function __construct( ?object $steps = null ) {
		$this->steps = $steps ?? new SchemaMigrations();
	}

	/**
	 * Migrator's pending-path entry: run one scope, then hook the notice; never throws (it runs on wp_loaded).
	 *
	 * @param string $scope 'schema' or 'update'.
	 * @return void
	 */
	public static function pending( string $scope ): void {
		try {
			$runner = new self();
			$result = 'schema' === $scope ? $runner->schema() : $runner->update();

			if ( is_admin() && [] !== self::failures() && false === has_action( 'admin_notices', [ \PerfLocale\Admin\UpgradeNotice::class, 'render' ] ) ) {
				add_action( 'admin_notices', [ \PerfLocale\Admin\UpgradeNotice::class, 'render' ] );
			}

			// The other sites of the network are behind too: walk them, so none waits for a visit.
			if ( is_multisite() ) {
				NetworkUpgrade::arm( false, 'done' === $result );
			}
		} catch ( \Throwable $e ) {
			self::log( $scope, self::describe( $e ) );
		}
	}

	/**
	 * A throwable as text to store, show and log: the message of PerfLocale's own OWN_ERROR throw, else its class and
	 * place only, as a foreign message can carry credentials or data.
	 *
	 * @param \Throwable $e The throwable.
	 * @return string
	 */
	public static function describe( \Throwable $e ): string {
		$file = str_replace( '\\', '/', $e->getFile() );

		if ( self::OWN_ERROR === $e->getCode() && str_starts_with( $file, str_replace( '\\', '/', dirname( __DIR__ ) ) . '/' ) ) {
			return $e->getMessage();
		}

		$place = '<path>/' . basename( $file );

		foreach ( [ '<content>/' => 'WP_CONTENT_DIR', '<wp>/' => 'ABSPATH' ] as $label => $name ) {
			$dir = rtrim( str_replace( '\\', '/', defined( $name ) ? (string) constant( $name ) : '' ), '/' ) . '/';

			if ( '/' !== $dir && str_starts_with( $file, $dir ) ) {
				$place = $label . substr( $file, strlen( $dir ) );
				break;
			}
		}

		/* translators: 1: exception class, 2: file and line */
		return sprintf( __( '%1$s thrown at %2$s.', 'perflocale' ), explode( "\0", get_class( $e ) )[0], $place . ':' . $e->getLine() );
	}

	/**
	 * Run the pending schema steps: migrate_to_N, dbDelta, full verification, then record N.
	 *
	 * @param bool $repair Also re-apply and verify a current schema (Retry now, WP-CLI).
	 * @return string 'done', 'current', 'waiting' (backoff), 'busy' (another runner) or 'failed'.
	 */
	public function schema( bool $repair = false ): string {
		if ( ! $repair && ! self::due( 'schema' ) ) {
			return 'waiting';
		}

		if ( ! Lock::acquire( self::LOCK, self::LOCK_TTL ) ) {
			return 'busy';
		}

		$target = (int) PERFLOCALE_DB_VERSION;

		try {
			self::refresh();

			// Not get_option(): this request's autoloaded copy predates a runner that finished while it waited.
			$read = self::stored( 'perflocale_db_version' );

			// Not "no row": 0 would pass the newer-schema refusal below and run every step.
			if ( false === $read ) {
				return self::unreadable_version( 'schema', $repair );
			}

			$old = (int) $read;

			if ( $old >= $target ) {
				self::clear( 'schema' );

				// A newer build's schema: repairing it to this build's shape would undo that build's keys.
				if ( $repair && $old > $target ) {
					/* translators: 1: stored database schema version, 2: this version's schema version. */
					self::$repair_error = sprintf( __( 'The database schema (version %1$d) is newer than this version of PerfLocale (%2$d), so its tables were not changed: update PerfLocale first.', 'perflocale' ), $old, $target );
					self::log( __( 'Database tables check', 'perflocale' ), self::$repair_error );
					return 'failed';
				}

				if ( $repair ) {
					self::$repair_error = $this->apply_and_verify( true );

					if ( '' !== self::$repair_error ) {
						self::log( __( 'Database tables check', 'perflocale' ), self::$repair_error );
						return 'failed';
					}
				}

				return 'current';
			}

			// Rechecked under the lock: a runner that failed while this request waited set a new backoff.
			if ( ! $repair && ! self::due( 'schema' ) ) {
				return 'waiting';
			}

			// A step stopped for its operator stays stopped unless a point read proves the blocking rows changed.
			if ( ! $repair && self::still_blocked( 'schema' ) ) {
				return 'waiting';
			}

			for ( $version = $old + 1; $version <= $target; $version++ ) {
				/* translators: %d: database schema version. */
				$step = sprintf( __( 'Database schema version %d', 'perflocale' ), $version );

				// Recorded before the step, so a run killed by a time or memory limit still backs off and shows.
				if ( ! self::claim( 'schema', $step ) ) {
					return 'busy';
				}

				$error = $this->step( $version, $repair );

				if ( ! self::owns() ) {
					return 'busy';
				}

				// Before the last stamp, so a stop, a lost lock or a throw leaves the schema pending and the next run repeats
				// the step and the sweep (both idempotent).
				if ( '' === $error && $version === $target ) {
					$error = self::sweep_left_by_tasks();

					if ( null === $error ) {
						return 'busy';
					}
				}

				if ( '' === $error ) {
					update_option( 'perflocale_db_version', $version, true );
					$error = self::saved( 'perflocale_db_version', $version );
				}

				if ( '' !== $error ) {
					self::fail( 'schema', $step, $error, self::stop_fields() );
					return 'failed';
				}

				self::clear( 'schema' );
			}

			self::clear( 'schema' );
		} finally {
			Lock::release( self::LOCK );
		}

		/** @hook perflocale/upgraded Fires after a database migration. */
		do_action( 'perflocale/upgraded', (string) $old, (string) $target );

		return 'done';
	}

	/**
	 * One schema step; '' when it applied and verified, else the cause.
	 *
	 * @param int  $version Target version of the step.
	 * @param bool $probe   Name duplicate rows behind a missing UNIQUE index (Retry now, WP-CLI).
	 * @return string
	 */
	private function step( int $version, bool $probe ): string {
		$method = 'migrate_to_' . $version;
		$errors = SchemaWrites::query_error_count();

		self::$duplicates       = [];
		self::$missing_unique   = [];
		self::$refusal          = [];
		self::$refused          = false;
		self::$server_duplicate = false;
		self::$denied           = false;

		try {
			// Before dbDelta, so drops and renames finish first; their DDL waits for a metadata lock as dbDelta's does.
			if ( method_exists( $this->steps, $method ) ) {
				$wait = self::bound_lock_wait();

				try {
					$error = $this->steps->$method();
				} finally {
					$wait();
				}

				// A step that names a cause stops here: no dbDelta, no stamp.
				if ( is_string( $error ) && '' !== $error ) {
					return self::classify( $errors, $error );
				}
			}

			$error = $this->apply_and_verify( $probe );

			return '' === $error ? '' : self::classify( $errors, $error );
		} catch ( \Throwable $e ) {
			return self::describe( $e );
		}
	}

	/**
	 * dbDelta the whole schema, then ask the server for every table, column and index; '' when all are there as declared.
	 *
	 * @param bool $probe Name duplicate rows behind a missing UNIQUE index: explicit runs only, never a visitor's request.
	 * @return string
	 */
	private function apply_and_verify( bool $probe ): string {
		$report  = $this->apply( $probe );
		$blocked = [];

		// dbDelta keeps a same-name key of another shape: replace ours in place, then check again.
		if ( is_array( $report ) && [] !== self::$reshape ) {
			$blocked = self::replace_reshaped_keys( static fn(): bool => self::owns() );
			$report  = self::owns() ? self::inspect( $probe ) : false;
		}

		if ( false === $report ) {
			return __( 'Another request took over the database upgrade.', 'perflocale' );
		}

		if ( null === $report ) {
			return __( 'The database did not return the table structure.', 'perflocale' );
		}

		$missing = self::with_shapes( $report );

		// A key replacement that did not confirm its transaction fails the step even when the check finds the key.
		if ( [] === $missing ) {
			return implode( ' ', $blocked );
		}

		if ( [] !== $report['blocked'] ) {
			$blocked[]        = self::blocked_text( $report['blocked'] );
			self::$duplicates = array_values( array_merge( self::$duplicates, $report['blocked'] ) );
		}

		self::$missing_unique = self::unique_parts( $report['missing'] );

		/* translators: %s: comma-separated table, column or index names. */
		$text = sprintf( __( 'Missing or different in the database: %s.', 'perflocale' ), implode( ', ', array_slice( $missing, 0, 8 ) ) . ( count( $missing ) > 8 ? ', ...' : '' ) );

		// The named causes first: the stored failure text is cut at 300 characters.
		return [] === $blocked ? $text : implode( ' ', $blocked ) . ' ' . $text;
	}

	/**
	 * Why UNIQUE indexes stay missing: rows that share their values.
	 *
	 * @param string[] $names Index names such as `wp_perflocale_jobs index uuid`.
	 * @return string
	 */
	public static function blocked_text( array $names ): string {
		/* translators: %s: comma-separated index names, e.g. "wp_perflocale_jobs index uuid". */
		return sprintf( __( 'Rows with the same values block the UNIQUE index %s: remove the duplicate rows, then retry.', 'perflocale' ), implode( ', ', $names ) );
	}

	/**
	 * Decide from the server's DDL errors whether a failed step needs an operator: duplicates at once, an identified
	 * permanent refusal after REFUSALS equal ones in a row, anything else never. Returns the failure text, its cause first.
	 *
	 * @param int    $errors query_error_count() before the step.
	 * @param string $text   The failure text so far.
	 * @return string
	 */
	private static function classify( int $errors, string $text ): string {
		$codes = self::ddl_error_codes( $errors );

		self::$server_duplicate = in_array( 'duplicate_key', $codes, true );

		if ( [] === self::$duplicates && self::$server_duplicate && [] !== self::$missing_unique ) {
			self::$duplicates = self::$missing_unique;

			return self::blocked_text( self::$missing_unique ) . ' ' . $text;
		}

		// Every code identified: an unidentified one beside it may be the real cause.
		if ( [] !== self::$duplicates || [] === $codes || [] !== array_diff( $codes, self::PERMANENT ) ) {
			return $text;
		}

		ksort( $codes );
		$sig           = md5( (string) wp_json_encode( $codes ) );
		$last          = (array) ( self::entry( 'schema' )['refusal'] ?? [] );
		self::$refusal = [
			'sig' => $sig,
			'n'   => $sig === ( $last['sig'] ?? '' ) ? Scalar::int( $last['n'] ?? 0 ) + 1 : 1,
		];

		if ( self::$refusal['n'] < self::REFUSALS ) {
			return $text;
		}

		self::$refused = true;
		self::$denied  = in_array( 'denied', $codes, true );
		$cause         = self::refused_text( array_values( $codes ) );

		return str_contains( $text, $cause ) ? $text : $cause . ' ' . $text;
	}

	/**
	 * The server's errors for this plugin's DDL since $errors, as statement => DbErrorCode code (never its text).
	 *
	 * @param int $errors query_error_count() before the step.
	 * @return array<string, string>
	 */
	private static function ddl_error_codes( int $errors ): array {
		$out = [];

		foreach ( array_slice( (array) ( $GLOBALS['EZSQL_ERROR'] ?? [] ), $errors ) as $failed ) {
			$failed = (array) $failed;
			$query  = Scalar::str( $failed['query'] ?? '' );

			if ( 1 === preg_match( '/\b(?:ALTER|CREATE|DROP)\b.*perflocale_/is', $query ) ) {
				$out[ $query ] = \PerfLocale\Util\DbErrorCode::of( Scalar::str( $failed['error_str'] ?? '' ) );
			}
		}

		return $out;
	}

	/**
	 * What to fix for a refusal, by DbErrorCode code; never the server's text, which can name users.
	 *
	 * @param list<string> $codes Codes of the refused statements.
	 * @return string
	 */
	private static function refused_text( array $codes ): string {
		if ( in_array( 'denied', $codes, true ) ) {
			return __( 'The database user may not change the PerfLocale tables: give it the ALTER and INDEX privileges, then use Retry now.', 'perflocale' );
		}

		if ( in_array( 'key_too_long', $codes, true ) ) {
			return __( 'The database refused a key as too long for this server: move the table to InnoDB with ROW_FORMAT=DYNAMIC, then use Retry now.', 'perflocale' );
		}

		return __( 'The database refuses this change on every try: fix the cause in the database, then use Retry now.', 'perflocale' );
	}

	/**
	 * The fields fail() stores for this attempt: the identified `cause`, a stopped step's `manual`, its first check an hour on.
	 *
	 * @return array<string, mixed>
	 */
	private static function stop_fields(): array {
		$duplicates = [] !== self::$duplicates;
		$rows       = $duplicates ? self::blocking_rows( self::$duplicates ) : null;
		$cause      = $duplicates ? 'duplicates' : ( self::$denied ? 'denied' : ( self::$refused ? 'refused' : '' ) );

		// A failed read, or no rows and no word from the server: nothing proven, so a stop could not see a repair.
		if ( ( ! $duplicates && ! self::$refused ) || false === $rows || ( $duplicates && null === $rows && ! self::$server_duplicate ) ) {
			return [
				'refusal' => self::$refusal,
				'cause'   => $cause,
			];
		}

		return [
			'refusal' => self::$refusal,
			'cause'   => $cause,
			'next'    => time() + self::STOPPED_CHECK,
			'manual'  => [
				'kind' => $duplicates ? 'duplicates' : 'refused',
				'code' => Scalar::str( constant( 'PERFLOCALE_VERSION' ) ),
				'rows' => $rows,
			],
		];
	}

	/**
	 * Two rows behind the first named key: primary keys only, what the cheap check compares, no values stored.
	 * Null when every read answered and none is seen (past PROBE_ROWS): only Retry now, WP-CLI or new code then resumes
	 * the step. False when a read failed.
	 *
	 * @param list<string> $names `table index name` of the blocked keys.
	 * @return array{table: string, key: string, ids: list<int>}|null|false
	 */
	private static function blocking_rows( array $names ): array|null|false {
		$wpdb   = $GLOBALS['wpdb'] ?? null;
		$failed = false;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		foreach ( array_slice( $names, 0, self::PROBE_INDEXES ) as $name ) {
			$key = self::declared_key( $name );

			if ( null === $key ) {
				continue;
			}

			[ $short, $index, $columns ] = $key;
			[ $pick, $group, $where ]    = self::duplicate_sql( $columns, defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Declared \w+ identifiers and int lengths.
			$row = $wpdb->get_row( 'SELECT MIN(id) AS a, MAX(id) AS b FROM (SELECT `id`, ' . $pick . ' FROM ' . Schema::sanitize_table( Schema::table( $short ) ) . ' WHERE ' . $where . ' LIMIT ' . self::PROBE_ROWS . ') AS d GROUP BY ' . $group . ' HAVING COUNT(*) > 1 LIMIT 1', ARRAY_A );

			if ( '' !== self::db_error( $wpdb ) ) {
				$failed = true;
				continue;
			}

			if ( is_array( $row ) ) {
				return [
					'table' => $short,
					'key'   => $index,
					'ids'   => [ Scalar::int( $row['a'] ?? 0 ), Scalar::int( $row['b'] ?? 0 ) ],
				];
			}
		}

		return $failed ? false : null;
	}

	/**
	 * Whether a step stopped for its operator stays stopped; rechecks at most hourly, in place of the step.
	 *
	 * @param string $key Scope.
	 * @return bool
	 */
	private static function still_blocked( string $key ): bool {
		$entry  = self::entry( $key );
		$manual = $entry['manual'] ?? null;

		if ( ! is_array( $manual ) || Scalar::str( constant( 'PERFLOCALE_VERSION' ) ) !== ( $manual['code'] ?? '' ) || self::rows_changed( $manual ) ) {
			return false;
		}

		$entry['next'] = time() + self::STOPPED_CHECK;
		$state         = self::state();
		$state[ $key ] = $entry;
		update_option( self::STATE_OPTION, $state, false );

		return true;
	}

	/**
	 * Whether the stopped step's two recorded rows no longer share the key's values: a primary-key read of two rows, no
	 * scan. Rows that were not recorded prove nothing; a failed read counts as unchanged.
	 *
	 * @param array<array-key, mixed> $manual The stop's `manual`.
	 * @return bool
	 */
	private static function rows_changed( array $manual ): bool {
		$rows = $manual['rows'] ?? null;
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! is_array( $rows ) || ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$key = self::declared_key( Schema::sanitize_table( Schema::table( Scalar::str( $rows['table'] ?? '' ) ) ) . ' index ' . Scalar::str( $rows['key'] ?? '' ) );
		$ids = array_map( Scalar::int( ... ), array_values( (array) ( $rows['ids'] ?? [] ) ) );

		// A key this code does not declare, or a mangled row: one attempt writes a clean state.
		if ( null === $key || 2 !== count( $ids ) ) {
			return true;
		}

		[ $pick, $group, $where ] = self::duplicate_sql( $key[2], defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Declared \w+ identifiers, int lengths and ids.
		$hit = $wpdb->get_var( 'SELECT 1 FROM (SELECT ' . $pick . ' FROM ' . Schema::sanitize_table( Schema::table( $key[0] ) ) . ' WHERE `id` IN (' . $ids[0] . ', ' . $ids[1] . ') AND ' . $where . ') AS d GROUP BY ' . $group . ' HAVING COUNT(*) > 1 LIMIT 1' );

		return null === $hit && '' === self::db_error( $wpdb );
	}

	/**
	 * Short table name, key name and columns of a blocked key the schema declares UNIQUE, with a single `id` primary key.
	 *
	 * @param string $name `table index name`.
	 * @return array{0: string, 1: string, 2: list<array{0: string, 1: int|null}>}|null
	 */
	private static function declared_key( string $name ): ?array {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb || 1 !== preg_match( '/^(\S+) index (\w+)$/', $name, $m ) || ! str_starts_with( $m[1], $wpdb->prefix . 'perflocale_' ) ) {
			return null;
		}

		$short = substr( $m[1], strlen( $wpdb->prefix . 'perflocale_' ) );
		$sql   = SchemaWrites::definitions()[ $short ] ?? '';
		$keys  = self::declared_shape( $sql )[1];

		if ( ! ( $keys[ $m[2] ][0] ?? false ) || [ 'id' ] !== ( $keys['PRIMARY'][1] ?? [] ) ) {
			return null;
		}

		$columns = array_map( static fn( string $p ): array => 1 === preg_match( '/^(\w+)\((\d+)\)$/', $p, $pm ) ? [ $pm[1], (int) $pm[2] ] : [ $p, null ], $keys[ $m[2] ][1] );

		return [ $short, $m[2], $columns ];
	}

	/**
	 * The `table index name` parts of a missing list that the schema declares UNIQUE.
	 *
	 * @param string[] $missing Missing parts.
	 * @return list<string>
	 */
	private static function unique_parts( array $missing ): array {
		return array_values( array_filter( $missing, static fn( string $name ): bool => null !== self::declared_key( $name ) ) );
	}

	/**
	 * Apply the whole schema with dbDelta while this runner owns the lock, then inspect(); false when the lock was lost.
	 *
	 * @param bool $probe Passed to inspect().
	 * @return array{missing: string[], differ: array<string, array{0: string, 1: string}>, blocked: string[]}|null|false
	 */
	private function apply( bool $probe ): array|null|false {
		$wait = self::bound_lock_wait();

		try {
			// Each table only while this runner still owns the lock: after the TTL another runner may own it.
			SchemaWrites::create_tables( static fn(): bool => self::owns() );
		} finally {
			$wait();
		}

		return self::owns() ? self::inspect( $probe ) : false;
	}

	/**
	 * Replace each same-name key of another shape the last missing_parts() found, on PerfLocale's own tables only: in one
	 * statement on MySQL/MariaDB, in one transaction on SQLite, so a refused key leaves the old one in place. A UNIQUE key
	 * over duplicate rows is not touched.
	 *
	 * @internal Called by the runner's schema step; public for the engine tests.
	 * @param (callable(): bool)|null $go_on Asked before each key and before a UNIQUE key's DDL; false stops (the runner lost its lock).
	 * @return string[] Why a key was left as it is (duplicate rows), for the failure text.
	 */
	public static function replace_reshaped_keys( ?callable $go_on = null ): array {
		$wpdb    = $GLOBALS['wpdb'] ?? null;
		$sqlite  = defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' );
		$blocked = [];
		$queue   = self::$reshape;

		self::$reshape = [];

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		// Each ALTER waits for a metadata lock at most DDL_LOCK_WAIT: go_on() renews the lock before it, so none outlives it.
		$wait = self::bound_lock_wait();

		// Table names come from SchemaWrites::definitions() (sanitized), key and column names from its text (\w+).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		try {
			foreach ( $queue as $table => $keys ) {
				foreach ( $keys as $key => [ $unique, $parts ] ) {
					if ( null !== $go_on && ! $go_on() ) {
						break 2;
					}

					$cols = implode( ', ', $parts );
					$add  = 'ADD ' . ( $unique ? 'UNIQUE ' : '' ) . "KEY {$key} ({$cols})";
					$why  = '';

					if ( $unique ) {
						$want = array_map( static fn( string $p ): array => 1 === preg_match( '/^(\w+)\((\d+)\)$/', $p, $pm ) ? [ $pm[1], (int) $pm[2] ] : [ $p, null ], $parts );

						// A bounded read names duplicates it sees; the ALTER is the check (it refuses over any, keeping the old key).
						if ( self::has_duplicates( $wpdb, $table, $want, array_fill_keys( array_map( static fn( array $w ): string => strtolower( $w[0] ), $want ), true ), $sqlite ) ) {
							/* translators: 1: key name, 2: table name. */
							$blocked[]          = sprintf( __( 'Duplicate rows block the unique key %1$s on %2$s: remove the duplicates, then use Retry now.', 'perflocale' ), $key, $table );
							self::$duplicates[] = $table . ' index ' . $key;
							continue;
						}

						// The read can outlast the lock: owned again right before the DDL.
						if ( null !== $go_on && ! $go_on() ) {
							break 2;
						}
					}

					if ( ! $sqlite ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from SchemaWrites::definitions() (sanitized); key and column names are \w+ from its text.
						$ok  = false !== $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$key}, {$add}" );
						$why = $wpdb->last_error;
					} else {
						// SQLite DDL is transactional: a refused CREATE INDEX rolls the DROP back. No DDL outside it, and a COMMIT
						// that failed is no replacement (rolled back; the check after it decides).
						if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
							/* translators: 1: key name, 2: table name. */
							$blocked[] = sprintf( __( 'The database did not start the replacement of the key %1$s on %2$s; nothing was changed: use Retry now.', 'perflocale' ), $key, $table );
							continue;
						}

						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table from SchemaWrites::definitions() (sanitized); key and column names are \w+ from its text.
						$ok        = false !== $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$key}" ) && false !== $wpdb->query( "ALTER TABLE {$table} {$add}" );
						$why       = $wpdb->last_error;
						$committed = $ok && false !== $wpdb->query( 'COMMIT' );

						if ( ! $committed ) {
							$wpdb->query( 'ROLLBACK' );
						}

						if ( $ok && ! $committed ) {
							/* translators: 1: key name, 2: table name. */
							$blocked[] = sprintf( __( 'The database did not commit the replacement of the key %1$s on %2$s: use Retry now.', 'perflocale' ), $key, $table );
							continue;
						}
					}

					if ( ! $ok && $unique ) {
						$code = \PerfLocale\Util\DbErrorCode::of( $why );

						if ( in_array( $code, self::TEMPORARY, true ) ) {
							/* translators: 1: key name, 2: table name. */
							$blocked[] = sprintf( __( 'The database could not replace the unique key %1$s on %2$s this time (a lock or a lost connection); the old key was kept and the next automatic try repeats it.', 'perflocale' ), $key, $table );
						} elseif ( in_array( $code, [ 'denied', 'key_too_long' ], true ) ) {
							/* translators: 1: key name, 2: table name. */
							$blocked[] = sprintf( __( 'The database refused the unique key %1$s on %2$s; the old key was kept.', 'perflocale' ), $key, $table ) . ' ' . self::refused_text( [ $code ] );
						} else {
							if ( 'duplicate_key' === $code ) {
								self::$duplicates[] = $table . ' index ' . $key;
							}

							/* translators: 1: key name, 2: table name. */
							$blocked[] = sprintf( __( 'The database refused the unique key %1$s on %2$s, usually over duplicate rows; the old key was kept: remove the duplicates, then use Retry now.', 'perflocale' ), $key, $table );
						}
					}
				}
			}
		} finally {
			$wait();
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $blocked;
	}

	/**
	 * Cap this connection's metadata-lock wait (MySQL/MariaDB) well below LOCK_TTL while dbDelta or the key repair runs; a statement that
	 * would wait longer fails, is reported by missing_parts() and retried with backoff. Returns the restore callback.
	 *
	 * @return callable(): void
	 */
	private static function bound_lock_wait(): callable {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb || ( defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) ) ) {
			return static function (): void {};
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A session variable, on the upgrade path only.
		$old = $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );

		if ( ! is_numeric( $old ) || (int) $old <= self::DDL_LOCK_WAIT ) {
			return static function (): void {};
		}

		$set = static function ( int $seconds ) use ( $wpdb ): void {
			$sql = $wpdb->prepare( 'SET SESSION lock_wait_timeout = %d', $seconds );

			if ( is_string( $sql ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; a session variable.
				$wpdb->query( $sql );
			}
		};

		$set( self::DDL_LOCK_WAIT );

		return static function () use ( $set, $old ): void {
			$set( (int) $old );
		};
	}

	/**
	 * Run the upgrade tasks of a new plugin version; the version is recorded only when every task succeeded.
	 *
	 * @return string 'done', 'current', 'waiting', 'busy' or 'failed'.
	 */
	public function update(): string {
		if ( ! self::due( 'update' ) ) {
			return 'waiting';
		}

		if ( ! Lock::acquire( self::LOCK, self::LOCK_TTL ) ) {
			return 'busy';
		}

		try {
			self::refresh();

			$read = self::stored( 'perflocale_version' );

			// Not "no row": '0' would pass the newer-version check below and run the tasks.
			if ( false === $read ) {
				return self::unreadable_version( 'update', false );
			}

			$stored = $read ?? '0';

			if ( version_compare( $stored, PERFLOCALE_VERSION, '>=' ) ) {
				self::clear( 'update' );
				return 'current';
			}

			// Rechecked under the lock, as in schema().
			if ( ! self::due( 'update' ) ) {
				return 'waiting';
			}

			// Tasks finished on an earlier try of this version are not run again.
			$entry  = self::entry( 'update' );
			$done   = PERFLOCALE_VERSION === ( $entry['target'] ?? '' ) ? array_values( (array) ( $entry['done'] ?? [] ) ) : [];
			$failed = '';
			$cause  = '';
			/* translators: %s: plugin version. */
			$step = sprintf( __( 'Upgrade tasks for version %s', 'perflocale' ), PERFLOCALE_VERSION );

			if ( ! self::claim( 'update', $step ) ) {
				return 'busy';
			}

			foreach ( self::tasks( $stored ) as $task => $run ) {
				if ( in_array( $task, $done, true ) ) {
					continue;
				}

				if ( ! self::owns() ) {
					return 'busy';
				}

				try {
					$run();
					$done[] = $task;
				} catch ( \Throwable $e ) {
					if ( '' === $failed ) {
						$failed = $task;
						$cause  = self::describe( $e );
					}
				}
			}

			if ( ! self::owns() ) {
				return 'busy';
			}

			if ( '' === $failed ) {
				update_option( 'perflocale_version', PERFLOCALE_VERSION, true );
				$error = self::saved( 'perflocale_version', PERFLOCALE_VERSION );
			} else {
				/* translators: 1: task name, 2: cause. */
				$error = sprintf( __( 'Task "%1$s": %2$s', 'perflocale' ), $failed, $cause );
			}

			if ( '' !== $error ) {
				self::fail(
					'update',
					$step,
					$error,
					[
						'done'   => $done,
						'target' => PERFLOCALE_VERSION,
					]
				);
				return 'failed';
			}

			self::clear( 'update' );
		} finally {
			Lock::release( self::LOCK );
		}

		return 'done';
	}

	/**
	 * The upgrade tasks by name, in order; each may throw.
	 *
	 * @param string $stored The stored (old) plugin version.
	 * @return array<string, callable(): void>
	 */
	private static function tasks( string $stored ): array {
		$plugin = \PerfLocale\Plugin::get_instance();

		return [
			// New code may produce different output.
			'cache'          => static function () use ( $plugin ): void {
				if ( $plugin->has( 'cache' ) ) {
					$plugin->get( 'cache' )->flush_all();
				}
			},
			// Orphan translation groups. It deletes rows by column state, so behind the schema it is left to schema(), which
			// visitors never start; the version read past this request's cache, under the lock schema() takes too.
			'sweep'          => static function (): void {
				$db = self::stored( 'perflocale_db_version' );

				if ( ! is_string( $db ) ) {
					throw new \RuntimeException( __( 'The database schema version could not be read.', 'perflocale' ), self::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
				}

				if ( (int) $db >= PERFLOCALE_DB_VERSION ) {
					self::sweep();
				}
			},
			// This site's string identity switch-over (cold class): `done` on an empty strings table, else the runner while not `done`.
			'ident'          => static function (): void {
				\PerfLocale\Strings\StrIdentMigration::upgrade_task();
			},
			// The GC protection of the option-text and label domains (Strings\Domains); a failed write is retried.
			'domains'        => static function (): void {
				if ( ! \PerfLocale\Strings\Domains::ensure_site_options() ) {
					throw new \RuntimeException( __( 'The protected string domains could not be saved.', 'perflocale' ), self::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
				}
			},
			// The row the scope edits' import fence locks, on sites that never imported.
			'imports'        => static function (): void {
				if ( ! \PerfLocale\Admin\ImportLock::ensure_generation() ) {
					throw new \RuntimeException( __( 'The import count could not be saved.', 'perflocale' ), self::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
				}
			},
			// This version's uninstall names into the owned-names manifest (cold: SiteCleanup).
			'owned'          => static function (): void {
				if ( ! SiteCleanup::record_owned_names() ) {
					throw new \RuntimeException( __( 'The list of PerfLocale data to delete on uninstall could not be saved.', 'perflocale' ), self::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
				}
			},
			'rewrite'        => static function (): void {
				update_option( 'perflocale_flush_rules', 1, true );
			},
			// Sites from before the rows were kept: each read of a missing row was a query per front-end request.
			'options'        => static function (): void {
				$left = \PerfLocale\Activator::seed_request_options();

				if ( [] !== $left ) {
					/* translators: %s: option names. */
					throw new \RuntimeException( sprintf( __( 'These options could not be written: %s.', 'perflocale' ), implode( ', ', $left ) ), self::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
				}
			},
			// The default is browser first since 1.0.8: a row that never stored an order keeps the old one, geo first.
			'redirect_order' => static function () use ( $stored ): void {
				if ( version_compare( $stored, '1.0.8', '<' ) ) {
					self::pin_redirect_order();
				}
			},
			// A guard file holding only "Deny from all" answers 500 on Apache 2.4 without mod_access_compat.
			'guards'         => static function (): void {
				\PerfLocale\Util\Uploads::harden_existing();
			},
			'hook'           => static function () use ( $stored ): void {
				/**
				 * Fires after the plugin code version is updated, for version-specific non-DB tasks.
				 * Fires again on a retry when a listener threw, so listeners must be idempotent.
				 *
				 * @param string $old_version Previous plugin version.
				 * @param string $new_version Current plugin version.
				 */
				do_action( 'perflocale/updated', $stored, PERFLOCALE_VERSION );
			},
		];
	}

	/**
	 * The sweep the upgrade tasks left when they ran on the old schema: '' when done or not needed, the cause, or null
	 * when the lock was lost meanwhile. An unreadable plugin version sweeps too: the schema was just verified.
	 *
	 * @return string|null
	 */
	private static function sweep_left_by_tasks(): ?string {
		$version = self::stored( 'perflocale_version' );

		if ( is_string( $version ) && version_compare( $version, PERFLOCALE_VERSION, '<' ) ) {
			return '';
		}

		try {
			self::sweep();
		} catch ( \Throwable $e ) {
			/* translators: %s: cause. */
			return sprintf( __( 'Orphan translation groups: %s', 'perflocale' ), self::describe( $e ) );
		}

		return self::owns() ? '' : null;
	}

	/**
	 * Delete orphan translation groups (a string deleted without its group, a merge import that stranded some).
	 *
	 * @return void
	 */
	private static function sweep(): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'cache' ) ) {
			( new Repository\TranslationGroupRepository( $plugin->get( 'cache' ) ) )->sweep_orphan_groups();
		}
	}

	/**
	 * Store the pre-1.0.8 default redirect order in a settings row that has none, under the settings lock.
	 *
	 * @return void
	 */
	private static function pin_redirect_order(): void {
		$done = Lock::with(
			'settings_update',
			10,
			static function (): bool {
				wp_cache_delete( 'perflocale_settings', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$row = get_option( 'perflocale_settings' );

				if ( ! is_array( $row ) || array_key_exists( 'redirect_priority_order', $row ) ) {
					return true;
				}

				$row['redirect_priority_order'] = [ 'geo', 'browser', 'edge_hint' ];

				return update_option( 'perflocale_settings', $row );
			}
		);

		if ( true !== $done ) {
			throw new \RuntimeException( __( 'The settings could not be saved; another save may be running.', 'perflocale' ), self::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
		}
	}

	/**
	 * Run one add-on's pending schema steps under the lock, with backoff.
	 *
	 * @param \PerfLocale\Addon\HasSchema&\PerfLocale\Addon\AddonInterface $addon    The add-on.
	 * @param string                                                     $addon_id Its id.
	 * @param bool                                                       $force    Ignore the backoff (WP-CLI, Retry now).
	 * @return bool True when the add-on is at its target version.
	 */
	public function addon( \PerfLocale\Addon\HasSchema&\PerfLocale\Addon\AddonInterface $addon, string $addon_id, bool $force = false ): bool {
		$key = 'addon:' . $addon_id;

		if ( ( ! $force && ! self::due( $key ) ) || ! Lock::acquire( self::LOCK, self::LOCK_TTL ) ) {
			return false;
		}

		try {
			self::refresh( \PerfLocale\Addon\AddonSchemaManager::VERSIONS_OPTION );

			// Rechecked under the lock, as in schema().
			if ( ! $force && ! self::due( $key ) ) {
				return false;
			}

			/* translators: 1: add-on id, 2: schema version. */
			$step = sprintf( __( 'Add-on "%1$s" schema version %2$d', 'perflocale' ), $addon_id, (int) $addon->get_schema_version() );

			if ( ! self::claim( $key, $step ) ) {
				return false;
			}

			$reached = \PerfLocale\Addon\AddonSchemaManager::run_pending( $addon );

			if ( ! self::owns() ) {
				return false;
			}

			if ( $reached ) {
				self::clear( $key );
				return true;
			}

			$record = \PerfLocale\Addon\AddonMigrationErrors::last_for_addon( $addon_id );

			self::fail( $key, $step, is_array( $record ) ? (string) ( $record['message'] ?? '' ) : '' );

			return false;
		} finally {
			Lock::release( self::LOCK );
		}
	}

	/**
	 * Retry now: every scope at once, ignoring the backoff, and a re-check of the tables.
	 *
	 * @return string 'busy' when another runner holds the lock, 'failed' when a failure remains, else 'done'.
	 */
	public static function retry(): string {
		self::$force        = true;
		self::$repair_error = '';
		$results            = [];

		try {
			$runner    = new self();
			$results[] = $runner->schema( true );

			if ( version_compare( (string) get_option( 'perflocale_version', '0' ), PERFLOCALE_VERSION, '<' ) ) {
				$results[] = $runner->update();
			}

			( new Migrator() )->maybe_migrate_addons();
		} finally {
			self::$force = false;
		}

		if ( is_multisite() ) {
			// Caught: the upgrade above has already run, and a scheduler refusal must not lose its result.
			try {
				NetworkUpgrade::on_retry();
			} catch ( \Throwable $e ) {
				self::log( __( 'Network upgrade pass', 'perflocale' ), self::describe( $e ) );
			}
		}

		if ( in_array( 'busy', $results, true ) ) {
			return 'busy';
		}

		return in_array( 'failed', $results, true ) || [] !== self::failures() ? 'failed' : 'done';
	}

	/**
	 * Cause of the last failed tables check of a current schema in this request ('' when none).
	 *
	 * @return string
	 */
	public static function repair_error(): string {
		return self::$repair_error;
	}

	/**
	 * This blog's failures that still apply: a step that failed, or one that stopped without finishing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function failures(): array {
		$out = [];

		foreach ( self::state() as $key => $entry ) {
			$key = (string) $key;

			if ( ! is_array( $entry ) || ! self::applies( $key, $entry ) ) {
				continue;
			}

			$running = (int) ( $entry['running'] ?? 0 );

			if ( '' !== (string) ( $entry['error'] ?? '' ) ) {
				$out[ $key ] = $entry;
			} elseif ( $running > 0 && $running + self::LOCK_TTL < time() ) {
				$entry['error'] = __( 'The last try stopped before it finished, for example at a time or memory limit.', 'perflocale' );
				$out[ $key ]    = $entry;
			}
		}

		return $out;
	}

	/**
	 * Whether an entry is still pending: its version not yet reached, its add-on still loaded and enabled.
	 *
	 * @param string               $key   Scope.
	 * @param array<string, mixed> $entry Its state.
	 * @return bool
	 */
	private static function applies( string $key, array $entry ): bool {
		if ( 'schema' === $key ) {
			return (int) get_option( 'perflocale_db_version', 0 ) < PERFLOCALE_DB_VERSION;
		}

		if ( 'update' === $key ) {
			return version_compare( (string) get_option( 'perflocale_version', '0' ), PERFLOCALE_VERSION, '<' );
		}

		$addon_id = substr( $key, 6 );
		$plugin   = \PerfLocale\Plugin::get_instance();
		$registry = $plugin->has( 'addon_registry' ) ? $plugin->get( 'addon_registry' ) : null;
		$addon    = null !== $registry && method_exists( $registry, 'get_loaded_addons' ) ? ( $registry->get_loaded_addons()[ $addon_id ] ?? null ) : null;

		if ( ! $addon instanceof \PerfLocale\Addon\HasSchema || in_array( $addon_id, \PerfLocale\Addon\AddonRegistry::get_disabled(), true ) ) {
			return false;
		}

		// Reached its version another way (WP-CLI reset-version, a lower schema version in a new release).
		try {
			return \PerfLocale\Addon\AddonSchemaManager::get_stored_version( $addon_id ) < (int) $addon->get_schema_version();
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Renew the lock before a write; false when another runner took it over after the TTL (it owns the state now).
	 *
	 * @return bool
	 */
	public static function owns(): bool {
		return Lock::extend( self::LOCK, self::LOCK_TTL );
	}

	/**
	 * Whether a version write reached the row: '' when the row holds the value, else the cause.
	 *
	 * Not update_option()'s result: it is false for a row that already holds the value too.
	 *
	 * @param string     $name  Option name.
	 * @param int|string $value Version.
	 * @return string
	 */
	private static function saved( string $name, int|string $value ): string {
		if ( self::stored( $name ) === (string) $value ) {
			return '';
		}

		/* translators: %s: version number. */
		return sprintf( __( 'The database did not save version %s.', 'perflocale' ), (string) $value );
	}

	/**
	 * Whether an add-on's version write reached the row on the primary: '' when it did, else the cause.
	 * Not update_option()'s result: it is false for an unchanged row too.
	 *
	 * @internal AddonSchemaManager::run_pending() after each step; here so the check loads with the upgrade only.
	 * @param string $addon_id Add-on id.
	 * @param int    $version  Version just written.
	 * @return string
	 */
	public static function addon_saved( string $addon_id, int $version ): string {
		$raw      = self::stored( \PerfLocale\Addon\AddonSchemaManager::VERSIONS_OPTION );
		$versions = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		$stored   = is_array( $versions ) ? ( $versions[ $addon_id ] ?? null ) : null;

		if ( is_numeric( $stored ) && (int) $stored === $version ) {
			return '';
		}

		return sprintf(
			/* translators: 1: schema version, 2: option name. */
			__( 'The database did not keep version %1$d in the %2$s option: the write was refused, or a filter on that option kept the old value.', 'perflocale' ),
			$version,
			\PerfLocale\Addon\AddonSchemaManager::VERSIONS_OPTION
		);
	}

	/**
	 * Seconds to wait after a given number of failed attempts.
	 *
	 * @param int $attempts Failed attempts so far (>= 1).
	 * @return int
	 */
	public static function delay( int $attempts ): int {
		return self::BACKOFF[ max( 1, min( $attempts, count( self::BACKOFF ) ) ) - 1 ];
	}

	/**
	 * Tables, columns and indexes of SchemaWrites::definitions() the server does not have as declared; null when unreadable.
	 * A column counts with its type and NOT NULL, a key with its uniqueness, column order and (not on SQLite) prefixes:
	 * dbDelta reports intent, and keeps a same-name key or a refused type change without an error.
	 *
	 * @return string[]|null Such as `wp_perflocale_jobs`, `wp_perflocale_jobs.log longtext`, `wp_perflocale_jobs index uuid`.
	 */
	public static function missing_parts(): ?array {
		$report = self::inspect( false );

		return null === $report ? null : self::with_shapes( $report );
	}

	/**
	 * missing_parts() in parts: missing (keys of another shape left out), differ (index => [found, expected] definitions)
	 * and blocked (missing UNIQUE indexes that duplicate rows block). Null when unreadable.
	 *
	 * @return array{missing: string[], differ: array<string, array{0: string, 1: string}>, blocked: string[]}|null
	 */
	public static function schema_report(): ?array {
		return self::inspect( true );
	}

	/**
	 * A report's missing parts, then each key of another shape with its declared definition.
	 *
	 * @param array{missing: string[], differ: array<string, array{0: string, 1: string}>, blocked: string[]} $report inspect().
	 * @return string[]
	 */
	private static function with_shapes( array $report ): array {
		$out = $report['missing'];

		foreach ( $report['differ'] as $name => [ , $expected ] ) {
			$out[] = $name . ' ' . $expected;
		}

		return $out;
	}

	/**
	 * Ask the server with dbDelta's own unquoted DESCRIBE and SHOW INDEX FROM, which both SQLite drivers implement; a key
	 * of another shape (never PRIMARY) is queued for replace_reshaped_keys().
	 *
	 * @param bool $probe Also look for duplicate rows behind a missing UNIQUE index (bounded: PROBE_INDEXES, PROBE_ROWS).
	 * @return array{missing: string[], differ: array<string, array{0: string, 1: string}>, blocked: string[]}|null
	 */
	private static function inspect( bool $probe ): ?array {
		self::$reshape = [];
		$wpdb          = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$prefix = $wpdb->prefix . 'perflocale_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema post-condition; must ask the server.
		$found = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );

		if ( '' !== self::db_error( $wpdb ) ) {
			return null;
		}

		$have = [];

		foreach ( $found as $name ) {
			$have[ strtolower( (string) $name ) ] = true;
		}

		$missing = [];
		$differ  = [];
		$blocked = [];
		$probes  = 0;

		foreach ( SchemaWrites::definitions() as $short => $sql ) {
			$table = Schema::sanitize_table( Schema::table( $short ) );

			if ( ! isset( $have[ strtolower( $table ) ] ) ) {
				$missing[] = $table;
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sanitized identifier, the statements dbDelta sends.
			$fields = (array) $wpdb->get_results( "DESCRIBE {$table};" );
			$error  = self::db_error( $wpdb );
			$rows   = (array) $wpdb->get_results( "SHOW INDEX FROM {$table};" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( '' !== $error . self::db_error( $wpdb ) || [] === $fields || [] === $rows ) {
				return null;
			}

			[ $m, $d, $b ] = self::table_differences( $wpdb, $table, self::declared_shape( $sql ), $fields, $rows, $probe, $probes );
			$missing       = array_merge( $missing, $m );
			$differ        = array_merge( $differ, $d );
			$blocked       = array_merge( $blocked, $b );
		}

		return [
			'missing' => $missing,
			'differ'  => $differ,
			'blocked' => $blocked,
		];
	}

	/**
	 * What one table lacks of its declared shape, given its DESCRIBE and SHOW INDEX rows.
	 *
	 * @param \wpdb                                                                                                          $wpdb   Database.
	 * @param string                                                                                                         $table  Table name.
	 * @param array{0: array<string, array{0: string, 1: bool, 2: bool}>, 1: array<string, array{0: bool, 1: list<string>}>} $shape  declared_shape().
	 * @param array<array-key, mixed>                                                                                        $fields DESCRIBE rows.
	 * @param array<array-key, mixed>                                                                                        $rows   SHOW INDEX rows.
	 * @param bool                                                                                                           $probe  Read for duplicate rows behind a missing UNIQUE index.
	 * @param int                                                                                                            $probes Duplicate reads so far (PROBE_INDEXES in all).
	 * @return array{0: string[], 1: array<string, array{0: string, 1: string}>, 2: string[]} Missing, differ, blocked.
	 */
	private static function table_differences( \wpdb $wpdb, string $table, array $shape, array $fields, array $rows, bool $probe, int &$probes ): array {
		$have = [];

		foreach ( $fields as $field ) {
			$field = (array) $field;
			$have[ strtolower( Scalar::str( $field['Field'] ?? '' ) ) ] = [ self::type_key( Scalar::str( $field['Type'] ?? '' ) ), 'NO' === strtoupper( Scalar::str( $field['Null'] ?? '' ) ) ];
		}

		$missing = [];

		foreach ( $shape[0] as $column => [ $type, $not_null, $nullable ] ) {
			$found = $have[ strtolower( $column ) ] ?? null;

			// Both ways for a column declared NULL: its writers store NULL (dbDelta never changes nullability).
			if ( null === $found ) {
				$missing[] = $table . '.' . $column;
			} elseif ( ! self::type_fits( $found[0], $type ) || ( $not_null && ! $found[1] ) || ( $nullable && $found[1] ) ) {
				$missing[] = $table . '.' . $column . ' ' . $type . ( $not_null ? ' NOT NULL' : ( $nullable ? ' NULL' : '' ) );
			}
		}

		// SQLite has no prefix indexes, and the legacy driver lists PRIMARY columns in table order.
		$sqlite  = defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' );
		$keys    = self::found_keys( $rows );
		$differ  = [];
		$blocked = [];

		foreach ( $shape[1] as $index => [ $unique, $parts ] ) {
			$name = $table . ' index ' . $index;
			$want = array_map( static fn( string $p ): array => 1 === preg_match( '/^(\w+)\((\d+)\)$/', $p, $pm ) ? [ $pm[1], (int) $pm[2] ] : [ $p, null ], $parts );
			$key  = self::find_key( $keys, strtolower( $index ) );

			if ( null === $key ) {
				$missing[] = $name;

				if ( $probe && $unique && $probes < self::PROBE_INDEXES ) {
					++$probes;

					if ( self::has_duplicates( $wpdb, $table, $want, $have, $sqlite ) ) {
						$blocked[] = $name;
					}
				}

				continue;
			}

			$found = $keys[ $key ];

			if ( $sqlite && 'PRIMARY' === $index && ! $found['ordered'] ) {
				$found = self::legacy_primary( $found, $keys[ strtolower( $table ) ] ?? null );
			}

			// An unstated prefix counts on a UNIQUE index only: older servers shorten a long non-unique key on their own.
			$cols     = array_map(
				static fn( array $c, int $i ): array => [ $c[0], null === ( $want[ $i ][1] ?? null ) && ! $unique ? null : $c[1] ],
				$found['columns'],
				array_keys( $found['columns'] )
			);
			$got      = self::index_text( $found['unique'], $cols, $sqlite );
			$expected = self::index_text( $unique, $want, $sqlite );

			if ( strtolower( $got ) !== strtolower( $expected ) ) {
				$differ[ $name ] = [ $got, $expected ];

				if ( 'PRIMARY' !== $index ) {
					self::$reshape[ $table ][ $index ] = [ $unique, $sqlite ? array_map( static fn( string $p ): string => (string) preg_replace( '/\(\d+\)$/', '', $p ), $parts ) : $parts ];
				}
			}
		}

		return [ $missing, $differ, $blocked ];
	}

	/**
	 * SHOW INDEX rows by lower-cased Key_name: uniqueness, [column, prefix length] in Seq_in_index order, and whether
	 * every row had a Seq_in_index (ordered).
	 *
	 * @param array<array-key, mixed> $rows SHOW INDEX FROM rows.
	 * @return array<string, array{unique: bool, ordered: bool, columns: list<array{0: string, 1: int|null}>}>
	 */
	private static function found_keys( array $rows ): array {
		$parts = [];

		foreach ( $rows as $i => $row ) {
			$row = (array) $row;
			$seq = $row['Seq_in_index'] ?? 0;
			$sub = $row['Sub_part'] ?? null;
			$col = $row['Column_name'] ?? '';
			$key = $row['Key_name'] ?? '';
			$key = strtolower( is_scalar( $key ) ? (string) $key : '' );

			$parts[ $key ][] = [
				is_numeric( $seq ) ? (int) $seq : 0,
				(int) $i,
				strtolower( is_scalar( $col ) ? (string) $col : '' ),
				is_numeric( $sub ) ? (int) $sub : null,
				in_array( $row['Non_unique'] ?? '1', [ 0, '0' ], true ),
			];
		}

		$keys = [];

		foreach ( $parts as $key => $list ) {
			// The legacy SQLite driver reports Seq_in_index 0 for every column, in index order.
			usort( $list, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );

			$keys[ (string) $key ] = [
				'unique'  => $list[0][4],
				'ordered' => $list[0][0] > 0,
				'columns' => array_map( static fn( array $p ): array => [ $p[2], $p[3] ], $list ),
			];
		}

		return $keys;
	}

	/**
	 * The legacy SQLite driver's PRIMARY key in key order: it lists the key's own index, in key order, under the table name.
	 *
	 * @param array{unique: bool, ordered: bool, columns: list<array{0: string, 1: int|null}>}      $primary PRIMARY rows, in table order.
	 * @param array{unique: bool, ordered: bool, columns: list<array{0: string, 1: int|null}>}|null $own     Key named as the table.
	 * @return array{unique: bool, ordered: bool, columns: list<array{0: string, 1: int|null}>}
	 */
	private static function legacy_primary( array $primary, ?array $own ): array {
		if ( null === $own ) {
			return $primary;
		}

		$a = array_column( $primary['columns'], 0 );
		$b = array_column( $own['columns'], 0 );
		sort( $a );
		sort( $b );

		return $a === $b ? $own : $primary;
	}

	/**
	 * An index definition as text, e.g. `UNIQUE (language_id, slug(191))`; prefix lengths left out on SQLite.
	 *
	 * @param bool                                $unique  Unique index.
	 * @param list<array{0: string, 1: int|null}> $columns Column names and prefix lengths.
	 * @param bool                                $sqlite  SQLite.
	 * @return string
	 */
	private static function index_text( bool $unique, array $columns, bool $sqlite ): string {
		$list = array_map( static fn( array $c ): string => $c[0] . ( null === $c[1] || $sqlite ? '' : '(' . $c[1] . ')' ), $columns );

		return ( $unique ? 'UNIQUE ' : '' ) . '(' . implode( ', ', $list ) . ')';
	}

	/**
	 * Whether rows share the values of a UNIQUE index that is not there yet, in its first PROBE_ROWS rows.
	 *
	 * @param \wpdb                               $wpdb    Database.
	 * @param string                              $table   Sanitized table name.
	 * @param list<array{0: string, 1: int|null}> $columns Declared columns and prefix lengths.
	 * @param array<string, mixed>                $fields  Lower-cased columns the table has.
	 * @param bool                                $sqlite  SQLite: whole columns, as it has no prefix indexes.
	 * @return bool
	 */
	private static function has_duplicates( \wpdb $wpdb, string $table, array $columns, array $fields, bool $sqlite ): bool {
		foreach ( $columns as [ $column ] ) {
			if ( ! isset( $fields[ strtolower( $column ) ] ) ) {
				return false;
			}
		}

		[ $pick, $group, $where ] = self::duplicate_sql( $columns, $sqlite );

		// The derived table's LIMIT bounds the rows read; duplicates further on stay unnamed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Declared \w+ identifiers and int lengths.
		$hit = $wpdb->get_var( 'SELECT 1 FROM (SELECT ' . $pick . ' FROM ' . $table . ' WHERE ' . $where . ' LIMIT ' . self::PROBE_ROWS . ') AS d GROUP BY ' . $group . ' HAVING COUNT(*) > 1 LIMIT 1' );

		return null !== $hit && '' === self::db_error( $wpdb );
	}

	/**
	 * The select list, group list and not-null filter of a UNIQUE key's duplicate read.
	 *
	 * @param list<array{0: string, 1: int|null}> $columns Declared columns and prefix lengths.
	 * @param bool                                $sqlite  SQLite: whole columns, as it has no prefix indexes.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function duplicate_sql( array $columns, bool $sqlite ): array {
		$pick  = [];
		$group = [];
		$where = [];

		foreach ( $columns as $i => [ $column, $length ] ) {
			$name    = '`' . $column . '`';
			$pick[]  = ( null === $length || $sqlite ? $name : 'SUBSTR(' . $name . ', 1, ' . $length . ')' ) . ' AS c' . $i;
			$group[] = 'c' . $i;
			$where[] = $name . ' IS NOT NULL';
		}

		return [ implode( ', ', $pick ), implode( ', ', $group ), implode( ' AND ', $where ) ];
	}

	/**
	 * The found key of a declared index: exact, or with the `<table>__` the SQLite drivers may leave in front.
	 *
	 * @param array<string, mixed> $keys  found_keys() result.
	 * @param string               $index Lower-cased declared name.
	 * @return string|null
	 */
	private static function find_key( array $keys, string $index ): ?string {
		if ( isset( $keys[ $index ] ) ) {
			return $index;
		}

		foreach ( array_keys( $keys ) as $key ) {
			if ( str_ends_with( (string) $key, '__' . $index ) ) {
				return (string) $key;
			}
		}

		return null;
	}

	/**
	 * A column type as compared: lower case, single spaces, no integer display width (MySQL 8.0.17+ drops it).
	 *
	 * @param string $type Declared or DESCRIBE type.
	 * @return string
	 */
	private static function type_key( string $type ): string {
		$type = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $type ) ) );

		return (string) preg_replace( '/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type );
	}

	/**
	 * Whether a column's type is the declared one; a wider text or blob type fits, as dbDelta never narrows those.
	 *
	 * @param string $have     type_key() of the server's type.
	 * @param string $declared type_key() of the declared type.
	 * @return bool
	 */
	private static function type_fits( string $have, string $declared ): bool {
		if ( $have === $declared ) {
			return true;
		}

		foreach ( [ [ 'tinytext', 'text', 'mediumtext', 'longtext' ], [ 'tinyblob', 'blob', 'mediumblob', 'longblob' ] ] as $family ) {
			$a = array_search( $have, $family, true );
			$b = array_search( $declared, $family, true );

			if ( false !== $a && false !== $b ) {
				return $a >= $b;
			}
		}

		return false;
	}

	/**
	 * The last query's error ('' when it succeeded).
	 *
	 * @phpstan-impure
	 * @param \wpdb $wpdb Database.
	 * @return string
	 */
	private static function db_error( \wpdb $wpdb ): string {
		return $wpdb->last_error;
	}

	/**
	 * Column names and index names (PRIMARY for the primary key) of one CREATE TABLE statement.
	 *
	 * @param string $sql CREATE TABLE statement, one column or key per line.
	 * @return array{0: string[], 1: string[]}
	 */
	public static function declared_parts( string $sql ): array {
		[ $columns, $indexes ] = self::declared_shape( $sql );

		return [ array_keys( $columns ), array_keys( $indexes ) ];
	}

	/**
	 * Columns (name => type_key(), NOT NULL, declared NULL) and keys (name => unique, `column` or `column(prefix)` list)
	 * of one CREATE TABLE statement; the primary key is named PRIMARY.
	 *
	 * @param string $sql CREATE TABLE statement, one column or key per line.
	 * @return array{0: array<string, array{0: string, 1: bool, 2: bool}>, 1: array<string, array{0: bool, 1: list<string>}>}
	 */
	public static function declared_shape( string $sql ): array {
		$columns = [];
		$indexes = [];

		foreach ( preg_split( '/\R/', $sql ) ?: [] as $line ) {
			$line = trim( $line );

			if ( '' === $line || ')' === $line[0] || 1 === preg_match( '/^CREATE\s+TABLE\b/i', $line ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^(PRIMARY\s+KEY|(UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX)\s+`?(\w+)`?)\s*\((.*)\)/i', $line, $m ) ) {
				$name             = '' === $m[3] ? 'PRIMARY' : $m[3];
				$parts            = array_map( static fn( string $p ): string => strtolower( (string) preg_replace( '/[\s`]+/', '', $p ) ), explode( ',', $m[4] ) );
				$indexes[ $name ] = [ 'PRIMARY' === $name || 1 === preg_match( '/^UNIQUE/i', $m[2] ), $parts ];
			} elseif ( 1 === preg_match( '/^`?(\w+)`?\s+(\w+(?:\([\d,]+\))?(?:\s+unsigned)?)/i', $line, $m ) ) {
				$not_null         = 1 === preg_match( '/\bNOT\s+NULL\b/i', $line );
				$columns[ $m[1] ] = [ self::type_key( $m[2] ), $not_null, ! $not_null && 1 === preg_match( '/\bNULL\b/i', (string) preg_replace( '/\bDEFAULT\s+NULL\b/i', '', $line ) ) ];
			}
		}

		return [ $columns, $indexes ];
	}

	/**
	 * Whether a scope's backoff has passed; a `next` over an hour ahead (the clock went back) counts as passed.
	 *
	 * @param string $key Scope.
	 * @return bool
	 */
	private static function due( string $key ): bool {
		if ( self::$force ) {
			return true;
		}

		$wait = (int) ( self::entry( $key )['next'] ?? 0 ) - time();

		return $wait <= 0 || $wait > self::BACKOFF[ count( self::BACKOFF ) - 1 ];
	}

	/**
	 * Count an attempt and schedule the next one before the step runs; false (nothing written, the step must not run)
	 * when the stored state could not be read, as a write from a cached copy could bring back entries since cleared.
	 *
	 * @param string $key  Scope.
	 * @param string $step What runs, for the notice.
	 * @return bool
	 */
	private static function claim( string $key, string $step ): bool {
		$state = self::stored_state();

		if ( null === $state ) {
			return false;
		}

		$previous = is_array( $state[ $key ] ?? null ) ? $state[ $key ] : [];
		$attempts = (int) ( $previous['attempts'] ?? 0 ) + 1;
		unset( $previous['manual'] );
		$now      = time();

		// The previous claim never reached fail() or clear(): that try was killed.
		if ( ! empty( $previous['running'] ) ) {
			$previous['error'] = __( 'The last try stopped before it finished, for example at a time or memory limit.', 'perflocale' );
			unset( $previous['cause'] );
		}

		$state[ $key ] = array_merge(
			$previous,
			[
				'step'     => $step,
				'attempts' => $attempts,
				'next'     => $now + self::delay( $attempts ),
				'since'    => (int) ( $previous['since'] ?? $now ),
				'last'     => $now,
				'running'  => $now,
			]
		);

		update_option( self::STATE_OPTION, $state, false );

		return true;
	}

	/**
	 * Record why the claimed attempt failed.
	 *
	 * @param string               $key   Scope.
	 * @param string               $step  What failed, for the notice.
	 * @param string               $error The cause.
	 * @param array<string, mixed> $extra Extra fields to keep.
	 * @return void
	 */
	private static function fail( string $key, string $step, string $error, array $extra = [] ): void {
		$state = self::stored_state();
		$error = '' === trim( $error ) ? __( 'No cause was reported.', 'perflocale' ) : $error;

		// Unreadable state: no write from a cached copy. The claim stays (the next one reports a stopped try); the cause goes to the log.
		if ( null === $state ) {
			self::log( $step, $error . ' (not recorded: the upgrade state could not be read)' );
			return;
		}

		$entry = is_array( $state[ $key ] ?? null ) ? $state[ $key ] : [];

		unset( $entry['cause'] );

		// Notice text for managers: no paths, bounded, valid UTF-8 (an invalid byte would break the serialized row).
		$entry = array_merge(
			$entry,
			$extra,
			[
				'step'    => $step,
				'error'   => wp_check_invalid_utf8( mb_substr( \PerfLocale\Util\PathRedactor::redact( $error ), 0, 300 ), true ),
				'running' => 0,
			]
		);

		$state[ $key ] = $entry;
		update_option( self::STATE_OPTION, $state, false );
		self::log( $step, $error . ' (attempt ' . (int) ( $entry['attempts'] ?? 0 ) . ')' );
	}

	/**
	 * Forget a scope; writes nothing when it has no entry or the state could not be read (its claim then stays).
	 *
	 * @param string $key Scope.
	 * @return void
	 */
	private static function clear( string $key ): void {
		$state = self::stored_state();

		if ( null === $state || ! isset( $state[ $key ] ) ) {
			return;
		}

		unset( $state[ $key ] );

		if ( [] === $state ) {
			delete_option( self::STATE_OPTION );
		} else {
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/**
	 * One scope's entry.
	 *
	 * @param string $key Scope.
	 * @return array<array-key, mixed>
	 */
	private static function entry( string $key ): array {
		$entry = self::state()[ $key ] ?? [];

		return is_array( $entry ) ? $entry : [];
	}

	/**
	 * The whole state of this blog.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function state(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * The state as stored, for the lock holder's writes: a concurrent request's stale `notoptions` in a shared object
	 * cache can hide the row from get_option(). Null when the read failed.
	 *
	 * @return array<array-key, mixed>|null
	 */
	private static function stored_state(): ?array {
		$raw = self::stored( self::STATE_OPTION );

		if ( false === $raw ) {
			return null;
		}

		$state = null === $raw ? [] : maybe_unserialize( $raw );

		return is_array( $state ) ? $state : [];
	}

	/**
	 * Drop this request's cached copies of the state (and $also), so the lock holder reads what another runner wrote.
	 *
	 * @param string $also Another non-autoloaded option to read again.
	 * @return void
	 */
	private static function refresh( string $also = '' ): void {
		$names = '' === $also ? [ self::STATE_OPTION ] : [ self::STATE_OPTION, $also ];

		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}

		NotOptions::unlist( $names );
	}

	/**
	 * An option's stored value read from the database, past this request's caches; null when the row is missing, false
	 * when the read failed.
	 *
	 * @param string $name Option name.
	 * @return string|null|false
	 */
	private static function stored( string $name ): string|null|false {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// HyperDB and LudicrousDB send a SELECT to a replica, which may not have the version just written yet.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read under the upgrade lock; a cached copy may predate the last runner.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return null === $value ? null : (string) $value;
	}

	/**
	 * End an attempt whose version read failed, before any step: the ordinary backoff, or none for a stopped step, which
	 * keeps its stop as the read proved nothing about it.
	 *
	 * @param string $key    Scope.
	 * @param bool   $repair Retry now or WP-CLI: name the cause there too.
	 * @return string 'failed'.
	 */
	private static function unreadable_version( string $key, bool $repair ): string {
		$step  = __( 'Reading the stored version', 'perflocale' );
		$error = __( 'The stored version could not be read, so nothing was changed. The next try reads it again.', 'perflocale' );

		if ( isset( self::entry( $key )['manual'] ) ) {
			self::log( $step, $error );
		} elseif ( self::claim( $key, $step ) ) {
			self::fail( $key, $step, $error );
		} else {
			self::log( $step, $error );
		}

		if ( $repair ) {
			self::$repair_error = $error;
		}

		return 'failed';
	}

	/**
	 * Log a failure for the operator (bounded by the backoff).
	 *
	 * @param string $step    What failed.
	 * @param string $message The cause.
	 * @return void
	 */
	private static function log( string $step, string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the upgrade-failure path.
		error_log( '[PerfLocale] upgrade: ' . $step . ' failed: ' . $message );
	}
}
