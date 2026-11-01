<?php
/**
 * PerfLocale Yoast SEO addon: sitemap alternates.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Yoast SEO sitemap alternates of PerfLocaleYoast, which forwards its sitemap hooks here.
 */
final class PerfLocaleYoastSitemap {

	/**
	 * Sitemap URL -> object type and ID, filled by capture_sitemap_entry(), read by add_sitemap_alternates().
	 *
	 * @var array<string, array{type: string, id: int}>
	 */
	private array $sitemap_object_map = [];

	/**
	 * Translation group repository, resolved on first use.
	 *
	 * @var \PerfLocale\Database\Repository\TranslationGroupRepository|null
	 */
	private ?\PerfLocale\Database\Repository\TranslationGroupRepository $repo = null;

	/**
	 * Language repository.
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
	 * Pin an untranslated entry's loc, then map that loc to the post or term behind it.
	 *
	 * @param array<string, mixed> $url_data Sitemap entry data array.
	 * @param string               $type Object type ('post' or 'term').
	 * @param object               $object The post or term object.
	 * @return array<string, mixed> Entry data.
	 */
	public function capture_sitemap_entry( $url_data, string $type, $object ) {
		// Untyped on purpose: returning something empty drops a Yoast sitemap entry, so an
		// earlier callback can pass `false`, and a typed parameter would fatal. See
		// docs-ai/11-code-rationale.md, "PerfLocaleYoast: untyped sitemap and breadcrumb callbacks".
		if ( ! is_array( $url_data ) || ! is_object( $object ) ) {
			return $url_data;
		}

		if ( empty( $url_data['loc'] ) ) {
			return $url_data;
		}

		if ( $type === 'term' && isset( $object->term_id ) ) {
			// Pin BEFORE mapping so the key matches the loc Yoast renders
			// (add_sitemap_alternates looks the entry up by its final URL).
			$url_data = $this->pin_untranslated_loc( $url_data, (int) $object->term_id, \PerfLocale\Enum\ObjectType::Term );

			$this->sitemap_object_map[ $url_data['loc'] ] = [
				'type' => 'term',
				'id'   => (int) $object->term_id,
			];
		} elseif ( $type === 'post' && isset( $object->ID ) ) {
			$url_data = $this->pin_untranslated_loc( $url_data, (int) $object->ID, \PerfLocale\Enum\ObjectType::Post );

			$this->sitemap_object_map[ $url_data['loc'] ] = [
				'type' => 'post',
				'id'   => (int) $object->ID,
			];
		}

		return $url_data;
	}

	/**
	 * Add xhtml:link alternates to a Yoast sitemap entry's rendered XML.
	 *
	 * @param string              $output Sitemap URL entry XML.
	 * @param array<string,mixed> $url URL data array with 'loc', 'mod', 'images' keys.
	 * @return string
	 */
	public function add_sitemap_alternates( string $output, array $url ): string {
		$loc = $url['loc'] ?? '';

		if ( $loc === '' ) {
			return $output;
		}

		// Use the object map populated by capture_sitemap_entry().
		$info = $this->sitemap_object_map[ $loc ] ?? null;

		if ( ! $info ) {
			// Fallback for posts when map is empty.
			$post_id = url_to_postid( $loc );

			if ( $post_id > 0 ) {
				$info = [
					'type' => 'post',
					'id'   => $post_id,
				];
			}
		}

		if ( ! $info ) {
			return $output;
		}

		$object_type = $info['type'] === 'term'
			? \PerfLocale\Enum\ObjectType::Term
			: \PerfLocale\Enum\ObjectType::Post;

		$alternates = $this->get_alternates_xml( $info['id'], $object_type );

		if ( $alternates === '' ) {
			return $output;
		}

		return str_replace( '</url>', $alternates . '</url>', $output );
	}

	/**
	 * Pin an unlinked entry's loc to the default language's URL shape.
	 *
	 * Unlinked (default-language) objects only: pin_sitemap_url_to_default() strips
	 * any language prefix, so it is safe only for a loc that has none.
	 *
	 * @param array<string, mixed>        $url_data    Sitemap entry data.
	 * @param int                         $object_id   Post or term ID.
	 * @param \PerfLocale\Enum\ObjectType $object_type Object type.
	 * @return array<string, mixed>
	 */
	private function pin_untranslated_loc( array $url_data, int $object_id, \PerfLocale\Enum\ObjectType $object_type ): array {
		if ( $this->repo()->get_translations( $object_id, $object_type ) !== [] ) {
			return $url_data;
		}

		$hreflang = $this->hreflang();

		if ( ! $hreflang ) {
			return $url_data;
		}

		$url_data['loc'] = $hreflang->pin_sitemap_url_to_default( (string) $url_data['loc'] );

		return $url_data;
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

			// Published translations only.
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
	 * Lazily resolve the translation group repository once per request.
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
	 * Lazily resolve the language repository once per request.
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
