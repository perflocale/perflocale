<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Migration\WpmlImporter}.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Migration\WpmlImporter;
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
 *   - Crash recovery: lock TTL refreshes per batch, worker resumes.
 *   - Visibility: status / progress under *PerfLocale → Jobs*.
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
	 * The WPML migration runs as a single monolithic call to the importer
	 * with no granular progress emission; it can legitimately take 30+
	 * minutes on big sites. Bump TTL to 4 hours so a second worker can't
	 * reclaim and re-run the (non-idempotent) replace operations.
	 */
	public function get_lock_ttl(): int {
		return 4 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Count rows in WPML's `wp_icl_translations` to estimate cost.
	 * Returns 0 when WPML data isn't present — skips async in that case
	 * (the importer would no-op anyway).
	 */
	protected function args_size( array $args ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( ! $exists ) {
			return 0;
		}

		// $table is the wpdb-prefixed `icl_translations` table name built
		// above — class-controlled string, no user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i',
				$table
			)
		);
	}

	/** {@inheritDoc} */
	public function execute( array $args, callable $progress ): array {
		$progress( 0, 1 );

		$importer = new WpmlImporter( Plugin::get_instance()->get( 'cache' ) );
		$result   = $importer->import();

		// Flush every cache that could be holding pre-import state.
		// See MigrationCacheHelper for the full sequence + rationale.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		$progress( 1, 1 );

		return is_array( $result ) ? $result : [];
	}
}
