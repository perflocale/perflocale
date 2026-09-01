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
 * Catches incoming requests whose URL prefix matches a renamed (now-stale)
 * language slug and 301s them to the new slug-prefixed URL.
 *
 * Triggered by {@see LanguageRepository::rename_slug()}, which records each
 * rename as `old_slug => new_slug` under {@see LanguageRepository::REDIRECTS_OPTION}.
 *
 * Single-site, subdomain-multisite, and subdirectory-multisite installs all
 * route through the same `wp` action since the slug prefix is always the
 * first path segment after the home URL — `home_url('/')` already accounts
 * for the install's URL shape.
 */
final class SlugRedirector {

	/**
	 * Register the redirect listener.
	 *
	 * Hooks `wp` instead of `template_redirect`, which is what puts the 301
	 * ahead of the rest of perflocale's `template_redirect` chain
	 * (default-to-prefix, edge hints, canonicalisation): core fires `wp` at
	 * the end of WP::main(), before the template loader dispatches
	 * `template_redirect` at all, so a stale slug never reaches those. The
	 * priority-1 argument orders this callback only against OTHER `wp`
	 * listeners, and is set so a theme's `wp` hook cannot emit output first
	 * and make the redirect impossible.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// `wp` fires on every front-end request after WP_Query has resolved
		// the URL but before template loading. That timing is intentional:
		// REST and admin contexts skip this hook entirely, so admin-side
		// edits of `/wp-admin/edit-tags.php?taxonomy=...&perflocale_lang=en`
		// don't get rerouted.
		add_action( 'wp', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * If the current request path begins with a redirected (renamed) slug,
	 * issue a 301 to the equivalent path under the new slug.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		// Skip: admin, REST, AJAX, CLI, cron — none use the slug prefix.
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

		// Slug-in-path redirects only make sense in subdirectory mode. In
		// subdomain (`en.site.com`) and domain modes the slug isn't in the
		// path, so a path segment that looks like a slug is just a regular
		// path — running the redirector would produce false-positive 301s.
		$plugin = \PerfLocale\Plugin::get_instance();

		if ( $plugin->has( 'settings' ) ) {
			$mode = $plugin->get( 'settings' )->get_url_mode();

			// Only subdirectory mode puts the language slug in the path.
			// Subdomain/domain carry it in the host and query mode in ?lang=,
			// so a path segment that looks like a slug is a regular path there.
			if ( $mode !== 'subdirectory' ) {
				return;
			}
		}

		// esc_url_raw() is the canonical URL sanitizer here, not
		// sanitize_text_field(). sanitize_text_field() strips %XX
		// percent-encoded sequences (WP-core formatting.php applies
		// preg_replace('/%[a-f0-9]{2}/i','',...) inside _sanitize_text_fields),
		// which would corrupt non-ASCII slug tails like `/de/über-uns/`
		// (sent as `/de/%C3%BCber-uns/`) before reconstruction at line 179.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		if ( $request_uri === '' ) {
			return;
		}

		// Resolve the path relative to the install root. Read the raw `home`
		// option, NOT home_url() — our home_url filter adds the default-
		// language prefix when hide_default_prefix=false, so concatenating the
		// slug again would yield `/en-us/en-us/foo`. The raw option is the
		// unfiltered root (`/`, or `/site2` on subdirectory multisite).
		$home_url_raw = (string) get_option( 'home', '' );
		$home_path    = (string) wp_parse_url( $home_url_raw, PHP_URL_PATH );
		$home_path    = $home_path === '' ? '/' : $home_path;
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$query_string = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( $request_path === '' ) {
			return;
		}

		// Strip the install-root prefix so we're matching against
		// path-relative-to-site only.
		$relative = $request_path;

		if ( $home_path !== '/' && str_starts_with( $request_path, $home_path ) ) {
			$relative = '/' . ltrim( substr( $request_path, strlen( $home_path ) ), '/' );
		}

		// Empty path or pure root is never a slug-prefix match.
		if ( $relative === '' || $relative === '/' ) {
			return;
		}

		// Match `/old-slug` or `/old-slug/...`. The regex is anchored so
		// `/news/` doesn't accidentally match a renamed `news` language.
		if ( ! preg_match( '#^/([a-z]{2,3}(?:-[a-z]{2,3})?)(/.*)?$#i', $relative, $m ) ) {
			return;
		}

		$candidate = strtolower( $m[1] );

		if ( ! isset( $redirects[ $candidate ] ) ) {
			return;
		}

		$new_slug = (string) $redirects[ $candidate ];

		// Defence in depth: re-validate the stored target slug at READ
		// time even though `rename_slug()` already validated it at write.
		// The option could be corrupted by a rogue plugin or DB import; a
		// non-slug value here would otherwise bend the redirect target
		// into a path-injection vector. Same regex shape as
		// `LanguageRepository::rename_slug()`.
		if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z]{2,3})?$/', $new_slug ) ) {
			return;
		}

		// Guard against a redirect loop: if somebody renamed `en` → `en-us`
		// then renamed `en-us` back → `en`, both entries can exist. Skip
		// when the chain would point at the same slug we already received.
		if ( $new_slug === $candidate ) {
			return;
		}

		$tail = $m[2] ?? '';

		// Strip the query component from $tail just in case parse_url
		// surprises us. The path captured by the regex never contains a
		// `?`, but belt-and-braces — concatenating an un-stripped tail
		// would produce `?utm=...?utm=...` if someone hand-crafted the URL.
		$qpos = strpos( $tail, '?' );
		if ( $qpos !== false ) {
			$tail = substr( $tail, 0, $qpos );
		}

		// Reject CRLF anywhere in the captured tail (defence against
		// header-injection via crafted REQUEST_URI). PHP/Apache strip
		// these before we get here, but assertion-level guard is cheap.
		if ( $tail !== '' && preg_match( '/[\r\n\0]/', $tail ) ) {
			return;
		}

		// SEO chain-shortening for `hide_default_prefix=true` sites:
		// when the new slug IS the active default AND default-no-prefix
		// is on, the canonical URL has no language segment at all. Skip
		// the intermediate `/en-us/foo` hop and 301 directly to `/foo`,
		// avoiding a needless second redirect from the canonicalize-
		// default-prefix handler downstream.
		$skip_prefix = $this->should_skip_prefix_for_default( $new_slug );

		$prefix_segment = $skip_prefix ? '' : ( '/' . $new_slug );
		$new_path       = rtrim( $home_path, '/' ) . $prefix_segment . $tail;

		// When the result is empty (default-no-prefix + tail empty),
		// fall back to the install root so we always emit at least `/`.
		if ( $new_path === '' ) {
			$new_path = '/';
		}

		$target = $new_path . ( $query_string !== '' ? '?' . $query_string : '' );

		// Use `wp_safe_redirect` so off-site URLs can't be smuggled through
		// the redirect map (they wouldn't be — get_slug_redirects() values
		// are pure slug strings — but defence in depth is cheap).
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Whether the rebuilt redirect target should drop the language prefix.
	 *
	 * True only when (a) the new slug matches the current default AND
	 * (b) `hide_default_prefix=true` is set. In that combo the canonical
	 * URL for the default language carries no slug segment at all, so
	 * 301-ing to `/new-slug/foo` would just trigger a second 301 from
	 * the canonicalize-default handler. We elide the chain.
	 *
	 * Repository + settings access happens through a fresh instance
	 * because SlugRedirector itself is a stateless eager service that
	 * doesn't get the DI container injected — and querying once per
	 * 301-eligible request is negligible cost.
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

		// `subdirectory` URL mode is the only one where the language
		// prefix appears in the path component. For `subdomain` /
		// `domain` modes the slug never lived in the path, so
		// prefix-stripping is meaningless (and would actually be wrong
		// — we'd lose the `/en-us/` segment that other modes don't
		// even have).
		$mode = $settings->get_url_mode();

		if ( $mode !== 'subdirectory' ) {
			return false;
		}

		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $plugin->get( 'cache' ) );
		$default   = $lang_repo->get_default();

		return $default && (string) $default->slug === $new_slug;
	}
}
