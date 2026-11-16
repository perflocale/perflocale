<?php
/**
 * The byte bound of the post reads of a resync page.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Background\MigrationCacheHelper;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a page's posts in chunks of whole groups (a source with its translations) whose text bytes stay within a
 * budget from the memory left, so no single read can exhaust memory. A post larger than a pair can hold is never
 * read: its pairs are refused first.
 */
final class PostReads {

	/** The columns may() needs, selected beside the bytes of a page read (posts as `p`). */
	public const FIELDS_SQL = "p.post_type AS p_type, p.post_status AS p_status, p.post_author AS p_author, p.post_password <> '' AS p_protected";

	/** The most bytes of post text one read holds. */
	public const READ_BYTES_MAX = 33554432;

	/** Bytes counted per post on top of its text (other columns, the object). */
	public const POST_BYTES = 2048;

	/** One read takes at most this share of the memory left below the ceiling (rows, cache entries, copies). */
	public const ROOM_SHARE = 4;

	/** Text bytes of posts alias `p` on MySQL and MariaDB. */
	private const BYTES_MYSQL = '( LENGTH( p.post_title ) + LENGTH( p.post_content ) + LENGTH( p.post_excerpt ) + LENGTH( p.post_content_filtered ) )';

	/** Bytes counted per meta row on top of its key and value (array entries in the meta cache). */
	public const META_ROW_BYTES = 256;

	/** Key and value bytes of one postmeta row on MySQL and MariaDB. */
	private const META_BYTES_MYSQL = '( COALESCE( LENGTH( meta_key ), 0 ) + COALESCE( LENGTH( meta_value ), 0 ) )';

	/** The same on the SQLite Database Integration. */
	private const META_BYTES_SQLITE = '( COALESCE( LENGTH( HEX( meta_key ) ) / 2, 0 ) + COALESCE( LENGTH( HEX( meta_value ) ) / 2, 0 ) )';

	/** Text bytes of base alias `b` on MySQL and MariaDB. */
	private const BASE_BYTES_MYSQL = '( LENGTH( b.title ) + LENGTH( b.content ) + LENGTH( b.excerpt ) )';

	/** The same on the SQLite Database Integration. */
	private const BASE_BYTES_SQLITE = '( LENGTH( HEX( b.title ) ) / 2 + LENGTH( HEX( b.content ) ) / 2 + LENGTH( HEX( b.excerpt ) ) / 2 )';

	/** The same on the SQLite Database Integration, whose LENGTH() counts characters. */
	private const BYTES_SQLITE = '( LENGTH( HEX( p.post_title ) ) / 2 + LENGTH( HEX( p.post_content ) ) / 2 + LENGTH( HEX( p.post_excerpt ) ) / 2 + LENGTH( HEX( p.post_content_filtered ) ) / 2 )';

	/** Bytes of a pending change's payload on MySQL and MariaDB. */
	private const PAYLOAD_BYTES_MYSQL = 'LENGTH(payload)';

	/** The same on the SQLite Database Integration. */
	private const PAYLOAD_BYTES_SQLITE = 'LENGTH(HEX(payload)) / 2';

	/**
	 * SQL: the text bytes of posts alias `p`; NULL when the row is missing.
	 * SQLite's LENGTH() counts characters, so bytes are half the hex length there.
	 *
	 * @return literal-string
	 */
	public static function bytes_sql(): string {
		return defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? self::BYTES_SQLITE : self::BYTES_MYSQL;
	}

	/**
	 * SQL: the key and value bytes of one postmeta row, counted as bytes_sql() counts a post's.
	 *
	 * @return literal-string
	 */
	public static function meta_bytes_sql(): string {
		return defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? self::META_BYTES_SQLITE : self::META_BYTES_MYSQL;
	}

	/**
	 * SQL: the text bytes of base alias `b`, counted as bytes_sql() counts a post's.
	 *
	 * @return literal-string
	 */
	public static function base_bytes_sql(): string {
		return defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? self::BASE_BYTES_SQLITE : self::BASE_BYTES_MYSQL;
	}

	/**
	 * SQL: the bytes of a pending change's `payload`, counted as bytes_sql() counts a post's.
	 *
	 * @return literal-string
	 */
	public static function payload_bytes_sql(): string {
		return defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? self::PAYLOAD_BYTES_SQLITE : self::PAYLOAD_BYTES_MYSQL;
	}

