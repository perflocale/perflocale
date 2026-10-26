<?php
/**
 * PerfLocale Meta Box addon: password sub-fields of copied and seeded group rows.
 *
 * Loaded only by PerfLocaleMetabox when a translation is created or a source save seeds a group row.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the password sub-fields of Meta Box groups out of a translation's copied and seeded rows.
 */
final class PerfLocaleMetaboxPasswords {

	/**
	 * Build it with the addon's field lookups.
	 *
	 * @param \Closure(string): array<int|string, mixed> $fields_for        The Meta Box fields of a post type.
	 * @param \Closure(array<string, mixed>): bool        $post_meta_storage Whether a field stores post meta.
	 */
	public function __construct( private \Closure $fields_for, private \Closure $post_meta_storage ) {}

	/**
	 * `perflocale/translation/created`: take password sub-fields out of the new translation's copied groups.
	 *
	 * A group is one meta row holding all its sub-fields, so exclude_password_fields() cannot drop one;
	 * only copied rows that hold a password sub-field are rewritten.
	 *
	 * @param int|mixed    $new_id New translation's ID.
	 * @param string|mixed $type   Object type, 'post' or 'term'.
	 * @return void
	 */
	public function drop_group_password_values( mixed $new_id = 0, mixed $type = '' ): void {
		if ( 'post' !== $type || ! is_numeric( $new_id ) || (int) $new_id <= 0 || ! function_exists( 'rwmb_get_object_fields' ) ) {
			return;
		}

		$post_id   = (int) $new_id;
		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) ) {
			return;
		}

		foreach ( ( $this->fields_for )( $post_type ) as $field ) {
			if ( ! is_array( $field ) || 'group' !== ( $field['type'] ?? '' ) || empty( $field['id'] ) || ! ( $this->post_meta_storage )( $field ) || ! self::has_password_sub_field( $field ) ) {
				continue;
			}

			$key = (string) $field['id'];

			foreach ( (array) get_post_meta( $post_id, $key, false ) as $row ) {
				$kept = self::without_password_sub_fields( $row, $field );

				if ( $kept !== $row ) {
					// The previous value picks the row; the new one is slashed
					// because update_post_meta() unslashes it.
					update_post_meta( $post_id, $key, \PerfLocale\Helper::deep_slash( $kept ), $row );
				}
			}
		}
	}

	/**
	 * `perflocale/sync/seed_meta_value`: a seeded Meta Box group row is copied without its password sub-fields.
	 *
	 * A translation with no row for a group gets the source's rows on the source's next save.
	 *
	 * @param mixed $value     Row as read from the source.
	 * @param mixed $key       Meta key.
	 * @param mixed $target_id Translation that receives the row.
	 * @return mixed
	 */
	public function seed_without_password_sub_fields( mixed $value, mixed $key = '', mixed $target_id = 0 ): mixed {
		if ( ! is_array( $value ) || ! is_string( $key ) || '' === $key || ! is_numeric( $target_id ) || ! function_exists( 'rwmb_get_object_fields' ) ) {
			return $value;
		}

		$post_type = get_post_type( (int) $target_id );

		if ( ! is_string( $post_type ) ) {
			return $value;
		}

		foreach ( ( $this->fields_for )( $post_type ) as $field ) {
			if ( is_array( $field ) && 'group' === ( $field['type'] ?? '' ) && $key === (string) ( $field['id'] ?? '' ) && ( $this->post_meta_storage )( $field ) && self::has_password_sub_field( $field ) ) {
				return self::without_password_sub_fields( $value, $field );
			}
		}

		return $value;
	}

	/**
	 * Whether a group field has a password sub-field, at any depth.
	 *
	 * @param array<string, mixed> $group Group field settings.
	 * @return bool
	 */
	private static function has_password_sub_field( array $group ): bool {
		foreach ( (array) ( $group['fields'] ?? [] ) as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}

			if ( 'password' === ( $sub['type'] ?? '' ) || ( 'group' === ( $sub['type'] ?? '' ) && self::has_password_sub_field( $sub ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A group's stored value without its password sub-fields.
	 *
	 * A list is the group's clones, each a value of its own; a map holds one
	 * value per sub-field ID. Nested groups are walked the same way.
	 *
	 * @param mixed                $value Stored group value.
	 * @param array<string, mixed> $group Group field settings.
	 * @return mixed
	 */
	private static function without_password_sub_fields( mixed $value, array $group ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		// A list of clones; not array_is_list(), which Plugin Check maps to WP 6.5.
		if ( $value === array_values( $value ) ) {
			foreach ( $value as $index => $clone ) {
				$value[ $index ] = self::without_password_sub_fields( $clone, $group );
			}

			return $value;
		}

		foreach ( (array) ( $group['fields'] ?? [] ) as $sub ) {
			$sub_id = is_array( $sub ) ? (string) ( $sub['id'] ?? '' ) : '';

			if ( $sub_id === '' || ! array_key_exists( $sub_id, $value ) ) {
				continue;
			}

			if ( 'password' === ( $sub['type'] ?? '' ) ) {
				unset( $value[ $sub_id ] );
			} elseif ( 'group' === ( $sub['type'] ?? '' ) ) {
				$value[ $sub_id ] = self::without_password_sub_fields( $value[ $sub_id ], $sub );
			}
		}

		return $value;
	}
}
