<?php
/**
 * Pending automation change repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for the `perflocale_pending_changes` table.
 *
 * A status change is a compare-and-set UPDATE that must affect exactly one row, so two requests cannot
 * both decide one change. Datetimes are UTC; the table is resolved per call so switch_to_blog() works.
 *
 * @phpstan-type ChangeRow array{id: int, area: string, object_type: string, object_id: int, source_id: int, language_id: int, origin: string, trigger_name: string, job_id: string, user_id: int, actor: string, decision: string, reason: string, status: string, payload: string, summary: string, base_hash: string, result_hash: string, result_status: string, result_modified: string, previous: string, previous_null: int, created_at: string, decided_at: string, decided_by: int}
 */
final class PendingChangeRepository {

	/** Status values. */
	public const STATUS_PENDING    = 'pending';
	public const STATUS_APPLIED    = 'applied';
	public const STATUS_REJECTED   = 'rejected';
	public const STATUS_SUPERSEDED = 'superseded';
	public const STATUS_REVERTED   = 'reverted';
	public const STATUS_REFUSED    = 'refused';

	/**
	 * Every status a row can hold.
	 *
	 * @var string[]
	 */
	public const STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_APPLIED,
		self::STATUS_REJECTED,
		self::STATUS_SUPERSEDED,
		self::STATUS_REVERTED,
		self::STATUS_REFUSED,
	];

	/**
	 * Decided statuses that record a person's refusal: a Reject, or an Undo of an applied change.
	 * As a string's newest decision it keeps the string out of the outdated runs while it still matches.
	 *
	 * @var string[]
	 */
	public const PERSON_DECLINED = [
		self::STATUS_REJECTED,
		self::STATUS_REVERTED,
	];

	/**
	 * Write-ahead intents of a string decision, held in `result_status` (unused by string rows otherwise)
	 * while the target write runs; '' when none. Never exported or imported.
	 */
	public const INTENT_APPLYING  = 'applying';
	public const INTENT_REVERTING = 'reverting';

	/**
	 * Every write-ahead intent.
	 *
	 * @var string[]
	 */
	public const INTENTS = [
		self::INTENT_APPLYING,
		self::INTENT_REVERTING,
	];

	/**
	 * Columns written on insert, in order.
	 *
	 * @var string[]
	 */
	private const INSERT_COLUMNS = [
		'area',
		'object_type',
		'object_id',
		'source_id',
		'language_id',
		'origin',
		'trigger_name',
		'job_id',
		'user_id',
		'actor',
		'decision',
		'reason',
		'status',
		'payload',
		'summary',
		'base_hash',
		'result_hash',
		'result_status',
		'result_modified',
		'previous',
		'created_at',
		'decided_at',
		'decided_by',
	];

	/** Rows per update when pending rows of a type or a language are closed. */
	private const CLOSE_BATCH = 500;

	/** Pending rows per window of a bulk close's id reads. */
	private const CLOSE_WINDOW = 2000;

	/**
	 * The pending-id read of the closes. ORDER BY decided_at, id: SQLite reads status_decided (else every string row on
	 * string_intent); an id window, never a LIMIT: with one MySQL walked PRIMARY instead.
	 */
	private const PENDING_IDS = 'SELECT id FROM %i WHERE status = %s AND decided_at IS NULL AND ';

	/**
	 * WordPress's database object.
	 *
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

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
	 * A database row as a typed change row.
	 *
	 * @param array<mixed> $row Row (ARRAY_A).
	 * @return array<string, int|string>
	 * @phpstan-return ChangeRow
	 */
	private static function shape( array $row ): array {
		$int = static fn ( string $key ): int => \PerfLocale\Util\Scalar::int( $row[ $key ] ?? 0 );
		$str = static fn ( string $key ): string => \PerfLocale\Util\Scalar::str( $row[ $key ] ?? '' );

		return [
			'id'              => $int( 'id' ),
			'area'            => $str( 'area' ),
			'object_type'     => $str( 'object_type' ),
			'object_id'       => $int( 'object_id' ),
			'source_id'       => $int( 'source_id' ),
			'language_id'     => $int( 'language_id' ),
			'origin'          => $str( 'origin' ),
			'trigger_name'    => $str( 'trigger_name' ),
			'job_id'          => $str( 'job_id' ),
			'user_id'         => $int( 'user_id' ),
			'actor'           => $str( 'actor' ),
			'decision'        => $str( 'decision' ),
			'reason'          => $str( 'reason' ),
			'status'          => $str( 'status' ),
			'payload'         => $str( 'payload' ),
			'summary'         => $str( 'summary' ),
			'base_hash'       => $str( 'base_hash' ),
			'result_hash'     => $str( 'result_hash' ),
			'result_status'   => $str( 'result_status' ),
			'result_modified' => $str( 'result_modified' ),
			'previous'        => $str( 'previous' ),
			'previous_null'   => array_key_exists( 'previous_null', $row ) ? $int( 'previous_null' ) : ( null === ( $row['previous'] ?? null ) ? 1 : 0 ),
			'created_at'      => $str( 'created_at' ),
			'decided_at'      => $str( 'decided_at' ),
			'decided_by'      => $int( 'decided_by' ),
		];
	}

	/**
	 * Typed rows from a get_results( ARRAY_A ) result.
	 *
	 * @param mixed $rows Result.
	 * @return list<array<string, int|string>>
	 * @phpstan-return list<ChangeRow>
	 */
	private static function shape_all( mixed $rows ): array {
		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::shape( $row );
			}
		}

		return $out;
	}

	/**
	 * Positive ints from a get_col() result.
	 *
	 * @param mixed $ids Result.
	 * @return list<int>
	 */
	private static function ids( mixed $ids ): array {
		$out = [];

		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			$id = \PerfLocale\Util\Scalar::int( $id );

			if ( $id > 0 ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The table name for the current blog.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'pending_changes' );
	}

	/**
	 * The current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Fill a row with the defaults of every insert column.
	 *
	 * @param array<string, mixed> $row Row values.
	 * @return array<string, int|string|null>
	 */
	private function normalize( array $row ): array {
		$now = self::now();
		$int = static fn ( string $key ): int => max( 0, \PerfLocale\Util\Scalar::int( $row[ $key ] ?? 0 ) );
		$str = static fn ( string $key, string $fallback = '' ): string => array_key_exists( $key, $row ) ? \PerfLocale\Util\Scalar::str( $row[ $key ] ) : $fallback;
		$opt = static fn ( string $key ): ?string => isset( $row[ $key ] ) && is_string( $row[ $key ] ) && '' !== $row[ $key ] ? $row[ $key ] : null;
		$out = [
			'area'            => $str( 'area' ),
			'object_type'     => $str( 'object_type' ),
			'object_id'       => $int( 'object_id' ),
			'source_id'       => $int( 'source_id' ),
			'language_id'     => $int( 'language_id' ),
			'origin'          => $str( 'origin', 'machine' ),
			'trigger_name'    => mb_substr( $str( 'trigger_name' ), 0, 32 ), // The column's width: a job's trigger comes from its args.
			'job_id'          => $str( 'job_id' ),
			'user_id'         => $int( 'user_id' ),
			'actor'           => $str( 'actor', 'user' ),
			'decision'        => $str( 'decision' ),
			'reason'          => $str( 'reason' ),
			'status'          => $str( 'status', self::STATUS_PENDING ),
			'payload'         => self::encode( $row['payload'] ?? '' ),
			'summary'         => self::encode( $row['summary'] ?? [] ),
			'base_hash'       => $str( 'base_hash' ),
			'result_hash'     => $str( 'result_hash' ),
			'result_status'   => $str( 'result_status' ),
			'result_modified' => $opt( 'result_modified' ),
			'previous'        => array_key_exists( 'previous', $row ) && null !== $row['previous'] ? self::encode( $row['previous'] ) : null,
			'created_at'      => $str( 'created_at', $now ),
			'decided_at'      => $opt( 'decided_at' ),
			'decided_by'      => $int( 'decided_by' ),
		];

		// A row that is not pending has been decided and carries the time; a pending one never does (pending reads
		// filter on `decided_at IS NULL`).
		if ( self::STATUS_PENDING === $out['status'] ) {
			$out['decided_at'] = null;
		} elseif ( null === $out['decided_at'] ) {
			$out['decided_at'] = $now;
		}

		return $out;
	}

	/**
	 * Encode a payload / summary / previous value as JSON ('' stays '').
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function encode( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		// Slashes unescaped so a site move's search-replace finds URLs; non-ASCII stays \uXXXX (utf8mb3 tables).
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Decode a JSON column into an array ([] for '' or invalid JSON).
	 *
	 * @param mixed $value Column value.
	 * @return array<mixed>
	 */
	public static function decode( mixed $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Insert one row.
	 *
	 * @param array<string, mixed> $row Row values (see INSERT_COLUMNS).
	 * @return int The new id, or 0 on failure.
	 */
	public function insert( array $row ): int {
		return 1 === $this->insert_many( [ $row ] ) ? (int) $this->wpdb->insert_id : 0;
	}

	/**
	 * Insert a pending row and supersede the target's other pending rows, both or neither: one transaction (nested in
	 * an open one), tied to its connection on MySQL/MariaDB. The caller holds the target's lock.
	 *
	 * @param array<string, mixed> $row     Pending row (object_type, object_id, language_id and the rest).
	 * @param callable|null        $check   fn(): bool, asked first inside the transaction; false writes nothing.
	 * @param bool                 $refused Set when $check refused.
	 * @return int The new id, 0 when nothing was written.
	 */
	public function insert_superseding( array $row, ?callable $check = null, bool &$refused = false ): int {
		$refused = false;
		$own     = ! TranslationGroupWrites::in_transaction();
		$guard   = $own && ConnectionGuard::applies() ? new ConnectionGuard( 'pending_hold' ) : null;

		// A database that refuses the marker (a db.php drop-in without user variables) runs the unit unmarked.
		if ( null !== $guard && ! $guard->begin() ) {
			$guard = null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		if ( $own && false === $this->run( 'START TRANSACTION' ) ) {
			$guard?->end();

			return 0;
		}

		$refused = null !== $check && ! $check();
		$marker  = null === $guard ? '' : ' AND ' . $guard->condition();
		$id      = ! $refused && 1 === $this->insert_many( [ $row ], $marker ) ? (int) $this->wpdb->insert_id : 0;
		$done    = $id > 0 && false !== $this->supersede_pending_for_target( \PerfLocale\Util\Scalar::str( $row['object_type'] ?? '' ), \PerfLocale\Util\Scalar::int( $row['object_id'] ?? 0 ), \PerfLocale\Util\Scalar::int( $row['language_id'] ?? 0 ), $id, $marker );

		if ( ! $own ) {
			// Nested: the caller's transaction ends it; a row its supersede did not follow is taken back.
			if ( ! $done && $id > 0 ) {
				$this->run( $this->wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $this->table(), $id ) );
			}

			return $done ? $id : 0;
		}

		$done = $done && ( null === $guard || $guard->kept() ) && false !== $this->run( 'COMMIT' );

		// After the COMMIT too: one resent on a new connection committed nothing (the server rolled the unit back).
		$done = $done && ( null === $guard || $guard->kept() );
		$guard?->end();

		if ( $done ) {
			return $id;
		}

		$this->run( 'ROLLBACK' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return 0;
	}

	/**
	 * Insert several rows in one statement.
	 *
	 * @param array<int, array<string, mixed>> $rows   Rows.
	 * @param string                           $marker A ConnectionGuard condition (' AND ...') for one row: sent as INSERT ... SELECT ... WHERE it.
	 * @return int Rows inserted.
	 */
	public function insert_many( array $rows, string $marker = '' ): int {
		if ( [] === $rows ) {
			return 0;
		}

		$placeholders = [];
		$tuples       = [];
		$values       = [ $this->table() ];

		foreach ( $rows as $row ) {
			$norm  = $this->normalize( $row );
			$tuple = [];

			foreach ( self::INSERT_COLUMNS as $column ) {
				$value = $norm[ $column ];

				if ( null === $value ) {
					$tuple[] = 'NULL';
					continue;
				}

				$tuple[]  = is_int( $value ) ? '%d' : '%s';
				$values[] = $value;
			}

			$tuples[]       = implode( ', ', $tuple );
			$placeholders[] = '(' . implode( ', ', $tuple ) . ')';
		}

		$guarded = '' !== $marker && 1 === count( $tuples );
		$sql     = $guarded
			? 'INSERT INTO %i (' . implode( ', ', self::INSERT_COLUMNS ) . ') SELECT ' . $tuples[0] . ' FROM DUAL WHERE 1 = 1'
			: 'INSERT INTO %i (' . implode( ', ', self::INSERT_COLUMNS ) . ') VALUES ' . implode( ', ', $placeholders );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Placeholders are built per column above; replacements arrive as one array; the marker is a checked literal.
		$prepared = $this->wpdb->prepare( $sql, $values );
		$result   = $this->run( null === $prepared || ! $guarded ? $prepared : $prepared . $marker );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, int|string>|null
	 * @phpstan-return ChangeRow|null
	 */
	public function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * The target's newest applied row, narrow: never reads `previous` or `payload`.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $language_id Language id.
	 * @return array{id: int, reason: string, result_hash: string, summary: string}|null
	 */
	public function newest_applied_narrow( string $object_type, int $object_id, int $language_id ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT id, reason, result_hash, summary FROM %i WHERE object_type = %s AND object_id = %d AND language_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
				$this->table(),
				$object_type,
				$object_id,
				$language_id,
				self::STATUS_APPLIED
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'id'          => \PerfLocale\Util\Scalar::int( $row['id'] ?? 0 ),
			'reason'      => \PerfLocale\Util\Scalar::str( $row['reason'] ?? '' ),
			'result_hash' => \PerfLocale\Util\Scalar::str( $row['result_hash'] ?? '' ),
			'summary'     => \PerfLocale\Util\Scalar::str( $row['summary'] ?? '' ),
		];
	}

	/**
	 * The target's pending row, narrow: the held-same check's columns, never `payload` or `previous`.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $language_id Language id.
	 * @return array{id: int, status: string, reason: string, base_hash: string, summary: string}|null
	 */
	public function pending_narrow_for_target( string $object_type, int $object_id, int $language_id ): ?array {
		return $this->narrow_row( true, $object_type, $object_id, $language_id );
	}

	/**
	 * The target's newest non-pending row, narrow, so a proposal a person rejected or undid is recognised.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $language_id Language id.
	 * @return array{id: int, status: string, reason: string, base_hash: string, summary: string}|null
	 */
	public function newest_decided_narrow( string $object_type, int $object_id, int $language_id ): ?array {
		return $this->narrow_row( false, $object_type, $object_id, $language_id );
	}

	/**
	 * The newest narrow row of a target that is pending, or that is not.
	 *
	 * @param bool   $pending     Whether to read the pending row or the newest decided one.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $language_id Language id.
	 * @return array{id: int, status: string, reason: string, base_hash: string, summary: string}|null
	 */
	private function narrow_row( bool $pending, string $object_type, int $object_id, int $language_id ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		if ( $pending ) {
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare( 'SELECT id, status, reason, base_hash, summary FROM %i WHERE object_type = %s AND object_id = %d AND language_id = %d AND status = %s ORDER BY id DESC LIMIT 1', $this->table(), $object_type, $object_id, $language_id, self::STATUS_PENDING ),
				ARRAY_A
			);
		} else {
			// MAX(id) first: with ORDER BY id DESC LIMIT 1 a server may walk PRIMARY back from the table's end.
			$id  = \PerfLocale\Util\Scalar::int(
				$this->wpdb->get_var(
					$this->wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE object_type = %s AND object_id = %d AND language_id = %d AND status <> %s', $this->table(), $object_type, $object_id, $language_id, self::STATUS_PENDING )
				)
			);
			$row = $id > 0 ? $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT id, status, reason, base_hash, summary FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ) : null;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'id'        => \PerfLocale\Util\Scalar::int( $row['id'] ?? 0 ),
			'status'    => \PerfLocale\Util\Scalar::str( $row['status'] ?? '' ),
			'reason'    => \PerfLocale\Util\Scalar::str( $row['reason'] ?? '' ),
			'base_hash' => \PerfLocale\Util\Scalar::str( $row['base_hash'] ?? '' ),
			'summary'   => \PerfLocale\Util\Scalar::str( $row['summary'] ?? '' ),
		];
	}

	/**
	 * Mark every pending row of a target superseded, except one id.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $language_id Language id.
	 * @param int    $except_id   Row to keep pending (0 = none).
	 * @param string $marker      A ConnectionGuard condition (' AND ...') the UPDATE carries; '' for none.
	 * @return int|false Rows superseded, false when the UPDATE failed.
	 */
	public function supersede_pending_for_target( string $object_type, int $object_id, int $language_id, int $except_id = 0, string $marker = '' ): int|false {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- The marker is a checked literal.
		$prepared = $this->wpdb->prepare(
			"UPDATE %i SET status = %s, payload = '', decided_at = %s, decided_by = 0
			 WHERE object_type = %s AND object_id = %d AND language_id = %d AND status = %s AND id <> %d",
			$this->table(),
			self::STATUS_SUPERSEDED,
			self::now(),
			$object_type,
			$object_id,
			$language_id,
			self::STATUS_PENDING,
			$except_id
		);
		$result   = $this->run( null === $prepared ? null : $prepared . $marker );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? false : (int) $result;
	}

	/**
	 * The pending row of each of several objects of one type, narrow, in one statement on the `target` index.
	 *
	 * @param string            $object_type Object type.
	 * @param array<int, mixed> $object_ids  Object ids.
	 * @return array<int, array{id: int, object_id: int, language_id: int, source_id: int, reason: string}> Keyed by object id.
	 */
	public function pending_for_objects( string $object_type, array $object_ids ): array {
		return PendingChangeReads::for_objects( $object_type, $object_ids );
	}

	/**
	 * Compare-and-set a row's status and write other columns in the same UPDATE.
	 *
	 * Only while the row still holds `$from`, so a concurrent decision is never overwritten.
	 *
	 * @param int                  $id     Row id.
	 * @param string               $from   Status the row must hold.
	 * @param string               $to     New status.
	 * @param array<string, mixed> $fields Other columns to write (payload, reason, result_*, previous, decided_by, decision, base_hash, summary).
	 * @return bool True when exactly one row changed.
	 */
	public function transition( int $id, string $from, string $to, array $fields = [] ): bool {
		$allowed = [ 'payload', 'reason', 'result_hash', 'result_status', 'result_modified', 'previous', 'decided_by', 'decision', 'base_hash', 'summary' ];
		$sets    = [ 'status = %s', 'decided_at = %s' ];
		$values  = [ $this->table(), $to, self::now() ];

		foreach ( $allowed as $column ) {
			if ( ! array_key_exists( $column, $fields ) ) {
				continue;
			}

			$value = $fields[ $column ];

			if ( null === $value ) {
				$sets[] = $column . ' = NULL';
				continue;
			}

			if ( 'payload' === $column || 'previous' === $column || 'summary' === $column ) {
				$value = self::encode( $value );
			}

			$sets[]   = $column . ( is_int( $value ) ? ' = %d' : ' = %s' );
			$values[] = is_int( $value ) ? $value : (string) ( is_scalar( $value ) ? $value : '' );
		}

		$values[] = $id;
		$values[] = $from;

		$sql = 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND status = %s';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Column names come from the allow-list above.
		$result = $this->run( $this->wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return 1 === $result;
	}

	/**
	 * Put a write-ahead intent on a row that holds none, writing `$previous` in the same statement.
	 * An approver goes in `decided_by`, the one column the privacy eraser clears, never in the intent's JSON.
	 *
	 * @param int                       $id         Row id.
	 * @param string                    $status     Status the row must hold.
	 * @param string                    $intent     INTENT_APPLYING or INTENT_REVERTING.
	 * @param array<string, mixed>|null $previous   The value to store in `previous`, null to keep it.
	 * @param int                       $decided_by The approver to record with the intent; 0 leaves the column.
	 * @return bool True when exactly one row changed.
	 */
	public function mark_intent( int $id, string $status, string $intent, ?array $previous = null, int $decided_by = 0 ): bool {
		$set    = null === $previous ? [ $intent ] : [ $intent, self::encode( $previous ) ];
		$values = array_merge( [ $this->table() ], $set, $decided_by > 0 ? [ $decided_by ] : [], [ $id, $status ] );

		if ( null === $previous ) {
			$sql = $decided_by > 0
				? "UPDATE %i SET result_status = %s, decided_by = %d WHERE id = %d AND status = %s AND result_status = ''"
				: "UPDATE %i SET result_status = %s WHERE id = %d AND status = %s AND result_status = ''";
		} else {
			$sql = $decided_by > 0
				? "UPDATE %i SET result_status = %s, previous = %s, decided_by = %d WHERE id = %d AND status = %s AND result_status = ''"
				: "UPDATE %i SET result_status = %s, previous = %s WHERE id = %d AND status = %s AND result_status = ''";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- One of four constant statements.
		$result = $this->run( $this->wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return 1 === $result;
	}

	/**
	 * Take a write-ahead intent off a row that stays in its status; a pending row loses the approver mark_intent() wrote.
	 *
	 * @param int    $id            Row id.
	 * @param string $status        Status the row must hold.
	 * @param string $intent        The intent the row must hold.
	 * @param bool   $drop_previous Whether `previous` is cleared too.
	 * @return bool True when exactly one row changed.
	 */
	public function clear_intent( int $id, string $status, string $intent, bool $drop_previous ): bool {
		$by  = self::STATUS_PENDING === $status ? ', decided_by = 0' : '';
		$sql = $drop_previous
			? "UPDATE %i SET result_status = '', previous = NULL{$by} WHERE id = %d AND status = %s AND result_status = %s"
			: "UPDATE %i SET result_status = ''{$by} WHERE id = %d AND status = %s AND result_status = %s";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- One of two constant statements.
		$result = $this->run( $this->wpdb->prepare( $sql, $this->table(), $id, $status, $intent ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return 1 === $result;
	}

	/**
	 * The pending row of a target that holds the applying intent.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $language_id Language id.
	 * @return array<string, int|string>|false|null Null when there is none, false when the read failed.
	 * @phpstan-return ChangeRow|false|null
	 */
	public function pending_intent_for_target( string $object_type, int $object_id, int $language_id ): array|false|null {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d AND language_id = %d AND status = %s AND result_status = %s ORDER BY id DESC LIMIT 1',
				$this->table(),
				$object_type,
				$object_id,
				$language_id,
				self::STATUS_PENDING,
				self::INTENT_APPLYING
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error ) {
			return false;
		}

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * String rows with a write-ahead intent (pending + applying, applied + reverting), oldest first.
	 * The implied `result_status IN` lets SQLite read both columns of KEY string_intent, as MySQL does.
	 *
	 * @param int $limit Maximum rows.
	 * @return list<array<string, int|string>>|null Null when the read failed.
	 * @phpstan-return list<ChangeRow>|null
	 */
	public function string_intents( int $limit ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND result_status IN ( %s, %s ) AND ( ( status = %s AND result_status = %s ) OR ( status = %s AND result_status = %s ) ) ORDER BY id LIMIT %d',
				$this->table(),
				'string',
				self::INTENT_APPLYING,
				self::INTENT_REVERTING,
				self::STATUS_PENDING,
				self::INTENT_APPLYING,
				self::STATUS_APPLIED,
				self::INTENT_REVERTING,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error ) {
			return null;
		}

		return self::shape_all( $rows );
	}

	/**
	 * How many string rows hold a write-ahead intent, read with a locking read on MySQL (inside a transaction a
	 * later mark_intent() waits for its end). Null when the read failed.
	 *
	 * @return int|null
	 */
	public function string_intents_for_update(): ?int {
		$locking = ! ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- The locking clause is a fixed literal.
		$open = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_type = %s AND result_status IN ( %s, %s ) AND ( ( status = %s AND result_status = %s ) OR ( status = %s AND result_status = %s ) )' . ( $locking ? ' FOR UPDATE' : '' ),
				$this->table(),
				'string',
				self::INTENT_APPLYING,
				self::INTENT_REVERTING,
				self::STATUS_PENDING,
				self::INTENT_APPLYING,
				self::STATUS_APPLIED,
				self::INTENT_REVERTING
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return null === $open || '' !== $this->wpdb->last_error ? null : (int) $open;
	}

	/**
	 * Update the recorded modified time of a row that stays in its status.
	 *
	 * @param int    $id       Row id.
	 * @param string $status   Status the row must hold.
	 * @param string $modified The time (GMT, MySQL format).
	 * @return bool True when exactly one row changed.
	 */
	public function set_result_modified( int $id, string $status, string $modified ): bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run( $this->wpdb->prepare( 'UPDATE %i SET result_modified = %s WHERE id = %d AND status = %s', $this->table(), $modified, $id, $status ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return 1 === $result;
	}

	/**
	 * Update the reason of a row that stays in its status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status Status the row must hold.
	 * @param string $reason New reason.
	 * @return bool
	 */
	public function set_reason( int $id, string $status, string $reason ): bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run(
			$this->wpdb->prepare(
				'UPDATE %i SET reason = %s WHERE id = %d AND status = %s',
				$this->table(),
				$reason,
				$id,
				$status
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return false !== $result;
	}

	/**
	 * Number of pending rows on this blog.
	 *
	 * @return int|null Null when the read failed.
	 */
	public function count_pending(): ?int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$read = $this->run( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND decided_at IS NULL', $this->table(), self::STATUS_PENDING ) );

		return is_int( $read ) ? (int) $this->wpdb->get_var() : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The newest pending string changes with payload, for the Strings review list.
	 *
	 * @param int                $limit           Most rows.
	 * @param array<int, string> $exclude_domains Domains left out.
	 * @return list<array{id: int, object_id: int, language_id: int, payload: array<mixed>, reason: string, created_at: string}>
	 */
	public function pending_strings( int $limit, array $exclude_domains = [] ): array {
		return PendingChangeReads::strings( $limit, $exclude_domains );
	}

	/**
	 * Statuses whose rows go once they were decided before the cutoff.
	 *
	 * @var string[]
	 */
	public const PRUNED_STATUSES = [
		self::STATUS_REJECTED,
		self::STATUS_SUPERSEDED,
		self::STATUS_REVERTED,
		self::STATUS_REFUSED,
	];

	/**
	 * Delete up to $batch rows of one status decided before a cutoff; PERSON_DECLINED rows go through prune_declined().
	 *
	 * @param string $status One of PRUNED_STATUSES.
	 * @param string $cutoff UTC datetime; rows decided before it go.
	 * @param int    $batch  Maximum rows.
	 * @return int Rows deleted, -1 when a statement failed.
	 */
	public function prune_status( string $status, string $cutoff, int $batch ): int {
		if ( ! in_array( $status, self::PRUNED_STATUSES, true ) || in_array( $status, self::PERSON_DECLINED, true ) ) {
			return 0;
		}

		// Ids first (status_decided, in order), then a delete by id: some SQLite builds refuse DELETE ... ORDER BY LIMIT.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, decided_at FROM %i WHERE status = %s AND decided_at < %s ORDER BY decided_at, id LIMIT %d',
				$this->table(),
				$status,
				$cutoff,
				max( 1, $batch )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( $this->db_failed() ) {
			return -1;
		}

		$rows = is_array( $rows ) ? $rows : [];

		return $this->delete_decided( self::ids( array_column( $rows, 'id' ) ), $status, self::span( $rows ) );
	}

	/**
	 * Of the next $batch PERSON_DECLINED rows decided before a cutoff (in (decided_at, id) order after a cursor), delete
	 * all but a string's newest decision while its pair is flagged Outdated: that row keeps the string out of the outdated
	 * runs. Candidates are read first, so a long kept prefix costs one bounded read per call.
	 *
	 * @param string                          $status One of PERSON_DECLINED.
	 * @param string                          $cutoff UTC datetime; rows decided before it go.
	 * @param int                             $batch  Maximum rows read.
	 * @param array{at: string, id: int}|null $after  The cursor; moves to the last row read, null once the pass read the last one.
	 * @return int Rows deleted, -1 when a statement failed (the cursor stays).
	 */
	public function prune_declined( string $status, string $cutoff, int $batch, ?array &$after ): int {
		if ( ! in_array( $status, self::PERSON_DECLINED, true ) ) {
			return 0;
		}

		$batch            = max( 1, $batch );
		$table            = $this->table();
		$string           = \PerfLocale\Enum\ObjectType::String->value;
		[ $range, $args ] = self::window( '', $after, null );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $range and the IN list are placeholders only.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, decided_at FROM %i WHERE status = %s AND decided_at < %s{$range} ORDER BY decided_at, id LIMIT %d",
				array_merge( [ $table, $status, $cutoff ], $args, [ $batch ] )
			),
			ARRAY_A
		);

		if ( '' !== $this->wpdb->last_error ) {
			return -1;
		}

		$rows = is_array( $rows ) ? $rows : [];
		$ids  = self::ids( array_column( $rows, 'id' ) );
		$gone = [] === $ids ? [] : $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT c.id FROM %i c
				 WHERE c.id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')
				   AND NOT (
					c.object_type = %s
					AND EXISTS (
						SELECT 1 FROM %i l
						WHERE l.type = %s AND l.object_id = c.object_id AND l.language_id = c.language_id AND l.status = %s
					)
					AND NOT EXISTS (
						SELECT 1 FROM %i n
						WHERE n.object_type = c.object_type AND n.object_id = c.object_id AND n.language_id = c.language_id
						  AND n.status <> %s AND n.id > c.id
					)
				   )',
				array_merge(
					[ $table ],
					$ids,
					[ $string, Schema::table( 'translation_links' ), $string, \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value, $table, self::STATUS_PENDING ]
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( $this->db_failed() ) {
			return -1;
		}

		$last    = [] === $rows ? null : (array) $rows[ count( $rows ) - 1 ];
		$deleted = $this->delete_decided( self::ids( $gone ), $status, self::span( $rows ) );

		if ( $deleted >= 0 ) {
			$after = count( $rows ) >= $batch && null !== $last ? [
				'at' => \PerfLocale\Util\Scalar::str( $last['decided_at'] ?? '' ),
				'id' => \PerfLocale\Util\Scalar::int( $last['id'] ?? 0 ),
			] : null;
		}

		return $deleted;
	}

	/**
	 * Of the next $batch applied rows decided before a cutoff (in (decided_at, id) order after a cursor), delete those
	 * with a newer applied row for the same target: only the newest is the record of the target's last automatic write.
	 * Two reads, no subquery, so a statement reads at most $batch candidates and no plan can drive from the newer rows.
	 *
	 * @param string                          $cutoff UTC datetime; rows decided before it go.
	 * @param int                             $batch  Maximum rows read.
	 * @param array{at: string, id: int}|null $after  The cursor; moves to the last row read, null once the pass read the last one.
	 * @return int Rows deleted, -1 when a statement failed (the cursor stays).
	 */
	public function prune_superseded_applied( string $cutoff, int $batch, ?array &$after ): int {
		$batch            = max( 1, $batch );
		[ $range, $args ] = self::window( '', $after, null );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $range is placeholders only.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, object_type, object_id, language_id, decided_at FROM %i WHERE status = %s AND decided_at < %s{$range} ORDER BY decided_at, id LIMIT %d",
				array_merge( [ $this->table(), self::STATUS_APPLIED, $cutoff ], $args, [ $batch ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( '' !== $this->wpdb->last_error ) {
			return -1;
		}

		$rows    = is_array( $rows ) ? $rows : [];
		$span    = self::span( $rows );
		$targets = [];
		$last    = null;

		foreach ( $rows as $i => $row ) {
			$row          = (array) $row;
			$key          = \PerfLocale\Util\Scalar::str( $row['object_type'] ?? '' ) . '|' . \PerfLocale\Util\Scalar::int( $row['object_id'] ?? 0 ) . '|' . \PerfLocale\Util\Scalar::int( $row['language_id'] ?? 0 );
			$rows[ $i ]   = [ \PerfLocale\Util\Scalar::int( $row['id'] ?? 0 ), $key ];
			$targets[ $key ] = [ \PerfLocale\Util\Scalar::str( $row['object_type'] ?? '' ), \PerfLocale\Util\Scalar::int( $row['object_id'] ?? 0 ), \PerfLocale\Util\Scalar::int( $row['language_id'] ?? 0 ) ];
			$last         = [
				'at' => \PerfLocale\Util\Scalar::str( $row['decided_at'] ?? '' ),
				'id' => $rows[ $i ][0],
			];
		}

		$newest = [] === $targets ? [] : $this->newest_applied_of( array_values( $targets ) );

		if ( null === $newest ) {
			return -1;
		}

		$ids = [];

		foreach ( $rows as [ $id, $key ] ) {
			if ( $id > 0 && $id < ( $newest[ $key ] ?? 0 ) ) {
				$ids[] = $id;
			}
		}

		$deleted = $this->delete_decided( $ids, self::STATUS_APPLIED, $span );

		if ( $deleted >= 0 ) {
			$after = count( $rows ) >= $batch ? $last : null;
		}

		return $deleted;
	}

	/**
	 * The newest applied row id per target, in one statement of point ranges on the target key.
	 *
	 * @param list<array{0: string, 1: int, 2: int}> $targets Object type, object id, language id.
	 * @return array<string, int>|null `type|id|language` => newest id; null when the read failed.
	 */
	private function newest_applied_of( array $targets ): ?array {
		$terms = [];
		$args  = [ $this->table() ];

		// The status inside each term: with it outside, SQLite reads every applied row on status_decided.
		foreach ( $targets as [ $type, $id, $language ] ) {
			$terms[] = '( object_type = %s AND object_id = %d AND language_id = %d AND status = %s )';
			array_push( $args, $type, $id, $language, self::STATUS_APPLIED );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The terms are placeholders only.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT object_type, object_id, language_id, MAX(id) AS newest FROM %i WHERE ' . implode( ' OR ', $terms ) . ' GROUP BY object_type, object_id, language_id',
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( '' !== $this->wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$row = (array) $row;
			$out[ \PerfLocale\Util\Scalar::str( $row['object_type'] ?? '' ) . '|' . \PerfLocale\Util\Scalar::int( $row['object_id'] ?? 0 ) . '|' . \PerfLocale\Util\Scalar::int( $row['language_id'] ?? 0 ) ] = \PerfLocale\Util\Scalar::int( $row['newest'] ?? 0 );
		}

		return $out;
	}

	/**
	 * The (decided_at, id) of the $size-th applied row decided before a cutoff after a cursor: a gone-target window's end.
	 *
	 * @param string                          $cutoff UTC datetime.
	 * @param array{at: string, id: int}|null $after  The cursor; null from the first row.
	 * @param int                             $size   Rows per window.
	 * @return array{at: string, id: int}|false|null Null when fewer rows remain (the last window); false when the read failed.
	 */
	public function applied_window_end( string $cutoff, ?array $after, int $size ): array|false|null {
		[ $range, $args ] = self::window( '', $after, null );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $range is placeholders only.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT decided_at, id FROM %i WHERE status = %s AND decided_at < %s{$range} ORDER BY decided_at, id LIMIT 1 OFFSET %d",
				array_merge( [ $this->table(), self::STATUS_APPLIED, $cutoff ], $args, [ max( 0, $size - 1 ) ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( '' !== $this->wpdb->last_error ) {
			return false;
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'at' => \PerfLocale\Util\Scalar::str( $row['decided_at'] ?? '' ),
			'id' => \PerfLocale\Util\Scalar::int( $row['id'] ?? 0 ),
		];
	}

	/**
	 * Delete up to $batch applied rows decided before a cutoff whose language, string or post is gone,
	 * the newest too (a trashed post still exists); with a window, only rows in (after, until].
	 *
	 * @param string                          $cutoff UTC datetime; rows decided before it go.
	 * @param int                             $batch  Maximum rows.
	 * @param array{at: string, id: int}|null $after  Window start (exclusive); null from the first row.
	 * @param array{at: string, id: int}|null $until  Window end (inclusive); null to the cutoff.
	 * @return int Rows deleted, -1 when a statement failed.
	 */
	public function prune_gone_applied( string $cutoff, int $batch, ?array $after = null, ?array $until = null ): int {
		[ $range, $args ] = self::window( 'c.', $after, $until );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $range is placeholders only.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT c.id, c.decided_at FROM %i c
				 LEFT JOIN %i l ON l.id = c.language_id
				 LEFT JOIN %i s ON c.object_type = 'string' AND s.id = c.object_id
				 LEFT JOIN %i p ON c.object_type = 'post' AND p.ID = c.object_id
				 WHERE c.status = %s AND c.decided_at < %s{$range}
				   AND ( l.id IS NULL OR ( c.object_type = 'string' AND s.id IS NULL ) OR ( c.object_type = 'post' AND p.ID IS NULL ) )
				 ORDER BY c.decided_at ASC LIMIT %d",
				array_merge(
					[ $this->table(), Schema::table( 'languages' ), Schema::table( 'strings' ), $this->wpdb->posts, self::STATUS_APPLIED, $cutoff ],
					$args,
					[ max( 1, $batch ) ]
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( $this->db_failed() ) {
			return -1;
		}

		$rows = is_array( $rows ) ? $rows : [];

		return $this->delete_decided( self::ids( array_column( $rows, 'id' ) ), self::STATUS_APPLIED, self::span( $rows ) );
	}

	/**
	 * WHERE text and args keeping rows in (after, until] by (decided_at, id). Each bound also states its plain
	 * decided_at range: SQLite reads status_decided as a range only then.
	 *
	 * @param ''|'c.'                          $alias Column prefix.
	 * @param array{at: string, id: int}|null $after Exclusive start; null for none.
	 * @param array{at: string, id: int}|null $until Inclusive end; null for none.
	 * @return array{0: literal-string, 1: list<int|string>}
	 */
	private static function window( string $alias, ?array $after, ?array $until ): array {
		$sql  = '';
		$args = [];

		if ( null !== $after ) {
			$sql .= " AND {$alias}decided_at >= %s AND ( {$alias}decided_at > %s OR ( {$alias}decided_at = %s AND {$alias}id > %d ) )";
			array_push( $args, $after['at'], $after['at'], $after['at'], $after['id'] );
		}

		if ( null !== $until ) {
			$sql .= " AND {$alias}decided_at <= %s AND ( {$alias}decided_at < %s OR ( {$alias}decided_at = %s AND {$alias}id <= %d ) )";
			array_push( $args, $until['at'], $until['at'], $until['at'], $until['id'] );
		}

		return [ $sql, $args ];
	}

	/**
	 * The first and last decided_at of rows read in (decided_at, id) order; [] for none.
	 *
	 * @param array<int, mixed> $rows Rows with decided_at.
	 * @return array{0: string, 1: string}|array{}
	 */
	private static function span( array $rows ): array {
		if ( [] === $rows ) {
			return [];
		}

		$first = (array) reset( $rows );
		$last  = (array) end( $rows );

		return [ \PerfLocale\Util\Scalar::str( $first['decided_at'] ?? '' ), \PerfLocale\Util\Scalar::str( $last['decided_at'] ?? '' ) ];
	}

	/**
	 * Delete rows of one status by id, within the decided_at span they were read in: with status alone SQLite reads
	 * every row of the status on status_decided.
	 *
	 * @param array<int>                         $ids    Row ids.
	 * @param string                             $status The status the rows must still have.
	 * @param array{0: string, 1: string}|array{} $span  First and last decided_at of the rows read; [] for none.
	 * @return int Rows deleted, -1 when the delete failed.
	 */
	private function delete_decided( array $ids, string $status, array $span = [] ): int {
		if ( [] === $ids ) {
			return 0;
		}

		$in    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$range = [] === $span ? '' : ' AND decided_at >= %s AND decided_at <= %s';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The range and IN list are placeholders only.
		$result = $this->run(
			$this->wpdb->prepare( "DELETE FROM %i WHERE status = %s{$range} AND id IN ({$in})", array_merge( [ $this->table(), $status ], $span, $ids ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return false === $result ? -1 : (int) $result;
	}

	/**
	 * The id ending a window of `$size` pending rows after `$after`, in id order (KEY status_decided reads `$size` entries).
	 * FORCE INDEX: with ORDER BY id LIMIT MySQL walked PRIMARY over the decided rows (66,000 rows for a 2,000-row window).
	 *
	 * @param int $after Window start (exclusive id).
	 * @param int $size  Rows per window.
	 * @return int|false|null Null when fewer rows remain (the last window); false when the read failed.
	 */
	public function pending_window_end( int $after, int $size ): int|false|null {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT id FROM %i FORCE INDEX (status_decided) WHERE status = %s AND decided_at IS NULL AND id > %d ORDER BY id LIMIT 1 OFFSET %d',
				$this->table(),
				self::STATUS_PENDING,
				$after,
				max( 0, $size - 1 )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error ) {
			return false;
		}

		return null === $id ? null : \PerfLocale\Util\Scalar::int( $id );
	}

	/**
	 * Close pending rows whose language, string or post is gone, and post rows whose source is gone; with a window, only
	 * pending rows with an id in (after, until], so a statement reads that window, never the whole backlog.
	 *
	 * @param int      $batch Maximum rows per kind per call.
	 * @param int      $after Window start (exclusive id).
	 * @param int|null $until Window end (inclusive id); null for no end.
	 * @return int|false Rows closed; false when a statement failed.
	 */
	public function close_pending_orphans( int $batch, int $after = 0, ?int $until = null ): int|false {
		$batch = max( 1, $batch );
		$table = $this->table();
		$range = ' AND c.id > %d' . ( null === $until ? '' : ' AND c.id <= %d' );
		$args  = null === $until ? [ $after, $batch ] : [ $after, $until, $batch ];

		// A multi-table UPDATE cannot take LIMIT, so the ids are read first.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $range is placeholders only.
		$reads = [
			$this->wpdb->prepare(
				"SELECT c.id FROM %i c LEFT JOIN %i l ON l.id = c.language_id WHERE c.status = %s AND c.decided_at IS NULL{$range} AND l.id IS NULL ORDER BY c.id LIMIT %d",
				array_merge( [ $table, Schema::table( 'languages' ), self::STATUS_PENDING ], $args )
			),
			$this->wpdb->prepare(
				"SELECT c.id FROM %i c LEFT JOIN %i s ON s.id = c.object_id WHERE c.status = %s AND c.decided_at IS NULL{$range} AND c.object_type = %s AND s.id IS NULL ORDER BY c.id LIMIT %d",
				array_merge( [ $table, Schema::table( 'strings' ), self::STATUS_PENDING ], array_slice( $args, 0, -1 ), [ 'string', $batch ] )
			),
			$this->wpdb->prepare(
				"SELECT c.id FROM %i c LEFT JOIN %i p ON p.ID = c.object_id AND p.post_status <> 'trash' WHERE c.status = %s AND c.decided_at IS NULL{$range} AND c.object_type = %s AND p.ID IS NULL ORDER BY c.id LIMIT %d",
				array_merge( [ $table, $this->wpdb->posts, self::STATUS_PENDING ], array_slice( $args, 0, -1 ), [ 'post', $batch ] )
			),
			// A trashed target counts as gone above; a trashed source keeps its rows until it is
			// deleted, so only a missing source row counts here.
			$this->wpdb->prepare(
				"SELECT c.id FROM %i c LEFT JOIN %i p ON p.ID = c.source_id WHERE c.status = %s AND c.decided_at IS NULL{$range} AND c.object_type = %s AND p.ID IS NULL ORDER BY c.id LIMIT %d",
				array_merge( [ $table, $this->wpdb->posts, self::STATUS_PENDING ], array_slice( $args, 0, -1 ), [ 'post', $batch ] )
			),
		];

		$all = [];

		foreach ( $reads as $sql ) {
			$found = $this->wpdb->get_col( $sql );

			// A failed read is no empty window: the caller keeps its cursor.
			if ( '' !== $this->wpdb->last_error ) {
				return false;
			}

			foreach ( self::ids( $found ) as $id ) {
				$all[ $id ] = true;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return $this->close_by_id( array_keys( $all ), 'target_gone', '' ) ?? false;
	}

	/**
	 * Close the pending rows of a language (status superseded, reason target_gone), CLOSE_BATCH per update: each
	 * update's locks end with it (the caller runs outside a transaction), so a hold waits for one batch at most.
	 *
	 * @param int $language_id Language id.
	 * @return int|null Rows closed, or null when a statement failed (earlier windows may be closed).
	 */
	public function close_pending_for_language( int $language_id ): ?int {
		return $this->close_pending_where( 'language_id', $language_id, 'target_gone', '' );
	}

	/**
	 * Whether a language row exists, by a current read on servers (it waits for a delete in progress).
	 *
	 * @param int $language_id Language id.
	 * @return bool|null Null when the read failed.
	 */
	public function language_exists( int $language_id ): ?bool {
		$locking = ! ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM %i WHERE id = %d' . ( $locking ? ' LOCK IN SHARE MODE' : '' ), Schema::table( 'languages' ), $language_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return '' === $this->wpdb->last_error ? null !== $found : null;
	}

	/**
	 * Close the pending post rows of a source post being deleted, so its text stays in no proposal.
	 *
	 * @param int $source_id Source post id.
	 * @return int Rows closed.
	 */
	public function close_pending_for_source( int $source_id ): int {
		if ( $source_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->run(
			$this->wpdb->prepare(
				"UPDATE %i SET status = %s, reason = 'target_gone', payload = '', decided_at = %s, decided_by = 0 WHERE status = %s AND object_type = %s AND source_id = %d",
				$this->table(),
				self::STATUS_SUPERSEDED,
				self::now(),
				self::STATUS_PENDING,
				'post',
				$source_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Close every pending row of one object type (superseded, payload emptied). A Replace import calls it
	 * in its transaction: afterwards the same ids may name other rows.
	 *
	 * @param string $object_type Object type (`string`).
	 * @param string $reason      Reason recorded on each row.
	 * @param string $guard       The import's connection-guard condition ('' outside one): on a new connection nothing matches.
	 * @return int|null Rows closed, or null when a statement failed.
	 */
	public function close_pending_of_type( string $object_type, string $reason, string $guard = '' ): ?int {
		// Ids by plain reads on status_decided, then updates by id: only this type's rows are rewritten and locked.
		$closed = $this->close_pending_where( 'object_type', $object_type, $reason, $guard );

		// SQLite: the caller's transaction holds the only write lock, so no row can arrive after the read.
		if ( null === $closed || ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) ) {
			return $closed;
		}

		// A current read last: it closes rows committed after the plain read's snapshot, and its range lock keeps
		// later pending inserts waiting until the caller commits.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- The guard's condition is prepared by the guard.
		$result = $this->run(
			$this->wpdb->prepare(
				"UPDATE %i SET status = %s, reason = %s, payload = '', decided_at = %s, decided_by = 0 WHERE status = %s AND decided_at IS NULL AND object_type = %s",
				$this->table(),
				self::STATUS_SUPERSEDED,
				$reason,
				self::now(),
				self::STATUS_PENDING,
				$object_type
			) . ( '' === $guard ? '' : ' AND ' . $guard )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_int( $result ) ? $closed + $result : null;
	}

	/**
	 * Close the pending rows with `$column = $value`, read in windows of CLOSE_WINDOW pending rows (pending_window_end()),
	 * each closed before the next read: no read returns or examines more than one window. Never `ORDER BY id LIMIT`:
	 * MySQL planned that on PRIMARY.
	 *
	 * @param string     $column `language_id` or `object_type`.
	 * @param int|string $value  Its value.
	 * @param string     $reason Reason recorded on each row.
	 * @param string     $guard  Connection-guard condition, or ''.
	 * @return int|null Rows closed, or null when a statement failed.
	 */
	private function close_pending_where( string $column, int|string $value, string $reason, string $guard ): ?int {
		$column = 'language_id' === $column ? 'language_id' : 'object_type';
		$closed = 0;
		$after  = 0;

		do {
			$until = $this->pending_window_end( $after, self::CLOSE_WINDOW );

			if ( false === $until ) {
				return null;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The column is one of two literals; the rest are placeholders.
			$ids = $this->wpdb->get_col(
				$this->wpdb->prepare(
					self::PENDING_IDS . 'id > %d' . ( null === $until ? '' : ' AND id <= %d' ) . " AND {$column} = " . ( is_int( $value ) ? '%d' : '%s' ) . ' ORDER BY decided_at, id',
					array_merge( [ $this->table(), self::STATUS_PENDING, $after ], null === $until ? [] : [ $until ], [ $value ] )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

			$done = '' === $this->wpdb->last_error ? $this->close_in_batches( self::ids( $ids ), $reason, $guard ) : null;

			if ( null === $done ) {
				return null;
			}

			$closed += $done;
			$after   = (int) $until;
		} while ( null !== $until );

		return $closed;
	}

	/**
	 * Whether the last query failed; read afresh after each call (wpdb clears last_error per query).
	 *
	 * @phpstan-impure
	 * @return bool
	 */
	private function db_failed(): bool {
		return '' !== $this->wpdb->last_error;
	}

	/**
	 * Close pending rows by id, CLOSE_BATCH per update.
	 *
	 * @param list<int> $ids    Row ids.
	 * @param string    $reason Reason recorded on each row.
	 * @param string    $guard  Connection-guard condition, or ''.
	 * @return int|null Rows closed, or null when an update failed.
	 */
	private function close_in_batches( array $ids, string $reason, string $guard ): ?int {
		$closed = 0;

		foreach ( array_chunk( $ids, self::CLOSE_BATCH ) as $batch ) {
			$done = $this->close_by_id( $batch, $reason, $guard );

			if ( null === $done ) {
				return null;
			}

			$closed += $done;
		}

		return $closed;
	}

	/**
	 * Close pending rows by id (status superseded, payload emptied).
	 *
	 * @param int[]  $ids    Row ids.
	 * @param string $reason Reason recorded on each row.
	 * @param string $guard  The import's connection-guard condition ('' outside one).
	 * @return int Rows closed.
	 */
	public function close_pending( array $ids, string $reason, string $guard = '' ): int {
		return (int) $this->close_by_id( self::ids( $ids ), $reason, $guard );
	}

	/**
	 * Close pending rows by id. With `decided_at IS NULL` SQLite reads the ids as points of status_decided; with
	 * `status` alone it reads the whole pending range.
	 *
	 * @param list<int> $ids    Positive row ids.
	 * @param string    $reason Reason recorded on each row.
	 * @param string    $guard  Connection-guard condition, or ''.
	 * @return int|null Rows closed, or null when the statement failed.
	 */
	private function close_by_id( array $ids, string $reason, string $guard ): ?int {
		if ( [] === $ids ) {
			return 0;
		}

		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only; the guard's condition is prepared by the guard.
		$result = $this->run(
			$this->wpdb->prepare(
				"UPDATE %i SET status = %s, reason = %s, payload = '', decided_at = %s, decided_by = 0 WHERE status = %s AND decided_at IS NULL AND id IN ({$in})",
				array_merge( [ $this->table(), self::STATUS_SUPERSEDED, $reason, self::now(), self::STATUS_PENDING ], $ids )
			) . ( '' === $guard ? '' : ' AND ' . $guard )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		return is_int( $result ) ? $result : null;
	}

	/**
	 * Rows a user triggered or decided, for the personal-data exporter: after an id (the last page's last row) when one
	 * is given, so a deep page reads `$limit` ids per key, else by offset.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Page size.
	 * @param int $offset  Offset (ignored with $after).
	 * @param int $after   Last id of the page before; 0 for none.
	 * @return list<array<string, int|string>>|null Rows with id, area, object, language, status, dates, user_id and decided_by; null when the read failed.
	 * @phpstan-return list<ChangeRow>|null
	 */
	public function rows_for_user( int $user_id, int $limit, int $offset, int $after = 0 ): ?array {
		$limit  = max( 1, $limit );
		$offset = $after > 0 ? 0 : max( 0, $offset );
		$after  = max( 0, $after );

		// The page's ids from each key's first offset + limit ids (index-only ordered reads), never an OR that sorts every
		// row; then the rows by id (one statement with a join let MariaDB scan the whole table on a deep page).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$ids  = self::ids(
			$this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT id FROM ( SELECT id FROM %i WHERE user_id = %d AND id > %d ORDER BY id LIMIT %d ) a
					UNION SELECT id FROM ( SELECT id FROM %i WHERE decided_by = %d AND id > %d ORDER BY id LIMIT %d ) b
					ORDER BY id LIMIT %d OFFSET %d',
					$this->table(),
					$user_id,
					$after,
					$offset + $limit,
					$this->table(),
					$user_id,
					$after,
					$offset + $limit,
					$limit,
					$offset
				)
			)
		);
		$rows = [] === $ids || '' !== $this->wpdb->last_error ? [] : $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, area, object_type, object_id, language_id, status, created_at, decided_at, user_id, decided_by FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id',
				array_merge( [ $this->table() ], $ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}

		return self::shape_all( $rows );
	}

	/**
	 * Anonymise a user's rows: user_id and decided_by become 0 and the actor 'erased'.
	 *
	 * @param int $user_id User id.
	 * @param int $batch   Maximum rows per statement.
	 * @return int|null Rows changed, or null when a statement failed.
	 */
	public function anonymize_user( int $user_id, int $batch ): ?int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$batch = max( 1, $batch );

		// Ids first (the column's key, in id order), then updates by id: some SQLite builds refuse UPDATE ... LIMIT.
		$triggered = $this->user_rows( false, $user_id, $batch );
		$decided   = $this->user_rows( true, $user_id, $batch );

		if ( null === $triggered || null === $decided ) {
			return null;
		}

		$changed = 0;

		if ( [] !== $triggered ) {
			$in = implode( ', ', array_fill( 0, count( $triggered ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
			$result = $this->run( $this->wpdb->prepare( "UPDATE %i SET user_id = 0, actor = 'erased' WHERE user_id = %d AND id IN ({$in})", array_merge( [ $this->table(), $user_id ], $triggered ) ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

			if ( false === $result ) {
				return null;
			}

			$changed += (int) $result;
		}

		if ( [] !== $decided ) {
			$in = implode( ', ', array_fill( 0, count( $decided ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
			$result = $this->run( $this->wpdb->prepare( "UPDATE %i SET decided_by = 0 WHERE decided_by = %d AND id IN ({$in})", array_merge( [ $this->table(), $user_id ], $decided ) ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

			if ( false === $result ) {
				return null;
			}

			$changed += (int) $result;
		}

		return $changed;
	}

	/**
	 * Up to $batch ids of the rows a user started or decided, in id order.
	 *
	 * @param bool $decider Whether to read decided_by (else user_id).
	 * @param int  $user_id User id.
	 * @param int  $batch   Maximum rows.
	 * @return list<int>|null Null when the read failed.
	 */
	private function user_rows( bool $decider, int $user_id, int $batch ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $this->wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Each literal has three placeholders; the sniff adds both.
			$this->wpdb->prepare(
				$decider ? 'SELECT id FROM %i WHERE decided_by = %d ORDER BY id LIMIT %d' : 'SELECT id FROM %i WHERE user_id = %d ORDER BY id LIMIT %d',
				$this->table(),
				$user_id,
				$batch
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return '' === $this->wpdb->last_error ? self::ids( $ids ) : null;
	}

	/**
	 * Whether any row still names a user.
	 *
	 * @param int $user_id User id.
	 * @return bool|null Null when the read failed.
	 */
	public function has_rows_for_user( int $user_id ): ?bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d OR decided_by = %d LIMIT 1',
				$this->table(),
				$user_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $this->wpdb->last_error ) {
			return null;
		}

		return null !== $found;
	}
}
