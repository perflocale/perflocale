<?php
/**
 * PerfLocale Elementor addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor integration for PerfLocale.
 *
 * Registers a Language Switcher Elementor widget and marks Elementor
 * page data as translatable meta for content sync.
 */
final class PerfLocaleElementor implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Elementor';
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
		return [ 'elementor/elementor.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register language switcher widget.
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );

		// Add Elementor data as translatable meta.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Builder layout keys keep FULL MIRROR semantics: the layout must stay
		// structurally identical across siblings (text inside is translated at
		// render), so a source layout edit always propagates. Without this the
		// key would fall into ContentSync's seed-only class and siblings would
		// stop receiving layout updates.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );

		// A raw meta mirror of the layout leaves the sibling's GENERATED
		// caches keyed to the pre-sync layout; drop them so Elementor
		// rebuilds on next view.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_generated_caches' ], 10, 3 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Register the PerfLocale Language Switcher Elementor widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widget manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ): void {
		require_once __DIR__ . '/widgets/class-language-switcher-widget.php';

		if ( class_exists( 'PerfLocale_Elementor_Language_Switcher' ) ) {
			$widgets_manager->register( new \PerfLocale_Elementor_Language_Switcher() );
		}
	}

	/**
	 * Add Elementor meta keys as translatable.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_elementor_data';
		// Deliberately NOT _elementor_css: it's a generated per-post CSS cache
		// whose rules are scoped to the source post's element IDs, so copying
		// it to a translation sibling yields wrong-ID CSS. Elementor rebuilds
		// each post's own cache on demand.

		return $keys;
	}

	/**
	 * Drop the sibling's generated Elementor caches after a layout mirror.
	 *
	 * Mirrors Elementor's own on-save invalidation (Document::save): the
	 * post CSS file + `_elementor_css` status meta, the `_elementor_page_assets`
	 * dependency list, and the `_elementor_element_cache` rendered-HTML cache
	 * all describe the OLD layout after `_elementor_data` is overwritten, and
	 * Elementor only rebuilds each of them when its meta row is absent.
	 *
	 * @param int                $source_id   Source post ID.
	 * @param int                $target_id   Sibling post ID whose layout meta was overwritten.
	 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
	 * @return void
	 */
	public function invalidate_generated_caches( int $source_id, int $target_id, array $mirror_keys ): void {
		if ( ! in_array( '_elementor_data', $mirror_keys, true ) ) {
			return;
		}

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			// Deletes the generated CSS file AND the `_elementor_css` meta.
			\Elementor\Core\Files\CSS\Post::create( $target_id )->delete();
		} else {
			delete_post_meta( $target_id, '_elementor_css' );
		}

		delete_post_meta( $target_id, '_elementor_page_assets' );
		delete_post_meta( $target_id, '_elementor_element_cache' );
	}
}
