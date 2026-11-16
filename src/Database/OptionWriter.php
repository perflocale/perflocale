<?php
/**
 * Option writes as update_option() makes them, with a compare-and-set on the row.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes an option as update_option() does, but the UPDATE matches only while the row holds the bytes the value was built from.
 *
 * For the plugin's read-modify-write of its own options, so a concurrent change is not undone; core's option hooks fire as usual.
 */
final class OptionWriter {

	/**
	 * Why the last compare_and_set() of each option wrote nothing (see last_failure()); per option, so a listener's
	 * write of another option does not replace the cause a caller reads after its own write.
	 *
	 * @var array<string, array{option: string, reason: string, error: string}>
	 */
	private static array $failures = [];

	/**
	 * Cache updates and completion actions held for the caller's open transaction (hold()); null when none is held.
	 *
	 * @var list<array{0: \Closure(): void, 1: \Closure(): void}>|null
	 */
	private static ?array $held = null;

	/**
	 * The transaction holding them (hold()); null when none.
	 *
	 * @var object|null
	 */
	private static ?object $holder = null;

	/**
	 * Options a write tried to change while held.
	 *
	 * @var array<string, true>
	 */
	private static array $touched = [];

	/**
	 * The bytes the last landed write of each option left: [ bytes ] (null bytes: deleted).
	 *
	 * @var array<string, array{0: ?string}>
	 */
	private static array $wrote = [];

	/**
	 * What the last write of an option left when it landed: [ bytes ] (null: the row deleted); null when it did not land.
	 *
	 * @param string $option Option name.
	 * @return array{0: ?string}|null
	 */
	public static function wrote( string $option ): ?array {
		return self::$wrote[ $option ] ?? null;
	}

	/**
	 * Hold the cache updates and completion actions of the writes that follow until settle(), so a transaction's writes
	 * reach no cache or listener before its COMMIT. The pre-write actions still fire before the write, as in core.
	 *
	 * @internal One open transaction at a time (Values::update(), LanguageWrites::delete()).
	 * @param object $owner The transaction (its ConnectionGuard); settle() acts only for it.
	 * @return bool False while another holds: its hold is never replaced.
	 */
	public static function hold( object $owner ): bool {
		if ( null !== self::$held ) {
			return false;
		}

		self::$held    = [];
		self::$touched = [];
		self::$holder  = $owner;

		return true;
	}

	/**
	 * End the hold: after a confirmed COMMIT run every held cache update (one that throws: every option written is
	 * forgotten), then $then, then the held actions; otherwise (rollback, unknown outcome) forget every cached form of each
	 * option written, then $then. A throw skips no step.
	 *
	 * @param bool          $committed Whether the COMMIT is confirmed.
	 * @param object        $owner     As hold(); any other owner changes nothing but $then.
	 * @param \Closure|null $then      The caller's mandatory invalidation, before any listener runs.
	 * @phpstan-param (\Closure(): void)|null $then
	 * @return list<string> The options forgotten.
	 * @throws \Throwable What a cache update, $then or a held action throws, after every step ran.
	 */
	public static function settle( bool $committed, object $owner, ?\Closure $then = null ): array {
		$then ??= static function (): void {};

		if ( null === self::$held || $owner !== self::$holder ) {
			$then();

			return [];
		}

		$held          = self::$held;
		$touched       = array_keys( self::$touched );
		self::$held    = null;
		self::$holder  = null;
		self::$touched = [];

		if ( ! $committed ) {
			try {
				foreach ( $touched as $option ) {
					self::forget( $option );
				}
			} finally {
				$then();
			}

			return $touched;
		}

		try {
			try {
				Tx::each( ...array_map( static fn( array $step ): \Closure => $step[0], $held ) );
			} catch ( \Throwable $e ) {
				// A cache update cut short (a throwing alloptions filter): no touched option keeps a stale cached form.
				foreach ( $touched as $option ) {
					self::forget( $option );
				}

				throw $e;
			} finally {
				$then();
			}
		} finally {
			foreach ( $held as $step ) {
				$step[1]();
			}
		}

		return [];
	}

