<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Migration\PolylangImporter}.
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
 * Runs the Polylang migration in the background.
 *
 * Polylang stores translation groups as serialised PHP arrays in the
 * `description` field of `post_translations` / `term_translations`
 * taxonomy terms. The importer walks those and rebuilds groups in
 * PerfLocale's tables. It does NOT touch Polylang's data, so re-runs
 * are safe.
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
	 * The importer reports progress after every batch, and a report at
	 * least every 30 seconds refreshes the lock, so the lock only has to
	 * outlive the longest gap between two batches: the same lifetime as the
	 * import lock's heartbeat. A killed worker's job is then found within
	 * minutes ({@see \PerfLocale\Background\JobState::worker_gone()}) and a
	 * new import can start.
	 */
	public function get_lock_ttl(): int {
		return MigrationLock::HEARTBEAT_TTL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Polylang's translation taxonomy lives in `wp_term_taxonomy`. Count
	 * `post_translations` rows as a proxy for migration cost.
	 */
	protected function args_size( array $args ): int {
		global $wpdb;
		// Count BOTH translation taxonomies: import() processes post_translations
		// AND term_translations, so a term-heavy site was under-counted and could
		// wrongly run inline (risking a PHP-FPM timeout) instead of async.
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
	 * Runs inside the shared import lock ({@see MigrationRunner}). When
	 * another import holds it, the exception is left to the worker, which
	 * retries the job later; the admin's inline run shows its message. A
	 * refused import is returned as a failed run with its reason. Each
	 * import heartbeat goes to {@see MigrationRunner::job_reporter()}.
	 */
	public function execute( array $args, callable $progress ): array {
		$progress( 0, 1 );

		$result = MigrationRunner::import( 'polylang', Plugin::get_instance()->get( 'cache' ), null, MigrationRunner::job_reporter( $progress ) );

		return MigrationRunner::job_result( $result );
	}
}
