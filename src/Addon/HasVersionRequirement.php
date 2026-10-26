<?php
/**
 * Opt-in interface - addons that need a minimum PerfLocale version.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in capability: on an older PerfLocale the registry skips the addon with an admin notice instead of a fatal.
 *
 * Compared with version_compare( PERFLOCALE_VERSION, $min, '<' ): return a dot version such as "1.4.0".
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasVersionRequirement {

	/**
	 * Minimum PerfLocale version this addon requires.
	 *
	 * @return string
	 */
	public function get_min_perflocale_version(): string;
}
