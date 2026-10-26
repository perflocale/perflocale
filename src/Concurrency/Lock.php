<?php
/**
 * Atomic cross-request locks for critical sections.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Concurrency;

use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic, TTL-bounded cross-request lock: an `INSERT IGNORE` of a wp_options row holding `<expiry>|<token>`.
 *
 * Not add_option(): its ON DUPLICATE KEY UPDATE can let two callers both succeed. Release and expired takeover are
 * compare-and-set on the stored value, so a holder past its TTL never deletes its successor's row. Acquiring does not
 * refresh `notoptions`; see docs-ai/11-code-rationale.md, "Lock: row protocol and option caches".
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class Lock {

	/**
	 * Prefix for lock option names.
	 */
	private const PREFIX = 'perflocale_lock_';

	/**
	 * Cron hook used by the expired-lock cleanup job.
	 */
	public const CLEANUP_HOOK = 'perflocale_lock_cleanup';

	/**
	 * Separator between the expiry and the per-acquire token; no token ever contains it.
	 */
	private const DELIMITER = '|';

	/**
	 * Locks this request holds: "blog:option name" => the exact stored value (the CAS value for release()).
	 *
	 * A second acquire() of a held lock returns false. Keyed by blog: each blog has its own options table.
	 *
	 * @var array<string, string>
	 */
	private static array $owned = [];

	/**
	 * Per-blog key for the {@see $owned} map.
	 *
	 * @param string $key Lock option name (already DB-prefixed).
	 * @return string
	 */
	private static function owned_key( string $key ): string {
		return ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $key;
	}

	/**
	 * Seconds until a held lock self-expires, or 0 when it is not held.
	 *
	 * Only the acquiring process can release(), so a run that fatals holds its lock until the TTL:
	 * callers show this wait. Reads the row itself; the options cache may not have it.
	 *
	 * @param string $name Lock name (unprefixed, as passed to acquire()).
	 * @return int Seconds remaining, 0 when free or already expired.
	 */
	public static function seconds_remaining( string $name ): int {
		global $wpdb;

		$key = self::PREFIX . $name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key )
		);

		if ( ! is_string( $value ) || $value === '' ) {
			return 0;
		}

		$expires = (int) strtok( $value, '|' );

		return max( 0, $expires - time() );
	}

	/**
	 * Attempt to atomically acquire a named lock.
	 *
	 * @param string $name Lock name (will be prefixed to avoid collisions).
	 * @param int    $ttl Seconds until the lock self-expires (frees it after a crash).
	 * @return bool True if the lock was acquired.
	 */
	public static function acquire( string $name, int $ttl = 15 ): bool {
		global $wpdb;

		$key       = self::PREFIX . $name;
		$owned_key = self::owned_key( $key );

		// Held by this request: refuse re-entry without a query, unless our expiry has passed.
		if ( isset( self::$owned[ $owned_key ] ) ) {
			$owned_expiry = self::parse_expiry( (string) self::$owned[ $owned_key ] );
			if ( $owned_expiry > 0 && $owned_expiry <= time() ) {
				unset( self::$owned[ $owned_key ] );
			} else {
				return false;
			}
		}

		$expires = time() + max( 1, $ttl );
		$value   = self::format_value( $expires, self::generate_token() );

		// The unique option_name key lets exactly one concurrent INSERT IGNORE affect a row.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$value,
				'no'
			)
		);

		if ( 1 === $inserted ) {
			self::invalidate_option_caches( $key );
			self::$owned[ $owned_key ] = $value;
			return true;
		}

		// Row exists: read its expiry past the object cache, which another process's release leaves stale.
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);

		// Released between the INSERT and the SELECT: retry the INSERT once.
		if ( $stored === null ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					$key,
					$value,
					'no'
				)
			);

			if ( 1 === $inserted ) {
				self::invalidate_option_caches( $key );
				self::$owned[ $owned_key ] = $value;
				return true;
			}

			return false;
		}

		$stored_expiry = self::parse_expiry( (string) $stored );

		if ( $stored_expiry <= 0 || $stored_expiry >= time() ) {
			// Live, or unparseable (treated as live: never take over an unexpected row).
			return false;
		}

		// Expired: CAS takeover, matching only the expired value read above, so one contender wins.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$key,
				(string) $stored
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( 1 === (int) $updated ) {
			self::invalidate_option_caches( $key );
			self::$owned[ $owned_key ] = $value;
			return true;
		}

		return false;
	}

	/**
	 * Release a lock this request acquired; a no-op for any other lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( string $name ): void {
		global $wpdb;

		$key         = self::PREFIX . $name;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;
		unset( self::$owned[ $owned_key ] );

		if ( $owned_value === null ) {
			return;
		}

		// Deletes only our stamped value: a lock taken over after our TTL is left alone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$owned_value
			)
		);
		self::invalidate_option_caches( $key );
	}

	/**
	 * Move a held lock's expiry to $ttl seconds from the current time (CAS: a lock taken over is not touched).
	 *
	 * Lets a long run keep a short TTL, so a crashed one still frees the lock soon.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds from the current time.
	 * @return bool False when this request does not hold the lock.
	 */
	public static function extend( string $name, int $ttl ): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$key       = self::PREFIX . $name;
		$owned_key = self::owned_key( $key );
		$owned     = self::$owned[ $owned_key ] ?? null;

		if ( null === $owned ) {
			return false;
		}

		$value = self::format_value( time() + max( 1, $ttl ), self::generate_token() );

		$sql = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, $key, $owned );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared on the line above.
		$updated = is_string( $sql ) ? $wpdb->query( $sql ) : false;

		if ( 1 !== (int) $updated ) {
			return false;
		}

		self::invalidate_option_caches( $key );
		self::$owned[ $owned_key ] = $value;

		return true;
	}

	/**
	 * Run a callable under a lock, released in a finally block.
	 *
	 * @param string   $name Lock name.
	 * @param int      $ttl TTL in seconds.
	 * @param callable $callback Callback to run when the lock is held.
	 * @return mixed Callback return value, or null when the lock was busy (return a non-null sentinel to tell them apart).
	 */
	public static function with( string $name, int $ttl, callable $callback ): mixed {
		// Same-request re-entry with the same name is a caller bug: the inner acquire() fails and returns null.
		static $held = [];

		// Keyed by blog like $owned: the same name on another blog is another row, so nesting it is legitimate.
		$held_key = self::owned_key( $name );

		if ( isset( $held[ $held_key ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s is the lock name. */
						__( 'Lock::with( "%s", ... ) cannot be re-entered for the same lock name in the same request - the inner acquire() will fail (returning null) and the outer release() will run at the wrong time. Refactor to flatten the nested critical section.', 'perflocale' ),
						$name
					)
				),
				'1.0.0'
			);
		}

		if ( ! self::acquire( $name, $ttl ) ) {
			return null;
		}

		$held[ $held_key ] = true;

		try {
			return $callback();
		} finally {
			unset( $held[ $held_key ] );
			self::release( $name );
		}
	}

	/**
	 * Delete expired and malformed lock rows that crashed requests left (daily cron).
	 *
	 * @return int Number of rows deleted.
	 */
	public static function reap_expired(): int {
		if ( \PerfLocale\Plugin::is_uninstalling() ) {
			return 0;
		}

		// For the Jobs page's last-run record.
		$started_at = time();

		global $wpdb;

		$now    = time();
		$prefix = self::PREFIX;

		// Keeps only live `<expiry>|<token>` rows: acquire() never takes over a malformed row, so this clears it.
		// `\\\\` here is MySQL `\\`, REGEXP `\`; INSTR()/SUBSTR(), not SUBSTRING_INDEX(), for the SQLite integration.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND NOT (
					option_value REGEXP '^[0-9]+\\\\|'
					AND CAST(CASE WHEN INSTR(option_value, '|') > 0 THEN SUBSTR(option_value, 1, INSTR(option_value, '|') - 1) ELSE option_value END AS UNSIGNED) >= %d
				)",
				$wpdb->esc_like( $prefix ) . '%',
				$now
			)
		);

		if ( $deleted > 0 ) {
			wp_cache_delete( 'alloptions', 'options' );

			// Forget the expired locks just deleted (other processes find out by CAS).
			$now_check = time();
			foreach ( self::$owned as $ok => $val ) {
				$exp = self::parse_expiry( (string) $val );
				if ( $exp > 0 && $exp <= $now_check ) {
					unset( self::$owned[ $ok ] );
				}
			}
		}

		\PerfLocale\Background\BackgroundEvents::record_run( self::CLEANUP_HOOK, $started_at );

		return $deleted;
	}

	/**
	 * Build the stored value: `"<expiry>|<token>"`.
	 *
	 * @param int    $expires Unix timestamp at which the lock auto-expires.
	 * @param string $token   Per-acquire random hex string.
	 * @return string
	 */
	private static function format_value( int $expires, string $token ): string {
		return $expires . self::DELIMITER . $token;
	}

	/**
	 * Generate a per-acquire token, unique within one lock's TTL.
	 *
	 * @return string 16 hex characters (uniqid() text without an entropy source); never contains {@see DELIMITER}.
	 */
	private static function generate_token(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			// No OS entropy source: weaker, still unique enough for one lock row.
			return uniqid( '', true ) . dechex( (int) ( microtime( true ) * 1e6 ) );
		}
	}

	/**
	 * Expiry timestamp of a stored `<expiry>|<token>` value; 0 when unparseable (callers treat it as live).
	 *
	 * @param string $stored Raw option_value.
	 * @return int Unix timestamp, or 0 if unparseable.
	 */
	private static function parse_expiry( string $stored ): int {
		$delim_pos = strpos( $stored, self::DELIMITER );

		if ( $delim_pos === false ) {
			return 0;
		}

		$prefix = substr( $stored, 0, $delim_pos );

		// Not ctype_digit(): ext-ctype is missing on some builds and WordPress polyfills none of it.
		if ( $prefix === '' || ! Helper::is_ascii_digits( $prefix ) ) {
			return 0;
		}

		return (int) $prefix;
	}

	/**
	 * Drop the per-key options cache entry of a lock row written with raw SQL.
	 *
	 * Not `notoptions`: dropping it sends every absent-option read to the database, site-wide under a persistent
	 * cache. Not `alloptions`: lock rows are never autoloaded. See docs-ai/11-code-rationale.md, "Lock: row protocol and option caches".
	 *
	 * @param string $key Option name (prefixed).
	 * @return void
	 */
	private static function invalidate_option_caches( string $key ): void {
		wp_cache_delete( $key, 'options' );
	}
}
