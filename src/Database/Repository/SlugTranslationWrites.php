<?php
/**
 * Slug translation writes and the repository's row API.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Schema;
use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SlugTranslationRepository's code a page view never runs, loaded at the first call of one of its forwarders.
 *
 * @internal
 */
final class SlugTranslationWrites {

	/**
	 * Characters of `slug` the UNIQUE slug_lookup index covers (`slug(191)` in Schema; 764 bytes, under InnoDB's 767).
	 */
	private const SLUG_INDEX_PREFIX = 191;

	/**
	 * The full table name for the current blog, computed per call so switch_to_blog() is followed.
	 *
	 * @return string
	 */
	private static function table(): string {
		return Schema::table( 'slug_translations' );
	}

	/**
	 * Set or update a slug translation.
	 *
	 * @param \wpdb        $wpdb           Database.
	 * @param CacheManager $cache          Cache manager.
	 * @param bool|null    $memo           The repository's has_any_slugs() memo, set to true on a write.
	 * @param string       $object_type    Object type ('post', 'term', etc.).
	 * @param string       $object_subtype Sub-type (post_type for posts, taxonomy for terms); '' for other kinds.
	 * @param int          $object_id      Object ID.
	 * @param int          $language_id    Language ID.
	 * @param string       $slug           Translated slug.
	 * @return bool
	 */
	public static function set_slug( \wpdb $wpdb, CacheManager $cache, ?bool &$memo, string $object_type, string $object_subtype, int $object_id, int $language_id, string $slug ): bool {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$slug = sanitize_title( $slug );

		if ( $slug === '' ) {
			self::report_write_failure(
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
		$was_suppressing = $wpdb->suppress_errors( true );

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$candidate = self::find_unique_slug( $wpdb, $object_type, $object_subtype, $object_id, $language_id, $slug );

			// Does this object have a row (UPDATE) or not (INSERT)? Checked on each retry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i
					WHERE object_type = %s AND object_id = %d AND language_id = %d',
					self::table(),
					$object_type,
					$object_id,
					$language_id
				)
			);

			// Cleared so a failure is this query's.
			$wpdb->last_error = '';

			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					self::table(),
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
				$result = $wpdb->insert(
					self::table(),
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
			if ( ! Schema::is_duplicate_key_error( (string) $wpdb->last_error ) ) {
				break;
			}
		}

		$wpdb->suppress_errors( $was_suppressing );

		if ( $result === false ) {
			// "Every suffix taken" apart from a real fault: the remedies differ.
			$db_error = (string) $wpdb->last_error;
			self::report_write_failure(
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
		$cache->delete( $cache_key, 'perflocale_slugs' );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $result !== false ) {
			// A slug row exists now: pin true everywhere.
			$memo = true;
			$cache->set_static( 'has_any_slugs', 1, 'perflocale_trans' );

			if ( $cache->l2_enabled() ) {
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
	private static function report_write_failure( string $reason, array $context ): void {
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
	 * @param \wpdb  $wpdb           Database.
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
	private static function find_unique_slug( \wpdb $wpdb, string $object_type, string $object_subtype, int $object_id, int $language_id, string $slug ): string {
		$candidate = $slug;

		for ( $i = 2; $i <= 100; $i++ ) {
			// The index compares the first 191 characters, so the check must too, without wrapping the column in a function:
			// an exact match below the prefix, a sargable LIKE 'prefix%' at or beyond it.
			if ( mb_strlen( $candidate ) >= self::SLUG_INDEX_PREFIX ) {
				$slug_clause = 'AND slug LIKE %s';
				$slug_value  = $wpdb->esc_like( mb_substr( $candidate, 0, self::SLUG_INDEX_PREFIX ) ) . '%';
			} else {
				$slug_clause = 'AND slug = %s';
				$slug_value  = $candidate;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$conflicting_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT object_id FROM %i
					WHERE language_id = %d
					  AND object_type = %s
					  AND object_subtype = %s
					  ' . $slug_clause . '
					  AND object_id != %d
					LIMIT 1',
					self::table(),
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
	 * One row by id.
	 *
	 * @param \wpdb $wpdb Database.
	 * @param int   $id   Row id.
	 * @return object|null
	 */
	public static function find( \wpdb $wpdb, int $id ): ?object {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::table(),
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Up to 1000 rows, optionally by object_type and language_id.
	 *
	 * @param \wpdb                $wpdb Database.
	 * @param array<string, mixed> $args Filters.
	 * @return array<int, object>
	 */
	public static function find_all( \wpdb $wpdb, array $args ): array {
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
		$sql          = $wpdb->prepare( $sql, array_merge( [ self::table() ], $query_args ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $sql );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $results ) ? $results : [];
	}

	/**
	 * Insert one row.
	 *
	 * @param \wpdb                $wpdb Database.
	 * @param array<string, mixed> $data Row.
	 * @return int|false New id.
	 */
	public static function insert( \wpdb $wpdb, array $data ): int|false {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			self::table(),
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

		return $result !== false ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update one row's slug.
	 *
	 * @param \wpdb                $wpdb Database.
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Fields.
	 * @return bool
	 */
	public static function update( \wpdb $wpdb, int $id, array $data ): bool {
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
		$result = $wpdb->update(
			self::table(),
			$update,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Delete one row.
	 *
	 * @param \wpdb $wpdb Database.
	 * @param int   $id   Row id.
	 * @return bool
	 */
	public static function delete( \wpdb $wpdb, int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			self::table(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}
}
