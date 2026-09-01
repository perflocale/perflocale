<?php
/**
 * Multilingual sitemap integration.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Seo;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates with WordPress core sitemaps to include all language
 * versions and add xhtml:link elements for hreflang alternate URLs.
 */
final class SitemapIntegration {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Per-request caches populated by add_alternate_links()/add_taxonomy_alternate_links().
	 *
	 * Stored at class level so switch_blog() can nuke them on multisite
	 * instead of relying on method-local statics that we can't reach.
	 *
	 * @var TranslationGroupRepository|null
	 */
	private static ?TranslationGroupRepository $repo_cache = null;

	/**
	 * @var LanguageRepository|null
	 */
	private static ?LanguageRepository $lang_repo_cache = null;

	/**
	 * @var array<int, object>|null
	 */
	private static ?array $languages_cache = null;

	/**
	 * @var object|null
	 */
	private static ?object $default_lang_cache = null;

	/**
	 * Map of language slug → hreflang value.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $slug_map_cache = null;

	/**
	 * Post types whose caches have been primed for this sitemap run.
	 *
	 * WordPress renders one sitemap per post type (and per taxonomy).
	 * A single global $primed bool would prime on the first post type
	 * and never again, so subsequent types miss the optimisation. Key
	 * the prime state by post type to fix that.
	 *
	 * @var array<string, bool>
	 */
	private static array $primed_types = [];

	/**
	 * Taxonomies whose term caches have been primed for this sitemap run.
	 * Mirrors $primed_types for the taxonomy path.
	 *
	 * @var array<string, bool>
	 */
	private static array $primed_taxonomies = [];

