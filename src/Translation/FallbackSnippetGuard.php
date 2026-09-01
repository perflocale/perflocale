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
 * Wraps the main content with `<div data-nosnippet>` when the current
 * request is serving a default-language post under a non-default-language
 * URL - i.e. the "show_default" branch of `missing_translation_action`.
 *
 * Without this wrapper, Google happily indexes the default-language text
 * under the non-default URL and shows (say) English snippets in German
 * SERPs. `data-nosnippet` tells Google not to use that content for snippet
 * generation, keeping the URL in the index but preventing mismatched
 * snippet text.
 *
 * Reference: https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag#data-nosnippet-attr
 *
 * Cost profile: one `the_content` filter at priority 1 that short-circuits
 * in 3 conditions - admin, not-main-query, or no language mismatch. When
 * fallback IS active, a single sprintf wrap. No regex, no parsing.
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
		// Priority 1 wraps before oEmbed / shortcode / autoembed so they
		// still run on the inner content. The closing </div> nests cleanly
		// around the final HTML.
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

		// Only wrap on the main, singular query - not on archives (where the
		// content filter runs once per post in the loop and the fallback
		// notion doesn't apply).
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

		// Resolve the post's actual language object. Posts with no
		// translation-link entry are treated as being in the default
		// language (matches the convention used by ContentChangeDetector
		// and TermQueryFilter) - without this fallback, a page authored
		// before PerfLocale was installed (or any page never linked into
		// a translation group) would be served under every /xx/ prefix
		// unguarded, leaking its default-language snippet into foreign
		// SERPs.
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
		 * Filter whether to wrap the content with data-nosnippet.
		 *
		 * Fires only when we've already determined a fallback is active -
		 * use this to opt out for specific post types or templates.
		 *
		 * @hook perflocale/fallback/wrap_nosnippet
		 * @param bool $wrap Default: true.
		 * @param int $post_id The singular post ID being rendered.
		 */
		if ( ! apply_filters( 'perflocale/fallback/wrap_nosnippet', true, $post_id ) ) {
			return $content;
		}

		/**
		 * Filter the wrapping tag for data-nosnippet.
		 *
		 * Default 'div' is safe for post content. Some themes wrap content
		 * in inline contexts where a block-level div would break layout -
		 * pass 'span' in those cases.
		 *
		 * @hook perflocale/fallback/nosnippet_tag
		 * @param string $tag Default: 'div'.
		 * @param int $post_id The singular post ID being rendered.
		 */
		$tag = (string) apply_filters( 'perflocale/fallback/nosnippet_tag', 'div', $post_id );

		// Allow only safe block/inline containers.
		$tag = preg_match( '/^[a-z]{1,8}$/', $tag ) ? $tag : 'div';

		// $tag is regex-validated to a lowercase letter run [a-z]{1,8}, so
		// it's safe to embed unescaped. $content is the value WordPress
		// passed through `the_content` - core has already filtered it for
		// users without unfiltered_html. Re-escaping with `wp_kses_post()`
		// here would double-filter post HTML and corrupt valid block markup
		// (SVGs, figures, embeds), the same reason core's own
		// `render_block_core_post_content()` returns $content unescaped.
		return sprintf( '<%1$s data-nosnippet>%2$s</%1$s>', $tag, $content );
	}
}
