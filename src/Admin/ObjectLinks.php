<?php
/**
 * Edit and view URLs for translatable objects.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use Stringable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the admin edit URL and the front-end view URL of a translatable object.
 *
 * Core's helpers return relative or 404 URLs for non-public add-on types: see
 * docs-ai/11-code-rationale.md, "ObjectLinks: non-public post types". '' means "render no link".
 */
final class ObjectLinks {

	/**
	 * Absolute admin URL for editing an object, or '' when nothing can edit it.
	 *
	 * @param int         $post_id   Object to link.
	 * @param string|null $post_type Its post type; resolved when null.
	 * @return string
	 */
	public static function edit_url( int $post_id, ?string $post_type = null ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		// ⚠️ A stale ID or mismatched hint must not become an unrelated form-editor URL.
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ( $post_type !== null && $post_type !== $post->post_type ) ) {
			return '';
		}

		$post_type = $post_type ?? $post->post_type;
		$url       = get_edit_post_link( $post_id, 'raw' );
		$url       = is_string( $url ) ? $url : '';

		/**
		 * Filter the admin URL used to edit a translatable object.
		 *
		 * Return an absolute admin URL for a type with its own editor, or '' for no link.
		 *
		 * @hook perflocale/admin/edit_post_link Filter the edit URL for a translatable object.
		 * @param string $url       URL core resolved, '' when it could not.
		 * @param int    $post_id   Object being linked.
		 * @param string $post_type Its post type.
		 */
		$url = apply_filters( 'perflocale/admin/edit_post_link', $url, $post_id, $post_type );

		return self::absolute( $url );
	}

	/**
	 * Absolute front-end URL for an object, or '' when there is nothing to view.
	 *
	 * @param WP_Post $post Object to link.
	 * @return string
	 */
	public static function view_url( WP_Post $post ): string {
		$url = is_post_type_viewable( $post->post_type ) ? (string) get_permalink( $post ) : '';

		/**
		 * Filter the front-end URL used to preview a translatable object.
		 *
		 * Add-ons restore a working permalink of a non-viewable type here (`product_variation`).
		 *
		 * @hook perflocale/admin/view_post_link Filter the front-end URL for a translatable object.
		 * @param string   $url  URL resolved so far, '' when the type is not viewable.
		 * @param WP_Post $post Object being linked.
		 */
		$url = apply_filters( 'perflocale/admin/view_post_link', $url, $post );

		return self::absolute( $url );
	}

	/**
	 * Reject anything that is not an absolute http(s) URL.
	 *
	 * A relative result resolves against the current page and looks like a working link.
	 *
	 * @param mixed $url Candidate returned by a filter.
	 * @return string
	 */
	private static function absolute( $url ): string {
		// A Stringable URI object counts as its string.
		if ( $url instanceof Stringable ) {
			$url = (string) $url;
		}

		if ( ! is_string( $url ) || $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		// The scheme check alone accepts 'https://' and 'https:///editor': require a parsed host.
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || $parts['host'] === '' ) {
			return '';
		}

		return $url;
	}
}
