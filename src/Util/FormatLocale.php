<?php
/**
 * Language arguments, number, currency, date and time formatting and flag emoji of the Helper, loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Frontend\LocaleDateFormat;
use PerfLocale\Helper;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a language slug, locale or BCP 47 tag, formats numbers with intl, and renders dates in another locale.
 */
final class FormatLocale {

	/**
	 * Per-request memo per blog: value => [ locale, language row or null ], or false when unknown.
	 *
	 * @var array<int, array<string, array{0: string, 1: object|null}|false>>
	 */
	private static array $targets = [];

	/**
	 * Per-request WP_Locale of each other locale, or null when it has no language pack.
	 *
	 * @var array<string, \WP_Locale|null>
	 */
	private static array $wp_locales = [];

	/**
	 * Per-request NumberFormatter per locale, style and decimals; null when ICU rejects the locale.
	 *
	 * @var array<string, \NumberFormatter|null>
	 */
	private static array $formatters = [];

	/**
	 * Drop the language lookups after a language change; the WP_Locale and NumberFormatter memos stay.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$targets = [];
	}

	/**
	 * A language slug or locale as [ locale, language row or null ]; null when unknown (reported once).
	 *
	 * @param string $value  Language slug, locale or BCP 47 tag.
	 * @param string $method Public method to name in the notice.
	 * @return array{0: string, 1: object|null}|null
	 */
	public static function target( string $value, string $method ): ?array {
		$memo = self::$targets[ get_current_blog_id() ][ $value ] ?? self::lookup( $value, $method );

		return false === $memo ? null : $memo;
	}

