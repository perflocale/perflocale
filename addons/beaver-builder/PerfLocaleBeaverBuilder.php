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
		// Builder layout keys keep FULL MIRROR semantics: the layout must stay
		// structurally identical across siblings (text inside is translated at
		// render), so a source layout edit always propagates. Without this the
		// key would fall into ContentSync's seed-only class and siblings would
		// stop receiving layout updates.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );

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
