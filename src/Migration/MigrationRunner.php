<?php
/**
 * Runs an import from WPML, Polylang or TranslatePress the same way on every path.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Background\MigrationCacheHelper;
use PerfLocale\Cache\CacheManager;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one import sequence shared by WP-CLI and the three migration jobs
 * (which the admin buttons run inline or in the background).
 *
 * Inside {@see MigrationLock}: the caller's own step (WP-CLI's
 * `--force-restart` clearing), the Polylang / TranslatePress language
 * check ({@see SourcePreflight}; the WPML importer checks for itself), the
 * import, the migration stamp, whether the import finished
 * ({@see MigrationState::record_outcome()}), and the post-import cache
 * flush. A refused import (`blocked`) writes nothing and flushes nothing.
 * An import that throws part-way is flushed too before the exception
 * leaves the lock.
 */
final class MigrationRunner {

	/**
	 * Minimum seconds between two progress reports of a migration job.
	 */
	public const PROGRESS_INTERVAL = 2;

	/**
	 * Run an import.
	 *
	 * A MigrationLockedException from {@see MigrationLock::run()} reaches the
	 * caller when another import holds the lock. An exception from the
	 * importer or a heartbeat callback is passed on after the post-import
	 * flush.
	 *
	 * @param string        $source wpml, polylang or translatepress.
	 * @param CacheManager  $cache  Cache manager.
	 * @param callable|null $before  Runs inside the lock before the import.
	 * @param callable|null $on_beat Called with each importer heartbeat (after each batch), after the lock refresh, with the progress detail ({@see ImportHeartbeat}; an empty array when the call only keeps the lock).
	 * @return array<string, mixed> The importer's result, with `blocked` and `preflight`.
	 * @throws \InvalidArgumentException On an unknown source.
	 */
	public static function import( string $source, CacheManager $cache, ?callable $before = null, ?callable $on_beat = null ): array {
		if ( ! in_array( $source, [ 'wpml', 'polylang', 'translatepress' ], true ) ) {
			throw new \InvalidArgumentException( esc_html( 'Unknown migration source: ' . $source ) );
		}

		return MigrationLock::run(
			static function ( callable $heartbeat ) use ( $source, $cache, $before, $on_beat ): array {
				if ( $before !== null ) {
					$before();
				}

				if ( $on_beat !== null ) {
					$heartbeat = static function ( array $detail = [] ) use ( $heartbeat, $on_beat ): void {
						$heartbeat();
						$on_beat( $detail );
					};
				}

				try {
					$result = self::run_importer( $source, $cache, $heartbeat, $on_beat !== null );
				} catch ( \Throwable $stopped ) {
					// An import stopped part-way (a cancel or a paused queue at a
					// heartbeat, or an error) keeps the batches it committed:
					// flush so the front end serves them, then pass the
					// exception on.
					try {
						MigrationCacheHelper::flush_post_migration_caches();
					} catch ( \Throwable $flush_error ) {
						unset( $flush_error );
					}

					throw $stopped;
				}

				if ( empty( $result['blocked'] ) ) {
					MigrationCacheHelper::flush_post_migration_caches();
				}

				return $result;
			},
			true
		);
	}

	/**
	 * The heartbeat reporter of a migration job.
	 *
	 * Passes the importer's progress to the job's progress callback: the
	 * stage's items done and in total, and in the detail the stage key, its
	 * label and the import's overall percent, which never goes down. It
	 * reports at most every {@see PROGRESS_INTERVAL} seconds, and at once
	 * when a stage starts or reaches its total. A heartbeat without progress
	 * repeats the last report at most every 30 seconds, which keeps the
	 * job's locks and status fresh; a cancel or a paused queue then takes
	 * effect between two batches.
	 *
	 * @param callable $progress The job's progress callback: `function(int $processed, int $total, array $detail = []): void`.
	 * @return callable(array<string, mixed>=):void
	 */
	public static function job_reporter( callable $progress ): callable {
		$last     = 0;
		$stage    = '';
		$done     = 0;
		$total    = 0;
		$percent  = 0;
		$finished = false;

		return static function ( array $detail = [] ) use ( $progress, &$last, &$stage, &$done, &$total, &$percent, &$finished ): void {
			$now = time();

			if ( ! isset( $detail['stage'] ) || ! is_string( $detail['stage'] ) || $detail['stage'] === '' ) {
				if ( $now - $last < 30 ) {
					return;
				}
			} else {
				$new_stage = $detail['stage'] !== $stage;
				$done      = max( 0, (int) ( $detail['processed'] ?? 0 ) );
				$total     = max( 0, (int) ( $detail['total'] ?? 0 ) );
				$reached   = $total > 0 && $done >= $total;

				if ( $new_stage ) {
					$stage    = $detail['stage'];
					$finished = false;
				}

				$percent = max( $percent, (int) ( $detail['percent'] ?? 0 ) );

				if ( ! $new_stage && ! ( $reached && ! $finished ) && $now - $last < self::PROGRESS_INTERVAL ) {
					return;
				}

				$finished = $reached;
			}

			$last = $now;

			if ( $stage === '' ) {
				$progress( 0, 1 );
				return;
			}

			$progress(
				$done,
				$total,
				[
					'stage'   => $stage,
					'label'   => self::stage_label( $stage ),
					'percent' => $percent,
				]
			);
		};
	}

