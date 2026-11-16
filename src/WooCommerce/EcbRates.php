<?php
/**
 * European Central Bank daily reference rates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The built-in `ecb` exchange-rate source: one fixed HTTPS file, EUR based, crossed to the store currency.
 *
 * Loaded only inside a rate sync (ExchangeRateSync::fetch_rates()).
 */
final class EcbRates {

	/**
	 * The ECB's daily reference-rate file.
	 */
	public const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/**
	 * Response cap; the file is about 1.5 KB, so a body this long is not it.
	 */
	private const MAX_BYTES = 65536;

	/**
	 * Most entries accepted (the ECB publishes about 30).
	 */
	private const MAX_ENTRIES = 64;

	/**
	 * Oldest reference date accepted, in days: a frozen file must not pass as fresh.
	 */
	private const MAX_AGE_DAYS = 14;

	/**
	 * Fetch the file and return rates per unit of $base for the covered $targets.
	 *
	 * @param string             $base    Store currency.
	 * @param array<int, string> $targets Target currency codes.
	 * @param int|null           $now     Clock for the date check; time() when null.
	 * @return array{0: array<string, float>, 1: string, 2: bool} Rates, the failure reason or a note ('' when none),
	 *                                                       and whether a failure is the store's setup (no retry helps).
	 */
	public static function fetch( string $base, array $targets, ?int $now = null ): array {
		$response = wp_safe_remote_get(
			self::URL,
			[
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				// Not core's default, which carries the site URL.
				'user-agent'          => 'PerfLocale',
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ [], 'European Central Bank: ' . $response->get_error_message(), false ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			return [ [], 'European Central Bank: HTTP ' . $code . '.', false ];
		}

		// At the cap the body may be cut short.
		if ( strlen( $body ) >= self::MAX_BYTES ) {
			return [ [], 'European Central Bank: the response is too large.', false ];
		}

		[ $eur, $error ] = self::parse( $body, $now ?? time() );

		if ( $error !== '' ) {
			return [ [], 'European Central Bank: ' . $error, false ];
		}

		[ $rates, $note ] = self::cross( $eur, $base, $targets );

		return [ $rates, $note !== '' ? 'European Central Bank: ' . $note : '', $rates === [] ];
	}

	/**
	 * Parse the reference-rate file into code => units per 1 EUR.
	 *
	 * Tag scan, not an XML parser: no extension needed and nothing is expanded. A malformed entry is skipped; a
	 * duplicate, an EUR row or a second dated block rejects the file.
	 *
	 * @param string $xml File body.
	 * @param int    $now Clock for the date check.
	 * @return array{0: array<string, float>, 1: string} Rates, and the reason when there are none.
	 */
	public static function parse( string $xml, int $now ): array {
		// The dated block, closed: a cut-off body has no closing tag.
		if ( preg_match( '~<Cube\s+time=([\'"])(\d{4})-(\d{2})-(\d{2})\1\s*>(.*?)</Cube>~s', $xml, $m ) !== 1 ) {
			return [ [], 'the file has no dated rate block.' ];
		}

		if ( preg_match_all( '~\btime=~', $xml ) !== 1 ) {
			return [ [], 'the file has more than one dated block.' ];
		}

		if ( ! checkdate( (int) $m[3], (int) $m[4], (int) $m[2] ) ) {
			return [ [], 'the reference date is not valid.' ];
		}

		$date = gmmktime( 0, 0, 0, (int) $m[3], (int) $m[4], (int) $m[2] );

		// A server clock may run behind the ECB's date by up to two days.
		if ( $date > $now + 2 * DAY_IN_SECONDS || $date < $now - self::MAX_AGE_DAYS * DAY_IN_SECONDS ) {
			return [ [], sprintf( 'the reference date %s-%s-%s is out of range.', $m[2], $m[3], $m[4] ) ];
		}

		if ( preg_match_all( '~<Cube\s([^<>]{1,200})/>~', $m[5], $tags ) === false || count( $tags[1] ) > self::MAX_ENTRIES ) {
			return [ [], 'the file has too many entries.' ];
		}

		$rates = [];

		foreach ( $tags[1] as $attrs ) {
			if (
				preg_match( '~\bcurrency=([\'"])([A-Z]{3})\1~', $attrs, $c ) !== 1
				|| preg_match( '~\brate=([\'"])(\d{1,7}(?:\.\d{1,8})?)\1~', $attrs, $r ) !== 1
			) {
				continue;
			}

			if ( isset( $rates[ $c[2] ] ) || $c[2] === 'EUR' ) {
				return [ [], 'the file lists ' . $c[2] . ' twice or as a rate.' ];
			}

			$rate = (float) $r[2];

			// Per 1 EUR: real rates lie far inside this range.
			if ( $rate >= 0.0001 && $rate <= 10000000.0 ) {
				$rates[ $c[2] ] = $rate;
			}
		}

		return $rates === [] ? [ [], 'the file has no usable rates.' ] : [ $rates, '' ];
	}

	/**
	 * Cross EUR-based rates to the store currency: rate(T) = eur[T] / eur[base].
	 *
	 * @param array<string, float> $eur     Code => units per 1 EUR.
	 * @param string               $base    Store currency.
	 * @param array<int, string>   $targets Target codes.
	 * @return array{0: array<string, float>, 1: string} Rates, and the failure or the codes without a rate.
	 */
	public static function cross( array $eur, string $base, array $targets ): array {
		$eur['EUR'] = 1.0;

		if ( ! isset( $eur[ $base ] ) ) {
			return [ [], sprintf( 'no reference rate for the store currency %s.', $base ) ];
		}

		$rates   = [];
		$missing = [];

		foreach ( $targets as $code ) {
			if ( $code === $base ) {
				continue;
			}

			if ( isset( $eur[ $code ] ) ) {
				$rates[ $code ] = $eur[ $code ] / $eur[ $base ];
			} else {
				$missing[] = $code;
			}
		}

		$note = $missing === [] ? '' : sprintf( 'no reference rate for %s; set a manual rate for it.', implode( ', ', $missing ) );

		return [ $rates, $note ];
	}
}
