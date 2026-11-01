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
class TranslationGroupRepository implements RepositoryInterface {

	/**
	 * Per-request cache of find_for_object(), shared by all instances, dropped by invalidate_find_cache(); capped at
	 * FIND_CACHE_CAP, as long CLI runs walk every post.
	 *
	 * @var array<string, object|null>
	 */
	protected static array $find_cache = [];

	/**
	 * How many times this process dropped cached group or link data after a change (link_changes()).
	 *
	 * @var int
	 */
	protected static int $link_changes = 0;

	/**
	 * Soft cap of $find_cache; past it the oldest quarter is evicted (as UrlConverter::cap_cache()).
	 */
	private const FIND_CACHE_CAP = 5000;

	/**
	 * Per-request memo of has_any_groups(), null until resolved.
	 *
	 * @var bool|null
	 */
	protected static ?bool $has_any_groups_memo = null;

	/**
	 * Per-request eager link map by `{blog_id}:{type}`: object_id => the group's links, or the 'too_large' sentinel.
	 * Blog-keyed, or switch_to_blog() would carry one blog's map or sentinel into another.
	 *
	 * @var array<string, array|string>
	 */
	protected static array $eager_link_map_memo = [];

	/**
	 * Per-request memo of is_string_group() by `{blog_id}:{group_id}`, true only (a missing id can become a string group
	 * later). Also cleared by reset_static_caches(): a Replace import re-inserts group ids that can carry another type.
	 *
	 * @var array<string, bool>
	 */
	protected static array $is_string_group_memo = [];

	/**
	 * While true, get_eager_link_map() returns null (JOIN fallback) and nothing is rebuilt: bulk writers set it around
	 * their loop.
	 *
	 * @var bool
	 */
	private static bool $eager_link_map_suspended = false;

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
	protected static bool $in_transaction = false;

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
	protected readonly \wpdb $wpdb;

	/**
	 * @var CacheManager
	 */
	protected readonly CacheManager $cache;

	/**
	 * The write half (TranslationGroupWrites), created on first use.
	 *
	 * @var TranslationGroupWrites|null
	 */
	private ?TranslationGroupWrites $writes = null;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 * @param \wpdb|null   $db    Connection, for the write half; default the global one.
	 */
	public function __construct( CacheManager $cache, ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb  = $db ?? $wpdb;
		$this->cache = $cache;
	}

	/**
	 * The write half, on this repository's connection and cache, loaded on first use.
	 *
	 * @return TranslationGroupWrites
	 */
	private function writes(): TranslationGroupWrites {
		return $this->writes ??= new TranslationGroupWrites( $this->cache, $this->wpdb );
	}

	/**
	 * The groups table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	protected function groups_table(): string {
		return Schema::table( 'translation_groups' );
	}

	/**
	 * The links table name for the current blog (as groups_table()).
	 *
	 * @return string
	 */
	protected function links_table(): string {
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
	protected static function find_cache_key( string $type, int $object_id ): string {
		return get_current_blog_id() . ":group_{$type}_{$object_id}";
	}

	/**
	 * The blog-prefixed key of $eager_link_map_memo, as find_cache_key().
	 *
	 * @param string $type Object type value.
	 * @return string
	 */
	protected static function eager_memo_key( string $type ): string {
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
		return $this->writes()->assign_new_string_group( $string_id, $in_transaction );
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
	protected function mark_has_any_groups(): void {
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
		return $this->writes()->find_source_object_ids_by_language_status( $type, $language_id, $status, $source_language_id );
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
		return $this->writes()->find_source_object_ids_by_status_any_language( $type, $status, $source_language_id );
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
		TranslationGroupWrites::on_post_status_transition( $new_status, $old_status, $post );
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
		$this->writes()->forget_status_selectors( $source_language_id );
	}

	/**
	 * Delete the selections deferred during a registered transaction, on every blog with a pending entry (a callback
	 * inside can switch blogs); nothing while a transaction is registered. Call it after a COMMIT or ROLLBACK of a
	 * transaction registered with set_in_transaction( true ) that can change a post status.
	 *
	 * @internal Called by the TranslatePress importer around its batch transactions.
	 * @return void
	 */
	public function release_status_selectors(): void {
		$this->writes()->release_status_selectors();
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
		return $this->writes()->find_source_object_ids_missing_any_language( $type, $language_ids, $source_language_id );
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
		return $this->writes()->find_translation_link_uncached( $object_id, $type, $language_id );
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
		$this->writes()->forget_stale_group( $group_id, $type );
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
		return $this->writes()->create_group( $type, $object_id, $language_id, $status, $source, $migration_source );
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
		return $this->writes()->link_object( $group_id, $object_id, $language_id, $status, $source );
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
		return $this->writes()->upsert_link( $group_id, $object_id, $language_id, $status, $source, $in_transaction );
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
		return $this->writes()->unlink_language( $group_id, $language_id );
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
		return $this->writes()->update_link_status( $object_id, $language_id, $status, $source, $type, $expect_status, $expect_updated_at );
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
		return $this->writes()->get_link_state( $object_id, $language_id, $type );
	}

	/**
	 * Remove every link of an object (a post or term deleted for good).
	 *
	 * @param int    $object_id Object ID.
	 * @param string $type Object type ('post' or 'term').
	 * @return bool
	 */
	public function unlink_by_object_id( int $object_id, string $type = 'post' ): bool {
		return $this->writes()->unlink_by_object_id( $object_id, $type );
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
		return $this->writes()->insert( $data );
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
		return $this->writes()->delete( $id );
	}

	/**
	 * Remove non-string groups without links (daily GC): ones unlink_by_object_id() kept on an unreadable count,
	 * existing orphans, and leftovers of paths that bypass the delete helpers. String groups are skipped: an empty one is
	 * a normal state during bulk string runs.
	 *
	 * @return int Number of orphan group rows removed.
	 */
	public static function gc_empty_groups(): int {
		return TranslationGroupWrites::gc_empty_groups();
	}

	/**
	 * Invalidate the caches of every object in a group.
	 *
	 * @param int $group_id Group ID.
	 * @return array<int, int> The objects the group links, as read for the invalidation; empty when the read failed.
	 */
	public function invalidate_group_cache( int $group_id ): array {
		return $this->writes()->invalidate_group_cache( $group_id );
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
		return $this->writes()->sweep_orphan_groups();
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
		return $this->writes()->upsert_string_links( $links, $source, $status );
	}

}
