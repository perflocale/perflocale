<?php
/**
 * Report of a filter callback's unusable return, loaded only when one happens.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the hook and the returned type once per call, and hands back the unfiltered value.
 */
final class FilterReturn {

	/**
	 * A string filter's result: a scalar as a string, anything else reported and replaced by the unfiltered value.
	 *
	 * @param string $hook     Hook name.
	 * @param mixed  $returned What the callbacks returned.
	 * @param string $fallback Unfiltered value.
	 * @return string
	 */
	public static function string( string $hook, $returned, string $fallback ): string {
		return is_scalar( $returned ) ? (string) $returned : self::rejected( $hook, $returned, $fallback );
	}

	/**
	 * Report a filter result of the wrong type; returns $fallback, the unfiltered value.
	 *
	 * @template T
	 * @param string $hook     Hook name.
	 * @param mixed  $returned What the callbacks returned.
	 * @param T      $fallback Unfiltered value.
	 * @return T
	 */
	public static function rejected( string $hook, $returned, $fallback ) {
		_doing_it_wrong(
			esc_html( 'apply_filters( "' . $hook . '", ... )' ),
			esc_html(
				sprintf(
					/* translators: 1: hook name, 2: the returned type. */
					__( 'A callback on %1$s returned %2$s, which this hook does not accept: the unfiltered value was used.', 'perflocale' ),
					$hook,
					get_debug_type( $returned )
				)
			),
			'1.0.7.5'
		);

		return $fallback;
	}
}
