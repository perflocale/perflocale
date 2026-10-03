<?php
/**
 * One lock shared by every import from WPML, Polylang and TranslatePress.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Migration;

use PerfLocale\Background\JobLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets only one import run at a time on a blog.
 *
 * Two imports running together split translation sets over several groups
 * and leave empty groups behind, whether they come from WP-CLI, the admin
 * button (inline) or a background job, and whichever source they read. Every
 * import path therefore runs inside {@see run()}, which holds the
 * `migration_import` type lock of {@see JobLock} (one option row, not
 * autoloaded, released in `finally` and again at shutdown).
 *
 * An import that reports progress (every importer calls the heartbeat
 * after each batch) holds the lock for {@see HEARTBEAT_TTL} seconds past its
 * last batch, so a run killed without a shutdown (SIGKILL, out of memory)
 * frees it within minutes. A caller without a heartbeat holds it for
 * {@see DEFAULT_TTL} seconds, the same bound as the background job's own
 * lock.
 *
 * A heartbeat that finds the lock gone, or held by another process (one
 * that took it over after this import went {@see HEARTBEAT_TTL} seconds
 * without a heartbeat), stops the import: it throws a
 * {@see MigrationLockedException} with {@see lost_message()}, and so does
 * every later heartbeat of that run.
 *
 * The lock lives in the blog's options table, so imports on two blogs of a
 * network run side by side.
 */
final class MigrationLock {

	/**
	 * JobLock type key.
	 */
	public const TYPE = 'migration_import';

	/**
	 * Lock lifetime after the last heartbeat, in seconds.
	 */
	public const HEARTBEAT_TTL = 900;

	/**
	 * Lock lifetime for an import without a heartbeat, in seconds.
	 */
	public const DEFAULT_TTL = 4 * HOUR_IN_SECONDS;

	/**
	 * Minimum seconds between two heartbeat writes.
	 */
	private const HEARTBEAT_INTERVAL = 30;

	/**
	 * Blog ids whose lock this process holds.
	 *
	 * @var array<int, true>
	 */
	private static array $held = [];

	/**
	 * Whether the shutdown release is registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Run an import while holding the lock.
	 *
	 * `$import` receives a heartbeat callable; call it after each batch to
	 * keep the lock (it writes at most once per 30 seconds). It throws a
	 * MigrationLockedException when the lock was lost, so the import writes
	 * nothing more. Pass `$heartbeat = true` only when `$import` calls it.
	 *
	 * Callers must not exit inside `$import` (WP_CLI::error(), wp_die()):
	 * exit skips `finally`. The shutdown release covers a fatal error.
	 *
	 * @template T
	 * @param callable(callable():void):T $import    The import.
	 * @param bool                        $heartbeat Whether `$import` calls the heartbeat.
	 * @return T
	 * @throws MigrationLockedException When another import holds the lock, or from the heartbeat when the import lost it.
	 */
	public static function run( callable $import, bool $heartbeat = false ): mixed {
		$ttl = $heartbeat ? self::HEARTBEAT_TTL : self::DEFAULT_TTL;

		if ( ! JobLock::acquire_type( self::TYPE, $ttl ) ) {
			throw new MigrationLockedException( esc_html( self::locked_message() ) );
		}

		$blog_id                = get_current_blog_id();
		self::$held[ $blog_id ] = true;

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( [ self::class, 'release_held' ] );
			self::$shutdown_registered = true;
		}

		$last = time();
		$beat = static function () use ( &$last ): void {
			$now = time();

			if ( $now - $last < self::HEARTBEAT_INTERVAL ) {
				return;
			}

			// JobLock keeps answering false for a lock this process lost, and
			// $last stays put, so every later heartbeat of the run throws too.
			if ( ! JobLock::refresh_type( self::TYPE, self::HEARTBEAT_TTL ) ) {
				throw new MigrationLockedException( esc_html( self::lost_message() ) );
			}

			$last = $now;
		};

		try {
			return $import( $beat );
		} finally {
			JobLock::release_type( self::TYPE );
			unset( self::$held[ $blog_id ] );
		}
	}

	/**
	 * Seconds until the lock expires, or 0 when no import holds it.
	 *
	 * @return int
	 */
	public static function seconds_remaining(): int {
		$stored = get_option( JobLock::TYPE_PREFIX . self::TYPE, '' );

		if ( ! is_string( $stored ) || $stored === '' ) {
			return 0;
		}

		$expires = (int) strtok( $stored, '|' );

		return max( 0, $expires - time() );
	}

	/**
	 * The message shown when an import is refused because another one runs.
	 *
	 * @return string
	 */
	public static function locked_message(): string {
		$minutes = max( 1, (int) ceil( self::seconds_remaining() / MINUTE_IN_SECONDS ) );

		return sprintf(
			/* translators: %d: minutes until the lock of an import that stopped unexpectedly expires */
			_n(
				'Another import is already running. Try again when it has finished. If it stopped unexpectedly, it releases the lock within %d minute.',
				'Another import is already running. Try again when it has finished. If it stopped unexpectedly, it releases the lock within %d minutes.',
				$minutes,
				'perflocale'
			),
			$minutes
		);
	}

	/**
	 * The message of an import stopped by a heartbeat that found the lock
	 * lost.
	 *
	 * @return string
	 */
	public static function lost_message(): string {
		return __( 'The import stopped because another import took over its lock, or the lock was removed, while it was running. What it imported so far is kept. Run the import again when no other import is running; what is already imported is skipped.', 'perflocale' );
	}

	/**
	 * Release the lock of an import that did not reach its `finally`.
	 *
	 * Registered as a shutdown function; a no-op when nothing is held.
	 * JobLock releases only the value this process stamped, so a lock another
	 * process took over is never removed.
	 *
	 * @return void
	 */
	public static function release_held(): void {
		$blog_id = get_current_blog_id();

		if ( isset( self::$held[ $blog_id ] ) ) {
			JobLock::release_type( self::TYPE );
			unset( self::$held[ $blog_id ] );
		}
	}
}
