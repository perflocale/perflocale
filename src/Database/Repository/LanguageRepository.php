<?php
/**
 * Language repository.
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
 * Data access for perflocale_languages: reads through the 3-layer cache; writes (LanguageWrites, loaded on first use)
 * invalidate it and fire hooks.
 */
final class LanguageRepository implements RepositoryInterface {

	/**
	 * The slug shape the routing layer can express (the REST item routes, create_item() and SlugRedirector hard-code
	 * it); every entry of data/languages.php matches. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: the slug
	 * shape and the bootstrap".
	 */
	public const SLUG_PATTERN = '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/';

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
	 * The write half, loaded on first use.
	 *
	 * @return LanguageWrites
	 */
	private function writes(): LanguageWrites {
		return new LanguageWrites( $this, $this->cache, $this->wpdb );
	}

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'languages' );
	}

	/**
	 * A language by id.
	 *
	 * @param int $id Language ID.
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		// An active language comes from the bootstrap already in memory, not a per-id transient (two queries): the front
		// end asks for the current language by id on every request.
		$bootstrap = $this->get_bootstrap();

		foreach ( $bootstrap['active'] as $row ) {
			if ( (int) $row->id === $id ) {
				return $row;
			}
		}

		// Inactive languages or a bootstrap miss: the per-id cache (admin dropdowns list every language).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$cached = $this->cache->get(
			"language_{$id}",
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			fn() => $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d',
					$this->table(),
					$id
				)
			),
			DAY_IN_SECONDS,
			'perflocale_langs'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A non-object payload from a corrupted persistent cache is a miss (see find_by_slug()).
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * Batch-fetch languages by IDs: one query for the uncached ones, which it caches for find().
	 *
	 * @param array<int, int> $ids Language IDs.
	 * @return array<int, object> Map of id => language object. Missing IDs are absent.
	 */
	public function find_many( array $ids ): array {
		return LanguageFindMany::find( $ids, fn(): array => $this->get_bootstrap()['active'], $this->cache, $this->wpdb );
	}

	/**
	 * A language by URL slug.
	 *
	 * @param string $slug Language slug (e.g. 'en', 'fr').
	 * @return object|null
	 */
	public function find_by_slug( string $slug ): ?object {
		$slug = sanitize_key( $slug );

		// The bootstrap's slug map first (every active language).
		$map = $this->get_bootstrap()['slug_map'];

		if ( isset( $map[ $slug ] ) ) {
			$bootstrap_hit = $map[ $slug ];
			// A persistent cache can return a malformed payload ('' from an older serialiser): a miss, re-queried, so the
			// ?object return type never fatals.
			if ( is_object( $bootstrap_hit ) ) {
				return $bootstrap_hit;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$cached = $this->cache->get(
			"language_slug_{$slug}",
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			fn() => $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE slug = %s',
					$this->table(),
					$slug
				)
			),
			DAY_IN_SECONDS,
			'perflocale_langs'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A non-object cached payload is corruption: null now, replaced on the next rename or language change.
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * A language by WordPress locale.
	 *
	 * @param string $locale Locale string (e.g. 'en_US').
	 * @return object|null
	 */
	public function find_by_locale( string $locale ): ?object {
		$locale = sanitize_text_field( $locale );

		// The bootstrap first: active languages, what the public filters need.
		foreach ( $this->get_bootstrap()['active'] as $row ) {
			if ( isset( $row->locale ) && (string) $row->locale === $locale ) {
				return $row;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$cached = $this->cache->get(
			"language_locale_{$locale}",
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			fn() => $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT * FROM %i WHERE locale = %s',
					$this->table(),
					$locale
				)
			),
			DAY_IN_SECONDS,
			'perflocale_langs'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A non-object cached payload is a miss (see find_by_slug()).
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * The bootstrap bundle: active list, default and slug map in one transient (2 queries instead of 6 without a
	 * persistent cache) and one coherent snapshot.
	 *
	 * @return array{active: array<int, object>, default: ?object, slug_map: array<string, object>}
	 */
	private function get_bootstrap(): array {
		$had_db_error = false;

		$result = $this->cache->get(
			'bootstrap',
			function () use ( &$had_db_error ): array {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// Probe the table first: wp_initialize_site fires switch_blog before activation runs on a new blog.
				$table_escaped = $this->wpdb->esc_like( $this->table() );
				$exists        = (string) $this->wpdb->get_var(
					$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_escaped )
				);

				// A failed probe (deadlock, "server gone away") also answers '': flag it so the empty bootstrap is not cached.
				if ( $this->wpdb->last_error !== '' ) {
					$had_db_error = true;
				}

				if ( $exists === '' ) {
					return [
						'active'   => [],
						'default'  => null,
						'slug_map' => [],
					];
				}

				// One SELECT of the active languages for all three parts.
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						'SELECT * FROM %i WHERE is_active = 1 ORDER BY sort_order ASC, id ASC',
						$this->table()
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// A query error is never cached, only a successful empty table; last_error decides (get_results() returns null or
				// [] on error by driver).
				if ( $this->wpdb->last_error !== '' ) {
					$had_db_error = true;
				}

				$rows = is_array( $rows ) ? $rows : [];

				$default  = null;
				$slug_map = [];

				foreach ( $rows as $row ) {
					if ( ! empty( $row->is_default ) ) {
						$default = $row;
					}

					if ( isset( $row->slug ) && $row->slug !== '' ) {
						$slug_map[ (string) $row->slug ] = $row;
					}
				}

				return [
					'active'   => $rows,
					'default'  => $default,
					'slug_map' => $slug_map,
				];
			},
			DAY_IN_SECONDS,
			'perflocale_langs'
		);

		// A cold-load error would persist an empty bootstrap for a day and make the site monolingual: drop it so the next
		// request retries. A real empty table stays cached; nothing is announced (no data changed).
		if ( $had_db_error ) {
			$this->cache->evict( 'bootstrap', 'perflocale_langs' );
		}

		return $result;
	}

	/**
	 * Every active language by sort_order, from the bootstrap (the most called method).
	 *
	 * @return array<int, object>
	 */
	public function get_active(): array {
		return $this->get_bootstrap()['active'];
	}

	/**
	 * The default language.
	 *
	 * @return object|null
	 */
	public function get_default(): ?object {
		return $this->get_bootstrap()['default'];
	}

	/**
	 * Slug => language map.
	 *
	 * @return array<string, object>
	 */
	public function get_slug_map(): array {
		return $this->get_bootstrap()['slug_map'];
	}

	/**
	 * Languages matching the arguments.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, object>
	 */
	public function find_all( array $args = [] ): array {
		// The no-argument call (every language by sort_order: admin screens, the REST list) is cached in the bootstrap's
		// group, so flush_languages() clears both.
		$is_canonical_default = empty( $args ) || (
			array_key_exists( 'is_active', $args ) && $args['is_active'] === null
			&& ( ( $args['orderby'] ?? 'sort_order' ) === 'sort_order' )
			&& ( strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) === 'ASC' )
		);

		if ( $is_canonical_default ) {
			return $this->cache->get(
				'all_sorted',
				function (): array {
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $this->wpdb->get_results(
						$this->wpdb->prepare(
							'SELECT * FROM %i ORDER BY sort_order ASC, id ASC',
							$this->table()
						)
					);
					// phpcs:enable
					return is_array( $rows ) ? $rows : [];
				},
				DAY_IN_SECONDS,
				'perflocale_langs'
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$defaults = [
			'is_active' => null,
			'orderby'   => 'sort_order',
			'order'     => 'ASC',
		];

		$args       = wp_parse_args( $args, $defaults );
		$where      = '1=1';
		$query_args = [];

		if ( $args['is_active'] !== null ) {
			$where       .= ' AND is_active = %d';
			$query_args[] = (int) $args['is_active'];
		}

		$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );

		if ( ! $orderby ) {
			$orderby = 'sort_order ASC';
		}

		$sql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY {$orderby}";

		if ( ! empty( $query_args ) ) {
			$sql = $this->wpdb->prepare( $sql, ...$query_args ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $results ) ? $results : [];
	}

	/**
	 * Insert a language.
	 *
	 * @param array<string, mixed> $data Language data.
	 * @return int|false Inserted ID or false.
	 */
	public function insert( array $data ): int|false {
		return $this->writes()->insert( $data );
	}

	/**
	 * Rename a language's slug and record an old => new 301 so `/old-slug/...` URLs keep working. Data-cheap (tables use
	 * the id); the cost is the rewrite flush and the redirect the front end reads.
	 *
	 * @param int    $id       Language ID.
	 * @param string $new_slug Proposed new slug (lowercase, alpha + hyphens).
	 * @return bool True on success.
	 */
	public function rename_slug( int $id, string $new_slug ): bool {
		return $this->writes()->rename_slug( $id, $new_slug );
	}

	/**
	 * The slug redirect map, read by the front-end redirect on every request (one autoloaded option).
	 *
	 * @return array<string, string> [old_slug => new_slug]
	 */
	public static function get_slug_redirects(): array {
		return (array) get_option( self::REDIRECTS_OPTION, [] );
	}

	/**
	 * Option of the slug redirect map, public so uninstall can delete it without loading this class.
	 */
	public const REDIRECTS_OPTION = 'perflocale_slug_redirects';

	/**
	 * Update a language.
	 *
	 * @param int $id Language ID.
	 * @param array<string, mixed> $data Data to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		return $this->writes()->update( $id, $data );
	}

	/**
	 * Rewrite sort_order for these ids in their order, starting at `$offset + 1` (a paginated slice keeps other pages'
	 * range). Every id is validated before any write, so a bogus one cannot half-apply.
	 *
	 * @param array<int, int> $ordered_ids Language IDs in their new visual order.
	 * @param int             $offset      Starting position (0-based). Sort orders begin at `offset + 1`.
	 * @return int Number of rows updated.
	 */
	public function reorder( array $ordered_ids, int $offset = 0 ): int {
		return $this->writes()->reorder( $ordered_ids, $offset );
	}

	/**
	 * Count what delete() would remove for a language, for the delete preview: each key mirrors one cascade step of
	 * delete(); keep the two in step.
	 *
	 * @param int $id Language ID.
	 * @return array<string, int> Table-ish label => row count.
	 */
	public function count_cascade( int $id ): array {
		return $this->writes()->count_cascade( $id );
	}

	/**
	 * Count the content that blocks deleting a language: its posts, which would become default-language duplicates with
	 * split WooCommerce stock (trash them or deactivate the language). Attachments, terms and templates are reported
	 * only. Docs: docs-ai/11-code-rationale.md, "LanguageRepository: deleting a language".
	 *
	 * @param int $id Language ID.
	 * @return array{posts: array<string, int>, attachments: int, terms: int, templates: int}|null
	 *         Blocking post counts by post type, plus the informational counts;
	 *         null when the count query failed (callers must refuse).
	 */
	public function delete_blockers( int $id ): ?array {
		return $this->writes()->delete_blockers( $id );
	}

	/**
	 * Delete a language and everything that references it.
	 *
	 * @param int $id Language ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return $this->writes()->delete( $id );
	}

	/**
	 * Make a language the default (unsetting the current one).
	 *
	 * @param int $id Language ID to make default.
	 * @return bool
	 * @throws \RuntimeException When the unset-default or set-default UPDATE fails inside the transaction.
	 */
	public function set_default( int $id ): bool {
		return $this->writes()->set_default( $id );
	}

	/**
	 * Sanitize language data for insert and update; public so the data import stores an untrusted user's language row
	 * by these rules. Sanitizes only: the slug shape is checked by insert() and update().
	 *
	 * @internal
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed> Sanitized data.
	 */
	public static function sanitize_data( array $data ): array {
		return LanguageWrites::sanitize_data( $data );
	}
}
