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
	 * Flush every cache that could hold pre-import state; idempotent, safe after a dry run. Each step runs when another
	 * throws (a listener of a flush action), then the first throw.
	 *
	 * @param bool $regenerate In files mode, regenerate the string files (false: the caller does it later).
	 * @return void
	 */
	public static function flush_post_migration_caches( bool $regenerate = true ): void {
		$cache = Plugin::get_instance()->has( 'cache' ) ? Plugin::get_instance()->get( 'cache' ) : null;
		$cache = $cache instanceof \PerfLocale\Cache\CacheManager ? $cache : null;

		\PerfLocale\Database\Tx::each(
			static fn() => \PerfLocale\Database\Repository\TranslationGroupRepository::reset_static_caches(),
			// invalidate_eager_link_map( null ) purges all five object types and clears each option's cache key even when the
			// row is gone (delete_option() skips the cache then, so a value restored from a backup would survive in alloptions).
			static function () use ( $cache ): void {
				if ( null !== $cache ) {
					( new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache ) )->invalidate_eager_link_map();
				}
			},
			// has_any_groups() pins its TRUE answer in the object cache with no expiry and outside the cache generations
			// flush_all() bumps.
			static function (): void {
				wp_cache_delete( 'has_any_groups', 'perflocale_trans' );
			},
			static function (): void {
				delete_option( 'perflocale_has_any_groups' );
				wp_cache_delete( 'perflocale_has_any_groups', 'options' );
				// Back as '' (check the table): a missing row is a query per front-end request.
				update_option( 'perflocale_has_any_groups', '', true );
			},
			static function () use ( $cache, $regenerate ): void {
				if ( null === $cache ) {
					return;
				}

				$cache->flush_all();

				// Files mode serves strings from generated .l10n.php files, which no cache flush refreshes: regenerate them all.
				if ( $regenerate && Plugin::get_instance()->has( 'settings' )
					&& Plugin::get_instance()->get( 'settings' )->get( 'string_translation_mode' ) === 'files'
				) {
					( new \PerfLocale\Strings\TranslationFileGenerator( $cache ) )->generate_all();
				}
			}
		);
	}

	/**
	 * Bound process memory between import batches: drop the runtime object cache copies and the SAVEQUERIES log.
	 *
	 * Runtime copies of every imported post and term otherwise grow without limit; the next read re-fetches them.
	 *
	 * @param bool $keep_options Keep the autoloaded options in memory (flush_runtime_keeping_options()) unless another
	 *                           process changed cached data.
	 * @return bool Whether another process changed cached data since the
	 *              process's last checkpoint (CacheManager::refresh_if_changed()).
	 */
	public static function release_batch_memory( bool $keep_options = false ): bool {
		global $wpdb;

		$wpdb->queries = [];

		// A batch boundary is a checkpoint: what another process changed meanwhile is read again from here on.
		$changed = \PerfLocale\Cache\CacheManager::refresh_if_changed();

		// Clears only in-memory copies, never a persistent backend; a drop-in without flush_runtime() makes it a no-op.
		if ( $keep_options && ! $changed ) {
			self::flush_runtime_keeping_options();
		} else {
			\PerfLocale\Cache\CacheManager::flush_runtime();
		}

		return $changed;
	}

	/**
	 * CacheManager::flush_runtime(), with this blog's alloptions and notoptions put back in memory only, as a request
	 * that never flushed holds them: a reload costs twice the autoloaded bytes. A drop-in without memory-only writes
	 * loses them. See docs-ai/11-code-rationale.md, "MigrationCacheHelper: options kept across a release".
	 *
	 * @return void
	 */
	public static function flush_runtime_keeping_options(): void {
		$ext  = wp_using_ext_object_cache();
		$api  = $ext ? self::memory_api() : null;
		$kept = [];

		foreach ( [ 'alloptions', 'notoptions' ] as $key ) {
			if ( null !== $api ) {
				$derived = ( $api['build'] )( $key, 'options' );
				$value   = is_string( $derived ) ? ( $api['get'] )( $derived ) : false;

				if ( is_string( $derived ) && false !== $value ) {
					$kept[ $derived ] = $value;
				}
			} elseif ( ! $ext ) {
				$found = false;
				$value = wp_cache_get( $key, 'options', false, $found );

				if ( $found ) {
					$kept[ $key ] = $value;
				}
			}
		}

		if ( ! \PerfLocale\Cache\CacheManager::flush_runtime() ) {
			return;
		}

		foreach ( $kept as $key => $value ) {
			if ( null !== $api ) {
				( $api['add'] )( $key, $value );
			} else {
				// Core's cache is memory only.
				wp_cache_set( $key, $value, 'options' );
			}
		}
	}

	/**
	 * The persistent drop-in's key builder and in-memory get and set by built key (Redis Object Cache); null without them.
	 *
	 * @return array{build: callable, get: callable, add: callable}|null
	 */
	private static function memory_api(): ?array {
		$cache = $GLOBALS['wp_object_cache'] ?? null;

		if ( ! is_object( $cache ) ) {
			return null;
		}

		$build = [ $cache, 'build_key' ];
		$get   = [ $cache, 'get_from_internal_cache' ];
		$add   = [ $cache, 'add_to_internal_cache' ];

		if ( ! is_callable( $build ) || ! is_callable( $get ) || ! is_callable( $add ) ) {
			return null;
		}

		return [
			'build' => $build,
			'get'   => $get,
			'add'   => $add,
		];
	}
}
