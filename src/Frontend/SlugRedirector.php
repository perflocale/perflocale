<?php
/**
 * 301 redirect handler for renamed language slugs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Database\Repository\LanguageRepository;
use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 301s a request whose path prefix is a renamed language slug to the new slug's prefix.
 *
 * The map is written by LanguageRepository::rename_slug() under LanguageRepository::REDIRECTS_OPTION.
 */
final class SlugRedirector {

	/**
	 * Register the redirect listener.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// `wp` runs before the plugin's template_redirect chain; priority 1 so no theme output comes first.
		add_action( 'wp', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * 301 a path that begins with a renamed slug to the new language's configured prefix.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		// Admin, REST, AJAX, CLI and cron use no slug prefix.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}

		$redirects = LanguageRepository::get_slug_redirects();

		if ( empty( $redirects ) ) {
			return;
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$settings = $plugin->has( 'settings' ) ? $plugin->get( 'settings' ) : null;

		if ( $settings instanceof Settings ) {
			$mode = $settings->get_url_mode();

			// Only subdirectory mode has the slug in the path; elsewhere a slug-like segment is a page path.
			if ( $mode !== 'subdirectory' ) {
				return;
			}
		}

		// Not sanitize_text_field(): it strips %XX sequences and would corrupt non-ASCII tails.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		if ( $request_uri === '' ) {
			return;
		}

		// Raw `home` option, not home_url(): the plugin's filter may add the default prefix (/en-us/en-us/foo).
		$home_url_raw = (string) get_option( 'home', '' );
		$home_path    = (string) wp_parse_url( $home_url_raw, PHP_URL_PATH );
		$home_path    = $home_path === '' ? '/' : $home_path;
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$query_string = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( $request_path === '' ) {
			return;
		}

		// Match against the path relative to the install root.
		$relative = $request_path;

		if ( $home_path !== '/' && str_starts_with( $request_path, $home_path ) ) {
			$relative = '/' . ltrim( substr( $request_path, strlen( $home_path ) ), '/' );
		}

		// Empty path or pure root is never a slug-prefix match.
		if ( $relative === '' || $relative === '/' ) {
			return;
		}

		// The first path segment must look like a language slug.
		if ( ! preg_match( '#^/([a-z]{2,3}(?:-[a-z]{2,3})?)(/.*)?$#i', $relative, $m ) ) {
			return;
		}

		$candidate = strtolower( $m[1] );

		if ( ! isset( $redirects[ $candidate ] ) ) {
			return;
		}

		$new_slug = (string) $redirects[ $candidate ];

		// Re-validate the stored target: a corrupted option must not inject a path.
		if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/', $new_slug ) ) {
			return;
		}

		// Renaming back and forth can leave a self-mapping: no loop.
		if ( $new_slug === $candidate ) {
			return;
		}

		// The map may come from a cached options list older than its row.
		// Redirect only from a prefix no active language serves to one an
		// active language serves. The router has already loaded this map.
		$lang_repo = $plugin->has( 'lang_repo' ) ? $plugin->get( 'lang_repo' ) : null;

		if ( ! $lang_repo instanceof LanguageRepository ) {
			return;
		}

		$live = $lang_repo->get_slug_map();

		if ( isset( $live[ $candidate ] ) || ! isset( $live[ $new_slug ] ) ) {
			return;
		}

		// A live locale-form prefix (de-de) is served by the router: redirecting away from it would loop.
		$router = $plugin->has( 'router' ) ? $plugin->get( 'router' ) : null;

		if ( $router instanceof LanguageRouter && $settings instanceof Settings ) {
			$served = $router->slug_for_path_prefix( $candidate );

			if ( $served !== null && isset( $live[ $served ] ) && $settings->get_url_prefix( $live[ $served ] ) === $candidate ) {
				return;
			}
		}

		$tail = $m[2] ?? '';

		// A query in the tail would be appended twice.
		$qpos = strpos( $tail, '?' );
		if ( $qpos !== false ) {
			$tail = substr( $tail, 0, $qpos );
		}

		// No CR, LF or NUL may reach the Location header.
		if ( $tail !== '' && preg_match( '/[\r\n\0]/', $tail ) ) {
			return;
		}

		$skip_prefix = $this->should_skip_prefix_for_default( $new_slug );

		// The target carries the new language's configured prefix, so a
		// locale-format site gets no second 301 from slug form to locale form.
		$new_prefix     = $settings instanceof Settings ? $settings->get_url_prefix( $live[ $new_slug ] ) : $new_slug;
		$prefix_segment = $skip_prefix ? '' : ( '/' . $new_prefix );
		$new_path       = rtrim( $home_path, '/' ) . $prefix_segment . $tail;

		// Unprefixed default with no tail: the install root.
		if ( $new_path === '' ) {
			$new_path = '/';
		}

		$target = $new_path . ( $query_string !== '' ? '?' . $query_string : '' );

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Whether the target drops the prefix: the new slug is the hidden-prefix default, saving a second 301.
	 *
	 * @param string $new_slug Resolved target slug from the redirect map.
	 * @return bool
	 */
	private function should_skip_prefix_for_default( string $new_slug ): bool {
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) || ! $plugin->has( 'settings' ) ) {
			return false;
		}

		$settings = $plugin->get( 'settings' );

		if ( ! $settings->hide_default_prefix() ) {
			return false;
		}

		$mode = $settings->get_url_mode();

		if ( $mode !== 'subdirectory' ) {
			return false;
		}

		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$default   = $lang_repo->get_default();

		return $default && (string) $default->slug === $new_slug;
	}
}
