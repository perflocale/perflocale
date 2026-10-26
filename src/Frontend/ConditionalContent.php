<?php
/**
 * Language-conditional content block, shortcode, and PHP helper.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows content only for chosen languages: the if-language block, `[perflocale_if]` and a PHP helper.
 *
 * All three go through should_show() so their semantics stay identical.
 */
final class ConditionalContent {

	/**
	 * Gutenberg block name.
	 */
	public const BLOCK_NAME = 'perflocale/if-language';

	/**
	 * Shortcode tag.
	 */
	public const SHORTCODE_TAG = 'perflocale_if';

	/**
	 * Register hooks for the block, the shortcode and editor assets.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_editor_style' ], 9 );
		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'init', [ $this, 'register_shortcode' ], 20 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	/**
	 * Register the editor stylesheet referenced from block.json.
	 *
	 * Priority 9: block.json names this handle, so it must exist before the block registers.
	 *
	 * @return void
	 */
	public function register_editor_style(): void {
		wp_register_style(
			'perflocale-block-editor',
			PERFLOCALE_URL . 'assets/css/block-editor.css',
			[],
			PERFLOCALE_VERSION
		);
	}

	/**
	 * Core predicate: should content show for the current language?
	 *
	 * @param array<int, string> $languages Slugs that should match (lowercase).
	 * @param bool               $invert True to invert - "show EXCEPT for these".
	 * @return bool
	 */
	public static function should_show( array $languages, bool $invert = false ): bool {
		$languages = self::normalize_languages( $languages );

		if ( $languages === [] ) {
			// No languages: never shown, or always shown when inverted.
			return $invert;
		}

		$current = self::current_slug();

		if ( $current === '' ) {
			// Unknown language: fail closed.
			return false;
		}

		$match = in_array( $current, $languages, true );

		return $invert ? ! $match : $match;
	}

	/**
	 * Register the Gutenberg block (server-side rendered).
	 *
	 * @return void
	 */
	public function register_block(): void {
		// Only the call that adds the metadata filter removes it, in finally
		// (see LanguageSwitcherBlock::register_block()).
		$strip = ! \PerfLocale\Helper::shows_registration_labels()
			&& false === has_filter( 'block_type_metadata', [ \PerfLocale\Helper::class, 'block_metadata_without_textdomain' ] );

		if ( $strip ) {
			add_filter( 'block_type_metadata', [ \PerfLocale\Helper::class, 'block_metadata_without_textdomain' ] );
		}

		try {
			register_block_type(
				PERFLOCALE_DIR . 'blocks/if-language',
				[
					'render_callback' => [ $this, 'render_block' ],
				]
			);
		} finally {
			if ( $strip ) {
				remove_filter( 'block_type_metadata', [ \PerfLocale\Helper::class, 'block_metadata_without_textdomain' ] );
			}
		}
	}

	/**
	 * Register the `[perflocale_if]` shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode(): void {
		add_shortcode( self::SHORTCODE_TAG, [ $this, 'render_shortcode' ] );
	}

	/**
	 * Attach the language list to the block's editor script (registered from block.json).
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		if ( ! $block_type instanceof \WP_Block_Type ) {
			return;
		}

		$languages = self::editor_language_choices();

		foreach ( (array) $block_type->editor_script_handles as $handle ) {
			wp_localize_script(
				$handle,
				'perflocaleConditional',
				[
					'languages' => $languages,
				]
			);
		}
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content Rendered inner block content.
	 * @return string
	 */
	public function render_block( array $attributes, string $content ): string {
		$languages = isset( $attributes['languages'] ) && is_array( $attributes['languages'] )
			? $attributes['languages']
			: [];
		$invert    = ! empty( $attributes['invert'] );

		if ( ! self::should_show( $languages, $invert ) ) {
			return '';
		}

		// The return value is echoed: escape it rather than trust the saved content.
		return self::kses_content( $content );
	}

	/**
	 * Shortcode callback for `[perflocale_if langs="fr,de"]` or `not_langs` (inverted).
	 *
	 * When both are given, `not_langs` wins.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @param string|null                  $content Enclosed shortcode content.
	 * @return string
	 */
	public function render_shortcode( $atts, ?string $content = null ): string {
		$atts = shortcode_atts(
			[
				'langs'     => '',
				'not_langs' => '',
			],
			(array) $atts,
			self::SHORTCODE_TAG
		);

		$invert    = ( (string) $atts['not_langs'] ) !== '';
		$raw_list  = $invert ? (string) $atts['not_langs'] : (string) $atts['langs'];
		$languages = array_map( 'trim', explode( ',', $raw_list ) );

		if ( ! self::should_show( $languages, $invert ) ) {
			return '';
		}

		// Nested blocks and shortcodes render first; the result is escaped below.
		$out = (string) ( $content ?? '' );

		if ( $out === '' ) {
			return '';
		}

		$out = do_blocks( $out );
		$out = do_shortcode( $out );

		return self::kses_content( $out );
	}

