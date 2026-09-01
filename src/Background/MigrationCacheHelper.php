<?php
/**
 * Post-migration cache flush helper.
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
 * Shared cache-invalidation sequence called by every importer-driven
 * BG job (WPML / Polylang / TranslatePress migration + data import).
 *
 * Owns the canonical sequence so a future cache layer doesn't need
 * four parallel updates across four jobs to stay in sync. Lives on
 * its own class because the prior home (a static method on
 * `WpmlMigrationJob`) misleadingly implied WPML ownership to the
 * three other callers that already reached across the namespace to
 * invoke it.
 *
 * Flushes:
 *   - L1 static memos on TranslationGroupRepository
 *     ($find_cache, $has_any_groups_memo, $eager_link_map_memo).
 *   - Autoloaded eager-link option rows that survive cross-process
 *     imports (perflocale_eager_links_post / _term / _has_any_groups).
 *   - L2 cache groups via CacheManager::flush_all.
 *
 * Without this sequence, long-running CLI / cron workers continue to
 * serve pre-import group memos for the rest of their process
 * lifetime, and operators who restored from backup before re-running
 * the importer see stale eager-link rows that pre-date the restore.
 */
final class MigrationCacheHelper {

	/**
	 * Flush every cache that could be holding pre-import state.
	 *
	 * Safe to call when no migration ran (e.g. dry-run paths) — every
	 * flush is idempotent on already-empty state.
	 *
	 * @return void
	 */
	public static function flush_post_migration_caches(): void {
		\PerfLocale\Database\Repository\TranslationGroupRepository::reset_static_caches();

		$cache = Plugin::get_instance()->has( 'cache' ) ? Plugin::get_instance()->get( 'cache' ) : null;

		// Eager-link maps are autoloaded and may contain links from BEFORE the
		// import. Route through invalidate_eager_link_map(null) so ALL FIVE
		// object-type variants (post/term/string/post_type/taxonomy) are purged
		// — not just post/term — and each single-option cache key is cleared
		// unconditionally (delete_option() returns early without touching the
		// cache when the DB row is already gone, so a stale autoloaded value
		// restored from backup would otherwise survive inside alloptions).
		if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$groups = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );
			$groups->invalidate_eager_link_map();
		}

		delete_option( 'perflocale_has_any_groups' );
		wp_cache_delete( 'perflocale_has_any_groups', 'options' );

		if ( $cache instanceof \PerfLocale\Cache\CacheManager ) {
			$cache->flush_all();

			// Files mode serves string translations from generated .l10n.php
			// files, which no cache flush can refresh — without a full
			// regeneration the just-imported string translations exist in
			// the DB while the frontend keeps serving the pre-migration
			// files. Migrations are rare one-shots; one full pass is the
			// correct price.
			if ( Plugin::get_instance()->has( 'settings' )
				&& Plugin::get_instance()->get( 'settings' )->get( 'string_translation_mode' ) === 'files'
			) {
				( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->generate_all();
			}
		}
	}

	/**
	 * Bound per-process memory between import batches on huge migrations.
	 *
	 * The same pattern WooCommerce's batch processors and WP-CLI's own
	 * long-command helper use: a legacy-site import touches tens of
	 * thousands of posts/terms, and every get_post()/get_term() copy
	 * accumulates in the RUNTIME object cache for the life of the request
	 * — unbounded growth on WPML-scale imports. Dropping the runtime
	 * copies between batches caps that; the next read simply re-fetches
	 * (from the persistent cache when one is configured, else the DB), so
	 * correctness is unaffected.
	 *
	 * Also empties $wpdb->queries, which grows one entry per query for the
	 * whole request when SAVEQUERIES is on (the actual culprit in a past
	 * WPML-import OOM diagnosed on a dev site).
	 *
	 * Cheap and idempotent — callers invoke it once per committed batch.
	 *
	 * @return void
	 */
	public static function release_batch_memory(): void {
		global $wpdb;

		$wpdb->queries = [];

		// WP 6.1+ (plugin floor 6.4). Clears ONLY in-memory runtime copies;
		// a persistent backend (Redis/Memcached) is untouched. Drop-ins
		// lacking flush_runtime() make this a silent no-op.
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
}
