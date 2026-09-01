<?php
/**
 * PerfLocale Oxygen Builder Classic addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oxygen Builder Classic integration for PerfLocale.
 *
 * Registers Oxygen's builder meta keys (JSON content, shortcodes,
 * page settings) as translatable so translated posts preserve
 * their Oxygen layouts. Also registers the ct_template custom
 * post type as translatable for reusable components.
 */
final class PerfLocaleOxygen implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'oxygen';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Oxygen Builder';
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
		return [ 'oxygen/ct-oxygen-plugin.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( 'CT_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register Oxygen meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Layout keys keep FULL MIRROR semantics: the layout must stay
		// structurally identical across siblings (text inside is translated at
		// render), so a source layout edit always propagates. ct_other_template
		// is deliberately excluded — it stores a per-page template POST ID, so
		// mirroring would pin every sibling to the source-language template;
		// left seed-only (translatable list only) so each sibling owns its
		// own assignment.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// A raw meta mirror of the layout leaves the sibling's GENERATED
		// CSS-cache file (uploads/oxygen/css/{id}.css) built from the
		// pre-sync layout; drop it so the sibling serves live CSS instead.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_css_cache' ], 10, 3 );

		// Register Oxygen custom post types as translatable.
		add_filter( 'perflocale/translatable_post_types', [ $this, 'add_post_types' ] );

		// NOTE: Oxygen Classic doesn't expose a plugin-facing element
		// registration API - the `oxygen_custom_elements_list` filter that
		// previous versions of this addon targeted doesn't actually exist
		// in Oxygen source. Users wanting a language switcher in Oxygen
		// should use the `[perflocale_switcher]` shortcode inside an Oxygen
		// Code Block or Shortcode element.
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add Oxygen meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = 'ct_builder_json';
		$keys[] = 'ct_builder_shortcodes';
		$keys[] = 'ct_page_settings';
		$keys[] = 'ct_other_template';
		// 'ct_options' is not post meta — it's a JSON attribute inside ct_*
		// shortcodes — so it never had anything to sync.

		return $keys;
	}

	/**
	 * Add Oxygen layout keys that keep full-mirror semantics.
	 *
	 * A strict subset of {@see self::add_meta_keys()}: only the structural
	 * layout keys, never ct_other_template (a per-page template ID that must
	 * not be pinned to the source language across siblings).
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mirror_keys( array $keys, string $post_type ): array {
		$keys[] = 'ct_builder_json';
		$keys[] = 'ct_builder_shortcodes';
		$keys[] = 'ct_page_settings';

		return $keys;
	}

	/**
	 * Drop the sibling's generated Oxygen CSS cache after a layout mirror.
	 *
	 * Oxygen's universal CSS cache stores per-post state in the
	 * `oxygen_vsb_css_files_state` option and only rewrites a post's cached
	 * file on ITS OWN builder save, so a mirrored sibling keeps enqueueing
	 * CSS built from the old layout. With the state entry gone the frontend
	 * falls back to live `xlink=css` output. Done inline rather than via
	 * oxygen_vsb_delete_css_file(): that function trips undefined-index
	 * warnings on entries flagged 'empty' — and an 'empty' entry must ALSO
	 * be cleared, or a layout that gained CSS stays skipped by
	 * oxygen_vsb_load_cached_css_files().
	 *
	 * @param int                $source_id   Source post ID.
	 * @param int                $target_id   Sibling post ID whose layout meta was overwritten.
	 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
	 * @return void
	 */
	public function invalidate_css_cache( int $source_id, int $target_id, array $mirror_keys ): void {
		$oxygen_keys = [ 'ct_builder_json', 'ct_builder_shortcodes', 'ct_page_settings' ];

		if ( array_intersect( $oxygen_keys, $mirror_keys ) === [] ) {
			return;
		}

		$files_meta = get_option( 'oxygen_vsb_css_files_state', [] );

		if ( ! is_array( $files_meta ) || ! isset( $files_meta[ $target_id ] ) ) {
			return;
		}

		if ( ! empty( $files_meta[ $target_id ]['path'] ) && file_exists( $files_meta[ $target_id ]['path'] ) ) {
			wp_delete_file( $files_meta[ $target_id ]['path'] );
		}

		unset( $files_meta[ $target_id ] );

		// Oxygen's OWN option name, deliberately unprefixed: this is Oxygen's
		// generated-CSS manifest and we are invalidating the stale entry for a
		// translation we just mirrored. A perflocale_-prefixed key would write
		// to an option Oxygen never reads, leaving its cache stale.
		update_option( 'oxygen_vsb_css_files_state', $files_meta );
	}

	/**
	 * Add Oxygen post types as translatable.
	 *
	 * @param array<int, string> $post_types Post types.
	 * @return array<int, string>
	 */
	public function add_post_types( array $post_types ): array {
		$post_types[] = 'ct_template';

		return array_unique( $post_types );
	}
}
