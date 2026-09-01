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
 * The key optimization: on the_posts hook, batch-load all slug
 * translations for the fetched posts in ONE query. This eliminates
 * per-post DB queries when rendering permalinks on archive pages.
 */
final class SlugManager {

	/**
	 * In-memory slug cache, blog-id prefixed for multisite isolation.
	 *
	 * Structure: [blog_id][object_type][object_id][language_id] = slug
	 *
	 * Blog-id prefix is mandatory on multisite: SlugManager is registered
	 * as an eager DI singleton, so the same instance serves every blog the
	 * request touches. Without the prefix, `switch_to_blog( 2 )` followed
	 * by `get_slug( 'post', 5, 7 )` would happily return blog 1's cached
	 * slug for post 5 — slug rows live in `wp_<id>_perflocale_slug_translations`
	 * and the object IDs are NOT globally unique across subsites.
	 *
	 * On single-site WP, `get_current_blog_id()` returns 1 so the prefix is
	 * constant; this costs one extra hash-table lookup per access and saves
	 * silent cross-blog bleed on multisite.
	 *
	 * Capped per (blog_id, object_type) at SLUGS_CAP entries — long-running
	 * CLI (sitemap rebuild, bulk slug regeneration) walks every post/term
	 * and would otherwise grow this map unboundedly through the long-lived
	 * DI singleton.
	 *
	 * @var array<int, array<string, array<int, array<int, string|null>>>>
	 */
	private array $slugs = [];

	/**
	 * Soft cap on $slugs entries per (blog_id, object_type). When count
	 * exceeds this the oldest 25% are evicted FIFO. Each entry is a leaf
	 * array of (~5) language→slug pairs ≈ 200B; cap = 5000 → ≈ 1MB per
	 * (blog, type) pair.
	 */
	private const SLUGS_CAP = 5000;

	/**
	 * Per-request gate snapshot for preload_slugs(): translate_slugs
	 * setting, current language ID, and the full active-language ID list.
	 *
	 * Lifted out of preload_slugs() as a function-level `static` so it
	 * can be invalidated by reset_static_caches() when settings or
	 * languages mutate mid-request. Without that, programmatic language
	 * adds/deletes would leave the gate frozen at first-read state and
	 * subsequent slug priming would skip the new language (or attempt to
	 * prime a deleted one).
	 *
	 * @var array{enabled:bool,language_id:int,all_lang_ids:array<int,int>}|null
	 */
	private static ?array $gate_memo = null;

