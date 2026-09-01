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
 * Handles plugin deactivation cleanup.
 *
 * Does NOT delete data - that is handled by uninstall.php.
 *
 * Multisite semantics: when the network admin deactivates the plugin
 * via "Network Deactivate", WordPress passes $network_deactivating = true
 * to the registered callback. EVERY piece of cleanup this class performs is
 * per-blog - rewrite rules, per-blog options, per-blog roles, per-blog
 * caches, WP-Cron events AND Action Scheduler actions - so all of it MUST
 * run on every subsite, not just the network-admin blog. Without that loop
 * each subsite keeps stale perflocale_* rewrite rules until it's next
 * visited (the rules sit in the per-blog `rewrite_rules` option), and
 * per-blog cleanup tasks (role caps, transient cleanup, etc.) leak forever
 * — which wp.org's automated uninstall-cleanup checks flag.
 *
 * Action Scheduler is NOT network-wide, despite being commonly described
 * that way — this file used to claim it and swept the AS queue once, outside
 * the loop. ActionScheduler_Abstract_Schema::get_full_table_name() builds its
 * table names from `$wpdb->prefix`, not `$wpdb->base_prefix`, so every blog
 * owns its own wp_<id>_actionscheduler_* set. AS registers those names in
 * `$wpdb->tables`, which means switch_to_blog() re-points them and a group
 * sweep issued while switched in hits THAT blog's queue. Refuted on a live
 * 4-blog network: the single outside-the-loop sweep cleared the network
 * admin's blog and left every other blog's pending `perflocale` actions
 * scheduled. The sweep therefore lives in {@see deactivate_for_blog()}.
 */
final class Deactivator {

	/**
	 * Share of PHP's `max_execution_time` the network sweep is allowed to
	 * spend before it stops and leaves the remaining blogs untouched.
	 *
	 * See {@see sweep_deadline()} for why stopping short beats running out.
	 *
	 * @var float
	 */
	private const NETWORK_SWEEP_TIME_SHARE = 0.7;

	/**
	 * Run deactivation tasks.
	 *
	 * @param bool $network_deactivating True when invoked via "Network Deactivate"
	 *                                   on a multisite install. WordPress passes
	 *                                   this argument to the
	 *                                   register_deactivation_hook callback;
	 *                                   default false preserves the single-site
	 *                                   call shape.
	 * @return void
	 */
	public static function deactivate( bool $network_deactivating = false ): void {
		if ( $network_deactivating && is_multisite() ) {
			self::deactivate_for_network();
		} else {
			// Single-site (or per-site deactivate on multisite) — just clean
			// up the blog we're currently on.
			self::deactivate_for_blog();
		}

		/** @hook perflocale/deactivated Fires after the plugin is deactivated. */
		do_action( 'perflocale/deactivated' );
	}

