<?php
/**
 * PerfLocale SEOPress addon.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEOPress integration for PerfLocale.
 *
 * Registers SEO meta keys as translatable and injects alternate
 * language URLs into SEOPress sitemaps. SEOPress delegates hreflang
 * to multilingual plugins, so no hreflang disabling is needed.
 */
final class PerfLocaleSeopress implements \PerfLocale\Addon\AddonInterface {

	/**
	 * Minimum tested SEOPress version.
	 */
	private const MIN_VERSION = '6.0';

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
		return 'seopress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return 'SEOPress';
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
		return [ 'wp-seopress/seopress.php' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_compatible(): bool {
		if ( ! function_exists( 'seopress_get_service' ) && ! class_exists( 'SEOPRESS_Functions' ) ) {
			return false;
		}

		if ( defined( 'SEOPRESS_VERSION' ) && version_compare( SEOPRESS_VERSION, self::MIN_VERSION, '<' ) ) {
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
	 * context — where SEOPress's canonical filter never fires anyway (its
	 * wp_head branches are gated on `! is_admin()`).
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
		// Register SEOPress meta keys as translatable.
		add_filter( 'perflocale/translatable_meta_keys', [ $this, 'add_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/translatable_meta_keys', [ $this, 'add_mt_meta_keys' ], 10, 2 );
		add_filter( 'perflocale/mt/meta_key_format', [ $this, 'mt_meta_key_format' ], 10, 3 );

		// og:locale needs no handling: PerfLocale filters WordPress's `locale`,
		// so SEOPress's own og:locale already reflects the current language.

		// Missing-translation canonical guard. PerfLocale registers it on
		// core's `get_canonical_url`, but SEOPress removes core's
		// rel_canonical and emits its own, so that filter never fires and the
		// guard is inert whenever this plugin is active. Re-register it on
		// SEOPress's own canonical filter — which SEOPress only calls at all
		// when something is hooked to it. HreflangTags decides whether the
		// value is PerfLocale's to correct, so the `_seopress_robots_canonical`
		// override (its own wp_head branch, same filter) is left alone.
		add_filter( 'seopress_titles_canonical', [ $this, 'pin_fallback_canonical' ] );

		// Inject alternate language URLs into SEOPress sitemap entries.
		add_filter( 'seopress_sitemaps_url', [ $this, 'add_sitemap_alternates' ], 10, 2 );

		// Add xhtml namespace to SEOPress sitemap urlset.
		add_filter( 'seopress_sitemaps_urlset', [ $this, 'add_urlset_xhtml_namespace' ] );

		// Term sitemaps must list ALL languages' terms, not only the current
		// one (matches the Yoast/Rank Math/AIOSEO addons). The callback gates
		// itself to sitemap requests, so ordinary term queries are untouched.
		// Sitemap predicate is request-constant (REQUEST_URI is fixed before
		// plugins_loaded) — register the get_terms_args callback only on
		// sitemap requests instead of no-op dispatching on every term query.
		if ( $this->is_sitemap_request() ) {
			add_filter( 'get_terms_args', [ $this, 'include_all_languages_in_sitemap' ] );
		}

		// Enrich SEOPress schema graph with inLanguage + translation siblings.
		// SEOPress assembles its decoded JSON-LD as an array of schema entities
		// keyed by schema slug on `seopress_json_schema_generator_get_jsons`
		// (JsonSchemaGenerator::getJsons) — there is no `seopress_schema_graph`
		// filter. Gated on the setting so we don't register a no-op filter when
		// enrichment is disabled.
		if ( $plugin->has( 'settings' ) && $plugin->get( 'settings' )->seo_schema_enrichment_enabled() ) {
			add_filter( 'seopress_json_schema_generator_get_jsons', [ $this, 'filter_schema_graph' ] );
		}
	}

	/**
	 * Pin SEOPress's canonical on a missing-translation fallback render.
	 *
	 * SEOPress is the odd one out: its filter carries the whole rendered
	 * `<link rel="canonical" href="…">` element rather than the URL, built as
	 * `htmlspecialchars( urldecode( $url ) )`. Unwrap the href, run the shared
	 * guard, and re-wrap with the equivalent WordPress escapers so the tag is
	 * byte-identical whenever the guard is a no-op.
	 *
	 * wp_specialchars_decode() reverses only the htmlspecialchars half; the
	 * href stays percent-DECODED, so what reaches the guard is not the raw
	 * get_permalink() the other five addons pass. HreflangTags's
	 * permalink-equality test decodes both sides for exactly this reason —
	 * without that it would never match a non-ASCII slug and the pin would be
	 * silently inert here. Re-encoding the href instead is not an option: the
	 * decode is lossy about which characters SEOPress encoded.
	 *
	 * @param mixed $canonical_tag Canonical `<link>` element SEOPress built.
	 * @return mixed
	 */
	public function pin_fallback_canonical( $canonical_tag ) {
		if ( ! is_string( $canonical_tag ) || $canonical_tag === '' ) {
			return $canonical_tag;
		}

		$hreflang = $this->hreflang();

		if ( ! $hreflang ) {
			return $canonical_tag;
		}

		if ( ! preg_match( '/href="([^"]*)"/', $canonical_tag, $matches ) ) {
			return $canonical_tag;
		}

		$current = wp_specialchars_decode( $matches[1], ENT_QUOTES );
		$pinned  = $hreflang->filter_seo_plugin_canonical( $current );

		if ( ! is_string( $pinned ) || $pinned === $current ) {
			return $canonical_tag;
		}

		return str_replace(
			'href="' . $matches[1] . '"',
			'href="' . esc_attr( $pinned ) . '"',
			$canonical_tag
		);
	}

	/**
	 * On sitemap requests, signal PerfLocale's term-query filter to include
	 * every language's terms (not just the current one). Mirrors the identical
	 * helper in the Yoast/Rank Math/AIOSEO addons.
	 *
	 * @param array<string, mixed> $args get_terms() query args.
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
			$is_sitemap  = (bool) preg_match( '/sitemap[^\/]*\.xml/', $request_uri );
		}

		return $is_sitemap;
	}

	/**
	 * Enrich SEOPress's schema graph via the shared SchemaEnricher.
	 *
	 * SEOPress hands over its decoded schemas as an array keyed by schema
	 * slug, each value a schema-entity array (or null when a schema produced
	 * no output). Enrich each entity individually and preserve the keys.
	 *
	 * @param array<string, array<string, mixed>|null>|mixed $graph SEOPress schema map.
	 * @return array<string, array<string, mixed>|null>|mixed
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
	 * Add SEOPress meta keys as translatable.
	 *
	 * @param array<int, string> $keys Meta keys.
	 * @param string             $post_type Post type.
	 * @return array<int, string>
	 */
	public function add_meta_keys( array $keys, string $post_type ): array {
		$keys[] = '_seopress_titles_title';
		$keys[] = '_seopress_titles_desc';
		$keys[] = '_seopress_social_fb_title';
		$keys[] = '_seopress_social_fb_desc';
		$keys[] = '_seopress_social_twitter_title';
		$keys[] = '_seopress_social_twitter_desc';

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
	 * The SEOPress meta keys this addon machine-translates — all plain-text SEO
	 * fields, so all route to the provider's text mode via mt_meta_key_format().
	 */
	private const MT_TEXT_KEYS = [
		'_seopress_titles_title',
		'_seopress_titles_desc',
		'_seopress_social_fb_title',
		'_seopress_social_fb_desc',
		'_seopress_social_twitter_title',
		'_seopress_social_twitter_desc',
	];

	public function add_mt_meta_keys( array $keys, string $post_type ): array {
		$settings = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! (bool) $settings->get( 'mt_meta_seo', true ) ) {
			return $keys;
		}

		return array_merge( $keys, self::MT_TEXT_KEYS );
	}

	/**
	 * Route SEOPress's plain-text SEO meta to the provider's text mode so the
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
	 * Add xhtml namespace declaration to the SEOPress sitemap urlset tag.
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
	 * Add alternate language URLs to SEOPress sitemap entries.
	 *
	 * Hooked to `seopress_sitemaps_url` which fires after SEOPress
	 * renders each URL entry to XML.
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

		// Fallback: try to resolve as a taxonomy term URL. SEOPress's
		// sitemap filter doesn't distinguish between post and term entries,
		// so we check each active translatable taxonomy.
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
	 * The twin of the guard core's SitemapIntegration applies to its own
	 * entries — which SEOPress's sitemap replaces wholesale. Only objects with
	 * NO translation links qualify: those are default-language content by
	 * definition, whereas a linked object's loc already names its own
	 * language. That guard is also what makes the pin safe — not the URL
	 * mode: pin_sitemap_url_to_default() strips a language prefix from any
	 * URL it is handed, so in path-based modes it comes out a no-op only
	 * because an unlinked object's loc has no prefix to strip.
	 *
	 * SEOPress hands over the rendered `<url>` element, so the swap is a
	 * targeted replacement of the `<loc>` it built from this entry's `loc`
	 * value (`<image:loc>` cannot collide — the needle starts at `<loc>`).
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

		// The loc arrives HTML-encoded, and by a different escaper per branch:
		// htmlspecialchars( urldecode( $permalink ) ) for a post entry,
		// esc_url( get_term_link() ) for a term one. Decode before converting
		// and re-encode with the equivalent WordPress escaper afterwards. The
		// no-op test compares the DECODED pair, never the re-encoded one —
		// that keeps a no-op conversion byte-identical instead of rewriting
		// the entity encoding.
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

			// Validate the guess against ground truth: SEOPress pushes
			// NON-term entries (post-type archives, author archives)
			// through the same filter, and their last path segment can
			// collide with a term slug — accepting on slug alone would
			// splice that term's hreflang into an unrelated entry. A real
			// term entry's own link path always equals the entry path.
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
