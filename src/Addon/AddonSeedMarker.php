<?php
/**
 * Publication of the add-on seed marker.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

use PerfLocale\Concurrency\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes AddonRegistry's seed marker under the add-on settings-write lock, on admin, cron and WP-CLI requests only;
 * loaded only after a boot pass checked an add-on.
 *
 * An AddonSettings write deletes the marker inside that lock, so the two rows read there agree.
 *
 * @since 1.0.7.5
 * @internal
 */
final class AddonSeedMarker {

	/**
	 * Add-on ids whose seed check read their settings entry (set by AddonRegistry): their marker needs that entry.
	 *
	 * @var array<string, bool>
	 */
	public static array $entries = [];

	/**
	 * Add this pass's checks to the stored marker, leaving out an add-on whose settings entry is now empty.
	 *
	 * @param array<string, string> $checked Add-on id => version whose check passed.
	 * @return void
	 */
	public static function publish( array $checked ): void {
		// Never from a visitor's request: it keeps the default check. Busy: nothing is published; the next request checks again.
		if ( ! ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) || ! Lock::acquire( AddonSettings::LOCK_NAME, AddonSettings::LOCK_TTL ) ) {
			return;
		}

		try {
			$settings = (array) self::row( AddonSettings::OPTION );

			foreach ( array_intersect_key( self::$entries, $checked ) as $id => $unused ) {
				if ( empty( $settings[ $id ] ) ) {
					unset( $checked[ $id ] );
				}
			}

			$marker = self::row( AddonRegistry::SEEDED_OPTION );
			$stored = is_array( $marker ) && PERFLOCALE_VERSION === ( $marker['v'] ?? null ) ? (array) ( $marker['a'] ?? [] ) : [];

			if ( [] !== $checked ) {
				update_option(
					AddonRegistry::SEEDED_OPTION,
					[
						'v' => PERFLOCALE_VERSION,
						'a' => $checked + $stored,
					],
					true
				);
			}
		} finally {
			Lock::release( AddonSettings::LOCK_NAME );
		}
	}

	/**
	 * An option's committed value, past this request's caches (the marker is autoloaded); null when the row is missing.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private static function row( string $name ): mixed {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read under the settings lock; a cached copy may predate another request's write.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? null : maybe_unserialize( $value );
	}
}
