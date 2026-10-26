<?php
/**
 * CLDR plural rules: how many plural forms a language has, and which form a count selects.
 *
 * Form 0 is the `singular` row, form 1 the `plural` row, forms 2..N its `extra_forms` JSON.
 *
 * @package PerfLocale\Strings
 */

namespace PerfLocale\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Locale plural-form metadata + form-index resolution.
 */
final class PluralRules {

	/**
	 * gettext Plural-Forms expressions keyed by base language code.
	 *
	 * Value: [ nplurals, expression ], the set translate.wordpress.org uses. `pt_BR` falls back to `pt`,
	 * an unknown language to the English 2-form rule.
	 *
	 * @var array<string, array{0:int,1:string}>
	 */
	private const RULES = array(
		// 1 form (no plural distinction).
		'ja'  => array( 1, '0' ),
		'zh'  => array( 1, '0' ),
		'ko'  => array( 1, '0' ),
		'vi'  => array( 1, '0' ),
		'th'  => array( 1, '0' ),
		'id'  => array( 1, '0' ),
		'ms'  => array( 1, '0' ),
		'lo'  => array( 1, '0' ),
		'km'  => array( 1, '0' ),
		'my'  => array( 1, '0' ),

		// 2 forms, n != 1 (the Germanic/Romance default).
		'en'  => array( 2, '(n != 1)' ),
		'de'  => array( 2, '(n != 1)' ),
		'nl'  => array( 2, '(n != 1)' ),
		'sv'  => array( 2, '(n != 1)' ),
		'da'  => array( 2, '(n != 1)' ),
		'no'  => array( 2, '(n != 1)' ),
		'nb'  => array( 2, '(n != 1)' ),
		'nn'  => array( 2, '(n != 1)' ),
		'es'  => array( 2, '(n != 1)' ),
		'it'  => array( 2, '(n != 1)' ),
		'pt'  => array( 2, '(n != 1)' ),
		'el'  => array( 2, '(n != 1)' ),
		'fi'  => array( 2, '(n != 1)' ),
		'et'  => array( 2, '(n != 1)' ),
		'he'  => array( 2, '(n != 1)' ),
		'bg'  => array( 2, '(n != 1)' ),
		'ca'  => array( 2, '(n != 1)' ),
		'eu'  => array( 2, '(n != 1)' ),
		'hu'  => array( 2, '(n != 1)' ),

		// 2 forms, n > 1 (French/Brazilian variety).
		'fr'  => array( 2, '(n > 1)' ),
		'pt_br' => array( 2, '(n > 1)' ),
		// Turkish and Persian are 2-form in CLDR and every WP language pack header: 1-form would drop
		// the plural msgstr their POs ship. (n > 1) matches the header translators write under, and for fa
		// it is also the exact CLDR rule (one = i=0 or n=1).
		'tr'  => array( 2, '(n > 1)' ),
		'fa'  => array( 2, '(n > 1)' ),

		// 3 forms - Slavic (Russian/Ukrainian/Serbian/Croatian family).
		'ru'  => array( 3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)' ),
		'uk'  => array( 3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)' ),
		'sr'  => array( 3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)' ),
		'hr'  => array( 3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)' ),
		'be'  => array( 3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)' ),

		// 3 forms - Czech/Slovak.
		'cs'  => array( 3, '((n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2)' ),
		'sk'  => array( 3, '((n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2)' ),

		// 3 forms - Polish.
		'pl'  => array( 3, '(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2)' ),

		// 3 forms - Lithuanian.
		'lt'  => array( 3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2)' ),

		// 4 forms - Slovenian.
		'sl'  => array( 4, '(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3)' ),

		// 4 forms - Scottish Gaelic.
		'gd'  => array( 4, '((n==1 || n==11) ? 0 : (n==2 || n==12) ? 1 : (n > 2 && n < 20) ? 2 : 3)' ),

		// 6 forms - Arabic.
		'ar'  => array( 6, '(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5)' ),

		// 4 forms - Welsh.
		'cy'  => array( 4, '((n==1) ? 0 : (n==2) ? 1 : (n != 8 && n != 11) ? 2 : 3)' ),

		// 3 forms - Latvian.
		'lv'  => array( 3, '(n%10==1 && n%100!=11 ? 0 : n != 0 ? 1 : 2)' ),

		// 3 forms - Romanian.
		'ro'  => array( 3, '(n==1 ? 0 : (n==0 || (n%100 > 0 && n%100 < 20)) ? 1 : 2)' ),
	);

