<?php
/**
 * Content synchronization across language versions.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes configured fields across all language versions of a post
 * when the original is updated.
 *
 * Fields like featured image, menu order, and custom meta keys can be
 * configured to stay in sync across translations. The featured image and
 * custom meta keys also follow writes made without a post save, except
 * writes made during a front-end page view. A blog whose Sync Fields list is
 * empty syncs none of them. The post password is not a Sync Field: it
 * follows the default-language member on every blog (sync_password()).
 */
final class ContentSync {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var PostTranslationManager
	 */
	private readonly PostTranslationManager $manager;

	/**
	 * Cache manager. Held so a sibling whose row needed no write still gets
	 * its object caches flushed - that is where the public
	 * `perflocale/cache/flush_object` purge signal comes from.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Short TTL for the cross-request sync lock (seconds).
	 *
	 * Long enough to outlive a normal sync; short enough that a crashed
	 * request doesn't block subsequent saves indefinitely.
	 */
	private const LOCK_TTL = 15;

	/**
	 * Sync fields that are columns of the posts row, mapped to the column.
	 */
	private const POST_FIELD_MAP = [
		'menu_order'     => 'menu_order',
		'post_date'      => 'post_date',
		'post_author'    => 'post_author',
		'post_parent'    => 'post_parent',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
	];

	/**
	 * Most posts this request tracks as "being saved" or as holding deferred
	 * meta-write syncs. Past it the oldest entry is dropped (open saves) or
	 * synced at once (deferred writes).
	 */
	private const DEFERRED_CAP = 200;

	/**
	 * Depth of sibling writes ContentSync itself is making. Meta writes seen
	 * while it is above zero are the sync's own and start nothing.
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Posts inside a save right now ("{blog_id}:{post_id}" => true): from
	 * `pre_post_update`, the start of `save_post` or the edit-screen referer
	 * check, until `wp_after_insert_post`. A synced meta write on such a post
	 * waits for the save instead of syncing on its own.
	 *
	 * @var array<string, true>
	 */
	private array $open_saves = [];

	/**
	 * Synced meta writes waiting for their post's save to finish,
	 * "{blog_id}:{post_id}" => [ sync field => true ].
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $deferred = [];

	/**
	 * Posts being permanently deleted right now ("{blog_id}:{post_id}" =>
	 * true), from `before_delete_post` / `delete_attachment` until
	 * `deleted_post`. Core removes their meta rows one by one on the way out;
	 * those removals are not edits and reach no sibling.
	 *
	 * @var array<string, true>
	 */
	private array $deleting = [];

	/**
	 * Whether the shutdown fallback for deferred writes is hooked.
	 *
	 * @var bool
	 */
	private bool $shutdown_hooked = false;

	/**
	 * Meta keys whose clears the meta-API watcher records in the seed-cleared
	 * marker (key => true), registered by integrations whose host plugin
	 * writes the keys itself; see track_seed_clears().
	 *
	 * @var array<string, true>
	 */
	private static array $clear_tracked = [];

	/**
	 * Posts a person is editing in this request ("{blog_id}:{post_id}" =>
	 * true): the post's edit screen passed its nonce check, a cookie-signed
	 * REST write names the post, or a host plugin's own form (ACF, Meta Box)
	 * passed its nonce check for it. Only a clear made while its post is
	 * listed here is recorded in the seed-cleared marker.
	 *
	 * @var array<string, true>
	 */
	private static array $person_edits = [];

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( Settings $settings, CacheManager $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
		$this->manager  = new PostTranslationManager( $cache, $settings );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Run after other save_post handlers (priority 20).
		add_action( 'save_post', [ $this, 'sync_on_save' ], 20, 2 );

		// Sync term-level changes to sibling translations too - without this
		// only post translations stay in sync while taxonomy term edits
		// diverge silently.
		add_action( 'edited_term', [ $this, 'sync_on_term_edit' ], 20, 3 );

		// The featured image and the site's own Sync Fields meta keys also
		// change without a post save, or after the save has already run:
		// set_post_thumbnail(), update_post_meta(), a WooCommerce CRUD save,
		// REST `featured_media` / `meta`. Each such write syncs that one field.
		add_action( 'added_post_meta', [ $this, 'on_meta_write' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'on_meta_write' ], 10, 3 );
		add_action( 'deleted_post_meta', [ $this, 'on_meta_write' ], 10, 3 );

		// A write made while its post is being saved waits for that save:
		// save_post covers what was written before it, wp_after_insert_post
		// what came after (REST writes the image and meta after save_post).
		add_action( 'pre_post_update', [ $this, 'open_save' ], 10, 1 );
		add_action( 'save_post', [ $this, 'open_save' ], PHP_INT_MIN, 1 );
		add_action( 'check_admin_referer', [ $this, 'open_edit_screen_save' ], 10, 2 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'open_rest_person_edit' ], 10, 3 );
		add_action( 'wp_after_insert_post', [ $this, 'close_save' ], 20, 2 );

		// A changed post password reaches the translations that held the
		// previous one, once the save is complete.
		add_action( 'wp_after_insert_post', [ $this, 'sync_password' ], 30, 4 );

		// Deleting a post removes its image and meta rows, which must not
		// clear them on the rest of the group.
		add_action( 'before_delete_post', [ $this, 'open_delete' ], PHP_INT_MIN, 1 );
		add_action( 'delete_attachment', [ $this, 'open_delete' ], PHP_INT_MIN, 1 );
		add_action( 'deleted_post', [ $this, 'close_delete' ], PHP_INT_MAX, 1 );

