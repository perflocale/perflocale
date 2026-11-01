<?php
/**
 * PerfLocale SEOPress addon: sitemap alternates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The SEOPress sitemap alternates of PerfLocaleSeopress, which forwards its sitemap hooks here.
 */
final class PerfLocaleSeopressSitemap {

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
	 * Add xhtml:link alternates to a SEOPress sitemap entry's rendered XML.
	 *
	 * @param string              $output Sitemap URL entry XML.
	 * @param array<string,mixed> $url URL data array with 'loc', 'mod', 'images' keys.
	 * @return string
	 */
	public function add_sitemap_alternates( string $output, array $url ): string {
		if ( empty( $url['loc'] ) ) {
			return $output;
		}

		// Fast path: try to resolve the URL as a post permalink.
		$post_id = url_to_postid( $url['loc'] );

		if ( $post_id > 0 ) {
			$alternates = $this->get_alternates_xml( $post_id, \PerfLocale\Enum\ObjectType::Post );

			if ( $alternates !== '' ) {
				return str_replace( '</url>', $alternates . '</url>', $output );
			}

			return $this->pin_untranslated_loc( $output, $url, $post_id, \PerfLocale\Enum\ObjectType::Post );
		}

		// Then a term URL: the filter does not say whether an entry is a post or a term.
		$term_id = $this->resolve_term_from_url( (string) $url['loc'] );

		if ( $term_id > 0 ) {
			$alternates = $this->get_alternates_xml( $term_id, \PerfLocale\Enum\ObjectType::Term );

			if ( $alternates !== '' ) {
				return str_replace( '</url>', $alternates . '</url>', $output );
			}

			return $this->pin_untranslated_loc( $output, $url, $term_id, \PerfLocale\Enum\ObjectType::Term );
		}

		return $output;
	}

	/**
	 * Pin an unlinked entry's loc to the default language's URL shape.
	 *
	 * Unlinked (default-language) objects only: pin_sitemap_url_to_default() strips
	 * any language prefix, so it is safe only for a loc that has none.
	 *
	 * @param string                      $output      Sitemap URL entry XML.
	 * @param array<string, mixed>        $url         URL data array.
	 * @param int                         $object_id   Post or term ID.
	 * @param \PerfLocale\Enum\ObjectType $object_type Object type.
	 * @return string
	 */
	private function pin_untranslated_loc( string $output, array $url, int $object_id, \PerfLocale\Enum\ObjectType $object_type ): string {
		if ( $this->repo()->get_translations( $object_id, $object_type ) !== [] ) {
			return $output;
		}

		$hreflang = $this->hreflang();

		if ( ! $hreflang ) {
			return $output;
		}

		// The loc arrives HTML-encoded (a different escaper for posts and terms): compare the
		// decoded pair, so a no-op leaves the XML byte-identical.
		$loc     = (string) $url['loc'];
		$decoded = wp_specialchars_decode( $loc, ENT_QUOTES );
		$pinned  = $hreflang->pin_sitemap_url_to_default( $decoded );

		if ( $pinned === $decoded ) {
			return $output;
		}

		return str_replace( '<loc>' . $loc . '</loc>', '<loc>' . esc_html( $pinned ) . '</loc>', $output );
	}

	/**
	 * Resolve a URL to a term ID by walking active translatable taxonomies.
	 *
	 * @param string $url Full URL from the sitemap entry.
	 * @return int Term ID or 0 when no match.
	 */
	private function resolve_term_from_url( string $url ): int {
		static $cache = [];

		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			$cache[ $url ] = 0;
			return 0;
		}

		$taxonomies = $plugin->get( 'settings' )->get_translatable_taxonomies();

		if ( empty( $taxonomies ) ) {
			$cache[ $url ] = 0;
			return 0;
		}

		// Take the last non-empty path segment as the slug candidate.
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
		$path = trim( $path, '/' );

		if ( $path === '' ) {
			$cache[ $url ] = 0;
			return 0;
		}

		$segments = explode( '/', $path );
		$slug     = sanitize_title( (string) end( $segments ) );

		if ( $slug === '' ) {
			$cache[ $url ] = 0;
			return 0;
		}

		foreach ( $taxonomies as $taxonomy ) {
			$term = get_term_by( 'slug', $slug, (string) $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			// The term's own link path must equal the entry path: archive entries share this
			// filter, and their last segment can match a term slug.
			$term_link = get_term_link( $term );
			$term_path = is_string( $term_link )
				? trim( (string) wp_parse_url( $term_link, PHP_URL_PATH ), '/' )
				: '';

			if ( $term_path !== '' && $term_path === $path ) {
				$cache[ $url ] = (int) $term->term_id;
				return (int) $term->term_id;
			}
		}

		$cache[ $url ] = 0;
		return 0;
	}

	/**
	 * Build xhtml:link alternate XML for a post or term.
	 *
	 * @param int                         $object_id Post or term ID.
	 * @param \PerfLocale\Enum\ObjectType $object_type Object type.
	 * @return string XML string.
	 */
	private function get_alternates_xml( int $object_id, \PerfLocale\Enum\ObjectType $object_type = \PerfLocale\Enum\ObjectType::Post ): string {
		$plugin = \PerfLocale\Plugin::get_instance();
		$links  = $this->repo()->get_translations( $object_id, $object_type );

		if ( count( $links ) < 2 ) {
			return '';
		}

		// Per-post SEO opt-out: no alternates here, and skipped as a sibling below.
		if ( $object_type === \PerfLocale\Enum\ObjectType::Post && \PerfLocale\Helper::is_seo_excluded( $object_id ) ) {
			return '';
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

		$xml   = '';
		$count = 0;

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

			if ( ! $permalink ) {
				continue;
			}

			$lang_obj = $slug_map[ (string) $link->language_slug ] ?? null;

			if ( ! $lang_obj ) {
				continue;
			}

			$hreflang = $this->locale_to_hreflang( $lang_obj->locale );
			$xml     .= "\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $hreflang ) . '" href="' . esc_url( $permalink ) . "\" />\n";
			++$count;
		}

		// An alternate set needs the entry itself plus at least one sibling.
		return $count > 1 ? $xml : '';
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
	 */
	private function lang_repo(): \PerfLocale\Database\Repository\LanguageRepository {
		if ( $this->lang_repo === null ) {
			$cache           = \PerfLocale\Plugin::get_instance()->get( 'cache' );
			$this->lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		}

		return $this->lang_repo;
	}
}
