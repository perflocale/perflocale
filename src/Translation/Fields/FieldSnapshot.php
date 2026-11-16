<?php
/**
 * A field's value before the save being processed.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-static store of a source's values before a save, taken by host pre-write hooks; never persisted.
 * Bounded per process: a scope is released at the end of its post save or by its consumer, so a long write loop
 * holds one object at a time. No snapshot = every rule keeps what the translation has.
 */
final class FieldSnapshot {

	/** Most entries per request. */
	public const MAX_ENTRIES = 200;

	/** Most bytes (serialized) per request. */
	public const MAX_BYTES = 1048576;

	/**
	 * Entries by scope and key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $entries = [];

	/**
	 * Serialized size of every entry, by scope and key.
	 *
	 * @var array<string, array<string, int>>
	 */
	private static array $sizes = [];

	/**
	 * Keys inside a captured container's subtree, by scope.
	 *
	 * @var array<string, array<string, true>>
	 */
	private static array $covered = [];

	/**
	 * Entries recorded in this request.
	 *
	 * @var int
	 */
	private static int $count = 0;

	/**
	 * Bytes recorded in this request (serialized sizes).
	 *
	 * @var int
	 */
	private static int $bytes = 0;

	/**
	 * Whether a bound refused an entry in this request.
	 *
	 * @var bool
	 */
	private static bool $capped = false;

	/**
	 * The scope of a post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function scope_post( int $post_id ): string {
		return get_current_blog_id() . ':post:' . $post_id;
	}

	/**
	 * The scope of an ACF storage id or a Meta Box settings page.
	 *
	 * @param string $storage_id Storage id.
	 * @return string
	 */
	public static function scope_option( string $storage_id ): string {
		return get_current_blog_id() . ':option:' . $storage_id;
	}

	/**
	 * Record a key's value before the save. The first capture of a key stands; later ones and ones over a bound
	 * are refused.
	 *
	 * @param string             $scope  Scope.
	 * @param string             $key    Key.
	 * @param mixed              $rows   The value (a container's subtree as key => value).
	 * @param array<int, string> $covers The concrete keys inside the captured subtree.
	 * @return bool Whether the entry was recorded.
	 */
	public static function take( string $scope, string $key, mixed $rows, array $covers = [] ): bool {
		if ( isset( self::$entries[ $scope ] ) && array_key_exists( $key, self::$entries[ $scope ] ) ) {
			return false;
		}

		$size = strlen( serialize( $rows ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Size of the entry, never stored.

		if ( self::$count >= self::MAX_ENTRIES || self::$bytes + $size > self::MAX_BYTES ) {
			self::refuse( $scope, $key, $size );

			return false;
		}

		self::$entries[ $scope ][ $key ] = $rows;
		self::$sizes[ $scope ][ $key ]   = $size;
		++self::$count;
		self::$bytes += $size;

		foreach ( $covers as $covered ) {
			self::$covered[ $scope ][ $covered ] = true;
		}

		return true;
	}

	/**
	 * The recorded value of a key, null when none.
	 *
	 * @param string $scope Scope.
	 * @param string $key   Key.
	 * @return mixed
	 */
	public static function get( string $scope, string $key ): mixed {
		return self::$entries[ $scope ][ $key ] ?? null;
	}

	/**
	 * Whether a key lies inside a captured container: itself, a recorded subtree key or a `{container}_{index}_`
	 * row key. A sibling sharing the prefix (`slides_total` next to `slides`) is not covered.
	 *
	 * @param string $scope Scope.
	 * @param string $key   Key.
	 * @return bool
	 */
	public static function covers( string $scope, string $key ): bool {
		if ( isset( self::$covered[ $scope ][ $key ] ) ) {
			return true;
		}

		foreach ( array_keys( self::$entries[ $scope ] ?? [] ) as $captured ) {
			$captured = (string) $captured;

			if ( $key === $captured || 1 === preg_match( '/^' . preg_quote( $captured, '/' ) . '_\d+_/', $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drop a scope's entries once read, giving their count and bytes back to the bounds.
	 *
	 * @param string $scope Scope.
	 * @return void
	 */
	public static function release( string $scope ): void {
		foreach ( self::$sizes[ $scope ] ?? [] as $size ) {
			--self::$count;
			self::$bytes -= $size;
		}

		unset( self::$entries[ $scope ], self::$sizes[ $scope ], self::$covered[ $scope ] );
	}

	/**
	 * `wp_after_insert_post` at the last priority: releases the post's scope whether or not a consumer read it.
	 *
	 * @param mixed $post_id Post id, as the hook passes it.
	 * @return void
	 */
	public static function release_post( mixed $post_id ): void {
		if ( is_numeric( $post_id ) && (int) $post_id > 0 ) {
			self::release( self::scope_post( (int) $post_id ) );
		}
	}

	/**
	 * Entries recorded in this request.
	 *
	 * @return int
	 */
	public static function count(): int {
		return self::$count;
	}

	/**
	 * Bytes recorded in this request.
	 *
	 * @return int
	 */
	public static function bytes(): int {
		return self::$bytes;
	}

	/**
	 * Whether a bound refused an entry in this request.
	 *
	 * @return bool
	 */
	public static function capped(): bool {
		return self::$capped;
	}

	/**
	 * Drop every entry.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$entries = [];
		self::$sizes   = [];
		self::$covered = [];
		self::$count   = 0;
		self::$bytes   = 0;
		self::$capped  = false;
	}

	/**
	 * Note a refused entry; logged once per request under WP_DEBUG_LOG.
	 *
	 * @param string $scope Scope.
	 * @param string $key   Key.
	 * @param int    $size  Size of the refused entry.
	 * @return void
	 */
	private static function refuse( string $scope, string $key, int $size ): void {
		if ( self::$capped ) {
			return;
		}

		self::$capped = true;

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'PerfLocale: field snapshot bound reached (%d entries, %d bytes); %s/%s (%d bytes) not recorded.', self::$count, self::$bytes, $scope, $key, $size ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Under WP_DEBUG_LOG only.
		}
	}
}
