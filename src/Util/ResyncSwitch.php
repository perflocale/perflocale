<?php
/**
 * The Content ReSync on/off switch.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Background\WorkerRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Content ReSync runs on this blog: the `resync_enabled` setting (off unless saved on), then the filter.
 * Off, no ReSync class loads and its data is kept; privacy export and erasure, user deletion, a permanent post delete
 * and (once ReSync has been on) the daily housekeeping still reach that data, and the next run re-reads states synced
 * before it was off.
 * Loaded on write requests: what only an off run, a refusal or a run needs is in ResyncOff.
 *
 * @since 1.0.8
 */
final class ResyncSwitch {

	/** `{at: int, reread: bool}`: when ReSync was last seen off (0: never), whether a run finished since; absent: never on. */
	public const OFF_OPTION = 'perflocale_resync_off_at';

	/**
	 * The switch per blog, read once per request.
	 *
	 * @var array<int, bool>
	 */
	private static array $on = [];

	/**
	 * Whether Content ReSync is on for the current blog.
	 *
	 * @return bool
	 */
	public static function on(): bool {
		$blog = get_current_blog_id();

		if ( ! isset( self::$on[ $blog ] ) ) {
			// The stored row itself: Settings would parse every default for one flag.
			self::$on[ $blog ] = self::decide( get_option( 'perflocale_settings', [] ) );
		}

		return self::$on[ $blog ];
	}

	/**
	 * The switch read again from the settings row past every cache, then the filter; later on() calls answer it.
	 *
	 * @return bool|null Null when the row could not be read (never a confirmed on; on() keeps its value).
	 */
	public static function fresh(): ?bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return self::on();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Past the alloptions cache: another request may have changed it.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, 'perflocale_settings' ) );

		if ( null === $raw && '' !== $wpdb->last_error ) {
			return null;
		}

		self::$on[ get_current_blog_id() ] = self::decide( is_string( $raw ) ? maybe_unserialize( $raw ) : [] );

		return self::on();
	}

	/**
	 * The setting in a settings row, then the filter.
	 *
	 * @param mixed $row The stored `perflocale_settings` value.
	 * @return bool
	 */
	private static function decide( mixed $row ): bool {
		$stored = is_array( $row ) && ! empty( $row['resync_enabled'] );

		/**
		 * Filters whether Content ReSync is on; the value wins over the setting. It is read once per request where ReSync
		 * is used, and again during a ReSync run (before each pair that may write): add it from a plugin or a must-use
		 * plugin, not a theme.
		 *
		 * @hook perflocale/resync/enabled
		 * @since 1.0.8
		 *
		 * @param bool $enabled The "Content ReSync" setting.
		 */
		return (bool) apply_filters( 'perflocale/resync/enabled', $stored );
	}

	/**
	 * The held updates of these posts, read before an edit screen loads the review classes: post id => newest pending
	 * change id, handed to ReviewUpdate::links() so the screen reads them once.
	 *
	 * @param array<int, mixed> $post_ids The posts a screen shows.
	 * @return array<int, int>
	 */
	public static function waiting_updates( array $post_ids ): array {
		global $wpdb;

		$version = get_option( 'perflocale_db_version', 0 );
		$ids     = [];

		foreach ( $post_ids as $id ) {
			$id = is_numeric( $id ) ? (int) $id : 0;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( [] === $ids || ! current_user_can( 'perflocale_translate' ) || ! is_numeric( $version ) || (int) $version < 2 || ! $wpdb instanceof \wpdb ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- The IN list is placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, object_id FROM %i WHERE object_type = %s AND object_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') AND status = %s ORDER BY id',
				array_merge( [ \PerfLocale\Database\Schema::table( 'pending_changes' ), 'post' ], $ids, [ 'pending' ] )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_numeric( $row['object_id'] ?? null ) && is_numeric( $row['id'] ?? null ) ) {
				$out[ (int) $row['object_id'] ] = (int) $row['id'];
			}
		}

		return $out;
	}

	/**
	 * Forget the per-request reads (tests, and a settings save that changed the switch).
	 *
	 * @internal
	 * @return void
	 */
	public static function reset(): void {
		self::$on = [];
	}

	/**
	 * Bind the automation housekeeping (daily, and hourly re-runs) and the content_resync worker when on; when off, a
	 * stop for queued runs, plus the housekeeping and the off marker only where ReSync has been on (its marker, written
	 * on the first on request, is in alloptions).
	 *
	 * @return void
	 */
	public static function register_workers(): void {
		$marked = isset( wp_load_alloptions()[ self::OFF_OPTION ] );

		if ( self::on() ) {
			self::bind_housekeeping();
			WorkerRegistry::register(
				'content_resync',
				static fn(): \PerfLocale\Background\AbstractJob => new \PerfLocale\Background\Jobs\ContentResyncJob()
			);

			if ( ! $marked ) {
				ResyncOff::mark_on();
			}

			return;
		}

		add_action( WorkerRegistry::worker_hook( 'content_resync' ), [ ResyncOff::class, 'stop_run' ], 10, 2 );

		if ( $marked ) {
			self::bind_housekeeping();
			self::note_off();
		}
	}

	/**
	 * Bind the automation housekeeping; it acts only while the tables exist.
	 *
	 * @return void
	 */
	private static function bind_housekeeping(): void {
		add_action( 'perflocale_jobs_gc', [ \PerfLocale\Automation\Housekeeping::class, 'on_jobs_gc' ] );
		add_action( 'perflocale_jobs_watchdog', [ \PerfLocale\Automation\Housekeeping::class, 'on_watchdog' ] );
	}

	/**
	 * Record that ReSync is off, once per off period, so the next run after it is back on re-reads the sources.
	 * The marker is autoloaded: an off request of the same period reads alloptions only; anything else
	 * goes to ResyncOff::start_off_period() (a read past every cache and a checked write).
	 *
	 * @return void
	 */
	public static function note_off(): void {
		$all    = wp_load_alloptions();
		$marker = is_string( $all[ self::OFF_OPTION ] ?? null ) ? maybe_unserialize( $all[ self::OFF_OPTION ] ) : null;

		if ( ! is_array( $marker ) || ! empty( $marker['reread'] ) ) {
			ResyncOff::start_off_period();
		}
	}

	/**
	 * User deletion: the job anonymisation and the automation tables, ReSync on or off (their data is kept while off).
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function on_user_deleted( int $user_id ): void {
		\PerfLocale\Automation\PersonalData::on_user_deleted( $user_id );
	}
}
