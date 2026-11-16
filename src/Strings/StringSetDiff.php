<?php
/**
 * The pairing step of a string-scope sync.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Strings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pairs a scope's old rows with its new members per context, without a database: exact matches kept;
 * then edits (aligner renames, equal positions, similarity within the cell budget); the rest new or removed.
 * An incomplete enumeration makes every pair a copy and removes nothing: an unreached member may hold the old text.
 *
 * @phpstan-type OldRow array{id: int, context: string, text: string, position: string, row: int}
 * @phpstan-type Member array{text: string, position: string, row: int}
 * @phpstan-type Kept array{id: int, position: string, row: int, changed: bool}
 * @phpstan-type Edit array{old_id: int, context: string, old_text: string, text: string, position: string, row: int, copy: bool}
 * @phpstan-type Insert array{context: string, text: string, position: string, row: int}
 * @phpstan-type Diff array{kept: list<Kept>, edits: list<Edit>, inserts: list<Insert>, removed: list<int>, leftover: list<int>, collapsed: list<int>}
 */
final class StringSetDiff {

	/**
	 * Most `|R| * |A|` cells the similarity pass compares in one context.
	 */
	public const SIMILARITY_CELLS = 2500;

	/**
	 * Pair the old rows of a scope with its new members.
	 *
	 * @param array<int, array<string, mixed>>                $old      Old rows: id, context, text, position, row.
	 * @param array<string, array<int, array<string, mixed>>> $sets     New members per context: text, position, row.
	 * @param bool                                            $complete Whether the caller enumerated the whole scope.
	 * @param array<string, array<string, string>>            $renames  Per context, old text => new text proved to be an edit.
	 * @return array<string, mixed>
	 * @phpstan-param list<OldRow> $old
	 * @phpstan-param array<string, list<Member>> $sets
	 * @phpstan-return Diff
	 */
	public static function diff( array $old, array $sets, bool $complete, array $renames = [] ): array {
		$kept      = [];
		$edits     = [];
		$inserts   = [];
		$leftover  = [];
		$collapsed = [];

		// (row, id) order: every tie below goes to the lower line number.
		$old_by_context = [];

		foreach ( $old as $row ) {
			$old_by_context[ (string) $row['context'] ][] = $row;
		}

		foreach ( $old_by_context as &$rows ) {
			usort( $rows, static fn( array $a, array $b ): int => [ $a['row'], $a['id'] ] <=> [ $b['row'], $b['id'] ] );
		}
		unset( $rows );

		$contexts = array_unique( array_merge( array_keys( $old_by_context ), array_keys( $sets ) ) );

		foreach ( $contexts as $context ) {
			$context = (string) $context;
			$rows    = $old_by_context[ $context ] ?? [];
			$dropped = [];

			// Identical texts share one row; the first occurrence is its position memory.
			$members = [];

			foreach ( $sets[ $context ] ?? [] as $member ) {
				$text = (string) $member['text'];

				if ( ! isset( $members[ $text ] ) ) {
					$members[ $text ] = [
						'text'     => $text,
						'position' => (string) $member['position'],
						'row'      => (int) $member['row'],
					];
				}
			}

			// 1. Exact matches.
			$all_old = [];
			$r       = [];

			foreach ( $rows as $row ) {
				$text = (string) $row['text'];

				$all_old[ $text ] ??= $row;

				if ( isset( $members[ $text ] ) ) {
					$member = $members[ $text ];
					$kept[] = [
						'id'       => (int) $row['id'],
						'position' => $member['position'],
						'row'      => $member['row'],
						'changed'  => $member['position'] !== (string) $row['position'] || $member['row'] !== (int) $row['row'],
					];
					unset( $members[ $text ] );
					continue;
				}

				$r[] = $row;
			}

			$a = $members;

			// 2a. The aligner's renames.
			$targets = [];

			foreach ( $renames[ $context ] ?? [] as $old_text => $new_text ) {
				$old_text = (string) $old_text;
				$new_text = (string) $new_text;

				if ( ! isset( $a[ $new_text ] ) || ! isset( $all_old[ $old_text ] ) ) {
					continue;
				}

				$targets[ $new_text ][] = $old_text;
			}

			// R indexed by text once. An old text keys one proof per context, so no earlier proof took its row.
			$r_by_text = [];

			if ( $targets !== [] ) {
				foreach ( $r as $index => $row ) {
					$r_by_text[ (string) $row['text'] ] ??= $index;
				}
			}

			foreach ( $targets as $new_text => $old_texts ) {
				$new_text = (string) $new_text;
				$movers   = [];
				$keepers  = [];

				foreach ( $old_texts as $old_text ) {
					$index = $r_by_text[ $old_text ] ?? null;

					if ( null !== $index ) {
						$movers[] = $index;
					} else {
						$keepers[] = $all_old[ $old_text ];
					}
				}

				if ( $movers !== [] ) {
					// R is in (row, id) order: the first mover has the lower line number.
					sort( $movers );
					$winner = array_shift( $movers );

					$edits[] = self::edit( $r[ $winner ], $context, $a[ $new_text ], false );

					// Losers go with the removals, never to another member.
					foreach ( $movers as $loser ) {
						$collapsed[] = (int) $r[ $loser ]['id'];
						$dropped[]   = (int) $r[ $loser ]['id'];
						unset( $r[ $loser ] );
					}

					unset( $r[ $winner ], $a[ $new_text ] );
					continue;
				}

				usort( $keepers, static fn( array $x, array $y ): int => [ $x['row'], $x['id'] ] <=> [ $y['row'], $y['id'] ] );

				$edits[] = self::edit( $keepers[0], $context, $a[ $new_text ], true );
				unset( $a[ $new_text ] );
			}

			$r = array_values( $r );

			// 2b. Equal positions.
			if ( $r !== [] && $a !== [] ) {
				$by_position = [];

				foreach ( $a as $text => $member ) {
					if ( '' !== $member['position'] ) {
						$by_position[ $member['position'] ] ??= (string) $text;
					}
				}

				foreach ( $r as $index => $row ) {
					$position = (string) $row['position'];

					if ( '' === $position || ! isset( $by_position[ $position ] ) ) {
						continue;
					}

					$text    = $by_position[ $position ];
					$edits[] = self::edit( $row, $context, $a[ $text ], false );
					unset( $r[ $index ], $a[ $text ], $by_position[ $position ] );
				}

				$r = array_values( $r );
			}

			// 2c. Similarity, within the cell budget; each text prepared once.
			if ( $r !== [] && $a !== [] && count( $r ) * count( $a ) <= self::SIMILARITY_CELLS ) {
				$candidates = [];
				$a_texts    = array_keys( $a );
				$prepared_a = [];

				foreach ( $a_texts as $position => $text ) {
					$prepared_a[ $position ] = TextSimilarity::prepare( (string) $text );
				}

				foreach ( $r as $index => $row ) {
					$prepared_r = TextSimilarity::prepare( (string) $row['text'] );

					foreach ( $prepared_a as $position => $prepared ) {
						$score = TextSimilarity::score_prepared( $prepared_r, $prepared );

						if ( $score >= TextSimilarity::EDIT_THRESHOLD ) {
							$candidates[] = [ $score, $index, $position ];
						}
					}
				}

				// Best score first; ties by the old row's order, then the member's.
				usort( $candidates, static fn( array $x, array $y ): int => [ $y[0], $x[1], $x[2] ] <=> [ $x[0], $y[1], $y[2] ] );

				$used_r = [];
				$used_a = [];

				foreach ( $candidates as [ $score, $index, $position ] ) {
					if ( isset( $used_r[ $index ] ) || isset( $used_a[ $position ] ) ) {
						continue;
					}

					$text    = (string) $a_texts[ $position ];
					$edits[] = self::edit( $r[ $index ], $context, $a[ $text ], false );

					$used_r[ $index ]    = true;
					$used_a[ $position ] = true;
					unset( $a[ $text ] );
				}

				foreach ( array_keys( $used_r ) as $index ) {
					unset( $r[ $index ] );
				}

				$r = array_values( $r );
			}

			// 3. New and removed.
			foreach ( $a as $member ) {
				$inserts[] = [
					'context'  => $context,
					'text'     => $member['text'],
					'position' => $member['position'],
					'row'      => $member['row'],
				];
			}

			foreach ( $r as $row ) {
				$leftover[] = (int) $row['id'];
			}

			foreach ( $dropped as $id ) {
				$leftover[] = $id;
			}
		}

		// An incomplete enumeration proves no old text gone.
		if ( ! $complete ) {
			foreach ( $edits as &$edit ) {
				$edit['copy'] = true;
			}
			unset( $edit );
		}

		return [
			'kept'      => $kept,
			'edits'     => $edits,
			'inserts'   => $inserts,
			'removed'   => $complete ? $leftover : [],
			'leftover'  => $leftover,
			'collapsed' => $collapsed,
		];
	}

	/**
	 * One edit pair.
	 *
	 * @param array<string, mixed> $row     Old row.
	 * @param string               $context Context.
	 * @param array<string, mixed> $member  New member.
	 * @param bool                 $copy    Whether the old row stays and its translations are copied.
	 * @return array<string, mixed>
	 * @phpstan-param OldRow $row
	 * @phpstan-param Member $member
	 * @phpstan-return Edit
	 */
	private static function edit( array $row, string $context, array $member, bool $copy ): array {
		return [
			'old_id'   => (int) $row['id'],
			'context'  => $context,
			'old_text' => (string) $row['text'],
			'text'     => $member['text'],
			'position' => $member['position'],
			'row'      => $member['row'],
			'copy'     => $copy,
		];
	}
}
