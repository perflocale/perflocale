<?php
/**
 * Response policy of a missing-translation fallback page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only when a post is reached without a published translation in the URL's language: the action filter, and the
 * noindex of show_default_noindex through core's robots, each SEO plugin's own robots output and an X-Robots-Tag.
 *
 * @since 1.0.8
 */
final class FallbackResponse {

	/**
	 * Whether this response was marked noindex.
	 */
	private static bool $noindex = false;

	/**
	 * The missing-translation action for this request after `perflocale/fallback/action`.
	 *
	 * @param \WP_Post $post          Post reached without a published translation in the URL's language.
	 * @param string   $language_slug The URL's language.
	 * @param string   $action        The setting.
	 * @return string One of Settings::MISSING_ACTIONS.
	 */
	public static function action( \WP_Post $post, string $language_slug, string $action ): string {
		/**
		 * Filter the missing-translation action of one request; any value but the four known ones keeps the setting.
		 *
		 * @hook perflocale/fallback/action
		 * @since 1.0.8
		 * @param string   $action        The setting: show_default, show_default_noindex, show_404 or redirect_default.
		 * @param \WP_Post $post          The post reached without a published translation in the URL's language.
		 * @param string   $language_slug The URL's language.
		 */
		$filtered = apply_filters( 'perflocale/fallback/action', $action, $post, $language_slug );

		return in_array( $filtered, Settings::MISSING_ACTIONS, true ) ? $filtered : $action;
	}

	/**
	 * Slug of a language id, empty when unknown (the redirect_status filter's "to" argument).
	 *
	 * @param int $language_id Language id.
	 * @return string
	 */
	public static function language_slug( int $language_id ): string {
		$lang = ( new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->cache() ) )->find( $language_id );

