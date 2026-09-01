<?php
/**
 * PerfLocale Kadence theme addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kadence theme integration for PerfLocale.
 *
 * Registers a Language Switcher element in Kadence's header/footer builder
 * with full configurable settings in the Customizer, reading defaults
 * from the plugin's global Language Switcher settings.
 */
final class PerfLocaleKadence implements \PerfLocale\Addon\AddonInterface {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'kadence';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Kadence';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		return 'kadence' === get_template();
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		add_filter( 'kadence_theme_customizer_control_choices', [ $this, 'add_builder_choices' ] );
		add_action( 'customize_register', [ $this, 'register_customizer_section' ], 20 );
		add_filter( 'kadence_header_elements_template_path', [ $this, 'filter_template_path' ], 10, 4 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Get plugin defaults for the language switcher.
	 *
	 * @return array<string, mixed>
	 */
	private function get_plugin_defaults(): array {
		$defaults = [
			'display'           => 'dropdown',
			'style'             => 'flags_names',
			'layout'            => 'horizontal',
			'name_format'       => 'native',
			'hide_current'      => false,
			'show_untranslated' => false,
			'font_size'         => 14,
			'flag_size'         => 20,
			'gap'               => 8,
		];

		try {
			$settings                      = \PerfLocale\Plugin::get_instance()->get( 'settings' );
			$defaults['display']           = $settings->get( 'switcher_display', 'dropdown' );
			$defaults['style']             = $settings->get( 'switcher_template', 'flags_names' );
			$defaults['layout']            = $settings->get( 'switcher_layout', 'horizontal' );
			$defaults['name_format']       = $settings->get( 'switcher_name_format', 'native' );
			$defaults['hide_current']      = (bool) $settings->get( 'switcher_hide_current', false );
			$defaults['show_untranslated'] = (bool) $settings->get( 'switcher_show_untranslated', false );
		} catch ( \Throwable $e ) {
			// Defaults already set above.
		}

		return $defaults;
	}

	/**
	 * Add Language Switcher to Kadence's header builder available items.
	 *
	 * @param array<string, array<string, array<string, string>>> $choices Builder choices.
	 * @return array<string, array<string, array<string, string>>>
	 */
	public function add_builder_choices( array $choices ): array {
		$switcher = [
			'name'    => esc_html__( 'Language Switcher', 'perflocale' ),
			'section' => 'kadence_customizer_header_language_switcher',
		];

		if ( isset( $choices['header_desktop_items'] ) && is_array( $choices['header_desktop_items'] ) ) {
			$choices['header_desktop_items']['language-switcher'] = $switcher;
		}

		if ( isset( $choices['header_mobile_items'] ) && is_array( $choices['header_mobile_items'] ) ) {
			$choices['header_mobile_items']['language-switcher'] = $switcher;
		}

		return $choices;
	}

	/**
	 * Register customizer section and controls for the Language Switcher element.
	 *
	 * @param \WP_Customize_Manager $wp_customize Customizer instance.
	 * @return void
	 */
	public function register_customizer_section( \WP_Customize_Manager $wp_customize ): void {
		$defaults = $this->get_plugin_defaults();

		$wp_customize->add_section(
			'kadence_customizer_header_language_switcher',
			[
				'title'    => __( 'Language Switcher', 'perflocale' ),
				'priority' => 90,
				'panel'    => 'kadence_customizer_header',
			]
		);

		// Display mode.
		$wp_customize->add_setting(
			'perflocale_kadence_display',
			[
				'default'           => $defaults['display'],
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, [ 'dropdown', 'inline', 'simple' ], true ) ? $v : 'dropdown';
				},
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_display',
			[
				'section' => 'kadence_customizer_header_language_switcher',
				'label'   => __( 'Display Mode', 'perflocale' ),
				'type'    => 'select',
				'choices' => [
					'dropdown' => __( 'Dropdown', 'perflocale' ),
					'inline'   => __( 'Inline List', 'perflocale' ),
					'simple'   => __( 'Simple List', 'perflocale' ),
				],
			]
		);

		$wp_customize->add_setting(
			'perflocale_kadence_arrow_style',
			[
				'default'           => '',
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, [ '', 'single', 'double', 'none' ], true ) ? $v : '';
				},
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_arrow_style',
			[
				'section'         => 'kadence_customizer_header_language_switcher',
				'label'           => __( 'Dropdown Arrow', 'perflocale' ),
				'description'     => __( 'Icon shown next to the language label on the dropdown trigger.', 'perflocale' ),
				'type'            => 'select',
				'choices'         => [
					''       => __( 'Use global setting', 'perflocale' ),
					'single' => __( 'Single arrow (down chevron)', 'perflocale' ),
					'double' => __( 'Double arrow (up + down chevrons)', 'perflocale' ),
					'none'   => __( 'No arrow', 'perflocale' ),
				],
				'active_callback' => static function () {
					return get_theme_mod( 'perflocale_kadence_display', 'dropdown' ) === 'dropdown';
				},
			]
		);

