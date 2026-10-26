<?php
/**
 * The data import's per-row bookkeeping, in a temporary table.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An import's id maps and id sets, in a session TEMPORARY table so memory does not grow with the file (else PHP arrays).
 *
 * Created before a Replace's START TRANSACTION; a reconnect loses it, so a failed statement throws and stops the import.
 * Nothing persists on the site, so it needs no export, import or uninstall entry.
 */
final class ImportWorkTable {

	/**
	 * Merge: file group id => the group here holding one of its members, or 0 when they sit in different groups here.
	 */
	public const ANCHOR = 1;

	/**
	 * Post ids the file's translation links name (new_id 0).
	 */
	public const LINKED_POST = 2;

	/**
	 * Replace: file group id => the id the group was written under, when a kept string group holds the file's id.
	 */
	public const MOVED_GROUP = 3;

	/**
	 * First kind of the per-parent-table id maps (old id => id here), assigned in first-use order.
	 */
	private const FIRST_ID_MAP = 16;

	/**
	 * First kind of the per-parent-table sets of file ids whose rows were not saved (new_id 0).
	 */
	private const FIRST_NOT_SAVED = 128;

	/**
	 * Rows per statement.
	 */
	private const CHUNK = 500;

	/**
	 * Whether the rows live in the temporary table (else in $memory).
	 *
	 * @var bool
	 */
	private bool $in_table = false;

	/**
	 * Whether the table exists on this connection (or the arrays are in use).
	 *
	 * @var bool
	 */
	private bool $open = false;

	/**
	 * The rows when the table could not be created: kind => old id => new id.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $memory = [];

	/**
	 * Parent table => its id-map kind.
	 *
	 * @var array<string, int>
	 */
	private array $kinds = [];

	/**
	 * Kinds that hold at least one row.
	 *
	 * @var array<int, true>
	 */
	private array $used = [];

	/**
	 * The message a failed statement throws.
	 *
	 * @var string
	 */
	private string $lost_message;

	/**
	 * The work table of one import.
	 *
	 * @param string $lost_message What a failed statement throws: the import's sentence for a lost connection.
	 */
	public function __construct( string $lost_message ) {
		$this->lost_message = $lost_message;
	}

