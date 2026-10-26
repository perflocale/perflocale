<?php
/**
 * PerfLocale Rank Math addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rank Math integration: translatable SEO meta, sitemap alternates and schema.
 *
 * No hreflang suppression: Rank Math emits hreflang only through a multilingual integration.
 */
final class PerfLocaleRankmath implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested Rank Math version (its sitemap filters).
	 */
	private const MIN_VERSION = '1.0.46';

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
	 * Language repository, resolved on first use.
	 *
	 * @var \PerfLocale\Database\Repository\LanguageRepository|null
	 */
	private ?\PerfLocale\Database\Repository\LanguageRepository $lang_repo = null;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'rankmath';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'Rank Math';
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
		return [ 'seo-by-rank-math/rank-math.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		if ( ! class_exists( 'RankMath' ) ) {
			return false;
		}

		if ( defined( 'RANK_MATH_VERSION' ) && version_compare( RANK_MATH_VERSION, self::MIN_VERSION, '<' ) ) {
			return false;
		}

		return true;
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
		// Register Rank Math meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// A field a person empties stays empty: the clear is recorded in the
		// post's seed-cleared marker, which machine translation and the seed honour.
		\PerfLocale\Translation\SyncMeta::track_seed_clears( $this->add_meta_keys( [], '' ) );

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so Rank Math's own og:locale already reflects the current language.

		// Rank Math removes core's rel_canonical, so the missing-translation canonical guard
		// rides Rank Math's filter; HreflangTags leaves an owner-set canonical alone.
		add_filter( 'rank_math/frontend/canonical', [ $this, 'pin_fallback_canonical' ] );

		// PHP_INT_MAX: capture after every URL rewriter, so the map key is the final URL
		// add_sitemap_alternates() looks up.
		add_filter( 'rank_math/sitemap/entry', [ $this, 'capture_sitemap_entry' ], PHP_INT_MAX, 3 );

		// Add alternate URLs to Rank Math's rendered sitemap entries.
		add_filter( 'rank_math/sitemap/url', [ $this, 'add_sitemap_alternates' ], 10, 2 );

		// All languages' terms in taxonomy sitemaps; registered on sitemap requests only,
		// because get_terms_args runs on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Add xhtml namespace to sitemap urlsets.
		add_action( 'wp_loaded', [ $this, 'register_sitemap_namespace' ] );

		// Schema enrichment, only when on. Rank Math builds its graph on `rank_math/json_ld`
		// (there is no `rank_math/schema/data` filter); priority 100 runs after its own
		// add_schema (10) and connect_schema_entities (99), on the assembled graph.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'rank_math/json_ld', [ $this, 'filter_schema_graph' ], 100, 1 );
		}
	}

	/**
	 * Pin Rank Math's canonical on a missing-translation fallback render.
	 *
	 * @param mixed $canonical Canonical URL Rank Math computed.
	 * @return mixed
	 */
	public function pin_fallback_canonical( $canonical ) {
		$hreflang = $this->hreflang();

		return $hreflang ? $hreflang->filter_seo_plugin_canonical( $canonical ) : $canonical;
	}

	/**
	 * Enrich Rank Math's schema graph via the shared SchemaEnricher.
	 *
	 * Rank Math passes a map keyed by snippet name, which enrich_graph() leaves untouched:
	 * enrich entity by entity and keep the keys. Never replace this loop with enrich_graph().
	 *
	 * @param array<string, array<string, mixed>>|mixed $graph Rank Math schema graph.
	 * @return array<string, array<string, mixed>>|mixed
	 */
	public function filter_schema_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		$enriched = [];

		foreach ( $graph as $key => $entity ) {
			$enriched[ $key ] = is_array( $entity )
				? \PerfLocale\Seo\SchemaEnricher::enrich_single( $entity )
				: $entity;
		}

		return $enriched;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Add Rank Math meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = 'rank_math_title';
		$keys[] = 'rank_math_description';
		$keys[] = 'rank_math_focus_keyword';
		$keys[] = 'rank_math_facebook_title';
		$keys[] = 'rank_math_facebook_description';
		$keys[] = 'rank_math_twitter_title';
		$keys[] = 'rank_math_twitter_description';

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
	 * Rank Math meta keys this addon machine-translates, all plain text (text mode).
	 */
	private const MT_TEXT_KEYS = [
		'rank_math_title',
		'rank_math_description',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
	];

	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_seo', true ) ) {
			return $keys;
		}

		return array_merge( $keys, self::MT_TEXT_KEYS );
	}

	/**
	 * Route Rank Math's plain-text SEO meta to the provider's text mode so the
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
	 * Include all language terms in get_terms() during Rank Math sitemap generation.
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
	 * Untyped on purpose: an empty entry drops it and Local SEO passes `[]` as the object,
	 * so a typed parameter would fatal. See docs-ai/11-code-rationale.md,
	 * "PerfLocaleRankmath: sitemap entry contract".
	 *
	 * @param array<string, mixed>|mixed $url_data Sitemap entry data, or what an earlier callback returned.
	 * @param string                     $type Object type ('post', 'term', 'user' or 'local').
	 * @param object|mixed               $object The post or term object.
	 * @return array<string, mixed>|mixed Entry data; anything else unchanged.
	 */
	public function capture_sitemap_entry( $url_data, string $type, $object ) {
		if ( ! is_array( $url_data ) || ! is_object( $object ) ) {
			return $url_data;
		}

		if ( empty( $url_data['loc'] ) ) {
			return $url_data;
		}

		if ( $type === 'term' && isset( $object->term_id ) ) {
			// Pin BEFORE mapping so the key matches the loc Rank Math renders
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
	 * Register xhtml namespace filter on sitemap urlsets for all public types.
	 */
	public function register_sitemap_namespace(): void {
		foreach ( get_post_types( [ 'public' => true ] ) as $type ) {
			add_filter( "rank_math/sitemap/{$type}_urlset", [ $this, 'add_urlset_xhtml_namespace' ] );
		}
		foreach ( get_taxonomies( [ 'public' => true ] ) as $taxonomy ) {
			add_filter( "rank_math/sitemap/{$taxonomy}_urlset", [ $this, 'add_urlset_xhtml_namespace' ] );
		}
	}

	/**
	 * Add xhtml namespace declaration to a sitemap urlset tag.
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
	 * Add xhtml:link alternates to a Rank Math sitemap entry's rendered XML.
	 *
	 * @param string              $output Sitemap URL entry XML.
	 * @param array<string,mixed> $url URL data array with 'loc', 'mod', 'images' keys.
	 * @return string
	 */
	public function add_sitemap_alternates( string $output, array $url ): string {
		if ( empty( $url['loc'] ) ) {
			return $output;
		}

		// Use the object map populated by capture_sitemap_entry().
		$info = $this->sitemap_object_map[ $url['loc'] ] ?? null;

		if ( ! $info ) {
			// Fallback for posts when map is empty.
			$post_id = url_to_postid( $url['loc'] );

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
	 * Convert a WordPress locale to a BCP 47 hreflang tag (`en_US` -> `en-US`).
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private function locale_to_hreflang( string $locale ): string {
		return \PerfLocale\Helper::format_locale_as_bcp47( $locale );
	}
}