	/**
	 * Network-deactivation sweep: run the per-blog cleanup on every site of
	 * the CURRENT network, switched in.
	 *
	 * Scoped and chunked the same way the network-activation loop in
	 * perflocale.php is:
	 *
	 *   - `network_id` — WP_Site_Query defaults to `'network_id' => 0`, which
	 *     core documents as "include all networks". On a multi-network install
	 *     an unscoped get_sites() would hand us a sibling network's blogs and
	 *     we'd strip rewrite rules, roles and schedules on a network nobody
	 *     deactivated anything on. Scope it.
	 *   - chunking — a network with tens of thousands of sites must not load
	 *     every row at once. Chunk IDs are ~10 bytes each; the real work is
	 *     the per-site cleanup, so the same 100-ID default (and the same
	 *     filter, so operators tune one number, not two) applies here.
	 *
	 * No filters are passed beyond the network scope: public, archived,
	 * mature, spam and deleted blogs all get swept, since the plugin's rewrite
	 * rules live in every blog's wp_<id>_options table regardless of
	 * public-flag state.
	 *
	 * @return void
	 */
	private static function deactivate_for_network(): void {
		/** @hook perflocale/activation/chunk_size Sites fetched per iteration. Must be >= 1. */
		$filtered_chunk = apply_filters( 'perflocale/activation/chunk_size', 100 );
		// A non-numeric return falls back to the default rather than casting to
		// 0 and clamping to a chunk of 1, which would turn one site query into one
		// site query per blog.
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
					// Deterministic order — `offset` paging is only stable
					// against a fixed sort, and it puts the network's main
					// site (the blog the network admin is standing on) first.
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

				// `$entered > 0` guarantees forward progress. The budget is a
				// hard cutoff, not a work estimate, so a request that reaches
				// this loop having already spent it — a host with a small
				// max_execution_time, or a bulk "deactivate selected" where
				// earlier plugins' hooks burned it — would otherwise sweep
				// NOTHING, not even the blog the network admin is standing on,
				// which the pre-loop code always cleaned. The test still sits
				// BEFORE switch_to_blog(), so no blog is ever left half-cleaned.
				//
				// It counts blogs ENTERED, not blogs cleaned successfully:
				// deactivate_for_blog() can throw part-way through (its failure
				// is caught below so the remaining subsites still get swept),
				// and a network where every blog throws would leave a
				// success-counting guard permanently at 0 — the deadline would
				// never engage and PHP would hard-kill the request mid-loop,
				// which is exactly the outcome sweep_deadline() exists to
				// prevent. A blog that threw has already had part of its
				// cleanup applied, so it is progress for this purpose.
				if ( $entered > 0 && null !== $deadline && microtime( true ) >= $deadline ) {
					$out_of_time = true;
					break 2;
				}

				++$entered;
				switch_to_blog( $site_id );
				try {
					self::deactivate_for_blog();
					// Counts fully-cleaned blogs only — it is what the
					// out-of-time log line below reports to the operator.
					++$swept;
				} catch ( \Throwable $e ) {
					// One blog's failure must not abort the network loop —
					// the remaining subsites still need cleanup. Log so an
					// operator can spot stuck rewrite rows.
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
			// Logged unconditionally, not behind WP_DEBUG_LOG: an incomplete
			// sweep is an operator-actionable condition, and by the time it
			// happens the plugin is already off, so there is no second chance
			// to surface it in the UI. One COUNT query, only on this path.
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
	 * Wall-clock deadline for the network sweep, or null when PHP imposes no
	 * execution limit (WP-CLI, most cron runners) and the loop may run to
	 * completion.
	 *
	 * Why bound it at all: `deactivate_{$plugin}` fires BEFORE core writes the
	 * shortened `active_plugins` value, so a max_execution_time abort halfway
	 * through the loop is the worst outcome available — the plugin stays
	 * ACTIVE while every blog swept so far has already had its rewrite rules
	 * deleted, its schedules cleared and the Translator role stripped.
	 * Stopping early instead leaves the unswept blogs entirely untouched
	 * (their state is still internally consistent) and lets core finish the
	 * deactivation it started.
	 *
	 * The loop applies this deadline only once it has entered at least one
	 * blog (see the `$entered > 0` guard in {@see deactivate_for_network()}),
	 * so a request that arrives with the budget already spent still cleans a
	 * site instead of turning the whole sweep into a no-op.
	 *
	 * Measured from the request start rather than from the loop, because
	 * bootstrap has already spent part of the limit by the time we get here.
	 * `$timestart` is set by timer_start() in wp-settings.php; it is read via
	 * $GLOBALS so a missing/odd value falls back instead of fatalling.
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

		// The remaining share covers core's own post-deactivation work (the
		// active_plugins write and the redirect) plus the one blog that is
		// mid-cleanup when the deadline lands.
		return $started + max( 1.0, $limit * self::NETWORK_SWEEP_TIME_SHARE );
	}

	/**
	 * Per-blog deactivation cleanup. Caller is responsible for being
	 * switched into the right blog (single-site = current blog; network
	 * deactivate = looped via switch_to_blog).
	 *
	 * Runs:
	 *   - unschedule_background_events() (this blog's WP-Cron + Action Scheduler)
	 *   - flush_rewrite_rules (per-blog wp_<id>_options 'rewrite_rules' row)
	 *   - delete_option('perflocale_flush_rules') flag
	 *   - TranslatorRole::remove_roles() (operates on the current blog's roles)
	 *   - CacheManager::flush_all() (per-blog L1/L2/L3 caches + transients)
	 *
	 * @return void
	 */
	private static function deactivate_for_blog(): void {
		self::unschedule_background_events();

		// Remove our own rewrite contribution BEFORE flushing. On a normal
		// deactivation request RewriteManager's `rewrite_rules_array` filter is
		// still hooked, so flush_rewrite_rules() would re-run it and re-add
		// every language-prefixed rule — leaving the regenerated option
		// byte-for-byte the same and making this cleanup a silent no-op.
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'rewrite_manager' ) ) {
			remove_filter( 'rewrite_rules_array', [ $plugin->get( 'rewrite_manager' ), 'filter_rewrite_rules' ] );
		}

