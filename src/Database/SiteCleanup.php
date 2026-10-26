<?php
/**
 * Per-site PerfLocale data purge: uninstall.php per blog, and wp_uninitialize_site when a subsite is deleted; both
 * delete what DataRegistry lists for uninstall, so they cannot drift from it.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-site cleanup, static only, run inside the blog being purged (switch_to_blog() first on multisite).
 * Docs: docs-ai/11-code-rationale.md, "SiteCleanup: the network uninstall sweep".
 */
final class SiteCleanup {

	/**
	 * Blog ids purged in this request, for the remove_user_from_blog handler core fires later in the same
	 * wp_delete_site() (purge_current_site()).
	 *
	 * @var array<int, true>
	 */
	private static array $purged_blogs = [];

	/**
	 * Network uninstall: each add-on's network-global targets the per-blog purges left for the end, and whether a blog's
	 * filter kept them.
	 *
	 * @var array<string, array{site_options: array<int, string>, user: array<int, string>, kept: bool}>
	 */
	private static array $addon_shared = [];

	/**
	 * Whether this request already purged the blog's plugin data.
	 *
	 * @param int $blog_id Blog to check.
	 * @return bool
	 */
	public static function was_site_purged( int $blog_id ): bool {
		return isset( self::$purged_blogs[ $blog_id ] );
	}

	/**
	 * Action Scheduler group of every PerfLocale action, copied so uninstall.php need not load ActionSchedulerRunner;
	 * keep it equal to ActionSchedulerRunner::GROUP.
	 *
	 * @var string
	 */
	private const AS_GROUP = 'perflocale';

	/**
	 * The settings row, which also holds the blog's `delete_data_on_uninstall`: its registry uninstall value is `last`,
	 * so purge_current_site() deletes it at the very end.
	 *
	 * @var string
	 */
	public const MODE_OPTION = 'perflocale_settings';

	/**
	 * Network option of the sweep's resume marker, on the main network: `[ 'last_site_id', 'updated', 'any_preserve',
	 * 'any_delete', 'unflushed' ]` (every blog at or below last_site_id is purged; the flags record the blogs' choices and
	 * an unflushed shared cache).
	 *
	 * @var string
	 */
	public const RESUME_OPTION = 'perflocale_uninstall_resume';

	/**
	 * MtCreateNotice::WAITING_META_PREFIX, which uninstall does not load: "<prefix><blog id>_<post id>".
	 */
	private const MT_NOTICE_META_PREFIX = 'perflocale_mt_notice_';

	/**
	 * Seconds a resume marker is trusted: a forgotten one would skip blogs on an uninstall months later, while expiring
	 * early only re-walks clean blogs. A literal, not DAY_IN_SECONDS: this class loads without WordPress in a harness.
	 *
	 * @var int
	 */
	private const RESUME_MAX_AGE = 86400;

	/**
	 * Share of max_execution_time the network purge may spend (as Deactivator::NETWORK_SWEEP_TIME_SHARE); the rest covers
	 * the blog in progress, the network options and core's removal of the files.
	 *
	 * @var float
	 */
	private const NETWORK_PURGE_TIME_SHARE = 0.7;

	/**
	 * Meta rows per page of delete_meta_like() with a persistent object cache.
	 */
	private const META_PAGE = 5000;

