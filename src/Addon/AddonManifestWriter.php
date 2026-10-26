<?php
/**
 * Addon manifest writer.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads the per-addon uninstall manifest, one non-autoloaded option per addon.
 *
 * The manifest drives the purge even after the addon's files are gone; it is rewritten only when its checksum changes.
 */
final class AddonManifestWriter {

	/** Option-name prefix. */
	public const OPTION_PREFIX = 'perflocale_addon_manifest_';

	/**
	 * Rewrite an addon's manifest when its live targets changed.
	 *
	 * HasUninstallTargets stores the targets, HasCustomUninstall the flag; an addon with neither gets no manifest.
	 *
	 * @param AddonInterface $addon The addon.
	 * @return bool True if a write occurred, false if unchanged or skipped.
	 */
	public static function refresh( AddonInterface $addon ): bool {
		$addon_id = $addon->get_id();

		if ( ! AddonSchemaManager::validate_addon_id( $addon_id ) ) {
			return false;
		}

		$has_targets = $addon instanceof HasUninstallTargets;
		$has_custom  = $addon instanceof HasCustomUninstall;

		// Nothing to store if the addon opts into neither capability.
		if ( ! $has_targets && ! $has_custom ) {
			return false;
		}

		$raw_targets = $has_targets ? $addon->get_uninstall_targets() : [];
		$normalized  = self::normalize( $raw_targets );

		// WP_DEBUG nudge for entries without the per-addon sub-namespace, which is a convention, not enforced.
		self::warn_unscoped_soft_prefixes( $addon_id, $normalized );

		$manifest = [
			'id'                      => $addon_id,
			'tables'                  => $normalized['tables'],
			'options'                 => $normalized['options'],
			'site_options'            => $normalized['site_options'],
			'transient_prefixes'      => $normalized['transient_prefixes'],
			'meta'                    => $normalized['meta'],
			'capabilities'            => $normalized['capabilities'],
			'cron_hooks'              => $normalized['cron_hooks'],
			'had_custom_uninstall'    => $has_custom,
			'last_seen_version'       => $addon instanceof HasSchema
				? (int) $addon->get_schema_version()
				: 0,
			'plugin_version_at_write' => defined( 'PERFLOCALE_VERSION' ) ? (string) PERFLOCALE_VERSION : '',
			'updated_at'              => gmdate( 'c' ),
		];

		// Checksum excludes updated_at so identical shape doesn't cause writes.
		$checksum_input = $manifest;
		unset( $checksum_input['updated_at'] );
		$manifest['checksum'] = sha1( wp_json_encode( $checksum_input ) );

		$existing = self::read( $addon_id );
		if ( null !== $existing && ( $existing['checksum'] ?? '' ) === $manifest['checksum'] ) {
			return false;
		}

		update_option( self::option_name( $addon_id ), $manifest, false );

		/** @hook perflocale/addon/manifest_written Fires after a manifest refresh. */
		do_action( 'perflocale/addon/manifest_written', $addon_id, $manifest );

		return true;
	}

