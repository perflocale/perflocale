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
 * Data access for translation groups and their links: a group joins every translation of one content, one link
 * per language. Statics and the eager map: docs-ai/11-code-rationale.md, "TranslationGroupRepository: static memos".
 */
final class TranslationGroupRepository implements RepositoryInterface {

	/**
	 * Per-request cache of find_for_object(), shared by all instances, dropped by invalidate_find_cache(); capped at
	 * FIND_CACHE_CAP, as long CLI runs walk every post.
	 *
	 * @var array<string, object|null>
	 */
	private static array $find_cache = [];

	/**
	 * How many times this process dropped cached group or link data after a change (link_changes()).
	 *
	 * @var int
	 */
	private static int $link_changes = 0;

	/**
	 * Soft cap of $find_cache; past it the oldest quarter is evicted (as UrlConverter::cap_cache()).
	 */
	private const FIND_CACHE_CAP = 5000;

	/**
	 * Per-request memo of has_any_groups(), null until resolved.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_any_groups_memo = null;

	/**
	 * Per-request eager link map by `{blog_id}:{type}`: object_id => the group's links, or the 'too_large' sentinel.
	 * Blog-keyed, or switch_to_blog() would carry one blog's map or sentinel into another.
	 *
	 * @var array<string, array|string>
	 */
	private static array $eager_link_map_memo = [];

	/**
	 * Per-request memo of is_string_group() by `{blog_id}:{group_id}`, true only (a missing id can become a string group
	 * later). Also cleared by reset_static_caches(): a Replace import re-inserts group ids that can carry another type.
	 *
	 * @var array<string, bool>
	 */
	private static array $is_string_group_memo = [];

	/**
	 * While true, get_eager_link_map() returns null (JOIN fallback) and nothing is rebuilt: bulk writers set it around
	 * their loop.
	 *
	 * @var bool
	 */
	private static bool $eager_link_map_suspended = false;

	/**
	 * Seconds after this process deleted (or deferred deleting) a blog's cached all-language status selections during
	 * which another status change does not delete them again (forget_status_selectors()).
	 */
	private const STATUS_SELECTORS_FORGET_SECONDS = 2.0;

	/**
	 * When this process last deleted or deferred a blog's status selections (microtime), by blog id, so a bulk status
	 * change deletes them once per window. Not in reset_static_caches(): same-blog switches on every save would undo it.
	 *
	 * @var array<int, float>
	 */
	private static array $status_selectors_forgotten_at = [];

	/**
	 * Blogs whose status selections a change inside a registered transaction asked to delete (blog id => default
	 * language id), deleted by release_status_selectors() after the transaction; not in reset_static_caches().
	 *
	 * @var array<int, int>
	 */
	private static array $status_selectors_pending = [];

	/**
	 * Suspend eager map reads and rebuilds during a bulk write; resume in a finally.
	 *
	 * @return void
	 */
	public static function suspend_eager_link_map(): void {
		self::$eager_link_map_suspended = true;
	}

	/**
	 * Resume the eager map after a bulk write; the caller invalidates once afterwards.
	 *
	 * @return void
	 */
	public static function resume_eager_link_map(): void {
		self::$eager_link_map_suspended = false;
	}

	/**
	 * Most link rows in the autoloaded eager map (about 250 bytes each); filter `perflocale/cache/eager_map_row_cap`.
	 * Sites with a persistent object cache get the same speed from prime_translations() at any size.
	 */
	private const EAGER_LINK_MAP_ROW_CAP = 2000;

	/**
	 * Serialized size cap of the eager map, for unusually wide rows; filter `perflocale/cache/eager_map_byte_cap`.
	 */
	private const EAGER_LINK_MAP_BYTE_CAP = 750 * 1024;

	/**
	 * Stored when a successful count proved the blog has no links of the type. A stored `[]` means unproven (a failed
	 * build may have stored it) and is rebuilt once. Docs: docs-ai/11-code-rationale.md, "TranslationGroupRepository:
	 * the eager link map".
	 */
	private const EAGER_MAP_EMPTY = 'empty';

	/**
	 * Whether a transaction is active: a static flag, as @@in_transaction is not on every MySQL or MariaDB version.
	 *
	 * @var bool
	 */
	private static bool $in_transaction = false;

	/**
	 * Register or clear a transaction the caller opened itself, so create_group() and link_object() nest in it instead of
	 * a second START TRANSACTION (an implicit COMMIT). Clear it on every exit (try/finally); an owner whose transaction
	 * can change a post status calls release_status_selectors() after it.
	 *
	 * @param bool $active Whether an external transaction is active.
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
	 * The group create_group() is linking its first object into: it holds no link yet, so link_object() reads no member.
	 *
	 * @var int
	 */
	private int $new_group_id = 0;

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
	 * The groups table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function groups_table(): string {
		return Schema::table( 'translation_groups' );
	}

	/**
	 * The links table name for the current blog (as groups_table()).
	 *
	 * @return string
	 */
	private function links_table(): string {
		return Schema::table( 'translation_links' );
	}

	/**
	 * Seed L1 for every sibling in this link list: they share it, so hreflang, the switcher and cross-language
	 * permalinks hit L1.
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
	 * The blog-prefixed key of $find_cache (shared by every blog of a network).
	 *
	 * @param string $type Object type value.
	 * @param int    $object_id Object ID.
	 * @return string
	 */
	private static function find_cache_key( string $type, int $object_id ): string {
		return get_current_blog_id() . ":group_{$type}_{$object_id}";
	}

	/**
	 * The blog-prefixed key of $eager_link_map_memo, as find_cache_key().
	 *
	 * @param string $type Object type value.
	 * @return string
	 */
	private static function eager_memo_key( string $type ): string {
		return get_current_blog_id() . ':' . $type;
	}

	/**
	 * Clear the request statics: on `switch_blog` (Bootstrap), after a language rename or delete (LanguageRepository)
	 * and after a data import (MigrationCacheHelper), which can put another type behind a known group id.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		++self::$link_changes;
		self::$find_cache           = [];
		self::$has_any_groups_memo  = null;
		self::$eager_link_map_memo  = [];
		self::$is_string_group_memo = [];
	}

	/**
	 * How many times this process dropped cached group or link data after a change (an invalidation or a static reset);
	 * memos built from group membership compare it to their build value.
	 *
	 * @return int
	 */
	public static function link_changes(): int {
		return self::$link_changes;
	}

	/**
	 * Drop this blog's per-process find_for_object() answers of one type, for a caller that must build from data read
	 * after a moment (InventorySync's stored copy maps).
	 *
	 * @param ObjectType $type Object type.
	 * @return void
	 */
	public static function forget_found_groups( ObjectType $type ): void {
		$prefix = get_current_blog_id() . ':group_' . $type->value . '_';

		foreach ( array_keys( self::$find_cache ) as $key ) {
			if ( str_starts_with( (string) $key, $prefix ) ) {
				unset( self::$find_cache[ $key ] );
			}
		}
	}

	/**
	 * A group by id.
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
	 * An object's own link row (group plus language), which find_for_object()'s `g.*` cannot give: the importers need it
	 * to re-language objects WordPress filed under the default. Read through the cached get_translations().
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
	 * The object holding a language in a group, uncached (importers read their own writes); one group_lang lookup.
	 *
	 * @param int $group_id    Group ID.
	 * @param int $language_id Language ID.
	 * @return int Object ID, or 0 when no object holds that language.
	 */
	public function object_in_group_language( int $group_id, int $language_id ): int {
		// Uncached on purpose: the importer reads its own writes.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$object_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT object_id FROM %i WHERE group_id = %d AND language_id = %d LIMIT 1',
				$this->links_table(),
				$group_id,
				$language_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $object_id;
	}

	/**
	 * The groups of many objects in one query, kept where find_for_object() looks first (known ids skipped); false and
	 * nothing kept when the query fails.
	 *
	 * @param array<int, int> $object_ids Object ids.
	 * @param ObjectType      $type       Object type.
	 * @return bool
	 */
	public function prime_find_for_objects( array $object_ids, ObjectType $type ): bool {
		$missing = [];

		foreach ( $object_ids as $object_id ) {
			$object_id = (int) $object_id;

			if ( $object_id > 0 && ! array_key_exists( self::find_cache_key( $type->value, $object_id ), self::$find_cache ) ) {
				$missing[ $object_id ] = true;
			}
		}

		if ( [] === $missing ) {
			return true;
		}

		$found = [];

		foreach ( array_chunk( array_keys( $missing ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge(), which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT l.object_id AS pfl_object_id, g.* FROM %i g
					INNER JOIN %i l ON g.id = l.group_id
					WHERE g.type = %s AND l.object_id IN ($placeholders)",
					array_merge( [ $this->groups_table(), $this->links_table(), $type->value ], $chunk )
				)
			);
			// phpcs:enable

			if ( '' !== (string) $this->wpdb->last_error || ! is_array( $rows ) ) {
				return false;
			}

			foreach ( $rows as $row ) {
				if ( ! isset( $row->pfl_object_id ) || ! is_numeric( $row->pfl_object_id ) ) {
					continue;
				}

				$object_id = (int) $row->pfl_object_id;

				if ( ! array_key_exists( $object_id, $found ) ) {
					unset( $row->pfl_object_id );
					$found[ $object_id ] = $row;
				}
			}
		}

		foreach ( array_keys( $missing ) as $object_id ) {
			if ( count( self::$find_cache ) >= self::FIND_CACHE_CAP ) {
				$evict            = (int) ( self::FIND_CACHE_CAP / 4 );
				self::$find_cache = array_slice( self::$find_cache, $evict, null, true );
			}

			self::$find_cache[ self::find_cache_key( $type->value, $object_id ) ] = $found[ $object_id ] ?? null;
		}

		return true;
	}

