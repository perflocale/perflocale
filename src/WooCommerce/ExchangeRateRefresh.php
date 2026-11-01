<?php
/**
 * One-shot exchange-rate refresh after a base-currency change.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\WooCommerce;

use PerfLocale\Background\BackgroundEvents;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues and runs one pending refresh for a new base currency, beside the single recurring sync.
 *
 * Loaded only by the admin currency save and when its event runs. Args: [ queued_at, attempt ].
 */
final class ExchangeRateRefresh {

	/**
	 * Event hook; ExchangeRateSync::register_write_hooks() writes it out.
	 *
	 * @var string
	 */
	public const HOOK = 'perflocale_exchange_rate_refresh';

	/**
	 * The sync lock ExchangeRateSync::sync_rates() takes.
	 *
	 * @var string
	 */
	public const LOCK = 'exchange_rate_sync';

	/**
	 * Seconds between attempts while another sync holds the lock.
	 *
	 * @var int
	 */
	private const RETRY_DELAY = MINUTE_IN_SECONDS;

	/**
	 * Last attempt number; the retries outlast the sync lock's 5-minute TTL.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPT = 6;

	/**
	 * Queue the refresh now, replacing a pending one, while auto-sync is on.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool Whether the scheduler accepted it.
	 */
	public static function queue( Settings $settings ): bool {
		if ( ! (bool) $settings->get( 'wc_exchange_rate_auto', false ) ) {
			return false;
		}

		BackgroundEvents::unschedule_all( self::HOOK );

		return BackgroundEvents::enqueue( self::HOOK, [ time(), 0 ] );
	}

	/**
	 * Event callback: sync for the current base unless one already did since the save.
	 *
	 * @param int|string $queued_at When the save queued the refresh.
	 * @param int|string $attempt   Attempt number, 0 first.
	 * @return void
	 */
	public static function run( $queued_at = 0, $attempt = 0 ): void {
		$queued_at = (int) $queued_at;
		$attempt   = (int) $attempt;
		$settings  = \PerfLocale\Plugin::get_instance()->get( 'settings' );

		if ( ! $settings instanceof Settings || ! (bool) $settings->get( 'wc_exchange_rate_auto', false ) ) {
			return;
		}

		// As sync_rates() before its lock: a failing provider is left to the recurring sync.
		if ( self::synced_since( $queued_at ) || \PerfLocale\Plugin::is_uninstalling() || ! function_exists( 'get_woocommerce_currency' ) || \PerfLocale\Concurrency\Breaker::is_open( 'fx_sync' ) ) {
			return;
		}

		// Checked again under the lock: a sync that finished while it was taken makes this one redundant.
		$ran = Lock::with(
			self::LOCK,
			5 * MINUTE_IN_SECONDS,
			static function () use ( $settings, $queued_at ): bool {
				if ( ! self::synced_since( $queued_at, true ) ) {
					( new ExchangeRateSync( $settings ) )->do_sync_rates();
				}

				return true;
			}
		);

		// Not acquired (the holder may be the old-base sync, or its row expires this second): retry.
		if ( null === $ran && $attempt < self::MAX_ATTEMPT ) {
			BackgroundEvents::enqueue( self::HOOK, [ $queued_at, $attempt + 1 ], self::RETRY_DELAY );
		}
	}

	/**
	 * Whether a sync for the current base finished at or after $since.
	 *
	 * @param int  $since Unix time.
	 * @param bool $fresh Read past this request's option cache: another worker may have synced since it was filled.
	 * @return bool
	 */
	private static function synced_since( int $since, bool $fresh = false ): bool {
		if ( $fresh ) {
			// A persistent cache's shared copies are current: refresh this request's; without one, drop them (local).
			// The base has its own key when it is not autoloaded.
			foreach ( [ ExchangeRateSync::LAST_SYNC_OPTION, 'woocommerce_currency', 'alloptions' ] as $key ) {
				if ( wp_using_ext_object_cache() ) {
					wp_cache_get( $key, 'options', true );
				} else {
					wp_cache_delete( $key, 'options' );
				}
			}

			\PerfLocale\Database\NotOptions::unlist( [ ExchangeRateSync::LAST_SYNC_OPTION, 'woocommerce_currency' ] );
		}

		$last = ExchangeRateSync::get_last_sync();
		$base = get_option( 'woocommerce_currency', 'USD' );

		return (int) ( $last['timestamp'] ?? 0 ) >= $since && is_string( $base ) && ( $last['base'] ?? '' ) === $base;
	}
}
