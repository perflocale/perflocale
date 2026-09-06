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
 * Data access layer for the perflocale_languages table.
 *
 * All read methods use the 3-layer cache. Write methods invalidate
 * relevant cache entries and fire hooks.
 */
final class LanguageRepository implements RepositoryInterface {

	/**
	 * The slug shape the routing layer can actually express.
	 *
	 * This is not a house style rule — it is the grammar three other
	 * surfaces already hard-code, so a slug outside it is a language the
	 * plugin cannot fully address:
	 *
	 *   - `LanguagesController` registers its item routes as
	 *     `/languages/(?P<slug>[a-z]{2,3}(?:-[a-z]{2,3})?)` and
	 *     `/translations/…/(?P<lang>[a-z]{2,3}(?:-[a-z]{2,3})?)`, so
	 *     GET/PATCH/DELETE-by-slug and the machine-translate write route
	 *     404 for anything else;
	 *   - `LanguagesController::create_item` rejects it at validation;
	 *   - `SlugRedirector` only recognises this shape as a candidate URL
	 *     prefix, so the 301 a rename records can never fire for it.
	 *
	 * All 194 entries in `data/languages.php` match, so nothing the plugin
	 * itself offers is excluded.
	 */
	private const SLUG_PATTERN = '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/';

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
		return Schema::table( 'languages' );
	}

	/**
	 * Find a language by ID.
	 *
	 * @param int $id Language ID.
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		// Serve from the bundled bootstrap cache when the ID belongs to an
		// active language - it's already in memory after the first request-
		// scoped access. Avoids a separate per-ID transient read (two DB
		// queries) for the common case where UrlConverter et al. ask for the
		// current language by id on every frontend request.
		$bootstrap = $this->get_bootstrap();

		foreach ( $bootstrap['active'] as $row ) {
			if ( (int) $row->id === $id ) {
				return $row;
			}
		}

		// Fallback - an inactive language, or a transient cache miss. Use the
		// individual per-id cache for these so they don't hammer the DB when
		// legitimately needed (e.g. admin editor showing a dropdown of every
		// language including inactive ones).
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

		// Defensive: see find_by_slug() — non-object cache payloads from
		// corrupted persistent-cache layers are treated as misses so the
		// strict ?object return type can't trip a TypeError fatal.
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * Batch-fetch languages by IDs with a single SQL query + cache priming.
	 *
	 * Intended for hot paths (sitemap rendering, hreflang output, URL
	 * preloading) that would otherwise call find() in a loop. Cache-aware:
	 * cached IDs are served from memory, uncached IDs are pulled in ONE
	 * query and cached individually so subsequent find() calls hit L1.
	 *
	 * @param array<int, int> $ids Language IDs.
	 * @return array<int, object> Map of id => language object. Missing IDs are absent.
	 */
	public function find_many( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$ids = array_filter( $ids, static fn( int $id ): bool => $id > 0 );

		if ( $ids === [] ) {
			return [];
		}

		$result  = [];
		$missing = [];

		// First pass: satisfy from the bundled bootstrap (covers every active
		// language in one in-memory lookup), then the per-id L1 cache (covers
		// inactive languages, which find() stores individually).
		$bootstrap_by_id = [];

		foreach ( $this->get_bootstrap()['active'] as $row ) {
			$bootstrap_by_id[ (int) $row->id ] = $row;
		}

		foreach ( $ids as $id ) {
			if ( isset( $bootstrap_by_id[ $id ] ) ) {
				$result[ $id ] = $bootstrap_by_id[ $id ];
				continue;
			}

			$cached = $this->cache->get_static( "language_{$id}", 'perflocale_langs' );

			if ( is_object( $cached ) ) {
				$result[ $id ] = $cached;
			} else {
				$missing[] = $id;
			}
		}

		if ( $missing === [] ) {
			return $result;
		}

		// One prepared IN() query for everything not already cached.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE id IN ({$placeholders})",
				array_merge( [ $this->table() ], $missing )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		// Prime the cache per row so subsequent find() calls are free.
		foreach ( $rows as $row ) {
			$id = (int) $row->id;
			$this->cache->set( "language_{$id}", $row, DAY_IN_SECONDS, 'perflocale_langs' );
			$result[ $id ] = $row;
		}

		return $result;
	}

	/**
	 * Find a language by its URL slug.
	 *
	 * @param string $slug Language slug (e.g. 'en', 'fr').
	 * @return object|null
	 */
	public function find_by_slug( string $slug ): ?object {
		$slug = sanitize_key( $slug );

		// Bootstrap bundle already has an O(1) slug → object hash for every
		// active language. Short-circuit to it before falling through to the
		// transient/DB path.
		$map = $this->get_bootstrap()['slug_map'];

		if ( isset( $map[ $slug ] ) ) {
			$bootstrap_hit = $map[ $slug ];
			// Defensive: the persistent cache layer (Redis Object Cache
			// drop-in) has been observed to return malformed payloads
			// (empty string instead of stdClass) from older serialiser
			// generations. The slug_map should ALWAYS contain stdClass
			// objects; if it doesn't, treat the entry as a cache miss
			// and re-query rather than satisfying the strict ?object
			// return type with a string and triggering a TypeError fatal.
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

		// Same defensive check as the bootstrap path: a non-object cached
		// payload is corruption, not a hit. Fall through to null so the
		// strict return type holds and the caller can re-resolve cleanly
		// on the next request (the cache delete on rename / language CRUD
		// will eventually replace the bad entry).
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * Find a language by its WordPress locale.
	 *
	 * @param string $locale Locale string (e.g. 'en_US').
	 * @return object|null
	 */
	public function find_by_locale( string $locale ): ?object {
		$locale = sanitize_text_field( $locale );

		// Scan the bootstrap bundle first - active languages only, which is
		// what the public filters (locale swap, hreflang) ever care about.
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

		// Defensive: see find_by_slug() — non-object cache payloads from
		// corrupted persistent-cache layers are treated as misses.
		return is_object( $cached ) ? $cached : null;
	}

	/**
	 * Bundled language bootstrap: active list + default + slug map.
	 *
	 * Frontend requests invariably need all three - language detection needs
	 * the slug map, the front-page router needs the default, and hreflang
	 * output iterates the active list - so storing them as three separate
	 * transients cost 6 DB lookups per request on sites without a persistent
	 * object cache (each transient read is value + timeout = 2 queries).
	 *
	 * Consolidating into one transient cuts that to 2 queries and guarantees
	 * a coherent snapshot: you can never see "active_languages" updated but
	 * "default_language" stale against the same underlying row set.
	 *
	 * @return array{active: array<int, object>, default: ?object, slug_map: array<string, object>}
	 */
	private function get_bootstrap(): array {
		$had_db_error = false;

		$result = $this->cache->get(
			'bootstrap',
			function () use ( &$had_db_error ): array {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// Multisite safety: probe that the languages table exists on
				// this blog before querying. wp_initialize_site fires
				// switch_blog BEFORE activation runs on a new blog, so the
				// SELECT below would error ("Table doesn't exist") and pollute
				// debug.log on every subsite provisioning. SHOW TABLES LIKE is
				// a cheap non-throwing probe.
				$table_escaped = $this->wpdb->esc_like( $this->table() );
				$exists        = (string) $this->wpdb->get_var(
					$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_escaped )
				);

				// wpdb resets last_error at the start of every query, so it now
				// reflects this probe. A transient error (deadlock, "server gone
				// away") also yields '' for $exists — flag it so the empty
				// bootstrap below is not cached for a day (see the delete below).
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

				// One SELECT serves all three accessors. The rows are tiny
				// (5 or so per site typically) so fetching even inactive
				// languages here would be cheap, but sticking to is_active=1
				// keeps behaviour identical to the old get_active().
				$rows = $this->wpdb->get_results(
					$this->wpdb->prepare(
						'SELECT * FROM %i WHERE is_active = 1 ORDER BY sort_order ASC, id ASC',
						$this->table()
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// A query error must not be cached — only a genuinely empty (but
				// successful) table should be. wpdb->last_error is the reliable
				// signal: get_results() returns null OR an empty array on error
				// depending on the driver, so checking the return type alone is
				// not enough; a non-empty last_error means the query failed.
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

		// A transient DB error on the cold load collapses to an empty bootstrap
		// that cache->get() just persisted for a full day — which would silently
		// turn the whole site monolingual (no languages → no hreflang, empty
		// switcher) until a manual flush. Drop it so the NEXT request retries
		// the DB. The genuinely-empty (successful, no active languages) case has
		// $had_db_error === false and stays cached.
		if ( $had_db_error ) {
			$this->cache->delete( 'bootstrap', 'perflocale_langs' );
		}

		return $result;
	}

	/**
	 * Get all active languages, sorted by sort_order.
	 *
	 * This is the most frequently called method - served from the bundled
	 * bootstrap cache.
	 *
	 * @return array<int, object>
	 */
	public function get_active(): array {
		return $this->get_bootstrap()['active'];
	}

	/**
	 * Get the default language.
	 *
	 * @return object|null
	 */
	public function get_default(): ?object {
		return $this->get_bootstrap()['default'];
	}

	/**
	 * Get a slug → language object hash map for O(1) lookups.
	 *
	 * @return array<string, object>
	 */
	public function get_slug_map(): array {
		return $this->get_bootstrap()['slug_map'];
	}

	/**
	 * Find all languages matching criteria.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, object>
	 */
	public function find_all( array $args = [] ): array {
		// Fast-path: the no-args / canonical-defaults call returns "every
		// language, sorted by sort_order ASC." That's the dominant pattern
		// across admin pages and the Languages REST list endpoint. Cache it
		// under the same `perflocale_langs` group as the bootstrap bundle
		// so flush_languages() invalidates both in one call.
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
	 * Insert a new language.
	 *
	 * @param array<string, mixed> $data Language data.
	 * @return int|false Inserted ID or false.
	 */
	public function insert( array $data ): int|false {
		$sanitized = $this->sanitize_data( $data );

		// A language with no slug can never match a rewrite rule, so the row is
		// dead on arrival. It was reachable: the Add Language screen sanitises
		// with sanitize_key(), which has no /u modifier and therefore deletes
		// every byte of a non-ASCII slug, and update() and rename_slug() both
		// validate their slug while this did not. The column's UNIQUE key let
		// exactly one such row exist, and the screen reported success.
		if ( ! isset( $sanitized['slug'] ) || $sanitized['slug'] === '' ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert( $this->table(), $sanitized );

		if ( $result === false ) {
			return false;
		}

		$id = (int) $this->wpdb->insert_id;

		$this->cache->flush_languages();

		// Adding a language makes its slug live, so the same rule as in
		// apply_slug_rename() applies: it must not remain a redirect SOURCE.
		// This is the Add Language screen's own designed flow — the rename
		// checkboxes move an existing language out of the way (recording
		// `old => new`) and the new language is then inserted under the freed
		// slug, so without this prune the language the admin just added has
		// its entire URL prefix 301'd into the renamed one.
		$this->drop_stale_slug_redirect(
			isset( $sanitized['slug'] ) && is_string( $sanitized['slug'] ) ? $sanitized['slug'] : ''
		);

		$language = $this->find( $id );

		/** @hook perflocale/language/added Fires after a new language is added. */
		do_action( 'perflocale/language/added', $language );

		return $id;
	}

	/**
	 * Remove a now-live slug from the 301 redirect map.
	 *
	 * {@see apply_slug_rename()} prunes inline because it already holds the
	 * decoded map; this is the standalone path for writers that only make a
	 * slug live (currently {@see insert()}). Reads the option first and writes
	 * only on an actual hit, so the common case — adding a language on a site
	 * that has never renamed one — costs a single autoloaded array lookup and
	 * no write at all.
	 *
	 * @param string $slug Slug that just became live.
	 * @return void
	 */
	private function drop_stale_slug_redirect( string $slug ): void {
		if ( $slug === '' ) {
			return;
		}

		$redirects = (array) get_option( self::REDIRECTS_OPTION, [] );

		if ( ! array_key_exists( $slug, $redirects ) ) {
			return;
		}

		unset( $redirects[ $slug ] );

		if ( $redirects === [] ) {
			delete_option( self::REDIRECTS_OPTION );

			// delete_option() returns early WITHOUT clearing the options cache
			// when the row is already gone; purge unconditionally so the rest
			// of this request can't read a stale autoloaded copy.
			wp_cache_delete( self::REDIRECTS_OPTION, 'options' );

			return;
		}

		// Autoload true — matches apply_slug_rename()'s write; SlugRedirector
		// reads this on every front-end request.
		update_option( self::REDIRECTS_OPTION, $redirects, true );
	}

	/**
	 * Rename a language's slug, persisting an old→new redirect record so
	 * the existing URL prefix `/old-slug/...` 301s to `/new-slug/...` for
	 * SEO + bookmark continuity.
	 *
	 * Translation links and every other table use language_id (not slug)
	 * as the FK, so the rename is data-cheap. The expensive parts are
	 * (a) flushing the URL rewrite rules so the new prefix takes over and
	 * (b) the redirect entry the frontend handler reads on every request.
	 *
	 * @param int    $id       Language ID.
	 * @param string $new_slug Proposed new slug (lowercase, alpha + hyphens).
	 * @return bool True on success.
	 */
	public function rename_slug( int $id, string $new_slug ): bool {
		$new_slug = strtolower( trim( $new_slug ) );

		// Strict shape check: lowercase letters + optional hyphen-region.
		// Shared with update(), whose Slug field is editable too — see
		// {@see self::SLUG_PATTERN} for the surfaces that hard-code it.
		if ( ! preg_match( self::SLUG_PATTERN, $new_slug ) ) {
			return false;
		}

		$old = $this->find( $id );

		if ( ! $old || (string) $old->slug === $new_slug ) {
			return false;
		}

		// Bail out if another language already owns the target slug —
		// uniqueness is enforced by the column type, but a clean false here
		// gives a better error than a wpdb-level constraint error.
		if ( $this->find_by_slug( $new_slug ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table(),
			[ 'slug' => $new_slug ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( $result === false ) {
			return false;
		}

		$old_slug = (string) $old->slug;

		// Everything a slug change implies beyond the row write itself —
		// the 301 record, the reference migration, cache invalidation and
		// the rewrite flush — lives in apply_slug_rename() so update(),
		// whose Slug field is editable too, cannot drift from this path.
		$this->apply_slug_rename( $id, $old_slug, $new_slug );

		return true;
	}

	/**
	 * Apply everything a slug change implies beyond the languages-row
	 * UPDATE itself: the 301 redirect record, the slug-reference
	 * migration, cache invalidation and the rewrite-rule flush flag.
	 *
	 * Shared by {@see rename_slug()} and {@see update()} — the Edit
	 * Language form renders an editable Slug field, so update() writes the
	 * column too and a slug changed through that path needs the identical
	 * follow-through. Keeping the follow-through here rather than routing
	 * update() into rename_slug() avoids a second write of the same row;
	 * the shape contract itself is shared instead, as
	 * {@see self::SLUG_PATTERN}, so neither entry point can mint a slug the
	 * REST item routes and SlugRedirector cannot address.
	 *
	 * @param int    $id       Language ID whose slug just changed.
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return void
	 */
	private function apply_slug_rename( int $id, string $old_slug, string $new_slug ): void {
		// Persist a redirect record. Stored in a single option (not a
		// table) because the typical site renames at most a handful of
		// slugs over its lifetime; an option array stays well under the
		// autoload size budget.
		$redirects = (array) get_option( self::REDIRECTS_OPTION, [] );

		// Compress redirect chains: any existing entry that pointed at the
		// OLD slug must now point at the NEW slug, so a single 301 lands
		// the visitor at the final destination instead of chaining
		// `/old1/ → /old2/ → /new/`. Search engines (notably Google)
		// devalue chained redirects; SEO-sensitive sites care here.
		foreach ( $redirects as $existing_old => $existing_new ) {
			if ( $existing_new === $old_slug ) {
				$redirects[ $existing_old ] = $new_slug;
			}
		}

		$redirects[ $old_slug ] = $new_slug;

		// The new slug is LIVE from here on, and a live slug must never stay a
		// redirect SOURCE. A leftover `new_slug => …` entry — left by an
		// earlier rename away from this slug, or by two languages swapping
		// slugs — makes the whole `/new-slug/` prefix 301 into the other
		// language, i.e. the language we just renamed becomes unreachable on
		// the front end. Pruning belongs on the write side because the read
		// side is asymmetric: LanguageRouter::maybe_redirect_renamed_query_slug()
		// checks the live slug map before honouring an entry, but
		// SlugRedirector::maybe_redirect() — subdirectory mode, the one mode
		// where the slug IS the path prefix — only validates the redirect
		// TARGET, never whether the source is a live language.
		unset( $redirects[ $new_slug ] );

		// Drop self-mappings (`x => x`) that compression could leave
		// behind when a slug is renamed back to itself transitively.
		$redirects = array_filter(
			$redirects,
			static fn( $v, $k ) => $v !== $k,
			ARRAY_FILTER_USE_BOTH
		);

		// Keep the last 50 redirects to bound the option size.
		if ( count( $redirects ) > 50 ) {
			$redirects = array_slice( $redirects, -50, null, true );
		}
		// Autoloaded: SlugRedirector reads this on every front-end request, so
		// keeping it in the bulk alloptions load avoids a per-request query.
		// The 50-entry cap keeps it tiny enough for the autoload budget.
		update_option( self::REDIRECTS_OPTION, $redirects, true );

		// Migrate every slug-as-value/slug-as-key reference scattered
		// outside the language_id FK graph. Without this, renaming `en`
		// → `en-us` silently drops alt-text/caption/description on
		// attachments, breaks linked nav menus, leaves stale entries in
		// the geo + fallbacks + domains settings, and decimates user
		// per-screen lang-hide preferences. All migrations run inside
		// this method so the rename is atomic from the caller's view.
		$this->migrate_slug_references( $old_slug, $new_slug );

		// Cache invalidation: belt-and-brace the group flush with explicit key
		// deletes because some persistent backends (older Redis Object Cache
		// on certain PHP/Redis pairings) don't fully honour flush_group() for
		// compound key prefixes, leaving stale `language_slug_<old>` entries
		// that resolve to the renamed language until TTL.
		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );
		$this->cache->delete( "language_slug_{$old_slug}", 'perflocale_langs' );
		$this->cache->delete( "language_slug_{$new_slug}", 'perflocale_langs' );

		// The language-scoped caches above are not the whole picture: the
		// eager link map has the OLD slug baked into every link row of this
		// language, and it outlives any TTL. Same remedy the language-delete
		// cascade uses.
		$this->flush_translation_link_caches();

		// Mark rewrite rules for flushing on next request (the rule regex
		// is built from active language slugs).
		update_option( 'perflocale_flush_rules', 1, false );

		/** @hook perflocale/language/slug_renamed Fires after a language slug rename succeeds. */
		do_action( 'perflocale/language/slug_renamed', $id, $old_slug, $new_slug );
	}

	/**
	 * Drop every cache layer that denormalises language columns into
	 * translation-link rows.
	 *
	 * The autoloaded eager link map (`perflocale_eager_links_{type}`) caches
	 * `language_slug` / `language_name` / `language_native_name` alongside
	 * each link, and get_translations() consults it BEFORE the TTL'd cache —
	 * so on a site under the map's size caps that option IS the source of
	 * truth for those columns, and it carries no TTL — only an explicit
	 * invalidation clears it. CacheManager::flush_all() (the admin
	 * Clear-cache button and `wp perflocale cache flush`) deletes the five
	 * options too, but that is an operator's last resort; the write that
	 * changed the column has to invalidate at the write site or the map stays
	 * wrong until somebody thinks to clear the cache. A stale slug there is
	 * user-visible: SitemapIntegration and UrlConverter both gate on
	 * slug-map membership, so the language silently drops out of hreflang,
	 * sitemap alternates and the switcher, and its permalinks lose the
	 * language prefix entirely.
	 *
	 * @return void
	 */
	private function flush_translation_link_caches(): void {
		$group_repo = new TranslationGroupRepository( $this->cache );
		$group_repo->invalidate_eager_link_map();
		TranslationGroupRepository::reset_static_caches();
		$this->cache->flush_translations();
	}

	/**
	 * Migrate every slug-as-value / slug-as-key reference outside the
	 * languages table itself. Called from {@see rename_slug()} so the
	 * rename atomically updates every place a slug is stored. Coverage:
	 *
	 *   KEY-suffix migrations (rename meta_key, value untouched):
	 *     - `_perflocale_alt_<slug>` post meta          — per-language alt
	 *     - `_perflocale_caption_<slug>` post meta     — per-language caption
	 *     - `_perflocale_description_<slug>` post meta — per-language desc
	 *     - `_perflocale_menu_<slug>` term meta        — linked-menu pointers
	 *
	 *   VALUE migrations (rename meta_value, key untouched):
	 *     - `_perflocale_language` term meta — nav-menu language tag
	 *     - `_perflocale_language` post meta — WC order language tag
	 *       (see WooCommerce\EmailTranslation::ORDER_LANG_META)
	 *
	 *   Option / settings array migrations (KEY and/or VALUE inside arrays):
	 *     - perflocale_settings.geo_country_map      — slug as VALUE
	 *     - perflocale_settings.language_fallbacks   — slug as KEY and VALUE
	 *     - perflocale_settings.language_domains     — slug as KEY
	 *     - perflocale_settings.wc_currencies        — slug as KEY
	 *     - perflocale_exchange_rates                — slug as KEY
	 *
	 *   User-meta migrations (slug as VALUE inside serialised array):
	 *     - perflocale_strings_hidden_langs
	 *     - perflocale_translations_hidden_langs
	 *
	 *   Cache invalidation: term-meta and post-meta caches are busted for
	 *   every term / post whose VALUE row was rewritten — direct
	 *   $wpdb->update doesn't fire the meta hooks, so without the explicit
	 *   wp_cache_delete_multiple a persistent-cache backend would keep
	 *   serving the OLD slug until the natural cache TTL expires.
	 *
	 * Translation links / groups / slug_translations
	 * all reference languages by `language_id` (FK) and do NOT need migrating.
	 *
	 * @param string $old_slug Pre-rename slug.
	 * @param string $new_slug Post-rename slug.
	 * @return void
	 */
	private function migrate_slug_references( string $old_slug, string $new_slug ): void {
		global $wpdb;

		// 1. Attachment meta keys interpolated with slug. A direct UPDATE of
		// the meta_key column is far cheaper than load-modify-save via
		// update_post_meta per attachment. The slow-meta-query sniff is a
		// false positive here: meta_key indexes the WHERE (EXPLAIN: range),
		// rows are per-language (single digits), admin-only rename path.
		$post_meta_prefixes = [
			'_perflocale_alt_',
			'_perflocale_caption_',
			'_perflocale_description_',
		];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		foreach ( $post_meta_prefixes as $prefix ) {
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_key' => $prefix . $new_slug ],
				[ 'meta_key' => $prefix . $old_slug ],
				[ '%s' ],
				[ '%s' ]
			);
		}

		// 2. Nav-menu linked-menu term meta. Same pattern; same justification
		// as above — wp_termmeta.meta_key is indexed, EXPLAIN is a range
		// seek, row count is one per language, admin-only path.
		$wpdb->update(
			$wpdb->termmeta,
			[ 'meta_key' => '_perflocale_menu_' . $new_slug ],
			[ 'meta_key' => '_perflocale_menu_' . $old_slug ],
			[ '%s' ],
			[ '%s' ]
		);

		// 2b. Term meta `_perflocale_language` — VALUE is the slug. A nav menu
		// tagged with a language stores the slug as the value; without the
		// rename MenuManager can't match the menu to any active language
		// (the linked-menus UI shows "None"). SELECT first so we know which
		// terms to bust in the cache.
		$affected_term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
				'_perflocale_language',
				$old_slug
			)
		);

		if ( ! empty( $affected_term_ids ) ) {
			// One-shot slug-rename migration; not a hot path. Plugin Check
			// warns on `meta_value` filtering, but the alternative would
			// require denormalising into a dedicated table just for this
			// rare admin operation.
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->update(
				$wpdb->termmeta,
				[ 'meta_value' => $new_slug ],
				[
					'meta_key'   => '_perflocale_language',
					'meta_value' => $old_slug,
				],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			// Bust term-meta cache for every touched term — direct
			// $wpdb->update doesn't fire the meta hooks that invalidate
			// it. Without this, the rest of this request (and any
			// persistent-cache backend) would still serve the OLD slug
			// from get_term_meta(). wp_cache_delete_multiple is WP 6.0+;
			// fall back to a per-id loop for older versions.
			$ids_int = array_map( 'intval', (array) $affected_term_ids );

			if ( function_exists( 'wp_cache_delete_multiple' ) ) {
				wp_cache_delete_multiple( $ids_int, 'term_meta' );
			} else {
				foreach ( $ids_int as $tid ) {
					wp_cache_delete( $tid, 'term_meta' );
				}
			}
		}

		// 2c. Post meta `_perflocale_language` — VALUE is the slug. Currently
		// only WooCommerce orders carry it (EmailTranslation::ORDER_LANG_META)
		// so order emails go out in the order's language; without the rename
		// the match drops and the next status email renders in the site
		// default. The query is generic, covering any future consumer.
		$affected_post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_perflocale_language',
				$old_slug
			)
		);

		if ( ! empty( $affected_post_ids ) ) {
			// One-shot slug-rename migration; not a hot path (see termmeta
			// note above).
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => $new_slug ],
				[
					'meta_key'   => '_perflocale_language',
					'meta_value' => $old_slug,
				],
				[ '%s' ],
				[ '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			$ids_int = array_map( 'intval', (array) $affected_post_ids );

			if ( function_exists( 'wp_cache_delete_multiple' ) ) {
				wp_cache_delete_multiple( $ids_int, 'post_meta' );
			} else {
				foreach ( $ids_int as $pid ) {
					wp_cache_delete( $pid, 'post_meta' );
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		// 3. Plugin settings — operate on the in-memory option array, then
		// persist once. Avoids three separate update_option calls and
		// keeps cache-invalidation simple.
		$settings = get_option( 'perflocale_settings', [] );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$dirty = false;

		// geo_country_map: [slug => "CC,CC,…"]. Slug is the KEY (the form field
		// is geo_country_map[<slug>]); the old code iterated it as
		// [country_code => slug] and so never matched — explicit geo mappings
		// were silently dropped on a language-slug rename.
		if ( ! empty( $settings['geo_country_map'] )
			&& is_array( $settings['geo_country_map'] )
			&& isset( $settings['geo_country_map'][ $old_slug ] )
		) {
			$settings['geo_country_map'][ $new_slug ] = $settings['geo_country_map'][ $old_slug ];
			unset( $settings['geo_country_map'][ $old_slug ] );
			$dirty                                    = true;
		}

		// language_fallbacks: [slug => [fallback_slug, fallback_slug, ...]]
		// Slug appears as both KEY and VALUE inside the nested arrays.
		if ( ! empty( $settings['language_fallbacks'] ) && is_array( $settings['language_fallbacks'] ) ) {
			$rekeyed = [];

			foreach ( $settings['language_fallbacks'] as $key => $fallbacks ) {
				$new_key = $key === $old_slug ? $new_slug : $key;

				if ( is_array( $fallbacks ) ) {
					$fallbacks = array_map(
						static fn( $s ) => $s === $old_slug ? $new_slug : $s,
						$fallbacks
					);
				}

				$rekeyed[ $new_key ] = $fallbacks;

				if ( $key !== $new_key ) {
					$dirty = true;
				}
			}

			if ( $rekeyed !== $settings['language_fallbacks'] ) {
				$settings['language_fallbacks'] = $rekeyed;
				$dirty                          = true;
			}
		}

		// language_domains: [slug => domain]. Slug as KEY only.
		if ( ! empty( $settings['language_domains'] ) && is_array( $settings['language_domains'] ) ) {
			if ( array_key_exists( $old_slug, $settings['language_domains'] ) ) {
				$settings['language_domains'][ $new_slug ] = $settings['language_domains'][ $old_slug ];
				unset( $settings['language_domains'][ $old_slug ] );
				$dirty = true;
			}
		}

		// wc_currencies: [slug => ['currency_code'=>..., 'exchange_rate'=>..., 'manual_rate'=>...]].
		// Slug as KEY. Without this, after a rename the per-language
		// currency override silently stops applying — visitors browsing
		// the renamed language fall back to the WooCommerce default.
		if ( ! empty( $settings['wc_currencies'] ) && is_array( $settings['wc_currencies'] ) ) {
			if ( array_key_exists( $old_slug, $settings['wc_currencies'] ) ) {
				$settings['wc_currencies'][ $new_slug ] = $settings['wc_currencies'][ $old_slug ];
				unset( $settings['wc_currencies'][ $old_slug ] );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			update_option( 'perflocale_settings', $settings );

			// Force the in-memory Settings singleton to reload from DB
			// before any code reads it again in this request. Without
			// this, the rest of the request keeps seeing the pre-rename
			// values for the 3 settings we just rewrote.
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'settings' ) ) {
				$plugin->get( 'settings' )->reset_cache();
			}
		}

		// 4b. WooCommerce auto-fetched exchange rates. Stored as a
		// dedicated option (separate from settings to avoid races
		// with manual rate writes), keyed by slug. Without this,
		// the next cron tick of ExchangeRateSync rewrites the option
		// anyway — but until that happens the renamed language gets
		// no rate, so prices fall back to 1:1 conversion.
		$rates = get_option( 'perflocale_exchange_rates', [] );

		if ( is_array( $rates ) && array_key_exists( $old_slug, $rates ) ) {
			$rates[ $new_slug ] = $rates[ $old_slug ];
			unset( $rates[ $old_slug ] );
			update_option( 'perflocale_exchange_rates', $rates, false );
		}

		// 4. Per-user hidden-langs arrays (Strings + Translations admin
		// column visibility). Stored as `[slug, slug, ...]` in user
		// meta. Walk via direct query so we touch only the rows that
		// contain the old slug; full users-table scan would be wasteful.
		$user_meta_keys = [
			'perflocale_strings_hidden_langs',
			'perflocale_translations_hidden_langs',
		];

		foreach ( $user_meta_keys as $meta_key ) {
			// One-shot per-meta-key scan during slug-rename migration.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta}
					WHERE meta_key = %s AND meta_value LIKE %s",
					$meta_key,
					'%' . $wpdb->esc_like( $old_slug ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$arr = maybe_unserialize( $row->meta_value );

				if ( ! is_array( $arr ) ) {
					continue;
				}

				$mutated = false;
				$next    = [];

				foreach ( $arr as $entry ) {
					if ( $entry === $old_slug ) {
						$next[]  = $new_slug;
						$mutated = true;
					} else {
						$next[] = $entry;
					}
				}

				if ( $mutated ) {
					update_user_meta( (int) $row->user_id, $meta_key, $next );
				}
			}
		}
	}

	/**
	 * Read the persisted slug-redirect map. Frontend redirect handler
	 * consumes this on every request; kept O(1) by storing in a single
	 * option row (not a custom table).
	 *
	 * @return array<string, string> [old_slug => new_slug]
	 */
	public static function get_slug_redirects(): array {
		return (array) get_option( self::REDIRECTS_OPTION, [] );
	}

	/**
	 * Option key for the slug-redirect map. Public-ish constant so
	 * uninstall code can clean it up by name without depending on the
	 * full repository class loading.
	 */
	public const REDIRECTS_OPTION = 'perflocale_slug_redirects';

	/**
	 * Update an existing language.
	 *
	 * @param int $id Language ID.
	 * @param array<string, mixed> $data Data to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$old = $this->find( $id );

		if ( ! $old ) {
			return false;
		}

		$sanitized = $this->sanitize_data( $data );

		// sanitize_data() whitelists `slug`, and the Edit Language form
		// renders the Slug field editable — so a rename can arrive here and
		// not only through rename_slug(). Both sides have to be read before
		// the write, while $old is still the pre-write row.
		$before   = (array) $old;
		$old_slug = isset( $before['slug'] ) && is_string( $before['slug'] ) ? $before['slug'] : '';
		$new_slug = isset( $sanitized['slug'] ) && is_string( $sanitized['slug'] ) ? $sanitized['slug'] : '';

		// Any change to the stored slug invalidates the eager link map, which
		// denormalises language_slug into every link row of this language.
		$slug_differs = isset( $sanitized['slug'] ) && $new_slug !== $old_slug;

		// Only a move BETWEEN two real slugs is a rename, though. The Edit
		// Language form posts an absent Slug field as '' and sanitize_data()
		// keeps the key, so a row can legitimately hold slug='' — and without
		// the $old_slug guard the next ordinary edit would treat that as a
		// rename: it would record a 301 keyed by the empty string, permanently
		// occupying a slot in the autoloaded 50-entry map that SlugRedirector
		// can never fire (its candidate regex demands 2-3 letters), and run
		// migrate_slug_references('', …), whose usermeta needle degenerates to
		// LIKE '%%' and reads every row of both hidden-langs meta keys. The
		// empty-slug cases still fall through to the cache flush below.
		$slug_changed = $slug_differs && $old_slug !== '' && $new_slug !== '';

		// A slug MOVE has to clear the same routing-shape bar rename_slug()
		// enforces, or the Edit form becomes a back door to a language the
		// plugin cannot address: `/wp-json/perflocale/v1/languages/<slug>`
		// and the machine-translate route only match SLUG_PATTERN, and
		// SlugRedirector never fires the 301 recorded below for a prefix
		// outside it. Deliberately narrower than rename_slug()'s guard on
		// two counts, so no site can be locked out of its own Edit screen:
		// the check only fires on a CHANGE (re-posting a legacy slug the
		// form already shows keeps saving), and it leaves the
		// slug-blanked-to-'' direction to the $slug_changed guard above,
		// which has always tolerated it.
		if ( $slug_differs && $new_slug !== '' && ! preg_match( self::SLUG_PATTERN, $new_slug ) ) {
			return false;
		}

		// The slug is not the only column the eager link map denormalises: the
		// display name and the native name ride along on every cached link
		// row, so editing either one leaves the language switcher labelling
		// links from a stale alloptions snapshot.
		$labels_changed = ( isset( $sanitized['name'] ) && $sanitized['name'] !== ( $before['name'] ?? null ) )
			|| ( isset( $sanitized['native_name'] ) && $sanitized['native_name'] !== ( $before['native_name'] ?? null ) );

		// Activating or deactivating a language changes which languages the
		// front end may route to, and update() is the only write path that
		// can do it — the REST PATCH sends `is_active` on its own, with no
		// slug or label alongside, so neither guard above sees it. Every
		// other language-mutating write in this class (rename, delete) drops
		// the eager link map; without this one an activity toggle leaves the
		// map — the single denormalised copy of the languages table that
		// carries NO TTL — as the last writer built it, and only a manual
		// Clear-cache ever rebuilds it.
		$active_changed = isset( $sanitized['is_active'] )
			&& (int) $sanitized['is_active'] !== (int) ( $before['is_active'] ?? 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table(),
			$sanitized,
			[ 'id' => $id ],
			null,
			[ '%d' ]
		);

		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		// Gated on $result so a UNIQUE-index collision on slug/locale — the
		// update returns false and nothing was written — can't record a 301
		// for a rename that never landed.
		if ( $result !== false ) {
			if ( $slug_changed ) {
				$this->apply_slug_rename( $id, $old_slug, $new_slug );
			} elseif ( $labels_changed || $active_changed || $slug_differs ) {
				// $slug_differs without $slug_changed is an empty slug on one
				// side: no 301 to record and nothing to migrate, but the eager
				// map still has the previous value baked into this language's
				// link rows and has to go. A slug filled in from empty is also
				// newly live, so it gets the same redirect-source prune as
				// insert() (a no-op for the blanking direction). An is_active
				// flip reaches the same flush with $new_slug === '' (the REST
				// PATCH carries no slug), so the prune is a no-op there.
				$this->drop_stale_slug_redirect( $new_slug );
				$this->flush_translation_link_caches();
			}
		}

		$language = $this->find( $id );

		/** @hook perflocale/language/updated Fires after a language is updated. */
		do_action( 'perflocale/language/updated', $language, $before );

		return $result !== false;
	}

	/**
	 * Bulk-rewrite the `sort_order` column for the given list of IDs in
	 * positional order. Returns the number of rows actually updated.
	 *
	 * Driven by the drag-handle reorder UI on the Languages list. The
	 * `$offset` parameter lets the caller renumber a paginated slice without
	 * disturbing rows on other pages — page 2 (offset=20) reordering its
	 * 20 rows assigns sort_orders 21..40 instead of clobbering page 1's
	 * range. Validates every supplied ID exists before issuing any write
	 * so a stray request with one bogus ID can't half-apply.
	 *
	 * @param array<int, int> $ordered_ids Language IDs in their new visual order.
	 * @param int             $offset      Starting position (0-based). Sort orders begin at `offset + 1`.
	 * @return int Number of rows updated.
	 */
	public function reorder( array $ordered_ids, int $offset = 0 ): int {
		$ordered_ids = array_values( array_map( 'intval', $ordered_ids ) );

		if ( empty( $ordered_ids ) ) {
			return 0;
		}

		// Validate: every ID must exist. Drop duplicates while we're at it.
		$ordered_ids  = array_values( array_unique( $ordered_ids ) );
		$placeholders = implode( ',', array_fill( 0, count( $ordered_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// $this->table() is class-owned; $placeholders is built by array_fill
		// from validated int IDs.
		$existing = (array) $this->wpdb->get_col(
			$this->wpdb->prepare( "SELECT id FROM %i WHERE id IN ($placeholders)", array_merge( [ $this->table() ], $ordered_ids ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( count( $existing ) !== count( $ordered_ids ) ) {
			return 0;
		}

		$offset = max( 0, $offset );

		// Single UPDATE … CASE id WHEN … END collapses the per-row update
		// loop (one query per language) into a single round-trip.
		$cases = [];
		$args  = [];

		foreach ( $ordered_ids as $position => $id ) {
			$cases[] = 'WHEN %d THEN %d';
			$args[]  = $id;
			$args[]  = $offset + $position + 1;
		}

		$args = array_merge( $args, $ordered_ids );

		// $cases is built from the literal string 'WHEN %d THEN %d' (lines
		// above), $placeholders from array_fill('%d', N), $this->table() is
		// class-owned. All parts are plugin-controlled, never user input;
		// $args carries the int values prepare() substitutes into the
		// placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET sort_order = CASE id ' . implode( ' ', $cases ) . " END WHERE id IN ({$placeholders})",
				array_merge( [ $this->table() ], $args )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// `flush_languages()` group-flushes `perflocale_langs`, which already
		// nukes every per-id `language_<id>` entry. No need to delete them
		// individually first.
		$updated = $result === false ? 0 : count( $ordered_ids );

		$this->cache->flush_languages();

		/** @hook perflocale/languages/reordered Fires after the language list is reordered. */
		do_action( 'perflocale/languages/reordered', $ordered_ids, $offset );

		return $updated;
	}

	/**
	 * Count the rows that delete() would cascade-remove for a language.
	 *
	 * Read-only mirror of the delete() cascade WHEREs — used by the
	 * Languages page delete-preview screen so the operator sees exactly
	 * what one click destroys BEFORE confirming. Each key matches one
	 * cascade step in delete(); keep the two in sync when adding steps.
	 *
	 * @param int $id Language ID.
	 * @return array<string, int> Table-ish label => row count.
	 */
	public function count_cascade( int $id ): array {
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$counts = [
			'translation_links'   => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', $links_table, $id )
			),
			// Groups that would become empty once this language's links go —
			// the same set delete()'s orphan-group GC removes (it sweeps
			// groups left with zero links, excluding 'string' groups).
			'translation_groups'  => (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM %i g
					 WHERE g.type != 'string'
					   AND EXISTS ( SELECT 1 FROM %i l1 WHERE l1.group_id = g.id AND l1.language_id = %d )
					   AND NOT EXISTS ( SELECT 1 FROM %i l2 WHERE l2.group_id = g.id AND l2.language_id != %d )",
					$groups_table,
					$links_table,
					$id,
					$links_table,
					$id
				)
			),
			'string_translations' => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', Schema::table( 'string_translations' ), $id )
			),
			'slug_translations'   => (int) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE language_id = %d', Schema::table( 'slug_translations' ), $id )
			),
		];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $counts;
	}

	/**
	 * Delete a language.
	 *
	 * @param int $id Language ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		$language = $this->find( $id );

		if ( ! $language ) {
			return false;
		}

		// Wrap every FK-cascade DELETE in one transaction so a script
		// timeout / OOM / dropped connection mid-cleanup can't leave the
		// site in the orphan state (languages row gone, but tens of
		// thousands of translation_links / slug_translations rows still
		// referencing its language_id). Same shape as set_default()'s transactional
		// pattern. On any DELETE failure or exception we ROLLBACK and
		// return false — the operator sees the failed action and can
		// retry; nothing is half-applied.
		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );
		$slug_table   = Schema::table( 'slug_translations' );
		$slug_rows    = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $this->wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
			if ( $result === false ) {
				throw new \RuntimeException( 'delete_language: failed to delete languages row' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->delete( $links_table, [ 'language_id' => $id ], [ '%d' ] ) ) {
				throw new \RuntimeException( 'delete_language: failed to delete translation_links' );
			}

			// Garbage-collect now-empty non-string groups (single-language
			// groups, or groups where every sibling-language link was also
			// deleted earlier). One pass, no widow rows.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE g FROM %i g
					LEFT JOIN %i l ON l.group_id = g.id
					WHERE l.id IS NULL AND g.type != 'string'",
					$groups_table,
					$links_table
				)
			) ) {
				throw new \RuntimeException( 'delete_language: failed to garbage-collect orphan translation_groups' );
			}

			// Cascade migration-source-map rows whose group was just GC'd (the
			// group-delete path does this via TGR; this inline GC would otherwise
			// strand source_map rows pointing at deleted group_ids). Best-effort:
			// orphan map rows are already neutralised at read by the importer's
			// dead-group guard, so a failure here must NOT abort the language
			// delete. Self-heals any pre-existing dangling rows too.
			$source_map_table = Schema::table( 'migration_source_map' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query(
				$this->wpdb->prepare(
					'DELETE m FROM %i m
					LEFT JOIN %i g ON g.id = m.group_id
					WHERE g.id IS NULL',
					$source_map_table,
					$groups_table
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// String translations table — uses the same $wpdb connection,
			// so its DELETE participates in our transaction.
			( new StringTranslationRepository( $this->cache ) )->delete_for_language( $id );

			// Capture per-row identifiers BEFORE the slug DELETE so we can
			// targeted-invalidate the L2 persistent cache after commit.
			// get_results returns null on "no rows", false on SQL error - we
			// need to distinguish so a failed SELECT doesn't silently produce
			// a bogus [false] from `(array) false` and crash the post-commit
			// invalidation loop.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$slug_rows_raw = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT object_type, object_id FROM %i WHERE language_id = %d',
					$slug_table,
					$id
				)
			);
			if ( $slug_rows_raw === false ) {
				throw new \RuntimeException( 'delete_language: failed to capture slug rows for cache invalidation' );
			}
			$slug_rows = (array) $slug_rows_raw;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->delete( $slug_table, [ 'language_id' => $id ], [ '%d' ] ) ) {
				throw new \RuntimeException( 'delete_language: failed to delete slug_translations' );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				// COMMIT failed (server already rolled back) — report failure
				// rather than flushing caches and returning success below.
				return false;
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		// Per-row L2 invalidation. The cache key shape mirrors
		// SlugTranslationRepository::get_slug(): "slug_{type}_{id}_{lang}",
		// group 'perflocale_slugs'.
		foreach ( (array) $slug_rows as $slug_row ) {
			$cache_key = sprintf( 'slug_%s_%d_%d', (string) $slug_row->object_type, (int) $slug_row->object_id, $id );
			$this->cache->delete( $cache_key, 'perflocale_slugs' );
		}

		// L1 / L3 / zero-state cleanup. The persistent L2 was just handled
		// per-row above; flush_slugs() still cleans the static memo and
		// the L3 transient envelopes which were never per-row tracked.
		$this->cache->flush_slugs();
		SlugTranslationRepository::reset_static_caches();

		// Translation-link caches. The language's link rows + any now-empty
		// groups were removed inside the transaction, but the autoloaded eager
		// link map (perflocale_eager_links_*) and the per-object translations_*
		// caches still reference the dead links — so get_translations(), the
		// hreflang block and the language switcher would keep advertising a URL
		// for the removed language until an unrelated link write rebuilt the
		// map. Mirror the slug cleanup above for the translation-link layer.
		$this->flush_translation_link_caches();

		// Per-language attachment + nav-menu meta is keyed by the language
		// slug, not its id, so it isn't caught by the FK cascades above and
		// would linger as dead data (and auto-re-link if the slug is re-added).
		// Use delete_metadata( $type, 0, $key, '', true ) — the "delete every
		// row matching $key" core API — not a raw $wpdb->delete(), so the
		// per-object meta cache is busted alongside the row.
		$post_meta_prefixes = [
			'_perflocale_alt_',
			'_perflocale_caption_',
			'_perflocale_description_',
		];

		foreach ( $post_meta_prefixes as $prefix ) {
			delete_metadata( 'post', 0, $prefix . $language->slug, '', true );
		}

		delete_metadata( 'term', 0, '_perflocale_menu_' . $language->slug, '', true );

		// `_perflocale_language` term meta stores the slug as the VALUE (one
		// row per nav-menu term). On delete the rows must go entirely, else
		// menus stay tagged with a slug that no longer resolves. Passing the
		// slug as $meta_value + $delete_all=true removes every matching
		// termmeta row (and busts cache per affected term).
		delete_metadata( 'term', 0, '_perflocale_language', $language->slug, true );

		// Per-user `hidden_langs` arrays (Strings + Translations admin
		// column visibility) may still list the deleted slug. Mirror the
		// rename-time logic in `migrate_slug_references()` but REMOVE the
		// entry instead of replacing it. Targeted SELECT on `meta_value
		// LIKE '%slug%'` keeps the row scan small (only users who have
		// hidden some column) and avoids a full users-table walk.
		$user_meta_keys = [
			'perflocale_strings_hidden_langs',
			'perflocale_translations_hidden_langs',
		];

		foreach ( $user_meta_keys as $meta_key ) {
			// $this->wpdb->usermeta is the WordPress core usermeta table name,
			// supplied by wpdb itself. The $meta_key and language slug are
			// passed through prepare()'s %s placeholders below. Plugin Check
			// can't trace the wpdb-supplied table name through dynamic
			// property access, so we wrap the whole prepare() in a block-
			// scoped disable.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT umeta_id, user_id, meta_value FROM {$this->wpdb->usermeta}
					WHERE meta_key = %s AND meta_value LIKE %s",
					$meta_key,
					'%' . $this->wpdb->esc_like( $language->slug ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$arr = maybe_unserialize( $row->meta_value );

				if ( ! is_array( $arr ) ) {
					continue;
				}

				$next = array_values(
					array_filter(
						$arr,
						static fn( $entry ): bool => $entry !== $language->slug
					)
				);

				if ( $next !== $arr ) {
					update_user_meta( (int) $row->user_id, $meta_key, $next );
				}
			}
		}

		// Strip every settings/option/transient reference that holds the
		// deleted slug as either a key or a value. Without this, the
		// inverse of every migration in `migrate_slug_references()` becomes
		// stale state — the most user-visible offender being
		// `wc_currencies[$slug]` (per-language currency override silently
		// stops applying because the slug no longer resolves) and
		// `language_domains[$slug]` (URL routing reference to nothing).
		$settings = get_option( 'perflocale_settings', [] );

		if ( is_array( $settings ) ) {
			$dirty = false;

			// geo_country_map: [slug => "CC,CC,…"]. Slug is the KEY, so drop the
			// deleted language's ENTRY (the old code filtered by VALUE and never
			// matched, leaking the mapping for the removed language).
			if ( ! empty( $settings['geo_country_map'] )
				&& is_array( $settings['geo_country_map'] )
				&& isset( $settings['geo_country_map'][ $language->slug ] )
			) {
				unset( $settings['geo_country_map'][ $language->slug ] );
				$dirty = true;
			}

			// language_fallbacks: [slug => [fallback_slug, ...]]. Slug appears
			// as both the outer KEY and inside the nested arrays as VALUES.
			if ( ! empty( $settings['language_fallbacks'] ) && is_array( $settings['language_fallbacks'] ) ) {
				if ( array_key_exists( $language->slug, $settings['language_fallbacks'] ) ) {
					unset( $settings['language_fallbacks'][ $language->slug ] );
					$dirty = true;
				}

				foreach ( $settings['language_fallbacks'] as $key => $fallbacks ) {
					if ( ! is_array( $fallbacks ) ) {
						continue;
					}
					$pruned = array_values(
						array_filter(
							$fallbacks,
							static fn( $s ): bool => $s !== $language->slug
						)
					);
					if ( $pruned !== $fallbacks ) {
						$settings['language_fallbacks'][ $key ] = $pruned;
						$dirty                                  = true;
					}
				}
			}

			// language_domains: [slug => domain]. Slug as KEY only.
			if ( ! empty( $settings['language_domains'] ) && is_array( $settings['language_domains'] ) ) {
				if ( array_key_exists( $language->slug, $settings['language_domains'] ) ) {
					unset( $settings['language_domains'][ $language->slug ] );
					$dirty = true;
				}
			}

			// wc_currencies: [slug => {…}]. Slug as KEY.
			if ( ! empty( $settings['wc_currencies'] ) && is_array( $settings['wc_currencies'] ) ) {
				if ( array_key_exists( $language->slug, $settings['wc_currencies'] ) ) {
					unset( $settings['wc_currencies'][ $language->slug ] );
					$dirty = true;
				}
			}

			if ( $dirty ) {
				update_option( 'perflocale_settings', $settings );

				// Force the in-memory Settings singleton to reload from DB
				// before any later code in this request reads it.
				$plugin = \PerfLocale\Plugin::get_instance();
				if ( $plugin->has( 'settings' ) ) {
					$plugin->get( 'settings' )->reset_cache();
				}
			}
		}

		// WC auto-fetched exchange rates live in their own option, keyed by
		// slug. ExchangeRateSync would eventually drop the dead key on its
		// next sync, but the row sits with a stale slug until then.
		$rates = get_option( 'perflocale_exchange_rates', [] );

		if ( is_array( $rates ) && array_key_exists( $language->slug, $rates ) ) {
			unset( $rates[ $language->slug ] );
			update_option( 'perflocale_exchange_rates', $rates, false );
		}

		// Slug 301-redirect map (written by apply_slug_rename(), i.e. both the
		// rename_slug() path and an Edit-form slug change through update()):
		// drop every entry
		// whose key OR value is the deleted slug. An entry pointing AT the slug
		// would 301 old URLs into a prefix that no longer routes (a permanent
		// 301→404, worse for SEO than a plain 404), and a stale key adds dead
		// autoload weight. There is no successor language to remap onto, so the
		// entries are dropped — old URLs then fall through to normal routing.
		$redirects = (array) get_option( self::REDIRECTS_OPTION, [] );

		if ( $redirects !== [] ) {
			$pruned = [];

			foreach ( $redirects as $from => $to ) {
				if ( (string) $from === $language->slug || (string) $to === $language->slug ) {
					continue;
				}
				$pruned[ $from ] = $to;
			}

			if ( $pruned !== $redirects ) {
				if ( $pruned === [] ) {
					delete_option( self::REDIRECTS_OPTION );
				} else {
					// Autoload true — matches rename_slug()'s write; SlugRedirector
					// reads this on every front-end request.
					update_option( self::REDIRECTS_OPTION, $pruned, true );
				}
			}
		}

		// Generated .l10n.php translation files for this language's locale
		// stay on disk indefinitely otherwise. Filename pattern is
		// `<domain>-<locale>.l10n.php` (matches what TranslationFileGenerator
		// writes), so glob by locale strips every domain in one pass.
		$locale = isset( $language->locale ) ? (string) $language->locale : '';

		if ( $locale !== '' ) {
			$upload_dir = wp_upload_dir();
			$base       = $upload_dir['basedir'] . '/perflocale/translations';
			$pattern    = $base . '/*-' . sanitize_file_name( $locale ) . '.l10n.php';
			$files      = glob( $pattern );

			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					wp_delete_file( $file );
				}
			}
		}

		$this->cache->flush_languages();
		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		/** @hook perflocale/language/deleted Fires after a language is deleted. */
		do_action( 'perflocale/language/deleted', $id, $language->slug );

		return true;
	}

	/**
	 * Set a language as the default (unsets the current default).
	 *
	 * @param int $id Language ID to make default.
	 * @return bool
	 * @throws \RuntimeException When the unset-default or set-default UPDATE fails inside the transaction.
	 */
	public function set_default( int $id ): bool {
		// Reject a nonexistent OR inactive target BEFORE mutating. $wpdb->update()
		// returns 0 (not false) when the WHERE matches no row, so the "set new
		// default" UPDATE wouldn't throw — it would just unset the old default and
		// set nothing, leaving the site with ZERO default languages. An inactive
		// target is just as bad: get_default() reads the active-only bootstrap, so
		// a default that isn't active resolves to NULL (breaking filter_locale /
		// is_default_language / the switcher). All callers promote active langs.
		$target = $this->find( $id );

		if ( $target === null || empty( $target->is_active ) ) {
			return false;
		}

		$old_default    = $this->get_default();
		$old_default_id = $old_default ? (int) $old_default->id : 0;

		// Wrap both UPDATEs in a transaction so a DB hiccup between them
		// can't leave the site with zero default languages (which would
		// break filter_locale, is_default_language, and the language
		// switcher until manually fixed).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Unset current default.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$unset_ok = $this->wpdb->update(
				$this->table(),
				[ 'is_default' => 0 ],
				[ 'is_default' => 1 ],
				[ '%d' ],
				[ '%d' ]
			);

			if ( $unset_ok === false ) {
				throw new \RuntimeException( 'set_default: failed to unset current default' );
			}

			// Set new default.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$set_ok = $this->wpdb->update(
				$this->table(),
				[ 'is_default' => 1 ],
				[ 'id' => $id ],
				[ '%d' ],
				[ '%d' ]
			);

			// Reject false (DB error) AND 0 rows affected: if the target row was
			// deleted between the guard above and this UPDATE (a concurrent
			// delete), `is_default=1 WHERE id=$id` matches nothing, the old
			// default was already unset, and committing would leave ZERO
			// defaults. A legitimate promotion always changes exactly one row.
			if ( $set_ok === false || (int) $set_ok < 1 ) {
				throw new \RuntimeException( 'set_default: failed to set new default' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				// COMMIT failed (server already rolled back) — report failure
				// rather than flushing caches and returning success below.
				return false;
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->query( 'ROLLBACK' );
			return false;
		}

		$this->cache->flush_languages();

		// Flush individual language caches so the is_default flag is refreshed.
		if ( $old_default_id > 0 ) {
			$this->cache->delete( "language_{$old_default_id}", 'perflocale_langs' );
		}

		$this->cache->delete( "language_{$id}", 'perflocale_langs' );

		$new_default = $this->find( $id );

		/** @hook perflocale/default_language/changed Fires when the default language changes. */
		do_action( 'perflocale/default_language/changed', $new_default, $old_default );

		return true;
	}

	/**
	 * Sanitize language data for insert/update.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed> Sanitized data.
	 */
	private function sanitize_data( array $data ): array {
		$sanitized = [];

		$string_fields = [ 'slug', 'locale', 'name', 'native_name', 'flag', 'date_format', 'time_format', 'text_direction' ];
		$int_fields    = [ 'is_default', 'is_active', 'sort_order' ];

		foreach ( $string_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}

		foreach ( $int_fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$sanitized[ $field ] = absint( $data[ $field ] );
			}
		}

		// Ensure slug is lowercase and enforce the schema column length
		// (VARCHAR(10)) so callers see an explicit value rather than a
		// silent MySQL truncation. Long unicode slugs are trimmed, not
		// rejected - mirrors the existing forgiving sanitisation style.
		if ( isset( $sanitized['slug'] ) ) {
			$sanitized['slug'] = mb_substr( sanitize_key( $sanitized['slug'] ), 0, 10 );
		}

		if ( isset( $sanitized['locale'] ) ) {
			$sanitized['locale'] = mb_substr( $sanitized['locale'], 0, 20 );
		}

		if ( isset( $sanitized['flag'] ) ) {
			$sanitized['flag'] = mb_substr( $sanitized['flag'], 0, 10 );
		}

		// Validate text direction.
		if ( isset( $sanitized['text_direction'] ) && ! in_array( $sanitized['text_direction'], [ 'ltr', 'rtl' ], true ) ) {
			$sanitized['text_direction'] = 'ltr';
		}

		return $sanitized;
	}
}
