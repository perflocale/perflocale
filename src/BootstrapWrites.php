<?php
/**
 * Bootstrap's content-write hook callbacks.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bodies of Bootstrap's save, delete, publish, term and user hooks, the translation-stub and file-regeneration
 * queues and the auto-translate scheduling; Bootstrap registers and forwards, so a page view does not compile them.
 *
 * @internal
 */
final class BootstrapWrites {

	/**
	 * Posts whose first publish asks for translation stubs, per blog, until
	 * their save has finished writing terms, meta and the featured image.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $pending_stubs = [];

	/**
	 * Products whose WooCommerce data was saved inside their own save_post, per
	 * blog; their stubs wait for that save to finish.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $stub_products_saved = [];

	/**
	 * Pending stub posts per blog before the oldest is created at once.
	 */
	private const PENDING_STUBS_CAP = 100;

	/**
	 * Blogs whose compiled translation files are fully regenerated at request end (files mode).
	 *
	 * @var array<int, true>
	 */
	private static array $pending_files_regeneration = [];

	/**
	 * Allow duplicate post slugs across languages; the URL's language prefix keeps them apart.
	 *
	 * @param string $slug The slug WP wants to assign (possibly with -2, -3, etc.).
	 * @param int    $post_id Post ID.
	 * @param string $post_status Post status.
	 * @param string $post_type Post type.
	 * @param int    $post_parent Post parent ID.
	 * @param string $original_slug The original desired slug before deduplication.
	 * @return string The slug to use.
	 */
	public static function allow_translation_duplicate_slugs(
		string $slug,
		int $post_id,
		string $post_status,
		string $post_type,
		int $post_parent,
		string $original_slug
	): string {
		// Never collapse a template translation's slug: its distinct post_name is core's only
		// lookup handle (`post_name__in`), and a shared one makes two rows answer one query.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post_type ) ) {
			return $slug;
		}

		// Only intervene if WP changed the slug (appended -2, -3, etc.).
		if ( $slug === $original_slug ) {
			return $slug;
		}

