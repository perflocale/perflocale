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
 * The one import sequence shared by WP-CLI and the three migration jobs (inline or background).
 *
 * Inside MigrationLock: caller step, language preflight, import, stamp, outcome and the cache flush, which
 * also runs when the import throws (a `blocked` one wrote nothing). CacheInvalidator defers hreflang to it.
 */
final class MigrationRunner {

	/**
	 * Minimum seconds between two progress reports of a migration job.
	 */
	public const PROGRESS_INTERVAL = 2;

	/**
	 * Blogs on which this process is running an importer, by blog id.
	 *
	 * @var array<int, true>
	 */
	private static array $importing = [];

	/**
	 * Whether this process is running an importer on the current blog.
	 *
	 * A run that is not blocked by its preflight (blocked = nothing written) ends with flush_post_migration_caches()
	 * on this blog, also when it throws, so per-change clears can wait.
	 *
	 * @internal Read by CacheInvalidator.
	 *
	 * @return bool
	 */
	public static function importing(): bool {
		return isset( self::$importing[ get_current_blog_id() ] );
	}

	/**
	 * Run an import.
	 *
	 * A MigrationLockedException reaches the caller; an importer or heartbeat exception follows the flush.
	 *
	 * @param string        $source           wpml, polylang or translatepress.
	 * @param CacheManager  $cache            Cache manager.
	 * @param callable|null $before           Runs inside the lock before the import.
	 * @param callable|null $on_beat          Called after each lock refresh with the progress detail ([] when it only keeps the lock).
	 * @param bool          $sanitize_strings WPML only: sanitize string translations (WpmlImporter::set_sanitize_strings()).
	 * @return array<string, mixed> The importer's result, with `blocked` and `preflight`.
	 * @throws \InvalidArgumentException On an unknown source.
	 */
	public static function import( string $source, CacheManager $cache, ?callable $before = null, ?callable $on_beat = null, bool $sanitize_strings = false ): array {
		if ( ! in_array( $source, [ 'wpml', 'polylang', 'translatepress' ], true ) ) {
			throw new \InvalidArgumentException( esc_html( 'Unknown migration source: ' . $source ) );
		}

		return MigrationLock::run(
			static function ( callable $heartbeat ) use ( $source, $cache, $before, $on_beat, $sanitize_strings ): array {
				if ( $before !== null ) {
					$before();
				}

				if ( $on_beat !== null ) {
					$heartbeat = static function ( array $detail = [] ) use ( $heartbeat, $on_beat ): void {
						$heartbeat();
						$on_beat( $detail );
					};
				}

				$blog_id = get_current_blog_id();
				$was     = self::$importing[ $blog_id ] ?? null;

				self::$importing[ $blog_id ] = true;

				try {
					try {
						$result = self::run_importer( $source, $cache, $heartbeat, $on_beat !== null, $sanitize_strings );
					} finally {
						// The record ends before the flush, so a link made inside the flush clears as any other.
						if ( null === $was ) {
							unset( self::$importing[ $blog_id ] );
						} else {
							self::$importing[ $blog_id ] = $was;
						}
					}
				} catch ( \Throwable $stopped ) {
					// A stopped import keeps its committed batches: flush so the front end serves them.
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
	 * Reports at most every PROGRESS_INTERVAL seconds (at once on a stage start or end); percent never goes down.
	 * A bare heartbeat repeats the last report every 30 seconds: it keeps the job's locks fresh and lets a cancel act.
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
	 * A job's view of the result: a refused or unfinished import is a failed run with its reason.
	 *
	 * `run_failed` is not retried: a refusal repeats until the operator acts.
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
	 * The lines listing what an import read and did not link, per reason, in the source's own words.
	 *
	 * @param string               $source Source.
	 * @param array<string, mixed> $result Import result.
	 * @return list<string>
	 */
	public static function skipped_lines( string $source, array $result ): array {
		return match ( $source ) {
			'wpml'     => WpmlImporter::skipped_lines( $result ),
			'polylang' => PolylangImporter::skipped_lines( $result ),
			default    => [],
		};
	}

	/**
	 * Run the source's importer.
	 *
	 * @param string       $source           Source.
	 * @param CacheManager $cache            Cache manager.
	 * @param callable     $heartbeat        Lock heartbeat.
	 * @param bool         $has_reporter     Whether the heartbeat passes the progress on to a reporter.
	 * @param bool         $sanitize_strings WPML only: sanitize string translations.
	 * @return array<string, mixed> With `incomplete_notice` when the import did not finish.
	 */
	private static function run_importer( string $source, CacheManager $cache, callable $heartbeat, bool $has_reporter, bool $sanitize_strings ): array {
		if ( $source === 'wpml' ) {
			$importer = new WpmlImporter( $cache );
			$importer->set_heartbeat( $heartbeat, $has_reporter );
			$importer->set_sanitize_strings( $sanitize_strings );

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

		// Polylang keeps its failures in `errors` and the language check's findings in its notices.
		if ( $source === 'polylang' ) {
			$result['notices'] = array_merge( SourcePreflight::messages( $preflight ), (array) ( $result['notices'] ?? [] ) );
		} else {
			$result['errors'] = array_merge( SourcePreflight::messages( $preflight ), (array) ( $result['errors'] ?? [] ) );
		}

		$result['blocked']   = false;
		$result['preflight'] = $preflight;

		// Only a finished run marks the source imported, so the first-import checks still apply to a rerun.
		if ( empty( $result['incomplete'] ) && array_filter( $preflight['map'], static fn( $slug ): bool => $slug !== null ) !== [] ) {
			MigrationState::mark_imported( $source );
		}

		MigrationState::record_outcome( $source, empty( $result['incomplete'] ) );

		if ( ! empty( $result['incomplete'] ) ) {
			$result['incomplete_notice'] = MigrationState::incomplete_message( [ $source ] );
		}

		return $result;
	}
}
