<?php
/**
 * Post meta behind the storage interface.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

use PerfLocale\Helper;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post meta. Single-row writes and deletes are compare-and-set on the row id and the digest of its stored bytes,
 * so an empty, '0' or deleted previous row is protected too. Fires core's meta hooks and drops the meta cache.
 * A multi-row key is refused here: the structure sync writes it row by row.
 */
final class PostMetaStorage implements FieldStorage {

	/**
	 * The rows a post meta key holds, [] when absent.
	 *
	 * @param int|string $object_id Post id.
	 * @param string     $key       Meta key.
	 * @return array<int, mixed>
	 */
	public function read( int|string $object_id, string $key ): array {
		$rows = get_post_meta( (int) $object_id, $key, false );

		return is_array( $rows ) ? array_values( $rows ) : [];
	}

	/**
	 * The rows read() gives, from one key's SELECT: a write drops the meta cache, so read() would reload every key.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return array<int, mixed>|null Null when the read failed.
	 */
	public function read_table( int $post_id, string $key ): ?array {
		$rows = $this->rows( $post_id, $key );

		return null === $rows ? null : array_map( static fn( array $row ): mixed => null === $row['value'] ? null : maybe_unserialize( $row['value'] ), $rows );
	}

	/**
	 * Write one value over the rows the caller read.
	 *
	 * @param int|string        $object_id Post id.
	 * @param string            $key       Meta key.
	 * @param mixed             $value     Value (unslashed).
	 * @param array<int, mixed> $prev_rows The key's rows as read ([] = absent).
	 * @return string `written`, `unchanged`, `conflict` or `failed`.
	 */
	public function write( int|string $object_id, string $key, mixed $value, array $prev_rows ): string {
		$post_id = (int) $object_id;

		if ( count( $prev_rows ) > 1 ) {
			return self::FAILED;
		}

		if ( $prev_rows !== [] && maybe_serialize( $prev_rows[0] ) === maybe_serialize( $value ) ) {
			return self::UNCHANGED;
		}

		if ( $prev_rows === [] ) {
			return $this->add( $post_id, $key, $value );
		}

		$row = $this->row_as_read( $post_id, $key, $prev_rows[0], $value );

		if ( is_string( $row ) ) {
			return $row;
		}

		$meta_value = sanitize_meta( $key, $value, 'post', get_object_subtype( 'post', $post_id ) );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's meta filters and actions, fired as core's update_metadata() fires them.
		/** This filter is documented in wp-includes/meta.php */
		$check = apply_filters( 'update_post_metadata', null, $post_id, $key, $meta_value, $prev_rows[0] );

		if ( null !== $check ) {
			return $check ? self::WRITTEN : $this->after_miss( $post_id, $key, $value );
		}

		$stored = maybe_serialize( $meta_value );

		/** This action is documented in wp-includes/meta.php */
		do_action( 'update_post_meta', $row['id'], $post_id, $key, $meta_value );
		/** This action is documented in wp-includes/meta.php */
		do_action( 'update_postmeta', $row['id'], $post_id, $key, $stored );

		$wpdb = self::db();

		// The compare-and-set: the row by id, while it holds the bytes read (digest from the table).
		$sql     = $wpdb->prepare( "UPDATE %i SET meta_value = %s WHERE meta_id = %d AND MD5(COALESCE(meta_value, '')) = %s", $wpdb->postmeta, Scalar::str( $stored ), $row['id'], $row['digest'] );
		$updated = null === $sql ? false : $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( false === $updated ) {
			return self::FAILED;
		}

		if ( 1 !== $updated ) {
			return $this->after_miss( $post_id, $key, $value );
		}

		wp_cache_delete( $post_id, 'post_meta' );

		/** This action is documented in wp-includes/meta.php */
		do_action( 'updated_post_meta', $row['id'], $post_id, $key, $meta_value );
		/** This action is documented in wp-includes/meta.php */
		do_action( 'updated_postmeta', $row['id'], $post_id, $key, $stored );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return self::WRITTEN;
	}

