<?php
/**
 * URL bases compile triggers.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for changes of compile inputs (DESIGN-step1 3.10 trigger table) and compiles once at shutdown.
 *
 * Registered in admin, cron, WP-CLI and signed-in AJAX/REST writes; elsewhere schedule() only writes the dirty marker.
 *
 * @phpstan-import-type Result from Publication
 *
 * @internal
 */
final class Lifecycle {

	/**
	 * Single cron event: network site paths changed; arguments network id, the last site id done and the batch's try.
	 */
	public const SITES_HOOK = LifecycleHooks::SITES_HOOK;

	/**
	 * Sites one SITES_HOOK event marks; the next batch is its own event.
	 */
	private const SITES_BATCH = 100;

	/**
	 * Retries of a batch whose run died; the last try queues the next batch first, so one failing site cannot stop the walk.
	 */
	private const SITES_TRIES = 3;

	/**
	 * Single cron event: an input changed where no compile can run (a front-end rewrite rules flush).
	 */
	public const COMPILE_HOOK = LifecycleHooks::COMPILE_HOOK;

	/**
	 * Option holding the time of the last hourly check.
	 */
	public const CHECKED_OPTION = 'perflocale_url_bases_checked';

	/**
	 * The dirty marker option: seq, reasons, retire. Autoloaded and kept (clean) while URL bases are configured, so
	 * admin_check() reads it with no query.
	 */
	public const DIRTY = LifecycleHooks::DIRTY;

	/**
	 * Values::OPTION, Publication::MAP and CodeValues::HOOK as literals: register() and configured() run on editor writes
	 * and WP-CLI, where the class constants would load those classes (ResyncBudgetTest and
	 * tools/regression-tests/fallback-publish-read-counts.php pin them equal).
	 */
	private const VALUES_OPTION = 'perflocale_url_bases';
	private const MAP_OPTION    = 'perflocale_url_bases_map';
	private const CODE_HOOK     = 'perflocale/url_bases/value';

	/**
	 * Reasons waiting for the shutdown compile, by blog id.
	 *
	 * @var array<int, list<string>>
	 */
	private static array $pending = [];

	/**
	 * Whether input writes are the compile's own (Publication storing durable fields).
	 *
	 * @var bool
	 */
	private static bool $quiet = false;

	/**
	 * Whether the shutdown compile is hooked.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Blogs marked dirty by this request where register() did not run.
	 *
	 * @var array<int, true>
	 */
	private static array $marked = [];

	/**
	 * Blogs with a translation link in this request, checked once at shutdown.
	 *
	 * @var array<int, true>
	 */
	private static array $linked = [];

	/**
	 * Blogs deactivated in this request: nothing more is marked or compiled for them.
	 *
	 * @var array<int, true>
	 */
	private static array $stopped = [];

	/**
	 * Provider input option names by blog id, built on first need.
	 *
	 * @var array<int, list<string>>
	 */
	private static array $provider_inputs = [];

	/**
	 * Register the listeners once (admin, cron, WP-CLI, signed-in AJAX and REST writes); see LifecycleHooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		LifecycleHooks::register();
	}

	/**
	 * Queue a compile for the end of this request; where register() did not run, set the dirty marker and queue one
	 * cron compile, so the stale window ends at the next cron run.
	 *
	 * @param string $reason Why.
	 * @return void
	 */
	public static function schedule( string $reason ): void {
		$blog = get_current_blog_id();

		if ( isset( self::$stopped[ $blog ] ) ) {
			return;
		}

		if ( ! LifecycleHooks::$registered ) {
			// One marker per blog per request: a plugin that flushes the rules on every request writes them twice.
			if ( isset( self::$marked[ $blog ] ) ) {
				return;
			}

			self::$marked[ $blog ] = true;
			self::mark_dirty( $reason );

			// Only on a request that already writes rewrite_rules (the 7.2 exception); one event at a time.
			if ( false === wp_next_scheduled( self::COMPILE_HOOK ) ) {
				wp_schedule_single_event( time(), self::COMPILE_HOOK );
			}

			return;
		}

		self::$pending[ $blog ][] = $reason;

		if ( ! self::$hooked ) {
			self::$hooked = true;
			// Late: after the request's last rewrite_rules write.
			add_action( 'shutdown', [ self::class, 'run_pending' ], 100 );
		}
	}

