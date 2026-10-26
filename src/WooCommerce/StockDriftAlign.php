<?php
/**
 * WooCommerce inventory sync: align the stock of a product's language copies.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets every copy of a drifting copy group to one reference value (a language's copy, or a callable's value).
 * Relative writes under renewed locks, no SQL transaction (row locks across WooCommerce hooks block checkouts):
 * steps in docs-ai/11-code-rationale.md, "StockDriftAlign: steps per group".
 *
 * @internal Internal to PerfLocale; not a public API.
 *
 * @phpstan-import-type StockGroup from StockDriftScan
 * @phpstan-import-type StockMember from StockDriftScan
 * @phpstan-import-type StockPage from StockDriftScan
 * @phpstan-type StockTarget array{product_id: int, parent_id: int, language: string, old: float}
 * @phpstan-type StockPlan array{code: string, reference: int, value: float, targets: list<StockTarget>}
 * @phpstan-type StockRow array{status: string, group: int, product_id: int, parent_id: int, language: string, old?: float, new?: float, reference?: int, code?: string, reason?: string}
 * @phpstan-type StockWritten array{product_id: int, parent_id: int, language: string, old: float, new: float, delta: float}
 * @phpstan-type StockOutcome array{0: string, 1: string, 2: string}
 * @phpstan-type StockReadRow array{stock: float|null, managed: bool, opted_out: bool, parent: int, copy: string|null}
 * @phpstan-type StockPublicGroup array{group: int, parent_id: int, members: list<StockMember>}
 */
final class StockDriftAlign {

	/**
	 * Codes of a group left as it was.
	 */
	public const SKIP_CODES = [ 'reference_missing', 'reference_unmanaged', 'reference_opted_out', 'reference_not_numeric', 'reference_changed', 'no_value', 'busy', 'changed', 'changed_while_aligning' ];

	/**
	 * Codes of a group whose write failed.
	 */
	public const FAIL_CODES = [ 'write_failed', 'undo_failed' ];

	/**
	 * Groups per scan page when the caller names none.
	 */
	public const DEFAULT_BATCH = 200;

	/**
	 * Tries of one write while every copy's stock moves evenly (orders).
	 */
	private const MOVE_TRIES = 3;

	/**
	 * The store the engine reads, locks and writes.
	 *
	 * @var StockAlignBackend
	 */
	private StockAlignBackend $backend;

	/**
	 * One page of drifting groups (StockDriftScan::groups_page()).
	 *
	 * @var callable(int, int, list<int>|null): StockPage
	 */
	private $pages;

	/**
	 * Constructor.
	 *
	 * @param StockAlignBackend                                $backend The store.
	 * @param callable(int, int, list<int>|null): StockPage $pages   The drifting groups, one page at a time.
	 */
	public function __construct( StockAlignBackend $backend, callable $pages ) {
		$this->backend = $backend;
		$this->pages   = $pages;
	}

