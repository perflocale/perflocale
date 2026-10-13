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
	 * Siblings a save in this request did not write because the saver may
	 * not edit them, and that the write would have given a value they do not
	 * hold ("{blog_id}:{post_id}" of the saved post => sibling id => true);
	 * see sync_siblings() and skipped_siblings().
	 *
	 * @var array<string, array<int, true>>
	 */
	private static array $skipped = [];

	/**
	 * Siblings skipped by a save that is not complete yet, compared once it
	 * is ("{blog_id}:{post_id}" of the saved post => the instance, the
	 * comparison's arguments and sibling id => language slug). A save
	 * through the REST API writes the featured image and meta after
	 * save_post, so the saved post's values are final only then. See
	 * note_skip() and run_skip_check().
	 *
	 * @var array<string, array{sync: self, fields: array<string>, default: bool, seed: array<string>, siblings: array<int, string>, mark: array<int, true>, saved: array<int, true>}>
	 */
	private static array $skip_checks = [];

	/**
	 * Translations the saves of a post-list bulk action in this request (a
	 * Bulk Edit) did not write although a value would have changed there
	 * ("{blog_id}:{sibling_id}" => true, each counted once however many
	 * edited posts share it); null when no bulk action ran. Its size is
	 * bounded by the posts of one list screen and their translations. See
	 * bulk_skipped().
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $bulk_skipped = null;

	/**
	 * Scheduled posts WordPress is publishing in this request without a
	 * person ("{blog_id}:{post_id}" => the author id, and the user whose
	 * rights gate the publish once resolved, 0 for none), from
	 * `future_to_publish` to the end of that save. See
	 * note_scheduled_publish() and scheduled_publish_user().
	 *
	 * @var array<string, array{author: int, user: int|null}>
	 */
	private static array $scheduled_publishes = [];

	/**
	 * Posts whose save in this request syncs once it is complete, under the
	 * rule of a save that clears a stale mark ("{blog_id}:{post_id}" =>
	 * true): a person's save that clears the post's mark, or a block editor
	 * meta-box request whose form names a cleared mark; until that save is
	 * complete and its sync has run (close_save(), sync_cleared()).
	 *
	 * @var array<string, true>
	 */
	private static array $clearing = [];

	/**
	 * The stale mark a person's save in this request clears, as it read at
	 * the save's `save_post` ("{blog_id}:{post_id}" => mark), until that
	 * save's sync has run. That sync clears it (settle_stale_mark()) and
	 * names it in the held-values row it writes (held_mark_id()).
	 *
	 * @var array<string, array{status: string, at: string, pending: list<string>}>
	 */
	private static array $cleared_marks = [];

	/**
	 * Posts whose stale mark a person's save in this request clears and
	 * whose content that same save changed while the mark still held its
	 * "needs update" (keeps_needs_update()), "{blog_id}:{post_id}" => true,
	 * until that save's sync has run. The changed content then settles the
	 * link once the mark is cleared (settle_stale_mark()).
	 *
	 * @var array<string, true>
	 */
	private static array $settled_content = [];

	/**
	 * Held values a block editor meta-box request names
	 * ("{blog_id}:{post_id}" => field => hash of the value, as in
	 * SYNC_HELD_META), until that save's sync has run (held_form_save(),
	 * sync_cleared()).
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $form_held = [];

	/**
	 * Post columns of the Sync Fields as they were before a person's save of
	 * the post changed its row ("{blog_id}:{post_id}" => column => value),
	 * from `post_updated`, until the save is complete.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $person_rows = [];

	/**
	 * Meta keys a person's save of a post wrote before it was complete
	 * ("{blog_id}:{post_id}" => meta key => the key's rows before the first
	 * write, read from the filled meta cache; true when the rows could not be
	 * read without a query and a write changed the key; false while such a
	 * write is not confirmed). False for a post whose filled meta cache shows
	 * no stale mark: nothing more is noted for it. See
	 * note_person_write_before().
	 *
	 * @var array<string, array<string, array<int|string, mixed>|bool>|false>
	 */
	private static array $person_writes = [];

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
		add_filter( 'rest_request_after_callbacks', [ $this, 'add_skipped_to_rest_response' ], 10, 3 );
		add_action( 'wp_after_insert_post', [ $this, 'close_save' ], 20, 2 );

		// The row of a post a person is saving, as it was before the save.
		add_action( 'post_updated', [ $this, 'note_person_row' ], 10, 3 );

		// The block editor's meta-box form of a translation marked stale
		// names the mark, for the meta-box requests sent after its saves.
		add_action( 'block_editor_meta_box_hidden_fields', [ $this, 'render_held_field' ], 10, 1 );

		// A scheduled post that goes live without a person publishing it
		// syncs with its author's rights until its save is complete.
		add_action( 'future_to_publish', [ $this, 'note_scheduled_publish' ], 10, 1 );
		add_action( 'wp_after_insert_post', [ $this, 'forget_scheduled_publish' ], PHP_INT_MAX, 1 );

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
	 * translation leave a value alone. A write on a post a person is editing
	 * is also noted (note_person_write_before()).
	 *
	 * @param int|mixed    $object_id  Post ID.
	 * @param string|mixed $meta_key   Meta key.
	 * @param mixed        $meta_value Value being added.
	 * @return void
	 */
	public function note_meta_add( mixed $object_id = 0, mixed $meta_key = '', mixed $meta_value = null ): void {
		self::note_person_write_before( $object_id, $meta_key );

		$post_id = $this->tracked_write( $object_id, $meta_key );

		if ( $post_id > 0 && ! self::is_empty_meta_value( $meta_value ) && false !== wp_cache_get( $post_id, 'post_meta' ) ) {
			self::record_seed_clear( $post_id, (string) $meta_key, false );
		}
	}

	/**
	 * `update_post_meta`: a tracked key saved empty after holding a value is
	 * cleared; saved with a value, it is not. A write on a post a person is
	 * editing is also noted (note_person_write_before()).
	 *
	 * @param int|mixed    $meta_id    Meta ID (unused).
	 * @param int|mixed    $object_id  Post ID.
	 * @param string|mixed $meta_key   Meta key.
	 * @param mixed        $meta_value New value.
	 * @return void
	 */
	public function note_meta_update( mixed $meta_id = 0, mixed $object_id = 0, mixed $meta_key = '', mixed $meta_value = null ): void {
		self::note_person_write_before( $object_id, $meta_key );

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
	 * held a value is cleared. A write on a post a person is editing is also
	 * noted (note_person_write_before()).
	 *
	 * @param int|int[]|mixed $meta_ids  Meta ID(s) being deleted.
	 * @param int|mixed       $object_id Post ID.
	 * @param string|mixed    $meta_key  Meta key.
	 * @return void
	 */
	public function note_meta_delete( mixed $meta_ids = [], mixed $object_id = 0, mixed $meta_key = '' ): void {
		self::note_person_write_before( $object_id, $meta_key );

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
	 * Before a meta write on a post a person is editing (open_person_edit()):
	 * note the key and its rows as they were before this save first wrote
	 * it, so that the save of a post marked stale can tell which Sync Fields
	 * the person changed (changed_in_save()). The rows are read from the
	 * post's meta cache only while it is filled, which core does before
	 * update_post_meta() writes; nothing is read when it is empty, and the
	 * key then counts as changed once a write lands (note_person_write_after()).
	 * A post whose filled meta cache shows no stale mark, and whose mark this
	 * request did not clear, is not followed further until it is marked
	 * (mark_stale()). The sync's own writes, the writes of the mark and the
	 * held-values row, and every other post read one static array.
	 *
	 * @param int|mixed    $object_id Post ID.
	 * @param string|mixed $meta_key  Meta key about to be written.
	 * @return void
	 */
	private static function note_person_write_before( mixed $object_id, mixed $meta_key ): void {
		if ( self::$person_edits === [] || self::$writing > 0 || ! is_string( $meta_key ) || ! is_numeric( $object_id )
			|| self::SYNC_STALE_META === $meta_key || self::SYNC_HELD_META === $meta_key ) {
			return;
		}

		$post_id = (int) $object_id;
		$key     = get_current_blog_id() . ':' . $post_id;

		if ( ! isset( self::$person_edits[ $key ] ) ) {
			return;
		}

		$known  = array_key_exists( $key, self::$person_writes );
		$writes = self::$person_writes[ $key ] ?? [];

		if ( false === $writes || array_key_exists( $meta_key, $writes ) ) {
			return;
		}

		if ( false === wp_cache_get( $post_id, 'post_meta' ) ) {
			$writes[ $meta_key ] = false;
		} elseif ( null === self::stale_restore( $post_id ) && ! isset( self::$clearing[ $key ] ) ) {
			$writes = false;
		} else {
			$writes[ $meta_key ] = (array) get_post_meta( $post_id, $meta_key, false );
		}

		if ( ! $known && count( self::$person_writes ) >= self::DEFERRED_CAP ) {
			unset( self::$person_writes[ array_key_first( self::$person_writes ) ] );
		}

		self::$person_writes[ $key ] = $writes;
	}

	/**
	 * After a meta write on a post a person is editing: a key whose earlier
	 * rows could not be read (note_person_write_before()) counts as changed,
	 * as core reports a write only when it changed a row.
	 *
	 * @param int|mixed    $object_id Post ID.
	 * @param string|mixed $meta_key  Meta key written.
	 * @return void
	 */
	private static function note_person_write_after( mixed $object_id, mixed $meta_key ): void {
		if ( self::$person_writes === [] || ! is_string( $meta_key ) || ! is_numeric( $object_id ) ) {
			return;
		}

		$key = get_current_blog_id() . ':' . (int) $object_id;

		if ( is_array( self::$person_writes[ $key ] ?? null ) && false === ( self::$person_writes[ $key ][ $meta_key ] ?? null ) ) {
			self::$person_writes[ $key ][ $meta_key ] = true;
		}
	}

	/**
	 * `post_updated`: keep the Sync Fields columns of a post a person is
	 * saving as they were before the save (see changed_in_save()). Every
	 * other save reads one static array.
	 *
	 * @param int|mixed           $post_id     Post ID (unused).
	 * @param \WP_Post|mixed|null $post_after  Post after the update (unused).
	 * @param \WP_Post|mixed|null $post_before Post before the update.
	 * @return void
	 */
	public function note_person_row( mixed $post_id = 0, mixed $post_after = null, mixed $post_before = null ): void {
		unset( $post_id, $post_after );

		if ( self::$person_edits === [] || self::$writing > 0 || ! $post_before instanceof \WP_Post ) {
			return;
		}

		$key = $this->state_key( (int) $post_before->ID );

		if ( ! isset( self::$person_edits[ $key ] ) || isset( self::$person_rows[ $key ] ) || false === ( self::$person_writes[ $key ] ?? null ) ) {
			return;
		}

		if ( count( self::$person_rows ) >= self::DEFERRED_CAP ) {
			unset( self::$person_rows[ array_key_first( self::$person_rows ) ] );
		}

		self::$person_rows[ $key ] = self::row_values( $post_before );
	}

	/**
	 * Keep the Sync Fields columns of a post as they are now, as the row
	 * before a save that is about to update it (see changed_in_save()). For
	 * an editing form whose own nonce check, and so open_person_edit(), comes
	 * after core's `post_updated` (Meta Box's front-end form): called before
	 * the form updates the post. The first row kept for a save wins.
	 *
	 * @param int|string|mixed $post_id Post about to be updated.
	 * @return void
	 */
	public static function note_pre_save_row( mixed $post_id = 0 ): void {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$key = get_current_blog_id() . ':' . $post_id;

		if ( isset( self::$person_rows[ $key ] ) ) {
			return;
		}

		if ( count( self::$person_rows ) >= self::DEFERRED_CAP ) {
			unset( self::$person_rows[ array_key_first( self::$person_rows ) ] );
		}

		self::$person_rows[ $key ] = self::row_values( $post );
	}

	/**
	 * The Sync Fields columns of a post, as strings.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string> Column => value.
	 */
	private static function row_values( \WP_Post $post ): array {
		return [
			'menu_order'     => (string) $post->menu_order,
			'post_date'      => (string) $post->post_date,
			'post_author'    => (string) $post->post_author,
			'post_parent'    => (string) $post->post_parent,
			'comment_status' => (string) $post->comment_status,
			'ping_status'    => (string) $post->ping_status,
		];
	}

	/**
	 * The Sync Fields among $fields that a person's save of a post changed:
	 * a column whose value differs from the one before the save
	 * (note_person_row()), or a meta key the save wrote whose rows differ
	 * from those before its first write (note_person_write_before()). Meta
	 * written by the save itself before `save_post` (`meta_input`, the
	 * featured image argument) counts as the person's change.
	 *
	 * @param string        $key     State key of the post.
	 * @param int           $post_id Post ID.
	 * @param array<string> $fields  Sync Fields of the save.
	 * @return array<int, string>
	 */
	private static function changed_in_save( string $key, int $post_id, array $fields ): array {
		$rows    = self::$person_rows[ $key ] ?? null;
		$writes  = self::$person_writes[ $key ] ?? [];
		$writes  = is_array( $writes ) ? $writes : [];
		$now     = null;
		$changed = [];

		foreach ( $fields as $field ) {
			$field = (string) $field;

			if ( isset( self::POST_FIELD_MAP[ $field ] ) ) {
				if ( null === $rows ) {
					continue;
				}

				if ( null === $now ) {
					$post = get_post( $post_id );
					$now  = $post instanceof \WP_Post ? self::row_values( $post ) : [];
				}

				$column = self::POST_FIELD_MAP[ $field ];

				if ( isset( $now[ $column ], $rows[ $column ] ) && $now[ $column ] !== $rows[ $column ] ) {
					$changed[] = $field;
				}

				continue;
			}

			$meta_key = 'featured_image' === $field ? '_thumbnail_id' : $field;
			$before   = $writes[ $meta_key ] ?? false;

			if ( true === $before
				|| ( is_array( $before ) && array_map( 'maybe_serialize', $before ) !== array_map( 'maybe_serialize', (array) get_post_meta( $post_id, $meta_key, false ) ) ) ) {
				$changed[] = $field;
			}
		}

		return $changed;
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
	 * also means a person is editing the post. A passed check of the post
	 * list's bulk actions starts the Bulk Edit count (bulk_skipped()).
	 *
	 * @param int|string|mixed $action Nonce action being checked.
	 * @param int|false|mixed  $result Nonce check result: 1 or 2 when valid.
	 * @return void
	 */
	public function open_edit_screen_save( mixed $action = '', mixed $result = false ): void {
		// The post list's bulk actions pass this check before Bulk Edit saves
		// its posts: from here on the request counts the translations those
		// saves skip.
		if ( 'bulk-posts' === $action && ( 1 === $result || 2 === $result ) ) {
			self::$bulk_skipped ??= [];
			return;
		}

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
	 * Record a sibling that a save of $post_id did not write although the
	 * write would have changed one of its values.
	 *
	 * @param int $post_id    Saved post.
	 * @param int $sibling_id Sibling left as it was.
	 * @return void
	 */
	private static function record_skip( int $post_id, int $sibling_id ): void {
		$key = get_current_blog_id() . ':' . $post_id;

		if ( ! isset( self::$skipped[ $key ] ) && count( self::$skipped ) >= self::DEFERRED_CAP ) {
			unset( self::$skipped[ array_key_first( self::$skipped ) ] );
		}

		self::$skipped[ $key ][ $sibling_id ] = true;

		if ( null !== self::$bulk_skipped ) {
			self::$bulk_skipped[ get_current_blog_id() . ':' . $sibling_id ] = true;
		}
	}

	/**
	 * A sibling that a save of $post_id did not write because the saver may
	 * not edit it. It is counted (record_skip()) when the write would have
	 * changed one of its values, judged against the saved post's values once
	 * its save is complete. While the save is open, or while a check of that
	 * post is waiting, the sibling joins that check (run_skip_check(), run
	 * when the save completes or when the count is read); otherwise it is
	 * compared now. With $mark, a counted sibling is also marked stale
	 * (mark_stale()). With $mark_saved, the sibling is the group's original
	 * and, when it is counted, the saved post is marked stale instead.
	 *
	 * @param int           $post_id           Saved post.
	 * @param int           $sibling_id        Sibling left as it was.
	 * @param string        $lang_slug         The sibling's language slug.
	 * @param array<string> $fields            Fields the save syncs.
	 * @param bool          $is_default_source Whether $post_id is the group's default-language member.
	 * @param array<string> $seed_only         Seed-only meta keys among $fields.
	 * @param bool          $mark              Whether the group's original holds the saved post's values.
	 * @param bool          $mark_saved        Whether the sibling is the group's original, which the save did not reach.
	 * @return void
	 */
	private function note_skip( int $post_id, int $sibling_id, string $lang_slug, array $fields, bool $is_default_source, array $seed_only, bool $mark, bool $mark_saved = false ): void {
		$key = $this->state_key( $post_id );

		if ( isset( self::$skip_checks[ $key ] ) ) {
			$check = self::$skip_checks[ $key ];

			if ( $check['fields'] !== $fields ) {
				$check['fields'] = array_values( array_unique( array_merge( $check['fields'], $fields ) ) );
			}

			if ( $seed_only !== [] && $check['seed'] !== $seed_only ) {
				$check['seed'] = array_values( array_unique( array_merge( $check['seed'], $seed_only ) ) );
			}

			$check['siblings'][ $sibling_id ] = $lang_slug;

			if ( $mark ) {
				$check['mark'][ $sibling_id ] = true;
			}

			if ( $mark_saved ) {
				$check['saved'][ $sibling_id ] = true;
			}

			self::$skip_checks[ $key ] = $check;
			return;
		}

		if ( ! isset( $this->open_saves[ $key ] ) ) {
			if ( $this->sync_fields_to_post( $post_id, $sibling_id, $fields, $lang_slug, $is_default_source, $seed_only, true ) ) {
				self::record_skip( $post_id, $sibling_id );

				if ( $mark ) {
					self::mark_stale( $sibling_id );
				}

				if ( $mark_saved ) {
					self::mark_stale( $post_id );
				}
			}

			return;
		}

		if ( count( self::$skip_checks ) >= self::DEFERRED_CAP ) {
			self::run_skip_check( (string) array_key_first( self::$skip_checks ) );
		}

		self::$skip_checks[ $key ] = [
			'sync'     => $this,
			'fields'   => $fields,
			'default'  => $is_default_source,
			'seed'     => $seed_only,
			'siblings' => [ $sibling_id => $lang_slug ],
			'mark'     => $mark ? [ $sibling_id => true ] : [],
			'saved'    => $mark_saved ? [ $sibling_id => true ] : [],
		];
	}

	/**
	 * Compare the siblings a save of a post skipped with that post's values
	 * as they are now, on the blog of the save, and count each one a write
	 * would have changed (record_skip()); one the save marks is also marked
	 * stale (mark_stale()), and a counted original the save did not reach
	 * marks the saved post stale. One comparison per sibling, from the post
	 * and meta caches.
	 *
	 * @param string $key State key of the saved post.
	 * @return void
	 */
	private static function run_skip_check( string $key ): void {
		$check = self::$skip_checks[ $key ] ?? null;
		unset( self::$skip_checks[ $key ] );

		[ $blog_id, $post_id ] = array_map( 'intval', explode( ':', $key, 2 ) + [ 0, 0 ] );

		if ( null === $check || $post_id <= 0 ) {
			return;
		}

		$switched = is_multisite() && $blog_id !== get_current_blog_id();

		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		try {
			if ( ! get_post( $post_id ) instanceof \WP_Post ) {
				return;
			}

			foreach ( $check['siblings'] as $sibling_id => $lang_slug ) {
				$mark  = isset( $check['mark'][ $sibling_id ] );
				$saved = isset( $check['saved'][ $sibling_id ] );

				if ( ( $mark || $saved || ! isset( self::$skipped[ $key ][ $sibling_id ] ) )
					&& $check['sync']->sync_fields_to_post( $post_id, $sibling_id, $check['fields'], $lang_slug, $check['default'], $check['seed'], true ) ) {
					self::record_skip( $post_id, $sibling_id );

					if ( $mark ) {
						self::mark_stale( $sibling_id );
					}

					if ( $saved ) {
						self::mark_stale( $post_id );
					}
				}
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * How many siblings the saves of a post in this request did not write
	 * because the saver may not edit them, counting only those that would
	 * have received a value they do not hold. Read by the save notices. A
	 * save that has not reported completion is judged as it stands now.
	 *
	 * @param int $post_id Saved post.
	 * @return int
	 */
	public static function skipped_siblings( int $post_id ): int {
		$key = get_current_blog_id() . ':' . $post_id;

		if ( isset( self::$skip_checks[ $key ] ) ) {
			self::run_skip_check( $key );
		}

		return count( self::$skipped[ $key ] ?? [] );
	}

	/**
	 * How many translations the posts saved by a post-list bulk action (a
	 * Bulk Edit) in this request did not reach, counted as
	 * skipped_siblings() counts them and each translation once. Null when no
	 * bulk action ran in this request. Read by the post list's notice.
	 *
	 * @return int|null
	 */
	public static function bulk_skipped(): ?int {
		while ( null !== self::$bulk_skipped && self::$skip_checks !== [] ) {
			self::run_skip_check( (string) array_key_first( self::$skip_checks ) );
		}

		return null === self::$bulk_skipped ? null : count( self::$bulk_skipped );
	}

	/**
	 * `rest_request_after_callbacks`: a post write through the core posts
	 * endpoints (the block editor's save) answers with
	 * `perflocale_sync_skipped`, the number of translations its Sync Fields
	 * did not reach although a value would have changed there (0 when none;
	 * see skipped_siblings()), for a translatable post type. The editor
	 * keeps the last answer on the post record, so every save answers.
	 *
	 * @param mixed $response Response so far.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public function add_skipped_to_rest_response( mixed $response = null, mixed $handler = null, mixed $request = null ): mixed {
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request
			|| ! in_array( $request->get_method(), [ 'POST', 'PUT', 'PATCH' ], true )
			|| ! is_array( $handler ) || ! is_array( $handler['callback'] ?? null )
			|| ! ( $handler['callback'][0] ?? null ) instanceof \WP_REST_Posts_Controller ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || ! isset( $data['id'] ) || ! is_int( $data['id'] ) ) {
			return $response;
		}

		$post_type = get_post_type( $data['id'] );

		if ( false === $post_type || ! in_array( $post_type, $this->settings->get_translatable_post_types(), true ) ) {
			return $response;
		}

		$data['perflocale_sync_skipped'] = self::skipped_siblings( $data['id'] );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * `future_to_publish`: WordPress publishes a scheduled post without a
	 * person doing it, in a cron run (WP-Cron, a server cron calling
	 * wp-cron.php, `wp cron event run`) or, outside WP-CLI, in a request of
	 * nobody logged in or of a user who may not edit the post (a plugin that
	 * publishes missed schedules during a visitor's page view or a background
	 * request). That save counts as its author's for the Sync Fields gate,
	 * exactly as if the author had saved the post, when the author may edit
	 * it: the author scheduled it, and a save with no user, or by a user who
	 * may not edit the saved post, is otherwise not gated. See
	 * scheduled_publish_user(). A person who may edit the post publishing it
	 * is gated as that person, and a WP-CLI command run outside a cron run
	 * keeps the operator's ungated write.
	 *
	 * @param \WP_Post|mixed $post Post being published.
	 * @return void
	 */
	public function note_scheduled_publish( mixed $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! wp_doing_cron()
			&& ( ( defined( 'WP_CLI' ) && WP_CLI )
				|| ( get_current_user_id() > 0 && \PerfLocale\Helper::user_can_edit_object( $post->ID ) ) ) ) {
			return;
		}

		$key = $this->state_key( $post->ID );

		if ( ! isset( self::$scheduled_publishes[ $key ] ) && count( self::$scheduled_publishes ) >= self::DEFERRED_CAP ) {
			unset( self::$scheduled_publishes[ array_key_first( self::$scheduled_publishes ) ] );
		}

		self::$scheduled_publishes[ $key ] = [
			'author' => (int) $post->post_author,
			'user'   => null,
		];
	}

	/**
	 * The user whose rights gate the Sync Fields of a scheduled post that
	 * WordPress is publishing without a person (note_scheduled_publish()), 0
	 * for none; null when this save of the post is not such a publish. By
	 * default the author, when the author may edit the post, and otherwise
	 * none: a post a Contributor wrote and an Editor scheduled still mirrors
	 * to the whole group when it goes live. Resolved once per publish, the
	 * first time the publish syncs.
	 *
	 * @param int $post_id Post being published.
	 * @return int|null
	 */
	private function scheduled_publish_user( int $post_id ): ?int {
		$key   = $this->state_key( $post_id );
		$entry = self::$scheduled_publishes[ $key ] ?? null;

		if ( null === $entry ) {
			return null;
		}

		if ( null !== $entry['user'] ) {
			return $entry['user'];
		}

		$author  = $entry['author'];
		$default = $author > 0 && \PerfLocale\Helper::user_can_edit_object( $post_id, $author ) ? $author : 0;

		/**
		 * Filters the user whose rights gate the Sync Fields of a scheduled
		 * post that WordPress publishes without a person: in a cron run, or
		 * outside WP-CLI in a request of nobody logged in or of a user who
		 * may not edit the post. The publish then writes Sync Fields, and a
		 * changed post password, only to the translations this user may edit,
		 * as a save by that user would; 0 writes them to every translation. Runs once per such publish that syncs Sync Fields or
		 * copies a changed password: not for a post without translations or
		 * opted out of sync, and for Sync Fields not for a post marked stale.
		 * A return that is not an integer of 0 or more leaves the default.
		 *
		 * @hook  perflocale/sync/scheduled_publish_user
		 * @since 1.0.7.1
		 *
		 * @param int $user_id User whose rights gate the publish, 0 for none.
		 *                     Default: the post author when the author may
		 *                     edit the post, otherwise 0.
		 * @param int $post_id Post being published.
		 */
		$user = self::user_id_or( apply_filters( 'perflocale/sync/scheduled_publish_user', $default, $post_id ), $default );

		self::$scheduled_publishes[ $key ] = [
			'author' => $author,
			'user'   => $user,
		];

		return $user;
	}

	/**
	 * A filter's return when it is an integer of 0 or more (a user ID, or 0
	 * for none), otherwise the value the filter received.
	 *
	 * @param mixed $value    Filter return.
	 * @param int   $fallback Value the filter received.
	 * @return int
	 */
	private static function user_id_or( mixed $value, int $fallback ): int {
		return is_int( $value ) && $value >= 0 ? $value : $fallback;
	}

	/**
	 * The save of a scheduled post published without a person is complete.
	 *
	 * @param int|mixed $post_id Post ID.
	 * @return void
	 */
	public function forget_scheduled_publish( mixed $post_id = 0 ): void {
		if ( self::$scheduled_publishes !== [] && is_numeric( $post_id ) ) {
			unset( self::$scheduled_publishes[ $this->state_key( (int) $post_id ) ] );
		}
	}

	/**
	 * A post's save is complete: run the sync of a save that cleared the
	 * post's stale mark (sync_cleared()), or sync the writes that waited for
	 * it, then compare the siblings the save skipped with the post's final
	 * values.
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

		if ( isset( self::$clearing[ $key ] ) && 0 === self::$writing ) {
			unset( self::$clearing[ $key ], $this->deferred[ $key ] );

			$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

			try {
				if ( $post instanceof \WP_Post ) {
					$this->run_sync( $post_id, $post, null, true );
				}
			} finally {
				unset( self::$cleared_marks[ $key ], self::$settled_content[ $key ], self::$form_held[ $key ] );
			}
		} elseif ( isset( $this->deferred[ $key ] ) && 0 === self::$writing ) {
			$fields = array_keys( $this->deferred[ $key ] );
			unset( $this->deferred[ $key ] );

			$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$this->run_sync( $post_id, $post, $fields );
			}
		}

		if ( 0 === self::$writing ) {
			unset( self::$person_rows[ $key ], self::$person_writes[ $key ] );
		}

		if ( isset( self::$skip_checks[ $key ] ) ) {
			self::run_skip_check( $key );
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
	 * The Sync Fields rule on who may write a translation applies
	 * (write_gate()): a save by a logged-in user who may edit the source
	 * writes only the translations that user may edit, and a scheduled post
	 * WordPress publishes without a person is judged by the user
	 * scheduled_publish_user() names. A translation skipped this way keeps
	 * its password, which then differs from the source's, so later changes of
	 * the source's password leave it alone too, until its password is the
	 * source's again. It is not marked stale (SYNC_STALE_META) and not
	 * counted for the save notice: both concern Sync Fields, which a
	 * translation's save can copy to its group, while a translation's
	 * password never leaves it. The rights are checked only when the
	 * password changed: once for the source and once per translation that
	 * held the previous password.
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

		$gate = null;

		foreach ( $translations as $translated_id ) {
			$translated_id = (int) $translated_id;

			if ( $translated_id === $source_id || $this->is_sync_opted_out( $translated_id ) ) {
				continue;
			}

			$translation = get_post( $translated_id );

			if ( ! $translation instanceof \WP_Post || (string) $translation->post_password !== $old || 'private' === $translation->post_status ) {
				continue;
			}

			$gate ??= $this->write_gate( $source_id );

			if ( $gate[0] && ! \PerfLocale\Helper::user_can_edit_object( $translated_id, $gate[1] ) ) {
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
	 * its save, and the check of the siblings it skipped, are dropped with
	 * it.
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
		unset( $this->deferred[ $key ], self::$skip_checks[ $key ], self::$clearing[ $key ], self::$cleared_marks[ $key ], self::$settled_content[ $key ], self::$form_held[ $key ], self::$person_rows[ $key ], self::$person_writes[ $key ] );
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
	 * Hook the shutdown fallback for syncs that wait for a save to complete.
	 *
	 * @return void
	 */
	private function hook_shutdown(): void {
		if ( ! $this->shutdown_hooked ) {
			add_action( 'shutdown', [ $this, 'flush_deferred' ] );
			$this->shutdown_hooked = true;
		}
	}

	/**
	 * Run the deferred syncs whose save never reported completion (a caller
	 * that inserted with `$fire_after_hooks = false` and did not fire
	 * wp_after_insert_post itself), the sync of a save that cleared a stale
	 * mark included.
	 *
	 * @return void
	 */
	public function flush_deferred(): void {
		while ( self::$clearing !== [] ) {
			$this->run_deferred( (string) array_key_first( self::$clearing ) );
		}

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
		$clearing = isset( self::$clearing[ $key ] );
		$fields   = array_keys( $this->deferred[ $key ] ?? [] );
		unset( $this->deferred[ $key ], $this->open_saves[ $key ], self::$clearing[ $key ] );

		[ $blog_id, $post_id ] = array_map( 'intval', explode( ':', $key, 2 ) + [ 0, 0 ] );

		if ( ( ! $clearing && $fields === [] ) || $post_id <= 0 ) {
			return;
		}

		$switched = is_multisite() && $blog_id !== get_current_blog_id();

		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		try {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post ) {
				$this->run_sync( $post_id, $post, $clearing ? null : $fields, $clearing );
			}
		} finally {
			if ( $clearing ) {
				unset( self::$person_rows[ $key ], self::$person_writes[ $key ], self::$cleared_marks[ $key ], self::$settled_content[ $key ], self::$form_held[ $key ] );
			}

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

		self::note_person_write_after( $object_id, $meta_key );

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
			$this->hook_shutdown();

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
	 * Per-post opt-out meta flag - the same key (and the same symmetric
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
	 * A translation whose Sync Fields differ from its group's: a save that
	 * gave the group's original new values skipped it because the saver may
	 * not edit it, or its own save could not give the group's original its
	 * new values for the same reason (sync_siblings()). One row, whose value
	 * holds the status its translation link held before it was set to "needs
	 * update" (`status`; `needs_update` when the link already read that) and
	 * the link's write time after the mark (`at`), and the Sync Fields a
	 * person's save that would have cleared the mark changed while another
	 * request held the post's lock (`pending`, keep_pending_changes()), which
	 * the save that clears it copies too. Clearing the mark gives the link
	 * back that status only while its write time is still `at`: a content
	 * change of the group's original that flagged the translation
	 * after the mark (ContentChangeDetector::flag_translations()) moves that
	 * time past the value it held, also within the same second, and its
	 * "needs update" then stays. While the mark is set, the translation's own
	 * saves copy none of its Sync Fields to the group, so its values never
	 * replace the group's. A person who may edit it and the group's original
	 * saving it from an editing screen (is_person_editing()) clears the mark
	 * (person_save_clears_stale()), once that save's sync holds the post's
	 * lock (settle_stale_mark()). That save copies to the group only the
	 * Sync Fields the person changed in it, and the translation takes the
	 * group's current values for the others (sync_cleared()).
	 */
	public const SYNC_STALE_META = '_perflocale_sync_stale';

	/**
	 * The Sync Fields values a person's save that cleared a translation's
	 * stale mark replaced with the group's (sync_cleared()): `mark` names the
	 * cleared mark (held_mark_id()), `values` maps each replaced field to a
	 * hash of the value the translation held before (held_value()), so the
	 * row stays small whatever the size of a field. The block editor's meta
	 * boxes still show those values: their form, rendered while the mark was
	 * set, carries the mark's name (render_held_field()), and each meta-box
	 * request it sends after a save counts a field it sends back unchanged as
	 * not changed by the person (held_form_save()). One row per post,
	 * replaced by the post's next clearing save and removed when the post is
	 * marked stale again, as no meta-box request can use it then; written
	 * only by clearing saves.
	 */
	public const SYNC_HELD_META = '_perflocale_sync_held';

	/**
	 * Name of the block editor meta-box form field that carries the stale
	 * mark the form was rendered under (render_held_field()).
	 */
	private const HELD_FIELD = 'perflocale_sync_held';

	/**
	 * Hash algorithm of the held-values row's values (held_value()), stored
	 * with each value as its label.
	 */
	private const HELD_HASH = 'sha256';

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
	 * The link status a post's stale mark (SYNC_STALE_META) restores, or null
	 * when the post is not marked. Read from the post's meta cache.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private static function stale_restore( int $post_id ): ?string {
		$mark = self::stale_mark( $post_id );

		return null === $mark ? null : $mark['status'];
	}

	/**
	 * A post's stale mark (SYNC_STALE_META): the link status it restores,
	 * the link's write time it was set at ('' when not known) and the Sync
	 * Fields a person's save changed while it could not clear the mark
	 * (keep_pending_changes()), or null when the post is not marked. Read
	 * from the post's meta cache.
	 *
	 * @param int $post_id Post ID.
	 * @return array{status: string, at: string, pending: list<string>}|null
	 */
	private static function stale_mark( int $post_id ): ?array {
		$rows = get_post_meta( $post_id, self::SYNC_STALE_META, false );

		if ( ! is_array( $rows ) || $rows === [] ) {
			return null;
		}

		$mark    = reset( $rows );
		$status  = is_array( $mark ) ? ( $mark['status'] ?? '' ) : $mark;
		$at      = is_array( $mark ) ? ( $mark['at'] ?? '' ) : '';
		$pending = is_array( $mark ) && is_array( $mark['pending'] ?? null ) ? array_values( array_filter( $mark['pending'], 'is_string' ) ) : [];

		return [
			'status'  => is_string( $status ) ? $status : '',
			'at'      => is_string( $at ) ? $at : '',
			'pending' => $pending,
		];
	}

	/**
	 * Write a post's stale mark.
	 *
	 * @param int                $post_id Post ID.
	 * @param string             $status  Link status the mark restores.
	 * @param string             $at      Link write time the mark was set at, '' when not known.
	 * @param array<int, string> $pending Sync Fields kept for the save that clears the mark; none when empty.
	 * @return void
	 */
	private static function write_stale_mark( int $post_id, string $status, string $at, array $pending = [] ): void {
		$mark = [
			'status' => $status,
			'at'     => $at,
		];

		if ( $pending !== [] ) {
			$mark['pending'] = $pending;
		}

		update_post_meta( $post_id, self::SYNC_STALE_META, $mark );
	}

	/**
	 * A post's own translation link (its language id, stored status and
	 * write time), from the group's cached links; null when it has none.
	 *
	 * @param int $post_id Post ID.
	 * @return array{language_id: int, status: string, updated_at: string}|null
	 */
	private static function own_link( int $post_id ): ?array {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'group_repo' ) ) {
			return null;
		}

		foreach ( $plugin->group_repo()->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post ) as $link ) {
			$object_id = is_object( $link ) ? ( $link->object_id ?? null ) : null;

			if ( is_numeric( $object_id ) && (int) $object_id === $post_id ) {
				$language_id = $link->language_id ?? null;
				$status      = $link->status ?? null;
				$updated_at  = $link->updated_at ?? null;

				return [
					'language_id' => is_numeric( $language_id ) ? (int) $language_id : 0,
					'status'      => is_string( $status ) ? $status : '',
					'updated_at'  => is_string( $updated_at ) ? $updated_at : '',
				];
			}
		}

		return null;
	}

	/**
	 * Mark a translation as stale (SYNC_STALE_META) and set its translation
	 * link to "needs update". The mark stores the status the link read and
	 * the link's write time after the mark. The link is written with a
	 * compare-and-set on the status read from the cached links; when another
	 * write reached the link first, its stored status is read again: a link
	 * that now reads "needs update" (a content flag) is left as it is and the
	 * mark stores that, any other status is stored and the link set to
	 * "needs update" over it. A link that keeps changing under the mark is
	 * set to "needs update" and the mark restores nothing.
	 *
	 * @param int $post_id Translation whose Sync Fields differ from its group's.
	 * @return void
	 */
	private static function mark_stale( int $post_id ): void {
		if ( null !== self::stale_mark( $post_id ) ) {
			return;
		}

		$link = self::own_link( $post_id );

		if ( null === $link ) {
			return;
		}

		// A person's edit of this post earlier in the request may have found
		// it unmarked and stopped following it (note_person_write_before());
		// a later save of it by that person follows it again.
		$key = get_current_blog_id() . ':' . $post_id;

		if ( false === ( self::$person_writes[ $key ] ?? null ) ) {
			unset( self::$person_writes[ $key ] );
		}

		// The held-values row of a mark cleared earlier serves no form once
		// the post is marked again: its next clearing save writes its own.
		// Read from the meta cache stale_mark() filled.
		if ( [] !== get_post_meta( $post_id, self::SYNC_HELD_META, false ) ) {
			delete_post_meta( $post_id, self::SYNC_HELD_META );
		}

		$needs   = \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value;
		$status  = $link['status'];
		$lang_id = $link['language_id'];
		$repo    = \PerfLocale\Plugin::get_instance()->group_repo();

		if ( $needs === $status ) {
			add_post_meta(
				$post_id,
				self::SYNC_STALE_META,
				[
					'status' => $needs,
					'at'     => $link['updated_at'],
				],
				true
			);
			return;
		}

		$at = current_time( 'mysql' );

		add_post_meta(
			$post_id,
			self::SYNC_STALE_META,
			[
				'status' => $status,
				'at'     => $at,
			],
			true
		);

		if ( $repo->update_link_status( $post_id, $lang_id, $needs, '', \PerfLocale\Enum\ObjectType::Post, $status ) ) {
			// The write stamps the link with the current time. When the
			// second turned over meanwhile, the stamp is read from the row.
			if ( current_time( 'mysql' ) !== $at ) {
				$live = $repo->get_link_state( $post_id, $lang_id, \PerfLocale\Enum\ObjectType::Post );
				self::write_stale_mark( $post_id, $status, null !== $live && $needs === $live['status'] ? $live['updated_at'] : '' );
			}

			return;
		}

		$live = $repo->get_link_state( $post_id, $lang_id, \PerfLocale\Enum\ObjectType::Post );

		if ( null === $live ) {
			delete_post_meta( $post_id, self::SYNC_STALE_META );
			return;
		}

		if ( $needs === $live['status'] ) {
			self::write_stale_mark( $post_id, $needs, $live['updated_at'] );
			return;
		}

		if ( $repo->update_link_status( $post_id, $lang_id, $needs, '', \PerfLocale\Enum\ObjectType::Post, $live['status'] ) ) {
			$written = $repo->get_link_state( $post_id, $lang_id, \PerfLocale\Enum\ObjectType::Post );
			self::write_stale_mark( $post_id, $live['status'], null !== $written && $needs === $written['status'] ? $written['updated_at'] : '' );
			return;
		}

		self::write_stale_mark( $post_id, $needs, '' );
		$repo->update_link_status( $post_id, $lang_id, $needs, '', \PerfLocale\Enum\ObjectType::Post );
	}

	/**
	 * Clear a post's stale mark and give its translation link back the status
	 * the mark stored, while the link still reads "needs update" and was not
	 * written since the mark (its write time is still the mark's). A link
	 * written since, as a content change of the group's original does, keeps
	 * "needs update"; so does one whose mark has no known write time.
	 *
	 * @param int                                                      $post_id Post ID.
	 * @param array{status: string, at: string, pending: list<string>} $mark    The post's stale mark.
	 * @return void
	 */
	private static function clear_stale( int $post_id, array $mark ): void {
		delete_post_meta( $post_id, self::SYNC_STALE_META );

		$needs   = \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value;
		$restore = $mark['status'];

		if ( $needs === $restore || '' === $mark['at'] || null === \PerfLocale\Enum\TranslationStatus::tryFrom( $restore ) ) {
			return;
		}

		$link = self::own_link( $post_id );

		if ( null !== $link ) {
			\PerfLocale\Plugin::get_instance()->group_repo()->update_link_status( $post_id, $link['language_id'], $restore, '', \PerfLocale\Enum\ObjectType::Post, $needs, $mark['at'] );
		}
	}

	/**
	 * Clear the stale mark a person's save clears (person_save_clears_stale()),
	 * as it read at that save's `save_post`, and give the translation link
	 * back the status the mark stored (clear_stale()). When the same save
	 * changed the post's content while the mark held its "needs update"
	 * (keeps_needs_update()), that change then settles the link as it does
	 * on any translation whose content changed: a link that still reads
	 * "needs update" becomes published (ContentChangeDetector).
	 *
	 * @param int $post_id Post saved.
	 * @return void
	 */
	private static function settle_stale_mark( int $post_id ): void {
		$key  = get_current_blog_id() . ':' . $post_id;
		$mark = self::$cleared_marks[ $key ] ?? null;

		if ( null === $mark ) {
			return;
		}

		self::clear_stale( $post_id, $mark );

		if ( ! isset( self::$settled_content[ $key ] ) ) {
			return;
		}

		unset( self::$settled_content[ $key ] );

		$link = self::own_link( $post_id );

		if ( null !== $link ) {
			\PerfLocale\Plugin::get_instance()->group_repo()->update_link_status( $post_id, $link['language_id'], \PerfLocale\Enum\TranslationStatus::Published->value, '', \PerfLocale\Enum\ObjectType::Post, \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value );
		}
	}

	/**
	 * Whether the save of a post marked stale (SYNC_STALE_META) must copy
	 * none of its Sync Fields, as its values are older than its group's: a
	 * save from cron, WP-CLI, an import or a background job, from code
	 * acting for a logged-in user, or from a person who may not edit the
	 * group's original. A person's save that clears the mark
	 * (person_save_clears_stale()) syncs once it is complete and is not held
	 * back: its sync clears the mark (sync_cleared(), settle_stale_mark()).
	 * Read from the meta cache the opt-out check fills.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool
	 */
	private function holds_stale_values( int $post_id ): bool {
		return null !== self::stale_restore( $post_id );
	}

	/**
	 * Whether a person's save of a post clears its stale mark
	 * (SYNC_STALE_META): a person saves it from an editing screen
	 * (is_person_editing()) and may also edit the group's original, and the
	 * post's row as it was before the save is known (note_person_row(),
	 * note_pre_save_row()). In a group without a default-language member,
	 * the person must be able to edit every other member. Otherwise the mark
	 * stays and the save copies none of the post's Sync Fields. The mark is
	 * not cleared here: the
	 * save's sync clears it once nothing can stop that sync, after it took
	 * the post's lock (run_sync(), settle_stale_mark()), so a sync that
	 * cannot run leaves the mark and the link status as they are. The save
	 * of an opted-out post, of a post alone in its group, of a type no longer
	 * translatable or with an empty Sync Fields list clears the mark too. Any
	 * other save reads one static array.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool Whether the save clears the mark; its sync then runs once
	 *              the save is complete (sync_cleared()).
	 */
	private function person_save_clears_stale( int $post_id ): bool {
		if ( self::$person_edits === [] || self::$writing > 0
			|| ! isset( self::$person_edits[ get_current_blog_id() . ':' . $post_id ] )
			|| ! self::is_person_editing( $post_id ) ) {
			return false;
		}

		$key = $this->state_key( $post_id );

		// Without the row as it was before the save, a post column the person
		// changed would count as unchanged and take the group's value
		// (changed_in_save()): such a save keeps the mark.
		if ( ! isset( self::$person_rows[ $key ] ) ) {
			return false;
		}

		$mark = self::stale_mark( $post_id );

		if ( null === $mark || ! $this->may_edit_original( $post_id ) ) {
			return false;
		}

		if ( ! isset( self::$cleared_marks[ $key ] ) && count( self::$cleared_marks ) >= self::DEFERRED_CAP ) {
			unset( self::$cleared_marks[ array_key_first( self::$cleared_marks ) ] );
		}

		self::$cleared_marks[ $key ] = $mark;

		return true;
	}

	/**
	 * The name of a stale mark, as the held-values row and the block editor
	 * meta-box form carry it.
	 *
	 * @param array{status: string, at: string, pending: list<string>} $mark Stale mark.
	 * @return string
	 */
	private static function held_mark_id( array $mark ): string {
		return $mark['status'] . '|' . $mark['at'];
	}

	/**
	 * `block_editor_meta_box_hidden_fields`: the block editor's meta-box form
	 * of a translation marked stale names the mark. Its meta boxes show the
	 * translation's values from before the save that will clear the mark,
	 * and core posts that form after each save from the page.
	 *
	 * @param \WP_Post|mixed $post Post being edited.
	 * @return void
	 */
	public function render_held_field( mixed $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$mark = self::stale_mark( (int) $post->ID );

		if ( null !== $mark ) {
			echo '<input type="hidden" name="' . esc_attr( self::HELD_FIELD ) . '" value="' . esc_attr( self::held_mark_id( $mark ) ) . '" />';
		}
	}

	/**
	 * Whether a person's save of a post is a block editor meta-box request
	 * whose form was rendered under the stale mark that an earlier save
	 * cleared: the form names the mark (render_held_field()) and the post's
	 * held-values row (SYNC_HELD_META) was written when that mark was
	 * cleared. Its values are kept for the save's sync (sync_cleared()), in
	 * which a Sync Field the form sends back with the value the row holds
	 * counts as unchanged. Any other save reads one static array, and a
	 * person's save whose form names no mark reads no more.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool
	 */
	private function held_form_save( int $post_id ): bool {
		$key = $this->state_key( $post_id );

		if ( self::$person_edits === [] || self::$writing > 0 || ! isset( self::$person_edits[ $key ], $_POST[ self::HELD_FIELD ], $_POST['_wpnonce'] ) ) {
			return false;
		}

		$nonce = is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'update-post_' . $post_id ) ) {
			return false;
		}

		if ( wp_doing_cron() || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$form = is_string( $_POST[ self::HELD_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::HELD_FIELD ] ) ) : '';
		$row  = get_post_meta( $post_id, self::SYNC_HELD_META, true );

		if ( '' === $form || ! is_array( $row ) || ( $row['mark'] ?? null ) !== $form || ! is_array( $row['values'] ?? null ) ) {
			return false;
		}

		$values = [];

		foreach ( $row['values'] as $field => $value ) {
			if ( is_string( $field ) && is_string( $value ) ) {
				$values[ $field ] = $value;
			}
		}

		if ( ! isset( self::$form_held[ $key ] ) && count( self::$form_held ) >= self::DEFERRED_CAP ) {
			unset( self::$form_held[ array_key_first( self::$form_held ) ] );
		}

		self::$form_held[ $key ] = $values;

		return true;
	}

	/**
	 * A Sync Field's value on a post, as the held-values row stores it: a
	 * hash of the value, labelled with its algorithm (HELD_HASH), of a post
	 * column as a string or a meta key (the featured image as
	 * `_thumbnail_id`) as its rows, serialized. Only equality is asked of it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Sync Field.
	 * @return string
	 */
	private static function held_value( int $post_id, string $field ): string {
		if ( isset( self::POST_FIELD_MAP[ $field ] ) ) {
			$post  = get_post( $post_id );
			$value = $post instanceof \WP_Post ? self::row_values( $post )[ self::POST_FIELD_MAP[ $field ] ] : '';
		} else {
			$rows  = get_post_meta( $post_id, 'featured_image' === $field ? '_thumbnail_id' : $field, false );
			$value = maybe_serialize( array_map( 'maybe_serialize', is_array( $rows ) ? $rows : [] ) );
		}

		return self::HELD_HASH . ':' . hash( self::HELD_HASH, $value );
	}

	/**
	 * Whether the current user may edit the original of a post's group: its
	 * default-language member or, in a group without one, every member
	 * other than the post.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool
	 */
	private function may_edit_original( int $post_id ): bool {
		$translations = $this->manager->get_translations( $post_id );
		$original     = $this->default_member( $translations );

		if ( $original > 0 ) {
			return \PerfLocale\Helper::user_can_edit_object( $original );
		}

		foreach ( $translations as $member ) {
			if ( $member !== $post_id && ! \PerfLocale\Helper::user_can_edit_object( $member ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a translation keeps its "needs update" status because it is
	 * marked stale (SYNC_STALE_META). Asked by ContentChangeDetector before it
	 * clears that status after the translation's content changed: the mark
	 * keeps it until a person's save clears the mark
	 * (person_save_clears_stale()). The changed content settles every
	 * content flag up to the link's last write: a mark that stored "needs
	 * update" now restores the published status, and the mark takes the
	 * link's write time, so the person's save restores its status unless a
	 * content change of the original flags the translation again. When the
	 * save that changed the content is itself a person's save that clears
	 * the mark, its sync settles the link once the mark is cleared
	 * (settle_stale_mark()).
	 *
	 * The link's write time is read from the table, for a marked post only.
	 *
	 * @param int $post_id Translation whose content changed.
	 * @return bool
	 */
	public static function keeps_needs_update( int $post_id ): bool {
		$mark = self::stale_mark( $post_id );

		if ( null === $mark ) {
			return false;
		}

		$status = \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value === $mark['status'] ? \PerfLocale\Enum\TranslationStatus::Published->value : $mark['status'];
		$link   = self::own_link( $post_id );
		$live   = null === $link ? null : \PerfLocale\Plugin::get_instance()->group_repo()->get_link_state( $post_id, $link['language_id'], \PerfLocale\Enum\ObjectType::Post );
		$at     = null === $live ? $mark['at'] : $live['updated_at'];

		if ( [ $status, $at ] !== [ $mark['status'], $mark['at'] ] ) {
			self::write_stale_mark( $post_id, $status, $at, $mark['pending'] );
		}

		$key = get_current_blog_id() . ':' . $post_id;

		if ( isset( self::$cleared_marks[ $key ] ) ) {
			self::$settled_content[ $key ] = true;
		}

		return true;
	}

	/**
	 * The group's default-language member, 0 when it has none or no default
	 * language is set.
	 *
	 * @param array<string, int> $translations language_slug => post_id map for the group.
	 * @return int
	 */
	private function default_member( array $translations ): int {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'lang_repo' ) ) {
			return 0;
		}

		$default = $plugin->lang_repo()->get_default();
		$slug    = ( $default && isset( $default->slug ) && is_string( $default->slug ) ) ? $default->slug : '';

		return '' === $slug ? 0 : (int) ( $translations[ $slug ] ?? 0 );
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
	 * same decision in sync_on_term_edit(). Seed-only meta keys and the
	 * publish date travel the same one way.
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
	 * Whether a post's date floats: a status core gives no fixed date (a
	 * draft, a pending post) and no GMT date stored. Core sets such a post's
	 * date to the current time on each save until a date is given. Also
	 * read when a translation is created
	 * (PostTranslationManager::do_create_translation()).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function date_floats( \WP_Post $post ): bool {
		return '0000-00-00 00:00:00' === $post->post_date_gmt
			&& in_array( $post->post_status, get_post_stati( [ 'date_floating' => true ] ), true );
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
		$key = $this->state_key( $post_id );
		unset( $this->deferred[ $key ] );

		// A person's save that clears the post's stale mark syncs once the
		// save is complete, when every write it makes has landed (the REST
		// API writes the featured image and meta after save_post); that sync
		// clears the mark. So does a block editor meta-box request whose form
		// shows the values such a save replaced (held_form_save()).
		if ( $this->person_save_clears_stale( $post_id ) || $this->held_form_save( $post_id ) ) {
			if ( ! isset( self::$clearing[ $key ] ) && count( self::$clearing ) >= self::DEFERRED_CAP ) {
				$this->run_deferred( (string) array_key_first( self::$clearing ) );
			}

			self::$clearing[ $key ] = true;
			$this->hook_shutdown();

			return;
		}

		$this->run_sync( $post_id, $post, null );
	}

	/**
	 * Sync a post's fields to the other members of its translation group.
	 *
	 * @param int                     $post_id  Post ID.
	 * @param \WP_Post                $post     Post object.
	 * @param array<int, string>|null $only     Null for a save (every synced field).
	 *                                          Otherwise the sync fields a meta write
	 *                                          changed; see sync_written_fields().
	 * @param bool                    $clearing Whether the save syncs as a save that
	 *                                          clears a stale mark (self::$clearing,
	 *                                          sync_cleared()); with $only null.
	 * @return void
	 */
	private function run_sync( int $post_id, \WP_Post $post, ?array $only, bool $clearing = false ): void {
		// The sync of a person's save that clears the post's stale mark
		// (person_save_clears_stale()) clears it once nothing can stop that
		// sync: when a check below ends it before any write, or once the
		// post's lock is taken. While another request holds the lock, the
		// mark and the link status stay as they are, so the post's older
		// values stay held back from the group, and the Sync Fields the save
		// changed are kept with the mark (keep_pending_changes()).
		$settle = $clearing && isset( self::$cleared_marks[ $this->state_key( $post_id ) ] );

		// The service is registered on every blog so a request that switches
		// blogs syncs there; a blog with an empty Sync Fields list syncs
		// nothing.
		if ( empty( (array) $this->settings->get( 'sync_fields', [] ) ) || ! $this->may_sync( $post_id, $post, $only, $settle ) ) {
			if ( $settle ) {
				self::settle_stale_mark( $post_id );
			}

			return;
		}

		// Atomically acquire the source lock - concurrent admin + cron
		// writes for the same post can't both enter the critical section.
		if ( ! Lock::acquire( $this->post_lock( $post_id ), self::LOCK_TTL ) ) {
			if ( $settle ) {
				$this->keep_pending_changes( $post_id, $post );
			}

			return;
		}

		try {
			$user_fields = (array) $this->settings->get( 'sync_fields', [] );

			if ( $settle ) {
				self::settle_stale_mark( $post_id );
			}

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
			 * can't enumerate - e.g. ACF repeater / flexible-content rows, whose
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
			 * The mirror below is BIDIRECTIONAL - any group member's save
			 * propagates to the rest - so a key holding words is destroyed in
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
			 * have the key yet, then owned by the sibling's translator - a
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

			if ( $clearing ) {
				$this->sync_cleared( $post_id, $translations, $sync_fields, $is_default_source, $seed_only );
			} else {
				$this->sync_siblings( $post_id, $translations, $sync_fields, $is_default_source, $seed_only );
			}
		} finally {
			Lock::release( $this->post_lock( $post_id ) );
		}
	}

	/**
	 * Whether a save or a meta write of a post on a blog with Sync Fields
	 * syncs to its group at all, answered before any lock is taken.
	 *
	 * @param int                     $post_id Post ID.
	 * @param \WP_Post                $post    Post object.
	 * @param array<int, string>|null $only    As in run_sync().
	 * @param bool                    $settle  Whether the sync clears the post's stale
	 *                                         mark; it is then not held back by it.
	 * @return bool
	 */
	private function may_sync( int $post_id, \WP_Post $post, ?array $only, bool $settle ): bool {
		// Skip revisions and autosaves before acquiring any lock - avoids
		// churning the options table on every autosave tick.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		// ⚠️ Templates are not content. sync_fields (post_parent, menu_order,
		// featured image) are meaningless for them, and this fires on EVERY
		// Site Editor save - acquiring two locks and walking the group - which
		// is pure overhead on the hottest editing path in a block theme.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( $post->post_type ) ) {
			return false;
		}

		// Check if post type is translatable.
		$translatable = $this->settings->get_translatable_post_types();

		if ( ! in_array( $post->post_type, $translatable, true ) ) {
			return false;
		}

		// A meta write on a post with no translations has nothing to reach;
		// answer that from the cached group before taking any lock.
		if ( $only !== null && count( $this->manager->get_translations( $post_id ) ) < 2 ) {
			return false;
		}

		// Symmetric opt-out: a flagged post pushes nothing. The mirror is
		// bidirectional (any group member's save propagates group-wide), so
		// a one-sided target check would let a deliberately diverged
		// translation clobber its siblings on its own next save.
		if ( $this->is_sync_opted_out( $post_id ) ) {
			return false;
		}

		// A save of a post with no translations has nothing to reach either:
		// answered from the same cached group, after the opt-out so that an
		// opted-out post reads nothing. A save the sync makes itself (a
		// sibling write, a password copy) skips this read: the lock run_sync()
		// takes is already held for that post, so the save stops there.
		if ( $only === null && self::$writing === 0 && count( $this->manager->get_translations( $post_id ) ) < 2 ) {
			return false;
		}

		// A translation marked stale copies none of its Sync Fields on a save
		// that does not clear the mark (holds_stale_values()). A meta write
		// still syncs the one field it changed.
		if ( $only === null && self::$writing === 0 && ! $settle && $this->holds_stale_values( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * A person's save that clears a post's stale mark could not take the
	 * post's lock, as another request holds it: its sync did not run and the
	 * mark stays. The Sync Fields that save changed (changed_in_save()) are
	 * kept with the mark (`pending`), so the save that clears it copies them
	 * to the group as well (sync_cleared()) and the person's change is not
	 * replaced by the group's value. The mark is read from the database
	 * first, so a mark another request cleared meanwhile is not written back.
	 *
	 * @param int      $post_id Post saved.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	private function keep_pending_changes( int $post_id, \WP_Post $post ): void {
		$fields = array_merge( (array) $this->settings->get( 'sync_fields', [] ), $this->settings->get_translatable_meta_keys( $post->post_type ) );

		/** This filter is documented in src/Translation/ContentSync.php */
		$fields = (array) apply_filters( 'perflocale/sync_fields', $fields, $post->post_type );

		/** This filter is documented in src/Translation/ContentSync.php */
		$fields = (array) apply_filters( 'perflocale/sync_fields/for_post', $fields, $post_id );

		$names = [];

		foreach ( $fields as $field ) {
			if ( is_string( $field ) && '' !== $field ) {
				$names[ $field ] = $field;
			}
		}

		$changed = self::changed_in_save( $this->state_key( $post_id ), $post_id, array_values( $names ) );

		if ( $changed === [] ) {
			return;
		}

		wp_cache_delete( $post_id, 'post_meta' );
		$mark = self::stale_mark( $post_id );

		if ( null === $mark ) {
			return;
		}

		$pending = array_values( array_unique( array_merge( $mark['pending'], $changed ) ) );

		if ( $pending === $mark['pending'] ) {
			return;
		}

		try {
			++self::$writing;
			self::write_stale_mark( $post_id, $mark['status'], $mark['at'], $pending );
		} finally {
			--self::$writing;
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
	 * The sync of a person's save that clears the post's stale mark
	 * (person_save_clears_stale(); run_sync() cleared the mark just before),
	 * or of a block editor meta-box request whose form shows the values such
	 * a save replaced (held_form_save()), run once the save is complete and
	 * under the saved post's lock. The
	 * Sync Fields the person changed are written to the group, gated as any
	 * save of that person: in a clearing save those the save changed
	 * (changed_in_save()); in a meta-box request every field but those the
	 * form sent back with the value the post held before the clearing save.
	 * For every other Sync Field the post takes the group's current values
	 * from the member that holds them (group_values_member()), as a save of
	 * that member would give them, so the values the mark held back never
	 * reach the group. A clearing save keeps the values it replaced in the
	 * post's held-values row (SYNC_HELD_META).
	 *
	 * @param int                $post_id           Post saved.
	 * @param array<string, int> $translations      language_slug => post_id map for the group.
	 * @param array<string>      $fields            Sync Fields of the save.
	 * @param bool               $is_default_source Whether $post_id is the group's default-language member.
	 * @param array<string>      $seed_only         Seed-only meta keys among $fields.
	 * @return void
	 */
	private function sync_cleared( int $post_id, array $translations, array $fields, bool $is_default_source, array $seed_only ): void {
		$key  = $this->state_key( $post_id );
		$held = self::$form_held[ $key ] ?? null;

		if ( null === $held ) {
			$changed = self::changed_in_save( $key, $post_id, $fields );
		} else {
			$changed = [];

			foreach ( $fields as $field ) {
				$field = (string) $field;

				if ( ! isset( $held[ $field ] ) || $held[ $field ] !== self::held_value( $post_id, $field ) ) {
					$changed[] = $field;
				}
			}
		}

		// Sync Fields an earlier save that could not clear the mark changed
		// (keep_pending_changes()) count as changed by this one.
		foreach ( self::$cleared_marks[ $key ]['pending'] ?? [] as $field ) {
			if ( in_array( $field, $fields, true ) && ! in_array( $field, $changed, true ) ) {
				$changed[] = $field;
			}
		}

		$rest   = array_values( array_diff( $fields, $changed ) );
		$source = $rest !== [] ? $this->group_values_member( $post_id, $translations ) : 0;
		$slug   = array_search( $post_id, $translations, true );
		$before = [];

		if ( $source > 0 && is_string( $slug ) ) {
			if ( null === $held ) {
				foreach ( $rest as $field ) {
					$before[ (string) $field ] = self::held_value( $post_id, (string) $field );
				}
			}

			try {
				++self::$writing;
				$this->sync_fields_to_post( $source, $post_id, $rest, $slug, $this->is_default_language_post( $source, $translations ), array_values( array_intersect( $seed_only, $rest ) ) );
				clean_post_cache( $post_id );
			} finally {
				--self::$writing;
			}
		}

		if ( null === $held && isset( self::$cleared_marks[ $key ] ) ) {
			$this->write_held( $post_id, self::held_mark_id( self::$cleared_marks[ $key ] ), $before );
		}

		if ( $changed !== [] ) {
			$this->sync_siblings( $post_id, $translations, $changed, $is_default_source, array_values( array_intersect( $seed_only, $changed ) ) );
		}
	}

	/**
	 * Keep, in the post's held-values row (SYNC_HELD_META), the Sync Fields
	 * values a clearing save replaced: those whose value before the copy
	 * ($before) differs from the post's value now. A clearing save that
	 * replaced none removes the post's row.
	 *
	 * @param int                   $post_id Post saved.
	 * @param string                $mark    Name of the mark the save cleared.
	 * @param array<string, string> $before  Field => value before the copy (held_value()).
	 * @return void
	 */
	private function write_held( int $post_id, string $mark, array $before ): void {
		$values = [];

		foreach ( $before as $field => $value ) {
			if ( $value !== self::held_value( $post_id, $field ) ) {
				$values[ $field ] = $value;
			}
		}

		try {
			++self::$writing;

			if ( $values !== [] ) {
				update_post_meta(
					$post_id,
					self::SYNC_HELD_META,
					[
						'mark'   => $mark,
						'values' => $values,
					]
				);
			} elseif ( [] !== get_post_meta( $post_id, self::SYNC_HELD_META, false ) ) {
				delete_post_meta( $post_id, self::SYNC_HELD_META );
			}
		} finally {
			--self::$writing;
		}
	}

	/**
	 * The group member whose Sync Fields values are the group's current
	 * ones, for a post whose stale mark was just cleared: the
	 * default-language member, or, when there is none or it is opted out of
	 * sync or marked stale itself, the first other member that is neither.
	 * 0 when no member qualifies.
	 *
	 * @param int                $post_id      Post whose mark was cleared.
	 * @param array<string, int> $translations language_slug => post_id map for the group.
	 * @return int
	 */
	private function group_values_member( int $post_id, array $translations ): int {
		$default = $this->default_member( $translations );
		$members = array_values( array_unique( array_merge( $default > 0 ? [ $default ] : [], array_map( 'intval', array_values( $translations ) ) ) ) );

		foreach ( $members as $member ) {
			if ( $member !== $post_id && $member > 0 && ! $this->is_sync_opted_out( $member ) && null === self::stale_restore( $member ) ) {
				return $member;
			}
		}

		return 0;
	}

	/**
	 * Whether a save of a post writes to the other members of its group
	 * only where a user may edit them, and that user (null for the current
	 * user). Gated: a scheduled post WordPress publishes without a person,
	 * when scheduled_publish_user() names a user, and a save outside a cron
	 * run by a logged-in user who may edit the saved post. Not gated: any
	 * other save with no user (WP-CLI, an import, a background job), a save
	 * in a cron run, and a save by a user who may not edit the saved post.
	 * The Sync Fields mirror (sync_siblings()) and the password copy
	 * (sync_password()) both follow it.
	 *
	 * @param int $post_id Saved post.
	 * @return array{0: bool, 1: int|null} Whether the save is gated, and the user whose rights gate it.
	 */
	private function write_gate( int $post_id ): array {
		$gate_user = $this->scheduled_publish_user( $post_id );
		$gated     = null !== $gate_user
			? $gate_user > 0
			: ( ! wp_doing_cron() && get_current_user_id() > 0 && \PerfLocale\Helper::user_can_edit_object( $post_id ) );

		return [ $gated, $gate_user ];
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
		// Parent, Publish Date and seed-only keys aside: a save of any member
		// writes them to the other members. Page Parent and Publish Date
		// move only from the default-language member (Page Parent mapped to
		// each sibling's own-language parent), and seed-only keys are copied
		// only from the default-language member, and only to a sibling that
		// lacks them.
		// A logged-in saver who may edit the saved post writes only to the
		// siblings they may edit as well. The others are skipped and keep
		// their values. One is counted for the save notice
		// (skipped_siblings()) when the write would have changed a value it
		// holds: the same decisions are made against its stored values
		// without writing (sync_fields_to_post() with $dry_run), from the
		// post and meta caches, once the save is complete (note_skip()), so
		// a sibling that already agrees costs no write and no notice. When
		// the group's original holds the saved post's values after the save
		// (it is the saved post, or the saver may edit it), each counted
		// translation is also marked stale (mark_stale(), SYNC_STALE_META):
		// its translation link reads "needs update", and its own saves copy
		// nothing back until a person who may edit it and the original saves
		// it (person_save_clears_stale()); that save copies only the Sync
		// Fields the person changed, and the translation takes the group's
		// values for the rest (sync_cleared()).
		// In a group without a default-language member, or whose
		// default-language member is opted out of sync, the saved post holds
		// the group's values, so its counted skips are marked too.
		// When the original was skipped too, the original's values stand and
		// the skipped translations are not marked. The saved post is marked
		// instead when the write would have changed the original (note_skip()):
		// its own later saves, ungated ones included (WP-CLI, an import, a
		// cron run), then copy none of its values to the group until a person
		// who may edit it and the original saves it, and that save copies the
		// Sync Fields the person changed. The mirror copies the saved post's
		// current values, not only what changed, so the next save by someone
		// who may edit the whole group makes it agree again on the values of
		// the post they save: an editor's save of the original wins. A
		// scheduled post WordPress publishes without a person
		// (note_scheduled_publish()) is gated by the rights of the user
		// scheduled_publish_user() names: by default its author when the
		// author may edit it, as if the author had saved it, and otherwise
		// not gated. Any other save with no user (WP-CLI, an import, a
		// background job) or in a cron run is not gated, and neither is a
		// save by a user who may not edit the saved post: that write is the
		// site's own (a checkout updating a product), not the user's. The
		// sync opt-out keeps a post out of the group, and setting it needs
		// edit_post on that post. The rule is write_gate(), which the
		// password copy (sync_password()) applies too.
		[ $gated, $gate_user ] = $this->write_gate( $post_id );

		$original = ( $gated && ! $is_default_source ) ? $this->default_member( $translations ) : 0;
		$reached  = $is_default_source || ( $gated && 0 === $original );
		$skipped  = [];

		foreach ( $translations as $lang_slug => $translated_id ) {
			if ( $translated_id === $post_id ) {
				continue;
			}

			// An opted-out default-language member holds no group values:
			// the group counts as one without it.
			if ( $this->is_sync_opted_out( $translated_id ) ) {
				$reached = $reached || $translated_id === $original;
				continue;
			}

			if ( $gated && ! \PerfLocale\Helper::user_can_edit_object( $translated_id, $gate_user ) ) {
				$skipped[ $translated_id ] = (string) $lang_slug;
				continue;
			}

			if ( $translated_id === $original ) {
				$reached = true;
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

		foreach ( $skipped as $sibling_id => $lang_slug ) {
			$this->note_skip( $post_id, $sibling_id, $lang_slug, $fields, $is_default_source, $seed_only, $reached, ! $reached && $sibling_id === $original );
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
	 * @param string        $target_lang_slug Target post's language slug - used to
	 *            translate post_parent into the same language so the sibling
	 *            doesn't end up with a cross-language parent reference.
	 * @param bool          $source_is_default_lang Whether the saved post is its
	 *            group's default-language member. Only that member may move the
	 *            group's hierarchy or publish date, or seed seed-only keys - see
	 *            is_default_language_post().
	 * @param array<string> $seed_only Meta keys with seed-only semantics: copied
	 *            from the default-language member when the sibling has NO rows
	 *            for the key and has not recorded it as deliberately cleared
	 *            (SEED_CLEARED_META), otherwise left untouched (never
	 *            overwritten, never deleted) - the sibling's translator owns the
	 *            per-language value.
	 * @param bool          $dry_run When true, nothing is written or flushed: the
	 *            same decisions are made against the sibling's stored values,
	 *            and the first field the write would change returns true.
	 * @return bool With $dry_run, whether the write would give the sibling a
	 *              value it does not hold; false otherwise.
	 */
	private function sync_fields_to_post( int $source_id, int $target_id, array $fields, string $target_lang_slug, bool $source_is_default_lang, array $seed_only = [], bool $dry_run = false ): bool {
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

				// The group's publish date is the default-language member's.
				// A translation's own date never moves it: core gives a
				// translation the current time when it is published or
				// created, and that would move the original's date and, with
				// a date-based permalink structure, its URL. A draft whose
				// date floats moves nothing either: core rewrites that date
				// to the current time on every save until one is set.
				if ( $field === 'post_date' ) {
					if ( ! $source_is_default_lang || self::date_floats( $source ) ) {
						continue;
					}

					// post_date needs post_date_gmt as well, plus edit_date=true:
					// wp_update_post() ignores an explicit date on an existing
					// post unless edit_date is set, so without it the sibling
					// silently keeps its own publish date.
					$update['post_date']     = $source->post_date;
					$update['post_date_gmt'] = $source->post_date_gmt;
					$update['edit_date']     = true;
					continue;
				}

				$update[ $prop ] = $source->$prop;
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
				if ( $dry_run ) {
					return true;
				}

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
			} elseif ( $identical_fields_dropped && ! $dry_run ) {
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

			if ( $dry_run ) {
				$image_id = $thumbnail_id ? $this->manager->attachment_in_language( (int) $thumbnail_id, $target_lang_slug ) : 0;

				if ( $image_id !== (int) get_post_thumbnail_id( $target_id ) ) {
					return true;
				}
			} elseif ( $thumbnail_id ) {
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
			$seed_set           = array_fill_keys( $seed_only, true );
			$cleared            = null;
			$sensitive_patterns = null;

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

					if ( $dry_run ) {
						return true;
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
				// Replicate EVERY row, not just the first - multi-value meta
				// (add_post_meta(..., false)) was being collapsed to a single
				// value on the sibling, permanently dropping the rest. Mirror
				// copy_post_meta(): clear the target, then re-add each source
				// row. An absent or single empty-string source clears the
				// target (preserving the prior single-value behaviour).
				$values = get_post_meta( $source_id, $field, false );

				// Change detection: skip the delete/re-add (and the
				// after_mirror cache purge this key would trigger) when the
				// sibling already holds identical rows - a title typo fix
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

				$source_lacks = $values === [] || ( count( $values ) === 1 && $values[0] === '' );

				// A credential-named key is never deleted from a member because
				// the saved post lacks it. A new translation never receives
				// such a key, so its first save would otherwise delete the
				// original's credential.
				if ( $source_lacks ) {
					$sensitive_patterns ??= PostTranslationManager::sensitive_meta_patterns();

					if ( PostTranslationManager::is_sensitive_meta_key( (string) $field, $sensitive_patterns ) ) {
						continue;
					}
				}

				// Removing rows that hold no value (one empty row) changes no
				// value the sibling shows.
				if ( $dry_run ) {
					if ( ! $source_lacks || ( is_array( $target_values ) && self::holds_value( array_values( $target_values ) ) ) ) {
						return true;
					}

					continue;
				}

				$mirrored_meta_keys[] = $field;

				if ( $source_lacks ) {
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
		// Oxygen/Beaver) but leaves the sibling's GENERATED CSS/asset caches -
		// keyed to the pre-sync layout - untouched, so the translated page keeps
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

		return false;
	}
}
