<?php
/**
 * Rule #9 off the canonical URL: suffix, base-only test and target.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

use PerfLocale\Router\UrlBases\Runtime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: UrlConverter::term_redirect_target() loads it only for a term archive request off its canonical path.
 *
 * @since 1.0.8
 */
final class TermRedirect {

	/**
	 * Core's canonical redirect was cancelled for rule #9 in this request.
	 *
	 * @var bool
	 */
	private static bool $yielded = false;

	/**
	 * core_yields() answers false from now on (core_again() runs core once more).
	 *
	 * @var bool
	 */
	private static bool $off = false;

	/**
	 * The canonical URL plus the request's feed or page suffix and query, or null when the difference is not the term's.
	 *
	 * In query mode the request's query args are merged into the canonical's, whose values win; otherwise the query
	 * string is appended verbatim.
	 *
	 * @param string $canonical   The term's link (get_term_link()).
	 * @param string $request_uri The URI judged (escaped request URI, or another URL of this request).
	 * @return string|null
	 */
	public static function target( string $canonical, string $request_uri ): ?string {
		$canonical_path = untrailingslashit( (string) wp_parse_url( $canonical, PHP_URL_PATH ) );
		$request_path   = untrailingslashit( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) );
		$qpos           = strpos( $request_uri, '?' );

		// Feed and page 2+ suffix, recognised only where redirect_canonical() would, so a term slugged `feed`
		// is never cut. Patterns come from $wp_rewrite (add_feed(), pagination base) and anchor on the end.
		global $wp_rewrite;

		$suffix         = '';
		$suffix_pattern = '';

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			$paged = get_query_var( 'paged' );

			if ( is_feed() && ! empty( $wp_rewrite->feeds ) ) {
				$feeds          = implode( '|', array_map( static fn( $feed ): string => preg_quote( (string) $feed, '#' ), (array) $wp_rewrite->feeds ) );
				$suffix_pattern = '/(?:' . preg_quote( (string) $wp_rewrite->feed_base, '#' ) . '/)?(?:' . $feeds . ')';
			} elseif ( is_numeric( $paged ) && (int) $paged > 1 ) {
				$suffix_pattern = '/' . preg_quote( (string) $wp_rewrite->pagination_base, '#' ) . '/?\d+';
			}
		}

		if ( self::at_canonical( $request_path, $canonical_path, $suffix_pattern ) ) {
			return null;
		}

		if ( $suffix_pattern !== '' && preg_match( '#' . $suffix_pattern . '$#', $request_path, $suffix_match ) === 1 ) {
			$suffix = $suffix_match[0];
		}

		// Only the URL base differs: rule #15's hop (it honours the base redirect switch and perflocale_alias).
		// Runtime is loaded only when a map exists; Runtime::class names it without loading it.
		if ( class_exists( Runtime::class, false ) ) {
			$base = Runtime::canonicalize_url( $request_uri );

			if ( self::at_canonical( untrailingslashit( (string) wp_parse_url( $base, PHP_URL_PATH ) ), $canonical_path, $suffix_pattern ) ) {
				return null;
			}
		}

		// Canonical URL plus the request's suffix. Loop-proof: the target's base is $canonical_path.
		$query_pos       = strpos( $canonical, '?' );
		$target          = $query_pos !== false ? substr( $canonical, 0, $query_pos ) : $canonical;
		$canonical_query = $query_pos !== false ? substr( $canonical, $query_pos + 1 ) : '';

		if ( $suffix !== '' ) {
			$target = str_ends_with( $target, '/' ) ? untrailingslashit( $target ) . $suffix . '/' : $target . $suffix;
		}

		if ( $canonical_query === '' ) {
			// Keep UTM, tracking and preview params, appended verbatim.
			if ( $qpos !== false ) {
				$target .= substr( $request_uri, $qpos );
			}
		} else {
			// Query URL mode: appending would add a second `?` whose `lang` matches nothing. Drop the
			// canonical's keys from the request args, then add the canonical's, so its language wins.
			$canonical_args = [];
			wp_parse_str( $canonical_query, $canonical_args );

			$request_query = $qpos !== false ? substr( $request_uri, $qpos ) : '';
			$target        = add_query_arg(
				urlencode_deep( $canonical_args ),
				$target . remove_query_arg( array_map( 'strval', array_keys( $canonical_args ) ), $request_query )
			);
		}

		return $target;
	}

	/**
	 * Whether core's redirect_canonical yields to rule #9 (Q20, E8): its target would get #9's hop again.
	 *
	 * Only for the request itself: core's chain check (redirect_canonical( $target, false )) and other callers keep
	 * core's answer.
	 *
	 * @param mixed $redirect_url  Core's target.
	 * @param mixed $requested_url The URL core judged.
	 * @return bool
	 */
	public static function core_yields( $redirect_url, $requested_url ): bool {
		$terms = \PerfLocale\Plugin::get_instance()->url_converter();
		$path  = static fn( $url ): string => (string) wp_parse_url( is_string( $url ) ? $url : '', PHP_URL_PATH );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Core builds the request's own URL from the raw REQUEST_URI; only compared with it.
		$uri           = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		self::$yielded = ! self::$off && is_string( $redirect_url ) && $path( $requested_url ) === $path( $uri )
			&& null !== $terms->term_redirect_target( $redirect_url ) && null !== $terms->term_redirect_target();

		return self::$yielded;
	}

	/**
	 * Rule #9 sent nothing (the sender declined): run core's canonical redirect it had cancelled, so a hop still happens.
	 *
	 * @return void
	 */
	public static function core_again(): void {
		if ( self::$yielded && function_exists( 'redirect_canonical' ) ) {
			self::$off = true;
			redirect_canonical();
		}
	}

	/**
	 * Whether a path is the canonical one, or it plus a whole feed or page suffix (a term slugged `feed` keeps `/feed/feed/`).
	 *
	 * @param string $path           Untrailingslashed path.
	 * @param string $canonical      Untrailingslashed canonical path.
	 * @param string $suffix_pattern Suffix regex, '' for none.
	 * @return bool
	 */
	private static function at_canonical( string $path, string $canonical, string $suffix_pattern ): bool {
		return $path === $canonical || ( $suffix_pattern !== '' && str_starts_with( $path, $canonical . '/' ) && preg_match( '#^' . $suffix_pattern . '$#', substr( $path, strlen( $canonical ) ) ) === 1 );
	}
}
