<?php
/**
 * Early exits for post-save and status-change hooks, so a save of a post type PerfLocale ignores loads no write half.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Same outcomes as the write halves' own first checks, answered before their file is loaded. Loaded on the first
 * post save or status change, never on a page view.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class SaveHookGuards {

	/**
	 * On transition_post_status: the status selection caches do not hold a type that is not translated.
	 *
	 * @param string        $new_status New post status.
	 * @param string        $old_status Previous post status.
	 * @param \WP_Post|null $post       The post.
	 * @return void
	 */
	public static function status_transition( $new_status, $old_status, $post = null ): void {
		if ( ! self::not_translatable( $post ) ) {
			\PerfLocale\Database\Repository\TranslationGroupWrites::on_post_status_transition( $new_status, $old_status, $post );
		}
	}

	/**
	 * On save_post:5: only a translatable type is assigned a language.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return void
	 */
	public static function auto_assign_default_language( int $post_id, ?\WP_Post $post = null ): void {
		if ( ! self::not_translatable( $post ) ) {
			\PerfLocale\BootstrapWrites::auto_assign_default_language( $post_id, $post );
		}
	}

	/**
	 * On save_post:20: a copy warning or stub title is only ever on a post carrying one of the two keys.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @return void
	 */
	public static function clear_meta_copy_errors( int $post_id, ?\WP_Post $post = null ): void {
		if ( ! $post instanceof \WP_Post || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$meta = get_post_meta( $post_id );

		// An unknown read is not "no keys": a non-array answer, or [] with a database error, goes to the old handler.
		if ( ! is_array( $meta ) || ( [] === $meta && '' !== $wpdb->last_error ) || isset( $meta['_perflocale_meta_copy_errors'] ) || isset( $meta['_perflocale_stub_title'] ) ) {
			\PerfLocale\BootstrapWrites::clear_meta_copy_errors( $post_id, $post );
		}
	}

	/**
	 * Whether the post is known to be of a type PerfLocale does not translate; anything unknown stays false.
	 *
	 * @param mixed $post Post.
	 * @return bool
	 */
	private static function not_translatable( mixed $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		try {
			$plugin = Plugin::get_instance();

			if ( ! $plugin->has( 'settings' ) ) {
				return false;
			}

			/**
			 * The settings service.
			 *
			 * @var \PerfLocale\Settings $settings
			 */
			$settings = $plugin->get( 'settings' );

			return ! in_array( $post->post_type, $settings->get_translatable_post_types(), true );
		} catch ( \Throwable $e ) {
			unset( $e );

			return false;
		}
	}
}