	/**
	 * Plan (and unless a dry run, apply) every drifting group, page by page; each row goes to $emit as decided.
	 *
	 * @param array{from: string|callable, dry_run: bool, after?: int, batch?: int, group_ids?: list<int>|null, anchors?: list<int>|null, expected?: array<int, array<int, float>>|null} $args
	 *        `from`: reference slug ('' when none) or callable; `group_ids` / `anchors`: limit to these
	 *        translation / copy groups; `expected`: anchor => member id => the stock the user saw.
	 * @param callable(StockRow): void $emit        Receives each result row.
	 * @param (callable(): bool)|null  $should_stop Asked after each page; true stops the run there.
	 * @return array{next: int|null, groups: int} The cursor to continue from (null: done) and the drifting groups handled.
	 * @throws \RuntimeException When a scan read fails (rows emitted before stay emitted).
	 */
	public function run( array $args, callable $emit, ?callable $should_stop = null ): array {
		$from      = $args['from'];
		$dry_run   = (bool) $args['dry_run'];
		$after     = max( 0, (int) ( $args['after'] ?? 0 ) );
		$batch     = (int) ( $args['batch'] ?? self::DEFAULT_BATCH );
		$group_ids = $args['group_ids'] ?? null;
		$anchors   = $args['anchors'] ?? null;
		$expected  = $args['expected'] ?? null;
		$handled   = 0;

		do {
			$page = ( $this->pages )( $after, $batch, $group_ids );

			foreach ( $page['groups'] as $group ) {
				if ( null !== $anchors && ! in_array( $group['group'], $anchors, true ) ) {
					continue;
				}

				++$handled;

				foreach ( $this->group( $group, $from, $dry_run, null !== $expected ? ( $expected[ $group['group'] ] ?? null ) : null ) as $row ) {
					$emit( $row );
				}
			}

			$after = $page['next'];

			if ( null !== $after && null !== $should_stop && $should_stop() ) {
				break;
			}
		} while ( null !== $after );

		return [
			'next'   => $after,
			'groups' => $handled,
		];
	}

	/**
	 * Plan one drifting group and, unless a dry run, apply the plan.
	 *
	 * @param StockGroup             $group    A StockDriftScan group.
	 * @param string|callable        $from     Reference language slug, or a callable.
	 * @param bool                   $dry_run  Plan only.
	 * @param array<int, float>|null $expected Member id => stock the user saw.
	 * @return list<StockRow> Result rows.
	 */
	public function group( array $group, string|callable $from, bool $dry_run, ?array $expected ): array {
		$plan = $this->plan( $group, $from, $expected );

		if ( '' !== $plan['code'] ) {
			return [ $this->left( $group, 'skipped', $plan['code'] ) ];
		}

		if ( $dry_run ) {
			$rows = [];

			foreach ( $plan['targets'] as $target ) {
				$rows[] = [
					'status'     => 'planned',
					'group'      => (int) $group['group'],
					'product_id' => $target['product_id'],
					'parent_id'  => $target['parent_id'],
					'language'   => $target['language'],
					'old'        => $target['old'],
					'new'        => $plan['value'],
					'reference'  => $plan['reference'],
				];
			}

			return $rows;
		}

		return $this->apply( $group, $plan, $expected );
	}

	/**
	 * The plan of one group: reference, value and differing copies. No lock, no write; a callable `from` runs here.
	 *
	 * @param StockGroup             $group    A StockDriftScan group.
	 * @param string|callable        $from     Reference language slug, or a callable.
	 * @param array<int, float>|null $expected Member id => stock the user saw.
	 * @return StockPlan
	 *         `code` is '' for a plan, else the skip code.
	 */
	public function plan( array $group, string|callable $from, ?array $expected ): array {
		$none = [
			'code'      => '',
			'reference' => 0,
			'value'     => 0.0,
			'targets'   => [],
		];

		$public    = self::public_group( $group );
		$reference = null;

		if ( is_string( $from ) ) {
			foreach ( $group['members'] as $member ) {
				if ( '' !== $from && $member['language'] === $from ) {
					$reference = $member;
					break;
				}
			}

			if ( null === $reference ) {
				$none['code'] = self::excluded_reason( $group, $from );
				return $none;
			}

			$value = $reference['stock'];
		} else {
			try {
				$value = $from( $public );
			} catch ( \Throwable $e ) {
				$this->backend->log( sprintf( 'PerfLocale stock alignment: the reference callable failed for group %d: %s', (int) $group['group'], $e->getMessage() ) );
				$value = null;
			}

			if ( null === $value ) {
				$none['code'] = 'no_value';
				return $none;
			}

			if ( ! self::is_number( $value ) ) {
				$none['code'] = 'reference_not_numeric';
				return $none;
			}
		}

		$reference_id = null !== $reference ? (int) $reference['product_id'] : 0;

		if ( null !== $expected ) {
			$code = self::against_expected( self::stocks( $group['members'] ), $expected, $reference_id );

			if ( '' !== $code ) {
				$none['code'] = $code;
				return $none;
			}
		}

		$value = $this->backend->filter_value( (float) $value, $public, null !== $reference ? self::public_member( $reference ) : null );

		if ( null === $value ) {
			$none['code'] = 'no_value';
			return $none;
		}

		if ( ! self::is_number( $value ) ) {
			$none['code'] = 'reference_not_numeric';
			return $none;
		}

		$value   = $this->backend->stock_amount( (float) $value );
		$targets = [];

		foreach ( $group['members'] as $member ) {
			if ( ! self::same( (float) $member['stock'], $value ) ) {
				$targets[] = [
					'product_id' => (int) $member['product_id'],
					'parent_id'  => (int) $member['parent_id'],
					'language'   => (string) $member['language'],
					'old'        => (float) $member['stock'],
				];
			}
		}

		return [
			'code'      => '',
			'reference' => $reference_id,
			'value'     => $value,
			'targets'   => $targets,
		];
	}

