<?php
/**
 * Visitor asset enqueues.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the switcher's stylesheet and script, minified unless SCRIPT_DEBUG.
 *
 * Loaded on first use only; LanguageSwitcherBlock::register_frontend_style() makes the same pick inline because it runs on every request.
 */
final class VisitorAssets {

	/**
	 * URL of a shipped visitor asset: its .min twin unless SCRIPT_DEBUG is on, as the init registration picks it.
	 *
	 * @param string $path A tools/minify/assets.json visitor source (GATE-MIN proves its twin ships).
	 * @return string
	 */
	public static function url( string $path ): string {
		if ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
			return PERFLOCALE_URL . (string) preg_replace( '/\.(js|css)$/', '.min.$1', $path );
		}

		return PERFLOCALE_URL . $path;
	}

	/**
	 * Enqueue the switcher stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		wp_enqueue_style( 'perflocale-frontend', self::url( 'assets/css/frontend.css' ), [], PERFLOCALE_VERSION );
	}

	/**
	 * Enqueue the dropdown script, deferred in the footer.
	 *
	 * @return void
	 */
	public static function enqueue_switcher_script(): void {
		wp_enqueue_script(
			'perflocale-switcher-dropdown',
			self::url( 'assets/js/language-switcher-dropdown.js' ),
			[],
			PERFLOCALE_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}
}
