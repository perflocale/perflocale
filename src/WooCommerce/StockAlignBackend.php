<?php
/**
 * WooCommerce stock alignment: what the alignment engine reads, locks and writes.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The store StockDriftAlign works on: group locks, fresh reads, WooCommerce's relative stock write,
 * set markers and the two public hooks. The site's store is WcStockAlignBackend; tests pass their own.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
interface StockAlignBackend {

	/**
	 * Take the stock change locks of the members' translation groups (StockChangeLock::hold()), then for every id
	 * (ascending) the "aligning" lock and the mirror's lock, then check the groups' fence rows. Never waits: when
	 * one is busy, or a fenced change is in flight, release the ones taken and return false.
	 *
	 * @param list<int> $ids Member ids, ascending.
	 * @return bool
	 */
	public function lock( array $ids ): bool;

	/**
	 * Renew every lock lock() took (a mirror lock only while held), confirm this process still owns each and that no
	 * change was fenced since lock().
	 *
	 * @param list<int> $ids Member ids.
	 * @return bool False when one was lost (expired and taken by another process).
	 */
	public function renew( array $ids ): bool;

	/**
	 * Release the mirror locks only; the "aligning" locks stay held.
	 *
	 * @param list<int> $ids Member ids.
	 * @return void
	 */
	public function release_mirror( array $ids ): void;

	/**
	 * Take the mirror locks again (after release_mirror()). Never waits.
	 *
	 * @param list<int> $ids Member ids, ascending.
	 * @return bool
	 */
	public function relock_mirror( array $ids ): bool;

	/**
	 * Release every lock still held for these ids.
	 *
	 * @param list<int> $ids Member ids.
	 * @return void
	 */
	public function release( array $ids ): void;

	/**
	 * Read the members' stock afresh (no cache); stock is null when not numeric, a missing id is left out.
	 * `copy` names the item a member is a copy of (translation group, and for a variation its attributes):
	 * equal for copies of one item, null when unknown.
	 *
	 * @param list<int> $ids Member ids.
	 * @return array<int, array{stock: float|null, managed: bool, opted_out: bool, parent: int, copy: string|null}>
	 * @throws \RuntimeException When the read fails.
	 */
	public function read( array $ids ): array;

	/**
	 * Change one member's stock by $delta with WooCommerce's relative write, the mirror suspended for that id,
	 * only while its stock is $expect (checked in the statement).
	 *
	 * @param int   $id     Product or variation id.
	 * @param float $delta  Signed change (never 0).
	 * @param float $expect Its stock as last read.
	 * @return float|null The stock its own statement set; null when the stock was not $expect (nothing written).
	 * @throws StockWriteFailed When the write failed or the product is gone.
	 * @throws StockLeaseLost When the group's locks could not be renewed right before the statement (nothing written).
	 */
	public function write( int $id, float $delta, float $expect ): ?float;

	/**
	 * The set markers of these ids: id => [ set time, stored value ].
	 *
	 * @param list<int> $ids Member ids.
	 * @return array<int, array{0: int, 1: string}>
	 */
	public function markers( array $ids ): array;

	/**
	 * Remove one marker if it still holds the value read.
	 *
	 * @param int    $id    Member id.
	 * @param string $value Stored value from markers().
	 * @return void
	 */
	public function clear_marker( int $id, string $value ): void;

	/**
	 * A value as WooCommerce stores a stock amount (wc_stock_amount()).
	 *
	 * @param float $value Value.
	 * @return float
	 */
	public function stock_amount( float $value ): float;

	/**
	 * The filter perflocale/woocommerce/stock_align_value.
	 *
	 * @param float                     $value     The reference value.
	 * @param array<string, mixed>      $group     One perflocale_stock_drift() entry.
	 * @param array<string, mixed>|null $reference The reference member; null for a callable.
	 * @return mixed
	 */
	public function filter_value( float $value, array $group, ?array $reference ): mixed;

	/**
	 * The action perflocale/woocommerce/stock_aligned.
	 *
	 * @param int   $product_id Product or variation written.
	 * @param float $old        Stock read under the lock before the write.
	 * @param float $new        Stock the write's own statement set.
	 * @param int   $group_id   The group's anchor id.
	 * @return void
	 */
	public function aligned( int $product_id, float $old, float $new, int $group_id ): void;

	/**
	 * Before release(): how this group's alignment ended, for a stock change a reconnect hid from it (StockChangeLock).
	 *
	 * @param int       $reference The member whose stock every member was set to (0: a callable or a filtered value).
	 * @param string    $state     'all' (every member holds it), 'none' (no write sent) or 'part' (a write sent: a lost reply may have landed it).
	 * @param list<int> $ids       The group's members.
	 * @return void
	 */
	public function settle( int $reference, string $state, array $ids ): void;

	/**
	 * Log an error the screen must not show (a callable's or hook's message), under WP_DEBUG only.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function log( string $message ): void;
}
