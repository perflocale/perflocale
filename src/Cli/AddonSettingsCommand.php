<?php
/**
 * WP-CLI subcommands for managing a PerfLocale addon's settings.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Cli;

use PerfLocale\Addon\AddonSchemaManager;
use PerfLocale\Addon\AddonSettings;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-addon settings commands.
 *
 * Registered as `wp perflocale addon settings <subcommand>`.
 */
final class AddonSettingsCommand {

	public function __construct() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'perflocale addon settings', $this );
		}
	}

	/**
	 * Read a single addon setting. Returns the stored value as JSON if
	 * --format=json, else as the raw scalar / serialised form.
	 *
	 * ## OPTIONS
	 *
	 * <addon-id>
	 * : Addon ID.
	 *
	 * <key>
	 * : Setting key declared in the addon's get_settings_fields().
	 *
	 * [--default=<value>]
	 * : Fallback returned when the value isn't stored. Default: empty string.
	 *
	 * [--format=<format>]
	 * : Output format. Default: scalar (prints the value as-is).
	 * ---
	 * default: scalar
	 * options:
	 *   - scalar
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon settings get woocommerce wc_sync_stock
	 * wp perflocale addon settings get my-addon endpoint --default=https://api.example.com
	 * wp perflocale addon settings get my-addon batch_size --format=json
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public function get( array $args, array $assoc_args ): void {
		$addon_id = (string) ( $args[0] ?? '' );
		$key      = (string) ( $args[1] ?? '' );
		$default  = $assoc_args['default'] ?? '';
		$format   = (string) ( $assoc_args['format'] ?? 'scalar' );

		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			\WP_CLI::error( 'Invalid addon id.' );
		}
		if ( $key === '' ) {
			\WP_CLI::error( 'Setting key required.' );
		}

		// Global-storage fields live in `perflocale_settings`, not in
		// `perflocale_addon_settings`. Read from the live option so the
		// operator sees the value that the addon's runtime actually uses.
		$field = $this->get_field_def( $addon_id, $key );
		if ( $field !== null && AddonSettings::is_global_storage( $field ) ) {
			$value = $this->read_global_setting( $key, $default );
		} else {
			$value = AddonSettings::get( $addon_id, $key, $default );
		}

		if ( $format === 'json' ) {
			\WP_CLI::print_value( $value, [ 'format' => 'json' ] );
			return;
		}

		// Scalar mode: bool → 'true'/'false', null → '', arrays → JSON,
		// everything else → string-cast. Same conventions as `wp option get`.
		if ( is_bool( $value ) ) {
			\WP_CLI::log( $value ? 'true' : 'false' );
		} elseif ( $value === null ) {
			\WP_CLI::log( '' );
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			\WP_CLI::log( (string) wp_json_encode( $value ) );
		} else {
			\WP_CLI::log( (string) $value );
		}
	}

	/**
	 * Set a single addon setting. The value is typed via --type; default
	 * is string. Returns an error when the addon's per-entry 16 KiB cap
	 * would be exceeded.
	 *
	 * ## OPTIONS
	 *
	 * <addon-id>
	 * : Addon ID.
	 *
	 * <key>
	 * : Setting key.
	 *
	 * <value>
	 * : Value to store. Interpretation depends on --type.
	 *
	 * [--type=<type>]
	 * : Value type. Default: string.
	 * ---
	 * default: string
	 * options:
	 *   - string
	 *   - bool
	 *   - int
	 *   - float
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp perflocale addon settings set woocommerce wc_sync_stock true --type=bool
	 * wp perflocale addon settings set my-addon batch_size 50 --type=int
	 * wp perflocale addon settings set my-addon limits '{"max":100}' --type=json
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public function set( array $args, array $assoc_args ): void {
		$addon_id = (string) ( $args[0] ?? '' );
		$key      = (string) ( $args[1] ?? '' );
		$raw      = (string) ( $args[2] ?? '' );
		$type     = (string) ( $assoc_args['type'] ?? 'string' );

		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			\WP_CLI::error( 'Invalid addon id.' );
		}
		if ( $key === '' ) {
			\WP_CLI::error( 'Setting key required.' );
		}

		switch ( $type ) {
			case 'bool':
				$value = in_array( strtolower( $raw ), [ '1', 'true', 'yes', 'on' ], true );
				break;
			case 'int':
				if ( ! is_numeric( $raw ) ) {
					\WP_CLI::error( "Value '{$raw}' is not numeric." );
				}
				$value = (int) $raw;
				break;
			case 'float':
				if ( ! is_numeric( $raw ) ) {
					\WP_CLI::error( "Value '{$raw}' is not numeric." );
				}
				$value = (float) $raw;
				break;
			case 'json':
				$value = json_decode( $raw, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					\WP_CLI::error( 'Invalid JSON: ' . json_last_error_msg() );
				}
				break;
			case 'string':
			default:
				$value = $raw;
				break;
		}

		// Fields declared `'storage' => 'global'` live in the shared
		// `perflocale_settings` option, not in addon storage — the addon's
		// runtime reads them via Settings. Write through Settings::update()
		// so the full invalidation chain runs (config-cache flush, cron
		// reschedules, settings-updated listeners); a raw `wp option patch`
		// would change the stored value while leaving all of that stale.
		$field = $this->get_field_def( $addon_id, $key );
		if ( $field !== null && AddonSettings::is_global_storage( $field ) ) {
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( ! $plugin->has( 'settings' ) ) {
				\WP_CLI::error( 'PerfLocale settings service unavailable.' );
			}

			/** @var \PerfLocale\Settings $settings */
			$settings = $plugin->get( 'settings' );
			$settings->update( [ $key => $value ] );

			$effective = $settings->get( $key );

			if ( $effective !== $value ) {
				// Sanitizer coercion or an env/constant override won.
				\WP_CLI::warning(
					"Saved, but the effective value of '{$key}' is " . wp_json_encode( $effective ) .
					' (sanitized, or overridden by an environment variable / PHP constant).'
				);
			}

			\WP_CLI::success( "Saved {$key} to the global perflocale_settings option." );
			return;
		}

		$ok = AddonSettings::set( $addon_id, $key, $value );

		if ( ! $ok ) {
			\WP_CLI::error( "Could not save '{$addon_id}.{$key}'. The addon entry may exceed the 16 KiB per-addon cap; check the PHP error log under WP_DEBUG." );
		}

		\WP_CLI::success( "Saved {$addon_id}.{$key}." );
	}

	/**
	 * List all stored settings for one addon as a key/value table.
	 *
	 * ## OPTIONS
	 *
	 * <addon-id>
	 * : Addon ID.
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
	 * wp perflocale addon settings list woocommerce
	 * wp perflocale addon settings list my-addon --format=json
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		$addon_id = (string) ( $args[0] ?? '' );
		$format   = (string) ( $assoc_args['format'] ?? 'table' );

		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			\WP_CLI::error( 'Invalid addon id.' );
		}

		$entry = AddonSettings::get_addon( $addon_id );

		// Collect global-storage fields too — they're declared by the
		// addon but live in `perflocale_settings`. Operator running
		// `settings list woocommerce` expects to see WC's settings even
		// though they're not in the addon-settings option.
		$reg    = Plugin::get_instance()->has( 'addon_registry' )
			? Plugin::get_instance()->get( 'addon_registry' )
			: null;
		$addons = $reg && method_exists( $reg, 'get_addons' ) ? $reg->get_addons() : [];
		$addon  = $addons[ $addon_id ] ?? null;

		$declared_global = [];
		if ( $addon && method_exists( $addon, 'get_settings_fields' ) ) {
			try {
				foreach ( (array) $addon->get_settings_fields() as $k => $f ) {
					if ( is_array( $f ) && AddonSettings::is_global_storage( $f ) ) {
						$declared_global[ (string) $k ] = $this->read_global_setting( (string) $k, $f['default'] ?? null );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$rows = [];
		foreach ( $entry as $k => $v ) {
			$rows[] = [
				'key'     => (string) $k,
				'value'   => $this->scalarize( $v ),
				'storage' => 'addon',
			];
		}
		foreach ( $declared_global as $k => $v ) {
			$rows[] = [
				'key'     => (string) $k,
				'value'   => $this->scalarize( $v ),
				'storage' => 'global',
			];
		}

		if ( empty( $rows ) ) {
			if ( $format === 'table' ) {
				\WP_CLI::success( "No stored settings for '{$addon_id}'." );
				return;
			}
			// Machine formats still need a parseable empty payload.
			\WP_CLI\Utils\format_items( $format, [], [ 'key', 'value', 'storage' ] );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'key', 'value', 'storage' ] );
	}

	/**
	 * Look up the get_settings_fields() definition for one key on one
	 * addon. Returns null if the addon isn't registered, doesn't declare
	 * that key, or its get_settings_fields() throws. Used by get/set/list
	 * to honour `'storage' => 'global'`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_field_def( string $addon_id, string $key ): ?array {
		$reg = Plugin::get_instance()->has( 'addon_registry' )
			? Plugin::get_instance()->get( 'addon_registry' )
			: null;
		if ( ! $reg || ! method_exists( $reg, 'get_addons' ) ) {
			return null;
		}
		$addons = $reg->get_addons();
		$addon  = $addons[ $addon_id ] ?? null;
		if ( ! $addon || ! method_exists( $addon, 'get_settings_fields' ) ) {
			return null;
		}
		try {
			$fields = (array) $addon->get_settings_fields();
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $fields[ $key ] ?? null ) ? $fields[ $key ] : null;
	}

	/**
	 * Read a single key from the main `perflocale_settings` option,
	 * falling through the same env-var → wp-config-constant → DB
	 * resolution that the runtime uses. Used by `get` to surface the
	 * live value for `'storage' => 'global'` fields.
	 *
	 * @param mixed $default
	 * @return mixed
	 */
	private function read_global_setting( string $key, $default ) {
		try {
			$settings = Plugin::get_instance()->get( 'settings' );
			return $settings->get( $key, $default );
		} catch ( \Throwable $e ) {
			return $default;
		}
	}

	/**
	 * Render a setting value for the CLI table — bool → 'true'/'false',
	 * array/object → JSON, null → '', everything else string-cast.
	 *
	 * @param mixed $v
	 */
	private function scalarize( $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( is_array( $v ) || is_object( $v ) ) {
			return (string) wp_json_encode( $v );
		}
		if ( $v === null ) {
			return '';
		}
		return (string) $v;
	}
}
