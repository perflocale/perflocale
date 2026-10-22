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
 * The meta keys Sync Fields stores, the meta keys whose clears it records,
 * and whether the request is a front-end page view. Kept apart from
 * ContentSync so that registering the keys at boot, and an integration
 * naming its keys, does not load ContentSync, which a request needs only
 * once it writes.
 */
final class SyncMeta {

	/**
	 * Per-post opt-out meta flag - the same key (and the same symmetric
	 * semantics) as WooCommerce's InventorySync: a flagged post is removed
	 * from the sync graph in BOTH directions. It neither receives mirror or
	 * seed writes nor pushes its own fields onto siblings, while the REST of
	 * the group keeps syncing among themselves. Set from the Translations
	 * metabox / Gutenberg panel checkbox (or the WooCommerce Advanced-tab
	 * checkbox on products, which writes the identical key).
	 */
	public const SYNC_OPTOUT_META = '_perflocale_sync_optout';
	/**
	 * Meta keys whose value a person deliberately emptied on this post, one
	 * row per key (the row's value is the key). Written by the Meta Box and
	 * ACF integrations and, for the keys given to track_seed_clears(), by the
	 * meta-API watcher, only while a person edits the post (see
	 * ContentSync::record_seed_clear()). Without it an emptied field reads as "never set": the
	 * next save of the default-language member seeds it again when its rows
	 * are gone, and machine translation fills it when it is empty. Consulted
	 * only when a seed is about to be written, so a sibling holding rows pays
	 * nothing. Machine translation honours it too.
	 */
	public const SEED_CLEARED_META = '_perflocale_seed_cleared';
	/**
	 * A translation whose Sync Fields differ from its group's: a save that
	 * gave the group's original new values skipped it because the saver may
	 * not edit it, or its own save could not give the group's original its
	 * new values for the same reason (ContentSync::sync_siblings()). One row, whose value
	 * holds the status its translation link held before it was set to "needs
	 * update" (`status`; `needs_update` when the link already read that) and
	 * the link's write time after the mark (`at`), and the Sync Fields a
	 * person's save that would have cleared the mark changed while another
	 * request held the post's lock (`pending`, ContentSync::keep_pending_changes()), which
	 * the save that clears it copies too. Clearing the mark gives the link
	 * back that status only while its write time is still `at`: a content
	 * change of the group's original that flagged the translation
	 * after the mark (ContentChangeDetector::flag_translations()) moves that
	 * time past the value it held, also within the same second, and its
	 * "needs update" then stays. While the mark is set, the translation's own
	 * saves copy none of its Sync Fields to the group, so its values never
	 * replace the group's. A person who may edit it and the group's original
	 * saving it from an editing screen (ContentSync::is_person_editing()) clears the mark
	 * (ContentSync::person_save_clears_stale()), once that save's sync holds the post's
	 * lock (ContentSync::settle_stale_mark()). That save copies to the group only the
	 * Sync Fields the person changed in it, and the translation takes the
	 * group's current values for the others (ContentSync::sync_cleared()).
	 */
	public const SYNC_STALE_META = '_perflocale_sync_stale';
	/**
	 * The Sync Fields values a person's save that cleared a translation's
	 * stale mark replaced with the group's (ContentSync::sync_cleared()): `mark` names the
	 * cleared mark (ContentSync::held_mark_id()), `values` maps each replaced field to a
	 * hash of the value the translation held before (ContentSync::held_value()), so the
	 * row stays small whatever the size of a field. The block editor's meta
	 * boxes still show those values: their form, rendered while the mark was
	 * set, carries the mark's name (ContentSync::render_held_field()), and each meta-box
	 * request it sends after a save counts a field it sends back unchanged as
	 * not changed by the person (ContentSync::held_form_save()). One row per post,
	 * replaced by the post's next clearing save and removed when the post is
	 * marked stale again, as no meta-box request can use it then; written
	 * only by clearing saves.
	 */
	public const SYNC_HELD_META = '_perflocale_sync_held';

	/**
	 * Meta keys whose clears the meta-API watcher records in the seed-cleared
	 * marker (key => true), registered by integrations whose host plugin
	 * writes the keys itself; see track_seed_clears().
	 *
	 * @var array<string, true>
	 */
	private static array $tracked = [];

	/**
	 * Prefix of the plugin's own post-meta keys. Sync Fields never mirrors
	 * or seeds one of them, whatever lists it (a stored Sync Fields value, an
	 * import, WP-CLI, the perflocale/sync_fields filters): each describes the
	 * one post it is on. The data registry holds the same rule (post-meta
	 * entries with sync `never`, and the namespace sweep); SyncMetaParityTest
	 * keeps the two equal. A prefix check here, so the save path never loads
	 * the registry.
	 */
	public const INTERNAL_META_PREFIX = '_perflocale_';

	/**
	 * The plugin's own post-meta keys Sync Fields may list: the registry's
	 * post-meta entries with sync `if_listed`, other than another plugin's
	 * meta (class FOREIGN).
	 */
	public const SYNCABLE_INTERNAL_META = [ '_perflocale_seo_exclude' ];

	/**
	 * Whether Sync Fields must leave a meta key alone: a key of the plugin's
	 * own namespace that is not one it may list.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_never_synced( string $key ): bool {
		return str_starts_with( $key, self::INTERNAL_META_PREFIX ) && ! in_array( $key, self::SYNCABLE_INTERNAL_META, true );
	}

	/**
	 * Whether this request is a front-end page view: a GET or HEAD request
	 * outside admin, AJAX, cron, REST and WP-CLI. Meta written while a page
	 * is viewed (a view counter, a cached value) is not an edit: it starts no
	 * sync and records no clear. The post's next save syncs every field.
	 *
	 * @return bool
	 */
	public static function is_page_view(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return ( 'GET' === $method || 'HEAD' === $method ) && ! Helper::is_write_context();
	}

	/**
	 * Record clears of these meta keys in the seed-cleared marker whenever
	 * they are written through the meta API. For integrations whose host
	 * plugin stores its fields with plain meta writes (SEO plugins).
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
	 * Whether clears of a meta key are recorded in the seed-cleared marker
	 * (track_seed_clears()).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_seed_clear_tracked( string $key ): bool {
		return isset( self::$tracked[ $key ] );
	}
}
