<?php
/**
 * Core URL bases provider.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases\Providers;

use PerfLocale\Contract\UrlBasesProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Group `core`: every translatable public post type and taxonomy with a rewrite slug (single, archive, term bases).
 *
 * @internal
 */
final class Core implements UrlBasesProvider {

	/**
	 * Post types whose single URL has no base of its own.
	 */
	private const NO_BASE_TYPES = [ 'post', 'page', 'attachment' ];

	/**
	 * Core keys of these types.
	 *
	 * @param list<string> $post_types Translatable post types.
	 * @param list<string> $taxonomies Translatable taxonomies.
	 */
	public function __construct( private readonly array $post_types, private readonly array $taxonomies ) {
	}

	/** Group id. */
	public function group(): string {
		return 'core';
	}

	/** Group label. */
	public function label(): string {
		return __( 'Content', 'perflocale' );
	}

	/**
	 * Keys with their labels.
	 *
	 * @return array<string, string>
	 */
	public function keys(): array {
		$keys = [];

		foreach ( $this->post_types as $type ) {
			$obj = get_post_type_object( $type );

			if ( ! $obj instanceof \WP_Post_Type || in_array( $obj->name, self::NO_BASE_TYPES, true ) || ! is_array( $obj->rewrite ) || '' === self::slug( $obj->rewrite ) || ! $obj->public ) {
				continue;
			}

			$name                       = self::label_of( $obj->labels, $obj->name );
			$keys[ 'pt:' . $obj->name ] = $name;

			if ( null !== self::archive_slug( $obj ) ) {
				/* translators: %s: post type name, e.g. "Event" */
				$keys[ 'pta:' . $obj->name ] = sprintf( __( '%s archive', 'perflocale' ), $name );
			}
		}

		foreach ( $this->taxonomies as $tax ) {
			$obj = get_taxonomy( $tax );

			if ( ! $obj instanceof \WP_Taxonomy || ! is_array( $obj->rewrite ) || '' === self::slug( $obj->rewrite ) || ! $obj->public ) {
				continue;
			}

			$keys[ 'tx:' . $obj->name ] = self::label_of( $obj->labels, $obj->name );
		}

		return $keys;
	}

	/**
	 * No inputs beyond the core options the compiler always reads.
	 *
	 * @return string[]
	 */
	public function inputs(): array {
		return [];
	}

	/**
	 * No shared namespaces.
	 *
	 * @return string[]
	 */
	public function shared(): array {
		return [];
	}

	/**
	 * The slug of a registered rewrite argument, without slashes at the ends; '' when none.
	 *
	 * @param array<mixed> $rewrite Registered rewrite argument.
	 * @return string
	 */
	public static function slug( array $rewrite ): string {
		return is_string( $rewrite['slug'] ?? null ) ? trim( $rewrite['slug'], '/' ) : '';
	}

	/**
	 * The archive slug of a post type when it differs from its single slug, else null.
	 *
	 * @param \WP_Post_Type $obj Post type.
	 * @return string|null
	 */
	public static function archive_slug( \WP_Post_Type $obj ): ?string {
		if ( ! $obj->has_archive || ! is_array( $obj->rewrite ) ) {
			return null;
		}

		$single  = self::slug( $obj->rewrite );
		$archive = is_string( $obj->has_archive ) ? trim( $obj->has_archive, '/' ) : $single;

		return ( '' !== $single && '' !== $archive && $archive !== $single ) ? $archive : null;
	}

	/**
	 * The singular label of a type, or its name.
	 *
	 * @param mixed  $labels Labels object.
	 * @param string $name   Type name.
	 * @return string
	 */
	private static function label_of( mixed $labels, string $name ): string {
		$label = is_object( $labels ) && isset( $labels->singular_name ) && is_string( $labels->singular_name ) ? $labels->singular_name : '';

		return '' !== $label ? $label : $name;
	}
}
