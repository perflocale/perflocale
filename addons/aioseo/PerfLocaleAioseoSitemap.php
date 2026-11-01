<?php
/**
 * PerfLocale All in One SEO addon: sitemap alternates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The All in One SEO sitemap alternates of PerfLocaleAioseo, which forwards its sitemap hooks here.
 */
final class PerfLocaleAioseoSitemap {

	/**
	 * Translation group repository, resolved on first use.
	 *
	 * @var \PerfLocale\Database\Repository\TranslationGroupRepository|null
	 */
	private ?\PerfLocale\Database\Repository\TranslationGroupRepository $repo = null;

	/**
	 * Language repository, resolved on first use.
	 *
	 * @var \PerfLocale\Database\Repository\LanguageRepository|null
	 */
	private ?\PerfLocale\Database\Repository\LanguageRepository $lang_repo = null;

	/**
	 * PerfLocale's hreflang service, read through the add-on.
	 *
	 * @var \Closure(): ?\PerfLocale\Frontend\HreflangTags
	 */
	private readonly \Closure $hreflang_service;

	/**
	 * Constructor.
	 *
	 * @param \Closure(): ?\PerfLocale\Frontend\HreflangTags $hreflang_service The add-on's hreflang().
	 */
	public function __construct( \Closure $hreflang_service ) {
		$this->hreflang_service = $hreflang_service;
	}

	/**
	 * PerfLocale's hreflang service, or null in admin.
	 *
	 * @return \PerfLocale\Frontend\HreflangTags|null
	 */
	private function hreflang(): ?\PerfLocale\Frontend\HreflangTags {
		return ( $this->hreflang_service )();
	}

	/**
	 * Add alternate language URLs to AIOSEO sitemap entries.
	 *
	 * @param array<string, mixed>|mixed $entry Sitemap entry.
	 * @param int                        $post_id Post ID.
	 * @param string                     $post_type Post type.
	 * @param string                     $type Object type label from AIOSEO (optional).
	 * @return array<string, mixed>|mixed Entry, unchanged when it is not an
	 *                                    entry array.
	 */
	public function add_sitemap_alternates( $entry, int $post_id, string $post_type, string $type = 'post' ) {
		// Untyped on purpose: a falsy return drops an AIOSEO sitemap entry, so an earlier
		// callback can pass one, and a typed parameter would fatal. See
		// docs-ai/11-code-rationale.md, "PerfLocaleAioseo: sitemap filter contract".
		if ( ! is_array( $entry ) ) {
			return $entry;
		}

		// AIOSEO also fires this for BuddyPress pseudo post types (`bp-activity` ...), whose
		// ids are not post ids: alternates and the loc pin would be wrong for them.
		if ( ! post_type_exists( $post_type ) ) {
			return $entry;
		}

		return $this->build_sitemap_languages( $entry, $post_id, \PerfLocale\Enum\ObjectType::Post );
	}

	/**
	 * Add alternate language URLs to AIOSEO taxonomy sitemap entries.
	 *
	 * @param array<string, mixed>|mixed $entry Sitemap entry.
	 * @param int                        $term_id Term ID.
	 * @param string                     $taxonomy Taxonomy name.
	 * @param string                     $type Object type identifier.
	 * @return array<string, mixed>|mixed Entry, unchanged when it is not an
	 *                                    entry array.
	 */
	public function add_taxonomy_sitemap_alternates( $entry, int $term_id, string $taxonomy, string $type ) {
		// Untyped on purpose: same exclusion contract as add_sitemap_alternates().
		if ( ! is_array( $entry ) ) {
			return $entry;
		}

		return $this->build_sitemap_languages( $entry, $term_id, \PerfLocale\Enum\ObjectType::Term );
	}