	/**
	 * The bytes one read may hold, capped at READ_BYTES_MAX; 0 when no memory is left (a chunk is one group).
	 *
	 * @param int $ceiling The memory use (real) at which the caller stops; 0 when `memory_limit` is unbounded.
	 * @return int
	 */
	public static function budget( int $ceiling ): int {
		if ( $ceiling <= 0 ) {
			return self::READ_BYTES_MAX;
		}

		return min( self::READ_BYTES_MAX, intdiv( max( 0, $ceiling - memory_get_usage( true ) ), self::ROOM_SHARE ) );
	}

	/**
	 * The most text bytes one post may hold for a pair to read it; PHP_INT_MAX when memory is unbounded.
	 * Not capped at READ_BYTES_MAX: that cap is per read, not per post.
	 *
	 * @param int $ceiling The memory use (real) at which the caller stops; 0 when `memory_limit` is unbounded.
	 * @return int
	 */
	public static function post_bound( int $ceiling ): int {
		if ( $ceiling <= 0 ) {
			return PHP_INT_MAX;
		}

		return intdiv( max( 0, $ceiling - memory_get_usage( true ) ), self::ROOM_SHARE );
	}

	/**
	 * Whether a post's known text bytes (plus POST_BYTES) pass a bound; an unknown length does not.
	 *
	 * @param array<int, int|null> $sizes Text bytes by post id.
	 * @param int                  $id    Post id.
	 * @param int                  $bound Bytes.
	 * @return bool
	 */
	public static function too_large( array $sizes, int $id, int $bound ): bool {
		return isset( $sizes[ $id ] ) && max( 0, $sizes[ $id ] ) + self::POST_BYTES > $bound;
	}

	/**
	 * The posts of a chunk one read holds: all within the budget, else each that keeps the running total within it.
	 * A post left out is read by the pair that needs it.
	 *
	 * @param array<int, int>      $ids    Post ids, in order (a group's source first).
	 * @param array<int, int|null> $sizes  Text bytes by post id.
	 * @param int                  $budget Bytes.
	 * @return list<int>
	 */
	public static function fitting( array $ids, array $sizes, int $budget ): array {
		$bytes = [];

		foreach ( $ids as $id ) {
			$bytes[ $id ] = max( 0, $sizes[ $id ] ?? 0 ) + self::POST_BYTES;
		}

		if ( array_sum( $bytes ) <= $budget ) {
			return array_values( $ids );
		}

		$out   = [];
		$total = 0;

		foreach ( $bytes as $id => $size ) {
			if ( $total + $size <= $budget ) {
				$out[]  = $id;
				$total += $size;
			}
		}

		return $out;
	}

	/**
	 * The groups in chunks: longest runs of whole groups within the budget; an oversized or unknown-length group
	 * is a chunk alone. A post in two groups of one chunk counts once.
	 *
	 * @param array<int, list<int>> $groups Post ids by group key (a source id), in page order.
	 * @param array<int, int|null>  $sizes  Text bytes by post id; a missing or null entry is not known.
	 * @param int                   $budget Bytes.
	 * @return list<list<int>> Group keys per chunk.
	 */
	public static function chunks( array $groups, array $sizes, int $budget ): array {
		$chunks = [];
		$chunk  = [];
		$seen   = [];
		$bytes  = 0;

		foreach ( $groups as $key => $ids ) {
			$size  = 0;
			$known = true;
			$new   = [];

			foreach ( $ids as $id ) {
				if ( isset( $seen[ $id ] ) || isset( $new[ $id ] ) ) {
					continue;
				}

				$new[ $id ] = true;

				if ( ! isset( $sizes[ $id ] ) ) {
					$known = false;
					continue;
				}

				$size += max( 0, $sizes[ $id ] ) + self::POST_BYTES;
			}

			if ( [] !== $chunk && ( ! $known || $bytes + $size > $budget ) ) {
				$chunks[] = $chunk;
				$chunk    = [];
				$seen     = [];
				$bytes    = 0;
			}

			$chunk[] = $key;
			$seen   += $new;
			$bytes  += $size;

			if ( ! $known ) {
				$chunks[] = $chunk;
				$chunk    = [];
				$seen     = [];
				$bytes    = 0;
			}
		}

		if ( [] !== $chunk ) {
			$chunks[] = $chunk;
		}

		return $chunks;
	}

	/**
	 * Drop a chunk's posts from memory before the next read. With a persistent cache the runtime cache is flushed
	 * instead: a delete per post would also remove the entries from the backend.
	 *
	 * @param array<int, int> $read The ids the chunk read (not those that were in the cache before).
	 * @param array<int, int> $held Every id the chunk held, read or cached.
	 * @return void
	 */
	public static function release( array $read, array $held ): void {
		if ( wp_using_ext_object_cache() ) {
			if ( [] !== $held ) {
				MigrationCacheHelper::flush_runtime_keeping_options();
			}

			return;
		}

		foreach ( $read as $id ) {
			wp_cache_delete( $id, 'posts' );
		}
	}

