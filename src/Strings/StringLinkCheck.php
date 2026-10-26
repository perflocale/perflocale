<?php
/**
 * String group and replaced-link checks of `wp perflocale health-check`.
 *
 * @package PerfLocale
 */

declare(strict_types=1);

namespace PerfLocale\Strings;

use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Repository\StringGroupAssigner;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\SourceType;

defined( 'ABSPATH' ) || exit;

/**
 * Reports post and term links a string import replaced, and gives each string without a string group of its own
 * one. Loaded only by WP-CLI.
 */
final class StringLinkCheck {

	/**
	 * Rows read per statement.
	 */
	private const BATCH = 500;

	/**
	 * Repairs run() committed so far; kept when a later read throws.
	 *
	 * @var array{fixed: int, moved: int, linked: int}
	 */
	private array $committed = [
		'fixed'  => 0,
		'moved'  => 0,
		'linked' => 0,
	];

	/**
	 * Repairs the last run() committed, also when it threw.
	 *
	 * @return array{fixed: int, moved: int, linked: int}
	 */
	public function committed(): array {
		return $this->committed;
	}

	/**
	 * Both checks, replaced links first: once a string has left a post group, the link stops showing it.
	 *
	 * @param bool $fix Give the strings their own groups.
	 * @return array{replaced: list<array{link_id: int, group_id: int, group_type: string, object_id: int, language_id: int, flags: list<string>, members: list<int>}>, missing: int, not_string: int, shared: int, sample: list<int>, fixed: int, failed: int, moved: int, linked: int}
	 *
	 * @throws \RuntimeException When a read failed.
	 */
	public function run( bool $fix ): array {
		$this->committed = [
			'fixed'  => 0,
			'moved'  => 0,
			'linked' => 0,
		];

		$report = [
			'replaced'   => $this->replaced_links(),
			'missing'    => 0,
			'not_string' => 0,
			'shared'     => 0,
			'sample'     => [],
			'fixed'      => 0,
			'failed'     => 0,
			'moved'      => 0,
			'linked'     => 0,
		];
		$after  = 0;

		do {
			$rows = $this->strings_without_own_group( $after );

			foreach ( $rows as $row ) {
				++$report[ $row['reason'] ];
				$after = $row['id'];

				if ( count( $report['sample'] ) < 20 ) {
					$report['sample'][] = $row['id'];
				}

				if ( ! $fix ) {
					continue;
				}

				$done = $this->give_own_group( $row );

				if ( null === $done ) {
					++$report['failed'];
					continue;
				}

				++$report['fixed'];
				$report['moved']  += $done['moved'];
				$report['linked'] += $done['linked'];
				$this->committed   = [
					'fixed'  => $report['fixed'],
					'moved'  => $report['moved'],
					'linked' => $report['linked'],
				];
			}
			$more = count( $rows ) === self::BATCH;
		} while ( $more );

		return $report;
	}