		$wp_customize->add_setting(
			'perflocale_kadence_trigger_format',
			[
				'default'           => '',
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, [ '', 'inherit', 'native', 'english', 'both', 'slug' ], true ) ? $v : '';
				},
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_trigger_format',
			[
				'section'         => 'kadence_customizer_header_language_switcher',
				'label'           => __( 'Trigger Label Format', 'perflocale' ),
				'description'     => __( 'Label format on the dropdown trigger button, independent of the options list.', 'perflocale' ),
				'type'            => 'select',
				'choices'         => [
					''        => __( 'Use global setting', 'perflocale' ),
					'inherit' => __( 'Match Name Format', 'perflocale' ),
					'native'  => __( 'Native (Deutsch)', 'perflocale' ),
					'english' => __( 'English (German)', 'perflocale' ),
					'both'    => __( 'Both (Deutsch / German)', 'perflocale' ),
					'slug'    => __( 'Code only (DE)', 'perflocale' ),
				],
				'active_callback' => static function () {
					return get_theme_mod( 'perflocale_kadence_display', 'dropdown' ) === 'dropdown';
				},
			]
		);

		// Style.
		$wp_customize->add_setting(
			'perflocale_kadence_style',
			[
				'default'           => $defaults['style'],
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, [ 'flags_names', 'flags_only', 'names_only' ], true ) ? $v : 'flags_names';
				},
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_style',
			[
				'section' => 'kadence_customizer_header_language_switcher',
				'label'   => __( 'Style', 'perflocale' ),
				'type'    => 'select',
				'choices' => [
					'flags_names' => __( 'Flags + Names', 'perflocale' ),
					'flags_only'  => __( 'Flags Only', 'perflocale' ),
					'names_only'  => __( 'Names Only', 'perflocale' ),
				],
			]
		);

		// Layout.
		$wp_customize->add_setting(
			'perflocale_kadence_layout',
			[
				'default'           => $defaults['layout'],
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, [ 'horizontal', 'vertical' ], true ) ? $v : 'horizontal';
				},
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_layout',
			[
				'section' => 'kadence_customizer_header_language_switcher',
				'label'   => __( 'Layout', 'perflocale' ),
				'type'    => 'select',
				'choices' => [
					'horizontal' => __( 'Horizontal', 'perflocale' ),
					'vertical'   => __( 'Vertical', 'perflocale' ),
				],
			]
		);

		// Name format.
		$wp_customize->add_setting(
			'perflocale_kadence_name_format',
			[
				'default'           => $defaults['name_format'],
				'sanitize_callback' => function ( $v ) {
					return in_array( $v, [ 'native', 'english', 'both', 'slug' ], true ) ? $v : 'native';
				},
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_name_format',
			[
				'section' => 'kadence_customizer_header_language_switcher',
				'label'   => __( 'Name Format', 'perflocale' ),
				'type'    => 'select',
				'choices' => [
					'native'  => __( 'Native (e.g. Deutsch)', 'perflocale' ),
					'english' => __( 'English (e.g. German)', 'perflocale' ),
					'both'    => __( 'Both (Deutsch / German)', 'perflocale' ),
					'slug'    => __( 'Code (e.g. DE)', 'perflocale' ),
				],
			]
		);

		// Hide current language.
		$wp_customize->add_setting(
			'perflocale_kadence_hide_current',
			[
				'default'           => $defaults['hide_current'],
				'sanitize_callback' => function ( $v ) {
					return (bool) $v; },
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_hide_current',
			[
				'section' => 'kadence_customizer_header_language_switcher',
				'label'   => __( 'Hide Current Language', 'perflocale' ),
				'type'    => 'checkbox',
			]
		);

		// Show untranslated.
		$wp_customize->add_setting(
			'perflocale_kadence_show_untranslated',
			[
				'default'           => $defaults['show_untranslated'],
				'sanitize_callback' => function ( $v ) {
					return (bool) $v; },
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_show_untranslated',
			[
				'section' => 'kadence_customizer_header_language_switcher',
				'label'   => __( 'Show Untranslated Languages', 'perflocale' ),
				'type'    => 'checkbox',
			]
		);

		// Font size.
		$wp_customize->add_setting(
			'perflocale_kadence_font_size',
			[
				'default'           => $defaults['font_size'],
				'sanitize_callback' => 'absint',
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_font_size',
			[
				'section'     => 'kadence_customizer_header_language_switcher',
				'label'       => __( 'Font Size (px)', 'perflocale' ),
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
			'perflocale_kadence_flag_size',
			[
				'default'           => $defaults['flag_size'],
				'sanitize_callback' => 'absint',
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_flag_size',
			[
				'section'     => 'kadence_customizer_header_language_switcher',
				'label'       => __( 'Flag Size (px)', 'perflocale' ),
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
			'perflocale_kadence_gap',
			[
				'default'           => $defaults['gap'],
				'sanitize_callback' => 'absint',
				'transport'         => 'refresh',
			]
		);
		$wp_customize->add_control(
			'perflocale_kadence_gap',
			[
				'section'     => 'kadence_customizer_header_language_switcher',
				'label'       => __( 'Spacing (px)', 'perflocale' ),
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
	 * Render the Language Switcher when Kadence loads the template part.
	 *
	 * @param string $template Template path.
	 * @param string $item Element slug.
	 * @param string $row Header row.
	 * @param string $column Column position.
	 * @return string
	 */
	public function filter_template_path( string $template, string $item, string $row, string $column ): string {
		if ( $item !== 'language-switcher' ) {
			return $template;
		}

		$defaults = $this->get_plugin_defaults();

		$display           = get_theme_mod( 'perflocale_kadence_display', $defaults['display'] );
		$style             = get_theme_mod( 'perflocale_kadence_style', $defaults['style'] );
		$layout            = get_theme_mod( 'perflocale_kadence_layout', $defaults['layout'] );
		$name_format       = get_theme_mod( 'perflocale_kadence_name_format', $defaults['name_format'] );
		$hide_current      = (bool) get_theme_mod( 'perflocale_kadence_hide_current', $defaults['hide_current'] );
		$show_untranslated = (bool) get_theme_mod( 'perflocale_kadence_show_untranslated', $defaults['show_untranslated'] );
		$font_size         = absint( get_theme_mod( 'perflocale_kadence_font_size', $defaults['font_size'] ) );
		$flag_size         = absint( get_theme_mod( 'perflocale_kadence_flag_size', $defaults['flag_size'] ) );
		$gap               = absint( get_theme_mod( 'perflocale_kadence_gap', $defaults['gap'] ) );
		$arrow_style       = (string) get_theme_mod( 'perflocale_kadence_arrow_style', '' );
		$trigger_format    = (string) get_theme_mod( 'perflocale_kadence_trigger_format', '' );

		$block = new \PerfLocale\Frontend\LanguageSwitcherBlock();

		$render_args = [
			'display'          => $display,
			'style'            => $style,
			'layout'           => $layout,
			'nameFormat'       => $name_format,
			'showFlags'        => $style !== 'names_only',
			'showNames'        => $style !== 'flags_only',
			'hideCurrent'      => $hide_current,
			'showUntranslated' => $show_untranslated,
			'fontSize'         => $font_size,
			'flagSize'         => $flag_size,
			'gap'              => $gap,
		];

		if ( $arrow_style !== '' ) {
			$render_args['arrowStyle'] = $arrow_style;
		}

		if ( $trigger_format !== '' ) {
			$render_args['triggerFormat'] = $trigger_format;
		}

		$html = $block->render( $render_args );

		if ( ! empty( $html ) ) {
			// kses_switcher() returns wp_kses()-sanitized switcher markup;
			// printf %3$s is the already-sanitized output.
			printf(
				'<div class="%1$s" data-section="%2$s">%3$s</div>',
				esc_attr( 'site-header-item site-header-focus-item site-header-item-language-switcher' ),
				esc_attr( 'kadence_customizer_header_language_switcher' ),
				\PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher( $html ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		return 'perflocale-null-template';
	}
}
