<?php
/**
 * The SEO-exclusion set behind Helper::seo_excluded_post_ids(), loaded on first use.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the flagged post ids once per blog and request; a flag write in the request drops them.
 */
final class SeoExclusion {

	/**
	 * Per-blog memo of the flagged post id set.
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $memo = [];

	/**
	 * Whether the meta-write listeners are attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Body of {@see Helper::seo_excluded_post_ids()}.
	 *
	 * One query per blog: sitemaps do not meta-prime siblings, so get_post_meta() would query each.
	 *
	 * @return array<int, true>
	 */
	public static function ids(): array {
		$blog_id = get_current_blog_id();

		if ( isset( self::$memo[ $blog_id ] ) ) {
			return self::$memo[ $blog_id ];
		}

		// A flag write later in this request (REST, CLI, an import) drops the set; attached by the first read only.
		if ( ! self::$hooked ) {
			foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
				add_action( $hook, [ self::class, 'forget' ], 10, 3 );
			}

			self::$hooked = true;
		}

		global $wpdb;

		/** @var \wpdb $wpdb */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single indexed lookup, memoised per request; WP has no batch "all posts with this meta" API short of WP_Query overhead.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = 'yes'",
				$wpdb->postmeta,
				Helper::SEO_EXCLUDE_META
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$set = [];

		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) ) {
				$set[ (int) $id ] = true;
			}
		}

		self::$memo[ $blog_id ] = $set;

		return $set;
	}

	/**
	 * Post meta write listener: drop the sets when the flag changed.
	 *
	 * @param mixed $meta_ids  Meta id(s).
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public static function forget( $meta_ids, $object_id, $meta_key ): void {
		unset( $meta_ids, $object_id );

		if ( $meta_key === Helper::SEO_EXCLUDE_META ) {
			self::$memo = [];
		}
	}

	/**
	 * Drop the sets (Helper::reset_static_caches()).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = [];
	}
}
