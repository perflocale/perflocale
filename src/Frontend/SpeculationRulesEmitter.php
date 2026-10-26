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
 * Adds a moderate-eagerness `prerender` rule for the current page's translations.
 *
 * WP 6.8+ goes through core's rule set; older versions get a footer script.
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
	 * @return void
	 */
	public function register_hooks(): void {
		// Disabled installs attach nothing.
		if ( ! (bool) $this->settings->get( 'prerender_on_hover', false ) ) {
			return;
		}

		if ( $this->core_api_available() ) {
			// Merges into core's output and inherits its disable-all configuration.
			add_action( 'wp_load_speculation_rules', [ $this, 'add_core_rule' ] );
			return;
		}

		// WP < 6.8: own script in the footer.
		add_action( 'wp_footer', [ $this, 'emit_fallback' ], 20 );
	}

	/**
	 * Whether WP Core's Speculation Rules API is available (WP 6.8+).
	 *
	 * PERFLOCALE_FORCE_SR_FALLBACK or the filter forces the fallback path, for tests.
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
		 * Return false to force the fallback path on WP 6.8+.
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
	 * Register the prerender rule on `wp_load_speculation_rules`.
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

		// Core applies the exclude-paths filter only to its own rule; a list rule cannot carry `where`.
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

		// Same exclusions as the core-API path.
		$urls = $this->apply_exclude_paths_filter( $urls );

		if ( $urls === [] ) {
			return;
		}

		/**
		 * Filter the speculation rules payload before emission (fallback path only).
		 *
		 * Not applied on WP 6.8+, where the rule goes through core's API.
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

		// A `</` in a URL must not close the script tag early.
		$safe = str_replace( '</', '<\/', $json );

		wp_print_inline_script_tag(
			$safe,
			[
				'type' => 'speculationrules',
				'id'   => 'perflocale-speculationrules',
			]
		);
	}

	/**
	 * Resolve the translation URLs to prerender, minus the current page.
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
		 * @hook perflocale/prerender/should_emit
		 * @param bool $should_emit Default: true when the setting is on.
		 */
		if ( ! apply_filters( 'perflocale/prerender/should_emit', true ) ) {
			return [];
		}

		// Memoized per request and shared with hreflang and the switcher.
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
	 * Apply the `wp_speculation_rules_href_exclude_paths` filter on both paths.
	 *
	 * Core never applies it to plugin list rules; patterns match as globs, not full URL Patterns.
	 *
	 * @param array<int, string> $urls Full URLs.
	 * @return array<int, string> Filtered URLs.
	 */
	private function apply_exclude_paths_filter( array $urls ): array {
		/**
		 * URL patterns to exclude from speculative loading (core's filter).
		 *
		 * @hook wp_speculation_rules_href_exclude_paths
		 * @param array<int, string> $paths Path patterns (URL Pattern spec).
		 * @param string $mode 'prefetch' or 'prerender'.
		 */
		// Core's own filter, so one exclusion list covers core's rules and these.
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

			// Patterns are relative to the home path, as in core.
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
	 * Glob-style path matcher for exclude paths; `*` also crosses `/`.
	 *
	 * @param string $path Path to test (leading slash).
	 * @param string $pattern Pattern (e.g. '/cart/*').
	 * @return bool
	 */
	private function path_matches_pattern( string $path, string $pattern ): bool {
		if ( $pattern === '' ) {
			return false;
		}

		// A pattern this long is a filter mistake.
		if ( strlen( $pattern ) > 512 ) {
			return false;
		}

		// Not PCRE: no backtracking hazard from filtered patterns.
		return fnmatch( $pattern, $path );
	}
}