	/**
	 * Note an option a held transaction writes without this class (forgotten by settle( false )).
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function touch( string $option ): void {
		if ( null !== self::$held ) {
			self::$touched[ $option ] = true;
		}
	}

	/**
	 * Drop every cached form of an option: its key, alloptions, and its name in notoptions (the next read goes to the database).
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function forget( string $option ): void {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		NotOptions::unlist( [ $option ] );
	}

	/**
	 * Run a landed write's cache update and completion actions now, or hold them until the transaction's COMMIT.
	 *
	 * @param \Closure $cache   The cache update.
	 * @param \Closure $actions The completion actions (listeners of other code).
	 * @phpstan-param \Closure(): void $cache
	 * @phpstan-param \Closure(): void $actions
	 * @return void
	 */
	private static function publish( \Closure $cache, \Closure $actions ): void {
		if ( null === self::$held ) {
			$cache();
			$actions();

			return;
		}

		self::$held[] = [ $cache, $actions ];
	}

	/**
	 * Write $value to $option with a compare-and-set on the row, with update_option()'s filters, actions and cache updates in its order.
	 *
	 * The filters and the `update_option` action run on every call, so a caller retrying after a lost compare-and-set runs them again.
	 * Order: docs-ai/11-code-rationale.md, "OptionWriter: hook and cache order".
	 *
	 * @param string    $option    Option name.
	 * @param mixed     $value     New value, before sanitize_option() and the filters.
	 * @param mixed     $old_value The value this write replaces, as the filters and actions receive it.
	 * @param ?string   $expected  The row's bytes the new value was built from; null: there is no row.
	 * @param bool|null $autoload  As update_option()'s $autoload: null leaves the row's autoload as it is
	 *                             (a new row gets add_option()'s default).
	 * @param bool      $same_end  The end state is the same whoever wrote it (never a claim): a write that reports no
	 *                             row while a successful re-read shows the new bytes counts as written (rule F).
	 * @param string    $condition ConnectionGuard::condition() of the caller's transaction, added to the write; '' for none.
	 * @return bool|null True when written; false when there was nothing to write; null when the row did not
	 *                   hold $expected, when a row was added after the read that found none (or the write failed).
	 */
	public static function compare_and_set( string $option, mixed $value, mixed $old_value, ?string $expected, ?bool $autoload = null, bool $same_end = false, string $condition = '' ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		unset( self::$failures[ $option ], self::$wrote[ $option ] );

		$value = sanitize_option( $option, $value );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter for this option.
		$value = apply_filters( "pre_update_option_{$option}", $value, $old_value, $option );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
		$value = apply_filters( 'pre_update_option', $value, $option, $old_value );

		if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
			self::$failures[ $option ] = [
				'option' => $option,
				'reason' => 'unchanged',
				'error'  => '',
			];

			return false;
		}

		if ( null === $expected ) {
			return self::insert_absent( $option, $value, $autoload, $same_end, $condition );
		}

		$serialized = self::bytes( $value );
		$autoload   = null === $autoload ? null : self::autoload_value( $autoload );
		$guard      = self::guard( $condition );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
		do_action( 'update_option', $option, $old_value, $value );
		self::touch( $option );

