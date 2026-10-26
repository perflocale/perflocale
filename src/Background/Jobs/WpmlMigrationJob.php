<?php
/**
 * Background job for the WPML importer.
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
 * Runs the WPML migration in the background. The importer is idempotent (a re-run resumes through existing groups);
 * the job adds heartbeat crash recovery, status under PerfLocale > Jobs and up to 5 attempts. No args: it reads the
 * current blog's `icl_*` tables, so dispatch on the blog with WPML data.
 */
final class WpmlMigrationJob extends AbstractJob {

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'wpml_migration';
	}

	/**
	 * {@inheritDoc}
	 *
	 * `manage_options`, as the admin's migrate_wpml action.
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
	 * The import lock's heartbeat TTL: each report (at most 30 s apart) refreshes the lock, so it only outlives the
	 * longest gap between batches.
	 */
	public function get_lock_ttl(): int {
		return MigrationLock::HEARTBEAT_TTL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * `icl_translations` plus `icl_string_translations` rows, so a big string table alone still runs in the background;
	 * 0 without WPML data.
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
	 * In the shared import lock (MigrationRunner): a held lock's exception reaches the worker (retry) or the inline
	 * run. A refused import returns a failed run; heartbeats go to MigrationRunner::job_reporter().
	 */
	public function execute( array $args, callable $progress ): array {
		$progress( 0, 1 );

		// Strings are stored at the requesting user's trust level, the current user on both paths (inline: their
		// request; worker: WorkerRunner switches to `created_by`). WP-CLI passes no flag (WPML's bytes kept).
		$sanitize_strings = ! current_user_can( 'unfiltered_html' );

		$result = MigrationRunner::import( 'wpml', Plugin::get_instance()->get( 'cache' ), null, MigrationRunner::job_reporter( $progress ), $sanitize_strings );

		return MigrationRunner::job_result( $result );
	}
}
