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
 * Rank Math integration for PerfLocale.
 *
 * Registers SEO meta keys as translatable and feeds alternate language
 * URLs into Rank Math's sitemap. No hreflang suppression is registered:
 * Rank Math only emits hreflang through a multilingual integration, so
 * PerfLocale's tags never collide with it. See the note in
 * HreflangManager::register_hooks().
 */
final class PerfLocaleRankmath implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested Rank Math version. The sitemap filters we rely on
	 * have been stable since 1.0.46.
	 */
	private const MIN_VERSION = '1.0.46';

	/**
	 * Map of sitemap URL → object info, populated by the entry filter
	 * and consumed by the URL filter.
	 *
	 * @var array<string, array{type: string, id: int}>
	 */
	private array $sitemap_object_map = [];

	/**
	 * Translation group repository - lazy.
	 *
	 * @var \PerfLocale\Database\Repository\TranslationGroupRepository|null
	 */
	private ?\PerfLocale\Database\Repository\TranslationGroupRepository $repo = null;

	/**
	 * Language repository - lazy.
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
	 * Frontend-only container service, so this is null in admin (non-AJAX)
	 * context — where Rank Math's canonical filter still fires for its own
	 * content analysis but there is no fallback render to pin.
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

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so Rank Math's own og:locale already reflects the current language.

		// Missing-translation canonical guard. PerfLocale registers it on
		// core's `get_canonical_url`, but Rank Math removes core's
		// rel_canonical and emits its own, so that filter never fires and the
		// guard is inert whenever this plugin is active. Re-register it on
		// Rank Math's own canonical filter; HreflangTags decides whether the
		// value is PerfLocale's to correct, so a site owner's manually-set
		// canonical (applied by Rank Math BEFORE this filter) is left alone.
		add_filter( 'rank_math/frontend/canonical', [ $this, 'pin_fallback_canonical' ] );

		// Capture at PHP_INT_MAX so the captured URL key matches the final
		// URL after any third-party rank_math/sitemap/entry rewriter has
		// fired — without this, a canonical-URL or CDN plugin between
		// priorities 10-999 would mutate the URL after our capture, and
		// add_sitemap_alternates would then miss the map lookup at line 132.
		add_filter( 'rank_math/sitemap/entry', [ $this, 'capture_sitemap_entry' ], PHP_INT_MAX, 3 );

		// Add alternate URLs to Rank Math's rendered sitemap entries.
		add_filter( 'rank_math/sitemap/url', [ $this, 'add_sitemap_alternates' ], 10, 2 );

		// Include all language terms in Rank Math taxonomy sitemaps. The
		// sitemap predicate is request-constant (REQUEST_URI is fixed before
		// plugins_loaded), so the callback — riding WP's hottest term-query
		// filter — is only registered on sitemap requests instead of
		// dispatching a no-op for every get_terms() call on every pageload.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Add xhtml namespace to sitemap urlsets.
		add_action( 'wp_loaded', [ $this, 'register_sitemap_namespace' ] );

		// Enrich Rank Math's schema graph with inLanguage + translation
		// siblings. Gated on the setting. Rank Math assembles its @graph on the
		// 'rank_math/json_ld' filter (do_filter('json_ld', [], $this) in
		// class-jsonld.php) — there is NO 'rank_math/schema/data' filter, so the
		// previous hook never fired and enrichment silently did nothing. Run at
		// priority 100, after Rank Math's own add_schema(10)/connect_schema_entities(99),
		// so the fully-assembled graph is enriched. Only the first arg ($data)
		// is needed; the JsonLD object passed as the second arg is ignored.
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
	 * Rank Math emits a MAP of entities keyed by snippet name — 'publisher',
	 * 'WebSite', 'WebPage', 'richSnippet' … — and only re-indexes it with
	 * array_values() when it renders (class-jsonld.php:159). That is NOT a
	 * shape SchemaEnricher::enrich_graph() handles: it recognises an `@graph`
	 * envelope, a sequential list, or one lone entity, and a keyed map falls
	 * through to the single-entity branch, where the `@type` lookup misses and
	 * the whole map comes back untouched. Enrich each entity individually and
	 * keep the keys. Do not "simplify" this loop into an enrich_graph() call —
	 * it would silently stop enriching every Rank Math page.
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
		$keys[] = 'rank_math_og_title';
		$keys[] = 'rank_math_og_description';
		$keys[] = 'rank_math_twitter_title';
		$keys[] = 'rank_math_twitter_description';

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
	 * The Rank Math meta keys this addon machine-translates — all plain-text SEO
	 * fields, so all route to the provider's text mode via mt_meta_key_format().
	 */
	private const MT_TEXT_KEYS = [
		'rank_math_title',
		'rank_math_description',
		'rank_math_og_title',
		'rank_math_og_description',
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
	 * Rank Math fires this filter for both posts and terms with the
	 * actual object as the third argument. We store the mapping so
	 * add_sitemap_alternates() can resolve the correct object type, and
	 * {@see pin_untranslated_loc()} first pins the loc of an object that has
	 * no translation links — so the map is keyed by the URL Rank Math will
	 * actually render.
	 *
	 * Neither the entry nor the object can be type-declared. Rank Math's own
	 * Local SEO module fires this filter for its KML sitemap with the literal
	 * `[]` in the object position (local-seo/class-kml-file.php:104-111, type
	 * 'local'), and Rank Math's WPML guard returns the empty STRING in the
	 * entry position to drop a hidden-language post (sitemap/class-sitemap.php:80)
	 * — the documented way to skip an entry, which is why every provider tests
	 * `! empty( $url )` after the filter. This callback runs at PHP_INT_MAX,
	 * i.e. after every such rewriter, so it sees whatever they returned. An
	 * `array`/`object` declaration would turn either into a TypeError raised
	 * during argument binding, before the guard below could return, fataling
	 * the whole sitemap request.
	 *
	 * @param array<string, mixed>|mixed $url_data Sitemap entry data array, or
	 *                                             whatever an earlier callback
	 *                                             returned to drop the entry.
	 * @param string                     $type Object type ('post', 'term',
	 *                                          'user' or 'local').
	 * @param object|mixed               $object The post or term object.
	 * @return array<string, mixed>|mixed Entry data, with an unlinked object's
	 *                                    loc pinned to the default language.
	 *                                    Anything this integration does not
	 *                                    handle is returned unchanged.
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
	 * The twin of the guard core's SitemapIntegration applies to its own
	 * entries — which Rank Math's sitemap replaces wholesale. Only objects
	 * with NO translation links qualify: those are default-language content
	 * by definition, whereas a linked object's loc already names its own
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
	 * Add alternate language URLs to Rank Math sitemap entries.
	 *
	 * Hooked to `rank_math/sitemap/url` which fires after Rank Math
	 * renders each URL entry to XML. Supports both posts and terms.
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
	 * Convert a WordPress locale to a canonical BCP 47 hreflang tag.
	 *
	 * Lowercase language + UPPERCASE region (`en_US` → `en-US`,
	 * `pt_BR` → `pt-BR`). See {@see \PerfLocale\Helper::format_locale_as_bcp47()}.
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private function locale_to_hreflang( string $locale ): string {
		return \PerfLocale\Helper::format_locale_as_bcp47( $locale );
	}
}