	/**
	 * Deactivation of this blog: drop its queued compile and ignore later triggers in this request, whose marker or map
	 * write would autoload the rows again.
	 *
	 * @return void
	 */
	public static function stop(): void {
		$blog                   = get_current_blog_id();
		self::$stopped[ $blog ] = true;
		unset( self::$pending[ $blog ] );
	}

	/**
	 * Compile now (settings save, WP-CLI, the end of an import); clears this blog's queued compile.
	 *
	 * @param string       $reason Why.
	 * @param list<string> $forget Keys whose old bases are dropped; `*` for all.
	 * @return Result
	 */
	public static function compile_now( string $reason, array $forget = [] ): array {
		unset( self::$pending[ get_current_blog_id() ] );

		return Publication::publish( $reason, $forget );
	}

	/**
	 * Shutdown: one compile of this blog; another blog's triggers mark it dirty (its own request compiles it).
	 *
	 * @return void
	 */
	public static function run_pending(): void {
		$pending       = self::$pending;
		self::$pending = [];
		// A request left switched has another blog's registry here: compile nothing, mark every blog.
		$here = [] === ( $GLOBALS['_wp_switched_stack'] ?? [] ) ? get_current_blog_id() : 0;

		foreach ( $pending as $blog => $reasons ) {
			$reason = implode( ',', array_values( array_unique( $reasons ) ) );

			// A blog deleted during this request, the current one too, has no tables left.
			if ( is_multisite() && ! get_site( $blog ) instanceof \WP_Site ) {
				continue;
			}

			if ( $blog !== $here ) {
				switch_to_blog( $blog );

				try {
					self::mark_dirty( $reason );
				} finally {
					restore_current_blog();
				}

				continue;
			}

			try {
				Publication::publish( $reason );
			} catch ( \Throwable $e ) {
				// A failed compile keeps the previous map; the marker makes the next admin request try again.
				self::mark_dirty( 'error' );
			}
		}
	}

	/**
	 * Set the dirty marker by compare-and-set on the row read, so a concurrent acknowledgement's update or DELETE is
	 * never overwritten from a stale read; after five lost races one plain upsert, as the marker must land.
	 *
	 * @param string       $reason Why the map needs a compile.
	 * @param list<string> $retire Adapters owed a retirement (Publication::owe_retirement()); kept attempts stay.
	 * @return void
	 */
	public static function mark_dirty( string $reason, array $retire = [] ): void {
		if ( ! self::set_up() ) {
			return;
		}

		$wpdb = \PerfLocale\Admin\Typed::wpdb();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The marker row itself, past any cache; prepared statements.
		for ( $try = 0; $try < 6; $try++ ) {
			$read = \PerfLocale\Database\OptionRead::of( self::DIRTY );
			$raw  = $read->bytes;
			$cur  = \PerfLocale\Admin\Typed::map( is_string( $raw ) ? maybe_unserialize( $raw ) : null );
			$why = \PerfLocale\Admin\Typed::strs( $cur['reasons'] ?? null );
			$val = maybe_serialize(
				[
					'seq'     => \PerfLocale\Admin\Typed::int( $cur['seq'] ?? null ) + 1,
					'reasons' => array_slice( array_values( array_unique( array_merge( $why, [ sanitize_key( $reason ) ] ) ) ), -20 ),
					'retire'  => \PerfLocale\Admin\Typed::map( $cur['retire'] ?? null ) + array_fill_keys( $retire, 0 ),
					'at'      => microtime( true ) . ':' . wp_rand(),
				]
			);

			// A failed read never feeds an overwrite: only an insert that keeps a stored marker.
			if ( 5 === $try && ! $read->failed ) {
				$sql = $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = %s', $wpdb->options, self::DIRTY, $val, self::autoload_on(), $val );
			} elseif ( is_string( $raw ) ) {
				$sql = $wpdb->prepare( 'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $val, self::autoload_on(), self::DIRTY, $raw );
			} else {
				$sql = $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::DIRTY, $val, self::autoload_on() );
			}

			// One affected row: this write won; none: the row changed since the read, read again.
			if ( is_string( $sql ) && ( 1 === $wpdb->query( $sql ) || 5 === $try ) ) {
				break;
			}
		}
		// phpcs:enable

		self::marker_written();
	}

