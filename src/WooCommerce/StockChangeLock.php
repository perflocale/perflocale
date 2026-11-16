<?php
/**
 * WooCommerce inventory sync: a server lock per translation group while a stock change is in flight.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A mirrored stock change holds one of SLOTS named locks of the translation group its mirror writes, from
 * before_set_stock until its mirror ran; a stock alignment takes every slot without waiting, so it never reads a
 * half-mirrored group (Codex C24-W1). Loaded at the first mirrored stock change.
 * See docs-ai/11-code-rationale.md, "StockChangeLock".
 *
 * @internal Internal to PerfLocale; not a public API.
 *
 * @since 1.0.8
 *
 * @phpstan-type Fence array{row: string, lock: string, table: string}
 * @phpstan-type LockEntry array{id: int, group: int, name: string, ref: \WeakReference<object>, taken: bool, statement: bool, handle: object|null, fence: Fence|null, own: bool, epoch: string|null, stmt: object|null, doubt: string, aligned: list<int>|null, pre: object|null, rerun: bool, failed: bool, watch: array{0: int, 1: string, 2: bool}|null, observe: bool, blog: int, stack: int|null, depth: int}
 */
final class StockChangeLock {

	/**
	 * Seconds one request's stock changes wait in total when every slot is busy; then they run fenced (never fail).
	 */
	public const WAIT = 2;

	/**
	 * Option name prefix of a fence row: `<prefix><group>_<token>` => `<liveness lock or ''>|<unix time>`.
	 */
	public const FENCE = '_perflocale_stkx_';

	/**
	 * Option name of the blog's fence generation, bumped after each fence row is written.
	 */
	public const GEN = '_perflocale_stkgen';

	/**
	 * Slot names per group: up to this many changes of one group run at once (a shared lock; Align takes them all).
	 */
	public const SLOTS = 16;

	/**
	 * Option name of the blog's alignment epoch, `<n>|<group>|<reference>|<state>|<member,...>`: each alignment of a
	 * group bumps n while it holds every slot, and records its reference, outcome and members before it lets them go.
	 */
	public const ALIGNED = '_perflocale_stkalign';

	/**
	 * Drains a kept flag gets before it is given up (a throw each time).
	 */
	private const DRAIN_TRIES = 3;

	/**
	 * Stock hooks find() runs in: they place an alignment's own write on the hook stack (own_level()).
	 */
	private const STOCK_HOOKS = [
		'woocommerce_product_before_set_stock'   => true,
		'woocommerce_variation_before_set_stock' => true,
		'woocommerce_product_set_stock'          => true,
		'woocommerce_variation_set_stock'        => true,
		'woocommerce_update_product'             => true,
		'woocommerce_update_product_variation'   => true,
		'woocommerce_new_product'                => true,
		'woocommerce_new_product_variation'      => true,
	];

	/**
	 * Open stock changes of this process, oldest first.
	 *
	 * @var list<LockEntry>
	 */
	private static array $open = [];

	/**
	 * Names an alignment of this process holds (hold()).
	 *
	 * @var array<string, true>
	 */
	private static array $held = [];

	/**
	 * Whether this server has usable named locks; null until asked.
	 *
	 * @var bool|null
	 */
	private static ?bool $available = null;

	/**
	 * Name prefix per table prefix (md5 of DB name and table prefix).
	 *
	 * @var array<string, string>
	 */
	private static array $prefixes = [];

	/**
	 * Whether the release callbacks are attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Whether release_all() is registered for shutdown.
	 *
	 * @var bool
	 */
	private static bool $shutdown = false;

	/**
	 * Seconds this request (or queued action) may still wait for a slot.
	 *
	 * @var int
	 */
	private static int $budget = self::WAIT;

	/**
	 * Whether the server logs this session's statements as statements (binlog_format STATEMENT): a replica would run
	 * a lock-guarded write without the lock and skip it, so no write carries a lock guard.
	 *
	 * @var bool
	 */
	private static bool $statement_log = false;

	/**
	 * Changes released without their mirror (a throw skipped set_stock) or judged unknown: [ blog id, stock id, tries ],
	 * flagged by drain() once no statement is in flight.
	 *
	 * @var list<array{0: int, 1: int, 2: int}>
	 */
	private static array $unmirrored = [];

	/**
	 * Whether this server has named locks that a session can hold several of (MySQL 5.7.5+, MariaDB 10.0.2+).
	 * SQLite answers GET_LOCK with '1=1' and locks nothing, so it is never asked.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		if ( null !== self::$available ) {
			return self::$available;
		}

		$wpdb = self::db();

		if ( ( defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) ) || null === $wpdb ) {
			self::$available = false;

			return false;
		}

		// MariaDB with PHP 8.1.0-8.1.2 reports "5.5.5-10.x": strip it as wpdb::has_cap() does (db_version() does not).
		$info    = $wpdb->db_server_info();
		$version = (string) preg_replace( '/[^0-9.].*/', '', (string) preg_replace( '/^5\.5\.5-/', '', $info ) );

		if ( '' === $version ) {
			return false;
		}

		// MariaDB reports 10.x and later; 10.0.0-10.0.1 still had one lock per session.
		self::$available = version_compare( $version, '5.7.5', '>=' ) && ! ( version_compare( $version, '10.0', '>=' ) && version_compare( $version, '10.0.2', '<' ) );

