<?php
/**
 * Accept-Language to language-slug matching.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps Accept-Language to an active language slug; loaded only when browser detection reads the header.
 *
 * Per tag: locale tag (exact, truncated, script dropped, zh script twin), a slug of the same base, then the base: a bare
 * tag the default language shares goes there, else the base's default region or bare locale, the default language, the
 * first by sort order. Refused (q=0) languages are skipped. Mirrored by assets/js/edge-helper.js (shared cases in tests/).
 *
 * @since 1.0.8
 */
final class AcceptLanguageMatcher {

	/** Default region where it is not the base itself (de -> de); only picks among several languages of one base. */
	private const DEFAULT_REGION = [
		'en' => 'us',
		'zh' => 'cn',
		'ja' => 'jp',
		'ko' => 'kr',
		'sv' => 'se',
		'da' => 'dk',
		'el' => 'gr',
		'cs' => 'cz',
		'uk' => 'ua',
		'he' => 'il',
		'nb' => 'no',
		'nn' => 'no',
		'sr' => 'rs',
		'sl' => 'si',
		'et' => 'ee',
		'ms' => 'my',
		'sq' => 'al',
		'bs' => 'ba',
	];

	/** Most Accept-Language entries read, whatever perflocale/accept_language_limit returns. */
	private const MAX_ENTRIES = 100;

	/** Deprecated or macrolanguage codes browsers still send. */
	private const BASE_ALIAS = [
		'no' => 'nb',
		'iw' => 'he',
		'in' => 'id',
	];

	/** Locale tag shape: base, second subtag, third subtag. */
	private const LOCALE_RE = '/^([a-z]{2,3})(?:-([a-z\d]{1,8}))?(-[a-z\d]{1,8})?(?:-[a-z\d]{1,8})*$/';

	/** Chinese region -> script, so zh-TW meets zh_Hant and zh-Hant meets zh_TW. */
	private const ZH_SCRIPT = [
		'cn' => 'hans',
		'sg' => 'hans',
		'my' => 'hans',
		'tw' => 'hant',
		'hk' => 'hant',
		'mo' => 'hant',
	];

	/**
	 * Slug map the index was built from (compared by identity: same request, same rows).
	 *
	 * @var array<string, object>|null
	 */
	private static ?array $map = null;

	/**
	 * Default slug the index was built with.
	 *
	 * @var string
	 */
	private static string $default = '';

	/**
	 * Lowercase locale tag => slug, region-derived Chinese script tag => slug, base => picked slug, default's base.
	 *
	 * @var array{tags: array<string, string>, twins: array<string, string>, bases: array<string, string>, default_base: string}
	 */
	private static array $index = [
		'tags'         => [],
		'twins'        => [],
		'bases'        => [],
		'default_base' => '',
	];

