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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer for the perflocale_strings table.
 *
 * Stores translatable strings discovered by the scanner, with SHA-256
 * hash-based deduplication for efficient lookups.
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
	 * Resolve the full table name for the current blog.
	 *
	 * Computed on every call so that switch_to_blog() flips the prefix
	 * correctly — capturing the name in the constructor would otherwise
	 * pin this instance to whichever blog was active at construction.
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
	 * Find a string by its hash.
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
	 * Bulk insert strings (used by the scanner).
	 *
	 * Uses INSERT IGNORE to skip duplicates based on the original_hash UNIQUE index.
	 *
	 * @param array<int, array{domain: string, context: string, original: string, file_path: string, line_number: int}> $strings Array of string data.
	 * @return int Number of strings inserted.
	 */
	public function bulk_insert( array $strings ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( empty( $strings ) ) {
			return 0;
		}

		// Compute hashes and deduplicate within the batch.
		$hashed = [];

		foreach ( $strings as $string ) {
			$hash = self::compute_hash(
				$string['domain'] ?? 'default',
				$string['context'] ?? '',
				$string['original']
			);

			// Skip duplicates within the same batch.
			if ( isset( $hashed[ $hash ] ) ) {
				continue;
			}

			$string['_hash'] = $hash;
			$hashed[ $hash ] = $string;
		}

		if ( empty( $hashed ) ) {
			return 0;
		}

		// Fetch all existing hashes in one query (batch lookup).
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

		$existing_set = array_flip( $existing );

		// Mark already-existing strings as freshly-seen so the GC's
		// stale-row sweep doesn't evict them. This is the "mark" half of
		// the mark-and-sweep: every scanner-rediscovered hash gets its
		// last_seen_at bumped; rows that the scanner stops finding (e.g.,
		// a plugin was deleted) keep their old timestamp and age out.
		if ( ! empty( $existing ) ) {
			$touch_placeholders = implode( ',', array_fill( 0, count( $existing ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE %i SET last_seen_at = CURRENT_TIMESTAMP WHERE original_hash IN ({$touch_placeholders})",
					$this->table(),
					...$existing
				)
			);
		}

		// Filter to only new strings.
		$new_strings = [];

		foreach ( $hashed as $hash => $string ) {
			if ( ! isset( $existing_set[ $hash ] ) ) {
				$new_strings[] = $string;
			}
		}

		if ( empty( $new_strings ) ) {
			return 0;
		}

		// Batch-insert translation groups first (1 query instead of N).
		$groups_table = Schema::table( 'translation_groups' );
		$count        = count( $new_strings );

		$group_ph   = implode( ',', array_fill( 0, $count, '(%s)' ) );
		$group_args = array_fill( 0, $count, 'string' );

		// Values bound via prepare(); the table name is bound with the %i
		// identifier placeholder, so only the generated (%s) list interpolates.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Generated placeholder list only; table bound via %i and values are %s-bound below.
		$rows_affected = $this->wpdb->query(
			$this->wpdb->prepare( "INSERT INTO %i (type) VALUES {$group_ph}", array_merge( [ $groups_table ], $group_args ) )
		);

		$first_group_id = (int) $this->wpdb->insert_id;
		$inserted       = 0;

		// Safety: if batch insert failed or returned unexpected count, fall back to individual inserts.
		if ( $first_group_id === 0 || $rows_affected !== $count ) {
			foreach ( $new_strings as $string ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$group_inserted = $this->wpdb->insert( $groups_table, [ 'type' => 'string' ], [ '%s' ] );

				// If the group insert failed, insert_id is unreliable (it can
				// hold a PRIOR row's id, not 0, depending on the mysqli driver).
				// Trusting it here would either link the string to the wrong
				// group or, on a subsequent string failure, DELETE a valid
				// earlier group. Skip this string entirely instead.
				if ( $group_inserted === false ) {
					continue;
				}

				$group_id = (int) $this->wpdb->insert_id;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $this->wpdb->insert(
					$this->table(),
					[
						'domain'        => sanitize_text_field( $string['domain'] ?? 'default' ),
						'context'       => sanitize_text_field( $string['context'] ?? '' ),
						// Store the original VERBATIM. It's a source-code gettext
						// literal whose identity must equal the runtime msgid, and
						// original_hash is computed from that raw value — so it is
						// escaped on OUTPUT (esc_html/esc_attr/textContent in the
						// strings admin), never sanitized on input. wp_kses_post
						// here entity-encoded &/</> and stripped tags, corrupting
						// PO/XLIFF export msgids and the admin preview.
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
					// Reclaim the group we just created for a string that
					// failed to insert, so it doesn't widow the groups table.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$this->wpdb->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
				}
			}

			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return $inserted;
		}

		// Map each string to its group ID. A single multi-row INSERT assigns
		// its rows a contiguous block of auto-increment values, but the STEP
		// between them is @@auto_increment_increment — which is > 1 on
		// multi-master clusters (Galera / Group Replication, where it equals
		// the node count). Assuming a step of 1 there would link strings to
		// nonexistent / wrong group_ids. Read the step once and apply it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$increment     = max( 1, (int) $this->wpdb->get_var( 'SELECT @@auto_increment_increment' ) );
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
					'context'       => sanitize_text_field( $string['context'] ?? '' ),
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
				// String insert failed (e.g. a hash collision raced past the
				// pre-filter); its pre-allocated group would otherwise widow.
				$orphan_groups[] = $group_id;
			}
		}

		// Reclaim any groups whose string insert failed - leaving them behind
		// is what historically bloated the groups table with empty rows.
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
	 */
	public function find_all( array $args = [] ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$domain      = sanitize_text_field( $args['domain'] ?? '' );
		$context_f   = sanitize_text_field( $args['context'] ?? '' );
		$search      = sanitize_text_field( $args['search'] ?? '' );
		$status      = sanitize_key( $args['status'] ?? '' );
		$language_id = absint( $args['language_id'] ?? 0 );
		// `absint()` accepts 0 — without the floor below, a caller that
		// explicitly passes `limit=0` (or any non-positive) returns an
		// empty result set even when data exists. Clamp non-positives to
		// the default 100.
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

				default: // contains.
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
		// JOIN placeholders bind BEFORE the WHERE placeholders in the final
		// SQL, so their values are collected separately and prepended to the
		// WHERE values at prepare() time — appending them to $query_args (which
		// already holds domain/context/search) would bind them out of order.
		// Table names ride along as %i identifier placeholders (WP 6.2+) and
		// are pushed at the exact position they appear in the assembled SQL.
		$join_args = [];

		if ( $status === 'translated' || $status === 'untranslated' || $status === 'needs_update' ) {
			$links_table  = Schema::table( 'translation_links' );
			$groups_table = Schema::table( 'translation_groups' );

			if ( $status === 'needs_update' ) {
				// EXISTS (not INNER JOIN) so a string with needs_update links
				// in several languages still returns once — a JOIN would
				// multiply the row and corrupt pagination/counts.
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
				// Filter for a specific language. Joins the dedicated
				// string_translations table on its (string_id, language_id)
				// PRIMARY KEY - index seek instead of a CONCAT-on-options
				// full scan.
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
				// No language selected - filter across ALL languages.
				if ( $status === 'translated' ) {
					// Strings with at least one translation link in any
					// language. EXISTS (not INNER JOIN) so a string translated
					// into several languages still returns once — a JOIN would
					// multiply the row and corrupt pagination/counts.
					$where .= " AND EXISTS (
						SELECT 1 FROM %i pl_fl
						INNER JOIN %i pl_fg ON pl_fg.id = pl_fl.group_id AND pl_fg.type = 'string'
						WHERE pl_fl.group_id = s.group_id
					)";

					$query_args[] = $links_table;
					$query_args[] = $groups_table;
				} else {
					// Strings with no translation in any language.
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
	 * Count strings matching filter criteria.
	 *
	 * @param array<string, mixed> $args Filter arguments (domain, search).
	 * @return int
	 */
	public function count( array $args = [] ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$domain      = sanitize_text_field( $args['domain'] ?? '' );
		$context_f   = sanitize_text_field( $args['context'] ?? '' );
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

				default: // contains.
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
				// EXISTS (not INNER JOIN) so a string with needs_update links
				// in several languages is counted once, not once per link.
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
					// EXISTS (not INNER JOIN) so a string translated into
					// several languages is counted once, not once per link.
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

		// Always apply $join/$where: a status filter (translated, untranslated,
		// needs_update) can add a clause WITHOUT any bound args, so gating the
		// filtered query on a non-empty $query_args silently returned the
		// unfiltered total for those filters.
		$sql = "SELECT COUNT(*) FROM %i s {$join} WHERE {$where}";

		// The table identifiers bind as %i (WP 6.2+), so the argument list is
		// never empty and every call goes through prepare(). JOIN args bind
		// ahead of the WHERE args (see find_all()): merge them in SQL order.
		$all_args = array_merge( [ $this->table() ], $join_args, $query_args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( $sql, ...$all_args )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * {@inheritDoc}
	 */
	public function insert( array $data ): int|false {
		$hash = self::compute_hash(
			$data['domain'] ?? 'default',
			$data['context'] ?? '',
			$data['original'] ?? ''
		);

		$groups_table = Schema::table( 'translation_groups' );

		// Sibling bulk_insert() at line ~181 documents that insert_id can hold
		// a STALE prior row's id on failure (mysqli-driver-dependent), so the
		// $group_id === 0 check alone isn't a reliable failure signal. Check
		// the insert() return explicitly first.
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
				'context'       => sanitize_text_field( $data['context'] ?? '' ),
				'original'      => $data['original'] ?? '',
				'original_hash' => $hash,
				'group_id'      => $group_id,
				'file_path'     => sanitize_text_field( $data['file_path'] ?? '' ),
				'line_number'   => absint( $data['line_number'] ?? 0 ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
		);

		// If the string insert failed (UNIQUE-hash collision, DB error), the
		// group we just inserted has no owner - it would be a widow. GC it
		// right away rather than leave schema rot.
		if ( $result === false ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->wpdb->delete( $groups_table, [ 'id' => $group_id ], [ '%d' ] );
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
		// Capture the owning group before deleting. String groups carry exactly
		// one string, so deleting the string leaves a widow group that nothing
		// else sweeps - the health-check deliberately skips string-type groups.
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

		// Cascade: drop the string's translations, then its now-empty group.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete( Schema::table( 'string_translations' ), [ 'string_id' => $id ], [ '%d' ] );

		if ( $group_id > 0 ) {
			$groups_table = Schema::table( 'translation_groups' );
			// Guard with NOT EXISTS so a (defensively) shared group survives.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE g FROM %i g
					WHERE g.id = %d AND g.type = 'string'
					AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )",
					$groups_table,
					$group_id,
					$this->table()
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		return true;
	}

	/**
	 * Compute the SHA-256 hash for a string's unique identity.
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
	 * Register a non-gettext string (e.g. from settings or WC emails).
	 *
	 * If the string already exists (same hash), does nothing. If the source text
	 * changed (same domain+context, different hash), migrates existing translations
	 * to the new string and marks them as 'needs_update'.
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

		// Serialize the SELECT-then-INSERT + stale-string-migration sequence
		// per (domain, context). Without the lock, two concurrent saves of
		// the SAME setting (e.g. an admin double-clicking Save, or two
		// admins editing the same setting within the same second) can both
		// observe `$exists === null`, both proceed to insert NEW string
		// rows with different hashes, and both call migrate_stale_translations()
		// on the same $old_strings set — the second migration finds nothing
		// to move, leaving one of the two new strings dangling without its
		// migrated translations.
		//
		// Lock per (domain, context) — registration within a domain is
		// rare-but-bursty (theme/plugin init mass-registers strings at
		// once), so the per-key lock keeps unrelated registrations
		// uncontended. 5 s TTL covers the SELECT + INSERT + migrate roundtrip
		// with comfortable headroom; a crashed worker just lets the TTL
		// expire.
		Lock::with(
			'register_setting_string_' . md5( $domain . '|' . $context ),
			5,
			function () use ( $text, $domain, $context ): void {
				$hash = self::compute_hash( $domain, $context, $text );

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

				// If exact hash exists, string is unchanged - nothing to do
				// except bump last_seen_at so the GC's stale-string sweep
				// keeps this manually-registered string alive as long as
				// the code path that registers it runs.
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

				// Check for old strings with same domain+context but different
				// hash (source text was changed). Migrate their translations.
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
					$this->migrate_stale_translations( $old_strings, $new_string_id );

					// A source-text change moved the translation onto a NEW
					// string hash; without busting the strings cache the
					// per-language all_string_translations map keeps the old
					// hash (and lacks the new one), so the changed string reads
					// UNTRANSLATED until the 6h TTL. Same treatment as
					// gc_stale_strings().
					$this->cache->invalidate_group( 'perflocale_strings' );
					$this->cache->invalidate_group( 'perflocale_trans' );

					// Fire hook for each old string that was replaced.
					foreach ( $old_strings as $old ) {
						/** @hook perflocale/string/needs_update Fires when a string's source text changes and translations are marked as needs_update. */
						do_action( 'perflocale/string/needs_update', $new_string_id, (string) $old->original, $text, $domain, $context );
					}
				}
			}
		);
	}

	/**
	 * Mark-and-sweep GC: delete strings whose last_seen_at is older than
	 * the configured retention window. Together with the last_seen_at
	 * touches in bulk_insert() (scanner) and register_setting_string()
	 * (manual registrations), this guarantees that strings whose source
	 * is removed (uninstalled plugin, deleted theme, removed code) age out
	 * automatically.
	 *
	 * Safety nets:
	 *   - Default retention is 90 days (filterable). A long window
	 *     tolerates rare-but-real code paths that only run a few times
	 *     a year.
	 *   - perflocale/strings/manual_contexts filter lets operators
	 *     blacklist any context value that should NEVER be GC'd
	 *     (preserves manually-registered settings/email templates even if
	 *     their register_setting_string code path hasn't run recently).
	 *   - Cascade: string_translations rows are dropped along with their
	 *     parent string in the same DELETE…JOIN.
	 *
	 * Called from the daily perflocale_jobs_gc cron handler.
	 *
	 * @return array{strings: int, translations: int} Row counts deleted.
	 */
	public function gc_stale_strings(): array {
		/**
		 * Retention window in days for unreferenced strings rows. After
		 * this many days without a last_seen_at touch (no scanner
		 * rediscovery and no register_setting_string() call), the row is
		 * eligible for GC.
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

		// Mark-phase gate. last_seen_at is bumped ONLY by a StringScanJob full
		// scan (manual — there is no automatic scan), so it only signals
		// liveness AFTER a scan has run. If no full scan has completed within
		// the retention window, every row's last_seen_at is stale for reasons
		// unrelated to the string being gone — deleting here would wipe strings
		// imported via PO/migration or registered once, that the operator
		// simply never scanned. Skip the sweep until a fresh mark phase exists.
		$last_full_scan = (int) get_option( 'perflocale_strings_last_full_scan', 0 );

		if ( $last_full_scan <= 0 || $last_full_scan < time() - ( $days * DAY_IN_SECONDS ) ) {
			return [
				'strings'      => 0,
				'translations' => 0,
				'groups'       => 0,
			];
		}

		/**
		 * Context values whose strings should never be GC'd, even when
		 * last_seen_at exceeds the retention window. Use this for
		 * manually-registered strings (settings labels, addon-registered
		 * templates) that may be set once and only re-touched if the
		 * underlying admin code path runs.
		 *
		 * @hook perflocale/strings/manual_contexts
		 * @param string[] $contexts Default [].
		 */
		$manual_contexts = (array) apply_filters(
			'perflocale/strings/manual_contexts',
			[]
		);

		// Identifiers (sanitized to a bare [A-Za-z0-9_] token as belt-and-
		// braces; they are bound below with prepare()'s %i placeholder).
		$strings_table      = Schema::sanitize_table( $this->table() );
		$translations_table = Schema::sanitize_table( Schema::table( 'string_translations' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		/**
		 * Domains whose strings must NEVER be GC'd. Backed by a persistent
		 * option (not only a filter) so an addon's protection survives its
		 * own deactivation: strings owned by an inactive addon (e.g. the
		 * visual editor's dynamic domain) are kept alive only by that
		 * addon's own touch cron, which stops with it — without a
		 * persistent exclusion, a routine string scan plus 90 idle days
		 * would silently delete the addon's entire translation corpus.
		 *
		 * @hook perflocale/strings/protected_domains
		 * @param string[] $domains Option-backed registrations (perflocale_gc_protected_domains).
		 */
		$protected_domains = (array) apply_filters(
			'perflocale/strings/protected_domains',
			array_values( array_filter( array_map( 'strval', (array) get_option( 'perflocale_gc_protected_domains', [] ) ) ) )
		);

		// Discover stale string IDs first so we can both cascade to
		// string_translations and report the deletion count. Cap at 5,000
		// per tick to keep the daily GC bounded on huge installs.
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

		// Capture the string-type groups these strings own BEFORE deleting the
		// rows, so we can drop the now-widowed group + its language links
		// afterwards. The periodic gc_empty_groups() deliberately skips
		// type='string' groups (an empty string group is a legit transient
		// state mid bulk-translate), so without this cascade a deleted string
		// leaves a permanent widow group + orphan links.
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

		// Cascade delete translations first (FK-style), then parent strings.
		// Table names bound with the %i identifier placeholder (WP 6.2+);
		// the dynamic id list keeps its %d placeholders ($id_ph).
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

		// Drop the widowed string-type groups + their language links. The
		// NOT EXISTS guard means a group still owned by a surviving string is
		// never touched (string<->group is 1:1, but stay defensive).
		$groups_deleted = 0;
		if ( $stale_group_ids !== [] ) {
			$g_ph = implode( ',', array_fill( 0, count( $stale_group_ids ), '%d' ) );

			$this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE l FROM %i l
					 INNER JOIN %i g ON g.id = l.group_id AND g.type = 'string'
					 WHERE l.group_id IN ({$g_ph})
					   AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )",
					array_merge( [ $links_table, $groups_table ], $stale_group_ids, [ $strings_table ] )
				)
			);

			$groups_deleted = (int) $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE g FROM %i g
					 WHERE g.type = 'string' AND g.id IN ({$g_ph})
					   AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )
					   AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.group_id = g.id )",
					array_merge( [ $groups_table ], $stale_group_ids, [ $strings_table, $links_table ] )
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $strings_deleted > 0 ) {
			// Drop the strings + translations cache groups so the next
			// lookup re-reads from disk rather than serving a stale ID.
			// Generation bump (not wp_cache_flush_group, which no-ops on
			// common object caches like Redis Object Cache + Predis).
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
	 * Migrate translations from old stale strings to a new string.
	 *
	 * Moves translation option values and links from old strings to the new
	 * string, marking them as 'needs_update'. Cleans up old string data.
	 *
	 * @param array<int, object> $old_strings Old string rows (id, group_id, original).
	 * @param int                $new_string_id New string's ID.
	 * @return void
	 */
	private function migrate_stale_translations( array $old_strings, int $new_string_id ): void {
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

		// Partition: strings whose group survives migration vs orphans.
		$grouped_ids = [];
		$group_ids   = [];
		$orphan_ids  = [];
		$all_old_ids = [];

		foreach ( $old_strings as $old ) {
			$old_id        = (int) $old->id;
			$old_group_id  = (int) $old->group_id;
			$all_old_ids[] = $old_id;

			if ( $old_group_id > 0 && $new_group_id > 0 ) {
				$grouped_ids[] = $old_id;
				$group_ids[]   = $old_group_id;
			} else {
				$orphan_ids[] = $old_id;
			}
		}

		// Set when a translation move fails. Every cleanup DELETE in this
		// method is gated on it staying false - see the move loop below.
		$move_failed = false;

		if ( $grouped_ids !== [] ) {
			// Bind the id list with %d placeholders (values are integers, but
			// prepare() makes that explicit and scanner-verifiable). Table
			// names bind with %i; only the generated placeholder lists are
			// interpolated — hence the surrounding phpcs:disable.
			$gid_list = array_values( array_unique( array_map( 'intval', $group_ids ) ) );
			$gid_ph   = implode( ',', array_fill( 0, count( $gid_list ), '%d' ) );

			// One query for every language linked under ANY old group
			// (was: one SELECT per old string).
			$lang_ids = array_map(
				'intval',
				(array) $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT DISTINCT language_id FROM %i WHERE group_id IN ({$gid_ph})",
						array_merge( [ $links_table ], $gid_list )
					)
				)
			);

			// Move every existing translation row from each old string to
			// the new one. One UPDATE per old string — already minimal.
			//
			// A move that FAILS leaves that string's translations on the OLD
			// string (move_translations() returns -1 and skips its own cleanup
			// DELETE). Everything after this loop that deletes is therefore
			// gated on $move_failed: the old rows are the last remaining copy
			// and there is no transaction to bring them back.
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

			if ( $lang_ids !== [] ) {
				// One query for which of those languages now carry a
				// non-empty translation on the new string (was: one
				// SELECT per (old string × language)). Checked after ALL
				// moves so a translation contributed by any old sibling
				// counts — the per-string ordering of the previous
				// implementation could miss a language whose value
				// arrived from a later sibling's move.
				$lid_ph           = implode( ',', array_fill( 0, count( $lang_ids ), '%d' ) );
				$translated_langs = array_map(
					'intval',
					(array) $this->wpdb->get_col(
						$this->wpdb->prepare(
							"SELECT language_id FROM %i WHERE string_id = %d AND translation != '' AND language_id IN ({$lid_ph})",
							$trans_table,
							$new_string_id,
							...$lang_ids
						)
					)
				);

				if ( $translated_langs !== [] ) {
					// One multi-row INSERT marks every migrated language
					// needs_update (was: one INSERT per link).
					// `type` mirrors the owning group's type (Schema.php:89). This
					// path only ever links a string-type group, so the literal is
					// safe and keeps the placeholder/arg counts unchanged. Omitting
					// it would insert the schema DEFAULT '' — a row invisible to
					// every type-qualified lookup.
					$row_ph = implode( ',', array_fill( 0, count( $translated_langs ), "(%d, %d, %d, 'string', %s, %s)" ) );
					$args   = [];
					foreach ( $translated_langs as $lang_id ) {
						array_push( $args, $new_group_id, $new_string_id, $lang_id, 'needs_update', 'manual' );
					}

					$this->wpdb->query(
						$this->wpdb->prepare(
							"INSERT INTO %i (group_id, object_id, language_id, type, status, source)
							VALUES {$row_ph}
							ON DUPLICATE KEY UPDATE status = 'needs_update', type = 'string'",
							array_merge( [ $links_table ], $args )
						)
					);
				}
			}

			// Batched cleanup (was: two DELETEs per old string). Skipped when
			// a move failed, because these DELETEs remove the links and groups
			// that still point at translations which never made it across.
			if ( ! $move_failed ) {
				$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE group_id IN ({$gid_ph})", array_merge( [ $links_table ], $gid_list ) ) );
				$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$gid_ph})", array_merge( [ $groups_table ], $gid_list ) ) );
			}
		}

		if ( $move_failed ) {
			// Leave the old strings, groups, links and translations exactly
			// where they are. A migration that did not happen is recoverable -
			// the operator still has every translation, and the daily
			// stale-string GC reclaims the rows once they stop being seen. A
			// half-applied migration that deleted them is not recoverable.
			return;
		}

		// Orphaned strings - drop their translations.
		foreach ( $orphan_ids as $old_id ) {
			$translations_repo->delete_for_string( $old_id );
		}

		if ( $all_old_ids !== [] ) {
			$id_list = array_map( 'intval', $all_old_ids );
			$id_ph   = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
			$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM %i WHERE id IN ({$id_ph})", array_merge( [ $this->table() ], $id_list ) ) );
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
