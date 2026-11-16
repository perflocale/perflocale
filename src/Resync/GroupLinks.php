<?php
/**
 * A post's translation group as ReSync reads it.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Database\Repository\TranslationGroupRepository;
use PerfLocale\Enum\ObjectType;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The group read get_translations() makes, minus its persistent fill: a run that changes nothing writes nothing.
 * Transients are skipped, as core deletes an expired one on read; the answer is kept for this request only.
 */
final class GroupLinks {

	/** The cache group and key prefix of TranslationGroupRepository::get_translations(), so both share one L1 entry. */
	private const GROUP = 'perflocale_trans';

	/**
	 * A reader over the given cache and repository.
	 *
	 * @param CacheManager               $cache The plugin's cache.
	 * @param TranslationGroupRepository $repo  The group repository.
	 */
	public function __construct( private CacheManager $cache, private TranslationGroupRepository $repo ) {
	}

	/**
	 * Language slug => post id of a post's group, from the plugin's services.
	 *
	 * @param int $post_id Any post of the group.
	 * @return array<string, int>
	 */
	public static function of( int $post_id ): array {
		$plugin = \PerfLocale\Plugin::get_instance();

		return ( new self( $plugin->cache(), $plugin->group_repo() ) )->map( $post_id );
	}

	/**
	 * Language slug => post id of a post's group ([] when it has none or the read failed).
	 *
	 * @param int $post_id Any post of the group.
	 * @return array<string, int>
	 */
	public function map( int $post_id ): array {
		$map = [];

		foreach ( $this->links( $post_id ) as $link ) {
			$slug = Scalar::str( Scalar::prop( $link, 'language_slug' ) );

			if ( '' !== $slug ) {
				$map[ $slug ] = Scalar::int( Scalar::prop( $link, 'object_id' ) );
			}
		}

		return $map;
	}

	/**
	 * The group's link rows, as get_translations() returns them.
	 *
	 * @param int $post_id Any post of the group.
	 * @return list<object>
	 */
	public function links( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		$key = 'translations_post_' . $post_id;

		// L1, and L2 only when it is a persistent object cache: reads that never write.
		$cached = wp_using_ext_object_cache() ? $this->cache->get_cached( $key, self::GROUP ) : $this->cache->get_static( $key, self::GROUP );

		if ( is_array( $cached ) ) {
			return array_values( array_filter( $cached, 'is_object' ) );
		}

		// The eager map, null while a run suspends it, over the cap, or with no links.
		$eager = $this->repo->get_eager_link_map( ObjectType::Post );

		if ( is_array( $eager ) ) {
			$links = is_array( $eager[ $post_id ] ?? null ) ? $eager[ $post_id ] : [];
		} else {
			global $wpdb;

			$links = $this->repo->get_translations_for_objects( [ $post_id ], ObjectType::Post )[ $post_id ] ?? [];

			// A failed read is no group: not kept, so nothing later in this request takes it as one.
			if ( $wpdb instanceof \wpdb && '' !== $wpdb->last_error ) {
				return [];
			}
		}

		$links = array_values( array_filter( $links, 'is_object' ) );
		$this->cache->set_static( $key, $links, self::GROUP );

		return $links;
	}
}