		return is_object( $lang ) && isset( $lang->slug ) && is_string( $lang->slug ) ? $lang->slug : '';
	}

	/**
	 * What a request for a post with no published sibling in a language gets, mirroring the handler after its
	 * own-sibling step: serve, serve_noindex, redirect (a chain member in another language) or none (404, a hop back).
	 *
	 * @param \WP_Post        $post             Post the request resolves to.
	 * @param object          $language         Language row (id, slug).
	 * @param array<int, int> $translations_map Language id => post id.
	 * @param int             $default_id       Default language id.
	 * @param Settings        $settings         Settings.
	 * @return string
	 */
	public static function outcome( \WP_Post $post, object $language, array $translations_map, int $default_id, Settings $settings ): string {
		$slug   = isset( $language->slug ) && is_string( $language->slug ) ? $language->slug : '';
		$action = self::action( $post, $slug, $settings->get_missing_translation_action() );

		if ( \PerfLocale\Translation\UrlLanguageServe::applies( $post, $translations_map, $slug, $action ) ) {
			return 'serve';
		}

		$chain = $translations_map !== [] ? $settings->get_fallback_chain( $slug, 'post_query' ) : [];
		$repo  = $chain !== [] ? new \PerfLocale\Database\Repository\LanguageRepository( \PerfLocale\Plugin::get_instance()->cache() ) : null;

		foreach ( $chain as $try_slug ) {
			$lang      = $repo?->find_by_slug( $try_slug );
			$lang_id   = is_object( $lang ) && isset( $lang->id ) && is_numeric( $lang->id ) ? (int) $lang->id : 0;
			$target_id = $lang_id > 0 ? (int) ( $translations_map[ $lang_id ] ?? 0 ) : 0;
			$target    = $target_id > 0 ? get_post( $target_id ) : null;

			// First published chain member; the default language would send the visitor back.
			if ( $target instanceof \WP_Post && $target->post_status === 'publish' ) {
				return $lang_id !== $default_id ? 'redirect' : 'none';
			}
		}

		return match ( $action ) {
			'show_default' => 'serve',
			'show_default_noindex' => 'serve_noindex',
			default => 'none',
		};
	}

	/**
	 * The opt-in hreflang entries of languages reached through a fallback: a singular alias only where it serves
	 * indexable content (the handler's own decision); archives, outside the singular policy, under show_default or a chain.
	 *
	 * @param array<string, string>  $urls        Alternates by language slug.
	 * @param array<int, object>     $languages   Active languages.
	 * @param array<string, bool>    $excluded    Slugs left out on purpose.
	 * @param Settings               $settings    Settings.
	 * @param \PerfLocale\Router\UrlConverter $converter URL converter.
	 * @param string                 $request_url Current request URL, when the queried object has no link.
	 * @return array<string, string>
	 */
	public static function fallback_alternates( array $urls, array $languages, array $excluded, Settings $settings, \PerfLocale\Router\UrlConverter $converter, string $request_url ): array {
		$action    = $settings->get_missing_translation_action();
		$fallbacks = (array) $settings->get( 'language_fallbacks', [] );
		$queried   = get_queried_object();

		// The object's own link, not the request URL: that strips the query, and under Plain permalinks (?p=42) every
		// alternate would point at the language home.
		$current = '';

		if ( is_singular() && $queried instanceof \WP_Post ) {
			$current = (string) get_permalink( $queried );
		} elseif ( ( is_category() || is_tag() || is_tax() ) && $queried instanceof \WP_Term ) {
			$link    = get_term_link( $queried );
			$current = is_string( $link ) ? $link : '';
		}

		$current = $current !== '' ? $current : $request_url;

		// convert() returns an unparseable URL unchanged; it never becomes an alternate.
		if ( wp_parse_url( $current ) === false ) {
			return $urls;
		}

		$plugin = \PerfLocale\Plugin::get_instance();
		$single = is_singular() && $queried instanceof \WP_Post ? $queried : null;
		$filter = $single !== null && $plugin->has( 'post_query_filter' ) ? $plugin->get( 'post_query_filter' ) : null;

		foreach ( $languages as $lang ) {
			$slug = isset( $lang->slug ) && is_string( $lang->slug ) ? $lang->slug : '';

			if ( isset( $urls[ $slug ] ) || isset( $excluded[ $slug ] ) ) {
				continue;
			}

			if ( $single !== null && $filter instanceof \PerfLocale\Translation\PostQueryFilter ) {
				$kind = null;
				$filter->serves_in_language( $single, $lang, $kind );

				if ( $kind !== 'serve' ) {
					continue;
				}
			} elseif ( ! in_array( $action, [ 'show_default', 'show_default_noindex' ], true ) && empty( $fallbacks[ $slug ] ) ) {
				continue;
			}

			$converted = $converter->convert( $current, $slug );

			if ( $converted !== '' ) {
				$urls[ $slug ] = $converted;
			}
		}

		return $urls;
	}

	/**
	 * Mark this response noindex, without canonical or hreflang (they would point search engines elsewhere).
	 *
	 * @return void
	 */
	public static function noindex(): void {
		if ( self::$noindex ) {
			return;
		}

		self::$noindex = true;

		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex', false );
		}

		// Last, so the SEO plugins' own values are in. Yoast and Slim SEO print through wp_robots.
		add_filter( 'wp_robots', [ self::class, 'core_robots' ], PHP_INT_MAX );
		add_filter( 'rank_math/frontend/robots', [ self::class, 'keyed_robots' ], PHP_INT_MAX );
		add_filter( 'seopress_titles_robots_attrs', [ self::class, 'listed_robots' ], PHP_INT_MAX );
		add_filter( 'aioseo_robots_meta', [ self::class, 'aioseo_robots' ], PHP_INT_MAX );
		add_filter( 'the_seo_framework_robots_meta_array', [ self::class, 'tsf_robots' ], PHP_INT_MAX, 2 );

		remove_action( 'wp_head', 'rel_canonical' );

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'hreflang' ) ) {
			remove_action( 'wp_head', [ $plugin->get( 'hreflang' ), 'output_hreflang' ], 1 );
		}

		// Rank Math and TSF drop their canonical on a noindex page themselves.
		foreach ( [ 'wpseo_canonical', 'seopress_titles_canonical', 'aioseo_canonical_url', 'slim_seo_canonical_url' ] as $hook ) {
			add_filter( $hook, '__return_empty_string', PHP_INT_MAX );
		}
	}

	/**
	 * Whether this response was marked noindex.
	 *
	 * @return bool
	 */
	public static function noindexed(): bool {
		return self::$noindex;
	}

	/**
	 * Core's robots: noindex, every other directive kept. Empty stays empty: an SEO plugin that prints its own tag
	 * (SEOPress, TSF) empties core's, and a second tag would contradict it.
	 *
	 * @param mixed $robots Directives.
	 * @return array<mixed>
	 */
	public static function core_robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : [];

		if ( $robots === [] ) {
			return [];
		}

		unset( $robots['index'], $robots['all'] );
		$robots['noindex'] = true;

		return $robots;
	}

	/**
	 * Rank Math: the `index` key holds index or noindex.
	 *
	 * @param mixed $robots Directives.
	 * @return array<mixed>
	 */
	public static function keyed_robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : [];
		unset( $robots['all'] );
		$robots['index'] = 'noindex';

		return $robots;
	}

	/**
	 * SEOPress: a list of comma-joined directive strings.
	 *
	 * @param mixed $attrs Directive strings.
	 * @return array<int, string>
	 */
	public static function listed_robots( $attrs ): array {
		$out = [ 'noindex' ];

		foreach ( is_array( $attrs ) ? $attrs : [] as $attr ) {
			foreach ( explode( ',', is_string( $attr ) ? $attr : '' ) as $token ) {
				$token = trim( $token );

				if ( $token !== '' && ! in_array( strtolower( $token ), [ 'index', 'all', 'noindex' ], true ) ) {
					$out[] = $token;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * AIOSEO: attributes keyed by directive.
	 *
	 * @param mixed $attributes Directives.
	 * @return array<mixed>
	 */
	public static function aioseo_robots( $attributes ): array {
		$attributes = is_array( $attributes ) ? $attributes : [];
		unset( $attributes['index'], $attributes['all'] );
		$attributes['noindex'] = 'noindex';

		return $attributes;
	}

	/**
	 * The SEO Framework: only its front-end call for this request (null query args).
	 *
	 * @param mixed $meta Directives.
	 * @param mixed $args Query args, null for the current request.
	 * @return mixed
	 */
	public static function tsf_robots( $meta, $args = null ) {
		if ( null !== $args || ! is_array( $meta ) ) {
			return $meta;
		}

		$meta['noindex'] = 'noindex';

		return $meta;
	}
}