		// Soft flush, NOT flush_rewrite_rules(). Under switch_to_blog() core
		// re-points $wpdb and the object cache but does NOT re-initialise
		// $wp_rewrite — it still holds the ORIGINAL blog's permalink_structure
		// and only the post types/taxonomies registered in THIS request. A hard
		// flush would therefore write the network-admin blog's rules into this
		// subsite's `rewrite_rules` option, dropping the subsite's own CPT and
		// taxonomy rules; and because WP_Rewrite::wp_rewrite_rules()
		// short-circuits on a non-empty option, the wrong rules would stick
		// until someone re-saved permalinks on every site. Deleting the option
		// instead lets each blog regenerate its own correct rules on its next
		// front-end request, which is exactly what we want here — our filter
		// has already been removed above, so the regenerated set is clean.
		delete_option( 'rewrite_rules' );

		// Remove the flush-rules flag on this blog. Activator sets this per-
		// blog so we have to clear it per-blog.
		delete_option( 'perflocale_flush_rules' );

		// Strip the custom translator role + capabilities from administrator
		// and editor on THIS blog. Without this, perflocale_* caps survive
		// deactivation and continue to grant access to plugin admin pages on
		// the next activation. The `perflocale/roles/cap_roles` filter inside
		// remove_roles() lets a site opt a role out — useful while developing
		// locally with frequent re-activations.
		\PerfLocale\Admin\TranslatorRole::remove_roles();

