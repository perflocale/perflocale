<?php
/**
 * Per-site PerfLocale data purge.
 *
 * Single source of truth for "remove every PerfLocale-owned row from this
 * site". Called from two places:
 *
 *   1. uninstall.php - once per blog when the plugin is deleted.
 *   2. perflocale.php's wp_uninitialize_site handler - when a network admin
 *      permanently deletes a subsite from the network.
 *
 * Both paths delete what the data registry (DataRegistry) lists for
 * uninstall, so they and the registry cannot drift apart silently.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-site cleanup orchestrator. Static-only - must be called inside the
 * blog context of the site being purged (after switch_to_blog when on
 * multisite).
 */
final class SiteCleanup {

	/**
	 * Blog IDs already purged during THIS request, keyed by blog ID.
	 *
	 * Request-local by design: the only consumer is the
	 * remove_user_from_blog handler that core fires later in the same
	 * wp_delete_site() call stack. See {@see purge_current_site()}.
	 *
	 * @var array<int, true>
	 */
	private static array $purged_blogs = [];

	/**
	 * Whether this request already purged the given blog's plugin data.
	 *
	 * @param int $blog_id Blog to check.
	 * @return bool
	 */
	public static function was_site_purged( int $blog_id ): bool {
		return isset( self::$purged_blogs[ $blog_id ] );
	}

	/**
	 * Action Scheduler group used by every PerfLocale-enqueued action.
	 *
	 * Duplicated as a local constant so `uninstall.php` can sweep AS rows
	 * without loading `ActionSchedulerRunner.php`. Keep in sync with
	 * `ActionSchedulerRunner::GROUP`.
	 *
	 * @var string
	 */
	private const AS_GROUP = 'perflocale';

	/**
	 * The settings row, which is also where each blog's
	 * `delete_data_on_uninstall` decision lives.
	 *
	 * Named as a constant because the purge has to treat it specially: the
	 * data registry gives it uninstall `last`, so the option sweep leaves it
	 * and purge_current_site() deletes it at the very end.
	 *
	 * @var string
	 */
	public const MODE_OPTION = 'perflocale_settings';

	/**
	 * Network option holding the uninstall sweep's resume marker.
	 *
	 * Shape: `[ 'last_site_id' => int, 'updated' => int, 'any_preserve' => bool, 'any_delete' => bool, 'unflushed' => bool ]`,
	 * where `last_site_id` means "every blog whose id is <= this has been
	 * purged", the two `any_` flags record whether one of those blogs chose
	 * to keep, or to delete, its data, and `unflushed` (optional) marks a
	 * pass that deleted data and may not have reached its shared cache flush. It is stored on the main network whichever network's
	 * admin deleted the plugin.
	 *
	 * @var string
	 */
	public const RESUME_OPTION = 'perflocale_uninstall_resume';

	/**
	 * How long a resume marker may be trusted.
	 *
	 * ⚠️ A marker that is honoured forever is worse than no marker at all.
	 * An interrupted uninstall that the operator never finishes leaves one
	 * behind in `wp_sitemeta`, where it would silently skip every blog at or
	 * below `last_site_id` on the NEXT uninstall - months later, after a
	 * re-install, on data that has nothing to do with the pass that wrote it.
	 * Past this age the marker's cursor is not trusted and the row is
	 * dropped, so the sweep starts from the beginning; its `any_preserve`,
	 * `any_delete` and `unflushed` flags are still carried into that pass,
	 * because they record choices, not progress, and cannot skip a blog.
	 *
	 * Every pass rewrites the marker, so a genuine multi-pass recovery keeps
	 * it fresh however long it takes. The failure mode of expiring too early
	 * is re-walking blogs that are already clean, which costs a little work
	 * and loses nothing; the failure mode of expiring too late is skipping
	 * blogs that still hold data. The asymmetry is the whole reason this
	 * constant exists.
	 *
	 * Spelled as a number rather than `DAY_IN_SECONDS`: this class is the one
	 * the uninstall path loads by hand, and it must not depend on a WordPress
	 * constant being defined wherever it is included - a bare-PHP harness
	 * without WP loaded fataled on exactly that.
	 *
	 * @var int
	 */
	private const RESUME_MAX_AGE = 86400;

	/**
	 * Share of PHP's `max_execution_time` the network purge may spend.
	 *
	 * Same number as Deactivator::NETWORK_SWEEP_TIME_SHARE, and deliberately
	 * so: an operator tuning one sweep is tuning the same kind of work in the
	 * other. The remaining share covers the blog that is mid-purge when the
	 * deadline lands, the network-option sweep after the loop, and core's own
	 * deletion of the plugin directory once uninstall.php returns.
	 *
	 * @var float
	 */
	private const NETWORK_PURGE_TIME_SHARE = 0.7;

	/**
	 * Meta rows one page of delete_meta_like() reads and deletes when a
	 * persistent object cache is in use.
	 */
	private const META_PAGE = 5000;

