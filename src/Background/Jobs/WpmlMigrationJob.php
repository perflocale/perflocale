<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Migration\WpmlImporter}.
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
 * Runs the WPML migration in the background.
 *
 * The underlying importer already chunks per-table and tolerates
 * being run multiple times (idempotent — re-running picks up where it
 * left off via existing translation-group lookups). The job layer adds:
 *
 *   - Crash recovery: the importer's heartbeat reports progress after each
 *     batch, which keeps the job's locks and status fresh; a killed worker's
 *     lock expires within {@see get_lock_ttl()} and the job is then marked
 *     failed ({@see \PerfLocale\Background\JobState::worker_gone()}).
 *   - Visibility: status, the stage and its items done / in total, and the
 *     overall progress under *PerfLocale → Jobs*
 *     ({@see MigrationRunner::job_reporter()}).
 *   - Retry: failed runs auto-retry up to 5 attempts.
 *
 * Args shape: none (the importer reads from `wp_icl_translations` and
 * `wp_icl_strings` in the current blog). Multisite: dispatch on the
 * blog that has WPML data; the worker runs in the same blog context.
 */
final class WpmlMigrationJob extends AbstractJob {

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'wpml_migration';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Mirrors the existing handler at AdminController::handle_migrate_wpml
	 * which gates on `manage_options`.
	 */
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
	 * The importer reports progress after every batch (at most every 30
	 * seconds), and each report refreshes the lock, so the lock only has to
	 * outlive the longest gap between two batches: the same lifetime as the
	 * import lock's heartbeat.
	 */
	public function get_lock_ttl(): int {
		return MigrationLock::HEARTBEAT_TTL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The rows the import reads: WPML's `icl_translations` plus its string
	 * translations (`icl_string_translations`). A site with few posts and a
	 * big string table still runs in the background. Returns 0 when WPML
	 * data isn't present (the importer would no-op anyway).
	 */
	protected function args_size( array $args ): int {
		global $wpdb;

		$size = 0;

		foreach ( [ 'icl_translations', 'icl_string_translations' ] as $name ) {
			$table = $wpdb->prefix . $name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off count before an admin-triggered import.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off count before an admin-triggered import.
			$size += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		}

		return $size;
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

		$result = MigrationRunner::import( 'wpml', Plugin::get_instance()->get( 'cache' ), null, MigrationRunner::job_reporter( $progress ) );

		return MigrationRunner::job_result( $result );
	}
}
