<?php
/**
 * Site Health hook wiring.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Admin;

use PerfLocale\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers SiteHealth's hooks with forwarders, so SiteHealth (the `site_health` service) loads only when one fires.
 *
 * @internal
 */
final class SiteHealthHooks {

	/**
	 * Register the Site Health hooks (admin, admin-ajax and cron: core's weekly check runs the tests from cron).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'site_status_tests', [ self::class, 'register_tests' ] );
		add_filter( 'debug_information', [ self::class, 'register_info' ] );

		// Settings changes can change the cached counts: drop them.
		add_action( 'perflocale/settings/updated', [ self::class, 'flush_counts_cache' ] );

		// String edits, PO imports and bulk runs change the counts, hreflang and files cards: drop their caches (admin and
		// background writes only).
		add_action( 'perflocale/strings/changed', [ self::class, 'flush_counts_cache' ] );

		add_action( 'admin_notices', [ self::class, 'render_leftover_notice' ] );

		// The network upgrade pass runs from WP-Cron and Action Scheduler (cron or admin-ajax); WP-CLI registers it too.
		if ( is_multisite() ) {
			add_action( \PerfLocale\Database\NetworkUpgrade::HOOK, [ \PerfLocale\Database\NetworkUpgrade::class, 'run' ] );
		}

		// The Status screen runs the language-pack test through this admin-ajax action.
		if ( wp_doing_ajax() ) {
			add_action( 'wp_ajax_health-check-perflocale-language-packs', [ self::class, 'ajax_language_packs' ] );
			add_action( 'wp_ajax_health-check-perflocale-duplicate-post-slugs', [ self::class, 'ajax_duplicate_post_slugs' ] );
			add_action( 'wp_ajax_health-check-perflocale-fallback-pages', [ self::class, 'ajax_fallback_pages' ] );
			add_action( 'wp_ajax_health-check-perflocale-term-slug-review', [ self::class, 'ajax_term_slug_review' ] );
		}
	}

	/**
	 * The SiteHealth service.
	 *
	 * @return SiteHealth
	 */
	private static function health(): SiteHealth {
		/** @var SiteHealth $health */
		$health = Plugin::get_instance()->get( 'site_health' );

		return $health;
	}

	/**
	 * @param array<string, array<string, array<string, mixed>>> $tests
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function register_tests( array $tests ): array {
		return self::health()->register_tests( $tests );
	}

	/**
	 * @param mixed $info Core's debug information.
	 * @return mixed
	 */
	public static function register_info( mixed $info ): mixed {
		return self::health()->register_info( $info );
	}

	/**
	 * Flush the cached counts (settings and string changes).
	 *
	 * @return void
	 */
	public static function flush_counts_cache(): void {
		self::health()->flush_counts_cache();
	}

	/**
	 * Leftover WPML add-on notice; the screen is checked first, so other screens do not load SiteHealth.
	 *
	 * @return void
	 */
	public static function render_leftover_notice(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		$id     = $screen !== null ? (string) $screen->id : '';

		if ( $id !== 'plugins' && ! str_contains( $id, 'perflocale' ) ) {
			return;
		}

		self::health()->render_leftover_notice();
	}

	/**
	 * Admin-ajax endpoint of the language-pack test.
	 *
	 * @return void
	 */
	public static function ajax_language_packs(): void {
		self::health()->ajax_language_packs();
	}

	/**
	 * Admin-ajax endpoint of the duplicate post address test (its class stays out of the classmap).
	 *
	 * @return void
	 */
	public static function ajax_duplicate_post_slugs(): void {
		require_once __DIR__ . '/SiteHealth/DuplicatePostSlugs.php';

		SiteHealth\DuplicatePostSlugs::ajax();
	}

	/**
	 * Admin-ajax endpoint of the untranslated content test (its class stays out of the classmap).
	 *
	 * @return void
	 */
	public static function ajax_fallback_pages(): void {
		require_once __DIR__ . '/SiteHealth/FallbackPages.php';

		SiteHealth\FallbackPages::ajax();
	}

	/**
	 * The stock flag test (StockDriftFlag, outside the class map).
	 *
	 * @return array<string, mixed>
	 */
	public static function stock_drift(): array {
		require_once dirname( __DIR__ ) . '/WooCommerce/StockDriftFlag.php';

		return \PerfLocale\WooCommerce\StockDriftFlag::health();
	}

	/**
	 * The term address review (weekly check); the class is outside the class map, so it is required here.
	 *
	 * @return array<string, mixed>
	 */
	public static function term_slug_review(): array {
		require_once __DIR__ . '/SiteHealth/TermSlugReview.php';

		return SiteHealth\TermSlugReview::test();
	}

	/**
	 * Admin-ajax endpoint of the term address review (Status screen).
	 *
	 * @return void
	 */
	public static function ajax_term_slug_review(): void {
		require_once __DIR__ . '/SiteHealth/TermSlugReview.php';
		SiteHealth\TermSlugReview::ajax();
	}
}
