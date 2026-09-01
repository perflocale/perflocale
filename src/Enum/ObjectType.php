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
 * Represents the type of translatable object.
 *
 * The string values are persisted in the `translation_groups.type` column
 * and are referenced both via this enum (PHP-side type safety) and via raw
 * string literals in performance-sensitive SQL builders (avoids the
 * `->value` access in tight join clauses). Either path is canonical; the
 * two MUST agree.
 *
 * Removing any case here is a schema-level change — existing rows in
 * `wp_perflocale_translation_groups` may carry that value, and a stripped
 * case would cause `from()` to throw on unserialise. Add a case freely,
 * remove only via a migration that rewrites or drops the affected rows.
 */
enum ObjectType: string {

	/**
	 * Post-like objects (posts, pages, custom post types). The dominant
	 * use of the plugin — every translation_group row created via
	 * PostTranslationManager carries this type.
	 */
	case Post = 'post';

	/**
	 * Term-like objects (categories, tags, custom taxonomies). Used by
	 * TermTranslationManager + TermQueryFilter.
	 */
	case Term = 'term';

	/**
	 * Reserved for translating wp_options values (settings whose
	 * user-facing strings need localising — e.g. site tagline,
	 * customizer text). No production code path uses this yet, but the
	 * schema accommodates it: removing the case here would force a
	 * migration when the feature lands, so it's kept ahead of demand.
	 * The unit test in tests/Unit/ObjectTypeTest.php pins the value so
	 * a refactor can't silently change it.
	 */
	case Option = 'option';

	/**
	 * String-translation groups (gettext-style key/value pairs in the
	 * dedicated `perflocale_strings` + `perflocale_string_translations`
	 * tables). Referenced in production via raw `'string'` SQL literals
	 * (see WooCommerce\EmailTranslation, Database\Repository\
	 * TranslationGroupRepository::link_object guard) rather than via
	 * this enum case — the literal is faster in tight join clauses and
	 * easier to scan in raw SQL. The enum case stays as the canonical
	 * source-of-truth definition.
	 */
	case String = 'string';
}