	/**
	 * Apply one group's plan: steps 2-8 of docs-ai/11-code-rationale.md, "StockDriftAlign: steps per group".
	 *
	 * @param StockGroup             $group    A StockDriftScan group.
	 * @param StockPlan              $plan     The plan.
	 * @param array<int, float>|null $expected Member id => stock the user saw.
	 * @return list<StockRow> Result rows.
	 */
	private function apply( array $group, array $plan, ?array $expected ): array {
		$ids = [];

		foreach ( $group['members'] as $member ) {
			$ids[] = (int) $member['product_id'];
		}

		sort( $ids );

		if ( ! $this->backend->lock( $ids ) ) {
			return [ $this->left( $group, 'skipped', 'busy' ) ];
		}

		$written = [];
		$outcome = null;

		try {
			$now  = $this->backend->read( $ids );
			$code = self::recheck( $group['members'], $now, $plan['reference'], $expected );

			if ( '' !== $code ) {
				return [ $this->left( $group, 'skipped', $code ) ];
			}

			$value = $plan['value'];
			$stock = self::numbers( $now );

			// An unmirrored SET (its marker) is replayed first, never overwritten by the plan.
			$members = [] === $this->backend->markers( $ids ) ? $group['members'] : [];

			foreach ( $members as $member ) {
				if ( self::same( $stock[ (int) $member['product_id'] ], $value ) ) {
					continue;
				}

				$outcome = $this->write( $ids, $member, $stock, $value, $written );

				if ( null !== $outcome ) {
					break;
				}
			}

			// false: a copy's stock moved unevenly (a SET or an edit); its replay and the verify decide.
			if ( null === $outcome || false === $outcome ) {
				$outcome = $this->finish( $group, $ids, $written );
			}
		} catch ( \RuntimeException $e ) {
			// A later read failed: the copies written so far stay reported; the group is not known aligned.
			if ( $e instanceof StockWriteFailed ) {
				throw $e;
			}

			$outcome = [ 'skipped', 'changed_while_aligning', '' ];
		} finally {
			$this->backend->release( $ids );
		}

		// After release: a SET that found the mirror lock taken but the "aligning" lock already free left
		// no marker; its write landed before the release, so this read sees it.
		if ( null === $outcome ) {
			try {
				$outcome = self::all_same( $this->backend->read( $ids ), $ids ) ? null : [ 'skipped', 'changed_while_aligning', '' ];
			} catch ( \RuntimeException $e ) {
				$outcome = [ 'skipped', 'changed_while_aligning', '' ];
			}
		}

		$rows = [];

		foreach ( $written as $entry ) {
			$rows[] = [
				'status'     => 'changed',
				'group'      => (int) $group['group'],
				'product_id' => $entry['product_id'],
				'parent_id'  => $entry['parent_id'],
				'language'   => $entry['language'],
				'old'        => $entry['old'],
				'new'        => $entry['new'],
				'reference'  => $plan['reference'],
			];
		}

		if ( null !== $outcome ) {
			$rows[] = $this->left( $group, $outcome[0], $outcome[1], $outcome[2] );
		}

		foreach ( $written as $entry ) {
			$this->backend->aligned( $entry['product_id'], $entry['old'], $entry['new'], (int) $group['group'] );
		}

		return $rows;
	}

