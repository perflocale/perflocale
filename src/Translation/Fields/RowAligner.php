<?php
/**
 * Row alignment for repeaters, flexible content and cloneable groups.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

use PerfLocale\Resync\Keys;
use PerfLocale\Strings\TextSimilarity;
use PerfLocale\Util\Lcs;
use PerfLocale\Util\WorkBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row aligner for structural sync, the MT row check, ReSync's rename map and option-text renames.
 * A row is `['layout' => ?string, 'sig' => list]` of text, value and nested `rows` leaves in definition order.
 * Guessed or refused pairings are flagged ({@see Alignment}), so a consumer defers instead of acting on them.
 */
final class RowAligner {

	/** Most rows on either side. */
	public const ROW_CAP = 1000;

	/** Most table cells (rows times rows). */
	public const CELL_BUDGET = 1000000;

	/**
	 * Most tokens one gap's texts may hold on both sides (the similarity table keeps them all while it runs).
	 * `perflocale/resync/work_budget` may raise it ({@see WorkBudget::ROW_TOKENS}).
	 */
	public const TOKEN_BUDGET = 500000;

	/** Refusals: the row cap or the cell budget, the memory headroom, the token budget. */
	public const REFUSED_CAP    = 'cap';
	public const REFUSED_MEMORY = 'memory';
	public const REFUSED_TOKENS = 'tokens';

	/** Memory the similarity tables leave unused under the limit. */
	private const MEMORY_RESERVE = 16777216;

	/** Least bytes the prepared tokens of a text take per byte: refuses a gap before any text is prepared. */
	private const PREPARED_BYTES_PER_BYTE = 16;

	/** Bytes of text prepared between two memory headroom checks. */
	private const MEMORY_CHECK_BYTES = 65536;

	/**
	 * A text leaf.
	 *
	 * @param string $text   Text.
	 * @param string $id     Concrete key or context the derived outputs report.
	 * @param string $format `text` or `html`.
	 * @return array{text: string, id: string, format: string}
	 */
	public static function text( string $text, string $id = '', string $format = FieldClass::FORMAT_TEXT ): array {
		return [
			'text'   => $text,
			'id'     => $id,
			'format' => $format,
		];
	}

	/**
	 * A non-text leaf (a reference in canonical form, a plain value).
	 *
	 * @param mixed  $value Value.
	 * @param string $id    Concrete key.
	 * @return array{value: mixed, id: string}
	 */
	public static function value( mixed $value, string $id = '' ): array {
		return [
			'value' => $value,
			'id'    => $id,
		];
	}

	/**
	 * A nested row list.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @param string                           $id   The container's concrete key.
	 * @return array{rows: array<int, array<string, mixed>>, id: string}
	 */
	public static function rows( array $rows, string $id = '' ): array {
		return [
			'rows' => $rows,
			'id'   => $id,
		];
	}

	/**
	 * A row.
	 *
	 * @param array<int, array<string, mixed>> $sig    Leaves in definition order.
	 * @param string|null                      $layout Flexible content layout, or null.
	 * @return array{layout: string|null, sig: array<int, array<string, mixed>>}
	 */
	public static function row( array $sig, ?string $layout = null ): array {
		return [
			'layout' => $layout,
			'sig'    => $sig,
		];
	}

