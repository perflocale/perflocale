<?php
/**
 * Content ReSync's off state: the stop of a queued run, the refusal texts and the off-period marker.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Background\JobState;
use PerfLocale\Background\WorkerRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only by a queued run while off, a refused ReSync action, a run, or a marker write; ResyncSwitch holds the switch.
 *
 * @since 1.0.8
 */
final class ResyncOff {

	/**
	 * A content_resync event while ReSync is off: cancel the run and say why (the job row is per blog).
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $args   Worker args.
	 * @return void
	 */
	public static function stop_run( string $job_id, array $args = [] ): void {
		$blog     = Scalar::int( $args['__perflocale_blog_id'] ?? 0 );
		$switched = $blog > 0 && is_multisite() && get_current_blog_id() !== $blog;

		if ( $switched ) {
			switch_to_blog( $blog );
		}

		try {
			if ( ResyncSwitch::on() ) {
				ResyncSwitch::register_workers();
				WorkerRegistry::run( $job_id, $args );
			} elseif ( JobState::cancel( $job_id ) ) {
				JobState::append_log( $job_id, self::off_text() );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The off marker as no alloptions copy holds it for this period: read it past every cache; this period's marker
	 * outside alloptions is made autoloaded with its time; anything else (none, a finished period, unreadable, a failed
	 * read) starts a new period, trusting states less, never more. A failed write changes nothing, so the next off
	 * request tries again.
	 *
	 * @return void
	 */
	public static function start_off_period(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$raw = self::read( $wpdb );

		self::off_period( $wpdb, '' === $wpdb->last_error && is_string( $raw ) ? maybe_unserialize( $raw ) : null );
	}

	/**
	 * ReSync on and its marker not in alloptions: store one, so later off requests know ReSync has been on. No marker
	 * row and no ReSync state trusts every state, insert-only: a marker another request wrote since the read is newer and
	 * stays. Anything else counts as an off period (start_off_period()). A failed read writes nothing; the next on request tries again.
	 *
	 * @return void
	 */
	public static function mark_on(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$raw = self::read( $wpdb );

		if ( '' !== $wpdb->last_error ) {
			return;
		}

		if ( null === $raw && self::no_states( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The options cache is dropped below.
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)', $wpdb->options, ResyncSwitch::OFF_OPTION, maybe_serialize( [ 'at' => 0, 'reread' => true ] ), self::autoload_yes() ) ?? '' );
			self::drop_caches();

			return;
		}

		self::off_period( $wpdb, is_string( $raw ) ? maybe_unserialize( $raw ) : null );
	}

	/**
	 * The stored marker past every cache (a cached copy may be the stale one).
	 *
	 * @param \wpdb $wpdb Database.
	 * @return mixed
	 */
	private static function read( \wpdb $wpdb ): mixed {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Past every cache: a cached copy may be the stale one.
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, ResyncSwitch::OFF_OPTION ) );
	}

	/**
	 * Keep this period's marker (made autoloaded) or start a new period now.
	 *
	 * @param \wpdb $wpdb   Database.
	 * @param mixed $marker The stored marker; null when none or unread.
	 * @return void
	 */
	private static function off_period( \wpdb $wpdb, mixed $marker ): void {
		if ( is_array( $marker ) && empty( $marker['reread'] ) ) {
			self::autoload_marker( $wpdb );

			return;
		}

		self::write( $wpdb );
	}

	/**
	 * Whether resync_state holds no row; a failed read counts as rows. Only a stored DB version below 2 (no table yet)
	 * skips the check: an unread version is no proof.
	 *
	 * @param \wpdb $wpdb Database.
	 * @return bool
	 */
	private static function no_states( \wpdb $wpdb ): bool {
		$version = get_option( 'perflocale_db_version', false );

		if ( is_numeric( $version ) && (int) $version < 2 ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Once per site, on the first on request.
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', \PerfLocale\Database\Schema::table( 'resync_state' ) ) );

		return null === $row && '' === $wpdb->last_error;
	}

	/**
	 * Start an off period now, autoloaded: an upsert, so a row the read missed is replaced, but only by a time at least
	 * its own: a newer marker stays, also when wpdb resends this statement after a lost reply.
	 *
	 * @param \wpdb $wpdb Database.
	 * @return void
	 */
	private static function write( \wpdb $wpdb ): void {
		$now = time();
		$tag = 's:2:"at";i:';

		// The CASE reads the stored `at` (serialize() writes `s:2:"at";i:<digits>;`; 0 when none) before option_value is assigned.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The options cache is dropped below.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`), `autoload` = VALUES(`autoload`), `option_value` = CASE WHEN (CASE WHEN INSTR(`option_value`, %s) > 0 AND INSTR(SUBSTR(`option_value`, INSTR(`option_value`, %s) + 11), %s) > 1 THEN CAST(SUBSTR(`option_value`, INSTR(`option_value`, %s) + 11, INSTR(SUBSTR(`option_value`, INSTR(`option_value`, %s) + 11), %s) - 1) AS SIGNED) ELSE 0 END) > %d THEN `option_value` ELSE VALUES(`option_value`) END',
				$wpdb->options,
				ResyncSwitch::OFF_OPTION,
				maybe_serialize(
					[
						'at'     => $now,
						'reread' => false,
					]
				),
				self::autoload_yes(),
				$tag,
				$tag,
				';',
				$tag,
				$tag,
				';',
				$now
			) ?? ''
		);
		self::drop_caches();
	}

