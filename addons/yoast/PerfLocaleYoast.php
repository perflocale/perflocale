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
 * Yoast SEO integration for PerfLocale.
 *
 * Registers SEO meta keys as translatable, registers alternate
 * language URLs into Yoast's sitemap, and translates breadcrumb
 * links. No hreflang suppression is registered: Yoast does not emit
 * head/header hreflang itself (it defers to multilingual plugins), so
 * PerfLocale's tags never collide with it. See the note in
 * HreflangManager::register_hooks().
 */
final class PerfLocaleYoast implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested Yoast SEO version. The sitemap + breadcrumb hooks
	 * this addon relies on have been stable since 14.0.
	 */
	private const MIN_VERSION = '14.0';

	/**
	 * Map of sitemap URL → object info, populated by the entry filter
	 * and consumed by the URL filter.
	 *
	 * @var array<string, array{type: string, id: int}>
	 */
	private array $sitemap_object_map = [];

	/**
	 * Translation group repository - lazily resolved once, reused across
	 * all sitemap/breadcrumb callbacks in the request.
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
	 * Frontend-only container service, so this is null in admin (non-AJAX)
	 * context — where Yoast's canonical filter still fires for its own
	 * metabox/analysis passes but there is no fallback render to pin.
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

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so Yoast's own og:locale already reflects the current language.

		// Missing-translation canonical guard. PerfLocale registers it on
		// core's `get_canonical_url`, but Yoast removes core's rel_canonical
		// and emits its own, so that filter never fires and the guard is inert
		// whenever this plugin is active. Re-register it on Yoast's own
		// canonical filter; HreflangTags decides whether the value is
		// PerfLocale's to correct, so a site owner's `_yoast_wpseo_canonical`
		// override (already applied by the time this filter runs) is left
		// alone. Only the URL argument is needed; the presentation object is
		// ignored, so a 1-arg registration is enough.
		add_filter( 'wpseo_canonical', [ $this, 'pin_fallback_canonical' ] );

		// Capture object type and ID from each sitemap entry AFTER any
		// URL-rewriting filter at priority < PHP_INT_MAX so the map's URL
		// key matches the final URL that `add_sitemap_alternates` looks
		// up. Without PHP_INT_MAX a third-party canonical-URL plugin at
		// priority 999+ would mutate the URL after our capture, breaking
		// the lookup and forcing the url_to_postid() fallback (which
		// silently fails for terms).
		add_filter( 'wpseo_sitemap_entry', [ $this, 'capture_sitemap_entry' ], PHP_INT_MAX, 3 );

		// Add alternate URLs to Yoast's sitemap entries.
		add_filter( 'wpseo_sitemap_url', [ $this, 'add_sitemap_alternates' ], 10, 2 );

		// Include all language terms in Yoast taxonomy sitemaps.
		// Sitemap predicate is request-constant (REQUEST_URI is fixed before
		// plugins_loaded) — register the get_terms_args callback only on
		// sitemap requests instead of no-op dispatching on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Add xhtml namespace to Yoast sitemap urlset.
		add_filter( 'wpseo_sitemap_urlset', [ $this, 'add_urlset_xhtml_namespace' ] );

		// Translate breadcrumb links.
		add_filter( 'wpseo_breadcrumb_links', [ $this, 'translate_breadcrumbs' ] );

		// Enrich schema graph with inLanguage + translation siblings.
		// Gated on the setting so the addon doesn't register a no-op filter
		// on every request when the feature is off.
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
	 * Machine-translatable SEO meta: titles/descriptions/social text ONLY.
	 * The focus keyword is deliberately excluded — keyword targeting in the
	 * target language is a human decision (add it back via this same filter
	 * if a site wants it). Gated by the mt_meta_seo setting.
	 *
	 * @param array<int, string> $keys      Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	/**
	 * The Yoast meta keys this addon machine-translates — all plain-text SEO
	 * fields, so all route to the provider's text mode via mt_meta_key_format().
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
	 * REQUEST_URI is fixed before plugins_loaded, so the verdict is
	 * request-constant — used both to gate the get_terms_args registration
	 * and inside the callback for callers that invoke it directly.
	 *
	 * @return bool
	 */
	private function is_sitemap_request(): bool {
		static $is_sitemap = null;

		if ( $is_sitemap === null ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: '';
			$is_sitemap  = (bool) preg_match( '/sitemap[^\\/]*\.xml/', $request_uri );
		}

		return $is_sitemap;
	}

	/**
	 * Pin an untranslated entry's loc, and capture its object type and ID,
	 * before XML rendering.
	 *
	 * Yoast fires this filter for both posts and terms with the actual
	 * object as the third argument. We store the mapping so
	 * add_sitemap_alternates() can resolve the correct object type, and
	 * {@see pin_untranslated_loc()} first pins the loc of an object that has
	 * no translation links — so the map is keyed by the URL Yoast will
	 * actually render.
	 *
	 * @param array<string, mixed> $url_data Sitemap entry data array.
	 * @param string               $type Object type ('post' or 'term').
	 * @param object               $object The post or term object.
	 * @return array<string, mixed> Entry data, with an unlinked object's loc
	 *                              pinned to the default language.
	 */
	public function capture_sitemap_entry( $url_data, string $type, $object ) {
		// Yoast's providers use "return something empty to drop this entry" as a
		// documented exclusion contract, and gate on `! empty( $url )` at
		// inc/sitemaps/class-post-type-sitemap-provider.php:226,
		// class-taxonomy-sitemap-provider.php:297 and
		// class-author-sitemap-provider.php:200. An earlier callback returning
		// `false` therefore reaches us as a bool, and this callback runs at
		// PHP_INT_MAX — after every other one. Declaring `array`/`object` here
		// made PHP throw at argument binding, before the body's own guards could
		// run, turning any site that excludes a sitemap entry into a fatal.
		// Anything we do not recognise goes back exactly as it came in.
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
	 * The twin of the guard core's SitemapIntegration applies to its own
	 * entries — which Yoast's sitemap replaces wholesale. Only objects with NO
	 * translation links qualify: those are default-language content by
	 * definition, whereas a linked object's loc already names its own
	 * language. That guard is also what makes the pin safe — not the URL
	 * mode: pin_sitemap_url_to_default() strips a language prefix from any
	 * URL it is handed, so in path-based modes it comes out a no-op only
	 * because an unlinked object's loc has no prefix to strip. The
	 * get_translations() call is the same one add_sitemap_alternates() makes
	 * for this object later in the request, served from the repository's
	 * per-request static cache the second time.
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
	 * Add alternate language URLs to Yoast sitemap entries.
	 *
	 * Yoast's `wpseo_sitemap_url` filter receives the full XML string
	 * for each URL entry. We inject xhtml:link alternate tags.
	 * Supports both posts and terms via the object map.
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
		// Yoast survives a non-array return from this filter on purpose:
		// src/generators/breadcrumbs-generator.php:172-183 filters into
		// $filtered_crumbs, then `if ( ! is_array( $filtered_crumbs ) )` emits
		// _doing_it_wrong() and keeps its own crumbs. That guard exists because
		// callbacks on this filter demonstrably return non-arrays. Declaring
		// `array` here turned Yoast's survivable notice into a fatal at
		// argument binding, before this body could run - the same shape that
		// took down wpseo_sitemap_entry. Hand anything unrecognised straight
		// back so Yoast's own guard still gets to decide.
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

		// Cache the manager per-request - Yoast can render breadcrumbs
		// multiple times in the same request on complex templates.
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

				if ( $translated_post instanceof \WP_Post ) {
					$link['text'] = $translated_post->post_title;
					$link['url']  = get_permalink( $translated_id );
					$link['id']   = $translated_id;
				}
			}
		}

		unset( $link );

		return $links;
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

		// Per-post opt-out: a flagged post advertises no alternates and is
		// skipped as a sibling below, so excluded translations never appear
		// in any alternate set.
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

		// Active languages only. get_slug_map() is the bootstrap bundle's
		// active-only slug -> row hash — the same source core's hreflang and
		// sitemap paths filter on. find_by_slug() resolves DEACTIVATED
		// languages too, which would advertise them as alternates; the map
		// lookup is also O(1) instead of a per-sibling cache descent.
		$slug_map = $this->lang_repo()->get_slug_map();

		$xml   = '';
		$count = 0;

		foreach ( $links as $link ) {
			if ( empty( $link->language_slug ) ) {
				continue;
			}

			// Only published translations belong in the sitemap as hreflang
			// alternates — skip draft/pending/private/trash (matches the
			// AIOSEO/SEOPress addons).
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

		// A lone self-referencing alternate is meaningless hreflang noise — an
		// alternates set needs at least two members (self + a sibling). Emit
		// nothing when only one language survived the publish filter.
		return $count > 1 ? $xml : '';
	}

	/**
	 * Convert a WordPress locale to an hreflang value.
	 *
	 * Simplifies redundant region codes (fr_FR → fr, de_DE → de)
	 * while preserving meaningful ones (en_US → en-us, pt_BR → pt-br).
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private function locale_to_hreflang( string $locale ): string {
		return \PerfLocale\Helper::format_locale_as_bcp47( $locale );
	}
}
