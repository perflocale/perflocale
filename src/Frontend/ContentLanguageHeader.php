<?php
/**
 * Content-Language HTTP response header.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Router\LanguageRouter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits the `Content-Language` response header on front-end requests.
 */
final class ContentLanguageHeader {

	/** @var LanguageRouter */
	private readonly LanguageRouter $router;

	/** @var Settings */
	private readonly Settings $settings;

	public function __construct( LanguageRouter $router, Settings $settings ) {
		$this->router   = $router;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Priority 20: after core and cache plugins send their headers.
		add_action( 'send_headers', [ $this, 'maybe_send' ], 20 );
	}

	/**
	 * Emit the header when appropriate.
	 *
	 * @return void
	 */
	public function maybe_send(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		// A 404 is no translated page: skip the router work.
		if ( is_404() ) {
			return;
		}

		if ( ! (bool) $this->settings->get( 'content_language_header', true ) ) {
			return;
		}

		$current = $this->router->get_current_language();

		if ( $current === null ) {
			return;
		}

		// BCP 47 locale (de-DE), as in the hreflang tags; the bare slug otherwise.
		$locale = isset( $current->locale ) && $current->locale !== ''
			? \PerfLocale\Helper::format_locale_as_bcp47( (string) $current->locale )
			: (string) ( $current->slug ?? '' );

		if ( $locale === '' ) {
			return;
		}

		/**
		 * Filter the Content-Language header value before it is sent.
		 *
		 * @hook perflocale/content_language/value
		 * @param string $locale  BCP 47 value, e.g. 'de-DE'.
		 * @param object $current Current language object.
		 */
		$locale = (string) apply_filters( 'perflocale/content_language/value', $locale, $current );

		// A filter may return anything: keep only BCP 47 characters.
		$locale = (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $locale );

		if ( $locale === '' ) {
			return;
		}

		header( 'Content-Language: ' . $locale );
	}
}
