<?php
/**
 * Rewrite rules manager.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Router;

use PerfLocale\Cache\CacheManager;
use PerfLocale\Plugin;
use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WordPress rewrite rules for language-prefixed URLs.
 */
final class RewriteManager {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private readonly Settings $settings;

	/**
	 * Key for the "rewrite rules contain language patterns" flag.
	 */
	private const VERIFIED_KEY = 'rewrites_verified';

	/**
	 * Autoloaded option holding the "verified" flag, so the hot-path read is an alloptions hit.
	 */
	private const VERIFIED_OPTION = 'perflocale_rewrites_verified';

	/**
	 * Cache group used for RewriteManager state.
	 */
	private const CACHE_GROUP = 'perflocale_rewrites';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolve the CacheManager lazily from the plugin container.
	 *
	 * Null when the container does not hold the cache service yet (early `init` paths).
	 *
	 * @return CacheManager|null
	 */
	private function cache(): ?CacheManager {
		try {
			$plugin = Plugin::get_instance();
			if ( $plugin->has( 'cache' ) ) {
				$resolved = $plugin->get( 'cache' );
				return $resolved instanceof CacheManager ? $resolved : null;
			}
		} catch ( \Throwable $e ) {
			// Container not ready.
			unset( $e );
		}
		return null;
	}

	/**
	 * Get the "rewrite rules verified" flag.
	 *
	 * @return bool
	 */
	private function is_verified(): bool {
		// Autoloaded option: clear_verified() runs whenever the rules regenerate, so no expiry is needed.
		CacheManager::before_autoloaded_read( self::VERIFIED_OPTION );

		return get_option( self::VERIFIED_OPTION, '' ) === '1';
	}

	/**
	 * Set the "rewrite rules verified" flag.
	 *
	 * @return void
	 */
	private function mark_verified(): void {
		update_option( self::VERIFIED_OPTION, '1', true );
	}

