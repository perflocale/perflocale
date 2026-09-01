<?php
/**
 * Addon migration / uninstall error log.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent log of per-addon migration + custom-uninstall errors.
 *
 * Records are stored in the `perflocale_addon_migration_errors` option
 * (autoload=false) keyed by `{addon_id}:{stage}:{version}` so the same
 * error doesn't accumulate duplicate entries on every retry. Shows one
 * admin notice per unique record until cleared.
 */
final class AddonMigrationErrors {

	/** Option name. */
	public const OPTION = 'perflocale_addon_migration_errors';

	/**
	 * Record an error.
	 *
	 * @param string     $addon_id Addon identifier.
	 * @param string     $stage Short stage label: 'migrate', 'custom_uninstall',
	 *     'custom_uninstall_skipped', 'purge'.
	 * @param string|int $detail Version number for migrate stage, or message string.
	 * @param string     $message Human-readable error message.
	 * @return void
	 */
	public static function record( string $addon_id, string $stage, string|int $detail, string $message ): void {
		self::with_lock(
			static function () use ( $addon_id, $stage, $detail, $message ) {
				$records = (array) get_option( self::OPTION, [] );
				$key     = $addon_id . ':' . $stage . ':' . $detail;

				$records[ $key ] = [
					'addon_id' => $addon_id,
					'stage'    => $stage,
					'detail'   => $detail,
					'message'  => $message,
					'recorded' => gmdate( 'c' ),
				];

				// Keep the log bounded - newest 200 entries.
				if ( count( $records ) > 200 ) {
					$records = array_slice( $records, -200, null, true );
				}

				update_option( self::OPTION, $records, false );
				return null;
			}
		);
	}

	/**
	 * Serialize a read-modify-write on the error option under the concurrency
	 * lock so two simultaneous record()/clear() calls (multi-addon activation,
	 * overlapping scheduled migrations) can't lose each other's writes. Falls
	 * back to an unlocked run if the lock is unavailable/contended — this is a
	 * best-effort error log, never durable system state.
	 *
	 * @param callable $mutate Returns the mutate result (any value, incl. null).
	 * @return mixed
	 */
	private static function with_lock( callable $mutate ) {
		$run = static fn() => [ 'r' => $mutate() ];

		if ( class_exists( '\\PerfLocale\\Concurrency\\Lock' ) ) {
			$out = \PerfLocale\Concurrency\Lock::with( 'addon_migration_errors', 5, $run );
			if ( is_array( $out ) ) {
				return $out['r'];
			}
		}

		return $run()['r'];
	}

	/**
	 * Clear a specific record or all records.
	 *
	 * @param string|null $addon_id If given, clears only this addon's records.
	 * @return int Number of records removed.
	 */
	public static function clear( ?string $addon_id = null ): int {
		return (int) self::with_lock(
			static function () use ( $addon_id ): int {
				$records = (array) get_option( self::OPTION, [] );

				if ( null === $addon_id ) {
					$removed = count( $records );
					delete_option( self::OPTION );
					return $removed;
				}

				$before  = count( $records );
				$records = array_filter(
					$records,
					static fn( $r ) => ( $r['addon_id'] ?? '' ) !== $addon_id
				);

				if ( count( $records ) === $before ) {
					return 0;
				}

				update_option( self::OPTION, $records, false );
				return $before - count( $records );
			}
		);
	}

	/**
	 * Get all recorded errors.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all(): array {
		return (array) get_option( self::OPTION, [] );
	}

	/**
	 * Return the most recent error record for one addon, or null if it has
	 * no recorded errors. Used by the Addons admin page to surface boot /
	 * migration failures inline on the card so operators don't have to dig
	 * through error_log to find out why an addon is quarantined.
	 *
	 * Records are stored in insertion order — newer overwrites the same
	 * key — so "most recent" = highest-positioned record matching the
	 * addon id.
	 *
	 * The returned shape is best-effort — records are validated as arrays
	 * but individual keys are not asserted, since the persisted option
	 * could have been written by an older plugin version with a different
	 * schema. Callers should null-coalesce each key they read.
	 *
	 * @param string $addon_id
	 * @return array<string, mixed>|null
	 */
	public static function last_for_addon( string $addon_id ): ?array {
		$records = self::get_all();
		if ( empty( $records ) ) {
			return null;
		}

		$found = null;
		foreach ( $records as $rec ) {
			if ( ! is_array( $rec ) || ( $rec['addon_id'] ?? '' ) !== $addon_id ) {
				continue;
			}
			$found = $rec;
		}

		return $found;
	}

	/**
	 * Register admin notice hook.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', [ self::class, 'render_notice' ] );
	}

	/**
	 * Render admin notice with pending errors.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$records = self::get_all();
		if ( [] === $records ) {
			return;
		}

		$latest = array_slice( $records, -5, null, true );
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><ul>',
			esc_html__( 'PerfLocale: addon migration / uninstall issues', 'perflocale' )
		);
		foreach ( $latest as $r ) {
			printf(
				'<li><code>%s</code> - %s - %s</li>',
				esc_html( (string) $r['addon_id'] . ':' . $r['stage'] . ':' . $r['detail'] ),
				esc_html( (string) $r['message'] ),
				esc_html( (string) $r['recorded'] )
			);
		}
		printf(
			'</ul><p>%s</p></div>',
			esc_html__( 'Clear via: wp perflocale addon errors --clear', 'perflocale' )
		);
	}
}
