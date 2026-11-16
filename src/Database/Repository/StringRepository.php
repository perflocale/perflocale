<?php
/**
 * String repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Admin\ImportLock;
use PerfLocale\Cache\CacheManager;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Contract\RepositoryInterface;
use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Enum\SourceType;
use PerfLocale\Strings\Domains;
use PerfLocale\Strings\StringScopeResult;
use PerfLocale\Strings\StringSetDiff;
use PerfLocale\Strings\StrIdentMigration;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for perflocale_strings: scanned and registered strings, deduplicated by a SHA-256 hash.
 */
final class StringRepository implements RepositoryInterface {

	/** Rows per position-rewrite statement in sync_string_scope(). */
	public const POSITION_BATCH = 200;

	/** Rows per removal chunk: a sync deletes one chunk and hands the rest to the `global_fields_sync` job. */
	public const REMOVAL_CHUNK = 500;

	/** Longest context the strings table holds (Schema: VARCHAR(191)). */
	public const CONTEXT_MAX_BYTES = 191;

	/** Prefix of the per-scope option of deferred removals, written only under that scope's lock; never autoloaded. */
	public const PENDING_REMOVALS_PREFIX = 'perflocale_scope_removals_';

	/**
	 * Option recording, per plugin version, that the four string tables are InnoDB: a deleting sync relies on
	 * ROLLBACK and refuses otherwise. Only a positive answer is stored; never autoloaded.
	 */
	public const ENGINE_OPTION = 'perflocale_string_tables_engine';

	/** Base lock TTL: the scope SELECT, batched position rewrites and one removal chunk; each job chunk takes it. */
	public const SCOPE_LOCK_TTL = 10;

	/** Longest lock TTL of a synchronous sync. */
	public const SCOPE_LOCK_TTL_MAX = 300;

	/** Members per second of lock TTL added to the base (an edit is a handful of statements). */
	public const SCOPE_LOCK_MEMBERS_PER_SECOND = 50;

	/** Characters of a refused text or context kept in its reason. */
	public const REFUSED_PREVIEW_CHARS = 100;

	/**
	 * An original that is more than spaces, tabs and line breaks (BLANK_CHARS): what the bulk job sends. SQL TRIM()
	 * strips spaces only; compared as bytes, as a collation equals NUL or a no-break space to ''. NUL is not blank:
	 * SQLite's REPLACE() cannot match it.
	 */
	private const NOT_BLANK = " AND CAST( TRIM( REPLACE( REPLACE( REPLACE( REPLACE( s.original, CHAR(9), '' ), CHAR(10), '' ), CHAR(13), '' ), CHAR(11), '' ) ) AS BINARY ) <> CAST( '' AS BINARY )";

