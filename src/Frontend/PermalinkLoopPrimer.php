<?php
/**
 * Loop priming for perflocale_get_permalink().
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Enum\ObjectType;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, per query, blog and language, the links, target posts and slugs of the posts a WP_Query returned, BATCH at a
 * time, so each perflocale_get_permalink() call in its loop hits memory. Loaded on the first call; it only warms caches.
 * Starts at the second post asked for: one link (a widget's, a header's) must not read the whole query.
 */
final class PermalinkLoopPrimer {

	/** Posts one priming reads at most: two links asked from a huge query read one window, not the query. */
	private const BATCH = 100;

	/**
	 * The query of the last `the_post`, tracked from the first call on.
	 *
	 * @var \WP_Query|null
	 */
	private static ?\WP_Query $query = null;

	/**
	 * Per query: its post ids in order, id => position and, per "blog:language", the position first asked for, then
	 * skip links over primed positions (forward, backward).
	 *
	 * @var \WeakMap<\WP_Query, array{order: array<int, int>, pos: array<int, int>, done: array<string, int|array{0: array<int, int>, 1: array<int, int>}>}>|null
	 */
	private static ?\WeakMap $seen = null;

	/**
	 * Prime the loop $post belongs to (the tracked query, else the main one) at its second post asked for.
	 *
	 * @param \WP_Post $post      Source post.
	 * @param string   $lang_slug Target language slug.
	 * @return void
	 */
	public static function prime( \WP_Post $post, string $lang_slug ): void {
		if ( self::$seen === null ) {
			self::$seen = new \WeakMap();
			add_action( 'the_post', [ self::class, 'track' ], 10, 2 );
		}

		$seen = self::$seen;
		$key  = get_current_blog_id() . ':' . $lang_slug;

		foreach ( [ self::$query, $GLOBALS['wp_query'] ?? null ] as $query ) {
			// post_count, not posts: posts is null before the query runs.
			if ( ! $query instanceof \WP_Query || $query->post_count < 2 ) {
				continue;
			}

			if ( ! isset( $seen[ $query ] ) ) {
				// 'ids' queries hold ints; 'id=>parent' ones stdClass rows.
				$order = array_values( array_diff( wp_parse_id_list( 'ids' === $query->get( 'fields' ) ? $query->posts : wp_list_pluck( $query->posts, 'ID' ) ), [ 0 ] ) );

				$seen[ $query ] = [
					'order' => $order,
					'pos'   => array_flip( $order ),
					'done'  => [],
				];
			}

			$pos = $seen[ $query ]['pos'][ $post->ID ] ?? null;

			if ( $pos === null ) {
				continue;
			}

			$done = $seen[ $query ]['done'][ $key ] ?? null;

			if ( $done === null ) {
				$seen[ $query ]['done'][ $key ] = $pos;
			} elseif ( is_int( $done ) ? $done !== $pos : ! isset( $done[0][ $pos ] ) ) {
				// In place, with no other copy held: copying the links each window would make a long loop quadratic.
				unset( $done );
				$skip = &$seen[ $query ]['done'][ $key ];

				if ( is_int( $skip ) ) {
					$skip = [ [ $skip => $skip + 1 ], [ $skip => $skip - 1 ] ];
				}

				$window = self::window( $seen[ $query ]['order'], $pos, $skip );
				unset( $skip );

				self::prime_batch( $window, $lang_slug, $query );
			}

			return;
		}
	}

	/**
	 * Track the query whose loop is running; hooked to `the_post` from the first call on.
	 *
	 * @param mixed $post  Post (unused).
	 * @param mixed $query The query.
	 * @return void
	 */
	public static function track( $post, $query = null ): void {
		unset( $post );

		if ( $query instanceof \WP_Query ) {
			self::$query = $query;
		}
	}

	/**
	 * Up to BATCH unprimed ids from $pos on, topped up from before it, so a loop in any order costs one read per BATCH.
	 *
	 * @param array<int, int>                               $order Post ids of the query, in order.
	 * @param int                                           $pos   Position of the post asked for.
	 * @param array{0: array<int, int>, 1: array<int, int>} $skip  Skip links over primed positions; the window's are added.
	 * @return array<int, int>
	 */
	private static function window( array $order, int $pos, array &$skip ): array {
		$window = [];

		foreach ( [ [ 0, $pos, 1 ], [ 1, $pos - 1, -1 ] ] as [ $side, $at, $step ] ) {
			while ( count( $window ) < self::BATCH ) {
				$at = self::unprimed( $skip[ $side ], $at );

				if ( ! isset( $order[ $at ] ) ) {
					break;
				}

				$window[]       = $order[ $at ];
				$skip[0][ $at ] = $at + 1;
				$skip[1][ $at ] = $at - 1;
				$at            += $step;
			}
		}

		return $window;
	}

	/**
	 * First unprimed position from $at on in $links' direction; the links passed are pointed at it.
	 *
	 * @param array<int, int> $links Primed position => next position to try.
	 * @param int             $at    Position.
	 * @return int
	 */
	private static function unprimed( array &$links, int $at ): int {
		$end = $at;

		while ( isset( $links[ $end ] ) ) {
			$end = $links[ $end ];
		}

		while ( $at !== $end ) {
			$next         = $links[ $at ];
			$links[ $at ] = $end;
			$at           = $next;
		}

		return $end;
	}

	/**
	 * One batched read each: links of $ids, their $lang_slug members' posts, the slugs of both.
	 *
	 * @param array<int, int> $ids       Post ids of the query.
	 * @param string          $lang_slug Target language slug.
	 * @param \WP_Query       $query     The query (preload_slugs() signature).
	 * @return void
	 */
	private static function prime_batch( array $ids, string $lang_slug, \WP_Query $query ): void {
		$plugin = Plugin::get_instance();
		$groups = $plugin->group_repo();
		$groups->prime_translations( ObjectType::Post, $ids );
		$targets = [];

		foreach ( $ids as $id ) {
			// Language slug => member id; one member per language in a group.
			$target = wp_list_pluck( $groups->get_translations( $id, ObjectType::Post ), 'object_id', 'language_slug' )[ $lang_slug ] ?? 0;

			if ( is_numeric( $target ) && (int) $target > 0 && (int) $target !== $id ) {
				$targets[] = (int) $target;
			}
		}

		if ( $targets === [] ) {
			return;
		}

		// The query's own posts too: an 'ids' query left them unread, and a suppress_filters one their slugs.
		$all = array_merge( $ids, $targets );
		_prime_post_caches( $all, false, false );

		// The setting gates the batched slug read as it gates SlugManager's the_posts filter.
		if ( $plugin->has( Plugin::SERVICE_SLUG_MGR ) && $plugin->settings()->get( 'cache_preload_slugs', true ) ) {
			$posts = array_filter( array_map( 'get_post', $all ), static fn( $p ): bool => $p instanceof \WP_Post );
			$plugin->slug_manager()->preload_slugs( array_values( $posts ), $query );
		}
	}
}
