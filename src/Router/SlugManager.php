<?php
/**
 * Slug translation manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\SlugTranslationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages slug translation preloading and lookups.
 *
 * Batches the slug rows of each the_posts result into one query, so permalinks build without per-post reads.
 */
final class SlugManager {

	/**
	 * In-memory slug memo: [blog_id][object_type][object_id][language_id] = slug, or null for no row.
	 *
	 * Keyed by blog id: one instance serves every blog after switch_to_blog(), and object ids repeat
	 * across subsites. Capped per (blog, type) at SLUGS_CAP for long CLI walks.
	 *
	 * @var array<int, array<string, array<int, array<int, string|null>>>>
	 */
	private array $slugs = [];

	/**
	 * Soft cap on $slugs entries per (blog_id, object_type); the oldest 25% are evicted FIFO.
	 */
	private const SLUGS_CAP = 5000;

	/**
	 * Core block-theme infrastructure post types left out of the slug batch (no null sentinel either).
	 *
	 * An explicit list on purpose: a viewable or never-scoped test would also drop wp_navigation, wp_block
	 * or ct_template, whose REST `link` reads slugs. See docs-ai/11-code-rationale.md, "SlugManager: unbatched post types".
	 *
	 * @var array<string, true>
	 */
	private const UNBATCHED_POST_TYPES = [
		'wp_global_styles' => true,
		'wp_template'      => true,
		'wp_template_part' => true,
	];

	/**
	 * Per-request gate snapshot for preload_slugs(): translate_slugs, current language id, active language ids.
	 *
	 * Static so reset_static_caches() can drop it when languages change mid-request.
	 *
	 * @var array{enabled:bool,language_id:int,all_lang_ids:array<int,int>}|null
	 */
	private static ?array $gate_memo = null;

	/**
	 * Clear the per-request gate snapshot (language CRUD hooks, and switch_blog on multisite).
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$gate_memo = null;
	}

	/**
	 * Drop this blog's slug memo and the slug gate memos after a cache flush.
	 *
	 * @return void
	 */
	public function forget_slugs(): void {
		unset( $this->slugs[ (int) get_current_blog_id() ] );
		self::$gate_memo = null;
		SlugTranslationRepository::reset_static_caches();
	}

	/**
	 * Cache manager.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Slug repository (lazy loaded).
	 *
	 * @var SlugTranslationRepository|null
	 */
	private ?SlugTranslationRepository $repo = null;

