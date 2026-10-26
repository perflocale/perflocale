<?php
/**
 * Optional addon-card metadata capability.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt-in capability: overrides for the addon's card on the Addons page; an empty array keeps every default.
 *
 * Optional string keys: name, description, category (an AddonsPage::get_categories() key, default 'feature'), icon
 * (a Dashicons class), requires (shown while the addon is not booted), settings_tab (the Manage link, sanitize_key()).
 *
 * @api  Stable addon-facing API surface - semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasCardInfo {

	/**
	 * The card overrides.
	 *
	 * @return array<string, mixed>
	 */
	public function get_card_info(): array;
}