	/**
	 * Clear the "rewrite rules verified" flag.
	 *
	 * @return void
	 */
	private function clear_verified(): void {
		// Also drops a flag left in the cached alloptions list without its row,
		// which would otherwise keep the rules scan off until a cache flush.
		CacheManager::delete_autoloaded_options( [ self::VERIFIED_OPTION ] );

		// Also clear the cache and transient copies an upgrade may have left set.
		$cache = $this->cache();
		if ( $cache instanceof CacheManager ) {
			$cache->delete( self::VERIFIED_KEY, self::CACHE_GROUP );
		}
		delete_transient( 'perflocale_rewrites_verified' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Add the language rewrite tag - runs after CPTs are registered.
		add_action( 'init', [ $this, 'add_rewrite_tag' ], 5 );

		// Modify rewrite rules to include language-prefixed variants.
		add_filter( 'rewrite_rules_array', [ $this, 'filter_rewrite_rules' ] );

		// Verify rewrite rules contain language patterns, flush if missing.
		add_action( 'admin_init', [ $this, 'maybe_flush_if_missing' ] );

		// Frontend self-healing: flush rules if language patterns are missing.
		if ( ! is_admin() ) {
			add_action( 'parse_request', [ $this, 'maybe_flush_if_missing_frontend' ], 0 );
		}

		// Clear the verified flag when rewrite rules are regenerated.
		add_action(
			'rewrite_rules_array',
			function ( $rules ) {
				$this->clear_verified();
				return $rules;
			},
			999
		);
	}

	/**
	 * Flush rewrite rules if language-prefixed rules are missing.
	 *
	 * @return void
	 */
	public function maybe_flush_if_missing(): void {
		if ( $this->settings->get_url_mode() !== 'subdirectory' ) {
			return;
		}

		// Reuse the same flag as the frontend check to avoid loading the
		// full rewrite_rules array on every admin page.
		if ( $this->is_verified() ) {
			return;
		}

		// Same guard as the frontend path: with no language regex a flush
		// cannot add language rules, so scheduling one would re-arm itself
		// on every admin page load. See maybe_flush_if_missing_frontend().
		if ( $this->build_language_regex() === '' ) {
			return;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		// Check if any rewrite value contains our language query var. The
		// name is filterable (UrlConverter::query_var()), so the scan must
		// look for whatever filter_rewrite_rules() actually writes.
		$lang_needle = UrlConverter::query_var() . '=$matches[1]';
		foreach ( $rules as $pattern => $rewrite ) {
			if ( str_contains( $rewrite, $lang_needle ) ) {
				$this->mark_verified();
				return; // Rules exist, no flush needed.
			}
		}

		// Language rules are missing - schedule a flush.
		update_option( 'perflocale_flush_rules', 1, false );
	}

	/**
	 * Frontend self-healing: flush rewrite rules if language patterns are missing.
	 *
	 * @return void
	 */
	public function maybe_flush_if_missing_frontend(): void {
		if ( $this->settings->get_url_mode() !== 'subdirectory' ) {
			return;
		}

		// Skip the expensive rules scan if we already verified recently.
		if ( $this->is_verified() ) {
			return;
		}

		// No language regex: a flush cannot add language rules and its `rewrite_rules_array` clears
		// the flag again, so every request would flush (fresh install, unreadable tables).
		if ( $this->build_language_regex() === '' ) {
			return;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		$lang_needle = UrlConverter::query_var() . '=$matches[1]';

		foreach ( $rules as $pattern => $rewrite ) {
			if ( str_contains( $rewrite, $lang_needle ) ) {
				// Rules are present - set a flag so we don't check again.
				$this->mark_verified();
				return;
			}
		}

		// Mark before flushing so concurrent requests skip their own flush. The flush's
		// `rewrite_rules_array` closure clears the flag again; the next request re-scans and
		// re-marks (one extra scan, never a loop).
		$this->mark_verified();
		flush_rewrite_rules( false );
	}

	/**
	 * Register the language rewrite tag.
	 *
	 * @return void
	 */
	public function add_rewrite_tag(): void {
		$lang_regex = $this->build_language_regex();

		if ( $lang_regex !== '' ) {
			add_rewrite_tag( '%perflocale_lang%', $lang_regex );
		}
	}

	/**
	 * Prepend language-prefixed rewrite rules before all existing rules.
	 *
	 * @param array<string, string> $rules Existing rewrite rules.
	 * @return array<string, string> Modified rules with language prefixes.
	 */
	public function filter_rewrite_rules( array $rules ): array {
		if ( $this->settings->get_url_mode() !== 'subdirectory' ) {
			return $rules;
		}

		$lang_regex = $this->build_language_regex();

		if ( $lang_regex === '' ) {
			return $rules;
		}

		$new_rules = [];

		// Filterable query-var name, so renaming it keeps rewrite rules,
		// add_query_vars() and the URL writer in agreement. Renaming on a
		// live site needs a permalink flush, as the rules are stored.
		$lang_qv = UrlConverter::query_var() . '=$matches[1]';

		// Add root language page rule.
		$new_rules[ $lang_regex . '/?$' ] = 'index.php?' . $lang_qv;

		// Add feed rule for language root.
		$new_rules[ $lang_regex . '/feed/(feed|rdf|rss|rss2|atom)/?$' ] =
			'index.php?' . $lang_qv . '&feed=$matches[2]';

		// Prefix each existing rule with the language segment.
		foreach ( $rules as $pattern => $rewrite ) {
			// Count existing matches to offset our new capture group.
			$max_match = 0;
			if ( preg_match_all( '/\$matches\[(\d+)\]/', $rewrite, $match_refs ) ) {
				$max_match = max( array_map( 'intval', $match_refs[1] ) );
			}

			// Increment all existing $matches[N] references by 1
			// because we're prepending a new capture group for the language.
			$new_rewrite = preg_replace_callback(
				'/\$matches\[(\d+)\]/',
				fn( $m ) => '$matches[' . ( (int) $m[1] + 1 ) . ']',
				$rewrite
			);

			// Skip this rule if regex replacement failed.
			if ( $new_rewrite === null ) {
				continue;
			}

			// Add the language parameter.
			$new_rewrite = str_replace( 'index.php?', 'index.php?' . $lang_qv . '&', $new_rewrite );

			// Strip a leading ^ (WooCommerce wc-auth/wc-api): WP matches #^pattern#, so a
			// mid-pattern anchor never matches. Our prefix re-anchors the pattern.
			$new_rules[ $lang_regex . '/' . ltrim( $pattern, '^' ) ] = $new_rewrite;
		}

		// Language-prefixed rules come first, then the originals.
		return array_merge( $new_rules, $rules );
	}

	/**
	 * Build a regex that matches only configured language URL prefixes.
	 *
	 * Only configured prefixes, never `[a-z]{2,3}`, which would match bases like 'tag' or 'cat'.
	 *
	 * @return string Regex capturing group, or empty string if no languages.
	 */
	private function build_language_regex(): string {
		if ( ! \PerfLocale\Database\Schema::tables_exist() ) {
			return '';
		}

		$plugin = Plugin::get_instance();

		if ( ! $plugin->has( 'cache' ) ) {
			return '';
		}

		$cache     = $plugin->get( 'cache' );
		$lang_repo = new \PerfLocale\Database\Repository\LanguageRepository( $cache );
		$languages = $lang_repo->get_active();

		if ( empty( $languages ) ) {
			return '';
		}

		$prefixes = [];

		foreach ( $languages as $lang ) {
			$prefix = $this->settings->get_url_prefix( $lang );

			if ( $prefix !== '' ) {
				$prefixes[] = preg_quote( $prefix, '/' );
			}
		}

		if ( empty( $prefixes ) ) {
			return '';
		}

		return '(' . implode( '|', $prefixes ) . ')';
	}
}
