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
 * Oxygen 6.0 (Breakdance in Oxygen mode) integration: the builder's content tree meta keys.
 *
 * The meta prefix depends on BREAKDANCE_MODE: `_oxygen_*` or `_breakdance_*`.
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
		// Distinct from Oxygen Classic's `ct_*` keys, so both add-ons can run together.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Machine translation reports this layout as not translated (BuilderLayout).
		add_filter( 'perflocale/mt/builder_documents', static fn( array $b ): array => [ ...$b, [ 'name' => 'Oxygen', 'keys' => [ '_oxygen_data' ], 'always' => true ], [ 'name' => 'Breakdance', 'keys' => [ '_breakdance_data' ], 'always' => true ] ] );
		// Only text-free keys mirror: a mirror is bidirectional and the builder document holds the user's words.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// A layout mirror leaves the sibling's generated cache meta describing the old layout.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_generated_caches' ], 10, 3 );
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
	 * See docs-ai/11-code-rationale.md, "PerfLocaleOxygen6: text-free mirror keys".
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mirror_keys( array $keys, string $post_type ): array {
		// Oxygen 6 and Breakdance are the same builder under two names.
		$prefix = '_oxygen_';

		if ( defined( 'BREAKDANCE_MODE' ) && BREAKDANCE_MODE === 'breakdance' ) {
			$prefix = '_breakdance_';
		}

		// Not `{$prefix}data`: that is the document holding the user's text.
		$keys[] = $prefix . 'template_settings';

		return $keys;
	}

	/**
	 * Add Oxygen 6.0 / Breakdance meta keys as translatable.
	 *
	 * Registers the active mode's prefix only: `_oxygen_` or `_breakdance_`.
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
		// Not *_dependency_cache / *_css_file_paths_cache: generated per-post caches of the source post.

		return $keys;
	}

	/**
	 * Drop the sibling's generated cache meta after a layout mirror.
	 *
	 * The builder's getPostCache() regenerates the CSS when either row is missing and overwrites its
	 * per-post files, so deleting the two rows is the complete invalidation.
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
