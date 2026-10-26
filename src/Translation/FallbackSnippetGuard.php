<?php
/**
 * data-nosnippet wrapper for fallback-language content.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps singular content in `data-nosnippet` when the post's language differs from the URL's language.
 *
 * Keeps fallback-language text out of search snippets for the translated URL.
 *
 * @link https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag#data-nosnippet-attr
 */
final class FallbackSnippetGuard {

	/** @var LanguageRouter */
	private readonly LanguageRouter $router;

	/** @var Settings */
	private readonly Settings $settings;

	/** @var PostTranslationManager */
	private readonly PostTranslationManager $manager;

	public function __construct(
		LanguageRouter $router,
		Settings $settings,
		PostTranslationManager $manager
	) {
		$this->router   = $router;
		$this->settings = $settings;
		$this->manager  = $manager;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Priority 1: wrap before oEmbed, shortcodes and autoembed so they still run on the inner content.
		add_filter( 'the_content', [ $this, 'maybe_wrap' ], 1 );
	}

	/**
	 * Wrap the content with `data-nosnippet` if we're in fallback mode.
	 *
	 * @param string $content Post content, as passed by the_content filter.
	 * @return string
	 */
	public function maybe_wrap( $content ): string {
		$content = (string) $content;

		if ( $content === '' || is_admin() ) {
			return $content;
		}

		if ( ! (bool) $this->settings->get( 'fallback_nosnippet', true ) ) {
			return $content;
		}

		// Main singular query only: on archives the filter runs per post in the loop.
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$current_slug = $this->router->get_current_slug();

		if ( $current_slug === '' ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			return $content;
		}

		// An unlinked post counts as default language, or it would be served unguarded under every prefix.
		$post_lang = $this->manager->detect_post_language( $post_id );

		if ( $post_lang === null || ! isset( $post_lang->id ) ) {
			$post_lang = $this->router->get_default_language();
		}

		if ( $post_lang === null || ! isset( $post_lang->id ) ) {
			return $content;
		}

		$current_lang = $this->router->get_current_language();
		$current_id   = $current_lang !== null ? (int) ( $current_lang->id ?? 0 ) : 0;
		$post_lang_id = (int) $post_lang->id;

		if ( $current_id === 0 || $post_lang_id === $current_id ) {
			return $content;
		}

		/**
		 * Filter whether to wrap the content with data-nosnippet; fires only when a fallback is active.
		 *
		 * @hook perflocale/fallback/wrap_nosnippet
		 * @param bool $wrap Default: true.
		 * @param int $post_id The singular post ID being rendered.
		 */
		if ( ! apply_filters( 'perflocale/fallback/wrap_nosnippet', true, $post_id ) ) {
			return $content;
		}

		/**
		 * Filter the wrapping tag for data-nosnippet; return 'span' where a theme renders content inline.
		 *
		 * @hook perflocale/fallback/nosnippet_tag
		 * @param string $tag Default: 'div'.
		 * @param int $post_id The singular post ID being rendered.
		 */
		$tag = (string) apply_filters( 'perflocale/fallback/nosnippet_tag', 'div', $post_id );

		// Allow only safe block/inline containers.
		$tag = preg_match( '/^[a-z]{1,8}$/', $tag ) ? $tag : 'div';

		// $tag is validated above. $content stays unescaped like core's post-content block: wp_kses_post()
		// would corrupt valid block markup (SVG, figures, embeds).
		return sprintf( '<%1$s data-nosnippet>%2$s</%1$s>', $tag, $content );
	}
}
