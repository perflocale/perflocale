<?php
/**
 * Object type enum.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Enum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Type of a translatable object, stored in `translation_groups.type`.
 *
 * Hot SQL builders use the raw string values, so the two must agree. Removing a case needs a migration of the stored
 * rows, or `from()` throws on them.
 */
enum ObjectType: string {

	/**
	 * Posts, pages and custom post types.
	 */
	case Post = 'post';

	/**
	 * Categories, tags and custom taxonomy terms.
	 */
	case Term = 'term';

	/**
	 * Reserved for wp_options values; unused, kept so the stored value never needs a migration.
	 */
	case Option = 'option';

	/**
	 * String-translation groups; production SQL uses the raw 'string' literal.
	 */
	case String = 'string';
}
