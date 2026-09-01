<?php
/**
 * Opt-in interface — addons that need a minimum PerfLocale version.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implement this on an addon class that uses an API surface introduced in
 * a specific PerfLocale release. The registry calls
 * {@see get_min_perflocale_version()} during boot and skips the addon
 * (surfacing an admin notice instead of fataling) when the host plugin is
 * older than the required version.
 *
 * The check uses PHP's {@see version_compare()} with the `<` operator, so
 * the string should be a SemVer-ish triple ("1.4.0", "2.0.0-beta1", etc.).
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasVersionRequirement {

	/**
	 * Minimum PerfLocale version this addon requires.
	 *
	 * @return string
	 */
	public function get_min_perflocale_version(): string;
}