	/**
	 * One correction (relative, from the stock in $stock); a failure undoes the copies written before it.
	 *
	 * @param list<int>          $ids     Member ids.
	 * @param StockMember        $member  The member to write.
	 * @param array<int, float>  $stock   Member id => its stock now (updated).
	 * @param float              $value   The group's value (moves with an order's even move).
	 * @param list<StockWritten> $written Copies written so far (appended to).
	 * @return StockOutcome|false|null The group's outcome when it ends here, false when the stock moved unevenly, else null.
	 */
	private function write( array $ids, array $member, array &$stock, float &$value, array &$written ): array|false|null {
		$id    = (int) $member['product_id'];
		$delta = $value - $stock[ $id ];
		$shift = 0.0;

		try {
			$done = $this->put( $ids, $id, $delta, $stock, $shift );
		} catch ( StockWriteFailed $e ) {
			// The statement ran before the failure: its change is undone with the others.
			if ( null !== $e->written ) {
				$written[] = [
					'product_id' => $id,
					'parent_id'  => (int) $member['parent_id'],
					'language'   => (string) $member['language'],
					'old'        => $e->written - $delta,
					'new'        => $e->written,
					'delta'      => $delta,
				];

				$stock[ $id ] = $e->written;
			}

			return $this->undo( $ids, $written, $stock, $id, $e );
		}

		$value += $shift;

		if ( is_string( $done ) ) {
			return 'lost' === $done ? [ 'skipped', 'changed_while_aligning', '' ] : false;
		}

		$written[] = [
			'product_id' => $id,
			'parent_id'  => (int) $member['parent_id'],
			'language'   => (string) $member['language'],
			'old'        => $done[0],
			'new'        => $done[1],
			'delta'      => $delta,
		];

		return null;
	}

	/**
	 * Renew the locks, then write $delta only while $id still holds $stock[ $id ]. An even move of every copy
	 * (an order and its mirror) is taken into $stock and $shift and tried again; any other change stops.
	 *
	 * @param list<int>         $ids   Member ids.
	 * @param int               $id    The member to write.
	 * @param float             $delta Signed change.
	 * @param array<int, float> $stock Member id => its stock now (updated).
	 * @param float             $shift Sum of the even moves taken (updated).
	 * @return array{0: float, 1: float}|string [ stock before, stock after ], or 'lost' (a lock) or 'moved'.
	 * @throws StockWriteFailed When the write failed.
	 */
	private function put( array $ids, int $id, float $delta, array &$stock, float &$shift ): array|string {
		for ( $try = 0; $try < self::MOVE_TRIES; ++$try ) {
			if ( ! $this->backend->renew( $ids ) ) {
				return 'lost';
			}

			$old = $stock[ $id ];
			$new = $this->backend->write( $id, $delta, $old );

			if ( null !== $new ) {
				$stock[ $id ] = $new;

				return [ $old, $new ];
			}

			$move = self::even_move( $stock, $this->backend->read( $ids ) );

			if ( null === $move ) {
				return 'moved';
			}

			$shift += $move[0];
			$stock  = $move[1];
		}

		return 'moved';
	}

