<?php
/**
 * Object-cache diagnostics: refused writes and stored sizes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The refused-write log, the refused string map flag (Site Health) and the stored size of L3 rows (Strings page).
 * Its own class: CacheManager loads on every request and hands over only after a refused write.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class CacheDiagnostics {

	/**
	 * The `perflocale_strings` key of the flag a refused string map leaves: fixed, without the group's generation, so
	 * reading it reads no option and a string save does not hide it.
	 */
	public const REFUSED_MAP_KEY = 'string_map_refused';

	/** The `perflocale_strings` key prefix of a language map's measured size; the language id follows. */
	public const STRING_MAP_BYTES_PREFIX = 'string_map_bytes_';

	/** The key prefix of a language's string map in `perflocale_strings`. */
	private const STRING_MAP_PREFIX = 'all_str2_translations_';

	/**
	 * The object cache refused an L2 write (CacheManager::set()): log it once per request, flag a string map.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @param mixed  $value The refused value.
	 * @return void
	 */
	public static function refused( string $key, string $group, mixed $value ): void {
		self::log_refused_l2_write( $key, $group, $value );
		self::record_refused_map( $key, $group, $value );
	}

	/**
	 * This request's refusal flags: `l2` after a refused write, `map` after a refused string map left its flag.
	 *
	 * @param string $set A flag to set; 'reset' clears both (tests); '' only reads.
	 * @return array{l2: bool, map: bool}
	 */
	private static function refusals( string $set = '' ): array {
		/**
		 * The flags.
		 *
		 * @var array{l2: bool, map: bool} $flags
		 */
		static $flags = [
			'l2'  => false,
			'map' => false,
		];

		if ( 'l2' === $set || 'map' === $set ) {
			$flags[ $set ] = true;
		} elseif ( 'reset' === $set ) {
			$flags = [
				'l2'  => false,
				'map' => false,
			];
		}

		return $flags;
	}

	/**
	 * Whether the object cache refused a write in this request.
	 *
	 * @return bool
	 */
	public static function l2_write_was_refused(): bool {
		return self::refusals()['l2'];
	}

	/**
	 * The flag the last refused string map left (map key, entry count, time), or null; for Site Health.
	 *
	 * @return array{key: string, entries: int, time: int}|null
	 */
	public static function refused_string_map(): ?array {
		$flag = wp_cache_get( self::REFUSED_MAP_KEY, 'perflocale_strings' );

		if ( ! is_array( $flag ) || ! is_string( $flag['key'] ?? null ) || ! is_int( $flag['entries'] ?? null ) || ! is_int( $flag['time'] ?? null ) ) {
			return null;
		}

		return [
			'key'     => $flag['key'],
			'entries' => $flag['entries'],
			'time'    => $flag['time'],
		];
	}

	/**
	 * Leave the refused-map flag in the object cache itself for a day, once per request; no database write. The
	 * entries are counted, never serialized.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @param mixed  $value The refused value.
	 * @return void
	 */
	private static function record_refused_map( string $key, string $group, mixed $value ): void {
		if ( self::refusals()['map'] || 'perflocale_strings' !== $group || ! str_starts_with( $key, self::STRING_MAP_PREFIX ) ) {
			return;
		}

		self::refusals( 'map' );

		wp_cache_set(
			self::REFUSED_MAP_KEY,
			[
				'key'     => $key,
				'entries' => is_array( $value ) ? count( $value ) : 0,
				'time'    => time(),
			],
			'perflocale_strings',
			DAY_IN_SECONDS
		);
	}

	/**
	 * Log the request's first refused L2 write (debug log only), with the value's shape, never its size: measuring
	 * would copy the refused map once more.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @param mixed  $value The refused value.
	 * @return void
	 */
	private static function log_refused_l2_write( string $key, string $group, mixed $value ): void {
		if ( self::refusals()['l2'] ) {
			return;
		}

		self::refusals( 'l2' );

		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		if ( is_array( $value ) ) {
			$keys  = count( $value );
			$shape = 1 === $keys ? '1 top-level key' : $keys . ' top-level keys';
		} else {
			$shape = 'a value of type ' . get_debug_type( $value );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for a cache that silently refuses a value.
		error_log(
			sprintf(
				'PerfLocale CacheManager: the object cache refused to store %1$s (group %2$s, %3$s); the value is rebuilt on every request until it fits. A sized cache (Memcached: 1 MB per item) refuses large string maps; files mode serves string translations with no size limit.',
				$key,
				$group,
				$shape
			)
		);
	}

	/**
	 * The stored size in bytes of each key's L3 row, in one LENGTH() query, values never read (on SQLite through the
	 * hex form: its LENGTH() counts characters). Keys without a row are absent; empty under an external object cache.
	 *
	 * @param CacheManager       $cache Cache (its transient names).
	 * @param array<int, string> $keys  Cache keys.
	 * @param string             $group Cache group.
	 * @return array<string, int> Key => bytes of the stored envelope.
	 */
	public static function stored_bytes( CacheManager $cache, array $keys, string $group ): array {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( $keys === [] || ! $wpdb instanceof \wpdb || wp_using_ext_object_cache() ) {
			return [];
		}

		$names = [];

		foreach ( $keys as $key ) {
			$names[ '_transient_' . $cache->derive_transient_key( $key, $group ) ] = $key;
		}

		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The name list is placeholders only; the LENGTH form is a fixed literal.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, ' . ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ? 'LENGTH(HEX(option_value)) / 2' : 'LENGTH(option_value)' ) . " AS bytes FROM %i WHERE option_name IN ({$placeholders})", $wpdb->options, ...array_keys( $names ) ) );
		// phpcs:enable

		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$name = isset( $row->option_name ) && is_string( $row->option_name ) ? $row->option_name : '';

			if ( isset( $names[ $name ] ) && isset( $row->bytes ) && is_numeric( $row->bytes ) ) {
				$out[ $names[ $name ] ] = (int) $row->bytes;
			}
		}

		return $out;
	}

	/**
	 * Drop the measured sizes (StringsPage) of these languages' maps; every write path that deletes a map calls it.
	 * Only an external object cache keeps them.
	 *
	 * @param CacheManager $cache        Cache.
	 * @param array<mixed> $language_ids Language ids.
	 * @return void
	 */
	public static function forget_map_sizes( CacheManager $cache, array $language_ids ): void {
		if ( $language_ids !== [] && wp_using_ext_object_cache() ) {
			$cache->delete_many( array_map( static fn( mixed $id ): string => self::STRING_MAP_BYTES_PREFIX . ( is_numeric( $id ) ? (int) $id : 0 ), array_values( $language_ids ) ), 'perflocale_strings' );
		}
	}
}
