<?php
/**
 * Settings form and save of the language switcher widget.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The widget's form() and update(), loaded only on the Widgets screen, in the Customizer and on a widget save.
 */
final class LanguageSwitcherWidgetForm {

	private const DEFAULTS = LanguageSwitcherWidget::DEFAULTS;

	/**
	 * The widget whose field ids and names the form prints.
	 *
	 * @var \WP_Widget
	 */
	private readonly \WP_Widget $widget;

	/**
	 * Constructor.
	 *
	 * @param \WP_Widget $widget Widget instance.
	 */
	public function __construct( \WP_Widget $widget ) {
		$this->widget = $widget;
	}

	/**
	 * The widget's field id.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function get_field_id( string $key ): string {
		return $this->widget->get_field_id( $key );
	}

	/**
	 * The widget's field name.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function get_field_name( string $key ): string {
		return $this->widget->get_field_name( $key );
	}

	/**
	 * Widget settings form in the Customizer / Widgets screen.
	 *
	 * @param array<string, mixed> $instance Current settings.
	 * @return void
	 */
	public function form( $instance ): void {
		$instance = wp_parse_args( $instance, self::DEFAULTS );

		$this->render_text_field( 'title', __( 'Title', 'perflocale' ), $instance['title'] );

		$this->render_select_field(
			'display',
			__( 'Display Mode', 'perflocale' ),
			$instance['display'] ?? 'dropdown',
			[
				'inline'   => __( 'Inline', 'perflocale' ),
				'simple'   => __( 'Simple', 'perflocale' ),
				'dropdown' => __( 'Dropdown', 'perflocale' ),
			]
		);

		$this->render_select_field(
			'style',
			__( 'Style', 'perflocale' ),
			$instance['style'],
			[
				'flags_names' => __( 'Flags + Names', 'perflocale' ),
				'names_only'  => __( 'Names Only', 'perflocale' ),
				'flags_only'  => __( 'Flags Only', 'perflocale' ),
			]
		);

		$this->render_select_field(
			'layout',
			__( 'Layout', 'perflocale' ),
			$instance['layout'],
			[
				'horizontal' => __( 'Horizontal', 'perflocale' ),
				'vertical'   => __( 'Vertical', 'perflocale' ),
			]
		);

		$this->render_select_field(
			'nameFormat',
			__( 'Name Format', 'perflocale' ),
			$instance['nameFormat'],
			[
				'native'  => __( 'Native (Français)', 'perflocale' ),
				'english' => __( 'English (French)', 'perflocale' ),
				'both'    => __( 'Both - English (Native)', 'perflocale' ),
				'slug'    => __( 'Code (FR)', 'perflocale' ),
			]
		);

		$this->render_select_field(
			'triggerFormat',
			__( 'Trigger Label Format', 'perflocale' ),
			$instance['triggerFormat'] ?? '',
			[
				''        => __( 'Use site default (Settings → Switcher)', 'perflocale' ),
				'inherit' => __( 'Match Name Format', 'perflocale' ),
				'native'  => __( 'Native (Français)', 'perflocale' ),
				'english' => __( 'English (French)', 'perflocale' ),
				'both'    => __( 'Both - English (Native)', 'perflocale' ),
				'slug'    => __( 'Code (FR)', 'perflocale' ),
			]
		);

		$this->render_select_field(
			'arrowStyle',
			__( 'Dropdown Arrow', 'perflocale' ),
			$instance['arrowStyle'] ?? '',
			[
				''       => __( 'Use site default (Settings → Switcher)', 'perflocale' ),
				'single' => __( 'Single chevron', 'perflocale' ),
				'double' => __( 'Double chevrons', 'perflocale' ),
				'none'   => __( 'No arrow', 'perflocale' ),
			]
		);

		$this->render_checkbox_field( 'showFlags', __( 'Show flags', 'perflocale' ), $instance['showFlags'] );
		$this->render_checkbox_field( 'showNames', __( 'Show names', 'perflocale' ), $instance['showNames'] );
		$this->render_checkbox_field( 'hideCurrent', __( 'Hide current language', 'perflocale' ), $instance['hideCurrent'] );
		$this->render_checkbox_field( 'showUntranslated', __( 'Show untranslated', 'perflocale' ), $instance['showUntranslated'] );

		$this->render_number_field( 'fontSize', __( 'Font size (px)', 'perflocale' ), (int) $instance['fontSize'], 10, 24 );
		$this->render_number_field( 'flagSize', __( 'Flag size (px)', 'perflocale' ), (int) $instance['flagSize'], 14, 40 );
		$this->render_number_field( 'gap', __( 'Gap (px)', 'perflocale' ), (int) $instance['gap'], 0, 24 );
	}

