<?php
/**
 * WP-CLI subcommands for managing PerfLocale addons.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cli;

use PerfLocale\Addon\AddonInterface;
use PerfLocale\Addon\AddonManifestWriter;
use PerfLocale\Addon\AddonMigrationErrors;
use PerfLocale\Addon\AddonRegistry;
use PerfLocale\Addon\AddonSchemaManager;
use PerfLocale\Addon\AddonUninstaller;
use PerfLocale\Addon\HasCustomUninstall;
use PerfLocale\Addon\HasSchema;
use PerfLocale\Addon\HasUninstallTargets;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Addon lifecycle management commands.
 *
 * Registered as `wp perflocale addon <subcommand>`.
 */
final class AddonCommand {

	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'perflocale addon', $this );
		}
	}

	/**
	 * List all registered addons with version and manifest status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns to render. Default: all.
	 *   Available: id, name, bundled, booted, disabled, compatible, has_schema,
	 *   schema_target, schema_stored, has_uninstall, has_custom, manifest.
	 *
	 * [--all]
	 * : Include orphan manifests (addons whose class is absent).
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon list
	 * wp perflocale addon list --format=json --all
	 * wp perflocale addon list --fields=id,disabled --format=csv
	 * wp perflocale addon list --format=ids
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		$format   = (string) ( $assoc_args['format'] ?? 'table' );
		$show_all = ! empty( $assoc_args['all'] );

		$rows        = [];
		$reg         = Plugin::get_instance()->has( 'addon_registry' )
			? Plugin::get_instance()->get( 'addon_registry' )
			: null;
		$live_addons = $reg && method_exists( $reg, 'get_addons' ) ? $reg->get_addons() : [];

		foreach ( $live_addons as $id => $addon ) {
			$rows[ $id ] = [
				'id'            => $id,
				'name'          => method_exists( $addon, 'get_name' ) ? $addon->get_name() : '',
				// Bundled = ships inside the plugin's own addons/ directory.
				// Bundled addons need --force to be disabled; external ones
				// honour the disabled list without opt-in. Operator's main
				// signal for "is this one of yours or one of mine".
				'bundled'       => $reg && method_exists( $reg, 'is_bundled' ) ? $this->bool_label( $reg->is_bundled( $id ) ) : '-',
				'booted'        => $reg && method_exists( $reg, 'is_booted' ) ? $this->bool_label( $reg->is_booted( $id ) ) : '-',
				'disabled'      => $this->bool_label( AddonRegistry::is_disabled( $id ) ),
				'compatible'    => $this->bool_label( $this->safe_is_compatible( $addon ) ),
				'has_schema'    => $this->bool_label( $addon instanceof HasSchema ),
				'schema_target' => $addon instanceof HasSchema ? (string) $addon->get_schema_version() : '-',
				'schema_stored' => (string) AddonSchemaManager::get_stored_version( $id ),
				'has_uninstall' => $this->bool_label( $addon instanceof HasUninstallTargets ),
				'has_custom'    => $this->bool_label( $addon instanceof HasCustomUninstall ),
				'manifest'      => $this->bool_label( null !== AddonManifestWriter::read( $id ) ),
			];
		}

		if ( $show_all ) {
			foreach ( AddonManifestWriter::list_ids() as $id ) {
				if ( isset( $rows[ $id ] ) ) {
					continue;
				}
				$rows[ $id ] = [
					'id'            => $id,
					'name'          => '(orphan - class absent)',
					'bundled'       => '-',
					'booted'        => 'no',
					'disabled'      => $this->bool_label( AddonRegistry::is_disabled( $id ) ),
					'compatible'    => '-',
					'has_schema'    => '-',
					'schema_target' => '-',
					'schema_stored' => (string) AddonSchemaManager::get_stored_version( $id ),
					'has_uninstall' => '-',
					'has_custom'    => '-',
					'manifest'      => 'yes',
				];
			}
		}

		$values         = array_values( $rows );
		$default_fields = [ 'id', 'name', 'bundled', 'booted', 'disabled', 'compatible', 'has_schema', 'schema_target', 'schema_stored', 'has_uninstall', 'has_custom', 'manifest' ];

		// Honour --fields=col1,col2 from CLI. format_items() doesn't read
		// runtime args itself — the command has to plumb the value through.
		$fields = $default_fields;
		if ( ! empty( $assoc_args['fields'] ) ) {
			$requested = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) );
			// Defensive: drop any user-supplied column that we don't expose,
			// so a typo doesn't fatal format_items with an undefined-index.
			$fields = array_values( array_intersect( $requested, $default_fields ) );
			if ( empty( $fields ) ) {
				$fields = $default_fields;
			}
		}

		if ( 'ids' === $format ) {
			// format_items() can't render 'ids' from associative rows — it
			// stringifies each row ("Array to string conversion"). Emit the
			// addon id column directly so `--format=ids` stays scriptable.
			\WP_CLI::line( implode( ' ', wp_list_pluck( $values, 'id' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $values, $fields );
	}

	/**
	 * Show full manifest + purge-plan preview for one addon.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Addon identifier.
	 *
	 * [--format=<format>]
	 * : Output format for the table view.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon info testad
	 * wp perflocale addon info visual-editor --format=json
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function info( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );
		if ( '' === $id || ! AddonSchemaManager::validate_addon_id( $id ) ) {
			\WP_CLI::error( 'Invalid addon id. Must match ' . AddonSchemaManager::ADDON_ID_PATTERN );
		}

		$format   = (string) ( $assoc_args['format'] ?? 'table' );
		$manifest = AddonManifestWriter::read( $id );
		$reg      = Plugin::get_instance()->get( 'addon_registry' );
		$addons   = method_exists( $reg, 'get_addons' ) ? $reg->get_addons() : [];
		$live     = $addons[ $id ] ?? null;

		if ( null === $manifest && null === $live ) {
			\WP_CLI::error( "Addon '{$id}' has no manifest and is not registered." );
		}

		$plan = AddonUninstaller::plan( $id, $live instanceof AddonInterface ? $live : null );

		$info = [
			'id'                      => $id,
			'class_present'           => $live ? get_class( $live ) : '(absent)',
			'schema_stored'           => AddonSchemaManager::get_stored_version( $id ),
			'schema_target'           => $live instanceof HasSchema ? $live->get_schema_version() : 0,
			'manifest_written_at'     => $manifest['updated_at'] ?? null,
			'plugin_version_at_write' => $manifest['plugin_version_at_write'] ?? null,
			'had_custom_uninstall'    => (bool) ( $manifest['had_custom_uninstall'] ?? false ),
			'plan_tables'             => count( $plan->tables ),
			'plan_options'            => count( $plan->options ),
			'plan_site_options'       => count( $plan->site_options ),
			'plan_transient_prefixes' => count( $plan->transient_prefixes ),
			'plan_meta_types'         => array_keys( array_filter( $plan->meta, static fn( $a ) => ! empty( $a ) ) ),
			'plan_capabilities'       => count( $plan->capabilities ),
			'plan_cron_hooks'         => count( $plan->cron_hooks ),
			'plan_estimated_rows'     => $plan->estimated_rows,
		];

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode( $info, JSON_PRETTY_PRINT ) );
			return;
		}
		if ( 'yaml' === $format ) {
			foreach ( $info as $k => $v ) {
				// wp_json_encode for scalars produces YAML-safe scalar
				// forms (true / false / 123 / "string") - same role
				// var_export played, minus the plugin-check warning.
				\WP_CLI::log( $k . ': ' . ( is_array( $v ) ? '[' . implode( ',', $v ) . ']' : (string) wp_json_encode( $v ) ) );
			}
			return;
		}

		// Default branch — render as the standard CLI table.
		$rows = [];
		foreach ( $info as $k => $v ) {
			$rows[] = [
				'field' => $k,
				'value' => is_array( $v ) ? implode( ', ', $v ) : ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );
	}

	/**
	 * List addons whose manifest exists but whose class is absent.
	 *
	 * These are addons whose plugin files were removed from disk while the
	 * manifest remains. The manifest is purged automatically when PerfLocale
	 * itself is uninstalled (with the delete-data setting on). To resume the
	 * addon, reinstall the plugin and run `wp perflocale addon migrate <id>`.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon orphans
	 *
	 * @return void
	 */
	public function orphans(): void {
		$reg          = Plugin::get_instance()->get( 'addon_registry' );
		$live_ids     = $reg && method_exists( $reg, 'get_addons' ) ? array_keys( $reg->get_addons() ) : [];
		$manifest_ids = AddonManifestWriter::list_ids();
		$orphans      = array_diff( $manifest_ids, $live_ids );

		if ( [] === $orphans ) {
			\WP_CLI::success( 'No orphan addons.' );
			return;
		}

		$rows = [];
		foreach ( $orphans as $id ) {
			$m      = AddonManifestWriter::read( $id );
			$rows[] = [
				'id'                      => $id,
				'stored_version'          => AddonSchemaManager::get_stored_version( $id ),
				'last_seen_version'       => $m['last_seen_version'] ?? 0,
				'plugin_version_at_write' => $m['plugin_version_at_write'] ?? '',
				'had_custom_uninstall'    => (bool) ( $m['had_custom_uninstall'] ?? false ) ? 'yes' : 'no',
				'updated_at'              => $m['updated_at'] ?? '',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( reset( $rows ) ) );
	}

	/**
	 * Run pending addon migrations.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Optional - migrate just this addon. Default: all.
	 *
	 * [--force]
	 * : Reset stored_version to 0 and re-run migrations from scratch. Dangerous.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon migrate
	 * wp perflocale addon migrate testad
	 * wp perflocale addon migrate testad --force
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$force = ! empty( $assoc_args['force'] );
		$id    = (string) ( $args[0] ?? '' );

		if ( '' !== $id ) {
			if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
				\WP_CLI::error( 'Invalid addon id.' );
			}
			$reg    = Plugin::get_instance()->get( 'addon_registry' );
			$addons = method_exists( $reg, 'get_addons' ) ? $reg->get_addons() : [];
			$addon  = $addons[ $id ] ?? null;
			if ( null === $addon ) {
				\WP_CLI::error( "Addon '{$id}' is not registered." );
			}
			if ( ! $addon instanceof HasSchema ) {
				\WP_CLI::warning( "Addon '{$id}' doesn't implement HasSchema - nothing to migrate." );
				return;
			}
			if ( $force ) {
				AddonSchemaManager::forget( $id );
				\WP_CLI::log( 'Reset stored version to 0 (--force).' );
			}
			$ok = AddonSchemaManager::migrate( $addon );
			$v  = AddonSchemaManager::get_stored_version( $id );
			if ( $ok ) {
				\WP_CLI::success( "Migrated '{$id}' to v{$v}." );
			} else {
				\WP_CLI::warning( "Migration halted. Current stored_version: {$v}. See 'wp perflocale addon errors'." );
			}
			return;
		}

		// All addons
		( new \PerfLocale\Database\Migrator() )->maybe_migrate_addons();
		\WP_CLI::success( 'Addon migration pass complete.' );
	}

	/**
	 * Show or clear the addon migration/uninstall error log.
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Clear the log instead of showing it.
	 *
	 * [<id>]
	 * : Optional - restrict to errors for a single addon.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon errors
	 * wp perflocale addon errors testad
	 * wp perflocale addon errors --clear
	 * wp perflocale addon errors testad --clear
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function errors( array $args, array $assoc_args ): void {
		$clear = ! empty( $assoc_args['clear'] );
		$id    = $args[0] ?? null;

		if ( $clear ) {
			$n = AddonMigrationErrors::clear( $id );
			\WP_CLI::success( sprintf( 'Cleared %d record%s.', $n, 1 === $n ? '' : 's' ) );
			return;
		}

		$records = AddonMigrationErrors::get_all();
		if ( null !== $id ) {
			$records = array_filter( $records, static fn( $r ) => ( $r['addon_id'] ?? '' ) === $id );
		}
		if ( [] === $records ) {
			\WP_CLI::success( 'No error records.' );
			return;
		}
		$rows = array_values(
			array_map(
				static fn( $r ) => [
					'addon_id' => (string) ( $r['addon_id'] ?? '' ),
					'stage'    => (string) ( $r['stage'] ?? '' ),
					'detail'   => (string) ( $r['detail'] ?? '' ),
					'recorded' => (string) ( $r['recorded'] ?? '' ),
					'message'  => (string) ( $r['message'] ?? '' ),
				],
				$records
			)
		);
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'addon_id', 'stage', 'detail', 'recorded', 'message' ] );
	}

	/**
	 * Reset an addon's stored schema version to a specific value.
	 *
	 * Dangerous - used for manual recovery. Normally the Migrator handles
	 * versions automatically. Setting a version LOWER than an addon's
	 * target causes migrate_to() steps to re-run.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Addon identifier.
	 *
	 * <version>
	 * : New version number (>= 0).
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon reset-version testad 0
	 * wp perflocale addon reset-version testad 2 --yes
	 *
	 * @subcommand reset-version
	 *
	 * @param array<int, string>    $args Positional args.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function reset_version( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );
		$v  = isset( $args[1] ) ? (int) $args[1] : -1;
		if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
			\WP_CLI::error( 'Invalid addon id.' );
		}
		if ( $v < 0 ) {
			\WP_CLI::error( 'Version must be a non-negative integer.' );
		}
		\WP_CLI::confirm( "Reset '{$id}' stored_version to {$v}?", $assoc_args );
		if ( 0 === $v ) {
			AddonSchemaManager::forget( $id );
		} else {
			AddonSchemaManager::set_stored_version( $id, $v );
		}
		\WP_CLI::success( "Stored version for '{$id}' is now {$v}." );
	}

	/**
	 * Disable an addon — it will be skipped on every subsequent boot
	 * until explicitly enabled again. Works for bundled and external
	 * addons alike: disabling means disabling, no per-type safeguard.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Addon ID. Must match /^[a-z0-9_]{2,16}$/.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon disable my-addon
	 * wp perflocale addon disable woocommerce
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public function disable( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );

		if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
			\WP_CLI::error( 'Invalid addon id.' );
		}

		$ok = AddonRegistry::set_disabled( $id, true );

		if ( ! $ok ) {
			\WP_CLI::error( "Could not disable '{$id}'. Check the PHP error log under WP_DEBUG for the rejection reason (validation, size cap, or lock contention)." );
		}

		\WP_CLI::success( "Addon '{$id}' disabled. Will be skipped on the next request." );
	}

	/**
	 * Re-enable a previously disabled addon. No-op when the addon is
	 * not in the disabled list. Always succeeds for valid ids — the
	 * underlying option write is idempotent.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Addon ID. Must match /^[a-z0-9_]{2,16}$/.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon enable my-addon
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public function enable( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );

		if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
			\WP_CLI::error( 'Invalid addon id.' );
		}

		$ok = AddonRegistry::set_disabled( $id, false );

		if ( ! $ok ) {
			\WP_CLI::error( "Could not enable '{$id}'. Check the PHP error log under WP_DEBUG." );
		}

		\WP_CLI::success( "Addon '{$id}' enabled." );
	}

	/**
	 * Clear a quarantined addon's failure counter so it can attempt to
	 * boot again on the next request. Use after fixing the underlying
	 * cause of the boot failure.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Addon ID.
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon reset-quarantine my-addon
	 *
	 * @subcommand reset-quarantine
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public function reset_quarantine( array $args, array $assoc_args ): void {
		$id = (string) ( $args[0] ?? '' );

		if ( ! AddonSchemaManager::validate_addon_id( $id ) ) {
			\WP_CLI::error( 'Invalid addon id.' );
		}

		$registry = Plugin::get_instance()->has( 'addon_registry' )
			? Plugin::get_instance()->get( 'addon_registry' )
			: null;

		if ( ! $registry ) {
			\WP_CLI::error( 'Addon registry unavailable.' );
		}

		$registry->reset_quarantine( $id );

		\WP_CLI::success( "Failure counter cleared for '{$id}'. The addon will retry boot on the next request." );
	}

	/**
	 * One-shot health summary: booted, disabled, quarantined, and
	 * version-mismatched addons in a single table. Operator's "what's
	 * up with my addons" entry point.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon doctor
	 * wp perflocale addon doctor --format=json
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public function doctor( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		$registry = Plugin::get_instance()->has( 'addon_registry' )
			? Plugin::get_instance()->get( 'addon_registry' )
			: null;

		if ( ! $registry ) {
			\WP_CLI::error( 'Addon registry unavailable.' );
		}

		$all_addons = $registry->get_addons();
		$booted_ids = [];
		foreach ( $all_addons as $aid => $_ ) {
			if ( $registry->is_booted( $aid ) ) {
				$booted_ids[] = $aid;
			}
		}
		$disabled_ids    = AddonRegistry::get_disabled();
		$quarantined_ids = $registry->get_quarantined_ids();
		$vmismatch_map   = $registry->get_version_mismatches();

		$rows = [
			[
				'state' => 'booted',
				'count' => count( $booted_ids ),
				'ids'   => $booted_ids === [] ? '(none)' : implode( ', ', $booted_ids ),
			],
			[
				'state' => 'disabled',
				'count' => count( $disabled_ids ),
				'ids'   => $disabled_ids === [] ? '(none)' : implode( ', ', $disabled_ids ),
			],
			[
				'state' => 'quarantined',
				'count' => count( $quarantined_ids ),
				'ids'   => $quarantined_ids === [] ? '(none)' : implode( ', ', $quarantined_ids ),
			],
			[
				'state' => 'version-mismatch',
				'count' => count( $vmismatch_map ),
				'ids'   => $vmismatch_map === []
					? '(none)'
					: implode(
						', ',
						array_map(
							static fn( $k, $v ) => $k . ' (needs ' . $v . ')',
							array_keys( $vmismatch_map ),
							array_values( $vmismatch_map )
						)
					),
			],
		];

		\WP_CLI\Utils\format_items( $format, $rows, [ 'state', 'count', 'ids' ] );
	}

	/**
	 * Stringify a boolean for column display.
	 *
	 * @param bool $b Value.
	 * @return string
	 */
	private function bool_label( bool $b ): string {
		return $b ? 'yes' : 'no';
	}

	/**
	 * Safely call is_compatible() - an addon's implementation could throw.
	 *
	 * @param object $addon Addon instance.
	 * @return bool
	 */
	private function safe_is_compatible( object $addon ): bool {
		try {
			return method_exists( $addon, 'is_compatible' ) ? (bool) $addon->is_compatible() : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
