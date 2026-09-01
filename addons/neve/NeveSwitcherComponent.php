<?php
/**
 * PerfLocale Language Switcher component for Neve's Header Footer Grid.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HFG Language Switcher component.
 *
 * Extends Neve's Abstract_Component to provide a draggable Language Switcher
 * element in the Customizer header builder with full display configuration.
 */
class PerfLocaleNeveSwitcherComponent extends \HFG\Core\Components\Abstract_Component {

	const COMPONENT_ID         = 'perflocale_switcher';
	const DISPLAY_ID           = 'display_mode';
	const STYLE_ID             = 'style';
	const LAYOUT_ID            = 'layout';
	const NAME_FMT_ID          = 'name_format';
	const HIDE_CUR_ID          = 'hide_current';
	const SHOW_UNTRANSLATED_ID = 'show_untranslated';
	const ARROW_STYLE_ID       = 'arrow_style';
	const TRIGGER_FORMAT_ID    = 'trigger_format';

	/**
	 * Initialize the component.
	 *
	 * @return void
	 */
	public function init() {
		$this->set_property( 'label', __( 'Language Switcher', 'perflocale' ) );
		$this->set_property( 'id', self::COMPONENT_ID );
		$this->set_property( 'component_slug', 'hfg-perflocale-switcher' );
		$this->set_property( 'width', 2 );
		$this->set_property( 'icon', 'translation' );
		$this->set_property( 'is_auto_width', true );
	}

