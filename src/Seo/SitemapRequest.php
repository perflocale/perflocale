<?php
/**
 * Multilingual sitemap integration: the work of a sitemap request.
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
 * The sitemap-request callbacks of {@see SitemapIntegration}: hreflang
 * alternates of post, term and blog-home entries, the page captures and the
 * cache priming. SitemapIntegration builds it on the first sitemap callback.
 */
final class SitemapRequest {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Per-request caches of the alternate builders, static so reset_caches() can clear them on switch_blog.
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
	 * Language slug -> hreflang value.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $slug_map_cache = null;

	/**
	 * Post types primed in this sitemap run, keyed by type: a run can render several post types.
	 *
	 * @var array<string, bool>
	 */
	private static array $primed_types = [];

	/**
	 * Taxonomies whose term caches are primed in this sitemap run.
	 *
	 * @var array<string, bool>
	 */
	private static array $primed_taxonomies = [];

	/**
	 * Query var that marks the core sitemap's own page query (a WP_Query for
	 * posts, a WP_Term_Query for terms), so capture_page_posts() and
	 * capture_page_term_query() record that query and no other.
	 *
	 * @var string
	 */
	public const PAGE_QUERY_VAR = 'perflocale_sitemap_page';

	/**
	 * Posts or terms primed per statement when a type or taxonomy is over the
	 * bulk-prime cap and only this page's sibling translations are primed.
	 *
	 * @var int
	 */
	private const SIBLING_PRIME_CHUNK = 500;

	/**
	 * Terms whose links are primed per statement, as in UrlConverter::preload_term_languages().
	 *
	 * The link query has no ORDER BY: a larger batch could reorder a page's alternates.
	 *
	 * @var int
	 */
	private const TERM_LINK_PRIME_CHUNK = 500;

	/**
	 * IDs of the posts the latest core sitemap page query returned, by post
	 * type, until the first entry of that page consumes them. Each page query
	 * replaces the set, so it never holds more than one page.
	 *
	 * @var array<string, array<int, int>>
	 */
	private static array $page_post_ids = [];

	/**
	 * Post types over the bulk-prime cap: each sitemap page primes the
	 * translation siblings of its own posts (prime_page_siblings()).
	 *
	 * @var array<string, bool>
	 */
	private static array $over_cap_types = [];

	/**
	 * The latest core taxonomy sitemap page query, by taxonomy, until the
	 * first entry of that page reads its terms. Each page query replaces it.
	 *
	 * @var array<string, \WP_Term_Query>
	 */
	private static array $page_term_queries = [];

	/**
	 * Taxonomies over the bulk-prime cap: each sitemap page primes the
	 * translation siblings of its own terms (prime_page_term_siblings()).
	 *
	 * @var array<string, bool>
	 */
	private static array $over_cap_taxonomies = [];

	/**
	 * Reset per-request caches when multisite switches blog context.
	 *
	 * @return void
	 */
	public static function reset_caches(): void {
		self::$repo_cache          = null;
		self::$lang_repo_cache     = null;
		self::$languages_cache     = null;
		self::$default_lang_cache  = null;
		self::$slug_map_cache      = null;
		self::$primed_types        = [];
		self::$primed_taxonomies   = [];
		self::$page_post_ids       = [];
		self::$over_cap_types      = [];
		self::$page_term_queries   = [];
		self::$over_cap_taxonomies = [];
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
	 * Record the IDs of the posts a core post sitemap page lists.
	 *
	 * Only the page query (PAGE_QUERY_VAR); each page, an empty one included, replaces the set.
	 *
	 * @param mixed $posts Posts the query returned.
	 * @param mixed $query The query.
	 * @return mixed The posts, unchanged.
	 */
	public function capture_page_posts( mixed $posts, mixed $query ): mixed {
		if ( ! $query instanceof \WP_Query || ! is_array( $posts ) || ! $query->get( self::PAGE_QUERY_VAR ) ) {
			return $posts;
		}

		$post_type = $query->get( 'post_type' );

		if ( ! is_string( $post_type ) || $post_type === '' ) {
			return $posts;
		}

		$ids = [];

		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && $post->ID > 0 ) {
				$ids[ $post->ID ] = $post->ID;
			}
		}

		self::$page_post_ids[ $post_type ] = $ids;

