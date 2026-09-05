<?php
/**
 * Cache invalidation handler.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WordPress hooks and surgically invalidates relevant cache entries.
 *
 * Does NOT flush all caches on every save - only the specific keys affected
 * by the change are invalidated.
 */
final class CacheInvalidator {

	/**
	 * Cache manager instance.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register hooks for cache invalidation.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Skip content-change hooks on read-only frontend GET requests.
		// No posts/terms/options are modified during a normal page view,
		// so these hooks would just sit in memory doing nothing. WP-CLI
		// is always treated as a write context — `wp post delete`,
		// `wp term delete`, migration scripts, etc. need the same
		// invalidation + orphan-cleanup the admin path gets.
		$is_write_context = is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] )
			|| defined( 'REST_REQUEST' )
			|| ( defined( 'WP_CLI' ) && WP_CLI );

		// Deletion orphan-cleanup must run in EVERY context. A translated post
		// or term can be deleted during a plain frontend GET (bbPress tag
		// pruning, directory "delete my listing" links, membership-expiry term
		// pruning on init), and the slug_translations / translation_links /
		// hreflang cleanup has to happen — otherwise those rows orphan,
		// reserving the translated slug so the next term that wants it gets a
		// silently degraded "-2" suffix. These only do work when a delete
		// actually fires, so leaving them ungated costs nothing on a read GET.
		add_action( 'delete_post', [ $this, 'on_delete_post' ] );
		add_action( 'delete_term', [ $this, 'on_delete_term' ], 10, 4 );

		// Full-page-cache invalidation on a visibility change. Registered in
		// every context: the handler is a cheap early-return unless public
		// readability actually changed, and a transition can arrive from a
		// front-end GET (scheduled-post publication on a visitor's request)
		// that the write-context gate below deliberately excludes.
		add_action( 'transition_post_status', [ $this, 'on_visibility_transition' ], 20, 3 );

		if ( $is_write_context ) {
			// Pure cache-flush hooks — these fire only on writes that don't
			// occur during a read-only GET, so they can stay gated.
			// Priority 999: run AFTER all other plugins (e.g. WooCommerce at
			// 100) so the cache reflects the final post state.
			add_action( 'save_post', [ $this, 'on_save_post' ], 999, 2 );

			// Term edits - invalidate translation group cache for the term.
			add_action( 'edited_term', [ $this, 'on_edited_term' ], 999, 3 );
		}

		// PerfLocale-specific hooks - always needed (fired programmatically).
		add_action( 'perflocale/language/added', [ $this, 'on_language_changed' ] );
		add_action( 'perflocale/language/updated', [ $this, 'on_language_changed' ] );
		add_action( 'perflocale/language/deleted', [ $this, 'on_language_changed' ] );
		// 2 accepted args: Settings::update() fires this with (new, old) and
		// on_settings_changed() needs BOTH to tell which keys changed. Registered
		// with the default 1, WordPress sliced the old settings off before the
		// call, so every save took the "can't tell what changed" fallback and the
		// selective branches below it never executed once.
		add_action( 'perflocale/settings/updated', [ $this, 'on_settings_changed' ], 10, 2 );

		// Multisite: flush on blog switch. Gated — switch_to_blog() fires
		// the action on single-site too (as a no-op), so registering this
		// handler unconditionally would invite spurious cache flushes from
		// plugins that call switch_to_blog(get_current_blog_id()).
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ $this, 'on_switch_blog' ] );
		}
	}

	/**
	 * Invalidate caches when a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function on_save_post( int $post_id, ?\WP_Post $post = null ): void {
		// WordPress re-reads the row after the write and hands the hook
		// whatever it got, which is null when the post was deleted in the
		// interim; some plugins also fire save_post with one argument. A
		// non-nullable hint turned either into an uncaught TypeError.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->cache->flush_object( $post_id, 'post' );

		// Clear hreflang transients for this post AND its translation siblings.
		// Hreflang links are bidirectional - when Post 10 (EN) changes,
		// Post 20 (FR) also needs its hreflang cache cleared because it
		// contains a link pointing back to Post 10.
		$this->clear_hreflang_for_post_and_siblings( $post_id );
	}

	/**
	 * Clear hreflang transients for a post and all its translation siblings.
	 *
	 * Hreflang tags are bidirectional: each translation lists all others.
	 * When one post changes, ALL siblings' hreflang caches become stale.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function clear_hreflang_for_post_and_siblings( int $post_id ): void {
		// A post's hreflang block lives only under the singular bucket
		// (perflocale_hreflang_s_<post_id>_<lang>). Archive/home/author/date
		// hreflang is keyed off the ARCHIVE URL's own alternates, not any
		// individual post, so a post edit can't stale them. Clear the post +
		// its translation siblings precisely through CacheManager so the
		// generation-prefixed L2 key AND the correctly derived L3 transient
		// are both hit (a hand-built delete_transient() key matches neither).
		$this->clear_singular_hreflang( $post_id, \PerfLocale\Enum\ObjectType::Post, 's' );

		// EXCEPTION: the static front page and the posts page render under the
		// home/blog-index ('h') bucket, which is keyed by language+paged (not
		// the object id) and so isn't precisely addressable. When THAT object
		// is edited (its translation set can change the home's per-language
		// alternates), flush the whole hreflang group. This is gated to those
		// one or two special objects — a normal post save runs only the cheap
		// autoloaded-option reads in is_home_object() and never bumps. This
		// path is the save hook, not a frontend render, so it adds no
		// visitor-facing overhead.
		if ( $this->is_home_object( $post_id ) ) {
			$this->cache->invalidate_group( 'perflocale_hreflang' );
		}
	}

	/**
	 * Whether a post is the static front page or the posts page — the objects
	 * whose edit affects the language-keyed home/blog-index hreflang block.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_home_object( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post_id ) {
			return true;
		}

		return (int) get_option( 'page_for_posts' ) === $post_id;
	}

	/**
	 * Clear hreflang for a term and all its translation siblings.
	 *
	 * A term's hreflang lives under the archive bucket
	 * (`perflocale_hreflang_t_<id>_<lang>_<paged>`) whose unbounded pagination
	 * isn't enumerable, and a `%category%` rename invalidates the permalink of
	 * every post beneath it (their singular hreflang too). Neither set is
	 * precisely addressable, so flush the whole hreflang group — O(1) via the
	 * generation bump (reliable on every backend, incl. Redis Object Cache +
	 * Predis where wp_cache_flush_group() no-ops). Term edits are rare, so the
	 * coarse flush is an acceptable trade for correctness.
	 *
	 * @param int    $term_id  Term ID (passed to the extension hook).
	 * @param string $taxonomy Taxonomy slug (unused; kept for the hook signature).
	 * @return void
	 */
	private function clear_hreflang_for_term_and_siblings( int $term_id, string $taxonomy = '' ): void {
		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $this->cache );

