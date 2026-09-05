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
 * configured to stay in sync across translations.
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
	 * same decision in sync_on_term_edit().
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

		// Skip revisions and autosaves before acquiring any lock - avoids
		// churning the options table on every autosave tick.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Check if post type is translatable.
		$translatable = $this->settings->get_translatable_post_types();

		if ( ! in_array( $post->post_type, $translatable, true ) ) {
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
			 * the siblings. Defaults to the user-configured `sync_fields` list;
			 * BUILDER addons (Elementor/Bricks/Oxygen/Beaver) add their layout
			 * keys here because a layout must stay structurally identical
			 * across siblings (text inside is translated at render).
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

			// Resolved once per save (not once per sibling) and honoured by
			// the post_parent branch of sync_fields_to_post(); every other
			// synced field stays bidirectional as before.
			$is_default_source = $this->is_default_language_post( $post_id, $translations );

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
					$this->sync_fields_to_post( $post_id, $translated_id, $sync_fields, $lang_slug, $is_default_source, $seed_only );

					// Free per-post object caches accumulated during the inner
					// sync so bulk runs (WP-CLI, cron) don't accumulate memory.
					clean_post_cache( $translated_id );
				} finally {
					Lock::release( $this->post_lock( $translated_id ) );
				}
			}
		} finally {
			Lock::release( $this->post_lock( $post_id ) );
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
	 *            group's hierarchy - see is_default_language_post().
	 * @param array<string> $seed_only Meta keys with seed-only semantics: copied
	 *            when the sibling has NO rows for the key, otherwise left
	 *            untouched (never overwritten, never deleted) — the sibling's
	 *            translator owns the per-language value.
	 * @return void
	 */
	private function sync_fields_to_post( int $source_id, int $target_id, array $fields, string $target_lang_slug, bool $source_is_default_lang, array $seed_only = [] ): void {
		// Post fields that can be batched into a single wp_update_post() call.
		$post_field_map = [
			'menu_order'     => 'menu_order',
			'post_date'      => 'post_date',
			'post_author'    => 'post_author',
			'post_parent'    => 'post_parent',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
		];

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
			if ( count( $update ) > 1 ) {
				$result = wp_update_post( $update, true );

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
		if ( $has_thumb ) {
			$thumbnail_id = get_post_thumbnail_id( $source_id );
			if ( $thumbnail_id ) {
				set_post_thumbnail( $target_id, $thumbnail_id );
			} else {
				delete_post_thumbnail( $target_id );
			}
		}

		// Meta field syncs.
		$mirrored_meta_keys = [];

		if ( $has_meta ) {
			foreach ( $fields as $field ) {
				if ( $field === 'featured_image' || isset( $post_field_map[ $field ] ) ) {
					continue;
				}

				// SEED-ONLY keys (SEO titles, ACF/Meta Box/Pods values, WC purchase
				// notes): the sibling's translator owns the per-language value.
				// Copy only when the sibling has NO rows for the key (a fresh
				// translation) and never delete - a source save must not clobber
				// a value typed on the translation.
				if ( in_array( $field, $seed_only, true ) ) {
					if ( get_post_meta( $target_id, $field, false ) !== [] ) {
						continue;
					}

					$seed_values = get_post_meta( $source_id, $field, false );

					if ( $seed_values === [] || ( count( $seed_values ) === 1 && $seed_values[0] === '' ) ) {
						continue;
					}

					foreach ( $seed_values as $meta_value ) {
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
