<?php
/**
 * Content ReSync tables: bases and per-translation states.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Schema;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes `{prefix}perflocale_resync_base` and `{prefix}perflocale_resync_state`.
 *
 * Tables are resolved per call (switch_to_blog()); datetimes are UTC; snapshot columns are bytes.
 *
 * @phpstan-type BaseRow array{id: int, source_id: int, source_hash: string, fields_hash: string, origin: string, revision_id: int, title: string, content: string, excerpt: string, fields: string, created_at: string, last_used_at: string}
 * @phpstan-type StateRow array{id: int, translation_id: int, source_id: int, language_id: int, base_id: int, prev_base_id: int, source_hash: string, fields_hash: string, translation_hash: string, translation_fields_hash: string, how: string, base_quality: string, complete: int, fields_owned: string, fields_unknown: string, block_map: string, intent: string, intent_at: string, synced_at: string}
 */
final class ResyncRepository {

	/** How an intent naming the stored post hash starts before its post write (the key leads, no hash yet). */
	public const INTENT_OPEN = '{"post_written":null';

	/** How such an intent starts before core's UPDATE of its post (the writing key follows, no hash yet). */
	public const INTENT_OPEN_WRITING = '{"post_written":null,"post_writing":null';

	/** Seconds touch_bases() leaves a newer last_used_at as it is; base retention adds them to its cutoff. */
	public const TOUCH_EVERY = 86400;

	/** Ids per orphan-check window: one statement reads at most this many primary-key entries. */
	public const WINDOW = 2000;

	/** A begin_writes() answer: a transaction was open already and the writes nest in it. */
	public const WRITES_JOINED = 'joined';

	/** A begin_writes() answer: this call opened the transaction; end_writes() or abort_writes() closes it. */
	public const WRITES_STARTED = 'started';

	/** A begin_writes() answer: START TRANSACTION failed; nothing may be written. */
	public const WRITES_FAILED = 'failed';

	/** The `object_type` of every row this repository reads or writes: its ids are post ids. */
	public const OBJECT_TYPE = 'post';

	/** Pending post rows per page read of bases_named_by_pending(). */
	private const PENDING_PAGE = 50;

	/**
	 * Payload bytes one page read of bases_named_by_pending() may carry; a payload longer than
	 * PENDING_PAGE_BYTES / PENDING_PAGE is read alone.
	 */
	private const PENDING_PAGE_BYTES = 8388608;

	/**
	 * State columns a caller may write, with their placeholder.
	 *
	 * @var array<string, string>
	 */
	private const STATE_COLUMNS = [
		'translation_id'          => '%d',
		'source_id'               => '%d',
		'language_id'             => '%d',
		'base_id'                 => '%d',
		'prev_base_id'            => '%d',
		'source_hash'             => '%s',
		'fields_hash'             => '%s',
		'translation_hash'        => '%s',
		'translation_fields_hash' => '%s',
		'how'                     => '%s',
		'base_quality'            => '%s',
		'complete'                => '%d',
		'fields_owned'            => '%s',
		'fields_unknown'          => '%s',
		'block_map'               => '%s',
		'intent'                  => '%s',
		'intent_at'               => '%s',
		'synced_at'               => '%s',
	];

	/**
	 * The columns of the narrow state read: no JSON columns.
	 *
	 * @var string[]
	 */
	private const STATE_NARROW = [
		'id',
		'translation_id',
		'source_id',
		'language_id',
		'base_id',
		'prev_base_id',
		'source_hash',
		'fields_hash',
		'translation_hash',
		'translation_fields_hash',
		'how',
		'base_quality',
		'complete',
		'intent_at',
		'synced_at',
	];

	/**
	 * WordPress's database object.
	 *
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * The connection marker of the open begin_writes() transaction (MySQL/MariaDB), shared by every instance.
	 *
	 * @var ConnectionGuard|null
	 */
	private static ?ConnectionGuard $tx_guard = null;

	/**
	 * Constructor.
	 *
	 * @throws \RuntimeException When WordPress's database object is missing.
	 */
	public function __construct() {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}

