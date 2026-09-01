<?php
/**
 * Language Switcher Gutenberg Block.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the perflocale/language-switcher Gutenberg block.
 *
 * Uses server-side rendering so the block output matches the frontend
 * exactly, including current language detection and URL generation.
 */
final class LanguageSwitcherBlock {

	/**
	 * Per-request memo of the `switcher_auto_insert` setting (null until
	 * first read). Cleared on switch_blog — the setting is per-blog.
	 *
	 * @var bool|null
	 */
	private ?bool $auto_insert_enabled = null;

	/**
	 * Per-request memo: "anchor|position" => whether it is the auto-insert
	 * target. Bounds at the number of distinct pairs in the templates
	 * being rendered (dozens), versus thousands of filter invocations.
	 *
	 * @var array<string, bool>
	 */
	private array $anchor_match_memo = [];

	/**
	 * Clear per-blog memos. Hooked to switch_blog on multisite.
	 *
	 * @return void
	 */
	public function reset_memos(): void {
		$this->auto_insert_enabled = null;
		$this->anchor_match_memo   = [];
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// `register_block_type_args` is intentionally NOT wired here — that
		// filter fires for EVERY block type registered after init (100-250
		// invocations on a typical site), and our callback short-circuits on
		// 99% of them. register_block() attaches+detaches the filter only
		// around our own register_block_type() call so the cost is paid once.
		add_action( 'init', [ $this, 'register_frontend_style' ], 9 );
		add_action( 'init', [ $this, 'register_block' ] );
		add_filter( 'hooked_block_types', [ $this, 'filter_hooked_block_types' ], 10, 4 );
		add_filter( 'hooked_block_perflocale/language-switcher', [ $this, 'filter_auto_insert_attrs' ], 10, 4 );

		if ( is_multisite() ) {
			add_action( 'switch_blog', [ $this, 'reset_memos' ] );
		}
	}

	/**
	 * Customise the attributes of the language-switcher block when WP's
	 * Block Hooks engine auto-inserts it.
	 *
	 * Site Title anchors are almost always inside an FSE header, where the
	 * full inline list (the global default) is too tall and the user really
	 * wants the compact `dropdown` mode. We override only the display + flag
	 * size by default; the user can supply any override via the
	 * `perflocale/switcher/auto_insert_attrs` filter.
	 *
	 * @param array|null $parsed_hooked_block The parsed-block array WP will insert.
	 *                                        Null means "don't insert"; we never return null.
	 * @param string     $hooked_block_type   Always 'perflocale/language-switcher' (route gate).
	 * @param string     $relative_position   Position relative to anchor ('before'/'after'/...).
	 * @param array|null $parsed_anchor_block The anchor block array (or null in some contexts).
	 * @return array|null
	 */
	public function filter_auto_insert_attrs( $parsed_hooked_block, string $hooked_block_type, string $relative_position, $parsed_anchor_block ) {
		if ( ! is_array( $parsed_hooked_block ) ) {
			return $parsed_hooked_block;
		}

		$anchor_type = is_array( $parsed_anchor_block ) ? (string) ( $parsed_anchor_block['blockName'] ?? '' ) : '';

		// Compact defaults for FSE header context.
		$defaults = [
			'display'  => 'dropdown',
			'flagSize' => 16,
			'fontSize' => 13,
		];

		/**
		 * Filter the default attributes applied to the auto-inserted switcher.
		 *
		 * Use this to force a specific display mode, font size, layout, etc.
		 * for the auto-inserted block — typically you want compact + dropdown
		 * inside an FSE header, but a site with a wide hero header might want
		 * the full inline list instead.
		 *
		 * Returning an empty array disables the per-context defaults so the
		 * block uses the global Settings → Language Switcher values verbatim.
		 *
		 * @hook perflocale/switcher/auto_insert_attrs
		 *
		 * @param array  $attrs              Default per-context attrs.
		 * @param string $anchor_block_type  The anchor block ('core/site-title' default).
		 * @param string $relative_position  'before' | 'after' | 'first_child' | 'last_child'.
		 * @param array|null $parsed_anchor_block The full anchor block payload.
		 */
		$attrs = (array) apply_filters(
			'perflocale/switcher/auto_insert_attrs',
			$defaults,
			$anchor_type,
			$relative_position,
			$parsed_anchor_block
		);

		if ( $attrs === [] ) {
			return $parsed_hooked_block;
		}

		$existing = isset( $parsed_hooked_block['attrs'] ) && is_array( $parsed_hooked_block['attrs'] )
			? $parsed_hooked_block['attrs']
			: [];

		// Merge: filter-returned attrs win over existing on key conflict, so
		// the operator's per-context overrides take effect. Existing keys
		// the filter doesn't touch are preserved.
		$parsed_hooked_block['attrs'] = array_merge( $existing, $attrs );

		return $parsed_hooked_block;
	}

