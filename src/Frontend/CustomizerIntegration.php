<?php
/**
 * WordPress Customizer integration - registers a Language Switcher section.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a "Language Switcher" section in the WordPress Customizer.
 */
final class CustomizerIntegration {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'customize_register', [ $this, 'register_customizer_settings' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
		add_action( 'wp_footer', [ $this, 'output_custom_placement' ] );
	}

	/**
	 * Enqueue the switcher's frontend CSS during wp_enqueue_scripts.
	 *
	 * The floating switcher renders in wp_footer, too late for the head stylesheets.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! get_theme_mod( 'perflocale_floating_switcher', false ) ) {
			return;
		}

		VisitorAssets::enqueue_style();
	}

	/**
	 * The Customizer controls, loaded when customize_register or a setting's sanitize callback runs.
	 *
	 * @return CustomizerControls
	 */
	private function controls(): CustomizerControls {
		return new CustomizerControls();
	}

	/**
	 * Register Customizer section, settings, and controls.
	 *
	 * @param \WP_Customize_Manager $wp_customize Customizer manager.
	 * @return void
	 */
	public function register_customizer_settings( \WP_Customize_Manager $wp_customize ): void {
		$this->controls()->register_customizer_settings( $wp_customize );
	}

	/**
	 * Output the floating language switcher if enabled.
	 *
	 * @return void
	 */
	public function output_custom_placement(): void {
		if ( is_admin() ) {
			return;
		}

		$enabled = get_theme_mod( 'perflocale_floating_switcher', false );

		if ( ! $enabled ) {
			return;
		}

		$settings          = \PerfLocale\Plugin::get_instance()->get( 'settings' );
		$position          = get_theme_mod( 'perflocale_floating_position', 'bottom-right' );
		$style             = get_theme_mod( 'perflocale_floating_style', $settings->get( 'switcher_template', 'flags_names' ) );
		$display_mode      = get_theme_mod( 'perflocale_floating_display', $settings->get( 'switcher_display', 'dropdown' ) );
		$layout            = get_theme_mod( 'perflocale_floating_layout', $settings->get( 'switcher_layout', 'horizontal' ) );
		$arrow_style       = get_theme_mod( 'perflocale_floating_arrow_style', $settings->get( 'switcher_arrow_style', 'single' ) );
		$trigger_format    = get_theme_mod( 'perflocale_floating_trigger_format', $settings->get( 'switcher_trigger_format', 'inherit' ) );
		$name_format       = get_theme_mod( 'perflocale_floating_name_format', $settings->get( 'switcher_name_format', 'native' ) );
		$hide_current      = get_theme_mod( 'perflocale_floating_hide_current', (bool) $settings->get( 'switcher_hide_current', false ) );
		$show_untranslated = get_theme_mod( 'perflocale_floating_show_untranslated', (bool) $settings->get( 'switcher_show_untranslated', false ) );
		$font_size         = absint( get_theme_mod( 'perflocale_floating_font_size', 14 ) );
		$flag_size         = absint( get_theme_mod( 'perflocale_floating_flag_size', 20 ) );
		$gap               = absint( get_theme_mod( 'perflocale_floating_gap', 8 ) );

		$block = new LanguageSwitcherBlock();

		$html = $block->render(
			[
				'display'          => $display_mode,
				'style'            => $style,
				'layout'           => $layout,
				'nameFormat'       => $name_format,
				'triggerFormat'    => $trigger_format,
				'showFlags'        => $style !== 'names_only',
				'showNames'        => $style !== 'flags_only',
				'hideCurrent'      => (bool) $hide_current,
				'showUntranslated' => (bool) $show_untranslated,
				'arrowStyle'       => $arrow_style,
				'fontSize'         => $font_size,
				'flagSize'         => $flag_size,
				'gap'              => $gap,
			]
		);

		$safe_html = LanguageSwitcherBlock::kses_switcher( $html );

		// Nothing to show after kses: no empty fixed-position box.
		if ( trim( $safe_html ) === '' ) {
			return;
		}

		$position_class = in_array( $position, [ 'bottom-left', 'top-right', 'top-left', 'bottom-right' ], true )
			? 'perflocale-floating-switcher--' . $position
			: 'perflocale-floating-switcher--bottom-right';

		$classes = 'perflocale-floating-switcher ' . $position_class;

		if ( is_admin_bar_showing() ) {
			$classes .= ' perflocale-floating-switcher--with-admin-bar';
		}

		echo '<div class="' . esc_attr( $classes ) . '">';
		// Re-escaped at the sink on purpose: the wordpress.org scanner ignores phpcs:ignore.
		// The second kses_switcher() pass is a memo hit.
		echo LanguageSwitcherBlock::kses_switcher( $safe_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist.
		echo '</div>';
	}

	/**
	 * Sanitize checkbox value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function sanitize_checkbox( mixed $value ): bool {
		return $this->controls()->sanitize_checkbox( $value );
	}

	/**
	 * Sanitize position value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function sanitize_position( mixed $value ): string {
		return $this->controls()->sanitize_position( $value );
	}
}
