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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs hreflang link tags in the HTML head for SEO.
 *
 * Generates <link rel="alternate" hreflang="..."> tags for all
 * language versions of the current page, plus x-default.
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
	 * Kept out of the constructor so existing callers don't need to change;
	 * hreflang output is tolerant of a missing cache (degrades to compute-
	 * on-every-request without breaking rendering).
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
		// Missing-translation fallback canonical guard. Independent of the
		// hreflang toggle: in subdomain/domain URL modes a fallback render
		// (default-language content served on a language host because no
		// translation exists) would otherwise be SELF-canonical on every
		// language host — cross-host duplicate content. Registered before the
		// hreflang gate so disabling hreflang output cannot disable it.
		add_filter( 'get_canonical_url', [ $this, 'filter_fallback_canonical' ], 10, 2 );

		if ( ! $this->settings->hreflang_enabled() ) {
			return;
		}

		$placement = (string) $this->settings->get( 'seo_hreflang_placement', 'head' );

		if ( $placement === 'head' || $placement === 'both' ) {
			add_action( 'wp_head', [ $this, 'output_hreflang' ], 1 );
		}

		if ( $placement === 'http_header' || $placement === 'both' ) {
			// Explicit priority 1 — runs early so PerfLocale's Link headers
			// land before any SEO plugin's. Sibling send_headers hooks on this
			// plugin set explicit priorities too (e.g. the cache-tag emitter and
			// Content-Language header) so the default here was the odd one out.
			add_action( 'send_headers', [ $this, 'send_hreflang_headers' ], 1 );
		}
	}

	/**
	 * Point a missing-translation fallback render's canonical at the post's
	 * own-language URL.
	 *
	 * Fires on core's `get_canonical_url`. When the queried post is rendered
	 * in a language other than its own purely as fallback (no translation
	 * exists in the current language), the canonical core computed inherits
	 * the current request context — on a language host that means a
	 * self-canonical duplicate of the default-language URL. Converting the
	 * canonical to the post's own language pins it: base host in
	 * subdomain/domain modes, unprefixed (or correctly prefixed) path in
	 * path modes, and a byte-identical no-op whenever the post is being
	 * served in its own language.
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

		// Not a fallback render: the post is in its own language, or a
		// translation for the current language exists (and owns this URL).
		if ( $own === '' || $own === (string) $current->slug || $has_current ) {
			return $canonical_url;
		}

		$pinned = (string) $this->url_converter->convert( $canonical_url, $own );

		return $pinned !== '' ? $pinned : $canonical_url;
	}

	/**
	 * Pin an SEO plugin's canonical for a missing-translation fallback render.
	 *
	 * {@see filter_fallback_canonical()} only rides core's `get_canonical_url`,
	 * and that filter never fires once an SEO plugin owns `rel=canonical` —
	 * Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework and Slim SEO all
	 * remove core's `rel_canonical` and compute their own. Each addon therefore
	 * re-registers the guard on its own plugin's canonical filter, so the
	 * coupling stays in the addon and the logic stays here.
	 *
	 * Two things separate this entry point from the core-filter one. The SEO
	 * plugins pass no post argument, so the queried object is resolved here;
	 * and every one of them applies a site owner's MANUAL canonical override
	 * before firing its filter (`rank_math_canonical_url`,
	 * `_yoast_wpseo_canonical`, AIOSEO's `canonical_url` column,
	 * `_seopress_robots_canonical`, `_genesis_canonical_uri`,
	 * `slim_seo[canonical]`), so an unconditional pin would rewrite a URL the
	 * owner chose deliberately. Acting only when the incoming value is the
	 * post's own permalink — the exact value core would have handed
	 * filter_fallback_canonical() — leaves overrides, paginated sub-page URLs
	 * and plugin-specific rewrites untouched. Skipping the pin on those is the
	 * safe direction: it restores the pre-SEO-plugin status quo, never worse.
	 *
	 * @param mixed $canonical_url Canonical URL the SEO plugin computed.
	 * @return mixed Pinned canonical, or the input unchanged.
	 */
	public function filter_seo_plugin_canonical( $canonical_url ) {
		// Core's guard is post-only (get_canonical_url never fires for
		// archives), so mirror that scope rather than widening it here.
		if ( ! is_string( $canonical_url ) || $canonical_url === '' || ! is_singular() ) {
			return $canonical_url;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
			return $canonical_url;
		}

		$permalink = get_permalink( $post );

		if ( ! is_string( $permalink ) || $permalink === '' ) {
			return $canonical_url;
		}

		// Insensitive to both cosmetic differences the plugins introduce. They
		// run their own user_trailingslashit() / rtrim() pass over the
		// permalink, and SEOPress renders the tag as
		// htmlspecialchars( urldecode( get_permalink() ) ) — so its addon
		// hands over a percent-DECODED URL while get_permalink() keeps a
		// non-ASCII slug encoded, which without this would make the whole
		// guard inert on every non-Latin-slug site. Neither difference means
		// "the owner set this by hand". Decoding widens only what counts as
		// the permalink, never what gets rewritten: an owner-set canonical
		// still has to decode to the permalink to be touched at all.
		$defaults = [ $permalink ];

		// A static front page is the one singular URL the SEO plugins do not
		// derive from get_permalink(). Rank Math builds it from home_url()
		// (class-paper.php: `if ( is_front_page() ) { $canonical =
		// user_trailingslashit( home_url() ); }`), and home_url() is
		// language-mapped where get_permalink( page_on_front ) is not — so on
		// /de/ the plugin offers `/de/` while the permalink is `/`, the
		// comparison below mismatches, and the guard silently skips the single
		// most duplicate-content-exposed URL on the site. In subdomain and
		// domain modes that means every language host advertises the
		// untranslated front page as self-canonical.
		//
		// This widens only what counts as "the plugin's own default value".
		// Whether anything is actually rewritten is still
		// filter_fallback_canonical()'s decision, and it refuses to pin when
		// the front page has a translation in the current language or is
		// itself current-language content — so an owner-set canonical, which
		// matches neither candidate, is still returned untouched.
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
	 * Pin a sitemap entry URL for an object that has no translation links.
	 *
	 * The addon-facing twin of the guard {@see \PerfLocale\Seo\SitemapIntegration}
	 * applies to core's own sitemap entries, for the SEO plugins that replace
	 * core sitemaps wholesale. An unlinked object is default-language content
	 * by definition, but every SEO plugin builds its `<loc>` from
	 * get_permalink() / get_term_link() in the REQUEST context — and in
	 * subdomain/domain URL modes home_url() is host-mapped to the current
	 * language, so each language host's sitemap advertises the same
	 * untranslated object under its own host (cross-host duplicate content).
	 * Converting to the default language maps the host back to the base
	 * domain.
	 *
	 * In path-based modes this is a no-op in practice but NOT by
	 * construction: handed a prefixed URL it strips the prefix
	 * (`/de/about/` → `/about/`). What makes it safe is the caller's guard,
	 * not the URL mode — an object with no translation links already
	 * resolves to the default language's URL shape, so there is no prefix
	 * to strip. Only call this for such an object; if an unlinked object
	 * could ever carry a prefixed permalink, this would start rewriting
	 * locs that were already correct.
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
		// Skip on 404 and search pages - no meaningful alternate URLs.
		// Search is especially expensive to cache (query string, pagination)
		// with poor hit rate, so skip instead of trying to cache it.
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

		// Build a cache key covering singular, archive, home AND pagination —
		// archive/home recompute costs 3–8ms per page on multi-language sites,
		// so they're cached too, not just singular.
		$cache_key     = $this->build_cache_key();
		$cache_manager = $this->cache();

		// `<link>` is not in wp_kses_post()'s allowlist (which is for post
		// bodies), so we pass a tight allowlist for the only tag we emit.
		// The inner href/hreflang values are already built with esc_attr()
		// / esc_url() inside the closure; wp_kses() runs ONCE per cache
		// fill (inside the loader) so the transient stores already-kses'd
		// HTML. Cache hits skip the wp_kses pass entirely.
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
			// CacheManager's 3-layer get() envelope-wraps, so empty strings (no
			// translations) cache correctly instead of colliding with WP's
			// transient false-sentinel. 12h TTL balances archive-pagination
			// rebuild cost (each ?paged=N is its own entry) against reflecting
			// a translation change within half a day if a narrow-flush is
			// missed; the per-post invalidator keeps singulars fresh.
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
			// Escape late: even though the cached value was built with
			// esc_url()/esc_attr() per attribute, we run wp_kses() at the
			// point of output with a tight <link rel|hreflang|href>
			// allowlist. The pass is idempotent on the well-formed tags we
			// emit and costs ~50-200 µs on this wp_head callback — cheap
			// insurance that nothing but hreflang <link>s can ever reach the
			// page head, regardless of how the cached value was produced.
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
	 * Whether this request is a non-HTML resource that must never emit
	 * hreflang alternates.
	 *
	 * Core sitemaps render at template_redirect — AFTER send_headers — so at
	 * header time a `/wp-sitemap.xml` request still parses as is_home(), and
	 * build_cache_key() would hand it the shared HOME bucket. get_hreflang_data()
	 * would then persist the sitemap's own path-preserved URL as every language
	 * alternate under that bucket for 12h, poisoning it for real homepage
	 * visitors. Feeds and embeds map to the home / singular buckets the same
	 * way; robots.txt and the favicon are simply not pages. rel=alternate is
	 * meaningless on any of them, so bail from both emission and caching.
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
	 * Returns null for previews, for non-page resources (sitemaps, feeds,
	 * embeds, robots, favicon — which would otherwise land in a real page's
	 * bucket and poison it), and for any query shape not enumerated below,
	 * where a key would be too variable to earn its cache entry.
	 *
	 * It deliberately does NOT bail on a logged-in view of unpublished
	 * content: an editor opening a draft or private post DOES get a real
	 * singular key and DOES fill the bucket. That is safe, and the reason is
	 * worth stating because it is the only thing keeping this from being a
	 * cache-poisoning hole. The alternate set is user-independent by
	 * construction — UrlConverter::get_translations_for_current_page() admits
	 * a sibling only at `post_status === 'publish'`, with no capability check
	 * anywhere in the path — so the editor stores exactly the bytes an
	 * anonymous visitor would have computed. And an anonymous request to that
	 * same unpublished URL 404s, where output_hreflang() returns before it
	 * ever reads the bucket.
	 *
	 * The key carries no user or password dimension for the same reason: a
	 * password-protected post's alternates are its siblings' URLs, which are
	 * identical whether or not the visitor has entered the password.
	 *
	 * @return string|null
	 */
	private function build_cache_key(): ?string {
		// Previews only. The singular key below is (post id, language) — it
		// carries no preview dimension — so without this bail a preview render
		// would write into the very bucket an ordinary visit reads. Skip
		// caching there rather than reason about what WP substitutes into a
		// previewed post. Note this is NOT a bail on "users who can see
		// drafts": a logged-in view of unpublished content DOES get a real key
		// and DOES fill the bucket; the docblock above says why that is safe.
		if ( is_preview() ) {
			return null;
		}

		// Non-page resources (sitemaps, feeds, embeds, robots, favicon) would
		// otherwise land in a real page's cache bucket and poison it.
		if ( $this->is_non_page_request() ) {
			return null;
		}

		$lang_id = $this->router->get_current_language_id();
		$paged   = max( 1, (int) get_query_var( 'paged', 1 ) );

		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();

			if ( $post_id <= 0 ) {
				return null;
			}

			return 'perflocale_hreflang_s_' . $post_id . '_' . $lang_id;
		}

		if ( is_front_page() || is_home() ) {
			// Front page + blog-posts page share the same hreflang set
			// (language root URLs). One cache bucket per language + page.
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
			// Date archives are stable per (year/month/day, language, paged).
			$year  = (int) get_query_var( 'year' );
			$month = (int) get_query_var( 'monthnum' );
			$day   = (int) get_query_var( 'day' );

			return 'perflocale_hreflang_d_' . $year . '_' . $month . '_' . $day . '_' . $lang_id . '_' . $paged;
		}

		if ( is_author() ) {
			$author_id = (int) get_queried_object_id();

			return 'perflocale_hreflang_a_' . $author_id . '_' . $lang_id . '_' . $paged;
		}

		// Unknown context - skip caching rather than risk serving wrong data.
		return null;
	}

	/**
	 * Send hreflang Link HTTP headers.
	 *
	 * Reuses the computed hreflang data from get_hreflang_data() to
	 * avoid recomputing URLs, locale formatting, and x-default resolution.
	 *
	 * @return void
	 */
	public function send_hreflang_headers(): void {
		if ( is_admin() ) {
			return;
		}

		// Mirror output_hreflang(): 404 and search results have no meaningful
		// alternate URLs, so don't advertise rel=alternate Link headers for
		// them (include_fallbacks mode would otherwise emit a full set).
		if ( is_404() || is_search() ) {
			return;
		}

		// A core sitemap request is is_home() at send_headers (it renders at
		// template_redirect), so without this bail get_hreflang_data() would
		// cache the sitemap URL as the home bucket's alternates for 12h. Feeds/
		// embeds/robots/favicon get the same non-page treatment.
		if ( $this->is_non_page_request() ) {
			return;
		}

		$tags = $this->get_hreflang_data();

		foreach ( $tags as $tag ) {
			$hreflang = $this->sanitize_hreflang( $tag['hreflang'] );

			// esc_url_raw (NOT esc_url) is deliberate here: this is an HTTP
			// Link header value, not HTML output. esc_url() HTML-entity-encodes
			// ampersands to `&amp;`, which would be invalid inside a raw HTTP
			// header - the browser expects literal `&`. esc_url_raw validates
			// + sanitizes the URL without HTML-encoding.
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
	 * Per-request cached so both output_hreflang() and send_hreflang_headers()
	 * share the same computed result without duplicate work.
	 *
	 * @return array<int, array{hreflang: string, href: string}>
	 */
	private function get_hreflang_data(): array {
		// Blog-keyed per-request memo (shared between output_hreflang at wp_head
		// and send_hreflang_headers at send_headers). Keyed by blog id so a
		// mid-request switch_to_blog() can't hand blog B the alternates computed
		// for blog A — the hreflang URLs, active-language set, and translated
		// slugs are all per-blog. A function-local static keyed only on "not
		// null" would leak across the switch (no reset can reach a function
		// static); blog-keying self-corrects with no switch_blog hook needed.
		static $cached_by_blog = [];

		$blog_key = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		if ( isset( $cached_by_blog[ $blog_key ] ) ) {
			return $cached_by_blog[ $blog_key ];
		}

		// Persistent tag-ARRAY cache shared by BOTH emitters. The 12h
		// transient in output_hreflang() wraps only the wp_head HTML build,
		// so with seo_hreflang_placement=http_header/both the send_headers
		// path recomputed the full alternate set (3-8ms on archives) on
		// EVERY request. Same key/TTL/group as the HTML layer, so the two
		// caches invalidate together; the tags cached here are the FILTERED
		// tags — identical to what the HTML transient has always persisted.
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
	 * The actual alternate-set computation — see get_hreflang_data() for
	 * the memo + persistent-cache layers in front of it.
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

		// Per-post hreflang opt-out (Helper::SEO_EXCLUDE_META): on singular
		// requests, drop flagged siblings from the alternate set — and when
		// the CURRENT post is flagged, emit no hreflang block at all (a set
		// referencing an excluded URL, or emitted FROM one, breaks hreflang
		// reciprocity). The flagged-id set is empty on almost every site,
		// so the common cost is one memoised set lookup. Term archives are
		// out of scope — the flag is post meta.
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

		// Include-fallbacks mode (opt-in): emit an hreflang entry for every
		// active language even without an explicit translation, because under
		// show_default / a fallback chain the language URL renders 200 and
		// search engines prefer a complete set. Defaults false; the filter
		// below takes precedence over the setting for addon/theme overrides.
		$include_fallbacks = (bool) apply_filters(
			'perflocale/seo/hreflang_include_fallbacks',
			(bool) $this->settings->get( 'seo_hreflang_include_fallbacks', false )
		);

		if ( $include_fallbacks ) {
			$missing_action = (string) $this->settings->get( 'missing_translation_action', 'show_default' );
			$fallbacks      = (array) $this->settings->get( 'language_fallbacks', [] );

			// Hoist the current-request URL out of the per-language loop —
			// it's the same value every iteration.
			//
			// For singulars and term archives use the object's own canonical
			// link, NOT the query-stripped $_SERVER reconstruction: under
			// Plain permalinks the page identity lives in the query string
			// (?p=42 / ?cat=5), which current_request_url() strips for cache-
			// poisoning safety — the collapsed URL would make every fallback
			// alternate point at the language HOMEPAGE. get_permalink()/
			// get_term_link() are server-derived (same anti-poisoning
			// property) and mode-aware; convert() swaps their lang param.
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

			foreach ( $languages as $lang ) {
				if ( isset( $urls[ $lang->slug ] ) || isset( $excluded_slugs[ $lang->slug ] ) ) {
					continue;
				}

				// A language URL renders 200 (safe to advertise) when either
				// missing_translation_action is "show_default" (default content
				// served at every prefix) or the language has a non-empty
				// fallback chain (some hop resolves).
				$has_fallback = ! empty( $fallbacks[ $lang->slug ] );

				if ( $missing_action !== 'show_default' && ! $has_fallback ) {
					continue;
				}

				// Build the language-prefixed URL for the CURRENT request.
				// Archive / home pages already get the full set via
				// get_translations_for_current_page(); is_singular() is the
				// branch this code path fixes.
				$converted = $this->url_converter->convert( $current_url, $lang->slug );

				if ( $converted !== '' ) {
					$urls[ $lang->slug ] = $converted;
				}
			}
		}

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
			// x-default must point at the DEFAULT-language version of this page.
			// If that sibling isn't published (so it's absent from $urls), omit
			// x-default rather than falling back to the current-language page —
			// pointing it at, say, the German page would tell search engines the
			// German URL is the universal/fallback target. The filter below can
			// still supply an explicit URL (e.g. a language-picker landing page).
			$default_url = $urls[ $default->slug ] ?? '';

			/**
			 * Filter the URL used for the <code>x-default</code> hreflang entry.
			 *
			 * Lets sites point the search-engine fallback at somewhere other
			 * than the default language's version of the current page -
			 * e.g. a language-picker landing page, or a specific regional
			 * variant - without having to rewrite the whole tag array
			 * via `perflocale/seo/hreflang_tags`.
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
						__( 'A hook on perflocale/seo/hreflang_tags returned %s — the filter contract is array-in / array-out. Falling back to the unfiltered tags.', 'perflocale' ),
						get_debug_type( $filtered )
					)
				),
				'1.0.0'
			);
			return $cached;
		}

		// Return the FILTERED tags: the memo and the persistent cache in
		// get_hreflang_data() persist this return value verbatim for the
		// second caller (send_hreflang_headers after output_hreflang, or
		// vice versa), so returning the pre-filter array would silently
		// drop perflocale/seo/hreflang_tags customizations there.
		return $filtered;
	}

	/**
	 * Convert a WordPress locale to an hreflang value.
	 *
	 * Routes through {@see \PerfLocale\Helper::format_locale_as_bcp47()} so
	 * the case convention is canonical: lowercase language + uppercase
	 * region (`en_US` → `en-US`, `pt_BR` → `pt-BR`). BCP 47 matching is
	 * case-insensitive, but every SEO validator and reference site uses
	 * the canonical form.
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
	 * Used by the include-fallbacks mode to compute per-language variants
	 * of the current URL. It returns the request path AS IS, language prefix
	 * and all — swapping one language's prefix for another is
	 * {@see UrlConverter::convert()}'s job, and convert() takes a fully
	 * formed URL in some language, not a stripped one. The only thing removed
	 * here is the query string (see below).
	 *
	 * Sanitizer choice: `esc_url_raw` (not `sanitize_text_field`) so
	 * percent-encoded bytes in non-ASCII slugs (e.g. `/de/%C3%BCber-uns/`
	 * for `über-uns`) survive into the per-language alternate URL —
	 * `sanitize_text_field` would `preg_replace('/%[a-f0-9]{2}/i','',...)`
	 * and corrupt the path.
	 *
	 * The query string is deliberately stripped before returning: the
	 * cached hreflang payload is keyed only by (post_id|archive, language,
	 * paged) — see {@see build_cache_key()}. Without the strip, the FIRST
	 * visitor's `?utm=evil` would be baked into the 12h-cached
	 * `<link rel="alternate" href>` set served to EVERY subsequent visitor
	 * of the same page (cache poisoning via cache-key/payload mismatch).
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

		// Drop query + fragment — cache key doesn't include them, so leaving
		// them in the URL would let the first request's query string poison
		// the cached alternates for every subsequent visitor of this page.
		$qpos = strpos( $uri, '?' );
		if ( $qpos !== false ) {
			$uri = substr( $uri, 0, $qpos );
		}

		return $scheme . '://' . $host . $uri;
	}

	/**
	 * Sanitize an hreflang value for safe use in HTTP headers.
	 *
	 * Strips any characters that are not valid in RFC 5646 language tags
	 * to prevent HTTP header injection.
	 *
	 * @param string $hreflang Hreflang value.
	 * @return string Sanitized value.
	 */
	private function sanitize_hreflang( string $hreflang ): string {
		// Allow A-Z because canonical BCP 47 region subtags are uppercase
		// (`en-US`). The earlier `[^a-z0-9\-]` regex stripped uppercase
		// letters, silently mangling the canonical form.
		return preg_replace( '/[^A-Za-z0-9\-]/', '', $hreflang ) ?? '';
	}
}
