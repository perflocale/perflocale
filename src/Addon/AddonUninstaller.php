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
 * Driven by manifest + (optionally) the live addon class. Enforces a strict
 * prefix namespace on every target so a malicious or buggy addon can't use
 * the uninstaller to drop WP core tables.
 *
 * See HasUninstallTargets for the security model.
 */
final class AddonUninstaller {

	/** Hard prefix for option names. */
	private const OPTION_PREFIX = 'perflocale_';

	/** Hard prefix for capability names. */
	private const CAP_PREFIX = 'perflocale_';

	/** Soft prefix for transient names. */
	private const TRANSIENT_PREFIX = 'perflocale_';

	/** Soft prefix for cron hooks. */
	private const CRON_PREFIX = 'perflocale_';

	/** Batch size for meta purge. */
	private const META_BATCH_SIZE = 1000;

	/**
	 * Compute a purge plan for an addon - either from its live class or from
	 * the manifest. Returns a readonly snapshot without touching the DB.
	 *
	 * @param string              $addon_id Addon identifier.
	 * @param AddonInterface|null $addon Live addon instance if known, null if absent.
	 * @return PurgePlan
	 */
	public static function plan( string $addon_id, ?AddonInterface $addon = null ): PurgePlan {
		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			return PurgePlan::empty( $addon_id );
		}

		// Prefer live targets when class is present - always up to date.
		if ( $addon instanceof HasUninstallTargets ) {
			AddonManifestWriter::refresh( $addon );
		}

		$manifest = AddonManifestWriter::read( $addon_id );
		if ( null === $manifest ) {
			// No declarative targets stored. If the live addon has only
			// custom uninstall (no targets), reflect that in the plan.
			if ( $addon instanceof HasCustomUninstall ) {
				return new PurgePlan( $addon_id, [], [], [], [], [], [], [], 0, true );
			}
			return PurgePlan::empty( $addon_id );
		}

		// Expand short-named tables to full names. A manifest written
		// directly to the options table (bypassing AddonManifestWriter::
		// normalize()) could contain short names with SQL metachars - we
		// still validate here as a second line of defense.
		$full_tables = [];
		foreach ( (array) ( $manifest['tables'] ?? [] ) as $short ) {
			if ( ! is_string( $short ) ) {
				continue;
			}
			if ( 1 !== preg_match( AddonSchemaManager::SHORT_NAME_PATTERN, $short ) ) {
				// Rejected at the plan layer - no path forward into DROP TABLE.
				continue;
			}
			$full_tables[] = AddonSchemaManager::table_name( $addon_id, $short );
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
	 * @return PurgeResult
	 */
	public static function purge( string $addon_id, ?AddonInterface $addon = null ): PurgeResult {
		$t_start = microtime( true );
		$plan    = self::plan( $addon_id, $addon );
		$errors  = [];

		/** @hook perflocale/addon/before_uninstall */
		do_action( 'perflocale/addon/before_uninstall', $addon_id, $plan );

		/**
		 * Per-addon override of the plugin-level delete_data_on_uninstall
		 * setting. Defaults to the plugin-level value (passed in via $default).
		 * Return false to preserve this addon's data even when the plugin-level
		 * setting is true - the purge short-circuits to an empty result and
		 * the manifest is left intact so a later reinstall can resume.
		 *
		 * @hook perflocale/addon/delete_data_on_uninstall
		 *
		 * @param bool $delete Whether to delete this addon's data.
		 * @param string $addon_id Addon identifier.
		 * @param PurgePlan $plan The plan that would be executed.
		 */
		// Read the raw option first - the uninstall.php flow may run without
		// the Settings service fully bootstrapped, and going through
		// Settings::get() can return defaults (false) even when the stored
		// value is true. Fall back to the Settings service only when the raw
		// option is absent (pre-save site state).
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
				[ 'skipped_by_filter' ],
				false,
				null
			);
			/** @hook perflocale/addon/uninstalled */
			do_action( 'perflocale/addon/uninstalled', $addon_id, $result );
			return $result;
		}