	/**
	 * Uncached {@see self::target()}; false when unknown. Kept only when the language repository answered.
	 *
	 * @param string $value  Language slug, locale or BCP 47 tag.
	 * @param string $method Public method to name in the notice.
	 * @return array{0: string, 1: object|null}|false
	 */
	private static function lookup( string $value, string $method ) {
		$repo = null;
		$row  = null;

		try {
			$plugin = Plugin::get_instance();
			/** @var \PerfLocale\Database\Repository\LanguageRepository|null $repo */
			$repo = $plugin->has( 'lang_repo' ) ? $plugin->get( 'lang_repo' ) : null;
			// A slug wins any collision with a locale: slugs can look like de_DE.
			$row = $repo?->find_by_slug( $value );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$locale = self::canonical( $value );

		if ( is_object( $row ) && isset( $row->locale ) && is_string( $row->locale ) && $row->locale !== '' ) {
			$found = [ $row->locale, $row ];
		} elseif ( $locale !== '' ) {
			try {
				$row = $repo?->find_by_locale( $locale );
			} catch ( \Throwable $e ) {
				$row = null;
			}

			$found = [ $locale, is_object( $row ) ? $row : null ];
		} else {
			_doing_it_wrong(
				esc_html( $method ),
				esc_html(
					sprintf(
						/* translators: %s: the language value that could not be resolved. */
						__( 'Unknown language %s: the current language was used instead.', 'perflocale' ),
						$value
					)
				),
				'1.0.7.5'
			);

			$found = false;
		}

		// Before the repository exists a slug cannot be found: look again on the next call.
		if ( $repo !== null ) {
			self::$targets[ get_current_blog_id() ][ $value ] = $found;
		}

		return $found;
	}

	/**
	 * A locale or BCP 47 tag in WordPress form ('de-at' => 'de_AT', 'de-DE-formal' => 'de_DE_formal'), or ''.
	 *
	 * The same pattern with or without intl: ICU rejects valid WordPress locales such as me_ME.
	 *
	 * @param string $value Locale or tag.
	 * @return string
	 */
	public static function canonical( string $value ): string {
		if ( ! preg_match( '/^([a-z]{2,3})(?:[-_]([a-z]{4}))?(?:[-_]([a-z]{2}|\d{3}))?(?:[-_]([a-z\d]{4,8}))?$/i', $value, $m ) ) {
			return '';
		}

		$parts = [ strtolower( $m[1] ), ucfirst( strtolower( $m[2] ?? '' ) ), strtoupper( $m[3] ?? '' ), strtolower( $m[4] ?? '' ) ];

		return implode( '_', array_filter( $parts, static fn( string $part ): bool => $part !== '' ) );
	}

	/**
	 * Body of {@see Helper::flag_emoji()}: a language's flag emoji, '' for an unknown language.
	 *
	 * @param string|null $lang Language slug or locale; null for the current language.
	 * @return string
	 */
	public static function flag_emoji( ?string $lang ): string {
		$row = $lang === null || $lang === '' ? Helper::get_instance()->current_language() : ( self::target( $lang, 'PerfLocale\Helper::flag_emoji' )[1] ?? null );

		return $row ? Helper::get_flag_emoji( $row ) : '';
	}

	/**
	 * Body of {@see Helper::format_number()}.
	 *
	 * @param float|int   $value    Number.
	 * @param string|null $lang     Language slug or locale; null for the current language.
	 * @param int|null    $decimals Decimal-place override.
	 * @param string      $method   Public method to name in an unknown-value notice.
	 * @return string
	 */
	public static function number( $value, ?string $lang, ?int $decimals, string $method ): string {
		$locale    = self::locale_for( $lang, $method );
		$float     = (float) $value;
		$formatted = class_exists( '\\NumberFormatter' ) ? self::intl( $float, $locale, '', $decimals ) : '';

		if ( $formatted !== '' ) {
			return $formatted;
		}

		// Derive the decimals (max 3, as intl's DECIMAL style) so both paths keep the fraction.
		if ( $decimals === null ) {
			$trimmed  = rtrim( rtrim( number_format( $float, 3, '.', '' ), '0' ), '.' );
			$dot      = strpos( $trimmed, '.' );
			$decimals = ( false === $dot ) ? 0 : strlen( $trimmed ) - $dot - 1;
		}

		return self::separated( $float, max( 0, $decimals ), $locale );
	}

	/**
	 * Body of {@see Helper::format_currency()}; without intl "{code} {number}" with two decimals, never a guessed symbol.
	 *
	 * @param float|int   $value  Amount.
	 * @param string      $code   ISO 4217 code.
	 * @param string|null $lang   Language slug or locale; null for the current language.
	 * @param string      $method Public method to name in an unknown-value notice.
	 * @return string
	 */
	public static function currency( $value, string $code, ?string $lang, string $method ): string {
		$locale = self::locale_for( $lang, $method );
		$code   = strtoupper( trim( $code ) );

		if ( $code === '' ) {
			return (string) $value;
		}

		$formatted = class_exists( '\\NumberFormatter' ) ? self::intl( (float) $value, $locale, $code ) : '';

		return $formatted !== '' ? $formatted : $code . ' ' . self::separated( (float) $value, 2, $locale );
	}

	/**
	 * A locale from a language slug or locale, the current language, or get_locale(), in that order.
	 *
	 * @param string|null $lang   Language slug or locale.
	 * @param string      $method Public method to name in an unknown-value notice.
	 * @return string
	 */
	private static function locale_for( ?string $lang, string $method ): string {
		$target = $lang !== null && $lang !== '' ? self::target( $lang, $method ) : null;

		if ( $target ) {
			return $target[0];
		}

		$current = self::field( Helper::get_instance()->current_language(), 'locale' );

		return $current !== '' ? $current : get_locale();
	}

	/**
	 * A string field of a language row; '' when it is missing or not a string.
	 *
	 * @param object|null $row   Language row.
	 * @param string      $field Field name.
	 * @return string
	 */
	private static function field( ?object $row, string $field ): string {
		$value = $row->{$field} ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * A number with a locale's WordPress separators, as number_format_i18n() punctuates for the running locale.
	 *
	 * @param float  $value    Number.
	 * @param int    $decimals Decimal places.
	 * @param string $locale   Target locale.
	 * @return string
	 */
	private static function separated( float $value, int $decimals, string $locale ): string {
		$format = (array) ( self::wp_locale( $locale )->number_format ?? [] );
		$point  = $format['decimal_point'] ?? null;
		$sep    = $format['thousands_sep'] ?? null;

		// The running locale, or one without a language pack: number_format_i18n(), read per call as the locale can change.
		if ( ! is_string( $point ) || ! is_string( $sep ) ) {
			return number_format_i18n( $value, $decimals );
		}

		$formatted = number_format( $value, $decimals, $point, $sep );

		// Core's filter: this path stands in for number_format_i18n().
		$filtered = apply_filters( 'number_format_i18n', $formatted, $value, $decimals ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core 'number_format_i18n' filter; this method stands in for number_format_i18n() on the cross-locale path, so dropping it would make format_number( $x ) and format_number( $x, 'de' ) disagree on a site that customises number punctuation.

		return FilterReturn::string( 'number_format_i18n', $filtered, $formatted );
	}

	/**
	 * A number (or, with a currency code, an amount) through a memoised intl NumberFormatter; '' when intl cannot.
	 *
	 * Try/catch: on PHP 8.4 the constructor throws for locales ICU rejects (me_ME, nah ship in data/).
	 *
	 * @param float    $value    Number.
	 * @param string   $locale   Locale.
	 * @param string   $currency ISO 4217 code, or '' for a plain number.
	 * @param int|null $decimals Fraction digits, or null for the style's own.
	 * @return string
	 */
	public static function intl( float $value, string $locale, string $currency = '', ?int $decimals = null ): string {
		$style = $currency === '' ? \NumberFormatter::DECIMAL : \NumberFormatter::CURRENCY;
		$key   = $locale . '|' . $style . '|' . $decimals;

		if ( ! array_key_exists( $key, self::$formatters ) ) {
			try {
				$fmt = new \NumberFormatter( $locale, $style );
				if ( $decimals !== null ) {
					$fmt->setAttribute( \NumberFormatter::FRACTION_DIGITS, max( 0, $decimals ) );
				}
			} catch ( \Throwable $e ) {
				$fmt = null;
			}
			self::$formatters[ $key ] = $fmt;
		}

		try {
			$out = $currency === '' ? self::$formatters[ $key ]?->format( $value ) : self::$formatters[ $key ]?->formatCurrency( $value, $currency );
		} catch ( \Throwable $e ) {
			$out = null;
		}

		return is_string( $out ) ? $out : '';
	}

	/**
	 * Body of {@see Helper::date_format_for_language()}: the language's own format, else the site's date_format option.
	 *
	 * @param object|int|string|null $lang   Language row, ID, slug, locale, or null for the current language.
	 * @param string                 $method Public method to name in an unknown-value notice.
	 * @return string
	 */
	public static function date_format( $lang, string $method ): string {
		$row    = self::row( $lang, $method );
		$format = self::own_format( $row, 'date_format', 'F j, Y' );

		/**
		 * Filter the resolved date format for a language.
		 *
		 * @hook perflocale/date_format
		 * @param string      $format The format string about to be returned.
		 * @param object|null $lang   The language row, or null if none was found.
		 */
		$filtered = apply_filters( 'perflocale/date_format', $format, $row );

		return FilterReturn::string( 'perflocale/date_format', $filtered, $format );
	}

	/**
	 * Body of {@see Helper::time_format_for_language()}, as {@see self::date_format()}.
	 *
	 * @param object|int|string|null $lang   Language row, ID, slug, locale, or null for the current language.
	 * @param string                 $method Public method to name in an unknown-value notice.
	 * @return string
	 */
	public static function time_format( $lang, string $method ): string {
		$row    = self::row( $lang, $method );
		$format = self::own_format( $row, 'time_format', 'g:i a' );

		/**
		 * Filter the resolved time format for a language.
		 *
		 * @hook perflocale/time_format
		 * @param string      $format The format string about to be returned.
		 * @param object|null $lang   The language row, or null if none was found.
		 */
		$filtered = apply_filters( 'perflocale/time_format', $format, $row );

		return FilterReturn::string( 'perflocale/time_format', $filtered, $format );
	}

	/**
	 * Date format plus time format for the same language.
	 *
	 * @param object|int|string|null $lang   Language row, ID, slug, locale, or null for the current language.
	 * @param string                 $method Public method to name in an unknown-value notice.
	 * @return string
	 */
	public static function datetime_format( $lang, string $method ): string {
		return trim( self::date_format( $lang, $method ) . ' ' . self::time_format( $lang, $method ) );
	}

	/**
	 * A language row's own format, else the site option without the front end's current-language override.
	 *
	 * @param object|null $row    Language row.
	 * @param string      $option date_format or time_format.
	 * @param string      $core   Core's default.
	 * @return string
	 */
	private static function own_format( ?object $row, string $option, string $core ): string {
		$own = self::field( $row, $option );

		// A stored format holding markup ('<') is not used: the site option applies.
		if ( $own !== '' && ! str_contains( $own, '<' ) ) {
			return $own;
		}

		// Other plugins' option filters still apply.
		$loaded = class_exists( LocaleDateFormat::class, false );
		$held   = $loaded && LocaleDateFormat::$raw;

		if ( $loaded ) {
			LocaleDateFormat::$raw = true;
		}

		try {
			$site = get_option( $option, $core );

			return is_string( $site ) ? $site : $core;
		} finally {
			if ( $loaded ) {
				LocaleDateFormat::$raw = $held;
			}
		}
	}

	/**
	 * A language row, id, slug, locale or null (current, else default) as a language row; null for a locale no language uses.
	 *
	 * @param object|int|string|null $lang   Language identifier.
	 * @param string                 $method Public method to name in an unknown-value notice.
	 * @return object|null
	 */
	private static function row( $lang, string $method ): ?object {
		if ( is_object( $lang ) ) {
			return $lang;
		}

		if ( is_string( $lang ) && $lang !== '' ) {
			$target = self::target( $lang, $method );

			if ( $target ) {
				return $target[1];
			}

			// Unknown, and reported: the current language applies.
			$lang = null;
		}

		try {
			$current = $lang === null || $lang === '' ? Helper::get_instance()->current_language() : null;

			if ( $current ) {
				return $current;
			}

			$plugin = Plugin::get_instance();
			$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

			if ( ! $cache instanceof CacheManager ) {
				return null;
			}

			$repo = new LanguageRepository( $cache );

			return $lang === null || $lang === '' ? $repo->get_default() : $repo->find( $lang );
		} catch ( \Throwable $e ) {
			// Bootstrap not ready, or not an id: fall through to global defaults.
			unset( $e );
		}

		return null;
	}

	/**
	 * Body of {@see Helper::format_date()} and its siblings: $format rendered with the language's month and day names.
	 *
	 * Without a language the current one applies, so a with_language() window renders its own names.
	 *
	 * @param int|string|\DateTimeInterface|null $timestamp Unix timestamp, date string (site time unless it has an offset), or null for now.
	 * @param string                             $format    Date format.
	 * @param string|null                        $lang      Language slug or locale, already reported by the format call.
	 * @return string '' on parse failure.
	 */
	public static function timestamp( $timestamp, string $format, ?string $lang ): string {
		$ts = self::unix( $timestamp );

		if ( $ts === null ) {
			return '';
		}

		$locale = $lang !== null && $lang !== '' ? ( self::target( $lang, __METHOD__ )[0] ?? '' ) : '';

		if ( $locale === '' ) {
			$locale = self::field( Helper::get_instance()->current_language(), 'locale' );
		}

		global $wp_locale;

		$running = $wp_locale;

		try {
			$wp_locale = self::wp_locale( $locale ) ?? $running; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored in finally; wp_date() reads only this global.

			return (string) wp_date( $format, $ts );
		} finally {
			$wp_locale = $running; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the running locale.
		}
	}

	/**
	 * A caller-supplied timestamp as a Unix int; null on failure. A date string without an offset is site time.
	 *
	 * @param int|string|\DateTimeInterface|null $timestamp Timestamp.
	 * @return int|null
	 */
	private static function unix( $timestamp ): ?int {
		if ( $timestamp === null || $timestamp === '' ) {
			return time();
		}

		if ( $timestamp instanceof \DateTimeInterface ) {
			return $timestamp->getTimestamp();
		}

		if ( is_int( $timestamp ) ) {
			return $timestamp;
		}

		if ( is_numeric( $timestamp ) ) {
			return (int) $timestamp;
		}

		// The parser stops at a NUL byte and reads the rest as now: such a string is no date.
		if ( str_contains( $timestamp, "\0" ) ) {
			return null;
		}

		try {
			$parsed = date_create_immutable( $timestamp, wp_timezone() );
		} catch ( \ValueError $e ) {
			$parsed = false;
		}

		return $parsed ? $parsed->getTimestamp() : null;
	}

	/**
	 * The WP_Locale of a locale other than the running one, switched to once per request; null otherwise or without a pack.
	 *
	 * @param string $locale Locale.
	 * @return \WP_Locale|null
	 */
	private static function wp_locale( string $locale ): ?object {
		global $wp_locale;

		$running = self::running_locale();

		if ( $locale === '' || $locale === $running || ! function_exists( 'switch_to_locale' ) ) {
			return null;
		}

		if ( ! array_key_exists( $locale, self::$wp_locales ) ) {
			// Core skips a switch to determine_locale(), which a front-end window filters to its language: pin the running one.
			$pin = static fn() => $running;

			add_filter( 'pre_determine_locale', $pin );

			try {
				$switched = switch_to_locale( $locale );
			} finally {
				remove_filter( 'pre_determine_locale', $pin );
			}

			// A failed switch is kept as null, never as the running locale's data under this locale's name.
			self::$wp_locales[ $locale ] = $switched && $wp_locale instanceof \WP_Locale ? $wp_locale : null;

			if ( $switched ) {
				restore_previous_locale();
			}
		}

		return self::$wp_locales[ $locale ];
	}

	/**
	 * The locale the running $wp_locale was built for: the switched one, else the one the request started with.
	 *
	 * Not determine_locale(): the router's `locale` filter names a with_language() window's locale before any switch.
	 *
	 * @return string
	 */
	private static function running_locale(): string {
		$switcher = $GLOBALS['wp_locale_switcher'] ?? null;

		if ( ! $switcher instanceof \WP_Locale_Switcher ) {
			return determine_locale();
		}

		$switched = $switcher->get_switched_locale();

		if ( is_string( $switched ) && $switched !== '' ) {
			return $switched;
		}

		// Captured at the end of setup_theme, right before core builds $wp_locale (Bootstrap).
		$boot = $GLOBALS['perflocale_boot_locale'] ?? '';

		return is_string( $boot ) && $boot !== '' ? $boot : determine_locale();
	}
}
