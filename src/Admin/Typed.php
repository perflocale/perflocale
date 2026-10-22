<?php
/**
 * Type-checked reads for the export and import code.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads of values whose type is not known (an export file's decoded JSON, a
 * database row, a request field) that check the type instead of casting:
 * a value of another type reads as the empty value of the wanted one.
 */
final class Typed {

	/**
	 * An integer: a number or a numeric string, otherwise $fallback.
	 *
	 * @param mixed $value    Value.
	 * @param int   $fallback Value for anything else.
	 * @return int
	 */
	public static function int( $value, int $fallback = 0 ): int {
		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * A string: a string, or a number as its digits; '' for anything else
	 * (a boolean, null, an array or an object).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function str( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) || is_float( $value ) ? (string) $value : '';
	}

	/**
	 * An array, or [] for anything else.
	 *
	 * @param mixed $value Value.
	 * @return array<array-key, mixed>
	 */
	public static function arr( $value ): array {
		return is_array( $value ) ? $value : [];
	}

	/**
	 * A string-keyed map: the array's members under string keys, or [] for
	 * anything that is not an array.
	 *
	 * @param mixed $value Value.
	 * @return array<string, mixed>
	 */
	public static function map( $value ): array {
		$out = [];

		foreach ( self::arr( $value ) as $key => $member ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $member;
			}
		}

		return $out;
	}

	/**
	 * The integers of a list, read with {@see self::int()}, in order.
	 *
	 * @param mixed $value List.
	 * @return list<int>
	 */
	public static function ints( $value ): array {
		$out = [];

		foreach ( self::arr( $value ) as $member ) {
			$out[] = self::int( $member );
		}

		return $out;
	}

	/**
	 * The strings of a list, read with {@see self::str()}, in order.
	 *
	 * @param mixed $value List.
	 * @return list<string>
	 */
	public static function strs( $value ): array {
		$out = [];

		foreach ( self::arr( $value ) as $member ) {
			$out[] = self::str( $member );
		}

		return $out;
	}

	/**
	 * Result rows read with ARRAY_A, each a string-keyed map; a result that
	 * is not a list of rows (null after a failed query) reads as [].
	 *
	 * @param mixed $result What `$wpdb->get_results( ..., ARRAY_A )` returned.
	 * @return list<array<string, mixed>>
	 */
	public static function rows( $result ): array {
		$out = [];

		foreach ( self::arr( $result ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::map( $row );
			}
		}

		return $out;
	}

	/**
	 * WordPress's database object.
	 *
	 * @return \wpdb
	 * @throws \RuntimeException When WordPress has not set it up (never on a loaded site).
	 */
	public static function wpdb(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}

		return $wpdb;
	}
}