	/**
	 * The autoload value core loads into alloptions: 'yes' before WordPress 6.6 reads only that.
	 *
	 * @return string
	 */
	public static function autoload_on(): string {
		return function_exists( 'wp_determine_option_autoload_value' ) ? 'on' : 'yes';
	}

	/**
	 * Drop the caches of the marker row after a direct write (it is autoloaded: alloptions holds it).
	 *
	 * @return void
	 */
	public static function marker_written(): void {
		wp_cache_delete( self::DIRTY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ self::DIRTY ] ) ) {
			unset( $notoptions[ self::DIRTY ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Run a write the compile makes itself, without queuing a compile for it.
	 *
	 * @template T
	 * @param callable(): T $write Write.
	 * @return T
	 */
	public static function quietly( callable $write ): mixed {
		self::$quiet = true;

		try {
			return $write();
		} finally {
			self::$quiet = false;
		}
	}

	/**
	 * `add_option_*`, `update_option_*`, `delete_option_*` of a core input.
	 *
	 * @return void
	 */
	public static function on_option(): void {
		if ( ! self::$quiet ) {
			self::schedule( 'option' );
		}
	}

	/**
	 * `added_option`, `updated_option`, `deleted_option`: an option a provider lists in inputs().
	 *
	 * @param mixed $option Option name.
	 * @return void
	 */
	public static function on_any_option( mixed $option = '' ): void {
		if ( ! is_string( $option ) || in_array( $option, LifecycleHooks::INPUT_OPTIONS, true ) || 1 === preg_match( LifecycleHooks::NOT_INPUT, $option ) ) {
			return;
		}

		$blog = get_current_blog_id();

		if ( ! isset( self::$provider_inputs[ $blog ] ) ) {
			self::$provider_inputs[ $blog ] = self::configured() ? Registry::build()->inputs() : [];
		}

		if ( in_array( $option, self::$provider_inputs[ $blog ], true ) ) {
			self::schedule( 'provider_input' );
		}
	}

	/**
	 * Language events.
	 *
	 * @return void
	 */
	public static function on_language(): void {
		if ( self::configured() ) {
			self::schedule( 'language' );
		}
	}

	/**
	 * `perflocale/settings/updated` when a setting the compile reads changed.
	 *
	 * @param mixed $after  New settings.
	 * @param mixed $before Old settings.
	 * @return void
	 */
	public static function on_settings( mixed $after = [], mixed $before = [] ): void {
		$after  = is_array( $after ) ? $after : [];
		$before = is_array( $before ) ? $before : [];

		foreach ( [ 'translatable_post_types', 'translatable_taxonomies', 'url_mode', 'url_prefix_type', 'hide_default_prefix', 'translate_slugs' ] as $key ) {
			if ( ( $after[ $key ] ?? null ) !== ( $before[ $key ] ?? null ) ) {
				if ( self::configured() ) {
					self::schedule( 'settings' );
				}
				return;
			}
		}
	}

	/**
	 * `perflocale/translation/linked`: a shop page translation may have changed. Checked at shutdown, once per blog:
	 * string imports link thousands of rows per request.
	 *
	 * @return void
	 */
	public static function on_linked(): void {
		if ( [] === self::$linked ) {
			add_action( 'shutdown', [ self::class, 'linked_check' ], 99 );
		}

		self::$linked[ get_current_blog_id() ] = true;
	}

	/**
	 * Before the shutdown compile: queue it for each linked blog with URL bases and a shop page.
	 *
	 * @internal Hooked by on_linked().
	 *
	 * @return void
	 */
	public static function linked_check(): void {
		$blogs        = array_keys( self::$linked );
		self::$linked = [];

		foreach ( $blogs as $blog ) {
			$switch = get_current_blog_id() !== $blog;

			if ( $switch ) {
				switch_to_blog( $blog );
			}

			try {
				if ( self::configured() && \PerfLocale\Admin\Typed::int( get_option( 'woocommerce_shop_page_id' ) ) > 0 ) {
					self::schedule( 'linked' );
				}
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * `post_updated`: a page or root-routed post moved to or from a configured address.
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $after   Post after.
	 * @param mixed $before  Post before.
	 * @return void
	 */
	public static function on_post_updated( mixed $post_id = 0, mixed $after = null, mixed $before = null ): void {
		foreach ( [ $after, $before ] as $post ) {
			if ( $post instanceof \WP_Post && self::post_hits( $post ) ) {
				self::schedule( 'content' );
				return;
			}
		}

		unset( $post_id );
	}

	/**
	 * `transition_post_status`: publication or withdrawal of such a post.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 * @return void
	 */
	public static function on_transition( mixed $new_status = '', mixed $old_status = '', mixed $post = null ): void {
		if ( $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) && $post instanceof \WP_Post && self::post_hits( $post ) ) {
			self::schedule( 'content' );
		}
	}

	/**
	 * Trash, delete and restore of such a post.
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public static function on_post_removed( mixed $post_id = 0 ): void {
		$post = get_post( \PerfLocale\Admin\Typed::int( $post_id ) );

		if ( $post instanceof \WP_Post && self::post_hits( $post ) ) {
			self::schedule( 'content' );
		}
	}

	/**
	 * `created_term`, `edited_term` of the taxonomy whose tag leads the permalink structure (after its front), or of a
	 * term named like an off shop key's page suffix.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_term( mixed $term_id = 0, mixed $tt_id = 0, mixed $taxonomy = '' ): void {
		if ( ! is_string( $taxonomy ) || ! self::configured() ) {
			return;
		}

		$lead = self::lead();
		$term = get_term( \PerfLocale\Admin\Typed::int( $term_id ), $taxonomy );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$slug = Validator::norm( $term->slug );

		if ( self::suffix_hit( $slug, null ) || ( 'term' === $lead['kind'] && $taxonomy === $lead['taxonomy'] && in_array( $slug, self::first_segments(), true ) ) ) {
			self::schedule( 'content' );
		}

		unset( $tt_id );
	}

	/**
	 * `user_register`, `profile_update` while %author% leads the permalink structure (after its front).
	 *
	 * @param mixed $user_id User id.
	 * @return void
	 */
	public static function on_user( mixed $user_id = 0 ): void {
		if ( ! self::configured() || 'author' !== self::lead()['kind'] ) {
			return;
		}

		$user = get_userdata( \PerfLocale\Admin\Typed::int( $user_id ) );

		if ( $user instanceof \WP_User && in_array( Validator::norm( $user->user_nicename ), self::first_segments(), true ) ) {
			self::schedule( 'content' );
		}
	}

	/**
	 * WooCommerce attribute changes.
	 *
	 * @return void
	 */
	public static function on_attribute(): void {
		if ( self::configured() ) {
			self::schedule( 'attribute' );
		}
	}

	/**
	 * The queued cron compile.
	 *
	 * @return void
	 */
	public static function on_cron(): void {
		self::schedule( 'cron' );
	}

	/**
	 * `wp_insert_site`, `wp_delete_site`, an address or status update: mark every mapped blog of the site's network in a
	 * cron job, not inline.
	 *
	 * @param mixed $site The site.
	 * @return void
	 */
	public static function on_site( mixed $site = null ): void {
		$args = [ $site instanceof \WP_Site ? (int) $site->site_id : get_current_network_id(), 0 ];
		// On the blog this request runs on: core deletes a site while switched to it, and its cron goes with it.
		$home   = \PerfLocale\Admin\Typed::ints( $GLOBALS['_wp_switched_stack'] ?? [] )[0] ?? get_current_blog_id();
		$switch = $home !== get_current_blog_id();

		if ( $switch ) {
			switch_to_blog( $home );
		}

		try {
			if ( ! wp_next_scheduled( self::SITES_HOOK, $args ) ) {
				wp_schedule_single_event( time(), self::SITES_HOOK, $args );
			}
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * `wp_update_site`: only a change of address or status counts; core updates `last_updated` on every publish.
	 *
	 * @param mixed $new_site Site after.
	 * @param mixed $old_site Site before.
	 * @return void
	 */
	public static function on_site_updated( mixed $new_site = null, mixed $old_site = null ): void {
		if ( $new_site instanceof \WP_Site && $old_site instanceof \WP_Site ) {
			foreach ( [ 'domain', 'path', 'public', 'archived', 'spam', 'deleted', 'mature', 'site_id' ] as $field ) {
				if ( (string) $new_site->$field !== (string) $old_site->$field ) {
					self::on_site( $new_site );

					// A site moved to another network leaves the old one's paths too.
					if ( (string) $new_site->site_id !== (string) $old_site->site_id ) {
						self::on_site( $old_site );
					}

					return;
				}
			}

			return;
		}

		self::on_site( $new_site );
	}

	/**
	 * Cron: set the dirty marker on every blog of the network where PerfLocale is active with URL bases, so each compiles
	 * in its own admin context; SITES_BATCH sites per event, by site id. Values alone count: a value refused for a site's
	 * path may leave no map.
	 *
	 * @param mixed $network_id Network; 0 for this one.
	 * @param mixed $after      Last site id done.
	 * @param mixed $tries      Earlier runs of this batch.
	 * @return void
	 */
	public static function mark_network( mixed $network_id = 0, mixed $after = 0, mixed $tries = 0 ): void {
		if ( ! is_multisite() ) {
			return;
		}

		$net  = \PerfLocale\Admin\Typed::int( $network_id );
		$net  = $net > 0 ? $net : get_current_network_id();
		$wpdb = \PerfLocale\Admin\Typed::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cron walk by primary key; WP_Site_Query has no id range.
		$ids = \PerfLocale\Admin\Typed::ints( $wpdb->get_col( $wpdb->prepare( 'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d ORDER BY blog_id LIMIT %d', $wpdb->blogs, $net, \PerfLocale\Admin\Typed::int( $after ), self::SITES_BATCH ) ) );
		$lost = '' !== $wpdb->last_error;

		$try   = \PerfLocale\Admin\Typed::int( $tries );
		$retry = [ $net, \PerfLocale\Admin\Typed::int( $after ), $try + 1 ];
		$at    = time() + 5 * MINUTE_IN_SECONDS;
		$next  = self::SITES_BATCH === count( $ids ) ? [ $net, (int) end( $ids ) ] : null;
		$redo  = $try < self::SITES_TRIES && ( [] !== $ids || $lost );

		// Queued first, so a run that dies mid-batch repeats it; 5 minutes: past the cron lock of a runner still going.
		if ( $redo ) {
			wp_schedule_single_event( $at, self::SITES_HOOK, $retry );
		} elseif ( null !== $next ) {
			wp_schedule_single_event( time(), self::SITES_HOOK, $next );
			$next = null;
		}

		// An inactive blog keeps its rows out of autoload; a marker would autoload one again. Activation is per network.
		$file    = plugin_basename( dirname( __DIR__, 3 ) . '/perflocale.php' );
		$sitewide = (array) get_network_option( $net, 'active_sitewide_plugins', [] );
		$network  = array_key_exists( $file, $sitewide );

		foreach ( $ids as $blog_id ) {
			switch_to_blog( $blog_id );

			try {
				$plugins = \PerfLocale\Database\OptionRead::of( 'active_plugins' );
				$active  = $network || $plugins->failed || in_array( $file, \PerfLocale\Admin\Typed::strs( maybe_unserialize( (string) $plugins->bytes ) ), true );

				if ( $active && self::network_uses( $sitewide, $plugins ) ) {
					self::mark_dirty( 'site_paths' );
				}
			} finally {
				restore_current_blog();
			}
		}

		// A failed site read keeps the retry queued.
		if ( $redo && ! $lost ) {
			wp_unschedule_event( $at, self::SITES_HOOK, $retry );
		}

		if ( null !== $next ) {
			wp_schedule_single_event( time(), self::SITES_HOOK, $next );
		}
	}

	/**
	 * `admin_init`: compile when the dirty marker is set; at most hourly, a full compile that writes only on change.
	 *
	 * @return void
	 */
	public static function admin_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// No query: the marker and the map are autoloaded rows; a configured site without a marker gets one from its
		// next compile.
		$all    = wp_load_alloptions();
		$marker = $all[ self::DIRTY ] ?? null;

		if ( ! is_string( $marker ) && ! isset( $all[ Publication::MAP ] ) && ! has_filter( CodeValues::HOOK ) ) {
			return;
		}

		$dirty = Publication::dirty( is_string( $marker ) ? $marker : '' );
		$owed  = array_filter( $dirty['retire'], static fn( int $n ): bool => $n < Publication::RETIRE_ATTEMPTS );

		// Marker rows left where URL bases are not in use (a 1.0.8 RC compile, the switch turned off) go, once nothing is owed.
		if ( [] === $owed && ! self::configured() ) {
			Publication::retire_unused();

			return;
		}

		if ( [] !== $dirty['reasons'] || [] !== $owed ) {
			self::schedule( 'dirty' );
			return;
		}

		if ( \PerfLocale\Admin\Typed::int( get_option( self::CHECKED_OPTION, 0 ) ) > time() - HOUR_IN_SECONDS ) {
			return;
		}

		update_option( self::CHECKED_OPTION, time(), true );
		self::schedule( 'hourly' );
	}

	/**
	 * The permalink structure's front and first tag, parsed as the compiler does.
	 *
	 * @return array{front: string, kind: string, taxonomy: string, vars: list<string>}
	 */
	private static function lead(): array {
		return Compiler::lead( \PerfLocale\Admin\Typed::str( get_option( 'permalink_structure' ) ) );
	}

	/**
	 * Whether this blog uses URL bases: a published map, or a values set that uses() them.
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		if ( LifecycleHooks::unconfigured() ) {
			return false;
		}

		if ( false !== get_option( self::MAP_OPTION, false ) ) {
			return true;
		}

		return self::uses( get_option( self::VALUES_OPTION, false ) );
	}

	/**
	 * Whether a stored values set uses URL bases: the switch on with a typed value, history, code values, or a product key
	 * that follows the Shop page.
	 *
	 * @param mixed     $values Stored values.
	 * @param int|null  $shop   The Shop page id the caller read (null: get_option()).
	 * @param bool|null $addon  Whether the WooCommerce add-on runs on this blog (null: this request's add-ons).
	 * @return bool
	 */
	public static function uses( mixed $values, ?int $shop = null, ?bool $addon = null ): bool {
		return Values::in_use( $values ) || ( is_array( $values ) && ! empty( $values['on'] ) && ( has_filter( self::CODE_HOOK ) || self::shop_follows( $values, $shop, $addon ) ) );
	}

	/**
	 * Whether a product key may follow the translated Shop page (3.9): the WooCommerce group and a product key on, a Shop
	 * page set. Its value needs no typed word, so it keeps the triggers; no map is published until a translation differs.
	 *
	 * @param array<mixed> $values Stored values.
	 * @param int|null     $shop   Shop page id (null: get_option()).
	 * @param bool|null    $addon  The WooCommerce add-on runs (null: this request's add-ons).
	 * @return bool
	 */
	private static function shop_follows( array $values, ?int $shop = null, ?bool $addon = null ): bool {
		$groups = is_array( $values['groups'] ?? null ) ? $values['groups'] : [];
		$keys   = is_array( $values['keys'] ?? null ) ? $values['keys'] : [];
		$on     = static fn( string $key ): bool => ! is_array( $keys[ $key ] ?? null ) || (bool) ( $keys[ $key ]['on'] ?? true );

		return (bool) ( $groups['woocommerce'] ?? true ) && ( $on( 'pt:product' ) || $on( 'pta:product' ) )
			&& ( $shop ?? \PerfLocale\Admin\Typed::int( get_option( 'woocommerce_shop_page_id' ) ) ) > 0
			&& ( $addon ?? \PerfLocale\Helper::get_instance()->is_addon_active( 'woocommerce' ) );
	}

	/**
	 * Whether the network walk marks the current (switched-to) blog: from its own rows read past the caches, never this
	 * request's add-ons; a failed or unknown read marks (a mark only recompiles in that blog's own request).
	 *
	 * @param array<mixed>                      $sitewide Network-active plugins (basename => time).
	 * @param \PerfLocale\Database\OptionRead $plugins  The blog's active_plugins row.
	 * @return bool
	 */
	private static function network_uses( array $sitewide, \PerfLocale\Database\OptionRead $plugins ): bool {
		$map    = \PerfLocale\Database\OptionRead::of( Publication::MAP );
		$values = \PerfLocale\Database\OptionRead::of( Values::OPTION );

		if ( $map->failed || null !== $map->bytes || $values->failed ) {
			return true;
		}

		$shop     = \PerfLocale\Database\OptionRead::of( 'woocommerce_shop_page_id' );
		$disabled = \PerfLocale\Database\OptionRead::of( \PerfLocale\Addon\AddonRegistry::DISABLED_OPTION );
		$active   = array_merge( array_keys( $sitewide ), \PerfLocale\Admin\Typed::strs( maybe_unserialize( (string) $plugins->bytes ) ) );
		$woo      = $plugins->failed || [] !== array_filter( $active, static fn( mixed $p ): bool => is_string( $p ) && ( 'woocommerce.php' === $p || str_ends_with( $p, '/woocommerce.php' ) ) );
		$addon    = $woo && ( $disabled->failed || ! in_array( 'woocommerce', \PerfLocale\Admin\Typed::strs( maybe_unserialize( (string) $disabled->bytes ) ), true ) );
		$shop_id  = $shop->failed ? 1 : \PerfLocale\Admin\Typed::int( maybe_unserialize( (string) $shop->bytes ) );

		return self::uses( null !== $values->bytes ? maybe_unserialize( $values->bytes ) : false, $shop_id, $addon );
	}

	/**
	 * A unit deleted the marker row this request (its cached forms go with the next clear_none() outside a unit).
	 *
	 * @var bool
	 */
	private static bool $cleared = false;

	/**
	 * Delete LifecycleHooks::NONE and confirm past every cache that it is gone; unconfirmed, the marker is not trusted
	 * for the rest of the request. Runs before and after every values or map write the plugin makes.
	 *
	 * @return bool Whether the marker is confirmed gone.
	 */
	public static function clear_none(): bool {
		global $wpdb;

		// No unit can be open while its class is not loaded.
		$tx = class_exists( \PerfLocale\Database\Tx::class, false ) ? \PerfLocale\Database\Tx::current() : null;

		if ( null !== $tx && $wpdb instanceof \wpdb ) {
			// Inside a unit: a guarded DELETE whose error ends the unit; no cache edit and no action before COMMIT (the
			// writer's clear_none() after COMMIT does them).
			\PerfLocale\Database\OptionWriter::touch( LifecycleHooks::NONE );
			self::$cleared = 1 === $tx->query( ( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, LifecycleHooks::NONE ) ?? '' ) . $tx->and() ) || self::$cleared;
		} else {
			delete_option( LifecycleHooks::NONE );
		}

		$left = $wpdb instanceof \wpdb
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The confirmation must not see a cache.
			? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, LifecycleHooks::NONE ) )
			: null;

		if ( '0' === ( is_scalar( $left ) ? (string) $left : null ) ) {
			// A unit deleted the row: delete_option() returned before its cache delete, so the cached marker goes here.
			if ( null === $tx && self::$cleared ) {
				self::$cleared = false;
				wp_cache_delete( LifecycleHooks::NONE, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}

			return true;
		}

		LifecycleHooks::$distrust = true;
		wp_cache_delete( 'alloptions', 'options' );

		return false;
	}

	/**
	 * Drop a marker left beside a values or map row (a restore or import past the option hooks); read past every cache.
	 * Runs daily (perflocale_jobs_gc), on the URL bases tab, in Site Health and after an import.
	 *
	 * @return void
	 */
	public static function validate_none(): void {
		global $wpdb;

		// No marker readers can see, nothing to retire (cold paths only, so a missed row may cost a read).
		if ( ! $wpdb instanceof \wpdb || false === get_option( LifecycleHooks::NONE, false ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A restore past the hooks leaves the cache unaware.
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name IN (%s, %s, %s)', $wpdb->options, LifecycleHooks::NONE, self::MAP_OPTION, self::VALUES_OPTION ) );

		if ( '' !== $wpdb->last_error ) {
			LifecycleHooks::$distrust = true;

			return;
		}

		if ( ! in_array( self::VALUES_OPTION, $rows, true ) && ! in_array( self::MAP_OPTION, $rows, true ) ) {
			return;
		}

		self::clear_none();

		// A restore can leave the marker only in alloptions, where delete_option() does not reach it.
		if ( ! wp_cache_delete( 'alloptions', 'options' ) ) {
			LifecycleHooks::$distrust = true;
		}
	}

	/**
	 * Whether PerfLocale was set up on this blog (version stamped): a listener firing while another plugin or core
	 * switched to a blog it never ran on writes no marker there. Absent from the cache is read past it (a failed load
	 * caches absence too); a failed read is not set up.
	 *
	 * @return bool
	 */
	private static function set_up(): bool {
		if ( false !== get_option( 'perflocale_db_version', false ) ) {
			return true;
		}

		$wpdb = \PerfLocale\Admin\Typed::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Past the option cache; prepared.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, 'perflocale_db_version' ) );

		return null !== $raw && '' === $wpdb->last_error;
	}

	/**
	 * Whether a post's address may collide with a configured base or belongs to the shop page tree.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function post_hits( \WP_Post $post ): bool {
		// Nothing configured: no base, map or value an address could hit.
		if ( ! self::configured() ) {
			return false;
		}

		if ( 'publish' === $post->post_status && self::suffix_hit( Validator::norm( $post->post_name ), $post->post_type ) ) {
			return true;
		}

		if ( 'page' === $post->post_type ) {
			$shop = \PerfLocale\Admin\Typed::int( get_option( 'woocommerce_shop_page_id' ) );

			if ( $shop > 0 ) {
				$tree = array_merge( [ $post->ID ], get_post_ancestors( $post ) );
				$shop = array_merge( [ $shop ], array_values( ( new Content() )->translations( $shop ) ) );

				if ( [] !== array_intersect( $tree, $shop ) ) {
					return true;
				}
			}

			$uri = get_page_uri( $post );

			return is_string( $uri ) && '' !== $uri && in_array( Validator::norm( explode( '/', $uri )[0] ), self::first_segments(), true );
		}

		if ( 'post' === $post->post_type && 'post' === self::lead()['kind'] ) {
			return in_array( Validator::norm( $post->post_name ), self::first_segments(), true );
		}

		return false;
	}

	/**
	 * Whether a slug is a page suffix an off shop key leaves to the page (map shr, pgs): a post of that key's type or a
	 * term (null type) named so must recompile, as the compiler then keeps its URLs aliases.
	 *
	 * @param string      $slug Slug in norm form.
	 * @param string|null $type Post type, or null for a term.
	 * @return bool
	 */
	private static function suffix_hit( string $slug, ?string $type ): bool {
		$map = \PerfLocale\Admin\Typed::map( get_option( Publication::MAP ) );

		if ( '' === $slug ) {
			return false;
		}

		foreach ( array_keys( \PerfLocale\Admin\Typed::map( $map['off'] ?? null ) ) as $key ) {
			$k = \PerfLocale\Admin\Typed::map( \PerfLocale\Admin\Typed::map( $map['keys'] ?? null )[ $key ] ?? null );

			if ( null !== $type && ! in_array( $key, [ 'pt:' . $type, 'pta:' . $type ], true ) ) {
				continue;
			}

			if ( isset( \PerfLocale\Admin\Typed::arr( $k['shr'] ?? null )[ $slug ] ) ) {
				return true;
			}

			foreach ( null !== $type ? \PerfLocale\Admin\Typed::strs( $k['pgs'] ?? null ) : [] as $tail ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A stored rule's tail; a malformed one is skipped.
				if ( 1 === @preg_match( '#^' . $tail . '#', '/' . $slug ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * First segments of every configured value (stored values, histories, map entries), suspended ones included, and of
	 * every key's effective base (its front).
	 *
	 * @return list<string>
	 */
	private static function first_segments(): array {
		/** @var array<int, list<string>> $memo */
		static $memo = [];

		$blog = get_current_blog_id();

		if ( isset( $memo[ $blog ] ) ) {
			return $memo[ $blog ];
		}

		$out = [];

		foreach ( Values::get()['keys'] as $entry ) {
			foreach ( $entry['tr'] ?? [] as $value ) {
				$out[] = explode( '/', $value )[0];
			}

			foreach ( $entry['hist'] ?? [] as $list ) {
				foreach ( $list as $value ) {
					$out[] = explode( '/', $value )[0];
				}
			}
		}

		$map = get_option( Publication::MAP );

		if ( is_array( $map ) && is_array( $map['alias'] ?? null ) ) {
			foreach ( array_keys( $map['alias'] ) as $first ) {
				$out[] = (string) $first;
			}
		}

		// Values sit after their key's front (`blog/` of /blog/%postname%/), where the compiler checks pages too.
		foreach ( is_array( $map ) ? \PerfLocale\Admin\Typed::arr( $map['keys'] ?? null ) : [] as $k ) {
			$eff = \PerfLocale\Admin\Typed::str( \PerfLocale\Admin\Typed::arr( $k )['eff'] ?? null );

			if ( '' !== $eff ) {
				$out[] = explode( '/', $eff )[0];
			}
		}

		$memo[ $blog ] = array_values( array_unique( $out ) );

		return $memo[ $blog ];
	}
}
