<?php
/**
 * Network upgrade pass.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Util\DbErrorCode;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multisite: one cron event walks the network's sites by id (cursor, batch, time budget) and runs each pending site's
 * upgrade, so a site nobody opens is upgraded too; it ends when a full walk leaves no site pending.
 *
 * @since 1.0.8
 * @internal
 */
final class NetworkUpgrade {

	/** The cron event, on the host: the main site, or the site that armed the pass when PerfLocale is off there or its event was refused. */
	public const HOOK = 'perflocale_network_upgrade';

	/** Network option: target, host, cursor (last site id done), left (sites still pending this walk), walks, at; after the last walk target, host, ended. */
	public const OPTION = 'perflocale_network_upgrade';

	/** Lock on the host, so a double-fired event runs one batch. */
	private const LOCK = 'network_upgrade';

	/** Most sites one run reads. */
	private const BATCH = 25;

	/** Seconds one run may spend; checked before each site after the first. */
	private const BUDGET = 10;

	/** Seconds to the next batch of a walk. */
	private const NEXT = 60;

	/** Seconds to the next walk while sites are still pending (their own backoff applies). */
	private const RETRY = 3600;

	/** Walks before the pass gives up on sites that keep failing (each shows its failure on its own screens). */
	private const MAX_WALKS = 24;

	/** A pass with no run for this long lost its event (host deactivated or deleted): the next arm() takes it over. */
	private const STALE = 3 * 3600;

	/**
	 * Whether this request is inside run(): its per-site upgrades must not re-arm the pass.
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Start the pass for this code version unless one is under way; on activation, always.
	 *
	 * @param bool $restart  Start over even when a pass is recorded (its event may have been cleared).
	 * @param bool $upgraded This request upgraded a site that was behind: an ended pass of this target belongs to an
	 *     earlier upgrade (a downgrade and re-upgrade, a site added meanwhile), so a new walk starts.
	 * @return void
	 */
	public static function arm( bool $restart = false, bool $upgraded = false ): void {
		if ( self::$running || ! is_multisite() ) {
			return;
		}

		if ( ! $restart ) {
			$state = self::stored();

			// A failed read proves nothing: the next page asks again.
			if ( false === $state || ( is_array( $state ) && self::target() === ( $state['target'] ?? null ) && ( ( ! empty( $state['ended'] ) && ! $upgraded ) || time() - (int) ( $state['at'] ?? 0 ) < self::STALE ) ) ) {
				return;
			}
		}

		$main = get_main_site_id();

		// Hosted on the main site, whose cron runs most (a server cron often hits only it), when PerfLocale runs there.
		if ( get_current_blog_id() !== $main && self::active_on( $main ) && self::arm_on( $main ) ) {
			return;
		}

		update_site_option( self::OPTION, self::fresh( 0 ) );
		self::reschedule( self::NEXT );
	}

