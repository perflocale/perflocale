<?php
/**
 * Multilingual sitemap integration.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Seo;

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
	 * The sitemap-request work, built on the first sitemap callback.
	 *
	 * @var SitemapRequest|null
	 */
	private ?SitemapRequest $request = null;

	/**
	 * Reset per-request caches when multisite switches blog context.
	 *
	 * @return void
	 */
	public static function reset_caches(): void {
		if ( class_exists( SitemapRequest::class, false ) ) {
			SitemapRequest::reset_caches();
		}
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
		// bare home_url() array that bypasses wp_sitemaps_posts_entry - it
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
	 * (defensive - covers users who haven't picked an SEO plugin in
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
	 * The sitemap-request work.
	 *
	 * @return SitemapRequest
	 */
	private function request(): SitemapRequest {
		return $this->request ??= new SitemapRequest( $this->settings );
	}

	/**
	 * Include posts in all languages in sitemaps.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function include_all_languages( array $args ): array {
		$args['perflocale_all_languages']       = true;
		$args[ SitemapRequest::PAGE_QUERY_VAR ] = true;

		// Registered only once a core post sitemap builds its query, so no
		// other request gains a the_posts callback. Adding the same callback
		// again is a no-op.
		add_filter( 'the_posts', [ $this, 'capture_page_posts' ], 10, 2 );

		return $args;
	}

	/**
	 * Record the IDs of the posts a core post sitemap page lists.
	 *
	 * Only the query marked by include_all_languages() is recorded, and it
	 * replaces the previous page's IDs (an empty page included). Over the
	 * bulk-prime cap, the page's first entry primes the translation siblings
	 * of exactly these posts.
	 *
	 * @param mixed $posts Posts the query returned.
	 * @param mixed $query The query.
	 * @return mixed The posts, unchanged.
	 */
	public function capture_page_posts( mixed $posts, mixed $query ): mixed {
		return $this->request()->capture_page_posts( $posts, $query );
	}

	/**
	 * Include terms in all languages in taxonomy sitemaps.
	 *
	 * @param array<string, mixed> $args Term query arguments.
	 * @return array<string, mixed>
	 */
	public function include_all_languages_taxonomies( array $args ): array {
		$args['perflocale_all_languages']       = true;
		$args[ SitemapRequest::PAGE_QUERY_VAR ] = true;

		// Registered only once a core taxonomy sitemap builds its query, so
		// no other request gains a pre_get_terms callback. Adding the same
		// callback again is a no-op.
		add_action( 'pre_get_terms', [ $this, 'capture_page_term_query' ] );

		return $args;
	}

	/**
	 * Record the term query a core taxonomy sitemap page runs.
	 *
	 * Only the query marked by include_all_languages_taxonomies() that loads
	 * whole terms is recorded (not the page-count query), and it replaces the
	 * previous page's query. Its terms are read by the page's first entry,
	 * after the query has run: over the bulk-prime cap, that entry primes the
	 * translation siblings of exactly these terms.
	 *
	 * @param mixed $query The term query.
	 * @return void
	 */
	public function capture_page_term_query( mixed $query ): void {
		$this->request()->capture_page_term_query( $query );
	}

	/**
	 * Add xhtml:link alternates to the blog-home sitemap entry
	 * (`show_on_front=posts`).
	 *
	 * The blog home exists in every active language (no publish-status
	 * gate applies), so one alternate per language is emitted, built via
	 * the mode-aware converter - path prefixes in subdirectory mode,
	 * `?lang=` in query mode, hosts in subdomain/domain modes.
	 *
	 * @param array<string, mixed> $entry Sitemap entry data (loc only).
	 * @return array<string, mixed>
	 */
	public function add_home_alternate_links( array $entry ): array {
		return $this->request()->add_home_alternate_links( $entry );
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
		return $this->request()->add_alternate_links( $entry, $post, $post_type );
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
		return $this->request()->add_taxonomy_alternate_links( $entry, $term_id, $taxonomy, $term );
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
