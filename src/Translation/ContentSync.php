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
 * Keeps the configured Sync Fields equal across the language versions of a post.
 *
 * The featured image and Sync Fields meta keys also follow writes made without a post save, except during a
 * front-end page view. The post password is not a Sync Field: it follows the default-language member (sync_password()).
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
	 * Cache manager: a sibling whose row needed no write still gets the `perflocale/cache/flush_object` purge.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Cross-request sync lock TTL (seconds).
	 *
	 * Outlives a normal sync; a crashed request blocks later saves only this long.
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
	 * Most posts one per-request state array tracks; past it the oldest entry is dropped, or synced at once (deferred writes).
	 */
	private const DEFERRED_CAP = 200;

	/**
	 * Most rounds of one change or delete of a post's stale mark.
	 *
	 * A round is lost only when another request changed the mark meanwhile.
	 */
	private const STALE_MARK_ROUNDS = 50;

	/**
	 * Depth of sibling writes the sync itself makes; meta writes seen above zero start nothing.
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Depth of meta writes that start no sync (suspend_meta_write_sync()): an import gives each post the file's own value.
	 *
	 * @var int
	 */
	private static int $suspended = 0;

	/**
	 * Posts inside a save ("{blog_id}:{post_id}" => true), from `pre_post_update`, `save_post` or the edit-screen
	 * referer check until `wp_after_insert_post`; a synced meta write on one waits for the save.
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
	 * Posts being permanently deleted ("{blog_id}:{post_id}" => true), until `deleted_post`.
	 *
	 * Core removes their meta rows one by one on the way out; those removals reach no sibling.
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
	 * Posts a person edits in this request ("{blog_id}:{post_id}" => true): an edit screen's or host form's nonce check
	 * passed, or a cookie-signed REST write names the post. Only a clear made on a listed post enters the seed-cleared marker.
	 *
	 * @var array<string, true>
	 */
	private static array $person_edits = [];

	/**
	 * Siblings a save skipped because the saver may not edit them, where the write would have changed a value
	 * ("{blog_id}:{post_id}" of the saved post => sibling id => true).
	 *
	 * @var array<string, array<int, true>>
	 */
	private static array $skipped = [];

	/**
	 * Siblings skipped by a save that is not complete yet, compared once it is (run_skip_check()).
	 *
	 * A REST save writes the featured image and meta after save_post, so the saved values are final only then.
	 *
	 * @var array<string, array{sync: self, fields: array<string>, default: bool, seed: array<string>, siblings: array<int, string>, mark: array<int, true>, saved: array<int, true>}>
	 */
	private static array $skip_checks = [];

	/**
	 * Translations a Bulk Edit in this request skipped although a value would have changed
	 * ("{blog_id}:{sibling_id}" => true, each once); null when no bulk action ran. Bounded by one list screen.
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $bulk_skipped = null;

	/**
	 * Scheduled posts WordPress publishes in this request without a person, from `future_to_publish` to the end of
	 * that save ("{blog_id}:{post_id}" => author id and the resolved gating user, 0 for none).
	 *
	 * @var array<string, array{author: int, user: int|null}>
	 */
	private static array $scheduled_publishes = [];

	/**
	 * Posts whose save syncs once complete under the clearing rule (close_save(), sync_cleared()): a person's save that
	 * clears the post's stale mark, or a block editor meta-box request whose form names a cleared mark.
	 *
	 * @var array<string, true>
	 */
	private static array $clearing = [];

	/**
	 * The stale mark a person's save clears, as read at its `save_post`, until its sync has run.
	 *
	 * That sync clears it (settle_stale_mark()), adds its pending fields and names it in the held-values row (held_mark_id()).
	 *
	 * @var array<string, array{status: string, at: string, pending: list<string>}>
	 */
	private static array $cleared_marks = [];

	/**
	 * Posts whose clearing save also changed their content while the mark kept "needs update" (keeps_needs_update()).
	 *
	 * That content change settles the link once the mark is cleared (settle_stale_mark()).
	 *
	 * @var array<string, true>
	 */
	private static array $settled_content = [];

	/**
	 * Held values a block editor meta-box request names (field => value hash, as in SYNC_HELD_META), until its sync has run.
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
	 * Meta keys a person's save wrote before it completed: key => rows before the first write; true when unreadable
	 * without a query and a write changed it; false until such a write is confirmed (note_person_write_before()).
	 * False for the whole post when its filled meta cache shows no stale mark.
	 *
	 * @var array<string, array<string, array<int|string, mixed>|bool>|false>
	 */
	private static array $person_writes = [];

	/**
	 * Stale marks read in this request (as stale_mark() returns them, null for none).
	 *
	 * Dropped on every write of the mark in this process and on `clean_post_cache`; a change or delete reads the table.
	 *
	 * @var array<string, array{status: string, at: string, pending: list<string>}|null>
	 */
	private static array $stale_marks = [];

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

		// Term edits sync to the sibling terms too.
		add_action( 'edited_term', [ $this, 'sync_on_term_edit' ], 20, 3 );

		// The featured image and Sync Fields meta also change without a post save or after it (set_post_thumbnail(),
		// WooCommerce CRUD, REST `featured_media` / `meta`): each such write syncs that one field.
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

		// A post whose caches core drops reads its stale mark again.
		add_action( 'clean_post_cache', [ $this, 'forget_cleaned_post' ], 10, 1 );
	}

	/**
	 * See SyncMeta::is_page_view().
	 *
	 * @return bool
	 */
	public static function is_page_view(): bool {
		return SyncMeta::is_page_view();
	}

	/**
	 * See SyncMeta::track_seed_clears().
	 *
	 * @param array<int, mixed> $keys Meta keys.
	 * @return void
	 */
	public static function track_seed_clears( array $keys ): void {
		SyncMeta::track_seed_clears( $keys );
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
	 * Add one key to a post's seed-cleared marker, or remove it; writes only on change.
	 *
	 * Added only while a person edits the post, so an empty from code, imports or jobs is still filled by the seed and
	 * machine translation. One row per key, so concurrent writers of different keys cannot overwrite each other.
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
	 * `add_post_meta`: a tracked key that gets a value leaves the seed-cleared marker.
	 *
	 * Checked only while the post's meta is cached, so a bulk insert pays no marker read per row; an entry left on a
	 * field that holds a value is harmless, as the seed and machine translation leave a value alone.
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
	 * `update_post_meta`: a tracked key saved empty after holding a value is cleared; saved with a value, it is not.
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
	 * `delete_post_meta`: a tracked key whose every row is removed while one held a value is cleared.
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
		if ( ! is_string( $meta_key ) || ! SyncMeta::is_seed_clear_tracked( $meta_key ) || ! is_numeric( $object_id ) || (int) $object_id <= 0 ) {
			return 0;
		}

		$post_id = (int) $object_id;

		if ( self::$writing > 0 || PostTranslationManager::is_creating()
			|| isset( $this->deleting[ $this->state_key( $post_id ) ] ) || SyncMeta::is_page_view() ) {
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
	 * Before a meta write on a post a person edits, note the key's rows from before this save first wrote it (changed_in_save()).
	 *
	 * Rows come only from a filled meta cache, never a query; otherwise the key counts as changed once a write lands.
	 * A post with no stale mark in its filled cache, whose mark this request did not clear, is not followed further.
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
	 * After a meta write on a post a person edits: a key whose earlier rows were unread counts as changed.
	 *
	 * Core reports a write only when it changed a row.
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
	 * `post_updated`: keep the Sync Fields columns of a post a person saves as they were before the save.
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
	 * Keep a post's current Sync Fields columns as the row before a save about to update it; the first row kept wins.
	 *
	 * For forms whose nonce check, and so open_person_edit(), comes after core's `post_updated` (Meta Box front end).
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
	 * The Sync Fields among $fields that a person's save of a post changed (columns and meta rows against before the save).
	 *
	 * Meta the save writes before `save_post` (`meta_input`, the featured image argument) counts as the person's change.
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
	 * Mark a post as being saved from its edit screen; a passed check also marks a person editing it.
	 *
	 * The classic editor writes custom fields right after this check, before wp_update_post().
	 *
	 * @param int|string|mixed $action Nonce action being checked.
	 * @param int|false|mixed  $result Nonce check result: 1 or 2 when valid.
	 * @return void
	 */
	public function open_edit_screen_save( mixed $action = '', mixed $result = false ): void {
		// Bulk actions pass this check before Bulk Edit saves its posts: start the skip count (bulk_skipped()).
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
	 * `rest_request_before_callbacks`: a write signed by the login cookie and REST nonce marks the post it names as edited by a person.
	 *
	 * Application passwords and other API clients mark nothing.
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
	 * Mark a post as edited by a person in this request, once an editing form's nonce check for it passed.
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
	 * Whether a logged-in person is editing this post: marked by open_person_edit(), editable by them, not cron.
	 *
	 * WP-CLI, imports, jobs and plain code never pass an editing nonce or a cookie-signed REST write.
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
	 * Record a sibling a save of $post_id skipped although the write would have changed one of its values.
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
	 * Note a sibling a save of $post_id skipped because the saver may not edit it.
	 *
	 * It counts only when the write would have changed one of its values, judged against the saved post's final
	 * values: while that save is open the sibling waits in its check (run_skip_check()).
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
	 * Count each sibling a save skipped that a write would have changed, compared on the save's blog.
	 *
	 * Marks counted siblings, or the saved post for an unreached original, stale as noted; reads only the post and meta caches.
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
	 * How many siblings the saves of a post in this request skipped where a write would have changed a value.
	 *
	 * Read by the save notices; a save that has not completed is judged as it stands.
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
	 * How many translations a Bulk Edit in this request did not reach, each once; null when no bulk action ran.
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
	 * `rest_request_after_callbacks`: a core posts-endpoint write answers with `perflocale_sync_skipped` (skipped_siblings()).
	 *
	 * The editor keeps the last answer on the post record, so every save answers, 0 included.
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
	 * `future_to_publish`: note a scheduled post WordPress publishes without a person (cron, or a visitor's request).
	 *
	 * Its Sync Fields are then gated as its author's save (scheduled_publish_user()); a save with no editing user is
	 * otherwise ungated. A person who may edit the post is gated as that person; WP-CLI outside cron stays ungated.
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
	 * The user gating a scheduled publish without a person, 0 for none; null when this save is not one.
	 *
	 * Default: the author when the author may edit the post, else 0, so a Contributor's post an Editor scheduled still
	 * mirrors to the whole group. Resolved once per publish.
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
		 * Filters the user whose rights gate the Sync Fields of a scheduled post WordPress publishes without a person.
		 *
		 * Sync Fields and a changed password then reach only the translations this user may edit; 0 reaches every one.
		 * Runs once per such publish that syncs; a return that is not an integer of 0 or more keeps the default.
		 *
		 * @hook  perflocale/sync/scheduled_publish_user
		 * @since 1.0.7.1
		 *
		 * @param int $user_id User whose rights gate the publish, 0 for none.
		 *                     Default: the author when the author may edit the post, otherwise 0.
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
	 * A post's save is complete: run its clearing sync or the writes that waited for it, then its skip check.
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
	 * `wp_after_insert_post`: a changed password of a group's default-language member reaches each translation holding the old one.
	 *
	 * A translation with its own password or a private one is left alone, as is a private source (core empties its
	 * password); the write gate applies. A skipped translation is neither marked stale nor counted: its password never
	 * leaves it. See docs-ai/11-code-rationale.md, "ContentSync: post password".
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

				// Slashed input. An empty page_template keeps the stored one: a template missing from the theme stops the save.
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
	 * Keep every column of a post but $columns and its modified dates as stored in its next wp_update_post() call.
	 *
	 * The update would otherwise run the row through the saver's filters (KSES), reset a draft's date and publish a
	 * past-due scheduled post. The modified dates move so sitemaps, caches and REST modified_after see the change.
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
	 * Mark a post as being permanently deleted; its waiting writes and skip check are dropped.
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
	 * Run the deferred and clearing syncs whose save never completed (an insert with `$fire_after_hooks = false`).
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
	 * The Sync Field a meta key stands for when Sync Fields lists it (`featured_image` for `_thumbnail_id`), else null.
	 *
	 * @param string $meta_key Meta key written.
	 * @return string|null
	 */
	private function configured_field_for_meta_key( string $meta_key ): ?string {
		// The plugin's own keys are never synced, whatever lists them.
		if ( SyncMeta::is_never_synced( $meta_key ) ) {
			return null;
		}

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
	 * Meta writes until resume_meta_write_sync() reach no sibling and mark nothing stale; calls nest (DataImporter).
	 *
	 * @return void
	 */
	public static function suspend_meta_write_sync(): void {
		++self::$suspended;
	}

	/**
	 * End one suspend_meta_write_sync().
	 *
	 * @return void
	 */
	public static function resume_meta_write_sync(): void {
		self::$suspended = max( 0, self::$suspended - 1 );
	}

	/**
	 * Sync one field after a post-meta write that no post save covers.
	 *
	 * Only the featured image and Sync Fields meta keys, mirrored as a save would write them; seed-only keys wait for
	 * the next save. The sync's own writes, translation creation, a post's delete and page views start nothing.
	 *
	 * @param int|int[]|mixed $meta_ids  Meta ID(s) written (unused).
	 * @param int|mixed       $object_id Post ID.
	 * @param string|mixed    $meta_key  Meta key.
	 * @return void
	 */
	public function on_meta_write( mixed $meta_ids = 0, mixed $object_id = 0, mixed $meta_key = '' ): void {
		// Core passes no post for a delete across all posts: every read
		// mark is dropped.
		if ( self::SYNC_STALE_META === $meta_key ) {
			self::forget_stale_mark( is_numeric( $object_id ) ? (int) $object_id : 0 );
		}

		if ( self::$writing > 0 || self::$suspended > 0 || ! is_string( $meta_key ) || ! is_numeric( $object_id ) || (int) $object_id <= 0 ) {
			return;
		}

		self::note_person_write_after( $object_id, $meta_key );

		$field = $this->configured_field_for_meta_key( $meta_key );

		if ( $field === null || PostTranslationManager::is_creating() || SyncMeta::is_page_view() ) {
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
	 * See SyncMeta::SYNC_OPTOUT_META.
	 */
	public const SYNC_OPTOUT_META = SyncMeta::SYNC_OPTOUT_META;

	/**
	 * See SyncMeta::SEED_CLEARED_META.
	 */
	public const SEED_CLEARED_META = SyncMeta::SEED_CLEARED_META;

	/**
	 * See SyncMeta::SYNC_STALE_META.
	 */
	public const SYNC_STALE_META = SyncMeta::SYNC_STALE_META;

	/**
	 * See SyncMeta::SYNC_HELD_META.
	 */
	public const SYNC_HELD_META = SyncMeta::SYNC_HELD_META;

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
	 * when the post is not marked (stale_mark()).
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private static function stale_restore( int $post_id ): ?string {
		$mark = self::stale_mark( $post_id );

		return null === $mark ? null : $mark['status'];
	}

	/**
	 * A post's stale mark (status to restore, link write time at marking, pending fields), or null when unmarked.
	 *
	 * Read once per request from the post's meta cache, then from $stale_marks.
	 *
	 * @param int $post_id Post ID.
	 * @return array{status: string, at: string, pending: list<string>}|null
	 */
	private static function stale_mark( int $post_id ): ?array {
		$key = get_current_blog_id() . ':' . $post_id;

		if ( array_key_exists( $key, self::$stale_marks ) ) {
			return self::$stale_marks[ $key ];
		}

		if ( count( self::$stale_marks ) >= self::DEFERRED_CAP ) {
			unset( self::$stale_marks[ array_key_first( self::$stale_marks ) ] );
		}

		self::$stale_marks[ $key ] = self::read_stale_mark( $post_id );

		return self::$stale_marks[ $key ];
	}

	/**
	 * Drop a post's read stale mark on the current blog, or every read mark for post 0.
	 *
	 * @param int $post_id Post ID, 0 for every post.
	 * @return void
	 */
	private static function forget_stale_mark( int $post_id ): void {
		if ( $post_id > 0 ) {
			unset( self::$stale_marks[ get_current_blog_id() . ':' . $post_id ] );
		} else {
			self::$stale_marks = [];
		}
	}

	/**
	 * `clean_post_cache`: a post whose caches core drops reads its stale mark again.
	 *
	 * @param int|mixed $post_id Post ID.
	 * @return void
	 */
	public function forget_cleaned_post( mixed $post_id = 0 ): void {
		if ( self::$stale_marks !== [] && is_numeric( $post_id ) && (int) $post_id > 0 ) {
			self::forget_stale_mark( (int) $post_id );
		}
	}

	/**
	 * A post's stale mark as its meta cache holds it.
	 *
	 * A loaded cache is read directly: the key is private, so no `get_post_metadata` filter needs to run for it.
	 *
	 * @param int $post_id Post ID.
	 * @return array{status: string, at: string, pending: list<string>}|null
	 */
	private static function read_stale_mark( int $post_id ): ?array {
		$meta = wp_cache_get( $post_id, 'post_meta' );

		if ( is_array( $meta ) ) {
			$rows = $meta[ self::SYNC_STALE_META ] ?? [];
			$rows = is_array( $rows ) ? array_map( static fn( $row ) => is_string( $row ) ? maybe_unserialize( $row ) : $row, $rows ) : [];
		} else {
			$rows = get_post_meta( $post_id, self::SYNC_STALE_META, false );
		}

		if ( ! is_array( $rows ) || $rows === [] ) {
			return null;
		}

		return self::parse_stale_mark( reset( $rows ) );
	}

	/**
	 * A stale mark's stored value in the form stale_mark() returns.
	 *
	 * @param mixed $mark Unserialized value of the mark's row.
	 * @return array{status: string, at: string, pending: list<string>}
	 */
	private static function parse_stale_mark( mixed $mark ): array {
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
	 * Set the link status and write time a post's stale mark stores, keeping its pending fields; never adds a mark.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Link status the mark restores.
	 * @param string $at      Link write time the mark was set at, '' when not known.
	 * @return void
	 */
	private static function write_stale_mark( int $post_id, string $status, string $at ): void {
		self::change_stale_mark(
			$post_id,
			static function ( array $mark ) use ( $status, $at ): ?array {
				if ( $status === $mark['status'] && $at === $mark['at'] ) {
					return null;
				}

				$mark['status'] = $status;
				$mark['at']     = $at;

				return $mark;
			}
		);
	}

	/**
	 * Change a post's stale mark with a compare-and-set on its row.
	 *
	 * A change another request made in between (a pending field) is never overwritten: the row is read and transformed
	 * again. Nothing is inserted, so a cleared mark stays cleared.
	 * See docs-ai/11-code-rationale.md, "ContentSync: stale mark compare-and-set".
	 *
	 * @param int      $post_id   Post ID.
	 * @param callable $transform Given the mark as read, returns the mark after the change, or null
	 *                            when nothing is to change.
	 * @phpstan-param callable(array{status: string, at: string, pending: list<string>}): (array{status: string, at: string, pending: list<string>}|null) $transform
	 * @return bool True once the row holds the changed mark or nothing had to change; false otherwise.
	 */
	private static function change_stale_mark( int $post_id, callable $transform ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$done   = false;
		$missed = null;

		for ( $round = 0; $round < self::STALE_MARK_ROUNDS; $round++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The mark's row itself, past the meta cache, for the compare-and-set below.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT meta_id, meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1', $wpdb->postmeta, $post_id, self::SYNC_STALE_META ) );

			if ( ! is_object( $row ) || ! isset( $row->meta_id, $row->meta_value ) || ! is_numeric( $row->meta_id ) || ! is_string( $row->meta_value ) ) {
				break;
			}

			$meta_id = (int) $row->meta_id;
			$read    = $row->meta_value;

			// The last write missed a row that did not change since: the
			// compare-and-set cannot match it.
			if ( [ $meta_id, $read ] === $missed ) {
				break;
			}

			$after = $transform( self::parse_stale_mark( maybe_unserialize( $read ) ) );

			if ( null === $after ) {
				$done = true;
				break;
			}

			$mark = [
				'status' => $after['status'],
				'at'     => $after['at'],
			];

			if ( $after['pending'] !== [] ) {
				$mark['pending'] = $after['pending'];
			}

			$value = maybe_serialize( $mark );

			if ( $value === $read ) {
				$done = true;
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set write of the mark; the post's meta cache is dropped below.
			$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->postmeta, $value, $meta_id, $read ) ?? '' );

			if ( 1 === $affected ) {
				$done = true;
				wp_cache_set_posts_last_changed();
				break;
			}

			if ( 0 !== $affected ) {
				break;
			}

			$missed = [ $meta_id, $read ];
		}

		wp_cache_delete( $post_id, 'post_meta' );
		self::forget_stale_mark( $post_id );

		return $done;
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
	 * Mark a translation stale and set its link to "needs update"; the mark stores the status read and the new write time.
	 *
	 * The link write is a compare-and-set on the cached status: a content flag that landed first stays and the mark
	 * stores it; a link that keeps changing gets "needs update" and the mark restores nothing.
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
			self::forget_stale_mark( $post_id );
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
		self::forget_stale_mark( $post_id );

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
			self::forget_stale_mark( $post_id );
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
	 * Clear a post's stale mark and restore its link's stored status while the link still holds the mark's write time.
	 *
	 * A link written since (a content change of the original) keeps "needs update". The status is restored only when
	 * this call deleted a row: a request that cleared the mark first restored it there.
	 *
	 * @param int                                                      $post_id Post ID.
	 * @param array{status: string, at: string, pending: list<string>} $mark    The post's stale mark.
	 * @return list<string> The Sync Fields the deleted rows kept as pending.
	 */
	private static function clear_stale( int $post_id, array $mark ): array {
		$pending = self::delete_stale_mark( $post_id );

		if ( null === $pending ) {
			return [];
		}

		$needs   = \PerfLocale\Enum\TranslationStatus::NeedsUpdate->value;
		$restore = $mark['status'];

		if ( $needs === $restore || '' === $mark['at'] || null === \PerfLocale\Enum\TranslationStatus::tryFrom( $restore ) ) {
			return $pending;
		}

		$link = self::own_link( $post_id );

		if ( null !== $link ) {
			\PerfLocale\Plugin::get_instance()->group_repo()->update_link_status( $post_id, $link['language_id'], $restore, '', \PerfLocale\Enum\ObjectType::Post, $needs, $mark['at'] );
		}

		return $pending;
	}

	/**
	 * Delete a post's stale mark rows with a compare-and-set on each row.
	 *
	 * A row changed in between (a pending field) is read again so its pending fields are returned too; a row still
	 * changing when the rounds run out stays for the next clearing save.
	 * See docs-ai/11-code-rationale.md, "ContentSync: stale mark compare-and-set".
	 *
	 * @param int $post_id Post ID.
	 * @return list<string>|null The pending Sync Fields of the deleted rows, or null when none was deleted.
	 */
	private static function delete_stale_mark( int $post_id ): ?array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$pending = [];
		$deleted = false;
		$missed  = [];

		for ( $round = 0; $round < self::STALE_MARK_ROUNDS; $round++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The mark's rows themselves, past the meta cache, for the compare-and-set below.
			$rows      = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id', $wpdb->postmeta, $post_id, self::SYNC_STALE_META ) );
			$unchanged = $missed;
			$missed    = [];

			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				if ( ! isset( $row->meta_id, $row->meta_value ) || ! is_numeric( $row->meta_id ) || ! is_string( $row->meta_value ) ) {
					continue;
				}

				$meta_id = (int) $row->meta_id;
				$read    = $row->meta_value;
				$pending = array_values( array_unique( array_merge( $pending, self::parse_stale_mark( maybe_unserialize( $read ) )['pending'] ) ) );

				if ( ( $unchanged[ $meta_id ] ?? null ) === $read ) {
					// The last delete missed this row and it did not change
					// since: the compare-and-set cannot match it.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row by meta_id, holding the value read; the post's meta cache is dropped below.
					$affected = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_id = %d', $wpdb->postmeta, $meta_id ) ?? '' );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set delete of the mark; the post's meta cache is dropped below.
					$affected = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_id = %d AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->postmeta, $meta_id, $read ) ?? '' );
				}

				if ( 1 === $affected ) {
					$deleted = true;
				} elseif ( 0 === $affected ) {
					$missed[ $meta_id ] = $read;
				}
			}

			if ( [] === $missed ) {
				break;
			}
		}

		wp_cache_delete( $post_id, 'post_meta' );
		self::forget_stale_mark( $post_id );

		if ( ! $deleted ) {
			return null;
		}

		wp_cache_set_posts_last_changed();

		return $pending;
	}

	/**
	 * Clear the stale mark a person's save clears and restore the link's stored status (clear_stale()).
	 *
	 * Pending fields of the deleted rows join this save's sync. A content change by the same save then settles the
	 * link as on any changed translation: "needs update" becomes published (ContentChangeDetector).
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

		// A field a lock-refused save added to the mark after this save read it counts as changed here too.
		// That save wrote the post after this one loaded it, so the post's caches are dropped.
		$pending = array_values( array_unique( array_merge( $mark['pending'], self::clear_stale( $post_id, $mark ) ) ) );

		if ( $pending !== $mark['pending'] ) {
			self::$cleared_marks[ $key ]['pending'] = $pending;
			clean_post_cache( $post_id );
		}

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
	 * Whether the save of a post marked stale must copy none of its Sync Fields, its values being older than the group's.
	 *
	 * A person's clearing save (person_save_clears_stale()) is not held back: it syncs once complete.
	 *
	 * @param int $post_id Post being saved.
	 * @return bool
	 */
	private function holds_stale_values( int $post_id ): bool {
		return null !== self::stale_restore( $post_id );
	}

	/**
	 * Whether a person's save of a post clears its stale mark.
	 *
	 * The person saves from an editing screen, may edit the group's original and the pre-save row is known. The save's
	 * sync clears the mark only once nothing can stop it (settle_stale_mark()), so a sync that cannot run leaves both.
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
	 * `block_editor_meta_box_hidden_fields`: the meta-box form of a translation marked stale names the mark.
	 *
	 * Core posts that form after each save, with the values from before the save that clears the mark.
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
	 * Whether a person's save is a block editor meta-box request rendered under a mark an earlier save cleared.
	 *
	 * Its held values go to the save's sync, where a field sent back with its held value counts as unchanged.
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
	 * A Sync Field's value on a post as the held-values row stores it: a labelled hash of the column or serialized rows.
	 *
	 * Only equality is asked of it.
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
	 * Whether a translation keeps "needs update" because it is marked stale; asked by ContentChangeDetector.
	 *
	 * The content change still settles every flag up to the link's last write: the mark's stored "needs update" becomes
	 * published and it takes the link's write time, so only a later content change of the original keeps the flag.
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
			self::write_stale_mark( $post_id, $status, $at );
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
	 * Only that member moves `post_parent`, the publish date and seed-only keys: a parentless translation would move
	 * the source to the root and change its live URL with no redirect (docs-ai/04-do-not-fix.md 2.16).
	 *
	 * @param int                $post_id      Post being saved.
	 * @param array<string, int> $translations language_slug => post_id map for the group.
	 * @return bool True when the post is the group's default-language member, or when no default language is set.
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

		// A group without its default-language member (source deleted) has no hierarchy to copy: nothing moves.
		return ( $translations[ $default_slug ] ?? 0 ) === $post_id;
	}

	/**
	 * Whether a post's date floats: a date_floating status (draft, pending) and no GMT date, so core restamps each save.
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
	 * @param \WP_Post|null $post    Post object; null when the row is gone or save_post fired with one argument.
	 * @return void
	 */
	public function sync_on_save( int $post_id, ?\WP_Post $post = null ): void {
		// Core hands over its re-read row, null when the post was deleted meanwhile; some plugins pass one argument.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// This save syncs every field as it stands now, so meta writes that
		// were waiting for it are covered.
		$key = $this->state_key( $post_id );
		unset( $this->deferred[ $key ] );

		// A clearing save, or a meta-box request showing held values, syncs once the save is complete: REST writes
		// the featured image and meta after save_post.
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
		// A clearing sync clears the mark once nothing can stop it: a check below ends it, or the lock is taken.
		// While another request holds the lock the mark stays and the changed fields join it (keep_pending_changes()).
		$settle = $clearing && isset( self::$cleared_marks[ $this->state_key( $post_id ) ] );

		// Registered on every blog so a request that switches blogs syncs there; empty Sync Fields sync nothing.
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

			// Addon translatable keys (builder layouts among them) join the site's Sync Fields.
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
			 * Expand per-post meta keys the post-type list cannot enumerate, such as ACF repeater rows past row 0.
			 *
			 * Receives the source post id so an addon can read its real row structure.
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
			 * Meta keys that keep FULL MIRROR semantics: a save overwrites the siblings' rows and a delete clears them.
			 *
			 * ⚠️ Never list a key that holds translatable text: the mirror is bidirectional, so a translator's save
			 * overwrites the source. Other addon translatable keys are seed-only.
			 * See docs-ai/11-code-rationale.md, "ContentSync: mirror and seed-only keys".
			 *
			 * @hook  perflocale/sync/mirror_meta_keys
			 * @since 1.0.0
			 *
			 * @param array<int, string> $mirror_keys Keys with full mirror semantics.
			 * @param string             $post_type   Post type being synced.
			 */
			$mirror_keys = (array) apply_filters( 'perflocale/sync/mirror_meta_keys', $user_fields, $post->post_type );

			// Seed-only: addon translatable keys plus per-post expansions, minus mirror keys.
			// Keys added through `perflocale/sync_fields` stay mirror.
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

			// Seed-only keys named like credentials never travel; they leave the whole synced set, since dropped from
			// $seed_only alone they would fall through to the mirror. Mirror keys are the site's choice and not gated.
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

			// Once per save: post_parent, the publish date and seed-only keys honour it; other fields are bidirectional.
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

		// Templates are not content, and this runs on every Site Editor save: stop before any lock.
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

		// Symmetric opt-out: the mirror is bidirectional, so an opted-out translation must not push to its group either.
		if ( $this->is_sync_opted_out( $post_id ) ) {
			return false;
		}

		// A save of a post with no translations reaches nothing; read after the opt-out so an opted-out post reads
		// nothing. The sync's own saves skip it: the post's lock is already held, so they stop there.
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
	 * A clearing save could not take the post's lock: keep the fields it changed with the mark (`pending`).
	 *
	 * The save that clears the mark copies them too, so the person's change is not replaced by the group's value.
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

		self::change_stale_mark(
			$post_id,
			static function ( array $mark ) use ( $changed ): ?array {
				$pending = array_values( array_unique( array_merge( $mark['pending'], $changed ) ) );

				if ( $pending === $mark['pending'] ) {
					return null;
				}

				$mark['pending'] = $pending;

				return $mark;
			}
		);
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
	 * The sync of a clearing save or held-values meta-box request, once the save is complete, under the post's lock.
	 *
	 * The fields the person changed go to the group, gated as their save; the post takes the group's values for the
	 * rest (group_values_member()), so held-back values never reach the group. Replaced values go to SYNC_HELD_META.
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
	 * Keep the Sync Fields values a clearing save replaced in the post's held-values row; none replaced removes the row.
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
	 * The member holding the group's current Sync Fields values: the default-language member, else the first other
	 * member neither opted out nor marked stale; 0 when none qualifies.
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
	 * Whether a save writes only to the group members a user may edit, and that user (null: the current user).
	 *
	 * Gated: a non-cron save by a logged-in user who may edit the post, and a scheduled publish whose gating user is
	 * set. Any other save is the site's own write (WP-CLI, imports, cron, a checkout updating a product).
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
		// ⚠️ A gated saver writes only the siblings they may edit. A skipped sibling a write would change is counted and,
		// when the original holds the saved values, marked stale; a skipped original marks the saved post instead.
		// See docs-ai/11-code-rationale.md, "ContentSync: write gate and stale marks".
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
	 * Only the parent, mapped to each language's own parent, and only from the default-language term; name, slug and
	 * description are translator-owned.
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

			// Hierarchy travels one way (docs-ai/04-do-not-fix.md 2.16): a new term translation sits at parent 0, and the
			// bulk term pass renames it in the same request, which would write that 0 back onto the source term.
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
					// Point the sibling at the parent's translation in its OWN language; an untranslated parent stays.
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

						// No parent translation in the sibling's language: keep its parent, never a cross-language one.
						if ( $translated_parent === 0 ) {
							$update_parent = false;
						}
					}

					/**
					 * Whether to sync this term's parent onto a specific translation sibling.
					 *
					 * Return false to keep the sibling's own hierarchy (per-language category trees).
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
	 * @param string        $target_lang_slug Target post's language slug, to map post_parent into that language.
	 * @param bool          $source_is_default_lang Whether the saved post is its group's default-language member,
	 *            the only one that moves hierarchy and publish date or seeds.
	 * @param array<string> $seed_only Seed-only meta keys: copied only to a sibling with no rows and no recorded
	 *            clear (SEED_CLEARED_META); never overwritten or deleted.
	 * @param bool          $dry_run Decide against the sibling's stored values without writing; true at the
	 *            first field a write would change.
	 * @return bool With $dry_run, whether the write would change a value of the sibling; false otherwise.
	 */
	private function sync_fields_to_post( int $source_id, int $target_id, array $fields, string $target_lang_slug, bool $source_is_default_lang, array $seed_only = [], bool $dry_run = false ): bool {
		// The plugin's own meta keys are never mirrored or seeded, whatever listed them; every sibling write passes here.
		$internal  = static function ( mixed $field ): bool {
			return ! ( is_string( $field ) && SyncMeta::is_never_synced( $field ) );
		};
		$fields    = array_values( array_filter( $fields, $internal ) );
		$seed_only = array_values( array_filter( $seed_only, $internal ) );

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

				// post_parent points at the parent's translation in the sibling's OWN language; an untranslated parent
				// stays. Same rule and direction as sync_on_term_edit().
				if ( $field === 'post_parent' ) {
					// Only the default-language member moves the group (docs-ai/04-do-not-fix.md 2.16); skip just
					// this field so the other synced fields still travel.
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

				// The publish date is the default-language member's: a translation's date (stamped when published or
				// created) would move the original's date and, with date permalinks, its URL. A floating date moves nothing.
				if ( $field === 'post_date' ) {
					if ( ! $source_is_default_lang || self::date_floats( $source ) ) {
						continue;
					}

					// With post_date_gmt and edit_date: wp_update_post() ignores an existing post's date without edit_date.
					$update['post_date']     = $source->post_date;
					$update['post_date_gmt'] = $source->post_date_gmt;
					$update['edit_date']     = true;
					continue;
				}

				$update[ $prop ] = $source->$prop;
			}

			// Drop every field the sibling already holds: wp_update_post() rewrites the row and fires the save hooks
			// even when nothing differs. $current is read under the sibling's lock, so it is the row the write merges
			// into. This decides only whether to write.
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

					// wp_insert_post() replaces an empty value of these with its own default (closed, the type's
					// default, the current user), so an empty value is never "already held".
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

					// The date trio is one package: without edit_date wp_update_post() restamps a floating sibling's
					// post_date (wp-includes/post.php). It is dropped only when nothing else is written.
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

			// Write only differing fields; an empty page_template keeps the stored one. A post_parent write carries
			// post_name too: core makes the slug unique among the new parent's children.
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

				// A vetoed update (WooCommerce stock or order-status guards) must not block the thumbnail and meta syncs.
				if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic; one line per vetoed sibling update.
					error_log( sprintf( 'PerfLocale ContentSync: wp_update_post failed for sibling %d: %s', $target_id, $result->get_error_message() ) );
				}
			} elseif ( $identical_fields_dropped && ! $dry_run ) {
				// No write means no save_post purge for this sibling: flush its caches so the
				// `perflocale/cache/flush_object` signal (CDN purges) still arrives.
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

				// SEED-ONLY keys: the translator owns the value. Copy only to a sibling with NO rows for the key; never delete.
				if ( isset( $seed_set[ $field ] ) ) {
					// Only from the default-language member: a value first typed on a translation is in its language.
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
						 * Filters one seeded row before it is copied to a sibling that has no rows for the key.
						 *
						 * The Meta Box add-on takes a group's password sub-fields out here.
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

				// MIRROR keys: clear the target and re-add every source row. An absent or single empty-string source
				// deletes the target's rows by design (docs-ai/04-do-not-fix.md 2.15).
				$values = get_post_meta( $source_id, $field, false );

				// Skip identical rows so a title fix rewrites no builder JSON. Compare SERIALIZED forms: builder meta can
				// hold objects (Beaver nodes), which a strict array compare never matches.
				$target_values = get_post_meta( $target_id, $field, false );

				if ( array_map( 'maybe_serialize', $target_values ) === array_map( 'maybe_serialize', $values )
					&& ! ( $target_values !== [] && ( $values === [] || ( count( $values ) === 1 && $values[0] === '' ) ) ) ) {
					continue;
				}

				$source_lacks = $values === [] || ( count( $values ) === 1 && $values[0] === '' );

				// A credential-named key is never deleted because the saved post lacks it: a new translation never
				// receives one, so its first save would delete the original's credential.
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

		// A raw mirror leaves the sibling's generated builder CSS built from the old layout: signal it so an addon drops it.
		if ( $mirrored_meta_keys !== [] ) {
			/**
			 * Fires after full-mirror meta keys have been written to a sibling translation.
			 *
			 * Builder addons drop the sibling's generated CSS/asset caches here (Elementor's `_elementor_css`).
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
