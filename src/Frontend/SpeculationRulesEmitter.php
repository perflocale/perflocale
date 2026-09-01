<?php
/**
 * Speculation Rules API emitter - prerender target translations on hover.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Router\LanguageRouter;
use PerfLocale\Router\UrlConverter;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a `prerender` speculation rule for translation-sibling URLs so that
 * when a visitor hovers/focuses a language-switcher link, the browser
 * prerenders the target translation URL in the background. Language switch
 * then becomes effectively instant.
 *
 * Integration strategy - two paths:
 *
 * 1. WP 6.8+ (has WP_Speculation_Rules): register the rule via the
 * `wp_load_speculation_rules` action. Core merges it into its single
 * speculation-rules output and respects the
 * `wp_speculation_rules_configuration` global opt-out. No duplicate tag.
 * Core does NOT apply `wp_speculation_rules_href_exclude_paths` to
 * plugin-added list rules, so we apply it ourselves before add_rule().
 *
 * 2. WP 6.4–6.7 (no Core API): emit an independent speculation-rules
 * payload at wp_footer as a fallback, using the native
 * `wp_print_inline_script_tag()` helper. Still honours the same filter
 * name (`wp_speculation_rules_href_exclude_paths`) so user filters work
 * identically across WP versions.
 *
 * Only emits on frontend pages that actually have translation alternates
 * (checked via UrlConverter::get_translations_for_current_page()). Browsers
 * without speculation-rules support simply ignore the script - zero breakage.
 */
final class SpeculationRulesEmitter {

	/** @var Settings */
	private readonly Settings $settings;

	/** @var LanguageRouter */
	private readonly LanguageRouter $router;

	/** @var UrlConverter */
	private readonly UrlConverter $url_converter;

	public function __construct(
		Settings $settings,
		LanguageRouter $router,
		UrlConverter $url_converter
	) {
		$this->settings      = $settings;
		$this->router        = $router;
		$this->url_converter = $url_converter;
	}

	/**
	 * Register hooks.
	 *
	 * On WP 6.8+: hooks `wp_load_speculation_rules` to register our rule
	 * inside Core's single output script.
	 *
	 * On WP 6.4–6.7: hooks `wp_footer` priority 20 and emits a standalone
	 * script.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Feature defaults OFF — skip attaching hooks entirely so disabled
		// installs don't pay for an add_action() + callback fire (which
		// would just short-circuit on the same setting read inside
		// resolve_target_urls()).
		if ( ! (bool) $this->settings->get( 'prerender_on_hover', false ) ) {
			return;
		}

		if ( $this->core_api_available() ) {
			// Core fires this once per request while building its speculation-
			// rules output. `add_rule()` inside merges with Core's rules and
			// inherits the disable-all config filter; exclude paths are
			// applied by add_core_rule() itself (Core doesn't do it for
			// plugin-added list rules).
			add_action( 'wp_load_speculation_rules', [ $this, 'add_core_rule' ] );
			return;
		}

		// Fallback: WP < 6.8 - emit our own script at wp_footer.
		add_action( 'wp_footer', [ $this, 'emit_fallback' ], 20 );
	}

	/**
	 * Whether WP Core's Speculation Rules API is available (WP 6.8+).
	 *
	 * The `PERFLOCALE_FORCE_SR_FALLBACK` constant + the identically-named
	 * filter allow tests (and integrators with unusual setups) to force
	 * the fallback self-emit path even when Core's API is present. Useful
	 * for exercising the fallback code on modern WP without downgrading.
	 *
	 * @return bool
	 */
	private function core_api_available(): bool {
		if ( defined( 'PERFLOCALE_FORCE_SR_FALLBACK' ) && PERFLOCALE_FORCE_SR_FALLBACK ) {
			return false;
		}

		/**
		 * Filter whether to use Core's Speculation Rules API when it's available.
		 *
		 * Return false to force the fallback self-emit path even on WP 6.8+.
		 * Primarily useful for tests that need to exercise the fallback code,
		 * or for integrators who want finer control than Core's API offers.
		 *
		 * @hook perflocale/prerender/use_core_api
		 * @param bool $use_core Default: true when Core's class exists.
		 */
		$use_core = (bool) apply_filters(
			'perflocale/prerender/use_core_api',
			class_exists( '\WP_Speculation_Rules' )
		);

		return $use_core;
	}

