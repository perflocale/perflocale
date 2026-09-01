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
 * Data access layer for translated slugs.
 *
 * Handles per-language slug translations for posts, terms, post type
 * archives, and taxonomy archives. Supports bulk preloading for
 * optimal performance on archive pages.
 */
final class SlugTranslationRepository implements RepositoryInterface {

	/**
	 * Per-request memo of `has_any_slugs()`. Stays null until the first
	 * call resolves it; thereafter all preload + get_slug calls in the
	 * same request use this without re-querying L1/L2/DB.
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
	 * Reset per-request statics on `switch_blog` (multisite). The answer
	 * to "does this blog have translated slugs?" is per-blog.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$has_any_slugs_memo = null;
	}

	/**
	 * Does any translated slug exist on this blog?
	 *
	 * Lets SlugManager::preload_slugs and SlugManager::get_slug skip
	 * their cold-path DB queries entirely on sites that don't have
	 * translated slugs yet (fresh installs, sites that translate posts
	 * but not slugs). Hot path is sub-µs; cold path is SELECT 1 LIMIT 1
	 * (~50 µs) and runs at most once per request.
	 *
	 * Only TRUE is cached. FALSE is rechecked each request, so a
	 * newly-translated slug becomes visible without explicit
	 * invalidation hooks.
	 *
	 * @return bool
	 */
	public function has_any_slugs(): bool {
		if ( self::$has_any_slugs_memo !== null ) {
			return self::$has_any_slugs_memo;
		}

		// L0 — autoloaded option for free reads across requests on
		// no-Redis sites. See has_any_groups() for rationale.
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
	 * Resolve the full table name for the current blog.
	 *
	 * Computed on every call so that switch_to_blog() flips the prefix
	 * correctly — capturing the name in the constructor would otherwise
	 * pin this instance to whichever blog was active at construction.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'slug_translations' );
	}

	/**
	 * Get the translated slug for an object in a specific language.
	 *
	 * @param string $object_type Object type (post, term, post_type, taxonomy).
	 * @param int    $object_id   Object ID.
	 * @param int    $language_id Language ID.
	 * @return string|null Translated slug or null.
	 */
	public function get_slug( string $object_type, int $object_id, int $language_id ): ?string {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$cache_key = "slug_{$object_type}_{$object_id}_{$language_id}";

		// Zero-state short-circuit: with no slug translations on this blog,
		// the loader's SELECT is guaranteed to return null. Cache and
		// return null directly so the DB hit is skipped entirely.
		if ( ! $this->has_any_slugs() ) {
			$cached = $this->cache->get_static( $cache_key, 'perflocale_slugs' );

			if ( $cached !== null ) {
				return is_string( $cached ) ? $cached : null;
			}

			$this->cache->set_static( $cache_key, '', 'perflocale_slugs' );
			return null;
		}

		$result = $this->cache->get(
			$cache_key,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			fn() => $this->wpdb->get_var(
				$this->wpdb->prepare(
					'SELECT slug FROM %i
					WHERE object_type = %s AND object_id = %d AND language_id = %d',
					$this->table(),
					$object_type,
					$object_id,
					$language_id
				)
			),
			HOUR_IN_SECONDS,
			'perflocale_slugs'
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $result;
	}

	/**
	 * Resolve an object from a translated slug.
	 *
	 * Reverse lookup: given a slug in a specific (language, object_type,
	 * object_subtype) namespace, find the object. Uses the slug_lookup
	 * UNIQUE index for index-only resolution.
	 *
	 * @param string $object_type    Object type ('post', 'term', etc.).
	 * @param string $object_subtype Sub-type (post_type for posts, taxonomy
	 *                               for terms). Empty string for legacy
	 *                               rows / non-WP object types.
	 * @param string $slug           Translated slug.
	 * @param int    $language_id    Language ID.
	 * @return int|null Object ID or null.
	 */
	public function resolve_from_slug( string $object_type, string $object_subtype, string $slug, int $language_id ): ?int {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT object_id FROM %i
				WHERE language_id = %d AND object_type = %s AND object_subtype = %s AND slug = %s',
				$this->table(),
				$language_id,
				$object_type,
				$object_subtype,
				$slug
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $result !== null ? (int) $result : null;
	}

	/**
	 * Set or update a slug translation.
	 *
	 * @param string $object_type    Object type ('post', 'term', etc.).
	 * @param string $object_subtype Sub-type (post_type for posts, taxonomy
	 *                               for terms). Empty string for non-WP
	 *                               object kinds.
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

		// Race-safe write loop. Slug-collision auto-suffix WITHIN the same
		// (language, object_type, object_subtype) namespace is normally
		// resolved by find_unique_slug() — but between its SELECT and our
		// INSERT/UPDATE, a concurrent worker can claim the same candidate.
		// The `UNIQUE KEY slug_lookup` catches that at the DB level as a
		// duplicate-key error; we retry with a freshly resolved candidate
		// so the caller still gets a successful write instead of a silent
		// false. Bounded to 3 attempts so a pathological state can't loop.
		//
		// Cross-namespace coincidences (different object_subtype) are
		// allowed at the DB level and never trip this retry path.
		$result = false;

		// A duplicate-key rejection is an EXPECTED outcome here — the loop
		// provokes it deliberately to detect a slug another worker just
		// claimed, then retries with a fresh candidate. Left unsuppressed,
		// wpdb prints each one as a "WordPress database error" (visible with
		// WP_DEBUG_DISPLAY on, and written to the debug log either way), so a
		// bulk term import would litter the log with errors the plugin has
		// already handled. last_error is still populated, so the attribution
		// below and report_write_failure() are unaffected. Restored to the
		// caller's prior setting on every exit path.
		$was_suppressing = $this->wpdb->suppress_errors( true );

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$candidate = $this->find_unique_slug( $object_type, $object_subtype, $object_id, $language_id, $slug );

			// Check if a row already exists for this object (we'd UPDATE)
			// or not (we'd INSERT). Re-check on each retry — a concurrent
			// worker may have just inserted our row.
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

			// Clear last_error before the write so we can attribute a
			// failure cleanly to THIS query.
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
						'object_subtype' => sanitize_key( $object_subtype ),
						'object_id'      => $object_id,
						'language_id'    => $language_id,
						'slug'           => $candidate,
					],
					[ '%s', '%s', '%d', '%d', '%s' ]
				);
			}

			if ( $result !== false ) {
				// Hand the resolved candidate back to the downstream
				// caches-and-flags block below.
				$slug = $candidate;
				break;
			}

			// Only retry on a UNIQUE-violation. Other errors (table
			// missing, connection lost, etc.) won't be fixed by another
			// pass — bail immediately so we don't burn cycles on a real
			// fault.
			if ( strpos( (string) $this->wpdb->last_error, 'Duplicate entry' ) === false ) {
				break;
			}
		}

		$this->wpdb->suppress_errors( $was_suppressing );

		if ( $result === false ) {
			// Distinguish "every suffix in the namespace is taken" from a real
			// fault (missing table, lost connection) — the remedies differ.
			$db_error = (string) $this->wpdb->last_error;
			$this->report_write_failure(
				str_contains( $db_error, 'Duplicate entry' ) ? 'duplicate_exhausted' : 'db_error',
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

		// Invalidate cache.
		$cache_key = "slug_{$object_type}_{$object_id}_{$language_id}";
		$this->cache->delete( $cache_key, 'perflocale_slugs' );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $result !== false ) {
			// We just wrote a slug row, so the blog is no longer in the
			// zero-state. Pin TRUE everywhere so the next has_any_slugs()
			// call short-circuits without any DB hit.
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
	 * Report a slug write that did not happen.
	 *
	 * A failed write is not fatal — the object keeps its untranslated slug and
	 * its URL still resolves — but it used to be completely silent: set_slug()
	 * returned false and every caller discarded it, so a site could lose slug
	 * translations with nothing to show for it. Signal it instead:
	 *
	 *  - an action, so an integrator or a bulk job can react or tally;
	 *  - a log line, but only under WP_DEBUG. A broken table during a 10k-term
	 *    import would otherwise write 10k lines into a production log.
	 *
	 * The wpdb error text is deliberately confined to the log and the action
	 * payload; it is never surfaced to a screen.
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
				isset( $context['db_error'] ) && '' !== $context['db_error'] ? ' — ' . $context['db_error'] : ''
			)
		);
	}

	/**
	 * Characters of `slug` covered by the UNIQUE slug_lookup index.
	 *
	 * Mirrors `slug(191)` in Schema.php. 191 utf8mb4 characters is 764 bytes,
	 * one under InnoDB's 767-byte per-column key limit on legacy row formats,
	 * and the same prefix core uses for wp_posts.post_name.
	 */
	private const SLUG_INDEX_PREFIX = 191;

	/**
	 * Resolve a collision-free slug within a single (language, object_type,
	 * object_subtype) namespace.
	 *
	 * Mirrors WordPress's `wp_unique_post_slug()` logic — if the desired
	 * slug already belongs to a DIFFERENT object in the same language +
	 * object_type + object_subtype, append `-2`, `-3`, … until an unused
	 * slug is found. Cross-namespace collisions (e.g. category/uncategorized
	 * and product_cat/uncategorized both translating to "sin-categoria"
	 * in Spanish) are NOT conflicts here — different subtypes are distinct
	 * URL spaces.
	 *
	 * Caps the search at 99 attempts to bound cold-path cost.
	 *
	 * @param string $object_type    Object type (post / term / etc.).
	 * @param string $object_subtype Sub-type (post_type / taxonomy).
	 * @param int    $object_id      The object we're assigning the slug to.
	 *                               A collision with this same object_id is
	 *                               NOT a conflict — we're just updating
	 *                               its existing slug to the same value.
	 * @param int    $language_id    Language ID.
	 * @param string $slug           Desired slug (already sanitized).
	 * @return string Collision-free slug. Falls back to the input if 99
	 *                attempts exhaust without finding a free slot.
	 */
	private function find_unique_slug( string $object_type, string $object_subtype, int $object_id, int $language_id, string $slug ): string {
		$candidate = $slug;

		for ( $i = 2; $i <= 100; $i++ ) {
			// UNIQUE slug_lookup indexes slug(191), so the constraint compares
			// only the first 191 characters. This resolver has to agree with it
			// or it declares "no conflict" for a slug the INSERT then rejects,
			// and set_slug() returns false with nothing written. WordPress
			// produces exactly that shape: wp_unique_post_slug() appends "-2" to
			// an already-198-character slug.
			//
			// Two index-friendly forms, chosen so the slug column is never
			// wrapped in a function (LEFT(slug, 191) = ? is exact but
			// unsargable — measured 44.8 ms scanning 20k rows versus 0.07 ms
			// for the forms below):
			//
			// - shorter than the prefix: LEFT(stored,191) can only equal a
			// sub-191 candidate when stored IS that candidate, so an exact
			// match is provably equivalent.
			// - at or beyond the prefix: stored conflicts exactly when it
			// starts with the candidate's first 191 characters, which is a
			// sargable LIKE 'prefix%' range scan.
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

			// The suffix has to land INSIDE the indexed prefix or the next
			// candidate collides identically — appending past character 191
			// leaves the compared prefix unchanged. Trimming the base to make
			// room is what core's _truncate_post_slug() does.
			$suffix    = '-' . $i;
			$base      = mb_substr( $slug, 0, max( 1, self::SLUG_INDEX_PREFIX - mb_strlen( $suffix ) ) );
			$candidate = $base . $suffix;
		}

		// Exhausted the search. Return the last candidate and let the
		// downstream INSERT/UPDATE proceed — soft-fail: the caller still gets a
		// slug back, just one that might clash on a pathological site.
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

		// Always go through prepare() - even when $query_args has no
		// user-supplied values, the LIMIT placeholder gives prepare() a
		// real argument to bind. Defends against a future maintainer
		// adding an interpolated condition into $where without args.
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
				'object_subtype' => sanitize_key( $data['object_subtype'] ?? '' ),
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
