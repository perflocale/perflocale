<?php
/**
 * The [perflocale_language] shortcode.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders [perflocale_language]; Bootstrap requires it on the first render.
 *
 * @internal
 */
final class LanguageShortcode {

	/**
	 * The current language as format="slug|locale|name|native_name|display_name|flag" (default name), escaped.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( [ 'format' => 'name' ], (array) $atts, 'perflocale_language' );
		$h    = perflocale();

		return match ( $atts['format'] ) {
			'slug' => esc_html( $h->slug() ),
			'locale' => esc_html( $h->locale() ),
			'name' => esc_html( $h->name() ),
			'native_name', 'native' => esc_html( $h->native_name() ),
			'display_name', 'display' => esc_html( $h->display_name() ),
			'flag' => esc_html( $h->flag() ),
			default => esc_html( $h->name() ),
		};
	}
}
