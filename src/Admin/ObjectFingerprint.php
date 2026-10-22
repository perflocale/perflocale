<?php
/**
 * Object fingerprints for id-keyed export rows.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A short description of the post or term an export row names by ID, so an
 * import can tell whether that ID still names the same object.
 *
 * Translation links, translated slugs, content hashes and the per-object
 * meta sections name a post or term by its numeric ID. On the site that
 * wrote the file, and on a copy of it, the ID names the same object; on a
 * site whose content was rebuilt (a WXR import, a different install at the
 * same address) it names another object or none. The exporter adds an `_fp`
 * field to each such row; the importer compares it with the object the ID
 * names on this site and leaves the row out when they differ. A file
 * without `_fp` (written before fingerprints existed) is imported as before.
 *
 * The comparison tolerates the edits a site makes to its own content: a
 * post matches when its post type is the same and at least one of its GUID,
 * slug or GMT date is unchanged (a slug edit, a draft published, a domain
 * move that rewrote GUIDs each change one); a term matches when its taxonomy
 * is the same and its slug or its name is unchanged. Each part is stored as
 * the first 12 hex digits of its MD5, so the file holds no new text; an
 * empty slug or an unset date is stored as '' and never counts as a match.
 *
 * Shape: `[ 'post', <post_type>, <guid>, <post_name>, <post_date_gmt> ]`,
 * `[ 'term', <taxonomy>, <slug>, <name> ]` or `[ 'order', <type>,
 * <created GMT> ]` (an order matches on its type and creation date), hashed
 * parts as above.
 */
final class ObjectFingerprint {

	/**
	 * Row field that carries the fingerprint. Not a column of any table, so
	 * the importer's column filter keeps it out of every insert.
	 */
	public const FIELD = '_fp';

	/**
	 * Fingerprints of objects that exist, by ID. IDs with no object are absent.
	 *
	 * @param string          $kind `post`, `term` or `order`.
	 * @param array<int, int> $ids  Object IDs.
	 * @return array<int, array<int, string>>
	 */
	public static function for_ids( string $kind, array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$out = [];

		if ( [] === $ids ) {
			return $out;
		}

		$wpdb = Typed::wpdb();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			if ( 'post' === $kind ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Core table bound with %i; ids bound one %d each.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, guid, post_name, post_date_gmt FROM %i WHERE ID IN ({$placeholders})", $wpdb->posts, ...$chunk ), ARRAY_A );

				foreach ( Typed::rows( $rows ) as $row ) {
					$out[ Typed::int( $row['ID'] ?? 0 ) ] = [ 'post', Typed::str( $row['post_type'] ?? '' ), self::part( $row['guid'] ?? '' ), self::part( $row['post_name'] ?? '' ), self::part( $row['post_date_gmt'] ?? '' ) ];
				}
			} elseif ( 'order' === $kind ) {
				// An order is described by its type and creation date, read
				// from the store WooCommerce uses now. HPOS keeps the same
				// date as the posts table, so a site that switched order
				// storage since the export still matches.
				if ( ExportItems::hpos() ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- WooCommerce table bound with %i; ids bound one %d each.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, date_created_gmt AS created FROM %i WHERE id IN ({$placeholders})", $wpdb->prefix . 'wc_orders', ...$chunk ), ARRAY_A );
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Core table bound with %i; ids bound one %d each.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID AS id, post_type AS type, post_date_gmt AS created FROM %i WHERE ID IN ({$placeholders})", $wpdb->posts, ...$chunk ), ARRAY_A );
				}

				foreach ( Typed::rows( $rows ) as $row ) {
					$out[ Typed::int( $row['id'] ?? 0 ) ] = [ 'order', Typed::str( $row['type'] ?? '' ), self::part( $row['created'] ?? '' ) ];
				}
			} elseif ( 'term' === $kind ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Core tables bound with %i; ids bound one %d each.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, tt.taxonomy, t.slug, t.name FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id WHERE t.term_id IN ({$placeholders}) ORDER BY tt.term_taxonomy_id ASC", $wpdb->terms, $wpdb->term_taxonomy, ...$chunk ), ARRAY_A );

				foreach ( Typed::rows( $rows ) as $row ) {
					$out[ Typed::int( $row['term_id'] ?? 0 ) ] ??= [ 'term', Typed::str( $row['taxonomy'] ?? '' ), self::part( $row['slug'] ?? '' ), self::part( $row['name'] ?? '' ) ];
				}
			}
		}

