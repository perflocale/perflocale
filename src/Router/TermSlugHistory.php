<?php
/**
 * Old term URL slugs: the history hit of the main request.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cold: the resolver loads it only when a requested term slug matched a history row (term_h1..term_h5).
 *
 * @since 1.0.8
 */
final class TermSlugHistory {

	/**
	 * Query vars naming a singular object; a term query var beside one is part of a permalink, not an archive route.
	 */
	private const SINGULAR = [ 'p', 'page_id', 'name', 'pagename', 'attachment', 'attachment_id', 'subpost', 'subpost_id' ];

	/**
	 * Accepted hits of this request by term id, the last one last.
	 *
	 * @var array<int, array{term_id: int, slug: string, taxonomy: string}>
	 */
	private static array $hits = [];

	/**
	 * Accept a history match of the main request on a term archive route of its taxonomy.
	 *
	 * @param \WP    $wp       Environment being parsed.
	 * @param int    $term_id  Term the history row belongs to.
	 * @param string $slug     Requested slug.
	 * @param string $taxonomy Taxonomy of the query var.
	 * @return bool False: the row is ignored (the request routes as without history).
	 */
	public static function record( \WP $wp, int $term_id, string $slug, string $taxonomy ): bool {
		if ( $wp !== ( $GLOBALS['wp'] ?? null ) ) {
			return false;
		}

		$vars = $wp->query_vars;

		foreach ( self::SINGULAR as $var ) {
			if ( ! empty( $vars[ $var ] ) ) {
				return false;
			}
		}

		// A product_cat inside a product URL comes with the product post type's query var.
		foreach ( get_post_types( [], 'objects' ) as $type ) {
			if ( is_string( $type->query_var ) && '' !== $type->query_var && ! empty( $vars[ $type->query_var ] ) ) {
				return false;
			}
		}

		unset( self::$hits[ $term_id ] );
		self::$hits[ $term_id ] = [
			'term_id'  => $term_id,
			'slug'     => $slug,
			'taxonomy' => $taxonomy,
		];

		return true;
	}

	/**
	 * The last history hit of this request, handed to rule #9.
	 *
	 * @api Frozen contract (1.0.8 step 1, 9.1).
	 * @return array{term_id: int, slug: string, taxonomy: string}|null
	 */
	public static function hit(): ?array {
		return self::$hits === [] ? null : self::$hits[ array_key_last( self::$hits ) ];
	}

	/**
	 * Whether a term was reached by one of its old slugs in this request.
	 *
	 * @param int $term_id Term.
	 * @return bool
	 */
	public static function has( int $term_id ): bool {
		return isset( self::$hits[ $term_id ] );
	}

	/**
	 * Forget the hit (tests and long CLI processes).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$hits = [];
	}

	/**
	 * Whether a live term of the taxonomy owns the slug as its DB slug in this language (a history row then yields).
	 *
	 * Reached: linked in this language or unlinked, and no display row in this language. A failed check answers true.
	 *
	 * @param string $slug Slug from the URL.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $language_id Current language ID.
	 * @return bool
	 */
	public static function shadowed( string $slug, string $taxonomy, int $language_id ): bool {
		$wpdb = \PerfLocale\Admin\Typed::wpdb();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from Schema::table() are safe.
		// A failed read refuses the history row, as a failed resolver query does.
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM %i n
				INNER JOIN %i nt ON nt.term_id = n.term_id AND nt.taxonomy = %s
				LEFT JOIN %i l ON l.type = 'term' AND l.object_id = n.term_id
				LEFT JOIN %i c ON c.object_type = 'term' AND c.object_id = n.term_id AND c.language_id = %d
				WHERE n.slug = %s AND ( l.id IS NULL OR l.language_id = %d ) AND c.id IS NULL
				LIMIT 1",
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$taxonomy,
				\PerfLocale\Database\Schema::table( 'translation_links' ),
				\PerfLocale\Database\Schema::table( 'slug_translations' ),
				$language_id,
				$slug,
				$language_id
			)
		) || $wpdb->last_error;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
