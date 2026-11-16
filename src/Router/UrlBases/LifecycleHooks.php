<?php
/**
 * URL bases: the compile triggers' hook registration.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router\UrlBases;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds Lifecycle's listeners by name, so a request where no trigger fires never loads Lifecycle; the two listeners
 * that run on most admin requests (admin_init, any option write) first run their no-query checks here.
 *
 * @internal
 * @since 1.0.8
 */
final class LifecycleHooks {

	/** Single cron event: network site paths changed. */
	public const SITES_HOOK = 'perflocale_url_bases_sites';

	/** Single cron event: an input changed where no compile can run. */
	public const COMPILE_HOOK = 'perflocale_url_bases_compile';

	/** The dirty marker option (autoloaded while URL bases are in use; in_use() reads it). */
	public const DIRTY = 'perflocale_url_bases_dirty';

	/** Core options whose stored value is a compile input; the last is Values::OPTION (naming it would load Values). */
	public const INPUT_OPTIONS = [ 'rewrite_rules', 'permalink_structure', 'category_base', 'tag_base', 'woocommerce_permalinks', 'woocommerce_shop_page_id', 'wp_attachment_pages_enabled', 'perflocale_url_bases' ];

	/** Option names never treated as provider inputs (no registry build for them). */
	public const NOT_INPUT = '/^(?:_site_transient_|_transient_|cron$|perflocale_|active_plugins$|recently_|can_compress|db_upgraded|auto_)/';

	/**
	 * Autoloaded marker of a blog with neither values nor a map, written by 1.0.8 RCs only; any write of either row
	 * deletes it. Absent (never read, failed, deleted): the rows are read as before.
	 */
	public const NONE = 'perflocale_url_bases_none';

	/** Publication::MAP and CodeValues::HOOK, as literals for the same reason. */
	private const MAP        = 'perflocale_url_bases_map';
	private const VALUE_HOOK = 'perflocale/url_bases/value';

	/**
	 * Set when a marker delete could not be confirmed: the marker is not trusted for the rest of the request.
	 *
	 * @var bool
	 */
	public static bool $distrust = false;

	/**
	 * Whether register() ran in this request (a context that may compile).
	 *
	 * @var bool
	 */
	public static bool $registered = false;

	/**
	 * Blogs a translation was linked on in this request (blog id => true).
	 *
	 * @var array<int, true>
	 */
	private static array $linked = [];

	/**
	 * Whether this blog uses URL bases: its map or dirty marker is autoloaded (no query). Unused, no trigger registers.
	 *
	 * @return bool
	 */
	public static function in_use(): bool {
		$all = wp_load_alloptions();

		return isset( $all[ self::MAP ] ) || isset( $all[ self::DIRTY ] );
	}

	/**
	 * Register the listeners where URL bases are in use.
	 *
	 * @return bool Whether they are registered.
	 */
	public static function maybe_register(): bool {
		if ( self::$registered || self::in_use() ) {
			self::register();

			return true;
		}

		add_action( 'add_option_woocommerce_shop_page_id', [ self::class, 'on_shop_option' ] );
		add_action( 'update_option_woocommerce_shop_page_id', [ self::class, 'on_shop_option' ] );

		return false;
	}

	/**
	 * A Shop page set on a blog whose triggers are not registered (write contexts hook this where URL bases are not in use):
	 * with the switch on and a product key following it, register them and compile in this request.
	 *
	 * @return void
	 */
	public static function on_shop_option(): void {
		if ( self::$registered ) {
			return;
		}

		// Past the caches; a failed read marks (marking only recompiles).
		$row = \PerfLocale\Database\OptionRead::of( 'perflocale_url_bases' );

		if ( ! $row->failed && ! Lifecycle::uses( null !== $row->bytes ? maybe_unserialize( $row->bytes ) : false ) ) {
			return;
		}

		self::register();
		Lifecycle::schedule( 'shop_page' );
	}