	/**
	 * Escape wrapped content on output with the post allowlist extended for core block output.
	 *
	 * Not wp_kses_post(): it strips iframes (oEmbeds), inline SVG and srcset.
	 * See docs-ai/11-code-rationale.md, "ConditionalContent: output allowlist".
	 *
	 * @param string $content Rendered inner content.
	 * @return string Escaped content.
	 */
	private static function kses_content( string $content ): string {
		if ( $content === '' ) {
			return '';
		}

		$allowed = wp_kses_allowed_html( 'post' );

		// Attributes core puts on block wrappers; wp_kses() allows data-* on its own.
		$common = [
			'class'           => true,
			'id'              => true,
			'style'           => true,
			'title'           => true,
			'role'            => true,
			'lang'            => true,
			'dir'             => true,
			'hidden'          => true,
			'tabindex'        => true,
			'aria-label'      => true,
			'aria-labelledby' => true,
			'aria-describedby' => true,
			'aria-hidden'     => true,
		];

		// oEmbed / embed block output.
		$allowed['iframe'] = [
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'marginwidth'     => true,
			'marginheight'    => true,
			'scrolling'       => true,
			'allow'           => true,
			'allowfullscreen' => true,
			'loading'         => true,
			'name'            => true,
			'referrerpolicy'  => true,
			'sandbox'         => true,
		] + $common;

		// Responsive images and modern loading hints.
		$allowed['img'] = ( $allowed['img'] ?? [] ) + [
			'srcset'        => true,
			'sizes'         => true,
			'decoding'      => true,
			'fetchpriority' => true,
			'loading'       => true,
		] + $common;

		// <picture>/<source> and media tracks.
		$allowed['picture'] = $common;
		$allowed['source']  = [
			'src'     => true,
			'srcset'  => true,
			'sizes'   => true,
			'type'    => true,
			'media'   => true,
		] + $common;
		$allowed['track'] = [
			'src'     => true,
			'kind'    => true,
			'srclang' => true,
			'label'   => true,
			'default' => true,
		] + $common;

		// Inline SVG (icons in custom HTML / theme blocks).
		$svg_common = [
			'xmlns'           => true,
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'opacity'         => true,
			'transform'       => true,
			'focusable'       => true,
			'preserveaspectratio' => true,
		] + $common;

		foreach ( [ 'svg', 'g', 'defs', 'title', 'desc', 'use', 'symbol', 'mask', 'clippath' ] as $tag ) {
			$allowed[ $tag ] = $svg_common + [ 'href' => true, 'xlink:href' => true ];
		}

		$allowed['path']     = $svg_common + [ 'd' => true, 'fill-rule' => true, 'clip-rule' => true ];
		$allowed['circle']   = $svg_common + [ 'cx' => true, 'cy' => true, 'r' => true ];
		$allowed['ellipse']  = $svg_common + [ 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ];
		$allowed['rect']     = $svg_common + [ 'x' => true, 'y' => true, 'rx' => true, 'ry' => true ];
		$allowed['line']     = $svg_common + [ 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ];
		$allowed['polyline'] = $svg_common + [ 'points' => true ];
		$allowed['polygon']  = $svg_common + [ 'points' => true ];

		/**
		 * Filter the allowlist that escapes conditional-content output.
		 *
		 * @hook perflocale/conditional_content/allowed_html
		 *
		 * @param array<string, array<string, bool>> $allowed Allowed HTML, wp_kses() shape.
		 * @param string                             $content Content being escaped.
		 */
		$allowed = (array) apply_filters( 'perflocale/conditional_content/allowed_html', $allowed, $content );

		// Core does not list `xlink:href` as a URI attribute, so wp_kses() would keep `javascript:` in it.
		$uri_attributes = [ self::class, 'with_xlink_href' ];
		$listed_here    = false === has_filter( 'wp_kses_uri_attributes', $uri_attributes );

		if ( $listed_here ) {
			add_filter( 'wp_kses_uri_attributes', $uri_attributes );
		}

		try {
			return wp_kses( $content, $allowed );
		} finally {
			if ( $listed_here ) {
				remove_filter( 'wp_kses_uri_attributes', $uri_attributes );
			}
		}
	}

	/**
	 * Adds `xlink:href` to `wp_kses_uri_attributes` while kses_content() runs.
	 *
	 * @internal
	 *
	 * @param mixed $attributes URI attribute names.
	 * @return array<int, string>
	 */
	public static function with_xlink_href( mixed $attributes ): array {
		$attributes = is_array( $attributes ) ? array_values( array_filter( $attributes, 'is_string' ) ) : [];

		if ( ! in_array( 'xlink:href', $attributes, true ) ) {
			$attributes[] = 'xlink:href';
		}

		return $attributes;
	}

	/**
	 * Normalise a languages array into lowercase slugs without empties.
	 *
	 * @param array<int, string> $languages
	 * @return array<int, string>
	 */
	private static function normalize_languages( array $languages ): array {
		$out = [];

		foreach ( $languages as $lang ) {
			$slug = sanitize_key( (string) $lang );

			if ( $slug !== '' ) {
				$out[] = $slug;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Safely resolve the current language slug.
	 *
	 * @return string Current slug, or '' when the router isn't available.
	 */
	private static function current_slug(): string {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return '';
		}

		$lang = $plugin->get( 'router' )->get_current_language();

		return is_object( $lang ) ? (string) ( $lang->slug ?? '' ) : '';
	}

	/**
	 * Build the editor's language choices, labelled by native name first.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private static function editor_language_choices(): array {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return [];
		}

		$languages = $plugin->get( 'router' )->get_active_languages();

		if ( ! is_array( $languages ) ) {
			return [];
		}

		$out = [];

		foreach ( $languages as $lang ) {
			if ( ! is_object( $lang ) || empty( $lang->slug ) ) {
				continue;
			}

			$native = (string) ( $lang->native_name ?? '' );
			$name   = (string) ( $lang->name ?? '' );

			$label = $native !== '' ? $native : ( $name !== '' ? $name : (string) $lang->slug );

			$out[] = [
				'slug'  => (string) $lang->slug,
				'label' => $label,
			];
		}

		return $out;
	}
}
