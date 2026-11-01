<?php
/**
 * Language values that are not an active slug as written, loaded only when a caller passes one.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a slug in another case, a locale or a BCP 47 tag to a language; reports an unknown value once.
 */
final class LanguageValue {

	/**
	 * Frames skipped when naming the public caller in a notice.
	 */
	private const INTERNAL = [ 'PerfLocale\Helper::url_slug', 'PerfLocale\Helper::resolve_language', 'PerfLocale\Frontend\LanguageSwitcherBlock::render' ];

	/**
	 * Per-request memo per blog: the active languages by slug.
	 *
	 * @var array<int, array<object>>
	 */
	private static array $active = [];

	/**
	 * Per-request memo per blog: value => language row, or false when unknown.
	 *
	 * @var array<int, array<string, object|false|null>>
	 */
	private static array $found = [];

	/**
	 * Per-request memo per blog of by_locale(): value => language row, or false when none.
	 *
	 * @var array<int, array<string, object|false|null>>
	 */
	private static array $by_locale = [];

	/**
	 * Per-request set per blog of the values already reported.
	 *
	 * @var array<int, array<string, true>>
	 */
	private static array $reported = [];

	/**
	 * Drop the language memos after a language change or a blog switch (Helper::reset_static_caches()).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$active    = [];
		self::$found     = [];
		self::$by_locale = [];
	}

	/**
	 * The active (or current) slug a value names, else '' (reported once); {@see Helper::url_slug()}.
	 *
	 * With $base: the current slug when the value shares its base language ('fr' and fr_CA), else the value.
	 *
	 * @param string $value Language slug, locale or BCP 47 tag.
	 * @param bool   $base  Base-language match against the current language.
	 * @return string
	 */
	public static function slug( string $value, bool $base = false ): string {
		$current = Helper::get_instance()->current_language();
		$now     = $current !== null ? self::field( $current, 'slug' ) : '';

		if ( $value === '' || $value === $now ) {
			return $value;
		}

		$map = self::active();

		if ( ! $base ) {
			// '' for an unknown or inactive value: no URL may name a language that is not active.
			return self::field( self::find( $value, $map ) ?? (object) [], 'slug' );
		}

		$row    = $map[ $value ] ?? $map[ strtolower( $value ) ] ?? null;
		$locale = $row !== null ? self::field( $row, 'locale' ) : FormatLocale::canonical( $value );

		if ( $locale === '' ) {
			self::find( $value, $map );

			return $value;
		}

		return $current !== null && $now !== '' && self::base( $locale ) === self::base( self::field( $current, 'locale' ) ) ? $now : $value;
	}

	/**
	 * A language row by the locale a locale or tag names, from any configured language; null (reported) when none.
	 *
	 * @param object $repo  Language repository.
	 * @param string $value Value the slug lookup missed.
	 * @return object|null
	 */
	public static function by_locale( object $repo, string $value ): ?object {
		$blog = get_current_blog_id();
		$row  = &self::$by_locale[ $blog ][ $value ];

		if ( $row === null ) {
			$locale = FormatLocale::canonical( $value );
			$row    = ( $repo instanceof LanguageRepository && $locale !== '' ? $repo->find_by_locale( $locale ) : null ) ?? false;
		}

		// with_language() reports an unknown value itself; the reported check first spares a repeat the backtrace.
		if ( $row === false && ! isset( self::$reported[ $blog ][ $value ] ) && self::caller() !== 'PerfLocale\Helper::with_language' ) {
			self::report( $value );
		}

		return $row === false ? null : $row;
	}

