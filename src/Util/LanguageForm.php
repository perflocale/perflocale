<?php
/**
 * Languages-form helpers.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Languages-form helpers: region-qualified slug suggestions and the default text direction.
 *
 * Kept out of Helper so visitor pages do not compile it; Helper forwards its @api methods here.
 */
final class LanguageForm {

	/**
	 * Suggest a region-qualified slug (`en` -> `en-us`) for a bare slug, from its locale or flag.
	 *
	 * Returns '' when the slug already has a region or none can be derived.
	 *
	 * @param object $language Language row (`->slug`, `->locale`).
	 * @return string Suggested slug, e.g. `en-us`, or empty string.
	 */
	public static function suggest_region_qualified_slug( object $language ): string {
		$slug   = strtolower( (string) ( $language->slug ?? '' ) );
		$locale = (string) ( $language->locale ?? '' );
		$flag   = strtolower( (string) ( $language->flag ?? '' ) );

		if ( $slug === '' || strpos( $slug, '-' ) !== false ) {
			return '';
		}

		// Primary: derive region from the WP locale (`en_US` → `us`).
		$region = '';
		$parts  = explode( '_', $locale );

		if ( count( $parts ) >= 2 && Helper::is_ascii_alpha( $parts[1] ) ) {
			$region = strtolower( $parts[1] );
		}

		// Fallback for region-less locales (`ar`): the flag column holds an ISO code or a flag emoji.
		if ( $region === '' && $flag !== '' ) {
			if ( Helper::is_ascii_alpha( $flag ) && strlen( $flag ) === 2 ) {
				$region = $flag;
			} else {
				$decoded = self::region_code_from_flag_emoji( $flag );

				if ( $decoded !== '' ) {
					$region = $decoded;
				}
			}
		}

		if ( $region === '' ) {
			return '';
		}

		$suggested = $slug . '-' . $region;

		return $suggested === $slug ? '' : $suggested;
	}


	/**
	 * Active bare slugs whose own language family has a region-qualified member (`en` with `en-GB`).
	 *
	 * Same family only: a lone `de` beside `fr-FR` is intentional and is never flagged.
	 *
	 * @param array<int, object> $languages All active languages.
	 * @return array<int, array{language: object, suggested: string}>
	 */
	public static function detect_bare_languages_with_region_siblings( array $languages ): array {
		// Group by family: the slug part before the first `-`.
		$families = [];

		foreach ( $languages as $lang ) {
			$slug = strtolower( (string) ( $lang->slug ?? '' ) );

			if ( $slug === '' ) {
				continue;
			}

			$dash   = strpos( $slug, '-' );
			$family = $dash === false ? $slug : substr( $slug, 0, $dash );

			$families[ $family ][] = $lang;
		}

		$out = [];

		foreach ( $families as $family => $members ) {
			$bare           = null;
			$has_region_var = false;

			foreach ( $members as $m ) {
				$slug = strtolower( (string) $m->slug );

				if ( $slug === $family ) {
					$bare = $m;
				} elseif ( strpos( $slug, '-' ) !== false ) {
					$has_region_var = true;
				}
			}

			if ( $bare === null || ! $has_region_var ) {
				// No bare member, or no region member: nothing asymmetric.
				continue;
			}

			$suggested = self::suggest_region_qualified_slug( $bare );

			if ( $suggested === '' ) {
				continue; // No region target.
			}

			$out[] = [
				'language'  => $bare,
				'suggested' => $suggested,
			];
		}

		return $out;
	}


	/**
	 * Bare-slug rename candidates for the Add-Language form, with no region-sibling requirement.
	 *
	 * The form is where the sibling gets added, so waiting for one would nudge too late.
	 *
	 * @param array<int, object> $languages All active languages.
	 * @return array<int, array{language: object, suggested: string}>
	 */
	public static function bare_language_rename_candidates_for_form( array $languages ): array {
		$out = [];

		foreach ( $languages as $lang ) {
			$slug = strtolower( (string) ( $lang->slug ?? '' ) );

			if ( $slug === '' || strpos( $slug, '-' ) !== false ) {
				continue;
			}

			$suggested = self::suggest_region_qualified_slug( $lang );

			if ( $suggested === '' ) {
				continue;
			}

			$out[] = [
				'language'  => $lang,
				'suggested' => $suggested,
			];
		}

		return $out;
	}


	/**
	 * Inverse of {@see Helper::country_code_to_emoji()}: a flag emoji pair to a lowercase country code, or ''.
	 *
	 * @param string $emoji Two regional-indicator codepoints.
	 * @return string Lowercase 2-letter region code, or '' on no match.
	 */
	public static function region_code_from_flag_emoji( string $emoji ): string {
		// Byte-level decode, not mb_ord(): core polyfills it only from WP 7.1 and mbstring is optional.
		if ( strlen( $emoji ) !== 8 ) {
			return '';
		}

		$prefix = "\xF0\x9F\x87";

		if ( substr( $emoji, 0, 3 ) !== $prefix || substr( $emoji, 4, 3 ) !== $prefix ) {
			return '';
		}

		$b1 = ord( $emoji[3] );
		$b2 = ord( $emoji[7] );

		// Trailing byte A6..BF maps to A..Z.
		if ( $b1 < 0xA6 || $b1 > 0xBF || $b2 < 0xA6 || $b2 > 0xBF ) {
			return '';
		}

		return chr( ord( 'a' ) + ( $b1 - 0xA6 ) ) . chr( ord( 'a' ) + ( $b2 - 0xA6 ) );
	}


	/**
	 * Default writing direction for a locale, keyed on its primary subtag.
	 *
	 * @param string $locale Locale string (`ar`, `he_IL`, `ckb-IR`, etc.).
	 * @return string 'rtl' or 'ltr'.
	 */
	public static function default_text_direction( string $locale ): string {
		$parts   = preg_split( '/[_\-]/', trim( $locale ), 2 );
		$primary = strtolower( (string) ( $parts[0] ?? '' ) );

		// RTL primary language subtags (mirrors data/languages.php RTL rows).
		static $rtl = [ 'ar', 'arc', 'bal', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi' ];

		return in_array( $primary, $rtl, true ) ? 'rtl' : 'ltr';
	}
}
