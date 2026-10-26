<?php
/**
 * String repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Contract\RepositoryInterface;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for perflocale_strings: scanned and registered strings, deduplicated by a SHA-256 hash.
 */
final class StringRepository implements RepositoryInterface {

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		global $wpdb;

		$this->wpdb  = $wpdb;
		$this->cache = $cache;
	}

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'strings' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function find( int $id ): ?object {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table(),
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * A string by its hash.
	 *
	 * @param string $domain Text domain.
	 * @param string $context Context.
	 * @param string $text Original text.
	 * @return object|null
	 */
	public function find_by_hash( string $domain, string $context, string $text ): ?object {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$hash = self::compute_hash( $domain, $context, $text );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE original_hash = %s',
				$this->table(),
				$hash
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Bulk insert strings for the scanner (INSERT IGNORE on the original_hash UNIQUE index), marking existing ones seen.
	 * Docs: docs-ai/11-code-rationale.md, "StringRepository: scanner inserts and group ids".
	 *
	 * @param array     $strings Array of string data.
	 * @param bool|null $marked  Set to false when strings of this batch that are
	 *                           already stored could not be re-marked as seen
	 *                           (the existing-hash read or the last_seen_at
	 *                           touch failed); true otherwise.
	 * @phpstan-param array<int, array{domain: string, context: string, original: string, file_path: string, line_number: int}> $strings
	 * @param-out bool $marked
	 * @return int Number of strings inserted.
	 */
	public function bulk_insert( array $strings, ?bool &$marked = null ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$marked = true;

		if ( empty( $strings ) ) {
			return 0;
		}

		// Hashes, deduplicated within the batch.
		$hashed = [];

		foreach ( $strings as $string ) {
			$hash = self::compute_hash(
				$string['domain'] ?? 'default',
				$string['context'] ?? '',
				$string['original']
			);

			// A duplicate within the batch.
			if ( isset( $hashed[ $hash ] ) ) {
				continue;
			}

			$string['_hash'] = $hash;
			$hashed[ $hash ] = $string;
		}

		if ( empty( $hashed ) ) {
			return 0;
		}

		// Existing hashes in one query.
		$hash_list    = array_keys( $hashed );
		$placeholders = implode( ',', array_fill( 0, count( $hash_list ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT original_hash FROM %i WHERE original_hash IN ({$placeholders})",
				$this->table(),
				...$hash_list
			)
		);

		// [] is also a failed query: read as "none exist" it would skip the seen touch and insert the batch blind.
		if ( '' !== $this->wpdb->last_error ) {
			$marked = false;

			return 0;
		}

		$existing_set = array_flip( $existing );

		// A row whose stored context lost its tags (its hash has them) gets the scanned context back. Compared here, byte
		// for byte: a PAD SPACE collation reads "button" and "button " as equal.
		$queued = false;

		foreach ( $existing as $hash ) {
			$context = is_string( $hash ) ? ( $hashed[ $hash ]['context'] ?? '' ) : '';

			if ( '' === $context || sanitize_text_field( $context ) === $context ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored = $this->wpdb->get_var( (string) $this->wpdb->prepare( 'SELECT context FROM %i WHERE original_hash = %s', $this->table(), $hash ) );

			// A context longer than the column is stored cut: no repair.
			if ( ! is_string( $stored ) || $stored === $context || mb_substr( $context, 0, 191 ) === $stored ) {
				continue;
			}

			// Compiled files hold the old context key: their regeneration is queued first, and a refused queue leaves
			// the row for the next scan.
			if ( ! $queued && ! self::queue_files_regeneration() ) {
				continue;
			}

			$queued = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( (string) $this->wpdb->prepare( 'UPDATE %i SET context = %s WHERE original_hash = %s', $this->table(), $context, $hash ) );
		}

		// A regeneration that started before these updates may have read the old contexts: one that has not started yet.
		if ( $queued ) {
			self::queue_files_regeneration( true );
		}

		// Mark existing strings seen (the mark half of the GC's mark-and-sweep); strings the scanner stops finding age out.
		if ( ! empty( $existing ) ) {
			$touch_placeholders = implode( ',', array_fill( 0, count( $existing ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$touched = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE %i SET last_seen_at = CURRENT_TIMESTAMP WHERE original_hash IN ({$touch_placeholders})",
					$this->table(),
					...$existing
				)
			);

			// Only false fails: MySQL counts changed rows, so a hash touched earlier in the same second reports 0.
			if ( false === $touched ) {
				$marked = false;
			}
		}

		// Only new strings.
		$new_strings = [];

		foreach ( $hashed as $hash => $string ) {
			if ( ! isset( $existing_set[ $hash ] ) ) {
				$new_strings[] = $string;
			}
		}

		if ( empty( $new_strings ) ) {
			return 0;
		}

		// Their groups first, in one query.
		$groups_table = Schema::table( 'translation_groups' );
		$count        = count( $new_strings );

		$group_ph   = implode( ',', array_fill( 0, $count, '(%s)' ) );
		$group_args = array_fill( 0, $count, 'string' );
		$sqlite     = defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' );

		// SQLite: the highest group id before the batch, which its first id must exceed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$prior_max = $sqlite ? (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', $groups_table ) ) : 0;

		// Values bound by prepare(), the table as %i; only the generated (%s) list is interpolated.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Generated placeholder list only; table bound via %i and values are %s-bound below.
		$rows_affected = $this->wpdb->query(
			$this->wpdb->prepare( "INSERT INTO %i (type) VALUES {$group_ph}", array_merge( [ $groups_table ], $group_args ) )
		);

		$first_group_id = (int) $this->wpdb->insert_id;
		$inserted       = 0;

		// SQLite gives the last row's id after a multi-row INSERT and has no increment variable: the block runs back from
		// insert_id - (count - 1), verified before it is trusted, else the per-row path below.
		if ( $sqlite && $first_group_id > 0 && $rows_affected === $count ) {
			$first_group_id -= $count - 1;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$fresh = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i g WHERE g.id BETWEEN %d AND %d AND g.type = 'string'
					AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )",
					$groups_table,
					$first_group_id,
					$first_group_id + $count - 1,
					$this->table()
				)
			);

			if ( $first_group_id <= $prior_max || $fresh !== $count ) {
				// Not the expected block: per row below; the batch's rows are left to the empty-group sweep.
				$first_group_id = 0;
			}
		}

		// The batch insert failed or counted wrong: insert one by one.
		if ( $first_group_id === 0 || $rows_affected !== $count ) {
			foreach ( $new_strings as $string ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$group_inserted = $this->wpdb->insert( $groups_table, [ 'type' => 'string' ], [ '%s' ] );

				// After a failed group insert insert_id can hold an earlier row's id: skip this string.
				if ( $group_inserted === false ) {
					continue;
				}

				$group_id = (int) $this->wpdb->insert_id;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $this->wpdb->insert(
					$this->table(),
					[
						'domain'        => sanitize_text_field( $string['domain'] ?? 'default' ),
						// As given, like `original`: the hash and the runtime lookup use it; escaped on output.
						'context'       => (string) ( $string['context'] ?? '' ),
						// Verbatim: the original is the runtime msgid and the hash's input; escaped on output, never sanitized on input.
						'original'      => $string['original'],
						'original_hash' => $string['_hash'],
						'group_id'      => $group_id,
						'file_path'     => sanitize_text_field( $string['file_path'] ?? '' ),
						'line_number'   => absint( $string['line_number'] ?? 0 ),
					],
					[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
				);

				if ( $result !== false ) {
					++$inserted;
				} elseif ( $group_id > 0 ) {
					// Reclaim the group of a string that failed to insert.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$this->wpdb->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
				}
			}

			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return $inserted;
		}

		// A multi-row INSERT's ids step by @@auto_increment_increment (above 1 on Galera / Group Replication).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$increment     = $sqlite ? 1 : max( 1, (int) $this->wpdb->get_var( 'SELECT @@auto_increment_increment' ) );
		$index         = 0;
		$orphan_groups = [];

		foreach ( $new_strings as $string ) {
			$group_id = $first_group_id + ( $index * $increment );
			++$index;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $this->wpdb->insert(
				$this->table(),
				[
					'domain'        => sanitize_text_field( $string['domain'] ?? 'default' ),
					// As given, like `original`: the hash and the runtime lookup use it; escaped on output.
					'context'       => (string) ( $string['context'] ?? '' ),
					'original'      => $string['original'],
					'original_hash' => $string['_hash'],
					'group_id'      => $group_id,
					'file_path'     => sanitize_text_field( $string['file_path'] ?? '' ),
					'line_number'   => absint( $string['line_number'] ?? 0 ),
				],
				[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
			);

			if ( $result !== false ) {
				++$inserted;
			} else {
				// The string insert failed (a hash raced the pre-filter): its group would be left empty.
				$orphan_groups[] = $group_id;
			}
		}

		// Reclaim the groups whose string insert failed.
		if ( $orphan_groups !== [] ) {
			$ids          = array_map( 'intval', $orphan_groups );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $groups_table bound via %i; $placeholders is array_fill('%d', count) bound to $ids via prepare()+spread.
			$this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM %i WHERE id IN ({$placeholders})",
					array_merge( [ $groups_table ], $ids )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $inserted;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Filters: domain, context, search + search_mode, status, language_id, and `missing_translation_language_ids`
	 * (int[]: rows still missing a translation in one of these languages).
	 */
	public function find_all( array $args = [] ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$domain      = sanitize_text_field( $args['domain'] ?? '' );
		$context_f   = is_string( $args['context'] ?? null ) ? $args['context'] : '';
		$search      = sanitize_text_field( $args['search'] ?? '' );
		$status      = sanitize_key( $args['status'] ?? '' );
		$language_id = absint( $args['language_id'] ?? 0 );
		// absint() accepts 0, which would return nothing: non-positive limits use 100.
		$limit = absint( $args['limit'] ?? 100 );
		if ( $limit < 1 ) {
			$limit = 100;
		}
		$offset = absint( $args['offset'] ?? 0 );

		$where      = '1=1';
		$query_args = [];

		if ( $domain !== '' ) {
			$where       .= ' AND s.domain = %s';
			$query_args[] = $domain;
		}

		if ( $context_f !== '' ) {
			$where       .= ' AND s.context = %s';
			$query_args[] = $context_f;
		}

		if ( $search !== '' ) {
			$search_mode = sanitize_key( $args['search_mode'] ?? 'contains' );
			$escaped     = $this->wpdb->esc_like( $search );

			switch ( $search_mode ) {
				case 'exact':
					$where       .= ' AND (s.original = %s OR s.context = %s OR s.file_path = %s)';
					$query_args[] = $search;
					$query_args[] = $search;
					$query_args[] = $search;
					break;

				case 'starts_with':
					$pattern      = $escaped . '%';
					$where       .= ' AND (s.original LIKE %s OR s.context LIKE %s OR s.file_path LIKE %s)';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'ends_with':
					$pattern      = '%' . $escaped;
					$where       .= ' AND (s.original LIKE %s OR s.context LIKE %s OR s.file_path LIKE %s)';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'not_contains':
					$pattern      = '%' . $escaped . '%';
					$where       .= ' AND s.original NOT LIKE %s AND s.context NOT LIKE %s AND s.file_path NOT LIKE %s';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'not_starts_with':
					$pattern      = $escaped . '%';
					$where       .= ' AND s.original NOT LIKE %s AND s.context NOT LIKE %s AND s.file_path NOT LIKE %s';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'not_ends_with':
					$pattern      = '%' . $escaped;
					$where       .= ' AND s.original NOT LIKE %s AND s.context NOT LIKE %s AND s.file_path NOT LIKE %s';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				default: // Contains.
					$pattern      = '%' . $escaped . '%';
					$where       .= ' AND (s.original LIKE %s OR s.context LIKE %s OR s.file_path LIKE %s)';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;
			}
		}

		// Translation status filter.
		$join = '';
		// JOIN placeholders come before the WHERE placeholders in the SQL: collected apart and prepended at prepare() time.
		// Tables bind as %i where they appear.
		$join_args = [];

		if ( $status === 'translated' || $status === 'untranslated' || $status === 'needs_update' ) {
			$links_table  = Schema::table( 'translation_links' );
			$groups_table = Schema::table( 'translation_groups' );

			if ( $status === 'needs_update' ) {
				// EXISTS, not a JOIN: a string with needs_update links in several languages returns once.
				$where .= " AND EXISTS (
					SELECT 1 FROM %i pl_fl
					INNER JOIN %i pl_fg ON pl_fg.id = pl_fl.group_id AND pl_fg.type = 'string'
					WHERE pl_fl.group_id = s.group_id AND pl_fl.status = 'needs_update'";

				$query_args[] = $links_table;
				$query_args[] = $groups_table;

				if ( $language_id > 0 ) {
					$where       .= ' AND pl_fl.language_id = %d';
					$query_args[] = $language_id;
				}

				$where .= ' )';
			} elseif ( $language_id > 0 ) {
				// One language: string_translations on its (string_id, language_id) primary key.
				$st_table = Schema::table( 'string_translations' );

				if ( $status === 'translated' ) {
					$join  = ' INNER JOIN %i pl_fl ON pl_fl.group_id = s.group_id AND pl_fl.language_id = %d';
					$join .= " INNER JOIN %i pl_fg ON pl_fg.id = s.group_id AND pl_fg.type = 'string'";
					$join .= " INNER JOIN %i pl_fst ON pl_fst.string_id = s.id AND pl_fst.language_id = %d AND pl_fst.translation != ''";

					$join_args[] = $links_table;
					$join_args[] = $language_id;
					$join_args[] = $groups_table;
					$join_args[] = $st_table;
					$join_args[] = $language_id;
				} else {
					$where .= " AND s.id NOT IN (
						SELECT s2.id FROM %i s2
						INNER JOIN %i pl_fl2 ON pl_fl2.group_id = s2.group_id AND pl_fl2.language_id = %d
						INNER JOIN %i pl_fg2 ON pl_fg2.id = s2.group_id AND pl_fg2.type = 'string'
						INNER JOIN %i pl_fst2 ON pl_fst2.string_id = s2.id AND pl_fst2.language_id = %d AND pl_fst2.translation != ''
					)";

					$query_args[] = $this->table();
					$query_args[] = $links_table;
					$query_args[] = $language_id;
					$query_args[] = $groups_table;
					$query_args[] = $st_table;
					$query_args[] = $language_id;
				}
			} else {
				// No language: across all languages.
				if ( $status === 'translated' ) {
					// A translation link in any language; EXISTS so a string returns once.
					$where .= " AND EXISTS (
						SELECT 1 FROM %i pl_fl
						INNER JOIN %i pl_fg ON pl_fg.id = pl_fl.group_id AND pl_fg.type = 'string'
						WHERE pl_fl.group_id = s.group_id
					)";

					$query_args[] = $links_table;
					$query_args[] = $groups_table;
				} else {
					// No translation in any language.
					$where .= " AND s.group_id NOT IN (
						SELECT pl_fg3.id FROM %i pl_fg3
						INNER JOIN %i pl_fl3 ON pl_fl3.group_id = pl_fg3.id
						WHERE pl_fg3.type = 'string'
					)";

					$query_args[] = $groups_table;
					$query_args[] = $links_table;
				}
			}
		}

		[ $missing_sql, $missing_args ] = $this->missing_translation_clause( $args );
		$where                         .= $missing_sql;
		$query_args                     = array_merge( $query_args, $missing_args );

		$query_args[] = $limit;
		$query_args[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT s.* FROM %i s {$join} WHERE {$where} ORDER BY s.domain ASC, s.id ASC LIMIT %d OFFSET %d",
				...array_merge( [ $this->table() ], $join_args, $query_args )
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $results ) ? $results : [];
	}

	/**
	 * WHERE fragment of `missing_translation_language_ids` (find_all(), count()): non-blank originals lacking a non-empty
	 * translation in one of the languages, the bulk job's skip-existing rule (StringTranslationRepository::get_many()).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{0: literal-string, 1: array<int, int|string>} SQL fragment (leading " AND ") and its values, in placeholder order.
	 */
	private function missing_translation_clause( array $args ): array {
		$ids = is_array( $args['missing_translation_language_ids'] ?? null ) ? $args['missing_translation_language_ids'] : [];
		$ids = array_values( array_filter( wp_parse_id_list( $ids ) ) );

		if ( $ids === [] ) {
			return [ '', [] ];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = " AND TRIM(s.original) <> '' AND (
			SELECT COUNT(*) FROM %i pl_mst
			WHERE pl_mst.string_id = s.id AND pl_mst.language_id IN ({$placeholders}) AND pl_mst.translation <> ''
		) < %d";

		return [ $sql, array_merge( [ Schema::table( 'string_translations' ) ], $ids, [ count( $ids ) ] ) ];
	}

	/**
	 * Count strings matching the find_all() filters.
	 *
	 * @param array<string, mixed> $args Filter arguments.
	 * @return int
	 */
	public function count( array $args = [] ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$domain      = sanitize_text_field( $args['domain'] ?? '' );
		$context_f   = is_string( $args['context'] ?? null ) ? $args['context'] : '';
		$search      = sanitize_text_field( $args['search'] ?? '' );
		$status      = sanitize_key( $args['status'] ?? '' );
		$language_id = absint( $args['language_id'] ?? 0 );

		$where      = '1=1';
		$query_args = [];

		if ( $domain !== '' ) {
			$where       .= ' AND s.domain = %s';
			$query_args[] = $domain;
		}

		if ( $context_f !== '' ) {
			$where       .= ' AND s.context = %s';
			$query_args[] = $context_f;
		}

		if ( $search !== '' ) {
			$search_mode = sanitize_key( $args['search_mode'] ?? 'contains' );
			$escaped     = $this->wpdb->esc_like( $search );

			switch ( $search_mode ) {
				case 'exact':
					$where       .= ' AND (s.original = %s OR s.context = %s OR s.file_path = %s)';
					$query_args[] = $search;
					$query_args[] = $search;
					$query_args[] = $search;
					break;

				case 'starts_with':
					$pattern      = $escaped . '%';
					$where       .= ' AND (s.original LIKE %s OR s.context LIKE %s OR s.file_path LIKE %s)';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'ends_with':
					$pattern      = '%' . $escaped;
					$where       .= ' AND (s.original LIKE %s OR s.context LIKE %s OR s.file_path LIKE %s)';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'not_contains':
					$pattern      = '%' . $escaped . '%';
					$where       .= ' AND s.original NOT LIKE %s AND s.context NOT LIKE %s AND s.file_path NOT LIKE %s';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'not_starts_with':
					$pattern      = $escaped . '%';
					$where       .= ' AND s.original NOT LIKE %s AND s.context NOT LIKE %s AND s.file_path NOT LIKE %s';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				case 'not_ends_with':
					$pattern      = '%' . $escaped;
					$where       .= ' AND s.original NOT LIKE %s AND s.context NOT LIKE %s AND s.file_path NOT LIKE %s';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;

				default: // Contains.
					$pattern      = '%' . $escaped . '%';
					$where       .= ' AND (s.original LIKE %s OR s.context LIKE %s OR s.file_path LIKE %s)';
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					$query_args[] = $pattern;
					break;
			}
		}

		$join      = '';
		$join_args = [];

		if ( $status === 'translated' || $status === 'untranslated' || $status === 'needs_update' ) {
			$links_table  = Schema::table( 'translation_links' );
			$groups_table = Schema::table( 'translation_groups' );

			if ( $status === 'needs_update' ) {
				// EXISTS, not a JOIN: counted once, not once per link.
				$where .= " AND EXISTS (
					SELECT 1 FROM %i pl_fl
					INNER JOIN %i pl_fg ON pl_fg.id = pl_fl.group_id AND pl_fg.type = 'string'
					WHERE pl_fl.group_id = s.group_id AND pl_fl.status = 'needs_update'";

				$query_args[] = $links_table;
				$query_args[] = $groups_table;

				if ( $language_id > 0 ) {
					$where       .= ' AND pl_fl.language_id = %d';
					$query_args[] = $language_id;
				}

				$where .= ' )';
			} elseif ( $language_id > 0 ) {
				$st_table = Schema::table( 'string_translations' );

				if ( $status === 'translated' ) {
					$join  = ' INNER JOIN %i pl_fl ON pl_fl.group_id = s.group_id AND pl_fl.language_id = %d';
					$join .= " INNER JOIN %i pl_fg ON pl_fg.id = s.group_id AND pl_fg.type = 'string'";
					$join .= " INNER JOIN %i pl_fst ON pl_fst.string_id = s.id AND pl_fst.language_id = %d AND pl_fst.translation != ''";

					$join_args[] = $links_table;
					$join_args[] = $language_id;
					$join_args[] = $groups_table;
					$join_args[] = $st_table;
					$join_args[] = $language_id;
				} else {
					$where .= " AND s.id NOT IN (
						SELECT s2.id FROM %i s2
						INNER JOIN %i pl_fl2 ON pl_fl2.group_id = s2.group_id AND pl_fl2.language_id = %d
						INNER JOIN %i pl_fg2 ON pl_fg2.id = s2.group_id AND pl_fg2.type = 'string'
						INNER JOIN %i pl_fst2 ON pl_fst2.string_id = s2.id AND pl_fst2.language_id = %d AND pl_fst2.translation != ''
					)";

					$query_args[] = $this->table();
					$query_args[] = $links_table;
					$query_args[] = $language_id;
					$query_args[] = $groups_table;
					$query_args[] = $st_table;
					$query_args[] = $language_id;
				}
			} elseif ( $status === 'translated' ) {
					// EXISTS, not a JOIN: counted once, not once per link.
					$where .= " AND EXISTS (
						SELECT 1 FROM %i pl_fl
						INNER JOIN %i pl_fg ON pl_fg.id = pl_fl.group_id AND pl_fg.type = 'string'
						WHERE pl_fl.group_id = s.group_id
					)";

					$query_args[] = $links_table;
					$query_args[] = $groups_table;
			} else {
				$where .= " AND s.group_id NOT IN (
						SELECT pl_fg3.id FROM %i pl_fg3
						INNER JOIN %i pl_fl3 ON pl_fl3.group_id = pl_fg3.id
						WHERE pl_fg3.type = 'string'
					)";

				$query_args[] = $groups_table;
				$query_args[] = $links_table;
			}
		}

		[ $missing_sql, $missing_args ] = $this->missing_translation_clause( $args );
		$where                         .= $missing_sql;
		$query_args                     = array_merge( $query_args, $missing_args );

		// Always apply $join/$where: a status filter can add a clause with no bound args.
		$sql = "SELECT COUNT(*) FROM %i s {$join} WHERE {$where}";

		// The tables bind as %i, so every call goes through prepare(); JOIN args before WHERE args, in SQL order.
		$all_args = array_merge( [ $this->table() ], $join_args, $query_args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( $sql, ...$all_args )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * {@inheritDoc}
	 *
	 * With $in_transaction the group of a string whose insert failed is left to the caller's rollback: nothing follows a
	 * failed statement inside a transaction (SQLite ends it there).
	 *
	 * @param array<string, mixed> $data           domain, context, original, file_path, line_number.
	 * @param bool                 $in_transaction Whether the caller holds an open transaction it rolls back on a failure.
	 */
	public function insert( array $data, bool $in_transaction = false ): int|false {
		$hash = self::compute_hash(
			$data['domain'] ?? 'default',
			$data['context'] ?? '',
			$data['original'] ?? ''
		);

		$groups_table = Schema::table( 'translation_groups' );

		// insert_id can hold an earlier row's id after a failure: check insert()'s return first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( false === $this->wpdb->insert( $groups_table, [ 'type' => 'string' ], [ '%s' ] ) ) {
			return false;
		}
		$group_id = (int) $this->wpdb->insert_id;

		if ( $group_id === 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->table(),
			[
				'domain'        => sanitize_text_field( $data['domain'] ?? 'default' ),
				// As given, like `original`: the hash and the runtime lookup use it; escaped on output.
				'context'       => is_string( $data['context'] ?? null ) ? $data['context'] : '',
				'original'      => $data['original'] ?? '',
				'original_hash' => $hash,
				'group_id'      => $group_id,
				'file_path'     => sanitize_text_field( $data['file_path'] ?? '' ),
				'line_number'   => absint( $data['line_number'] ?? 0 ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
		);

		// The string insert failed: delete the group it would have owned.
		if ( $result === false ) {
			if ( ! $in_transaction ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$this->wpdb->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
			}

			return false;
		}

		return $result !== false ? (int) $this->wpdb->insert_id : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( int $id, array $data ): bool {
		$update = [];
		$format = [];

		if ( isset( $data['file_path'] ) ) {
			$update['file_path'] = sanitize_text_field( $data['file_path'] );
			$format[]            = '%s';
		}

		if ( isset( $data['line_number'] ) ) {
			$update['line_number'] = absint( $data['line_number'] );
			$format[]              = '%d';
		}

		if ( empty( $update ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table(),
			$update,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( int $id ): bool {
		// The owning group, read first: a string group holds one string, and nothing else sweeps it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$group_id = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT group_id FROM %i WHERE id = %d', $this->table(), $id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		if ( $result === false ) {
			return false;
		}

		// Cascade: translations, links, then the empty group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete( Schema::table( 'string_translations' ), [ 'string_id' => $id ], [ '%d' ] );

		// The links too, as gc_stale_strings() does, scoped by type and object_id (the table is polymorphic).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete(
			Schema::table( 'translation_links' ),
			[
				'object_id' => $id,
				'type'      => ObjectType::String->value,
			],
			[ '%d', '%s' ]
		);

		if ( $group_id > 0 ) {
			$groups_table = Schema::table( 'translation_groups' );
			// NOT EXISTS keeps a shared group. One table per DELETE: the SQLite integration's 2.2.x legacy driver rejects
			// `DELETE g FROM`.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM %i
					WHERE id = %d AND type = 'string'
					AND NOT EXISTS ( SELECT 1 FROM %i WHERE group_id = %d )",
					$groups_table,
					$group_id,
					$this->table(),
					$group_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		return true;
	}

	/**
	 * In files mode, queue the one background regeneration of the compiled files (as after a plugin update).
	 *
	 * @param bool $not_started Count only a regeneration that has not started (a running one may have read old rows).
	 * @return bool False when it could not be queued.
	 */
	private static function queue_files_regeneration( bool $not_started = false ): bool {
		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;

		if ( null === $settings || ! method_exists( $settings, 'get' ) || 'files' !== $settings->get( 'string_translation_mode', 'files' ) ) {
			return true;
		}

		$hook = \PerfLocale\Bootstrap::FILES_REGENERATE_HOOK;

		if ( $not_started ) {
			// WP-Cron drops an event before it runs it, maybe in another process: read the cron option again.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'cron', 'options' );

			// Action Scheduler keeps a started action out of 'pending'.
			$pending = \PerfLocale\Background\JobRunnerFactory::action_scheduler_available() && function_exists( 'as_get_scheduled_actions' )
				&& [] !== as_get_scheduled_actions( [ 'hook' => \PerfLocale\Bootstrap::FILES_REGENERATE_HOOK, 'args' => [], 'group' => \PerfLocale\Background\ActionSchedulerRunner::GROUP, 'status' => 'pending', 'per_page' => 1 ], 'ids' );

			return $pending || false !== wp_next_scheduled( $hook, [] ) || \PerfLocale\Background\BackgroundEvents::enqueue( $hook );
		}

		return \PerfLocale\Background\BackgroundEvents::is_scheduled( $hook, [] ) || \PerfLocale\Background\BackgroundEvents::enqueue( $hook );
	}

	/**
	 * The SHA-256 of a string's identity.
	 *
	 * @param string $domain Text domain.
	 * @param string $context Context.
	 * @param string $text Original text.
	 * @return string 64-character hex hash.
	 */
	public static function compute_hash( string $domain, string $context, string $text ): string {
		return hash( 'sha256', $domain . '|' . $context . '|' . $text );
	}

	/**
	 * Register a non-gettext string (settings, WooCommerce emails). Unchanged: nothing. Changed text (same domain and
	 * context): translations move to the new string as needs_update; a migration that moved nothing removes the new
	 * string, so the next registration retries. Docs: docs-ai/11-code-rationale.md, "StringRepository: stale strings and their migration".
	 *
	 * @param string $text Original text.
	 * @param string $domain Text domain.
	 * @param string $context Translation context.
	 * @return void
	 */
	public function register_setting_string( string $text, string $domain, string $context ): void {
		if ( $text === '' ) {
			return;
		}

		// One registration per (domain, context) at a time (5 s TTL): two saves could both insert and race the migration.
		Lock::with(
			'register_setting_string_' . md5( $domain . '|' . $context ),
			5,
			function () use ( $text, $domain, $context ): void {
				$hash = self::compute_hash( $domain, $context, $text );

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				// Unchanged: only mark it seen, so the GC keeps it while its code path runs.
				$exists = $this->wpdb->get_var(
					$this->wpdb->prepare(
						'SELECT id FROM %i WHERE original_hash = %s LIMIT 1',
						$this->table(),
						$hash
					)
				);

				if ( $exists ) {
					$this->wpdb->query(
						$this->wpdb->prepare(
							'UPDATE %i SET last_seen_at = CURRENT_TIMESTAMP WHERE id = %d',
							$this->table(),
							(int) $exists
						)
					);
					return;
				}

				// Old strings with the same domain and context: the source text changed; migrate their translations.
				$old_strings = $this->wpdb->get_results(
					$this->wpdb->prepare(
						'SELECT id, group_id, original FROM %i WHERE domain = %s AND context = %s AND original_hash != %s',
						$this->table(),
						$domain,
						$context,
						$hash
					)
				);

				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				$new_string_id = $this->insert(
					[
						'domain'   => $domain,
						'context'  => $context,
						'original' => $text,
					]
				);

				if ( ! empty( $old_strings ) && $new_string_id ) {
					$migrated = $this->migrate_stale_translations( $old_strings, $new_string_id );

					// Moved nothing: kept, the new row would answer every later registration and nothing would retry; removed, the
					// next save or scan inserts it again and retries.
					if ( ! $migrated ) {
						$this->remove_unmigrated_string( $new_string_id, $hash );
					}

					// The translation moved to a new hash: bust the strings cache or the string reads untranslated until the 6 h TTL.
					$this->cache->invalidate_group( 'perflocale_strings' );
					$this->cache->invalidate_group( 'perflocale_trans' );

					// The hook per replaced string; not when nothing was marked needs_update.
					if ( $migrated ) {
						foreach ( $old_strings as $old ) {
							/** @hook perflocale/string/needs_update Fires when a string's source text changes and translations are marked as needs_update. */
							do_action( 'perflocale/string/needs_update', $new_string_id, (string) $old->original, $text, $domain, $context );
						}
					}
				}
			}
		);
	}

	/**
	 * Delete strings unseen longer than the retention window (mark-and-sweep with the touches in bulk_insert() and
	 * register_setting_string()), cascading to translations; manual contexts and protected domains are kept. Daily.
	 *
	 * @return array{strings: int, translations: int} Row counts deleted.
	 */
	public function gc_stale_strings(): array {
		/**
		 * Filter the days a string may go unseen (no scan rediscovery, no registration) before the GC may delete it.
		 *
		 * @hook perflocale/strings/stale_retention_days
		 * @param int $days Default 90.
		 */
		$days = (int) apply_filters( 'perflocale/strings/stale_retention_days', 90 );

		if ( $days < 1 ) {
			// A non-positive value disables the GC.
			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		// last_seen_at moves only with a full scan: without one inside the window every row looks stale, so no sweep.
		$last_full_scan = (int) get_option( 'perflocale_strings_last_full_scan', 0 );

		if ( $last_full_scan <= 0 || $last_full_scan < time() - ( $days * DAY_IN_SECONDS ) ) {
			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		/**
		 * Filter the contexts whose strings are never deleted by the GC (settings labels and other registrations that may
		 * be touched rarely).
		 *
		 * @hook perflocale/strings/manual_contexts
		 * @param string[] $contexts Default [].
		 */
		$manual_contexts = (array) apply_filters(
			'perflocale/strings/manual_contexts',
			[]
		);

		// Identifiers sanitized to a bare token as well as bound with %i.
		$strings_table      = Schema::sanitize_table( $this->table() );
		$translations_table = Schema::sanitize_table( Schema::table( 'string_translations' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		/**
		 * Filter the domains whose strings are never deleted by the GC; backed by an option so an add-on's protection
		 * outlives its deactivation (its own touch cron stops with it).
		 *
		 * @hook perflocale/strings/protected_domains
		 * @param string[] $domains Option-backed registrations (perflocale_gc_protected_domains).
		 */
		$protected_domains = (array) apply_filters(
			'perflocale/strings/protected_domains',
			array_values( array_filter( array_map( 'strval', (array) get_option( 'perflocale_gc_protected_domains', [] ) ) ) )
		);

		// The stale ids first, for the cascade and the count; at most 5,000 per run.
		$context_filter = '';
		$bind           = [ $days ];

		if ( $manual_contexts !== [] ) {
			$ph             = implode( ',', array_fill( 0, count( $manual_contexts ), '%s' ) );
			$context_filter = " AND context NOT IN ({$ph})";
			$bind           = array_merge( $bind, $manual_contexts );
		}

		if ( $protected_domains !== [] ) {
			$ph              = implode( ',', array_fill( 0, count( $protected_domains ), '%s' ) );
			$context_filter .= " AND domain NOT IN ({$ph})";
			$bind            = array_merge( $bind, $protected_domains );
		}

		$stale_ids = (array) $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM %i
				 WHERE last_seen_at < DATE_SUB( NOW(), INTERVAL %d DAY ){$context_filter}
				 LIMIT 5000",
				array_merge( [ $strings_table ], $bind )
			)
		);

		if ( $stale_ids === [] ) {
			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		$stale_ids = array_map( 'intval', $stale_ids );
		$id_ph     = implode( ',', array_fill( 0, count( $stale_ids ), '%d' ) );

		// Their string groups, read before the delete: gc_empty_groups() skips string groups, so the cascade drops them.
		$groups_table    = Schema::sanitize_table( Schema::table( 'translation_groups' ) );
		$links_table     = Schema::sanitize_table( Schema::table( 'translation_links' ) );
		$stale_group_ids = array_map(
			'intval',
			(array) $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT DISTINCT group_id FROM %i WHERE id IN ({$id_ph}) AND group_id > 0",
					$strings_table,
					...$stale_ids
				)
			)
		);

		// Translations first, then the strings; tables as %i, the id list as %d.
		$translations_deleted = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE string_id IN ({$id_ph})",
				$translations_table,
				...$stale_ids
			)
		);
		$strings_deleted      = (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$id_ph})",
				$strings_table,
				...$stale_ids
			)
		);

		// The emptied string groups and their links; NOT IN keeps a group a surviving string owns. One table per DELETE:
		// the SQLite integration's 2.2.x legacy driver rejects `DELETE g FROM` and mishandles a JOIN.
		$groups_deleted = 0;
		if ( $stale_group_ids !== [] ) {
			$g_ph = implode( ',', array_fill( 0, count( $stale_group_ids ), '%d' ) );

			$this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM %i
					 WHERE group_id IN ({$g_ph})
					   AND group_id IN ( SELECT id FROM %i WHERE type = 'string' )
					   AND group_id NOT IN ( SELECT group_id FROM %i )",
					array_merge( [ $links_table ], $stale_group_ids, [ $groups_table, $strings_table ] )
				)
			);

			$groups_deleted = (int) $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM %i
					 WHERE type = 'string' AND id IN ({$g_ph})
					   AND id NOT IN ( SELECT group_id FROM %i )
					   AND id NOT IN ( SELECT group_id FROM %i )",
					array_merge( [ $groups_table ], $stale_group_ids, [ $strings_table, $links_table ] )
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $strings_deleted > 0 ) {
			// A generation bump of the strings and translations groups (wp_cache_flush_group() is a no-op on some caches).
			$this->cache->invalidate_group( 'perflocale_strings' );
			$this->cache->invalidate_group( 'perflocale_trans' );
			/** @hook perflocale/strings/gc_complete Fires after the daily strings GC. */
			do_action( 'perflocale/strings/gc_complete', $strings_deleted, $translations_deleted );
		}

		return [
			'strings'      => $strings_deleted,
			'translations' => $translations_deleted,
			'groups'       => $groups_deleted,
		];
	}

	/**
	 * Move the old strings' translations to the new string: link its group in every language first, then move the rows
	 * as needs_update; old data is cleaned up only after every move succeeded.
	 *
	 * @param array<int, object> $old_strings Old string rows (id, group_id, original).
	 * @param int                $new_string_id New string's ID.
	 * @return bool False when it stopped before moving or linking anything: the
	 *              new string holds nothing and every translation is still on
	 *              its old string. True otherwise, including after a failed
	 *              move, when an earlier sibling's translations may already be
	 *              on the new string.
	 */
	private function migrate_stale_translations( array $old_strings, int $new_string_id ): bool {
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$trans_table  = Schema::table( 'string_translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$new_group_id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT group_id FROM %i WHERE id = %d',
				$this->table(),
				$new_string_id
			)
		);

		$translations_repo = new StringTranslationRepository( $this->cache );

		// Strings whose group survives vs orphans.
		$grouped_ids = [];
		$group_ids   = [];
		$orphan_ids  = [];
		$all_old_ids = [];

		foreach ( $old_strings as $old ) {
			$old_id        = (int) $old->id;
			$old_group_id  = (int) $old->group_id;
			$all_old_ids[] = $old_id;

			if ( $old_group_id > 0 ) {
				$grouped_ids[] = $old_id;
				$group_ids[]   = $old_group_id;
			} else {
				$orphan_ids[] = $old_id;
			}
		}

		// insert() always gives a group: 0 means the read failed, so there is nothing to link to.
		if ( $new_group_id <= 0 ) {
			$this->log_migration_abort( "reading the new string's group", $new_string_id, $all_old_ids );
			return false;
		}

		// Set when a move fails; every cleanup DELETE below waits for it to stay false.
		$move_failed = false;

		if ( $grouped_ids !== [] ) {
			// Id lists bound as %d, tables as %i; only the generated placeholder lists are
			// interpolated - hence the surrounding phpcs:disable.
			$gid_list = array_values( array_unique( array_map( 'intval', $group_ids ) ) );
			$gid_ph   = implode( ',', array_fill( 0, count( $gid_list ), '%d' ) );
			$sid_ph   = implode( ',', array_fill( 0, count( $grouped_ids ), '%d' ) );

			// Link the new group before moving anything (serving needs the link), in every language an old sibling holds a
			// linked non-empty value in. Through query(), not get_col(), which answers [] or stale rows on a failure.
			$sql  = $this->wpdb->prepare(
				"SELECT DISTINCT st.language_id FROM %i st
				INNER JOIN %i l ON l.language_id = st.language_id AND l.group_id IN ({$gid_ph})
				WHERE st.string_id IN ({$sid_ph}) AND st.translation != ''",
				array_merge( [ $trans_table, $links_table ], $gid_list, $grouped_ids )
			);
			$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false;

			if ( ! is_int( $read ) ) {
				$this->log_migration_abort( 'reading the languages to link', $new_string_id, $all_old_ids );
				return false;
			}

			$translated_langs = array_map( 'intval', $this->wpdb->get_col() );

			if ( $translated_langs !== [] ) {
				// One multi-row INSERT marks every migrated language needs_update; `type` is 'string' (an omitted type is '',
				// invisible to every typed lookup).
				$row_ph = implode( ',', array_fill( 0, count( $translated_langs ), "(%d, %d, %d, 'string', %s, %s)" ) );
				$args   = [];
				foreach ( $translated_langs as $lang_id ) {
					array_push( $args, $new_group_id, $new_string_id, $lang_id, 'needs_update', 'manual' );
				}

				$linked = $this->wpdb->query(
					$this->wpdb->prepare(
						"INSERT INTO %i (group_id, object_id, language_id, type, status, source)
						VALUES {$row_ph}
						ON DUPLICATE KEY UPDATE status = 'needs_update', type = 'string'" . Schema::touch_write_time( [ [ 'status', "'needs_update'", 'text' ], [ 'type', "'string'", 'text' ] ] ),
						array_merge( [ $links_table ], $args )
					)
				);

				if ( false === $linked ) {
					$this->log_migration_abort( 'linking the new string', $new_string_id, $all_old_ids );
					return false;
				}
			}

			// One UPDATE per old string. A failed move keeps that string's rows where they are, so every delete after this
			// loop waits on $move_failed: those rows are the last copy.
			foreach ( $grouped_ids as $old_id ) {
				if ( $translations_repo->move_translations( $old_id, $new_string_id ) < 0 ) {
					$move_failed = true;

					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent data-loss point.
					error_log(
						sprintf(
							'PerfLocale StringRepository: moving translations from string %1$d to %2$d failed; the stale-string cleanup was skipped so the existing translations are not deleted. wpdb error: %3$s',
							$old_id,
							$new_string_id,
							(string) $this->wpdb->last_error
						)
					);

					break;
				}
			}

			// Batched cleanup, skipped after a failed move: it would delete links and groups still serving those translations.
			if ( ! $move_failed ) {
				$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE group_id IN ({$gid_ph})", array_merge( [ $links_table ], $gid_list ) ) );
				$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$gid_ph})", array_merge( [ $groups_table ], $gid_list ) ) );
			}
		}

		if ( $move_failed ) {
			// Leave everything in place: a migration that did not happen is recoverable, a half-applied one is not.
			return true;
		}

		// Orphaned strings: drop their translations.
		foreach ( $orphan_ids as $old_id ) {
			$translations_repo->delete_for_string( $old_id );
		}

		if ( $all_old_ids !== [] ) {
			$id_list = array_map( 'intval', $all_old_ids );
			$id_ph   = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
			$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$id_ph})", array_merge( [ $this->table() ], $id_list ) ) );
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return true;
	}

	/**
	 * Log a stale-string migration that stopped before moving anything; the translations stay on their old strings,
	 * which are not re-marked seen, so the GC deletes them after the window unless their domain is protected.
	 *
	 * @param string          $step          What failed.
	 * @param int             $new_string_id New string's ID.
	 * @param array<int, int> $old_ids       IDs of the old strings that keep the translations.
	 * @return void
	 */
	private function log_migration_abort( string $step, int $new_string_id, array $old_ids ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent data-loss point.
		error_log(
			sprintf(
				'PerfLocale StringRepository: %1$s failed while migrating translations to string %2$d; nothing was moved or deleted. The translations stay on string(s) %3$s under the old text and are not served under the new text until a later migration moves them. If none does, the stale-string GC deletes those strings, with their translations, once they have gone unseen for the retention window, unless their domain is protected. wpdb error: %4$s',
				$step,
				$new_string_id,
				implode( ', ', $old_ids ),
				(string) $this->wpdb->last_error
			)
		);
	}

	/**
	 * Remove the string register_setting_string() inserted after its migration moved nothing, only while it still has
	 * this text and group and holds no translation or link; then its group unless a link reached it. A failed removal is
	 * logged and leaves the row.
	 *
	 * @param int    $string_id String inserted by this registration.
	 * @param string $hash      Its original_hash.
	 * @return void
	 */
	private function remove_unmigrated_string( int $string_id, string $hash ): void {
		// One table per DELETE: the SQLite integration's 2.2.x legacy driver mishandles a DELETE holding JOIN.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$group_id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT group_id FROM %i WHERE id = %d', $this->table(), $string_id ) );
		$removed  = false;

		if ( '' === $this->wpdb->last_error ) {
			$group_id = (int) $group_id;
			$sql      = $this->wpdb->prepare(
				'DELETE FROM %i
				WHERE id = %d AND original_hash = %s AND group_id = %d
				  AND NOT EXISTS ( SELECT 1 FROM %i WHERE group_id = %d )
				  AND NOT EXISTS ( SELECT 1 FROM %i WHERE string_id = %d )',
				$this->table(),
				$string_id,
				$hash,
				$group_id,
				Schema::table( 'translation_links' ),
				$group_id,
				Schema::table( 'string_translations' ),
				$string_id
			);
			$removed  = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false;
		}

		if ( false !== $removed ) {
			$sql = 1 === (int) $removed && $group_id > 0 ? $this->wpdb->prepare(
				"DELETE FROM %i WHERE id = %d AND type = 'string' AND NOT EXISTS ( SELECT 1 FROM %i WHERE group_id = %d )",
				Schema::table( 'translation_groups' ),
				$group_id,
				Schema::table( 'translation_links' ),
				$group_id
			) : null;

			if ( is_string( $sql ) && '' !== $sql ) {
				$this->wpdb->query( $sql );
			}

			return;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent data-loss point.
		error_log(
			sprintf(
				'PerfLocale StringRepository: string %1$d could not be removed after its migration stopped, so registering the same text again does not retry the migration; only another change to the source text does. wpdb error: %2$s',
				$string_id,
				(string) $this->wpdb->last_error
			)
		);
	}
}