		// Flush ALL plugin caches on this blog - static (L1), object cache
		// (L2), and transients (L3). Without this, stale translation link
		// data persists across deactivation/reactivation and causes wrong-
		// language content to be served on subsequent page loads.
		try {
			$plugin = Plugin::get_instance();

			if ( $plugin->has( 'cache' ) ) {
				$plugin->get( 'cache' )->flush_all();
			}
		} catch ( \Throwable $e ) {
			// Plugin container may not be fully initialized during deactivation.
			// Fallback: clear all transients with our prefix manually for THIS blog.
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
	 * Clear THIS blog's pending background work in both back-ends (WP-Cron
	 * AND Action Scheduler). Pending events that fire after deactivation
	 * would otherwise log "no callback" errors until they naturally expire.
	 *
	 * Both stores are per-blog — WP-Cron lives in the blog's `cron` option and
	 * Action Scheduler's tables are prefixed with `$wpdb->prefix` (see the
	 * class docblock) — so this belongs inside the network loop, once per
	 * blog, not once per deactivation.
	 *
	 * @return void
	 */
	private static function unschedule_background_events(): void {
		global $wpdb;

		// Silence wpdb's error reporting for the duration of the sweep, then
		// put it back exactly as it was.
		//
		// Action Scheduler's tables are per-blog (see the class docblock), and
		// AS creates them lazily on first use — so a subsite that was never
		// visited has no wp_<id>_actionscheduler_* tables at all, and every AS
		// call below fails its query. wpdb::print_error() writes to error_log()
		// unconditionally: it is NOT gated on WP_DEBUG. Left alone, one such
		// subsite sprays ~25 "table doesn't exist" lines into the host's log
		// per deactivation, and where display errors are on it also prints an
		// HTML error block into the `deactivate_{$plugin}` output — ahead of
		// plugins.php's redirect, which then breaks.
		//
		// Nothing here reads a result set: an absent table means there was no
		// pending work to cancel, which is the outcome we wanted anyway. The
		// bracket covers the WP-Cron half too, since both engines are cleared
		// through one BackgroundEvents call; that half writes the blog's `cron`
		// option, and a failure there is not actionable on a teardown path.
		$suppress_errors = $wpdb->suppress_errors( true );

		try {
			// BackgroundEvents::unschedule_all() clears every occurrence of the
			// hook (any args, any timestamp) in BOTH engines:
			// - WP-Cron: wp_unschedule_hook() (matches across all args,
			// unlike wp_clear_scheduled_hook which needs args to match).
			// - Action Scheduler: as_unschedule_all_actions($hook, [], group).
			// Doing both matters because the `background_engine` setting can be
			// flipped mid-life, leaving the same hook pending under the engine
			// that is no longer selected.
			foreach ( self::cron_hooks() as $hook ) {
				\PerfLocale\Background\BackgroundEvents::unschedule_all( $hook );
			}

			// Then sweep the whole group, which also catches actions whose hook is
			// not in cron_hooks() — Tier-2 runners enqueue per-job hooks and older
			// builds scheduled hooks whose owning classes no longer exist here.
			// Guarded with function_exists() because Action Scheduler is bundled by
			// other plugins (WooCommerce et al) and may simply be absent; the group
			// constant is reused rather than re-typed so the two can't drift.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( '', [], \PerfLocale\Background\ActionSchedulerRunner::GROUP );
			}
		} finally {
			$wpdb->suppress_errors( $suppress_errors );
		}
	}

	/**
	 * Every recurring + one-shot cron hook the plugin schedules.
	 *
	 * Consumed by the per-blog both-engines clear in
	 * {@see unschedule_background_events()}.
	 *
	 * @return list<string>
	 */
	private static function cron_hooks(): array {
		return [
			\PerfLocale\WooCommerce\ExchangeRateSync::CRON_HOOK,
			\PerfLocale\Concurrency\Lock::CLEANUP_HOOK,
			\PerfLocale\Bootstrap::AUTO_TRANSLATE_CRON,
			// Webhook delivery + retry one-shots (scheduled with per-delivery args).
			\PerfLocale\Api\WebhookController::DELIVERY_HOOK,
			\PerfLocale\Api\WebhookController::RETRY_HOOK,
			// Coalesced-queue drainer (WP-Cron engine). Omitting it left a
			// perflocale_drain_webhooks event orphaned in cron after deactivation.
			\PerfLocale\Api\WebhookController::DRAIN_HOOK,
			'perflocale_cleanup_temp_import',
			// Background-jobs GC + watchdog + MT quality scoring (per-blog recurring).
			'perflocale_jobs_gc',
			'perflocale_jobs_watchdog',
			// MT-usage GC (per-blog recurring; scheduled by
			// ensure_recurring_schedules()). Omitting them here orphaned both in
			// each blog's WP-Cron after deactivation.
			'perflocale_mt_usage_gc',
			// Recurring events from features removed in later versions. Swept by
			// RAW NAME (their owning classes are gone) so a site upgrading from
			// an older build doesn't keep firing an event nothing listens to.
			'perflocale_tm_gc',
			'perflocale_mt_quality_score',
			'perflocale_glossary_import',
			// Tier-2 background workers: each a `perflocale_job_run_<type>` hook.
			'perflocale_job_run_data_import',
			'perflocale_job_run_data_export',
			'perflocale_job_run_wpml_migration',
			'perflocale_job_run_polylang_migration',
			'perflocale_job_run_translatepress_migration',
			'perflocale_job_run_string_scan',
			'perflocale_job_run_bulk_translate',
			'perflocale_job_run_bulk_string_translate',
			'perflocale_job_run_site_translate',
			// Resume-after-reactivation handler.
			\PerfLocale\Background\Resumer::HOOK,
		];
	}
}
