<?php
/**
 * REST request detection for service eagerness.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Util;

use PerfLocale\Bootstrap;
use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only when Helper::is_rest_request() flags the URL, from Bootstrap's write-services decision.
 * Core references and the fail-safe: docs-ai/11-code-rationale.md, "REST read diet".
 *
 * @internal
 */
final class RestRead {

	/**
	 * The page-only services Bootstrap::register_frontend_services() skips on a REST request.
	 *
	 * @var list<string>
	 */
	public const PAGE_SERVICES = [ 'frontend_assets', 'slug_redirector', 'content_language_header', 'view_transitions', 'speculation_rules', 'fallback_snippet_guard' ];

	/**
	 * Whether the net booted the page services.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Whether the write services boot up front; false only on a REST read.
	 *
	 * On a REST request also sets Bootstrap::$rest and arms the net that boots the page services if no REST response comes.
	 *
	 * @return bool
	 */
	public static function writes(): bool {
		if ( ! self::is_rest() ) {
			return true;
		}

		Bootstrap::$rest = true;

		// The first points where core has decided (WP::main), then the first page output outside it.
		add_action( 'parse_request', [ self::class, 'boot_unless_rest' ], PHP_INT_MIN );
		add_action( 'send_headers', [ self::class, 'boot_page_services' ], PHP_INT_MIN );
		add_filter( 'render_block_perflocale/language-switcher', [ self::class, 'boot_on_render' ], PHP_INT_MIN );
		add_action( 'wp_head', [ self::class, 'boot_page_services' ], PHP_INT_MIN );

		return ! self::is_read();
	}

