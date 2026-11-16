<?php
/**
 * WooCommerce inventory sync: the flag a copy write that gave up leaves for Site Health and Check stock.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One non-autoloaded option per blog: count, last time, up to IDS copies whose stock change was not mirrored and up to
 * IDS whose stock status or shop data was not refreshed. Written only when StockRelativeWrite gave up, with plain SQL
 * compare-and-set on every try (concurrent writers keep each other's ids). Loaded on first use.
 *
 * @internal Internal to PerfLocale; not a public API.
 */
final class StockDriftFlag {

	/**
	 * Most ids kept per list, newest first.
	 */
	public const IDS = 20;

	/**
	 * Seconds between two writes that add no new id (a permanent error would otherwise write on every change).
	 */
	public const NOTE_EVERY = 900;

	/**
	 * Compare-and-set tries of one write; a note that loses all of them is tried again at shutdown.
	 */
	private const TRIES = 5;

	/**
	 * Notes that lost every try, per blog, for flush().
	 *
	 * @var array<int, list<callable(array{count: int, last: int, ids: list<int>, refresh: list<int>}): (array<string, mixed>|null)>>
	 */
	private static array $deferred = [];

	/**
	 * Changes of note_on() that lost every try, per blog, for flush() on that blog's table.
	 *
	 * @var array<int, list<callable(array{count: int, last: int, ids: list<int>, refresh: list<int>}): (array<string, mixed>|null)>>
	 */
	private static array $direct = [];

	/**
	 * Ids noted in this request, per blog: a repeat in the same request reads nothing.
	 *
	 * @var array<int, array<string, true>>
	 */
	private static array $noted = [];

	/**
	 * Record copies whose stock change was not mirrored, or whose status / lookup row was not refreshed.
	 *
	 * @param list<int> $ids     Copies whose stock may differ.
	 * @param list<int> $refresh Copies whose status or lookup row may be stale.
	 * @return void
	 */
	public static function note( array $ids, array $refresh = [] ): void {
		$blog = get_current_blog_id();
		$keys = array_merge( array_map( static fn( int $id ): string => 's' . $id, $ids ), array_map( static fn( int $id ): string => 'r' . $id, $refresh ) );

		if ( [] === array_diff_key( array_flip( $keys ), self::$noted[ $blog ] ?? [] ) ) {
			return;
		}

		self::$noted[ $blog ] = ( self::$noted[ $blog ] ?? [] ) + array_fill_keys( $keys, true );

		self::write( static fn( array $flag ): ?array => self::adding( $flag, $ids, $refresh ), $keys );
	}

	/**
	 * Note $ids on $blog through its options table by name, without a switch: the last resort when switching there or
	 * the work there kept failing.
	 *
	 * @param int       $blog Blog id.
	 * @param list<int> $ids  Copies whose stock may differ.
	 * @return bool Whether it is stored.
	 */
	public static function note_on( int $blog, array $ids ): bool {
		return self::write( static fn( array $flag ): ?array => self::adding( $flag, $ids, [] ), null, $blog, true );
	}

	/**
	 * The flag with $ids and $refresh added; null when it already says so (written lately).
	 *
	 * @param array{count: int, last: int, ids: list<int>, refresh: list<int>} $flag    The stored flag.
	 * @param list<int>                                                        $ids     Copies whose stock may differ.
	 * @param list<int>                                                        $refresh Copies whose status or lookup row may be stale.
	 * @return array<string, mixed>|null
	 */
	private static function adding( array $flag, array $ids, array $refresh ): ?array {
		$new = [
			'ids'     => self::merge( $ids, $flag['ids'] ),
			'refresh' => self::merge( $refresh, $flag['refresh'] ),
		];

		if ( $flag['count'] > 0 && [] === array_diff( $ids, $flag['ids'] ) && [] === array_diff( $refresh, $flag['refresh'] ) && time() - $flag['last'] < self::NOTE_EVERY ) {
			return null;
		}

		return [
			'count' => $flag['count'] + count( $ids ) + count( $refresh ),
			'last'  => time(),
		] + $new;
	}