	/**
	 * The characters a blank original consists of, for trim(); the same set as NOT_BLANK.
	 */
	public const BLANK_CHARS = " \t\n\r\x0B";

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * The connection marker of this repository's own transaction (scope edits, delete_cascade(), copy_held()), MySQL only.
	 *
	 * @var ConnectionGuard|null
	 */
	private ?ConnectionGuard $tx_guard = null;

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
	 * A string by its hash; read only. Until the identity switch-over is `done`, also its other-form row, the hash first.
	 *
	 * @param string $domain Text domain.
	 * @param string $context Context.
	 * @param string $text Original text.
	 * @return object|null
	 */
	public function find_by_hash( string $domain, string $context, string $text ): ?object {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$hash  = self::identity_hashes( $domain, $context, $text );
		$state = get_option( 'perflocale_str_ident' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE original_hash IN (%s, %s) ORDER BY original_hash != %s LIMIT 2',
				$this->table(),
				$hash[0],
				$hash[ is_string( $state ) && str_contains( $state, '"phase":"done"' ) ? 0 : 1 ],
				$hash[0]
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( ! self::foreign( $row, $hash[0] ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Bulk insert strings for the scanner (INSERT IGNORE on the original_hash UNIQUE index), marking existing ones seen.
	 * Docs: docs-ai/11-code-rationale.md, "StringRepository: scanner inserts and group ids".
	 *
	 * @param array      $strings  Array of string data.
	 * @param bool|null  $marked   Set to false when stored strings of the batch could not be re-marked seen.
	 * @param int|null   $unstored Set to the count not stored as their own row.
	 * @param array|null $held     Pass an array to collect texts whose hash another tuple holds (a row read per
	 *                             rescanned string, so the scanner passes none).
	 * @phpstan-param array<int, array{domain: string, context: string, original: string, file_path: string, line_number: int}> $strings
	 * @phpstan-param array<int, string>|null $held
	 * @param-out bool $marked
	 * @param-out int $unstored
	 * @param-out array<int, string>|null $held
	 * @return int Number of strings inserted.
	 */
	public function bulk_insert( array $strings, ?bool &$marked = null, ?int &$unstored = null, ?array &$held = null ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$marked   = true;
		$unstored = 0;
		$failed   = [];

		if ( empty( $strings ) ) {
			return 0;
		}

		// Hashes, deduplicated within the batch.
		$hashed = [];

		foreach ( $strings as $string ) {
			if ( self::refused( $string['domain'], $string['context'], $string['original'] ) ) {
				++$unstored;
				continue;
			}

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

		// Other-form rows of the batch move to their new hash first; a failed move is a failed read.
		if ( ! StrIdentMigration::adopt( $hashed ) ) {
			$marked = false;

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

		StrIdentMigration::repair_contexts( $this->wpdb, $this->table(), $existing, $hashed );

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

			// A stored hash is the member's own row only when the row is its (domain, context, text).
			if ( null !== $held ) {
				$members = [];

				foreach ( Scalar::strings( $existing ) as $hash ) {
					if ( isset( $hashed[ $hash ] ) ) {
						$members[ $hash ] = $hashed[ $hash ];
					}
				}

				[ $missing, $texts ] = StrIdentMigration::unstored( $this->wpdb, $this->table(), $members );
				$unstored           += $missing;
				$held                = array_merge( $held, $texts );
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
					$failed[] = $string['_hash'];
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
				} else {
					$failed[] = $string['_hash'];

					// Reclaim the group of a string that failed to insert.
					if ( $group_id > 0 ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$this->wpdb->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
					}
				}
			}

			[ $missing, $texts ] = StrIdentMigration::unstored( $this->wpdb, $this->table(), array_intersect_key( $hashed, array_flip( $failed ) ) );
			$unstored           += $missing;

			if ( null !== $held ) {
				$held = array_merge( $held, $texts );
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
				$failed[]        = $string['_hash'];
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

		[ $missing, $texts ] = StrIdentMigration::unstored( $this->wpdb, $this->table(), array_intersect_key( $hashed, array_flip( $failed ) ) );
		$unstored           += $missing;

		if ( null !== $held ) {
			$held = array_merge( $held, $texts );
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

		[ $domain_sql, $domain_args, $domain_hidden ] = self::domain_clause( $domain, $args );

		if ( $domain_hidden ) {
			return [];
		}

		$where     .= $domain_sql;
		$query_args = array_merge( $query_args, $domain_args );

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
					)" . self::NOT_BLANK;

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
					)" . self::NOT_BLANK;

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
	 * WHERE fragment for the `domain` and `exclude_domains` arguments of find_all() and count().
	 *
	 * `exclude_domains`: domains the user may not see. A filter on one selects nothing; otherwise the exclusion stays
	 * beside the domain filter so both compare under the column collation and no equated spelling slips through.
	 *
	 * @param string               $domain Domain filter ('' = all).
	 * @param array<string, mixed> $args   Query arguments.
	 * @return array{0: literal-string, 1: array<int, string>, 2: bool} SQL fragment (leading " AND "), its values, and whether the filtered domain is excluded.
	 */
	private static function domain_clause( string $domain, array $args ): array {
		$exclude = [];

		foreach ( (array) ( $args['exclude_domains'] ?? [] ) as $excluded ) {
			if ( is_string( $excluded ) && '' !== $excluded ) {
				$exclude[ $excluded ] = $excluded;
			}
		}

		if ( $domain !== '' && isset( $exclude[ $domain ] ) ) {
			return [ '', [], true ];
		}

		$sql  = '';
		$args = [];

		if ( $domain !== '' ) {
			$sql   .= ' AND s.domain = %s';
			$args[] = $domain;
		}

		if ( $exclude !== [] ) {
			$placeholders = implode( ',', array_fill( 0, count( $exclude ), '%s' ) );
			$sql         .= " AND s.domain NOT IN ({$placeholders})";
			$args         = array_merge( $args, array_values( $exclude ) );
		}

		return [ $sql, $args, false ];
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

		$sql = self::NOT_BLANK . " AND (
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

		[ $domain_sql, $domain_args, $domain_hidden ] = self::domain_clause( $domain, $args );

		if ( $domain_hidden ) {
			return 0;
		}

		$where     .= $domain_sql;
		$query_args = array_merge( $query_args, $domain_args );

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
					)" . self::NOT_BLANK;

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
					)" . self::NOT_BLANK;

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
	 * @param int                  $group_id       Set to the new row's group id.
	 */
	public function insert( array $data, bool $in_transaction = false, int &$group_id = 0 ): int|false {
		if ( self::refused( Scalar::str( $data['domain'] ?? 'default' ), Scalar::str( $data['context'] ?? '' ), Scalar::str( $data['original'] ?? '' ) ) ) {
			return false;
		}

		$hash = self::compute_hash(
			$data['domain'] ?? 'default',
			$data['context'] ?? '',
			$data['original'] ?? ''
		);

		$groups_table = Schema::table( 'translation_groups' );

		// In the own transaction both rows carry the marker: on a new connection they insert nothing (0).
		$guard = $this->tx_guard;

		// insert_id can hold an earlier row's id after a failure: check insert()'s return first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$grouped = null === $guard ? $this->wpdb->insert( $groups_table, [ 'type' => 'string' ], [ '%s' ] ) : $guard->insert( $groups_table, [ 'type' => 'string' ], [ '%s' ] );

		if ( false === $grouped || 0 === $grouped ) {
			return false;
		}
		$group_id = (int) $this->wpdb->insert_id;

		if ( $group_id === 0 ) {
			return false;
		}

		$row    = [
			'domain'        => sanitize_text_field( $data['domain'] ?? 'default' ),
			// As given, like `original`: the hash and the runtime lookup use it; escaped on output.
			'context'       => is_string( $data['context'] ?? null ) ? $data['context'] : '',
			'original'      => $data['original'] ?? '',
			'original_hash' => $hash,
			'group_id'      => $group_id,
			'file_path'     => sanitize_text_field( $data['file_path'] ?? '' ),
			'line_number'   => absint( $data['line_number'] ?? 0 ),
		];
		$format = [ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = null === $guard ? $this->wpdb->insert( $this->table(), $row, $format ) : $guard->insert( $this->table(), $row, $format );

		// The string insert failed: delete the group it would have owned.
		if ( $result === false || 0 === $result ) {
			if ( ! $in_transaction ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$this->wpdb->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
			}

			return false;
		}

		return (int) $this->wpdb->insert_id;
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
	public static function queue_files_regeneration( bool $not_started = false ): bool {
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
	 * Whether a tuple is refused on every write path: a bar in the domain or a gettext separator in the context or text
	 * would hash like another tuple, which would then be served in its place. A context may carry a bar.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Original text.
	 * @return bool
	 */
	public static function refused( string $domain, string $context, string $text ): bool {
		return str_contains( $domain, '|' ) || self::holds_separator( $context ) || self::holds_separator( $text );
	}

	/**
	 * Whether a row found by $hash is another tuple's row under one of its own forms (a bar context's 1.0.7.5 hash is a
	 * bar-free tuple's identity), so it is never that hash's string. A row whose columns hash to neither form is kept.
	 *
	 * @param object|array<string, mixed> $row  A strings row with domain, context, original and original_hash.
	 * @param string                      $hash The identity hash looked up.
	 * @return bool
	 */
	public static function foreign( object|array $row, string $hash ): bool {
		$row = (object) $row;
		$own = self::identity_hashes( Scalar::str( Scalar::prop( $row, 'domain' ) ), Scalar::str( Scalar::prop( $row, 'context' ) ), Scalar::str( Scalar::prop( $row, 'original' ) ) );

		return $own[0] !== $hash && in_array( Scalar::str( Scalar::prop( $row, 'original_hash' ) ), $own, true );
	}

	/**
	 * Whether a context or text holds gettext's separators ("\x04" ends a context, "\0" a singular): refused on write.
	 *
	 * @param string $value Context or text.
	 * @return bool
	 */
	public static function holds_separator( string $value ): bool {
		return str_contains( $value, "\x04" ) || str_contains( $value, "\0" );
	}

	/**
	 * The SHA-256 of a string's identity ({@see \PerfLocale\Strings\StringTranslation::identity()}).
	 *
	 * @param string $domain Text domain.
	 * @param string $context Context.
	 * @param string $text Original text.
	 * @return string 64-character hex hash.
	 */
	public static function compute_hash( string $domain, string $context, string $text ): string {
		return \PerfLocale\Strings\StringTranslation::identity_hash( $domain, $context, $text );
	}

	/**
	 * A tuple's hash, then its other form's, which a row still to be moved by the switch-over carries.
	 *
	 * @param string $domain  Text domain.
	 * @param string $context Context.
	 * @param string $text    Original text.
	 * @return array{0: string, 1: string}
	 */
	public static function identity_hashes( string $domain, string $context, string $text ): array {
		return [ self::compute_hash( $domain, $context, $text ), \PerfLocale\Strings\StringTranslation::identity_hash( $domain, $context, $text, true ) ];
	}

	/**
	 * Register a non-gettext string (settings, WooCommerce emails). Unchanged: nothing. Changed text (same domain and
	 * context): translations move to the new string as needs_update; a migration that moved nothing removes the new
	 * string, so the next registration retries. Docs: docs-ai/11-code-rationale.md, "StringRepository: stale strings and their migration".
	 *
	 * @param string $text Original text.
	 * @param string $domain Text domain.
	 * @param string $context Translation context.
	 * @return bool True when registered or unchanged; false when the lock was busy, the insert failed or the
	 *              migration removed the new string.
	 */
	public function register_setting_string( string $text, string $domain, string $context ): bool {
		if ( $text === '' ) {
			return false;
		}

		// One registration per (domain, context) at a time (5 s TTL): two saves could both insert and race the migration.
		$registered = Lock::with(
			'register_setting_string_' . md5( $domain . '|' . $context ),
			5,
			function () use ( $text, $domain, $context ): bool {
				[ $hash, $old ] = self::identity_hashes( $domain, $context, $text );

				// An other-form row of this text moves to its new hash first; a failed move retries on the next save.
				if ( ! StrIdentMigration::adopt( [ $hash => compact( 'domain', 'context' ) + [ 'original' => $text ] ] ) ) {
					return false;
				}

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				// Unchanged: only mark it seen, so the GC keeps it while its code path runs.
				$held = $this->wpdb->get_row(
					$this->wpdb->prepare(
						'SELECT id, domain, context, original, original_hash FROM %i WHERE original_hash = %s LIMIT 1',
						$this->table(),
						$hash
					)
				);

				// Another tuple's row under its own form (a kept twin pair) holds the hash: not this string's row.
				if ( is_object( $held ) && self::foreign( $held, $hash ) ) {
					return false;
				}

				$exists = is_object( $held ) ? Scalar::int( Scalar::prop( $held, 'id' ) ) : 0;

				if ( $exists ) {
					$this->wpdb->query(
						$this->wpdb->prepare(
							'UPDATE %i SET last_seen_at = CURRENT_TIMESTAMP WHERE id = %d',
							$this->table(),
							(int) $exists
						)
					);
					return true;
				}

				// Old strings with the same domain and context: the source text changed; migrate their translations. Both are
				// also compared byte for byte: under a case-insensitive collation another spelling is another setting's row.
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						'SELECT id, group_id, domain, context, original FROM %i WHERE domain = %s AND context = %s AND CAST( domain AS BINARY ) = CAST( %s AS BINARY ) AND CAST( context AS BINARY ) = CAST( %s AS BINARY ) AND original_hash NOT IN (%s, %s)',
						$this->table(),
						$domain,
						$context,
						$domain,
						$context,
						$hash,
						$old
					)
				);

				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				$old_strings = array_values(
					array_filter(
						is_array( $rows ) ? $rows : [],
						static fn( \stdClass $row ): bool => Scalar::str( Scalar::prop( $row, 'domain' ) ) === $domain
							&& Scalar::str( Scalar::prop( $row, 'context' ) ) === $context
					)
				);

				$new_string_id = $this->insert(
					[
						'domain'   => $domain,
						'context'  => $context,
						'original' => $text,
					]
				);

				$migrated = true;

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
						// Files mode keys translations by source text: the moved one is served after a regeneration.
						\PerfLocale\Bootstrap::queue_files_regeneration();

						foreach ( $old_strings as $old ) {
							/** @hook perflocale/string/needs_update Fires when a string's source text changes and translations are marked as needs_update. */
							do_action( 'perflocale/string/needs_update', $new_string_id, (string) $old->original, $text, $domain, $context );
						}
					}
				}

				return false !== $new_string_id && $migrated;
			}
		);

		return true === $registered;
	}

	/**
	 * Bring one scope of a domain in line with the enumerated texts: rows sharing (domain, context) are a set; edits keep
	 * translations as needs_update; removals run only when `$complete`, above REMOVAL_CHUNK deferred to
	 * `global_fields_sync`.
	 *
	 * @param string                                          $domain         Text domain.
	 * @param string                                          $context_prefix The scope: every member context starts with it.
	 * @param array<string, array<int, array<string, mixed>>> $sets           Per context, the members: text, position (file_path), row (line_number).
	 * @param bool                                            $complete       Whether the caller enumerated the whole scope (no cap hit, no read error).
	 * @param array<string, array<string, string>>            $renames        Per context, old text => new text the save-path alignment proved to be edits.
	 * @return StringScopeResult
	 * @phpstan-param array<string, list<array{text: string, position: string, row: int}>> $sets
	 */
	public function sync_string_scope( string $domain, string $context_prefix, array $sets, bool $complete, array $renames = [] ): StringScopeResult {
		if ( '' === $domain || '' === $context_prefix ) {
			return new StringScopeResult( StringScopeResult::DB_ERROR, refused: [ __( 'A scope needs a domain and a context prefix.', 'perflocale' ) ] );
		}

		// Whole segments only (`options|`, not `options`, which `options_fr|` starts with); in a sweepable domain the
		// scope is the first segment alone.
		if ( ! str_ends_with( $context_prefix, '|' ) || ( in_array( $domain, Domains::SWEEPABLE, true ) && 1 !== substr_count( $context_prefix, '|' ) ) ) {
			/* translators: %s: a string context prefix */
			return new StringScopeResult( StringScopeResult::DB_ERROR, refused: [ sprintf( __( '%s: not a scope prefix', 'perflocale' ), self::preview( $context_prefix ) ) ] );
		}

		$result = Lock::with(
			self::scope_lock_name( $domain, $context_prefix ),
			self::scope_lock_ttl( array_sum( array_map( 'count', $sets ) ) ),
			fn(): StringScopeResult => $this->sync_scope_locked( $domain, $context_prefix, $sets, $complete, $renames )
		);

		return $result instanceof StringScopeResult ? $result : StringScopeResult::failed( StringScopeResult::LOCK_BUSY );
	}

	/**
	 * The lock TTL of a synchronous sync; it grows with the members because edits are not batched.
	 *
	 * @param int $members Members of the enumerated set.
	 * @return int Seconds.
	 */
	public static function scope_lock_ttl( int $members ): int {
		return min( self::SCOPE_LOCK_TTL_MAX, self::SCOPE_LOCK_TTL + intdiv( max( 0, $members ), self::SCOPE_LOCK_MEMBERS_PER_SECOND ) );
	}

	/**
	 * Delete the next chunk of the removals a sync of this scope deferred, under the scope's lock as the sync does.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return StringScopeResult `ok` when nothing is left, `deferred` with the rows still pending, `lock_busy` or `db_error`.
	 */
	public function continue_scope_removals( string $domain, string $context_prefix ): StringScopeResult {
		$result = Lock::with(
			self::scope_lock_name( $domain, $context_prefix ),
			self::SCOPE_LOCK_TTL,
			function () use ( $domain, $context_prefix ): StringScopeResult {
				// A failed read is no empty record: stop before any write so a retry continues from the stored one.
				$record = $this->pending_record( $domain, $context_prefix );

				if ( null === $record ) {
					return StringScopeResult::failed( StringScopeResult::DB_ERROR );
				}

				$pending = $record['ids'];
				$copies  = $record['copies'];

				if ( $pending === [] && $copies === [] ) {
					return new StringScopeResult( StringScopeResult::OK );
				}

				$engine = $pending === [] ? null : $this->engine_refusal();

				if ( null !== $engine ) {
					return new StringScopeResult( StringScopeResult::DB_ERROR, refused: [ $engine ] );
				}

				// Pending copies finish before any removal (the source still holds the values); one failing again stops
				// the chunk. A failed record rewrite leaves a superset of the truth, safe for a retry.
				$copied = 0;

				if ( $copies !== [] ) {
					$ends = $this->scope_rows_by_id( $domain, $context_prefix, array_values( array_unique( array_merge( array_keys( $copies ), array_values( $copies ) ) ) ) );

					if ( null === $ends ) {
						return StringScopeResult::failed( StringScopeResult::DB_ERROR );
					}

					$ends_by_id = [];

					foreach ( $ends as $end ) {
						$ends_by_id[ Scalar::int( $end->id ) ] = $end;
					}

					// Each copy only while the record still names it (a Replace import takes the record with it).
					[ $copies, $copy_failed, $copied, $gone ] = $this->retry_copies( $copies, $ends_by_id, fn ( int $to, int $from ): ?bool => $this->record_names_copy( $domain, $context_prefix, $to, $from ) );

					if ( $gone ) {
						if ( $copied > 0 ) {
							$this->after_scope_change( true );
						}

						return new StringScopeResult( StringScopeResult::OK );
					}

					if ( $copy_failed !== [] ) {
						$this->store_pending_removals( $domain, $context_prefix, $pending, $copies, false );
						$refused = [];

						foreach ( $copy_failed as $to ) {
							/* translators: %s: the edited text */
							$refused[] = sprintf( __( '%s: the translations could not be copied again; the next run copies them', 'perflocale' ), self::preview( Scalar::str( $ends_by_id[ $to ]->original ?? '' ) ) );
						}

						return new StringScopeResult( StringScopeResult::DB_ERROR, refused: $refused );
					}
				}

				$chunk = array_slice( $pending, 0, self::REMOVAL_CHUNK );
				$rest  = array_slice( $pending, self::REMOVAL_CHUNK );
				$rows  = $this->scope_rows_by_id( $domain, $context_prefix, $chunk );

				if ( null === $rows ) {
					return StringScopeResult::failed( StringScopeResult::DB_ERROR );
				}

				// A Replace import that committed since the record read took the record with it: its rows are the file's.
				$deleted = $rows === [] ? 0 : $this->delete_scope_rows( $rows, fn (): bool => $this->record_holds( $domain, $context_prefix, $chunk ) );

				if ( null === $deleted ) {
					return new StringScopeResult( StringScopeResult::OK );
				}

				if ( false === $deleted ) {
					return StringScopeResult::failed( StringScopeResult::DB_ERROR );
				}

				// The next list must name what is left; one not stored stops the job (a retry skips deleted ids).
				$stored = $this->store_pending_removals( $domain, $context_prefix, $rest, $copies, false );

				if ( $deleted > 0 || $copied > 0 ) {
					$this->after_scope_change( true );
				}

				if ( ! $stored ) {
					return new StringScopeResult( StringScopeResult::DB_ERROR, deleted: $deleted );
				}

				return new StringScopeResult( $rest === [] ? StringScopeResult::OK : StringScopeResult::DEFERRED, deleted: $deleted, deferred: count( $rest ) );
			}
		);

		return $result instanceof StringScopeResult ? $result : StringScopeResult::failed( StringScopeResult::LOCK_BUSY );
	}

	/**
	 * The rows a sync of this scope deferred and the job has not deleted yet.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return array<int, int> String ids.
	 */
	public function pending_removals( string $domain, string $context_prefix ): array {
		return self::ids_of( get_option( self::pending_removals_option( $domain, $context_prefix ), [] ) );
	}

	/**
	 * The ids a pending record names.
	 *
	 * @param mixed $entry The option value.
	 * @return array<int, int>
	 */
	private static function ids_of( mixed $entry ): array {
		if ( ! is_array( $entry ) || ! is_array( $entry['ids'] ?? null ) ) {
			return [];
		}

		return array_values( array_filter( array_map( [ Scalar::class, 'int' ], $entry['ids'] ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Remove every scope of a domain whose id is not live (a removed widget, an unregistered options page).
	 *
	 * Only after a complete live-id enumeration, never from a save hook; only for {@see Domains::SWEEPABLE} (elsewhere
	 * it would remove every row). A scope with a row created after $enumerated_at is `skipped`.
	 *
	 * @param string                  $domain        Text domain.
	 * @param array<int, string>      $live_ids      The storage ids or option names that exist.
	 * @param int                     $enumerated_at Unix time the live list was enumerated; 0 skips nothing.
	 * @param array<int, string>|null $scope_ids     The domain's scope ids as the caller read them; null reads them here.
	 * @return array<string, StringScopeResult>|null Per orphan prefix, the result of its removal; null when the domain cannot be swept or its scope ids could not be read.
	 */
	public function sweep_orphan_scopes( string $domain, array $live_ids, int $enumerated_at = 0, ?array $scope_ids = null ): ?array {
		if ( ! in_array( $domain, Domains::SWEEPABLE, true ) ) {
			return null;
		}

		$ids = $scope_ids ?? $this->scope_ids( $domain );

		if ( null === $ids ) {
			return null;
		}

		$live    = array_fill_keys( array_map( 'strval', $live_ids ), true );
		$results = [];

		foreach ( $ids as $id ) {
			if ( isset( $live[ $id ] ) ) {
				continue;
			}

			if ( $enumerated_at <= 0 ) {
				$results[ $id . '|' ] = $this->sync_string_scope( $domain, $id . '|', [], true );
				continue;
			}

			// The freshness check and the removal under one scope lock: a save that lands between them is seen.
			$prefix = $id . '|';
			$result = Lock::with(
				self::scope_lock_name( $domain, $prefix ),
				self::scope_lock_ttl( 0 ),
				function () use ( $domain, $prefix, $enumerated_at ): StringScopeResult {
					$since = $this->scope_created_since( $domain, $prefix, $enumerated_at );

					if ( null === $since ) {
						return StringScopeResult::failed( StringScopeResult::DB_ERROR );
					}

					return $since ? StringScopeResult::failed( StringScopeResult::SKIPPED ) : $this->sync_scope_locked( $domain, $prefix, [], true, [] );
				}
			);

			$results[ $prefix ] = $result instanceof StringScopeResult ? $result : StringScopeResult::failed( StringScopeResult::LOCK_BUSY );
		}

		return $results;
	}

	/**
	 * Whether a scope holds a row created after $since, compared on the DB server's clock (the column default's).
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @param int    $since          Unix time.
	 * @return bool|null Null when the read failed.
	 */
	private function scope_created_since( string $domain, string $context_prefix, int $since ): ?bool {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE domain = %s AND CAST( domain AS BINARY ) = CAST( %s AS BINARY ) AND context LIKE %s AND created_at >= DATE_SUB( NOW(), INTERVAL %d SECOND ) LIMIT 1',
				$this->table(),
				$domain,
				$domain,
				$this->wpdb->esc_like( $context_prefix ) . '%',
				// The enumeration's own second counts, plus one for clock skew: a doubt only waits for the next sweep.
				max( 0, time() - $since + 1 )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( '' !== $this->wpdb->last_error ) {
			return null;
		}

		return null !== $row;
	}

	/**
	 * The scope ids (context before the first `|`) present in a domain.
	 *
	 * A failed read is null, never empty: the sweep's mass-removal guards rest on this list.
	 *
	 * @param string $domain Text domain.
	 * @return array<int, string>|null Null when the read failed.
	 */
	public function scope_ids( string $domain ): ?array {
		$sql = $this->wpdb->prepare(
			"SELECT DISTINCT CASE WHEN INSTR(context, '|') > 0 THEN SUBSTR(context, 1, INSTR(context, '|') - 1) ELSE context END FROM %i WHERE domain = %s AND CAST( domain AS BINARY ) = CAST( %s AS BINARY ) AND context LIKE %s",
			$this->table(),
			$domain,
			$domain,
			'%|%'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; the table name binds with %i.
		$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false;

		if ( ! is_int( $read ) ) {
			return null;
		}

		$ids = $this->wpdb->get_col(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The result of the checked read above; no query runs here.

		return array_values( array_filter( Scalar::strings( $ids ), static fn( string $id ): bool => '' !== $id ) );
	}

	/**
	 * Copy one string's translations to another as needs_update, for each language the destination has no non-empty
	 * value in; used when an edited row's old text stays a member of its set.
	 *
	 * @param int $from_id Source string.
	 * @param int $to_id   Destination string.
	 * @return int Rows copied, 0 when there was nothing to copy, or -1 when a write failed.
	 */
	public function copy_translations( int $from_id, int $to_id ): int {
		if ( $from_id <= 0 || $to_id <= 0 || $from_id === $to_id ) {
			return 0;
		}

		$links_table = Schema::table( 'translation_links' );
		$trans_table = Schema::table( 'string_translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$groups     = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT id, group_id FROM %i WHERE id IN (%d, %d)', $this->table(), $from_id, $to_id ),
			ARRAY_A
		);
		$from_group = 0;
		$to_group   = 0;

		foreach ( is_array( $groups ) ? $groups : [] as $group ) {
			$id = Scalar::int( $group['id'] ?? 0 );

			if ( $id === $from_id ) {
				$from_group = Scalar::int( $group['group_id'] ?? 0 );
			} elseif ( $id === $to_id ) {
				$to_group = Scalar::int( $group['group_id'] ?? 0 );
			}
		}

		if ( $to_group <= 0 ) {
			return -1;
		}

		if ( $from_group <= 0 ) {
			return 0;
		}

		// Languages with a linked non-empty source value and no destination value, with the link's provenance;
		// read through query() so a failed read is not "no languages".
		$sql  = $this->wpdb->prepare(
			'SELECT DISTINCT st.language_id, l.source FROM %i st
			INNER JOIN %i l ON l.language_id = st.language_id AND l.group_id = %d
			WHERE st.string_id = %d AND LENGTH( st.translation ) > 0
			  AND NOT EXISTS ( SELECT 1 FROM %i d WHERE d.string_id = %d AND d.language_id = st.language_id AND LENGTH( d.translation ) > 0 )',
			$trans_table,
			$links_table,
			$from_group,
			$from_id,
			$trans_table,
			$to_id
		);
		$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false;

		if ( ! is_int( $read ) ) {
			return -1;
		}

		$sources = self::link_sources( $this->wpdb->get_col( null, 0 ), $this->wpdb->get_col( null, 1 ) );

		if ( $sources === [] ) {
			return 0;
		}

		$languages = array_keys( $sources );
		$guarded   = $this->guarded();

		// Link first (both serving paths join a value to its link); the link keeps the source's provenance.
		if ( false === $this->link_needs_update( $to_group, $to_id, $sources ) ) {
			return -1;
		}

		// An empty destination value would block the INSERT IGNORE below: delete it first. LENGTH(): under a PAD SPACE
		// collation a value of spaces equals ''.
		$lang_ph = implode( ',', array_fill( 0, count( $languages ), '%d' ) );
		$cleared = $this->run(
			$this->wpdb->prepare(
				"DELETE FROM %i WHERE string_id = %d AND LENGTH( translation ) = 0 AND language_id IN ({$lang_ph})",
				array_merge( [ $trans_table, $to_id ], $languages )
			) . $guarded
		);

		if ( false === $cleared ) {
			return -1;
		}

		$copied = $this->run(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO %i (string_id, language_id, translation, extra_forms)
				SELECT %d, language_id, translation, extra_forms FROM %i
				WHERE string_id = %d AND LENGTH( translation ) > 0 AND language_id IN ({$lang_ph})",
				array_merge( [ $trans_table, $to_id, $trans_table, $from_id ], $languages )
			) . $guarded
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return false === $copied ? -1 : (int) $copied;
	}

	/**
	 * Link a string in each language as needs_update, keeping each new link's provenance, in one statement (two on the
	 * legacy SQLite driver); in the own transaction the rows are selected under the marker, so a statement resent on a
	 * new connection writes nothing.
	 *
	 * @param int                $group   The string's group.
	 * @param int                $object  The string id.
	 * @param array<int, string> $sources Language id => link source.
	 * @return int|false Rows affected, or false.
	 */
	private function link_needs_update( int $group, int $object, array $sources ): int|false {
		$guarded = $this->guarded();
		$args    = [];

		foreach ( $sources as $language_id => $source ) {
			array_push( $args, $group, $object, $language_id, $source );
		}

		$values = 'VALUES ' . implode( ',', array_fill( 0, count( $sources ), "(%d, %d, %d, 'string', 'needs_update', %s)" ) );
		$touch  = Schema::touch_write_time( [ [ 'status', "'needs_update'", 'text' ], [ 'type', "'string'", 'text' ] ] );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder lists and the marker only.
		// The legacy SQLite translator turns ON DUPLICATE KEY UPDATE into one ON CONFLICT over both unique keys' columns,
		// which SQLite refuses: insert the missing links, then mark the group's.
		if ( ! ConnectionGuard::applies() && \PerfLocale\Resync\Store::legacy_sqlite() ) {
			$table    = Schema::table( 'translation_links' );
			$inserted = $this->run( $this->wpdb->prepare( "INSERT IGNORE INTO %i (group_id, object_id, language_id, type, status, source) {$values}", array_merge( [ $table ], $args ) ) );
			$lang_ph  = implode( ',', array_fill( 0, count( $sources ), '%d' ) );
			$marked   = false === $inserted ? false : $this->run( $this->wpdb->prepare( "UPDATE %i SET status = 'needs_update', type = 'string'{$touch} WHERE group_id = %d AND language_id IN ({$lang_ph})", array_merge( [ $table, $group ], array_keys( $sources ) ) ) );

			return false === $marked ? false : $inserted;
		}

		$rows = '' === $guarded
			? $values
			: "SELECT v.g, v.o, v.l, 'string', 'needs_update', v.s FROM ( SELECT %d AS g, %d AS o, %d AS l, %s AS s"
				. str_repeat( ' UNION ALL SELECT %d, %d, %d, %s', count( $sources ) - 1 ) . ' ) AS v WHERE 1 = 1';

		$sql = $this->wpdb->prepare(
			"INSERT INTO %i (group_id, object_id, language_id, type, status, source) {$rows}",
			array_merge( [ Schema::table( 'translation_links' ) ], $args )
		);

		return is_string( $sql ) && '' !== $sql ? $this->run( $sql . $guarded . " ON DUPLICATE KEY UPDATE status = 'needs_update', type = 'string'" . $touch ) : false;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * The lock name of a scope: the synchronous sync and every job chunk take it.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return string
	 */
	public static function scope_lock_name( string $domain, string $context_prefix ): string {
		return 'string_scope_' . self::scope_key( $domain, $context_prefix );
	}

	/**
	 * The key of a scope in its lock name and its pending-removals option.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return string
	 */
	private static function scope_key( string $domain, string $context_prefix ): string {
		return md5( $domain . '|' . $context_prefix );
	}

	/**
	 * The option holding the removals a sync of this scope deferred.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return string
	 */
	public static function pending_removals_option( string $domain, string $context_prefix ): string {
		return self::PENDING_REMOVALS_PREFIX . self::scope_key( $domain, $context_prefix );
	}

	/**
	 * A text or context shortened for a reason the panel and the job log show.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function preview( string $text ): string {
		return mb_strlen( $text ) > self::REFUSED_PREVIEW_CHARS ? mb_substr( $text, 0, self::REFUSED_PREVIEW_CHARS ) . '...' : $text;
	}

	/**
	 * The sync, under the scope's lock.
	 *
	 * @param string                                          $domain         Text domain.
	 * @param string                                          $context_prefix The scope.
	 * @param array<string, array<int, array<string, mixed>>> $sets           Members per context.
	 * @param bool                                            $complete       Whether the whole scope was enumerated.
	 * @param array<string, array<string, string>>            $renames        Proven edits per context.
	 * @return StringScopeResult
	 */
	private function sync_scope_locked( string $domain, string $context_prefix, array $sets, bool $complete, array $renames ): StringScopeResult {
		$refused = [];
		$members = [];

		foreach ( $sets as $context => $list ) {
			$context = (string) $context;

			if ( ! str_starts_with( $context, $context_prefix ) ) {
				/* translators: %s: a string context */
				$refused[] = sprintf( __( '%s: outside the scope', 'perflocale' ), self::preview( $context ) );
				continue;
			}

			if ( strlen( $context ) > self::CONTEXT_MAX_BYTES ) {
				/* translators: 1: a string context, 2: the longest context allowed, in bytes */
				$refused[] = sprintf( __( '%1$s: longer than %2$d bytes', 'perflocale' ), self::preview( $context ), self::CONTEXT_MAX_BYTES );
				continue;
			}

			// A context sanitize_text_field() alters never matches its stored row (inserted and removed every sync).
			if ( sanitize_text_field( $context ) !== $context ) {
				/* translators: %s: a string context */
				$refused[] = sprintf( __( '%s: holds characters a context cannot carry', 'perflocale' ), self::preview( $context ) );
				continue;
			}

			foreach ( $list as $member ) {
				$text = Scalar::str( $member['text'] ?? '' );

				if ( '' === $text ) {
					continue;
				}

				// The position is compared against its stored form.
				$members[ $context ][] = [
					'text'     => $text,
					'position' => sanitize_text_field( Scalar::str( $member['position'] ?? '' ) ),
					'row'      => max( 0, Scalar::int( $member['row'] ?? 0 ) ),
				];
			}
		}

		// A refused context's rows may be stored in another form: nothing is removed on its account.
		if ( $complete && $refused !== [] ) {
			$complete  = false;
			$refused[] = __( 'Nothing was removed: a context was refused.', 'perflocale' );
		}

		// An import replaces the rows this sync reads: none may run, and one that starts later stops the writes.
		$import = ImportLock::fence_generation();

		if ( null === $import ) {
			return new StringScopeResult( StringScopeResult::DB_ERROR, refused: $refused );
		}

		if ( ImportLock::counting( $import ) ) {
			return StringScopeResult::failed( StringScopeResult::LOCK_BUSY );
		}

		$old = $this->scope_rows( $domain, $context_prefix );

		if ( null === $old ) {
			return new StringScopeResult( StringScopeResult::DB_ERROR, refused: $refused );
		}

		$by_id    = [];
		$old_rows = [];
		$failed   = false;
		$inserted = 0;
		$migrated = 0;
		$moved    = false;

		foreach ( $old as $row ) {
			$id           = Scalar::int( $row->id );
			$by_id[ $id ] = $row;
			$old_rows[]   = [
				'id'       => $id,
				'context'  => Scalar::str( $row->context ),
				'text'     => Scalar::str( $row->original ),
				'position' => Scalar::str( $row->file_path ),
				'row'      => Scalar::int( $row->line_number ),
			];
		}

		$diff = StringSetDiff::diff( $old_rows, $members, $complete, $renames );

		// A deleting sync (a removal, or a moving edit) relies on ROLLBACK: refused before any write unless InnoDB.
		$deletes = $complete && $diff['removed'] !== [];

		foreach ( $diff['edits'] as $edit ) {
			$deletes = $deletes || ! $edit['copy'];
		}

		$engine = $deletes ? $this->engine_refusal() : null;

		if ( null !== $engine ) {
			$refused[] = $engine;

			return new StringScopeResult( StringScopeResult::DB_ERROR, refused: $refused );
		}

		// A failed read is no empty record (its copies could not be derived again): stop before any write.
		$record = $this->pending_record( $domain, $context_prefix );

		if ( null === $record ) {
			return new StringScopeResult( StringScopeResult::DB_ERROR, refused: $refused );
		}

		if ( $this->import_moved( $import ) ) {
			return self::import_stopped( $refused );
		}

		// Copies an earlier sync could not finish, retried before any removal while their source holds the values, each
		// only while the record names it (an import clears it); their reasons wait for the record below.
		[ $copies, $retry_failed, $copied, $gone ] = $this->retry_copies( $record['copies'], $by_id, fn ( int $to, int $from ): ?bool => $this->record_names_copy( $domain, $context_prefix, $to, $from ) );

		if ( $gone ) {
			return self::import_stopped( $refused );
		}

		if ( $retry_failed !== [] ) {
			$failed = true;
		}

		if ( $copied > 0 ) {
			$migrated += $copied;
			$moved     = true;
		}

		// The copies of this sync that failed: new row id => context and text.
		$new_copies = [];

		// Position memory, in batches, only for rows whose position moved.
		$positions = array_values( array_filter( $diff['kept'], static fn( array $kept ): bool => $kept['changed'] ) );

		foreach ( array_chunk( $positions, self::POSITION_BATCH ) as $chunk ) {
			if ( ! $this->rewrite_positions( $chunk ) ) {
				$failed = true;
			}
		}

		// Kept rows are live: one touch keeps the GC from aging them out (a protected domain needs none).
		$kept_ids = array_column( $diff['kept'], 'id' );

		if ( $kept_ids !== [] && ! in_array( $domain, $this->protected_domains(), true ) && ! $this->touch_seen( $kept_ids ) ) {
			$failed = true;
		}

		// Edits: one transaction each (a death mid-edit commits no new row), nested in another owner's open one.
		// The edit's own transaction is not registered, so nothing inside may call TranslationGroupRepository's
		// create_group(), link_object() or delete_cascade(): each would commit the edit halfway.
		foreach ( $diff['edits'] as $edit ) {
			$own = ! TranslationGroupWrites::in_transaction();

			if ( $own && ! $this->begin_own() ) {
				$failed = true;
				/* translators: %s: the edited text */
				$refused[] = sprintf( __( '%s: could not be stored; the edit is retried by the next sync', 'perflocale' ), self::preview( $edit['text'] ) );
				continue;
			}

			// Before any write: an import since the row read stops here; one that starts later waits for the COMMIT.
			if ( $this->import_fenced( $import ) ) {
				$this->end_edit( $own, false );

				return self::import_stopped( $refused );
			}

			// Inside the edit's transaction (own or borrowed): no cleanup statement after a refused insert.
			$group  = 0;
			$new_id = $this->insert(
				[
					'domain'      => $domain,
					'context'     => $edit['context'],
					'original'    => $edit['text'],
					'file_path'   => $edit['position'],
					'line_number' => $edit['row'],
				],
				true,
				$group
			);

			if ( false === $new_id ) {
				$this->end_edit( $own, false );
				$failed = true;
				/* translators: %s: the text that could not be stored */
				$refused[] = sprintf( __( '%s: could not be inserted', 'perflocale' ), self::preview( $edit['text'] ) );
				continue;
			}

			++$inserted;

			if ( $edit['copy'] ) {
				$values = $this->copy_translations( $edit['old_id'], $new_id );

				// New row and link are committed; the copy is recorded for the next sync (the only retry: a rerun
				// has no proof of the edit). When the record cannot be written either, the edit is undone below.
				if ( $values < 0 ) {
					$failed = true;

					// Committed only when the new row is still there (a deadlock rolls the whole edit back); a failed
					// read rolls back too.
					if ( ! $this->end_edit( $own, true === $this->string_exists( $new_id ) ) ) {
						--$inserted;
						/* translators: %s: the edited text */
						$refused[] = sprintf( __( '%s: could not be stored; the edit is retried by the next sync', 'perflocale' ), self::preview( $edit['text'] ) );
						continue;
					}

					$copies[ $new_id ]     = $edit['old_id'];
					$new_copies[ $new_id ] = [
						'context' => $edit['context'],
						'text'    => $edit['text'],
					];
					continue;
				}

				if ( ! $this->end_edit( $own, true ) ) {
					--$inserted;
					$failed = true;
					/* translators: %s: the edited text */
					$refused[] = sprintf( __( '%s: could not be stored; the edit is retried by the next sync', 'perflocale' ), self::preview( $edit['text'] ) );
					continue;
				}

				++$migrated;

				if ( $values > 0 ) {
					$moved = true;
				}

				/**
				 * Fires when a string's source text changes and translations are marked as needs_update.
				 *
				 * @hook perflocale/string/needs_update
				 */
				do_action( 'perflocale/string/needs_update', $new_id, $edit['old_text'], $edit['text'], $domain, $edit['context'] );
				continue;
			}

			$old_row = $by_id[ $edit['old_id'] ] ?? null;

			if ( null === $old_row ) {
				if ( ! $this->end_edit( $own, true ) ) {
					--$inserted;
					$failed = true;
				}

				continue;
			}

			// A stopped migration or failed move rolls back; the undo statements cover what a rollback cannot
			// (another owner's transaction, a table that ignored it).
			if ( ! $this->migrate_stale_translations( [ $old_row ], $new_id, $own, $group ) ) {
				$this->end_edit( $own, false );
				$this->remove_unmigrated_string( $new_id, self::compute_hash( $domain, $edit['context'], $edit['text'] ) );
				--$inserted;
				$failed = true;
				continue;
			}

			// The migration reports true after a failed move too (the old row keeps its translations), and the next
			// complete sync would remove that row: undo the edit instead. A failed read counts as "still there".
			if ( false !== $this->string_exists( Scalar::int( $old_row->id ) ) ) {
				$this->end_edit( $own, false );
				$this->remove_unmoved_edit( $new_id, self::compute_hash( $domain, $edit['context'], $edit['text'] ) );
				--$inserted;
				$failed = true;
				/* translators: %s: the edited text */
				$refused[] = sprintf( __( '%s: the translations could not be moved; the edit is retried by the next sync', 'perflocale' ), self::preview( $edit['text'] ) );
				continue;
			}

			if ( ! $this->end_edit( $own, true ) ) {
				--$inserted;
				$failed = true;
				/* translators: %s: the edited text */
				$refused[] = sprintf( __( '%s: could not be stored; the edit is retried by the next sync', 'perflocale' ), self::preview( $edit['text'] ) );
				continue;
			}

			++$migrated;
			$moved = true;

			/**
			 * Fires when a string's source text changes and translations are marked as needs_update.
			 *
			 * @hook perflocale/string/needs_update
			 */
			do_action( 'perflocale/string/needs_update', $new_id, $edit['old_text'], $edit['text'], $domain, $edit['context'] );
		}

		// New texts.
		if ( $diff['inserts'] !== [] ) {
			$rows = [];

			foreach ( $diff['inserts'] as $insert ) {
				$rows[] = [
					'domain'      => $domain,
					'context'     => $insert['context'],
					'original'    => $insert['text'],
					'file_path'   => $insert['position'],
					'line_number' => $insert['row'],
				];
			}

			$marked    = true;
			$unstored  = 0;
			$held      = [];
			$inserted += $this->bulk_insert( $rows, $marked, $unstored, $held );

			// A refused INSERT fails the sync but the removals stand (they follow from the enumeration); a member
			// whose key another text holds can never be inserted and is named.
			if ( ! $marked || $unstored > 0 ) {
				$failed = true;
			}

			foreach ( $held ?? [] as $text ) {
				/* translators: %s: the text that could not be stored */
				$refused[] = sprintf( __( '%s: could not be stored, another text holds its key', 'perflocale' ), self::preview( $text ) );
			}

			$unstored -= count( $held ?? [] );

			if ( $unstored > 0 ) {
				/* translators: %d: how many new texts */
				$refused[] = sprintf( _n( '%d new text could not be stored; the next sync inserts it', '%d new texts could not be stored; the next sync inserts them', $unstored, 'perflocale' ), $unstored );
			}
		}

		// Removals: only after a complete enumeration, one chunk here; a row a pending copy still reads stays.
		$deleted  = 0;
		$deferred = [];

		if ( $complete && $diff['removed'] !== [] ) {
			$sources  = array_flip( array_values( $copies ) );
			$removed  = array_values( array_filter( $diff['removed'], static fn( int $id ): bool => ! isset( $sources[ $id ] ) ) );
			$chunk    = array_slice( $removed, 0, self::REMOVAL_CHUNK );
			$deferred = array_slice( $removed, self::REMOVAL_CHUNK );
			$rows     = [];

			foreach ( $chunk as $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$rows[] = $by_id[ $id ];
				}
			}

			$removed = $this->delete_scope_rows( $rows, fn(): bool => ! $this->import_moved( $import, true ) );

			if ( null === $removed ) {
				return self::import_stopped( $refused );
			}

			if ( false === $removed ) {
				$failed   = true;
				$deferred = [];
			} else {
				$deleted = $removed;
			}
		}

		// The import cleared the records: none is written after it.
		if ( $this->import_moved( $import ) ) {
			return self::import_stopped( $refused );
		}

		// A kept row leaves the pending list; a complete enumeration replaces the list, an incomplete one keeps it.
		$pending = $record['ids'];

		if ( $complete ) {
			$pending = $deferred;
		} elseif ( $pending !== [] ) {
			$pending = array_values( array_diff( $pending, $kept_ids ) );
		}

		// The record: ids the job reads and copies the next sync finishes. Ids can be derived again, copies cannot,
		// so a failed write is retried with the copies alone.
		$listed   = $this->store_pending_removals( $domain, $context_prefix, $pending, $copies, false );
		$recorded = $listed;

		if ( ! $recorded && $pending !== [] && $copies !== [] ) {
			$recorded = $this->store_pending_removals( $domain, $context_prefix, [], $copies, false );
		}

		// Ids the job would read were not stored: none is claimed; the next complete sync derives them again.
		if ( ! $listed ) {
			$failed    = true;
			$deferred  = [];
			$refused[] = __( 'The deferred removals could not be recorded; the next complete sync of the scope removes them.', 'perflocale' );
		}

		// Nothing written: the earlier record stays with its copies, cleared only when it names a row this sync kept.
		$earlier = $recorded || $this->settle_stale_record( $domain, $context_prefix, $kept_ids );

		foreach ( $new_copies as $new_id => $copy ) {
			if ( $recorded ) {
				/* translators: %s: the edited text */
				$refused[] = sprintf( __( '%s: the translations could not be copied; the next sync of the scope copies them', 'perflocale' ), self::preview( $copy['text'] ) );
				continue;
			}

			// Unrecorded, the copy would never be made: take the new row back; the next sync derives the edit again.
			$this->remove_unmoved_edit( $new_id, self::compute_hash( $domain, $copy['context'], $copy['text'] ) );
			--$inserted;
			/* translators: %s: the edited text */
			$refused[] = sprintf( __( '%s: the translations could not be copied and the retry could not be recorded; the edit is retried by the next sync', 'perflocale' ), self::preview( $copy['text'] ) );
		}

		foreach ( $retry_failed as $to ) {
			$text = self::preview( Scalar::str( $by_id[ $to ]->original ?? '' ) );

			if ( $earlier ) {
				/* translators: %s: the edited text */
				$refused[] = sprintf( __( '%s: the translations could not be copied again; the next sync of the scope copies them', 'perflocale' ), $text );
				continue;
			}

			/* translators: %s: the edited text */
			$refused[] = sprintf( __( '%s: the translations could not be copied again and the retry could not be recorded; translate it again', 'perflocale' ), $text );
		}

		if ( $inserted > 0 || $migrated > 0 || $deleted > 0 ) {
			$this->after_scope_change( $moved || $deleted > 0 );
		}

		$status = StringScopeResult::OK;

		if ( $failed ) {
			$status = StringScopeResult::DB_ERROR;
		} elseif ( $deferred !== [] ) {
			$status = StringScopeResult::DEFERRED;
		}

		return new StringScopeResult(
			$status,
			inserted: $inserted,
			kept: count( $diff['kept'] ),
			migrated: $migrated,
			deleted: $deleted,
			deferred: count( $deferred ),
			refused: $refused
		);
	}

	/**
	 * Whether a counted import took the lock since `$generation` was read or holds it now (a URL-slug write's
	 * uncounted hold is no import); a failed read of the count counts as one.
	 *
	 * @param int  $generation ImportLock::generation() before the sync's first read.
	 * @param bool $locking    Read the count by a locking read (inside a transaction a plain read is its snapshot).
	 * @return bool True as well when the locking read failed.
	 */
	private function import_moved( int $generation, bool $locking = false ): bool {
		if ( ImportLock::counting( $generation ) ) {
			return true;
		}

		if ( ! $locking ) {
			return ImportLock::fence_generation() !== $generation;
		}

		return $this->import_fenced( $generation );
	}

	/**
	 * Whether the import generation moved since `$generation`, by a locking read held to the transaction's end: an
	 * import that starts later waits for it (its generation bump writes that row before the import's first write).
	 *
	 * @param int $generation ImportLock::generation() before the sync's first read.
	 * @return bool True as well when the read failed.
	 */
	private function import_fenced( int $generation ): bool {
		$now = $this->locked_option( ImportLock::GENERATION_OPTION );

		return false === $now || Scalar::int( $now ?? 0 ) !== $generation;
	}

	/**
	 * A sync an import overtook: what it wrote before stands, nothing more is written.
	 *
	 * @param array<int, string> $refused Reasons so far.
	 * @return StringScopeResult
	 */
	private static function import_stopped( array $refused ): StringScopeResult {
		$refused[] = __( 'An import ran during the sync; nothing more was changed. The next sync of the scope runs again.', 'perflocale' );

		return new StringScopeResult( StringScopeResult::DB_ERROR, refused: $refused );
	}

	/**
	 * The old state of a scope: every row of the domain whose context starts with the prefix.
	 *
	 * The domain is also compared byte for byte (a ci collation equates other spellings); the LIKE pattern is
	 * escaped (storage ids carry `_`) and rows are re-checked against the literal prefix.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return array<int, \stdClass>|null Rows (id, group_id, context, original, file_path, line_number, typed), or null when the read failed.
	 */
	private function scope_rows( string $domain, string $context_prefix ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, group_id, domain, context, original, file_path, line_number FROM %i WHERE domain = %s AND CAST( domain AS BINARY ) = CAST( %s AS BINARY ) AND context LIKE %s',
				$this->table(),
				$domain,
				$domain,
				$this->wpdb->esc_like( $context_prefix ) . '%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error ) {
			return null;
		}

		return self::typed_rows( $rows, $domain, $context_prefix );
	}

	/**
	 * Scope rows as typed objects, only those whose domain is the scope's byte for byte and whose context starts
	 * with the literal prefix.
	 *
	 * @param array<int, mixed> $rows           Rows as wpdb returned them (ARRAY_A).
	 * @param string            $domain         Text domain.
	 * @param string            $context_prefix The scope.
	 * @return array<int, \stdClass>
	 */
	private static function typed_rows( array $rows, string $domain, string $context_prefix ): array {
		$out = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$context = Scalar::str( $row['context'] ?? '' );

			if ( Scalar::str( $row['domain'] ?? '' ) !== $domain || ! str_starts_with( $context, $context_prefix ) ) {
				continue;
			}

			$typed              = new \stdClass();
			$typed->id          = Scalar::int( $row['id'] ?? 0 );
			$typed->group_id    = Scalar::int( $row['group_id'] ?? 0 );
			$typed->context     = $context;
			$typed->original    = Scalar::str( $row['original'] ?? '' );
			$typed->file_path   = Scalar::str( $row['file_path'] ?? '' );
			$typed->line_number = Scalar::int( $row['line_number'] ?? 0 );

			$out[] = $typed;
		}

		return $out;
	}

	/**
	 * The rows of a scope among a list of ids: a deferred id whose row has left the scope is not deleted.
	 *
	 * @param string          $domain         Text domain.
	 * @param string          $context_prefix The scope.
	 * @param array<int, int> $ids            String ids.
	 * @return array<int, \stdClass>|null Rows (id, group_id), or null when the read failed.
	 */
	private function scope_rows_by_id( string $domain, string $context_prefix, array $ids ): ?array {
		$ids = array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );

		if ( $ids === [] ) {
			return [];
		}

		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The id list is placeholders only.
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, group_id, domain, context, original, file_path, line_number FROM %i WHERE domain = %s AND CAST( domain AS BINARY ) = CAST( %s AS BINARY ) AND context LIKE %s AND id IN ({$ph})",
				array_merge( [ $this->table(), $domain, $domain, $this->wpdb->esc_like( $context_prefix ) . '%' ], $ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error ) {
			return null;
		}

		return self::typed_rows( $rows, $domain, $context_prefix );
	}

	/**
	 * The ids among a list whose string is not in any of the given domains.
	 *
	 * @param array<int, int>    $ids     String ids.
	 * @param array<int, string> $domains Domains to leave out.
	 * @return array<int, int>
	 */
	public function ids_outside_domains( array $ids, array $domains ): array {
		$ids     = array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
		$domains = array_values( array_filter( $domains, static fn( string $domain ): bool => '' !== $domain ) );

		if ( $ids === [] || $domains === [] ) {
			return $ids;
		}

		$id_ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$domain_ph = implode( ',', array_fill( 0, count( $domains ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Both lists are placeholders only.
		$allowed = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM %i WHERE id IN ({$id_ph}) AND domain NOT IN ({$domain_ph})",
				array_merge( [ $this->table() ], $ids, $domains )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$allowed = array_fill_keys( array_map( [ Scalar::class, 'int' ], $allowed ), true );

		return array_values( array_filter( $ids, static fn( int $id ): bool => isset( $allowed[ $id ] ) ) );
	}

	/**
	 * Mark rows as seen by the enumeration, in one statement.
	 *
	 * @param array<int, int> $ids String ids.
	 * @return bool
	 */
	private function touch_seen( array $ids ): bool {
		$ids = array_values( array_filter( array_map( [ Scalar::class, 'int' ], $ids ), static fn( int $id ): bool => $id > 0 ) );

		if ( $ids === [] ) {
			return true;
		}

		$id_ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The id list is placeholders only.
		$result = $this->run(
			$this->wpdb->prepare(
				"UPDATE %i SET last_seen_at = CURRENT_TIMESTAMP WHERE id IN ({$id_ph})",
				array_merge( [ $this->table() ], $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return false !== $result;
	}

	/**
	 * The domains the GC never considers: the persistent option, under the filter.
	 *
	 * @return array<int, string>
	 */
	private function protected_domains(): array {
		/**
		 * Filter the domains whose strings are never deleted by the GC; backed by an option so an add-on's protection
		 * outlives its deactivation (its own touch cron stops with it).
		 *
		 * @hook perflocale/strings/protected_domains
		 * @param string[] $domains Option-backed registrations (perflocale_gc_protected_domains).
		 */
		$domains = (array) apply_filters(
			'perflocale/strings/protected_domains',
			array_values( array_filter( array_map( 'strval', (array) get_option( 'perflocale_gc_protected_domains', [] ) ) ) )
		);

		return array_values( array_filter( array_map( 'strval', $domains ) ) );
	}

	/**
	 * Rewrite the position memory of up to POSITION_BATCH rows in one statement.
	 *
	 * @param array<int, array<string, mixed>> $rows id, position, row.
	 * @return bool
	 */
	private function rewrite_positions( array $rows ): bool {
		if ( $rows === [] ) {
			return true;
		}

		$path_cases = '';
		$row_cases  = '';
		$path_args  = [];
		$row_args   = [];
		$ids        = [];

		foreach ( $rows as $row ) {
			$id          = Scalar::int( $row['id'] ?? 0 );
			$path_cases .= ' WHEN %d THEN %s';
			$row_cases  .= ' WHEN %d THEN %d';
			array_push( $path_args, $id, sanitize_text_field( Scalar::str( $row['position'] ?? '' ) ) );
			array_push( $row_args, $id, max( 0, Scalar::int( $row['row'] ?? 0 ) ) );
			$ids[] = $id;
		}

		$id_ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The CASE arms and the id list are placeholders only.
		$result = $this->run(
			$this->wpdb->prepare(
				"UPDATE %i SET file_path = CASE id{$path_cases} END, line_number = CASE id{$row_cases} END WHERE id IN ({$id_ph})",
				array_merge( [ $this->table() ], $path_args, $row_args, $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return false !== $result;
	}

	/**
	 * Delete string rows with delete()'s cascade, batched as gc_stale_strings() does (one transaction).
	 *
	 * @param array<int, \stdClass> $rows  Rows carrying id and group_id.
	 * @param callable|null         $check Run inside the cascade's transaction before its first DELETE and before COMMIT; false refuses.
	 * @return int|false|null Rows deleted, false when a statement of the cascade failed (nothing is deleted then), null when $check refused.
	 */
	private function delete_scope_rows( array $rows, ?callable $check = null ): int|false|null {
		$ids    = [];
		$groups = [];

		foreach ( $rows as $row ) {
			$id    = Scalar::int( $row->id ?? 0 );
			$group = Scalar::int( $row->group_id ?? 0 );
			$ids[] = $id;

			if ( $group > 0 ) {
				$groups[ $group ] = $group;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( $ids === [] ) {
			return 0;
		}

		$refused = false;
		$deleted = $this->delete_cascade( $ids, array_values( $groups ), $check, $refused );

		if ( $refused ) {
			return null;
		}

		return false === $deleted ? false : $deleted['strings'];
	}

	/**
	 * Whether a scope's deferred-removal record still names every id of a chunk, read with a locking read.
	 *
	 * @param string          $domain         Text domain.
	 * @param string          $context_prefix The scope.
	 * @param array<int, int> $chunk          String ids.
	 * @return bool
	 */
	private function record_holds( string $domain, string $context_prefix, array $chunk ): bool {
		$entry = $this->locked_record( $domain, $context_prefix );

		return is_array( $entry ) && [] === array_diff( $chunk, self::ids_of( $entry ) );
	}

	/**
	 * Whether the scope's record, read with a locking read inside the caller's transaction, still names the copy.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @param int    $to             Destination id.
	 * @param int    $from           Source id.
	 * @return bool|null Null when the record or the copy is gone, false when the read failed.
	 */
	private function record_names_copy( string $domain, string $context_prefix, int $to, int $from ): ?bool {
		$entry = $this->locked_record( $domain, $context_prefix );

		if ( false === $entry ) {
			return false;
		}

		return is_array( $entry ) && ( self::copies_of( $entry )[ $to ] ?? 0 ) === $from ? true : null;
	}

	/**
	 * The scope's record by a locking read (none on SQLite: one writer at a time), past the options caches.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return mixed The stored value, null when there is no record, false when the read failed.
	 */
	private function locked_record( string $domain, string $context_prefix ): mixed {
		return $this->locked_option( self::pending_removals_option( $domain, $context_prefix ) );
	}

	/**
	 * An option's row by a locking read (none on SQLite: one writer at a time), past the options caches.
	 *
	 * @param string $name Option name.
	 * @return mixed The stored value, null when there is no row, false when the read failed.
	 */
	private function locked_option( string $name ): mixed {
		$locking = ! ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The locking clause is a fixed literal.
		$sql  = $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1' . ( $locking ? ' FOR UPDATE' : '' ), $this->wpdb->options, $name );
		$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; past the options caches.

		if ( ! is_int( $read ) ) {
			return false;
		}

		return $read < 1 ? null : maybe_unserialize( Scalar::str( $this->wpdb->get_var() ) );
	}

	/**
	 * Delete string rows, their values, and the links and groups they leave empty, as one transaction.
	 *
	 * Scope paths reach it only after {@see self::engine_refusal()} found InnoDB. Inside another owner's transaction
	 * the statements nest in it and a failure is that owner's to roll back.
	 *
	 * @param array<int>    $ids     String ids.
	 * @param array<int>    $groups  The string-type groups those rows own.
	 * @param callable|null $check   Run after the transaction began, before the first DELETE and again before COMMIT; false deletes nothing.
	 * @param bool          $refused Set when $check refused.
	 * @return array{strings: int, translations: int, groups: int}|false Rows deleted per table, or false when a statement failed.
	 */
	private function delete_cascade( array $ids, array $groups, ?callable $check = null, bool &$refused = false ): array|false {
		$ids    = array_values( array_unique( array_filter( array_map( [ Scalar::class, 'int' ], $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$groups = array_values( array_unique( array_filter( array_map( [ Scalar::class, 'int' ], $groups ), static fn( int $id ): bool => $id > 0 ) ) );

		if ( $ids === [] ) {
			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		$strings_table = $this->table();
		$trans_table   = Schema::table( 'string_translations' );
		$groups_table  = Schema::table( 'translation_groups' );
		$links_table   = Schema::table( 'translation_links' );
		$id_ph         = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$own           = ! TranslationGroupWrites::in_transaction();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The id lists are placeholders only; the transaction statements and the marker are constant.
		if ( $own && ! $this->begin_own() ) {
			return false;
		}

		$guarded = $this->guarded();
		$refused = null !== $check && true !== $check();

		if ( $refused ) {
			if ( $own ) {
				$this->end_own( false );
			}

			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		$strings      = $this->run( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$id_ph})", array_merge( [ $strings_table ], $ids ) ) . $guarded );
		$translations = false === $strings ? false : $this->run( $this->wpdb->prepare( "DELETE FROM %i WHERE string_id IN ({$id_ph})", array_merge( [ $trans_table ], $ids ) ) . $guarded );
		$links        = false === $translations ? false : 0;
		$emptied      = false === $translations ? false : 0;

		if ( false !== $translations && $groups !== [] ) {
			$g_ph = implode( ',', array_fill( 0, count( $groups ), '%d' ) );

			// One table per DELETE: the SQLite integration's 2.2.x legacy driver rejects `DELETE g FROM` and a JOIN.
			$links = $this->run(
				$this->wpdb->prepare(
					"DELETE FROM %i
					 WHERE group_id IN ({$g_ph})
					   AND group_id IN ( SELECT id FROM %i WHERE type = 'string' )
					   AND group_id NOT IN ( SELECT group_id FROM %i )",
					array_merge( [ $links_table ], $groups, [ $groups_table, $strings_table ] )
				) . $guarded
			);

			$emptied = false === $links ? false : $this->run(
				$this->wpdb->prepare(
					"DELETE FROM %i
					 WHERE type = 'string' AND id IN ({$g_ph})
					   AND id NOT IN ( SELECT group_id FROM %i )
					   AND id NOT IN ( SELECT group_id FROM %i )",
					array_merge( [ $groups_table ], $groups, [ $strings_table, $links_table ] )
				) . $guarded
			);
		}

		if ( false === $strings || false === $translations || false === $links || false === $emptied ) {
			if ( $own ) {
				$this->end_own( false );
			}

			return false;
		}

		// Again before COMMIT: a writer the DELETEs waited for may have committed meanwhile.
		if ( null !== $check && true !== $check() ) {
			if ( ! $own ) {
				return false;
			}

			$this->end_own( false );
			$refused = true;

			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		if ( $own && ! $this->end_own( true ) ) {
			return false;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return [
			'strings'      => $strings,
			'translations' => $translations,
			'groups'       => $emptied,
		];
	}

	/**
	 * End a scope edit's transaction (commit or roll back); inside another owner's transaction there is nothing to end.
	 *
	 * @param bool $own    Whether the edit opened the transaction.
	 * @param bool $commit Commit (true) or roll back (false).
	 * @return bool Whether the edit stands: committed, or nested in the owner's transaction.
	 */
	private function end_edit( bool $own, bool $commit ): bool {
		return $own ? $this->end_own( $commit ) : $commit;
	}

	/**
	 * Why a scope sync may not delete on these tables (ROLLBACK needs InnoDB), or null when it may.
	 *
	 * Only the positive answer is stored (per plugin version, ENGINE_OPTION); anything else is read again next time.
	 *
	 * @return string|null The reason, or null.
	 */
	private function engine_refusal(): ?string {
		$version = defined( 'PERFLOCALE_VERSION' ) ? Scalar::str( PERFLOCALE_VERSION ) : '';
		$stored  = get_option( self::ENGINE_OPTION );

		if ( is_array( $stored ) && ( $stored['version'] ?? null ) === $version && true === ( $stored['innodb'] ?? null ) ) {
			return null;
		}

		$tables = [
			$this->table(),
			Schema::table( 'string_translations' ),
			Schema::table( 'translation_groups' ),
			Schema::table( 'translation_links' ),
		];

		// The InnoDB tables among the four, read through query() so a failed read is not "none".
		$sql  = $this->wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB' AND TABLE_NAME IN (%s, %s, %s, %s)",
			$tables[0],
			$tables[1],
			$tables[2],
			$tables[3]
		);
		$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepare() result.

		if ( ! is_int( $read ) ) {
			return __( 'Nothing was changed: the storage engine of the string tables could not be read. The next sync tries again.', 'perflocale' );
		}

		// Compared without case: with lower_case_table_names the server answers a capitalised prefix in lower case.
		$innodb = array_flip( array_map( 'strtolower', Scalar::strings( $this->wpdb->get_col() ) ) );

		foreach ( $tables as $table ) {
			if ( ! isset( $innodb[ strtolower( $table ) ] ) ) {
				return __( 'Nothing was changed: the string tables do not all use the InnoDB storage engine, so a removal could not be undone after a failure. Convert them to InnoDB.', 'perflocale' );
			}
		}

		update_option(
			self::ENGINE_OPTION,
			[
				'version' => $version,
				'innodb'  => true,
			],
			false
		);

		return null;
	}

	/**
	 * Run a prepared statement; one prepare() could not build is a failed query.
	 *
	 * @param string|null $sql The prepare() result.
	 * @return int|false Rows affected, or false.
	 */
	private function run( ?string $sql ): int|false {
		if ( ! is_string( $sql ) || '' === $sql ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the prepare() result.
		$result = $this->wpdb->query( $sql );

		// A transaction statement may answer true (the SQLite integration's drivers): it ran, no rows.
		return is_int( $result ) ? $result : ( true === $result ? 0 : false );
	}

	/**
	 * Open this repository's own transaction, its connection marked first (MySQL/MariaDB): after a lost connection
	 * the guarded statements match nothing and end_own() rolls back. A refused marker opens nothing on a mysqli link
	 * (wpdb resends on a new one); a drop-in without one (no user variables) runs the unit unmarked.
	 *
	 * @return bool False when nothing is open.
	 */
	private function begin_own(): bool {
		$this->tx_guard = ConnectionGuard::applies() ? new ConnectionGuard( 'string_tx' ) : null;

		if ( null !== $this->tx_guard && ! $this->tx_guard->begin() ) {
			$this->tx_guard = null;

			if ( ConnectionGuard::reconnects() ) {
				return false;
			}
		}

		if ( false === $this->run( 'START TRANSACTION' ) ) {
			$this->tx_guard?->end();
			$this->tx_guard = null;

			return false;
		}

		return true;
	}

	/**
	 * End the own transaction: COMMIT only while it ran on one connection, else (or on a failed COMMIT) ROLLBACK.
	 *
	 * @param bool $commit Commit (true) or roll back (false).
	 * @return bool Whether it committed.
	 */
	private function end_own( bool $commit ): bool {
		$done = $commit && ( null === $this->tx_guard || $this->tx_guard->kept() ) && false !== $this->run( 'COMMIT' );

		// After the COMMIT too: one resent on a new connection committed nothing (the server rolled the unit back).
		$done = $done && ( null === $this->tx_guard || $this->tx_guard->kept() );

		$this->tx_guard?->end();
		$this->tx_guard = null;

		if ( ! $done ) {
			$this->run( 'ROLLBACK' );
		}

		return $done;
	}

	/**
	 * The marker condition a write inside the own transaction carries (' AND @perflocale_string_tx = ...'), else ''.
	 *
	 * @return string
	 */
	private function guarded(): string {
		$condition = null === $this->tx_guard ? '' : $this->tx_guard->condition();

		return '' === $condition ? '' : ' AND ' . $condition;
	}

	/**
	 * Record (or clear) a scope's pending removals and unfinished copies, verified, with one retry.
	 *
	 * On false the caller reports it (the job is never handed unstored ids) and decides what becomes of the old record.
	 *
	 * @param string               $domain           Text domain.
	 * @param string               $context_prefix   The scope.
	 * @param array<int, int>      $ids              String ids.
	 * @param array<int, int>|null $copies           Destination id => source id; null keeps the stored ones.
	 * @param bool                 $clear_on_failure Whether a record that cannot be written is cleared.
	 * @return bool Whether the option holds exactly this record (or is absent, for an empty one).
	 */
	private function store_pending_removals( string $domain, string $context_prefix, array $ids, ?array $copies = null, bool $clear_on_failure = true ): bool {
		$name = self::pending_removals_option( $domain, $context_prefix );
		$ids  = array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );

		if ( null === $copies ) {
			$record = $this->pending_record( $domain, $context_prefix );

			if ( null === $record ) {
				return false;
			}

			$copies = $record['copies'];
		}

		if ( $ids === [] && $copies === [] ) {
			return $this->clear_pending_option( $name );
		}

		$entry = [
			'domain' => $domain,
			'prefix' => $context_prefix,
			'ids'    => $ids,
			'copies' => $copies,
		];

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// False is also what an unchanged value returns: the stored row decides.
			if ( update_option( $name, $entry, false ) ) {
				return true;
			}

			if ( self::stored_option( $name ) === $entry ) {
				return true;
			}
		}

		if ( $clear_on_failure ) {
			$this->clear_pending_option( $name );
		}

		return false;
	}

	/**
	 * After a sync could not write its record: clear the earlier one only when it names a row this sync kept (the
	 * job would delete it); any other stays, with its copies.
	 *
	 * @param string          $domain         Text domain.
	 * @param string          $context_prefix The scope.
	 * @param array<int, int> $kept_ids       The rows this sync kept.
	 * @return bool Whether a record is still stored.
	 */
	private function settle_stale_record( string $domain, string $context_prefix, array $kept_ids ): bool {
		$name   = self::pending_removals_option( $domain, $context_prefix );
		$stored = self::stored_option( $name );

		if ( ! is_array( $stored ) ) {
			return false;
		}

		if ( array_intersect( self::ids_of( $stored ), $kept_ids ) === [] ) {
			return true;
		}

		$this->clear_pending_option( $name );

		return false;
	}

	/**
	 * An option as its row holds it, past the caches: a refused write leaves the old value cached, and
	 * delete_option() lists the name in notoptions even when its DELETE was refused.
	 *
	 * @param string $name The option.
	 * @return mixed The value, or false when the row is absent.
	 */
	private static function stored_option( string $name ): mixed {
		wp_cache_delete( $name, 'options' );
		\PerfLocale\Database\NotOptions::unlist( [ $name ] );

		return get_option( $name );
	}

	/**
	 * Remove a scope's pending record, verified absent, with one retry.
	 *
	 * @param string $name The option.
	 * @return bool Whether the option is absent.
	 */
	private function clear_pending_option( string $name ): bool {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$stored = self::stored_option( $name );

			if ( false === $stored ) {
				return true;
			}

			if ( $attempt < 2 && delete_option( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A scope's record (deferred removals, unfinished copies), read past the options caches; a failed read is null.
	 *
	 * @param string $domain         Text domain.
	 * @param string $context_prefix The scope.
	 * @return array{ids: array<int, int>, copies: array<int, int>}|null Null when the read failed.
	 */
	private function pending_record( string $domain, string $context_prefix ): ?array {
		$sql = $this->wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $this->wpdb->options, self::pending_removals_option( $domain, $context_prefix ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; a checked read of the row, past the options caches.
		$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false;

		if ( ! is_int( $read ) ) {
			return null;
		}

		$entry = $read > 0 ? maybe_unserialize( Scalar::str( $this->wpdb->get_var() ) ) : [];

		return [
			'ids'    => self::ids_of( $entry ),
			'copies' => self::copies_of( $entry ),
		];
	}

	/**
	 * Per language, a copied value's link source from a (language_id, source) read; first row wins, none = `manual`.
	 *
	 * @param array<mixed> $languages Language ids.
	 * @param array<mixed> $sources   Link sources, by the same index.
	 * @return array<int, string> Language id => source.
	 */
	private static function link_sources( array $languages, array $sources ): array {
		$out = [];

		foreach ( $languages as $i => $language ) {
			$language = Scalar::int( $language );
			$source   = Scalar::str( $sources[ $i ] ?? '' );

			if ( $language > 0 && ! isset( $out[ $language ] ) ) {
				$out[ $language ] = '' !== $source ? $source : SourceType::Manual->value;
			}
		}

		return $out;
	}

	/**
	 * Per language, the provenance of the value a migration moves: the first old string (in move order) holding a
	 * value, via its link on its own group; no such link = `manual`.
	 *
	 * @param array<int, int>    $order     Old string ids in the order of the moves.
	 * @param array<int, object> $old       The old strings (id, group_id).
	 * @param array<mixed>       $sids      Column string_id of the read.
	 * @param array<mixed>       $languages Column language_id.
	 * @param array<mixed>       $groups    Column group_id (the link's group).
	 * @param array<mixed>       $sources   Column source (the link's source).
	 * @return array<int, string> Language id => source, for every language to link.
	 */
	private static function moved_link_sources( array $order, array $old, array $sids, array $languages, array $groups, array $sources ): array {
		$rank     = array_flip( $order );
		$group_of = [];

		foreach ( $old as $string ) {
			$group_of[ Scalar::int( Scalar::prop( $string, 'id' ) ) ] = Scalar::int( Scalar::prop( $string, 'group_id' ) );
		}

		$first = [];
		$own   = [];

		foreach ( $sids as $i => $sid ) {
			$sid      = Scalar::int( $sid );
			$language = Scalar::int( $languages[ $i ] ?? 0 );

			if ( $language <= 0 || ! isset( $rank[ $sid ] ) ) {
				continue;
			}

			if ( ! isset( $first[ $language ] ) || $rank[ $sid ] < $rank[ $first[ $language ] ] ) {
				$first[ $language ] = $sid;
			}

			if ( Scalar::int( $groups[ $i ] ?? 0 ) === ( $group_of[ $sid ] ?? -1 ) ) {
				$own[ $sid ][ $language ] = Scalar::str( $sources[ $i ] ?? '' );
			}
		}

		$out = [];

		foreach ( $first as $language => $sid ) {
			$source           = $own[ $sid ][ $language ] ?? '';
			$out[ $language ] = '' !== $source ? $source : SourceType::Manual->value;
		}

		return $out;
	}

	/**
	 * The copies a pending record names.
	 *
	 * @param mixed $entry The option value.
	 * @return array<int, int> Destination id => source id.
	 */
	private static function copies_of( mixed $entry ): array {
		$copies = [];

		foreach ( is_array( $entry ) && is_array( $entry['copies'] ?? null ) ? $entry['copies'] : [] as $to => $from ) {
			$to   = Scalar::int( $to );
			$from = Scalar::int( $from );

			if ( $to > 0 && $from > 0 ) {
				$copies[ $to ] = $from;
			}
		}

		return $copies;
	}

	/**
	 * Retry pending copies whose two rows are still in the scope (others are dropped); a done copy fires the hook.
	 *
	 * @param array<int, int>       $copies Destination id => source id.
	 * @param array<int, \stdClass> $rows   The scope's rows by id.
	 * @param callable|null         $holds  fn( int $to, int $from ): ?bool, run in each copy's transaction before its writes:
	 *                                      true copies, null stops (the copies are gone), false fails the copy.
	 * @return array{0: array<int, int>, 1: array<int, int>, 2: int, 3: bool} The copies still pending, the destination ids whose copy failed again, how many values were copied, and whether $holds stopped.
	 */
	private function retry_copies( array $copies, array $rows, ?callable $holds = null ): array {
		$failed = [];
		$values = 0;

		foreach ( $copies as $to => $from ) {
			if ( ! isset( $rows[ $to ], $rows[ $from ] ) ) {
				unset( $copies[ $to ] );
				continue;
			}

			$copied = null === $holds ? $this->copy_translations( $from, $to ) : $this->copy_held( $from, $to, $holds );

			if ( null === $copied ) {
				return [ [], $failed, $values, true ];
			}

			if ( $copied < 0 ) {
				$failed[] = $to;
				continue;
			}

			unset( $copies[ $to ] );
			$values += $copied;

			/**
			 * Fires when a string's source text changes and translations are marked as needs_update.
			 *
			 * @hook perflocale/string/needs_update
			 */
			do_action( 'perflocale/string/needs_update', $to, Scalar::str( $rows[ $from ]->original ?? '' ), Scalar::str( $rows[ $to ]->original ?? '' ), Scalar::str( $rows[ $to ]->domain ?? '' ), Scalar::str( $rows[ $to ]->context ?? '' ) );
		}

		return [ $copies, $failed, $values, false ];
	}

	/**
	 * Run copy_translations() in one transaction after $holds (inside a caller's transaction, in that one).
	 *
	 * @param int      $from  Source id.
	 * @param int      $to    Destination id.
	 * @param callable $holds fn( int $to, int $from ): ?bool.
	 * @return int|null As copy_translations(); null when $holds answered null (nothing written).
	 */
	private function copy_held( int $from, int $to, callable $holds ): ?int {
		$own = ! TranslationGroupWrites::in_transaction();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
		if ( $own && ! $this->begin_own() ) {
			return -1;
		}

		// SQLite: the write lock before the record read (a no-op UPDATE), so another connection's clear waits for this copy.
		if ( $own && defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE && false === $this->run( $this->wpdb->prepare( 'UPDATE %i SET option_value = option_value WHERE 1 = 0', $this->wpdb->options ) ) ) {
			$this->end_own( false );

			return -1;
		}

		$held   = $holds( $to, $from );
		$copied = true === $held ? $this->copy_translations( $from, $to ) : -1;

		if ( $own && ! $this->end_own( $copied >= 0 ) ) {
			$copied = -1;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $held ? null : $copied;
	}

	/**
	 * After a scope changed: drop the string maps, tell the listeners, and regenerate the files when asked.
	 *
	 * @param bool $regenerate Whether a translation was moved or copied to a new string, or a member was removed.
	 * @return void
	 */
	private function after_scope_change( bool $regenerate ): void {
		$this->cache->invalidate_group( 'perflocale_strings' );
		$this->cache->invalidate_group( 'perflocale_trans' );

		/**
		 * Fires after string translations change.
		 *
		 * @hook perflocale/strings/changed
		 *
		 * @param string $origin What changed the strings ('global_fields').
		 */
		do_action( 'perflocale/strings/changed', 'global_fields' );

		if ( $regenerate ) {
			/**
			 * Regenerate the .l10n.php files (files mode).
			 *
			 * @hook perflocale/strings/regenerate_files
			 *
			 * @param CacheManager $cache The cache the generator reads through.
			 */
			do_action( 'perflocale/strings/regenerate_files', $this->cache );
		}
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

		// Identifier sanitized to a bare token as well as bound with %i.
		$strings_table = Schema::sanitize_table( $this->table() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$protected_domains = $this->protected_domains();

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

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Strings, values and widowed string groups with their links, in one transaction (a refused statement deletes
		// nothing); the cascade's NOT EXISTS guards spare a group a surviving string owns.
		$deleted = $this->delete_cascade( $stale_ids, $stale_group_ids );

		if ( false === $deleted ) {
			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		$strings_deleted      = $deleted['strings'];
		$translations_deleted = $deleted['translations'];
		$groups_deleted       = $deleted['groups'];

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
	 * With `$stop_on_failure` (caller in its own transaction) a failed cleanup statement stops it and the old strings
	 * stay: after a deadlock rollback a later statement would run outside the transaction.
	 *
	 * @param array<int, object> $old_strings     Old string rows (id, group_id, original).
	 * @param int                $new_string_id   New string's ID.
	 * @param bool               $stop_on_failure Whether a failed cleanup statement stops the migration.
	 * @param int                $new_group_id    The new string's group when the caller knows it, else 0 (read here).
	 * @return bool False when it stopped before moving or linking anything (every translation is still on its old
	 *              string); true otherwise, even after a failed move.
	 */
	private function migrate_stale_translations( array $old_strings, int $new_string_id, bool $stop_on_failure = false, int $new_group_id = 0 ): bool {
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$trans_table  = Schema::table( 'string_translations' );

		// In the own transaction every write carries the marker: resent on a new connection it matches nothing.
		$guarded = $this->guarded();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $new_group_id <= 0 ) {
			$new_group_id = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT group_id FROM %i WHERE id = %d',
					$this->table(),
					$new_string_id
				)
			);
		}

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
				"SELECT DISTINCT st.string_id, st.language_id, l.group_id, l.source FROM %i st
				INNER JOIN %i l ON l.language_id = st.language_id AND l.group_id IN ({$gid_ph})
				WHERE st.string_id IN ({$sid_ph}) AND st.translation != ''",
				array_merge( [ $trans_table, $links_table ], $gid_list, $grouped_ids )
			);
			$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false;

			if ( ! is_int( $read ) ) {
				$this->log_migration_abort( 'reading the languages to link', $new_string_id, $all_old_ids );
				return false;
			}

			$sources = self::moved_link_sources( $grouped_ids, $old_strings, $this->wpdb->get_col( null, 0 ), $this->wpdb->get_col( null, 1 ), $this->wpdb->get_col( null, 2 ), $this->wpdb->get_col( null, 3 ) );

			// One INSERT marks every migrated language needs_update; each link keeps the provenance its moved value had.
			if ( $sources !== [] && false === $this->link_needs_update( $new_group_id, $new_string_id, $sources ) ) {
				$this->log_migration_abort( 'linking the new string', $new_string_id, $all_old_ids );
				return false;
			}

			// One UPDATE per old string. A failed move keeps that string's rows where they are, so every delete after this
			// loop waits on $move_failed: those rows are the last copy.
			foreach ( $grouped_ids as $old_id ) {
				// The move's own cleanup DELETE reports no failure; last_error does (wpdb clears it on every query).
				if ( $translations_repo->move_translations( $old_id, $new_string_id, $guarded ) < 0 || ( $stop_on_failure && '' !== $this->wpdb->last_error ) ) {
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
				$unlinked = $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE group_id IN ({$gid_ph})", array_merge( [ $links_table ], $gid_list ) ) . $guarded );

				if ( $stop_on_failure && false === $unlinked ) {
					return true;
				}

				$ungrouped = $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$gid_ph})", array_merge( [ $groups_table ], $gid_list ) ) . $guarded );

				if ( $stop_on_failure && false === $ungrouped ) {
					return true;
				}
			}
		}

		if ( $move_failed ) {
			// Leave everything in place: a migration that did not happen is recoverable, a half-applied one is not.
			return true;
		}

		// Orphaned strings: drop their translations.
		foreach ( $orphan_ids as $old_id ) {
			$translations_repo->delete_for_string( $old_id, $guarded );

			if ( $stop_on_failure && '' !== $this->wpdb->last_error ) {
				return true;
			}
		}

		if ( $all_old_ids !== [] ) {
			$id_list = array_map( 'intval', $all_old_ids );
			$id_ph   = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
			$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$id_ph})", array_merge( [ $this->table() ], $id_list ) ) . $guarded );
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
	 * Whether a string row exists, or null when the read failed.
	 *
	 * Through query(), not get_var(): get_var() answers null ("gone") on a failure, or the previous query's row.
	 *
	 * @param int $string_id String id.
	 * @return bool|null
	 */
	private function string_exists( int $string_id ): ?bool {
		$sql = $this->wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $this->table(), $string_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; the table name binds with %i.
		$read = is_string( $sql ) && '' !== $sql ? $this->wpdb->query( $sql ) : false;

		return is_int( $read ) ? $read > 0 : null;
	}

	/**
	 * Undo a scope edit whose translation move failed: remove the new group's links, then the string and its group
	 * ({@see self::remove_unmigrated_string()}); the old row keeps its translations.
	 *
	 * @param int    $string_id The new string.
	 * @param string $hash      Its original_hash.
	 * @return void
	 */
	private function remove_unmoved_edit( int $string_id, string $hash ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; the table names bind with %i.
		$sql = $this->wpdb->prepare(
			"DELETE FROM %i
			WHERE type = 'string' AND object_id = %d
			AND group_id IN ( SELECT group_id FROM %i WHERE id = %d AND original_hash = %s )",
			Schema::table( 'translation_links' ),
			$string_id,
			$this->table(),
			$string_id,
			$hash
		);

		if ( is_string( $sql ) && '' !== $sql ) {
			$this->wpdb->query( $sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->remove_unmigrated_string( $string_id, $hash );
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
