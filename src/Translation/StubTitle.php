<?php
/**
 * The title PerfLocale gave a translation it created empty.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A translation PerfLocale creates empty (a stub) starts with its source's title, recorded as stored in this
 * protected meta, so a machine fill can tell PerfLocale's copy from a typed title even after the source was retitled.
 * Removed at the first non-autosave save that gives content or another title; never copied to another translation.
 */
final class StubTitle {

	/** The post meta key. */
	public const KEY = '_perflocale_stub_title';

	/**
	 * Record the title of a translation just created, when it is empty and has a title.
	 *
	 * @param \WP_Post $post The new translation, as stored.
	 * @return bool Whether a record was added.
	 */
	public static function record( \WP_Post $post ): bool {
		$title = (string) $post->post_title;

		if ( '' !== (string) $post->post_content || '' === trim( $title ) ) {
			return false;
		}

		return false !== add_post_meta( (int) $post->ID, self::KEY, wp_slash( $title ), true );
	}

	/**
	 * Whether a title is still the recorded one, ignoring outer whitespace and entities vs characters, or equal to
	 * the record after the `title_save_pre` KSES a save without unfiltered_html applies (it strips `<span>`, `<br>`).
	 * KSES runs only when the plain compare fails.
	 *
	 * @param string $title    The title now.
	 * @param mixed  $recorded The record, as read.
	 * @return bool
	 */
	public static function carries( string $title, mixed $recorded ): bool {
		if ( ! is_string( $recorded ) || '' === trim( $recorded ) ) {
			return false;
		}

		$title = self::decoded( $title );

		if ( $title === self::decoded( $recorded ) ) {
			return true;
		}

		$saved = self::decoded( wp_kses( $recorded, 'title_save_pre' ) );

		return '' !== $saved && $title === $saved;
	}

	/**
	 * On a save: remove the record once the post has content or a title that no longer carries it.
	 * Called after save_post loaded the meta, so the read is a cache hit.
	 *
	 * @param \WP_Post $post The post as saved.
	 * @return void
	 */
	public static function forget_spent( \WP_Post $post ): void {
		$recorded = get_post_meta( (int) $post->ID, self::KEY, true );

		if ( '' === $recorded ) {
			return;
		}

		if ( '' === (string) $post->post_content && self::carries( (string) $post->post_title, $recorded ) ) {
			return;
		}

		delete_post_meta( (int) $post->ID, self::KEY );
	}

	/**
	 * A title with its HTML entities decoded, trimmed.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function decoded( string $title ): string {
		return trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