		return self::$available;
	}

	/**
	 * One slot's lock name for a translation group of the current blog: at most 63 characters (GET_LOCK allows 64).
	 *
	 * @param int $group Translation group id.
	 * @param int $slot  Slot, 0 to SLOTS - 1.
	 * @return string
	 */
	public static function name( int $group, int $slot = 0 ): string {
		return self::base() . $group . '_' . $slot;
	}

	/**
	 * A stock change starts (sync on): take() when the mirror copies it, that is the product (a variation's parent)
	 * has a language copy and is not opted out, and it is not an alignment's own write (own_write(): the object it
	 * passed); a listener's nested change of that id (another object) is a normal change. Without named locks: observe().
	 * Keyed by the group the mirror will write (the same cached read), so a relink meanwhile changes nothing.
	 *
	 * @param int                         $id        Stock id.
	 * @param object                      $product   The product or variation (WC_Product).
	 * @param InventorySync               $sync      The stock mirror.
	 * @param array<int, array<int, int>> $suspended The mirror's suspended ids, blog id => id => depth.
	 * @param int|null                    $anchor    Product (a variation's parent), when the caller read it.
	 * @param array<mixed>|null           $links     The anchor's language links, when the caller read them.
	 * @return bool Whether a lock was asked for.
	 */
	public static function begin( int $id, object $product, InventorySync $sync, array $suspended, ?int $anchor = null, ?array $links = null ): bool {
		if ( isset( $suspended[ get_current_blog_id() ][ $id ] ) && self::own_open( $product ) ) {
			return false;
		}

		$anchor ??= 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : $id;
		$links  ??= $anchor > 0 ? \PerfLocale\Plugin::get_instance()->group_repo()->get_translations( $anchor, \PerfLocale\Enum\ObjectType::Post ) : [];
		$first  = reset( $links );
		$group  = is_object( $first ) && is_numeric( $first->group_id ?? null ) ? (int) $first->group_id : 0;

		$parent = $anchor !== $id ? $anchor : 0;

		// A kept "opted out" is read again as stored before it skips the lock (a failed read takes it); a kept "not
		// opted out" only takes one the mirror's own stored check may not use.
		if ( count( $links ) < 2 || $group <= 0 || ( $sync->is_sync_opted_out( $id, $parent ) && $sync->is_sync_opted_out( $id, $parent, self::stored_flags( [ $id, $parent ] ) ) ) ) {
			return false;
		}

		if ( ! self::available() ) {
			self::observe( $id, $group, $product );

			return false;
		}

		self::take( $id, $group, $product );

		return true;
	}

	/**
	 * Flags as stored (OptOutMemo::read()); a failed read answers "not opted out", so begin() takes the lock.
	 *
	 * @param array<int, int> $ids Post ids.
	 * @return array<int, bool>
	 */
	private static function stored_flags( array $ids ): array {
		require_once __DIR__ . '/OptOutMemo.php';

		return OptOutMemo::read( $ids ) ?? [];
	}

	/**
	 * A mirrored stock change of $id starts: take a free slot of its group, else (after the request's wait budget)
	 * fence it, so an alignment still sees it; a refused fence runs the change anyway (a checkout never fails).
	 *
	 * @param int    $id      Stock id.
	 * @param int    $group   Translation group its mirror writes.
	 * @param object $product The changing product object (its closers match it).
	 * @return void
	 */
	public static function take( int $id, int $group, object $product ): void {
		self::sweep();

		[ $name, $taken, $epoch ] = self::slot( $group, self::WAIT );
		self::$open[]             = [
			'id'        => $id,
			'group'     => $group,
			'name'      => $name,
			'ref'       => \WeakReference::create( $product ),
			'taken'     => $taken,
			'statement' => false,
			'handle'    => self::handle(),
			'fence'     => $taken ? null : self::fence( $group ),
			'own'       => false,
			// Read from a busy slot while an alignment ran: no baseline (dead before it settled, it leaves the epoch as read).
			'epoch'     => $taken || ! str_ends_with( (string) $epoch, '|run' ) ? $epoch : null,
			'stmt'      => null,
			'doubt'     => '',
			'aligned'   => null,
			'pre'       => null,
			'rerun'     => false,
			'failed'    => false,
			'watch'     => null,
			'observe'   => false,
			'blog'      => get_current_blog_id(),
			'stack'     => null,
			'depth'     => 0,
		];

		self::hooks();
	}

	/**
	 * A mirrored change where no named lock is asked for (SQLite, an older server): its frame only watches WooCommerce's
	 * statement (failed(), rerun()); it holds nothing and is never retaken.
	 *
	 * @param int    $id      Stock id.
	 * @param int    $group   Translation group its mirror writes.
	 * @param object $product The changing product object.
	 * @return void
	 */
	private static function observe( int $id, int $group, object $product ): void {
		self::sweep();
		self::$open[] = [
			'id'        => $id,
			'group'     => $group,
			'name'      => '',
			'ref'       => \WeakReference::create( $product ),
			'taken'     => false,
			'statement' => false,
			'handle'    => self::handle(),
			'fence'     => null,
			'own'       => false,
			'epoch'     => null,
			'stmt'      => null,
			'doubt'     => '',
			'aligned'   => null,
			'pre'       => null,
			'rerun'     => false,
			'failed'    => false,
			'watch'     => null,
			'observe'   => true,
			'blog'      => get_current_blog_id(),
			'stack'     => null,
			'depth'     => 0,
		];

		self::hooks();
	}

	/**
	 * Whether the mirror skips this set_stock: the object's newest change is an alignment's own write; with no
	 * change of it open (no frame was opened), whether its id is suspended.
	 *
	 * @param object                      $product   The product or variation whose stock changed.
	 * @param mixed                       $id        Its id.
	 * @param array<int, array<int, int>> $suspended The mirror's suspended ids, blog id => id => depth.
	 * @return bool
	 */
	public static function own( object $product, mixed $id, array $suspended ): bool {
		return null === self::find( $product, false ) ? is_int( $id ) && isset( $suspended[ get_current_blog_id() ][ $id ] ) : self::own_open( $product );
	}

	/**
	 * An alignment's own write of this object starts (before wc_update_product_stock()): its frame holds no lock, and
	 * its set_stock is not mirrored (own()). Bound to the object and to this hook depth (find()): a listener's nested
	 * change, even of this object, runs one hook deeper and opens a normal frame.
	 *
	 * @param int    $id      Stock id.
	 * @param object $product The object it passes to wc_update_product_stock().
	 * @return void
	 */
	public static function own_write( int $id, object $product ): void {
		self::$open[] = [
			'id'        => $id,
			'group'     => 0,
			'name'      => '',
			'ref'       => \WeakReference::create( $product ),
			'taken'     => false,
			'statement' => false,
			'handle'    => null,
			'fence'     => null,
			'own'       => true,
			'epoch'     => null,
			'stmt'      => null,
			'doubt'     => '',
			'aligned'   => null,
			'pre'       => null,
			'rerun'     => false,
			'failed'    => false,
			'watch'     => null,
			'observe'   => false,
			'blog'      => get_current_blog_id(),
			'stack'     => null,
			'depth'     => self::stack(),
		];

		self::hooks();
	}

	/**
	 * An alignment's write ended (also by a throw before its set_stock): drop its own frame.
	 *
	 * @param object $product The object it passed to wc_update_product_stock().
	 * @return void
	 */
	public static function drop_own( object $product ): void {
		self::$open = array_values( array_filter( self::$open, static fn( array $entry ): bool => ! $entry['own'] || $entry['ref']->get() !== $product ) );
	}

	/**
	 * Attach the release callbacks once.
	 *
	 * @return void
	 */
	private static function hooks(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_filter( 'woocommerce_update_product_stock_query', [ self::class, 'nested' ], PHP_INT_MIN, 1 );
		add_filter( 'woocommerce_update_product_stock_query', [ self::class, 'before_statement' ], PHP_INT_MAX, 2 );
		add_action( 'switch_blog', [ self::class, 'switched' ], 10, 0 );
		add_action( 'woocommerce_updated_product_stock', [ self::class, 'note_statement' ], PHP_INT_MIN, 1 );

		// Inventory Sync's mirror runs at 10: retake a lost slot at 9, release at 11.
		add_action( 'woocommerce_product_set_stock', [ self::class, 'before_mirror' ], 9, 1 );
		add_action( 'woocommerce_variation_set_stock', [ self::class, 'before_mirror' ], 9, 1 );
		add_action( 'woocommerce_product_set_stock', [ self::class, 'end' ], 11, 1 );
		add_action( 'woocommerce_variation_set_stock', [ self::class, 'end' ], 11, 1 );

		// A CRUD save whose stock write changed nothing fires no set_stock.
		foreach ( [ 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_new_product', 'woocommerce_new_product_variation' ] as $hook ) {
			add_action( $hook, [ self::class, 'end_save' ], 10, 2 );
		}

		// A throw inside one queued action must not hold a lock for the rest of the queue run.
		add_action( 'action_scheduler_after_execute', [ self::class, 'end_changes' ], 10, 0 );
		add_action( 'action_scheduler_failed_execution', [ self::class, 'end_changes' ], 10, 0 );

		self::on_shutdown();
	}

	/**
	 * WooCommerce's stock statement for this id is about to run (last filter): the handle it starts on, and a watch on
	 * that statement (after_statement()).
	 *
	 * @param mixed $sql        WooCommerce's statement.
	 * @param mixed $product_id Stock id.
	 * @return mixed The statement, unchanged.
	 */
	public static function before_statement( mixed $sql, mixed $product_id = 0 ): mixed {
		$id = is_numeric( $product_id ) ? (int) $product_id : 0;
		$at = $id > 0 ? self::newest( $id ) : null;

		if ( null !== $at && ! self::$open[ $at ]['statement'] ) {
			self::$open[ $at ]['pre']   = self::handle();
			self::$open[ $at ]['watch'] = [ self::depth(), is_string( $sql ) ? $sql : '', false ];
			self::$open[ $at ]['stack'] = self::stack();
			add_filter( 'query', [ self::class, 'after_statement' ], PHP_INT_MIN, 1 );
		}

		return $sql;
	}

	/**
	 * Each query while a statement is watched (first `query` callback, before another one can run a nested query): the
	 * watched statement itself (its SQL: another callback of the stock filter may query first), then the next query at
	 * its level reads its error. Nested queries inside another callback are deeper and skipped.
	 *
	 * @param mixed $sql The next statement.
	 * @return mixed The statement, unchanged.
	 */
	public static function after_statement( mixed $sql ): mixed {
		$depth = self::depth();
		$left  = false;

		foreach ( self::$open as $at => $entry ) {
			$watch = $entry['watch'];

			if ( null === $watch ) {
				continue;
			}

			if ( $watch[2] && $depth <= $watch[0] + 1 ) {
				self::$open[ $at ]['failed'] = $entry['failed'] || '' !== (string) self::db()?->last_error;
				self::$open[ $at ]['watch']  = null;
				continue;
			}

			if ( ! $watch[2] && $sql === $watch[1] ) {
				self::$open[ $at ]['watch'] = [ $watch[0], $watch[1], true ];
			}

			$left = true;
		}

		if ( ! $left ) {
			remove_filter( 'query', [ self::class, 'after_statement' ], PHP_INT_MIN );
		}

		return $sql;
	}

	/**
	 * Another stock statement starts or the blog switches while a change's statement is in flight (armed, not yet
	 * noted): every open change is unknown (failed), whatever the nested activity was (another plugin's work).
	 *
	 * @param mixed $value Filter value, unchanged.
	 * @return mixed
	 */
	public static function nested( mixed $value ): mixed {
		self::switched();

		return $value;
	}

	/**
	 * The blog switches (switch_blog), or nested(): while a statement is in flight, every open change is unknown.
	 *
	 * @return void
	 */
	public static function switched(): void {
		foreach ( self::$open as $entry ) {
			if ( null !== $entry['stack'] && ! $entry['statement'] ) {
				foreach ( array_keys( self::$open ) as $at ) {
					self::$open[ $at ]['failed'] = true;
				}

				return;
			}
		}
	}

	/**
	 * How many hooks run now: arming and noting a statement sit at the same level; a throw leaves an extra one.
	 *
	 * @return int
	 */
	private static function stack(): int {
		global $wp_current_filter;

		return is_array( $wp_current_filter ) ? count( $wp_current_filter ) : 0;
	}

	/**
	 * How many `query` filters run now: a query run inside another query's filter is one deeper.
	 *
	 * @return int
	 */
	private static function depth(): int {
		global $wp_current_filter;

		return is_array( $wp_current_filter ) ? count( array_keys( $wp_current_filter, 'query', true ) ) : 0;
	}

	/**
	 * WooCommerce's stock statement for this id ran: its save comes before set_stock, so end_save() keeps the entry.
	 * A new handle since before_statement(): wpdb reconnected inside it and may have run it twice (rerun()).
	 *
	 * @param mixed $product_id Stock id.
	 * @return void
	 */
	public static function note_statement( mixed $product_id ): void {
		for ( $i = count( self::$open ) - 1; $i >= 0; --$i ) {
			if ( is_numeric( $product_id ) && self::$open[ $i ]['id'] === (int) $product_id && self::$open[ $i ]['blog'] === get_current_blog_id() ) {
				$watch = self::$open[ $i ]['watch'];

				// Still watched: no query since (wpdb holds its error); never seen run: unknown, so failed.
				if ( null !== $watch ) {
					self::$open[ $i ]['failed'] = self::$open[ $i ]['failed'] || ! $watch[2] || '' !== (string) self::db()?->last_error;
					self::$open[ $i ]['watch']  = null;
				}

				// A throw caught since arming left a hook on the stack: what ran in between is unknown.
				if ( null !== self::$open[ $i ]['stack'] && self::stack() !== self::$open[ $i ]['stack'] ) {
					self::$open[ $i ]['failed'] = true;
				}

				self::$open[ $i ]['statement'] = true;
				self::$open[ $i ]['stmt']      = self::handle();
				self::$open[ $i ]['rerun']     = null !== self::$open[ $i ]['pre'] && self::$open[ $i ]['pre'] !== self::$open[ $i ]['stmt'];

				return;
			}
		}
	}

	/**
	 * Whether WooCommerce's own stock statement of $id's open change ran across a reconnect (applied once or twice).
	 *
	 * @param int $id Stock id.
	 * @return bool
	 */
	public static function rerun( int $id ): bool {
		$at = self::newest( $id );

		return null !== $at && self::$open[ $at ]['rerun'];
	}

	/**
	 * Whether WooCommerce's own stock statement of $id's open change failed (core goes on: the copies get a change the
	 * source never got).
	 *
	 * @param int $id Stock id.
	 * @return bool
	 */
	public static function failed( int $id ): bool {
		$at = self::newest( $id );

		return null !== $at && self::$open[ $at ]['failed'];
	}

	/**
	 * After the mirror (set_stock): end this object's newest change.
	 *
	 * @param mixed $product The product or variation.
	 * @return void
	 */
	public static function end( mixed $product ): void {
		if ( is_object( $product ) ) {
			self::close( $product, false );
		}
	}

	/**
	 * After a save: end this object's newest change whose statement did not run. Another object's save ends nothing.
	 *
	 * @param mixed $product_id Saved product or variation id (unused: the object decides).
	 * @param mixed $product    The saved object.
	 * @return void
	 */
	public static function end_save( mixed $product_id, mixed $product = null ): void {
		if ( is_object( $product ) ) {
			self::close( $product, true );
		}
	}

	/**
	 * Before the mirror (set_stock 9): after a reconnect noticed since the change started (a new handle), or a slot that
	 * timed out, make the change visible again and judge it (regain()). A killed session keeps its handle until its
	 * next statement: the mirror's guarded writes notice that one (guard()).
	 *
	 * @param mixed $product The product or variation whose stock changed.
	 * @return void
	 */
	public static function before_mirror( mixed $product ): void {
		$at = is_object( $product ) ? self::find( $product, false ) : null;

		if ( null === $at || self::$open[ $at ]['own'] || self::$open[ $at ]['observe'] ) {
			return;
		}

		$entry  = self::$open[ $at ];
		$handle = self::handle();

		// A fence row outlives a reconnect; its liveness lock does not.
		if ( null !== $entry['fence'] ) {
			if ( $entry['handle'] !== $handle ) {
				self::judged( $at, self::regain_at( $at, [ $entry['id'] ], [] ) );
			}

			return;
		}

		// HyperDB/LudicrousDB move dbh between datasets without a reconnect: same handle, same session, nothing asked.
		if ( $entry['taken'] && $entry['handle'] === $handle ) {
			return;
		}

		// Neither slot nor fence: alignments could not see its statement; judged as one that ran after a reconnect.
		if ( ! $entry['taken'] ) {
			[ $entry['name'], $entry['taken'] ] = self::slot( $entry['group'], 0 );
			$entry['handle']                    = self::handle();
			self::$open[ $at ]                  = $entry;

			self::judged( $at, $entry['taken'] ? self::judge( $at, false, [ $entry['id'] ], [] ) : 'flag' );

			return;
		}

		self::judged( $at, self::regain_at( $at, [ $entry['id'] ], [] ) );
	}

	/**
	 * The lock the mirror's writes of $id's open change must still hold on their own session ('' when none):
	 * `AND IS_USED_LOCK(...) = CONNECTION_ID()` in the same statement, so a write rerun on a new session matches nothing.
	 *
	 * @param int $id Stock id.
	 * @return string
	 */
	public static function guard( int $id ): string {
		$at = self::newest( $id );

		if ( null === $at ) {
			return '';
		}

		$entry = self::$open[ $at ];

		if ( self::$statement_log ) {
			return '';
		}

		if ( null !== $entry['fence'] ) {
			return $entry['fence']['lock'];
		}

		return $entry['taken'] ? $entry['name'] : '';
	}

	/**
	 * A guarded write of $id's change matched nothing: 'held' (the session still holds its lock: the row is gone), else
	 * the change is visible again and judged (see doubt()).
	 *
	 * @param int       $id      Stock id.
	 * @param list<int> $holders Members already holding the change (the source, copies written before).
	 * @param list<int> $maybe   Members whose write a reconnect hid: committed or not.
	 * @return string
	 */
	public static function regain( int $id, array $holders, array $maybe = [] ): string {
		$at = self::newest( $id );

		if ( null === $at ) {
			return 'flag';
		}

		$doubt = self::regain_at( $at, $holders, $maybe );
		self::judged( $at, $doubt );

		return $doubt;
	}

	/**
	 * Keep a verdict on its change ('held' changes nothing).
	 *
	 * @param int    $at    Index in $open.
	 * @param string $doubt Verdict.
	 * @return void
	 */
	private static function judged( int $at, string $doubt ): void {
		if ( 'held' !== $doubt ) {
			self::$open[ $at ]['doubt'] = $doubt;
		}
	}

	/**
	 * See regain(): ask the server, retake a slot (else a fence), judge.
	 *
	 * @param int       $at      Index in $open.
	 * @param list<int> $holders Members already holding the change.
	 * @param list<int> $maybe   Members that may hold it.
	 * @return string
	 */
	private static function regain_at( int $at, array $holders, array $maybe ): string {
		$entry = self::$open[ $at ];

		if ( null !== $entry['fence'] ) {
			if ( '' !== $entry['fence']['lock'] && self::mine( $entry['fence']['lock'] ) ) {
				return 'held';
			}

			$doubt                       = self::refence( $entry['fence'] );
			self::$open[ $at ]['handle'] = self::handle();

			return $doubt;
		}

		if ( $entry['taken'] && self::mine( $entry['name'] ) ) {
			self::$open[ $at ]['handle'] = self::handle();

			return 'held';
		}

		$k1 = null !== $entry['stmt'] && $entry['stmt'] === $entry['handle'];

		// After the retake: a killed session is noticed, and replaced, by the first statement on it.
		[ $entry['name'], $entry['taken'] ] = self::slot( $entry['group'], 0 );
		$entry['handle']                    = self::handle();

		if ( ! $entry['taken'] ) {
			$entry['fence'] = self::fence( $entry['group'] );
		}

		self::$open[ $at ] = $entry;

		return self::judge( $at, $k1, $holders, $maybe );
	}

	/**
	 * How the mirror of $id's open change goes after a reconnect, for the members aligned(): '' as usual; 'skip' (an
	 * alignment set them to a reference holding it); 'all' (to one without it); 'maybe' (to one that may hold it);
	 * 'flag' (every member unknown). An unknown member is written for a sale, not for a restock, and flagged.
	 *
	 * @param int $id Stock id.
	 * @return string
	 */
	public static function doubt( int $id ): string {
		$at = self::newest( $id );

		return null === $at ? '' : self::$open[ $at ]['doubt'];
	}

	/**
	 * The members the judged alignment set (null: every member).
	 *
	 * @param int $id Stock id.
	 * @return list<int>|null
	 */
	public static function aligned( int $id ): ?array {
		$at = self::newest( $id );

		return null === $at ? null : ( self::$open[ $at ]['aligned'] ?? null );
	}

	/**
	 * Index of $id's newest open change of the current blog that is not an alignment's own write, or null.
	 *
	 * @param int $id Stock id.
	 * @return int|null
	 */
	private static function newest( int $id ): ?int {
		for ( $i = count( self::$open ) - 1; $i >= 0; --$i ) {
			if ( self::$open[ $i ]['id'] === $id && ! self::$open[ $i ]['own'] && self::$open[ $i ]['blog'] === get_current_blog_id() ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * After a lost slot was made visible again: compare the alignment epoch (on the writer) with the one read with the
	 * slot; a 'skip', 'all' or 'maybe' applies to the members the alignment recorded (aligned()).
	 *
	 * @param int       $at      Index in $open.
	 * @param bool      $k1      Its stock statement ran on the session that held the slot (so before any alignment).
	 * @param list<int> $holders Members holding the change before the reconnect.
	 * @param list<int> $maybe   Members that may hold it.
	 * @return string See doubt().
	 */
	private static function judge( int $at, bool $k1, array $holders, array $maybe ): string {
		$entry = self::$open[ $at ];
		$wpdb  = self::db();
		$now   = null === $wpdb ? null : self::row( $wpdb->options, self::ALIGNED );

		if ( null === $now || null === $entry['epoch'] ) {
			return 'flag';
		}

		if ( $now === $entry['epoch'] ) {
			return '';
		}

		$then = (int) explode( '|', $entry['epoch'] )[0];
		$now  = array_pad( explode( '|', $now ), 5, '' );

		if ( (int) $now[0] !== $then + 1 || (int) $now[1] <= 0 ) {
			return 'flag';
		}

		// The one alignment since aligned another group, or wrote nothing.
		if ( (int) $now[1] !== $entry['group'] || 'none' === $now[3] ) {
			return '';
		}

		$ids = '' === $now[4] ? [] : array_map( 'intval', explode( ',', $now[4] ) );

		if ( 'all' !== $now[3] || (int) $now[2] <= 0 || ! $k1 || [] === $ids ) {
			return 'flag';
		}

		self::$open[ $at ]['aligned'] = $ids;

		return in_array( (int) $now[2], $holders, true ) ? 'skip' : ( in_array( (int) $now[2], $maybe, true ) ? 'maybe' : 'all' );
	}

	/**
	 * A fenced change after a reconnect: retake its liveness lock, bump the generation, then its row must still be
	 * there. An alignment deletes a row whose lock it found free: before the read, the read sees it; after, its renew()
	 * sees the bump and it stops before its first write.
	 *
	 * @param Fence $fence The change's fence.
	 * @return string '' or 'flag'.
	 */
	private static function refence( array $fence ): string {
		$wpdb = self::db();

		if ( null === $wpdb ) {
			return 'flag';
		}

		if ( '' !== $fence['lock'] ) {
			self::get_lock( $fence['lock'], 0 );
		}

		self::bump_generation( $wpdb );

		$row = self::row( $fence['table'], $fence['row'] );

		return null === $row || '' === $row ? 'flag' : '';
	}

	/**
	 * One option row past the cache on a writable server: its value, '' when missing, null when unknown (an error, a
	 * read-only server such as a replica a proxy chose). The '=' keeps a missing row apart: get_var() answers '' as null.
	 *
	 * @param string $table Options table.
	 * @param string $name  Option name.
	 * @return string|null
	 */
	private static function row( string $table, string $name ): ?string {
		$got = self::ask( "SELECT /* pfl-writer */ IF(@@global.read_only OR @@global.innodb_read_only, NULL, CONCAT('=', COALESCE((SELECT option_value FROM %i WHERE option_name = %s), '')))", $table, $name );

		return null === $got ? null : substr( $got, 1 );
	}

	/**
	 * Release every stock change's lock (shutdown, end of a queued action).
	 *
	 * @return void
	 */
	public static function end_changes(): void {
		self::$budget = self::WAIT;

		while ( [] !== self::$open ) {
			$entry = (array) array_pop( self::$open );
			self::unmirrored( $entry );
			self::release_entry( $entry );
		}

		self::drain();
	}

	/**
	 * Shutdown: release every lock this process still holds. Never RELEASE_ALL_LOCKS(): ImportLock may share the session.
	 *
	 * @return void
	 */
	public static function release_all(): void {
		self::end_changes();

		// A flag a throw kept in the last drain: the request ends here, so try the rest now.
		for ( $i = 0; $i < self::DRAIN_TRIES && [] !== self::$unmirrored; ++$i ) {
			self::drain();
		}

		$held       = array_keys( self::$held );
		self::$held = [];

		foreach ( $held as $name ) {
			self::release_lock( $name );
		}
	}

	/**
	 * Align: take every slot of every group without waiting, ascending; when one is busy or unanswered, release them.
	 *
	 * @param list<int> $groups Translation group ids.
	 * @return bool
	 */
	public static function hold( array $groups ): bool {
		sort( $groups );
		self::sweep();

		$taken = [];
		$first = true;
		$on    = null;

		foreach ( $groups as $group ) {
			$base = substr( self::name( $group ), 0, -1 );

			// A change of this group in flight in this process: the server would count a slot again and exclude nothing.
			foreach ( self::$open as $entry ) {
				if ( $entry['group'] === $group && str_starts_with( $entry['name'], $base ) ) {
					self::unhold_names( $taken );

					return false;
				}
			}

			for ( $slot = 0; $slot < self::SLOTS; ++$slot ) {
				$name = self::name( $group, $slot );

				// Another alignment of this process holds it: busy, and its names are not ours to release or retake.
				if ( isset( self::$held[ $name ] ) ) {
					self::unhold_names( $taken );

					return false;
				}

				$got = self::get_lock( $name, 0 );

				if ( true === $got ) {
					self::$held[ $name ] = true;
					$taken[]             = $name;
				}

				// A reconnect between two takes freed the earlier ones (wpdb reruns the statement on a new session).
				if ( true !== $got || ( ! $first && self::handle() !== $on ) ) {
					self::unhold_names( $taken );

					return false;
				}

				$first = false;
				$on    = self::handle();
			}
		}

		self::on_shutdown();

		return true;
	}

	/**
	 * Align: release the slots hold() took for these groups.
	 *
	 * @param list<int> $groups Translation group ids.
	 * @return void
	 */
	public static function unhold( array $groups ): void {
		self::unhold_names( self::names( $groups ) );
	}

	/**
	 * Align: whether this session still holds every slot of these groups (asked only when the connection handle changed).
	 *
	 * @param list<int>   $groups Translation group ids.
	 * @param object|null $handle The handle the locks were taken on (updated when they are still held).
	 * @return bool
	 */
	public static function still_held( array $groups, ?object &$handle ): bool {
		$now   = self::handle();
		$names = self::names( $groups );

		foreach ( $names as $name ) {
			if ( ! isset( self::$held[ $name ] ) ) {
				return false;
			}
		}

		if ( $now === $handle ) {
			return true;
		}

		foreach ( $names as $name ) {
			if ( ! self::mine( $name ) ) {
				return false;
			}
		}

		$handle = $now;

		return true;
	}

	/**
	 * The wpdb connection handle (a new object after a reconnect), or null.
	 *
	 * @return object|null
	 */
	public static function handle(): ?object {
		$dbh = self::db()?->__get( 'dbh' );

		return is_object( $dbh ) ? $dbh : null;
	}

	/**
	 * Take a slot of $group: each slot without waiting from a start slot chosen by process, then (with $wait, within
	 * the request's budget) the start slot, then once more each slot without waiting (one may have freed meanwhile).
	 *
	 * @param int $group Translation group id.
	 * @param int $wait  Seconds to wait on the start slot when all are busy (0: no wait).
	 * @return array{0: string, 1: bool, 2: string|null} The slot's name, whether it was taken and the alignment epoch
	 *                                                  read with the first answer ('' no row; null no answer).
	 */
	private static function slot( int $group, int $wait ): array {
		$pid   = getmypid();
		$start = ( false === $pid ? 0 : $pid ) % self::SLOTS;
		$first = null;

		for ( $i = 0; $i < self::SLOTS; ++$i ) {
			$name            = self::name( $group, ( $start + $i ) % self::SLOTS );
			[ $got, $epoch ] = self::claim( $name, 0 );
			$first         ??= null === $got ? null : $epoch;

			if ( null === $got ) {
				return [ $name, false, $first ];
			}

			if ( $got ) {
				return [ $name, true, $epoch ];
			}
		}

		$name          = self::name( $group, $start );
		$wait          = min( $wait, self::$budget );
		self::$budget -= $wait;

		if ( $wait > 0 ) {
			[ $got ] = self::claim( $name, $wait );

			// MySQL 8.4 and MariaDB 11.8 read the epoch before the wait ends: after a reconnect this change is unjudged.
			if ( true === $got ) {
				return [ $name, true, null ];
			}
		}

		return $wait > 0 ? self::slot( $group, 0 ) : [ $name, false, $first ];
	}

	/**
	 * A slot's get_lock(), with the alignment epoch and the binlog format read in the same statement: no extra query
	 * on a checkout. 's': statement-based binary logging (see $statement_log).
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait (0: none).
	 * @return array{0: bool|null, 1: string} As get_lock(), and the epoch ('' when none).
	 */
	private static function claim( string $name, int $timeout ): array {
		$wpdb = self::db();
		$got  = null === $wpdb ? null : self::ask( "SELECT IF(@@global.read_only OR @@global.innodb_read_only, NULL, CONCAT(GET_LOCK(%s, %d), IF(@@global.log_bin AND @@session.binlog_format = 'STATEMENT', 's', ''), '#', COALESCE((SELECT option_value FROM %i WHERE option_name = %s), '')))", $name, $timeout, $wpdb->options, self::ALIGNED );
		$got  = null === $got ? [ '' ] : explode( '#', $got, 2 );

		if ( str_ends_with( $got[0], 's' ) ) {
			self::$statement_log = true;
			$got[0]              = substr( $got[0], 0, -1 );
		}

		return [ '1' === $got[0] ? true : ( '0' === $got[0] ? false : null ), $got[1] ?? '' ];
	}

	/**
	 * Make a change without a slot visible to alignments: a private liveness lock, a fence row, then the blog's
	 * generation bump, all before its stock write. Row before bump: an alignment that missed the row sees the bump.
	 *
	 * @param int $group Translation group id.
	 * @return Fence|null Null when the database refused the row (the change runs unfenced).
	 */
	private static function fence( int $group ): ?array {
		$wpdb = self::db();
		$lock = '';

		if ( null === $wpdb ) {
			return null;
		}

		try {
			$token = bin2hex( random_bytes( 8 ) );
			$table = $wpdb->options;
			$lock  = 'pfl_stx_' . md5( self::base() . $group . '|' . $token );
			$lock  = true === self::get_lock( $lock, 0 ) ? $lock : '';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fence rows: no option API (no cache, no autoload).
			if ( 1 !== self::run( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, self::fence_row( $group, $token ), $lock . '|' . time() ) ) ) {
				throw new \RuntimeException( 'refused' );
			}

			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::bump_generation( $wpdb );
		} catch ( \Throwable $e ) {
			if ( '' !== $lock ) {
				self::release_lock( $lock );
			}

			return null;
		}

		return [
			'row'   => self::fence_row( $group, $token ),
			'lock'  => $lock,
			'table' => $table,
		];
	}

	/**
	 * Bump the blog's fence generation; no row yet: create it (a racing creator's row is bumped instead).
	 *
	 * @param \wpdb $wpdb Database.
	 * @return void
	 */
	private static function bump_generation( \wpdb $wpdb ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The generation row: no option API (no cache, no autoload).
		if ( 1 !== self::run( $wpdb->prepare( 'UPDATE %i SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s', $wpdb->options, self::GEN ) )
			&& 1 !== self::run( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'off')", $wpdb->options, self::GEN ) ) ) {
			self::run( $wpdb->prepare( 'UPDATE %i SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s', $wpdb->options, self::GEN ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * A fence row's option name.
	 *
	 * @param int    $group Translation group id.
	 * @param string $token The change's random token.
	 * @return string
	 */
	private static function fence_row( int $group, string $token ): string {
		return self::FENCE . $group . '_' . $token;
	}

	/**
	 * Every slot name of these groups.
	 *
	 * @param list<int> $groups Translation group ids.
	 * @return list<string>
	 */
	private static function names( array $groups ): array {
		$names = [];

		foreach ( $groups as $group ) {
			for ( $slot = 0; $slot < self::SLOTS; ++$slot ) {
				$names[] = self::name( $group, $slot );
			}
		}

		return $names;
	}

	/**
	 * Whether this object's newest open entry is an alignment's own frame.
	 *
	 * @param object $product The product object.
	 * @return bool
	 */
	private static function own_open( object $product ): bool {
		$at = self::find( $product, false );

		return null !== $at && self::$open[ $at ]['own'];
	}

	/**
	 * Lock name prefix of the current blog (md5 of DB name and table prefix).
	 *
	 * @return string
	 */
	private static function base(): string {
		$db     = self::db();
		$prefix = null === $db ? '' : $db->prefix;

		if ( ! isset( self::$prefixes[ $prefix ] ) ) {
			$name                      = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : '';
			self::$prefixes[ $prefix ] = 'pfl_stk_' . md5( ( is_string( $name ) ? $name : '' ) . '|' . $prefix ) . '_';
		}

		return self::$prefixes[ $prefix ];
	}

	/**
	 * The global wpdb, or null before WordPress made it.
	 *
	 * @return \wpdb|null
	 */
	private static function db(): ?\wpdb {
		global $wpdb;

		return $wpdb instanceof \wpdb ? $wpdb : null;
	}

	/**
	 * Register release_all() for shutdown once (it runs after a fatal error too).
	 *
	 * @return void
	 */
	private static function on_shutdown(): void {
		if ( ! self::$shutdown ) {
			self::$shutdown = true;
			register_shutdown_function( [ self::class, 'release_all' ] );
		}
	}

	/**
	 * Release held names.
	 *
	 * @param list<string> $names Lock names.
	 * @return void
	 */
	private static function unhold_names( array $names ): void {
		foreach ( $names as $name ) {
			if ( isset( self::$held[ $name ] ) ) {
				unset( self::$held[ $name ] );
				self::release_lock( $name );
			}
		}
	}

	/**
	 * Release the entries whose product object is gone: a caught throw ended that change (a CLI loop, an import).
	 *
	 * @return void
	 */
	private static function sweep(): void {
		$dead = [];
		$live = [];

		foreach ( self::$open as $entry ) {
			if ( null === $entry['ref']->get() ) {
				$dead[] = $entry;
			} else {
				$live[] = $entry;
			}
		}

		self::$open = $live;

		foreach ( $dead as $entry ) {
			self::unmirrored( $entry );
			self::release_entry( $entry );
		}
	}

	/**
	 * A change released other than by end() after its mirror: when its statement ran, may have run, or was judged
	 * unknown, its copies may differ: keep it for drain().
	 *
	 * @param array<string, mixed> $entry A LockEntry.
	 * @return void
	 */
	private static function unmirrored( array $entry ): void {
		if ( empty( $entry['own'] ) && is_int( $entry['blog'] ?? null ) && is_int( $entry['id'] ?? null ) && ( ! empty( $entry['statement'] ) || isset( $entry['stack'] ) || ! empty( $entry['failed'] ) || ! empty( $entry['rerun'] ) ) ) {
			self::$unmirrored[] = [ $entry['blog'], $entry['id'], 0 ];
		}
	}

	/**
	 * Flag the groups unmirrored() kept, each on its own blog, once no change's statement is in flight (its flag
	 * statements would run inside it). Idempotent: StockDriftFlag keeps a set of ids.
	 *
	 * @return void
	 */
	private static function drain(): void {
		foreach ( self::$open as $entry ) {
			if ( null !== $entry['stack'] && ! $entry['statement'] ) {
				return;
			}
		}

		$todo             = self::$unmirrored;
		self::$unmirrored = [];

		if ( [] === $todo ) {
			return;
		}

		require_once __DIR__ . '/StockDriftFlag.php';

		while ( [] !== $todo ) {
			[ $blog, $id, $tries ] = array_shift( $todo );

			$done = StockDriftFlag::on_blog(
				$blog,
				static function () use ( $id ): void {
					$anchor = 'product_variation' === get_post_type( $id ) ? (int) wp_get_post_parent_id( $id ) : $id;
					$ids    = [ $id ];

					foreach ( $anchor > 0 ? \PerfLocale\Plugin::get_instance()->group_repo()->get_translations( $anchor, \PerfLocale\Enum\ObjectType::Post ) : [] as $link ) {
						if ( is_object( $link ) && is_numeric( $link->object_id ?? null ) ) {
							$ids[] = (int) $link->object_id;
						}
					}

					StockDriftFlag::note( array_values( array_unique( $ids ) ) );
				}
			);

			// A flag never breaks a stock change or shutdown: one whose work threw is kept for the next drain (a few
			// times), then noted for the source alone on its blog's table without a switch (Check stock finds the rest).
			if ( ! $done && $tries < self::DRAIN_TRIES ) {
				self::$unmirrored[] = [ $blog, $id, $tries + 1 ];
			} elseif ( ! $done ) {
				StockDriftFlag::note_on( $blog, [ $id ] );
			}
		}
	}

	/**
	 * Remove this object's newest entry (with $unmarked: only one whose statement did not run) and release its lock.
	 *
	 * @param object $product  The product object.
	 * @param bool   $unmarked Only an entry whose statement did not run.
	 * @return void
	 */
	private static function close( object $product, bool $unmarked ): void {
		$at = self::find( $product, $unmarked );

		if ( null !== $at ) {
			$entry = self::$open[ $at ];

			array_splice( self::$open, $at, 1 );

			// A save ending a change whose statement may have run (end() comes after the mirror instead).
			if ( $unmarked ) {
				self::unmirrored( $entry );
			}

			self::release_entry( $entry );
		}

		self::sweep();
		self::drain();
	}

	/**
	 * Release one entry: its fence row, then its liveness lock; its slot on the session that took it (after a
	 * reconnect the server already freed it).
	 *
	 * @param array<string, mixed> $entry A LockEntry.
	 * @return void
	 */
	private static function release_entry( array $entry ): void {
		$fence = $entry['fence'] ?? null;

		// Its own blog's table: a switch_to_blog() since the fence moved $wpdb->options.
		if ( is_array( $fence ) && is_string( $fence['row'] ?? null ) && is_string( $fence['lock'] ?? null ) && is_string( $fence['table'] ?? null ) ) {
			self::run( self::db()?->prepare( 'DELETE FROM %i WHERE option_name = %s', $fence['table'], $fence['row'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The fence row fence() wrote.

			if ( '' !== $fence['lock'] ) {
				self::release_lock( $fence['lock'] );
			}
		}

		$name = is_string( $entry['name'] ?? null ) ? $entry['name'] : '';

		if ( empty( $entry['taken'] ) || '' === $name ) {
			return;
		}

		// A new handle: a reconnect (lock gone; the name may now be a nested entry's) or a HyperDB dataset move.
		if ( ( $entry['handle'] ?? null ) !== self::handle() ) {
			foreach ( self::$open as $other ) {
				if ( $other['name'] === $name ) {
					return;
				}
			}

			if ( isset( self::$held[ $name ] ) || ! self::mine( $name ) ) {
				return;
			}
		}

		self::release_lock( $name );
	}

	/**
	 * Index of this object's newest entry (with $unmarked: whose statement did not run), or null. An own frame counts
	 * only in its write's own hooks (own_level()), not in a listener's nested change.
	 *
	 * @param object $product  The product object.
	 * @param bool   $unmarked Only an entry whose statement did not run.
	 * @return int|null
	 */
	private static function find( object $product, bool $unmarked ): ?int {
		for ( $i = count( self::$open ) - 1; $i >= 0; --$i ) {
			if ( self::$open[ $i ]['ref']->get() === $product && ( ! $unmarked || ! self::$open[ $i ]['statement'] ) && ( ! self::$open[ $i ]['own'] || self::own_level( self::$open[ $i ]['depth'] ) ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Whether the newest stock hook above own_write()'s depth runs one deeper (the write's own hook). Other hooks are
	 * skipped: a caught throw leaves one on the stack, so a plain count would miss the own level.
	 *
	 * @param int $depth Hook count at own_write().
	 * @return bool
	 */
	private static function own_level( int $depth ): bool {
		global $wp_current_filter;

		$hooks = is_array( $wp_current_filter ) ? array_values( $wp_current_filter ) : [];

		for ( $i = count( $hooks ) - 1; $i >= $depth; --$i ) {
			if ( is_string( $hooks[ $i ] ) && isset( self::STOCK_HOOKS[ $hooks[ $i ] ] ) ) {
				return $i === $depth;
			}
		}

		return count( $hooks ) <= $depth + 1;
	}

	/**
	 * GET_LOCK on a writable server only, errors silent: true taken, false busy or timed out, null no usable answer
	 * (error, '1=1', a throw, a read-only server such as a replica a proxy chose, where the lock would exclude nothing).
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait (0: none).
	 * @return bool|null
	 */
	private static function get_lock( string $name, int $timeout ): ?bool {
		$got = self::ask( 'SELECT IF(@@global.read_only OR @@global.innodb_read_only, NULL, GET_LOCK(%s, %d))', $name, $timeout );

		return '1' === $got ? true : ( '0' === $got ? false : null );
	}

	/**
	 * Whether this session holds the lock (IS_USED_LOCK = CONNECTION_ID()).
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	private static function mine( string $name ): bool {
		return '1' === self::ask( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name );
	}

	/**
	 * RELEASE_LOCK, errors silent; its answer does not matter (0 or NULL: not held any more).
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	private static function release_lock( string $name ): void {
		self::ask( 'SELECT RELEASE_LOCK(%s)', $name );
	}

	/**
	 * One lock statement on the writing connection; null on any failure (a stock change never fails for a lock).
	 *
	 * @param literal-string $sql     Statement with placeholders.
	 * @param string|int     ...$args Values.
	 * @return string|null
	 */
	private static function ask( string $sql, string|int ...$args ): ?string {
		$wpdb = self::db();

		if ( null === $wpdb ) {
			return null;
		}

		// HyperDB/LudicrousDB send a SELECT to a replica; the lock must live on the writing connection.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		$was = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- A lock function, prepared here.
			$got = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
		} catch ( \Throwable $e ) {
			$got = null;
		} finally {
			$wpdb->suppress_errors( $was );
		}

		return is_scalar( $got ) ? (string) $got : null;
	}

	/**
	 * Run one prepared fence-row write, errors silent; the rows affected, 0 on any failure.
	 *
	 * @param mixed $sql The prepared statement (null when prepare() refused it).
	 * @return int
	 */
	private static function run( mixed $sql ): int {
		$wpdb = self::db();

		if ( null === $wpdb || ! is_string( $sql ) ) {
			return 0;
		}

		$was = $wpdb->suppress_errors( true );

		try {
			$done = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared by the caller.
		} catch ( \Throwable $e ) {
			$done = false;
		} finally {
			$wpdb->suppress_errors( $was );
		}

		return is_int( $done ) ? $done : 0;
	}
}