	/**
	 * Shutdown: write the notes that lost every try (a lost one is logged; Check stock still finds its drift). A note a
	 * failed blog switch kept from its write is tried in the next round, then on its blog's table without a switch, as
	 * are note_on()'s.
	 *
	 * @return void
	 */
	public static function flush(): void {
		for ( $round = 0; $round < self::TRIES && [] !== self::$deferred; ++$round ) {
			$deferred       = self::$deferred;
			self::$deferred = [];

			foreach ( $deferred as $blog => $changes ) {
				$left = $changes;

				self::on_blog(
					$blog,
					static function () use ( &$left ): void {
						while ( [] !== $left ) {
							self::stored( self::write( array_shift( $left ) ) );
						}
					}
				);

				if ( [] !== $left ) {
					self::$deferred[ $blog ] = array_merge( self::$deferred[ $blog ] ?? [], $left );
				}
			}
		}

		$left = self::$deferred;

		foreach ( self::$direct as $blog => $changes ) {
			$left[ $blog ] = array_merge( $left[ $blog ] ?? [], $changes );
		}

		self::$deferred = [];
		self::$direct   = [];

		foreach ( $left as $blog => $changes ) {
			foreach ( $changes as $change ) {
				self::stored( self::write( $change, null, $blog ) );
			}
		}
	}

	/**
	 * Log a note that was not stored (WP_DEBUG).
	 *
	 * @param bool $stored Whether it was.
	 * @return void
	 */
	public static function stored( bool $stored ): void {
		if ( ! $stored && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'PerfLocale InventorySync: a stock drift note was not stored: the flag kept changing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only.
		}
	}

