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
 * Best-guess ISO 4217 currency from a language's locale: the first value of a new Exchange Rates row.
 *
 * @internal WC settings UI only, not @api: the map follows ISO 4217, and a stored wc_currencies row always wins.
 */
final class LocaleCurrency {

	/**
	 * ISO 3166-1 alpha-2 country code to the ISO 4217 currency it uses today.
	 *
	 * @var array<string, string>
	 */
	private const COUNTRY_TO_CURRENCY = [
		// Eurozone (alphabetical by country code).
		'AD' => 'EUR',
		'AT' => 'EUR',
		'BE' => 'EUR',
		'BG' => 'EUR',
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
	 * Primary country of a bare language code (`pl`, `de`), for locales without a region.
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
	 * Best-guess ISO 4217 currency for a language: the locale's region, then its bare language, else ''.
	 *
	 * @param object $language Language record with `->locale` and `->slug`.
	 * @return string ISO 4217 alpha-3 code, or `''` if no inference.
	 */
	public static function guess_currency( object $language ): string {
		$locale = (string) ( $language->locale ?? '' );

		// 1. Region from the locale; imported data may use `-` instead of `_`.
		if ( $locale !== '' ) {
			$parts = preg_split( '/[_-]/', $locale, 2 );
			if ( is_array( $parts ) && isset( $parts[1] ) ) {
				$region = strtoupper( substr( $parts[1], 0, 2 ) );
				if ( isset( self::COUNTRY_TO_CURRENCY[ $region ] ) ) {
					return self::COUNTRY_TO_CURRENCY[ $region ];
				}
			}

			// No region (`pl`): step 2 uses the locale's language part.
			$lang_code = strtolower( $parts[0] ?? '' );
		} else {
			$lang_code = '';
		}

		// 2. Language to primary country; the slug when the locale gave no language.
		if ( $lang_code === '' ) {
			$lang_code = strtolower( (string) ( $language->slug ?? '' ) );
		}

		// A slug may carry a region (`pt-br`): keep the base language.
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
