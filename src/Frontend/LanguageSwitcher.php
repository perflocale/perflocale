<?php
/**
 * Language switcher for the frontend.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Router\LanguageRouter;
use PerfLocale\Router\UrlConverter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language switcher output for the widget, shortcode, template tag and nav menus.
 */
final class LanguageSwitcher {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var UrlConverter
	 */
	private readonly UrlConverter $url_converter;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router        Language router.
	 * @param UrlConverter   $url_converter URL converter.
	 * @param Settings       $settings      Plugin settings.
	 */
	public function __construct( LanguageRouter $router, UrlConverter $url_converter, Settings $settings ) {
		$this->router        = $router;
		$this->url_converter = $url_converter;
		$this->settings      = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_shortcode( 'perflocale_switcher', [ $this, 'shortcode' ] );

		// In admin this service can boot after widgets_init has fired.
		if ( did_action( 'widgets_init' ) ) {
			$this->register_widget();
		} else {
			add_action( 'widgets_init', [ $this, 'register_widget' ] );
		}

		add_filter( 'wp_nav_menu_items', [ $this, 'add_to_menu' ], 20, 2 );
	}

	/**
	 * Render the language switcher: maps snake_case args to LanguageSwitcherBlock attributes.
	 *
	 * @param array<string, mixed> $args Display arguments.
	 * @return string HTML output.
	 */
	public function render( array $args = [] ): string {
		$defaults = [
			'template'          => $this->settings->get( 'switcher_template', 'flags_names' ),
			'display'           => $this->settings->get( 'switcher_display', 'dropdown' ),
			'layout'            => $this->settings->get( 'switcher_layout', 'horizontal' ),
			'name_format'       => $this->settings->get( 'switcher_name_format', 'native' ),
			'trigger_format'    => $this->settings->get( 'switcher_trigger_format', 'inherit' ),
			'arrow_style'       => $this->settings->get( 'switcher_arrow_style', 'single' ),
			'show_flags'        => $this->settings->get( 'switcher_flag_style', 'rectangular' ) !== 'none',
			'show_names'        => true,
			'show_native'       => true,
			'hide_current'      => (bool) $this->settings->get( 'switcher_hide_current', false ),
			'show_untranslated' => (bool) $this->settings->get( 'switcher_show_untranslated', false ),
			// Per-call class only: the block render already adds the global `switcher_class`.
			'class'             => '',
		];

		$args = wp_parse_args( $args, $defaults );

		$block_attrs = [
			'display'          => $args['display'],
			'style'            => $args['template'],
			'layout'           => $args['layout'],
			'nameFormat'       => $args['name_format'],
			'triggerFormat'    => $args['trigger_format'],
			'arrowStyle'       => $args['arrow_style'],
			// Not a (bool) cast: shortcode strings like "false" would be true.
			'showFlags'        => wp_validate_boolean( $args['show_flags'] ),
			'showNames'        => wp_validate_boolean( $args['show_names'] ),
			'hideCurrent'      => wp_validate_boolean( $args['hide_current'] ),
			'showUntranslated' => wp_validate_boolean( $args['show_untranslated'] ),
			'fontSize'         => isset( $args['font_size'] ) ? (int) $args['font_size'] : 14,
			'flagSize'         => isset( $args['flag_size'] ) ? (int) $args['flag_size'] : 20,
			'gap'              => isset( $args['gap'] ) ? (int) $args['gap'] : 8,
			'className'        => $args['class'],
		];

		$block = new LanguageSwitcherBlock();
		$html  = $block->render( $block_attrs );

		/**
		 * Filter the final switcher output.
		 *
		 * @param string $html Switcher HTML.
		 * @param array  $args Switcher arguments (snake_case shortcode keys).
		 */
		$filtered = apply_filters( 'perflocale/switcher/output', $html, $args );

		// A non-string would fatal in the typed kses_switcher(): fall back to the unfiltered HTML.
		if ( ! is_string( $filtered ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/switcher/output", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/switcher/output returned %s - the filter contract is string-in / string-out. Falling back to the unfiltered switcher HTML.', 'perflocale' ),
						get_debug_type( $filtered )
					)
				),
				'1.0.0'
			);
			return $html;
		}

		return $filtered;
	}

	/**
	 * Build the escaped attribute string for a per-language switcher link.
	 *
	 * True renders a boolean attribute; false or null omits it.
	 *
	 * @param array  $base_attrs   Default attribute map.
	 * @param object $lang         Language object.
	 * @param string $current_slug Currently active language slug.
	 * @param array  $args         Switcher arguments.
	 * @return string Pre-escaped HTML attribute string.
	 */
	public static function render_link_attrs( array $base_attrs, object $lang, string $current_slug, array $args ): string {
		/**
		 * Filter the HTML attributes for a per-language link in the switcher.
		 *
		 * @hook perflocale/switcher/link_attrs
		 *
		 * @param array  $attrs        Attribute name => value pairs.
		 * @param object $lang         Language object for this link.
		 * @param string $current_slug Currently active language slug.
		 * @param array  $args         Switcher arguments.
		 */
		$attrs = apply_filters( 'perflocale/switcher/link_attrs', $base_attrs, $lang, $current_slug, $args );

		if ( ! is_array( $attrs ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/switcher/link_attrs", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/switcher/link_attrs returned %s - must be an associative array of attribute name => value. Falling back to the unfiltered base attributes so the link still renders.', 'perflocale' ),
						get_debug_type( $attrs )
					)
				),
				'1.0.0'
			);
			$attrs = $base_attrs;
		}

		$parts = [];

		foreach ( $attrs as $name => $value ) {
			if ( $value === null || $value === false ) {
				continue;
			}

			$clean_name = preg_replace( '/[^A-Za-z0-9:_\-]/', '', (string) $name );

			if ( $clean_name === '' ) {
				continue;
			}

			if ( $value === true ) {
				$parts[] = $clean_name;
				continue;
			}

			$escaped = $clean_name === 'href'
				? esc_url( (string) $value )
				: esc_attr( (string) $value );

			$parts[] = $clean_name . '="' . $escaped . '"';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Shortcode handler: [perflocale_switcher].
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'style'             => '',
				'display'           => '',
				'layout'            => '',
				'name_format'       => '',
				'trigger_format'    => '',
				'arrow_style'       => '',
				'show_flags'        => '',
				'show_names'        => '',
				'hide_current'      => '',
				'show_untranslated' => '',
				'class'             => '',
			],
			(array) $atts,
			'perflocale_switcher'
		);

		$atts['template'] = $atts['style'];
		unset( $atts['style'] );

		$args = array_filter( $atts, fn( $v ) => $v !== '' );

		return LanguageSwitcherBlock::kses_switcher( $this->render( $args ) );
	}

	/**
	 * Register the language switcher widget.
	 *
	 * @return void
	 */
	public function register_widget(): void {
		register_widget( LanguageSwitcherWidget::class );
	}

	/**
	 * Add language switcher to nav menus that have the option enabled.
	 *
	 * @param string    $items Menu HTML items.
	 * @param \stdClass $args  Menu arguments.
	 * @return string Modified menu items.
	 */
	public function add_to_menu( string $items, \stdClass $args ): string {
		// Locations picked in settings, case kept: theme_location is the raw, possibly mixed-case key.
		$setting_locations = array_values(
			array_filter(
				array_map( 'strval', (array) $this->settings->get( 'switcher_menu_locations', [] ) ),
				// Not the default falsy filter: a location slug "0" is valid.
				static fn( string $s ): bool => $s !== ''
			)
		);

		/**
		 * Filter whether to add the switcher to this menu.
		 *
		 * @param bool      $add  Whether to add the switcher.
		 * @param \stdClass $args Menu arguments.
		 */
		$add = apply_filters( 'perflocale/switcher/add_to_menu', $setting_locations !== [], $args );

		if ( ! $add ) {
			return $items;
		}

		/**
		 * Filter which menu locations should include the language switcher.
		 *
		 * Return an array of theme location slugs; empty array = all menus.
		 *
		 * @param array<int, string> $locations Allowed menu location slugs.
		 * @param \stdClass          $args      Menu arguments.
		 */
		$locations = apply_filters( 'perflocale/switcher/menu_locations', $setting_locations, $args );

		if ( ! empty( $locations ) && ! in_array( $args->theme_location ?? '', $locations, true ) ) {
			return $items;
		}

		$languages = $this->router->get_active_languages();

		if ( count( $languages ) < 2 ) {
			return $items;
		}

		// Resolved only when the switcher renders; the lookup is memoized per request.
		$current_slug = $this->router->get_current_slug();
		$urls         = $this->url_converter->get_translations_for_current_page();

		$settings          = $this->settings;
		$hide_current      = (bool) $settings->get( 'switcher_hide_current', false );
		$show_untranslated = (bool) $settings->get( 'switcher_show_untranslated', false );
		$untranslated_link = (string) $settings->get( 'switcher_untranslated_link', 'homepage' );

		foreach ( $languages as $lang ) {
			$is_current      = $lang->slug === $current_slug;
			$url             = $urls[ $lang->slug ] ?? '';
			$has_translation = ( $url !== '' );

			if ( $hide_current && $is_current ) {
				continue;
			}

			if ( ! $has_translation && ! $is_current ) {
				if ( ! $show_untranslated ) {
					continue;
				}

				if ( $untranslated_link === 'hide' ) {
					continue;
				}

				if ( $untranslated_link === 'no_link' ) {
					$url = '';
				} else {
					// Target language's home, as in LanguageSwitcherBlock::render().
					$url = perflocale_home_url( $lang->slug );
				}
			}

			$li_class = 'menu-item perflocale-menu-item' . ( $is_current ? ' current-language' : '' );
			$label    = esc_html( $lang->native_name ?? $lang->name ?? '' );

			if ( $url === '' ) {
				$items .= sprintf(
					'<li class="%s perflocale-menu-item--disabled"><span lang="%s"%s aria-disabled="true">%s</span></li>',
					esc_attr( $li_class ),
					esc_attr( \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ) ),
					( ! empty( $lang->text_direction ) && $lang->text_direction === 'rtl' ) ? ' dir="rtl"' : '',
					$label
				);

				continue;
			}

			$base_attrs = [
				'href'     => $url,
				'hreflang' => \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ),
				'lang'     => \PerfLocale\Helper::format_locale_as_bcp47( $lang->slug ),
			];

			if ( ! empty( $lang->text_direction ) && $lang->text_direction === 'rtl' ) {
				$base_attrs['dir'] = 'rtl';
			}

			if ( $is_current ) {
				$base_attrs['aria-current'] = 'true';
			}

			$link_attrs = self::render_link_attrs( $base_attrs, $lang, $current_slug, [] );

			$items .= sprintf(
				'<li class="%s"><a %s>%s</a></li>',
				esc_attr( $li_class ),
				$link_attrs,
				$label
			);
		}

		return $items;
	}
}
