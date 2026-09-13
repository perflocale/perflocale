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
 * Resolves the admin edit URL and the front-end view URL for an object that
 * PerfLocale can translate.
 *
 * ⚠️ WHY THIS EXISTS. Core's helpers quietly assume a PUBLIC post type, and
 * every post type an addon contributes through `perflocale/translatable_post_types`
 * is non-public:
 *
 *   - `get_edit_post_link()` builds from the post type's `_edit_link`, which
 *     WordPress only populates when `show_ui` is true. For `wpcf7_contact_form`,
 *     `wpforms` and `product_variation` it returns null — and the Classic Editor
 *     plugin then filters that empty string into the RELATIVE href
 *     `?classic-editor`, which a browser resolves against whatever page it is
 *     on. That is worse than a dead link: it looks real, and it silently
 *     navigated users back to admin.php with no error.
 *   - `get_permalink()` returns a URL for a non-viewable type too
 *     (`/wpcf7_contact_form/contact-form-1/`), which 404s — so an emptiness
 *     check cannot tell a real link from a dead one.
 *
 * Both helpers below therefore refuse anything that is not an absolute http(s)
 * URL, and expose a filter so an addon can point its own type at the editor or
 * front-end route that actually owns it.
 *
 * Callers must treat '' as "render no link" — never as an error. A translation
 * with no reachable editor is a normal state, not a failure.
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
		 * Return an absolute admin URL for a post type whose editor is not
		 * WordPress's own — a form builder, a page builder — or '' to render
		 * the object without a link rather than with a broken one.
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
		 * ⚠️ Viewability alone is not the whole answer: `product_variation` is
		 * NOT publicly queryable, yet its permalink works because WooCommerce
		 * maps it onto the parent product. Addons use this to restore their own
		 * type; nothing else may resurrect a dead permalink.
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
	 * ⚠️ A relative result resolves against the current page and reads as a
	 * working link. Returning '' is always the safer answer.
	 *
	 * @param mixed $url Candidate returned by a filter.
	 * @return string
	 */
	private static function absolute( $url ): string {
		// Preserve valid URI objects that the previous string cast accepted.
		if ( $url instanceof Stringable ) {
			$url = (string) $url;
		}

		if ( ! is_string( $url ) || $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		// A scheme prefix alone also accepts 'https://' and 'https:///editor'.
		// Require a parsed host without DNS or outbound HTTP.
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || $parts['host'] === '' ) {
			return '';
		}

		return $url;
	}
}
