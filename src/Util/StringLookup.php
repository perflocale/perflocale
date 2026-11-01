<?php
/**
 * Registered-string lookups of Helper::translate() and the perflocale/string/translate filter, loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\StringRepository;
use PerfLocale\Database\Repository\StringTranslationRepository;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a stored translation once per request and language, and runs the translate filter for every string service.
 */
final class StringLookup {

	/**
	 * Lookups kept per request; past this the memo starts over, so a long loop cannot grow it without bound.
	 */
	private const MAX = 500;

	/**
	 * Per-request memo: "blog|language id|domain|context|text" => translation, '' when none.
	 *
	 * @var array<string, string>
	 */
	private static array $memo = [];

	/**
	 * The perflocale_strings cache generation the memo was filled under; a bump (string merge, GC) empties it.
	 *
	 * @var int|null
	 */
	private static ?int $generation = null;

	/**
	 * The stored translation of a registered string in a language, filtered; '' when there is none.
	 *
	 * @param string $domain   Text domain.
	 * @param string $context  Gettext context.
	 * @param string $text     Source text.
	 * @param object $language Language row.
	 * @return string
	 */
	public static function find( string $domain, string $context, string $text, object $language ): string {
		$plugin = Plugin::get_instance();
		$cache  = $plugin->has( 'cache' ) ? $plugin->get( 'cache' ) : null;

		if ( ! $cache instanceof CacheManager ) {
			return '';
		}

		$generation = CacheManager::l2_generation( 'perflocale_strings' );

		if ( self::$generation !== $generation ) {
			// Saves announce perflocale/strings/changed; hooked once, here, so no request pays for it before a lookup.
			if ( self::$generation === null ) {
				add_action( 'perflocale/strings/changed', [ self::class, 'forget' ] );
			}

			self::$memo       = [];
			self::$generation = $generation;
		}

		$id  = self::int( $language->id ?? 0 );
		$key = get_current_blog_id() . '|' . $id . '|' . $domain . '|' . $context . '|' . $text;

		if ( ! isset( self::$memo[ $key ] ) ) {
			// Only a new key starts a full memo over: a held one is still answered.
			if ( count( self::$memo ) >= self::MAX ) {
				self::$memo = [];
			}

			$string = ( new StringRepository( $cache ) )->find_by_hash( $domain, $context, $text );
			$sid    = self::int( $string->id ?? 0 );

			self::$memo[ $key ] = $sid > 0 ? ( new StringTranslationRepository( $cache ) )->get( $sid, $id ) : '';
		}

		$slug = $language->slug ?? '';

		return self::$memo[ $key ] === '' ? '' : self::filtered( self::$memo[ $key ], $text, $domain, is_string( $slug ) ? $slug : '' );
	}

	/**
	 * A row id as an int; 0 when it is not numeric.
	 *
	 * @param mixed $value Raw id.
	 * @return int
	 */
	private static function int( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Drop the memo after a string save.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$memo = [];
	}

	/**
	 * A found translation through perflocale/string/translate, the one place every string service runs it.
	 *
	 * @param string $translated Translation.
	 * @param string $text       Source text.
	 * @param string $domain     Text domain.
	 * @param string $lang       Language slug of the translation.
	 * @return string The filtered translation; the unfiltered one when a callback returns a non-string.
	 */
	public static function filtered( string $translated, string $text, string $domain, string $lang ): string {
		if ( ! has_filter( 'perflocale/string/translate' ) ) {
			return $translated;
		}

		/**
		 * Filter a translated string before returning it.
		 *
		 * @hook perflocale/string/translate
		 * @param string $translated The translated text.
		 * @param string $text       The original text.
		 * @param string $domain     Text domain.
		 * @param string $lang       Slug of the language the translation is in.
		 */
		$filtered = apply_filters( 'perflocale/string/translate', $translated, $text, $domain, $lang );

		return FilterReturn::string( 'perflocale/string/translate', $filtered, $translated );
	}
}
