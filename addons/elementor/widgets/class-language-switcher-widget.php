<?php
/**
 * Elementor Language Switcher Widget.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PerfLocale Language Switcher widget for Elementor.
 *
 * Renders the language switcher using PerfLocale's shared block renderer
 * with configurable style, display mode, and layout options.
 */
class PerfLocale_Elementor_Language_Switcher extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'perflocale_language_switcher';
	}

	/**
	 * Get widget title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'Language Switcher', 'perflocale' );
	}

	/**
	 * Get widget icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-globe';
	}

	/**
	 * Get widget categories.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return [ 'general' ];
	}

	/**
	 * Get widget keywords.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords(): array {
		return [ 'language', 'switcher', 'translation', 'perflocale', 'multilingual' ];
	}

	/**
	 * Register widget controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'content_section',
			[
				'label' => __( 'Language Switcher', 'perflocale' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'style',
			[
				'label'   => __( 'Style', 'perflocale' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'flags_names',
				'options' => [
					'flags_names' => __( 'Flags + Names', 'perflocale' ),
					'flags_only'  => __( 'Flags Only', 'perflocale' ),
					'names_only'  => __( 'Names Only', 'perflocale' ),
				],
			]
		);

		$this->add_control(
			'display',
			[
				'label'   => __( 'Display Mode', 'perflocale' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inline',
				'options' => [
					'inline'   => __( 'Inline', 'perflocale' ),
					'simple'   => __( 'Simple', 'perflocale' ),
					'dropdown' => __( 'Dropdown', 'perflocale' ),
				],
			]
		);

		$this->add_control(
			'layout',
			[
				'label'     => __( 'Layout', 'perflocale' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'horizontal',
				'options'   => [
					'horizontal' => __( 'Horizontal', 'perflocale' ),
					'vertical'   => __( 'Vertical', 'perflocale' ),
				],
				'condition' => [
					'display!' => 'dropdown',
				],
			]
		);

		$this->add_control(
			'name_format',
			[
				'label'   => __( 'Name Format', 'perflocale' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'native',
				'options' => [
					'native'  => __( 'Native name', 'perflocale' ),
					'english' => __( 'English name', 'perflocale' ),
					'both'    => __( 'Both', 'perflocale' ),
					'slug'    => __( 'Code (EN)', 'perflocale' ),
				],
			]
		);

		$this->add_control(
			'trigger_format',
			[
				'label'     => __( 'Trigger Label Format', 'perflocale' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''        => __( 'Use site default', 'perflocale' ),
					'inherit' => __( 'Match Name Format', 'perflocale' ),
					'native'  => __( 'Native (Français)', 'perflocale' ),
					'english' => __( 'English (French)', 'perflocale' ),
					'both'    => __( 'Both', 'perflocale' ),
					'slug'    => __( 'Code (FR)', 'perflocale' ),
				],
				'condition' => [
					'display' => 'dropdown',
				],
			]
		);

		$this->add_control(
			'arrow_style',
			[
				'label'     => __( 'Dropdown Arrow', 'perflocale' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => '',
				'options'   => [
					''       => __( 'Use site default', 'perflocale' ),
					'single' => __( 'Single chevron', 'perflocale' ),
					'double' => __( 'Double chevrons', 'perflocale' ),
					'none'   => __( 'No arrow', 'perflocale' ),
				],
				'condition' => [
					'display' => 'dropdown',
				],
			]
		);

		$this->add_control(
			'hide_current',
			[
				'label'        => __( 'Hide Current Language', 'perflocale' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$this->add_control(
			'show_untranslated',
			[
				'label'        => __( 'Show Untranslated', 'perflocale' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			]
		);

		$this->add_control(
			'font_size',
			[
				'label'   => __( 'Font Size', 'perflocale' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 14,
				'min'     => 10,
				'max'     => 32,
			]
		);

		$this->add_control(
			'flag_size',
			[
				'label'   => __( 'Flag Size', 'perflocale' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 20,
				'min'     => 12,
				'max'     => 40,
			]
		);

		$this->add_control(
			'gap',
			[
				'label'   => __( 'Gap', 'perflocale' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 8,
				'min'     => 0,
				'max'     => 24,
			]
		);

		$this->add_control(
			'custom_class',
			[
				'label'       => __( 'Custom CSS Class', 'perflocale' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'e.g. my-switcher', 'perflocale' ),
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget output.
	 *
	 * @return void
	 */
	protected function render(): void {
		if ( ! class_exists( '\PerfLocale\Frontend\LanguageSwitcherBlock' ) ) {
			return;
		}

		$settings = $this->get_settings_for_display();
		$block    = new \PerfLocale\Frontend\LanguageSwitcherBlock();

		$style = $settings['style'] ?? 'flags_names';

		$render_args = [
			'display'          => $settings['display'] ?? 'inline',
			'style'            => $style,
			'layout'           => $settings['layout'] ?? 'horizontal',
			'nameFormat'       => $settings['name_format'] ?? 'native',
			'showFlags'        => $style !== 'names_only',
			'showNames'        => $style !== 'flags_only',
			'hideCurrent'      => ( $settings['hide_current'] ?? '' ) === 'yes',
			'showUntranslated' => ( $settings['show_untranslated'] ?? '' ) === 'yes',
			'fontSize'         => (int) ( $settings['font_size'] ?? 14 ),
			'flagSize'         => (int) ( $settings['flag_size'] ?? 20 ),
			'gap'              => (int) ( $settings['gap'] ?? 8 ),
			'className'        => sanitize_html_class( $settings['custom_class'] ?? '' ),
		];

		$trigger_format = sanitize_key( $settings['trigger_format'] ?? '' );
		if ( $trigger_format !== '' ) {
			$render_args['triggerFormat'] = $trigger_format;
		}

		$arrow_style = sanitize_key( $settings['arrow_style'] ?? '' );
		if ( $arrow_style !== '' ) {
			$render_args['arrowStyle'] = $arrow_style;
		}

		$html = $block->render( $render_args );

		echo \PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist.
	}
}
