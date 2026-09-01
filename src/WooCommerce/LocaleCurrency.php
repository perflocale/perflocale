<?php
/**
 * Locale → currency inference for the WooCommerce settings UI.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Best-guess ISO 4217 currency code from a language's WordPress locale.
 *
 * Used by the WooCommerce settings tab to populate the per-language
 * Exchange Rates matrix when the user adds a new language: instead of
 * defaulting every row to the store's base currency (which on a
 * European store is usually EUR, so a freshly-added Polish row would
 * be set to EUR rather than PLN), the matrix uses this inference for
 * the initial value. The user can still override per row.
 *
 * Inference path:
 *   1. WordPress locales are `xx_YY` where YY is the ISO 3166-1 alpha-2
 *      country code. Look it up in the country → currency map below.
 *   2. If the locale carries no region (bare `pl`, `fr`, `de`, …),
 *      fall through to a small language → primary-country map for
 *      common languages.
 *   3. If neither match, return empty string — the caller's existing
 *      fallback (WC base currency) takes over.
 *
 * @internal Implementation detail of the WC settings UI. Not part of
 *           the @api surface — content of the map may shift as the
 *           ISO currency assignments do (e.g. Croatia adopted EUR in
 *           2023; Latvia 2014; Estonia 2011).
 */
final class LocaleCurrency {