		if ( null === $autoload ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compare-and-set write of the option; the options cache is updated below. $guard: see guard().
			$affected = $wpdb->query( ( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $serialized, $option, $expected ) ?? '' ) . $guard );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compare-and-set write of the option; the options cache is updated below. $guard: see guard().
			$affected = $wpdb->query( ( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $serialized, $autoload, $option, $expected ) ?? '' ) . $guard );
		}

		if ( 1 !== $affected && ! self::landed( $option, $affected, $serialized, $same_end ) ) {
			return null;
		}

		self::$wrote[ $option ] = [ $serialized ];

		self::publish(
			static function () use ( $option, $serialized, $autoload ): void {
				$notoptions = wp_cache_get( 'notoptions', 'options' );

				if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
					unset( $notoptions[ $option ] );
					wp_cache_set( 'notoptions', $notoptions, 'options' );
				}

				self::update_cache( $option, $serialized, $autoload );
			},
			static function () use ( $option, $old_value, $value ): void {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action for this option.
				do_action( "update_option_{$option}", $old_value, $value, $option );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
				do_action( 'updated_option', $option, $old_value, $value );
			}
		);

		return true;
	}

	/**
	 * Delete an option as delete_option() does, but only while the row holds the bytes read (byte for byte).
	 *
	 * @param string $option   Option name.
	 * @param string $expected The row's bytes as read.
	 * @param bool   $same_end As compare_and_set(): no row reported and a successful re-read finds none counts as deleted.
	 * @param string $condition As compare_and_set().
	 * @return bool|null True when deleted; null when the row did not hold $expected (or was gone), or the query failed.
	 */
	public static function compare_and_delete( string $option, string $expected, bool $same_end = false, string $condition = '' ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		unset( self::$failures[ $option ], self::$wrote[ $option ] );
		wp_protect_special_option( $option );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
		do_action( 'delete_option', $option );
		self::touch( $option );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Compare-and-delete of the option; the options cache is updated below. guard(): a checked marker.
		$deleted = $wpdb->query( ( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $option, $expected ) ?? '' ) . self::guard( $condition ) );

		if ( 1 !== $deleted && ! self::landed( $option, $deleted, null, $same_end ) ) {
			return null;
		}

		self::$wrote[ $option ] = [ null ];

		self::publish(
			static function () use ( $option ): void {
				if ( ! wp_installing() ) {
					$alloptions = wp_load_alloptions( true );

					if ( isset( $alloptions[ $option ] ) ) {
						unset( $alloptions[ $option ] );
						wp_cache_set( 'alloptions', $alloptions, 'options' );
					}

					wp_cache_delete( $option, 'options' );

					$notoptions            = wp_cache_get( 'notoptions', 'options' );
					$notoptions            = is_array( $notoptions ) ? $notoptions : [];
					$notoptions[ $option ] = true;
					wp_cache_set( 'notoptions', $notoptions, 'options' );
				}
			},
			static function () use ( $option ): void {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action for this option.
				do_action( "delete_option_{$option}", $option );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
				do_action( 'deleted_option', $option );
			}
		);

		return true;
	}

	/**
	 * Add the row of an option that had none, as add_option() does, but never over a row another writer added.
	 *
	 * Not add_option(): it skips its existence check when `notoptions` lists the option and overwrites that row. On a lost
	 * race this process's cached absence and copy are dropped so the caller's next read sees the row, and null is returned.
	 *
	 * @param string    $option   Option name.
	 * @param mixed     $value    Value, after the pre_update_option filters.
	 * @param bool|null $autoload As add_option()'s $autoload.
	 * @param bool      $same_end As compare_and_set().
	 * @param string    $condition As compare_and_set(): the row is sent as `SELECT ... FROM DUAL WHERE <condition>`.
	 * @return bool|null True when added; null when a row exists (or the write failed).
	 */
	private static function insert_absent( string $option, mixed $value, ?bool $autoload, bool $same_end = false, string $condition = '' ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		wp_protect_special_option( $option );

		if ( is_object( $value ) ) {
			$value = clone $value;
		}

		$value      = sanitize_option( $option, $value );
		$serialized = self::bytes( $value );
		$autoload   = self::add_autoload_value( $option, $value, $serialized, $autoload );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
		do_action( 'add_option', $option, $value );
		self::touch( $option );

		if ( '' === $condition ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert of an absent option row that never replaces an existing one; the options cache is updated below.
			$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $option, $serialized, $autoload ) ?? '' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- As above, guarded (guard(): a checked marker).
			$inserted = $wpdb->query( ( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s FROM DUAL WHERE 1 = 1', $wpdb->options, $option, $serialized, $autoload ) ?? '' ) . self::guard( $condition ) );
		}

		// Held: notoptions changes only at publication; before COMMIT another request would list the absent row again.
		$deferred = null !== self::$held;

		if ( ! $deferred ) {
			$notoptions = wp_cache_get( 'notoptions', 'options' );

			if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
				unset( $notoptions[ $option ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}

		if ( 1 !== $inserted && ! self::landed( $option, $inserted, $serialized, $same_end ) ) {
			wp_cache_delete( $option, 'options' );

			return null;
		}

		self::$wrote[ $option ] = [ $serialized ];

		self::publish(
			static function () use ( $option, $serialized, $autoload, $deferred ): void {
				// The shared list, per name, before the key and the hooks: core reads notoptions before the key.
				if ( $deferred ) {
					NotOptions::unlist( [ $option ] );
				}

				if ( ! wp_installing() ) {
					if ( in_array( $autoload, self::autoload_on_values(), true ) ) {
						$alloptions            = wp_load_alloptions( true );
						$alloptions[ $option ] = $serialized;
						wp_cache_set( 'alloptions', $alloptions, 'options' );
					} else {
						wp_cache_set( $option, $serialized, 'options' );
					}
				}
			},
			static function () use ( $option, $value ): void {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action for this option.
				do_action( "add_option_{$option}", $option, $value );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
				do_action( 'added_option', $option, $value );
			}
		);

		return true;
	}

	/**
	 * ' AND <condition>' for a guarded write, '' without one; only ConnectionGuard::condition() (a checked name and a hex token).
	 *
	 * @param string $condition The condition.
	 * @return string
	 * @throws \InvalidArgumentException For any other text (it goes into the SQL unprepared).
	 */
	private static function guard( string $condition ): string {
		if ( '' === $condition ) {
			return '';
		}

		if ( 1 !== preg_match( "/^@perflocale_[a-z0-9_]{1,40} = '[0-9a-f]{32}'\\z/", $condition ) ) {
			throw new \InvalidArgumentException( 'OptionWriter: invalid write condition.' );
		}

		return ' AND ' . $condition;
	}

	/**
	 * Whether a write that changed no row counts as done: only for a same-end write whose successful re-read shows
	 * $bytes (null: no row), as a resent statement that already landed leaves it. Otherwise the cause is recorded; a
	 * failed re-read is a database failure (unknown, never "changed").
	 *
	 * @param string      $option   Option name.
	 * @param mixed       $result   The write's result.
	 * @param string|null $bytes    The row the write leaves; null when it deletes.
	 * @param bool        $same_end The caller accepts the end state whoever wrote it.
	 * @return bool
	 */
	private static function landed( string $option, mixed $result, ?string $bytes, bool $same_end ): bool {
		if ( ! $same_end ) {
			self::failed( $option, $result );

			return false;
		}

		$now = OptionRead::of( $option );

		if ( ! $now->failed && $now->bytes === $bytes ) {
			return true;
		}

		self::failed( $option, $now->failed ? false : $result );

		return false;
	}

	/**
	 * Why the last compare_and_set() of $option wrote nothing; null after a write. Reasons: 'unchanged' (the filtered
	 * value is the stored one), 'changed' (the row no longer held the expected bytes), 'database' (the query failed).
	 *
	 * @param string $option Option name.
	 * @return array{option: string, reason: string, error: string}|null The error is the database's text, for logs only.
	 */
	public static function last_failure( string $option ): ?array {
		return self::$failures[ $option ] ?? null;
	}

	/**
	 * Record a write that changed no row; a database error goes to the debug log (it can name tables and values).
	 *
	 * @param string $option Option name.
	 * @param mixed  $result The query's result.
	 * @return void
	 */
	private static function failed( string $option, mixed $result ): void {
		global $wpdb;

		// A fixed code, never the database's text (it can quote the value written).
		$code                      = false === $result && $wpdb instanceof \wpdb && '' !== $wpdb->last_error ? \PerfLocale\Util\DbErrorCode::of( $wpdb->last_error ) : '';
		self::$failures[ $option ] = [
			'option' => $option,
			'reason' => false === $result ? 'database' : 'changed',
			'error'  => $code,
		];

		if ( '' !== $code && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic, only when WP_DEBUG_LOG is on.
			error_log( '[PerfLocale] option ' . $option . ' not written: database error - code ' . $code );
		}
	}

	/**
	 * The autoload value add_option() writes for a new row, as wp_determine_option_autoload_value() answers.
	 *
	 * Without that function (WordPress before 6.6): 'no' for false, else 'yes'.
	 *
	 * @param string    $option     Option name.
	 * @param mixed     $value      Value.
	 * @param string    $serialized The value as stored.
	 * @param bool|null $autoload   As add_option()'s $autoload.
	 * @return string
	 */
	private static function add_autoload_value( string $option, mixed $value, string $serialized, ?bool $autoload ): string {
		if ( null !== $autoload ) {
			return self::autoload_value( $autoload );
		}

		if ( ! function_exists( 'wp_determine_option_autoload_value' ) ) {
			return 'yes';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, applied as wp_determine_option_autoload_value() applies it.
		$default = apply_filters( 'wp_default_autoload_value', null, $option, $value, $serialized );

		if ( is_bool( $default ) ) {
			return $default ? 'auto-on' : 'auto-off';
		}

		return 'auto';
	}

	/**
	 * A value as maybe_serialize() stores it, as a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function bytes( mixed $value ): string {
		$serialized = maybe_serialize( $value );

		return is_scalar( $serialized ) ? (string) $serialized : '';
	}

	/**
	 * The autoload value update_option() writes for a boolean: 'on'/'off' from WordPress 6.6 (before any filter), else 'yes'/'no'.
	 *
	 * @param bool $autoload Whether to autoload.
	 * @return string
	 */
	private static function autoload_value( bool $autoload ): string {
		if ( function_exists( 'wp_determine_option_autoload_value' ) ) {
			return $autoload ? 'on' : 'off';
		}

		return $autoload ? 'yes' : 'no';
	}

	/**
	 * The autoload values that put an option in alloptions, as wp_autoload_values_to_autoload() computes them.
	 *
	 * Its four defaults through its filter, which can only remove one; the same four without it (WordPress before 6.6).
	 *
	 * @return array<int, string>
	 */
	public static function autoload_on_values(): array {
		$values = [ 'yes', 'on', 'auto-on', 'auto' ];

		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, applied as wp_autoload_values_to_autoload() applies it.
			$values = array_values( array_intersect( array_filter( (array) apply_filters( 'wp_autoload_values_to_autoload', $values ), 'is_string' ), $values ) );
		}

		return $values;
	}

	/**
	 * The options cache after a write, as update_option() leaves it.
	 *
	 * @param string      $option     Option name.
	 * @param string      $serialized The value written.
	 * @param string|null $autoload   The autoload value written, null when the row's was kept.
	 * @return void
	 */
	private static function update_cache( string $option, string $serialized, ?string $autoload ): void {
		if ( wp_installing() ) {
			return;
		}

		if ( null === $autoload ) {
			$alloptions = wp_load_alloptions( true );

			if ( isset( $alloptions[ $option ] ) ) {
				$alloptions[ $option ] = $serialized;
				wp_cache_set( 'alloptions', $alloptions, 'options' );
			} else {
				wp_cache_set( $option, $serialized, 'options' );
			}

			return;
		}

		if ( in_array( $autoload, self::autoload_on_values(), true ) ) {
			wp_cache_delete( $option, 'options' );

			$alloptions            = wp_load_alloptions( true );
			$alloptions[ $option ] = $serialized;
			wp_cache_set( 'alloptions', $alloptions, 'options' );

			return;
		}

		$alloptions = wp_load_alloptions( true );

		if ( isset( $alloptions[ $option ] ) ) {
			unset( $alloptions[ $option ] );
			wp_cache_set( 'alloptions', $alloptions, 'options' );
		}

		wp_cache_set( $option, $serialized, 'options' );
	}
}
