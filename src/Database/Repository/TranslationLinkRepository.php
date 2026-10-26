<?php
/**
 * Translation link repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Contract\RepositoryInterface;
use PerfLocale\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Low-level access to the translation links table for the post and term managers, and the status counts.
 */
final class TranslationLinkRepository implements RepositoryInterface {

	/**
	 * @var \wpdb
	 */
	private readonly \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
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
		return Schema::table( 'translation_links' );
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
	 * SQL for a link's effective status joined to its post: TranslationStatus::effective()'s arms in its order, so a
	 * filter, a count and a cell agree. A NULL post (term or string link, missing post) keeps the stored value.
	 *
	 * @param literal-string $link_alias Alias of the translation_links table (a literal in the caller's SQL).
	 * @param literal-string $post_alias Alias of the posts table, LEFT JOINed on object_id (a literal in the caller's SQL).
	 * @return literal-string CASE expression.
	 */
	public static function effective_status_sql( string $link_alias, string $post_alias ): string {
		return "CASE
			WHEN {$post_alias}.post_status IN ( 'trash', 'auto-draft' ) THEN 'empty'
			WHEN {$link_alias}.status = 'needs_update' THEN 'needs_update'
			WHEN {$post_alias}.post_status = 'publish' THEN 'published'
			WHEN {$post_alias}.post_status = 'draft' THEN 'draft'
			WHEN {$post_alias}.post_status = 'pending' THEN 'pending'
			ELSE {$link_alias}.status
		END";
	}

	/**
	 * Count a language's translations by status.
	 *
	 * @param int    $language_id Language ID.
	 * @param string $type Object type filter (optional).
	 * @return array<string, int> Status => count.
	 */
	public function count_by_status( int $language_id, string $type = '' ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$groups_table = Schema::table( 'translation_groups' );
		$posts_table  = $this->wpdb->posts;

		$where = 'l.language_id = %d';
		$args  = [ $language_id ];
		$join  = 'INNER JOIN %i g ON l.group_id = g.id';

		// Post links count by effective status, as the Translations screen and Dashboard show it. Posts join only for post
		// groups: object_id is polymorphic, and a term would borrow a same-numbered post's status.
		$join       .= " LEFT JOIN {$posts_table} p ON l.object_id = p.ID AND g.type = 'post'";
		$status_expr = self::effective_status_sql( 'l', 'p' );

		if ( $type !== '' ) {
			// One post type; p is NULL for term links, so they drop out too.
			$where .= " AND g.type = 'post' AND p.post_type = %s";
			$args[] = $type;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				// Not aliased `status`: GROUP BY resolves a bare name against the FROM columns first, so it would group by l.status.
				"SELECT {$status_expr} AS effective_status, COUNT(*) as count
				FROM %i l
				{$join}
				WHERE {$where}
				GROUP BY effective_status",
				array_merge( [ $this->table(), $groups_table ], $args )
			)
		);

		$counts = [];

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$counts[ $row->effective_status ] = (int) $row->count;
			}
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $counts;
	}