	/**
	 * ISO 3166-1 alpha-2 country code → ISO 4217 alpha-3 currency code.
	 *
	 * Coverage prioritises:
	 *   • All 20 EUR-using EU member states + non-EU EUR users
	 *     (Andorra, Monaco, San Marino, Vatican, Montenegro, Kosovo)
	 *   • All G20 economies
	 *   • Top 50 countries by population
	 *   • All countries where WP commonly ships a locale
	 *
	 * Source: ISO 4217 official assignments as of 2026. Entries that
	 * shifted historically (HR/EE/LV/LT to EUR) reflect the current
	 * state, not the historical one — what matters here is the
	 * currency a fresh WC row should suggest TODAY.
	 *
	 * @var array<string, string>
	 */
	private const COUNTRY_TO_CURRENCY = [
		// Eurozone (alphabetical by country code).
		'AD' => 'EUR',
		'AT' => 'EUR',
		'BE' => 'EUR',
		'CY' => 'EUR',
		'DE' => 'EUR',
		'EE' => 'EUR',
		'ES' => 'EUR',
		'FI' => 'EUR',
		'FR' => 'EUR',
		'GR' => 'EUR',
		'HR' => 'EUR',
		'IE' => 'EUR',
		'IT' => 'EUR',
		'LT' => 'EUR',
		'LU' => 'EUR',
		'LV' => 'EUR',
		'MC' => 'EUR',
		'ME' => 'EUR',
		'MT' => 'EUR',
		'NL' => 'EUR',
		'PT' => 'EUR',
		'SI' => 'EUR',
		'SK' => 'EUR',
		'SM' => 'EUR',
		'VA' => 'EUR',
		'XK' => 'EUR',

		// Non-Eurozone Europe.
		'AL' => 'ALL',
		'BA' => 'BAM',
		'BG' => 'BGN',
		'BY' => 'BYN',
		'CH' => 'CHF',
		'CZ' => 'CZK',
		'DK' => 'DKK',
		'GB' => 'GBP',
		'GE' => 'GEL',
		'HU' => 'HUF',
		'IS' => 'ISK',
		'LI' => 'CHF',
		'MD' => 'MDL',
		'MK' => 'MKD',
		'NO' => 'NOK',
		'PL' => 'PLN',
		'RO' => 'RON',
		'RS' => 'RSD',
		'RU' => 'RUB',
		'SE' => 'SEK',
		'TR' => 'TRY',
		'UA' => 'UAH',

		// Americas.
		'AR' => 'ARS',
		'BO' => 'BOB',
		'BR' => 'BRL',
		'CA' => 'CAD',
		'CL' => 'CLP',
		'CO' => 'COP',
		'CR' => 'CRC',
		'CU' => 'CUP',
		'DO' => 'DOP',
		'EC' => 'USD',
		'GT' => 'GTQ',
		'HN' => 'HNL',
		'HT' => 'HTG',
		'JM' => 'JMD',
		'MX' => 'MXN',
		'NI' => 'NIO',
		'PA' => 'PAB',
		'PE' => 'PEN',
		'PR' => 'USD',
		'PY' => 'PYG',
		'SV' => 'USD',
		'TT' => 'TTD',
		'US' => 'USD',
		'UY' => 'UYU',
		'VE' => 'VES',

		// Asia / Oceania.
		'AE' => 'AED',
		'AF' => 'AFN',
		'AU' => 'AUD',
		'BD' => 'BDT',
		'BH' => 'BHD',
		'BN' => 'BND',
		'BT' => 'BTN',
		'CN' => 'CNY',
		'FJ' => 'FJD',
		'HK' => 'HKD',
		'ID' => 'IDR',
		'IL' => 'ILS',
		'IN' => 'INR',
		'IQ' => 'IQD',
		'IR' => 'IRR',
		'JO' => 'JOD',
		'JP' => 'JPY',
		'KH' => 'KHR',
		'KP' => 'KPW',
		'KR' => 'KRW',
		'KW' => 'KWD',
		'KZ' => 'KZT',
		'LA' => 'LAK',
		'LB' => 'LBP',
		'LK' => 'LKR',
		'MM' => 'MMK',
		'MN' => 'MNT',
		'MO' => 'MOP',
		'MV' => 'MVR',
		'MY' => 'MYR',
		'NP' => 'NPR',
		'NZ' => 'NZD',
		'OM' => 'OMR',
		'PG' => 'PGK',
		'PH' => 'PHP',
		'PK' => 'PKR',
		'QA' => 'QAR',
		'SA' => 'SAR',
		'SG' => 'SGD',
		'SY' => 'SYP',
		'TH' => 'THB',
		'TJ' => 'TJS',
		'TM' => 'TMT',
		'TW' => 'TWD',
		'UZ' => 'UZS',
		'VN' => 'VND',
		'YE' => 'YER',

		// Africa.
		'AO' => 'AOA',
		'CD' => 'CDF',
		'CI' => 'XOF',
		'CM' => 'XAF',
		'DZ' => 'DZD',
		'EG' => 'EGP',
		'ET' => 'ETB',
		'GH' => 'GHS',
		'KE' => 'KES',
		'LY' => 'LYD',
		'MA' => 'MAD',
		'MZ' => 'MZN',
		'NG' => 'NGN',
		'SN' => 'XOF',
		'TN' => 'TND',
		'TZ' => 'TZS',
		'UG' => 'UGX',
		'ZA' => 'ZAR',
		'ZW' => 'ZWL',
	];