	/**
	 * Run $work on $blog, then come back to the caller's blog and stack depth whatever threw: whether $work finished.
	 * Core switches, and restores, before its switch_blog listeners run, so after a listener's throw the work still
	 * runs once $blog's tables are selected.
	 *
	 * @param int      $blog Blog id.
	 * @param callable $work The work.
	 * @return bool
	 */
	public static function on_blog( int $blog, callable $work ): bool {
		$depth = self::switched();
		$stack = $GLOBALS['_wp_switched_stack'] ?? [];
		$from  = get_current_blog_id();
		$lang  = self::router();
		$done  = false;
		$odd   = false;
		$here  = true;

		try {
			if ( $blog !== $from && function_exists( 'switch_to_blog' ) ) {
				try {
					switch_to_blog( $blog );
				} catch ( \Throwable $e ) {
					// Switches the listener left open come off first; then $blog must be the one selected.
					$odd = true;
					self::unwind( $depth + 1 );
					self::resume( $blog, $from, 'switch' );
				}

				// A listener may have switched on without a throw: the work runs on $blog's tables only.
				$here = self::selected( $blog );

				if ( $here ) {
					wp_cache_switch_to_blog( $blog );
				}
			}

			if ( $here ) {
				$work();
				$done = true;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Reported by the result.
		}

		if ( self::unwind( $depth ) || $odd || ! self::selected( $from ) || ( $GLOBALS['_wp_switched_stack'] ?? [] ) !== $stack ) {
			self::back( $from, is_array( $stack ) ? $stack : [], $lang );
		}

		return $done;
	}

	/**
	 * Restore while the stack is above $to and each restore takes it lower; a restore whose listener threw is resumed.
	 *
	 * @param int $to Stack depth.
	 * @return bool Whether a restore threw or stopped making progress.
	 */
	private static function unwind( int $to ): bool {
		$odd = false;

		while ( self::switched() > $to ) {
			$before = self::switched();
			$prev   = get_current_blog_id();

			try {
				restore_current_blog();
			} catch ( \Throwable $e ) {
				$odd = true;

				try {
					self::resume( get_current_blog_id(), $prev, 'restore' );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- back() checks the end state.
				}
			}

			// A listener switched again inside its restore: stop here, back() repairs the stack.
			if ( self::switched() >= $before ) {
				return true;
			}
		}

		return $odd;
	}

	/**
	 * Back to the caller's state after an irregular switch: core's stack as the caller had it, $from's tables and object
	 * cache selected as restore_current_blog() selects them, LanguageRouter's language state as the caller had it.
	 *
	 * @param int                       $from  The caller's blog.
	 * @param array<mixed>              $stack The caller's switch stack.
	 * @param array<string, mixed>|null $lang  The caller's LanguageRouter state (router()).
	 * @return void
	 */
	private static function back( int $from, array $stack, ?array $lang ): void {
		global $wpdb;

		try {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's own switch state, set as restore_current_blog() sets it.
			$GLOBALS['_wp_switched_stack'] = $stack;
			$GLOBALS['switched']           = [] !== $stack;
			// phpcs:enable

			$was = get_current_blog_id();

			if ( ! self::selected( $from ) && $wpdb instanceof \wpdb ) {
				$wpdb->set_blog_id( $from );
				// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- As restore_current_blog() sets them.
				$GLOBALS['table_prefix'] = $wpdb->get_blog_prefix();
				$GLOBALS['blog_id']      = $from;
				// phpcs:enable
			}

			try {
				wp_cache_switch_to_blog( $from );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The memos below are reset all the same.
			}

			if ( $was !== $from ) {
				self::ours( $from, $was, 'restore' );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Nothing more can be done here.
		} finally {
			// Whatever threw above: the router's frames cannot follow an irregular switch, the caller's state can.
			try {
				if ( null !== $lang ) {
					self::router( $lang );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Nothing more can be done here.
			}
		}
	}

	/**
	 * LanguageRouter's language state when it is loaded (its own frames cannot follow frames cut from core's stack),
	 * or, given one, put it back; the derived path-prefix map is dropped.
	 *
	 * @param array<string, mixed>|null $state A state this returned.
	 * @return array<string, mixed>|null
	 */
	private static function router( ?array $state = null ): ?array {
		$class = \PerfLocale\Router\LanguageRouter::class;

		if ( ! class_exists( $class, false ) ) {
			return null;
		}

		$out = [];

		foreach ( [ 'current_language', 'default_language', 'detection_finalized', 'blog_state_stack', 'path_prefix_map' ] as $name ) {
			$prop = new \ReflectionProperty( $class, $name );

			if ( null === $state ) {
				$out[ $name ] = $prop->getValue();
			} else {
				$prop->setValue( null, 'path_prefix_map' === $name ? null : $state[ $name ] );
			}
		}

		return null === $state ? $out : null;
	}

	/**
	 * Whether $blog, its tables and its options table are the ones selected.
	 *
	 * @param int $blog Blog id.
	 * @return bool
	 */
	private static function selected( int $blog ): bool {
		global $wpdb;

		return get_current_blog_id() === $blog && $wpdb instanceof \wpdb && $wpdb->blogid === $blog && $wpdb->prefix === $wpdb->get_blog_prefix( $blog ) && $wpdb->options === $wpdb->prefix . 'options';
	}

	/**
	 * After a switch_blog listener threw: finish the switch to $blog (its object cache, PerfLocale's own listeners,
	 * which the throw may have skipped), or throw when $blog's tables are not selected.
	 *
	 * @param int    $blog The blog switched to.
	 * @param int    $prev The blog switched from.
	 * @param string $how  'switch' or 'restore'.
	 * @return void
	 * @throws \RuntimeException When $blog is not selected.
	 */
	private static function resume( int $blog, int $prev, string $how ): void {
		if ( ! self::selected( $blog ) ) {
			throw new \RuntimeException( 'blog not selected' );
		}

		wp_cache_switch_to_blog( $blog );
		self::ours( $blog, $prev, $how );
	}

	/**
	 * Run PerfLocale's own switch_blog listeners for this transition (memo resets), LanguageRouter's only when it has
	 * not handled it (it parks and pops language frames).
	 *
	 * @param int    $blog The blog switched to.
	 * @param int    $prev The blog switched from.
	 * @param string $how  'switch' or 'restore'.
	 * @return void
	 */
	private static function ours( int $blog, int $prev, string $how ): void {
		$router = [ \PerfLocale\Router\LanguageRouter::class, 'maybe_reset_on_switch' ];
		$at     = self::switched() + ( 'restore' === $how ? 1 : 0 );
		$hooks  = $GLOBALS['wp_filter'] ?? null;
		$hook   = is_array( $hooks ) ? $hooks['switch_blog'] ?? null : null;

		foreach ( $hook instanceof \WP_Hook ? $hook->callbacks : [] as $callbacks ) {
			foreach ( is_array( $callbacks ) ? $callbacks : [] as $callback ) {
				$fn    = is_array( $callback ) ? $callback['function'] ?? null : null;
				$owner = is_array( $fn ) ? $fn[0] ?? null : $fn;
				$owner = $owner instanceof \Closure ? ( new \ReflectionFunction( $owner ) )->getClosureScopeClass()?->getName() : ( is_object( $owner ) ? get_class( $owner ) : $owner );

				if ( $router === $fn && \PerfLocale\Router\LanguageRouter::handled( $at ) === ( 'restore' === $how ? 'r' : 's' ) . $blog . ':' . $prev ) {
					continue;
				}

				if ( is_callable( $fn ) && is_string( $owner ) && str_starts_with( $owner, 'PerfLocale' ) ) {
					$fn( ...array_slice( [ $blog, $prev, $how ], 0, is_numeric( $callback['accepted_args'] ?? null ) ? (int) $callback['accepted_args'] : 1 ) );
				}
			}
		}
	}

	/**
	 * How many blog switches are open (core's stack).
	 *
	 * @return int
	 */
	private static function switched(): int {
		$stack = $GLOBALS['_wp_switched_stack'] ?? [];

		return is_array( $stack ) ? count( $stack ) : 0;
	}

	/**
	 * Keep a note that lost every try for flush(), and forget its ids so a later note in this request tries again.
	 *
	 * @param int                                                                                                     $blog   Blog id.
	 * @param callable(array{count: int, last: int, ids: list<int>, refresh: list<int>}): (array<string, mixed>|null) $change The note.
	 * @param list<string>                                                                                            $keys   Its memo keys.
	 * @return void
	 */
	private static function defer( int $blog, callable $change, array $keys ): void {
		if ( [] === self::$deferred && [] === self::$direct ) {
			register_shutdown_function( [ self::class, 'flush' ] );
		}

		self::$deferred[ $blog ][] = $change;
		self::$noted[ $blog ]      = array_diff_key( self::$noted[ $blog ] ?? [], array_flip( $keys ) );
	}

	/**
	 * Drop the stock ids (Check stock found no drift: null) or these ids (an alignment left them aligned), and the
	 * refresh ids Check stock refreshed; the row goes once both lists are empty. A clear that loses every try is
	 * dropped: the flag stays until the next Check stock.
	 *
	 * @param list<int>|null $ids       Aligned ids; null drops every stock id noted before $since.
	 * @param list<int>      $refreshed Refreshed ids.
	 * @param int            $since     When the clean check started: a flag noted later keeps its stock ids.
	 * @return void
	 */
	public static function clear( ?array $ids = null, array $refreshed = [], int $since = 0 ): void {
		unset( self::$noted[ get_current_blog_id() ] );

		self::write(
			static function ( array $flag ) use ( $ids, $refreshed, $since ): ?array {
				if ( 0 === $flag['count'] ) {
					return null;
				}

				$keep = null === $ids ? ( $since > 0 && $flag['last'] >= $since ? $flag['ids'] : [] ) : array_values( array_diff( $flag['ids'], $ids ) );
				$left = [
					'ids'     => $keep,
					'refresh' => array_values( array_diff( $flag['refresh'], $refreshed ) ),
				];

				if ( $left['ids'] === $flag['ids'] && $left['refresh'] === $flag['refresh'] ) {
					return null;
				}

				return [] === $left['ids'] && [] === $left['refresh'] ? [] : $left + $flag;
			}
		);
	}

	/**
	 * The stored flag (count 0 when none), read from the table: concurrent writers must see each other.
	 *
	 * @return array{count: int, last: int, ids: list<int>, refresh: list<int>}
	 */
	public static function read(): array {
		return self::raw()[0];
	}

	/**
	 * Apply $change to the stored flag with compare-and-set on the row's value on every try, so a concurrent note or
	 * clear is never overwritten. $change returns null for "no write" and [] for "delete the row".
	 *
	 * @param callable(array{count: int, last: int, ids: list<int>, refresh: list<int>}): (array<string, mixed>|null) $change Change.
	 * @param list<string>|null                                                                                       $keys   A note's memo keys: when every try lost, kept for flush().
	 * @param int|null                                                                                                $blog   Write $blog's table by name (no switch); null: the current blog's.
	 * @param bool                                                                                                    $later  With $blog: when every try lost, kept for flush() on that table.
	 * @return bool False when every try lost its race (or failed).
	 */
	private static function write( callable $change, ?array $keys = null, ?int $blog = null, bool $later = false ): bool {
		for ( $try = 1; $try <= self::TRIES; ++$try ) {
			// A throw (another plugin's query filter, a driver) is a lost try: the note is tried again, then deferred.
			try {
				$done = self::try_once( $change, $blog );
			} catch ( \Throwable $e ) {
				$done = false;
			}

			if ( $done ) {
				return true;
			}
		}

		if ( null !== $keys ) {
			self::defer( get_current_blog_id(), $change, $keys );
		} elseif ( null !== $blog && $later ) {
			if ( [] === self::$deferred && [] === self::$direct ) {
				register_shutdown_function( [ self::class, 'flush' ] );
			}

			self::$direct[ $blog ][] = $change;
		}

		return false;
	}

	/**
	 * One compare-and-set try of write().
	 *
	 * @param callable(array{count: int, last: int, ids: list<int>, refresh: list<int>}): (array<string, mixed>|null) $change Change.
	 * @param int|null                                                                                                $blog   As write().
	 * @return bool Whether it is stored (or nothing was to write).
	 */
	private static function try_once( callable $change, ?int $blog = null ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$table          = null === $blog ? $wpdb->options : $wpdb->get_blog_prefix( $blog ) . 'options';
		[ $flag, $raw ] = self::raw( $table );
		$new            = $change( $flag );

		if ( null === $new ) {
			return true;
		}

		$value = serialize( $new ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Stored as the options API stores an array.

		if ( [] === $new ) {
			$sql = null === $raw ? '' : $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $table, 'perflocale_stock_drift_flag', $raw );
		} elseif ( null === $raw ) {
			$sql = $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, 'perflocale_stock_drift_flag', $value, 'no' );
		} else {
			$sql = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $table, $value, 'perflocale_stock_drift_flag', $raw );
		}

		if ( '' === $sql ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Compare-and-set on the row's own value (the options API has none); $sql is prepared above.
		$done = is_string( $sql ) ? $wpdb->query( $sql ) : false;

		if ( null === $blog ) {
			wp_cache_delete( 'perflocale_stock_drift_flag', 'options' );
		}

		return is_int( $done ) && $done > 0;
	}

	/**
	 * The stored flag and the row's raw value (null when there is no row).
	 *
	 * @param string|null $table Options table; null: the current blog's.
	 * @return array{0: array{count: int, last: int, ids: list<int>, refresh: list<int>}, 1: string|null}
	 */
	private static function raw( ?string $table = null ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The row as stored now, past the object cache.
		$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table ?? $wpdb->options, 'perflocale_stock_drift_flag' ) );
		$raw  = is_string( $raw ) ? $raw : null;
		$flag = null === $raw ? [] : maybe_unserialize( $raw );
		$flag = is_array( $flag ) ? $flag : [];

		return [
			[
				'count'   => is_numeric( $flag['count'] ?? null ) ? max( 0, (int) $flag['count'] ) : 0,
				'last'    => is_numeric( $flag['last'] ?? null ) ? (int) $flag['last'] : 0,
				'ids'     => self::ids( $flag['ids'] ?? null ),
				'refresh' => self::ids( $flag['refresh'] ?? null ),
			],
			$raw,
		];
	}