	/**
	 * Status counts per (language, post type, status) in one query for the Dashboard, by effective status.
	 *
	 * @param int[]    $language_ids Languages to count for.
	 * @param string[] $post_types   Post types to count for.
	 * @return array<int, array<string, array<string, int>>>
	 *     [language_id][post_type][status] => count
	 */
	public function count_status_matrix( array $language_ids, array $post_types ): array {
		$matrix = [];

		// Nothing to count: no query (IN () is a SQL error).
		if ( empty( $language_ids ) || empty( $post_types ) ) {
			return $matrix;
		}

		// Integers and non-empty strings before building placeholders.
		$lang_ids = array_values( array_unique( array_map( 'intval', $language_ids ) ) );
		$lang_ids = array_filter( $lang_ids, static fn( $i ) => $i > 0 );

		$pt_safe = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $p ) => is_string( $p ) ? $p : '', $post_types ),
					static fn( $p ) => $p !== ''
				)
			)
		);

		if ( empty( $lang_ids ) || empty( $pt_safe ) ) {
			return $matrix;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$groups_table = Schema::table( 'translation_groups' );
		$posts_table  = $this->wpdb->posts;

		$lang_ph = implode( ',', array_fill( 0, count( $lang_ids ), '%d' ) );
		$pt_ph   = implode( ',', array_fill( 0, count( $pt_safe ), '%s' ) );

		// The stored status is not kept current, so links count by their effective status: a live post is published, a
		// trashed or auto-draft one is 'empty' (still needs a translation), a stored needs_update is kept.
		$status_expr = self::effective_status_sql( 'l', 'p' );

		$sql = "SELECT l.language_id AS lid, p.post_type AS pt,
				{$status_expr} AS effective_status,
				COUNT(*) AS cnt
			FROM %i l
			INNER JOIN %i g ON l.group_id = g.id
			INNER JOIN {$posts_table} p ON l.object_id = p.ID
			WHERE g.type = 'post'
				AND l.language_id IN ({$lang_ph})
				AND p.post_type IN ({$pt_ph})
			GROUP BY l.language_id, p.post_type, effective_status";

		$args = array_merge( $lang_ids, $pt_safe );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, array_merge( [ $this->table(), $groups_table ], $args ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return $matrix;
		}

		foreach ( $rows as $row ) {
			$lid = (int) $row->lid;
			$pt  = (string) $row->pt;
			$matrix[ $lid ][ $pt ][ (string) $row->effective_status ] = (int) $row->cnt;
		}

		return $matrix;
	}

	/**
	 * Published source posts of a language and post type with a link: the denominator of translation progress.
	 *
	 * @param int    $language_id Default language ID.
	 * @param string $post_type Post type slug.
	 * @return int Number of published source posts.
	 */
	public function count_source_published( int $language_id, string $post_type ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$groups_table = Schema::table( 'translation_groups' );
		$posts_table  = $this->wpdb->posts;

		// translation_groups.type is 'post' for every post type: filter by wp_posts.post_type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*)
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id
				INNER JOIN %i p ON l.object_id = p.ID
				WHERE l.language_id = %d
					AND g.type = 'post'
					AND p.post_type = %s
					AND p.post_status = 'publish'",
				$this->table(),
				$groups_table,
				$posts_table,
				$language_id,
				$post_type
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $count;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find_all( array $args = [] ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$limit = absint( $args['limit'] ?? 100 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d',
				$this->table(),
				$limit
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $results ) ? $results : [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function insert( array $data ): int|false {
		// An enum case or a raw string (REST); SourceType::from_db() falls back to Manual.
		$source_raw = $data['source'] ?? \PerfLocale\Enum\SourceType::Manual;
		if ( $source_raw instanceof \PerfLocale\Enum\SourceType ) {
			$source = $source_raw->value;
		} else {
			$source = \PerfLocale\Enum\SourceType::from_db( (string) $source_raw )->value;
		}

		// The object type column: omitted, the default '' is invisible to every typed lookup. A case or its value; 'post'
		// by default.
		$type_raw = $data['type'] ?? \PerfLocale\Enum\ObjectType::Post;
		if ( $type_raw instanceof \PerfLocale\Enum\ObjectType ) {
			$type = $type_raw->value;
		} else {
			$type = \PerfLocale\Enum\ObjectType::tryFrom( (string) $type_raw )?->value
				?? \PerfLocale\Enum\ObjectType::Post->value;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->table(),
			[
				'group_id'    => absint( $data['group_id'] ),
				'object_id'   => absint( $data['object_id'] ),
				'language_id' => absint( $data['language_id'] ),
				'status'      => sanitize_key( $data['status'] ?? 'empty' ),
				'source'      => $source,
				'type'        => $type,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%s' ]
		);

		return $result !== false ? (int) $this->wpdb->insert_id : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( int $id, array $data ): bool {
		$update = [];
		$format = [];

		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_key( $data['status'] );
			$format[]         = '%s';
		}

		if ( isset( $data['source'] ) ) {
			$update['source'] = $data['source'] instanceof \PerfLocale\Enum\SourceType
				? $data['source']->value
				: \PerfLocale\Enum\SourceType::from_db( (string) $data['source'] )->value;
			$format[]         = '%s';
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}
}
