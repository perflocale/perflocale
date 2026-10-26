<?php
/**
 * Per-job and per-type locks: an atomic INSERT IGNORE plus a compare-and-set reclaim.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

use PerfLocale\Database\NotOptions;
use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps two workers from running the same job (or, per type, the same job type) at once, under both runners.
 *
 * Value `"<expiry>|<token>"` in a non-autoloaded option; never add_option(), which is not race-safe here.
 * Design: docs-ai/11-code-rationale.md, "JobLock: atomic acquire and lost leases".
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
final class JobLock {

	/**
	 * Option name prefix of a job lock (+ job id).
	 */
	public const PREFIX = 'perflocale_job_lock_';

	/**
	 * Option name prefix of a type lock (one job of a type at a time); apart from PREFIX so the two never collide.
	 */
	public const TYPE_PREFIX = 'perflocale_type_lock_';

	/**
	 * Default lock TTL (30 minutes). Too low lets a live but blocked worker lose its lock to a second one; a longer
	 * blocking job overrides AbstractJob::get_lock_ttl() or calls refresh().
	 */
	public const DEFAULT_TTL = 1800;

	/**
	 * Separator between expiry and token in the stored value.
	 */
	private const DELIMITER = '|';

	/**
	 * Lock key (namespaced by blog id) => the exact value this process wrote at acquire: the compare value of
	 * release() and refresh(), so a late holder never clobbers a takeover.
	 *
	 * @var array<string, string>
	 */
	private static array $owned = [];

	/**
	 * Keys whose lease this process held and provably lost; keeps refresh() and release() off the ownerless paths,
	 * which would overwrite or delete the new owner's lease. Cleared by cas_acquire() and release().
	 *
	 * @var array<string, true>
	 */
	private static array $lost = [];

	/**
	 * The blog-namespaced key of the $owned map.
	 *
	 * @param string $key Lock option name (already prefixed).
	 * @return string
	 */
	private static function owned_key( string $key ): string {
		return ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . ':' . $key;
	}

	/**
	 * Acquire the job's lock: INSERT IGNORE, else take an expired row with one conditional UPDATE (cas_acquire()).
	 *
	 * @param string $job_id Job identifier (must match {@see JobState::is_safe_id()}).
	 * @param int    $ttl    Seconds before the lock auto-expires. Default DEFAULT_TTL.
	 * @return bool True if the lock was acquired; false if another holder owns it.
	 */
	public static function acquire( string $job_id, int $ttl = self::DEFAULT_TTL ): bool {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return false;
		}

		$key     = self::PREFIX . $job_id;
		$expires = time() + max( 30, $ttl );

		return self::cas_acquire( $key, $expires );
	}

	/**
	 * Extend the lock of a long-running worker, keeping its token; a no-op (logged) when another worker took it over.
	 *
	 * @param string $job_id Job identifier.
	 * @param int    $ttl    New TTL window from now.
	 * @return void
	 */
	public static function refresh( string $job_id, int $ttl = self::DEFAULT_TTL ): void {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return;
		}

		$key         = self::PREFIX . $job_id;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;

		if ( $owned_value === null && isset( self::$lost[ $owned_key ] ) ) {
			// Held and provably lost: the ownerless overwrite below would give the lease to two workers at once.
			return;
		}

		if ( $owned_value === null ) {
			// No acquire in this request (static state reset between batches): best-effort overwrite.
			update_option( $key, self::format_value( time() + max( 30, $ttl ), self::generate_token() ), false );
			return;
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		// Keep the token and update only while the stored value is ours.
		$token       = self::parse_token( $owned_value );
		$new_expires = time() + max( 30, $ttl );
		$new_value   = self::format_value( $new_expires, $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = %s
				 WHERE option_name = %s
				 AND option_value = %s",
				$new_value,
				$key,
				$owned_value
			)
		);

		if ( 1 === (int) $updated ) {
			self::$owned[ $owned_key ] = $new_value;
			self::forget_cached( $key );
			return;
		}

		// 0 affected rows is also an UPDATE to identical bytes (refresh in the acquire's second, equal TTL): re-read, or
		// release() would stop matching and the lock would leak for its TTL.
		if ( self::current_value( $key ) === $new_value ) {
			self::$owned[ $owned_key ] = $new_value;
			return;
		}

		// Lost ownership: logged, not thrown; callers do not expect this to fail mid-loop.
		if ( function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on lock-loss path.
			error_log( '[perflocale][job-lock] refresh() lost ownership of ' . $key . ' (another worker took over after TTL expiry)' );
		}

		// Also in the Jobs log: error_log is often unreachable on managed hosting.
		JobState::append_log( $job_id, __( 'Lock refresh lost - another worker took over after the lock TTL expired.', 'perflocale' ) );

		unset( self::$owned[ $owned_key ] );
		// Lost, not merely absent: keeps the next refresh() and the finally release() off the new owner's lease.
		self::$lost[ $owned_key ] = true;
	}

	/**
	 * A lock row's value read past the options cache (lock rows are written with raw SQL).
	 *
	 * @param string $key Full option name (already prefixed).
	 * @return string|null Stored value, or null when the row is gone.
	 */
	private static function current_value( string $key ): ?string {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Release the lock (call it in a finally block). The DELETE matches only the value written at acquire, so a
	 * takeover's lock survives.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function release( string $job_id ): void {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return;
		}

		$key         = self::PREFIX . $job_id;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;
		$was_lost    = isset( self::$lost[ $owned_key ] );
		unset( self::$owned[ $owned_key ], self::$lost[ $owned_key ] );

		if ( $owned_value === null && $was_lost ) {
			// Held, lost to a takeover, and unwinding through the worker's finally: the row is another worker's live lease.
			// An operator cancel never recorded a loss and still takes the forced path.
			return;
		}

		// A cancel from another request (REST, CLI) has no owned value; the key names this job only, so deleting it is
		// safe. release_type() never forces: its key is shared by the type.
		self::release_key( $key, $owned_value, true );
	}

	/**
	 * Whether a live lock exists for the job (read only).
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public static function is_held( string $job_id ): bool {
		return self::expires_at( $job_id ) > time();
	}

	/**
	 * Whether the job's lock is held and unexpired, read past the options cache.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public static function is_held_now( string $job_id ): bool {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return false;
		}

		return self::parse_expiry( (string) self::current_value( self::PREFIX . $job_id ) ) > time();
	}

	/**
	 * When the job's lock expires (or expired); read only.
	 *
	 * @param string $job_id Job identifier.
	 * @return int Expiry timestamp, or 0 when there is no lock or its value
	 *             cannot be parsed.
	 */
	public static function expires_at( string $job_id ): int {
		if ( ! JobState::is_safe_id( $job_id ) ) {
			return 0;
		}

		return self::parse_expiry( (string) get_option( self::PREFIX . $job_id, '' ) );
	}

	/**
	 * Acquire the type lock so one job of $type runs at a time (WorkerRunner re-queues the others with a backoff).
	 * Its TTL should be at least the job lock's.
	 *
	 * @param string $type Job type slug (sanitize_key sanitized; only
	 *                     `[a-z0-9_-]` is expected from registered types).
	 * @param int    $ttl  Seconds before the lock auto-expires.
	 * @return bool True if the lock was acquired; false if another holder owns it.
	 */
	public static function acquire_type( string $type, int $ttl = self::DEFAULT_TTL ): bool {
		$type = self::sanitize_type( $type );

		if ( $type === '' ) {
			return false;
		}

		$key     = self::TYPE_PREFIX . $type;
		$expires = time() + max( 30, $ttl );

		return self::cas_acquire( $key, $expires );
	}

	/**
	 * Acquire with INSERT IGNORE, else reclaim an expired row with one conditional UPDATE (one racer wins); the
	 * reclaim also drops the cached copy, as raw SQL bypasses the options API.
	 *
	 * @param string $key     Full option name (already prefixed).
	 * @param int    $expires Unix expiry timestamp.
	 * @return bool True when this process holds the lock.
	 */
	private static function cas_acquire( string $key, int $expires ): bool {
		$value = self::format_value( $expires, self::generate_token() );

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// Raw INSERT IGNORE, atomic through the UNIQUE option_name key; add_option() reports success to every racer.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$value,
				'no'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( 1 === (int) $inserted ) {
			self::forget_cached( $key );
			self::$owned[ self::owned_key( $key ) ] = $value;
			// A fresh acquire ends any recorded loss.
			unset( self::$lost[ self::owned_key( $key ) ] );
			return true;
		}

		// Take the row only while its stored expiry is past; INSTR()/SUBSTR() so SQLite reclaims too.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = %s
				 WHERE option_name = %s
				 AND CAST(CASE WHEN INSTR(option_value, '|') > 0 THEN SUBSTR(option_value, 1, INSTR(option_value, '|') - 1) ELSE option_value END AS UNSIGNED) < %d",
				$value,
				$key,
				time()
			)
		);

		if ( (int) $updated !== 1 ) {
			return false;
		}

		self::forget_cached( $key );

		self::$owned[ self::owned_key( $key ) ] = $value;
		// A successful reclaim ends any recorded loss.
		unset( self::$lost[ self::owned_key( $key ) ] );
		return true;
	}

	/**
	 * Extend the type lock, keeping its token; called with the job's real TTL after the job is built, then from the
	 * progress callback, or a long job's type lock expires and a second job of the type runs.
	 *
	 * @param string $type Job type slug.
	 * @param int    $ttl  New TTL in seconds.
	 * @return bool False when the lock is gone or another holder has it (this
	 *              process lost it); true when this process still holds it.
	 */
	public static function refresh_type( string $type, int $ttl = self::DEFAULT_TTL ): bool {
		$type = self::sanitize_type( $type );

		if ( $type === '' ) {
			return false;
		}

		$key         = self::TYPE_PREFIX . $type;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;

		if ( $owned_value === null && isset( self::$lost[ $owned_key ] ) ) {
			// Provably lost (as in refresh()): an overwrite would break one-job-at-a-time for the type.
			return false;
		}

		if ( $owned_value === null ) {
			// No acquire in this request: best-effort overwrite, as refresh().
			update_option( $key, self::format_value( time() + max( 30, $ttl ), self::generate_token() ), false );
			return true;
		}

		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		$token     = self::parse_token( $owned_value );
		$new_value = self::format_value( time() + max( 30, $ttl ), $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				$key,
				$owned_value
			)
		);

		if ( 1 === (int) $updated ) {
			self::$owned[ $owned_key ] = $new_value;
			self::forget_cached( $key );
			return true;
		}

		// 0 affected can be an identical-bytes UPDATE (acquire_type then refresh_type in one second, equal TTLs): re-read,
		// or the type lock leaks and defers every job of the type as busy.
		if ( self::current_value( $key ) === $new_value ) {
			self::$owned[ $owned_key ] = $new_value;
			return true;
		}

		// Lost: forget it and mark it lost so the ownerless overwrite stays off for this key.
		unset( self::$owned[ $owned_key ] );
		self::$lost[ $owned_key ] = true;

		return false;
	}

	/**
	 * Release the type lock (call it in a finally block).
	 *
	 * @param string $type
	 * @return void
	 */
	public static function release_type( string $type ): void {
		$type = self::sanitize_type( $type );

		if ( $type === '' ) {
			return;
		}

		$key         = self::TYPE_PREFIX . $type;
		$owned_key   = self::owned_key( $key );
		$owned_value = self::$owned[ $owned_key ] ?? null;
		unset( self::$owned[ $owned_key ] );

		self::release_key( $key, $owned_value );
	}

	/**
	 * Release path of release() and release_type().
	 *
	 * @param string      $key         Full option name (already prefixed).
	 * @param string|null $owned_value Value we wrote at acquire time, or
	 *                                 null when called without prior acquire
	 *                                 in this request.
	 * @param bool        $force       Delete even without a recorded owner.
	 *                                 Only safe for keys namespaced to a
	 *                                 single job (see {@see self::release()}).
	 * @return void
	 */
	private static function release_key( string $key, ?string $owned_value, bool $force = false ): void {
		global $wpdb;

		if ( $owned_value === null && ! $force ) {
			return;
		}

		if ( $owned_value === null ) {
			// Forced, ownerless: delete the row.
			delete_option( $key );
			self::forget_cached( $key );
			return;
		}

		if ( ! $wpdb instanceof \wpdb ) {
			// No $wpdb: delete_option() always deletes the row.
			delete_option( $key );
			return;
		}

		// Delete only while the stored value is the one written at acquire.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$owned_value
			)
		);
		self::forget_cached( $key );
	}

	/**
	 * Drop the cached copy of a lock row written with raw SQL and take its name out of `notoptions`.
	 * Only the name: dropping the whole list makes the blog's next request re-read every absent option.
	 *
	 * @param string $key Full option name (already prefixed).
	 * @return void
	 */
	private static function forget_cached( string $key ): void {
		wp_cache_delete( $key, 'options' );
		NotOptions::unlist( [ $key ] );
	}

	/**
	 * Keep a type slug to `[a-z0-9_-]` so a malformed call cannot reach another option key.
	 *
	 * @param string $type
	 * @return string Sanitized type slug, or empty string if invalid.
	 */
	private static function sanitize_type( string $type ): string {
		if ( $type === '' || strlen( $type ) > 64 ) {
			return '';
		}

		return (string) preg_replace( '/[^a-z0-9_-]/', '', $type );
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
	 * A random per-acquire token (as Concurrency\Lock::generate_token(), without depending on it).
	 *
	 * @return string Lowercase hex; never contains {@see DELIMITER}.
	 */
	private static function generate_token(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return uniqid( '', true ) . dechex( (int) ( microtime( true ) * 1e6 ) );
		}
	}

	/**
	 * Parse the integer expiry timestamp out of either storage format.
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

		if ( $prefix === '' || ! Helper::is_ascii_digits( $prefix ) ) {
			return 0;
		}

		return (int) $prefix;
	}

	/**
	 * Extract the token portion from a stored value.
	 *
	 * @param string $stored Raw option_value.
	 * @return string
	 */
	private static function parse_token( string $stored ): string {
		$delim_pos = strpos( $stored, self::DELIMITER );

		if ( $delim_pos === false ) {
			return '';
		}

		return substr( $stored, $delim_pos + 1 );
	}
}
