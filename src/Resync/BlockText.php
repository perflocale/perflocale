<?php
/**
 * The text attributes a resync segments, per block type.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Translation\BlockAttributeSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the attributes whose values are text units: {@see BlockAttributeSource::chain()} behind a map for the blocks it
 * misreads, plus comment-JSON text attributes (built-in list, `role: content` schema text outside core, the filter).
 * Names the chain already holds are left out, so an attribute is one unit, never two.
 */
final class BlockText {

	/** Filter: `string[] $names, string $block_name`. */
	public const FILTER_TEXT_ATTRIBUTES = 'perflocale/resync/block_text_attributes';

	/**
	 * The resync map: block name => text attribute names, overriding BlockAttributeSource::chain() for these blocks.
	 *
	 * @var array<string, list<string>>
	 */
	private const RESYNC_CHAIN = [
		'core/list'       => [],
		'core/media-text' => [ 'mediaAlt' ],
		'core/cover'      => [ 'alt' ],
		'core/more'       => [ 'customText' ],
		'core/gallery'    => [ 'caption', 'images' ],
	];

	/**
	 * Comment attributes holding text, with no source in core's schemas (checked against core 7.1.2 block.json files).
	 *
	 * @var array<string, list<string>>
	 */
	private const COMMENT_ATTRIBUTES = [
		'core/navigation-link'              => [ 'label', 'title', 'description' ],
		'core/navigation-submenu'           => [ 'label', 'title', 'description' ],
		'core/home-link'                    => [ 'label' ],
		'core/search'                       => [ 'label', 'placeholder', 'buttonText' ],
		'core/post-excerpt'                 => [ 'moreText' ],
		'core/post-navigation-link'         => [ 'label' ],
		'core/post-terms'                   => [ 'prefix', 'suffix' ],
		'core/social-link'                  => [ 'label' ],
		'core/categories'                   => [ 'label' ],
		'core/more'                         => [ 'customText' ],
		'core/query-pagination-next'        => [ 'label' ],
		'core/query-pagination-previous'    => [ 'label' ],
		'core/comments-pagination-next'     => [ 'label' ],
		'core/comments-pagination-previous' => [ 'label' ],
		'core/cover'                        => [ 'alt' ],
		'core/tab-panel'                    => [ 'label' ],
		'core/read-more'                    => [ 'content' ],
	];

	/** Attribute names accepted from the schema and the filter. */
	private const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

	/** Most names taken for one block type (the same bound as BlockAttributeSource::read()). */
	private const MAX_NAMES = 32;

	/**
	 * The text attribute names of a block type, in priority order.
	 *
	 * @param string $block_name Block name, e.g. `core/image`.
	 * @return list<string>
	 */
	public static function chain( string $block_name ): array {
		if ( isset( self::RESYNC_CHAIN[ $block_name ] ) ) {
			return self::RESYNC_CHAIN[ $block_name ];
		}

		return array_values( BlockAttributeSource::chain( $block_name ) );
	}

	/**
	 * The comment-JSON attributes of a block type whose string values are text, without the names the chain names.
	 *
	 * @param string $block_name Block name.
	 * @return list<string>
	 */
	public static function comment_attributes( string $block_name ): array {
		if ( $block_name === '' ) {
			return [];
		}

		$names = self::COMMENT_ATTRIBUTES[ $block_name ] ?? [];

		// Core's role: content attributes without a source are the list above; its others (url, rel, linkTarget, ...) are not text.
		if ( ! str_starts_with( $block_name, 'core/' ) ) {
			foreach ( BlockAttributeSource::schema( $block_name ) as $name => $def ) {
				if ( is_string( $name ) && is_array( $def ) && self::is_content_attribute( $name, $def ) ) {
					$names[] = $name;
				}
			}
		}

		/**
		 * Filters the comment-JSON attributes of a block type whose string values Content ReSync treats as text. A non-array
		 * keeps the default; non-strings, chain names, names other than a letter plus up to 63 letters, digits, `_` or `-`,
		 * and non-text names (class, id, url, style, ...) are dropped; at most 32 are kept.
		 *
		 * @hook perflocale/resync/block_text_attributes
		 * @since 1.0.8
		 *
		 * @param list<string> $names      Attribute names: PerfLocale's list for the block, plus, for a block outside
		 *                                 core, its registered content attributes without a source.
		 * @param string       $block_name Block name, e.g. `core/image`.
		 */
		/**
		 * A filter may return anything; only an array of names is taken.
		 *
		 * @var mixed $filtered
		 */
		$filtered = apply_filters( 'perflocale/resync/block_text_attributes', $names, $block_name );
		$chain    = self::chain( $block_name );
		$out      = [];

		foreach ( is_array( $filtered ) ? $filtered : $names as $name ) {
			if ( ! is_string( $name ) || in_array( $name, $out, true ) || in_array( $name, $chain, true ) || preg_match( self::NAME_PATTERN, $name ) !== 1 || preg_match( BlockAttributeSource::IGNORE_NAMES, $name ) === 1 ) {
				continue;
			}

			$out[] = $name;

			if ( count( $out ) >= self::MAX_NAMES ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Whether a schema attribute is `role: content` text kept in the comment.
	 *
	 * @param string       $name Attribute name.
	 * @param array<mixed> $def  Attribute schema.
	 * @return bool
	 */
	private static function is_content_attribute( string $name, array $def ): bool {
		return ( $def['role'] ?? '' ) === 'content'
			&& in_array( $def['type'] ?? '', [ 'string', 'rich-text' ], true )
			&& ! isset( $def['source'] )
			&& preg_match( BlockAttributeSource::IGNORE_NAMES, $name ) !== 1;
	}
}
