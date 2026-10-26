<?php
/**
 * View Transitions API emitter.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Frontend;

use PerfLocale\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits the cross-document View Transitions opt-in (meta tag and CSS) for language switches.
 *
 * Off by default: theme on-navigation animations can fight the browser transition.
 */
final class ViewTransitionsEmitter {

	/** @var Settings */
	private readonly Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Disabled installs attach nothing.
		if ( ! (bool) $this->settings->get( 'view_transitions_enabled', false ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_style' ] );

		add_action( 'wp_head', [ $this, 'emit_meta' ], 2 );
	}

	/**
	 * Whether this request should emit View Transitions output (meta + CSS).
	 *
	 * @return bool
	 */
	private function should_emit(): bool {
		if ( is_admin() || is_feed() ) {
			return false;
		}

		if ( ! (bool) $this->settings->get( 'view_transitions_enabled', false ) ) {
			return false;
		}

		/**
		 * Filter whether to emit the View Transitions opt-in on this request.
		 *
		 * @hook perflocale/view_transitions/should_emit
		 * @param bool $should_emit Default: true when the setting is on.
		 */
		return (bool) apply_filters( 'perflocale/view_transitions/should_emit', true );
	}

	/**
	 * Emit the `<meta name="view-transition">` opt-in tag.
	 *
	 * @return void
	 */
	public function emit_meta(): void {
		if ( ! $this->should_emit() ) {
			return;
		}

		echo '<meta name="view-transition" content="same-origin">' . "\n";
	}

	/**
	 * Register and attach the View Transitions CSS.
	 *
	 * @return void
	 */
	public function enqueue_style(): void {
		if ( ! $this->should_emit() ) {
			return;
		}

		/**
		 * Filter the CSS block emitted for View Transitions.
		 *
		 * Returning '' disables output.
		 *
		 * @hook perflocale/view_transitions/css
		 * @param string $css Default CSS block.
		 */
		$default_css = "@view-transition { navigation: auto; }\n"
			// Root crossfade timing.
			. "::view-transition-old(root),::view-transition-new(root){animation-duration:.24s;animation-timing-function:cubic-bezier(.4,0,.2,1);}\n"
			// Reduced motion: no crossfade (WCAG 2.1 SC 2.3.3).
			. "@media (prefers-reduced-motion: reduce){::view-transition-old(root),::view-transition-new(root){animation-duration:0s;animation:none;}}\n";

		$css = (string) apply_filters( 'perflocale/view_transitions/css', $default_css );

		// Filtered CSS may be untrusted: stripping tags stops a </style> break-out; real CSS has no `<`.
		$css = wp_strip_all_tags( $css );

		if ( $css === '' ) {
			return;
		}

		$handle = 'perflocale-view-transitions';

		wp_register_style( $handle, false, [], defined( 'PERFLOCALE_VERSION' ) ? PERFLOCALE_VERSION : null );
		wp_enqueue_style( $handle );

		// No esc_* exists for a stylesheet body (HTML escaping corrupts CSS); the tag strip above guards it.
		wp_add_inline_style( $handle, $css );
	}
}
