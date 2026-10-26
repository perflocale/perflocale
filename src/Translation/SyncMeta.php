<?php
/**
 * Sync Fields meta keys and the parts of Sync Fields every request may use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Fields meta keys, tracked seed-clear keys and the page-view check.
 * Kept out of ContentSync so boot-time key registration does not load it.
 */
final class SyncMeta {

	/**
	 * Per-post Sync Fields opt-out flag.
	 * Same key as WooCommerce InventorySync: removes the post from syncing in both directions.
	 */
	public const SYNC_OPTOUT_META = '_perflocale_sync_optout';
	/**
	 * Meta keys a person deliberately emptied on this post, one row per key.
	 * Without it an emptied field reads as "never set" and seeding or machine translation refills it.
	 */
	public const SEED_CLEARED_META = '_perflocale_seed_cleared';
	/**
	 * Stale mark: this translation's Sync Fields differ from its group's (a permission-skipped sync).
	 * Restores the link `status` only while its write time is still `at`; see docs-ai/11-code-rationale.md, "SyncMeta: stale mark".
	 */
	public const SYNC_STALE_META = '_perflocale_sync_stale';
	/**
	 * Hashes of the values a stale-mark clearing save replaced (`mark`, `values`).
	 * Block editor meta boxes resend those old values; see docs-ai/11-code-rationale.md, "SyncMeta: held values".
	 */
	public const SYNC_HELD_META = '_perflocale_sync_held';

	/**
	 * Meta keys whose clears the meta-API watcher records (track_seed_clears()).
	 *
	 * @var array<string, true>
	 */
	private static array $tracked = [];

	/**
	 * Prefix of the plugin's own post-meta keys, which Sync Fields never copies.
	 * Mirrors the data registry rule (SyncMetaParityTest) so the save path never loads the registry.
	 */
	public const INTERNAL_META_PREFIX = '_perflocale_';

	/**
	 * The plugin's own post-meta keys Sync Fields may list (registry sync `if_listed`).
	 */
	public const SYNCABLE_INTERNAL_META = [ '_perflocale_seo_exclude' ];

	/**
	 * Whether Sync Fields must leave a meta key alone.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_never_synced( string $key ): bool {
		return str_starts_with( $key, self::INTERNAL_META_PREFIX ) && ! in_array( $key, self::SYNCABLE_INTERNAL_META, true );
	}

	/**
	 * Whether this request is a front-end GET/HEAD page view.
	 * Meta written during a view (a counter, a cache) is not an edit: it starts no sync and records no clear.
	 *
	 * @return bool
	 */
	public static function is_page_view(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return ( 'GET' === $method || 'HEAD' === $method ) && ! Helper::is_write_context();
	}

	/**
	 * Records clears of these meta keys in the seed-cleared marker.
	 * For host plugins that store fields with plain meta writes (SEO plugins).
	 *
	 * @param array<int, mixed> $keys Meta keys.
	 * @return void
	 */
	public static function track_seed_clears( array $keys ): void {
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && $key !== '' ) {
				self::$tracked[ $key ] = true;
			}
		}
	}

	/**
	 * Whether clears of a meta key are recorded in the seed-cleared marker.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_seed_clear_tracked( string $key ): bool {
		return isset( self::$tracked[ $key ] );
	}
}
