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
 * Opt-in capability interface for addons that want to control how their
 * card renders on the WP Admin → PerfLocale → Addons page.
 *
 * Without this interface, the registry generates a generic card using the
 * addon's `get_name()` / `get_version()` / hard-coded defaults. Implement
 * this to override category, icon, description, requires-line, and the
 * Settings deep-link.
 *
 * Implementations must return an associative array with any subset of:
 *
 *   • name         (string)  Display name override. Defaults to get_name().
 *   • description  (string)  One-line card description. Default: empty.
 *   • category     (string)  Must match a category key from
 *                            AddonsPage::get_categories(): 'feature',
 *                            'theme', 'seo', 'ecommerce', 'builder',
 *                            'fields', 'forms'. Default: 'feature'.
 *   • icon         (string)  Dashicons class, e.g. 'dashicons-translation'.
 *                            Default: 'dashicons-admin-plugins'.
 *   • requires     (string)  Short prerequisite blurb shown on the card
 *                            when the addon isn't booted. Default:
 *                            "{$name} v{$version}".
 *   • settings_tab (string)  If non-empty, the card's "Manage" link points
 *                            at Settings → Addons → {tab}. Sanitised
 *                            through sanitize_key() before use.
 *
 * Returning an empty array is the same as not implementing the interface
 * at all — every default applies.
 *
 * @api  Stable addon-facing API surface — semver-bound; safe for 3rd-party addons to depend on.
 */
interface HasCardInfo {

	/**
	 * @return array<string, mixed>
	 */
	public function get_card_info(): array;
}
