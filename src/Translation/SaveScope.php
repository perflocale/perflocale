<?php
/**
 * Tells which wp_insert_post() call a save callback is running for.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scopes a save callback to one wp_insert_post() call by the hook depth recorded when armed ({@see self::depth()}).
 *
 * That call's `wp_insert_post_data` runs one level deeper, a save started inside it at least two; its revision runs
 * two deeper under `wp_after_insert_post` (or `post_updated` where a site moved revisions there).
 */
final class SaveScope {

	/**
	 * Saves a scoped saver owns right now: "blog:post id" => count.
	 *
	 * @var array<string, int>
	 */
	private static array $claimed = [];

	/**
	 * Mark a post's save as owned by a scoped saver (machine translation, a
	 * copy update), so the general save rule leaves it to that saver.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function claim( int $post_id ): void {
		$key = get_current_blog_id() . ':' . $post_id;

		self::$claimed[ $key ] = ( self::$claimed[ $key ] ?? 0 ) + 1;
	}

	/**
	 * Undo one claim().
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function release( int $post_id ): void {
		$key = get_current_blog_id() . ':' . $post_id;

		if ( ( self::$claimed[ $key ] ?? 0 ) <= 1 ) {
			unset( self::$claimed[ $key ] );
			return;
		}

		--self::$claimed[ $key ];
	}

	/**
	 * Whether a scoped saver owns the save of a post in this blog.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function claimed( int $post_id ): bool {
		return [] !== self::$claimed && isset( self::$claimed[ get_current_blog_id() . ':' . $post_id ] );
	}

	/**
	 * Number of hooks running right now.
	 *
	 * @return int
	 */
	public static function depth(): int {
		$stack = $GLOBALS['wp_current_filter'] ?? [];

		return is_array( $stack ) ? count( $stack ) : 0;
	}

	/**
	 * Whether a `wp_insert_post_data` call is the revision core writes for the
	 * save of $target_id that was armed at $armed_depth.
	 *
	 * @param mixed $data        Post data about to be written.
	 * @param mixed $postarr     Arguments of the save.
	 * @param int   $target_id   Post the armed save wrote.
	 * @param int   $armed_depth depth() when the save was armed.
	 * @return bool
	 */
	public static function is_revision_insert( mixed $data, mixed $postarr, int $target_id, int $armed_depth ): bool {
		if ( $target_id <= 0 || ! is_array( $data ) ) {
			return false;
		}

		$stack = $GLOBALS['wp_current_filter'] ?? [];
		$stack = is_array( $stack ) ? array_values( $stack ) : [];

		if ( count( $stack ) !== $armed_depth + 2 || ! in_array( $stack[ $armed_depth ] ?? '', [ 'wp_after_insert_post', 'post_updated' ], true ) ) {
			return false;
		}

		$id     = is_array( $postarr ) ? ( $postarr['ID'] ?? 0 ) : 0;
		$parent = $data['post_parent'] ?? 0;

		return empty( $id )
			&& 'revision' === ( $data['post_type'] ?? '' )
			&& is_numeric( $parent ) && (int) $parent === $target_id
			&& $target_id . '-revision-v1' === ( $data['post_name'] ?? '' );
	}

	/**
	 * Whether a query is the posts-table UPDATE of one post's row.
	 *
	 * @param string $query   SQL.
	 * @param int    $post_id Post id.
	 * @return bool
	 */
	public static function updates_post( string $query, int $post_id ): bool {
		/**
		 * WordPress database.
		 *
		 * @var \wpdb $wpdb
		 */
		global $wpdb;

		return str_starts_with( $query, 'UPDATE `' . $wpdb->posts . '` SET ' )
			&& 1 === preg_match( "/ WHERE `ID` = '?" . $post_id . "'?\\z/", $query );
	}
}
