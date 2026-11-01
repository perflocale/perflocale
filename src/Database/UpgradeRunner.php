<?php
/**
 * Upgrade runner: schema steps, upgrade tasks and add-on steps under one lock, with backoff.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

use PerfLocale\Concurrency\Lock;

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

	/** Per-blog state: scope => step, attempts, error, next, since, last, running (+ done, target for `update`). */
	public const STATE_OPTION = 'perflocale_upgrade_state';

	/** Lock name; the row lives in the blog's own options table. */
	public const LOCK = 'upgrade';

	/** Lock TTL, renewed after each step and task: one ALTER on a large table can run for many minutes. */
	private const LOCK_TTL = 3600;

	/** Seconds before the next try after the 1st, 2nd, 3rd, 4th and later failure; Migrator::maybe_update() mirrors the last. */
	private const BACKOFF = [ 60, 300, 900, 3600 ];

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

			if ( 'schema' === $scope ) {
				$runner->schema();
			} else {
				$runner->update();
			}

			if ( is_admin() && [] !== self::failures() && false === has_action( 'admin_notices', [ \PerfLocale\Admin\UpgradeNotice::class, 'render' ] ) ) {
				add_action( 'admin_notices', [ \PerfLocale\Admin\UpgradeNotice::class, 'render' ] );
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
			$old = (int) self::stored( 'perflocale_db_version' );

			if ( $old >= $target ) {
				self::clear( 'schema' );

				if ( $repair ) {
					self::$repair_error = $this->apply_and_verify();

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

			for ( $version = $old + 1; $version <= $target; $version++ ) {
				/* translators: %d: database schema version. */
				$step = sprintf( __( 'Database schema version %d', 'perflocale' ), $version );

				// Recorded before the step, so a run killed by a time or memory limit still backs off and shows.
				self::claim( 'schema', $step );
				$error = $this->step( $version );

				if ( ! self::owns() ) {
					return 'busy';
				}

				if ( '' === $error ) {
					update_option( 'perflocale_db_version', $version, true );
					$error = self::saved( 'perflocale_db_version', $version );
				}

				if ( '' !== $error ) {
					self::fail( 'schema', $step, $error );
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
	 * @param int $version Target version of the step.
	 * @return string
	 */
	private function step( int $version ): string {
		$method = 'migrate_to_' . $version;

		try {
			// Before dbDelta, so drops and renames finish first.
			if ( method_exists( $this->steps, $method ) ) {
				$this->steps->$method();
			}

			return $this->apply_and_verify();
		} catch ( \Throwable $e ) {
			return self::describe( $e );
		}
	}

	/**
	 * dbDelta the whole schema, then ask the server for every table, column and index; '' when all are there.
	 *
	 * @return string
	 */
	private function apply_and_verify(): string {
		Schema::create_tables();

		$missing = self::missing_parts();

		if ( null === $missing ) {
			return __( 'The database did not return the table structure.', 'perflocale' );
		}

		if ( [] === $missing ) {
			return '';
		}

		/* translators: %s: comma-separated table, column or index names. */
		return sprintf( __( 'The database did not create %s.', 'perflocale' ), implode( ', ', array_slice( $missing, 0, 8 ) ) . ( count( $missing ) > 8 ? ', ...' : '' ) );
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

			$stored = (string) ( self::stored( 'perflocale_version' ) ?? '0' );

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

			self::claim( 'update', $step );

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
			'cache'   => static function () use ( $plugin ): void {
				if ( $plugin->has( 'cache' ) ) {
					$plugin->get( 'cache' )->flush_all();
				}
			},
			// Orphan translation groups (a string deleted without its group, a merge import that stranded some).
			'sweep'   => static function () use ( $plugin ): void {
				// It deletes rows by column state: not against a schema the code does not expect.
				if ( SchemaWrites::upgrade_pending() ) {
					throw new \RuntimeException( __( 'Waiting for the database schema upgrade to finish.', 'perflocale' ), self::OWN_ERROR ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An int constant; the notice escapes the message.
				}

				if ( $plugin->has( 'cache' ) ) {
					( new Repository\TranslationGroupRepository( $plugin->get( 'cache' ) ) )->sweep_orphan_groups();
				}
			},
			'rewrite' => static function (): void {
				update_option( 'perflocale_flush_rules', 1, false );
			},
			// A guard file holding only "Deny from all" answers 500 on Apache 2.4 without mod_access_compat.
			'guards'  => static function (): void {
				\PerfLocale\Util\Uploads::harden_existing();
			},
			'hook'    => static function () use ( $stored ): void {
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

			self::claim( $key, $step );

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
		$versions = null === $raw ? null : maybe_unserialize( $raw );
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
	 * Tables, columns and indexes of SchemaWrites::definitions() the server does not have; null when unreadable.
	 *
	 * Asks with dbDelta's own unquoted DESCRIBE and SHOW INDEX FROM, which both SQLite drivers implement.
	 *
	 * @return string[]|null Names such as `wp_perflocale_jobs`, `wp_perflocale_jobs.uuid`, `wp_perflocale_jobs index uuid`.
	 */
	public static function missing_parts(): ?array {
		$wpdb = $GLOBALS['wpdb'] ?? null;

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

		foreach ( SchemaWrites::definitions() as $short => $sql ) {
			$table = Schema::sanitize_table( Schema::table( $short ) );

			if ( ! isset( $have[ strtolower( $table ) ] ) ) {
				$missing[] = $table;
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Sanitized identifier, the statements dbDelta sends.
			$fields = (array) $wpdb->get_col( "DESCRIBE {$table};" );
			$error  = self::db_error( $wpdb );
			$rows   = (array) $wpdb->get_results( "SHOW INDEX FROM {$table};" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( '' !== $error . self::db_error( $wpdb ) || [] === $fields || [] === $rows ) {
				return null;
			}

			$fields = array_flip( array_map( static fn( $f ): string => strtolower( (string) $f ), $fields ) );
			$keys   = [];

			foreach ( $rows as $row ) {
				$keys[] = strtolower( (string) ( ( (array) $row )['Key_name'] ?? '' ) );
			}

			[ $columns, $indexes ] = self::declared_parts( $sql );

			foreach ( $columns as $column ) {
				if ( ! isset( $fields[ strtolower( $column ) ] ) ) {
					$missing[] = $table . '.' . $column;
				}
			}

			foreach ( $indexes as $index ) {
				if ( ! self::has_key( $keys, strtolower( $index ) ) ) {
					$missing[] = $table . ' index ' . $index;
				}
			}
		}

		return $missing;
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
	 * Whether a Key_name list holds an index: exact, or with the `<table>__` the SQLite drivers may leave in front.
	 *
	 * @param string[] $keys  Lower-cased Key_name values.
	 * @param string   $index Lower-cased declared name.
	 * @return bool
	 */
	private static function has_key( array $keys, string $index ): bool {
		foreach ( $keys as $key ) {
			if ( $key === $index || str_ends_with( $key, '__' . $index ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Column names and index names (PRIMARY for the primary key) of one CREATE TABLE statement.
	 *
	 * @param string $sql CREATE TABLE statement, one column or key per line.
	 * @return array{0: string[], 1: string[]}
	 */
	public static function declared_parts( string $sql ): array {
		$columns = [];
		$indexes = [];

		foreach ( preg_split( '/\R/', $sql ) ?: [] as $line ) {
			$line = trim( $line );

			if ( '' === $line || ')' === $line[0] || 1 === preg_match( '/^CREATE\s+TABLE\b/i', $line ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^PRIMARY\s+KEY\b/i', $line ) ) {
				$indexes[] = 'PRIMARY';
			} elseif ( 1 === preg_match( '/^(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX)\s+`?(\w+)`?/i', $line, $m ) ) {
				$indexes[] = $m[1];
			} elseif ( 1 === preg_match( '/^`?(\w+)`?\s+\w/', $line, $m ) ) {
				$columns[] = $m[1];
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
	 * Count an attempt and schedule the next one before the step runs.
	 *
	 * @param string $key  Scope.
	 * @param string $step What runs, for the notice.
	 * @return void
	 */
	private static function claim( string $key, string $step ): void {
		$state    = self::state();
		$previous = self::entry( $key );
		$attempts = (int) ( $previous['attempts'] ?? 0 ) + 1;
		$now      = time();

		// The previous claim never reached fail() or clear(): that try was killed.
		if ( ! empty( $previous['running'] ) ) {
			$previous['error'] = __( 'The last try stopped before it finished, for example at a time or memory limit.', 'perflocale' );
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
		$state = self::state();
		$entry = self::entry( $key );
		$error = '' === trim( $error ) ? __( 'No cause was reported.', 'perflocale' ) : $error;

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
	 * Forget a scope; writes nothing when it has no entry.
	 *
	 * @param string $key Scope.
	 * @return void
	 */
	private static function clear( string $key ): void {
		$state = self::state();

		if ( ! isset( $state[ $key ] ) ) {
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
	 * An option's stored value read from the database, past this request's caches; null when the row is missing.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private static function stored( string $name ): ?string {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// HyperDB and LudicrousDB send a SELECT to a replica, which may not have the version just written yet.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read under the upgrade lock; a cached copy may predate the last runner.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? null : (string) $value;
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
