<?php
/**
 * Content change detector - flags translations as "needs update" when source changes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Schema;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors source content changes and flags translations as "needs update".
 *
 * Stores a SHA-256 hash of the source post/term content. On each save,
 * compares the new hash with the stored one. If different AND the object
 * is in the default language (i.e. the source), all translations in the
 * group are flagged.
 *
 * Editing a translation does NOT flag the source or other translations -
 * only source changes propagate the "needs_update" status.
 */
final class ContentChangeDetector {

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * Cached default language ID (per-request).
	 *
	 * @var int|null
	 */
	private ?int $default_language_id = null;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Plugin settings.
	 * @param CacheManager $cache Cache manager.
	 */
	public function __construct( Settings $settings, CacheManager $cache ) {
		$this->settings = $settings;
		$this->cache    = $cache;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Post content change detection.
		add_action( 'save_post', [ $this, 'check_content_change' ], 30, 2 );

		// Term content change detection.
		add_action( 'edited_term', [ $this, 'check_term_change' ], 30, 3 );

		// Clean up orphaned hashes on permanent deletion.
		add_action( 'delete_post', [ $this, 'delete_post_hash' ] );
		add_action( 'delete_term', [ $this, 'delete_term_hash' ], 10, 3 );
	}

	/**
	 * Check if post content has changed and flag translations.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object; null when core re-read the row after
	 *                               the write and found it gone, or when a third
	 *                               party re-fires save_post with one argument.
	 * @return void
	 */
	public function check_content_change( int $post_id, ?\WP_Post $post = null ): void {
		// WordPress re-reads the row after the write and hands the hook
		// whatever it got, which is null when the post was deleted in the
		// interim; some plugins also fire save_post with one argument. A
		// non-nullable hint turned either into an uncaught TypeError.
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( $post->post_status !== 'publish' ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->settings->get_translatable_post_types(), true ) ) {
			return;
		}

		$new_hash = $this->compute_post_hash( $post );
		$old_hash = $this->get_stored_hash( $post_id, 'post' );

		// If this is the first save or hash hasn't changed, nothing to flag.
		if ( $old_hash === null || $old_hash === $new_hash ) {
			// Only write the hash if it's new (no row existed yet).
			if ( $old_hash === null ) {
				$this->store_hash( $post_id, 'post', $new_hash );
			}

			return;
		}

		// Content changed - store the new hash.
		$this->store_hash( $post_id, 'post', $new_hash );

		// Only flag when the SOURCE (default language) post changes.
		// Editing a translation should not mark the source or other translations.
		if ( ! $this->is_default_language_object( $post_id, 'post' ) ) {
			return;
		}

