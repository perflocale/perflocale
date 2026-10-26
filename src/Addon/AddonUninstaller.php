<?php
/**
 * Addon uninstall orchestrator.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes purge plans and executes purges for addons.
 *
 * Driven by the stored manifest, so a deleted addon's data still goes. Every target must sit in the addon's namespace,
 * so an addon cannot use the uninstaller to drop core data (see HasUninstallTargets).
 */
final class AddonUninstaller {

	/** Hard prefix for option names. */
	private const OPTION_PREFIX = 'perflocale_';

	/**
	 * Error of a purge whose deferred network targets could not be stored; its manifest is kept for the next pass.
	 */
	public const JOURNAL_ERROR = 'deferred network targets not stored; the manifest is kept for the next pass';

	/** Hard prefix for capability names. */
	private const CAP_PREFIX = 'perflocale_';

	/** Soft prefix for transient names. */
	private const TRANSIENT_PREFIX = 'perflocale_';

	/** Soft prefix for cron hooks. */
	private const CRON_PREFIX = 'perflocale_';

	/** Batch size for meta purge. */
	private const META_BATCH_SIZE = 1000;

	/**
	 * Compute a purge plan from the manifest, refreshed from the live class when there is one; deletes nothing.
	 *
	 * @param string              $addon_id Addon identifier.
	 * @param AddonInterface|null $addon Live addon instance if known, null if absent.
	 * @param bool                $single_site_teardown True when ONE subsite is being deleted: `site_options`
	 *     and `user` meta are network-wide and stay for the surviving sites.
	 * @return PurgePlan
	 */
	public static function plan( string $addon_id, ?AddonInterface $addon = null, bool $single_site_teardown = false ): PurgePlan {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			return PurgePlan::empty( $addon_id );
		}

		// The live class is current: refresh the manifest from it.
		if ( $addon instanceof HasUninstallTargets ) {
			AddonManifestWriter::refresh( $addon );
		}

		$manifest = AddonManifestWriter::read( $addon_id );
		if ( null === $manifest ) {
			// No manifest: only a live custom uninstall can still run.
			if ( $addon instanceof HasCustomUninstall ) {
				return new PurgePlan( $addon_id, [], [], [], [], [], [], [], 0, true );
			}
			return PurgePlan::empty( $addon_id );
		}

		// Validated again: a manifest written straight to the options table skipped normalize().
		$full_tables = [];
		foreach ( (array) ( $manifest['tables'] ?? [] ) as $short ) {
			if ( ! is_string( $short ) ) {
				continue;
			}
			if ( 1 !== preg_match( AddonSchemaManager::SHORT_NAME_PATTERN, $short ) ) {
				// Never reaches DROP TABLE.
				continue;
			}
			$full_tables[] = AddonSchemaManager::table_name( $addon_id, $short );
		}

		if ( $single_site_teardown ) {
			$manifest['site_options'] = [];

			if ( isset( $manifest['meta'] ) && is_array( $manifest['meta'] ) ) {
				unset( $manifest['meta']['user'] );
			}
		}

		$meta = [];
		foreach ( [ 'post', 'user', 'term', 'comment' ] as $type ) {
			$keys = $manifest['meta'][ $type ] ?? [];
			if ( is_array( $keys ) && [] !== $keys ) {
				$meta[ $type ] = array_values( array_filter( $keys, 'is_string' ) );
			}
		}

		$estimated_rows = self::estimate_rows(
			$addon_id,
			$full_tables,
			(array) ( $manifest['options'] ?? [] ),
			(array) ( $manifest['transient_prefixes'] ?? [] ),
			$meta
		);

