<?php
/**
 * Post slug decisions around a language change (write path only, loaded on first use).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

use PerfLocale\Database\Repository\LanguageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The language a post's slug check must use: a form's submitted language, or the new one after a move.
 *
 * @since 1.0.8
 */
final class PostSlugCheck {

	/**
	 * Verified form language per "blog|post ID" ('' when none).
	 *
	 * @var array<string, string>
	 */
	private static array $submitted = [];

	/**
	 * Post being re-checked and its language (null: the stored one, no form hint).
	 *
	 * @var array{0: int, 1: ?object}|null
	 */
	private static ?array $recheck = null;

	/**
	 * Posts whose rename hooks are running ("blog|post ID"): a listener's recheck of the same post is a no-op.
	 *
	 * @var array<string, true>
	 */
	private static array $firing = [];

	/**
	 * Language for the slug check of a post, or null to read the stored one.
	 *
	 * @param int                    $post_id Post ID.
	 * @param PostTranslationManager $manager Post translation manager.
	 * @param LanguageRepository     $langs   Language repository.
	 * @return object|null
	 */
	public static function language_for( int $post_id, PostTranslationManager $manager, LanguageRepository $langs ): ?object {
		if ( null !== self::$recheck ) {
			return self::$recheck[0] === $post_id ? self::$recheck[1] : null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence only; verified in submitted().
		if ( $post_id <= 0 || ( ! isset( $_POST['perflocale_meta_nonce'] ) && ! isset( $_POST['perflocale_qe_nonce'] ) ) ) {
			return null;
		}

		$key = get_current_blog_id() . '|' . $post_id;

		self::$submitted[ $key ] ??= self::submitted( $post_id, $manager, $langs );

		return '' === self::$submitted[ $key ] ? null : $langs->find_by_slug( self::$submitted[ $key ] );
	}

	/**
	 * Re-check a post's slug against its language; a same-language clash gets core's next free "-N".
	 * Compare-and-set write, so a concurrent rename is never overwritten; then core's update hooks, as wp_publish_post().
	 *
	 * @param int         $post_id Post ID.
	 * @param object|null $lang    Its new language, or null for the stored one.
	 * @return void
	 */
	public static function recheck( int $post_id, ?object $lang ): void {
		$key  = get_current_blog_id() . '|' . $post_id;
		$post = isset( self::$firing[ $key ] ) ? null : get_post( $post_id );

		if ( ! $post instanceof \WP_Post || '' === $post->post_name || ! in_array( $post->post_status, [ 'publish', 'future', 'private' ], true ) ) {
			return;
		}

		// After a refused form language: the form's hint must not decide this post's later saves in this request.
		if ( null === $lang ) {
			self::$submitted[ $key ] = '';
		}

		self::$recheck = [ $post_id, $lang ];

		try {
			$slug = wp_unique_post_slug( $post->post_name, $post_id, $post->post_status, $post->post_type, $post->post_parent );
		} finally {
			self::$recheck = null;
		}

		global $wpdb;

		if ( $slug === $post->post_name || ! $wpdb instanceof \wpdb ) {
			return;
		}

		$where = [
			'ID'        => $post_id,
			'post_name' => $post->post_name,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== $wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], $where ) ) {
			return;
		}

		clean_post_cache( $post_id );

		$after = \WP_Post::get_instance( $post_id );

		if ( ! $after instanceof \WP_Post ) {
			return;
		}

		self::$firing[ $key ] = true;

		// The listeners build links: no memo may hold the old language or slug.
		if ( class_exists( \PerfLocale\Router\UrlConverter::class, false ) ) {
			\PerfLocale\Router\UrlConverter::reset_static_caches();
		}

		// Not wp_update_post(): it re-runs kses on the content for a user without unfiltered_html (WP-CLI, cron).
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's update hooks.
		try {
			do_action( "edit_post_{$after->post_type}", $post_id, $after );
			do_action( 'edit_post', $post_id, $after );

			// Core's wp_check_for_changed_slugs() listens here; a post inserted in this request never had the old address.
			if ( ! \PerfLocale\BootstrapWrites::inserted_now( $post_id ) ) {
				do_action( 'post_updated', $post_id, $after, $post );
			}

			do_action( "save_post_{$after->post_type}", $post_id, $after, true );
			do_action( 'save_post', $post_id, $after, true );
			do_action( 'wp_insert_post', $post_id, $after, true );
			wp_after_insert_post( $after, true, $post );
		} finally {
			unset( self::$firing[ $key ] );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * The language slug a verified metabox or Quick Edit form submits for this post when it would change it, else ''.
	 * The save handlers' checks, cheapest first, then the sibling refusal set_post_language() gives.
	 *
	 * @param int                    $post_id Post ID.
	 * @param PostTranslationManager $manager Post translation manager.
	 * @param LanguageRepository     $langs   Language repository.
	 * @return string
	 */
	private static function submitted( int $post_id, PostTranslationManager $manager, LanguageRepository $langs ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified below, before any field is used.
		$form = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;

		if ( $form !== $post_id ) {
			return '';
		}

		$meta  = isset( $_POST['perflocale_meta_nonce'] );
		$key   = $meta ? 'perflocale_meta_nonce' : 'perflocale_qe_nonce';
		$nonce = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_key( $_POST[ $key ] ) : '';

		if ( ! wp_verify_nonce( $nonce, $meta ? 'perflocale_meta_box' : 'perflocale_quick_edit' ) ) {
			return '';
		}

		if ( ( is_multisite() && ms_is_switched() ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		$key  = $meta ? 'perflocale_post_language' : 'perflocale_language_qe';
		$slug = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_key( $_POST[ $key ] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$lang = '' === $slug ? null : $langs->find_by_slug( $slug );

		if ( ! $lang ) {
			return '';
		}

		$stored = $manager->detect_post_language( $post_id );

		if ( $stored && self::id_of( $stored ) === self::id_of( $lang ) ) {
			return '';
		}

		$sibling = $manager->get_translation_id( $post_id, $slug );

		return $sibling && $sibling !== $post_id ? '' : $slug;
	}

	/**
	 * A language row's id (rows are untyped).
	 *
	 * @param object $row Language row.
	 * @return int
	 */
	private static function id_of( object $row ): int {
		$id = get_object_vars( $row )['id'] ?? 0;

		return is_numeric( $id ) ? (int) $id : 0;
	}
}