		// Security: validate hard prefixes - throw on violation (these paths
		// could wipe WP core data if addon authored maliciously).
		try {
			self::validate_hard_prefixes( $addon_id, $plan );
		} catch ( \InvalidArgumentException $e ) {
			AddonMigrationErrors::record( $addon_id, 'purge_validation', 'hard', $e->getMessage() );
			// Fail closed - do not touch anything.
			return new PurgeResult(
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
			// Manifest said this addon has custom cleanup, but we don't have
			// the class to run it. Warn the admin.
			AddonMigrationErrors::record(
				$addon_id,
				'custom_uninstall_skipped',
				'absent',
				'Addon class absent; custom cleanup (before_uninstall) skipped. '
				. 'Manually clear any ActionScheduler hooks, external webhooks, or other resources the addon owned.'
			);
		}

		// Execute declarative purge, soft-erroring per target so one failure
		// doesn't abort the rest.
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

		// Drop manifest + stored schema version.
		AddonManifestWriter::forget( $addon_id );
		AddonSchemaManager::forget( $addon_id );

		// Flush object-cache groups whose underlying rows we deleted - without
		// this, same-request reads via get_transient() / get_post_meta() /
		// get_option() return stale values from the cache even though the
		// DB rows are gone. Targeted flushes (not a global wp_cache_flush()
		// which nukes unrelated caches too).
		if ( [] !== $plan->transient_prefixes || [] !== $plan->options ) {
			wp_cache_flush_group( 'options' );
		}
		// With a persistent object cache, transients live in the dedicated
		// 'transient' / 'site-transient' groups, NOT in wp_options - so the
		// raw DELETE above (and the 'options' flush) miss them entirely and a
		// same-request get_transient() would still serve the cached value.
		// Transients are disposable, so flushing those groups is safe and far
		// narrower than a global wp_cache_flush().
		if ( [] !== $plan->transient_prefixes && wp_using_ext_object_cache() ) {
			// Sentinel-verify the flush: some backends silently no-op
			// wp_cache_flush_group() even when wp_cache_supports() claims
			// support (e.g. Redis Object Cache with the Predis client), which
			// would leave no-expiry transients orphaned in the external cache
			// while the purge report claims success. Probe with a throwaway
			// key so the failure surfaces in the result's errors.
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
			if ( isset( $meta_deleted[ $type ] ) && $meta_deleted[ $type ] > 0 ) {
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
				// Exception messages are esc_html'd so that if WP ever
				// renders this through wp_die() the addon_id/table values
				// (programmer-controlled but still dynamic) cannot carry
				// HTML into the error page.
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
			// Defence in depth: the name already passed
			// validate_hard_prefixes(), but a table identifier cannot be
			// bound with wpdb::prepare() (placeholders are for values only),
			// so we additionally strip it to a bare [A-Za-z0-9_] identifier
			// right before interpolation. A name that doesn't survive intact
			// is not one of ours and is skipped.
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
	 * @param array<int, string> $options Option names.
	 * @return array{0:int}
	 */
	private static function delete_site_options( array $options ): array {
		$count = 0;
		foreach ( $options as $name ) {
			if ( ! is_string( $name ) || ! str_starts_with( $name, self::OPTION_PREFIX ) ) {
				continue;
			}
			if ( delete_site_option( $name ) ) {
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
					// wp_unschedule_hook returns int, treat as "done".
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
			// Query only known-safe names (passed validation).
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
			// $table comes from a class-local map of WP core meta tables;
			// $placeholders is a runtime %s-list whose count matches $keys.
			// Scanner can't see either statically.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Replacements are assembled with array_merge()/unpacking, which WPCS cannot count; the %i table names lead, then the values in placeholder order.
			$cnt = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE meta_key IN ({$placeholders})",
					$table,
					...array_values( $keys )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total += $cnt;
		}

		return $total;
	}
}
