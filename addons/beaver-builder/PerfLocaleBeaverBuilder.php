<?php
/**
 * PerfLocale Beaver Builder addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Beaver Builder integration for PerfLocale.
 *
 * Translates BB module text content stored as post meta
 * and provides a language switcher BB module.
 */
final class PerfLocaleBeaverBuilder implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'beaver-builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Beaver Builder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [ 'bb-plugin/fl-builder.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return class_exists( 'FLBuilder' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Add BB data as translatable meta.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// ⚠️ Only the TEXT-FREE keys mirror — see add_mirror_keys() for what is on
		// that list and why the builder document is not. A mirror is
		// bidirectional, so mirroring a document that carries the user's words
		// destroys translated text in both directions.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// A raw meta mirror of the layout leaves the sibling's GENERATED
		// asset cache (uploads/bb-plugin/cache/{id}-layout*.css/js) built
		// from the pre-sync layout; drop it so BB re-renders on next view.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_asset_cache' ], 10, 3 );

		// Register Language Switcher module for Beaver Builder.
		add_action( 'init', [ $this, 'register_module' ] );
	}

	/**
	 * Register the Language Switcher BB module.
	 *
	 * @return void
	 */
	public function register_module(): void {
		if ( ! class_exists( 'FLBuilder' ) ) {
			return;
		}

		require_once __DIR__ . '/modules/language-switcher/language-switcher.php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Layout keys that keep FULL MIRROR semantics.
	 *
	 * ⚠️ The document that holds the user's TEXT is deliberately absent from this
	 * list. It is seeded into a new translation by
	 * {@see self::add_meta_keys()} and is owned by that translation from then on.
	 *
	 * A continuous mirror is bidirectional in ContentSync ("any group member's
	 * save propagates group-wide"), so mirroring a builder document that carries
	 * headings, paragraphs and button labels destroys translated text in BOTH
	 * directions — a translator's save overwrites the source, and the next source
	 * save overwrites the translation. That was reproduced end to end for
	 * Elementor on 4.2.1 and 4.1.3, and observed for Beaver Builder on a replica;
	 * every builder here stores text the same way, so all of them were moved.
	 *
	 * What stays below is the part that carries no user-visible text and genuinely
	 * should track the source.
	 *
	 * The accepted cost: a structural edit to the source no longer propagates.
	 * Propagating layout without destroying translated text needs a
	 * builder-aware merge that can tell a layout node from a localisable value; a
	 * raw whole-document mirror cannot give you both.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mirror_keys( array $keys, string $post_type ): array {
		// NOT `_fl_builder_data` — that is the published layout, and every module's
		// heading, text editor content and button label lives inside it.
		$keys[] = '_fl_builder_data_settings';
		// The enabled flag decides whether BB renders the layout at all
		// (FLBuilderModel::is_builder_enabled). It must keep mirroring, or a
		// sibling seeded before the source was converted to BB (or after a
		// revert-to-editor) renders the wrong content type. It holds no text.
		$keys[] = '_fl_builder_enabled';

		return $keys;
	}

	/**
	 * Add Beaver Builder meta keys to translatable list.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_fl_builder_data';
		$keys[] = '_fl_builder_data_settings';
		// The enabled flag decides whether BB renders the layout at all
		// (FLBuilderModel::is_builder_enabled). It must mirror alongside the
		// layout data, or a sibling seeded before the source was converted to
		// BB (or after a revert-to-editor) renders the wrong content type.
		$keys[] = '_fl_builder_enabled';
		// Deliberately NOT the _fl_builder_draft* working copy: it's per-post
		// in-editor state, so syncing it would clobber a sibling's in-progress
		// translation edit. Only the published layout is propagated.

		return $keys;
	}

	/**
	 * Drop the sibling's generated BB asset cache after a layout mirror.
	 *
	 * BB only deletes uploads/bb-plugin/cache/{id}-layout*.css/js on its own
	 * save path, so after a meta mirror the sibling keeps enqueueing assets
	 * rendered from the OLD layout. delete_all_asset_cache() removes them;
	 * BB regenerates missing files on next enqueue. It is a no-op in inline
	 * enqueue mode, which renders fresh per request with no persistent files.
	 *
	 * @param int                $source_id   Source post ID.
	 * @param int                $target_id   Sibling post ID whose layout meta was overwritten.
	 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
	 * @return void
	 */
	public function invalidate_asset_cache( int $source_id, int $target_id, array $mirror_keys ): void {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return;
		}

		$bb_keys = [ '_fl_builder_data', '_fl_builder_data_settings', '_fl_builder_enabled' ];

		if ( array_intersect( $bb_keys, $mirror_keys ) === [] ) {
			return;
		}

		\FLBuilderModel::delete_all_asset_cache( $target_id );
	}
}
