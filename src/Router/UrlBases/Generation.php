<?php
/**
 * Rules stored after the compile: the route core picks for the original path wins until the recompile (OQ1).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

use PerfLocale\Router\UrlConverter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: Swap loads it only when the stored rules are not the generation the map was compiled against (map `rg`).
 *
 * @since 1.0.8
 */
final class Generation {

	/**
	 * Query vars of a generic catch-all, as the compiler's Rules::GENERIC_VARS.
	 */
	private const GENERIC = [ 'pagename', 'name', 'attachment', 'attachment_id', 'page', 'paged', 'feed', 'embed', 'tb', 'cpage', 'p', 'post_type', 'preview', 'withcomments', 'withoutcomments' ];

	/**
	 * Object identity vars a catch-all must capture from the path, as Rules::IDENTITY.
	 */
	private const IDENTITY = [ 'pagename', 'name', 'attachment', 'attachment_id', 'p', 'page_id' ];

	/**
	 * Whether the first stored rule that matches the original path (core's order) is a route the swap would hide:
	 * neither the key's own (eff/) nor a generic catch-all whose literal ends at the root or the key's front (the
	 * compiler's rule 3c). Only rules whose literal prefix fits are matched.
	 *
	 * @param array<mixed, mixed> $rules Stored rules core is about to match.
	 * @param array<int, string>  $tests Paths as core matches them (raw and urldecoded).
	 * @param string              $eff   The key's effective base.
	 * @param string              $front The key's front and root part of eff ('' or ending in "/").
	 * @return bool
	 */
	public static function keeps( array $rules, array $tests, string $eff, string $front ): bool {
		foreach ( $rules as $re => $query ) {
			$re = (string) $re;
			$n  = strcspn( $re, '.[]()?*+{}|$^\\' );

			// A quantifier right after the literal makes its last byte optional.
			$lit = substr( $re, 0, $n > 0 && str_contains( '?*{', $re[ $n ] ?? '' ) ? $n - 1 : $n );

			foreach ( $tests as $test ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed plugin rule is skipped, as core does.
				if ( str_starts_with( $test, $lit ) && 1 === @preg_match( '#^' . $re . '#', $test, $m ) ) {
					if ( ! self::page( is_string( $query ) ? $query : '', $m ) ) {
						continue 2;
					}

					$at_front = $lit === '' || ( str_ends_with( $lit, '/' ) && str_starts_with( $front, $lit ) );

					return ! Swap::own_rule( $re, $eff ) && ! ( $at_front && self::generic( is_string( $query ) ? $query : '' ) );
				}
			}
		}

		return false;
	}

	/**
	 * Core's verbose page check (class-wp.php:242-256): a pagename=$matches[n] match counts only for a page core
	 * would serve; else core goes on to the next rule.
	 *
	 * @param string        $query Rule query.
	 * @param array<string> $m     Matches.
	 * @return bool
	 */
	private static function page( string $query, array $m ): bool {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->use_verbose_page_rules || ! preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $v ) ) {
			return true;
		}

		$page   = get_page_by_path( $m[ (int) $v[1] ] ?? '' );
		$status = is_object( $page ) ? get_post_status_object( $page->post_status ) : null;

		return is_object( $page ) && ( $status === null || $status->public || $status->protected || $status->private || ! $status->exclude_from_search );
	}

	/**
	 * Whether a rule query is a generic catch-all (a page, post or attachment by a captured name, endpoints, the
	 * language copy's var), as the compiler's Rules::is_generic() with the site's post type, endpoint and tag vars.
	 *
	 * @param string $query Rule query.
	 * @return bool
	 */
	private static function generic( string $query ): bool {
		global $wp_rewrite;

		$vars = [];
		$qs   = str_contains( $query, '?' ) ? substr( $query, (int) strpos( $query, '?' ) + 1 ) : $query;

		foreach ( explode( '&', $qs ) as $pair ) {
			[ $var, $value ] = array_pad( explode( '=', $pair, 2 ), 2, '' );

			if ( $var !== '' ) {
				$vars[ $var ] = $value;
			}
		}

		$pt  = [];
		$tag = [];
		$ep  = [];

		foreach ( get_post_types( [], 'objects' ) as $type ) {
			$pt[] = is_string( $type->query_var ) ? $type->query_var : '';
		}

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			foreach ( (array) $wp_rewrite->queryreplace as $q ) {
				$tag[] = rtrim( (string) $q, '=' );
			}

			foreach ( (array) $wp_rewrite->endpoints as $endpoint ) {
				$var  = ( (array) $endpoint )[2] ?? '';
				$ep[] = is_string( $var ) ? $var : '';
			}
		}

		if ( $vars === [] || array_diff( array_keys( $vars ), self::GENERIC, $pt, $tag, $ep, [ UrlConverter::query_var() ] ) !== [] ) {
			return false;
		}

		$named = false;

		foreach ( $vars as $var => $value ) {
			if ( in_array( (string) $var, array_merge( self::IDENTITY, $pt, $tag ), true ) ) {
				if ( ! str_starts_with( $value, '$matches[' ) ) {
					return false;
				}

				$named = true;
			}
		}

		return $named || ! isset( $vars['post_type'] );
	}
}
