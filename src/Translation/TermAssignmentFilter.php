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
 * Swaps a post's wrong-language terms for their siblings in the post's language.
 *
 * The classic tag box submits names, and term_exists() returns the first term with that name in any language.
 * A term without a sibling stays assigned; no term is created here.
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
	 * Posts whose terms this class is rewriting, so its own wp_set_object_terms() does not re-enter.
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

		// Only translatable taxonomies have per-language siblings.
		if ( ! in_array( $taxonomy, $this->settings->get_translatable_taxonomies(), true ) ) {
			return;
		}

		// Only posts have a language to match; the object may be a user or other object.
		if ( get_post( $object_id ) === null ) {
			return;
		}

		$post_lang_slug = $this->post_language_slug( $object_id );

		if ( $post_lang_slug === null ) {
			return;
		}

		$current_ids = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $current_ids ) || empty( $current_ids ) ) {
			return;
		}

		$this->normalize_taxonomy( $object_id, $taxonomy, array_map( 'intval', $current_ids ), $post_lang_slug );
	}

	/**
	 * Normalize every translatable taxonomy of a post to the post's language.
	 *
	 * For a post that has just received its first language: wp_insert_post() attaches its terms before
	 * save_post assigns the language, so the set_object_terms pass had nothing to compare them with.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function normalize_post_terms( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || isset( $this->in_progress[ $post_id ] ) ) {
			return;
		}

		$taxonomies = array_values( array_intersect( get_object_taxonomies( $post->post_type ), $this->settings->get_translatable_taxonomies() ) );

		$post_lang_slug = $taxonomies === [] ? null : $this->post_language_slug( $post_id );

		if ( $post_lang_slug === null ) {
			return;
		}

		$terms = wp_get_object_terms( $post_id, $taxonomies, [ 'fields' => 'all' ] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$by_taxonomy = [];

		foreach ( $terms as $term ) {
			$by_taxonomy[ $term->taxonomy ][] = (int) $term->term_id;
		}

		foreach ( $by_taxonomy as $taxonomy => $ids ) {
			$this->normalize_taxonomy( $post_id, (string) $taxonomy, $ids, $post_lang_slug );
		}
	}

	/**
	 * Language slug of a post, or null when it has none yet.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function post_language_slug( int $post_id ): ?string {
		$post_manager = new PostTranslationManager( $this->cache, $this->settings );
		$post_lang    = $post_manager->detect_post_language( $post_id );

		if ( ! $post_lang || ! isset( $post_lang->slug ) ) {
			return null;
		}

		return (string) $post_lang->slug;
	}

	/**
	 * Swap a post's wrong-language terms in one taxonomy for their siblings in the post's language.
	 *
	 * @param int             $object_id      Post ID.
	 * @param string          $taxonomy       Translatable taxonomy slug.
	 * @param array<int, int> $current_ids    Term IDs the post holds in it.
	 * @param string          $post_lang_slug The post's language slug.
	 * @return void
	 */
	private function normalize_taxonomy( int $object_id, string $taxonomy, array $current_ids, string $post_lang_slug ): void {
		$term_manager = new TermTranslationManager( $this->cache );

		$corrected = [];
		$changed   = false;

		foreach ( $current_ids as $term_id ) {
			$term_id   = (int) $term_id;
			$term_lang = $term_manager->detect_term_language( $term_id );

			// A term without a language may be shared across languages on purpose.
			if ( ! $term_lang || ! isset( $term_lang->slug ) ) {
				$corrected[] = $term_id;
				continue;
			}

			if ( (string) $term_lang->slug === $post_lang_slug ) {
				$corrected[] = $term_id;
				continue;
			}

			$sibling_id = $term_manager->get_translation_id( $term_id, $post_lang_slug );

			if ( $sibling_id && $sibling_id !== $term_id ) {
				$corrected[] = (int) $sibling_id;
				$changed     = true;
				continue;
			}

			// No sibling: keep the term rather than silently drop it.
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
