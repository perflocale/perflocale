<?php
/**
 * PerfLocale Bricks Builder addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks Builder integration: Bricks' layout meta keys and the language switcher element.
 *
 * Bricks is a theme, not a plugin: detection uses BRICKS_VERSION or the Bricks\Theme class.
 */
final class PerfLocaleBricks implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'bricks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Bricks Builder';
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
		// Bricks is a theme, not a plugin - no plugin file to require.
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'BRICKS_VERSION' ) || class_exists( 'Bricks\\Theme' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register Bricks meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Machine translation reports this layout as not translated (BuilderLayout).
		add_filter( 'perflocale/mt/builder_documents', static fn( array $b ): array => [ ...$b, [ 'name' => 'Bricks', 'keys' => [ '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2', '_bricks_page_content' ], 'mode' => [ '_bricks_editor_mode', 'bricks' ] ] ] );
		// Only text-free keys mirror: a mirror is bidirectional and the builder documents hold the user's words.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// A layout mirror leaves the sibling's generated CSS built from the old layout.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_generated_caches' ], 10, 3 );

		// Register Language Switcher element for Bricks Builder.
		add_action( 'init', [ $this, 'register_elements' ], 11 );
	}

	/**
	 * Rebuild Bricks' generated CSS for a translation whose layout just changed.
	 *
	 * Best effort: untested against real Bricks, so every call is gated by `is_callable()` (not
	 * `method_exists()`, which misses `__call()` methods). Regenerating cannot leave a dangling CSS file.
	 *
	 * @param int                $source_id   Source post whose meta was mirrored.
	 * @param int                $target_id   Translation that received the mirror.
	 * @param array<int, string> $mirror_keys Meta keys that were mirrored.
	 * @return void
	 */
	public function invalidate_generated_caches( int $source_id, int $target_id, array $mirror_keys ): void {
		$layout_keys = [
			'_bricks_page_content',
			'_bricks_page_content_2',
			'_bricks_page_settings',
			'_bricks_page_header_2',
			'_bricks_page_footer_2',
		];

		if ( array_intersect( $layout_keys, $mirror_keys ) === [] ) {
			return;
		}

		if ( ! class_exists( '\Bricks\Assets_Files' ) ) {
			return;
		}

		$regenerate = [ '\Bricks\Assets_Files', 'generate_post_css_file' ];

		if ( is_callable( $regenerate ) ) {
			$regenerate( $target_id );
		}
	}

	/**
	 * Register custom Bricks elements.
	 *
	 * @return void
	 */
	public function register_elements(): void {
		if ( ! class_exists( 'Bricks\\Elements' ) ) {
			return;
		}

		$element_file = __DIR__ . '/elements/language-switcher.php';

		if ( file_exists( $element_file ) ) {
			\Bricks\Elements::register_element( $element_file );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Layout keys that keep full mirror semantics: the text-free ones only.
	 *
	 * See docs-ai/11-code-rationale.md, "PerfLocaleBricks: text-free mirror keys".
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mirror_keys( array $keys, string $post_type ): array {
		// Not the element trees (`_bricks_page_content*`, `*_header_2`, `*_footer_2`): they carry the text.
		$keys[] = '_bricks_page_settings';

		return $keys;
	}

	/**
	 * Add Bricks Builder meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_bricks_page_content';
		$keys[] = '_bricks_page_content_2';
		$keys[] = '_bricks_page_settings';
		// Header/footer element trees of bricks_template posts. Not _bricks_template_settings: it holds
		// display conditions, so siblings would fight over the same targeting.
		$keys[] = '_bricks_page_header_2';
		$keys[] = '_bricks_page_footer_2';

		return $keys;
	}
}