	/**
	 * Runs a queued term slug prime before a term slug is read.
	 *
	 * Called from get_slug() on a term miss, not at UrlConverter's call sites, so every caller of the
	 * slug_manager service (addons too) is covered. See docs-ai/11-code-rationale.md, "SlugManager: queued term slug prime".
	 *
	 * @var (\Closure(int): void)|null
	 */
	private ?\Closure $term_slug_prime_flusher = null;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register the callback that runs a queued term slug prime.
	 *
	 * @internal Wired by UrlConverter::register_hooks(); not an extension point.
	 *
	 * @param \Closure $flusher Receives the ID of the term whose slug is about to be read.
	 * @phpstan-param \Closure(int): void $flusher
	 * @return void
	 */
	public function set_term_slug_prime_flusher( \Closure $flusher ): void {
		$this->term_slug_prime_flusher = $flusher;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// The memos would otherwise outlive a flush in a long (WP-CLI) process.
		add_action( 'perflocale/cache/flush_all', [ $this, 'forget_slugs' ] );
		add_action( 'perflocale/cache/flush_slugs', [ $this, 'forget_slugs' ] );

		// Preload slug translations for WP_Query results (setting cache_preload_slugs, default on).
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'settings' ) && ! (bool) $plugin->get( 'settings' )->get( 'cache_preload_slugs', true ) ) {
			return;
		}

		add_filter( 'the_posts', [ $this, 'preload_slugs' ], 10, 2 );

		$reset_static = [ self::class, 'reset_static_caches' ];

		// Invalidate the gate memo when languages change mid-request.
		add_action( 'perflocale/language/added', $reset_static );
		add_action( 'perflocale/language/updated', $reset_static );
		add_action( 'perflocale/language/slug_renamed', $reset_static );
		add_action( 'perflocale/language/deleted', $reset_static );

		if ( is_multisite() ) {
			add_action( 'switch_blog', $reset_static );
		}
	}

	/**
	 * Preload slug translations for all posts in a WP_Query result.
	 *
	 * Skips non-WP_Post members: the_posts runs before WP_Query's array_map( 'get_post' ), so one can be null.
	 *
	 * @param array<int, mixed> $posts Posts returned by WP_Query; non-WP_Post members are skipped.
	 * @param \WP_Query         $query The WP_Query instance.
	 * @return array<int, mixed> The same array, unmodified (this is a filter).
	 */
	public function preload_slugs( array $posts, \WP_Query $query ): array {
		if ( empty( $posts ) || is_admin() ) {
			return $posts;
		}

		// Gate memo shared by every WP_Query of the request. It holds every active language because
		// hreflang and the switcher read other languages' slugs, which one batched query primes.
		if ( self::$gate_memo === null ) {
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( ! $plugin->has( 'router' ) || ! $plugin->has( 'settings' ) ) {
				return $posts;
			}

			/** @var LanguageRouter $router */
			$router   = $plugin->get( 'router' );
			$settings = $plugin->get( 'settings' );

			$all_lang_ids = [];

			foreach ( $router->get_active_languages() as $lang ) {
				$lid = (int) ( $lang->id ?? 0 );

				if ( $lid > 0 ) {
					$all_lang_ids[] = $lid;
				}
			}

			self::$gate_memo = [
				'enabled'      => $settings->translate_slugs_enabled(),
				'language_id'  => $router->get_current_language_id(),
				'all_lang_ids' => $all_lang_ids,
			];
		}

		$gate = self::$gate_memo;

		if ( ! $gate['enabled'] || $gate['language_id'] === 0 ) {
			return $posts;
		}

		// No slug rows at all: seed null sentinels instead of running a query that returns nothing.
		$blog_id = (int) get_current_blog_id();

		if ( ! $this->get_repo()->has_any_slugs() ) {
			$language_id  = $gate['language_id'];
			$all_lang_ids = $gate['all_lang_ids'] === [] ? [ $language_id ] : $gate['all_lang_ids'];

			foreach ( $posts as $p ) {
				if ( ! ( $p instanceof \WP_Post ) ) {
					continue;
				}

				$pid = (int) $p->ID;

				foreach ( $all_lang_ids as $lid ) {
					if ( ! isset( $this->slugs[ $blog_id ]['post'][ $pid ][ $lid ] ) ) {
						$this->slugs[ $blog_id ]['post'][ $pid ][ $lid ] = null;
					}
				}
			}

			return $posts;
		}

		$language_id  = $gate['language_id'];
		$all_lang_ids = $gate['all_lang_ids'];
		$post_ids     = [];

		// Infrastructure posts are skipped, not seeded (UNBATCHED_POST_TYPES);
		// a result made only of them returns below without a query.
		foreach ( $posts as $p ) {
			if ( $p instanceof \WP_Post && ! isset( self::UNBATCHED_POST_TYPES[ $p->post_type ] ) ) {
				$post_ids[] = (int) $p->ID;
			}
		}

		if ( $post_ids === [] ) {
			return $posts;
		}

		// Every (post, current language) pair already primed by an earlier query: nothing to do.
		$any_missing = false;

		foreach ( $post_ids as $pid ) {
			if ( ! isset( $this->slugs[ $blog_id ]['post'][ $pid ] )
				|| ! array_key_exists( $language_id, $this->slugs[ $blog_id ]['post'][ $pid ] ) ) {
				$any_missing = true;
				break;
			}
		}

		if ( ! $any_missing ) {
			return $posts;
		}

		// One query for every active language (see the gate memo above).
		if ( $all_lang_ids === [] ) {
			$all_lang_ids = [ $language_id ];
		}

		$this->preload_slugs_multilang( $post_ids, $all_lang_ids );

		return $posts;
	}

	/**
	 * Batch-load slug translations for a set of posts across many languages.
	 *
	 * Pairs without a row get a null sentinel so later lookups skip the repository.
	 *
	 * @param array<int, int> $post_ids Post IDs to prime.
	 * @param array<int, int> $lang_ids Language IDs to prime across.
	 * @return void
	 */
	private function preload_slugs_multilang( array $post_ids, array $lang_ids ): void {
		if ( $post_ids === [] || $lang_ids === [] ) {
			return;
		}

		global $wpdb;

		$slug_table = \PerfLocale\Database\Schema::table( 'slug_translations' );

		$pid_ph  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$lang_ph = implode( ',', array_fill( 0, count( $lang_ids ), '%d' ) );
		$args    = array_merge( $post_ids, $lang_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table names from Schema::table(), placeholders are %d.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, language_id, slug
				 FROM %i
				 WHERE object_type = 'post'
				 AND object_id IN ({$pid_ph})
				 AND language_id IN ({$lang_ph})",
				$slug_table,
				...$args
			)
		);
		// phpcs:enable

		$found = [];

		foreach ( (array) $rows as $row ) {
			$found[ (int) $row->object_id . ':' . (int) $row->language_id ] = (string) $row->slug;
		}

		$blog_id = (int) get_current_blog_id();

		// Seed the in-memory cache with null sentinels for confirmed-absent
		// pairs so subsequent get_slug() calls don't re-query.
		foreach ( $post_ids as $pid ) {
			foreach ( $lang_ids as $lid ) {
				$key = $pid . ':' . $lid;

				if ( isset( $this->slugs[ $blog_id ]['post'][ $pid ][ $lid ] ) ) {
					continue; // Already seeded - don't clobber a previous prime.
				}

				$this->slugs[ $blog_id ]['post'][ $pid ][ $lid ] = $found[ $key ] ?? null;
			}
		}
	}

	/**
	 * Whether ANY slug translation exists (table-wide, cached verdict).
	 *
	 * Shared with UrlConverter's term slug prime, so that prime needs no repository of its own.
	 *
	 * @return bool
	 */
	public function has_any_slugs(): bool {
		return $this->get_repo()->has_any_slugs();
	}

	/**
	 * Get the translated slug for an object.
	 *
	 * For terms, a miss first runs any queued term slug prime (see $term_slug_prime_flusher).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id Object ID.
	 * @param int    $language_id Language ID.
	 * @return string|null Translated slug or null.
	 */
	public function get_slug( string $object_type, int $object_id, int $language_id ): ?string {
		$blog_id = (int) get_current_blog_id();

		// Use array_key_exists() so a null sentinel (no translation) also short-circuits.
		if ( isset( $this->slugs[ $blog_id ][ $object_type ][ $object_id ] )
			&& array_key_exists( $language_id, $this->slugs[ $blog_id ][ $object_type ][ $object_id ] ) ) {
			return $this->slugs[ $blog_id ][ $object_type ][ $object_id ][ $language_id ];
		}

		if ( $object_type === 'term' && $this->term_slug_prime_flusher !== null ) {
			( $this->term_slug_prime_flusher )( $object_id );
		}

		// Fallback to repository (which uses the 3-layer cache).
		$slug = $this->get_repo()->get_slug( $object_type, $object_id, $language_id );

		// FIFO eviction at SLUGS_CAP object ids per (blog, object_type).
		if ( isset( $this->slugs[ $blog_id ][ $object_type ] ) && count( $this->slugs[ $blog_id ][ $object_type ] ) >= self::SLUGS_CAP ) {
			$evict                                   = (int) ( self::SLUGS_CAP / 4 );
			$this->slugs[ $blog_id ][ $object_type ] = array_slice( $this->slugs[ $blog_id ][ $object_type ], $evict, null, true );
		}

		// Cache both positive and negative results in memory.
		$this->slugs[ $blog_id ][ $object_type ][ $object_id ][ $language_id ] = $slug;

		return $slug;
	}

	/**
	 * Set a slug translation.
	 *
	 * @param string $object_type    Object type ('post', 'term', etc.).
	 * @param string $object_subtype Sub-type (post_type for posts, taxonomy
	 *                               for terms).
	 * @param int    $object_id      Object ID.
	 * @param int    $language_id    Language ID.
	 * @param string $slug           Translated slug.
	 * @return bool
	 */
	public function set_slug( string $object_type, string $object_subtype, int $object_id, int $language_id, string $slug ): bool {
		$result = $this->get_repo()->set_slug( $object_type, $object_subtype, $object_id, $language_id, $slug );

		if ( $result ) {
			$blog_id = (int) get_current_blog_id();
				// Drop, do not seed: find_unique_slug() may store a suffixed slug that differs from $slug.
			unset( $this->slugs[ $blog_id ][ $object_type ][ $object_id ][ $language_id ] );
		}

		return $result;
	}

	/**
	 * Get the slug repository (lazy loaded).
	 *
	 * @return SlugTranslationRepository
	 */
	private function get_repo(): SlugTranslationRepository {
		if ( $this->repo === null ) {
			$this->repo = new SlugTranslationRepository( $this->cache );
		}

		return $this->repo;
	}
}
