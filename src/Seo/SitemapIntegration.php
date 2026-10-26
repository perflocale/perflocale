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

		// Alternates go into one sitemap tree only: two trees can read as conflicting alternates.
		if ( ! $this->should_inject_into_core_sitemap() ) {
			return;
		}

		// Include all languages in WordPress core sitemaps.
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'include_all_languages' ] );
		add_filter( 'wp_sitemaps_taxonomies_query_args', [ $this, 'include_all_languages_taxonomies' ] );

		// Add xhtml:link alternate elements to each sitemap entry.
		add_filter( 'wp_sitemaps_posts_entry', [ $this, 'add_alternate_links' ], 10, 3 );
		add_filter( 'wp_sitemaps_taxonomies_entry', [ $this, 'add_taxonomy_alternate_links' ], 10, 4 );

		// Core builds the blog-home entry outside wp_sitemaps_posts_entry; only this filter sees it.
		add_filter( 'wp_sitemaps_posts_show_on_front_entry', [ $this, 'add_home_alternate_links' ] );

		// No-op filter: index entries are returned unchanged.
		add_filter( 'wp_sitemaps_index_entry', [ $this, 'pass_through' ] );

		// Core's renderer drops the `xhtml:link` key, so it is replaced.
		add_action( 'wp_sitemaps_init', [ $this, 'swap_renderer' ] );
	}

	/**
	 * Whether to wire up the core sitemap hooks (`seo_sitemap_source`: core, plugin or auto).
	 *
	 * Auto skips core when an SEO plugin is set or detected: its add-on fills that plugin's sitemap.
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
	 * Swap core's sitemap renderer for the subclass that writes xhtml:link elements.
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

		// Only sitemap requests gain a the_posts callback; re-adding it is a no-op.
		add_filter( 'the_posts', [ $this, 'capture_page_posts' ], 10, 2 );

		return $args;
	}

	/**
	 * Record the IDs of the posts a core post sitemap page lists (marked query only).
	 *
	 * Over the bulk-prime cap, the page's first entry primes the siblings of exactly these posts.
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

		// Only sitemap requests gain a pre_get_terms callback; re-adding it is a no-op.
		add_action( 'pre_get_terms', [ $this, 'capture_page_term_query' ] );

		return $args;
	}

	/**
	 * Record the term query a core taxonomy sitemap page runs (marked, whole-term query only).
	 *
	 * Over the bulk-prime cap, the page's first entry primes the siblings of exactly these terms.
	 *
	 * @param mixed $query The term query.
	 * @return void
	 */
	public function capture_page_term_query( mixed $query ): void {
		$this->request()->capture_page_term_query( $query );
	}

	/**
	 * Add xhtml:link alternates to the blog-home sitemap entry (`show_on_front=posts`).
	 *
	 * The blog home exists in every active language, so each gets an alternate.
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
	 * The page's first entry loads all its translation links in one query.
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