	/**
	 * Reset per-request caches when multisite switches blog context.
	 *
	 * @return void
	 */
	public static function reset_caches(): void {
		self::$repo_cache         = null;
		self::$lang_repo_cache    = null;
		self::$languages_cache    = null;
		self::$default_lang_cache = null;
		self::$slug_map_cache     = null;
		self::$primed_types       = [];
		self::$primed_taxonomies  = [];
	}

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! $this->settings->get( 'seo_sitemap_enabled', true ) ) {
			return;
		}

		// `seo_sitemap_source` selects which sitemap tree carries our
		// hreflang xhtml:link alternates. If a third-party SEO plugin
		// ships its own sitemap renderer (Yoast / Rank Math / AIOSEO /
		// SEOPress) AND its addon injects alternates into THAT tree,
		// injecting into the WP core sitemap as well would emit hreflang
		// in two parallel sitemap trees with different XML shapes, which
		// search engines may index as conflicting alternates. Skip the
		// core injection in those cases.
		if ( ! $this->should_inject_into_core_sitemap() ) {
			return;
		}

		// Include all languages in WordPress core sitemaps.
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'include_all_languages' ] );
		add_filter( 'wp_sitemaps_taxonomies_query_args', [ $this, 'include_all_languages_taxonomies' ] );

		// Add xhtml:link alternate elements to each sitemap entry.
		add_filter( 'wp_sitemaps_posts_entry', [ $this, 'add_alternate_links' ], 10, 3 );
		add_filter( 'wp_sitemaps_taxonomies_entry', [ $this, 'add_taxonomy_alternate_links' ], 10, 4 );

		// The blog-home entry (show_on_front=posts) is built by core as a
		// bare home_url() array that bypasses wp_sitemaps_posts_entry — it
		// only ever passes through this dedicated filter, so hook it too or
		// the homepage ships without hreflang alternates in every url_mode.
		add_filter( 'wp_sitemaps_posts_show_on_front_entry', [ $this, 'add_home_alternate_links' ] );

		// Register the xhtml namespace for the sitemap XML.
		add_filter( 'wp_sitemaps_index_entry', [ $this, 'pass_through' ] );

		// Swap WP core's sitemap renderer for our subclass that recognizes
		// the `xhtml:link` entry key. Core's default renderer silently
		// drops any field other than loc/lastmod/changefreq/priority, which
		// is why hreflang-in-sitemap never actually reaches the XML unless
		// the renderer itself is overridden.
		add_action( 'wp_sitemaps_init', [ $this, 'swap_renderer' ] );
	}

	/**
	 * Decide whether to wire up the WP core sitemap hooks.
	 *
	 * - source = 'core'   → always inject into core.
	 * - source = 'plugin' → never inject into core.
	 * - source = 'auto'   → skip core when an SEO plugin (Yoast / Rank
	 *                       Math / AIOSEO) is detected, since that
	 *                       plugin's addon already injects alternates
	 *                       into its own sitemap tree. Otherwise inject
	 *                       into core.
	 *
	 * Detection uses the user-facing `seo_plugin` setting first
	 * (explicit), then falls back to the plugin's own constant / class
	 * (defensive — covers users who haven't picked an SEO plugin in
	 * settings but DO have one active).
	 *
	 * Filterable via `perflocale/sitemap/inject_into_core` for sites with
	 * unusual setups (e.g. a third-party sitemap plugin that PerfLocale
	 * doesn't recognize).
	 */
	private function should_inject_into_core_sitemap(): bool {
		$source = (string) $this->settings->get( 'seo_sitemap_source', 'auto' );

		if ( $source === 'core' ) {
			$decision = true;
		} elseif ( $source === 'plugin' ) {
			$decision = false;
		} else {
			// auto: skip core when a known SEO plugin is active.
			$seo_plugin    = (string) $this->settings->get( 'seo_plugin', 'none' );
			$plugin_active = (
				$seo_plugin === 'yoast'
				|| $seo_plugin === 'rankmath'
				|| $seo_plugin === 'aioseo'
				|| defined( 'WPSEO_VERSION' )
				|| class_exists( 'RankMath' )
				|| defined( 'AIOSEO_FILE' )
			);
			$decision = ! $plugin_active;
		}

		/**
		 * Filter the decision to inject alternates into WP core sitemaps.
		 *
		 * @hook perflocale/sitemap/inject_into_core
		 * @param bool $decision True = inject xhtml:link into WP core sitemap entries.
		 */
		return (bool) apply_filters( 'perflocale/sitemap/inject_into_core', $decision );
	}

	/**
	 * Swap WP core's sitemap renderer for PerfLocale's subclass that
	 * supports xhtml:link child elements.
	 *
	 * Fires on the `wp_sitemaps_init` action which runs once per request,
	 * right after WP_Sitemaps is constructed. Safe to swap because
	 * WP_Sitemaps::$renderer is a public property.
	 *
	 * @param \WP_Sitemaps $sitemaps Core sitemap server instance.
	 * @return void
	 */
	public function swap_renderer( \WP_Sitemaps $sitemaps ): void {
		$sitemaps->renderer = new SitemapRenderer();
	}

	/**
	 * Include posts in all languages in sitemaps.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function include_all_languages( array $args ): array {
		$args['perflocale_all_languages'] = true;

		return $args;
	}

	/**
	 * Include terms in all languages in taxonomy sitemaps.
	 *
	 * @param array<string, mixed> $args Term query arguments.
	 * @return array<string, mixed>
	 */
	public function include_all_languages_taxonomies( array $args ): array {
		$args['perflocale_all_languages'] = true;

		return $args;
	}

	/**
	 * Add xhtml:link alternates to the blog-home sitemap entry
	 * (`show_on_front=posts`).
	 *
	 * The blog home exists in every active language (no publish-status
	 * gate applies), so one alternate per language is emitted, built via
	 * the mode-aware converter — path prefixes in subdirectory mode,
	 * `?lang=` in query mode, hosts in subdomain/domain modes.
	 *
	 * @param array<string, mixed> $entry Sitemap entry data (loc only).
	 * @return array<string, mixed>
	 */
	public function add_home_alternate_links( array $entry ): array {
		$this->prime_static_caches();

		if ( count( self::$languages_cache ) < 2 ) {
			return $entry;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return $entry;
		}

		$url_converter = $plugin->get( 'url_converter' );
		$default       = self::$default_lang_cache;
		$alternates    = [];
		$default_url   = null;

		foreach ( self::$languages_cache as $lang ) {
			if ( empty( $lang->slug ) || ! isset( self::$slug_map_cache[ $lang->slug ] ) ) {
				continue;
			}

			$href = $url_converter->convert( home_url( '/' ), $lang->slug );

			if ( $href === '' ) {
				continue;
			}

			$alternates[] = [
				'href'     => $href,
				'hreflang' => self::$slug_map_cache[ $lang->slug ],
			];

			if ( $default && $lang->slug === $default->slug ) {
				$default_url = $href;
			}
		}

		if ( count( $alternates ) > 1 ) {
			if ( $default_url && (bool) $this->settings->get( 'seo_x_default', true ) ) {
				$alternates[] = [
					'href'     => $default_url,
					'hreflang' => 'x-default',
				];
			}

			$entry['xhtml:link'] = $alternates;
		}

		return $entry;
	}

	/**
	 * Add xhtml:link alternate entries to a sitemap post entry.
	 *
	 * Adds translation URLs as alternate links so search engines understand
	 * the relationship between language versions of the same content.
	 *
	 * Uses batch preloading: when the first post in a sitemap page is
	 * processed, all translation links for that page are loaded in a
	 * single query. Subsequent calls use the preloaded cache.
	 *
	 * @param array<string, mixed> $entry Sitemap entry data.
	 * @param object               $post Post object (WP_Post or stdClass).
	 * @param string               $post_type Post type.
	 * @return array<string, mixed>
	 */
	public function add_alternate_links( array $entry, object $post, string $post_type ): array {
		$this->prime_static_caches();

		if ( count( self::$languages_cache ) < 2 ) {
			return $entry;
		}

		// Prime the post cache for all translation IDs on first call
		// PER post type. WordPress sitemaps render one page per post type,
		// and priming only once would miss all subsequent types.
		if ( empty( self::$primed_types[ $post_type ] ) ) {
			self::$primed_types[ $post_type ] = true;
			$this->prime_sitemap_caches( self::$repo_cache, $post_type );
		}

		// Per-post opt-out: a flagged entry advertises no alternates (and is
		// skipped as a sibling below) so excluded translations never appear
		// in any alternate set. O(1) against a per-request memoised id set.
		if ( \PerfLocale\Helper::is_seo_excluded( (int) $post->ID ) ) {
			return $entry;
		}

		$links = self::$repo_cache->get_translations( $post->ID, ObjectType::Post );

		if ( empty( $links ) ) {
			// Untranslated post. In host-based URL modes (subdomain/domain)
			// every language host serves its own /wp-sitemap.xml, and the loc
			// core built inherits the REQUEST host — so the same untranslated
			// post would be advertised self-canonically on every language
			// host (cross-host duplicate content). An unlinked object is
			// default-language content by definition, so pin the loc to the
			// default language; a no-op in path-based modes.
			return $this->pin_entry_loc_to_default( $entry );
		}

		// Detect front page translations - use language root URL instead of permalink.
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

		$plugin        = Plugin::get_instance();
		$url_converter = ( $is_front_page && $plugin->has( 'url_converter' ) )
			? $plugin->get( 'url_converter' )
			: null;

		// Collect all translation object IDs for batch status/permalink check.
		$alternates  = [];
		$default_url = null;
		$slug_map    = self::$slug_map_cache;
		$default     = self::$default_lang_cache;

		foreach ( $links as $link ) {
			if ( empty( $link->language_slug ) || ! isset( $slug_map[ $link->language_slug ] ) ) {
				continue;
			}

			$object_id = (int) $link->object_id;

			// get_post_status() uses the WP post cache (primed above).
			if ( get_post_status( $object_id ) !== 'publish' ) {
				continue;
			}

			if ( \PerfLocale\Helper::is_seo_excluded( $object_id ) ) {
				continue;
			}

			if ( $url_converter ) {
				$permalink = $url_converter->convert( home_url( '/' ), $link->language_slug );
			} else {
				$permalink = get_permalink( $object_id );
			}

			if ( ! $permalink ) {
				continue;
			}

			$alternates[] = [
				'href'     => $permalink,
				'hreflang' => $slug_map[ $link->language_slug ],
			];

			// Track default language URL for x-default.
			if ( $default && $link->language_slug === $default->slug ) {
				$default_url = $permalink;
			}
		}

		if ( count( $alternates ) > 1 ) {
			if ( $default_url && (bool) $this->settings->get( 'seo_x_default', true ) ) {
				$alternates[] = [
					'href'     => $default_url,
					'hreflang' => 'x-default',
				];
			}

			// Store under the standard W3C key 'xhtml:link'. WordPress core's
			// default renderer drops unknown fields silently - we swap in
			// PerfLocaleSitemapRenderer via wp_sitemaps_init below so this
			// array is serialized as real <xhtml:link rel="alternate" …> child
			// elements of <url>, which is the documented hreflang-in-sitemap
			// pattern (sitemaps.org + Google Search Central).
			$entry['xhtml:link'] = $alternates;
		}

		return $entry;
	}

	/**
	 * Pin a sitemap entry's loc to the default language's URL shape.
	 *
	 * Core builds loc from get_permalink() in the REQUEST context; in
	 * subdomain/domain URL modes home_url() is host-mapped to the current
	 * language, so on a language host an unlinked (default-language) object's
	 * loc inherits that host. Every language host serves its own
	 * /wp-sitemap.xml, so without this the same content is advertised
	 * self-canonically on every host. UrlConverter::convert() to the default
	 * language maps the host back to the base domain; in path-based modes the
	 * default language is unprefixed, making this a byte-identical no-op.
	 *
	 * @param array<string, mixed> $entry Sitemap entry.
	 * @return array<string, mixed>
	 */
	private function pin_entry_loc_to_default( array $entry ): array {
		$default = self::$default_lang_cache;

		if ( ! $default || empty( $entry['loc'] ) ) {
			return $entry;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'url_converter' ) ) {
			return $entry;
		}

		$pinned = (string) $plugin->get( 'url_converter' )->convert( (string) $entry['loc'], (string) $default->slug );

		if ( $pinned !== '' ) {
			$entry['loc'] = $pinned;
		}

		return $entry;
	}

	/**
	 * Populate the class-level caches on first use. Cheap no-op afterwards.
	 *
	 * @return void
	 */
	private function prime_static_caches(): void {
		if ( self::$repo_cache !== null ) {
			return;
		}

		$plugin                   = Plugin::get_instance();
		$cache                    = $plugin->get( 'cache' );
		self::$repo_cache         = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		self::$lang_repo_cache    = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
		self::$languages_cache    = self::$lang_repo_cache->get_active();
		self::$default_lang_cache = self::$lang_repo_cache->get_default();

		$slug_map = [];

		foreach ( self::$languages_cache as $lang ) {
			$slug_map[ $lang->slug ] = $this->locale_to_hreflang( $lang->locale );
		}

		self::$slug_map_cache = $slug_map;
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

	/**
	 * Add xhtml:link alternate entries to a taxonomy sitemap entry.
	 *
	 * @param array<string, mixed> $entry Sitemap entry data.
	 * @param int                  $term_id Term ID.
	 * @param string               $taxonomy Taxonomy name.
	 * @param object               $term Term object.
	 * @return array<string, mixed>
	 */
	public function add_taxonomy_alternate_links( array $entry, int $term_id, string $taxonomy, object $term ): array {
		$this->prime_static_caches();

		if ( count( self::$languages_cache ) < 2 ) {
			return $entry;
		}

		// Batch-prime term caches once per taxonomy (mirrors the post path):
		// otherwise each sibling's get_term_link() below issues its own
		// get_term() query, an N+1 that scales with page size × languages.
		if ( empty( self::$primed_taxonomies[ $taxonomy ] ) ) {
			self::$primed_taxonomies[ $taxonomy ] = true;
			$this->prime_sitemap_term_caches( $taxonomy );
		}

		$links = self::$repo_cache->get_translations( $term_id, ObjectType::Term );

		if ( empty( $links ) ) {
			// Untranslated term — same cross-host duplicate-content guard as
			// the post path.
			return $this->pin_entry_loc_to_default( $entry );
		}

		$slug_map    = self::$slug_map_cache;
		$default     = self::$default_lang_cache;
		$alternates  = [];
		$default_url = null;

		foreach ( $links as $link ) {
			if ( empty( $link->language_slug ) || ! isset( $slug_map[ $link->language_slug ] ) ) {
				continue;
			}

			$term_link = get_term_link( (int) $link->object_id );

			if ( is_wp_error( $term_link ) ) {
				continue;
			}

			$alternates[] = [
				'href'     => $term_link,
				'hreflang' => $slug_map[ $link->language_slug ],
			];

			if ( $default && $link->language_slug === $default->slug ) {
				$default_url = $term_link;
			}
		}

		if ( count( $alternates ) > 1 ) {
			if ( $default_url && (bool) $this->settings->get( 'seo_x_default', true ) ) {
				$alternates[] = [
					'href'     => $default_url,
					'hreflang' => 'x-default',
				];
			}

			// Store under the standard W3C key 'xhtml:link'. WordPress core's
			// default renderer drops unknown fields silently - we swap in
			// PerfLocaleSitemapRenderer via wp_sitemaps_init below so this
			// array is serialized as real <xhtml:link rel="alternate" …> child
			// elements of <url>, which is the documented hreflang-in-sitemap
			// pattern (sitemaps.org + Google Search Central).
			$entry['xhtml:link'] = $alternates;
		}

		return $entry;
	}

	/**
	 * Batch-prime WordPress post caches for all translation siblings.
	 *
	 * Loads all translation link object IDs for the current post type
	 * in a single query, then primes the WP post cache so subsequent
	 * get_post_status() and get_permalink() calls are free.
	 *
	 * @param TranslationGroupRepository $repo Repository instance.
	 * @param string                     $post_type Post type.
	 * @return void
	 */
	private function prime_sitemap_caches( TranslationGroupRepository $repo, string $post_type ): void {
		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );

		// Memory bound: a sitemap PAGE renders at most wp_sitemaps_get_max_urls()
		// (2000) URLs, so priming every translated post of the type is wasteful
		// and, on a very large site (tens of thousands of translated posts),
		// loads them all into memory on a single sitemap request. Cap the prime
		// at a generous multiple of one page; SELECT one row past the cap so we
		// can detect the over-limit case cheaply. When the type has MORE than
		// the cap, skip bulk priming entirely — the per-entry get_post_status()/
		// get_permalink() lookups below still return correct output, they just
		// query per sibling instead of from a warmed cache. Filterable.
		$max_urls = function_exists( 'wp_sitemaps_get_max_urls' ) ? (int) wp_sitemaps_get_max_urls( 'post' ) : 2000;

		// Bound the prime by what ONE PAGE can actually consume, not by a flat
		// multiple of it. A page renders at most $max_urls entries, and each
		// entry needs its siblings — so $max_urls * languages is the most this
		// request can use. The previous flat 10x meant a site with, say, 15,000
		// translated posts loaded all 15,000 into memory on every sitemap page
		// request (measured ~4 KB/post, so tens of MB) while rendering 2,000
		// URLs — enough to exhaust a 128 MB frontend limit that bots then hit
		// on every page of the tree. It also inverted the risk: a >20,000-post
		// site fell through to the safe per-entry path while a 15,000-post site
		// did not. Sites whose catalog fits inside one page's need are
		// unaffected and still get the full bulk prime.
		$lang_count = max( 2, count( self::$languages_cache ) );
		$prime_cap  = max( 2000, (int) apply_filters( 'perflocale/sitemap/max_prime', $max_urls * $lang_count ) );

		// Get translation object IDs for this post type in one query, bounded.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$all_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT l.object_id
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id AND g.type = 'post'
				WHERE l.object_id IN (
					SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
				)
				LIMIT %d",
				$links_table,
				$groups_table,
				$post_type,
				$prime_cap + 1
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Over the cap: bulk priming would blow memory to no benefit (the page
		// only needs ~2000 of these). Bail to per-entry lookups.
		if ( count( (array) $all_ids ) > $prime_cap ) {
			return;
		}

		if ( ! empty( $all_ids ) ) {
			$ids = array_map( 'intval', $all_ids );

			// Prime WP post cache in one batch - makes get_post_status()
			// and get_permalink() use the cache instead of querying.
			// `_prime_post_caches` is an internal WP function that could
			// in theory be renamed; fall back to a plain get_posts() pull
			// which triggers the same cache priming via WP_Query.
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $ids, true, false );
			} else {
				get_posts(
					[
						'post__in'               => $ids,
						'posts_per_page'         => count( $ids ),
						'post_type'              => 'any',
						'post_status'            => 'any',
						'no_found_rows'          => true,
						'update_post_term_cache' => true,
						'update_post_meta_cache' => false,
					]
				);
			}
		}
	}

	/**
	 * Batch-prime WordPress term caches for all translation siblings of a
	 * taxonomy. Same bounded design as {@see prime_sitemap_caches()} for posts:
	 * one query capped at a generous multiple of a sitemap page, and a bail on
	 * the over-cap case (per-entry get_term_link() lookups stay correct).
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	private function prime_sitemap_term_caches( string $taxonomy ): void {
		if ( ! function_exists( '_prime_term_caches' ) ) {
			return;
		}

		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );

		// Page-proportional bound — see prime_sitemap_caches() for why a flat
		// multiple of the page size over-fetches on large catalogues.
		$max_urls   = function_exists( 'wp_sitemaps_get_max_urls' ) ? (int) wp_sitemaps_get_max_urls( 'term' ) : 2000;
		$lang_count = max( 2, count( self::$languages_cache ) );
		$prime_cap  = max( 2000, (int) apply_filters( 'perflocale/sitemap/max_prime', $max_urls * $lang_count ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$all_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT l.object_id
				FROM %i l
				INNER JOIN %i g ON l.group_id = g.id AND g.type = 'term'
				WHERE l.object_id IN (
					SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s
				)
				LIMIT %d",
				$links_table,
				$groups_table,
				$taxonomy,
				$prime_cap + 1
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( count( (array) $all_ids ) > $prime_cap ) {
			return;
		}

		if ( ! empty( $all_ids ) ) {
			_prime_term_caches( array_map( 'intval', $all_ids ) );
		}
	}

	/**
	 * Pass-through filter for index entries.
	 *
	 * @param array<string, mixed> $entry Index entry.
	 * @return array<string, mixed>
	 */
	public function pass_through( array $entry ): array {
		return $entry;
	}
}
