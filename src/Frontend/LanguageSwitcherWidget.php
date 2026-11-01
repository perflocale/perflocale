<?php
/**
 * Language switcher widget - full settings matching the Gutenberg block.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress widget for the language switcher.
 *
 * Delegates rendering to LanguageSwitcherBlock so widget output matches the block.
 */
class LanguageSwitcherWidget extends \WP_Widget {

	/**
	 * Default settings matching the Gutenberg block attributes (LanguageSwitcherWidgetForm reads them too).
	 */
	public const DEFAULTS = [
		'title'            => '',
		'display'          => 'dropdown',
		'style'            => 'flags_names',
		'layout'           => 'horizontal',
		'nameFormat'       => 'native',
		'triggerFormat'    => '',
		'arrowStyle'       => '',
		'showFlags'        => true,
		'showNames'        => true,
		'hideCurrent'      => false,
		'showUntranslated' => false,
		'fontSize'         => 14,
		'flagSize'         => 20,
		'gap'              => 8,
	];

	/**
	 * Constructor: labels are translated only where they can show, else on first render.
	 *
	 * See docs-ai/11-code-rationale.md, "LanguageSwitcherWidget: lazy labels".
	 */
	public function __construct() {
		$labels = \PerfLocale\Helper::shows_registration_labels() || is_customize_preview();

		parent::__construct(
			'perflocale_switcher',
			$labels ? __( 'Language Switcher', 'perflocale' ) : 'Language Switcher',
			[
				'description'                 => $labels
					? __( 'Display a language switcher with flags, names, or dropdown.', 'perflocale' )
					: 'Display a language switcher with flags, names, or dropdown.',
				'customize_selective_refresh' => true,
			]
		);

		if ( ! $labels ) {
			add_action( 'widgets_init', [ $this, 'translate_labels_where_placed' ], 99, 0 );
			add_action( 'the_widget', [ self::class, 'label_the_widget' ], 1 );
			add_filter( 'dynamic_sidebar_params', [ self::class, 'label_sidebar_params' ], 1 );
		}
	}

	/**
	 * Translates the labels when a classic widget area holds the widget.
	 *
	 * Priority 99: after themes register sidebars, before core copies the labels at 100.
	 *
	 * @return void
	 */
	public function translate_labels_where_placed(): void {
		if ( ! current_theme_supports( 'widgets' ) || ! self::is_in_a_sidebar() ) {
			return;
		}

		$this->name                          = __( 'Language Switcher', 'perflocale' );
		$this->widget_options['description'] = __( 'Display a language switcher with flags, names, or dropdown.', 'perflocale' );

		remove_action( 'the_widget', [ self::class, 'label_the_widget' ], 1 );
		remove_filter( 'dynamic_sidebar_params', [ self::class, 'label_sidebar_params' ], 1 );
	}

	/**
	 * Translates the registered labels before the_widget() renders this widget.
	 *
	 * @param string $widget Widget class name.
	 * @return void
	 */
	public static function label_the_widget( $widget ): void {
		if ( self::class === $widget ) {
			self::translate_registered_labels();
		}
	}

	/**
	 * Gives an instance rendered outside a holding sidebar its translated widget_name.
	 *
	 * Priority 1: before a theme's own filter reads the name.
	 *
	 * @param array<int, mixed> $params Sidebar parameters for one widget.
	 * @return array<int, mixed>
	 */
	public static function label_sidebar_params( $params ) {
		$first = $params[0] ?? null;

		if ( is_array( $first ) && isset( $first['widget_id'] ) && is_string( $first['widget_id'] ) && str_starts_with( $first['widget_id'], 'perflocale_switcher-' ) ) {
			self::translate_registered_labels();
			$first['widget_name'] = __( 'Language Switcher', 'perflocale' );
			$params[0]            = $first;
		}

		return $params;
	}

