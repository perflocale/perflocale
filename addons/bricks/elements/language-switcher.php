<?php
/**
 * PerfLocale Language Switcher element for Bricks Builder.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language Switcher Bricks element.
 *
 * Renders the PerfLocale language switcher as a native Bricks
 * Builder element with all configuration options exposed.
 */
class PerfLocale_Bricks_Language_Switcher extends \Bricks\Element {

	/**
	 * Element category.
	 *
	 * @var string
	 */
	public $category = 'general';

	/**
	 * Element name (unique ID).
	 *
	 * @var string
	 */
	public $name = 'perflocale-language-switcher';

	/**
	 * Element icon.
	 *
	 * @var string
	 */
	public $icon = 'ti-world';

	/**
	 * Get element label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Language Switcher', 'perflocale' );
	}

	/**
	 * Set element controls.
	 *
	 * @return void
	 */
	public function set_controls(): void {
		$this->controls['style'] = [
			'tab'     => 'content',
			'label'   => __( 'Style', 'perflocale' ),
			'type'    => 'select',
			'options' => [
				'flags_names' => __( 'Flags + Names', 'perflocale' ),
				'flags_only'  => __( 'Flags Only', 'perflocale' ),
				'names_only'  => __( 'Names Only', 'perflocale' ),
			],
			'default' => 'flags_names',
		];

		$this->controls['display'] = [
			'tab'     => 'content',
			'label'   => __( 'Display', 'perflocale' ),
			'type'    => 'select',
			'options' => [
				'inline'   => __( 'Inline', 'perflocale' ),
				'simple'   => __( 'Simple', 'perflocale' ),
				'dropdown' => __( 'Dropdown', 'perflocale' ),
			],
			'default' => 'inline',
		];

		$this->controls['layout'] = [
			'tab'      => 'content',
			'label'    => __( 'Layout', 'perflocale' ),
			'type'     => 'select',
			'options'  => [
				'horizontal' => __( 'Horizontal', 'perflocale' ),
				'vertical'   => __( 'Vertical', 'perflocale' ),
			],
			'default'  => 'horizontal',
			'required' => [ 'display', '!=', 'dropdown' ],
		];

		$this->controls['name_format'] = [
			'tab'     => 'content',
			'label'   => __( 'Name Format', 'perflocale' ),
			'type'    => 'select',
			'options' => [
				'native'  => __( 'Native', 'perflocale' ),
				'english' => __( 'English', 'perflocale' ),
				'both'    => __( 'Both', 'perflocale' ),
				'slug'    => __( 'Language Code', 'perflocale' ),
			],
			'default' => 'native',
		];

		$this->controls['trigger_format'] = [
			'tab'      => 'content',
			'label'    => __( 'Trigger Label Format', 'perflocale' ),
			'type'     => 'select',
			'options'  => [
				''        => __( 'Use site default', 'perflocale' ),
				'inherit' => __( 'Match Name Format', 'perflocale' ),
				'native'  => __( 'Native', 'perflocale' ),
				'english' => __( 'English', 'perflocale' ),
				'both'    => __( 'Both', 'perflocale' ),
				'slug'    => __( 'Language Code', 'perflocale' ),
			],
			'default'  => '',
			'required' => [ 'display', '=', 'dropdown' ],
		];

		$this->controls['arrow_style'] = [
			'tab'      => 'content',
			'label'    => __( 'Dropdown Arrow', 'perflocale' ),
			'type'     => 'select',
			'options'  => [
				''       => __( 'Use site default', 'perflocale' ),
				'single' => __( 'Single chevron', 'perflocale' ),
				'double' => __( 'Double chevrons', 'perflocale' ),
				'none'   => __( 'No arrow', 'perflocale' ),
			],
			'default'  => '',
			'required' => [ 'display', '=', 'dropdown' ],
		];

		$this->controls['hide_current'] = [
			'tab'     => 'content',
			'label'   => __( 'Hide Current Language', 'perflocale' ),
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['show_untranslated'] = [
			'tab'     => 'content',
			'label'   => __( 'Show Untranslated Languages', 'perflocale' ),
			'type'    => 'checkbox',
			'default' => false,
		];

		$this->controls['font_size'] = [
			'tab'     => 'content',
			'label'   => __( 'Font Size (px)', 'perflocale' ),
			'type'    => 'number',
			'default' => 14,
			'min'     => 10,
			'max'     => 32,
		];

		$this->controls['flag_size'] = [
			'tab'     => 'content',
			'label'   => __( 'Flag Size (px)', 'perflocale' ),
			'type'    => 'number',
			'default' => 20,
			'min'     => 12,
			'max'     => 40,
		];

		$this->controls['gap'] = [
			'tab'     => 'content',
			'label'   => __( 'Gap (px)', 'perflocale' ),
			'type'    => 'number',
			'default' => 8,
			'min'     => 0,
			'max'     => 24,
		];

		$this->controls['custom_class'] = [
			'tab'         => 'content',
			'label'       => __( 'Custom CSS Class', 'perflocale' ),
			'type'        => 'text',
			'default'     => '',
			'placeholder' => __( 'e.g. my-switcher', 'perflocale' ),
		];
	}

	/**
	 * Render element output.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! class_exists( '\PerfLocale\Frontend\LanguageSwitcherBlock' ) ) {
			return;
		}

		$settings = $this->settings;
		$block    = new \PerfLocale\Frontend\LanguageSwitcherBlock();
		$style    = $settings['style'] ?? 'flags_names';

		$render_args = [
			'display'          => $settings['display'] ?? 'inline',
			'style'            => $style,
			'layout'           => $settings['layout'] ?? 'horizontal',
			'nameFormat'       => $settings['name_format'] ?? 'native',
			'showFlags'        => $style !== 'names_only',
			'showNames'        => $style !== 'flags_only',
			'hideCurrent'      => ! empty( $settings['hide_current'] ),
			'showUntranslated' => ! empty( $settings['show_untranslated'] ),
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

		// Bricks' render_attributes() is third-party output that also carries
		// builder-authored custom attributes, so escape it here rather than
		// trusting the theme. wp_kses_post() keeps the id / class / style /
		// data-* attributes Bricks needs for element styling and builder
		// selection, and drops event handlers.
		echo wp_kses_post( '<div ' . (string) $this->render_attributes( '_root' ) . '>' );
		echo \PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist.
		echo '</div>';
	}
}