	/**
	 * How far every member moved by the same amount since $stock, with the fresh stock; null when not even.
	 *
	 * @param array<int, float>        $stock Member id => its stock before.
	 * @param array<int, StockReadRow> $fresh Fresh read.
	 * @return array{0: float, 1: array<int, float>}|null
	 */
	private static function even_move( array $stock, array $fresh ): ?array {
		$move = null;
		$out  = [];

		foreach ( $stock as $id => $was ) {
			$read = $fresh[ $id ] ?? null;

			if ( null === $read || ! $read['managed'] || $read['opted_out'] || null === $read['stock'] ) {
				return null;
			}

			$move ??= $read['stock'] - $was;

			if ( ! self::same( $read['stock'], $was + $move ) ) {
				return null;
			}

			$out[ $id ] = $read['stock'];
		}

		return null === $move ? null : [ $move, $out ];
	}

	/**
	 * Member id => numeric stock of a fresh read (members without a number left out).
	 *
	 * @param array<int, StockReadRow> $now Fresh read.
	 * @return array<int, float>
	 */
	private static function numbers( array $now ): array {
		$out = [];

		foreach ( $now as $id => $read ) {
			if ( null !== $read['stock'] ) {
				$out[ $id ] = $read['stock'];
			}
		}

		return $out;
	}

	/**
	 * Undo the copies written so far, newest first, each after renewing the locks; undone ones leave $written.
	 *
	 * @param list<int>          $ids     Member ids.
	 * @param list<StockWritten> $written Copies written so far.
	 * @param array<int, float>  $stock   Member id => its stock now (updated).
	 * @param int                $failed  The copy whose write failed.
	 * @param StockWriteFailed   $error   Its failure.
	 * @return StockOutcome The group's outcome.
	 */
	private function undo( array $ids, array &$written, array &$stock, int $failed, StockWriteFailed $error ): array {
		while ( [] !== $written ) {
			$last  = $written[ count( $written ) - 1 ];
			$shift = 0.0;

			try {
				$done = $this->put( $ids, (int) $last['product_id'], -(float) $last['delta'], $stock, $shift );
			} catch ( StockWriteFailed $e ) {
				$done = 'moved';
			}

			if ( 'lost' === $done ) {
				return [ 'skipped', 'changed_while_aligning', '' ];
			}

			if ( 'moved' === $done ) {
				return [
					'failed',
					'undo_failed',
					sprintf(
						/* translators: 1: product or variation id whose stock could not be saved, 2: id whose stock could not be put back. */
						__( 'WooCommerce could not save the stock of #%1$d, and putting back #%2$d failed. Check this product\'s stock by hand.', 'perflocale' ),
						$failed,
						(int) $last['product_id']
					),
				];
			}

			array_pop( $written );
		}

		if ( $error->gone ) {
			return [ 'skipped', 'changed', '' ];
		}

		if ( $error->by_plugin ) {
			return [
				'failed',
				'write_failed',
				sprintf(
					/* translators: 1: product or variation id. */
					__( 'WooCommerce could not save the stock of #%1$d (a plugin stopped the change). The other copies were put back.', 'perflocale' ),
					$failed
				),
			];
		}

		return [
			'failed',
			'write_failed',
			sprintf(
				/* translators: 1: product or variation id, 2: WooCommerce's or the database's message. */
				__( 'WooCommerce could not save the stock of #%1$d: %2$s. The other copies were put back.', 'perflocale' ),
				$failed,
				$error->getMessage()
			),
		];
	}

	/**
	 * Steps 6 and 7: replay a skipped SET, verify, release the mirror locks, replay a SET that came meanwhile.
	 *
	 * @param StockGroup         $group   A StockDriftScan group.
	 * @param list<int>          $ids     Member ids.
	 * @param list<StockWritten> $written Copies written so far (appended to).
	 * @return StockOutcome|null The group's outcome, null when it ends aligned.
	 */
	private function finish( array $group, array $ids, array &$written ): ?array {
		if ( ! $this->replay( $group, $ids, $written ) ) {
			return [ 'skipped', 'changed_while_aligning', '' ];
		}

		$aligned = self::all_same( $this->backend->read( $ids ), $ids );

		$this->backend->release_mirror( $ids );

		if ( [] === $this->backend->markers( $ids ) ) {
			return $aligned ? null : [ 'skipped', 'changed_while_aligning', '' ];
		}

		// A SET that arrived meanwhile: its replay and the read after it decide.
		if ( ! $this->backend->relock_mirror( $ids ) ) {
			return [ 'skipped', 'changed_while_aligning', '' ];
		}

		try {
			if ( ! $this->replay( $group, $ids, $written ) ) {
				return [ 'skipped', 'changed_while_aligning', '' ];
			}

			return self::all_same( $this->backend->read( $ids ), $ids ) ? null : [ 'skipped', 'changed_while_aligning', '' ];
		} finally {
			$this->backend->release_mirror( $ids );
		}
	}

