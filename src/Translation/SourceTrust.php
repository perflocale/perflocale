<?php
/**
 * Whose HTML rules a copied or kept source value follows.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Least trust: a post's markup is kept as written only when the acting user, the post's author and its last editor all
 * have `unfiltered_html`; else it gets the HTML rules of a user without it. Core never KSES-filters meta, so a
 * Contributor's custom field could otherwise reach a page under an administrator's rules. Loaded by require_once.
 */
final class SourceTrust {

	/**
	 * Whether a post's stored markup may be kept as written.
	 *
	 * @param int      $post_id The post the value comes from.
	 * @param int|null $actor   The acting user (null: the current one; 0: nobody, so only the post's writers count).
	 * @return bool
	 */
	public static function trusted( int $post_id, ?int $actor = null ): bool {
		return self::trusted_writers( self::writers( $post_id ), $actor ?? get_current_user_id() );
	}

	/**
	 * Whether these writers (writers()) and the actor (0: none) all have `unfiltered_html`; null never is.
	 *
	 * @param array{int, int}|null $writers Author and last editor.
	 * @param int                  $actor   Acting user.
	 * @return bool
	 */
	public static function trusted_writers( ?array $writers, int $actor ): bool {
		if ( null === $writers ) {
			return false;
		}

		$users = [ $writers[0] ];

		foreach ( [ $writers[1], $actor ] as $user ) {
			if ( $user > 0 ) {
				$users[] = $user;
			}
		}

		foreach ( array_unique( $users ) as $user ) {
			if ( $user <= 0 || ! user_can( $user, 'unfiltered_html' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A post's author and last editor (0: no `_edit_last` row), read after a meta load checked for a database error.
	 * Null when the post, the load or the row is unreadable, or the row is not one user id: such a post is untrusted.
	 *
	 * @param int $post_id Post id.
	 * @return array{int, int}|null
	 */
	public static function writers( int $post_id ): ?array {
		global $wpdb;

		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post || ! is_object( $wpdb ) ) {
			return null;
		}

		wp_cache_delete( $post_id, 'post_meta' );
		$rows = get_post_meta( $post_id, '_edit_last', false );

		if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
			wp_cache_delete( $post_id, 'post_meta' );

			return null;
		}

		if ( [] === $rows ) {
			return [ (int) $post->post_author, 0 ];
		}

		$last = 1 === count( $rows ) ? $rows[0] : null;

		return is_string( $last ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $last ) ? [ (int) $post->post_author, (int) $last ] : null;
	}

	/**
	 * A source text for machine translation from an untrusted post: the MT allowlist, then KSES (what such a user
	 * could save).
	 *
	 * @param string $value Source text.
	 * @return string
	 */
	public static function mt_source( string $value ): string {
		if ( ! str_contains( $value, '<' ) ) {
			return $value;
		}

		$clean = wp_kses_post( \PerfLocale\MachineTranslation\TranslationService::sanitize_mt_value( $value ) );

		return self::lone_amp( $value ) === $clean ? $value : $clean;
	}

	/**
	 * A meta row from an untrusted post as a user without `unfiltered_html` may store it: strings through KSES, arrays
	 * leaf by leaf. Null when a structured string (JSON) or an object would change: the row is then not copied.
	 *
	 * @param mixed $value Row as read (unslashed).
	 * @param int   $depth Nesting depth.
	 * @return mixed
	 */
	public static function least_row( mixed $value, int $depth = 0 ): mixed {
		if ( is_array( $value ) ) {
			if ( $depth > 32 ) {
				return null;
			}

			foreach ( $value as $k => $leaf ) {
				$clean = self::least_row( $leaf, $depth + 1 );

				if ( null === $clean && null !== $leaf ) {
					return null;
				}

				$value[ $k ] = $clean;
			}

			return $value;
		}

		if ( is_object( $value ) ) {
			return self::holds_markup( serialize( $value ) ) ? null : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A read-only probe of a stored row.
		}

		if ( ! is_string( $value ) || ! str_contains( $value, '<' ) ) {
			return $value;
		}

		$clean = wp_kses_post( $value );

		if ( self::lone_amp( $value ) === $clean ) {
			return $value;
		}

		// KSES would rewrite the escaping of JSON or builder data: not copied rather than corrupted.
		$start = ltrim( $value )[0] ?? '';

		return '{' === $start || '[' === $start ? null : $clean;
	}

	/**
	 * Whether serialized data holds a tag KSES would remove.
	 *
	 * @param string $data Data.
	 * @return bool
	 */
	private static function holds_markup( string $data ): bool {
		return str_contains( $data, '<' ) && wp_kses_post( $data ) !== self::lone_amp( $data );
	}

	/**
	 * A text with each `&` that cannot begin a character reference written as `&amp;`.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function lone_amp( string $value ): string {
		return (string) preg_replace( '/&(?![A-Za-z0-9#])/', '&amp;', $value );
	}
}
