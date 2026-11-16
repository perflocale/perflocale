<?php
/**
 * Block attribute JSON, decoded and encoded as core does.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decodes the `{...}` of a block comment and re-encodes it as core's `serialize_block_attributes()`. The decode keeps
 * objects as `stdClass`: cast to an array, an object keyed "0".."n-1" would encode as a list, which at the top level
 * the delimiter regex no longer matches (the block turns freeform). Core's function takes only arrays, hence this.
 */
final class BlockJson {

	/** The editor's escapes applied after encoding (core's table in `serialize_block_attributes()`). */
	private const ESCAPES = [
		'\\\\' => '\\u005c',
		'--'   => '\\u002d\\u002d',
		'<'    => '\\u003c',
		'>'    => '\\u003e',
		'&'    => '\\u0026',
		'\\"'  => '\\u0022',
	];

	/**
	 * Decode attribute JSON keeping objects as objects.
	 *
	 * @param string $json The `{...}` bytes.
	 * @return \stdClass|null Null when the JSON is invalid or not an object.
	 */
	public static function decode( string $json ): ?\stdClass {
		$decoded = json_decode( $json );

		return $decoded instanceof \stdClass ? $decoded : null;
	}

	/**
	 * Decode attribute JSON associatively, exactly as `WP_Block_Parser` does.
	 *
	 * @param string $json The `{...}` bytes.
	 * @return array<mixed>|null Null when the JSON is invalid (core stores null attributes then).
	 */
	public static function decode_assoc( string $json ): ?array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Whether valid attribute JSON nests deeper than a number of container levels (top object level 1), counted by the
	 * decoder so a bracket in a string is no level. Invalid JSON is not deeper (core reads no attributes); JSON past the
	 * decoder's own limit is.
	 *
	 * @param string $json   The `{...}` bytes.
	 * @param int    $levels Container levels allowed.
	 * @return bool
	 * @phpstan-param int<0, max> $levels
	 */
	public static function deeper_than( string $json, int $levels ): bool {
		if ( substr_count( $json, '{' ) + substr_count( $json, '[' ) <= $levels ) {
			return false;
		}

		// PHP's depth counts the containers plus one.
		json_decode( $json, true, $levels + 1 );

		if ( json_last_error() !== JSON_ERROR_DEPTH ) {
			return false;
		}

		json_decode( $json, true );

		return in_array( json_last_error(), [ JSON_ERROR_NONE, JSON_ERROR_DEPTH ], true );
	}

	/**
	 * Encode attributes as core's `serialize_block_attributes()` would.
	 *
	 * @param \stdClass $attrs Attributes.
	 * @return string The JSON; '' when it cannot be encoded.
	 */
	public static function encode( \stdClass $attrs ): string {
		$json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) ) {
			return '';
		}

		return strtr( $json, self::ESCAPES );
	}
}
