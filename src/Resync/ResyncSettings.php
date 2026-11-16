<?php
/**
 * Typed reads of the Content ReSync settings.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Helper;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The resync_* settings as their callers read them; kept out of Settings, which every request loads.
 *
 * @since 1.0.8
 */
final class ResyncSettings {

	/** `resync_person_blocks`: keep a person's blocks on every run, or once. */
	public const PERSON_BLOCKS_ALWAYS = 'always';
	public const PERSON_BLOCKS_ONCE   = 'once';

	/**
	 * The match threshold (a RunOptions::THRESHOLDS value, else 100).
	 *
	 * @param Settings $settings Settings.
	 * @return int
	 */
	public static function match_threshold( Settings $settings ): int {
		return self::match_threshold_choice( $settings->get( 'resync_match_threshold', 100 ) );
	}

	/**
	 * A stored or posted threshold as a RunOptions::THRESHOLDS value, else 100.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function match_threshold_choice( mixed $value ): int {
		$value = is_int( $value ) || ( is_string( $value ) && Helper::is_ascii_digits( $value ) ) ? (int) $value : 100;

		return in_array( $value, RunOptions::THRESHOLDS, true ) ? $value : 100;
	}

	/**
	 * Whether a run keeps the translation's own exceptions (on unless set false).
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public static function keep_exceptions( Settings $settings ): bool {
		return false !== $settings->get( 'resync_keep_exceptions', true );
	}

	/**
	 * What fills changed parts: 'source' or 'machine'.
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function changed_parts( Settings $settings ): string {
		return self::changed_parts_choice( $settings->get( 'resync_changed_parts', 'source' ), true );
	}

	/**
	 * A stored or posted changed-parts value; 'machine' only where machine translation is offered.
	 *
	 * @param mixed $value      Value.
	 * @param bool  $mt_offered Whether machine translation is offered.
	 * @return string
	 */
	public static function changed_parts_choice( mixed $value, bool $mt_offered ): string {
		return $mt_offered && 'machine' === $value ? 'machine' : 'source';
	}

	/**
	 * When a person's blocks are kept: PERSON_BLOCKS_ALWAYS or PERSON_BLOCKS_ONCE.
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function person_blocks( Settings $settings ): string {
		return self::person_blocks_choice( $settings->get( 'resync_person_blocks', self::PERSON_BLOCKS_ALWAYS ) );
	}

	/**
	 * A stored or posted person-blocks value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function person_blocks_choice( mixed $value ): string {
		return self::PERSON_BLOCKS_ONCE === $value ? self::PERSON_BLOCKS_ONCE : self::PERSON_BLOCKS_ALWAYS;
	}
}