		if ( ! $repo->has_any_groups() ) {
			return;
		}

		$this->cache->invalidate_group( 'perflocale_hreflang' );

		/** This hook is documented in clear_singular_hreflang(). */
		do_action( 'perflocale/cache/flush_archive_hreflang', $term_id );
	}

	/**
	 * Precisely clear the singular hreflang cache for an object and all its
	 * translation siblings, across every active language. Routes through
	 * CacheManager::delete() so L1 + the generation-prefixed L2 + the derived
	 * L3 transient are all invalidated with the exact keys CacheManager wrote.
	 *
	 * @param int                         $object_id   Object id.
	 * @param \PerfLocale\Enum\ObjectType $object_type Post / Term / etc.
	 * @param string                      $key_letter  Singular bucket letter ('s' for posts).
	 * @return void
	 */
	private function clear_singular_hreflang(
		int $object_id,
		\PerfLocale\Enum\ObjectType $object_type,
		string $key_letter
	): void {
		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $this->cache );

		// Zero-state short-circuit: no groups => no siblings and no hreflang
		// rows can exist. has_any_groups() is sub-µs warm.
		if ( ! $repo->has_any_groups() ) {
			return;
		}

		// Collect this object + its siblings BEFORE any caller-side unlink:
		// on_delete_post must invoke us before unlink_by_object_id or the
		// sibling list is already empty.
		$links      = $repo->get_translations( $object_id, $object_type );
		$object_ids = [ $object_id ];

		foreach ( $links as $link ) {
			$sibling_id = (int) $link->object_id;

			if ( $sibling_id > 0 && $sibling_id !== $object_id ) {
				$object_ids[] = $sibling_id;
			}
		}

		$languages = ( new \PerfLocale\Database\Repository\LanguageRepository( $this->cache ) )->get_active();

		foreach ( $object_ids as $oid ) {
			foreach ( $languages as $lang ) {
				$key = 'perflocale_hreflang_' . $key_letter . '_' . $oid . '_' . (int) $lang->id;

				$this->cache->delete( $key, 'perflocale_hreflang' );

				// HreflangTags caches TWO entries per page in this group: the
				// HTML chunk under $key, and the computed tag ARRAY (the
				// Link-HTTP-header path) under 'tags_' . $key. A per-key delete
				// that dropped only the former left the tags_ entry live for its
				// full 12h TTL, so headers kept emitting the stale alternate set
				// AND the HTML rebuild read back through it — defeating the
				// precise on_save_post / on_delete_post invalidation entirely.
				$this->cache->delete( 'tags_' . $key, 'perflocale_hreflang' );
			}
		}

		/**
		 * Extension hook: fires after PerfLocale's own hreflang invalidation
		 * so object-cache-backed installs can issue their own wipe.
		 *
		 * @hook perflocale/cache/flush_archive_hreflang
		 * @param int $object_id Object whose save/edit/delete triggered the flush.
		 */
		do_action( 'perflocale/cache/flush_archive_hreflang', $object_id );
	}

	/**
	 * Invalidate caches and remove translation link when a post is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_delete_post( int $post_id ): void {
		// Skip revisions and autosaves — they can never hold translation
		// links, slug rows, or hreflang entries, yet wp_delete_post() fires
		// 'delete_post' once per stored revision before the parent's own fire.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Clear hreflang FIRST: the helper enumerates siblings via the
		// translation_links row, which the unlink below removes. If we
		// unlinked first the sibling list would be empty and every other
		// translation of this post would keep a 12h-stale hreflang block
		// pointing at the about-to-404 deleted URL.
		$this->clear_hreflang_for_post_and_siblings( $post_id );

		// Remove the translation link so deleted posts don't leave stale references.
		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $this->cache );
		$repo->unlink_by_object_id( $post_id, 'post' );

		$this->cache->flush_object( $post_id, 'post' );
	}

	/**
	 * Invalidate caches when a term is edited.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function on_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		// Sibling-aware hreflang clear (per-term keys for this term + its
		// translation siblings, plus the taxonomy-archive bucket). Without
		// this a renamed-slug term leaves up-to-12h-stale hreflang on every
		// sibling term archive, which Google reports as missing return tags.
		$this->clear_hreflang_for_term_and_siblings( $term_id, $taxonomy );

		$this->cache->flush_object( $term_id, 'term' );
	}

	/**
	 * Invalidate caches when a term is deleted.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param mixed  $deleted_term Deleted term object.
	 * @return void
	 */
	public function on_delete_term( int $term_id, int $tt_id, string $taxonomy, mixed $deleted_term ): void {
		// Sibling-aware hreflang clear runs BEFORE the unlink for the same
		// reason as on_delete_post — once the translation_links row is gone
		// the helper can no longer enumerate siblings.
		$this->clear_hreflang_for_term_and_siblings( $term_id, $taxonomy );

		// Remove the translation link so deleted terms don't leave stale references.
		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $this->cache );
		$repo->unlink_by_object_id( $term_id, 'term' );

		// Also drop slug_translations for this term. Without this, every
		// deleted translated term leaves N orphan rows (one per language)
		// pointing at a term_id that no longer exists. Tables-exist guard
		// covers the early-deactivation / uninstall ordering window.
		if ( \PerfLocale\Database\Schema::tables_exist() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				\PerfLocale\Database\Schema::table( 'slug_translations' ),
				[
					'object_id'   => $term_id,
					'object_type' => 'term',
				],
				[ '%d', '%s' ]
			);

			// Also drop the content-hash row. ContentChangeDetector only boots
			// in admin/REST/CLI, so a term deleted in any other context leaves
			// an orphan content_hashes row (the term analog of the post cleanup
			// in Bootstrap::cleanup_translation_link_on_delete). No-op when the
			// detector never hashed this term.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				\PerfLocale\Database\Schema::table( 'content_hashes' ),
				[
					'object_id'   => $term_id,
					'object_type' => 'term',
				],
				[ '%d', '%s' ]
			);
		}

		$this->cache->flush_object( $term_id, 'term' );
	}

	/**
	 * Flush all language caches when languages are modified.
	 *
	 * @param mixed ...$args Hook arguments (ignored). Variadic because the
	 *                      fires-from hooks (created/updated/deleted/activated/
	 *                      deactivated/default-changed) have heterogeneous
	 *                      signatures; we don't read any of them.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- variadic captures heterogeneous hook signatures we deliberately ignore.
	public function on_language_changed( mixed ...$args ): void {
		$this->cache->flush_languages();

		// Rewrite rules use an explicit regex of language slugs (e.g. (en|fr|de)).
		// When languages change, the regex is stale and rules must be regenerated.
		update_option( 'perflocale_flush_rules', 1, false );
		delete_transient( 'perflocale_rewrites_verified' );
	}

	/**
	 * Invalidate the caches a settings save can stale.
	 *
	 * `flush_languages()` runs on every save (it also bumps + sweeps the
	 * hreflang group); the per-category blocks below add the invalidations
	 * that only specific keys require, and are deliberately NOT mutually
	 * exclusive — one save can change a URL key AND the string-translation
	 * mode, and an early return after the first match skipped the rest.
	 *
	 * Both hook arguments are required. `Settings::update()` fires
	 * `perflocale/settings/updated` with (new, old); the registration in
	 * register_hooks() must therefore ask for 2 accepted args or WordPress
	 * slices `$old_settings` off and every branch below is dead.
	 *
	 * @param mixed ...$args Hook arguments (new_settings, old_settings).
	 * @return void
	 */
	public function on_settings_changed( mixed ...$args ): void {
		$new_settings = $args[0] ?? [];
		$old_settings = $args[1] ?? [];

		// Unconditional, as on every save before the per-key blocks existed:
		// language data underpins slug/hreflang derivation, and this call also
		// bumps the hreflang generation and sweeps its L3 rows.
		$this->cache->flush_languages();

		// Without both payloads there is nothing to diff — the blanket flush
		// above is all we can honestly do.
		if ( ! is_array( $new_settings ) || ! is_array( $old_settings ) || $old_settings === [] ) {
			return;
		}

		// URL-related settings: the cached hreflang alternate sets and
		// found-rows counts both embed URLs/values shaped by the URL mode, and
		// hreflang entries live 12h. Without this, switching e.g.
		// subdirectory -> query serves alternates pointing at the OLD mode's
		// URLs until the TTL expires.
		$url_keys = [ 'url_mode', 'url_prefix_type', 'hide_default_prefix', 'excluded_paths', 'translate_slugs', 'language_domains' ];

		foreach ( $url_keys as $key ) {
			if ( ( $new_settings[ $key ] ?? null ) !== ( $old_settings[ $key ] ?? null ) ) {
				// invalidate_group() covers all three layers — the generation
				// bump alone leaves 12h L3 transients alive on hosts without a
				// persistent object cache (derive_transient_key is not
				// generation-aware).
				$this->cache->invalidate_group( 'perflocale_hreflang' );
				$this->cache->invalidate_group( 'perflocale_found_rows' );
				break;
			}
		}

		// Translatable-type/taxonomy changes: the persistent caches genuinely
		// derived from type-translatability are the 12h hreflang alternate sets
		// (no serve-time translatability re-check — a de-translated type would
		// keep emitting alternates until TTL) and the found-rows counts (their
		// md5-of-SQL keys self-correct, but the superseded L3 transients would
		// linger). Mirror the URL-keys branch above.
		$trans_keys = [ 'translatable_post_types', 'translatable_taxonomies' ];

		foreach ( $trans_keys as $key ) {
			if ( ( $new_settings[ $key ] ?? null ) !== ( $old_settings[ $key ] ?? null ) ) {
				$this->cache->invalidate_group( 'perflocale_hreflang' );
				$this->cache->invalidate_group( 'perflocale_found_rows' );
				break;
			}
		}

		// String translation mode change: flush string caches and, when
		// switching to files mode, trigger file regeneration so the new
		// .l10n.php files exist before the next frontend request arrives.
		if ( ( $new_settings['string_translation_mode'] ?? null ) !== ( $old_settings['string_translation_mode'] ?? null ) ) {
			$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $this->cache );
			$languages = $lang_repo->get_active();

			foreach ( $languages as $lang ) {
				$this->cache->delete( "all_string_translations_{$lang->id}", 'perflocale_strings' );
			}

			if ( ( $new_settings['string_translation_mode'] ?? '' ) === 'files' ) {
				// Signal to TranslationFileLoader that files are being regenerated
				// so it doesn't permanently mark itself as "loaded" on the first
				// miss and give up retrying.
				if ( false === set_transient( 'perflocale_strings_regenerating', 1, 5 * MINUTE_IN_SECONDS ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; without this flag files-mode strings may read untranslated until the next regen.
					error_log( 'PerfLocale: failed to set the strings-regenerating flag; files-mode strings may read untranslated until regeneration completes.' );
				}

				/**
				 * Fires when string translation mode is switched to files.
				 * Hooked by Bootstrap to run TranslationFileGenerator::generate_all().
				 *
				 * @hook perflocale/strings/regenerate_files
				 * @param CacheManager $cache Cache manager instance.
				 */
				do_action( 'perflocale/strings/regenerate_files', $this->cache );
			}
		}
	}

	/**
	 * Flush static caches on multisite blog switch.
	 *
	 * @param int $new_blog_id New blog ID. Required by the WP `switch_blog`
	 *                        hook signature; we reset all per-request static
	 *                        caches unconditionally so the ID isn't read.
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP switch_blog hook signature; reset_static() is blog-agnostic.
	public function on_switch_blog( int $new_blog_id ): void {
		// Only reset the request-scoped static caches on blog switch — they're
		// keyed by the old blog and must not leak. The persistent layers
		// (wp_cache group + L3 transients) are already per-blog in WP, so
		// touching them is pointless AND would fatal on a fresh subsite whose
		// tables don't exist yet (switch_blog fires before our activator runs).
		$this->cache->reset_static();
	}

	/**
	 * Purge full-page caches for a post whose PUBLIC visibility changed.
	 *
	 * WHY THIS EXISTS
	 *   A translated page is a separate post with its own language-prefixed
	 *   URL. When such a page was public, was fetched anonymously (warming a
	 *   full-page cache), and was then made private, the cached copy stayed
	 *   anonymously readable. Reproduced on both multisites, root and child
	 *   blogs, with Redis hot and with Redis disabled; the same transition
	 *   invalidated correctly when the full-page cache was bypassed, so the
	 *   stale copy lives in the page-cache layer, not the object cache.
	 *
	 *   Two things combine. A page cache purges what it believes the post's URL
	 *   to be, and it computes that during an admin/CLI request — where this
	 *   plugin deliberately does NOT add the language prefix, so the URL it
	 *   purges is not the URL it stored. And the whole translation GROUP is
	 *   affected: hiding one language changes sibling pages that link to it.
	 *
	 *   So this resolves the real front-end URLs itself and hands them to the
	 *   page cache, rather than assuming the cache can work them out.
	 *
	 * DELIBERATELY NARROW. Only a transition that changes whether the post is
	 * publicly readable does anything, and only that post and its translation
	 * siblings are purged. There is no global page-cache flush, no object-cache
	 * flush and no database flush — a visibility change on one post must not
	 * cost the whole site its cache. Nothing here runs on a front-end request.
	 *
	 * @param string    $new_status New post status.
	 * @param string    $old_status Previous post status.
	 * @param \WP_Post|null $post   The post.
	 * @return void
	 */
	public function on_visibility_transition( $new_status, $old_status, $post = null ): void {
		if ( ! $post instanceof \WP_Post || $new_status === $old_status ) {
			return;
		}

		$was_public = $this->status_is_public( (string) $old_status );
		$is_public  = $this->status_is_public( (string) $new_status );

		// Only a change in public readability can strand a cached page.
		if ( $was_public === $is_public ) {
			return;
		}

		$urls = $this->public_translation_urls( (int) $post->ID );

		if ( $urls === [] ) {
			return;
		}

		/**
		 * Fires with every public URL affected by a visibility change.
		 *
		 * @hook perflocale/cache/purge_urls Purge these exact URLs from a full-page cache.
		 * @param array<int, string> $urls    Absolute front-end URLs, language prefixes included.
		 * @param int                $post_id The post whose visibility changed.
		 */
		do_action( 'perflocale/cache/purge_urls', $urls, (int) $post->ID );

		// DELIBERATELY NO DIRECT CALLS INTO PAGE-CACHE PLUGINS.
		//
		// The obvious implementation — calling each cache's per-post purge
		// (Breeze's `purge_post_cache`, `w3tc_flush_post`, `rocket_clean_post`,
		// `wpsc_delete_post_cache`) — was written, tested and removed. Those
		// entry points do more than clear a local file: Breeze's, for one, goes
		// on to purge CloudFlare and Varnish over the network, so every
		// publish/private toggle would have made PerfLocale the origin of
		// outbound HTTP inside the request that saved the post. The
		// cov-never-loaded suite caught exactly that (90 blocked requests
		// carrying a PerfLocale frame), and it is right to: a translation plugin
		// should not silently add network calls to someone else's save.
		//
		// So this publishes the facts only the translation layer knows — the
		// exact public URLs — and leaves the purge to the site. One filter wires
		// it to any cache:
		//
		//     add_action( 'perflocale/cache/purge_urls', function ( $urls ) {
		//         foreach ( $urls as $url ) { my_cache_purge( $url ); }
		//     } );
		//
		// With no listener this costs nothing and reaches nothing.
	}

	/**
	 * Is a post with this status readable by an anonymous visitor?
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	private function status_is_public( string $status ): bool {
		$object = get_post_status_object( $status );

		return is_object( $object ) && ! empty( $object->public );
	}

	/**
	 * Every post id in this post's translation group, including itself.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, int>
	 */
	private function translation_group_post_ids( int $post_id ): array {
		$ids = [ $post_id ];

		try {
			$repo  = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
			$links = $repo->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post );

			foreach ( $links as $link ) {
				$sibling = (int) $link->object_id;

				if ( $sibling > 0 ) {
					$ids[] = $sibling;
				}
			}
		} catch ( \Throwable $e ) {
			// A missing container or table must never block a status change.
			unset( $e );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Front-end URLs for every post in this post's translation group.
	 *
	 * get_permalink() is used deliberately: this runs during a status change,
	 * which is an admin/CLI/REST request, and the URL that matters is the one a
	 * visitor would have requested. Anything that cannot be resolved is skipped
	 * rather than guessed.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, string>
	 */
	private function public_translation_urls( int $post_id ): array {
		$urls = [];

		foreach ( $this->translation_group_post_ids( $post_id ) as $id ) {
			$permalink = get_permalink( $id );

			if ( is_string( $permalink ) && $permalink !== '' ) {
				$urls[] = $permalink;
			}
		}

		return array_values( array_unique( $urls ) );
	}
}
