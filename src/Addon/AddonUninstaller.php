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

	/**
	 * Add-on ids of the running uninstall pass (set_pass_ids()), siblings for each other's purge.
	 *
	 * @var list<string>
	 */
	private static array $pass_ids = [];

	/** The registered add-ons could not be read: nothing is purged, the manifest stays for the next pass. */
	public const CLAIMS_ERROR = 'the registered add-ons could not be read; nothing purged, the manifest is kept for the next pass';

	/** The delete-data setting could not be read: nothing is purged, the manifest stays for the next pass. */
	public const SETTING_ERROR = 'the delete-data setting could not be read; nothing purged, the manifest is kept for the next pass';

	/** The database refused a DROP or DELETE: what is left stays listed in the manifest for the next pass. */
	public const LEFT_ERROR = 'the database refused a DROP or DELETE; the manifest is kept for the next pass';

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
	 * @param bool|null           $unread Set to true when the manifest read failed (the plan is then empty).
	 * @param-out bool           $unread
	 * @return PurgePlan
	 */
	public static function plan( string $addon_id, ?AddonInterface $addon = null, bool $single_site_teardown = false, ?bool &$unread = null ): PurgePlan {
		$unread = false;

		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			return PurgePlan::empty( $addon_id );
		}

		// The live class is current: refresh the manifest from it.
		if ( $addon instanceof HasUninstallTargets ) {
			AddonManifestWriter::refresh( $addon );
		}

		$manifest = self::manifest_row( $addon_id );
		$unread   = false === $manifest;
		if ( ! is_array( $manifest ) ) {
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
		$unread   = false;
		$plan     = self::plan( $addon_id, $addon, $single_site_teardown || $defer_shared, $unread );
		$errors   = [];
		$shared   = [];
		$claims   = self::others_claims( $addon_id );
		$deferred = $defer_shared ? self::shared_targets( $addon_id, $claims ) : [];

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
		// The stored row first, past every cache: uninstall.php runs without a booted Settings service, whose default could win.
		$plugin_setting = true;
		$settings_row   = \PerfLocale\Database\OptionRead::of( 'perflocale_settings' );

		// A failed read never grants: nothing is purged.
		if ( $settings_row->failed ) {
			$result = new PurgeResult( $plan, 0, 0, 0, 0, [], 0, 0, ( microtime( true ) - $t_start ) * 1000, [ self::SETTING_ERROR ], false, null );

			/**
			 * Fires after the refused purge.
			 *
			 * @hook perflocale/addon/uninstalled
			 */
			do_action( 'perflocale/addon/uninstalled', $addon_id, $result );

			return $result;
		}

		$raw_settings = null !== $settings_row->bytes ? maybe_unserialize( $settings_row->bytes ) : null;
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
				$shared = ( $deferred ?? [] ) + [ 'kept' => true ];
				if ( null === $deferred || ( null !== $journal && ! $journal( $shared ) ) ) {
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

		// A failed read never grants: without the other add-ons' names or this add-on's manifest nothing is purged.
		if ( null === $claims || $unread || null === $deferred ) {
			$result = new PurgeResult( $plan, 0, 0, 0, 0, [], 0, 0, ( microtime( true ) - $t_start ) * 1000, [ self::CLAIMS_ERROR ], false, null );

			/** @hook perflocale/addon/uninstalled */
			do_action( 'perflocale/addon/uninstalled', $addon_id, $result );

			return $result;
		}

		// A hard target outside the namespace could wipe core data.
		try {
			self::validate_hard_prefixes( $addon_id, $plan );
		} catch ( \InvalidArgumentException $e ) {
			$cause = \PerfLocale\Database\UpgradeRunner::describe( $e );
			AddonMigrationErrors::record( $addon_id, 'purge_validation', 'hard', $cause );
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
				[ $cause ],
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
				// The class and place, never the add-on's message.
				$custom_err = \PerfLocale\Database\UpgradeRunner::describe( $e );
				AddonMigrationErrors::record( $addon_id, 'custom_uninstall', 'run', \PerfLocale\Database\UpgradeRunner::describe( $e ) );
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

		// A name another component owns is never this add-on's: skipped with an error, the rest purges.
		$claim_errors = [];
		$meta         = [];

		foreach ( $plan->meta as $type => $keys ) {
			$meta[ $type ] = self::own_names( $addon_id, (array) $keys, $type . ' meta key', $claims, false, $claim_errors );
		}

		$options    = self::own_names( $addon_id, $plan->options, 'option', $claims, false, $claim_errors );
		$site_opts  = self::own_names( $addon_id, $plan->site_options, 'site_option', $claims, false, $claim_errors );
		$transients = self::own_names( $addon_id, $plan->transient_prefixes, 'transient prefix', $claims, true, $claim_errors );
		$caps       = self::own_names( $addon_id, $plan->capabilities, 'capability', $claims, false, $claim_errors );
		$crons      = self::own_names( $addon_id, $plan->cron_hooks, 'cron hook', $claims, false, $claim_errors );

		// One failed target does not stop the rest.
		[ $tables_dropped, $table_errors, $tables_refused ]           = self::drop_tables( $plan->tables );
		[ $options_deleted ]                                          = self::delete_options( $options );
		[ $site_options_deleted, $site_errors, $site_refused ]        = self::delete_site_options( $site_opts );
		[ $transient_deleted, $transient_errors, $transient_refused ] = self::delete_transients( $transients );
		[ $meta_deleted, $meta_errors, $meta_refused ]                = self::delete_meta( $meta );
		[ $caps_removed, $cap_errors ]                                = self::remove_capabilities( $caps );
		[ $cron_unscheduled ]                                         = self::unschedule_crons( $crons );

		$errors = array_merge(
			$claim_errors,
			$table_errors,
			$site_errors,
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
		} elseif ( $tables_refused + $site_refused + $transient_refused + $meta_refused > 0 ) {
			// Without the manifest nothing could find what is left; every step is a no-op when it runs again.
			$errors[] = self::LEFT_ERROR;
		} else {
			AddonManifestWriter::forget( $addon_id );
			AddonSchemaManager::forget( $addon_id );
		}

		// Flush the groups of deleted rows, or cached copies outlive them; never wp_cache_flush().
		// Not when one subsite is deleted: a Redis group flush reaches every blog, and the dying blog's keys carry its id.
		if ( ! $single_site_teardown && ( [] !== $plan->transient_prefixes || [] !== $plan->options ) ) {
			wp_cache_flush_group( 'options' );
		}
		// Never the shared 'transient' groups of a persistent cache: every plugin's transients live there; the add-on's expire.
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
	 * The add-on ids a SiteCleanup pass read before purging any of them: they stay siblings after their manifests go.
	 *
	 * @param array<array-key, string> $ids Add-on ids.
	 * @return void
	 */
	public static function set_pass_ids( array $ids ): void {
		self::$pass_ids = array_values( array_filter( $ids, static fn( $id ): bool => '' !== $id ) );
	}

	/**
	 * An addon's network-global targets from its manifest: network options and
	 * user meta keys, each within the namespace the purge enforces.
	 *
	 * @param string                                                  $addon_id Addon identifier.
	 * @param array{exact: list<string>, prefixes: list<string>}|null $claims   others_claims(); null: unfiltered.
	 * @return array{site_options: array<int, string>, user: array<int, string>}|null Null when the manifest read failed.
	 */
	private static function shared_targets( string $addon_id, ?array $claims ): ?array {
		$manifest = self::manifest_row( $addon_id );

		if ( false === $manifest ) {
			return null;
		}

		$manifest = $manifest ?? [];
		$meta     = is_array( $manifest['meta'] ?? null ) ? $manifest['meta'] : [];
		$errors   = [];
		$site     = array_values( array_filter( (array) ( $manifest['site_options'] ?? [] ), static fn( $o ): bool => is_string( $o ) && str_starts_with( $o, self::OPTION_PREFIX ) ) );
		$user     = array_values( array_filter( (array) ( $meta['user'] ?? [] ), static fn( $k ): bool => is_string( $k ) && ( str_starts_with( $k, 'perflocale_' ) || str_starts_with( $k, '_perflocale_' ) ) ) );

		// Unknown claims (failed read): the names stay as declared, only ever kept (the purge itself fails closed).
		return null === $claims ? [
			'site_options' => $site,
			'user'         => $user,
		] : [
			'site_options' => self::own_names( $addon_id, $site, 'site_option', $claims, false, $errors ),
			'user'         => self::own_names( $addon_id, $user, 'user meta key', $claims, false, $errors ),
		];
	}

	/**
	 * The stored manifest past every cache: a cached absence or a failed read never looks like "no manifest".
	 *
	 * @param string $addon_id Addon identifier.
	 * @return array<mixed>|false|null Null when absent or not an array, false when the read failed.
	 */
	private static function manifest_row( string $addon_id ): array|false|null {
		$row = \PerfLocale\Database\OptionRead::of( AddonManifestWriter::option_name( $addon_id ) );

		if ( $row->failed ) {
			return false;
		}

		$manifest = null !== $row->bytes ? maybe_unserialize( $row->bytes ) : null;

		return is_array( $manifest ) ? $manifest : null;
	}

	/**
	 * What other components own, lowercase without leading underscores: the core registry's names and name prefixes (not
	 * its namespace-wide entries), core's capabilities, and the prefixes of Pro and of every other registered add-on.
	 *
	 * @param string $addon_id The add-on being purged.
	 * @return array{exact: list<string>, prefixes: list<string>}|null Null when the stored manifests or schema versions could not be read.
	 */
	private static function others_claims( string $addon_id ): ?array {
		global $wpdb;

		[ 'exact' => $exact, 'prefixes' => $prefixes ] = \PerfLocale\Database\SiteCleanup::core_claims();

		$dir = dirname( __DIR__, 2 ) . '/addons';
		$ids = array_merge( AddonManifestWriter::list_ids(), self::$pass_ids );

		// list_ids() answers [] for a failed read too; its query is the last one.
		if ( ! $wpdb instanceof \wpdb || '' !== $wpdb->last_error ) {
			return null;
		}

		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : [] as $entry ) {
			if ( is_string( $entry ) && '' !== $entry && '.' !== $entry[0] && is_dir( $dir . '/' . $entry ) ) {
				$ids[] = $entry;
			}
		}

		$versions = \PerfLocale\Database\OptionRead::of( AddonSchemaManager::VERSIONS_OPTION );

		if ( $versions->failed ) {
			return null;
		}

		$versions = null !== $versions->bytes ? maybe_unserialize( $versions->bytes ) : null;
		$ids      = array_merge( $ids, is_array( $versions ) ? array_map( 'strval', array_keys( $versions ) ) : [] );

		foreach ( array_diff( array_unique( $ids ), [ $addon_id ] ) as $id ) {
			$prefixes[] = 'perflocale_' . str_replace( '-', '_', $id ) . '_';
		}

		$bare = static fn( string $n ): string => strtolower( ltrim( $n, '_' ) );

		return [
			'exact'    => array_values( array_unique( array_map( $bare, $exact ) ) ),
			'prefixes' => array_values( array_unique( array_filter( array_map( $bare, $prefixes ), static fn( string $p ): bool => strlen( $p ) > strlen( 'perflocale_' ) ) ) ),
		];
	}

	/**
	 * $names without those another component may own (others_claims()): under a sibling's prefix is refused even when it
	 * is also under this add-on's longer one (ids `foo` and `foo_bar`): ownership is unproven.
	 * A transient prefix ($prefix) is also refused when it would cover another's name.
	 *
	 * @param string                                             $addon_id Add-on id.
	 * @param array<int, mixed>                                  $names    Declared names.
	 * @param string                                             $kind     Target kind, for the error.
	 * @param array{exact: list<string>, prefixes: list<string>} $claims   Others' names.
	 * @param bool                                               $prefix   Whether each name is a transient prefix.
	 * @param array<int, string>                                 $errors   Gets one error per refused name.
	 * @return array<int, string>
	 */
	private static function own_names( string $addon_id, array $names, string $kind, array $claims, bool $prefix, array &$errors ): array {
		$out = [];

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$bare    = strtolower( ltrim( $name, '_' ) );
			$claimed = in_array( $bare, $claims['exact'], true ) || ( $prefix && strlen( $bare ) <= strlen( 'perflocale_' ) );

			foreach ( $claims['prefixes'] as $p ) {
				if ( str_starts_with( $bare, $p ) || ( $prefix && str_starts_with( $p, $bare ) ) ) {
					$claimed = true;
					break;
				}
			}

			foreach ( $prefix ? $claims['exact'] : [] as $other ) {
				$claimed = $claimed || str_starts_with( $other, $bare );
			}

			if ( $claimed ) {
				$errors[] = "Addon '{$addon_id}' {$kind} '{$name}' belongs to another component (skipped)";
				continue;
			}

			$out[] = $name;
		}

		return $out;
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
		$errors = [];
		foreach ( $by_network as $network_id => $names ) {
			$errors = array_merge( $errors, self::delete_site_options( $names, $network_id )[1] );
		}

		[ $deleted, $meta_errors ] = self::delete_meta( [ 'user' => array_values( $user_keys ) ] );
		$errors                    = array_merge( $errors, $meta_errors );

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
					esc_html( "Addon '{$addon_id}' target table '{$table}' is outside its namespace {$expected_table_prefix}*" ),
					(int) \PerfLocale\Database\UpgradeRunner::OWN_ERROR
				);
			}
		}

		foreach ( $plan->options as $option ) {
			if ( ! is_string( $option ) || ! str_starts_with( $option, self::OPTION_PREFIX ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Addon '{$addon_id}' target option '{$option}' must start with " . self::OPTION_PREFIX ),
					(int) \PerfLocale\Database\UpgradeRunner::OWN_ERROR
				);
			}
		}

		foreach ( $plan->site_options as $option ) {
			if ( ! is_string( $option ) || ! str_starts_with( $option, self::OPTION_PREFIX ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Addon '{$addon_id}' target site_option '{$option}' must start with " . self::OPTION_PREFIX ),
					(int) \PerfLocale\Database\UpgradeRunner::OWN_ERROR
				);
			}
		}

		foreach ( $plan->capabilities as $cap ) {
			if ( ! is_string( $cap ) || ! str_starts_with( $cap, self::CAP_PREFIX ) ) {
				throw new \InvalidArgumentException(
					esc_html( "Addon '{$addon_id}' target capability '{$cap}' must start with " . self::CAP_PREFIX ),
					(int) \PerfLocale\Database\UpgradeRunner::OWN_ERROR
				);
			}
		}
	}

	/**
	 * Drop declared tables.
	 *
	 * @param array<int, string> $tables Full table names with prefix.
	 * @return array{0:int,1:array<int,string>,2:int} Dropped, errors, DROPs the database refused.
	 */
	private static function drop_tables( array $tables ): array {
		global $wpdb;
		$dropped = 0;
		$errors  = [];
		$refused = 0;
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
				$errors[] = "DROP TABLE {$safe} failed: " . \PerfLocale\Util\DbErrorCode::of( \PerfLocale\Admin\Typed::str( $wpdb->last_error ) );
				++$refused;
				continue;
			}
			++$dropped;
		}
		return [ $dropped, $errors, $refused ];
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
	 * Delete declared site options (multisite); a row still there or unreadable after a false is an error.
	 *
	 * @param array<int, string> $options    Option names.
	 * @param int|null           $network_id Network; null: the current one.
	 * @return array{0:int,1:array<int,string>,2:int} Deleted, errors, rows left.
	 */
	private static function delete_site_options( array $options, ?int $network_id = null ): array {
		$count   = 0;
		$errors  = [];
		$refused = 0;
		$network = $network_id ?? get_current_network_id();
		foreach ( $options as $name ) {
			if ( ! is_string( $name ) || ! str_starts_with( $name, self::OPTION_PREFIX ) ) {
				continue;
			}
			if ( delete_network_option( $network, $name ) ) {
				++$count;
			} elseif ( ! \PerfLocale\Database\SiteCleanup::network_option_gone( $network, $name ) ) {
				$errors[] = "Network option {$name} on network {$network} was not deleted";
				++$refused;
			}
		}
		return [ $count, $errors, $refused ];
	}

	/**
	 * Delete transients by prefix (wp_options rows whose name starts with
	 * _transient_{$prefix} or _transient_timeout_{$prefix}).
	 *
	 * @param array<int, string> $prefixes Transient prefixes.
	 * @return array{0:int,1:array<int,string>,2:int} Deleted, errors, DELETEs the database refused.
	 */
	private static function delete_transients( array $prefixes ): array {
		global $wpdb;
		$total   = 0;
		$errors  = [];
		$refused = 0;
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
				$errors[] = "Transient purge for prefix '{$prefix}' failed: " . \PerfLocale\Util\DbErrorCode::of( \PerfLocale\Admin\Typed::str( $wpdb->last_error ) );
				++$refused;
				continue;
			}
			$total += (int) $deleted;
		}
		return [ $total, $errors, $refused ];
	}

	/**
	 * Delete object meta rows, batched per object type.
	 *
	 * @param array<string, array<int, string>> $meta object_type => meta keys.
	 * @return array{0:array<string,int>,1:array<int,string>,2:int} Deleted per type, errors, DELETEs the database refused.
	 */
	private static function delete_meta( array $meta ): array {
		global $wpdb;
		$counts  = [];
		$errors  = [];
		$refused = 0;

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
					$errors[] = "DELETE from {$table} failed: " . \PerfLocale\Util\DbErrorCode::of( \PerfLocale\Admin\Typed::str( $wpdb->last_error ) );
					++$refused;
					break;
				}
				$total += (int) $deleted;

				/** @hook perflocale/addon/meta_purge_batch */
				do_action( 'perflocale/addon/meta_purge_batch', $type, (int) $deleted, $total );
			} while ( (int) $deleted === self::META_BATCH_SIZE );

			$counts[ $type ] = $total;
		}

		return [ $counts, $errors, $refused ];
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
