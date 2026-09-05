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
 * Direct access layer for translation links table.
 *
 * Provides low-level link operations used by PostTranslationManager
 * and TermTranslationManager.
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
	 * Resolve the full table name for the current blog.
	 *
	 * Computed on every call so that switch_to_blog() flips the prefix
	 * correctly — capturing the name in the constructor would otherwise
	 * pin this instance to whichever blog was active at construction.
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
	 * Count translations by status for a given language.
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

		// When a post-type filter is supplied we already INNER JOIN wp_posts;
		// piggy-back on that join to resolve the link's effective status from
		// the WP post_status when the link row is still the default 'empty'.
		// This mirrors the runtime fix-up in TranslationsPage::collect_rows()
		// at src/Admin/Pages/TranslationsPage.php:353 - the link table can
		// drift behind the post status (older translations created before
		// the publish-sync hook landed, or imports that bypassed it).
		// LEFT JOIN wp_posts (gated to post-type links) so a link still at the
		// default 'empty' status resolves its effective status from the WP
		// post_status even with NO post-type filter. Without this the no-type
		// path returned raw l.status and mis-counted published translations as
		// 'empty', so `wp perflocale status` / the Dashboard disagreed with the
		// per-type views. The `g.type = 'post'` half of the join condition is
		// what keeps term links out: object_id is polymorphic and holds a
		// term_id for term links, which collides freely with a post ID, so
		// without that gate a term would borrow a same-numbered post's status.
		// With it, term links never join a post row and keep their stored
		// status via the ELSE branch.
		$join       .= " LEFT JOIN {$posts_table} p ON l.object_id = p.ID AND g.type = 'post'";
		$status_expr = "CASE
			WHEN l.status = 'empty' AND p.post_status = 'publish' THEN 'published'
			WHEN l.status = 'empty' AND p.post_status = 'draft' THEN 'draft'
			ELSE l.status
		END";

		if ( $type !== '' ) {
			// Narrow to one post type. p is NULL for term links, so the
			// post_type predicate also excludes them (INNER semantics).
			$where .= " AND g.type = 'post' AND p.post_type = %s";
			$args[] = $type;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				// Alias deliberately NOT `status`: for GROUP BY, MySQL resolves an
				// unqualified name against the FROM columns FIRST and only then
				// against SELECT aliases, and `l.status` is a real column — so
				// `GROUP BY status` grouped by the STORED status and silently
				// merged an 'empty'/publish row with an 'empty'/draft row while
				// emitting two rows keyed 'published'. `effective_status` exists on
				// no joined table, so it can only bind to the CASE expression.
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
	 * Batch-count status counts across (language × post_type × status) in
	 * a single query — replaces an O(P×L) loop of per-cell
	 * `count_by_status()` calls on the dashboard.
	 *
	 * The status expression mirrors `count_by_status()`'s post-status
	 * fixup: when the link's stored status is 'empty' but the linked
	 * post is publish/draft, the effective status takes the WP post's
	 * status. Same fixup also lives in
	 * `Admin/Pages/TranslationsPage::collect_rows()` so display matches.
	 *
	 * @param int[]    $language_ids Languages to count for.
	 * @param string[] $post_types   Post types to count for.
	 * @return array<int, array<string, array<string, int>>>
	 *     [language_id][post_type][status] => count
	 */
	public function count_status_matrix( array $language_ids, array $post_types ): array {
		$matrix = [];

		// Empty inputs: nothing to count. Don't issue a query that would
		// IN-list zero values (MySQL syntax error on `IN ()`).
		if ( empty( $language_ids ) || empty( $post_types ) ) {
			return $matrix;
		}

		// Sanitize to integers / non-empty strings before placeholder build.
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

		$sql = "SELECT l.language_id AS lid, p.post_type AS pt,
				CASE
					WHEN l.status = 'empty' AND p.post_status = 'publish' THEN 'published'
					WHEN l.status = 'empty' AND p.post_status = 'draft' THEN 'draft'
					ELSE l.status
				END AS effective_status,
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
	 * Count published source posts for a given language and post type.
	 *
	 * Counts WP posts with status 'publish' that have a translation link
	 * for the specified language. Used to calculate the denominator for
	 * translation progress - only source-language posts should count.
	 *
	 * @param int    $language_id Default language ID.
	 * @param string $post_type Post type slug.
	 * @return int Number of published source posts.
	 */
	public function count_source_published( int $language_id, string $post_type ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$groups_table = Schema::table( 'translation_groups' );
		$posts_table  = $this->wpdb->posts;

		// translation_groups.type stores the ObjectType enum value ('post' for
		// all post types), not the WP post_type slug. Filter the actual post
		// type via wp_posts.post_type instead.
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
		// $data['source'] may be a SourceType enum case or a raw string
		// (REST input arrives as a string) — normalise via SourceType::from_db
		// which falls back to Manual on unknown values.
		$source_raw = $data['source'] ?? \PerfLocale\Enum\SourceType::Manual;
		if ( $source_raw instanceof \PerfLocale\Enum\SourceType ) {
			$source = $source_raw->value;
		} else {
			$source = \PerfLocale\Enum\SourceType::from_db( (string) $source_raw )->value;
		}

		// Polymorphic object type (DB v3). This method predates the column;
		// omitting it would insert the schema default '' — a row invisible to
		// every type-qualified lookup. Accept an ObjectType case or its string
		// value; default to 'post', the overwhelming common case.
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