	/**
	 * Pair the rows of one container before and after a save.
	 *
	 * @param array<int, array<string, mixed>> $before Rows before.
	 * @param array<int, array<string, mixed>> $after Rows after.
	 * @param int                              $cap Most rows on either side.
	 * @return Alignment
	 */
	public static function align( array $before, array $after, int $cap = self::ROW_CAP ): Alignment {
		$before = array_values( $before );
		$after  = array_values( $after );
		$n      = count( $before );
		$m      = count( $after );

		if ( $n > $cap || $m > $cap || $n * $m > self::CELL_BUDGET ) {
			return self::refused( $n, $m, self::REFUSED_CAP );
		}

		$hash_old = array_map( [ self::class, 'hash' ], $before );
		$hash_new = array_map( [ self::class, 'hash' ], $after );

		// Step 1: exact anchors (LCS of row hashes), then equal rows that crossed it, in order per hash.
		$intern  = [];
		$tok_old = [];
		$tok_new = [];

		foreach ( $hash_old as $h ) {
			$tok_old[] = $intern[ $h ] ??= count( $intern );
		}

		foreach ( $hash_new as $h ) {
			$tok_new[] = $intern[ $h ] ??= count( $intern );
		}

		$pair_old = [];
		$pair_new = [];

		foreach ( Lcs::pairs( $tok_old, $tok_new, self::CELL_BUDGET ) ?? [] as [ $i, $j ] ) {
			$pair_old[ $i ] = $j;
			$pair_new[ $j ] = $i;
		}

		[ $left_old, $left_new ] = self::leftovers( $n, $m, $pair_old, $pair_new );

		foreach ( self::in_order( $left_old, $left_new, $hash_old, $hash_new ) as [ $i, $j ] ) {
			$pair_old[ $i ] = $j;
			$pair_new[ $j ] = $i;
		}

		// A hash with different counts on the two sides was paired by guess.
		$guessed_hash = [];
		$count_old    = array_count_values( $hash_old );
		$count_new    = array_count_values( $hash_new );

		foreach ( $count_old as $h => $count ) {
			if ( isset( $count_new[ $h ] ) && $count_new[ $h ] !== $count ) {
				$guessed_hash[ $h ] = true;
			}
		}

		$guessed = [];

		foreach ( $pair_new as $j => $i ) {
			if ( isset( $guessed_hash[ $hash_new[ $j ] ] ) ) {
				$guessed[ $j ] = true;
			}
		}

		// Step 2, per gap between ordered anchors: equal non-text leaves in order, then text similarity.
		$chain   = Lcs::monotone( $pair_old );
		$chain[] = [ $n, $m ];
		$from_i  = 0;
		$from_j  = 0;

		foreach ( $chain as [ $to_i, $to_j ] ) {
			$gap_old = [];
			$gap_new = [];

			for ( $i = $from_i; $i < $to_i; $i++ ) {
				if ( ! isset( $pair_old[ $i ] ) ) {
					$gap_old[] = $i;
				}
			}

			for ( $j = $from_j; $j < $to_j; $j++ ) {
				if ( ! isset( $pair_new[ $j ] ) ) {
					$gap_new[] = $j;
				}
			}

			$from_i = $to_i + 1;
			$from_j = $to_j + 1;

			if ( $gap_old === [] || $gap_new === [] ) {
				continue;
			}

			self::pair_by_non_text( $before, $after, $gap_old, $gap_new, $pair_old, $pair_new, $guessed );

			[ $gap_old, $gap_new ] = self::unpaired( $gap_old, $gap_new, $pair_old, $pair_new );

			if ( $gap_old !== [] && $gap_new !== [] ) {
				$refused = self::pair_by_similarity( $before, $after, $gap_old, $gap_new, $pair_old, $pair_new );

				if ( $refused !== '' ) {
					return self::refused( $n, $m, $refused );
				}
			}
		}

		$map     = [];
		$removed = [];
		$moves   = [];

		for ( $j = 0; $j < $m; $j++ ) {
			$map[] = $pair_new[ $j ] ?? null;

			if ( isset( $pair_new[ $j ] ) && $pair_new[ $j ] !== $j ) {
				$moves[] = [ $pair_new[ $j ], $j ];
			}
		}

		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! isset( $pair_old[ $i ] ) ) {
				$removed[] = $i;
			}
		}

		$guessed = array_keys( $guessed );
		sort( $guessed );

		return new Alignment( $map, $removed, $guessed !== [], $moves, $guessed );
	}

	/**
	 * The translation's row for each source row, by the evidence that survives translation (values, layout,
	 * untranslated text). Several candidates pair nothing unless one is the row's own index; above the cap, identity.
	 *
	 * @param array<int, array<string, mixed>> $b_rows Source rows.
	 * @param array<int, array<string, mixed>> $t_rows Translation rows.
	 * @param int                              $cap    Most rows on either side.
	 * @return array<int, int|null> Translation index per source index.
	 */
	public static function evidence( array $b_rows, array $t_rows, int $cap = self::ROW_CAP ): array {
		$b_rows = array_values( $b_rows );
		$t_rows = array_values( $t_rows );
		$n      = count( $b_rows );
		$m      = count( $t_rows );

		if ( $n > $cap || $m > $cap || $n * $m > self::CELL_BUDGET ) {
			$identity = [];

			for ( $j = 0; $j < $n; $j++ ) {
				$identity[] = $j < $m ? $j : null;
			}

			return $identity;
		}

		// Source rows per (path, value): the rows a translation leaf names.
		$holders = [];

		foreach ( $b_rows as $j => $row ) {
			foreach ( self::flatten( $row ) as $path => $value ) {
				if ( ! self::is_empty_leaf( $value ) ) {
					$holders[ $path ][ serialize( $value ) ][] = $j; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A comparison key, never stored.
				}
			}
		}

		$result = array_fill( 0, $n, null );
		$taken  = [];

		foreach ( $t_rows as $t_index => $t_row ) {
			$supports = [];
			$named    = 0;

			foreach ( self::flatten( $t_row ) as $path => $value ) {
				if ( self::is_empty_leaf( $value ) ) {
					continue;
				}

				$rows = $holders[ $path ][ serialize( $value ) ] ?? []; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A comparison key, never stored.

				if ( $rows === [] ) {
					continue;
				}

				++$named;

				foreach ( $rows as $j ) {
					$supports[ $j ] = ( $supports[ $j ] ?? 0 ) + 1;
				}
			}

			// A candidate is supported by every leaf that names a row.
			$candidates = [];

			for ( $j = 0; $j < $n; $j++ ) {
				if ( $named > 0 && ( $supports[ $j ] ?? 0 ) === $named ) {
					$candidates[] = $j;
				}
			}

			$evidence = $named > 0;

			if ( ! $evidence ) {
				// No leaf names any source row: the row stays at its index.
				if ( $t_index < $n && ! isset( $taken[ $t_index ] ) && self::layout_of( $b_rows[ $t_index ] ) === self::layout_of( $t_row ) ) {
					$result[ $t_index ] = $t_index;
					$taken[ $t_index ]  = true;
				}

				continue;
			}

			// Several candidates (not including its own index): no pairing.
			$choice = null;

			if ( in_array( $t_index, $candidates, true ) ) {
				$choice = $t_index;
			} elseif ( count( $candidates ) === 1 ) {
				$choice = $candidates[0];
			}

			if ( null !== $choice && isset( $taken[ $choice ] ) ) {
				$choice = null;
			}

			if ( null !== $choice ) {
				$result[ $choice ] = $t_index;
				$taken[ $choice ]  = true;
			}
		}

		return $result;
	}

	/**
	 * Text leaves of surviving rows whose text changed: `[ id => [ old text => new text ] ]`.
	 * Guessed pairs rename nothing.
	 *
	 * @param array<int, array<string, mixed>> $before       Rows before.
	 * @param array<int, array<string, mixed>> $after       Rows after.
	 * @param Alignment|null                   $alignment Their alignment, when already made.
	 * @return array<string, array<string, string>>
	 */
	public static function text_renames( array $before, array $after, ?Alignment $alignment = null ): array {
		$before = array_values( $before );
		$after  = array_values( $after );
		$out    = [];

		self::collect_text_renames( $before, $after, $alignment ?? self::align( $before, $after ), $out );

		return $out;
	}

	/**
	 * The leaf ids that moved (old id => new id), nested rows included.
	 *
	 * @param array<int, array<string, mixed>> $before       Rows before.
	 * @param array<int, array<string, mixed>> $after       Rows after.
	 * @param Alignment|null                   $alignment Their alignment, when already made.
	 * @return array<string, string>
	 */
	public static function key_renames( array $before, array $after, ?Alignment $alignment = null ): array {
		$before = array_values( $before );
		$after  = array_values( $after );
		$out    = [];

		self::collect_key_renames( $before, $after, $alignment ?? self::align( $before, $after ), $out );

		return $out;
	}

	/**
	 * The concrete keys that moved between two field sets of one object: old key => new key.
	 *
	 * @param FieldMap             $map      The object's map.
	 * @param array<string, mixed> $fields_b Key => value before.
	 * @param array<string, mixed> $fields_n Key => value after.
	 * @return array<string, string>
	 */
	public static function rename_map( FieldMap $map, array $fields_b, array $fields_n ): array {
		$out = [];

		foreach ( $map->keys() as $key ) {
			$descriptor = $map->descriptors_for( $key )[0];

			if ( FieldClass::STRUCTURE !== $descriptor->class || $descriptor->chain !== [] ) {
				continue;
			}

			$rows_b = self::rows_of( $map, $key, static fn( string $k ): mixed => $fields_b[ $k ] ?? null );
			$rows_n = self::rows_of( $map, $key, static fn( string $k ): mixed => $fields_n[ $k ] ?? null );

			$out += self::key_renames( $rows_b, $rows_n );
		}

		return $out;
	}

	/**
	 * The rows of one container from the map's templates and a value reader. Meta Box structures have no rows:
	 * their blob key never moves.
	 *
	 * @param FieldMap $map       The object's map.
	 * @param string   $container Concrete key of the container.
	 * @param callable $read      `callable(string $key): mixed`, the stored value of a concrete key, null when absent.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-param callable(string): mixed $read
	 */
	public static function rows_of( FieldMap $map, string $container, callable $read ): array {
		$descriptors = $map->descriptors_for( $container );

		if ( $descriptors === [] || FieldClass::STRUCTURE !== $descriptors[0]->class ) {
			return [];
		}

		$children = [];

		return self::build_rows( $map, $container, $descriptors[0]->template, $descriptors[0]->type, count( $descriptors[0]->chain ), $read, $children );
	}

	/**
	 * Build the rows of one container. A stored repeater count is clamped at ROW_CAP + 1 so a corrupt count
	 * cannot spin (above the cap the aligners answer the flagged identity).
	 *
	 * @param FieldMap                                   $map       Map.
	 * @param string                                     $container Concrete container key.
	 * @param string                                     $template  The container's template key.
	 * @param string                                     $type      The container's host type.
	 * @param int                                        $depth     Rows enclosing the container (its chain's length).
	 * @param callable                                   $read      Value reader.
	 * @param array<string, array<int, FieldDescriptor>> $children  Children per template prefix, filled on first use.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-param callable(string): mixed $read
	 */
	private static function build_rows( FieldMap $map, string $container, string $template, string $type, int $depth, callable $read, array &$children ): array {
		$stored  = $read( $container );
		$layouts = [];

		if ( 'flexible_content' === $type ) {
			if ( ! is_array( $stored ) ) {
				return [];
			}

			foreach ( $stored as $layout ) {
				if ( is_string( $layout ) ) {
					$layouts[] = $layout;
				}
			}
		} else {
			$count = is_numeric( $stored ) ? min( (int) $stored, self::ROW_CAP + 1 ) : 0;

			for ( $i = 0; $i < $count; $i++ ) {
				$layouts[] = null;
			}
		}

		if ( $layouts === [] ) {
			return [];
		}

		$template_prefix                = $template . '_0_';
		$children[ $template_prefix ] ??= self::children( $map, $template_prefix, $depth + 1 );
		$rows                           = [];

		foreach ( $layouts as $i => $layout ) {
			$prefix = $container . '_' . $i . '_';
			$sig    = [];

			foreach ( $children[ $template_prefix ] as $child ) {
				$child_layout = $child->chain[ count( $child->chain ) - 1 ][2];

				if ( null !== $layout && $child_layout !== $layout ) {
					continue;
				}

				$key = $prefix . substr( $child->template, strlen( $template_prefix ) );

				if ( FieldClass::STRUCTURE === $child->class ) {
					$sig[] = self::rows( self::build_rows( $map, $key, $child->template, $child->type, $depth + 1, $read, $children ), $key );
				} elseif ( FieldClass::TEXT === $child->class ) {
					$value = $read( $key );
					$sig[] = self::text( is_scalar( $value ) ? (string) $value : '', $key, $child->format );
				} elseif ( FieldClass::PASSWORD !== $child->class && FieldClass::IGNORE !== $child->class ) {
					$sig[] = self::value( $read( $key ), $key );
				}
			}

			$rows[] = self::row( $sig, $layout );
		}

		return $rows;
	}

	/**
	 * The direct children of a container, one per (template key, layout), in producer order.
	 * Matched by template prefix and chain depth only: a container name may hold digits (`step_1_items`).
	 *
	 * @param FieldMap $map    Map.
	 * @param string   $prefix The container's template key followed by `_0_`.
	 * @param int      $depth  The chain length of a direct child.
	 * @return array<int, FieldDescriptor>
	 */
	private static function children( FieldMap $map, string $prefix, int $depth ): array {
		$out  = [];
		$seen = [];

		foreach ( $map->descriptors() as $d ) {
			if ( count( $d->chain ) !== $depth || ! str_starts_with( $d->template, $prefix ) ) {
				continue;
			}

			$last = $d->chain[ $depth - 1 ];
			$id   = $d->template . '|' . ( $last[2] ?? '' );

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$out[]       = $d;
		}

		return $out;
	}

	/**
	 * Rows of one layout whose non-text leaves are all equal pair in order; a run of several is a guess.
	 *
	 * @param array<int, array<string, mixed>> $before      Rows before.
	 * @param array<int, array<string, mixed>> $after      Rows after.
	 * @param array<int, int>                  $gap_old  Unpaired old indices of the gap.
	 * @param array<int, int>                  $gap_new  Unpaired new indices of the gap.
	 * @param array<int, int>                  $pair_old New index per old index, added to.
	 * @param array<int, int>                  $pair_new Old index per new index, added to.
	 * @param array<int, true>                 $guessed  Guessed new indices, added to.
	 * @return void
	 */
	private static function pair_by_non_text( array $before, array $after, array $gap_old, array $gap_new, array &$pair_old, array &$pair_new, array &$guessed ): void {
		$coarse_old = [];
		$coarse_new = [];
		$list_old   = [];
		$list_new   = [];

		foreach ( $gap_old as $i ) {
			if ( self::has_non_text( $before[ $i ] ) ) {
				$coarse_old[ $i ] = self::hash( $before[ $i ], true );
				$list_old[]       = $i;
			}
		}

		foreach ( $gap_new as $j ) {
			if ( self::has_non_text( $after[ $j ] ) ) {
				$coarse_new[ $j ] = self::hash( $after[ $j ], true );
				$list_new[]       = $j;
			}
		}

		if ( $list_old === [] || $list_new === [] ) {
			return;
		}

		$count_old = array_count_values( $coarse_old );
		$count_new = array_count_values( $coarse_new );

		foreach ( self::in_order( $list_old, $list_new, $coarse_old, $coarse_new ) as [ $i, $j ] ) {
			$pair_old[ $i ] = $j;
			$pair_new[ $j ] = $i;

			$h = $coarse_new[ $j ];

			if ( $count_old[ $h ] > 1 || $count_new[ $h ] > 1 ) {
				$guessed[ $j ] = true;
			}
		}
	}

	/**
	 * Rows of one layout with texts similar at the shared threshold pair order-preserving, best total first.
	 * Refused (pairs nothing) when the gap's tokens exceed the token budget or would leave less than
	 * MEMORY_RESERVE under the memory limit; checked per MEMORY_CHECK_BYTES of text and before the table.
	 *
	 * @param array<int, array<string, mixed>> $before      Rows before.
	 * @param array<int, array<string, mixed>> $after      Rows after.
	 * @param array<int, int>                  $gap_old  Unpaired old indices of the gap.
	 * @param array<int, int>                  $gap_new  Unpaired new indices of the gap.
	 * @param array<int, int>                  $pair_old New index per old index, added to.
	 * @param array<int, int>                  $pair_new Old index per new index, added to.
	 * @return string '' when the gap was paired; {@see self::REFUSED_MEMORY} or {@see self::REFUSED_TOKENS} when it was refused.
	 * @phpstan-param list<int> $gap_old
	 * @phpstan-param list<int> $gap_new
	 */
	private static function pair_by_similarity( array $before, array $after, array $gap_old, array $gap_new, array &$pair_old, array &$pair_new ): string {
		$text_old   = [];
		$text_new   = [];
		$layout_old = [];
		$layout_new = [];
		$tokens     = 0;
		$budget     = self::TOKEN_BUDGET;
		$pending    = 0;
		$limit      = false;

		foreach ( $gap_old as $i ) {
			$layout_old[ $i ] = self::layout_of( $before[ $i ] );
			$text             = self::text_of( $before[ $i ] );
			$pending         += strlen( $text );

			if ( $pending >= self::MEMORY_CHECK_BYTES ) {
				$limit = false === $limit ? WorkBudget::memory_limit() : $limit;

				if ( ! self::within_memory( $limit, $pending * self::PREPARED_BYTES_PER_BYTE ) ) {
					return self::REFUSED_MEMORY;
				}

				$pending = 0;
			}

			$text_old[ $i ] = TextSimilarity::prepare( $text );
			$tokens        += $text_old[ $i ]['length'];

			if ( $tokens > $budget ) {
				// The filter may raise the budget; read once.
				if ( $budget === self::TOKEN_BUDGET ) {
					$budget = WorkBudget::get( WorkBudget::ROW_TOKENS );
				}

				if ( $tokens > $budget ) {
					return self::REFUSED_TOKENS;
				}
			}
		}

		foreach ( $gap_new as $j ) {
			$layout_new[ $j ] = self::layout_of( $after[ $j ] );
			$text             = self::text_of( $after[ $j ] );
			$pending         += strlen( $text );

			if ( $pending >= self::MEMORY_CHECK_BYTES ) {
				$limit = false === $limit ? WorkBudget::memory_limit() : $limit;

				if ( ! self::within_memory( $limit, $pending * self::PREPARED_BYTES_PER_BYTE ) ) {
					return self::REFUSED_MEMORY;
				}

				$pending = 0;
			}

			$text_new[ $j ] = TextSimilarity::prepare( $text );
			$tokens        += $text_new[ $j ]['length'];

			if ( $tokens > $budget ) {
				// The filter may raise the budget; read once.
				if ( $budget === self::TOKEN_BUDGET ) {
					$budget = WorkBudget::get( WorkBudget::ROW_TOKENS );
				}

				if ( $tokens > $budget ) {
					return self::REFUSED_TOKENS;
				}
			}
		}

		// The table: a byte of traceback per cell within the cell budget, and two rows of scores.
		$count_old = count( $gap_old );
		$count_new = count( $gap_new );
		$table     = min( $count_old * $count_new, self::CELL_BUDGET ) + 2 * 64 * ( $count_new + 1 ) + 64 * $count_old;

		if ( $table >= self::MEMORY_CHECK_BYTES && ! self::within_memory( false === $limit ? WorkBudget::memory_limit() : $limit, $table ) ) {
			return self::REFUSED_MEMORY;
		}

		$weight = static function ( int $k, int $l ) use ( $gap_old, $gap_new, $text_old, $text_new, $layout_old, $layout_new ): float {
			$i = $gap_old[ $k ];
			$j = $gap_new[ $l ];

			if ( $layout_old[ $i ] !== $layout_new[ $j ] || '' === $text_old[ $i ]['text'] || '' === $text_new[ $j ]['text'] ) {
				return 0.0;
			}

			return TextSimilarity::score_prepared( $text_old[ $i ], $text_new[ $j ] );
		};

		$min   = (float) TextSimilarity::EDIT_THRESHOLD;
		$pairs = Lcs::weighted( $count_old, $count_new, $weight, $min, self::CELL_BUDGET )
			?? Lcs::banded( $count_old, $count_new, $weight, $min, self::CELL_BUDGET );

		foreach ( $pairs as [ $k, $l ] ) {
			$pair_old[ $gap_old[ $k ] ] = $gap_new[ $l ];
			$pair_new[ $gap_new[ $l ] ] = $gap_old[ $k ];
		}

		return '';
	}

	/**
	 * The flagged identity answered instead of a pairing, every pair a guess, the refusal named.
	 *
	 * @param int    $n      Old count.
	 * @param int    $m      New count.
	 * @param string $reason {@see self::REFUSED_CAP}, {@see self::REFUSED_MEMORY} or {@see self::REFUSED_TOKENS}.
	 * @return Alignment
	 */
	private static function refused( int $n, int $m, string $reason ): Alignment {
		$map     = [];
		$removed = [];
		$guessed = [];

		for ( $j = 0; $j < $m; $j++ ) {
			if ( $j < $n ) {
				$map[]     = $j;
				$guessed[] = $j;
			} else {
				$map[] = null;
			}
		}

		for ( $i = $m; $i < $n; $i++ ) {
			$removed[] = $i;
		}

		return new Alignment( $map, $removed, true, [], $guessed, $reason );
	}

	/**
	 * Whether an allocation leaves at least MEMORY_RESERVE under the memory limit (always when unbounded).
	 *
	 * @param int|null $limit The memory limit in bytes ({@see WorkBudget::memory_limit()}).
	 * @param int      $bytes The allocation.
	 * @return bool
	 */
	private static function within_memory( ?int $limit, int $bytes ): bool {
		return $limit === null || memory_get_usage( true ) + $bytes + self::MEMORY_RESERVE <= $limit;
	}

	/**
	 * Record the text renames of paired rows, nested rows included.
	 *
	 * @param array<int, array<string, mixed>>     $before       Rows before.
	 * @param array<int, array<string, mixed>>     $after       Rows after.
	 * @param Alignment                            $alignment Their alignment.
	 * @param array<string, array<string, string>> $out       Renames, added to.
	 * @return void
	 */
	private static function collect_text_renames( array $before, array $after, Alignment $alignment, array &$out ): void {
		foreach ( $alignment->map as $j => $i ) {
			if ( null === $i || $alignment->is_guessed( $j ) ) {
				continue;
			}

			$sig_old = self::sig_of( $before[ $i ] ?? [] );
			$sig_new = self::sig_of( $after[ $j ] ?? [] );

			foreach ( $sig_new as $p => $leaf ) {
				$other = $sig_old[ $p ] ?? null;

				if ( null === $other ) {
					continue;
				}

				if ( isset( $leaf['text'], $other['text'] ) && is_string( $leaf['text'] ) && is_string( $other['text'] ) ) {
					if ( Keys::norm( $leaf['text'], self::format_of( $leaf ) ) !== Keys::norm( $other['text'], self::format_of( $other ) ) ) {
						$id                           = self::id_of( $leaf );
						$out[ $id ][ $other['text'] ] = $leaf['text'];
					}
				} elseif ( isset( $leaf['rows'], $other['rows'] ) ) {
					$nested_old = self::rows_in( $other );
					$nested_new = self::rows_in( $leaf );
					self::collect_text_renames( $nested_old, $nested_new, self::align( $nested_old, $nested_new ), $out );
				}
			}
		}
	}

	/**
	 * Record the id renames of paired rows, nested rows included.
	 *
	 * @param array<int, array<string, mixed>> $before       Rows before.
	 * @param array<int, array<string, mixed>> $after       Rows after.
	 * @param Alignment                        $alignment Their alignment.
	 * @param array<string, string>            $out       Renames, added to.
	 * @return void
	 */
	private static function collect_key_renames( array $before, array $after, Alignment $alignment, array &$out ): void {
		if ( '' !== $alignment->refused ) {
			return;
		}

		foreach ( $alignment->map as $j => $i ) {
			if ( null === $i ) {
				continue;
			}

			$sig_old = self::sig_of( $before[ $i ] ?? [] );
			$sig_new = self::sig_of( $after[ $j ] ?? [] );

			foreach ( $sig_new as $p => $leaf ) {
				$other = $sig_old[ $p ] ?? null;

				if ( null === $other ) {
					continue;
				}

				$id_old = self::id_of( $other );
				$id_new = self::id_of( $leaf );

				if ( '' !== $id_old && '' !== $id_new && $id_old !== $id_new ) {
					$out[ $id_old ] = $id_new;
				}

				if ( isset( $leaf['rows'], $other['rows'] ) ) {
					$nested_old = self::rows_in( $other );
					$nested_new = self::rows_in( $leaf );
					self::collect_key_renames( $nested_old, $nested_new, self::align( $nested_old, $nested_new ), $out );
				}
			}
		}
	}

	/**
	 * The hash of a row; `$coarse` blanks the text leaves.
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param bool                 $coarse Whether to blank the text leaves.
	 * @return string
	 */
	private static function hash( array $row, bool $coarse = false ): string {
		return hash( 'sha256', serialize( self::canon( $row, $coarse ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A comparison key, never stored.
	}

	/**
	 * The comparison form of a row.
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param bool                 $coarse Whether to blank the text leaves.
	 * @return array<int, mixed>
	 */
	private static function canon( array $row, bool $coarse ): array {
		$leaves = [];

		foreach ( self::sig_of( $row ) as $leaf ) {
			if ( isset( $leaf['rows'] ) ) {
				$nested = [];

				foreach ( self::rows_in( $leaf ) as $sub ) {
					$nested[] = self::canon( $sub, $coarse );
				}

				$leaves[] = [ 'r', $nested ];
			} elseif ( array_key_exists( 'text', $leaf ) ) {
				$leaves[] = $coarse ? [ 't' ] : [ 't', Keys::norm( self::text_in( $leaf ), self::format_of( $leaf ) ) ];
			} else {
				$leaves[] = [ 'v', $leaf['value'] ?? null ];
			}
		}

		return [ self::layout_of( $row ), $leaves ];
	}

	/**
	 * Every text and value leaf of a row by positional path, plus the row's layout. Nested counts and layouts
	 * are not leaves: a translator changes them, so they name no source row.
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param string               $prefix Path prefix.
	 * @return array<string, mixed>
	 */
	private static function flatten( array $row, string $prefix = '' ): array {
		$out = '' === $prefix ? [ 'layout' => self::layout_of( $row ) ] : [];

		foreach ( self::sig_of( $row ) as $p => $leaf ) {
			$path = $prefix . $p;

			if ( isset( $leaf['rows'] ) ) {
				$nested = self::rows_in( $leaf );

				foreach ( $nested as $r => $sub ) {
					$out += self::flatten( $sub, $path . '/' . $r . '/' );
				}
			} elseif ( array_key_exists( 'text', $leaf ) ) {
				$out[ $path ] = [ 't', Keys::norm( self::text_in( $leaf ), self::format_of( $leaf ) ) ];
			} else {
				$out[ $path ] = [ 'v', $leaf['value'] ?? null ];
			}
		}

		return $out;
	}

	/**
	 * Whether a flattened leaf carries no evidence.
	 *
	 * @param mixed $value Flattened leaf.
	 * @return bool
	 */
	private static function is_empty_leaf( mixed $value ): bool {
		if ( null === $value || '' === $value || [] === $value ) {
			return true;
		}

		if ( is_array( $value ) && isset( $value[0] ) && array_key_exists( 1, $value ) ) {
			return null === $value[1] || '' === $value[1] || [] === $value[1];
		}

		return false;
	}

	/**
	 * Whether a row has a non-text leaf, nested rows included.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return bool
	 */
	private static function has_non_text( array $row ): bool {
		foreach ( self::sig_of( $row ) as $leaf ) {
			if ( isset( $leaf['rows'] ) ) {
				foreach ( self::rows_in( $leaf ) as $sub ) {
					if ( self::has_non_text( $sub ) ) {
						return true;
					}
				}
			} elseif ( ! array_key_exists( 'text', $leaf ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The text of a row: its text leaves, nested rows included, one per line.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string
	 */
	private static function text_of( array $row ): string {
		$parts = [];

		foreach ( self::sig_of( $row ) as $leaf ) {
			if ( isset( $leaf['rows'] ) ) {
				foreach ( self::rows_in( $leaf ) as $sub ) {
					$text = self::text_of( $sub );

					if ( '' !== $text ) {
						$parts[] = $text;
					}
				}
			} else {
				$text = self::text_in( $leaf );

				if ( '' !== trim( $text ) ) {
					$parts[] = $text;
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * The layout of a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string|null
	 */
	private static function layout_of( array $row ): ?string {
		return isset( $row['layout'] ) && is_string( $row['layout'] ) ? $row['layout'] : null;
	}

	/**
	 * The format of a text leaf.
	 *
	 * @param array<string, mixed> $leaf Leaf.
	 * @return string
	 */
	private static function format_of( array $leaf ): string {
		return ( $leaf['format'] ?? '' ) === FieldClass::FORMAT_HTML ? FieldClass::FORMAT_HTML : FieldClass::FORMAT_TEXT;
	}

	/**
	 * The text of a text leaf.
	 *
	 * @param array<string, mixed> $leaf Leaf.
	 * @return string
	 */
	private static function text_in( array $leaf ): string {
		return isset( $leaf['text'] ) && is_scalar( $leaf['text'] ) ? (string) $leaf['text'] : '';
	}

	/**
	 * The id of a leaf.
	 *
	 * @param array<string, mixed> $leaf Leaf.
	 * @return string
	 */
	private static function id_of( array $leaf ): string {
		return isset( $leaf['id'] ) && is_scalar( $leaf['id'] ) ? (string) $leaf['id'] : '';
	}

	/**
	 * The leaves of a row, in order.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<array<string, mixed>>
	 */
	private static function sig_of( array $row ): array {
		return self::arrays_in( isset( $row['sig'] ) && is_array( $row['sig'] ) ? $row['sig'] : [] );
	}

	/**
	 * The nested rows of a rows leaf, in order.
	 *
	 * @param array<string, mixed> $leaf Leaf.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<array<string, mixed>>
	 */
	private static function rows_in( array $leaf ): array {
		return self::arrays_in( isset( $leaf['rows'] ) && is_array( $leaf['rows'] ) ? $leaf['rows'] : [] );
	}

	/**
	 * The array members of a list, in order.
	 *
	 * @param array<mixed> $items Items.
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<array<string, mixed>>
	 */
	private static function arrays_in( array $items ): array {
		$out = [];

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				/**
				 * A leaf or a row is an array keyed by its attribute names.
				 *
				 * @var array<string, mixed> $item
				 */
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * The unpaired indices of two lists.
	 *
	 * @param int             $n        Old count.
	 * @param int             $m        New count.
	 * @param array<int, int> $pair_old New index per old index.
	 * @param array<int, int> $pair_new Old index per new index.
	 * @return array{list<int>, list<int>}
	 */
	private static function leftovers( int $n, int $m, array $pair_old, array $pair_new ): array {
		return self::unpaired( range( 0, max( 0, $n - 1 ) ), range( 0, max( 0, $m - 1 ) ), $pair_old, $pair_new, $n, $m );
	}

	/**
	 * The still unpaired members of two index lists.
	 *
	 * @param array<int, int> $list_old Old indices.
	 * @param array<int, int> $list_new New indices.
	 * @param array<int, int> $pair_old New index per old index.
	 * @param array<int, int> $pair_new Old index per new index.
	 * @param int|null        $n        Old count, when the lists come from a range.
	 * @param int|null        $m        New count.
	 * @return array{list<int>, list<int>}
	 */
	private static function unpaired( array $list_old, array $list_new, array $pair_old, array $pair_new, ?int $n = null, ?int $m = null ): array {
		$left_old = [];
		$left_new = [];

		foreach ( $list_old as $i ) {
			if ( ( null === $n || $i < $n ) && ! isset( $pair_old[ $i ] ) ) {
				$left_old[] = $i;
			}
		}

		foreach ( $list_new as $j ) {
			if ( ( null === $m || $j < $m ) && ! isset( $pair_new[ $j ] ) ) {
				$left_new[] = $j;
			}
		}

		return [ $left_old, $left_new ];
	}

	/**
	 * Pair two index lists by hash in order: the k-th row of a hash with the k-th on the other side.
	 *
	 * @param array<int, int>    $list_old Old indices.
	 * @param array<int, int>    $list_new New indices.
	 * @param array<int, string> $hash_old Hash per old index.
	 * @param array<int, string> $hash_new Hash per new index.
	 * @return array<int, array{int, int}>
	 * @phpstan-param list<int> $list_old
	 * @phpstan-param list<int> $list_new
	 */
	private static function in_order( array $list_old, array $list_new, array $hash_old, array $hash_new ): array {
		$tok_old = [];
		$tok_new = [];

		foreach ( $list_old as $i ) {
			$tok_old[] = $hash_old[ $i ];
		}

		foreach ( $list_new as $j ) {
			$tok_new[] = $hash_new[ $j ];
		}

		$out = [];

		foreach ( Lcs::in_order( $tok_old, $tok_new ) as [ $k, $l ] ) {
			$out[] = [ $list_old[ $k ], $list_new[ $l ] ];
		}

		return $out;
	}
}
