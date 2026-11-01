<?php
/**
 * Queue pause read.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `background_paused` read of the workers and the Jobs screen, loaded on first use.
 */
final class QueuePause {

	/**
	 * Whether the queue is paused via the `background_paused` setting.
	 *
	 * Fails open (not paused) when Settings has not booted, so early bootstrap cannot deadlock the queue.
	 *
	 * @return bool
	 */
	public static function queue_is_paused(): bool {
		// Re-read at most once per `pause_refresh_window` seconds, so an operator pause lands within a tick without a read on
		// every progress callback. Keyed by blog: one PHP-FPM child serves workers for several blogs.
		static $cache = [];

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$window  = (int) apply_filters( 'perflocale/jobs/pause_refresh_window', 10 );
		$now     = time();

		if ( isset( $cache[ $blog_id ] )
			&& ( $now - $cache[ $blog_id ]['refreshed'] ) < max( 1, $window )
		) {
			return (bool) $cache[ $blog_id ]['value'];
		}

		// Bounded for long-running workers that touch many blogs; oldest entries drop first.
		if ( count( $cache ) > 64 ) {
			$cache = array_slice( $cache, -32, null, true );
		}

		// Read the row directly: forcing get_option() to re-read means deleting `alloptions`, which on a persistent object
		// cache makes every web request reload it. Core's `pre_option_*` / `option_*` filters are applied under their exact
		// core names, so operator pause filters still apply. See docs-ai/11-code-rationale.md, "WorkerRegistry: pause read".
		$stored = apply_filters( 'pre_option_perflocale_settings', false, 'perflocale_settings', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( false === $stored ) {
			$stored = null;
			global $wpdb;

			if ( $wpdb instanceof \wpdb ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$raw = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
						'perflocale_settings'
					)
				);

				if ( is_string( $raw ) ) {
					// Parity with get_option(): core's exact `option_{$option}` hook name.
					$stored = apply_filters( 'option_perflocale_settings', maybe_unserialize( $raw ), 'perflocale_settings' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				}
			}
		}

		if ( is_array( $stored ) ) {
			$value             = ! empty( $stored['background_paused'] );
			$cache[ $blog_id ] = [
				'value'     => $value,
				'refreshed' => $now,
			];
			return $value;
		}

		// Option missing or filtered to a non-array: fall back to the in-request Settings value.
		$fallback = false;
		try {
			$plugin = \PerfLocale\Plugin::get_instance();
			if ( $plugin->has( 'settings' ) ) {
				$settings = $plugin->get( 'settings' );
				if ( $settings instanceof \PerfLocale\Settings ) {
					$fallback = (bool) $settings->get( 'background_paused', false );
				}
			}
		} catch ( \Throwable $e ) {
			$fallback = false;

			// Fail open so jobs keep running, but log it: the container should always be up here. The per-blog cache
			// limits this to one line per refresh window.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[PerfLocale] queue_is_paused settings lookup threw for blog ' . (int) $blog_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for an unexpected settings/container fault path.
			}
		}

		$cache[ $blog_id ] = [
			'value'     => $fallback,
			'refreshed' => $now,
		];
		return $fallback;
	}
}