		return new PurgePlan(
			$addon_id,
			$full_tables,
			(array) ( $manifest['options'] ?? [] ),
			(array) ( $manifest['site_options'] ?? [] ),
			(array) ( $manifest['transient_prefixes'] ?? [] ),
			$meta,
			(array) ( $manifest['capabilities'] ?? [] ),
			(array) ( $manifest['cron_hooks'] ?? [] ),
			$estimated_rows,
			(bool) ( $manifest['had_custom_uninstall'] ?? false ) || $addon instanceof HasCustomUninstall
		);
	}

	/**
	 * Execute a full purge for an addon.
	 *
	 * @param string              $addon_id Addon identifier.
	 * @param AddonInterface|null $addon Live addon instance if known, null if absent.
	 * @param bool                $single_site_teardown True when ONE subsite is being
	 *     deleted: no network-global targets and no network-wide cache flushes.
	 * @param bool                $defer_shared True on the network uninstall sweep: the
	 *     network-global targets are left for the caller to delete once (purge_shared()).
	 * @param array<string, mixed> $shared Set with $defer_shared: those targets
	 *     (`site_options`, `user`) and `kept` (the filter kept this addon's data);
	 *     [] when the purge failed closed.
	 * @param (callable(array<string, mixed>): bool)|null $journal With $defer_shared: stores $shared durably before
	 *     the manifest goes; false keeps the manifest and adds an error.
	 * @return PurgeResult
	 */
	public static function purge( string $addon_id, ?AddonInterface $addon = null, bool $single_site_teardown = false, bool $defer_shared = false, array &$shared = [], ?callable $journal = null ): PurgeResult {
		// Narrowed in the plan, so the actions and before_uninstall() get the plan that runs.
		$t_start  = microtime( true );
		$plan     = self::plan( $addon_id, $addon, $single_site_teardown || $defer_shared );
		$errors   = [];
		$shared   = [];
		$deferred = $defer_shared ? self::shared_targets( $addon_id ) : [];

		/** @hook perflocale/addon/before_uninstall */
		do_action( 'perflocale/addon/before_uninstall', $addon_id, $plan );

		/**
		 * Per-addon override of delete_data_on_uninstall; false keeps this addon's data and its manifest.
		 *
		 * @hook perflocale/addon/delete_data_on_uninstall
		 *
		 * @param bool $delete Whether to delete this addon's data.
		 * @param string $addon_id Addon identifier.
		 * @param PurgePlan $plan The plan that would be executed.
		 */
		// The raw option first: uninstall.php runs without a booted Settings service, whose default could win.
		$plugin_setting = true;
		$raw_settings   = get_option( 'perflocale_settings', null );
		if ( is_array( $raw_settings ) && array_key_exists( 'delete_data_on_uninstall', $raw_settings ) ) {
			$plugin_setting = (bool) $raw_settings['delete_data_on_uninstall'];
		} elseif ( function_exists( 'perflocale' ) && class_exists( \PerfLocale\Plugin::class ) ) {
			$p = \PerfLocale\Plugin::get_instance();
			if ( $p->has( 'settings' ) ) {
				$plugin_setting = (bool) $p->get( 'settings' )->get( 'delete_data_on_uninstall', true );
			}
		}
		$should_delete = (bool) apply_filters(
			'perflocale/addon/delete_data_on_uninstall',
			$plugin_setting,
			$addon_id,
			$plan
		);

		if ( ! $should_delete ) {
			// Short-circuit: no data touched, no manifest cleared.
			$skip_errors = [ 'skipped_by_filter' ];
			if ( $defer_shared ) {
				$shared = $deferred + [ 'kept' => true ];
				if ( null !== $journal && ! $journal( $shared ) ) {
					$skip_errors[] = self::JOURNAL_ERROR;
				}
			}
			$result = new PurgeResult(
				$plan,
				0,
				0,
				0,
				0,
				[],
				0,
				0,
				( microtime( true ) - $t_start ) * 1000,
				$skip_errors,
				false,
				null
			);
			/**
			 * Fires after an addon uninstall completes, including this
			 * skipped-by-filter exit.
			 *
			 * @hook perflocale/addon/uninstalled
			 */
			do_action( 'perflocale/addon/uninstalled', $addon_id, $result );
			return $result;
		}

		// A hard target outside the namespace could wipe core data.
		try {
			self::validate_hard_prefixes( $addon_id, $plan );
		} catch ( \InvalidArgumentException $e ) {
			AddonMigrationErrors::record( $addon_id, 'purge_validation', 'hard', $e->getMessage() );
			// Fail closed - do not touch anything.
			$result = new PurgeResult(
				$plan,
				0,
				0,
				0,
				0,
				[],
				0,
				0,
				( microtime( true ) - $t_start ) * 1000,
				[ $e->getMessage() ],
				false,
				null
			);

			// Fired on every exit; `errors` tells a fail-closed purge from a successful one.
			/** @hook perflocale/addon/uninstalled */
			do_action( 'perflocale/addon/uninstalled', $addon_id, $result );

			return $result;
		}

		// Optional: run the addon's before_uninstall callback.
		$custom_ran = false;
		$custom_err = null;
		if ( $addon instanceof HasCustomUninstall ) {
			try {
				$addon->before_uninstall( $plan );
				$custom_ran = true;
			} catch ( \Throwable $e ) {
				$custom_err = $e->getMessage();
				AddonMigrationErrors::record( $addon_id, 'custom_uninstall', 'run', $custom_err );
			}
		} elseif ( $plan->had_custom_uninstall ) {
			// Custom cleanup cannot run without the class: tell the admin.
			AddonMigrationErrors::record(
				$addon_id,
				'custom_uninstall_skipped',
				'absent',
				'Addon class absent; custom cleanup (before_uninstall) skipped. '
				. 'Manually clear any ActionScheduler hooks, external webhooks, or other resources the addon owned.'
			);
		}

		// One failed target does not stop the rest.
		[ $tables_dropped, $table_errors ]        = self::drop_tables( $plan->tables );
		[ $options_deleted ]                      = self::delete_options( $plan->options );
		[ $site_options_deleted ]                 = self::delete_site_options( $plan->site_options );
		[ $transient_deleted, $transient_errors ] = self::delete_transients( $plan->transient_prefixes );
		[ $meta_deleted, $meta_errors ]           = self::delete_meta( $plan->meta );
		[ $caps_removed, $cap_errors ]            = self::remove_capabilities( $plan->capabilities );
		[ $cron_unscheduled ]                     = self::unschedule_crons( $plan->cron_hooks );

		$errors = array_merge(
			$table_errors,
			$transient_errors,
			$meta_errors,
			$cap_errors
		);

		if ( $defer_shared ) {
			$shared = $deferred + [ 'kept' => false ];
		}

		// Drop manifest + stored schema version, once the deferred targets are stored: the manifest is their only source.
		if ( $defer_shared && null !== $journal && ! $journal( $shared ) ) {
			$errors[] = self::JOURNAL_ERROR;
		} else {
			AddonManifestWriter::forget( $addon_id );
			AddonSchemaManager::forget( $addon_id );
		}

		// Flush the groups of deleted rows, or cached copies outlive them; never wp_cache_flush().
		// Not when one subsite is deleted: a Redis group flush reaches every blog, and the dying blog's keys carry its id.
		if ( ! $single_site_teardown && ( [] !== $plan->transient_prefixes || [] !== $plan->options ) ) {
			wp_cache_flush_group( 'options' );
		}
		// With a persistent cache transients live in their own groups, which the raw DELETE misses.
		if ( ! $single_site_teardown && [] !== $plan->transient_prefixes && wp_using_ext_object_cache() ) {
			// Probe: some backends ignore the group flush while claiming support (Redis Object Cache with Predis).
			wp_cache_set( 'perflocale_purge_probe', 1, 'transient', 60 );
			wp_cache_flush_group( 'transient' );
			wp_cache_flush_group( 'site-transient' );
			if ( false !== wp_cache_get( 'perflocale_purge_probe', 'transient' ) ) {
				wp_cache_delete( 'perflocale_purge_probe', 'transient' );
				$errors[] = sprintf(
					"Object-cache group flush had no effect on this backend - external-cache transients for prefix(es) '%s' may persist until they expire or the cache is flushed manually",
					implode( "', '", array_filter( $plan->transient_prefixes, 'is_string' ) )
				);
			}
		}
		foreach ( [
			'post'    => 'post_meta',
			'user'    => 'user_meta',
			'term'    => 'term_meta',
			'comment' => 'comment_meta',
		] as $type => $group ) {
			if ( ! $single_site_teardown && isset( $meta_deleted[ $type ] ) && $meta_deleted[ $type ] > 0 ) {
				wp_cache_flush_group( $group );
			}
		}

		$result = new PurgeResult(
			$plan,
			$tables_dropped,
			$options_deleted,
			$site_options_deleted,
			$transient_deleted,
			$meta_deleted,
			$caps_removed,
			$cron_unscheduled,
			( microtime( true ) - $t_start ) * 1000,
			$errors,
			$custom_ran,
			$custom_err
		);

		/** @hook perflocale/addon/uninstalled */
		do_action( 'perflocale/addon/uninstalled', $addon_id, $result );

		return $result;
	}

	/**
	 * An addon's network-global targets from its manifest: network options and
	 * user meta keys, each within the namespace the purge enforces.
	 *
	 * @param string $addon_id Addon identifier.
	 * @return array{site_options: array<int, string>, user: array<int, string>}
	 */
	private static function shared_targets( string $addon_id ): array {
		$manifest = AddonManifestWriter::read( $addon_id ) ?? [];
		$meta     = is_array( $manifest['meta'] ?? null ) ? $manifest['meta'] : [];

		return [
			'site_options' => array_values( array_filter( (array) ( $manifest['site_options'] ?? [] ), static fn( $o ): bool => is_string( $o ) && str_starts_with( $o, self::OPTION_PREFIX ) ) ),
			'user'         => array_values( array_filter( (array) ( $meta['user'] ?? [] ), static fn( $k ): bool => is_string( $k ) && ( str_starts_with( $k, 'perflocale_' ) || str_starts_with( $k, '_perflocale_' ) ) ) ),
		];
	}

	/**
	 * Delete network-global addon targets the network uninstall deferred: network options as "<network id>:<name>",
	 * each on its own network, and user meta keys.
	 *
	 * @param array<int, string> $site_options Network options, "<network id>:<name>".
	 * @param array<int, string> $user_keys    User meta keys.
	 * @return array<int, string> Errors; empty when every DELETE succeeded.
	 */
	public static function purge_shared( array $site_options, array $user_keys ): array {
		$by_network = [];
		foreach ( $site_options as $target ) {
			if ( 1 === preg_match( '/^([1-9][0-9]*):(.+)$/s', $target, $m ) ) {
				$by_network[ (int) $m[1] ][] = $m[2];
			}
		}
		foreach ( $by_network as $network_id => $names ) {
			self::delete_site_options( $names, $network_id );
		}

		[ $deleted, $errors ] = self::delete_meta( [ 'user' => array_values( $user_keys ) ] );

		if ( ( $deleted['user'] ?? 0 ) > 0 ) {
			wp_cache_flush_group( 'user_meta' );
		}

		return $errors;
	}

	/**
	 * Validate targets that CANNOT contain WP-core names (tables, options,
	 * site_options, capabilities). Throws on any violation.
	 *
	 * @param string    $addon_id Addon identifier.
	 * @param PurgePlan $plan Plan.
	 * @throws \InvalidArgumentException On namespace violation.
	 * @return void
	 */
	private static function validate_hard_prefixes( string $addon_id, PurgePlan $plan ): void {
		$expected_table_prefix = AddonSchemaManager::addon_table_prefix( $addon_id );

		foreach ( $plan->tables as $table ) {
			if ( ! is_string( $table ) || ! str_starts_with( $table, $expected_table_prefix ) ) {
				// Escaped in case wp_die() renders the message.
				throw new \InvalidArgumentException(
					esc_html( "Addon '{$addon_id}' target table '{$table}' is outside its namespace {$expected_table_prefix}*" )
				);
			}
		}

		foreach ( $plan->options as $option ) {
			if ( ! is_string( $option ) || ! str_starts_with( $option, self::OPTION_PREFIX ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Addon '{$addon_id}' target option '{$option}' must start with " . self::OPTION_PREFIX )
				);
			}
		}

		foreach ( $plan->site_options as $option ) {
			if ( ! is_string( $option ) || ! str_starts_with( $option, self::OPTION_PREFIX ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Addon '{$addon_id}' target site_option '{$option}' must start with " . self::OPTION_PREFIX )
				);
			}
		}

		foreach ( $plan->capabilities as $cap ) {
			if ( ! is_string( $cap ) || ! str_starts_with( $cap, self::CAP_PREFIX ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Addon '{$addon_id}' target capability '{$cap}' must start with " . self::CAP_PREFIX )
				);
			}
		}
	}

	/**
	 * Drop declared tables.
	 *
	 * @param array<int, string> $tables Full table names with prefix.
	 * @return array{0:int,1:array<int,string>}
	 */
	private static function drop_tables( array $tables ): array {
		global $wpdb;
		$dropped = 0;
		$errors  = [];
		foreach ( $tables as $t ) {
			if ( ! is_string( $t ) || '' === $t ) {
				continue;
			}
			// Namespace-checked already; a name sanitize_table() would change is not one of ours.
			$safe = \PerfLocale\Database\Schema::sanitize_table( $t );
			if ( $safe !== $t ) {
				$errors[] = "Refused to drop table with unexpected name: {$t}";
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Teardown DDL: table name is an identifier (sanitized to [A-Za-z0-9_] above) and is bound with %i; DROP has no cache to invalidate.
			$ok = $wpdb->query(
				$wpdb->prepare(
					'DROP TABLE IF EXISTS %i',
					$safe
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $ok ) {
				$errors[] = "DROP TABLE {$safe} failed: " . $wpdb->last_error;
				continue;
			}
			++$dropped;
		}
		return [ $dropped, $errors ];
	}

	/**
	 * Delete declared options.
	 *
	 * @param array<int, string> $options Option names.
	 * @return array{0:int}
	 */
	private static function delete_options( array $options ): array {
		$count = 0;
		foreach ( $options as $name ) {
			if ( ! is_string( $name ) || ! str_starts_with( $name, self::OPTION_PREFIX ) ) {
				continue;
			}
			if ( delete_option( $name ) ) {
				++$count;
			}
		}
		return [ $count ];
	}

	/**
	 * Delete declared site options (multisite).
	 *
	 * @param array<int, string> $options    Option names.
	 * @param int|null           $network_id Network; null: the current one.
	 * @return array{0:int}
	 */
	private static function delete_site_options( array $options, ?int $network_id = null ): array {
		$count = 0;
		foreach ( $options as $name ) {
			if ( ! is_string( $name ) || ! str_starts_with( $name, self::OPTION_PREFIX ) ) {
				continue;
			}
			if ( delete_network_option( $network_id, $name ) ) {
				++$count;
			}
		}
		return [ $count ];
	}

	/**
	 * Delete transients by prefix (wp_options rows whose name starts with
	 * _transient_{$prefix} or _transient_timeout_{$prefix}).
	 *
	 * @param array<int, string> $prefixes Transient prefixes.
	 * @return array{0:int,1:array<int,string>}
	 */
	private static function delete_transients( array $prefixes ): array {
		global $wpdb;
		$total  = 0;
		$errors = [];
		foreach ( $prefixes as $prefix ) {
			if ( ! is_string( $prefix ) || ! str_starts_with( $prefix, self::TRANSIENT_PREFIX ) ) {
				$errors[] = "Transient prefix '{$prefix}' does not start with " . self::TRANSIENT_PREFIX . ' (skipped)';
				continue;
			}
			$like_value   = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			$like_timeout = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$like_value,
					$like_timeout
				)
			);
			if ( false === $deleted ) {
				$errors[] = "Transient purge for prefix '{$prefix}' failed: " . $wpdb->last_error;
				continue;
			}
			$total += (int) $deleted;
		}
		return [ $total, $errors ];
	}

	/**
	 * Delete object meta rows, batched per object type.
	 *
	 * @param array<string, array<int, string>> $meta object_type => meta keys.
	 * @return array{0:array<string,int>,1:array<int,string>}
	 */
	private static function delete_meta( array $meta ): array {
		global $wpdb;
		$counts = [];
		$errors = [];

		$tables = [
			'post'    => $wpdb->postmeta,
			'user'    => $wpdb->usermeta,
			'term'    => $wpdb->termmeta,
			'comment' => $wpdb->commentmeta,
		];

		foreach ( $meta as $type => $keys ) {
			if ( ! isset( $tables[ $type ] ) || ! is_array( $keys ) || [] === $keys ) {
				continue;
			}
			$table = $tables[ $type ];

			// Filter to keys matching soft prefix.
			$safe_keys = array_filter(
				$keys,
				static fn( $k ) => is_string( $k )
					&& ( str_starts_with( $k, 'perflocale_' ) || str_starts_with( $k, '_perflocale_' ) )
			);
			if ( count( $safe_keys ) !== count( array_filter( $keys, 'is_string' ) ) ) {
				$errors[] = "Some {$type}meta keys outside perflocale_/_perflocale_ namespace were skipped";
			}
			if ( [] === $safe_keys ) {
				continue;
			}

			$total = 0;
			// $table is from the core meta-table map above and $placeholders matches $safe_keys.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$placeholders = implode( ',', array_fill( 0, count( $safe_keys ), '%s' ) );

			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"DELETE FROM {$table} WHERE meta_key IN ({$placeholders}) LIMIT %d",
						...array_merge( array_values( $safe_keys ), [ self::META_BATCH_SIZE ] )
					)
				);
				if ( false === $deleted ) {
					$errors[] = "DELETE from {$table} failed: " . $wpdb->last_error;
					break;
				}
				$total += (int) $deleted;

				/** @hook perflocale/addon/meta_purge_batch */
				do_action( 'perflocale/addon/meta_purge_batch', $type, (int) $deleted, $total );
			} while ( (int) $deleted === self::META_BATCH_SIZE );

			$counts[ $type ] = $total;
		}

		return [ $counts, $errors ];
	}

	/**
	 * Remove capabilities from all roles.
	 *
	 * @param array<int, string> $caps Capability names.
	 * @return array{0:int,1:array<int,string>}
	 */
	private static function remove_capabilities( array $caps ): array {
		$count  = 0;
		$errors = [];
		$roles  = wp_roles();
		if ( null === $roles ) {
			return [ 0, [ 'wp_roles() unavailable' ] ];
		}
		foreach ( $caps as $cap ) {
			if ( ! is_string( $cap ) || ! str_starts_with( $cap, self::CAP_PREFIX ) ) {
				continue;
			}
			foreach ( $roles->role_objects as $role ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
					++$count;
				}
			}
		}
		return [ $count, $errors ];
	}

	/**
	 * Unschedule cron hooks.
	 *
	 * @param array<int, string> $hooks Hook names.
	 * @return array{0:int}
	 */
	private static function unschedule_crons( array $hooks ): array {
		$count = 0;
		foreach ( $hooks as $hook ) {
			if ( ! is_string( $hook ) || ! str_starts_with( $hook, self::CRON_PREFIX ) ) {
				continue;
			}
			if ( function_exists( 'wp_unschedule_hook' ) ) {
				$unscheduled = wp_unschedule_hook( $hook );
				if ( is_int( $unscheduled ) && $unscheduled >= 0 ) {
					// An int is done.
					++$count;
				} elseif ( false !== $unscheduled ) {
					++$count;
				}
			}
		}
		return [ $count ];
	}

	/**
	 * Estimate how many rows a purge will remove, for the PurgePlan.
	 *
	 * @param string                            $addon_id Addon identifier.
	 * @param array<int, string>                $tables Full table names.
	 * @param array<int, string>                $options Option names.
	 * @param array<int, string>                $transient_prefixes Transient prefixes.
	 * @param array<string, array<int, string>> $meta object_type => keys.
	 * @return int
	 */
	private static function estimate_rows(
		string $addon_id,
		array $tables,
		array $options,
		array $transient_prefixes,
		array $meta
	): int {
		global $wpdb;
		$total = 0;

		foreach ( $tables as $t ) {
			if ( ! is_string( $t ) ) {
				continue;
			}
			// Built by table_name() from validated short names.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$cnt = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i',
					$t
				)
			);
			if ( null !== $cnt ) {
				$total += (int) $cnt;
			}
		}

		$total += count( $options );

		foreach ( $transient_prefixes as $p ) {
			if ( ! is_string( $p ) ) {
				continue;
			}
			$like = $wpdb->esc_like( '_transient_' . $p ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cnt    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
			$total += $cnt;
		}

		$tables_map = [
			'post'    => $wpdb->postmeta,
			'user'    => $wpdb->usermeta,
			'term'    => $wpdb->termmeta,
			'comment' => $wpdb->commentmeta,
		];
		foreach ( $meta as $type => $keys ) {
			if ( ! isset( $tables_map[ $type ] ) || ! is_array( $keys ) || [] === $keys ) {
				continue;
			}
			$table        = $tables_map[ $type ];
			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// $table is from the core meta-table map above and $placeholders matches $keys.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$cnt = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE meta_key IN ({$placeholders})",
					$table,
					...array_values( $keys )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$total += $cnt;
		}

		return $total;
	}
}
