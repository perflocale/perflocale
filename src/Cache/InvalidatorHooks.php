<?php
/**
 * Cache invalidation hooks.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cache;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CacheInvalidator's hooks as static forwarders to the `cache_invalidator` service, built at the first change, so a
 * page view does not load the invalidator.
 *
 * @internal
 */
final class InvalidatorHooks {

	/**
	 * The shared invalidator: its open saves and link changes span hooks.
	 *
	 * @return CacheInvalidator
	 */
	private static function invalidator(): CacheInvalidator {
		/** @var CacheInvalidator $invalidator */
		$invalidator = Plugin::get_instance()->get( 'cache_invalidator' );

		return $invalidator;
	}

	/**
	 * Register hooks for cache invalidation.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// The gated hooks below need a write context (WP-CLI included); a front-end GET changes no content.
		$is_write_context = is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] )
			|| defined( 'REST_REQUEST' )
			|| ( defined( 'WP_CLI' ) && WP_CLI );

		// Delete cleanup runs in every context: a post or term can be deleted inside a front-end GET, and a
		// missed cleanup orphans rows that reserve the translated slug. Idle unless a delete fires.
		add_action( 'delete_post', [ self::class, 'on_delete_post' ] );
		add_action( 'delete_term', [ self::class, 'on_delete_term' ], 10, 4 );

		// Term edits in every context too: a cron event inside a GET can rename a term. Priority 999: after
		// other plugins, so the cache reflects the final term.
		add_action( 'edited_term', [ self::class, 'on_edited_term' ], 999, 3 );

		// Machine-translated meta lands after the translation's own save. Ungated: jobs also run from WP-Cron
		// inside a visitor's GET.
		add_action( 'perflocale/mt/meta_translated', [ self::class, 'on_meta_translated' ], 10, 2 );

		// A post link written outside a post save changes its groups' alternates. Ungated for the same reason;
		// a migration import leaves it to the flush that ends the import.
		add_action( 'perflocale/translation/linked', [ self::class, 'on_translation_linked' ] );

		// Ungated: a publication can happen in a front-end GET (a scheduled post on a visitor's request). The
		// handler returns early unless public readability changed.
		add_action( 'transition_post_status', [ self::class, 'on_visibility_transition' ], 20, 3 );

		if ( $is_write_context ) {
			// Gated: a publication inside a front-end request is covered by on_visibility_transition(), which
			// clears hreflang itself when this handler is absent. Priority 999: after other plugins.
			add_action( 'save_post', [ self::class, 'on_save_post' ], 999, 2 );

			// The post a save_post run is for, until on_save_post() takes it:
			// a group change inside that run leaves its hreflang to the save.
			add_action( 'save_post', [ self::class, 'open_save' ], PHP_INT_MIN, 2 );
		}

		// PerfLocale-specific hooks - always needed (fired programmatically).
		add_action( 'perflocale/language/added', [ self::class, 'on_language_changed' ] );
		add_action( 'perflocale/language/updated', [ self::class, 'on_language_changed' ] );
		add_action( 'perflocale/language/deleted', [ self::class, 'on_language_changed' ] );
		// 2 accepted args: on_settings_changed() needs the new and the old settings to tell which keys changed.
		add_action( 'perflocale/settings/updated', [ self::class, 'on_settings_changed' ], 10, 2 );

		// Multisite only: switch_to_blog() fires switch_blog on single-site too, so a same-blog switch would flush.
		if ( is_multisite() ) {
			add_action( 'switch_blog', [ self::class, 'on_switch_blog' ] );
		}
	}

	/**
	 * Invalidate caches when a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function on_save_post( int $post_id, ?\WP_Post $post = null ): void {
		self::invalidator()->on_save_post( $post_id, $post );
	}

	/**
	 * Note the post a save_post run is for, when the run carries the post object.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    Post object.
	 * @return void
	 */
	public static function open_save( mixed $post_id = 0, mixed $post = null ): void {
		self::invalidator()->open_save( $post_id, $post );
	}

	/**
	 * Invalidate caches and remove translation link when a post is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete_post( int $post_id ): void {
		self::invalidator()->on_delete_post( $post_id );
	}

	/**
	 * Invalidate caches when a term is edited.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function on_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		self::invalidator()->on_edited_term( $term_id, $tt_id, $taxonomy );
	}

	/**
	 * Purge a translation after machine translation wrote its meta.
	 *
	 * @param mixed $translation_id Translation post id.
	 * @param mixed $written_keys   Meta keys written by the run.
	 * @return void
	 */
	public static function on_meta_translated( $translation_id = 0, $written_keys = [] ): void {
		self::invalidator()->on_meta_translated( $translation_id, $written_keys );
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
	public static function on_delete_term( int $term_id, int $tt_id, string $taxonomy, mixed $deleted_term ): void {
		self::invalidator()->on_delete_term( $term_id, $tt_id, $taxonomy, $deleted_term );
	}

	/**
	 * Clear the cached hreflang of the pages a post link changed.
	 *
	 * @param mixed $group_id Group the object was linked to.
	 * @return void
	 */
	public static function on_translation_linked( mixed $group_id = 0 ): void {
		self::invalidator()->on_translation_linked( $group_id );
	}

	/**
	 * Purge full-page caches for a post whose PUBLIC visibility changed.
	 *
	 * @param string    $new_status New post status.
	 * @param string    $old_status Previous post status.
	 * @param \WP_Post|null $post   The post.
	 * @return void
	 */
	public static function on_visibility_transition( $new_status, $old_status, $post = null ): void {
		self::invalidator()->on_visibility_transition( $new_status, $old_status, $post );
	}

	/**
	 * Flush all language caches when languages are modified.
	 *
	 * @param mixed ...$args Hook arguments (ignored; the firing hooks have different signatures).
	 * @return void
	 */
	public static function on_language_changed( mixed ...$args ): void {
		self::invalidator()->on_language_changed( ...$args );
	}

	/**
	 * Invalidate the caches a settings save can stale. The per-key blocks
	 * are not exclusive: one save can change several kinds of keys.
	 *
	 * @param mixed ...$args Hook arguments (new_settings, old_settings); both needed (2 accepted args).
	 * @return void
	 */
	public static function on_settings_changed( mixed ...$args ): void {
		self::invalidator()->on_settings_changed( ...$args );
	}

	/**
	 * Flush static caches on multisite blog switch (CacheInvalidator::on_switch_blog() without loading it); the reset is
	 * blog-agnostic, so the hook's blog id is not read.
	 *
	 * @return void
	 */
	public static function on_switch_blog(): void {
		Plugin::get_instance()->cache()->reset_static();
	}
}
