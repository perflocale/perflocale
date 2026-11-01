<?php
/**
 * Customizer section, settings and controls of the language switcher.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Language Switcher" Customizer section; CustomizerIntegration forwards here.
 */
final class CustomizerControls {

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
	 * Typed mixed: the Customizer passes the raw JSON value, and a string type would throw a TypeError.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function sanitize_position( mixed $value ): string {
		$valid = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ];

		return is_string( $value ) && in_array( $value, $valid, true ) ? $value : 'bottom-right';
	}
}
