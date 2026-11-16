<?php
/**
 * Typed reads of loosely typed values.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A database row, decoded JSON or object property value read as the type the caller needs.
 *
 * @since 1.0.8
 */
final class Scalar {

	/**
	 * An int, or 0 when the value is not numeric.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A string, or '' when the value is not scalar.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function str( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A list of strings: each scalar element as a string, anything else ''.
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	public static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];

		foreach ( $value as $item ) {
			$out[] = self::str( $item );
		}

		return $out;
	}

	/**
	 * As filter_var( $s, FILTER_VALIDATE_INT ) without ext-filter (core lists it as optional): null where it gives false.
	 *
	 * @param string $s Candidate.
	 * @return int|null
	 */
	public static function parse_int( string $s ): ?int {
		$s = trim( $s, " \t\n\r\x0B" );

		if ( '' === $s ) {
			return null;
		}

		$negative = '-' === $s[0];
		$digits   = ( $negative || '+' === $s[0] ) ? substr( $s, 1 ) : $s;

		if ( '0' === $digits ) {
			return 0;
		}

		if ( ! \PerfLocale\Helper::is_ascii_digits( $digits ) || '0' === $digits[0] ) {
			return null;
		}

		// Equal-length digit strings compare as numbers, without an overflowing cast.
		$limit = $negative ? substr( (string) PHP_INT_MIN, 1 ) : (string) PHP_INT_MAX;

		if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) {
			return null;
		}

		return (int) ( $negative ? '-' . $digits : $digits );
	}

	/**
	 * A query-string argument of the current request as a whole number (parse_int()), or null.
	 *
	 * @param string $name Argument name.
	 * @return int|null
	 */
	public static function query_int( string $name ): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A display value validated as a whole number below; no state changes on it.
		$raw = isset( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : null;

		return is_string( $raw ) ? self::parse_int( $raw ) : null;
	}

	/**
	 * A public property of an object, or null when absent.
	 *
	 * @param mixed  $value Object.
	 * @param string $name  Property.
	 * @return mixed
	 */
	public static function prop( mixed $value, string $name ): mixed {
		if ( ! is_object( $value ) ) {
			return null;
		}

		$vars = get_object_vars( $value );

		return $vars[ $name ] ?? null;
	}
}