	/**
	 * Post and term links a string import replaced: imported, naming a string id, and flagged
	 * string_group (that string names the group), missing (no such post or term), other_group
	 * (linked in another group too) or post_type (not the type of the group's own posts).
	 *
	 * @return list<array{link_id: int, group_id: int, group_type: string, object_id: int, language_id: int, flags: list<string>, members: list<int>}>
	 *
	 * @throws \RuntimeException When a read failed.
	 */
	public function replaced_links(): array {
		$wpdb = self::db();

		$found = [];
		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off check from WP-CLI; must read the stored rows.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.group_id, l.object_id, l.language_id, l.type, g.type AS group_type, s.group_id AS string_group
					FROM %i l INNER JOIN %i g ON g.id = l.group_id INNER JOIN %i s ON s.id = l.object_id
					WHERE l.id > %d AND l.type <> 'string' AND g.type IN ('post', 'term') AND l.source IN (%s, %s, %s)
					ORDER BY l.id LIMIT %d",
					Schema::table( 'translation_links' ),
					Schema::table( 'translation_groups' ),
					Schema::table( 'strings' ),
					$after,
					SourceType::ImportedTrp->value,
					SourceType::ImportedWpml->value,
					SourceType::ImportedPolylang->value,
					self::BATCH
				),
				ARRAY_A
			);
			self::check_read();

			$rows = self::rows( $rows );

			if ( [] !== $rows ) {
				$after = self::int( $rows[ count( $rows ) - 1 ]['id'] ?? 0 );
				$found = array_merge( $found, $this->flag( $rows ) );
			}
			$more = count( $rows ) === self::BATCH;
		} while ( $more );

		return $found;
	}

	/**
	 * Language slugs by id.
	 *
	 * @return array<int, string>
	 *
	 * @throws \RuntimeException When the read failed.
	 */
	public function language_slugs(): array {
		$wpdb = self::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off check from WP-CLI.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, slug FROM %i', Schema::table( 'languages' ) ), ARRAY_A );
		self::check_read();

		$slugs = [];

		foreach ( self::rows( $rows ) as $row ) {
			$slugs[ self::int( $row['id'] ?? 0 ) ] = is_scalar( $row['slug'] ?? null ) ? (string) $row['slug'] : '';
		}

		return $slugs;
	}

	/**
	 * The flags of one batch of candidates; candidates with none are dropped.
	 *
	 * @param list<array<mixed>> $rows Candidates.
	 * @return list<array{link_id: int, group_id: int, group_type: string, object_id: int, language_id: int, flags: list<string>, members: list<int>}>
	 *
	 * @throws \RuntimeException When a read failed.
	 */
	private function flag( array $rows ): array {
		$candidates = [];

		foreach ( $rows as $row ) {
			$candidates[ self::int( $row['id'] ?? 0 ) ] = true;
		}

		$group_ids  = array_values( array_unique( array_map( static fn( array $row ): int => self::int( $row['group_id'] ?? 0 ), $rows ) ) );
		$object_ids = array_values( array_unique( array_map( static fn( array $row ): int => self::int( $row['object_id'] ?? 0 ), $rows ) ) );
		$members    = self::read_links( 'group_id IN', $group_ids );
		$elsewhere  = self::read_links( "type <> 'string' AND object_id IN", $object_ids );

		// Posts: the candidates of post groups and the other posts of those groups.
		$post_ids = [];
		$term_ids = [];

		foreach ( $rows as $row ) {
			if ( 'post' === ( $row['group_type'] ?? '' ) ) {
				$post_ids[] = self::int( $row['object_id'] ?? 0 );
			} else {
				$term_ids[] = self::int( $row['object_id'] ?? 0 );
			}
		}

		foreach ( $members as $member ) {
			if ( 'post' === $member['type'] ) {
				$post_ids[] = $member['object_id'];
			}
		}

		$post_types = self::post_types( $post_ids );
		$terms      = self::existing_terms( $term_ids );
		$found      = [];

		foreach ( $rows as $row ) {
			$link_id = self::int( $row['id'] ?? 0 );
			$group   = self::int( $row['group_id'] ?? 0 );
			$object  = self::int( $row['object_id'] ?? 0 );
			$type    = is_string( $row['type'] ?? null ) ? $row['type'] : '';
			$is_post = 'post' === ( $row['group_type'] ?? '' );
			$flags   = [];
			$others  = [];
			$kinds   = [];

			foreach ( $members as $member ) {
				if ( $member['group_id'] !== $group || $member['id'] === $link_id ) {
					continue;
				}

				$others[] = $member['object_id'];

				// The group's own posts: not themselves read as replaced links.
				if ( 'post' === $member['type'] && ! isset( $candidates[ $member['id'] ] ) && isset( $post_types[ $member['object_id'] ] ) ) {
					$kinds[ $post_types[ $member['object_id'] ] ] = true;
				}
			}

			if ( self::int( $row['string_group'] ?? 0 ) === $group ) {
				$flags[] = 'string_group';
			}

			if ( $is_post ? ! isset( $post_types[ $object ] ) : ! isset( $terms[ $object ] ) ) {
				$flags[] = 'missing';
			}

			foreach ( $elsewhere as $link ) {
				if ( $link['object_id'] === $object && $link['type'] === $type && $link['group_id'] !== $group ) {
					$flags[] = 'other_group';
					break;
				}
			}

			if ( $is_post && isset( $post_types[ $object ] ) && [] !== $kinds && ! isset( $kinds[ $post_types[ $object ] ] ) ) {
				$flags[] = 'post_type';
			}

			if ( [] === $flags ) {
				continue;
			}

			sort( $others );

			$found[] = [
				'link_id'     => $link_id,
				'group_id'    => $group,
				'group_type'  => $is_post ? 'post' : 'term',
				'object_id'   => $object,
				'language_id' => self::int( $row['language_id'] ?? 0 ),
				'flags'       => $flags,
				'members'     => $others,
			];
		}

		return $found;
	}

	/**
	 * Strings without a string group of their own, in id order: the group is
	 * missing, is not a string group, or a lower-id string has it too.
	 *
	 * @param int $after Last string id read.
	 * @return list<array{id: int, group_id: int, reason: 'missing'|'not_string'|'shared'}>
	 *
	 * @throws \RuntimeException When the read failed.
	 */
	private function strings_without_own_group( int $after ): array {
		$wpdb = self::db();

		$strings = Schema::table( 'strings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A one-off check from WP-CLI; must read the stored rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.group_id, g.type AS group_type FROM %i s LEFT JOIN %i g ON g.id = s.group_id
				WHERE s.id > %d AND ( g.id IS NULL OR g.type <> 'string'
				OR EXISTS ( SELECT 1 FROM %i o WHERE o.group_id = s.group_id AND o.id < s.id ) )
				ORDER BY s.id LIMIT %d",
				$strings,
				Schema::table( 'translation_groups' ),
				$after,
				$strings,
				self::BATCH
			),
			ARRAY_A
		);
		self::check_read();

		$out = [];

		foreach ( self::rows( $rows ) as $row ) {
			$type   = $row['group_type'] ?? null;
			$reason = null === $type ? 'missing' : ( 'string' !== $type ? 'not_string' : 'shared' );
			$out[]  = [
				'id'       => self::int( $row['id'] ?? 0 ),
				'group_id' => self::int( $row['group_id'] ?? 0 ),
				'reason'   => $reason,
			];
		}

		return $out;
	}

	/**
	 * Give one string a new string group in one transaction: its string links move there as whole rows and its
	 * translated languages are linked; a shared group's owner gets the languages the move freed. Every write carries
	 * the connection marker, so after a lost connection none lands.
	 *
	 * @param array{id: int, group_id: int, reason: string} $row String.
	 * @return array{moved: int, linked: int}|null Null when a statement failed
	 *                                            (rolled back).
	 */
	private function give_own_group( array $row ): ?array {
		$wpdb = self::db();

		$errors = Schema::query_error_count();
		$guard  = new ConnectionGuard( 'string_group' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One string's repair, in its own transaction; the guard's condition is built by the guard.
		if ( ! $guard->begin() || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return null;
		}

		$marker    = $guard->condition();
		$string_id = $row['id'];
		$group_id  = StringGroupAssigner::assign( $wpdb, $string_id, true, $guard );
		$moved     = $group_id > 0 ? $wpdb->query(
			( $wpdb->prepare(
				"UPDATE %i SET group_id = %d, updated_at = updated_at WHERE type = 'string' AND object_id = %d AND group_id <> %d",
				Schema::table( 'translation_links' ),
				$group_id,
				$string_id,
				$group_id
			) ?? '' ) . ( '' === $marker ? '' : ' AND ' . $marker )
		) : false;
		$linked    = is_int( $moved ) ? $this->link_translated( $string_id, $group_id, $marker ) : null;

		if ( null !== $linked && 'shared' === $row['reason'] ) {
			$owner  = self::int( $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(id) FROM %i WHERE group_id = %d', Schema::table( 'strings' ), $row['group_id'] ) ) );
			$more   = '' === $wpdb->last_error ? ( $owner > 0 ? $this->link_translated( $owner, $row['group_id'], $marker ) : 0 ) : null;
			$linked = null === $more ? null : $linked + $more;
		}

		// A COMMIT wpdb sent again on a new connection committed nothing.
		if ( ! is_int( $moved ) || null === $linked || Schema::transaction_lost( $errors ) || ! $guard->verify() || false === $wpdb->query( 'COMMIT' ) || ! $guard->kept() ) {
			$wpdb->query( 'ROLLBACK' );
			TranslationGroupRepository::reset_static_caches();

			return null;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return [
			'moved'  => $moved,
			'linked' => $linked,
		];
	}

	/**
	 * Link each language the string has a translation in, no string link in, and a free place in the group.
	 *
	 * @param int    $string_id String.
	 * @param int    $group_id  Its string group.
	 * @param string $marker    The unit's connection marker, or '' (SQLite).
	 * @return int|null Links written; null when a statement failed.
	 */
	private function link_translated( int $string_id, int $group_id, string $marker ): ?int {
		$wpdb = self::db();

		$links = Schema::table( 'translation_links' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Inside the string's transaction; the value list is built from placeholders only.
		$langs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT t.language_id FROM %i t WHERE t.string_id = %d AND t.translation <> ''
				AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.type = 'string' AND l.object_id = t.string_id AND l.language_id = t.language_id )
				AND NOT EXISTS ( SELECT 1 FROM %i h WHERE h.group_id = %d AND h.language_id = t.language_id )
				ORDER BY t.language_id",
				Schema::table( 'string_translations' ),
				$string_id,
				$links,
				$links,
				$group_id
			)
		);

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		$values = [];

		foreach ( (array) $langs as $lang ) {
			array_push( $values, $group_id, $string_id, self::int( $lang ) );
		}

		$count = intdiv( count( $values ), 3 );

		if ( 0 === $count ) {
			return 0;
		}

		// With a marker, the rows are a SELECT that returns nothing on another connection.
		$first   = "SELECT %d AS group_id, %d AS object_id, %d AS language_id, 'string' AS type, 'translated' AS status, 'manual' AS source";
		$rows    = '' === $marker
			? 'VALUES ' . implode( ', ', array_fill( 0, $count, "(%d, %d, %d, 'string', 'translated', 'manual')" ) )
			: 'SELECT * FROM ( ' . implode( ' UNION ALL ', array_merge( [ $first ], array_fill( 0, $count - 1, "SELECT %d, %d, %d, 'string', 'translated', 'manual'" ) ) ) . ' ) AS v';
		$written = $wpdb->query( ( $wpdb->prepare( "INSERT INTO %i (group_id, object_id, language_id, type, status, source) {$rows}", $links, ...$values ) ?? '' ) . ( '' === $marker ? '' : ' WHERE ' . $marker ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $written === $count ? $count : null;
	}

	/**
	 * Links matching a condition on a list of ids.
	 *
	 * @param literal-string $where Condition ending in a column and IN.
	 * @param int[]          $ids   Ids.
	 * @return list<array{id: int, group_id: int, object_id: int, type: string}>
	 *
	 * @throws \RuntimeException When the read failed.
	 */
	private static function read_links( string $where, array $ids ): array {
		$wpdb = self::db();

		if ( [] === $ids ) {
			return [];
		}

		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is one of two literals; the list is built from placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, group_id, object_id, type FROM %i WHERE {$where} ({$in})", Schema::table( 'translation_links' ), ...$ids ), ARRAY_A );
		self::check_read();

		$out = [];

		foreach ( self::rows( $rows ) as $row ) {
			$out[] = [
				'id'        => self::int( $row['id'] ?? 0 ),
				'group_id'  => self::int( $row['group_id'] ?? 0 ),
				'object_id' => self::int( $row['object_id'] ?? 0 ),
				'type'      => is_string( $row['type'] ?? null ) ? $row['type'] : '',
			];
		}

		return $out;
	}

	/**
	 * Post types of the posts that exist among the ids.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, string>
	 *
	 * @throws \RuntimeException When the read failed.
	 */
	private static function post_types( array $ids ): array {
		$wpdb = self::db();

		$ids = array_values( array_unique( $ids ) );

		if ( [] === $ids ) {
			return [];
		}

		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The list is built from placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM %i WHERE ID IN ({$in})", $wpdb->posts, ...$ids ), ARRAY_A );
		self::check_read();

		$types = [];

		foreach ( self::rows( $rows ) as $row ) {
			$types[ self::int( $row['ID'] ?? 0 ) ] = is_string( $row['post_type'] ?? null ) ? $row['post_type'] : '';
		}

		return $types;
	}

	/**
	 * The terms that exist among the ids.
	 *
	 * @param int[] $ids Term ids.
	 * @return array<int, true>
	 *
	 * @throws \RuntimeException When the read failed.
	 */
	private static function existing_terms( array $ids ): array {
		$wpdb = self::db();

		$ids = array_values( array_unique( $ids ) );

		if ( [] === $ids ) {
			return [];
		}

		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The list is built from placeholders only.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM %i WHERE term_id IN ({$in})", $wpdb->terms, ...$ids ) );
		self::check_read();

		$terms = [];

		foreach ( (array) $found as $id ) {
			$terms[ self::int( $id ) ] = true;
		}

		return $terms;
	}

	/**
	 * Stop when the read that just ran failed (wpdb clears last_error at the
	 * start of every query).
	 *
	 * @phpstan-impure
	 * @return void
	 *
	 * @throws \RuntimeException When it failed.
	 */
	private static function check_read(): void {
		$wpdb = self::db();

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'A database read failed: ' . esc_html( $wpdb->last_error ) );
		}
	}

	/**
	 * The database handle.
	 *
	 * @return \wpdb
	 *
	 * @throws \RuntimeException When there is none.
	 */
	private static function db(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'No database connection.' );
		}

		return $wpdb;
	}

	/**
	 * Result rows as arrays.
	 *
	 * @param mixed $rows get_results() answer.
	 * @return list<array<mixed>>
	 */
	private static function rows( mixed $rows ): array {
		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * A column value as an int (0 when it is not numeric).
	 *
	 * @param mixed $value Column value.
	 * @return int
	 */
	private static function int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