	/**
	 * Sanitize and save widget settings.
	 *
	 * @param array<string, mixed> $new New settings.
	 * @param array<string, mixed> $old Old settings.
	 * @return array<string, mixed>
	 */
	public function update( $new, $old ): array {
		$trigger_format_in = sanitize_key( $new['triggerFormat'] ?? '' );
		$trigger_format    = in_array( $trigger_format_in, [ '', 'inherit', 'native', 'english', 'both', 'slug' ], true )
			? $trigger_format_in
			: '';

		$arrow_style_in = sanitize_key( $new['arrowStyle'] ?? '' );
		$arrow_style    = in_array( $arrow_style_in, [ '', 'single', 'double', 'none' ], true )
			? $arrow_style_in
			: '';

		return [
			'title'            => sanitize_text_field( $new['title'] ?? '' ),
			'display'          => sanitize_key( $new['display'] ?? 'dropdown' ),
			'style'            => sanitize_key( $new['style'] ?? 'flags_names' ),
			'layout'           => sanitize_key( $new['layout'] ?? 'horizontal' ),
			'nameFormat'       => sanitize_key( $new['nameFormat'] ?? 'native' ),
			'triggerFormat'    => $trigger_format,
			'arrowStyle'       => $arrow_style,
			'showFlags'        => ! empty( $new['showFlags'] ),
			'showNames'        => ! empty( $new['showNames'] ),
			'hideCurrent'      => ! empty( $new['hideCurrent'] ),
			'showUntranslated' => ! empty( $new['showUntranslated'] ),
			'fontSize'         => max( 10, min( 24, absint( $new['fontSize'] ?? 14 ) ) ),
			'flagSize'         => max( 14, min( 40, absint( $new['flagSize'] ?? 20 ) ) ),
			'gap'              => max( 0, min( 24, absint( $new['gap'] ?? 8 ) ) ),
		];
	}

	/**
	 * Render a text input field.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param string $value Current value.
	 * @return void
	 */
	private function render_text_field( string $key, string $label, string $value ): void {
		echo '<p>';
		echo '<label for="' . esc_attr( $this->get_field_id( $key ) ) . '">' . esc_html( $label ) . '</label>';
		echo '<input class="widefat" id="' . esc_attr( $this->get_field_id( $key ) ) . '" name="' . esc_attr( $this->get_field_name( $key ) ) . '" type="text" value="' . esc_attr( $value ) . '">';
		echo '</p>';
	}

	/**
	 * Render a select field.
	 *
	 * @param string               $key Field key.
	 * @param string               $label Field label.
	 * @param string               $value Current value.
	 * @param array<string,string> $options Options.
	 * @return void
	 */
	private function render_select_field( string $key, string $label, string $value, array $options ): void {
		echo '<p>';
		echo '<label for="' . esc_attr( $this->get_field_id( $key ) ) . '">' . esc_html( $label ) . '</label>';
		echo '<select class="widefat" id="' . esc_attr( $this->get_field_id( $key ) ) . '" name="' . esc_attr( $this->get_field_name( $key ) ) . '">';

		foreach ( $options as $opt_value => $opt_label ) {
			echo '<option value="' . esc_attr( $opt_value ) . '"' . selected( $value, $opt_value, false ) . '>' . esc_html( $opt_label ) . '</option>';
		}

		echo '</select>';
		echo '</p>';
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param bool   $value Current value.
	 * @return void
	 */
	private function render_checkbox_field( string $key, string $label, bool $value ): void {
		echo '<p>';
		echo '<input type="checkbox" id="' . esc_attr( $this->get_field_id( $key ) ) . '" name="' . esc_attr( $this->get_field_name( $key ) ) . '" value="1"' . checked( $value, true, false ) . '>';
		echo ' <label for="' . esc_attr( $this->get_field_id( $key ) ) . '">' . esc_html( $label ) . '</label>';
		echo '</p>';
	}

	/**
	 * Render a number input field.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param int    $value Current value.
	 * @param int    $min Minimum value.
	 * @param int    $max Maximum value.
	 * @return void
	 */
	private function render_number_field( string $key, string $label, int $value, int $min, int $max ): void {
		echo '<p>';
		echo '<label for="' . esc_attr( $this->get_field_id( $key ) ) . '">' . esc_html( $label ) . '</label>';
		echo '<input class="tiny-text" id="' . esc_attr( $this->get_field_id( $key ) ) . '" name="' . esc_attr( $this->get_field_name( $key ) ) . '" type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $value ) . '" style="width: 60px; margin-left: 4px;">';
		echo '</p>';
	}
}