	/**
	 * Register our prerender rule via WP Core's Speculation Rules API.
	 *
	 * Called by Core via the `wp_load_speculation_rules` action.
	 *
	 * @param object $rules WP_Speculation_Rules instance.
	 * @return void
	 */
	public function add_core_rule( $rules ): void {
		if ( ! is_object( $rules ) || ! method_exists( $rules, 'add_rule' ) ) {
			return;
		}

		$urls = $this->resolve_target_urls();

		if ( $urls === [] ) {
			return;
		}

		// Core does NOT apply wp_speculation_rules_href_exclude_paths to
		// rules registered via add_rule(): it bakes the filter only into its
		// own main document rule, and list-source rules structurally cannot
		// carry a `where` clause (WP_Speculation_Rules::add_rule rejects it).
		// Apply the same filter ourselves so an owner's `/checkout/*`-style
		// exclusion holds here exactly like on the <6.8 fallback path.
		$urls = $this->apply_exclude_paths_filter( $urls );

		if ( $urls === [] ) {
			return;
		}

		$rules->add_rule(
			'prerender',
			'perflocale-translation-prerender',
			[
				'source'    => 'list',
				'urls'      => $urls,
				'eagerness' => 'moderate',
			]
		);
	}

	/**
	 * Fallback emission for WP 6.4–6.7 (no Core Speculation Rules API).
	 *
	 * @return void
	 */
	public function emit_fallback(): void {
		$urls = $this->resolve_target_urls();

		if ( $urls === [] ) {
			return;
		}

		// Apply the same exclude-paths filter Core would apply on 6.8+ so
		// users who add `/cart/*`, `/checkout/*`, etc. get consistent
		// behaviour across WP versions.
		$urls = $this->apply_exclude_paths_filter( $urls );

		if ( $urls === [] ) {
			return;
		}

		/**
		 * Filter the speculation rules payload before emission (fallback path only).
		 *
		 * Default: prerender on moderate eagerness. Options per Chromium docs:
		 * 'immediate' | 'eager' | 'moderate' | 'conservative'.
		 *
		 * On WP 6.8+ this filter is bypassed - rules are registered via Core's
		 * `wp_load_speculation_rules` action instead. Use the Core API's own
		 * filters on modern WP.
		 *
		 * @hook perflocale/prerender/rules
		 * @param array<string, mixed> $rules Speculation-rules JSON (pre-encode).
		 * @param array<int, string> $urls Translation URLs being prerendered.
		 */
		$rules = apply_filters(
			'perflocale/prerender/rules',
			[
				'prerender' => [
					[
						'source'    => 'list',
						'urls'      => $urls,
						'eagerness' => 'moderate',
					],
				],
			],
			$urls
		);

		if ( ! is_array( $rules ) || $rules === [] ) {
			return;
		}

		$json = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES );

		if ( $json === false ) {
			return;
		}

		// Guard against a premature close-tag sequence inside any URL
		// string that could close the tag early. wp_json_encode with
		// JSON_UNESCAPED_SLASHES alone doesn't protect against this.
		$safe = str_replace( '</', '<\/', $json );