	/**
	 * Strip our auto-inserted hook when the user has opted out, or rewire the
	 * anchor when a theme declares its own preferred insertion point.
	 *
	 * The block.json `blockHooks` declaration always registers the switcher
	 * against `core/site-title:after`. This filter is the runtime gate that
	 * the `switcher_auto_insert` setting (and the per-theme override filter)
	 * uses to decide whether to actually inject for any given anchor.
	 *
	 * `$anchor_block_type` is nullable: WordPress core's `insert_hooked_blocks()`
	 * walks template trees recursively and passes null when the outer iterator
	 * has no anchor context (top-of-template injection for first_child /
	 * last_child positions). Strict-typing it would 500 the entire FSE
	 * rendering pipeline.
	 *
	 * @param string[]    $hooked_block_types Block types the engine will hook.
	 * @param string      $relative_position  'before'|'after'|'first_child'|'last_child'.
	 * @param string|null $anchor_block_type  The anchor block's full type string, or null for context-less iteration.
	 * @param mixed       $context            Template / pattern / template part the engine is composing.
	 * @return string[]
	 */
	public function filter_hooked_block_types( array $hooked_block_types, string $relative_position, ?string $anchor_block_type, $context ): array {
		// Null anchor = context-less iteration. Our injection is targeted at
		// a specific anchor block; without one, just pass through unchanged.
		if ( $anchor_block_type === null ) {
			return $hooked_block_types;
		}

		// This filter fires for EVERY (block, position) pair the template
		// engine walks — thousands of invocations on block-heavy screens
		// (4,000+ on a wp-admin pages list). The enabled flag is request-
		// constant and the anchor-override decision is constant per
		// (anchor, position) pair, so both are memoised; per-call work
		// after the first sighting of a pair is two array lookups.
		if ( $this->auto_insert_enabled === null ) {
			$plugin = \PerfLocale\Plugin::get_instance();

			$this->auto_insert_enabled = $plugin->has( 'settings' )
				? (bool) $plugin->get( 'settings' )->get( 'switcher_auto_insert', true )
				: true;
		}

		$already_present = in_array( 'perflocale/language-switcher', $hooked_block_types, true );

		if ( ! $this->auto_insert_enabled ) {
			return $already_present
				? array_values( array_diff( $hooked_block_types, [ 'perflocale/language-switcher' ] ) )
				: $hooked_block_types;
		}

		$pair_key = $anchor_block_type . '|' . $relative_position;

		if ( ! isset( $this->anchor_match_memo[ $pair_key ] ) ) {
			$target_anchor   = 'core/site-title';
			$target_position = 'after';

			/**
			 * Allow themes / addons to redirect the auto-insertion to a different
			 * anchor block. Return `[ block_type, position ]` (e.g.
			 * `[ 'core/navigation', 'last_child' ]`) to retarget; return false to
			 * keep the default `core/site-title:after`.
			 *
			 * @hook perflocale/switcher/auto_insert_anchor
			 *
			 * @param array|false $override          Defaults to false (no override).
			 * @param string      $anchor_block_type The anchor the engine is asking about.
			 * @param string      $relative_position The position relative to that anchor.
			 */
			$override = apply_filters( 'perflocale/switcher/auto_insert_anchor', false, $anchor_block_type, $relative_position );

			if ( is_array( $override ) && count( $override ) === 2 ) {
				$target_anchor   = (string) $override[0];
				$target_position = (string) $override[1];
			}

			$this->anchor_match_memo[ $pair_key ] = ( $anchor_block_type === $target_anchor && $relative_position === $target_position );
		}

		$matches_target = $this->anchor_match_memo[ $pair_key ];

		if ( $matches_target && ! $already_present ) {
			$hooked_block_types[] = 'perflocale/language-switcher';
			return $hooked_block_types;
		}

		if ( ! $matches_target && $already_present ) {
			return array_values( array_diff( $hooked_block_types, [ 'perflocale/language-switcher' ] ) );
		}

		return $hooked_block_types;
	}

	/**
	 * Register the `perflocale-frontend` stylesheet handle.
	 *
	 * Hooked at priority 9 so the handle exists before `register_block_type` at default priority.
	 *
	 * @return void
	 */
	public function register_frontend_style(): void {
		if ( wp_style_is( 'perflocale-frontend', 'registered' ) ) {
			return;
		}

		wp_register_style(
			'perflocale-frontend',
			PERFLOCALE_URL . 'assets/css/frontend.css',
			[],
			PERFLOCALE_VERSION
		);
	}

