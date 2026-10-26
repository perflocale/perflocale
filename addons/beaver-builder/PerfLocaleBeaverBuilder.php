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
 * Beaver Builder integration: seed-once layout sync and a Language Switcher module.
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
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		// Machine translation reports this layout as not translated (BuilderLayout).
		add_filter( 'perflocale/mt/builder_documents', static fn( array $b ): array => [ ...$b, [ 'name' => 'Beaver Builder', 'keys' => [ '_fl_builder_data' ], 'mode' => [ '_fl_builder_enabled', '1' ] ] ] );
		// Only text-free keys mirror: a mirror is bidirectional and the builder document holds the user's words.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// A layout mirror leaves the sibling's generated asset cache built from the old layout.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_asset_cache' ], 10, 3 );

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

		// Labels only where Beaver Builder shows them: a logged-in builder request, admin, REST and WP-CLI.
		$labels = ( ( isset( $_GET['fl_builder'] ) || isset( $_POST['fl_builder_data'] ) ) && is_user_logged_in() ) || \PerfLocale\Helper::shows_registration_labels(); // phpcs:ignore WordPress.Security.NonceVerification -- request type only.

		require_once __DIR__ . '/modules/language-switcher/' . ( $labels ? 'language-switcher.php' : 'language-switcher-source.php' );
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
	 * The text-holding document is seeded once and owned by the translation; see
	 * docs-ai/11-code-rationale.md, "PerfLocaleBeaverBuilder: text-free mirror keys".
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_mirror_keys( array $keys, string $post_type ): array {
		// Not `_fl_builder_data`: every module's text lives in the published layout.
		$keys[] = '_fl_builder_data_settings';
		// Decides whether BB renders the layout at all; a stale sibling would render the wrong content type.
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
		$keys[] = '_fl_builder_enabled';
		// Not the _fl_builder_draft* working copy: it is per-post editor state.

		return $keys;
	}

	/**
	 * Drop the sibling's generated BB asset cache after a layout mirror.
	 *
	 * BB deletes its cached layout CSS/JS only on its own save path and regenerates missing files on enqueue.
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
