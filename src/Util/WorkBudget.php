<?php
/**
 * The work budgets of a resync that a site may raise, and the memory
 * limit the engine checks its headroom against.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Translation\BlockAttributeSource;
use PerfLocale\Translation\Fields\RowAligner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The budgets past which a resync refuses an input as too large and leaves it for review; a refusal is never a
 * wrong result. Read only where the default would refuse, so inputs within the defaults never call the filter.
 */
final class WorkBudget {

	/** Budget names, the keys of the `perflocale/resync/work_budget` array. */
	public const ROW_TOKENS     = 'row_tokens';
	public const TOKENIZER_SCAN = 'tokenizer_scan';
	public const TAG_ATTRIBUTES = 'tag_attributes';

	/** Raise ceilings: tokenizer x8 (about 8 s per MB of hostile fragment), tag attributes x4 (about 15 MB per tag). */
	private const MAXIMUM = [
		self::ROW_TOKENS     => 8 * RowAligner::TOKEN_BUDGET,
		self::TOKENIZER_SCAN => 8 * BlockAttributeSource::SCAN_BYTES_PER_BYTE,
		self::TAG_ATTRIBUTES => 4 * BlockAttributeSource::MAX_ATTRIBUTES,
	];

	/**
	 * One budget as the filter sets it, kept between the default and MAXIMUM.
	 *
	 * @param string $name ROW_TOKENS, TOKENIZER_SCAN or TAG_ATTRIBUTES.
	 * @return int
	 */
	public static function get( string $name ): int {
		$defaults = [
			self::ROW_TOKENS     => RowAligner::TOKEN_BUDGET,
			self::TOKENIZER_SCAN => BlockAttributeSource::SCAN_BYTES_PER_BYTE,
			self::TAG_ATTRIBUTES => BlockAttributeSource::MAX_ATTRIBUTES,
		];

		/**
		 * Filters the work budgets past which a resync refuses an input as too large and leaves it for review.
		 * Called only when an input is over a default; values are kept between default and maximum. A document too
		 * large for the request's memory is refused whatever these are: raise `memory_limit` for it.
		 *
		 * @internal Not public: memory and CPU limits; a large post needs a higher memory_limit instead.
		 * @hook perflocale/resync/work_budget
		 * @since 1.0.8
		 *
		 * @param array<string, int> $budgets {
		 *     @type int $row_tokens     Tokens the texts of one row-alignment gap may hold on both sides. Default 500,000, at most 4,000,000.
		 *     @type int $tokenizer_scan Bytes the tokenizer may read again, per byte of a fragment, from `<` that start no tag. Default 1,024, at most 8,192.
		 *     @type int $tag_attributes Distinct attribute names of one start tag. Default 4,096, at most 16,384.
		 * }
		 * @param string             $name    The budget being decided.
		 */
		$budgets = apply_filters( 'perflocale/resync/work_budget', $defaults, $name );
		$default = $defaults[ $name ] ?? 0;
		$value   = Scalar::int( $budgets[ $name ] ?? $default );

		return min( max( $value, $default ), self::MAXIMUM[ $name ] ?? $default );
	}

	/** Real memory an autoloaded option write takes per autoloaded byte (Redis Object Cache + Predis: 4.0 to 4.5). */
	private const OPTIONS_PEAK_PER_BYTE = 4.5;

	/** And per autoloaded option: many small options cost more than their bytes (up to 9.9 x on 100,000 of 10 B). */
	private const OPTIONS_PEAK_PER_OPTION = 256;

	/**
	 * The options_reserve() answers by blog id.
	 *
	 * @var array<int, int>
	 */
	private static array $options_reserve = [];

	/**
	 * The limit a resync call's ceiling is built on: memory_limit() less options_reserve(); null when unbounded.
	 *
	 * @return int|null
	 */
	public static function work_limit(): ?int {
		$limit = self::memory_limit();

		return null === $limit ? null : max( 0, $limit - self::options_reserve() );
	}

	/**
	 * Memory an option write takes at its peak under a persistent object cache: it fetches the autoloaded options again
	 * and sets them back; 0 without one. Read once per blog and process.
	 *
	 * @return int Bytes.
	 */
	public static function options_reserve(): int {
		if ( ! wp_using_ext_object_cache() ) {
			return 0;
		}

		$blog = get_current_blog_id();

		if ( isset( self::$options_reserve[ $blog ] ) ) {
			return self::$options_reserve[ $blog ];
		}

		$all = wp_cache_get( 'alloptions', 'options' );

		if ( ! is_array( $all ) ) {
			return 0;
		}

		$bytes = 0;

		foreach ( $all as $name => $value ) {
			$bytes += strlen( (string) $name ) + ( is_string( $value ) ? strlen( $value ) : 0 );
		}

		/**
		 * Filter the real memory per autoloaded byte a ReSync run keeps free for an option write; raise only.
		 *
		 * For an object-cache drop-in that takes more than the measured default; a lower or non-numeric value is ignored.
		 *
		 * @internal Not public: a memory guard; a wrong value risks running out of memory.
		 * @hook perflocale/resync/options_peak_per_byte
		 * @since 1.0.8
		 *
		 * @param float $per_byte Bytes per autoloaded byte. Default 4.5.
		 */
		$per_byte = Scalar::str( apply_filters( 'perflocale/resync/options_peak_per_byte', self::OPTIONS_PEAK_PER_BYTE ) );
		$per_byte = is_numeric( $per_byte ) ? max( self::OPTIONS_PEAK_PER_BYTE, (float) $per_byte ) : self::OPTIONS_PEAK_PER_BYTE;

		self::$options_reserve[ $blog ] = (int) ( $bytes * $per_byte ) + count( $all ) * self::OPTIONS_PEAK_PER_OPTION;

		return self::$options_reserve[ $blog ];
	}

	/**
	 * The request's memory limit in bytes; null when it is unbounded.
	 *
	 * @return int|null
	 */
	public static function memory_limit(): ?int {
		$raw = trim( ini_get( 'memory_limit' ) );

		if ( $raw === '' || $raw === '-1' ) {
			return null;
		}

		$number = (int) $raw;
		$bytes  = match ( strtolower( substr( $raw, -1 ) ) ) {
			'g' => $number * 1073741824,
			'm' => $number * 1048576,
			'k' => $number * 1024,
			default => $number,
		};

		return $bytes > 0 ? $bytes : null;
	}
}