	/**
	 * Read an addon's manifest, or null if none.
	 *
	 * @param string $addon_id Addon identifier.
	 * @return array<string, mixed>|null
	 */
	public static function read( string $addon_id ): ?array {
		$raw = get_option( self::option_name( $addon_id ), null );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Delete an addon's manifest.
	 *
	 * @param string $addon_id Addon identifier.
	 * @return bool
	 */
	public static function forget( string $addon_id ): bool {
		return delete_option( self::option_name( $addon_id ) );
	}

	/**
	 * Ids of every addon with a manifest on this site (a prefix LIKE on the option_name index).
	 *
	 * @return array<int, string>
	 */
	public static function list_ids(): array {
		global $wpdb;
		$prefix = self::OPTION_PREFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		if ( ! is_array( $names ) ) {
			return [];
		}
		$ids = [];
		foreach ( $names as $name ) {
			$id = substr( (string) $name, strlen( $prefix ) );
			if ( '' !== $id && AddonSchemaManager::validate_addon_id( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Option name for an addon's manifest.
	 *
	 * @param string $addon_id Addon identifier.
	 * @return string
	 */
	public static function option_name( string $addon_id ): string {
		return self::OPTION_PREFIX . $addon_id;
	}

	/**
	 * Under WP_DEBUG, a `_doing_it_wrong` for each soft-prefix entry outside the per-addon sub-namespace.
	 *
	 * That is `perflocale_{addon_id}_` (or `_perflocale_{addon_id}_` for meta). Only `perflocale_` is enforced, so a
	 * sibling addon's broader prefix could wipe such an entry on its uninstall.
	 *
	 * @param string               $addon_id   Addon identifier.
	 * @param array<string, mixed> $normalized Already-normalised manifest shape from self::normalize().
	 * @return void
	 */
	private static function warn_unscoped_soft_prefixes( string $addon_id, array $normalized ): void {
		// Before any message or filter runs.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		/**
		 * Filters whether the soft-prefix nudge runs; false for an addon that claims a broader namespace on purpose.
		 *
		 * @hook perflocale/addon/manifest/check_soft_prefix_namespacing
		 * @param bool   $check    Default true.
		 * @param string $addon_id Identifier of the addon whose manifest is being written.
		 */
		if ( ! apply_filters( 'perflocale/addon/manifest/check_soft_prefix_namespacing', true, $addon_id ) ) {
			return;
		}

		$expected     = 'perflocale_' . $addon_id . '_';
		$expected_alt = '_perflocale_' . $addon_id . '_';

		$nudge = static function ( string $kind, string $entry ) use ( $addon_id, $expected, $expected_alt ): void {
			$message = sprintf(
				/* translators: 1: kind of manifest entry (transient prefix / cron hook / meta key), 2: the offending entry, 3: addon id, 4: expected prefix, 5: alt prefix for meta */
				'Addon "%3$s" declared a %1$s ("%2$s") that doesn\'t start with the recommended per-addon sub-namespace ("%4$s"%5$s). The "perflocale_" namespace is enforced but per-addon namespacing is a convention only, so a sibling addon\'s broader pattern could wipe this entry on the sibling\'s uninstall. Either rename it (recommended) or suppress this notice via the perflocale/addon/manifest/check_soft_prefix_namespacing filter if the broader scope is intentional. See https://perflocale.com/docs/addon-system/#hard-vs-soft-prefixes.',
				$kind,
				$entry,
				$addon_id,
				$expected,
				'meta key' === $kind ? ' or "' . $expected_alt . '"' : ''
			);
			_doing_it_wrong( 'PerfLocale addon manifest', esc_html( $message ), '1.0.0' );
		};

		foreach ( $normalized['transient_prefixes'] ?? [] as $prefix ) {
			$p = (string) $prefix;
			if ( '' !== $p && ! str_starts_with( $p, $expected ) ) {
				$nudge( 'transient prefix', $p );
			}
		}

		foreach ( $normalized['cron_hooks'] ?? [] as $hook ) {
			$h = (string) $hook;
			if ( '' !== $h && ! str_starts_with( $h, $expected ) ) {
				$nudge( 'cron hook', $h );
			}
		}

		foreach ( [ 'post', 'user', 'term', 'comment' ] as $object_type ) {
			$keys = $normalized['meta'][ $object_type ] ?? [];
			foreach ( $keys as $key ) {
				$k = (string) $key;
				if ( '' === $k ) {
					continue;
				}
				if ( ! str_starts_with( $k, $expected ) && ! str_starts_with( $k, $expected_alt ) ) {
					$nudge( $object_type . ' meta key', $k );
				}
			}
		}
	}

	/**
	 * Normalize raw uninstall targets into the manifest shape, dropping non-string and empty entries.
	 *
	 * The namespace check that guards core data is AddonUninstaller's; this only coerces shape.
	 *
	 * @param array<string, mixed> $raw Targets as returned by get_uninstall_targets().
	 * @return array<string, array<int|string, mixed>>
	 */
	public static function normalize( array $raw ): array {
		$str_list = static function ( mixed $v ): array {
			if ( ! is_array( $v ) ) {
				return [];
			}
			$out = [];
			foreach ( $v as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$out[] = $item;
				}
			}
			return array_values( array_unique( $out ) );
		};

		// Short names become part of a table identifier: only SHORT_NAME_PATTERN passes.
		$tables = array_values(
			array_filter(
				$str_list( $raw['tables'] ?? [] ),
				static fn( string $name ) => 1 === preg_match( AddonSchemaManager::SHORT_NAME_PATTERN, $name )
			)
		);

		$meta_by_type = [];
		if ( isset( $raw['meta'] ) && is_array( $raw['meta'] ) ) {
			foreach ( [ 'post', 'user', 'term', 'comment' ] as $type ) {
				$keys = $raw['meta'][ $type ] ?? [];
				// Bound values; the prefix keeps other plugins' meta out.
				$meta_by_type[ $type ] = array_values(
					array_filter(
						$str_list( $keys ),
						static fn( string $k ) => str_starts_with( $k, 'perflocale_' )
						|| str_starts_with( $k, '_perflocale_' )
					)
				);
			}
		} else {
			$meta_by_type = [
				'post'    => [],
				'user'    => [],
				'term'    => [],
				'comment' => [],
			];
		}

		// esc_like()'d values; the prefix keeps other plugins' transients out.
		$transients = array_values(
			array_filter(
				$str_list( $raw['transients'] ?? [] ),
				static fn( string $p ) => str_starts_with( $p, 'perflocale_' )
			)
		);

		return [
			'tables'             => $tables,
			'options'            => $str_list( $raw['options'] ?? [] ),
			'site_options'       => $str_list( $raw['site_options'] ?? [] ),
			'transient_prefixes' => $transients,
			'meta'               => $meta_by_type,
			'capabilities'       => $str_list( $raw['capabilities'] ?? [] ),
			'cron_hooks'         => $str_list( $raw['cron_hooks'] ?? [] ),
		];
	}
}