	/**
	 * Translate the registered widget's name and description.
	 *
	 * @return void
	 */
	private static function translate_registered_labels(): void {
		global $wp_widget_factory;

		$widget = $wp_widget_factory instanceof \WP_Widget_Factory ? $wp_widget_factory->get_widget_object( 'perflocale_switcher' ) : null;

		if ( $widget instanceof self ) {
			$widget->name                          = __( 'Language Switcher', 'perflocale' );
			$widget->widget_options['description'] = __( 'Display a language switcher with flags, names, or dropdown.', 'perflocale' );
		}
	}

	/**
	 * Whether an active sidebar holds this widget, from the stored option alone.
	 *
	 * Not wp_get_sidebars_widgets(): its filters may depend on a page not yet parsed.
	 *
	 * @return bool
	 */
	private static function is_in_a_sidebar(): bool {
		$sidebars = get_option( 'sidebars_widgets', [] );

		if ( ! is_array( $sidebars ) ) {
			return false;
		}

		foreach ( $sidebars as $sidebar => $widgets ) {
			if ( ! is_array( $widgets ) || 'wp_inactive_widgets' === $sidebar || str_starts_with( (string) $sidebar, 'orphaned_widgets' ) ) {
				continue;
			}

			foreach ( $widgets as $widget_id ) {
				if ( is_string( $widget_id ) && str_starts_with( $widget_id, 'perflocale_switcher-' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Render the widget.
	 *
	 * @param array<string, string> $args Widget arguments.
	 * @param array<string, mixed>  $instance Widget settings.
	 * @return void
	 */
	public function widget( $args, $instance ): void {
		$instance = wp_parse_args( $instance, self::DEFAULTS );

		// A programmatic caller may omit the wrapper keys.
		$args = wp_parse_args(
			$args,
			[
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			]
		);

		$render_args = [
			'display'          => sanitize_key( $instance['display'] ?? 'dropdown' ),
			'style'            => sanitize_key( $instance['style'] ),
			'layout'           => sanitize_key( $instance['layout'] ),
			'nameFormat'       => sanitize_key( $instance['nameFormat'] ),
			'showFlags'        => (bool) $instance['showFlags'],
			'showNames'        => (bool) $instance['showNames'],
			'hideCurrent'      => (bool) $instance['hideCurrent'],
			'showUntranslated' => (bool) $instance['showUntranslated'],
			'fontSize'         => (int) $instance['fontSize'],
			'flagSize'         => (int) $instance['flagSize'],
			'gap'              => (int) $instance['gap'],
		];

		if ( ! empty( $instance['triggerFormat'] ) ) {
			$render_args['triggerFormat'] = sanitize_key( $instance['triggerFormat'] );
		}

		if ( ! empty( $instance['arrowStyle'] ) ) {
			$render_args['arrowStyle'] = sanitize_key( $instance['arrowStyle'] );
		}

		$block = new LanguageSwitcherBlock();
		$html  = LanguageSwitcherBlock::kses_switcher( $block->render( $render_args ) );

		// No options to show: emit no empty widget box either.
		if ( trim( $html ) === '' ) {
			return;
		}

		echo wp_kses_post( $args['before_widget'] );

		if ( ! empty( $instance['title'] ) ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( $instance['title'] ) . wp_kses_post( $args['after_title'] );
		}

		// Re-escaped at the sink on purpose for scanners that ignore phpcs:ignore; kses_switcher() memoizes.
		echo LanguageSwitcherBlock::kses_switcher( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG-aware kses allowlist.

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Widget settings form in the Customizer / Widgets screen.
	 *
	 * @param array<string, mixed> $instance Current settings.
	 * @return void
	 */
	public function form( $instance ): void {
		( new LanguageSwitcherWidgetForm( $this ) )->form( $instance );
	}

	/**
	 * Sanitize and save widget settings.
	 *
	 * @param array<string, mixed> $new New settings.
	 * @param array<string, mixed> $old Old settings.
	 * @return array<string, mixed>
	 */
	public function update( $new, $old ): array {
		return ( new LanguageSwitcherWidgetForm( $this ) )->update( $new, $old );
	}
}
