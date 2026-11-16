<?php
/**
 * Three-way merge of custom fields.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Util\TextTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides every custom field of a pair from three value maps (vB base, vN source now, vT translation), the first rule
 * that applies winning: left alone, kept, filled, re-translated, unknown base, or changed outside ReSync. A key
 * holding the source's own text (two tokens or more) where the source added or changed it is a seeded text, proposed
 * as new. Pure.
 *
 * @phpstan-type Context array{owned?: array<string, string>, unknown?: list<string>, seed_cleared?: array<string, mixed>, threshold?: int, texts?: array<string, string>, verbatim?: list<string>, base_known?: bool, absent?: list<string>}
 * @phpstan-type Result array{values: array<string, string>, status: array<string, string>, edited: list<string>, flags: list<string>, counts: array<string, int>}
 */
final class FieldMerge {

	/** The key was seed-cleared on the translation; never touched. */
	public const STATUS_SEED_CLEARED = 'seed_cleared';
	/** The translation already holds the source's value. */
	public const STATUS_IN_SYNC = 'in_sync';
	/** The base value is unknown; the translation's value is kept. */
	public const STATUS_UNKNOWN_BASE = 'unknown_base';
	/** The source did not change the key; the translation's value is kept. */
	public const STATUS_KEPT = 'kept';
	/** A key new on the source. */
	public const STATUS_NEW = 'new';
	/** The source changed the key. */
	public const STATUS_CHANGED = 'changed';
	/** The source's change is a near match of the base; the translation's value is kept. */
	public const STATUS_FUZZY = 'fuzzy';

	/** The flag of a rejected machine output. */
	public const FLAG_MT_QUALITY = 'mt_quality';

	/** The fewest tokens of a seeded text (the same-as-source rule of blocks). */
	private const MIN_SEEDED_TOKENS = 2;

	/** The lowest threshold that keeps near matches; a value outside 80..100 is exact only. */
	private const MIN_THRESHOLD = 80;

	/**
	 * Merge the fields of a pair.
	 *
	 * @param array<string, mixed> $base        Base values per key (vB).
	 * @param array<string, mixed> $source      The source's values now (vN).
	 * @param array<string, mixed> $translation The translation's values (vT).
	 * @param array<string, mixed> $context     `owned` (key => sha256 of the value automation wrote), `unknown` (keys whose base is unknown), `seed_cleared` (keyed by key), `threshold` (`resync_match_threshold`, default 100), `texts` (machine outputs per key), `verbatim` (machine mode: keys whose source value has nothing to translate; they take it, counted `same_as_source`), `base_known` (false for a revision bootstrap: every base value is unknown), `absent` (keys checked reads found no row for on both posts; a null value alone may be several rows or a failed read).
	 * @return array{values: array<string, string>, status: array<string, string>, edited: list<string>, flags: list<string>, counts: array<string, int>}
	 * @phpstan-param Context $context
	 * @phpstan-return Result
	 */
	public static function merge( array $base, array $source, array $translation, array $context = [] ): array {
		$owned      = $context['owned'] ?? [];
		$unknown    = array_fill_keys( $context['unknown'] ?? [], true );
		$seed       = $context['seed_cleared'] ?? [];
		$threshold  = $context['threshold'] ?? 100;
		$texts      = $context['texts'] ?? [];
		$verbatim   = array_fill_keys( $context['verbatim'] ?? [], true );
		$base_known = $context['base_known'] ?? true;
		$absent     = array_fill_keys( $context['absent'] ?? [], true );

		$values = [];
		$status = [];
		$edited = [];
		$flags  = [];
		$counts = [
			'new'                => 0,
			'changed'            => 0,
			'fuzzy'              => 0,
			'left_source'        => 0,
			'machine_translated' => 0,
			'unknown_base'       => 0,
			'same_as_source'     => 0,
		];

		$keys = array_keys( $source + $translation );

		foreach ( $keys as $key ) {
			$key = (string) $key;
			$vb  = self::value( $base, $key );
			$vn  = self::value( $source, $key );
			$vt  = self::value( $translation, $key );

			if ( isset( $seed[ $key ] ) ) {
				$status[ $key ] = self::STATUS_SEED_CLEARED;
				continue;
			}

			// Both reads proved no row: nothing to decide, so never an unknown base (it would keep the write incomplete).
			if ( isset( $absent[ $key ] ) && ! isset( $source[ $key ] ) && ! isset( $translation[ $key ] ) ) {
				$status[ $key ] = self::STATUS_IN_SYNC;
				continue;
			}

			if ( $vt !== null && $vt === $vn ) {
				// A seeded text: the source's own text on the translation, where the source added the key or changed it, is
				// proposed as new.
				if ( $base_known && ! isset( $unknown[ $key ] ) && $vb !== $vn && ! self::opaque( $base, $key ) && count( TextTokens::tokens( $vn ) ) >= self::MIN_SEEDED_TOKENS ) {
					// As below: a value ReSync's record does not match was changed outside ReSync (by a person or by a
					// machine translation): held for review.
					if ( isset( $owned[ $key ] ) && $owned[ $key ] !== hash( 'sha256', $vt ) ) {
						$edited[] = $key;
					}

					$status[ $key ] = self::STATUS_NEW;
					++$counts['new'];
					self::seeded_output( $key, $texts, $verbatim, $values, $counts, $flags );
					continue;
				}

				$status[ $key ] = self::STATUS_IN_SYNC;
				continue;
			}

			if ( ! $base_known || isset( $unknown[ $key ] ) || self::opaque( $base, $key ) || self::opaque( $source, $key ) || self::opaque( $translation, $key ) ) {
				$status[ $key ] = self::STATUS_UNKNOWN_BASE;
				++$counts['unknown_base'];
				continue;
			}

			if ( $vb !== null && $vn === $vb ) {
				$status[ $key ] = self::STATUS_KEPT;
				continue;
			}

			if ( $vb === null && $vt !== null ) {
				$status[ $key ] = self::STATUS_UNKNOWN_BASE;
				++$counts['unknown_base'];
				continue;
			}

			if ( $vn === null ) {
				$status[ $key ] = self::STATUS_KEPT;
				continue;
			}

			if ( $vb === null ) {
				$status[ $key ] = self::STATUS_NEW;
				++$counts['new'];
				$values[ $key ] = self::output( $key, $vn, $texts, $verbatim, $counts, $flags );
				continue;
			}

			if ( $vt === null || $vt === '' || $vt === $vb ) {
				// A copy of the base ReSync's record does not match was changed outside ReSync: held for review.
				if ( $vt !== null && $vt !== '' && isset( $owned[ $key ] ) && $owned[ $key ] !== hash( 'sha256', $vt ) ) {
					$edited[] = $key;
				}

				$status[ $key ] = self::STATUS_CHANGED;
				++$counts['changed'];
				$values[ $key ] = self::output( $key, $vn, $texts, $verbatim, $counts, $flags );
				continue;
			}

			if ( ! isset( $owned[ $key ] ) || $owned[ $key ] !== hash( 'sha256', $vt ) ) {
				$edited[] = $key;
			}

			if ( $threshold < 100 && $threshold >= self::MIN_THRESHOLD && TextTokens::similarity( $vn, $vb ) >= $threshold ) {
				$status[ $key ] = self::STATUS_FUZZY;
				++$counts['fuzzy'];
				continue;
			}

			$status[ $key ] = self::STATUS_CHANGED;
			++$counts['changed'];
			$values[ $key ] = self::output( $key, $vn, $texts, $verbatim, $counts, $flags );
		}

		return [
			'values' => $values,
			'status' => $status,
			'edited' => $edited,
			'flags'  => $flags,
			'counts' => $counts,
		];
	}

