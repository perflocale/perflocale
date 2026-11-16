<?php
/**
 * Marked override names of synced patterns.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockSkipFilter;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `metadata.name` values of a synced pattern's blocks marked "Do not translate" in the pattern or its translation:
 * their override values in a host post are never units. Asked once per pattern reference, answered once per request.
 */
final class PatternMarks {

	/**
	 * Names per blog, pattern id and language; null for a pattern too deep to read.
	 *
	 * @var array<string, list<string>|null>
	 */
	private static array $memo = [];

	/**
	 * The segmenter's resolver for a language.
	 *
	 * @param int $language_id The translation's language.
	 * @return callable(int): (list<string>|null)
	 */
	public static function resolver( int $language_id ): callable {
		return static fn ( int $ref ): ?array => self::names( $ref, $language_id );
	}

	/**
	 * The marked names of a pattern and of its translation in a language; null when either nests deeper than the scanner
	 * reads ({@see BlockScanner::UNSUPPORTED_DEPTH}), so its marks are unknown.
	 *
	 * @param int $ref         The pattern post id.
	 * @param int $language_id The language.
	 * @return list<string>|null
	 */
	public static function names( int $ref, int $language_id ): ?array {
		$key = get_current_blog_id() . ':' . $ref . ':' . $language_id;

		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		$names   = [];
		$pattern = $ref > 0 ? get_post( $ref ) : null;

		if ( $pattern instanceof \WP_Post ) {
			$names = self::names_in( $pattern->post_content );
			$ids   = null === $names ? [] : self::group( $ref );

			foreach ( $ids as $link ) {
				$object_id = Scalar::int( Scalar::prop( $link, 'object_id' ) );

				if ( Scalar::int( Scalar::prop( $link, 'language_id' ) ) !== $language_id || $object_id === $ref || $object_id <= 0 ) {
					continue;
				}

				$translation = get_post( $object_id );

				if ( $translation instanceof \WP_Post ) {
					$more = self::names_in( $translation->post_content );

					if ( null === $more ) {
						$names = null;
						break;
					}

					$names = array_merge( $names, $more );
				}
			}
		}

		self::$memo[ $key ] = null === $names ? null : array_values( array_unique( $names ) );

		return self::$memo[ $key ];
	}

	/**
	 * The `metadata.name` values of every block that is skip-marked or inside one: none for an unsupported document, null
	 * for one that nests deeper than the scanner reads.
	 *
	 * @param string $content Block content.
	 * @return list<string>|null
	 */
	public static function names_in( string $content ): ?array {
		$tree = BlockScanner::scan( $content );

		if ( ! $tree->supported ) {
			return BlockScanner::UNSUPPORTED_DEPTH === $tree->reason ? null : [];
		}

		$out = [];

		foreach ( $tree->nodes as $node ) {
			self::collect( $node, false, $out );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Walk a subtree, collecting names under a marked block.
	 *
	 * @param BlockNode          $node   Node.
	 * @param bool               $marked Whether an ancestor is marked.
	 * @param array<int, string> $out    Names, appended.
	 * @return void
	 */
	private static function collect( BlockNode $node, bool $marked, array &$out ): void {
		$attrs  = is_array( $node->attrs ) ? $node->attrs : [];
		$marked = $marked || ! empty( $attrs[ BlockSkipFilter::SKIP_ATTRIBUTE ] );

		if ( $marked && is_array( $attrs['metadata'] ?? null ) && is_string( $attrs['metadata']['name'] ?? null ) && '' !== $attrs['metadata']['name'] ) {
			$out[] = $attrs['metadata']['name'];
		}

		foreach ( $node->children as $child ) {
			self::collect( $child, $marked, $out );
		}
	}

	/**
	 * Forget the names of this request.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memo = [];
	}

	/**
	 * The link rows of a pattern's group, read as a run reads groups ({@see GroupLinks}).
	 *
	 * @param int $ref The pattern post id.
	 * @return list<object>
	 */
	private static function group( int $ref ): array {
		$plugin = \PerfLocale\Plugin::get_instance();

		return ( new GroupLinks( $plugin->cache(), $plugin->group_repo() ) )->links( $ref );
	}
}
