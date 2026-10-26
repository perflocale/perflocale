<?php
/**
 * Writes of the operator's disabled-addon list, loaded on the first write (admin toggle, WP-CLI, import).
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AddonRegistry::set_disabled() and set_disabled_list(), which forward here.
 *
 * @internal
 */
final class AddonDisabledList {

	/**
	 * Serialised size cap of the disabled list: the option is autoloaded, so every request deserialises it.
	 */
	private const DISABLED_OPTION_MAX_BYTES = 4096;

	/**
	 * Lock around every write, or two concurrent toggles drop one commit; separate from AddonSettings' lock.
	 */
	private const DISABLED_LOCK_NAME = 'addon_disabled_write';

	/** Short TTL - the work inside is a single update_option call. */
	private const DISABLED_LOCK_TTL = 10;

	/**
	 * Set the on/off state of one addon; asking for the state it is in succeeds without a write.
	 *
	 * False for a malformed id, a list over the cap, a held lock (Lock::with() does not wait) or a refused write.
	 *
	 * @param string $id       Addon ID.
	 * @param bool   $disabled Whether the addon should be skipped at boot.
	 * @return bool True when the stored list holds the requested state; false on rejection.
	 */
	public static function set_disabled( string $id, bool $disabled ): bool {
		$id = trim( $id );

		if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'PerfLocale AddonRegistry::set_disabled rejected - invalid addon_id "%s"', substr( $id, 0, 64 ) ) );
			}
			return false;
		}

		$result = \PerfLocale\Concurrency\Lock::with(
			self::DISABLED_LOCK_NAME,
			self::DISABLED_LOCK_TTL,
			static function () use ( $id, $disabled ): bool {
				// Read the committed row: get_option() answers an autoloaded option from this request's `alloptions`,
				// so that copy goes (and the per-key entry, for a row stored with autoload off), for update_option()'s
				// own comparison too.
				wp_cache_delete( AddonRegistry::DISABLED_OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$current = (array) get_option( AddonRegistry::DISABLED_OPTION, [] );

				// Normalised as get_disabled() does, so junk from a direct write is not written back.
				$normalised = [];
				foreach ( $current as $value ) {
					$candidate = is_scalar( $value ) ? (string) $value : '';
					if ( $candidate !== '' && AddonSchemaManager::validate_addon_id( $candidate ) ) {
						$normalised[] = $candidate;
					}
				}
				$current = array_values( array_unique( $normalised ) );
				$before  = $current;

				if ( $disabled ) {
					if ( ! in_array( $id, $current, true ) ) {
						$current[] = $id;
					}
				} else {
					$current = array_values( array_filter( $current, static fn( $value ) => (string) $value !== $id ) );
				}

				// The cap applies to the list after the change.
				$serialised = (string) maybe_serialize( $current );

				if ( strlen( $serialised ) > self::DISABLED_OPTION_MAX_BYTES ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log(
							sprintf(
								'PerfLocale AddonRegistry::set_disabled rejected - disabled-list would exceed %d bytes (current %d)',
								self::DISABLED_OPTION_MAX_BYTES,
								strlen( $serialised )
							)
						);
					}
					return false;
				}

				// update_option() is false for an unchanged value too; an unchanged normalised list means the state holds,
				// even when the write that would drop junk entries is refused.
				if ( ! update_option( AddonRegistry::DISABLED_OPTION, $current, true ) && $current !== $before ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( sprintf( 'PerfLocale AddonRegistry::set_disabled rejected - the options write for "%s" was refused', $id ) );
					}
					return false;
				}
				return true;
			}
		);

		// Null: the lock was held (acquire() does not wait).
		if ( $result === null ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'PerfLocale AddonRegistry::set_disabled rejected - lock contention on "%s"', $id ) );
			}
			return false;
		}

		if ( $result === true ) {
			// Outside the lock: the bootable cache is not authoritative state.
			AddonRegistry::flush_bootable_cache();
		}

		return (bool) $result;
	}

	/**
	 * Replace the whole disabled-addon list in one write (data import), with set_disabled()'s checks and lock.
	 *
	 * An empty array means nothing disabled and is stored, not skipped.
	 *
	 * @param array<int, string> $ids Addon IDs to disable. Non-string /
	 *                                 invalid / duplicate entries are dropped.
	 * @return bool True when the stored list equals the normalised one; false for the cap, a held lock or a refused write.
	 */
	public static function set_disabled_list( array $ids ): bool {
		$normalised = [];
		foreach ( $ids as $value ) {
			$candidate = is_scalar( $value ) ? (string) $value : '';
			if ( $candidate !== '' && AddonSchemaManager::validate_addon_id( $candidate ) ) {
				$normalised[] = $candidate;
			}
		}
		$normalised = array_values( array_unique( $normalised ) );

		$serialised = (string) maybe_serialize( $normalised );
		if ( strlen( $serialised ) > self::DISABLED_OPTION_MAX_BYTES ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'PerfLocale AddonRegistry::set_disabled_list rejected - list would exceed %d bytes (got %d)',
						self::DISABLED_OPTION_MAX_BYTES,
						strlen( $serialised )
					)
				);
			}
			return false;
		}

		$result = \PerfLocale\Concurrency\Lock::with(
			self::DISABLED_LOCK_NAME,
			self::DISABLED_LOCK_TTL,
			static function () use ( $normalised ): bool {
				// The committed row, not the bootstrap `alloptions` copy: a stale copy would pass for a no-op.
				wp_cache_delete( AddonRegistry::DISABLED_OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				$stored = AddonRegistry::get_disabled();

				// update_option() is false for an unchanged value too; only a refusal fails.
				if ( ! update_option( AddonRegistry::DISABLED_OPTION, $normalised, true ) && $stored !== $normalised ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'PerfLocale AddonRegistry::set_disabled_list rejected - the options write was refused' );
					}
					return false;
				}
				return true;
			}
		);

		if ( $result !== true ) {
			return false;
		}

		AddonRegistry::flush_bootable_cache();
		return true;
	}
}
