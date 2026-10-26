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
 * Emits og:locale:alternate for the page's other translations.
 *
 * The page's own og:locale comes from the SEO plugin, which reads the filtered `locale`.
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
	 * Whether a known OG-emitting SEO plugin is active (same detection as the add-ons).
	 *
	 * Alternates without an SEO plugin's og:* block would stand alone, so they are skipped.
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
	 * Deduplicated locales of the current page's other translations, for og:locale:alternate.
	 *
	 * Reuses the per-request translation set shared with hreflang and the switcher.
	 *
	 * @return array<int, string>
	 */
	public static function alternate_locales(): array {
		// Keyed by blog: no switch_blog reset can reach a function-local static.
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

		// SEO exclusion applies here as in HreflangTags: nothing from an excluded page, no excluded siblings.
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
	 * Emitted here, not per add-on: SEO plugins' unique-keyed OG arrays cannot carry repeats.
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
