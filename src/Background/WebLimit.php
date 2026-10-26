<?php
/**
 * Guard for work one web request cannot finish.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses, on a web request, a one-request job (migrations, data import and export, string scan) whose size estimate
 * exceeds the request time limit, naming the WP-CLI command instead; Site Health lists a week of refusals.
 * The limit is max_execution_time (TranslatePress: its own raised one); a host kill PHP cannot see is for the filter.
 *
 * @internal
 */
final class WebLimit {

	/**
	 * Seconds per unit of each type's estimate (AbstractJob::estimated_size()); measured rates carry 2x headroom over the
	 * lab worst, the migration rates are provisional until measured (dev OPEN-ITEMS D82).
	 */
	private const SECONDS_PER_UNIT = [
		'wpml_migration'           => 0.055,
		'polylang_migration'       => 0.055,
		'translatepress_migration' => 0.055,
		'data_import'              => 0.004, // Lab worst 0.0018 s per 200 bytes (Merge, wall): 2x headroom.
		'data_export'              => 0.0001, // Lab worst 6.2e-6 s per row: wide headroom.
		'string_scan'              => 0.01, // Lab worst 0.005 s per unit: 2x headroom.
	];

	/**
	 * Seconds of the limit kept for WordPress and the end of the request.
	 */
	private const MARGIN = 5;

	/**
	 * Option of the refusals Site Health shows for a week: job type => [ command, time ].
	 */
	public const NOTE = 'perflocale_web_limit_refused';

	/**
	 * Why this dispatch cannot run in this web request ('' when it can), naming $command.
	 *
	 * @param AbstractJob          $job     Job.
	 * @param array<string, mixed> $args    Dispatch args.
	 * @param string               $command The WP-CLI command that runs it.
	 * @return string
	 */
	public static function refusal( AbstractJob $job, array $args, string $command ): string {
		$type  = $job->get_type();
		$limit = 0;
		$cap   = self::cap( $type, $limit );

		if ( $cap < 0 || $job->estimated_size( $args, $cap < PHP_INT_MAX ? $cap + 1 : $cap ) <= $cap ) {
			return '';
		}

		return self::refuse( $type, $cap, $limit, $command );
	}

	/**
	 * Why $units of $type cannot run in this web request ('' when they can): the check before a data-import upload.
	 *
	 * @param string $type    Job type.
	 * @param int    $units   Size in the type's unit (data_import: bytes / 200).
	 * @param string $command The WP-CLI command that runs it.
	 * @return string
	 */
	public static function units_refusal( string $type, int $units, string $command ): string {
		$limit = 0;
		$cap   = self::cap( $type, $limit );

		return $cap < 0 || $units <= $cap ? '' : self::refuse( $type, $cap, $limit, $command );
	}

	/**
	 * Most units of $type one web request runs; -1 for no cap (no rate for the type, WP-CLI, or no time limit).
	 *
	 * @param string $type  Job type.
	 * @param int    $limit Set to the request time limit in seconds (0: none).
	 * @return int
	 */
	private static function cap( string $type, int &$limit ): int {
		if ( ! isset( self::SECONDS_PER_UNIT[ $type ] ) || 'cli' === PHP_SAPI ) {
			return -1;
		}

		$limit = (int) ini_get( 'max_execution_time' );

		if ( 'translatepress_migration' === $type ) {
			$limit = \PerfLocale\Migration\TranslatePressImporter::time_limit( $limit );
		}

		// 0 is no PHP limit: no cap unless the filter sets one.
		$cap = $limit > 0 ? (int) floor( max( 1, $limit - self::MARGIN ) / self::SECONDS_PER_UNIT[ $type ] ) : -1;

		/**
		 * Filter the most estimate units of a job type one web request runs; a larger dispatch is refused with its WP-CLI
		 * command. Negative: no cap.
		 *
		 * @hook  perflocale/jobs/web_unit_cap
		 * @since 1.0.7.4
		 *
		 * @param int    $cap   Default from the time limit and the type's seconds per unit; -1 with no time limit.
		 * @param string $type  Job type: wpml_migration, polylang_migration, translatepress_migration, data_import (bytes / 200), data_export (table rows) or string_scan.
		 * @param int    $limit The time limit in seconds (0: none).
		 */
		return \PerfLocale\Admin\Typed::int( apply_filters( 'perflocale/jobs/web_unit_cap', $cap, $type, $limit ), $cap );
	}

	/**
	 * Record the refusal for Site Health and return its message.
	 *
	 * @param string $type    Job type.
	 * @param int    $cap     The cap that was exceeded.
	 * @param int    $limit   The request time limit in seconds (0: none).
	 * @param string $command The WP-CLI command that runs it.
	 * @return string
	 */
	private static function refuse( string $type, int $cap, int $limit, string $command ): string {
		$notes          = get_option( self::NOTE, [] );
		$notes          = is_array( $notes ) ? $notes : [];
		$notes[ $type ] = [ $command, time() ];
		update_option( self::NOTE, $notes, false );

		if ( 'data_import' === $type ) {
			return sprintf(
				/* translators: %s: largest file this site imports through the screen, e.g. "512 MB". "wp perflocale import" is a command and stays in English. */
				__( 'This file is larger than this site imports through this screen (%s). Import it with WP-CLI instead: wp perflocale import <file>.', 'perflocale' ),
				size_format( $cap * 200 )
			);
		}

		if ( $limit <= 0 ) {
			return sprintf(
				/* translators: %s: a WP-CLI command, which stays in English. */
				__( 'This is more than one web request can finish on this site. Run it with WP-CLI instead: %s', 'perflocale' ),
				$command
			);
		}

		return sprintf(
			/* translators: 1: the request time limit in seconds, 2: a WP-CLI command, which stays in English. */
			__( 'This is more than one web request can finish on this site (its time limit is %1$d seconds). Run it with WP-CLI instead: %2$s', 'perflocale' ),
			$limit,
			$command
		);
	}
}
