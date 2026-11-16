<?php
/**
 * Slug translation writes and the repository's row API.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database\Repository;

use PerfLocale\Admin\ImportLock;
use PerfLocale\Admin\Typed;
use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\ConnectionGuard;
use PerfLocale\Database\Schema;
use PerfLocale\Database\Txn;
use PerfLocale\Helper;
use PerfLocale\Plugin;
use PerfLocale\Util\Utf8;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SlugTranslationRepository's code a page view never runs, loaded at the first call of one of its forwarders.
 *
 * @internal
 * @phpstan-type TermWrite array{ok: bool, error: string, conflict_term_id: int|null, notes: array<int, string>, slug: string, changed: array<int, int>}
 */
final class SlugTranslationWrites {

	/**
	 * Characters of `slug` the UNIQUE slug_lookup index covers (`slug(191)` in Schema; 764 bytes, under InnoDB's 767).
	 */
	private const SLUG_INDEX_PREFIX = 191;

	/**
	 * A term's current URL slug and its old ones, newest first (section 4, Q12: no schema change).
	 */
	public const TERM_TYPES = [ 'term', 'term_h1', 'term_h2', 'term_h3', 'term_h4', 'term_h5' ];

	/**
	 * Option of the orphan history sweep's cursor: slot (1-5, term_h<slot>) and the last term id read.
	 */
	public const HISTORY_SWEEP_OPTION = 'perflocale_term_history_sweep';

	/**
	 * Term ids one statement of the orphan history sweep reads.
	 */
	private const HISTORY_SWEEP_BATCH = 250;