		return $posts;
	}

	/**
	 * Record the term query a core taxonomy sitemap page runs.
	 *
	 * Only the page query loading whole terms (not the count query); the page's first entry
	 * reads its terms once it has run.
	 *
	 * @param mixed $query The term query.
	 * @return void
	 */
	public function capture_page_term_query( mixed $query ): void {
		if ( ! $query instanceof \WP_Term_Query || empty( $query->query_vars[ self::PAGE_QUERY_VAR ] ) || ( $query->query_vars['fields'] ?? '' ) !== 'all' ) {
			return;
		}

		$taxonomy = $query->query_vars['taxonomy'] ?? null;

		if ( is_array( $taxonomy ) && count( $taxonomy ) === 1 ) {
			$taxonomy = reset( $taxonomy );
		}

		if ( ! is_string( $taxonomy ) || $taxonomy === '' ) {
			return;
		}

		self::$page_term_queries[ $taxonomy ] = $query;
	}

	/**
	 * Add xhtml:link alternates to the blog-home sitemap entry (`show_on_front=posts`).
	 *
	 * The home exists in every active language: one alternate each, through the mode-aware converter.
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
	 * The first entry primes the siblings' caches: per post type, or per page over the cap.
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

		// Once per post type: a run can render several post types.
		if ( empty( self::$primed_types[ $post_type ] ) ) {
			self::$primed_types[ $post_type ] = true;
			$this->prime_sitemap_caches( $post_type );
		}

		// The first entry of each captured page: over the cap, prime that
		// page's siblings; then drop its IDs. A later page of the same run
		// (a CLI or background builder) captures and primes its own.
		if ( isset( self::$page_post_ids[ $post_type ] ) ) {
			if ( isset( self::$over_cap_types[ $post_type ] ) ) {
				$this->prime_page_siblings( $post_type );
			}

			unset( self::$page_post_ids[ $post_type ] );
		}

		// Per-post SEO opt-out: no alternates here, and skipped as a sibling below.
		if ( \PerfLocale\Helper::is_seo_excluded( (int) $post->ID ) ) {
			return $entry;
		}

		$links = self::$repo_cache->get_translations( $post->ID, ObjectType::Post );

		if ( empty( $links ) ) {
			// Unlinked = default-language content: pin its loc, or in host modes every
			// language host's sitemap would list it under its own host.
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

			// Core's renderer drops unknown keys: SitemapRenderer writes this as <xhtml:link>.
			$entry['xhtml:link'] = $alternates;
		}

		return $entry;
	}

	/**
	 * Pin a sitemap entry's loc to the default language's URL shape.
	 *
	 * In host modes the loc inherits the request's language host; in path modes this is a no-op.
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
	 * Convert a WordPress locale to a BCP 47 hreflang tag (`en_US` -> `en-US`).
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

		// Once per taxonomy, or each sibling's get_term_link() runs its own query.
		if ( empty( self::$primed_taxonomies[ $taxonomy ] ) ) {
			self::$primed_taxonomies[ $taxonomy ] = true;
			$this->prime_sitemap_term_caches( $taxonomy );
		}

		// The first entry of each captured page: over the cap, prime that
		// page's siblings; then drop the query.
		if ( isset( self::$page_term_queries[ $taxonomy ] ) ) {
			if ( isset( self::$over_cap_taxonomies[ $taxonomy ] ) ) {
				$this->prime_page_term_siblings( self::$page_term_queries[ $taxonomy ]->terms );
			}

			unset( self::$page_term_queries[ $taxonomy ] );
		}

		$links = self::$repo_cache->get_translations( $term_id, ObjectType::Term );

		if ( empty( $links ) ) {
			// Unlinked: the same loc pin as the post path.
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

			// Core's renderer drops unknown keys: SitemapRenderer writes this as <xhtml:link>.
			$entry['xhtml:link'] = $alternates;
		}

		return $entry;
	}

	/**
	 * Batch-prime the post caches of a post type's translated posts in one query.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function prime_sitemap_caches( string $post_type ): void {
		global $wpdb;

		$links_table  = \PerfLocale\Database\Schema::table( 'translation_links' );
		$groups_table = \PerfLocale\Database\Schema::table( 'translation_groups' );

		// Memory bound: one row past the cap marks the type over it, and each page then primes
		// only its own siblings (prime_page_siblings()).
		$max_urls = function_exists( 'wp_sitemaps_get_max_urls' ) ? (int) wp_sitemaps_get_max_urls( 'post' ) : 2000;

		// Cap = max URLs x languages, what one page can use, never a flat multiple. See
		// docs-ai/11-code-rationale.md, "SitemapRequest: prime cap".
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

		// Over the cap: bulk priming would load posts this page never lists.
		// Each page primes its own siblings instead (add_alternate_links()).
		if ( count( (array) $all_ids ) > $prime_cap ) {
			self::$over_cap_types[ $post_type ] = true;
			return;
		}

		if ( ! empty( $all_ids ) ) {
			$ids = array_map( 'intval', $all_ids );

			// One batch for get_post_status()/get_permalink(); get_posts() primes the same
			// caches should the internal _prime_post_caches() go away.
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
	 * Prime the siblings of the posts this sitemap page lists, for a post type over the cap.
	 *
	 * Links in one batch, siblings off the page in SIBLING_PRIME_CHUNK chunks. Without a
	 * captured page query nothing is primed: the per-entry lookups stay correct.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function prime_page_siblings( string $post_type ): void {
		$repo     = self::$repo_cache;
		$page_ids = array_values( self::$page_post_ids[ $post_type ] ?? [] );

		if ( $repo === null || $page_ids === [] || ! function_exists( '_prime_post_caches' ) ) {
			return;
		}

		$repo->prime_translations( ObjectType::Post, $page_ids );

		$on_page  = self::$page_post_ids[ $post_type ];
		$siblings = [];

		foreach ( $page_ids as $page_id ) {
			foreach ( $repo->get_translations( $page_id, ObjectType::Post ) as $link ) {
				if ( ! is_object( $link ) || ! isset( $link->object_id ) || ! is_numeric( $link->object_id ) ) {
					continue;
				}

				$sibling_id = (int) $link->object_id;

				if ( $sibling_id > 0 && ! isset( $on_page[ $sibling_id ] ) ) {
					$siblings[ $sibling_id ] = $sibling_id;
				}
			}
		}

		foreach ( array_chunk( array_values( $siblings ), self::SIBLING_PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, true, false );
		}
	}

	/**
	 * Batch-prime the term caches of a taxonomy's translated terms, bounded as prime_sitemap_caches().
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

		// Page-proportional cap, as in prime_sitemap_caches().
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
			self::$over_cap_taxonomies[ $taxonomy ] = true;
			return;
		}

		if ( ! empty( $all_ids ) ) {
			_prime_term_caches( array_map( 'intval', $all_ids ) );
		}
	}

	/**
	 * Prime the siblings of the terms this sitemap page lists, for a taxonomy over the cap.
	 *
	 * Siblings off the page are primed without term meta (get_term_link() does not read it).
	 * Without a captured page query nothing is primed: the per-entry lookups stay correct.
	 *
	 * @param mixed $terms The page query's terms.
	 * @return void
	 */
	private function prime_page_term_siblings( mixed $terms ): void {
		$repo = self::$repo_cache;

		if ( $repo === null || ! is_array( $terms ) || ! function_exists( '_prime_term_caches' ) ) {
			return;
		}

		$on_page = [];

		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && $term->term_id > 0 ) {
				$on_page[ $term->term_id ] = $term->term_id;
			}
		}

		if ( $on_page === [] ) {
			return;
		}

		foreach ( array_chunk( array_values( $on_page ), self::TERM_LINK_PRIME_CHUNK ) as $chunk ) {
			$repo->prime_translations( ObjectType::Term, $chunk );
		}

		$siblings = [];

		foreach ( $on_page as $term_id ) {
			foreach ( $repo->get_translations( $term_id, ObjectType::Term ) as $link ) {
				if ( ! is_object( $link ) || ! isset( $link->object_id ) || ! is_numeric( $link->object_id ) ) {
					continue;
				}

				$sibling_id = (int) $link->object_id;

				if ( $sibling_id > 0 && ! isset( $on_page[ $sibling_id ] ) ) {
					$siblings[ $sibling_id ] = $sibling_id;
				}
			}
		}

		foreach ( array_chunk( array_values( $siblings ), self::SIBLING_PRIME_CHUNK ) as $chunk ) {
			_prime_term_caches( $chunk, false );
		}
	}
}
