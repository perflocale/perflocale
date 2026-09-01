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
 * Writes + reads the per-addon uninstall manifest.
 *
 * Manifest is the source of truth for uninstall - even when the addon's
 * class file is gone from disk, the manifest still drives the declarative
 * purge. This is what makes third-party addon cleanup reliable.
 *
 * One option per addon (`perflocale_addon_manifest_{addon_id}`, autoload=false).
 * Checksumed against the live targets - re-written only when the shape
 * actually changes. That keeps the option_id churn low.
 */
final class AddonManifestWriter {

	/** Option-name prefix. */
	public const OPTION_PREFIX = 'perflocale_addon_manifest_';

	/**
	 * Refresh the manifest for an addon if the live targets differ from
	 * what's stored. No-op when targets are unchanged.
	 *
	 * Handles 3 cases:
	 * • Addon implements HasUninstallTargets: capture current targets + flags.
	 * • Addon implements only HasCustomUninstall: capture the flag, no targets.
	 * • Addon implements neither: no-op (no manifest written).
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

		// Developer-mode nudge: notice each soft-prefix entry that doesn't
		// carry the per-addon sub-namespace. The trust model is intentional
		// (per-addon namespacing is not enforced, only the plugin-wide
		// `perflocale_` prefix is), but helping addon authors catch
		// accidental cross-addon collisions during development beats
		// debugging "why did my data disappear?" after the fact.
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
	 * Return all addon-ids currently with a manifest on this site.
	 *
	 * Uses a LIKE scan on the options table (indexed by option_name).
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
	 * Developer-mode DX nudge: emit a `_doing_it_wrong` notice for each
	 * soft-prefix manifest entry that doesn't carry the per-addon
	 * sub-namespace (`perflocale_{addon_id}_` for transients/cron-hooks,
	 * additionally `_perflocale_{addon_id}_` for meta keys).
	 *
	 * Soft-prefix isolation is NOT enforced — the trust model matches
	 * WordPress's own (a sibling plugin can wipe your transients on its
	 * uninstall too). This helper only surfaces accidental sub-namespace
	 * omissions to addon authors during development; production users
	 * never see it (core gates `_doing_it_wrong` on `WP_DEBUG`).
	 *
	 * Suppress on a per-addon basis via the
	 * `perflocale/addon/manifest/check_soft_prefix_namespacing` filter
	 * — useful for addons that intentionally coordinate state across the
	 * addon namespace.
	 *
	 * @param string               $addon_id   Addon identifier.
	 * @param array<string, mixed> $normalized Already-normalised manifest shape from self::normalize().
	 * @return void
	 */
	private static function warn_unscoped_soft_prefixes( string $addon_id, array $normalized ): void {
		// Cheap early exit before building any messages or running filters
		// in production. `_doing_it_wrong` itself also gates on WP_DEBUG.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		/**
		 * Filter to suppress the soft-prefix DX nudge for addons that
		 * intentionally claim a broader namespace (e.g. a coordinator
		 * addon cleaning up after a family of sibling addons it manages).
		 * Return false to skip the check entirely for this addon.
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
	 * Normalize raw uninstall targets into the manifest shape, filtering out
	 * obviously invalid entries (non-string, empty, wrong type).
	 *
	 * Prefix validation (the security-sensitive part) happens later in
	 * AddonUninstaller; this step just coerces shape.
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

		// Table short names are inserted verbatim into DROP TABLE IF EXISTS
		// statements wrapped in backticks. An addon returning a crafted value
		// containing backticks or semicolons could break out and execute
		// arbitrary SQL. Enforce the same strict pattern AddonSchemaManager
		// uses for apply_schema() - reject anything that doesn't match.
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
				// Meta keys are bound parameters to DELETE ... WHERE meta_key IN (%s,…),
				// so no SQL-injection vector - but enforce the soft prefix as a data-
				// integrity guard.
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

		// Transient prefixes are escape_like-wrapped in delete_transients() -
		// safe from SQL injection. Enforce the perflocale_ namespace as a
		// data-integrity guard so an addon can't expire unrelated plugins'
		// transients.
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
