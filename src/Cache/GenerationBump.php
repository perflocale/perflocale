<?php
/**
 * The write of a cache group's generation bump (CacheManager::bump_group_generation()).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A group's L2 generation incremented in the database and read back. Its own class: CacheManager loads on every
 * request, a bump runs only on writes and flushes.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class GenerationBump {

	/**
	 * Increment the group's generation; update CacheManager's memo and moved-past flags (passed by reference).
	 *
	 * @param string            $group  Cache group.
	 * @param string            $option Its generation option.
	 * @param array<string,int> $memo   CacheManager::$gen_memo.
	 * @param array<int,true>   $past   CacheManager::$bumped_past.
	 * @return bool Whether the increment was written.
	 */
	public static function run( string $group, string $option, array &$memo, array &$past ): bool {
		global $wpdb;

		$blog     = is_multisite() ? get_current_blog_id() : 0;
		$memo_key = $blog . ':' . $group;
		$previous = $memo[ $memo_key ] ?? null;

		// Atomic increment in the DB, never memo + 1: a long-lived worker's memo is stale, and memo + 1 would roll
		// the generation back and resurrect stale entries. Autoload stays 'yes' (the ON DUPLICATE branch never
		// touches it) so l2_generation() costs no query. A missing row starts at the clock in ms, not 1: after an
		// uninstall an unflushable cache still holds the earlier install's low generations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bumped = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %s, 'yes')
				 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$option,
				(string) (int) ( microtime( true ) * 1000 )
			)
		);

		// Memoise the committed value, read past the stale options cache (the row was written by raw SQL).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$read      = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
		$committed = (int) $read;

		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		// Unlist only a created row (1 affected; an update gives 2): an existing one is autoloaded, and get_option()
		// reads the alloptions dropped above before notoptions.
		if ( 1 === $bumped ) {
			\PerfLocale\Database\NotOptions::unlist( [ $option ] );
		}

		if ( null !== $previous && $committed !== $previous + 1 ) {
			$past[ $blog ] = true;
		}

		$memo[ $memo_key ] = $committed;

		// A failed or malformed read-back (not a positive integer) is not memoised: the next l2_generation() reads the row.
		if ( $committed < 1 || (string) $committed !== $read ) {
			unset( $memo[ $memo_key ] );
		}

		return false !== $bumped;
	}
}
