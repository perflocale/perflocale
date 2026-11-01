<?php
/**
 * Admin list-screen helpers.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin list-screen helpers: rows-per-page bounds and a post-count summary line.
 *
 * Kept out of Helper so visitor pages do not compile it; Helper forwards its @api methods here.
 */
final class ListScreen {

	/**
	 * Ceiling for a "Rows per page" screen option: 40% of max_input_vars.
	 *
	 * PHP drops POST fields past max_input_vars, so a larger page bulk-acts on a prefix of the selection.
	 *
	 * @param string $option Screen-option name, for the filter.
	 * @return int
	 */
	public static function per_page_ceiling( string $option = '' ): int {
		$max_input_vars = (int) ini_get( 'max_input_vars' );
		$ceiling        = $max_input_vars > 0 ? max( 10, (int) floor( $max_input_vars * 0.4 ) ) : 400;

		/**
		 * Filter the maximum rows-per-page any PerfLocale list screen will use.
		 *
		 * @hook  perflocale/admin/per_page_ceiling
		 * @since 1.0.5
		 *
		 * @param int    $ceiling Computed ceiling.
		 * @param string $option  Screen-option name.
		 * @return int
		 */
		$ceiling = (int) apply_filters( 'perflocale/admin/per_page_ceiling', $ceiling, $option );

		return max( 1, $ceiling );
	}


	/**
	 * Clamp an effective rows-per-page value.
	 *
	 * Applied on read, not only on save, so an oversized stored value is capped; the stored value stays.
	 *
	 * @param int    $value   Raw stored value.
	 * @param int    $default Fallback when unset/invalid.
	 * @param string $option  Screen-option name, for the filter.
	 * @return int
	 */
	public static function normalize_per_page( int $value, int $default = 20, string $option = '' ): int {
		if ( $value < 1 ) {
			$value = $default;
		}

		return min( max( 1, $value ), self::per_page_ceiling( $option ) );
	}


	/**
	 * One line naming post counts by post type, e.g. "Products: 20, Posts: 4".
	 *
	 * Plain text: escape at the point of output.
	 *
	 * @param array<string, int> $counts Post type => count.
	 * @return string
	 */
	public static function post_type_counts_summary( array $counts ): string {
		$parts = [];

		foreach ( $counts as $type => $count ) {
			$object  = get_post_type_object( (string) $type );
			$label   = ( $object instanceof \WP_Post_Type && is_string( $object->labels->name ?? null ) ) ? $object->labels->name : (string) $type;
			$parts[] = sprintf( '%s: %s', $label, number_format_i18n( (int) $count ) );
		}

		return implode( ', ', $parts );
	}
}
