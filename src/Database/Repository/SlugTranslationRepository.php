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
use PerfLocale\Helper;

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
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$slug = sanitize_title( $slug );

		if ( $slug === '' ) {
			$this->report_write_failure(
				'empty_slug',
				[
					'object_type'    => $object_type,
					'object_subtype' => $object_subtype,
					'object_id'      => $object_id,
					'language_id'    => $language_id,
					'slug'           => $slug,
				]
			);

			return false;
		}

		// A concurrent writer can take the candidate between find_unique_slug() and the write: a duplicate-key error on
		// slug_lookup retries with a fresh one, at most 3 times. Docs: docs-ai/11-code-rationale.md, "SlugTranslationRepository:
		// the 191-character prefix".
		$result = false;

		// The duplicate-key error is expected (the loop provokes it): suppressed, or every bulk term import fills the debug
		// log; last_error stays set. Restored on every exit.
		$was_suppressing = $this->wpdb->suppress_errors( true );

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$candidate = $this->find_unique_slug( $object_type, $object_subtype, $object_id, $language_id, $slug );

			// Does this object have a row (UPDATE) or not (INSERT)? Checked on each retry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT id FROM %i
					WHERE object_type = %s AND object_id = %d AND language_id = %d',
					$this->table(),
					$object_type,
					$object_id,
					$language_id
				)
			);

			// Cleared so a failure is this query's.
			$this->wpdb->last_error = '';

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $this->wpdb->update(
					$this->table(),
					[
						'object_subtype' => $object_subtype,
						'slug'           => $candidate,
					],
					[
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'language_id' => $language_id,
					],
					[ '%s', '%s' ],
					[ '%s', '%d', '%d' ]
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $this->wpdb->insert(
					$this->table(),
					[
						'object_type'    => sanitize_key( $object_type ),
						// Not sanitize_key(): every reader matches the raw subtype, and sanitize_key() strips non-ASCII (`pa_色` became
						// `pa_`). It is a bound value, never an identifier.
						'object_subtype' => $object_subtype,
						'object_id'      => $object_id,
						'language_id'    => $language_id,
						'slug'           => $candidate,
					],
					[ '%s', '%s', '%d', '%d', '%s' ]
				);
			}

			if ( $result !== false ) {
				// The resolved candidate, for the cache and flag updates below.
				$slug = $candidate;
				break;
			}

			// Retry only on a unique violation; other errors (missing table, lost connection) fail at once.
			if ( ! Schema::is_duplicate_key_error( (string) $this->wpdb->last_error ) ) {
				break;
			}
		}

		$this->wpdb->suppress_errors( $was_suppressing );

		if ( $result === false ) {
			// "Every suffix taken" apart from a real fault: the remedies differ.
			$db_error = (string) $this->wpdb->last_error;
			$this->report_write_failure(
				Schema::is_duplicate_key_error( $db_error ) ? 'duplicate_exhausted' : 'db_error',
				[
					'object_type'    => $object_type,
					'object_subtype' => $object_subtype,
					'object_id'      => $object_id,
					'language_id'    => $language_id,
					'slug'           => $slug,
					'db_error'       => $db_error,
				]
			);
		}

		// Invalidate the cache.
		$cache_key = "slug_{$object_type}_{$object_id}_{$language_id}";
		$this->cache->delete( $cache_key, 'perflocale_slugs' );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $result !== false ) {
			// A slug row exists now: pin true everywhere.
			self::$has_any_slugs_memo = true;
			$this->cache->set_static( 'has_any_slugs', 1, 'perflocale_trans' );

			if ( $this->cache->l2_enabled() ) {
				wp_cache_set( 'has_any_slugs', 1, 'perflocale_trans', 0 );
			}

			if ( get_option( 'perflocale_has_any_slugs', '' ) !== '1' ) {
				update_option( 'perflocale_has_any_slugs', '1', true );
			}
		}

		return $result !== false;
	}

	/**
	 * Report a slug write that did not happen (the object keeps its untranslated slug): an action for integrators and
	 * bulk jobs, and a log line under WP_DEBUG only (a broken table in a 10k-term import). The wpdb error never reaches
	 * a screen.
	 *
	 * @param string               $reason  One of: empty_slug, duplicate_exhausted, db_error.
	 * @param array<string, mixed> $context Object + language identifiers and the slug attempted.
	 * @return void
	 */
	private function report_write_failure( string $reason, array $context ): void {
		/**
		 * Fires when a slug translation could not be written.
		 *
		 * @hook perflocale/slug/write_failed
		 *
		 * @param string               $reason  Failure reason: 'empty_slug', 'duplicate_exhausted' or 'db_error'.
		 * @param array<string, mixed> $context object_type, object_subtype, object_id, language_id, slug and db_error.
		 */
		do_action( 'perflocale/slug/write_failed', $reason, $context );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic on a write-failure path.
		error_log(
			sprintf(
				'[PerfLocale] slug write failed (%1$s): %2$s #%3$d lang %4$d slug "%5$s"%6$s',
				$reason,
				(string) ( $context['object_type'] ?? '?' ),
				(int) ( $context['object_id'] ?? 0 ),
				(int) ( $context['language_id'] ?? 0 ),
				(string) ( $context['slug'] ?? '' ),
				isset( $context['db_error'] ) && '' !== $context['db_error'] ? ' - ' . $context['db_error'] : ''
			)
		);
	}

	/**
	 * Characters of `slug` the UNIQUE slug_lookup index covers (`slug(191)` in Schema; 764 bytes, under InnoDB's 767).
	 */
	private const SLUG_INDEX_PREFIX = 191;

	/**
	 * Trim a slug to at most $length characters without cutting a percent-escape: a cut escape makes the translated
	 * permalink a 400 at the web server (Helper::truncate_slug(), as core's _truncate_post_slug()).
	 *
	 * @param string $slug   Slug to trim (already sanitize_title()'d).
	 * @param int    $length Maximum length in characters.
	 * @return string
	 */
	private static function truncate_to_prefix( string $slug, int $length ): string {
		// One truncator, shared with the term manager.
		return Helper::truncate_slug( $slug, $length );
	}

	/**
	 * A collision-free slug within one (language, object_type, object_subtype), as wp_unique_post_slug(): `-2`, `-3`...
	 * up to 99 tries. Other subtypes are separate URL spaces and never conflict.
	 *
	 * @param string $object_type    Object type (post / term / etc.).
	 * @param string $object_subtype Sub-type (post_type / taxonomy).
	 * @param int    $object_id      The object we're assigning the slug to.
	 *                               A collision with this same object_id is
	 *                               NOT a conflict - we're just updating
	 *                               its existing slug to the same value.
	 * @param int    $language_id    Language ID.
	 * @param string $slug           Desired slug (already sanitized).
	 * @return string Collision-free slug; the last candidate tried if 99 attempts find no free slot.
	 */
	private function find_unique_slug( string $object_type, string $object_subtype, int $object_id, int $language_id, string $slug ): string {
		$candidate = $slug;

		for ( $i = 2; $i <= 100; $i++ ) {
			// The index compares the first 191 characters, so the check must too, without wrapping the column in a function:
			// an exact match below the prefix, a sargable LIKE 'prefix%' at or beyond it.
			if ( mb_strlen( $candidate ) >= self::SLUG_INDEX_PREFIX ) {
				$slug_clause = 'AND slug LIKE %s';
				$slug_value  = $this->wpdb->esc_like( mb_substr( $candidate, 0, self::SLUG_INDEX_PREFIX ) ) . '%';
			} else {
				$slug_clause = 'AND slug = %s';
				$slug_value  = $candidate;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$conflicting_id = $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT object_id FROM %i
					WHERE language_id = %d
					  AND object_type = %s
					  AND object_subtype = %s
					  ' . $slug_clause . '
					  AND object_id != %d
					LIMIT 1',
					$this->table(),
					$language_id,
					$object_type,
					$object_subtype,
					$slug_value,
					$object_id
				)
			);
			// phpcs:enable

			if ( $conflicting_id === null ) {
				return $candidate;
			}

			// The suffix must land inside the indexed prefix: trim the base, never inside a percent-escape (truncate_to_prefix()).
			$suffix    = '-' . $i;
			$base      = self::truncate_to_prefix( $slug, max( 1, self::SLUG_INDEX_PREFIX - mb_strlen( $suffix ) ) );
			$candidate = $base . $suffix;
		}

		// Search exhausted: the last candidate (soft fail).
		return $candidate;
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
	 * {@inheritDoc}
	 */
	public function find_all( array $args = [] ): array {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$object_type = sanitize_key( $args['object_type'] ?? '' );
		$language_id = absint( $args['language_id'] ?? 0 );

		$where      = '1=1';
		$query_args = [];

		if ( $object_type !== '' ) {
			$where       .= ' AND object_type = %s';
			$query_args[] = $object_type;
		}

		if ( $language_id > 0 ) {
			$where       .= ' AND language_id = %d';
			$query_args[] = $language_id;
		}

		// Always through prepare(): the LIMIT placeholder gives it an argument, so a later interpolated condition is caught.
		$sql          = "SELECT * FROM %i WHERE {$where} ORDER BY id ASC LIMIT %d";
		$query_args[] = 1000;
		$sql          = $this->wpdb->prepare( $sql, array_merge( [ $this->table() ], $query_args ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $this->wpdb->get_results( $sql );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $results ) ? $results : [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function insert( array $data ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert(
			$this->table(),
			[
				'object_type'    => sanitize_key( $data['object_type'] ?? '' ),
				// Raw subtype, as set_slug(): readers match it.
				'object_subtype' => (string) ( $data['object_subtype'] ?? '' ),
				'object_id'      => absint( $data['object_id'] ?? 0 ),
				'language_id'    => absint( $data['language_id'] ?? 0 ),
				'slug'           => sanitize_title( $data['slug'] ?? '' ),
			],
			[ '%s', '%s', '%d', '%d', '%s' ]
		);

		return $result !== false ? (int) $this->wpdb->insert_id : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( int $id, array $data ): bool {
		$update = [];
		$format = [];

		if ( isset( $data['slug'] ) ) {
			$update['slug'] = sanitize_title( $data['slug'] );
			$format[]       = '%s';
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete(
			$this->table(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}
}