	/**
	 * Replace block.json attribute defaults with the site's switcher settings.
	 *
	 * Per-block inspector overrides still win.
	 *
	 * @param array<string, mixed> $args Block registration args.
	 * @param string               $name Block type name.
	 * @return array<string, mixed>
	 */
	public function apply_setting_defaults( array $args, string $name ): array {
		if ( $name !== 'perflocale/language-switcher' ) {
			return $args;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return $args;
		}

		$settings = $plugin->get( 'settings' );

		$map = [
			'display'          => [ 'switcher_display', 'dropdown' ],
			'layout'           => [ 'switcher_layout', 'horizontal' ],
			'style'            => [ 'switcher_template', 'flags_names' ],
			'nameFormat'       => [ 'switcher_name_format', 'native' ],
			'triggerFormat'    => [ 'switcher_trigger_format', 'inherit' ],
			'hideCurrent'      => [ 'switcher_hide_current', false ],
			'showUntranslated' => [ 'switcher_show_untranslated', false ],
			'untranslatedLink' => [ 'switcher_untranslated_link', 'homepage' ],
			'arrowStyle'       => [ 'switcher_arrow_style', 'single' ],
		];

		foreach ( $map as $attr => [ $key, $fallback ] ) {
			if ( ! isset( $args['attributes'][ $attr ] ) ) {
				continue;
			}

			$value                                  = $settings->get( $key, $fallback );
			$args['attributes'][ $attr ]['default'] = is_bool( $fallback ) ? (bool) $value : (string) $value;
		}

		return $args;
	}

	/**
	 * Register the block type from its block.json metadata.
	 *
	 * @return void
	 */
	public function register_block(): void {
		// Scope the register_block_type_args filter to just our own
		// registration: attach immediately before, detach immediately after.
		// WP's block-registry calls our filter only during the call below,
		// so any other block-type registration in the request — WooCommerce,
		// FSE patterns, third-party plugins — runs without paying the
		// callback's cost (~150-400 µs/request total).
		add_filter( 'register_block_type_args', [ $this, 'apply_setting_defaults' ], 10, 2 );

		register_block_type(
			PERFLOCALE_DIR . 'blocks/language-switcher',
			[
				'render_callback' => [ $this, 'render_block_callback' ],
			]
		);

		remove_filter( 'register_block_type_args', [ $this, 'apply_setting_defaults' ], 10 );
	}

	/**
	 * Render the dropdown trigger's arrow indicator.
	 *
	 * @param string $style One of `single`, `double`, `none`.
	 * @return string HTML to inject inside the trigger button.
	 */
	public static function arrow_html( string $style = 'single' ): string {
		$style = sanitize_key( $style );

		$svg_attrs  = 'class="perflocale-dd__arrow" width="0.85em" height="0.85em" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"';
		$path_attrs = 'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"';

		switch ( $style ) {
			case 'single':
				$html = '<svg ' . $svg_attrs . '><path d="M3 4.5L6 7.5L9 4.5" ' . $path_attrs . '/></svg>';
				break;

			case 'double':
				$html = '<svg ' . $svg_attrs . '><path d="M3 4.5L6 2L9 4.5M3 7.5L6 10L9 7.5" ' . $path_attrs . '/></svg>';
				break;

			default:
				$html = '';
				break;
		}

		/**
		 * Filter the dropdown trigger arrow HTML.
		 *
		 * @hook perflocale/switcher/arrow_html
		 *
		 * @param string $html  Computed arrow HTML.
		 * @param string $style The resolved style key.
		 */
		$filtered = apply_filters( 'perflocale/switcher/arrow_html', $html, $style );

		if ( ! is_string( $filtered ) || $filtered === '' ) {
			return '';
		}

		// The built-in arrow is a hardcoded, input-free SVG; return it as-is so
		// its case-sensitive `viewBox` survives — wp_kses (via kses_fragment)
		// lowercases attribute names, and browsers ignore a lowercased
		// `viewbox` on <svg>, leaving the chevron unable to scale with the
		// trigger font size. Only third-party-FILTERED markup is sanitised.
		return $filtered === $html ? $filtered : self::kses_fragment( $filtered );
	}