	/**
	 * The switcher's languages after its include / exclude lists; the current language always stays.
	 *
	 * @param mixed                $languages  Switcher languages.
	 * @param array<string, mixed> $attributes Switcher attributes holding include and/or exclude.
	 * @return array<int, object>
	 */
	public static function pick( $languages, array $attributes ): array {
		$languages = array_filter( is_array( $languages ) ? $languages : [], 'is_object' );
		$map       = array_column( $languages, null, 'slug' );
		$current   = Helper::get_instance()->slug();
		$sets      = [];

		foreach ( [ 'include', 'exclude' ] as $key ) {
			$list = $attributes[ $key ] ?? [];
			$list = is_array( $list ) ? $list : explode( ',', is_scalar( $list ) ? (string) $list : '' );

			foreach ( $list as $value ) {
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				// Uncached: this map is the switcher's, not the active list find() memoizes.
				$row = $value === '' ? false : ( $map[ $value ] ?? self::lookup( $value, $map ) );

				if ( $row !== false ) {
					$sets[ $key ][ self::field( $row, 'slug' ) ] = true;
				}
			}
		}

		// A list that named only unknown values still filters: an include of nothing shows only the current language.
		$include = isset( $attributes['include'] ) && $attributes['include'] !== '' && $attributes['include'] !== [] ? ( $sets['include'] ?? [] ) : null;

		return array_values(
			array_filter(
				$languages,
				static function ( object $lang ) use ( $current, $include, $sets ): bool {
					$slug = self::field( $lang, 'slug' );

					return $slug === $current || ( ( $include === null || isset( $include[ $slug ] ) ) && ! isset( $sets['exclude'][ $slug ] ) );
				}
			)
		);
	}

	/**
	 * The language in $map that a slug in any case, or a locale or tag (exact locale), names; null (reported once).
	 *
	 * @param string        $value Language slug, locale or BCP 47 tag.
	 * @param array<object> $map   Languages by slug.
	 * @return object|null
	 */
	private static function find( string $value, array $map ): ?object {
		$memo   = &self::$found[ get_current_blog_id() ][ $value ];
		$memo ??= self::lookup( $value, $map );

		return $memo === false ? null : $memo;
	}

	/**
	 * Uncached {@see self::find()}; false when unknown.
	 *
	 * @param string        $value Language value.
	 * @param array<object> $map   Languages by slug.
	 * @return object|false
	 */
	private static function lookup( string $value, array $map ) {
		$slug = strtolower( $value );

		if ( isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}

		$locale = FormatLocale::canonical( $value );

		foreach ( $locale !== '' ? $map : [] as $row ) {
			if ( self::field( $row, 'locale' ) === $locale ) {
				return $row;
			}
		}

		self::report( $value );

		return false;
	}

	/**
	 * The active languages by slug, read once per request and blog; an empty list is not memoised.
	 *
	 * @return array<object>
	 */
	private static function active(): array {
		$memo = &self::$active[ get_current_blog_id() ];

		if ( ! $memo ) {
			$memo = array_column( Helper::get_instance()->languages(), null, 'slug' );
		}

		return $memo;
	}

	/**
	 * Report a language value that names no language, once per value per request, naming the public caller.
	 *
	 * @param string $value The value passed.
	 * @return void
	 */
	private static function report( string $value ): void {
		$blog = get_current_blog_id();

		if ( isset( self::$reported[ $blog ][ $value ] ) ) {
			return;
		}

		self::$reported[ $blog ][ $value ] = true;

		_doing_it_wrong(
			esc_html( self::caller() ),
			esc_html(
				sprintf(
					/* translators: %s: the language value that names no language. */
					__( 'Unknown or inactive language "%s": pass the slug or locale of an active language.', 'perflocale' ),
					$value
				)
			),
			'1.0.7.5'
		);
	}

	/**
	 * The public function or method that received the language value.
	 *
	 * @return string
	 */
	private static function caller(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Names the caller in a debug notice; cold path only.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {
			$class = $frame['class'] ?? '';
			$name  = $class !== '' ? $class . '::' . $frame['function'] : $frame['function'];

			if ( $class !== self::class && ! in_array( $name, self::INTERNAL, true ) ) {
				return $name;
			}
		}

		return self::class;
	}

	/**
	 * A string field of a language row; '' when missing.
	 *
	 * @param object $row  Language row.
	 * @param string $name Field.
	 * @return string
	 */
	private static function field( object $row, string $name ): string {
		$value = $row->{$name} ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The base language subtag of a locale ('fr' for fr_CA).
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	private static function base( string $locale ): string {
		return strtolower( explode( '_', $locale )[0] );
	}
}