	/**
	 * Register the listeners once (admin, cron, WP-CLI, signed-in AJAX and REST writes).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		$l                = Lifecycle::class;

		foreach ( self::INPUT_OPTIONS as $option ) {
			add_action( "add_option_{$option}", [ $l, 'on_option' ] );
			add_action( "update_option_{$option}", [ $l, 'on_option' ] );
			add_action( "delete_option_{$option}", [ $l, 'on_option' ] );
		}

		// Provider inputs: the generic hooks, so the providers filter runs only when an option changes.
		add_action( 'added_option', [ self::class, 'on_any_option' ] );
		add_action( 'updated_option', [ self::class, 'on_any_option' ] );
		add_action( 'deleted_option', [ self::class, 'on_any_option' ] );

		foreach ( [ 'perflocale/language/added', 'perflocale/language/updated', 'perflocale/language/slug_renamed', 'perflocale/language/deleted', 'perflocale/default_language/changed' ] as $hook ) {
			add_action( $hook, [ $l, 'on_language' ] );
		}

		add_action( 'perflocale/settings/updated', [ $l, 'on_settings' ], 10, 2 );
		add_action( 'perflocale/translation/linked', [ self::class, 'on_linked' ] );

		add_action( 'post_updated', [ $l, 'on_post_updated' ], 10, 3 );
		add_action( 'transition_post_status', [ self::class, 'on_transition' ], 10, 3 );
		add_action( 'wp_trash_post', [ $l, 'on_post_removed' ] );
		add_action( 'before_delete_post', [ $l, 'on_post_removed' ] );
		add_action( 'untrashed_post', [ $l, 'on_post_removed' ] );

		add_action( 'created_term', [ $l, 'on_term' ], 10, 3 );
		add_action( 'edited_term', [ $l, 'on_term' ], 10, 3 );
		add_action( 'user_register', [ $l, 'on_user' ] );
		add_action( 'profile_update', [ $l, 'on_user' ] );

		foreach ( [ 'woocommerce_attribute_added', 'woocommerce_attribute_updated', 'woocommerce_attribute_deleted' ] as $hook ) {
			add_action( $hook, [ $l, 'on_attribute' ] );
		}

		if ( is_multisite() ) {
			add_action( 'wp_insert_site', [ $l, 'on_site' ] );
			add_action( 'wp_update_site', [ $l, 'on_site_updated' ], 10, 2 );
			add_action( 'wp_delete_site', [ $l, 'on_site' ] );
		}

		add_action( self::SITES_HOOK, [ $l, 'mark_network' ], 10, 3 );
		add_action( self::COMPILE_HOOK, [ $l, 'on_cron' ] );
		add_action( 'perflocale_jobs_gc', [ $l, 'validate_none' ] );

		if ( is_admin() && ! wp_doing_ajax() ) {
			add_action( 'admin_init', [ self::class, 'admin_check' ], 20 );
			add_action( 'admin_post_perflocale_save_url_bases', [ \PerfLocale\Admin\UrlBases\Tab::class, 'handle_save' ] );
		}
	}

	/**
	 * `added_option`, `updated_option`, `deleted_option`: a values or map write drops NONE (Lifecycle loads then);
	 * Lifecycle::on_any_option()
	 * for a name that may be an input.
	 *
	 * @param mixed $option Option name.
	 * @return void
	 */
	public static function on_any_option( mixed $option = '' ): void {
		if ( self::MAP === $option || 'perflocale_url_bases' === $option ) {
			Lifecycle::clear_none();
		}

		if ( is_string( $option ) && ! in_array( $option, self::INPUT_OPTIONS, true ) && 1 !== preg_match( self::NOT_INPUT, $option ) ) {
			Lifecycle::on_any_option( $option );
		}
	}

	/**
	 * Whether this blog is known to have no URL bases: the NONE marker in alloptions and no code values.
	 *
	 * @return bool
	 */
	public static function unconfigured(): bool {
		if ( self::$distrust || has_filter( self::VALUE_HOOK ) ) {
			return false;
		}

		$all = wp_load_alloptions();

		// A map in alloptions beside the marker (restored past the hooks): the marker is stale.
		return array_key_exists( self::NONE, $all ) && ! array_key_exists( self::MAP, $all );
	}

	/**
	 * `perflocale/translation/linked`: notes the blog; linked_shutdown() checks it once. Not per link: an import batch
	 * flushes the runtime cache, so a check here reloads the whole alloptions inside every batch.
	 *
	 * @return void
	 */
	public static function on_linked(): void {
		if ( [] === self::$linked ) {
			add_action( 'shutdown', [ self::class, 'linked_shutdown' ], 98 );
		}

		self::$linked[ get_current_blog_id() ] = true;
	}

	/**
	 * Shutdown: Lifecycle's linked check for each linked blog not known to have no URL bases (Lifecycle loads only then).
	 *
	 * @return void
	 */
	public static function linked_shutdown(): void {
		$blogs        = array_keys( self::$linked );
		self::$linked = [];
		$any          = false;

		foreach ( $blogs as $blog ) {
			$switch = get_current_blog_id() !== $blog;

			if ( $switch ) {
				switch_to_blog( $blog );
			}

			try {
				if ( ! self::unconfigured() ) {
					Lifecycle::on_linked();
					$any = true;
				}
			} finally {
				if ( $switch ) {
					restore_current_blog();
				}
			}
		}

		if ( $any ) {
			Lifecycle::linked_check();
		}
	}

	/**
	 * `transition_post_status`: Lifecycle::on_transition() for a publication or withdrawal only (not an auto-draft).
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 * @return void
	 */
	public static function on_transition( mixed $new_status = '', mixed $old_status = '', mixed $post = null ): void {
		if ( $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			Lifecycle::on_transition( $new_status, $old_status, $post );
		}
	}

	/**
	 * `admin_init`: Lifecycle::admin_check() for a manager when a marker, a map or code values exist (read from alloptions);
	 * the capability last, as it costs more than the read.
	 *
	 * @return void
	 */
	public static function admin_check(): void {
		$all = wp_load_alloptions();

		if ( ( is_string( $all[ self::DIRTY ] ?? null ) || isset( $all[ self::MAP ] ) || has_filter( self::VALUE_HOOK ) ) && current_user_can( 'manage_options' ) ) {
			Lifecycle::admin_check();
		}
	}
}