	/**
	 * Build the attribute string for the outer <nav> wrapper.
	 *
	 * @param string         $classes      Space-separated class list.
	 * @param string         $inline_style Inline style string.
	 * @param \WP_Block|null $block        Block instance when called via render_callback.
	 * @return string Ready-to-interpolate attribute string.
	 */
	private static function build_wrapper_attrs( string $classes, string $inline_style, $block = null ): string {
		$aria_label = __( 'Language switcher', 'perflocale' );
		$base       = [
			'class'      => $classes,
			'style'      => $inline_style,
			'aria-label' => $aria_label,
		];

		if ( $block instanceof \WP_Block && function_exists( 'get_block_wrapper_attributes' ) ) {
			return get_block_wrapper_attributes( $base );
		}

		$parts = [];

		foreach ( $base as $name => $value ) {
			if ( $value === '' ) {
				continue;
			}

			$parts[] = $name . '="' . esc_attr( $value ) . '"';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Sanitize switcher HTML for safe `echo` from addons.
	 *
	 * @param string $html Switcher fragment.
	 * @return string Sanitized HTML.
	 */
	public static function kses_switcher( string $html ): string {
		if ( $html === '' ) {
			return '';
		}

		// Sanitisation is unconditional by design. Every call site echoes this
		// return value directly, so an opt-out filter here would make the
		// escaping guarantee those call sites rely on conditional. Sites that
		// need extra tags or attributes widen the allowlist through
		// `perflocale/switcher/kses_allowed_html` instead of turning escaping off.
		static $memo = [];
		$key         = sha1( $html );

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$allowed = self::kses_allowlist( $html );
		$out     = wp_kses( $html, $allowed );

		if ( count( $memo ) >= 32 ) {
			array_shift( $memo );
		}

		$memo[ $key ] = $out;
		return $out;
	}

	/**
	 * Build the wp_kses allowlist used by `kses_switcher()`.
	 *
	 * @param string $html The HTML being sanitized (exposed so filters can vary by content).
	 * @return array<string, array<string, bool>>
	 */
	private static function kses_allowlist( string $html ): array {
		$allowed        = wp_kses_allowed_html( 'post' );
		$switcher_attrs = [
			'class'           => true,
			'style'           => true,
			'role'            => true,
			'aria-label'      => true,
			'aria-labelledby' => true,
			'aria-controls'   => true,
			'aria-expanded'   => true,
			'aria-haspopup'   => true,
			'aria-selected'   => true,
			'aria-current'    => true,
			'aria-disabled'   => true,
			'aria-hidden'     => true,
			'tabindex'        => true,
			'id'              => true,
			'lang'            => true,
			'dir'             => true,
		];

		$allowed['nav']    = ( $allowed['nav'] ?? [] ) + $switcher_attrs;
		$allowed['button'] = ( $allowed['button'] ?? [] ) + $switcher_attrs + [ 'type' => true ];
		$allowed['div']    = ( $allowed['div'] ?? [] ) + $switcher_attrs;
		$allowed['span']   = ( $allowed['span'] ?? [] ) + $switcher_attrs;
		$allowed['a']      = ( $allowed['a'] ?? [] ) + $switcher_attrs + [
			'href'     => true,
			'hreflang' => true,
			'target'   => true,
			'rel'      => true,
		];

		$allowed['svg']  = [
			'class'       => true,
			'width'       => true,
			'height'      => true,
			'viewbox'     => true,
			'fill'        => true,
			'xmlns'       => true,
			'aria-hidden' => true,
			'role'        => true,
		];
		$allowed['path'] = [
			'd'               => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'fill'            => true,
		];

		/**
		 * Filter the wp_kses allowlist used to sanitize switcher HTML.
		 * Map shape: `[ 'tag' => [ 'attr' => true, ... ], ... ]`.
		 *
		 * @param array<string, array<string, bool>> $allowed Current allowlist.
		 * @param string                             $html    The HTML being sanitized.
		 */
		return (array) apply_filters( 'perflocale/switcher/kses_allowed_html', $allowed, $html );
	}

	/**
	 * Sanitize a small switcher HTML fragment.
	 *
	 * @param string $html Fragment to sanitize.
	 * @return string
	 */
	public static function kses_fragment( string $html ): string {
		return self::kses_switcher( $html );
	}

	/**
	 * Build a ` lang="…" [dir="rtl"]` attribute pair (leading space).
	 *
	 * @param object $lang Language object.
	 * @return string
	 */
	private static function language_attr_string( object $lang ): string {
		$out = ' lang="' . esc_attr( \PerfLocale\Helper::format_locale_as_bcp47( (string) ( $lang->slug ?? '' ) ) ) . '"';

		if ( ! empty( $lang->text_direction ) && $lang->text_direction === 'rtl' ) {
			$out .= ' dir="rtl"';
		}

		return $out;
	}

	/**
	 * Block render_callback entry point. Wraps {@see render()} in the same
	 * SVG-aware {@see kses_switcher()} allowlist that LanguageSwitcherWidget
	 * and CustomizerIntegration apply when they echo the switcher, so the
	 * block-into-the_content path is sanitized at the same boundary. The
	 * memo inside kses_switcher() makes the second pass effectively free.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Sanitized HTML output.
	 */
	public function render_block_callback( array $attributes, string $content = '', $block = null ): string {
		return self::kses_switcher( $this->render( $attributes, $content, $block ) );
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string HTML output.
	 */
	public function render( array $attributes, string $content = '', $block = null ): string {
		unset( $content );

		if ( ! is_admin() && ! wp_style_is( 'perflocale-frontend', 'enqueued' ) ) {
			wp_enqueue_style(
				'perflocale-frontend',
				PERFLOCALE_URL . 'assets/css/frontend.css',
				[],
				PERFLOCALE_VERSION
			);
		}

		static $languages    = null;
		static $current_slug = null;
		static $urls         = null;
		static $memo_blog    = null;

		// These are function-statics (reset_memos() can't reach them), so key
		// them by blog id: a request that renders the switcher for blog A and
		// then, after switch_to_blog(), for blog B must not reuse A's languages/
		// URLs. No-op on single-site (blog id is constant).
		$current_blog = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;

		if ( $languages === null || $memo_blog !== $current_blog ) {
			$memo_blog = $current_blog;
			$plugin    = \PerfLocale\Plugin::get_instance();

			if ( ! $plugin->has( 'router' ) ) {
				$languages = [];
			} else {
				$router       = $plugin->get( 'router' );
				$languages    = $router->get_active_languages();
				$current_slug = $router->get_current_slug();
				$urls         = $plugin->has( 'url_converter' )
					? $plugin->get( 'url_converter' )->get_translations_for_current_page()
					: [];
			}

			/**
			 * Filter the languages shown in the switcher.
			 *
			 * Applied inside the populate block (and the filtered result is
			 * memoised) so a second switcher on the same page doesn't re-run
			 * the filter on already-filtered data — non-idempotent callbacks
			 * would otherwise compound across renders.
			 *
			 * @hook perflocale/switcher/languages
			 *
			 * @param array $languages Active language objects.
			 */
			$languages = apply_filters( 'perflocale/switcher/languages', $languages );
		}

		if ( empty( $languages ) ) {
			return '<p>' . esc_html__( 'No languages configured.', 'perflocale' ) . '</p>';
		}

		$plugin_settings  = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$default_display  = $plugin_settings->get( 'switcher_display', 'dropdown' );
		$default_style    = $plugin_settings->get( 'switcher_template', 'flags_names' );
		$default_layout   = $plugin_settings->get( 'switcher_layout', 'horizontal' );
		$default_name_fmt = $plugin_settings->get( 'switcher_name_format', 'native' );
		$default_hide     = (bool) $plugin_settings->get( 'switcher_hide_current', false );
		$default_untrans  = (bool) $plugin_settings->get( 'switcher_show_untranslated', false );

		$display              = sanitize_key( $attributes['display'] ?? $default_display );
		$layout               = sanitize_key( $attributes['layout'] ?? $default_layout );
		$style                = sanitize_key( $attributes['style'] ?? $default_style );
		$show_flags           = (bool) ( $attributes['showFlags'] ?? true );
		$show_names           = (bool) ( $attributes['showNames'] ?? true );
		$name_format          = sanitize_key( $attributes['nameFormat'] ?? $default_name_fmt );
		$hide_current         = (bool) ( $attributes['hideCurrent'] ?? $default_hide );
		$show_untranslated    = (bool) ( $attributes['showUntranslated'] ?? $default_untrans );
		$default_untrans_link = (string) $plugin_settings->get( 'switcher_untranslated_link', 'homepage' );
		$untranslated_link    = sanitize_key( $attributes['untranslatedLink'] ?? $default_untrans_link );
		$default_arrow_style  = (string) $plugin_settings->get( 'switcher_arrow_style', 'single' );
		$arrow_style          = sanitize_key( $attributes['arrowStyle'] ?? $default_arrow_style );
		$default_trigger_fmt  = (string) $plugin_settings->get( 'switcher_trigger_format', 'inherit' );
		$trigger_format       = sanitize_key( $attributes['triggerFormat'] ?? $default_trigger_fmt );
		if ( $trigger_format === 'inherit' || $trigger_format === '' ) {
			$trigger_format = $name_format;
		}
		$font_size      = absint( $attributes['fontSize'] ?? 14 );
		$flag_size      = absint( $attributes['flagSize'] ?? 20 );
		$gap            = absint( $attributes['gap'] ?? 8 );
		$global_class   = (string) $plugin_settings->get( 'switcher_class', '' );
		$instance_class = trim( $attributes['className'] ?? '' );
		$raw_classes    = trim( $global_class . ' ' . $instance_class );
		$class_name     = implode( ' ', array_map( 'sanitize_html_class', array_filter( explode( ' ', $raw_classes ) ) ) );

		if ( $style === 'flags_only' ) {
			$show_flags = true;
			$show_names = false;
		} elseif ( $style === 'names_only' ) {
			$show_flags = false;
			$show_names = true;
		}

		$resolved = [
			'display'          => $display,
			'showFlags'        => $show_flags,
			'showNames'        => $show_names,
			'nameFormat'       => $name_format,
			'triggerFormat'    => $trigger_format,
			'hideCurrent'      => $hide_current,
			'showUntranslated' => $show_untranslated,
			'untranslatedLink' => $untranslated_link,
			'arrowStyle'       => $arrow_style,
			'fontSize'         => $font_size,
			'flagSize'         => $flag_size,
			'className'        => $class_name,
		];

		if ( count( $languages ) < 2 ) {
			return '';
		}

		if ( $display === 'dropdown' ) {
			return $this->render_dropdown( $languages, $current_slug, $urls, $resolved, $block );
		}

		$is_simple   = ( $display === 'simple' );
		$is_vertical = ( $layout === 'vertical' );

		$wrapper_style = sprintf(
			'--perflocale-gap:%dpx;--perflocale-font-size:%dpx;--perflocale-flag-size:%dpx;',
			$gap,
			$font_size,
			$flag_size
		);

		$classes  = 'perflocale-switcher-block';
		$classes .= $is_vertical ? ' perflocale-switcher-block--vertical' : ' perflocale-switcher-block--horizontal';

		if ( $is_simple ) {
			$classes .= ' perflocale-switcher-block--simple';
		}

		if ( $class_name ) {
			$classes .= ' ' . $class_name;
		}

		$html  = '<nav ' . self::build_wrapper_attrs( $classes, $wrapper_style, $block ) . '>';
		$html .= '<ul>';

		foreach ( $languages as $lang ) {
			$is_current = ( $lang->slug === $current_slug );

			if ( $is_current && $hide_current ) {
				continue;
			}

			$url             = $urls[ $lang->slug ] ?? '';
			$has_translation = ( $url !== '' );

			if ( ! $has_translation && ! $is_current ) {
				if ( ! $show_untranslated || $untranslated_link === 'hide' ) {
					continue;
				}
			}

			if ( ! $has_translation && ! $is_current ) {
				if ( $untranslated_link === 'no_link' ) {
					$url = '';
				} else {
					$url = perflocale_home_url( $lang->slug );
				}
			}

			$label = $this->format_name( $lang, $name_format );

			// An untranslated language shown as a non-link placeholder is NOT
			// the current language: it must not wear the --current style, and it
			// carries aria-disabled so assistive tech announces it as an inert
			// option — mirroring the dropdown and nav-menu placeholder paths.
			$is_placeholder = ( ! $has_translation && $untranslated_link === 'no_link' );

			if ( $is_current ) {
				$aria = ' aria-current="true"';
			} elseif ( $is_placeholder ) {
				$aria = ' aria-disabled="true"';
			} else {
				$aria = '';
			}

			$html .= '<li>';

			if ( $is_current || $is_placeholder ) {
				$item_class = 'perflocale-switcher-block__item ' . ( $is_current
					? 'perflocale-switcher-block__item--current'
					: 'perflocale-switcher-block__item--disabled' );
				$html      .= '<span class="' . esc_attr( $item_class ) . '"' . $aria . self::language_attr_string( $lang ) . '>';
			} else {
				$base_attrs = [
					'href'     => $url,
					'class'    => 'perflocale-switcher-block__item',
					'hreflang' => \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ),
					'lang'     => \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ),
				];

				if ( ! empty( $lang->text_direction ) && $lang->text_direction === 'rtl' ) {
					$base_attrs['dir'] = 'rtl';
				}

				$link_attrs = LanguageSwitcher::render_link_attrs(
					$base_attrs,
					$lang,
					$current_slug,
					$resolved
				);
				$html      .= '<a ' . $link_attrs . '>';
			}

			$inner = '';

			if ( $show_flags && $style !== 'names_only' ) {
				$inner .= '<span class="perflocale-switcher-block__flag">';
				$inner .= esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) );
				$inner .= '</span>';
			}

			if ( $show_names && $style !== 'flags_only' ) {
				$inner .= '<span class="perflocale-switcher-block__label">';
				$inner .= esc_html( $label );
				$inner .= '</span>';
			}

			$html .= self::kses_fragment(
				(string) apply_filters(
					'perflocale/switcher/option_content',
					$inner,
					$lang,
					$current_slug,
					$resolved
				)
			);

			if ( $is_current || $is_placeholder ) {
				$html .= '</span>';
			} else {
				$html .= '</a>';
			}

			$html .= '</li>';
		}

