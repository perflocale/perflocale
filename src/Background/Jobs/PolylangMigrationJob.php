<?php
/**
 * Tier-2 wrapper for {@see \PerfLocale\Migration\PolylangImporter}.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

use PerfLocale\Background\AbstractJob;
use PerfLocale\Migration\PolylangImporter;
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
	 * Polylang migration runs as a single monolithic importer call. Lift
	 * the lock TTL so the lock doesn't expire mid-flight on big sites.
	 */
	public function get_lock_ttl(): int {
		return 4 * HOUR_IN_SECONDS;
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

	/** {@inheritDoc} */
	public function execute( array $args, callable $progress ): array {
		$progress( 0, 1 );

		$importer = new PolylangImporter( Plugin::get_instance()->get( 'cache' ) );
		$result   = $importer->import();

		// See MigrationCacheHelper for the full sequence + rationale.
		\PerfLocale\Background\MigrationCacheHelper::flush_post_migration_caches();

		$progress( 1, 1 );

		return is_array( $result ) ? $result : [];
	}
}
