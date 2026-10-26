<?php
/**
 * The public API objects behind perflocale()->addon(), loaded on the first call.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates an addon's public API object (HasPublicApi::get_public_api()) for AddonRegistry::public_api().
 *
 * @internal
 */
final class AddonPublicApi {

	/**
	 * Helper::addon(): the registry's public API object for this id, or null without a registry.
	 *
	 * @param string $id Addon ID.
	 * @return object|null
	 */
	public static function lookup( string $id ): ?object {
		if ( '' === $id ) {
			return null;
		}

		try {
			$plugin = Plugin::get_instance();

			if ( ! $plugin->has( Plugin::SERVICE_ADDONS ) ) {
				return null;
			}

			$registry = $plugin->addon_registry();
		} catch ( \Throwable $e ) {
			return null;
		}

		return $registry->public_api( $id );
	}

	/**
	 * AddonRegistry::public_api(): the kept object, or one created for a booted addon and kept for the request.
	 *
	 * @param string                        $id     Addon ID.
	 * @param array<string, object|null>    $apis   The registry's objects handed out in this request (updated).
	 * @param array<string, bool>           $booted The registry's booted ids.
	 * @param array<string, AddonInterface> $addons The registry's addons.
	 * @return object|null
	 */
	public static function get( string $id, array &$apis, array $booted, array $addons ): ?object {
		// Not kept before the addon boots: the registry boots in passes.
		if ( ! array_key_exists( $id, $apis ) && '' !== $id && isset( $booted[ $id ] ) ) {
			$apis[ $id ] = self::create( $id, $addons[ $id ] ?? null );
		}

		return $apis[ $id ] ?? null;
	}

	/**
	 * The API object of a booted addon: null when it does not implement HasPublicApi, or when get_public_api()
	 * threw (reported with _doing_it_wrong(); its message is logged under WP_DEBUG only).
	 *
	 * @param string              $id    Addon ID.
	 * @param AddonInterface|null $addon The registered addon.
	 * @return object|null
	 */
	public static function create( string $id, ?AddonInterface $addon ): ?object {
		// Mapped here, not through HasPublicApi: the interface would load on every request of a WooCommerce site.
		if ( null !== $addon && 'PerfLocaleWooCommerce' === $addon::class ) {
			return \PerfLocale\WooCommerce\Api::instance();
		}

		$api = null;

		if ( $addon instanceof HasPublicApi ) {
			try {
				$api = $addon->get_public_api();
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'PerfLocale addon %s: get_public_api() failed: %s', $id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only.
				}
			}

			if ( null === $api ) {
				_doing_it_wrong(
					'perflocale()->addon()',
					esc_html(
						sprintf(
							/* translators: %s: addon id. */
							__( 'The public API of add-on "%s" could not be created; perflocale()->addon() returns null for it.', 'perflocale' ),
							$id
						)
					),
					'1.0.7.4'
				);
			}
		}

		return $api;
	}
}
