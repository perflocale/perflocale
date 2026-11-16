<?php
/**
 * The estimate of a resync run.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Background\Jobs\ContentResyncJob;
use PerfLocale\Database\Schema;
use PerfLocale\Helper;
use PerfLocale\MachineTranslation\ContextSettings;
use PerfLocale\MachineTranslation\CostEstimator;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\WorkBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a run would do and cost, before it starts, writing and sending nothing: the job's dry run for small runs
 * (exact), else an upper bound from the full source text. `complete` only for an exact estimate or a finished
 * bound, so a run started at once never rests on a partial count.
 *
 * @phpstan-import-type Args from ContentResyncJob
 */
final class Estimator {

	/** A dry run finished. */
	public const BOUND_EXACT = 'exact';

	/** An upper bound that finished. */
	public const BOUND_AT_MOST = 'at_most';

	/** An upper bound that ran out of time: a lower bound of the work. */
	public const BOUND_AT_LEAST = 'at_least';

	/** Pairs up to which the estimate is a dry run. */
	public const EXACT_PAIRS = 200;

	/** The estimate's budget, in seconds. */
	public const BUDGET_SECONDS = 10.0;

	/** The counts an envelope carries, besides the cost estimator's. */
	private const COUNTS = [ 'pairs', 'proposals', 'unchanged', 'outdated', 'confirmed', 'fills', 'fill_chars', 'mt_chars', 'mt_units', 'reused', 'left_source', 'machine_translated', 'missing', 'empty', 'skipped', 'skipped_permission', 'busy', 'no_base', 'unsupported', 'held', 'needs_mt' ];

	/**
	 * Estimate a run.
	 *
	 * @param array<string, mixed> $args   The run's arguments (normalised here).
	 * @param float                $budget Seconds for each of the dry run and the bound.
	 * @return array<string, mixed> The envelope plus `mt_unavailable` (why MT is unavailable to pairs that need it) and `stopped`/`first_error` (why a dry run stopped early).
	 */
	public static function estimate( array $args, float $budget = self::BUDGET_SECONDS ): array {
		$args     = ContentResyncJob::normalize_args( $args );
		$settings = \PerfLocale\Plugin::get_instance()->settings();
		$ce       = new CostEstimator( $settings );
		$pairs    = self::pairs( $args );

		if ( null === $pairs ) {
			return self::read_failed( $ce, array_fill_keys( self::COUNTS, 0 ) );
		}

		if ( $pairs <= self::exact_limit() ) {
			$result  = ( new ContentResyncJob() )->dry_run( $args, $budget );
			$stopped = Scalar::str( $result['stopped'] ?? '' );

			if ( empty( $result['run_failed'] ) && 'budget' !== $stopped ) {
				return self::envelope( $ce, self::totals_of( $result ), '' === $stopped ? self::BOUND_EXACT : self::BOUND_AT_LEAST ) + [
					'mt_unavailable' => Scalar::str( $result['mt_unavailable'] ?? '' ),
					'stopped'        => $stopped,
					'first_error'    => '' === $stopped ? '' : Scalar::str( $result['first_error'] ?? '' ),
				];
			}
		}

		[ $totals, $finished, $failed ] = self::upper_bound( $args, $budget );

		if ( $failed ) {
			return self::read_failed( $ce, $totals );
		}

		// Billed context is not in the source text, so with it the bound is a floor.
		if ( $finished && $args['machine'] && $totals['proposals'] > 0 && self::context_billed() ) {
			$finished = false;
		}

		return self::envelope( $ce, $totals, $finished ? self::BOUND_AT_MOST : self::BOUND_AT_LEAST ) + [
			'mt_unavailable' => '',
			'stopped'        => '',
			'first_error'    => '',
		];
	}

