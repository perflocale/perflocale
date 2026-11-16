<?php
/**
 * Content ReSync base store: the version gate and the snapshot formulas.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Util\Scalar;
use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The gate every resync entry point checks before it touches the resync tables, and the formulas they store
 * (content hash, canonical field snapshot and digest). The formulas are pure so a stored row can be verified alone.
 */
final class Store {

	/** The database version that creates the two resync tables (schema step 2). */
	public const DB_VERSION = 2;

	/** The newest database version these statements understand; equal to PERFLOCALE_DB_VERSION (ResyncStoreTest). */
	public const MAX_DB_VERSION = 2;

	/**
	 * Whether ReSync may run: the resync tables exist (an autoloaded per-blog option, no query) and the database driver
	 * is not the SQLite integration's legacy translator.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return self::has_tables() && ! self::legacy_sqlite();
	}

	/**
	 * Whether the ReSync tables exist as this build knows them (an autoloaded per-blog option, no query). Deletions and
	 * wipes of their rows run on every driver, also where available() is false. A newer build's schema may hold rows these
	 * statements do not understand: no read or write at all, deletions included.
	 *
	 * @return bool
	 */
	public static function has_tables(): bool {
		$stored = Scalar::int( get_option( 'perflocale_db_version', 0 ) );

		return $stored >= self::DB_VERSION && $stored <= self::MAX_DB_VERSION;
	}

	/**
	 * Whether the SQLite Database Integration runs its legacy translator (2.2.x without WP_SQLITE_AST_DRIVER), which
	 * rewrites some ReSync statements wrongly; 2.2.x with the AST driver and 3.x load WP_SQLite_Driver instead.
	 *
	 * @return bool
	 */
	public static function legacy_sqlite(): bool {
		return defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) && class_exists( 'WP_SQLite_Translator', false );
	}

	/**
	 * Why ReSync cannot run here (available() is false).
	 *
	 * @return string
	 */
	public static function unavailable_text(): string {
		return self::legacy_sqlite()
			? __( 'Content ReSync does not support this SQLite driver. Update the SQLite Database Integration plugin, then try again.', 'perflocale' )
			: __( 'Content ReSync is not ready: the database update has not run yet.', 'perflocale' );
	}

	/**
	 * The content hash of a snapshot; must equal ContentChangeDetector::post_hash() for the same post.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @param string $excerpt Excerpt.
	 * @return string
	 */
	public static function content_hash( string $title, string $content, string $excerpt ): string {
		return StateHash::post( $title, $content, $excerpt );
	}

	/**
	 * The canonical field snapshot `{"keys": [...], "values": {...}}`, keys sorted, null for a key without a row.
	 * A base adds `seen`, the row digest of the source's other keys (outside the digest: a key not yet in scope).
	 *
	 * @param array<string, string|null> $values Value per key.
	 * @param array<string, string>|null $seen   Row digest per other key ({@see self::rows_digest()}), null: none.
	 * @return string JSON.
	 */
	public static function fields_json( array $values, ?array $seen = null ): string {
		ksort( $values, SORT_STRING );

		$clean = [];

		foreach ( $values as $key => $value ) {
			$clean[ (string) $key ] = null === $value ? null : (string) $value;
		}

		$doc = [
			'keys'   => array_keys( $clean ),
			'values' => (object) $clean,
		];

		if ( null !== $seen ) {
			ksort( $seen, SORT_STRING );
			$doc['seen'] = (object) $seen;
		}

		$json = wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '{"keys":[],"values":{}}';
	}

	/**
	 * Decode a stored field snapshot.
	 *
	 * @param string $json The `fields` column.
	 * @return array{keys: list<string>, values: array<string, string|null>}
	 */
	public static function decode_fields( string $json ): array {
		$empty = [
			'keys'   => [],
			'values' => [],
		];

		if ( '' === $json ) {
			return $empty;
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) || ! is_array( $decoded['keys'] ?? null ) || ! is_array( $decoded['values'] ?? null ) ) {
			return $empty;
		}

		$values = [];

		foreach ( $decoded['values'] as $key => $value ) {
			$values[ (string) $key ] = null === $value ? null : Scalar::str( $value );
		}

		return [
			'keys'   => self::key_list( $decoded['keys'] ),
			'values' => $values,
		];
	}

	/**
	 * The string entries of a stored key list; a numeric key is encoded as a JSON number (array_keys() gives an int).
	 *
	 * @param array<mixed> $keys Decoded `keys`.
	 * @return list<string>
	 */
	private static function key_list( array $keys ): array {
		$out = [];

		foreach ( $keys as $key ) {
			if ( is_string( $key ) || is_int( $key ) ) {
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/**
	 * A base's key list and its `seen` map (null for a base stored without one), from the `fields` column.
	 *
	 * @param string $json The `fields` column.
	 * @return array{keys: list<string>, seen: array<string, string>|null}
	 */
	public static function base_keys( string $json ): array {
		$decoded = '' === $json ? null : json_decode( $json, true );

		if ( ! is_array( $decoded ) || ! is_array( $decoded['keys'] ?? null ) || ! is_array( $decoded['values'] ?? null ) ) {
			return [
				'keys' => [],
				'seen' => null,
			];
		}

		$seen = null;

		if ( is_array( $decoded['seen'] ?? null ) ) {
			$seen = [];

			foreach ( $decoded['seen'] as $key => $digest ) {
				if ( is_string( $digest ) ) {
					$seen[ (string) $key ] = $digest;
				}
			}
		}

		return [
			'keys' => self::key_list( $decoded['keys'] ),
			'seen' => $seen,
		];
	}

	/**
	 * The digest of one key's meta rows, as stored (64 bits: a change check, not a security one).
	 *
	 * @param array<mixed> $rows The key's rows, in row order (keys ignored).
	 * @return string
	 */
	public static function rows_digest( array $rows ): string {
		return substr( hash( 'sha256', serialize( array_map( static fn ( mixed $row ): string => Scalar::str( $row ), array_values( $rows ) ) ) ), 0, 16 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact bytes of a string list, never unserialized.
	}

	/**
	 * The digest of a field snapshot: sha256 of its canonical JSON.
	 *
	 * @param array<string, string|null> $values Value per key.
	 * @return string
	 */
	public static function fields_digest( array $values ): string {
		return hash( 'sha256', self::fields_json( $values ) );
	}

	/**
	 * The digest of the listed keys in a live value map (a missing key counts as no row); others never count.
	 *
	 * @param array<int, string>         $keys Key list (the base's list).
	 * @param array<string, string|null> $live Live value per key.
	 * @return string
	 */
	public static function digest_for_keys( array $keys, array $live ): string {
		$values = [];

		foreach ( $keys as $key ) {
			$values[ $key ] = array_key_exists( $key, $live ) ? $live[ $key ] : null;
		}

		return self::fields_digest( $values );
	}

	/**
	 * The ownership record of written values: sha256 per value (keys without a value are not owned).
	 *
	 * @param array<string, string|null> $values Value per key.
	 * @return array<string, string>
	 */
	public static function owned_hashes( array $values ): array {
		$out = [];

		foreach ( $values as $key => $value ) {
			if ( null !== $value ) {
				$out[ (string) $key ] = hash( 'sha256', (string) $value );
			}
		}

		return $out;
	}
}
