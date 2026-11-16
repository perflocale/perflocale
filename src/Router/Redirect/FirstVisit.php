<?php
/**
 * Shared gate of the first-visit redirects (browser, geo, edge hint).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\Redirect;

use PerfLocale\Router\LanguageRouter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which request a first-visit rule may steer, and one verdict per request; loaded by literal require from those rules.
 *
 * @since 1.0.8
 */
final class FirstVisit {

	/**
	 * A rule reached a verdict (stay or send) on this request: later rules stand down, so the admin's order decides.
	 *
	 * @var bool
	 */
	private static bool $decided = false;

	/**
	 * Whether this default-language request can be steered (no language cookie, not a bot); only that answer is no-store.
	 *
	 * A decided visitor and a bot get the page's own cache headers: their answer never depends on the visitor.
	 *
	 * @param LanguageRouter $router Router.
	 * @return bool
	 */
	public static function steerable( LanguageRouter $router ): bool {
		if ( self::$decided || isset( $_COOKIE['perflocale_lang'] ) || ! $router->is_default_language() ) {
			return false;
		}

		$ua = $_SERVER['HTTP_USER_AGENT'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed and sanitized at its use below.

		if ( is_string( $ua ) && LanguageRouter::is_bot_ua( sanitize_text_field( wp_unslash( $ua ) ) ) ) {
			return false;
		}

		CacheHeaders::restrict_page();

		return true;
	}

	/**
	 * The URL without its archive page (`/page/N/` and `paged`): the other language may have fewer pages (404).
	 *
	 * @param string $url Current request URL.
	 * @return string
	 */
	public static function unpaged( string $url ): string {
		global $wp_rewrite;

		if ( ! is_paged() ) {
			return $url;
		}

		$base  = $wp_rewrite instanceof \WP_Rewrite ? $wp_rewrite->pagination_base : 'page';
		$parts = explode( '?', $url, 2 );
		$segs  = explode( '/', rtrim( $parts[0], '/' ) );
		$n     = count( $segs );

		// Decoded, as WordPress matches the request (`/p%61ge/%33/`); 4 pieces = scheme and host. The query needs no
		// decoding: callers pass current_request_url(), whose add_query_arg() already decodes `%70aged` to `paged`.
		if ( $n > 4 && urldecode( $segs[ $n - 2 ] ) === $base && \PerfLocale\Helper::is_ascii_digits( urldecode( $segs[ $n - 1 ] ) ) ) {
			$parts[0] = implode( '/', array_slice( $segs, 0, -2 ) ) . '/';
		}

		$url = $parts[0] . ( isset( $parts[1] ) ? '?' . $parts[1] : '' );

		return isset( $parts[1] ) && str_contains( $parts[1], 'paged=' ) ? remove_query_arg( 'paged', $url ) : $url;
	}

	/**
	 * Whether a rule reached a verdict on this request.
	 *
	 * @return bool
	 */
	public static function decided(): bool {
		return self::$decided;
	}

	/**
	 * Record this request's verdict.
	 *
	 * @return void
	 */
	public static function decide(): void {
		self::$decided = true;
	}
}
