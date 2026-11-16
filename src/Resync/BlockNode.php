<?php
/**
 * One node of a scanned block document.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A block, or a run of top-level freeform HTML, as `[start, end)` byte ranges of its document: opener, own chunks
 * alternating with children (n + 1 chunks for n children), closer; a void block has only its opener, a freeform node
 * one chunk. A supported tree's ranges tile the document; the composer copies them and never re-serializes.
 */
final class BlockNode {

	/**
	 * Constructor.
	 *
	 * @param string|null                 $name       Block name with its namespace (`core/paragraph`); null for freeform HTML.
	 * @param int                         $start      Byte offset of the node's first byte.
	 * @param int                         $end        Byte offset after the node's last byte.
	 * @param array{int, int}|null        $open       Opener delimiter range; null for freeform.
	 * @param array{int, int}|null        $close      Closer delimiter range; null for freeform and for a void block.
	 * @param array{int, int}|null        $attrs_json Range of the `{...}` attribute JSON inside the opener; null when the opener has none.
	 * @param array<mixed>|null           $attrs      Attributes decoded associatively, as core's parser decodes them: [] without JSON, null when the JSON is invalid.
	 * @param bool                        $is_void    Whether the block is a void block (`/-->`).
	 * @param array<int, array{int, int}> $chunks Own HTML ranges, one more than the children; empty ranges included.
	 * @param array<int, BlockNode>       $children  Inner blocks, in document order.
	 * @param BlockPath                   $path       Child indexes from the document root, over every node (top-level freeform nodes count), as a chain shared with the ancestors.
	 * @phpstan-param list<array{int, int}> $chunks
	 * @phpstan-param list<BlockNode> $children
	 */
	public function __construct(
		public readonly ?string $name,
		public readonly int $start,
		public readonly int $end,
		public readonly ?array $open,
		public readonly ?array $close,
		public readonly ?array $attrs_json,
		public readonly ?array $attrs,
		public readonly bool $is_void,
		public readonly array $chunks,
		public readonly array $children,
		public readonly BlockPath $path
	) {}

	/**
	 * Whether the node is freeform HTML rather than a block.
	 *
	 * @return bool
	 */
	public function is_freeform(): bool {
		return $this->name === null;
	}

	/**
	 * The node's whole range.
	 *
	 * @return array{int, int}
	 */
	public function range(): array {
		return [ $this->start, $this->end ];
	}
}
