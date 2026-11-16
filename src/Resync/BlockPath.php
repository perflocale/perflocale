<?php
/**
 * The position of a node in its tree.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A node's child indexes from the root as a chain (each path links to its parent's), so a tree holds one small object
 * per node whatever its depth: a list per node would cost d²/2 integers (over a GB for a 460 KB document 10,000 deep).
 * Top-level freeform nodes take an index too, so this is not `BlockAttributeSource::path_is_skip_marked()`'s path.
 */
final class BlockPath {

	/**
	 * The parent node's path; null at the top level.
	 *
	 * @var BlockPath|null
	 */
	public readonly ?BlockPath $parent;

	/**
	 * Index among the siblings: the tree's top-level nodes, or the parent's children.
	 *
	 * @var int
	 */
	public readonly int $index;

	/**
	 * Constructor.
	 *
	 * @param BlockPath|null $parent_path The parent node's path; null at the top level.
	 * @param int            $index       Index among the siblings.
	 */
	public function __construct( ?BlockPath $parent_path, int $index ) {
		$this->parent = $parent_path;
		$this->index  = $index;
	}

	/**
	 * The child indexes from the document root.
	 *
	 * @return array<int, int>
	 * @phpstan-return list<int>
	 */
	public function indexes(): array {
		$out = [];

		for ( $path = $this; $path !== null; $path = $path->parent ) {
			$out[] = $path->index;
		}

		return array_reverse( $out );
	}
}