	/**
	 * A key's value as a string, null when absent or not a string.
	 *
	 * @param array<string, mixed> $values Values.
	 * @param string               $key    Key.
	 * @return string|null
	 */
	private static function value( array $values, string $key ): ?string {
		if ( ! array_key_exists( $key, $values ) ) {
			return null;
		}

		$value = $values[ $key ];

		return is_string( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : null );
	}

	/**
	 * Whether a key holds a value the merge cannot read (an array or object: serialized or structured meta); such a key
	 * is an unknown base, never written.
	 *
	 * @param array<string, mixed> $values Values.
	 * @param string               $key    Key.
	 * @return bool
	 */
	private static function opaque( array $values, string $key ): bool {
		return isset( $values[ $key ] ) && ! is_scalar( $values[ $key ] );
	}

	/**
	 * The value a seeded key gets: an accepted machine output; else nothing is written (it holds the source's text) and
	 * it counts `same_as_source` when it has nothing to translate, else `left_source`.
	 *
	 * @param string                $key      Key.
	 * @param array<string, string> $texts    Machine outputs per key.
	 * @param array<string, true>   $verbatim Keys with nothing to translate.
	 * @param array<string, string> $values   Values to write, updated.
	 * @param array<string, int>    $counts   Counts, updated.
	 * @param array<int, string>    $flags    Flags, updated.
	 * @return void
	 * @phpstan-param list<string> $flags
	 */
	private static function seeded_output( string $key, array $texts, array $verbatim, array &$values, array &$counts, array &$flags ): void {
		if ( isset( $verbatim[ $key ] ) ) {
			++$counts['same_as_source'];

			return;
		}

		if ( isset( $texts[ $key ] ) && Verifier::accepts_text( $texts[ $key ] ) ) {
			++$counts['machine_translated'];
			$values[ $key ] = $texts[ $key ];

			return;
		}

		if ( isset( $texts[ $key ] ) && ! in_array( self::FLAG_MT_QUALITY, $flags, true ) ) {
			$flags[] = self::FLAG_MT_QUALITY;
		}

		++$counts['left_source'];
	}

	/**
	 * The value a changed or new key gets: the source's value (`same_as_source`) when it has nothing to translate, else an
	 * accepted machine output, else the source's text (`left_source`).
	 *
	 * @param string                $key      Key.
	 * @param string                $source   The source's value.
	 * @param array<string, string> $texts    Machine outputs per key.
	 * @param array<string, true>   $verbatim Keys with nothing to translate.
	 * @param array<string, int>    $counts   Counts, updated.
	 * @param array<int, string>    $flags    Flags, updated.
	 * @return string
	 * @phpstan-param list<string> $flags
	 */
	private static function output( string $key, string $source, array $texts, array $verbatim, array &$counts, array &$flags ): string {
		if ( isset( $verbatim[ $key ] ) ) {
			++$counts['same_as_source'];

			return $source;
		}

		if ( isset( $texts[ $key ] ) ) {
			if ( Verifier::accepts_text( $texts[ $key ] ) ) {
				++$counts['machine_translated'];

				return $texts[ $key ];
			}

			if ( ! in_array( self::FLAG_MT_QUALITY, $flags, true ) ) {
				$flags[] = self::FLAG_MT_QUALITY;
			}
		}

		++$counts['left_source'];

		return $source;
	}
}
