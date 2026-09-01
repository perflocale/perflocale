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
 * Emits the `Content-Language` response header on frontend requests.
 *
 * Content-Language is the standards-defined way to tell crawlers, proxies,
 * and translation tools the language of the response body (as opposed to
 * Accept-Language, which is request-side). Google, Yandex, and proxy/CDN
 * layers all honour it as a signal in addition to `<html lang>`.
 *
 * Cost profile: one `header()` call per frontend request. Gated by settings
 * + admin/REST context - admin, REST, CLI, cron never touch this.
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
		// `send_headers` fires once per request, after the main query is set up
		// but before the body is sent - the correct place to set additional
		// response headers. Priority 20 so core + cache plugins run first.
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

		// 404 pages: skip the locale resolution + header write. A 404
		// response doesn't represent a translated page, and the value
		// is a CDN/proxy hint that has no content to apply to. Cheap
		// short-circuit before doing any router work.
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

		// Prefer canonical BCP 47 locale (`de-DE`, `en-US`) over bare slug.
		// The Helper enforces lowercase-language + UPPERCASE-region per
		// RFC 5646 — same convention as the hreflang link tags.
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
		 * @param string $locale The BCP-47-normalised language value (e.g. 'de-DE').
		 * @param object $current The current language object from the router.
		 */
		$locale = (string) apply_filters( 'perflocale/content_language/value', $locale, $current );

		// Defensive: BCP-47 language tags are restricted to ASCII letters,
		// digits, and hyphens. Strip everything else from the filtered
		// value before passing it to header() - PHP's header() already
		// refuses values containing CRLF (the response-splitting vector),
		// but stripping non-token characters here defends against a
		// misbehaving filter callback returning anything else odd
		// (control chars, multibyte, etc.) and keeps the header strictly
		// RFC-5646-compliant.
		$locale = (string) preg_replace( '/[^A-Za-z0-9\-]/', '', $locale );

		if ( $locale === '' ) {
			return;
		}

		header( 'Content-Language: ' . $locale );
	}
}
