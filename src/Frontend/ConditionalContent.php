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
 * Evaluates whether content should display based on the current language,
 * exposed consistently across three surfaces:
 *
 * - Gutenberg block - `perflocale/if-language` wrapping inner blocks
 * - Shortcode - `[perflocale_if langs="fr,de"]...[/perflocale_if]`
 * - PHP helper - `perflocale_if_language( [ 'fr', 'de' ] )`
 *
 * All three call {@see should_show()} so semantics can never drift.
 * Zero DB I/O: the current-language lookup is already cached once per
 * request by LanguageRouter - evaluating the predicate is a single
 * `in_array()` plus light attribute parsing.
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
	 * Register hooks for block + shortcode registration + editor assets.
	 *
	 * Called from Bootstrap. Idempotent - safe to call more than once
	 * but Bootstrap only wires it in the container lifecycle.
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
	 * block.json's `editorStyle` field expects a registered handle when it
	 * isn't prefixed with `file:`. Registering here (before register_block)
	 * lets the block metadata resolve the handle at registration time.
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
			// No languages specified. Non-inverted → block never shows;
			// inverted → block always shows (because "none" excluded).
			return $invert;
		}

		$current = self::current_slug();

		if ( $current === '' ) {
			// Unknown current language - fail closed so conditional content
			// doesn't accidentally leak in the wrong locale on edge cases
			// (e.g. during language setup before any language is active).
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
		register_block_type(
			PERFLOCALE_DIR . 'blocks/if-language',
			[
				'render_callback' => [ $this, 'render_block' ],
			]
		);
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
	 * Attach the editor-script data for the block.
	 *
	 * The script itself is registered + enqueued by WP core via block.json's
	 * `editorScript` field. We just need to localise the language list onto
	 * that auto-generated handle so the editor can populate its multi-select
	 * without a REST roundtrip per block instance.
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
	 * @param string               $content Inner (block) content - already
	 *               rendered by the core parser
	 *               before this callback fires.
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

		// Escaped on the way out. $content is inner-block HTML that core has
		// already rendered (and KSES-filtered at save for authors without
		// unfiltered_html), but this callback's return value is echoed by
		// WordPress, so it is filtered here too rather than trusted.
		return self::kses_content( $content );
	}

	/**
	 * Shortcode callback: [perflocale_if langs="fr,de" not_langs="" ]...[/perflocale_if]
	 *
	 * Accepts either `langs` (positive match) OR `not_langs` (inverted).
	 * When both are supplied `not_langs` wins (inverts against its list).
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

		// Render nested shortcodes + blocks so authors can nest e.g.
		// `[perflocale_if][perflocale_switcher][/perflocale_if]`. do_blocks()/
		// do_shortcode() return trusted HTML by definition, and the inner
		// $content came through the_content (KSES-filtered by core for users
		// without unfiltered_html); escaping it would defeat the purpose and
		// corrupt valid markup, like every container shortcode in WP.
		$out = (string) ( $content ?? '' );

		if ( $out === '' ) {
			return '';
		}

		$out = do_blocks( $out );
		$out = do_shortcode( $out );

		return self::kses_content( $out );
	}

	/**
	 * Escape wrapped content on output.
	 *
	 * Both callbacks return author-authored content that WordPress echoes
	 * directly, so it is run through `wp_kses()` here instead of being
	 * trusted. Plain `wp_kses_post()` is not usable: it strips `<iframe>`
	 * (destroying every oEmbed), removes inline `<svg>` outright, and drops
	 * `srcset`/`sizes` from responsive images — so the post allowlist is
	 * extended with exactly the elements and attributes WordPress core's own
	 * block renderers emit.
	 *
	 * Anything still missing can be restored per-site through the
	 * `perflocale/conditional_content/allowed_html` filter rather than by
	 * disabling escaping.
	 *
	 * @param string $content Rendered inner content.
	 * @return string Escaped content.
	 */
	private static function kses_content( string $content ): string {
		if ( $content === '' ) {
			return '';
		}

		$allowed = wp_kses_allowed_html( 'post' );

		// Shared presentational/accessibility attributes core puts on block
		// wrappers. data-* is allowed wholesale by wp_kses since WP 5.0.
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
		 * Filter the allowlist used to escape conditional-content output.
		 *
		 * @hook perflocale/conditional_content/allowed_html
		 *
		 * @param array<string, array<string, bool>> $allowed Allowed HTML, wp_kses() shape.
		 * @param string                             $content Content being escaped.
		 */
		$allowed = (array) apply_filters( 'perflocale/conditional_content/allowed_html', $allowed, $content );

		return wp_kses( $content, $allowed );
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
	 * Build the list of languages the block editor should offer.
	 *
	 * Each entry is `{ slug, label }` - labels favour the native name so
	 * editors recognise their own language instantly in the UI.
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