	/**
	 * Mirror the newest marked member's stored stock to the others (relative writes after renewing the locks),
	 * then clear the markers read.
	 *
	 * @param StockGroup         $group   A StockDriftScan group.
	 * @param list<int>          $ids     Member ids.
	 * @param list<StockWritten> $written Copies written so far (appended to).
	 * @return bool False when a lock was lost or a write failed (the markers stay).
	 *
	 * @phpstan-impure
	 */
	private function replay( array $group, array $ids, array &$written ): bool {
		$marks = $this->backend->markers( $ids );

		if ( [] === $marks ) {
			return true;
		}

		$source = 0;
		$newest = -1;

		foreach ( $marks as $id => [ $at ] ) {
			if ( $at > $newest ) {
				$newest = $at;
				$source = $id;
			}
		}

		$now   = $this->backend->read( $ids );
		$value = isset( $now[ $source ] ) && $now[ $source ]['managed'] && ! $now[ $source ]['opted_out'] ? $now[ $source ]['stock'] : null;

		if ( null !== $value ) {
			$stock = [];

			foreach ( $now as $id => $read ) {
				if ( $read['managed'] && ! $read['opted_out'] && null !== $read['stock'] ) {
					$stock[ $id ] = $read['stock'];
				}
			}

			foreach ( $group['members'] as $member ) {
				$id = (int) $member['product_id'];

				if ( $id === $source || ! isset( $stock[ $id ] ) || self::same( $stock[ $id ], $value ) ) {
					continue;
				}

				if ( null !== $this->write_replay( $ids, $member, $stock, $value, $written ) ) {
					return false;
				}
			}
		}

		foreach ( $marks as $id => [ , $raw ] ) {
			$this->backend->clear_marker( $id, $raw );
		}

		return true;
	}

	/**
	 * One replay write: as write(), without undo (the SET being replayed is not ours to take back).
	 *
	 * @param list<int>          $ids     Member ids.
	 * @param StockMember        $member  The member to write.
	 * @param array<int, float>  $stock   Member id => its stock now (updated).
	 * @param float              $value   The SET's value (moves with an order's even move).
	 * @param list<StockWritten> $written Copies written so far (appended to).
	 * @return string|null Null when written, else why not.
	 */
	private function write_replay( array $ids, array $member, array &$stock, float &$value, array &$written ): ?string {
		$id    = (int) $member['product_id'];
		$delta = $value - $stock[ $id ];
		$shift = 0.0;

		try {
			$done = $this->put( $ids, $id, $delta, $stock, $shift );
		} catch ( StockWriteFailed $e ) {
			$this->backend->log( sprintf( 'PerfLocale stock alignment: replaying a stock change on #%d failed: %s', $id, $e->getMessage() ) );

			// The statement ran before the failure: the write stays reported.
			if ( null !== $e->written ) {
				$written[] = [
					'product_id' => $id,
					'parent_id'  => (int) $member['parent_id'],
					'language'   => (string) $member['language'],
					'old'        => $e->written - $delta,
					'new'        => $e->written,
					'delta'      => $delta,
				];
			}

			return 'failed';
		}

		$value += $shift;

		if ( is_string( $done ) ) {
			return $done;
		}

		$written[] = [
			'product_id' => $id,
			'parent_id'  => (int) $member['parent_id'],
			'language'   => (string) $member['language'],
			'old'        => $done[0],
			'new'        => $done[1],
			'delta'      => $delta,
		];

		return null;
	}

