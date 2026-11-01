<?php
/**
 * Add-on id check.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The add-on id pattern, apart from AddonSchemaManager so registering an add-on does not load the schema code.
 */
final class AddonId {

	/**
	 * Regex for addon id - enforced by validate_addon_id().
	 *
	 * Hyphens are allowed for the bundled `contact-form-7`, `beaver-builder` and `gravity-forms`; table_name() strips them.
	 */
	public const ADDON_ID_PATTERN = '/^[a-z0-9_-]{2,16}$/';

	/**
	 * Validate an addon id against ADDON_ID_PATTERN.
	 *
	 * @param string $addon_id Addon identifier.
	 * @return bool
	 */
	public static function validate_addon_id( string $addon_id ): bool {
		return 1 === preg_match( self::ADDON_ID_PATTERN, $addon_id );
	}
}
