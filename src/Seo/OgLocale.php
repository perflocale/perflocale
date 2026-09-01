<?php
/**
 * Open Graph locale coordination.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Seo;

use PerfLocale\Enum\ObjectType;
use PerfLocale\Helper;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared og:locale logic.
 *
 * The page's own og:locale is left to the active SEO plugin: PerfLocale
 * filters WordPress's `locale` (see Bootstrap), so every SEO plugin that
 * derives og:locale from get_locale() already emits the current language's
 * locale without any per-plugin help. What no SEO plugin emits is
 * og:locale:alternate for the page's *other* translations, so
 * {@see self::emit_alternates()} adds those once from the core.
 *
 * Gated on the `seo_og_locale` setting via {@see self::enabled()}.
 */
final class OgLocale {

	/**
	 * Whether og:locale output is enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return true;
		}

		return (bool) $plugin->get( 'settings' )->get( 'seo_og_locale', true );
	}

	/**
	 * Whether a known OG-emitting SEO plugin is active.
	 *
	 * Used to gate og:locale:alternate emission: alternates only make sense
	 * alongside an existing og:* block, so on a site with no SEO plugin we
	 * skip them rather than emit lone alternate tags. Mirrors the detection
	 * each addon's is_compatible() uses. Memoized per request.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active(): bool {
		static $active = null;

		if ( $active !== null ) {
			return $active;
		}

		$active = defined( 'WPSEO_VERSION' )
			|| class_exists( 'RankMath' )
			|| class_exists( 'AIOSEO\\Plugin\\AIOSEO' )
			|| defined( 'SLIM_SEO_VER' )
			|| defined( 'SEOPRESS_VERSION' )
			|| function_exists( 'the_seo_framework' )
			|| function_exists( 'tsf' );

		return $active;
	}

	/**
	 * Locales of the current page's other translations, for og:locale:alternate.
	 *
	 * Reuses {@see \PerfLocale\Router\UrlConverter::get_translations_for_current_page()},
	 * which is already memoized per request and shared with the hreflang and
	 * language-switcher output, so this adds no extra query when those have
	 * run. Returns deduplicated WordPress-form locales excluding the current
	 * language's. Memoized per request.
	 *
	 * @return array<int, string>
	 */
	public static function alternate_locales(): array {
		// Blog-keyed per-request memo — the alternate og:locale set is per-blog
		// (active languages + their locales differ). Keying by blog id means a
		// mid-request switch_to_blog() gets its own entry instead of blog A's,
		// with no switch_blog reset needed (a plain function-local static would
		// leak across the switch since no reset can reach it).
		static $cached_by_blog = [];

		$blog_key = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( isset( $cached_by_blog[ $blog_key ] ) ) {
			return $cached_by_blog[ $blog_key ];
		}

		$cached                      = [];
		$cached_by_blog[ $blog_key ] = &$cached;

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) || ! $plugin->has( 'url_converter' ) ) {
			return $cached;
		}

		$router       = $plugin->get( 'router' );
		$current_slug = $router->get_current_slug();
		$translations = $plugin->get( 'url_converter' )->get_translations_for_current_page();

		if ( count( $translations ) < 2 ) {
			return $cached;
		}

		// slug => locale for the active languages (single cached read).
		$locales = [];

		foreach ( $router->get_active_languages() as $lang ) {
			if ( ! empty( $lang->slug ) && ! empty( $lang->locale ) ) {
				$locales[ (string) $lang->slug ] = (string) $lang->locale;
			}
		}

		// `_perflocale_seo_exclude` must hide a translation from EVERY search
		// signal, not just hreflang: an og:locale:alternate naming a locale the
		// hreflang set deliberately omits contradicts it. Mirrors the same
		// two-part contract as HreflangTags — emit nothing FROM an excluded
		// page, and skip excluded siblings. The flagged-id set is empty on
		// almost every site, so the common cost is one memoised lookup.
		$excluded_slugs = [];

		if ( is_singular() && Helper::seo_excluded_post_ids() !== [] ) {
			$post_id = (int) get_queried_object_id();

			if ( Helper::is_seo_excluded( $post_id ) ) {
				return $cached;
			}

			if ( $post_id > 0 && $plugin->has( 'group_repo' ) ) {
				foreach ( $plugin->get( 'group_repo' )->get_translations( $post_id, ObjectType::Post ) as $link ) {
					if ( ! empty( $link->language_slug ) && Helper::is_seo_excluded( (int) $link->object_id ) ) {
						$excluded_slugs[ (string) $link->language_slug ] = true;
					}
				}
			}
		}

		$seen = [];

		foreach ( array_keys( $translations ) as $slug ) {
			$slug = (string) $slug;

			if ( $slug === $current_slug || ! isset( $locales[ $slug ] ) || isset( $excluded_slugs[ $slug ] ) ) {
				continue;
			}

			$locale = $locales[ $slug ];

			if ( isset( $seen[ $locale ] ) ) {
				continue;
			}

			$seen[ $locale ] = true;
			$cached[]        = $locale;
		}

		return $cached;
	}

	/**
	 * Emit og:locale:alternate meta tags for the current page's translations.
	 *
	 * Emitted once from the core (not per addon) since alternate tags are
	 * plugin-agnostic and unique-keyed OG-tag arrays can't carry repeats.
	 * Gated on an active SEO plugin so no-SEO-plugin sites stay clean.
	 *
	 * @return void
	 */
	public static function emit_alternates(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! self::enabled() || ! self::seo_plugin_active() ) {
			return;
		}

		foreach ( self::alternate_locales() as $locale ) {
			printf(
				'<meta property="og:locale:alternate" content="%s" />' . "\n",
				esc_attr( $locale )
			);
		}
	}
}
