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
 * Enqueues the front-end switcher stylesheet only on pages that use a switcher.
 */
final class Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );

		// A switcher in a template part is invisible to enqueue(); the block-specific filter
		// avoids a callback per block, and WP prints late-enqueued styles in the footer.
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
	 * Enqueues the stylesheet when the switcher block renders, template parts included.
	 *
	 * @param string $block_content Rendered block HTML (returned unchanged).
	 * @param array  $block         Parsed block array.
	 * @return string
	 */
	public function maybe_enqueue_for_block( string $block_content, array $block ): string {
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		// Other perflocale blocks need no switcher stylesheet.
		if ( $name === 'perflocale/language-switcher' ) {
			$this->enqueue_frontend_style();
		}

		return $block_content;
	}

	/**
	 * Enqueue the frontend stylesheet once per request.
	 *
	 * @return void
	 */
	private function enqueue_frontend_style(): void {
		// Cheap guard on the per-block path; wp_enqueue_style() alone would also be idempotent.
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
	 * Whether the current page uses the switcher (widget, shortcode or block), memoized per request.
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
	 * Whether the post has the switcher shortcode ([perflocale_language] uses no frontend.css rule).
	 *
	 * @return bool
	 */
	private function page_has_shortcode(): bool {
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, 'perflocale_switcher' );
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
