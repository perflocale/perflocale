<?php
/**
 * Translation group repository.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Contract\RepositoryInterface;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer for translation groups and links.
 *
 * A translation group connects all translations of the same content
 * across languages. Each group has one link per language.
 */
final class TranslationGroupRepository implements RepositoryInterface {

	/**
	 * Per-request cache for find_for_object() lookups.
	 *
	 * Shared across all instances (static). Entries are cleared by
	 * invalidate_find_cache() when groups/links change. Capped at
	 * FIND_CACHE_CAP entries — long-running CLI processes (bulk MT,
	 * sitemap rebuild, XLIFF import) walk every post and would otherwise
	 * grow this array to N×~150 bytes per entry without bound.
	 *
	 * @var array<string, object|null>
	 */
	private static array $find_cache = [];

	/**
	 * Soft cap on $find_cache. When exceeded, the oldest 25% of entries
	 * are evicted FIFO. Same heuristic as UrlConverter::cap_cache().
	 */
	private const FIND_CACHE_CAP = 5000;

	/**
	 * Per-request memo of `has_any_groups()`. Stays null until the first
	 * call resolves it; thereafter all prime_translations calls in the
	 * same request use this without re-querying L1/L2/DB.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_any_groups_memo = null;

	/**
	 * Per-request memo of the eager link map, keyed by `{blog_id}:{type}`.
	 *
	 * Each entry is either:
	 *   - array<int, list<object>>  — object_id → group's full link list
	 *   - 'too_large'               — sentinel: this blog is over the size
	 *                                 cap, fall back to per-post caching
	 *
	 * Multisite must blog-prefix the key (same pattern as $find_cache via
	 * find_cache_key()) - otherwise switch_to_blog() carries the previous
	 * blog's 'too_large' sentinel or link map into the new blog, causing
	 * the new blog to either skip the eager fast path (N+1 fallback) or
	 * read sibling links from the wrong blog's tables.
	 *
	 * @var array<string, array|string>
	 */
	private static array $eager_link_map_memo = [];

	/**
	 * Per-request memo of `is_string_group()`, keyed by `{blog_id}:{group_id}`.
	 *
	 * Blog-prefixed for the same reason as $find_cache and
	 * $eager_link_map_memo: group ids are per-blog, so on multisite the same
	 * id names a different group on every blog and an id-only memo hands
	 * blog B the answer computed for blog A — a string write would then be
	 * let loose on (or refused for) the wrong group.
	 *
	 * Cleared by reset_static_caches() as well, and the blog key is not what
	 * makes that necessary — a replace-mode import is. DataImporter wipes
	 * `translation_groups` and re-inserts the export's rows under their
	 * ORIGINAL ids, and the table is polymorphic (post, term and string groups
	 * share one id space), so an id this process already answered for can come
	 * back carrying a different type. Every import path closes with
	 * MigrationCacheHelper::flush_post_migration_caches(), which routes here —
	 * without this line an Action Scheduler worker that ran a string job
	 * before the import would keep answering from the pre-import types for the
	 * rest of its process lifetime.
	 *
	 * The other route in is Bootstrap's ungated `switch_blog` hook, where the
	 * clear is redundant (the blog key already discriminates) and costs one
	 * query per distinct group id after each of the same-blog
	 * switch_to_blog( get_current_blog_id() ) calls WooCommerce / AIOWPSecurity
	 * make. That is the identical, deliberate trade Bootstrap already makes for
	 * $find_cache, $eager_link_map_memo and every other static cache it wires
	 * to that hook; splitting this one memo out of the shared reset to dodge it
	 * would cost the import guarantee above.
	 *
	 * @var array<string, bool>
	 */
	private static array $is_string_group_memo = [];

	/**
	 * When true, get_eager_link_map() returns null (JOIN fallback) and no
	 * rebuild-and-persist happens. Set by bulk writers around their loop so
	 * per-row invalidations don't trigger a full map rebuild per row.
	 *
	 * @var bool
	 */
	private static bool $eager_link_map_suspended = false;

	/**
	 * Suspend eager-link-map reads/rebuilds for the duration of a bulk
	 * write. Pair with {@see resume_eager_link_map()} in a finally block.
	 *
	 * @return void
	 */
	public static function suspend_eager_link_map(): void {
		self::$eager_link_map_suspended = true;
	}

	/**
	 * Resume eager-link-map service after a bulk write. The caller should
	 * invalidate once afterwards so the next read rebuilds a fresh map.
	 *
	 * @return void
	 */
	public static function resume_eager_link_map(): void {
		self::$eager_link_map_suspended = false;
	}

	/**
	 * Maximum link-row count to materialise into the autoloaded eager map.
	 *
	 * 2000 covers the vast majority of real multilingual installs without
	 * making alloptions a megabyte (typical row serialises to ~250 bytes
	 * → 500 KB at the cap, well under the WP "alloptions is getting too
	 * big" threshold). The byte cap below is the final defensive gate
	 * for unusually wide rows.
	 *
	 * Filterable via `perflocale/cache/eager_map_row_cap`. Sites with a
	 * persistent object cache (Redis/Memcached) get the same fast path
	 * regardless of size via prime_translations Step 1's L2 promotion,
	 * so the cap is really only about no-Redis installs.
	 */
	private const EAGER_LINK_MAP_ROW_CAP = 2000;

	/**
	 * Serialised size cap for the eager link map (bytes). 750 KB lets the
	 * map grow to a comfortable size when rows carry many language
	 * fields, without single-handedly bloating alloptions. Filterable
	 * via `perflocale/cache/eager_map_byte_cap`.
	 */
	private const EAGER_LINK_MAP_BYTE_CAP = 750 * 1024;

	/**
	 * Sentinel stored (and memoised) when a SUCCESSFUL count proved this blog
	 * has zero links of the type.
	 *
	 * Distinct from a stored `[]` on purpose. A `[]` is what a FAILED cold
	 * build used to persist — `wpdb::get_var()` answers NULL on error and
	 * `(int) null === 0`, which took the "no rows" branch — so the two states
	 * are indistinguishable in the pre-1.0.1 shape, and the memo handed the
	 * empty array to every consumer after the first as an authoritative
	 * "this object has no translations". With this sentinel, `[]` means only
	 * "legacy or unproven" and is rebuilt once; the sentinel means "proven
	 * empty" and is cached like any other answer.
	 */
	private const EAGER_MAP_EMPTY = 'empty';

	/**
	 * Tracks whether a transaction is already active.
	 *
	 * Uses a static flag instead of querying @@in_transaction which is
	 * not available on all MySQL/MariaDB versions.
	 *
	 * @var bool
	 */
	private static bool $in_transaction = false;

	/**
	 * Register (or clear) an externally-owned transaction.
	 *
	 * Lets a caller that opened its OWN raw START TRANSACTION (e.g. the
	 * TranslatePress migration importer, which wraps a whole batch) tell
	 * create_group()/link_object() that a transaction is already active so they
	 * nest inside it rather than issuing a second START (which MySQL treats as
	 * an implicit COMMIT of the outer). The owner MUST reset this to false on
	 * every exit path (commit, rollback, throw) — ideally via try/finally —
	 * otherwise a leaked `true` would silently run later statements outside any
	 * transaction.
	 *
	 * @param bool $active Whether an external transaction is now active.
	 * @return void
	 */
	public function set_in_transaction( bool $active ): void {
		self::$in_transaction = $active;
	}

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
	 * Resolve the groups table name for the current blog.
	 *
	 * Computed on every call so switch_to_blog() is honoured — capturing
	 * the name in the constructor would otherwise pin this instance to
	 * the blog that was active at construction.
	 *
	 * @return string
	 */
	private function groups_table(): string {
		return Schema::table( 'translation_groups' );
	}

	/**
	 * Resolve the links table name for the current blog.
	 *
	 * See groups_table() for rationale.
	 *
	 * @return string
	 */
	private function links_table(): string {
		return Schema::table( 'translation_links' );
	}