	/**
	 * The translation group of an object.
	 *
	 * @param int        $object_id Object ID (post ID, term ID, etc.).
	 * @param ObjectType $type Object type.
	 * @return object|null Group record or null.
	 */
	public function find_for_object( int $object_id, ObjectType $type ): ?object {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// A per-request static, not a transient (which turns null into ''), blog-prefixed.
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

		// At the cap drop the oldest quarter (insertion order, so FIFO).
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
	 * Prime the translation caches of many objects in one query instead of two transient reads each; objects without a
	 * group get an empty answer cached. Idempotent. Docs: docs-ai/11-code-rationale.md, "TranslationGroupRepository:
	 * priming translations".
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

		// No groups at all: seed empty answers in L1 (has_any_groups() caches its true answer, so real sites go on).
		if ( ! $this->has_any_groups() ) {
			foreach ( $object_ids as $id ) {
				$key = "translations_{$type->value}_{$id}";

				if ( $this->cache->get_static( $key, 'perflocale_trans' ) === null ) {
					$this->cache->set_static( $key, [], 'perflocale_trans' );
				}
			}

			return;
		}

		// Step 0: the eager map (one autoloaded option) when the site is under its caps; then only copy into L1.
		$eager_map = $this->get_eager_link_map( $type );

		if ( is_array( $eager_map ) ) {
			foreach ( $object_ids as $id ) {
				$key = "translations_{$type->value}_{$id}";

				if ( $this->cache->get_static( $key, 'perflocale_trans' ) !== null ) {
					continue;
				}

				$links = $eager_map[ $id ] ?? [];
				$this->cache->set_static( $key, $links, 'perflocale_trans' );

				// Siblings share the link list: seed each.
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

		// Step 1: drop what L1 or L2 already holds; L2 hits move to L1, read with wp_cache_get_multiple() (one MGET).
		$after_l1  = [];
		$check_l2  = $this->cache->l2_enabled();
		$l2_needed = []; // cache_key => id.
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
				// CacheManager's generation-prefixed key: a bare key misses every entry CacheManager::get() wrote.
				$value = wp_cache_get( CacheManager::l2_key( $key, 'perflocale_trans' ), 'perflocale_trans', false, $found );

				if ( $found ) {
					$this->cache->set_static( $key, $value, 'perflocale_trans' );
					$this->cascade_siblings_to_l1( $value, $type, $id );
					continue;
				}
			}

			$after_l1[] = $id;
		}

		// One MGET with generation-prefixed keys, mapped back to the logical keys.
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

		// Step 2: the remaining L3 transients in one SELECT IN, no rewrite of fresh ones; skipped under an external object
		// cache (no transients in wp_options).
		$stale_timeout_keys = [];

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
				// No timeout row is non-expiring, as set_many() writes them; only a timeout in the past is expired.
				$not_expired = ! isset( $existing_expires[ $transient_id ] ) || $existing_expires[ $transient_id ] >= $now;

				if ( $has_value && $not_expired ) {
					// Fresh: L1 only, no writes.
					$envelope = $existing_values[ $transient_id ];
					$value    = is_array( $envelope ) && array_key_exists( 'v', $envelope ) ? $envelope['v'] : $envelope;

					$this->cache->set_static( $cache_key, $value, 'perflocale_trans' );

					// Siblings share this group's list: seed L1 for each.
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

				// A value with a past timeout twin is recomputed; the twin is deleted after the write (set_many() writes none), or
				// every request recomputes it. Kept when Step 3's read fails.
				if ( $has_value ) {
					$stale_timeout_keys[] = $cache_key;
				}

				$missing[] = $id;
			}
		}

		if ( $missing === [] ) {
			return;
		}

		// Step 3: one JOIN for the ids still unresolved.
		$lang_table = Schema::table( 'languages' );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		// Args in the SELECT's placeholder order: links %i, groups %i, %s type, links %i, languages %i, then the %d list.
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

		// Read before any other statement (wpdb resets last_error). A failed read writes every key as "no translations",
		// so Step 2's expired timeout rows are kept and those keys are recomputed next request.
		$read_failed = '' !== (string) $this->wpdb->last_error || ! is_array( $rows );

		// Link rows by source: every member of a group gets the same list, so it is cached under every member's key.
		$grouped_by_source = array_fill_keys( $missing, [] );
		$all_object_ids    = [];

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$sid = (int) ( $row->source_id ?? 0 );

				if ( $sid === 0 ) {
					continue;
				}

				// Drop source_id, a query artefact.
				$clean = clone $row;
				unset( $clean->source_id );

				$grouped_by_source[ $sid ][] = $clean;

