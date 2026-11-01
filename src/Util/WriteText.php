<?php
/**
 * Input shaping for writes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Input shaping for writes: plain-text sanitising, slug truncation, filter_var-exact booleans.
 *
 * Kept out of Helper so visitor pages do not compile it; Helper forwards its @api methods here.
 */
final class WriteText {

	/**
	 * Sanitise plain text like sanitize_text_field() but without deleting percent-escapes ("100%25 off").
	 *
	 * The UTF-8 scrub is needed: wpdb refuses a whole write whose value strip_invalid_text() would change.
	 * Takes mixed: REST sanitize_callbacks pass whatever the client sent, and strict_types would throw.
	 *
	 * @param mixed $str           Raw value; a scalar is cast to a string, anything else yields ''.
	 * @param bool  $keep_newlines Keep line breaks (excerpt) or collapse them (title).
	 * @return string Sanitised text.
	 */
	public static function sanitize_plain_text( $str, bool $keep_newlines = false ): string {
		if ( ! is_scalar( $str ) ) {
			return '';
		}

		$str = (string) $str;

		if ( $str === '' ) {
			return '';
		}

		// One PCRE pass proves validity; a valid subject never enters the scrub.
		if ( preg_match( '//u', $str ) !== 1 ) {
			// Same alternation as wpdb::strip_invalid_text(): valid sequences kept, other bytes dropped.
			$str = (string) preg_replace(
				'/([\x00-\x7F]'
				. '|[\xC2-\xDF][\x80-\xBF]'
				. '|\xE0[\xA0-\xBF][\x80-\xBF]'
				. '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
				. '|\xED[\x80-\x9F][\x80-\xBF]'
				. '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
				. '|[\xF1-\xF3][\x80-\xBF]{3}'
				. '|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
				'$1',
				$str
			);
		}

		// Core's tag handling verbatim, to match sanitize_text_field() on markup.
		if ( str_contains( $str, '<' ) ) {
			$str = wp_pre_kses_less_than( $str );
			$str = wp_strip_all_tags( $str, false );
			$str = str_replace( "<\n", '&lt;' . "\n", $str );
		}

		if ( ! $keep_newlines ) {
			$str = (string) preg_replace( '/[\r\n\t ]+/', ' ', $str );
		}

		return trim( $str );
	}


	/**
	 * Shorten an already-sanitised slug to `$length` bytes without cutting inside a percent-escape.
	 *
	 * Walks the encoded slug, never decodes it (decoding breaks `%25`); see docs-ai/11-code-rationale.md.
	 * The byte bound is load-bearing: force_insert_term()'s "-N" walk spins forever otherwise.
	 *
	 * @param string $slug   Slug, already sanitize_title()'d.
	 * @param int    $length Maximum length to keep.
	 * @return string Shortened slug, or the input when it already fits.
	 */
	public static function truncate_slug( string $slug, int $length ): string {
		if ( $length < 1 || strlen( $slug ) <= $length ) {
			return $slug;
		}

		$out = '';
		$i   = 0;
		$n   = strlen( $slug );

		while ( $i < $n ) {
			$char = $slug[ $i ];

			$hex = $char === '%' && $i + 2 < $n ? substr( $slug, $i + 1, 2 ) : '';

			// Strspn, not ctype_xdigit: the plugin does not require ext-ctype.
			if ( $hex !== '' && strspn( $hex, '0123456789abcdefABCDEF' ) === 2 ) {
				$byte = (int) hexdec( $hex );

				// A lead byte >= 0xC0 makes all escapes of its character one indivisible atom.
				if ( $byte >= 0xF0 ) {
					$octets = 4;
				} elseif ( $byte >= 0xE0 ) {
					$octets = 3;
				} elseif ( $byte >= 0xC0 ) {
					$octets = 2;
				} else {
					$octets = 1;
				}

				$atom = substr( $slug, $i, 3 * $octets );
			} elseif ( ord( $char ) >= 0x80 ) {
				// Raw UTF-8 from a third-party `sanitize_title` filter: keep the character whole.
				$lead   = ord( $char );
				$octets = $lead >= 0xF0 ? 4 : ( $lead >= 0xE0 ? 3 : ( $lead >= 0xC0 ? 2 : 1 ) );
				$atom   = substr( $slug, $i, $octets );
			} else {
				$atom = $char;
			}

			if ( strlen( $out ) + strlen( $atom ) > $length ) {
				break;
			}

			$out .= $atom;
			$i   += strlen( $atom );
		}

		return rtrim( $out, '-' );
	}


	/**
	 * Exact native replacement for `filter_var( $v, FILTER_VALIDATE_BOOLEAN )`.
	 *
	 * Not wp_validate_boolean() or rest_sanitize_boolean(): both map "off" and "no" to true.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value === 1.0;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		// Explicit charlist: filter_var trims these, but not NUL as the default trim() does.
		return in_array( strtolower( trim( $value, " \t\n\r\x0B" ) ), [ '1', 'true', 'on', 'yes' ], true );
	}
}