		// Use WordPress' native wp_print_inline_script_tag() helper
		// (WP 5.7+) so the output rides Core's script-tag pipeline
		// (wp_inline_script_attributes + wp_inline_script filters) and
		// no raw markup is echoed from plugin code. The JSON body is the
		// tag contents; $safe already has the close-tag injection guard
		// applied above.
		wp_print_inline_script_tag(
			$safe,
			[
				'type' => 'speculationrules',
				'id'   => 'perflocale-speculationrules',
			]
		);
	}

	/**
	 * Resolve the set of translation URLs to prerender (minus the current page).
	 *
	 * Shared by both the Core-API path and the fallback path. Returns an
	 * empty array when the feature is disabled, the page has no alternates,
	 * or the request is one where speculation makes no sense (admin, feeds,
	 * 404, preview).
	 *
	 * @return array<int, string>
	 */
	private function resolve_target_urls(): array {
		if ( is_admin() || is_feed() || is_404() || is_preview() ) {
			return [];
		}

		if ( ! (bool) $this->settings->get( 'prerender_on_hover', false ) ) {
			return [];
		}

		/**
		 * Filter whether to register/emit speculation rules on this request.
		 *
		 * Use this to exclude templates where prerendering would waste
		 * bandwidth (e.g. pages with huge media, paginated archives, or
		 * any page that triggers non-idempotent side-effects on load).
		 *
		 * @hook perflocale/prerender/should_emit
		 * @param bool $should_emit Default: true when the setting is on.
		 */
		if ( ! apply_filters( 'perflocale/prerender/should_emit', true ) ) {
			return [];
		}

		// Translation-URL list: UrlConverter caches this internally so other
		// consumers (hreflang, switcher) hit the same cache.
		$translations = $this->url_converter->get_translations_for_current_page();

		if ( empty( $translations ) ) {
			return [];
		}

		$current_slug = $this->router->get_current_slug();
		$urls         = [];

		foreach ( $translations as $slug => $url ) {
			if ( $slug === $current_slug || ! is_string( $url ) || $url === '' ) {
				continue; // Skip the page we're already on.
			}

			$urls[] = esc_url_raw( $url );
		}

		return $urls;
	}

	/**
	 * Apply the `wp_speculation_rules_href_exclude_paths` filter manually.
	 * Used on BOTH paths: Core (6.8+) never applies it to plugin-added list
	 * rules, and the <6.8 fallback has no Core to lean on either way.
	 *
	 * Patterns follow the URL Pattern web spec, but we implement a minimal
	 * glob-style matcher here that covers the common cases (`/cart/*`,
	 * `/checkout/*`, `/my-account/*`). Full URL Pattern spec fidelity isn't
	 * practical to replicate in PHP - users on WP 6.8+ get the real
	 * implementation; on 6.4–6.7 they get best-effort coverage.
	 *
	 * @param array<int, string> $urls Full URLs.
	 * @return array<int, string> Filtered URLs.
	 */
	private function apply_exclude_paths_filter( array $urls ): array {
		/**
		 * URL patterns to exclude from speculative loading.
		 *
		 * Same filter name Core uses on WP 6.8+. The second arg ('prerender')
		 * lets users distinguish prefetch-vs-prerender exclusions like they
		 * do on modern WP.
		 *
		 * @hook wp_speculation_rules_href_exclude_paths
		 * @param array<int, string> $paths Path patterns (URL Pattern spec).
		 * @param string $mode 'prefetch' or 'prerender'.
		 */
		// This is WordPress core's own filter (WP 6.8+ Speculation Rules API),
		// not one we invent. We apply it so a theme/plugin customising core's
		// exclusion list also affects ours - same rule, one source of truth.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$excluded = (array) apply_filters(
			'wp_speculation_rules_href_exclude_paths',
			[],
			'prerender'
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( $excluded === [] ) {
			return $urls;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = '/' . trim( $home_path, '/' );

		if ( $home_path === '/' ) {
			$home_path = '';
		}

		$filtered = [];

		foreach ( $urls as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			// Strip the home-URL subdirectory prefix (Core does this too)
			// so a pattern of "/cart/*" matches "/wp/cart/foo/" on sites
			// installed in a subdirectory.
			if ( $home_path !== '' && str_starts_with( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) );
			}

			if ( $path === '' ) {
				$path = '/';
			}

			$excluded_this = false;

			foreach ( $excluded as $pattern ) {
				if ( $this->path_matches_pattern( $path, (string) $pattern ) ) {
					$excluded_this = true;
					break;
				}
			}

			if ( ! $excluded_this ) {
				$filtered[] = $url;
			}
		}

		return $filtered;
	}

	/**
	 * Simple glob-style path matcher for fallback exclude-paths handling.
	 *
	 * `*` matches any sequence of characters (not just a single segment -
	 * Core's URLPattern would distinguish but the common use case doesn't).
	 * Other special regex characters are escaped so patterns like
	 * `/tag/.../` behave literally.
	 *
	 * @param string $path Path to test (leading slash).
	 * @param string $pattern Pattern (e.g. '/cart/*').
	 * @return bool
	 */
	private function path_matches_pattern( string $path, string $pattern ): bool {
		if ( $pattern === '' ) {
			return false;
		}

		// Defensive length cap - patterns longer than this are almost
		// certainly a mistake from an upstream filter.
		if ( strlen( $pattern ) > 512 ) {
			return false;
		}

		// fnmatch() is purpose-built for glob matching and doesn't use PCRE,
		// so no backtracking hazard from adversarial patterns supplied via
		// the wp_speculation_rules_href_exclude_paths filter.
		return fnmatch( $pattern, $path );
	}
}
