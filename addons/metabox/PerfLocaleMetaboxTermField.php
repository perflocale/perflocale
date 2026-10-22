<?php
/**
 * PerfLocale MetaBox addon: the term display class of `taxonomy_advanced`
 * fields on a translated request.
 *
 * Loaded only by PerfLocaleMetaboxTranslated::term_display_field_class(), after Meta
 * Box has loaded RWMB_Taxonomy_Advanced_Field.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta Box's `taxonomy_advanced` field class with one change: the term
 * format_single_value() prints is first mapped to the current language's
 * sibling term. Every other method, saves included, is Meta Box's own.
 */
class PerfLocaleMetaboxTermField extends RWMB_Taxonomy_Advanced_Field {

	/**
	 * Maps a printed term to the term to print, or null to print it as it is.
	 *
	 * @var (\Closure(mixed): mixed)|null
	 */
	public static ?\Closure $map = null;

	/**
	 * Format one term for the_value(), after mapping it.
	 *
	 * @param mixed $field   Field settings.
	 * @param mixed $value   The term object.
	 * @param mixed $args    Additional arguments.
	 * @param mixed $post_id Post ID, or null for the current post.
	 * @return mixed
	 */
	public static function format_single_value( $field, $value, $args, $post_id ) {
		if ( null !== self::$map && is_object( $value ) ) {
			$value = ( self::$map )( $value );
		}

		return parent::format_single_value( $field, $value, $args, $post_id );
	}
}
