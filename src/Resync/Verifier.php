<?php
/**
 * Verification of a composition before any write.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\MachineTranslation\BlockStructureGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a {@see Composition} against its own plan: rescanned and resegmented, the content must show the planned block
 * tree and unit texts. A text holding a comment opener is refused before a slot (it could inject block structure). Any
 * failure is a `compose_error`: the pair is skipped and nothing is written.
 */
final class Verifier {

	/** The composed content is not a supported document, or its text could not be read (a tokenizer failure, a selector over its budget). */
	public const ERROR_UNSUPPORTED = 'unsupported_output';
	/** The composed block tree differs from the plan. */
	public const ERROR_TREE = 'block_tree_differs';
	/** The composed units differ from the plan. */
	public const ERROR_UNITS = 'units_differ';
	/** The composed content has more damaged block markup than its source and translation. */
	public const ERROR_STRUCTURE = 'structure';

	/** The kinds of BlockStructureGuard::damage() read without a source. */
	private const DAMAGE_KINDS = [ 'escaped', 'text', 'unbalanced' ];

	/**
	 * Whether a text may be written into a unit slot: no comment opener.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function accepts_text( string $text ): bool {
		return ! str_contains( $text, '<!--' );
	}

	/**
	 * Whether a text may be written into a slot inside an HTML comment (the raw `<!--more ...-->` mirror): no opener, no
	 * `-->` and no `--!>` (a browser ends a comment there too).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function accepts_comment_text( string $text ): bool {
		return self::accepts_text( $text ) && ! str_contains( $text, '-->' ) && ! str_contains( $text, '--!>' );
	}

	/**
	 * Whether a text may be written into a unit's slots: accepts_comment_text() for a comment mirror, else accepts_text().
	 *
	 * @param Unit   $unit The unit whose slots get the text.
	 * @param string $text Text.
	 * @return bool
	 */
	public static function accepts_for( Unit $unit, string $text ): bool {
		return null !== $unit->mirror && Unit::MIRROR_COMMENT === $unit->mirror['kind'] ? self::accepts_comment_text( $text ) : self::accepts_text( $text );
	}

	/**
	 * Verify a composition.
	 *
	 * @param Composition          $composition     The composition.
	 * @param array<string, mixed> $segment_options The segmenter options the engine used.
	 * @param callable|null        $reference       The reference parse for the scanner's parity guard.
	 * @return array{errors: list<string>, segmentation: Segmentation|null} The errors (none when the composition holds) and the output's segmentation.
	 * @phpstan-param (callable(string): array<mixed>)|null $reference
	 */
	public static function verify( Composition $composition, array $segment_options = [], ?callable $reference = null ): array {
		$tree = BlockScanner::scan( $composition->content, $reference );

		if ( ! $tree->supported ) {
			return [
				'errors'       => [ self::ERROR_UNSUPPORTED ],
				'segmentation' => null,
			];
		}

		$segmentation = Segmenter::segment( $tree, $segment_options );

		if ( $segmentation->error !== '' ) {
			return [
				'errors'       => [ self::ERROR_UNSUPPORTED ],
				'segmentation' => null,
			];
		}

		$errors = [];

		if ( self::blocks( $tree->nodes, 0 ) !== $composition->blocks ) {
			$errors[] = self::ERROR_TREE;
		}

		$texts = [];

		foreach ( $segmentation->units as $unit ) {
			$texts[] = $unit->text;
		}

		if ( $texts !== $composition->units ) {
			$errors[] = self::ERROR_UNITS;
		}

		return [
			'errors'       => $errors,
			'segmentation' => $segmentation,
		];
	}

	/**
	 * Whether a composed content has more damaged block markup of a kind than the source and the translation have
	 * ({@see BlockStructureGuard::damage()}): the belt for a composer bug. The inputs are read only when the output
	 * shows damage.
	 *
	 * @param string $output      The composed content.
	 * @param string $source      The source content.
	 * @param string $translation The translation content.
	 * @return bool
	 */
	public static function adds_damage( string $output, string $source, string $translation ): bool {
		$out = BlockStructureGuard::damage( $output );

		if ( 0 === $out['escaped'] + $out['text'] + $out['unbalanced'] ) {
			return false;
		}

		$src = BlockStructureGuard::damage( $source );
		$was = BlockStructureGuard::damage( $translation );

		foreach ( self::DAMAGE_KINDS as $kind ) {
			if ( $out[ $kind ] > max( $src[ $kind ], $was[ $kind ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Depth and name of every block in document order, walked with an explicit stack: each block visited once, no rows copied.
	 *
	 * @param array<int, BlockNode> $nodes Nodes.
	 * @param int                   $depth Depth of the nodes.
	 * @return list<array{int, string}>
	 * @phpstan-param list<BlockNode> $nodes
	 */
	public static function blocks( array $nodes, int $depth ): array {
		$out   = [];
		$stack = [];

		for ( $i = count( $nodes ) - 1; $i >= 0; $i-- ) {
			$stack[] = [ $nodes[ $i ], $depth ];
		}

		while ( [] !== $stack ) {
			[ $node, $level ] = array_pop( $stack );

			if ( $node->name === null ) {
				continue;
			}

			$out[] = [ $level, $node->name ];

			for ( $i = count( $node->children ) - 1; $i >= 0; $i-- ) {
				$stack[] = [ $node->children[ $i ], $level + 1 ];
			}
		}

		return $out;
	}
}