	/**
	 * Purge every blog of the installation by its own `delete_data_on_uninstall` (uninstall.php only; WP-CLI has no
	 * deadline). Not a background job (the files go right after) and not scoped to one network (every network loses the
	 * code); an interrupted sweep keeps a marker below the first failure, and every step is idempotent.
	 *
	 * @param float|null $deadline Wall-clock time (microtime(true)) after
	 *     which the loop stops between blogs, or null for no limit.
	 * @return array{complete: bool, out_of_time: bool, list_error: bool, meta_error: bool, entered: int, purged: int, failed: int, skipped: int, remaining: int, last_site_id: int, total: int}
	 */
	public static function purge_network( ?float $deadline = null ): array {
		/** This filter is documented in src/Deactivator.php. */
		$filtered_chunk = apply_filters( 'perflocale/activation/chunk_size', 100 );
		// A non-numeric answer uses the default, not 0 clamped to 1 (one site query per blog).
		$chunk = is_numeric( $filtered_chunk ) ? max( 1, (int) $filtered_chunk ) : 100;

		// The marker is whatever is in wp_sitemeta: anything malformed, without both flags, or stale degrades to a fresh
		// start (re-walking clean blogs is safe, skipping dirty ones is not).
		$marker             = get_network_option( self::marker_network(), self::RESUME_OPTION, [] );
		$done_up_to         = 0;
		$any_preserve       = false;
		$any_delete         = false;
		$unflushed          = false;
		self::$addon_shared = [];

		if ( is_array( $marker ) ) {
			$well_formed = isset( $marker['last_site_id'], $marker['any_preserve'], $marker['any_delete'] )
				&& is_numeric( $marker['last_site_id'] );

			// The earlier blogs' choices survive the marker's age: a purged delete-mode blog has lost its settings row, so these
			// flags are its only record; they cannot make the walk skip a blog.
			if ( $well_formed ) {
				$any_preserve       = (bool) $marker['any_preserve'];
				$any_delete         = (bool) $marker['any_delete'];
				$unflushed          = ! empty( $marker['unflushed'] );
				self::$addon_shared = self::read_addon_shared( $marker['addon_shared'] ?? [] );
			}

			$age     = isset( $marker['updated'] ) && is_numeric( $marker['updated'] ) ? time() - (int) $marker['updated'] : -1;
			$trusted = $well_formed && $age >= 0 && $age <= self::RESUME_MAX_AGE;

			if ( $trusted ) {
				$done_up_to = max( 0, (int) $marker['last_site_id'] );
			} else {
				// Stale, from the future (clock change) or untrusted: dropped, so no later uninstall inherits a skip.
				delete_network_option( self::marker_network(), self::RESUME_OPTION );
			}
		}

		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// Blogs earlier passes finished, counted once for the log; the walk starts after them.
		$skipped = 0;

		if ( $done_up_to > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one COUNT on the uninstall path; the blogs table has no API for an id range.
			$skipped = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE blog_id <= %d', $wpdb->blogs, $done_up_to ) );
		}

		// Keyset paging on blog_id: an offset over a list that shrinks mid-sweep skips a blog. A direct query: WP_Site_Query
		// has no "id greater than", and a sites_clauses filter is defeated by its query cache.
		$cursor         = $done_up_to;
		$entered        = 0;
		$purged         = 0;
		$failed         = 0;
		$high_water     = $done_up_to;
		$had_failure    = false;
		$list_error     = false;
		$out_of_time    = false;
		$got_full_chunk = false;

		// Whether this pass entered a delete-mode blog: only then is there anything to flush from the shared cache.
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

			// A failed page query returns [], which reads like the end of the network (a false complete pass).
			if ( '' !== $wpdb->last_error ) {
				$list_error  = true;
				$had_failure = true;

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path; there is no UI left to surface it in.
				error_log( '[PerfLocale] uninstall could not read the list of sites after site ' . $cursor . ': ' . $wpdb->last_error );
				break;
			}

			$site_ids = array_map( 'intval', (array) $page );

			foreach ( $site_ids as $site_id ) {
				// The cursor advances whatever happens; what counts as done is $high_water, which a failure freezes.
				$cursor = $site_id;

				// At least one blog per request, and the deadline only before switch_to_blog(), so no blog is half-purged by it.
				if ( $entered > 0 && null !== $deadline && microtime( true ) >= $deadline ) {
					$out_of_time = true;
					break 2;
				}

				++$entered;

				// switch_to_blog() inside the try: a throwing `switch_blog` listener would leave the wrong blog current and abort
				// the sweep; the finally restores.
				try {
					switch_to_blog( $site_id );

					$mode = self::blog_uninstall_mode();

					if ( 'preserve' === $mode ) {
						$any_preserve = true;
					} elseif ( 'delete' === $mode ) {
						// Recorded before the pass's first deletion: a killed pass never reaches the end, and the purged blog's settings row
						// is gone. Only re-walks can follow, never skips.
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
									'addon_shared' => self::$addon_shared,
								]
							);
						}

						$any_delete        = true;
						$deleted_this_pass = true;
					}

					// `true`: user meta and the shared cache flush are left to the end of the pass.
					if ( ! self::purge_current_site( 'delete' === $mode, false, true ) ) {
						// A statement failed without throwing (logged): the blog is not done, nor the pass.
						++$failed;
						$had_failure = true;
						continue;
					}

					++$purged;

