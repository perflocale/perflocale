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
	 * Posts inserted in this request that got the default language, per "blog|ID": they had no earlier address.
	 *
	 * @var array<string, true>
	 */
	private static array $assigned_new = [];

	/**
	 * The shape of a root path a language takes: a code or a locale-form prefix (de, fr-fr, de-de-formal).
	 */
	private const PATH_SHAPE = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/';

	/**
	 * Allow duplicate post slugs across languages; the URL's language prefix keeps them apart.
	 * Untyped: callers may pass null (core's REST create: ID and parent); a non-string slug or type passes through.
	 *
	 * @param mixed $slug The slug WP wants to assign (possibly with -2, -3, etc.).
	 * @param mixed $post_id Post ID; null reads as 0, like wp_insert_post()'s new post.
	 * @param mixed $post_status Post status (not read).
	 * @param mixed $post_type Post type.
	 * @param mixed $post_parent Post parent ID (hierarchical types).
	 * @param mixed $original_slug The original desired slug before deduplication.
	 * @return mixed The slug to use.
	 */
	public static function allow_translation_duplicate_slugs( $slug, $post_id = 0, $post_status = '', $post_type = '', $post_parent = 0, $original_slug = '' ) {
		if ( ! is_string( $slug ) || ! is_string( $original_slug ) || ! is_string( $post_type ) ) {
			return $slug;
		}

		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		// Never collapse a template translation's slug: its distinct post_name is core's only
		// lookup handle (`post_name__in`), and a shared one makes two rows answer one query.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post_type ) ) {
			return $slug;
		}

		// Only intervene if WP changed the slug (appended -2, -3, etc.) or the slug has a root path's shape.
		$shaped = 1 === preg_match( self::PATH_SHAPE, $slug );

		if ( $slug === $original_slug && ! $shaped ) {
			return $slug;
		}

		$plugin       = Plugin::get_instance();
		$settings     = $plugin->get( 'settings' );
		$translatable = in_array( $post_type, $settings->get_translatable_post_types(), true );

		// A type not translated keeps core's slug, except a root path below: the language prefix routes there anyway.
		if ( ! $translatable && ( ! $shaped || ( 'page' !== $post_type && 'post' !== $post_type ) ) ) {
			return $slug;
		}

		$lang_repo = new Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$parent    = is_numeric( $post_parent ) ? (int) $post_parent : 0;
		$result    = $slug === $original_slug || ! $translatable ? $slug : self::shared_slug( $slug, $post_id, $post_type, $parent, $original_slug, $lang_repo );

		if ( 0 !== $parent || ! method_exists( $settings, 'get_url_mode' ) || 'subdirectory' !== $settings->get_url_mode() ) {
			return $result;
		}

		$paths  = [];
		$locale = method_exists( $settings, 'get_url_prefix_type' ) && 'locale' === $settings->get_url_prefix_type();

		// Its code, and in locale form its prefix too (the code 301s to it): inactive languages as well.
		foreach ( $lang_repo->find_all() as $l ) {
			$paths[] = is_string( $l->slug ?? null ) ? $l->slug : '';
			$paths[] = $locale && is_string( $l->locale ?? null ) && method_exists( $settings, 'get_url_prefix' ) ? $settings->get_url_prefix( $l ) : '';
		}

		// A root path a language takes reads as that language, in every language: core's next free "-N".
		if ( '' !== $result && in_array( $result, $paths, true ) ) {
			require_once __DIR__ . '/Translation/LanguageCodeSlugs.php';

			if ( Translation\LanguageCodeSlugs::at_root( $post_type, $parent ) ) {
				$status = is_string( $post_status ) ? $post_status : 'publish';

				// First free "<prefix>-N" from the prefix: core suffixes a taken seed again ("de-2-2").
				for ( $n = 2; $n < 100; $n++ ) {
					if ( wp_unique_post_slug( $result . '-' . $n, $post_id, $status, $post_type, $parent ) === $result . '-' . $n ) {
						return $result . '-' . $n;
					}
				}

				return wp_unique_post_slug( $result . '-' . self::free_suffix( $result, $post_id, $post_type ), $post_id, $status, $post_type, $parent );
			}
		}

		return $result;
	}

	/**
	 * The first N from 100 whose "<prefix>-N" no root post of core's conflict set holds, from one read.
	 *
	 * @param string $prefix    Root path a language takes.
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type (page or post).
	 * @return int
	 */
	private static function free_suffix( string $prefix, int $post_id, string $post_type ): int {
		global $wpdb;

		$taken = [];

		if ( $wpdb instanceof \wpdb ) {
			// Core's set: pages share it with attachments under one parent; short names only, so the read stays small.
			$types = 'page' === $post_type ? [ 'page', 'attachment' ] : [ $post_type ];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$names = $wpdb->get_col( $wpdb->prepare( 'SELECT post_name FROM %i WHERE post_name LIKE %s AND LENGTH(post_name) <= %d AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID != %d' . ( 'page' === $post_type ? ' AND post_parent = 0' : '' ), array_merge( [ $wpdb->posts, $wpdb->esc_like( $prefix . '-' ) . '%', strlen( $prefix ) + 8 ], $types, [ $post_id ] ) ) );

			foreach ( (array) $names as $name ) {
				if ( is_string( $name ) && 1 === preg_match( '/^' . preg_quote( $prefix, '/' ) . '-([0-9]+)$/', $name, $m ) ) {
					$taken[ (int) $m[1] ] = true;
				}
			}
		}

		$n = 100;

		while ( isset( $taken[ $n ] ) ) {
			++$n;
		}

		return $n;
	}

	/**
	 * Core's slug, or the original one when every post holding it is in another language.
	 *
	 * @param string                                 $slug          Core's slug.
	 * @param int                                    $post_id       Post ID.
	 * @param string                                 $post_type     Post type.
	 * @param int                                    $parent        Parent ID.
	 * @param string                                 $original_slug Slug asked for.
	 * @param Database\Repository\LanguageRepository $lang_repo     Languages.
	 * @return string
	 */
	private static function shared_slug( string $slug, int $post_id, string $post_type, int $parent, string $original_slug, Database\Repository\LanguageRepository $lang_repo ): string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return $slug;
		}

		// Core's conflict set: hierarchical types compare one parent and attachments too.
		$hierarchical = is_post_type_hierarchical( $post_type );
		$sql          = $hierarchical
			? "SELECT ID, post_type FROM %i WHERE post_name = %s AND post_type IN ( %s, 'attachment' ) AND ID != %d AND post_status NOT IN ( 'trash', 'auto-draft' ) AND post_parent = %d"
			: "SELECT ID, post_type FROM %i WHERE post_name = %s AND post_type = %s AND ID != %d AND post_status NOT IN ( 'trash', 'auto-draft' )";
		$args         = [ $wpdb->posts, $original_slug, $post_type, $post_id ];

		if ( $hierarchical ) {
			$args[] = $parent;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is one of the two literals above.
		$conflicts = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

		// No conflict left (core suffixed for another reason), or an attachment (no language): keep core's slug.
		if ( [] === $conflicts || in_array( 'attachment', array_column( $conflicts, 'post_type' ), true ) ) {
			return $slug;
		}

		// Collapse only when the conflicting posts are in a different language; a same-language
		// collision keeps WP's -N suffix, or the URLs would be identical.
		$manager = new Translation\PostTranslationManager( Plugin::get_instance()->get( 'cache' ), Plugin::get_instance()->get( 'settings' ) );

		// The language this save ends with: a move's or a form's, create_translation()'s hint for its new post (ID 0, no
		// link row yet), the stored one.
		require_once __DIR__ . '/Translation/PostSlugCheck.php';

		$current_lang = Translation\PostSlugCheck::language_for( $post_id, $manager, $lang_repo );

		if ( ! $current_lang && 0 === $post_id && Translation\PostTranslationManager::$creating_translation_lang_slug !== null ) {
			$current_lang = $lang_repo->find_by_slug( Translation\PostTranslationManager::$creating_translation_lang_slug );
		}

		$current_lang = $current_lang ?? $manager->detect_post_language( $post_id );

		// A new post (ID 0, e.g. a REST create) gets the default language at save_post; a later move is re-checked.
		if ( ! $current_lang && 0 === $post_id ) {
			$current_lang = $lang_repo->get_default();
		}

		if ( ! $current_lang ) {
			// Unknown language: be conservative.
			return $slug;
		}

		$default_id = $lang_repo->get_default()->id ?? null;
		$current_id = $current_lang->id ?? null;
		$is_default = is_numeric( $default_id ) && is_numeric( $current_id ) && (int) $default_id === (int) $current_id;

		foreach ( array_column( $conflicts, 'ID' ) as $conflict_id ) {
			$conflict_lang = $manager->detect_post_language( is_numeric( $conflict_id ) ? (int) $conflict_id : 0 );

			// Same language, or no language (served at the default language's address) while this post is in the default.
			if ( $conflict_lang ? (int) $conflict_lang->id === (int) $current_lang->id : $is_default ) {
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

		// Its Content ReSync rows (as a translation; as a source its bases, which copy its text) and the pending changes
		// proposed from it, ReSync on or off and on every driver; a trashed post keeps them. has_tables() reads an autoloaded option.
		if ( Resync\Store::has_tables() ) {
			( new Database\Repository\ResyncRepository() )->forget_post( $post_id );

			if ( ( new Database\Repository\PendingChangeRepository() )->close_pending_for_source( $post_id ) > 0 ) {
				wp_cache_delete( Automation\ChangeService::badge_cache_key(), Automation\ChangeService::BADGE_GROUP );
			}
		}
	}

	/**
	 * Bind the global_fields_sync worker and Content ReSync's where Bootstrap did not (a REST retry); registering again
	 * replaces the factory.
	 *
	 * @return void
	 */
	public static function bind_resync_workers(): void {
		Background\WorkerRegistry::register(
			'global_fields_sync',
			static fn(): Background\AbstractJob => new Background\Jobs\GlobalFieldsSyncJob()
		);
		Util\ResyncSwitch::register_workers();
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
				Automation\PersonalData::forget_user( $user_id );
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

		// An insert (an update moves post_modified): a later move in this request records no old slug.
		if ( $post->post_date_gmt === $post->post_modified_gmt ) {
			self::$assigned_new[ get_current_blog_id() . '|' . $post_id ] = true;
		}

		// Terms passed to wp_insert_post() were attached before the post had a language: run the
		// wrong-language term swap once, with the language known.
		$term_filter = $plugin->has( 'term_assignment_filter' ) ? $plugin->get( 'term_assignment_filter' ) : null;

		if ( $term_filter instanceof Translation\TermAssignmentFilter ) {
			$term_filter->normalize_post_terms( $post_id );
		}
	}

	/**
	 * Whether a post was inserted in this request (and got the default language then).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function inserted_now( int $post_id ): bool {
		return isset( self::$assigned_new[ get_current_blog_id() . '|' . $post_id ] );
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

		// Both keys in one read: one cache read and one pass of other plugins' meta filters. Row 0 as a single read
		// gives it: a filter may supply rows already unserialized.
		$meta   = get_post_meta( $post_id );
		$row    = static fn( string $key ): mixed => is_array( $meta ) && is_array( $meta[ $key ] ?? null ) && array_key_exists( 0, $meta[ $key ] ) ? ( is_string( $meta[ $key ][0] ) ? maybe_unserialize( $meta[ $key ][0] ) : $meta[ $key ][0] ) : '';
		$errors = $row( '_perflocale_meta_copy_errors' );

		// A stub's recorded title goes once the post has content or another title; StubTitle loads only for a post
		// that carries one.
		if ( '' !== $row( '_perflocale_stub_title' ) ) {
			Translation\StubTitle::forget_spent( $post );
		}

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
				case 'unresolved_fields':
					// Custom fields "+ Create" left out (their definition was not loaded): addressed once each holds a value.
					$missing = array_filter( (array) ( $err['keys'] ?? [] ), static fn ( mixed $key ): bool => ! is_string( $key ) || '' === get_post_meta( $post_id, $key, true ) );

					if ( [] === $missing ) {
						continue 2;
					}
					break;
				case 'post_meta':
					// Keys a failed insert stopped: addressed once each holds the rows the copy wanted (a stop leaves a prefix).
					if ( isset( $err['rows'] ) && is_array( $err['rows'] ) ) {
						$missing = array_filter( $err['rows'], static fn ( mixed $rows, int|string $key ): bool => ! is_int( $rows ) || ! is_array( $meta ) || count( (array) ( $meta[ $key ] ?? [] ) ) < $rows, ARRAY_FILTER_USE_BOTH );

						if ( [] === $missing ) {
							continue 2;
						}
						break;
					}

					// Without named keys, a proxy: any non-internal meta key counts as addressed (_thumbnail_id belongs
					// to featured_image).
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

	/**
	 * Switch to files mode (perflocale/strings/regenerate_files): regenerate every .l10n.php file in the saving
	 * request, then clear the transient so TranslationFileLoader stops retrying.
	 *
	 * @param Cache\CacheManager $cache Cache manager.
	 * @return void
	 */
	public static function regenerate_all_files( Cache\CacheManager $cache ): void {
		$generator = new Strings\TranslationFileGenerator( $cache );
		$generator->generate_all();
		delete_transient( 'perflocale_strings_regenerating' );
	}

	/**
	 * Reset Settings' memo and the ReSync switch's (if loaded) when the row is added, saved or deleted (Settings::watch_row()).
	 *
	 * @return void
	 */
	public static function reset_settings_memo(): void {
		if ( class_exists( Util\ResyncSwitch::class, false ) ) {
			Util\ResyncSwitch::reset();
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'settings' ) ) {
			return;
		}

		$settings = $plugin->get( 'settings' );

		if ( method_exists( $settings, 'reset_cache' ) ) {
			$settings->reset_cache();
		}
	}
}
