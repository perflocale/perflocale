<?php
/**
 * Opt-in interface - addons that offer a PHP API through perflocale()->addon().
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An addon's PHP API object for perflocale()->addon( <id> ). No magic methods: callers feature-detect with method_exists().
 * Rules (no internals, secrets or request routes): /docs/addon-system/developer-toolkit/.
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 * @since 1.0.7.4
 */
interface HasPublicApi {

	/**
	 * The API object; called once per request, on the first perflocale()->addon() call after the addon booted. Keep it cheap.
	 *
	 * @return object
	 */
	public function get_public_api(): object;
}