	/**
	 * Seed every sibling in this link list into L1 under their own cache key.
	 *
	 * Every member of a translation group sees the same `$value` (the full
	 * link list). Pre-populating L1 for every sibling means downstream
	 * get_translations(sibling_id) calls — hreflang, language switcher,
	 * cross-language permalinks — hit L1 immediately instead of round-
	 * tripping to L2 or worse.
	 *
	 * @param mixed                       $value    Link list (array of objects) or anything else (skipped).
	 * @param \PerfLocale\Enum\ObjectType $type
	 * @param int                         $self_id  The source object_id whose key was just written; siblings only.
	 * @return void
	 */
	private function cascade_siblings_to_l1( $value, \PerfLocale\Enum\ObjectType $type, int $self_id ): void {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $link ) {
			$sibling_id = (int) ( $link->object_id ?? 0 );

			if ( $sibling_id > 0 && $sibling_id !== $self_id ) {
				$sibling_key = "translations_{$type->value}_{$sibling_id}";

				if ( $this->cache->get_static( $sibling_key, 'perflocale_trans' ) === null ) {
					$this->cache->set_static( $sibling_key, $value, 'perflocale_trans' );
				}
			}
		}
	}

	/**
	 * Compose a blog-prefixed cache key for the static $find_cache.
	 *
	 * The $find_cache static is shared across every blog in a multisite
	 * install, so lookups must be segregated by blog_id to prevent one
	 * blog's cached group from shadowing another's.
	 *
	 * @param string $type Object type value.
	 * @param int    $object_id Object ID.
	 * @return string
	 */
	private static function find_cache_key( string $type, int $object_id ): string {
		return get_current_blog_id() . ":group_{$type}_{$object_id}";
	}

	/**
	 * Compose a blog-prefixed key for the static $eager_link_map_memo.
	 *
	 * Mirrors find_cache_key(): same shared-static-across-blogs reason,
	 * same blog_id prefix guarantee. Without this, a switch_to_blog()
	 * call within a single PHP request can cause blog A's 'too_large'
	 * sentinel or link map to be returned for blog B.
	 *
	 * @param string $type Object type value.
	 * @return string
	 */
	private static function eager_memo_key( string $type ): string {
		return get_current_blog_id() . ':' . $type;
	}

	/**
	 * Clear the request-scoped static caches.
	 *
	 * Hooked to the `switch_blog` action by Bootstrap so that switching
	 * between sites in a multisite install does not serve stale entries
	 * keyed to the previous blog. Also called directly wherever group rows
	 * change underneath a still-running process: after a language slug
	 * rename or delete (LanguageRepository), and after a data import
	 * (MigrationCacheHelper). Only the import is what makes clearing
	 * $is_string_group_memo more than belt-and-braces — a rename touches no
	 * group row, but a replace-mode import can put a different TYPE behind an
	 * id this process already answered for. See that property's docblock.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$find_cache           = [];
		self::$has_any_groups_memo  = null;
		self::$eager_link_map_memo  = [];
		self::$is_string_group_memo = [];
	}

	/**
	 * Find a group by ID.
	 *
	 * @param int $id Group ID.
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->groups_table(),
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Find an object's OWN link row (group membership plus language).
	 *
	 * {@see find_for_object()} answers "which group is this in?" — it selects
	 * `g.*`, so it structurally cannot say which LANGUAGE the object is filed
	 * under. Callers that need to decide whether an object is already filed
	 * correctly (the WPML/Polylang importers, which must re-language objects
	 * WordPress auto-assigned to the default language) need the link row.
	 *
	 * Reads through the cached {@see get_translations()} set, so this costs
	 * nothing extra when the caller also inspects the siblings.
	 *
	 * @param int        $object_id Object ID (post ID, term ID, etc.).
	 * @param ObjectType $type Object type.
	 * @return object|null Link record (group_id, language_id, status, …) or null.
	 */
	public function find_link_for_object( int $object_id, ObjectType $type ): ?object {
		foreach ( $this->get_translations( $object_id, $type ) as $link ) {
			if ( (int) ( $link->object_id ?? 0 ) === $object_id ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Find the translation group for a given object.
	 *
	 * @param int        $object_id Object ID (post ID, term ID, etc.).
	 * @param ObjectType $type Object type.
	 * @return object|null Group record or null.
	 */
	public function find_for_object( int $object_id, ObjectType $type ): ?object {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Per-request cache - avoids transient serialization issues where
		// null gets corrupted to '' and breaks ?object return type.
		// Key is blog-prefixed so multisite switch_to_blog() cannot serve
		// a group row from the wrong site's tables.
		$cache_key = self::find_cache_key( $type->value, $object_id );

		if ( array_key_exists( $cache_key, self::$find_cache ) ) {
			return self::$find_cache[ $cache_key ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT g.* FROM %i g
				INNER JOIN %i l ON g.id = l.group_id
				WHERE l.object_id = %d AND g.type = %s
				LIMIT 1',
				$this->groups_table(),
				$this->links_table(),
				$object_id,
				$type->value
			)
		);

		// Evict the oldest 25% of entries when the cap is reached. PHP
		// preserves insertion order, so array_slice from the front gives
		// us FIFO. The next iteration's add proceeds normally.
		if ( count( self::$find_cache ) >= self::FIND_CACHE_CAP ) {
			$evict            = (int) ( self::FIND_CACHE_CAP / 4 );
			self::$find_cache = array_slice( self::$find_cache, $evict, null, true );
		}

		self::$find_cache[ $cache_key ] = $result;

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $result;
	}

	/**
	 * Get all translation links for an object (all its language versions).
	 *
	 * @param int $object_id Object ID.
	 * @param ObjectType $type Object type.
	 * @return array<int, object> Array of link records with language data.
	 */
	/**
	 * Batch-prime translation caches for a list of object IDs.
	 *
	 * On an archive page showing N posts, each call to `get_translations()` is
	 * 2 transient reads (value + timeout) when there's no persistent object
	 * cache - that's 2N DB hits over the loop. Priming up front collapses it
	 * to ONE SQL query no matter how many posts are displayed, and warms each
	 * per-object cache entry so downstream `get_translations()` / hreflang /
	 * language-switcher / fallback-walker calls are free.
	 *
	 * Objects with no translation group get an empty array cached - so
	 * untranslated posts don't re-query on the next call either.
	 *
	 * Idempotent: IDs already in the L1 static cache are skipped.
	 *
	 * @param ObjectType      $type Object type (post or term).
	 * @param array<int, int> $object_ids Object IDs to prime.
	 * @return void
	 */
	public function prime_translations( ObjectType $type, array $object_ids ): void {
		$object_ids = array_values( array_unique( array_map( 'intval', $object_ids ) ) );
		$object_ids = array_values( array_filter( $object_ids, static fn( int $id ): bool => $id > 0 ) );

		if ( $object_ids === [] ) {
			return;
		}

		// Zero-state short-circuit: on a site with no translation groups, seed
		// L1 with empty results for every input ID (what the JOIN path's Step 4
		// would do anyway) so downstream get_translations() hits L1 instead of
		// the JOIN + transient writes. has_any_groups() caches its TRUE answer
		// (L1 + L2), so real multilingual sites fall straight through below.
		if ( ! $this->has_any_groups() ) {
			foreach ( $object_ids as $id ) {
				$key = "translations_{$type->value}_{$id}";

				if ( $this->cache->get_static( $key, 'perflocale_trans' ) === null ) {
					$this->cache->set_static( $key, [], 'perflocale_trans' );
				}
			}

			return;
		}

		// Step 0 — eager map fast-path. On small/medium sites (under the
		// row + byte caps) we keep every translation_link in a single
		// autoloaded option that costs ~1 µs to read (alloptions cache
		// hit). When present, the whole prime path collapses to "copy
		// what's needed into L1" — no SELECT-IN, no JOIN, no transient
		// peek. Bigger sites get a null return here and fall through to
		// Steps 1-4 unchanged.
		$eager_map = $this->get_eager_link_map( $type );

		if ( is_array( $eager_map ) ) {
			foreach ( $object_ids as $id ) {
				$key = "translations_{$type->value}_{$id}";

				if ( $this->cache->get_static( $key, 'perflocale_trans' ) !== null ) {
					continue;
				}

				$links = $eager_map[ $id ] ?? [];
				$this->cache->set_static( $key, $links, 'perflocale_trans' );

				// Sibling cascade — every member of this object's group
				// shares the same link list, so seed L1 for each.
				foreach ( $links as $link ) {
					$sibling_id = (int) ( $link->object_id ?? 0 );

					if ( $sibling_id > 0 && $sibling_id !== $id ) {
						$sibling_key = "translations_{$type->value}_{$sibling_id}";

						if ( $this->cache->get_static( $sibling_key, 'perflocale_trans' ) === null ) {
							$this->cache->set_static( $sibling_key, $links, 'perflocale_trans' );
						}
					}
				}
			}

			return;
		}

		// Step 1 — drop anything already warm in L1 or L2. Pulling an L2 hit
		// (object cache) forward into L1 collapses the rest of the function to
		// a no-op for warm-cache requests; without it, Redis/Memcached sites
		// would still pay the L3 peek or the Step 3 JOIN. L2 reads go through
		// wp_cache_get_multiple() so capable backends pipeline them into one
		// MGET instead of N sequential GETs.
		$after_l1  = [];
		$check_l2  = $this->cache->l2_enabled();
		$l2_needed = []; // map cache_key => $id
		$has_multi = $check_l2 && function_exists( 'wp_cache_get_multiple' );

		foreach ( $object_ids as $id ) {
			$key = "translations_{$type->value}_{$id}";

			if ( $this->cache->get_static( $key, 'perflocale_trans' ) !== null ) {
				continue;
			}

			if ( $has_multi ) {
				$l2_needed[ $key ] = $id;
				continue;
			}

			if ( $check_l2 ) {
				$found = false;
				// Must use CacheManager's generation-prefixed key — the L2
				// entries this fast-path skips on were written by
				// CacheManager::get(); a bare $key would miss them all.
				$value = wp_cache_get( CacheManager::l2_key( $key, 'perflocale_trans' ), 'perflocale_trans', false, $found );

				if ( $found ) {
					$this->cache->set_static( $key, $value, 'perflocale_trans' );
					$this->cascade_siblings_to_l1( $value, $type, $id );
					continue;
				}
			}

			$after_l1[] = $id;
		}

		// Pipelined L2 fetch — one MGET for every cold L1 key. Keys must be
		// generation-prefixed to match CacheManager::get()'s L2 writes, so
		// fetch by versioned key and map the result back to the logical key.
		if ( $l2_needed !== [] ) {
			$versioned_keys = [];
			foreach ( array_keys( $l2_needed ) as $logical_key ) {
				$versioned_keys[ $logical_key ] = CacheManager::l2_key( $logical_key, 'perflocale_trans' );
			}

			$batch = wp_cache_get_multiple( array_values( $versioned_keys ), 'perflocale_trans' );

			foreach ( $l2_needed as $key => $id ) {
				$value = $batch[ $versioned_keys[ $key ] ] ?? false;

				if ( $value !== false ) {
					$this->cache->set_static( $key, $value, 'perflocale_trans' );
					$this->cascade_siblings_to_l1( $value, $type, $id );
				} else {
					$after_l1[] = $id;
				}
			}
		}

		if ( $after_l1 === [] ) {
			return;
		}

		// Step 2 — batched read of L3 transients for the remaining IDs. One
		// SELECT-IN + skipping writes for already-populated transients avoids
		// set_transient()'s 3-queries-per-ID rewrite on warm requests. Under an
		// external object cache transients aren't in wp_options, so the peek
		// would return zero rows — skip it and let Step 3 + the object-cache-
		// aware get_translations() fill path handle priming.
		if ( wp_using_ext_object_cache() ) {
			$missing = $after_l1;
		} else {
			$transient_option_keys = [];

			foreach ( $after_l1 as $id ) {
				$cache_key    = "translations_{$type->value}_{$id}";
				$transient_id = $this->transient_option_name( $cache_key );

				$transient_option_keys[] = '_transient_' . $transient_id;
				$transient_option_keys[] = '_transient_timeout_' . $transient_id;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$placeholders = implode( ',', array_fill( 0, count( $transient_option_keys ), '%s' ) );

			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT option_name, option_value FROM {$this->wpdb->options} WHERE option_name IN ({$placeholders})",
					...$transient_option_keys
				),
				OBJECT
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$existing_values  = [];
			$existing_expires = [];

			if ( is_array( $rows ) ) {
				foreach ( $rows as $r ) {
					$name = (string) $r->option_name;

					if ( str_starts_with( $name, '_transient_timeout_' ) ) {
						$transient_id                      = substr( $name, strlen( '_transient_timeout_' ) );
						$existing_expires[ $transient_id ] = (int) $r->option_value;
					} elseif ( str_starts_with( $name, '_transient_' ) ) {
						$transient_id                     = substr( $name, strlen( '_transient_' ) );
						$existing_values[ $transient_id ] = maybe_unserialize( (string) $r->option_value );
					}
				}
			}

			$now     = time();
			$missing = [];

			foreach ( $after_l1 as $id ) {
				$cache_key    = "translations_{$type->value}_{$id}";
				$transient_id = $this->transient_option_name( $cache_key );

				$has_value = array_key_exists( $transient_id, $existing_values );
				// No timeout row = non-expiring (WP semantics — and exactly
				// what CacheManager::set_many() writes: it skips the timeout
				// row by design and relies on CacheInvalidator for staleness).
				// Only an existing timeout in the past means expired. Requiring
				// a timeout here treated every set_many()-persisted value as
				// expired, re-running the Step-3 JOIN + write-back on every
				// request on large (post-eager-map) sites without Redis.
				$not_expired = ! isset( $existing_expires[ $transient_id ] ) || $existing_expires[ $transient_id ] >= $now;

				if ( $has_value && $not_expired ) {
					// Transient is fresh - populate the L1 cache only, no writes.
					$envelope = $existing_values[ $transient_id ];
					$value    = is_array( $envelope ) && array_key_exists( 'v', $envelope ) ? $envelope['v'] : $envelope;

					$this->cache->set_static( $cache_key, $value, 'perflocale_trans' );

					// Cascade: every sibling in the link list shares this same
					// group, so its translations list is identical. Populating
					// L1 for them means downstream get_translations(sibling_id)
					// calls (hreflang, switcher, cross-language permalinks) hit
					// L1 without a separate transient round-trip each.
					if ( is_array( $value ) ) {
						foreach ( $value as $link ) {
							$sibling_id = (int) ( $link->object_id ?? 0 );

							if ( $sibling_id > 0 && $sibling_id !== $id ) {
								$sibling_key = "translations_{$type->value}_{$sibling_id}";

								if ( $this->cache->get_static( $sibling_key, 'perflocale_trans' ) === null ) {
									$this->cache->set_static( $sibling_key, $value, 'perflocale_trans' );
								}
							}
						}
					}

					continue;
				}

				$missing[] = $id;
			}
		}

		if ( $missing === [] ) {
			return;
		}

		// Step 3 - one JOIN SQL for IDs that still need to be resolved.
		$lang_table = Schema::table( 'languages' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		// Args follow the placeholder order of the SELECT below exactly:
		// links %i, groups %i, %s type, links %i (second join), lang %i, then the %d list.
		$args = array_merge(
			[ $this->links_table(), $this->groups_table(), $type->value, $this->links_table(), $lang_table ],
			$missing
		);

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT src.object_id AS source_id,
				 l.*,
				 lang.slug AS language_slug,
				 lang.name AS language_name,
				 lang.native_name AS language_native_name
				 FROM %i src
				 INNER JOIN %i g ON src.group_id = g.id AND g.type = %s
				 INNER JOIN %i l ON l.group_id = src.group_id
				 INNER JOIN %i lang ON l.language_id = lang.id
				 WHERE src.object_id IN ({$placeholders})",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Group the flat link rows by source_id: every member of a group sees
		// the same sibling list, so cache that list under EVERY member's key in
		// one pass, not just the requested IDs. Then when HreflangTags or the
		// LanguageSwitcher later call get_translations() for a sibling (to
		// render the current page's alternates), it's a free L1 hit instead of
		// another transient/DB round-trip.
		$grouped_by_source = array_fill_keys( $missing, [] );
		$all_object_ids    = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$sid = (int) ( $row->source_id ?? 0 );

				if ( $sid === 0 ) {
					continue;
				}

				// Strip the source_id column - it's the query-side artefact
				// and isn't expected by downstream consumers.
				$clean = clone $row;
				unset( $clean->source_id );

				$grouped_by_source[ $sid ][] = $clean;

				if ( isset( $clean->object_id ) ) {
					$all_object_ids[ (int) $clean->object_id ] = true;
				}
			}
		}

		// Build the full write set first (own entries + sibling cascade) so a
		// single batched cache->set_many() call collapses what used to be
		// N × 2 update_option round-trips into multi-row INSERTs — one on the
		// page-render path this method actually serves, and a bounded handful
		// on a bulk prime, where set_many() splits the batch at its
		// per-statement row/byte ceiling (500 rows / 256 KB). An entry here is
		// one link row per language (~1.7 KB across four languages), so it is
		// the byte ceiling that trips first — set_many() lets a single
		// over-budget row through on its own, and nothing this method writes
		// gets near that — leaving every statement well inside the 1 MB
		// max_allowed_packet floor the budget is sized against.
		$to_write = [];

		foreach ( $grouped_by_source as $sid => $links ) {
			$to_write[ "translations_{$type->value}_{$sid}" ] = $links;
		}

		// Cascade - for each sibling object_id in the fetched link rows that
		// WASN'T in the original $missing list, cache the same group's link
		// list under its key. Pick the first non-empty source list as the
		// canonical view of the group.
		foreach ( $all_object_ids as $sibling_id => $_ ) {
			if ( isset( $grouped_by_source[ $sibling_id ] ) ) {
				continue;
			}

			// Find the list for whichever missing ID shares this sibling's
			// group. Since all siblings are in the same group, any non-
			// empty source list works.
			foreach ( $grouped_by_source as $source_links ) {
				if ( $source_links === [] ) {
					continue;
				}

				$shares_group = false;

				foreach ( $source_links as $link ) {
					if ( (int) ( $link->object_id ?? 0 ) === $sibling_id ) {
						$shares_group = true;
						break;
					}
				}

				if ( $shares_group ) {
					$to_write[ "translations_{$type->value}_{$sibling_id}" ] = $source_links;
					break;
				}
			}
		}

		if ( $to_write !== [] ) {
			$this->cache->set_many( $to_write, HOUR_IN_SECONDS, 'perflocale_trans' );
		}

		// We just resolved at least one group, so prove the site is no
		// longer in the zero-state. Pin TRUE everywhere so the next
		// `has_any_groups()` call short-circuits without any DB hit.
		self::$has_any_groups_memo = true;
		$this->cache->set_static( 'has_any_groups', 1, 'perflocale_trans' );

		if ( $this->cache->l2_enabled() ) {
			wp_cache_set( 'has_any_groups', 1, 'perflocale_trans', 0 );
		}

		// Cross-request persistence via autoloaded option. Idempotent;
		// no-op if already '1' on autoload.
		if ( get_option( 'perflocale_has_any_groups', '' ) !== '1' ) {
			update_option( 'perflocale_has_any_groups', '1', true );
		}
	}

	/**
	 * Does any translation group exist on this blog?
	 *
	 * Lets {@see prime_translations()} (and other callers that would
	 * otherwise hit the same translation_links / translation_groups
	 * tables) skip their cold-path DB query entirely on fresh installs
	 * and single-language sites. The hot path is sub-microsecond on
	 * warm-cache requests; the cold path is a SELECT 1 LIMIT 1 (~50 µs)
	 * and runs at most once per request.
	 *
	 * Only TRUE is cached. FALSE is checked again on the next request,
	 * so a newly-created group becomes visible immediately — no explicit
	 * cache invalidation hook needed.
	 *
	 * @return bool
	 */
	/**
	 * Whether a group id exists AND is a string-type group.
	 *
	 * `strings.group_id` is an unenforced foreign key. A dangling id that
	 * happens to collide with a live post/term group would make a string
	 * write repoint or delete THAT group's link, so every string-link writer
	 * checks the type first. Memoised per request (blog-keyed, and cleared
	 * by reset_static_caches()): the callers loop over thousands of strings
	 * that share a handful of groups.
	 *
	 * @param int $group_id Group id to test.
	 * @return bool True when the group exists and its type is 'string'.
	 */
	public function is_string_group( int $group_id ): bool {
		if ( $group_id <= 0 ) {
			return false;
		}

		// Blog-prefixed key, same shape as find_cache_key()/eager_memo_key():
		// a bare $group_id would serve another blog's group type after a
		// switch_to_blog(). Lives on a class static rather than a
		// function-local one so reset_static_caches() can reach it — see the
		// property docblock for the replace-mode import that makes that
		// reachability load-bearing rather than cosmetic.
		$memo_key = get_current_blog_id() . ':' . $group_id;

		if ( isset( self::$is_string_group_memo[ $memo_key ] ) ) {
			return self::$is_string_group_memo[ $memo_key ];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Memoised above; a type read has no repository cache of its own. The identifier is bound with %i; WPCS cannot follow prepare() called on a property.
		$type = (string) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT type FROM %i WHERE id = %d',
				$this->groups_table(),
				$group_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		self::$is_string_group_memo[ $memo_key ] = ( 'string' === $type );

		return self::$is_string_group_memo[ $memo_key ];
	}

	public function has_any_groups(): bool {
		if ( self::$has_any_groups_memo !== null ) {
			return self::$has_any_groups_memo;
		}

		// L0 — autoloaded option. On no-Redis sites this is the only place
		// the TRUE answer survives across requests without paying a DB
		// roundtrip, since L2 (wp_cache) is per-request without persistence.
		// The option is loaded with alloptions on every request, so reads
		// are essentially free (~1 µs).
		if ( get_option( 'perflocale_has_any_groups', '' ) === '1' ) {
			self::$has_any_groups_memo = true;
			return self::$has_any_groups_memo;
		}

		// L1 — survives `reset_static()` cycles that some flows trigger
		// mid-request but is the first place a pinned TRUE answer lives.
		$l1 = $this->cache->get_static( 'has_any_groups', 'perflocale_trans' );

		if ( $l1 === 1 || $l1 === true ) {
			self::$has_any_groups_memo = true;
			return self::$has_any_groups_memo;
		}

		// L2 — pinned TRUE from a previous request on Redis-backed sites.
		if ( $this->cache->l2_enabled() ) {
			$found = false;
			$value = wp_cache_get( 'has_any_groups', 'perflocale_trans', false, $found );

			if ( $found && (bool) $value ) {
				$this->cache->set_static( 'has_any_groups', 1, 'perflocale_trans' );
				self::$has_any_groups_memo = true;
				return self::$has_any_groups_memo;
			}
		}

		// DB — cheap presence check; explicit LIMIT 1 stops the planner
		// from scanning beyond the first matching row. The table name
		// comes from Schema::table() (plugin-controlled `{$wpdb->prefix}
		// perflocale_translation_groups`); not user input.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Identifier (not a value): bound with the %i placeholder below. WPCS cannot follow the nested prepare() call, hence the suppression.
		$exists = (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT 1 FROM %i LIMIT 1',
				$this->groups_table()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		if ( $exists ) {
			$this->cache->set_static( 'has_any_groups', 1, 'perflocale_trans' );

			if ( $this->cache->l2_enabled() ) {
				wp_cache_set( 'has_any_groups', 1, 'perflocale_trans', 0 );
			}

			// Persist via autoloaded option so subsequent requests skip
			// the DB roundtrip entirely. Idempotent — no-op if already
			// stored as '1' on autoload.
			update_option( 'perflocale_has_any_groups', '1', true );
		}

		self::$has_any_groups_memo = $exists;
		return self::$has_any_groups_memo;
	}

	/**
	 * Pin every layer of the has_any_groups cache to TRUE.
	 *
	 * Called after create_group() successfully commits so callers that
	 * subsequently consult has_any_groups() in the SAME request (most
	 * notably CacheInvalidator's short-circuit gate) see the just-created
	 * group instead of a stale FALSE memo from an earlier call. Idempotent:
	 * safe to call when already TRUE.
	 *
	 * @return void
	 */
	private function mark_has_any_groups(): void {
		self::$has_any_groups_memo = true;
		$this->cache->set_static( 'has_any_groups', 1, 'perflocale_trans' );

		if ( $this->cache->l2_enabled() ) {
			wp_cache_set( 'has_any_groups', 1, 'perflocale_trans', 0 );
		}

		// L0 — autoloaded option. update_option is a no-op if the value
		// is already '1', so re-firing this on every group creation costs
		// effectively nothing after the first one.
		update_option( 'perflocale_has_any_groups', '1', true );
	}

	/**
	 * Materialise (or return cached) eager map of object_id → full group
	 * link list for every link row of the given type on this blog.
	 *
	 * The point of this is to replace the per-call SELECT-IN that Step 2 of
	 * `prime_translations` performs (a DB roundtrip with a 2N-key IN list
	 * for every sub-query on the page) with a single alloptions hit. On
	 * sites without persistent object cache, that single SELECT-IN was
	 * the dominant warm-cache cost (~250 µs × 4 sub-queries ≈ 1 ms).
	 *
	 * Strategy:
	 *   - Per-request: memo in {@see $eager_link_map_memo}.
	 *   - Cross-request: persist in the autoloaded option
	 *     `perflocale_eager_links_{type}`. Reads land in alloptions
	 *     (~1 µs) for sites under the row + byte caps; bigger sites
	 *     get a 'too_large' sentinel so they fall through to the
	 *     per-key transient path.
	 *   - Invalidated by {@see invalidate_eager_link_map()} on every
	 *     translation/group write.
	 *
	 * Returns null for blogs that exceed the size caps OR have zero
	 * link rows. Callers should fall back to the original prime path
	 * when null is returned.
	 *
	 * @param ObjectType $type
	 * @return array<int, list<object>>|null
	 */
	public function get_eager_link_map( ObjectType $type ): ?array {
		// Bulk-write suspension: a bulk-translate loop invalidates the map
		// after EVERY created link, and the next row's read would rebuild
		// and persist the whole map again — O(rows × map size). While
		// suspended, readers fall back to the JOIN path (the pre-cap
		// behaviour) and one rebuild happens after resume.
		if ( self::$eager_link_map_suspended ) {
			return null;
		}

		// $memo_key is blog-prefixed so multisite switch_to_blog() can't
		// cross-contaminate the static memo. $option_key is the wp_options
		// row name and is naturally per-blog (each blog has its own
		// wp_<id>_options table), so it stays type-only.
		$memo_key   = self::eager_memo_key( $type->value );
		$option_key = 'perflocale_eager_links_' . $type->value;

		if ( array_key_exists( $memo_key, self::$eager_link_map_memo ) ) {
			$cached = self::$eager_link_map_memo[ $memo_key ];
			// `!== []` keeps the memo and the option path answering the same
			// thing. Before, a memoised `[]` came back as an authoritative empty
			// ARRAY while the option path returned null for the same state, so
			// the second consumer in a request cached "no translations" where the
			// first had correctly fallen back to SQL. Post-fix nothing memoises
			// `[]` any more (proven-empty uses EAGER_MAP_EMPTY); this makes the
			// invariant explicit rather than relying on it.
			return is_array( $cached ) && $cached !== [] ? $cached : null;
		}

		$stored = get_option( $option_key, false );

		if ( $stored === 'too_large' ) {
			self::$eager_link_map_memo[ $memo_key ] = 'too_large';
			return null;
		}

		if ( $stored === self::EAGER_MAP_EMPTY ) {
			// Proven empty by a COUNT that actually returned zero. Callers still
			// get null (unchanged contract: "no usable map, use the JOIN path"),
			// but the sentinel is memoised so this request does not re-run the
			// cold build for every consumer.
			self::$eager_link_map_memo[ $memo_key ] = self::EAGER_MAP_EMPTY;
			return null;
		}

		if ( is_array( $stored ) && $stored !== [] ) {
			self::$eager_link_map_memo[ $memo_key ] = $stored;
			return $stored;
		}

		// A stored `[]` is the pre-1.0.1 empty shape, which a FAILED cold build
		// could also write. It is therefore NOT trusted: fall through and
		// rebuild once. The rebuild re-persists either the real map or
		// EAGER_MAP_EMPTY, so an install poisoned by the old bug repairs itself
		// on the next read instead of waiting for a write or a Clear Cache —
		// one COUNT per blog/type after the update, not one per request.

		// Caps are filterable so sites with extra RAM (or smaller envelope)
		// can tune autoload bloat vs prime_translations DB cost. Default
		// 1000 rows / 500 KB serialised covers the typical multilingual
		// site comfortably without making alloptions a million bytes.
		$row_cap  = (int) apply_filters( 'perflocale/cache/eager_map_row_cap', self::EAGER_LINK_MAP_ROW_CAP, $type );
		$byte_cap = (int) apply_filters( 'perflocale/cache/eager_map_byte_cap', self::EAGER_LINK_MAP_BYTE_CAP, $type );

		// Cold build. Cheap size check first so we don't pull 100k+
		// rows on a site that will never fit in alloptions anyway.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		$count_raw = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i l
				 INNER JOIN %i g ON l.group_id = g.id
				 WHERE g.type = %s',
				$this->links_table(),
				$this->groups_table(),
				$type->value
			)
		);

		// get_var() answers NULL when the query FAILED, and `(int) null` is 0 —
		// which used to take the "this type has no links" branch below and
		// persist an AUTHORITATIVE empty map built from a deadlock or a "server
		// has gone away". Every consumer then cached "no translations" until the
		// next write. wpdb resets last_error at the start of every query, so it
		// reflects this one; same discipline as LanguageRepository::get_bootstrap().
		// Persist and memoise NOTHING on failure: the next healthy call rebuilds.
		if ( $count_raw === null || $this->wpdb->last_error !== '' ) {
			return null;
		}

		$count = (int) $count_raw;

		if ( $count === 0 ) {
			self::persist_eager_map( $option_key, self::EAGER_MAP_EMPTY );
			self::$eager_link_map_memo[ $memo_key ] = self::EAGER_MAP_EMPTY;
			return null;
		}

		if ( $count > $row_cap ) {
			self::persist_eager_map( $option_key, 'too_large' );
			self::$eager_link_map_memo[ $memo_key ] = 'too_large';
			return null;
		}

		$lang_table = Schema::table( 'languages' );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT l.*, lang.slug AS language_slug, lang.name AS language_name, lang.native_name AS language_native_name
				 FROM %i l
				 INNER JOIN %i g ON l.group_id = g.id
				 INNER JOIN %i lang ON l.language_id = lang.id
				 WHERE g.type = %s',
				$this->links_table(),
				$this->groups_table(),
				$lang_table,
				$type->value
			)
		);
		// phpcs:enable

		// A FAILED SELECT also yields `[]` here — wpdb::query() flushes
		// last_result before it returns false — so `$rows === []` on its own
		// cannot be read as "no rows". Check the error channel first and, on
		// failure, persist and memoise nothing so a later healthy request
		// rebuilds instead of the site serving an authoritative empty map.
		if ( ! is_array( $rows ) || $this->wpdb->last_error !== '' ) {
			return null;
		}

		if ( $rows === [] ) {
			self::persist_eager_map( $option_key, self::EAGER_MAP_EMPTY );
			self::$eager_link_map_memo[ $memo_key ] = self::EAGER_MAP_EMPTY;
			return null;
		}

		// Group rows by group_id so every member sees the full sibling list.
		$by_group = [];

		foreach ( $rows as $row ) {
			$gid = (int) ( $row->group_id ?? 0 );

			if ( $gid <= 0 ) {
				continue;
			}

			$by_group[ $gid ][] = $row;
		}

		$map = [];

		foreach ( $by_group as $links ) {
			foreach ( $links as $link ) {
				$oid = (int) ( $link->object_id ?? 0 );

				if ( $oid > 0 ) {
					$map[ $oid ] = $links;
				}
			}
		}

		// Defensive size guard on the serialised payload.
		$serialised = maybe_serialize( $map );

		if ( strlen( (string) $serialised ) > $byte_cap ) {
			self::persist_eager_map( $option_key, 'too_large' );
			self::$eager_link_map_memo[ $memo_key ] = 'too_large';
			return null;
		}

		if ( $map === [] ) {
			// Rows existed but none carried a usable object_id, so the map is
			// genuinely empty. Store the sentinel, never a bare `[]`: the reader
			// no longer trusts `[]`, so persisting it here would re-run this
			// cold build (COUNT + three-table JOIN) on every request forever.
			self::persist_eager_map( $option_key, self::EAGER_MAP_EMPTY );
			self::$eager_link_map_memo[ $memo_key ] = self::EAGER_MAP_EMPTY;

			return null;
		}

		self::persist_eager_map( $option_key, $map );
		self::$eager_link_map_memo[ $memo_key ] = $map;

		return $map;
	}

	/**
	 * Persist one eager-link-map option and clear WP's negative-cache blob.
	 *
	 * Every caller here writes an option that an invalidation just deleted,
	 * which is exactly the window WP core leaves open: `add_option()` returns
	 * false *before* it refreshes `notoptions` whenever its INSERT affects 0
	 * rows, so a concurrent request can leave the row present in `wp_options`
	 * while the name is still listed as missing. After that `get_option()`
	 * answers false forever, this 20 KB+ map is cold-rebuilt (a COUNT plus a
	 * three-table JOIN) on every request, and every re-persist is a silent
	 * no-op.
	 *
	 * Dropping the whole blob atomically is the same race-free fix
	 * {@see \PerfLocale\Cache\CacheManager::set_many()} already uses, and for
	 * the same reason: atomic delete is supported by every object-cache
	 * backend, atomic read-modify-write is not. The next miss rebuilds the
	 * blob from a single sub-millisecond lookup.
	 *
	 * @param string       $option_key Autoloaded option name.
	 * @param array|string $value      Map, the {@see EAGER_MAP_EMPTY} sentinel,
	 *                                 or the `'too_large'` sentinel. A bare `[]`
	 *                                 is no longer written — see EAGER_MAP_EMPTY.
	 * @return void
	 */
	private static function persist_eager_map( string $option_key, array|string $value ): void {
		update_option( $option_key, $value, true );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Invalidate the eager link map. Called by every write path that
	 * touches translation_groups or translation_links.
	 *
	 * @param ObjectType|null $type Restrict to one type, or null for all.
	 * @return void
	 */
	public function invalidate_eager_link_map( ?ObjectType $type = null ): void {
		$types = $type ? [ $type->value ] : [ 'post', 'term', 'string', 'post_type', 'taxonomy' ];

		foreach ( $types as $t ) {
			unset( self::$eager_link_map_memo[ self::eager_memo_key( $t ) ] );
			delete_option( 'perflocale_eager_links_' . $t );
			// delete_option() returns early WITHOUT clearing caches when the
			// DB row is already gone — a cache entry orphaned by a rolled-back
			// transaction (or an update/delete race between processes) then
			// serves a stale map forever. Purge the single-option cache
			// unconditionally so the next read must rebuild from the DB.
			wp_cache_delete( 'perflocale_eager_links_' . $t, 'options' );
		}

		// A post-link mutation moves posts between language buckets, so the
		// cached per-archive found_posts counts (PostQueryFilter's SQL_CALC
		// replacement) are now stale. Bump their generation. This is the single
		// funnel every post-link write passes through, so it covers link/unlink/
		// batch/admin/job paths without each needing to know about the counter.
		if ( $type === null || $type === ObjectType::Post ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( 'perflocale_found_rows' );
		}
	}

	/**
	 * Reproduce CacheManager's transient key so we can read the raw option
	 * row directly - lets prime_translations() skip the timeout-rewrite that
	 * set_transient() triggers on every call.
	 *
	 * @param string $key Cache key.
	 * @return string Transient option base (without the `_transient_` prefix).
	 */
	private function transient_option_name( string $key ): string {
		// Single source of truth lives in CacheManager — calling its public
		// derive method instead of reproducing the length-threshold + md5
		// fallback inline guarantees we can't drift if either side changes.
		return $this->cache->derive_transient_key( $key, 'perflocale_trans' );
	}

	/**
	 * Batch-load translations for many source object IDs in a single query.
	 *
	 * Returns a map of source-object-id => array of translation-link rows (same
	 * shape as get_translations()). Groups where the source object has no link
	 * are returned as an empty array for that id. Issues at most two DB queries
	 * regardless of the input size, replacing the N+1 pattern of looping
	 * get_translations() per-row.
	 *
	 * @param array<int, int> $object_ids Source object IDs.
	 * @param ObjectType      $type Object type.
	 * @return array<int, array<int, object>> object_id => [links...]
	 */
	public function get_translations_for_objects( array $object_ids, ObjectType $type ): array {
		$out = [];
		foreach ( $object_ids as $oid ) {
			$out[ (int) $oid ] = [];
		}

		$object_ids = array_values( array_unique( array_map( 'intval', $object_ids ) ) );
		$object_ids = array_filter( $object_ids, static fn( $i ) => $i > 0 );

		if ( empty( $object_ids ) ) {
			return $out;
		}

		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$lang_table   = Schema::table( 'languages' );

		// Step 1 - resolve each input object to its group_id.
		$args = array_merge( [ $type->value ], $object_ids );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
		$group_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.object_id, l.group_id
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id
				WHERE g.type = %s AND l.object_id IN ($placeholders)",
				array_merge( [ $this->links_table(), $this->groups_table() ], $args )
			)
		);
		// phpcs:enable

		if ( empty( $group_rows ) ) {
			return $out;
		}

		// Collect unique group IDs and map object -> group.
		$group_ids    = [];
		$obj_to_group = [];
		foreach ( $group_rows as $gr ) {
			$gid                  = (int) $gr->group_id;
			$oid                  = (int) $gr->object_id;
			$group_ids[ $gid ]    = true;
			$obj_to_group[ $oid ] = $gid;
		}

		$group_ids      = array_keys( $group_ids );
		$placeholders_g = implode( ',', array_fill( 0, count( $group_ids ), '%d' ) );

		// Step 2 - load every link in those groups, joined with languages.
		// $placeholders_g is a runtime-built %d-list matching the $group_ids array
		// length. The standards scanner can't statically see the placeholders inside
		// the string, hence the UnfinishedPrepare + NotPrepared disables.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
		$links = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, lang.slug AS language_slug, lang.name AS language_name, lang.native_name AS language_native_name
				FROM %i l
				INNER JOIN %i lang ON l.language_id = lang.id
				WHERE l.group_id IN ($placeholders_g)",
				array_merge( [ $this->links_table(), $lang_table ], $group_ids )
			)
		);
		// phpcs:enable

		if ( empty( $links ) ) {
			return $out;
		}

		// Bucket links by group_id.
		$links_by_group = [];
		foreach ( $links as $link ) {
			$links_by_group[ (int) $link->group_id ][] = $link;
		}

		// For each input object, copy its group's links into the output map.
		foreach ( $obj_to_group as $oid => $gid ) {
			$out[ $oid ] = $links_by_group[ $gid ] ?? [];
		}

		return $out;
	}

	/**
	 * Find source object IDs whose translation group includes a link matching
	 * the given language (and optionally status).
	 *
	 * Used by the Translations admin page to push language/status filters down
	 * into SQL, so WP_Query's `found_posts` reflects the real filter-result
	 * size instead of the unfiltered row count (which previously caused a
	 * paginator-vs-filter mismatch).
	 *
	 * When $status is 'empty', returns object IDs whose group is MISSING a link
	 * in the target language.
	 *
	 * @param ObjectType $type Object type (e.g. Post).
	 * @param int        $language_id Target language ID.
	 * @param string     $status Optional status filter ('' = any, 'empty'
	 *     = missing the language entirely).
	 * @return array<int, int> Source object IDs.
	 */
	public function find_source_object_ids_by_language_status(
		ObjectType $type,
		int $language_id,
		string $status = '',
		int $source_language_id = 0
	): array {
		if ( $language_id <= 0 ) {
			return [];
		}

		// See the note on $source_language_id in the docblock: bound the outer
		// scan to the one row per group that can survive the caller's filter.
		$src_sql  = $source_language_id > 0 ? ' AND l.language_id = %d' : '';
		$src_args = $source_language_id > 0 ? [ $source_language_id ] : [];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		if ( $status === 'empty' ) {
			// Source object IDs whose group has NO link in the target language.
			$rows = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT l.object_id
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					WHERE g.type = %s
					AND l.language_id != %d' . $src_sql . '
					AND l.group_id NOT IN (
						SELECT group_id FROM %i
						WHERE language_id = %d
					)',
					array_merge(
						[ $this->links_table(), $this->groups_table(), $type->value, $language_id ],
						$src_args,
						[ $this->links_table(), $language_id ]
					)
				)
			);
			return array_map( 'intval', (array) $rows );
		}

		// Source object IDs whose group has a link in target language (with optional status match).
		// The outer object_id filter is "any object in that group", which is the source set
		// (the translations-page lists source-or-any rows; the render then dedupes visually).
		if ( $status !== '' ) {
			$rows = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT l.object_id
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					WHERE g.type = %s' . $src_sql . '
					AND l.group_id IN (
						SELECT group_id FROM %i
						WHERE language_id = %d AND status = %s
					)',
					array_merge(
						[ $this->links_table(), $this->groups_table(), $type->value ],
						$src_args,
						[ $this->links_table(), $language_id, $status ]
					)
				)
			);
		} else {
			$rows = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT l.object_id
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					WHERE g.type = %s' . $src_sql . '
					AND l.group_id IN (
						SELECT group_id FROM %i
						WHERE language_id = %d
					)',
					array_merge(
						[ $this->links_table(), $this->groups_table(), $type->value ],
						$src_args,
						[ $this->links_table(), $language_id ]
					)
				)
			);
		}
		// phpcs:enable

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Find source object IDs whose translation group includes ANY link at
	 * the given status (no language constraint). Used for the "show all posts
	 * whose any-language translation is at status X" filter.
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $status Status value (non-empty, non-'empty').
	 * @return array<int, int>
	 */
	public function find_source_object_ids_by_status_any_language(
		ObjectType $type,
		string $status,
		int $source_language_id = 0
	): array {
		// Bound the outer scan to the one row per group that can survive the
		// caller's source-only filter. See find_source_object_ids_by_language_status().
		$src_sql  = $source_language_id > 0 ? ' AND l.language_id = %d' : '';
		$src_args = $source_language_id > 0 ? [ $source_language_id ] : [];
		if ( $status === '' || $status === 'empty' ) {
			return [];
		}

		// For post-type translations, accept rows where the link is still
		// the default 'empty' but the underlying WP post is actually at
		// the requested status (publish / draft). Mirrors count_by_status()
		// and the in-PHP resolution in TranslationsPage::display() so all
		// three views agree on which posts match a given status filter.
		$post_status_match = null;
		if ( $type === ObjectType::Post ) {
			if ( $status === 'published' ) {
				$post_status_match = 'publish';
			} elseif ( $status === 'draft' ) {
				$post_status_match = 'draft';
			}
		}

		$posts_table = $this->wpdb->posts;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		if ( $post_status_match !== null ) {
			$rows = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT DISTINCT l.object_id
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					WHERE g.type = %s" . $src_sql . "
					AND l.group_id IN (
						SELECT l2.group_id
						FROM %i l2
						LEFT JOIN %i p ON l2.object_id = p.ID
						WHERE l2.status = %s
							OR ( l2.status = 'empty' AND p.post_status = %s )
					)",
					array_merge(
						[ $this->links_table(), $this->groups_table(), $type->value ],
						$src_args,
						[ $this->links_table(), $posts_table, $status, $post_status_match ]
					)
				)
			);
		} else {
			$rows = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT l.object_id
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					WHERE g.type = %s' . $src_sql . '
					AND l.group_id IN (
						SELECT group_id FROM %i WHERE status = %s
					)',
					array_merge(
						[ $this->links_table(), $this->groups_table(), $type->value ],
						$src_args,
						[ $this->links_table(), $status ]
					)
				)
			);
		}
		// phpcs:enable

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Find source object IDs whose group is missing a link in at least one of
	 * the given languages - used for the "status=empty, language=All" filter.
	 *
	 * @param ObjectType     $type Object type.
	 * @param array<int,int> $language_ids Active language IDs.
	 * @return array<int, int>
	 */
	public function find_source_object_ids_missing_any_language(
		ObjectType $type,
		array $language_ids,
		int $source_language_id = 0
	): array {
		$language_ids = array_values( array_unique( array_map( 'intval', $language_ids ) ) );
		$language_ids = array_filter( $language_ids, static fn( $i ) => $i > 0 );

		if ( empty( $language_ids ) ) {
			return [];
		}

		// Cache for a short window: the underlying correlated-subquery +
		// LEFT JOIN + GROUP BY scans all of translation_groups and is the
		// slowest admin query here on 50k+ groups (every paginator click
		// re-ran it). 60s TTL is fine for an admin list; link insert/update/
		// delete invalidate explicitly, so the TTL is just the staleness
		// ceiling if an invalidation path was missed.
		sort( $language_ids );
		// $source_language_id participates in the key: it changes the result set.
		$cache_key = sprintf( 'missing_any_lang_%s_%s_%d', $type->value, md5( implode( ',', $language_ids ) ), $source_language_id );

		return (array) $this->cache->get(
			$cache_key,
			function () use ( $type, $language_ids, $source_language_id ): array {
				// Bound the outer scan to the row that survives the caller's
				// source-only filter. See find_source_object_ids_by_language_status().
				$src_sql  = $source_language_id > 0 ? ' AND l.language_id = %d' : '';
				$src_args = $source_language_id > 0 ? [ $source_language_id ] : [];
				$lang_count   = count( $language_ids );
				$placeholders = implode( ',', array_fill( 0, $lang_count, '%d' ) );

				// Inner query: LEFT-JOIN each group to its links filtered to the
				// target languages and count distinct matches; a count below the
				// target count means at least one is missing. LEFT JOIN is
				// essential so groups with ZERO matching links still appear as
				// count=0 (correctly "missing").
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows = $this->wpdb->get_col(
					$this->wpdb->prepare(
						"SELECT DISTINCT l.object_id
						FROM %i l
						INNER JOIN %i g ON l.group_id = g.id
						WHERE g.type = %s" . $src_sql . "
						AND l.group_id IN (
							SELECT g2.id
							FROM %i g2
							LEFT JOIN %i l2
								ON l2.group_id = g2.id AND l2.language_id IN ($placeholders)
							WHERE g2.type = %s
							GROUP BY g2.id
							HAVING COUNT(DISTINCT l2.language_id) < %d
						)",
						array_merge(
							[ $this->links_table(), $this->groups_table(), $type->value ],
							$src_args,
							[ $this->groups_table(), $this->links_table() ],
							$language_ids,
							[ $type->value, $lang_count ]
						)
					)
				);
				// phpcs:enable

				return array_map( 'intval', (array) $rows );
			},
			MINUTE_IN_SECONDS,
			'perflocale_trans'
		);
	}

	public function get_translations( int $object_id, ObjectType $type ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$cache_key = "translations_{$type->value}_{$object_id}";

		// Zero-state short-circuit: with no translation groups on this blog,
		// the loader's JOIN query is guaranteed to return zero rows. Skip
		// straight to caching `[]` and avoid the DB hit. The check is
		// sub-µs after has_any_groups()'s first call per request, so real
		// multilingual sites see no overhead from this guard.
		if ( ! $this->has_any_groups() ) {
			$cached = $this->cache->get_static( $cache_key, 'perflocale_trans' );

			if ( $cached !== null ) {
				return $cached;
			}

			$this->cache->set_static( $cache_key, [], 'perflocale_trans' );
			return [];
		}

		// L0 — eager link map. Same fast-path as prime_translations Step 0:
		// when the blog fits the size caps, every translation_link is in
		// the autoloaded option, so a single-id lookup is an array hit
		// instead of a 3-layer cache descent (worst-case DB roundtrip).
		// Falls through to the original cache->get on bigger blogs where
		// get_eager_link_map returns null.
		if ( $this->cache->get_static( $cache_key, 'perflocale_trans' ) === null ) {
			$eager_map = $this->get_eager_link_map( $type );

			if ( is_array( $eager_map ) ) {
				$links = $eager_map[ $object_id ] ?? [];
				$this->cache->set_static( $cache_key, $links, 'perflocale_trans' );
				return $links;
			}
		}

		return $this->cache->get(
			$cache_key,
			function () use ( $object_id, $type ): array {
				$lang_table = Schema::table( 'languages' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$results = $this->wpdb->get_results(
					$this->wpdb->prepare(
						'SELECT l.*, lang.slug AS language_slug, lang.name AS language_name, lang.native_name AS language_native_name
						FROM %i l
						INNER JOIN %i g ON l.group_id = g.id
						INNER JOIN %i lang ON l.language_id = lang.id
						WHERE g.type = %s
						AND l.group_id = (
							SELECT l2.group_id FROM %i l2
							INNER JOIN %i g2 ON l2.group_id = g2.id
							WHERE l2.object_id = %d AND g2.type = %s
							LIMIT 1
						)',
						$this->links_table(),
						$this->groups_table(),
						$lang_table,
						$type->value,
						$this->links_table(),
						$this->groups_table(),
						$object_id,
						$type->value
					)
				);

				return is_array( $results ) ? $results : [];
			},
			HOUR_IN_SECONDS,
			'perflocale_trans'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get the translated object ID for a specific language.
	 *
	 * @param int        $object_id Source object ID.
	 * @param ObjectType $type Object type.
	 * @param int        $language_id Target language ID.
	 * @return int|null Translated object ID or null.
	 */
	public function get_translation_in_language( int $object_id, ObjectType $type, int $language_id ): ?int {
		$translations = $this->get_translations( $object_id, $type );

		foreach ( $translations as $link ) {
			if ( (int) $link->language_id === $language_id ) {
				return (int) $link->object_id;
			}
		}

		return null;
	}

	/**
	 * Create a new translation group and link the first object.
	 *
	 * When `$migration_source` is provided, the (migration_type, source_key)
	 * tuple gets recorded in `perflocale_migration_source_map` INSIDE the
	 * same transaction that creates the group row — so a retry after a
	 * partial-failure crash or a post-import DB restore can find the
	 * mapping and reuse the existing group_id instead of allocating a
	 * duplicate. Callers (WpmlImporter / PolylangImporter) should
	 * `get_group_id()` against the map FIRST and only call create_group()
	 * with the migration_source param when no prior mapping exists.
	 *
	 * Canonical migration_source shapes (used by the shipped importers):
	 *
	 *   - WPML posts:      `[ 'type' => 'wpml',     'key' => '<trid>|post'      ]`
	 *   - WPML terms:      `[ 'type' => 'wpml',     'key' => '<trid>|term'      ]`
	 *   - Polylang posts:  `[ 'type' => 'polylang', 'key' => '<term_id>|post'   ]`
	 *   - Polylang terms:  `[ 'type' => 'polylang', 'key' => '<term_id>|term'   ]`
	 *   - TranslatePress:  `[ 'type' => 'trp',      'key' => '<post_id>|<lang_id>' ]`
	 *
	 * Custom importers should pick a stable identifier from the source
	 * plugin that survives DB restores (e.g. the source plugin's own
	 * translation-group identifier, not the WP post_id). The `type`
	 * string is the operator-facing name passed to
	 * `wp perflocale migrate --force-restart <type>` — keep it short
	 * and stable.
	 *
	 * @param ObjectType                                $type Object type.
	 * @param int                                       $object_id Object ID.
	 * @param int                                       $language_id Language ID.
	 * @param string                                    $status Translation status.
	 * @param \PerfLocale\Enum\SourceType               $source Translation source provenance.
	 * @param array{type: string, key: string}|null     $migration_source Optional source-map link.
	 * @return int|false Group ID or false.
	 * @throws \InvalidArgumentException When $object_id is <= 0 and WP_DEBUG is on (guards a site-wide DELETE).
	 */
	public function create_group(
		ObjectType $type,
		int $object_id,
		int $language_id,
		string $status = 'published',
		\PerfLocale\Enum\SourceType $source = \PerfLocale\Enum\SourceType::Manual,
		?array $migration_source = null
	): int|false {
		// Same guard as link_object() (which create_group() calls below):
		// an object_id of 0 would cascade into a site-wide DELETE there.
		if ( $object_id <= 0 ) {
			$msg = sprintf(
				'PerfLocale %s() requires a non-zero object_id (got %d). For string translations pass the string_id - passing 0 would delete every zero-object row in translation_links site-wide on every call.',
				__FUNCTION__,
				$object_id
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				throw new \InvalidArgumentException( esc_html( $msg ) );
			}

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional programmer-error log for production soft-fail.
			}

			return false;
		}

		// Use a transaction to ensure group + link are created atomically.
		// If anything fails, both are rolled back - no orphaned empty groups.
		// When an outer owner (e.g. a migration importer) has already opened a
		// transaction and registered it via set_in_transaction(), nest inside it
		// instead of issuing a second START — MySQL has no nested transactions,
		// so a second START would IMPLICITLY COMMIT the outer one. Mirrors the
		// $needs_own_transaction pattern in link_object().
		$needs_own_transaction = ! self::$in_transaction;

		if ( $needs_own_transaction ) {
			$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::$in_transaction = true;
		}

		$committed = false;

		// try/finally guarantees the static $in_transaction flag is cleared
		// (and the transaction rolled back) even if a hook fired inside
		// link_object()/insert() throws — otherwise the flag would leak true
		// for the rest of the request and make subsequent create_group()/
		// link_object() calls silently run their statements outside a
		// transaction.
		try {
			// Create the group.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $this->wpdb->insert(
				$this->groups_table(),
				[ 'type' => $type->value ],
				[ '%s' ]
			);

			if ( $result === false ) {
				return false;
			}

			$group_id = (int) $this->wpdb->insert_id;

			// Link the object.
			$link_id = $this->link_object( $group_id, $object_id, $language_id, $status, $source );

			if ( $link_id === false ) {
				return false;
			}

			// Migration source-map link, if the caller asked for one. Done
			// INSIDE the same transaction so a crash here rolls back both the
			// group and the link, leaving no orphan group OR stale map entry.
			// The map's UNIQUE (migration_type, source_key) backs an
			// ON DUPLICATE KEY UPDATE so a re-importer hitting the same source
			// key after the group_id has been recycled (very rare) converges
			// to the latest group rather than failing.
			if ( is_array( $migration_source ) && $migration_source['type'] !== '' && $migration_source['key'] !== '' ) {
				// Table bound with the %i identifier placeholder (WP 6.2+); values
				// bound with %s/%s/%d — fully prepared, no interpolation. The
				// disable block spans the whole multi-line call because the
				// sniff doesn't recognise the injected $this->wpdb property as
				// the global $wpdb.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
				$map_result = $this->wpdb->query(
					$this->wpdb->prepare(
						'INSERT INTO %i (migration_type, source_key, group_id)
						 VALUES (%s, %s, %d)
						 ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)',
						Schema::table( 'migration_source_map' ),
						$migration_source['type'],
						$migration_source['key'],
						$group_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

				if ( $map_result === false ) {
					return false;
				}
			}

			if ( $needs_own_transaction ) {
				// A failed COMMIT (deadlock/connection loss at commit time) means
				// the server already rolled the transaction back. Bail before
				// mark_has_any_groups()/returning a phantom id; the finally below
				// clears the static flag (its ROLLBACK is a harmless no-op then).
				if ( false === $this->wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					return false;
				}
			}
			$committed = true;
		} finally {
			// Only the transaction owner commits/rolls-back and clears the flag.
			// When nested under an outer owner, an early `return false` above just
			// propagates and the outer owner issues the single ROLLBACK.
			if ( $needs_own_transaction ) {
				if ( ! $committed ) {
					$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				self::$in_transaction = false;
			}
		}

		// First-group invariant: flip every layer of the has_any_groups cache
		// to TRUE now that a group definitely exists. Without this, an early
		// in-request has_any_groups() call (e.g. UrlConverter::should_skip_
		// url_modification) pins the per-request memo to FALSE; subsequent
		// CacheInvalidator::clear_hreflang_for_object_and_siblings short-
		// circuits on that stale memo and leaves stale hreflang transients
		// (up to 12h) pointing at the wrong sibling set.
		$this->mark_has_any_groups();

		// The pre-assignment read path caches an empty translations list for
		// this object (auto_assign_default_language's detect runs before the
		// group exists) — in L2 with an hour TTL and in the eager link map,
		// so even a fresh request keeps seeing "no language" after the link
		// lands. Same invalidation idiom as update_link_status().
		$this->cache->flush_object( $object_id, $type->value );
		$this->invalidate_find_cache( $object_id, $type );
		$this->invalidate_eager_link_map( $type );

		return $group_id;
	}

	/**
	 * Exclusive-link: move an object into this group, destroying any prior
	 * links the object had in any other group.
	 *
	 * It enforces the invariant *"an object belongs to exactly one group of
	 * its type"* by deleting the object's existing links before inserting the
	 * new one — a `DELETE … INNER JOIN translation_groups … WHERE g.type =
	 * <destination group's type> AND l.object_id = $object_id`, scoped to the
	 * destination type so a term (or string) that merely shares the numeric
	 * object_id keeps its own link. Correct for **posts and terms** (one post
	 * → one group → N language-links for distinct post IDs).
	 *
	 * **Do not call on string-type groups** - string translations share
	 * one `object_id = string_id` across all language-links, so the
	 * DELETE would wipe every existing language-link for the string.
	 * Use `upsert_link()` for string translations. A runtime guard below
	 * rejects string-group calls: it throws in `WP_DEBUG=true`, writes to
	 * `debug.log` and returns `false` in production.
	 *
	 * @param int                         $group_id Group ID.
	 * @param int                         $object_id Object ID (post ID or term ID - never 0).
	 * @param int                         $language_id Language ID.
	 * @param string                      $status Translation status.
	 * @param \PerfLocale\Enum\SourceType $source Translation source provenance.
	 * @return int|false Link ID or false on rejection/failure.
	 * @throws \InvalidArgumentException When $object_id <= 0 or the group is a string-type group, and WP_DEBUG is on.
	 */
	public function link_object(
		int $group_id,
		int $object_id,
		int $language_id,
		string $status = 'empty',
		\PerfLocale\Enum\SourceType $source = \PerfLocale\Enum\SourceType::Manual
	): int|false {
		// Guard 1: `object_id = 0` would make the first DELETE wipe every
		// zero-object row in the table in one shot. Fail loud in debug,
		// log + return false in production so no destructive SQL runs
		// either way.
		if ( $object_id <= 0 ) {
			$msg = sprintf(
				'PerfLocale %s() requires a non-zero object_id (got %d). For string translations pass the string_id - passing 0 would delete every zero-object row in translation_links site-wide on every call.',
				__FUNCTION__,
				$object_id
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				throw new \InvalidArgumentException( esc_html( $msg ) );
			}

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional programmer-error log for production soft-fail.
			}

			return false;
		}

		// Guard 2: string-type groups carry one object_id (the string_id)
		// across all language-links. The DELETE step below would therefore
		// wipe every prior language's link for the string. Route callers
		// to upsert_link() which uses INSERT ... ON DUPLICATE KEY UPDATE
		// and leaves sibling language links untouched. One indexed SELECT
		// per call - cached after the first hit in a request.
		$group = $this->find( $group_id );

		if ( $group && ( $group->type ?? '' ) === 'string' ) {
			$msg = sprintf(
				'PerfLocale link_object() refuses to operate on string-type group %d. link_object() DELETEs all prior links for this object, which would wipe every language link for the string. Use upsert_link() for string translations - its INSERT ... ON DUPLICATE KEY UPDATE pattern leaves sibling language links intact.',
				$group_id
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				throw new \InvalidArgumentException( esc_html( $msg ) );
			}

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional programmer-error log for production soft-fail.
			}

			return false;
		}

		// Guard 3: the destination group must exist. Everything below keys off
		// its type — the snapshot, the invariant DELETE and the type written
		// into the new row — and with no group row there is no type: the DELETE
		// would fall back to "every link with this object_id, whatever its
		// type", wiping a colliding term's (or string's) link and
		// garbage-collecting that row's group, and the INSERT would store
		// type='' — a link invisible to every type-qualified lookup — under a
		// group id that names nothing. A missing group is always caller error
		// (a group id read before a concurrent GC removed it, or a stale
		// find_for_object() cache entry), never a state to write through.
		if ( ! $group ) {
			$msg = sprintf(
				'PerfLocale link_object() refuses to link object %1$d to group %2$d: no such group. The group was removed after the caller resolved it (concurrent GC, import, or a stale cache entry); re-resolve the group and retry.',
				$object_id,
				$group_id
			);

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional programmer-error log for production soft-fail.
			}

			return false;
		}

		$safe_status = sanitize_key( $status );
		$safe_source = $source->value;

		// translation_links.object_id is polymorphic (post / term / string ID
		// spaces share one column), so a post and a term with COLLIDING numeric
		// IDs would corrupt each other if the invariant DELETE below keyed on
		// object_id alone: linking term 7 would DELETE post 7's link (evicting
		// it from its group). Both the snapshot and the DELETE are therefore
		// scoped to the DESTINATION group's type, and the row written carries
		// that same type so the object_lang UNIQUE keeps the id-spaces apart.
		// Guard 3 above guarantees the group row — and so the type — exists.
		$dest_type = (string) ( $group->type ?? '' );

		// Use transaction to ensure DELETE + INSERT are atomic.
		// Without this, a crash between DELETE and INSERT orphans the object.
		// Uses a static flag instead of @@in_transaction which is unavailable
		// on older MySQL/MariaDB versions.
		$needs_own_transaction = ! self::$in_transaction;

		if ( $needs_own_transaction ) {
			$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::$in_transaction = true;
		}

		// Snapshot the groups this object belongs to so we can GC any that
		// become empty after the move (re-linking out of an auto-created group
		// would otherwise leave it as a dangling widow row).
		// Tables are bound with %i, so the statements interpolate nothing.
		// NotPrepared stays disabled because that sniff only recognises a bare
		// $wpdb->prepare(), not the injected $this->wpdb property.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		$previous_group_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT l.group_id FROM %i l INNER JOIN %i g ON g.id = l.group_id AND g.type = %s WHERE l.object_id = %d',
				$this->links_table(),
				$this->groups_table(),
				$dest_type,
				$object_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders
		$previous_group_ids = array_map( 'intval', (array) $previous_group_ids );

		// Remove existing links for this object within its own TYPE space, so
		// the object belongs to exactly one group of that type. Type-scoped
		// (see $dest_type note above) so a foreign type-space row that merely
		// shares the numeric object_id is never wiped. Failure to delete means
		// the invariant could be violated by the subsequent INSERT — abort +
		// roll back so the broken state never commits.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		$invariant_delete = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE l FROM %i l INNER JOIN %i g ON g.id = l.group_id AND g.type = %s WHERE l.object_id = %d',
				$this->links_table(),
				$this->groups_table(),
				$dest_type,
				$object_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

		if ( false === $invariant_delete ) {
			if ( $needs_own_transaction ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::$in_transaction = false;
			}
			return false;
		}

		// Remove any existing link for this group+language
		// (prevents unique constraint violations on group_lang key).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $this->wpdb->delete(
			$this->links_table(),
			[
				'group_id'    => $group_id,
				'language_id' => $language_id,
			],
			[ '%d', '%d' ]
		) ) {
			if ( $needs_own_transaction ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::$in_transaction = false;
			}
			return false;
		}

		// Insert the new link.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->links_table(),
			[
				'group_id'    => $group_id,
				'object_id'   => $object_id,
				'language_id' => $language_id,
				// Type mirrors the destination group so the (type, object_id,
				// language_id) UNIQUE keeps post/term/string id-spaces distinct.
				'type'        => $dest_type,
				'status'      => $safe_status,
				'source'      => $safe_source,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( $result === false ) {
			if ( $needs_own_transaction ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::$in_transaction = false;
			}
			return false;
		}

		// Garbage-collect any previous group(s) that are now empty because
		// we just moved this object out of them. Only applies to previous
		// groups that are (a) NOT the destination and (b) actually empty.
		// Excludes string-type groups - those are shared per-string across
		// language links and should never be GC'd here.
		$surviving_prev = [];
		foreach ( $previous_group_ids as $prev_gid ) {
			if ( $prev_gid <= 0 || $prev_gid === $group_id ) {
				continue;
			}

			// See the snapshot block above for why NotPrepared stays disabled.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
			$remaining = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE group_id = %d',
					$this->links_table(),
					$prev_gid
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

			if ( $remaining > 0 ) {
				// Group survives (still has other siblings), but its cached
				// member list - and those siblings' find_for_object() entries -
				// still include the object we just moved out. Refresh it below
				// once the move has committed.
				$surviving_prev[] = $prev_gid;
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
			$prev_group_row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT type FROM %i WHERE id = %d',
					$this->groups_table(),
					$prev_gid
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

			if ( ! $prev_group_row || ( $prev_group_row->type ?? '' ) === 'string' ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->delete(
				$this->groups_table(),
				[ 'id' => $prev_gid ],
				[ '%d' ]
			) ) {
				// Same shape as the INSERT-failure path at line ~1566: a true
				// SQL error here (connection drop, lock timeout, permission)
				// means the transaction is in a doubtful state — better to
				// roll the whole link operation back so the caller can retry
				// than to commit half-done cleanup with the main link applied.
				// DELETE returning 0 affected rows is not false, so a benign
				// concurrent-GC race won't hit this path.
				if ( $needs_own_transaction ) {
					$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::$in_transaction = false;
				}
				return false;
			}
		}

		if ( $needs_own_transaction ) {
			$committed            = $this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::$in_transaction = false;
			if ( false === $committed ) {
				// COMMIT can fail on lock timeout / deadlock / connection loss;
				// the server has already rolled the transaction back, so the
				// link did not persist. Surface failure rather than returning a
				// link id the caller would treat as committed.
				return false;
			}
		}

		$link_id = (int) $this->wpdb->insert_id;

		if ( ! $link_id ) {
			return false;
		}

		// Invalidate cache for all objects in this group (including the new object).
		$this->invalidate_group_cache( $group_id );

		// Refresh any previous group that survived the move so its remaining
		// siblings no longer resolve the moved-out object as a member.
		foreach ( $surviving_prev as $prev_gid ) {
			$this->invalidate_group_cache( $prev_gid );
		}

		// Also clear find_for_object cache for the linked object - the group
		// cache query may not include it yet if the insert just happened.
		$group_row = $this->find( $group_id );
		if ( $group_row ) {
			$find_key = self::find_cache_key( (string) $group_row->type, $object_id );
			unset( self::$find_cache[ $find_key ] );
		}

		/** @hook perflocale/translation/linked Fires after an object is linked to a group. */
		do_action( 'perflocale/translation/linked', $group_id, $object_id, $language_id );

		return $link_id;
	}

	/**
	 * Additively link a language to a group via `INSERT … ON DUPLICATE KEY
	 * UPDATE`. Sibling-language links are never touched.
	 *
	 * The intended collision is on the `(group_id, language_id)` UNIQUE key
	 * `group_lang`, but the table carries a second UNIQUE — `object_lang`
	 * `(type, object_id, language_id)` — and a stale link for this string in
	 * another group collides on that one instead, updating a foreign row and
	 * leaving this group unlinked. The write therefore re-reads the
	 * group_lang key to decide what actually happened; when nothing linked,
	 * it deletes the conflicting stale row (type-scoped, never this group's)
	 * and writes once more. That single conditional DELETE is the only one
	 * here.
	 *
	 * Use this for **string translations**, where one string (one group)
	 * carries N language-links that all share the same `object_id`
	 * (`object_id = string_id` per the admin String-save convention).
	 * Calling it multiple times for different languages leaves every prior
	 * language's link intact; calling it twice for the same language
	 * updates that row in place.
	 *
	 * For posts and terms (one object → one group) use `link_object()`,
	 * which enforces the one-object invariant via its DELETE step.
	 *
	 * @param int                         $group_id Group ID.
	 * @param int                         $object_id Object ID (string ID for string groups).
	 * @param int                         $language_id Language ID.
	 * @param string                      $status Translation status.
	 * @param \PerfLocale\Enum\SourceType $source Translation source provenance.
	 * @return int|false Link ID or false on failure.
	 * @throws \InvalidArgumentException When $object_id <= 0 and WP_DEBUG is on (mirrors link_object() guard).
	 */
	public function upsert_link(
		int $group_id,
		int $object_id,
		int $language_id,
		string $status = 'translated',
		\PerfLocale\Enum\SourceType $source = \PerfLocale\Enum\SourceType::Manual
	): int|false {
		// Same object_id > 0 guard as link_object() - dual-mode.
		if ( $object_id <= 0 ) {
			$msg = sprintf(
				'PerfLocale %s() requires a non-zero object_id (got %d). For string translations pass the string_id.',
				__FUNCTION__,
				$object_id
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				throw new \InvalidArgumentException( esc_html( $msg ) );
			}

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional programmer-error log for production soft-fail.
			}

			return false;
		}

		$safe_status = sanitize_key( $status );
		$safe_source = $source->value;

		$link_id = $this->write_string_link( $group_id, $object_id, $language_id, $safe_status, $safe_source );

		if ( $link_id === 0 ) {
			// The write reported no error yet this group has no link for the
			// language: translation_links carries TWO unique keys, and the
			// INSERT collided on object_lang (type, object_id, language_id)
			// instead of the intended group_lang (group_id, language_id). The
			// ON DUPLICATE KEY UPDATE then rewrote a row that belongs to a
			// DIFFERENT group and left its group_id alone, so nothing linked
			// this string to $group_id. That stale row is unreachable debris —
			// the strings table says this string belongs to $group_id, and a
			// string carries exactly one group — so drop it and write again.
			// Same reap BulkStringTranslateJob performs, scoped by type
			// because object_id is polymorphic (a post or term id collides
			// freely with a string id) and by "not this group" so the row we
			// are trying to write is never the one deleted.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
			$reaped = $this->wpdb->query(
				$this->wpdb->prepare(
					'DELETE FROM %i WHERE type = %s AND object_id = %d AND language_id = %d AND group_id != %d',
					$this->links_table(),
					\PerfLocale\Enum\ObjectType::String->value,
					$object_id,
					$language_id,
					$group_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

			if ( is_int( $reaped ) && $reaped > 0 ) {
				$link_id = $this->write_string_link( $group_id, $object_id, $language_id, $safe_status, $safe_source );
			}
		}

		$this->invalidate_group_cache( $group_id );

		if ( $link_id > 0 ) {
			/** @hook perflocale/translation/linked Fires after an object is linked to a group. */
			do_action( 'perflocale/translation/linked', $group_id, $object_id, $language_id );

			return $link_id;
		}

		return false;
	}

	/**
	 * One `INSERT … ON DUPLICATE KEY UPDATE` for a string link, answering
	 * with the id of the row that now links THIS group to THIS language.
	 *
	 * The id is resolved by re-reading the group_lang key rather than by
	 * trusting `insert_id`. On an ON DUPLICATE KEY UPDATE that updates,
	 * `insert_id` names whichever row MySQL touched — and when the statement
	 * collided on the object_lang key that is a row in a DIFFERENT group, so
	 * returning it would report success for a link this group never got.
	 * Costs one indexed lookup on the UNIQUE key per call.
	 *
	 * @param int    $group_id    Group ID.
	 * @param int    $object_id   String ID.
	 * @param int    $language_id Language ID.
	 * @param string $status      Sanitised status.
	 * @param string $source      Source value.
	 * @return int Link ID, or 0 when this group + language ended up with no row.
	 */
	private function write_string_link( int $group_id, int $object_id, int $language_id, string $status, string $source ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table bound with %i; the NotPrepared sniff misses $this->wpdb->prepare().
		// upsert_link() is the string-group-safe path (link_object()'s Guard 2
		// routes string groups here; nothing routes post/term groups here), so
		// type is always 'string' — set it explicitly, no group lookup needed.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (group_id, object_id, language_id, type, status, source)
				VALUES (%d, %d, %d, \'string\', %s, %s)
				ON DUPLICATE KEY UPDATE
					object_id = VALUES(object_id),
					status = VALUES(status),
					source = VALUES(source)',
				$this->links_table(),
				$group_id,
				$object_id,
				$language_id,
				$status,
				$source
			)
		);

		if ( $result === false ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE group_id = %d AND language_id = %d LIMIT 1',
				$this->links_table(),
				$group_id,
				$language_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Remove a SINGLE language's link from a group (group_id + language_id),
	 * leaving sibling-language links intact. Used when a translation is
	 * removed: the string_translations row and its translation_links row must
	 * be dropped TOGETHER (mirrors AdminController's string-save removal), or
	 * an orphan 'translated' link is left pointing at a now-missing row.
	 *
	 * @param int $group_id    Group ID.
	 * @param int $language_id Language ID whose link to remove.
	 * @return bool True if a row was deleted.
	 */
	public function unlink_language( int $group_id, int $language_id ): bool {
		if ( $group_id <= 0 || $language_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $this->wpdb->delete(
			$this->links_table(),
			[
				'group_id'    => $group_id,
				'language_id' => $language_id,
			],
			[ '%d', '%d' ]
		);

		return (int) $deleted > 0;
	}

	/**
	 * Update the status/source of ONE object's translation link.
	 *
	 * `object_id` is polymorphic — it is a post id under `type='post'` and a
	 * term id under `type='term'`, and the two id-spaces collide freely — so
	 * the UPDATE is scoped by type as well. Without that scope, publishing
	 * post 42's German translation also rewrote the status of term 42's
	 * German link.
	 *
	 * No caller ships in the plugin today (the publish-sync path writes
	 * through link_object()); it stays as the repository's status-write entry
	 * point for addons and is kept type-correct for whoever picks it up.
	 *
	 * @param int        $object_id Object ID (post id, term id, or string id per $type).
	 * @param int        $language_id Language ID.
	 * @param string     $status New status.
	 * @param string     $source New source (optional).
	 * @param ObjectType $type Object type the id belongs to. Defaults to post.
	 * @return bool True when the UPDATE ran (a matching row is not guaranteed).
	 */
	public function update_link_status( int $object_id, int $language_id, string $status, string $source = '', ObjectType $type = ObjectType::Post ): bool {
		$data   = [
			'status'     => sanitize_key( $status ),
			'updated_at' => current_time( 'mysql' ),
		];
		$format = [ '%s', '%s' ];

		if ( $source !== '' ) {
			$data['source'] = sanitize_key( $source );
			$format[]       = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->links_table(),
			$data,
			[
				'object_id'   => $object_id,
				'language_id' => $language_id,
				'type'        => $type->value,
			],
			$format,
			[ '%d', '%d', '%s' ]
		);

		if ( $result !== false ) {
			// Invalidate the type that was actually written. Flushing a type
			// the write never touched costs two pointless cache deletes AND
			// fires `perflocale/cache/flush_object` with that wrong type — a
			// payload CDN integrations listening on the hook would turn into a
			// spurious purge.
			$this->cache->flush_object( $object_id, $type->value );

			// The link's status is part of the eager link map, and
			// get_translations() reads that map first (Step 0). flush_object()
			// only clears the per-object cache, so without this a publish/
			// complete (empty → published) would leave the eager map stale and
			// the translation would stay missing from hreflang, the language
			// switcher, and resolution until an unrelated link CRUD rebuilt it.
			$this->invalidate_eager_link_map( $type );

			/** @hook perflocale/translation/status_changed Fires after a translation status changes. */
			do_action( 'perflocale/translation/status_changed', $object_id, $status, $language_id );
		}

		return $result !== false;
	}

	/**
	 * Remove all translation links for a given object ID.
	 *
	 * Called when a post or term is permanently deleted to prevent
	 * stale references in the translation group.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $type Object type ('post' or 'term').
	 * @return bool
	 */
	public function unlink_by_object_id( int $object_id, string $type = 'post' ): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Find the link first for group cache invalidation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$link = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT l.* FROM %i l
				INNER JOIN %i g ON l.group_id = g.id
				WHERE l.object_id = %d AND g.type = %s',
				$this->links_table(),
				$this->groups_table(),
				$object_id,
				$type
			)
		);

		if ( ! $link ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->links_table(),
			[
				'object_id' => $object_id,
				'group_id'  => (int) $link->group_id,
			],
			[ '%d', '%d' ]
		);

		if ( $result !== false ) {
			$this->invalidate_group_cache( (int) $link->group_id );

			// If that was the last link in the group, garbage-collect the
			// now-empty group. Otherwise it becomes a widow row - the kind
			// that accumulated historically before this fix. String-type
			// groups keep their own lifecycle; skip them.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$remaining = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE group_id = %d',
					$this->links_table(),
					(int) $link->group_id
				)
			);

			if ( $remaining === 0 && ( $link->type ?? $type ) !== 'string' ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->wpdb->delete(
					$this->groups_table(),
					[ 'id' => (int) $link->group_id ],
					[ '%d' ]
				);

				$this->delete_source_map_for_group( (int) $link->group_id );

				// A group was garbage-collected. Drop the per-request memo so
				// the next has_any_groups() re-checks, and — if NO groups
				// remain at all — clear the autoloaded fast-path flag so a
				// site that just removed its last translation returns to the
				// zero-overhead gate instead of pinning it "on" forever.
				// Self-healing: has_any_groups() re-queries + re-sets the flag
				// if a group reappears, so a racing create() can't strand a
				// false-negative.
				self::$has_any_groups_memo = null;

				// %i (WP 6.2+; plugin floor is 6.4) binds the table name as an
				// identifier placeholder instead of string interpolation — same
				// semantics (prepare() backtick-quotes and escapes it), but the
				// statement now contains no interpolated variable at all.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$any_left = (int) $this->wpdb->get_var(
					$this->wpdb->prepare( 'SELECT EXISTS( SELECT 1 FROM %i LIMIT 1 )', $this->groups_table() )
				);

				if ( 0 === $any_left && '1' === get_option( 'perflocale_has_any_groups', '' ) ) {
					update_option( 'perflocale_has_any_groups', '', true );
				}
			}
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $result !== false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find_all( array $args = [] ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT 100',
				$this->groups_table()
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $results ) ? $results : [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function insert( array $data ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->groups_table(),
			[ 'type' => sanitize_key( $data['type'] ?? 'post' ) ],
			[ '%s' ]
		);

		return $result !== false ? (int) $this->wpdb->insert_id : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( int $id, array $data ): bool {
		return false; // Groups are immutable except for their links.
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( int $id ): bool {
		// Delete all links first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete( $this->links_table(), [ 'group_id' => $id ], [ '%d' ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete( $this->groups_table(), [ 'id' => $id ], [ '%d' ] );

		$this->delete_source_map_for_group( $id );

		return $result !== false;
	}

	/**
	 * Cascade-delete the migration source-map row(s) pointing at a group that
	 * is being removed, so a `(type, key) -> group_id` mapping never outlives
	 * its group. A surviving orphan would make a later re-import resolve to a
	 * dead group_id and link content to a group that no longer exists.
	 *
	 * @param int $group_id translation_groups.id being deleted.
	 * @return void
	 */
	private function delete_source_map_for_group( int $group_id ): void {
		if ( $group_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete(
			Schema::table( 'migration_source_map' ),
			[ 'group_id' => $group_id ],
			[ '%d' ]
		);
	}

	/**
	 * Garbage-collect translation_groups rows whose links are all gone.
	 *
	 * Runtime cleanup is in place in unlink_by_object_id() and the
	 * language-cascade delete path, so newly-deleted posts/terms/languages
	 * never leak group rows. This static helper exists to mop up
	 * HISTORICAL orphans accumulated by pre-fix versions of the plugin
	 * (the original schema didn't have the cleanup at all), and as a
	 * defensive safety net for any future write path that bypasses the
	 * standard delete helpers. Called from the daily perflocale_jobs_gc
	 * cron handler.
	 *
	 * Skips 'string'-type groups because string translations use a
	 * different lifecycle (shared object_id across all language-links)
	 * and an empty 'string' group is a legitimate intermediate state
	 * during bulk-string-translate runs. The actual GC drops non-string
	 * groups that have zero rows in translation_links.
	 *
	 * @return int Number of orphan group rows removed.
	 */
	public static function gc_empty_groups(): int {
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return 0;
		}
		global $wpdb;

		// $groups_table + $links_table come from Schema::table() — that
		// helper returns "$wpdb->prefix . 'perflocale_' . <whitelist>",
		// no user input, no shell interpolation, no SQL injection vector.
		// Plugin Check's UnescapedDBParameter sniff can't trace the
		// helper's whitelisting through method dispatch, hence the
		// block-level suppression.
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );
		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );

		// MySQL doesn't allow LIMIT on multi-table DELETE, so split into a
		// SELECT (with batch cap) and a single-table DELETE. LEFT JOIN +
		// IS NULL is index-friendly here — the engine picks the (group_id)
		// index on translation_links and the PK on groups.

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT g.id FROM %i g
				LEFT JOIN %i l ON l.group_id = g.id
				WHERE l.id IS NULL AND g.type != 'string'
				LIMIT 1000",
				$groups_table,
				$links_table
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		// Build a placeholder list — every value is a class-trusted bigint
		// from the SELECT above (no user input). Use %d so prepare()
		// parameterises the IN-list properly.
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Replacements are a %i table name followed by an unpacked int list; WPCS cannot count an unpack.
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$placeholders})",
				$groups_table,
				...array_map( 'intval', $ids )
			)
		);

		// Cascade: drop migration source-map rows that pointed at the groups
		// just garbage-collected, so a later re-import can't resolve a dead
		// group_id. Same trusted bigint id-list as the DELETE above.
		$map_table = \PerfLocale\Database\Schema::table( 'migration_source_map' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE group_id IN ({$placeholders})",
				$map_table,
				...array_map( 'intval', $ids )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $removed;
	}

	/**
	 * Invalidate cache for all objects in a translation group.
	 *
	 * @param int $group_id Group ID.
	 * @return void
	 */
	public function invalidate_group_cache( int $group_id ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$links = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT l.object_id, g.type FROM %i l
				INNER JOIN %i g ON l.group_id = g.id
				WHERE l.group_id = %d',
				$this->links_table(),
				$this->groups_table(),
				$group_id
			)
		);

		if ( ! is_array( $links ) ) {
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return;
		}

		$dirty_types = [];

		foreach ( $links as $link ) {
			$this->cache->flush_object( (int) $link->object_id, $link->type );

			// Also clear the per-request find_for_object() cache.
			$find_key = self::find_cache_key( (string) $link->type, (int) $link->object_id );
			unset( self::$find_cache[ $find_key ] );

			$dirty_types[ (string) $link->type ] = true;
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Drop the autoloaded eager-link-map(s) for every type touched by
		// this group so the next request sees the freshly mutated graph
		// instead of a stale alloptions snapshot. We invalidate ALL known
		// types when the group's row count is zero (delete-then-flush
		// flow) since the SELECT above can't tell us what type WAS there.
		if ( $dirty_types === [] ) {
			$this->invalidate_eager_link_map();
		} else {
			foreach ( array_keys( $dirty_types ) as $type_value ) {
				unset( self::$eager_link_map_memo[ self::eager_memo_key( $type_value ) ] );
				delete_option( 'perflocale_eager_links_' . $type_value );
				// Same orphaned-cache purge as invalidate_eager_link_map():
				// delete_option() skips cache clearing when the row is gone.
				wp_cache_delete( 'perflocale_eager_links_' . $type_value, 'options' );
			}
		}
	}

	/**
	 * Clear the per-request find_for_object cache for a specific object.
	 *
	 * Called after creating or modifying groups/links to prevent stale
	 * null lookups from affecting subsequent find_for_object() calls.
	 *
	 * @param int        $object_id Object ID.
	 * @param ObjectType $type Object type.
	 * @return void
	 */
	public function invalidate_find_cache( int $object_id, ObjectType $type ): void {
		$find_key = self::find_cache_key( $type->value, $object_id );
		unset( self::$find_cache[ $find_key ] );
	}

	/**
	 * Reclaim widow translation groups. Two inert kinds:
	 *   - non-string groups with no links (a deleted object, or a merge import
	 *     that stranded the group when its links skipped on a unique key);
	 *   - string groups with no owning row in the strings table (a string
	 *     deleted without cascading its group leaves the group behind). Their
	 *     language links go with them: a link left pointing at a deleted group
	 *     holds the object_lang UNIQUE hostage and breaks the next
	 *     upsert_link() for that string.
	 *
	 * Canonical sweep shared by the merge importer and the upgrade self-heal so
	 * the cleanup lives in exactly one place. Returns the number removed.
	 *
	 * @return int
	 */
	public function sweep_orphan_groups(): int {
		$groups  = $this->groups_table();
		$links   = $this->links_table();
		$strings = Schema::table( 'strings' );

		// Both queries below interpolate table names that are produced by
		// Schema::table() (a class-controlled allowlist), not user input.
		// Plugin Check's per-line `phpcs:ignore` directive doesn't always
		// cover all violations within a multi-line $wpdb->query() call, so
		// we use the disable/enable block form which is more reliable.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		$widows = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE g FROM %i g
				LEFT JOIN %i l ON l.group_id = g.id
				WHERE l.id IS NULL AND g.type != 'string'",
				$groups,
				$links
			)
		);

		// Cascade the links FIRST. A string group can carry language links
		// (upsert_link() writes one per translated language), and deleting the
		// group without them leaves rows whose group_id names nothing — debris
		// that is not merely untidy: it occupies the object_lang UNIQUE
		// (type, object_id, language_id), so the next upsert_link() for that
		// string collides on it instead of on group_lang and the translation
		// ends up stored but not served. Type-scoped through the group join,
		// and guarded by the same NOT EXISTS as the group delete below so a
		// group still owned by a live string is never touched.
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE l FROM %i l
				INNER JOIN %i g ON g.id = l.group_id AND g.type = 'string'
				WHERE NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )",
				$links,
				$groups,
				$strings
			)
		);

		$orphan_strings = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE g FROM %i g
				WHERE g.type = 'string'
				AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )",
				$groups,
				$strings
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

		return max( 0, (int) $widows ) + max( 0, (int) $orphan_strings );
	}
}
