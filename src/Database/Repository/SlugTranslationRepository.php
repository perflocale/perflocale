<?php
/**
 * Slug translation repository.
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
 * Translated slugs of posts, terms, post type archives and taxonomy archives, with bulk preloading for archives.
 */
final class SlugTranslationRepository implements RepositoryInterface {

	/**
	 * Per-request memo of has_any_slugs(), null until resolved.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_any_slugs_memo = null;

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
	 * Reset the per-request statics on `switch_blog`: the answer is per blog.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$has_any_slugs_memo = null;
	}

	/**
	 * Whether any translated slug exists on this blog, so SlugManager skips its cold-path queries on sites without one.
	 * Only true is cached; false is checked again each request, so a first slug shows without invalidation.
	 *
	 * @return bool
	 */
	public function has_any_slugs(): bool {
		if ( self::$has_any_slugs_memo !== null ) {
			return self::$has_any_slugs_memo;
		}

		// L0: an autoloaded option, free across requests without Redis (as has_any_groups()).
		if ( get_option( 'perflocale_has_any_slugs', '' ) === '1' ) {
			self::$has_any_slugs_memo = true;
			return self::$has_any_slugs_memo;
		}

		$l1 = $this->cache->get_static( 'has_any_slugs', 'perflocale_trans' );

		if ( $l1 === 1 || $l1 === true ) {
			self::$has_any_slugs_memo = true;
			return self::$has_any_slugs_memo;
		}

		if ( $this->cache->l2_enabled() ) {
			$found = false;
			$value = wp_cache_get( 'has_any_slugs', 'perflocale_trans', false, $found );

			if ( $found && (bool) $value ) {
				$this->cache->set_static( 'has_any_slugs', 1, 'perflocale_trans' );
				self::$has_any_slugs_memo = true;
				return self::$has_any_slugs_memo;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Identifier (not a value): bound with the %i placeholder below. WPCS cannot follow the nested prepare() call, hence the suppression.
		$exists = (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT 1 FROM %i LIMIT 1',
				$this->table()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( $exists ) {
			$this->cache->set_static( 'has_any_slugs', 1, 'perflocale_trans' );

			if ( $this->cache->l2_enabled() ) {
				wp_cache_set( 'has_any_slugs', 1, 'perflocale_trans', 0 );
			}

			update_option( 'perflocale_has_any_slugs', '1', true );
		}

		self::$has_any_slugs_memo = $exists;
		return self::$has_any_slugs_memo;
	}

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'slug_translations' );
	}

	/**
	 * The translated slug of an object in a language.
	 *
	 * @param string $object_type Object type (post, term, post_type, taxonomy).
	 * @param int    $object_id   Object ID.
	 * @param int    $language_id Language ID.
	 * @return string|null Translated slug or null.
	 */
	public function get_slug( string $object_type, int $object_id, int $language_id ): ?string {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$cache_key = "slug_{$object_type}_{$object_id}_{$language_id}";

		// No slug translations on this blog: the answer is null, cached without a query.
		if ( ! $this->has_any_slugs() ) {
			$cached = $this->cache->get_static( $cache_key, 'perflocale_slugs' );

			if ( $cached !== null ) {
				return is_string( $cached ) ? $cached : null;
			}

			$this->cache->set_static( $cache_key, '', 'perflocale_slugs' );
			return null;
		}

		$miss   = new \stdClass();
		$cached = $this->cache->get_cached( $cache_key, 'perflocale_slugs', $miss );

		if ( $cached !== $miss ) {
			return is_string( $cached ) ? $cached : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT slug FROM %i
				WHERE object_type = %s AND object_id = %d AND language_id = %d',
				$this->table(),
				$object_type,
				$object_id,
				$language_id
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A failed read answers null like a missing row, but is not cached.
		if ( '' !== (string) $this->wpdb->last_error ) {
			return null;
		}

		$this->cache->set( $cache_key, $result, HOUR_IN_SECONDS, 'perflocale_slugs' );

		return $result;
	}

	// Cold paths: one-line forwarders; the bodies load on first call.

	/**
	 * Set or update a slug translation.
	 *
	 * @param string $object_type    Object type ('post', 'term', etc.).
	 * @param string $object_subtype Sub-type (post_type for posts, taxonomy for terms); '' for other kinds.
	 * @param int    $object_id      Object ID.
	 * @param int    $language_id    Language ID.
	 * @param string $slug           Translated slug.
	 * @return bool
	 */
	public function set_slug( string $object_type, string $object_subtype, int $object_id, int $language_id, string $slug ): bool {
		return SlugTranslationWrites::set_slug( $this->wpdb, $this->cache, self::$has_any_slugs_memo, $object_type, $object_subtype, $object_id, $language_id, $slug );
	}

	/**
	 * {@inheritDoc}
	 */
	public function find( int $id ): ?object {
		return SlugTranslationWrites::find( $this->wpdb, $id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function find_all( array $args = [] ): array {
		return SlugTranslationWrites::find_all( $this->wpdb, $args );
	}

	/**
	 * {@inheritDoc}
	 */
	public function insert( array $data ): int|false {
		return SlugTranslationWrites::insert( $this->wpdb, $data );
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( int $id, array $data ): bool {
		return SlugTranslationWrites::update( $this->wpdb, $id, $data );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( int $id ): bool {
		return SlugTranslationWrites::delete( $this->wpdb, $id );
	}
}
