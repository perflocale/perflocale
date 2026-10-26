<?php
/**
 * Cache flush after an import.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cache flush every importer job runs (WPML, Polylang, TranslatePress migrations and data import): group memos,
 * the eager-link options, the has_any_groups key, the L2 groups and, in files mode, the generated string files.
 * Otherwise long-running workers keep serving pre-import data.
 */
final class MigrationCacheHelper {

	/**
	 * Flush every cache that could hold pre-import state; idempotent, safe after a dry run.
	 *
	 * @return void
	 */
	public static function flush_post_migration_caches(): void {
		\PerfLocale\Database\Repository\TranslationGroupRepository::reset_static_caches();

		$cache = Plugin::get_instance()->has( 'cache' ) ? Plugin::get_instance()->get( 'cache' ) : null;

		// invalidate_eager_link_map( null ) purges all five object types and clears each option's cache key even when the
		// row is gone (delete_option() skips the cache then, so a value restored from a backup would survive in alloptions).
		if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$groups = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );
			$groups->invalidate_eager_link_map();
		}

		delete_option( 'perflocale_has_any_groups' );
		wp_cache_delete( 'perflocale_has_any_groups', 'options' );
		// has_any_groups() pins its TRUE answer in the object cache with no
		// expiry and outside the cache generations flush_all() bumps.
		wp_cache_delete( 'has_any_groups', 'perflocale_trans' );

		if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$cache->flush_all();

			// Files mode serves strings from generated .l10n.php files, which no cache flush refreshes: regenerate them all.
			if ( Plugin::get_instance()->has( 'settings' )
				&& Plugin::get_instance()->get( 'settings' )->get( 'string_translation_mode' ) === 'files'
			) {
				( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->generate_all();
			}
		}
	}

	/**
	 * Bound process memory between import batches: drop the runtime object cache copies and the SAVEQUERIES log.
	 *
	 * Runtime copies of every imported post and term otherwise grow without limit; the next read re-fetches them.
	 *
	 * @return bool Whether another process changed cached data since the
	 *              process's last checkpoint (CacheManager::refresh_if_changed()).
	 */
	public static function release_batch_memory(): bool {
		global $wpdb;

		$wpdb->queries = [];

		// A batch boundary is a checkpoint: what another process changed meanwhile is read again from here on.
		$changed = \PerfLocale\Cache\CacheManager::refresh_if_changed();

		// Clears only in-memory copies, never a persistent backend; a drop-in without flush_runtime() makes it a no-op.
		\PerfLocale\Cache\CacheManager::flush_runtime();

		return $changed;
	}
}
