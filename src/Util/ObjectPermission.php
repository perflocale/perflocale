<?php
/**
 * Object-level permissions with the host's say, loaded on the first check.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core's capability for one post, then the perflocale/object/user_can filter. See docs-ai/11-code-rationale.md.
 */
final class ObjectPermission {

	/**
	 * Resolve one object-level permission. Trash goes through here (it is a deletion), publish does not.
	 *
	 * @param string   $action     Semantic action: 'edit', 'delete', 'read' or 'create'.
	 * @param string   $capability Core capability checked by default.
	 * @param int      $post_id    Post ID.
	 * @param int|null $user_id    User asked about; null for the current user. Another user gets core's answer only.
	 * @return bool
	 */
	public static function check( string $action, string $capability, int $post_id, ?int $user_id = null ): bool {
		if ( null !== $user_id && get_current_user_id() !== $user_id ) {
			return user_can( $user_id, $capability, $post_id );
		}

		$can = current_user_can( $capability, $post_id );

		// For the filter to switch on (a listener answers for its own type only); read and filtered on every check, as
		// a host may count the filter's runs (did_filter()).
		$post_type = (string) get_post_type( $post_id );

		if ( $post_type === '' ) {
			return $can;
		}

		/**
		 * Filter whether the current user may act on one translatable object.
		 *
		 * Answer with the host's own authorization function for your own post type; return `$can` for others.
		 * Returning true grants access; 'create' means a new post of this type. See docs-ai/11-code-rationale.md.
		 *
		 * @hook  perflocale/object/user_can
		 * @since 1.0.5
		 *
		 * @param bool   $can       Whether core's capability check passed.
		 * @param string $action    Semantic action: 'edit', 'delete', 'read' or 'create'.
		 * @param int    $post_id   Object being acted on.
		 * @param string $post_type Its post type.
		 * @return bool
		 */
		return (bool) apply_filters( 'perflocale/object/user_can', $can, $action, $post_id, $post_type );
	}

	/**
	 * Whether the current user may create a new post of this post's type (its `create_posts`), host-aware.
	 *
	 * A non-string `create_posts` (Pods sets false) refuses everyone, super admins included.
	 *
	 * @param int $post_id Post the new translation would be copied from.
	 * @return bool False when the type is not registered or has no create capability name.
	 */
	public static function create_like( int $post_id ): bool {
		$type_object = get_post_type_object( (string) get_post_type( $post_id ) );
		$capability  = $type_object instanceof \WP_Post_Type ? ( $type_object->cap->create_posts ?? null ) : null;

		if ( ! is_string( $capability ) ) {
			return false;
		}

		return self::check( 'create', $capability, $post_id );
	}

	/**
	 * Whether the current user may create a term in `$term_id`'s taxonomy, as core rules a new term.
	 *
	 * That is edit_terms, or for a flat taxonomy also assign_terms, never per-term edit_term; wp_insert_term() checks none.
	 *
	 * @param int $term_id Term the new translation would be copied from.
	 * @return bool False when the term or its taxonomy does not exist.
	 */
	public static function create_term_like( int $term_id ): bool {
		$term     = get_term( $term_id );
		$taxonomy = $term instanceof \WP_Term ? get_taxonomy( $term->taxonomy ) : false;

		if ( ! $taxonomy instanceof \WP_Taxonomy ) {
			return false;
		}

		$caps = $taxonomy->hierarchical ? [ $taxonomy->cap->edit_terms ] : [ $taxonomy->cap->edit_terms, $taxonomy->cap->assign_terms ];

		foreach ( $caps as $capability ) {
			if ( is_string( $capability ) && current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}
}