		// Only for translatable post types.
		$plugin   = Plugin::get_instance();
		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $post_type, $settings->get_translatable_post_types(), true ) ) {
			return $slug;
		}

		// Collapse only when the conflicting posts are in a different language; a same-language
		// collision keeps WP's -N suffix, or the URLs would be identical.
		$cache     = $plugin->get( 'cache' );
		$manager   = new Translation\PostTranslationManager( $cache, $settings );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );

		// create_translation() sets this hint before wp_insert_post(): the link row does not exist yet.
		$current_lang = null;
		if ( Translation\PostTranslationManager::$creating_translation_lang_slug !== null ) {
			$current_lang = $lang_repo->find_by_slug(
				Translation\PostTranslationManager::$creating_translation_lang_slug
			);
		}

		if ( ! $current_lang ) {
			$current_lang = $manager->detect_post_language( $post_id );
		}

		if ( ! $current_lang ) {
			// Unknown language: be conservative.
			return $slug;
		}

		global $wpdb;

		// All same-slug, same-type, non-trash siblings; one same-language conflict keeps the suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conflict_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_name = %s
				AND post_type = %s
				AND ID != %d
				AND post_status NOT IN ( 'trash', 'auto-draft' )",
				$original_slug,
				$post_type,
				$post_id
			)
		);

		if ( empty( $conflict_ids ) ) {
			return $slug;
		}

		foreach ( (array) $conflict_ids as $conflict_id ) {
			$conflict_lang = $manager->detect_post_language( (int) $conflict_id );

			// Same-language or unassigned (treated as default) conflict: keep WP's slug.
			if ( ! $conflict_lang || (int) $conflict_lang->id === (int) $current_lang->id ) {
				return $slug;
			}
		}

		// Every conflict is in another language: the language router prefixes the URL.
		return $original_slug;
	}

	/**
	 * Remove a permanently deleted post's translation link and slug/content-hash rows.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function cleanup_translation_link_on_delete( int $post_id ): void {
		// Revisions never carry translation links.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		// Clear the post's and its siblings' hreflang while the link still names the siblings.
		if ( $plugin->has( 'cache_invalidator' ) ) {
			$plugin->get( 'cache_invalidator' )->clear_hreflang_before_unlink( $post_id );
		}

		// Translation link plus group GC.
		$groups = new Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
		$groups->unlink_by_object_id( $post_id, 'post' );

		// Drop this post's language memo so same-request callers do not keep the stale value.
		Translation\PostTranslationManager::forget_post_language( $post_id );

		// Tables may not exist yet (activation) or any more (uninstall).
		if ( ! Database\Schema::tables_exist() ) {
			return;
		}

		global $wpdb;

		// The post's slug_translations rows (one per language).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Database\Schema::table( 'slug_translations' ),
			[
				'object_id'   => $post_id,
				'object_type' => 'post',
			],
			[ '%d', '%s' ]
		);

		// The post's content_hashes row: ContentChangeDetector's own delete hook is not booted on
		// the front end, and this handler always is.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			Database\Schema::table( 'content_hashes' ),
			[
				'object_id'   => $post_id,
				'object_type' => 'post',
			],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Store a sync opt-out flag sent in a REST update before the post is saved.
	 *
	 * REST writes `meta` after save_post, so ContentSync (save_post:20) would read the old flag
	 * for that Update. Same permission check and sanitising as core's later write; creates and
	 * autosaves are left to core.
	 *
	 * @param \stdClass|\WP_Error   $prepared_post Post prepared for the database.
	 * @param \WP_REST_Request|null $request       Request being handled.
	 * @return \stdClass|\WP_Error The prepared post, unchanged.
	 */
	public static function apply_rest_sync_optout( $prepared_post, $request = null ) {
		if ( ! $prepared_post instanceof \stdClass || ! $request instanceof \WP_REST_Request ) {
			return $prepared_post;
		}

		$post_id = isset( $prepared_post->ID ) && is_numeric( $prepared_post->ID ) ? (int) $prepared_post->ID : 0;

		if ( $post_id <= 0 || str_ends_with( untrailingslashit( $request->get_route() ), '/autosaves' ) ) {
			return $prepared_post;
		}

		$meta = $request->get_param( 'meta' );
		$key  = Translation\SyncMeta::SYNC_OPTOUT_META;

		if ( ! is_array( $meta ) || ! array_key_exists( $key, $meta ) ) {
			return $prepared_post;
		}

		if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) ) {
			return $prepared_post;
		}

		$value = null === $meta[ $key ] ? '' : sanitize_meta( $key, $meta[ $key ], 'post', (string) get_post_type( $post_id ) );

		if ( 'yes' === $value ) {
			update_post_meta( $post_id, $key, 'yes' );
		} else {
			delete_post_meta( $post_id, $key );
		}

		return $prepared_post;
	}

	/**
	 * Anonymise a user's background-job rows on the site they are removed from.
	 *
	 * Hooked in every context: wpmu_delete_user() reaches the user's other sites only through
	 * this action, and account plugins run it from a front-end POST.
	 *
	 * @param int|string $user_id User being removed.
	 * @param int|string $blog_id Site the user is removed from.
	 * @return void
	 */
	public static function anonymize_jobs_on_blog( $user_id, $blog_id = 0 ): void {
		$user_id = (int) $user_id;
		$blog_id = (int) $blog_id;

		if ( $user_id <= 0 ) {
			return;
		}

		// Site teardown removes members after SiteCleanup dropped the tables: nothing to anonymise.
		if ( $blog_id > 0 && Database\SiteCleanup::was_site_purged( $blog_id ) ) {
			return;
		}

		$switched = false;

		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			// A site where the plugin was never activated has no jobs table.
			if ( Database\Schema::tables_exist() ) {
				Background\JobState::anonymize_for_user( $user_id );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Auto-assign the default language to new translatable posts (save_post:5, every context).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_assign_default_language( int $post_id, ?\WP_Post $post = null ): void {
		// Core passes null when the post was deleted after the write, and some plugins fire
		// save_post with one argument.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Child types inherit the parent's language (product_variation).
		if ( $post->post_type === 'product_variation' ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $post->post_type, $settings->get_translatable_post_types(), true ) ) {
			return;
		}

		$cache   = $plugin->get( 'cache' );
		$manager = new Translation\PostTranslationManager( $cache, $settings );

		// Already has a language.
		if ( $manager->detect_post_language( $post_id ) !== null ) {
			return;
		}

		$lang_repo    = new Database\Repository\LanguageRepository( $cache );
		$default_lang = $lang_repo->get_default();

		if ( ! $default_lang ) {
			return;
		}

		// The template reference rules at request end learn the post's type from here.
		Translation\TemplateBlockRefs::note_known_type( $post_id, $post->post_type );

		if ( ! $manager->set_post_language( $post_id, $default_lang->slug ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the post stays unassigned and is treated as default-language by the fallback convention.
			error_log( sprintf( 'PerfLocale: auto default-language assignment failed for new post %d.', $post_id ) );

			return;
		}

		// Terms passed to wp_insert_post() were attached before the post had a language: run the
		// wrong-language term swap once, with the language known.
		$term_filter = $plugin->has( 'term_assignment_filter' ) ? $plugin->get( 'term_assignment_filter' ) : null;

		if ( $term_filter instanceof Translation\TermAssignmentFilter ) {
			$term_filter->normalize_post_terms( $post_id );
		}
	}

	/**
	 * Auto-create empty translation stubs when a post is first published.
	 *
	 * The transition fires before REST and WooCommerce write terms, meta and product data, so
	 * this only records the post; stubs are made once its save completes
	 * ({@see self::create_stubs_after_insert()}, the WooCommerce hooks, a shutdown fallback).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public static function auto_create_translation_stubs( string $new_status, string $old_status, \WP_Post $post ): void {
		// No empty stubs for templates: a published empty translation renders a blank header.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post->post_type ) ) {
			return;
		}

		// Only trigger when a post transitions to "publish" for the first time.
		if ( $new_status !== 'publish' || $old_status === 'publish' ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $plugin->get( 'settings' )->get_translatable_post_types(), true ) ) {
			return;
		}

		$blog_id = get_current_blog_id();

		if ( isset( self::$pending_stubs[ $blog_id ] ) && count( self::$pending_stubs[ $blog_id ] ) >= self::PENDING_STUBS_CAP ) {
			self::create_stubs_now( (int) array_key_first( self::$pending_stubs[ $blog_id ] ) );
		}

		self::$pending_stubs[ $blog_id ][ (int) $post->ID ] = true;

		if ( false === has_action( 'shutdown', [ Bootstrap::class, 'create_pending_stubs' ] ) ) {
			add_action( 'wp_after_insert_post', [ Bootstrap::class, 'create_stubs_after_insert' ], 100, 1 );
			add_action( 'woocommerce_new_product', [ Bootstrap::class, 'create_stubs_after_product_save' ], 100, 1 );
			add_action( 'woocommerce_update_product', [ Bootstrap::class, 'create_stubs_after_product_save' ], 100, 1 );
			add_action( 'shutdown', [ Bootstrap::class, 'create_pending_stubs' ], 0 );
		}
	}

	/**
	 * Create the stubs of a post whose save has just completed.
	 *
	 * A WooCommerce product waits for its data store's hook, unless that save already ran inside
	 * this post save.
	 *
	 * @param int|string $post_id Post ID.
	 * @return void
	 */
	public static function create_stubs_after_insert( $post_id ): void {
		$post_id = (int) $post_id;
		$blog_id = get_current_blog_id();

		if ( ! isset( self::$pending_stubs[ $blog_id ][ $post_id ] ) ) {
			return;
		}

		if ( 'product' === get_post_type( $post_id ) && did_action( 'woocommerce_loaded' ) && ! isset( self::$stub_products_saved[ $blog_id ][ $post_id ] ) ) {
			return;
		}

		self::create_stubs_now( $post_id );
	}

	/**
	 * Create the stubs of a product once WooCommerce has saved its data.
	 *
	 * @param int|string $product_id Product ID.
	 * @return void
	 */
	public static function create_stubs_after_product_save( $product_id ): void {
		$product_id = (int) $product_id;
		$blog_id    = get_current_blog_id();

		if ( ! isset( self::$pending_stubs[ $blog_id ][ $product_id ] ) ) {
			return;
		}

		// Saved inside the product's own save_post: the language select and other save_post
		// writers have not run yet, so wait for wp_after_insert_post.
		if ( doing_action( 'save_post' ) ) {
			self::$stub_products_saved[ $blog_id ][ $product_id ] = true;

			return;
		}

		self::create_stubs_now( $product_id );
	}

	/**
	 * Create the stubs still pending at request end (no wp_after_insert_post or WooCommerce hook).
	 *
	 * @return void
	 */
	public static function create_pending_stubs(): void {
		foreach ( array_keys( self::$pending_stubs ) as $blog_id ) {
			$switched = is_multisite() && get_current_blog_id() !== (int) $blog_id;

			if ( $switched ) {
				switch_to_blog( (int) $blog_id );
			}

			try {
				foreach ( array_keys( self::$pending_stubs[ $blog_id ] ) as $post_id ) {
					self::create_stubs_now( (int) $post_id );
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		self::$pending_stubs       = [];
		self::$stub_products_saved = [];
	}

	/**
	 * Create the translation stubs of one recorded post on the current blog.
	 *
	 * The language is read after the save, so the editor's language select is respected.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function create_stubs_now( int $post_id ): void {
		$blog_id = get_current_blog_id();

		unset( self::$pending_stubs[ $blog_id ][ $post_id ], self::$stub_products_saved[ $blog_id ][ $post_id ] );

		$post   = get_post( $post_id );
		$plugin = Plugin::get_instance();

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		$settings  = $plugin->get( 'settings' );
		$cache     = $plugin->get( 'cache' );
		$manager   = new Translation\PostTranslationManager( $cache, $settings );
		$lang_repo = new Database\Repository\LanguageRepository( $cache );
		$default   = $lang_repo->get_default();

		if ( ! $default ) {
			return;
		}

		// Only create stubs for default-language posts.
		$post_lang = $manager->detect_post_language( $post->ID );

		if ( $post_lang && $post_lang->slug !== $default->slug ) {
			return;
		}

		// Lock the existence check and create loop: two near-simultaneous publish transitions would
		// both pass the `count > 1` guard. 60s TTL, below WP-Cron's 15-minute timeout.
		Concurrency\Lock::with(
			'auto_stubs_' . $post->ID,
			60,
			function () use ( $manager, $lang_repo, $default, $post ) {
				$existing = $manager->get_translations( $post->ID );

				if ( count( $existing ) > 1 ) {
					return;
				}

				foreach ( $lang_repo->get_active() as $lang ) {
					if ( $lang->slug === $default->slug ) {
						continue;
					}

					$manager->create_translation( $post->ID, $lang->slug, false );
				}
			}
		);
	}

	/**
	 * Queue a full file regeneration when a language update leaves a language without files.
	 *
	 * That is a non-default language activated or its locale changed; $language is re-read after
	 * the write, so an update that wrote nothing queues nothing.
	 *
	 * @param mixed $language Language row after the update (null when it could not be read).
	 * @param mixed $before   Language row before the update, as an array.
	 * @return void
	 */
	public static function regenerate_files_after_language_update( $language, $before = [] ): void {
		if ( ! is_object( $language ) || ! is_array( $before ) || ! empty( $language->is_default ) || empty( $language->is_active ) ) {
			return;
		}

		$activated      = empty( $before['is_active'] );
		$locale_changed = (string) ( $language->locale ?? '' ) !== (string) ( $before['locale'] ?? '' );

		if ( $activated || $locale_changed ) {
			self::queue_files_regeneration();
		}
	}

	/**
	 * Queue one full regeneration of this blog's compiled files for request end (files mode).
	 *
	 * Debounced per request and blog, so an import's many edits share one run on the final state.
	 *
	 * @return void
	 */
	public static function queue_files_regeneration(): void {
		if ( ! self::files_mode() ) {
			return;
		}

		self::$pending_files_regeneration[ get_current_blog_id() ] = true;

		if ( false === has_action( 'shutdown', [ Bootstrap::class, 'run_pending_files_regeneration' ] ) ) {
			add_action( 'shutdown', [ Bootstrap::class, 'run_pending_files_regeneration' ], 0 );
		}
	}

	/**
	 * Run the queued regenerations: one generate_all() per blog still in files mode.
	 *
	 * @return void
	 */
	public static function run_pending_files_regeneration(): void {
		$pending                          = self::$pending_files_regeneration;
		self::$pending_files_regeneration = [];

		foreach ( array_keys( $pending ) as $blog_id ) {
			$switched = is_multisite() && get_current_blog_id() !== $blog_id;

			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			try {
				if ( self::files_mode() ) {
					self::regenerate_files_now();
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Queue the background regeneration after a plugin update (files mode; one pending event).
	 *
	 * @return void
	 */
	public static function queue_files_regeneration_after_update(): void {
		if ( ! self::files_mode() ) {
			return;
		}

		if ( ! Background\BackgroundEvents::is_scheduled( Bootstrap::FILES_REGENERATE_HOOK, [] ) ) {
			Background\BackgroundEvents::enqueue( Bootstrap::FILES_REGENERATE_HOOK );
		}
	}

	/**
	 * Handler of FILES_REGENERATE_HOOK: one full regeneration while still in files mode.
	 *
	 * @return void
	 */
	public static function regenerate_files_in_background(): void {
		if ( self::files_mode() ) {
			self::regenerate_files_now();
		}
	}

	/**
	 * Whether strings are served from compiled translation files.
	 *
	 * @return bool
	 */
	private static function files_mode(): bool {
		$plugin = Plugin::get_instance();

		return $plugin->has( 'settings' ) && 'files' === (string) $plugin->get( 'settings' )->get( 'string_translation_mode', 'files' );
	}

	/**
	 * Full regeneration of the current blog's compiled files; a failure is logged, never thrown.
	 *
	 * @return void
	 */
	private static function regenerate_files_now(): void {
		try {
			( new Strings\TranslationFileGenerator( Plugin::get_instance()->get( 'cache' ) ) )->generate_all();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on a failure path that must not break the request.
			error_log( 'PerfLocale: regenerating the compiled translation files failed: ' . $e->getMessage() );
		}
	}

	/**
	 * transition_post_status: queue auto-translation of a newly published post (Background\Jobs\AutoTranslateJob).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public static function auto_translate_on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			Background\Jobs\AutoTranslateJob::on_publish( $post );
		}
	}

	/**
	 * AUTO_TRANSLATE_CRON: run an event an earlier version scheduled as a job.
	 *
	 * @param int $post_id Post ID.
	 * @param int $attempt Handoffs tried before.
	 * @return void
	 */
	public static function process_auto_translate( int $post_id, int $attempt = 0 ): void {
		Background\Jobs\AutoTranslateJob::from_legacy_event( $post_id, $attempt );
	}

	/**
	 * Auto-assign the default language to new translatable terms.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public static function auto_assign_default_term_language( int $term_id, int $tt_id, string $taxonomy, array $args = [] ): void {
		// Skip WP-internal taxonomies.
		if ( in_array( $taxonomy, [ 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' ], true ) ) {
			return;
		}

		// A language chosen in the term form is saved by the created_{taxonomy} handler.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['perflocale_term_language'] ) ) {
			return;
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) || ! $plugin->has( 'cache' ) ) {
			return;
		}

		$settings = $plugin->get( 'settings' );

		if ( ! in_array( $taxonomy, $settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		$cache   = $plugin->get( 'cache' );
		$manager = new Translation\TermTranslationManager( $cache );

		// Already has a language.
		if ( $manager->detect_term_language( $term_id ) !== null ) {
			return;
		}

		$lang_repo    = new Database\Repository\LanguageRepository( $cache );
		$default_lang = $lang_repo->get_default();

		if ( $default_lang && ! $manager->set_term_language( $term_id, $default_lang->slug ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic at a silent-failure point; the term stays unassigned and is treated as default-language by the fallback convention.
			error_log( sprintf( 'PerfLocale: auto default-language assignment failed for new term %d.', $term_id ) );
		}
	}

	/**
	 * Clear the `_perflocale_meta_copy_errors` warning entries a real save has addressed (save_post).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return void
	 */
	public static function clear_meta_copy_errors( int $post_id, ?\WP_Post $post = null ) : void {
		// Core passes null when the row was deleted after the write; see
		// auto_assign_default_language().
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Skip autosaves and revisions so a heartbeat autosave does not dismiss the
		// warning. Autosaves are revisions, so wp_is_post_revision() covers them.
		if (
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
		) {
			return;
		}

		// Most posts never had a copy-errors warning.
		$errors = get_post_meta( $post_id, '_perflocale_meta_copy_errors', true );

		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}

		// Self-clearing: a failed step's entry is dropped only when its content looks
		// populated, so saving without filling the fields keeps the warning.
		$remaining = [];
		foreach ( $errors as $err ) {
			$step = is_array( $err ) && isset( $err['step'] ) ? (string) $err['step'] : '';
			switch ( $step ) {
				case 'featured_image':
					if ( has_post_thumbnail( $post_id ) ) {
						continue 2; // drop this error entry
					}
					break;
				case 'taxonomy_terms':
					// Terms beyond the taxonomy default count: core gives every new post
					// "Uncategorized", which does not mean the copy worked.
					$has_term = false;
					foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
						$terms = wp_get_object_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
						if ( is_wp_error( $terms ) || count( $terms ) === 0 ) {
							continue;
						}
						$default_term = (int) get_option( 'default_' . $tax, 0 );
						$non_default  = array_filter(
							array_map( 'intval', $terms ),
							static fn( int $tid ): bool => $tid !== $default_term
						);
						if ( count( $non_default ) > 0 ) {
							$has_term = true;
							break;
						}
					}
					if ( $has_term ) {
						continue 2;
					}
					break;
				case 'post_meta':
					// Proxy: any non-internal meta key counts as addressed (the failed key is
					// not stored; _thumbnail_id belongs to featured_image).
					$meta_keys     = get_post_meta( $post_id );
					$has_user_meta = false;
					if ( is_array( $meta_keys ) ) {
						foreach ( array_keys( $meta_keys ) as $key ) {
							if ( ! is_string( $key ) ) {
								continue;
							}
							if ( $key === '_perflocale_meta_copy_errors' ) {
								continue;
							}
							if ( str_starts_with( $key, '_' ) ) {
								continue;
							}
							$has_user_meta = true;
							break;
						}
					}
					if ( $has_user_meta ) {
						continue 2;
					}
					break;
				default:
					// Unknown step: keep it rather than drop a signal.
					break;
			}
			$remaining[] = $err;
		}

		if ( count( $remaining ) === count( $errors ) ) {
			// Nothing addressed: keep the warning.
			return;
		}

		if ( empty( $remaining ) ) {
			delete_post_meta( $post_id, '_perflocale_meta_copy_errors' );
			return;
		}

		update_post_meta( $post_id, '_perflocale_meta_copy_errors', wp_slash( $remaining ) );
	}
}