		return $out;
	}

	/**
	 * Whether the object an ID names here is the one the file's fingerprint
	 * describes.
	 *
	 * @param mixed                   $file The row's `_fp` value.
	 * @param array<int, string>|null $here The fingerprint of the object here, or null when there is none.
	 * @return bool|null True or false; null when the file's value is not a
	 *                   fingerprint this build reads (the row is then not
	 *                   checked).
	 */
	public static function matches( $file, ?array $here ): ?bool {
		$result = self::compare( $file, $here );

		return null === $result ? null : $result['match'];
	}

	/**
	 * Compare a row's fingerprint with the object its ID names here, and say
	 * which parts matched.
	 *
	 * @param mixed                   $file The row's `_fp` value.
	 * @param array<int, string>|null $here The fingerprint of the object here, or null when there is none.
	 * @return array{match: bool, parts: array<int, string>, changed: bool}|null Null when the file's value is
	 *         not a fingerprint this build reads. `parts` names the parts that are equal (`guid`, `slug`,
	 *         `date`, `name`); `changed` is true when the object matched but a part that the file records
	 *         differs here (a renamed slug, a published draft, a moved site).
	 */
	public static function compare( $file, ?array $here ): ?array {
		if ( ! is_array( $file ) || ! isset( $file[0], $file[1] ) || ! is_string( $file[0] ) ) {
			return null;
		}

		$file  = array_values( array_map( static fn( $part ): string => is_scalar( $part ) ? (string) $part : '', $file ) );
		$names = [
			'post'  => [
				2 => 'guid',
				3 => 'slug',
				4 => 'date',
			],
			'term'  => [
				2 => 'slug',
				3 => 'name',
			],
			'order' => [ 2 => 'date' ],
		];

		if ( ! isset( $names[ $file[0] ] ) || count( $file ) < 2 + count( $names[ $file[0] ] ) ) {
			return null;
		}

		$out = [
			'match'   => false,
			'parts'   => [],
			'changed' => false,
		];

		if ( null === $here || ( $here[0] ?? '' ) !== $file[0] || ( $here[1] ?? '' ) !== $file[1] ) {
			return $out;
		}

		foreach ( $names[ $file[0] ] as $i => $name ) {
			if ( self::same( (string) ( $here[ $i ] ?? '' ), $file[ $i ] ) ) {
				$out['parts'][] = $name;
			} elseif ( '' !== $file[ $i ] ) {
				$out['changed'] = true;
			}
		}

		$out['match'] = [] !== $out['parts'];

		if ( ! $out['match'] ) {
			$out['changed'] = false;
		}

		return $out;
	}

	/**
	 * The kind of object (`post` or `term`) a row's type column names, or ''
	 * for one that has no fingerprint (strings and anything else).
	 *
	 * @param mixed $type The row's `type` or `object_type`.
	 * @return string
	 */
	public static function kind( $type ): string {
		return ( 'post' === $type || 'term' === $type ) ? (string) $type : '';
	}

	/**
	 * Two hashed parts are equal and not empty.
	 *
	 * @param string $a Part.
	 * @param string $b Part.
	 * @return bool
	 */
	private static function same( string $a, string $b ): bool {
		return '' !== $a && $a === $b;
	}

	/**
	 * One hashed part; '' for an empty value or an unset date, which say
	 * nothing about which object this is (every draft has them).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function part( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '';
		}

		return substr( md5( $value ), 0, 12 );
	}
}
