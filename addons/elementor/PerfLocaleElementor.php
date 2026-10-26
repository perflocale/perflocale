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
 * Elementor integration: a Language Switcher widget and seed-once layout sync.
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
		add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );

		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Machine translation reports this layout as not translated (BuilderLayout).
		add_filter( 'perflocale/mt/builder_documents', static fn( array $b ): array => [ ...$b, [ 'name' => 'Elementor', 'keys' => [ '_elementor_data' ], 'mode' => [ '_elementor_edit_mode', 'builder' ] ] ] );
		// Seed once, never mirror: `_elementor_data` holds every widget's text, and the mirror is bidirectional.
		// See docs-ai/11-code-rationale.md, "PerfLocaleElementor: seed once, never mirror".

		// A layout mirror leaves the sibling's generated caches keyed to the old layout.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_generated_caches' ], 10, 3 );

		// The create-time copy is a blanket copy, so generated caches must be excluded explicitly.
		add_filter( 'perflocale/translation/excluded_meta_keys', [ $this, 'exclude_generated_caches' ], 10, 2 );
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
		// Leaving `_elementor_css` out here does not stop the create-time copy: exclude_generated_caches() does.

		return $keys;
	}

	/**
	 * Drop the sibling's generated Elementor caches after a layout mirror.
	 *
	 * As Elementor's Document::save does: each is rebuilt only when its meta row is absent.
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

	/**
	 * Keep Elementor's generated caches out of a new translation.
	 *
	 * Elementor's own duplicate-post list, minus `_elementor_template_type`, which Elementor writes back and PerfLocale does not.
	 *
	 * @param array<int, string> $excluded  Meta keys already excluded from copying.
	 * @param int                $source_id Post being copied from.
	 * @return array<int, string>
	 */
	public function exclude_generated_caches( array $excluded, int $source_id ): array {
		return array_values(
			array_unique(
				array_merge(
					$excluded,
					[
						'_elementor_css',
						'_elementor_page_assets',
						'_elementor_element_cache',
						'_elementor_controls_usage',
						'_elementor_screenshot',
					]
				)
			)
		);
	}
}
