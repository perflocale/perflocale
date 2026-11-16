<?php
/**
 * A scanned block document.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of {@see BlockScanner::scan()}: the document, whether the scanner supports it, and its top-level nodes;
 * an unsupported document (`$reason`: a BlockScanner::UNSUPPORTED_* value) has no nodes and gets no proposal.
 */
final class BlockTree {

	/**
	 * Constructor.
	 *
	 * @param string                $document  The scanned bytes.
	 * @param bool                  $supported Whether both scanner guards hold.
	 * @param string                $reason    Why the document is unsupported; '' when it is.
	 * @param array<int, BlockNode> $nodes Top-level nodes in document order; [] when unsupported or empty.
	 * @phpstan-param list<BlockNode> $nodes
	 */
	public function __construct(
		public readonly string $document,
		public readonly bool $supported,
		public readonly string $reason,
		public readonly array $nodes
	) {}

	/**
	 * The bytes of a range.
	 *
	 * @param array{int, int} $range Start offset and end offset (exclusive).
	 * @return string
	 */
	public function slice( array $range ): string {
		return substr( $this->document, $range[0], $range[1] - $range[0] );
	}
}