		$html .= '</ul>';
		$html .= '</nav>';

		return $html;
	}

	/**
	 * Render dropdown style.
	 *
	 * @param array<int, object>   $languages Active languages.
	 * @param string               $current_slug Current language slug.
	 * @param array<string,string> $urls Translation URLs.
	 * @param array<string,mixed>  $attributes Block attributes.
	 * @return string
	 */
	private function render_dropdown( array $languages, string $current_slug, array $urls, array $attributes, $block = null ): string {
		// Bind the Settings singleton once. Common renders (no per-block
		// arrowStyle / untranslatedLink override) hit BOTH fallback paths
		// below and previously walked the
		// Plugin::get_instance()->get('settings')->get(...) chain twice
		// per dropdown render. One bind, two reuses.
		$plugin_settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		$name_format    = $attributes['nameFormat'] ?? 'native';
		$trigger_format = isset( $attributes['triggerFormat'] ) && $attributes['triggerFormat'] !== ''
			? sanitize_key( (string) $attributes['triggerFormat'] )
			: 'inherit';
		if ( $trigger_format === 'inherit' ) {
			$trigger_format = $name_format;
		}
		$show_flags        = (bool) ( $attributes['showFlags'] ?? true );
		$show_names        = (bool) ( $attributes['showNames'] ?? true );
		$font_size         = (int) ( $attributes['fontSize'] ?? 14 );
		$flag_size         = (int) ( $attributes['flagSize'] ?? 18 );
		$class_name        = implode( ' ', array_map( 'sanitize_html_class', array_filter( explode( ' ', $attributes['className'] ?? '' ) ) ) );
		$hide_current      = (bool) ( $attributes['hideCurrent'] ?? false );
		$show_untranslated = (bool) ( $attributes['showUntranslated'] ?? false );
		$arrow_style       = isset( $attributes['arrowStyle'] ) && $attributes['arrowStyle'] !== ''
			? sanitize_key( (string) $attributes['arrowStyle'] )
			: (string) $plugin_settings->get( 'switcher_arrow_style', 'single' );
		$untranslated_link = isset( $attributes['untranslatedLink'] ) && $attributes['untranslatedLink'] !== ''
			? sanitize_key( (string) $attributes['untranslatedLink'] )
			: (string) $plugin_settings->get( 'switcher_untranslated_link', 'homepage' );

		$classes = 'perflocale-switcher-block perflocale-switcher-block--dropdown';

		if ( $class_name !== '' ) {
			$classes .= ' ' . $class_name;
		}

		$current_lang = null;

		foreach ( $languages as $lang ) {
			if ( $lang->slug === $current_slug ) {
				$current_lang = $lang;
				break;
			}
		}

		if ( ! $current_lang ) {
			return '';
		}

		// Pre-count the options the loop below would actually render (same
		// skip rules). On an untranslated page with hideCurrent + untranslated
		// languages hidden, that count is ZERO — rendering a trigger whose
		// panel opens empty is a dead control, so suppress the whole block.
		$renderable = 0;

		foreach ( $languages as $lang ) {
			if ( $hide_current && $lang->slug === $current_slug ) {
				continue;
			}

			$is_active       = $lang->slug === $current_slug;
			$has_translation = ( ( $urls[ $lang->slug ] ?? '' ) !== '' );

			if ( ! $has_translation && ! $is_active && ( ! $show_untranslated || $untranslated_link === 'hide' ) ) {
				continue;
			}

			++$renderable;
		}

		if ( $renderable === 0 ) {
			return '';
		}

		$dropdown_id = 'perflocale-dd-' . wp_unique_id();

		$wrapper_style = sprintf(
			'--perflocale-font-size:%dpx;--perflocale-flag-size:%dpx;',
			$font_size,
			$flag_size
		);

		$html = '<nav ' . self::build_wrapper_attrs( $classes, $wrapper_style, $block ) . '>';

		$current_name = $this->format_name( $current_lang, $trigger_format );
		$trigger_id   = $dropdown_id . '-trigger';
		/* translators: %s: name of the currently-selected language */
		$trigger_label = sprintf( __( 'Choose language, currently %s', 'perflocale' ), $current_name );

		$html .= '<div class="perflocale-dd__anchor">';

		$html .= '<button type="button" id="' . esc_attr( $trigger_id ) . '" class="perflocale-dd__trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="' . esc_attr( $dropdown_id ) . '" aria-label="' . esc_attr( $trigger_label ) . '"' . self::language_attr_string( $current_lang ) . '>';

		if ( $show_flags ) {
			$html .= '<span class="perflocale-dd__flag">' . esc_html( \PerfLocale\Helper::get_flag_emoji( $current_lang ) ) . '</span>';
		}

		if ( $show_names ) {
			$html .= '<span class="perflocale-dd__label">' . esc_html( $current_name ) . '</span>';
		}

		$html .= self::arrow_html( $arrow_style );
		$html .= '</button>';

		$html .= '<div id="' . esc_attr( $dropdown_id ) . '" class="perflocale-dd__panel" role="listbox" aria-labelledby="' . esc_attr( $trigger_id ) . '">';

		/**
		 * Filter HTML injected at the START of the dropdown panel.
		 *
		 * @hook perflocale/switcher/panel_before
		 *
		 * @param string             $html         HTML to inject (default '').
		 * @param array<int, object> $languages    Languages about to be rendered.
		 * @param string             $current_slug Current language slug.
		 * @param array              $attributes   Resolved switcher attributes.
		 */
		$html .= self::kses_fragment( (string) apply_filters( 'perflocale/switcher/panel_before', '', $languages, $current_slug, $attributes ) );

		foreach ( $languages as $lang ) {
			if ( $hide_current && $lang->slug === $current_slug ) {
				continue;
			}

			$is_active       = $lang->slug === $current_slug;
			$url             = $urls[ $lang->slug ] ?? '';
			$has_translation = ( $url !== '' );

			if ( ! $has_translation && ! $is_active ) {
				if ( ! $show_untranslated || $untranslated_link === 'hide' ) {
					continue;
				}
			}

			if ( ! $has_translation && ! $is_active ) {
				if ( $untranslated_link === 'no_link' ) {
					$url = '';
				} else {
					$url = perflocale_home_url( $lang->slug );
				}
			}

			$option_class = 'perflocale-dd__option' . ( $is_active ? ' perflocale-dd__option--active' : '' );

			if ( $url !== '' ) {
				$base_attrs = [
					'href'     => $url,
					'class'    => $option_class,
					'role'     => 'option',
					'tabindex' => '-1',
					'hreflang' => \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ),
					'lang'     => \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ),
				];

				if ( ! empty( $lang->text_direction ) && $lang->text_direction === 'rtl' ) {
					$base_attrs['dir'] = 'rtl';
				}

				if ( $is_active ) {
					$base_attrs['aria-selected'] = 'true';
				}

				$link_attrs = LanguageSwitcher::render_link_attrs(
					$base_attrs,
					$lang,
					$current_slug,
					$attributes
				);

				$html .= '<a ' . $link_attrs . '>';
			} else {
				$html .= '<span class="' . esc_attr( $option_class ) . ' perflocale-dd__option--disabled" role="option" aria-disabled="true" tabindex="-1"' . ( $is_active ? ' aria-selected="true"' : '' ) . self::language_attr_string( $lang ) . '>';
			}

			$inner = '';

			if ( $show_flags ) {
				$inner .= '<span class="perflocale-dd__flag">' . esc_html( \PerfLocale\Helper::get_flag_emoji( $lang ) ) . '</span>';
			}

			if ( $show_names ) {
				$inner .= '<span>' . esc_html( $this->format_name( $lang, $name_format ) ) . '</span>';
			}

			/**
			 * Filter the inner HTML rendered inside each switcher option.
			 *
			 * @hook perflocale/switcher/option_content
			 *
			 * @param string $inner        Default inner HTML (flag span + name span).
			 * @param object $lang         Language object for this option.
			 * @param string $current_slug Currently active language slug.
			 * @param array  $attrs        Resolved switcher attributes.
			 */
			$html .= self::kses_fragment(
				(string) apply_filters(
					'perflocale/switcher/option_content',
					$inner,
					$lang,
					$current_slug,
					$attributes
				)
			);

			$html .= $url !== '' ? '</a>' : '</span>';
		}

		/**
		 * Filter HTML injected at the END of the dropdown panel.
		 *
		 * @hook perflocale/switcher/panel_after
		 *
		 * @param string             $html         HTML to inject (default '').
		 * @param array<int, object> $languages    Languages that were rendered.
		 * @param string             $current_slug Current language slug.
		 * @param array              $attributes   Resolved switcher attributes.
		 */
		$html .= self::kses_fragment( (string) apply_filters( 'perflocale/switcher/panel_after', '', $languages, $current_slug, $attributes ) );

		$html .= '</div>';
		$html .= '</div>';
		$html .= '</nav>';

		if ( ! wp_script_is( 'perflocale-switcher-dropdown', 'enqueued' ) ) {
			wp_enqueue_script(
				'perflocale-switcher-dropdown',
				PERFLOCALE_URL . 'assets/js/language-switcher-dropdown.js',
				[],
				PERFLOCALE_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}

		return $html;
	}

	/**
	 * Format a language name based on the chosen format.
	 *
	 * @param object $lang Language object.
	 * @param string $name_format Format key.
	 * @return string
	 */
	private function format_name( object $lang, string $name_format ): string {
		$name        = $lang->name ?? '';
		$native_name = $lang->native_name ?? $name;

		return match ( $name_format ) {
			'english' => $name,
			'native' => $native_name ?: $name,
			'both' => $name . ' (' . ( $native_name ?: $name ) . ')',
			'slug' => \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ),
			default => $native_name ?: $name,
		};
	}
}