	/**
	 * Purge every blog of the installation, each one per its own
	 * `delete_data_on_uninstall` setting.
	 *
	 * `uninstall.php` is the only caller. It passes a deadline, because a
	 * plugin deletion is an ordinary admin request; under WP-CLI
	 * (`wp plugin uninstall perflocale`) there is no execution limit, the
	 * deadline is null, and one pass sweeps the whole network.
	 *
	 * ⚠️ This work cannot be deferred to a background job. `uninstall.php`
	 * runs while the plugin is being deleted and WordPress removes the files
	 * immediately afterwards, so a queued job would fire against a callback
	 * that no longer exists. Whatever does not finish in this request never
	 * happens - which is why an interrupted sweep leaves a marker instead.
	 *
	 * The chunk size and the deadline mirror
	 * {@see \PerfLocale\Deactivator::deactivate_for_network()}, with two
	 * deliberate differences. Sites are paged by a cursor on blog_id rather
	 * than an offset (see the loop), and `network_id` is NOT scoped. Deactivation
	 * acts on the one network whose admin pressed the button; uninstall
	 * removes the plugin's FILES from the whole installation, so every blog
	 * of every network loses the code that owns these rows. Narrowing this
	 * query would strand sibling networks' tables, options and schedules
	 * forever with nothing left on disk to clean them up.
	 *
	 * The resume marker holds the highest blog id for which every blog at or
	 * below it has been purged. It stops advancing at the first blog that
	 * throws, and a pass with any failure counts as incomplete, so the marker
	 * is kept and a resumed sweep re-runs that blog rather than stepping over
	 * a half-cleaned one. Every purge step is idempotent, so re-running costs
	 * work, never data.
	 *
	 * @param float|null $deadline Wall-clock time (microtime(true)) after
	 *     which the loop stops between blogs, or null for no limit.
	 * @return array{complete: bool, out_of_time: bool, list_error: bool, meta_error: bool, entered: int, purged: int, failed: int, skipped: int, remaining: int, last_site_id: int, total: int}
	 */
	public static function purge_network( ?float $deadline = null ): array {
		/** This filter is documented in src/Deactivator.php. */
		$filtered_chunk = apply_filters( 'perflocale/activation/chunk_size', 100 );
		// A non-numeric return falls back to the default rather than casting
		// to 0 and clamping to a chunk of 1, which would turn one site query
		// into one site query per blog.
		$chunk = is_numeric( $filtered_chunk ) ? max( 1, (int) $filtered_chunk ) : 100;

		// Everything about the marker is checked before it is trusted: it is
		// whatever happens to be in wp_sitemeta. A hand-edited row, a
		// half-written one, or one left by an uninstall nobody ever finished
		// must all degrade to "start from the beginning" - the safe
		// direction, because it re-walks clean blogs instead of skipping
		// dirty ones.
		//
		// A marker without both flags is not trusted either: it cannot say
		// which modes the blogs below it used, and its cursor may not come
		// from this keyset walk, so it could point past a blog nobody
		// entered. Starting again costs a walk over purged blogs; trusting it
		// could skip one for good.
		$marker       = get_network_option( self::marker_network(), self::RESUME_OPTION, [] );
		$done_up_to   = 0;
		$any_preserve = false;
		$any_delete   = false;
		$unflushed    = false;

		if ( is_array( $marker ) ) {
			$well_formed = isset( $marker['last_site_id'], $marker['any_preserve'], $marker['any_delete'] )
				&& is_numeric( $marker['last_site_id'] );

			// What the blogs an EARLIER pass handled chose, kept whatever the
			// marker's age. A purged delete-mode blog has lost its settings
			// row, so a fresh walk reads it as 'none': these flags are the only
			// record that it chose deletion. Unlike the cursor they cannot make
			// the walk skip a blog, and activation deletes them with the
			// marker, so they never outlive the uninstall that set them.
			if ( $well_formed ) {
				$any_preserve = (bool) $marker['any_preserve'];
				$any_delete   = (bool) $marker['any_delete'];
				$unflushed    = ! empty( $marker['unflushed'] );
			}

			$age     = isset( $marker['updated'] ) && is_numeric( $marker['updated'] ) ? time() - (int) $marker['updated'] : -1;
			$trusted = $well_formed && $age >= 0 && $age <= self::RESUME_MAX_AGE;

			if ( $trusted ) {
				$done_up_to = max( 0, (int) $marker['last_site_id'] );
			} else {
				// Stale, stamped in the future by a clock change, or not
				// trustworthy as above. Drop it rather than carry it: leaving
				// it would let the next uninstall inherit a skip nobody asked
				// for.
				delete_network_option( self::marker_network(), self::RESUME_OPTION );
			}
		}

		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// Blogs an earlier pass already finished. Counted once, for the log
		// line; the walk below starts after them and never visits them.
		$skipped = 0;

		if ( $done_up_to > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one COUNT on the uninstall path; the blogs table has no API for an id range.
			$skipped = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE blog_id <= %d', $wpdb->blogs, $done_up_to ) );
		}

		// KEYSET paging on blog_id, not offset paging. The site list can
		// change during the sweep - a network admin deleting a subsite in
		// another tab - and an offset over a list that shrank skips a blog:
		// with ids [1,2,3] and a chunk of 2, deleting site 1 after the first
		// page makes the offset-2 page empty, so site 3 is never purged and
		// the sweep reports complete. A cursor on the id cannot skip.
		//
		// A direct query because WP_Site_Query has no "id greater than"
		// argument, and faking one with a `sites_clauses` filter does not
		// work: the query cache is keyed on query vars, so every page would
		// be served page one - an endless loop under WP-CLI.
		$cursor         = $done_up_to;
		$entered        = 0;
		$purged         = 0;
		$failed         = 0;
		$high_water     = $done_up_to;
		$had_failure    = false;
		$list_error     = false;
		$out_of_time    = false;
		$got_full_chunk = false;

		// Whether THIS pass entered a blog that chose deletion. Only such a
		// pass has anything to flush from the shared cache groups.
		$deleted_this_pass = false;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset paging, see above.
			$page = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT blog_id FROM %i WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d',
					$wpdb->blogs,
					$cursor,
					$chunk
				)
			);

			// A failed page query returns an empty list, which reads exactly
			// like the end of the network: the pass would report complete and
			// make the network-wide decisions below on a partial walk.
			// wpdb clears last_error at the start of every query, so a
			// non-empty one belongs to this SELECT.
			if ( '' !== $wpdb->last_error ) {
				$list_error  = true;
				$had_failure = true;

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path; there is no UI left to surface it in.
				error_log( '[PerfLocale] uninstall could not read the list of sites after site ' . $cursor . ': ' . $wpdb->last_error );
				break;
			}

			$site_ids = array_map( 'intval', (array) $page );

			foreach ( $site_ids as $site_id ) {
				// Advance first, whatever happens to this blog: the cursor is
				// only where the NEXT page starts. What counts as done is
				// $high_water, which a failure freezes.
				$cursor = $site_id;

				// `$entered > 0` guarantees forward progress: a request that
				// arrives having already spent its budget must still purge one
				// blog rather than turning the whole sweep into a no-op. The
				// test sits BEFORE switch_to_blog(), so no blog is ever left
				// half-purged by the deadline itself.
				if ( $entered > 0 && null !== $deadline && microtime( true ) >= $deadline ) {
					$out_of_time = true;
					break 2;
				}

				++$entered;

				// switch_to_blog() is INSIDE the try, unlike the deactivation
				// sweep's otherwise identical loop. Core pushes onto the
				// switched stack before it fires `switch_blog`, so a listener
				// that throws would leave the wrong blog current for the rest
				// of the request - and, with the switch outside the try, abort
				// the sweep for every remaining blog on the installation. The
				// finally below restores either way.
				try {
					switch_to_blog( $site_id );

					$mode = self::blog_uninstall_mode();

					if ( 'preserve' === $mode ) {
						$any_preserve = true;
					} elseif ( 'delete' === $mode ) {
						// Recorded BEFORE the first deletion of the pass. Once
						// this blog is purged its settings row is gone, and a
						// pass killed outright - a process killer, an OOM, a
						// Ctrl-C under WP-CLI - never reaches the end-of-pass
						// write: the next pass would find nothing saying that
						// a blog chose deletion, or that the shared cache
						// still needs its flush. The cursor stays at
						// $high_water, so this can only cause re-walks, never
						// skips.
						if ( ! $deleted_this_pass ) {
							update_network_option(
								self::marker_network(),
								self::RESUME_OPTION,
								[
									'last_site_id' => $high_water,
									'updated'      => time(),
									'any_preserve' => $any_preserve,
									'any_delete'   => true,
									'unflushed'    => true,
								]
							);
						}

						$any_delete        = true;
						$deleted_this_pass = true;
					}

					// `true`: the user-meta sweep and the shared cache flush
					// are left to the end of the pass, below.
					if ( ! self::purge_current_site( 'delete' === $mode, false, true ) ) {
						// A statement failed without throwing (logged by
						// purge_current_site). Same consequence as a throw:
						// the blog is not done, so the pass is not complete.
						++$failed;
						$had_failure = true;
						continue;
					}

					++$purged;

					if ( ! $had_failure ) {
						$high_water = $site_id;
					}
				} catch ( \Throwable $e ) {
					// One blog's failure must not abort the sweep - every
					// other blog still loses the code that owns its rows.
					++$failed;
					$had_failure = true;

					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path; there is no UI left to surface it in.
					error_log( '[PerfLocale] uninstall purge failed on site ' . $site_id . ': ' . $e->getMessage() );
				} finally {
					restore_current_blog();
				}
			}

			$got_full_chunk = ( count( $site_ids ) === $chunk );
		} while ( $got_full_chunk );

		// A pass is complete only if it reached the end AND nothing failed.
		// Counting a blog that threw as finished would delete the resume
		// marker and let uninstall.php remove the network options as though
		// every site were clean - and WordPress then deletes the plugin
		// files, so nothing on disk would be left to finish that blog. Kept
		// incomplete, the marker survives at $high_water (frozen just below
		// the first failure), and deleting the plugin again resumes there.
		$complete = ! $out_of_time && ! $had_failure;
		$total    = 0;

		// The shared cache groups, once per pass rather than once per blog:
		// on Redis a group flush scans the whole keyspace, whichever blog
		// asks. Only a pass that deleted something flushes: 'transient' and
		// 'site-transient' hold every plugin's transients, so a sweep in which
		// every blog kept its data - the default - must not empty them. Not
		// deferred to the completing pass either, so an interrupted sweep
		// leaves no entries a re-activation could read back. A pass that was
		// killed before its flush left `unflushed` in the marker; this pass
		// flushes for it.
		if ( $deleted_this_pass || $unflushed ) {
			self::flush_shared_cache_groups();
		}

		// User meta is NETWORK-GLOBAL, so it is decided once, now that every
		// blog is done: it goes only if some blog chose deletion and none
		// chose to keep its data. A blog that kept its data keeps its users'
		// preferences too; a blog PerfLocale never ran on is neutral, so a
		// network with no delete-mode blog keeps them.
		//
		// BEFORE the marker goes: every blog's settings row is gone by now,
		// so the marker's flags are the only record that this network chose
		// deletion. A DELETE that fails keeps the pass incomplete; the marker
		// then sits at the last blog, and the next pass skips every blog and
		// retries only this step.
		$meta_error = false;

		if ( $complete && $any_delete && ! $any_preserve && ! self::sweep_plugin_user_meta() ) {
			$meta_error = true;
			$complete   = false;

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path; there is no UI left to surface it in.
			error_log( '[PerfLocale] uninstall could not delete PerfLocale user preferences: ' . $wpdb->last_error );
		}

		if ( $complete ) {
			// Nothing left to resume.
			delete_network_option( self::marker_network(), self::RESUME_OPTION );
		} else {
			$total = (int) get_sites( [ 'count' => true ] );

			update_network_option(
				self::marker_network(),
				self::RESUME_OPTION,
				[
					'last_site_id' => $high_water,
					'updated'      => time(),
					'any_preserve' => $any_preserve,
					'any_delete'   => $any_delete,
				]
			);
		}

		return [
			'complete'     => $complete,
			// Why a pass is incomplete - each needs different advice.
			'out_of_time'  => $out_of_time,
			'list_error'   => $list_error,
			'meta_error'   => $meta_error,
			'entered'      => $entered,
			'purged'       => $purged,
			'failed'       => $failed,
			'skipped'      => $skipped,
			// Blogs not yet purged: those this pass did not reach, plus those
			// that failed. `purged` counts only this pass and `skipped` only
			// what earlier passes did, so neither on its own tells an
			// operator how much is left.
			'remaining'    => max( 0, $total - $skipped - $purged ),
			'last_site_id' => $high_water,
			'total'        => $total,
		];
	}

	/**
	 * The network the resume marker lives on: the MAIN network.
	 *
	 * The sweep deliberately visits every blog of every network, and any
	 * network's super admin can delete the plugin. Kept on whichever network
	 * happened to call it, the marker would be invisible to an uninstall
	 * started from another network, which would then begin again from the
	 * first blog.
	 *
	 * @return int
	 */
	private static function marker_network(): int {
		return function_exists( 'get_main_network_id' ) ? (int) get_main_network_id() : 1;
	}

	/**
	 * Forget any unfinished uninstall. Called when the plugin is activated:
	 * an operator who is installing is not recovering, and a leftover marker
	 * would otherwise make a LATER uninstall skip every blog below it.
	 *
	 * @return void
	 */
	public static function forget_uninstall_progress(): void {
		delete_network_option( self::marker_network(), self::RESUME_OPTION );
	}

	/**
	 * Remove PerfLocale's network-scoped options from EVERY network, once,
	 * after a completed sweep.
	 *
	 * The sweep covers every network of the installation, but
	 * delete_site_option() acts on the calling network only, so on a
	 * multi-network install every other network would keep its copy.
	 *
	 * @return void
	 */
	public static function purge_network_scope(): void {
		$networks = [ self::marker_network() ];

		if ( function_exists( 'get_networks' ) ) {
			$networks = array_map(
				'intval',
				(array) get_networks(
					[
						'fields' => 'ids',
						'number' => 0,
					]
				)
			);
		}

		foreach ( $networks as $network_id ) {
			foreach ( DataRegistry::network_options() as $option ) {
				delete_network_option( $network_id, $option );
			}
		}
	}

	/**
	 * Wall-clock deadline for the network purge, or null when PHP imposes no
	 * execution limit (WP-CLI, most cron runners) and the loop may run to
	 * completion.
	 *
	 * Why bound it at all: a kill lands in the middle of one blog's purge -
	 * tables dropped, options half-swept - and WordPress then deletes the
	 * plugin directory anyway, leaving nothing on disk that knows how to
	 * finish. Stopping between blogs instead leaves every unswept blog
	 * internally consistent and records where to pick up.
	 *
	 * ⚠️ `max_execution_time` is a CPU-time limit on most builds, and a purge
	 * is mostly waiting on the database, so PHP's own timer may never fire.
	 * It is used here as the best available PROXY for the wall-clock budget
	 * that actually kills plugin-deletion requests - the web server's or
	 * proxy's own timeout, which PHP cannot see. Being early costs one extra
	 * pass; being late costs a half-purged blog.
	 *
	 * Measured from the request start rather than from the loop, because
	 * bootstrap and core's own pre-delete work have already spent part of the
	 * limit by the time uninstall.php runs. `$timestart` is set by
	 * timer_start() in wp-settings.php; it is read via $GLOBALS so a missing
	 * or odd value falls back instead of fatalling.
	 *
	 * @return float|null
	 */
	public static function uninstall_deadline(): ?float {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return null;
		}

		$started = isset( $GLOBALS['timestart'] ) && is_numeric( $GLOBALS['timestart'] )
			? (float) $GLOBALS['timestart']
			: microtime( true );

		return $started + max( 1.0, $limit * self::NETWORK_PURGE_TIME_SHARE );
	}

	/**
	 * What the current blog asked for on uninstall: 'delete', 'preserve', or
	 * 'none' when get_option() finds no settings row.
	 *
	 * 'none' is a blog PerfLocale was never activated on. It matters only to
	 * purge_network()'s one network-global decision - sweep the shared
	 * user-meta table or not - where treating such a blog as "preserve" would
	 * disable the sweep on every partially activated network, and treating
	 * it as "delete" would be inventing a choice nobody made.
	 *
	 * The default is an object only this call holds, so a row whose value
	 * unserializes to null is told apart from no row. A read that FAILED
	 * returns the default too, and so also reads as 'none': get_option()
	 * cannot tell the two apart, and a blog whose options table is gone has
	 * no choice to respect.
	 *
	 * @return string 'delete' | 'preserve' | 'none'
	 */
	private static function blog_uninstall_mode(): string {
		$absent   = new \stdClass();
		$settings = get_option( self::MODE_OPTION, $absent );

		if ( $absent === $settings ) {
			return 'none';
		}

		// Anything present but unreadable counts as keep - never as delete.
		return ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) ? 'delete' : 'preserve';
	}

	/**
	 * Read the current blog's "delete data on uninstall" decision.
	 *
	 * Single-site uninstall reads it through perflocale_should_delete_data().
	 * The network sweep classifies each blog, switched in, with
	 * blog_uninstall_mode(), which applies the same test and also tells a
	 * blog with no settings row apart - reading one decision from whatever
	 * blog is current would purge subsites whose admin chose to keep data.
	 *
	 * @return bool True if this blog opted in to full data deletion.
	 */
	public static function blog_wants_data_deleted(): bool {
		$settings = get_option( 'perflocale_settings', [] );

		return is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
	}

	/**
	 * Run the chosen cleanup path for the current blog. Caller is
	 * responsible for switch_to_blog / restore_current_blog on multisite.
	 *
	 * @param bool $delete_data When true, runs the full data-deleting
	 *     purge. When false, only role/cap removal (preserves all data so
	 *     a re-install picks up where the user left off).
	 * @param bool $single_site_teardown True when this runs for a SINGLE
	 *     subsite being permanently deleted (wp_uninitialize_site), as opposed
	 *     to a full plugin uninstall across the whole network. On the
	 *     single-subsite path, network-GLOBAL stores (wp_usermeta) must NOT be
	 *     swept, or one deleted subsite would wipe every user's PerfLocale UI
	 *     preferences on the SURVIVING sites where the plugin is still active.
	 * @param bool $network_sweep True when called from purge_network(). The
	 *     NETWORK-GLOBAL steps are then left to purge_network(): it flushes
	 *     the shared object-cache groups once per pass, and decides the
	 *     wp_usermeta sweep once, after a completed sweep.
	 * @return bool False if a table drop, a post/term/order meta DELETE, the
	 *     Action Scheduler history DELETE or (single site) the user-meta DELETE
	 *     failed; the settings row is then kept, so the next pass finishes the
	 *     job in the same mode. Always true in preserve mode.
	 */
	public static function purge_current_site( bool $delete_data, bool $single_site_teardown = false, bool $network_sweep = false ): bool {
		// Remember which blogs this request has already purged. Core's
		// wp_uninitialize_site() runs AFTER this (priority 10 vs our 5) and
		// calls remove_user_from_blog for every member of the dying blog -
		// which would send TranslatorRole's handler into
		// JobState::anonymize_for_user against tables we just dropped,
		// logging a spurious "Table doesn't exist" per deleted subsite.
		// TranslatorRole consults was_site_purged() and skips those blogs.
		self::$purged_blogs[ get_current_blog_id() ] = true;

		$clean = true;

		if ( $delete_data ) {
			$clean = self::full_purge( $single_site_teardown, $network_sweep );
		} else {
			self::preserve_purge();
		}

		// Scheduled-event cleanup ALWAYS runs, even when the operator preserves
		// data on uninstall: the plugin code is about to leave disk, so a
		// pending cron/AS event whose callback lived here would log
		// "no callback" / missing-class errors. Cleaning loses nothing (no user
		// data in schedules; AS actions restart on the next install).
		self::clear_all_scheduled_events();

		// The deleting path also removes this blog's Action Scheduler history
		// of the plugin's group: completed, failed and canceled actions and
		// their log lines, which Action Scheduler keeps (failed ones for good)
		// after the plugin is gone. After the cancel above, so the log lines
		// that cancel writes go too. Preserve mode keeps it, as it keeps the
		// rest of the data.
		if ( $delete_data && ! self::purge_action_scheduler_rows() ) {
			$clean = false;
		}

		// LAST, and only on the deleting path: the settings row is what
		// `blog_wants_data_deleted()` and `blog_uninstall_mode()` read. While
		// it is still there, an interrupted purge is a purge that will be
		// finished in the same mode when the operator resumes or deletes
		// again. Once it is gone the blog reads as one PerfLocale never ran
		// on, so it must not go until there is nothing left to get wrong.
		//
		// And only if everything above SUCCEEDED. A DROP or a meta DELETE
		// that failed - a lock-wait timeout, a host query-killer - left data
		// behind; with the settings row gone too, the retry would read this
		// blog as one that never ran PerfLocale and never remove it.
		// Not checked on purpose: delete_option() also returns false for a
		// row that is already gone, and it drops the cached copy even when
		// the DELETE fails, so on a persistent object cache no retry would
		// see this row. wpdb logs a failed DELETE itself.
		if ( $delete_data && $clean ) {
			delete_option( self::MODE_OPTION );
		}

		// Not logged here when the site itself is being deleted: core drops
		// the blog's own tables (options, postmeta, termmeta) right after
		// this, so the kept settings row and a failed meta DELETE do not
		// matter, and there is no retry to advise. A leftover perflocale_*
		// table, which core does not drop, is logged in full_purge().
		if ( ! $clean && ! $single_site_teardown ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
			error_log( '[PerfLocale] uninstall could not delete all of site ' . get_current_blog_id() . "'s PerfLocale tables, meta or scheduled-action history; its settings are kept so deleting the plugin again finishes the job." );
		}

		return $clean;
	}

	/**
	 * Drop tables, options, transients, meta, role+caps, and translation
	 * files for the current blog.
	 *
	 * @param bool $single_site_teardown See {@see purge_current_site()}: skip
	 *     network-global stores when only one subsite is being deleted.
	 * @param bool $network_sweep See {@see purge_current_site()}: leave the
	 *     user-meta sweep and the shared cache flush to the caller.
	 * @return bool False if a table drop, a post/term/order meta DELETE or
	 *     (single site) the user-meta DELETE failed.
	 */
	private static function full_purge( bool $single_site_teardown = false, bool $network_sweep = false ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// Whether the steps a retry cannot finish without this blog's settings
		// row all succeeded: the table drops, the post/term meta DELETEs and,
		// on a single site, the user-meta DELETE.
		// Only a step that FAILED clears it - `false === $wpdb->query()`,
		// strictly: 0 affected rows is the normal result on most blogs, and
		// treating it as a failure would keep every blog's settings row
		// forever and pin the resume marker at the first delete-mode blog.
		$clean = true;

		// 1. Addon-driven cleanup FIRST. Manifests describe each addon's
		// tables/options/meta independent of whether the addon plugin is
		// still on disk, so this works on partial-uninstall scenarios.
		self::purge_addons( $single_site_teardown );

		// 2. Drop core tables (canonical list lives in Schema::drop_tables).
		Schema::drop_tables();

		// drop_tables() reports nothing, and a DROP can fail - a lock-wait
		// timeout, a metadata lock held by a long-running query. Ask what is
		// left rather than assume.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one SHOW TABLES per purged blog, on the uninstall path only.
		$left = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'perflocale_' ) . '%' ) );

		// A SHOW that fails also returns null, which reads as "nothing
		// left". wpdb clears last_error at the start of every query, so a
		// non-empty one belongs to this SHOW.
		if ( null !== $left || '' !== $wpdb->last_error ) {
			$clean = false;

			if ( $single_site_teardown ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the site-deletion path.
				error_log( '[PerfLocale] site ' . get_current_blog_id() . ' is being deleted, but its ' . $wpdb->prefix . 'perflocale_* tables could not all be dropped (or checked); WordPress does not remove them, so drop any that remain by hand.' );
			}
		}

		// 3. Plugin options - static list + LIKE-patterns.
		//
		// `perflocale_settings` is held back to the END of the whole purge
		// (see purge_current_site()). It is the option this blog's
		// delete-or-preserve decision is read from, so deleting it here left
		// a purge that died part-way - a max_execution_time kill, a fatal on
		// one blog - coming back as PRESERVE mode on the retry, stranding
		// that blog's meta, translation files and role permanently. Nothing
		// else in the purge reads it, so holding it back costs nothing.
		// The registry's lists: exact names (perflocale_settings is not among
		// them, its uninstall value is `last`) and LIKE patterns, transient
		// value and timeout rows included.
		foreach ( DataRegistry::uninstall_options() as $opt ) {
			if ( self::MODE_OPTION === $opt ) {
				continue;
			}

			delete_option( $opt );
		}

		foreach ( DataRegistry::uninstall_option_likes() as $pattern ) {
			self::delete_options_like( $pattern );
		}

		// 4. User meta - namespace LIKE sweep (the registry's user-meta
		// prefixes). We own `perflocale_*` exclusively, so a prefix DELETE is
		// safe and sweeps any key the registry does not list by name; its
		// fixed list (DataRegistry::user_meta_keys()) is what the GDPR erase
		// flow uses (no wildcard there).
		//
		// SKIP on a single-subsite teardown: wp_usermeta is NETWORK-GLOBAL
		// (switch_to_blog does NOT re-scope it, unlike post/term meta), and
		// PerfLocale's user-meta keys (screen options, hidden-language column
		// prefs) are stored unprefixed/network-wide. Sweeping here when just
		// ONE subsite is deleted would wipe every user's UI preferences across
		// all SURVIVING sites where the plugin is still active. These keys have
		// no correct per-blog deletion, so they are only swept on a full,
		// network-wide plugin uninstall.
		//
		// ALSO SKIP on the network uninstall sweep. The table is shared by
		// every blog, so a per-blog sweep would let the FIRST blog that chose
		// "delete my data" wipe every user's preferences installation-wide -
		// including for users of blogs whose admin chose to keep them.
		// purge_network() decides it once, after the whole walk.
		if ( ! $single_site_teardown && ! $network_sweep && ! self::sweep_plugin_user_meta() ) {
			$clean = false;
		}

		// 5. Term meta - language tags and pairings of menus: the registry's
		// term-meta prefixes.
		foreach ( DataRegistry::uninstall_meta_likes( 'term' ) as $prefix ) {
			if ( ! self::delete_meta_like( $wpdb->termmeta, 'meta_id', 'term_id', 'term_meta', $prefix ) ) {
				$clean = false;
			}
		}

		// 6. Post meta - the registry's post-meta prefixes: the WC-order
		// language tag on shop_order posts, the per-language attachment
		// texts and every other `_perflocale_` key. Each prefix runs through
		// esc_like() so the underscores stay literal rather than being
		// treated as single-char wildcards.
		foreach ( DataRegistry::uninstall_meta_likes( 'post' ) as $prefix ) {
			if ( ! self::delete_meta_like( $wpdb->postmeta, 'meta_id', 'post_id', 'post_meta', $prefix ) ) {
				$clean = false;
			}
		}

		// 6b. The same keys in WooCommerce's own order meta table, where an
		// HPOS store keeps the order language tag instead of postmeta.
		if ( ! self::purge_order_meta() ) {
			$clean = false;
		}

		// 7. Role + caps. `true`: this is the full-data path, so the
		// Translator role ASSIGNMENT goes too - see sweep_orphan_user_caps().
		self::strip_role_and_caps( false, true );

		// 8. Translation files in uploads/perflocale/translations.
		self::delete_translation_files();

		// 9. Drop the cached rewrite rules so language-prefix rules don't 404
		// after the plugin is gone. Deleting the option rather than calling
		// flush_rewrite_rules() is deliberate on both counts:
		//   - This runs inside switch_to_blog() (uninstall.php loops the
		//     network). Core does not re-initialise $wp_rewrite on a blog
		//     switch, so a hard flush would write the ORIGINAL blog's
		//     permalink structure and post types into this subsite's option
		//     and, because WP_Rewrite short-circuits on a non-empty option,
		//     they would stick - every subsite with a different structure
		//     404s until someone re-saves its permalinks.
		//   - Regenerating with the plugin still loaded walks home_url →
		//     UrlConverter → our repositories, whose tables step 2 just
		//     dropped, logging spurious "Table doesn't exist" errors.
		// Each blog regenerates its own correct rules on its next request.
		// Skipped on a single-subsite teardown: core drops the blog's whole
		// options table right after this hook, so the write is wasted.
		if ( ! $single_site_teardown ) {
			delete_option( 'rewrite_rules' );
		}

		// 10. Persistent object-cache (L2) flush. The wp_options transient
		// rows are cleared by step 3 above, but on a Redis/Memcached
		// backend every key written via wp_cache_set( …, 'perflocale_*' )
		// lives in the cache server and persists until its TTL - up to
		// ~12 h, which can show up as ghost reads on the next install.
		// Flush every plugin-owned group explicitly.
		//
		// The per-blog half always runs; the SHARED half (which, on Redis,
		// reaches every blog on the network) only on a single-site uninstall.
		// Deleting one subsite must not flush `transient` and `site-transient`:
		// on Redis that empties every plugin's cached data on every surviving
		// site, plus core's update checks. That blog's own keys are prefixed
		// with its id and become unreachable the moment core drops it. The
		// network sweep runs the shared flush once per pass, at its end.
		self::flush_cache_groups( ! $single_site_teardown && ! $network_sweep );

		// Scheduled-event cleanup is run by `purge_current_site()` after
		// this method returns, so a leaked event has no chance to fire
		// against now-dropped tables.

		return $clean;
	}

	/**
	 * Invalidate every plugin-owned persistent object-cache group for the
	 * current blog, and optionally flush the shared groups.
	 *
	 * Static so it runs from the uninstall path without instantiating
	 * CacheManager (which needs Settings + the plugin container, both of
	 * which are gone during uninstall). Reads the canonical group list
	 * from CacheManager::GROUPS so the two sweeps can't drift.
	 *
	 * @param bool $shared Also run flush_shared_cache_groups(). False when
	 *     one subsite is being deleted (the flush would reach every surviving
	 *     site) and on the network sweep (which flushes once per pass).
	 * @return void
	 */
	private static function flush_cache_groups( bool $shared = true ): void {
		if ( ! class_exists( \PerfLocale\Cache\CacheManager::class ) ) {
			return;
		}

		// Bump each group's generation - reliable on every object-cache
		// backend, including those whose wp_cache_flush_group() is missing
		// (WP < 6.1) or a silent no-op (e.g. Redis Object Cache + Predis).
		foreach ( \PerfLocale\Cache\CacheManager::GROUPS as $group ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( $group );
		}

		// On a persistent object cache, PerfLocale's transients (breaker state,
		// MT rate-limit counters, etc.) live in WP's 'transient'/'site-transient'
		// cache groups, NOT wp_options - so deleting the _transient_perflocale_%
		// option rows is a no-op for them. Flush those groups too so a full
		// uninstall leaves no ghost keys for a quick reinstall to read back.
		// Gated on an external cache (on the DB backend the rows already went)
		// and the WP 6.1+ helper. Single-site uninstall only: the network
		// sweep flushes once per pass, and deleting one subsite never flushes
		// the shared groups. Transients are disposable.
		if ( $shared ) {
			self::flush_shared_cache_groups();
		}

		// bump_group_generation() above RE-WRITES each `perflocale_cgen_<group>`
		// option (it persists the incremented generation), which the
		// option LIKE sweep in full_purge() already deleted a few steps
		// earlier - so without this, a full-data uninstall leaves ~10 orphaned
		// generation tokens in wp_options forever. This method is the LAST cache
		// step and runs only during uninstall, where nothing reads the cache
		// afterwards, so removing the tokens now is safe: the L2 keys they gated
		// are orphaned by their TTL regardless (a bump never deleted them). Run
		// last so it undoes the bump's option writes.
		self::delete_options_like( 'perflocale_cgen_%' );
	}

	/**
	 * Flush the object-cache groups that are SHARED across the network.
	 *
	 * On a persistent object cache, PerfLocale's transients (breaker state,
	 * MT rate-limit counters, etc.) live in WP's 'transient'/'site-transient'
	 * cache groups, NOT wp_options - so deleting the _transient_perflocale_%
	 * option rows is a no-op for them. Flushing those groups, and each plugin
	 * group, is what leaves no ghost keys for a quick reinstall to read back.
	 * The generation bump alone is not enough: the cgen tokens that gate the
	 * keys are deleted too, which returns a reinstall to generation 0 and the
	 * identical key space. Flushing removes them outright on backends that
	 * support it (phpredis / memcached); a no-op backend (Predis) still relies
	 * on TTL.
	 *
	 * ⚠️ These flushes are NETWORK-WIDE on Redis Object Cache: a group flush
	 * matches every blog prefix, and 'site-transient' is a global group. So
	 * this runs once per uninstall pass - never once per blog, never on a
	 * pass that deleted nothing, and never when one subsite is deleted, where it would wipe every surviving site's cached
	 * data (every plugin's transients, core's update checks) to clear keys
	 * that became unreachable when the blog did. Gated on an external cache
	 * (on the DB backend the rows are already gone) and the WP 6.1+ helper.
	 *
	 * A backend whose group flush is per-blog reaches only the blog
	 * uninstall.php runs on (normally the main site); the other blogs'
	 * entries expire by their TTL. Flushing per
	 * blog instead would repeat a whole-keyspace scan once per blog on Redis,
	 * the common backend - the cost this method exists to avoid.
	 *
	 * @return void
	 */
	private static function flush_shared_cache_groups(): void {
		if ( ! wp_using_ext_object_cache() || ! function_exists( 'wp_cache_flush_group' ) ) {
			return;
		}

		wp_cache_flush_group( 'transient' );
		wp_cache_flush_group( 'site-transient' );

		if ( class_exists( \PerfLocale\Cache\CacheManager::class ) ) {
			foreach ( \PerfLocale\Cache\CacheManager::GROUPS as $group ) {
				wp_cache_flush_group( $group );
			}
		}
	}

	/**
	 * Delete every `perflocale_*` user-meta row.
	 *
	 * The user-meta table is NETWORK-GLOBAL: switch_to_blog() does not
	 * re-scope it, and PerfLocale's keys there (screen options,
	 * hidden-language column prefs) are not blog-prefixed. There is no
	 * correct per-blog version of this, so it runs only on a single-site
	 * uninstall, or once at the end of a completed network sweep in which
	 * some blog chose deletion and none chose to keep its data.
	 *
	 * We own the `perflocale_` namespace exclusively, so a prefix DELETE (the
	 * registry's user-meta prefixes) is safe and catches keys the registry
	 * does not list by name; DataRegistry::user_meta_keys() stays the fixed
	 * list the GDPR erase flow uses.
	 *
	 * @return bool False only if a DELETE failed. 0 rows deleted is success.
	 */
	private static function sweep_plugin_user_meta(): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		foreach ( DataRegistry::uninstall_meta_likes( 'user' ) as $prefix ) {
			if ( ! self::delete_meta_like( $wpdb->usermeta, 'umeta_id', 'user_id', 'user_meta', $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete the rows of one core meta table whose key starts with a prefix,
	 * and drop WordPress's cached meta of every object that held one.
	 *
	 * The DELETE bypasses the meta API, so with a persistent object cache
	 * (Redis, Memcached) each object's `<type>_meta` cache entry would keep
	 * the deleted rows: get_post_meta() would go on returning them, and after
	 * a reinstall an import would skip a row of the file because
	 * metadata_exists() reads the cached copy. With such a cache the rows go
	 * in pages of META_PAGE, in row id order: each page's rows are read (row
	 * id and object id, after the last row id of the page before), deleted
	 * by row id, and their objects' cache entries deleted, so memory holds
	 * one page whatever the number of rows. Without a
	 * persistent object cache the entries end with the request, so one
	 * DELETE removes every row.
	 *
	 * @param string $table       Meta table.
	 * @param string $row_column  Its row id column (meta_id, umeta_id).
	 * @param string $id_column   Its object id column (post_id, term_id, user_id).
	 * @param string $cache_group The core cache group of that meta type.
	 * @param string $prefix      Literal key prefix.
	 * @return bool False if a read or a DELETE failed.
	 */
	private static function delete_meta_like( string $table, string $row_column, string $id_column, string $cache_group, string $prefix ): bool {
		global $wpdb;
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */

		$like = $wpdb->esc_like( $prefix ) . '%';

		if ( ! wp_using_ext_object_cache() ) {
			$delete = $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $table, $like );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared just above.
			return null !== $delete && false !== $wpdb->query( $delete );
		}

		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the uninstall's own page of the rows it deletes next.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT %i, %i FROM %i WHERE meta_key LIKE %s AND %i > %d ORDER BY %i LIMIT %d', $row_column, $id_column, $table, $like, $row_column, $after, $row_column, self::META_PAGE ), ARRAY_N );

			// wpdb clears last_error at the start of every query, so a
			// non-empty one belongs to this SELECT.
			if ( '' !== $wpdb->last_error || null === $rows ) {
				return false;
			}

			$row_ids = [];
			$objects = [];

			foreach ( $rows as $row ) {
				if ( is_numeric( $row[0] ?? null ) && is_numeric( $row[1] ?? null ) ) {
					$row_ids[]                = (int) $row[0];
					$objects[ (int) $row[1] ] = true;
				}
			}

			if ( [] === $row_ids ) {
				return true;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $row_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and column bound with %i; row ids bound one %d each.
			$delete = $wpdb->prepare( "DELETE FROM %i WHERE %i IN ({$placeholders})", $table, $row_column, ...$row_ids );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared just above.
			if ( null === $delete || false === $wpdb->query( $delete ) ) {
				return false;
			}

			$after = max( $row_ids );

			wp_cache_delete_multiple( array_keys( $objects ), $cache_group );

			$read = count( $rows );
		} while ( $read >= self::META_PAGE );

		return true;
	}

	/**
	 * Whether a table of the current blog exists.
	 *
	 * @param string $table Full table name.
	 * @return bool|null Null when the check itself failed.
	 */
	private static function table_exists( string $table ): ?bool {
		global $wpdb;
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe on the uninstall path only.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		// wpdb clears last_error at the start of every query, so a non-empty
		// one belongs to this SHOW, which returns null for "no such table" too.
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return null !== $found;
	}

	/**
	 * Delete the plugin's keys from WooCommerce's order meta table.
	 *
	 * With High-Performance Order Storage an order's meta lives in
	 * `{prefix}wc_orders_meta`, not in postmeta, so the postmeta sweep misses
	 * the order language tag there. Runs whenever the table exists, whether
	 * or not WooCommerce is active: in compatibility mode both tables hold
	 * the tag, and a store that has left HPOS keeps the table.
	 *
	 * @return bool False if the table check or a DELETE failed.
	 */
	private static function purge_order_meta(): bool {
		global $wpdb;
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */

		$table  = $wpdb->prefix . 'wc_orders_meta';
		$exists = self::table_exists( $table );

		// No such table is nothing to do; a failed check is a failure.
		if ( true !== $exists ) {
			return null !== $exists;
		}

		foreach ( DataRegistry::uninstall_meta_likes( 'order' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $table, $wpdb->esc_like( $prefix ) . '%' ) );

			if ( false === $deleted ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete the current blog's Action Scheduler rows of the plugin's group:
	 * the actions, their log lines, and the group row.
	 *
	 * Plain SQL on the blog's own tables (Action Scheduler builds their names
	 * from `$wpdb->prefix`, so every blog of a network has its own set), with
	 * no Action Scheduler API: its store is not loaded when WooCommerce is
	 * inactive, and its history rows outlive that. Each table is checked
	 * first. An action still `in-progress` is left with its log lines, and
	 * the group row with it: the request running it would fail to record
	 * its end on a missing row.
	 *
	 * @return bool False if a table check or a DELETE failed.
	 */
	private static function purge_action_scheduler_rows(): bool {
		global $wpdb;
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';

		$has_actions = self::table_exists( $actions );
		$has_groups  = self::table_exists( $groups );

		if ( null === $has_actions || null === $has_groups ) {
			return false;
		}

		if ( ! $has_actions || ! $has_groups ) {
			return true;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall path only.
		$group_id = $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $groups, self::AS_GROUP ) );

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		if ( null === $group_id ) {
			return true;
		}

		$group_id = (int) $group_id;
		$has_logs = self::table_exists( $logs );

		if ( null === $has_logs ) {
			return false;
		}

		if ( $has_logs && false === $wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l INNER JOIN %i a ON a.action_id = l.action_id WHERE a.group_id = %d AND a.status <> %s', $logs, $actions, $group_id, 'in-progress' ) ) ) {
			return false;
		}

		if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d AND status <> %s', $actions, $group_id, 'in-progress' ) ) ) {
			return false;
		}

		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d AND NOT EXISTS ( SELECT 1 FROM %i WHERE group_id = %d )', $groups, $group_id, $actions, $group_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $deleted;
	}

	/**
	 * Repeats the per-blog Action Scheduler sweep on the current blog;
	 * Action Scheduler tables are per blog.
	 *
	 * @return void
	 */
	public static function network_clear_action_scheduler(): void {
		self::clear_action_scheduler_orphans();
	}

	/**
	 * Defensive sweep: cancel every pending scheduled event the plugin
	 * could possibly own, regardless of engine or hook name. Used at
	 * full uninstall to defeat orphans that survived a misordered
	 * deactivation, mid-life engine flip, or third-party addon that
	 * registered a `perflocale_*` hook we don't know about.
	 *
	 * Action Scheduler side: enumerate every pending/in-progress action
	 * in the `perflocale` group via `as_get_scheduled_actions()`, collect
	 * the distinct hook names, then call `as_unschedule_all_actions(hook,
	 * [], group)` for each. This is the documented public-API path -
	 * preferable to passing an empty hook ("match all in group") which
	 * happens to work today but isn't a contracted behaviour.
	 *
	 * WP-Cron side: walks `_get_cron_array()` (private but stable since
	 * WP 3.5) and unschedules every `perflocale_*` hook. Includes an
	 * option-table fallback so a future WP refactor that renames or
	 * removes `_get_cron_array()` doesn't silently break the sweep.
	 *
	 * @return void
	 */
	private static function clear_all_scheduled_events(): void {
		self::clear_action_scheduler_orphans();
		self::clear_wp_cron_orphans();
	}

	/**
	 * Cancel every pending action in the `perflocale` group via the
	 * documented public AS API. Iterates through all pages because
	 * `as_get_scheduled_actions` paginates at ~100 by default.
	 *
	 * @return void
	 */
	private static function clear_action_scheduler_orphans(): void {
		if ( did_action( 'action_scheduler_init' ) === 0 ) {
			return;
		}

		// Preferred path: cancel every action in our group regardless of
		// hook/args. AS exposes `cancel_actions_by_group()` as a public
		// store method; `as_unschedule_all_actions('', [], $group)`
		// dispatches to it explicitly (see action-scheduler/functions.php
		// `as_unschedule_all_actions()`). This catches recurring events
		// that were scheduled with `[$blog_id]` args - which the older
		// per-hook + empty-args sweep silently missed.
		if ( class_exists( '\\ActionScheduler_Store' )
			&& method_exists( '\\ActionScheduler_Store', 'instance' )
		) {
			try {
				$store = \ActionScheduler_Store::instance();
				if ( method_exists( $store, 'cancel_actions_by_group' ) ) {
					$store->cancel_actions_by_group( self::AS_GROUP );
					return;
				}
			} catch ( \Throwable $e ) {
				// Fall through to the per-hook iteration below.
				unset( $e );
			}
		}

		// Fallback: enumerate hooks (with their actual args) and cancel
		// per-hook. Used only if the store API is missing - kept so
		// future AS API changes don't leave us silently unable to clean
		// up.
		if ( ! function_exists( 'as_get_scheduled_actions' )
			|| ! function_exists( 'as_unschedule_action' )
		) {
			return;
		}

		$page      = 1;
		$per_page  = 100;
		$max_pages = 100;

		while ( $page <= $max_pages ) {
			$ids = as_get_scheduled_actions(
				[
					'group'    => self::AS_GROUP,
					'status'   => [ 'pending', 'in-progress' ],
					'per_page' => $per_page,
					'paged'    => $page,
					'orderby'  => 'action_id',
					'order'    => 'ASC',
				],
				'ids'
			);

			if ( ! is_array( $ids ) || $ids === [] ) {
				break;
			}

			// Cancel by id - guarantees we catch every action regardless of
			// hook/args/group-membership shape.
			foreach ( $ids as $id ) {
				try {
					\ActionScheduler::store()->cancel_action( (int) $id );
				} catch ( \Throwable $e ) {
					// best-effort
					unset( $e );
				}
			}

			++$page;
			if ( count( $ids ) < $per_page ) {
				break;
			}
		}
	}

	/**
	 * Walk WP-Cron and remove every event whose hook name starts with
	 * `perflocale_`. Catches:
	 *   - Hooks in our Deactivator list but with unexpected args.
	 *   - Hooks we never knew about (registered by an addon).
	 *   - Hooks left orphaned after an engine-setting flip.
	 *
	 * @return void
	 */
	private static function clear_wp_cron_orphans(): void {
		$cron = self::read_cron_array();

		if ( ! is_array( $cron ) ) {
			return;
		}

		$perflocale_hooks = [];

		foreach ( $cron as $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook_name => $unused_events ) {
				if ( is_string( $hook_name )
					&& strpos( $hook_name, 'perflocale_' ) === 0 ) {
					$perflocale_hooks[ $hook_name ] = true;
				}
			}
		}

		foreach ( array_keys( $perflocale_hooks ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Read the cron array, preferring the public-API
	 * `_get_cron_array()` (private but stable since WP 3.5) and falling
	 * back to a direct `get_option('cron')` read if WP ever removes it.
	 *
	 * The option payload format - `[ timestamp => [ hook => [ key => event ] ] ]` -
	 * has been stable since at least WP 2.1 and is what `_get_cron_array()`
	 * decodes internally, so the fallback yields the same structure.
	 *
	 * @return array<int|string, mixed>|null
	 */
	private static function read_cron_array(): ?array {
		if ( function_exists( '_get_cron_array' ) ) {
			$cron = _get_cron_array();
			if ( is_array( $cron ) ) {
				return $cron;
			}
		}

		$cron = get_option( 'cron' );

		if ( ! is_array( $cron ) ) {
			return null;
		}

		// Newer WPs may store a `version` key on the cron option; drop
		// it so callers don't have to filter.
		unset( $cron['version'] );

		return $cron;
	}

	/**
	 * Preserve-mode cleanup. Strips role + caps (those live in roles/users
	 * tables and must always go on uninstall) but leaves all plugin data
	 * intact so a re-install resumes where the user left off.
	 *
	 * @return void
	 */
	private static function preserve_purge(): void {
		// `true`: this path may skip blogs that never had our caps. The full
		// purge may NOT - by the time it calls this, its own option sweep has
		// already deleted the flag the skip reads.
		self::strip_role_and_caps( true );
	}

	/**
	 * Run each addon's manifest-driven uninstaller. Errors are isolated -
	 * one bad addon does not block the rest of cleanup.
	 *
	 * @param bool $single_site_teardown True when one subsite is being
	 *     deleted: each addon purge then leaves its network-global targets
	 *     and the network-wide cache flushes alone.
	 * @return void
	 */
	private static function purge_addons( bool $single_site_teardown = false ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$manifest_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'perflocale_addon_manifest_' ) . '%'
			)
		);

		if ( ! class_exists( \PerfLocale\Addon\AddonUninstaller::class ) ) {
			return;
		}

		foreach ( (array) $manifest_keys as $key ) {
			$addon_id = substr( (string) $key, strlen( 'perflocale_addon_manifest_' ) );
			if ( '' === $addon_id ) {
				continue;
			}

			$live_addon = null;
			if (
				function_exists( 'perflocale' )
				&& class_exists( \PerfLocale\Plugin::class )
				&& \PerfLocale\Plugin::get_instance()->has( 'addon_registry' )
			) {
				$registry = \PerfLocale\Plugin::get_instance()->get( 'addon_registry' );
				if ( method_exists( $registry, 'get_addons' ) ) {
					$all        = (array) $registry->get_addons();
					$live_addon = $all[ $addon_id ] ?? null;
				}
			}

			try {
				$result = \PerfLocale\Addon\AddonUninstaller::purge(
					$addon_id,
					$live_addon instanceof \PerfLocale\Addon\AddonInterface ? $live_addon : null,
					$single_site_teardown
				);

				// Report what the addon purge could not do, here: the record
				// AddonMigrationErrors keeps of it is deleted a few lines below
				// with the rest of the plugin's options, so unreported, a table
				// that failed to drop would stay behind with no trace anywhere.
				// Reporting only: failing the whole blog for it would skip that
				// blog's ENTIRE purge to save a few addon rows, which is worse.
				self::report_addon_purge_problems( $addon_id, $result );
			} catch ( \Throwable $e ) {
				// Unconditional, like every other diagnostic on this path:
				// the plugin's files are about to go, and a failure here can
				// leave the addon's data behind.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
				error_log( '[PerfLocale] addon uninstall ' . $addon_id . ' failed: ' . $e->getMessage() );
			}
		}

		if ( class_exists( \PerfLocale\Addon\AddonMigrationErrors::class ) ) {
			delete_option( \PerfLocale\Addon\AddonMigrationErrors::OPTION );
		}
	}

	/**
	 * Log what an addon purge could not do.
	 *
	 * Unconditional, not behind WP_DEBUG_LOG: this runs on uninstall or site
	 * deletion, the plugin's own error record is deleted moments later, and
	 * an unreported failure here is data left behind with no trace.
	 *
	 * `skipped_by_filter` is not a problem - it is an operator's own filter
	 * choosing to keep that addon's data - so it is not reported.
	 *
	 * @param string                        $addon_id Addon identifier.
	 * @param \PerfLocale\Addon\PurgeResult $result   What the purge did.
	 * @return void
	 */
	private static function report_addon_purge_problems( string $addon_id, \PerfLocale\Addon\PurgeResult $result ): void {
		$problems = array_values(
			array_filter(
				array_map( 'strval', $result->errors ),
				static fn( string $error ): bool => 'skipped_by_filter' !== $error
			)
		);

		if ( null !== $result->custom_uninstall_error ) {
			$problems[] = 'before_uninstall() threw: ' . $result->custom_uninstall_error;
		} elseif ( $result->plan->had_custom_uninstall && ! $result->custom_uninstall_ran && ! in_array( 'skipped_by_filter', $result->errors, true ) ) {
			$problems[] = 'its custom cleanup (before_uninstall) did not run; clear any scheduled hooks, webhooks or external resources it owned by hand';
		}

		if ( [] === $problems ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
		error_log( '[PerfLocale] addon uninstall ' . $addon_id . ' left work undone: ' . implode( '; ', $problems ) );
	}

	/**
	 * Delete every wp_options row matching a LIKE pattern, going through
	 * delete_option/delete_transient so the alloptions / transient caches
	 * stay coherent.
	 *
	 * @param string $like_pattern e.g. 'perflocale_addon_manifest_%'.
	 * @return void
	 */
	private static function delete_options_like( string $like_pattern ): void {
		global $wpdb;

		// ⚠️ `_` IS A SINGLE-CHARACTER WILDCARD IN LIKE. Every pattern of
		// DataRegistry::uninstall_option_likes() contains underscores - `perflocale_lock_%` also
		// matches `perflocaleXlockY...`, so the DELETE reached further than the
		// pattern says. Nothing is known to have been lost (no other plugin
		// ships an option that close to ours), but a cleanup routine must
		// delete exactly what it claims to.
		//
		// Escape the literal underscores while PRESERVING the intended `%`:
		// split on `%`, escape each piece, rejoin. `esc_like()` on the whole
		// string would escape the trailing `%` too and match nothing. No
		// branch, and it matches the semantics the gate's orphan-data audit
		// has always modelled.
		$escaped = implode( '%', array_map( [ $wpdb, 'esc_like' ], explode( '%', $like_pattern ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$escaped
			)
		);

		foreach ( (array) $names as $name ) {
			$name = (string) $name;

			if ( str_starts_with( $name, '_transient_timeout_' ) || str_starts_with( $name, '_site_transient_timeout_' ) ) {
				// Usually already gone: delete_transient() on the matching
				// value row takes its timeout with it. But a timeout whose
				// value row no longer exists - left by a DB-cleanup tool or a
				// crashed write - has nothing to take it, and skipping it would
				// leave it behind forever. Delete the exact row. Not
				// delete_transient( substr( … ) ): that would build the name
				// `timeout_perflocale_…` and, on a persistent object cache,
				// only delete a cache key that never existed.
				delete_option( $name );
				continue;
			}

			// The API call keeps the transient caches coherent, but it does not
			// always reach this row. With a persistent object cache it deletes
			// only the cache key, and on multisite delete_site_transient()
			// targets wp_sitemeta, never a blog's options table. The row is
			// then deleted by name: left alone, it would outlive the timeout
			// row the branch above removes and never expire.
			if ( str_starts_with( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );

				if ( wp_using_ext_object_cache() ) {
					delete_option( $name );
				}
				continue;
			}

			if ( str_starts_with( $name, '_site_transient_' ) ) {
				delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );

				if ( wp_using_ext_object_cache() || is_multisite() ) {
					delete_option( $name );
				}
				continue;
			}

			delete_option( $name );
		}
	}

	/**
	 * Remove the perflocale_translator role and strip every PerfLocale cap
	 * from the standard caps roles. Honors the perflocale/roles/cap_roles
	 * filter so operators can keep custom roles untouched.
	 *
	 * @param bool $allow_skip When true (preserve path only), a blog that has
	 *     neither `perflocale_caps_version` nor the Translator role is left
	 *     alone. The full-data path must pass false: by the time it calls
	 *     this, its own option sweep has already deleted that flag.
	 * @param bool $drop_role_assignment Full-data path only - see
	 *     {@see sweep_orphan_user_caps()}. Also deletes Oxygen's access
	 *     option for the Translator role.
	 * @return void
	 */
	private static function strip_role_and_caps( bool $allow_skip = false, bool $drop_role_assignment = false ): void {
		// A blog that never had our caps installed has nothing to strip. On a
		// network uninstall in preserve mode this is the entire per-blog
		// cost: without it every blog of the installation pays a
		// remove_role() write, a remove_cap() write per cap per cap-role, and
		// a usermeta scan - on a large network, tens of thousands of writes
		// for blogs the plugin was never activated on.
		//
		// Both markers have to be absent. `perflocale_caps_version` is
		// written whenever caps are installed, and the role's presence is
		// checked directly, so a blog that has either one takes the full
		// path.
		//
		// Only the preserve path may take this shortcut. On the full-data
		// path the caller has already deleted `perflocale_caps_version` in
		// its option sweep, so the flag would read as absent on every blog
		// and a role-less blog would keep its editor/administrator caps.
		if ( $allow_skip
			&& false === get_option( 'perflocale_caps_version', false )
			&& null === get_role( \PerfLocale\Admin\TranslatorRole::ROLE_SLUG )
		) {
			return;
		}

		remove_role( \PerfLocale\Admin\TranslatorRole::ROLE_SLUG );

		// Oxygen stores an access level per editable role
		// (`oxygen_vsb_access_role_<role>`, added on every admin page load)
		// and never deletes one, and WordPress tells no plugin that a role
		// was removed. Once a full-data uninstall has removed the Translator
		// role for good, Oxygen's row for it can never be read again, so it
		// goes with the role. Deactivation and a preserve-mode uninstall keep
		// it: the role and the access level chosen for it come back together
		// on the next activation.
		if ( $drop_role_assignment ) {
			delete_option( 'oxygen_vsb_access_role_perflocale_translator' );
		}

		$caps = self::canonical_caps();

		// Which roles to strip. On a FULL-DATA uninstall, every role: an admin
		// may have granted perflocale_translate to Authors, a WooCommerce
		// shop_manager or a custom reviewer role, and those grants would
		// outlive a "delete all plugin data" uninstall if only the two roles
		// the plugin itself grants to were visited. Everywhere else - deactivation
		// and preserve mode - the narrow default is REQUIRED: install_caps()
		// re-grants only editor and administrator, so widening it there would
		// permanently strip custom roles on every deactivate/reactivate cycle.
		// The operator's filter still narrows either list.
		$default_roles = [ 'editor', 'administrator' ];

		if ( $drop_role_assignment && function_exists( 'wp_roles' ) ) {
			$default_roles = array_keys( wp_roles()->roles );
		}

		/** This filter is documented in src/Admin/TranslatorRole.php. */
		$cap_roles = (array) apply_filters( 'perflocale/roles/cap_roles', $default_roles );

		foreach ( $cap_roles as $role_slug ) {
			$role = get_role( sanitize_key( (string) $role_slug ) );

			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				// WP_Roles::remove_cap() calls update_option() on the whole
				// `wp_user_roles` array every time, including for a cap the
				// role never had - serialising the array and comparing it
				// against the stored copy on each call. update_option()
				// no-ops when the value is unchanged, so those calls cost
				// work rather than DB writes; skipping them is the saving,
				// and it is not a row-count saving. An earlier draft of this
				// patch claimed "14 writes to 4" because the bench measuring
				// it counted calls as writes.
				if ( ! isset( $role->capabilities[ $cap ] ) ) {
					continue;
				}

				$role->remove_cap( $cap );
			}
		}

		// Sweep users for orphaned `perflocale_*` capability grants. Same
		// helper is called by TranslatorRole::remove_roles() during plugin
		// deactivation so users with directly-granted caps don't keep
		// dead serialized refs across deactivation/reactivation cycles.
		self::sweep_orphan_user_caps( $drop_role_assignment );

		// Drop the install version flag in lock-step with the caps we just
		// removed. The flag and the caps are two halves of the same state -
		// if the caps go and the flag stays, install_caps() short-circuits
		// on the next reinstall and the caps never come back. full_purge()
		// already deletes this through the registry's option list, so the duplicate is a
		// harmless no-op there; the load-bearing call is from preserve_purge().
		delete_option( 'perflocale_caps_version' );
	}

	/**
	 * Strip every `perflocale_*` key from per-user `wp_capabilities` meta.
	 *
	 * Called from BOTH `strip_role_and_caps()` (on full uninstall) and
	 * `TranslatorRole::remove_roles()` (on plugin deactivation). Without
	 * the shared helper the two paths drifted: only the uninstall path
	 * stripped direct add_cap() grants, leaving orphan serialized refs in
	 * user_meta across deactivation/reactivation cycles.
	 *
	 * Safe on multisite: the call site has already entered the correct
	 * blog via switch_to_blog, so `$wpdb->usermeta` resolves to the
	 * current blog's table.
	 *
	 * @param bool $drop_role_assignment Also remove the Translator ROLE key.
	 *     Full-data uninstall only - deactivation and preserve mode keep it,
	 *     because WordPress promises that a deactivation is reversible.
	 * @return void
	 */
	public static function sweep_orphan_user_caps( bool $drop_role_assignment = false ): void {
		global $wpdb;

		$cap_meta_key = $wpdb->prefix . 'capabilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dirty_user_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$cap_meta_key,
				'%perflocale_%'
			)
		);

		// Strip CAPABILITIES only, never the role assignment. WordPress stores a
		// role assignment as a KEY in wp_capabilities, and ROLE_SLUG is
		// 'perflocale_translator' - which matches a naive 'perflocale_' prefix
		// test. Stripping it leaves a translator-only user with a:0:{}: no role,
		// no `read` cap, locked out of wp-admin, with nothing recording who had
		// it. That would fire on a plain deactivation (which WordPress
		// guarantees is reversible) and on preserve-mode uninstall. Core's own
		// remove_role() deliberately leaves assignments in place so a
		// reactivation restores them; only direct add_cap() grants are orphans.
		//
		// EXCEPT on a full-data uninstall ($drop_role_assignment). There the
		// role itself is gone, so a translator-only user already has no
		// capabilities at all - stripping the key locks nobody out who was not
		// locked out already. Leaving it would mean that deleting the plugin
		// with "Delete all plugin data" ticked and installing it again (a
		// common troubleshooting step) silently hands every former translator
		// the recreated role back, edit_others_posts included, with no one
		// having assigned it. Deactivation and preserve mode never pass true.
		$strip = array_merge(
			self::canonical_caps(),
			// Retired capabilities that earlier builds granted directly.
			[ 'perflocale_manage_glossary' ]
		);

		if ( $drop_role_assignment && class_exists( \PerfLocale\Admin\TranslatorRole::class ) ) {
			$strip[] = \PerfLocale\Admin\TranslatorRole::ROLE_SLUG;
		}

		$strip_caps = array_flip( $strip );

		foreach ( $dirty_user_ids as $uid ) {
			$uid    = (int) $uid;
			$stored = get_user_meta( $uid, $cap_meta_key, true );

			if ( ! is_array( $stored ) ) {
				continue;
			}

			$cleaned = array_filter(
				$stored,
				static fn( $key ): bool => ! isset( $strip_caps[ (string) $key ] ),
				ARRAY_FILTER_USE_KEY
			);

			if ( $cleaned !== $stored ) {
				update_user_meta( $uid, $cap_meta_key, $cleaned );
			}
		}
	}

	/**
	 * Caps to strip on uninstall: current TranslatorRole::CAPABILITIES, with
	 * a static fallback if the class can't be loaded (e.g. during early
	 * uninstall ordering).
	 *
	 * @return string[]
	 */
	private static function canonical_caps(): array {
		return class_exists( \PerfLocale\Admin\TranslatorRole::class )
			? \PerfLocale\Admin\TranslatorRole::CAPABILITIES
			: [
				'perflocale_translate',
				'perflocale_manage_translations',
				'perflocale_approve_translations',
				'perflocale_manage_languages',
				'perflocale_manage_addons',
				'perflocale_use_mt',
				'perflocale_import_export',
			];
	}

	/**
	 * Purge the plugin's uploads tree on uninstall:
	 *   - `uploads/perflocale/translations/` (generated .l10n.php files)
	 *   - `uploads/perflocale/temp/`         (in-flight import scratch)
	 *   - `uploads/perflocale/exports/`      (export bundles)
	 *   - `uploads/perflocale/`              (the consolidated parent)
	 *
	 * @return void
	 */
	private static function delete_translation_files(): void {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return;
		}
		$basedir = trailingslashit( (string) $upload_dir['basedir'] );

		self::delete_dir_recursive( $basedir . 'perflocale' );
	}

	/**
	 * Recursive directory removal. Refuses to descend into anything
	 * outside `uploads/` so a buggy caller can't traverse beyond.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function delete_dir_recursive( string $dir ): void {
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return;
		}
		$uploads_real = realpath( (string) $upload_dir['basedir'] );
		$dir_real     = realpath( $dir );

		if ( $uploads_real === false || $dir_real === false
			|| ! str_starts_with( $dir_real, rtrim( $uploads_real, '/' ) . DIRECTORY_SEPARATOR )
		) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @ suppresses the unreadable-dir warning; is_array() check on the next line short-circuits the cleanup if scandir() actually fails.
		$entries = @scandir( $dir_real );
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir_real . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::delete_dir_recursive( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// Raw rmdir to match the credential-free wp_delete_file() (unlink)
		// calls that just emptied this directory: WP_Filesystem here would
		// need FS credentials that aren't guaranteed during uninstall, which
		// would leave the now-empty dir behind on FTP/SSH hosts.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Credential-free cleanup; see note above.
		@rmdir( $dir_real );
	}
}