	/**
	 * The label of an import stage, as the Jobs page shows it.
	 *
	 * @param string $stage Stage key.
	 * @return string The label, or the key itself when it is not an import stage.
	 */
	public static function stage_label( string $stage ): string {
		return self::stage_labels()[ $stage ] ?? $stage;
	}

	/**
	 * Every import stage's label, by stage key.
	 *
	 * @return array<string, string>
	 */
	public static function stage_labels(): array {
		return [
			/* translators: Stage of a migration import: linking posts, pages and products with their translations. */
			'posts'        => __( 'Posts', 'perflocale' ),
			/* translators: Stage of a migration import: linking categories, tags and other terms with their translations. */
			'terms'        => __( 'Terms', 'perflocale' ),
			/* translators: Stage of a migration import: posts that exist in one language only. */
			'single_posts' => __( 'Posts in one language', 'perflocale' ),
			/* translators: Stage of a migration import: terms that exist in one language only. */
			'single_terms' => __( 'Terms in one language', 'perflocale' ),
			/* translators: Stage of a migration import: translated category and tag names. */
			'term_names'   => __( 'Term names', 'perflocale' ),
			/* translators: Stage of a migration import: checking that posts use categories and tags of their own language. */
			'term_check'   => __( 'Category and tag check', 'perflocale' ),
			/* translators: Stage of a migration import: navigation menus. */
			'menus'        => __( 'Menus', 'perflocale' ),
			/* translators: Stage of a migration import: string translations. */
			'strings'      => __( 'Strings', 'perflocale' ),
			/* translators: Stage of a migration import: menus, strings and settings kept outside posts and terms. */
			'site'         => __( 'Menus, strings and settings', 'perflocale' ),
			/* translators: Stage of a migration import: translated URL slugs. */
			'slugs'        => __( 'Slugs', 'perflocale' ),
			/* translators: Stage of a migration import: the language of WooCommerce orders. */
			'orders'       => __( 'Order languages', 'perflocale' ),
		];
	}

	/**
	 * A job's view of the result: a refused import, or one that did not
	 * finish, is a failed run with its reason.
	 *
	 * The worker records a result carrying `run_failed` as failed and does
	 * not retry it (the refusal would repeat until the operator acts; an
	 * unfinished import keeps what it imported, and the operator runs it
	 * again).
	 *
	 * @param array<string, mixed> $result Import result.
	 * @return array<string, mixed>
	 */
	public static function job_result( array $result ): array {
		if ( ! empty( $result['blocked'] ) ) {
			$errors                = (array) ( $result['errors'] ?? [] );
			$result['run_failed']  = true;
			$result['first_error'] = (string) ( $errors[0] ?? '' );
		} elseif ( ! empty( $result['incomplete'] ) ) {
			$result['run_failed']  = true;
			$result['first_error'] = (string) ( $result['incomplete_notice'] ?? '' );
		}

		return $result;
	}

	/**
	 * Run the source's importer.
	 *
	 * @param string       $source       Source.
	 * @param CacheManager $cache        Cache manager.
	 * @param callable     $heartbeat    Lock heartbeat.
	 * @param bool         $has_reporter Whether the heartbeat passes the progress on to a reporter.
	 * @return array<string, mixed> With `incomplete_notice` when the import did not finish.
	 */
	private static function run_importer( string $source, CacheManager $cache, callable $heartbeat, bool $has_reporter ): array {
		if ( $source === 'wpml' ) {
			$importer = new WpmlImporter( $cache );
			$importer->set_heartbeat( $heartbeat, $has_reporter );

			return $importer->import();
		}

		$importer = $source === 'polylang' ? new PolylangImporter( $cache ) : new TranslatePressImporter( $cache );
		$importer->set_heartbeat( $heartbeat, $has_reporter );

		if ( ! $importer->can_import() ) {
			return $importer->import();
		}

		$languages = Plugin::get_instance()->get( 'lang_repo' );
		$preflight = $source === 'polylang'
			? SourcePreflight::polylang( $languages )
			: SourcePreflight::translatepress( $languages );

		if ( $preflight['blocking'] ) {
			return SourcePreflight::blocked_result( $preflight );
		}

		$result = $importer->import();

		$result['errors']    = array_merge( SourcePreflight::messages( $preflight ), (array) ( $result['errors'] ?? [] ) );
		$result['blocked']   = false;
		$result['preflight'] = $preflight;

		if ( array_filter( $preflight['map'], static fn( $slug ): bool => $slug !== null ) !== [] ) {
			MigrationState::mark_imported( $source );
		}

		MigrationState::record_outcome( $source, empty( $result['incomplete'] ) );

		if ( ! empty( $result['incomplete'] ) ) {
			$result['incomplete_notice'] = MigrationState::incomplete_message( [ $source ] );
		}

		return $result;
	}
}