	/**
	 * A busy lock was met in this request: later creation writes try once.
	 *
	 * @var bool
	 */
	private static bool $busy_seen = false;

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
	 * @internal Also used by the term URL slug writers.
	 * @param string               $reason  One of: empty_slug, duplicate_exhausted, db_error, conflict, busy.
	 * @param array<string, mixed> $context Object + language identifiers and the slug attempted.
	 * @return void
	 */
	public static function report_write_failure( string $reason, array $context ): void {
		/**
		 * Fires when a slug translation could not be written.
		 *
		 * @hook perflocale/slug/write_failed
		 *
		 * @param string               $reason  Failure reason: 'empty_slug', 'duplicate_exhausted', 'db_error', 'conflict' (a term URL slug
		 *                                      another term of the language uses) or 'busy' (an import or another URL-slug change ran).
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
	 * Write a term's URL slug in a language under the import lock, refresh its caches after the commit, report a failure.
	 *
	 * @param string      $taxonomy    Taxonomy.
	 * @param int         $term_id     Term.
	 * @param int         $language_id Language.
	 * @param string      $slug        URL slug.
	 * @param bool        $insert_only Keep an existing display slug (creation writes, backfill).
	 * @param int         $tries       Attempts to take the lock.
	 * @param int         $wait_ms     Pause between attempts.
	 * @param string|null $expect      Write only while the current slug is this one (error `stale` otherwise).
	 * @return TermWrite
	 */
	public static function write_term_url_slug( string $taxonomy, int $term_id, int $language_id, string $slug, bool $insert_only, int $tries, int $wait_ms, ?string $expect = null ): array {
		$wpdb   = Typed::wpdb();
		$result = ImportLock::with_slug_write(
			static fn( ?ConnectionGuard $guard ): array => self::set_term_url_slug( $wpdb, $guard, $taxonomy, $term_id, $language_id, $slug, $insert_only, $expect ),
			$insert_only && self::$busy_seen ? 1 : $tries,
			$wait_ms
		);

		if ( false === $result ) {
			self::$busy_seen = true;

			// A creation write retries after the import (cold class, out of the classmap).
			if ( $insert_only ) {
				require_once __DIR__ . '/../../Translation/TermSlugRetry.php';
				\PerfLocale\Translation\TermSlugRetry::queue( $taxonomy, $term_id, $language_id, $slug );
			}
		}

		$out = self::outcome( Typed::map( false === $result ? [ 'error' => 'busy' ] : $result ), $slug );

		if ( $out['ok'] ) {
			self::refresh_term_caches( $out['changed'], $language_id );
		} elseif ( 'empty' !== $out['error'] && 'stale' !== $out['error'] ) {
			self::report_write_failure(
				$out['error'],
				[
					'object_type'    => 'term',
					'object_subtype' => $taxonomy,
					'object_id'      => $term_id,
					'language_id'    => $language_id,
					'slug'           => $slug,
				]
			);
		}

		return $out;
	}

	/**
	 * After a term's language move: its URL slug rows leave every other language, and the old language's URL slug (or
	 * the DB slug, when another term holds it in the new language) goes to the new one, with the first free "-N".
	 * $old_lang 0 (a first language, or a DB slug rename without a URL slug): only the DB slug check, read without the lock.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term.
	 * @param int    $old_lang Language it left, or 0.
	 * @param int    $new_lang Language it joined.
	 * @param string $db_slug  Its DB slug.
	 * @return TermWrite `slug` '' when nothing was written; `conflict_term_id` holds the slug the term did not get.
	 */
	public static function move_term_url_slug( string $taxonomy, int $term_id, int $old_lang, int $new_lang, string $db_slug ): array {
		$wpdb = Typed::wpdb();

		// An unread holder (null) is not "free": the locked path reads it again, checked.
		if ( 0 === $old_lang && 0 === self::held_by_row( $wpdb, $taxonomy, $new_lang, self::term_slug( $db_slug ), $term_id ) ) {
			return self::outcome(
				[
					'ok'   => true,
					'slug' => '',
				],
				''
			);
		}

		$result = ImportLock::with_slug_write(
			static function ( ?ConnectionGuard $guard ) use ( $wpdb, $taxonomy, $term_id, $old_lang, $new_lang, $db_slug ): array {
				$table = self::table();
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$now = $wpdb->get_var( $wpdb->prepare( "SELECT language_id FROM %i WHERE type = 'term' AND object_id = %d LIMIT 1", Schema::table( 'translation_links' ), $term_id ) );

				if ( self::failed( $wpdb ) ) {
					return [
						'ok'    => false,
						'error' => 'db_error',
					];
				}

				// A later move of this term owns its rows.
				if ( ! is_numeric( $now ) || (int) $now !== $new_lang ) {
					return [
						'ok'   => true,
						'slug' => '',
					];
				}

				$old = $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM %i WHERE object_type = 'term' AND object_id = %d AND language_id = %d", $table, $term_id, $old_lang ) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				// Unread is not "no URL slug": the DELETE below would drop it and its history (CODING-RULES A).
				if ( self::failed( $wpdb ) ) {
					return [
						'ok'    => false,
						'error' => 'db_error',
					];
				}

				if ( ! self::guarded( $wpdb, $guard, $wpdb->prepare( "DELETE FROM %i WHERE object_type IN ('term','term_h1','term_h2','term_h3','term_h4','term_h5') AND object_id = %d AND language_id <> %d", $table, $term_id, $new_lang ) ) ) {
					return [
						'ok'    => false,
						'error' => 'db_error',
					];
				}

				$want  = is_string( $old ) && '' !== $old ? $old : $db_slug;
				$first = null;
				$i     = 1;

				// Without an old URL slug the DB slug serves, unless another term holds it here.
				if ( $want === $db_slug ) {
					$first = self::taken_by( $wpdb, $taxonomy, $new_lang, self::term_slug( $want ), $term_id );

					if ( null === $first ) {
						return [
							'ok'    => false,
							'error' => 'db_error',
						];
					}

					if ( 0 === $first ) {
						return [
							'ok'   => true,
							'slug' => '',
						];
					}

					$i = 2;
				}

				for ( ; $i <= 100; $i++ ) {
					// The suffix lands inside the indexed prefix, as find_unique_slug().
					$slug = 1 === $i ? $want : self::truncate_to_prefix( self::term_slug( $want ), self::SLUG_INDEX_PREFIX - strlen( '-' . $i ) ) . '-' . $i;
					$r    = self::set_term_url_slug( $wpdb, $guard, $taxonomy, $term_id, $new_lang, $slug, true );

					if ( 'conflict' !== $r['error'] ) {
						$r['conflict_term_id'] = $first;

						return $r;
					}

					$first ??= $r['conflict_term_id'];
				}

				return [
					'ok'               => false,
					'error'            => 'conflict',
					'conflict_term_id' => $first,
				];
			},
			3,
			200
		);

		$out = self::outcome( Typed::map( false === $result ? [ 'error' => 'busy' ] : $result ), '' );

		if ( $out['ok'] ) {
			if ( $old_lang > 0 ) {
				self::refresh_term_caches( [ $term_id ], $old_lang );
			}
			self::refresh_term_caches( $out['changed'], $new_lang );
		} else {
			self::report_write_failure(
				$out['error'],
				[
					'object_type'    => 'term',
					'object_subtype' => $taxonomy,
					'object_id'      => $term_id,
					'language_id'    => $new_lang,
					'slug'           => $db_slug,
				]
			);
		}

		return $out;
	}

	/**
	 * Insert-only term URL slugs for many terms (backfill): 200 terms per lock and transaction (3 tries each), so imports
	 * and edits get the lock between batches; each refusal reported, a busy batch stops the run.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}>      $items [ taxonomy, term id, language id, slug ].
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}>|null $left  Set to the items a busy lock left unwritten.
	 * @param-out array<int, array{0: string, 1: int, 2: int, 3: string}> $left
	 * @return int Rows inserted.
	 */
	public static function backfill_term_url_slugs( array $items, ?array &$left = null ): int {
		$wpdb    = Typed::wpdb();
		$count   = 0;
		$left    = [];
		$batches = array_chunk( $items, 200 );

		foreach ( $batches as $i => $batch ) {
			$written = [];
			$failed  = [];
			$n       = 0;
			$result  = ImportLock::with_slug_write(
				static function ( ?ConnectionGuard $guard ) use ( $wpdb, $batch, &$written, &$failed, &$n ): array {
					foreach ( $batch as [ $taxonomy, $term_id, $language_id, $slug ] ) {
						$r = self::set_term_url_slug( $wpdb, $guard, $taxonomy, $term_id, $language_id, $slug, true );

						if ( ! $r['ok'] ) {
							$failed[] = [ $r['error'], $taxonomy, $term_id, $language_id, $slug ];
						} elseif ( in_array( $term_id, $r['changed'], true ) ) {
							++$n;
							$written[ $language_id ] = array_merge( $written[ $language_id ] ?? [], $r['changed'] );
						}
					}

					return [ 'ok' => true ];
				},
				3
			);

			if ( false === $result ) {
				$failed = [ [ 'busy', '', 0, 0, '' ] ];
			}

			foreach ( $failed as [ $error, $taxonomy, $term_id, $language_id, $slug ] ) {
				if ( 'empty' !== $error ) {
					self::report_write_failure(
						$error,
						[
							'object_type'    => 'term',
							'object_subtype' => $taxonomy,
							'object_id'      => $term_id,
							'language_id'    => $language_id,
							'slug'           => $slug,
						]
					);
				}
			}

			if ( false === $result || empty( $result['ok'] ) ) {
				$left = array_merge( ...array_slice( $batches, $i ) );
				break;
			}

			$count += $n;

			foreach ( $written as $language_id => $ids ) {
				self::refresh_term_caches( array_values( array_unique( $ids ) ), $language_id );
			}
		}

		return $count;
	}

	/**
	 * A term URL slug write's result in its one shape.
	 *
	 * @param array<array-key, mixed> $r    Partial result.
	 * @param string                  $slug Slug written.
	 * @return TermWrite
	 */
	private static function outcome( array $r, string $slug ): array {
		$ok = true === ( $r['ok'] ?? null );

		return [
			'ok'               => $ok,
			'error'            => $ok ? '' : ( Typed::str( $r['error'] ?? '' ) ?: 'db_error' ),
			'conflict_term_id' => isset( $r['conflict_term_id'] ) ? Typed::int( $r['conflict_term_id'] ) : null,
			'notes'            => Typed::strs( $r['notes'] ?? [] ),
			'slug'             => Typed::str( $r['slug'] ?? $slug ),
			'changed'          => Typed::ints( $r['changed'] ?? [] ),
		];
	}

	/**
	 * Strict write of a term's URL slug (section 4 rules 1-5): a slug another term of the namespace uses is refused, never
	 * suffixed. Runs inside ImportLock::with_slug_write(); the caller commits and refreshes the caches.
	 *
	 * @param \wpdb                $wpdb        Database.
	 * @param ConnectionGuard|null $guard       The write unit's guard, null inside another owner's transaction.
	 * @param string               $taxonomy    Taxonomy (object_subtype).
	 * @param int                  $term_id     Term.
	 * @param int                  $language_id Language.
	 * @param string               $slug        Requested slug.
	 * @param bool                 $insert_only Keep an existing display slug.
	 * @param string|null          $expect      Write only while the current slug is this one (error `stale` otherwise).
	 * @return TermWrite
	 */
	public static function set_term_url_slug( \wpdb $wpdb, ?ConnectionGuard $guard, string $taxonomy, int $term_id, int $language_id, string $slug, bool $insert_only = false, ?string $expect = null ): array {
		$slug    = self::term_slug( $slug );
		$notes   = [];
		$changed = [ $term_id ];
		$fail    = static fn( string $error, ?int $conflict = null ): array => self::outcome(
			[
				'error'            => $error,
				'conflict_term_id' => $conflict,
			],
			$slug
		);

		if ( '' === $slug ) {
			return $fail( 'empty' );
		}

		// An encoded slash, backslash, control byte or dot segment breaks the link and its old-slug redirect.
		if ( \PerfLocale\Router\UrlBases\Validator::guard_hit( \PerfLocale\Router\UrlBases\Validator::norm( $slug ) ) ) {
			return $fail( 'bad_characters' );
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cur = $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM %i WHERE object_type = 'term' AND object_id = %d AND language_id = %d", $table, $term_id, $language_id ) );

		if ( self::failed( $wpdb ) ) {
			return $fail( 'db_error' );
		}

		$cur = is_string( $cur ) ? $cur : null;

		if ( null !== $expect && $cur !== $expect ) {
			return $fail( 'stale' );
		}

		if ( $cur === $slug || ( $insert_only && null !== $cur ) ) {
			return self::outcome( [ 'ok' => true ], $slug );
		}

		$taken = self::taken_by( $wpdb, $taxonomy, $language_id, $slug, $term_id );

		if ( null === $taken ) {
			return $fail( 'db_error' );
		}

		if ( $taken > 0 ) {
			return $fail( 'conflict', $taken );
		}

		if ( ! self::delete_dead_rows( $wpdb, $guard, $taxonomy, $language_id, $slug, $term_id ) ) {
			return $fail( 'db_error' );
		}

		$holders = self::history_holders( $wpdb, $taxonomy, $language_id, $slug );

		if ( null === $holders ) {
			return $fail( 'db_error' );
		}

		// Rule 1: the new current slug stops being anyone's old slug.
		foreach ( $holders as $holder ) {
			if ( $holder !== $term_id ) {
				$changed[] = $holder;
				/* translators: 1: URL slug, 2: term ID. */
				$notes[] = sprintf( __( 'The old URL slug "%1$s" of term %2$d no longer redirects: it is this term\'s URL slug now.', 'perflocale' ), $slug, $holder );
			}
		}

		if ( ! self::delete_history( $wpdb, $guard, $taxonomy, $language_id, $slug ) ) {
			return $fail( 'db_error' );
		}

		if ( null !== $cur ) {
			$kept = self::keep_old_slug( $wpdb, $guard, $taxonomy, $term_id, $language_id, $cur, $notes, $changed );

			// Rule 3: rotation, oldest dropped, so neither unique key is hit on the way.
			if ( null === $kept || ( $kept && ( ! self::guarded( $wpdb, $guard, $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'term_h5' AND object_id = %d AND language_id = %d", $table, $term_id, $language_id ) )
				|| ! self::rotate( $wpdb, $guard, $term_id, $language_id )
				|| ! self::insert_row( $wpdb, $guard, 'term_h1', $taxonomy, $term_id, $language_id, $cur ) ) ) ) {
				return $fail( 'db_error' );
			}

			$saved = self::guarded( $wpdb, $guard, $wpdb->prepare( "UPDATE %i SET slug = %s WHERE object_type = 'term' AND object_id = %d AND language_id = %d", $table, $slug, $term_id, $language_id ) );
		} else {
			$saved = self::insert_row( $wpdb, $guard, 'term', $taxonomy, $term_id, $language_id, $slug );
		}

		if ( ! $saved ) {
			// Another writer took the slug between the check and the write (only outside the import lock).
			return $fail( Schema::is_duplicate_key_error( $wpdb->last_error ) ? 'conflict' : 'db_error' );
		}

		return self::outcome(
			[
				'ok'      => true,
				'notes'   => $notes,
				'changed' => array_values( array_unique( $changed ) ),
			],
			$slug
		);
	}

	/**
	 * Delete other terms' current rows that hold $slug but answer no URL: of a deleted term, or of one linked in another
	 * language. They still hold the unique key.
	 *
	 * @param \wpdb                $wpdb        Database.
	 * @param ConnectionGuard|null $guard       Unit guard.
	 * @param string               $taxonomy    Taxonomy.
	 * @param int                  $language_id Language.
	 * @param string               $slug        Slug.
	 * @param int                  $term_id     The term being written.
	 * @return bool
	 */
	private static function delete_dead_rows( \wpdb $wpdb, ?ConnectionGuard $guard, string $taxonomy, int $language_id, string $slug, int $term_id ): bool {
		$table             = self::table();
		[ $match, $value ] = self::match( $wpdb, $slug );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::guarded( $wpdb, $guard, $wpdb->prepare( "DELETE FROM %i WHERE language_id = %d AND object_subtype = %s AND object_type = 'term' AND slug {$match} AND object_id <> %d AND ( NOT EXISTS ( SELECT 1 FROM %i t WHERE t.term_id = object_id ) OR EXISTS ( SELECT 1 FROM %i l WHERE l.type = 'term' AND l.object_id = %i.object_id AND l.language_id <> %d ) )", $table, $language_id, $taxonomy, $value, $term_id, $wpdb->terms, Schema::table( 'translation_links' ), $table, $language_id ) );
	}

	/**
	 * A term URL slug as stored: sanitize_title(), cut to the column's 200 bytes without splitting an escape.
	 *
	 * @param string $slug Requested or imported slug.
	 * @return string
	 */
	private static function term_slug( string $slug ): string {
		return Helper::truncate_slug( sanitize_title( $slug ), 200 );
	}

	/**
	 * Restore imported term URL slug rows (section 4): the final namespace state is computed first (the file wins per slot,
	 * old slugs merged newest first, rules 1, 2 and 5, at most 5), then written. CALLER writes inside the caller's open
	 * transaction (through its guard); OWN opens one transaction per (language, taxonomy) under the held import lock.
	 *
	 * @param array<int, array<string, mixed>> $rows  slug_translations rows of the TERM_TYPES, file order.
	 * @param Txn                              $mode  Transaction owner.
	 * @param ConnectionGuard|null             $guard The caller's guard (CALLER).
	 * @return array{imported: int, skipped: int, dropped: int, written: array<int, string>, notes: array<int, string>}
	 * @throws \RuntimeException On a failed statement (CALLER: the caller rolls back) or a lost connection.
	 */
	public static function restore_term_slugs( array $rows, Txn $mode, ?ConnectionGuard $guard = null ): array {
		$wpdb   = \PerfLocale\Admin\Typed::wpdb();
		$result = [
			'imported' => 0,
			'skipped'  => 0,
			'dropped'  => 0,
			'written'  => [],
			'notes'    => [],
		];
		$spaces = [];

		foreach ( $rows as $row ) {
			$slot = array_search( $row['object_type'] ?? null, self::TERM_TYPES, true );

			// The file is not trusted to hold stored-form slugs (WP-CLI and unfiltered_html imports skip the row cleaning).
			$slug = is_string( $row['slug'] ?? null ) ? self::term_slug( $row['slug'] ) : '';

			if ( false === $slot || '' === $slug || \PerfLocale\Router\UrlBases\Validator::guard_hit( \PerfLocale\Router\UrlBases\Validator::norm( $slug ) ) ) {
				++$result['skipped'];
				continue;
			}

			$spaces[ Typed::int( $row['language_id'] ?? 0 ) . '|' . Typed::str( $row['object_subtype'] ?? '' ) ][] = [ (int) $slot, Typed::int( $row['object_id'] ?? 0 ), $slug ];
		}

		foreach ( $spaces as $space => $entries ) {
			[ $language_id, $taxonomy ] = explode( '|', (string) $space, 2 );

			if ( Txn::CALLER === $mode ) {
				self::restore_space( $wpdb, $guard, (int) $language_id, $taxonomy, $entries, $result );
				continue;
			}

			// Between transactions, never inside one.
			ImportLock::heartbeat();
			$own = new ConnectionGuard( 'slug_restore' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $own->begin() || false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'restore_term_slugs: no transaction' );
			}

			try {
				self::restore_space( $wpdb, $own, (int) $language_id, $taxonomy, $entries, $result );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( ! $own->verify() || false === $wpdb->query( 'COMMIT' ) || ! $own->kept() ) {
					throw new \RuntimeException( 'restore_term_slugs: commit failed' );
				}
			} catch ( \Throwable $e ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				throw $e;
			} finally {
				$own->end();
			}
		}

		return $result;
	}

	/**
	 * One namespace of restore_term_slugs(): plan in memory, then delete and insert the changed terms' rows.
	 *
	 * @param \wpdb                                     $wpdb        Database.
	 * @param ConnectionGuard|null                      $guard       Unit guard.
	 * @param int                                       $language_id Language.
	 * @param string                                    $taxonomy    Taxonomy.
	 * @param array<int, array{0: int, 1: int, 2: string}> $entries  [ slot, term id, slug ] in file order.
	 * @param array{imported: int, skipped: int, dropped: int, written: array<int, string>, notes: array<int, string>} $result Totals (updated).
	 * @return void
	 * @throws \RuntimeException On a failed statement.
	 */
	private static function restore_space( \wpdb $wpdb, ?ConnectionGuard $guard, int $language_id, string $taxonomy, array $entries, array &$result ): void {
		$table = self::table();
		$have  = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT st.object_id, st.object_type, st.slug, t.term_id AS live FROM %i st LEFT JOIN %i t ON t.term_id = st.object_id WHERE st.language_id = %d AND st.object_subtype = %s AND st.object_type IN ('term','term_h1','term_h2','term_h3','term_h4','term_h5') ORDER BY st.object_type", $table, $wpdb->terms, $language_id, $taxonomy ), ARRAY_A );

		if ( self::failed( $wpdb ) ) {
			throw new \RuntimeException( 'restore_term_slugs: read failed' );
		}

		// The file's rows per term: current, and old slugs by slot.
		$file = [];

		foreach ( $entries as [ $slot, $term_id, $slug ] ) {
			if ( isset( $file[ $term_id ][ $slot ] ) ) {
				++$result['skipped'];
				continue;
			}

			$file[ $term_id ][ $slot ] = $slug;
		}

		// Rows of deleted terms outside the file are no owners (removed below); the file's own terms keep theirs.
		foreach ( Typed::rows( $existing ) as $r ) {
			$id = Typed::int( $r['object_id'] ?? 0 );

			if ( null !== ( $r['live'] ?? null ) || isset( $file[ $id ] ) ) {
				$have[ $id ][ (int) array_search( $r['object_type'] ?? '', self::TERM_TYPES, true ) ] = Typed::str( $r['slug'] ?? '' );
			}
		}

		// Current slugs: the file's wins for its term, unless a term outside the file (or earlier in it) holds it.
		$dropped = [];
		$final   = [];
		$owner  = [];
		$origin = [];

		foreach ( $have as $term_id => $slots ) {
			if ( ! isset( $file[ $term_id ] ) && isset( $slots[0] ) ) {
				$owner[ self::key( $slots[0] ) ] = $term_id;
			}
		}

		foreach ( $file as $term_id => $slots ) {
			$cur = $slots[0] ?? null;

			if ( null !== $cur && isset( $owner[ self::key( $cur ) ] ) ) {
				++$result['dropped'];
				$dropped[ $term_id ][ $cur ] = true;
				/* translators: 1: URL slug, 2: term ID. */
				$result['notes'][] = sprintf( __( 'The URL slug "%1$s" of term %2$d was not imported: another term of that language uses it.', 'perflocale' ), $cur, $term_id );
				$cur               = null;
			}

			$cur ??= $have[ $term_id ][0] ?? null;

			if ( null !== $cur && ! isset( $owner[ self::key( $cur ) ] ) ) {
				$owner[ self::key( $cur ) ] = $term_id;
				$final[ $term_id ][0]       = $cur;
			}

			// Old slugs this site serves first (a replaced current, then its own old ones), then the file's: a repeated
			// import gives the same rows and never drops a redirect this site has.
			$list = [];

			if ( isset( $have[ $term_id ][0] ) && self::key( $have[ $term_id ][0] ) !== self::key( (string) $cur ) ) {
				$list[] = [ $have[ $term_id ][0], false, true ];
			}

			foreach ( [ 1, 2, 3, 4, 5 ] as $slot ) {
				if ( isset( $have[ $term_id ][ $slot ] ) ) {
					$list[] = [ $have[ $term_id ][ $slot ], false ];
				}
			}

			foreach ( [ 1, 2, 3, 4, 5 ] as $slot ) {
				if ( isset( $slots[ $slot ] ) ) {
					$list[] = [ $slots[ $slot ], true ];
				}
			}

			$origin[ $term_id ] = $list;
		}

		// Terms outside the file keep their rows unless a rule removes an old slug.
		foreach ( $have as $term_id => $slots ) {
			if ( ! isset( $file[ $term_id ] ) ) {
				$final[ $term_id ] = $slots;
				unset( $final[ $term_id ][1], $final[ $term_id ][2], $final[ $term_id ][3], $final[ $term_id ][4], $final[ $term_id ][5] );
				$origin[ $term_id ] = array_map( static fn( string $v ): array => [ $v, false ], array_values( array_diff_key( $slots, [ 0 => true ] ) ) );
			}
		}

		// Rule 2: a live term this language reaches by its DB slug keeps it (the term's own DB slug: pointless).
		$candidates = [];

		foreach ( $origin as $list ) {
			foreach ( $list as [ $value ] ) {
				$candidates[ $value ] = true;
			}
		}

		$reached = self::reached_by_db_slug( $wpdb, $taxonomy, $language_id, array_keys( $candidates ), array_keys( $owner === [] ? [] : array_flip( $owner ) ) );

		if ( null === $reached ) {
			throw new \RuntimeException( 'restore_term_slugs: read failed' );
		}

		// Rules 1 and 5: no old slug equals a current one; each old slug once: a replaced live slug, then the file's.
		$claimed = [];
		$changed = array_fill_keys( array_keys( $file ), true );
		$order   = array_merge( array_keys( $file ), array_diff( array_keys( $origin ), array_keys( $file ) ) );

		foreach ( [ 'live', 'file', 'site' ] as $pass ) {
			foreach ( $order as $term_id ) {
				foreach ( $origin[ $term_id ] as $o ) {
					[ $value, $is_file ] = $o;

					if ( ( isset( $o[2] ) ? 'live' : ( $is_file ? 'file' : 'site' ) ) !== $pass ) {
						continue;
					}

					$key = self::key( $value );

					// The term's own copy of a value it already claimed: one slot, no drop.
					if ( ( $claimed[ $key ] ?? 0 ) === $term_id ) {
						continue;
					}

					if ( ! isset( $owner[ $key ] ) && ! isset( $claimed[ $key ] ) && ! isset( $reached[ $key ] ) ) {
						$claimed[ $key ] = $term_id;
						continue;
					}

					if ( $is_file ) {
						++$result['dropped'];
						$dropped[ $term_id ][ $value ] = true;
						/* translators: 1: URL slug, 2: term ID. */
						$result['notes'][] = sprintf( __( 'The old URL slug "%1$s" of term %2$d was not imported: another term of that language uses it.', 'perflocale' ), $value, $term_id );
					} elseif ( ! isset( $file[ $term_id ] ) ) {
						$changed[ $term_id ] = true;
					}
				}
			}
		}

		foreach ( $origin as $term_id => $list ) {
			$slot = 1;

			foreach ( $list as [ $value, $is_file ] ) {
				$key = self::key( $value );

				if ( ( $claimed[ $key ] ?? 0 ) !== $term_id ) {
					continue;
				}

				unset( $claimed[ $key ] );

				if ( $slot <= 5 ) {
					$final[ $term_id ][ $slot++ ] = $value;
				} elseif ( $is_file ) {
					++$result['dropped'];
					$dropped[ $term_id ][ $value ] = true;
					/* translators: 1: URL slug, 2: term ID. */
					$result['notes'][] = sprintf( __( 'The old URL slug "%1$s" of term %2$d was not imported: a term keeps at most 5 old URL slugs.', 'perflocale' ), $value, $term_id );
				}
			}
		}

		// Write: unchanged terms are left alone; the changed ones' rows are replaced (deletes first, so no key is hit).
		foreach ( array_keys( $changed ) as $term_id ) {
			$want = $final[ $term_id ] ?? [];
			ksort( $want );

			if ( $want === ( $have[ $term_id ] ?? [] ) ) {
				unset( $changed[ $term_id ] );
			}
		}

		// Rows of deleted terms outside the file still hold the unique keys: removed with the namespace's first write.
		$ids = array_keys( $file );

		if ( [] !== $changed && ! self::guarded( $wpdb, $guard, $wpdb->prepare( "DELETE FROM %i WHERE language_id = %d AND object_subtype = %s AND object_type IN ('term','term_h1','term_h2','term_h3','term_h4','term_h5') AND object_id NOT IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND NOT EXISTS ( SELECT 1 FROM %i t WHERE t.term_id = object_id )', array_merge( [ $table, $language_id, $taxonomy ], $ids, [ $wpdb->terms ] ) ) ) ) {
			throw new \RuntimeException( 'restore_term_slugs: delete failed' );
		}

		foreach ( array_keys( $changed ) as $term_id ) {
			if ( ! self::guarded( $wpdb, $guard, $wpdb->prepare( "DELETE FROM %i WHERE object_id = %d AND language_id = %d AND object_type IN ('term','term_h1','term_h2','term_h3','term_h4','term_h5')", $table, $term_id, $language_id ) ) ) {
				throw new \RuntimeException( 'restore_term_slugs: delete failed' );
			}
		}

		foreach ( array_keys( $changed ) as $term_id ) {
			foreach ( $final[ $term_id ] ?? [] as $slot => $value ) {
				if ( ! self::insert_row( $wpdb, $guard, self::TERM_TYPES[ $slot ], $taxonomy, $term_id, $language_id, $value ) ) {
					throw new \RuntimeException( 'restore_term_slugs: insert failed' );
				}
			}

			$result['written'][] = $language_id . '|' . $term_id;
		}

		// Each file row once: imported when it is in and new to its term, dropped (counted above), else skipped.
		foreach ( $file as $term_id => $slots ) {
			foreach ( $slots as $value ) {
				if ( isset( $dropped[ $term_id ][ $value ] ) ) {
					continue;
				}

				$new = isset( $changed[ $term_id ] ) && in_array( $value, $final[ $term_id ] ?? [], true ) && ! in_array( $value, $have[ $term_id ] ?? [], true );
				++$result[ $new ? 'imported' : 'skipped' ];
			}
		}
	}

	/**
	 * The term (other than $term_id) that keeps $slug from being a current URL slug in a namespace, or 0: another term's
	 * current slug (while that term is unlinked or linked in this language), or the DB slug of a live term the language
	 * reaches by it.
	 *
	 * @param \wpdb  $wpdb        Database.
	 * @param string $taxonomy    Taxonomy.
	 * @param int    $language_id Language.
	 * @param string $slug        Slug.
	 * @param int    $term_id     The term being written.
	 * @return int|null Null when a read failed.
	 */
	private static function taken_by( \wpdb $wpdb, string $taxonomy, int $language_id, string $slug, int $term_id ): ?int {
		$other = self::held_by_row( $wpdb, $taxonomy, $language_id, $slug, $term_id );

		if ( null === $other || $other > 0 ) {
			return $other;
		}

		$reached = self::reached_by_db_slug( $wpdb, $taxonomy, $language_id, [ $slug ], [ $term_id ] );

		return null === $reached ? null : (int) ( reset( $reached ) ?: 0 );
	}

	/**
	 * Another live term whose current URL slug row in the namespace answers $slug (unlinked or linked there), else 0.
	 *
	 * @param \wpdb  $wpdb        Database.
	 * @param string $taxonomy    Taxonomy.
	 * @param int    $language_id Language.
	 * @param string $slug        Slug.
	 * @param int    $term_id     The term being written.
	 * @return int|null Null when the read failed.
	 */
	private static function held_by_row( \wpdb $wpdb, string $taxonomy, int $language_id, string $slug, int $term_id ): ?int {
		[ $match, $value ] = self::match( $wpdb, $slug );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $match is 'LIKE %s' or '= %s' from match().
		$other = $wpdb->get_var( $wpdb->prepare( "SELECT st.object_id FROM %i st INNER JOIN %i t ON t.term_id = st.object_id WHERE st.language_id = %d AND st.object_type = 'term' AND st.object_subtype = %s AND st.slug {$match} AND st.object_id <> %d AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.type = 'term' AND l.object_id = st.object_id AND l.language_id <> st.language_id ) LIMIT 1", self::table(), $wpdb->terms, $language_id, $taxonomy, $value, $term_id, Schema::table( 'translation_links' ) ) );
		// phpcs:enable

		if ( self::failed( $wpdb ) ) {
			return null;
		}

		return is_numeric( $other ) ? (int) $other : 0;
	}

	/**
	 * Live terms of a taxonomy the language reaches by their DB slug (linked in it or unlinked, no current slug in it),
	 * as slug key => term id, for some slugs.
	 *
	 * @param \wpdb             $wpdb        Database.
	 * @param string            $taxonomy    Taxonomy.
	 * @param int               $language_id Language.
	 * @param array<int, string> $slugs      Slugs.
	 * @param array<int, int>    $exclude    Terms not to count (the one written; terms that will hold a current slug).
	 * @return array<string, int>|null Null when a read failed.
	 */
	private static function reached_by_db_slug( \wpdb $wpdb, string $taxonomy, int $language_id, array $slugs, array $exclude ): ?array {
		$out = [];

		foreach ( array_chunk( array_values( array_unique( $slugs ) ), 200 ) as $chunk ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT n.term_id, n.slug FROM %i n
					INNER JOIN %i nt ON nt.term_id = n.term_id AND nt.taxonomy = %s
					LEFT JOIN %i l ON l.type = 'term' AND l.object_id = n.term_id
					LEFT JOIN %i c ON c.object_type = 'term' AND c.object_id = n.term_id AND c.language_id = %d
					WHERE n.slug IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ') AND ( l.id IS NULL OR l.language_id = %d ) AND c.id IS NULL',
					array_merge( [ $wpdb->terms, $wpdb->term_taxonomy, $taxonomy, Schema::table( 'translation_links' ), self::table(), $language_id ], $chunk, [ $language_id ] )
				),
				ARRAY_A
			);
			// phpcs:enable

			if ( self::failed( $wpdb ) ) {
				return null;
			}

			foreach ( Typed::rows( $rows ) as $r ) {
				if ( ! in_array( Typed::int( $r['term_id'] ?? 0 ), $exclude, true ) ) {
					$out[ self::key( Typed::str( $r['slug'] ?? '' ) ) ] = Typed::int( $r['term_id'] ?? 0 );
				}
			}
		}

		return $out;
	}

	/**
	 * Terms holding $slug as an old URL slug in a namespace.
	 *
	 * @param \wpdb  $wpdb        Database.
	 * @param string $taxonomy    Taxonomy.
	 * @param int    $language_id Language.
	 * @param string $slug        Slug.
	 * @return array<int, int>|null Null when the read failed.
	 */
	private static function history_holders( \wpdb $wpdb, string $taxonomy, int $language_id, string $slug ): ?array {
		[ $match, $value ] = self::match( $wpdb, $slug );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $match is 'LIKE %s' or '= %s' from match().
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM %i WHERE language_id = %d AND object_subtype = %s AND object_type IN ('term_h1','term_h2','term_h3','term_h4','term_h5') AND slug {$match}", self::table(), $language_id, $taxonomy, $value ) );

		return self::failed( $wpdb ) ? null : Typed::ints( $ids );
	}

	/**
	 * Delete every old URL slug equal to $slug in a namespace (any term, any slot).
	 *
	 * @param \wpdb                $wpdb        Database.
	 * @param ConnectionGuard|null $guard       Unit guard.
	 * @param string               $taxonomy    Taxonomy.
	 * @param int                  $language_id Language.
	 * @param string               $slug        Slug.
	 * @return bool
	 */
	private static function delete_history( \wpdb $wpdb, ?ConnectionGuard $guard, string $taxonomy, int $language_id, string $slug ): bool {
		[ $match, $value ] = self::match( $wpdb, $slug );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::guarded( $wpdb, $guard, $wpdb->prepare( "DELETE FROM %i WHERE language_id = %d AND object_subtype = %s AND object_type IN ('term_h1','term_h2','term_h3','term_h4','term_h5') AND slug {$match}", self::table(), $language_id, $taxonomy, $value ) );
	}

	/**
	 * Whether a term's replaced URL slug becomes its newest old slug (rules 2 and 5); deletes others' copies of it.
	 *
	 * @param \wpdb                $wpdb        Database.
	 * @param ConnectionGuard|null $guard       Unit guard.
	 * @param string               $taxonomy    Taxonomy.
	 * @param int                  $term_id     Term.
	 * @param int                  $language_id Language.
	 * @param string               $old         The replaced slug.
	 * @param array<int, string>   $notes       Notes (appended).
	 * @param array<int, int>      $changed     Changed terms (appended).
	 * @return bool|null Null on a database error.
	 */
	private static function keep_old_slug( \wpdb $wpdb, ?ConnectionGuard $guard, string $taxonomy, int $term_id, int $language_id, string $old, array &$notes, array &$changed ): ?bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$own = $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE term_id = %d', $wpdb->terms, $term_id ) );

		if ( self::failed( $wpdb ) ) {
			return null;
		}

		if ( is_string( $own ) && self::key( $own ) === self::key( $old ) ) {
			return false;
		}

		$reached = self::reached_by_db_slug( $wpdb, $taxonomy, $language_id, [ $old ], [ $term_id ] );
		$holders = null === $reached || [] !== $reached ? [] : self::history_holders( $wpdb, $taxonomy, $language_id, $old );

		if ( null === $reached || null === $holders ) {
			return null;
		}

		if ( $reached !== [] ) {
			/* translators: 1: URL slug, 2: term ID. */
			$notes[] = sprintf( __( 'The old URL slug "%1$s" does not redirect here: term %2$d is reached by it.', 'perflocale' ), $old, (int) reset( $reached ) );

			return false;
		}

		foreach ( $holders as $holder ) {
			if ( $holder !== $term_id ) {
				$changed[] = $holder;
				/* translators: 1: URL slug, 2: term ID. */
				$notes[] = sprintf( __( 'The old URL slug "%1$s" now redirects here, no longer to term %2$d.', 'perflocale' ), $old, $holder );
			}
		}

		return self::delete_history( $wpdb, $guard, $taxonomy, $language_id, $old ) ? true : null;
	}

	/**
	 * Move a term's old slugs one slot older (term_h4 -> term_h5 first, so object_slug stays unique).
	 *
	 * @param \wpdb                $wpdb        Database.
	 * @param ConnectionGuard|null $guard       Unit guard.
	 * @param int                  $term_id     Term.
	 * @param int                  $language_id Language.
	 * @return bool
	 */
	private static function rotate( \wpdb $wpdb, ?ConnectionGuard $guard, int $term_id, int $language_id ): bool {
		for ( $slot = 4; $slot >= 1; $slot-- ) {
			if ( ! self::guarded( $wpdb, $guard, $wpdb->prepare( 'UPDATE %i SET object_type = %s WHERE object_type = %s AND object_id = %d AND language_id = %d', self::table(), 'term_h' . ( $slot + 1 ), 'term_h' . $slot, $term_id, $language_id ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Insert one term URL slug row, through the unit's guard when there is one.
	 *
	 * @param \wpdb                $wpdb        Database.
	 * @param ConnectionGuard|null $guard       Unit guard.
	 * @param string               $type        One of TERM_TYPES.
	 * @param string               $taxonomy    Taxonomy, raw (readers match its bytes).
	 * @param int                  $term_id     Term.
	 * @param int                  $language_id Language.
	 * @param string               $slug        Slug.
	 * @return bool
	 * @throws \RuntimeException When the guard finds the connection lost.
	 */
	private static function insert_row( \wpdb $wpdb, ?ConnectionGuard $guard, string $type, string $taxonomy, int $term_id, int $language_id, string $slug ): bool {
		$row = [
			'object_type'    => $type,
			'object_subtype' => $taxonomy,
			'object_id'      => $term_id,
			'language_id'    => $language_id,
			'slug'           => $slug,
		];
		$fmt = [ '%s', '%s', '%d', '%d', '%s' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$done = null !== $guard ? $guard->insert( self::table(), $row, $fmt ) : $wpdb->insert( self::table(), $row, $fmt );

		if ( 0 === $done ) {
			throw new \RuntimeException( 'term URL slug write: the database connection was lost' );
		}

		return false !== $done;
	}

	/**
	 * Run a prepared DELETE or UPDATE, carrying the guard's condition (a statement resent on a new connection matches nothing).
	 *
	 * @param \wpdb                $wpdb  Database.
	 * @param ConnectionGuard|null $guard Unit guard.
	 * @param string|null          $sql   Prepared statement with a WHERE clause.
	 * @return bool
	 */
	private static function guarded( \wpdb $wpdb, ?ConnectionGuard $guard, ?string $sql ): bool {
		if ( null === $sql ) {
			return false;
		}

		$condition = null !== $guard ? $guard->condition() : '';

		if ( null !== $guard && ! $guard->holds() ) {
			throw new \RuntimeException( 'term URL slug write: the database connection was lost' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the caller; the guard's condition holds a checked name and a hex token.
		return false !== $wpdb->query( '' !== $condition ? $sql . ' AND ' . $condition : $sql );
	}

	/**
	 * The SQL test of a slug as the slug_lookup key compares it: equality, or its 191-character prefix (sargable LIKE).
	 *
	 * @param \wpdb  $wpdb Database.
	 * @param string $slug Slug.
	 * @return array{0: literal-string, 1: string} Clause after the column (one %s) and its value.
	 */
	private static function match( \wpdb $wpdb, string $slug ): array {
		return mb_strlen( $slug ) >= self::SLUG_INDEX_PREFIX
			? [ 'LIKE %s', $wpdb->esc_like( mb_substr( $slug, 0, self::SLUG_INDEX_PREFIX ) ) . '%' ]
			: [ '= %s', $slug ];
	}

	/**
	 * A slug as the slug_lookup key compares it: the first 191 characters, case-folded, trailing spaces ignored.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function key( string $slug ): string {
		return rtrim( self::fold( mb_substr( $slug, 0, self::SLUG_INDEX_PREFIX ) ), ' ' );
	}

	/**
	 * A slug lowercased by Utf8::lower(): the same bytes with or without mbstring.
	 *
	 * @internal
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function fold( string $slug ): string {
		return Utf8::lower( $slug );
	}

	/**
	 * After a term URL slug commit: the slug cache of each changed term, its links and the hreflang of its group.
	 *
	 * @param array<int, int> $term_ids    Changed terms.
	 * @param int             $language_id Language.
	 * @return void
	 */
	public static function refresh_term_caches( array $term_ids, int $language_id ): void {
		if ( [] === $term_ids ) {
			return;
		}

		$plugin = Plugin::get_instance();
		$cache  = $plugin->cache();

		foreach ( $term_ids as $term_id ) {
			$cache->delete( "slug_term_{$term_id}_{$language_id}", 'perflocale_slugs' );
			$cache->flush_object( $term_id, 'term' );

			/** This hook is documented in src/Cache/CacheInvalidator.php */
			do_action( 'perflocale/cache/flush_archive_hreflang', $term_id );
		}

		$cache->invalidate_group( 'perflocale_hreflang' );

		if ( get_option( 'perflocale_has_any_slugs', '' ) !== '1' ) {
			update_option( 'perflocale_has_any_slugs', '1', true );
		}

		SlugTranslationRepository::reset_static_caches();
		$plugin->slug_manager()->forget_slugs();
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

	/**
	 * Daily GC: delete the old-slug rows (term_h1..term_h5) of terms that no longer exist, reading at most $max_ids term
	 * ids per run along object_slug from a stored cursor. Such rows outlive a term deleted under code that knew only 'term'.
	 *
	 * @param CacheManager|null $cache   Flushes each removed term's entries.
	 * @param int               $max_ids Most term ids one run reads.
	 * @return int Rows deleted.
	 */
	public static function gc_orphan_history( ?CacheManager $cache, int $max_ids = 1000 ): int {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$table   = self::table();
		$cursor  = get_option( self::HISTORY_SWEEP_OPTION );
		$slot    = is_array( $cursor ) ? max( 1, min( 5, (int) ( $cursor['slot'] ?? 1 ) ) ) : 1;
		$after   = is_array( $cursor ) ? max( 0, (int) ( $cursor['id'] ?? 0 ) ) : 0;
		$read    = 0;
		$deleted = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A daily GC; each IN list is one %d per id.
		while ( $read < $max_ids ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT object_id FROM %i WHERE object_type = %s AND object_id > %d ORDER BY object_id LIMIT %d', $table, 'term_h' . $slot, $after, min( self::HISTORY_SWEEP_BATCH, $max_ids - $read ) ) ) );

			if ( self::failed( $wpdb ) ) {
				return $deleted;
			}

			if ( [] !== $ids ) {
				$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$live = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM %i WHERE term_id IN ($in)", $wpdb->terms, ...$ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds one %d per id.

				if ( self::failed( $wpdb ) ) {
					return $deleted;
				}

				$gone = array_values( array_diff( $ids, $live ) );

				if ( [] !== $gone ) {
					$in = implode( ',', array_fill( 0, count( $gone ), '%d' ) );
					$n  = $wpdb->query( (string) $wpdb->prepare( "DELETE FROM %i WHERE object_type IN ('term_h1','term_h2','term_h3','term_h4','term_h5') AND object_id IN ($in)", $table, ...$gone ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds one %d per id.

					if ( false === $n ) {
						return $deleted;
					}

					$deleted += (int) $n;

					foreach ( $gone as $term_id ) {
						$cache?->flush_object( $term_id, 'term' );
					}
				}

				$read += count( $ids );
				$after = (int) end( $ids );
			}

			// This slot is done: the next one, or after term_h5 a new pass from the start next run.
			if ( count( $ids ) < self::HISTORY_SWEEP_BATCH && $read < $max_ids ) {
				$after = 0;

				if ( 5 === $slot ) {
					$slot = 1;
					break;
				}

				++$slot;
			}
		}
		// phpcs:enable

		update_option(
			self::HISTORY_SWEEP_OPTION,
			[
				'slot' => $slot,
				'id'   => $after,
			],
			false
		);

		return $deleted;
	}

	/**
	 * Whether the last query failed; impure, so PHPStan does not keep a narrowed last_error across queries.
	 *
	 * @phpstan-impure
	 * @param \wpdb $wpdb Database.
	 * @return bool
	 */
	private static function failed( \wpdb $wpdb ): bool {
		return '' !== $wpdb->last_error;
	}
}