	/**
	 * Delete the one row the caller read, while it still holds the bytes read.
	 * A key that had no row is left alone (a row another writer adds meanwhile stands).
	 *
	 * @param int|string        $object_id Post id.
	 * @param string            $key       Meta key.
	 * @param array<int, mixed> $prev_rows The key's rows as read ([] = absent).
	 * @return string `written` (deleted), `unchanged` (no row), `conflict` or `failed`.
	 */
	public function delete( int|string $object_id, string $key, array $prev_rows ): string {
		$post_id = (int) $object_id;

		if ( count( $prev_rows ) > 1 ) {
			return self::FAILED;
		}

		if ( $prev_rows === [] ) {
			return self::UNCHANGED;
		}

		$row = $this->row_as_read( $post_id, $key, $prev_rows[0], null );

		if ( is_string( $row ) ) {
			return $row;
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's meta filters and actions, fired as core's delete_metadata() fires them.
		/** This filter is documented in wp-includes/meta.php */
		$check = apply_filters( 'delete_post_metadata', null, $post_id, $key, '', false );

		if ( null !== $check ) {
			return $check ? self::WRITTEN : $this->after_miss( $post_id, $key, null );
		}

		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$deleted = $this->delete_row( $post_id, $key, $row['id'], $row['digest'] );

		if ( false === $deleted ) {
			return self::FAILED;
		}

		return 1 === $deleted ? self::WRITTEN : $this->after_miss( $post_id, $key, null );
	}

	/**
	 * Delete one row by id while it holds the bytes with `$digest`, with core's delete hooks.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param int    $id      Meta id.
	 * @param string $digest  MD5 of the bytes the row must hold.
	 * @return int|false Rows deleted.
	 */
	private function delete_row( int $post_id, string $key, int $id, string $digest ): int|false {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's meta actions, fired as core's delete_metadata() fires them.
		/** This action is documented in wp-includes/meta.php */
		do_action( 'delete_post_meta', [ $id ], $post_id, $key, '' );
		/** This action is documented in wp-includes/meta.php */
		do_action( 'delete_postmeta', [ $id ] );

		$wpdb    = self::db();
		$sql     = $wpdb->prepare( "DELETE FROM %i WHERE meta_id = %d AND MD5(COALESCE(meta_value, '')) = %s", $wpdb->postmeta, $id, $digest );
		$deleted = null === $sql ? false : $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		if ( 1 === $deleted ) {
			wp_cache_delete( $post_id, 'post_meta' );

			/** This action is documented in wp-includes/meta.php */
			do_action( 'deleted_post_meta', [ $id ], $post_id, $key, '' );
			/** This action is documented in wp-includes/meta.php */
			do_action( 'deleted_postmeta', [ $id ] );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return is_int( $deleted ) ? $deleted : false;
	}

	/**
	 * Add the key's first row (slashed: the meta API unslashes). add_post_meta( ..., true ) checks uniqueness by a
	 * read before its INSERT, so the rows are read again: another writer's second row makes this a conflict.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value (unslashed).
	 * @return string
	 */
	private function add( int $post_id, string $key, mixed $value ): string {
		$meta_id = add_post_meta( $post_id, $key, Helper::deep_slash( $value ), true );

		if ( false === $meta_id ) {
			return $this->after_miss( $post_id, $key, $value );
		}

		$rows = $this->rows( $post_id, $key );

		if ( null === $rows ) {
			return self::FAILED;
		}

		if ( 1 === count( $rows ) && (int) $meta_id === $rows[0]['id'] ) {
			return self::WRITTEN;
		}

		// Only while the row holds what this add stored: a person may have saved over it by id since (Codex ASTRA-15).
		if ( in_array( (int) $meta_id, array_column( $rows, 'id' ), true ) ) {
			$this->delete_row( $post_id, $key, (int) $meta_id, md5( Scalar::str( maybe_serialize( sanitize_meta( $key, $value, 'post', get_object_subtype( 'post', $post_id ) ) ) ) ) );
		}

		return self::CONFLICT;
	}

	/**
	 * The key's one row from the table while it holds the value the caller read; else `failed`, `unchanged`
	 * (already as the write would leave it) or `conflict`.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $prev    The value the caller read.
	 * @param mixed  $value   The value to write (null for a delete).
	 * @return array{id: int, value: string|null, digest: string}|string
	 */
	private function row_as_read( int $post_id, string $key, mixed $prev, mixed $value ): array|string {
		$rows = $this->rows( $post_id, $key );

		if ( null === $rows ) {
			return self::FAILED;
		}

		if ( 1 !== count( $rows ) ) {
			return null === $value && [] === $rows ? self::UNCHANGED : self::CONFLICT;
		}

		if ( Scalar::str( $rows[0]['value'] ) !== Scalar::str( maybe_serialize( $prev ) ) ) {
			return null !== $value && Scalar::str( $rows[0]['value'] ) === Scalar::str( maybe_serialize( $value ) ) ? self::UNCHANGED : self::CONFLICT;
		}

		return $rows[0];
	}

	/**
	 * What a write that matched no row amounts to: `unchanged`, `failed` or `conflict`.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   The value written (null for a delete).
	 * @return string
	 */
	private function after_miss( int $post_id, string $key, mixed $value ): string {
		$rows = $this->rows( $post_id, $key );

		if ( null === $rows ) {
			return self::FAILED;
		}

		if ( null === $value ) {
			return [] === $rows ? self::UNCHANGED : self::CONFLICT;
		}

		return 1 === count( $rows ) && Scalar::str( $rows[0]['value'] ) === Scalar::str( maybe_serialize( $value ) ) ? self::UNCHANGED : self::CONFLICT;
	}

	/**
	 * The key's rows from the table (never a cache) with the table's digest of them; null when the read failed.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return list<array{id: int, value: string|null, digest: string}>|null
	 */
	private function rows( int $post_id, string $key ): ?array {
		$wpdb = self::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The compare-and-set reads the table, never a cache.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value, MD5(COALESCE(meta_value, '')) AS digest FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $wpdb->postmeta, $post_id, $key ), ARRAY_A );

		if ( ! is_array( $found ) || '' !== $wpdb->last_error ) {
			return null;
		}

		$out = [];

		foreach ( $found as $row ) {
			$out[] = [
				'id'     => Scalar::int( $row['meta_id'] ?? 0 ),
				'value'  => isset( $row['meta_value'] ) ? Scalar::str( $row['meta_value'] ) : null,
				'digest' => Scalar::str( $row['digest'] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * WordPress's database object.
	 *
	 * @return \wpdb
	 * @throws \RuntimeException When it is missing.
	 */
	private static function db(): \wpdb {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'The WordPress database object is not available.' );
		}

		return $wpdb;
	}
}
