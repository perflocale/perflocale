<?php
/**
 * Admin: the Content ReSync links and buttons on screens.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Resync\RunRequest;
use PerfLocale\Resync\Scope;
use PerfLocale\Resync\Store;
use PerfLocale\Util\Scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a screen needs to show a Content ReSync control (edit screen, Translations page, source panel); the
 * handlers behind them are in ResyncEntry, so showing a control does not load them.
 *
 * @since 1.0.8
 */
final class ResyncLinks {

	/** The nonce action of the per-post link. */
	public const NONCE = 'perflocale_resync';

	/**
	 * Types the current user may resync, per blog and user (render checks).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static array $allowed = [];

	/**
	 * `perflocale_translate` per blog and user, asked once per request.
	 *
	 * @var array<string, bool>
	 */
	private static array $can = [];

	/**
	 * The admin-post.php URL per blog.
	 *
	 * @var array<int, string>
	 */
	private static array $post_urls = [];

	/**
	 * The per-post link's nonce, per blog and user.
	 *
	 * @var array<string, string>
	 */
	private static array $nonces = [];

	/**
	 * Whether the current user may press "Resync from source" on a post of this type; every per-post control
	 * is rendered only when this holds.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function allowed_for_type( string $post_type ): bool {
		$blog = get_current_blog_id() . ':' . get_current_user_id();

		if ( isset( self::$allowed[ $blog ][ $post_type ] ) ) {
			return self::$allowed[ $blog ][ $post_type ];
		}

		$ok = '' !== $post_type
			&& self::can_translate()
			&& Store::available()
			&& in_array( $post_type, self::scope_types(), true );

		self::$allowed[ $blog ][ $post_type ] = $ok;

		return $ok;
	}

	/**
	 * The per-post link: the handler's arguments without the nonce (a click posts it).
	 *
	 * @param int    $post_id The post the button is on (a source or a translation).
	 * @param string $lang    A language slug, or `all`.
	 * @param string $back    edit, translations or referer.
	 * @return string
	 */
	public static function resync_url( int $post_id, string $lang, string $back ): string {
		return add_query_arg(
			[
				'action' => 'perflocale_resync',
				'post'   => $post_id,
				'lang'   => '' === $lang ? 'all' : $lang,
				'return' => in_array( $back, [ 'edit', 'translations', 'referer' ], true ) ? $back : 'referer',
			],
			self::post_url()
		);
	}

	/**
	 * The nonce a click on a handler link posts; '' for any other URL (a link a filter pointed elsewhere gets none).
	 *
	 * @param string $url The link.
	 * @return string
	 */
	public static function post_link_nonce( string $url ): string {
		if ( ! str_starts_with( $url, self::post_url() . '?' ) ) {
			return '';
		}

		$args = [];
		parse_str( Scalar::str( wp_parse_url( $url, PHP_URL_QUERY ) ), $args );

		$action = Scalar::str( $args['action'] ?? '' );

		if ( 'perflocale_resync' === $action ) {
			return self::nonce();
		}

		if ( ! in_array( $action, [ 'perflocale_resync_start', 'perflocale_resync_cancel' ], true ) ) {
			return '';
		}

		$token = RunRequest::valid_token( Scalar::str( $args['token'] ?? '' ) );

		return '' === $token ? '' : wp_create_nonce( $action . '_' . $token );
	}

	/**
	 * The attribute that makes a click on a handler link post it with its nonce (read by admin-actions.js,
	 * enqueued here); '' for any other URL.
	 *
	 * @param string $url The link.
	 * @return string The attribute with a leading space, escaped.
	 */
	public static function post_link_attributes( string $url ): string {
		$nonce = self::post_link_nonce( $url );

		if ( '' === $nonce ) {
			return '';
		}

		if ( ! wp_script_is( 'perflocale-admin-actions', 'enqueued' ) ) {
			wp_enqueue_script( 'perflocale-admin-actions', Scalar::str( constant( 'PERFLOCALE_URL' ) ) . 'assets/js/admin-actions.js', [], Scalar::str( constant( 'PERFLOCALE_VERSION' ) ), true );
		}

		return ' data-perflocale-post="' . esc_attr( $nonce ) . '"';
	}

	/**
	 * The per-post link's nonce (one per user and session).
	 *
	 * @return string
	 */
	public static function nonce(): string {
		$key = get_current_blog_id() . ':' . get_current_user_id();

		if ( ! isset( self::$nonces[ $key ] ) ) {
			self::$nonces[ $key ] = wp_create_nonce( self::NONCE );
		}

		return self::$nonces[ $key ];
	}

