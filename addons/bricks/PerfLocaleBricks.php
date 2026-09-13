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
		// ⚠️ Only the TEXT-FREE keys mirror — see add_mirror_keys() for what is on
		// that list and why the builder document is not. A mirror is
		// bidirectional, so mirroring a document that carries the user's words
		// destroys translated text in both directions.
		add_filter( 'perflocale/sync/mirror_meta_keys', [ $this, 'add_mirror_keys' ], 10, 2 );

		// Regenerate Bricks' cached CSS for a translation after its layout was
		// mirrored. Every other builder addon does this (elementor, oxygen,
		// oxygen6 and beaver-builder all register after_mirror); Bricks was the
		// only one of the five that mirrored layout meta without invalidating
		// the generated assets, so a translation could keep serving CSS built
		// from the PREVIOUS layout.
		add_action( 'perflocale/sync/after_mirror', [ $this, 'invalidate_generated_caches' ], 10, 3 );

		// Register Language Switcher element for Bricks Builder.
		add_action( 'init', [ $this, 'register_elements' ], 11 );
	}

	/**
	 * Rebuild Bricks' generated CSS for a translation whose layout just changed.
	 *
	 * ⚠️ BEST EFFORT, AND DELIBERATELY SO. Bricks is a commercial theme and is
	 * not installed in any of this project's test environments, so — unlike the
	 * elementor / oxygen / oxygen6 / beaver-builder twins — this handler could
	 * not be exercised against the real host. Rather than guess at an API and
	 * risk a fatal on someone's live site, every call below is gated behind
	 * `is_callable()` and the method degrades to doing nothing at all, which is
	 * exactly the behaviour Bricks sites have today.
	 *
	 * `is_callable()` NOT `method_exists()`: the latter returns false for a
	 * method routed through `__call()`, which would silently disable this on
	 * any Bricks version that uses magic-method dispatch.
	 *
	 * Regenerate rather than delete: regeneration is idempotent and cannot
	 * leave a post referencing a CSS file that no longer exists. Bricks only
	 * writes per-post CSS files when its `cssLoading` setting is 'file', so on
	 * a default (inline CSS) install this is correctly a no-op.
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
		// NOT the element trees — `_bricks_page_content`, `_bricks_page_content_2`,
		// `_bricks_page_header_2` and `_bricks_page_footer_2` all carry the text
		// a translator types.
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
