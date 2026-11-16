<?php
/**
 * REST boot: route-aware registration of the perflocale/v1 routes, the REST fields and the URL-bases listener.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Api;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded by a literal require at rest_api_init (outside the classmap), so admin pages do not compile it.
 *
 * @internal
 */
final class RestBoot {

	/**
	 * Servers that hold the perflocale/v1 routes (a replaced server registers them again).
	 *
	 * @var \WeakMap<\WP_REST_Server, true>|null
	 */
	private static ?\WeakMap $routed = null;

	/**
	 * Routes and fields on rest_api_init; an HTTP REST request to another namespace skips the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		// The route core is about to serve; empty outside an HTTP REST request (CLI, cron, editor preload): eager there.
		$wp    = $GLOBALS['wp'] ?? null;
		$route = $wp instanceof \WP ? ( $wp->query_vars['rest_route'] ?? '' ) : '';

		if ( is_string( $route ) && '' !== $route && ! self::needs_routes( $route ) ) {
			// A nested request (rest_do_request, _embed, preload) to perflocale/v1 still finds the routes.
			add_filter( 'rest_pre_dispatch', [ self::class, 'routes_on_dispatch' ], PHP_INT_MIN, 3 );
		} else {
			self::register_routes();
		}

		static $fields = false;

		if ( $fields ) {
			return;
		}

		$fields = true;

		// The language of each navigation menu in the Site Editor's edit-context data.
		( new NavigationLanguageField() )->register();

		// A term update that also sends the URL slug field lets that field decide the rename's follow.
		foreach ( Plugin::get_instance()->settings()->get_translatable_taxonomies() as $taxonomy ) {
			add_filter( "rest_pre_insert_{$taxonomy}", [ self::class, 'term_rest_pre_insert' ], 10, 2 );
		}

		// A term's URL slug in its language (edit context; the callbacks load Admin\TermUrlSlugField).
		register_rest_field(
			Plugin::get_instance()->settings()->get_translatable_taxonomies(),
			'perflocale_url_slug',
			[
				'get_callback'    => [ \PerfLocale\Admin\TermUrlSlugField::class, 'rest_get' ],
				'update_callback' => [ \PerfLocale\Admin\TermUrlSlugField::class, 'rest_update' ],
				'schema'          => [
					'description' => __( 'The term\'s URL slug in its own language; the old one keeps redirecting.', 'perflocale' ),
					'type'        => 'string',
					'context'     => [ 'edit' ],
				],
			]
		);

		// Only where URL bases are in use (LifecycleHooks::in_use(), inline so other requests load nothing).
		$all = wp_load_alloptions();

		if ( isset( $all['perflocale_url_bases_map'] ) || isset( $all['perflocale_url_bases_dirty'] ) ) {
			add_filter( 'rest_pre_dispatch', [ self::class, 'url_bases_on_rest' ], 10, 3 );
		} else {
			// A Shop page set later may start them (LifecycleHooks::on_shop_option(), loaded then).
			add_action( 'add_option_woocommerce_shop_page_id', [ \PerfLocale\Router\UrlBases\LifecycleHooks::class, 'on_shop_option' ] );
			add_action( 'update_option_woocommerce_shop_page_id', [ \PerfLocale\Router\UrlBases\LifecycleHooks::class, 'on_shop_option' ] );
		}
	}

	/**
	 * Whether a request to this route can reach perflocale/v1: the index, the namespace, or batch/v1 (it matches its
	 * items before rest_pre_dispatch). Case-insensitive and newline-trimmed, as core's `$@i` route match is.
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	private static function needs_routes( string $route ): bool {
		$r = strtolower( trim( $route, "/\n" ) );

		return '' === $r || 'batch/v1' === $r || 'perflocale/v1' === $r || str_starts_with( $r, 'perflocale/v1/' );
	}

	/**
	 * `rest_pre_dispatch` (PHP_INT_MIN, before core's OPTIONS handler): register the routes for a request that needs them.
	 *
	 * @param mixed $result  Short-circuit value (returned unchanged).
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public static function routes_on_dispatch( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if ( $request instanceof \WP_REST_Request && self::needs_routes( $request->get_route() ) ) {
			self::register_routes();
		}

		unset( $server );

		return $result;
	}

	/**
	 * Build and register the perflocale/v1 controllers once per server.
	 *
	 * @return void
	 */
	private static function register_routes(): void {
		$server         = rest_get_server();
		self::$routed ??= new \WeakMap();

		if ( isset( self::$routed[ $server ] ) ) {
			return;
		}

		self::$routed[ $server ] = true;

		$controllers = [
			new LanguagesController(),
			new TranslationsController(),
			new StringsController(),
			new XliffController(),
			new WebhookController(),
			new JobsController(),
		];

		// MT routes register unconditionally: the handlers check mt_enabled() per request and
		// answer `mt_disabled`, not `rest_no_route`, to an editor that cached the route.
		$controllers[] = new MachineTranslateController();
		$controllers[] = new BlockTranslateController();

		// The public edge-config endpoint exists only when edge integration is enabled.
		if ( Plugin::get_instance()->settings()->edge_integration_enabled() ) {
			$controllers[] = new ConfigController();
		}

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Forward rest_pre_insert_{taxonomy} to TermSlugFollow, which is outside the class map and so required here.
	 *
	 * @param mixed $prepared Term prepared for the database (returned unchanged).
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public static function term_rest_pre_insert( mixed $prepared, mixed $request = null ): mixed {
		require_once __DIR__ . '/../Translation/TermSlugFollow.php';

		return \PerfLocale\Translation\TermSlugFollow::rest_request( $prepared, $request );
	}

	/**
	 * `rest_pre_dispatch`: URL bases compile triggers for a write to a route that changes a compile input, by a user
	 * who may write one (as AJAX, h10 #12); never for the Store API, a read or another plugin's route.
	 *
	 * @param mixed $result  Short-circuit value (returned unchanged).
	 * @param mixed $server  REST server.
	 * @param mixed $request Request.
	 * @return mixed
	 */
	public static function url_bases_on_rest( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if ( $request instanceof \WP_REST_Request && ! in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true )
			&& is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) )
			&& self::url_bases_route( $request->get_route() ) ) {
			\PerfLocale\Router\UrlBases\LifecycleHooks::register();
		}

		unset( $server );

		return $result;
	}

	/**
	 * Whether a REST route writes a URL bases compile input: core content, users and settings, WooCommerce products
	 * and settings, PerfLocale's routes. Core filters each batch item on its own, so the batch route itself is no.
	 *
	 * @param string $route Route.
	 * @return bool
	 */
	private static function url_bases_route( string $route ): bool {
		if ( 1 === preg_match( '#^/(?:perflocale/v1|wc/v3/(?:products|settings))(?:/|$)#', $route ) ) {
			return true;
		}

		if ( 1 !== preg_match( '#^/wp/v2/([^/]+)#', $route, $m ) ) {
			return false;
		}

		if ( in_array( $m[1], [ 'posts', 'pages', 'media', 'categories', 'tags', 'users', 'settings' ], true ) ) {
			return true;
		}

		foreach ( array_merge( get_post_types( [ 'show_in_rest' => true ], 'objects' ), get_taxonomies( [ 'show_in_rest' => true ], 'objects' ) ) as $object ) {
			$base = is_string( $object->rest_base ) && '' !== $object->rest_base ? $object->rest_base : $object->name;

			if ( $m[1] === $base ) {
				return true;
			}
		}

		return false;
	}
}