	/**
	 * Why a group's members read under the lock differ from its plan: '' when they match.
	 *
	 * @param list<StockMember>             $members   The plan's members.
	 * @param array<int, StockReadRow>      $now       Fresh read.
	 * @param int                                                                   $reference Reference member id (0: a callable).
	 * @param array<int, float>|null                                                $expected  Member id => stock the user saw.
	 * @return string
	 */
	public static function recheck( array $members, array $now, int $reference, ?array $expected ): string {
		$copy = null;

		foreach ( $members as $member ) {
			$read = $now[ (int) $member['product_id'] ] ?? null;

			if ( null === $read || ! $read['managed'] || $read['opted_out'] || null === $read['stock'] ) {
				return 'changed';
			}

			// Still copies of one item: the same parent, translation group and variation attributes.
			if ( $read['parent'] !== (int) $member['parent_id'] || null === $read['copy'] || ( null !== $copy && $read['copy'] !== $copy ) ) {
				return 'changed';
			}

			$copy = $read['copy'];
		}

		foreach ( $members as $member ) {
			$id = (int) $member['product_id'];

			if ( $id === $reference && ! self::same( (float) $now[ $id ]['stock'], (float) $member['stock'] ) ) {
				return 'reference_changed';
			}
		}

		// The plan came from the scanned stock: every copy must still hold it (a SET in between keeps its value).
		foreach ( $members as $member ) {
			$id = (int) $member['product_id'];

			if ( ! self::same( (float) $now[ $id ]['stock'], (float) $member['stock'] ) ) {
				return 'changed';
			}
		}

		if ( null === $expected ) {
			return '';
		}

		$current = [];

		foreach ( $members as $member ) {
			$current[ $member['product_id'] ] = (float) ( $now[ $member['product_id'] ]['stock'] ?? 0.0 );
		}

		return self::against_expected( $current, $expected, $reference );
	}

	/**
	 * Whether the members and stock are what the user saw: '', `reference_changed` or `changed`.
	 *
	 * @param array<int, float> $current   Member id => its stock.
	 * @param array<int, float> $expected  Member id => stock the user saw.
	 * @param int               $reference Reference member id (0: none).
	 * @return string
	 */
	public static function against_expected( array $current, array $expected, int $reference ): string {
		$ids = $current;

		if ( isset( $ids[ $reference ], $expected[ $reference ] ) && ! self::same( $ids[ $reference ], (float) $expected[ $reference ] ) ) {
			return 'reference_changed';
		}

		if ( count( $ids ) !== count( $expected ) ) {
			return 'changed';
		}

		foreach ( $expected as $id => $stock ) {
			if ( ! isset( $ids[ $id ] ) || ! self::same( $ids[ $id ], (float) $stock ) ) {
				return 'changed';
			}
		}

		return '';
	}

	/**
	 * The members' stock: id => stock.
	 *
	 * @param list<StockMember> $members Members.
	 * @return array<int, float>
	 */
	private static function stocks( array $members ): array {
		$out = [];

		foreach ( $members as $member ) {
			$out[ $member['product_id'] ] = $member['stock'];
		}

		return $out;
	}

	/**
	 * Why the reference language has no member: why its copy was left out, or `reference_missing`.
	 *
	 * @param StockGroup $group A StockDriftScan group.
	 * @param string     $slug  Reference language slug.
	 * @return string
	 */
	private static function excluded_reason( array $group, string $slug ): string {
		if ( '' !== $slug ) {
			foreach ( $group['excluded'] as $left ) {
				if ( $left['language'] === $slug ) {
					return match ( $left['reason'] ) {
						'opted_out'   => 'reference_opted_out',
						'unmanaged'   => 'reference_unmanaged',
						default       => 'reference_not_numeric',
					};
				}
			}
		}

		return 'reference_missing';
	}