	/**
	 * Host the pass on another site through core cron: this request's Action Scheduler may not run there.
	 *
	 * @param int $id Site id.
	 * @return bool False when the event was not queued there (the caller hosts the pass itself).
	 */
	private static function arm_on( int $id ): bool {
		switch_to_blog( $id );

		try {
			wp_unschedule_hook( self::HOOK );

			if ( true !== wp_schedule_single_event( time() + self::NEXT, self::HOOK, [], true ) ) {
				return false;
			}

			update_site_option( self::OPTION, self::fresh( 0 ) );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Retry now and WP-CLI: a pass that used its last walk walks again; a pass under way keeps its place.
	 *
	 * @return void
	 */
	public static function on_retry(): void {
		$state = self::stored();

		if ( is_array( $state ) && ! empty( $state['ended'] ) ) {
			self::arm( true );
		}
	}

	/**
	 * The cron event: one batch of the walk, then the next event, or none once no site is left pending.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$state = self::stored();

		// A failed read proves nothing: queue the next event, or the pass ends here.
		if ( false === $state ) {
			self::reschedule( self::NEXT );
			return;
		}

		if ( ! self::runs_here( $state ) ) {
			return;
		}

		// Locked (a double fire, or a dead run until its TTL): queue the next event, or the pass ends here.
		if ( ! Lock::acquire( self::LOCK, 10 * self::BUDGET ) ) {
			self::reschedule( self::NEXT );
			return;
		}

		self::$running = true;

		try {
			// Read again under the lock: a double-fired event may have ended the pass since the read above.
			$state = self::stored();

			if ( false === $state ) {
				self::reschedule( self::NEXT );
				return;
			}

			if ( ! self::runs_here( $state ) ) {
				return;
			}

			if ( self::target() !== ( $state['target'] ?? null ) ) {
				$state = self::fresh( 0 );
			}

			// Queued first, so a run that dies mid-batch (fatal error, time limit) still has its next event.
			self::reschedule( self::NEXT );

			$began = microtime( true );
			$walks = (int) ( $state['walks'] ?? 0 );
			$ids   = self::sites_after( (int) ( $state['cursor'] ?? 0 ) );

			// A failed read is not the end of the list: the event queued above asks again from the same place.
			if ( false === $ids ) {
				return;
			}

			$more = count( $ids ) >= self::BATCH;
			$ran  = 0;

			foreach ( $ids as $id ) {
				if ( $ran > 0 && microtime( true ) - $began >= self::BUDGET ) {
					$more = true;
					break;
				}

				$current = self::upgrade_site( $id );
				++$ran;

				// Stored per site: a run that dies resumes after the last site it finished.
				$state = [
					'cursor' => $id,
					'left'   => (int) ( $state['left'] ?? 0 ) + ( $current ? 0 : 1 ),
					'at'     => time(),
				] + $state;
				update_site_option( self::OPTION, $state );
			}

			if ( $more ) {
				return;
			}

			if ( 0 === (int) ( $state['left'] ?? 0 ) ) {
				delete_site_option( self::OPTION );
				BackgroundEvents::unschedule_all( self::HOOK );
				return;
			}

			if ( $walks + 1 >= self::MAX_WALKS ) {
				// Kept, as a deleted row would let the next admin page start the same target over; a refused write keeps the event, which writes it again.
				$end = [
					'target' => self::target(),
					'host'   => get_current_blog_id(),
					'ended'  => time(),
				];

				if ( update_site_option( self::OPTION, $end ) ) {
					BackgroundEvents::unschedule_all( self::HOOK );
				}

				return;
			}

			update_site_option( self::OPTION, self::fresh( $walks + 1 ) );
			self::reschedule( self::RETRY );
		} finally {
			self::$running = false;
			Lock::release( self::LOCK );
		}
	}

	/**
	 * Upgrade one site when it is pending; true when it is known to be current afterwards (or not set up for PerfLocale).
	 *
	 * @param int $id Site id.
	 * @return bool
	 */
	private static function upgrade_site( int $id ): bool {
		switch_to_blog( $id );

		try {
			$pending = self::pending_here();

			// Only a read that answered can call the site done; unknown stays pending for the next walk.
			if ( true !== $pending ) {
				return false === $pending;
			}

			if ( (int) get_option( 'perflocale_db_version', 0 ) < PERFLOCALE_DB_VERSION ) {
				UpgradeRunner::pending( 'schema' );
			}

			if ( version_compare( (string) get_option( 'perflocale_version', '0' ), PERFLOCALE_VERSION, '<' ) ) {
				UpgradeRunner::pending( 'update' );
			}

			return false === self::pending_here();
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Whether the current site runs PerfLocale, was set up for it, and is behind the code; null when a read failed.
	 *
	 * @phpstan-impure
	 * @return bool|null
	 */
	private static function pending_here(): ?bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- get_option() cannot tell a failed read from no row.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name IN ( %s, %s, %s )', $wpdb->options, 'perflocale_version', 'perflocale_db_version', 'active_plugins' ), ARRAY_A );

		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			// A site row with no tables holds nothing to upgrade, and would otherwise keep the pass to its last walk.
			return 'missing_table' === DbErrorCode::of( $wpdb->last_error ) ? false : null;
		}

		$options = array_column( $rows, 'option_value', 'option_name' );
		$version = Scalar::str( $options['perflocale_version'] ?? '' );

		if ( '' === $version || ( (int) ( $options['perflocale_db_version'] ?? 0 ) >= PERFLOCALE_DB_VERSION && version_compare( $version, PERFLOCALE_VERSION, '>=' ) ) ) {
			return false;
		}

		$plugin = self::plugin();
		$active = $options['active_plugins'] ?? '';

		if ( is_string( $active ) && in_array( $plugin, (array) maybe_unserialize( $active ), true ) ) {
			return true;
		}

		$sitewide = self::network_value( 'active_sitewide_plugins' );

		return false === $sitewide ? null : array_key_exists( $plugin, (array) maybe_unserialize( (string) $sitewide ) );
	}

	/**
	 * PerfLocale's plugin basename, as core lists it in active_plugins.
	 *
	 * @return string
	 */
	private static function plugin(): string {
		return plugin_basename( PERFLOCALE_FILE );
	}

	/**
	 * Whether PerfLocale is active on a site (network-wide or there).
	 *
	 * @param int $id Site id.
	 * @return bool
	 */
	private static function active_on( int $id ): bool {
		$plugin = self::plugin();

		return array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', [] ) ) || in_array( $plugin, (array) get_blog_option( $id, 'active_plugins', [] ), true );
	}

	/**
	 * The next site ids of this network after the cursor, in id order (the blogs primary key).
	 *
	 * @param int $cursor Last site id done.
	 * @return list<int>|false False when the read failed.
	 */
	private static function sites_after( int $cursor ): array|false {
		global $wpdb;
		/** @var \wpdb $wpdb */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cron walk by primary key; WP_Site_Query has no id range.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d ORDER BY blog_id LIMIT %d', $wpdb->blogs, get_current_network_id(), $cursor, self::BATCH ) );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * The stored pass, past this request's caches: null when there is none, false when the read failed.
	 *
	 * @phpstan-impure
	 * @return array<array-key, mixed>|null|false
	 */
	private static function stored(): array|null|false {
		$value = self::network_value( self::OPTION );

		if ( false === $value ) {
			return false;
		}

		$state = null === $value ? null : maybe_unserialize( $value );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * A network option's row, past this request's caches: null when there is none, false when the read failed.
	 *
	 * @phpstan-impure
	 * @param string $name Option name.
	 * @return string|null|false
	 */
	private static function network_value( string $name ): string|null|false {
		$wpdb = $GLOBALS['wpdb'] ?? null;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		// HyperDB and LudicrousDB send a SELECT to a replica, which may not have the end just written.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- get_site_option() cannot tell a failed read from no row.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE meta_key = %s AND site_id = %d LIMIT 1', $wpdb->sitemeta, $name, get_current_network_id() ) );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		return null === $value ? null : (string) $value;
	}

	/**
	 * Whether this site's event may run a batch: the pass is recorded, hosted here and has not ended.
	 *
	 * @param array<array-key, mixed>|null $state stored().
	 * @return bool
	 */
	private static function runs_here( ?array $state ): bool {
		return null !== $state && get_current_blog_id() === (int) ( $state['host'] ?? 0 ) && empty( $state['ended'] );
	}

	/**
	 * Replace this site's pass event with one in $delay seconds.
	 *
	 * @param int $delay Seconds.
	 * @return void
	 */
	private static function reschedule( int $delay ): void {
		BackgroundEvents::unschedule_all( self::HOOK );
		BackgroundEvents::enqueue( self::HOOK, [], $delay );
	}

	/**
	 * A walk from the first site, hosted here.
	 *
	 * @param int $walks Walks done.
	 * @return array<string, mixed>
	 */
	private static function fresh( int $walks ): array {
		return [
			'target' => self::target(),
			'host'   => get_current_blog_id(),
			'cursor' => 0,
			'left'   => 0,
			'walks'  => $walks,
			'at'     => time(),
		];
	}

	/**
	 * The code version the pass upgrades to.
	 *
	 * @return string
	 */
	private static function target(): string {
		return PERFLOCALE_VERSION . '|' . PERFLOCALE_DB_VERSION;
	}
}
