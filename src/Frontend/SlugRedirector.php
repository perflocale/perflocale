<?php
/**
 * 301 redirect handler for renamed language slugs.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Database\Repository\LanguageRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 301s a request whose path prefix is a renamed language slug to the new slug's prefix.
 *
 * The map is written by LanguageRepository::rename_slug() under LanguageRepository::REDIRECTS_OPTION. The matching runs
 * in SlugRedirectWorker, loaded only when the map is not empty (almost never on a page).
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

		( new SlugRedirectWorker() )->redirect( $redirects );
	}
}