		$this->wpdb = $wpdb;
	}

	/**
	 * The base table of the current blog.
	 *
	 * @return string
	 */
	public static function base_table(): string {
		return Schema::table( 'resync_base' );
	}

	/**
	 * The state table of the current blog.
	 *
	 * @return string
	 */
	public static function state_table(): string {
		return Schema::table( 'resync_state' );
	}

	/**
	 * Now, in UTC, as the tables store it.
	 *
	 * @return string
	 */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Whether the database takes `FOR UPDATE` / `LOCK IN SHARE MODE`: SQLite (DB_ENGINE `sqlite`) does not,
	 * and needs none since it runs one writer at a time.
	 *
	 * @return bool
	 */
	private static function locking_reads(): bool {
		return ! ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE );
	}

	/**
	 * Run a prepared statement (false when prepare() refused it).
	 *
	 * @param string|null $sql Prepared SQL.
	 * @return int|bool Rows affected, true for statements without a row count, false on failure.
	 */
	private function run( ?string $sql ): int|bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Every caller passes the result of $this->wpdb->prepare().
		return null === $sql ? false : $this->wpdb->query( $sql );
	}

	/**
	 * Open a transaction for the writes that follow, unless one is open already (they then nest in it). Its
	 * connection is marked first (ConnectionGuard): a write wpdb resends on a new connection then matches nothing.
	 *
	 * @return string WRITES_JOINED, WRITES_STARTED (end_writes() closes it) or WRITES_FAILED.
	 */
	public function begin_writes(): string {
		if ( TranslationGroupWrites::in_transaction() ) {
			return self::WRITES_JOINED;
		}

		$guard = ConnectionGuard::applies() ? new ConnectionGuard( 'resync_tx' ) : null;

		// A database that refuses the marker (a db.php drop-in without user variables) runs the writes unmarked.
		self::$tx_guard = null !== $guard && $guard->begin() ? $guard : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			self::$tx_guard?->end();
			self::$tx_guard = null;

			return self::WRITES_FAILED;
		}

		return self::WRITES_STARTED;
	}

	/**
	 * Commit the transaction begin_writes() opened, only while it ran on one connection; otherwise, or when the
	 * commit fails, roll back so the connection never stays in it.
	 *
	 * @return bool Whether it committed.
	 */
	public function end_writes(): bool {
		$guard          = self::$tx_guard;
		self::$tx_guard = null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		$done = ( null === $guard || $guard->kept() ) && false !== $this->wpdb->query( 'COMMIT' );

		// After the COMMIT too: one resent on a new connection committed nothing (the server rolled the unit back).
		$done = $done && ( null === $guard || $guard->kept() );
		$guard?->end();

		if ( $done ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		$this->wpdb->query( 'ROLLBACK' );

		return false;
	}

	/**
	 * Roll back the transaction begin_writes() opened.
	 *
	 * @return void
	 */
	public function abort_writes(): void {
		self::$tx_guard?->end();
		self::$tx_guard = null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		$this->wpdb->query( 'ROLLBACK' );
	}

	/**
	 * The marker condition a write inside the begin_writes() transaction carries (' AND @perflocale_resync_tx = ...'), else ''.
	 *
	 * @return string
	 */
	private static function guarded(): string {
		$condition = null === self::$tx_guard ? '' : self::$tx_guard->condition();

		return '' === $condition ? '' : ' AND ' . $condition;
	}

	/**
	 * A database row as a typed base row.
	 *
	 * @param array<mixed> $row Row (ARRAY_A).
	 * @return array<string, int|string>
	 * @phpstan-return BaseRow
	 */
	private static function shape_base( array $row ): array {
		$int = static fn ( string $key ): int => Scalar::int( $row[ $key ] ?? 0 );
		$str = static fn ( string $key ): string => Scalar::str( $row[ $key ] ?? '' );

		return [
			'id'           => $int( 'id' ),
			'source_id'    => $int( 'source_id' ),
			'source_hash'  => $str( 'source_hash' ),
			'fields_hash'  => $str( 'fields_hash' ),
			'origin'       => $str( 'origin' ),
			'revision_id'  => $int( 'revision_id' ),
			'title'        => $str( 'title' ),
			'content'      => $str( 'content' ),
			'excerpt'      => $str( 'excerpt' ),
			'fields'       => $str( 'fields' ),
			'created_at'   => $str( 'created_at' ),
			'last_used_at' => $str( 'last_used_at' ),
		];
	}

	/**
	 * A database row as a typed state row (missing columns are '' / 0).
	 *
	 * @param array<mixed> $row Row (ARRAY_A).
	 * @return array<string, int|string>
	 * @phpstan-return StateRow
	 */
	private static function shape_state( array $row ): array {
		$int = static fn ( string $key ): int => Scalar::int( $row[ $key ] ?? 0 );
		$str = static fn ( string $key ): string => Scalar::str( $row[ $key ] ?? '' );

		return [
			'id'                      => $int( 'id' ),
			'translation_id'          => $int( 'translation_id' ),
			'source_id'               => $int( 'source_id' ),
			'language_id'             => $int( 'language_id' ),
			'base_id'                 => $int( 'base_id' ),
			'prev_base_id'            => $int( 'prev_base_id' ),
			'source_hash'             => $str( 'source_hash' ),
			'fields_hash'             => $str( 'fields_hash' ),
			'translation_hash'        => $str( 'translation_hash' ),
			'translation_fields_hash' => $str( 'translation_fields_hash' ),
			'how'                     => $str( 'how' ),
			'base_quality'            => $str( 'base_quality' ),
			'complete'                => $int( 'complete' ),
			'fields_owned'            => $str( 'fields_owned' ),
			'fields_unknown'          => $str( 'fields_unknown' ),
			'block_map'               => $str( 'block_map' ),
			'intent'                  => $str( 'intent' ),
			'intent_at'               => $str( 'intent_at' ),
			'synced_at'               => $str( 'synced_at' ),
		];
	}

	/**
	 * The id of the base row of a source state, 0 when none (the unique key).
	 *
	 * @param int    $source_id   Source post id.
	 * @param string $source_hash Content hash.
	 * @param string $fields_hash Field digest ('' when fields were not read).
	 * @param bool   $latest      A locking read on MySQL and MariaDB: the latest committed row, also inside a transaction that has read before.
	 * @return int
	 */
	public function find_base( int $source_id, string $source_hash, string $fields_hash, bool $latest = false ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE source_id = %d AND source_hash = %s AND fields_hash = %s AND object_type = %s LIMIT 1' . ( $latest && self::locking_reads() ? ' LOCK IN SHARE MODE' : '' ),
				self::base_table(),
				$source_id,
				$source_hash,
				$fields_hash,
				self::OBJECT_TYPE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return Scalar::int( $id );
	}

	/**
	 * Insert a base row (the caller ran find_base() first, so an existing state never re-sends its content).
	 *
	 * A concurrent duplicate is left as it is (`id = id`), so no failed statement lands in a caller's
	 * transaction. The id is read back with a locking read, never insert_id: SQLite's is stale when nothing was inserted.
	 *
	 * @param array<string, mixed> $row source_id, source_hash, fields_hash, origin, revision_id, title, content, excerpt, fields.
	 * @return int The id of the new or the existing row, or 0 on failure.
	 */
	public function insert_base( array $row ): int {
		$now         = self::now();
		$source_id   = max( 0, Scalar::int( $row['source_id'] ?? 0 ) );
		$source_hash = Scalar::str( $row['source_hash'] ?? '' );
		$fields_hash = Scalar::str( $row['fields_hash'] ?? '' );

		$guard = self::guarded();

		// In the begin_writes() transaction the row is a SELECT whose WHERE carries the marker, appended after prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One of two constant statements; the marker is a checked literal.
		$sql    = $this->wpdb->prepare(
			'' === $guard
				? 'INSERT INTO %i (source_id, source_hash, fields_hash, origin, revision_id, title, content, excerpt, fields, created_at, last_used_at, object_type)
				 VALUES (%d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE id = id'
				: 'INSERT INTO %i (source_id, source_hash, fields_hash, origin, revision_id, title, content, excerpt, fields, created_at, last_used_at, object_type)
				 SELECT %d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s FROM DUAL WHERE 1 = 1',
			self::base_table(),
			$source_id,
			$source_hash,
			$fields_hash,
			Scalar::str( $row['origin'] ?? 'live' ),
			max( 0, Scalar::int( $row['revision_id'] ?? 0 ) ),
			Scalar::str( $row['title'] ?? '' ),
			Scalar::str( $row['content'] ?? '' ),
			Scalar::str( $row['excerpt'] ?? '' ),
			Scalar::str( $row['fields'] ?? '' ),
			$now,
			$now,
			self::OBJECT_TYPE
		);
		$result = $this->run( null === $sql || '' === $guard ? $sql : $sql . $guard . ' ON DUPLICATE KEY UPDATE id = id' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( false === $result ) {
			return 0;
		}

		$id = $this->find_base( $source_id, $source_hash, $fields_hash, true );

		return '' === $this->wpdb->last_error ? $id : 0;
	}

	/**
	 * One base row by id.
	 *
	 * @param int $id Base id.
	 * @return array<string, int|string>|null
	 * @phpstan-return BaseRow|null
	 */
	public function base( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND object_type = %s', self::base_table(), $id, self::OBJECT_TYPE ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? self::shape_base( $row ) : null;
	}

	/**
	 * The text bytes a read of a base's document holds (its own text, or its revision's when empty), in one
	 * statement without reading the text.
	 *
	 * @param int $id Base id.
	 * @return int|null Null when the row does not exist or the read failed.
	 */
	public function base_bytes( int $id ): ?int {
		if ( $id <= 0 ) {
			return null;
		}

		$own      = \PerfLocale\Resync\PostReads::base_bytes_sql();
		$revision = \PerfLocale\Resync\PostReads::bytes_sql();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The byte expressions are built from fixed column names.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT b.revision_id, $own AS own_bytes, $revision AS revision_bytes FROM %i b LEFT JOIN %i p ON p.ID = b.revision_id WHERE b.id = %d AND b.object_type = %s",
				self::base_table(),
				$this->wpdb->posts,
				$id,
				self::OBJECT_TYPE
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) || '' !== $this->wpdb->last_error ) {
			return null;
		}

		$own_bytes = Scalar::int( $row['own_bytes'] ?? 0 );

		return 0 === $own_bytes && Scalar::int( $row['revision_id'] ?? 0 ) > 0 ? Scalar::int( $row['revision_bytes'] ?? 0 ) : $own_bytes;
	}

	/**
	 * Set `last_used_at` of base rows to now: the listed ids and, with a translation id, its state row's bases.
	 * The state row is read by its unique key, then one primary-key UPDATE, so the base table is never scanned.
	 * A row touched less than TOUCH_EVERY ago is left as it is, so a busy base is written at most once a day.
	 *
	 * @param array<int> $ids            Base ids (0 ignored).
	 * @param int        $translation_id Translation whose current row's bases are touched too (0 for none).
	 * @return int Rows updated (0 when all were touched recently); -1 when a statement failed (a failed state read runs no UPDATE).
	 */
	public function touch_bases( array $ids, int $translation_id = 0 ): int {
		if ( $translation_id > 0 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
			$read = $this->run( $this->wpdb->prepare( 'SELECT base_id, prev_base_id FROM %i WHERE translation_id = %d AND object_type = %s', self::state_table(), $translation_id, self::OBJECT_TYPE ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

			if ( ! is_int( $read ) ) {
				return -1;
			}

			$row = ( (array) $this->wpdb->last_result )[0] ?? null;

			if ( null !== $row ) {
				$row   = (array) $row;
				$ids[] = Scalar::int( $row['base_id'] ?? 0 );
				$ids[] = Scalar::int( $row['prev_base_id'] ?? 0 );
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( [ Scalar::class, 'int' ], $ids ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( [] === $ids ) {
			return 0;
		}

		$now = self::now();
		$sql = 'UPDATE %i SET last_used_at = %s WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') AND object_type = %s AND last_used_at < %s';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only; the marker is a checked literal.
		$prepared = $this->wpdb->prepare( $sql, array_merge( [ self::base_table(), $now ], $ids, [ self::OBJECT_TYPE, gmdate( 'Y-m-d H:i:s', (int) strtotime( $now . ' UTC' ) - self::TOUCH_EVERY ) ] ) );
		$result   = $this->run( null === $prepared ? null : $prepared . self::guarded() );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return false === $result ? -1 : (int) $result;
	}

	/**
	 * The bases (base_id, prev_base_id) of these translations' state rows, by one read on their unique key.
	 *
	 * @param array<int> $translation_ids Translation ids.
	 * @return list<int>|null Base ids (0 included), null when the read failed.
	 */
	public function state_bases( array $translation_ids ): ?array {
		$ids = array_values( array_unique( array_filter( array_map( [ Scalar::class, 'int' ], $translation_ids ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( [] === $ids ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT base_id, prev_base_id FROM %i WHERE translation_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') AND object_type = %s', array_merge( [ self::state_table() ], $ids, [ self::OBJECT_TYPE ] ) ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( '' !== $this->wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = Scalar::int( $row['base_id'] ?? 0 );
			$out[] = Scalar::int( $row['prev_base_id'] ?? 0 );
		}

		return $out;
	}

	/**
	 * Keep a reused base out of the retention prune: touch it; when the touch wrote nothing (0 is never "gone"), a
	 * current read tells a recently touched base, which is no prune candidate, from one the prune deleted.
	 *
	 * @param int $id Base id.
	 * @return bool|null Whether the base exists; null when a statement failed.
	 */
	public function claim_base( int $id ): ?bool {
		$touched = $this->touch_bases( [ $id ] );

		if ( $touched < 0 ) {
			return null;
		}

		if ( $touched > 0 ) {
			return true;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND object_type = %s' . ( self::locking_reads() ? ' LOCK IN SHARE MODE' : '' ), self::base_table(), $id, self::OBJECT_TYPE )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return '' === $this->wpdb->last_error ? null !== $found : null;
	}

	/**
	 * The key list and `seen` map of a base's field snapshot: the `fields` column alone, never the content.
	 *
	 * @param int $id Base id.
	 * @return array{keys: list<string>, seen: array<string, string>|null}|null Null when the row does not exist.
	 */
	public function base_keys( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$fields = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT fields FROM %i WHERE id = %d AND object_type = %s', self::base_table(), $id, self::OBJECT_TYPE ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_string( $fields ) ) {
			return null;
		}

		return \PerfLocale\Resync\Store::base_keys( $fields );
	}

	/**
	 * The key lists and `seen` maps of several bases' field snapshots, in one read.
	 *
	 * @param array<int, int> $ids Base ids.
	 * @return array<int, array{keys: list<string>, seen: array<string, string>|null}>|null By base id (missing rows absent); null when the read failed.
	 */
	public function base_keys_of( array $ids ): ?array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list is built from placeholders.
		$sql = $this->wpdb->prepare( "SELECT id, fields FROM %i WHERE id IN ($in) AND object_type = %s", array_merge( [ self::base_table() ], $ids, [ self::OBJECT_TYPE ] ) );

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		// query() answers an int only without an error (get_results() answers [] for a failed read).
		$read = $this->wpdb->query( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_int( $read ) ) {
			return null;
		}

		$out = [];

		foreach ( (array) $this->wpdb->last_result as $row ) {
			$row = (array) $row;

			$out[ Scalar::int( $row['id'] ?? 0 ) ] = \PerfLocale\Resync\Store::base_keys( Scalar::str( $row['fields'] ?? '' ) );
		}

		return $out;
	}

	/**
	 * The state row of a translation.
	 *
	 * @param int $translation_id Translation post id.
	 * @return array<string, int|string>|null
	 * @phpstan-return StateRow|null
	 */
	public function state( int $translation_id ): ?array {
		if ( $translation_id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE translation_id = %d AND object_type = %s', self::state_table(), $translation_id, self::OBJECT_TYPE ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? self::shape_state( $row ) : null;
	}

	/**
	 * The hash columns of a translation's state (no JSON columns).
	 *
	 * @param int $translation_id Translation post id.
	 * @return array<string, int|string>|null
	 * @phpstan-return StateRow|null
	 */
	public function state_hashes( int $translation_id ): ?array {
		if ( $translation_id <= 0 ) {
			return null;
		}

		$sql = 'SELECT ' . implode( ', ', self::STATE_NARROW ) . ' FROM %i WHERE translation_id = %d AND object_type = %s';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Column names come from the constant above.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, self::state_table(), $translation_id, self::OBJECT_TYPE ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? self::shape_state( $row ) : null;
	}

	/**
	 * The hash columns of several translations' states in one statement, by translation id.
	 * Null when the read failed: a failed read is not "no state".
	 *
	 * @param array<int, int> $translation_ids Translation post ids.
	 * @return array<int, array<string, int|string>>|null
	 * @phpstan-return array<int, StateRow>|null
	 */
	public function state_hashes_of( array $translation_ids ): ?array {
		$ids = [];

		foreach ( $translation_ids as $id ) {
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( [] === $ids ) {
			return [];
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Column names come from the constant above; the IN list is built from placeholders.
		$sql = $this->wpdb->prepare( 'SELECT ' . implode( ', ', self::STATE_NARROW ) . " FROM %i WHERE translation_id IN ($in) AND object_type = %s", array_merge( [ self::state_table() ], array_values( $ids ), [ self::OBJECT_TYPE ] ) );

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		// query() answers an int only without an error (get_results() answers [] for a failed read).
		$read = $this->wpdb->query( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_int( $read ) ) {
			return null;
		}

		$out = [];

		foreach ( (array) $this->wpdb->last_result as $row ) {
			$state = self::shape_state( (array) $row );

			$out[ $state['translation_id'] ] = $state;
		}

		return $out;
	}

	/**
	 * The translations of sources in the given languages (every language when none) in one statement, as a ReSync or
	 * bulk run pairs them. Null when the read failed.
	 *
	 * @param array<int, int> $source_ids   Source post ids.
	 * @param array<int, int> $language_ids Language ids.
	 * @return list<int>|null
	 */
	public function translations_of( array $source_ids, array $language_ids ): ?array {
		if ( [] === $source_ids ) {
			return [];
		}

		$links   = Schema::table( 'translation_links' );
		$src_in  = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
		$lang_in = [] === $language_ids ? '' : ' AND t.language_id IN (' . implode( ',', array_fill( 0, count( $language_ids ), '%d' ) ) . ')';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN lists are built from placeholders.
		$sql = $this->wpdb->prepare(
			"SELECT DISTINCT t.object_id FROM %i s INNER JOIN %i t ON t.group_id = s.group_id AND t.type = 'post'
			 WHERE s.type = 'post' AND s.object_id IN ($src_in) AND t.object_id <> s.object_id$lang_in",
			array_merge( [ $links, $links ], array_values( $source_ids ), array_values( $language_ids ) )
		);

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		$read = $this->wpdb->query( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_int( $read ) ) {
			return null;
		}

		return array_values( array_map( static fn ( $row ): int => Scalar::int( ( (array) $row )['object_id'] ?? 0 ), (array) $this->wpdb->last_result ) );
	}

	/**
	 * Upsert a translation's state on the `translation` key (columns not given keep their value); with
	 * `$only_without_intent` a row holding an intent is left, and a `fields_unknown` the column would cut is refused. The
	 * id is read back by the key (`FOR UPDATE`): SQLite's insert_id is stale.
	 *
	 * @param int          $translation_id      Translation post id.
	 * @param array<mixed> $values              Columns to write (see STATE_COLUMNS).
	 * @param bool         $only_without_intent Write an existing row only while it holds no intent.
	 * @return int The row id, 0 on failure, when the row is gone or (with $only_without_intent) when it holds another intent.
	 */
	public function upsert_state( int $translation_id, array $values, bool $only_without_intent = false ): int {
		if ( $translation_id <= 0 ) {
			return 0;
		}

		$intent = Scalar::str( $values['intent'] ?? '' );

		if ( $only_without_intent && '' === $intent ) {
			return 0;
		}

		$values['translation_id'] = $translation_id;

		$insert_columns = [];
		$insert_holders = [];
		$insert_values  = [];
		$updates        = [];
		$update_values  = [];
		$intent_at      = null;
		$now            = self::now();

		foreach ( self::STATE_COLUMNS as $column => $holder ) {
			$given = array_key_exists( $column, $values );
			$value = $given ? $values[ $column ] : null;

			if ( 'intent_at' === $column ) {
				$insert_columns[] = $column;

				if ( $given && null !== $value && '' !== $value ) {
					$insert_holders[] = '%s';
					$insert_values[]  = Scalar::str( $value );
					$intent_at        = [ '%s', Scalar::str( $value ) ];
				} else {
					$insert_holders[] = 'NULL';

					if ( $given ) {
						$intent_at = [ 'NULL', null ];
					}
				}

				continue;
			}

			$insert_columns[] = $column;
			$insert_holders[] = $holder;

			if ( '%d' === $holder ) {
				$insert_values[] = $given ? max( 0, Scalar::int( $value ) ) : 0;
			} elseif ( 'synced_at' === $column ) {
				$insert_values[] = $given ? Scalar::str( $value ) : $now;
			} else {
				$insert_values[] = $given ? Scalar::str( $value ) : '';
			}

			if ( $given && 'translation_id' !== $column ) {
				$updates[]       = [ $column, $holder ];
				$update_values[] = '%d' === $holder ? max( 0, Scalar::int( $value ) ) : Scalar::str( $value );
			}
		}

		// Part of the `translation` key, never updated.
		$insert_columns[] = 'object_type';
		$insert_holders[] = '%s';
		$insert_values[]  = self::OBJECT_TYPE;

		// intent_at is assigned last: with $only_without_intent every other column tests its old value.
		if ( null !== $intent_at ) {
			$updates[] = [ 'intent_at', $intent_at[0] ];

			if ( null !== $intent_at[1] ) {
				$update_values[] = $intent_at[1];
			}
		}

		$assignments = [];

		foreach ( $updates as [ $column, $holder ] ) {
			$assignments[] = $column . ' = ' . ( $only_without_intent ? 'IF( intent_at IS NULL, ' . $holder . ', ' . $column . ' )' : $holder );
		}

		// An existing row with nothing to update still has a valid clause.
		$assignments[] = 'id = id';

		$guard  = self::guarded();
		$update = ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $assignments );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Column names and placeholders come from the constant above; the marker is a checked literal.
		if ( '' === $guard ) {
			$sql = $this->wpdb->prepare( 'INSERT INTO %i (' . implode( ', ', $insert_columns ) . ') VALUES (' . implode( ', ', $insert_holders ) . ')' . $update, array_merge( [ self::state_table() ], $insert_values, $update_values ) );
		} else {
			// In the begin_writes() transaction the row is a SELECT whose WHERE carries the marker, between the two prepared parts.
			$head = $this->wpdb->prepare( 'INSERT INTO %i (' . implode( ', ', $insert_columns ) . ') SELECT ' . implode( ', ', $insert_holders ) . ' FROM DUAL WHERE 1 = 1', array_merge( [ self::state_table() ], $insert_values ) );
			$tail = [] === $update_values ? $update : $this->wpdb->prepare( $update, $update_values );
			$sql  = null === $head || null === $tail ? null : $head . $guard . $tail;
		}

		$result = $this->run( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( false === $result ) {
			return 0;
		}

		$read = 'SELECT id' . ( $only_without_intent ? ', intent' : '' ) . ' FROM %i WHERE translation_id = %d AND object_type = %s' . ( self::locking_reads() ? ' FOR UPDATE' : '' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- The statement is built from fixed text above.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $read, self::state_table(), $translation_id, self::OBJECT_TYPE ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error || ! is_array( $row ) ) {
			return 0;
		}

		// A row that holds another writer's intent was left as it was.
		if ( $only_without_intent && Scalar::str( $row['intent'] ?? '' ) !== $intent ) {
			return 0;
		}

		return max( 0, Scalar::int( $row['id'] ?? 0 ) );
	}

	/**
	 * Store the stored post's hash in the open intent `$intent_id` by replacing only its INTENT_OPEN lead,
	 * so the statement carries the hash, not the intent. A gone, already marked or foreign intent matches no row.
	 *
	 * @param int    $translation_id Translation post id.
	 * @param string $intent_id      The writer's intent id.
	 * @param string $post_hash      sha256 hex of the stored post.
	 * @return bool Whether one row now holds the hash.
	 */
	public function mark_post_written( int $translation_id, string $intent_id, string $post_hash ): bool {
		return $this->replace_lead( $translation_id, $intent_id, self::INTENT_OPEN, $post_hash, '{"post_written":"' . $post_hash . '"' );
	}

	/**
	 * Store the hash core's UPDATE is about to store in the open intent, right before it (INTENT_OPEN_WRITING, once).
	 *
	 * @param int    $translation_id Translation post id.
	 * @param string $intent_id      The writer's intent id.
	 * @param string $post_hash      sha256 hex of the post being written.
	 * @return bool Whether one row now holds the hash.
	 */
	public function mark_post_writing( int $translation_id, string $intent_id, string $post_hash ): bool {
		return $this->replace_lead( $translation_id, $intent_id, self::INTENT_OPEN_WRITING, $post_hash, '{"post_written":null,"post_writing":"' . $post_hash . '"' );
	}

	/**
	 * Mark the open intent as being put back, before the put-back writes anything (a leading `restoring` key;
	 * true: the intent's own `previous`). An intent marked already, or another writer's, matches no row.
	 *
	 * @param int    $translation_id Translation post id.
	 * @param string $intent_id      The intent id.
	 * @param string $restoring      The JSON value of the key.
	 * @param string $own_slug       The slug the write's own save left ('' none): a leading `own_slug` key.
	 * @return bool Whether one row is now marked.
	 */
	public function mark_restoring( int $translation_id, string $intent_id, string $restoring, string $own_slug = '' ): bool {
		$named = $this->intent_named( $intent_id );

		$slug = '' === $own_slug ? '' : wp_json_encode( $own_slug, JSON_UNESCAPED_SLASHES );

		if ( $translation_id <= 0 || null === $named || '' === $restoring || ! is_string( $slug ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run(
			$this->wpdb->prepare(
				'UPDATE %i SET intent = CONCAT( %s, SUBSTRING( intent, 2 ) ) WHERE translation_id = %d AND object_type = %s AND intent LIKE %s AND intent LIKE %s',
				self::state_table(),
				'{"restoring":' . $restoring . ',' . ( '' === $slug ? '' : '"own_slug":' . $slug . ',' ),
				$translation_id,
				self::OBJECT_TYPE,
				$this->wpdb->esc_like( '{"post_written":' ) . '%',
				$named
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return 1 === $result;
	}

	/**
	 * Replace the lead `$lead` of the open intent `$intent_id` with `$with`.
	 *
	 * @param int    $translation_id Translation post id.
	 * @param string $intent_id      The intent id.
	 * @param string $lead           The lead the intent must start with.
	 * @param string $post_hash      The hash `$with` carries (a state hash, StateHash::is_valid()).
	 * @param string $with           The new lead.
	 * @return bool Whether one row changed.
	 */
	private function replace_lead( int $translation_id, string $intent_id, string $lead, string $post_hash, string $with ): bool {
		$named = $this->intent_named( $intent_id );

		if ( $translation_id <= 0 || null === $named || ! StateHash::is_valid( $post_hash ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run(
			$this->wpdb->prepare(
				'UPDATE %i SET intent = CONCAT( %s, SUBSTRING( intent, %d ) ) WHERE translation_id = %d AND object_type = %s AND intent LIKE %s AND intent LIKE %s',
				self::state_table(),
				$with,
				strlen( $lead ) + 1,
				$translation_id,
				self::OBJECT_TYPE,
				$this->wpdb->esc_like( $lead ) . ',%',
				$named
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return 1 === $result;
	}

	/**
	 * The LIKE pattern matching the intent with this id as its JSON stores it, null for no id.
	 *
	 * @param string $intent_id Intent id.
	 * @return string|null
	 */
	private function intent_named( string $intent_id ): ?string {
		$json = '' === $intent_id ? false : wp_json_encode( $intent_id, JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? '%' . $this->wpdb->esc_like( '"id":' . $json ) . '%' : null;
	}

	/**
	 * How many intents are open, set since `$stale_before` and older (one read on the `intent_at` key). Every object type:
	 * it guards wipe(), which clears every type.
	 *
	 * @param string $stale_before GMT datetime.
	 * @return array{fresh: int, stale: int}|null Null when the read failed.
	 */
	public function open_intents( string $stale_before ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT COUNT(*) AS open_n, COALESCE( SUM( intent_at < %s ), 0 ) AS stale_n FROM %i WHERE intent_at IS NOT NULL',
				$stale_before,
				self::state_table()
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		$stale = Scalar::int( $row['stale_n'] ?? 0 );

		return [
			'fresh' => max( 0, Scalar::int( $row['open_n'] ?? 0 ) - $stale ),
			'stale' => $stale,
		];
	}

	/**
	 * Clear the intent of a translation's state row. With `$intent_id` only that intent is cleared, so one
	 * another writer began since stands.
	 *
	 * @param int    $translation_id Translation post id.
	 * @param string $intent_id      The intent to clear, '' for any.
	 * @return bool Whether the statement ran.
	 */
	public function clear_intent( int $translation_id, string $intent_id = '' ): bool {
		if ( $translation_id <= 0 ) {
			return false;
		}

		if ( '' === $intent_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->run( $this->wpdb->prepare( "UPDATE %i SET intent = '', intent_at = NULL WHERE translation_id = %d AND object_type = %s", self::state_table(), $translation_id, self::OBJECT_TYPE ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

			return false !== $result;
		}

		$named = $this->intent_named( $intent_id );

		if ( null === $named ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run( $this->wpdb->prepare( "UPDATE %i SET intent = '', intent_at = NULL WHERE translation_id = %d AND object_type = %s AND intent LIKE %s", self::state_table(), $translation_id, self::OBJECT_TYPE, $named ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return false !== $result;
	}

	/**
	 * How many intents are open, with a locking read in the caller's transaction: an intent set after it waits. Every
	 * object type, as open_intents().
	 *
	 * @return int|null Null when the read failed.
	 */
	public function open_intents_for_update(): ?int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$open = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE intent_at IS NOT NULL' . ( self::locking_reads() ? ' FOR UPDATE' : '' ), self::state_table() ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return null === $open ? null : Scalar::int( $open );
	}

	/**
	 * Delete the state row of a translation.
	 *
	 * @param int $translation_id Translation post id.
	 * @return bool
	 */
	public function delete_state( int $translation_id ): bool {
		if ( $translation_id <= 0 ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run( $this->wpdb->prepare( 'DELETE FROM %i WHERE translation_id = %d AND object_type = %s', self::state_table(), $translation_id, self::OBJECT_TYPE ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return false !== $result;
	}

	/**
	 * Delete the rows of a post being deleted: its own state row, the state rows naming it as source, then its bases that
	 * no state row of any type names any more. A failed read deletes nothing.
	 *
	 * Other bases those rows referenced are touched so they stay for the retention period an undo may need.
	 * Another translation's row holding an intent stays for the reconciler, and so does its base; the DELETEs re-check.
	 *
	 * @param int $post_id Post id.
	 * @return int Rows deleted.
	 */
	public function forget_post( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, base_id, prev_base_id FROM %i WHERE ( translation_id = %d AND object_type = %s ) OR ( source_id = %d AND object_type = %s AND intent_at IS NULL )',
				self::state_table(),
				$post_id,
				self::OBJECT_TYPE,
				$post_id,
				self::OBJECT_TYPE
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) {
			return 0;
		}

		$ids     = [];
		$bases   = [];
		$deleted = 0;

		foreach ( $rows as $row ) {
			$ids[]   = Scalar::int( $row['id'] ?? 0 );
			$bases[] = Scalar::int( $row['base_id'] ?? 0 );
			$bases[] = Scalar::int( $row['prev_base_id'] ?? 0 );
		}

		$ids = array_values( array_filter( array_map( [ Scalar::class, 'int' ], $ids ), static fn ( int $id ): bool => $id > 0 ) );

		if ( [] !== $ids ) {
			// The DELETE repeats the read's condition: a row given an intent or another source since the read stays.
			$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
			$result = $this->run( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$in}) AND object_type = %s AND ( translation_id = %d OR ( source_id = %d AND intent_at IS NULL ) )", array_merge( [ self::state_table() ], $ids, [ self::OBJECT_TYPE, $post_id, $post_id ] ) ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

			$deleted = false === $result ? 0 : (int) $result;
		}

		$base  = self::base_table();
		$state = self::state_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE source_id = %d AND object_type = %s
				 AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.base_id = %i.id )
				 AND NOT EXISTS ( SELECT 1 FROM %i s2 WHERE s2.prev_base_id = %i.id )',
				$base,
				$post_id,
				self::OBJECT_TYPE,
				$state,
				$base,
				$state,
				$base
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		// The post's unreferenced bases are gone, so the touch keeps only the others.
		$this->touch_bases( $bases );

		return $deleted + ( false === $result ? 0 : (int) $result );
	}

	/**
	 * Translations whose state holds an intent older than a time, oldest first, after the given position.
	 * Ordered by (intent_at, id), the order of KEY intent_at, so a page reads no more rows than it returns.
	 *
	 * @param string $before   UTC datetime.
	 * @param int    $limit    Maximum rows.
	 * @param string $after_at The intent time of the position ('' for the start).
	 * @param int    $after_id The state row id of the position.
	 * @return list<array{0: int, 1: string, 2: int}>|null Translation id, intent time, state row id; null when the read failed.
	 */
	public function stale_intents( string $before, int $limit, string $after_at = '', int $after_id = 0 ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$rows = '' === $after_at
			? $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT translation_id, intent_at, id FROM %i WHERE intent_at IS NOT NULL AND intent_at < %s AND object_type = %s ORDER BY intent_at, id LIMIT %d',
					self::state_table(),
					$before,
					self::OBJECT_TYPE,
					max( 1, $limit )
				),
				ARRAY_N
			)
			: $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT translation_id, intent_at, id FROM %i WHERE intent_at IS NOT NULL AND intent_at < %s AND ( intent_at > %s OR ( intent_at = %s AND id > %d ) ) AND object_type = %s ORDER BY intent_at, id LIMIT %d',
					self::state_table(),
					$before,
					$after_at,
					$after_at,
					$after_id,
					self::OBJECT_TYPE,
					max( 1, $limit )
				),
				ARRAY_N
			);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( (array) $rows as $row ) {
			$id = Scalar::int( $row[0] ?? 0 );

			if ( $id > 0 ) {
				$out[] = [ $id, Scalar::str( $row[1] ?? '' ), Scalar::int( $row[2] ?? 0 ) ];
			}
		}

		return $out;
	}

	/**
	 * Delete state rows with an id in (after, after + WINDOW] whose translation, source or language is gone. A row
	 * holding a write intent stays: the reconciler may still have to put its translation back.
	 *
	 * @param int $batch Maximum rows.
	 * @param int $after Window start (exclusive); moves to where the next call starts, 0 once past the last row.
	 * @return int Rows deleted, -1 when a read failed (the cursor stays).
	 */
	public function delete_orphan_states( int $batch, int &$after ): int {
		$batch = max( 1, $batch );
		$until = $after + self::WINDOW;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT s.id FROM %i s
				 LEFT JOIN %i t ON t.ID = s.translation_id
				 LEFT JOIN %i p ON p.ID = s.source_id
				 LEFT JOIN %i l ON l.id = s.language_id
				 WHERE s.id > %d AND s.id <= %d AND s.object_type = %s AND s.intent_at IS NULL AND ( t.ID IS NULL OR p.ID IS NULL OR l.id IS NULL )
				 ORDER BY s.id LIMIT %d',
				self::state_table(),
				$this->wpdb->posts,
				$this->wpdb->posts,
				Schema::table( 'languages' ),
				$after,
				$until,
				self::OBJECT_TYPE,
				$batch
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error || ! $this->next_window( self::state_table(), (array) $ids, $batch, $until, $after ) ) {
			return -1;
		}

		$ids = array_values( array_filter( array_map( [ Scalar::class, 'int' ], (array) $ids ), static fn ( int $id ): bool => $id > 0 ) );

		if ( [] === $ids ) {
			return 0;
		}

		// The DELETE repeats the read's condition: a row a writer gave an intent since the read stays.
		$state = self::state_table();
		$in    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$result = $this->run(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$in}) AND object_type = %s AND intent_at IS NULL
				 AND ( NOT EXISTS ( SELECT 1 FROM %i t WHERE t.ID = %i.translation_id )
				    OR NOT EXISTS ( SELECT 1 FROM %i p WHERE p.ID = %i.source_id )
				    OR NOT EXISTS ( SELECT 1 FROM %i l WHERE l.id = %i.language_id ) )",
				array_merge( [ $state ], $ids, [ self::OBJECT_TYPE, $this->wpdb->posts, $state, $this->wpdb->posts, $state, Schema::table( 'languages' ), $state ] )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete base rows with an id in (after, after + WINDOW] whose source post no longer exists; the DELETE re-checks the
	 * source and that no state row of any type names the base.
	 *
	 * @param int $batch Maximum rows.
	 * @param int $after Window start (exclusive); moves to where the next call starts, 0 once past the last row.
	 * @return int Rows deleted, -1 when a read failed (the cursor stays).
	 */
	public function delete_bases_of_gone_sources( int $batch, int &$after ): int {
		$batch = max( 1, $batch );
		$until = $after + self::WINDOW;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT b.id FROM %i b LEFT JOIN %i p ON p.ID = b.source_id WHERE b.id > %d AND b.id <= %d AND b.object_type = %s AND p.ID IS NULL ORDER BY b.id LIMIT %d',
				self::base_table(),
				$this->wpdb->posts,
				$after,
				$until,
				self::OBJECT_TYPE,
				$batch
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error || ! $this->next_window( self::base_table(), (array) $ids, $batch, $until, $after ) ) {
			return -1;
		}

		$ids = array_values( array_filter( array_map( [ Scalar::class, 'int' ], (array) $ids ), static fn ( int $id ): bool => $id > 0 ) );

		if ( [] === $ids ) {
			return 0;
		}

		$base  = self::base_table();
		$state = self::state_table();
		$in    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$result = $this->run(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$in}) AND object_type = %s
				 AND NOT EXISTS ( SELECT 1 FROM %i p WHERE p.ID = %i.source_id )
				 AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.base_id = %i.id )
				 AND NOT EXISTS ( SELECT 1 FROM %i s2 WHERE s2.prev_base_id = %i.id )",
				array_merge( [ $base ], $ids, [ self::OBJECT_TYPE, $this->wpdb->posts, $base, $state, $base, $state, $base ] )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Move a window cursor: past the last id read when the batch was full (the window has more), else to just
	 * before the next row after the window (gaps are skipped), 0 when none follows. Any object type: it only places the cursor.
	 *
	 * @param string       $table Table name.
	 * @param array<mixed> $ids   Ids the window read returned.
	 * @param int          $batch The read's LIMIT.
	 * @param int          $until The window end (inclusive).
	 * @param int          $after The cursor.
	 * @return bool False when the read failed (the cursor stays).
	 */
	private function next_window( string $table, array $ids, int $batch, int $until, int &$after ): bool {
		// The ids are in order: the last is the highest.
		if ( count( $ids ) >= $batch ) {
			$after = Scalar::int( end( $ids ) );

			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$next = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MIN(id) FROM %i WHERE id > %d', $table, $until ) );

		if ( '' !== $this->wpdb->last_error ) {
			return false;
		}

		$after = max( 0, Scalar::int( $next ) - 1 );

		return true;
	}

	/**
	 * Retention prune candidates in (last_used_at, id) order after a cursor: bases unused since a time. Read on
	 * KEY last_used_at alone (covering, type included), so a statement reads $batch post entries; delete_bases() checks the references.
	 *
	 * @param string                          $cutoff UTC datetime.
	 * @param int                             $batch  Maximum rows.
	 * @param array{at: string, id: int}|null $after  The cursor; moves to the last row read, null once the pass read the last one.
	 * @return list<int>|null Base ids, null when the read failed (the cursor stays).
	 */
	public function old_bases( string $cutoff, int $batch, ?array &$after ): ?array {
		$batch = max( 1, $batch );
		$range = '';
		$args  = [ self::base_table(), $cutoff, self::OBJECT_TYPE ];

		// The plain `>=` lets SQLite read the key as a range.
		if ( null !== $after ) {
			$range = ' AND last_used_at >= %s AND ( last_used_at > %s OR ( last_used_at = %s AND id > %d ) )';
			array_push( $args, $after['at'], $after['at'], $after['at'], $after['id'] );
		}

		$args[] = $batch;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $range is placeholders only.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT id, last_used_at FROM %i WHERE last_used_at < %s AND object_type = %s{$range} ORDER BY last_used_at, id LIMIT %d", $args ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( '' !== $this->wpdb->last_error ) {
			return null;
		}

		$ids  = [];
		$last = null;

		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			$id  = Scalar::int( $row['id'] ?? 0 );

			if ( $id > 0 ) {
				$ids[] = $id;
				$last  = [
					'at' => Scalar::str( $row['last_used_at'] ?? '' ),
					'id' => $id,
				];
			}
		}

		$after = count( $ids ) >= $batch ? $last : null;

		return $ids;
	}

	/**
	 * Continue collecting the bases pending post payloads name, after `$after`, in pages of PENDING_PAGE
	 * (a payload over the page's byte share is read alone). `$after` and `$named` move row by row, so a pass
	 * stopped by time or a failed read resumes; ids only grow, so a row added meanwhile comes after the cursor.
	 *
	 * @param callable         $in_time Whether another statement may run.
	 * @param int              $after   The id the pass continues after; set to the last row read.
	 * @param array<int, true> $named   Base ids as keys; the bases read are added.
	 * @return bool Whether the pass read the last row.
	 */
	public function bases_named_by_pending( callable $in_time, int &$after, array &$named ): bool {
		$table = Schema::table( 'pending_changes' );
		$cap   = intdiv( self::PENDING_PAGE_BYTES, self::PENDING_PAGE );
		$bytes = \PerfLocale\Resync\PostReads::payload_bytes_sql();

		do {
			if ( ! $in_time() ) {
				return false;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->run(
				$this->wpdb->prepare(
					'SELECT id, CASE WHEN ' . $bytes . ' <= %d THEN payload END AS payload FROM %i FORCE INDEX (status_decided) WHERE status = %s AND decided_at IS NULL AND object_type = %s AND id > %d ORDER BY id LIMIT %d',
					$cap,
					$table,
					PendingChangeRepository::STATUS_PENDING,
					'post',
					$after,
					self::PENDING_PAGE
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

			if ( ! is_int( $result ) ) {
				return false;
			}

			$rows = (array) $this->wpdb->last_result;
			$read = count( $rows );

			foreach ( $rows as $row ) {
				$row     = (array) $row;
				$id      = Scalar::int( $row['id'] ?? 0 );
				$payload = $row['payload'] ?? null;

				if ( null === $payload ) {
					$payload = $this->pending_payload( $table, $id, $in_time );

					if ( null === $payload ) {
						return false;
					}
				}

				$base = PendingChangeRepository::decode( Scalar::str( $payload ) )['base_id'] ?? null;

				if ( is_int( $base ) && $base > 0 ) {
					$named[ $base ] = true;
				}

				$after = max( $after, $id );
			}
		} while ( $read >= self::PENDING_PAGE );

		return true;
	}

	/**
	 * The payload of one pending post row, read alone ('' when no longer pending).
	 *
	 * @param string   $table   The pending changes table.
	 * @param int      $id      Row id.
	 * @param callable $in_time Whether another statement may run.
	 * @return string|null Null when the time ran out or the read failed.
	 */
	private function pending_payload( string $table, int $id, callable $in_time ): ?string {
		if ( ! $in_time() ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run(
			$this->wpdb->prepare(
				'SELECT payload FROM %i WHERE id = %d AND status = %s AND object_type = %s',
				$table,
				$id,
				PendingChangeRepository::STATUS_PENDING,
				'post'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_int( $result ) ) {
			return null;
		}

		$row = ( (array) $this->wpdb->last_result )[0] ?? null;

		return null === $row ? '' : Scalar::str( ( (array) $row )['payload'] ?? '' );
	}

	/**
	 * Delete retention candidates by id unless a state row of any object type references them or they were used since the cutoff.
	 *
	 * @param array<int> $ids    Base ids.
	 * @param string     $cutoff The UTC datetime the candidates were read with.
	 * @return int Rows deleted.
	 */
	public function delete_bases( array $ids, string $cutoff ): int {
		$ids = array_values( array_filter( array_map( [ Scalar::class, 'int' ], $ids ), static fn ( int $id ): bool => $id > 0 ) );

		if ( [] === $ids ) {
			return 0;
		}

		$base = self::base_table();
		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$result = $this->run(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$in}) AND object_type = %s AND last_used_at < %s
				 AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.base_id = %i.id )
				 AND NOT EXISTS ( SELECT 1 FROM %i s2 WHERE s2.prev_base_id = %i.id )",
				array_merge( [ $base ], $ids, [ self::OBJECT_TYPE, $cutoff, self::state_table(), $base, self::state_table(), $base ] )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Empty both tables, every object type (a Replace import whose plan wipes the links).
	 *
	 * @param string $guard The import's connection-guard condition ('' outside one): on a new connection nothing matches.
	 * @return string '' when both statements ran, else the database error.
	 */
	public function wipe( string $guard = '' ): string {
		foreach ( [ self::state_table(), self::base_table() ] as $table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- The guard's condition is prepared by the guard.
			$result = $this->run( $this->wpdb->prepare( 'DELETE FROM %i', $table ) . ( '' === $guard ? '' : ' WHERE ' . $guard ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $result ) {
				$error = $this->wpdb->last_error;

				return '' === $error ? $table : $error;
			}
		}

		return '';
	}
}
