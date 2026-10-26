<?php
/**
 * WooCommerce stock alignment: the "Align all to the default language" job.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Background\AbstractJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aligns every drifting copy group to its default-language copy ("Stock across languages" block).
 * A listed group whose stock differs from `expected` (what the user saw) is left; no stock value comes
 * from the request. A retry plans from fresh reads: a copy still aligned is not written again, one that drifted is.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockAlignJob extends AbstractJob {

	/**
	 * Job type.
	 */
	public const TYPE = 'wc_stock_align';

	/**
	 * Most members `expected` may name.
	 */
	public const MAX_EXPECTED_MEMBERS = 1000;

	/**
	 * Groups left as they were that the result lists.
	 */
	public const LISTED = 100;

	/**
	 * Seconds of work per slice when run as a job.
	 */
	private const SLICE_SECONDS = 20;

	/**
	 * Groups per scan page.
	 */
	private const BATCH = 50;

	/**
	 * {@inheritDoc}
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_capability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_threshold(): int {
		return 2000;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_continuation(): bool {
		return true;
	}

	/**
	 * The shop's product translation groups.
	 *
	 * @param array<string, mixed> $args Worker args.
	 * @return int
	 */
	protected function args_size( array $args ): int {
		return StockAlignSite::product_groups();
	}

	/**
	 * The `expected` argument as anchor => member id => stock; null when invalid
	 * (over StockCheckJob::LISTED groups or MAX_EXPECTED_MEMBERS members, bad ids, non-finite numbers).
	 *
	 * @param mixed $value Value given (decoded JSON).
	 * @return array<int, array<int, float>>|null
	 */
	public static function expected( mixed $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > StockCheckJob::LISTED ) {
			return null;
		}

		$out     = [];
		$members = 0;

		foreach ( $value as $anchor => $group ) {
			if ( ! is_array( $group ) || ! self::positive_id( $anchor ) || [] === $group ) {
				return null;
			}

			foreach ( $group as $id => $stock ) {
				if ( ! self::positive_id( $id ) || ! ( is_int( $stock ) || is_float( $stock ) ) || ! is_finite( (float) $stock ) || ++$members > self::MAX_EXPECTED_MEMBERS ) {
					return null;
				}

				$out[ (int) $anchor ][ (int) $id ] = (float) $stock;
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args     Worker args (`from`: 'default', `expected`).
	 * @param callable             $progress Progress callback.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the alignment cannot run or a read fails.
	 */
	public function execute( array $args, callable $progress ): array {
		$sync = Api::ready();

		if ( $sync instanceof \WP_Error ) {
			throw new \RuntimeException( esc_html( $sync->get_error_message() ) );
		}

		$expected = self::expected( $args['expected'] ?? [] );

		// The ajax request validated the list already: a row whose list does not validate aligns nothing.
		if ( null === $expected ) {
			throw new \RuntimeException( esc_html( StockDriftAlign::reason( 'changed' ) ) );
		}

		$site   = new StockAlignSite( $sync );
		$engine = $site->engine();
		$result = self::resume( $args );
		$began  = microtime( true );
		$sliced = is_array( $args['__perflocale_slice'] ?? null ) && 'cli' !== PHP_SAPI;
		$from   = $site->reference( 'default' );

		if ( 0 === $result['total'] ) {
			$result['total'] = StockAlignSite::product_groups();
		}

		do {
			$changed = [];

			$out = $engine->run(
				[
					'from'     => $from,
					'dry_run'  => false,
					'after'    => $result['cursor'],
					'batch'    => self::BATCH,
					'expected' => $expected,
				],
				static function ( array $row ) use ( &$result, &$changed ): void {
					if ( 'changed' === $row['status'] ) {
						++$result['copies'];
						$changed[ $row['group'] ] = true;
						return;
					}

					++$result['left'];

					if ( count( $result['left_groups'] ) < self::LISTED ) {
						$result['left_groups'][] = [
							'group'     => $row['group'],
							'parent_id' => $row['parent_id'],
							'status'    => $row['status'],
							'code'      => $row['code'] ?? '',
							'reason'    => $row['reason'] ?? '',
						];
					}
				},
				static fn(): bool => true
			);

			$result['groups'] += count( $changed );
			$result['read']   += self::BATCH;
			$result['cursor']  = (int) ( $out['next'] ?? 0 );

			$progress( min( $result['read'], max( 1, $result['total'] ) ), max( 1, $result['total'] ) );

			if ( null === $out['next'] ) {
				$result['done'] = true;
				return $result;
			}
		} while ( ! $sliced || microtime( true ) - $began < self::SLICE_SECONDS );

		$result['__perflocale_continue'] = true;

		return $result;
	}

	/**
	 * The result so far: the stored result of the previous slice, or a new one.
	 *
	 * @param array<string, mixed> $args Worker args.
	 * @return array{done: bool, cursor: int, read: int, total: int, copies: int, groups: int, left: int, left_groups: list<array<mixed>>}
	 */
	private static function resume( array $args ): array {
		$fresh  = [
			'done'        => false,
			'cursor'      => 0,
			'read'        => 0,
			'total'       => 0,
			'copies'      => 0,
			'groups'      => 0,
			'left'        => 0,
			'left_groups' => [],
		];
		$slice  = $args['__perflocale_slice'] ?? null;
		$stored = is_array( $slice ) ? ( $slice['resume'] ?? null ) : null;

		if ( ! is_array( $stored ) || ! isset( $stored['cursor'], $stored['left_groups'] ) || ! is_array( $stored['left_groups'] ) ) {
			return $fresh;
		}

		foreach ( [ 'cursor', 'read', 'total', 'copies', 'groups', 'left' ] as $key ) {
			$fresh[ $key ] = is_numeric( $stored[ $key ] ?? null ) ? max( 0, (int) $stored[ $key ] ) : 0;
		}

		$fresh['left_groups'] = array_values( array_filter( array_slice( $stored['left_groups'], 0, self::LISTED ), 'is_array' ) );

		return $fresh;
	}

	/**
	 * Whether a key is a positive whole number (an int, or its decimal string).
	 *
	 * @param mixed $id Key.
	 * @return bool
	 */
	private static function positive_id( mixed $id ): bool {
		return ( is_int( $id ) && $id > 0 ) || ( is_string( $id ) && '' !== $id && \PerfLocale\Helper::is_ascii_digits( $id ) && (int) $id > 0 );
	}
}