	/**
	 * Site Health test: recommended while the flag is set.
	 *
	 * @return array<string, mixed>
	 */
	public static function health(): array {
		$flag   = self::read();
		$result = [
			'test'        => 'perflocale_stock_drift',
			'badge'       => [
				'label' => __( 'PerfLocale', 'perflocale' ),
				'color' => 'blue',
			],
			'status'      => 'good',
			'label'       => __( 'Stock is copied to every language', 'perflocale' ),
			'description' => '<p>' . esc_html__( 'Every recent sale and refund reached all language copies of its product.', 'perflocale' ) . '</p>',
			'actions'     => '',
		];

		if ( 0 === $flag['count'] ) {
			return $result;
		}

		$format = get_option( 'date_format' );
		$time   = get_option( 'time_format' );
		$text   = sprintf(
			/* translators: 1: number of stock changes, 2: date and time of the last one */
			_n(
				'%1$d stock change (last on %2$s) did not fully reach a language copy because the database stayed busy.',
				'%1$d stock changes (last on %2$s) did not fully reach a language copy because the database stayed busy.',
				$flag['count'],
				'perflocale'
			),
			$flag['count'],
			wp_date( ( is_string( $format ) ? $format : 'Y-m-d' ) . ' ' . ( is_string( $time ) ? $time : 'H:i' ), $flag['last'] )
		);

		if ( [] !== $flag['ids'] ) {
			/* translators: %s: product ids */
			$text .= ' ' . sprintf( __( 'Stock may differ: %s.', 'perflocale' ), '#' . implode( ', #', $flag['ids'] ) );
		}

		if ( [] !== $flag['refresh'] ) {
			/* translators: %s: product ids */
			$text .= ' ' . sprintf( __( 'Stock status or shop data may be stale: %s.', 'perflocale' ), '#' . implode( ', #', $flag['refresh'] ) );
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'Stock differences suspected: run Check stock', 'perflocale' );
		$result['description'] = '<p>' . esc_html( $text ) . '</p><p>' . esc_html__( 'Check stock lists the copies that differ and refreshes stale stock statuses; Align sets the stock to the default language.', 'perflocale' ) . '</p>';
		$result['actions']     = '<p><a href="' . esc_url( admin_url( 'admin.php?page=perflocale-settings&tab=addons&subtab=woocommerce' ) ) . '">' . esc_html__( 'Check stock', 'perflocale' ) . '</a></p>';

		return $result;
	}

	/**
	 * New ids first, then the stored ones, unique, at most IDS.
	 *
	 * @param list<int> $new    New ids.
	 * @param list<int> $stored Stored ids.
	 * @return list<int>
	 */
	private static function merge( array $new, array $stored ): array {
		return array_slice( array_values( array_unique( array_merge( $new, $stored ) ) ), 0, self::IDS );
	}

	/**
	 * A stored id list, positive ints only.
	 *
	 * @param mixed $value Stored value.
	 * @return list<int>
	 */
	private static function ids( mixed $value ): array {
		$out = [];

		foreach ( is_array( $value ) ? $value : [] as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[] = (int) $id;
			}
		}

		return $out;
	}
}