	/**
	 * Create the table (empty) on the current connection, or fall back to PHP arrays when the database refuses it.
	 *
	 * @return void
	 */
	public function open(): void {
		$wpdb = Typed::wpdb();
		$was  = $wpdb->suppress_errors( true );

		try {
			$columns = ' (kind TINYINT UNSIGNED NOT NULL, old_id BIGINT UNSIGNED NOT NULL, new_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (kind, old_id))';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name is the site prefix and a constant; a session TEMPORARY table.
			$created = $wpdb->query( 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->table() . $columns );

			// SQLite Database Integration refuses IF NOT EXISTS before the session's first temporary table.
			if ( false === $created ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- As above.
				$created = $wpdb->query( 'CREATE TEMPORARY TABLE ' . $this->table() . $columns );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- As above.
			$this->in_table = false !== $created && false !== $wpdb->query( 'DELETE FROM ' . $this->table() );
		} finally {
			$wpdb->suppress_errors( $was );
		}

		$this->memory = [];
		$this->kinds  = [];
		$this->used   = [];
		$this->open   = true;
	}

	/**
	 * Drop the table (when it was created) and forget every row.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( $this->open && $this->in_table ) {
			$wpdb = Typed::wpdb();
			$was  = $wpdb->suppress_errors( true );

			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The table name is the site prefix and a constant.
				$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $this->table() );
			} finally {
				$wpdb->suppress_errors( $was );
			}
		}

		$this->open     = false;
		$this->in_table = false;
		$this->memory   = [];
		$this->kinds    = [];
		$this->used     = [];
	}

	/**
	 * Whether the rows live in the temporary table.
	 *
	 * @return bool
	 */
	public function in_table(): bool {
		return $this->in_table;
	}

	/**
	 * The id-map kind of a parent table.
	 *
	 * @param string $parent_table Short table name.
	 * @return int
	 */
	public function id_map( string $parent_table ): int {
		if ( ! isset( $this->kinds[ $parent_table ] ) ) {
			$this->kinds[ $parent_table ] = self::FIRST_ID_MAP + count( $this->kinds );
		}

		return $this->kinds[ $parent_table ];
	}

	/**
	 * The kind of a parent table's file ids whose rows were not saved.
	 *
	 * @param string $parent_table Short table name.
	 * @return int
	 */
	public function not_saved( string $parent_table ): int {
		return $this->id_map( $parent_table ) - self::FIRST_ID_MAP + self::FIRST_NOT_SAVED;
	}

	/**
	 * Whether a kind holds at least one row.
	 *
	 * @param int $kind Kind.
	 * @return bool
	 */
	public function has( int $kind ): bool {
		return isset( $this->used[ $kind ] );
	}

	/**
	 * Store old id => new id pairs of one kind; a pair already stored for the same old id is replaced.
	 *
	 * @param int             $kind Kind.
	 * @param array<int, int> $map  Old id => new id.
	 * @return void
	 * @throws \RuntimeException When a statement fails.
	 */
	public function put( int $kind, array $map ): void {
		if ( [] === $map ) {
			return;
		}

		$this->used[ $kind ] = true;

		if ( ! $this->in_table ) {
			foreach ( $map as $old => $new ) {
				$this->memory[ $kind ][ (int) $old ] = (int) $new;
			}

			return;
		}

		$wpdb = Typed::wpdb();

		foreach ( array_chunk( $map, self::CHUNK, true ) as $chunk ) {
			$values = [];

			foreach ( $chunk as $old => $new ) {
				$values[] = (int) $kind;
				$values[] = (int) $old;
				$values[] = (int) $new;
			}

			$rows = implode( ', ', array_fill( 0, count( $chunk ), '(%d, %d, %d)' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name is the site prefix and a constant; the values are bound with %d in $rows.
			$sql = $wpdb->prepare( 'REPLACE INTO %i (kind, old_id, new_id) VALUES ' . $rows, array_merge( [ $this->name() ], $values ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			if ( ! is_string( $sql ) || false === $wpdb->query( $sql ) ) {
				throw new \RuntimeException( $this->lost_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
			}
		}
	}

	/**
	 * The stored new ids of the given old ids of one kind.
	 *
	 * @param int        $kind    Kind.
	 * @param array<int> $old_ids Old ids (repeats and non-positive ids are ignored).
	 * @return array<int, int> Old id => new id, for the ids stored.
	 * @throws \RuntimeException When a statement fails.
	 */
	public function get( int $kind, array $old_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $old_ids ), static fn( int $id ): bool => $id > 0 ) ) );

		if ( [] === $ids || ! isset( $this->used[ $kind ] ) ) {
			return [];
		}

		if ( ! $this->in_table ) {
			return array_intersect_key( $this->memory[ $kind ] ?? [], array_flip( $ids ) );
		}

		$wpdb = Typed::wpdb();
		$out  = [];

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacements arrive as one list. The table name is the site prefix and a constant; the values are bound with %d.
			$sql  = $wpdb->prepare( 'SELECT old_id, new_id FROM %i WHERE kind = %d AND old_id IN (' . $in . ')', array_merge( [ $this->name(), $kind ], $chunk ) );
			$rows = is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( $this->lost_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
			}

			foreach ( Typed::rows( $rows ) as $row ) {
				$out[ Typed::int( $row['old_id'] ?? null ) ] = Typed::int( $row['new_id'] ?? null );
			}
		}

		return $out;
	}

	/**
	 * The old ids of one kind, in ascending order, a page at a time.
	 *
	 * @param int $kind Kind.
	 * @return \Generator<int, array<int, int>> Pages of at most 500 old ids.
	 * @throws \RuntimeException When a statement fails.
	 */
	public function pages( int $kind ): \Generator {
		if ( ! isset( $this->used[ $kind ] ) ) {
			return;
		}

		if ( ! $this->in_table ) {
			$ids = array_keys( $this->memory[ $kind ] ?? [] );
			sort( $ids );

			foreach ( array_chunk( $ids, self::CHUNK ) as $page ) {
				yield $page;
			}

			return;
		}

		$wpdb  = Typed::wpdb();
		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table name is the site prefix and a constant; the values are bound with %d.
			$sql  = $wpdb->prepare( 'SELECT old_id FROM %i WHERE kind = %d AND old_id > %d ORDER BY old_id LIMIT %d', $this->name(), $kind, $after, self::CHUNK );
			$rows = is_string( $sql ) ? $wpdb->get_col( $sql ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( $this->lost_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for WP-CLI and the import report; the admin notice escapes it when it prints it.
			}

			$page = Typed::ints( $rows );
			$full = self::CHUNK === count( $page );

			if ( [] !== $page ) {
				yield $page;
				$after = (int) end( $page );
			}
		} while ( $full );
	}

	/**
	 * The table name, backquoted.
	 *
	 * @return string
	 */
	private function table(): string {
		return '`' . $this->name() . '`';
	}

	/**
	 * The table name.
	 *
	 * @return string
	 */
	private function name(): string {
		return Typed::wpdb()->prefix . 'perflocale_import_work';
	}
}