		$this->flag_translations( $post_id, ObjectType::Post );
	}

	/**
	 * Check if term content has changed and flag translations.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function check_term_change( int $term_id, int $tt_id, string $taxonomy ): void {
		$translatable = $this->settings->get_translatable_taxonomies();

		if ( ! in_array( $taxonomy, $translatable, true ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$new_hash = $this->compute_term_hash( $term );
		$old_hash = $this->get_stored_hash( $term_id, 'term' );

		if ( $old_hash === null || $old_hash === $new_hash ) {
			if ( $old_hash === null ) {
				$this->store_hash( $term_id, 'term', $new_hash );
			}

			return;
		}

		$this->store_hash( $term_id, 'term', $new_hash );

		if ( ! $this->is_default_language_object( $term_id, 'term' ) ) {
			return;
		}

		$this->flag_translations( $term_id, ObjectType::Term );
	}

	/**
	 * Delete content hash when a post is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function delete_post_hash( int $post_id ): void {
		$this->delete_hash( $post_id, 'post' );
	}

	/**
	 * Delete content hash when a term is permanently deleted.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function delete_term_hash( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->delete_hash( $term_id, 'term' );
	}

	/**
	 * Compute a hash of a post's translatable content.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string SHA-256 hash.
	 */
	private function compute_post_hash( \WP_Post $post ): string {
		$content = $post->post_title . '|' . $post->post_content . '|' . $post->post_excerpt;

		return hash( 'sha256', $content );
	}

	/**
	 * Compute a hash of a term's translatable content.
	 *
	 * @param \WP_Term $term Term object.
	 * @return string SHA-256 hash.
	 */
	private function compute_term_hash( \WP_Term $term ): string {
		$content = $term->name . '|' . $term->description;

		return hash( 'sha256', $content );
	}

	/**
	 * Check if an object belongs to the default language.
	 *
	 * Only default-language objects are "sources". Editing a translation
	 * should not flag siblings as needs_update.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $object_type 'post' or 'term'.
	 * @return bool True if the object is in the default language or has no language assignment.
	 */
	private function is_default_language_object( int $object_id, string $object_type ): bool {
		$default_id = $this->get_default_language_id();

		if ( $default_id === 0 ) {
			return true; // No default language configured - treat all as source.
		}

		$object_lang_id = $this->get_object_language_id( $object_id, $object_type );

		// Unassigned objects (no translation link) are treated as default language.
		if ( $object_lang_id === 0 ) {
			return true;
		}

		return $object_lang_id === $default_id;
	}

	/**
	 * Get the language ID for an object from translation_links.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $object_type 'post' or 'term'.
	 * @return int Language ID or 0 if not found.
	 */
	private function get_object_language_id( int $object_id, string $object_type ): int {
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$links_table  = Schema::table( 'translation_links' );
		$groups_table = Schema::table( 'translation_groups' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lang_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT tl.language_id FROM %i tl
				INNER JOIN %i tg ON tl.group_id = tg.id AND tg.type = %s
				WHERE tl.object_id = %d
				LIMIT 1',
				$links_table,
				$groups_table,
				$object_type,
				$object_id
			)
		);

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $lang_id !== null ? (int) $lang_id : 0;
	}

	/**
	 * Get the default language ID (cached per request).
	 *
	 * @return int Language ID or 0.
	 */
	private function get_default_language_id(): int {
		if ( $this->default_language_id !== null ) {
			return $this->default_language_id;
		}

		try {
			$lang_repo = \PerfLocale\Plugin::get_instance()->get( 'lang_repo' );
			$default   = $lang_repo->get_default();

			$this->default_language_id = $default ? (int) $default->id : 0;
		} catch ( \Throwable $e ) {
			$this->default_language_id = 0;
		}

		return $this->default_language_id;
	}

	/**
	 * Get the stored content hash.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type.
	 * @return string|null
	 */
	private function get_stored_hash( int $object_id, string $object_type ): ?string {
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$table = Schema::table( 'content_hashes' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var(
			$wpdb->prepare(
				'SELECT content_hash FROM %i WHERE object_id = %d AND object_type = %s',
				$table,
				$object_id,
				$object_type
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Store a content hash.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type.
	 * @param string $hash Content hash.
	 * @return void
	 */
	private function store_hash( int $object_id, string $object_type, string $hash ): void {
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$table = Schema::table( 'content_hashes' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (object_id, object_type, content_hash) VALUES (%d, %s, %s)
				ON DUPLICATE KEY UPDATE content_hash = VALUES(content_hash)',
				$table,
				$object_id,
				$object_type,
				$hash
			)
		);
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Delete a stored content hash.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $object_type Object type.
	 * @return void
	 */
	private function delete_hash( int $object_id, string $object_type ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$table = Schema::table( 'content_hashes' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			[
				'object_id'   => $object_id,
				'object_type' => $object_type,
			],
			[ '%d', '%s' ]
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Flag all translations of an object as "needs_update".
	 *
	 * Only called when the source (default language) object's content changes.
	 *
	 * @param int        $object_id Object ID.
	 * @param ObjectType $type Object type.
	 * @return void
	 */
	private function flag_translations( int $object_id, ObjectType $type ): void {
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
		global $wpdb;

		$repo  = \PerfLocale\Plugin::get_instance()->get( 'group_repo' );
		$group = $repo->find_for_object( $object_id, $type );

		if ( ! $group ) {
			return;
		}

		$links_table = Schema::table( 'translation_links' );

		// Update all links in this group EXCEPT the source post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'needs_update', updated_at = NOW()
				WHERE group_id = %d AND object_id != %d",
				$links_table,
				(int) $group->id,
				$object_id
			)
		);

		$repo->invalidate_group_cache( (int) $group->id );

		/** @hook perflocale/content/changed Fires when source content changes and translations are flagged. */
		do_action( 'perflocale/content/changed', $object_id, $type->value, (int) $group->id );
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
