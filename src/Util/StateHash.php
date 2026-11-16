<?php
/**
 * The one encoding of every hash over several values.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hashes over several values, each framed as "<byte length>:<bytes>" so two different lists never share an input.
 *
 * The stored() form ("v2" + 62 hex) is for every stored or compared hash; the prefix keeps it from ever equalling a legacy
 * unframed hash (64 hex), which legacy_post()/legacy_term() still read until the next save. plain() is in-memory only.
 *
 * @since 1.0.8
 */
final class StateHash {

	/**
	 * The prefix of a stored hash.
	 */
	public const VERSION = 'v2';

	/**
	 * A stored hash of this encoding.
	 */
	private const CURRENT = '/^v2[0-9a-f]{62}\z/';

	/**
	 * The framed input of a list of values.
	 *
	 * @param string ...$values The values, in their fixed order.
	 * @return string
	 */
	public static function frame( string ...$values ): string {
		$out = '';

		foreach ( $values as $value ) {
			$out .= strlen( $value ) . ':' . $value;
		}

		return $out;
	}

	/**
	 * The stored form: "v2" and the first 62 hex characters of the framed sha256.
	 *
	 * @param string ...$values The values, in their fixed order.
	 * @return string 64 characters.
	 */
	public static function stored( string ...$values ): string {
		return self::VERSION . substr( self::digest( $values ), 0, 62 );
	}

	/**
	 * The in-memory form: the framed sha256, 64 hex characters.
	 *
	 * @param string ...$values The values, in their fixed order.
	 * @return string 64 characters.
	 */
	public static function plain( string ...$values ): string {
		return self::digest( $values );
	}

	/**
	 * The sha256 of frame( ...$values ), hashed frame by frame so a large value is never copied.
	 *
	 * @param array<int|string, string> $values The values, in their fixed order.
	 * @return string 64 hex characters.
	 */
	private static function digest( array $values ): string {
		$context = hash_init( 'sha256' );

		foreach ( $values as $value ) {
			hash_update( $context, strlen( $value ) . ':' );
			hash_update( $context, $value );
		}

		return hash_final( $context );
	}

	/**
	 * The state hash of a post: title, content, excerpt.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param string $excerpt Excerpt.
	 * @return string
	 */
	public static function post( string $title, string $content, string $excerpt ): string {
		return self::stored( $title, $content, $excerpt );
	}

	/**
	 * The state hash of a term: name, description.
	 *
	 * @param string $name        Name.
	 * @param string $description Description.
	 * @return string
	 */
	public static function term( string $name, string $description ): string {
		return self::stored( $name, $description );
	}

	/**
	 * The unframed hash a version before 1.0.8 stored for a post.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param string $excerpt Excerpt.
	 * @return string
	 */
	public static function legacy_post( string $title, string $content, string $excerpt ): string {
		return self::legacy( $title, $content, $excerpt );
	}

	/**
	 * The unframed hash a version before 1.0.8 stored for a term.
	 *
	 * @param string $name        Name.
	 * @param string $description Description.
	 * @return string
	 */
	public static function legacy_term( string $name, string $description ): string {
		return self::legacy( $name, $description );
	}

	/**
	 * Whether a value is a stored hash of this encoding.
	 *
	 * @param string $hash Value.
	 * @return bool
	 */
	public static function is_current( string $hash ): bool {
		return 1 === preg_match( self::CURRENT, $hash );
	}

	/**
	 * Whether a value is an unframed hash, or its first $length characters: 0-9a-f only, no prefix.
	 *
	 * @param string $hash   Value.
	 * @param int    $length Its length (64; 16 for a block map anchor).
	 * @return bool
	 */
	public static function is_legacy( string $hash, int $length = 64 ): bool {
		return $length > 0 && strlen( $hash ) === $length && strspn( $hash, '0123456789abcdef' ) === $length;
	}

	/**
	 * Whether a value has the shape of a current or legacy state hash.
	 *
	 * @param string $hash Value.
	 * @return bool
	 */
	public static function is_valid( string $hash ): bool {
		return self::is_current( $hash ) || self::is_legacy( $hash );
	}

	/**
	 * The unframed formula: sha256 of the values joined with "|", without building the joined input.
	 *
	 * @param string ...$values The values.
	 * @return string
	 */
	private static function legacy( string ...$values ): string {
		$context = hash_init( 'sha256' );

		$first = true;

		foreach ( $values as $value ) {
			if ( ! $first ) {
				hash_update( $context, '|' );
			}

			hash_update( $context, $value );
			$first = false;
		}

		return hash_final( $context );
	}
}