					if ( ! $had_failure ) {
						$high_water = $site_id;
					}
				} catch ( \Throwable $e ) {
					// One blog's failure never aborts the sweep.
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

		// Complete only if it reached the end with no failure; otherwise the marker stays below the first failure and
		// deleting the plugin again resumes there (counted complete, uninstall.php would drop the network options).
		$complete = ! $out_of_time && ! $had_failure;
		$total    = 0;

		// The shared cache groups once per pass that deleted something (or was left unflushed): on Redis a group flush scans
		// the whole keyspace, and a pass where every blog kept its data must not empty every plugin's transients.
		if ( $deleted_this_pass || $unflushed ) {
			self::flush_shared_cache_groups();
		}

		// User meta is network-global: swept once, now, only if some blog chose deletion and none kept data; before the
		// marker goes (its flags are the only record left). A failed DELETE keeps the pass incomplete.
		$meta_error = false;

		if ( $complete && $any_delete && ! $any_preserve && ! self::purge_network_user_data() ) {
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
					'addon_shared' => self::$addon_shared,
				]
			);
		}

		return [
			'complete'     => $complete,
			// Why a pass is incomplete: each needs different advice.
			'out_of_time'  => $out_of_time,
			'list_error'   => $list_error,
			'meta_error'   => $meta_error,
			'entered'      => $entered,
			'purged'       => $purged,
			'failed'       => $failed,
			'skipped'      => $skipped,
			// Blogs left: those this pass did not reach plus those that failed.
			'remaining'    => max( 0, $total - $skipped - $purged ),
			'last_site_id' => $high_water,
			'total'        => $total,
		];
	}

	/**
	 * The network of the resume marker, the main one: any network's super admin can delete the plugin, and the sweep
	 * visits every network.
	 *
	 * @return int
	 */
	private static function marker_network(): int {
		return function_exists( 'get_main_network_id' ) ? (int) get_main_network_id() : 1;
	}

	/**
	 * Forget an unfinished uninstall on activation: a leftover marker would make a later uninstall skip blogs.
	 *
	 * @return void
	 */
	public static function forget_uninstall_progress(): void {
		delete_network_option( self::marker_network(), self::RESUME_OPTION );
	}

	/**
	 * Remove PerfLocale's network options from every network after a completed sweep (delete_site_option() acts on the
	 * calling network only).
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
	 * End of a network uninstall that deletes user data: PerfLocale's user meta minus kept add-ons' keys, and the
	 * network options and user meta the add-on purges deferred.
	 *
	 * @return bool False if a DELETE failed.
	 */
	private static function purge_network_user_data(): bool {
		$kept = [
			'site_options' => [],
			'user'         => [],
		];
		$gone = $kept;

		foreach ( self::$addon_shared as $entry ) {
			foreach ( [ 'site_options', 'user' ] as $kind ) {
				if ( $entry['kept'] ) {
					$kept[ $kind ] = array_merge( $kept[ $kind ], $entry[ $kind ] );
				} else {
					$gone[ $kind ] = array_merge( $gone[ $kind ], $entry[ $kind ] );
				}
			}
		}

		if ( ! self::sweep_plugin_user_meta( $kept['user'] ) ) {
			return false;
		}

		// A name a kept add-on declares too stays.
		$site = array_values( array_diff( array_unique( $gone['site_options'] ), $kept['site_options'] ) );
		$user = array_values( array_diff( array_unique( $gone['user'] ), $kept['user'] ) );

		if ( [] === $site && [] === $user ) {
			return true;
		}

		return [] === \PerfLocale\Addon\AddonUninstaller::purge_shared( $site, $user );
	}

	/**
	 * The marker's add-on list as read; anything not of its shape is left out.
	 *
	 * @param mixed $raw Stored list.
	 * @return array<string, array{site_options: array<int, string>, user: array<int, string>, kept: bool}>
	 */
	private static function read_addon_shared( $raw ): array {
		$out = [];

		foreach ( is_array( $raw ) ? $raw : [] as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$names = array_filter( is_array( $entry['site_options'] ?? null ) ? $entry['site_options'] : [], 'is_string' );

			$out[ (string) $id ] = [
				// A name without its network id is on the main network.
				'site_options' => array_values( array_map( static fn( string $n ): string => 1 === preg_match( '/^[1-9][0-9]*:/', $n ) ? $n : self::marker_network() . ':' . $n, $names ) ),
				'user'         => array_values( array_filter( is_array( $entry['user'] ?? null ) ? $entry['user'] : [], 'is_string' ) ),
				'kept'         => ! empty( $entry['kept'] ),
			];
		}

		return $out;
	}

	/**
	 * The network purge's wall-clock deadline from the request start ($timestart), or null without an execution limit.
	 * A proxy for the web server's timeout PHP cannot see: early costs a pass, late a half-purged blog.
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
	 * The current blog's uninstall choice: 'delete', 'preserve', or 'none' without a settings row (never activated, or
	 * unreadable). 'none' is neutral in the network's one user-meta decision.
	 *
	 * @return string 'delete' | 'preserve' | 'none'
	 */
	private static function blog_uninstall_mode(): string {
		$absent   = new \stdClass();
		$settings = get_option( self::MODE_OPTION, $absent );

		if ( $absent === $settings ) {
			return 'none';
		}

		// Present but unreadable counts as keep, never as delete.
		return ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) ? 'delete' : 'preserve';
	}

	/**
	 * The current blog's "delete data on uninstall" decision (single-site uninstall reads it through
	 * perflocale_should_delete_data(); the network sweep uses blog_uninstall_mode() per blog).
	 *
	 * @return bool True if this blog opted in to full data deletion.
	 */
	public static function blog_wants_data_deleted(): bool {
		$settings = get_option( 'perflocale_settings', [] );

		return is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );
	}

	/**
	 * Run the chosen cleanup for the current blog (the caller switches blogs). Docs: docs-ai/11-code-rationale.md,
	 * "SiteCleanup: one blog's purge".
	 *
	 * @param bool $delete_data When true, runs the full data-deleting
	 *     purge. When false, only role/cap removal (preserves all data so
	 *     a re-install picks up where the user left off).
	 * @param bool $single_site_teardown True when one subsite is deleted (wp_uninitialize_site): network-global stores
	 *     (wp_usermeta) are not swept, or the surviving sites would lose every user's preferences.
	 * @param bool $network_sweep True from purge_network(), which flushes the shared cache once per pass and decides the
	 *     wp_usermeta sweep once.
	 * @return bool False if a table drop, a post/term/order meta DELETE, the
	 *     Action Scheduler history DELETE or (single site) the user-meta DELETE
	 *     failed; the settings row is then kept, so the next pass finishes the
	 *     job in the same mode. Always true in preserve mode.
	 */
	public static function purge_current_site( bool $delete_data, bool $single_site_teardown = false, bool $network_sweep = false ): bool {
		// Blogs this request purged: core's wp_uninitialize_site() (priority 10, after our 5) then fires
		// remove_user_from_blog per member, and Bootstrap::anonymize_jobs_on_blog() skips these dropped tables.
		self::$purged_blogs[ get_current_blog_id() ] = true;

		$clean = true;

		if ( $delete_data ) {
			$clean = self::full_purge( $single_site_teardown, $network_sweep );
		} else {
			self::preserve_purge();
		}

		// Scheduled events always go, data kept or not: their callbacks are leaving disk.
		self::clear_all_scheduled_events();

		// The delete path also removes the blog's Action Scheduler history of the group (kept for good otherwise), after the
		// cancel above so its log lines go too.
		if ( $delete_data && ! self::purge_action_scheduler_rows() ) {
			$clean = false;
		}

		// Last, and only when everything above succeeded: the settings row holds the delete-or-preserve choice, so an
		// interrupted purge resumes in the same mode. Its own delete is not checked (false also for a gone row).
		if ( $delete_data && $clean ) {
			delete_option( self::MODE_OPTION );
		}

		// Not logged when the site itself is deleted: core drops its tables right after; a leftover perflocale_* table is
		// logged in full_purge().
		if ( ! $clean && ! $single_site_teardown ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
			error_log( '[PerfLocale] uninstall could not delete all of site ' . get_current_blog_id() . "'s PerfLocale tables, meta or scheduled-action history; its settings are kept so deleting the plugin again finishes the job." );
		}

		return $clean;
	}

	/**
	 * Drop the current blog's tables, options, transients, meta, roles and caps, and translation files.
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

		// Whether the steps a retry needs the settings row for all succeeded; only `false === $wpdb->query()` fails (0 rows
		// is normal and must not pin every blog's row).
		$clean = true;

		// 1. Add-on manifests first (they work without the add-on on disk); what a kept add-on declared stays below.
		$keep = self::purge_addons( $single_site_teardown, $network_sweep );

		if ( $keep['unjournaled'] ) {
			$clean = false;
		}

		// 2. Core tables (Schema::drop_tables()).
		SchemaWrites::drop_tables( $keep['tables'] );

		// drop_tables() reports nothing and a DROP can fail (lock wait, metadata lock): ask what is left.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one SHOW TABLES per purged blog, on the uninstall path only.
		$left = array_filter( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'perflocale_' ) . '%' ) ), 'is_string' );

		// A failed SHOW also returns no rows: last_error decides.
		if ( [] !== array_diff( $left, $keep['tables'] ) || '' !== $wpdb->last_error ) {
			$clean = false;

			if ( $single_site_teardown ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the site-deletion path.
				error_log( '[PerfLocale] site ' . get_current_blog_id() . ' is being deleted, but its ' . $wpdb->prefix . 'perflocale_* tables could not all be dropped (or checked); WordPress does not remove them, so drop any that remain by hand.' );
			}
		}

		// 3. The registry's option names and LIKE patterns (transient rows included). perflocale_settings is held back to the
		// end (purge_current_site()): a purge dying part-way must resume in delete mode.
		foreach ( DataRegistry::uninstall_options() as $opt ) {
			if ( self::MODE_OPTION === $opt ) {
				continue;
			}

			// A kept add-on keeps its schema version, so a reinstall resumes it.
			if ( [] !== $keep['ids'] && \PerfLocale\Addon\AddonSchemaManager::VERSIONS_OPTION === $opt ) {
				$versions = get_option( $opt );
				$versions = is_array( $versions ) ? array_intersect_key( $versions, array_flip( $keep['ids'] ) ) : [];

				if ( [] !== $versions ) {
					update_option( $opt, $versions, false );
					continue;
				}
			}

			delete_option( $opt );
		}

		foreach ( DataRegistry::uninstall_option_likes() as $pattern ) {
			self::delete_options_like( $pattern, $keep['options'] );
		}

		// 4. User meta by the registry's prefixes (the namespace is ours); DataRegistry::user_meta_keys() is the GDPR list.
		// Never on a single-subsite teardown or in the network sweep: wp_usermeta is network-global.
		if ( ! $single_site_teardown && ! $network_sweep && ! self::sweep_plugin_user_meta( $keep['meta']['user'] ?? [] ) ) {
			$clean = false;
		}

		// 4b. This blog's MT notice markers: per blog, so they go with it whatever the shared sweep decides.
		if ( ( $single_site_teardown || $network_sweep ) && ! self::delete_meta_like( $wpdb->usermeta, 'umeta_id', 'user_id', 'user_meta', self::MT_NOTICE_META_PREFIX . get_current_blog_id() . '_' ) ) {
			$clean = false;
		}

		// 5. Term meta: the registry's prefixes (menu language tags and pairings).
		foreach ( DataRegistry::uninstall_meta_likes( 'term' ) as $prefix ) {
			if ( ! self::delete_meta_like( $wpdb->termmeta, 'meta_id', 'term_id', 'term_meta', $prefix, $keep['meta']['term'] ?? [] ) ) {
				$clean = false;
			}
		}

		// 6. Post meta: the registry's prefixes, each esc_like()'d so underscores stay literal.
		foreach ( DataRegistry::uninstall_meta_likes( 'post' ) as $prefix ) {
			if ( ! self::delete_meta_like( $wpdb->postmeta, 'meta_id', 'post_id', 'post_meta', $prefix, $keep['meta']['post'] ?? [] ) ) {
				$clean = false;
			}
		}

		// 6b. The same keys in WooCommerce's HPOS order meta table.
		if ( ! self::purge_order_meta() ) {
			$clean = false;
		}

		// 7. Roles and caps; `true`: the full path also removes the Translator role assignment (sweep_orphan_user_caps()).
		self::strip_role_and_caps( false, true );

		// 8. Translation files in uploads/perflocale/translations.
		self::delete_translation_files();

		// 9. Delete the cached rewrite rules, not flush_rewrite_rules(): inside switch_to_blog() $wp_rewrite is not
		// re-initialised (the first blog's rules would stick here), and regenerating walks into the dropped tables.
		if ( ! $single_site_teardown ) {
			delete_option( 'rewrite_rules' );
		}

		// 10. The object cache: every plugin group per blog; the shared half only on a single-site uninstall (one subsite's
		// deletion must not flush every site's transients; the network sweep flushes once per pass).
		self::flush_cache_groups( ! $single_site_teardown && ! $network_sweep );

		// purge_current_site() clears the scheduled events after this returns.

		return $clean;
	}

	/**
	 * Invalidate every plugin cache group of the current blog, and with $shared the shared groups; static, as uninstall
	 * has no container. Groups from CacheManager::GROUPS. Docs: docs-ai/11-code-rationale.md, "SiteCleanup: caches,
	 * options and capabilities".
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

		// A generation bump works on every backend, also where wp_cache_flush_group() is missing or a no-op.
		foreach ( \PerfLocale\Cache\CacheManager::GROUPS as $group ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( $group );
		}

		// With a persistent cache the plugin's transients live in the 'transient' groups, not wp_options: flushed too (an
		// external cache and WP 6.1+ only; single-site uninstall only).
		if ( $shared ) {
			self::flush_shared_cache_groups();
		}

		// The bump above rewrites each `perflocale_cgen_<group>` option the option sweep deleted: delete them again, last.
		// A reinstall starts its generations at the clock, so it never reads the keys these gated.
		self::delete_options_like( 'perflocale_cgen_%' );
	}

	/**
	 * Flush the network-shared object-cache groups ('transient', 'site-transient', each plugin group) so a reinstall reads
	 * no ghost keys. On Redis a flush reaches every blog: once per uninstall pass, never per blog, never on a pass that
	 * deleted nothing, never for one deleted subsite. An external cache and WP 6.1+ only.
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
	 * Delete every `perflocale_*` user meta row by the registry's prefixes (our namespace). wp_usermeta is network-global
	 * and the keys are not blog-prefixed: only on a single-site uninstall or once after a completed network sweep.
	 *
	 * @param array<int, string> $keep User meta keys to leave (an addon that kept its data).
	 * @return bool False only if a DELETE failed. 0 rows deleted is success.
	 */
	private static function sweep_plugin_user_meta( array $keep = [] ): bool {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return false;
		}

		foreach ( DataRegistry::uninstall_meta_likes( 'user' ) as $prefix ) {
			if ( ! self::delete_meta_like( $wpdb->usermeta, 'umeta_id', 'user_id', 'user_meta', $prefix, $keep ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete one meta table's rows whose key starts with a prefix, and the cached meta of their objects (the DELETE
	 * bypasses the meta API, and a persistent cache would keep serving them): in pages of META_PAGE by row id with such
	 * a cache, else one DELETE.
	 *
	 * @param string $table       Meta table.
	 * @param string $row_column  Its row id column (meta_id, umeta_id).
	 * @param string $id_column   Its object id column (post_id, term_id, user_id).
	 * @param string $cache_group The core cache group of that meta type.
	 * @param string $prefix      Literal key prefix.
	 * @param array<int, string> $keep Keys to leave.
	 * @return bool False if a read or a DELETE failed.
	 */
	private static function delete_meta_like( string $table, string $row_column, string $id_column, string $cache_group, string $prefix, array $keep = [] ): bool {
		global $wpdb;
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */

		$like   = $wpdb->esc_like( $prefix ) . '%';
		$keep   = array_values( array_unique( array_filter( $keep, 'is_string' ) ) );
		$not_in = [] === $keep ? '' : ' AND meta_key NOT IN (' . implode( ', ', array_fill( 0, count( $keep ), '%s' ) ) . ')';

		if ( ! wp_using_ext_object_cache() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $not_in is placeholders only; the kept keys are bound one %s each.
			$delete = $wpdb->prepare( "DELETE FROM %i WHERE meta_key LIKE %s{$not_in}", $table, $like, ...$keep );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared just above.
			return null !== $delete && false !== $wpdb->query( $delete );
		}

		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the uninstall's own page of the rows it deletes next; $not_in is placeholders only, the kept keys bound one %s each.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i, %i FROM %i WHERE meta_key LIKE %s{$not_in} AND %i > %d ORDER BY %i LIMIT %d", $row_column, $id_column, $table, $like, ...array_merge( $keep, [ $row_column, $after, $row_column, self::META_PAGE ] ) ), ARRAY_N );

			// last_error belongs to this SELECT (wpdb clears it per query).
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared just above.
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

		// last_error belongs to this SHOW, which answers null for "no such table" too.
		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return null !== $found;
	}

	/**
	 * Delete the plugin's keys from WooCommerce's HPOS order meta table, whenever it exists (compatibility mode keeps both
	 * tables; a store that left HPOS keeps the table).
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

		// No table: nothing to do; a failed check fails.
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
	 * Delete the blog's Action Scheduler rows of the plugin's group (actions, logs, group) by plain SQL on its own tables:
	 * the store is not loaded without WooCommerce, and the rows outlive it. An in-progress action keeps its rows.
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

		// One table per DELETE: the SQLite integration's 2.2.x legacy driver mishandles a DELETE with a JOIN.
		if ( $has_logs && false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE action_id IN ( SELECT action_id FROM %i WHERE group_id = %d AND status <> %s )', $logs, $actions, $group_id, 'in-progress' ) ) ) {
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
	 * The per-blog Action Scheduler sweep on the current blog (its tables are per blog).
	 *
	 * @return void
	 */
	public static function network_clear_action_scheduler(): void {
		self::clear_action_scheduler_orphans();
	}

	/**
	 * Cancel every pending event the plugin could own, any engine or hook (a misordered deactivation, an engine switch,
	 * an add-on's `perflocale_*` hook): Action Scheduler through its public API, WP-Cron through _get_cron_array() with
	 * an option fallback.
	 *
	 * @return void
	 */
	private static function clear_all_scheduled_events(): void {
		self::clear_action_scheduler_orphans();
		self::clear_wp_cron_orphans();
	}

	/**
	 * Cancel every pending action of the `perflocale` group through Action Scheduler's API, all pages.
	 *
	 * @return void
	 */
	private static function clear_action_scheduler_orphans(): void {
		if ( did_action( 'action_scheduler_init' ) === 0 ) {
			return;
		}

		// Preferred: the store's cancel_actions_by_group() through as_unschedule_all_actions( '', [], $group ), which also
		// catches recurring events scheduled with [ $blog_id ] args.
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
				// Fall through to the per-hook path below.
				unset( $e );
			}
		}

		// Fallback when the store API is missing: enumerate hooks with their args and cancel each.
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

			// Cancel by id: every action, whatever its hook, args or group shape.
			foreach ( $ids as $id ) {
				try {
					\ActionScheduler::store()->cancel_action( (int) $id );
				} catch ( \Throwable $e ) {
					// Best effort.
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
	 * Remove every WP-Cron event whose hook starts with `perflocale_` (unexpected args, add-on hooks, an engine switch).
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
	 * The cron array through _get_cron_array() (private, stable since WP 3.5), else get_option( 'cron' ), the same shape.
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

		// Drop the `version` key newer WordPress stores.
		unset( $cron['version'] );

		return $cron;
	}

	/**
	 * Preserve-mode cleanup: roles and caps go (they live in roles and users), all plugin data stays for a reinstall.
	 *
	 * @return void
	 */
	private static function preserve_purge(): void {
		// `true`: may skip blogs that never had our caps; the full purge cannot (its sweep already deleted the flag).
		self::strip_role_and_caps( true );
	}

	/**
	 * Run each add-on's manifest uninstaller, one add-on's failure isolated from the rest.
	 *
	 * @param bool $single_site_teardown True when one subsite is being
	 *     deleted: each addon purge then leaves its network-global targets
	 *     and the network-wide cache flushes alone.
	 * @param bool $network_sweep True on the network uninstall: the network-global
	 *     targets are noted in self::$addon_shared for the end of the walk.
	 * @return array{ids: array<int, string>, tables: array<int, string>, options: array<int, string>, meta: array<string, array<int, string>>, unjournaled: bool}
	 *     What the addons the delete_data_on_uninstall filter kept declared
	 *     (none on a site deletion: the site goes); `unjournaled`: a deferred target list could not be stored.
	 */
	private static function purge_addons( bool $single_site_teardown = false, bool $network_sweep = false ): array {
		global $wpdb;

		$keep = [
			'ids'         => [],
			'tables'      => [],
			'options'     => [],
			'meta'        => [],
			'unjournaled' => false,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$manifest_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'perflocale_addon_manifest_' ) . '%'
			)
		);

		if ( ! class_exists( \PerfLocale\Addon\AddonUninstaller::class ) ) {
			return $keep;
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
				$shared  = [];
				$journal = $network_sweep ? static fn( array $targets ): bool => self::journal_addon_shared( $addon_id, $targets ) : null;
				$result  = \PerfLocale\Addon\AddonUninstaller::purge(
					$addon_id,
					$live_addon instanceof \PerfLocale\Addon\AddonInterface ? $live_addon : null,
					$single_site_teardown,
					$network_sweep,
					$shared,
					$journal
				);

				// The blog keeps its settings and this manifest, so the next pass purges it again with the manifest.
				if ( in_array( \PerfLocale\Addon\AddonUninstaller::JOURNAL_ERROR, $result->errors, true ) ) {
					$keep['unjournaled'] = true;
					$keep['options'][]   = 'perflocale_addon_manifest_' . $addon_id;
				}

				if ( [] !== $shared ) {
					self::note_addon_shared( $addon_id, $shared );
				}

				if ( ! $single_site_teardown && in_array( 'skipped_by_filter', $result->errors, true ) ) {
					$keep['ids'][]   = $addon_id;
					$keep['tables']  = array_merge( $keep['tables'], $result->plan->tables );
					$keep['options'] = array_merge( $keep['options'], $result->plan->options, $result->plan->site_options, [ 'perflocale_addon_manifest_' . $addon_id ] );

					foreach ( $result->plan->meta as $type => $keys ) {
						$keep['meta'][ $type ] = array_merge( $keep['meta'][ $type ] ?? [], $keys );
					}
				}

				// Reported here: its AddonMigrationErrors record is deleted below with the options. Reporting only: failing the blog
				// would skip its whole purge.
				self::report_addon_purge_problems( $addon_id, $result );
			} catch ( \Throwable $e ) {
				// Unconditional, as every diagnostic on this path: the files are about to go.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
				error_log( '[PerfLocale] addon uninstall ' . $addon_id . ' failed: ' . $e->getMessage() );
			}
		}

		if ( class_exists( \PerfLocale\Addon\AddonMigrationErrors::class ) ) {
			delete_option( \PerfLocale\Addon\AddonMigrationErrors::OPTION );
		}

		return $keep;
	}

	/**
	 * Merge one add-on purge's deferred network-global targets into the walk's list ("<network id>:<name>" for options).
	 *
	 * @param string               $addon_id Addon identifier.
	 * @param array<string, mixed> $shared   `site_options`, `user`, `kept` from AddonUninstaller::purge().
	 * @return void
	 */
	private static function note_addon_shared( string $addon_id, array $shared ): void {
		$entry = self::$addon_shared[ $addon_id ] ?? [
			'site_options' => [],
			'user'         => [],
			'kept'         => false,
		];

		$site    = get_site();
		$network = $site instanceof \WP_Site ? (int) $site->network_id : self::marker_network();
		$names   = array_filter( (array) ( $shared['site_options'] ?? [] ), 'is_string' );
		$add     = [
			'site_options' => array_map( static fn( string $name ): string => $network . ':' . $name, $names ),
			'user'         => array_filter( (array) ( $shared['user'] ?? [] ), 'is_string' ),
		];

		foreach ( [ 'site_options', 'user' ] as $kind ) {
			$entry[ $kind ] = array_values( array_unique( array_merge( $entry[ $kind ], $add[ $kind ] ) ) );
		}

		$entry['kept'] = $entry['kept'] || ! empty( $shared['kept'] );

		self::$addon_shared[ $addon_id ] = $entry;
	}

	/**
	 * Note one addon's deferred targets and store the walk's list in the resume marker, before their manifest goes.
	 *
	 * @param string       $addon_id Addon identifier.
	 * @param array<mixed> $shared   `site_options`, `user`, `kept` from AddonUninstaller::purge().
	 * @return bool False when the marker is missing or the write failed.
	 */
	private static function journal_addon_shared( string $addon_id, array $shared ): bool {
		$targets = [
			'site_options' => (array) ( $shared['site_options'] ?? [] ),
			'user'         => (array) ( $shared['user'] ?? [] ),
			'kept'         => ! empty( $shared['kept'] ),
		];

		if ( [] === $targets['site_options'] && [] === $targets['user'] ) {
			return true;
		}

		self::note_addon_shared( $addon_id, $targets );

		// Written before the walk's first deletion; read back so its other fields stay as they are.
		$marker = get_network_option( self::marker_network(), self::RESUME_OPTION, null );

		if ( ! is_array( $marker ) ) {
			return false;
		}

		if ( ( $marker['addon_shared'] ?? null ) === self::$addon_shared ) {
			return true;
		}

		$marker['addon_shared'] = self::$addon_shared;

		return update_network_option( self::marker_network(), self::RESUME_OPTION, $marker );
	}

	/**
	 * Log what an add-on purge could not do, unconditionally (the plugin's own error record is deleted moments later);
	 * an operator filter's skip is no problem and is not reported.
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
	 * Delete every wp_options row matching a LIKE pattern through delete_option() / delete_transient(), so the caches
	 * stay coherent.
	 *
	 * @param string             $like_pattern e.g. 'perflocale_addon_manifest_%'.
	 * @param array<int, string> $keep         Option names to leave (an addon that kept its data).
	 * @return void
	 */
	private static function delete_options_like( string $like_pattern, array $keep = [] ): void {
		global $wpdb;

		// `_` is a LIKE wildcard (`perflocale_lock_%` would also match `perflocaleXlockY`): escape each piece between the `%`
		// signs, as esc_like() on the whole would escape the `%` too.
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

			if ( in_array( $name, $keep, true ) ) {
				continue;
			}

			if ( str_starts_with( $name, '_transient_timeout_' ) || str_starts_with( $name, '_site_transient_timeout_' ) ) {
				// A timeout row whose value row is gone has nothing to take it: delete the exact row (delete_transient() on its
				// name would only touch a cache key that never existed).
				delete_option( $name );
				continue;
			}

			// The API may not reach this row (a persistent cache deletes only the key; multisite targets wp_sitemeta): delete it
			// by name, or it outlives its timeout row.
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
	 * Remove the perflocale_translator role and strip PerfLocale's caps from the cap roles (perflocale/roles/cap_roles
	 * narrows them).
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
		// A blog that never had our caps (no flag, no role): nothing to strip, otherwise every blog of a large network pays
		// role writes and a usermeta scan. Preserve path only (the full path already deleted the flag).
		if ( $allow_skip
			&& false === get_option( 'perflocale_caps_version', false )
			&& null === get_role( \PerfLocale\Admin\TranslatorRole::ROLE_SLUG )
		) {
			return;
		}

		remove_role( \PerfLocale\Admin\TranslatorRole::ROLE_SLUG );

		// Oxygen's per-role access option (`oxygen_vsb_access_role_<role>`, never deleted by Oxygen) goes with a role a
		// full-data uninstall removed for good; deactivation and preserve keep both.
		if ( $drop_role_assignment ) {
			delete_option( 'oxygen_vsb_access_role_perflocale_translator' );
		}

		$caps = self::canonical_caps();

		// Full-data uninstall: every role (caps may have been granted to authors, shop managers). Elsewhere editor and
		// administrator only, which install_caps() re-grants, or custom roles lose caps on every reactivation.
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
				// remove_cap() rewrites the whole roles array even for a cap the role lacks: skip those.
				if ( ! isset( $role->capabilities[ $cap ] ) ) {
					continue;
				}

				$role->remove_cap( $cap );
			}
		}

		// Orphaned direct grants on users (shared with TranslatorRole::remove_roles() on deactivation).
		self::sweep_orphan_user_caps( $drop_role_assignment );

		// The install flag goes with the caps, or install_caps() would skip them on a reinstall; a no-op on the full path.
		delete_option( 'perflocale_caps_version' );
	}

	/**
	 * Strip every `perflocale_*` key from users' `wp_capabilities` meta, shared by strip_role_and_caps() and
	 * TranslatorRole::remove_roles(); the call site has switched to the right blog.
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

		// Capabilities only, never the `perflocale_translator` role key (it matches the prefix): stripping it would lock
		// translator-only users out on a deactivation. Except a full-data uninstall, so a reinstall does not hand the role back.
		$strip = array_merge(
			self::canonical_caps(),
			// Retired capabilities older builds granted directly.
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
	 * Caps to strip: TranslatorRole::CAPABILITIES, or a static list when that class cannot load early in uninstall.
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
	 * Remove the plugin's uploads tree (translations, temp, exports, and the uploads/perflocale/ parent).
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
	 * Remove a directory tree, never outside uploads/.
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

		// Raw rmdir() as the credential-free deletes before it: WP_Filesystem may need credentials during uninstall.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Credential-free cleanup; see note above.
		@rmdir( $dir_real );
	}
}