	/**
	 * What the block editor and Site Editor need to build the link; null when the handler would refuse.
	 *
	 * @param string               $post_type The edited type ('' in the Site Editor: any of its types in scope).
	 * @param array<int, string>   $types     The Site Editor's types, when `$post_type` is ''.
	 * @param \WP_Post|null        $post      The edited post (the block editor; null in the Site Editor, which has none at load).
	 * @param array<string, mixed> $map       The edited post's translations by language slug, as the screen already read them.
	 * @return array{url: string, nonce: string, types: list<string>}|null
	 */
	public static function editor_config( string $post_type, array $types = [], ?\WP_Post $post = null, array $map = [] ): ?array {
		$candidates = '' === $post_type ? $types : [ $post_type ];
		$allowed    = array_values( array_filter( $candidates, static fn ( string $type ): bool => self::allowed_for_type( $type ) ) );

		if ( [] === $allowed || ( null !== $post && null === self::source_of( $post, $map ) ) ) {
			return null;
		}

		return [
			'url'   => self::post_url(),
			'nonce' => self::nonce(),
			'types' => $allowed,
		];
	}

	/**
	 * The source a per-post button on this post resyncs from, or null when the handler would refuse it (trashed,
	 * unreadable source text, out of scope). Reads only the source post: the translations come from the caller.
	 *
	 * @param \WP_Post             $post The post the button is on (a source or a translation).
	 * @param array<string, mixed> $map  Its group's members by language slug.
	 * @return \WP_Post|null
	 */
	public static function source_of( \WP_Post $post, array $map ): ?\WP_Post {
		if ( in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return null;
		}

		$dslug  = Scalar::str( Scalar::prop( \PerfLocale\Plugin::get_instance()->lang_repo()->get_default(), 'slug' ) );
		$source = '' === $dslug ? 0 : Scalar::int( $map[ $dslug ] ?? 0 );
		$spost  = $source === $post->ID ? $post : ( $source > 0 ? get_post( $source ) : null );

		if ( ! $spost instanceof \WP_Post || in_array( $spost->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return null;
		}

		return \PerfLocale\Util\ObjectPermission::read_text( $spost ) && self::covers( $spost ) ? $spost : null;
	}

	/**
	 * Whether Content ReSync covers a source: its type is in scope and `perflocale/resync/include_object` keeps it.
	 *
	 * @param \WP_Post $source The source.
	 * @return bool
	 */
	public static function covers( \WP_Post $source ): bool {
		return in_array( $source->post_type, self::scope_types(), true )
			&& Scope::includes(
				$source,
				[
					'trigger'      => 'resync',
					'language_ids' => [],
					'user_id'      => get_current_user_id(),
				]
			);
	}

	/**
	 * The post types the Content ReSync scope covers.
	 *
	 * @return list<string>
	 */
	public static function scope_types(): array {
		$types = Scope::object_types(
			[
				'trigger'      => 'resync',
				'language_ids' => [],
				'user_id'      => get_current_user_id(),
			]
		);

		return array_values( array_diff( $types, [ Scope::CUSTOM_FIELDS ] ) );
	}

	/**
	 * The admin-post.php URL on this blog, built once per request (each build reads `siteurl`; a screen builds one per link).
	 *
	 * @return string
	 */
	private static function post_url(): string {
		return self::$post_urls[ get_current_blog_id() ] ??= admin_url( 'admin-post.php' );
	}

	/**
	 * Whether the current user has `perflocale_translate` on this blog; ReviewUpdate asks it on the same screens.
	 *
	 * @return bool
	 */
	public static function can_translate(): bool {
		$key = get_current_blog_id() . ':' . get_current_user_id();

		if ( [] === self::$can && [] === self::$allowed ) {
			self::watch_caps();
		}

		return self::$can[ $key ] ??= current_user_can( 'perflocale_translate' );
	}

	/**
	 * Drop the capability answers when a user, role or capability changes in this request (one callback per hook).
	 *
	 * @return void
	 */
	private static function watch_caps(): void {
		global $wpdb;

		$hooks = [ 'set_current_user', 'set_user_role', 'add_user_role', 'remove_user_role', 'granted_super_admin', 'revoked_super_admin', 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ];

		if ( $wpdb instanceof \wpdb ) {
			$hooks[] = 'update_option_' . $wpdb->prefix . 'user_roles';
		}

		foreach ( $hooks as $hook ) {
			add_action( $hook, [ self::class, 'forget_caps' ] );
		}
	}

	/**
	 * Forget the capability answers of every blog and user.
	 *
	 * @internal Hooked by watch_caps().
	 * @return void
	 */
	public static function forget_caps(): void {
		self::$can     = [];
		self::$allowed = [];
	}

	/**
	 * Whether the current user may choose machine translated drafts.
	 *
	 * @param bool|null $mt_available AdminController::bulk_mt_translate_available() when the caller already has it.
	 * @return bool
	 */
	public static function machine_fill_allowed( ?bool $mt_available = null ): bool {
		return ( $mt_available ?? AdminController::bulk_mt_translate_available() ) && current_user_can( 'perflocale_use_mt' );
	}
}
