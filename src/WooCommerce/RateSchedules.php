<?php
/**
 * The exchange-rate cron intervals.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `cron_schedules` intervals of ExchangeRateSync, in their own class so reading the schedules loads neither
 * ExchangeRateSync nor more of WcLazyHooks, which every WooCommerce page loads.
 *
 * @since 1.0.7.5
 * @internal
 */
final class RateSchedules {

	/**
	 * Add the custom intervals that are not registered yet.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add( array $schedules ): array {
		// Prefixed: the registry is global. Setting slugs stay unprefixed (ExchangeRateSync::cron_schedule_name()).
		$custom = [
			'perflocale_every_2_hours'  => [
				'interval' => 2 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 2 Hours', 'perflocale' ),
			],
			'perflocale_every_4_hours'  => [
				'interval' => 4 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 4 Hours', 'perflocale' ),
			],
			'perflocale_every_6_hours'  => [
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 6 Hours', 'perflocale' ),
			],
			'perflocale_every_8_hours'  => [
				'interval' => 8 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 8 Hours', 'perflocale' ),
			],
			'perflocale_every_12_hours' => [
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 12 Hours', 'perflocale' ),
			],
			'perflocale_monthly'        => [
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'perflocale' ),
			],
		];

		foreach ( $custom as $key => $schedule ) {
			if ( ! isset( $schedules[ $key ] ) ) {
				$schedules[ $key ] = $schedule;
			}
		}

		return $schedules;
	}
}