	/**
	 * Make this period's marker autoloaded (wp_set_option_autoload() where core has it, else the row), then drop the
	 * caches: core changes nothing on a row already autoloaded, so a stale alloptions copy would stay.
	 *
	 * @param \wpdb $wpdb Database.
	 * @return void
	 */
	private static function autoload_marker( \wpdb $wpdb ): void {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( ResyncSwitch::OFF_OPTION, true );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The options cache is dropped below.
			$wpdb->update( $wpdb->options, [ 'autoload' => self::autoload_yes() ], [ 'option_name' => ResyncSwitch::OFF_OPTION ], [ '%s' ], [ '%s' ] );
		}

		self::drop_caches();
	}

	/**
	 * The autoload value that puts a row in alloptions: `on` from WordPress 6.6, `yes` before.
	 *
	 * @return string
	 */
	private static function autoload_yes(): string {
		return function_exists( 'wp_determine_option_autoload_value' ) ? 'on' : 'yes';
	}

	/**
	 * Drop the marker's cached copies (its value, alloptions, its notoptions entry) after a write past update_option().
	 *
	 * @return void
	 */
	private static function drop_caches(): void {
		wp_cache_delete( ResyncSwitch::OFF_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		\PerfLocale\Database\NotOptions::unlist( [ ResyncSwitch::OFF_OPTION ] );
	}

	/**
	 * Why a ReSync run or screen is refused while ReSync is off.
	 *
	 * @return string
	 */
	public static function off_text(): string {
		return __( 'Content ReSync is off, so this run was canceled. Turn it on in the settings to run it again.', 'perflocale' );
	}

	/**
	 * Why a ReSync action is refused while ReSync is off.
	 *
	 * @return string
	 */
	public static function refused_text(): string {
		return __( 'Content ReSync is off. Turn it on in the settings first.', 'perflocale' );
	}

	/**
	 * End a ReSync request that arrived while ReSync is off (a stale form or link).
	 *
	 * @return never
	 */
	public static function refuse(): never {
		wp_die(
			esc_html( self::refused_text() ),
			'',
			[
				'response'  => 409,
				'back_link' => true,
			]
		);
	}

	/**
	 * The time states must be newer than to be trusted: when ReSync was last seen off; 0 when never.
	 *
	 * @return int
	 */
	public static function trusted_after(): int {
		$marker = get_option( ResyncSwitch::OFF_OPTION, null );

		return is_array( $marker ) ? Scalar::int( $marker['at'] ?? 0 ) : 0;
	}

	/**
	 * Whether no ReSync run finished since ReSync was back on (the estimate says the sources are read again).
	 *
	 * @return bool
	 */
	public static function reread_pending(): bool {
		$marker = get_option( ResyncSwitch::OFF_OPTION, null );

		return is_array( $marker ) && empty( $marker['reread'] );
	}

	/**
	 * A ReSync run finished while on: later estimates no longer say the sources are read again. Read past the caches and
	 * set only while the row still holds those bytes: a stale copy must not replace a newer off period's time. The marker
	 * stays in alloptions: an off request without it there takes the site as one where ReSync was never on.
	 *
	 * @return void
	 */
	public static function reread_done(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			$marker = get_option( ResyncSwitch::OFF_OPTION, null );

			if ( is_array( $marker ) && empty( $marker['reread'] ) ) {
				$marker['reread'] = true;
				update_option( ResyncSwitch::OFF_OPTION, $marker, true );
			}

			return;
		}

		$raw    = self::read( $wpdb );
		$marker = '' === $wpdb->last_error && is_string( $raw ) ? maybe_unserialize( $raw ) : null;

		if ( ! is_string( $raw ) || ! is_array( $marker ) || ! empty( $marker['reread'] ) ) {
			return;
		}

		$marker['reread'] = true;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-set on the row; the options cache is dropped below.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)', $wpdb->options, maybe_serialize( $marker ), self::autoload_yes(), ResyncSwitch::OFF_OPTION, $raw ) ?? '' );
		self::drop_caches();
	}
}
