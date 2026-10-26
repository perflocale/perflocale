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
 * Listens to WordPress hooks and invalidates only the cache entries a change affects.
 */
final class CacheInvalidator {

	/**
	 * Cache manager instance.
	 *
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Post links that link_object() reported for the
	 * `perflocale/translation/linked` action it fires next, keyed by blog id
	 * and group id. on_translation_linked() removes the entry it handles.
	 *
	 * @var array<string, array{object: int, members: array<int, int>, displaced: int, left: array<int, int>}>
	 */
	private array $link_changes = [];

	/**
	 * Posts whose save_post is running and has not reached on_save_post()
	 * yet, as "<blog id>:<post id>", the innermost save last.
	 *
	 * @var array<int, string>
	 */
	private array $open_saves = [];

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
		// The gated hooks below need a write context (WP-CLI included); a front-end GET changes no content.
		$is_write_context = is_admin() || wp_doing_ajax() || wp_doing_cron()
			|| ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] )
			|| defined( 'REST_REQUEST' )
			|| ( defined( 'WP_CLI' ) && WP_CLI );

		// Delete cleanup runs in every context: a post or term can be deleted inside a front-end GET, and a
		// missed cleanup orphans rows that reserve the translated slug. Idle unless a delete fires.
		add_action( 'delete_post', [ $this, 'on_delete_post' ] );
		add_action( 'delete_term', [ $this, 'on_delete_term' ], 10, 4 );

		// Term edits in every context too: a cron event inside a GET can rename a term. Priority 999: after
		// other plugins, so the cache reflects the final term.
		add_action( 'edited_term', [ $this, 'on_edited_term' ], 999, 3 );

		// Machine-translated meta lands after the translation's own save. Ungated: jobs also run from WP-Cron
		// inside a visitor's GET.
		add_action( 'perflocale/mt/meta_translated', [ $this, 'on_meta_translated' ], 10, 2 );

		// A post link written outside a post save changes its groups' alternates. Ungated for the same reason;
		// a migration import leaves it to the flush that ends the import.
		add_action( 'perflocale/translation/linked', [ $this, 'on_translation_linked' ] );

		// Ungated: a publication can happen in a front-end GET (a scheduled post on a visitor's request). The
		// handler returns early unless public readability changed.
		add_action( 'transition_post_status', [ $this, 'on_visibility_transition' ], 20, 3 );

		if ( $is_write_context ) {
			// Gated: a publication inside a front-end request is covered by on_visibility_transition(), which
			// clears hreflang itself when this handler is absent. Priority 999: after other plugins.
			add_action( 'save_post', [ $this, 'on_save_post' ], 999, 2 );

			// The post a save_post run is for, until on_save_post() takes it:
			// a group change inside that run leaves its hreflang to the save.
			add_action( 'save_post', [ $this, 'open_save' ], PHP_INT_MIN, 2 );
		}

		// PerfLocale-specific hooks - always needed (fired programmatically).
		add_action( 'perflocale/language/added', [ $this, 'on_language_changed' ] );
		add_action( 'perflocale/language/updated', [ $this, 'on_language_changed' ] );
		add_action( 'perflocale/language/deleted', [ $this, 'on_language_changed' ] );
		// 2 accepted args: on_settings_changed() needs the new and the old settings to tell which keys changed.
		add_action( 'perflocale/settings/updated', [ $this, 'on_settings_changed' ], 10, 2 );

		// Multisite only: switch_to_blog() fires switch_blog on single-site too, so a same-blog switch would flush.
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
		$this->close_save( $post_id );

			// WordPress passes null when the post was deleted meanwhile, and some plugins pass one argument.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->cache->flush_object( $post_id, 'post' );

		// Block templates are never a queried object, so they have no singular hreflang entry: skip the sweep.
		// Must stay after flush_object(): the Site Editor's Translations panel reads `translations_post_*`.
		// Narrow on purpose (is_template_type(), not ! is_post_type_viewable()): no other type changes.
		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( (string) get_post_type( $post_id ) ) ) {
			return;
		}

		// Hreflang is bidirectional: the siblings' cached alternates link back to this post.
		$this->clear_hreflang_for_post_and_siblings( $post_id );
	}

	/**
	 * Clear the hreflang of a post about to be deleted and of its translation
	 * siblings, while its translation link still names them.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function clear_hreflang_before_unlink( int $post_id ): void {
		$this->clear_hreflang_for_post_and_siblings( $post_id );
	}

	/**
	 * Record what a post link changed, for on_translation_linked().
	 *
	 * @internal Called by TranslationGroupRepository::link_object() through the plugin container, before it fires `perflocale/translation/linked`.
	 *
	 * @param int             $group_id        Group the post was linked to.
	 * @param int             $object_id       Linked post.
	 * @param array<int, int> $member_ids      The group's members after the link.
	 * @param int             $displaced_id    Post that held the language in the group, 0 for none.
	 * @param array<int, int> $left_member_ids Members of the groups the post left that still exist.
	 * @return void
	 */
	public function note_link_change( int $group_id, int $object_id, array $member_ids, int $displaced_id, array $left_member_ids ): void {
		$this->link_changes[ get_current_blog_id() . ':' . $group_id ] = [
			'object'    => $object_id,
			'members'   => $member_ids,
			'displaced' => $displaced_id,
			'left'      => $left_member_ids,
		];
	}

	/**
	 * Clear the cached hreflang of the pages a post link changed.
	 *
	 * Acts on the links link_object() recorded with note_link_change(); for
	 * any other `perflocale/translation/linked` (a string translation, for
	 * one) it returns at once.
	 *
	 * @param mixed $group_id Group the object was linked to.
	 * @return void
	 */
	public function on_translation_linked( mixed $group_id = 0 ): void {
		$key = get_current_blog_id() . ':' . ( is_numeric( $group_id ) ? (int) $group_id : 0 );

		if ( ! isset( $this->link_changes[ $key ] ) ) {
			return;
		}

		$change = $this->link_changes[ $key ];
		unset( $this->link_changes[ $key ] );

		$this->clear_hreflang_after_member_change( $change['object'], $change['members'], $change['displaced'], $change['left'] );
	}

	/**
	 * Note the post a save_post run is for, when the run carries the post object.
	 *
	 * Only such a run is cleared by on_save_post(); a run fired with the post ID alone is not noted.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    Post object.
	 * @return void
	 */
	public function open_save( mixed $post_id = 0, mixed $post = null ): void {
		global $wp_current_filter;

		// The outermost save_post run starts with no save open; an entry left
		// over (a save that threw, or ran with on_save_post() detached) is
		// dropped.
		if ( 1 === count( array_keys( (array) $wp_current_filter, 'save_post', true ) ) ) {
			$this->open_saves = [];
		}

		if ( $post instanceof \WP_Post && is_numeric( $post_id ) && (int) $post_id > 0 ) {
			$this->open_saves[] = get_current_blog_id() . ':' . (int) $post_id;
		}
	}

	/**
	 * Remove the innermost open save of a post, as on_save_post() takes it.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function close_save( int $post_id ): void {
		$key = get_current_blog_id() . ':' . $post_id;

		for ( $i = count( $this->open_saves ) - 1; $i >= 0; --$i ) {
			if ( $this->open_saves[ $i ] === $key ) {
				array_splice( $this->open_saves, $i, 1 );

				return;
			}
		}
	}

	/**
	 * Whether the save_post run in progress is this post's and on_save_post()
	 * will still clear the hreflang of the post and its group in it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function save_will_clear( int $post_id ): bool {
		return [] !== $this->open_saves
			&& get_current_blog_id() . ':' . $post_id === $this->open_saves[ count( $this->open_saves ) - 1 ]
			&& doing_action( 'save_post' )
			&& false !== has_action( 'save_post', [ $this, 'on_save_post' ] )
			&& ! wp_is_post_revision( $post_id )
			&& ! wp_is_post_autosave( $post_id );
	}

	/**
	 * Clear the cached hreflang of the pages whose alternates changed when a post joined a group or changed its language in one.
	 *
	 * Pages list their group's published members; inside the post's own save a change that moved no other post
	 * is left to on_save_post(). See docs-ai/11-code-rationale.md, "CacheInvalidator: hreflang after a group change".
	 *
	 * @internal Called by on_translation_linked() and, through the plugin container, by PostTranslationManager::set_post_language().
	 *
	 * @param int             $object_id       The post.
	 * @param array<int, int> $member_ids      Its group's members.
	 * @param int             $displaced_id    Post that left the group to make room for it, 0 for none.
	 * @param array<int, int> $left_member_ids Members of the groups it left that still exist.
	 * @return void
	 */
	public function clear_hreflang_after_member_change( int $object_id, array $member_ids, int $displaced_id = 0, array $left_member_ids = [] ): void {
		// A migration import ends with a flush that clears hreflang. class_exists() does not autoload: a process
		// that has not loaded the runner runs no import.
		if ( class_exists( \PerfLocale\Migration\MigrationRunner::class, false ) && \PerfLocale\Migration\MigrationRunner::importing() ) {
			return;
		}

		if ( \PerfLocale\Translation\BlockTemplateSupport::is_template_type( (string) get_post_type( $object_id ) ) ) {
			return;
		}

		$published = 'publish' === get_post_status( $object_id );
		$post_ids  = [];

		if ( $published || $displaced_id > 0 ) {
			$post_ids = array_merge( [ $object_id, $displaced_id ], $member_ids );
		}

		if ( $published ) {
			$post_ids = array_merge( $post_ids, $left_member_ids );
		}

		$post_ids = array_values( array_unique( array_filter( $post_ids, static fn( int $id ): bool => $id > 0 ) ) );

		if ( [] === $post_ids ) {
			return;
		}

		// A group with the static front page or the posts page retires the whole hreflang group: the posts page
		// renders under a language-keyed bucket no post id addresses.
		$home = $this->has_home_object( $post_ids );

		// The post's own save clears the post and the group it is in when it
		// reaches on_save_post(): the same entries, when no other post left a
		// group and no home object is involved.
		if ( 0 === $displaced_id && [] === $left_member_ids && ! $home && $this->save_will_clear( $object_id ) ) {
			return;
		}

		$this->clear_singular_hreflang( $object_id, \PerfLocale\Enum\ObjectType::Post, 's', $post_ids );

		if ( $home ) {
			$this->cache->invalidate_group( 'perflocale_hreflang' );
		}
	}

	/**
	 * Clear hreflang transients for a post and all its translation siblings (each translation lists all others).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function clear_hreflang_for_post_and_siblings( int $post_id ): void {
		// A post's hreflang lives only under the singular bucket; archive buckets do not depend on one post. Keys
		// go through CacheManager, so the generation-prefixed L2 key and the derived L3 name both match.
		$this->clear_singular_hreflang( $post_id, \PerfLocale\Enum\ObjectType::Post, 's' );

		// The static front page, the posts page and its translations render under language-keyed home buckets no
		// object id addresses, so their edit flushes the whole hreflang group.
		if ( $this->is_home_object( $post_id ) ) {
			$this->cache->invalidate_group( 'perflocale_hreflang' );
		}
	}

	/**
	 * Whether a post is the static front page, the posts page or a translation of the posts page.
	 *
	 * Their edits change the language-keyed home hreflang: the posts page lists the languages it has a
	 * published sibling in.
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

		$posts_page = (int) get_option( 'page_for_posts' );

		if ( $posts_page <= 0 ) {
			return false;
		}

		if ( $posts_page === $post_id ) {
			return true;
		}

		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $this->cache );

		foreach ( $repo->get_translations( $post_id, \PerfLocale\Enum\ObjectType::Post ) as $link ) {
			if ( (int) $link->object_id === $posts_page ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether these posts include the static front page or the posts page,
	 * the objects is_home_object() looks for.
	 *
	 * @param array<int, int> $post_ids Post IDs.
	 * @return bool
	 */
	private function has_home_object( array $post_ids ): bool {
		$front_page = get_option( 'page_on_front' );

		if ( 'page' === get_option( 'show_on_front' ) && is_numeric( $front_page ) && in_array( (int) $front_page, $post_ids, true ) ) {
			return true;
		}

		$posts_page = get_option( 'page_for_posts' );

		return is_numeric( $posts_page ) && (int) $posts_page > 0 && in_array( (int) $posts_page, $post_ids, true );
	}

	/**
	 * Clear hreflang for a term and all its translation siblings by flushing the whole hreflang group.
	 *
	 * Paged archive buckets are not enumerable, and a `%category%` rename changes the permalink of every post
	 * beneath it.
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
	 * Clear the singular hreflang of an object and its translation siblings in every active language.
	 *
	 * Uses CacheManager::delete_existing(), so every layer is cleared with the exact keys CacheManager wrote.
	 *
	 * @param int                         $object_id   Object id.
	 * @param \PerfLocale\Enum\ObjectType $object_type Post / Term / etc.
	 * @param string                      $key_letter  Singular bucket letter ('s' for posts).
	 * @param array<int, int>|null        $object_ids  The objects to clear, when the caller holds them; null for the object and its translation siblings.
	 * @return void
	 */
	private function clear_singular_hreflang(
		int $object_id,
		\PerfLocale\Enum\ObjectType $object_type,
		string $key_letter,
		?array $object_ids = null
	): void {
		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $this->cache );

		// No groups: no siblings and no hreflang rows can exist.
		if ( ! $repo->has_any_groups() ) {
			return;
		}

		// Collect the siblings before any caller-side unlink (on_delete_post() calls this first), unless the
		// caller passes the objects.
		$links      = null === $object_ids ? $repo->get_translations( $object_id, $object_type ) : [];
		$object_ids = $object_ids ?? [ $object_id ];

		foreach ( $links as $link ) {
			$sibling_id = (int) $link->object_id;

			if ( $sibling_id > 0 && $sibling_id !== $object_id ) {
				$object_ids[] = $sibling_id;
			}
		}

		$languages = ( new \PerfLocale\Database\Repository\LanguageRepository( $this->cache ) )->get_active();

		// HreflangTags caches two entries per page: the HTML block under $key and the Link-header tag array under
		// 'tags_' . $key. Both go, or one path keeps the old alternates.
		$keys = [];

		foreach ( $object_ids as $oid ) {
			foreach ( $languages as $lang ) {
				$key    = 'perflocale_hreflang_' . $key_letter . '_' . $oid . '_' . (int) $lang->id;
				$keys[] = $key;
				$keys[] = 'tags_' . $key;
			}
		}

		$this->cache->delete_existing( $keys, 'perflocale_hreflang' );

		/**
		 * Extension hook: fires after PerfLocale's own hreflang invalidation
		 * so object-cache-backed installs can issue their own wipe.
		 *
		 * @hook perflocale/cache/flush_archive_hreflang
		 * @param int $object_id Object whose save, edit, delete or translation-group change triggered the flush.
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
			// Revisions hold no links, slugs or hreflang, yet wp_delete_post() fires delete_post for each one.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Clear hreflang first: it finds the siblings through the translation link the unlink below removes.
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
		// Flushes the hreflang group, so the sibling archives of a renamed term drop the old URL.
		$this->clear_hreflang_for_term_and_siblings( $term_id, $taxonomy );

		$this->cache->flush_object( $term_id, 'term' );
	}

	/**
	 * Purge a translation after machine translation wrote its meta.
	 *
	 * Sends the purge signal again once the field values are final. Hreflang stays: it never reads meta.
	 *
	 * @param mixed $translation_id Translation post id.
	 * @param mixed $written_keys   Meta keys written by the run.
	 * @return void
	 */
	public function on_meta_translated( $translation_id = 0, $written_keys = [] ): void {
		$translation_id = is_numeric( $translation_id ) ? (int) $translation_id : 0;

		if ( $translation_id <= 0 || ! is_array( $written_keys ) || [] === $written_keys ) {
			return;
		}

		$this->cache->flush_object( $translation_id, 'post' );
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
		// Clear hreflang before the unlink, as in on_delete_post().
		$this->clear_hreflang_for_term_and_siblings( $term_id, $taxonomy );

		// Remove the translation link so deleted terms don't leave stale references.
		$repo = new \PerfLocale\Database\Repository\TranslationGroupRepository( $this->cache );
		$repo->unlink_by_object_id( $term_id, 'term' );

		// Drop the term's slug_translations rows, which would otherwise orphan. The tables guard covers the
		// deactivation and uninstall ordering window.
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

			// And its content_hashes row: ContentChangeDetector boots only in admin, REST and CLI, so its own
			// cleanup misses a term deleted elsewhere.
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
	 * @param mixed ...$args Hook arguments (ignored; the firing hooks have different signatures).
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- variadic captures heterogeneous hook signatures we deliberately ignore.
	public function on_language_changed( mixed ...$args ): void {
		$this->cache->flush_languages();

		// Rewrite rules embed a regex of the language slugs, so they must be regenerated.
		update_option( 'perflocale_flush_rules', 1, false );
		delete_transient( 'perflocale_rewrites_verified' );
	}

	/**
	 * Invalidate the caches a settings save can stale. The per-key blocks
	 * are not exclusive: one save can change several kinds of keys.
	 *
	 * @param mixed ...$args Hook arguments (new_settings, old_settings); both needed (2 accepted args).
	 * @return void
	 */
	public function on_settings_changed( mixed ...$args ): void {
		$new_settings = $args[0] ?? [];
		$old_settings = $args[1] ?? [];

		// A save of operational keys only (jobs, MT, ...) keeps the warmed hreflang entries.
		if ( is_array( $new_settings ) && is_array( $old_settings ) && $old_settings !== [] && OperationalSettings::only_these_changed( $new_settings, $old_settings ) ) {
			return;
		}

		// Language data underpins slug/hreflang derivation; this also bumps
		// the hreflang generation and sweeps its L3 rows.
		$this->cache->flush_languages();

		// Without both payloads there is nothing to diff: the flush above is all.
		if ( ! is_array( $new_settings ) || ! is_array( $old_settings ) || $old_settings === [] ) {
			return;
		}

		// URL settings shape the URLs in cached hreflang alternates and found-rows counts.
		$url_keys = [ 'url_mode', 'url_prefix_type', 'hide_default_prefix', 'excluded_paths', 'translate_slugs', 'language_domains' ];

		foreach ( $url_keys as $key ) {
			if ( ( $new_settings[ $key ] ?? null ) !== ( $old_settings[ $key ] ?? null ) ) {
				// invalidate_group(), not a bare bump: L3 transient names are not generation-aware.
				$this->cache->invalidate_group( 'perflocale_hreflang' );
				$this->cache->invalidate_group( 'perflocale_found_rows' );
				break;
			}
		}

		// Type translatability: cached hreflang alternates are not re-checked at serve time, and superseded
		// found-rows transients would linger.
		$trans_keys = [ 'translatable_post_types', 'translatable_taxonomies' ];

		foreach ( $trans_keys as $key ) {
			if ( ( $new_settings[ $key ] ?? null ) !== ( $old_settings[ $key ] ?? null ) ) {
				$this->cache->invalidate_group( 'perflocale_hreflang' );
				$this->cache->invalidate_group( 'perflocale_found_rows' );
				break;
			}
		}

		// String mode change: every active language's string cache goes (do not narrow it to one), and files
		// mode regenerates the .l10n.php files before the next front-end request.
		if ( ( $new_settings['string_translation_mode'] ?? null ) !== ( $old_settings['string_translation_mode'] ?? null ) ) {
			$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $this->cache );
			$languages = $lang_repo->get_active();

			foreach ( $languages as $lang ) {
				$this->cache->delete( "all_string_translations_{$lang->id}", 'perflocale_strings' );
			}

			if ( ( $new_settings['string_translation_mode'] ?? '' ) === 'files' ) {
					// Tells TranslationFileLoader the files are regenerating, so a first miss does not end its retries.
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
	 * @param int $new_blog_id New blog ID (unused: the reset is blog-agnostic).
	 * @return void
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP switch_blog hook signature; reset_static() is blog-agnostic.
	public function on_switch_blog( int $new_blog_id ): void {
		// Only the request-scoped static caches: the persistent layers are per-blog already, and touching them
		// would fatal on a fresh subsite whose tables do not exist yet (switch_blog fires before the activator).
		$this->cache->reset_static();
	}

	/**
	 * Purge full-page caches for a post whose PUBLIC visibility changed.
	 *
	 * Resolves the group's front-end URLs itself: a page cache computes them in an admin request, without the
	 * language prefix. Narrow on purpose: only this post's group, never a global flush.
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

		// A public change alters every sibling's alternates. Where on_save_post() is hooked it clears them, so this
		// covers only requests without it (a publication in a front-end GET). Block templates have no singular entry.
		if ( false === has_action( 'save_post', [ $this, 'on_save_post' ] )
			&& ! \PerfLocale\Translation\BlockTemplateSupport::is_template_type( (string) $post->post_type ) ) {
			$this->clear_hreflang_for_post_and_siblings( (int) $post->ID );
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

		// No direct calls into page-cache plugins: their per-post purges (Breeze's, for one) also purge CDNs over
		// the network inside the save request. Sites hook `perflocale/cache/purge_urls` to their cache instead.
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
	 * Uses get_permalink() on purpose: it gives the URL a visitor requests, even in this admin request. URLs that
	 * cannot be resolved are skipped, never guessed.
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
