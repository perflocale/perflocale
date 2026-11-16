<?php
/**
 * The scope of a resync run.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which objects a run may resync: translatable post types plus custom fields, minus exclusions, only ever
 * narrowed by the filters. Nothing here runs on a save or a page view.
 */
final class Scope {

	/** The scope entry for custom fields. */
	public const CUSTOM_FIELDS = 'custom_fields';

	/**
	 * The lists of this request, keyed by blog and run arguments.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $memo = [];

	/**
	 * The object types in scope for a run.
	 *
	 * @param array<string, mixed> $args The run's facts for the filter: trigger, language_ids, user_id.
	 * @return list<string> Post type names, and `custom_fields` when fields are in scope.
	 */
	public static function object_types( array $args = [] ): array {
		$key = get_current_blog_id() . ':' . md5( (string) wp_json_encode( $args ) );

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$settings     = \PerfLocale\Plugin::get_instance()->settings();
		$translatable = Scalar::strings( $settings->get_site_translate_post_types() );
		$excluded     = Scalar::strings( $settings->get( 'resync_excluded', [] ) );
		$types        = self::narrow( $translatable, $excluded, null );

		/**
		 * Filters the object types a resync run covers; the answer can only narrow the list.
		 *
		 * @hook perflocale/resync/object_types
		 * @since 1.0.8
		 *
		 * @param string[]             $types Post type names, and `custom_fields` when fields are in scope.
		 * @param array<string, mixed> $args  The run: trigger, language_ids, user_id.
		 */
		$filtered = apply_filters( 'perflocale/resync/object_types', $types, $args );

		self::$memo[ $key ] = self::narrow( $translatable, $excluded, $filtered );

		return self::$memo[ $key ];
	}

	/**
	 * The pure rule: translatable types (never attachment) plus `custom_fields`, minus exclusions, intersected
	 * with the filter's answer.
	 *
	 * @param array<int, string> $translatable The site's translatable post types.
	 * @param array<int, string> $excluded     The `resync_excluded` setting.
	 * @param mixed              $filtered     The filter's answer, null before the filter.
	 * @return list<string>
	 */
	public static function narrow( array $translatable, array $excluded, mixed $filtered ): array {
		$all = [];

		foreach ( $translatable as $type ) {
			if ( '' !== $type && 'attachment' !== $type && self::CUSTOM_FIELDS !== $type ) {
				$all[] = $type;
			}
		}

		$all[] = self::CUSTOM_FIELDS;
		$out   = array_values( array_diff( array_unique( $all ), $excluded ) );

		if ( is_array( $filtered ) ) {
			$allowed = Scalar::strings( $filtered );
			$out     = array_values( array_intersect( $out, $allowed ) );
		}

		return $out;
	}

	/**
	 * The `resync_excluded` setting from the Include checkboxes. An exclusion the form does not offer (its plugin
	 * is off) is kept, and an unchanged save gives the stored list back byte for byte.
	 *
	 * @param array<int, string> $included The ticked entries.
	 * @param array<int, string> $stored   The stored exclusions.
	 * @param array<int, string> $offered  The entries the form offers.
	 * @return list<string>
	 */
	public static function exclusions( array $included, array $stored, array $offered ): array {
		$out = [];

		foreach ( $stored as $entry ) {
			if ( ! in_array( $entry, $offered, true ) || ! in_array( $entry, $included, true ) ) {
				$out[] = $entry;
			}
		}

		foreach ( $offered as $entry ) {
			if ( ! in_array( $entry, $included, true ) ) {
				$out[] = $entry;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a source is in scope: its type is listed and the filter agrees.
	 *
	 * @param \WP_Post             $source The source.
	 * @param array<string, mixed> $args   The run's facts.
	 * @return bool
	 */
	public static function includes( \WP_Post $source, array $args = [] ): bool {
		if ( ! in_array( $source->post_type, self::object_types( $args ), true ) ) {
			return false;
		}

		/**
		 * Filters whether one source is resynced in a run (for example a "do not resync" flag on the post).
		 *
		 * @hook perflocale/resync/include_object
		 * @since 1.0.8
		 *
		 * @param bool                 $include Whether the source is in scope.
		 * @param \WP_Post             $source  The source.
		 * @param array<string, mixed> $args    The run: trigger, language_ids, user_id.
		 */
		return (bool) apply_filters( 'perflocale/resync/include_object', true, $source, $args );
	}

	/**
	 * Whether custom fields are in scope for a run.
	 *
	 * @param array<string, mixed> $args The run's facts.
	 * @return bool
	 */
	public static function fields_included( array $args = [] ): bool {
		return in_array( self::CUSTOM_FIELDS, self::object_types( $args ), true );
	}

	/**
	 * Forget the lists of this request.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = [];
	}
}