	/**
	 * A skipped or failed result row.
	 *
	 * @param StockGroup $group  A StockDriftScan group.
	 * @param string     $status skipped or failed.
	 * @param string     $code   Skip or failure code.
	 * @param string     $reason The reason text ('' for the code's own text).
	 * @return StockRow
	 */
	private function left( array $group, string $status, string $code, string $reason = '' ): array {
		return [
			'status'     => $status,
			'group'      => (int) $group['group'],
			'product_id' => (int) $group['group'],
			'parent_id'  => (int) $group['parent_id'],
			'language'   => '',
			'code'       => $code,
			'reason'     => '' !== $reason ? $reason : self::reason( $code ),
		];
	}

	/**
	 * The text of a skip code.
	 *
	 * @param string $code Skip code.
	 * @return string
	 */
	public static function reason( string $code ): string {
		return match ( $code ) {
			'reference_missing'      => __( 'No copy in the reference language.', 'perflocale' ),
			'reference_unmanaged'    => __( 'The reference copy does not manage stock.', 'perflocale' ),
			'reference_opted_out'    => __( 'The reference copy is set to "Independent across languages".', 'perflocale' ),
			'reference_not_numeric'  => __( 'The reference copy has no stock number.', 'perflocale' ),
			'reference_changed'      => __( 'The reference stock changed after the check. Check again.', 'perflocale' ),
			'no_value'               => __( 'No reference value was given.', 'perflocale' ),
			'busy'                   => __( 'Inventory Sync or another alignment is updating this product. Try again in a minute.', 'perflocale' ),
			'changed'                => __( 'A copy was edited, deleted or excluded after the check. Check again.', 'perflocale' ),
			'changed_while_aligning' => __( 'Stock changed while aligning, likely an order or an edit. Check again.', 'perflocale' ),
			default                  => '',
		};
	}

	/**
	 * A group as perflocale_stock_drift() returns it (no excluded members).
	 *
	 * @param StockGroup $group A StockDriftScan group.
	 * @return StockPublicGroup
	 */
	public static function public_group( array $group ): array {
		$members = [];

		foreach ( $group['members'] as $member ) {
			$members[] = self::public_member( $member );
		}

		return [
			'group'     => (int) $group['group'],
			'parent_id' => (int) $group['parent_id'],
			'members'   => $members,
		];
	}

	/**
	 * A member as perflocale_stock_drift() returns it.
	 *
	 * @param StockMember $member A StockDriftScan member.
	 * @return StockMember
	 */
	private static function public_member( array $member ): array {
		return [
			'product_id' => (int) $member['product_id'],
			'parent_id'  => (int) $member['parent_id'],
			'language'   => (string) $member['language'],
			'stock'      => (float) $member['stock'],
		];
	}

	/**
	 * Whether every id was read with the same numeric stock.
	 *
	 * @param array<int, StockReadRow> $now Fresh read.
	 * @param list<int>                $ids Member ids.
	 * @return bool
	 */
	private static function all_same( array $now, array $ids ): bool {
		$first = null;

		foreach ( $ids as $id ) {
			$stock = $now[ $id ]['stock'] ?? null;

			if ( null === $stock ) {
				return false;
			}

			if ( null === $first ) {
				$first = $stock;
			} elseif ( ! self::same( $first, $stock ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether two stock values are the same number, as the scan compares them ("5" equals "5.0").
	 *
	 * @param float $a Value.
	 * @param float $b Value.
	 * @return bool
	 */
	public static function same( float $a, float $b ): bool {
		return (string) $a === (string) $b;
	}

	/**
	 * Whether a value is a finite int or float.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 *
	 * @phpstan-assert-if-true int|float $value
	 */
	private static function is_number( mixed $value ): bool {
		return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
	}
}