	/**
	 * The active language slug the header prefers, or null.
	 *
	 * @param string                $header       Sanitized Accept-Language value.
	 * @param array<string, object> $slug_map     Active languages by slug, in sort order.
	 * @param object|null           $default_lang Default language row.
	 * @return string|null A key of $slug_map.
	 */
	public static function match( string $header, array $slug_map, ?object $default_lang ): ?string {
		[ $tags, $refused ] = self::parse( $header );

		if ( $tags === [] ) {
			return null;
		}

		$default_slug = $default_lang->slug ?? '';
		$default_slug = is_string( $default_slug ) ? $default_slug : '';

		if ( self::$map !== $slug_map || self::$default !== $default_slug ) {
			self::build( $slug_map, $default_slug );
		}

		$blocked = $refused === [] ? [] : self::blocked( $refused, $tags, $slug_map );

		foreach ( $tags as $tag ) {
			$slug = self::match_tag( $tag, $slug_map, $blocked );

			if ( $slug !== null ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Accepted tags by quality (equal q in header order) and refused (q=0) tags; a repeated tag keeps its first weight.
	 *
	 * @param string $header Accept-Language value.
	 * @return array{0: array<int, string>, 1: array<int, string>}
	 */
	private static function parse( string $header ): array {
		/**
		 * Filter the maximum number of Accept-Language entries parsed.
		 *
		 * The limit defends against crafted headers; clamped to 1-100.
		 *
		 * @hook perflocale/accept_language_limit
		 * @param int $limit Default 20.
		 */
		$raw     = apply_filters( 'perflocale/accept_language_limit', 20 );
		$raw     = is_numeric( $raw ) && ! is_nan( (float) $raw ) ? (float) $raw : 20.0; // @phpstan-ignore function.alreadyNarrowedType (a callback may return anything)
		$limit   = (int) max( 1.0, min( (float) self::MAX_ENTRIES, $raw ) );
		$tags    = [];
		$refused = [];

		// explode()'s limit keeps a huge header to $limit + 1 pieces; the last holds the unread rest.
		foreach ( array_slice( explode( ',', $header, $limit + 1 ), 0, $limit ) as $part ) {
			if ( ! preg_match( '/^\s*([a-z]{1,8})((?:[-_][a-z\d]{1,8}){0,7})\s*(?:;\s*q\s*=\s*([\d.]{1,6})\s*)?$/i', $part, $m ) ) {
				continue;
			}

			$base = strtolower( $m[1] );
			$tag  = ( self::BASE_ALIAS[ $base ] ?? $base ) . strtolower( strtr( $m[2], '_', '-' ) );
			$q    = isset( $m[3] ) ? min( 1.0, (float) $m[3] ) : 1.0;

			if ( isset( $tags[ $tag ] ) ) {
				continue;
			}

			// A refused tag also listed as accepted stays refused: blocked() lets the refusal win a tie.
			if ( $q > 0 ) {
				$tags[ $tag ] = $q;
			} else {
				$refused[ $tag ] = 0.0;
			}
		}

		arsort( $tags );

		return [ array_map( 'strval', array_keys( $tags ) ), array_map( 'strval', array_keys( $refused ) ) ];
	}

	/**
	 * Slugs whose most specific matching range (RFC 4647 basic filtering) in the header is refused.
	 *
	 * @param array<int, string>    $refused  Refused tags.
	 * @param array<int, string>    $accepted Accepted tags.
	 * @param array<string, object> $slug_map Active languages by slug.
	 * @return array<string, true>
	 */
	private static function blocked( array $refused, array $accepted, array $slug_map ): array {
		$blocked = [];

		foreach ( $slug_map as $slug => $lang ) {
			$tag  = self::locale_tag( $lang );
			$best = -1;
			$no   = false;

			foreach ( [ $refused, $accepted ] as $i => $ranges ) {
				foreach ( $ranges as $range ) {
					$len = substr_count( $range, '-' );

					if ( $len > $best && ( $tag === $range || str_starts_with( $tag, $range . '-' ) ) ) {
						$best = $len;
						$no   = $i === 0;
					}
				}
			}

			if ( $no ) {
				$blocked[ (string) $slug ] = true;
			}
		}

		return $blocked;
	}

	/**
	 * A language's lowercase locale tag (de_AT -> de-at).
	 *
	 * @param object $lang Language row.
	 * @return string
	 */
	private static function locale_tag( object $lang ): string {
		$locale = $lang->locale ?? '';

		return strtolower( strtr( is_string( $locale ) ? $locale : '', '_@', '--' ) );
	}

	/**
	 * Build the index from the active languages.
	 *
	 * @param array<string, object> $slug_map     Active languages by slug, in sort order.
	 * @param string                $default_slug Default language slug.
	 * @return void
	 */
	private static function build( array $slug_map, string $default_slug ): void {
		$tags  = [];
		$short = [];
		$twins = [];
		$bases = [];
		$held  = [];

		$default_base = '';

		foreach ( $slug_map as $slug => $lang ) {
			$slug = (string) $slug;
			$tag  = self::locale_tag( $lang );

			if ( ! preg_match( self::LOCALE_RE, $tag, $m ) ) {
				continue;
			}

			$base   = $m[1];
			$second = $m[2] ?? '';
			$script = strlen( $second ) === 4;
			$region = $script ? substr( $m[3] ?? '', 1 ) : $second;
			$want   = self::DEFAULT_REGION[ $base ] ?? $base;

			$tags[ $tag ] ??= $slug;

			if ( $slug === $default_slug ) {
				$default_base = $base;
			}

			// Base pick: the default region (a bare locale counts) holds; else the default language; else the first.
			if ( ! isset( $held[ $base ] ) ) {
				if ( $region === $want || $second === '' ) {
					$bases[ $base ] = $slug;
					$held[ $base ]  = true;
				} elseif ( $slug === $default_slug || ! isset( $bases[ $base ] ) ) {
					$bases[ $base ] = $slug;
				}
			}

			if ( ( $m[3] ?? '' ) !== '' ) {
				$parts = explode( '-', $tag );

				for ( $i = count( $parts ) - 1; $i >= 2; $i-- ) {
					$short[ implode( '-', array_slice( $parts, 0, $i ) ) ] ??= $slug;
				}
			}

			if ( $base === 'zh' && ! $script && isset( self::ZH_SCRIPT[ $region ] ) ) {
				$twins[ 'zh-' . self::ZH_SCRIPT[ $region ] ] ??= $slug;
			}
		}

		self::$map     = $slug_map;
		self::$default = $default_slug;
		self::$index   = [
			'tags'         => $tags + $short,
			'twins'        => $twins,
			'bases'        => $bases,
			'default_base' => $default_base,
		];
	}

	/**
	 * One tag's slug, or null.
	 *
	 * @param string                $tag      Lowercase language tag.
	 * @param array<string, object> $slug_map Active languages by slug.
	 * @param array<string, true>   $blocked  Refused slugs.
	 * @return string|null
	 */
	private static function match_tag( string $tag, array $slug_map, array $blocked ): ?string {
		$parts = explode( '-', $tag );
		$n     = count( $parts );
		$base  = $parts[0];
		$cands = [ $tag ];

		for ( $i = $n - 1; $i >= 2; $i-- ) {
			$cands[] = implode( '-', array_slice( $parts, 0, $i ) );
		}

		$script = $n > 1 && strlen( $parts[1] ) === 4;

		if ( $script && $n > 2 ) {
			$cands[] = $base . '-' . $parts[2];
		}

		if ( $base === 'zh' && $n > 1 && isset( self::ZH_SCRIPT[ $parts[ $script ? 2 : 1 ] ?? '' ] ) ) {
			$cands[] = 'zh-' . self::ZH_SCRIPT[ $parts[ $script ? 2 : 1 ] ];
		}

		foreach ( [ 'tags', 'twins' ] as $level ) {
			foreach ( $cands as $cand ) {
				$hit = self::$index[ $level ][ $cand ] ?? null;

				if ( $hit !== null && ! isset( $blocked[ $hit ] ) ) {
					return $hit;
				}
			}
		}

		// A slug is no language tag: "br" (Breton) must not reach pt_BR's slug br.
		if ( $n > 1 && isset( $slug_map[ $tag ] ) && ! isset( $blocked[ $tag ] )
			&& preg_match( self::LOCALE_RE, self::locale_tag( $slug_map[ $tag ] ), $m ) && $m[1] === $base ) {
			return $tag;
		}

		if ( $n === 1 && $base === self::$index['default_base'] && ! isset( $blocked[ self::$default ] ) ) {
			return self::$default;
		}

		$pick = self::$index['bases'][ $base ] ?? null;

		return $pick === null || ! isset( $blocked[ $pick ] ) ? $pick : self::pick_base( $base, $slug_map, $blocked );
	}

	/**
	 * The base pick without refused languages (the index holds the unrefused pick).
	 *
	 * @param string                $base     Language subtag.
	 * @param array<string, object> $slug_map Active languages by slug, in sort order.
	 * @param array<string, true>   $blocked  Refused slugs.
	 * @return string|null
	 */
	private static function pick_base( string $base, array $slug_map, array $blocked ): ?string {
		$want = self::DEFAULT_REGION[ $base ] ?? $base;
		$pick = null;

		foreach ( $slug_map as $slug => $lang ) {
			$slug = (string) $slug;

			if ( isset( $blocked[ $slug ] ) || ! preg_match( self::LOCALE_RE, self::locale_tag( $lang ), $m ) || $m[1] !== $base ) {
				continue;
			}

			$second = $m[2] ?? '';
			$region = strlen( $second ) === 4 ? substr( $m[3] ?? '', 1 ) : $second;

			if ( $region === $want || $second === '' ) {
				return $slug;
			}

			if ( $pick === null || $slug === self::$default ) {
				$pick = $slug;
			}
		}

		return $pick;
	}
}
