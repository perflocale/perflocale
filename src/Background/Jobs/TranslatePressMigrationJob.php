<?php
/**
 * Background job for the TranslatePress importer.
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
 * Runs the TranslatePress migration in the background. The heaviest importer (it rebuilds post bodies from TP's
 * dictionary), hence the lower threshold; an inline run raises the time limit itself (TranslatePressImporter).
 */
final class TranslatePressMigrationJob extends AbstractJob {

	/** {@inheritDoc} */
	public function get_type(): string {
		return 'translatepress_migration';
	}

	/** {@inheritDoc} */
	public function get_required_capability(): string {
		return 'manage_options';
	}

	/** {@inheritDoc} */
	public function get_default_threshold(): int {
		return 200;
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
	 * TranslatePress's `trp_original_strings` rows; 0 without TranslatePress (nothing to migrate).
	 */
	protected function args_size( array $args ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'trp_original_strings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( ! $exists ) {
			return 0;
		}

		// $table is the prefixed `trp_original_strings` name built above, not user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * In the shared import lock (MigrationRunner): a held lock's exception reaches the worker (retry) or the inline
	 * run. A refused or unfinished import (a TP read or a write failed) returns a failed run with its reason.
	 */
	public function execute( array $args, callable $progress ): array {
		$progress( 0, 1 );

		$result = MigrationRunner::import( 'translatepress', Plugin::get_instance()->get( 'cache' ), null, MigrationRunner::job_reporter( $progress ) );

		return MigrationRunner::job_result( $result );
	}
}