	/**
	 * Fallback for bare locales (no region) — e.g. `pl`, `de`, `fr`.
	 * Maps each common bare language code to its conventional primary
	 * country, which is then looked up in COUNTRY_TO_CURRENCY.
	 *
	 * Order of preference for ambiguous ones:
	 *   • `en` → US (most WP installs anchor on en_US for the bare slug)
	 *   • `pt` → BR (more WP users than pt_PT)
	 *   • `es` → ES (canonical Spanish — Latin America is regionalised)
	 *   • `zh` → CN (most users; Taiwan + Hong Kong have their own locales)
	 *   • `ar` → SA (canonical reference Arabic)
	 *
	 * @var array<string, string>
	 */
	private const LANGUAGE_TO_COUNTRY = [
		'af'  => 'ZA',
		'ar'  => 'SA',
		'az'  => 'AZ',
		'be'  => 'BY',
		'bg'  => 'BG',
		'bn'  => 'BD',
		'bs'  => 'BA',
		'ca'  => 'ES',
		'cs'  => 'CZ',
		'cy'  => 'GB',
		'da'  => 'DK',
		'de'  => 'DE',
		'el'  => 'GR',
		'en'  => 'US',
		'es'  => 'ES',
		'et'  => 'EE',
		'eu'  => 'ES',
		'fa'  => 'IR',
		'fi'  => 'FI',
		'fil' => 'PH',
		'fr'  => 'FR',
		'gl'  => 'ES',
		'he'  => 'IL',
		'hi'  => 'IN',
		'hr'  => 'HR',
		'hu'  => 'HU',
		'hy'  => 'AM',
		'id'  => 'ID',
		'is'  => 'IS',
		'it'  => 'IT',
		'ja'  => 'JP',
		'ka'  => 'GE',
		'kk'  => 'KZ',
		'km'  => 'KH',
		'ko'  => 'KR',
		'ky'  => 'KG',
		'lo'  => 'LA',
		'lt'  => 'LT',
		'lv'  => 'LV',
		'mk'  => 'MK',
		'mn'  => 'MN',
		'ms'  => 'MY',
		'mt'  => 'MT',
		'my'  => 'MM',
		'nb'  => 'NO',
		'ne'  => 'NP',
		'nl'  => 'NL',
		'nn'  => 'NO',
		'no'  => 'NO',
		'pl'  => 'PL',
		'pt'  => 'BR',
		'ro'  => 'RO',
		'ru'  => 'RU',
		'si'  => 'LK',
		'sk'  => 'SK',
		'sl'  => 'SI',
		'sq'  => 'AL',
		'sr'  => 'RS',
		'sv'  => 'SE',
		'sw'  => 'KE',
		'ta'  => 'IN',
		'th'  => 'TH',
		'tl'  => 'PH',
		'tr'  => 'TR',
		'uk'  => 'UA',
		'ur'  => 'PK',
		'uz'  => 'UZ',
		'vi'  => 'VN',
		'zh'  => 'CN',
	];

	/**
	 * Best-guess ISO 4217 currency code for a language record.
	 *
	 * Inference order:
	 *   1. Region in the locale (`pl_PL` → PL → PLN, `en_GB` → GB → GBP).
	 *   2. Bare language code (`pl` → PL → PLN, `de` → DE → EUR).
	 *   3. Empty string if nothing matches — caller falls back to its
	 *      own default (the WooCommerce base currency).
	 *
	 * @param object $language Language record with at minimum `->locale`
	 *                         and `->slug` properties.
	 * @return string ISO 4217 alpha-3 code, or `''` if no inference.
	 */
	public static function guess_currency( object $language ): string {
		$locale = (string) ( $language->locale ?? '' );

		// 1. Region from locale: `pl_PL`, `en-GB`, `pt_BR`, etc.
		// Accept both `_` and `-` separators — WP normalises to `_` but
		// some imported data carries hyphens.
		if ( $locale !== '' ) {
			$parts = preg_split( '/[_-]/', $locale, 2 );
			if ( is_array( $parts ) && isset( $parts[1] ) ) {
				$region = strtoupper( substr( $parts[1], 0, 2 ) );
				if ( isset( self::COUNTRY_TO_CURRENCY[ $region ] ) ) {
					return self::COUNTRY_TO_CURRENCY[ $region ];
				}
			}

			// Locale with no region (`pl`, `de`) — fall through to step 2
			// using the locale's language part.
			$lang_code = strtolower( $parts[0] ?? '' );
		} else {
			$lang_code = '';
		}

		// 2. Bare language slug — try the language → country fallback.
		if ( $lang_code === '' ) {
			$lang_code = strtolower( (string) ( $language->slug ?? '' ) );
		}

		// Slug may be region-qualified too (`pt-br`, `en-gb`) — peel the
		// suffix off so we look up the base language.
		$lang_code = preg_split( '/[_-]/', $lang_code )[0] ?? '';

		if ( $lang_code !== '' && isset( self::LANGUAGE_TO_COUNTRY[ $lang_code ] ) ) {
			$country = self::LANGUAGE_TO_COUNTRY[ $lang_code ];
			if ( isset( self::COUNTRY_TO_CURRENCY[ $country ] ) ) {
				return self::COUNTRY_TO_CURRENCY[ $country ];
			}
		}

		return '';
	}
}
