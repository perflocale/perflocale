<?php
/**
 * PerfLocale Yoast SEO addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO integration: translatable SEO meta, sitemap alternates, breadcrumbs and schema.
 *
 * No hreflang suppression: Yoast emits no hreflang of its own.
 */
final class PerfLocaleYoast implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested Yoast SEO version (its sitemap and breadcrumb hooks).
	 */
	private const MIN_VERSION = '14.0';

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
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'yoast';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Yoast SEO';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_version(): string {
		return '1.0.0';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_plugins(): array {
		return [ 'wordpress-seo/wp-seo.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}

		return version_compare( WPSEO_VERSION, self::MIN_VERSION, '>=' );
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

	/**
	 * Lazily resolve PerfLocale's hreflang service.
	 *
	 * Null in admin (non-AJAX): the service is front-end only.
	 *
	 * @return \PerfLocale\Frontend\HreflangTags|null
	 */
	private function hreflang(): ?\PerfLocale\Frontend\HreflangTags {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'hreflang' ) ) {
			return null;
		}

		$service = $plugin->get( 'hreflang' );

		return $service instanceof \PerfLocale\Frontend\HreflangTags ? $service : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot( \PerfLocale\Plugin $plugin ): void {
		// Register Yoast meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// A field a person empties stays empty: the clear is recorded in the
		// post's seed-cleared marker, which machine translation and the seed honour.
		\PerfLocale\Translation\SyncMeta::track_seed_clears( $this->add_meta_keys( [], '' ) );

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so Yoast's own og:locale already reflects the current language.

		// Yoast removes core's rel_canonical, so the missing-translation canonical guard
		// rides Yoast's filter; HreflangTags leaves a `_yoast_wpseo_canonical` override alone.
		add_filter( 'wpseo_canonical', [ $this, 'pin_fallback_canonical' ] );

		// PHP_INT_MAX: capture after every URL rewriter, so the map key is the final URL
		// add_sitemap_alternates() looks up (its url_to_postid() fallback misses terms).
		add_filter( 'wpseo_sitemap_entry', [ $this, 'capture_sitemap_entry' ], PHP_INT_MAX, 3 );

		// Add alternate URLs to Yoast's sitemap entries.
		add_filter( 'wpseo_sitemap_url', [ $this, 'add_sitemap_alternates' ], 10, 2 );

		// All languages' terms in taxonomy sitemaps; registered on sitemap requests only,
		// because get_terms_args runs on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Add xhtml namespace to Yoast sitemap urlset.
		add_filter( 'wpseo_sitemap_urlset', [ $this, 'add_urlset_xhtml_namespace' ] );

		// Translate breadcrumb links.
		add_filter( 'wpseo_breadcrumb_links', [ $this, 'translate_breadcrumbs' ] );

		// Schema inLanguage and translation siblings, registered only when enrichment is on.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'wpseo_schema_graph', [ $this, 'filter_schema_graph' ] );
		}
	}

	/**
	 * Pin Yoast's canonical on a missing-translation fallback render.
	 *
	 * @param mixed $canonical Canonical URL Yoast computed.
	 * @return mixed
	 */
	public function pin_fallback_canonical( $canonical ) {
		$hreflang = $this->hreflang();

		return $hreflang ? $hreflang->filter_seo_plugin_canonical( $canonical ) : $canonical;
	}

	/**
	 * Enrich Yoast's schema graph via the shared SchemaEnricher.
	 *
	 * @param array<int, array<string, mixed>>|mixed $graph Yoast schema graph (list of entities).
	 * @return array<int, array<string, mixed>>|mixed
	 */
	public function filter_schema_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		return \PerfLocale\Seo\SchemaEnricher::enrich_graph( $graph );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add Yoast meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_yoast_wpseo_title';
		$keys[] = '_yoast_wpseo_metadesc';
		$keys[] = '_yoast_wpseo_focuskw';
		$keys[] = '_yoast_wpseo_opengraph-title';
		$keys[] = '_yoast_wpseo_opengraph-description';
		$keys[] = '_yoast_wpseo_twitter-title';
		$keys[] = '_yoast_wpseo_twitter-description';

		return $keys;
	}

	/**
	 * Machine-translatable SEO meta (titles, descriptions, social), gated by mt_meta_seo.
	 *
	 * The focus keyword is left out on purpose: keyword targeting is a human decision.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	/**
	 * Yoast meta keys this addon machine-translates, all plain text (text mode).
	 */
	private const MT_TEXT_KEYS = [
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_twitter-title',
		'_yoast_wpseo_twitter-description',
	];

	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_seo', true ) ) {
			return $keys;
		}

		return array_merge( $keys, self::MT_TEXT_KEYS );
	}

	/**
	 * Route Yoast's plain-text SEO meta to the provider's text mode so the
	 * stored translation keeps a literal '&' instead of an entity-escaped one.
	 *
	 * @param string $format    Inherited format ('html' default).
	 * @param string $key       Meta key.
	 * @param string $post_type Source post type.
	 * @return string 'text' for this addon's SEO keys, else $format.
	 */
	public function mt_meta_key_format( string $format, string $key, string $post_type ): string {
		return in_array( $key, self::MT_TEXT_KEYS, true ) ? 'text' : $format;
	}


	/**
	 * Include all language terms in get_terms() during Yoast sitemap generation.
	 *
	 * @param array<string, mixed> $args Term query arguments.
	 * @return array<string, mixed>
	 */
	public function include_all_languages_in_sitemap( array $args ): array {
		if ( $this->is_sitemap_request() ) {
			$args['perflocale_all_languages'] = true;
		}

		return $args;
	}

	/**
	 * Whether the current request renders an XML sitemap.
	 *
	 * Memoised: REQUEST_URI is fixed before plugins_loaded.
	 *
	 * @return bool
	 */
	private function is_sitemap_request(): bool {
		static $is_sitemap = null;

		if ( $is_sitemap === null ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$is_sitemap  = \PerfLocale\Helper::is_sitemap_uri( $request_uri );
		}

		return $is_sitemap;
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
	 * Add xhtml namespace declaration to the Yoast sitemap urlset tag.
	 *
	 * @param string $urlset Urlset opening tag.
	 * @return string
	 */
	public function add_urlset_xhtml_namespace( string $urlset ): string {
		if ( strpos( $urlset, 'xmlns:xhtml' ) === false ) {
			$urlset = str_replace(
				'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"',
				'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml"',
				$urlset
			);
		}

		return $urlset;
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
	 * Translate breadcrumb links to use translated post titles and URLs.
	 *
	 * @param array<int, array<string, string>>|mixed $links Breadcrumb links.
	 * @return array<int, array<string, string>>|mixed Links, unchanged when
	 *                                                they are not a crumb array.
	 */
	public function translate_breadcrumbs( $links ) {
		// Untyped on purpose: Yoast keeps its own crumbs when a callback returns a
		// non-array, so a typed parameter would turn its notice into a fatal.
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'router' ) ) {
			return $links;
		}

		$slug = $plugin->get( 'router' )->get_current_slug();

		if ( $slug === '' ) {
			return $links;
		}

		// Per request: Yoast can render breadcrumbs several times in one request.
		static $manager = null;

		if ( $manager === null ) {
			$manager = new \PerfLocale\Translation\PostTranslationManager(
				$plugin->get( 'cache' ),
				$plugin->get( 'settings' )
			);
		}

		foreach ( $links as &$link ) {
			if ( empty( $link['id'] ) ) {
				continue;
			}

			$translated_id = $manager->get_translation_id( (int) $link['id'], $slug );

			if ( $translated_id && $translated_id !== (int) $link['id'] ) {
				$translated_post = get_post( $translated_id );

				// Publicly viewable siblings only: Yoast emits BreadcrumbList JSON-LD even
				// when no trail is shown, and every translation starts as a draft.
				if ( $translated_post instanceof \WP_Post && $this->is_publicly_viewable_translation( $translated_id ) ) {
					// The stored title without tags, as Yoast makes the text of
					// its own crumbs: its presenter prints crumb text as is.
					$link['text'] = wp_strip_all_tags( $translated_post->post_title, true );
					$link['url']  = get_permalink( $translated_id );
					$link['id']   = $translated_id;
				}
			}
		}

		unset( $link );

		return $links;
	}

	/**
	 * Whether a translated post may be shown to the current visitor.
	 *
	 * Same rule as the ACF, Meta Box and Pods addons: core's rule (WP 5.7+), else a public status.
	 *
	 * @param int $post_id Translated post ID.
	 * @return bool
	 */
	private function is_publicly_viewable_translation( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'is_post_publicly_viewable' ) ) {
			return (bool) is_post_publicly_viewable( $post_id );
		}

		$status = get_post_status( $post_id );

		return is_string( $status ) && in_array( $status, get_post_stati( [ 'public' => true ] ), true );
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
}
