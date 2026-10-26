<?php
/**
 * Bulk string machine translation: the per-dispatch cap.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The most strings one bulk string machine translation translates.
 *
 * Its own class so the Strings screen reads it without loading BulkStringTranslateJob.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class BulkStringCap {

	/**
	 * 5000, through the `perflocale/mt/bulk_string_max_per_dispatch` filter.
	 *
	 * @return int
	 */
	public static function max_per_dispatch(): int {
		$cap = apply_filters( 'perflocale/mt/bulk_string_max_per_dispatch', 5000 );

		return is_scalar( $cap ) || null === $cap ? (int) $cap : 0;
	}
}