		// A tracked key emptied on a post is recorded in its seed-cleared
		// marker, read before core rewrites or removes the row.
		add_action( 'add_post_meta', [ $this, 'note_meta_add' ], 10, 3 );
		add_action( 'update_post_meta', [ $this, 'note_meta_update' ], 10, 4 );
		add_action( 'delete_post_meta', [ $this, 'note_meta_delete' ], 10, 3 );
	}

	/**
	 * Whether this request is a front-end page view: a GET or HEAD request
	 * outside admin, AJAX, cron, REST and WP-CLI. Meta written while a page
	 * is viewed (a view counter, a cached value) is not an edit: it starts no
	 * sync and records no clear. The post's next save syncs every field.
	 *
	 * @return bool
	 */
	public static function is_page_view(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return ( 'GET' === $method || 'HEAD' === $method ) && ! \PerfLocale\Helper::is_write_context();
	}

	/**
	 * Record clears of these meta keys in the seed-cleared marker whenever
	 * they are written through the meta API. For integrations whose host
	 * plugin stores its fields with plain meta writes (SEO plugins).
	 *
	 * @param array<int, mixed> $keys Meta keys.
	 * @return void
	 */
	public static function track_seed_clears( array $keys ): void {
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && $key !== '' ) {
				self::$clear_tracked[ $key ] = true;
			}
		}
	}

	/**
	 * The keys a post's seed-cleared marker lists, read from its meta cache.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, true> Cleared key => true.
	 */
	public static function seed_cleared_keys( int $post_id ): array {
		$cleared = [];

		foreach ( (array) get_post_meta( $post_id, self::SEED_CLEARED_META, false ) as $key ) {
			if ( is_string( $key ) && $key !== '' ) {
				$cleared[ $key ] = true;
			}
		}

		return $cleared;
	}

	/**
	 * Add one key to a post's seed-cleared marker, or remove it. A key is
	 * added only while a person is editing the post (is_person_editing());
	 * an empty written by code, an import, WP-CLI or a background job is not
	 * recorded, so the seed and machine translation still fill it. A key is
	 * removed whoever writes a value. Each key is its own row, so writers
	 * that record different keys at the same time cannot overwrite each
	 * other; a duplicate row left by two writers of the same key is removed
	 * with it. Writes only on change.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $cleared True to record the clear, false to forget it.
	 * @return void
	 */
	public static function record_seed_clear( int $post_id, string $key, bool $cleared ): void {
		if ( $post_id <= 0 || $key === '' || ( $cleared && ! self::is_person_editing( $post_id ) )
			|| isset( self::seed_cleared_keys( $post_id )[ $key ] ) === $cleared ) {
			return;
		}

		if ( $cleared ) {
			add_post_meta( $post_id, self::SEED_CLEARED_META, wp_slash( $key ) );
		} else {
			delete_post_meta( $post_id, self::SEED_CLEARED_META, wp_slash( $key ) );
		}
	}

	/**
	 * Whether a stored meta value counts as empty: no value, '' or [].
	 *
	 * @param mixed $value Meta value.
	 * @return bool
	 */
	private static function is_empty_meta_value( mixed $value ): bool {
		return null === $value || false === $value || '' === $value || [] === $value;
	}

	/**
	 * `add_post_meta`: a tracked key that gets a value is no longer cleared.
	 * Checked only while the post's meta is cached (an editor or REST save
	 * reads it first), so a bulk insert pays no marker read per row. An entry
	 * left on a field that holds a value changes nothing: the seed and machine
	 * translation leave a value alone.
	 *
	 * @param int|mixed    $object_id  Post ID.
	 * @param string|mixed $meta_key   Meta key.
	 * @param mixed        $meta_value Value being added.
	 * @return void
	 */
	public function note_meta_add( mixed $object_id = 0, mixed $meta_key = '', mixed $meta_value = null ): void {
		$post_id = $this->tracked_write( $object_id, $meta_key );

		if ( $post_id > 0 && ! self::is_empty_meta_value( $meta_value ) && false !== wp_cache_get( $post_id, 'post_meta' ) ) {
			self::record_seed_clear( $post_id, (string) $meta_key, false );
		}
	}

	/**
	 * `update_post_meta`: a tracked key saved empty after holding a value is
	 * cleared; saved with a value, it is not.
	 *
	 * @param int|mixed    $meta_id    Meta ID (unused).
	 * @param int|mixed    $object_id  Post ID.
	 * @param string|mixed $meta_key   Meta key.
	 * @param mixed        $meta_value New value.
	 * @return void
	 */
	public function note_meta_update( mixed $meta_id = 0, mixed $object_id = 0, mixed $meta_key = '', mixed $meta_value = null ): void {
		$post_id = $this->tracked_write( $object_id, $meta_key );

		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! self::is_empty_meta_value( $meta_value ) ) {
			self::record_seed_clear( $post_id, (string) $meta_key, false );
		} elseif ( self::holds_value( (array) get_post_meta( $post_id, (string) $meta_key, false ) ) ) {
			$this->record_tracked_clear( $post_id, (string) $meta_key );
		}
	}

	/**
	 * `delete_post_meta`: a tracked key whose every row is removed while one
	 * held a value is cleared.
	 *
	 * @param int|int[]|mixed $meta_ids  Meta ID(s) being deleted.
	 * @param int|mixed       $object_id Post ID.
	 * @param string|mixed    $meta_key  Meta key.
	 * @return void
	 */
	public function note_meta_delete( mixed $meta_ids = [], mixed $object_id = 0, mixed $meta_key = '' ): void {
		$post_id = $this->tracked_write( $object_id, $meta_key );

		if ( $post_id <= 0 ) {
			return;
		}

		$rows = (array) get_post_meta( $post_id, (string) $meta_key, false );

		if ( count( (array) $meta_ids ) >= count( $rows ) && self::holds_value( $rows ) ) {
			$this->record_tracked_clear( $post_id, (string) $meta_key );
		}
	}

	/**
	 * The post a write of a tracked key belongs to, or 0 when the write is
	 * not a person's edit: the sync's own writes, a translation being
	 * created, a post being deleted, or a write made during a page view.
	 *
	 * @param int|mixed    $object_id Post ID.
	 * @param string|mixed $meta_key  Meta key.
	 * @return int
	 */
	private function tracked_write( mixed $object_id, mixed $meta_key ): int {
		if ( ! is_string( $meta_key ) || ! isset( self::$clear_tracked[ $meta_key ] ) || ! is_numeric( $object_id ) || (int) $object_id <= 0 ) {
			return 0;
		}

		$post_id = (int) $object_id;

		if ( self::$writing > 0 || PostTranslationManager::is_creating()
			|| isset( $this->deleting[ $this->state_key( $post_id ) ] ) || self::is_page_view() ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Whether any of a key's rows holds a value.
	 *
	 * @param array<int, mixed> $rows Meta rows.
	 * @return bool
	 */
	private static function holds_value( array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( ! self::is_empty_meta_value( $row ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record a tracked key's clear on a post of a translatable post type.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	private function record_tracked_clear( int $post_id, string $meta_key ): void {
		if ( in_array( get_post_type( $post_id ), $this->settings->get_translatable_post_types(), true ) ) {
			self::record_seed_clear( $post_id, $meta_key, true );
		}
	}

	/**
	 * Key for the per-request save state of a post on the current blog.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function state_key( int $post_id ): string {
		return get_current_blog_id() . ':' . $post_id;
	}

	/**
	 * Mark a post as being saved.
	 *
	 * @param int|mixed $post_id Post ID.
	 * @return void
	 */
	public function open_save( mixed $post_id = 0 ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( count( $this->open_saves ) >= self::DEFERRED_CAP ) {
			unset( $this->open_saves[ array_key_first( $this->open_saves ) ] );
		}

		$this->open_saves[ $this->state_key( $post_id ) ] = true;
	}

	/**
	 * Mark a post as being saved from its edit screen. The classic editor
	 * writes custom fields before wp_update_post(), right after this check.
	 * The block editor's meta-box save passes the same check. A passed check
	 * also means a person is editing the post.
	 *
	 * @param int|string|mixed $action Nonce action being checked.
	 * @param int|false|mixed  $result Nonce check result: 1 or 2 when valid.
	 * @return void
	 */
	public function open_edit_screen_save( mixed $action = '', mixed $result = false ): void {
		if ( is_string( $action ) && str_starts_with( $action, 'update-post_' ) ) {
			$this->open_save( substr( $action, 12 ) );

			if ( 1 === $result || 2 === $result ) {
				self::open_person_edit( substr( $action, 12 ) );
			}
		}
	}

	/**
	 * `rest_request_before_callbacks`: a write request signed by the
	 * logged-in cookie and its REST nonce (the block editor, and the editor
	 * panels of SEO and field plugins) marks the post it names as edited by
	 * a person. Requests authenticated any other way (application passwords
	 * and other API clients) mark nothing.
	 *
	 * @param mixed $response Response so far, returned unchanged.
	 * @param mixed $handler  Route handler (unused).
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public function open_rest_person_edit( mixed $response = null, mixed $handler = null, mixed $request = null ): mixed {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request
			|| in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true )
			|| true !== ( $GLOBALS['wp_rest_auth_cookie'] ?? null ) || ! \PerfLocale\Helper::is_rest_request() ) {
			return $response;
		}

		foreach ( [ 'id', 'post', 'post_id', 'objectID' ] as $param ) {
			self::open_person_edit( $request->get_param( $param ) );
		}

		return $response;
	}

	/**
	 * Mark a post as edited by a person in this request. Called when an
	 * editing form's own nonce check for the post has passed.
	 *
	 * @param int|string|mixed $post_id Post ID.
	 * @return void
	 */
	public static function open_person_edit( mixed $post_id = 0 ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( count( self::$person_edits ) >= self::DEFERRED_CAP ) {
			unset( self::$person_edits[ array_key_first( self::$person_edits ) ] );
		}

		self::$person_edits[ get_current_blog_id() . ':' . $post_id ] = true;
	}

	/**
	 * Whether a logged-in person is editing this post right now: an editing
	 * context marked the post (open_person_edit()), the current user may edit
	 * it, and this is not a cron run. WP-CLI, imports, background jobs and
	 * plain code never pass an editing form's nonce check or a cookie-signed
	 * REST write, so they never mark a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_person_editing( int $post_id ): bool {
		return $post_id > 0
			&& isset( self::$person_edits[ get_current_blog_id() . ':' . $post_id ] )
			&& ! wp_doing_cron()
			&& is_user_logged_in()
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * A post's save is complete: sync the writes that waited for it.
	 *
	 * @param int|mixed           $post_id Post ID.
	 * @param \WP_Post|mixed|null $post    Post object.
	 * @return void
	 */
	public function close_save( mixed $post_id = 0, mixed $post = null ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return;
		}

		$key = $this->state_key( $post_id );
		unset( $this->open_saves[ $key ] );

		if ( ! isset( $this->deferred[ $key ] ) || self::$writing > 0 ) {
			return;
		}

		$fields = array_keys( $this->deferred[ $key ] );
		unset( $this->deferred[ $key ] );

		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$this->run_sync( $post_id, $post, $fields );
		}
	}

	/**
	 * `wp_after_insert_post`: when the post password of a group's
	 * default-language member is set, changed or removed, each translation
	 * that held the previous password gets the new one.
	 *
	 * A translation is the same page in another language, so the password
	 * that protects the source protects it too: a new translation copies the
	 * source's password (PostTranslationManager::create_translation()), and
	 * this keeps it in step afterwards, whatever the Sync Fields list holds.
	 * Like Page Parent and the seed-only keys it travels only from the
	 * default-language member, and like the seed-only keys a translation
	 * keeps a value of its own: a translation whose password differs from
	 * the source's previous one is left alone, and so is a private one (core
	 * stores no password for a private post). A source that is now private is
	 * skipped for the same reason: its password was emptied by its status,
	 * not removed. The sync opt-out applies on both sides. A save that keeps
	 * the password returns after one string comparison.
	 *
	 * The translation is written with wp_update_post(), so its save hooks
	 * (cache purges included) run, and only its password and its modified
	 * dates change. Its
	 * lock is taken when free, so that save starts no sync of its own; a
	 * translation another request is syncing is still written, as that sync
	 * does not carry the password.
	 *
	 * @param int|mixed           $post_id     Post ID.
	 * @param \WP_Post|mixed|null $post        Post object after the save.
	 * @param bool|mixed          $update      Whether an existing post was updated.
	 * @param \WP_Post|mixed|null $post_before Post object before the save; null for a new post.
	 * @return void
	 */
	public function sync_password( mixed $post_id = 0, mixed $post = null, mixed $update = false, mixed $post_before = null ): void {
		unset( $post_id, $update );

		if ( ! $post instanceof \WP_Post || ! $post_before instanceof \WP_Post ) {
			return;
		}

		$old = (string) $post_before->post_password;
		$new = (string) $post->post_password;

		if ( $old === $new || 'private' === $post->post_status ) {
			return;
		}

		$source_id = (int) $post->ID;

		if ( wp_is_post_revision( $source_id ) || wp_is_post_autosave( $source_id )
			|| \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post->post_type )
			|| ! in_array( $post->post_type, $this->settings->get_translatable_post_types(), true )
		) {
			return;
		}

		$translations = $this->manager->get_translations( $source_id );

		if ( count( $translations ) < 2 || ! $this->is_default_language_post( $source_id, $translations ) || $this->is_sync_opted_out( $source_id ) ) {
			return;
		}

		foreach ( $translations as $translated_id ) {
			$translated_id = (int) $translated_id;

			if ( $translated_id === $source_id || $this->is_sync_opted_out( $translated_id ) ) {
				continue;
			}

			$translation = get_post( $translated_id );

			if ( ! $translation instanceof \WP_Post || (string) $translation->post_password !== $old || 'private' === $translation->post_status ) {
				continue;
			}

			$locked = Lock::acquire( $this->post_lock( $translated_id ), self::LOCK_TTL );

			$unpin = self::keep_columns( $translation, [ 'post_password' ] );

			try {
				++self::$writing;

				// wp_update_post() expects slashed input. An empty page_template
				// leaves the stored template alone: one the theme no longer has
				// would stop the save before its hooks.
				$result = wp_update_post(
					[
						'ID'            => $translated_id,
						'post_password' => wp_slash( $new ),
						'page_template' => '',
					],
					true
				);

				if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; one line per vetoed translation update.
					error_log( sprintf( 'PerfLocale ContentSync: the password of translation %d was not updated: %s', $translated_id, $result->get_error_message() ) );
				}
			} finally {
				$unpin();
				--self::$writing;

				if ( $locked ) {
					Lock::release( $this->post_lock( $translated_id ) );
				}
			}
		}
	}

	/**
	 * Keep every column of a post but the given ones and its modified dates
	 * as stored in its next wp_update_post() call.
	 *
	 * The update merges the stored row and runs it through the saving user's
	 * filters (KSES rewrites the content, title and excerpt for a user
	 * without unfiltered_html), resets a draft's date and publishes a
	 * scheduled post whose date has passed. For the first update of this
	 * post after this call, every column outside $columns is written back as
	 * stored, except post_modified and post_modified_gmt: the write moves
	 * them as any save does, so sitemaps, caches and REST modified_after
	 * queries see the change. The save and its hooks run as usual, so cache
	 * purges run. The caller passes an empty page_template with the update,
	 * so a stored template the theme no longer has does not stop the save
	 * before its hooks, and removes the filter with the returned callable in
	 * `finally`.
	 *
	 * @param \WP_Post           $post    The post as stored.
	 * @param array<int, string> $columns Columns the update writes.
	 * @return \Closure():void Removes the filter.
	 */
	private static function keep_columns( \WP_Post $post, array $columns ): \Closure {
		$stored  = get_object_vars( $post );
		$writes  = array_flip( array_merge( $columns, [ 'post_modified', 'post_modified_gmt' ] ) );
		$post_id = (int) $post->ID;
		$applied = false;
		$filter  = static function ( mixed $data, mixed $args ) use ( $stored, $writes, $post_id, &$applied ): mixed {
			$target = is_array( $args ) ? ( $args['ID'] ?? 0 ) : 0;

			if ( $applied || ! is_array( $data ) || ! is_numeric( $target ) || (int) $target !== $post_id ) {
				return $data;
			}

			$applied = true;

			foreach ( array_keys( $data ) as $column ) {
				if ( ! isset( $writes[ $column ] ) && array_key_exists( $column, $stored ) ) {
					$data[ $column ] = is_string( $stored[ $column ] ) ? wp_slash( $stored[ $column ] ) : $stored[ $column ];
				}
			}

			return $data;
		};
		$hooks   = [ 'wp_insert_post_data', 'wp_insert_attachment_data' ];

		foreach ( $hooks as $hook ) {
			add_filter( $hook, $filter, PHP_INT_MAX, 2 );
		}

		return static function () use ( $hooks, $filter ): void {
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, $filter, PHP_INT_MAX );
			}
		};
	}

	/**
	 * Mark a post as being permanently deleted. Writes that were waiting for
	 * its save are dropped with it.
	 *
	 * @param int|mixed $post_id Post ID.
	 * @return void
	 */
	public function open_delete( mixed $post_id = 0 ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( count( $this->deleting ) >= self::DEFERRED_CAP ) {
			unset( $this->deleting[ array_key_first( $this->deleting ) ] );
		}

		$key = $this->state_key( $post_id );

		$this->deleting[ $key ] = true;
		unset( $this->deferred[ $key ] );
	}

	/**
	 * A post's permanent delete is complete.
	 *
	 * @param int|mixed $post_id Post ID.
	 * @return void
	 */
	public function close_delete( mixed $post_id = 0 ): void {
		if ( is_numeric( $post_id ) ) {
			unset( $this->deleting[ $this->state_key( (int) $post_id ) ] );
		}
	}

	/**
	 * Run the deferred syncs whose save never reported completion (a caller
	 * that inserted with `$fire_after_hooks = false` and did not fire
	 * wp_after_insert_post itself).
	 *
	 * @return void
	 */
	public function flush_deferred(): void {
		while ( $this->deferred !== [] ) {
			$this->run_deferred( (string) array_key_first( $this->deferred ) );
		}
	}

	/**
	 * Run one deferred entry now, on the blog it was recorded on.
	 *
	 * @param string $key State key.
	 * @return void
	 */
	private function run_deferred( string $key ): void {
		$fields = array_keys( $this->deferred[ $key ] ?? [] );
		unset( $this->deferred[ $key ], $this->open_saves[ $key ] );

		[ $blog_id, $post_id ] = array_map( 'intval', explode( ':', $key, 2 ) + [ 0, 0 ] );

		if ( $fields === [] || $post_id <= 0 ) {
			return;
		}

		$switched = is_multisite() && $blog_id !== get_current_blog_id();

		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		try {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$this->run_sync( $post_id, $post, $fields );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The sync field a post-meta key stands for when the site's Sync Fields
	 * setting lists it: `featured_image` for `_thumbnail_id`, or the key
	 * itself for a custom meta key. Null for every other key.
	 *
	 * @param string $meta_key Meta key written.
	 * @return string|null
	 */
	private function configured_field_for_meta_key( string $meta_key ): ?string {
		$user_fields = (array) $this->settings->get( 'sync_fields', [] );

		if ( $meta_key === '_thumbnail_id' ) {
			return in_array( 'featured_image', $user_fields, true ) ? 'featured_image' : null;
		}

		if ( $meta_key === 'featured_image' || isset( self::POST_FIELD_MAP[ $meta_key ] ) ) {
			return null;
		}

		return in_array( $meta_key, $user_fields, true ) ? $meta_key : null;
	}

	/**
	 * Sync one field after a post-meta write that no post save covers.
	 *
	 * Only the featured image and the custom meta keys in the site's Sync
	 * Fields setting are followed, with full mirror semantics, exactly as a
	 * save of that post would write them. Seed-only keys are left to the
	 * next save. The sync's own writes to siblings, everything written
	 * while a translation is being created, the meta removed with a
	 * deleted post and writes made during a page view start nothing.
	 *
	 * @param int|int[]|mixed $meta_ids  Meta ID(s) written (unused).
	 * @param int|mixed       $object_id Post ID.
	 * @param string|mixed    $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta_write( mixed $meta_ids = 0, mixed $object_id = 0, mixed $meta_key = '' ): void {
		if ( self::$writing > 0 || ! is_string( $meta_key ) || ! is_numeric( $object_id ) || (int) $object_id <= 0 ) {
			return;
		}

		$field = $this->configured_field_for_meta_key( $meta_key );

		if ( $field === null || PostTranslationManager::is_creating() || self::is_page_view() ) {
			return;
		}

		$post_id = (int) $object_id;
		$key     = $this->state_key( $post_id );

		if ( isset( $this->deleting[ $key ] ) ) {
			return;
		}

		if ( isset( $this->open_saves[ $key ] ) ) {
			if ( ! isset( $this->deferred[ $key ] ) && count( $this->deferred ) >= self::DEFERRED_CAP ) {
				$this->run_deferred( (string) array_key_first( $this->deferred ) );
			}

			$this->deferred[ $key ][ $field ] = true;

			if ( ! $this->shutdown_hooked ) {
				add_action( 'shutdown', [ $this, 'flush_deferred' ] );
				$this->shutdown_hooked = true;
			}

			return;
		}

		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$this->run_sync( $post_id, $post, [ $field ] );
		}
	}

	/**
	 * Build the lock name for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function post_lock( int $post_id ): string {
		return 'contentsync_post_' . $post_id;
	}

	/**
	 * Build the lock name for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private function term_lock( int $term_id ): string {
		return 'contentsync_term_' . $term_id;
	}

	/**
	 * Per-post opt-out meta flag — the same key (and the same symmetric
	 * semantics) as WooCommerce's InventorySync: a flagged post is removed
	 * from the sync graph in BOTH directions. It neither receives mirror or
	 * seed writes nor pushes its own fields onto siblings, while the REST of
	 * the group keeps syncing among themselves. Set from the Translations
	 * metabox / Gutenberg panel checkbox (or the WooCommerce Advanced-tab
	 * checkbox on products, which writes the identical key).
	 */
	public const SYNC_OPTOUT_META = '_perflocale_sync_optout';

	/**
	 * Meta keys whose value a person deliberately emptied on this post, one
	 * row per key (the row's value is the key). Written by the Meta Box and
	 * ACF integrations and, for the keys given to track_seed_clears(), by the
	 * meta-API watcher, only while a person edits the post (see
	 * record_seed_clear()). Without it an emptied field reads as "never set": the
	 * next save of the default-language member seeds it again when its rows
	 * are gone, and machine translation fills it when it is empty. Consulted
	 * only when a seed is about to be written, so a sibling holding rows pays
	 * nothing. Machine translation honours it too.
	 */
	public const SEED_CLEARED_META = '_perflocale_seed_cleared';

	/**
	 * Whether a post is opted out of cross-language content sync.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_sync_opted_out( int $post_id ): bool {
		return get_post_meta( $post_id, self::SYNC_OPTOUT_META, true ) === 'yes';
	}

	/**
	 * Whether a post is the DEFAULT-language member of its translation group.
	 *
	 * Hierarchy is authored in the default language and mirrored outward:
	 * `post_parent` may only travel from the group's default-language member
	 * to its translations, never back. A translation is created parentless
	 * whenever its parent has no translation yet, so letting one push its own
	 * `post_parent` up the group would move the published SOURCE to the site
	 * root on the translation's first save - silently changing the source's
	 * live URL. Nothing records a redirect for a re-parented post (the
	 * slug-redirect map holds renamed LANGUAGE slugs only), so every inbound
	 * link and search result for the old URL dies. The term side takes the
	 * same decision in sync_on_term_edit(). Seed-only meta keys travel the
	 * same one way.
	 *
	 * @param int                $post_id      Post being saved.
	 * @param array<string, int> $translations language_slug => post_id map for the group.
	 * @return bool True when the post is the group's default-language member,
	 *              or when no default language is configured - on a
	 *              half-set-up site there is nothing to compare against, so
	 *              the previous behaviour is kept rather than silently
	 *              freezing the feature.
	 */
	private function is_default_language_post( int $post_id, array $translations ): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'lang_repo' ) ) {
			return true;
		}

		$default      = $plugin->lang_repo()->get_default();
		$default_slug = ( $default && isset( $default->slug ) && is_string( $default->slug ) ) ? $default->slug : '';

		if ( $default_slug === '' ) {
			return true;
		}

		// A group whose default-language member is missing entirely (source
		// hard-deleted, translations still linked) has no authority to copy
		// hierarchy from, so nothing moves - the same conclusion as "this
		// post is a translation".
		return ( $translations[ $default_slug ] ?? 0 ) === $post_id;
	}

	/**
	 * Synchronize configured fields when a post is saved.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object; null when core re-read the row after
	 *                               the write and found it gone, or when a third
	 *                               party re-fires save_post with one argument.
	 * @return void
	 */
	public function sync_on_save( int $post_id, ?\WP_Post $post = null ): void {
		// WordPress re-reads the row after the write and hands the hook
		// whatever it got, which is null when the post was deleted in the
		// interim; some plugins also fire save_post with one argument. A
		// non-nullable hint turned either into an uncaught TypeError.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// This save syncs every field as it stands now, so meta writes that
		// were waiting for it are covered.
		unset( $this->deferred[ $this->state_key( $post_id ) ] );

		$this->run_sync( $post_id, $post, null );
	}

	/**
	 * Sync a post's fields to the other members of its translation group.
	 *
	 * @param int                     $post_id Post ID.
	 * @param \WP_Post                $post    Post object.
	 * @param array<int, string>|null $only    Null for a save (every synced field).
	 *                                         Otherwise the sync fields a meta write
	 *                                         changed; see sync_written_fields().
	 * @return void
	 */
	private function run_sync( int $post_id, \WP_Post $post, ?array $only ): void {
		// The service is registered on every blog so a request that switches
		// blogs syncs there; a blog with an empty Sync Fields list syncs
		// nothing.
		if ( empty( (array) $this->settings->get( 'sync_fields', [] ) ) ) {
			return;
		}

		// Skip revisions and autosaves before acquiring any lock - avoids
		// churning the options table on every autosave tick.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// ⚠️ Templates are not content. sync_fields (post_parent, menu_order,
		// featured image) are meaningless for them, and this fires on EVERY
		// Site Editor save — acquiring two locks and walking the group — which
		// is pure overhead on the hottest editing path in a block theme.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post->post_type ) ) {
			return;
		}

		// Check if post type is translatable.
		$translatable = $this->settings->get_translatable_post_types();

		if ( ! in_array( $post->post_type, $translatable, true ) ) {
			return;
		}

		// A meta write on a post with no translations has nothing to reach;
		// answer that from the cached group before taking any lock.
		if ( $only !== null && count( $this->manager->get_translations( $post_id ) ) < 2 ) {
			return;
		}

		// Symmetric opt-out: a flagged post pushes nothing. The mirror is
		// bidirectional (any group member's save propagates group-wide), so
		// a one-sided target check would let a deliberately diverged
		// translation clobber its siblings on its own next save.
		if ( $this->is_sync_opted_out( $post_id ) ) {
			return;
		}

		// Atomically acquire the source lock - concurrent admin + cron
		// writes for the same post can't both enter the critical section.
		if ( ! Lock::acquire( $this->post_lock( $post_id ), self::LOCK_TTL ) ) {
			return;
		}

		try {
			$user_fields = (array) $this->settings->get( 'sync_fields', [] );

			// Merge addon-contributed meta keys so builder layouts
			// (Elementor/Bricks/Oxygen/etc.) propagate across siblings
			// without the user having to add each key manually in settings.
			$addon_keys  = $this->settings->get_translatable_meta_keys( $post->post_type );
			$sync_fields = array_values( array_unique( array_merge( $user_fields, $addon_keys ) ) );

			/** @hook perflocale/sync_fields Filter fields synced across translations. Includes built-in fields and custom meta keys. */
			$sync_fields = (array) apply_filters( 'perflocale/sync_fields', $sync_fields, $post->post_type );

			if ( $only !== null ) {
				$this->sync_written_fields( $post_id, $post, $only, $sync_fields, $user_fields, $addon_keys );
				return;
			}

			$before_for_post = $sync_fields;

			/**
			 * Expand per-post dynamic meta keys the post-type-scoped list above
			 * can't enumerate — e.g. ACF repeater / flexible-content rows, whose
			 * key set depends on each post's actual row count (the static field
			 * list only knows row 0). Addons receive the SOURCE post id so they
			 * can read its real row structure and add the remaining rows' keys.
			 *
			 * @hook perflocale/sync_fields/for_post
			 * @param array<int, string> $sync_fields Meta keys to sync.
			 * @param int                $post_id     Source post id.
			 */
			$sync_fields = (array) apply_filters( 'perflocale/sync_fields/for_post', $sync_fields, (int) $post_id );

			if ( empty( $sync_fields ) ) {
				return;
			}

			/**
			 * Meta keys that keep FULL MIRROR semantics: every source save
			 * overwrites the sibling's rows, and a delete on the source clears
			 * the siblings. Defaults to the user-configured `sync_fields` list.
			 *
			 * ⚠️ NOTHING THAT CARRIES TRANSLATABLE TEXT BELONGS ON THIS LIST.
			 * The mirror below is BIDIRECTIONAL — any group member's save
			 * propagates to the rest — so a key holding words is destroyed in
			 * both directions: the translator saves and overwrites the source,
			 * then the next source save overwrites the translation.
			 *
			 * Page-builder layout documents hold their text inside them, so they
			 * are seed-only; only text-free builder keys (Beaver's
			 * `_fl_builder_enabled`, Oxygen's `_ct_page_settings` and its
			 * legacy `ct_page_settings`) mirror.
			 * Pinned by builder-layout-ownership.php.
			 *
			 * Every other addon-contributed "translatable" key (SEO titles/
			 * descriptions, ACF/Meta Box/Pods field values, WooCommerce
			 * purchase notes) is SEED-ONLY: copied to a sibling that does not
			 * have the key yet, then owned by the sibling's translator — a
			 * source save never overwrites or deletes a per-language value the
			 * translator typed on the translation.
			 *
			 * @hook  perflocale/sync/mirror_meta_keys
			 * @since 1.0.0
			 *
			 * @param array<int, string> $mirror_keys Keys with full mirror semantics.
			 * @param string             $post_type   Post type being synced.
			 */
			$mirror_keys = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', $user_fields, $post->post_type );

			// Seed-only = addon translatable keys + per-post expansions (ACF
			// repeater rows derive from translatable parents), minus anything
			// explicitly declared mirror. Keys added via the public
			// `perflocale/sync_fields` filter stay mirror (documented as
			// "fields synced across translations").
			$seed_only = array_values(
				array_diff(
					array_unique( array_merge( $addon_keys, array_diff( $sync_fields, $before_for_post ) ) ),
					$mirror_keys
				)
			);

			$translations = $this->manager->get_translations( $post_id );

			if ( count( $translations ) < 2 ) {
				return;
			}

			// Seed-only keys whose name marks a credential never travel, by the
			// same patterns that keep them out of a new translation's copy. They
			// leave the synced set entirely: dropped from $seed_only alone they
			// would fall through to the bidirectional mirror. Mirror keys are
			// the site's explicit choice and are not gated.
			if ( $seed_only !== [] ) {
				$patterns = PostTranslationManager::sensitive_meta_patterns();
				$denied   = [];

				foreach ( $seed_only as $seed_key ) {
					if ( PostTranslationManager::is_sensitive_meta_key( (string) $seed_key, $patterns ) ) {
						$denied[ $seed_key ] = true;
					}
				}

				if ( $denied !== [] ) {
					$allowed = static function ( mixed $key ) use ( $denied ): bool {
						return ! ( ( is_string( $key ) || is_int( $key ) ) && isset( $denied[ $key ] ) );
					};

					$seed_only   = array_values( array_filter( $seed_only, $allowed ) );
					$sync_fields = array_values( array_filter( $sync_fields, $allowed ) );
				}
			}

			// Resolved once per save (not once per sibling) and honoured by
			// the post_parent and seed-only branches of sync_fields_to_post();
			// every other synced field is bidirectional.
			$is_default_source = $this->is_default_language_post( $post_id, $translations );

			$this->sync_siblings( $post_id, $translations, $sync_fields, $is_default_source, $seed_only );
		} finally {
			Lock::release( $this->post_lock( $post_id ) );
		}
	}

	/**
	 * Sync the fields a meta write changed: only those that mirror for this
	 * post type are written, and nothing is seeded (seed-only keys wait for
	 * the next save). Runs under the saved post's lock.
	 *
	 * @param int                $post_id     Post ID.
	 * @param \WP_Post           $post        Post object.
	 * @param array<int, string> $only        Sync fields the write changed.
	 * @param array<mixed>       $sync_fields Synced fields for this post type.
	 * @param array<mixed>       $user_fields The site's Sync Fields setting.
	 * @param array<int, string> $addon_keys  Addon-registered translatable keys.
	 * @return void
	 */
	private function sync_written_fields( int $post_id, \WP_Post $post, array $only, array $sync_fields, array $user_fields, array $addon_keys ): void {
		/** This filter is documented in src/Translation/ContentSync.php */
		$mirror_keys = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', $user_fields, $post->post_type );
		$written     = [];

		foreach ( $only as $field ) {
			$is_seed = in_array( $field, $addon_keys, true ) && ! in_array( $field, $mirror_keys, true );

			if ( in_array( $field, $sync_fields, true ) && ! $is_seed ) {
				$written[] = $field;
			}
		}

		if ( $written === [] ) {
			return;
		}

		$translations = $this->manager->get_translations( $post_id );

		if ( count( $translations ) < 2 ) {
			return;
		}

		$this->sync_siblings( $post_id, $translations, $written, $this->is_default_language_post( $post_id, $translations ), [] );
	}

	/**
	 * Write the synced fields to every other member of the group.
	 *
	 * @param int                $post_id           Post whose values are copied.
	 * @param array<string, int> $translations      language_slug => post_id map for the group.
	 * @param array<string>      $fields            Fields to sync.
	 * @param bool               $is_default_source Whether $post_id is the group's default-language member.
	 * @param array<string>      $seed_only         Seed-only meta keys among $fields.
	 * @return void
	 */
	private function sync_siblings( int $post_id, array $translations, array $fields, bool $is_default_source, array $seed_only ): void {
		// ⚠️ Sync fields are one value shared by the whole group, Page
		// Parent and seed-only keys aside: a save of any member writes them
		// to every other member, so the right to set them is the right to
		// edit the post being saved. Page Parent moves only from the
		// default-language member, mapped to each sibling's own-language
		// parent, and seed-only keys are copied only from the
		// default-language member, and only to a sibling that lacks them.
		// There is deliberately no per-sibling edit check for any of them.
		// The mirror copies the saved post's current values, not only what
		// changed, so a sibling skipped here would keep the old value and
		// its own next save would copy that back over the member just
		// changed; a scheduled publish or a WP-CLI save may also have no
		// user to check. The sync opt-out keeps a post out of the group,
		// and setting it needs edit_post on that post.
		foreach ( $translations as $lang_slug => $translated_id ) {
			if ( $translated_id === $post_id || $this->is_sync_opted_out( $translated_id ) ) {
				continue;
			}

			// If the sibling is locked by another request, skip - the
			// other request will write the same data. Race-free.
			if ( ! Lock::acquire( $this->post_lock( $translated_id ), self::LOCK_TTL ) ) {
				continue;
			}

			try {
				++self::$writing;
				$this->sync_fields_to_post( $post_id, $translated_id, $fields, $lang_slug, $is_default_source, $seed_only );

				// Free per-post object caches accumulated during the inner
				// sync so bulk runs (WP-CLI, cron) don't accumulate memory.
				clean_post_cache( $translated_id );
			} finally {
				--self::$writing;
				Lock::release( $this->post_lock( $translated_id ) );
			}
		}
	}

	/**
	 * Synchronize configured term fields when a taxonomy term is edited.
	 *
	 * Keeps the PARENT in sync across linked translations (mapped to each
	 * language's own translated parent) so hierarchies don't diverge silently.
	 * One-way: only an edit of the group's DEFAULT-language term propagates,
	 * because the hierarchy is authored there - see the guard below.
	 * Name, slug, and description are translator-owned and never synced.
	 *
	 * @param int    $term_id Edited term ID.
	 * @param int    $tt_id Term-taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function sync_on_term_edit( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( empty( (array) $this->settings->get( 'sync_fields', [] ) ) ) {
			return;
		}

		$translatable = $this->settings->get_translatable_taxonomies();

		if ( ! in_array( $taxonomy, $translatable, true ) ) {
			return;
		}

		// Sites with deliberately different category trees per language (a
		// smaller flattened catalog in one market) can switch hierarchy sync
		// off entirely; the per-sibling filter below vetoes selectively.
		if ( ! (bool) $this->settings->get( 'sync_term_hierarchy', true ) ) {
			return;
		}

		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return;
		}

		if ( ! Lock::acquire( $this->term_lock( $term_id ), self::LOCK_TTL ) ) {
			return;
		}

		try {
			$source_term = get_term( $term_id, $taxonomy );

			if ( ! $source_term instanceof \WP_Term ) {
				return;
			}

			$repo  = new \PerfLocale\Database\Repository\TranslationGroupRepository( $plugin->get( 'cache' ) );
			$links = $repo->get_translations( $term_id, \PerfLocale\Enum\ObjectType::Term );

			if ( count( $links ) < 2 ) {
				return;
			}

			// Hierarchy travels one way: default language -> translations. A
			// fresh term translation sits at parent 0 whenever the source's
			// parent has no translation yet, and the bulk term pass
			// (Bootstrap::ajax_create_taxonomy_translations) renames it with
			// wp_update_term() in the SAME request as create_translation() -
			// so without this guard translating one category writes that 0
			// back onto the source-language term, flattening the tree the
			// site is authored in and changing its live permalink. Sites that
			// genuinely want per-language trees still have the
			// `perflocale/sync/term_parent` filter and the
			// `sync_term_hierarchy` setting checked above.
			$default_lang = $plugin->has( 'lang_repo' ) ? $plugin->lang_repo()->get_default() : null;
			$default_id   = ( $default_lang && isset( $default_lang->id ) && is_numeric( $default_lang->id ) ) ? (int) $default_lang->id : 0;

			// The group's member in the default language - $term_id itself when
			// the edit came from the source language, and null when the group
			// has no default-language member left to copy hierarchy from.
			if ( $default_id > 0
				&& $repo->get_translation_in_language( $term_id, \PerfLocale\Enum\ObjectType::Term, $default_id ) !== $term_id ) {
				return;
			}

			foreach ( $links as $link ) {
				$sibling_id = (int) $link->object_id;

				if ( $sibling_id === $term_id ) {
					continue;
				}

				$sibling = get_term( $sibling_id, $taxonomy );

				if ( ! $sibling instanceof \WP_Term ) {
					continue;
				}

				if ( ! Lock::acquire( $this->term_lock( $sibling_id ), self::LOCK_TTL ) ) {
					continue;
				}

				try {
					// Only sync parent (description/name stay translator-owned).
					// Point the sibling at the parent's translation in the
					// sibling's OWN language (a DE child → the DE parent, not the
					// EN parent's term_id, which would orphan it cross-language).
					// If the parent isn't translated yet, leave it unchanged.
					$source_parent_id  = (int) $source_term->parent;
					$translated_parent = 0;
					$update_parent     = true;

					if ( $source_parent_id > 0 ) {
						$sibling_lang_id = (int) $link->language_id;
						$parent_siblings = $repo->get_translations( $source_parent_id, \PerfLocale\Enum\ObjectType::Term );

						foreach ( $parent_siblings as $ps ) {
							if ( (int) $ps->language_id === $sibling_lang_id ) {
								$translated_parent = (int) $ps->object_id;
								break;
							}
						}

						// Parent has no translation in the sibling's language.
						// Skip the parent update entirely so we don't orphan
						// it with a cross-language reference. The finally
						// below still releases the sibling lock cleanly.
						if ( $translated_parent === 0 ) {
							$update_parent = false;
						}
					}

					/**
					 * Whether to sync this term's parent onto a specific
					 * translation sibling. Return false to keep the sibling's
					 * own hierarchy (per-language category trees) while name,
					 * slug, and description stay translator-owned as always.
					 *
					 * @hook perflocale/sync/term_parent
					 * @param bool     $sync              Default true (mirror the hierarchy).
					 * @param \WP_Term $source_term       The edited term.
					 * @param int      $sibling_id        Sibling term ID about to be updated.
					 * @param string   $taxonomy          Taxonomy slug.
					 * @param int      $translated_parent Parent mapped into the sibling's language (0 = top level).
					 */
					if ( $update_parent && ! (bool) apply_filters( 'perflocale/sync/term_parent', true, $source_term, $sibling_id, $taxonomy, $translated_parent ) ) {
						$update_parent = false;
					}

					// Change-only: an unchanged parent needs no wp_update_term
					// (which would churn term caches on every routine edit).
					if ( $update_parent && (int) $sibling->parent === $translated_parent ) {
						$update_parent = false;
					}

					if ( $update_parent ) {
						$parent_result = wp_update_term(
							$sibling_id,
							$taxonomy,
							[
								'parent' => $translated_parent,
							]
						);

						if ( is_wp_error( $parent_result ) ) {
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; one line per failed sibling parent sync.
								error_log( sprintf( 'PerfLocale ContentSync: wp_update_term parent sync failed for term %d: %s', $sibling_id, $parent_result->get_error_message() ) );
							}
						} else {
							clean_term_cache( $sibling_id, $taxonomy );
						}
					}
				} finally {
					Lock::release( $this->term_lock( $sibling_id ) );
				}
			}
		} finally {
			Lock::release( $this->term_lock( $term_id ) );
		}
	}

	/**
	 * Sync specific fields from source to target post.
	 *
	 * @param int           $source_id Source post ID.
	 * @param int           $target_id Target post ID.
	 * @param array<string> $fields Fields to sync.
	 * @param string        $target_lang_slug Target post's language slug — used to
	 *            translate post_parent into the same language so the sibling
	 *            doesn't end up with a cross-language parent reference.
	 * @param bool          $source_is_default_lang Whether the saved post is its
	 *            group's default-language member. Only that member may move the
	 *            group's hierarchy or seed seed-only keys - see
	 *            is_default_language_post().
	 * @param array<string> $seed_only Meta keys with seed-only semantics: copied
	 *            from the default-language member when the sibling has NO rows
	 *            for the key and has not recorded it as deliberately cleared
	 *            (SEED_CLEARED_META), otherwise left untouched (never
	 *            overwritten, never deleted) — the sibling's translator owns the
	 *            per-language value.
	 * @return void
	 */
	private function sync_fields_to_post( int $source_id, int $target_id, array $fields, string $target_lang_slug, bool $source_is_default_lang, array $seed_only = [] ): void {
		// Post fields that can be batched into a single wp_update_post() call.
		$post_field_map = self::POST_FIELD_MAP;

		// Collect post-level updates, fetch source post only once if needed.
		$identical_fields_dropped = false;
		$update                   = [ 'ID' => $target_id ];
		$needs_source             = false;
		$has_meta                 = false;
		$has_thumb                = false;

		foreach ( $fields as $field ) {
			if ( $field === 'featured_image' ) {
				$has_thumb = true;
			} elseif ( isset( $post_field_map[ $field ] ) ) {
				$needs_source = true;
			} else {
				$has_meta = true;
			}
		}

		$source = $needs_source ? get_post( $source_id ) : null;

		// Batch all post-field syncs into one wp_update_post() call.
		if ( $source ) {
			foreach ( $fields as $field ) {
				if ( ! isset( $post_field_map[ $field ] ) ) {
					continue;
				}

				$prop = $post_field_map[ $field ];

				// post_parent points at the parent's translation in the
				// sibling's OWN language (a DE child → the DE parent, not the
				// EN parent's post_id, which would orphan it cross-language).
				// If the parent isn't translated yet, leave it unchanged.
				// Mirrors the term-side guard in sync_on_term_edit, including
				// its default-language-only direction.
				if ( $field === 'post_parent' ) {
					// Only the default-language member may move the group. A
					// translation saving its own parent - 0 whenever its parent
					// has no translation yet - would otherwise flatten every
					// sibling, the published source included. Skip just this
					// field so the sibling's other synced fields still travel.
					if ( ! $source_is_default_lang ) {
						continue;
					}

					$source_parent = (int) $source->post_parent;

					if ( $source_parent === 0 ) {
						$update[ $prop ] = 0;
						continue;
					}

					$parent_translations = $this->manager->get_translations( $source_parent );
					$translated_parent   = $parent_translations[ $target_lang_slug ] ?? 0;

					if ( $translated_parent === 0 ) {
						continue; // Skip post_parent for this sibling, keep other fields.
					}

					$update[ $prop ] = $translated_parent;
					continue;
				}

				$update[ $prop ] = $source->$prop;

				// post_date needs post_date_gmt as well, plus edit_date=true:
				// wp_update_post() ignores an explicit date on an existing post
				// unless edit_date is set, so without it the sibling silently
				// keeps its own publish date.
				if ( $field === 'post_date' ) {
					$update['post_date_gmt'] = $source->post_date_gmt;
					$update['edit_date']     = true;
				}
			}

			// Drop every field the sibling already holds. wp_update_post()
			// rewrites the row and fires save_post / post_updated /
			// transition_post_status even when all values are identical, so a
			// source save that touched none of the synced fields still cost one
			// full post write per translation. $current is read under the same
			// sibling lock the write is made under, so it is the row
			// wp_update_post() goes on to merge into.
			//
			// This decides only WHETHER to write. wp_insert_post() still
			// reconciles the columns it owns on any write that does happen.
			if ( count( $update ) > 1 ) {
				$current = get_post( $target_id );

				if ( $current instanceof \WP_Post ) {
					// Numeric columns compare as integers (post_author is a numeric
					// string on WP_Post), the rest as strings.
					$sibling_now = [
						'menu_order'     => (int) $current->menu_order,
						'post_author'    => (int) $current->post_author,
						'post_parent'    => (int) $current->post_parent,
						'comment_status' => (string) $current->comment_status,
						'ping_status'    => (string) $current->ping_status,
					];

					// Fields wp_insert_post() replaces when the incoming value is
					// empty: comment_status becomes 'closed' on an update,
					// ping_status the post type's default, post_author the current
					// user. An empty value is therefore never "already held" - the
					// row would end up holding what core substitutes, not what we
					// compared. menu_order and post_parent are plain int casts and
					// need no such exception, so a legitimate 0 still compares.
					$normalised_when_empty = [
						'post_author'    => true,
						'comment_status' => true,
						'ping_status'    => true,
					];

					foreach ( $sibling_now as $key => $sibling_value ) {
						if ( ! array_key_exists( $key, $update )
							|| ( isset( $normalised_when_empty[ $key ] ) && empty( $update[ $key ] ) ) ) {
							continue;
						}

						$incoming = is_int( $sibling_value ) ? (int) $update[ $key ] : (string) $update[ $key ];

						if ( $incoming === $sibling_value ) {
							unset( $update[ $key ] );
							$identical_fields_dropped = true;
						}
					}

					// post_date, post_date_gmt and edit_date are one package, not
					// three values. edit_date is a control flag: without it
					// wp_update_post() sets $clear_date on a sibling that is a
					// draft/pending/auto-draft with a zero post_date_gmt and
					// rewrites its post_date to the current time
					// (wp-includes/post.php). The trio may only be dropped when
					// doing so leaves nothing to write at all; any write that still
					// happens carries all three exactly as built above.
					$other_fields = array_diff_key(
						$update,
						[
							'ID'            => true,
							'post_date'     => true,
							'post_date_gmt' => true,
							'edit_date'     => true,
						]
					);

					if ( $other_fields === []
						&& isset( $update['post_date'] )
						&& (string) $update['post_date'] === (string) $current->post_date
						&& (string) ( $update['post_date_gmt'] ?? '' ) === (string) $current->post_date_gmt ) {
						unset( $update['post_date'], $update['post_date_gmt'], $update['edit_date'] );
						$identical_fields_dropped = true;
					}
				}
			}

			// Only call wp_update_post if there are actual fields to update.
			// The sibling's other columns stay as stored; an empty
			// page_template leaves its stored template alone. A write that
			// carries post_parent carries post_name too: core makes the slug
			// unique among the new parent's children.
			if ( count( $update ) > 1 ) {
				$sibling = get_post( $target_id );
				$writes  = array_keys( $update );

				if ( isset( $update['post_parent'] ) ) {
					$writes[] = 'post_name';
				}

				$unpin = $sibling instanceof \WP_Post ? self::keep_columns( $sibling, $writes ) : null;

				$update['page_template'] = '';

				try {
					$result = wp_update_post( $update, true );
				} finally {
					if ( null !== $unpin ) {
						$unpin();
					}
				}

				// A filter vetoing wp_update_post (e.g. WooCommerce stock or
				// order-status guards) must not block the independent thumbnail
				// and meta syncs below - those use their own APIs and have
				// nothing to do with the post-field update path.
				if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; one line per vetoed sibling update.
					error_log( sprintf( 'PerfLocale ContentSync: wp_update_post failed for sibling %d: %s', $target_id, $result->get_error_message() ) );
				}
			} elseif ( $identical_fields_dropped ) {
				// The row already held every value, so no write and therefore
				// no save_post -> CacheInvalidator::on_save_post for this
				// sibling. Flush its object caches here instead: integrations
				// purge their CDN from `perflocale/cache/flush_object`, and
				// that signal has to keep arriving for a sibling whenever the
				// write it used to ride on would have happened.
				$this->cache->flush_object( $target_id, 'post' );
			}
		}

		// Featured image (handled separately - uses set_post_thumbnail API).
		// A sibling whose language has its own copy of the image (media
		// duplicated per language by a migrated plugin) gets that copy.
		if ( $has_thumb ) {
			$thumbnail_id = get_post_thumbnail_id( $source_id );
			if ( $thumbnail_id ) {
				set_post_thumbnail( $target_id, $this->manager->attachment_in_language( (int) $thumbnail_id, $target_lang_slug ) );
			} else {
				delete_post_thumbnail( $target_id );
			}
		}

		// Meta field syncs.
		$mirrored_meta_keys = [];

		if ( $has_meta ) {
			// A set, so the per-field membership test below stays constant-time
			// on posts with thousands of expanded repeater keys.
			$seed_set = array_fill_keys( $seed_only, true );
			$cleared  = null;

			foreach ( $fields as $field ) {
				if ( $field === 'featured_image' || isset( $post_field_map[ $field ] ) ) {
					continue;
				}

				// SEED-ONLY keys (SEO titles, ACF/Meta Box/Pods values, WC purchase
				// notes): the sibling's translator owns the per-language value.
				// Copy only when the sibling has NO rows for the key (a fresh
				// translation) and never delete - a source save must not clobber
				// a value typed on the translation.
				if ( isset( $seed_set[ $field ] ) ) {
					// Seeds flow outward from the default-language member only,
					// like Page Parent. A value first typed on a translation is in
					// that translation's language, and must not be copied onto the
					// original or into the other languages.
					if ( ! $source_is_default_lang ) {
						continue;
					}

					if ( get_post_meta( $target_id, $field, false ) !== [] ) {
						continue;
					}

					$seed_values = get_post_meta( $source_id, $field, false );

					if ( $seed_values === [] || ( count( $seed_values ) === 1 && $seed_values[0] === '' ) ) {
						continue;
					}

					// No rows because the sibling's owner emptied the field, not
					// because it was never set: it stays empty. The read above
					// primed the sibling's meta cache, so this costs no query.
					$cleared ??= self::seed_cleared_keys( $target_id );

					if ( isset( $cleared[ $field ] ) ) {
						continue;
					}

					foreach ( $seed_values as $meta_value ) {
						/**
						 * Filters one seeded row before it is copied to a sibling
						 * that has no rows for the key. Runs only when a seed is
						 * written. The Meta Box add-on takes a group's password
						 * sub-fields out here, as it does when a translation is
						 * created.
						 *
						 * @hook  perflocale/sync/seed_meta_value
						 * @since 1.0.7
						 *
						 * @param mixed  $meta_value Row as read from the source, unslashed.
						 * @param string $field      Meta key.
						 * @param int    $target_id  Sibling that receives the row.
						 * @param int    $source_id  Default-language member the row comes from.
						 */
						$meta_value = apply_filters( 'perflocale/sync/seed_meta_value', $meta_value, $field, $target_id, $source_id );
						add_post_meta( $target_id, $field, \PerfLocale\Helper::deep_slash( $meta_value ) );
					}

					continue;
				}

				// MIRROR keys keep full overwrite + delete-clears semantics:
				// Replicate EVERY row, not just the first — multi-value meta
				// (add_post_meta(..., false)) was being collapsed to a single
				// value on the sibling, permanently dropping the rest. Mirror
				// copy_post_meta(): clear the target, then re-add each source
				// row. An absent or single empty-string source clears the
				// target (preserving the prior single-value behaviour).
				$values = get_post_meta( $source_id, $field, false );

				// Change detection: skip the delete/re-add (and the
				// after_mirror cache purge this key would trigger) when the
				// sibling already holds identical rows — a title typo fix
				// must not rewrite multi-hundred-KB builder JSON on every
				// sibling and throw away their compiled CSS. Compare
				// SERIALIZED forms: get_post_meta() unserializes, and builder
				// meta can contain objects (Beaver Builder nodes) where a
				// strict array compare would test instance identity and never
				// match.
				$target_values = get_post_meta( $target_id, $field, false );

				if ( array_map( 'maybe_serialize', $target_values ) === array_map( 'maybe_serialize', $values )
					&& ! ( $target_values !== [] && ( $values === [] || ( count( $values ) === 1 && $values[0] === '' ) ) ) ) {
					continue;
				}

				$mirrored_meta_keys[] = $field;

				if ( $values === [] || ( count( $values ) === 1 && $values[0] === '' ) ) {
					delete_post_meta( $target_id, $field );
				} else {
					delete_post_meta( $target_id, $field );
					foreach ( $values as $meta_value ) {
						// wp_slash counteracts add_post_meta()'s internal wp_unslash(); the value came
							// unslashed from get_post_meta(), so without it backslash-bearing builder
							// JSON (_elementor_data, Bricks/Oxygen/Beaver) is corrupted on the sibling.
							add_post_meta( $target_id, $field, \PerfLocale\Helper::deep_slash( $meta_value ) );
					}
				}
			}
		}

		// A raw meta mirror overwrites a builder layout key (Elementor/Bricks/
		// Oxygen/Beaver) but leaves the sibling's GENERATED CSS/asset caches —
		// keyed to the pre-sync layout — untouched, so the translated page keeps
		// enqueueing a stylesheet built from the old layout until a manual editor
		// save or a site-wide regenerate. Signal the mirror so a builder addon
		// can drop the sibling's stale generated cache.
		if ( $mirrored_meta_keys !== [] ) {
			/**
			 * Fires after full-mirror meta keys have been written to a sibling
			 * translation. Builder addons hook this to invalidate the sibling's
			 * generated CSS/asset caches (e.g. Elementor's `_elementor_css` /
			 * `_elementor_page_assets` meta) that a raw meta mirror can't touch.
			 *
			 * @hook  perflocale/sync/after_mirror
			 * @since 1.0.0
			 *
			 * @param int                $source_id   Source post ID.
			 * @param int                $target_id   Sibling (target) post ID.
			 * @param array<int, string> $mirror_keys Mirror meta keys just written to the sibling.
			 */
			do_action( 'perflocale/sync/after_mirror', $source_id, $target_id, $mirrored_meta_keys );
		}
	}
}
