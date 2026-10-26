<?php
/**
 * Plugin deactivator.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin deactivation cleanup; data stays (uninstall.php deletes it).
 *
 * Everything cleaned is per blog, Action Scheduler's tables included (named from `$wpdb->prefix`), so a network
 * deactivation runs deactivate_for_blog() on every site, switched in.
 */
final class Deactivator {

	/**
	 * Share of `max_execution_time` the network sweep may spend before it stops (see sweep_deadline()).
	 *
	 * @var float
	 */
	private const NETWORK_SWEEP_TIME_SHARE = 0.7;

	/**
	 * Run deactivation tasks.
	 *
	 * @param bool $network_deactivating True on "Network Deactivate" of a multisite install.
	 * @return void
	 */
	public static function deactivate( bool $network_deactivating = false ): void {
		if ( $network_deactivating && is_multisite() ) {
			self::deactivate_for_network();
		} else {
			// Single site, or one site of a network.
			self::deactivate_for_blog();
		}

		/** @hook perflocale/deactivated Fires after the plugin is deactivated. */
		do_action( 'perflocale/deactivated' );
	}

	/**
	 * Network-deactivation sweep: the per-blog cleanup on every site of the CURRENT network, switched in.
	 *
	 * Scoped by `network_id` (WP_Site_Query's 0 means all networks) and chunked like network activation. Every site
	 * status is swept: archived, spam and deleted blogs hold the plugin's rules and schedules too.
	 *
	 * @return void
	 */
	private static function deactivate_for_network(): void {
		/** @hook perflocale/activation/chunk_size Sites fetched per iteration. Must be >= 1. */
		$filtered_chunk = apply_filters( 'perflocale/activation/chunk_size', 100 );
		// A non-numeric return takes the default, not a chunk of 1 (one site query per blog).
		$chunk = is_numeric( $filtered_chunk ) ? max( 1, (int) $filtered_chunk ) : 100;

		$deadline       = self::sweep_deadline();
		$network_id     = get_current_network_id();
		$offset         = 0;
		$entered        = 0;
		$swept          = 0;
		$out_of_time    = false;
		$got_full_chunk = false;

		do {
			$site_ids = get_sites(
				[
					'fields'     => 'ids',
					'number'     => $chunk,
					'offset'     => $offset,
					// Offset paging needs a fixed order; it also puts the main site first.
					'orderby'    => 'id',
					'network_id' => $network_id,
				]
			);
			$site_ids = (array) $site_ids;

			foreach ( $site_ids as $site_id ) {
				$site_id = (int) $site_id;
				if ( $site_id <= 0 ) {
					continue;
				}

				// At least one blog is swept even when the budget is already spent; checked before switch_to_blog(), so no
				// blog is left half-cleaned. Counts blogs entered, not cleaned: if every blog throws, the deadline still engages.
				if ( $entered > 0 && null !== $deadline && microtime( true ) >= $deadline ) {
					$out_of_time = true;
					break 2;
				}

				++$entered;
				switch_to_blog( $site_id );
				try {
					self::deactivate_for_blog();
					// Fully cleaned blogs, for the out-of-time log line.
					++$swept;
				} catch ( \Throwable $e ) {
					// One blog's failure does not stop the sweep.
					if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						error_log( '[PerfLocale] deactivate_for_blog failed on site ' . $site_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				} finally {
					restore_current_blog();
				}
			}

			$offset        += $chunk;
			$got_full_chunk = ( count( $site_ids ) === $chunk );
		} while ( $got_full_chunk );

		if ( $out_of_time ) {
			// Always logged: the plugin is off by now, so no screen can show it.
			$total = (int) get_sites(
				[
					'network_id' => $network_id,
					'count'      => true,
				]
			);

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the incomplete-cleanup path.
			error_log(
				sprintf(
					'[PerfLocale] network deactivation swept %d of %d sites before running out of max_execution_time budget; the remaining sites keep their perflocale rewrite rules, scheduled events and Translator role. Re-activate and network-deactivate again from WP-CLI (where max_execution_time is 0) to finish the sweep.',
					$swept,
					$total
				)
			);
		}
	}

	/**
	 * Deadline for the network sweep, from the request start, or null without an execution limit (WP-CLI).
	 *
	 * Core records the deactivation only after this hook, so a timeout mid-sweep leaves the plugin active on stripped
	 * blogs; stopping early leaves the rest untouched and lets core finish.
	 *
	 * @return float|null
	 */
	private static function sweep_deadline(): ?float {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return null;
		}

		$started = isset( $GLOBALS['timestart'] ) && is_numeric( $GLOBALS['timestart'] )
			? (float) $GLOBALS['timestart']
			: microtime( true );

		// The rest covers core's own work after the hook and the blog mid-cleanup at the deadline.
		return $started + max( 1.0, $limit * self::NETWORK_SWEEP_TIME_SHARE );
	}

