<?php
/**
 * WooCommerce stock alignment: the "Check stock" job of the add-on settings.
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
 * Counts the drifting copy groups and the copies an alignment would change, and lists the first LISTED.
 * Reads only. Stores ids and numbers only: the status request adds product names for the user who asked.
 *
 * @internal Internal to PerfLocale; not a public API.
 *
 * @phpstan-import-type StockGroup from StockDriftScan
 * @phpstan-import-type StockPlan from StockDriftAlign
 * @phpstan-type StockListed array{group: int, parent_id: int, members: list<array{0: int, 1: string, 2: float}>, reference: int, code: string}
 */
final class StockCheckJob extends AbstractJob {

	/**
	 * Job type.
	 */
	public const TYPE = 'wc_stock_check';

	/**
	 * Groups listed for the screen.
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
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args     Worker args (`from`: 'default').
	 * @param callable             $progress Progress callback.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the alignment cannot run or a read fails.
	 */
	public function execute( array $args, callable $progress ): array {
		$sync = Api::ready();

		if ( $sync instanceof \WP_Error ) {
			throw new \RuntimeException( esc_html( $sync->get_error_message() ) );
		}

		$site   = new StockAlignSite( $sync );
		$engine = $site->engine();
		$from   = $site->reference( 'default' );
		$result = self::resume( $args );
		$began  = microtime( true );
		$sliced = is_array( $args['__perflocale_slice'] ?? null ) && 'cli' !== PHP_SAPI;
		$pages  = $site->pages();

		if ( 0 === $result['total'] ) {
			$result['total'] = StockAlignSite::product_groups();
		}

		do {
			$page = $pages( $result['cursor'], self::BATCH, null );

			foreach ( $page['groups'] as $group ) {
				$plan = $engine->plan( $group, $from, null );

				++$result['drifting'];

				if ( '' !== $plan['code'] ) {
					++$result['skipped'];
				} else {
					$result['copies'] += count( $plan['targets'] );
				}

				if ( count( $result['groups'] ) < self::LISTED ) {
					$result['groups'][] = self::listed( $group, $plan );
				}
			}

			$result['read']  += self::BATCH;
			$result['cursor'] = (int) ( $page['next'] ?? 0 );

			$progress( min( $result['read'], max( 1, $result['total'] ) ), max( 1, $result['total'] ) );

			if ( null === $page['next'] ) {
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
	 * @return array{done: bool, cursor: int, read: int, total: int, drifting: int, copies: int, skipped: int, groups: list<array<mixed>>}
	 */
	private static function resume( array $args ): array {
		$fresh  = [
			'done'     => false,
			'cursor'   => 0,
			'read'     => 0,
			'total'    => 0,
			'drifting' => 0,
			'copies'   => 0,
			'skipped'  => 0,
			'groups'   => [],
		];
		$slice  = $args['__perflocale_slice'] ?? null;
		$stored = is_array( $slice ) ? ( $slice['resume'] ?? null ) : null;

		if ( ! is_array( $stored ) || ! isset( $stored['cursor'], $stored['groups'] ) || ! is_array( $stored['groups'] ) ) {
			return $fresh;
		}

		foreach ( [ 'cursor', 'read', 'total', 'drifting', 'copies', 'skipped' ] as $key ) {
			$fresh[ $key ] = is_numeric( $stored[ $key ] ?? null ) ? max( 0, (int) $stored[ $key ] ) : 0;
		}

		$fresh['groups'] = array_values( array_filter( array_slice( $stored['groups'], 0, self::LISTED ), 'is_array' ) );

		return $fresh;
	}

	/**
	 * One listed group: ids and numbers only.
	 *
	 * @param StockGroup $group A StockDriftScan group.
	 * @param StockPlan  $plan  Its plan.
	 * @return StockListed
	 */
	private static function listed( array $group, array $plan ): array {
		$members = [];

		foreach ( $group['members'] as $member ) {
			$members[] = [ $member['product_id'], $member['language'], $member['stock'] ];
		}

		return [
			'group'     => $group['group'],
			'parent_id' => $group['parent_id'],
			'members'   => $members,
			'reference' => $plan['reference'],
			'code'      => $plan['code'],
		];
	}
}
