<?php
/**
 * PerfLocale Oxygen 6.0 / Breakdance addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oxygen 6.0 (Breakdance in Oxygen mode) integration for PerfLocale.
 *
 * Registers the builder's content tree meta keys as translatable.
 * Handles the dynamic meta prefix - Oxygen mode uses `_oxygen_*`
 * while Breakdance mode uses `_breakdance_*`.
 */
final class PerfLocaleOxygen6 implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'oxygen6';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Oxygen 6.0';
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
		return [ 'oxygen-6.0/plugin.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return defined( '__BREAKDANCE_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Oxygen 6.0 / Breakdance uses `_oxygen_*` or `_breakdance_*` meta
		// keys depending on BREAKDANCE_MODE. These are distinct from the
		// `ct_*` keys that Oxygen Classic registers, so running both
		// addons concurrently is safe (no key collisions).
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Builder layout keys keep FULL MIRROR semantics: the layout must stay
		// structurally identical across siblings (text inside is translated at
		// render), so a source layout edit always propagates. Without this the
		// key would fall into ContentSync's seed-only class and siblings would
		// stop receiving layout updates.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );

		// A raw meta mirror of the layout leaves the sibling's GENERATED
		// per-post cache meta (css_file_paths_cache / dependency_cache)
		// describing the pre-sync layout; drop them so the builder
		// regenerates on next view.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_generated_caches' ], 10, 3 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add Oxygen 6.0 / Breakdance meta keys as translatable.
	 *
	 * The meta prefix is dynamic: `_oxygen_` in Oxygen mode,
	 * `_breakdance_` in Breakdance mode. We register both to
	 * ensure compatibility regardless of the active mode.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		// Determine the active meta prefix.
		$prefix = '_oxygen_';

		if ( defined( 'BREAKDANCE_MODE' ) && BREAKDANCE_MODE === 'breakdance' ) {
			$prefix = '_breakdance_';
		}

		$keys[] = $prefix . 'data';
		$keys[] = $prefix . 'template_settings';
		// Deliberately NOT *_dependency_cache / *_css_file_paths_cache: these
		// are generated per-post caches that reference the source post, so
		// copying them to a translation sibling yields stale/wrong-post caches.
		// The builder rebuilds each post's own.

		return $keys;
	}

	/**
	 * Drop the sibling's generated cache meta after a layout mirror.
	 *
	 * The builder's getPostCache() regenerates the per-post CSS whenever
	 * EITHER the `*_css_file_paths_cache` or `*_dependency_cache` meta row
	 * is missing, and the generated CSS files are overwritten in place
	 * (deterministic per-post filenames), so deleting the two rows is the
	 * complete invalidation.
	 *
	 * @param int                $source_id   Source post ID.
	 * @param int                $target_id   Sibling post ID whose layout meta was overwritten.
	 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
	 * @return void
	 */
	public function invalidate_generated_caches( int $source_id, int $target_id, array $mirror_keys ): void {
		$prefix = '_oxygen_';

		if ( defined( 'BREAKDANCE_MODE' ) && BREAKDANCE_MODE === 'breakdance' ) {
			$prefix = '_breakdance_';
		}

		if ( ! in_array( $prefix . 'data', $mirror_keys, true ) && ! in_array( $prefix . 'template_settings', $mirror_keys, true ) ) {
			return;
		}

		delete_post_meta( $target_id, $prefix . 'css_file_paths_cache' );
		delete_post_meta( $target_id, $prefix . 'dependency_cache' );
	}
}
