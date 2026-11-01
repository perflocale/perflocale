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

		// Global-storage fields live in `perflocale_settings`: read the value the addon's runtime uses.
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

		// Global-storage fields are written through Settings::update(), so its invalidation (config cache, cron
		// reschedules, settings listeners) runs; a raw option write would leave all of that stale.
		$field = $this->get_field_def( $addon_id, $key );
		if ( $field !== null && AddonSettings::is_global_storage( $field ) ) {
			$plugin = \PerfLocale\Plugin::get_instance();

			if ( ! $plugin->has( 'settings' ) ) {
				\WP_CLI::error( 'PerfLocale settings service unavailable.' );
			}

			/** @var \PerfLocale\Settings $settings */
			$settings = $plugin->get( 'settings' );

			// The settings screen's rule: a new agency URL must be https; the stored value stays, http or not.
			if ( 'mt_agency_url' === $key && \PerfLocale\Util\OutboundUrl::refuses_new_plain_http( $value, $settings->get( 'mt_agency_url' ) ) ) {
				\WP_CLI::error( 'mt_agency_url not saved: it must start with https:// (http:// only for a host added to the perflocale/mt/trusted_hosts filter). Run the command again with an https:// address.' );
			}

			if ( ! $settings->update( [ $key => $value ] ) && ! \PerfLocale\Settings::last_update_was_noop() ) {
				\WP_CLI::error( self::refusal( $key ) );
			}

			// The same rule inside the settings lock: another save may have changed the stored URL since the check above.
			if ( 'mt_agency_url' === $key && \PerfLocale\Util\OutboundUrl::refused_plain_http() ) {
				\WP_CLI::error( 'mt_agency_url not saved: it must start with https:// (http:// only for a host added to the perflocale/mt/trusted_hosts filter). Run the command again with an https:// address.' );
			}

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
			\WP_CLI::error( self::addon_refusal( "{$addon_id}.{$key}" ) );
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

		// Global-storage fields too: declared by the addon, stored in `perflocale_settings`.
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
	 * The get_settings_fields() definition of one key of one addon, or null (not registered, not declared, or it throws).
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
	 * One key of `perflocale_settings`, through the runtime's environment, constant and database resolution.
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
	 * Render a setting value for the CLI table - bool → 'true'/'false',
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

	/**
	 * Why a global key's save stored nothing: the settings lock, or OptionWriter's reason for the perflocale_settings row.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function refusal( string $key ): string {
		$reason = \PerfLocale\Settings::last_update_was_busy() ? 'busy' : ( \PerfLocale\Database\OptionWriter::last_failure( 'perflocale_settings' )['reason'] ?? '' );

		switch ( $reason ) {
			case 'busy':
				return "{$key} not saved: another settings save was running. Run the command again.";
			case 'database':
				return "{$key} not saved: the database refused to store the option perflocale_settings, so the value may be too long or hold characters it cannot store. Check the value, then run the command again. "
					. ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? 'The database error is in the debug log.' : 'To log the database error, turn on WP_DEBUG_LOG and run the command again.' );
			case 'changed':
				return "{$key} not saved: another plugin or request kept changing the stored settings during this save. Run the command again.";
			case 'unchanged':
				return "{$key} not saved: a filter on the option perflocale_settings kept the stored value. Check the plugins that filter it, then run the command again.";
		}

		return "{$key} not saved; the stored settings are unchanged. Run the command again.";
	}

	/**
	 * Why an add-on setting was not saved, from AddonSettings::last_failure().
	 *
	 * @param string $item "<addon id>.<key>".
	 * @return string
	 */
	private static function addon_refusal( string $item ): string {
		switch ( AddonSettings::last_failure() ) {
			case 'busy':
				return "Could not save '{$item}': another add-on settings save was running. Run the command again.";
			case 'too_large':
				return "Could not save '{$item}': the add-on's settings would be larger than the " . intdiv( AddonSettings::MAX_ADDON_BYTES, 1024 ) . ' KiB stored per add-on. Use a shorter value, then run the command again.';
			case 'database':
				return "Could not save '{$item}': the database refused to store the option perflocale_addon_settings, so the value may hold characters it cannot store. Check the value, then run the command again. "
					. ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? 'The database error is in the debug log.' : 'To log the database error, turn on WP_DEBUG_LOG and run the command again.' );
			case 'unchanged':
				return "Could not save '{$item}': a filter on the option perflocale_addon_settings kept the stored value. Check the plugins that filter it, then run the command again.";
		}

		return "Could not save '{$item}'. Check the add-on id and key, then run the command again.";
	}
}
