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
 * Bricks Builder integration for PerfLocale.
 *
 * Registers Bricks' content meta keys as translatable so translated
 * posts preserve their Bricks layouts. Bricks is a premium WordPress
 * theme (not a plugin), so detection uses the BRICKS_VERSION constant
 * or the Bricks\Theme class.
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
		// Builder layout keys keep FULL MIRROR semantics: the layout must stay
		// structurally identical across siblings (text inside is translated at
		// render), so a source layout edit always propagates. Without this the
		// key would fall into ContentSync's seed-only class and siblings would
		// stop receiving layout updates.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );

		// Register Language Switcher element for Bricks Builder.
		add_action( 'init', [ $this, 'register_elements' ], 11 );
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
		// Header/footer element trees on bricks_template posts are the same
		// class of layout structure as the content tree above; without them a
		// translated header/footer template diverges from the source after
		// edits. (_bricks_template_settings holds display CONDITIONS, not
		// layout — deliberately not mirrored, or siblings would fight over the
		// same targeting.)
		$keys[] = '_bricks_page_header_2';
		$keys[] = '_bricks_page_footer_2';

		return $keys;
	}
}