	/**
	 * Per-blog deactivation cleanup of the current blog: schedules, rewrite rules, the Translator role and caches.
	 *
	 * @return void
	 */
	private static function deactivate_for_blog(): void {
		self::unschedule_background_events();

		// Unhooked first: the still-hooked filter would add every language rule back when the rules regenerate.
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'rewrite_manager' ) ) {
			remove_filter( 'rewrite_rules_array', [ $plugin->get( 'rewrite_manager' ), 'filter_rewrite_rules' ] );
		}

		// Deleted, not flush_rewrite_rules(): switch_to_blog() keeps the first blog's $wp_rewrite, so a flush would write
		// its rules into this blog, and they would stick. Each blog regenerates its own on its next front-end request.
		delete_option( 'rewrite_rules' );

		// Per-blog flag set by the Activator.
		delete_option( 'perflocale_flush_rules' );

		// The Translator role and the perflocale_* caps of this blog; the Activator installs them again.
		\PerfLocale\Admin\TranslatorRole::remove_roles();

		// Every plugin cache of this blog, or stale links serve wrong-language content after reactivation.
		try {
			$plugin = Plugin::get_instance();

			if ( $plugin->has( 'cache' ) ) {
				$plugin->get( 'cache' )->flush_all();
			}
		} catch ( \Throwable $e ) {
			// The container may not be initialised: clear this blog's transients directly.
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_perflocale_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_perflocale_' ) . '%'
				)
			);
			wp_cache_flush();
		}
	}

	/**
	 * Clear this blog's pending background work in WP-Cron and Action Scheduler, both per blog.
	 *
	 * @return void
	 */
	private static function unschedule_background_events(): void {
		global $wpdb;

		// Errors silenced: a never-visited subsite has no Action Scheduler tables, and wpdb logs (and may print) each
		// failed query even without WP_DEBUG, which breaks the redirect. An absent table means nothing to cancel.
		$suppress_errors = $wpdb->suppress_errors( true );

		try {
			// Every occurrence in both engines: the engine setting can change, leaving a hook pending in the other one.
			foreach ( self::cron_hooks() as $hook ) {
				\PerfLocale\Background\BackgroundEvents::unschedule_all( $hook );
			}

			// The whole group too, for hooks not in cron_hooks() (per-job runner hooks); Action Scheduler may be absent.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( '', [], \PerfLocale\Background\ActionSchedulerRunner::GROUP );
			}
		} finally {
			$wpdb->suppress_errors( $suppress_errors );
		}
	}

	/**
	 * Every cron hook the plugin schedules, from DataRegistry::cron_hooks( 'deactivate' ).
	 *
	 * Includes retired hooks of removed features, so upgraded sites get them unscheduled: never drop a retired entry.
	 *
	 * @return list<string>
	 */
	private static function cron_hooks(): array {
		return \PerfLocale\Database\DataRegistry::cron_hooks( 'deactivate' );
	}
}