	/**
	 * Whether core's router will serve REST (WP::parse_request(), rest_api_loaded()), as far as plugin load can tell.
	 *
	 * False when unsure. The home path is stripped at a segment boundary only, and the prefix compared literally.
	 *
	 * @return bool
	 */
	public static function is_rest(): bool {
		// index.php: the entry point REST uses (wp-signup.php and friends also call wp(): a missed saving only).
		// A network-activated plugin loads before vars.php sets $pagenow: PHP_SELF, as vars.php reads it.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Matched against a fixed pattern only.
		$php_self = $_SERVER['PHP_SELF'] ?? '';
		$self     = $GLOBALS['pagenow'] ?? ( is_string( $php_self ) && preg_match( '#([^/]+\.php)([?/].*?)?$#i', $php_self, $m ) ? strtolower( $m[1] ) : 'index.php' );

		if ( 'index.php' !== $self || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Read for this load decision only.
		// A rest_route query var decides over the path, $_POST before $_GET; rest_api_loaded() needs a non-empty string.
		if ( isset( $_POST['rest_route'] ) || isset( $_GET['rest_route'] ) ) {
			$route = $_POST['rest_route'] ?? $_GET['rest_route'];

			return is_string( $route ) && '' !== $route && '0' !== $route;
		}

		$uri  = $_SERVER['REQUEST_URI'] ?? null;
		$info = $_SERVER['PATH_INFO'] ?? '';
		// phpcs:enable

		$prefix = (string) rest_get_url_prefix();

		// Plain permalinks have no rewrite rules: only rest_route makes REST there. Core uses the prefix as a regex.
		if ( ! is_string( $uri ) || ! is_string( $info ) || ! preg_match( '#^[A-Za-z0-9._~/-]+$#', $prefix ) || ! get_option( 'permalink_structure' ) ) {
			return false;
		}

		// The path WP::parse_request() matches its rules against.
		$info = str_replace( '%', '%25', explode( '?', $info )[0] );
		// The raw option: home_url() filters may need pluggable functions not loaded yet. A late difference meets the net.
		$home = get_option( 'home' );
		$home = is_string( $home ) ? trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' ) : '';
		$uri  = self::below( trim( str_replace( $info, '', explode( '?', $uri )[0] ), '/' ), $home );
		$info = self::below( trim( $info, '/' ), $home );

		if ( null === $uri || null === $info ) {
			return false;
		}

		$path = '' !== $info && '0' !== $info && ! preg_match( '|^.*index.php$|', $info ) ? $info : ( 'index.php' === $uri ? '' : $uri );

		// rest_api_register_rewrites(): the prefix alone or followed by '/', also under index.php/; core tries urldecode() too.
		// RewriteManager copies each rule under every active language's URL prefix: one leading segment.
		$bases = [ $prefix, 'index.php/' . $prefix ];
		foreach ( self::language_prefixes() as $lang ) {
			$bases[] = $lang . '/' . $prefix;
			$bases[] = $lang . '/index.php/' . $prefix;
		}

		foreach ( [ $path, urldecode( $path ) ] as $candidate ) {
			foreach ( $bases as $base ) {
				if ( $candidate === $base || str_starts_with( $candidate, $base . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The URL prefixes RewriteManager::filter_rewrite_rules() puts in front of every rule (subdirectory mode only).
	 *
	 * The same calls build_language_regex() makes; the active list is the bootstrap row detect_locale_early() read.
	 *
	 * @return list<string>
	 */
	private static function language_prefixes(): array {
		$plugin = Plugin::get_instance();

		if ( 'subdirectory' !== $plugin->settings()->get_url_mode() || ! $plugin->has( 'lang_repo' ) || ! \PerfLocale\Database\Schema::tables_exist() ) {
			return [];
		}

		$out = [];

		foreach ( $plugin->lang_repo()->get_active() as $lang ) {
			$lang_prefix = $plugin->settings()->get_url_prefix( $lang );

			if ( '' !== $lang_prefix ) {
				$out[] = $lang_prefix;
			}
		}

		return $out;
	}

	/**
	 * GET or HEAD with no method override: core honours `_method` (query or body) and X-HTTP-Method-Override.
	 *
	 * @return bool
	 */
	public static function is_read(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared to two literals only.

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Presence check only.
		return ( 'GET' === $method || 'HEAD' === $method ) && ! isset( $_GET['_method'] ) && ! isset( $_POST['_method'] ) && ! isset( $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] );
	}

	/**
	 * `parse_request` callback ahead of rest_api_loaded(): boots the page services unless core is about to serve REST.
	 *
	 * @param mixed $wp Core's WP instance.
	 * @return void
	 */
	public static function boot_unless_rest( $wp = null ): void {
		$route = is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ? ( $wp->query_vars['rest_route'] ?? null ) : null;

		if ( empty( $route ) || ! is_string( $route ) ) {
			self::boot_page_services();
		}
	}

	/**
	 * Render filter of the switcher block (Assets enqueues on it): boots the page services, returns the content.
	 *
	 * @param mixed $content Block content.
	 * @return mixed
	 */
	public static function boot_on_render( $content ) {
		self::boot_page_services();

		return $content;
	}

	/**
	 * Boot the skipped page services as Plugin::boot() does; once per request.
	 *
	 * The two emitters return from register_hooks() at once when their setting is off.
	 *
	 * @return void
	 */
	public static function boot_page_services(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		$plugin       = Plugin::get_instance();

		foreach ( self::PAGE_SERVICES as $id ) {
			if ( ! $plugin->has( $id ) ) {
				continue;
			}

			$service = $plugin->get( $id );

			if ( method_exists( $service, 'register_hooks' ) ) {
				$service->register_hooks();
			}
		}
	}

	/**
	 * The path below the home path (case-insensitive, as core); unchanged outside it; null mid-segment (core strips there).
	 *
	 * @param string $path Trimmed request path.
	 * @param string $home Trimmed home path.
	 * @return string|null
	 */
	private static function below( string $path, string $home ): ?string {
		$len = strlen( $home );

		if ( 0 === $len || 0 !== strncasecmp( $path, $home, $len ) ) {
			return $path;
		}

		return isset( $path[ $len ] ) && '/' !== $path[ $len ] ? null : ltrim( substr( $path, $len ), '/' );
	}
}
