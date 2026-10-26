<?php
/**
 * Background job for the Polylang importer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Migration\MigrationLock;
use PerfLocale\Migration\MigrationRunner;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the Polylang migration in the background. It reads Polylang's translation terms and never writes them, so a
 * re-run is safe.
 */
final class PolylangMigrationJob extends AbstractJob {

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'polylang_migration';
	}

	/** {@inheritDoc} */
	public function get_required_capability(): string {
		return 'manage_options';
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		return 500;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The import lock's heartbeat TTL: a report at least every 30 s refreshes the lock, so it only outlives the longest
	 * gap between batches and a killed worker is found within minutes (JobState::worker_gone()).
	 */
	public function get_lock_ttl(): int {
		return MigrationLock::HEARTBEAT_TTL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Polylang's translation terms in `wp_term_taxonomy` as the migration's cost.
	 */
	protected function args_size( array $args ): int {
		global $wpdb;
		// Both taxonomies: import() walks post and term groups; counting one would send a term-heavy site to an
		// inline run (a PHP-FPM timeout risk).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ( %s, %s )",
				'post_translations',
				'term_translations'
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * In the shared import lock (MigrationRunner): a held lock's exception reaches the worker (retry) or the inline
	 * run. A refused import returns a failed run; heartbeats go to MigrationRunner::job_reporter().
	 */
	public function execute( array $args, callable $progress ): array {
		$progress( 0, 1 );

		$result = MigrationRunner::import( 'polylang', Plugin::get_instance()->get( 'cache' ), null, MigrationRunner::job_reporter( $progress ) );

		return MigrationRunner::job_result( $result );
	}
}