	/**
	 * Register customizer settings for the component.
	 *
	 * @return void
	 */
	public function add_settings() {
		$defaults = $this->get_plugin_defaults();

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::DISPLAY_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_key',
				'default'           => $defaults['display'],
				'label'             => __( 'Display Mode', 'perflocale' ),
				'type'              => 'select',
				'section'           => $this->section,
				'options'           => [
					'choices' => [
						'dropdown' => __( 'Dropdown', 'perflocale' ),
						'inline'   => __( 'Inline List', 'perflocale' ),
						'simple'   => __( 'Simple List', 'perflocale' ),
					],
				],
			]
		);

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::ARROW_STYLE_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => static function ( $v ) {
					return in_array( $v, [ '', 'single', 'double', 'none' ], true ) ? $v : '';
				},
				'default'           => '',
				'label'             => __( 'Dropdown Arrow', 'perflocale' ),
				'description'       => __( 'Only applies when Display Mode is Dropdown.', 'perflocale' ),
				'type'              => 'select',
				'section'           => $this->section,
				'options'           => [
					'choices' => [
						''       => __( 'Use global setting', 'perflocale' ),
						'single' => __( 'Single arrow (down chevron)', 'perflocale' ),
						'double' => __( 'Double arrow (up + down chevrons)', 'perflocale' ),
						'none'   => __( 'No arrow', 'perflocale' ),
					],
				],
			]
		);

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::TRIGGER_FORMAT_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => static function ( $v ) {
					return in_array( $v, [ '', 'inherit', 'native', 'english', 'both', 'slug' ], true ) ? $v : '';
				},
				'default'           => '',
				'label'             => __( 'Trigger Label Format', 'perflocale' ),
				'description'       => __( 'Label format on the dropdown trigger button. Only applies when Display Mode is Dropdown.', 'perflocale' ),
				'type'              => 'select',
				'section'           => $this->section,
				'options'           => [
					'choices' => [
						''        => __( 'Use global setting', 'perflocale' ),
						'inherit' => __( 'Match Name Format', 'perflocale' ),
						'native'  => __( 'Native (Deutsch)', 'perflocale' ),
						'english' => __( 'English (German)', 'perflocale' ),
						'both'    => __( 'Both (Deutsch / German)', 'perflocale' ),
						'slug'    => __( 'Code only (DE)', 'perflocale' ),
					],
				],
			]
		);

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::STYLE_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_key',
				'default'           => $defaults['style'],
				'label'             => __( 'Style', 'perflocale' ),
				'type'              => 'select',
				'section'           => $this->section,
				'options'           => [
					'choices' => [
						'flags_names' => __( 'Flags + Names', 'perflocale' ),
						'flags_only'  => __( 'Flags Only', 'perflocale' ),
						'names_only'  => __( 'Names Only', 'perflocale' ),
					],
				],
			]
		);

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::LAYOUT_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_key',
				'default'           => $defaults['layout'],
				'label'             => __( 'Layout', 'perflocale' ),
				'type'              => 'select',
				'section'           => $this->section,
				'options'           => [
					'choices' => [
						'horizontal' => __( 'Horizontal', 'perflocale' ),
						'vertical'   => __( 'Vertical', 'perflocale' ),
					],
				],
			]
		);

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::NAME_FMT_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_key',
				'default'           => $defaults['name_format'],
				'label'             => __( 'Name Format', 'perflocale' ),
				'type'              => 'select',
				'section'           => $this->section,
				'options'           => [
					'choices' => [
						'native'  => __( 'Native (e.g. Deutsch)', 'perflocale' ),
						'english' => __( 'English (e.g. German)', 'perflocale' ),
						'both'    => __( 'Both (Deutsch / German)', 'perflocale' ),
						'slug'    => __( 'Code (e.g. DE)', 'perflocale' ),
					],
				],
			]
		);

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::HIDE_CUR_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => 'neve_sanitize_checkbox',
				'default'           => $defaults['hide_current'] ? 'on' : '',
				'label'             => __( 'Hide Current Language', 'perflocale' ),
				'type'              => 'neve_toggle_control',
				'section'           => $this->section,
			]
		);

		\HFG\Core\Settings\Manager::get_instance()->add(
			[
				'id'                => self::SHOW_UNTRANSLATED_ID,
				'group'             => self::COMPONENT_ID,
				'tab'               => \HFG\Core\Settings\Manager::TAB_GENERAL,
				'transport'         => 'refresh',
				'sanitize_callback' => 'neve_sanitize_checkbox',
				'default'           => $defaults['show_untranslated'] ? 'on' : '',
				'label'             => __( 'Show Untranslated Languages', 'perflocale' ),
				'type'              => 'neve_toggle_control',
				'section'           => $this->section,
			]
		);
	}

	/**
	 * Get component settings for the builder UI.
	 *
	 * Sets fromTheme=true so the component appears in the free components list.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings() {
		$settings              = parent::get_settings();
		$settings['fromTheme'] = true;

		return $settings;
	}

	/**
	 * Render the component on the frontend.
	 *
	 * @return void
	 */
	public function render_component() {
		// Fall back to the plugin's global switcher settings — the same values
		// add_settings() registers as control defaults — so an unsaved control
		// renders what the customizer form displays. HFG's Manager::get
		// short-circuits to any non-null default passed in and never consults
		// the registered default, so the fallbacks here must match.
		$defaults = $this->get_plugin_defaults();

		$display           = $this->get_setting_value( self::DISPLAY_ID, $defaults['display'] );
		$style             = $this->get_setting_value( self::STYLE_ID, $defaults['style'] );
		$layout            = $this->get_setting_value( self::LAYOUT_ID, $defaults['layout'] );
		$name_format       = $this->get_setting_value( self::NAME_FMT_ID, $defaults['name_format'] );
		$hide_cur          = $this->get_toggle_value( self::HIDE_CUR_ID, (bool) $defaults['hide_current'] );
		$show_untranslated = $this->get_toggle_value( self::SHOW_UNTRANSLATED_ID, (bool) $defaults['show_untranslated'] );
		$arrow_style       = (string) $this->get_setting_value( self::ARROW_STYLE_ID, '' );
		$trigger_format    = (string) $this->get_setting_value( self::TRIGGER_FORMAT_ID, '' );

		$block = new \PerfLocale\Frontend\LanguageSwitcherBlock();

		$render_args = [
			'display'          => $display,
			'style'            => $style,
			'layout'           => $layout,
			'nameFormat'       => $name_format,
			'showFlags'        => $style !== 'names_only',
			'showNames'        => $style !== 'flags_only',
			'hideCurrent'      => $hide_cur,
			'showUntranslated' => $show_untranslated,
			'fontSize'         => 13,
			'flagSize'         => 16,
			'gap'              => 8,
		];

		if ( $arrow_style !== '' ) {
			$render_args['arrowStyle'] = $arrow_style;
		}

		if ( $trigger_format !== '' ) {
			$render_args['triggerFormat'] = $trigger_format;
		}

		$html = $block->render( $render_args );

		if ( ! empty( $html ) ) {
			echo \PerfLocale\Frontend\LanguageSwitcherBlock::kses_switcher( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist.
		}
	}

	/**
	 * Get a component setting value.
	 *
	 * @param string $id      Setting ID.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private function get_setting_value( string $id, $default = '' ) {
		$value = \HFG\Core\Settings\Manager::get_instance()->get( $this->get_id() . '_' . $id, $default );

		return ! empty( $value ) ? $value : $default;
	}

	/**
	 * Resolve a checkbox/toggle setting to a boolean.
	 *
	 * A toggle saved OFF is stored as '' (empty), which get_setting_value()'s
	 * `! empty()` coercion cannot distinguish from an unsaved control — so once
	 * the plugin default is truthy, an explicit OFF would be silently overridden
	 * back ON and the customizer control (bound to the raw mod) would disagree
	 * with the frontend. Read the raw mod against a sentinel instead: a value
	 * that was actually saved is authoritative even when '', and the plugin
	 * default applies only when the control was never touched.
	 *
	 * @param string $id         Setting ID.
	 * @param bool   $default_on Plugin-derived default when the control is unsaved.
	 * @return bool
	 */
	private function get_toggle_value( string $id, bool $default_on ): bool {
		$sentinel = "\0pfl_unset";
		$raw      = \HFG\Core\Settings\Manager::get_instance()->get( $this->get_id() . '_' . $id, $sentinel );

		if ( $raw === $sentinel ) {
			return $default_on;
		}

		return ! empty( $raw );
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
			// Plugin not fully loaded.
		}

		return $defaults;
	}
}
