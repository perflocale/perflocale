<?php
/**
 * Frontend asset manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditionally enqueues minimal frontend CSS for the language switcher.
 *
 * Only loads when a language switcher widget or shortcode is active
 * on the current page. Absolute minimal footprint.
 */
final class Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );

		// FSE / block-theme safety net: when a perflocale switcher block
		// renders from a wp_template part (header.html, footer.html, etc.)
		// — i.e. not from $post->post_content — the wp_enqueue_scripts
		// pass at the top of enqueue() can't see it (page_has_block() only
		// inspects the global $post). The block-SPECIFIC render filter
		// fires only when our block actually renders — unlike the generic
		// `render_block`, which would call this callback for every block
		// on the page (thousands on FSE templates) just to compare names.
		// Calling wp_enqueue_style this late still works because WP prints
		// newly-enqueued styles via the late-style hooks (wp_footer).
		// Without this hook the switcher renders unstyled on every
		// block-theme site that puts the switcher in a template part.
		add_filter( 'render_block_perflocale/language-switcher', [ $this, 'maybe_enqueue_for_block' ], 10, 2 );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( $this->page_uses_switcher() ) {
			$this->enqueue_frontend_style();
		}
	}

	/**
	 * Render-time enqueue: ensure the frontend stylesheet is queued when a
	 * perflocale block renders from anywhere — including template parts
	 * which the early wp_enqueue_scripts pass cannot inspect.
	 *
	 * Safe to call repeatedly: wp_enqueue_style is idempotent on the same
	 * handle.
	 *
	 * @param string $block_content Rendered block HTML (returned unchanged).
	 * @param array  $block         Parsed block array.
	 * @return string
	 */
	public function maybe_enqueue_for_block( string $block_content, array $block ): string {
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		// Only act on the language-switcher block. Other perflocale blocks
		// (e.g. if-language) don't need the switcher stylesheet.
		if ( $name === 'perflocale/language-switcher' ) {
			$this->enqueue_frontend_style();
		}

		return $block_content;
	}

	/**
	 * Enqueue the frontend stylesheet exactly once per request. Centralised
	 * so the wp_enqueue_scripts path and the render_block safety-net path
	 * stay in sync on handle/url/version.
	 *
	 * @return void
	 */
	private function enqueue_frontend_style(): void {
		// wp_enqueue_style is itself idempotent on (handle, src, deps,
		// ver), but a tiny is-enqueued guard avoids the duplicate string
		// hashing inside WP's enqueue API on the hot per-block path.
		if ( wp_style_is( 'perflocale-frontend', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'perflocale-frontend',
			PERFLOCALE_URL . 'assets/css/frontend.css',
			[],
			PERFLOCALE_VERSION
		);
	}

	/**
	 * Whether the current page uses the language switcher (widget,
	 * shortcode, or block). Per-request memoized so any future caller
	 * that asks the same question doesn't re-scan post_content.
	 *
	 * `is_active_widget()` already short-circuits the more-expensive
	 * `has_shortcode()` / `has_block()` scans, but caching the boolean
	 * itself makes the whole check a single bit-flag lookup on second
	 * and subsequent calls within the same request.
	 *
	 * @return bool
	 */
	private function page_uses_switcher(): bool {
		static $cached = null;

		if ( $cached !== null ) {
			return $cached;
		}

		$cached = is_active_widget( false, false, 'perflocale_switcher' )
			|| $this->page_has_shortcode()
			|| $this->page_has_block();

		return $cached;
	}

	/**
	 * Check if the current page content contains the shortcode.
	 *
	 * @return bool
	 */
	private function page_has_shortcode(): bool {
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, 'perflocale_switcher' )
			|| has_shortcode( $post->post_content, 'perflocale_language' );
	}

	/**
	 * Check if the current page has a PerfLocale block.
	 *
	 * @return bool
	 */
	private function page_has_block(): bool {
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_block( 'perflocale/language-switcher', $post );
	}
}