				if ( isset( $clean->object_id ) ) {
					$all_object_ids[ (int) $clean->object_id ] = true;
				}
			}
		}

		// One set_many() for the whole write set (own and sibling entries): multi-row INSERTs, split at its 500 rows /
		// 256 KB statement ceiling, within the 1 MB max_allowed_packet floor.
		$to_write = [];

		foreach ( $grouped_by_source as $sid => $links ) {
			$to_write[ "translations_{$type->value}_{$sid}" ] = $links;
		}

		// Siblings not requested get their group's list under their own key (sibling_cascade()).
		$to_write += self::sibling_cascade( $grouped_by_source, $all_object_ids, $type->value );

		if ( $to_write !== [] ) {
			$this->cache->set_many( $to_write, HOUR_IN_SECONDS, 'perflocale_trans', $read_failed ? [] : $stale_timeout_keys );
		}

		// A group was resolved: pin true everywhere.
		self::$has_any_groups_memo = true;
		$this->cache->set_static( 'has_any_groups', 1, 'perflocale_trans' );

		if ( $this->cache->l2_enabled() ) {
			wp_cache_set( 'has_any_groups', 1, 'perflocale_trans', 0 );
		}

		// Across requests through the autoloaded option.
		if ( get_option( 'perflocale_has_any_groups', '' ) !== '1' ) {
			update_option( 'perflocale_has_any_groups', '1', true );
		}
	}

	/**
	 * Cache entries for the siblings in fetched link lists: each object in $all_object_ids not itself requested gets the
	 * first list linking it, as "translations_{type}_{id}"; an object-id index keeps it linear in the links.
	 *
	 * @internal Used by prime_translations(); public for its unit test.
	 *
	 * @param array<int, list<\stdClass>> $grouped_by_source Link lists by requested object ID.
	 * @param array<int, mixed>            $all_object_ids    Every object ID in those lists, as keys.
	 * @param string                       $type_value        Object type value (post or term).
	 * @return array<non-falsy-string, list<\stdClass>> Cache key => link list.
	 */
	public static function sibling_cascade( array $grouped_by_source, array $all_object_ids, string $type_value ): array {
		$list_of = [];

		foreach ( $grouped_by_source as $source_links ) {
			foreach ( $source_links as $link ) {
				$object_id = (int) ( $link->object_id ?? 0 );

				if ( ! isset( $list_of[ $object_id ] ) ) {
					$list_of[ $object_id ] = $source_links;
				}
			}
		}

		$entries = [];

		foreach ( $all_object_ids as $sibling_id => $_ ) {
			if ( ! isset( $grouped_by_source[ $sibling_id ] ) && isset( $list_of[ $sibling_id ] ) ) {
				$entries[ "translations_{$type_value}_{$sibling_id}" ] = $list_of[ $sibling_id ];
			}
		}

		return $entries;
	}

	/**
	 * Whether any translation group exists on this blog, so cold-path queries are skipped on fresh and single-language
	 * sites. Only true is cached; false is checked again next request.
	 *
	 * @return bool
	 */
	/**
	 * Whether a group id exists and is a string group: strings.group_id is an unenforced FK, and a dangling id matching a
	 * post or term group would let a string write repoint that group's link. Memoised per request and blog.
	 *
	 * @param int $group_id Group id to test.
	 * @return bool True when the group exists and its type is 'string'.
	 */
	public function is_string_group( int $group_id ): bool {
		if ( $group_id <= 0 ) {
			return false;
		}

		// Blog-prefixed (another blog's type after switch_to_blog() otherwise), on a class static so reset_static_caches()
		// reaches it (Replace imports).
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

		if ( 'string' !== $type ) {
			return false;
		}

		self::$is_string_group_memo[ $memo_key ] = true;

		return true;
	}

	/**
	 * Record a group the caller just created as a string group, so the type check needs no read for it.
	 *
	 * @param int $group_id Id of the string group the caller inserted.
	 * @return void
	 */
	public static function remember_string_group( int $group_id ): void {
		if ( $group_id > 0 ) {
			self::$is_string_group_memo[ get_current_blog_id() . ':' . $group_id ] = true;
		}
	}

	/**
	 * Give one string a new string-type group and point the string at it ({@see StringGroupAssigner::assign()}).
	 *
	 * @param int  $string_id      Row in the `strings` table.
	 * @param bool $in_transaction True inside the caller's transaction: nothing follows a failed statement.
	 * @return int The new group id, or 0 when the string could not be moved.
	 */
	public function assign_new_string_group( int $string_id, bool $in_transaction = false ): int {
		return StringGroupAssigner::assign( $this->wpdb, $string_id, $in_transaction || self::$in_transaction );
	}

	/**
	 * Which of these group ids are string groups, in one read; ids known this request are not read again.
	 *
	 * @param int[] $group_ids Group ids.
	 * @return array<int, true> group_id => true for each string group.
	 */
	private function string_group_ids( array $group_ids ): array {
		$out     = [];
		$blog_id = get_current_blog_id();
		$unknown = [];

		foreach ( $group_ids as $group_id ) {
			if ( isset( self::$is_string_group_memo[ $blog_id . ':' . $group_id ] ) ) {
				$out[ $group_id ] = true;
			} elseif ( $group_id > 0 ) {
				$unknown[] = $group_id;
			}
		}

		if ( $unknown === [] ) {
			return $out;
		}

		$placeholders = implode( ',', array_fill( 0, count( $unknown ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is a generated %d list bound to $unknown.
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM %i WHERE type = 'string' AND id IN ({$placeholders})",
				array_merge( [ $this->groups_table() ], $unknown )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( (array) $ids as $id ) {
			$out[ (int) $id ] = true;
		}

		return $out;
	}

	public function has_any_groups(): bool {
		if ( self::$has_any_groups_memo !== null ) {
			return self::$has_any_groups_memo;
		}

		// L0: the autoloaded option, the only cross-request home of true without Redis (read with alloptions).
		if ( get_option( 'perflocale_has_any_groups', '' ) === '1' ) {
			self::$has_any_groups_memo = true;
			return self::$has_any_groups_memo;
		}

		// L1: survives reset_static() cycles within the request.
		$l1 = $this->cache->get_static( 'has_any_groups', 'perflocale_trans' );

		if ( $l1 === 1 || $l1 === true ) {
			self::$has_any_groups_memo = true;
			return self::$has_any_groups_memo;
		}

		// L2: a true pinned by an earlier request on persistent caches.
		if ( $this->cache->l2_enabled() ) {
			$found = false;
			$value = wp_cache_get( 'has_any_groups', 'perflocale_trans', false, $found );

			if ( $found && (bool) $value ) {
				$this->cache->set_static( 'has_any_groups', 1, 'perflocale_trans' );
				self::$has_any_groups_memo = true;
				return self::$has_any_groups_memo;
			}
		}

		// A presence check with LIMIT 1; the table name comes from Schema::table().
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

			// Persisted in the autoloaded option, so later requests skip the query.
			update_option( 'perflocale_has_any_groups', '1', true );
		}

		self::$has_any_groups_memo = $exists;
		return self::$has_any_groups_memo;
	}

	/**
	 * Pin every layer of has_any_groups() to true after create_group() commits, so a later check in the same request
	 * (CacheInvalidator's gate) sees the new group. Idempotent.
	 *
	 * @return void
	 */
	private function mark_has_any_groups(): void {
		self::$has_any_groups_memo = true;
		$this->cache->set_static( 'has_any_groups', 1, 'perflocale_trans' );

		if ( $this->cache->l2_enabled() ) {
			wp_cache_set( 'has_any_groups', 1, 'perflocale_trans', 0 );
		}

		// L0: update_option() is a no-op when already '1'.
		update_option( 'perflocale_has_any_groups', '1', true );
	}

	/**
	 * The eager map (object_id => the group's links) of every link of this type on the blog: one alloptions read in
	 * place of prime_translations()'s SELECT IN. Memoised per request, persisted in `perflocale_eager_links_{type}`,
	 * invalidated on every write; null over the caps or with no links (callers use the JOIN path).
	 *
	 * @param ObjectType $type
	 * @return array<int, list<object>>|null
	 */
	public function get_eager_link_map( ObjectType $type ): ?array {
		// Suspended during a bulk write: each created link invalidates the map, and rebuilding per row is O(rows x map).
		if ( self::$eager_link_map_suspended ) {
			return null;
		}

		// The memo key is blog-prefixed; the option is per blog by its table.
		$memo_key   = self::eager_memo_key( $type->value );
		$option_key = 'perflocale_eager_links_' . $type->value;

		if ( array_key_exists( $memo_key, self::$eager_link_map_memo ) ) {
			$cached = self::$eager_link_map_memo[ $memo_key ];
			// `!== []` keeps the memo and the option path answering alike (a memoised [] read as "no translations").
			return is_array( $cached ) && $cached !== [] ? $cached : null;
		}

		CacheManager::before_autoloaded_read( $option_key );

		$stored = get_option( $option_key, false );

		if ( $stored === 'too_large' ) {
			self::$eager_link_map_memo[ $memo_key ] = 'too_large';
			return null;
		}

		if ( $stored === self::EAGER_MAP_EMPTY ) {
			// Proven empty by a COUNT of zero: null for callers, the sentinel memoised so the build is not repeated.
			self::$eager_link_map_memo[ $memo_key ] = self::EAGER_MAP_EMPTY;
			return null;
		}

		if ( is_array( $stored ) && $stored !== [] ) {
			self::$eager_link_map_memo[ $memo_key ] = $stored;
			return $stored;
		}

		// A stored [] (an older shape a failed build could write) is not trusted: rebuild once, persisting the map or the
		// sentinel, one COUNT per blog and type.

		// Filterable caps (defaults EAGER_LINK_MAP_ROW_CAP rows, EAGER_LINK_MAP_BYTE_CAP bytes): autoload size against
		// prime_translations() queries.
		$row_cap  = (int) apply_filters( 'perflocale/cache/eager_map_row_cap', self::EAGER_LINK_MAP_ROW_CAP, $type );
		$byte_cap = (int) apply_filters( 'perflocale/cache/eager_map_byte_cap', self::EAGER_LINK_MAP_BYTE_CAP, $type );

		// Cold build: count at most $row_cap + 1 rows, enough to tell 0, within the cap, and over it.
		$count_limit = $row_cap < PHP_INT_MAX ? max( 0, $row_cap ) + 1 : PHP_INT_MAX;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		$count_raw = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM (
					SELECT 1 FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					WHERE g.type = %s
					LIMIT %d
				) bounded_links',
				$this->links_table(),
				$this->groups_table(),
				$type->value,
				$count_limit
			)
		);

		// get_var() answers NULL on failure and (int) null is 0: on an error persist and memoise nothing (an empty map
		// from a deadlock would read as "no translations"), as LanguageRepository::get_bootstrap().
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

		// A failed SELECT also gives []: check the error first and persist nothing on failure.
		if ( ! is_array( $rows ) || $this->wpdb->last_error !== '' ) {
			return null;
		}

		if ( $rows === [] ) {
			self::persist_eager_map( $option_key, self::EAGER_MAP_EMPTY );
			self::$eager_link_map_memo[ $memo_key ] = self::EAGER_MAP_EMPTY;
			return null;
		}

		// By group, so every member sees the full sibling list.
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

		// Size guard on the serialized payload.
		$serialised = maybe_serialize( $map );

		if ( strlen( (string) $serialised ) > $byte_cap ) {
			self::persist_eager_map( $option_key, 'too_large' );
			self::$eager_link_map_memo[ $memo_key ] = 'too_large';
			return null;
		}

		if ( $map === [] ) {
			// Rows without a usable object_id: empty. The sentinel, never [] (not trusted, so it would rebuild every request).
			self::persist_eager_map( $option_key, self::EAGER_MAP_EMPTY );
			self::$eager_link_map_memo[ $memo_key ] = self::EAGER_MAP_EMPTY;

			return null;
		}

		self::persist_eager_map( $option_key, $map );
		self::$eager_link_map_memo[ $memo_key ] = $map;

		return $map;
	}

	/**
	 * Persist one eager-link-map option and take its name out of `notoptions`: add_option() returns
	 * before it does that when its INSERT finds the row, and a listed name means a cold rebuild per request.
	 *
	 * @param string       $option_key Autoloaded option name.
	 * @param array|string $value      Map, the {@see EAGER_MAP_EMPTY} sentinel,
	 *                                 or the `'too_large'` sentinel. A bare `[]`
	 *                                 is never written - see EAGER_MAP_EMPTY.
	 * @return void
	 */
	private static function persist_eager_map( string $option_key, array|string $value ): void {
		update_option( $option_key, $value, true );
		$listed = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $listed ) && isset( $listed[ $option_key ] ) ) {
			\PerfLocale\Database\NotOptions::unlist( [ $option_key ] );
		}
	}

	/**
	 * Invalidate the eager link map; every write to groups or links calls it.
	 *
	 * @param ObjectType|null $type Restrict to one type, or null for all.
	 * @return void
	 */
	public function invalidate_eager_link_map( ?ObjectType $type = null ): void {
		++self::$link_changes;

		$types = $type ? [ $type->value ] : [ 'post', 'term', 'option', 'string', 'post_type', 'taxonomy' ];
		$names = [];

		foreach ( $types as $t ) {
			unset( self::$eager_link_map_memo[ self::eager_memo_key( $t ) ] );
			$names[] = 'perflocale_eager_links_' . $t;
		}

		// Also from the cached alloptions list, or a map without its row (rollback, concurrent store) is served until the
		// object cache is flushed (CacheManager::delete_autoloaded_options()).
		CacheManager::delete_autoloaded_options( $names );

		// A post link change moves posts between language buckets: bump the generation of PostQueryFilter's cached archive
		// counts. Every post link write passes here.
		if ( $type === null || $type === ObjectType::Post ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( 'perflocale_found_rows' );
		}
	}

	/**
	 * CacheManager's transient key, so prime_translations() reads the option row directly and skips set_transient()'s
	 * timeout rewrite.
	 *
	 * @param string $key Cache key.
	 * @return string Transient option base (without the `_transient_` prefix).
	 */
	private function transient_option_name( string $key ): string {
		// CacheManager derives it, so the two cannot drift.
		return $this->cache->derive_transient_key( $key, 'perflocale_trans' );
	}

	/**
	 * Translations of many source objects in at most two queries (instead of get_translations() per row): object id =>
	 * link rows as get_translations() returns them, [] when the object has no link.
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

		// Step 1: each object's group_id.
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

		// Unique group ids, and object => group.
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

		// Step 2: every link of those groups with its language; the %d list is built at run time (hence the disables).
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

		// Links by group_id.
		$links_by_group = [];
		foreach ( $links as $link ) {
			$links_by_group[ (int) $link->group_id ][] = $link;
		}

		// Each object gets its group's links.
		foreach ( $obj_to_group as $oid => $gid ) {
			$out[ $oid ] = $links_by_group[ $gid ] ?? [];
		}

		return $out;
	}

	/**
	 * Source object ids whose group has a link in the language (optionally at an effective status), or with 'empty'
	 * none, so the Translations screen filters in SQL and pagination counts the filtered set.
	 * Docs: docs-ai/11-code-rationale.md, "TranslationGroupRepository: status selections".
	 *
	 * @param ObjectType $type Object type (e.g. Post).
	 * @param int        $language_id Target language ID.
	 * @param string     $status Optional status filter ('' = any, 'empty'
	 *     = missing the language entirely).
	 * @param int        $source_language_id Default language ID (0 = none): binds
	 *     the returned row to the group's default-language member.
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

		// The outer scan to the one row per group that can survive the caller's filter.
		$src_sql  = $source_language_id > 0 ? ' AND l.language_id = %d' : '';
		$src_args = $source_language_id > 0 ? [ $source_language_id ] : [];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		if ( $status === 'empty' ) {
			// Groups without a link in the target language.
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

		// Groups with a link in the target language (at the status, if given); the outer filter is any member of the group.
		if ( $status !== '' ) {
			// t is the group's target link (group_lang), p its post (primary key): one effective status per group. Typed by
			// groups.type (old rows have an empty links.type).
			[ $post_join, $post_args, $status_match ] = $this->status_match_parts( $type, $status );

			$rows = $this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT l.object_id
					FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					INNER JOIN %i t ON t.group_id = l.group_id AND t.language_id = %d'
					. $post_join . '
					WHERE g.type = %s' . $src_sql . '
					AND ' . $status_match,
					array_merge(
						[ $this->links_table(), $this->groups_table(), $this->links_table(), $language_id ],
						$post_args,
						[ $type->value ],
						$src_args,
						[ $status ]
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
	 * Source object ids whose group has any translation at the effective status, in any language; with a default
	 * language only the other members count (the row's own status is the source's).
	 *
	 * @param ObjectType $type Object type.
	 * @param string     $status Status value (non-empty, non-'empty').
	 * @param int        $source_language_id Default language ID (0 = none).
	 * @return array<int, int>
	 */
	public function find_source_object_ids_by_status_any_language(
		ObjectType $type,
		string $status,
		int $source_language_id = 0
	): array {
		// The outer scan to the one row per group that can survive the source-only filter.
		$src_sql  = $source_language_id > 0 ? ' AND l.language_id = %d' : '';
		$src_args = $source_language_id > 0 ? [ $source_language_id ] : [];
		if ( $status === '' || $status === 'empty' ) {
			return [];
		}

		// Cached briefly: the effective status reads every translation's post. Status changes delete it (at most every two
		// seconds per blog and process); link writes do not, so a link change can take a minute (matches_filters() re-checks).
		$cache_key = self::status_selector_key( $type, $status, $source_language_id );

		// This process holds a selection again: a later status change must delete it.
		unset( self::$status_selectors_forgotten_at[ get_current_blog_id() ] );

		return (array) $this->cache->get(
			$cache_key,
			function () use ( $type, $status, $source_language_id, $src_sql, $src_args ): array {
				// t walks the other members (the source excluded), p each member's post.
				$exclude_sql = $source_language_id > 0 ? ' AND t.language_id <> l.language_id' : '';

				[ $post_join, $post_args, $status_match ] = $this->status_match_parts( $type, $status );

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
				$rows = $this->wpdb->get_col(
					$this->wpdb->prepare(
						'SELECT DISTINCT l.object_id
						FROM %i l
						INNER JOIN %i g ON l.group_id = g.id
						INNER JOIN %i t ON t.group_id = l.group_id' . $exclude_sql
						. $post_join . '
						WHERE g.type = %s' . $src_sql . '
						AND ' . $status_match,
						array_merge(
							[ $this->links_table(), $this->groups_table(), $this->links_table() ],
							$post_args,
							[ $type->value ],
							$src_args,
							[ $status ]
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

	/**
	 * The cache key of one all-language status selection.
	 *
	 * @param ObjectType $type               Object type.
	 * @param string     $status             Status filter value.
	 * @param int        $source_language_id Default language ID (0 = none).
	 * @return string
	 */
	private static function status_selector_key( ObjectType $type, string $status, int $source_language_id ): string {
		return sprintf( 'status_any_lang_%s_%s_%d', $type->value, md5( $status ), $source_language_id );
	}

	/**
	 * `transition_post_status`: a translatable post's status change deletes (or, in a registered transaction, defers)
	 * the cached status selections. Registered on write requests only; an unchanged status, a non-translatable type and
	 * a new auto-draft do nothing.
	 *
	 * @param string        $new_status New post status.
	 * @param string        $old_status Previous post status.
	 * @param \WP_Post|null $post       The post.
	 * @return void
	 */
	public static function on_post_status_transition( $new_status, $old_status, $post = null ): void {
		if ( ! $post instanceof \WP_Post || $new_status === $old_status ) {
			return;
		}

		if ( 'new' === $old_status && 'auto-draft' === $new_status ) {
			return;
		}

		try {
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'group_repo' ) || ! $plugin->has( 'lang_repo' ) ) {
				return;
			}

			if ( ! in_array( $post->post_type, $plugin->settings()->get_translatable_post_types(), true ) ) {
				return;
			}

			$default = $plugin->lang_repo()->get_default();

			$plugin->group_repo()->forget_status_selectors( ( $default && ! empty( $default->id ) ) ? (int) $default->id : 0 );
		} catch ( \Throwable $e ) {
			// A missing container or table never blocks a status change.
			unset( $e );
		}
	}

	/**
	 * Delete (or in a registered transaction defer to release_status_selectors()) one default language's cached status
	 * selections of post groups, every status the screen offers, from every layer; skipped within
	 * STATUS_SELECTORS_FORGET_SECONDS of the last one unless a selection was read since.
	 *
	 * @param int $source_language_id Default language ID (0 = none), as the screen passes it.
	 * @return void
	 */
	public function forget_status_selectors( int $source_language_id ): void {
		$blog_id = get_current_blog_id();

		if ( isset( self::$status_selectors_forgotten_at[ $blog_id ] )
			&& microtime( true ) - self::$status_selectors_forgotten_at[ $blog_id ] < self::STATUS_SELECTORS_FORGET_SECONDS
		) {
			return;
		}

		if ( self::$in_transaction ) {
			self::$status_selectors_forgotten_at[ $blog_id ] = microtime( true );
			self::$status_selectors_pending[ $blog_id ]      = $source_language_id;

			return;
		}

		unset( self::$status_selectors_pending[ $blog_id ] );

		$this->delete_status_selectors( $source_language_id );

		self::$status_selectors_forgotten_at[ $blog_id ] = microtime( true );
	}

	/**
	 * Delete the selections deferred during a registered transaction, on every blog with a pending entry (a callback
	 * inside can switch blogs); nothing while a transaction is registered. Call it after a COMMIT or ROLLBACK of a
	 * transaction registered with set_in_transaction( true ) that can change a post status.
	 *
	 * @internal Called by the TranslatePress importer around its batch transactions.
	 *
	 * @return void
	 */
	public function release_status_selectors(): void {
		if ( self::$in_transaction || [] === self::$status_selectors_pending ) {
			return;
		}

		$current_blog_id = get_current_blog_id();

		foreach ( self::$status_selectors_pending as $blog_id => $source_language_id ) {
			unset( self::$status_selectors_pending[ $blog_id ] );

			if ( $blog_id === $current_blog_id ) {
				$this->delete_status_selectors( $source_language_id );

				continue;
			}

			switch_to_blog( $blog_id );

			try {
				$this->delete_status_selectors( $source_language_id );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Delete one default language's cached status selections of post groups from every layer.
	 *
	 * @param int $source_language_id Default language ID (0 = none).
	 * @return void
	 */
	private function delete_status_selectors( int $source_language_id ): void {
		$keys = [];

		foreach ( \PerfLocale\Enum\TranslationStatus::cases() as $status ) {
			if ( \PerfLocale\Enum\TranslationStatus::Empty !== $status ) {
				$keys[] = self::status_selector_key( ObjectType::Post, $status->value, $source_language_id );
			}
		}

		$this->cache->delete_many( $keys, 'perflocale_trans' );
	}

	/**
	 * Join and status predicate of member `t` for the selectors: post links compare the effective status of their post
	 * `p`, others the stored one; needs_update also binds the stored column, so only flagged links' posts are read.
	 *
	 * @param ObjectType $type   Object type.
	 * @param string     $status Status filter value.
	 * @return array{0: literal-string, 1: array<int, string>, 2: literal-string} Join SQL
	 *     (with a %i placeholder), its replacement values, and the predicate (one %s: the status).
	 */
	private function status_match_parts( ObjectType $type, string $status ): array {
		if ( $type !== ObjectType::Post ) {
			return [ '', [], 't.status = %s' ];
		}

		return [
			' LEFT JOIN %i p ON p.ID = t.object_id',
			[ $this->wpdb->posts ],
			TranslationLinkRepository::effective_status_sql( 't', 'p' ) . ' = %s'
				. ( $status === 'needs_update' ? " AND t.status = 'needs_update'" : '' ),
		];
	}

	/**
	 * Source object ids whose group lacks a link in at least one of the languages ("empty" with all languages).
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

		// Cached 60 s: the slowest admin query here on large sites (every page click ran it); link writes invalidate it.
		sort( $language_ids );
		// The default language changes the result: part of the key.
		$cache_key = sprintf( 'missing_any_lang_%s_%s_%d', $type->value, md5( implode( ',', $language_ids ) ), $source_language_id );

		return (array) $this->cache->get(
			$cache_key,
			function () use ( $type, $language_ids, $source_language_id ): array {
				// The outer scan to the row that survives the source-only filter.
				$src_sql  = $source_language_id > 0 ? ' AND l.language_id = %d' : '';
				$src_args = $source_language_id > 0 ? [ $source_language_id ] : [];
				$lang_count   = count( $language_ids );
				$placeholders = implode( ',', array_fill( 0, $lang_count, '%d' ) );

				// Each group LEFT JOINed to its links in the target languages; fewer distinct matches than languages means one is
				// missing (LEFT JOIN, so groups with none count 0).
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

		// No groups on this blog: cache [] without the JOIN.
		if ( ! $this->has_any_groups() ) {
			$cached = $this->cache->get_static( $cache_key, 'perflocale_trans' );

			if ( $cached !== null ) {
				return $cached;
			}

			$this->cache->set_static( $cache_key, [], 'perflocale_trans' );
			return [];
		}

		// L0: the eager map, as prime_translations() Step 0 (an array hit); null on larger blogs, then the cache below.
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
	 * The translated object id in a language.
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
	 * The link in a language of an object's group, from the database: for decisions that must see other processes'
	 * writes (get_translation_in_language() answers from this process's caches). One indexed query; not for read paths.
	 *
	 * @param int        $object_id   Any object in the group.
	 * @param ObjectType $type        The object's type.
	 * @param int        $language_id Language to look up.
	 * @return array{group_id: int, object_id: int}|false|null The link; null
	 *     when the object has no group of that type or nothing is linked in the
	 *     language; false when there is no answer: the query did not run, failed,
	 *     or returned a row of the wrong shape.
	 */
	public function find_translation_link_uncached( int $object_id, ObjectType $type, int $language_id ): array|false|null {
		$links_table = $this->links_table();

		// object_id spans post, term and string ids: the type is bound on the groups join.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A read that must bypass every cache. Identifiers are bound with %i; WPCS cannot follow prepare() called on a property.
		$sql = $this->wpdb->prepare(
			'SELECT t.group_id, t.object_id FROM %i s
			INNER JOIN %i g ON g.id = s.group_id AND g.type = %s
			INNER JOIN %i t ON t.group_id = s.group_id AND t.language_id = %d
			WHERE s.object_id = %d
			LIMIT 1',
			$links_table,
			$this->groups_table(),
			$type->value,
			$links_table,
			$language_id,
			$object_id
		);

		if ( ! is_string( $sql ) || '' === $sql ) {
			return false;
		}

		// Not get_row(): it cannot tell no row from no answer (after an early false from query() it returns the previous
		// query's row). query() returns a count only when this query ran without an error.
		$rows = $this->wpdb->query( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_int( $rows ) ) {
			return false;
		}

		if ( 0 === $rows ) {
			return null;
		}

		$result = $this->wpdb->last_result;
		$row    = is_array( $result ) ? ( $result[0] ?? null ) : null;
		$fields = is_object( $row ) ? get_object_vars( $row ) : [];
		$group  = $fields['group_id'] ?? null;
		$linked = $fields['object_id'] ?? null;

		if ( ! is_numeric( $group ) || ! is_numeric( $linked ) || (int) $group <= 0 || (int) $linked <= 0 ) {
			return false;
		}

		return [
			'group_id'  => (int) $group,
			'object_id' => (int) $linked,
		];
	}

	/**
	 * Drop this process's view of a group after the database showed a link it lacked: invalidate_group_cache() plus the
	 * "no groups" memo and the eager map inside this process's alloptions (delete_option() leaves it when another process
	 * deleted the row). Only when two processes created the same translation.
	 *
	 * @param int        $group_id Group the link was found in.
	 * @param ObjectType $type     The group's type.
	 * @return void
	 */
	public function forget_stale_group( int $group_id, ObjectType $type ): void {
		$this->invalidate_group_cache( $group_id );

		if ( false === self::$has_any_groups_memo ) {
			self::reset_static_caches();
		}

		if ( array_key_exists( 'perflocale_eager_links_' . $type->value, wp_load_alloptions() ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/**
	 * Create a group and link its first object; `$migration_source` is recorded in the same transaction, so a retry
	 * after a crash or restore reuses the group (importers call get_group_id() first). Source keys and the transaction
	 * rules: docs-ai/11-code-rationale.md, "TranslationGroupRepository: create_group() and link_object()".
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
		// As link_object(): object_id 0 would cascade into a site-wide DELETE there.
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

		// Group and link in one transaction (no empty group on failure), nested in a registered outer one instead of a
		// second START TRANSACTION, which MySQL treats as a COMMIT (as link_object()).
		$needs_own_transaction = ! self::$in_transaction;

		if ( $needs_own_transaction ) {
			// Without a transaction a later failure would leave an empty group: write nothing.
			if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				return false;
			}

			self::$in_transaction = true;
		}

		$committed = false;

		// finally clears the static flag and rolls back even when a hook inside throws, or later calls run outside a
		// transaction.
		try {
			// The group.
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

			// Its first object.
			$this->new_group_id = $group_id;

			try {
				$link_id = $this->link_object( $group_id, $object_id, $language_id, $status, $source );
			} finally {
				$this->new_group_id = 0;
			}

			if ( $link_id === false ) {
				return false;
			}

			// The source-map row in the same transaction (a crash leaves neither); its upsert converges on a reused key.
			if ( is_array( $migration_source ) && $migration_source['type'] !== '' && $migration_source['key'] !== '' ) {
				// Fully prepared (%i, %s, %s, %d); the disable spans the call because the sniff does not know $this->wpdb.
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
				// A failed COMMIT means the server rolled back: no phantom id. The finally clears the flag.
				if ( false === $this->wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					return false;
				}
			}
			$committed = true;
		} finally {
			// Only the owner commits or rolls back; nested, a false return lets the outer owner roll back once.
			if ( $needs_own_transaction ) {
				if ( ! $committed ) {
					$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
				self::$in_transaction = false;
			}
		}

		// Pin has_any_groups() true: an earlier false memo made CacheInvalidator skip the siblings' hreflang (up to 12 h).
		$this->mark_has_any_groups();

		// The read before the group existed cached an empty list (L2 for an hour, the eager map): flush it, as
		// update_link_status() does.
		$this->cache->flush_object( $object_id, $type->value );
		$this->invalidate_find_cache( $object_id, $type );
		$this->invalidate_eager_link_map( $type );

		return $group_id;
	}

	/**
	 * Move an object into this group, deleting its links in other groups of the destination type (one group per type),
	 * for posts and terms only. Never for string groups (their links share one object_id): use upsert_link(); guarded,
	 * with object_id 0 and a missing group (throws under WP_DEBUG, else logs and returns false).
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
		// Guard 1: object_id 0 would make the DELETE wipe every zero row; no destructive SQL either way.
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

		// Guard 2: a string group's links share the string id, so the DELETE would wipe every language: upsert_link().
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

		// Guard 3: the group must exist; without its type the DELETE would remove a colliding term's or string's link and
		// the INSERT store type ''. Always a caller error (a stale id).
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

		// object_id is polymorphic: snapshot, DELETE and the new row carry the destination type, or linking term 7 would
		// evict post 7.
		$dest_type = (string) ( $group->type ?? '' );

		// DELETE and INSERT in one transaction (a crash between them orphans the object); a static flag, not @@in_transaction.
		$needs_own_transaction = ! self::$in_transaction;

		if ( $needs_own_transaction ) {
			// The DELETEs are safe only in a transaction (a failed INSERT would leave no link): write nothing without one.
			if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				return false;
			}

			self::$in_transaction = true;
		}

		// The object's current groups, to remove any left empty by the move. Tables as %i; NotPrepared stays disabled (the
		// sniff only knows a bare $wpdb->prepare()).
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
		// A failed read stops the transaction: on SQLite it has ended and later writes would commit one by one.
		if ( $this->statement_failed() ) {
			if ( $needs_own_transaction ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::$in_transaction = false;
			}

			return false;
		}

		$previous_group_ids = array_map( 'intval', (array) $previous_group_ids );

		// Remove the object's links in its own type space (one group per type); a failed DELETE aborts and rolls back. One
		// table per DELETE: the SQLite integration's 2.2.x legacy driver mishandles a DELETE holding JOIN.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		$invariant_delete = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM %i WHERE object_id = %d AND group_id IN ( SELECT id FROM %i WHERE type = %s )',
				$this->links_table(),
				$object_id,
				$this->groups_table(),
				$dest_type
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

		// The post holding this language in a post group leaves it with the DELETE below, and cached alternates still list
		// it: read it first, locking the row the DELETE locks (a group create_group() just made has no link).
		$displaced_id = 0;

		if ( ObjectType::Post->value === $dest_type && $group_id !== $this->new_group_id ) {
			// Row lock on MySQL only (see ConnectionGuard::lock_suffix()).
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Table bound with %i; the NotPrepared sniff misses $this->wpdb->prepare().
			$displaced = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT object_id FROM %i WHERE group_id = %d AND language_id = %d' . \PerfLocale\Database\ConnectionGuard::lock_suffix(),
					$this->links_table(),
					$group_id,
					$language_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

			// A failed read puts the transaction in doubt, as for the statements around it.
			if ( $this->statement_failed() ) {
				if ( $needs_own_transaction ) {
					$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::$in_transaction = false;
				}
				return false;
			}

			$displaced_id = (int) $displaced;
		}

		// Remove the group's link for this language (the group_lang key).
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

		// The new link.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->links_table(),
			[
				'group_id'    => $group_id,
				'object_id'   => $object_id,
				'language_id' => $language_id,
				// The destination group's type, so the object_lang key keeps the id spaces apart.
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

		// Remove previous groups the move left empty (not the destination, never string groups).
		$surviving_prev = [];
		foreach ( $previous_group_ids as $prev_gid ) {
			if ( $prev_gid <= 0 || $prev_gid === $group_id ) {
				continue;
			}

			// NotPrepared stays disabled (see the snapshot above).
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
			$remaining = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE group_id = %d',
					$this->links_table(),
					$prev_gid
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

			// COUNT always answers one row: NULL or an error is a failure, and (int) null would delete a group siblings still
			// link to. Roll back and fail (after a lost connection the server has rolled the move back already).
			if ( null === $remaining || '' !== $this->wpdb->last_error ) {
				if ( $needs_own_transaction ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$this->wpdb->query( 'ROLLBACK' );
					self::$in_transaction = false;
				}
				return false;
			}

			if ( (int) $remaining > 0 ) {
				// Survives with other members, whose cached member lists still include the moved object: refreshed after commit.
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

			// A failed read stops the transaction, as above.
			if ( $this->statement_failed() ) {
				if ( $needs_own_transaction ) {
					$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::$in_transaction = false;
				}

				return false;
			}

			if ( ! $prev_group_row || ( $prev_group_row->type ?? '' ) === 'string' ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $this->wpdb->delete(
				$this->groups_table(),
				[ 'id' => $prev_gid ],
				[ '%d' ]
			) ) {
				// A real SQL error (not 0 affected rows, which a concurrent GC can cause): roll the whole link back so the caller
				// can retry.
				if ( $needs_own_transaction ) {
					$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					self::$in_transaction = false;
				}
				return false;
			}

			// The import records that pointed at the emptied group go with it.
			$this->delete_source_map_for_group( $prev_gid );
		}

		if ( $needs_own_transaction ) {
			$committed            = $this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::$in_transaction = false;
			if ( false === $committed ) {
				// A failed COMMIT means the server rolled back: report failure, not a link id.
				return false;
			}
		}

		$link_id = (int) $this->wpdb->insert_id;

		if ( ! $link_id ) {
			return false;
		}

		// Invalidate every member of this group, the new object included.
		$member_ids = $this->invalidate_group_cache( $group_id );

		// Refresh surviving previous groups so their members stop resolving the moved object.
		$left_member_ids = [];

		foreach ( $surviving_prev as $prev_gid ) {
			$left_member_ids = array_merge( $left_member_ids, $this->invalidate_group_cache( $prev_gid ) );
		}

		// The displaced post is not among the members read above.
		if ( $displaced_id > 0 ) {
			$this->cache->flush_object( $displaced_id, $dest_type );
			unset( self::$find_cache[ self::find_cache_key( $dest_type, $displaced_id ) ] );
		}

		// The linked object's own find_for_object() entry (dest type; a group's type never changes), as the invalidation
		// above drops it only when its member SELECT succeeds.
		unset( self::$find_cache[ self::find_cache_key( $dest_type, $object_id ) ] );

		if ( ObjectType::Post->value === $dest_type ) {
			self::report_link_change( $group_id, $object_id, $member_ids, $displaced_id, $left_member_ids );

			// Template menu and pattern references follow the link at the end of the request (posts only).
			\PerfLocale\Translation\TemplateBlockRefs::note_post_link( $object_id );
		}

		/** @hook perflocale/translation/linked Fires after an object is linked to a group. */
		do_action( 'perflocale/translation/linked', $group_id, $object_id, $language_id );

		return $link_id;
	}

	/**
	 * Hand CacheInvalidator the posts a post link changed, for its `perflocale/translation/linked` listener that clears
	 * their pages' hreflang.
	 *
	 * @param int             $group_id        Group the object was linked to.
	 * @param int             $object_id       Linked post.
	 * @param array<int, int> $member_ids      The group's members after the link.
	 * @param int             $displaced_id    Post that held the language in the group, 0 for none.
	 * @param array<int, int> $left_member_ids Members of the groups the post left that still exist.
	 * @return void
	 */
	private static function report_link_change( int $group_id, int $object_id, array $member_ids, int $displaced_id, array $left_member_ids ): void {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'cache_invalidator' ) ) {
			return;
		}

		$invalidator = $plugin->get( 'cache_invalidator' );

		if ( $invalidator instanceof \PerfLocale\Cache\CacheInvalidator ) {
			$invalidator->note_link_change( $group_id, $object_id, $member_ids, $displaced_id, $left_member_ids );
		}
	}

	/**
	 * Add a language to a string group with an upsert, other languages untouched (for posts and terms use link_object()).
	 * A stale link in another group colliding on object_lang is reaped (type-scoped) and the write retried once.
	 * Docs: docs-ai/11-code-rationale.md, "TranslationGroupRepository: upsert_link() and the two keys".
	 *
	 * @param int                         $group_id Group ID.
	 * @param int                         $object_id Object ID (string ID for string groups).
	 * @param int                         $language_id Language ID.
	 * @param string                      $status Translation status.
	 * @param \PerfLocale\Enum\SourceType $source Translation source provenance.
	 * @param bool                        $in_transaction True when the caller runs this inside its own
	 *                                                    transaction (one it did not mark with
	 *                                                    set_in_transaction()): a failed write is then
	 *                                                    returned at once, with no further statement.
	 * @return int|false Link ID or false on failure.
	 * @throws \InvalidArgumentException When $object_id <= 0 and WP_DEBUG is on (mirrors link_object() guard).
	 */
	public function upsert_link(
		int $group_id,
		int $object_id,
		int $language_id,
		string $status = 'translated',
		\PerfLocale\Enum\SourceType $source = \PerfLocale\Enum\SourceType::Manual,
		bool $in_transaction = false
	): int|false {
		// The object_id > 0 guard of link_object().
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

		// String links only in string groups: in a post or term group the upsert would rewrite that group's link. Callers
		// give the string its own group first (assign_new_string_group()).
		if ( ! $this->is_string_group( $group_id ) ) {
			return false;
		}

		$safe_status = sanitize_key( $status );
		$safe_source = $source->value;

		$link_id = $this->write_string_link( $group_id, $object_id, $language_id, $safe_status, $safe_source );
		$link_id = $link_id < 0 && ! ( $in_transaction || self::$in_transaction ) ? 0 : $link_id;

		// In a transaction nothing may follow a failed write (SQLite ends it): report it for the caller's rollback. Outside
		// one, a failed write still gets the reap and retry below.
		if ( $link_id < 0 && ( $in_transaction || self::$in_transaction ) ) {
			return false;
		}

		if ( $link_id === 0 ) {
			// No error, yet no link for this group: the INSERT collided on object_lang and rewrote another group's row. That row
			// is debris (a string has one group): drop it (type-scoped, never this group's) and write again.
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

			if ( false === $reaped && ( $in_transaction || self::$in_transaction ) ) {
				return false;
			}

			if ( is_int( $reaped ) && $reaped > 0 ) {
				$link_id = $this->write_string_link( $group_id, $object_id, $language_id, $safe_status, $safe_source );

				if ( $link_id < 0 && ( $in_transaction || self::$in_transaction ) ) {
					return false;
				}
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
	 * One string-link upsert, answering with the id of the row that links this group to this language after it, re-read on
	 * group_lang: insert_id after an update names whatever row MySQL touched, possibly another group's.
	 *
	 * @param int    $group_id    Group ID.
	 * @param int    $object_id   String ID.
	 * @param int    $language_id Language ID.
	 * @param string $status      Sanitised status.
	 * @param string $source      Source value.
	 * @return int Link ID, 0 when this group + language ended up with no row, or -1 when the
	 *             statement failed.
	 */
	private function write_string_link( int $group_id, int $object_id, int $language_id, string $status, string $source ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table bound with %i; the NotPrepared sniff misses $this->wpdb->prepare().
		// Only string groups come here (link_object()'s Guard 2): type is 'string', no lookup.
		$touch  = \PerfLocale\Database\Schema::touch_write_time(
			[
				[ 'object_id', 'VALUES(object_id)', 'int' ],
				[ 'status', 'VALUES(status)', 'text' ],
				[ 'source', 'VALUES(source)', 'text' ],
			]
		);
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO %i (group_id, object_id, language_id, type, status, source)
				VALUES (%d, %d, %d, \'string\', %s, %s)
				ON DUPLICATE KEY UPDATE
					object_id = VALUES(object_id),
					status = VALUES(status),
					source = VALUES(source)' . $touch,
				$this->links_table(),
				$group_id,
				$object_id,
				$language_id,
				$status,
				$source
			)
		);

		if ( $result === false ) {
			return -1;
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
	 * Remove one language's link from a group, siblings intact: dropped together with the string_translations row (as
	 * AdminController's string save), or a 'translated' link points at nothing.
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
	 * Update one object's link status and source, scoped by type (post and term ids collide). The status write entry
	 * point besides link_object() (ContentChangeDetector::clear_needs_update() uses it).
	 *
	 * @param int         $object_id Object ID (post id, term id, or string id per $type).
	 * @param int         $language_id Language ID.
	 * @param string      $status New status.
	 * @param string      $source New source (optional).
	 * @param ObjectType  $type Object type the id belongs to. Defaults to post.
	 * @param string|null $expect_status Write ONLY while the row still reads this status
	 *                                   (compare-and-set). Required of any caller that
	 *                                   chose $status by reading the current one, since
	 *                                   the read and this write are separate statements.
	 *                                   Null writes unconditionally.
	 * @param string|null $expect_updated_at Write ONLY while the row's `updated_at` still
	 *                                   reads this value as well: no other write reached
	 *                                   the row since that time was read. Null for none.
	 * @return bool True when the row was written. With a precondition set, false also
	 *              means the precondition did not hold and nothing changed.
	 */
	public function update_link_status( int $object_id, int $language_id, string $status, string $source = '', ObjectType $type = ObjectType::Post, ?string $expect_status = null, ?string $expect_updated_at = null ): bool {
		$data   = [
			'status'     => sanitize_key( $status ),
			'updated_at' => current_time( 'mysql' ),
		];
		$format = [ '%s', '%s' ];

		if ( $source !== '' ) {
			$data['source'] = sanitize_key( $source );
			$format[]       = '%s';
		}

		$where        = [
			'object_id'   => $object_id,
			'language_id' => $language_id,
			'type'        => $type->value,
		];
		$where_format = [ '%d', '%d', '%s' ];

		// Compare-and-set: a caller that chose by reading the status puts it in the WHERE; unconditional writers (publish,
		// bulk reconcile) pass nothing.
		if ( $expect_status !== null ) {
			$where['status'] = sanitize_key( $expect_status );
			$where_format[]  = '%s';
		}

		if ( $expect_updated_at !== null ) {
			$where['updated_at'] = $expect_updated_at;
			$where_format[]      = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->links_table(),
			$data,
			$where,
			$format,
			$where_format
		);

		// Under a precondition 0 rows means it did not hold (updated_at = NOW(), so a match always counts): return
		// before flushing caches or firing status_changed for a write that did not happen.
		if ( ( $expect_status !== null || $expect_updated_at !== null ) && ( $result === false || (int) $result === 0 ) ) {
			return false;
		}

		if ( $result !== false ) {
			// Only the type written: another type would fire a spurious flush_object purge.
			$this->cache->flush_object( $object_id, $type->value );

			// The status is in the eager map, which get_translations() reads first: without this a publish would stay missing
			// from hreflang, the switcher and resolution until another link write.
			$this->invalidate_eager_link_map( $type );

			// Every member caches the group's links with their statuses: drop the siblings' caches too.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared through $this->wpdb, identifier bound with %i.
			$group_id = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT group_id FROM %i WHERE object_id = %d AND language_id = %d AND type = %s LIMIT 1',
					$this->links_table(),
					$object_id,
					$language_id,
					$type->value
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $group_id > 0 ) {
				$this->invalidate_group_cache( $group_id );
			}

			/** @hook perflocale/translation/status_changed Fires after a translation status changes. */
			do_action( 'perflocale/translation/status_changed', $object_id, $status, $language_id );
		}

		return $result !== false;
	}

	/**
	 * One object's link as stored at this moment (status, last write time), uncached: for decisions that must see other processes'
	 * writes. Null without a link in that language or without an answer.
	 *
	 * @param int        $object_id   Object ID.
	 * @param int        $language_id Language ID.
	 * @param ObjectType $type        Object type the id belongs to.
	 * @return array{status: string, updated_at: string}|null
	 */
	public function get_link_state( int $object_id, int $language_id, ObjectType $type = ObjectType::Post ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A read that must bypass every cache. The identifier is bound with %i; WPCS cannot follow prepare() called on a property.
		$sql = $this->wpdb->prepare(
			'SELECT status, updated_at FROM %i WHERE object_id = %d AND language_id = %d AND type = %s LIMIT 1',
			$this->links_table(),
			$object_id,
			$language_id,
			$type->value
		);

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		// query() answers a row count only when this query ran; get_row() could hand back an earlier query's row.
		$rows = $this->wpdb->query( $sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_int( $rows ) || 0 === $rows ) {
			return null;
		}

		$result     = $this->wpdb->last_result;
		$row        = is_array( $result ) ? ( $result[0] ?? null ) : null;
		$fields     = is_object( $row ) ? get_object_vars( $row ) : [];
		$status     = $fields['status'] ?? null;
		$updated_at = $fields['updated_at'] ?? null;

		if ( ! is_string( $status ) || ! is_string( $updated_at ) ) {
			return null;
		}

		return [
			'status'     => $status,
			'updated_at' => $updated_at,
		];
	}

	/**
	 * Remove every link of an object (a post or term deleted for good).
	 *
	 * @param int    $object_id Object ID.
	 * @param string $type Object type ('post' or 'term').
	 * @return bool
	 */
	public function unlink_by_object_id( int $object_id, string $type = 'post' ): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// The link first, for the group cache invalidation.
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

			// The object left the group's links: drop its own cached group by name.
			$this->cache->flush_object( $object_id, $type );
			unset( self::$find_cache[ self::find_cache_key( $type, $object_id ) ] );

			// The group's last link gone: remove the empty group (string groups have their own lifecycle).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$remaining = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE group_id = %d',
					$this->links_table(),
					(int) $link->group_id
				)
			);

			// COUNT always answers one row: NULL or an error is a failure ((int) null is 0). Unknown keeps the group (siblings
			// may still link to it); gc_empty_groups() removes it later if empty.
			$count_known = null !== $remaining && '' === $this->wpdb->last_error;

			if ( $count_known && 0 === (int) $remaining && ( $link->type ?? $type ) !== 'string' ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->wpdb->delete(
					$this->groups_table(),
					[ 'id' => (int) $link->group_id ],
					[ '%d' ]
				);

				$this->delete_source_map_for_group( (int) $link->group_id );

				// A group was removed: reset the memo, and when none remain clear the autoloaded flag so the site returns to the
				// no-groups gate (has_any_groups() sets it again if one reappears).
				self::$has_any_groups_memo = null;

				// The table bound as %i: nothing interpolated.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$any_left = (int) $this->wpdb->get_var(
					$this->wpdb->prepare( 'SELECT EXISTS( SELECT 1 FROM %i LIMIT 1 )', $this->groups_table() )
				);

				if ( 0 === $any_left ) {
					if ( '1' === get_option( 'perflocale_has_any_groups', '' ) ) {
						update_option( 'perflocale_has_any_groups', '', true );
					}

					// The request and object-cache copies of true go too (that key has no expiry).
					$this->cache->set_static( 'has_any_groups', null, 'perflocale_trans' );
					wp_cache_delete( 'has_any_groups', 'perflocale_trans' );
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
		// Links first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->delete( $this->links_table(), [ 'group_id' => $id ], [ '%d' ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete( $this->groups_table(), [ 'id' => $id ], [ '%d' ] );

		$this->delete_source_map_for_group( $id );

		return $result !== false;
	}

	/**
	 * Delete the migration source-map rows of a group being removed, so a re-import never resolves a dead group id.
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
	 * Remove non-string groups without links (daily GC): ones unlink_by_object_id() kept on an unreadable count,
	 * existing orphans, and leftovers of paths that bypass the delete helpers. String groups are skipped: an empty one is
	 * a normal state during bulk string runs.
	 *
	 * @return int Number of orphan group rows removed.
	 */
	public static function gc_empty_groups(): int {
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return 0;
		}
		global $wpdb;

		// Tables from Schema::table() (an allowlist); Plugin Check cannot trace that, hence the block suppression.
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );
		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );

		// MySQL refuses LIMIT on a multi-table DELETE: a capped SELECT (LEFT JOIN, indexed) then a single-table DELETE.

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

		// %d per id from the SELECT above.
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- Replacements are a %i table name followed by an unpacked int list; WPCS cannot count an unpack.
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$placeholders})",
				$groups_table,
				...array_map( 'intval', $ids )
			)
		);

		// Their migration source-map rows go too, so a re-import cannot resolve a dead group id.
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
	 * Invalidate the caches of every object in a group.
	 *
	 * @param int $group_id Group ID.
	 * @return array<int, int> The objects the group links, as read for the invalidation; empty when the read failed.
	 */
	public function invalidate_group_cache( int $group_id ): array {
		++self::$link_changes;

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
			return [];
		}

		$dirty_types = [];

		foreach ( $links as $link ) {
			$this->cache->flush_object( (int) $link->object_id, $link->type );

			// And the per-request find_for_object() entries.
			$find_key = self::find_cache_key( (string) $link->type, (int) $link->object_id );
			unset( self::$find_cache[ $find_key ] );

			$dirty_types[ (string) $link->type ] = true;
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Drop the eager maps of every type the group touched; with no rows left (delete then flush) the type is unknown,
		// so all of them.
		if ( $dirty_types === [] ) {
			$this->invalidate_eager_link_map();
		} else {
			$names = [];

			foreach ( array_keys( $dirty_types ) as $type_value ) {
				unset( self::$eager_link_map_memo[ self::eager_memo_key( $type_value ) ] );
				$names[] = 'perflocale_eager_links_' . $type_value;
			}

			// As invalidate_eager_link_map(): a cached map without its row goes too.
			CacheManager::delete_autoloaded_options( $names );
		}

		return array_map( static fn( $id ): int => is_numeric( $id ) ? (int) $id : 0, array_column( $links, 'object_id' ) );
	}

	/**
	 * Clear one object's per-request find_for_object() entry after a group or link change.
	 *
	 * @param int        $object_id Object ID.
	 * @param ObjectType $type Object type.
	 * @return void
	 */
	public function invalidate_find_cache( int $object_id, ObjectType $type ): void {
		++self::$link_changes;

		$find_key = self::find_cache_key( $type->value, $object_id );
		unset( self::$find_cache[ $find_key ] );
	}

	/**
	 * Remove widow groups: non-string groups without links (deleted objects, merge imports) and string groups owning no
	 * string, with their links (a link to a deleted group holds the object_lang key and breaks the next upsert_link()).
	 * Shared by the merge importer and the upgrade self-heal.
	 *
	 * @return int
	 */
	public function sweep_orphan_groups(): int {
		$groups  = $this->groups_table();
		$links   = $this->links_table();
		$strings = Schema::table( 'strings' );

		// Plugin Check's per-line `phpcs:ignore` directive doesn't always
		// cover every line of a multi-line $wpdb->query() call: hence the disable/enable form.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders -- Replacements arrive via array_merge(), which WPCS cannot count.
		// Single-table DELETEs: the SQLite integration's 2.2.x legacy driver rejects `DELETE g FROM` and mishandles a JOIN.
		$widows = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i
				WHERE type != 'string' AND id NOT IN ( SELECT group_id FROM %i )",
				$groups,
				$links
			)
		);

		// Links first: a string group's leftover links hold the object_lang key, so the string's next upsert_link() collides
		// there and the translation is stored but not served. Same type join and NOT EXISTS as the group delete.
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i
				WHERE group_id IN (
					SELECT g.id FROM %i g
					WHERE g.type = 'string' AND NOT EXISTS ( SELECT 1 FROM %i s WHERE s.group_id = g.id )
				)",
				$links,
				$groups,
				$strings
			)
		);

		$orphan_strings = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM %i
				WHERE type = 'string' AND id NOT IN ( SELECT group_id FROM %i )",
				$groups,
				$strings
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders

		return max( 0, (int) $widows ) + max( 0, (int) $orphan_strings );
	}

	/**
	 * Link many strings to their groups, one upsert per 200 links, then one read to confirm each; a link the batch could
	 * not place (a stale object_lang row) goes through upsert_link(). Non-string groups are not written. In a registered
	 * transaction it stops when the transaction is lost and answers -1 for the caller's rollback.
	 *
	 * @param array<int, array{0: int, 1: int, 2: int}> $links  group_id, string_id, language_id.
	 * @param \PerfLocale\Enum\SourceType               $source Source written on each link.
	 * @param string                                    $status Status written on each link.
	 * @return int Links that could not be written, or -1 when the transaction was lost.
	 */
	public function upsert_string_links( array $links, \PerfLocale\Enum\SourceType $source, string $status = 'translated' ): int {
		$links = array_values( array_filter( $links, static fn( array $l ): bool => (int) $l[0] > 0 && (int) $l[1] > 0 && (int) $l[2] > 0 ) );

		if ( $links === [] ) {
			return 0;
		}

		$safe_status = sanitize_key( $status );
		$failed      = 0;
		$group_ids   = [];
		$errors      = \PerfLocale\Database\Schema::query_error_count();
		$touch       = \PerfLocale\Database\Schema::touch_write_time(
			[
				[ 'object_id', 'VALUES(object_id)', 'int' ],
				[ 'status', 'VALUES(status)', 'text' ],
				[ 'source', 'VALUES(source)', 'text' ],
			]
		);

		foreach ( array_chunk( $links, 200 ) as $chunk ) {
			// A link into a non-string group is not written (see upsert_link()) and counts as not written.
			$string_groups = $this->string_group_ids( array_values( array_unique( array_map( static fn( array $l ): int => (int) $l[0], $chunk ) ) ) );

			if ( self::$in_transaction && \PerfLocale\Database\Schema::transaction_lost( $errors, $this->statement_failed() ) ) {
				return -1;
			}

			$kept    = array_values( array_filter( $chunk, static fn( array $l ): bool => isset( $string_groups[ (int) $l[0] ] ) ) );
			$failed += count( $chunk ) - count( $kept );
			$chunk   = $kept;

			if ( $chunk === [] ) {
				continue;
			}

			$values = implode( ',', array_fill( 0, count( $chunk ), "(%d, %d, %d, 'string', %s, %s)" ) );
			$args   = [ $this->links_table() ];

			foreach ( $chunk as $l ) {
				$args[] = (int) $l[0];
				$args[] = (int) $l[1];
				$args[] = (int) $l[2];
				$args[] = $safe_status;
				$args[] = $source->value;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $values is a generated placeholder list bound to $args.
			$written = $this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO %i (group_id, object_id, language_id, type, status, source)
					VALUES {$values}
					ON DUPLICATE KEY UPDATE
						object_id = VALUES(object_id),
						status = VALUES(status),
						source = VALUES(source)" . $touch,
					$args
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( self::$in_transaction && \PerfLocale\Database\Schema::transaction_lost( $errors, false === $written ) ) {
				return -1;
			}

			$ids          = array_values( array_unique( array_map( static fn( array $l ): int => (int) $l[0], $chunk ) ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is a generated %d list bound to $ids.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT group_id, language_id, object_id FROM %i WHERE group_id IN ({$placeholders})",
					array_merge( [ $this->links_table() ], $ids )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( self::$in_transaction && \PerfLocale\Database\Schema::transaction_lost( $errors, $this->statement_failed() ) ) {
				return -1;
			}

			$now = [];

			foreach ( (array) $rows as $row ) {
				$now[ (int) $row->group_id ][ (int) $row->language_id ] = (int) $row->object_id;
			}

			foreach ( $chunk as [ $group_id, $string_id, $language_id ] ) {
				$group_id    = (int) $group_id;
				$string_id   = (int) $string_id;
				$language_id = (int) $language_id;

				if ( ( $now[ $group_id ][ $language_id ] ?? 0 ) !== $string_id ) {
					$linked = $this->upsert_link( $group_id, $string_id, $language_id, $safe_status, $source );

					if ( self::$in_transaction && \PerfLocale\Database\Schema::transaction_lost( $errors, false === $linked && $this->statement_failed() ) ) {
						return -1;
					}

					if ( false === $linked ) {
						++$failed;
					}

					continue;
				}

				$group_ids[ $group_id ] = true;

				/** This action is documented in upsert_link(). */
				do_action( 'perflocale/translation/linked', $group_id, $string_id, $language_id );

				if ( self::$in_transaction && \PerfLocale\Database\Schema::transaction_lost( $errors ) ) {
					return -1;
				}
			}
		}

		$this->invalidate_groups_cache( array_keys( $group_ids ) );

		return $failed;
	}

	/**
	 * Invalidate many groups' caches with one read of their links (the batch invalidate_group_cache()).
	 *
	 * @param int[] $group_ids Group ids.
	 * @return void
	 */
	private function invalidate_groups_cache( array $group_ids ): void {
		if ( $group_ids === [] ) {
			return;
		}

		$dirty_types = [];

		foreach ( array_chunk( $group_ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is a generated %d list bound to $chunk.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT l.object_id, g.type FROM %i l
					INNER JOIN %i g ON l.group_id = g.id
					WHERE l.group_id IN ({$placeholders})",
					array_merge( [ $this->links_table(), $this->groups_table() ], $chunk )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

			foreach ( (array) $rows as $link ) {
				$this->cache->flush_object( (int) $link->object_id, (string) $link->type );
				unset( self::$find_cache[ self::find_cache_key( (string) $link->type, (int) $link->object_id ) ] );
				$dirty_types[ (string) $link->type ] = true;
			}
		}

		$names = [];

		foreach ( array_keys( $dirty_types ) as $type_value ) {
			unset( self::$eager_link_map_memo[ self::eager_memo_key( $type_value ) ] );
			$names[] = 'perflocale_eager_links_' . $type_value;
		}

		CacheManager::delete_autoloaded_options( $names );
	}

	/**
	 * Whether the statement that just ran failed (wpdb clears last_error at the start of every query).
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private function statement_failed(): bool {
		return '' !== $this->wpdb->last_error;
	}
}
