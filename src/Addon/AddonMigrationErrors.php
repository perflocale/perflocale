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
 * Persistent log of per-addon boot, migration and uninstall errors, in one non-autoloaded option.
 *
 * Keyed by `{addon_id}:{stage}:{detail}`, so a retry replaces its record; the admin notice lists the latest five.
 */
final class AddonMigrationErrors {

	/** Option name. */
	public const OPTION = 'perflocale_addon_migration_errors';

	/**
	 * Record an error.
	 *
	 * @param string     $addon_id Addon identifier.
	 * @param string     $stage Stage label, e.g. 'boot', 'schema', 'migrate', 'purge_validation', 'custom_uninstall'.
	 * @param string|int $detail The version for 'migrate' and 'schema', else a short label.
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

				// Bounded to the last 200 entries.
				if ( count( $records ) > 200 ) {
					$records = array_slice( $records, -200, null, true );
				}

				update_option( self::OPTION, $records, false );
				return null;
			}
		);
	}

	/**
	 * Run a read-modify-write of the option under a lock, so concurrent record()/clear() calls keep each other's writes.
	 *
	 * Runs unlocked when the lock is held or missing: this is a best-effort log, not system state.
	 *
	 * @param callable $mutate Returns the mutate result (any value, incl. null).
	 * @return mixed
	 */
	private static function with_lock( callable $mutate ) {
		$run = static fn() => [ 'r' => $mutate() ];

		if ( class_exists( '\\PerfLocale\\Concurrency\\Lock' ) ) {
			$out = \PerfLocale\Concurrency\Lock::with(
				'addon_migration_errors',
				5,
				static function () use ( $run ): array {
					// Read the committed row, not this request's cached copy, or the write drops another request's change.
					wp_cache_delete( self::OPTION, 'options' );

					// A `notoptions` entry from before another request created the row would hide it; unlist it.
					$notoptions = wp_cache_get( 'notoptions', 'options' );

					if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
						\PerfLocale\Database\NotOptions::unlist( [ self::OPTION ] );
					}

					return $run();
				}
			);
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
	 * Clear one addon's records for a single stage.
	 *
	 * A successful boot clears only `boot`: a failed migration or uninstall is a separate fact the operator still needs.
	 *
	 * @param string $addon_id Addon identifier.
	 * @param string $stage    Stage label to drop, e.g. 'boot'.
	 * @return int Number of records removed.
	 */
	public static function clear_stage( string $addon_id, string $stage ): int {
		return (int) self::with_lock(
			static function () use ( $addon_id, $stage ): int {
				$records = (array) get_option( self::OPTION, [] );
				$before  = count( $records );

				$records = array_filter(
					$records,
					static fn( $r ) => ( $r['addon_id'] ?? '' ) !== $addon_id || ( $r['stage'] ?? '' ) !== $stage
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
	 * The addon's last record in stored order, or null; a re-recorded key keeps its first position.
	 *
	 * Keys inside a record are not checked: callers null-coalesce each one.
	 *
	 * @param string $addon_id Addon identifier.
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
			esc_html__( 'Clear via WP CLI: wp perflocale addon errors --clear', 'perflocale' )
		);
	}
}
