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
 * Writes an option the way update_option() does, except that the UPDATE
 * matches only while the row still holds the bytes the new value was built
 * from. Used by the plugin's read-modify-write of its own options
 * (Settings::update(), the language delete and rename prunes), so that a
 * concurrent writer's change is not undone, while code listening to core's
 * option hooks sees the same hooks, in the same order and with the same
 * arguments, as for update_option().
 */
final class OptionWriter {

	/**
	 * Write $value to $option with a compare-and-set on the row.
	 *
	 * In core's order: sanitize_option(); the `pre_update_option_{$option}`
	 * and `pre_update_option` filters (their result is what is written);
	 * nothing written when the filtered value equals $old_value; when there
	 * is no row, what add_option() does (insert_absent()), except that the
	 * INSERT never replaces a row another writer added; the `update_option`
	 * action; the UPDATE (matching
	 * only while the row holds $expected); the options cache updated as
	 * update_option() updates it; the `update_option_{$option}` and
	 * `updated_option` actions after a write. The filters and the
	 * `update_option` action run on every call, so a caller that retries
	 * after a lost compare-and-set runs them again with the value it read
	 * again.
	 *
	 * @param string    $option    Option name.
	 * @param mixed     $value     New value, before sanitize_option() and the filters.
	 * @param mixed     $old_value The value this write replaces, as the filters and actions receive it.
	 * @param ?string   $expected  The row's bytes the new value was built from; null: there is no row.
	 * @param bool|null $autoload  As update_option()'s $autoload: null leaves the row's autoload as it is
	 *                             (a new row gets add_option()'s default).
	 * @return bool|null True when written; false when there was nothing to write; null when the row no
	 *                   longer held $expected, when a row was added after the read that found none (or
	 *                   the write failed).
	 */
	public static function compare_and_set( string $option, mixed $value, mixed $old_value, ?string $expected, ?bool $autoload = null ): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$value = sanitize_option( $option, $value );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter for this option.
		$value = apply_filters( "pre_update_option_{$option}", $value, $old_value, $option );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
		$value = apply_filters( 'pre_update_option', $value, $option, $old_value );

		if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
			return false;
		}

		if ( null === $expected ) {
			return self::insert_absent( $option, $value, $autoload );
		}

		$serialized = self::bytes( $value );
		$autoload   = null === $autoload ? null : self::autoload_value( $autoload );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
		do_action( 'update_option', $option, $old_value, $value );

		if ( null === $autoload ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set write of the option; the options cache is updated below.
			$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $serialized, $option, $expected ) ?? '' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set write of the option; the options cache is updated below.
			$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, $serialized, $autoload, $option, $expected ) ?? '' );
		}

		if ( 1 !== $affected ) {
			return null;
		}

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		self::update_cache( $option, $serialized, $autoload );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action for this option.
		do_action( "update_option_{$option}", $old_value, $value, $option );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
		do_action( 'updated_option', $option, $old_value, $value );

		return true;
	}

	/**
	 * Add the row of an option that had none, as add_option() adds it: the
	 * value through sanitize_option(), the autoload value add_option() gives,
	 * the `add_option` action, the INSERT, the options cache and the
	 * `notoptions` list updated, the `add_option_{$option}` and
	 * `added_option` actions. The INSERT ignores a row that exists (on the
	 * SQLite Database Integration an INSERT OR IGNORE, which does not fail
	 * inside a transaction): when another writer added the row after the
	 * read that found none, nothing is written, this process's cached
	 * absence and copy of the option are dropped so that the caller's next
	 * read sees that row, and null is returned. add_option() itself skips
	 * its existence check when `notoptions` lists the option and then
	 * overwrites such a row.
	 *
	 * @param string    $option   Option name.
	 * @param mixed     $value    Value, after the pre_update_option filters.
	 * @param bool|null $autoload As add_option()'s $autoload.
	 * @return bool|null True when added; null when a row exists (or the write failed).
	 */
	private static function insert_absent( string $option, mixed $value, ?bool $autoload ): ?bool {
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert of an absent option row that never replaces an existing one; the options cache is updated below.
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $option, $serialized, $autoload ) ?? '' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		if ( 1 !== $inserted ) {
			wp_cache_delete( $option, 'options' );

			return null;
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

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action for this option.
		do_action( "add_option_{$option}", $option, $value );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own action.
		do_action( 'added_option', $option, $value );

		return true;
	}

	/**
	 * The autoload value add_option() writes for a new row: from WordPress
	 * 6.6 what wp_determine_option_autoload_value() answers ('on' or 'off'
	 * for a boolean; otherwise the `wp_default_autoload_value` filter's
	 * boolean as 'auto-on' or 'auto-off', else 'auto'), before 6.6 'no' for
	 * false and 'yes' otherwise.
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
	 * The autoload value update_option() writes for a boolean $autoload:
	 * 'on' or 'off' from WordPress 6.6 (wp_determine_option_autoload_value()
	 * answers a boolean with these, before any filter), 'yes' or 'no' before.
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
	 * The autoload values that put an option in the alloptions list, as
	 * wp_autoload_values_to_autoload() computes them from WordPress 6.6: its
	 * four defaults through its filter, which can only remove one. The same
	 * four before 6.6, where update_option() wrote only 'yes' or 'no'.
	 *
	 * @return array<int, string>
	 */
	private static function autoload_on_values(): array {
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
