<?php
/**
 * Hreflang tag output.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Helper;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Router\UrlConverter;
use PerfLocale\Settings;
use PerfLocale\Translation\PostQueryFilter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs hreflang alternates for every language version of the current page, plus x-default.
 */
final class HreflangTags {

	/**
	 * @var LanguageRouter
	 */
	private readonly LanguageRouter $router;

	/**
	 * @var UrlConverter
	 */
	private readonly UrlConverter $url_converter;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param LanguageRouter $router Language router.
	 * @param UrlConverter   $url_converter URL converter.
	 * @param Settings       $settings Plugin settings.
	 */
	public function __construct( LanguageRouter $router, UrlConverter $url_converter, Settings $settings ) {
		$this->router        = $router;
		$this->url_converter = $url_converter;
		$this->settings      = $settings;
	}

	/**
	 * Lazily resolve the CacheManager from the plugin container.
	 *
	 * Null means compute on every request; rendering never depends on the cache.
	 *
	 * @return CacheManager|null
	 */
	private function cache(): ?CacheManager {
		try {
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'cache' ) ) {
				$resolved = $plugin->get( 'cache' );
				return $resolved instanceof CacheManager ? $resolved : null;
			}
		} catch ( \Throwable $e ) {
			// Container not ready: fall back to an uncached path.
			unset( $e );
		}
		return null;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Fallback canonical guard: before the hreflang gate, because without it a fallback render
		// is self-canonical on every language host (cross-host duplicate content).
		add_filter( 'get_canonical_url', [ $this, 'filter_fallback_canonical' ], 10, 2 );

		if ( ! $this->settings->hreflang_enabled() ) {
			return;
		}

		$placement = (string) $this->settings->get( 'seo_hreflang_placement', 'head' );

		if ( $placement === 'head' || $placement === 'both' ) {
			add_action( 'wp_head', [ $this, 'output_hreflang' ], 1 );
		}

		if ( $placement === 'http_header' || $placement === 'both' ) {
			// Priority 1: PerfLocale's Link headers land before any SEO plugin's.
			add_action( 'send_headers', [ $this, 'send_hreflang_headers' ], 1 );
		}
	}

	/**
	 * Point a missing-translation fallback render's canonical at the post's own-language URL.
	 *
	 * Core's canonical inherits the request language, so a fallback render on a language host
	 * would be a self-canonical duplicate; in the post's own language this is a no-op.
	 *
	 * @param mixed $canonical_url Canonical URL core computed.
	 * @param mixed $post          Queried post.
	 * @return mixed
	 */
	public function filter_fallback_canonical( $canonical_url, $post ) {
		if ( ! $post instanceof \WP_Post || ! is_string( $canonical_url ) || $canonical_url === '' ) {
			return $canonical_url;
		}

		$current = $this->router->get_current_language();

		if ( ! $current ) {
			return $canonical_url;
		}

		$cache_manager = $this->cache();

		if ( ! $cache_manager instanceof CacheManager ) {
			return $canonical_url;
		}

		$repo  = new TranslationGroupRepository( $cache_manager );
		$links = $repo->get_translations( (int) $post->ID, ObjectType::Post );

		$own         = '';
		$has_current = false;

		foreach ( $links as $link ) {
			if ( empty( $link->language_slug ) ) {
				continue;
			}

			if ( (int) $link->object_id === (int) $post->ID ) {
				$own = (string) $link->language_slug;
			}

			if ( (string) $link->language_slug === (string) $current->slug ) {
				$has_current = true;
			}
		}

		if ( $own === '' ) {
			// Unlinked object = default-language content by definition.
			$default = $this->router->get_default_language();
			$own     = $default ? (string) $default->slug : '';
		}

		// Not a fallback render: own language, or a current-language translation owns this URL.
		if ( $own === '' || $own === (string) $current->slug || $has_current ) {
			return $canonical_url;
		}

		$pinned = (string) $this->url_converter->convert( $canonical_url, $own );

		return $pinned !== '' ? $pinned : $canonical_url;
	}

	/**
	 * Pin an SEO plugin's canonical for a missing-translation fallback render.
	 *
	 * SEO addons hook this on their plugin's canonical filter. Only the plugin's own default value
	 * is pinned, because a manual canonical override arrives here too and must stay; see
	 * docs-ai/11-code-rationale.md, "HreflangTags: SEO plugin canonical guard".
	 *
	 * @param mixed $canonical_url Canonical URL the SEO plugin computed.
	 * @return mixed Pinned canonical, or the input unchanged.
	 */
	public function filter_seo_plugin_canonical( $canonical_url ) {
		if ( ! is_string( $canonical_url ) || $canonical_url === '' ) {
			return $canonical_url;
		}

		// Core prints no archive canonical, so archives are pinned only here.
		if ( ! is_singular() ) {
			return $this->pin_archive_fallback_canonical( $canonical_url );
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return $canonical_url;
		}

		$permalink = get_permalink( $post );

		if ( ! is_string( $permalink ) || $permalink === '' ) {
			return $canonical_url;
		}

		// Compared decoded and untrailingslashed: plugins re-slash the permalink and SEOPress decodes it.
		$defaults = [ $permalink ];

		// Plugins build a static front page's canonical from home_url(), which is language-mapped
		// where its permalink is not.
		if ( is_front_page() && (int) $post->ID === (int) get_option( 'page_on_front' ) ) {
			$defaults[] = home_url( '/' );
		}

		$offered = urldecode( untrailingslashit( $canonical_url ) );
		$matched = false;

		foreach ( $defaults as $default ) {
			if ( $offered === urldecode( untrailingslashit( $default ) ) ) {
				$matched = true;
				break;
			}
		}

		if ( ! $matched ) {
			return $canonical_url;
		}

		return $this->filter_fallback_canonical( $canonical_url, $post );
	}

	/**
	 * Pin an SEO plugin's canonical on a term archive or the posts page shown as a fallback.
	 *
	 * Only the plugin's own default value (term link, posts page permalink) is touched; terms of
	 * untranslatable taxonomies are shared by every language and left alone.
	 *
	 * @param string $canonical_url Canonical URL the SEO plugin computed.
	 * @return string Pinned canonical, or the input unchanged.
	 */
	private function pin_archive_fallback_canonical( string $canonical_url ): string {
		$offered = urldecode( untrailingslashit( $canonical_url ) );

		if ( is_home() && ! is_front_page() ) {
			$page = get_post( (int) get_option( 'page_for_posts' ) );
			$link = $page instanceof \WP_Post ? get_permalink( $page ) : false;

			if ( ! is_string( $link ) || $offered !== urldecode( untrailingslashit( $link ) ) ) {
				return $canonical_url;
			}

			$pinned = $this->filter_fallback_canonical( $canonical_url, $page );

			return is_string( $pinned ) ? $pinned : $canonical_url;
		}

		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return $canonical_url;
		}

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
			return $canonical_url;
		}

		$link          = get_term_link( $term );
		$current       = $this->router->get_current_language();
		$cache_manager = $this->cache();

		if ( ! is_string( $link ) || $offered !== urldecode( untrailingslashit( $link ) ) || ! $current || ! $cache_manager instanceof CacheManager ) {
			return $canonical_url;
		}

		$own         = '';
		$has_current = false;

		foreach ( ( new TranslationGroupRepository( $cache_manager ) )->get_translations( (int) $term->term_id, ObjectType::Term ) as $link_row ) {
			if ( empty( $link_row->language_slug ) ) {
				continue;
			}

			if ( (int) $link_row->object_id === (int) $term->term_id ) {
				$own = (string) $link_row->language_slug;
			}

			if ( (string) $link_row->language_slug === (string) $current->slug ) {
				$has_current = true;
			}
		}

		if ( $own === '' ) {
			$default = $this->router->get_default_language();
			$own     = $default ? (string) $default->slug : '';
		}

		if ( $own === '' || $own === (string) $current->slug || $has_current ) {
			return $canonical_url;
		}

		$pinned = (string) $this->url_converter->convert( $canonical_url, $own );

		return $pinned !== '' ? $pinned : $canonical_url;
	}

	/**
	 * Pin a sitemap entry URL for an object that has no translation links.
	 *
	 * For SEO plugins' own sitemaps: their `<loc>` is built on the request's (language-mapped) host.
	 * Only for unlinked objects: it strips a language prefix, which such an object never carries.
	 *
	 * @param string $url Sitemap entry URL as the SEO plugin built it.
	 * @return string Pinned URL, or the input unchanged.
	 */
	public function pin_sitemap_url_to_default( string $url ): string {
		if ( $url === '' ) {
			return $url;
		}

		$default = $this->router->get_default_language();

		if ( ! $default || empty( $default->slug ) ) {
			return $url;
		}

		$pinned = (string) $this->url_converter->convert( $url, (string) $default->slug );

		return $pinned !== '' ? $pinned : $url;
	}

	/**
	 * Output hreflang link tags.
	 *
	 * @return void
	 */
	public function output_hreflang(): void {
		// 404 and search have no meaningful alternates.
		if ( is_404() || is_search() ) {
			return;
		}

		// Sitemaps/feeds/embeds/robots/favicon are not translatable pages.
		if ( $this->is_non_page_request() ) {
			return;
		}

		$languages = $this->router->get_active_languages();

		if ( count( $languages ) < 2 ) {
			return;
		}

		$cache_key     = $this->build_cache_key();
		$cache_manager = $this->cache();

		// A tight `<link>` allowlist: wp_kses_post() does not allow `<link>`.
		$build_html = function (): string {
			$tags = $this->get_hreflang_data();
			$html = '';
			foreach ( $tags as $tag ) {
				$html .= sprintf(
					'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
					esc_attr( $tag['hreflang'] ),
					esc_url( $tag['href'] )
				);
			}
			if ( $html === '' ) {
				return '';
			}
			return wp_kses(
				$html,
				[
					'link' => [
						'rel'      => true,
						'hreflang' => true,
						'href'     => true,
					],
				]
			);
		};

		if ( $cache_key !== null && $cache_manager instanceof CacheManager ) {
			// The TTL bounds staleness when a narrow flush is missed; singulars are cleared per post.
			$ttl = (int) apply_filters(
				'perflocale/seo/hreflang_cache_ttl',
				12 * HOUR_IN_SECONDS
			);

			$html = (string) $cache_manager->get(
				$cache_key,
				$build_html,
				max( 1, $ttl ),
				'perflocale_hreflang'
			);
		} else {
			$html = $build_html();
		}

		if ( $html !== '' ) {
			// Escape late: whatever the cache returns, only hreflang `<link>` tags reach the head.
			echo wp_kses(
				$html,
				[
					'link' => [
						'rel'      => true,
						'hreflang' => true,
						'href'     => true,
					],
				]
			);
		}
	}

	/**
	 * Whether this request is a non-HTML resource that must never emit hreflang alternates.
	 *
	 * A core sitemap still parses as is_home() at send_headers, so it would poison the home bucket.
	 *
	 * @return bool
	 */
	private function is_non_page_request(): bool {
		if ( get_query_var( 'sitemap' ) !== '' || get_query_var( 'sitemap-stylesheet' ) !== '' ) {
			return true;
		}

		return is_feed() || is_embed() || is_robots() || is_favicon();
	}

	/**
	 * Build a deterministic transient key for the current page context.
	 *
	 * No user or password dimension: the alternate set admits only published siblings with no
	 * capability check, so it is user-independent; see docs-ai/11-code-rationale.md,
	 * "HreflangTags: cache keys".
	 *
	 * @return string|null
	 */
	private function build_cache_key(): ?string {
		// The key has no preview dimension. Not a bail for logged-in drafts: see the docblock.
		if ( is_preview() ) {
			return null;
		}

		// Non-page resources would land in a real page's bucket.
		if ( $this->is_non_page_request() ) {
			return null;
		}

		// A filtered page N shares the plain page N's bucket but skips the page-existence check.
		if ( $this->is_filtered_listing_page() ) {
			return null;
		}

		$lang_id = $this->router->get_current_language_id();
		$paged   = max( 1, (int) get_query_var( 'paged', 1 ) );

		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();

			// An endpoint page (/my-account/orders/) has its own alternates.
			if ( $post_id <= 0 || $this->url_converter->current_endpoint() !== null ) {
				return null;
			}

			return 'perflocale_hreflang_s_' . $post_id . '_' . $lang_id;
		}

		if ( is_home() && ! is_front_page() ) {
			// The posts page lists only languages with a published sibling: not the front page's set.
			return 'perflocale_hreflang_hp_' . $lang_id . '_' . $paged;
		}

		if ( is_front_page() || is_home() ) {
			return 'perflocale_hreflang_h_' . $lang_id . '_' . $paged;
		}

		if ( is_post_type_archive() ) {
			$pt = (string) get_query_var( 'post_type', 'post' );

			return 'perflocale_hreflang_pta_' . sanitize_key( $pt ) . '_' . $lang_id . '_' . $paged;
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				return 'perflocale_hreflang_t_' . (int) $term->term_id . '_' . $lang_id . '_' . $paged;
			}

			return null;
		}

		if ( is_date() ) {
			$year  = (int) get_query_var( 'year' );
			$month = (int) get_query_var( 'monthnum' );
			$day   = (int) get_query_var( 'day' );

			return 'perflocale_hreflang_d_' . $year . '_' . $month . '_' . $day . '_' . $lang_id . '_' . $paged;
		}

		if ( is_author() ) {
			$author_id = (int) get_queried_object_id();

			return 'perflocale_hreflang_a_' . $author_id . '_' . $lang_id . '_' . $paged;
		}

		// Unknown context: not cached.
		return null;
	}

	/**
	 * Send hreflang Link HTTP headers.
	 *
	 * @return void
	 */
	public function send_hreflang_headers(): void {
		if ( is_admin() ) {
			return;
		}

		// As output_hreflang(): 404 and search have no meaningful alternates.
		if ( is_404() || is_search() ) {
			return;
		}

		// A core sitemap is is_home() here and would poison the home bucket.
		if ( $this->is_non_page_request() ) {
			return;
		}

		$tags = $this->get_hreflang_data();

		foreach ( $tags as $tag ) {
			$hreflang = $this->sanitize_hreflang( $tag['hreflang'] );

			// esc_url_raw(), not esc_url(): a header needs a literal `&`, not `&amp;`.
			header(
				sprintf(
					'Link: <%s>; rel="alternate"; hreflang="%s"',
					esc_url_raw( $tag['href'] ),
					$hreflang
				),
				false
			);
		}
	}

	/**
	 * Compute hreflang data (shared between head tags and HTTP headers).
	 *
	 * @return array<int, array{hreflang: string, href: string}>
	 */
	private function get_hreflang_data(): array {
		// Keyed by blog: no reset reaches a function static, and switch_to_blog() changes the set.
		static $cached_by_blog = [];

		$blog_key = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( isset( $cached_by_blog[ $blog_key ] ) ) {
			return $cached_by_blog[ $blog_key ];
		}

		// Same key, TTL and group as the HTML cache, so the two invalidate together.
		$cache_key     = $this->build_cache_key();
		$cache_manager = $this->cache();

		if ( $cache_key !== null && $cache_manager instanceof CacheManager ) {
			/** This filter is documented in output_hreflang(). */
			$ttl = (int) apply_filters(
				'perflocale/seo/hreflang_cache_ttl',
				12 * HOUR_IN_SECONDS
			);

			$tags = $cache_manager->get(
				'tags_' . $cache_key,
				fn(): array => $this->compute_hreflang_data(),
				max( 1, $ttl ),
				'perflocale_hreflang'
			);

			$tags                        = is_array( $tags ) ? $tags : [];
			$cached_by_blog[ $blog_key ] = $tags;

			return $tags;
		}

		$tags                        = $this->compute_hreflang_data();
		$cached_by_blog[ $blog_key ] = $tags;

		return $tags;
	}

	/**
	 * Compute the alternate set behind get_hreflang_data()'s memo and cache.
	 *
	 * @return array<int, array{hreflang: string, href: string}>
	 */
	private function compute_hreflang_data(): array {
		$cached = [];

		$languages = $this->router->get_active_languages();

		if ( count( $languages ) < 2 ) {
			return $cached;
		}

		$urls    = $this->url_converter->get_translations_for_current_page();
		$default = $this->router->get_default_language();
		$urls    = $this->restrict_archive_alternates( $urls, $default );

		// An unlinked post is default-language content.
		if ( $urls === [] && $default !== null && is_singular() ) {
			$urls = $this->unlinked_post_alternates( $default );
		}

		// A term of an untranslated taxonomy has no language versions.
		if ( $urls === null ) {
			return $cached;
		}

		// Per-post opt-out: drop flagged siblings, and emit nothing from a flagged post (reciprocity).
		$excluded_slugs = [];

		if ( is_singular() && Helper::seo_excluded_post_ids() !== [] ) {
			$post_id = (int) get_queried_object_id();

			if ( Helper::is_seo_excluded( $post_id ) ) {
				return $cached;
			}

			$cache_manager = $this->cache();

			if ( $post_id > 0 && $cache_manager instanceof CacheManager ) {
				$repo = new TranslationGroupRepository( $cache_manager );

				foreach ( $repo->get_translations( $post_id, ObjectType::Post ) as $link ) {
					if ( ! empty( $link->language_slug ) && Helper::is_seo_excluded( (int) $link->object_id ) ) {
						$excluded_slugs[ (string) $link->language_slug ] = true;
						unset( $urls[ (string) $link->language_slug ] );
					}
				}
			}
		}

		// Opt-in: an entry for every language whose URL renders 200 through a fallback.
		$include_fallbacks = (bool) apply_filters(
			'perflocale/seo/hreflang_include_fallbacks',
			(bool) $this->settings->get( 'seo_hreflang_include_fallbacks', false )
		);

		if ( $include_fallbacks ) {
			$missing_action = (string) $this->settings->get( 'missing_translation_action', 'show_default' );
			$fallbacks      = (array) $this->settings->get( 'language_fallbacks', [] );

			// The object's own link, not current_request_url(): that strips the query, and under
			// Plain permalinks (?p=42) every alternate would point at the language home.
			$current_url = '';

			if ( is_singular() && (int) get_queried_object_id() > 0 ) {
				$permalink   = get_permalink( get_queried_object_id() );
				$current_url = is_string( $permalink ) ? $permalink : '';
			} elseif ( ( is_category() || is_tag() || is_tax() ) && get_queried_object() instanceof \WP_Term ) {
				$term_link   = get_term_link( get_queried_object() );
				$current_url = is_string( $term_link ) ? $term_link : '';
			}

			if ( $current_url === '' ) {
				$current_url = $this->current_request_url();
			}

			// convert() returns an unparseable URL unchanged; it never becomes an alternate.
			$fill = wp_parse_url( $current_url ) !== false ? $languages : [];

			foreach ( $fill as $lang ) {
				if ( isset( $urls[ $lang->slug ] ) || isset( $excluded_slugs[ $lang->slug ] ) ) {
					continue;
				}

				// Renders 200 under show_default or a non-empty fallback chain.
				$has_fallback = ! empty( $fallbacks[ $lang->slug ] );

				if ( $missing_action !== 'show_default' && ! $has_fallback ) {
					continue;
				}

				$converted = $this->url_converter->convert( $current_url, $lang->slug );

				if ( $converted !== '' ) {
					$urls[ $lang->slug ] = $converted;
				}
			}
		}

		$urls = $this->drop_missing_listing_pages( $urls, $languages );

		foreach ( $languages as $lang ) {
			if ( ! isset( $urls[ $lang->slug ] ) ) {
				continue;
			}

			$cached[] = [
				'hreflang' => $this->locale_to_hreflang( $lang->locale ),
				'href'     => $urls[ $lang->slug ],
			];
		}

		if ( $this->settings->get( 'seo_x_default', true ) && $default ) {
			$current_slug = $this->router->get_current_slug();
			// No published default sibling: omit x-default, never point it at the current language.
			$default_url = $urls[ $default->slug ] ?? '';

			/**
			 * Filter the URL used for the <code>x-default</code> hreflang entry.
			 *
			 * @hook perflocale/seo/x_default_url
			 * @param string $default_url URL currently slated for x-default.
			 * @param object $default Default-language object.
			 * @param string $current_slug Slug of the language being rendered.
			 */
			$default_url = (string) apply_filters(
				'perflocale/seo/x_default_url',
				$default_url,
				$default,
				$current_slug
			);

			if ( $default_url !== '' ) {
				$cached[] = [
					'hreflang' => 'x-default',
					'href'     => $default_url,
				];
			}
		}

		/** @hook perflocale/seo/hreflang_tags Filter hreflang tags before output. */
		$filtered = apply_filters( 'perflocale/seo/hreflang_tags', $cached );

		if ( ! is_array( $filtered ) ) {
			_doing_it_wrong(
				'apply_filters( "perflocale/seo/hreflang_tags", ... )',
				esc_html(
					sprintf(
						/* translators: %s is the offending return type. */
						__( 'A hook on perflocale/seo/hreflang_tags returned %s - the filter contract is array-in / array-out. Falling back to the unfiltered tags.', 'perflocale' ),
						get_debug_type( $filtered )
					)
				),
				'1.0.0'
			);
			return $cached;
		}

		// The filtered tags: the memo and cache serve this to the second emitter verbatim.
		return $filtered;
	}

	/**
	 * Keep only the languages a term archive or the posts page exists in.
	 *
	 * UrlConverter lists every language for an unlinked object, but those URLs render it only as a
	 * fallback. An unlinked object keeps the default language only.
	 *
	 * @param array<string, string> $urls             Language slug => URL.
	 * @param object|null           $default_language Default language.
	 * @return array<string, string>|null Restricted map, or null for a term of
	 *                                    a taxonomy that is not translatable.
	 */
	private function restrict_archive_alternates( array $urls, ?object $default_language ): ?array {
		$object_id   = 0;
		$object_type = null;

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( ! $term instanceof \WP_Term ) {
				return $urls;
			}

			if ( ! in_array( $term->taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
				return null;
			}

			$object_id   = (int) $term->term_id;
			$object_type = ObjectType::Term;
		} elseif ( is_home() && ! is_front_page() ) {
			$object_id   = (int) get_option( 'page_for_posts' );
			$object_type = ObjectType::Post;
		}

		$cache_manager = $this->cache();

		if ( $object_id <= 0 || $object_type === null || ! $cache_manager instanceof CacheManager ) {
			return $urls;
		}

		$exists = [];

		foreach ( ( new TranslationGroupRepository( $cache_manager ) )->get_translations( $object_id, $object_type ) as $link ) {
			if ( empty( $link->language_slug ) ) {
				continue;
			}

			if ( $object_type === ObjectType::Post && get_post_status( (int) $link->object_id ) !== 'publish' ) {
				continue;
			}

			$exists[ (string) $link->language_slug ] = true;
		}

		if ( $exists === [] && $default_language && ! empty( $default_language->slug ) ) {
			$exists[ (string) $default_language->slug ] = true;
		}

		return array_intersect_key( $urls, $exists );
	}

	/**
	 * Drop the alternates of page N of a listing for languages whose listing is shorter.
	 *
	 * A shorter listing answers page N with a 404. Term archives and filtered requests are left alone;
	 * see docs-ai/11-code-rationale.md, "HreflangTags: paginated listing alternates".
	 *
	 * @param array<array-key, string> $urls      Alternate URL per language slug.
	 * @param array<int, object>       $languages Active languages.
	 * @return array<array-key, string>
	 */
	private function drop_missing_listing_pages( array $urls, array $languages ): array {
		global $wp_query;

		if (
			(int) get_query_var( 'paged' ) < 2
			|| ! $wp_query instanceof \WP_Query
			|| is_singular()
			|| is_category()
			|| is_tag()
			|| is_tax()
			|| is_search()
			|| $this->is_filtered_listing_page()
		) {
			return $urls;
		}

		$current = (string) $this->router->get_current_slug();
		$targets = [];

		foreach ( $languages as $lang ) {
			$slug = (string) $lang->slug;

			if ( $slug === $current || ! isset( $urls[ $slug ] ) || (int) ( $lang->id ?? 0 ) <= 0 ) {
				continue;
			}

			$targets[ $slug ] = (int) $lang->id;
		}

		if ( [] === $targets ) {
			return $urls;
		}

		// An unscoped listing has the same rows in every language.
		if ( PostQueryFilter::is_unscoped_main_listing( $wp_query ) ) {
			return [] !== (array) $wp_query->posts ? $urls : array_diff_key( $urls, $targets );
		}

		$exists = PostQueryFilter::listing_page_exists( $wp_query, array_values( $targets ) );

		foreach ( $targets as $slug => $language_id ) {
			if ( is_array( $exists ) ) {
				$has_page = $exists[ $language_id ] ?? true;
			} else {
				$probe    = new \WP_Query(
					array_merge(
						$wp_query->query_vars,
						[
							'perflocale_language_id' => $language_id,
							'perflocale_optimize_found_rows' => false,
							'fields'                 => 'ids',
							'no_found_rows'          => true,
							'cache_results'          => false,
							'update_post_meta_cache' => false,
							'update_post_term_cache' => false,
							'lazy_load_term_meta'    => false,
						]
					)
				);
				$has_page = [] !== $probe->posts;
			}

			if ( ! $has_page ) {
				unset( $urls[ $slug ] );
			}
		}

		return $urls;
	}

	/**
	 * True on page 2 or later of a listing with query vars that build_cache_key() does not hold.
	 *
	 * Such vars narrow the main query, so its page probes would not describe the plain page N.
	 *
	 * @return bool
	 */
	private function is_filtered_listing_page(): bool {
		$paged = get_query_var( 'paged' );

		if ( ! is_numeric( $paged ) || (int) $paged < 2 || is_singular() || is_category() || is_tag() || is_tax() || is_search() ) {
			return false;
		}

		$allowed = [ 'paged', 'post_type', UrlConverter::query_var() ];

		if ( ! is_post_type_archive() && ! is_home() ) {
			if ( is_date() ) {
				array_push( $allowed, 'year', 'monthnum', 'day' );
			} elseif ( is_author() ) {
				$allowed[] = 'author';
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only the keys are read, to decide whether the alternate set may be cached.
		$keys = array_merge( array_keys( $_GET ), array_keys( $_POST ) );

		return array_diff( array_map( 'strval', $keys ), $allowed ) !== [];
	}

	/**
	 * The one language version of a queried post that has no translation link.
	 *
	 * An unlinked post is default-language content; linked, unpublished and untranslated types get none.
	 *
	 * @param object $default_language Default-language object.
	 * @return array<string, string> Default-language slug => URL, or [].
	 */
	private function unlinked_post_alternates( object $default_language ): array {
		$post = get_queried_object();

		if (
			! $post instanceof \WP_Post
			|| $post->post_status !== 'publish'
			|| ! in_array( $post->post_type, $this->settings->get_translatable_post_types(), true )
		) {
			return [];
		}

		$cache_manager = $this->cache();

		if ( ! $cache_manager instanceof CacheManager ) {
			return [];
		}

		$repo = new TranslationGroupRepository( $cache_manager );

		foreach ( $repo->get_translations( (int) $post->ID, ObjectType::Post ) as $link ) {
			if ( (int) ( $link->object_id ?? 0 ) === (int) $post->ID ) {
				return [];
			}
		}

		$permalink = (string) get_permalink( $post );

		if ( $permalink === '' ) {
			return [];
		}

		// The permalink of an unlinked post carries the visitor's language.
		$url = $this->url_converter->convert( $permalink, (string) $default_language->slug );

		return $url !== '' ? [ (string) $default_language->slug => $url ] : [];
	}

	/**
	 * Convert a WordPress locale to an hreflang value in canonical case (`en_US` to `en-US`).
	 *
	 * @param string $locale WordPress locale.
	 * @return string Hreflang-compatible language tag.
	 */
	private function locale_to_hreflang( string $locale ): string {
		return \PerfLocale\Helper::format_locale_as_bcp47( $locale );
	}

	/**
	 * Resolve the current request URL from the server environment.
	 *
	 * The path keeps its language prefix (convert() swaps it). Query string and request Host are dropped:
	 * the cache key holds neither, so the first visitor's would be cached for everyone. The path uses
	 * esc_url_raw(): sanitize_text_field() strips the percent-encoded bytes of non-ASCII slugs.
	 *
	 * @return string
	 */
	private function current_request_url(): string {
		$host   = sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		$uri    = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$scheme = is_ssl() ? 'https' : 'http';

		if ( $host === '' ) {
			return home_url( '/' );
		}

		$home = wp_parse_url( home_url() );

		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return home_url( '/' );
		}

		$authority = (string) $home['host'] . ( isset( $home['port'] ) ? ':' . (int) $home['port'] : '' );

		// The cache key holds no query string.
		$qpos = strpos( $uri, '?' );
		if ( $qpos !== false ) {
			$uri = substr( $uri, 0, $qpos );
		}

		return $scheme . '://' . $authority . $uri;
	}

	/**
	 * Sanitize an hreflang value for safe use in HTTP headers.
	 *
	 * Strips characters not valid in RFC 5646 language tags (header injection).
	 *
	 * @param string $hreflang Hreflang value.
	 * @return string Sanitized value.
	 */
	private function sanitize_hreflang( string $hreflang ): string {
		// A-Z kept: canonical region subtags are uppercase (`en-US`).
		return preg_replace( '/[^A-Za-z0-9\-]/', '', $hreflang ) ?? '';
	}
}
