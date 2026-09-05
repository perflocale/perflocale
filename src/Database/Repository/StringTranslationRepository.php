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
 * Data access for the `perflocale_string_translations` table.
 *
 * Introduced in DB version 2. Replaces the prior pattern of storing each
 * translation as an individual `perflocale_str_<string_id>_<lang_id>` row
 * in wp_options, which required CONCAT() joins that couldn't use indexes.
 *
 * All lookups here use the composite PRIMARY KEY on (string_id, language_id)
 * or the secondary index on language_id - index seeks, not table scans.
 */
final class StringTranslationRepository {

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager — accepted for DI consistency
	 *                           with sibling repos; this class currently
	 *                           caches only via wpdb's query cache, so the
	 *                           manager isn't referenced.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- DI signature parity with sibling repositories.
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb = $wpdb;
	}

	/**
	 * Resolve the full table name for the current blog.
	 *
	 * Computed on every call so that switch_to_blog() flips the prefix
	 * correctly — capturing the name in the constructor would otherwise
	 * pin this instance to whichever blog was active at construction.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'string_translations' );
	}

	/**
	 * Get the translation for a single (string, language) pair.
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
	 * Batched fetch of translations for many strings in one language — one
	 * IN() query instead of get() per string (the bulk-MT skip-existing hot
	 * path called it once per string per chunk).
	 *
	 * Only NON-EMPTY translations are returned, so a caller can treat a
	 * missing key exactly like get()==='' (translatable). Empty rows never
	 * exist anyway — set('') deletes the row — but the WHERE guards it.
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
	 * Upsert a single translation. Empty value deletes the row so the
	 * absence semantic ("no translation") matches the pre-v2 behavior
	 * where an empty option was indistinguishable from a missing one.
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

		// INSERT … ON DUPLICATE KEY UPDATE on the (string_id, language_id)
		// PRIMARY KEY — atomic insert-or-update, deliberately NOT REPLACE
		// INTO. REPLACE is a DELETE+INSERT that silently resets every column
		// it doesn't name: it would blow away extra_forms (plural forms 2..N)
		// whenever ANY caller — admin save, the MT bulk job, migrations, or
		// an addon bridging a value-matched string — rewrites the base
		// translation. This upsert preserves extra_forms unconditionally
		// (only set_extra_forms() and authoritative plural imports touch it)
		// and resets the MT review columns ONLY when the text really changed
		// (a quality score belongs to the text it scored; a no-op rewrite
		// keeps it, so the sampler isn't forced to re-score). Assignment
		// ORDER is load-bearing: the review IF()s must run before
		// `translation = VALUES(translation)` — ON DUPLICATE KEY UPDATE
		// assigns left-to-right, and afterwards `translation` already holds
		// the new value, making the comparison a tautology.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (string_id, language_id, translation)
				 VALUES (%d, %d, %s)
				 ON DUPLICATE KEY UPDATE
					translation = VALUES(translation)',
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
	 * Set (or clear) the JSON plural extra_forms on an EXISTING row.
	 *
	 * Uses UPDATE — the row must already exist, so callers create it via
	 * set() first. set() itself never touches this column (its upsert
	 * preserves extra_forms), making this method the single write path for
	 * plural forms 2..N outside authoritative plural imports.
	 * A null or empty list stores NULL, clearing any stale value.
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

		// A clear must store SQL NULL, not '' — wpdb::prepare() binds a null
		// %s argument as the empty string, which would satisfy the
		// `IS NOT NULL` guards (batch map load, counts) with a junk value.
		// NULL is the column's only "no extra forms" state, so branch the SQL.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		if ( $json === null ) {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE %i SET extra_forms = NULL WHERE string_id = %d AND language_id = %d',
					$this->table(),
					$string_id,
					$language_id
				)
			);
		} else {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE %i SET extra_forms = %s WHERE string_id = %d AND language_id = %d',
					$this->table(),
					$json,
					$string_id,
					$language_id
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		return $result !== false;
	}

	/**
	 * Canonicalise a positional extra-forms list to the exact string this
	 * repository stores (a JSON array, or NULL for "no extra forms"). Shared
	 * by set_extra_forms() and callers that need to DIFF a posted value
	 * against the stored column without re-writing it.
	 *
	 * Forms are POSITIONAL (form 2 at index 0, form 3 at index 1, …). Only
	 * TRAILING empties are truly absent — a middle empty must be kept as ''
	 * so a later form keeps its CLDR index (dropping it would shift, say,
	 * "many" into the "few" slot). Empty forms fall back to form 1 at serve
	 * time.
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
	 * Read the decoded plural extra_forms for one row.
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
	 * Defensive orphan-sweep: delete any string_translations rows whose
	 * referenced strings.id no longer exists.
	 *
	 * The primary cleanup path is StringRepository::gc_stale_strings(),
	 * which cascades to string_translations via an explicit IN-list before
	 * deleting the parent strings row. This method is a safety net for
	 * any path that bypasses that cascade (manual SQL, partial-failure
	 * recovery, a future code path that forgets the cascade).
	 *
	 * Single-statement LEFT JOIN; cheap when there's nothing to delete.
	 *
	 * @return int Number of orphan rows deleted.
	 */
	public function gc_orphans(): int {
		$translations_table = $this->table();
		$strings_table      = Schema::table( 'strings' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE tr FROM %i tr
				LEFT JOIN %i s ON s.id = tr.string_id
				WHERE s.id IS NULL',
				$translations_table,
				$strings_table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $deleted > 0 ) {
			// Generation bump — reliable on object caches whose
			// wp_cache_flush_group() no-ops (e.g. Redis Object Cache + Predis).
			CacheManager::bump_group_generation( 'perflocale_trans' );
			/** @hook perflocale/string_translations/orphans_swept Fires when the orphan-sweep deletes rows. */
			do_action( 'perflocale/string_translations/orphans_swept', $deleted );
		}

		return $deleted;
	}

	/**
	 * Delete every translation row for a given language.
	 *
	 * Used when a language is removed from PerfLocale - replaces the old
	 * REGEXP-against-wp_options cleanup.
	 *
	 * Throws on DB failure so callers running this inside a transaction
	 * (LanguageRepository::delete wraps the full FK-cascade in one) see
	 * the failure and ROLLBACK. Returning silent 0 on failure would let
	 * the outer transaction COMMIT with this table's DELETE not applied,
	 * defeating the all-or-nothing guarantee.
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
	 * Delete every translation row for a given string.
	 *
	 * Used when a string is removed (e.g. during stale-string migration).
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
	 * Move every translation row from one string to another.
	 *
	 * Used by the stale-string migration path when a source text changes
	 * but the translations should be reused (marked as needs_update later).
	 *
	 * Never deletes the source rows unless the UPDATE that copies them
	 * reported success, so a database failure cannot leave the translations
	 * with no home.
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE IGNORE %i SET string_id = %d WHERE string_id = %d',
				$this->table(),
				$to_id,
				$from_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

		// A failed UPDATE moved NOTHING - every row is still attached to
		// $from_id, and the DELETE below is the destination-wins cleanup for
		// rows the UNIQUE key refused to move. Running it after a failure
		// deletes the only remaining copy of those translations. A transaction
		// is not the answer here: START TRANSACTION silently no-ops on a
		// MyISAM-default host (which is exactly why ENGINE=InnoDB is written
		// out on all nine tables), so ORDER is the mechanism - never delete a
		// source the move did not confirm it copied. Report the failure and
		// leave the rows alone; the caller aborts its cascade on a negative.
		if ( $result === false ) {
			return -1;
		}

		// Clean up any rows that couldn't move due to a pre-existing
		// row at the target (unique conflict from IGNORE).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete( $this->table(), [ 'string_id' => $from_id ], [ '%d' ] );

		return (int) $result;
	}

	/**
	 * Bulk-load translations for a set of string IDs into a lookup map
	 * keyed by string_id. Used by TranslationFileGenerator and lookups
	 * that would otherwise issue one query per row.
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
	 * Batch-fetch decoded plural extra_forms for a set of string IDs.
	 *
	 * Returns only string_ids that actually have extra forms (the JSON
	 * column is NULL for every 2-form language and every non-plural
	 * string), so the map is usually empty.
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
}
