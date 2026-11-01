<?php
/**
 * Loads bundled addons held as deferred slots in AddonRegistry.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requires a deferred bundled addon's main file and puts its object in its slot, on first use (get_addons(), or a
 * filter that boots it).
 *
 * @internal
 */
final class AddonSlotLoader {

	/**
	 * Load every deferred slot's addon, in place.
	 *
	 * @param array<string, AddonInterface|null>                                 $addons   The registry's addons (updated).
	 * @param array<string, AddonInterface|null>                                 $bundled  The registry's bundled addons (updated).
	 * @param array<string, array{file: string, class: string, compat: callable}> $manifest Bundled addon manifest.
	 * @return void
	 */
	public static function fill_all( array &$addons, array &$bundled, array $manifest ): void {
		foreach ( array_keys( $addons, null, true ) as $id ) {
			self::fill( (string) $id, $addons, $bundled, $manifest );
		}
	}

	/**
	 * Load one deferred bundled addon's file and object into its slot; a file without a valid class frees the slot.
	 *
	 * @param string                                                              $id       Addon ID.
	 * @param array<string, AddonInterface|null>                                 $addons   The registry's addons (updated).
	 * @param array<string, AddonInterface|null>                                 $bundled  The registry's bundled addons (updated).
	 * @param array<string, array{file: string, class: string, compat: callable}> $manifest Bundled addon manifest.
	 * @return AddonInterface|null
	 */
	public static function fill( string $id, array &$addons, array &$bundled, array $manifest ): ?AddonInterface {
		$entry = $manifest[ $id ] ?? null;
		$addon = null;

		if ( null !== $entry && file_exists( $entry['file'] ) ) {
			require_once $entry['file'];

			$class = $entry['class'];
			$made  = class_exists( $class ) ? new $class() : null;
			$addon = $made instanceof AddonInterface ? $made : null;
		}

		if ( null === $addon ) {
			unset( $addons[ $id ], $bundled[ $id ] );

			return null;
		}

		$addons[ $id ] = $addon;

		if ( array_key_exists( $id, $bundled ) ) {
			$bundled[ $id ] = $addon;
		}

		return $addon;
	}
}