	/**
	 * Clear the per-request gate snapshot. Hooked to language CRUD events
	 * (and switch_blog on multisite) so subsequent preload_slugs() calls
	 * within the same request rebuild from fresh state.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		self::$gate_memo = null;
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
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Preload slug translations for posts returned by WP_Query.
		// Runs after WP fetches posts, before template rendering.
		// Controlled by the cache_preload_slugs setting (default: true).
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'settings' ) && ! (bool) $plugin->get( 'settings' )->get( 'cache_preload_slugs', true ) ) {
			return;
		}

		add_filter( 'the_posts', [ $this, 'preload_slugs' ], 10, 2 );

		$reset_static = [ self::class, 'reset_static_caches' ];

		// Invalidate the gate memo when languages change mid-request so
		// the next preload_slugs() call rebuilds the active-language list
		// from fresh state instead of using a frozen snapshot.
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
	 * This is the key performance optimization. Instead of querying
	 * slug translations one-by-one when rendering permalinks, we
	 * batch-load them all in a single query here.
	 *
	 * @param array<int, \WP_Post> $posts Posts returned by WP_Query.
	 * @param \WP_Query            $query The WP_Query instance.
	 * @return array<int, \WP_Post> Unmodified posts (this is a filter).
	 */
	public function preload_slugs( array $posts, \WP_Query $query ): array {
		if ( empty( $posts ) || is_admin() ) {
			return $posts;
		}

		// Per-request cache of the runtime gate - translate_slugs off OR
		// current language zero means the preload query is pure waste.
		// Cached so every WP_Query on the request (sidebars, related,
		// widgets) doesn't re-resolve the same settings + router values.
		// Also carries the full active-language list so we can prime slugs
		// across every language in one batched query - hreflang + language
		// switcher + alternate-language permalinks all call get_slug with
		// the target language ID, not the visitor's, and without multi-lang
		// priming those calls each trigger an L3 transient read (~200µs × N
		// posts × N other languages on every archive page).
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

		// Zero-state short-circuit: if no rows have ever been written to
		// the slug_translations table, the batch query below is guaranteed
		// to return zero rows. Seed null sentinels for each (pid, lang)
		// pair instead so subsequent `get_slug()` calls hit the in-memory
		// cache without falling through to the repository. The
		// has_any_slugs check is sub-µs on warm cache.
		$blog_id = (int) get_current_blog_id();

		if ( ! $this->get_repo()->has_any_slugs() ) {
			$language_id  = $gate['language_id'];
			$all_lang_ids = $gate['all_lang_ids'] === [] ? [ $language_id ] : $gate['all_lang_ids'];

			foreach ( $posts as $p ) {
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
		$post_ids     = array_map( static fn( \WP_Post $p ) => (int) $p->ID, $posts );

		// Fast path: if every (post, current_language) pair is already cached,
		// the batch is a no-op. Second and later WP_Query executions (sidebars,
		// related loops, wp_list_pages) hit this when the same posts were
		// primed by the first query.
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

		// Batch-load slugs for EVERY active language in one query. Without
		// this, get_slug(post, other_lang_id) calls from the hreflang tags +
		// language switcher each triggered per-post L3 transient reads
		// (~200µs × N × M on archive pages). Single multi-language batch
		// returns all rows, then we seed the cache with null sentinels for
		// every (post, lang) pair we probed so subsequent gets hit L1.
		if ( $all_lang_ids === [] ) {
			$all_lang_ids = [ $language_id ];
		}

		$this->preload_slugs_multilang( $post_ids, $all_lang_ids );

		return $posts;
	}

	/**
	 * Batch-load slug translations for a set of posts across many languages.
	 *
	 * Single scoped SQL query populates the L1 cache for every
	 * (post_id, language_id) pair, using null sentinels for combinations
	 * without a stored row so subsequent lookups short-circuit.
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
	 * Public passthrough so batch primes outside this class (UrlConverter's
	 * term-slug prime) can share the same cheap gate the post-side preload
	 * uses instead of constructing their own repository.
	 *
	 * @return bool
	 */
	public function has_any_slugs(): bool {
		return $this->get_repo()->has_any_slugs();
	}

	/**
	 * Get the translated slug for an object.
	 *
	 * Checks the in-memory cache first, then falls back to the repository.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id Object ID.
	 * @param int    $language_id Language ID.
	 * @return string|null Translated slug or null.
	 */
	public function get_slug( string $object_type, int $object_id, int $language_id ): ?string {
		$blog_id = (int) get_current_blog_id();

		// Check in-memory cache first (populated by preload_slugs). We use
		// array_key_exists here so the preloaded null-sentinel for posts
		// confirmed-without-a-translation short-circuits too - without this
		// we'd fall through to the repository for every untranslated post
		// on every permalink build.
		if ( isset( $this->slugs[ $blog_id ][ $object_type ][ $object_id ] )
			&& array_key_exists( $language_id, $this->slugs[ $blog_id ][ $object_type ][ $object_id ] ) ) {
			return $this->slugs[ $blog_id ][ $object_type ][ $object_id ][ $language_id ];
		}

		// Fallback to repository (which uses the 3-layer cache).
		$slug = $this->get_repo()->get_slug( $object_type, $object_id, $language_id );

		// FIFO eviction at SLUGS_CAP per (blog, object_type) — see property
		// docblock for rationale. Counted at the object_id level since
		// each object_id holds a small map of language→slug pairs.
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
			// Invalidate (don't seed) the memo: the repository runs the slug
			// through find_unique_slug(), so a collision auto-suffix can make
			// the stored value differ from $slug. Seeding with the requested
			// slug would hand a stale value back from get_slug() later in this
			// same request; dropping the entry forces a fresh canonical read.
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
