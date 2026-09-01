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
	 * Needed because the floating switcher is echoed in wp_footer, after
	 * LanguageSwitcherBlock::render()'s own enqueue call would be too late.
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

		if ( ! wp_style_is( 'perflocale-frontend', 'registered' ) ) {
			wp_register_style(
				'perflocale-frontend',
				PERFLOCALE_URL . 'assets/css/frontend.css',
				[],
				PERFLOCALE_VERSION
			);
		}

		wp_enqueue_style( 'perflocale-frontend' );
	}

	/**
	 * Register Customizer section, settings, and controls.
	 *
	 * @param \WP_Customize_Manager $wp_customize Customizer manager.
	 * @return void
	 */
	public function register_customizer_settings( \WP_Customize_Manager $wp_customize ): void {
		// Section.
		$wp_customize->add_section(
			'perflocale_switcher',
			[
				'title'       => __( 'Language Switcher', 'perflocale' ),
				'description' => __( 'Configure the language switcher appearance. Use the widget or shortcode to place it.', 'perflocale' ),
				'priority'    => 35,
			]
		);

		// Enable floating switcher.
		$wp_customize->add_setting(
			'perflocale_floating_switcher',
			[
				'default'           => false,
				'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_switcher',
			[
				'label'       => __( 'Show floating language switcher', 'perflocale' ),
				'description' => __( 'Displays a fixed language switcher in the corner of the page. Works with any theme.', 'perflocale' ),
				'section'     => 'perflocale_switcher',
				'type'        => 'checkbox',
			]
		);

		// Position.
		$wp_customize->add_setting(
			'perflocale_floating_position',
			[
				'default'           => 'bottom-right',
				'sanitize_callback' => [ $this, 'sanitize_position' ],
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_position',
			[
				'label'   => __( 'Position', 'perflocale' ),
				'section' => 'perflocale_switcher',
				'type'    => 'select',
				'choices' => [
					'bottom-right' => __( 'Bottom Right', 'perflocale' ),
					'bottom-left'  => __( 'Bottom Left', 'perflocale' ),
					'top-right'    => __( 'Top Right', 'perflocale' ),
					'top-left'     => __( 'Top Left', 'perflocale' ),
				],
			]
		);

		// Style.
		$wp_customize->add_setting(
			'perflocale_floating_style',
			[
				'default'           => 'flags_names',
				'sanitize_callback' => 'sanitize_key',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_style',
			[
				'label'   => __( 'Style', 'perflocale' ),
				'section' => 'perflocale_switcher',
				'type'    => 'select',
				'choices' => [
					'flags_names' => __( 'Flags + Names', 'perflocale' ),
					'names_only'  => __( 'Names Only', 'perflocale' ),
					'flags_only'  => __( 'Flags Only', 'perflocale' ),
				],
			]
		);

		// Display mode.
		$wp_customize->add_setting(
			'perflocale_floating_display',
			[
				'default'           => 'inline',
				'sanitize_callback' => 'sanitize_key',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_display',
			[
				'label'   => __( 'Display Mode', 'perflocale' ),
				'section' => 'perflocale_switcher',
				'type'    => 'select',
				'choices' => [
					'inline'   => __( 'Inline', 'perflocale' ),
					'simple'   => __( 'Simple', 'perflocale' ),
					'dropdown' => __( 'Dropdown', 'perflocale' ),
				],
			]
		);

		$wp_customize->add_setting(
			'perflocale_floating_arrow_style',
			[
				'default'           => 'single',
				'sanitize_callback' => 'sanitize_key',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_arrow_style',
			[
				'label'           => __( 'Dropdown Arrow', 'perflocale' ),
				'description'     => __( 'Icon shown next to the language label on the dropdown trigger. Themes can override with the perflocale/switcher/arrow_html filter.', 'perflocale' ),
				'section'         => 'perflocale_switcher',
				'type'            => 'select',
				'choices'         => [
					'single' => __( 'Single arrow (down chevron)', 'perflocale' ),
					'double' => __( 'Double arrow (up + down chevrons)', 'perflocale' ),
					'none'   => __( 'No arrow', 'perflocale' ),
				],
				'active_callback' => static function () {
					return get_theme_mod( 'perflocale_floating_display', 'inline' ) === 'dropdown';
				},
			]
		);

		$wp_customize->add_setting(
			'perflocale_floating_trigger_format',
			[
				'default'           => 'inherit',
				'sanitize_callback' => 'sanitize_key',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_trigger_format',
			[
				'label'           => __( 'Trigger Label Format', 'perflocale' ),
				'description'     => __( 'Label format on the dropdown trigger button, independent of the options list below. "Match Name Format" keeps trigger and options in sync.', 'perflocale' ),
				'section'         => 'perflocale_switcher',
				'type'            => 'select',
				'choices'         => [
					'inherit' => __( 'Match Name Format (default)', 'perflocale' ),
					'native'  => __( 'Native (Deutsch)', 'perflocale' ),
					'english' => __( 'English (German)', 'perflocale' ),
					'both'    => __( 'Both (Deutsch / German)', 'perflocale' ),
					'slug'    => __( 'Code only (DE)', 'perflocale' ),
				],
				'active_callback' => static function () {
					return get_theme_mod( 'perflocale_floating_display', 'inline' ) === 'dropdown';
				},
			]
		);

		// Layout.
		$wp_customize->add_setting(
			'perflocale_floating_layout',
			[
				'default'           => 'vertical',
				'sanitize_callback' => 'sanitize_key',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_layout',
			[
				'label'   => __( 'Layout', 'perflocale' ),
				'section' => 'perflocale_switcher',
				'type'    => 'select',
				'choices' => [
					'horizontal' => __( 'Horizontal', 'perflocale' ),
					'vertical'   => __( 'Vertical', 'perflocale' ),
				],
			]
		);

		// Name format.
		$wp_customize->add_setting(
			'perflocale_floating_name_format',
			[
				'default'           => 'native',
				'sanitize_callback' => 'sanitize_key',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_name_format',
			[
				'label'   => __( 'Name Format', 'perflocale' ),
				'section' => 'perflocale_switcher',
				'type'    => 'select',
				'choices' => [
					'native'  => __( 'Native (Français)', 'perflocale' ),
					'english' => __( 'English (French)', 'perflocale' ),
					'both'    => __( 'Both', 'perflocale' ),
					'slug'    => __( 'Code (FR)', 'perflocale' ),
				],
			]
		);

		// Hide current.
		$wp_customize->add_setting(
			'perflocale_floating_hide_current',
			[
				'default'           => false,
				'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_hide_current',
			[
				'label'   => __( 'Hide current language', 'perflocale' ),
				'section' => 'perflocale_switcher',
				'type'    => 'checkbox',
			]
		);

		// Show untranslated.
		$wp_customize->add_setting(
			'perflocale_floating_show_untranslated',
			[
				'default'           => false,
				'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_show_untranslated',
			[
				'label'   => __( 'Show untranslated languages', 'perflocale' ),
				'section' => 'perflocale_switcher',
				'type'    => 'checkbox',
			]
		);

		// Font size.
		$wp_customize->add_setting(
			'perflocale_floating_font_size',
			[
				'default'           => 14,
				'sanitize_callback' => 'absint',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_font_size',
			[
				'label'       => __( 'Font Size (px)', 'perflocale' ),
				'section'     => 'perflocale_switcher',
				'type'        => 'number',
				'input_attrs' => [
					'min'  => 10,
					'max'  => 24,
					'step' => 1,
				],
			]
		);

		// Flag size.
		$wp_customize->add_setting(
			'perflocale_floating_flag_size',
			[
				'default'           => 20,
				'sanitize_callback' => 'absint',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_flag_size',
			[
				'label'       => __( 'Flag Size (px)', 'perflocale' ),
				'section'     => 'perflocale_switcher',
				'type'        => 'number',
				'input_attrs' => [
					'min'  => 12,
					'max'  => 32,
					'step' => 1,
				],
			]
		);

		// Gap.
		$wp_customize->add_setting(
			'perflocale_floating_gap',
			[
				'default'           => 8,
				'sanitize_callback' => 'absint',
				'transport'         => 'refresh',
			]
		);

		$wp_customize->add_control(
			'perflocale_floating_gap',
			[
				'label'       => __( 'Spacing (px)', 'perflocale' ),
				'section'     => 'perflocale_switcher',
				'type'        => 'number',
				'input_attrs' => [
					'min'  => 0,
					'max'  => 24,
					'step' => 1,
				],
			]
		);
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

		$position_class = in_array( $position, [ 'bottom-left', 'top-right', 'top-left', 'bottom-right' ], true )
			? 'perflocale-floating-switcher--' . $position
			: 'perflocale-floating-switcher--bottom-right';

		$classes = 'perflocale-floating-switcher ' . $position_class;

		if ( is_admin_bar_showing() ) {
			$classes .= ' perflocale-floating-switcher--with-admin-bar';
		}

		echo '<div class="' . esc_attr( $classes ) . '">';
		echo LanguageSwitcherBlock::kses_switcher( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist.
		echo '</div>';
	}

	/**
	 * Sanitize checkbox value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function sanitize_checkbox( mixed $value ): bool {
		return (bool) $value;
	}

	/**
	 * Sanitize position value.
	 *
	 * Typed `mixed`, like sanitize_checkbox() above, because a customizer
	 * sanitize callback is handed the RAW value out of the `customized`
	 * JSON payload: WP_Customize_Manager::validate_setting_values() skips
	 * only null before calling WP_Customize_Setting::sanitize(), so an
	 * array (or any other JSON shape) reaches this filter unconverted and
	 * a declared `string $value` threw a TypeError at argument binding —
	 * a 500 out of the customizer instead of a rejected value. Anything
	 * that is not one of the four known positions falls back to the
	 * default, which is what an unrecognised string already did.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function sanitize_position( mixed $value ): string {
		$valid = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ];

		return is_string( $value ) && in_array( $value, $valid, true ) ? $value : 'bottom-right';
	}
}