	/**
	 * Whether a machine-mode run would send context the provider bills.
	 *
	 * @return bool
	 */
	private static function context_billed(): bool {
		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->settings();

		if ( ! $settings->mt_enabled() || ContextBuilder::MODE_OFF === ContextSettings::mode( $settings ) ) {
			return false;
		}

		$service = new TranslationService( $settings, $plugin->cache() );

		try {
			return $service->context_billed( $service->get_provider() );
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * The envelope of an estimate whose read failed: at least what was counted, never complete.
	 *
	 * @param CostEstimator        $ce     The cost estimator.
	 * @param array<string, mixed> $totals What was counted before the read failed.
	 * @return array<string, mixed>
	 */
	private static function read_failed( CostEstimator $ce, array $totals ): array {
		return self::envelope( $ce, $totals, self::BOUND_AT_LEAST ) + [
			'mt_unavailable' => '',
			'stopped'        => 'db_error',
			'first_error'    => ContentResyncJob::read_failed_text( __( 'A database read failed, so the estimate could not be completed. Try again.', 'perflocale' ) ),
		];
	}

	/**
	 * The pairs up to which the estimate is exact: 200, filterable.
	 *
	 * @return int
	 */
	public static function exact_limit(): int {
		/**
		 * Filters the number of pairs up to which a resync estimate is exact; above it, an upper bound.
		 *
		 * @internal Not public: a display threshold.
		 * @hook perflocale/resync/exact_estimate_pairs
		 * @since 1.0.8
		 *
		 * @param int $pairs Default 200.
		 */
		return max( 0, Scalar::int( apply_filters( 'perflocale/resync/exact_estimate_pairs', self::EXACT_PAIRS ) ) );
	}

	/**
	 * The estimate's envelope: the cost estimator's summary (same `would_exceed` as every bulk tool), bound, counts.
	 *
	 * @param CostEstimator        $ce     The cost estimator.
	 * @param array<string, mixed> $totals Counts by key.
	 * @param string               $bound  exact, at_most or at_least.
	 * @return array<string, mixed>
	 */
	public static function envelope( CostEstimator $ce, array $totals, string $bound ): array {
		$bound = in_array( $bound, [ self::BOUND_EXACT, self::BOUND_AT_MOST ], true ) ? $bound : self::BOUND_AT_LEAST;
		$out   = [];

		foreach ( self::COUNTS as $key ) {
			$out[ $key ] = Scalar::int( $totals[ $key ] ?? 0 );
		}

		$mt_chars   = Scalar::int( $totals['mt_chars'] ?? 0 );
		$fill_chars = Scalar::int( $totals['fill_chars'] ?? 0 );
		$items      = Scalar::int( $totals['items'] ?? ( ( $mt_chars > 0 ? Scalar::int( $totals['proposals'] ?? 0 ) : 0 ) + ( $fill_chars > 0 ? Scalar::int( $totals['fills'] ?? 0 ) : 0 ) ) );

		return $ce->summarize( $mt_chars + $fill_chars, $items, 0 ) + [
			'bound'    => $bound,
			'complete' => self::BOUND_AT_LEAST !== $bound,
		] + $out;
	}

	/**
	 * The totals of a dry-run result.
	 *
	 * @param array<string, mixed> $result The job's dry-run result.
	 * @return array<string, mixed>
	 */
	public static function totals_of( array $result ): array {
		$totals = [];

		foreach ( self::COUNTS as $key ) {
			$totals[ $key ] = Scalar::int( $result[ $key ] ?? 0 );
		}

		return $totals;
	}

	/**
	 * The pair count of a run (sources times languages), for the exact-or-bound choice.
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 * @phpstan-param Args $args
	 * @return int|null Null when the count could not be read.
	 */
	private static function pairs( array $args ): ?int {
		$langs = count( $args['language_ids'] );

		if ( ContentResyncJob::MODE_SOURCES === $args['mode'] ) {
			return count( $args['source_ids'] ) * $langs;
		}

		$types = self::query_types( $args );

		if ( [] === $types ) {
			return 0;
		}

		$wpdb = self::db();

		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One count per estimate; the IN list is built from placeholders.
		$rows = self::read_rows(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i l INNER JOIN %i p ON p.ID = l.object_id
				 WHERE l.type = 'post' AND l.language_id = %d AND p.post_status = 'publish' AND p.post_type IN ($in)",
				array_merge( [ Schema::table( 'translation_links' ), $wpdb->posts, self::default_language() ], $types )
			)
		);
		// phpcs:enable

		return null === $rows ? null : Scalar::int( array_values( $rows[0] ?? [] )[0] ?? 0 ) * $langs;
	}

	/**
	 * The upper bound: per page of sources, the pairs by stage and the full source text of every pair that could
	 * send characters. Rights-check posts are read in byte-bounded chunks; at the time or memory ceiling it is a floor.
	 *
	 * @param array<string, mixed> $args   Normalised arguments.
	 * @phpstan-param Args $args
	 * @param float                $budget Seconds.
	 * @return array{0: array<string, int>, 1: bool, 2: bool} The totals, whether every page was counted, and whether a read failed.
	 */
	private static function upper_bound( array $args, float $budget ): array {
		$totals = array_fill_keys( self::COUNTS, 0 );
		$began  = microtime( true );
		$langs  = $args['language_ids'];
		$fill   = $args['fill'];
		$items  = 0;

		if ( [] === $langs ) {
			return [ $totals + [ 'items' => 0 ], true, false ];
		}

		$after   = 0;
		$pages   = 0;
		$scope   = [
			'trigger'      => $args['trigger'],
			'language_ids' => $langs,
			'user_id'      => get_current_user_id(),
		];
		$fields  = Scope::fields_included( $scope );
		$ce      = new CostEstimator( \PerfLocale\Plugin::get_instance()->settings() );
		$limit   = WorkBudget::work_limit();
		$ceiling = null === $limit ? 0 : ContentResyncJob::memory_ceiling( $limit, memory_get_usage( true ) );

		while ( true ) {
			$page = self::page( $args, $after );

			if ( null === $page ) {
				return [ $totals + [ 'items' => $items ], false, true ];
			}

			if ( [] === $page ) {
				break;
			}

			// Pages are left and the time is up: what was counted is a floor.
			if ( $pages > 0 && microtime( true ) - $began >= $budget ) {
				return [ $totals + [ 'items' => $items ], false, false ];
			}

			$after = (int) end( $page );
			++$pages;

			$read = self::links( $page, $langs );

			if ( null === $read ) {
				return [ $totals + [ 'items' => $items ], false, true ];
			}

			[ $links, $sizes, $post_fields ] = $read;

			if ( $fields && ( ContentResyncJob::MODE_SOURCES === $args['mode'] ? $args['only_outdated'] : $args['query']['outdated'] ) ) {
				$links = self::with_drift( $links, static fn (): bool => microtime( true ) - $began >= $budget );

				if ( null === $links ) {
					return [ $totals + [ 'items' => $items ], false, true ];
				}

				if ( false === $links ) {
					return [ $totals + [ 'items' => $items ], false, false ];
				}
			}

			$chars  = [];
			$rights = [];

			if ( $args['machine'] || ContentResyncJob::FILL_MACHINE === $fill ) {
				$chars = $ce->chars_by_post( $page, $fields );
			}

			$groups = self::checked_posts( $args, $page, $links, $langs );
			$bound  = PostReads::post_bound( $ceiling );
			$room   = PostReads::budget( $ceiling );

			foreach ( PostReads::chunks( $groups, $sizes, $room ) as $c => $chunk ) {
				// Memory is left for the next chunk's posts, or what was counted is a floor.
				if ( ( $pages > 1 || $c > 0 ) && $ceiling > 0 && memory_get_usage( true ) >= $ceiling ) {
					return [ $totals + [ 'items' => $items ], false, false ];
				}

				$held = [];

				foreach ( $chunk as $source_id ) {
					foreach ( $groups[ $source_id ] as $id ) {
						if ( ! PostReads::too_large( $sizes, $id, $bound ) ) {
							$held[ $id ] = $id;
						}
					}
				}

				// Of a group larger than one read, the rest is read by its pair's checks and released after them.
				$fit    = PostReads::fitting( array_values( $held ), $sizes, $room );
				$split  = count( $fit ) < count( $held );
				$primed = self::prime( $fit );

				foreach ( $chunk as $source_id ) {
					foreach ( $langs as $language_id ) {
						$link = $links[ $source_id ][ $language_id ] ?? null;
						$kind = self::kind( $args, $link );

						if ( '' === $kind ) {
							continue;
						}

						++$totals['pairs'];

						$translation_id = null === $link ? 0 : $link['translation_id'];

						// A post larger than a pair can hold is not read: the run refuses the pair, after its rights.
						if ( 'missing' !== $kind && ( PostReads::too_large( $sizes, $source_id, $bound ) || ( $translation_id > 0 && PostReads::too_large( $sizes, $translation_id, $bound ) ) ) ) {
							++$totals[ PostReads::may_pair( $kind, $source_id, $translation_id, $post_fields ) ? 'unsupported' : 'skipped_permission' ];
							continue;
						}

						$allowed = self::permitted( $kind, $source_id, $link, $rights );

						if ( $split && $translation_id > 0 && ! in_array( $translation_id, $fit, true ) ) {
							PostReads::release( [ $translation_id ], [ $translation_id ] );
						}

						if ( ! $allowed ) {
							++$totals['skipped_permission'];
						} elseif ( 'fill' === $kind ) {
							++$totals['fills'];

							if ( ContentResyncJob::FILL_MACHINE === $fill ) {
								$totals['fill_chars'] += $chars[ $source_id ] ?? 0;
								++$items;
							}
						} elseif ( 'missing' === $kind ) {
							++$totals['missing'];
						} elseif ( 'confirm' === $kind ) {
							++$totals['confirmed'];
						} else {
							++$totals['proposals'];

							if ( $args['machine'] ) {
								$totals['mt_chars'] += $chars[ $source_id ] ?? 0;
								++$items;
							}
						}
					}
				}

				PostReads::release( $primed, array_values( $held ) );
			}
		}

		return [ $totals + [ 'items' => $items ], true, false ];
	}

	/**
	 * The posts the rights checks of each source's pairs read, by source in page order.
	 *
	 * @param array<string, mixed>                                                            $args  Normalised arguments.
	 * @phpstan-param Args $args
	 * @param array<int, int>                                                                 $page  Source ids.
	 * @param array<int, array<int, array{translation_id: int, status: string, empty: bool}>> $links The page's translations.
	 * @param array<int, int>                                                                 $langs Language ids.
	 * @return array<int, list<int>>
	 */
	private static function checked_posts( array $args, array $page, array $links, array $langs ): array {
		$groups = [];

		foreach ( $page as $source_id ) {
			$ids = [];

			foreach ( $langs as $language_id ) {
				$link = $links[ $source_id ][ $language_id ] ?? null;
				$kind = self::kind( $args, $link );

				if ( '' === $kind || 'missing' === $kind ) {
					continue;
				}

				$ids[ $source_id ] = $source_id;

				$translation_id = null === $link ? 0 : $link['translation_id'];

				if ( $translation_id > 0 ) {
					$ids[ $translation_id ] = $translation_id;
				}
			}

			$groups[ $source_id ] = array_values( $ids );
		}

		return $groups;
	}

	/**
	 * Whether the current user may act on a pair, asked as PairRunner and Filler ask it.
	 *
	 * @param string                                                       $kind      resync, confirm, fill or missing.
	 * @param int                                                          $source_id The source.
	 * @param array{translation_id: int, status: string, empty: bool}|null $link      The pair's translation.
	 * @param array<string, array<int, bool>>                              $rights    The answers so far on the page, by right and post.
	 * @return bool
	 */
	private static function permitted( string $kind, int $source_id, ?array $link, array &$rights ): bool {
		if ( 'missing' === $kind ) {
			return true;
		}

		$translation_id = null === $link ? 0 : $link['translation_id'];

		if ( 'fill' === $kind ) {
			return self::may( 'edit', $source_id, $rights ) && ( $translation_id <= 0 || self::may( 'edit', $translation_id, $rights ) );
		}

		return self::may( 'edit', $translation_id, $rights ) && self::may( 'read_text', $source_id, $rights );
	}

	/**
	 * One right of the current user on one post, asked once per page.
	 *
	 * @param string                          $right   edit or read_text.
	 * @param int                             $post_id The post.
	 * @param array<string, array<int, bool>> $rights  The answers so far on the page.
	 * @return bool
	 */
	private static function may( string $right, int $post_id, array &$rights ): bool {
		if ( ! isset( $rights[ $right ][ $post_id ] ) ) {
			if ( 'edit' === $right ) {
				$rights[ $right ][ $post_id ] = $post_id > 0 && Helper::user_can_edit_object( $post_id );
			} else {
				$post = $post_id > 0 ? get_post( $post_id ) : null;

				$rights[ $right ][ $post_id ] = $post instanceof \WP_Post && \PerfLocale\Util\ObjectPermission::read_text( $post );
			}
		}

		return $rights[ $right ][ $post_id ];
	}

	/**
	 * Prime the chunk's uncached posts in one statement, for the rights checks.
	 *
	 * @param array<int, int> $ids The chunk's posts.
	 * @return list<int> The ids read.
	 */
	private static function prime( array $ids ): array {
		$ids = [] === $ids ? [] : array_values( array_map( Scalar::int( ... ), _get_non_cached_ids( $ids, 'posts' ) ) );

		if ( [] !== $ids ) {
			_prime_post_caches( $ids, false, false );
		}

		return $ids;
	}

	/**
	 * The links with `drift` set on current recorded pairs whose source fields changed, as the run's scan finds them.
	 *
	 * @param array<int, array<int, array{translation_id: int, status: string, empty: bool}>> $links The page's translations.
	 * @param callable                                                                        $stop  True stops.
	 * @phpstan-param callable(): bool $stop
	 * @return array<int, array<int, array{translation_id: int, status: string, empty: bool, drift?: bool}>>|false|null False when stopped; null when a read failed.
	 */
	private static function with_drift( array $links, callable $stop ): array|false|null {
		$ids = [];

		foreach ( $links as $by_language ) {
			foreach ( $by_language as $link ) {
				if ( ! $link['empty'] && 'needs_update' !== $link['status'] ) {
					$ids[] = $link['translation_id'];
				}
			}
		}

		$state = ( new \PerfLocale\Database\Repository\ResyncRepository() )->state_hashes_of( $ids );

		if ( null === $state ) {
			return null;
		}

		$pairs = [];

		foreach ( $state as $translation_id => $row ) {
			$pairs[ $translation_id ] = [
				'source_id'   => $row['source_id'],
				'base_id'     => $row['base_id'],
				'fields_hash' => $row['fields_hash'],
			];
		}

		$changed = FieldDrift::changed( $pairs, $stop );

		if ( ! is_array( $changed ) ) {
			return $changed;
		}

		$out = [];

		foreach ( $links as $source_id => $by_language ) {
			foreach ( $by_language as $language_id => $link ) {
				$link['drift']                     = isset( $changed[ $link['translation_id'] ] );
				$out[ $source_id ][ $language_id ] = $link;
			}
		}

		return $out;
	}

	/**
	 * What a pair counts as in the bound: `resync`, `confirm`, `fill`, `missing`, or '' (not selected).
	 *
	 * @param array<string, mixed>                                                       $args Normalised arguments.
	 * @phpstan-param Args $args
	 * @param array{translation_id: int, status: string, empty: bool, drift?: bool}|null $link The pair's translation.
	 * @return string
	 */
	private static function kind( array $args, ?array $link ): string {
		$missing = null === $link || $link['empty'];
		$fill    = '' !== $args['fill'];

		if ( ContentResyncJob::MODE_SOURCES === $args['mode'] ) {
			if ( $missing ) {
				return $fill ? 'fill' : 'missing';
			}

			return $args['only_outdated'] && 'needs_update' !== $link['status'] && empty( $link['drift'] ) ? '' : 'resync';
		}

		$q = $args['query'];

		if ( $missing ) {
			return $q['missing'] ? ( $fill ? 'fill' : 'missing' ) : '';
		}

		if ( 'needs_update' === $link['status'] || ! empty( $link['drift'] ) ) {
			return $q['outdated'] ? 'resync' : '';
		}

		return $q['record_in_sync'] ? 'confirm' : '';
	}

	/**
	 * The next page of sources after a cursor.
	 *
	 * @param array<string, mixed> $args  Normalised arguments.
	 * @phpstan-param Args $args
	 * @param int                  $after The last source of the previous page.
	 * @return array<int, int>|null Null when the page could not be read.
	 */
	private static function page( array $args, int $after ): ?array {
		if ( ContentResyncJob::MODE_SOURCES === $args['mode'] ) {
			$rest = array_values( array_filter( $args['source_ids'], static fn ( int $id ): bool => $id > $after ) );

			return array_slice( $rest, 0, ContentResyncJob::PAGE_SOURCES );
		}

		$types = self::query_types( $args );

		if ( [] === $types ) {
			return [];
		}

		$wpdb = self::db();

		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Keyset page; the IN list is built from placeholders.
		$rows = self::read_rows(
			$wpdb->prepare(
				"SELECT l.object_id FROM %i l INNER JOIN %i p ON p.ID = l.object_id
				 WHERE l.type = 'post' AND l.language_id = %d AND l.object_id > %d AND p.post_status = 'publish' AND p.post_type IN ($in)
				 ORDER BY l.object_id ASC LIMIT %d",
				array_merge( [ Schema::table( 'translation_links' ), $wpdb->posts, self::default_language(), $after ], $types, [ ContentResyncJob::PAGE_SOURCES ] )
			)
		);
		// phpcs:enable

		return null === $rows ? null : array_map( static fn ( array $row ): int => Scalar::int( $row['object_id'] ?? 0 ), $rows );
	}

	/**
	 * The page's translations (id, status, empty), the text bytes and PostReads::may()'s fields of sources and
	 * translations, in one statement.
	 *
	 * @param array<int, int> $page  Source ids.
	 * @param array<int, int> $langs Language ids.
	 * @return array{0: array<int, array<int, array{translation_id: int, status: string, empty: bool}>>, 1: array<int, int|null>, 2: array<int, array<string, mixed>|null>}|null Links by source and language, bytes and fields by post id; null on failure.
	 */
	private static function links( array $page, array $langs ): ?array {
		$wpdb = self::db();

		$links_table = Schema::table( 'translation_links' );
		$src_in      = implode( ',', array_fill( 0, count( $page ), '%d' ) );
		$lang_in     = implode( ',', array_fill( 0, count( $langs ), '%d' ) );
		$bytes       = PostReads::bytes_sql();
		$fields      = PostReads::FIELDS_SQL;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Reads of one page; the IN lists are built from placeholders and the byte expression from fixed column names.
		$rows = self::read_rows(
			$wpdb->prepare(
				"SELECT s.object_id AS source_id, t.object_id AS translation_id, t.language_id, t.status, ( p.post_content = '' ) AS empty_content, $bytes AS bytes, $fields
				 FROM %i s INNER JOIN %i t ON t.group_id = s.group_id AND t.type = 'post'
				 INNER JOIN %i p ON p.ID = t.object_id
				 WHERE s.type = 'post' AND s.language_id = %d AND s.object_id IN ($src_in) AND t.language_id IN ($lang_in)
				 UNION ALL
				 SELECT p.ID AS source_id, 0 AS translation_id, 0 AS language_id, '' AS status, 0 AS empty_content, $bytes AS bytes, $fields
				 FROM %i p WHERE p.ID IN ($src_in)",
				array_merge( [ $links_table, $links_table, $wpdb->posts, self::default_language() ], $page, $langs, [ $wpdb->posts ], $page )
			)
		);
		// phpcs:enable

		if ( null === $rows ) {
			return null;
		}

		$out    = [];
		$sizes  = [];
		$fields = [];

		foreach ( $rows as $row ) {
			$source_id      = Scalar::int( $row['source_id'] ?? 0 );
			$translation_id = Scalar::int( $row['translation_id'] ?? 0 );
			$id             = $translation_id > 0 ? $translation_id : $source_id;

			$sizes[ $id ]  = null === ( $row['bytes'] ?? null ) ? null : Scalar::int( $row['bytes'] );
			$fields[ $id ] = PostReads::fields( $row );

			if ( $translation_id <= 0 ) {
				continue;
			}

			$out[ $source_id ][ Scalar::int( $row['language_id'] ?? 0 ) ] = [
				'translation_id' => $translation_id,
				'status'         => Scalar::str( $row['status'] ?? '' ),
				'empty'          => 1 === Scalar::int( $row['empty_content'] ?? 0 ),
			];
		}

		return [ $out, $sizes, $fields ];
	}

	/**
	 * A query run's types within the scope list.
	 *
	 * @param array<string, mixed> $args Normalised arguments.
	 * @phpstan-param Args $args
	 * @return list<string>
	 */
	private static function query_types( array $args ): array {
		$scope = Scope::object_types(
			[
				'trigger'      => $args['trigger'],
				'language_ids' => $args['language_ids'],
				'user_id'      => get_current_user_id(),
			]
		);

		return array_values( array_intersect( $args['query']['post_types'], array_diff( $scope, [ Scope::CUSTOM_FIELDS ] ) ) );
	}

	/**
	 * The rows of a read, or null when it failed. Through query(): get_results() answers [] for a failed read too.
	 *
	 * @param string|null $sql The prepare() result.
	 * @return list<array<string, mixed>>|null
	 */
	private static function read_rows( ?string $sql ): ?array {
		$wpdb = self::db();

		if ( ! is_string( $sql ) || '' === $sql ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the prepare() result.
		$read = $wpdb->query( $sql );

		if ( ! is_int( $read ) ) {
			return null;
		}

		$rows = [];

		foreach ( (array) $wpdb->last_result as $row ) {
			$item = [];

			foreach ( (array) $row as $key => $value ) {
				$item[ (string) $key ] = $value;
			}

			$rows[] = $item;
		}

		return $rows;
	}

	/**
	 * WordPress's database object.
	 *
	 * @return \wpdb
	 * @throws \RuntimeException When it is missing.
	 */
	private static function db(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}

		return $wpdb;
	}

	/**
	 * The default language's id.
	 *
	 * @return int
	 */
	private static function default_language(): int {
		return Scalar::int( Scalar::prop( \PerfLocale\Plugin::get_instance()->lang_repo()->get_default(), 'id' ) );
	}
}
