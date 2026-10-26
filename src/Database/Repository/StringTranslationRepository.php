<?php
/**
 * String translation repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for `perflocale_string_translations`: every lookup is an index seek on the (string_id, language_id)
 * primary key or the language_id index.
 */
final class StringTranslationRepository {

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager, accepted for DI consistency with sibling repositories (unused).
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- DI signature parity with sibling repositories.
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb = $wpdb;
	}

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'string_translations' );
	}

	/**
	 * The translation of one (string, language) pair.
	 *
	 * @param int $string_id String ID.
	 * @param int $language_id Language ID.
	 * @return string Empty string when no translation exists.
	 */
	public function get( int $string_id, int $language_id ): string {
		if ( $string_id <= 0 || $language_id <= 0 ) {
			return '';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT translation FROM %i WHERE string_id = %d AND language_id = %d LIMIT 1',
				$this->table(),
				$string_id,
				$language_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Translations of many strings in one language in one IN() query (the bulk skip-existing path). Only non-empty ones,
	 * so a missing key reads as get() === ''.
	 *
	 * @param int[] $string_ids  String IDs.
	 * @param int   $language_id  Language ID.
	 * @return array<int, string> Map of string_id → non-empty translation.
	 */
	public function get_many( array $string_ids, int $language_id ): array {
		$string_ids = array_values( array_filter( array_map( 'intval', $string_ids ), static fn( int $i ): bool => $i > 0 ) );

		if ( $string_ids === [] || $language_id <= 0 ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT string_id, translation FROM %i
				WHERE string_id IN ({$placeholders}) AND language_id = %d AND translation <> ''",
				array_merge( [ $this->table() ], $string_ids, [ $language_id ] )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->string_id ] = (string) $row->translation;
		}

		return $map;
	}

	/**
	 * Upsert one translation; an empty value deletes the row (no row is "no translation").
	 *
	 * @param int    $string_id String ID.
	 * @param int    $language_id Language ID.
	 * @param string $translation Translation text.
	 * @return bool True on success.
	 */
	public function set( int $string_id, int $language_id, string $translation ): bool {
		if ( $string_id <= 0 || $language_id <= 0 ) {
			return false;
		}

		if ( $translation === '' ) {
			return $this->delete( $string_id, $language_id );
		}

		// Atomic upsert on the (string_id, language_id) primary key, not REPLACE INTO: REPLACE is DELETE+INSERT and
		// would reset extra_forms (plural forms 2..N), which only set_extra_forms() and plural imports may change.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (string_id, language_id, translation)
				 VALUES (%d, %d, %s)
				 ON DUPLICATE KEY UPDATE
					translation = VALUES(translation)' . Schema::touch_write_time( [ [ 'translation', 'VALUES(translation)', 'text' ] ] ),
				$this->table(),
				$string_id,
				$language_id,
				$translation
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return $result !== false;
	}

	/**
	 * Set or clear the plural extra_forms of an existing row (an UPDATE: set() creates the row and never touches this
	 * column). Null or [] stores NULL.
	 *
	 * @param int                        $string_id   String ID.
	 * @param int                        $language_id Language ID.
	 * @param array<int, string>|null    $forms       Forms 2..N, or null to clear.
	 * @return bool
	 */
	public function set_extra_forms( int $string_id, int $language_id, ?array $forms ): bool {
		if ( $string_id <= 0 || $language_id <= 0 ) {
			return false;
		}

		$json = self::normalize_extra_forms( $forms );

		// A clear stores SQL NULL, not '': prepare() binds a null %s as '', which would pass the IS NOT NULL guards.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacements arrive as one array (a second binding on SQLite only).
		if ( $json === null ) {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE %i SET extra_forms = NULL' . Schema::touch_write_time( [ [ 'extra_forms', 'NULL', 'text_null' ] ] ) . ' WHERE string_id = %d AND language_id = %d',
					$this->table(),
					$string_id,
					$language_id
				)
			);
		} else {
			// On SQLite the write-time fragment compares with a second binding of the new JSON (a stored NULL compares unequal).
			$touch  = Schema::touch_write_time( [ [ 'extra_forms', '%s', 'text' ] ] );
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE %i SET extra_forms = %s' . $touch . ' WHERE string_id = %d AND language_id = %d',
					array_merge( [ $this->table(), $json ], '' === $touch ? [] : [ $json ], [ $string_id, $language_id ] )
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return $result !== false;
	}

	/**
	 * The stored form of an extra-forms list (a JSON array, or null for none), shared with callers that compare a posted
	 * value. Positional (form 2 at index 0): only trailing empties are dropped, a middle '' keeps later CLDR indexes.
	 *
	 * @param array<int, string>|null $forms Forms 2..N, or null.
	 * @return string|null Stored JSON, or null when there are no forms.
	 */
	public static function normalize_extra_forms( ?array $forms ): ?string {
		if ( ! is_array( $forms ) ) {
			return null;
		}

		$forms = array_map( 'strval', $forms );

		while ( $forms !== [] && end( $forms ) === '' ) {
			array_pop( $forms );
		}

		return $forms === [] ? null : wp_json_encode( $forms );
	}

	/**
	 * The decoded plural extra_forms of one row.
	 *
	 * @param int $string_id   String ID.
	 * @param int $language_id Language ID.
	 * @return array<int, string> Forms 2..N (empty when none).
	 */
	public function get_extra_forms( int $string_id, int $language_id ): array {
		if ( $string_id <= 0 || $language_id <= 0 ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$json = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT extra_forms FROM %i WHERE string_id = %d AND language_id = %d',
				$this->table(),
				$string_id,
				$language_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_string( $json ) || $json === '' ) {
			return [];
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : [];
	}

	/**
	 * Delete a translation row.
	 *
	 * @param int $string_id String ID.
	 * @param int $language_id Language ID.
	 * @return bool
	 */
	public function delete( int $string_id, int $language_id ): bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $this->wpdb->delete(
			$this->table(),
			[
				'string_id'   => $string_id,
				'language_id' => $language_id,
			],
			[ '%d', '%d' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result !== false;
	}

	/**
	 * Delete translation rows whose string is gone: a safety net behind StringRepository::gc_stale_strings()'s cascade.
	 * One table per statement (the SQLite integration's 2.2.x legacy driver mishandles a DELETE with a JOIN).
	 *
	 * @return int Number of orphan rows deleted.
	 */
	public function gc_orphans(): int {
		$translations_table = $this->table();
		$strings_table      = Schema::table( 'strings' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE string_id NOT IN ( SELECT id FROM %i )',
				$translations_table,
				$strings_table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $deleted > 0 ) {
			// A generation bump: wp_cache_flush_group() is a no-op on some object caches (Redis Object Cache with Predis).
			CacheManager::bump_group_generation( 'perflocale_trans' );
			/** @hook perflocale/string_translations/orphans_swept Fires when the orphan-sweep deletes rows. */
			do_action( 'perflocale/string_translations/orphans_swept', $deleted );
		}

		return $deleted;
	}

	/**
	 * Delete every translation of a language. Throws on failure, so LanguageRepository::delete()'s transaction rolls
	 * back instead of committing without this table.
	 *
	 * @param int $language_id Language ID.
	 * @return int Number of rows deleted.
	 * @throws \RuntimeException When the underlying DELETE fails.
	 */
	public function delete_for_language( int $language_id ): int {
		if ( $language_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $this->wpdb->delete(
			$this->table(),
			[ 'language_id' => $language_id ],
			[ '%d' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $result === false ) {
			throw new \RuntimeException( 'StringTranslationRepository::delete_for_language: wpdb->delete failed' );
		}

		return (int) $result;
	}

	/**
	 * Delete every translation of a string.
	 *
	 * @param int $string_id String ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_for_string( int $string_id ): int {
		if ( $string_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $this->wpdb->delete(
			$this->table(),
			[ 'string_id' => $string_id ],
			[ '%d' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result === false ? 0 : (int) $result;
	}

	/**
	 * Move every translation of one string to another (stale-string migration); the source rows are deleted only after
	 * the UPDATE reported success.
	 *
	 * @param int $from_id Source string ID.
	 * @param int $to_id Destination string ID.
	 * @return int Rows moved, 0 when there was nothing to move, or -1 when the
	 *             UPDATE failed - in which case nothing was deleted and every
	 *             row is still attached to $from_id.
	 */
	public function move_translations( int $from_id, int $to_id ): int {
		if ( $from_id <= 0 || $to_id <= 0 || $from_id === $to_id ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The replacements arrive as one array (a second binding on SQLite only).
		$touch  = Schema::touch_write_time( [ [ 'string_id', '%d', 'int' ] ] );
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE IGNORE %i SET string_id = %d' . $touch . ' WHERE string_id = %d',
				array_merge( [ $this->table(), $to_id ], '' === $touch ? [] : [ $to_id ], [ $from_id ] )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// A failed UPDATE moved nothing: the DELETE below would remove the only copy. Order is the safeguard, not a
		// transaction (a no-op on MyISAM-default hosts).
		if ( $result === false ) {
			return -1;
		}

		// Rows the unique key kept from moving (the target had one): the destination wins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete( $this->table(), [ 'string_id' => $from_id ], [ '%d' ] );

		return (int) $result;
	}

	/**
	 * Translations of many strings in one language, string_id => translation, in one query.
	 *
	 * @param array<int, int> $string_ids String IDs.
	 * @param int             $language_id Language ID.
	 * @return array<int, string> Map of string_id => translation.
	 */
	public function get_map( array $string_ids, int $language_id ): array {
		$string_ids = array_values( array_unique( array_map( 'intval', $string_ids ) ) );
		$string_ids = array_filter( $string_ids, static fn( int $id ): bool => $id > 0 );

		if ( $string_ids === [] || $language_id <= 0 ) {
			return [];
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );

		$args   = $string_ids;
		$args[] = $language_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT string_id, translation FROM %i WHERE string_id IN ({$placeholders}) AND language_id = %d",
				array_merge( [ $this->table() ], $args )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row->string_id ] = (string) $row->translation;
			}
		}

		return $out;
	}

	/**
	 * Decoded plural extra_forms of many strings, only those that have some (usually none).
	 *
	 * @param array<int, int> $string_ids  Source string IDs.
	 * @param int             $language_id Language ID.
	 * @return array<int, array<int, string>> string_id => [form2, form3, …]
	 */
	public function get_extra_forms_map( array $string_ids, int $language_id ): array {
		$string_ids = array_values( array_unique( array_map( 'intval', $string_ids ) ) );
		$string_ids = array_filter( $string_ids, static fn( int $id ): bool => $id > 0 );

		if ( $string_ids === [] || $language_id <= 0 ) {
			return [];
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$placeholders = implode( ',', array_fill( 0, count( $string_ids ), '%d' ) );

		$args   = $string_ids;
		$args[] = $language_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT string_id, extra_forms FROM %i WHERE string_id IN ({$placeholders}) AND language_id = %d AND extra_forms IS NOT NULL AND extra_forms <> ''",
				array_merge( [ $this->table() ], $args )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$decoded = json_decode( (string) $row->extra_forms, true );

				if ( is_array( $decoded ) && $decoded !== [] ) {
					$out[ (int) $row->string_id ] = array_map( 'strval', $decoded );
				}
			}
		}

		return $out;
	}

	/**
	 * Upsert many translations, one statement per 200 rows, as set() writes each (`translation` only, extra_forms kept);
	 * empty translations and non-positive ids are skipped (bulk writers never delete).
	 *
	 * @param array<int, array{0: int, 1: int, 2: string}> $rows string_id, language_id, translation.
	 * @return bool False when a statement failed (earlier chunks stay written).
	 */
	public function set_many( array $rows ): bool {
		$rows = array_values(
			array_filter(
				$rows,
				static fn( array $r ): bool => (int) $r[0] > 0 && (int) $r[1] > 0 && (string) $r[2] !== ''
			)
		);

		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = implode( ',', array_fill( 0, count( $chunk ), '(%d, %d, %s)' ) );
			$args   = [ $this->table() ];

			foreach ( $chunk as $r ) {
				$args[] = (int) $r[0];
				$args[] = (int) $r[1];
				$args[] = (string) $r[2];
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $values is a generated placeholder list bound to $args.
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO %i (string_id, language_id, translation)
					 VALUES {$values}
					 ON DUPLICATE KEY UPDATE
						translation = VALUES(translation)" . Schema::touch_write_time( [ [ 'translation', 'VALUES(translation)', 'text' ] ] ),
					$args
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $result === false ) {
				return false;
			}
		}

		return true;
	}
}
