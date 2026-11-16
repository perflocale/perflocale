<?php
/**
 * Typed reads of the machine translation context settings.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The mt_context_* settings; kept out of Settings, which every request loads, and out of Content ReSync, which may be off.
 *
 * @since 1.0.8
 */
final class ContextSettings {

	/** `mt_context_mode` values. */
	public const MODES = [ 'off', 'neighbours', 'section' ];

	/** Bounds of `mt_context_neighbours`. */
	public const NEIGHBOURS_MIN = 1;
	public const NEIGHBOURS_MAX = 5;

	/** Bounds of `mt_context_max_chars`. */
	public const MAX_CHARS_MIN = 200;
	public const MAX_CHARS_MAX = 5000;

	/**
	 * The context mode (MODES, else 'off').
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function mode( Settings $settings ): string {
		$value = $settings->get( 'mt_context_mode', 'off' );

		return is_string( $value ) && in_array( $value, self::MODES, true ) ? $value : 'off';
	}

	/**
	 * Neighbouring pieces sent as context, within its bounds.
	 *
	 * @param Settings $settings Settings.
	 * @return int
	 */
	public static function neighbours( Settings $settings ): int {
		$value = $settings->get( 'mt_context_neighbours', 2 );

		return is_numeric( $value ) ? max( self::NEIGHBOURS_MIN, min( self::NEIGHBOURS_MAX, (int) $value ) ) : 2;
	}

	/**
	 * Whether the previous translation goes with a piece as context.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public static function previous( Settings $settings ): bool {
		return false !== (bool) $settings->get( 'mt_context_previous', true );
	}

	/**
	 * The most context characters per piece, within its bounds.
	 *
	 * @param Settings $settings Settings.
	 * @return int
	 */
	public static function max_chars( Settings $settings ): int {
		$value = $settings->get( 'mt_context_max_chars', 1500 );

		return is_numeric( $value ) ? max( self::MAX_CHARS_MIN, min( self::MAX_CHARS_MAX, (int) $value ) ) : 1500;
	}
}
