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
	 * The owned names the running purge applies (owned_names()); null: this version's own.
	 *
	 * @var array<string, list<string>>|null
	 */
	private static ?array $owned = null;

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

	/** The owned-names manifest: every name an installed version's uninstall deletes (blog option; network option too). */
	public const OWNED_OPTION = 'perflocale_owned_names';

	/** Read-union-write rounds of one manifest write before it gives up (each lost round means another writer won). */
	private const OWNED_TRIES = 5;

	/** Rows per page of delete_network_likes(). */
	private const NETWORK_LIKE_BATCH = 500;

	/** The manifest's lists: option names, option LIKE patterns, table short names, network options, user transient and meta prefixes. */
	private const OWNED_KEYS = [ 'options', 'likes', 'tables', 'network', 'user_transients', 'meta_post', 'meta_term', 'meta_user', 'meta_order', 'keys_post', 'keys_term', 'keys_user' ];

	/** Meta lists that never hold a namespace-wide prefix: post, term and user meta go by enumerated keys and patterns. */
	private const NARROW_META = [ 'meta_post', 'meta_term', 'meta_user' ];

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
	 * Prefix of the index rows of Content ReSync's MT checkpoints (Resync\UnitTranslator::KEEP_INDEX; uninstall.php
	 * loads no autoloader).
	 */
	public const RESYNC_KEPT_INDEX = 'perflocale_resync_kept_';

	/** Prefix of a checkpoint's transient key (PairRunner::units_key(), Filler::units_key()). */
	private const RESYNC_KEPT_KEY_PREFIX = 'perflocale_resync_units_';

	/** The most chunks removed for one index row: a bound against a damaged row. */
	private const RESYNC_KEPT_MAX_CHUNKS = 10000;

	/**
	 * Object-cache entries with a fixed name, by group, that a full purge deletes by name: a group flush can be a no-op
	 * and a generation bump misses keys written without CacheManager::l2_key(). CacheWriteSitesTest keeps it complete.
	 *
	 * @var array<string, list<string>>
	 */
	public const CACHE_KEYS_BY_NAME = [
		'perflocale'         => [ 'tables_exist' ],
		'perflocale_trans'   => [ 'has_any_groups', 'has_any_slugs' ],
		'perflocale_strings' => [ 'string_map_refused' ],
		'transient'          => [ 'perflocale_vcopy_epoch' ],
	];

	/** Transient prefix of the stored variation maps (InventorySync::STORED_MAP_PREFIX) + a product id. */
	public const VARIATION_MAP_PREFIX = 'perflocale_vsig_';

	/** Product ids read per query when the stored variation maps are deleted. */
	private const VARIATION_MAP_BATCH = 500;

	/** Name prefixes of the namespace sweep: rows a newer version left that this registry does not list. */
	private const NAMESPACE_PREFIXES = [ 'perflocale_', '_perflocale_', '_transient_perflocale_', '_transient_timeout_perflocale_', '_site_transient_perflocale_', '_site_transient_timeout_perflocale_' ];

	/** Bare name prefixes of PerfLocale Pro and the Visual Editor (the gate's foreign_namespaces): the free plugin deletes none of their rows, tables, meta, theme mods or events. */
	public const FOREIGN_PREFIXES = [ 'perflocale_pro_', 'perflocale_ve_', 'perflocale_visual_editor' ];


	/**
	 * Purge every blog of the installation by its own `delete_data_on_uninstall` (uninstall.php only; WP-CLI has no
	 * deadline). Not a background job (the files go right after) and not scoped to one network (every network loses the
	 * code); an interrupted sweep keeps a marker below the first failure, and every step is idempotent.
	 *
	 * @param float|null $deadline Wall-clock time (microtime(true)) after
	 *     which the loop stops between blogs, or null for no limit.
	 * @return array{complete: bool, out_of_time: bool, list_error: bool, meta_error: bool, namespace_error: bool, entered: int, purged: int, failed: int, skipped: int, remaining: int, last_site_id: int, total: int}
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
				// the sweep; the finally unwinds every switch, a listener's own included.
				$depth = self::switch_depth();

				try {
					switch_to_blog( $site_id );

					$mode = self::blog_uninstall_mode();

					// A failed read never grants: the blog is not purged and the pass stays incomplete, so nothing network-wide goes.
					if ( 'failed' === $mode ) {
						++$failed;
						$had_failure = true;

						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
						error_log( '[PerfLocale] uninstall could not read the settings of site ' . $site_id . ': ' . $wpdb->last_error );
						continue;
					}

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
					self::unwind_to( $depth );
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

		$deleting = $complete && $any_delete && ! $any_preserve;

		if ( $deleting ) {
			self::$owned = self::owned_names( true );
		}

		if ( $deleting && ( null === self::$owned || ! self::purge_network_user_data() ) ) {
			$meta_error = true;
			$complete   = false;

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path; there is no UI left to surface it in.
			error_log( '[PerfLocale] uninstall could not delete PerfLocale user preferences or add-on network targets' . ( '' !== $wpdb->last_error ? ': ' . $wpdb->last_error : '' ) );
		}

		$namespace_error = false;

		// The registry's network options on every network, and under the same rule as the user meta the manifest's, then the
		// network manifest itself, before the marker goes: a failure keeps the pass to retry.
		$scope_failed = $complete ? self::purge_network_scope( $any_delete && ! $any_preserve ) : [];

		if ( [] !== $scope_failed ) {
			$namespace_error = true;
			$complete        = false;

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
			error_log( '[PerfLocale] uninstall could not read or delete the PerfLocale network options: ' . implode( ', ', $scope_failed ) );
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
			'complete'        => $complete,
			// Why a pass is incomplete: each needs different advice.
			'out_of_time'     => $out_of_time,
			'list_error'      => $list_error,
			'meta_error'      => $meta_error,
			'namespace_error' => $namespace_error,
			'entered'         => $entered,
			'purged'          => $purged,
			'failed'          => $failed,
			'skipped'         => $skipped,
			// Blogs left: those this pass did not reach plus those that failed.
			'remaining'       => max( 0, $total - $skipped - $purged ),
			'last_site_id'    => $high_water,
			'total'           => $total,
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
	 * Remove the registry's network options from every network after a completed sweep; with $owned also the manifest's
	 * and, once all is gone, the network manifest. The main network's resume marker is the caller's.
	 *
	 * @param bool $owned Data deleted on every blog: the manifest's network options and the manifest go too.
	 * @return list<string> What failed: `the network list`, `the owned names`, or `<network id>:<name>`; empty when all is gone.
	 */
	private static function purge_network_scope( bool $owned ): array {
		$networks = self::network_ids();
		$failed   = null === $networks ? [ 'the network list' ] : [];
		$names    = DataRegistry::network_options();

		if ( $owned ) {
			if ( null === self::$owned ) {
				return [ 'the owned names' ];
			}

			$names = array_merge( $names, self::$owned['network'] );
		}

		// The manifest goes last, below.
		$names = array_values( array_unique( array_diff( $names, [ self::OWNED_OPTION ] ) ) );

		foreach ( $networks ?? [ self::marker_network() ] as $network_id ) {
			if ( $owned ) {
				$failed = array_merge( $failed, self::delete_network_likes( $network_id ) );
			}

			foreach ( $names as $option ) {
				if ( ( self::RESUME_OPTION !== $option || self::marker_network() !== $network_id )
					&& ! delete_network_option( $network_id, $option ) && ! self::network_option_gone( $network_id, $option ) ) {
					$failed[] = $network_id . ':' . $option;
				}
			}
		}

		// Last: a retry needs the manifest.
		if ( $owned && [] === $failed && ! delete_network_option( self::marker_network(), self::OWNED_OPTION ) && ! self::network_option_gone( self::marker_network(), self::OWNED_OPTION ) ) {
			$failed[] = self::marker_network() . ':' . self::OWNED_OPTION;
		}

		return $failed;
	}

	/**
	 * Delete one network's wp_sitemeta rows that match an owned option pattern (site transients on multisite), Pro's and
	 * registered add-ons' aside, in keyset pages over the key index.
	 *
	 * @param int $network_id Network id.
	 * @return list<string> What failed, as purge_network_scope() reports it.
	 */
	private static function delete_network_likes( int $network_id ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$protected = self::protected_prefixes( array_map( 'strval', array_keys( self::$addon_shared ) ) );
		$failed    = [];

		// Unread add-on list: an add-on's or Pro's site options cannot be told apart, so none goes; the pass is retried.
		if ( null === $protected ) {
			return [ $network_id . ':the add-on list' ];
		}

		foreach ( self::owned( 'likes' ) as $pattern ) {
			$after = '';

			do {
				$before = $after;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall path; a range scan on the key index.
				$names = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE site_id = %d AND meta_key LIKE %s AND meta_key > %s ORDER BY meta_key LIMIT %d', $wpdb->sitemeta, $network_id, self::option_like( $pattern ), $after, self::NETWORK_LIKE_BATCH ) );

				if ( '' !== $wpdb->last_error ) {
					return array_merge( $failed, [ $network_id . ':' . $pattern ] );
				}

				$names = array_map( static fn( $n ): string => is_scalar( $n ) ? (string) $n : '', (array) $names );
				$full  = count( $names ) === self::NETWORK_LIKE_BATCH;

				foreach ( $names as $name ) {
					$after = '' !== $name ? $name : $after;

					if ( self::RESUME_OPTION === $name || self::OWNED_OPTION === $name || self::in_namespaces( $name, $protected ) ) {
						continue;
					}

					if ( ! delete_network_option( $network_id, $name ) && ! self::network_option_gone( $network_id, $name ) ) {
						$failed[] = $network_id . ':' . $name;
					}
				}
			} while ( $full && $after !== $before );
		}

		return $failed;
	}

	/**
	 * After delete_option() said false (no row, or a refused DELETE): is this blog's row really gone? A failed read is no.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function option_gone( string $name ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one uncached check on the uninstall path.
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $name ) );

		return null === $row && '' === $wpdb->last_error;
	}

	/**
	 * After delete_network_option() said false (no row, or a refused DELETE): is the row really gone? A failed read is no.
	 *
	 * @param int    $network_id Network id.
	 * @param string $name       Option name.
	 * @return bool
	 */
	public static function network_option_gone( int $network_id, string $name ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one uncached check on the uninstall path.
		$row = is_multisite()
			? $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE meta_key = %s AND site_id = %d LIMIT 1', $wpdb->sitemeta, $name, $network_id ) )
			: $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $name ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $row && '' === $wpdb->last_error;
	}

	/**
	 * Merge this version's uninstall names into the owned-names manifest of the blog (and of the network on multisite), so
	 * an older version's uninstall deletes them too. A set union: running it twice changes nothing.
	 *
	 * @return bool False when a read or a write failed (the upgrade task retries).
	 */
	public static function record_owned_names(): bool {
		$now = self::owned_now();
		$ok  = self::merge_owned( null, $now );

		return is_multisite() ? self::merge_owned( self::marker_network(), $now ) && $ok : $ok;
	}

	/**
	 * This version's uninstall names, in the manifest's shape.
	 *
	 * @return array<string, list<string>>
	 */
	private static function owned_now(): array {
		$out = [
			'options'         => array_merge( DataRegistry::uninstall_options(), DataRegistry::uninstall_last_options() ),
			'likes'           => DataRegistry::uninstall_option_likes(),
			'tables'          => Schema::REQUIRED_TABLES,
			'network'         => DataRegistry::network_options(),
			'user_transients' => DataRegistry::uninstall_user_transient_prefixes(),
		];

		// Shop orders without HPOS keep their meta in postmeta: the order keys and patterns go with the post ones.
		foreach ( [ 'post', 'term', 'user' ] as $type ) {
			$out[ 'meta_' . $type ] = DataRegistry::uninstall_meta_likes( $type, false );
			$out[ 'keys_' . $type ] = DataRegistry::uninstall_meta_keys( $type );
		}

		$out['meta_post']  = array_merge( $out['meta_post'], DataRegistry::uninstall_meta_likes( 'order', false ) );
		$out['keys_post']  = array_merge( $out['keys_post'], DataRegistry::uninstall_meta_keys( 'order' ) );
		$out['meta_order'] = DataRegistry::uninstall_meta_likes( 'order' );

		return self::owned_shape( $out );
	}

	/**
	 * One list of the running purge's owned names; this version's own outside a purge.
	 *
	 * @param string $key An OWNED_KEYS entry.
	 * @return list<string>
	 */
	private static function owned( string $key ): array {
		return ( self::$owned ?? self::owned_now() )[ $key ] ?? [];
	}

	/**
	 * This version's names with one stored manifest: the blog's for blog data, the network's for network data (one blog's
	 * record never proves ownership on another).
	 *
	 * @param bool $network The network copy (wp_sitemeta, network-global user meta).
	 * @return array<string, list<string>>|null Null when the manifest read failed.
	 */
	private static function owned_names( bool $network = false ): ?array {
		$stored = self::stored_owned( $network ? self::marker_network() : null );

		return null === $stored ? null : self::owned_shape( array_merge_recursive( self::owned_now(), $stored ) );
	}

	/**
	 * A stored manifest by a direct read: the blog's (null) or a network's.
	 *
	 * @param int|null $network_id Network id; null: the current blog.
	 * @return array<string, list<string>>|null Empty lists when absent; null when the read failed.
	 */
	private static function stored_owned( ?int $network_id ): ?array {
		$read = self::read_owned( $network_id );

		return null === $read ? null : $read['value'];
	}

	/**
	 * The stored manifest and what a conditional write needs: the raw value and the row id (wp_sitemeta has no unique key,
	 * so a network copy written twice at once is read as the union of its rows).
	 *
	 * @param int|null $network_id Network id; null: the current blog.
	 * @return array{raw: string|null, id: int, value: array<string, list<string>>}|null Null when the read failed.
	 */
	private static function read_owned( ?int $network_id ): ?array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a failed read must not look like an absent row.
		$rows = null === $network_id
			? $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, self::OWNED_OPTION ), ARRAY_N )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, meta_value FROM %i WHERE meta_key = %s AND site_id = %d ORDER BY meta_id', $wpdb->sitemeta, self::OWNED_OPTION, $network_id ), ARRAY_N );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' !== $wpdb->last_error || null === $rows ) {
			return null;
		}

		$values = [];

		foreach ( $rows as $row ) {
			$value = is_string( $row[1] ?? null ) ? maybe_unserialize( $row[1] ) : null;

			// A row that does not decode to a manifest is an unreadable one, never an empty one: no write over it.
			if ( ! self::is_manifest( $value ) ) {
				return null;
			}

			$values[] = self::owned_shape( $value );
		}

		$first = $rows[0] ?? [];

		return [
			'raw'   => is_string( $first[1] ?? null ) ? $first[1] : null,
			'id'    => is_numeric( $first[0] ?? null ) ? (int) $first[0] : 0,
			'value' => self::owned_shape( [] === $values ? [] : array_merge_recursive( ...$values ) ),
		];
	}

	/**
	 * Whether a decoded value has the manifest's shape: an array whose known keys hold arrays (a newer version may add keys).
	 *
	 * @param mixed $value Decoded row value.
	 * @return bool
	 */
	private static function is_manifest( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( self::OWNED_KEYS as $key ) {
			if ( array_key_exists( $key, $value ) && ! is_array( $value[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every OWNED_KEYS list, sorted and unique; a name outside PerfLocale's namespace (or a table name not [a-z0-9_]) is dropped.
	 *
	 * @param mixed $value Manifest as read.
	 * @return array<string, list<string>>
	 */
	private static function owned_shape( $value ): array {
		$out = [];

		foreach ( self::OWNED_KEYS as $key ) {
			$list = is_array( $value ) && is_array( $value[ $key ] ?? null ) ? $value[ $key ] : [];
			$list = array_filter(
				$list,
				static fn( $n ): bool => is_string( $n ) && ( 'tables' === $key
					? 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $n )
					: 1 === preg_match( '/(^|_)perflocale_/i', $n ) )
			);
			if ( in_array( $key, self::NARROW_META, true ) ) {
				$list = array_diff( $list, self::NAMESPACE_PREFIXES );
			}

			$list = array_values( array_unique( $list ) );
			sort( $list, SORT_STRING );
			$out[ $key ] = $list;
		}

		return $out;
	}

	/**
	 * Union $now into one stored manifest by a write conditional on the value read (compare-and-set): a writer in between
	 * makes it match nothing and the union is read and written again. A rerun after a lost reply matches nothing too, and
	 * the next read finds the union stored.
	 *
	 * @param int|null                    $network_id Network id; null: the current blog.
	 * @param array<string, list<string>> $now        owned_now().
	 * @return bool False when a read or write failed, or the union was not stored after OWNED_TRIES rounds.
	 */
	private static function merge_owned( ?int $network_id, array $now ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		for ( $try = 0; $try < self::OWNED_TRIES; $try++ ) {
			$read = self::read_owned( $network_id );

			// A failed read never writes: the union would lose a newer version's names.
			if ( null === $read ) {
				return false;
			}

			$merged = self::owned_shape( array_merge_recursive( $read['value'], $now ) );

			if ( null !== $read['raw'] && $merged === $read['value'] ) {
				return true;
			}

			$value = maybe_serialize( $merged );

			if ( null === $network_id ) {
				$sql = null === $read['raw']
					? $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::OWNED_OPTION, $value, 'off' )
					: $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_id = %d AND option_value = %s', $wpdb->options, $value, $read['id'], $read['raw'] );
			} else {
				$sql = null === $read['raw']
					? $wpdb->prepare( 'INSERT INTO %i (site_id, meta_key, meta_value) SELECT %d, %s, %s FROM (SELECT 1) AS x WHERE NOT EXISTS (SELECT 1 FROM %i WHERE site_id = %d AND meta_key = %s)', $wpdb->sitemeta, $network_id, self::OWNED_OPTION, $value, $wpdb->sitemeta, $network_id, self::OWNED_OPTION )
					: $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND meta_value = %s', $wpdb->sitemeta, $value, $read['id'], $read['raw'] );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- conditional writes the option API cannot express (prepared above); caches cleared below.
			$wrote = null === $sql ? false : $wpdb->query( $sql );

			if ( null === $network_id ) {
				wp_cache_delete( self::OWNED_OPTION, 'options' );
				\PerfLocale\Database\NotOptions::unlist( [ self::OWNED_OPTION ] );
			} else {
				wp_cache_delete( $network_id . ':' . self::OWNED_OPTION, 'site-options' );
			}

			if ( false === $wrote ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Every network id of the installation.
	 *
	 * @return int[]|null Null when none was read: an installation has its main network, so an empty list is a failed read.
	 */
	private static function network_ids(): ?array {
		if ( ! function_exists( 'get_networks' ) ) {
			return [ self::marker_network() ];
		}

		$ids = array_map(
			'intval',
			(array) get_networks(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			)
		);

		return [] === $ids ? null : $ids;
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

		$errors = \PerfLocale\Addon\AddonUninstaller::purge_shared( $site, $user );

		if ( [] !== $errors ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
			error_log( '[PerfLocale] uninstall could not delete add-on network targets: ' . implode( '; ', $errors ) );
		}

		return [] === $errors;
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
	 * The current blog's uninstall choice from the stored row: 'delete', 'preserve', 'none' without a settings row (never
	 * activated; neutral in the network's one user-meta decision), or 'failed' when the read failed (never 'none').
	 *
	 * @return string 'delete' | 'preserve' | 'none' | 'failed'
	 */
	private static function blog_uninstall_mode(): string {
		$row = OptionRead::of( self::MODE_OPTION );

		if ( $row->failed ) {
			return 'failed';
		}

		if ( null === $row->bytes ) {
			return 'none';
		}

		// Present but undecodable counts as keep, never as delete.
		$settings = maybe_unserialize( $row->bytes );

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
	 * Site deletion (wp_uninitialize_site): purge the deleted blog's data. Docs: docs-ai/11-code-rationale.md, "perflocale.php: site deletion".
	 *
	 * @param \WP_Site $old_site The site being deleted.
	 * @return void
	 */
	public static function on_site_deleted( \WP_Site $old_site ): void {
		// Core pushes the switch stack before a `switch_blog` listener can throw: restore whenever it grew.
		$depth = self::switch_depth();

		try {
			switch_to_blog( (int) $old_site->blog_id );

			// Single-site teardown: network-global stores (wp_usermeta) stay for the other sites.
			self::purge_current_site( true, true );
		} finally {
			self::unwind_to( $depth );
		}
	}

	/**
	 * The depth of WordPress's blog switch stack.
	 *
	 * @return int
	 */
	private static function switch_depth(): int {
		return count( (array) ( $GLOBALS['_wp_switched_stack'] ?? [] ) );
	}

	/**
	 * Restore until the switch stack is back at $depth: a listener may have switched again before it threw.
	 *
	 * @param int $depth switch_depth() before the switch.
	 * @throws \Throwable The first exception a restore threw, once the stack is back.
	 * @return void
	 */
	private static function unwind_to( int $depth ): void {
		$first = null;

		// Core pops the stack before `switch_blog` fires on restore: a throwing listener never stops the unwinding.
		for ( $open = self::switch_depth() - $depth; $open > 0 && self::switch_depth() > $depth; --$open ) {
			try {
				restore_current_blog();
			} catch ( \Throwable $e ) {
				$first = $first ?? $e;
			}
		}

		if ( null !== $first ) {
			throw $first;
		}
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
	 *     job in the same mode. In preserve mode false only when a scheduled action could not be cancelled.
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
		if ( ! self::clear_all_scheduled_events() ) {
			$clean = false;
		}

		// The delete path also removes the blog's Action Scheduler history of the group (kept for good otherwise), after the
		// cancel above so its log lines go too.
		if ( $delete_data && ! self::purge_action_scheduler_rows() ) {
			$clean = false;
		}

		// Last, and only when everything above succeeded: the settings row holds the delete-or-preserve choice, so an
		// interrupted purge resumes in the same mode. Its own delete is not checked (false also for a gone row).
		// The manifest only once the settings row is confirmed gone: a retry resumes in delete mode and needs both.
		if ( $delete_data && $clean ) {
			$clean = ( delete_option( self::MODE_OPTION ) || self::option_gone( self::MODE_OPTION ) )
				&& ( delete_option( self::OWNED_OPTION ) || self::option_gone( self::OWNED_OPTION ) );
		}

		// No settings row but a manifest: a delete pass whose last DELETE failed. The manifest is this plugin's own row.
		$mode = $delete_data ? '' : self::blog_uninstall_mode();
		if ( 'failed' === $mode || ( 'none' === $mode && ! delete_option( self::OWNED_OPTION ) && ! self::option_gone( self::OWNED_OPTION ) ) ) {
			$clean = false;
		}

		self::$owned = null;

		// Not logged when the site itself is deleted: core drops its tables right after; a leftover perflocale_* table is
		// logged in full_purge().
		if ( ! $clean && ! $single_site_teardown ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
			error_log( '[PerfLocale] uninstall could not delete all of site ' . get_current_blog_id() . "'s PerfLocale tables, meta, scheduled actions or their history; its settings are kept so deleting the plugin again finishes the job." );
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

		// Read before the add-on purge forgets the manifests. Unread: nothing goes (as for unread manifests below), so the
		// retry still knows every add-on; a deleted site loses only the namespace sweep (its options table goes anyway).
		$protected = self::protected_prefixes();

		if ( null === $protected ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
			error_log( '[PerfLocale] uninstall could not read the add-on list of site ' . get_current_blog_id() . ', so options and transients PerfLocale add-ons or Pro may own are kept; deleting the plugin again finishes the job.' );

			if ( ! $single_site_teardown ) {
				return false;
			}

			$clean = false;
		}

		// Deleting needs positive proof: only what this version or an earlier-installed one recorded as its own.
		self::$owned = self::owned_names();

		if ( null === self::$owned ) {
			$clean = false;
		}

		// 1. Add-on manifests first (they work without the add-on on disk); what a kept add-on declared stays below.
		$keep = self::purge_addons( $single_site_teardown, $network_sweep );

		if ( $keep['unjournaled'] ) {
			$clean = false;
		}

		// Unread manifests: a kept add-on's tables and meta cannot be told apart, so nothing goes; the next pass retries.
		if ( $keep['manifests'] && ! $single_site_teardown ) {
			return false;
		}

		// 2. The owned tables.
		$tables = array_map( static fn( string $short ): string => $wpdb->prefix . 'perflocale_' . $short, self::owned( 'tables' ) );
		SchemaWrites::drop_tables( $keep['tables'], self::owned( 'tables' ) );

		// drop_tables() reports nothing and a DROP can fail (lock wait, metadata lock): ask what is left.
		// A failed SHOW also returns no rows: last_error decides.
		if ( [] !== self::tables_left( $tables, $keep['tables'] ) || '' !== $wpdb->last_error ) {
			$clean = false;

			if ( $single_site_teardown ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the site-deletion path.
				error_log( '[PerfLocale] site ' . get_current_blog_id() . ' is being deleted, but its ' . $wpdb->prefix . 'perflocale_* tables could not all be dropped (or checked); WordPress does not remove them, so drop any that remain by hand.' );
			}
		}

		// 3. The registry's option names and LIKE patterns (transient rows included). perflocale_settings is held back to the
		// end (purge_current_site()): a purge dying part-way must resume in delete mode.
		foreach ( self::owned( 'options' ) as $opt ) {
			if ( self::MODE_OPTION === $opt || self::OWNED_OPTION === $opt ) {
				continue;
			}

			// An unfinished add-on purge keeps every version: the retry's sibling claims read them.
			if ( $keep['unjournaled'] && \PerfLocale\Addon\AddonSchemaManager::VERSIONS_OPTION === $opt ) {
				continue;
			}

			// A kept add-on keeps its schema version, so a reinstall resumes it.
			if ( [] !== $keep['ids'] && \PerfLocale\Addon\AddonSchemaManager::VERSIONS_OPTION === $opt ) {
				$read = OptionRead::of( $opt );

				// Unread is not "no version": the row stays whole for the retry.
				if ( $read->failed ) {
					$clean = false;
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic on the uninstall path.
					error_log( '[PerfLocale] uninstall could not read the add-on schema versions of site ' . get_current_blog_id() . ', so they are kept; deleting the plugin again finishes the job.' );
					continue;
				}

				$versions = null !== $read->bytes ? maybe_unserialize( $read->bytes ) : null;
				$versions = is_array( $versions ) ? array_intersect_key( $versions, array_flip( $keep['ids'] ) ) : [];

				if ( [] !== $versions ) {
					update_option( $opt, $versions, false );
					continue;
				}
			}

			delete_option( $opt );
		}

		// The MT checkpoints and publish notices are found through their index rows, so before the sweeps delete the rows.
		// Notices go by name only with a persistent cache (else the option sweep takes them); a deleted site's are
		// unreachable once step 4b drops its markers.
		$read_checkpoints = self::forget_resync_checkpoints();
		$read_notices     = $single_site_teardown || ! wp_using_ext_object_cache() || self::forget_mt_notices();

		// Before step 7 strips the caps the user query selects by.
		self::forget_user_transients();

		if ( ! $read_checkpoints || ! $read_notices ) {
			$clean = false;
		}

		if ( null !== $protected ) {
			foreach ( self::owned( 'likes' ) as $pattern ) {
				self::delete_options_like( $pattern, $keep['options'], $protected );
			}
		}

		// 3b. PerfLocale's entries inside WordPress's own rows (theme mods of every theme, placed switcher widgets); a
		// deleted subsite's options table goes with it.
		if ( ! $single_site_teardown ) {
			self::purge_theme_mods_and_widgets();
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

		// 5. Term meta: the owned keys and patterns (menu language tags and pairings).
		if ( ! self::delete_owned_meta( 'term', $wpdb->termmeta, 'meta_id', 'term_id', 'term_meta', $keep['meta']['term'] ?? [] ) ) {
			$clean = false;
		}

		// 6. Post meta: the owned keys and patterns, each esc_like()'d so underscores stay literal.
		if ( ! self::delete_owned_meta( 'post', $wpdb->postmeta, 'meta_id', 'post_id', 'post_meta', $keep['meta']['post'] ?? [] ) ) {
			$clean = false;
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

		// 10. The object cache: every plugin group per blog by generation; the network-wide group flush only on a single-site
		// uninstall (one subsite's deletion must not reach every site; the network sweep flushes once per pass).
		self::flush_cache_groups( ! $single_site_teardown && ! $network_sweep, ! $single_site_teardown );

		// A failed delete or a failed read of a pattern's names leaves rows: checked after the flush, which rewrites and
		// deletes the generation counters, so the settings row stays for a retry.
		if ( null !== $protected && ! self::options_gone( $keep['options'], [] !== $keep['ids'] || $keep['unjournaled'], $protected ) ) {
			$clean = false;
		}

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
	 * @param bool $variation_maps Also delete the stored variation maps (false for a deleted subsite: never read again).
	 * @return void
	 */
	private static function flush_cache_groups( bool $shared = true, bool $variation_maps = true ): void {
		// Fixed-name entries first, on every backend, so none depends on the group flush.
		self::delete_cache_entries_by_name( $variation_maps );

		if ( ! class_exists( \PerfLocale\Cache\CacheManager::class ) ) {
			return;
		}

		// A generation bump works on every backend, also where wp_cache_flush_group() is missing or a no-op.
		foreach ( \PerfLocale\Cache\CacheManager::GROUPS as $group ) {
			\PerfLocale\Cache\CacheManager::bump_group_generation( $group );
		}

		// The plugin groups' keys of every blog too (an external cache and WP 6.1+ only; single-site uninstall only).
		if ( $shared ) {
			self::flush_shared_cache_groups();
		}

		// The bump above rewrites each `perflocale_cgen_<group>` option the option sweep deleted: delete them again, last.
		// A reinstall starts its generations at the clock, so it never reads the keys these gated.
		self::delete_options_like( 'perflocale_cgen_%' );
	}

	/**
	 * Delete the current blog's fixed-name cache entries: CACHE_KEYS_BY_NAME, and with a persistent object cache the
	 * registry's fixed-name transients and (when asked) every product's stored variation map.
	 *
	 * @param bool $variation_maps Also delete the stored variation maps.
	 * @return void
	 */
	private static function delete_cache_entries_by_name( bool $variation_maps = true ): void {
		foreach ( self::CACHE_KEYS_BY_NAME as $group => $keys ) {
			foreach ( $keys as $key ) {
				wp_cache_delete( $key, $group );
			}
		}

		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		foreach ( DataRegistry::uninstall_transient_names() as $transient ) {
			delete_transient( $transient );
		}

		if ( $variation_maps ) {
			self::delete_variation_maps();
		}
	}

	/**
	 * Delete every product's stored variation map from the persistent cache's `transient` group, by id range pages.
	 *
	 * A map of a product deleted while PerfLocale was inactive is missed; it expires within 30 days.
	 *
	 * @return void
	 */
	private static function delete_variation_maps(): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$after = 0;
		$full  = true;

		while ( $full ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One primary-key range read per batch, on the uninstall path only.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i WHERE post_type = %s AND ID > %d ORDER BY ID LIMIT %d',
					$wpdb->posts,
					'product',
					$after,
					self::VARIATION_MAP_BATCH
				)
			);

			$full  = count( $ids ) === self::VARIATION_MAP_BATCH;
			$names = [];

			foreach ( $ids as $id ) {
				if ( is_numeric( $id ) ) {
					$after   = (int) $id;
					$names[] = self::VARIATION_MAP_PREFIX . $after;
				}
			}

			if ( [] === $names ) {
				return;
			}

			wp_cache_delete_multiple( $names, 'transient' );
		}
	}

	/**
	 * Delete this blog's seven-day publish notices by name (an object cache keeps them past the option sweep): one per
	 * user waiting marker `<MT_NOTICE_META_PREFIX><blog>_<post>`, transient `perflocale_mt_create_notice_<user>_<post>`.
	 *
	 * @return bool False when reading the markers failed.
	 */
	private static function forget_mt_notices(): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$prefix = self::MT_NOTICE_META_PREFIX . get_current_blog_id() . '_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed range read of the markers, on the uninstall path only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, meta_key FROM %i WHERE meta_key LIKE %s',
				$wpdb->usermeta,
				$wpdb->esc_like( $prefix ) . '%'
			),
			ARRAY_N
		);

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$user = is_numeric( $row[0] ?? null ) ? (int) $row[0] : 0;
			$post = substr( is_string( $row[1] ?? null ) ? $row[1] : '', strlen( $prefix ) );

			if ( $user > 0 && 1 === preg_match( '/^[0-9]+$/', $post ) ) {
				delete_transient( 'perflocale_mt_create_notice_' . $user . '_' . $post );
			}
		}

		return '' === $wpdb->last_error;
	}

	/**
	 * With a persistent object cache, delete the registry's `{user}` transients by name for every user who can reach a
	 * writer of one (a plugin cap, edit_posts, manage_categories or manage_options) and every super admin.
	 *
	 * @return void
	 */
	private static function forget_user_transients(): void {
		$prefixes = wp_using_ext_object_cache() ? self::owned( 'user_transients' ) : [];

		if ( [] === $prefixes ) {
			return;
		}

		$ids  = [];
		$page = 1;

		foreach ( is_multisite() ? get_super_admins() : [] as $login ) {
			$user = get_user_by( 'login', (string) $login );

			if ( $user instanceof \WP_User ) {
				$ids[] = $user->ID;
			}
		}

		do {
			$got = get_users(
				[
					'capability__in' => array_merge( self::canonical_caps(), [ 'edit_posts', 'manage_categories', 'manage_options' ] ),
					'fields'         => 'ID',
					'orderby'        => 'ID',
					'number'         => self::VARIATION_MAP_BATCH,
					'paged'          => $page++,
					'count_total'    => false,
				]
			);

			$names = [];

			foreach ( array_merge( $ids, $got ) as $id ) {
				if ( ! is_numeric( $id ) ) {
					continue;
				}

				$id = (int) $id;

				foreach ( $prefixes as $prefix ) {
					$names[] = $prefix . $id;
				}
			}

			$ids = [];

			if ( [] !== $names ) {
				wp_cache_delete_multiple( array_values( array_unique( $names ) ), 'transient' );
			}

			$full = count( $got ) >= self::VARIATION_MAP_BATCH;
		} while ( $full );
	}

	/**
	 * Delete every chunk of Content ReSync's MT checkpoints by name, then their index rows: a persistent object cache keeps
	 * the transients where the option sweep cannot reach them. An index row holds `<expiry>|<chunks>|<key>`; the chunks
	 * are `<key>` and `<key>_<n>`.
	 *
	 * @return bool False when the read of the index rows failed.
	 */
	private static function forget_resync_checkpoints(): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed range read of the index rows, on the uninstall path only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( self::RESYNC_KEPT_INDEX ) . '%'
			),
			ARRAY_N
		);

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$name   = is_string( $row[0] ?? null ) ? $row[0] : '';
			$parts  = explode( '|', is_string( $row[1] ?? null ) ? $row[1] : '', 3 );
			$chunks = min( max( 0, (int) ( $parts[1] ?? 0 ) ), self::RESYNC_KEPT_MAX_CHUNKS );
			$key    = $parts[2] ?? '';

			if ( str_starts_with( $key, self::RESYNC_KEPT_KEY_PREFIX ) ) {
				for ( $chunk = 0; $chunk < $chunks; $chunk++ ) {
					delete_transient( 0 === $chunk ? $key : $key . '_' . $chunk );
				}
			}

			if ( '' !== $name ) {
				delete_option( $name );
			}
		}

		return '' === $wpdb->last_error;
	}

	/**
	 * Remove PerfLocale's entries from every `theme_mods_<theme>` row (the floating switcher's settings, and switcher
	 * widgets in the layout kept for a theme the site left) and from `sidebars_widgets`; rows without one are not written.
	 *
	 * @return void
	 */
	private static function purge_theme_mods_and_widgets(): void {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the uninstall path; finds the theme rows by name, then each goes through the options API.
		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_value LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'theme_mods_' ) . '%',
				'%' . $wpdb->esc_like( 'perflocale_' ) . '%'
			)
		);

		foreach ( $names as $name ) {
			$mods = is_string( $name ) ? get_option( $name ) : null;

			if ( ! is_string( $name ) || ! is_array( $mods ) ) {
				continue;
			}

			$kept = self::without_plugin_theme_mods( $mods );

			// The sidebar layout switch_theme() keeps in the mods of the theme the site left.
			$layout = $kept['sidebars_widgets'] ?? null;

			if ( is_array( $layout ) && isset( $layout['data'] ) && is_array( $layout['data'] ) ) {
				$layout['data']           = self::without_switcher_widgets( $layout['data'] );
				$kept['sidebars_widgets'] = $layout;
			}

			if ( $kept !== $mods ) {
				update_option( $name, $kept );
			}
		}

		$sidebars = get_option( 'sidebars_widgets' );

		if ( is_array( $sidebars ) ) {
			$kept = self::without_switcher_widgets( $sidebars );

			if ( $kept !== $sidebars ) {
				update_option( 'sidebars_widgets', $kept );
			}
		}
	}

	/**
	 * Theme mods without PerfLocale's (`perflocale_*` keys, Pro's kept).
	 *
	 * @param array<int|string, mixed> $mods One theme's mods.
	 * @return array<int|string, mixed>
	 */
	private static function without_plugin_theme_mods( array $mods ): array {
		return array_filter(
			$mods,
			static fn( $key ): bool => ! ( is_string( $key ) && str_starts_with( $key, 'perflocale_' ) && ! self::is_foreign( $key ) ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Sidebar assignments without Language Switcher widgets (`perflocale_switcher-<number>`).
	 *
	 * @param array<int|string, mixed> $sidebars The `sidebars_widgets` value.
	 * @return array<int|string, mixed>
	 */
	private static function without_switcher_widgets( array $sidebars ): array {
		foreach ( $sidebars as $sidebar => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}

			$other = array_filter(
				$widgets,
				static fn( $id ): bool => ! ( is_string( $id ) && 1 === preg_match( '/^perflocale_switcher-\d+$/', $id ) )
			);

			if ( count( $other ) !== count( $widgets ) ) {
				$sidebars[ $sidebar ] = array_values( $other );
			}
		}

		return $sidebars;
	}

	/**
	 * Flush PerfLocale's own object-cache groups (CacheManager::GROUPS) so a reinstall reads no ghost keys. Never the shared
	 * 'transient' groups: other plugins' keys live there; ours go by name or expire. On Redis a flush reaches every blog:
	 * once per uninstall pass, never per blog, never on a pass that deleted nothing, never for one deleted subsite.
	 *
	 * @return void
	 */
	private static function flush_shared_cache_groups(): void {
		if ( ! wp_using_ext_object_cache() || ! function_exists( 'wp_cache_flush_group' ) ) {
			return;
		}

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

		return self::delete_owned_meta( 'user', $wpdb->usermeta, 'umeta_id', 'user_id', 'user_meta', $keep );
	}

	/**
	 * Delete one meta table's owned keys (exact) and patterns (prefix): never the rest of the `_perflocale_` namespace.
	 *
	 * @param string             $type        post, term or user.
	 * @param string             $table       Meta table.
	 * @param string             $row_column  Its row id column.
	 * @param string             $id_column   Its object id column.
	 * @param string             $cache_group The core cache group of that meta type.
	 * @param array<int, string> $keep        Keys to leave.
	 * @return bool False if a read or a DELETE failed.
	 */
	private static function delete_owned_meta( string $type, string $table, string $row_column, string $id_column, string $cache_group, array $keep ): bool {
		$ok = true;

		foreach ( self::owned( 'keys_' . $type ) as $key ) {
			$ok = self::delete_meta_like( $table, $row_column, $id_column, $cache_group, $key, $keep, true ) && $ok;
		}

		foreach ( self::owned( 'meta_' . $type ) as $prefix ) {
			$ok = self::delete_meta_like( $table, $row_column, $id_column, $cache_group, $prefix, $keep ) && $ok;
		}

		return $ok;
	}

	/**
	 * Delete one meta table's rows whose key starts with a prefix, Pro's aside, and the cached meta of their objects (the
	 * DELETE bypasses the meta API, and a persistent cache would keep serving them): in pages of META_PAGE by row id with
	 * such a cache, else one DELETE.
	 *
	 * @param string             $table       Meta table.
	 * @param string             $row_column  Its row id column (meta_id, umeta_id).
	 * @param string             $id_column   Its object id column (post_id, term_id, user_id).
	 * @param string             $cache_group The core cache group of that meta type.
	 * @param string             $prefix      Literal key prefix.
	 * @param array<int, string> $keep Keys to leave.
	 * @param bool               $exact Whether $prefix is a whole key.
	 * @return bool False if a read or a DELETE failed.
	 */
	private static function delete_meta_like( string $table, string $row_column, string $id_column, string $cache_group, string $prefix, array $keep = [], bool $exact = false ): bool {
		global $wpdb;
		/**
		 * WordPress database object.
		 *
		 * @var \wpdb $wpdb
		 */

		$like    = $exact ? $prefix : $wpdb->esc_like( $prefix ) . '%';
		$op      = $exact ? '=' : 'LIKE';
		$keep    = array_values( array_unique( array_filter( $keep, 'is_string' ) ) );
		$foreign = self::foreign_meta_likes( $prefix );
		$not_in  = ( [] === $keep ? '' : ' AND meta_key NOT IN (' . implode( ', ', array_fill( 0, count( $keep ), '%s' ) ) . ')' )
			. implode( '', array_fill( 0, count( $foreign ), ' AND meta_key NOT LIKE %s' ) );
		$keep    = array_merge( $keep, $foreign );

		if ( ! wp_using_ext_object_cache() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $not_in is placeholders only; the kept keys and Pro's patterns are bound one %s each.
			$delete = $wpdb->prepare( "DELETE FROM %i WHERE meta_key {$op} %s{$not_in}", $table, $like, ...$keep );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared just above.
			return null !== $delete && false !== $wpdb->query( $delete );
		}

		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the uninstall's own page of the rows it deletes next; $not_in is placeholders only, the kept keys and Pro's patterns bound one %s each.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT %i, %i FROM %i WHERE meta_key {$op} %s{$not_in} AND %i > %d ORDER BY %i LIMIT %d", $row_column, $id_column, $table, $like, ...array_merge( $keep, [ $row_column, $after, $row_column, self::META_PAGE ] ) ), ARRAY_N );

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

		foreach ( self::owned( 'meta_order' ) as $prefix ) {
			$foreign = self::foreign_meta_likes( $prefix );
			$not     = implode( '', array_fill( 0, count( $foreign ), ' AND meta_key NOT LIKE %s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $not is placeholders only, Pro's patterns bound one %s each.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE meta_key LIKE %s{$not}", $table, $wpdb->esc_like( $prefix ) . '%', ...$foreign ) );

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
	 * @return bool False when Action Scheduler kept an action this could not cancel.
	 */
	private static function clear_all_scheduled_events(): bool {
		$cancelled = self::clear_action_scheduler_orphans();
		self::clear_wp_cron_orphans();

		return $cancelled;
	}

	/**
	 * Cancel every pending action of the `perflocale` group through Action Scheduler's API.
	 *
	 * @return bool False when an action could not be cancelled, or the cancellation cannot be confirmed.
	 */
	private static function clear_action_scheduler_orphans(): bool {
		if ( did_action( 'action_scheduler_init' ) === 0 ) {
			return true;
		}

		$threw = false;

		// Preferred: the store's cancel_actions_by_group() through as_unschedule_all_actions( '', [], $group ), which also
		// catches recurring events scheduled with [ $blog_id ] args.
		if ( class_exists( '\\ActionScheduler_Store' )
			&& method_exists( '\\ActionScheduler_Store', 'instance' )
		) {
			try {
				$store = \ActionScheduler_Store::instance();
				if ( method_exists( $store, 'cancel_actions_by_group' ) ) {
					$store->cancel_actions_by_group( self::AS_GROUP );
					return true;
				}
			} catch ( \Throwable $e ) {
				// Fall through to the per-hook path below.
				$threw = true;
			}
		}

		// Fallback when the store API is missing: enumerate the ids and cancel each. Always the first page, as each cancel
		// shrinks the set; an id still listed after its cancel counts as failed, with room left so those hide nothing.
		if ( ! function_exists( 'as_get_scheduled_actions' )
			|| ! function_exists( 'as_unschedule_action' )
		) {
			return ! $threw;
		}

		$tried = [];

		for ( $round = 0; $round < 100; $round++ ) {
			$errors = SchemaWrites::query_error_count();

			try {
				$ids = as_get_scheduled_actions(
					[
						'group'    => self::AS_GROUP,
						'status'   => [ 'pending', 'in-progress' ],
						'per_page' => 100 + count( $tried ),
						'orderby'  => 'action_id',
						'order'    => 'ASC',
					],
					'ids'
				);
			} catch ( \Throwable $e ) {
				return false;
			}

			// A failed read also returns nothing.
			if ( ! is_array( $ids ) || SchemaWrites::query_error_count() > $errors ) {
				return false;
			}

			if ( $ids === [] ) {
				return true;
			}

			$fresh = array_values( array_diff( array_map( static fn( $id ): int => is_numeric( $id ) ? (int) $id : 0, $ids ), $tried ) );

			if ( $fresh === [] ) {
				return false;
			}

			// Cancel by id: every action, whatever its hook, args or group shape.
			foreach ( $fresh as $id ) {
				try {
					\ActionScheduler::store()->cancel_action( $id );
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				$tried[] = $id;
			}
		}

		return false;
	}

	/**
	 * Remove every WP-Cron event whose hook starts with `perflocale_` (unexpected args, add-on hooks, an engine switch), Pro's
	 * aside.
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
					&& strpos( $hook_name, 'perflocale_' ) === 0
					&& ! self::is_foreign( $hook_name ) ) {
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
	 * @return array{ids: array<int, string>, tables: array<int, string>, options: array<int, string>, meta: array<string, array<int, string>>, unjournaled: bool, manifests: bool}
	 *     What the addons the delete_data_on_uninstall filter kept declared
	 *     (none on a site deletion: the site goes); `unjournaled`: an add-on purge is not finished (a deferred target
	 *     list not stored, a refused DROP or DELETE, a throw), so its manifest and the settings row stay for the next
	 *     pass; `manifests`: the manifests could not be read, so every one stays.
	 */
	private static function purge_addons( bool $single_site_teardown = false, bool $network_sweep = false ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$keep = [
			'ids'         => [],
			'tables'      => [],
			'options'     => [],
			'meta'        => [],
			'unjournaled' => false,
			'manifests'   => false,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$manifest_keys = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'perflocale_addon_manifest_' ) . '%'
			)
		);

		// A failed read is not "no add-ons": the manifests are their only record, so they stay with the settings for a retry.
		if ( '' !== $wpdb->last_error ) {
			$keep['unjournaled'] = true;
			$keep['manifests']   = true;

			return $keep;
		}

		if ( ! class_exists( \PerfLocale\Addon\AddonUninstaller::class ) ) {
			return $keep;
		}

		// Every add-on of the pass stays a sibling for the later ones: a purged manifest is gone from the stored list.
		\PerfLocale\Addon\AddonUninstaller::set_pass_ids( array_map( static fn( $k ): string => substr( (string) $k, strlen( 'perflocale_addon_manifest_' ) ), (array) $manifest_keys ) );

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
				if ( [] !== array_intersect( [ \PerfLocale\Addon\AddonUninstaller::JOURNAL_ERROR, \PerfLocale\Addon\AddonUninstaller::CLAIMS_ERROR, \PerfLocale\Addon\AddonUninstaller::SETTING_ERROR, \PerfLocale\Addon\AddonUninstaller::LEFT_ERROR ], $result->errors ) ) {
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
				// Unfinished: the manifest is the only record of what is left, so it stays with the settings for a retry.
				$keep['unjournaled'] = true;
				$keep['options'][]   = 'perflocale_addon_manifest_' . $addon_id;

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
	 * Whether no option row the full purge deletes is left (exact names and the registry's patterns, minus kept ones).
	 *
	 * @param string[] $keep          Option names a kept add-on declared.
	 * @param bool     $versions_kept Whether a kept add-on keeps its schema versions row.
	 * @param string[] $protected     self::protected_prefixes(): such rows are left on purpose.
	 * @return bool False when a row is left or the read failed.
	 */
	private static function options_gone( array $keep, bool $versions_kept, array $protected ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$skip  = array_merge( $keep, [ self::MODE_OPTION ], $versions_kept ? [ \PerfLocale\Addon\AddonSchemaManager::VERSIONS_OPTION ] : [] );
		$names = array_values( array_diff( self::owned( 'options' ), $skip, [ self::OWNED_OPTION ] ) );
		$where = [];
		$args  = [ $wpdb->options ];

		if ( [] !== $names ) {
			$where[] = 'option_name IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')';
			$args    = array_merge( $args, $names );
		}

		foreach ( self::owned( 'likes' ) as $pattern ) {
			$where[] = 'option_name LIKE %s';
			$args[]  = self::option_like( $pattern );
		}

		if ( [] === $where ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One read on the uninstall path; the placeholders are built above.
		$left = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE ' . implode( ' OR ', $where ), $args ) );

		$left = array_filter( array_map( static fn( $name ): string => is_scalar( $name ) ? (string) $name : '', (array) $left ), static fn( string $name ): bool => ! self::in_namespaces( $name, $protected ) );

		return '' === $wpdb->last_error && [] === array_diff( $left, $skip );
	}

	/**
	 * A registry option pattern as a LIKE value: each piece between the `%` signs escaped (`_` is a LIKE wildcard).
	 *
	 * @param string $like_pattern Pattern with `%` wildcards.
	 * @return string
	 */
	private static function option_like( string $like_pattern ): string {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		return implode( '%', array_map( [ $wpdb, 'esc_like' ], explode( '%', $like_pattern ) ) );
	}

	/**
	 * Delete every wp_options row matching a LIKE pattern through delete_option() / delete_transient(), so the caches
	 * stay coherent.
	 *
	 * @param string             $like_pattern e.g. 'perflocale_addon_manifest_%'.
	 * @param array<int, string> $keep         Option names to leave (an addon that kept its data).
	 * @param string[]           $protected    Bare name prefixes to leave (self::protected_prefixes()).
	 * @return void
	 */
	private static function delete_options_like( string $like_pattern, array $keep = [], array $protected = self::FOREIGN_PREFIXES ): void {
		global $wpdb;

		$escaped = self::option_like( $like_pattern );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$escaped
			)
		);

		foreach ( (array) $names as $name ) {
			$name = (string) $name;

			if ( ! in_array( $name, $keep, true ) && ! self::in_namespaces( $name, $protected ) ) {
				self::delete_option_row( $name );
			}
		}
	}

	/**
	 * Delete one wp_options row by name, through the transient API for a transient row so the caches stay coherent.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private static function delete_option_row( string $name ): void {
		if ( str_starts_with( $name, '_transient_timeout_' ) || str_starts_with( $name, '_site_transient_timeout_' ) ) {
			// A timeout row whose value row is gone has nothing to take it: delete the exact row (delete_transient() on its
			// name would only touch a cache key that never existed).
			delete_option( $name );
			return;
		}

		// The API may not reach this row (a persistent cache deletes only the key; multisite targets wp_sitemeta): delete it
		// by name, or it outlives its timeout row.
		if ( str_starts_with( $name, '_transient_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );

			if ( wp_using_ext_object_cache() ) {
				delete_option( $name );
			}
			return;
		}

		if ( str_starts_with( $name, '_site_transient_' ) ) {
			delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );

			if ( wp_using_ext_object_cache() || is_multisite() ) {
				delete_option( $name );
			}
			return;
		}

		delete_option( $name );
	}

	/**
	 * Bare option and transient name prefixes no free sweep or cache flush deletes, lowercase: Pro's, and every registered
	 * add-on's or extension's, active or not (stored manifests, schema versions, bundled add-on folders, extension entries).
	 *
	 * A prefix as broad as `perflocale_`, or one a core uninstall name starts with (add-on id `mt`), is dropped: it would
	 * protect core rows.
	 *
	 * @param string[] $addon_ids Further add-on ids (a network sweep's record of blogs already purged).
	 * @return list<string>|null Null when a read failed: no add-on's rows can be told apart, so no sweep may run.
	 */
	public static function protected_prefixes( array $addon_ids = [] ): ?array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$out = self::FOREIGN_PREFIXES;
		$dir = dirname( __DIR__, 2 ) . '/addons';

		foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : [] as $entry ) {
			if ( is_string( $entry ) && '' !== $entry && '.' !== $entry[0] && is_dir( $dir . '/' . $entry ) ) {
				$addon_ids[] = $entry;
			}
		}

		$versions = OptionRead::of( 'perflocale_addon_schema_versions' );

		if ( $versions->failed ) {
			return null;
		}

		$versions  = null !== $versions->bytes ? maybe_unserialize( $versions->bytes ) : null;
		$addon_ids = array_merge( $addon_ids, is_array( $versions ) ? array_map( 'strval', array_keys( $versions ) ) : [] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cold paths only (uninstall, cache flush).
		$manifests = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'perflocale_addon_manifest_' ) . '%' ) );

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		foreach ( $manifests as $name ) {
			$name        = is_scalar( $name ) ? (string) $name : '';
			$addon_ids[] = substr( $name, strlen( 'perflocale_addon_manifest_' ) );
			$manifest    = OptionRead::of( $name );

			if ( $manifest->failed ) {
				return null;
			}

			$manifest = null !== $manifest->bytes ? maybe_unserialize( $manifest->bytes ) : null;

			foreach ( [ 'options', 'site_options', 'transient_prefixes' ] as $field ) {
				foreach ( is_array( $manifest ) && is_array( $manifest[ $field ] ?? null ) ? $manifest[ $field ] : [] as $declared ) {
					$out[] = is_string( $declared ) ? $declared : '';
				}
			}
		}

		foreach ( $addon_ids as $id ) {
			$out[] = 'perflocale_' . $id . '_';
			$out[] = 'perflocale_' . str_replace( '-', '_', $id ) . '_';
		}

		foreach ( DataRegistry::extension_entries() as $e ) {
			if ( in_array( $e['kind'] ?? null, [ 'option', 'network_option', 'transient', 'site_transient' ], true ) && is_string( $e['name'] ?? null ) ) {
				$out[] = DataRegistry::to_like_prefix( $e['name'] );
			}
		}

		$out  = array_map( static fn( string $p ): string => strtolower( ltrim( $p, '_' ) ), $out );
		$core = self::core_deleted_prefixes();

		return array_values(
			array_unique(
				array_filter(
					$out,
					static function ( string $p ) use ( $core ): bool {
						if ( '' === $p || str_starts_with( 'perflocale_', $p ) ) {
							return false;
						}

						if ( in_array( $p, self::FOREIGN_PREFIXES, true ) ) {
							return true;
						}

						foreach ( $core as $c ) {
							if ( str_starts_with( $c, $p ) ) {
								return false;
							}
						}

						return true;
					}
				)
			)
		);
	}

	/**
	 * Names an add-on purge must not take (AddonUninstaller): the core registry's names and name prefixes (not its
	 * namespace-wide entries), core's capabilities, and Pro's prefixes.
	 *
	 * @return array{exact: list<string>, prefixes: list<string>}
	 */
	public static function core_claims(): array {
		$exact    = class_exists( \PerfLocale\Admin\TranslatorRole::class ) ? \PerfLocale\Admin\TranslatorRole::CAPABILITIES : [];
		$prefixes = self::FOREIGN_PREFIXES;

		foreach ( [ 'option', 'network_option', 'transient', 'site_transient', 'post_meta', 'term_meta', 'user_meta', 'order_meta', 'cron' ] as $kind ) {
			foreach ( DataRegistry::entries_of_kind( $kind ) as $e ) {
				$name = is_string( $e['name'] ?? null ) ? $e['name'] : '';

				if ( 'core' !== ( $e['owner'] ?? '' ) || 'NAMESPACE' === ( $e['class'] ?? '' ) || '' === $name ) {
					continue;
				}

				if ( DataRegistry::has_placeholder( $name ) ) {
					$prefixes[] = DataRegistry::to_like_prefix( $name );
				} else {
					$exact[] = $name;
				}
			}
		}

		return [
			'exact'    => $exact,
			'prefixes' => $prefixes,
		];
	}

	/**
	 * Lowercase names and LIKE prefixes of the core registry's option and transient entries an uninstall deletes, and of
	 * the cache groups' transients (`perflocale_{group}_{key}`, whose registry prefix is the bare namespace).
	 *
	 * @return string[]
	 */
	private static function core_deleted_prefixes(): array {
		$out = class_exists( \PerfLocale\Cache\CacheManager::class ) ? array_map( static fn( string $g ): string => 'perflocale_' . $g . '_', \PerfLocale\Cache\CacheManager::GROUPS ) : [];

		foreach ( [ 'option', 'network_option', 'transient', 'site_transient' ] as $kind ) {
			foreach ( DataRegistry::entries_of_kind( $kind ) as $e ) {
				$name = is_string( $e['name'] ?? null ) ? $e['name'] : '';

				if ( '' !== $name && 'core' === ( $e['owner'] ?? null ) && in_array( $e['uninstall'] ?? null, [ 'data', 'always' ], true ) ) {
					$out[] = strtolower( DataRegistry::has_placeholder( $name ) ? DataRegistry::to_like_prefix( $name ) : $name );
				}
			}
		}

		return $out;
	}

	/**
	 * CacheManager's transient keep list: for the whole `perflocale_` namespace (flush_all()) also protected_prefixes().
	 *
	 * @param string   $transient_prefix The sweep's transient-name prefix.
	 * @param string[] $keep             Its own keep list.
	 * @return string[]
	 */
	public static function flush_keep( string $transient_prefix, array $keep ): array {
		// An unread add-on list keeps the whole namespace: the sweep then deletes nothing.
		return 'perflocale_' === $transient_prefix ? array_merge( $keep, self::protected_prefixes() ?? [ 'perflocale_' ] ) : $keep;
	}

	/**
	 * Whether a row name (option, transient or their timeout row) is in Pro's namespaces (FOREIGN_PREFIXES).
	 *
	 * @param string $name Row name.
	 * @return bool
	 */
	private static function is_foreign( string $name ): bool {
		return self::in_namespaces( $name, self::FOREIGN_PREFIXES );
	}

	/**
	 * The listed tables SHOW TABLES still has, the kept ones left out; compared without case, as
	 * lower_case_table_names=1 answers an upper-case prefix in lower case.
	 *
	 * @param string[] $tables Full table names.
	 * @param string[] $keep   Full table names a kept add-on owns.
	 * @return string[]
	 */
	private static function tables_left( array $tables, array $keep ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one SHOW TABLES per purged blog, on the uninstall path only.
		$found = array_filter( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'perflocale_' ) . '%' ) ), 'is_string' );
		$want  = array_diff( array_map( 'strtolower', $tables ), array_map( 'strtolower', $keep ) );

		return array_values( array_filter( $found, static fn( string $name ): bool => in_array( strtolower( $name ), $want, true ) ) );
	}

	/**
	 * Whether a table of the current blog (`<prefix>perflocale_pro_*`) is in Pro's namespaces.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function is_foreign_table( string $table ): bool {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		return str_starts_with( $table, $wpdb->prefix ) && self::is_foreign( substr( $table, strlen( $wpdb->prefix ) ) );
	}

	/**
	 * NOT LIKE values for the meta keys of Pro's namespaces (bare or `_`-led) a meta sweep under $prefix would reach.
	 *
	 * @param string $prefix The sweep's literal key prefix.
	 * @return string[]
	 */
	private static function foreign_meta_likes( string $prefix ): array {
		global $wpdb;
		/**
		 * WordPress database access object.
		 *
		 * @var \wpdb $wpdb
		 */

		$out = [];

		foreach ( self::FOREIGN_PREFIXES as $foreign ) {
			foreach ( [ $foreign, '_' . $foreign ] as $key ) {
				if ( str_starts_with( $key, $prefix ) ) {
					$out[] = $wpdb->esc_like( $key ) . '%';
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a row name, read as its bare `perflocale_` name, starts with one of $prefixes.
	 *
	 * @param string   $name     Row name.
	 * @param string[] $prefixes Bare lowercase prefixes.
	 * @return bool
	 */
	private static function in_namespaces( string $name, array $prefixes ): bool {
		$lower = strtolower( $name );

		foreach ( self::NAMESPACE_PREFIXES as $prefix ) {
			if ( ! str_starts_with( $lower, $prefix ) ) {
				continue;
			}

			$bare = 'perflocale_' . substr( $lower, strlen( $prefix ) );

			foreach ( $prefixes as $foreign ) {
				if ( str_starts_with( $bare, $foreign ) ) {
					return true;
				}
			}
		}

		return false;
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

		// Preserve mode: the ledger, and the role as the owner left it (Activator restores it on the next activation).
		if ( ! $drop_role_assignment && class_exists( \PerfLocale\Admin\TranslatorRole::class ) ) {
			\PerfLocale\Admin\TranslatorRole::record_translator_ledger();
			self::snapshot_translator_role();
		}

		remove_role( \PerfLocale\Admin\TranslatorRole::ROLE_SLUG );

		// Oxygen's per-role access option (`oxygen_vsb_access_role_<role>`, never deleted by Oxygen) goes with a role a
		// full-data uninstall removed for good; deactivation and preserve keep both.
		if ( $drop_role_assignment ) {
			delete_option( 'oxygen_vsb_access_role_perflocale_translator' );
		}

		// Preserve mode: the Shop Manager grant comes off by its record, which stays for a reinstall's grant pass. The full
		// path deleted the record already and its every-role loop below removes the capability.
		if ( ! $drop_role_assignment ) {
			\PerfLocale\Admin\TranslatorRole::strip_shop_manager_caps();
		}

		$caps = self::canonical_caps();

		// Full-data uninstall: every role (caps may have been granted to authors, shop managers). Elsewhere editor and
		// administrator only, which install_caps() re-grants, or custom roles lose caps on every reactivation.
		$default_roles = [ 'editor', 'administrator' ];

		if ( $drop_role_assignment && function_exists( 'wp_roles' ) ) {
			$default_roles = array_keys( wp_roles()->roles );
		}

		/**
		 * Filter which WordPress roles have PerfLocale capabilities removed on uninstall.
		 *
		 * Remove a role slug from this array to keep its PerfLocale capabilities after an uninstall.
		 *
		 * @hook perflocale/roles/cap_roles
		 *
		 * @param string[] $roles Role slugs to strip. Default: ['editor', 'administrator']; every role on a delete-data uninstall.
		 */
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

		// Orphaned direct grants on users.
		self::sweep_orphan_user_caps( $drop_role_assignment );

		// The install flag goes with the caps, or install_caps() would skip them on a reinstall; a no-op on the full path.
		delete_option( 'perflocale_caps_version' );
	}

	/**
	 * Store the Translator role's name and full capability map before a keep-data uninstall removes it; none when gone.
	 *
	 * @return void
	 */
	private static function snapshot_translator_role(): void {
		$slug = \PerfLocale\Admin\TranslatorRole::ROLE_SLUG;
		$role = get_role( $slug );

		if ( null === $role ) {
			delete_option( \PerfLocale\Admin\TranslatorRole::ROLE_SNAPSHOT_OPTION );

			return;
		}

		$name = wp_roles()->roles[ $slug ]['name'] ?? '';

		update_option(
			\PerfLocale\Admin\TranslatorRole::ROLE_SNAPSHOT_OPTION,
			[
				'name' => is_string( $name ) ? $name : '',
				'caps' => $role->capabilities,
			],
			false
		);
	}

	/**
	 * Strip every `perflocale_*` key from users' `wp_capabilities` meta; the call site has switched to the right blog.
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
		$strip = self::canonical_caps();

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
	 * Caps to strip: TranslatorRole::CAPABILITIES (a static list when that class cannot load early in uninstall) and the
	 * retired ones older builds granted. Retire a capability by moving it here, never by deleting it.
	 *
	 * @return string[]
	 */
	private static function canonical_caps(): array {
		$caps = class_exists( \PerfLocale\Admin\TranslatorRole::class )
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

		return array_merge( $caps, [ 'perflocale_manage_glossary' ] );
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
