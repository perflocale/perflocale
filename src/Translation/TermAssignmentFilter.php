<?php
/**
 * Term assignment language normalizer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Corrects post→term assignments that end up with a wrong-language term_id.
 *
 * The classic-editor tag box submits tags as *name strings*, so WP resolves
 * them via term_exists(), which matches against the first term with that
 * name - regardless of language. On a site with sibling tags "tag1" (EN)
 * and "tag1" (ES), term_exists('tag1') returns whichever was inserted
 * first, so saving a Spanish post with "tag1" can attach the English
 * term_id and the frontend links to /en/tag/tag1/.
 *
 * This filter runs after wp_set_object_terms() and, for each assigned
 * term whose language doesn't match the post's language, looks up the
 * sibling term in the correct language via translation groups and
 * re-assigns it. If no sibling exists, the assignment is left as-is
 * (we do not silently create new terms here).
 */
final class TermAssignmentFilter {

	/**
	 * @var CacheManager
	 */
	private readonly CacheManager $cache;

	/**
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Guard to prevent our own wp_set_object_terms() call from re-entering
	 * the filter and looping.
	 *
	 * @var array<int, true>
	 */
	private array $in_progress = [];

	/**
	 * Constructor.
	 *
	 * @param CacheManager $cache Cache manager.
	 * @param Settings     $settings Plugin settings.
	 */
	public function __construct( CacheManager $cache, Settings $settings ) {
		$this->cache    = $cache;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'set_object_terms', [ $this, 'normalize_assignment' ], 10, 6 );
	}

	/**
	 * Normalize a post's term assignment to the post's language.
	 *
	 * @param int                    $object_id Post ID.
	 * @param array<int, int|string> $terms Term IDs or names as passed in.
	 * @param array<int, int>        $tt_ids Term taxonomy IDs of the assigned terms.
	 * @param string                 $taxonomy Taxonomy slug.
	 * @param bool                   $append Whether the call appended or replaced.
	 * @param array<int, int>        $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public function normalize_assignment( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( isset( $this->in_progress[ $object_id ] ) ) {
			return;
		}

		// Only act on translatable taxonomies; other taxonomies have no
		// per-language siblings to swap to.
		if ( ! in_array( $taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		// Only act on posts - term→term and user→term assignments don't
		// have a "post language" to coordinate against.
		if ( get_post( $object_id ) === null ) {
			return;
		}

		$post_manager = new PostTranslationManager( $this->cache, $this->settings );
		$post_lang    = $post_manager->detect_post_language( $object_id );

		if ( ! $post_lang || ! isset( $post_lang->slug ) ) {
			return;
		}

		$post_lang_slug = (string) $post_lang->slug;

		$term_manager = new TermTranslationManager( $this->cache );

		$current_ids = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $current_ids ) || empty( $current_ids ) ) {
			return;
		}

		$corrected = [];
		$changed   = false;

		foreach ( $current_ids as $term_id ) {
			$term_id   = (int) $term_id;
			$term_lang = $term_manager->detect_term_language( $term_id );

			// Unmanaged term (no language link yet) - leave it alone. The
			// operator may be intentionally using a shared term across
			// languages.
			if ( ! $term_lang || ! isset( $term_lang->slug ) ) {
				$corrected[] = $term_id;
				continue;
			}

			if ( (string) $term_lang->slug === $post_lang_slug ) {
				$corrected[] = $term_id;
				continue;
			}

			// Wrong language - try to swap to the sibling in the correct one.
			$sibling_id = $term_manager->get_translation_id( $term_id, $post_lang_slug );

			if ( $sibling_id && $sibling_id !== $term_id ) {
				$corrected[] = (int) $sibling_id;
				$changed     = true;
				continue;
			}

			// No sibling exists for the post's language. Leave the wrong
			// assignment in place rather than silently dropping the tag -
			// the operator can fix manually or create the missing
			// translation.
			$corrected[] = $term_id;
		}

		if ( ! $changed ) {
			return;
		}

		$corrected = array_values( array_unique( $corrected ) );

		$this->in_progress[ $object_id ] = true;
		wp_set_object_terms( $object_id, $corrected, $taxonomy, false );
		unset( $this->in_progress[ $object_id ] );
	}
}