	/**
	 * may()'s fields of a row read with FIELDS_SQL; null when the post row is missing.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed>|null
	 */
	public static function fields( array $row ): ?array {
		if ( null === ( $row['p_type'] ?? null ) ) {
			return null;
		}

		return [
			'type'      => Scalar::str( $row['p_type'] ),
			'status'    => Scalar::str( $row['p_status'] ?? '' ),
			'author'    => Scalar::int( $row['p_author'] ?? 0 ),
			'protected' => Scalar::int( $row['p_protected'] ?? 1 ) > 0,
		];
	}

	/**
	 * The rights a pair's runner checks first (edit the translation; read the source text, or for a fill edit the
	 * source), from fields: for a pair too large to read, so its refusal names nothing the user may not see.
	 *
	 * @param string                                $kind           fill, or another pair kind.
	 * @param int                                   $source_id      Source id.
	 * @param int                                   $translation_id Translation id, 0 when missing.
	 * @param array<int, array<string, mixed>|null> $fields         may()'s fields by post id.
	 * @return bool
	 */
	public static function may_pair( string $kind, int $source_id, int $translation_id, array $fields ): bool {
		if ( $translation_id > 0 && ! self::may( 'edit', $translation_id, $fields[ $translation_id ] ?? null ) ) {
			return false;
		}

		return self::may( 'fill' === $kind ? 'edit' : 'read_text', $source_id, $fields[ $source_id ] ?? null );
	}

	/**
	 * Whether the current user may edit, or read the text of, a post known by its fields, without loading it: the
	 * primitive capabilities map_meta_cap() asks for (its filter is not run: it may load the post). False when unsure.
	 *
	 * @param string                    $right  edit or read_text.
	 * @param int                       $id     Post id.
	 * @param array<string, mixed>|null $fields type, status, author, protected.
	 * @return bool
	 */
	public static function may( string $right, int $id, ?array $fields ): bool {
		if ( null === $fields ) {
			return false;
		}

		$type   = get_post_type_object( Scalar::str( $fields['type'] ?? '' ) );
		$status = Scalar::str( $fields['status'] ?? '' );
		$object = get_post_status_object( $status );

		// Trash and inherit need the post's meta or parent; revisions their parent.
		if ( ! $type instanceof \WP_Post_Type || null === $object || in_array( $status, [ 'trash', 'inherit' ], true ) || 'revision' === $type->name ) {
			return false;
		}

		$cap    = (array) $type->cap;
		$author = Scalar::int( $fields['author'] ?? 0 );
		$own    = $author > 0 && get_current_user_id() === $author;

		if ( 'read_text' === $right ) {
			if ( ! $type->map_meta_cap ) {
				$caps = [ $cap['read_post'] ?? null ];
			} elseif ( ! empty( $object->public ) || $own ) {
				$caps = [ $cap['read'] ?? null ];
			} elseif ( ! empty( $object->private ) ) {
				$caps = [ $cap['read_private_posts'] ?? null ];
			} else {
				return self::may( 'edit', $id, $fields );
			}

			return self::all( $caps ) && ( empty( $fields['protected'] ) || self::may( 'edit', $id, $fields ) );
		}

		$published = in_array( $status, [ 'publish', 'future' ], true );

		if ( ! $type->map_meta_cap ) {
			$caps = [ $cap['edit_post'] ?? null ];
		} elseif ( $own ) {
			$caps = [ $published ? $cap['edit_published_posts'] ?? null : $cap['edit_posts'] ?? null ];
		} else {
			$caps = [ $cap['edit_others_posts'] ?? null ];

			if ( $published ) {
				$caps[] = $cap['edit_published_posts'] ?? null;
			} elseif ( 'private' === $status ) {
				$caps[] = $cap['edit_private_posts'] ?? null;
			}
		}

		if ( $id > 0 && Scalar::int( get_option( 'wp_page_for_privacy_policy' ) ) === $id ) {
			$caps[] = 'manage_privacy_options';
		}

		return self::all( $caps );
	}

	/**
	 * Whether the current user has every capability; a missing name refuses.
	 *
	 * @param array<int, mixed> $caps Capability names.
	 * @return bool
	 */
	private static function all( array $caps ): bool {
		foreach ( $caps as $cap ) {
			if ( ! is_string( $cap ) || '' === $cap || ! current_user_can( $cap ) ) {
				return false;
			}
		}

		return true;
	}
}