	/**
	 * Build language alternates for an AIOSEO sitemap entry.
	 *
	 * @param array<string, mixed>        $entry Sitemap entry.
	 * @param int                         $object_id Post or term ID.
	 * @param \PerfLocale\Enum\ObjectType $object_type Object type.
	 * @return array<string, mixed>
	 */
	private function build_sitemap_languages( array $entry, int $object_id, \PerfLocale\Enum\ObjectType $object_type ): array {
		$plugin = \PerfLocale\Plugin::get_instance();
		$links  = $this->repo()->get_translations( $object_id, $object_type );

		if ( $links === [] ) {
			// Unlinked = default-language content, but its loc carries the request host, so
			// in host modes every language host would list it. Pinning is safe only because
			// an unlinked loc has no language prefix for pin_sitemap_url_to_default() to strip.
			return $this->pin_untranslated_loc( $entry );
		}

		if ( count( $links ) < 2 ) {
			return $entry;
		}

		// Per-post SEO opt-out: no alternates here, and skipped as a sibling below.
		if ( $object_type === \PerfLocale\Enum\ObjectType::Post && \PerfLocale\Helper::is_seo_excluded( $object_id ) ) {
			return $entry;
		}

		// Front page detection only applies to posts.
		$url_converter = null;

		if ( $object_type === \PerfLocale\Enum\ObjectType::Post ) {
			$front_page_id = (int) get_option( 'page_on_front' );
			$is_front_page = false;

			if ( $front_page_id > 0 ) {
				foreach ( $links as $link ) {
					if ( (int) $link->object_id === $front_page_id ) {
						$is_front_page = true;
						break;
					}
				}
			}

			$url_converter = ( $is_front_page && $plugin->has( 'url_converter' ) )
				? $plugin->get( 'url_converter' )
				: null;
		}

		// Active languages only: find_by_slug() also resolves deactivated languages.
		$slug_map = $this->lang_repo()->get_slug_map();

		$languages = [];

		foreach ( $links as $link ) {
			if ( empty( $link->language_slug ) ) {
				continue;
			}

			if ( $object_type === \PerfLocale\Enum\ObjectType::Post && get_post_status( (int) $link->object_id ) !== 'publish' ) {
				continue;
			}

			if ( $object_type === \PerfLocale\Enum\ObjectType::Post && \PerfLocale\Helper::is_seo_excluded( (int) $link->object_id ) ) {
				continue;
			}

			if ( $url_converter ) {
				$permalink = $url_converter->convert( home_url( '/' ), $link->language_slug );
			} elseif ( $object_type === \PerfLocale\Enum\ObjectType::Term ) {
				$term_link = get_term_link( (int) $link->object_id );
				$permalink = is_wp_error( $term_link ) ? '' : $term_link;
			} else {
				$permalink = get_permalink( (int) $link->object_id );
			}

			$lang_obj = $slug_map[ (string) $link->language_slug ] ?? null;

			if ( $permalink && $lang_obj ) {
				$hreflang    = $this->locale_to_hreflang( $lang_obj->locale );
				$languages[] = [
					'language' => $hreflang,
					'location' => $permalink,
				];
			}
		}

		if ( count( $languages ) > 1 ) {
			$entry['languages'] = $languages;
		}

		return $entry;
	}

	/**
	 * Pin an unlinked entry's loc to the default language's URL shape.
	 *
	 * @param array<string, mixed> $entry Sitemap entry.
	 * @return array<string, mixed>
	 */
	private function pin_untranslated_loc( array $entry ): array {
		if ( empty( $entry['loc'] ) ) {
			return $entry;
		}

		$hreflang = $this->hreflang();

		if ( ! $hreflang ) {
			return $entry;
		}

		$entry['loc'] = $hreflang->pin_sitemap_url_to_default( (string) $entry['loc'] );

		return $entry;
	}

	/**
	 * Convert a WordPress locale to a BCP 47 hreflang tag (`fr_FR` -> `fr-FR`, region kept).
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private function locale_to_hreflang( string $locale ): string {
		return \PerfLocale\Helper::format_locale_as_bcp47( $locale );
	}

	/**
	 * Lazily resolve the translation group repository.
	 *
	 * @return \PerfLocale\Database\Repository\TranslationGroupRepository
	 */
	private function repo(): \PerfLocale\Database\Repository\TranslationGroupRepository {
		if ( $this->repo === null ) {
			$cache      = \PerfLocale\Plugin::get_instance()->get( 'cache' );
			$this->repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $cache );
		}

		return $this->repo;
	}

	/**
	 * Lazily resolve the language repository.
	 *
	 * @return \PerfLocale\Database\Repository\LanguageRepository
	 */
	private function lang_repo(): \PerfLocale\Database\Repository\LanguageRepository {
		if ( $this->lang_repo === null ) {
			$cache           = \PerfLocale\Plugin::get_instance()->get( 'cache' );
			$this->lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		}

		return $this->lang_repo;
	}
}