	/**
	 * The safe fallback for an unlisted language: English 2-form.
	 */
	private const DEFAULT_RULE = array( 2, '(n != 1)' );

	/**
	 * Compiled {@see \Plural_Forms} instances, memoized by expression.
	 *
	 * @var array<string, \Plural_Forms>
	 */
	private static array $compiled = array();

	/**
	 * Resolve a locale/slug to its [ nplurals, expression ] rule.
	 *
	 * Accepts a WP locale (`pt_BR`), a slug (`pt-br`) or a bare code: exact normalized key, then the base.
	 *
	 * @param string $locale Locale, slug, or language code.
	 * @return array{0:int,1:string}
	 */
	public static function rule_for( string $locale ): array {
		$key = strtolower( str_replace( '-', '_', $locale ) );

		if ( isset( self::RULES[ $key ] ) ) {
			return self::RULES[ $key ];
		}

		$base = strtok( $key, '_' );

		if ( is_string( $base ) && isset( self::RULES[ $base ] ) ) {
			return self::RULES[ $base ];
		}

		return self::DEFAULT_RULE;
	}

	/**
	 * Number of grammatical plural forms for a locale (1-6).
	 *
	 * @param string $locale Locale, slug, or language code.
	 * @return int
	 */
	public static function nplurals( string $locale ): int {
		return self::rule_for( $locale )[0];
	}

	/**
	 * The full gettext `Plural-Forms:` header value for a locale.
	 *
	 * @param string $locale Locale, slug, or language code.
	 * @return string e.g. "nplurals=3; plural=(n==1 ? 0 : …);"
	 */
	public static function header( string $locale ): string {
		list( $nplurals, $expr ) = self::rule_for( $locale );

		return sprintf( 'nplurals=%d; plural=%s;', $nplurals, $expr );
	}

	/**
	 * The CLDR plural FORM INDEX (0-based) a count selects in a locale.
	 *
	 * Uses core's Plural_Forms, so the pick matches what gettext would choose; clamped to [0, nplurals-1].
	 *
	 * @param string $locale Locale, slug, or language code.
	 * @param int    $number The count.
	 * @return int Form index in [0, nplurals-1].
	 */
	public static function form_index( string $locale, int $number ): int {
		list( $nplurals, $expr ) = self::rule_for( $locale );

		if ( $nplurals <= 1 ) {
			return 0;
		}

		if ( ! class_exists( '\\Plural_Forms' ) ) {
			// Core's pomo file is not autoloaded: require it.
			$pomo = ABSPATH . WPINC . '/pomo/plural-forms.php';

			if ( is_readable( $pomo ) ) {
				require_once $pomo;
			}
		}

		if ( ! class_exists( '\\Plural_Forms' ) ) {
			// Last-ditch: mirror the 2-form default without the evaluator.
			return $number === 1 ? 0 : min( 1, $nplurals - 1 );
		}

		if ( ! isset( self::$compiled[ $expr ] ) ) {
			try {
				self::$compiled[ $expr ] = new \Plural_Forms( $expr );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $number === 1 ? 0 : min( 1, $nplurals - 1 );
			}
		}

		try {
			$index = (int) self::$compiled[ $expr ]->get( $number );
		} catch ( \Throwable $e ) {
			unset( $e );
			$index = $number === 1 ? 0 : 1;
		}

		return max( 0, min( $index, $nplurals - 1 ) );
	}

	/**
	 * Reset the compiled-expression memo (test isolation / MU switch).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$compiled = array();
	}
}
