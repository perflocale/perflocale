<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Migration\TranslatePressImporter}.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Migration\TranslatePressImporter;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the TranslatePress migration in the background.
 *
 * TranslatePress's content reconstruction is the heaviest of the three
 * importers (it rebuilds full post bodies by substituting matched strings
 * from TP's dictionary into the original post). Sync runs are protected
 * by a `set_time_limit(300)` workaround; this job replaces that workaround
 * entirely once a site routes through Dispatcher.
 *
 * Threshold is deliberately lower than WPML / Polylang because the
 * per-row work is more expensive (content reconstruction vs. table-row
 * relinking).
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
	 * TranslatePress migration runs as a single monolithic importer call;
	 * lift the lock TTL so the lock doesn't expire mid-flight on big sites.
	 */
	public function get_lock_ttl(): int {
		return 4 * HOUR_IN_SECONDS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Count TP's `wp_trp_original_strings` rows. If TP isn't installed
	 * we return 0 (no migration needed → sync no-op is fine).
	 */
	protected function args_size( array $args ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'trp_original_strings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( ! $exists ) {
			return 0;
		}

		// $table is the wpdb-prefixed `trp_original_strings` table name
		// built above — class-controlled string, no user input.
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

		$importer = new TranslatePressImporter( Plugin::get_instance()->get( 'cache' ) );
		$result   = $importer->import();

		// See MigrationCacheHelper for the full sequence + rationale.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		$progress( 1, 1 );

		return is_array( $result ) ? $result : [];
	}
}
