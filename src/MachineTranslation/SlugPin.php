<?php
/**
 * The one-shot slug pin for automatic saves of a translation (write path only, required at first use).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\MachineTranslation;

use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the slug a published translation holds through an automatic save (ReSync, sibling sync, MT, imports, REST).
 *
 * @since 1.0.8
 */
final class SlugPin {

	/**
	 * Keep a post's stored slug through the save the caller starts next: core re-checks a published slug on every save
	 * and would rename it for a same-language draft. One-shot `pre_wp_unique_post_slug` at the caller's hook level only
	 * (a save a hook starts is not this one), and only for a slug the post already holds under a checked status.
	 *
	 * @param int $post_id Post id.
	 * @return \Closure(): void Removes the filter.
	 */
	public static function keep( int $post_id ): \Closure {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || '' === $post->post_name || in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft', 'trash', 'inherit' ], true ) ) {
			return static function (): void {};
		}

		$slug  = $post->post_name;
		$type  = $post->post_type;
		$above = (int) $post->post_parent;
		$blog  = get_current_blog_id();
		$level = ExpectedWrite::level( 'pre_wp_unique_post_slug' );
		$pin   = null;
		$pin   = static function ( $override, $wanted = '', $id = 0, $status = '', $post_type = '', $parent_id = 0 ) use ( $post_id, $slug, $type, $above, $blog, $level, &$pin ) {
			if ( Scalar::int( $id ) !== $post_id || get_current_blog_id() !== $blog || ! ExpectedWrite::at( $level ) ) {
				return $override;
			}

			remove_filter( 'pre_wp_unique_post_slug', $pin, PHP_INT_MAX );

			return null === $override && $wanted === $slug && $post_type === $type && Scalar::int( $parent_id ) === $above ? $slug : $override;
		};

		add_filter( 'pre_wp_unique_post_slug', $pin, PHP_INT_MAX, 6 );

		return static function () use ( &$pin ): void {
			remove_filter( 'pre_wp_unique_post_slug', $pin, PHP_INT_MAX );
		};
	}
}
